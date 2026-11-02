<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../server/lib.php';
require_once __DIR__ . '/../../web/lib/scores.php';

final class RankingTest extends TestCase
{
    private const TA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';   // jetons de test (32 caractères hexadécimaux)
    private const TB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const TC = 'cccccccccccccccccccccccccccccccc';

    private static function h(string $token): string { return tokenHash($token); }

    // ---- Elo ----------------------------------------------------------------------------------------------------

    public function testExpectedScoreIsSymmetricAndBounded(): void
    {
        $this->assertEqualsWithDelta(0.5, ratingExpected(1000, 1000), 1e-12);
        foreach ([[1000, 1200], [800, 1500], [1000, 1000], [2000, 100]] as [$a, $b]) {
            $this->assertEqualsWithDelta(1.0, ratingExpected($a, $b) + ratingExpected($b, $a), 1e-12);
            $e = ratingExpected($a, $b);
            $this->assertGreaterThan(0.0, $e);
            $this->assertLessThan(1.0, $e);
        }
        $this->assertEqualsWithDelta(0.7597, ratingExpected(1200, 1000), 1e-3, '+200 points = 76 % de chances');
        $this->assertGreaterThan(ratingExpected(1000, 1000), ratingExpected(1100, 1000));
    }

    public function testKFactorIsHigherForNewPlayers(): void
    {
        $this->assertSame(40, ratingK(0));
        $this->assertSame(40, ratingK(9));
        $this->assertSame(24, ratingK(10));
        $this->assertSame(24, ratingK(500));
    }

    // ---- identité par jeton --------------------------------------------------------------------------------------

    public static function tokens(): array
    {
        return [
            'valide 32' => [str_repeat('a1', 16), true], 'valide 64' => [str_repeat('0f', 32), true],
            'trop court' => ['abc123', false], 'trop long' => [str_repeat('a', 65), false],
            'majuscules' => [str_repeat('A', 32), false], 'non hexadécimal' => [str_repeat('g', 32), false],
            'vide' => ['', false], 'null' => [null, false], 'entier' => [12345678901234567890123456789012, false],
            'tableau' => [['a'], false], 'injection' => [str_repeat('a', 31) . "\n", false],
        ];
    }

    #[DataProvider('tokens')]
    public function testTokenValidation(mixed $token, bool $valid): void
    {
        $this->assertSame($valid, tokenValid($token));
    }

    public function testTokenHashIsStableAndDoesNotRevealTheToken(): void
    {
        $this->assertSame(self::h(self::TA), self::h(self::TA));
        $this->assertNotSame(self::h(self::TA), self::h(self::TB));
        $this->assertSame(64, strlen(self::h(self::TA)));
        $this->assertStringNotContainsString(self::TA, self::h(self::TA));
    }

    public function testNameKeyIsCaseInsensitiveWithUnicode(): void
    {
        $this->assertSame(nameKey('GNIÉ'), nameKey('gnié'));
    }

    // ---- enregistrement d'un match ------------------------------------------------------------------------------

