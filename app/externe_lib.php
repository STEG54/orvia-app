<?php
// Copie des sauvegardes hors du serveur : base de données, fichiers (photos, documents) et config.php, envoyés dans un stockage
// objet compatible S3 (OVHcloud Object Storage, ou tout autre fournisseur S3 de l'Union européenne), chiffrés avec une clé
// DISTINCTE de celle de l'agence. Une fuite chez le fournisseur de stockage ne suffit pas à lire les données.
//
// Réglage dans config.php (les six premiers obligatoires) :
//   define('SV_S3_ENDPOINT', 'https://s3.<region>.io.cloud.ovh.net');  // adresse du service S3
//   define('SV_S3_REGION', '<region>');                                // ex. gra, sbg, rbx, de
//   define('SV_S3_BUCKET', 'amaya-sauvegardes');                       // conteneur (bucket) créé chez le fournisseur
//   define('SV_S3_CLE_ACCES', '…'); define('SV_S3_SECRET', '…');       // identifiants S3 limités à ce conteneur
//   define('SV_CLE_EXTERNE', '…');                                     // 32 octets en base64, générés par outils/cle-externe.php
//   define('SV_S3_PREFIXE', 'agence-x');                               // facultatif : dossier de l'agence dans le conteneur
//   define('SV_S3_STYLE', 'chemin');                                   // facultatif : 'chemin' (défaut) ou 'hote'
// La clé SV_CLE_EXTERNE et une copie de config.php doivent AUSSI être gardées hors du serveur : sans elles, aucune restauration.
require_once __DIR__ . '/sauvegarde_lib.php';

require_once __DIR__ . '/stockage_lib.php';
/** Dossier de l'agence dans le conteneur : réglé, sinon déduit du nom court et de la clé (stable, unique par installation). */
function sx_prefixe(): string {
  if (defined('SV_S3_PREFIXE') && SV_S3_PREFIXE !== '') return trim(preg_replace('/[^a-z0-9._-]+/', '-', strtolower((string)SV_S3_PREFIXE)), '-');
  return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', AG_COURT) ?: 'agence')), '-') . '-' . substr(hash('sha256', instance_graine()), 0, 8);
}

