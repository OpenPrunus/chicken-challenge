<?php

declare(strict_types=1);

namespace Chicken\Tests\Physics;

use Chicken\Physics\Axis;
use Chicken\Physics\CatchArbiter;
use Chicken\Physics\DefenderView;
use Chicken\Physics\Hen;
use Chicken\Physics\Launch;
use Chicken\Physics\ThrowValidator;
use PHPUnit\Framework\TestCase;

/** Physique du serveur : identique au moteur JavaScript, et arbitrage des captures (honnêtes acceptées, fausses refusées). */
final class PhysicsTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $fx;

    public static function setUpBeforeClass(): void
    {
        self::$fx = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/physics-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function hen(array $wire): Hen
    {
        return Hen::thrown(Launch::tryFromArray($wire) ?? throw new \LogicException('lancer invalide'));
    }

    /** Capture plausible ? Les paramètres optionnels reprennent ceux de l'arbitre du serveur. */
    private static function plausible(array $wire, float $t, mixed $x, mixed $y, float $timeSlack = 1.0, float $reachSlack = 1.35, float $forward = 0.15): bool
    {
        return (new CatchArbiter($timeSlack, $reachSlack, $forward))->isPlausible(Launch::tryFromArray($wire), $t, $x, $y);
    }

    private static function legit(array $wire): bool
    {
        return (new ThrowValidator())->isLegit($wire);
    }

    /** Lancer droit au centre : speed 1, atteint le défenseur après ~1 s ; capture possible entre ~0.75 s et 1.0 s. */
    private static function straight(): array
    {
        $y0 = 0.6;
        $T = 1.0;

        return ['x' => 0.0, 'y' => $y0, 'vx' => 0.0, 'vy' => -$y0 / $T - 0.5 * Hen::GRAVITY * $T, 'spin' => 0.0, 'speed' => 1.0];
    }

    // ---- parité avec le moteur JavaScript ----------------------------------------------------------------------

    public function testTheFixtureIsNotTrivial(): void
    {
        $this->assertGreaterThanOrEqual(25, count(self::$fx['thrown']));
        $this->assertGreaterThanOrEqual(250, count(self::$fx['canCatch']));
        $this->assertGreaterThanOrEqual(150, count(self::$fx['honest']));
        $this->assertSame(Hen::GRAVITY, self::$fx['constants']['grav'], 'même gravité que le moteur');
    }

    public function testTrajectoriesMatchTheJavaScriptEngineStepForStep(): void
    {
        $compared = 0;
        $bounced = 0;
        foreach (self::$fx['thrown'] as $i => $case) {
            $h = self::hen($case['hen']);
            $n = 0;
            foreach ($case['samples'] as $s) {
                while ($n < $s['n']) {
                    $h->step(1 / 120);
                    $n++;
                }
                foreach (['z', 'x', 'y', 'vx', 'vy', 'spin', 'speed'] as $k) {
                    $this->assertEqualsWithDelta($s[$k], $h->$k, 2e-9, "lancer $i, pas $n : $k");
                }
                $this->assertSame($s['bounces'], $h->bounces, "lancer $i, pas $n : rebonds");
                $compared++;
                $bounced += $s['bounces'] > 0 ? 1 : 0;
            }
        }
        $this->assertGreaterThan(200, $compared);
        $this->assertGreaterThan(30, $bounced, 'le jeu de test contient des rebonds');
    }

    public function testStepReportsTheSurfaceHit(): void
    {
        $h = self::hen(['x' => 0.95, 'y' => 0.0, 'vx' => 6.0, 'vy' => 0.0, 'spin' => 0.0, 'speed' => 1.0]);
        $this->assertSame(Axis::X, $h->step(0.1), 'mur');
        $this->assertSame(1.0 - 0.0, abs($h->x) * 1.0);
        $this->assertSame(1, $h->bounces);
        $floor = self::hen(['x' => 0.0, 'y' => 0.95, 'vx' => 0.0, 'vy' => 3.0, 'spin' => 0.0, 'speed' => 1.0]);
        $this->assertSame(Axis::Y, $floor->step(0.1), 'sol');
        $this->assertNull(self::hen(self::straight())->step(0.01), 'pas de rebond');
    }

    public function testCaptureDecisionsMatchTheJavaScriptEngine(): void
    {
        $yes = 0;
        foreach (self::$fx['canCatch'] as $i => $c) {
            $this->assertSame($c['expect'], (new DefenderView(...$c['h']))->canCatch($c['m'][0], $c['m'][1], 1.0, (float) $c['boost']), "cas $i " . json_encode($c));
            $yes += $c['expect'] ? 1 : 0;
        }
        $this->assertGreaterThan(40, $yes);
        $this->assertLessThan(count(self::$fx['canCatch']) - 40, $yes);       // il y a des oui et des non
    }

    public function testChaosAndReachMatchTheDocumentedFormulas(): void
    {
        $this->assertSame(0.0, (new DefenderView(0.0, 0.0, 0.0, 0, 0.0, 0.8))->chaos());
        $this->assertSame(1.0, (new DefenderView(0.0, 0.0, 0.0, 9, -5.0, 3.0))->chaos());
        $this->assertEqualsWithDelta(0.3, (new DefenderView(0.0, 0.0, 0.0, 0, 2.0, 0.8))->chaos(), 1e-12, 'le signe de l\'effet est sans importance');
        $this->assertEqualsWithDelta(0.3, (new DefenderView(0.0, 0.0, 0.0, 0, -2.0, 0.8))->chaos(), 1e-12);
        $calm = (new DefenderView(0.0, 0.0, 0.0, 0, 0.0, 0.8))->reach();
        $this->assertSame([0.42, 0.25], [$calm->radius, $calm->zMax]);
        $wild = (new DefenderView(0.0, 0.0, 0.0, 9, 3.0, 3.0))->reach();
        $this->assertEqualsWithDelta(0.64, $wild->radius, 1e-12);
        $this->assertEqualsWithDelta(0.33, $wild->zMax, 1e-12);
    }

    public function testMirrorSeenByTheDefender(): void
    {
        $h = self::hen(['x' => 0.4, 'y' => 0.2, 'vx' => 0, 'vy' => 0, 'spin' => 1.5, 'speed' => 1.2]);
        $h->z = 0.3;
        $d = $h->seenByDefender();
        $this->assertEqualsWithDelta(0.7, $d->z, 1e-12);
        $this->assertSame(-0.4, $d->x);
        $this->assertSame(0.2, $d->y);
        $this->assertSame(1.5, $d->spin0);
    }

    public function testTheHenArrivesAtTheDefender(): void
    {
        $h = self::hen(self::straight());
        $this->assertFalse($h->hasArrived);
        for ($i = 0; $i < 130; $i++) {
            $h->step(1 / 120);
        }
        $this->assertTrue($h->hasArrived);
    }

    // ---- arbitrage : les captures honnêtes sont acceptées ------------------------------------------------------

    public function testEveryHonestCatchIsAcceptedWhateverTheLatency(): void
    {
        $checked = 0;
        foreach (self::$fx['honest'] as $i => $c) {
            foreach ([0.0, 0.05, 0.2, 0.5, 0.9] as $latency) {                      // aller-retour réseau : le serveur mesure plus que le client
                $this->assertTrue(
                    self::plausible($c['hen'], $c['c'] + $latency, $c['hand'][0], $c['hand'][1]),
                    sprintf('capture honnête %d refusée (temps client %.3f s, latence %.2f s, pas %s)', $i, $c['c'], $latency, json_encode($c['dt'])),
                );
                $checked++;
            }
        }
        $this->assertGreaterThan(700, $checked);
    }

    public function testHonestCatchesAreAcceptedWithEveryClientTimeStepIncludingSlowDevices(): void
    {
        $steps = array_count_values(array_map(static fn ($c) => json_encode($c['dt']), self::$fx['honest']));
        $this->assertGreaterThanOrEqual(3, count($steps), 'plusieurs pas de temps représentés : ' . json_encode($steps));
        $this->assertArrayHasKey('"variable"', $steps);
    }

    public function testTheFirstAndTheLastInstantOfTheWindowBothWork(): void
    {
        $wire = self::straight();
        $okTimes = [];
        for ($t = 0.5; $t <= 1.3; $t += 0.01) {
            if (self::plausible($wire, $t, 0.0, 0.0, 0.05, 1.35, 0.0)) {                           // sans tolérance réseau : la vraie fenêtre
                $okTimes[] = round($t, 2);
            }
        }
        $this->assertNotEmpty($okTimes);
        $this->assertGreaterThan(0.7, min($okTimes));
        $this->assertLessThan(1.1, max($okTimes));
    }

    // ---- arbitrage : les fausses captures sont refusées --------------------------------------------------------

    public function testCatchesWithoutValidCoordinatesAreRefused(): void
    {
        $w = self::straight();
        foreach ([[null, null], ['0', '0'], [NAN, 0.0], [0.0, INF], [-INF, 0.0], [true, false], [[0], [0]], [2.0, 0.0], [0.0, -1.6], [null, 0.0], [0.0, null]] as [$x, $y]) {
            $this->assertFalse(self::plausible($w, 0.9, $x, $y), 'coordonnées ' . json_encode([$x, $y]));
        }
    }

    public function testACatchFarFromTheChickenIsRefused(): void
    {
        $w = self::straight();
        $this->assertTrue(self::plausible($w, 0.9, 0.0, 0.0), 'la poule arrive au centre : main au centre = ok');
        foreach ([[1.2, -1.2], [-1.2, 1.2], [1.4, 0.0], [0.0, 1.4], [-1.4, -1.4]] as [$x, $y]) {
            $this->assertFalse(self::plausible($w, 0.9, $x, $y), "main en ($x, $y)");
        }
    }

    public function testACatchThatIsTooEarlyOrTooLateIsRefused(): void
    {
        $w = self::straight();
        $this->assertFalse(self::plausible($w, 0.2, 0.0, 0.0), 'la poule vient de partir');
        $this->assertFalse(self::plausible($w, 0.4, 0.0, 0.0), 'poule encore loin');
        $this->assertFalse(self::plausible($w, 2.6, 0.0, 0.0), 'la poule est arrivée depuis longtemps');
        $this->assertFalse(self::plausible($w, 60.0, 0.0, 0.0), 'temps absurde, sans boucle infinie');
        $this->assertFalse(self::plausible($w, -1.0, 0.0, 0.0), 'temps négatif');
    }

    public function testAHandFarFromEveryPositionOfTheChickenNeverPasses(): void
    {
        // Pour chaque lancer, on cherche un coin du tunnel loin de TOUTES les positions de la poule (et de celles où elle est
        // dessinée) pendant qu'elle est à portée : annoncer « attrapée » avec la main là, à n'importe quel moment, doit échouer.
        $tested = 0;
        foreach (self::$fx['legit'] as $i => $w) {
            $positions = [];
            $h = self::hen($w);
            for ($step = 0; $step < 700 && !$h->hasArrived; $step++) {
                $h->step(1 / 120);
                $d = $h->seenByDefender();
                if ($d->z < 0.45) {
                    $s = 1 / (1 + 3 * $d->z);
                    $positions[] = [$d->x, $d->y];
                    $positions[] = [$d->x * $s, $d->y * $s];
                }
            }
            foreach ([[1.4, 1.4], [1.4, -1.4], [-1.4, 1.4], [-1.4, -1.4]] as [$mx, $my]) {
                if (array_any($positions, static fn (array $p): bool => hypot($p[0] - $mx, $p[1] - $my) < 1.1)) {
                    continue;
                }
                for ($t = 0.0; $t <= 3.0; $t += 0.07) {
                    $this->assertFalse(self::plausible($w, $t, $mx, $my), "lancer $i, t=" . round($t, 2) . ", main en ($mx, $my)");
                }
                $tested++;
                break;
            }
        }
        $this->assertGreaterThan(80, $tested, 'assez de lancers pour lesquels un coin lointain existe');
    }

    public function testRandomGuessesArePassedOnlyAsOftenAsTheGameItselfIsGenerous(): void
    {
        // Limite assumée : la zone de capture du jeu est large (rester immobile au centre rattrape déjà ~73 % des lancers),
        // donc des mains au hasard passent parfois, EXACTEMENT comme pour un joueur honnête. Le contrôle ne doit pas être
        // plus généreux que la règle du jeu élargie par la tolérance réseau.
        mt_srand(7);
        $passes = 0;
        $n = 2000;
        for ($i = 0; $i < $n; $i++) {
            $c = self::$fx['honest'][$i % count(self::$fx['honest'])];
            if (self::plausible($c['hen'], mt_rand(0, 3000) / 1000, mt_rand(-1000, 1000) / 1000, mt_rand(-1000, 1000) / 1000)) {
                $passes++;
            }
        }
        $this->assertLessThan($n * 0.45, $passes, "$passes / $n captures au hasard acceptées");
        $this->assertGreaterThan(0, $passes, 'sinon le contrôle refuserait aussi des captures honnêtes');
    }

    public function testHandParkedAtTheCentreIsNotAWinningStrategyBeyondWhatTheGameAllows(): void
    {
        $passes = 0;
        $n = 0;
        foreach (self::$fx['legit'] as $w) {
            $ok = false;
            for ($t = 0.3; $t <= 2.5 && !$ok; $t += 0.05) {
                $ok = self::plausible($w, $t, 0.0, 0.0);
            }
            $passes += $ok ? 1 : 0;
            $n++;
        }
        $this->assertLessThan($n, $passes, 'au moins un lancer échappe à une main immobile au centre');
        $this->assertLessThan($n * 0.97, $passes, "$passes / $n : plus généreux que la règle du jeu + tolérance");
    }

    public function testNoCoordinatesNeverPassWhateverTheTiming(): void
    {
        foreach (self::$fx['legit'] as $w) {
            for ($t = 0.3; $t <= 2.5; $t += 0.1) {
                $this->assertFalse(self::plausible($w, $t, null, null));
                $this->assertFalse(self::plausible($w, $t, 'x', 0.0));
            }
        }
    }

    // ---- lancers : seuls ceux que l'interface peut produire --------------------------------------------------------

    public function testEveryThrowTheRealGameCanProduceIsAccepted(): void
    {
        $n = 0;
        foreach ([...self::$fx['legit'], ...array_column(self::$fx['honest'], 'hen')] as $i => $w) {      // aléatoires + extrêmes (contre les murs, vitesse max)
            $this->assertTrue(self::legit($w), "lancer légitime $i refusé : " . json_encode($w));
            $n++;
        }
        $this->assertGreaterThan(450, $n);
    }

    public function testTheExtremesOfTheRealGameAreAccepted(): void
    {
        foreach ([[0.8, 1.6, 2.2], [-0.8, 1.6, -2.2], [0.0, 0.7, 0.0], [0.8, 0.7, 2.2]] as [$x0, $speed, $spin]) {
            foreach ([[0, 0], [0, 600], [800, 0], [800, 600], [400, 300], [0, 300], [800, 300], [400, 0], [400, 600], [30, 560]] as [$px, $py]) {
                $u = max(-0.97, min(0.97, ($px - 400) / 360));
                $v = max(-0.97, min(0.97, ($py - 285) / 250));
                $d = max(1.3, min(4, 1 / max(abs($u), abs($v), 0.25)));
                $tgt = ['x' => max(-1, min(1, $u * $d)), 'y' => max(-1, min(1, $v * $d)), 'z' => ($d - 1) / 3];
                $T = $tgt['z'] / $speed;
                $w = ['x' => $x0, 'y' => 0.6, 'vx' => ($tgt['x'] - $x0) / $T, 'vy' => ($tgt['y'] - 0.6) / $T - 0.5 * Hen::GRAVITY * $T, 'spin' => $spin, 'speed' => $speed];
                $this->assertTrue(self::legit($w), "extrême x0=$x0 speed=$speed spin=$spin visée ($px,$py) : " . json_encode($w));
            }
        }
    }

    public function testThrowsBeyondWhatTheGameAllowsAreRefused(): void
    {
        $ok = ['x' => 0.2, 'y' => 0.6, 'vx' => -1.5, 'vy' => -0.3, 'spin' => 1.1, 'speed' => 1.0];
        $this->assertTrue(self::legit($ok), 'référence');
        $this->assertTrue(self::legit(['vx' => 0.0, 'vy' => 0.0] + $ok), 'immobile : atteint le fond du couloir, que le jeu permet de viser');
        $bad = [
            'trop rapide' => ['speed' => 1.9], 'vitesse maximale du serveur' => ['speed' => 1.7], 'trop lent' => ['speed' => 0.5],
            'effet excessif' => ['spin' => 2.6], 'effet 2.5' => ['spin' => -2.5],
            'départ hors ligne' => ['x' => 0.9], 'départ à gauche' => ['x' => -1.0],
            'hauteur de départ truquée' => ['y' => 0.0], 'hauteur 0.7' => ['y' => 0.7],
            'vx 60' => ['vx' => 60.0], 'vx -40' => ['vx' => -40.0], 'vy -60' => ['vy' => -60.0], 'vy 40' => ['vy' => 40.0],
            'trop vite vers le mur proche' => ['vx' => -35.0],
            'touche le sol plus près que le jeu ne le permet' => ['vx' => 0.0, 'vy' => 5.0],
            'plus près que la limite du jeu' => ['vx' => 0.0, 'vy' => -30.0],
        ];
        foreach ($bad as $name => $patch) {
            $this->assertFalse(self::legit($patch + $ok), $name);
        }
    }

    public function testMalformedThrowsAreRefusedWithoutWarnings(): void
    {
        $ok = ['x' => 0.2, 'y' => 0.6, 'vx' => -1.5, 'vy' => -0.3, 'spin' => 1.1, 'speed' => 1.0];
        foreach (['x', 'y', 'vx', 'vy', 'spin', 'speed'] as $k) {
            foreach ([NAN, INF, -INF, '1', null, true, [1]] as $bad) {
                $this->assertFalse(self::legit([$k => $bad] + $ok), "$k = " . json_encode(is_float($bad) && is_nan($bad) ? 'NAN' : $bad));
            }
            $missing = $ok;
            unset($missing[$k]);
            $this->assertFalse(self::legit($missing), "$k absent");
        }
        $this->assertFalse(self::legit([]));
    }

    public function testRandomThrowsWithinTheServerBoundsAreMostlyRefused(): void
    {
        // avant : n'importe quelles valeurs dans les bornes larges (vx ±60, vitesse ≤ 1.9...) passaient ; maintenant presque aucune
        mt_srand(11);
        $accepted = 0;
        $n = 4000;
        for ($i = 0; $i < $n; $i++) {
            $w = [
                'x' => mt_rand(-1000, 1000) / 1000, 'y' => mt_rand(-1000, 1000) / 1000,
                'vx' => mt_rand(-60000, 60000) / 1000, 'vy' => mt_rand(-60000, 60000) / 1000,
                'spin' => mt_rand(-2600, 2600) / 1000, 'speed' => mt_rand(300, 1900) / 1000,
            ];
            if (self::legit($w)) {
                $accepted++;
            }
        }
        $this->assertLessThan($n * 0.002, $accepted, "$accepted / $n lancers aléatoires acceptés");
    }

    public function testTheLegitimacyCheckIsCheap(): void
    {
        $t0 = hrtime(true);
        for ($i = 0; $i < 20000; $i++) {
            self::legit(self::$fx['legit'][$i % count(self::$fx['legit'])]);
        }
        $this->assertLessThan(500, (hrtime(true) - $t0) / 1e6);
    }

    public function testTheCheckIsCheap(): void
    {
        $t0 = hrtime(true);
        for ($i = 0; $i < 200; $i++) {
            self::plausible(self::$fx['legit'][$i % count(self::$fx['legit'])], 1.2, 0.1, 0.1);
        }
        $ms = (hrtime(true) - $t0) / 1e6;
        $this->assertLessThan(500, $ms, "200 vérifications : $ms ms");
    }

    public function testAnAimbotPlacingTheHandExactlyOnTheChickenIsNotDetected(): void
    {
        // Limite assumée : un client qui calcule la trajectoire et pose la main pile dessus passe. Seule la triche triviale est coupée.
        $w = self::straight();
        $h = self::hen($w);
        for ($t = 0.0; $t < 0.9; $t += 1 / 120) {
            $h->step(1 / 120);
        }
        $d = $h->seenByDefender();
        $s = 1 / (1 + 3 * $d->z);
        $this->assertTrue(self::plausible($w, 0.9, $d->x * $s, $d->y * $s));
    }

    // ---- lancer reçu du réseau -------------------------------------------------------------------------------------

    public function testWireThrowsAreClampedToTheProtocolBounds(): void
    {
        $l = Launch::fromWire(['x' => 5, 'y' => -5, 'vx' => 100, 'vy' => -100, 'spin' => 9, 'speed' => 0]);
        $this->assertSame([1.0, -1.0, 60.0, -60.0, 2.6, 0.3], array_values($l->toArray()));
    }

    public function testWireThrowsWithMissingOrNonNumericFieldsAreRejected(): void
    {
        $ok = ['x' => 0, 'y' => 0.6, 'vx' => 0, 'vy' => 0, 'spin' => 0, 'speed' => 1];
        $this->assertNotNull(Launch::fromWire($ok));
        $this->assertNull(Launch::fromWire('texte'));
        $this->assertNull(Launch::fromWire(null));
        foreach (array_keys($ok) as $k) {
            $this->assertNull(Launch::fromWire(['x' => '1'] + [$k => null] + $ok), "$k absent");
            $this->assertNull(Launch::fromWire([$k => '0.5'] + $ok), "$k texte");
        }
    }
}
