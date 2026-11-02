<?php
// Clé de chiffrement des documents (AMAYA_CLE, ou LPI_CLE sur les installations d'origine) : changement de clé sans rien perdre.
// 1. « Changer la clé » (Maintenance, dirigeant) : nouvelle clé dans config.php, l'ancienne passe dans AMAYA_CLES_ANCIENNES (encore lue).
// 2. Rechiffrement par lots (tâche planifiée, ou bouton) : pièces, CV, contrats, preuves, n° de sécurité sociale, IBAN,
//    secrets de double authentification et sauvegardes locales passent sous la nouvelle clé.
// 3. Une fois tout rechiffré : « Retirer l'ancienne clé ».
require_once __DIR__ . '/lib.php';

/** État affiché dans Maintenance. */
function cle_etat(): array {
  $anc = defined('LPI_CLES_ANCIENNES') ? count((array)LPI_CLES_ANCIENNES) : 0;
  $e = json_decode((string)reglage('cle_rechiffrement', ''), true) ?: null;
  $fic = defined('LPI_CLE_FICHIER') && LPI_CLE_FICHIER !== '';
  return ['source' => $fic ? 'fichier' : (defined('LPI_CLE') ? 'config' : 'aucune'), 'expose' => $fic && cle_chemin_expose((string)LPI_CLE_FICHIER),
    'repli' => is_file(upload_dir() . '/fiches/.cle'), 'anciennes' => $anc, 'rechiffrement' => $e, 'modifiable' => is_writable(__DIR__ . '/config.php')];
}
/** Réécrit config.php de façon sûre (fichier temporaire puis remplacement). */
function cle_config_ecrire(callable $f): void {
  $c = __DIR__ . '/config.php'; if (!is_writable($c)) throw new RuntimeException('config.php n\'est pas modifiable par l\'appli : faites le changement à la main (voir LISEZMOI).');
  $s = (string)file_get_contents($c); $n = $f($s); if ($n === $s) return;
  $tmp = $c . '.tmp' . bin2hex(random_bytes(4)); if (file_put_contents($tmp, $n) === false) throw new RuntimeException('Écriture de config.php impossible.');
  @chmod($tmp, fileperms($c) & 0777); if (!@rename($tmp, $c)) { @unlink($tmp); throw new RuntimeException('Remplacement de config.php impossible.'); }
  if (function_exists('opcache_invalidate')) @opcache_invalidate($c, true);
}
/** Avant de sortir la clé de config.php : la graine de l'installation ne doit plus être la clé elle-même.
 *  Elle devient une empreinte (irréversible) ; conséquences, une seule fois : chacun se reconnecte, l'adresse de surveillance change.
 *  Le dossier de la copie hors du serveur est figé tel quel (SV_S3_PREFIXE) pour ne pas repartir de zéro. */
function cle_figer_graine(string $s): string {
  if (defined('AMAYA_GRAINE') && (string)AMAYA_GRAINE !== (defined('LPI_CLE') ? (string)LPI_CLE : '')) return $s;
  $s = preg_replace("/\\ndefine\\(\\s*'AMAYA_GRAINE'.*\\n?/", "\n", $s);
  $s = rtrim($s) . "\n// Graine de l'installation (cookies de session, surveillance, copie externe) : ne pas modifier.\ndefine('AMAYA_GRAINE', " . var_export(hash('sha256', 'graine|' . instance_graine()), true) . ");\n";
  if (!defined('SV_S3_PREFIXE') || SV_S3_PREFIXE === '') { require_once __DIR__ . '/externe_lib.php'; if (sx_actif()) $s .= "define('SV_S3_PREFIXE', " . var_export(sx_prefixe(), true) . "); // dossier de la copie hors du serveur\n"; }
  return $s;
}
/** Emplacement proposé pour la clé, hors de tout dossier web : dans le dossier du compte, à côté de « www » (ex. /home/compte/amaya-prive/).
 *  Le dossier du compte est cherché dans le chemin de l'appli (avant « www », « public_html »...), et non à côté du dossier du site :
 *  un site dont la racine est www/animation mettrait sinon la clé dans www/amaya-prive, joignable par le site principal. */