/* ---------- Envoi ---------- */
function sx_etat_fichier(): string { return sv_dir() . '/.externe.json'; }
function sx_etat(): array { $j = json_decode((string)@file_get_contents(sx_etat_fichier()), true); return is_array($j) ? $j + ['base' => [], 'fichiers' => [], 'config' => ''] : ['base' => [], 'fichiers' => [], 'config' => '']; }
function sx_etat_ecrire(array $e): void { @file_put_contents(sx_etat_fichier(), json_encode($e), LOCK_EX); @chmod(sx_etat_fichier(), 0600); }
/** Fichiers de l'agence à copier : tout uploads/ sauf les dossiers techniques (sessions, sauvegardes locales). */
function sx_fichiers_locaux(): array {
  $racine = upload_dir(); $o = [];
  $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
    fn($f) => !($f->isDir() && str_starts_with($f->getFilename(), '.'))));
  foreach ($it as $f) { if (!$f->isFile()) continue; $n = $f->getFilename(); $rel = substr($f->getPathname(), strlen($racine) + 1);
    if (str_starts_with($n, '.') && $rel !== 'fiches/.cle') continue; $o[$rel] = [$f->getSize(), $f->getMTime()]; }
  return $o;
}
/** Envoi incrémental dans la limite de $secondes : sauvegardes de la base pas encore envoyées, config.php s'il a changé, fichiers nouveaux ou modifiés. */
function sx_envoyer(int $secondes = 60): array {
  if (!sx_actif()) throw new RuntimeException('Copie hors du serveur non réglée (voir config.php).');
  $fin = time() + $secondes; $P = sx_prefixe(); $E = sx_etat(); $n = ['base' => 0, 'fichiers' => 0, 'supprimes' => 0, 'reste' => 0];
  // 1. Base : chaque sauvegarde locale, rechiffrée avec la clé externe.
  foreach (array_reverse(sv_liste()) as $s) { if (isset($E['base'][$s['nom']])) continue; if (time() > $fin) break;
    sx_put("$P/base/" . str_replace('.enc', '', $s['nom']) . '.amx', sx_chiffre(sv_lire($s['nom']))); $E['base'][$s['nom']] = time(); $n['base']++; sx_etat_ecrire($E); }
  // Rotation distante : 14 dernières + la première de chaque mois sur 12 mois (comme sur le serveur).
  $dist = array_keys(sx_liste("$P/base/")); rsort($dist); $garde = array_slice($dist, 0, 14); $mois = [];
  foreach (array_reverse($dist) as $k) { $m = substr(basename($k), 6, 6); if (!isset($mois[$m]) && count($mois) < 12) { $mois[$m] = 1; $garde[] = $k; } }
  foreach (array_diff($dist, $garde) as $k) sx_del($k);
  $E['base'] = array_intersect_key($E['base'], array_flip(array_column(sv_liste(), 'nom')));
  // 2. config.php (contient la clé des documents : indispensable à la restauration).
  $cf = __DIR__ . '/config.php'; $kf = defined('LPI_CLE_FICHIER') && LPI_CLE_FICHIER !== '' && is_readable(LPI_CLE_FICHIER) ? (string)LPI_CLE_FICHIER : '';
  $h = (is_file($cf) ? hash_file('sha256', $cf) : '') . ($kf ? hash_file('sha256', $kf) : '');
  if (is_file($cf) && $h !== $E['config']) { sx_put("$P/config/config.php.amx", sx_chiffre((string)file_get_contents($cf)));
    if ($kf) sx_put("$P/config/cle.amx", sx_chiffre((string)file_get_contents($kf))); // clé gardée hors du dossier web : copiée elle aussi
    $E['config'] = $h; }
  // 3. Fichiers : nouveaux ou modifiés.
  $L = sx_fichiers_locaux();
  foreach ($L as $rel => [$taille, $mt]) { $deja = $E['fichiers'][$rel] ?? null; if ($deja && $deja[0] === $taille && $deja[1] === $mt && empty($deja[2])) continue;
    if (time() > $fin) { $n['reste']++; continue; }
    sx_put("$P/fichiers/$rel.amx", sx_chiffre((string)file_get_contents(upload_dir() . '/' . $rel))); $E['fichiers'][$rel] = [$taille, $mt]; $n['fichiers']++;
    if ($n['fichiers'] % 50 === 0) sx_etat_ecrire($E); }
  // Fichiers supprimés sur le serveur (purge, effacement) : retirés du stockage externe après 35 jours.
  foreach ($E['fichiers'] as $rel => $v) { if (isset($L[$rel])) continue; if (empty($v[2])) { $E['fichiers'][$rel][2] = time(); continue; }
    if ($v[2] < time() - 35 * 86400 && time() <= $fin) { sx_del("$P/fichiers/$rel.amx"); unset($E['fichiers'][$rel]); $n['supprimes']++; } }
  $E['dernier_envoi'] = time(); $E['dernier_bilan'] = $n; $E['erreur'] = ''; sx_etat_ecrire($E);
  return $n;
}

