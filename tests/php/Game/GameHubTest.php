<?php

declare(strict_types=1);

namespace Chicken\Tests\Game;

use Chicken\Game\Client;
use Chicken\Game\ClientStatus;
use Chicken\Game\GameHub;
use Chicken\Game\HubConfig;
use Chicken\Physics\Launch;
use Chicken\Ranking\RankingStore;
use Chicken\Support\CheckMode;
use Chicken\Tests\Game\Support\FakeTransport;
use Chicken\Tests\Game\Support\MutableClock;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Stringable;

/** Le jeu de bout en bout, sans réseau : clients simulés, horloge contrôlée, journal capturé. */
final class GameHubTest extends TestCase
{
    private const string TA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string TB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $dir;
    private int $ipCounter = 0;
    private MutableClock $clock;
    /** @var list<string> */
    private array $log = [];
    private GameHub $hub;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/chicken-hub-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->clock = new MutableClock();
        $this->hub = $this->hub();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
    }

    private function hub(?HubConfig $config = null): GameHub
    {
        $logger = new class ($this->log) extends AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $replace = [];
                foreach ($context as $key => $value) {
                    $replace['{' . $key . '}'] = is_scalar($value) ? (string) $value : json_encode($value);
                }
                $this->lines[] = strtr((string) $message, $replace);
            }
        };

        return GameHub::create(
            $config ?? new HubConfig(rankMinPointSeconds: 0.0),
            new RankingStore("{$this->dir}/multi_scores.json"),
            $logger,
            $this->clock,
            new Randomizer(new Mt19937(1)),
        );
    }

    /** @return array{Client, FakeTransport} */
    private function join(string $name, ?string $token = null, ?string $ip = null): array
    {
        $transport = new FakeTransport();
        $client = $this->hub->accept($transport);
        $client->setAddress($ip ?? '203.0.113.' . (++$this->ipCounter));        // une adresse différente par navigateur, sauf demande contraire
        $this->say($client, ['t' => 'hello', 'name' => $name, 'kind' => 1, 'token' => $token]);

        return [$client, $transport];
    }

    /** @param array<string, mixed> $msg */
    private function say(Client $client, array $msg): void
    {
        $this->hub->receive($client, json_encode($msg));
    }

    /** Lancer légitime que l'interface produit : droit vers le centre du fond, assez lent pour être rattrapable. */
    private static function hen(): array
    {
        $y0 = 0.6;
        $T = 1.0;

        return ['x' => 0.0, 'y' => $y0, 'vx' => 0.0, 'vy' => -$y0 / $T - 0.5 * 0.5 * $T, 'spin' => 0.0, 'speed' => 1.0];
    }

    /** @return array{Client, FakeTransport, Client, FakeTransport} deux joueurs en match (le premier sert) */
    private function startMatch(?string $tokenA = null, ?string $tokenB = null): array
    {
        [$a, $ta] = $this->join('Alice', $tokenA);
        [$b, $tb] = $this->join('Bob', $tokenB);
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->say($b, ['t' => 'accept', 'from' => $a->id]);
        $first = $ta->last('match')['serve'] ? [$a, $ta, $b, $tb] : [$b, $tb, $a, $ta];

        return $first;
    }

    private function playPoint(Client $thrower, Client $defender): void
    {
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->say($defender, ['t' => 'miss']);
    }

    // ---- salon --------------------------------------------------------------------------------------------------

    public function testHelloWelcomesAndListsThePlayers(): void
    {
        [$alice, $ta] = $this->join('Alice', self::TA);
        $this->assertSame(['t' => 'welcome', 'id' => $alice->id, 'name' => 'Alice', 'ranked' => true], $ta->last('welcome'));
        [, $tb] = $this->join('Bob');
        $this->assertFalse($tb->last('welcome')['ranked'], 'sans jeton : invité');
        $names = array_column($ta->last('lobby')['players'], 'name');
        $this->assertSame(['Alice', 'Bob'], $names, 'Alice a été prévenue de l\'arrivée de Bob');
    }

    public function testNamesAreMadeUniqueAndInvalidOnesAreRefused(): void
    {
        $this->join('Alice');
        [, $second] = $this->join('ALICE');
        $this->assertSame('ALICE2', $second->last('welcome')['name']);
        [$anon, $transport] = $this->join('<<>>');
        $this->assertSame('invalid_name', $transport->last('error')['code']);
        $this->assertFalse($anon->isNamed);
        $this->say($anon, ['t' => 'auto']);
        $this->assertSame(ClientStatus::New, $anon->status, 'sans pseudo, rien d\'autre n\'est accepté');
    }

    public function testPingAnswersEvenBeforeHello(): void
    {
        $transport = new FakeTransport();
        $client = $this->hub->accept($transport);
        $this->say($client, ['t' => 'ping']);
        $this->assertSame(['pong'], $transport->types());
    }

    public function testGarbageIsIgnored(): void
    {
        [$client, $transport] = $this->join('Alice');
        $count = count($transport->sent);
        foreach (['pas du json', '"texte"', '5', '{"t":"inconnu"}', '{"t":["x"]}', '{}'] as $raw) {
            $this->hub->receive($client, $raw);
        }
        $this->assertCount($count, $transport->sent);
    }

    public function testFloodingIsIgnored(): void
    {
        [$client, $transport] = $this->join('Alice');
        for ($i = 0; $i < 200; $i++) {
            $this->say($client, ['t' => 'ping']);
        }
        $this->assertLessThanOrEqual(80, count($transport->of('pong')));
        $this->clock->advance(1);
        $this->say($client, ['t' => 'ping']);
        $this->assertGreaterThan(79, count($transport->of('pong')), 'le quota se renouvelle chaque seconde');
    }

    public function testChallengeAcceptStartsAMatch(): void
    {
        [$a, $ta] = $this->join('Alice');
        [$b, $tb] = $this->join('Bob');
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->assertSame('Bob', $ta->last('waiting')['name']);
        $this->assertSame(['id' => $a->id, 'name' => 'Alice', 'kind' => 1], $tb->last('invite')['from']);
        $this->say($b, ['t' => 'accept', 'from' => $a->id]);
        $this->assertSame([ClientStatus::InMatch, ClientStatus::InMatch], [$a->status, $b->status]);
        $this->assertSame('Bob', $ta->last('match')['opp']['name']);
        $this->assertNotSame($ta->last('match')['serve'], $tb->last('match')['serve'], 'un seul des deux sert');
        $this->assertContains('Match #1 : Alice vs Bob', $this->log);
    }

    public function testDeclineTimeoutAndUnavailableTargets(): void
    {
        [$a, $ta] = $this->join('Alice');
        [$b, $tb] = $this->join('Bob');
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->say($b, ['t' => 'decline', 'from' => $a->id]);
        $this->assertSame(['refused', 'Bob'], [$ta->last('declined')['code'], $ta->last('declined')['name']]);
        $this->say($b, ['t' => 'accept', 'from' => $a->id]);
        $this->assertSame('stale_invite', $tb->last('error')['code'], 'le défi décliné n\'existe plus');

        $this->say($a, ['t' => 'challenge', 'to' => $a->id]);
        $this->assertSame('unavailable', $ta->last('declined')['code'], 'on ne se défie pas soi-même');
        $this->say($a, ['t' => 'challenge', 'to' => 999]);
        $this->assertSame('unavailable', $ta->last('declined')['code']);

        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->clock->advance(21);
        $this->hub->tick();
        $this->assertSame('timeout', $ta->last('declined')['code']);
        $this->assertSame($a->id, $tb->last('invite_cancel')['from']);
    }

    public function testAutomaticMatchmakingPairsTwoQueuedPlayers(): void
    {
        [$a, $ta] = $this->join('Alice');
        [$b, $tb] = $this->join('Bob');
        $this->say($a, ['t' => 'auto']);
        $this->assertSame(ClientStatus::Queue, $a->status);
        $this->assertNotNull($ta->last('queued'));
        $this->say($a, ['t' => 'cancel']);
        $this->assertSame(ClientStatus::Lobby, $a->status);
        $this->assertNotNull($ta->last('unqueued'));
        $this->say($a, ['t' => 'auto']);
        $this->say($b, ['t' => 'auto']);
        $this->assertSame([ClientStatus::InMatch, ClientStatus::InMatch], [$a->status, $b->status]);
        $this->assertNotNull($tb->last('match'));
    }

    public function testDisconnectingCancelsPendingInvitationsAndAnnouncesTheDeparture(): void
    {
        [$a] = $this->join('Alice');
        [$b, $tb] = $this->join('Bob');
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->hub->disconnect($a);
        $this->assertSame($a->id, $tb->last('invite_cancel')['from']);
        $this->assertSame(['Bob'], array_column($tb->last('lobby')['players'], 'name'));
        $this->assertContains('- Alice (#1) left', $this->log);
        $this->assertSame(1, $this->hub->lobby->count());
    }

    // ---- match --------------------------------------------------------------------------------------------------

    public function testThrowThenMissScoresForTheThrower(): void
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->assertSame(1, $td->last('throw')['rally']);
        $this->assertEqualsWithDelta(self::hen()['vy'], $td->last('throw')['hen']['vy'], 1e-9);
        $this->say($defender, ['t' => 'miss']);
        $result = $tt->last('result');
        $this->assertSame(['goal', 'thrower', 'you', 1, 0, 'you'], [$result['kind'], $result['role'], $result['by'], $result['you'], $result['opp'], $result['next']]);
        $this->assertSame(['defender', 'opp', 0, 1], [$td->last('result')['role'], $td->last('result')['by'], $td->last('result')['you'], $td->last('result')['opp']]);
    }

    public function testOnlyTheServingPlayerMayThrowAndOnlyInTheServePhase(): void
    {
        [$thrower, , $defender, $td] = $this->startMatch();
        $this->say($defender, ['t' => 'throw', 'hen' => self::hen()]);
        $this->assertNull($td->last('throw'), 'ce n\'est pas son tour');
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->assertCount(1, $td->of('throw'), 'la poule est déjà en vol');
        $this->say($thrower, ['t' => 'miss']);
        $this->say($thrower, ['t' => 'catch', 'x' => 0, 'y' => 0]);
        $this->assertCount(0, $td->of('result'), 'le lanceur ne peut pas répondre à la place du défenseur');
    }

    public function testImpossibleThrowsAreRefusedInEnforceModeAndLoggedInLogMode(): void
    {
        $cheat = ['speed' => 1.9] + self::hen();
        [$thrower, , , $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => $cheat]);
        $this->assertNull($td->last('throw'));
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'REFUSED lancer')));

        $this->log = [];
        $this->clock->advance(1);
        $this->hub = $this->hub(new HubConfig(throwMode: CheckMode::Log, rankMinPointSeconds: 0.0));
        [$thrower, , , $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => $cheat]);
        $this->assertNotNull($td->last('throw'), 'mode log : accepté');
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'suspect lancer')));
    }

    public function testMalformedThrowsAreIgnored(): void
    {
        [$thrower, , , $td] = $this->startMatch();
        foreach ([null, 'texte', ['x' => 0], ['x' => 'a'] + self::hen()] as $hen) {
            $this->say($thrower, ['t' => 'throw', 'hen' => $hen]);
        }
        $this->assertNull($td->last('throw'));
    }

    public function testAnImpossibleCatchBecomesAGoalForTheThrower(): void
    {
        [$thrower, $tt, $defender] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->clock->advance(0.9);
        $this->say($defender, ['t' => 'catch', 'x' => 1.4, 'y' => 1.4]);          // main à l'autre bout du tunnel
        $result = $tt->last('result');
        $this->assertSame(['goal', true, 'you'], [$result['kind'], $result['corrected'], $result['by']]);
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'REFUSED capture Bob') || str_starts_with($l, 'REFUSED capture Alice')));
    }

    public function testAnHonestCatchMakesTheDefenderTheNextThrower(): void
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->clock->advance(0.9);
        $this->say($defender, ['t' => 'catch', 'x' => 0.0, 'y' => 0.0]);
        $result = $td->last('result');
        $this->assertSame(['catch', 'you', 'you'], [$result['kind'], $result['by'], $result['next']]);
        $this->assertSame('opp', $tt->last('result')['next']);
        // le défenseur sert maintenant
        $this->say($defender, ['t' => 'throw', 'hen' => self::hen()]);
        $this->assertSame(2, $tt->last('throw')['rally']);
    }

    public function testACatchInstantlyAfterTheThrowIsIgnored(): void
    {
        [$thrower, , $defender, $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->clock->advance(0.05);
        $this->say($defender, ['t' => 'catch', 'x' => 0.0, 'y' => 0.0]);
        $this->assertCount(0, $td->of('result'));
    }

    public function testCatchChecksCanBeSwitchedOff(): void
    {
        $this->hub = $this->hub(new HubConfig(catchMode: CheckMode::Off, rankMinPointSeconds: 0.0));
        [$thrower, , $defender, $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'throw', 'hen' => self::hen()]);
        $this->clock->advance(0.5);
        $this->say($defender, ['t' => 'catch', 'x' => 1.4, 'y' => 1.4]);
        $this->assertSame('catch', $td->last('result')['kind'], 'le client est cru sur parole');
    }

    public function testTimeoutsAwardThePointToTheOpponentOfTheSlowPlayer(): void
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch();
        $this->clock->advance(31);
        $this->hub->tick();                                            // le serveur n'a pas lancé à temps
        $this->assertSame(['goal', 'opp'], [$tt->last('result')['kind'], $tt->last('result')['by']]);
        $this->clock->advance(3);                                      // animation du but terminée
        $this->say($defender, ['t' => 'throw', 'hen' => self::hen()]);       // le marqueur relance
        $this->clock->advance(11);
        $this->hub->tick();                                            // le défenseur ne répond pas : le lanceur marque
        $this->assertSame(2, $td->last('result')['you'] + $td->last('result')['opp']);
    }

    public function testAMatchEndsAtFourPointsWithTwoAhead(): void
    {
        [$thrower, $tt, $defender] = $this->startMatch();
        for ($i = 0; $i < 4; $i++) {
            $this->playPoint($thrower, $defender);
            $this->clock->advance(3);
        }
        $this->assertTrue($tt->last('result')['over']);
        $this->assertContains('Match #1 finished: ' . ($thrower->id < $defender->id ? '4-0' : '0-4'), $this->log);
    }

    public function testRematchRestartsWhenBothAgreeAndTheLoserServes(): void
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch();
        for ($i = 0; $i < 4; $i++) {
            $this->playPoint($thrower, $defender);
            $this->clock->advance(3);
        }
        $this->say($thrower, ['t' => 'rematch']);
        $this->assertNotNull($td->last('rematch_ask'));
        $this->say($defender, ['t' => 'rematch']);
        $this->assertFalse($tt->last('match')['serve'], 'celui qui n\'a pas marqué en dernier sert');
        $this->assertTrue($td->last('match')['serve']);
        $this->assertCount(2, $tt->of('match'));
    }

    public function testRematchIsIgnoredWhileTheMatchIsStillRunning(): void
    {
        [$thrower, , $defender, $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'rematch']);
        $this->say($defender, ['t' => 'rematch']);
        $this->assertCount(0, $td->of('rematch_ask'));
        $this->assertCount(1, $td->of('match'));
    }

    public function testPositionsAreRelayedAndClamped(): void
    {
        [$thrower, , , $td] = $this->startMatch();
        $this->say($thrower, ['t' => 'pos', 'x' => 5]);
        $this->assertEquals(1.0, $td->last('pos')['x']);
        $this->say($thrower, ['t' => 'pos', 'x' => 'a']);
        $this->assertCount(1, $td->of('pos'));
    }

    public function testLeavingMidMatchMakesTheOpponentWinAndFreesBoth(): void
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch(self::TA, self::TB);
        $this->playPoint($thrower, $defender);                         // 1-0 : un point au moins
        $this->clock->advance(3);
        $this->say($defender, ['t' => 'leave']);
        $this->assertNotNull($td->last('left'));
        $this->assertNotNull($tt->last('oppleft'));
        $this->assertSame([ClientStatus::Lobby, ClientStatus::Lobby], [$thrower->status, $defender->status]);
        $this->assertSame('win', $tt->last('rated')['result'], 'partir en cours de match = défaite');
        $this->assertSame('loss', $td->last('rated')['result']);
    }

    public function testLeavingAtZeroZeroRecordsNothing(): void
    {
        [$thrower, $tt, $defender] = $this->startMatch(self::TA, self::TB);
        $this->say($defender, ['t' => 'leave']);
        $this->assertNull($tt->last('rated'));
        $this->assertFileDoesNotExist("{$this->dir}/multi_scores.json");
    }

    // ---- classement ---------------------------------------------------------------------------------------------

    private function playRankedMatch(): array
    {
        [$thrower, $tt, $defender, $td] = $this->startMatch(self::TA, self::TB);
        for ($i = 0; $i < 4; $i++) {
            $this->playPoint($thrower, $defender);
            $this->clock->advance(3);
        }

        return [$thrower, $tt, $defender, $td];
    }

    public function testARankedMatchUpdatesRatingsAndPersistsThem(): void
    {
        [$thrower, $tt, $defender, $td] = $this->playRankedMatch();
        $win = $tt->last('rated');
        $this->assertEquals(['win', 1020, 20, 1, 1.0, 2], [$win['result'], $win['rating'], $win['delta'], $win['rank'], $win['factor'], $win['total']]);
        $this->assertSame(['loss', 980, -20, 2], [$td->last('rated')['result'], $td->last('rated')['rating'], $td->last('rated')['delta'], $td->last('rated')['rank']]);
        $this->assertTrue($tt->last('match')['ranked']);
        $saved = json_decode((string) file_get_contents("{$this->dir}/multi_scores.json"), true);
        $this->assertEqualsCanonicalizing(['Alice', 'Bob'], array_column($saved['players'], 'name'));
        $this->assertCount(1, $saved['pairs']);
    }

    public function testGuestsPlayUnrankedMatches(): void
    {
        [$a, $ta] = $this->join('Alice', self::TA);
        [$b] = $this->join('Bob');                                      // invité
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->say($b, ['t' => 'accept', 'from' => $a->id]);
        $this->assertFalse($ta->last('match')['ranked']);
    }

    public function testTheSameBrowserCannotPlayAgainstItself(): void
    {
        [$a, $ta] = $this->join('Alice', self::TA);
        [$b] = $this->join('Alice Bis', self::TA);
        $this->say($a, ['t' => 'challenge', 'to' => $b->id]);
        $this->say($b, ['t' => 'accept', 'from' => $a->id]);
        $this->assertFalse($ta->last('match')['ranked']);
    }

    public function testAReservedNameIsRenamedForAnotherToken(): void
    {
        $this->playRankedMatch();
        $this->hub = $this->hub();                                       // le serveur redémarre : le classement est relu
        [, $usurper] = $this->join('Alice', 'dddddddddddddddddddddddddddddddd');
        $this->assertSame('Alice2', $usurper->last('welcome')['name']);
        [, $owner] = $this->join('Alice', self::TA);
        $this->assertSame('Alice', $owner->last('welcome')['name'], 'le propriétaire récupère son pseudo');
    }

    public function testTooFastRankedMatchesAreNotRanked(): void
    {
        $this->hub = $this->hub(new HubConfig());                          // 1,5 s par point exigé
        [$thrower, $tt, $defender, $td] = $this->startMatch(self::TA, self::TB);
        for ($i = 0; $i < 4; $i++) {
            $this->playPoint($thrower, $defender);
            $this->clock->advance(0.1);
        }
        $this->assertSame('too_fast', $tt->last('unrated')['reason']);
        $this->assertNotNull($td->last('unrated'));
        $this->assertNull($tt->last('rated'));
        $this->assertNotEmpty(array_filter($this->log, static fn (string $l): bool => str_starts_with($l, 'Unranked match #1')));
    }

    public function testRepeatedMeetingsOfTheSamePairPayLessAndLess(): void
    {
        $factors = [];
        for ($game = 0; $game < 5; $game++) {
            $this->hub = $this->hub();
            [$thrower, $tt, $defender] = $this->startMatch(self::TA, self::TB);
            for ($i = 0; $i < 4; $i++) {
                $this->playPoint($thrower, $defender);
                $this->clock->advance(3);
            }
            $factors[] = $tt->last('rated')['factor'];
        }
        $this->assertEquals([1.0, 1.0, 0.5, 0.25, 0.0], $factors);
    }

    // ---- anti-triche par adresse IP --------------------------------------------------------------------------------

    public function testPlayersOnTheSameAddressPlayAFriendlyMatchWithAReason(): void
    {
        [$a, $ta] = $this->join('Alice', self::TA, '198.51.100.5');
        [$b, $tb] = $this->join('Bob', self::TB, '198.51.100.5');
        $this->say($a, ['t' => 'auto']);
        $this->say($b, ['t' => 'auto']);
        $this->assertFalse($ta->last('match')['ranked']);
        $this->assertSame('same_ip', $ta->last('match')['why']);
        $this->assertSame('same_ip', $tb->last('match')['why']);
    }

    public function testPlayersOnDifferentAddressesAreRankedWithoutAnyReason(): void
    {
        [$a, $ta] = $this->join('Alice', self::TA, '198.51.100.5');
        [$b] = $this->join('Bob', self::TB, '198.51.100.6');
        $this->say($a, ['t' => 'auto']);
        $this->say($b, ['t' => 'auto']);
        $this->assertTrue($ta->last('match')['ranked']);
        $this->assertArrayNotHasKey('why', $ta->last('match'));
    }

    public function testAFourthAccountFromOneAddressIsNotRanked(): void
    {
        $results = [];
        for ($i = 1; $i <= 4; $i++) {
            [$a, $ta] = $this->join("Farm$i", str_repeat((string) $i, 32), '198.51.100.50');
            [$b] = $this->join("Real$i", str_repeat(chr(96 + $i), 32), "198.51.100.1$i");
            $this->say($a, ['t' => 'auto']);
            $this->say($b, ['t' => 'auto']);
            $results[] = [$ta->last('match')['ranked'], $ta->last('match')['why'] ?? null];
            $this->hub->disconnect($a);
            $this->hub->disconnect($b);
        }
        $this->assertSame([[true, null], [true, null], [true, null], [false, 'too_many_accounts']], $results);
    }

    public function testAGuestWithoutTokenIsStillUnrankedWithoutAReason(): void
    {
        [$a] = $this->join('Alice', self::TA, '198.51.100.5');
        [$g, $tg] = $this->join('Guest', null, '198.51.100.6');
        $this->say($a, ['t' => 'auto']);
        $this->say($g, ['t' => 'auto']);
        $this->assertFalse($tg->last('match')['ranked']);
        $this->assertArrayNotHasKey('why', $tg->last('match'));
    }
}
