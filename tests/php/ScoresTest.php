<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../web/lib/scores.php';

final class ScoresTest extends TestCase
{
    // ---- pseudo -------------------------------------------------------------------------------------------------

    public static function names(): array
    {
        return [
            'simple'              => ['Alice', 'Alice'],
            'espaces autour'      => ['  Bob  ', 'Bob'],
            'accents conservés'   => ['gnié', 'gnié'],
            'caractères permis'   => ['a_b-c.d e', 'a_b-c.d e'],
            'html retiré'         => ['<img src=x>', 'img srcx'],
            'script retiré'       => ['<b>Bob</b>', 'bBobb'],
            'html + troncature'   => ['<img src=x onerror=1>', 'img srcx onerro'],
            'emoji retirés'       => ['Poule🐔', 'Poule'],
            'tronqué à 15'        => [str_repeat('z', 40), str_repeat('z', 15)],
            'tronqué sans espace' => [str_repeat('a', 14) . ' bbb', str_repeat('a', 14)],
            'vide'                => ['', ''],
            'que du symbole'      => ['<>/\\', ''],
            'unicode multi-octet' => [str_repeat('é', 20), str_repeat('é', 15)],
        ];
    }

    #[DataProvider('names')]
    public function testCleanName(string $raw, string $expected): void
    {
        $this->assertSame($expected, chicken_clean_name($raw));
    }

    // ---- validité d'un score ------------------------------------------------------------------------------------

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
        $this->assertSame($expected, chicken_valid_score('Joueur', $me, $opp));
    }

    public function testEmptyNameIsAlwaysInvalid(): void
    {
        $this->assertFalse(chicken_valid_score('', 4, 0));
    }

    // ---- cumul --------------------------------------------------------------------------------------------------

    public function testFirstMatchCreatesTheRow(): void
    {
        $rows = chicken_apply_score([], 'Alice', 4, 1);
        $this->assertSame([['name' => 'Alice', 'played' => 1, 'wins' => 1, 'gf' => 4, 'ga' => 1]], $rows);
    }

    public function testLossCountsAPlayedGameButNoWin(): void
    {
        $rows = chicken_apply_score([], 'Alice', 1, 4);
        $this->assertSame(0, $rows[0]['wins']);
        $this->assertSame(1, $rows[0]['played']);
    }

    public function testMatchesAccumulate(): void
    {
        $rows = chicken_apply_score([], 'Alice', 4, 1);
        $rows = chicken_apply_score($rows, 'Alice', 2, 4);
        $rows = chicken_apply_score($rows, 'Alice', 5, 3);
        $this->assertCount(1, $rows);
        $this->assertSame(['name' => 'Alice', 'played' => 3, 'wins' => 2, 'gf' => 11, 'ga' => 8], $rows[0]);
    }

    public function testNamesAreMatchedCaseInsensitivelyWithUnicode(): void
    {
        $rows = chicken_apply_score([], 'GNIÉ', 4, 0);
        $rows = chicken_apply_score($rows, 'gnié', 4, 0);
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['played']);
        $this->assertSame('GNIÉ', $rows[0]['name'], 'garde la graphie du premier match');
    }

    public function testOtherPlayersAreUntouched(): void
    {
        $rows = chicken_apply_score([], 'Alice', 4, 0);
        $rows = chicken_apply_score($rows, 'Bob', 0, 4);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['wins']);
        $this->assertSame(0, $rows[1]['wins']);
    }

    public function testTableIsCappedAt1000Players(): void
    {
        $rows = [];
        for ($i = 0; $i < 1005; $i++) $rows = chicken_apply_score($rows, "p$i", 4, 0);
        $this->assertCount(1000, $rows);
    }

    // ---- classement ---------------------------------------------------------------------------------------------

    public function testRankingByWinsThenGoalDifference(): void
    {
        $rows = [
            ['name' => 'C', 'played' => 3, 'wins' => 1, 'gf' => 10, 'ga' => 1],
            ['name' => 'A', 'played' => 3, 'wins' => 2, 'gf' => 8, 'ga' => 8],
            ['name' => 'B', 'played' => 3, 'wins' => 2, 'gf' => 12, 'ga' => 4],
        ];
        $this->assertSame(['B', 'A', 'C'], array_column(chicken_rank($rows), 'name'));
    }

    public function testRankingIsLimitedToTwentyByDefault(): void
    {
        $rows = [];
        for ($i = 0; $i < 30; $i++) $rows[] = ['name' => "p$i", 'played' => 1, 'wins' => $i, 'gf' => 4, 'ga' => 0];
        $top = chicken_rank($rows);
        $this->assertCount(20, $top);
        $this->assertSame('p29', $top[0]['name']);
        $this->assertCount(3, chicken_rank($rows, 3));
    }

    // ---- fichier ------------------------------------------------------------------------------------------------

    public function testLoadReturnsEmptyListWhenFileIsMissingOrCorrupt(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame([], chicken_load("$dir/absent.json"));
            file_put_contents("$dir/bad.json", '{ pas du json');
            $this->assertSame([], chicken_load("$dir/bad.json"));
            file_put_contents("$dir/ok.json", '[{"name":"A","played":1,"wins":1,"gf":4,"ga":0}]');
            $this->assertSame('A', chicken_load("$dir/ok.json")[0]['name']);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
