<?php

declare(strict_types=1);

namespace Chicken\WebSocket;

/** Encodage et décodage des trames WebSocket (RFC 6455, §5). */
final readonly class FrameCodec
{
    final public const int DEFAULT_MAX_PAYLOAD = 8192;

    public function __construct(private int $maxPayload = self::DEFAULT_MAX_PAYLOAD)
    {
    }

    /** Trame serveur → client (jamais masquée). */
    public static function encode(string $payload, Opcode $opcode = Opcode::Text): string
    {
        $len = strlen($payload);
        $header = chr(0x80 | $opcode->value);
        $header .= match (true) {
            $len < 126 => chr($len),
            $len < 65536 => chr(126) . pack('n', $len),
            default => chr(127) . pack('J', $len),
        };

        return $header . $payload;
    }

    /**
     * Décode la trame cliente au début de $buffer.
     *
     * @return Frame|null null si la trame est incomplète (attendre la suite)
     * @throws ProtocolException si elle est non conforme (non masquée, trop grande)
     */
    public function decode(string $buffer): ?Frame
    {
        $n = strlen($buffer);
        if ($n < 2) {
            return null;
        }
        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);
        $len = $b1 & 0x7F;
        $pos = 2;
        if ($len === 126) {
            if ($n < 4) {
                return null;
            }
            $len = self::unpackInt('n', substr($buffer, 2, 2));
            $pos = 4;
        } elseif ($len === 127) {
            if ($n < 10) {
                return null;
            }
            $len = self::unpackInt('J', substr($buffer, 2, 8));
            $pos = 10;
        }
        if (($b1 & 0x80) === 0 || $len > $this->maxPayload || $len < 0) {
            throw new ProtocolException('unmasked or oversized frame');       // la longueur annoncée suffit : on ne lit pas 4 Go avant de refuser
        }
        if ($n < $pos + 4 + $len) {
            return null;
        }
        $mask = substr($buffer, $pos, 4);
        $payload = substr($buffer, $pos + 4, $len);
        if ($len > 0) {
            $payload ^= substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
        }

        return new Frame(($b0 & 0x80) !== 0, $b0 & 0x0F, $payload, $pos + 4 + $len);
    }

    /** Entier réseau (big-endian) lu dans $bytes, dont la longueur a déjà été vérifiée. */
    private static function unpackInt(string $format, string $bytes): int
    {
        $values = unpack($format, $bytes);

        return $values === false ? 0 : (int) $values[1];
    }
}
