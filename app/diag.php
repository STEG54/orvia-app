<?php
// Diagnostic d'installation. Supprimez ce fichier une fois l'appli en marche.
header('Content-Type: text/html; charset=utf-8');
// Protégé : il affiche des informations sur le serveur et la base. Ouvert seulement
//  - avant l'installation (pas encore de config.php),
//  - à un(e) dirigeant(e) connecté(e),
//  - ou pendant 2 heures après le dépôt par FTP d'un fichier diag.txt dans le dossier prive/ (quand l'appli ne s'ouvre plus).
header('Cache-Control: no-store'); header('X-Robots-Tag: noindex'); header('X-Frame-Options: DENY');
(function () {
  $d = __DIR__ . '/prive/diag.txt'; if (!is_file(__DIR__ . '/config.php') || (is_file($d) && filemtime($d) > time() - 7200)) return;
  try { ob_start(); require_once __DIR__ . '/lib.php'; start_session(); $ok = est_principal(); ob_end_clean(); if ($ok) return; } catch (Throwable $e) { while (ob_get_level()) ob_end_clean(); }
  http_response_code(403);
  exit('<p style="font:15px sans-serif;max-width:560px;margin:40px auto">Diagnostic fermé. Connectez-vous à l\'administration en tant que dirigeant(e), ou, si l\'appli ne s\'ouvre plus, déposez par FTP un fichier vide nommé <b>diag.txt</b> dans le dossier <b>prive</b> : le diagnostic s\'ouvre alors pendant 2 heures.</p>');
})();
function ligne($ok, $txt) { echo '<p style="font:15px sans-serif;margin:8px 0">' . ($ok ? '<b style="color:#2e7d32">OK</b> ' : '<b style="color:#c62828">PROBLÈME</b> ') . $txt . '</p>'; }
echo '<h2 style="font:600 18px sans-serif">Amaya : diagnostic</h2>';
$v = PHP_VERSION;
ligne(version_compare($v, '8.0.0', '>='), 'Version PHP : ' . $v . (version_compare($v, '8.0.0', '>=') ? '' : ' (il faut PHP 8.0 minimum : voir le fichier .ovhconfig)'));
ligne(extension_loaded('pdo_mysql'), 'Extension PDO MySQL');
ligne(extension_loaded('gd'), 'Extension GD (photos)');
if (!is_file(__DIR__ . '/config.php')) { ligne(false, 'config.php introuvable'); exit; }
$code = file_get_contents(__DIR__ . '/config.php');
if (!is_readable(__DIR__ . '/config.php')) { ligne(false, 'config.php existe mais ne peut pas être lu (droits du fichier : mettez 0644 dans FileZilla, clic droit, Droits d\'accès)'); exit; }
$lines = file(__DIR__ . '/config.php');
function montre($lines, $n) {
  $l = isset($lines[$n - 1]) ? rtrim($lines[$n - 1]) : '';
  if (preg_match('/(DB_PASS|ADMIN_PASSWORD_HASH|ADMIN_PASSWORD)/', $l, $m)) $l = substr($l, 0, strpos($l, $m[1]) + strlen($m[1])) . ' … (valeur masquée)';
  return '<br><code style="background:#f4eee7;padding:4px 8px;display:inline-block;margin-top:6px">ligne ' . $n . ' : ' . htmlspecialchars($l) . '</code>';
}
ob_start();
try { $ok = include_once __DIR__ . '/config.php'; }
catch (ParseError $e) { ob_end_clean(); ligne(false, 'config.php contient une erreur de frappe vers la ligne ' . $e->getLine() . ' (souvent une apostrophe, une virgule ou un point-virgule manquant, ou des guillemets typographiques ’ “ au lieu de \' ).' . montre($lines, $e->getLine() - 1) . montre($lines, $e->getLine())); exit; }
catch (Throwable $e) { ob_end_clean(); ligne(false, 'config.php contient une erreur de frappe ligne ' . $e->getLine() . ' : la valeur doit être entre apostrophes droites \' \' (pas ’ ’ ni “ ”).' . montre($lines, $e->getLine())); exit; }
ob_end_clean();
$manque = array();
foreach (array('DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS') as $c) if (!defined($c)) $manque[] = $c; if (!defined('ADMIN_PASSWORD_HASH') && !defined('ADMIN_PASSWORD')) $manque[] = 'ADMIN_PASSWORD_HASH';
if ($manque) { ligne(false, 'config.php : ligne(s) absente(s) ou mal écrite(s) pour ' . implode(', ', $manque) . '. Chaque ligne doit avoir la forme <code>define(\'DB_PASS\', \'valeur\');</code>'); exit; }
ligne(true, 'config.php lu');
ligne(strpos(DB_HOST, 'xxxxxx') === false && strpos(DB_NAME, 'xxxxxx') === false, 'DB_HOST = ' . htmlspecialchars(DB_HOST) . ', DB_NAME = ' . htmlspecialchars(DB_NAME) . ', DB_USER = ' . htmlspecialchars(DB_USER));
ligne(DB_PASS !== 'mot_de_passe_base' && DB_PASS !== '', 'DB_PASS renseigné');
ligne(defined('ADMIN_PASSWORD_HASH'), defined('ADMIN_PASSWORD_HASH') ? 'Mot de passe de secours enregistré sous forme d\'empreinte' : 'Mot de passe de secours encore en clair : connectez-vous une fois à admin.php pour le convertir (config.php doit être modifiable)');
try {
  $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
  ligne(true, 'Connexion à la base');
  $t = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
  $need = array('animatrices', 'marques', 'magasins', 'rapports', 'rapport_marques', 'photos', 'envois');
  $miss = array_diff($need, $t);
  ligne(!$miss, $miss ? 'Tables manquantes : ' . implode(', ', $miss) . ' (importez install.sql)' : 'Les 7 tables sont présentes');
} catch (Exception $e) {
  $m = $e->getMessage();
  $why = strpos($m, '1045') !== false ? 'mot de passe ou utilisateur refusé' : (strpos($m, '1049') !== false ? 'nom de base inconnu' : (strpos($m, '2002') !== false ? 'adresse du serveur introuvable' : ''));
  ligne(false, 'Connexion à la base : ' . htmlspecialchars($m) . ($why ? ' <b>(' . $why . ')</b>' : ''));
}
// Fichiers bien envoyés et à jour ?
$attendus = json_decode('/*MANIFEST*/', true) ?: array();
$pb = array();
foreach ($attendus as $f => $md5) { if (!is_file(__DIR__ . '/' . $f)) $pb[] = $f . ' (absent)'; elseif (md5_file(__DIR__ . '/' . $f) !== $md5) $pb[] = $f . ' (ancienne version)'; }
ligne(!$pb, $pb ? 'Fichiers à renvoyer par FileZilla : ' . htmlspecialchars(implode(', ', $pb)) : 'Tous les fichiers de l\'appli sont à jour (' . count($attendus) . ')');
// Test réel de l'appli (mise à jour automatique de la base comprise)
if (!$pb) {
  try { require_once __DIR__ . '/lib.php'; db(); reglage('code_agence'); db()->query('SELECT COUNT(*) FROM brouillons'); ligne(true, 'Appli testée : base à jour, code de l\'appli = ' . htmlspecialchars(reglage('code_agence'))); }
  catch (Throwable $e) { ligne(false, 'Test de l\'appli : ' . htmlspecialchars($e->getMessage()) . ' (' . basename($e->getFile()) . ', ligne ' . $e->getLine() . ')'); }
}
$up = __DIR__ . '/uploads';
ligne(is_dir($up) && is_writable($up), 'Dossier uploads accessible en écriture');
