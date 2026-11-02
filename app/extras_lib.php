<?php
// Notifications sur le téléphone (Web Push), disponibilités, notes de frais, échéances des documents, fins de contrat.
require_once __DIR__ . '/gestion_lib.php';

function x_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; $pdo = db(); $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $pdo->exec("CREATE TABLE IF NOT EXISTS push_abos (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, endpoint VARCHAR(700) NOT NULL, p256dh VARCHAR(200) NOT NULL DEFAULT '',
    auth VARCHAR(100) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, dernier_envoi DATETIME NULL, UNIQUE KEY (endpoint(255)), INDEX (animatrice_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS notifs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, titre VARCHAR(160) NOT NULL, texte VARCHAR(400) NOT NULL DEFAULT '',
    vue VARCHAR(20) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, affiche_le DATETIME NULL, INDEX (animatrice_id, id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS dispos (animatrice_id INT UNSIGNED NOT NULL, jour DATE NOT NULL, etat CHAR(1) NOT NULL, modifie_le DATETIME NOT NULL, PRIMARY KEY (animatrice_id, jour), INDEX (jour)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS ndf (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, jour DATE NOT NULL, type VARCHAR(12) NOT NULL, montant DECIMAL(10,2) NOT NULL,
    devis_id INT UNSIGNED NULL, magasin VARCHAR(160) NOT NULL DEFAULT '', commentaire VARCHAR(500) NOT NULL DEFAULT '', fichier VARCHAR(80) NOT NULL DEFAULT '', mime VARCHAR(60) NOT NULL DEFAULT '',
    statut VARCHAR(10) NOT NULL DEFAULT 'a_valider', motif VARCHAR(300) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', decide_le DATETIME NULL, paye_mois CHAR(7) NULL,
    created_at DATETIME NOT NULL, INDEX (animatrice_id, jour), INDEX (statut)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS contrats_fin (animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NOT NULL, ft_le DATE NOT NULL, par VARCHAR(120) NOT NULL DEFAULT '', PRIMARY KEY (animatrice_id, devis_id)) $E");
  $c = $pdo->query("SHOW COLUMNS FROM pieces_identite")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('expire_le', $c, true)) $pdo->exec("ALTER TABLE pieces_identite ADD COLUMN expire_le DATE NULL, ADD COLUMN rappel_le DATETIME NULL");
}

/* ---------- Notifications (Web Push sans contenu chiffré : le téléphone vient chercher le message dans l'appli) ---------- */
function x_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
/** Clés VAPID de l'application, créées une fois et gardées dans le dossier protégé. */
function push_cles(): ?array {
  $f = fiche_dir() . '/.vapid'; if (is_file($f) && ($k = json_decode((string)file_get_contents($f), true))) return $k;
  if (!function_exists('openssl_pkey_new')) return null;
  $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]); if (!$key) return null;
  openssl_pkey_export($key, $pem); $d = openssl_pkey_get_details($key);
  $k = ['pem' => $pem, 'pub' => x_b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))];
  file_put_contents($f, json_encode($k)); @chmod($f, 0600); return $k;
}
/** Signature ES256 au format brut (r||s) attendu par les JWT. */
function x_es256(string $data, string $pem): ?string {
  if (!openssl_sign($data, $der, $pem, OPENSSL_ALGO_SHA256)) return null;
  $o = 3; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f; // longueur longue
  $lr = ord($der[$o]); $r = substr($der, $o + 1, $lr); $o += 1 + $lr + 1; $ls = ord($der[$o]); $s = substr($der, $o + 1, $ls);
  $n = fn($x) => str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT);
  return $n($r) . $n($s);
}
/** Message dans l'appli + notification sur les téléphones de la personne (si le planning lui est ouvert). */
function lp_notif(int $aid, string $titre, string $texte, string $vue = ''): void {
  try {
    x_schema(); if (!p_ouvert() && !p_testeur($aid)) return; // phase de test : rien n'arrive aux intervenants
    db()->prepare("INSERT INTO notifs (animatrice_id, titre, texte, vue, created_at) VALUES (?,?,?,?,?)")->execute([$aid, mb_substr($titre, 0, 160), mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($texte))), 0, 400), $vue, now()]);
    $K = push_cles(); if (!$K || !function_exists('curl_init') || demo_actif()) return;
    $st = db()->prepare("SELECT id, endpoint FROM push_abos WHERE animatrice_id = ?"); $st->execute([$aid]);
    foreach ($st->fetchAll() as $s) {
      $u = parse_url($s['endpoint']); if (empty($u['scheme']) || empty($u['host'])) continue;
      $h = x_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . x_b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . MAIL_FROM]));
      $sig = x_es256($h, $K['pem']); if (!$sig) return;
      $c = curl_init($s['endpoint']); curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['TTL: 86400', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . $h . '.' . x_b64u($sig) . ', k=' . $K['pub']]]);
      curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
      if (in_array($code, [404, 410], true)) db()->prepare("DELETE FROM push_abos WHERE id = ?")->execute([$s['id']]); // abonnement périmé (appli désinstallée)
      elseif ($code >= 200 && $code < 300) db()->prepare("UPDATE push_abos SET dernier_envoi = ? WHERE id = ?")->execute([now(), $s['id']]);
      else error_log('Notification ' . $code . ' pour ' . $u['host']);
    }
  } catch (Throwable $e) { error_log('Notification : ' . $e->getMessage()); }
}

