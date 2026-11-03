<?php

declare(strict_types=1);

namespace Chicken\Server;

use Chicken\Game\Client;
use Chicken\Game\Transport;
use Chicken\WebSocket\FrameCodec;
use Chicken\WebSocket\Opcode;

/** Une connexion TCP non bloquante : tampons d'entrée et de sortie, état de la poignée de main et des fragments. */
final class Connection implements Transport
{
    public string $inbound = '';
    public bool $upgraded = false;
    public ?string $fragment = null;
    public bool $dead = false;
    public ?Client $client = null;

    public private(set) string $outbound = '';

    /** Des octets attendent de pouvoir partir : la socket doit être surveillée en écriture. */
    public bool $wantsWrite {
        get => $this->outbound !== '';
    }

    /** @param resource $socket */
    public function __construct(public readonly mixed $socket)
    {
    }

    /** Message texte vers le client (ignoré tant que la poignée de main n'est pas faite). */
    #[\Override]
    public function send(string $json): void
    {
        if (!$this->upgraded) {
            return;
        }
        $this->outbound .= FrameCodec::encode($json);
        $this->flush();
    }

    /** Trame de contrôle (pong, close). */
    public function sendControl(Opcode $opcode, string $payload = ''): void
    {
        $this->outbound .= FrameCodec::encode($payload, $opcode);
        $this->flush();
    }

    /** Écrit autant que la socket l'accepte ; le reste attend le prochain tour de boucle. */
    public function flush(): void
    {
        if ($this->outbound === '') {
            return;
        }
        $written = @fwrite($this->socket, $this->outbound);
        if ($written === false) {
            $this->dead = true;

            return;
        }
        $this->outbound = (string) substr($this->outbound, $written);
    }

    public function writeRaw(string $bytes): void
    {
        @fwrite($this->socket, $bytes);
    }

    public function close(): void
    {
        @fclose($this->socket);
    }
}