    public function testFirstRankedMatchCreatesBothPlayersAndReservesTheirNames(): void
    {
        $r = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 1);
        $this->assertNotNull($r);
        $p = $r['players'];
        $this->assertSame(['name' => 'Alice', 'th' => self::h(self::TA), 'rating' => 1020, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 1], $p['alice']);
        $this->assertSame(['name' => 'Bob', 'th' => self::h(self::TB), 'rating' => 980, 'played' => 1, 'wins' => 0, 'losses' => 1, 'gf' => 1, 'ga' => 4], $p['bob']);
        $this->assertSame(['rating' => 1020, 'delta' => 20, 'rank' => 1], $r['winner']);
        $this->assertSame(['rating' => 980, 'delta' => -20, 'rank' => 2], $r['loser']);
    }

    public function testEqualPlayersExchangeHalfOfK(): void
    {
        $r = recordRankedMatch([], ['A', self::h(self::TA)], ['B', self::h(self::TB)], 4, 0);
        $this->assertSame(20, $r['winner']['delta']);          // K=40 * (1 - 0.5)
        $this->assertSame(-20, $r['loser']['delta']);
    }

    public function testUpsetGainsMoreThanExpectedWin(): void
    {
        $players = [
            'fort' => ['name' => 'Fort', 'th' => self::h(self::TA), 'rating' => 1400, 'played' => 30, 'wins' => 25, 'losses' => 5, 'gf' => 0, 'ga' => 0],
            'faible' => ['name' => 'Faible', 'th' => self::h(self::TB), 'rating' => 1000, 'played' => 30, 'wins' => 5, 'losses' => 25, 'gf' => 0, 'ga' => 0],
        ];
        $upset = recordRankedMatch($players, ['Faible', self::h(self::TB)], ['Fort', self::h(self::TA)], 4, 2);
        $expected = recordRankedMatch($players, ['Fort', self::h(self::TA)], ['Faible', self::h(self::TB)], 4, 2);
        $this->assertGreaterThan($expected['winner']['delta'], $upset['winner']['delta']);
        $this->assertGreaterThan(20, $upset['winner']['delta'], 'plus de la moitié de K=24');
        $this->assertSame(-$upset['winner']['delta'], $upset['loser']['delta'], 'même K : somme nulle');
    }

    public function testRatingNeverDropsBelowTheFloor(): void
    {
        // deux joueurs à égalité tout en bas : le perdant devrait perdre 12 points (K=24), mais la note ne descend pas sous 100
        $players = [
            'bas' => ['name' => 'Bas', 'th' => self::h(self::TB), 'rating' => 105, 'played' => 50, 'wins' => 0, 'losses' => 50, 'gf' => 0, 'ga' => 0],
            'gagnant' => ['name' => 'Gagnant', 'th' => self::h(self::TA), 'rating' => 105, 'played' => 50, 'wins' => 0, 'losses' => 50, 'gf' => 0, 'ga' => 0],
        ];
        $r = recordRankedMatch($players, ['Gagnant', self::h(self::TA)], ['Bas', self::h(self::TB)], 4, 0);
        $this->assertSame(RATING_FLOOR, $r['players']['bas']['rating']);
        $this->assertSame(RATING_FLOOR - 105, $r['loser']['delta'], 'le delta annoncé correspond à la note réellement appliquée');
        $this->assertSame(117, $r['players']['gagnant']['rating']);
    }

    public function testStatsAccumulateOverSeveralMatches(): void
    {
        $a = ['Alice', self::h(self::TA)]; $b = ['Bob', self::h(self::TB)];
        $p = recordRankedMatch([], $a, $b, 4, 1)['players'];
        $p = recordRankedMatch($p, $b, $a, 5, 3)['players'];
        $p = recordRankedMatch($p, $a, $b, 4, 0)['players'];
        $this->assertSame([3, 2, 1, 4 + 3 + 4, 1 + 5 + 0], [$p['alice']['played'], $p['alice']['wins'], $p['alice']['losses'], $p['alice']['gf'], $p['alice']['ga']]);
        $this->assertSame([3, 1, 2, 1 + 5 + 0, 4 + 3 + 4], [$p['bob']['played'], $p['bob']['wins'], $p['bob']['losses'], $p['bob']['gf'], $p['bob']['ga']]);
    }

    public function testTotalRatingIsConservedWhenBothPlayersShareTheSameK(): void
    {
        $a = ['Alice', self::h(self::TA)]; $b = ['Bob', self::h(self::TB)];
        $p = [];
        for ($i = 0; $i < 30; $i++) {                                     // 30 matchs : les deux passent au K des habitués
            $p = recordRankedMatch($p, $i % 3 === 0 ? $b : $a, $i % 3 === 0 ? $a : $b, 4, 1)['players'];
        }
        $this->assertEqualsWithDelta(2000, $p['alice']['rating'] + $p['bob']['rating'], 3, 'les points ne se créent pas (arrondis près)');
    }

    // ---- matchs non classés -------------------------------------------------------------------------------------

    public function testGuestsAndSameTokenMatchesAreNotRanked(): void
    {
        $this->assertNull(recordRankedMatch([], ['Invite', ''], ['Bob', self::h(self::TB)], 4, 0), 'gagnant sans jeton');
        $this->assertNull(recordRankedMatch([], ['Alice', self::h(self::TA)], ['Invite', ''], 4, 0), 'perdant sans jeton');
        $this->assertNull(recordRankedMatch([], ['Alice', self::h(self::TA)], ['Alice2', self::h(self::TA)], 4, 0), 'même navigateur des deux côtés : pas de farm');
    }

    public function testAReservedNameCannotBeUsedByAnotherToken(): void
    {
        $p = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0)['players'];
        $this->assertTrue(nameReservedByOther($p, 'Alice', self::h(self::TC)));
        $this->assertTrue(nameReservedByOther($p, 'ALICE', self::h(self::TC)), 'insensible à la casse');
        $this->assertTrue(nameReservedByOther($p, 'Alice', ''), 'un invité ne peut pas prendre un pseudo réservé');
        $this->assertFalse(nameReservedByOther($p, 'Alice', self::h(self::TA)), 'le propriétaire peut le réutiliser');
        $this->assertFalse(nameReservedByOther($p, 'Nouveau', self::h(self::TC)), 'pseudo libre');
        $this->assertNull(recordRankedMatch($p, ['Alice', self::h(self::TC)], ['Zoe', self::h(self::TB)], 4, 0), 'pas d\'usurpation dans un match');
        $this->assertSame($p, $p, 'la table d\'origine n\'est pas modifiée');
    }

    public function testRecordingDoesNotMutateTheInputTable(): void
    {
        $before = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0)['players'];
        $copy = $before;
        recordRankedMatch($before, ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0);
        $this->assertSame($copy, $before);
    }

    // ---- rang ---------------------------------------------------------------------------------------------------

    public function testRankOfHandlesTiesAndUnknownPlayers(): void
    {
        $p = [
            'a' => ['rating' => 1100, 'wins' => 5], 'b' => ['rating' => 1050, 'wins' => 3],
            'c' => ['rating' => 1050, 'wins' => 3], 'd' => ['rating' => 900, 'wins' => 1],
        ];
        $this->assertSame(1, rankOf($p, 'a'));
        $this->assertSame(2, rankOf($p, 'b'));
        $this->assertSame(2, rankOf($p, 'c'), 'ex æquo : même rang');
        $this->assertSame(4, rankOf($p, 'd'));
        $this->assertSame(5, rankOf($p, 'inconnu'));
    }

    // ---- persistance --------------------------------------------------------------------------------------------

    public function testSaveThenLoadRoundTripsAndIsAtomic(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-rank-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $file = "$dir/multi_scores.json";
        try {
            $this->assertSame([], loadPlayers($file), 'absent : vide');
            $p = recordRankedMatch([], ['gnié', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 2)['players'];
            $this->assertTrue(savePlayers($file, $p));
            $this->assertSame($p, loadPlayers($file));
            $this->assertFileDoesNotExist("$file.tmp", 'pas de fichier temporaire oublié');
            $this->assertSame([], chicken_rank_multi([]), 'table vide');
            $raw = json_decode((string)file_get_contents($file), true);
            $this->assertSame(1, $raw['version']);
            $this->assertStringContainsString('gnié', (string)file_get_contents($file), 'UTF-8 non échappé');
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testLoadIgnoresCorruptOrForeignFiles(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-rank-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            foreach (['{ pas du json', '[]', '{"players": 5}', '"texte"', ''] as $i => $content) {
                file_put_contents("$dir/f$i.json", $content);
                $this->assertSame([], loadPlayers("$dir/f$i.json"), "contenu : $content");
            }
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testSaveFailsCleanlyWhenTheDirectoryDoesNotExist(): void
    {
        $this->assertFalse(@savePlayers('/chemin/inexistant/multi_scores.json', []));
    }

    public function testNumericPseudosSurviveASaveLoadCycle(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-rank-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $p = recordRankedMatch([], ['12345', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0)['players'];
            savePlayers("$dir/m.json", $p);
            $q = loadPlayers("$dir/m.json");
            $this->assertSame('12345', $q['12345']['name'] ?? $q[12345]['name']);
            $this->assertTrue(nameReservedByOther($q, '12345', self::h(self::TC)));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    // ---- API publique (côté web) --------------------------------------------------------------------------------

    public function testPublicBoardNeverExposesTokenHashes(): void
    {
        $p = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 1)['players'];
        $rows = chicken_rank_multi($p);
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['name', 'rating', 'played', 'wins', 'losses', 'gf', 'ga'], array_keys($row));
            $this->assertArrayNotHasKey('th', $row);
        }
        $this->assertStringNotContainsString(self::h(self::TA), json_encode($rows));
        $this->assertStringNotContainsString(self::h(self::TB), json_encode($rows));
    }

    public function testPublicBoardSortsByRatingThenWinsThenNameAndIsLimited(): void
    {
        $mk = fn(string $n, int $r, int $w) => ['name' => $n, 'th' => 'x', 'rating' => $r, 'played' => 10, 'wins' => $w, 'losses' => 10 - $w, 'gf' => 0, 'ga' => 0];
        $p = ['z' => $mk('Zed', 1100, 6), 'a' => $mk('Amy', 1100, 6), 'b' => $mk('Bob', 1100, 9), 'c' => $mk('Cat', 1200, 1), 'd' => $mk('Dan', 900, 8)];
        $this->assertSame(['Cat', 'Bob', 'Amy', 'Zed', 'Dan'], array_column(chicken_rank_multi($p), 'name'));
        $this->assertCount(2, chicken_rank_multi($p, 2));
        $many = [];
        for ($i = 0; $i < 30; $i++) $many["p$i"] = $mk("P$i", 1000 + $i, $i);
        $this->assertCount(20, chicken_rank_multi($many));
        $this->assertSame('P29', chicken_rank_multi($many)[0]['name']);
    }

    public function testPublicBoardSkipsMalformedRowsAndMatchesServerRanks(): void
    {
        $p = [
            'ok' => ['name' => 'Ok', 'th' => 'x', 'rating' => 1010, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 0],
            'cassee' => ['th' => 'x'],
            'autre' => ['name' => 'Autre', 'th' => 'x', 'rating' => 990, 'played' => 1, 'wins' => 0, 'losses' => 1, 'gf' => 0, 'ga' => 4],
        ];
        $rows = chicken_rank_multi($p);
        $this->assertSame(['Ok', 'Autre'], array_column($rows, 'name'));
        // le rang annoncé aux joueurs en fin de match = leur position dans le classement public
        foreach ($rows as $i => $row) $this->assertSame($i + 1, rankOf($p, nameKey($row['name'])));
    }

    public function testLoadMultiReadsTheFileWrittenByTheServer(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-rank-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $this->assertSame([], chicken_load_multi("$dir/none.json"));
            $p = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 1)['players'];
            savePlayers("$dir/multi_scores.json", $p);
            $this->assertSame('Alice', chicken_rank_multi(chicken_load_multi("$dir/multi_scores.json"))[0]['name']);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }
}
