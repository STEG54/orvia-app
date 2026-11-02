<?php
// Pas encore installée : l'assistant d'installation prend la main.
if (!is_file(__DIR__ . '/config.php')) {
  if (PHP_SAPI !== 'cli' && preg_match('/\.php$/', $_SERVER['SCRIPT_NAME'] ?? '') && !str_ends_with($_SERVER['SCRIPT_NAME'], 'api.php')) { header('Location: installation.php'); exit; }
  http_response_code(503); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Application pas encore installée : ouvrez installation.php.']); exit;
}
require_once __DIR__ . '/config.php';
// Clé des documents : AMAYA_CLE (installations récentes) ou LPI_CLE (installations d'origine), même rôle.
if (defined('AMAYA_CLE') && !defined('LPI_CLE')) define('LPI_CLE', AMAYA_CLE);
if (defined('AMAYA_CLE_FICHIER') && !defined('LPI_CLE_FICHIER')) define('LPI_CLE_FICHIER', AMAYA_CLE_FICHIER);
if (defined('AMAYA_CLES_ANCIENNES') && !defined('LPI_CLES_ANCIENNES')) define('LPI_CLES_ANCIENNES', AMAYA_CLES_ANCIENNES);
/** En-têtes de sécurité envoyés par toutes les pages et API (Apache comme Nginx) :
 *  HTTPS imposé au navigateur, pas d'affichage dans un cadre d'un autre site, pas de devinette de type, scripts et connexions limités à l'appli.
 *  Les scripts et styles intégrés aux pages restent permis (étape suivante : les déplacer dans des fichiers pour retirer 'unsafe-inline'). */
function entetes_securite(): void {
  if (PHP_SAPI === 'cli' || headers_sent()) return;
  if (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') header('Strict-Transport-Security: max-age=31536000');
  header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(self), geolocation=(self), microphone=(), payment=(), usb=()');
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; "
    . "connect-src 'self' https://geo.api.gouv.fr; media-src 'self' blob:; worker-src 'self'; manifest-src 'self'; frame-src 'self' blob:; frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'");
  // Les PDF, photos et fichiers servis par l'appli gardent leur affichage natif : la politique de contenu ne vise que les pages.
  header_register_callback(function () { foreach (headers_list() as $h) if (stripos($h, 'Content-Type:') === 0 && stripos($h, 'text/html') === false) { header_remove('Content-Security-Policy'); break; }
    if (function_exists('journal_telechargement')) journal_telechargement(); });
}
entetes_securite();
/** Erreurs : jamais de détail technique dans le navigateur (chemins, requêtes, extraits de code). Le détail part dans le journal d'erreurs de l'hébergement,
 *  la personne voit un message simple et une référence à transmettre. Les messages rédigés pour l'utilisateur (RuntimeException de l'appli) restent affichés. */
if (PHP_SAPI !== 'cli') {
  ini_set('display_errors', '0'); ini_set('log_errors', '1');
  set_exception_handler(function (Throwable $e) {
    $ref = strtoupper(bin2hex(random_bytes(3))); error_log('Amaya [' . $ref . '] ' . get_class($e) . ' : ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n" . $e->getTraceAsString());
    $msg = $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Erreur interne. Si elle se répète, transmettez la référence ' . $ref . ' à l\'éditeur.';
    if (!headers_sent()) { http_response_code(500); header('Cache-Control: no-store'); }
    $json = str_ends_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '_api.php') || str_ends_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api.php') || requete_appli();
    if ($json) { if (!headers_sent()) header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE); }
    else { if (!headers_sent()) header('Content-Type: text/html; charset=utf-8'); echo '<!DOCTYPE html><meta charset="utf-8"><title>Erreur</title><p style="font:16px sans-serif;margin:40px auto;max-width:520px">' . htmlspecialchars($msg) . '</p>'; }
  });
}
// Nom du produit, affiché partout (logos : assets/logo-produit*.png, styles : marque.css). Un seul endroit à changer en cas de nouvelle marque.
defined('PRODUIT_NOM') || define('PRODUIT_NOM', 'Keylia');
defined('AG_NOM') || define('AG_NOM', 'Agence'); defined('AG_COURT') || define('AG_COURT', AG_NOM); defined('AG_EMAIL') || define('AG_EMAIL', MAIL_TO_DEFAULT);
defined('AG_COULEUR') || define('AG_COULEUR', '#6A4BFF'); defined('AG_MATRICULE') || define('AG_MATRICULE', 'AG'); defined('AG_TESTEURS') || define('AG_TESTEURS', []);
defined('ADMINS_PRINCIPAUX') || define('ADMINS_PRINCIPAUX', []);
/** Logo de l'agence (déposé dans Réglages), sinon celui d'Amaya. $blanc : version claire pour les bandeaux foncés. */
/** Démo publique (config.php : define('AMAYA_DEMO', true)) : données fictives, e-mails coupés, réglages sensibles bloqués. */
/** Requête envoyée par l'appli elle-même (en-tête ajouté par son JavaScript : protège les envois contre les formulaires d'autres sites). L'ancien nom « lpi » reste accepté le temps que les téléphones rechargent l'appli. */
function requete_appli(): bool { return in_array($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', ['amaya', 'lpi'], true); }
function demo_actif(): bool { return defined('AMAYA_DEMO') && AMAYA_DEMO; }
const DEMO_BLOQUE = ['mail_test', 'mail_etat', 'exp_agence', 'exp_telecharger', 'cle_changer', 'cle_deplacer', 'cle_rechiffrer', 'cle_retirer', 'geo_tester', 'journal', 'externe_envoyer', 'externe_verifier', 'tf_reinit', 'tf_preparer', 'tf_activer', 'tf_desactiver', 'save_admin', 'admin_principal_pw', 'delete_admin', 'my_password', 'save_code', 'erase_animatrice', 'fusionner', 'push_abonner',
  'nettoyer_liste', 'maint', 'sauvegarde_faire', 'sauvegarde_dl', 'maj_deposer', 'maj_distante', 'maj_appliquer', 'identite_image', 'login', 'login_code', 'login_admin', 'login_totp'];
function demo_bloquer(string $action): void {
  if (!demo_actif() || !in_array($action, DEMO_BLOQUE, true)) return;
  http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Désactivé dans la démo (données fictives). Tout le reste se teste librement.']); exit;
}
/** Thèmes proposés (Réglages) : fond, encre, filets, texte secondaire, couleur « pop » du dégradé. La couleur principale reste celle de l'agence. */
const AG_THEMES = [
  'orvia' => ['nom' => 'Keylia (noir et blanc)', 'bg' => '#F4F3F1', 'ink' => '#0F0F0F', 'line' => '#E4E2DE', 'muted' => '#6A6864', 'pop' => '#0F0F0F', 'defaut' => '#0F0F0F'],
  'aurore' => ['nom' => 'Aurore (violet et rose)', 'bg' => '#F6F5FF', 'ink' => '#16123A', 'line' => '#E6E3F7', 'muted' => '#6E6A8F', 'pop' => '#FF5C8A', 'defaut' => '#6A4BFF'],
  'lagon' => ['nom' => 'Lagon (turquoise et mangue)', 'bg' => '#F1FAF8', 'ink' => '#0F2A2E', 'line' => '#D9EDEA', 'muted' => '#5D7A7C', 'pop' => '#FFB020', 'defaut' => '#0EA5A0'],
  'cobalt' => ['nom' => 'Cobalt (bleu et corail)', 'bg' => '#F3F6FF', 'ink' => '#0C1A3D', 'line' => '#E0E6F7', 'muted' => '#5F6B8A', 'pop' => '#FF7A45', 'defaut' => '#2F5BFF'],
  'cuivre' => ['nom' => 'Cuivre (brun et cuivre)', 'bg' => '#FAF6F1', 'ink' => '#221A15', 'line' => '#EBE0D5', 'muted' => '#7A6A5E', 'pop' => '#D9956E', 'defaut' => '#A9633F'],
];
/** Couleur principale : celle choisie dans Réglages, sinon celle de l'installation. */
function ag_couleur(): string { $c = ''; try { $c = (string)reglage('couleur', ''); } catch (Throwable $e) {} if (!preg_match('/^#[0-9a-f]{6}$/i', $c)) $c = AG_COULEUR; return preg_match('/^#[0-9a-f]{6}$/i', $c) ? $c : ag_theme()['defaut']; }
function ag_theme(): array { $k = 'orvia'; try { $k = (string)reglage('theme', 'orvia'); } catch (Throwable $e) {} return AG_THEMES[$k] ?? AG_THEMES['orvia']; }
/** Dans le <head>, après style.css : polices, thème et couleur de l'agence. */
function ag_style(): string {
  $t = ag_theme(); $c = ag_couleur();
  return '<link rel="stylesheet" href="theme.css?v=' . @filemtime(__DIR__ . '/theme.css') . '">' . "\n"
    . '<link rel="stylesheet" href="marque.css?v=' . @filemtime(__DIR__ . '/marque.css') . '">' . "\n"
    . "<style>:root{--terra:$c;--terra-soft:color-mix(in srgb, $c 12%, #fff);--pop:{$t['pop']};--esp:{$t['ink']};--sand:{$t['bg']};--line:{$t['line']};--muted:{$t['muted']};--ok:#0C8A64;--ok-soft:#E3F8F0;--bad:#E03131;--bad-soft:#FFF0F0}</style>";
}
/** Bandeau de la démo : passer d'un espace à l'autre (direction, équipe, marque) et revenir au site, depuis n'importe quelle page. */
function demo_ruban(): string {
  $ici = ['admin.php' => 'direction', 'index.php' => 'equipe', 'proprietaire.php' => 'proprietaire'][basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))] ?? '';
  $l = []; foreach (['direction' => 'Direction', 'equipe' => 'Équipe terrain', 'proprietaire' => 'Propriétaire'] as $k => $t) $l[] = $k === $ici ? '<b aria-current="page">' . $t . '</b>' : '<a href="demo.php?role=' . $k . '">' . $t . '</a>';
  return '<nav class="demo-ruban" aria-label="Démo ' . PRODUIT_NOM . '"><span class="dr-t">Démo<span class="dr-l"> ' . PRODUIT_NOM . '</span></span>' . implode('', $l)
    . '<a href="demo.php" class="dr-x">Accueil démo</a>' . (defined('AMAYA_SITE') && AMAYA_SITE ? '<a href="' . h((string)AMAYA_SITE) . '" class="dr-x">Retour au site</a>' : '') . '</nav>';
}
/** Avant les scripts : nom de l'agence pour les textes de l'appli. */
function ag_head(): string {
  return (demo_actif() ? demo_ruban() : '') . '<script>const AG = ' . json_encode(AG_COURT) . ', AGN = ' . json_encode(AG_NOM) . ', CONTACT = ' . json_encode(AG_EMAIL) . ', PRODUIT = ' . json_encode(PRODUIT_NOM) . ', AMAYA_DEMO = ' . (demo_actif() ? 'true' : 'false') . ";</script>";
}
function ag_logo(bool $blanc = false): string { foreach ($blanc ? ['logo-agence-blanc.png', 'logo-agence.png'] : ['logo-agence.png'] as $f) if (is_file(__DIR__ . '/uploads/' . $f)) return 'uploads/' . $f; return $blanc ? 'assets/logo-produit-blanc.png' : 'assets/logo-produit.png'; }
/** Logo pour l'en-tête des pages, avec la date du fichier : le navigateur recharge un logo modifié. */
function ag_logo_url(bool $blanc = false): string { $f = ag_logo($blanc); return $f . '?v=' . @filemtime(__DIR__ . '/' . $f); }

const ZONES = ['France' => 'EUR']; // Amaya : France métropolitaine ; le découpage se fait par département et par secteur (groupes de départements)
const DEVISE_SYMBOLE = ['EUR' => '€'];
const MAX_PHOTOS = 5;
// Clients proposés aux intervenants : nom, marques (affichées entre parenthèses), marques dont le CA est saisi séparément.
const CLIENTS = []; // clients (marques) de l'agence : créés dans l'onglet Marques
// Reportings de BA permanentes : module sur mesure, désactivé (aucun modèle).
// Modèles de rapport attribués nominativement. photos : 0 = pas de limite.
const BA_MODELES = [];
const BA_PHOTOS_MAX = 60; // garde-fou technique quand il n'y a « pas de limite »
// Listes de destinataires proposées à l'envoi : nom, adresses, clients (noms), zones, modèles BA.
const LISTES_DEPART = []; // listes de destinataires : créées dans l'onglet Envois
// Droits des comptes administrateurs (la lecture est toujours permise).
const ADMIN_DROITS = ['rapports' => 'Préparer les rapports et corriger les comptes rendus', 'envoi' => 'Envoyer les rapports aux clients', 'tout' => 'Tout gérer (intervenants, marques, formations, points de vente, listes)'];
// Administrateurs principaux : tous les droits, et seuls à créer des comptes et donner des droits.
// ADMINS_PRINCIPAUX (dirigeants de l'agence) est défini dans config.php par l'assistant d'installation.

// RGPD : durée de conservation des comptes rendus et des photos.
const CONSERVATION_ANS = 3;
const RGPD_CONTACT = AG_EMAIL;
const FREQUENTATION = ['Mauvaise', 'Moyenne', 'Bonne', 'Excellente'];
const CONCURRENCE = []; // remplacée par concurrents() : liste propre à chaque agence (Marques)
/** Marques concurrentes proposées dans les comptes rendus : liste saisie par l'agence (Reporting › Marques). */
function concurrents(): array {
  static $c = null; if ($c !== null) return $c;
  try { db()->exec("CREATE TABLE IF NOT EXISTS g_reglages (cle VARCHAR(40) PRIMARY KEY, valeur MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $v = (string)db()->query("SELECT valeur FROM g_reglages WHERE cle = 'concurrents'")->fetchColumn(); } catch (Throwable $e) { $v = ''; }
  return $c = array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 60), preg_split('/[\n,;]+/', $v)), 'strlen')));
}

/** Démo : chaque visiteur a ses propres tables (préfixe d<n>_), dans la même base. Personne ne voit les données d'un autre. */
/** Démo : l'agence fictive de ce visiteur existe-t-elle encore (moins de 7 jours) ? */
function demo_lead_ok(int $id): bool { $o = $GLOBALS['DEMO_P'] ?? null; $GLOBALS['DEMO_P'] = '';
  try { $st = db()->prepare("SELECT 1 FROM demo_leads WHERE id = ? AND supprime_le IS NULL"); $st->execute([$id]); return (bool)$st->fetchColumn(); } catch (Throwable $e) { return false; }
  finally { if ($o === null) unset($GLOBALS['DEMO_P']); else $GLOBALS['DEMO_P'] = $o; } }
