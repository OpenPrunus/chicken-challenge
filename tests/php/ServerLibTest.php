<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../server/lib.php';

final class ServerLibTest extends TestCase
{
    /** Construit une trame cliente (toujours masquée, comme l'exige la RFC 6455). */
    private static function clientFrame(string $payload, int $op = 0x1, bool $fin = true, string $mask = "\x12\x34\x56\x78", bool $masked = true): string
    {
        $len = strlen($payload);
        $b0 = ($fin ? 0x80 : 0) | $op;
        $m = $masked ? 0x80 : 0;
        if ($len < 126) $h = chr($b0) . chr($m | $len);
        elseif ($len < 65536) $h = chr($b0) . chr($m | 126) . pack('n', $len);
        else $h = chr($b0) . chr($m | 127) . pack('J', $len);
        if (!$masked) return $h . $payload;
        $out = '';
        for ($i = 0; $i < $len; $i++) $out .= $payload[$i] ^ $mask[$i & 3];
        return $h . $mask . $out;
    }

    // ---- poignée de main ----------------------------------------------------------------------------------------

    public function testAcceptKeyMatchesTheRfc6455Example(): void
    {
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', wsAcceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    // ---- encodage serveur -> client -----------------------------------------------------------------------------

    public static function payloadSizes(): array
    {
        return ['vide' => [0], 'court' => [5], '125' => [125], '126 (2 octets)' => [126], '300' => [300],
                '65535' => [65535], '65536 (8 octets)' => [65536]];
    }

    #[DataProvider('payloadSizes')]
    public function testEncodeFrameHeaderAndLength(int $size): void
    {
        $f = encodeFrame(str_repeat('x', $size));
        $this->assertSame(0x81, ord($f[0]), 'FIN + texte');
        if ($size < 126) {
            $this->assertSame($size, ord($f[1]));
            $this->assertSame($size + 2, strlen($f));
        } elseif ($size < 65536) {
            $this->assertSame(126, ord($f[1]));
            $this->assertSame($size, unpack('n', substr($f, 2, 2))[1]);
            $this->assertSame($size + 4, strlen($f));
        } else {
            $this->assertSame(127, ord($f[1]));
            $this->assertSame($size, unpack('J', substr($f, 2, 8))[1]);
            $this->assertSame($size + 10, strlen($f));
        }
        $this->assertSame(0, ord($f[1]) & 0x80, 'jamais masqué côté serveur');
    }

    public function testEncodeFrameOpcodes(): void
    {
        $this->assertSame(0x8A, ord(encodeFrame('', 0xA)[0]), 'pong');
        $this->assertSame(0x88, ord(encodeFrame('', 0x8)[0]), 'close');
    }

    // ---- décodage client -> serveur -----------------------------------------------------------------------------

    public static function decodableSizes(): array
    {
        return ['vide' => [0], 'court' => [5], '125' => [125], '126 (2 octets)' => [126], '300' => [300], 'limite 8192' => [8192]];
    }

    #[DataProvider('decodableSizes')]
    public function testDecodeRoundTrip(int $size): void
    {
        $payload = $size === 0 ? '' : random_bytes($size);
        $f = decodeFrame(self::clientFrame($payload));
        $this->assertIsArray($f);
        $this->assertSame($payload, $f['payload']);
        $this->assertSame(1, $f['op']);
        $this->assertTrue($f['fin']);
        $this->assertSame(strlen(self::clientFrame($payload)), $f['consumed']);
    }

    public function testDecodeJsonMessage(): void
    {
        $msg = json_encode(['t' => 'hello', 'name' => 'gnié 🐔'], JSON_UNESCAPED_UNICODE);
        $f = decodeFrame(self::clientFrame($msg));
        $this->assertSame($msg, $f['payload']);
    }

    public function testEveryMaskKeyAndPayloadLengthWorks(): void
    {
        foreach (["\x00\x00\x00\x00", "\xff\xff\xff\xff", "\x01\x02\x03\x04", "\xde\xad\xbe\xef"] as $mask) {
            foreach ([1, 2, 3, 4, 5, 7, 8, 9, 31] as $len) {
                $payload = random_bytes($len);
                $f = decodeFrame(self::clientFrame($payload, 0x1, true, $mask));
                $this->assertSame($payload, $f['payload'], "mask=" . bin2hex($mask) . " len=$len");
            }
        }
    }

    public function testIncompleteFramesReturnNullAtEveryPrefix(): void
    {
        foreach ([5, 130] as $size) {
            $full = self::clientFrame(str_repeat('a', $size));
            for ($cut = 0; $cut < strlen($full); $cut++) {
                $this->assertNull(decodeFrame(substr($full, 0, $cut)), "préfixe de $cut octets (payload $size)");
            }
            $this->assertIsArray(decodeFrame($full));
        }
    }

    public function testConsumedAllowsReadingSeveralFramesFromOneBuffer(): void
    {
        $a = self::clientFrame('un'); $b = self::clientFrame('deux'); $c = self::clientFrame('trois');
        $buf = $a . $b . $c;
        $got = [];
        while (($f = decodeFrame($buf)) !== null) {
            $got[] = $f['payload'];
            $buf = substr($buf, $f['consumed']);
        }
        $this->assertSame(['un', 'deux', 'trois'], $got);
        $this->assertSame('', $buf);
    }

    public function testControlAndContinuationOpcodesAndFinBit(): void
    {
        $this->assertSame(9, decodeFrame(self::clientFrame('', 0x9))['op'], 'ping');
        $this->assertSame(8, decodeFrame(self::clientFrame('', 0x8))['op'], 'close');
        $frag = decodeFrame(self::clientFrame('part', 0x1, false));
        $this->assertFalse($frag['fin']);
        $this->assertSame(0, decodeFrame(self::clientFrame('suite', 0x0, true))['op']);
    }

    public function testUnmaskedClientFrameIsRejected(): void
    {
        $this->assertFalse(decodeFrame(self::clientFrame('x', 0x1, true, "\0\0\0\0", false)));
    }

    public function testOversizedFrameIsRejected(): void
    {
        $this->assertIsArray(decodeFrame(self::clientFrame(str_repeat('a', 8192))));
        $this->assertFalse(decodeFrame(self::clientFrame(str_repeat('a', 8193))));
        // la longueur annoncée suffit : on ne lit pas 4 Go avant de refuser
        $this->assertFalse(decodeFrame("\x81" . chr(0x80 | 127) . pack('J', 4_000_000_000)));
        $this->assertFalse(decodeFrame("\x81" . chr(0x80 | 127) . pack('J', PHP_INT_MAX)));
    }

    public function testCustomPayloadLimit(): void
    {
        $this->assertFalse(decodeFrame(self::clientFrame('abcdef'), 5));
        $this->assertIsArray(decodeFrame(self::clientFrame('abcde'), 5));
    }

    // ---- règles et messages -------------------------------------------------------------------------------------

    public static function matchStates(): array
    {
        return [[0, 0, false], [3, 0, false], [4, 0, true], [0, 4, true], [4, 3, false], [4, 2, true], [5, 3, true],
                [5, 4, false], [11, 11, false], [12, 11, true], [11, 12, true], [12, 10, true], [10, 11, false]];
    }

    #[DataProvider('matchStates')]
    public function testMatchOver(int $a, int $b, bool $over): void
    {
        $this->assertSame($over, matchOver($a, $b));
    }

    public function testNumClampsAndRejectsNonNumbers(): void
    {
        $this->assertSame(0.5, num(0.5, -1, 1));
        $this->assertSame(1.0, num(5, -1, 1));
        $this->assertSame(-1.0, num(-99.9, -1, 1));
        $this->assertSame(1.9, num(2, 0.3, 1.9), 'borne haute');
        $this->assertSame(0.3, num(0, 0.3, 1.9), 'borne basse');
        foreach (['1', null, true, [], 'abc', new stdClass()] as $bad) {
            $this->assertNull(num($bad, -1, 1), 'rejette ' . get_debug_type($bad));
        }
        $this->assertNull(num(INF, -1, 1));
        $this->assertNull(num(-INF, -1, 1));
        $this->assertNull(num(NAN, -1, 1));
    }

    public function testCleanNameMatchesTheScoresRules(): void
    {
        $this->assertSame('Alice', cleanName(' Alice '));
        $this->assertSame(str_repeat('é', 15), cleanName(str_repeat('é', 40)));
        $this->assertSame('img srcx onerro', cleanName('<img src=x onerror=1>' . str_repeat('z', 40)));
        $this->assertSame('', cleanName('<<>>'));
    }

    public function testOtherOf(): void
    {
        $m = ['a' => 3, 'b' => 7];
        $this->assertSame(7, otherOf($m, 3));
        $this->assertSame(3, otherOf($m, 7));
    }

    public function testResultIsDescribedFromEachPlayersPerspective(): void
    {
        $m = ['a' => 1, 'b' => 2, 'score' => [1 => 3, 2 => 1], 'turn' => 1, 'thrower' => 1, 'rally' => 5];
        $forThrower = resultFor($m, 1, 'goal', 1, false);
        $forDefender = resultFor($m, 2, 'goal', 1, false);

        $this->assertSame(['thrower', 'you', 3, 1, 'you'], [$forThrower['role'], $forThrower['by'], $forThrower['you'], $forThrower['opp'], $forThrower['next']]);
        $this->assertSame(['defender', 'opp', 1, 3, 'opp'], [$forDefender['role'], $forDefender['by'], $forDefender['you'], $forDefender['opp'], $forDefender['next']]);
        $this->assertSame(5, $forThrower['rally']);
        $this->assertFalse($forThrower['over']);
        $this->assertTrue(resultFor($m, 1, 'goal', 1, true)['over']);
        $this->assertSame('catch', resultFor($m, 2, 'catch', 2, false)['kind']);
    }

    public function testEnvNumUsesEnvironmentOnlyWhenPositive(): void
    {
        $k = 'CHICKEN_TEST_' . bin2hex(random_bytes(3));
        $this->assertSame(7.0, envNum($k, 7));
        putenv("$k=2.5"); $this->assertSame(2.5, envNum($k, 7));
        putenv("$k=0");   $this->assertSame(7.0, envNum($k, 7), 'zéro ignoré');
        putenv("$k=-3");  $this->assertSame(7.0, envNum($k, 7), 'négatif ignoré');
        putenv("$k=abc"); $this->assertSame(7.0, envNum($k, 7), 'non numérique ignoré');
        putenv($k);
    }
}
