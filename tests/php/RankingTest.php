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
        $this->assertSame(['rating' => 1020, 'delta' => 20, 'rank' => 1, 'factor' => 1.0], $r['winner']);
        $this->assertSame(['rating' => 980, 'delta' => -20, 'rank' => 2, 'factor' => 1.0], $r['loser']);
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

    // ---- anti-collusion -----------------------------------------------------------------------------------------

    public function testPairKeyIgnoresOrderAndNeverContainsAWholeHash(): void
    {
        $a = self::h(self::TA); $b = self::h(self::TB);
        $this->assertSame(pairKey($a, $b), pairKey($b, $a));
        $this->assertNotSame(pairKey($a, $b), pairKey($a, self::h(self::TC)));
        $this->assertStringNotContainsString($a, pairKey($a, $b), 'seul le début du hash est conservé');
        $this->assertSame(33, strlen(pairKey($a, $b)));
    }

    public function testPairFactorDecreasesWithEveryMeetingThenStopsPayingOut(): void
    {
        $pairs = []; $got = [];
        for ($i = 0; $i < 7; $i++) { [$f, $pairs] = pairFactor($pairs, 'k', 1000 + $i * 60); $got[] = $f; }
        $this->assertSame([1.0, 1.0, 0.5, 0.25, 0.0, 0.0, 0.0], $got);
    }

    public function testPairFactorWindowSlidesAndForgetsOldMeetings(): void
    {
        $pairs = [];
        foreach ([1000, 1100, 1200, 1300] as $t) [, $pairs] = pairFactor($pairs, 'k', $t, 3600);      // 4 rencontres : le prochain facteur serait 0
        [$f] = pairFactor($pairs, 'k', 1400, 3600);
        $this->assertSame(0.0, $f);
        [$f, $pairs] = pairFactor($pairs, 'k', 1000 + 3600 + 150, 3600);                              // 1000 et 1100 sont sortis de la fenêtre
        $this->assertSame(0.5, $f, 'il reste 2 rencontres dans la fenêtre : 3e = 0.5');
        [$f] = pairFactor($pairs, 'k', 99999, 3600);
        $this->assertSame(1.0, $f, 'longtemps après : tout est oublié');
    }

    public function testDifferentPairsAreIndependentAndExpiredPairsArePurged(): void
    {
        $pairs = [];
        foreach ([1000, 1010, 1020, 1030] as $t) [, $pairs] = pairFactor($pairs, 'a|b', $t);
        [$f, $pairs] = pairFactor($pairs, 'a|c', 1040);
        $this->assertSame(1.0, $f, 'un autre adversaire n\'est pas pénalisé');
        [, $pairs] = pairFactor($pairs, 'x|y', 1000 + 86400 + 100);
        $this->assertSame(['x|y'], array_keys($pairs), 'les couples expirés disparaissent (le fichier ne grossit pas)');
    }

    public function testPairFactorDoesNotMutateItsInput(): void
    {
        $pairs = ['k' => [1000]];
        pairFactor($pairs, 'k', 1100);
        $this->assertSame(['k' => [1000]], $pairs);
    }

    public function testAReducedFactorScalesGainsAndLossesProportionally(): void
    {
        $a = ['Alice', self::h(self::TA)]; $b = ['Bob', self::h(self::TB)];
        foreach ([[1.0, 20], [0.5, 10], [0.25, 5], [0.0, 0]] as [$factor, $delta]) {
            $r = recordRankedMatch([], $a, $b, 4, 0, $factor);
            $this->assertSame($delta, $r['winner']['delta'], "facteur $factor");
            $this->assertSame(-$delta, $r['loser']['delta']);
            $this->assertSame($factor, $r['winner']['factor']);
        }
    }

    public function testWithFactorZeroTheStatsStillCountButTheRatingsDoNot(): void
    {
        $a = ['Alice', self::h(self::TA)]; $b = ['Bob', self::h(self::TB)];
        $r = recordRankedMatch([], $a, $b, 4, 1, 0.0);
        $this->assertSame(1000, $r['players']['alice']['rating']); $this->assertSame(1000, $r['players']['bob']['rating']);
        $this->assertSame([1, 1, 0, 4, 1], [$r['players']['alice']['played'], $r['players']['alice']['wins'], $r['players']['alice']['losses'], $r['players']['alice']['gf'], $r['players']['alice']['ga']]);
    }

    public function testFarmingByRepeatedMatchesIsCapped(): void
    {
        // Alice « gagne » 30 fois de suite contre Bob (collusion) : sans le facteur elle gagnerait ~270 points ; avec, moins de 60.
        $a = ['Alice', self::h(self::TA)]; $b = ['Bob', self::h(self::TB)];
        $players = []; $pairs = []; $uncapped = []; $now = 1000;
        for ($i = 0; $i < 30; $i++) {
            [$f, $pairs] = pairFactor($pairs, pairKey($a[1], $b[1]), $now += 120);
            $players = recordRankedMatch($players, $a, $b, 4, 0, $f)['players'];
            $uncapped = recordRankedMatch($uncapped, $a, $b, 4, 0)['players'];
        }
        $this->assertLessThan(60, $players['alice']['rating'] - 1000, 'collusion plafonnée');
        $this->assertGreaterThan(150, $uncapped['alice']['rating'] - 1000, 'sans protection, la note aurait gonflé');
        $this->assertGreaterThan(3 * ($players['alice']['rating'] - 1000), $uncapped['alice']['rating'] - 1000, 'au moins trois fois plus');
        $this->assertSame(30, $players['alice']['played']);
    }

    public function testRankingFileKeepsThePairsAcrossARestart(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-pairs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $file = "$dir/multi_scores.json";
            $p = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0)['players'];
            [, $pairs] = pairFactor([], 'a|b', 5000);
            $this->assertTrue(savePlayers($file, $p, $pairs));
            $loaded = loadRanking($file);
            $this->assertSame($p, $loaded['players']);
            $this->assertSame(['a|b' => [5000]], $loaded['pairs']);
            $this->assertSame($p, loadPlayers($file), 'l\'ancienne fonction lit toujours le même fichier');
            file_put_contents($file, '{"version":1,"players":{}}');                                    // ancien fichier, sans couples
            $this->assertSame(['players' => [], 'pairs' => []], loadRanking($file));
            file_put_contents($file, '{ cassé');
            $this->assertSame(['players' => [], 'pairs' => []], loadRanking($file));
            $this->assertSame(['players' => [], 'pairs' => []], loadRanking("$dir/absent.json"));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testThePublicBoardIgnoresThePairsSection(): void
    {
        $dir = sys_get_temp_dir() . '/chicken-pairs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $p = recordRankedMatch([], ['Alice', self::h(self::TA)], ['Bob', self::h(self::TB)], 4, 0)['players'];
            savePlayers("$dir/m.json", $p, ['a|b' => [1, 2, 3]]);
            $out = json_encode(chicken_rank_multi(chicken_load_multi("$dir/m.json")));
            $this->assertStringNotContainsString('a|b', $out);
            $this->assertStringNotContainsString('pairs', $out);
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
