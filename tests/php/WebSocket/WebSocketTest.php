<?php

declare(strict_types=1);

namespace Chicken\Tests\WebSocket;

use Chicken\WebSocket\Frame;
use Chicken\WebSocket\FrameCodec;
use Chicken\WebSocket\Handshake;
use Chicken\WebSocket\Opcode;
use Chicken\WebSocket\ProtocolException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebSocketTest extends TestCase
{
    /** Construit une trame cliente (toujours masquée, comme l'exige la RFC 6455). */
    private static function clientFrame(string $payload, int $op = 0x1, bool $fin = true, string $mask = "\x12\x34\x56\x78", bool $masked = true): string
    {
        $len = strlen($payload);
        $b0 = ($fin ? 0x80 : 0) | $op;
        $m = $masked ? 0x80 : 0;
        $h = match (true) {
            $len < 126 => chr($b0) . chr($m | $len),
            $len < 65536 => chr($b0) . chr($m | 126) . pack('n', $len),
            default => chr($b0) . chr($m | 127) . pack('J', $len),
        };
        if (!$masked) {
            return $h . $payload;
        }
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $payload[$i] ^ $mask[$i & 3];
        }

        return $h . $mask . $out;
    }

    private static function decode(string $bytes, int $max = 8192): ?Frame
    {
        return (new FrameCodec($max))->decode($bytes);
    }

    // ---- poignée de main ----------------------------------------------------------------------------------------

    public function testAcceptKeyMatchesTheRfc6455Example(): void
    {
        $this->assertSame('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', Handshake::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='));
    }

    private static function request(array $extra = []): string
    {
        $headers = ['GET / HTTP/1.1', 'Host: localhost', 'Upgrade: websocket', 'Connection: Upgrade', 'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==', ...$extra];

        return implode("\r\n", $headers);
    }

    public function testHandshakeAnswers101WithTheAcceptKey(): void
    {
        $response = (new Handshake())->respond(self::request());
        $this->assertStringStartsWith('HTTP/1.1 101 Switching Protocols', (string) $response);
        $this->assertStringContainsString('Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', (string) $response);
        $this->assertStringEndsWith("\r\n\r\n", (string) $response);
    }

    public function testHandshakeRefusesNonWebSocketRequests(): void
    {
        $this->assertNull((new Handshake())->respond("GET / HTTP/1.1\r\nHost: x"));
        $this->assertNull((new Handshake())->respond("GET / HTTP/1.1\r\nUpgrade: websocket"), 'sans clé');
        $this->assertNull((new Handshake())->respond("GET / HTTP/1.1\r\nSec-WebSocket-Key: abc"), 'sans Upgrade');
        $this->assertStringStartsWith('HTTP/1.1 400', Handshake::rejection());
    }

    public function testHandshakeEnforcesTheOriginAllowList(): void
    {
        $strict = new Handshake(['https://jeu.example']);
        $this->assertNotNull($strict->respond(self::request(['Origin: https://jeu.example'])));
        $this->assertNull($strict->respond(self::request(['Origin: https://evil.example'])));
        $this->assertNull($strict->respond(self::request()), 'sans origine, quand une liste est imposée');
        $this->assertNotNull((new Handshake())->respond(self::request(['Origin: https://evil.example'])), 'liste vide : toutes les origines');
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $head = "GET / HTTP/1.1\r\nUPGRADE: WebSocket\r\nsec-websocket-key: dGhlIHNhbXBsZSBub25jZQ==";
        $this->assertNotNull((new Handshake())->respond($head));
    }

    // ---- encodage serveur -> client -----------------------------------------------------------------------------

    /** @return array<string, array{int}> */
    public static function payloadSizes(): array
    {
        return ['vide' => [0], 'court' => [5], '125' => [125], '126 (2 octets)' => [126], '300' => [300],
                '65535' => [65535], '65536 (8 octets)' => [65536]];
    }

    #[DataProvider('payloadSizes')]
    public function testEncodeFrameHeaderAndLength(int $size): void
    {
        $f = FrameCodec::encode(str_repeat('x', $size));
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
        $this->assertSame(0x8A, ord(FrameCodec::encode('', Opcode::Pong)[0]), 'pong');
        $this->assertSame(0x88, ord(FrameCodec::encode('', Opcode::Close)[0]), 'close');
    }

    // ---- décodage client -> serveur -----------------------------------------------------------------------------

    /** @return array<string, array{int}> */
    public static function decodableSizes(): array
    {
        return ['vide' => [0], 'court' => [5], '125' => [125], '126 (2 octets)' => [126], '300' => [300], 'limite 8192' => [8192]];
    }

    #[DataProvider('decodableSizes')]
    public function testDecodeRoundTrip(int $size): void
    {
        $payload = $size === 0 ? '' : random_bytes($size);
        $wire = self::clientFrame($payload);
        $f = self::decode($wire);
        $this->assertNotNull($f);
        $this->assertSame($payload, $f->payload);
        $this->assertSame(1, $f->opcode);
        $this->assertSame(Opcode::Text, $f->type());
        $this->assertTrue($f->fin);
        $this->assertSame(strlen($wire), $f->consumed);
    }

    public function testDecodeJsonMessage(): void
    {
        $msg = json_encode(['t' => 'hello', 'name' => 'gnié 🐔'], JSON_UNESCAPED_UNICODE);
        $this->assertSame($msg, self::decode(self::clientFrame($msg))->payload);
    }

    public function testEveryMaskKeyAndPayloadLengthWorks(): void
    {
        foreach (["\x00\x00\x00\x00", "\xff\xff\xff\xff", "\x01\x02\x03\x04", "\xde\xad\xbe\xef"] as $mask) {
            foreach ([1, 2, 3, 4, 5, 7, 8, 9, 31] as $len) {
                $payload = random_bytes($len);
                $this->assertSame($payload, self::decode(self::clientFrame($payload, 0x1, true, $mask))->payload, 'mask=' . bin2hex($mask) . " len=$len");
            }
        }
    }

    public function testIncompleteFramesReturnNullAtEveryPrefix(): void
    {
        foreach ([5, 130] as $size) {
            $full = self::clientFrame(str_repeat('a', $size));
            for ($cut = 0; $cut < strlen($full); $cut++) {
                $this->assertNull(self::decode(substr($full, 0, $cut)), "préfixe de $cut octets (payload $size)");
            }
            $this->assertInstanceOf(Frame::class, self::decode($full));
        }
    }

    public function testConsumedAllowsReadingSeveralFramesFromOneBuffer(): void
    {
        $buf = self::clientFrame('un') . self::clientFrame('deux') . self::clientFrame('trois');
        $got = [];
        while (($f = self::decode($buf)) !== null) {
            $got[] = $f->payload;
            $buf = substr($buf, $f->consumed);
        }
        $this->assertSame(['un', 'deux', 'trois'], $got);
        $this->assertSame('', $buf);
    }

    public function testControlAndContinuationOpcodesAndFinBit(): void
    {
        $this->assertSame(Opcode::Ping, self::decode(self::clientFrame('', 0x9))->type());
        $this->assertSame(Opcode::Close, self::decode(self::clientFrame('', 0x8))->type());
        $this->assertFalse(self::decode(self::clientFrame('part', 0x1, false))->fin);
        $this->assertSame(Opcode::Continuation, self::decode(self::clientFrame('suite', 0x0, true))->type());
        $this->assertNull(self::decode(self::clientFrame('bin', 0x2))->type(), 'binaire : non géré');
    }

    public function testUnmaskedClientFrameIsRejected(): void
    {
        $this->expectException(ProtocolException::class);
        self::decode(self::clientFrame('x', 0x1, true, "\0\0\0\0", false));
    }

    public function testOversizedFrameIsRejected(): void
    {
        $this->assertInstanceOf(Frame::class, self::decode(self::clientFrame(str_repeat('a', 8192))));
        $this->expectException(ProtocolException::class);
        self::decode(self::clientFrame(str_repeat('a', 8193)));
    }

    public function testAnnouncedLengthAloneIsEnoughToRefuse(): void
    {
        // on ne lit pas 4 Go avant de refuser
        foreach ([4_000_000_000, PHP_INT_MAX] as $length) {
            try {
                self::decode("\x81" . chr(0x80 | 127) . pack('J', $length));
                $this->fail("longueur $length acceptée");
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCustomPayloadLimit(): void
    {
        $this->assertInstanceOf(Frame::class, self::decode(self::clientFrame('abcde'), 5));
        $this->expectException(ProtocolException::class);
        self::decode(self::clientFrame('abcdef'), 5);
    }
}
