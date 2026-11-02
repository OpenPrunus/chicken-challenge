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

In the named Docker volume `chicken-challenge_scores`, mounted on `/var/www/html/data` (file
`scores.json`). It survives image rebuilds.

```bash
# backup
docker compose cp chicken:/var/www/html/data/scores.json ./scores-backup.json
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

The game interface is in French, so the messages below are quoted as they appear in the game.

| Symptom | Likely cause |
| --- | --- |
| "Serveur multijoueur injoignable" (multiplayer server unreachable) | the `chicken-ws` service is not running, or port 8081 is blocked (firewall, proxy) |
| Multiplayer silent behind HTTPS | `CHICKEN_WS` not set to a `wss://` URL, or the proxy lacks the `Upgrade` headers |
| "Classement indisponible" (leaderboard unavailable) | `api.php` is not responding, or `web/data/` is not writable |
| No sound | browsers only allow audio after a click: it starts with the first click on "Play" |
| Stale page after an update | clear the cache (Ctrl+F5) |

To check the WebSocket server by hand:

```bash
docker compose logs chicken-ws        # should print "Chicken WS en écoute sur :8081"
```
