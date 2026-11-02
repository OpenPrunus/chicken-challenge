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
function chicken_apply_score(array $rows, string $name, int $me, int $opp, string $tokenHash = ''): array {
    $key = mb_strtolower($name);
    foreach ($rows as &$r) {
        if (mb_strtolower((string)$r['name']) === $key) {
            $r['played']++;
            $r['wins'] += $me > $opp ? 1 : 0;
            $r['gf'] += $me;
            $r['ga'] += $opp;
            if (($r['th'] ?? '') === '' && $tokenHash !== '') $r['th'] = $tokenHash;     // le premier jeton réserve le pseudo
            unset($r);
            return array_slice($rows, 0, 1000);
        }
    }
    unset($r);
    $row = ['name' => $name, 'played' => 1, 'wins' => $me > $opp ? 1 : 0, 'gf' => $me, 'ga' => $opp];
    if ($tokenHash !== '') $row['th'] = $tokenHash;
    $rows[] = $row;
    return array_slice($rows, 0, 1000);
}

/** Classement : victoires d'abord, puis différence de buts. */
function chicken_rank(array $rows, int $limit = 20): array {
    usort($rows, fn($a, $b) => [$b['wins'], $b['gf'] - $b['ga']] <=> [$a['wins'], $a['gf'] - $a['ga']]);
    // champs publics seulement : jamais le hash du jeton (« th »)
    $rows = array_map(fn($r) => ['name' => $r['name'], 'played' => $r['played'], 'wins' => $r['wins'], 'gf' => $r['gf'], 'ga' => $r['ga']], $rows);
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

// ---------------------------------------------------------------------------------------------------------
// Classement contre l'IA : défenses contre les faux scores. Une partie se déroule dans le navigateur, donc le
// serveur ne peut pas la vérifier ; il rend la fraude LENTE, LIMITÉE et NOMINATIVE :
//   - un ticket signé (HMAC) est délivré au début de la partie et n'est valable qu'une fois ;
//   - une partie ne peut pas se terminer plus vite que ce que le jeu permet (2 s par point) ;
//   - nombre d'enregistrements limité par heure, par adresse IP et par jeton ;
//   - un pseudo est réservé par le premier jeton qui l'utilise (comme en multijoueur).
// Un script patient reste possible : c'est un classement « déclaré », et l'interface le dit.
// ---------------------------------------------------------------------------------------------------------

const CHICKEN_TICKET_MAX_AGE = 10800;       // 3 h : une partie ne dure pas plus longtemps

function chicken_token_valid(mixed $t): bool { return is_string($t) && preg_match('/^[a-f0-9]{32,64}$/', $t) === 1; }
function chicken_token_hash(string $t): string { return hash('sha256', $t); }

function chicken_b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function chicken_b64url_dec(string $s): string|false { return base64_decode(strtr($s, '-_', '+/'), true); }

/** Ticket de partie : charge utile (jeton haché, niveau, heure, nonce) + signature HMAC-SHA256. */
function chicken_ticket_make(string $secret, string $tokenHash, int $level, int $now, ?string $nonce = null): string {
    $payload = chicken_b64url(json_encode(['n' => $nonce ?? bin2hex(random_bytes(8)), 't' => $now, 'h' => $tokenHash, 'l' => $level]));
    return $payload . '.' . hash_hmac('sha256', $payload, $secret);
}

/** Charge utile du ticket si la signature est bonne et qu'il n'est ni trop vieux ni daté du futur ; sinon null. */
function chicken_ticket_verify(string $ticket, string $secret, int $now, int $maxAge = CHICKEN_TICKET_MAX_AGE): ?array {
    $parts = explode('.', $ticket);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') return null;
    if (!hash_equals(hash_hmac('sha256', $parts[0], $secret), $parts[1])) return null;
    $json = chicken_b64url_dec($parts[0]);
    $p = $json === false ? null : json_decode($json, true);
    if (!is_array($p) || !isset($p['n'], $p['t'], $p['h'], $p['l']) || !is_int($p['t']) || !is_string($p['n']) || !is_string($p['h'])) return null;
    if ($p['t'] > $now + 5 || $now - $p['t'] > $maxAge) return null;
    return $p;
}

/** Durée minimale plausible d'une partie : chaque point demande un vol de poule et l'animation du but (≈ 2 s). */
function chicken_min_seconds(int $me, int $opp, float $perPoint = 2.0): float { return $perPoint * ($me + $opp); }

/**
 * Limite de débit à fenêtre glissante. $state : clé => liste d'horodatages. Retourne [autorisé, nouvel état, secondes à attendre].
 * Ne consomme un « jeton » que si la requête est autorisée.
 */
function chicken_rate_check(array $state, string $key, int $now, int $limit, int $window): array {
    foreach ($state as $k => $ts) {                                   // purge des entrées expirées
        $state[$k] = array_values(array_filter($ts, fn($t) => $t > $now - $window));
        if (!$state[$k]) unset($state[$k]);
    }
    $hits = $state[$key] ?? [];
    if (count($hits) >= $limit) return [false, $state, $hits ? max(1, min($hits) + $window - $now) : max(1, $window)];   // limite 0 : aucune entrée à attendre
    $hits[] = $now;
    $state[$key] = $hits;
    return [true, $state, 0];
}

/** Adresse du client : X-Forwarded-For n'est cru que si la connexion vient d'un proxy privé (la passerelle nginx). */
function chicken_client_ip(array $server): string {
    $peer = (string)($server['REMOTE_ADDR'] ?? '');
    $private = filter_var($peer, FILTER_VALIDATE_IP) !== false
        && filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    if ($private && !empty($server['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', (string)$server['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP) !== false) return $first;
    }
    return $peer !== '' ? $peer : 'unknown';
}

/** Ce pseudo est-il réservé par un autre jeton ? (une ligne sans jeton, ancienne, n'est réservée par personne) */
function chicken_name_taken(array $rows, string $name, string $tokenHash): bool {
    $key = mb_strtolower($name);
    foreach ($rows as $r) {
        if (mb_strtolower((string)($r['name'] ?? '')) === $key) return ($r['th'] ?? '') !== '' && $r['th'] !== $tokenHash;
    }
    return false;
}

/** Valeur d'une variable d'environnement numérique positive, sinon la valeur par défaut (réglages, tests). */
function chicken_env_num(string $name, float $default): float {
    $v = getenv($name);
    return ($v !== false && is_numeric($v) && (float)$v >= 0) ? (float)$v : $default;
}
