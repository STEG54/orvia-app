<?php
// Mises à jour sans FTP : paquet officiel Amaya (zip) signé par l'éditeur (Ed25519), vérifié fichier par fichier,
// appliqué après une sauvegarde de la base et des fichiers remplacés. config.php, uploads/ et prive/ ne sont jamais touchés.
require_once __DIR__ . '/sauvegarde_lib.php';
require_once __DIR__ . '/version.php';

// Clés publiques de l'éditeur acceptées (la plus récente en premier).
const MAJ_CLES = ['fyCQPPOUsIExaM1o0Ko0NyOJHFBYV0JI7iU+67oZvkw='];
// Fichiers et dossiers jamais écrasés ni supprimés par une mise à jour.
const MAJ_PROTEGES = ['config.php', 'installation.php', 'uploads/', 'prive/', '.user.ini', '.ovhconfig'];

function maj_protege(string $p): bool { foreach (MAJ_PROTEGES as $x) if ($p === $x || (str_ends_with($x, '/') && str_starts_with($p, $x))) return true; return false; }
function maj_chemin_ok(string $p): bool { return $p !== '' && !str_contains($p, '..') && !str_starts_with($p, '/') && !str_contains($p, '\\') && preg_match('#^[A-Za-z0-9_./-]+$#', $p); }

/** Ouvre et vérifie un paquet : signature de l'éditeur, version, empreinte de chaque fichier. */
function maj_verifier(string $zip): array {
  if (!class_exists('ZipArchive')) throw new RuntimeException('Extension PHP « zip » absente sur l\'hébergement : mise à jour par FTP nécessaire.');
  if (!function_exists('sodium_crypto_sign_verify_detached')) throw new RuntimeException('Extension PHP « sodium » absente : impossible de vérifier la signature.');
  $z = new ZipArchive(); if ($z->open($zip) !== true) throw new RuntimeException('Fichier zip illisible.');
  $man = $z->getFromName('amaya-manifeste.json'); $sig = $z->getFromName('amaya-manifeste.sig');
  if ($man === false || $sig === false) { $z->close(); throw new RuntimeException('Ce fichier n\'est pas un paquet de mise à jour Amaya.'); }
  $ok = false; $sigb = base64_decode(trim($sig), true) ?: '';
  foreach (MAJ_CLES as $k) if (strlen($sigb) === SODIUM_CRYPTO_SIGN_BYTES && sodium_crypto_sign_verify_detached($sigb, $man, base64_decode($k))) { $ok = true; break; }
  if (!$ok) { $z->close(); throw new RuntimeException('Signature invalide : ce paquet ne vient pas de l\'éditeur d\'Amaya ou a été modifié. Mise à jour refusée.'); }
  $M = json_decode($man, true); if (!is_array($M) || empty($M['version']) || !is_array($M['fichiers'] ?? null)) { $z->close(); throw new RuntimeException('Manifeste du paquet invalide.'); }
  foreach ($M['fichiers'] as $p => $h) {
    if (!maj_chemin_ok($p) || maj_protege($p)) { $z->close(); throw new RuntimeException('Chemin interdit dans le paquet : ' . $p); }
    $c = $z->getFromName('fichiers/' . $p); if ($c === false || !hash_equals((string)$h, hash('sha256', $c))) { $z->close(); throw new RuntimeException('Fichier altéré ou manquant dans le paquet : ' . $p); }
  }
  foreach ((array)($M['supprimes'] ?? []) as $p) if (!maj_chemin_ok($p) || maj_protege($p)) { $z->close(); throw new RuntimeException('Suppression interdite : ' . $p); }
  $z->close();
  $M['changes'] = count(array_filter(array_keys($M['fichiers']), fn($p) => !is_file(__DIR__ . '/' . $p) || hash_file('sha256', __DIR__ . '/' . $p) !== $M['fichiers'][$p]));
  return $M;
}

