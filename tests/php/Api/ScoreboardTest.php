<?php

declare(strict_types=1);

namespace Chicken\Tests\Api;

use Chicken\Api\AiScoreboard;
use Chicken\Api\ScoreFile;
use Chicken\Api\ScoreRow;
use Chicken\Api\UpdateResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoreboardTest extends TestCase
{
    // ---- validité d'un score ------------------------------------------------------------------------------------

    /** @return array<string, array{int, int, bool}> */
    public static function scores(): array
    {
        return [
            '4-0' => [4, 0, true], '0-4' => [0, 4, true], '4-2' => [4, 2, true], '5-3' => [5, 3, true],
            '12-11 (plafond)' => [12, 11, true], '11-12' => [11, 12, true], '12-0' => [12, 0, true],
            '6-4' => [6, 4, true], '12-10' => [12, 10, true],
            '0-0' => [0, 0, false], '3-0' => [3, 0, false], '4-3 (1 seul point d\'écart)' => [4, 3, false],
            '5-4' => [5, 4, false], '11-11' => [11, 11, false], '11-10' => [11, 10, false],
            '13-0 (au-delà du plafond)' => [13, 0, false], '0-13' => [0, 13, false],
            'négatif' => [-1, 4, false], 'négatif adverse' => [4, -2, false], 'défaut absent' => [-1, -1, false],
        ];
    }

    #[DataProvider('scores')]
    public function testValidScore(int $me, int $opp, bool $expected): void
    {
        $this->assertSame($expected, AiScoreboard::isValidScore('Joueur', $me, $opp));
    }

    public function testEmptyNameIsAlwaysInvalid(): void
    {
        $this->assertFalse(AiScoreboard::isValidScore('', 4, 0));
    }

    // ---- cumul --------------------------------------------------------------------------------------------------

    public function testFirstMatchCreatesTheRow(): void
    {
        $this->assertSame([['name' => 'Alice', 'played' => 1, 'wins' => 1, 'gf' => 4, 'ga' => 1]], (new AiScoreboard())->withMatch('Alice', 4, 1)->toArray());
    }

    public function testLossCountsAPlayedGameButNoWin(): void
    {
        $row = (new AiScoreboard())->withMatch('Alice', 1, 4)->top()[0];
        $this->assertSame([0, 1], [$row->wins, $row->played]);
    }

    public function testMatchesAccumulate(): void
    {
        $board = (new AiScoreboard())->withMatch('Alice', 4, 1)->withMatch('Alice', 2, 4)->withMatch('Alice', 5, 3);
        $this->assertSame([['name' => 'Alice', 'played' => 3, 'wins' => 2, 'gf' => 11, 'ga' => 8]], $board->toArray());
    }

    public function testNamesAreMatchedCaseInsensitivelyWithUnicode(): void
    {
        $rows = (new AiScoreboard())->withMatch('GNIÉ', 4, 0)->withMatch('gnié', 4, 0)->top();
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]->played);
        $this->assertSame('GNIÉ', $rows[0]->name, 'garde la graphie du premier match');
    }

    public function testOtherPlayersAreUntouched(): void
    {
        $rows = (new AiScoreboard())->withMatch('Alice', 4, 0)->withMatch('Bob', 0, 4)->toArray();
        $this->assertCount(2, $rows);
        $this->assertSame([1, 0], [$rows[0]['wins'], $rows[1]['wins']]);
    }

    public function testTableIsCappedAt1000Players(): void
    {
        $board = new AiScoreboard();
        for ($i = 0; $i < 1005; $i++) {
            $board = $board->withMatch("p$i", 4, 0);
        }
        $this->assertCount(1000, $board->toArray());
    }

    public function testBoardIsImmutable(): void
    {
        $board = (new AiScoreboard())->withMatch('Alice', 4, 0);
        (void) $board->withMatch('Alice', 4, 0);
        $this->assertSame(1, $board->top()[0]->played);
    }

    // ---- classement ---------------------------------------------------------------------------------------------

    public function testRankingByWinsThenGoalDifference(): void
    {
        $board = AiScoreboard::fromArray([
            ['name' => 'C', 'played' => 3, 'wins' => 1, 'gf' => 10, 'ga' => 1],
            ['name' => 'A', 'played' => 3, 'wins' => 2, 'gf' => 8, 'ga' => 8],
            ['name' => 'B', 'played' => 3, 'wins' => 2, 'gf' => 12, 'ga' => 4],
        ]);
        $this->assertSame(['B', 'A', 'C'], array_map(static fn (ScoreRow $r): string => $r->name, $board->top()));
    }

    public function testRankingIsLimitedToTwentyByDefault(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) {
            $rows[] = ['name' => "p$i", 'played' => 1, 'wins' => $i, 'gf' => 4, 'ga' => 0];
        }
        $board = AiScoreboard::fromArray($rows);
        $this->assertCount(20, $board->top());
        $this->assertSame('p29', $board->top()[0]->name);
        $this->assertCount(3, $board->top(3));
    }

    // ---- pseudos réservés ---------------------------------------------------------------------------------------

    public function testNameReservation(): void
    {
        $board = AiScoreboard::fromArray([
            ['name' => 'Ancien', 'played' => 3, 'wins' => 1, 'gf' => 5, 'ga' => 9],                       // ancienne ligne, sans jeton
            ['name' => 'Reine', 'th' => 'hashA', 'played' => 1, 'wins' => 1, 'gf' => 4, 'ga' => 0],
        ]);
        $this->assertFalse($board->isNameTaken('Ancien', 'hashB'), 'une ligne sans jeton n\'est réservée par personne');
        $this->assertTrue($board->isNameTaken('Reine', 'hashB'));
        $this->assertTrue($board->isNameTaken('REINE', 'hashB'), 'insensible à la casse');
        $this->assertFalse($board->isNameTaken('Reine', 'hashA'), 'le propriétaire');
        $this->assertFalse($board->isNameTaken('Inconnu', 'hashB'));
        $this->assertFalse((new AiScoreboard())->isNameTaken('Reine', 'hashB'));
    }

    public function testApplyScoreStoresTheTokenAndNeverOverwritesIt(): void
    {
        $rows = (new AiScoreboard())->withMatch('Alice', 4, 1, 'hashA')->withMatch('alice', 4, 0, 'hashB')->toArray();
        $this->assertSame('hashA', $rows[0]['th'], 'un autre jeton ne prend pas la place');
        $this->assertSame(2, $rows[0]['played']);

        $legacy = AiScoreboard::fromArray([['name' => 'Ancien', 'played' => 3, 'wins' => 1, 'gf' => 5, 'ga' => 9]]);
        $this->assertSame('hashC', $legacy->withMatch('Ancien', 4, 0, 'hashC')->toArray()[0]['th'], 'le premier jeton réserve une ligne ancienne');
        $this->assertArrayNotHasKey('th', $legacy->withMatch('Ancien', 4, 0)->toArray()[0], 'sans jeton, rien n\'est ajouté');
    }

    public function testPublicBoardNeverContainsTheTokenHash(): void
    {
        $board = (new AiScoreboard())->withMatch('Alice', 4, 1, 'secretHashA')->withMatch('Bob', 0, 4, 'secretHashB');
        $out = array_map(static fn (ScoreRow $r): array => $r->toPublicArray(), $board->top());
        foreach ($out as $r) {
            $this->assertSame(['name', 'played', 'wins', 'gf', 'ga'], array_keys($r));
        }
        $this->assertStringNotContainsString('secretHash', json_encode($out));
    }

    // ---- fichier ------------------------------------------------------------------------------------------------

    public function testLoadReturnsEmptyBoardWhenFileIsMissingOrCorrupt(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame([], (new ScoreFile("$dir/absent.json"))->load()->toArray());
            file_put_contents("$dir/bad.json", '{ pas du json');
            $this->assertSame([], (new ScoreFile("$dir/bad.json"))->load()->toArray());
            file_put_contents("$dir/ok.json", '[{"name":"A","played":1,"wins":1,"gf":4,"ga":0}]');
            $this->assertSame('A', (new ScoreFile("$dir/ok.json"))->load()->top()[0]->name);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testUpdateIsAtomicAndReportsRefusalsAndFailures(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $file = new ScoreFile("$dir/scores.json");
            $this->assertSame(UpdateResult::Saved, $file->update(static fn (AiScoreboard $b): AiScoreboard => $b->withMatch('Alice', 4, 0)));
            $this->assertSame(UpdateResult::Saved, $file->update(static fn (AiScoreboard $b): AiScoreboard => $b->withMatch('Alice', 4, 1)));
            $this->assertSame(2, $file->load()->top()[0]->played);
            $this->assertSame(UpdateResult::Rejected, $file->update(static fn (AiScoreboard $b): ?AiScoreboard => null));
            $this->assertSame(2, $file->load()->top()[0]->played, 'un refus ne modifie rien');
            $this->assertSame(UpdateResult::Failed, @(new ScoreFile("$dir/absent/scores.json"))->update(static fn (AiScoreboard $b): AiScoreboard => $b));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
