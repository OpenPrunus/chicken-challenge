<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../web/lib/scores.php';

/** Défenses du classement contre l'IA : tickets signés, durée minimale, limite de débit, adresse IP, pseudos. */
final class AntiAbuseTest extends TestCase
{
    private const SECRET = 'ce-secret-de-test-fait-bien-plus-de-trente-deux-caracteres';
    private const TOKEN = 'abababababababababababababababab';

    // ---- tickets ------------------------------------------------------------------------------------------------

    public function testTicketRoundTripCarriesItsFields(): void
    {
        $th = chicken_token_hash(self::TOKEN);
        $p = chicken_ticket_verify(chicken_ticket_make(self::SECRET, $th, 2, 1000, 'nonce1'), self::SECRET, 1000);
        $this->assertSame(['n' => 'nonce1', 't' => 1000, 'h' => $th, 'l' => 2], $p);
    }

    public function testEveryTicketHasItsOwnNonce(): void
    {
        $a = chicken_ticket_make(self::SECRET, 'h', 1, 1000);
        $b = chicken_ticket_make(self::SECRET, 'h', 1, 1000);
        $this->assertNotSame($a, $b);
        $this->assertNotSame(chicken_ticket_verify($a, self::SECRET, 1000)['n'], chicken_ticket_verify($b, self::SECRET, 1000)['n']);
    }

    public function testTicketSignedWithAnotherSecretIsRejected(): void
    {
        $t = chicken_ticket_make('un-autre-secret-aussi-long-que-le-vrai-secret-ci', 'h', 1, 1000);
        $this->assertNull(chicken_ticket_verify($t, self::SECRET, 1000));
    }

    public function testTamperedPayloadOrSignatureIsRejected(): void
    {
        $t = chicken_ticket_make(self::SECRET, 'h', 1, 1000, 'n1');
        [$payload, $sig] = explode('.', $t);
        $forgedPayload = chicken_b64url(json_encode(['n' => 'n1', 't' => 1000, 'h' => 'h', 'l' => 2]));   // niveau modifié, signature d'origine
        $this->assertNull(chicken_ticket_verify("$forgedPayload.$sig", self::SECRET, 1000), 'charge utile modifiée');
        $flip = $sig[0] === 'a' ? 'b' : 'a';
        $this->assertNull(chicken_ticket_verify($payload . '.' . $flip . substr($sig, 1), self::SECRET, 1000), 'signature modifiée');
        $this->assertNull(chicken_ticket_verify($payload . '.' . substr($sig, 0, -1), self::SECRET, 1000), 'signature tronquée');
        $this->assertNull(chicken_ticket_verify($payload . '.' . strtoupper($sig), self::SECRET, 1000), 'signature en majuscules');
    }

    public static function malformedTickets(): array
    {
        return ['vide' => [''], 'sans point' => ['abcdef'], 'point seul' => ['.'], 'trois parties' => ['a.b.c'], 'sans signature' => ['abc.'],
                'sans charge' => ['.abc'], 'base64 invalide' => ['@@@.@@@'], 'espaces' => [' . '], 'gigantesque' => [str_repeat('a', 5000) . '.' . str_repeat('b', 64)]];
    }

    #[DataProvider('malformedTickets')]
    public function testMalformedTicketsAreRejectedWithoutWarnings(string $ticket): void
    {
        $this->assertNull(chicken_ticket_verify($ticket, self::SECRET, 1000));
    }

    public function testTicketAgeBoundaries(): void
    {
        $t = chicken_ticket_make(self::SECRET, 'h', 1, 1000);
        $this->assertNotNull(chicken_ticket_verify($t, self::SECRET, 1000 + CHICKEN_TICKET_MAX_AGE), 'pile à la limite : accepté');
        $this->assertNull(chicken_ticket_verify($t, self::SECRET, 1000 + CHICKEN_TICKET_MAX_AGE + 1), 'expiré');
        $this->assertNotNull(chicken_ticket_verify($t, self::SECRET, 995), 'horloge légèrement en retard : tolérée (5 s)');
        $this->assertNull(chicken_ticket_verify($t, self::SECRET, 990), 'daté du futur : refusé');
        $this->assertNull(chicken_ticket_verify($t, self::SECRET, 5000, 3600), 'durée de vie personnalisée');
    }

