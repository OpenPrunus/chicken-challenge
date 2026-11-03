<?php
/**
 * Physique de la poule côté serveur : copie fidèle de web/assets/engine.js (step, chaos, zone de capture), pour que le
 * serveur puisse ARBITRER les captures au lieu de croire le défenseur sur parole. Les deux implémentations sont
 * comparées par des vecteurs de test générés par le moteur JavaScript (tests/fixtures/physics-vectors.json).
 *
 * Repère « lanceur » : la poule part de z = 0 (le lanceur) vers z = 1 (le défenseur). Le défenseur la voit avec
 * z' = 1 - z et x' = -x (perspective inversée).
 */
declare(strict_types=1);

const HEN_GRAV = 0.5;

/** État de la poule juste après le lancer, dans le repère du lanceur (paramètres reçus sur le réseau). */
function henThrown(array $w): array {
    return [
        'z' => 0.0, 'x' => (float)$w['x'], 'y' => (float)$w['y'], 'vx' => (float)$w['vx'], 'vy' => (float)$w['vy'],
        'spin' => (float)$w['spin'], 'speed' => (float)$w['speed'],
        'speed0' => (float)$w['speed'], 'spin0' => (float)$w['spin'], 'bounces' => 0,
    ];
}

/** Un pas de physique (identique à engine.js) ; retourne 'x' | 'y' si la poule a rebondi, sinon null. */
function henStep(array &$h, float $dt): ?string {
    $h['z'] += $h['speed'] * $dt;
    $h['x'] += $h['vx'] * $dt; $h['y'] += $h['vy'] * $dt;
    $h['vx'] += $h['spin'] * $dt;
    $h['vy'] += HEN_GRAV * $dt;
    $hit = null;
    if (abs($h['x']) > 1) {
        $h['x'] = $h['x'] > 0 ? 1.0 : -1.0; $h['vx'] *= -0.88; $h['spin'] *= 0.7; $hit = 'x';
    }
    if ($h['y'] > 1 || $h['y'] < -1) {
        $h['y'] = $h['y'] > 0 ? 1.0 : -1.0; $h['vy'] *= -0.82; $hit ??= 'y';
    }
    if ($hit !== null) { $h['bounces']++; $h['speed'] = max(0.35, $h['speed'] * 0.93); }
    return $hit;
}

/** Même poule vue par le défenseur : z' = 1 - z, x' = -x. */
function henMirror(array $h): array {
    return ['z' => 1.0 - $h['z'], 'x' => -$h['x'], 'y' => $h['y'], 'bounces' => $h['bounces'], 'spin0' => $h['spin0'], 'speed0' => $h['speed0']];
}

/** « Chaos » de la poule, de 0 à 1 : rebonds, effet et vitesse de départ (voir engine.js). */
function henChaos(array $h): float {
    $c = 0.25 * $h['bounces']
       + 0.30 * min(1.0, abs($h['spin0']) / 2)
       + 0.30 * max(0.0, ($h['speed0'] - 0.9) / 0.7);
    return max(0.0, min(1.0, $c));
}

/** Zone de capture : rayon de la main (unités du tunnel) et fenêtre de profondeur. */
function henReach(array $h): array {
    $c = henChaos($h);
    return ['r' => 0.42 + 0.22 * $c, 'zmax' => 0.25 + 0.08 * $c];
}

const HEN_VISUAL_UNITS = 100 / 360;      // rayon de la poule au plan proche / demi-largeur du tunnel (identique en paysage et en portrait)

/**
 * La main (mx, my) rattrape-t-elle la poule du défenseur ? Même règle que engine.js : près de la position d'arrivée
 * (x, y), OU sur la poule telle qu'elle est dessinée (x·s, y·s, à sa taille visible, qui dépend de l'agrandissement
 * $boost de l'écran du joueur). $slack élargit tolérance et fenêtre (latence, pas de temps du client).
 *
 * @param array{z: float, x: float, y: float, bounces: int, spin0: float, speed0: float} $d poule dans le repère du défenseur
 */
function henCanCatch(array $d, float $mx, float $my, float $slack = 1.0, float $boost = 1.0): bool {
    $reach = henReach($d);
    if ($d['z'] >= $reach['zmax'] * ($slack > 1.0 ? 1.15 : 1.0)) return false;
    $s = 1 / (1 + 3 * $d['z']);
    if (hypot($d['x'] - $mx, $d['y'] - $my) < $reach['r'] * $slack) return true;
    return hypot($d['x'] * $s - $mx, $d['y'] * $s - $my) < HEN_VISUAL_UNITS * $boost * $s * 1.15 * $slack;
}