function cle_dossier_hors_web(): ?string {
  $app = realpath(__DIR__) ?: __DIR__; $doc = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: ''; $base = null;
  foreach ([$app, $doc] as $p) if ($p !== '' && preg_match('#^(/.+?)/(?:www|public_html|htdocs|httpdocs)(?:/|$)#', $p, $m)) { $base = $m[1]; break; }
  $base ??= $doc !== '' ? dirname($doc) : dirname($app, 2); if ($base === '' || $base === '/') return null;
  $d = $base . '/amaya-prive'; return cle_chemin_expose($d) ? null : $d;
}
/** Un chemin est exposé s'il est sous la racine du site ou dans un dossier web de l'hébergement (www, public_html...). */
function cle_chemin_expose(string $p): bool {
  $doc = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: ''; $app = realpath(__DIR__) ?: __DIR__;
  if ($doc !== '' && str_starts_with($p . '/', rtrim($doc, '/') . '/')) return true;
  if (str_starts_with($p . '/', rtrim($app, '/') . '/')) return true;
  return (bool)preg_match('#/(?:www|public_html|htdocs|httpdocs)(?:/|$)#', $p);
}
/** Place la clé dans un fichier hors du dossier web, puis config.php ne garde que le chemin (LPI_CLE_FICHIER). */
function cle_deplacer(): string {
  if (defined('LPI_CLE_FICHIER') && LPI_CLE_FICHIER !== '') { if (!cle_chemin_expose((string)LPI_CLE_FICHIER)) throw new RuntimeException('La clé est déjà hors du dossier web.'); return cle_fichier_deplacer(); }
  if (!defined('LPI_CLE')) throw new RuntimeException('Aucune clé AMAYA_CLE ou LPI_CLE dans config.php.');
  if (defined('LPI_CLES_ANCIENNES') && (array)LPI_CLES_ANCIENNES) throw new RuntimeException('Terminez d\'abord le changement de clé en cours.');
  $d = cle_dossier_hors_web(); if (!$d) throw new RuntimeException('Emplacement hors du dossier web introuvable sur cet hébergement : faites-le à la main (voir LISEZMOI).');
  if (!is_dir($d) && !@mkdir($d, 0700, true)) throw new RuntimeException('Création du dossier ' . $d . ' impossible : faites-le à la main (voir LISEZMOI).');
  @file_put_contents("$d/.htaccess", "Require all denied\n");
  $f = $d . '/cle-' . substr(hash('sha256', 'fichier|' . __DIR__), 0, 10) . '.txt'; $k = (string)LPI_CLE;
  if (is_file($f) && trim((string)file_get_contents($f)) !== $k) throw new RuntimeException('Un autre fichier de clé existe déjà à cet endroit : ' . $f);
  if (file_put_contents($f, $k . "\n") === false || trim((string)file_get_contents($f)) !== $k) throw new RuntimeException('Écriture du fichier de clé impossible.');
  @chmod($f, 0600);
  cle_config_ecrire(function (string $s) use ($f) {
    $re = "/define\\(\\s*'(?:LPI|AMAYA)_CLE'\\s*,\\s*'[^']*'\\s*\\);/"; if (!preg_match($re, $s)) throw new RuntimeException('Ligne de la clé (AMAYA_CLE ou LPI_CLE) introuvable dans config.php.');
    $s = cle_figer_graine($s);
    return preg_replace_callback($re, fn() => "define('AMAYA_CLE_FICHIER', " . var_export($f, true) . "); // clé des documents, hors du dossier web : copie à garder hors du serveur", $s, 1);
  });
  journal('clé de chiffrement', 'clé placée hors du dossier web'); return $f;
}
/** Nouvelle clé : l'actuelle rejoint les anciennes (toujours lisibles), la graine de l'installation est figée. Renvoie la nouvelle clé (base64), à noter hors du serveur. */
function cle_changer(): string {
  if (defined('LPI_CLE_FICHIER') && LPI_CLE_FICHIER !== '') throw new RuntimeException('Votre clé est dans un fichier hors du dossier web : écrivez la nouvelle clé dans ce fichier et mettez l\'ancienne dans LPI_CLES_ANCIENNES (voir LISEZMOI).');
  if (!defined('LPI_CLE')) throw new RuntimeException('Aucune clé AMAYA_CLE ou LPI_CLE dans config.php.');
  if (defined('LPI_CLES_ANCIENNES') && (array)LPI_CLES_ANCIENNES) throw new RuntimeException('Un changement de clé est déjà en cours : terminez le rechiffrement et retirez l\'ancienne clé d\'abord.');
  $nouvelle = base64_encode(random_bytes(32)); $ancienne = (string)LPI_CLE;
  cle_config_ecrire(function (string $s) use ($nouvelle, $ancienne) {
    $re = "/define\\(\\s*'(?:LPI|AMAYA)_CLE'\\s*,\\s*'[^']*'\\s*\\);/"; if (!preg_match($re, $s)) throw new RuntimeException('Ligne de la clé (AMAYA_CLE ou LPI_CLE) introuvable dans config.php.');
    $s = preg_replace_callback($re, fn() => "define('AMAYA_CLE', " . var_export($nouvelle, true) . ");\ndefine('AMAYA_CLES_ANCIENNES', [" . var_export($ancienne, true) . "]); // à retirer une fois le rechiffrement terminé (Maintenance)", $s, 1);
    $s = cle_figer_graine($s);
    return $s;
  });
  reglage_set('cle_rechiffrement', json_encode(['debut' => date('Y-m-d H:i'), 'fin' => null, 'faits' => 0, 'restants' => null, 'illisibles' => 0]));
  journal('clé de chiffrement', 'nouvelle clé mise en service, rechiffrement lancé');
  return $nouvelle;
}
/** Liste de tout ce qui est chiffré avec la clé des documents. */
function cle_elements(): array {
  $E = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(fiche_dir(), FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) if ($f->isFile() && !str_starts_with($f->getFilename(), '.')) $E[] = ['fichier', $f->getPathname()];
  require_once __DIR__ . '/sauvegarde_lib.php'; foreach (sv_liste() as $s) $E[] = ['fichier', sv_dir() . '/' . $s['nom']];
  foreach (db()->query("SELECT animatrice_id, data FROM fiches_animatrices")->fetchAll() as $r) if (str_contains((string)$r['data'], 'enc:')) $E[] = ['fiche', (int)$r['animatrice_id']];
  tf_schema(); foreach (db()->query("SELECT id FROM admins WHERE totp_secret <> ''")->fetchAll(PDO::FETCH_COLUMN) as $id) $E[] = ['admins', (int)$id];
  $c = db()->query("SHOW COLUMNS FROM comptes_clients LIKE 'totp_secret'")->fetch(); if ($c) foreach (db()->query("SELECT id FROM comptes_clients WHERE totp_secret <> ''")->fetchAll(PDO::FETCH_COLUMN) as $id) $E[] = ['comptes_clients', (int)$id];
  return $E;
}
/** Rechiffre sous la clé en service ce qui ne l'est pas encore, dans la limite de $secondes. */
function cle_rechiffrer(int $secondes = 60): array {
  $fin = time() + $secondes; $n = ['faits' => 0, 'deja' => 0, 'illisibles' => 0, 'restants' => 0];
  $re = function (string $b) use (&$n): ?string { $p = amy_dechiffre($b, $rang); if ($p === null) { $n['illisibles']++; return null; } if ($rang === 0) { $n['deja']++; return null; } $n['faits']++; return amy_chiffre($p); };
  foreach (cle_elements() as [$type, $id]) {
    if (time() > $fin) { $n['restants']++; continue; }
    if ($type === 'fichier') { $x = $re((string)file_get_contents($id)); if ($x !== null) { $t = $id . '.tmp'; file_put_contents($t, $x); @chmod($t, fileperms($id) & 0777); rename($t, $id); } }
    elseif ($type === 'fiche') {
      $st = db()->prepare("SELECT data FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$id]); $d = json_decode((string)$st->fetchColumn(), true) ?: []; $ch = false;
      foreach (['secu', 'iban'] as $k) if (str_starts_with((string)($d[$k] ?? ''), 'enc:') && ($x = $re((string)base64_decode(substr($d[$k], 4)))) !== null) { $d[$k] = 'enc:' . base64_encode($x); $ch = true; }
      if ($ch) db()->prepare("UPDATE fiches_animatrices SET data = ? WHERE animatrice_id = ?")->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $id]);
    } else {
      $st = db()->prepare("SELECT totp_secret FROM $type WHERE id = ?"); $st->execute([$id]);
      if (($x = $re((string)base64_decode((string)$st->fetchColumn()))) !== null) db()->prepare("UPDATE $type SET totp_secret = ? WHERE id = ?")->execute([base64_encode($x), $id]);
    }
  }
  $e = json_decode((string)reglage('cle_rechiffrement', ''), true) ?: ['debut' => date('Y-m-d H:i'), 'faits' => 0];
  $e['faits'] = (int)($e['faits'] ?? 0) + $n['faits']; $e['restants'] = $n['restants']; $e['illisibles'] = $n['illisibles']; $e['passe'] = date('Y-m-d H:i');
  if ($n['restants'] === 0 && empty($e['fin'])) { $e['fin'] = date('Y-m-d H:i'); journal('clé de chiffrement', 'rechiffrement terminé : ' . $e['faits'] . ' élément(s)' . ($n['illisibles'] ? ', ' . $n['illisibles'] . ' illisible(s)' : '')); }
  reglage_set('cle_rechiffrement', json_encode($e)); return $n;
}
/** Retire l'ancienne clé de config.php, seulement si tout est rechiffré. */
function cle_retirer_anciennes(): void {
  if (!defined('LPI_CLES_ANCIENNES') || !(array)LPI_CLES_ANCIENNES) return;
  $n = cle_rechiffrer(240); if ($n['restants'] > 0) throw new RuntimeException('Rechiffrement pas encore terminé : relancez dans quelques minutes.');
  cle_config_ecrire(fn(string $s) => preg_replace("/\\ndefine\\(\\s*'(?:LPI|AMAYA)_CLES_ANCIENNES'.*\\n?/", "\n", $s, 1));
  journal('clé de chiffrement', 'ancienne clé retirée de config.php');
}
/** Tâche planifiée : poursuit un rechiffrement en cours. Ne bloque jamais l'appli. */
function cle_auto(int $secondes): void { if (!defined('LPI_CLES_ANCIENNES') || !(array)LPI_CLES_ANCIENNES) return; try { cle_rechiffrer($secondes); } catch (Throwable $e) { error_log('Amaya rechiffrement : ' . $e->getMessage()); } }
/** Fichier de clé déjà sorti de config.php mais rangé dans un dossier web (versions avant 1.9.1) : il est déplacé dans le dossier du compte,
 *  config.php est mis à jour, puis l'ancien fichier (et les copies d'anciennes clés posées à côté) est supprimé. La graine est déjà figée : personne n'est déconnecté. */
