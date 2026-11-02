<?php
// Évolutions de la base, numérotées : chacune est appliquée une seule fois, dans l'ordre, et notée dans la table « migrations ».
// On sait ainsi à tout moment où en est chaque agence (Maintenance), et une mise à jour d'Amaya applique d'elle-même ce qui manque.
// Règles pour ajouter une évolution : un nouveau numéro à la fin de MIGRATIONS et la fonction mig_<numéro>(PDO) correspondante ;
// ne jamais modifier une évolution déjà publiée ; chaque fonction doit pouvoir être relancée sans dommage (tables ou colonnes déjà là).
// Les évolutions antérieures à ce mécanisme (avant la 1.8.0) restent appliquées par migrate() et les fonctions *_schema().

const MIGRATIONS = [
  1 => 'Point de départ : base existante (évolutions antérieures appliquées au fil de l\'eau)',
  2 => 'Documents de l\'agence : table docs_lpi renommée docs_agence',
  3 => 'Code d\'accès des intervenants : réglage code_lpi renommé code_agence',
];

function mig_derniere(): int { return (int)array_key_last(MIGRATIONS); }
function mig_table(PDO $pdo): string { $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; return $p . 'migrations'; }
function mig_existe(PDO $pdo, string $t): bool { $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"); $st->execute([$t]); return (bool)$st->fetchColumn(); }

/** Applique les évolutions manquantes. Appelé à chaque première connexion à la base ; ne coûte qu'une lecture de fichier quand tout est à jour. */
function mig_auto(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : '';
  $flag = upload_dir() . '/.migrations' . ($p !== '' ? '-' . $p : '');
  if ((int)@file_get_contents($flag) === mig_derniere()) return;
  try { mig_appliquer($pdo); @file_put_contents($flag, (string)mig_derniere()); }
  catch (Throwable $e) { error_log('Amaya évolutions de la base : ' . $e->getMessage()); }
}
/** Applique, dans l'ordre, les évolutions pas encore notées. Renvoie les numéros appliqués. */
function mig_appliquer(PDO $pdo): array {
  $T = mig_table($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS `$T` (num INT UNSIGNED PRIMARY KEY, nom VARCHAR(200) NOT NULL, le DATETIME NOT NULL, duree_ms INT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $verrou = (int)$pdo->query("SELECT GET_LOCK('amaya_migrations_" . md5(DB_NAME . $T) . "', 10)")->fetchColumn(); // deux visites simultanées : une seule applique
  $faits = [];
  try {
    $deja = array_map('intval', $pdo->query("SELECT num FROM `$T`")->fetchAll(PDO::FETCH_COLUMN));
    foreach (MIGRATIONS as $n => $nom) {
      if (in_array($n, $deja, true)) continue;
      $t0 = microtime(true); $f = 'mig_' . $n; if (function_exists($f)) $f($pdo);
      $pdo->prepare("INSERT INTO `$T` (num, nom, le, duree_ms) VALUES (?,?,?,?)")->execute([$n, $nom, date('Y-m-d H:i:s'), (int)round((microtime(true) - $t0) * 1000)]);
      $faits[] = $n;
    }
  } finally { if ($verrou) $pdo->query("SELECT RELEASE_LOCK('amaya_migrations_" . md5(DB_NAME . $T) . "')"); }
  return $faits;
}
/** État affiché dans Maintenance. */
function mig_etat(PDO $pdo): array {
  $T = mig_table($pdo); $L = mig_existe($pdo, $T) ? $pdo->query("SELECT num, nom, le FROM `$T` ORDER BY num")->fetchAll() : [];
  $n = $L ? (int)end($L)['num'] : 0;
  return ['niveau' => $n, 'attendu' => mig_derniere(), 'a_jour' => $n >= mig_derniere() && count($L) === count(MIGRATIONS), 'liste' => array_slice($L, -5)];
}

/* ---------- Les évolutions ---------- */
// Écrites avec les noms de tables complets (préfixe de la démo compris), pour ne pas dépendre de la réécriture automatique des requêtes.
function mig_1(PDO $pdo): void {}
function mig_2(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $a = $p . 'docs_lpi'; $b = $p . 'docs_agence';
  if (mig_existe($pdo, $a) && !mig_existe($pdo, $b)) $pdo->exec("RENAME TABLE `$a` TO `$b`");
  elseif (mig_existe($pdo, $a) && mig_existe($pdo, $b) && !(int)$pdo->query("SELECT COUNT(*) FROM `$b`")->fetchColumn()) { $pdo->exec("DROP TABLE `$b`"); $pdo->exec("RENAME TABLE `$a` TO `$b`"); }
}
function mig_3(PDO $pdo): void {
  $T = (function_exists('demo_prefixe') ? demo_prefixe() : '') . 'reglages'; if (!mig_existe($pdo, $T)) return;
  $v = $pdo->query("SELECT valeur FROM `$T` WHERE cle = 'code_lpi'")->fetchColumn();
  if ($v !== false) { $pdo->prepare("INSERT INTO `$T` (cle, valeur) VALUES ('code_agence', ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")->execute([$v]); $pdo->exec("DELETE FROM `$T` WHERE cle = 'code_lpi'"); }
}
