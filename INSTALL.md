# Installation

Chicken Challenge consists of two processes: the **website** (PHP + Apache, port 80) and the
**multiplayer server** (PHP command line, port 8081). Without the latter, only the mode against the
AI works.

## 1. With Docker (recommended)

Requirements: Docker and Docker Compose.

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge
docker compose up -d --build
```

- Game: <http://localhost:8080>
- WebSocket: `ws://localhost:8081`

The image is based on `php:8.5-apache-trixie` (Debian 13, PHP 8.5). Both services use the same
image.

Useful commands:

```bash
docker compose logs -f chicken-ws     # multiplayer server log
docker compose ps                     # service status
docker compose down                   # stop (scores are kept)
docker compose down -v                # stop AND delete the scores
```

### Where are the scores stored?

In the named Docker volume `chicken-challenge_scores`, mounted on `/var/www/html/data`: `scores.json` (games against
the AI, written by the website) and `multi_scores.json` (multiplayer Elo leaderboard, written by the **game
server**, which is why the `chicken-ws` service mounts the same volume). It survives image rebuilds.

```bash
# backup
docker compose cp chicken:/var/www/html/data ./scores-backup
# reset
docker compose exec chicken sh -c 'echo "[]" > /var/www/html/data/scores.json'
```

### Changing the ports

In `docker-compose.yml`, change the left-hand side of `ports` (`"8080:80"`, `"8081:8081"`).
If you change the WebSocket port, set the full URL in `CHICKEN_WS` (see below).

## 2. Without Docker

Requirements: PHP with the `json` and `mbstring` extensions (tested version: **8.5**; other
versions have not been tried), and a web server (Apache, nginx + php-fpm) or PHP's built-in server
for testing.

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge

# 1) the website (PHP development server)
mkdir -p web/data && chmod 775 web/data
php -S 0.0.0.0:8080 -t web

# 2) the multiplayer server, in a second terminal
php server/server.php
```

In production, point your web server's document root at `web/` (and **not** at the repository
root: `server/` must never be served over the web). The `web/data/` directory must be writable by
the web server user (`www-data` on Debian) and must not be downloadable:

- Apache: the provided `web/data/.htaccess` denies access, with `AllowOverride All`.
- nginx: add `location /data/ { deny all; }`.

The multiplayer server needs **write access** to the data directory (`web/data/` by default, or `CHICKEN_DATA_DIR`),
run it as the same user as the web server (`www-data`) so the website can read what it writes. If the directory is
not writable the server logs a warning and still runs, but results are not saved.

To keep the multiplayer server running permanently, use for example a systemd unit:

```ini
# /etc/systemd/system/chicken-ws.service
[Unit]
Description=Chicken Challenge WebSocket
After=network.target

[Service]
User=www-data
ExecStart=/usr/bin/php /srv/chicken-challenge/server/server.php
Environment=WS_PORT=8081
Restart=always

[Install]
WantedBy=multi-user.target
```

## 3. Configuration

| Variable | Where | Purpose | Default |
| --- | --- | --- | --- |
| `CHICKEN_WS` | website (PHP) | WebSocket URL used by the **browser** | empty = same host, port 8081 |
| `WS_PORT` | WS server | listening port | `8081` |
| `WS_ALLOWED_ORIGINS` | WS server | allowed origins, comma-separated | empty = all |
| `CHICKEN_DATA_DIR` | WS server | directory where `multi_scores.json` is written (must be writable, and the same one the website reads) | `web/data` (set to `/var/www/html/data` in the Docker image) |

Anti-cheat tuning (defaults are fine; see the *Anti-cheat* section of the README):

| Variable | Where | Purpose | Default |
| --- | --- | --- | --- |
| `CHICKEN_CATCH_CHECK` | WS server | catch arbitration: `enforce`, `log` (accept but log "suspect"), `off` | `enforce` |
| `CHICKEN_THROW_CHECK` | WS server | refuse throws the interface cannot produce: `enforce` / `log` / `off` | `enforce` |
| `CHICKEN_CATCH_SLACK` | WS server | network tolerance of the catch check, in seconds (round-trip time) | `1.0` |
| `CHICKEN_RANK_MIN_POINT_SECONDS` | WS server | a ranked match cannot be faster than this per point (`0` disables) | `1.5` |
| `CHICKEN_PAIR_WINDOW` | WS server | window (seconds) of the repeated-opponent rating reduction | `86400` |
| `CHICKEN_SECRET` | website | secret signing the AI-game tickets (otherwise generated in `data/secret.key`) | generated |
| `CHICKEN_MIN_POINT_SECONDS` | website | an AI game cannot be recorded faster than this per point | `2.0` |
| `CHICKEN_RATE_RECORDS` / `CHICKEN_RATE_TICKETS` | website | recorded games / tickets per hour, per IP and per token | `8` / `40` |

If a legitimate player is wrongly refused (for instance on a very slow connection), set `CHICKEN_CATCH_CHECK=log`
to keep playing while watching `docker compose logs chicken-ws` for `suspect capture` lines, or raise
`CHICKEN_CATCH_SLACK`. The website also keeps `data/secret.key`, `data/abuse.json` and `data/api.lock`: they are
denied to the web like the rest of `data/` and must be included in backups.

With Docker, set these variables in `docker-compose.yml`.

## 4. Behind an HTTPS reverse proxy

A page served over HTTPS can only open a secure WebSocket (`wss://`). You therefore need to proxy
the WebSocket and give its public URL.

nginx example, with the WebSocket under `/ws`:

```nginx
server {
    listen 443 ssl;
    server_name game.example.com;
    # ... certificates ...

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
    }

    location /ws {
        proxy_pass http://127.0.0.1:8081;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 120s;
    }
}
```

Then, in `docker-compose.yml`:

```yaml
  chicken:
    environment:
      CHICKEN_WS: "wss://game.example.com/ws"
  chicken-ws:
    environment:
      WS_ALLOWED_ORIGINS: "https://game.example.com"
```

Restart with `docker compose up -d`.

## 5. Troubleshooting

Messages are quoted as they appear in the English interface.

| Symptom | Likely cause |
| --- | --- |
| "Multiplayer server unreachable" | the `chicken-ws` service is not running, or port 8081 is blocked (firewall, proxy) |
| Multiplayer silent behind HTTPS | `CHICKEN_WS` not set to a `wss://` URL, or the proxy lacks the `Upgrade` headers |
| "Leaderboard unavailable" | `api.php` is not responding, or `web/data/` is not writable |
| No sound | browsers only allow audio after a click: it starts with the first click on "Play" |
| Stale page after an update | clear the cache (Ctrl+F5) |

To check the WebSocket server by hand:

```bash
docker compose logs chicken-ws        # should print "Chicken WS listening on :8081"
```
