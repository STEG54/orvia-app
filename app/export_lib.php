<?php
// Exports de données :
//  - d'une personne (droit d'accès et de portabilité RGPD) : ses données en clair, ses documents déchiffrés, ses photos de profil ;
//  - de toute l'agence (réversibilité, fin de contrat) : base complète, fichiers, et documents déchiffrés, dans un ZIP chiffré (AES-256)
//    dont le mot de passe n'est montré qu'une fois.
// Les fichiers produits sont rangés dans le dossier protégé des sauvegardes et effacés au bout de 24 h.
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/sauvegarde_lib.php';
require_once __DIR__ . '/version.php';

/** Tables qui concernent une personne (colonne animatrice_id, ou aid), hors données purement techniques. */
function exp_tables_personne(): array {
  $T = [];
  foreach (db()->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('animatrice_id', 'aid')")->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
    $p = demo_prefixe(); if ($p !== '') { if (!str_starts_with($t, $p)) continue; $t = substr($t, strlen($p)); } elseif (preg_match('/^d\d+_/', $t)) continue;
    if (in_array($t, ['jetons', 'push_abos', 'brouillons', 'brouillons_ba', 'contrats_brouillons'], true)) continue; $T[$t] = $c;
  }
  ksort($T); return $T;
}
function exp_csv(array $rows): string {
  if (!$rows) return '';
  $o = fopen('php://temp', 'w+'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, array_keys($rows[0]), ';');
  foreach ($rows as $r) fputcsv($o, array_map(fn($v) => is_scalar($v) || $v === null ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE), $r), ';');
  rewind($o); $s = (string)stream_get_contents($o); fclose($o); return $s;
}
/** Nom de fichier sûr dans un ZIP. */
function exp_nom(string $s): string { $s = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $s); return trim($s, ' .') ?: 'fichier'; }
function exp_dossier(): string { return sv_dir(); }
/** Efface les exports de plus de 24 h. */
function exp_menage(): void { foreach (glob(exp_dossier() . '/export-*.zip') ?: [] as $f) if (filemtime($f) < time() - 86400) @unlink($f); }

