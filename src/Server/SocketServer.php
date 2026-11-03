<?php

declare(strict_types=1);

namespace Chicken\Server;

use Chicken\Game\GameHub;
use Chicken\WebSocket\FrameCodec;
use Chicken\WebSocket\Handshake;
use Chicken\WebSocket\Opcode;
use Chicken\WebSocket\ProtocolException;
use RuntimeException;

/**
 * Serveur WebSocket (RFC 6455) en PHP pur : une boucle stream_select, des sockets non bloquantes. Il ne connaît du jeu
 * que le GameHub : connexions entrantes, messages texte complets, déconnexions, battement (tick).
 */
final class SocketServer
{
    final public const int MAX_CLIENTS = 500;
    final public const int IDLE_TIMEOUT = 90;                  // s sans aucun message : déconnexion
    final public const int READ_SIZE = 8192;
    final public const int MAX_BUFFER = 20000;

    /** @var array<int, Connection> indexées par (int) socket */
    private array $connections = [];

    /** @param resource $listener */
    private function __construct(
        private readonly mixed $listener,
        private readonly GameHub $hub,
        private readonly Handshake $handshake,
        private readonly FrameCodec $codec = new FrameCodec(),
    ) {
    }

    /**
     * Ouvre le port d'écoute.
     *
     * @throws RuntimeException si le port est indisponible
     */
    public static function listen(int $port, GameHub $hub, Handshake $handshake): self
    {
        $socket = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("Cannot listen on port $port: $errstr");
        }
        stream_set_blocking($socket, false);

        return new self($socket, $hub, $handshake);
    }

    /** Boucle principale : ne rend jamais la main. */
    public function run(): never
    {
        while (true) {
            $this->cycle();
        }
    }

    /** Un tour de boucle : attend l'activité des sockets (200 ms au plus), la traite, puis fait battre le jeu. */
    public function cycle(): void
    {
        $read = [$this->listener];
        $write = [];
        $except = null;
        foreach ($this->connections as $connection) {
            $read[] = $connection->socket;
            if ($connection->wantsWrite) {
                $write[] = $connection->socket;
            }
        }
        if (@stream_select($read, $write, $except, 0, 200000) === false) {
            return;
        }
        foreach ($read as $socket) {
            $socket === $this->listener ? $this->acceptConnection() : $this->readFrom($socket);
        }
        foreach ($write as $socket) {
            ($this->connections[(int) $socket] ?? null)?->flush();
        }
        $this->tick();
    }

    private function acceptConnection(): void
    {
        $socket = @stream_socket_accept($this->listener, 0);
        if ($socket === false) {
            return;
        }
        if ($this->hub->lobby->count() >= self::MAX_CLIENTS) {
            fclose($socket);

            return;
        }
        stream_set_blocking($socket, false);
        $connection = new Connection($socket);
        $connection->client = $this->hub->accept($connection);
        $this->connections[(int) $socket] = $connection;
    }

    /** @param resource $socket */
    private function readFrom(mixed $socket): void
    {
        $connection = $this->connections[(int) $socket] ?? null;
        if ($connection === null) {
            return;
        }
        $data = @fread($socket, self::READ_SIZE);
        if ($data === false || ($data === '' && feof($socket))) {
            $this->drop($connection);

            return;
        }
        $connection->inbound .= $data;
        if (strlen($connection->inbound) > self::MAX_BUFFER) {
            $this->drop($connection);

            return;
        }
        if (!$connection->upgraded && !$this->upgrade($connection)) {
            return;
        }
        $this->readFrames($connection);
    }

    /** Poignée de main HTTP → WebSocket. Retourne faux tant qu'elle n'est pas faite (incomplète ou refusée). */
    private function upgrade(Connection $connection): bool
    {
        $end = strpos($connection->inbound, "\r\n\r\n");
        if ($end === false) {
            return false;
        }
        $head = substr($connection->inbound, 0, $end);
        $connection->inbound = (string) substr($connection->inbound, $end + 4);
        $response = $this->handshake->respond($head);
        if ($response === null) {
            $connection->writeRaw(Handshake::rejection());
            $this->drop($connection);

            return false;
        }
        $connection->writeRaw($response);
        $connection->upgraded = true;

        return true;
    }

    private function readFrames(Connection $connection): void
    {
        while (true) {
            try {
                $frame = $this->codec->decode($connection->inbound);
            } catch (ProtocolException) {
                $this->drop($connection, sendClose: true);                           // client non conforme

                return;
            }
            if ($frame === null) {
                return;                                                              // trame incomplète : on attend la suite
            }
            $connection->inbound = (string) substr($connection->inbound, $frame->consumed);

            switch ($frame->type()) {
                case Opcode::Close:
                    $this->drop($connection, sendClose: true);

                    return;
                case Opcode::Ping:
                    $connection->sendControl(Opcode::Pong, $frame->payload);
                    break;
                case Opcode::Text:
                case Opcode::Continuation:
                    $connection->fragment = $frame->opcode === Opcode::Text->value
                        ? $frame->payload
                        : ($connection->fragment ?? '') . $frame->payload;
                    if (strlen($connection->fragment) > FrameCodec::DEFAULT_MAX_PAYLOAD) {
                        $this->drop($connection, sendClose: true);

                        return;
                    }
                    if ($frame->fin) {
                        $message = $connection->fragment;
                        $connection->fragment = null;
                        $this->hub->receive($this->clientOf($connection), $message);
                        if (!isset($this->connections[(int) $connection->socket])) {
                            return;
                        }
                    }
                    break;
                default:
                    break;                                                           // pong, binaire : ignorés
            }
        }
    }

    private function clientOf(Connection $connection): \Chicken\Game\Client
    {
        return $connection->client ?? throw new RuntimeException('connection without client');
    }

    /** Fait battre le jeu (délais) puis expulse les connexions mortes ou muettes. */
    private function tick(): void
    {
        $this->hub->tick();
        $now = time();
        foreach ($this->connections as $connection) {
            if ($connection->dead || $this->clientOf($connection)->idleFor($now) > self::IDLE_TIMEOUT) {
                $this->drop($connection, sendClose: true);
            }
        }
    }

    private function drop(Connection $connection, bool $sendClose = false): void
    {
        if (!isset($this->connections[(int) $connection->socket])) {
            return;
        }
        if ($sendClose && $connection->upgraded) {
            $connection->writeRaw(FrameCodec::encode('', Opcode::Close));
        }
        $this->hub->disconnect($this->clientOf($connection));
        unset($this->connections[(int) $connection->socket]);
        $connection->close();
    }
}
