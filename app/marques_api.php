<?php
// Espace marques : les clients consultent leurs comptes rendus validés. Tous les garde-fous sont appliqués ici, côté serveur.
require __DIR__ . '/lib.php';
require __DIR__ . '/report_lib.php';
require_once __DIR__ . '/gestion_lib.php';
/** Devis adressés à ce compte (envoyés, acceptés ou refusés). */
function cl_devis(array $c): array {
  g_schema(); $out = [];
  foreach (db()->query("SELECT id FROM g_docs WHERE type = 'devis' AND statut IN ('envoye','accepte','refuse') ORDER BY date_doc DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $d = g_doc((int)$id); if ($d && g_devis_visible($d, $c['email'])) $out[] = $d;
  }
  return $out;
}
/** Double authentification facultative des comptes marques (mêmes appli et format que la direction). */
function cl_tf_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  $c = db()->query("SHOW COLUMNS FROM comptes_clients")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('totp_secret', $c, true)) db()->exec("ALTER TABLE comptes_clients ADD COLUMN totp_secret VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN totp_actif TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN totp_secours TEXT NULL, ADD COLUMN totp_dernier BIGINT NOT NULL DEFAULT 0");
}
function cl_tf_codes(): array { $codes = []; $h = []; for ($i = 0; $i < 8; $i++) { $c = strtoupper(bin2hex(random_bytes(4))); $codes[] = substr($c, 0, 4) . '-' . substr($c, 4); $h[] = password_hash($c, PASSWORD_DEFAULT); } return [$codes, $h]; }
function cl_ouvrir(int $id): void {
  session_regenerate_id(true); $_SESSION['client_id'] = $id; $_SESSION['ctries'] = 0; unset($_SESSION['client_apercu'], $_SESSION['ctf']);
  db()->prepare("UPDATE comptes_clients SET derniere_connexion = ? WHERE id = ?")->execute([now(), $id]);
  journal('connexion', 'espace marques');
}
start_session();
header('Cache-Control: no-store');

function cl_out($d, int $c = 200) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function cl_fail(string $m, int $c = 400) { cl_out(['error' => $m], $c); }
if (!function_exists('fail')) { function fail(string $m, int $c = 400) { cl_fail($m, $c); } } // utilisé par limite()
/** Opérations d'accueil visibles par ce compte : devis qui lui sont adressés, ou clients rattachés à ses marques. */
function cl_evt_devis(array $c): array {
  require_once __DIR__ . '/evt_lib.php'; ev_schema(); $ids = array_map(fn($d) => (int)$d['id'], cl_devis($c));
  if ($c['marques_ids']) foreach (db()->query("SELECT d.id FROM g_docs d JOIN g_clients k ON k.id = d.client_id WHERE d.type = 'devis' AND k.marque_id IN (" . implode(',', array_map('intval', $c['marques_ids'])) . ")")->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[] = (int)$i;
  return array_values(array_unique($ids));
}
/** Compte connecté, relu à chaque requête : un compte désactivé ou modifié prend effet immédiatement. */
function cl_compte(): array {
  $id = (int)($_SESSION['client_id'] ?? 0); if (!$id) cl_fail('Connexion requise', 401);
  $st = db()->prepare("SELECT * FROM comptes_clients WHERE id = ? AND actif = 1"); $st->execute([$id]); $c = $st->fetch();
  if (!$c) { unset($_SESSION['client_id']); cl_fail('Connexion requise', 401); }
  $c['per'] = compte_perimetre($c);
  $c['marques_ids'] = array_values(array_unique(array_column($c['per'], 'm')));
  // Zones visibles : l'union des zones du périmètre (toutes si une marque est ouverte sur toutes les zones).
  $c['zones_l'] = array_filter($c['per'], fn($x) => !$x['z']) ? [] : array_values(array_unique(array_merge(...array_column($c['per'], 'z'))));
  $c['droits_l'] = array_values(array_filter(explode(',', $c['droits'])));
  $c['ba_l'] = compte_ba($c);
  return $c;
}
/** Nom d'une personne selon le réglage du compte (prénom et nom, prénom seul, anonyme). */
function cl_nom(array $c, string $prenom, string $nom, int $n): string {
  $p = mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE);
  return $c['noms'] === 'non' ? 'Intervenant(e) ' . $n : ($c['noms'] === 'prenom' ? $p : trim($p . ' ' . mb_strtoupper($nom)));
}
function cl_params(array $q, array $c): array {
  $req = array_filter(array_map('intval', is_array($q['marques'] ?? null) ? $q['marques'] : explode(',', (string)($q['marques'] ?? ''))));
  $mk = array_values(array_intersect($req ?: $c['marques_ids'], $c['marques_ids'])) ?: $c['marques_ids'];
  $zone = (string)($q['zone'] ?? ''); if ($zone !== '' && !str_contains($zone, ':') && $c['zones_l'] && !in_array($zone, $c['zones_l'], true)) $zone = '';
  $p = report_params(['from' => $q['from'] ?? '', 'to' => $q['to'] ?? '', 'marques' => $mk, 'zone' => $zone, 'xfmt' => $q['xfmt'] ?? '', 'xmag' => $q['xmag'] ?? '']);
  $p['zones'] = $c['zones_l'];
  $p['magasin'] = clip($q['magasin'] ?? '', 120);
  $p['animatrice'] = (int)($q['animatrice'] ?? 0);
  $p['client'] = ['droits' => $c['droits_l'], 'noms' => $c['noms'], 'perimetre' => $c['per']];
  return $p;
}

