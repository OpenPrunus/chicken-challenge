<?php

declare(strict_types=1);

namespace Chicken\Tests\Api;

use Chicken\Api\AbuseState;
use Chicken\Api\AbuseStore;
use Chicken\Api\ApiConfig;
use Chicken\Api\ClientIp;
use Chicken\Api\RateLimiter;
use Chicken\Api\TicketSigner;
use Chicken\Ranking\Token;
use Chicken\Support\Base64Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Défenses du classement contre l'IA : tickets signés, durée minimale, limite de débit, adresse IP. */
final class AntiAbuseTest extends TestCase
{
    private const string SECRET = 'ce-secret-de-test-fait-bien-plus-de-trente-deux-caracteres';

    private static function signer(string $secret = self::SECRET): TicketSigner
    {
        return new TicketSigner($secret);
    }

    // ---- tickets ------------------------------------------------------------------------------------------------

    public function testTicketRoundTripCarriesItsFields(): void
    {
        $hash = Token::hash('abababababababababababababababab');
        $t = self::signer()->verify(self::signer()->issue($hash, 2, 1000, 'nonce1'), 1000);
        $this->assertSame(['nonce1', 1000, $hash, 2], [$t->nonce, $t->issuedAt, $t->tokenHash, $t->level]);
    }

    public function testEveryTicketHasItsOwnNonce(): void
    {
        $a = self::signer()->issue('h', 1, 1000);
        $b = self::signer()->issue('h', 1, 1000);
        $this->assertNotSame($a, $b);
        $this->assertNotSame(self::signer()->verify($a, 1000)->nonce, self::signer()->verify($b, 1000)->nonce);
    }

    public function testTicketSignedWithAnotherSecretIsRejected(): void
    {
        $t = self::signer('un-autre-secret-aussi-long-que-le-vrai-secret-ci')->issue('h', 1, 1000);
        $this->assertNull(self::signer()->verify($t, 1000));
    }

    public function testTamperedPayloadOrSignatureIsRejected(): void
    {
        [$payload, $sig] = explode('.', self::signer()->issue('h', 1, 1000, 'n1'));
        $forgedPayload = Base64Url::encode(json_encode(['n' => 'n1', 't' => 1000, 'h' => 'h', 'l' => 2]));   // niveau modifié, signature d'origine
        $this->assertNull(self::signer()->verify("$forgedPayload.$sig", 1000), 'charge utile modifiée');
        $flip = $sig[0] === 'a' ? 'b' : 'a';
        $this->assertNull(self::signer()->verify($payload . '.' . $flip . substr($sig, 1), 1000), 'signature modifiée');
        $this->assertNull(self::signer()->verify($payload . '.' . substr($sig, 0, -1), 1000), 'signature tronquée');
        $this->assertNull(self::signer()->verify($payload . '.' . strtoupper($sig), 1000), 'signature en majuscules');
    }

    /** @return array<string, array{string}> */
    public static function malformedTickets(): array
    {
        return ['vide' => [''], 'sans point' => ['abcdef'], 'point seul' => ['.'], 'trois parties' => ['a.b.c'], 'sans signature' => ['abc.'],
                'sans charge' => ['.abc'], 'base64 invalide' => ['@@@.@@@'], 'espaces' => [' . '], 'gigantesque' => [str_repeat('a', 5000) . '.' . str_repeat('b', 64)]];
    }

    #[DataProvider('malformedTickets')]
    public function testMalformedTicketsAreRejectedWithoutWarnings(string $ticket): void
    {
        $this->assertNull(self::signer()->verify($ticket, 1000));
    }

    public function testTicketAgeBoundaries(): void
    {
        $t = self::signer()->issue('h', 1, 1000);
        $this->assertNotNull(self::signer()->verify($t, 1000 + TicketSigner::MAX_AGE), 'pile à la limite : accepté');
        $this->assertNull(self::signer()->verify($t, 1000 + TicketSigner::MAX_AGE + 1), 'expiré');
        $this->assertNotNull(self::signer()->verify($t, 995), 'horloge légèrement en retard : tolérée (5 s)');
        $this->assertNull(self::signer()->verify($t, 990), 'daté du futur : refusé');
        $this->assertNull(self::signer()->verify($t, 5000, 3600), 'durée de vie personnalisée');
    }

    public function testSignedButMalformedPayloadsAreRejected(): void
    {
        $payloads = [
            ['n' => 'x', 't' => '1000', 'h' => 'h', 'l' => 1], ['n' => 1, 't' => 1000, 'h' => 'h', 'l' => 1],
            ['t' => 1000, 'h' => 'h', 'l' => 1], 'texte', [], ['n' => 'x', 't' => 1000, 'h' => ['a'], 'l' => 1],
        ];
        foreach ($payloads as $payload) {
            $b = Base64Url::encode(json_encode($payload));
            $ticket = $b . '.' . hash_hmac('sha256', $b, self::SECRET);
            $this->assertNull(self::signer()->verify($ticket, 1000), json_encode($payload));
        }
    }

    // ---- durée minimale -----------------------------------------------------------------------------------------

    public function testMinimumDurationGrowsWithThePointsPlayed(): void
    {
        $config = new ApiConfig();
        $this->assertSame(8.0, $config->minimumSeconds(4, 0));
        $this->assertSame(10.0, $config->minimumSeconds(4, 1));
        $this->assertSame(46.0, $config->minimumSeconds(12, 11));
        $this->assertSame(2.0, (new ApiConfig(secondsPerPoint: 2.0))->minimumSeconds(1, 0));
        $this->assertSame(0.0, (new ApiConfig(secondsPerPoint: 0.0))->minimumSeconds(12, 0));
        $this->assertGreaterThan($config->minimumSeconds(4, 0), $config->minimumSeconds(4, 2));
    }

