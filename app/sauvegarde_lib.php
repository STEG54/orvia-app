<?php
// Sauvegardes de la base : une par jour (cron, ou à la première ouverture du tableau de bord de la journée), chiffrées avec la clé de config.php,
// gardées 14 jours + la première de chaque mois pendant un an. Les photos et documents restent sur l'hébergement (sauvegardés aussi par OVH).
require_once __DIR__ . '/lib.php';

function sv_dir(): string {
  $d = upload_dir() . '/.sauvegardes';
  if (!is_dir($d)) @mkdir($d, 0700, true);
  if (!is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\nDeny from all\n");
  return $d;
}
/** Liste des sauvegardes, la plus récente d'abord. */
function sv_liste(): array {
  $L = [];
  foreach (glob(sv_dir() . '/amaya-*.sql.gz.enc') ?: [] as $f) $L[] = ['nom' => basename($f), 'taille' => filesize($f), 'date' => date('Y-m-d H:i', filemtime($f))];
  usort($L, fn($a, $b) => strcmp($b['nom'], $a['nom'])); return $L;
}
/** Export SQL complet (structure + données), compressé puis chiffré (AES-256-GCM).
 *  Écrit au fil de l'eau : lignes lues une à une (sans tampon côté PHP) et compressées directement dans un fichier temporaire ;
 *  seule la version compressée (5 à 10 fois plus petite) passe en mémoire, le temps du chiffrement. */
function sv_faire(string $motif = 'auto'): array {
  $tmp = sv_dir() . '/.en-cours-' . bin2hex(random_bytes(4)) . '.gz';
  try {
    sv_ecrire_gz($tmp, $motif);
    $nom = 'amaya-' . date('Ymd-Hi') . '.sql.gz.enc'; $f = sv_dir() . '/' . $nom;
    if (file_put_contents($f, amy_chiffre((string)file_get_contents($tmp))) === false) throw new RuntimeException('Écriture de la sauvegarde impossible (espace disque ou droits du dossier uploads).');
  } finally { @unlink($tmp); }
  @chmod($f, 0600); sv_rotation(); @file_put_contents(sv_dir() . '/.derniere', (string)time());
  return ['nom' => $nom, 'taille' => filesize($f)];
}
/** Export SQL complet écrit au fil de l'eau dans un fichier compressé (non chiffré : à placer dans un dossier protégé, puis à effacer). */
function sv_ecrire_gz(string $tmp, string $motif): void {
  @set_time_limit(600); $pdo = db(); $gz = gzopen($tmp, 'wb6');
  if (!$gz) throw new RuntimeException('Écriture de la sauvegarde impossible (espace disque ou droits du dossier uploads).');
  try {
    gzwrite($gz, "-- Sauvegarde " . AG_NOM . " (" . $motif . ") du " . date('d/m/Y H:i') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
    $tampon = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    foreach ($tables as [$t]) {
      $cr = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
      gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n$cr;\n");
      $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
      try {
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM); $buf = [];
        while ($r = $st->fetch()) {
          $buf[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
          if (count($buf) >= 200) { gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $buf) . ";\n"); $buf = []; }
        }
        $st->closeCursor();
        if ($buf) gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(",\n", $buf) . ";\n");
      } finally { $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $tampon); }
      gzwrite($gz, "\n");
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n");
  } finally { gzclose($gz); }
}
/** Garde les 14 dernières, plus la première de chaque mois sur 12 mois. */
function sv_rotation(): void {
  $L = array_column(sv_liste(), 'nom'); $garde = array_slice($L, 0, 14); $mois = [];
  foreach (array_reverse($L) as $n) { $m = substr($n, 6, 6); if (!isset($mois[$m]) && count($mois) < 12) { $mois[$m] = 1; $garde[] = $n; } }
  foreach (array_diff($L, $garde) as $n) @unlink(sv_dir() . '/' . $n);
  foreach (glob(sv_dir() . '/.en-cours-*.gz') ?: [] as $t) if (filemtime($t) < time() - 86400) @unlink($t); // sauvegarde interrompue
}
/** Sauvegarde du jour si la dernière a plus de 20 h (appelé par cron.php et à l'ouverture du tableau de bord). Ne bloque jamais l'appli. */
function sv_auto(): void {
  try { $d = (int)@file_get_contents(sv_dir() . '/.derniere'); if (time() - $d < 20 * 3600) return; sv_faire('auto'); }
  catch (Throwable $e) { error_log('Amaya sauvegarde : ' . $e->getMessage()); }
}
/** Lit une sauvegarde compressée par morceaux : noms des tables créées et taille du SQL, sans tout décompresser en mémoire. */
function sv_inventaire(string $gz): ?array {
  $ctx = @inflate_init(ZLIB_ENCODING_GZIP); if (!$ctx) return null; $tables = []; $taille = 0; $reste = '';
  for ($i = 0, $n = strlen($gz); $i < $n; $i += 262144) {
    $b = @inflate_add($ctx, substr($gz, $i, 262144), $i + 262144 >= $n ? ZLIB_FINISH : ZLIB_SYNC_FLUSH); if ($b === false) return null;
    $taille += strlen($b); $b = $reste . $b; $p = strrpos($b, "\n"); $reste = $p === false ? $b : substr($b, $p + 1); $b = $p === false ? '' : substr($b, 0, $p);
    if (preg_match_all('/^CREATE TABLE `([^`]+)`/m', $b, $m)) array_push($tables, ...$m[1]);
  }
  if (preg_match('/^CREATE TABLE `([^`]+)`/', $reste, $m)) $tables[] = $m[1];
  return ['tables' => $tables, 'taille' => $taille];
}
/** Contenu SQL compressé (.sql.gz) d'une sauvegarde, déchiffré, pour la télécharger. */
function sv_lire(string $nom): string {
  if (!preg_match('/^amaya-\d{8}-\d{4}\.sql\.gz\.enc$/', $nom) || !is_file(sv_dir() . '/' . $nom)) throw new RuntimeException('Sauvegarde introuvable.');
  $gz = amy_dechiffre((string)file_get_contents(sv_dir() . '/' . $nom)); if ($gz === null) throw new RuntimeException('Sauvegarde illisible : la clé de config.php a changé ?');
  return $gz;
}
