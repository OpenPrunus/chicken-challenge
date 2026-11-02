# 🐔 Chicken Challenge

Un jeu de lancer de poule à deux joueurs, en **PHP / JavaScript / HTML**, jouable dans le navigateur
contre une IA ou en ligne contre un autre joueur.

Deux joueurs se font face aux deux extrémités d'un long couloir et se lancent une poule à tour de
rôle. Celui qui rate sa parade prend la poule en pleine figure : **GOAL !**

> Projet de fan, inspiré du jeu Flash *Poulaga Challenge* (Yamago, 2003). Aucun élément graphique,
> sonore ou de code de l'original n'est repris : tout est redessiné en vectoriel dans le canvas et
> synthétisé avec WebAudio. Non affilié à l'original.

## Fonctionnalités

- **Vue en perspective** dans un couloir de poulailler (grillage, poutres, paille), dessiné au trait
  noir épais, avec 4 personnages : le fermier, le cochon, le punk et la mamie.
- **Lancer à la souris** en trois temps :
  1. *Placement* : la souris déplace ton joueur de gauche à droite.
  2. *Clic maintenu* : la position est verrouillée, une jauge de puissance oscille, et la souris
     vise un point précis du couloir (mur, plafond, sol, fond). Une trajectoire en pointillés montre
     les rebonds prévus.
  3. *Relâchement* : souris immobile = tir droit ; un coup de poignet sec à gauche ou à droite donne
     de l'**effet** (trajectoire courbe).
- **Physique de rebonds** sur les murs, le plafond et le sol, avec perte de vitesse.
- **Réception** : la poule grossit en arrivant ; il faut cliquer au bon moment, main placée dessus.
  Trop tôt, la main se ferme dans le vide ; trop tard, elle s'écrase sur l'écran.
- **Score** : premier à 4 points avec 2 d'écart, 12 maximum. Celui qui marque garde la poule.
- **Contre l'IA** : 3 niveaux (Collégien, Flic, Commissaire) et un classement local.
- **Multijoueur en ligne** : salon d'attente, défi par clic sur un pseudo, ou match automatique ;
  revanche en un clic.
- Effets : poule aplatie contre l'écran, plumes, tremblement, bruitages de poule synthétisés.
- Plein écran (touche `F`), quitter avec `Échap`.

## Démarrage rapide

```bash
git clone https://github.com/OpenPrunus/chicken-challenge.git
cd chicken-challenge
docker compose up -d --build
```

Puis ouvre <http://localhost:8080>. Voir [INSTALL.md](INSTALL.md) pour les détails (sans Docker,
reverse proxy HTTPS, configuration, dépannage).

## Architecture

```
web/              site, servi par Apache (PHP 8.5)
  index.php       page unique (menu, salon, jeu)
  api.php         classement des parties contre l'IA (JSON)
  assets/         game.js (moteur, rendu, réseau) et style.css
  data/           scores.json (créé au premier lancement)
server/server.php serveur multijoueur WebSocket en PHP pur, hors de la racine web
Dockerfile        image php:8.5-apache-trixie (Debian 13)
docker-compose.yml  deux services : site (8080) et WebSocket (8081)
```

### Comment fonctionne le multijoueur

- Le serveur WebSocket (`server/server.php`) n'a aucune dépendance : il implémente RFC 6455
  directement avec `stream_select`.
- Les trajectoires sont **déterministes** : le lanceur envoie seulement les paramètres de son tir
  (position, vitesse, effet). Chaque client rejoue la physique de son côté, avec les coordonnées
  inversées (gauche/droite en miroir, la poule part du fond pour le défenseur).
- Pour ne pas subir le lag sur le timing du clic, la **capture est détectée en local** chez le
  défenseur, qui annonce `catch` ou `miss`. Le serveur tient les scores et le tour de rôle, borne
  toutes les valeurs reçues et applique des délais (un joueur inactif perd le point).

### Limites connues

- Le verdict de capture vient du client défenseur : un client modifié pourrait tricher.
- Les parties en ligne ne sont pas enregistrées au classement.
- Pas de reconnexion automatique en cours de match.

## Contrôles

| Action | Commande |
| --- | --- |
| Se placer | Déplacer la souris |
| Viser et charger | Clic maintenu + souris |
| Lancer | Relâcher (coup de poignet = effet) |
| Attraper | Clic (ou barre d'espace) au bon moment |
| Plein écran | `F` |
| Quitter | `Échap` |
