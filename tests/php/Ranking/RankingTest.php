<?php

declare(strict_types=1);

namespace Chicken\Tests\Ranking;

use Chicken\Ranking\Elo;
use Chicken\Ranking\PairLimiter;
use Chicken\Ranking\Participant;
use Chicken\Ranking\Player;
use Chicken\Ranking\Ranking;
use Chicken\Ranking\RankingSnapshot;
use Chicken\Ranking\RankingStore;
use Chicken\Ranking\Token;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RankingTest extends TestCase
{
    private const string TA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';   // jetons de test (32 caractères hexadécimaux)
    private const string TB = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const string TC = 'cccccccccccccccccccccccccccccccc';

    private static function p(string $name, string $token): Participant
    {
        return new Participant($name, Token::hash($token));
    }

    private static function player(string $name, string $token, int $rating, int $played = 30, int $wins = 0): Player
    {
        return new Player($name, Token::hash($token), $rating, $played, $wins, $played - $wins);
    }

    /** @param list<Player> $players */
    private static function table(array $players): Ranking
    {
        $map = [];
        foreach ($players as $p) {
            $map[$p->key()] = $p;
        }

        return new Ranking($map);
    }

    private static function record(Ranking $r, Participant $w, Participant $l, int $gw = 4, int $gl = 1, float $factor = 1.0): \Chicken\Ranking\RankedMatch
    {
        return $r->record($w, $l, $gw, $gl, $factor) ?? throw new \LogicException('match non classé');
    }

    // ---- Elo ----------------------------------------------------------------------------------------------------

    public function testExpectedScoreIsSymmetricAndBounded(): void
    {
        $this->assertEqualsWithDelta(0.5, Elo::expected(1000, 1000), 1e-12);
        foreach ([[1000, 1200], [800, 1500], [1000, 1000], [2000, 100]] as [$a, $b]) {
            $this->assertEqualsWithDelta(1.0, Elo::expected($a, $b) + Elo::expected($b, $a), 1e-12);
            $e = Elo::expected($a, $b);
            $this->assertGreaterThan(0.0, $e);
            $this->assertLessThan(1.0, $e);
        }
        $this->assertEqualsWithDelta(0.7597, Elo::expected(1200, 1000), 1e-3, '+200 points = 76 % de chances');
        $this->assertGreaterThan(Elo::expected(1000, 1000), Elo::expected(1100, 1000));
    }

    public function testKFactorIsHigherForNewPlayers(): void
    {
        $this->assertSame(40, Elo::kFactor(0));
        $this->assertSame(40, Elo::kFactor(9));
        $this->assertSame(24, Elo::kFactor(10));
        $this->assertSame(24, Elo::kFactor(500));
    }

    // ---- identité par jeton --------------------------------------------------------------------------------------

    /** @return array<string, array{mixed, bool}> */
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
        $this->assertSame($valid, Token::isValid($token));
    }

    public function testTokenHashIsStableAndDoesNotRevealTheToken(): void
    {
        $this->assertSame(Token::hash(self::TA), Token::hash(self::TA));
        $this->assertNotSame(Token::hash(self::TA), Token::hash(self::TB));
        $this->assertSame(64, strlen(Token::hash(self::TA)));
        $this->assertSame(hash('sha256', self::TA), Token::hash(self::TA));
        $this->assertStringNotContainsString(self::TA, Token::hash(self::TA));
    }

    // ---- enregistrement d'un match ------------------------------------------------------------------------------

    public function testFirstRankedMatchCreatesBothPlayersAndReservesTheirNames(): void
    {
        $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB));
        $this->assertSame(
            ['name' => 'Alice', 'th' => Token::hash(self::TA), 'rating' => 1020, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 1],
            $r->ranking->find('alice')->toArray(),
        );
        $this->assertSame(
            ['name' => 'Bob', 'th' => Token::hash(self::TB), 'rating' => 980, 'played' => 1, 'wins' => 0, 'losses' => 1, 'gf' => 1, 'ga' => 4],
            $r->ranking->find('BOB')->toArray(),
        );
        $this->assertSame(['rating' => 1020, 'delta' => 20, 'rank' => 1, 'factor' => 1.0], $r->winner->toArray());
        $this->assertSame(['rating' => 980, 'delta' => -20, 'rank' => 2, 'factor' => 1.0], $r->loser->toArray());
    }

    public function testEqualPlayersExchangeHalfOfK(): void
    {
        $r = self::record(new Ranking(), self::p('A', self::TA), self::p('B', self::TB), 4, 0);
        $this->assertSame(20, $r->winner->delta);          // K=40 * (1 - 0.5)
        $this->assertSame(-20, $r->loser->delta);
    }

    public function testUpsetGainsMoreThanExpectedWin(): void
    {
        $table = self::table([self::player('Fort', self::TA, 1400, 30, 25), self::player('Faible', self::TB, 1000, 30, 5)]);
        $upset = self::record($table, self::p('Faible', self::TB), self::p('Fort', self::TA), 4, 2);
        $expected = self::record($table, self::p('Fort', self::TA), self::p('Faible', self::TB), 4, 2);
        $this->assertGreaterThan($expected->winner->delta, $upset->winner->delta);
        $this->assertGreaterThan(20, $upset->winner->delta, 'plus de la moitié de K=24');
        $this->assertSame(-$upset->winner->delta, $upset->loser->delta, 'même K : somme nulle');
    }

    public function testRatingNeverDropsBelowTheFloor(): void
    {
        // deux joueurs à égalité tout en bas : le perdant devrait perdre 12 points (K=24), mais la note ne descend pas sous 100
        $table = self::table([self::player('Bas', self::TB, 105, 50), self::player('Gagnant', self::TA, 105, 50)]);
        $r = self::record($table, self::p('Gagnant', self::TA), self::p('Bas', self::TB), 4, 0);
        $this->assertSame(Elo::FLOOR, $r->ranking->find('bas')->rating);
        $this->assertSame(Elo::FLOOR - 105, $r->loser->delta, 'le delta annoncé correspond à la note réellement appliquée');
        $this->assertSame(117, $r->ranking->find('gagnant')->rating);
    }

    public function testStatsAccumulateOverSeveralMatches(): void
    {
        $a = self::p('Alice', self::TA);
        $b = self::p('Bob', self::TB);
        $r = self::record(new Ranking(), $a, $b, 4, 1)->ranking;
        $r = self::record($r, $b, $a, 5, 3)->ranking;
        $r = self::record($r, $a, $b, 4, 0)->ranking;
        $alice = $r->find('alice');
        $bob = $r->find('bob');
        $this->assertSame([3, 2, 1, 11, 6], [$alice->played, $alice->wins, $alice->losses, $alice->goalsFor, $alice->goalsAgainst]);
        $this->assertSame([3, 1, 2, 6, 11], [$bob->played, $bob->wins, $bob->losses, $bob->goalsFor, $bob->goalsAgainst]);
    }

    public function testTotalRatingIsConservedWhenBothPlayersShareTheSameK(): void
    {
        $a = self::p('Alice', self::TA);
        $b = self::p('Bob', self::TB);
        $r = new Ranking();
        for ($i = 0; $i < 30; $i++) {                                     // 30 matchs : les deux passent au K des habitués
            $r = $i % 3 === 0 ? self::record($r, $b, $a)->ranking : self::record($r, $a, $b)->ranking;
        }
        $this->assertEqualsWithDelta(2000, $r->find('alice')->rating + $r->find('bob')->rating, 3, 'les points ne se créent pas (arrondis près)');
    }

    // ---- matchs non classés -------------------------------------------------------------------------------------

    public function testGuestsAndSameTokenMatchesAreNotRanked(): void
    {
        $r = new Ranking();
        $this->assertNull($r->record(new Participant('Invite', ''), self::p('Bob', self::TB), 4, 0), 'gagnant sans jeton');
        $this->assertNull($r->record(self::p('Alice', self::TA), new Participant('Invite', ''), 4, 0), 'perdant sans jeton');
        $this->assertNull($r->record(self::p('Alice', self::TA), self::p('Alice2', self::TA), 4, 0), 'même navigateur des deux côtés : pas de farm');
    }

    public function testAReservedNameCannotBeUsedByAnotherToken(): void
    {
        $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0)->ranking;
        $this->assertTrue($r->isReservedByOther('Alice', Token::hash(self::TC)));
        $this->assertTrue($r->isReservedByOther('ALICE', Token::hash(self::TC)), 'insensible à la casse');
        $this->assertTrue($r->isReservedByOther('Alice', ''), 'un invité ne peut pas prendre un pseudo réservé');
        $this->assertFalse($r->isReservedByOther('Alice', Token::hash(self::TA)), 'le propriétaire peut le réutiliser');
        $this->assertFalse($r->isReservedByOther('Nouveau', Token::hash(self::TC)), 'pseudo libre');
        $this->assertNull($r->record(self::p('Alice', self::TC), self::p('Zoe', self::TB), 4, 0), 'pas d\'usurpation dans un match');
    }

    public function testRankingIsImmutable(): void
    {
        $before = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0)->ranking;
        $copy = $before->toArray();
        (void) self::record($before, self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0);
        $this->assertSame($copy, $before->toArray());
        $this->assertCount(2, $before);
    }

    // ---- rang ---------------------------------------------------------------------------------------------------

    public function testRankOfHandlesTiesAndUnknownPlayers(): void
    {
        $r = self::table([
            new Player('a', '', 1100, wins: 5), new Player('b', '', 1050, wins: 3),
            new Player('c', '', 1050, wins: 3), new Player('d', '', 900, wins: 1),
        ]);
        $this->assertSame(1, $r->rankOf('a'));
        $this->assertSame(2, $r->rankOf('b'));
        $this->assertSame(2, $r->rankOf('c'), 'ex æquo : même rang');
        $this->assertSame(4, $r->rankOf('d'));
        $this->assertSame(5, $r->rankOf('inconnu'));
    }

    // ---- classement public ---------------------------------------------------------------------------------------

    public function testPublicBoardNeverExposesTokenHashes(): void
    {
        $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB))->ranking;
        $rows = array_map(static fn (Player $p): array => $p->toPublicArray(), $r->top());
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame(['name', 'rating', 'played', 'wins', 'losses', 'gf', 'ga'], array_keys($row));
        }
        $this->assertStringNotContainsString(Token::hash(self::TA), json_encode($rows));
        $this->assertStringNotContainsString(Token::hash(self::TB), json_encode($rows));
    }

    public function testPublicBoardSortsByRatingThenWinsThenNameAndIsLimited(): void
    {
        $mk = static fn (string $n, int $r, int $w): Player => new Player($n, 'x', $r, 10, $w, 10 - $w);
        $r = self::table([$mk('Zed', 1100, 6), $mk('Amy', 1100, 6), $mk('Bob', 1100, 9), $mk('Cat', 1200, 1), $mk('Dan', 900, 8)]);
        $this->assertSame(['Cat', 'Bob', 'Amy', 'Zed', 'Dan'], array_map(static fn (Player $p): string => $p->name, $r->top()));
        $this->assertCount(2, $r->top(2));
        $many = [];
        for ($i = 0; $i < 30; $i++) {
            $many[] = $mk("P$i", 1000 + $i, $i);
        }
        $this->assertCount(20, self::table($many)->top());
        $this->assertSame('P29', self::table($many)->top()[0]->name);
    }

    public function testMalformedRowsAreSkippedWhenLoading(): void
    {
        $r = Ranking::fromArray([
            'ok' => ['name' => 'Ok', 'th' => 'x', 'rating' => 1010, 'played' => 1, 'wins' => 1, 'losses' => 0, 'gf' => 4, 'ga' => 0],
            'cassee' => ['th' => 'x'],
            'texte' => 'pas une ligne',
            'autre' => ['name' => 'Autre', 'th' => 'x', 'rating' => 990, 'played' => 1, 'wins' => 0, 'losses' => 1, 'gf' => 0, 'ga' => 4],
        ]);
        $this->assertCount(2, $r);
        $rows = $r->top();
        $this->assertSame(['Ok', 'Autre'], array_map(static fn (Player $p): string => $p->name, $rows));
        // le rang annoncé aux joueurs en fin de match = leur position dans le classement public
        foreach ($rows as $i => $row) {
            $this->assertSame($i + 1, $r->rankOf($row->name));
        }
        $this->assertCount(0, Ranking::fromArray('n\'importe quoi'));
    }

    // ---- persistance --------------------------------------------------------------------------------------------

    private function withTempDir(callable $test): void
    {
        $dir = sys_get_temp_dir() . '/chicken-rank-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $test($dir);
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testSaveThenLoadRoundTripsAndIsAtomic(): void
    {
        $this->withTempDir(function (string $dir): void {
            $store = new RankingStore("$dir/multi_scores.json");
            $this->assertCount(0, $store->load()->ranking, 'absent : vide');
            $r = self::record(new Ranking(), self::p('gnié', self::TA), self::p('Bob', self::TB), 4, 2)->ranking;
            $this->assertTrue($store->save(new RankingSnapshot($r)));
            $this->assertSame($r->toArray(), $store->load()->ranking->toArray());
            $this->assertFileDoesNotExist("$dir/multi_scores.json.tmp", 'pas de fichier temporaire oublié');
            $raw = (string) file_get_contents("$dir/multi_scores.json");
            $this->assertSame(1, json_decode($raw, true)['version']);
            $this->assertStringContainsString('gnié', $raw, 'UTF-8 non échappé');
        });
    }

    public function testLoadIgnoresCorruptOrForeignFiles(): void
    {
        $this->withTempDir(function (string $dir): void {
            foreach (['{ pas du json', '[]', '{"players": 5}', '"texte"', ''] as $i => $content) {
                file_put_contents("$dir/f$i.json", $content);
                $this->assertCount(0, (new RankingStore("$dir/f$i.json"))->load()->ranking, "contenu : $content");
            }
        });
    }

    public function testSaveFailsCleanlyWhenTheDirectoryDoesNotExist(): void
    {
        $this->assertFalse(@(new RankingStore('/chemin/inexistant/multi_scores.json'))->save(new RankingSnapshot()));
    }

    public function testNumericPseudosSurviveASaveLoadCycle(): void
    {
        $this->withTempDir(function (string $dir): void {
            $store = new RankingStore("$dir/m.json");
            $r = self::record(new Ranking(), self::p('12345', self::TA), self::p('Bob', self::TB), 4, 0)->ranking;
            $store->save(new RankingSnapshot($r));
            $loaded = $store->load()->ranking;
            $this->assertSame('12345', $loaded->find('12345')?->name);
            $this->assertTrue($loaded->isReservedByOther('12345', Token::hash(self::TC)));
        });
    }

    // ---- anti-collusion -----------------------------------------------------------------------------------------

    public function testPairKeyIgnoresOrderAndNeverContainsAWholeHash(): void
    {
        $a = Token::hash(self::TA);
        $b = Token::hash(self::TB);
        $this->assertSame(PairLimiter::key($a, $b), PairLimiter::key($b, $a));
        $this->assertNotSame(PairLimiter::key($a, $b), PairLimiter::key($a, Token::hash(self::TC)));
        $this->assertStringNotContainsString($a, PairLimiter::key($a, $b), 'seul le début du hash est conservé');
        $this->assertSame(33, strlen(PairLimiter::key($a, $b)));
    }

    public function testPairFactorDecreasesWithEveryMeetingThenStopsPayingOut(): void
    {
        $limiter = new PairLimiter();
        $got = [];
        for ($i = 0; $i < 7; $i++) {
            $meeting = $limiter->meet('k', 1000 + $i * 60);
            $got[] = $meeting->factor;
            $limiter = $meeting->limiter;
        }
        $this->assertSame([1.0, 1.0, 0.5, 0.25, 0.0, 0.0, 0.0], $got);
    }

    public function testPairFactorWindowSlidesAndForgetsOldMeetings(): void
    {
        $limiter = new PairLimiter();
        foreach ([1000, 1100, 1200, 1300] as $t) {                                          // 4 rencontres : le prochain facteur serait 0
            $limiter = $limiter->meet('k', $t, 3600)->limiter;
        }
        $this->assertSame(0.0, $limiter->meet('k', 1400, 3600)->factor);
        $meeting = $limiter->meet('k', 1000 + 3600 + 150, 3600);                            // 1000 et 1100 sont sortis de la fenêtre
        $this->assertSame(0.5, $meeting->factor, 'il reste 2 rencontres dans la fenêtre : 3e = 0.5');
        $this->assertSame(1.0, $meeting->limiter->meet('k', 99999, 3600)->factor, 'longtemps après : tout est oublié');
    }

    public function testDifferentPairsAreIndependentAndExpiredPairsArePurged(): void
    {
        $limiter = new PairLimiter();
        foreach ([1000, 1010, 1020, 1030] as $t) {
            $limiter = $limiter->meet('a|b', $t)->limiter;
        }
        $meeting = $limiter->meet('a|c', 1040);
        $this->assertSame(1.0, $meeting->factor, 'un autre adversaire n\'est pas pénalisé');
        $later = $meeting->limiter->meet('x|y', 1000 + 86400 + 100);
        $this->assertSame(['x|y'], array_keys($later->limiter->toArray()), 'les couples expirés disparaissent (le fichier ne grossit pas)');
    }

    public function testPairLimiterIsImmutable(): void
    {
        $limiter = new PairLimiter(['k' => [1000]]);
        (void) $limiter->meet('k', 1100);
        $this->assertSame(['k' => [1000]], $limiter->toArray());
    }

    public function testAReducedFactorScalesGainsAndLossesProportionally(): void
    {
        foreach ([[1.0, 20], [0.5, 10], [0.25, 5], [0.0, 0]] as [$factor, $delta]) {
            $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0, $factor);
            $this->assertSame($delta, $r->winner->delta, "facteur $factor");
            $this->assertSame(-$delta, $r->loser->delta);
            $this->assertSame($factor, $r->winner->factor);
        }
    }

    public function testWithFactorZeroTheStatsStillCountButTheRatingsDoNot(): void
    {
        $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 1, 0.0)->ranking;
        $alice = $r->find('alice');
        $this->assertSame([1000, 1000], [$alice->rating, $r->find('bob')->rating]);
        $this->assertSame([1, 1, 0, 4, 1], [$alice->played, $alice->wins, $alice->losses, $alice->goalsFor, $alice->goalsAgainst]);
    }

    public function testFarmingByRepeatedMatchesIsCapped(): void
    {
        // Alice « gagne » 30 fois de suite contre Bob (collusion) : sans le facteur elle gagnerait ~270 points ; avec, moins de 60.
        $a = self::p('Alice', self::TA);
        $b = self::p('Bob', self::TB);
        $capped = new Ranking();
        $uncapped = new Ranking();
        $limiter = new PairLimiter();
        $now = 1000;
        for ($i = 0; $i < 30; $i++) {
            $meeting = $limiter->meet(PairLimiter::key($a->tokenHash, $b->tokenHash), $now += 120);
            $limiter = $meeting->limiter;
            $capped = self::record($capped, $a, $b, 4, 0, $meeting->factor)->ranking;
            $uncapped = self::record($uncapped, $a, $b, 4, 0)->ranking;
        }
        $gain = $capped->find('alice')->rating - 1000;
        $free = $uncapped->find('alice')->rating - 1000;
        $this->assertLessThan(60, $gain, 'collusion plafonnée');
        $this->assertGreaterThan(150, $free, 'sans protection, la note aurait gonflé');
        $this->assertGreaterThan(3 * $gain, $free, 'au moins trois fois plus');
        $this->assertSame(30, $capped->find('alice')->played);
    }

    public function testRankingFileKeepsThePairsAcrossARestart(): void
    {
        $this->withTempDir(function (string $dir): void {
            $store = new RankingStore("$dir/multi_scores.json");
            $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0)->ranking;
            $pairs = (new PairLimiter())->meet('a|b', 5000)->limiter;
            $this->assertTrue($store->save(new RankingSnapshot($r, $pairs)));
            $loaded = $store->load();
            $this->assertSame($r->toArray(), $loaded->ranking->toArray());
            $this->assertSame(['a|b' => [5000]], $loaded->pairs->toArray());
            file_put_contents("$dir/multi_scores.json", '{"version":1,"players":{}}');                    // ancien fichier, sans couples
            $this->assertSame([], $store->load()->pairs->toArray());
            file_put_contents("$dir/multi_scores.json", '{ cassé');
            $this->assertSame([], $store->load()->pairs->toArray());
            $this->assertSame([], (new RankingStore("$dir/absent.json"))->load()->pairs->toArray());
        });
    }

    public function testThePublicBoardIgnoresThePairsSection(): void
    {
        $this->withTempDir(function (string $dir): void {
            $store = new RankingStore("$dir/m.json");
            $r = self::record(new Ranking(), self::p('Alice', self::TA), self::p('Bob', self::TB), 4, 0)->ranking;
            $store->save(new RankingSnapshot($r, new PairLimiter(['a|b' => [1, 2, 3]])));
            $out = json_encode(array_map(static fn (Player $p): array => $p->toPublicArray(), $store->load()->ranking->top()));
            $this->assertStringNotContainsString('a|b', $out);
            $this->assertStringNotContainsString('pairs', $out);
        });
    }
}
