<?php
/**
 * Classement des parties contre l'IA : fonctions pures (testées par PHPUnit, tests/php/).
 * Ce fichier ne produit aucune sortie : il ne contient que des définitions de fonctions.
 */
declare(strict_types=1);

/** Pseudo nettoyé : lettres, chiffres, espace, « _ - . » ; 15 caractères au plus. */
function chicken_clean_name(string $raw): string {
    $n = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $raw) ?? '';
    return trim(mb_substr(trim($n), 0, 15));
}

/** Un score de match valide : 4 points avec 2 d'écart, ou 12 points au plus. */
function chicken_valid_score(string $name, int $me, int $opp): bool {
    return $name !== '' && $me >= 0 && $opp >= 0 && $me <= 12 && $opp <= 12
        && ($me === 12 || $opp === 12 || (max($me, $opp) >= 4 && abs($me - $opp) >= 2));
}

/** Ajoute un match au cumul du joueur (insensible à la casse) ; 1000 joueurs au plus. */
function chicken_apply_score(array $rows, string $name, int $me, int $opp): array {
    $key = mb_strtolower($name);
    foreach ($rows as &$r) {
        if (mb_strtolower((string)$r['name']) === $key) {
            $r['played']++;
            $r['wins'] += $me > $opp ? 1 : 0;
            $r['gf'] += $me;
            $r['ga'] += $opp;
            unset($r);
            return array_slice($rows, 0, 1000);
        }
    }
    unset($r);
    $rows[] = ['name' => $name, 'played' => 1, 'wins' => $me > $opp ? 1 : 0, 'gf' => $me, 'ga' => $opp];
    return array_slice($rows, 0, 1000);
}

/** Classement : victoires d'abord, puis différence de buts. */
function chicken_rank(array $rows, int $limit = 20): array {
    usort($rows, fn($a, $b) => [$b['wins'], $b['gf'] - $b['ga']] <=> [$a['wins'], $a['gf'] - $a['ga']]);
    return array_slice($rows, 0, $limit);
}

/** Lit le fichier de scores ; retourne [] s'il est absent ou illisible. */
function chicken_load(string $file): array {
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return is_array($d) ? $d : [];
}

// ---------------------------------------------------------------------------------------------------------
// Classement multijoueur : écrit par le serveur de jeu (server/lib.php), lu ici. On n'expose JAMAIS le hash
// du jeton (champ « th ») : seuls les champs publics ci-dessous sortent par l'API.
// ---------------------------------------------------------------------------------------------------------

/** Lit multi_scores.json ; [] si absent ou illisible. */
function chicken_load_multi(string $file): array {
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return (is_array($d) && is_array($d['players'] ?? null)) ? $d['players'] : [];
}

/** Top du classement : rating, puis victoires, puis pseudo. Champs publics uniquement. */
function chicken_rank_multi(array $players, int $limit = 20): array {
    $rows = [];
    foreach ($players as $p) {
        if (!isset($p['name'], $p['rating'])) continue;
        $rows[] = [
            'name' => (string)$p['name'], 'rating' => (int)$p['rating'], 'played' => (int)($p['played'] ?? 0),
            'wins' => (int)($p['wins'] ?? 0), 'losses' => (int)($p['losses'] ?? 0),
            'gf' => (int)($p['gf'] ?? 0), 'ga' => (int)($p['ga'] ?? 0),
        ];
    }
    usort($rows, fn($a, $b) => [$b['rating'], $b['wins'], $a['name']] <=> [$a['rating'], $a['wins'], $b['name']]);
    return array_slice($rows, 0, $limit);
}