$action = $_GET['action'] ?? ''; if (demo_actif() && in_array($action, ['tf_preparer', 'tf_activer', 'tf_desactiver'], true)) cl_fail('Indisponible dans la démo.', 403);
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !requete_appli()) cl_fail('Requête refusée', 403);

try {
  switch ($action) {
    case 'session':
      if (empty($_SESSION['client_id'])) cl_out(['compte' => null]);
      $c = cl_compte();
      $bam = [];
      $mk = $c['marques_ids'] ? db()->query("SELECT id, nom, sous_marques FROM marques WHERE id IN (" . implode(',', $c['marques_ids']) . ") ORDER BY nom")->fetchAll() : [];
      cl_out(['compte' => ['nom' => $c['nom'], 'societe' => $c['societe'], 'email' => $c['email'], 'droits' => $c['droits_l'], 'zones' => $c['zones_l'] ?: array_keys(ZONES), 'evts' => (bool)array_filter(cl_evt_devis($c), fn($i) => (bool)db()->query("SELECT 1 FROM evenements_cr WHERE valide_le IS NOT NULL AND devis_id = " . (int)$i . " LIMIT 1")->fetchColumn()),
        'merch' => (function () use ($c) { if (!$c['marques_ids']) return false; require_once __DIR__ . '/merch_lib.php'; mc_schema();
          $li = implode(',', array_map('intval', $c['marques_ids']));
          return (bool)db()->query("SELECT 1 FROM merch_visites WHERE valide_le IS NOT NULL AND marque_id IN ($li) LIMIT 1")->fetchColumn() || (bool)db()->query("SELECT 1 FROM merch_refs WHERE actif = 1 AND marque_id IN ($li) LIMIT 1")->fetchColumn()
            || (bool)array_filter($c['marques_ids'], fn($m) => (bool)mc_ops_marque((int)$m)); })(),
        'geo' => (function () { dep_schema(); $o = []; foreach (secteurs() as $s) $o[] = ['s:' . $s['nom'], 'Secteur ' . $s['nom']];
          foreach (db()->query("SELECT DISTINCT dep FROM magasins WHERE dep <> '' ORDER BY dep")->fetchAll(PDO::FETCH_COLUMN) as $d) $o[] = ['d:' . $d, dep_nom($d)]; return $o; })()],
        'marques' => array_map(fn($m) => ['id' => (int)$m['id'], 'nom' => $m['nom'], 'ventile' => trim((string)$m['sous_marques']) !== ''], $mk), 'ba' => $bam,
        'apercu' => !empty($_SESSION['client_apercu']) && !empty($_SESSION['admin']), 'devis' => count(cl_devis($c))]);

    case 'login':
      // Limites par connexion (adresse IP) et par compte : un robot qui change de cookie ou d'adresse est quand même freiné.
      $em = mb_strtolower(trim((string)($in['email'] ?? '')));
      limite('marque_login', 10, 900); limite('marque_login_compte', 8, 900, $em);
      cl_tf_schema(); $st = db()->prepare("SELECT id, pass_hash, actif, totp_actif FROM comptes_clients WHERE email = ?"); $st->execute([$em]); $c = $st->fetch();
      if (!$c || !(int)$c['actif'] || !password_verify((string)($in['password'] ?? ''), $c['pass_hash'])) { journal('connexion refusée', 'espace marques', $em, 'anonyme'); sleep(1); cl_fail('E-mail ou mot de passe incorrect.', 401); }
      if ((int)$c['totp_actif']) { session_regenerate_id(true); $_SESSION['ctf'] = ['id' => (int)$c['id'], 't' => time(), 'n' => 0]; cl_out(['ok' => true, 'totp' => true]); }
      cl_ouvrir((int)$c['id']); cl_out(['ok' => true]);

    case 'login_totp':
      limite('marque_code', 10, 900);
      $p = $_SESSION['ctf'] ?? null; if (!$p || time() - $p['t'] > 300) { unset($_SESSION['ctf']); cl_fail('Session expirée : saisissez à nouveau votre e-mail et votre mot de passe.', 401); }
      if (++$_SESSION['ctf']['n'] > 5) { unset($_SESSION['ctf']); cl_fail('Trop d\'essais : recommencez la connexion.', 429); }
      cl_tf_schema(); $st = db()->prepare("SELECT * FROM comptes_clients WHERE id = ? AND actif = 1"); $st->execute([$p['id']]); $c = $st->fetch(); if (!$c) cl_fail('Compte introuvable.', 401);
      $code = trim((string)($in['code'] ?? ''));
      if (($pas = tf_verifier(tf_secret_clair($c), $code, (int)$c['totp_dernier'])) !== null) db()->prepare("UPDATE comptes_clients SET totp_dernier = ? WHERE id = ?")->execute([$pas, $c['id']]);
      else {
        $sec = json_decode((string)$c['totp_secours'], true) ?: []; $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)); $ok = false;
        foreach ($sec as $i => $h) if ($k !== '' && password_verify($k, $h)) { unset($sec[$i]); $ok = true; break; }
        if (!$ok) { sleep(1); cl_fail('Code incorrect.', 401); }
        db()->prepare("UPDATE comptes_clients SET totp_secours = ? WHERE id = ?")->execute([json_encode(array_values($sec)), $c['id']]);
      }
      cl_ouvrir((int)$c['id']); cl_out(['ok' => true]);

    case 'tf_etat':
      $c = cl_compte(); cl_tf_schema(); $st = db()->prepare("SELECT totp_actif, totp_secours FROM comptes_clients WHERE id = ?"); $st->execute([$c['id']]); $a = $st->fetch();
      cl_out(['actif' => (bool)$a['totp_actif'], 'secours' => count(json_decode((string)$a['totp_secours'], true) ?: [])]);
    case 'tf_preparer':
      $c = cl_compte(); if (!empty($_SESSION['client_apercu'])) cl_fail('Indisponible en aperçu.', 403);
      $sec = tf_b32(random_bytes(20)); $_SESSION['ctf_nouveau'] = $sec; $label = rawurlencode(AG_COURT . ':' . $c['email']);
      cl_out(['secret' => trim(chunk_split($sec, 4, ' ')), 'uri' => 'otpauth://totp/' . $label . '?secret=' . $sec . '&issuer=' . rawurlencode(AG_COURT) . '&digits=6&period=30']);
    case 'tf_activer':
      $c = cl_compte(); limite('marque_tf', 15, 900); $sec = (string)($_SESSION['ctf_nouveau'] ?? ''); if ($sec === '') cl_fail('Recommencez l\'activation.');
      if (($pas = tf_verifier($sec, (string)($in['code'] ?? ''), 0)) === null) cl_fail('Code incorrect : vérifiez l\'heure du téléphone et réessayez.');
      cl_tf_schema(); [$codes, $h] = cl_tf_codes();
      db()->prepare("UPDATE comptes_clients SET totp_secret = ?, totp_actif = 1, totp_secours = ?, totp_dernier = ? WHERE id = ?")->execute([base64_encode(amy_chiffre($sec)), json_encode($h), $pas, $c['id']]);
      unset($_SESSION['ctf_nouveau']); cl_out(['ok' => true, 'secours' => $codes]);
    case 'tf_desactiver':
      $c = cl_compte(); limite('marque_tf', 15, 900); if (!empty($_SESSION['client_apercu'])) cl_fail('Indisponible en aperçu.', 403);
      if (!password_verify((string)($in['password'] ?? ''), (string)$c['pass_hash'])) { sleep(1); cl_fail('Mot de passe incorrect.'); }
      cl_tf_schema(); db()->prepare("UPDATE comptes_clients SET totp_secret = '', totp_actif = 0, totp_secours = NULL, totp_dernier = 0 WHERE id = ?")->execute([$c['id']]);
      cl_out(['ok' => true]);

    case 'logout':
      unset($_SESSION['client_id'], $_SESSION['client_apercu']); cl_out(['ok' => true]);

    case 'data':
      $c = cl_compte(); $p = cl_params($_GET, $c); $d = collect($p);
      $ca = in_array('ca', $c['droits_l'], true);
      // Listes des filtres : tous les points de vente et intervenants visibles sur la période (sans les filtres magasin / intervenant(e)).
      $po = $p; $po['magasin'] = ''; $po['animatrice'] = 0; $all = collect($po)['rapports'];
      $mags = []; $anims = []; foreach ($all as $r) { $mags[$r['magasin']] = $r['ville']; $anims[$r['animatrice_id']] = $r['animatrice']; }
      ksort($mags); asort($anims);
      $rows = []; $tot = ['jours' => 0, 'ca' => 0.0, 'obj' => 0.0, 'ca_obj' => 0.0];
      $byM = []; $byMag = []; $byA = []; $byB = [];
      foreach ($d['rapports'] as $r) {
        $lines = []; $rca = 0.0;
        foreach ($r['lines'] as $l) {
          $lines[] = ['marque' => $l['marque'], 'ca' => $l['ca'] === null ? null : (float)$l['ca'], 'objectif' => $l['objectif'] === null ? null : (float)$l['objectif'],
            'parts' => $l['parts'], 'reactions' => $l['reactions'], 'ruptures' => $l['ruptures'], 'testeurs' => $l['testeurs'],
            'photos' => array_map(fn($x) => ['id' => (int)$x['id'], 'url' => photo_url($x['fichier'], true), 'full' => photo_url($x['fichier'])], $l['photos'])];
          $v = (float)$l['ca']; $rca += $v;
          $b = $l['marque']; $byB[$b] ??= ['nom' => $b, 'jours' => 0, 'ca' => 0.0, 'obj' => 0.0, 'ca_obj' => 0.0]; $byB[$b]['jours']++; $byB[$b]['ca'] += $v;
          if ($l['objectif'] !== null && (float)$l['objectif'] > 0) { $byB[$b]['obj'] += (float)$l['objectif']; $byB[$b]['ca_obj'] += $v; $tot['obj'] += (float)$l['objectif']; $tot['ca_obj'] += $v; }
        }
        $tot['jours']++; $tot['ca'] += $rca;
        $add = function (array &$g, string $k) use ($rca) { $g[$k] ??= ['nom' => $k, 'jours' => 0, 'ca' => 0.0]; $g[$k]['jours']++; $g[$k]['ca'] += $rca; };
        $add($byM, substr($r['date_animation'], 0, 7)); $add($byMag, $r['magasin']); $add($byA, $r['animatrice']);
        $rows[] = ['id' => (int)$r['id'], 'date' => $r['date_animation'], 'zone' => $r['zone'], 'magasin' => $r['magasin'], 'ville' => $r['ville'], 'animatrice' => $r['animatrice'],
          'frequentation' => $r['frequentation'], 'concurrence' => array_values(array_filter(array_merge($r['concurrence'], [$r['concurrence_autre']]))), 'offres' => (string)$r['offres'], 'faits' => (string)$r['faits'],
          'ca' => $ca ? $rca : null, 'lignes' => $lines];
      }
      ksort($byM); uasort($byMag, fn($a, $b) => $b['ca'] <=> $a['ca']); uasort($byA, fn($a, $b) => $b['ca'] <=> $a['ca']);
      cl_out(['from' => $p['from'], 'to' => $p['to'], 'rows' => array_reverse($rows), 'ca_visible' => $ca,
        'kpi' => $ca ? $tot : ['jours' => $tot['jours']], 'par_mois' => array_values($byM), 'par_magasin' => array_values($byMag), 'par_animatrice' => array_values($byA), 'par_marque' => array_values($byB),
        'options' => ['magasins' => array_map(fn($m, $v) => ['nom' => $m, 'ville' => $v], array_keys($mags), $mags), 'animatrices' => array_map(fn($id, $n) => ['id' => $id, 'nom' => $n], array_keys($anims), $anims)]]);

    case 'xlsx': case 'pdf': case 'photos':
      $c = cl_compte(); if (!in_array('telecharger', $c['droits_l'], true)) cl_fail('Votre compte ne permet pas les téléchargements.', 403);
      @set_time_limit(180); require_once __DIR__ . '/xlsx_lib.php';
      $p = cl_params($_GET, $c); $d = collect($p);
      if (!$d['rapports']) { header('Content-Type: text/plain; charset=utf-8'); exit('Aucun compte rendu validé sur cette sélection.'); }
      $slug = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', brands_label($d))) . '_' . $p['from'] . '_' . $p['to'];
      if ($action === 'photos') {
        if (!in_array('photos', $c['droits_l'], true)) cl_fail('Votre compte ne permet pas de voir les photos.', 403);
        header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Photos_' . $slug . '.zip"'); echo photos_zip(report_photo_list($d)); exit;
      }
      if ($action === 'pdf') {
        $an = ($_GET['type'] ?? '') === 'analyse';
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . ($an ? 'Analyse-ventes_' : 'Rapport-animation_') . $slug . '.pdf"');
        echo make_pdf($an ? render_analyse($d, false) : render_cr($d)); exit;
      }
      $fs = xlsx_fichiers($d, $p['xfmt'], $p['xmag'], $slug);
      if (count($fs) > 1) { header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Reportings_' . $slug . '.zip"'); $z = []; foreach ($fs as [$n, $x]) $z[$n] = $x; echo zip_build($z); exit; }
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $fs[0][0] . '"'); echo $fs[0][1]; exit;

    case 'phototheque': case 'phototheque_zip':
      // Photothèque : photos montrées aux marques (jamais de selfie), des journées validées de son périmètre.
      $c = cl_compte(); if (!in_array('photos', $c['droits_l'], true)) cl_fail('Votre compte ne permet pas de voir les photos.', 403);
      $src = (string)($_GET['source'] ?? 'anim'); require_once __DIR__ . '/evt_lib.php'; ev_schema(); $list = [];
      $nomf = fn($s) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', (string)$s)), '-');
      if ($src === 'anim') {
        $p = cl_params($_GET, $c); $p['animatrice'] = 0; $d = collect($p);
        foreach ($d['rapports'] as $r) {
          $all = array_map(fn($x) => [$x, ''], $r['photos']); foreach ($r['lines'] as $l) foreach ($l['photos'] as $x) $all[] = [$x, $l['marque']];
          foreach ($all as [$x, $mq]) $list[] = ['id' => (int)$x['id'], 'fichier' => $x['fichier'], 'date' => $r['date_animation'], 'magasin' => $r['magasin'], 'ville' => $r['ville'], 'qui' => $r['animatrice'], 'marque' => $mq ?: implode(', ', array_column($r['lines'], 'marque'))];
        }
      }
      if ($src === 'evts') {
        // Photos des rapports d'événement validés (selfies exclus, photos décochées par l'agence exclues).
        $ids = cl_evt_devis($c); $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
        $from = $ok($_GET['from'] ?? null) ? $_GET['from'] : date('Y-01-01'); $to = $ok($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d');
        if ($ids) { $st = db()->prepare("SELECT p.id, p.fichier, e.date_evt, e.lieu, d.objet, a.prenom, a.nom FROM photos p JOIN evenements_cr e ON e.id = p.evt_id JOIN g_docs d ON d.id = e.devis_id
            JOIN animatrices a ON a.id = e.animatrice_id WHERE e.valide_le IS NOT NULL AND p.client = 1 AND p.slot <> 'animatrice' AND e.devis_id IN (" . implode(',', $ids) . ") AND e.date_evt BETWEEN ? AND ?");
          $st->execute([$from, $to]); foreach ($st->fetchAll() as $x) $list[] = ['id' => (int)$x['id'], 'fichier' => $x['fichier'], 'date' => $x['date_evt'], 'magasin' => $x['lieu'], 'ville' => '', 'qui' => cl_nom($c, $x['prenom'], $x['nom'], 1), 'marque' => $x['objet']]; }
      }
      $mag = (string)($_GET['magasin'] ?? ''); $qui = (string)($_GET['qui'] ?? '');
      $opts = ['magasins' => array_values(array_unique(array_column($list, 'magasin'))), 'personnes' => array_values(array_unique(array_column($list, 'qui')))]; sort($opts['magasins']); sort($opts['personnes']);
      $list = array_values(array_filter($list, fn($x) => ($mag === '' || $x['magasin'] === $mag) && ($qui === '' || $x['qui'] === $qui)));
      usort($list, fn($a, $b) => strcmp($b['date'], $a['date']));
      foreach ($list as &$x) $x['nom'] = $x['date'] . '_' . $nomf($x['magasin']) . '_' . $nomf($x['qui']) . '_' . $x['id'] . '.jpg'; unset($x);
      if ($action === 'phototheque_zip') {
        if (!in_array('telecharger', $c['droits_l'], true)) cl_fail('Votre compte ne permet pas les téléchargements.', 403);
        $keep = array_flip(array_map('intval', array_filter(explode(',', (string)($_GET['ids'] ?? '')))));
        $sel = array_values(array_filter($list, fn($x) => isset($keep[$x['id']]))); // seulement des photos que ce compte a le droit de voir
        if (!$sel) { header('Content-Type: text/plain; charset=utf-8'); exit('Aucune photo sélectionnée.'); }
        @set_time_limit(180); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Photos_' . date('Y-m-d') . '.zip"'); echo photos_zip($sel); exit;
      }
      cl_out(['photos' => array_map(fn($x) => ['id' => $x['id'], 'url' => photo_url($x['fichier'], true), 'full' => photo_url($x['fichier']), 'nom' => $x['nom'],
        'date' => $x['date'], 'magasin' => $x['magasin'], 'ville' => $x['ville'], 'qui' => $x['qui'], 'marque' => $x['marque']], array_slice($list, 0, 600)), 'total' => count($list), 'options' => $opts]);

    case 'devis':
      $c = cl_compte(); $today = date('Y-m-d');
      cl_out(['devis' => array_map(fn($d) => ['id' => (int)$d['id'], 'numero' => $d['numero'], 'date' => $d['date_doc'], 'validite' => $d['validite'], 'objet' => $d['objet'], 'client' => $d['client']['raison'] ?? '',
        'ht' => (float)$d['total_ht'], 'ttc' => (float)$d['total_ttc'], 'ref' => $d['ref_type'] ? (G_REFS[$d['ref_type']] ?? '') . ' ' . $d['ref_num'] : '', 'statut' => $d['statut'],
        'expire' => $d['statut'] === 'envoye' && $d['validite'] && $d['validite'] < $today, 'accepte_le' => $d['accepte_le'], 'accepte_par' => $d['accepte_par'], 'refus' => $d['refus_motif']], cl_devis($c))]);

    case 'evts':
      // Rapports d'événement validés par l'agence, sur les opérations de ce compte.
      $c = cl_compte(); $ids = cl_evt_devis($c); $L = [];
      if ($ids) { $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ''; $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
        $L = array_values(array_filter(ev_liste(['from' => $from, 'to' => $to, 'statut' => 'valide']), fn($e) => in_array((int)$e['devis_id'], $ids, true))); }
      cl_out(['evts' => $L]);
    case 'mc': case 'mc_pdf': case 'mc_xlsx':
      // Merchandising : visites validées des marques du compte (secteur facultatif).
      $c = cl_compte(); require_once __DIR__ . '/merch_lib.php'; require_once __DIR__ . '/report_lib.php'; if (!$c['marques_ids']) cl_fail('Aucune marque sur ce compte.', 403);
      $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); $mk = array_values(array_intersect(array_map('intval', array_filter(explode(',', (string)($_GET['marques'] ?? '')))), $c['marques_ids'])) ?: $c['marques_ids'];
      $p = ['from' => $ok($_GET['from'] ?? null) ? $_GET['from'] : date('Y-01-01'), 'to' => $ok($_GET['to'] ?? null) ? $_GET['to'] : date('Y-m-d'), 'zone' => (string)($_GET['zone'] ?? ''), 'statut' => 'valide', 'marques' => $mk];
      $V = mc_visites_completes($p);
      if ($action === 'mc') { $S = mc_stats($V); cl_out(['stats' => $S['tot'], 'refs' => $S['refs'], 'pdv' => $S['pdv'], 'visites' => count($V),
        'avap' => array_slice(array_values(array_filter(array_map(function ($v) { $a = array_values(array_filter($v['photos'], fn($x) => $x['slot'] === 'avant' && (int)$x['client'])); $b = array_values(array_filter($v['photos'], fn($x) => $x['slot'] === 'apres' && (int)$x['client']));
          return $a && $b ? ['magasin' => $v['magasin'], 'date' => $v['date_visite'], 'avant' => $a[0]['url'], 'apres' => $b[0]['url'], 'avant_full' => $a[0]['full'], 'apres_full' => $b[0]['full']] : null; }, $V))), 0, 12)]); }
      if (!$V) cl_fail('Aucune visite validée sur cette période.', 404);
      if ($action === 'mc_xlsx') { header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Releves-merchandising_' . $p['from'] . '_' . $p['to'] . '.xlsx"'); echo mc_xlsx($V); exit; }
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Rapport-merchandising_' . $p['from'] . '_' . $p['to'] . '.pdf"'); echo mc_pdf(mc_report_html($V, $p, mc_reco_auto(mc_stats($V)))); exit;
    case 'mc_refs':
      // Catalogue suivi en merchandising : la marque voit ses références (celles que l'agence relève sont marquées) et saisit ses PVP.
      $c = cl_compte(); require_once __DIR__ . '/merch_lib.php'; $o = [];
      foreach ($c['marques_ids'] as $m) { $nm = (string)db()->query("SELECT nom FROM marques WHERE id = " . (int)$m)->fetchColumn();
        $o[] = ['id' => (int)$m, 'nom' => $nm, 'refs' => array_map(fn($r) => ['id' => (int)$r['id'], 'nom' => $r['nom'], 'gamme' => $r['gamme'], 'ean' => $r['ean'], 'code' => $r['code'], 'prix' => $r['prix'], 'a_relever' => (int)$r['a_relever'], 'pvp_par' => $r['pvp_par'], 'pvp_le' => $r['pvp_le']], mc_refs((int)$m))]; }
      cl_out(['marques' => $o, 'peut' => in_array('pvp', $c['droits_l'], true)]);
    case 'mc_pvp': case 'mc_import':
      $c = cl_compte(); require_once __DIR__ . '/merch_lib.php'; if (!in_array('pvp', $c['droits_l'], true)) cl_fail('Votre compte ne permet pas de saisir les prix : demandez-le à l\'agence.', 403);
      $mq = (int)($_POST['marque'] ?? $in['marque'] ?? 0); if (!in_array($mq, array_map('intval', $c['marques_ids']), true)) cl_fail('Marque inconnue sur ce compte.', 403);
      $par = trim($c['nom'] . ($c['societe'] ? ' · ' . $c['societe'] : '')) . ' (marque)';
      if ($action === 'mc_pvp') { $up = db()->prepare("UPDATE merch_refs SET prix_conseille = ?, pvp_par = ?, pvp_le = ? WHERE id = ? AND marque_id = ? AND NOT (prix_conseille <=> ?)"); $n = 0;
        foreach (array_slice((array)($in['prix'] ?? []), 0, 5000, true) as $id => $v) { $px = mc_prix_lu($v); $up->execute([$px, $px === null ? '' : $par, $px === null ? null : now(), (int)$id, $mq, $px]); $n += $up->rowCount(); }
        cl_out(['ok' => true, 'n' => $n]); }
      $f = $_FILES['fichier'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) cl_fail('Choisissez votre fichier.'); if ($f['size'] > 15 * 1024 * 1024) cl_fail('Fichier trop lourd (15 Mo au plus).');
      try { $A = mc_import_analyse(mc_fichier_lignes($f['tmp_name'], (string)$f['name'])); } catch (RuntimeException $e) { cl_fail($e->getMessage()); }
      if (!isset($A['map']['nom']) && !isset($A['map']['ean'])) cl_fail('Colonnes non reconnues : mettez une ligne de titres avec au moins « Désignation » (ou « Référence ») et « EAN », plus « PVP ».');
      if (!isset($A['map']['prix'])) cl_fail('Colonne des prix non trouvée : nommez-la « PVP » (ou « Prix public conseillé »).');
      if (empty($_POST['appliquer'])) cl_out(['colonnes' => $A['colonnes'], 'map' => $A['map'], 'apercu' => array_slice($A['lignes'], 0, 5), 'n' => count($A['lignes'])]);
      cl_out(['ok' => true] + mc_import_marque($mq, $A, $A['map'], $par));
    case 'evt_bilan_pdf':
      // Bilan de l'opération : toutes ses journées validées, en un PDF.
      $c = cl_compte(); require_once __DIR__ . '/evt_lib.php'; $dv = (int)($_GET['devis_id'] ?? 0);
      if (!in_array($dv, cl_evt_devis($c), true)) cl_fail('Opération introuvable.', 404);
      $B = ev_bilan($dv); if (!$B['sel']) cl_fail('Aucun rapport validé pour cette opération.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Rapport-evenementiel_' . $dv . '.pdf"'); echo ev_bilan_pdf($B, $B['reco_auto']); exit;
    case 'evt_pdf':
      $c = cl_compte(); $e = (function () { require_once __DIR__ . '/evt_lib.php'; return ev_row((int)($_GET['id'] ?? 0)); })();
      if (!$e || !$e['valide_le'] || !in_array((int)$e['devis_id'], cl_evt_devis($c), true)) cl_fail('Rapport introuvable.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Rapport_evenement_' . $e['date_evt'] . '.pdf"'); echo ev_pdf($e); exit;
    case 'devis_pdf':
      $c = cl_compte(); $d = g_doc((int)($_GET['id'] ?? 0));
      if (!$d || !g_devis_visible($d, $c['email'])) cl_fail('Devis introuvable.', 404);
      require_once __DIR__ . '/report_lib.php'; @set_time_limit(120);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . g_nom_fichier($d) . '"'); echo make_pdf(g_render($d)); exit;

    case 'devis_repondre':
      // Bon pour accord en ligne : nom, fonction, date, heure et adresse IP sont gardés ; l'agence est prévenu par e-mail.
      $c = cl_compte(); if (!empty($_SESSION['client_apercu'])) cl_fail('Aperçu administrateur : la validation est réservée au client.', 403);
      $d = g_doc((int)($in['id'] ?? 0)); if (!$d || !g_devis_visible($d, $c['email'])) cl_fail('Devis introuvable.', 404);
      if ($d['statut'] !== 'envoye') cl_fail('Ce devis a déjà reçu une réponse.');
      $ok = ($in['decision'] ?? '') === 'accepte';
      if ($ok && $d['validite'] && $d['validite'] < date('Y-m-d')) cl_fail('Ce devis a expiré : contactez ' . AG_NOM . ' pour le renouveler.');
      $nom = clip($in['nom'] ?? '', 120); $fct = clip($in['fonction'] ?? '', 120); if ($nom === '') cl_fail('Indiquez votre nom.');
      if ($ok && empty($in['cgv'])) cl_fail('Cochez l\'acceptation du devis et des conditions générales de vente.');
      $par = $nom . ($fct !== '' ? ', ' . $fct : '') . ' · ' . $c['email'];
      db()->prepare("UPDATE g_docs SET statut = ?, accepte_le = ?, accepte_par = ?, accepte_ip = ?, refus_motif = ? WHERE id = ?")
        ->execute([$ok ? 'accepte' : 'refuse', now(), $par, clip($_SERVER['REMOTE_ADDR'] ?? '', 60), $ok ? null : clip($in['motif'] ?? '', 2000), $d['id']]);
      $r = g_reg(); $to = implode(', ', emails_list($r['notif_emails']));
      if ($to) @mail_send($to, ($ok ? 'Devis accepté : ' : 'Devis refusé : ') . $d['numero'] . ' · ' . ($d['client']['raison'] ?? ''),
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5"><p><b>Le devis ' . h($d['numero']) . ' a été ' . ($ok ? 'accepté' : 'refusé') . ' en ligne.</b></p>'
        . '<p>Client : ' . h($d['client']['raison'] ?? '') . '<br>Objet : ' . h($d['objet']) . '<br>Montant : ' . h(g_eur((float)$d['total_ht'])) . ' HT<br>Par : ' . h($par) . '<br>Le : ' . date('d/m/Y à H:i') . '</p>'
        . (!$ok && trim((string)($in['motif'] ?? '')) !== '' ? '<p>Motif : ' . nl2br(h($in['motif'])) . '</p>' : '') . '<p>Voir dans l\'administration, onglet Administration.</p></div>');
      cl_out(['ok' => true]);

    case 'mes_rapports':
      $c = cl_compte(); $st = db()->prepare("SELECT id, nom, params, created_at FROM rapports_clients WHERE compte_id = ? ORDER BY nom"); $st->execute([$c['id']]);
      cl_out(['rapports' => array_map(fn($r) => ['id' => (int)$r['id'], 'nom' => $r['nom'], 'params' => json_decode($r['params'], true) ?: [], 'le' => substr($r['created_at'], 0, 10)], $st->fetchAll())]);

    case 'rapport_save':
      // Sélection enregistrée : seulement des filtres, revérifiés côté serveur à chaque ouverture (périmètre, droits, validation).
      $c = cl_compte(); $nom = clip($in['nom'] ?? '', 120); if ($nom === '') cl_fail('Donnez un nom au rapport.');
      $n = db()->prepare("SELECT COUNT(*) FROM rapports_clients WHERE compte_id = ?"); $n->execute([$c['id']]); if ($n->fetchColumn() >= 50) cl_fail('50 rapports maximum : supprimez-en un.');
      $p = is_array($in['params'] ?? null) ? $in['params'] : []; $j = json_encode($p, JSON_UNESCAPED_UNICODE); if (strlen($j) > 4000) cl_fail('Sélection trop longue.');
      db()->prepare("INSERT INTO rapports_clients (compte_id, nom, params, created_at) VALUES (?,?,?,?)")->execute([$c['id'], $nom, $j, now()]); cl_out(['ok' => true]);

    case 'rapport_delete':
      $c = cl_compte(); db()->prepare("DELETE FROM rapports_clients WHERE id = ? AND compte_id = ?")->execute([(int)($in['id'] ?? 0), $c['id']]); cl_out(['ok' => true]);

    default:
      cl_fail('Action inconnue', 404);
  }
} catch (Throwable $e) {
  error_log('Espace marques : ' . $e->getMessage());
  cl_fail('Erreur du serveur. Réessayez dans un instant.', 500);
}
