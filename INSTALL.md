# Installation

Chicken Challenge se compose de deux processus : le **site** (PHP + Apache, port 80) et le
**serveur multijoueur** (PHP en ligne de commande, port 8081). Sans le second, seul le mode contre
l'IA fonctionne.

## 1. Avec Docker (recommandé)

Prérequis : Docker et Docker Compose.

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge
docker compose up -d --build
```

- Jeu : <http://localhost:8080>
- WebSocket : `ws://localhost:8081`

L'image est basée sur `php:8.5-apache-trixie` (Debian 13, PHP 8.5). Les deux services utilisent la
même image.

Commandes utiles :

```bash
docker compose logs -f chicken-ws     # journal du serveur multijoueur
docker compose ps                     # état des services
docker compose down                   # arrêt (les scores sont conservés)
docker compose down -v                # arrêt ET suppression des scores
```

### Où sont les scores ?

Dans le volume Docker nommé `chicken-challenge_scores`, monté sur `/var/www/html/data` (fichier
`scores.json`). Il survit aux reconstructions de l'image.

```bash
# sauvegarde
docker compose cp chicken:/var/www/html/data/scores.json ./scores-backup.json
# remise à zéro
docker compose exec chicken sh -c 'echo "[]" > /var/www/html/data/scores.json'
```

### Changer les ports

Dans `docker-compose.yml`, modifie la partie gauche des `ports` (`"8080:80"`, `"8081:8081"`).
Si tu changes le port WebSocket, indique l'URL complète dans `CHICKEN_WS` (voir plus bas).

## 2. Sans Docker

Prérequis : PHP avec les extensions `json` et `mbstring` (version testée : **8.5** ; les autres
versions n'ont pas été essayées), et un serveur web (Apache, nginx + php-fpm) ou le serveur intégré de PHP pour les tests.

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge

# 1) le site (serveur de développement PHP)
mkdir -p web/data && chmod 775 web/data
php -S 0.0.0.0:8080 -t web

# 2) le serveur multijoueur, dans un second terminal
php server/server.php
```

Pour la production, pointe la racine de ton serveur web sur `web/` (et **pas** sur la racine du
dépôt : `server/` ne doit jamais être servi par le web). Le dossier `web/data/` doit être
inscriptible par l'utilisateur du serveur web (`www-data` sous Debian) et ne doit pas être
téléchargeable :

- Apache : le fichier `web/data/.htaccess` fourni refuse l'accès, avec `AllowOverride All`.
- nginx : ajoute `location /data/ { deny all; }`.

Pour garder le serveur multijoueur actif en permanence, utilise par exemple une unité systemd :

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

| Variable | Où | Rôle | Défaut |
| --- | --- | --- | --- |
| `CHICKEN_WS` | site (PHP) | URL WebSocket que le **navigateur** utilise | vide = même hôte, port 8081 |
| `WS_PORT` | serveur WS | port d'écoute | `8081` |
| `WS_ALLOWED_ORIGINS` | serveur WS | origines autorisées, séparées par des virgules | vide = toutes |

Avec Docker, ces variables se règlent dans `docker-compose.yml`.

## 4. Derrière un reverse proxy HTTPS

Une page servie en HTTPS ne peut ouvrir qu'un WebSocket sécurisé (`wss://`). Il faut donc proxifier
le WebSocket et indiquer son URL publique.

Exemple nginx, avec le WebSocket sous `/ws` :

```nginx
server {
    listen 443 ssl;
    server_name jeu.exemple.fr;
    # ... certificats ...

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

Puis dans `docker-compose.yml` :

```yaml
  chicken:
    environment:
      CHICKEN_WS: "wss://jeu.exemple.fr/ws"
  chicken-ws:
    environment:
      WS_ALLOWED_ORIGINS: "https://jeu.exemple.fr"
```

Redémarre avec `docker compose up -d`.

## 5. Dépannage

| Symptôme | Cause probable |
| --- | --- |
| « Serveur multijoueur injoignable » | le service `chicken-ws` ne tourne pas, ou le port 8081 est bloqué (pare-feu, proxy) |
| Multijoueur muet derrière HTTPS | `CHICKEN_WS` non défini en `wss://`, ou proxy sans en-têtes `Upgrade` |
| « Classement indisponible » | `api.php` ne répond pas, ou `web/data/` n'est pas inscriptible |
| Pas de son | les navigateurs n'autorisent le son qu'après un clic : il démarre au premier clic sur « Jouer » |
| Page obsolète après mise à jour | vider le cache (Ctrl+F5) |

Pour vérifier le serveur WebSocket à la main :

```bash
docker compose logs chicken-ws        # doit afficher « Chicken WS en écoute sur :8081 »
```
