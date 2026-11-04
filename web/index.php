<?php
$v = filemtime(__DIR__ . '/assets/game.js');
$e = filemtime(__DIR__ . '/assets/engine.js');
$i = filemtime(__DIR__ . '/assets/i18n.js');
$c = filemtime(__DIR__ . '/assets/style.css');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#fbfbf8">
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="icons/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<script src="pwa.js" defer></script>
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
      <p data-i18n-html="rules.touch"></p>
    </details>
    <h2 data-i18n="board.title">Leaderboard</h2>
    <div id="boardTabs" role="tablist">
      <button data-board="ai" class="sel" data-i18n="board.ai">Vs the AI</button>
      <button data-board="multi" data-i18n="board.multi">Multiplayer</button>
    </div>
    <p id="boardNote" class="note"></p>
    <ol id="board"><li>…</li></ol>
  </section>

  <section id="lobby" class="panel" hidden>
    <h2 data-i18n="lobby.title">Lobby</h2>
    <p id="lobbyStatus">…</p>
    <button id="auto" class="big" data-i18n="lobby.auto">AUTOMATIC MATCH</button>
    <div class="label" data-i18n="lobby.players">Connected players</div>
    <ul id="players"></ul>
    <h2 data-i18n="board.multi.title">Multiplayer leaderboard</h2>
    <ol id="boardLobby" class="board"><li>…</li></ol>
    <button id="lobbyBack" data-i18n="lobby.back">← Back</button>
  </section>

  <div id="invite" class="panel" hidden>
    <p id="inviteText"></p>
    <button id="invYes" class="big" data-i18n="invite.yes">ACCEPT</button>
    <button id="invNo" data-i18n="invite.no">Decline</button>
  </div>

  <footer id="foot">
    <a class="all" href="https://games.prunus.dev" data-i18n="foot.games">← All games</a>
    <a class="gh" href="https://github.com/OpenPrunus/chicken-challenge" target="_blank" rel="noopener noreferrer">
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
      <span data-i18n="foot.github">View the project on GitHub</span>
    </a>
    <span class="license" title="MIT License">MIT</span>
  </footer>

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
<script src="assets/engine.js?v=<?= $e ?>"></script>
<script src="assets/game.js?v=<?= $v ?>"></script>
</body>
</html>