/** ZIP des données d'une personne. Renvoie [chemin, nom de téléchargement]. */
function exp_personne(int $aid): array {
  exp_menage(); $st = db()->prepare("SELECT * FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch(); if (!$a) throw new RuntimeException('Intervenant(e) introuvable.');
  $f = exp_dossier() . '/export-personne-' . $aid . '-' . bin2hex(random_bytes(4)) . '.zip'; $z = new ZipArchive();
  if ($z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Création du fichier d\'export impossible.');
  $F = fiche_get($aid); $d = (array)$F['data']; foreach (['secu', 'iban'] as $k) if (($d[$k] ?? '') !== '') $d[$k] = secu_clair((string)$d[$k]);
  $id = ['personne' => array_diff_key($a, ['code' => 1]), 'fiche' => $d, 'notes_de_l_agence' => $F['notes_admin'] ?? '', 'export' => ['agence' => AG_NOM, 'le' => date('Y-m-d H:i')]];
  $z->addFromString('identite.json', json_encode($id, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $n = 0;
  foreach (exp_tables_personne() as $t => $c) { $q = db()->prepare("SELECT * FROM `$t` WHERE `$c` = ?"); $q->execute([$aid]); $rows = $q->fetchAll();
    if ($t === 'fiches_animatrices') continue; // déjà dans identite.json, en clair
    if ($rows) { $z->addFromString("donnees/$t.csv", exp_csv($rows)); $n += count($rows); } }
  // Documents : pièces, CV, contrats signés, justificatifs de frais (déchiffrés).
  $doc = function (string $fichier, string $dest) use ($z) { $p = fiche_dir() . '/' . basename($fichier); if ($fichier === '' || !is_file($p)) return;
    $c = (string)file_get_contents($p); $x = amy_dechiffre($c); $z->addFromString($dest, $x ?? $c); };
  $q = db()->prepare("SELECT id, type, nom, fichier FROM pieces_identite WHERE animatrice_id = ?"); $q->execute([$aid]);
  foreach ($q->fetchAll() as $p) $doc((string)$p['fichier'], 'documents/pieces/' . (int)$p['id'] . '_' . exp_nom((string)($p['nom'] ?: $p['type'])));
  $q = db()->prepare("SELECT cv, cv_nom, photo, book FROM fiches_animatrices WHERE animatrice_id = ?"); $q->execute([$aid]); $fi = $q->fetch() ?: [];
  if (($fi['cv'] ?? '') !== '') $doc((string)$fi['cv'], 'documents/cv_' . exp_nom((string)($fi['cv_nom'] ?: 'cv')));
  try { $q = db()->prepare("SELECT numero, avenant, pdf_fichier FROM contrats WHERE animatrice_id = ? AND pdf_fichier IS NOT NULL AND pdf_fichier <> ''"); $q->execute([$aid]);
    foreach ($q->fetchAll() as $c) $doc((string)$c['pdf_fichier'], 'documents/contrats/contrat_' . exp_nom((string)$c['numero']) . ((int)$c['avenant'] ? '_avenant' . (int)$c['avenant'] : '') . '.pdf'); } catch (Throwable $e) {}
  try { $q = db()->prepare("SELECT id, jour, fichier FROM ndf WHERE animatrice_id = ? AND fichier <> ''"); $q->execute([$aid]);
    foreach ($q->fetchAll() as $x) $doc((string)$x['fichier'], 'documents/frais/' . $x['jour'] . '_' . (int)$x['id'] . '_' . exp_nom(basename((string)$x['fichier']))); } catch (Throwable $e) {}
  // Photos de profil et book.
  foreach (array_filter(array_merge([(string)($fi['photo'] ?? '')], (array)json_decode((string)($fi['book'] ?? ''), true))) as $ph)
    if (preg_match('/^[a-f0-9]{32}$/', (string)$ph) && is_file($p = upload_dir() . "/$ph.jpg")) $z->addFile($p, "photos/$ph.jpg");
  $z->addFromString('LISEZMOI.txt', "Données de " . $a['prenom'] . ' ' . $a['nom'] . " détenues par " . AG_NOM . ", exportées le " . date('d/m/Y à H:i') . ".\n\n"
    . "identite.json : coordonnées, fiche (dont n° de sécurité sociale et IBAN en clair), notes de l'agence.\n"
    . "donnees/ : une table par fichier (CSV, séparateur ;) : missions, contrats, relevés, comptes rendus, frais, paie, etc. ($n lignes).\n"
    . "documents/ : pièces, CV, contrats signés, justificatifs, déchiffrés.\nphotos/ : photo de profil et book.\n\n"
    . "Ce fichier contient des données personnelles sensibles : à transmettre à la personne seulement, par un moyen sûr.\n");
  if (!$z->close()) throw new RuntimeException('Écriture du fichier d\'export impossible (espace disque ?).');
  @chmod($f, 0600); journal('export', 'données de l\'intervenant(e) n° ' . $aid);
  return [$f, 'Donnees_' . exp_nom($a['prenom'] . '_' . $a['nom']) . '_' . date('Y-m-d') . '.zip'];
}

/** ZIP de toute l'agence, chiffré AES-256 : base (SQL), fichiers (photos, logos), documents déchiffrés, config.php sans secrets techniques.
 *  Renvoie [chemin, nom de téléchargement, mot de passe]. */
function exp_agence(): array {
  exp_menage(); @set_time_limit(600);
  if (!defined('ZipArchive::EM_AES_256')) throw new RuntimeException('Chiffrement des ZIP indisponible sur ce serveur (libzip trop ancienne).');
  $mdp = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  $gzf = sv_dir() . '/.en-cours-' . bin2hex(random_bytes(4)) . '.gz'; sv_ecrire_gz($gzf, 'export complet');
  $f = exp_dossier() . '/export-agence-' . bin2hex(random_bytes(4)) . '.zip'; $z = new ZipArchive();
  if ($z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Création du fichier d\'export impossible.');
  $z->setPassword($mdp); $chiffre = function (string $n) use ($z) { $z->setEncryptionName($n, ZipArchive::EM_AES_256); };
  $z->addFile($gzf, 'base/base.sql.gz'); $chiffre('base/base.sql.gz');
  // Fichiers : photos et logos tels quels ; documents chiffrés rendus en clair.
  $racine = upload_dir(); $nb = 0;
  $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS), fn($x) => !($x->isDir() && str_starts_with($x->getFilename(), '.'))));
  foreach ($it as $x) { if (!$x->isFile() || str_starts_with($x->getFilename(), '.')) continue; $rel = substr($x->getPathname(), strlen($racine) + 1);
    if (str_starts_with($rel, 'fiches/')) { $c = (string)file_get_contents($x->getPathname()); $d = amy_dechiffre($c); $n = 'documents/' . substr($rel, 7); $z->addFromString($n, $d ?? $c); }
    else { $n = 'fichiers/' . $rel; $z->addFile($x->getPathname(), $n); }
    $chiffre($n); $nb++; }
  // Données des fiches en clair (n° de sécurité sociale, IBAN), pour reprise dans un autre logiciel.
  $rows = []; foreach (db()->query("SELECT f.animatrice_id, a.prenom, a.nom, a.email, f.data FROM fiches_animatrices f JOIN animatrices a ON a.id = f.animatrice_id")->fetchAll() as $r) {
    $d = json_decode((string)$r['data'], true) ?: []; foreach (['secu', 'iban'] as $k) if (($d[$k] ?? '') !== '') $d[$k] = secu_clair((string)$d[$k]);
    $rows[] = ['id' => $r['animatrice_id'], 'prenom' => $r['prenom'], 'nom' => $r['nom'], 'email' => $r['email']] + array_map(fn($v) => is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE), $d); }
  if ($rows) { $keys = []; foreach ($rows as $r) $keys += array_flip(array_keys($r)); $rows = array_map(fn($r) => array_merge(array_fill_keys(array_keys($keys), ''), $r), $rows);
    $z->addFromString('base/fiches_en_clair.csv', exp_csv($rows)); $chiffre('base/fiches_en_clair.csv'); }
  $z->addFromString('LISEZMOI.txt', "Export complet de " . AG_NOM . " (Amaya " . AMAYA_VERSION . "), le " . date('d/m/Y à H:i') . ".\n\n"
    . "base/base.sql.gz : la base complète (MySQL/MariaDB). Les n° de sécurité sociale et IBAN y restent chiffrés ; leur version en clair est dans base/fiches_en_clair.csv.\n"
    . "documents/ : pièces d'identité, CV, contrats signés, justificatifs, déchiffrés ($nb fichiers au total avec les photos).\nfichiers/ : photos, logos et autres fichiers de l'agence.\n\n"
    . "Tout le contenu est chiffré (AES-256) : ouvrez-le avec 7-Zip ou WinRAR et le mot de passe affiché une seule fois dans Amaya.\n");
  $ok = $z->close(); @unlink($gzf); if (!$ok) throw new RuntimeException('Écriture du fichier d\'export impossible (espace disque ?).');
  @chmod($f, 0600); journal('export', 'export complet de l\'agence (' . $nb . ' fichiers)');
  return [$f, 'Export_' . exp_nom(AG_COURT) . '_' . date('Y-m-d') . '.zip', $mdp];
}
/** Remise d'un export préparé (nom contrôlé), puis suppression immédiate du fichier. */
function exp_remettre(string $nom, string $tel): void {
  if (!preg_match('/^export-(personne-\d+|agence)-[a-f0-9]{8}\.zip$/', $nom) || !is_file($p = exp_dossier() . '/' . $nom)) { http_response_code(404); exit('Export introuvable ou expiré : relancez-le.'); }
  header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="' . str_replace('"', '', $tel) . '"'); header('Content-Length: ' . filesize($p)); header('Cache-Control: no-store');
  readfile($p); @unlink($p); exit;
}
