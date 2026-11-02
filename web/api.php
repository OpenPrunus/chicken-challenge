<?php
// API du classement : GET = top 20, POST = enregistrer un match terminé.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = __DIR__ . '/data/scores.json';

function load(string $file): array {
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    return is_array($d) ? $d : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $d = load($file);
    usort($d, fn($a, $b) => [$b['wins'], $b['gf'] - $b['ga']] <=> [$a['wins'], $a['gf'] - $a['ga']]);
    echo json_encode(array_slice($d, 0, 20));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo '{"error":"bad json"}'; exit; }

$name = trim(preg_replace('/[^\p{L}\p{N} _\-.]/u', '', (string)($in['name'] ?? '')));
$name = mb_substr($name, 0, 16);
$me   = (int)($in['me'] ?? -1);
$opp  = (int)($in['opp'] ?? -1);
$lvl  = (int)($in['level'] ?? 1);

// contrôle de cohérence d'un score de match valide
$valid = $name !== '' && $me >= 0 && $opp >= 0 && $me <= 12 && $opp <= 12
      && ($me === 12 || $opp === 12 || (max($me, $opp) >= 4 && abs($me - $opp) >= 2));
if (!$valid) { http_response_code(422); echo '{"error":"invalid"}'; exit; }

$fp = fopen($file, 'c+');
flock($fp, LOCK_EX);
$d = json_decode((string)stream_get_contents($fp), true);
if (!is_array($d)) $d = [];

$key = mb_strtolower($name);
$found = false;
foreach ($d as &$r) {
    if (mb_strtolower($r['name']) === $key) {
        $r['played']++;
        $r['wins'] += $me > $opp ? 1 : 0;
        $r['gf'] += $me;
        $r['ga'] += $opp;
        $found = true;
        break;
    }
}
unset($r);
if (!$found) {
    $d[] = ['name' => $name, 'played' => 1, 'wins' => $me > $opp ? 1 : 0, 'gf' => $me, 'ga' => $opp];
}
$d = array_slice($d, 0, 1000);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
echo '{"ok":true}';