/** Applique un paquet vérifié. Avant : sauvegarde de la base et zip des fichiers remplacés (retour arrière possible). */
function maj_appliquer(string $zip): array {
  @set_time_limit(300); $M = maj_verifier($zip);
  if (version_compare($M['version'], AMAYA_VERSION, '<') && empty($M['retour'])) throw new RuntimeException('Ce paquet (' . $M['version'] . ') est plus ancien que la version installée (' . AMAYA_VERSION . ').');
  if (!empty($M['php_min']) && version_compare(PHP_VERSION, $M['php_min'], '<')) throw new RuntimeException('Cette version demande PHP ' . $M['php_min'] . ' au minimum (hébergement : ' . PHP_VERSION . ').');
  $sv = sv_faire('avant mise à jour ' . $M['version']);
  // Copie des fichiers qui vont être remplacés ou supprimés.
  $bk = sv_dir() . '/fichiers-' . AMAYA_VERSION . '-' . date('Ymd-His') . '.zip'; $b = new ZipArchive(); $b->open($bk, ZipArchive::CREATE);
  foreach (array_merge(array_keys($M['fichiers']), (array)($M['supprimes'] ?? [])) as $p) if (is_file(__DIR__ . '/' . $p)) $b->addFile(__DIR__ . '/' . $p, $p);
  $b->close(); @chmod($bk, 0600);
  // Écriture : fichier temporaire puis renommage (jamais de fichier à moitié écrit).
  $z = new ZipArchive(); $z->open($zip); $n = 0;
  foreach ($M['fichiers'] as $p => $h) {
    $dst = __DIR__ . '/' . $p; if (is_file($dst) && hash_file('sha256', $dst) === $h) continue;
    if (!is_dir(dirname($dst))) @mkdir(dirname($dst), 0755, true);
    $tmp = $dst . '.maj-' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $z->getFromName('fichiers/' . $p)) === false || !@rename($tmp, $dst)) { @unlink($tmp); $z->close(); throw new RuntimeException('Écriture impossible : ' . $p . ' (droits du dossier). Restaurez avec ' . basename($bk) . ' si besoin.'); }
    $n++;
  }
  $z->close();
  foreach ((array)($M['supprimes'] ?? []) as $p) if (is_file(__DIR__ . '/' . $p)) @unlink(__DIR__ . '/' . $p);
  foreach (glob(upload_dir() . '/.schema*') ?: [] as $f) @unlink($f); // la base se met à jour au prochain chargement
  if (function_exists('opcache_reset')) @opcache_reset();
  db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('maj_derniere', ?)")->execute([json_encode(['de' => AMAYA_VERSION, 'vers' => $M['version'], 'le' => date('Y-m-d H:i'), 'par' => admin_nom(), 'fichiers' => $n], JSON_UNESCAPED_UNICODE)]);
  return ['version' => $M['version'], 'fichiers' => $n, 'sauvegarde' => $sv['nom'], 'copie' => basename($bk), 'notes' => (string)($M['notes'] ?? '')];
}

/** Recherche d'une nouvelle version sur le serveur de l'éditeur (AMAYA_MAJ_URL dans config.php, facultatif). */
function maj_distante(): ?array {
  if (!defined('AMAYA_MAJ_URL') || !AMAYA_MAJ_URL) return null;
  $j = @file_get_contents(rtrim(AMAYA_MAJ_URL, '/') . '/latest.json', false, stream_context_create(['http' => ['timeout' => 8]]));
  $d = $j ? json_decode($j, true) : null; return is_array($d) && !empty($d['version']) && !empty($d['url']) ? $d : null;
}
function maj_telecharger(string $url): string {
  if (!preg_match('#^https://#', $url)) throw new RuntimeException('Adresse de téléchargement refusée (https obligatoire).');
  $c = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 60]])); if ($c === false || strlen($c) < 100) throw new RuntimeException('Téléchargement impossible : réessayez ou déposez le paquet à la main.');
  $f = sv_dir() . '/paquet-' . bin2hex(random_bytes(6)) . '.zip'; file_put_contents($f, $c); return $f;
}
