<?php

declare(strict_types=1);

namespace Chicken\WebSocket;

/** Poignée de main HTTP → WebSocket (RFC 6455, §4). */
final readonly class Handshake
{
    private const string GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @param list<string> $allowedOrigins vide = toutes les origines sont acceptées */
    public function __construct(private array $allowedOrigins = [])
    {
    }

    /** Valeur du Sec-WebSocket-Accept pour une clé client (§4.2.2). */
    public static function acceptKey(string $clientKey): string
    {
        return base64_encode(sha1($clientKey . self::GUID, true));
    }

    /** Réponse 101 si la requête (en-têtes bruts) est une mise à niveau WebSocket autorisée, sinon null. */
    public function respond(string $head): ?string
    {
        $headers = self::parseHeaders($head);
        $acceptable = isset($headers['sec-websocket-key'])
            && stripos($headers['upgrade'] ?? '', 'websocket') !== false
            && ($this->allowedOrigins === [] || in_array($headers['origin'] ?? '', $this->allowedOrigins, true));
        if (!$acceptable) {
            return null;
        }

        return "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Accept: ' . self::acceptKey($headers['sec-websocket-key']) . "\r\n\r\n";
    }

    public static function rejection(): string
    {
        return "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n";
    }

    /** @return array<string, string> en-têtes en minuscules */
    private static function parseHeaders(string $head): array
    {
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return $headers;
    }
}
