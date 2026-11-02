<?php
// Administration : clients, devis, factures. Réservé à la direction (vérifié à chaque requête, côté serveur).
require __DIR__ . '/gestion_lib.php';
start_session();
header('Cache-Control: no-store');
function gout($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function gfail(string $m, int $c = 400) { http_response_code($c); gout(['error' => $m]); }
if (!function_exists('fail')) { function fail(string $m, int $c = 400) { gfail($m, $c); } } // utilisé par les fonctions communes (lecture Excel)

$action = $_GET['action'] ?? ''; demo_bloquer($action);
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !requete_appli()) gfail('Requête refusée', 403);
g_need();

/** Données d'un document à partir du formulaire (devis ou facture brouillon). */
function g_from_input(array $in, array $cur = []): array {
  $type = $cur['type'] ?? (($in['type'] ?? '') === 'facture' ? 'facture' : 'devis');
  $cid = (int)($in['client_id'] ?? 0); $c = null;
  if ($cid) { $st = db()->prepare("SELECT * FROM g_clients WHERE id = ?"); $st->execute([$cid]); $c = $st->fetch() ?: null; }
  if (!$c) gfail('Choisissez le client.');
  $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date_doc'] ?? '') ? $in['date_doc'] : date('Y-m-d');
  $pay = isset(G_PAIEMENTS[$in['paiement'] ?? '']) ? $in['paiement'] : '30j';
  $tva = isset(G_TVA[(string)($in['tva'] ?? '')]) ? (string)$in['tva'] : '20';
  $lig = g_lignes((array)($in['lignes'] ?? [])); if (!$lig) gfail('Ajoutez au moins une ligne.');
  foreach ($lig as $l) if ($l['type'] === 'presta' && !$l['dates']) gfail('Cochez au moins une date pour chaque prestation.');
  $t = g_totaux($lig, $tva);
  $val = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['validite'] ?? '') ? $in['validite'] : date('Y-m-d', strtotime($date . ' +' . (int)g_reg()['validite_jours'] . ' days'));
  return ['type' => $type, 'client_id' => $cid, 'client' => json_encode(array_intersect_key($c, array_flip(['raison', 'contact', 'adresse', 'cp', 'ville', 'pays', 'tva_intra', 'emails', 'cc'])), JSON_UNESCAPED_UNICODE),
    'date_doc' => $date, 'validite' => $type === 'devis' ? $val : null, 'echeance' => $type === 'facture' ? g_echeance($date, $pay) : null,
    'objet' => clip($in['objet'] ?? '', 300), 'pole' => isset(G_POLES[$in['pole'] ?? '']) ? (string)$in['pole'] : ($cur['pole'] ?? 'anim'), 'type_op' => in_array((string)($in['type_op'] ?? ''), array_merge(...array_values(G_TYPES)), true) ? (string)$in['type_op'] : '', 'zone' => isset(ZONES[$in['zone'] ?? '']) ? $in['zone'] : '', 'paiement' => $pay,
    'acompte' => max(0, min(100, g_num($in['acompte'] ?? 0))), 'tva' => $tva, 'lignes' => json_encode($lig, JSON_UNESCAPED_UNICODE),
    'ref_type' => isset(G_REFS[$in['ref_type'] ?? '']) ? (string)($in['ref_type'] ?? '') : '', 'ref_num' => clip($in['ref_num'] ?? '', 80),
    'tva_mention' => isset(G_TVA_MENTIONS[(string)($in['tva_mention'] ?? '')]) ? (string)($in['tva_mention'] ?? '') : '', 'compte' => (string)($c['compte'] ?? ''),
    'mention' => clip($in['mention'] ?? '', 3000), 'notes' => clip($in['notes'] ?? '', 3000), 'total_ht' => $t['ht'], 'total_tva' => $t['tva'], 'total_ttc' => $t['ttc']];
}
function g_pdf(array $d): string {
  require_once __DIR__ . '/report_lib.php';
  if ($d['type'] === 'facture' && $d['devis_id']) { $st = db()->prepare("SELECT numero FROM g_docs WHERE id = ?"); $st->execute([$d['devis_id']]); $d['devis_numero'] = (string)$st->fetchColumn(); }
  return make_pdf(g_render($d));
}

