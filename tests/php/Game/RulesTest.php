<?php

declare(strict_types=1);

namespace Chicken\Tests\Game;

use Chicken\Game\GameMatch;
use Chicken\Game\MatchRules;
use Chicken\Game\MatchState;
use Chicken\Physics\Launch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    /** @return list<array{int, int, bool}> */
    public static function matchStates(): array
    {
        return [[0, 0, false], [3, 0, false], [4, 0, true], [0, 4, true], [4, 3, false], [4, 2, true], [5, 3, true],
                [5, 4, false], [11, 11, false], [12, 11, true], [11, 12, true], [12, 10, true], [10, 11, false]];
    }

    #[DataProvider('matchStates')]
    public function testMatchOver(int $a, int $b, bool $over): void
    {
        $this->assertSame($over, MatchRules::isOver($a, $b));
    }

    public function testFinalScoreAlsoRequiresPlausibleBounds(): void
    {
        $this->assertTrue(MatchRules::isFinalScore(12, 11));
        $this->assertFalse(MatchRules::isFinalScore(13, 0));
        $this->assertFalse(MatchRules::isFinalScore(-1, 4));
        $this->assertFalse(MatchRules::isFinalScore(4, 3));
    }

    private static function match(): GameMatch
    {
        return new GameMatch(1, 3, 7, 3, true, 100.0);
    }

    public function testOtherPlayerAndInitialState(): void
    {
        $m = self::match();
        $this->assertSame([7, 3], [$m->other(3), $m->other(7)]);
        $this->assertSame([MatchState::Serve, 3, 0], [$m->state, $m->turn, $m->totalPoints]);
    }

    public function testStateTransitions(): void
    {
        $m = self::match();
        $m->beginFlight(3, new Launch(0.0, 0.6, 0.0, 0.0, 0.0, 1.0), 101.0);
        $this->assertSame([MatchState::Flight, 3, 1, 101.0], [$m->state, $m->thrower, $m->rally, $m->since]);
        $m->completeCatch(7, 102.0);
        $this->assertSame([MatchState::Serve, 7], [$m->state, $m->turn]);
        $this->assertFalse($m->scoreGoal(7, 103.0));
        $this->assertSame([1, 7, 105.5], [$m->totalPoints, $m->turn, $m->since], 'le but laisse 2,5 s d\'animation');
        for ($i = 0; $i < 3; $i++) {
            $over = $m->scoreGoal(7, 110.0);
        }
        $this->assertTrue($over);
        $this->assertSame([MatchState::Over, 7], [$m->state, $m->winner()]);
        $this->assertSame(3, $m->rematchServer(), 'celui qui n\'a pas marqué en dernier sert');
    }

    public function testRematchNeedsBothPlayers(): void
    {
        $m = self::match();
        $this->assertFalse($m->requestRematch(3));
        $this->assertFalse($m->requestRematch(3), 'deux fois le même joueur ne suffit pas');
        $this->assertTrue($m->requestRematch(7));
    }

    public function testResultIsDescribedFromEachPlayersPerspective(): void
    {
        $m = new GameMatch(1, 1, 2, 1, false, 0.0);
        $m->beginFlight(1, new Launch(0.0, 0.6, 0.0, 0.0, 0.0, 1.0), 1.0);
        foreach ([1, 1, 1, 2] as $scorer) {
            $m->scoreGoal($scorer, 2.0);
        }
        $m->completeCatch(1, 3.0);
        $thrower = $m->resultFor(1, 'goal', 1, false);
        $defender = $m->resultFor(2, 'goal', 1, false);
        $this->assertSame(['thrower', 'you', 3, 1, 'you'], [$thrower['role'], $thrower['by'], $thrower['you'], $thrower['opp'], $thrower['next']]);
        $this->assertSame(['defender', 'opp', 1, 3, 'opp'], [$defender['role'], $defender['by'], $defender['you'], $defender['opp'], $defender['next']]);
        $this->assertSame(1, $thrower['rally']);
        $this->assertFalse($thrower['over']);
        $this->assertTrue($m->resultFor(1, 'goal', 1, true)['over']);
        $this->assertSame('catch', $m->resultFor(2, 'catch', 2, false)['kind']);
    }
}
