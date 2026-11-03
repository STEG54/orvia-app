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
  4 => 'ORVIA : logements, propriétaires, réservations, fiches voyageurs, missions et incidents',
  5 => 'ORVIA : arrivée des voyageurs, tâches du gestionnaire, avis, lien de mission, position, services à l\'arrivée',
  6 => 'ORVIA : livret d\'arrivée (position fixée, repère, étapes en photos, Wi-Fi)',
  7 => 'ORVIA : questions des voyageurs et réponses de l\'assistant',
  8 => 'Keylia : messages, rapports et devis échangés avec les propriétaires',
  9 => 'Keylia : linge (articles, kits, blanchisserie) et entretiens programmés',
  10 => 'Keylia : paiement de l\'équipe (ajustements et règlements mensuels)',
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
function mig_4(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_proprietaires` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(160) NOT NULL, email VARCHAR(160) NOT NULL DEFAULT '', telephone VARCHAR(40) NOT NULL DEFAULT '',
    jeton_hash CHAR(64) DEFAULT NULL, plafond DECIMAL(10,2) NOT NULL DEFAULT 0, actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, UNIQUE KEY jeton_hash (jeton_hash)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_logements` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(160) NOT NULL, ville VARCHAR(80) NOT NULL DEFAULT '', adresse VARCHAR(300) NOT NULL DEFAULT '',
    proprietaire_id INT UNSIGNED DEFAULT NULL, agent_id INT UNSIGNED DEFAULT NULL, acces_enc TEXT, code_enc TEXT, checklist TEXT, kit_linge VARCHAR(120) NOT NULL DEFAULT '', duree_menage SMALLINT UNSIGNED NOT NULL DEFAULT 120,
    ical TEXT, ical_maj DATETIME DEFAULT NULL, notes TEXT, actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, KEY proprietaire_id (proprietaire_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_reservations` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logement_id INT UNSIGNED NOT NULL, source VARCHAR(20) NOT NULL DEFAULT 'direct', uid VARCHAR(220) NOT NULL DEFAULT '',
    arrivee DATE NOT NULL, depart DATE NOT NULL, libelle VARCHAR(190) NOT NULL DEFAULT '', nb_voyageurs TINYINT UNSIGNED NOT NULL DEFAULT 1, statut VARCHAR(12) NOT NULL DEFAULT 'confirmee',
    lien_hash CHAR(64) NOT NULL, lien_enc TEXT, code_envoye_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
    UNIQUE KEY lien_hash (lien_hash), KEY logement (logement_id, arrivee), KEY uid (logement_id, uid(100))) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_voyageurs` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, data_enc TEXT NOT NULL, piece CHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL, scelle_at DATETIME DEFAULT NULL, KEY reservation_id (reservation_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_missions` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logement_id INT UNSIGNED NOT NULL, reservation_id INT UNSIGNED DEFAULT NULL, type VARCHAR(12) NOT NULL DEFAULT 'menage',
    date DATE NOT NULL, heure VARCHAR(5) NOT NULL DEFAULT '', animatrice_id INT UNSIGNED DEFAULT NULL, statut VARCHAR(12) NOT NULL DEFAULT 'a_faire', etat MEDIUMTEXT, note VARCHAR(500) NOT NULL DEFAULT '',
    debut_at DATETIME DEFAULT NULL, fin_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, KEY jour (date), KEY agent (animatrice_id, date), KEY reservation_id (reservation_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_incidents` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logement_id INT UNSIGNED NOT NULL, mission_id INT UNSIGNED DEFAULT NULL, auteur VARCHAR(160) NOT NULL DEFAULT '',
    description VARCHAR(1000) NOT NULL, photo CHAR(32) DEFAULT NULL, bloquant TINYINT(1) NOT NULL DEFAULT 0, statut VARCHAR(10) NOT NULL DEFAULT 'ouvert', cout DECIMAL(10,2) DEFAULT NULL,
    proprio_statut VARCHAR(10) NOT NULL DEFAULT '', proprio_le DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, clos_at DATETIME DEFAULT NULL, KEY logement (logement_id, statut)) $E");
}
function mig_5(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $col = function (string $t, string $c, string $def) use ($pdo, $p) { $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"); $st->execute([$p . $t, $c]);
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$p}$t` ADD COLUMN $c $def"); };
  $col('c_reservations', 'arrivee_mode', "VARCHAR(10) NOT NULL DEFAULT ''");
  $col('c_reservations', 'vol', "VARCHAR(12) NOT NULL DEFAULT ''");
  $col('c_reservations', 'arrivee_heure', "VARCHAR(5) NOT NULL DEFAULT ''");
  $col('c_reservations', 'whatsapp_enc', 'TEXT');
  $col('c_missions', 'admin_id', 'INT UNSIGNED DEFAULT NULL');
  $col('c_missions', 'duree', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
  $col('c_missions', 'lien_hash', 'CHAR(64) DEFAULT NULL');
  foreach (['debut', 'fin'] as $k) { $col('c_missions', $k . '_lat', 'DECIMAL(9,6) DEFAULT NULL'); $col('c_missions', $k . '_lng', 'DECIMAL(9,6) DEFAULT NULL'); $col('c_missions', $k . '_dist', 'INT DEFAULT NULL'); }
  $col('c_logements', 'lat', 'DECIMAL(9,6) DEFAULT NULL'); $col('c_logements', 'lng', 'DECIMAL(9,6) DEFAULT NULL');
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_avis` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, logement_id INT UNSIGNED NOT NULL, moment VARCHAR(8) NOT NULL,
    proprete TINYINT UNSIGNED DEFAULT NULL, logement TINYINT UNSIGNED DEFAULT NULL, accueil TINYINT UNSIGNED DEFAULT NULL, commentaire VARCHAR(1000) NOT NULL DEFAULT '', photo CHAR(32) DEFAULT NULL,
    mission_id INT UNSIGNED DEFAULT NULL, animatrice_id INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE KEY un (reservation_id, moment), KEY logement (logement_id), KEY agent (animatrice_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_services` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(120) NOT NULL, nom_en VARCHAR(120) NOT NULL DEFAULT '', nom_ar VARCHAR(120) NOT NULL DEFAULT '',
    description VARCHAR(300) NOT NULL DEFAULT '', prix DECIMAL(10,2) NOT NULL DEFAULT 0, cout DECIMAL(10,2) NOT NULL DEFAULT 0, delai_h SMALLINT UNSIGNED NOT NULL DEFAULT 24, actif TINYINT(1) NOT NULL DEFAULT 1, ordre SMALLINT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_commandes` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, service_id INT UNSIGNED NOT NULL, quantite SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    prix DECIMAL(10,2) NOT NULL, cout DECIMAL(10,2) NOT NULL, statut VARCHAR(10) NOT NULL DEFAULT 'demandee', mission_id INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, KEY reservation_id (reservation_id), KEY mois (created_at)) $E");
}

function mig_6(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : '';
  foreach (['livret_enc' => 'TEXT', 'pos_fixe' => 'TINYINT(1) NOT NULL DEFAULT 0'] as $c => $def) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"); $st->execute([$p . 'c_logements', $c]);
    if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$p}c_logements` ADD COLUMN $c $def");
  }
}

function mig_7(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : '';
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_messages` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, auteur VARCHAR(10) NOT NULL, texte TEXT NOT NULL,
    a_traiter TINYINT(1) NOT NULL DEFAULT 0, traite_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, KEY reservation_id (reservation_id), KEY a_traiter (a_traiter)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function mig_8(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  // Lien du propriétaire gardé chiffré : il part dans les e-mails et WhatsApp de la conciergerie à chaque message ou rapport.
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'jeton_enc'"); $st->execute([$p . 'c_proprietaires']);
  if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$p}c_proprietaires` ADD COLUMN jeton_enc TEXT NULL");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_prop_messages` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, proprietaire_id INT UNSIGNED NOT NULL, logement_id INT UNSIGNED DEFAULT NULL, auteur VARCHAR(12) NOT NULL,
    nom VARCHAR(120) NOT NULL DEFAULT '', texte TEXT NOT NULL, photos TEXT NULL, lu_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, KEY proprietaire_id (proprietaire_id), KEY lu_at (lu_at)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_prop_rapports` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, proprietaire_id INT UNSIGNED NOT NULL, type VARCHAR(10) NOT NULL, titre VARCHAR(200) NOT NULL,
    periode_from DATE DEFAULT NULL, periode_to DATE DEFAULT NULL, mission_id INT UNSIGNED DEFAULT NULL, note TEXT NULL, contenu MEDIUMTEXT NULL, photos TEXT NULL, par VARCHAR(120) NOT NULL DEFAULT '',
    lu_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, KEY proprietaire_id (proprietaire_id)) $E");
  // Devis avant travaux : accepté ou refusé par le propriétaire en ligne, puis réglé.
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_prop_devis` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, proprietaire_id INT UNSIGNED NOT NULL, logement_id INT UNSIGNED DEFAULT NULL, titre VARCHAR(200) NOT NULL,
    description TEXT NULL, montant DECIMAL(10,2) NOT NULL DEFAULT 0, prestataire VARCHAR(160) NOT NULL DEFAULT '', photos TEXT NULL, statut VARCHAR(10) NOT NULL DEFAULT 'envoye', reponse TEXT NULL,
    decide_at DATETIME DEFAULT NULL, paiement VARCHAR(10) NOT NULL DEFAULT '', regle_at DATETIME DEFAULT NULL, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
    KEY proprietaire_id (proprietaire_id), KEY statut (statut)) $E");
}

