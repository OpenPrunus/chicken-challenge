<?php
// API du classement : GET = top 20, POST = enregistrer un match terminé.
require __DIR__ . '/lib/scores.php';

ini_set('display_errors', '0');          // jamais d'avertissement PHP (ni de chemin interne) dans la réponse

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$file = __DIR__ . '/data/scores.json';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['board'] ?? '') === 'multi') {          // classement multijoueur, écrit par le serveur de jeu
        echo json_encode(chicken_rank_multi(chicken_load_multi(__DIR__ . '/data/multi_scores.json')), JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(chicken_rank(chicken_load($file)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo '{"error":"bad json"}'; exit; }

$name = chicken_clean_name((string)($in['name'] ?? ''));
$me   = (int)($in['me'] ?? -1);
$opp  = (int)($in['opp'] ?? -1);

if (!chicken_valid_score($name, $me, $opp)) { http_response_code(422); echo '{"error":"invalid"}'; exit; }

$fp = @fopen($file, 'c+');
if ($fp === false || !flock($fp, LOCK_EX)) {      // dossier ou fichier non inscriptible : on le dit, on ne prétend pas avoir enregistré
    http_response_code(500);
    echo '{"error":"storage"}';
    exit;
}
$d = json_decode((string)stream_get_contents($fp), true);
$d = chicken_apply_score(is_array($d) ? $d : [], $name, $me, $opp);

ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($d, JSON_UNESCAPED_UNICODE));
fflush($fp);
flock($fp, LOCK_UN);
fclose($fp);
echo '{"ok":true}';