    // ---- limite de débit ----------------------------------------------------------------------------------------

    public function testRateLimitAllowsUpToTheLimitThenRefusesWithRetryAfter(): void
    {
        $limiter = new RateLimiter();
        for ($i = 0; $i < 3; $i++) {
            $d = $limiter->attempt('ip:1', 1000 + $i, 3, 3600);
            $this->assertTrue($d->allowed, "requête $i");
            $this->assertSame(0, $d->retryAfter);
        }
        $before = $limiter->toArray();
        $d = $limiter->attempt('ip:1', 1010, 3, 3600);
        $this->assertFalse($d->allowed);
        $this->assertSame(1000 + 3600 - 1010, $d->retryAfter, 'attente jusqu\'à l\'expiration de la plus ancienne');
        $this->assertSame($before, $limiter->toArray(), 'une requête refusée ne consomme rien');
    }

    public function testRateLimitWindowSlides(): void
    {
        $l = new RateLimiter();
        $l->attempt('k', 1000, 2, 100);
        $l->attempt('k', 1050, 2, 100);
        $this->assertFalse($l->attempt('k', 1099, 2, 100)->allowed, 'toujours dans la fenêtre');
        $this->assertTrue($l->attempt('k', 1101, 2, 100)->allowed, 'la plus ancienne est sortie de la fenêtre');
        $this->assertFalse($l->attempt('k', 1102, 2, 100)->allowed, 'mais 1050 et 1101 comptent encore');
    }

    public function testRateLimitKeysAreIndependentAndExpiredKeysArePurged(): void
    {
        $l = new RateLimiter();
        $l->attempt('a', 1000, 1, 100);
        $this->assertTrue($l->attempt('b', 1000, 1, 100)->allowed, 'une autre clé a son propre quota');
        $this->assertFalse($l->attempt('a', 1001, 1, 100)->allowed);
        $l->attempt('c', 5000, 1, 100);
        $this->assertSame(['c'], array_keys($l->toArray()), 'les clés expirées disparaissent (le fichier ne grossit pas)');
    }

    public function testRateLimitOfZeroRefusesEverything(): void
    {
        $d = (new RateLimiter())->attempt('k', 1000, 0, 100);
        $this->assertFalse($d->allowed);
        $this->assertGreaterThanOrEqual(1, $d->retryAfter);
    }

    // ---- état persistant ----------------------------------------------------------------------------------------

    public function testAbuseStatePersistsRateCountersAndUsedTickets(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-abuse-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $store = new AbuseStore("$dir/abuse.json");
            $this->assertSame(['rate' => [], 'used' => []], $store->load()->toArray(), 'absent : vide');
            $state = new AbuseState();
            $state->rate->attempt('k', 1000, 5, 3600);
            $state->markUsed('nonce1', 1000);
            $this->assertTrue($store->save($state));
            $loaded = $store->load();
            $this->assertTrue($loaded->isUsed('nonce1'));
            $this->assertFalse($loaded->isUsed('nonce2'));
            $this->assertSame(['k' => [1000]], $loaded->rate->toArray());
            file_put_contents("$dir/abuse.json", '{ cassé');
            $this->assertSame(['rate' => [], 'used' => []], $store->load()->toArray(), 'illisible : vide');
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testUsedTicketsAreForgottenOnceTheyCouldNotBeReplayedAnyway(): void
    {
        $state = new AbuseState();
        $state->markUsed('vieux', 1000);
        $state->markUsed('recent', 1000 + TicketSigner::MAX_AGE + 100);
        $this->assertFalse($state->isUsed('vieux'), 'plus vieux que la durée de vie d\'un ticket');
        $this->assertTrue($state->isUsed('recent'));
    }

    // ---- adresse IP ---------------------------------------------------------------------------------------------

    /** @return array<string, array{array<string, string>, string}> */
    public static function ips(): array
    {
        return [
            'client direct public, XFF falsifié ignoré' => [['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], '203.0.113.9'],
            'passerelle privée : XFF cru' => [['REMOTE_ADDR' => '192.168.1.46', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '198.51.100.7'],
            'réseau docker : XFF cru' => [['REMOTE_ADDR' => '172.18.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '198.51.100.7'],
            'boucle locale : XFF cru' => [['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '198.51.100.7'],
            'IPv6 locale : XFF cru' => [['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::5'], '2001:db8::5'],
            'chaîne : premier élément' => [['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.9'], '198.51.100.7'],
            'espaces' => [['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '  198.51.100.7  '], '198.51.100.7'],
            'XFF invalide : on garde le pair' => [['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'pas-une-ip'], '10.0.0.2'],
            'XFF vide' => [['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => ''], '10.0.0.2'],
            'pas de XFF' => [['REMOTE_ADDR' => '10.0.0.2'], '10.0.0.2'],
            'IPv6 publique, XFF ignoré' => [['REMOTE_ADDR' => '2001:4860:4860::8888', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4'], '2001:4860:4860::8888'],
            'rien du tout' => [[], 'unknown'],
        ];
    }

    /** @param array<string, string> $server */
    #[DataProvider('ips')]
    public function testClientIp(array $server, string $expected): void
    {
        $this->assertSame($expected, ClientIp::resolve($server));
    }
}