function demo_prefixe(): string { if (!demo_actif()) return ''; $p = (string)($GLOBALS['DEMO_P'] ?? ($_SESSION['demo_p'] ?? '')); return preg_match('/^d\d{1,9}_$/', $p) ? $p : ''; }
/** Ajoute le préfixe du visiteur aux noms de tables d'une requête (hors textes entre guillemets). */
function sql_prefixer(string $sql, string $p): string {
  if ($p === '') return $sql;
  $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*')/s", $sql, -1, PREG_SPLIT_DELIM_CAPTURE); $out = '';
  foreach ($parts as $i => $x) {
    if ($i % 2) { // texte entre guillemets : seul SHOW TABLES LIKE 'x' est concerné
      $out .= preg_match('/SHOW\s+TABLES\s+LIKE\s*$/i', $out) ? "'" . $p . substr($x, 1) : $x; continue; }
    $x = preg_replace_callback('/\b(FROM|JOIN|INTO|REFERENCES|TRUNCATE(?:\s+TABLE)?|(?<!KEY\s)UPDATE(?:\s+IGNORE)?|TABLE(?:\s+IF\s+(?:NOT\s+)?EXISTS)?)(\s+)(`?)([A-Za-z_][A-Za-z0-9_]*)\3/i', function ($m) use ($p) {
      $n = $m[4]; if (strcasecmp($n, 'information_schema') === 0 || strcasecmp($n, 'DUAL') === 0 || str_starts_with($n, $p) || strtoupper($n) === 'SELECT') return $m[0];
      return $m[1] . $m[2] . '`' . $p . $n . '`'; }, $x);
    $x = preg_replace_callback('/\bCONSTRAINT(\s+)`([A-Za-z0-9_]+)`/i', fn($m) => 'CONSTRAINT' . $m[1] . '`' . $p . $m[2] . '`', $x);
    $out .= $x;
  }
  return $out;
}
if (!class_exists('DemoPDO')) {
  class DemoPDO extends PDO {
    public function prepare(string $q, array $o = []): PDOStatement|false { return parent::prepare(sql_prefixer($q, demo_prefixe()), $o); }
    public function exec(string $q): int|false { return parent::exec(sql_prefixer($q, demo_prefixe())); }
    public function query(string $q, ?int $m = null, mixed ...$a): PDOStatement|false { $q = sql_prefixer($q, demo_prefixe()); return $m === null ? parent::query($q) : parent::query($q, $m, ...$a); }
  }
}
function db(): PDO {
  static $pdo = null;
  if (!$pdo) {
    try {
      $cl = demo_actif() ? 'DemoPDO' : 'PDO';
      $pdo = new $cl('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      ]);
    } catch (PDOException $e) {
      error_log('Amaya, base : ' . $e->getMessage());
      throw new RuntimeException('Connexion à la base impossible : vérifiez DB_HOST, DB_NAME, DB_USER et DB_PASS dans config.php (ouvrez diag.php pour le détail).');
    }
    $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    migrate($pdo);
    require_once __DIR__ . '/migrations_lib.php'; mig_auto($pdo); // évolutions numérotées (depuis la 1.8.0)
  }
  return $pdo;
}

/** Met à jour la base automatiquement (pas besoin de repasser par phpMyAdmin). */
function migrate(PDO $pdo): void {
  $flag = upload_dir() . '/.schema33';
  if (is_file($flag)) return;
  migrate_v14($pdo);
  migrate_v16($pdo);
  migrate_v17($pdo);
  migrate_v18($pdo);
  migrate_v19($pdo);
  migrate_v20($pdo);
  geo_schema(); // v21 : adresse et coordonnées des points de vente
  migrate_v22($pdo);
  migrate_v25($pdo);
  // v24 : statut de la personne (actif, actif sans missions groupées, stand-by, refusé).
  if (!in_array('statut', $pdo->query("SHOW COLUMNS FROM animatrices")->fetchAll(PDO::FETCH_COLUMN), true)) {
    $pdo->exec("ALTER TABLE animatrices ADD COLUMN statut VARCHAR(12) NOT NULL DEFAULT 'actif'"); $pdo->exec("UPDATE animatrices SET statut = 'refuse' WHERE actif = 0"); }
  // v23 : la zone suit le code postal de la fiche quand il est renseigné.
  foreach ($pdo->query("SELECT animatrice_id, data FROM fiches_animatrices WHERE data LIKE '%code_postal%'")->fetchAll() as $f) { $d = json_decode((string)$f['data'], true) ?: [];
    if ($z = zone_cp((string)($d['code_postal'] ?? ''))) $pdo->prepare("UPDATE animatrices SET zone = ? WHERE id = ? AND zone <> ?")->execute([$z, $f['animatrice_id'], $z]); }
  $pdo->exec("CREATE TABLE IF NOT EXISTS fiches_animatrices (animatrice_id INT UNSIGNED PRIMARY KEY, data MEDIUMTEXT NULL, photo VARCHAR(80) NOT NULL DEFAULT '', cv VARCHAR(80) NOT NULL DEFAULT '', cv_nom VARCHAR(200) NOT NULL DEFAULT '', notes_admin TEXT NULL, updated_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  fiche_dir();
  $c = $pdo->query("SHOW COLUMNS FROM animatrices")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('rgpd_info_at', $c, true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN rgpd_info_at DATETIME NULL, ADD COLUMN rgpd_image_at DATETIME NULL");
  if (!in_array('restreint', $pdo->query("SHOW COLUMNS FROM formations")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE formations ADD COLUMN restreint TINYINT(1) NOT NULL DEFAULT 0");
  $pdo->exec("CREATE TABLE IF NOT EXISTS formation_acces (formation_id INT UNSIGNED NOT NULL, animatrice_id INT UNSIGNED NOT NULL, PRIMARY KEY (formation_id, animatrice_id), INDEX (animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("CREATE TABLE IF NOT EXISTS formations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, marque_id INT UNSIGNED NULL, titre VARCHAR(160) NOT NULL, description TEXT NULL,
    type VARCHAR(10) NOT NULL, fichier VARCHAR(80) NOT NULL DEFAULT '', nom_original VARCHAR(200) NOT NULL DEFAULT '', mime VARCHAR(100) NOT NULL DEFAULT '', taille BIGINT NOT NULL DEFAULT 0,
    url VARCHAR(500) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (marque_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  formation_dir();
  // v7 : les intervenants choisissent un client (marques entre parenthèses) ; un client peut ventiler son CA par marque.
  $col = fn($t) => $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('detail', $col('marques'), true)) $pdo->exec("ALTER TABLE marques ADD COLUMN detail VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN sous_marques VARCHAR(200) NOT NULL DEFAULT '', ADD COLUMN ordre INT NOT NULL DEFAULT 100");
  if (!in_array('ventilation', $col('rapport_marques'), true)) $pdo->exec("ALTER TABLE rapport_marques ADD COLUMN ventilation TEXT NULL");
  if (!in_array('email', $col('animatrices'), true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN email VARCHAR(160) NOT NULL DEFAULT '' AFTER telephone");
  if (!is_file(upload_dir() . '/.clients')) {
    $pdo->exec("UPDATE marques SET actif = 0");
    $ins = $pdo->prepare("INSERT INTO marques (nom, detail, sous_marques, ordre, actif) VALUES (?,?,?,?,1) ON DUPLICATE KEY UPDATE detail = VALUES(detail), sous_marques = VALUES(sous_marques), ordre = VALUES(ordre), actif = 1");
    foreach (CLIENTS as $i => [$nom, $detail, $sous]) $ins->execute([$nom, $detail, $sous, $i + 1]);
    @file_put_contents(upload_dir() . '/.clients', date('c'));
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS groupes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(80) NOT NULL, marques VARCHAR(400) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!in_array('objectif', $pdo->query("SHOW COLUMNS FROM rapport_marques")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE rapport_marques ADD COLUMN objectif DECIMAL(12,2) NULL AFTER ca");
  if (!in_array('modifie_le', $pdo->query("SHOW COLUMNS FROM rapports")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE rapports ADD COLUMN modifie_le DATETIME NULL");
  $pdo->exec("CREATE TABLE IF NOT EXISTS brouillons (animatrice_id INT UNSIGNED PRIMARY KEY, token CHAR(32) NOT NULL, data MEDIUMTEXT NOT NULL, updated_at BIGINT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $cols = $pdo->query("SHOW COLUMNS FROM animatrices")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('telephone', $cols, true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN telephone VARCHAR(20) NOT NULL DEFAULT '' AFTER nom, ADD INDEX (telephone)");
  if (!in_array('created_at', $cols, true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN created_at DATETIME NULL");
  $pdo->exec("ALTER TABLE animatrices MODIFY code CHAR(6) NULL");
  $pdo->exec("CREATE TABLE IF NOT EXISTS reglages (cle VARCHAR(40) PRIMARY KEY, valeur VARCHAR(200) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $pdo->exec("INSERT IGNORE INTO reglages (cle, valeur) VALUES ('code_agence', 'AMAYA')");
  @file_put_contents($flag, date('c'));
}
/** v18 : validation des comptes rendus avant l'espace marques, photos montrées aux clients, comptes clients. */
function migrate_v18(PDO $pdo): void {
  $c = $pdo->query("SHOW COLUMNS FROM rapports")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('valide_le', $c, true)) {
    $pdo->exec("ALTER TABLE rapports ADD COLUMN valide_le DATETIME NULL, ADD COLUMN valide_par VARCHAR(120) NOT NULL DEFAULT ''");
    // L'historique déjà envoyé aux marques est considéré comme validé ; la semaine en cours reste à valider.
    $lundi = date('Y-m-d', strtotime('monday this week'));
    $pdo->prepare("UPDATE rapports SET valide_le = created_at, valide_par = 'Historique' WHERE date_animation < ? OR source = 'import'")->execute([$lundi]);
  }
  if (!in_array('client', $pdo->query("SHOW COLUMNS FROM photos")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE photos ADD COLUMN client TINYINT(1) NOT NULL DEFAULT 1");
  $pdo->exec("CREATE TABLE IF NOT EXISTS comptes_clients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, societe VARCHAR(120) NOT NULL DEFAULT '', email VARCHAR(160) NOT NULL UNIQUE,
    pass_hash VARCHAR(255) NOT NULL, marques VARCHAR(400) NOT NULL DEFAULT '', zones VARCHAR(200) NOT NULL DEFAULT '', droits VARCHAR(100) NOT NULL DEFAULT 'ca,photos,cr,telecharger',
    noms VARCHAR(10) NOT NULL DEFAULT 'complet', actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, derniere_connexion DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // Périmètre précis : chaque marque avec ses zones.
  if (!in_array('perimetre', $pdo->query("SHOW COLUMNS FROM comptes_clients")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE comptes_clients ADD COLUMN perimetre TEXT NULL");
}
/** v19 : circuit des points de vente et des listes. */
/** v20 : les testeurs internes ajoutés comme intervenants pour les essais (retrouvés par leur e-mail à la première connexion). */
/** v22 : matricule unique et définitif par personne, pièces d'identité chiffrées, n° de sécurité sociale chiffré. */
function migrate_v22(PDO $pdo): void {
  $c = $pdo->query("SHOW COLUMNS FROM animatrices")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('matricule', $c, true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN matricule VARCHAR(12) NULL UNIQUE");
  matricules();
  $pdo->exec("CREATE TABLE IF NOT EXISTS pieces_identite (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, type VARCHAR(12) NOT NULL, fichier VARCHAR(80) NOT NULL,
    nom VARCHAR(200) NOT NULL DEFAULT '', mime VARCHAR(60) NOT NULL DEFAULT '', taille INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, INDEX (animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // N° de sécurité sociale déjà saisis : chiffrés.
  foreach ($pdo->query("SELECT animatrice_id, data FROM fiches_animatrices WHERE data LIKE '%\"secu\"%'")->fetchAll() as $f) {
    $d = json_decode((string)$f['data'], true) ?: []; if (($d['secu'] ?? '') !== '' && !str_starts_with((string)$d['secu'], 'enc:'))
      $pdo->prepare("UPDATE fiches_animatrices SET data = ? WHERE animatrice_id = ?")->execute([fiche_data_json($d), $f['animatrice_id']]);
  }
}
/** v25 : documents de la personne (administratifs, salariaux) et documents de l'agence (règlements), avec journal des consultations. */
function migrate_v25(PDO $pdo): void {
  $c = $pdo->query("SHOW COLUMNS FROM pieces_identite")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('par', $c, true)) $pdo->exec("ALTER TABLE pieces_identite ADD COLUMN par VARCHAR(120) NOT NULL DEFAULT '', ADD COLUMN titre VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN periode VARCHAR(7) NULL, ADD COLUMN vu_le DATETIME NULL");
  $pdo->exec("CREATE TABLE IF NOT EXISTS docs_agence (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, categorie VARCHAR(12) NOT NULL, titre VARCHAR(160) NOT NULL, fichier VARCHAR(80) NOT NULL,
    nom VARCHAR(200) NOT NULL DEFAULT '', mime VARCHAR(60) NOT NULL DEFAULT '', taille INT NOT NULL DEFAULT 0, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // CV déjà déposés : chiffrés comme les autres documents.
  foreach ($pdo->query("SELECT animatrice_id, cv FROM fiches_animatrices WHERE cv LIKE '%.pdf'")->fetchAll() as $f) {
    $src = fiche_dir() . '/' . basename($f['cv']); if (!is_file($src)) continue; $dst = substr(basename($f['cv']), 0, -4) . '.bin';
    if (file_put_contents(fiche_dir() . '/' . $dst, amy_chiffre((string)file_get_contents($src))) !== false) { $pdo->prepare("UPDATE fiches_animatrices SET cv = ? WHERE animatrice_id = ?")->execute([$dst, $f['animatrice_id']]); @unlink($src); }
  }
  $pdo->exec("CREATE TABLE IF NOT EXISTS docs_acces (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, doc VARCHAR(20) NOT NULL, qui VARCHAR(160) NOT NULL, ip VARCHAR(60) NOT NULL DEFAULT '', le DATETIME NOT NULL, INDEX (doc))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Matricule (préfixe-0001…) attribué une fois pour toutes, à la validation du profil par l'administrateur (jamais aux candidatures en attente ou refusées), jamais réutilisé. */
function matricules(): void {
  $pdo = db(); $max = 0; foreach ($pdo->query("SELECT matricule FROM animatrices WHERE matricule IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $m) $max = max($max, (int)preg_replace('/\D/', '', (string)$m));
  $max = max($max, (int)reglage('matricule_dernier', '0'));
  foreach ($pdo->query("SELECT id FROM animatrices WHERE matricule IS NULL AND statut NOT IN ('attente', 'refuse') ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $id) { $max++; $pdo->prepare("UPDATE animatrices SET matricule = ? WHERE id = ?")->execute([sprintf(AG_MATRICULE . '-%04d', $max), $id]); }
  $pdo->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('matricule_dernier', ?)")->execute([(string)$max]);
}
function migrate_v20(PDO $pdo): void {
  foreach ([] as [$p, $n, $e]) { // (comptes de test : rien à créer pour une nouvelle agence)
    $st = $pdo->prepare("SELECT id FROM animatrices WHERE LOWER(email) = ? OR (prenom = ? AND nom = ?) LIMIT 1"); $st->execute([$e, $p, $n]);
    if (!$st->fetchColumn()) $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, code, actif, created_at) VALUES (?,?,'',?,'France',NULL,1,NULL)")->execute([$p, $n, $e]);
  }
}
function migrate_v19(PDO $pdo): void {
  $col = fn($t) => $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('ba', $col('comptes_clients'), true)) $pdo->exec("ALTER TABLE comptes_clients ADD COLUMN ba VARCHAR(200) NOT NULL DEFAULT ''");
  if (!in_array('circuit', $col('magasins'), true)) $pdo->exec("ALTER TABLE magasins ADD COLUMN circuit VARCHAR(10) NOT NULL DEFAULT ''");
  if (!in_array('circuit', $col('listes_diffusion'), true)) {
    $pdo->exec("ALTER TABLE listes_diffusion ADD COLUMN circuit VARCHAR(10) NOT NULL DEFAULT ''");
  }
  // Reprise de poste : la nouvelle BA reprend les journées de celle qu'elle remplace (le poste continue, seul le nom change).
  if (!in_array('succede_a', $col('animatrices'), true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN succede_a INT UNSIGNED NULL");
  // Comptes déjà créés avec un nom tiré de l'adresse : on met le prénom et le nom.
  if (in_array('nom', $col('comptes_clients'), true)) { $u = $pdo->prepare("UPDATE comptes_clients SET nom = ? WHERE email = ? AND nom = ?");
    foreach (NOMS_CONTACTS as $e => $n) $u->execute([$n, $e, mb_convert_case(str_replace(['.', '_', '-'], ' ', explode('@', $e)[0]), MB_CASE_TITLE)]); }
  // Rapports enregistrés par les clients (une sélection nommée, qu'ils rouvrent ou suppriment).
  $pdo->exec("CREATE TABLE IF NOT EXISTS rapports_clients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, compte_id INT UNSIGNED NOT NULL, nom VARCHAR(120) NOT NULL, params TEXT NOT NULL,
    created_at DATETIME NOT NULL, INDEX (compte_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // Reportings BA : visibles par les clients jusqu'à la date validée par l'agence (au départ, la veille du lundi de cette semaine).
  $st = $pdo->prepare("INSERT IGNORE INTO reglages (cle, valeur) VALUES (?, ?)");
  foreach (array_keys(BA_MODELES) as $k) $st->execute(['ba_valide_' . $k, date('Y-m-d', strtotime('monday this week -1 day'))]);
}
/** v17 : objectifs fixés par l'agence (animations) et verrou des objectifs BA saisis par l'agence. */
function migrate_v17(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS objectifs_anim (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NULL, marque_id INT UNSIGNED NOT NULL, magasin_id INT UNSIGNED NULL,
    date_debut DATE NOT NULL, date_fin DATE NOT NULL, montant DECIMAL(12,2) NULL, sous TEXT NULL, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
    INDEX (marque_id, date_debut, date_fin), INDEX (animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!in_array('par_admin', $pdo->query("SHOW COLUMNS FROM objectifs_ba")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE objectifs_ba ADD COLUMN par_admin TINYINT(1) NOT NULL DEFAULT 0");
  // Comptes rendus repris d'un Excel de marque (source = 'import') : jamais confondus avec ceux saisis dans l'appli.
  if (!in_array('source', $pdo->query("SHOW COLUMNS FROM rapports")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE rapports ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT ''");
}
/**
 * Objectif fixé par l'agence pour une animation (intervenant(e), marque, date, point de vente). La règle la plus précise l'emporte :
 * l'intervenant(e) nommée avant « toutes », le point de vente précis avant « tous ». Renvoie null s'il n'y en a pas.
 */
function objectif_fixe(int $aid, int $marque, string $date, ?int $mid): ?array {
  $st = db()->prepare("SELECT montant, sous FROM objectifs_anim WHERE marque_id = ? AND ? BETWEEN date_debut AND date_fin AND (animatrice_id IS NULL OR animatrice_id = ?) AND (magasin_id IS NULL OR magasin_id = ?)
    ORDER BY (animatrice_id IS NOT NULL) DESC, (magasin_id IS NOT NULL) DESC, id DESC LIMIT 1");
  $st->execute([$marque, $date, $aid, $mid ?: 0]); $r = $st->fetch();
  if (!$r) return null;
  return ['montant' => $r['montant'] === null ? null : (float)$r['montant'], 'sub' => (object)(json_decode((string)$r['sous'], true) ?: [])];
}
/** v16 : listes des clients complètes, marques rattachées par leur nom (sans tenir compte des accents ni de l'apostrophe). */
function migrate_v16(PDO $pdo): void {
  $norm = fn($x) => preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', str_replace(['’', '‘'], "'", (string)$x)) ?: ''));
  $mq = []; foreach ($pdo->query("SELECT id, nom FROM marques")->fetchAll() as $m) $mq[$norm($m['nom'])] = (string)$m['id'];
  $have = []; foreach ($pdo->query("SELECT id, nom, marques FROM listes_diffusion")->fetchAll() as $l) $have[$norm($l['nom'])] = $l;
  $ins = $pdo->prepare("INSERT INTO listes_diffusion (nom, emails, marques, zones, modeles, ordre) VALUES (?,?,?,?,?,?)");
  $upd = $pdo->prepare("UPDATE listes_diffusion SET marques = ? WHERE id = ?");
  foreach (LISTES_DEPART as $i => [$nom, $emails, $marques, $zones, $modeles]) {
    $ids = implode(',', array_filter(array_map(fn($n) => $mq[$norm($n)] ?? '', $marques)));
    $l = $have[$norm($nom)] ?? null;
    if (!$l) $ins->execute([$nom, implode(', ', $emails), $ids, $zones, $modeles, $i + 1]);
    elseif (trim($l['marques']) === '' && $ids !== '') $upd->execute([$ids, $l['id']]);
  }
}
/** v14 : listes de destinataires, comptes administrateurs. */
function migrate_v14(PDO $pdo): void {
  $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $pdo->exec("CREATE TABLE IF NOT EXISTS rapports_ba (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(32) NULL UNIQUE, animatrice_id INT UNSIGNED NOT NULL, modele VARCHAR(30) NOT NULL,
    date_animation DATE NOT NULL, statut VARCHAR(20) NOT NULL DEFAULT 'Travaillé', magasin VARCHAR(120) NOT NULL DEFAULT '', ville VARCHAR(80) NOT NULL DEFAULT '', data MEDIUMTEXT NULL,
    total DECIMAL(12,2) NULL, created_at DATETIME NOT NULL, modifie_le DATETIME NULL, modifie_par VARCHAR(120) NOT NULL DEFAULT '', INDEX (animatrice_id, date_animation), INDEX (modele, date_animation)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS brouillons_ba (animatrice_id INT UNSIGNED PRIMARY KEY, data MEDIUMTEXT NOT NULL, updated_at BIGINT NOT NULL) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS objectifs_ba (animatrice_id INT UNSIGNED NOT NULL, mois CHAR(7) NOT NULL, enseigne VARCHAR(60) NOT NULL, categorie VARCHAR(10) NOT NULL, montant DECIMAL(12,2) NULL,
    PRIMARY KEY (animatrice_id, mois, enseigne, categorie)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS listes_diffusion (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, emails TEXT NOT NULL, marques VARCHAR(400) NOT NULL DEFAULT '',
    zones VARCHAR(200) NOT NULL DEFAULT '', modeles VARCHAR(200) NOT NULL DEFAULT '', ordre INT NOT NULL DEFAULT 100) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS admins (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, email VARCHAR(160) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL,
    droits VARCHAR(100) NOT NULL DEFAULT '', actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, derniere_connexion DATETIME NULL) $E");
  $col = fn($t) => $pdo->query("SHOW COLUMNS FROM $t")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('modele', $col('animatrices'), true)) $pdo->exec("ALTER TABLE animatrices ADD COLUMN modele VARCHAR(30) NOT NULL DEFAULT ''");
  if (!in_array('ba_id', $col('photos'), true)) $pdo->exec("ALTER TABLE photos ADD COLUMN ba_id INT UNSIGNED NULL, ADD INDEX (ba_id)");
  if (!in_array('par', $col('envois'), true)) $pdo->exec("ALTER TABLE envois MODIFY destinataire TEXT NOT NULL, ADD COLUMN par VARCHAR(120) NOT NULL DEFAULT ''");
  if (!(int)$pdo->query("SELECT COUNT(*) FROM listes_diffusion")->fetchColumn()) {
    $id = function (string $nom) use ($pdo) { $st = $pdo->prepare("SELECT id FROM marques WHERE nom = ?"); $st->execute([$nom]); return (string)($st->fetchColumn() ?: ''); };
    $ins = $pdo->prepare("INSERT INTO listes_diffusion (nom, emails, marques, zones, modeles, ordre) VALUES (?,?,?,?,?,?)");
    foreach (LISTES_DEPART as $i => [$nom, $emails, $marques, $zones, $modeles])
      $ins->execute([$nom, implode(', ', $emails), implode(',', array_filter(array_map($id, $marques))), $zones, $modeles, $i + 1]);
  }
}
function reglage(string $cle, string $defaut = ''): string {
  $st = db()->prepare("SELECT valeur FROM reglages WHERE cle = ?"); $st->execute([$cle]);
  $v = $st->fetchColumn(); return $v === false ? $defaut : (string)$v;
}
function reglage_set(string $cle, string $v): void { db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES (?, ?)")->execute([$cle, $v]); }
/** Montant saisi (« 1 250,50 ») ; null si vide. */
function montant($v): ?float {
  $v = trim(str_replace([' ', "\u{00A0}", '€'], '', (string)$v)); if ($v === '') return null;
  $v = str_replace(',', '.', $v); if (!is_numeric($v)) throw new RuntimeException('Montant invalide : ' . $v);
  $f = round((float)$v, 2); if ($f < 0 || $f > 10000000) throw new RuntimeException('Montant invalide : ' . $v); return $f;
}
/** CA ventilé par sous-marque (client à plusieurs marques). Renvoie null si le client n'est pas ventilé. */
function ventiler(string $sous, array $l): ?array {
  $noms = array_values(array_filter(array_map('trim', explode(',', $sous)))); if (!$noms) return null;
  $parts = []; $ca = null; $obj = null;
  foreach ($noms as $n) {
    $x = $l['sub'][$n] ?? [];
    $c = montant($x['ca'] ?? ''); $o = montant($x['objectif'] ?? '');
    $parts[] = ['nom' => $n, 'ca' => $c, 'objectif' => $o];
    if ($c !== null) $ca = ($ca ?? 0) + $c; if ($o !== null) $obj = ($obj ?? 0) + $o;
  }
  // L'objectif global n'a de sens que si chaque marque en a un ; sinon on garde les objectifs par marque.
  if (array_filter($parts, fn($p) => $p['objectif'] === null)) $obj = null;
  return ['ca' => $ca, 'objectif' => $obj, 'json' => json_encode($parts, JSON_UNESCAPED_UNICODE), 'parts' => $parts];
}
/** Dossier des supports de formation : jamais servi directement, uniquement via formation.php (connexion obligatoire). */
function formation_dir(): string {
  $d = upload_dir() . '/formations';
  if (!is_dir($d)) @mkdir($d, 0755, true);
  if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
  return $d;
}
const FORMATION_TYPES = ['pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
  'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4',
  'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'zip' => 'application/zip'];
/** L'intervenant(e) connectée peut-elle voir ce support ? (supports ouverts à toutes, ou accès donné nominativement) */
function formation_visible(array $f, ?int $aid): bool {
  if (!(int)($f['restreint'] ?? 0)) return true;
  if (!$aid) return false;
  $st = db()->prepare("SELECT 1 FROM formation_acces WHERE formation_id = ? AND animatrice_id = ?"); $st->execute([(int)$f['id'], $aid]);
  return (bool)$st->fetchColumn();
}
// ---------- Fiche intervenant(e) ----------
const FICHE_STATUTS = ['Salarié(e)', 'Étudiant(e)', 'Auto-entrepreneur(se)', 'Intermittent(e)', 'Autre'];
const FICHE_AE = 'Auto-entrepreneur(se)';
const FICHE_JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const FICHE_LANGUES = ['Français', 'Anglais', 'Espagnol', 'Italien', 'Allemand', 'Portugais', 'Néerlandais', 'Arabe', 'Russe', 'Chinois', 'Japonais', 'Langue des signes'];
const FICHE_COMPETENCES = ['Vente et conseil', 'Démonstration', 'Dégustation', 'Grande distribution', 'Beauté et cosmétique', 'Pharmacie et santé', 'Mode et textile', 'High-tech et électroménager', 'Vins et spiritueux', 'Automobile', 'Merchandising', 'Animation micro', 'Formation'];
// Métiers souhaités (pôles) et expériences d'accueil et d'hôtessariat.
const FICHE_METIERS = ['Animation commerciale', 'Accueil & hôtessariat'];
const FICHE_ACCUEIL = ['Salons et foires', 'Congrès et séminaires', 'Soirées, galas et VIP', 'Accueil en entreprise', 'Événements sportifs', 'Concerts et festivals', 'Vestiaire', 'Billetterie et contrôle d\'accès', 'Street marketing et sampling', 'Défilés et showrooms', 'Guide et visites', 'Standard téléphonique'];
const FICHE_CERTIFS = ['Secourisme (PSC1, SST)', 'Sécurité incendie (SSIAP)', 'BAFA', 'Carte professionnelle de sécurité'];
const FICHE_ETUDES = ['CAP, BEP', 'Bac', 'Bac +2', 'Bac +3', 'Bac +5 et plus'];
const STATUTS_ANIM = ['actif' => 'Actif', 'sans_offres' => 'Actif sans envoi e-mail', 'standby' => 'Stand-by', 'refuse' => 'Refusé(e)', 'attente' => 'À valider (nouvelle inscription)'];
// Documents déposés par la personne (administratifs, nécessaires à l'embauche)
const PIECES_TYPES = ['cni' => 'Carte d\'identité', 'passeport' => 'Passeport', 'titre' => 'Titre de séjour / autorisation de travail', 'vitale' => 'Carte Vitale / attestation de sécurité sociale',
  'rib' => 'IBAN / RIB', 'photo' => 'Photo d\'identité (badge)', 'autre' => 'Document supplémentaire'];
// Documents salariaux, déposés par l'agence pour la personne
const DOCS_SAL = ['bulletin' => 'Bulletin de salaire', 'ft' => 'Attestation France Travail', 'sal_autre' => 'Autre document salarial'];
const DOCS_TYPES = PIECES_TYPES + DOCS_SAL;
// Règlements et conditions, communs à tous
const DOCS_AGENCE = ['charte' => 'Charte ' . AG_COURT, 'dress' => 'Dress code', 'reglement' => 'Règlement intérieur', 'mutuelle' => 'Mutuelle et prévoyance', 'autre' => 'Autre'];
const DOCS_MIMES = ['image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp', 'application/pdf'];
/** Fichier reçu : type réel vérifié (pas l'extension), 10 Mo maximum, chiffré puis enregistré sous un nom aléatoire hors du web. */
function doc_recu(string $champ = 'fichier', ?array $f = null): array {
  $f ??= $_FILES[$champ] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) fail('Le document n\'a pas été reçu (10 Mo maximum).');
  $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
  if (!in_array($mime, DOCS_MIMES, true) || $f['size'] > 10485760) fail('Photo ou PDF de 10 Mo maximum.');
  $name = bin2hex(random_bytes(16)) . '.bin';
  if (file_put_contents(fiche_dir() . '/' . $name, amy_chiffre((string)file_get_contents($f['tmp_name']))) === false) fail('Enregistrement impossible.');
  return ['fichier' => $name, 'nom' => clip(preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', basename((string)$f['name'])), 200), 'mime' => $mime, 'taille' => (int)$f['size']];
}
/** Limite le nombre d'essais par adresse internet (connexion, codes) : effacer ses cookies ne remet pas le compteur à zéro. */
function limite(string $quoi, int $max, int $fenetre, ?string $cle = null): void {
  $pdo = db(); $pdo->exec("CREATE TABLE IF NOT EXISTS limites (k CHAR(40) PRIMARY KEY, n INT NOT NULL, debut INT NOT NULL) ENGINE=InnoDB");
  $k = sha1($quoi . '|' . ($cle ?? ($_SERVER['REMOTE_ADDR'] ?? ''))); $t = time();
  $st = $pdo->prepare("SELECT n, debut FROM limites WHERE k = ?"); $st->execute([$k]); $r = $st->fetch();
  if (!$r || $t - (int)$r['debut'] > $fenetre) { $pdo->prepare("REPLACE INTO limites (k, n, debut) VALUES (?,?,?)")->execute([$k, 1, $t]); if (random_int(1, 50) === 1) $pdo->prepare("DELETE FROM limites WHERE debut < ?")->execute([$t - 86400]); return; }
  if ((int)$r['n'] >= $max) { sleep(2); fail('Trop d\'essais depuis cette connexion. Réessayez dans ' . max(1, (int)ceil(($fenetre - ($t - (int)$r['debut'])) / 60)) . ' min.', 429); }
  $pdo->prepare("UPDATE limites SET n = n + 1 WHERE k = ?")->execute([$k]);
}
function email_masque(string $e): string { [$u, $d] = array_pad(explode('@', $e, 2), 2, ''); return mb_substr($u, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($u) - 1))) . '@' . $d; }
function doc_journal(string $doc, string $qui): void { try { db()->prepare("INSERT INTO docs_acces (doc, qui, ip, le) VALUES (?,?,?,?)")->execute([$doc, mb_substr($qui, 0, 160), (string)($_SERVER['REMOTE_ADDR'] ?? ''), now()]); } catch (Throwable $e) {} journal('document', $doc, $qui); }
/** Contrôle d'un mot de passe choisi : 12 caractères au moins, ni courant, ni répétitif, ni construit sur le nom de l'agence. Renvoie le message d'erreur, ou null. */
function mdp_probleme(string $pw, string $email = ''): ?string {
  if (mb_strlen($pw) < 12) return 'Le mot de passe doit faire au moins 12 caractères.';
  $b = mb_strtolower(preg_replace('/[\s_.\-!?*@#$%&0-9]+/u', '', $pw));
  if (count(array_unique(mb_str_split(mb_strtolower($pw)))) < 5) return 'Mot de passe trop répétitif : variez les caractères.';
  foreach (['motdepasse', 'password', 'azerty', 'qwerty', 'abcdef', 'soleil', 'bonjour', 'amaya', 'admin', 'secret', 'iloveyou', 'loveyou', 'jetaime', 'doudou', 'chouchou', 'marseille', 'paris', 'football', 'princesse', 'nicolas', 'camille', 'qsdfgh', 'wxcvbn', 'aaaaaa', 'changeme', 'welcome', 'bienvenue', 'parfum', 'animation', 'hotesse'] as $c)
    if ($b === $c || ($b !== '' && mb_strlen($b) <= mb_strlen($c) + 2 && str_contains($b, $c))) return 'Mot de passe trop courant : choisissez une phrase ou une suite de mots à vous.';
  if (preg_match('/^(0123456789|1234567890|123456789012|azertyuiop|qwertyuiop)/', mb_strtolower($pw))) return 'Mot de passe trop courant : choisissez une phrase ou une suite de mots à vous.';
  $ag = mb_strtolower(preg_replace('/[^\p{L}]+/u', '', AG_COURT)); if (mb_strlen($ag) >= 4 && $b !== '' && mb_strlen($b) <= mb_strlen($ag) + 3 && str_contains($b, $ag)) return 'Mot de passe trop proche du nom de l\'agence.';
  $u = mb_strtolower(strtok($email, '@') ?: ''); if (mb_strlen($u) >= 4 && str_contains(mb_strtolower($pw), $u)) return 'Le mot de passe ne doit pas reprendre votre adresse e-mail.';
  return null;
}
/** Message montré pour une erreur inattendue : le texte rédigé pour l'utilisateur (RuntimeException de l'appli), sinon une référence ; le détail va au journal d'erreurs. */
function erreur_publique(Throwable $e, string $ctx = ''): string {
  $ref = strtoupper(bin2hex(random_bytes(3))); error_log('Amaya [' . $ref . '] ' . $ctx . ' ' . get_class($e) . ' : ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
  return $e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'Erreur interne. Si elle se répète, transmettez la référence ' . $ref . ' à l\'éditeur.';
}
/** Texte envoyé au service d'IA (facultatif) : noms des intervenant(e)s, e-mails et téléphones retirés avant l'envoi. */
function ia_anonymiser(string $t, array $noms): string {
  $t = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '[e-mail]', $t);
  $t = preg_replace('/(?<!\d)(?:\+?\d[\s.-]?){9,14}\d(?!\d)/u', '[téléphone]', $t);
  $mots = []; foreach ($noms as $n) foreach (preg_split('/[\s\-]+/u', (string)$n) as $m) if (mb_strlen($m) >= 3) $mots[mb_strtolower($m)] = $m;
  uksort($mots, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
  foreach ($mots as $m) $t = preg_replace('/(?<![\p{L}])' . preg_quote($m, '/') . '(?![\p{L}])/iu', '[intervenant(e)]', $t);
  return $t;
}
// ---------- Journal des actions sensibles (Maintenance › Journal) : qui, quoi, quand, depuis quelle adresse. Conservé 12 mois. ----------
function journal_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  db()->exec("CREATE TABLE IF NOT EXISTS journal (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, le DATETIME NOT NULL, qui VARCHAR(160) NOT NULL, role VARCHAR(12) NOT NULL, action VARCHAR(40) NOT NULL,
    objet VARCHAR(255) NOT NULL DEFAULT '', ip VARCHAR(60) NOT NULL DEFAULT '', INDEX (le), INDEX (action)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Auteur de la requête en cours, d'après la session (sans requête en base). */
function journal_auteur(): array {
  if (!empty($_SESSION['admin'])) return [(string)($_SESSION['admin_email'] ?? $_SESSION['admin_nom'] ?? 'administration'), 'direction'];
  if (!empty($_SESSION['client_id'])) return ['compte marque n° ' . (int)$_SESSION['client_id'], 'marque'];
  if (!empty($_SESSION['anim'])) return ['intervenant(e) n° ' . (int)$_SESSION['anim'], 'intervenant'];
  return ['(non connecté)', 'anonyme'];
}
function journal(string $action, string $objet = '', ?string $qui = null, ?string $role = null): void {
  if (PHP_SAPI === 'cli' && !defined('JOURNAL_CLI')) return;
  try { journal_schema(); [$q, $r] = journal_auteur();
    db()->prepare("INSERT INTO journal (le, qui, role, action, objet, ip) VALUES (?,?,?,?,?,?)")->execute([now(), mb_substr($qui ?? $q, 0, 160), $role ?? $r, mb_substr($action, 0, 40), mb_substr($objet, 0, 255), (string)($_SERVER['REMOTE_ADDR'] ?? '')]);
  } catch (Throwable $e) { error_log(AG_COURT . ' journal : ' . $e->getMessage()); }
}
/** Tout fichier remis au navigateur (PDF, Excel, CSV, ZIP, SEPA, sauvegarde, CV, pièce) est noté, avec le script et l'action d'origine. */
function journal_telechargement(): void {
  $nom = ''; foreach (headers_list() as $h) if (stripos($h, 'Content-Disposition:') === 0 && preg_match('/filename="([^"]+)"/', $h, $m)) $nom = $m[1];
  if ($nom === '' || http_response_code() >= 400) return;
  $src = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')); if (in_array($src, ['photo.php', 'formation.php'], true)) return;
  journal('téléchargement', $nom . ' (' . $src . (isset($_GET['action']) ? ' › ' . preg_replace('/[^a-z0-9_]/', '', (string)$_GET['action']) : '') . ')');
}

const NATIONALITES = ['Française', 'Algérienne', 'Allemande', 'Américaine', 'Belge', 'Brésilienne', 'Britannique', 'Camerounaise', 'Canadienne', 'Chinoise', 'Comorienne', 'Congolaise', 'Cubaine', 'Dominicaine',
  'Espagnole', 'Haïtienne', 'Indienne', 'Italienne', 'Ivoirienne', 'Japonaise', 'Libanaise', 'Luxembourgeoise', 'Malgache', 'Malienne', 'Marocaine', 'Mauricienne', 'Néerlandaise', 'Polonaise',
  'Portugaise', 'Roumaine', 'Russe', 'Sénégalaise', 'Seychelloise', 'Suisse', 'Tunisienne', 'Turque', 'Ukrainienne', 'Vénézuélienne', 'Vietnamienne', 'Autre'];
const FICHE_TAILLES = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
/** Dossier privé des CV : servi uniquement via fiche_fichier.php. */
function fiche_dir(): string {
  $d = upload_dir() . '/fiches';
  if (!is_dir($d)) @mkdir($d, 0755, true);
  if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
  return $d;
}
/** Nettoie la fiche saisie : seules les rubriques prévues sont gardées. */
function fiche_clean(array $in): array {
  $t = fn($k, $n = 200) => clip($in[$k] ?? '', $n);
  $pick = fn($k, $list) => array_values(array_intersect($list, (array)($in[$k] ?? [])));
  return [
    'adresse' => $t('adresse'), 'code_postal' => $t('code_postal', 10), 'ville' => $t('ville', 80),
    // Pour le contrat de travail
    'civilite' => in_array($in['civilite'] ?? '', ['Mme', 'M.'], true) ? $in['civilite'] : '', 'naissance_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['naissance_date'] ?? '')) ? $in['naissance_date'] : '',
    'naissance_lieu' => $t('naissance_lieu', 120), 'nationalite' => $t('nationalite', 60), 'secu' => preg_replace('/[^0-9AB ]/i', '', $t('secu', 30)),
    'nom_naissance' => mb_strtoupper($t('nom_naissance', 80)), 'iban' => strtoupper(preg_replace('/[^0-9A-Z]/i', '', $t('iban', 50))), 'bic' => strtoupper(preg_replace('/[^0-9A-Z]/i', '', $t('bic', 15))),
    'statut' => in_array($st = (['Salariée' => 'Salarié(e)', 'Auto-entrepreneuse' => FICHE_AE][$in['statut'] ?? ''] ?? ($in['statut'] ?? '')), FICHE_STATUTS, true) ? $st : '', 'siret' => preg_replace('/\D/', '', $t('siret', 20)),
    'permis' => !empty($in['permis']), 'vehicule' => !empty($in['vehicule']), 'mobilite' => $t('mobilite', 300),
    // Mobilité : départements entiers et communes choisies dans la liste officielle (pas de faute de frappe possible).
    'mob_deps' => array_values(array_filter(array_unique((array)($in['mob_deps'] ?? [])), fn($d) => isset(DEPARTEMENTS[$d]))),
    'mob_communes' => array_slice(array_values(array_filter(array_map(fn($c) => is_array($c) && isset(DEPARTEMENTS[(string)($c['dep'] ?? '')]) ? ['nom' => clip($c['nom'] ?? '', 80), 'cp' => preg_replace('/\D/', '', (string)($c['cp'] ?? '')), 'dep' => (string)$c['dep']] : null, (array)($in['mob_communes'] ?? [])))), 0, 30),
    'certifs' => $pick('certifs', FICHE_CERTIFS), 'etudes' => in_array($in['etudes'] ?? '', FICHE_ETUDES, true) ? $in['etudes'] : '', 'stature' => ($s = (int)($in['stature'] ?? 0)) >= 130 && $s <= 220 ? $s : '',
    'jours' => $pick('jours', FICHE_JOURS), 'nocturnes' => !empty($in['nocturnes']),
    'langues' => $pick('langues', FICHE_LANGUES), 'langues_autres' => $t('langues_autres', 120),
    'competences' => $pick('competences', FICHE_COMPETENCES), 'metiers' => $pick('metiers', FICHE_METIERS), 'accueil' => $pick('accueil', FICHE_ACCUEIL),
    'marques' => $t('marques', 600), 'experience' => max(0, min(60, (int)($in['experience'] ?? 0))),
    'taille_haut' => in_array($in['taille_haut'] ?? '', FICHE_TAILLES, true) ? $in['taille_haut'] : '',
    'taille_bas' => in_array($in['taille_bas'] ?? '', FICHE_TAILLES, true) ? $in['taille_bas'] : '',
    'pointure' => preg_match('/^\d{2}(\.5)?$/', (string)($in['pointure'] ?? '')) ? (string)$in['pointure'] : '',
    'presentation' => $t('presentation', 1500),
  ];
}
/** SIRET valide : 14 chiffres et clé de Luhn. */
function siret_ok(string $s): bool { $s = preg_replace('/\D/', '', $s); if (strlen($s) !== 14) return false; $t = 0;
  for ($i = 0; $i < 14; $i++) { $d = (int)$s[$i]; if ($i % 2 === 0) { $d *= 2; if ($d > 9) $d -= 9; } $t += $d; } return $t % 10 === 0 || str_starts_with($s, '356000000'); }
/** Départements où la personne peut travailler : domicile, départements choisis, communes choisies. */
function fiche_deps(array $d): array { $o = []; if ($x = dep_cp((string)($d['code_postal'] ?? ''))) $o[] = $x; foreach ((array)($d['mob_deps'] ?? []) as $x) $o[] = $x; foreach ((array)($d['mob_communes'] ?? []) as $c) $o[] = $c['dep'] ?? ''; return array_values(array_unique(array_filter($o))); }
/** Book : jusqu'à 6 photos en pied ou en tenue, en plus de la photo de profil. */
const FICHE_BOOK_MAX = 6;
function fiche_book(int $aid): array {
  static $ok = false; if (!$ok) { $ok = true; if (!in_array('book', db()->query("SHOW COLUMNS FROM fiches_animatrices")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE fiches_animatrices ADD COLUMN book TEXT NULL"); }
  $st = db()->prepare("SELECT book FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$aid]);
  return array_map(fn($f) => ['f' => $f, 'url' => photo_url($f, true), 'full' => photo_url($f)], array_values(array_filter((array)json_decode((string)$st->fetchColumn(), true), fn($f) => preg_match('/^[a-f0-9]{32}$/', (string)$f))));
}
function fiche_get(int $aid): array {
  $st = db()->prepare("SELECT * FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$aid]); $f = $st->fetch() ?: [];
  $d = json_decode((string)($f['data'] ?? ''), true) ?: []; foreach (['secu', 'iban'] as $k) if (($d[$k] ?? '') !== '') $d[$k] = secu_clair((string)$d[$k]);
  return ['data' => (object)$d, 'photo' => ($f['photo'] ?? '') ? photo_url($f['photo'], true) : '', 'book' => fiche_book($aid),
    'cv' => ($f['cv'] ?? '') !== '', 'cv_nom' => $f['cv_nom'] ?? '', 'notes_admin' => $f['notes_admin'] ?? '', 'updated_at' => $f['updated_at'] ?? null];
}
// ---------- Chiffrement des données sensibles (n° de sécurité sociale, pièces d'identité) ----------
/** Clés AES-256, la clé en service d'abord :
 *  - AMAYA_CLE_FICHIER, ou LPI_CLE_FICHIER (recommandé) : chemin d'un fichier HORS du dossier web (ex. /home/compte/amaya-cle.txt) contenant la clé en base64 ;
 *  - sinon AMAYA_CLE (ou LPI_CLE, nom d'origine) dans config.php ;
 *  - AMAYA_CLES_ANCIENNES, ou LPI_CLES_ANCIENNES (facultatif) : anciennes clés, encore lues après un changement de clé, le temps du rechiffrement (Maintenance).
 *  L'ancien repli (fichier uploads/fiches/.cle) n'est plus jamais créé ; s'il existe déjà, il reste lu pour ne rien perdre. */
function amy_cles(): array {
  static $K = null; if ($K !== null) return $K; $K = []; $b = fn($v) => strlen((string)base64_decode(trim((string)$v), true)) === 32 ? base64_decode(trim((string)$v), true) : null;
  if (defined('LPI_CLE_FICHIER') && LPI_CLE_FICHIER !== '') { $k = is_readable(LPI_CLE_FICHIER) ? $b(strtok((string)file_get_contents(LPI_CLE_FICHIER), "\r\n")) : null;
    if ($k === null) throw new RuntimeException('Clé de chiffrement illisible : vérifiez le fichier indiqué par AMAYA_CLE_FICHIER (ou LPI_CLE_FICHIER) dans config.php.'); $K[] = $k; }
  if (defined('LPI_CLE') && ($k = $b(LPI_CLE)) && !in_array($k, $K, true)) $K[] = $k;
  if (defined('LPI_CLES_ANCIENNES')) foreach ((array)LPI_CLES_ANCIENNES as $v) if (($k = $b($v)) && !in_array($k, $K, true)) $K[] = $k;
  $f = upload_dir() . '/fiches/.cle'; if (is_file($f) && ($k = $b(file_get_contents($f))) && !in_array($k, $K, true)) $K[] = $k;
  if (!$K) throw new RuntimeException('Clé de chiffrement absente : ajoutez AMAYA_CLE (ou AMAYA_CLE_FICHIER) dans config.php.');
  return $K;
}
function amy_cle(): string { return amy_cles()[0]; }
/** Graine stable de l'installation (nom du cookie de session, adresse de surveillance, dossier de copie externe) : ne change pas quand la clé change. */
function instance_graine(): string { return defined('AMAYA_GRAINE') ? (string)AMAYA_GRAINE : (defined('LPI_CLE') ? (string)LPI_CLE : __DIR__); }
function amy_chiffre(string $s): string { $iv = random_bytes(12); $ct = openssl_encrypt($s, 'aes-256-gcm', amy_cle(), OPENSSL_RAW_DATA, $iv, $tag); return $iv . $tag . $ct; }
/** Déchiffre avec la clé en service, puis avec les anciennes ; $rang dit laquelle a servi (0 = en service). */
function amy_dechiffre(string $b, ?int &$rang = null): ?string {
  $rang = null; if (strlen($b) < 29) return null;
  foreach (amy_cles() as $i => $k) { $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', $k, OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16)); if ($r !== false) { $rang = $i; return $r; } }
  return null;
}
/** Données de fiche prêtes à enregistrer : n° de sécurité sociale et IBAN chiffrés. */
function iban_masque(string $v): string { $v = preg_replace('/\s/', '', $v); return strlen($v) < 8 ? ($v ? '•••' : '') : substr($v, 0, 4) . ' •••• •••• ' . substr($v, -4); }
function fiche_data_json(array $d): string {
  foreach (['secu', 'iban'] as $k) if (($d[$k] ?? '') !== '' && !str_starts_with((string)$d[$k], 'enc:')) $d[$k] = 'enc:' . base64_encode(amy_chiffre((string)$d[$k])); // n° SS et IBAN chiffrés
  return json_encode($d, JSON_UNESCAPED_UNICODE);
}
function secu_clair(string $v): string { return str_starts_with($v, 'enc:') ? (string)amy_dechiffre((string)base64_decode(substr($v, 4))) : $v; }
function secu_masque(string $v): string { $v = preg_replace('/\s/', '', $v); return strlen($v) < 6 ? ($v ? '•••' : '') : substr($v, 0, 1) . ' ' . substr($v, 1, 2) . ' •• •• ••• ••• ' . substr($v, -2); }
function est_titulaire(): bool { return admin_ok() && est_dirigeant_email((string)($_SESSION['admin_email'] ?? '')); } // un(e) dirigeant(e), nommément
/** Contrôle d'un n° de sécurité sociale : 13 caractères + clé (Corse 2A/2B comprise). */
function secu_ok(string $v): bool {
  $v = strtoupper(preg_replace('/\s/', '', $v)); if (!preg_match('/^([12378])(\d{2})(\d{2})(\d{2}|2A|2B)(\d{3})(\d{3})(\d{2})$/', $v, $m)) return false;
  $n = str_replace(['2A', '2B'], ['19', '18'], substr($v, 0, 13)); return 97 - (int)bcmod_s($n, 97) === (int)substr($v, 13, 2);
}
function bcmod_s(string $n, int $m): int { $r = 0; foreach (str_split($n) as $c) $r = ($r * 10 + (int)$c) % $m; return $r; }
// ---------- Départements et secteurs (filtres des rapports, vivier, points de vente) ----------
const DEPARTEMENTS = ['01' => 'Ain', '02' => 'Aisne', '03' => 'Allier', '04' => 'Alpes-de-Haute-Provence', '05' => 'Hautes-Alpes', '06' => 'Alpes-Maritimes', '07' => 'Ardèche', '08' => 'Ardennes', '09' => 'Ariège', '10' => 'Aube', '11' => 'Aude', '12' => 'Aveyron', '13' => 'Bouches-du-Rhône', '14' => 'Calvados', '15' => 'Cantal', '16' => 'Charente', '17' => 'Charente-Maritime', '18' => 'Cher', '19' => 'Corrèze', '2A' => 'Corse-du-Sud', '2B' => 'Haute-Corse', '21' => 'Côte-d\'Or', '22' => 'Côtes-d\'Armor', '23' => 'Creuse', '24' => 'Dordogne', '25' => 'Doubs', '26' => 'Drôme', '27' => 'Eure', '28' => 'Eure-et-Loir', '29' => 'Finistère', '30' => 'Gard', '31' => 'Haute-Garonne', '32' => 'Gers', '33' => 'Gironde', '34' => 'Hérault', '35' => 'Ille-et-Vilaine', '36' => 'Indre', '37' => 'Indre-et-Loire', '38' => 'Isère', '39' => 'Jura', '40' => 'Landes', '41' => 'Loir-et-Cher', '42' => 'Loire', '43' => 'Haute-Loire', '44' => 'Loire-Atlantique', '45' => 'Loiret', '46' => 'Lot', '47' => 'Lot-et-Garonne', '48' => 'Lozère', '49' => 'Maine-et-Loire', '50' => 'Manche', '51' => 'Marne', '52' => 'Haute-Marne', '53' => 'Mayenne', '54' => 'Meurthe-et-Moselle', '55' => 'Meuse', '56' => 'Morbihan', '57' => 'Moselle', '58' => 'Nièvre', '59' => 'Nord', '60' => 'Oise', '61' => 'Orne', '62' => 'Pas-de-Calais', '63' => 'Puy-de-Dôme', '64' => 'Pyrénées-Atlantiques', '65' => 'Hautes-Pyrénées', '66' => 'Pyrénées-Orientales', '67' => 'Bas-Rhin', '68' => 'Haut-Rhin', '69' => 'Rhône', '70' => 'Haute-Saône', '71' => 'Saône-et-Loire', '72' => 'Sarthe', '73' => 'Savoie', '74' => 'Haute-Savoie', '75' => 'Paris', '76' => 'Seine-Maritime', '77' => 'Seine-et-Marne', '78' => 'Yvelines', '79' => 'Deux-Sèvres', '80' => 'Somme', '81' => 'Tarn', '82' => 'Tarn-et-Garonne', '83' => 'Var', '84' => 'Vaucluse', '85' => 'Vendée', '86' => 'Vienne', '87' => 'Haute-Vienne', '88' => 'Vosges', '89' => 'Yonne', '90' => 'Territoire de Belfort', '91' => 'Essonne', '92' => 'Hauts-de-Seine', '93' => 'Seine-Saint-Denis', '94' => 'Val-de-Marne', '95' => 'Val-d\'Oise'];
/** Département d'après un code postal trouvé dans le texte (adresse, ville) : '75', '2A'… ou ''. */
function dep_cp(string $s): string {
  if (!preg_match('/\b(\d{5})\b/', $s, $m)) return ''; $c = $m[1]; if (str_starts_with($c, '97') || str_starts_with($c, '98')) return '';
  if (str_starts_with($c, '20')) return (int)$c < 20200 ? '2A' : '2B';
  $d = substr($c, 0, 2); return isset(DEPARTEMENTS[$d]) ? $d : '';
}
function dep_nom(string $d): string { return isset(DEPARTEMENTS[$d]) ? DEPARTEMENTS[$d] . ' (' . $d . ')' : $d; }
/** Colonne « dep » des points de vente, remplie d'après le code postal de l'adresse. */
function dep_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  if (!in_array('dep', db()->query("SHOW COLUMNS FROM magasins")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE magasins ADD COLUMN dep VARCHAR(3) NOT NULL DEFAULT ''");
  dep_remplir();
}
function dep_remplir(): void {
  $up = db()->prepare("UPDATE magasins SET dep = ? WHERE id = ?");
  foreach (db()->query("SELECT id, adresse, ville FROM magasins WHERE dep = ''")->fetchAll() as $m) { $d = dep_cp($m['adresse'] . ' ' . $m['ville']); if ($d !== '') $up->execute([$d, $m['id']]); }
}
/** Secteurs définis par l'agence : [['nom' => 'Hauts-de-France', 'deps' => ['59','62','80']], …] */
function secteurs(): array {
  try { db()->exec("CREATE TABLE IF NOT EXISTS g_reglages (cle VARCHAR(40) PRIMARY KEY, valeur MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $v = (string)db()->query("SELECT valeur FROM g_reglages WHERE cle = 'secteurs'")->fetchColumn(); } catch (Throwable $e) { return []; }
  $o = []; foreach ((array)json_decode($v, true) as $x) { $n = trim((string)($x['nom'] ?? '')); $d = array_values(array_filter((array)($x['deps'] ?? []), fn($k) => isset(DEPARTEMENTS[$k]))); if ($n !== '' && $d) $o[] = ['nom' => $n, 'deps' => $d]; }
  return $o;
}
/** Filtre géographique « d:75 » (département) ou « s:Nom » (secteur) : condition SQL sur la colonne du point de vente. Vide = toute la France. */
function secteur_sql(string $z, array &$a, string $col = 'r.magasin_id'): string {
  $deps = secteur_deps($z); if ($deps === null) return '';
  if (!$deps) return '1 = 0'; dep_schema();
  array_push($a, ...$deps); return $col . ' IN (SELECT id FROM magasins WHERE dep IN (' . implode(',', array_fill(0, count($deps), '?')) . '))';
}
function secteur_deps(string $z): ?array {
  if (str_starts_with($z, 'd:')) { $d = substr($z, 2); return isset(DEPARTEMENTS[$d]) ? [$d] : []; }
  if (str_starts_with($z, 's:')) { foreach (secteurs() as $x) if ($x['nom'] === substr($z, 2)) return $x['deps']; return []; }
  return null;
}
function secteur_label(string $z): string {
  if (str_starts_with($z, 'd:')) return dep_nom(substr($z, 2));
  if (str_starts_with($z, 's:')) return 'Secteur ' . substr($z, 2);
  return 'Toute la France';
}
function zone_cp(string $cp): ?string { $cp = preg_replace('/\D/', '', $cp); return strlen($cp) === 5 ? 'France' : null; }
/** Doublons possibles : même nom et prénom (accents et casse ignorés), même e-mail ou même portable. */
function doublons(): array {
  $L = db()->query("SELECT a.id, a.matricule, a.prenom, a.nom, a.zone, a.telephone, a.email, a.created_at, a.actif,
    (SELECT COUNT(*) FROM rapports r WHERE r.animatrice_id = a.id) AS rapports, (SELECT COUNT(*) FROM missions m WHERE m.animatrice_id = a.id) AS missions
    FROM animatrices a WHERE a.prenom <> 'Animatrice' AND a.nom NOT LIKE '%(fusionné avec%' ORDER BY a.nom, a.prenom")->fetchAll();
  $n = fn($s) => preg_replace('/[^a-z]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$s)));
  $par = []; $grp = []; $find = function ($x) use (&$par, &$find) { while (($par[$x] ?? $x) !== $x) $x = $par[$x]; return $x; };
  $lien = function ($a, $b) use (&$par, $find) { $par[$find($a)] = $find($b); };
  $cles = [];
  foreach ($L as $a) { $id = (int)$a['id']; $par[$id] = $par[$id] ?? $id;
    foreach (['n:' . $n($a['nom']) . '|' . $n($a['prenom']), $a['email'] ? 'e:' . strtolower($a['email']) : null, strlen($a['telephone']) >= 9 ? 't:' . substr(preg_replace('/\D/', '', $a['telephone']), -9) : null] as $k)
      if ($k) { if (isset($cles[$k])) $lien($id, $cles[$k]); else $cles[$k] = $id; } }
  foreach ($L as $a) $grp[$find((int)$a['id'])][] = $a;
  return array_values(array_filter($grp, fn($g) => count($g) > 1));
}
/** Fusionne des fiches en double dans celle qu'on garde : rapports, planning, contrats, relevés, pièces… tout suit. */
function fusionner(int $garder, array $absorber): int {
  $pdo = db(); $t = $pdo->query("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'animatrice_id'")->fetchAll(PDO::FETCH_COLUMN); $n = 0;
  $g = $pdo->prepare("SELECT * FROM animatrices WHERE id = ?"); $g->execute([$garder]); $G = $g->fetch(); if (!$G) return 0;
  foreach ($absorber as $x) { $x = (int)$x; if ($x === $garder) continue; $g->execute([$x]); $X = $g->fetch(); if (!$X) continue;
    // Fiche : on complète ce qui est vide chez celle qu'on garde.
    $fg = (array)fiche_get($garder)['data']; $fx = (array)fiche_get($x)['data'];
    foreach ($fx as $k => $v) if (($fg[$k] ?? '') === '' || ($fg[$k] ?? null) === [] || !isset($fg[$k])) $fg[$k] = $v;
    $pdo->prepare("INSERT INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)")->execute([$garder, fiche_data_json($fg), now()]);
    $pdo->prepare("UPDATE fiches_animatrices f JOIN fiches_animatrices o ON o.animatrice_id = ? SET f.photo = IF(f.photo = '', o.photo, f.photo), f.cv = IF(f.cv = '', o.cv, f.cv), f.cv_nom = IF(f.cv_nom = '', o.cv_nom, f.cv_nom) WHERE f.animatrice_id = ?")->execute([$x, $garder]);
    foreach ($t as $tb) if ($tb !== 'fiches_animatrices') $pdo->exec("UPDATE IGNORE `$tb` SET animatrice_id = $garder WHERE animatrice_id = $x");
    $pdo->prepare("DELETE FROM fiches_animatrices WHERE animatrice_id = ?")->execute([$x]);
    $pdo->prepare("UPDATE animatrices SET succede_a = ? WHERE succede_a = ?")->execute([$garder, $x]);
    // Coordonnées manquantes reprises, puis la fiche absorbée est vidée et désactivée (sinon la connexion pourrait tomber dessus).
    $pdo->prepare("UPDATE animatrices SET telephone = IF(telephone = '', ?, telephone), email = IF(email = '', ?, email), created_at = COALESCE(created_at, ?), rgpd_info_at = COALESCE(rgpd_info_at, ?), rgpd_image_at = COALESCE(rgpd_image_at, ?) WHERE id = ?")
      ->execute([$X['telephone'], $X['email'], $X['created_at'], $X['rgpd_info_at'], $X['rgpd_image_at'], $garder]);
    $pdo->prepare("UPDATE animatrices SET telephone = '', email = '', actif = 0, nom = CONCAT(nom, ' (fusionné avec ', ?, ')') WHERE id = ?")->execute([$G['matricule'] ?: '#' . $garder, $x]);
    $n++;
  }
  // Zone recalée sur le code postal de la fiche fusionnée.
  $f = (array)fiche_get($garder)['data']; if ($z = zone_cp((string)($f['code_postal'] ?? ''))) $pdo->prepare("UPDATE animatrices SET zone = ? WHERE id = ?")->execute([$z, $garder]);
  return $n;
}
function pieces_liste(int $aid): array {
  static $ok = false; if (!$ok) { $ok = true; if (!in_array('expire_le', db()->query("SHOW COLUMNS FROM pieces_identite")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE pieces_identite ADD COLUMN expire_le DATE NULL, ADD COLUMN rappel_le DATETIME NULL"); }
  $st = db()->prepare("SELECT id, type, nom, titre, periode, par, vu_le, mime, taille, expire_le, created_at FROM pieces_identite WHERE animatrice_id = ? ORDER BY type, periode DESC, id"); $st->execute([$aid]); return $st->fetchAll(); }
function docs_agence(): array { return db()->query("SELECT id, categorie, titre, nom, mime, taille, created_at FROM docs_agence ORDER BY FIELD(categorie, 'charte', 'reglement', 'dress', 'mutuelle', 'autre'), titre")->fetchAll(); }
function upload_limit(): int {
  $b = function ($v) { $v = trim((string)$v); $n = (float)$v; $u = strtolower(substr($v, -1)); return (int)($u === 'g' ? $n * 1073741824 : ($u === 'm' ? $n * 1048576 : ($u === 'k' ? $n * 1024 : $n))); };
  $a = [$b(ini_get('upload_max_filesize')), $b(ini_get('post_max_size'))]; $a = array_filter($a); return $a ? min($a) : 0;
}
/** Purge RGPD, au plus une fois par jour : comptes rendus et photos au-delà de la durée de conservation, brouillons abandonnés. */
function purge_rgpd(): void {
  $flag = upload_dir() . '/.purge'; if (is_file($flag) && filemtime($flag) > time() - 86400) return;
  @touch($flag);
  $limite = date('Y-m-d', strtotime('-' . CONSERVATION_ANS . ' years'));
  $pdo = db();
  $old = $pdo->prepare("SELECT p.fichier FROM photos p JOIN rapports r ON r.id = p.rapport_id WHERE r.date_animation < ?"); $old->execute([$limite]);
  foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); }
  $pdo->prepare("DELETE p FROM photos p JOIN rapports r ON r.id = p.rapport_id WHERE r.date_animation < ?")->execute([$limite]);
  $pdo->prepare("DELETE FROM rapports WHERE date_animation < ?")->execute([$limite]);
  // Photos de brouillons jamais envoyés depuis plus de 60 jours
  $orph = $pdo->prepare("SELECT fichier FROM photos WHERE rapport_id IS NULL AND created_at < ?"); $orph->execute([date('Y-m-d H:i:s', strtotime('-60 days'))]);
  foreach ($orph->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); }
  $pdo->prepare("DELETE FROM photos WHERE rapport_id IS NULL AND ba_id IS NULL AND created_at < ?")->execute([date('Y-m-d H:i:s', strtotime('-60 days'))]);
  $pdo->prepare("DELETE FROM brouillons WHERE updated_at < ?")->execute([(int)(strtotime('-60 days') * 1000)]);
  // Anciennes journées BA (module désactivé) : même durée de conservation.
  $old = $pdo->prepare("SELECT p.fichier FROM photos p JOIN rapports_ba b ON b.id = p.ba_id WHERE b.date_animation < ?"); $old->execute([$limite]);
  foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); }
  $pdo->prepare("DELETE p FROM photos p JOIN rapports_ba b ON b.id = p.ba_id WHERE b.date_animation < ?")->execute([$limite]);
  $pdo->prepare("DELETE FROM rapports_ba WHERE date_animation < ?")->execute([$limite]);
  $pdo->prepare("DELETE FROM brouillons_ba WHERE updated_at < ?")->execute([(int)(strtotime('-60 days') * 1000)]);
  journal_schema(); $pdo->prepare("DELETE FROM journal WHERE le < ?")->execute([date('Y-m-d H:i:s', strtotime('-12 months'))]);
}
/** Effacement des données d'un(e) intervenant(e) (demande RGPD) : identité et photos supprimées, chiffres des comptes rendus conservés anonymement. */
function effacer_animatrice(int $id): void {
  $pdo = db();
  $st = $pdo->prepare("SELECT p.fichier FROM photos p LEFT JOIN rapports r ON r.id = p.rapport_id WHERE r.animatrice_id = ?"); $st->execute([$id]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); }
  $pdo->prepare("DELETE p FROM photos p JOIN rapports r ON r.id = p.rapport_id WHERE r.animatrice_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM brouillons WHERE animatrice_id = ?")->execute([$id]);
  $st = $pdo->prepare("SELECT p.fichier FROM photos p JOIN rapports_ba b ON b.id = p.ba_id WHERE b.animatrice_id = ?"); $st->execute([$id]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); }
  $pdo->prepare("DELETE p FROM photos p JOIN rapports_ba b ON b.id = p.ba_id WHERE b.animatrice_id = ?")->execute([$id]);
  $pdo->prepare("DELETE FROM brouillons_ba WHERE animatrice_id = ?")->execute([$id]);
  if ($pdo->query("SHOW TABLES LIKE 'fiches_animatrices'")->fetchColumn()) {
    $st = $pdo->prepare("SELECT photo, cv FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$id]);
    if ($f = $st->fetch()) { if ($f['photo']) { @unlink(upload_dir() . "/{$f['photo']}.jpg"); @unlink(upload_dir() . "/{$f['photo']}_t.jpg"); } if ($f['cv']) @unlink(fiche_dir() . '/' . basename($f['cv'])); }
    $pdo->prepare("DELETE FROM fiches_animatrices WHERE animatrice_id = ?")->execute([$id]);
    if ($pdo->query("SHOW TABLES LIKE 'pieces_identite'")->fetchColumn()) { $q = $pdo->prepare("SELECT fichier FROM pieces_identite WHERE animatrice_id = ?"); $q->execute([$id]);
      foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink(fiche_dir() . '/' . basename($f)); $pdo->prepare("DELETE FROM pieces_identite WHERE animatrice_id = ?")->execute([$id]); }
  }
  $pdo->prepare("UPDATE rapports SET staff = '' WHERE animatrice_id = ?")->execute([$id]);
  $pdo->prepare("UPDATE animatrices SET prenom = 'Intervenant(e)', nom = CONCAT('effacée n°', id), telephone = '', email = '', code = NULL, actif = 0, rgpd_info_at = NULL, rgpd_image_at = NULL WHERE id = ?")->execute([$id]);
  if ($pdo->query("SHOW TABLES LIKE 'formation_acces'")->fetchColumn()) $pdo->prepare("DELETE FROM formation_acces WHERE animatrice_id = ?")->execute([$id]);
}
/** Lit la première feuille d'un fichier Excel (.xlsx) : tableau de lignes, chaque ligne étant un tableau de cellules (texte). */
function xlsx_rows(string $path): array {
  if (!class_exists('ZipArchive')) fail('Lecture Excel indisponible sur ce serveur : enregistrez le fichier en CSV.');
  $z = new ZipArchive(); if ($z->open($path) !== true) fail('Fichier Excel illisible.');
  $ss = [];
  if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
    $d = new DOMDocument(); $d->loadXML($x, LIBXML_NONET | LIBXML_COMPACT);
    foreach ($d->getElementsByTagName('si') as $si) { $t = ''; foreach ($si->getElementsByTagName('t') as $tt) $t .= $tt->textContent; $ss[] = $t; }
  }
  $sheet = $z->getFromName('xl/worksheets/sheet1.xml');
  if ($sheet === false) for ($i = 0; $i < $z->numFiles; $i++) { $nm = $z->getNameIndex($i); if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $nm)) { $sheet = $z->getFromName($nm); break; } }
  $z->close(); if ($sheet === false) fail('Aucune feuille trouvée dans le fichier Excel.');
  $d = new DOMDocument(); $d->loadXML($sheet, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
  $rows = [];
  foreach ($d->getElementsByTagName('row') as $row) {
    $r = []; $k = 0;
    foreach ($row->getElementsByTagName('c') as $c) {
      if (preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m)) { $k = 0; foreach (str_split($m[1]) as $ch) $k = $k * 26 + ord($ch) - 64; $k--; }
      $t = $c->getAttribute('t'); $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
      if ($t === 's') $v = $ss[(int)$v] ?? '';
      elseif ($t === 'inlineStr') $v = $c->getElementsByTagName('t')->item(0)?->textContent ?? '';
      elseif (is_numeric($v) && floor((float)$v) == (float)$v && abs((float)$v) < 1e15) $v = sprintf('%.0f', (float)$v);
      $r[$k] = $v; $k++;
    }
    if ($r) { $max = max(array_keys($r)); $rows[] = array_replace(array_fill(0, $max + 1, ''), $r); }
  }
  return $rows;
}

function tel_norm(string $t): string { return substr(preg_replace('/\D/', '', $t), -15); }

/** Cookies limités au dossier de l'appli : plusieurs installations sur le même domaine ne se mélangent pas. */
function cookie_path(): string { $d = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/x'))), '/'); return $d . '/'; }
/** Domaine des cookies : le domaine avec et sans www partagent la même connexion. */
function cookie_domain(): string {
  $h = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
  if ($h === '' || !str_contains($h, '.') || filter_var($h, FILTER_VALIDATE_IP)) return '';
  return '.' . preg_replace('/^www\./', '', $h);
}

// ---------- Photos : accès contrôlé (photo.php) ----------
/** Adresse d'une photo dans l'appli (miniature si $mini). Le fichier n'est plus lisible directement dans uploads/. */
function photo_url(string $f, bool $mini = false): string { return 'photo.php?f=' . rawurlencode($f) . ($mini ? '&t=1' : ''); }
function photo_cle(): string { return hash_hmac('sha256', 'photos-liens', amy_cle(), true); }
/** Lien absolu, signé et daté (30 jours par défaut), pour montrer une photo à quelqu'un qui n'a pas de compte. */
function photo_lien(string $f, int $jours = 30, bool $mini = false): string {
  $e = time() + $jours * 86400; $s = substr(hash_hmac('sha256', $f . '|' . $e, photo_cle()), 0, 32);
  return rtrim(BASE_URL, '/') . '/' . photo_url($f, $mini) . '&e=' . $e . '&s=' . $s;
}
function photo_signature_ok(string $f, int $e, string $s): bool { return $e >= time() && hash_equals(substr(hash_hmac('sha256', $f . '|' . $e, photo_cle()), 0, 32), $s); }
/** Aperçu HTML (comptes rendus) : les chemins de fichiers des photos deviennent des adresses photo.php. */
function photos_html(string $h): string { return preg_replace_callback('#(["\'])uploads/([a-f0-9]{32})(_t)?\.jpg\1#', fn($m) => $m[1] . h(photo_url($m[2], $m[3] !== '')) . $m[1], $h); }
/** Règle d'accès du dossier uploads/ : photos refusées en accès direct (mise en place aussi sur les installations existantes). */
function uploads_protection(): void {
  $f = upload_dir() . '/.htaccess'; $c = (string)@file_get_contents($f); if (str_contains($c, 'amaya-photos-v1')) return;
  $r = "# amaya-photos-v1 : les photos passent par photo.php (connexion vérifiée) ; aucun script ne peut s'exécuter ici\nOptions -Indexes\n"
    . "<FilesMatch \"\\.(php|phtml|php\\d|phar|pl|py|cgi|sh)$\">\n  Require all denied\n</FilesMatch>\n"
    . "<FilesMatch \"^[a-f0-9]{32}(_t)?\\.jpg$\">\n  Require all denied\n</FilesMatch>\nRemoveHandler .php .phtml .php5 .php7 .php8\n";
  @file_put_contents($f, $r);
}
if (!defined('JETON_JOURS')) define('JETON_JOURS', 90);
function start_session(): void {
  $life = 60 * 60 * 24 * 90;
  ini_set('session.gc_maxlifetime', (string)$life);
  // Sessions rangées dans notre propre dossier : le ménage automatique de l'hébergeur (qui efface les sessions au bout de 24 min) ne les touche plus.
  $dir = upload_dir() . '/.sessions';
  if (!is_dir($dir)) @mkdir($dir, 0700, true);
  if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
  uploads_protection();
  if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
  $secure = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
  session_set_cookie_params(['lifetime' => $life, 'path' => cookie_path(), 'domain' => cookie_domain(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
  session_name('ag' . substr(hash('sha256', instance_graine()), 0, 8));
  session_start();
  // Démo : sans agence fictive personnelle en session, retour au formulaire (jamais les tables communes).
  if (demo_actif() && demo_prefixe() !== '' && !demo_lead_ok((int)substr(demo_prefixe(), 1))) unset($_SESSION['demo_p'], $_SESSION['demo_lead']);
  if (demo_actif() && demo_prefixe() === '' && !in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['demo.php', 'cron.php', 'sante.php', 'diag.php', 'installer.php'], true)) {
    if (str_ends_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), 'api.php')) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Démo terminée : rouvrez le lien reçu par e-mail.']); exit; }
    header('Location: demo.php'); exit; }
  // Filet de sécurité : si la session a disparu, le jeton « se souvenir de ce téléphone » reconnecte l'animatrice.
  // Jeton gardé dans le cookie, et en double dans le téléphone (envoyé par l'appli) : si le navigateur efface ses cookies, l'appli reconnecte quand même.
  $tok = (string)($_COOKIE['ag_rem'] ?? '') ?: (string)($_SERVER['HTTP_X_AMAYA_REM'] ?? ($_SERVER['HTTP_X_LPI_REM'] ?? '')); // ancien nom d'en-tête encore accepté
  if (empty($_SESSION['anim']) && preg_match('/^[a-f0-9]{64}$/', $tok)) {
    try {
      jetons_table();
      $st = db()->prepare("SELECT j.animatrice_id FROM jetons j JOIN animatrices a ON a.id = j.animatrice_id AND a.actif = 1 WHERE j.hash = ? AND j.expire_le > NOW()");
      $st->execute([hash('sha256', $tok)]);
      if ($id = $st->fetchColumn()) { session_regenerate_id(true); $_SESSION['anim'] = (int)$id; $_SESSION['anim_t'] = time();
        if (empty($_COOKIE['ag_rem'])) setcookie('ag_rem', $tok, ['expires' => time() + JETON_JOURS * 86400, 'path' => cookie_path(), 'domain' => cookie_domain(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']); }
    } catch (Throwable $e) { error_log(AG_COURT . ' jeton : ' . $e->getMessage()); }
  }
  if (!empty($_SESSION['anim'])) anim_session_verifier($tok);
  if (demo_actif() && demo_prefixe() !== '') { require_once __DIR__ . '/migrations_lib.php'; mig_auto(db()); } // agence fictive du visiteur
}
/** Intervenant(e) connecté(e) : accès désactivé ou téléphones déconnectés par la direction, la session se ferme aussitôt.
 *  Jeton « se souvenir de ce téléphone » prolongé au plus une fois par jour : il n'expire qu'après JETON_JOURS sans utilisation. */
function anim_session_verifier(string $tok): void {
  if (demo_actif()) return;
  try {
    jetons_table(); $st = db()->prepare("SELECT actif, acces_revoque_le FROM animatrices WHERE id = ?"); $st->execute([(int)$_SESSION['anim']]); $a = $st->fetch();
    if (!$a || !(int)$a['actif'] || (int)$a['acces_revoque_le'] >= (int)($_SESSION['anim_t'] ?? 0) && (int)$a['acces_revoque_le'] > 0) { unset($_SESSION['anim'], $_SESSION['anim_t'], $_SESSION['jeton_vu']); return; }
    if (preg_match('/^[a-f0-9]{64}$/', $tok) && (int)($_SESSION['jeton_vu'] ?? 0) < time() - 86400) {
      db()->prepare("UPDATE jetons SET expire_le = NOW() + INTERVAL " . JETON_JOURS . " DAY WHERE hash = ? AND animatrice_id = ?")->execute([hash('sha256', $tok), (int)$_SESSION['anim']]);
      $_SESSION['jeton_vu'] = time();
    }
  } catch (Throwable $e) { error_log(AG_COURT . ' session : ' . $e->getMessage()); }
}
/** Déconnecte tous les téléphones d'un(e) intervenant(e) : jetons supprimés, sessions ouvertes fermées à la requête suivante. */
function anim_deconnecter(int $id): void {
  jetons_table(); db()->prepare("DELETE FROM jetons WHERE animatrice_id = ?")->execute([$id]);
  db()->prepare("UPDATE animatrices SET acces_revoque_le = ? WHERE id = ?")->execute([time(), $id]);
}
function jetons_table(): void {
  static $ok = false; if ($ok) return;
  db()->exec("CREATE TABLE IF NOT EXISTS jetons (hash CHAR(64) PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, expire_le DATETIME NOT NULL, INDEX (animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $c = db()->query("SHOW COLUMNS FROM animatrices LIKE 'acces_revoque_le'")->fetch(); if (!$c) db()->exec("ALTER TABLE animatrices ADD COLUMN acces_revoque_le INT UNSIGNED NOT NULL DEFAULT 0");
  $ok = true;
}
// ---------- Double authentification des administrateurs (TOTP, RFC 6238 : Google Authenticator, Microsoft Authenticator…) ----------
function tf_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  $c = db()->query("SHOW COLUMNS FROM admins")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('totp_secret', $c, true)) db()->exec("ALTER TABLE admins ADD COLUMN totp_secret VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN totp_actif TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_secours TEXT NULL, ADD COLUMN totp_dernier BIGINT NOT NULL DEFAULT 0");
  if (!in_array('dirigeant', $c, true)) { db()->exec("ALTER TABLE admins ADD COLUMN dirigeant TINYINT(1) NOT NULL DEFAULT 0");
    if (ADMINS_PRINCIPAUX) { $in = implode(',', array_fill(0, count(ADMINS_PRINCIPAUX), '?')); db()->prepare("UPDATE admins SET dirigeant = 1 WHERE email IN ($in)")->execute(array_keys(ADMINS_PRINCIPAUX)); } }
}
function tf_b32(string $bin): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 5) as $g) $o .= $a[bindec(str_pad($g, 5, '0'))]; return $o; }
function tf_b32d(string $s): string { $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT); $o = ''; foreach (str_split($bits, 8) as $g) if (strlen($g) === 8) $o .= chr(bindec($g)); return $o; }
function tf_code(string $secret, int $pas): string { $h = hash_hmac('sha1', pack('J', $pas), tf_b32d($secret), true); $o = ord($h[19]) & 15; $n = ((ord($h[$o]) & 127) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000; return str_pad((string)$n, 6, '0', STR_PAD_LEFT); }
/** Pas de temps du code accepté (±30 s), ou null. Un même code ne sert qu'une fois. */
function tf_verifier(string $secret, string $code, int $dernier): ?int { $code = preg_replace('/\D/', '', $code); if (strlen($code) !== 6) return null; $t = intdiv(time(), 30);
  foreach ([0, -1, 1] as $d) if ($t + $d > $dernier && hash_equals(tf_code($secret, $t + $d), $code)) return $t + $d; return null; }
/** L'ancienne coupure d'urgence AG_2FA_OFF n'a plus d'effet : un accès perdu se rétablit par la procédure de secours (secours.php). */
function tf_off(): bool { return false; }
/** Double authentification obligatoire pour tout compte d'administration (sauf dans la démo, aux données fictives). */
function tf_obligatoire(): bool { return !demo_actif(); }

// ---------- Mot de passe de secours (config.php) ----------
/** Le mot de passe de secours n'est conservé que sous forme hachée (ADMIN_PASSWORD_HASH). Les anciennes installations (ADMIN_PASSWORD en clair) sont converties à la première connexion. */
function mdp_secours_ok(string $p): bool {
  $v = [$p, trim(trim($p), "'\" ")];
  if (defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '') { foreach ($v as $x) if ($x !== '' && password_verify($x, ADMIN_PASSWORD_HASH)) return true; return false; }
  if (defined('ADMIN_PASSWORD') && ADMIN_PASSWORD !== '') { foreach ($v as $x) if ($x !== '' && hash_equals(trim((string)ADMIN_PASSWORD), $x)) return true; }
  return false;
}
/** Remplace dans config.php la ligne du mot de passe en clair par son empreinte. Renvoie vrai si config.php ne contient plus de mot de passe en clair. */
function config_mdp_migrer(): bool {
  if (!defined('ADMIN_PASSWORD') || defined('ADMIN_PASSWORD_HASH')) return true;
  $f = __DIR__ . '/config.php'; if (!is_file($f) || !is_writable($f)) return false;
  $s = (string)file_get_contents($f); $re = "/define\\(\\s*'ADMIN_PASSWORD'\\s*,\\s*'(?:[^'\\\\]|\\\\.)*'\\s*\\);/";
  if (!preg_match($re, $s)) return false;
  $ligne = "define('ADMIN_PASSWORD_HASH', " . var_export(password_hash(trim((string)ADMIN_PASSWORD), PASSWORD_DEFAULT), true) . ");";
  $n = preg_replace_callback($re, fn() => $ligne, $s, 1); // rappel : l'empreinte contient des « $ », jamais en remplacement direct
  $n = str_replace("// Mot de passe de secours de l'administration (avec l'e-mail d'un dirigeant, ou seul)", "// Mot de passe de secours (empreinte) : première connexion d'un dirigeant et procédure de secours (secours.php)", $n);
  $tmp = $f . '.tmp' . bin2hex(random_bytes(4)); if (file_put_contents($tmp, $n) === false) return false; @chmod($tmp, fileperms($f) & 0777);
  if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
  if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
  return true;
}
/** Procédure de secours active : un fichier prive/secours.txt déposé par FTP il y a moins de 2 heures (preuve d'accès à l'hébergement). */
function secours_actif(): bool { $f = __DIR__ . '/prive/secours.txt'; return is_file($f) && filemtime($f) > time() - 7200; }
function tf_secret_clair(array $a): string { $s = (string)($a['totp_secret'] ?? ''); return $s === '' ? '' : (string)amy_dechiffre((string)base64_decode($s)); }
/** Ouvre la session administrateur (après mot de passe, et code si la double authentification est active). */
function admin_ouvrir(array $a, array $droits): void {
  session_regenerate_id(true); unset($_SESSION['tf']); $_SESSION['admin'] = true; $_SESSION['admin_debut'] = $_SESSION['admin_vu'] = time(); $_SESSION['admin_id'] = (int)$a['id']; $_SESSION['admin_email'] = $a['email']; $_SESSION['admin_nom'] = $a['nom']; $_SESSION['droits'] = $droits;
  db()->prepare("UPDATE admins SET derniere_connexion = ? WHERE id = ?")->execute([now(), $a['id']]);
  journal('connexion', 'administration');
}

/** Ce téléphone reste connecté, même si la session est effacée : JETON_JOURS jours sans utilisation au plus. */
function remember_set(int $id): string {
  jetons_table();
  $t = bin2hex(random_bytes(32));
  db()->prepare("INSERT INTO jetons (hash, animatrice_id, expire_le) VALUES (?, ?, NOW() + INTERVAL " . JETON_JOURS . " DAY)")->execute([hash('sha256', $t), $id]);
  db()->exec("DELETE FROM jetons WHERE expire_le < NOW()");
  $secure = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off');
  setcookie('ag_rem', $t, ['expires' => time() + JETON_JOURS * 86400, 'path' => cookie_path(), 'domain' => cookie_domain(), 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
  return $t;
}
function remember_clear(): void {
  if (!empty($_COOKIE['ag_rem'])) { try { jetons_table(); db()->prepare("DELETE FROM jetons WHERE hash = ?")->execute([hash('sha256', (string)$_COOKIE['ag_rem'])]); } catch (Throwable $e) {} }
  setcookie('ag_rem', '', ['expires' => time() - 3600, 'path' => cookie_path()]); setcookie('ag_rem', '', ['expires' => time() - 3600, 'path' => cookie_path(), 'domain' => cookie_domain()]);
}

// ---------- Administrateurs ----------
/** Droits de l'administrateur connecté : la lecture est toujours permise ; « tout » couvre tout. Les comptes désactivés perdent l'accès immédiatement. */
if (!defined('ADMIN_SESSION_MAX')) define('ADMIN_SESSION_MAX', 12 * 3600);       // réglable dans config.php
if (!defined('ADMIN_SESSION_INACTIF')) define('ADMIN_SESSION_INACTIF', 3600);
function admin_ok(): bool {
  static $checked = false;
  if (empty($_SESSION['admin'])) return false;
  // Plus de session anonyme « mot de passe de config.php » : chaque accès est nominatif (sessions ouvertes avant la mise à jour fermées).
  if (empty($_SESSION['admin_id']) && !demo_actif()) { foreach (['admin', 'admin_id', 'admin_email', 'admin_nom', 'droits'] as $k) unset($_SESSION[$k]); return false; }
  if (!isset($_SESSION['droits'])) $_SESSION['droits'] = [];
  // Durée des sessions de la direction : 12 h au plus, fermeture après 60 min sans activité (hors démo).
  if (!demo_actif()) { $t = time(); $_SESSION['admin_debut'] ??= $t; $_SESSION['admin_vu'] ??= $t;
    if ($t - (int)$_SESSION['admin_debut'] > ADMIN_SESSION_MAX || $t - (int)$_SESSION['admin_vu'] > ADMIN_SESSION_INACTIF) {
      foreach (['admin', 'admin_id', 'admin_email', 'admin_nom', 'droits', 'admin_debut', 'admin_vu', 'client_apercu', 'apercu'] as $k) unset($_SESSION[$k]);
      if (!headers_sent()) header('X-Amaya-Session: expiree'); return false; }
    $_SESSION['admin_vu'] = $t; }
  if (!$checked && !empty($_SESSION['admin_id'])) {
    $checked = true; tf_schema();
    $st = db()->prepare("SELECT nom, email, droits, actif, totp_actif FROM admins WHERE id = ?"); $st->execute([(int)$_SESSION['admin_id']]); $a = $st->fetch();
    if (!$a || (!(int)$a['actif'] && !isset(ADMINS_PRINCIPAUX[$a['email']]))) { $_SESSION = []; return false; }
    // Double authentification obligatoire : une session ouverte sans elle passe par l'activation avant tout accès.
    if (tf_obligatoire() && !(int)$a['totp_actif']) { $_SESSION['tf_ins'] = ['id' => (int)$_SESSION['admin_id'], 'droits' => $_SESSION['droits'], 't' => time(), 'mdp' => false, 'tf' => true];
      foreach (['admin', 'admin_id', 'admin_email', 'admin_nom', 'droits'] as $k) unset($_SESSION[$k]); return false; }
    $_SESSION['droits'] = est_dirigeant_email($a['email']) ? ['tout'] : array_values(array_filter(explode(',', $a['droits'])));
    $_SESSION['admin_nom'] = $a['nom']; $_SESSION['admin_email'] = $a['email'];
  }
  return true;
}
/** La direction (dirigeants nommés dans config.php). */
/** Dirigeant(e)s : ceux de config.php (fondateurs, seuls à pouvoir utiliser la procédure de secours) et ceux nommés dans l'appli par un(e) dirigeant(e). */
function dirigeants(bool $relire = false): array {
  static $D = null; if ($D !== null && !$relire) return $D; $D = ADMINS_PRINCIPAUX;
  try { tf_schema(); foreach (db()->query("SELECT email, nom FROM admins WHERE dirigeant = 1 AND actif = 1")->fetchAll() as $a) $D[mb_strtolower($a['email'])] ??= $a['nom']; } catch (Throwable $e) {}
  return $D;
}
function est_dirigeant_email(string $e): bool { return isset(dirigeants()[mb_strtolower($e)]); }
function est_principal(): bool { return admin_ok() && est_dirigeant_email((string)($_SESSION['admin_email'] ?? '')); }
function can(string $d): bool {
  if (!admin_ok()) return false; if ($d === 'lecture') return true; if ($d === 'principal') return est_principal();
  $x = $_SESSION['droits'] ?? []; return in_array('tout', $x, true) || in_array($d, $x, true);
}
function admin_nom(): string { return (string)($_SESSION['admin_nom'] ?? 'Administrateur'); }

function now(): string { return date('Y-m-d H:i:s'); }
function clip($s, int $max = 2000): string { return mb_substr(trim((string)($s ?? '')), 0, $max); }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($v, string $devise = 'EUR'): string {
  if ($v === null || $v === '') return '';
  return number_format((float)$v, 0, ',', "\u{202F}") . "\u{00A0}" . (DEVISE_SYMBOLE[$devise] ?? $devise);
}
function date_fr(string $d, bool $long = false): string {
  $t = strtotime($d);
  $jours = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  $mois = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  return $long ? $jours[(int)date('w', $t)] . ' ' . ((int)date('j', $t) === 1 ? '1er' : (int)date('j', $t)) . ' ' . $mois[(int)date('n', $t) - 1] . ' ' . date('Y', $t)
               : date('d/m/Y', $t);
}
function upload_dir(): string { return __DIR__ . '/uploads'; }

/** Enregistre une image envoyée : vérifie, réoriente, redimensionne, ré-encode (supprime les métadonnées). */
function save_image(string $tmp, int $max = 1600): array {
  $info = @getimagesize($tmp);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Format d\'image non reconnu (JPEG, PNG ou WebP).');
  $src = match ($info[2]) { IMAGETYPE_JPEG => imagecreatefromjpeg($tmp), IMAGETYPE_PNG => imagecreatefrompng($tmp), IMAGETYPE_WEBP => imagecreatefromwebp($tmp) };
  if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
    $o = @exif_read_data($tmp)['Orientation'] ?? 1;
    if ($o == 3) $src = imagerotate($src, 180, 0); elseif ($o == 6) $src = imagerotate($src, -90, 0); elseif ($o == 8) $src = imagerotate($src, 90, 0);
  }
  $name = bin2hex(random_bytes(16));
  foreach (['' => $max, '_t' => 700] as $suffix => $lim) {
    $w = imagesx($src); $hh = imagesy($src); $r = min(1, $lim / max($w, $hh));
    $dst = imagecreatetruecolor((int)round($w * $r), (int)round($hh * $r));
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $hh);
    imagejpeg($dst, upload_dir() . '/' . $name . $suffix . '.jpg', $suffix ? 78 : 84);
    imagedestroy($dst);
  }
  imagedestroy($src);
  return ['fichier' => $name];
}
require_once __DIR__ . '/geo_lib.php';
require_once __DIR__ . '/marques_lib.php';

/* ---------- Espace marques : périmètre d'un compte ---------- */
/** Périmètre d'un compte : liste de [marque, zones] (zones vides = toutes). */
function compte_perimetre(array $c): array {
  $p = json_decode((string)($c['perimetre'] ?? ''), true);
  if (is_array($p) && $p) return array_values(array_map(fn($x) => ['m' => (int)($x['m'] ?? 0), 'z' => array_values(array_intersect(array_keys(ZONES), (array)($x['z'] ?? []))),
    'c' => in_array($x['c'] ?? '', ['local', 'tr'], true) ? $x['c'] : ''], array_filter($p, fn($x) => (int)($x['m'] ?? 0) > 0)));
  $z = array_values(array_filter(explode(',', (string)$c['zones'])));
  return array_map(fn($m) => ['m' => (int)$m, 'z' => $z, 'c' => ''], array_values(array_filter(array_map('intval', explode(',', (string)$c['marques'])))));
}
/** Circuit : '' tous, 'local' marché local seulement, 'tr' travel retail seulement (points de vente cochés « Travel retail »). */
function perimetre_ok(array $per, int $marque, string $zone, string $circuit = ''): bool {
  foreach ($per as $x) if ($x['m'] === $marque && (!$x['z'] || in_array($zone, $x['z'], true))
    && (($x['c'] ?? '') === '' || (($x['c'] === 'tr') === ($circuit === 'tr')))) return true;
  return false;
}
/** Prénom et nom des contacts dont l'adresse ne les donne pas (comptes de l'espace marques). */
const NOMS_CONTACTS = [];
const BA_TOUTES = 'TOUTES ENSEIGNES';
const COACHS_ZONES = []; // coachs de l'agence qui suivent une zone dans l'espace marques : ['adresse' => ['Zone', …]]
/** Modèles BA visibles par un compte (module désactivé : aucun). */
function compte_ba(array $c): array { return array_values(array_intersect(array_keys(BA_MODELES), explode(',', (string)($c['ba'] ?? '')))); }
/** Adresses d'une liste de destinataires qui sont internes à l'agence (jamais transformées en comptes clients). */
function email_interne(string $e): bool {
  if (est_dirigeant_email($e) || isset(COACHS_ZONES[$e])) return true;
  $dom = strtolower(substr(strrchr(AG_EMAIL, '@') ?: '', 1)); if ($dom !== '' && !in_array($dom, ['gmail.com', 'hotmail.fr', 'hotmail.com', 'outlook.fr', 'outlook.com', 'yahoo.fr', 'orange.fr', 'wanadoo.fr', 'icloud.com'], true) && str_ends_with($e, '@' . $dom)) return true; // adresses de l'agence
  $st = db()->prepare("SELECT COUNT(*) FROM admins WHERE email = ?"); $st->execute([$e]); return (bool)$st->fetchColumn();
}
/** Propositions de comptes clients à partir des listes de destinataires : une personne par adresse, avec le périmètre exact de ses listes. */
function comptes_depuis_listes(): array {
  $L = db()->query("SELECT * FROM listes_diffusion ORDER BY ordre, nom")->fetchAll();
  $ex = []; foreach (db()->query("SELECT email FROM comptes_clients")->fetchAll(PDO::FETCH_COLUMN) as $e) $ex[mb_strtolower($e)] = true;
  $out = [];
  foreach ($L as $l) {
    $mk = array_values(array_filter(array_map('intval', explode(',', (string)$l['marques']))));
    $ba = array_values(array_intersect(array_keys(BA_MODELES), explode(',', (string)$l['modeles'])));
    if (!$mk && !$ba) continue;
    $zs = array_values(array_filter(array_map('trim', explode(',', (string)$l['zones'])))); $ci = (string)($l['circuit'] ?? '');
    foreach (emails_list((string)$l['emails']) as $e) {
      $e = mb_strtolower($e); $coach = COACHS_ZONES[$e] ?? null;
      $o = $out[$e] ?? ['email' => $e, 'nom' => NOMS_CONTACTS[$e] ?? mb_convert_case(str_replace(['.', '_', '-'], ' ', explode('@', $e)[0]), MB_CASE_TITLE), 'listes' => [], 'perimetre' => [], 'ba' => [],
        'interne' => $coach ? false : email_interne($e), 'coach' => (bool)$coach, 'existe' => isset($ex[$e])];
      $o['listes'][] = $l['nom'];
      if (!$coach) $o['ba'] = array_values(array_unique(array_merge($o['ba'], $ba))); // les coachs ne voient jamais les BA
      $z = $zs;
      if ($coach) { $z = $z ? array_values(array_intersect($z, $coach)) : $coach; }
      foreach (($coach && !$z) ? [] : $mk as $m) {
        $found = false;
        foreach ($o['perimetre'] as &$x) if ($x['m'] === $m && $x['c'] === $ci) { $found = true; $x['z'] = ($x['z'] && $z) ? array_values(array_unique(array_merge($x['z'], $z))) : []; } unset($x);
        if (!$found) $o['perimetre'][] = ['m' => $m, 'z' => $z, 'c' => $ci];
      }
      $out[$e] = $o;
    }
  }
  foreach ($out as &$o) $o['societe'] = $o['coach'] ? 'Coach ' . AG_COURT : implode(' / ', array_unique($o['listes'])); unset($o);
  return array_values(array_filter($out, fn($o) => $o['perimetre'] || $o['ba']));
}
// Démo : les pages de l'appli vérifient la session dès l'ouverture.
if (PHP_SAPI !== 'cli' && demo_actif() && in_array(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), ['index.php', 'admin.php', 'marques.php', 'offre.php', 'formation.php'], true) && session_status() === PHP_SESSION_NONE) start_session();

// ---------- Adresses e-mail saisies et lecture complète d'un classeur Excel ----------
/** Liste d'adresses saisie (virgules, points-virgules ou retours à la ligne). */
function emails_list($s): array {
  $out = [];
  foreach (preg_split('/[\s,;]+/', is_array($s) ? implode(',', $s) : (string)$s) as $e) {
    $e = mb_strtolower(trim($e)); if ($e === '') continue;
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide : ' . $e);
    if (!in_array($e, $out, true)) $out[] = $e;
  }
  if (count($out) > 30) throw new RuntimeException('30 destinataires maximum.');
  return $out;
}

/** Lit toutes les feuilles d'un classeur : [nom => [ligne => [colonne => ['v' => valeur, 'f' => formule]]]]. */
function xlsx_all(string $path): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('Lecture Excel indisponible sur ce serveur.');
  $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException('Fichier Excel illisible.');
  $load = function (string $n) use ($z) { $x = $z->getFromName($n); if ($x === false) return null; $d = new DOMDocument(); $d->loadXML($x, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE); return $d; };
  $ss = [];
  if ($d = $load('xl/sharedStrings.xml')) foreach ($d->getElementsByTagName('si') as $si) { $t = ''; foreach ($si->getElementsByTagName('t') as $tt) $t .= $tt->textContent; $ss[] = $t; }
  $rels = []; if ($d = $load('xl/_rels/workbook.xml.rels')) foreach ($d->getElementsByTagName('Relationship') as $r) $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
  $out = [];
  $wb = $load('xl/workbook.xml'); if (!$wb) throw new RuntimeException('Classeur Excel invalide.');
  foreach ($wb->getElementsByTagName('sheet') as $sh) {
    $rid = $sh->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
    $target = $rels[$rid] ?? ''; if ($target === '') continue;
    $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
    $d = $load($target); if (!$d) continue;
    $rows = [];
    foreach ($d->getElementsByTagName('c') as $c) {
      if (!preg_match('/^([A-Z]+)(\d+)$/', $c->getAttribute('r'), $m)) continue;
      $col = 0; foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64; $col--;
      $t = $c->getAttribute('t'); $v = $c->getElementsByTagName('v')->item(0)?->textContent;
      if ($t === 's') $v = $ss[(int)$v] ?? '';
      elseif ($t === 'inlineStr') $v = $c->getElementsByTagName('t')->item(0)?->textContent ?? '';
      elseif ($t === 'str' || $t === 'e') $v = (string)$v;
      elseif ($v !== null && is_numeric($v)) $v = $v + 0;
      $f = $c->getElementsByTagName('f')->item(0)?->textContent;
      if ($v === null && $f === null) continue;
      $rows[(int)$m[2]][$col] = ['v' => $v, 'f' => $f];
    }
    $out[trim($sh->getAttribute('name'))] = $rows;
  }
  $z->close();
  return $out;
}
