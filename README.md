# 🐔 Chicken Challenge

A two-player chicken-throwing game written in **PHP / JavaScript / HTML**, playable in the browser
against an AI or online against another player.

Two players face each other at opposite ends of a long tunnel and throw a chicken back and forth.
Whoever fails to catch it takes the bird right in the face: **GOAL!**

> Fan project inspired by the Flash game *Poulaga Challenge* (Yamago, 2003). No graphics, sound or
> code from the original are used: everything is redrawn as vector art on a canvas and synthesized
> with WebAudio. Not affiliated with the original.

## Features

- **Perspective view** of a chicken-coop tunnel (wire mesh, beams, straw) drawn in thick black
  outlines, with 4 characters: the farmer, the pig, the punk and the granny.
- **Mouse-driven throw** in three steps:
  1. *Positioning*: moving the mouse slides your player left and right.
  2. *Hold the click*: your position is locked, a power gauge oscillates, and the mouse aims at a
     precise point of the tunnel (wall, ceiling, floor, back). A dotted line previews the
     trajectory, bounces included.
  3. *Release*: mouse still = straight shot; a sharp flick to the left or right gives the chicken
     **spin** (curved trajectory).
- **Bounce physics** off the walls, ceiling and floor, losing speed on every bounce.
- **Catching**: the chicken grows as it approaches; click at the right moment with your hand over
  it. Too early and your hand closes on thin air; too late and it splats against the screen.
- **Scoring**: first to 4 points with a 2-point lead, 12 maximum. Whoever scores keeps the chicken.
- **Versus the AI**: 3 difficulty levels (Schoolkid, Cop, Chief) and a local leaderboard.
- **Online multiplayer**: lobby, challenge a player by clicking their nickname, or automatic
  matchmaking; one-click rematch.
- Effects: chicken flattened against the screen, feathers, screen shake, synthesized chicken
  sound effects.
- Fullscreen (`F` key), quit with `Esc`.
- **English and French interface**: the language is detected from the browser (French if the
  browser is set to French, English otherwise) and can be switched with the **FR / EN** buttons
  on the menu; the choice is remembered.

Translations live in `web/assets/i18n.js` (one dictionary per language); adding a language is a matter
of adding a dictionary there and a button in `web/index.php`.

## Quick start

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge
docker compose up -d --build
```

Then open <http://localhost:8080>. See [INSTALL.md](INSTALL.md) for details (without Docker,
HTTPS reverse proxy, configuration, troubleshooting).

## Architecture

```
web/                 website, served by Apache (PHP 8.5)
  index.php          single page (menu, lobby, game)
  api.php            leaderboard for games against the AI (JSON)
  assets/            game.js (engine, rendering, networking), i18n.js (FR/EN) and style.css
  data/              scores.json (created on first run)
server/server.php    WebSocket multiplayer server in plain PHP, outside the web root
Dockerfile           php:8.5-apache-trixie image (Debian 13)
docker-compose.yml   two services: website (8080) and WebSocket server (8081)
```

### How multiplayer works

- The WebSocket server (`server/server.php`) has no dependencies: it implements RFC 6455 directly
  on top of `stream_select`.
- Trajectories are **deterministic**: the thrower only sends the parameters of the shot (position,
  speed, spin). Each client replays the physics locally with mirrored coordinates (left and right
  swapped, and the chicken starts from the far end for the defender).
- To avoid click-timing lag, **the catch is detected locally** on the defender's side, which then
  reports `catch` or `miss`. The server keeps the scores and the turn order, clamps every value it
  receives and enforces timeouts (an idle player loses the point).

### Known limitations

- The catch verdict comes from the defending client, so a modified client could cheat.
- Online games are not recorded in the leaderboard.
- No automatic reconnection in the middle of a match.

## Controls

| Action | Control |
| --- | --- |
| Move | Move the mouse |
| Aim and charge | Hold the click + move the mouse |
| Throw | Release (flick = spin) |
| Catch | Click (or space bar) at the right moment |
| Fullscreen | `F` |
| Quit | `Esc` |