    public function testSignedButMalformedPayloadsAreRejected(): void
    {
        foreach ([['n' => 'x', 't' => '1000', 'h' => 'h', 'l' => 1], ['n' => 1, 't' => 1000, 'h' => 'h', 'l' => 1], ['t' => 1000, 'h' => 'h', 'l' => 1], 'texte', [], ['n' => 'x', 't' => 1000, 'h' => ['a'], 'l' => 1]] as $payload) {
            $b = chicken_b64url(json_encode($payload));
            $ticket = $b . '.' . hash_hmac('sha256', $b, self::SECRET);
            $this->assertNull(chicken_ticket_verify($ticket, self::SECRET, 1000), json_encode($payload));
        }
    }

    public function testBase64UrlRoundTripAndRejection(): void
    {
        foreach (['', 'a', "bin\x00\xff\xfe", str_repeat('é', 50)] as $s) $this->assertSame($s, chicken_b64url_dec(chicken_b64url($s)));
        $this->assertFalse(chicken_b64url_dec('***'));
        $this->assertStringNotContainsString('=', chicken_b64url('a'));
    }

    // ---- durée minimale -----------------------------------------------------------------------------------------

    public function testMinimumDurationGrowsWithThePointsPlayed(): void
    {
        $this->assertSame(8.0, chicken_min_seconds(4, 0));
        $this->assertSame(10.0, chicken_min_seconds(4, 1));
        $this->assertSame(46.0, chicken_min_seconds(12, 11));
        $this->assertSame(2.0, chicken_min_seconds(1, 0, 2.0));
        $this->assertSame(0.0, chicken_min_seconds(12, 0, 0.0));
        $this->assertGreaterThan(chicken_min_seconds(4, 0), chicken_min_seconds(4, 2));
    }

    // ---- limite de débit ----------------------------------------------------------------------------------------

    public function testRateLimitAllowsUpToTheLimitThenRefusesWithRetryAfter(): void
    {
        $state = [];
        for ($i = 0; $i < 3; $i++) {
            [$ok, $state, $retry] = chicken_rate_check($state, 'ip:1', 1000 + $i, 3, 3600);
            $this->assertTrue($ok, "requête $i");
            $this->assertSame(0, $retry);
        }
        [$ok, $state2, $retry] = chicken_rate_check($state, 'ip:1', 1010, 3, 3600);
        $this->assertFalse($ok);
        $this->assertSame(1000 + 3600 - 1010, $retry, 'attente jusqu\'à l\'expiration de la plus ancienne');
        $this->assertSame($state, $state2, 'une requête refusée ne consomme rien');
    }

    public function testRateLimitWindowSlides(): void
    {
        $s = [];
        [, $s] = chicken_rate_check($s, 'k', 1000, 2, 100);
        [, $s] = chicken_rate_check($s, 'k', 1050, 2, 100);
        [$ok] = chicken_rate_check($s, 'k', 1099, 2, 100);
        $this->assertFalse($ok, 'toujours dans la fenêtre');
        [$ok, $s] = chicken_rate_check($s, 'k', 1101, 2, 100);
        $this->assertTrue($ok, 'la plus ancienne est sortie de la fenêtre');
        [$ok] = chicken_rate_check($s, 'k', 1102, 2, 100);
        $this->assertFalse($ok, 'mais 1050 et 1101 comptent encore');
    }

    public function testRateLimitKeysAreIndependentAndExpiredKeysArePurged(): void
    {
        $s = [];
        [, $s] = chicken_rate_check($s, 'a', 1000, 1, 100);
        [$ok, $s] = chicken_rate_check($s, 'b', 1000, 1, 100);
        $this->assertTrue($ok, 'une autre clé a son propre quota');
        [$ok] = chicken_rate_check($s, 'a', 1001, 1, 100);
        $this->assertFalse($ok);
        [, $s] = chicken_rate_check($s, 'c', 5000, 1, 100);
        $this->assertSame(['c'], array_keys($s), 'les clés expirées disparaissent (le fichier ne grossit pas)');
    }

    public function testRateLimitOfZeroRefusesEverything(): void
    {
        [$ok, , $retry] = chicken_rate_check([], 'k', 1000, 0, 100);
        $this->assertFalse($ok);
        $this->assertGreaterThanOrEqual(1, $retry);
    }