/**
 * Une capture déclarée est-elle plausible ? On rejoue la trajectoire et on cherche, dans la fenêtre de temps
 * [écoulé - tolérance, écoulé + marge], un instant où la main annoncée rattrape la poule. Le temps écoulé est mesuré par le
 * serveur depuis le lancer : il dépasse le temps vu par le défenseur de la latence aller-retour (d'où la fenêtre).
 * Rejette : pas de coordonnées, main loin de la poule, capture trop tôt (poule encore loin) ou trop tard (déjà passée).
 */
function catchIsPlausible(array $wire, float $elapsed, mixed $mx, mixed $my, float $timeSlack = 1.0, float $reachSlack = 1.35, float $forward = 0.15): bool {
    if (!is_int($mx) && !is_float($mx)) return false;
    if (!is_int($my) && !is_float($my)) return false;
    if (!is_finite((float)$mx) || !is_finite((float)$my) || abs($mx) > 1.5 || abs($my) > 1.5) return false;
    $h = henThrown($wire);
    $dt = 1 / 120; $t = 0.0;
    for ($i = 0; $i < 6000; $i++) {                                  // 50 s au plus : un vol dure moins de 3 s
        if ($t > $elapsed + $forward) break;                       // marge vers l'avant : sur une trajectoire très chaotique, la simulation du serveur peut être légèrement en retard sur celle du client
        if ($t >= $elapsed - $timeSlack && henCanCatch(henMirror($h), (float)$mx, (float)$my, $reachSlack, 2.0)) return true;   // 2.0 : agrandissement maximal d'un petit écran
        if ($h['z'] >= 1.0) break;                                   // elle est arrivée au défenseur : plus rien à rattraper
        henStep($h, $dt); $t += $dt;
    }
    return false;
}

/**
 * Ce lancer peut-il être produit par l'interface du jeu ? Le vrai jeu part de (x0 ∈ ±0.8, y0 = 0.6), avec une vitesse de
 * 0.7 à 1.6 et un effet de ±2.2, en visant un point précis du couloir : mur, plafond, sol ou fond, à une profondeur
 * tz ∈ [0.1, 1] (voir aimPoint / makeHenAim). Les vitesses vx, vy en découlent :
 *     vx = (tx - x0) * speed / tz        vy = (ty - y0) * speed / tz - g * tz / (2 * speed)
 * Un lancer est légitime s'il existe une profondeur tz où la poule touche une surface (|tx| = 1 ou |ty| = 1) ou le fond
 * ou la limite proche (tz = 1 ou 0.1) tout en restant dans le tunnel. On teste les seuls candidats possibles : les racines
 * de |tx| = 1 (linéaire), de |ty| = 1 (second degré) et les deux extrémités.
 *
 * @param array{x: mixed, y: mixed, vx: mixed, vy: mixed, spin: mixed, speed: mixed} $w
 */
function throwIsLegit(array $w): bool {
    foreach (['x', 'y', 'vx', 'vy', 'spin', 'speed'] as $k) {
        if (!isset($w[$k]) || (!is_int($w[$k]) && !is_float($w[$k])) || !is_finite((float)$w[$k])) return false;
    }
    [$x0, $y0, $vx, $vy, $spin, $speed] = [(float)$w['x'], (float)$w['y'], (float)$w['vx'], (float)$w['vy'], (float)$w['spin'], (float)$w['speed']];
    if (abs($x0) > 0.801 || abs($y0 - 0.6) > 0.001 || abs($spin) > 2.201 || $speed < 0.699 || $speed > 1.601) return false;

    $eps = 0.02;
    $tx = fn(float $tz) => $x0 + $vx * $tz / $speed;
    $a = $vy / $speed; $b = 0.5 * HEN_GRAV / ($speed * $speed);                       // ty(tz) = y0 + a·tz + b·tz²
    $ty = fn(float $tz) => $y0 + $a * $tz + $b * $tz * $tz;

    $candidates = [0.1, 1.0];
    if (abs($vx) > 1e-9) foreach ([1.0, -1.0] as $wall) $candidates[] = ($wall - $x0) * $speed / $vx;        // |tx| = 1
    foreach ([1.0, -1.0] as $surface) {                                                                        // |ty| = 1 : b·tz² + a·tz + (y0 ∓ 1) = 0
        $c = $y0 - $surface; $disc = $a * $a - 4 * $b * $c;
        if ($disc >= 0) { $sq = sqrt($disc); $candidates[] = (-$a + $sq) / (2 * $b); $candidates[] = (-$a - $sq) / (2 * $b); }
    }
    foreach ($candidates as $tz) {
        if ($tz < 0.1 - 1e-6 || $tz > 1.0 + 1e-6) continue;
        $mx = abs($tx($tz)); $my = abs($ty($tz));
        if ($mx > 1 + $eps || $my > 1 + $eps) continue;                               // hors du tunnel
        $onSurface = max($mx, $my) >= 1 - $eps;
        if ($onSurface || $tz >= 1.0 - 1e-6 || $tz <= 0.1 + 1e-6) return true;
    }
    return false;
}
