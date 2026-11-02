<?php
$v = filemtime(__DIR__ . '/assets/game.js');
$i = filemtime(__DIR__ . '/assets/i18n.js');
$c = filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Chicken Challenge</title>
<link rel="stylesheet" href="assets/style.css?v=<?= $c ?>">
</head>
<body>
<main id="app">
  <div id="lang" role="group" aria-label="Language">
    <button data-lang="fr" title="Français">FR</button>
    <button data-lang="en" title="English">EN</button>
  </div>
  <h1>🐔 Chicken Challenge</h1>

  <section id="menu" class="panel">
    <label><span data-i18n="menu.nick">Nickname</span>
      <input id="pseudo" maxlength="15" data-i18n-ph="menu.nick.ph" placeholder="Your nickname" autocomplete="off">
    </label>
    <div class="label" data-i18n="menu.char">Choose your character</div>
    <div id="avatars"></div>
    <div class="label" data-i18n="menu.level">Level</div>
    <div id="levels">
      <button data-level="0" data-i18n="level0">Schoolkid</button>
      <button data-level="1" data-i18n="level1" class="sel">Cop</button>
      <button data-level="2" data-i18n="level2">Chief</button>
    </div>
    <button id="play" class="big" data-i18n="menu.play">PLAY VS THE AI</button>
    <button id="mp" class="big blue" data-i18n="menu.mp">MULTIPLAYER</button>
    <details>
      <summary data-i18n="rules.title">Rules</summary>
      <p data-i18n-html="rules.body"></p>
    </details>
    <h2 data-i18n="board.title">Leaderboard</h2>
    <ol id="board"><li>…</li></ol>
  </section>

  <section id="lobby" class="panel" hidden>
    <h2 data-i18n="lobby.title">Lobby</h2>
    <p id="lobbyStatus">…</p>
    <button id="auto" class="big" data-i18n="lobby.auto">AUTOMATIC MATCH</button>
    <div class="label" data-i18n="lobby.players">Connected players</div>
    <ul id="players"></ul>
    <button id="lobbyBack" data-i18n="lobby.back">← Back</button>
  </section>

  <div id="invite" class="panel" hidden>
    <p id="inviteText"></p>
    <button id="invYes" class="big" data-i18n="invite.yes">ACCEPT</button>
    <button id="invNo" data-i18n="invite.no">Decline</button>
  </div>

  <section id="game" hidden>
    <canvas id="cv" width="800" height="600"></canvas>
    <div id="bar">
      <button id="btnFs" data-i18n-title="game.fs" title="Fullscreen (F)">⛶</button>
      <button id="btnQuit" data-i18n="game.quit" data-i18n-title="game.quit.title" title="Quit (Esc)">✕ Quit</button>
    </div>
    <div id="over" class="panel" hidden>
      <h2 id="overTitle"></h2>
      <p id="overScore"></p>
      <p id="overMsg"></p>
      <button id="again" class="big">Play again</button>
      <button id="toMenu" data-i18n="over.menu">Menu</button>
    </div>
  </section>
</main>
<script>window.CHICKEN_WS = <?= json_encode((string)getenv('CHICKEN_WS')) ?>;</script>
<script src="assets/i18n.js?v=<?= $i ?>"></script>
<script src="assets/game.js?v=<?= $v ?>"></script>
</body>
</html>
