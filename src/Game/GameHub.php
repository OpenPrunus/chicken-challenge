<?php

declare(strict_types=1);

namespace Chicken\Game;

use Arcade\Server\Hub;
use Arcade\Server\Session;
use Arcade\Server\Transport;
use Arcade\Support\SystemClock;
use Chicken\Game\Handler\AcceptHandler;
use Chicken\Game\Handler\AutoHandler;
use Chicken\Game\Handler\CancelHandler;
use Chicken\Game\Handler\ChallengeHandler;
use Chicken\Game\Handler\DeclineHandler;
use Chicken\Game\Handler\HelloHandler;
use Chicken\Game\Handler\LeaveHandler;
use Chicken\Game\Handler\MessageHandler;
use Chicken\Physics\CatchArbiter;
use Chicken\Physics\ThrowValidator;
use Chicken\Ranking\RankingStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Random\Randomizer;

/**
 * Façade du jeu : tout ce que le serveur réseau a besoin de savoir. Il lui confie les connexions et les messages bruts,
 * le hub s'occupe du salon, des défis, des matchs et du classement.
 */
final class GameHub implements Hub
{
    /** @var array<string, MessageHandler> clé = valeur de ClientMessage */
    private array $handlers;

    public function __construct(
        public readonly Lobby $lobby,
        private readonly Invitations $invitations,
        private readonly MatchService $matches,
        private readonly HubConfig $config,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        HelloHandler $hello,
        ChallengeHandler $challenge,
        AcceptHandler $accept,
        DeclineHandler $decline,
        AutoHandler $auto,
        CancelHandler $cancel,
        LeaveHandler $leave,
    ) {
        $this->handlers = [
            ClientMessage::Hello->value => $hello, ClientMessage::Challenge->value => $challenge,
            ClientMessage::Accept->value => $accept, ClientMessage::Decline->value => $decline,
            ClientMessage::Auto->value => $auto, ClientMessage::Cancel->value => $cancel,
            ClientMessage::Leave->value => $leave,
        ];
    }

    /** Fabrique : assemble le hub et ses dépendances (racine de composition du jeu). */
    public static function create(
        HubConfig $config,
        RankingStore $store,
        LoggerInterface $logger,
        ClockInterface $clock = new SystemClock(),
        Randomizer $randomizer = new Randomizer(),
    ): self {
        $lobby = new Lobby();
        $invitations = new Invitations();
        $book = new RankingBook($store, $logger);
        $matches = new MatchService(
            config: $config,
            catchArbiter: new CatchArbiter(timeSlack: $config->catchTimeSlack),
            throwValidator: new ThrowValidator(),
            logger: $logger,
            lobby: $lobby,
            invitations: $invitations,
            book: $book,
            clock: $clock,
            randomizer: $randomizer,
        );

        return new self(
            lobby: $lobby,
            invitations: $invitations,
            matches: $matches,
            config: $config,
            clock: $clock,
            logger: $logger,
            hello: new HelloHandler($lobby, $book, $logger),
            challenge: new ChallengeHandler($lobby, $invitations, $matches),
            accept: new AcceptHandler($lobby, $invitations, $matches),
            decline: new DeclineHandler($lobby, $invitations),
            auto: new AutoHandler($lobby, $matches),
            cancel: new CancelHandler($lobby),
            leave: new LeaveHandler($matches),
        );
    }

    #[\Override]
    public function accept(Transport $transport): Client
    {
        $client = new Client($this->lobby->nextId(), $transport, (int) $this->clock->now()->format('U'));
        $this->lobby->add($client);

        return $client;
    }

    /** Un message texte complet reçu d'un client. */
    #[\Override]
    public function receive(Session $session, string $raw): void
    {
        $client = $this->clientOf($session);
        $now = (int) $this->clock->now()->format('U');
        if (!$client->acceptMessage($now)) {
            return;                                                             // anti-flood
        }
        $msg = json_decode($raw, true);
        if (!is_array($msg)) {
            return;
        }
        $type = is_string($msg['t'] ?? null) ? ClientMessage::tryFrom($msg['t']) : null;
        if ($type === null) {
            return;
        }
        if ($type === ClientMessage::Ping) {
            $client->send(Event::Pong);

            return;
        }
        if ($type !== ClientMessage::Hello && !$client->isNamed) {
            return;
        }
        if ($type->isMatchMessage()) {
            $this->matches->handle($client, $type, $msg);

            return;
        }
        $this->handlers[$type->value]->handle($client, $msg);
    }

    /** Le client a disparu (déconnexion, expulsion) : il quitte son match, ses défis, le salon. */
    #[\Override]
    public function disconnect(Session $session): void
    {
        $client = $this->clientOf($session);
        $this->matches->leave($client);
        foreach ($this->invitations->forget($client->id) as $recipient) {
            $this->lobby->get($recipient)?->send(Event::InviteCancel, ['from' => $client->id]);
        }
        $this->lobby->remove($client);
        if ($client->isNamed) {
            $this->logger->info('- {name} (#{id}) left', ['name' => $client->name, 'id' => $client->id]);
            $this->lobby->broadcast();
        }
    }

    #[\Override]
    public function population(): int
    {
        return $this->lobby->count();
    }

    private function clientOf(Session $session): Client
    {
        return $session instanceof Client ? $session : throw new \InvalidArgumentException('unknown session');
    }

    /** Délais : un joueur qui ne répond plus perd le point, un défi sans réponse expire. */
    #[\Override]
    public function tick(): void
    {
        $now = SystemClock::seconds($this->clock);
        $this->matches->tick($now);
        foreach ($this->invitations->expire($now, $this->config->inviteTtl) as ['from' => $from, 'to' => $to]) {
            $this->lobby->get($from)?->send(Event::Declined, ['code' => 'timeout', 'reason' => 'No answer.']);
            $this->lobby->get($to)?->send(Event::InviteCancel, ['from' => $from]);
        }
    }
}
