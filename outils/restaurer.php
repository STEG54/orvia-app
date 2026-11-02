<?php
// Restauration d'une agence à partir de la copie hors du serveur, sur une machine quelconque (ne dépend que de PHP, curl, openssl, PDO MySQL).
// Usage :
//   php outils/restaurer.php --parametres=fichier.php --cible=/dossier/vide [--date=AAAAMMJJ]
//        [--db-host=… --db-name=… --db-user=… --db-pass=…]   (importe la base ; sans ces options, le fichier SQL est seulement écrit)
//        [--verifier]                                         (relit tout sans rien écrire)
// fichier.php contient les define() SV_S3_ENDPOINT, SV_S3_REGION, SV_S3_BUCKET, SV_S3_CLE_ACCES, SV_S3_SECRET, SV_CLE_EXTERNE, SV_S3_PREFIXE.
// Résultat dans la cible : uploads/ (photos et documents), config.php.restaure (à relire : accès à la nouvelle base), base.sql.
if (PHP_SAPI !== 'cli') exit;
$o = getopt('', ['parametres:', 'cible:', 'date:', 'db-host:', 'db-name:', 'db-user:', 'db-pass:', 'verifier']);
if (empty($o['parametres']) || (empty($o['cible']) && !isset($o['verifier']))) { fwrite(STDERR, "Usage : php outils/restaurer.php --parametres=fichier.php --cible=/dossier [--date=AAAAMMJJ] [--db-host= --db-name= --db-user= --db-pass=] [--verifier]\n"); exit(2); }
require $o['parametres']; require __DIR__ . '/../app/stockage_lib.php';
if (!sx_actif()) { fwrite(STDERR, "Paramètres incomplets ou clé externe invalide.\n"); exit(2); }
if (!defined('SV_S3_PREFIXE') || SV_S3_PREFIXE === '') { fwrite(STDERR, "Indiquez SV_S3_PREFIXE (dossier de l'agence, affiché dans Maintenance).\n"); exit(2); }
$P = SV_S3_PREFIXE; $verif = isset($o['verifier']); $cible = rtrim((string)($o['cible'] ?? ''), '/'); $t0 = microtime(true);
$dire = fn($m) => print('[' . date('H:i:s') . "] $m\n");
if (!$verif) { if (is_dir($cible) && count(scandir($cible)) > 2) { fwrite(STDERR, "La cible doit être un dossier vide.\n"); exit(2); } @mkdir($cible, 0750, true); }

// 1. Base
$bases = array_keys(sx_liste("$P/base/")); rsort($bases);
if (!empty($o['date'])) $bases = array_values(array_filter($bases, fn($k) => str_contains(basename($k), 'amaya-' . $o['date'])));
if (!$bases) { fwrite(STDERR, "Aucune sauvegarde de la base trouvée.\n"); exit(1); }
$gz = sx_dechiffre(sx_get($bases[0])); $sql = $gz === null ? false : gzdecode($gz);
if ($sql === false) { fwrite(STDERR, "Sauvegarde illisible : clé externe incorrecte ?\n"); exit(1); }
preg_match_all('/^CREATE TABLE `([^`]+)`/m', $sql, $m); $dire(basename($bases[0]) . ' : ' . count($m[1]) . ' tables');
if (!$verif) { file_put_contents("$cible/base.sql", $sql);
  if (!empty($o['db-name'])) { $pdo = new PDO('mysql:host=' . ($o['db-host'] ?? 'localhost') . ';dbname=' . $o['db-name'] . ';charset=utf8mb4', (string)($o['db-user'] ?? ''), (string)($o['db-pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec($sql); $n = count($pdo->query('SHOW TABLES')->fetchAll()); $dire("base importée dans {$o['db-name']} : $n tables"); } }

// 2. config.php
$cfg = sx_dechiffre(sx_get("$P/config/config.php.amx")); if ($cfg === null) { fwrite(STDERR, "config.php illisible.\n"); exit(1); }
$dire('config.php relu' . (preg_match('/(LPI|AMAYA)_CLE/', $cfg) ? ' (clé des documents présente)' : ' (SANS clé des documents)'));
if (!$verif) { file_put_contents("$cible/config.php.restaure", $cfg); @chmod("$cible/config.php.restaure", 0600); }
// Clé gardée hors du dossier web (LPI_CLE_FICHIER) : copiée à part, à replacer au chemin indiqué dans config.php.
if (preg_match('/(LPI|AMAYA)_CLE_FICHIER/', $cfg)) { $k = null; try { $k = sx_dechiffre(sx_get("$P/config/cle.amx")); } catch (Throwable $e) {}
  $dire($k === null ? 'ATTENTION : fichier de clé introuvable dans la copie' : 'fichier de clé relu');
  if (!$verif && $k !== null) { file_put_contents("$cible/cle.txt.restaure", $k); @chmod("$cible/cle.txt.restaure", 0600); } }

// 3. Fichiers
$F = sx_liste("$P/fichiers/"); $n = 0; $err = 0;
foreach (array_keys($F) as $k) { $rel = substr($k, strlen("$P/fichiers/"), -4); if ($rel === '' || str_contains($rel, '..')) continue;
  $d = sx_dechiffre(sx_get($k)); if ($d === null) { $err++; continue; } $n++;
  if (!$verif) { $f = "$cible/uploads/$rel"; @mkdir(dirname($f), 0750, true); file_put_contents($f, $d); } }
$dire("$n fichiers relus" . ($err ? ", $err illisibles" : '') . ($verif ? '' : " et écrits dans $cible/uploads"));
$dire('terminé en ' . round(microtime(true) - $t0, 1) . ' s');
if (!$verif) echo "\nÉtapes suivantes : déposer le paquet amaya.zip dans le dossier de l'installation, y copier uploads/,\nrenommer config.php.restaure en config.php et y corriger DB_HOST, DB_NAME, DB_USER, DB_PASS et BASE_URL.\n";
exit($err ? 1 : 0);