function mig_9(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_linge_articles` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nom VARCHAR(80) NOT NULL, stock INT NOT NULL DEFAULT 0, prix_lavage DECIMAL(8,2) NOT NULL DEFAULT 0,
    ordre SMALLINT NOT NULL DEFAULT 0, actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_linge_kits` (logement_id INT UNSIGNED NOT NULL, article_id INT UNSIGNED NOT NULL, qte SMALLINT UNSIGNED NOT NULL DEFAULT 0, PRIMARY KEY (logement_id, article_id)) $E");
  // Un envoi à la blanchisserie : le linge sale des ménages faits jusqu'à son départ ; au retour, les quantités reçues (l'écart = pertes, retirées du stock).
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_linge_envois` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, blanchisserie VARCHAR(120) NOT NULL DEFAULT '', envoye_at DATETIME NOT NULL, retour_at DATETIME DEFAULT NULL,
    statut VARCHAR(10) NOT NULL DEFAULT 'envoye', lignes TEXT NOT NULL, cout DECIMAL(10,2) NOT NULL DEFAULT 0, note VARCHAR(300) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '') $E");
  // Entretien programmé : une tâche récurrente par logement, qui crée sa mission de maintenance à l'approche de l'échéance.
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_entretiens` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, logement_id INT UNSIGNED NOT NULL, titre VARCHAR(160) NOT NULL, frequence_jours SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    prochaine DATE NOT NULL, heure VARCHAR(5) NOT NULL DEFAULT '10:00', animatrice_id INT UNSIGNED DEFAULT NULL, prestataire VARCHAR(160) NOT NULL DEFAULT '', consignes TEXT NULL, actif TINYINT(1) NOT NULL DEFAULT 1,
    derniere_mission_id INT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, KEY logement_id (logement_id), KEY prochaine (prochaine)) $E");
  $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'entretien_id'"); $st->execute([$p . 'c_missions']);
  if (!(int)$st->fetchColumn()) $pdo->exec("ALTER TABLE `{$p}c_missions` ADD COLUMN entretien_id INT UNSIGNED DEFAULT NULL");
}

function mig_10(PDO $pdo): void {
  $p = function_exists('demo_prefixe') ? demo_prefixe() : ''; $E = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_paie_ajust` (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, mois CHAR(7) NOT NULL, libelle VARCHAR(160) NOT NULL, montant DECIMAL(10,2) NOT NULL,
    par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, KEY am (animatrice_id, mois)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS `{$p}c_paie_reglements` (animatrice_id INT UNSIGNED NOT NULL, mois CHAR(7) NOT NULL, montant DECIMAL(10,2) NOT NULL, moyen VARCHAR(20) NOT NULL DEFAULT '', detail MEDIUMTEXT NULL,
    regle_at DATETIME NOT NULL, par VARCHAR(120) NOT NULL DEFAULT '', PRIMARY KEY (animatrice_id, mois)) $E");
}