    // ---- adresse IP ---------------------------------------------------------------------------------------------

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

    #[DataProvider('ips')]
    public function testClientIp(array $server, string $expected): void
    {
        $this->assertSame($expected, chicken_client_ip($server));
    }

    // ---- pseudos réservés ---------------------------------------------------------------------------------------

    public function testNameReservation(): void
    {
        $rows = [
            ['name' => 'Ancien', 'played' => 3, 'wins' => 1, 'gf' => 5, 'ga' => 9],                       // ancienne ligne, sans jeton
            ['name' => 'Reine', 'th' => 'hashA', 'played' => 1, 'wins' => 1, 'gf' => 4, 'ga' => 0],
        ];
        $this->assertFalse(chicken_name_taken($rows, 'Ancien', 'hashB'), 'une ligne sans jeton n\'est réservée par personne');
        $this->assertTrue(chicken_name_taken($rows, 'Reine', 'hashB'));
        $this->assertTrue(chicken_name_taken($rows, 'REINE', 'hashB'), 'insensible à la casse');
        $this->assertFalse(chicken_name_taken($rows, 'Reine', 'hashA'), 'le propriétaire');
        $this->assertFalse(chicken_name_taken($rows, 'Inconnu', 'hashB'));
        $this->assertFalse(chicken_name_taken([], 'Reine', 'hashB'));
    }

    public function testApplyScoreStoresTheTokenAndNeverOverwritesIt(): void
    {
        $rows = chicken_apply_score([], 'Alice', 4, 1, 'hashA');
        $this->assertSame('hashA', $rows[0]['th']);
        $rows = chicken_apply_score($rows, 'alice', 4, 0, 'hashB');
        $this->assertSame('hashA', $rows[0]['th'], 'un autre jeton ne prend pas la place');
        $this->assertSame(2, $rows[0]['played']);

        $legacy = [['name' => 'Ancien', 'played' => 3, 'wins' => 1, 'gf' => 5, 'ga' => 9]];
        $claimed = chicken_apply_score($legacy, 'Ancien', 4, 0, 'hashC');
        $this->assertSame('hashC', $claimed[0]['th'], 'le premier jeton réserve une ligne ancienne');
        $unclaimed = chicken_apply_score($legacy, 'Ancien', 4, 0);
        $this->assertArrayNotHasKey('th', $unclaimed[0], 'sans jeton, rien n\'est ajouté');
    }

    public function testPublicBoardNeverContainsTheTokenHash(): void
    {
        $rows = chicken_apply_score(chicken_apply_score([], 'Alice', 4, 1, 'secretHashA'), 'Bob', 0, 4, 'secretHashB');
        $out = chicken_rank($rows);
        foreach ($out as $r) {
            $this->assertSame(['name', 'played', 'wins', 'gf', 'ga'], array_keys($r));
        }
        $this->assertStringNotContainsString('secretHash', json_encode($out));
    }

    // ---- jeton / environnement ----------------------------------------------------------------------------------

    public function testTokenValidationAndHash(): void
    {
        $this->assertTrue(chicken_token_valid(self::TOKEN));
        foreach (['', 'abc', str_repeat('g', 32), str_repeat('A', 32), null, 5, [], str_repeat('a', 65)] as $bad) $this->assertFalse(chicken_token_valid($bad));
        $this->assertSame(64, strlen(chicken_token_hash(self::TOKEN)));
        $this->assertSame(hash('sha256', self::TOKEN), chicken_token_hash(self::TOKEN));
    }

    public function testEnvNumReadsPositiveNumbersOnly(): void
    {
        $k = 'CHICKEN_T_' . bin2hex(random_bytes(3));
        $this->assertSame(7.5, chicken_env_num($k, 7.5));
        putenv("$k=2");   $this->assertSame(2.0, chicken_env_num($k, 7.5));
        putenv("$k=0");   $this->assertSame(0.0, chicken_env_num($k, 7.5), 'zéro autorisé (désactive une durée minimale)');
        putenv("$k=-1");  $this->assertSame(7.5, chicken_env_num($k, 7.5));
        putenv("$k=abc"); $this->assertSame(7.5, chicken_env_num($k, 7.5));
        putenv($k);
    }
}
