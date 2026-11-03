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
- **Multiplayer leaderboard (Elo)**: results are recorded by the game server, which is the only one that knows the
  real score. Everyone starts at 1000 points; beating a stronger player is worth more. Leaving a match that is
  already underway counts as a defeat. The menu has one leaderboard tab for games against the AI and one for
  multiplayer, and the lobby shows the multiplayer top; the end-of-match screen shows your rating change and rank.
- Effects: chicken flattened against the screen, feathers, screen shake, synthesized chicken
  sound effects.
- Fullscreen (`F` key), quit with `Esc`.
- **Mobile friendly, in portrait or landscape**: responsive menu, and a game view that adapts to the screen shape: a
  tall vertical tunnel filling the whole screen in portrait (scores on top, thumb zone at the bottom), the 4:3 view
  in landscape, switching live when you rotate the device. Tap-sized buttons and touch controls: put a finger down to lock your position, drag to aim (the
  crosshair appears above the finger so it stays visible), release to throw, flick sideways for spin, and tap the
  chicken to catch it. Fullscreen is requested automatically where the browser allows it.
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

## Tests

```bash
npm run test:unit          # JavaScript: game engine (physics, aiming, spin, catching, scoring) and FR/EN dictionaries
composer install
composer test              # PHP unit tests: game hub (lobby, challenges, matches, arbitration), Elo ranking, API, WebSocket framing
composer cs                # PSR-12 coding standard (PHP_CodeSniffer); `composer cs:fix` repairs what can be
composer stan              # static analysis (PHPStan, level 8)
composer check             # the three above
npm run test:integration   # starts the real PHP WebSocket server and plays matches against it
npm run test:browser       # headless Chrome with an emulated phone: layout, orientation, touch aiming and catching
```

Requirements: Node 22+ (no npm dependencies), PHP 8.5+ with Composer, and Chrome/Chromium for the browser tests
(`CHROME_BIN` to point at a specific binary, `BASE_URL` to target a site that is already running). `npm test` runs the JavaScript unit
tests and the integration tests (which need `php` on the PATH) and the browser tests. The GitHub Actions workflow
(`.github/workflows/ci.yml`) runs everything on each push and pull request: JS unit tests, PHP quality (PSR-12,
PHPStan, PHPUnit) on 8.5, the integration tests, the mobile browser tests, and a Docker smoke test.

The pure game logic lives in `web/assets/engine.js` (usable from the browser and from Node), and the whole PHP side
is made of small classes with no global state (`src/`), so the game can be tested without a browser, a socket or a
real clock: the unit tests drive the `GameHub` with fake clients and a controllable clock.
The integration test can also target an already running server: `WS_URL=ws://localhost:8081 npm run test:integration`.

## Architecture

```
web/                 website, served by Apache (PHP 8.5)
  index.php          single page (menu, lobby, game)
  api.php            thin entry point of the leaderboard API (JSON): builds a Request, sends the Response
  assets/            engine.js (pure game logic), game.js (rendering, input, networking), i18n.js (FR/EN), style.css
  data/              scores.json (games vs the AI) and multi_scores.json (multiplayer, written by the game server)
server/server.php    entry point of the WebSocket multiplayer server, outside the web root (a few lines of wiring)
src/                 all PHP code, PSR-4 namespace `Chicken\` (see below)
tests/               unit tests (js/, php/) and integration tests (integration/)
docs/diagrams/       class diagrams (draw.io sources + PNG exports)
.github/workflows/   CI pipeline
Dockerfile           php:8.5-apache-trixie image (Debian 13)
docker-compose.yml   two services: website (8080) and WebSocket server (8081)
```

### PHP code (`src/`)