function cle_fichier_deplacer(): string {
  $src = (string)LPI_CLE_FICHIER; $d = cle_dossier_hors_web(); if (!$d) throw new RuntimeException('Emplacement hors du dossier web introuvable sur cet hébergement : faites-le à la main (voir LISEZMOI).');
  $k = trim(strtok((string)@file_get_contents($src), "\r\n")); if (strlen((string)base64_decode($k, true)) !== 32) throw new RuntimeException('Fichier de clé actuel illisible : rien n\'a été déplacé.');
  if (!is_dir($d) && !@mkdir($d, 0700, true)) throw new RuntimeException('Création du dossier ' . $d . ' impossible.');
  @file_put_contents("$d/.htaccess", "Require all denied\n");
  $f = $d . '/' . basename($src);
  if (is_file($f) && trim(strtok((string)file_get_contents($f), "\r\n")) !== $k) throw new RuntimeException('Un autre fichier de clé existe déjà à cet endroit : ' . $f);
  if (file_put_contents($f, $k . "\n") === false || trim((string)file_get_contents($f)) !== $k) throw new RuntimeException('Écriture du fichier de clé impossible.');
  @chmod($f, 0600);
  cle_config_ecrire(function (string $s) use ($src, $f) {
    $re = "/define\\(\\s*'(?:LPI|AMAYA)_CLE_FICHIER'\\s*,\\s*'[^']*'\\s*\\);/"; if (!preg_match($re, $s)) throw new RuntimeException('Ligne AMAYA_CLE_FICHIER introuvable dans config.php.');
    return preg_replace_callback($re, fn() => "define('AMAYA_CLE_FICHIER', " . var_export($f, true) . ");", $s, 1);
  });
  @unlink($src); foreach (glob(dirname($src) . '/ancienne-cle-uploads-*.txt') ?: [] as $a) { if (@copy($a, $d . '/' . basename($a))) { @chmod($d . '/' . basename($a), 0600); @unlink($a); } }
  journal('clé de chiffrement', 'fichier de clé déplacé hors des dossiers web'); return $f;
}