/* ---------- Disponibilités ---------- */
/** Pour des dates données : [date => [animatrice_id => 'o' (disponible) | 'n' (indisponible)]]. */
function x_dispos(array $dates): array {
  x_schema(); $dates = array_values(array_unique(array_filter($dates))); if (!$dates) return [];
  $st = db()->prepare("SELECT animatrice_id, jour, etat FROM dispos WHERE jour IN (" . implode(',', array_fill(0, count($dates), '?')) . ")"); $st->execute($dates);
  $o = []; foreach ($st->fetchAll() as $x) $o[$x['jour']][(int)$x['animatrice_id']] = $x['etat']; return $o;
}

/* ---------- Notes de frais ---------- */
const NDF_TYPES = ['parking' => 'Parking', 'carburant' => 'Carburant', 'peage' => 'Péage', 'transport' => 'Bus, taxi, train', 'repas' => 'Repas', 'materiel' => 'Matériel, fournitures', 'autre' => 'Autre'];
const NDF_STATUTS = ['a_valider' => 'À valider', 'validee' => 'Validée', 'refusee' => 'Refusée', 'payee' => 'Payée'];
function ndf_liste(array $w = [], array $a = []): array {
  x_schema(); $st = db()->prepare("SELECT n.*, CONCAT(UPPER(an.nom), ' ', an.prenom) AS nom, d.numero AS devis, d.objet FROM ndf n JOIN animatrices an ON an.id = n.animatrice_id LEFT JOIN g_docs d ON d.id = n.devis_id"
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY n.jour DESC, n.id DESC LIMIT 1000"); $st->execute($a);
  return array_map(fn($x) => ['id' => (int)$x['id'], 'animatrice_id' => (int)$x['animatrice_id'], 'nom' => $x['nom'], 'jour' => $x['jour'], 'type' => $x['type'], 'type_txt' => NDF_TYPES[$x['type']] ?? $x['type'],
    'montant' => (float)$x['montant'], 'devis_id' => $x['devis_id'] ? (int)$x['devis_id'] : null, 'devis' => $x['devis'], 'objet' => $x['objet'], 'magasin' => $x['magasin'], 'commentaire' => $x['commentaire'],
    'ticket' => $x['fichier'] !== '', 'statut' => $x['statut'], 'motif' => $x['motif'], 'par' => $x['par'], 'decide_le' => $x['decide_le'], 'paye_mois' => $x['paye_mois'], 'created_at' => $x['created_at']], $st->fetchAll());
}
/** Notes de frais validées (ou payées) d'une personne, à rembourser avec la paye du mois. */
function ndf_mois(int $aid, string $du, string $au): array {
  x_schema(); $st = db()->prepare("SELECT id, jour, type, montant, devis_id FROM ndf WHERE animatrice_id = ? AND statut IN ('validee','payee') AND jour BETWEEN ? AND ? ORDER BY jour");
  $st->execute([$aid, $du, $au]); return $st->fetchAll();
}

/* ---------- Échéances des documents et fins de contrat ---------- */
/** Rappels quotidiens (une fois par jour, au premier passage) : documents qui expirent dans 30 jours ou moins. */
function x_rappels(): void {
  try {
    x_schema(); $j = date('Y-m-d'); $st = db()->prepare("SELECT valeur FROM g_reglages WHERE cle = 'rappels_le'"); $st->execute(); if ($st->fetchColumn() === $j) return;
    db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES ('rappels_le', ?)")->execute([$j]);
    require_once __DIR__ . '/offres_lib.php';
    $q = db()->prepare("SELECT p.id, p.animatrice_id, p.type, p.expire_le FROM pieces_identite p JOIN animatrices a ON a.id = p.animatrice_id WHERE a.actif = 1 AND p.expire_le IS NOT NULL
      AND p.expire_le <= ? AND (p.rappel_le IS NULL OR p.rappel_le < ?)");
    $q->execute([date('Y-m-d', strtotime('+30 days')), date('Y-m-d H:i:s', strtotime('-14 days'))]);
    foreach ($q->fetchAll() as $p) { $t = DOCS_TYPES[$p['type']] ?? 'document'; $exp = $p['expire_le'] < $j;
      $msg = 'Votre ' . mb_strtolower($t) . ($exp ? ' a expiré le ' : ' expire le ') . g_date($p['expire_le']) . '. Merci de déposer le nouveau document dans votre appli, rubrique « Mes documents ».';
      o_mail((int)$p['animatrice_id'], ($exp ? 'Document expiré : ' : 'Document à renouveler : ') . $t, '<p>' . h($msg) . '</p>', 'docs');
      db()->prepare("UPDATE pieces_identite SET rappel_le = ? WHERE id = ?")->execute([now(), $p['id']]); }
  } catch (Throwable $e) { error_log('Rappels : ' . $e->getMessage()); }
}
/** Contrats terminés sans attestation France Travail notée (faite sur l'espace employeur France Travail). */
function x_fins_a_faire(): array {
  x_schema(); require_once __DIR__ . '/contrats_lib.php'; c_schema();
  $q = db()->query("SELECT c.animatrice_id, c.devis_id, c.numero, c.lignes, CONCAT(UPPER(a.nom), ' ', a.prenom) AS nom FROM contrats c JOIN animatrices a ON a.id = c.animatrice_id
    JOIN (SELECT animatrice_id, devis_id, MAX(avenant) av FROM contrats GROUP BY animatrice_id, devis_id) x ON x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id AND x.av = c.avenant
    WHERE c.signe_test = 0 AND NOT EXISTS (SELECT 1 FROM contrats_fin f WHERE f.animatrice_id = c.animatrice_id AND f.devis_id = c.devis_id)");
  $out = []; $t = date('Y-m-d');
  foreach ($q->fetchAll() as $c) { $d = array_column(json_decode((string)$c['lignes'], true) ?: [], 'date'); if (!$d) continue; $fin = max($d);
    if ($fin < $t) $out[] = ['animatrice_id' => (int)$c['animatrice_id'], 'devis_id' => (int)$c['devis_id'], 'numero' => (int)$c['numero'], 'nom' => $c['nom'], 'fin' => $fin]; }
  usort($out, fn($a, $b) => strcmp($a['fin'], $b['fin'])); return $out;
}