PHP 8.5, PSR-4 autoloading, PSR-12 style, `declare(strict_types=1)` everywhere, PHPStan level 8. Runtime dependencies are
the PSR interfaces `psr/log` (PSR-3) and `psr/clock` (PSR-20), and [`openprunus/arcade-core`](https://github.com/OpenPrunus/arcade-core),
the WebSocket server and support classes shared with Gloop Volley (namespace `Arcade\`).

| Namespace | Role | Patterns |
| --- | --- | --- |
| `Chicken\Game` | The multiplayer game: `GameHub` (facade) with `Lobby`, `Invitations`, `MatchService`, `GameMatch`, `RankingBook` | **State** (`MatchState` → `Phase\ServePhase` / `FlightPhase` / `OverPhase`), **Command** (one `Handler\*` per client message), **Factory** (`GameHub::create`) |
| `Chicken\Physics` | Server copy of the game physics: `Hen`, `DefenderView`, `CatchArbiter`, `ThrowValidator` | Value objects (`Launch`, `Reach`), strategy (`CheckMode`: enforce / log / off) |
| `Chicken\Ranking` | Elo, `Ranking` (immutable), `PairLimiter` (anti-collusion), `RankingStore` | **Repository** (`RankingStore`), immutable value objects updated with `clone with` |
| `Arcade\WebSocket`, `Arcade\Server` *(arcade-core)* | RFC 6455 codec and the `stream_select` server (`SocketServer`); it knows nothing about the game | **Adapter** (`Connection` implements `Transport`); the game plugs in through `Hub` (`GameHub`) and `Session` (`Client`) |
| `Chicken\Api` | The leaderboard API: `ApiController`, `Action\StartGame` / `RecordScore`, tickets, rate limiting | **Command** (`Action\ApiAction`), **Repository** (`ScoreFile`, `AbuseStore`) |
| `Arcade\Support` *(arcade-core)*, `Chicken\Support` | `Env`, `Nickname`, `SystemClock`, `StdoutLogger`, `AtomicFile`; here `CheckMode`, `Num`, `Base64Url` | Dependency injection of clock, logger and randomizer |

Class diagrams (editable in [draw.io](https://app.diagrams.net)) are in [`docs/diagrams/`](docs/diagrams): network and facade, matches and phases, physics and ranking, leaderboard API.

PHP 8.5 and recent PHP features in use: the pipe operator (`Nickname::clean`), `clone with` on readonly classes (`Player`,
`ScoreRow`), `#[\NoDiscard]` on immutable updates, `array_first` / `array_find` / `array_any`, property hooks and
asymmetric visibility (`GameMatch`, `Client`, `Connection`), typed class constants, enums for every protocol vocabulary
(`ClientMessage`, `Event`, `MatchState`, `ClientStatus`), `readonly` classes, `#[\Override]`, `json_validate`, `mb_trim`,
`new` in initializers and `Random\Randomizer` injection.

### How multiplayer works

- The WebSocket server (`server/server.php`) has no framework: it implements RFC 6455 directly
  on top of `stream_select`.
- Trajectories are **deterministic**: the thrower only sends the parameters of the shot (position,
  speed, spin). Each client replays the physics locally with mirrored coordinates (left and right
  swapped, and the chicken starts from the far end for the defender).
- To avoid click-timing lag, **the catch is detected locally** on the defender's side, which then
  reports `catch` or `miss`. The server keeps the scores and the turn order, clamps every value it
  receives and enforces timeouts (an idle player loses the point).

### Ranked identity (no accounts)

Each browser generates a random secret token (kept in `localStorage`) and sends it when joining; the server keeps
only its SHA-256 hash. The first player to finish a ranked match under a nickname **reserves** it: nobody else can
use it, even when its owner is offline. Players without a valid token (other clients, invalid tokens) are guests and
their matches are not ranked, and two tabs of the same browser cannot rank against each other.

### Anti-cheat

What the server does, and what it cannot do:

| Where | Protection |
| --- | --- |
| Multiplayer scores | Counted by the server only; clients cannot send scores, results or "game over". |
| Catches | The defender announces "caught" **with the position of the hand**; the server replays the chicken's trajectory (same physics as the game, `Chicken\Physics\CatchArbiter`) and accepts the catch only if it was possible, within a network tolerance. An impossible catch becomes a point for the thrower. |
| Throws | Accepted only if the game's interface could have produced them (start position, speed, spin and a reachable aim point). |
| Collusion | A ranked match cannot end faster than the game allows (1.5 s per point). Between the same two players, rating gains drop to ×1, ×1, ×0.5, ×0.25, then 0 within 24 hours. Two tabs of the same browser never rank against each other. |
| Nicknames | Reserved by the first browser token that finishes a ranked game under it. |
| AI leaderboard | A game starts with a signed single-use ticket; a score is accepted only with it, after a minimum plausible duration (2 s per point), within a per-hour limit per IP and per token, under a reserved nickname. |

Honest limits: the AI leaderboard is **self-reported** (the game runs in the browser, so it cannot be verified: the protections make cheating slow, rate-limited and nominative, not impossible, and the interface says "unverified"). A client that *computes* the chicken's trajectory and places the hand exactly on it (an aimbot) cannot be told apart from a very good player. Standing still near the centre already catches about 73 % of throws, as designed. Several accounts owned by one person can still trade wins slowly. Each check has a mode (`enforce`, `log`, `off`), see [INSTALL.md](INSTALL.md).

### Known limitations

- The catch verdict comes from the defending client, so a modified client could cheat.
- The multiplayer leaderboard has no real accounts: clearing the site data loses the token (and the reserved nickname), and two
  different browsers owned by the same person can still farm rating from each other.
- No automatic reconnection in the middle of a match.

## Controls

| Action | Control |
| --- | --- |
| Move | Move the mouse |
| Aim and charge | Hold the click + move the mouse |
| Throw | Release (flick = spin) |
| Catch | Click (or space bar) at the right moment |
| Touch: throw | Finger down (locks position), drag to aim, lift to release |
| Touch: catch | Tap the chicken at the right moment |
| Fullscreen | `F` |
| Quit | `Esc` |

## License

[MIT](LICENSE). The MIT license covers this code only; it grants no rights over the original
*Poulaga Challenge* game or its assets, which are not included.