try {
  switch ($action) {
    case 'init':
      $y = (int)date('Y'); $fe = []; foreach (array_keys(ZONES) as $z) for ($a = $y - 1; $a <= $y + 2; $a++) $fe[$z] = ($fe[$z] ?? []) + g_feries($a, $z);
      require_once __DIR__ . '/presence_lib.php'; pr_schema(); tf_schema(); gout(['apparence' => ['theme' => (string)reglage('theme', 'aurore'), 'couleur' => ag_couleur(), 'themes' => AG_THEMES], 'images' => ['logo' => is_file(__DIR__ . '/uploads/logo-agence.png'), 'logo_blanc' => is_file(__DIR__ . '/uploads/logo-agence-blanc.png'), 'signature' => is_file(__DIR__ . '/prive/signature-gerant.png')], 'reglages' => g_reg(), 'refs' => G_REFS, 'paiements' => G_PAIEMENTS, 'tva' => G_TVA, 'statuts' => G_STATUTS, 'tva_mentions' => G_TVA_MENTIONS, 'poles' => G_POLES, 'types_op' => G_TYPES, 'feries' => $fe,
        'clients' => db()->query("SELECT * FROM g_clients WHERE actif = 1 ORDER BY raison")->fetchAll()]);

    case 'docs':
      $type = ($_GET['type'] ?? 'devis') === 'facture' ? 'facture' : 'devis';
      $st = db()->prepare("SELECT d.id, d.type, d.numero, d.devis_id, d.date_doc, d.validite, d.echeance, d.objet, d.zone, d.total_ht, d.total_ttc, d.statut, d.envoye_le, d.accepte_le, d.accepte_par, d.accepte_ip, d.avenant_de, d.pole, d.client,
        (SELECT f.id FROM g_docs f WHERE f.devis_id = d.id LIMIT 1) AS facture_id FROM g_docs d WHERE d.type = ? ORDER BY d.date_doc DESC, d.id DESC LIMIT 500");
      $st->execute([$type]);
      gout(['docs' => array_map(function ($d) { $c = json_decode((string)$d['client'], true) ?: []; unset($d['client']); return $d + ['client_nom' => $c['raison'] ?? '']; }, $st->fetchAll())]);

    case 'doc':
      $d = g_doc((int)($_GET['id'] ?? 0)) ?: gfail('Document introuvable.', 404); gout(['doc' => $d]);

    case 'doc_save':
      $id = (int)($in['id'] ?? 0); $cur = $id ? (g_doc($id) ?: gfail('Document introuvable.', 404)) : [];
      if ($cur && $cur['type'] === 'facture' && $cur['numero']) gfail('Une facture émise ne se modifie plus. Faites un avoir ou une nouvelle facture.');
      $v = g_from_input($in, $cur); $v['modifie_le'] = now();
      if ($id) {
        unset($v['type']); $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
        db()->prepare("UPDATE g_docs SET $sets WHERE id = ?")->execute([...array_values($v), $id]);
      } else {
        $v['numero'] = $v['type'] === 'devis' ? g_numero('devis') : null; $v['statut'] = 'brouillon'; $v['par'] = admin_nom(); $v['created_at'] = now();
        $cols = array_keys($v); db()->prepare("INSERT INTO g_docs (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($v));
        $id = (int)db()->lastInsertId();
      }
      gout(['ok' => true, 'id' => $id, 'doc' => g_doc($id)]);

    case 'doc_delete':
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Document introuvable.', 404);
      if ($d['type'] === 'facture' && $d['numero']) gfail('Une facture émise ne peut pas être supprimée (numérotation continue obligatoire).');
      db()->prepare("DELETE FROM g_docs WHERE id = ?")->execute([$d['id']]); gout(['ok' => true]);

    case 'doc_dupliquer':
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Document introuvable.', 404);
      db()->prepare("INSERT INTO g_docs (type, numero, client_id, client, date_doc, validite, objet, zone, paiement, acompte, tva, lignes, mention, notes, total_ht, total_tva, total_ttc, statut, par, created_at)
        VALUES ('devis',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'brouillon',?,?)")->execute([g_numero('devis'), $d['client_id'], json_encode($d['client'], JSON_UNESCAPED_UNICODE), date('Y-m-d'),
        date('Y-m-d', strtotime('+' . (int)g_reg()['validite_jours'] . ' days')), $d['objet'], $d['zone'], $d['paiement'], $d['acompte'], $d['tva'], json_encode($d['lignes'], JSON_UNESCAPED_UNICODE),
        $d['mention'], $d['notes'], $d['total_ht'], $d['total_tva'], $d['total_ttc'], admin_nom(), now()]);
      $nid = (int)db()->lastInsertId(); db()->prepare("UPDATE g_docs SET pole = ? WHERE id = ?")->execute([g_pole($d), $nid]);
      gout(['ok' => true, 'id' => $nid]);

    case 'facture_depuis_devis':
      // La facture reprend le devis : la direction retire ou change les journées non faites, puis l'émet.
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Devis introuvable.', 404); if ($d['type'] !== 'devis') gfail('Ce document n\'est pas un devis.');
      // Lignes d'après le planning : dates annulées retirées, reports ajoutés, écarts justifiés.
      $lig = g_lignes(g_lignes_realisees($d)); $t = g_totaux($lig, (string)$d['tva']); $d['lignes'] = $lig; [$d['total_ht'], $d['total_tva'], $d['total_ttc']] = [$t['ht'], $t['tva'], $t['ttc']];
      $ex = db()->prepare("SELECT id, numero FROM g_docs WHERE devis_id = ? AND type = 'facture' LIMIT 1"); $ex->execute([$d['id']]);
      if ($f = $ex->fetch()) {
        if (!empty($in['actualiser']) && !$f['numero']) db()->prepare("UPDATE g_docs SET lignes = ?, total_ht = ?, total_tva = ?, total_ttc = ?, modifie_le = ? WHERE id = ?")->execute([json_encode($lig, JSON_UNESCAPED_UNICODE), $t['ht'], $t['tva'], $t['ttc'], now(), $f['id']]);
        gout(['ok' => true, 'id' => (int)$f['id']]);
      }
      db()->prepare("INSERT INTO g_docs (type, numero, devis_id, client_id, client, date_doc, echeance, objet, zone, paiement, acompte, tva, lignes, mention, notes, total_ht, total_tva, total_ttc, statut, par, created_at)
        VALUES ('facture',NULL,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,'brouillon',?,?)")->execute([$d['id'], $d['client_id'], json_encode($d['client'], JSON_UNESCAPED_UNICODE), date('Y-m-d'),
        g_echeance(date('Y-m-d'), $d['paiement']), $d['objet'], $d['zone'], $d['paiement'], $d['tva'], json_encode($d['lignes'], JSON_UNESCAPED_UNICODE), $d['mention'], '',
        $d['total_ht'], $d['total_tva'], $d['total_ttc'], admin_nom(), now()]);
      $fid = (int)db()->lastInsertId(); db()->prepare("UPDATE g_docs SET ref_type = ?, ref_num = ?, tva_mention = ?, compte = ?, pole = ? WHERE id = ?")->execute([$d['ref_type'] ?? '', $d['ref_num'] ?? '', $d['tva_mention'] ?? '', $d['compte'], g_pole($d), $fid]);
      gout(['ok' => true, 'id' => $fid]);

    case 'facture_emettre':
      // Numéro définitif, sans trou ; la facture ne se modifie plus ensuite.
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Facture introuvable.', 404); if ($d['type'] !== 'facture') gfail('Ce document n\'est pas une facture.');
      if ($d['numero']) gout(['ok' => true, 'numero' => $d['numero']]);
      // Même numéro que le devis (20261009/02) ; une facture sans devis prend un numéro du jour.
      $num = '';
      if ($d['devis_id']) { $st = db()->prepare("SELECT numero FROM g_docs WHERE id = ?"); $st->execute([$d['devis_id']]); $num = (string)$st->fetchColumn(); }
      $chk = db()->prepare("SELECT COUNT(*) FROM g_docs WHERE type = 'facture' AND numero = ?"); $chk->execute([$num]);
      if ($num === '' || $chk->fetchColumn()) $num = g_numero(); $date = date('Y-m-d');
      db()->prepare("UPDATE g_docs SET numero = ?, date_doc = ?, echeance = ?, statut = 'emise', modifie_le = ? WHERE id = ?")->execute([$num, $date, g_echeance($date, $d['paiement']), now(), $d['id']]);
      if ($d['devis_id']) db()->prepare("UPDATE g_docs SET statut = 'accepte' WHERE id = ? AND statut IN ('brouillon','envoye')")->execute([$d['devis_id']]);
      gout(['ok' => true, 'numero' => $num]);

    case 'statut':
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Document introuvable.', 404); $s = (string)($in['statut'] ?? '');
      $ok = $d['type'] === 'devis' ? ['brouillon', 'envoye', 'accepte', 'refuse'] : ['emise', 'payee'];
      if (!in_array($s, $ok, true) || ($d['type'] === 'facture' && !$d['numero'])) gfail('Statut impossible.');
      db()->prepare("UPDATE g_docs SET statut = ? WHERE id = ?")->execute([$s, $d['id']]); gout(['ok' => true]);

    case 'pdf':
      $d = g_doc((int)($_GET['id'] ?? 0)) ?: gfail('Document introuvable.', 404); @set_time_limit(120);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . g_nom_fichier($d) . '"'); echo g_pdf($d); exit;

    case 'envoyer':
      require_once __DIR__ . '/report_lib.php';
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Document introuvable.', 404);
      if ($d['type'] === 'facture' && !$d['numero']) gfail('Émettez d\'abord la facture (numéro définitif).');
      $to = emails_list($in['to'] ?? ''); if (!$to) gfail('Indiquez au moins une adresse.'); $cc = emails_list($in['cc'] ?? '');
      $msg = trim((string)($in['message'] ?? '')); $sujet = clip($in['sujet'] ?? '', 200) ?: (($d['type'] === 'facture' ? 'Facture ' : 'Devis ') . $d['numero'] . ' · ' . AG_NOM);
      $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">' . nl2br(h($msg)) . '</div>';
      if (!mail_send(implode(', ', $to), $sujet, $html, [[g_nom_fichier($d), g_pdf($d), 'application/pdf']], implode(', ', $cc))) gfail('L\'e-mail n\'a pas pu partir. Réessayez.');
      db()->prepare("UPDATE g_docs SET envoye_le = ?, envoye_a = ?, statut = CASE WHEN type = 'devis' AND statut = 'brouillon' THEN 'envoye' ELSE statut END WHERE id = ?")
        ->execute([now(), implode(', ', array_merge($to, $cc)), $d['id']]);
      gout(['ok' => true]);

    case 'mail_modele':
      $d = g_doc((int)($_GET['id'] ?? 0)) ?: gfail('Document introuvable.', 404);
      gout(['sujet' => ($d['type'] === 'facture' ? 'Facture ' : 'Devis ') . $d['numero'] . ' · ' . AG_NOM, 'message' => g_mail_texte($d)]);

    case 'tableau':
      // Tableau de bord : devis en attente, acceptés à facturer, factures à encaisser.
      $all = db()->query("SELECT id, type, numero, devis_id, date_doc, validite, echeance, objet, total_ht, total_ttc, statut, envoye_le, accepte_le, accepte_par, refus_motif, client FROM g_docs ORDER BY date_doc DESC, id DESC")->fetchAll();
      foreach ($all as &$x) { $c = json_decode((string)$x['client'], true) ?: []; $x['client_nom'] = $c['raison'] ?? ''; unset($x['client']); } unset($x);
      $facOf = []; foreach ($all as $x) if ($x['type'] === 'facture' && $x['devis_id']) $facOf[$x['devis_id']] = $x;
      $today = date('Y-m-d'); $ym = date('Y-m'); $y = date('Y');
      $attente = array_values(array_filter($all, fn($x) => $x['type'] === 'devis' && $x['statut'] === 'envoye'));
      $afacturer = array_values(array_filter($all, fn($x) => $x['type'] === 'devis' && $x['statut'] === 'accepte' && !(isset($facOf[$x['id']]) && $facOf[$x['id']]['numero'])));
      $encours = array_values(array_filter($all, fn($x) => $x['type'] === 'facture' && $x['statut'] === 'emise'));
      foreach ($encours as &$x) $x['retard'] = $x['echeance'] && $x['echeance'] < $today ? (int)((strtotime($today) - strtotime($x['echeance'])) / 86400) : 0; unset($x);
      $emises = array_filter($all, fn($x) => $x['type'] === 'facture' && $x['numero']);
      $sum = fn($L, $k) => round(array_sum(array_map(fn($x) => (float)$x[$k], $L)), 2);
      $enligne = array_values(array_filter($all, fn($x) => $x['type'] === 'devis' && ($x['accepte_le'] || $x['refus_motif'] !== null && $x['statut'] === 'refuse')));
      usort($enligne, fn($a, $b) => strcmp((string)$b['accepte_le'], (string)$a['accepte_le']));
      gout(['kpi' => ['attente_n' => count($attente), 'attente_ht' => $sum($attente, 'total_ht'), 'afacturer_n' => count($afacturer), 'afacturer_ht' => $sum($afacturer, 'total_ht'),
        'encours_ttc' => $sum($encours, 'total_ttc'), 'retard_ttc' => $sum(array_filter($encours, fn($x) => $x['retard'] > 0), 'total_ttc'),
        'mois_ht' => $sum(array_filter($emises, fn($x) => substr($x['date_doc'], 0, 7) === $ym), 'total_ht'), 'annee_ht' => $sum(array_filter($emises, fn($x) => substr($x['date_doc'], 0, 4) === $y), 'total_ht')],
        'attente' => $attente, 'afacturer' => $afacturer, 'encours' => $encours, 'enligne' => array_slice($enligne, 0, 10)]);

    case 'itineraire':
      // Tournée : distance routière point après point, dans l'ordre de l'itinéraire (aller simple, du premier au dernier).
      require_once __DIR__ . '/geo_lib.php'; $N = array_values(array_filter(array_map(fn($x) => clip((string)$x, 160), (array)($in['magasins'] ?? []))));
      if (count($N) < 2) gfail('Il faut au moins deux points d\'intervention.');
      $P = []; $approx = false; foreach ($N as $n) { $g = geo_magasin($n); if (!$g || !$g['pos']) gfail('Adresse introuvable pour « ' . $n . ' » : complétez-la dans Points d\'intervention.'); $P[] = $g['pos']; $approx = $approx || !empty($g['approx']); }
      $km = 0.0; $et = []; for ($i = 1; $i < count($P); $i++) { $r = geo_route($P[$i - 1], $P[$i]); $km += $r['km']; $et[] = ['de' => $N[$i - 1], 'a' => $N[$i], 'km' => $r['km']]; }
      gout(['km' => round($km, 1), 'etapes' => $et, 'approx' => $approx]);

    case 'pilotage':
      // Tableau de bord d'accueil : chiffres clés, journées du jour et du lendemain, dernières activités (planning, contrats, relevés, offres, fiches, comptes rendus).
      require_once __DIR__ . '/contrats_lib.php'; require_once __DIR__ . '/offres_lib.php'; c_schema(); r_schema(); o_schema(); p_schema();
      // Sauvegarde du jour (si aucune tâche planifiée ne l'a faite) : après l'envoi de la réponse, sans faire attendre.
      register_shutdown_function(function () { require_once __DIR__ . '/sauvegarde_lib.php'; if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request(); sv_auto(); require_once __DIR__ . '/externe_lib.php'; sx_auto(25); });
      $pdo = db(); $t0 = date('Y-m-d'); $t1 = date('Y-m-d', strtotime('+1 day')); $q = fn($sql, $a = []) => (function () use ($pdo, $sql, $a) { $st = $pdo->prepare($sql); $st->execute($a); return $st; })();
      $k = [];
      $k['inscrits'] = (int)$q("SELECT COUNT(*) FROM animatrices WHERE created_at IS NOT NULL AND actif = 1")->fetchColumn();
      $k['equipe'] = (int)$q("SELECT COUNT(*) FROM animatrices WHERE actif = 1 AND nom NOT LIKE 'effacée%'")->fetchColumn();
      $k['nouveaux'] = (int)$q("SELECT COUNT(*) FROM animatrices WHERE created_at >= ?", [date('Y-m-d', strtotime('-30 days'))])->fetchColumn();
      $k['aujourdhui'] = (int)$q("SELECT COUNT(*) FROM missions WHERE date_mission = ? AND animatrice_id IS NOT NULL AND statut <> 'annulee'", [$t0])->fetchColumn();
      $k['demain'] = (int)$q("SELECT COUNT(*) FROM missions WHERE date_mission = ? AND animatrice_id IS NOT NULL AND statut <> 'annulee'", [$t1])->fetchColumn();
      // Créneaux à pourvoir sur les opérations acceptées (à venir)
      $apourvoir = 0; $ops = [];
      foreach ($q("SELECT id FROM g_docs WHERE type = 'devis' AND statut = 'accepte'")->fetchAll(PDO::FETCH_COLUMN) as $id) { $d = g_doc((int)$id); if (!$d) continue;
        $n = count(array_filter(p_operation($d), fn($x) => $x['date'] >= $t0 && !$x['animatrice_id'] && $x['statut'] !== 'annulee'));
        if ($n) { $apourvoir += $n; $ops[] = ['id' => (int)$id, 'nom' => ($d['client']['raison'] ?? '') . ' · ' . ($d['objet'] ?: $d['numero']), 'n' => $n]; } }
      $k['a_pourvoir'] = $apourvoir; $k['ops_a_booker'] = count($ops);
      $k['devis_attente'] = (int)$q("SELECT COUNT(*) FROM g_docs WHERE type = 'devis' AND statut = 'envoye'")->fetchColumn();
      $k['contrats_a_signer'] = (int)$q("SELECT COUNT(*) FROM contrats c WHERE statut = 'envoye' AND avenant = (SELECT MAX(avenant) FROM contrats x WHERE x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id)")->fetchColumn();
      $k['sans_contrat'] = (int)$q("SELECT COUNT(*) FROM (SELECT DISTINCT m.animatrice_id, m.devis_id FROM missions m WHERE m.animatrice_id IS NOT NULL AND m.devis_id IS NOT NULL AND m.statut <> 'annulee' AND m.date_mission >= ?
        AND NOT EXISTS (SELECT 1 FROM contrats c WHERE c.animatrice_id = m.animatrice_id AND c.devis_id = m.devis_id)) x", [$t0])->fetchColumn();
      $k['dpae'] = (int)$q("SELECT COUNT(*) FROM (SELECT m.animatrice_id, m.devis_id, MIN(m.date_mission) d FROM missions m WHERE m.animatrice_id IS NOT NULL AND m.devis_id IS NOT NULL AND m.statut <> 'annulee' GROUP BY m.animatrice_id, m.devis_id) x
        WHERE x.d BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM contrats_dpae p WHERE p.animatrice_id = x.animatrice_id AND p.devis_id = x.devis_id)", [$t0, date('Y-m-d', strtotime('+3 days'))])->fetchColumn();
      $k['releves_a_signer'] = (int)$q("SELECT COUNT(*) FROM releves WHERE statut = 'envoye'")->fetchColumn();
      $k['positionnes'] = (int)$q("SELECT COUNT(*) FROM offres_dest od JOIN offres o ON o.id = od.offre_id WHERE o.statut = 'ouvert' AND od.reponse = 'oui' AND od.retenu = 0")->fetchColumn();
      $k['antichambre'] = (int)$q("SELECT COUNT(*) FROM animatrices WHERE statut = 'attente'")->fetchColumn();
      require_once __DIR__ . '/extras_lib.php'; x_rappels(); // rappels d'échéance des documents, une fois par jour
      require_once __DIR__ . '/presence_lib.php'; pr_auto(); $k['presence'] = count(array_filter(pr_jour($t0), fn($m) => $m['a_envoyer'] && !$m['envoi']));
      $k['ndf'] = (int)$q("SELECT COUNT(*) FROM ndf WHERE statut = 'a_valider'")->fetchColumn();
      $k['fins'] = count(x_fins_a_faire());
      try { require_once __DIR__ . '/rh_lib.php'; $S5 = rh_suivi(); $k['cdii'] = count(array_filter($S5, fn($x) => in_array($x['etat'], ['a_proposer', 'retard'], true))); $k['cdii_bientot'] = count(array_filter($S5, fn($x) => in_array($x['etat'], ['prevu', 'approche'], true))); } catch (Throwable $e) { error_log('500 h : ' . $e->getMessage()); }
      $k['docs_exp'] = (int)$q("SELECT COUNT(*) FROM pieces_identite p JOIN animatrices a ON a.id = p.animatrice_id WHERE a.actif = 1 AND p.expire_le IS NOT NULL AND p.expire_le <= ?", [date('Y-m-d', strtotime('+30 days'))])->fetchColumn();
      try { require_once __DIR__ . '/poste_lib.php'; $pj = pp_jour($t0); $k['poste'] = ['total' => count($pj), 'en_poste' => count(array_filter($pj, fn($x) => in_array($x['code'], ['en_poste', 'present'], true))),
        'retard' => count(array_filter($pj, fn($x) => $x['code'] === 'retard')), 'non_signale' => count(array_filter($pj, fn($x) => $x['code'] === 'non_signale')), 'absent' => count(array_filter($pj, fn($x) => $x['code'] === 'absent'))]; }
      catch (Throwable $e) { $k['poste'] = null; error_log('Prise de poste : ' . $e->getMessage()); }
      $k['cr_semaine'] = (int)$q("SELECT COUNT(*) FROM rapports WHERE created_at >= ?", [date('Y-m-d', strtotime('monday this week'))])->fetchColumn();
      // Choses à traiter, une ligne par élément : un clic sur une tuile mène à l'élément (ou à la liste s'il y en a plusieurs).
      $T = []; $fd = fn($d) => $d ? date('d/m', strtotime($d)) : '';
      $T['operations'] = array_map(fn($o) => ['t' => $o['nom'], 's' => $o['n'] . ' créneau' . ($o['n'] > 1 ? 'x' : '') . ' sans personne', 'o' => ['k' => 'planning', 'd' => $o['id']]], $ops);
      $T['devis'] = array_map(fn($x) => ['t' => ($x['numero'] ?: 'Devis') . ' · ' . ((json_decode((string)$x['client'], true) ?: [])['raison'] ?? ''), 's' => (string)$x['objet'], 'o' => ['k' => 'doc', 'id' => (int)$x['id']]],
        $q("SELECT id, numero, objet, client FROM g_docs WHERE type = 'devis' AND statut = 'envoye' ORDER BY envoye_le")->fetchAll());
      $T['contrats'] = array_merge(
        array_map(fn($x) => ['t' => $x['nom'], 's' => 'contrat envoyé, pas encore signé · ' . $x['objet'], 'o' => ['k' => 'contrat', 'a' => (int)$x['animatrice_id'], 'd' => (int)$x['devis_id']]],
          $q("SELECT c.animatrice_id, c.devis_id, CONCAT(UPPER(a.nom), ' ', a.prenom) nom, COALESCE(d.objet, '') objet FROM contrats c JOIN animatrices a ON a.id = c.animatrice_id LEFT JOIN g_docs d ON d.id = c.devis_id
            WHERE c.statut = 'envoye' AND c.avenant = (SELECT MAX(avenant) FROM contrats x WHERE x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id) ORDER BY a.nom")->fetchAll()),
        array_map(fn($x) => ['t' => $x['nom'], 's' => 'contrat à envoyer · ' . $x['objet'] . ' · 1re journée le ' . $fd($x['d']), 'o' => ['k' => 'contrat', 'a' => (int)$x['animatrice_id'], 'd' => (int)$x['devis_id']]],
          $q("SELECT m.animatrice_id, m.devis_id, MIN(m.date_mission) d, CONCAT(UPPER(a.nom), ' ', a.prenom) nom, COALESCE(g.objet, '') objet FROM missions m JOIN animatrices a ON a.id = m.animatrice_id LEFT JOIN g_docs g ON g.id = m.devis_id
            WHERE m.animatrice_id IS NOT NULL AND m.devis_id IS NOT NULL AND m.statut <> 'annulee' AND m.date_mission >= ? AND NOT EXISTS (SELECT 1 FROM contrats c WHERE c.animatrice_id = m.animatrice_id AND c.devis_id = m.devis_id)
            GROUP BY m.animatrice_id, m.devis_id ORDER BY d", [$t0])->fetchAll()));
      $T['dpae'] = array_map(fn($x) => ['t' => $x['nom'], 's' => 'DPAE · 1re journée le ' . $fd($x['d']) . ' · ' . $x['objet'], 'o' => ['k' => 'contrat', 'a' => (int)$x['animatrice_id'], 'd' => (int)$x['devis_id']]],
        $q("SELECT x.*, CONCAT(UPPER(a.nom), ' ', a.prenom) nom, COALESCE(g.objet, '') objet FROM (SELECT m.animatrice_id, m.devis_id, MIN(m.date_mission) d FROM missions m WHERE m.animatrice_id IS NOT NULL AND m.devis_id IS NOT NULL AND m.statut <> 'annulee' GROUP BY m.animatrice_id, m.devis_id) x
          JOIN animatrices a ON a.id = x.animatrice_id LEFT JOIN g_docs g ON g.id = x.devis_id WHERE x.d BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM contrats_dpae p WHERE p.animatrice_id = x.animatrice_id AND p.devis_id = x.devis_id) ORDER BY x.d", [$t0, date('Y-m-d', strtotime('+3 days'))])->fetchAll());
      $T['releves'] = array_map(fn($x) => ['t' => $x['nom'], 's' => 'relevé ' . r_mois_txt($x['mois']) . ' envoyé, pas encore signé', 'o' => ['k' => 'releve', 'a' => (int)$x['animatrice_id'], 'm' => $x['mois']]],
        $q("SELECT r.animatrice_id, r.mois, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM releves r JOIN animatrices a ON a.id = r.animatrice_id WHERE r.statut = 'envoye' ORDER BY r.mois, a.nom")->fetchAll());
      $T['candidatures'] = array_map(fn($x) => ['t' => $x['nom'], 's' => $x['n'] . ' candidature' . ($x['n'] > 1 ? 's' : '') . ' à traiter', 'o' => ['k' => 'planning', 'd' => (int)$x['devis_id']]],
        $q("SELECT o.devis_id, CONCAT(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.client, '$.raison')), ''), ' · ', COALESCE(d.objet, '')) nom, COUNT(*) n FROM offres_dest od JOIN offres o ON o.id = od.offre_id LEFT JOIN g_docs d ON d.id = o.devis_id
          WHERE o.statut = 'ouvert' AND od.reponse = 'oui' AND od.retenu = 0 GROUP BY o.devis_id, d.client, d.objet")->fetchAll());
      $T['fins'] = array_map(fn($x) => ['t' => $x['nom'], 's' => 'contrat n°' . $x['numero'] . ' terminé le ' . $fd($x['fin']) . ' · attestation France Travail', 'o' => ['k' => 'contrat', 'a' => $x['animatrice_id'], 'd' => $x['devis_id']]], $k['fins'] ? x_fins_a_faire() : []);
      $T['documents'] = array_map(fn($x) => ['t' => $x['nom'], 's' => (DOCS_TYPES[$x['type']] ?? 'pièce') . ' · ' . ($x['expire_le'] < $t0 ? 'expirée le ' : 'expire le ') . $fd($x['expire_le']), 'o' => ['k' => 'fiche', 'a' => (int)$x['id']]],
        $q("SELECT a.id, p.type, p.expire_le, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM pieces_identite p JOIN animatrices a ON a.id = p.animatrice_id WHERE a.actif = 1 AND p.expire_le IS NOT NULL AND p.expire_le <= ? ORDER BY p.expire_le", [date('Y-m-d', strtotime('+30 days'))])->fetchAll());
      try { require_once __DIR__ . '/evt_lib.php'; ev_schema(); $T['evts'] = array_map(fn($x) => ['t' => $x['objet'], 's' => 'compte rendu du ' . $fd($x['date_evt']) . ' · ' . $x['nom'], 'o' => ['k' => 'evt', 'id' => (int)$x['id']]],
        $q("SELECT e.id, e.date_evt, COALESCE(d.objet, '') objet, CONCAT(a.prenom, ' ', a.nom) nom FROM evenements_cr e JOIN animatrices a ON a.id = e.animatrice_id LEFT JOIN g_docs d ON d.id = e.devis_id WHERE e.valide_le IS NULL ORDER BY e.date_evt")->fetchAll()); $k['evts'] = count($T['evts']); } catch (Throwable $e) { $k['evts'] = 0; }
      try { require_once __DIR__ . '/merch_lib.php'; mc_schema(); $T['mc'] = array_map(fn($x) => ['t' => $x['magasin'], 's' => 'visite du ' . $fd($x['date_visite']) . ' · ' . $x['nom'], 'o' => ['k' => 'mc', 'id' => (int)$x['id']]],
        $q("SELECT v.id, v.magasin, v.date_visite, CONCAT(a.prenom, ' ', a.nom) nom FROM merch_visites v JOIN animatrices a ON a.id = v.animatrice_id WHERE v.valide_le IS NULL ORDER BY v.date_visite")->fetchAll()); $k['mc'] = count($T['mc']); } catch (Throwable $e) { $k['mc'] = 0; }
      if (isset($S5)) $T['cdii'] = array_values(array_map(fn($x) => ['t' => $x['nom'], 's' => round((float)$x['heures_12m']) . ' h sur 12 mois', 'o' => ['k' => 'cdii', 'a' => (int)$x['animatrice_id']]], array_filter($S5, fn($x) => in_array($x['etat'], ['a_proposer', 'retard', 'prevu', 'approche'], true))));
      // Journées du jour et du lendemain, avec le compte rendu reçu ou non
      $jours = $q("SELECT m.date_mission AS date, m.debut, m.fin, m.magasin, m.client, m.animatrice_id, m.devis_id, CONCAT(UPPER(a.nom), ' ', a.prenom) AS nom,
        (SELECT COUNT(*) FROM rapports r WHERE r.animatrice_id = m.animatrice_id AND r.date_animation = m.date_mission) AS cr
        FROM missions m JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date_mission IN (?, ?) AND m.statut <> 'annulee' ORDER BY m.date_mission, m.debut, a.nom", [$t0, $t1])->fetchAll();
      // Fil d'activité
      $since = date('Y-m-d H:i:s', strtotime('-21 days')); $F = [];
      foreach ($q("SELECT l.created_at le, l.texte, l.par, l.devis_id, d.objet, d.numero FROM missions_log l LEFT JOIN g_docs d ON d.id = l.devis_id WHERE l.created_at >= ? ORDER BY l.id DESC LIMIT 40", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['le'], 'type' => 'planning', 'qui' => $x['par'], 'quoi' => $x['texte'], 'sur' => $x['objet'] ?: $x['numero'], 'o' => $x['devis_id'] ? ['k' => 'planning', 'd' => (int)$x['devis_id']] : null];
      foreach ($q("SELECT c.animatrice_id, c.devis_id, c.envoye_le, c.envoye_par, c.signe_le, c.numero, c.avenant, c.signe_test, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM contrats c JOIN animatrices a ON a.id = c.animatrice_id WHERE c.envoye_le >= ? OR c.signe_le >= ?", [$since, $since])->fetchAll() as $x) {
        $t = ($x['avenant'] ? 'avenant n°' . $x['avenant'] . ' au contrat n°' : 'contrat n°') . $x['numero'];
        if ($x['envoye_le'] >= $since) $F[] = ['le' => $x['envoye_le'], 'type' => 'contrat', 'qui' => $x['envoye_par'], 'quoi' => 'a envoyé le ' . $t, 'sur' => $x['nom'], 'o' => ['k' => 'contrat', 'a' => (int)$x['animatrice_id'], 'd' => (int)$x['devis_id']]];
        if ($x['signe_le'] && $x['signe_le'] >= $since) $F[] = ['le' => $x['signe_le'], 'type' => 'signe', 'qui' => $x['nom'], 'quoi' => 'a signé le ' . $t . ($x['signe_test'] ? ' (test)' : ''), 'sur' => '', 'o' => ['k' => 'contrat', 'a' => (int)$x['animatrice_id'], 'd' => (int)$x['devis_id']]];
      }
      foreach ($q("SELECT r.animatrice_id, r.envoye_le, r.envoye_par, r.signe_le, r.mois, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM releves r JOIN animatrices a ON a.id = r.animatrice_id WHERE r.envoye_le >= ? OR r.signe_le >= ?", [$since, $since])->fetchAll() as $x) {
        if ($x['envoye_le'] >= $since) $F[] = ['le' => $x['envoye_le'], 'type' => 'releve', 'qui' => $x['envoye_par'], 'quoi' => 'a envoyé le relevé d\'heures ' . r_mois_txt($x['mois']), 'sur' => $x['nom'], 'o' => ['k' => 'releve', 'a' => (int)$x['animatrice_id'], 'm' => $x['mois']]];
        if ($x['signe_le'] && $x['signe_le'] >= $since) $F[] = ['le' => $x['signe_le'], 'type' => 'signe', 'qui' => $x['nom'], 'quoi' => 'a signé son relevé d\'heures ' . r_mois_txt($x['mois']), 'sur' => '', 'o' => ['k' => 'releve', 'a' => (int)$x['animatrice_id'], 'm' => $x['mois']]];
      }
      foreach ($q("SELECT od.repondu_le, od.reponse, o.devis_id, CONCAT(UPPER(a.nom), ' ', a.prenom) nom, d.objet FROM offres_dest od JOIN offres o ON o.id = od.offre_id JOIN animatrices a ON a.id = od.animatrice_id LEFT JOIN g_docs d ON d.id = o.devis_id WHERE od.repondu_le >= ?", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['repondu_le'], 'type' => 'offre', 'qui' => $x['nom'], 'quoi' => $x['reponse'] === 'oui' ? 'a postulé sur une mission' : 'n\'est pas disponible pour une mission', 'sur' => (string)$x['objet'], 'o' => $x['devis_id'] ? ['k' => 'planning', 'd' => (int)$x['devis_id']] : null];
      foreach ($q("SELECT r.id, r.created_at, r.magasin, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM rapports r JOIN animatrices a ON a.id = r.animatrice_id WHERE r.created_at >= ? ORDER BY r.id DESC LIMIT 30", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['created_at'], 'type' => 'cr', 'qui' => $x['nom'], 'quoi' => 'a envoyé son compte rendu', 'sur' => (string)$x['magasin'], 'o' => ['k' => 'cr', 'id' => (int)$x['id']]];
      foreach ($q("SELECT a.id, a.created_at, CONCAT(UPPER(a.nom), ' ', a.prenom) nom, a.zone FROM animatrices a WHERE a.created_at >= ?", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['created_at'], 'type' => 'inscription', 'qui' => $x['nom'], 'quoi' => 's\'est inscrit(e) dans l\'appli', 'sur' => (string)$x['zone'], 'o' => ['k' => 'fiche', 'a' => (int)$x['id']]];
      foreach ($q("SELECT f.animatrice_id, f.updated_at, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM fiches_animatrices f JOIN animatrices a ON a.id = f.animatrice_id WHERE f.updated_at >= ? ORDER BY f.updated_at DESC LIMIT 20", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['updated_at'], 'type' => 'fiche', 'qui' => $x['nom'], 'quoi' => 'fiche mise à jour', 'sur' => '', 'o' => ['k' => 'fiche', 'a' => (int)$x['animatrice_id']]];
      if ($pdo->query("SHOW TABLES LIKE 'pieces_identite'")->fetchColumn()) foreach ($q("SELECT p.animatrice_id, p.created_at, p.type, p.par, CONCAT(UPPER(a.nom), ' ', a.prenom) nom FROM pieces_identite p JOIN animatrices a ON a.id = p.animatrice_id WHERE p.created_at >= ? ORDER BY p.id DESC LIMIT 30", [$since])->fetchAll() as $x)
        $F[] = ['le' => $x['created_at'], 'type' => 'document', 'qui' => ($x['par'] ?? '') ?: $x['nom'], 'quoi' => 'a déposé : ' . (DOCS_TYPES[$x['type']] ?? 'document'), 'sur' => ($x['par'] ?? '') ? $x['nom'] : '', 'o' => ['k' => 'fiche', 'a' => (int)$x['animatrice_id']]];
      foreach ($q("SELECT id, accepte_le, accepte_ip, statut, numero, objet, client FROM g_docs WHERE type = 'devis' AND accepte_le >= ?", [$since])->fetchAll() as $x) { $c = json_decode((string)$x['client'], true) ?: [];
        $F[] = ['le' => $x['accepte_le'], 'type' => $x['statut'] === 'refuse' ? 'refus' : 'devis', 'qui' => $c['raison'] ?? 'Client', 'quoi' => ($x['statut'] === 'refuse' ? 'a refusé le devis ' : ($x['accepte_ip'] === 'e-mail' ? 'a donné son accord par e-mail pour le devis ' : 'a accepté le devis ')) . $x['numero'], 'sur' => (string)$x['objet'], 'o' => ['k' => 'doc', 'id' => (int)$x['id']]]; }
      usort($F, fn($a, $b) => strcmp((string)$b['le'], (string)$a['le']));
      // Fiches mises à jour en série (import d'un fichier) : regroupées en une seule ligne.
      $G = []; foreach ($F as $f) { $last = end($G);
        if ($f['type'] === 'fiche' && $last && $last['type'] === 'fiche' && substr($last['le'], 0, 16) === substr($f['le'], 0, 16)) { $kl = array_key_last($G); $G[$kl]["n"] = ($G[$kl]["n"] ?? 1) + 1; continue; }
        $G[] = $f; }
      foreach ($G as &$f) if (($f['n'] ?? 1) > 1) { $f['qui'] = $f['n'] . ' fiches'; $f['quoi'] = 'mises à jour en même temps (import ou correction groupée)'; } unset($f);
      $F = array_slice($G, 0, 60);
      foreach ($F as &$f) $f['min'] = max(0, (int)round((time() - strtotime((string)$f['le'])) / 60)); unset($f); // calculé ici : l'heure du serveur fait foi, quel que soit le fuseau de l'appareil
      // Premiers pas : ce qu'il reste à régler pour démarrer (masqué quand tout est fait, ou à la demande).
      $R = g_reg(); tf_schema(); $n = fn($sql) => (int)$pdo->query($sql)->fetchColumn(); $ip = fn($v) => strlen(preg_replace('/\s/', '', (string)$v)) >= 15;
      $tfMoi = !empty($_SESSION['admin_id']) && (int)$pdo->query("SELECT totp_actif FROM admins WHERE id = " . (int)$_SESSION['admin_id'])->fetchColumn();
      $pp = [['logo', 'Déposer votre logo', is_file(__DIR__ . '/uploads/logo-agence.png') || is_file(__DIR__ . '/uploads/logo-agence-blanc.png'), 'reglages'],
        ['societe', 'Compléter adresse, téléphone et SIRET', trim($R['adresse']) !== '' && trim($R['telephone']) !== '' && trim($R['siret']) !== '', 'reglages'],
        ['banque', 'Renseigner votre IBAN (factures et virements)', $ip($R['iban']), 'reglages'],
        ['contrats', 'Régler les contrats de travail (représentant, URSSAF, caisses)', trim($R['ct_representant']) !== '' && trim($R['ct_urssaf']) !== '' && trim($R['ct_caisse']) !== '', 'reglages'],
        ['magasins', 'Ajouter vos points d\'intervention (magasins, salons, lieux d\'événement)', $n("SELECT COUNT(*) FROM magasins") > 0, 'magasins'],
        ['clients', 'Ajouter vos clients (ou les importer)', $n("SELECT COUNT(*) FROM g_clients") > 0, 'clients'],
        ['equipe', 'Inviter votre équipe (code de l\'appli, ou import Excel)', $n("SELECT COUNT(*) FROM animatrices") > 0, 'animatrices'],
        ['securite', 'Activer la double authentification sur votre compte', $tfMoi, 'moncompte']];
      if (in_array('anim', g_poles_actifs($R), true)) $pp[] = ['cidd', 'Saisir votre modèle de contrat CIDD (validé par votre avocat)', trim((string)$R['ct_modele']) !== '' && (bool)$R['ct_valide'], 'reglages'];
      if (in_array('hot', g_poles_actifs($R), true)) $pp[] = ['cddu', 'Saisir votre modèle de contrat CDDU (validé par votre avocat)', trim((string)$R['hot_modele']) !== '' && (bool)$R['hot_valide'], 'reglages'];
      $pp = array_map(fn($x) => ['cle' => $x[0], 'texte' => $x[1], 'fait' => $x[2], 'cible' => $x[3]], $pp);
      $demarrage = reglage('pp_masque', '') === '1' || !array_filter($pp, fn($x) => !$x['fait']) ? null : $pp;
      gout(['kpi' => $k, 'todo' => $T, 'ops' => $ops, 'jours' => $jours, 'fil' => $F, 'aujourdhui' => $t0, 'demain' => $t1, 'moi' => explode(' ', admin_nom())[0], 'demarrage' => $demarrage, 'code_appli' => reglage('code_agence', '')]);
    case 'pp_masquer':
      db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('pp_masque', '1')")->execute(); gout(['ok' => true]);

    case 'client_save':
      $raison = clip($in['raison'] ?? '', 160); if ($raison === '') gfail('Indiquez la raison sociale.');
      $em = implode(', ', emails_list($in['emails'] ?? '')); $cc = implode(', ', emails_list($in['cc'] ?? ''));
      $cpt = ($in['compte'] ?? '') === '2' ? '2' : ''; require_once __DIR__ . '/presence_lib.php'; pr_schema();
      $prm = isset(PR_MODES[$in['presence_mode'] ?? '']) ? $in['presence_mode'] : 'incident'; $pre = implode(', ', emails_list($in['presence_emails'] ?? '')); $pra = !empty($in['presence_auto']) && $in['presence_auto'] !== '0' ? 1 : 0;
      $v = [$raison, clip($in['contact'] ?? '', 160), clip($in['adresse'] ?? '', 400), clip($in['cp'] ?? '', 20), clip($in['ville'] ?? '', 120), clip($in['pays'] ?? 'France', 80) ?: 'France',
        $em, $cc, clip($in['tva_intra'] ?? '', 40), isset(G_TVA[(string)($in['tva'] ?? '')]) ? (string)$in['tva'] : '20', isset(G_PAIEMENTS[$in['paiement'] ?? '']) ? $in['paiement'] : '30j', clip($in['notes'] ?? '', 2000), clip($in['tarif'] ?? '', 40)];
      if (!empty($in['id'])) db()->prepare("UPDATE g_clients SET raison=?, contact=?, adresse=?, cp=?, ville=?, pays=?, emails=?, cc=?, tva_intra=?, tva=?, paiement=?, notes=?, tarif=?, compte=? WHERE id=?")->execute([...$v, $cpt, (int)$in['id']]);
      else db()->prepare("INSERT INTO g_clients (raison, contact, adresse, cp, ville, pays, emails, cc, tva_intra, tva, paiement, notes, tarif, compte, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$v, $cpt, now()]);
      $cid = !empty($in['id']) ? (int)$in['id'] : (int)db()->lastInsertId();
      db()->prepare("UPDATE g_clients SET presence_mode = ?, presence_emails = ?, presence_auto = ? WHERE id = ?")->execute([$prm, $pre, $pra, $cid]);
      gout(['ok' => true, 'id' => $cid]);

    case 'client_delete':
      db()->prepare("UPDATE g_clients SET actif = 0 WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); gout(['ok' => true]); // ses devis et factures restent intacts

    case 'accepter_email':
      // Le client a donné son accord par e-mail : l'agence enregistre l'acceptation pour lui, avec la preuve (date, expéditeur, e-mail joint).
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Devis introuvable.', 404); if ($d['type'] !== 'devis') gfail('Ce document n\'est pas un devis.');
      $de = trim((string)($in['de'] ?? '')); $de = preg_replace('/^.*<([^>]+)>.*$/', '$1', $de);
      if (!filter_var($de, FILTER_VALIDATE_EMAIL)) gfail('Indiquez l\'adresse e-mail de la personne qui a donné l\'accord.');
      $le = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string)($in['le'] ?? '')) ? str_replace('T', ' ', $in['le']) . ':00' : '';
      if ($le === '' || $le > now()) gfail('Indiquez la date et l\'heure de l\'e-mail (pas dans le futur).');
      $nom = clip($in['nom'] ?? '', 120);
      $b64 = (string)($in['fichier'] ?? ''); if ($b64 === '') gfail('Joignez l\'e-mail du client (enregistré en .eml, .msg ou .pdf, ou une capture).');
      $data = base64_decode(preg_replace('/^data:[^,]*,/', '', $b64), true); if ($data === false || strlen($data) < 20) gfail('Fichier illisible.');
      if (strlen($data) > 12 * 1024 * 1024) gfail('Fichier trop lourd (12 Mo au plus).');
      $fn = clip(basename((string)($in['nom_fichier'] ?? 'accord')), 120) ?: 'accord'; $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
      $mime = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook', 'txt' => 'text/plain'][$ext] ?? '';
      if ($mime === '') gfail('Formats acceptés : .eml, .msg, .pdf, .png, .jpg, .txt.');
      $f = 'accord_' . $d['id'] . '_' . bin2hex(random_bytes(6)) . '.bin'; file_put_contents(fiche_dir() . '/' . $f, amy_chiffre($data));
      $preuve = json_encode(['fichier' => $f, 'nom' => $fn, 'mime' => $mime, 'sha256' => hash('sha256', $data), 'taille' => strlen($data), 'enregistre_le' => now(), 'enregistre_par' => admin_nom()], JSON_UNESCAPED_UNICODE);
      db()->prepare("UPDATE g_docs SET statut = 'accepte', accepte_le = ?, accepte_par = ?, accepte_ip = 'e-mail', accepte_preuve = ?, refus_motif = NULL WHERE id = ?")
        ->execute([$le, clip(($nom !== '' ? $nom . ' ' : '') . '<' . $de . '>, enregistré par ' . admin_nom(), 250), $preuve, $d['id']]);
      gout(['ok' => true, 'doc' => g_doc($d['id'])]);

    case 'preuve':
      $d = g_doc((int)($_GET['id'] ?? 0)) ?: gfail('Devis introuvable.', 404); $p = json_decode((string)$d['accepte_preuve'], true);
      if (!$p || !is_file($fp = fiche_dir() . '/' . basename($p['fichier']))) gfail('Aucune preuve enregistrée.', 404);
      $data = amy_dechiffre((string)file_get_contents($fp)); if ($data === null) gfail('Preuve illisible.', 500);
      $aff = in_array($p['mime'], ['application/pdf', 'image/png', 'image/jpeg'], true);
      header('Content-Type: ' . ($aff ? $p['mime'] : 'application/octet-stream')); header('X-Content-Type-Options: nosniff');
      header('Content-Disposition: ' . ($aff && !isset($_GET['dl']) ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]+/', '_', $p['nom']) . '"');
      echo $data; exit;

    case 'avenant':
      // Avenant au devis : jours supplémentaires demandés par le client, sur un document à part qui renvoie au devis initial.
      $d = g_doc((int)($in['id'] ?? 0)) ?: gfail('Devis introuvable.', 404); if ($d['type'] !== 'devis') gfail('Ce document n\'est pas un devis.');
      $base = $d['avenant_de'] ? (g_doc((int)$d['avenant_de']) ?: $d) : $d;
      $n = (int)db()->query("SELECT COUNT(*) FROM g_docs WHERE type = 'devis' AND avenant_de = " . (int)$base['id'])->fetchColumn() + 1;
      $lig = array_values(array_map(fn($l) => array_merge($l, ['dates' => [], 'maj' => [], 'fnt' => [], 'total' => 0]), array_filter($base['lignes'], fn($l) => $l['type'] === 'presta')));
      db()->prepare("INSERT INTO g_docs (type, numero, client_id, client, date_doc, validite, objet, zone, paiement, acompte, tva, tva_mention, lignes, mention, notes, total_ht, total_tva, total_ttc, statut, par, created_at, avenant_de, ref_type, ref_num)
        VALUES ('devis',?,?,?,?,?,?,?,?,0,?,?,?,?,'',0,0,0,'brouillon',?,?,?,?,?)")->execute([$base['numero'] . '-A' . $n, $base['client_id'], json_encode($base['client'], JSON_UNESCAPED_UNICODE), date('Y-m-d'),
        date('Y-m-d', strtotime('+' . (int)g_reg()['validite_jours'] . ' days')), mb_substr('Avenant n°' . $n . ' · ' . $base['objet'], 0, 300), $base['zone'], $base['paiement'], $base['tva'], $base['tva_mention'] ?? '',
        json_encode($lig, JSON_UNESCAPED_UNICODE), $base['mention'], admin_nom(), now(), $base['id'], $base['ref_type'] ?? '', $base['ref_num'] ?? '']);
      $nid = (int)db()->lastInsertId(); db()->prepare("UPDATE g_docs SET pole = ? WHERE id = ?")->execute([g_pole($base), $nid]);
      gout(['ok' => true, 'id' => $nid]);

    case 'operations_suivi':
      // Opérations en cours (comme Plany) : statut, dates, ce qui reste à faire, personnes affectées et bookées (contrat signé).
      require_once __DIR__ . '/contrats_lib.php'; c_schema(); $pdo = db(); $t0 = date('Y-m-d');
      $fac = []; foreach ($pdo->query("SELECT devis_id, numero, statut FROM g_docs WHERE type = 'facture' AND devis_id IS NOT NULL")->fetchAll() as $f) $fac[(int)$f['devis_id']] = $f;
      $ct = []; foreach ($pdo->query("SELECT c.animatrice_id, c.devis_id, c.statut FROM contrats c JOIN (SELECT animatrice_id, devis_id, MAX(avenant) av FROM contrats GROUP BY animatrice_id, devis_id) x
        ON x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id AND x.av = c.avenant")->fetchAll() as $c) $ct[$c['devis_id'] . '|' . $c['animatrice_id']] = $c['statut'];
      $dp = []; foreach ($pdo->query("SELECT animatrice_id, devis_id FROM contrats_dpae")->fetchAll() as $x) $dp[$x['devis_id'] . '|' . $x['animatrice_id']] = true;
      $cr = []; $st = $pdo->prepare("SELECT animatrice_id, date_animation FROM rapports WHERE date_animation >= ?"); $st->execute([date('Y-m-d', strtotime('-18 months'))]);
      foreach ($st->fetchAll() as $x) $cr[$x['animatrice_id'] . '|' . $x['date_animation']] = true;
      require_once __DIR__ . '/evt_lib.php'; ev_schema(); $ev = []; foreach ($pdo->query("SELECT devis_id, date_evt FROM evenements_cr WHERE devis_id IS NOT NULL")->fetchAll() as $x) $ev[$x['devis_id'] . '|' . $x['date_evt']] = true;
      $ids = $pdo->prepare("SELECT id FROM g_docs WHERE type = 'devis' AND statut <> 'refuse' AND (date_doc >= ? OR id IN (SELECT DISTINCT devis_id FROM missions WHERE date_mission >= ?)) ORDER BY date_doc DESC, id DESC LIMIT 400");
      $ids->execute([date('Y-m-d', strtotime('-14 months')), date('Y-m-d', strtotime('-2 months'))]); $out = [];
      foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $d = g_doc((int)$id); if (!$d) continue; $S = array_values(array_filter(p_operation($d), fn($s) => $s['statut'] !== 'annulee'));
        $dates = array_column($S, 'date'); sort($dates); $du = $dates[0] ?? null; $au = $dates ? end($dates) : null;
        $aff = array_filter($S, fn($s) => $s['animatrice_id']); $book = array_filter($aff, fn($s) => ($ct[$d['id'] . '|' . $s['animatrice_id']] ?? '') === 'signe');
        $f = $fac[(int)$d['id']] ?? null; $facturee = $f && $f['numero'];
        if ($facturee) $statut = 'facturee'; elseif (in_array($d['statut'], ['brouillon', 'envoye'], true)) $statut = 'creation';
        elseif (!$du || $du > $t0) $statut = 'a_terme'; elseif ($au >= $t0) $statut = 'en_cours'; else $statut = 'terminee';
        $T = [];
        if ($d['statut'] === 'brouillon') $T[] = ['t' => 'devis à envoyer au client', 'go' => 'devis'];
        if ($d['statut'] === 'envoye') $T[] = ['t' => 'accord du client à obtenir', 'go' => 'devis'];
        $vide = count(array_filter($S, fn($s) => !$s['animatrice_id'] && $s['date'] >= $t0)); if ($vide) $T[] = ['t' => $vide . ' créneau' . ($vide > 1 ? 'x' : '') . ' à pourvoir', 'go' => 'planning'];
        $pers = array_unique(array_map(fn($s) => (int)$s['animatrice_id'], array_filter($aff, fn($s) => $s['date'] >= $t0)));
        $sansC = count(array_filter($pers, fn($a) => !isset($ct[$d['id'] . '|' . $a]))); if ($sansC) $T[] = ['t' => $sansC . ' contrat' . ($sansC > 1 ? 's' : '') . ' à envoyer', 'go' => 'contrats'];
        $aSig = count(array_filter($pers, fn($a) => ($ct[$d['id'] . '|' . $a] ?? '') === 'envoye')); if ($aSig) $T[] = ['t' => $aSig . ' contrat' . ($aSig > 1 ? 's' : '') . ' à faire signer', 'go' => 'contrats'];
        $prem = []; foreach ($aff as $s) { $k = (int)$s['animatrice_id']; if (!isset($prem[$k]) || $s['date'] < $prem[$k]) $prem[$k] = $s['date']; }
        $dpae = count(array_filter(array_keys($prem), fn($a) => $prem[$a] >= $t0 && $prem[$a] <= date('Y-m-d', strtotime('+8 days')) && !isset($dp[$d['id'] . '|' . $a]))); if ($dpae) $T[] = ['t' => $dpae . ' DPAE à faire', 'go' => 'contrats'];
        if (g_pole($d) === 'hot') { // accueil : un rapport d'événement par jour (le chef d'équipe pour l'équipe)
          $jr = array_unique(array_map(fn($s) => $s['date'], array_filter($aff, fn($s) => $s['date'] < $t0))); $sansCr = count(array_filter($jr, fn($j) => !isset($ev[$d['id'] . '|' . $j])));
          if ($sansCr) $T[] = ['t' => $sansCr . ' rapport' . ($sansCr > 1 ? 's' : '') . ' d\'événement manquant' . ($sansCr > 1 ? 's' : ''), 'go' => 'evts']; }
        else { $sansCr = count(array_filter($aff, fn($s) => $s['date'] < $t0 && !isset($cr[$s['animatrice_id'] . '|' . $s['date']]))); if ($sansCr) $T[] = ['t' => $sansCr . ' compte' . ($sansCr > 1 ? 's' : '') . ' rendu' . ($sansCr > 1 ? 's' : '') . ' manquant' . ($sansCr > 1 ? 's' : ''), 'go' => 'rapports']; }
        if ($statut === 'terminee') $T[] = ['t' => $f ? 'facture à émettre' : 'facture à créer', 'go' => 'devis'];
        $out[] = ['id' => (int)$d['id'], 'numero' => (string)$d['numero'], 'nom' => (string)$d['objet'], 'client' => (string)($d['client']['raison'] ?? ''), 'zone' => (string)$d['zone'], 'devis_statut' => $d['statut'], 'pole' => g_pole($d), 'type_op' => (string)($d['type_op'] ?? ''),
          'statut' => $statut, 'du' => $du, 'au' => $au, 'jours' => count(array_unique($dates)), 'creneaux' => count($S), 'affectes' => count($aff), 'bookes' => count($book),
          'personnes' => count(array_unique(array_column($aff, 'animatrice_id'))), 'taches' => $T, 'ht' => (float)$d['total_ht'], 'facture' => $f ? ($f['numero'] ?: 'brouillon') : null];
      }
      gout(['operations' => $out, 'aujourdhui' => $t0]);

    case 'poste_jour':
      require_once __DIR__ . '/poste_lib.php'; $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
      // Période (du … au …, 62 jours au plus) : les lignes de chaque jour, avec leur date.
      $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['au'] ?? '')) && $_GET['au'] > $date ? min($_GET['au'], date('Y-m-d', strtotime($date . ' +61 days'))) : $date; $L = [];
      for ($j = $date; $j <= $au; $j = date('Y-m-d', strtotime($j . ' +1 day'))) foreach (pp_jour($j) as $x) $L[] = $x + ['jour' => $j];
      gout(['date' => $date, 'au' => $au, 'lignes' => $L, 'etats' => PP_ETATS, 'tolerance' => PP_TOLERANCE, 'rayon' => PP_RAYON]);

    case 'poste_marquer':
      // l'agence corrige à la main : absent(e), ou présent(e) (prévenu par téléphone, pas de réseau…). Vide : retour au constat automatique.
      require_once __DIR__ . '/poste_lib.php'; pp_schema(); $mid = (int)($in['mission_id'] ?? 0); $s = (string)($in['statut'] ?? '');
      if (!in_array($s, ['', 'absent', 'present'], true)) gfail('Statut inconnu.');
      $st = db()->prepare("SELECT animatrice_id, date_mission FROM missions WHERE id = ?"); $st->execute([$mid]); $m = $st->fetch() ?: gfail('Mission introuvable.', 404);
      $aid = (int)($in['animatrice_id'] ?? 0) ?: (int)$m['animatrice_id']; // une personne déjà remplacée garde sa propre ligne
      db()->prepare("INSERT INTO prises_poste (mission_id, animatrice_id, date_mission, statut_admin, note, par, modifie_le) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE statut_admin = VALUES(statut_admin), note = VALUES(note), par = VALUES(par), modifie_le = VALUES(modifie_le)")
        ->execute([$mid, $aid, $m['date_mission'], $s, clip($in['note'] ?? '', 300), admin_nom(), now()]);
      gout(['ok' => true]);

    case 'partager':
      // Journée partagée : relais par une autre personne en cours de journée, et/ou demi-journée dans un autre point d'intervention.
      try { gout(p_partager((int)($in['mission_id'] ?? 0), (string)($in['heure'] ?? ''), (int)($in['animatrice_id'] ?? 0), (string)($in['magasin'] ?? ''), (int)($in['pause1'] ?? 0), (int)($in['pause2'] ?? 0))); }
      catch (RuntimeException $e) { gfail($e->getMessage()); }

    case 'departager':
      try { p_departager((int)($in['mission_id'] ?? 0)); gout(['ok' => true]); } catch (RuntimeException $e) { gfail($e->getMessage()); }

    case 'poste_remplacer':
      require_once __DIR__ . '/poste_lib.php'; try { gout(pp_remplacer((int)($in['mission_id'] ?? 0), (int)($in['animatrice_id'] ?? 0))); } catch (RuntimeException $e) { gfail($e->getMessage()); }

    case 'rup':
    case 'journal_ventes':
      // Export comptable : journal des ventes (VT), une écriture par facture émise, prête à importer ou à transmettre au cabinet comptable.
      require_once __DIR__ . '/xlsx_lib.php';
      $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['du'] ?? '')) ? $_GET['du'] : date('Y-m-01'); $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['au'] ?? '')) ? $_GET['au'] : date('Y-m-t');
      $st = db()->prepare("SELECT * FROM g_docs WHERE type = 'facture' AND numero IS NOT NULL AND statut IN ('emise','payee') AND date_doc BETWEEN ? AND ? ORDER BY date_doc, numero"); $st->execute([$du, $au]);
      $cpt = fn(string $r) => '411' . substr(preg_replace('/[^A-Z0-9]/', '', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', $r))), 0, 8);
      $R = [1 => [0 => ['v' => 'Journal des ventes · du ' . g_date($du) . ' au ' . g_date($au), 's' => 'y_title']], 2 => [0 => ['v' => AG_NOM . ' · comptes à adapter à votre plan comptable (411 client, 706 prestations, 44571 TVA collectée).', 's' => 'y_hint']]];
      foreach (['Date', 'Journal', 'N° de pièce', 'Compte', 'Libellé', 'Débit', 'Crédit', 'Échéance', 'Statut'] as $i => $h) $R[3][$i] = ['v' => $h, 's' => 'y_head'];
      $r = 4; $nb = 0;
      foreach ($st->fetchAll() as $f) { $c = json_decode((string)$f['client'], true) ?: []; $nomc = (string)($c['raison'] ?? 'Client'); $lib = 'Facture ' . $f['numero'] . ' ' . $nomc; $nb++;
        foreach ([[$cpt($nomc), (float)$f['total_ttc'], 0.0], ['706000', 0.0, (float)$f['total_ht']], ['445710', 0.0, (float)$f['total_tva']]] as [$co, $de, $cr]) { if (!$de && !$cr) continue;
          $R[$r++] = [0 => ['d' => $f['date_doc'], 's' => 'y_date'], 1 => ['v' => 'VT', 's' => 'y_txt'], 2 => ['v' => (string)$f['numero'], 's' => 'y_txt'], 3 => ['v' => $co, 's' => 'y_txt'], 4 => ['v' => $lib, 's' => 'y_txt'],
            5 => ['v' => $de ?: '', 's' => 'y_eur'], 6 => ['v' => $cr ?: '', 's' => 'y_eur'], 7 => $f['echeance'] ? ['d' => $f['echeance'], 's' => 'y_date'] : ['v' => '', 's' => 'y_txt'], 8 => ['v' => $f['statut'] === 'payee' ? 'payée' : 'émise', 's' => 'y_txt']]; } }
      $last = max(4, $r - 1); $R[$r] = [0 => ['v' => 'TOTAL', 's' => 'y_tot'], 5 => ['f' => "SUM(F4:F$last)", 's' => 'y_tot'], 6 => ['f' => "SUM(G4:G$last)", 's' => 'y_tot']];
      $bin = xl_book([['name' => 'Journal des ventes', 'rows' => $R, 'widths' => [0 => 12, 1 => 8, 2 => 14, 3 => 14, 4 => 46, 5 => 12, 6 => 12, 7 => 12, 8 => 10], 'heights' => [3 => 24], 'freeze' => 'A4']]);
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Journal_des_ventes_' . $du . '_' . $au . '.xlsx"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit;

    case 'rup_xlsx':
    case 'rup_pdf':
      // Registre unique du personnel (art. L1221-13 et D1221-23 du Code du travail) : une ligne par embauche (contrat), dans l'ordre des entrées.
      require_once __DIR__ . '/rup_lib.php'; $R = rup_lignes();
      if ($action === 'rup') gout(['lignes' => $R]);
      if ($action === 'rup_xlsx') { $bin = rup_xlsx($R); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Registre_unique_du_personnel_' . date('Y-m-d') . '.xlsx"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit; }
      require_once __DIR__ . '/report_lib.php'; @set_time_limit(120); header('Content-Type: application/pdf');
      header('Content-Disposition: inline; filename="Registre_unique_du_personnel_' . date('Y-m-d') . '.pdf"'); echo make_pdf(rup_html($R)); exit;

    case 'factures_ae':
      require_once __DIR__ . '/ae_lib.php'; $w = []; $a = [];
      if (isset(AE_STATUTS[$_GET['statut'] ?? ''])) { $w[] = 'f.statut = ?'; $a[] = $_GET['statut']; }
      gout(['factures' => ae_liste($w, $a), 'statuts' => AE_STATUTS]);
    case 'facture_ae_statut':
      require_once __DIR__ . '/ae_lib.php'; ae_schema(); $s = (string)($in['statut'] ?? ''); if (!isset(AE_STATUTS[$s])) gfail('Statut inconnu.');
      foreach (array_map('intval', (array)($in['ids'] ?? [])) as $id) { db()->prepare("UPDATE factures_ae SET statut = ?, motif = ?, par = ?, decide_le = ? WHERE id = ?")->execute([$s, clip($in['motif'] ?? '', 300), admin_nom(), now(), $id]);
        $st = db()->prepare("SELECT animatrice_id, numero, montant FROM factures_ae WHERE id = ?"); $st->execute([$id]); if ($x = $st->fetch()) { require_once __DIR__ . '/extras_lib.php';
          lp_notif((int)$x['animatrice_id'], 'Facture ' . mb_strtolower(AE_STATUTS[$s]), 'Facture ' . ($x['numero'] ?: '') . ' de ' . g_eur((float)$x['montant']) . ' : ' . mb_strtolower(AE_STATUTS[$s]) . '.', 'docs'); } }
      gout(['ok' => true]);
    case 'ndf_admin':
      // Notes de frais : à valider, validées (reprises dans la paye du mois de la dépense), refusées, payées.
      require_once __DIR__ . '/extras_lib.php'; $w = []; $a = [];
      if (($s = (string)($_GET['statut'] ?? '')) !== '' && isset(NDF_STATUTS[$s])) { $w[] = 'n.statut = ?'; $a[] = $s; }
      if (preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mois'] ?? ''))) { $w[] = 'n.jour BETWEEN ? AND ?'; $a[] = $_GET['mois'] . '-01'; $a[] = date('Y-m-t', strtotime($_GET['mois'] . '-01')); }
      if ((int)($_GET['animatrice_id'] ?? 0)) { $w[] = 'n.animatrice_id = ?'; $a[] = (int)$_GET['animatrice_id']; }
      gout(['ndf' => ndf_liste($w, $a), 'types' => NDF_TYPES, 'statuts' => NDF_STATUTS, 'a_valider' => (int)db()->query("SELECT COUNT(*) FROM ndf WHERE statut = 'a_valider'")->fetchColumn()]);

    case 'ndf_decider':
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $s = (string)($in['statut'] ?? ''); if (!isset(NDF_STATUTS[$s])) gfail('Statut inconnu.');
      $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))); if (!$ids) gfail('Aucune note choisie.');
      $motif = clip($in['motif'] ?? '', 300); if ($s === 'refusee' && $motif === '') gfail('Indiquez le motif du refus : la personne le verra.');
      foreach ($ids as $id) { $st = db()->prepare("SELECT animatrice_id, jour, type, montant, statut FROM ndf WHERE id = ?"); $st->execute([$id]); $n = $st->fetch(); if (!$n) continue;
        db()->prepare("UPDATE ndf SET statut = ?, motif = ?, par = ?, decide_le = ?, paye_mois = ? WHERE id = ?")->execute([$s, $s === 'refusee' ? $motif : '', admin_nom(), now(), $s === 'payee' ? date('Y-m') : null, $id]);
        if (in_array($s, ['validee', 'refusee'], true) && $n['statut'] !== $s)
          lp_notif((int)$n['animatrice_id'], 'Note de frais ' . ($s === 'validee' ? 'validée' : 'refusée'), (NDF_TYPES[$n['type']] ?? '') . ' du ' . g_date($n['jour']) . ', ' . g_eur((float)$n['montant']) . ($s === 'refusee' ? ' : ' . $motif : ' : remboursée avec votre paye.'), 'ndf'); }
      gout(['ok' => true]);

    case 'fin_ft':
      // Attestation France Travail établie sur l'espace employeur France Travail à la fin du contrat : notée ici (la tâche disparaît du tableau de bord).
      require_once __DIR__ . '/extras_lib.php'; x_schema();
      if (!empty($in['annuler'])) db()->prepare("DELETE FROM contrats_fin WHERE animatrice_id = ? AND devis_id = ?")->execute([(int)$in['animatrice_id'], (int)$in['devis_id']]);
      else db()->prepare("REPLACE INTO contrats_fin (animatrice_id, devis_id, ft_le, par) VALUES (?,?,?,?)")->execute([(int)$in['animatrice_id'], (int)$in['devis_id'], date('Y-m-d'), admin_nom()]);
      gout(['ok' => true]);

    case 'presence_jour':
      require_once __DIR__ . '/presence_lib.php'; $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['date'] ?? '')) ? $_GET['date'] : date('Y-m-d');
      gout(['messages' => pr_jour($date), 'modes' => PR_MODES]);

    case 'presence_envoyer':
      require_once __DIR__ . '/presence_lib.php'; $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['date'] ?? '')) ? $in['date'] : date('Y-m-d');
      try { if (!pr_envoyer((int)($in['client_id'] ?? 0), $date, (string)($in['a'] ?? ''), clip($in['sujet'] ?? '', 240), clip($in['texte'] ?? '', 8000), admin_nom())) gfail('L\'e-mail n\'a pas pu partir. Réessayez.'); }
      catch (RuntimeException $e) { gfail($e->getMessage()); }
      gout(['ok' => true]);

    case 'presence_ignorer':
      require_once __DIR__ . '/presence_lib.php'; pr_schema();
      db()->prepare("REPLACE INTO presence_envois (client_id, jour, statut, envoye_le, par) VALUES (?,?,'ignore',?,?)")->execute([(int)($in['client_id'] ?? 0), (string)($in['date'] ?? date('Y-m-d')), now(), admin_nom()]);
      gout(['ok' => true]);

    case 'presence_recap':
      require_once __DIR__ . '/presence_lib.php'; try { gout(pr_recap((int)($_GET['devis_id'] ?? 0))); } catch (RuntimeException $e) { gfail($e->getMessage()); }

    case 'presence_recap_envoyer':
      require_once __DIR__ . '/presence_lib.php'; require_once __DIR__ . '/report_lib.php';
      $to = implode(', ', emails_list((string)($in['a'] ?? ''))); if ($to === '') gfail('Indiquez l\'adresse de la marque.');
      $ok = mail_send($to, clip($in['sujet'] ?? 'Bilan de présence', 240), '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">' . nl2br(h(clip($in['texte'] ?? '', 12000))) . '</div>', [], implode(', ', emails_list((string)g_reg()['notif_emails'])));
      if (!$ok) gfail('L\'e-mail n\'a pas pu partir.');
      if ((int)($in['devis_id'] ?? 0)) p_log((int)$in['devis_id'], '', 'Bilan de présence envoyé à ' . $to);
      gout(['ok' => true]);

    // ---------- Maintenance : version, sauvegardes, mises à jour ----------
    case 'mail_etat':
      // E-mails : domaine d'envoi, enregistrements DNS qui prouvent l'authenticité (SPF, DMARC), mode d'envoi.
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      $dom = strtolower(substr(strrchr(MAIL_FROM, '@') ?: '', 1)); $txt = fn(string $h) => array_values(array_filter(array_map(fn($r) => (string)($r['txt'] ?? implode('', (array)($r['entries'] ?? []))), (array)@dns_get_record($h, DNS_TXT))));
      $spf = array_values(array_filter($txt($dom), fn($t) => stripos($t, 'v=spf1') === 0)); $dmarc = array_values(array_filter($txt('_dmarc.' . $dom), fn($t) => stripos($t, 'v=DMARC1') === 0));
      $smtp = defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '';
      gout(['expediteur' => MAIL_FROM, 'domaine' => $dom, 'spf' => $spf[0] ?? '', 'spf_ovh' => (bool)array_filter($spf, fn($t) => stripos($t, 'mx.ovh.com') !== false || stripos($t, 'ovh') !== false),
        'dmarc' => $dmarc[0] ?? '', 'dmarc_politique' => preg_match('/\bp=(\w+)/i', $dmarc[0] ?? '', $m) ? strtolower($m[1]) : '', 'mode' => $smtp ? 'smtp' : 'hebergement', 'smtp' => $smtp ? (string)MAIL_SMTP_HOST : '',
        'dernier' => json_decode(reglage('smtp_dernier', ''), true)]);
    case 'mail_test':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/report_lib.php'; $to = (string)($_SESSION['admin_email'] ?? MAIL_TO_DEFAULT);
      $ok = mail_send($to, 'Test d\'envoi ' . AG_COURT, '<p>Cet e-mail de test confirme que ' . h(AG_NOM) . ' peut envoyer des messages (contrats, plannings, comptes rendus).</p><p>Envoyé le ' . date('d/m/Y à H:i') . '.</p>');
      journal('e-mail de test', $to); gout(['ok' => $ok, 'a' => $to, 'erreur' => $ok ? '' : ((json_decode(reglage('smtp_dernier', ''), true)['erreur'] ?? '') ?: 'refusé par le serveur d\'envoi')]);

    case 'geo_tester':
      // Un itinéraire de test (Paris → Lyon), sans passer par le cache : vérifie la clé Geoapify.
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/geo_lib.php'; if (geo_cle() === '') gfail('Aucune clé Geoapify : ajoutez define(\'GEO_CLE\', \'…\'); dans config.php.');
      $j = geo_geoapify('/v1/routing', ['waypoints' => '48.8566,2.3522|45.7640,4.8357', 'mode' => 'drive', 'format' => 'json']); $code = (int)($GLOBALS['GEO_CODE'] ?? 0);
      $km = isset($j['results'][0]['distance']) ? round($j['results'][0]['distance'] / 1000) : null;
      gout(['ok' => $code === 200 && $km !== null, 'code' => $code, 'texte' => geo_etat_texte($code) . ($km ? ' (Paris → Lyon : ' . $km . ' km)' : '')]);

    case 'cle_changer':
      // Nouvelle clé des documents : l'ancienne reste lue le temps du rechiffrement. La nouvelle clé est montrée une seule fois, à noter hors du serveur.
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      if (($in['confirmation'] ?? '') !== 'CHANGER') gfail('Tapez CHANGER pour confirmer.');
      require_once __DIR__ . '/cle_lib.php'; try { $k = cle_changer(); } catch (RuntimeException $e) { gfail($e->getMessage()); }
      gout(['ok' => true, 'cle' => $k]);
    case 'exp_agence':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/export_lib.php'; try { [$f, $tel, $mdp] = exp_agence(); } catch (RuntimeException $e) { gfail($e->getMessage()); }
      $_SESSION['exp_agence'] = [basename($f), $tel]; gout(['ok' => true, 'mdp' => $mdp, 'taille' => filesize($f)]);
    case 'exp_telecharger':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/export_lib.php'; $E = $_SESSION['exp_agence'] ?? null; if (!$E) gfail('Relancez l\'export.', 404); unset($_SESSION['exp_agence']); session_write_close(); exp_remettre($E[0], $E[1]);
    case 'cle_deplacer':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/cle_lib.php'; try { $f = cle_deplacer(); } catch (RuntimeException $e) { gfail($e->getMessage()); }
      gout(['ok' => true, 'fichier' => $f]);
    case 'cle_rechiffrer':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/cle_lib.php'; @set_time_limit(120); $n = cle_rechiffrer(50); gout(['ok' => true, 'bilan' => $n, 'etat' => cle_etat()]);
    case 'cle_retirer':
      if (!est_principal()) gfail('Réservé aux dirigeants.', 403);
      require_once __DIR__ . '/cle_lib.php'; @set_time_limit(300); try { cle_retirer_anciennes(); } catch (RuntimeException $e) { gfail($e->getMessage()); } gout(['ok' => true]);

    case 'journal':
      // Journal des actions sensibles : connexions, téléchargements (contrats, CV, exports, SEPA, sauvegardes), comptes, effacements. Dirigeants seulement.
      if (!est_principal()) gfail('Journal réservé aux dirigeants.', 403);
      journal_schema(); $du = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['du'] ?? '')) ? $_GET['du'] : date('Y-m-d', strtotime('-30 days'));
      $au = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['au'] ?? '')) ? $_GET['au'] : date('Y-m-d'); $q = '%' . addcslashes(trim((string)($_GET['q'] ?? '')), '%_\\') . '%';
      $st = db()->prepare("SELECT le, qui, role, action, objet, ip FROM journal WHERE le >= ? AND le < ? + INTERVAL 1 DAY AND (qui LIKE ? OR action LIKE ? OR objet LIKE ? OR ip LIKE ?) ORDER BY id DESC LIMIT " . (isset($_GET['csv']) ? 20000 : 500));
      $st->execute([$du, $au, $q, $q, $q, $q]); $L = $st->fetchAll();
      if (isset($_GET['csv'])) {
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="Journal_' . $du . '_' . $au . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['Date', 'Qui', 'Rôle', 'Action', 'Objet', 'Adresse IP'], ';');
        foreach ($L as $l) fputcsv($o, array_values($l), ';'); fclose($o); exit;
      }
      gout(['du' => $du, 'au' => $au, 'lignes' => $L]);

    case 'maint':
      require_once __DIR__ . '/maj_lib.php'; tf_schema();
      $d = null; try { $d = maj_distante(); } catch (Throwable $e) {}
      gout(['version' => AMAYA_VERSION, 'php' => PHP_VERSION, 'zip' => class_exists('ZipArchive'), 'sodium' => function_exists('sodium_crypto_sign_verify_detached'),
        'sauvegardes' => sv_liste(), 'derniere_maj' => json_decode((string)reglage('maj_derniere', ''), true), 'distante' => $d && version_compare($d['version'], AMAYA_VERSION, '>') ? $d : null,
        'cron' => (int)@file_get_contents(sv_dir() . '/.cron'), 'externe' => (function () { require_once __DIR__ . '/externe_lib.php'; $E = sx_etat(); $L = sx_actif() ? sx_fichiers_locaux() : [];
          return ['actif' => sx_actif(), 'prefixe' => sx_actif() ? sx_prefixe() : '', 'fournisseur' => defined('SV_S3_ENDPOINT') ? (string)parse_url((string)SV_S3_ENDPOINT, PHP_URL_HOST) : '', 'dernier' => $E['dernier_envoi'] ?? null, 'bilan' => $E['dernier_bilan'] ?? null,
            'copies' => count(array_filter($E['fichiers'], fn($v) => empty($v[2]))), 'locaux' => count($L), 'bases' => count($E['base']), 'erreur' => $E['erreur'] ?? '', 'verif' => sx_derniere_verif()]; })(), 'tf' => (function () { $D = array_keys(dirigeants()); if (!$D) return 0; $st = db()->prepare("SELECT COUNT(*) FROM admins WHERE totp_actif = 1 AND email IN (" . implode(',', array_fill(0, count($D), '?')) . ")"); $st->execute($D); return (int)$st->fetchColumn(); })(), 'principal' => est_principal(), 'base' => (function () { require_once __DIR__ . '/migrations_lib.php'; return mig_etat(db()); })(), 'cle' => est_principal() ? (function () { require_once __DIR__ . '/cle_lib.php'; return cle_etat() + ['hors_web' => cle_dossier_hors_web()]; })() : null, 'geo' => (function () { require_once __DIR__ . '/geo_lib.php'; $e = geo_cle() !== '' ? geo_etat() : null; return ['cle' => geo_cle() !== '', 'etat' => $e ? $e + ['texte' => geo_etat_texte((int)$e['code'])] : null]; })(), 'dirigeants' => count(dirigeants()),
        'sante' => rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/sante.php?cle=' . substr(hash('sha256', 'sante' . instance_graine()), 0, 24)]);
    case 'externe_envoyer': case 'externe_verifier':
      // Copie hors du serveur : envoi immédiat (90 s au plus, la suite au prochain passage) ou vérification de restauration.
      if (!est_titulaire()) gfail('Réservé à la direction.', 403); require_once __DIR__ . '/externe_lib.php'; @set_time_limit(180);
      try { gout($action === 'externe_envoyer' ? ['ok' => true, 'bilan' => sx_envoyer(90)] : ['ok' => true, 'verif' => sx_verifier()]); }
      catch (Throwable $e) { $E = sx_etat(); $E['erreur'] = date('Y-m-d H:i') . ' : ' . $e->getMessage(); sx_etat_ecrire($E); gfail($e->getMessage()); }
    case 'sauvegarde_faire':
      require_once __DIR__ . '/sauvegarde_lib.php'; try { $r = sv_faire('manuelle par ' . admin_nom()); } catch (RuntimeException $e) { gfail($e->getMessage()); } gout(['ok' => true] + $r + ['sauvegardes' => sv_liste()]);
    case 'sauvegarde_dl':
      require_once __DIR__ . '/sauvegarde_lib.php'; $nom = (string)($_GET['nom'] ?? '');
      try { $gz = sv_lire($nom); } catch (RuntimeException $e) { gfail($e->getMessage(), 404); }
      header('Content-Type: application/gzip'); header('Content-Disposition: attachment; filename="' . str_replace('.enc', '', $nom) . '"'); header('Content-Length: ' . strlen($gz)); echo $gz; exit;
    case 'maj_deposer':
    case 'maj_distante':
      // Étape 1 : le paquet est vérifié (signature, empreintes) et gardé en attente ; rien n'est encore modifié.
      require_once __DIR__ . '/maj_lib.php';
      try {
        if ($action === 'maj_distante') { $d = maj_distante() ?: gfail('Aucune mise à jour disponible.'); $f = maj_telecharger((string)$d['url']); }
        else { $u = $_FILES['paquet'] ?? null; if (!$u || $u['error'] !== UPLOAD_ERR_OK) gfail('Envoi du fichier impossible (taille maximale de l\'hébergement : ' . ini_get('upload_max_filesize') . ').');
          $f = sv_dir() . '/paquet-' . bin2hex(random_bytes(6)) . '.zip'; if (!move_uploaded_file($u['tmp_name'], $f)) gfail('Enregistrement du paquet impossible.'); }
        foreach (glob(sv_dir() . '/paquet-*.zip') ?: [] as $x) if ($x !== $f && filemtime($x) < time() - 3600) @unlink($x);
        $M = maj_verifier($f);
      } catch (RuntimeException $e) { if (!empty($f)) @unlink($f); gfail($e->getMessage()); }
      $_SESSION['maj_paquet'] = basename($f);
      gout(['ok' => true, 'version' => $M['version'], 'actuelle' => AMAYA_VERSION, 'notes' => (string)($M['notes'] ?? ''), 'fichiers' => count($M['fichiers']), 'changes' => $M['changes'], 'date' => (string)($M['date'] ?? '')]);
    case 'maj_appliquer':
      require_once __DIR__ . '/maj_lib.php'; $nom = (string)($_SESSION['maj_paquet'] ?? '');
      if (!preg_match('/^paquet-[0-9a-f]{12}\.zip$/', $nom) || !is_file(sv_dir() . '/' . $nom)) gfail('Déposez d\'abord le paquet de mise à jour.');
      try { $r = maj_appliquer(sv_dir() . '/' . $nom); } catch (RuntimeException $e) { gfail($e->getMessage()); }
      @unlink(sv_dir() . '/' . $nom); unset($_SESSION['maj_paquet']); journal('mise à jour', 'version ' . AMAYA_VERSION . ' → ' . ($r['version'] ?? '?')); gout(['ok' => true] + $r);

    case 'import':
      // Reprise de données : clients ou points d'intervention, depuis Excel / CSV ou un copier-coller. « simuler » : aperçu sans rien enregistrer.
      require_once __DIR__ . '/import_lib.php'; @set_time_limit(120);
      $type = (string)($_POST['type'] ?? ''); $sim = !empty($_POST['simuler']);
      $rows = im_lignes((string)($_POST['texte'] ?? ''), $_FILES['fichier'] ?? null); if (!$rows) gfail('Rien à importer : choisissez un fichier ou collez des lignes.');
      if (count($rows) > 5000) gfail('5 000 lignes au maximum par import.');
      $r = $type === 'clients' ? im_clients($rows, $sim) : ($type === 'magasins' ? im_magasins($rows, (string)($_POST['zone'] ?? ''), $sim) : gfail('Type d\'import inconnu.'));
      gout(['ok' => true, 'simule' => $sim, 'nb' => count($r['ajoutes']), 'apercu' => array_slice($r['ajoutes'], 0, 8), 'ignores' => array_slice($r['ignores'], 0, 30), 'nb_ignores' => count($r['ignores'])]);

    case 'apparence':
      // Thème et couleur principale de l'appli (toutes les pages, documents PDF compris).
      $th = (string)($in['theme'] ?? ''); $co = strtoupper((string)($in['couleur'] ?? ''));
      if (!isset(AG_THEMES[$th])) gfail('Thème inconnu.'); if (!preg_match('/^#[0-9A-F]{6}$/', $co)) gfail('Couleur invalide.');
      $st = db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES (?, ?)"); $st->execute(['theme', $th]); $st->execute(['couleur', $co]);
      gout(['ok' => true]);
    case 'identite_image':
      // Logo de l'agence (version normale et version claire pour les bandeaux foncés) et signature du gérant (contrats).
      $dest = ['logo' => __DIR__ . '/uploads/logo-agence.png', 'logo_blanc' => __DIR__ . '/uploads/logo-agence-blanc.png', 'signature' => __DIR__ . '/prive/signature-gerant.png'][$in['type'] ?? ''] ?? gfail('Type inconnu.');
      $d = (string)($in['data'] ?? '');
      if ($d === '') { if (is_file($dest)) @unlink($dest); gout(['ok' => true]); }
      if (!preg_match('#^data:image/(png|jpe?g|webp);base64,#', $d)) gfail('Image PNG, JPEG ou WebP attendue.');
      $bin = base64_decode(substr($d, strpos($d, ',') + 1), true); if ($bin === false || strlen($bin) > 4 * 1024 * 1024) gfail('Image trop lourde (4 Mo maximum).');
      $im = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bin) : false; if (!$im) gfail('Image illisible.');
      $w = imagesx($im); $hh = imagesy($im); $k = min(1, 900 / max($w, $hh)); $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($hh * $k));
      $out = imagecreatetruecolor($nw, $nh); imagealphablending($out, false); imagesavealpha($out, true); imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
      imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $hh);
      if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
      imagepng($out, $dest, 9) || gfail("Enregistrement impossible (droits d'écriture du dossier).");
      gout(['ok' => true]);
    case 'reglages_save':
      try { g_reg_save($in); } catch (RuntimeException $e) { gfail($e->getMessage()); } gout(['ok' => true, 'reglages' => g_reg()]);

    default:
      gfail('Action inconnue', 404);
  }
} catch (Throwable $e) {
  gfail(erreur_publique($e, 'gestion'), 500);
}