/* ---------- Vérification de restauration ---------- */
/** Relit la dernière sauvegarde de la base depuis le stockage externe et un échantillon de fichiers : déchiffrement, contenu, empreintes. */
function sx_verifier(int $echantillon = 20): array {
  if (!sx_actif()) throw new RuntimeException('Copie hors du serveur non réglée.');
  $P = sx_prefixe(); $R = ['le' => date('Y-m-d H:i'), 'ok' => false, 'details' => []];
  $dist = array_keys(sx_liste("$P/base/")); rsort($dist);
  if (!$dist) { $R['details'][] = 'Aucune sauvegarde de la base sur le stockage externe.'; return sx_verif_noter($R); }
  $gz = sx_dechiffre(sx_get($dist[0])); $inv = $gz === null ? null : sv_inventaire($gz); unset($gz);
  if (!$inv) { $R['details'][] = 'Dernière sauvegarde illisible (clé externe différente ou fichier abîmé).'; return sx_verif_noter($R); }
  $tables = $inv['tables'];
  $vivantes = db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN); $manque = array_diff($vivantes, $tables);
  // Tables créées après cette sauvegarde (mise à jour d'Amaya entre-temps) : pas un défaut de la copie.
  if ($manque && preg_match('/amaya-(\d{8})-(\d{4})/', basename($dist[0]), $dm)) { $quand = DateTime::createFromFormat('YmdHi', $dm[1] . $dm[2]);
    $ct = db()->prepare("SELECT CREATE_TIME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $manque = array_values(array_filter($manque, function ($t) use ($ct, $quand) { $ct->execute([$t]); $c = $ct->fetchColumn(); return !$c || !$quand || new DateTime($c) <= $quand; })); }
  $R['details'][] = basename($dist[0]) . ' : ' . count($tables) . ' tables, ' . number_format($inv['taille'] / 1048576, 1, ',', ' ') . ' Mo une fois déchiffrée' . ($manque ? ', tables absentes : ' . implode(', ', array_slice($manque, 0, 5)) : '');
  $cfg = sx_dechiffre(sx_get("$P/config/config.php.amx")); $kok = $cfg !== null && (preg_match("/define\\('(?:LPI|AMAYA)_CLE',/", $cfg) || (preg_match('/(LPI|AMAYA)_CLE_FICHIER/', $cfg) && (function () use ($P) { try { return sx_dechiffre(sx_get("$P/config/cle.amx")) !== null; } catch (Throwable $e) { return false; } })()));
  $R['details'][] = $cfg === null ? 'config.php absent ou illisible' : ($kok ? 'config.php relu (clé des documents présente)' : 'config.php relu, mais SANS la clé des documents');
  $E = sx_etat(); $vus = array_keys(array_filter($E['fichiers'], fn($v) => empty($v[2]))); shuffle($vus); $bons = 0; $testes = 0;
  foreach (array_slice($vus, 0, $echantillon) as $rel) { $loc = upload_dir() . '/' . $rel; if (!is_file($loc)) continue; $testes++;
    try { $d = sx_dechiffre(sx_get("$P/fichiers/$rel.amx")); if ($d !== null && hash('sha256', $d) === hash_file('sha256', $loc)) $bons++; else $R['details'][] = "Fichier différent : $rel"; }
    catch (Throwable $e) { $R['details'][] = "Fichier absent : $rel"; } }
  $R['details'][] = "$bons fichier" . ($bons > 1 ? 's' : '') . " sur $testes relu" . ($bons > 1 ? 's' : '') . ' à l\'identique (échantillon) ; ' . count($vus) . ' fichiers copiés au total';
  $R['ok'] = !$manque && $cfg !== null && $kok && $bons === $testes;
  return sx_verif_noter($R);
}
function sx_verif_noter(array $R): array { @file_put_contents(sv_dir() . '/.externe-verif.json', json_encode($R, JSON_UNESCAPED_UNICODE)); return $R; }
function sx_derniere_verif(): ?array { $j = json_decode((string)@file_get_contents(sv_dir() . '/.externe-verif.json'), true); return is_array($j) ? $j : null; }

/** Appelé par cron.php (et après le tableau de bord) : envoi incrémental, vérification automatique chaque semaine. Ne bloque jamais l'appli. */
function sx_auto(int $secondes): void {
  if (!sx_actif()) return;
  try { sx_envoyer($secondes);
    $v = sx_derniere_verif(); if (!$v || strtotime($v['le']) < time() - 7 * 86400) sx_verifier(); }
  catch (Throwable $e) { $E = sx_etat(); $E['erreur'] = date('Y-m-d H:i') . ' : ' . $e->getMessage(); sx_etat_ecrire($E); error_log('Amaya copie externe : ' . $e->getMessage()); }
}
