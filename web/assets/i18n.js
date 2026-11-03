// Internationalisation FR / EN. Langue : choix mémorisé > langue du navigateur (FR si français, EN sinon).
(() => {
'use strict';

const DICT = {
  fr: {
    'foot.github': 'Voir le projet sur GitHub',
    'menu.nick': 'Pseudo', 'menu.nick.ph': 'Ton pseudo', 'menu.char': 'Choisis ton personnage', 'menu.level': 'Niveau',
    'level0': 'Collégien', 'level1': 'Flic', 'level2': 'Commissaire',
    'char0': 'Le Fermier', 'char1': 'Le Cochon', 'char2': 'Le Punk', 'char3': 'La Mamie',
    'menu.play': 'JOUER CONTRE L\'IA', 'menu.mp': 'MULTIJOUEUR',
    'rules.title': 'Règles',
    'rules.touch': '<b>Sur téléphone ou tablette</b> (en portrait comme en paysage) : pose le doigt pour te placer et verrouiller ta position, ' +
      '<b>glisse</b> pour viser (le viseur apparaît au-dessus du doigt), puis <b>relâche</b>. Un geste sec de côté au moment de relâcher donne de l\'effet. ' +
      'Pour attraper la poule, <b>tape dessus</b> au bon moment.',
    'rules.body': 'Deux joueurs se lancent une poule dans un tunnel. <b>Déplace la souris</b> pour te placer, ' +
      'puis <b>clique et maintiens</b> : ta position est verrouillée, une jauge oscille et la souris sert à ' +
      '<b>viser un point du couloir</b> (mur, plafond, sol, fond) : la poule y rebondit. <b>Relâche</b> pour ' +
      'lancer : plus la jauge est haute, plus elle va vite. Souris immobile = tir droit ; un <b>coup de poignet</b> ' +
      'sec à gauche ou à droite au moment du relâchement donne de l\'effet (trajectoire courbe). Quand la poule ' +
      'arrive sur toi, place la main dessus et <b>clique au bon moment</b> pour l\'attraper (ou barre d\'espace). ' +
      'Rater = but pour l\'adversaire. En <b>multijoueur</b>, rejoins le salon : défie un joueur ou lance un match ' +
      'automatique. Premier à 4 points avec 2 d\'écart, 12 maximum.',
    'board.title': 'Classement', 'board.empty': 'Personne n\'a encore joué.', 'board.error': 'Classement indisponible.',
    'board.row': ' — {w} V / {p} parties ({gf}-{ga})',
    'board.ai': 'Contre l\'IA', 'board.multi': 'Multijoueur', 'board.multi.title': 'Classement multijoueur',
    'board.row.multi': ' — {r} pts · {w} V / {l} D ({gf}-{ga})', 'board.empty.multi': 'Aucune partie classée pour l\'instant.',

    'lobby.title': 'Salon d\'attente', 'lobby.connecting': 'Connexion au serveur…',
    'lobby.players': 'Joueurs connectés — clique sur un pseudo pour le défier', 'lobby.back': '← Retour',
    'lobby.auto': 'MATCH AUTOMATIQUE', 'lobby.cancel': 'ANNULER LA RECHERCHE',
    'lobby.online_one': 'Connecté en tant que « {name} » — {n} joueur en ligne.',
    'lobby.online_other': 'Connecté en tant que « {name} » — {n} joueurs en ligne.',
    'st.lobby': 'Disponible', 'st.queue': 'Cherche un match…', 'st.match': 'En match', 'lobby.you': '(toi)',
    'lobby.challenge': 'Défier {name}', 'lobby.waiting': 'Défi envoyé à {name}… (20 s)',
    'lobby.searching': 'Recherche d\'un adversaire…',
    'invite.text': '{name} te défie !', 'invite.yes': 'ACCEPTER', 'invite.no': 'Refuser',
    'srv.invalid_name': 'Pseudo invalide.', 'srv.unavailable': 'Ce joueur n\'est plus disponible.',
    'srv.timeout': 'Pas de réponse.', 'srv.refused': '{name} a refusé le défi.', 'srv.stale_invite': 'Ce défi n\'est plus valable.',
    'srv.error': 'Erreur.',
    'net.down': 'Serveur multijoueur injoignable (port 8081 ?).', 'net.lost': 'Déconnecté du serveur.',

    'game.fs': 'Plein écran (F)', 'game.quit': '✕ Quitter', 'game.quit.title': 'Quitter (Échap)',
    'game.quit.confirm': 'Quitter la partie en cours ?',
    'tag.you': 'TOI', 'tag.opp': 'ADVERSAIRE', 'game.rule': 'premier à 4 (2 d\'écart) — 12 max',
    'hint.serve': 'À toi ! Place-toi, puis clique et maintiens',
    'hint.aim': 'Vise un point, puis relâche (coup de poignet = effet)',
    'hint.wait': 'L\'adversaire se prépare…', 'hint.catch': 'Clique au bon moment pour attraper !',
    'hint.serve.touch': 'À toi ! Pose le doigt, glisse pour viser, relâche', 'hint.catch.touch': 'Tape sur la poule au bon moment !',
    'flash.goal': 'GOAL !', 'flash.caught': 'Attrapée !',
    'splat0': 'PAF !', 'splat1': 'SPLOTCH !', 'splat2': 'PROUT !', 'splat3': 'BOUF !',

    'over.win': '🏆 VICTOIRE !', 'over.lose': '😵 DÉFAITE…',
    'over.saving': 'Enregistrement du score…', 'over.saved': 'Score enregistré au classement.',
    'over.notSaved': 'Score non enregistré.', 'over.notSaved2': 'Score non enregistré (serveur injoignable).',
    'over.notSavedRate': 'Score non enregistré : trop de parties enregistrées en peu de temps, réessaie plus tard.',
    'over.nameTaken': 'Score non enregistré : ce pseudo est déjà réservé par un autre joueur dans le classement.',
    'board.ai.note': 'Scores déclarés par les joueurs, non vérifiés.', 'board.multi.note': 'Parties classées par le serveur (Elo).',
    'over.again': 'Rejouer', 'over.rematch': 'Revanche', 'over.menu': 'Menu',
    'over.lostTitle': '🔌 CONNEXION PERDUE', 'over.lostMsg': 'Le serveur multijoueur ne répond plus.',
    'over.lostMsg2': 'Connexion au serveur perdue.',
    'over.forfeit': '🏆 VICTOIRE PAR FORFAIT', 'over.oppLeft': 'L\'adversaire a quitté la partie.',
    'over.rematchAsk': 'L\'adversaire veut une revanche !', 'over.waitRematch': 'En attente de l\'adversaire…',
    'over.rated': 'Classement : {rating} pts ({delta}) — {rank}{ord} sur {total}', 'over.unranked': 'Partie non classée.',
    'over.unrated.too_fast': 'Match non classé : terminé trop vite pour être crédible.',
    'over.reduced': 'Gains réduits : tu as déjà affronté cet adversaire plusieurs fois aujourd\'hui.',
    'over.reducedNone': 'Aucun gain : tu as déjà affronté cet adversaire trop souvent aujourd\'hui.',
  },
  en: {
    'foot.github': 'View the project on GitHub',
    'menu.nick': 'Nickname', 'menu.nick.ph': 'Your nickname', 'menu.char': 'Choose your character', 'menu.level': 'Level',
    'level0': 'Schoolkid', 'level1': 'Cop', 'level2': 'Chief',
    'char0': 'The Farmer', 'char1': 'The Pig', 'char2': 'The Punk', 'char3': 'Granny',
    'menu.play': 'PLAY VS THE AI', 'menu.mp': 'MULTIPLAYER',
    'rules.title': 'Rules',
    'rules.touch': '<b>On a phone or tablet</b> (portrait and landscape both work): touch to position yourself and lock your position, ' +
      '<b>drag</b> to aim (the crosshair appears above your finger), then <b>release</b>. A sharp sideways flick as you let go gives it spin. ' +
      'To catch the chicken, <b>tap it</b> at the right moment.',
    'rules.body': 'Two players throw a chicken at each other down a tunnel. <b>Move the mouse</b> to position ' +
      'yourself, then <b>click and hold</b>: your position is locked, a gauge oscillates and the mouse ' +
      '<b>aims at a spot in the tunnel</b> (wall, ceiling, floor, back): the chicken bounces off it. ' +
      '<b>Release</b> to throw: the higher the gauge, the faster it goes. Mouse still = straight shot; a sharp ' +
      '<b>flick</b> to the left or right as you release gives it spin (curved trajectory). When the chicken ' +
      'comes at you, put your hand over it and <b>click at the right moment</b> to catch it (or press space). ' +
      'Miss = a goal for your opponent. In <b>multiplayer</b>, join the lobby: challenge a player or start an ' +
      'automatic match. First to 4 points with a 2-point lead, 12 maximum.',
    'board.title': 'Leaderboard', 'board.empty': 'Nobody has played yet.', 'board.error': 'Leaderboard unavailable.',
    'board.row': ' — {w} W / {p} games ({gf}-{ga})',
    'board.ai': 'Vs the AI', 'board.multi': 'Multiplayer', 'board.multi.title': 'Multiplayer leaderboard',
    'board.row.multi': ' — {r} pts · {w} W / {l} L ({gf}-{ga})', 'board.empty.multi': 'No ranked game yet.',

    'lobby.title': 'Lobby', 'lobby.connecting': 'Connecting to the server…',
    'lobby.players': 'Connected players — click a nickname to challenge them', 'lobby.back': '← Back',
    'lobby.auto': 'AUTOMATIC MATCH', 'lobby.cancel': 'CANCEL SEARCH',
    'lobby.online_one': 'Connected as "{name}" — {n} player online.',
    'lobby.online_other': 'Connected as "{name}" — {n} players online.',
    'st.lobby': 'Available', 'st.queue': 'Looking for a match…', 'st.match': 'In a match', 'lobby.you': '(you)',
    'lobby.challenge': 'Challenge {name}', 'lobby.waiting': 'Challenge sent to {name}… (20 s)',
    'lobby.searching': 'Looking for an opponent…',
    'invite.text': '{name} challenges you!', 'invite.yes': 'ACCEPT', 'invite.no': 'Decline',
    'srv.invalid_name': 'Invalid nickname.', 'srv.unavailable': 'This player is no longer available.',
    'srv.timeout': 'No answer.', 'srv.refused': '{name} declined the challenge.', 'srv.stale_invite': 'This challenge is no longer valid.',
    'srv.error': 'Error.',
    'net.down': 'Multiplayer server unreachable (port 8081?).', 'net.lost': 'Disconnected from the server.',

    'game.fs': 'Fullscreen (F)', 'game.quit': '✕ Quit', 'game.quit.title': 'Quit (Esc)',
    'game.quit.confirm': 'Leave the current game?',
    'tag.you': 'YOU', 'tag.opp': 'OPPONENT', 'game.rule': 'first to 4 (2-point lead) — 12 max',
    'hint.serve': 'Your turn! Move, then click and hold',
    'hint.aim': 'Aim at a spot, then release (flick = spin)',
    'hint.wait': 'Your opponent is getting ready…', 'hint.catch': 'Click at the right moment to catch it!',
    'hint.serve.touch': 'Your turn! Touch, drag to aim, release', 'hint.catch.touch': 'Tap the chicken at the right moment!',
    'flash.goal': 'GOAL!', 'flash.caught': 'Caught!',
    'splat0': 'SPLAT!', 'splat1': 'SMACK!', 'splat2': 'THWAP!', 'splat3': 'FLUMP!',

    'over.win': '🏆 YOU WIN!', 'over.lose': '😵 YOU LOSE…',
    'over.saving': 'Saving your score…', 'over.saved': 'Score saved to the leaderboard.',
    'over.notSaved': 'Score not saved.', 'over.notSaved2': 'Score not saved (server unreachable).',
    'over.notSavedRate': 'Score not saved: too many games recorded in a short time, try again later.',
    'over.nameTaken': 'Score not saved: this nickname is already reserved by another player on the leaderboard.',
    'board.ai.note': 'Scores reported by the players, unverified.', 'board.multi.note': 'Games ranked by the server (Elo).',
    'over.again': 'Play again', 'over.rematch': 'Rematch', 'over.menu': 'Menu',
    'over.lostTitle': '🔌 CONNECTION LOST', 'over.lostMsg': 'The multiplayer server is no longer responding.',
    'over.lostMsg2': 'Connection to the server lost.',
    'over.forfeit': '🏆 WIN BY FORFEIT', 'over.oppLeft': 'Your opponent left the game.',
    'over.rematchAsk': 'Your opponent wants a rematch!', 'over.waitRematch': 'Waiting for your opponent…',
    'over.rated': 'Ranking: {rating} pts ({delta}) — {rank}{ord} of {total}', 'over.unranked': 'Unranked game.',
    'over.unrated.too_fast': 'Unranked: finished too quickly to be credible.',
    'over.reduced': 'Reduced gains: you have already played this opponent several times today.',
    'over.reducedNone': 'No gains: you have already played this opponent too often today.',
  },
};

const store = {
  get() { try { return localStorage.getItem('cc_lang'); } catch { return null; } },
  set(v) { try { localStorage.setItem('cc_lang', v); } catch {} },
};
const detect = () => String((navigator.languages && navigator.languages[0]) || navigator.language || 'en')
  .toLowerCase().startsWith('fr') ? 'fr' : 'en';

let lang = store.get();
if (lang !== 'fr' && lang !== 'en') lang = detect();
const listeners = [];

function t(key, vars) {
  let s = DICT[lang][key] ?? DICT.en[key] ?? key;
  if (vars) s = s.replace(/\{(\w+)\}/g, (_, k) => (vars[k] ?? ''));
  return s;
}
const tn = (key, n, vars) => t(key + (n === 1 ? '_one' : '_other'), { n, ...vars });

// Suffixe d'ordinal : 1er, 2e, 3e… en français ; 1st, 2nd, 3rd, 4th, 11th, 12th, 21st… en anglais.
function ordinal(n) {
  if (lang === 'fr') return n === 1 ? 'er' : 'e';
  const m100 = n % 100;
  if (m100 >= 11 && m100 <= 13) return 'th';
  return { 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] || 'th';
}

function apply() {
  document.documentElement.lang = lang;
  document.querySelectorAll('[data-i18n]').forEach((e) => { e.textContent = t(e.dataset.i18n); });
  document.querySelectorAll('[data-i18n-html]').forEach((e) => { e.innerHTML = t(e.dataset.i18nHtml); });   // textes internes, de confiance
  document.querySelectorAll('[data-i18n-title]').forEach((e) => { e.title = t(e.dataset.i18nTitle); });
  document.querySelectorAll('[data-i18n-ph]').forEach((e) => { e.placeholder = t(e.dataset.i18nPh); });
  document.querySelectorAll('#lang button').forEach((b) => b.classList.toggle('sel', b.dataset.lang === lang));
}

function setLang(l) {
  if (l !== 'fr' && l !== 'en') return;
  lang = l; store.set(l); apply(); listeners.forEach((f) => f(l));
}

document.querySelectorAll('#lang button').forEach((b) => { b.onclick = () => setLang(b.dataset.lang); });
apply();

window.I18N = { t, tn, ordinal, setLang, apply, get lang() { return lang; }, onChange: (f) => listeners.push(f), dict: DICT };
})();
