<?php
$v = filemtime(__DIR__ . '/assets/game.js');
$c = filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chicken Challenge</title>
<link rel="stylesheet" href="assets/style.css?v=<?= $c ?>">
</head>
<body>
<main id="app">
  <h1>🐔 Chicken Challenge</h1>

  <section id="menu" class="panel">
    <label>Pseudo
      <input id="pseudo" maxlength="15" placeholder="Ton pseudo" autocomplete="off">
    </label>
    <div class="label">Choisis ton personnage</div>
    <div id="avatars"></div>
    <div class="label">Niveau</div>
    <div id="levels">
      <button data-level="0">Collégien</button>
      <button data-level="1" class="sel">Flic</button>
      <button data-level="2">Commissaire</button>
    </div>
    <button id="play" class="big">JOUER CONTRE L'IA</button>
    <button id="mp" class="big blue">MULTIJOUEUR</button>
    <details>
      <summary>Règles</summary>
      <p>Deux joueurs se lancent une poule dans un tunnel. <b>Déplace la souris</b> pour te
      placer, puis <b>clique et maintiens</b> : ta position est verrouillée, une jauge oscille
      et la souris sert à <b>viser un point du couloir</b> (mur, plafond, sol, fond) : la poule
      y rebondit. <b>Relâche</b> pour lancer : plus la jauge est haute, plus elle va vite. Souris
      immobile = tir droit ; un <b>coup de poignet</b> sec à gauche ou à droite au moment du
      relâchement donne de l'effet (trajectoire courbe). Quand la poule arrive sur toi, place la
      main dessus et <b>clique au bon moment</b> pour l'attraper (ou barre d'espace).
      Rater = but pour l'adversaire. En <b>multijoueur</b>, rejoins le salon : défie un joueur ou lance un match automatique. Premier à 4 points avec 2 d'écart, 12 maximum.</p>
    </details>
    <h2>Classement</h2>
    <ol id="board"><li>…</li></ol>
  </section>

  <section id="lobby" class="panel" hidden>
    <h2>Salon d'attente</h2>
    <p id="lobbyStatus">Connexion…</p>
    <button id="auto" class="big">MATCH AUTOMATIQUE</button>
    <div class="label">Joueurs connectés — clique sur un pseudo pour le défier</div>
    <ul id="players"></ul>
    <button id="lobbyBack">← Retour</button>
  </section>

  <div id="invite" class="panel" hidden>
    <p id="inviteText"></p>
    <button id="invYes" class="big">ACCEPTER</button>
    <button id="invNo">Refuser</button>
  </div>

  <section id="game" hidden>
    <canvas id="cv" width="800" height="600"></canvas>
    <div id="bar">
      <button id="btnFs" title="Plein écran (F)">⛶</button>
      <button id="btnQuit" title="Quitter (Échap)">✕ Quitter</button>
    </div>
    <div id="over" class="panel" hidden>
      <h2 id="overTitle"></h2>
      <p id="overScore"></p>
      <p id="overMsg"></p>
      <button id="again" class="big">Rejouer</button>
      <button id="toMenu">Menu</button>
    </div>
  </section>
</main>
<script>window.CHICKEN_WS = <?= json_encode((string)getenv('CHICKEN_WS')) ?>;</script>
<script src="assets/game.js?v=<?= $v ?>"></script>
</body>
</html>
