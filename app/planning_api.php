<?php
// Planning par opération (un devis) : qui fait quoi sur chaque créneau, remplacements, annulations, reports. Réservé à la direction.
require __DIR__ . '/contrats_lib.php';
require_once __DIR__ . '/offres_lib.php';
start_session();
header('Cache-Control: no-store');
function pout($d) { header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function pfail(string $m, int $c = 400) { http_response_code($c); pout(['error' => $m]); }
if (!function_exists('fail')) { function fail(string $m, int $c = 400) { pfail($m, $c); } } // utilisé par les fonctions communes (dépôt de fichier)
if (!admin_ok()) pfail('Accès réservé à l\'administrateur', 401);
if (!g_acces()) pfail('Accès réservé à la direction.', 403);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !requete_appli()) pfail('Requête refusée', 403);
$action = $_GET['action'] ?? ''; demo_bloquer($action);
$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
g_schema(); p_schema();

function p_devis(int $id): array { $d = g_doc($id); if (!$d || $d['type'] !== 'devis') pfail('Opération introuvable.', 404); return $d; }
function p_slot(array $d, string $k): array { foreach (p_operation($d) as $s) if ($s['key'] === $k) return $s; pfail('Créneau introuvable.'); }
function p_nom(?int $aid): string { if (!$aid) return 'personne'; $st = db()->prepare("SELECT CONCAT(nom, ' ', prenom) FROM animatrices WHERE id = ?"); $st->execute([$aid]); return (string)$st->fetchColumn(); }
/** E-mail de planning à un(e) intervenant(e). Tant que le planning n'est pas ouvert aux intervenants (phase de test), l'e-mail part à l'administrateur connecté. */
function p_mail(int $aid, string $intro = '', array $chg = []): bool {
  require_once __DIR__ . '/report_lib.php';
  $st = db()->prepare("SELECT prenom, nom, email FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch(); if (!$a) return false;
  $test = !p_ouvert() && !p_testeur($aid); $to = $test ? (string)($_SESSION['admin_email'] ?? '') : (string)$a['email']; $qui = $a['prenom'] . ' ' . $a['nom'];
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $GLOBALS['P_PREV'][] = $qui . ' (pas d\'e-mail sur sa fiche, non prévenu(e))'; return false; }
  $rows = ''; foreach (p_missions($aid, date('Y-m-d')) as $m) $rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2">' . h(date_fr($m['date'])) . '</td><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2"><b>' . h($m['magasin']) . '</b><br><span style="color:#857869">' . h($m['poste'] . ($m['client'] ? ' · ' . $m['client'] : '')) . '</span></td><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2;white-space:nowrap">' . h($m['debut'] . ' à ' . $m['fin']) . '</td></tr>';
  $url = rtrim(BASE_URL, '/') . '/';
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">'
    . ($test ? '<p style="background:#FFF4D6;padding:8px 10px;border-radius:6px"><b>Test</b> : cet e-mail serait envoyé à ' . h($a['prenom'] . ' ' . $a['nom']) . ' (' . h($a['email'] ?: 'pas d\'e-mail sur sa fiche') . '). Il vous arrive à vous tant que le planning n\'est pas ouvert aux intervenants.</p>' : '')
    . '<p>Bonjour ' . h(mb_convert_case(mb_strtolower($a['prenom']), MB_CASE_TITLE)) . ',</p>'
    . ($chg ? '<div style="border-left:3px solid ' . ag_couleur() . ';background:#F6F1EA;padding:10px 14px;margin:10px 0"><b>Changement dans votre planning</b><br>' . implode('<br>', array_map('h', $chg)) . '</div><p>Voici votre planning à jour :</p>'
      : '<p>' . h($intro ?: 'Voici votre planning d\'animations à venir avec ' . AG_NOM . '.') . '</p>')
    . ($rows ? '<table style="border-collapse:collapse;font-size:14px;margin:10px 0">' . $rows . '</table>' : '<p><i>Aucune animation prévue pour l\'instant.</i></p>')
    . '<p>Retrouvez-le à tout moment dans votre appli ' . AG_COURT . ', rubrique « Mon planning » : <a href="' . h($url) . '">' . h($url) . '</a></p>'
    . '<p>Merci de nous prévenir au plus vite en cas d\'empêchement.</p><p>Cordialement.</p><p>' . nl2br(h(g_reg()['signature'])) . '</p></div>';
  // En test, le nom de la personne est dans l'objet : les e-mails ne se regroupent pas dans la même conversation.
  $ok = mail_send($to, ($test ? '[Test pour ' . $qui . '] ' : '') . ($chg ? 'Changement dans votre planning ' . AG_COURT : 'Votre planning ' . AG_COURT), $html);
  require_once __DIR__ . '/extras_lib.php'; lp_notif($aid, $chg ? 'Changement dans votre planning' : 'Votre planning ' . AG_COURT, $chg ? implode(' · ', $chg) : ($intro ?: 'Vos animations à venir sont dans l\'appli.'), 'plan');
  $GLOBALS['P_PREV'][] = $qui . ($ok ? '' : ' (échec de l\'envoi)');
  if ($ok) db()->prepare("UPDATE missions SET notifie_le = ? WHERE animatrice_id = ? AND date_mission >= CURDATE()")->execute([now(), $aid]);
  return $ok;
}
/** E-mail « relevé à signer » (vers l'administrateur en phase de test). */
function r_mail(array $R): bool {
  require_once __DIR__ . '/report_lib.php'; $a = $R['animatrice'];
  $test = !p_ouvert() && !p_testeur((int)$a['id']); $to = $test ? (string)($_SESSION['admin_email'] ?? '') : (string)$a['email']; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $url = rtrim(BASE_URL, '/') . '/';
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">'
    . ($test ? '<p style="background:#FFF4D6;padding:8px 10px;border-radius:6px"><b>Test</b> : cet e-mail serait envoyé à ' . h($a['prenom'] . ' ' . $a['nom']) . ' (' . h($a['email'] ?: 'pas d\'e-mail sur sa fiche') . '). Pour signer à sa place en test : onglet Intervenants, « Voir son appli », puis « Mes relevés d\'heures ».</p>' : '')
    . '<p>Bonjour ' . h(mb_convert_case(mb_strtolower($a['prenom']), MB_CASE_TITLE)) . ',</p>'
    . '<p>Votre relevé d\'heures de <b>' . h($R['mois_txt']) . '</b> est prêt : ' . $R['jours'] . ' jour' . ($R['jours'] > 1 ? 's' : '') . ', <b>' . g_dur($R['minutes']) . '</b>.</p>'
    . '<p>Merci de le vérifier et de le signer dans votre appli ' . AG_COURT . ', rubrique « Mes relevés d\'heures » : <a href="' . h($url) . '">' . h($url) . '</a></p>'
    . '<p>Si quelque chose ne va pas, ne signez pas et prévenez-nous.</p><p>Cordialement.</p><p>' . nl2br(h(g_reg()['signature'])) . '</p></div>';
  require_once __DIR__ . '/extras_lib.php'; lp_notif((int)$a['id'], 'Relevé d\'heures à signer', 'Votre relevé de ' . $R['mois_txt'] . ' est prêt : vérifiez-le et signez-le.', 'rel');
  return mail_send($to, ($test ? '[Test] ' : '') . 'Votre relevé d\'heures de ' . $R['mois_txt'] . ' à signer', $html, [[r_fichier($R), r_pdf($R)]]);
}
/** Une personne ne peut pas être à deux endroits le même jour : renvoie le créneau qui bloque, sinon null. */
function p_occupee(int $aid, string $date, string $sauf): ?string {
  // Journée partagée : deux créneaux le même jour sont possibles s'ils ne se chevauchent pas.
  $own = db()->prepare("SELECT debut, fin FROM missions WHERE slot_key = ?"); $own->execute([$sauf]); $o = $own->fetch();
  $st = db()->prepare("SELECT magasin, client, debut, fin, partie FROM missions WHERE animatrice_id = ? AND date_mission = ? AND statut <> 'annulee' AND slot_key <> ?"); $st->execute([$aid, $date, $sauf]);
  $m = null; foreach ($st->fetchAll() as $x) { if ($o && ((int)$x['partie'] || str_contains($sauf, '|p')) && (g_hm($x['fin']) <= g_hm($o['debut']) || g_hm($x['debut']) >= g_hm($o['fin']))) continue; $m = $x; break; } return $m ? p_nom($aid) . ' travaille déjà le ' . g_date($date) . ' à ' . ($m['magasin'] ?: 'un autre point d\'intervention') . ($m['client'] ? ' (' . $m['client'] . ')' : '') . '.' : null;
}
/** Pour chaque date de l'opération, qui travaille déjà ailleurs ce jour-là (grisé dans les listes). */
function p_occupees(array $d, array $op): array {
  $dates = array_values(array_unique(array_column($op, 'date'))); if (!$dates) return [];
  $st = db()->prepare("SELECT date_mission, animatrice_id, slot_key, magasin, debut, fin FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission IN (" . implode(',', array_fill(0, count($dates), '?')) . ")"); $st->execute($dates);
  $o = []; foreach ($st->fetchAll() as $m) $o[$m['date_mission']][$m['animatrice_id']][] = ['key' => $m['slot_key'], 'magasin' => $m['magasin'], 'debut' => $m['debut'], 'fin' => $m['fin']]; return $o;
}
function p_ou(array $s): string { return date_fr($s['date'], true) . ($s['magasin'] ? ' à ' . $s['magasin'] : '') . ' (' . $s['debut'] . ' à ' . $s['fin'] . ')'; }
$GLOBALS['P_PREV'] = [];
/** Trace dans l'historique qui a été prévenu par e-mail. */
function p_logprev(int $devis): void { if ($GLOBALS['P_PREV']) p_log($devis, '', 'E-mail' . (p_ouvert() ? '' : ' de test (reçu par vous)') . ' pour : ' . implode(', ', $GLOBALS['P_PREV'])); }
/** La personne a déjà reçu son planning pour cette opération : seuls ses changements lui sont alors envoyés. */
function p_connu(int $aid, int $devis): bool { $st = db()->prepare("SELECT 1 FROM missions WHERE animatrice_id = ? AND devis_id = ? AND notifie_le IS NOT NULL LIMIT 1"); $st->execute([$aid, $devis]); return (bool)$st->fetchColumn(); }
/** E-mail « contrat (ou avenant) à signer », PDF joint. */
function c_mail(array $c): bool {
  require_once __DIR__ . '/report_lib.php'; $aid = (int)$c['animatrice_id']; $S = $c['salarie'];
  $st = db()->prepare("SELECT prenom, nom, email FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch(); if (!$a) return false;
  $test = !p_ouvert() && !p_testeur($aid); $to = $test ? (string)($_SESSION['admin_email'] ?? '') : (string)$a['email']; $qui = $a['prenom'] . ' ' . $a['nom'];
  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $GLOBALS['P_PREV'][] = $qui . ' (pas d\'e-mail sur sa fiche)'; return false; }
  $url = rtrim(BASE_URL, '/') . '/'; $L = $c['lignes']; $t = c_titre($c);
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">'
    . ($test ? '<p style="background:#FFF4D6;padding:8px 10px;border-radius:6px"><b>Test</b> : cet e-mail serait envoyé à ' . h($qui) . ' (' . h($a['email'] ?: 'pas d\'e-mail sur sa fiche') . ').</p>' : '')
    . '<p>Bonjour ' . h($S['prenom'] ?? $a['prenom']) . ',</p>'
    . ($c['avenant'] ? '<p>Votre planning a changé après la signature de votre contrat n°' . (int)$c['numero'] . '. Voici l\'<b>avenant n°' . (int)$c['avenant'] . '</b> à signer, avec vos nouvelles dates.</p>'
      : '<p>Voici votre <b>contrat de travail n°' . (int)$c['numero'] . '</b> pour l\'opération « ' . h($c['params']['operation']) . ' » (' . h($c['params']['client']) . ')' . ($L ? ', du ' . g_date($L[0]['date']) . ' au ' . g_date(end($L)['date']) : '') . '.</p>')
    . '<p>Merci de le lire et de le signer dans votre appli ' . AG_COURT . ', rubrique « Mes contrats » : <a href="' . h($url) . '">' . h($url) . '</a>. Le PDF est joint pour information.</p>'
    . '<p>Cordialement.</p><p>' . nl2br(h(g_reg()['signature'])) . '</p></div>';
  require_once __DIR__ . '/extras_lib.php'; lp_notif($aid, $t . ' à signer', 'Lisez-le et signez-le dans votre appli, rubrique « Mes contrats ».', 'ct');
  $ok = mail_send($to, ($test ? '[Test pour ' . $qui . '] ' : '') . $t . ' à signer · ' . AG_NOM, $html, [[c_fichier($c), c_pdf($c)]]);
  $GLOBALS['P_PREV'][] = $qui . ($ok ? '' : ' (échec de l\'envoi)'); return $ok;
}
/** Après un changement de planning : contrats déjà envoyés ou signés qui ne correspondent plus. */
function p_ct_alertes(int $devis, array $aids): array {
  $o = []; foreach (array_unique(array_filter($aids)) as $aid) { $E = c_etat((int)$aid, $devis);
    if ($E['etat'] === 'avenant') $o[] = p_nom((int)$aid) . ' : contrat signé, avenant à envoyer'; elseif ($E['etat'] === 'a_mettre_a_jour') $o[] = p_nom((int)$aid) . ' : contrat envoyé non signé, à renvoyer'; }
  return $o;
}
/** Offres d'une opération avec les réponses. */
function o_liste(int $devis): array {
  o_schema(); $st = db()->prepare("SELECT * FROM offres WHERE devis_id = ? ORDER BY id DESC"); $st->execute([$devis]); $out = [];
  $d = g_doc($devis);
  foreach ($st->fetchAll() as $o) {
    // Offre encore ouverte mais toutes ses dates sont pourvues (ou annulées) : elle se clôt.
    if ($o['statut'] === 'ouvert' && $d) { $oo = o_get((int)$o['id']); $libres = array_filter(o_slots($d, array_column($oo['slots'], 'key')), fn($x) => !$x['pris']);
      if (!$libres) { o_clore($oo); $o = array_merge($o, ['statut' => 'clos', 'clos_le' => now()]); } }
    $q = db()->prepare("SELECT od.*, CONCAT(a.nom, ' ', a.prenom) AS nom, a.telephone FROM offres_dest od JOIN animatrices a ON a.id = od.animatrice_id WHERE od.offre_id = ? ORDER BY od.reponse = 'oui' DESC, od.repondu_le, a.nom");
    $q->execute([$o['id']]); $D = $q->fetchAll(); foreach ($D as &$x) $x['dates'] = json_decode((string)$x['dates'], true) ?: []; unset($x);
    $out[] = ['id' => (int)$o['id'], 'slots' => json_decode($o['slots'], true) ?: [], 'rayon' => $o['rayon'] !== null ? (int)$o['rayon'] : null, 'statut' => $o['statut'], 'created_at' => $o['created_at'], 'par' => $o['par'], 'clos_le' => $o['clos_le'],
      'oui' => array_values(array_filter($D, fn($x) => $x['reponse'] === 'oui')), 'non' => count(array_filter($D, fn($x) => $x['reponse'] === 'non')), 'attente' => count(array_filter($D, fn($x) => $x['reponse'] === null)), 'total' => count($D)];
  }
  return $out;
}
/** Clôture d'une offre : petit mot aux personnes positionnées mais pas retenues. */
function o_clore(array $o): int {
  $n = 0;
    $st = db()->prepare("SELECT animatrice_id FROM offres_dest WHERE offre_id = ? AND reponse = 'oui' AND retenu = 0 AND refus_envoye IS NULL"); $st->execute([$o['id']]); $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) {
      if (o_mail((int)$aid, 'Mission ' . date_fr($o['slots'][0]['date'] ?? date('Y-m-d')) . ' : merci pour votre réponse', '<p>Merci pour votre candidature sur la mission du ' . h(date_fr($o['slots'][0]['date'] ?? '', true)) . ' à ' . h($o['slots'][0]['magasin'] ?? '') . '.</p><p>Nous ne vous avons pas retenu(e) cette fois-ci, mais n\'hésitez surtout pas à postuler sur nos prochaines offres : nous comptons sur vous !</p>')) $n++;
      db()->prepare("UPDATE offres_dest SET refus_envoye = ? WHERE offre_id = ? AND animatrice_id = ?")->execute([now(), $o['id'], $aid]);
    }
    db()->prepare("UPDATE offres SET statut = 'clos', clos_le = ? WHERE id = ?")->execute([now(), $o['id']]);
    p_log((int)$o['devis_id'], '', 'Offre close' . ($n ? ', ' . $n . ' message' . ($n > 1 ? 's' : '') . ' aux personnes non retenues' : ''));
  return $n;
}
const P_MAJ = 'Votre planning a été mis à jour. Voici vos animations à venir avec ' . AG_NOM . '.';

/** Relevé vérifié : figé et envoyé pour signature (ou e-mail renvoyé s'il l'était déjà). */
function pl_releve_envoyer(int $aid, string $mois): array {
  $row = r_ensure($aid, $mois); $R = r_get($aid, $mois);
  if ($row['statut'] === 'brouillon') {
    db()->prepare("UPDATE releves SET statut = 'envoye', lignes = ?, minutes = ?, base_hash = ?, envoye_le = ?, envoye_par = ?, modifie_le = ? WHERE id = ?")
      ->execute([json_encode($R['lignes'], JSON_UNESCAPED_UNICODE), $R['minutes'], r_hash(r_auto($aid, $mois)), now(), admin_nom(), now(), $row['id']]);
    r_journal((int)$row['id'], 'Vérifié et envoyé pour signature par ' . admin_nom() . ' (' . g_dur($R['minutes']) . ')'); $R = r_get($aid, $mois);
  } else r_journal((int)$row['id'], 'E-mail de signature renvoyé par ' . admin_nom());
  return [$R, r_mail($R)];
}

try {
  switch ($action) {
    case 'operations':
      // Opérations : devis non refusés, acceptés d'abord, avec l'avancement des attributions.
      $out = [];
      foreach (db()->query("SELECT id FROM g_docs WHERE type = 'devis' AND statut IN ('accepte','envoye','brouillon') ORDER BY FIELD(statut, 'accepte', 'envoye', 'brouillon'), date_doc DESC LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $d = g_doc((int)$id); $op = p_operation($d); if (!$op) continue; $act = array_filter($op, fn($s) => $s['statut'] !== 'annulee');
        $out[] = ['id' => (int)$d['id'], 'numero' => $d['numero'], 'client' => $d['client']['raison'] ?? '', 'objet' => $d['objet'], 'statut' => $d['statut'],
          'du' => min(array_column($op, 'date')), 'au' => max(array_column($op, 'date')), 'n' => count($act), 'pourvus' => count(array_filter($act, fn($s) => $s['animatrice_id'])),
          'ecarts' => count(array_filter($op, fn($s) => $s['statut'] === 'annulee'))];
      }
      pout(['operations' => $out, 'ouvert' => p_ouvert()]);

    case 'operation':
      $d = p_devis((int)($_GET['id'] ?? 0));
      $log = db()->prepare("SELECT slot_key, texte, par, created_at FROM missions_log WHERE devis_id = ? ORDER BY id DESC LIMIT 200"); $log->execute([$d['id']]);
      $f = db()->prepare("SELECT id, numero FROM g_docs WHERE type = 'facture' AND devis_id = ? LIMIT 1"); $f->execute([$d['id']]);
      pout(['devis' => ['id' => (int)$d['id'], 'numero' => $d['numero'], 'client' => $d['client']['raison'] ?? '', 'contact' => $d['client']['contact'] ?? '', 'objet' => $d['objet'], 'statut' => $d['statut'], 'pole' => g_pole($d), 'type_op' => (string)($d['type_op'] ?? '')],
        'offres' => o_liste((int)$d['id']), 'creneaux' => ($op = p_operation($d)), 'occupees' => p_occupees($d, $op), 'dispos' => (function () use ($op) { require_once __DIR__ . '/extras_lib.php'; return x_dispos(array_column($op, 'date')); })(), 'journal' => $log->fetchAll(), 'facture' => $f->fetch() ?: null, 'ouvert' => p_ouvert()]);

    case 'affecter':
      // Attribue, remplace ou retire la personne sur un ou plusieurs créneaux ; l'historique garde qui était prévu.
      $d = p_devis((int)($in['devis_id'] ?? 0)); $aid = (int)($in['animatrice_id'] ?? 0) ?: null; $mot = clip($in['motif'] ?? '', 300);
      if ($aid) { $st = db()->prepare("SELECT statut FROM animatrices WHERE id = ? AND actif = 1"); $st->execute([$aid]); $stt = $st->fetchColumn();
        if ($stt === false) pfail('Intervenant(e) introuvable ou refusé(e).'); if ($stt === 'standby') pfail(p_nom($aid) . ' est en stand-by : changez son statut dans l\'onglet Intervenants pour lui donner des dates.'); }
      $avant = []; $n = 0; $vus = []; $chg = []; $connu = $aid ? p_connu($aid, (int)$d['id']) : false;
      // Vérifie tout avant d'enregistrer : deux endroits le même jour, refusé.
      if ($aid) foreach ((array)($in['keys'] ?? []) as $k) { $s = p_slot($d, (string)$k); if ($s['statut'] === 'annulee' || $s['animatrice_id'] === $aid) continue;
        if (isset($vus[$s['date']])) pfail(p_nom($aid) . ' ne peut pas faire deux points d\'intervention le ' . g_date($s['date']) . '. Décochez une des deux dates.');
        $vus[$s['date']] = 1; if ($e = p_occupee($aid, $s['date'], $s['key'])) pfail($e . ' Impossible de la mettre à deux endroits le même jour.'); }
      foreach ((array)($in['keys'] ?? []) as $k) { $s = p_slot($d, (string)$k); if ($s['statut'] === 'annulee' || $s['animatrice_id'] === $aid) continue;
        p_save_slot($d, $s, ['animatrice_id' => $aid, 'notifie_le' => null]); $n++;
        // E-mail seulement à qui connaît déjà son planning ; les autres le recevront avec « Envoyer à chacun son planning ».
        if ($s['animatrice_id']) { $avant[$s['animatrice_id']] = true; if ($s['notifie_le']) $chg[$s['animatrice_id']][] = 'Vous n\'êtes plus prévu(e) le ' . p_ou($s) . '.'; }
        if ($aid && $connu) $chg[$aid][] = 'Nouvelle animation le ' . p_ou($s) . '.';
        p_log($d['id'], $s['key'], g_date($s['date']) . ' ' . $s['magasin'] . ' : ' . ($s['animatrice_id'] ? p_nom($s['animatrice_id']) . ($aid ? ' remplacée par ' . p_nom($aid) : ' retirée') : 'attribué à ' . p_nom($aid)) . ($mot ? ' (' . $mot . ')' : ''));
      }
      // Deux animations le même jour pour une même personne : signalé.
      $cf = [];
      $mails = 0;
      if (!empty($in['notifier'])) foreach ($chg as $x => $c) if (p_mail((int)$x, '', $c)) $mails++;
      p_logprev($d['id']); pout(['ok' => true, 'n' => $n, 'mails' => $mails, 'conflits' => $cf, 'prevenus' => $GLOBALS['P_PREV'], 'contrats' => p_ct_alertes((int)$d['id'], array_merge([$aid], array_keys($avant)))]);

    case 'deplacer':
      // Date annulée et remplacée par une autre avec l'accord du client : l'écart est justifié sur la facture.
      $d = p_devis((int)($in['devis_id'] ?? 0)); $s = p_slot($d, (string)($in['key'] ?? ''));
      $nd = (string)($in['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $nd)) pfail('Choisissez la nouvelle date.');
      if ($s['statut'] === 'annulee') pfail('Ce créneau est déjà annulé.');
      $accord = clip($in['accord'] ?? '', 250); if ($accord === '') pfail('Indiquez qui a donné son accord (et quand).');
      $nk = $d['id'] . '|' . $s['ligne'] . '|' . $s['magasin'] . '|' . $nd;
      foreach (p_operation($d) as $x) if ($x['key'] === $nk && $x['statut'] !== 'annulee') pfail('Ce point d\'intervention a déjà un créneau le ' . g_date($nd) . '.');
      $aid = array_key_exists('animatrice_id', $in) ? ((int)$in['animatrice_id'] ?: null) : $s['animatrice_id'];
      if ($aid && ($e = p_occupee($aid, $nd, $s['key']))) pfail($e . ' Choisissez une autre personne ou une autre date.');
      p_save_slot($d, $s, ['statut' => 'annulee', 'remplace_par' => $nd, 'motif' => clip($in['motif'] ?? '', 300), 'accord' => $accord]);
      p_save_slot($d, ['key' => $nk, 'date' => $nd, 'origine' => 'ajout'] + $s, ['animatrice_id' => $aid, 'statut' => 'prevue', 'remplace' => $s['date'], 'remplace_par' => null, 'accord' => $accord, 'motif' => null]);
      p_log($d['id'], $s['key'], g_date($s['date']) . ' ' . $s['magasin'] . ' annulé, remplacé par le ' . g_date($nd) . ($aid ? ' (' . p_nom($aid) . ')' : '') . ' · accord : ' . $accord . (trim((string)($in['motif'] ?? '')) !== '' ? ' · ' . clip($in['motif'], 300) : ''));
      $mails = 0; $ns = ['date' => $nd] + $s;
      if (!empty($in['notifier']) && ($s['notifie_le'] || ($aid && p_connu($aid, (int)$d['id'])))) {
        if ($aid && $aid === $s['animatrice_id']) $mails += (int)p_mail($aid, '', ['Votre animation du ' . p_ou($s) . ' est reportée au ' . date_fr($nd, true) . ', mêmes horaires.']);
        else { if ($aid) $mails += (int)p_mail($aid, '', ['Nouvelle animation le ' . p_ou($ns) . '.']); if ($s['animatrice_id']) $mails += (int)p_mail($s['animatrice_id'], '', ['Votre animation du ' . p_ou($s) . ' est annulée (reportée à une autre date).']); }
      }
      p_logprev($d['id']); pout(['ok' => true, 'mails' => $mails, 'prevenus' => $GLOBALS['P_PREV'], 'contrats' => p_ct_alertes((int)$d['id'], [$s['animatrice_id'], $aid ?? null])]);

    case 'annuler':
      $d = p_devis((int)($in['devis_id'] ?? 0)); $s = p_slot($d, (string)($in['key'] ?? ''));
      p_save_slot($d, $s, ['statut' => 'annulee', 'remplace_par' => null, 'motif' => clip($in['motif'] ?? '', 300), 'accord' => clip($in['accord'] ?? '', 250)]);
      p_log($d['id'], $s['key'], g_date($s['date']) . ' ' . $s['magasin'] . ' annulé, non facturé' . (trim((string)($in['motif'] ?? '')) !== '' ? ' · ' . clip($in['motif'], 300) : '') . (trim((string)($in['accord'] ?? '')) !== '' ? ' · accord : ' . clip($in['accord'], 250) : ''));
      $mails = (!empty($in['notifier']) && $s['animatrice_id'] && $s['notifie_le']) ? (int)p_mail($s['animatrice_id'], '', ['Votre animation du ' . p_ou($s) . ' est annulée.']) : 0;
      p_logprev($d['id']); pout(['ok' => true, 'mails' => $mails, 'prevenus' => $GLOBALS['P_PREV'], 'contrats' => p_ct_alertes((int)$d['id'], [$s['animatrice_id'], $aid ?? null])]);

    case 'retablir':
      // Retour en arrière : la date annulée revient, son report (s'il existe) disparaît.
      $d = p_devis((int)($in['devis_id'] ?? 0)); $s = p_slot($d, (string)($in['key'] ?? ''));
      if ($s['origine'] === 'ajout') {
        db()->prepare("DELETE FROM missions WHERE slot_key = ?")->execute([$s['key']]);
        db()->prepare("UPDATE missions SET statut = 'prevue', remplace_par = NULL, motif = NULL, accord = '' WHERE devis_id = ? AND remplace_par = ? AND ligne = ? AND magasin = ?")->execute([$d['id'], $s['date'], $s['ligne'], $s['magasin']]);
        p_log($d['id'], $s['key'], 'Report du ' . g_date($s['date']) . ' ' . $s['magasin'] . ' supprimé, date d\'origine ' . g_date($s['remplace']) . ' rétablie');
        if (!empty($in['notifier']) && $s['animatrice_id'] && $s['notifie_le']) p_mail($s['animatrice_id'], '', ['Le report au ' . date_fr($s['date'], true) . ' est annulé : votre animation reste le ' . p_ou(['date' => $s['remplace']] + $s) . '.']);
        p_logprev($d['id']); pout(['ok' => true, 'prevenus' => $GLOBALS['P_PREV'], 'contrats' => p_ct_alertes((int)$d['id'], [$s['animatrice_id']])]);
      }
      if ($s['statut'] !== 'annulee') pfail('Ce créneau n\'est pas annulé.');
      if ($s['animatrice_id'] && ($e = p_occupee((int)$s['animatrice_id'], $s['date'], $s['key']))) pfail($e . ' Retirez-la d\'abord de l\'autre magasin, ou choisissez une autre personne.');
      if ($s['remplace_par']) db()->prepare("DELETE FROM missions WHERE slot_key = ? AND origine = 'ajout'")->execute([$d['id'] . '|' . $s['ligne'] . '|' . $s['magasin'] . '|' . $s['remplace_par']]);
      p_save_slot($d, $s, ['statut' => 'prevue', 'remplace_par' => null, 'motif' => null, 'accord' => '']);
      p_log($d['id'], $s['key'], g_date($s['date']) . ' ' . $s['magasin'] . ' rétabli' . ($s['remplace_par'] ? ' (report du ' . g_date($s['remplace_par']) . ' supprimé)' : ''));
      if (!empty($in['notifier']) && $s['animatrice_id'] && $s['notifie_le']) p_mail($s['animatrice_id'], '', ['Votre animation du ' . p_ou($s) . ' est maintenue' . ($s['remplace_par'] ? ' (le report au ' . date_fr($s['remplace_par'], true) . ' est annulé)' : '') . '.']);
      p_logprev($d['id']); pout(['ok' => true, 'prevenus' => $GLOBALS['P_PREV'], 'contrats' => p_ct_alertes((int)$d['id'], [$s['animatrice_id']])]);

    case 'notifier':
      $ids = !empty($in['animatrice_id']) ? [(int)$in['animatrice_id']] : array_values(array_unique(array_filter(array_column(p_operation(p_devis((int)($in['devis_id'] ?? 0))), 'animatrice_id'))));
      $n = 0; foreach ($ids as $x) if (p_mail((int)$x)) $n++;
      pout(['ok' => true, 'n' => $n, 'total' => count($ids), 'test' => !p_ouvert()]);

    case 'ouvrir':
      // Ouvre (ou referme) le planning aux intervenants : appli et e-mails.
      db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES ('planning_ouvert', ?)")->execute([!empty($in['ouvert']) ? '1' : '0']); pout(['ok' => true, 'ouvert' => p_ouvert()]);

    case 'purger':
      // Remise à zéro des essais, seulement en phase de test : planning, historique, relevés, et si demandé tous les devis et factures.
      if (p_ouvert()) pfail('Le planning est ouvert aux intervenants : remettez-le en test avant d\'effacer.');
      if (trim((string)($in['confirm'] ?? '')) !== 'EFFACER') pfail('Tapez EFFACER pour confirmer.');
      r_schema(); $n = ['missions' => (int)db()->query("SELECT COUNT(*) FROM missions")->fetchColumn(), 'releves' => (int)db()->query("SELECT COUNT(*) FROM releves")->fetchColumn(), 'docs' => 0];
      c_schema(); $n['contrats'] = (int)db()->query("SELECT COUNT(*) FROM contrats")->fetchColumn();
      db()->exec("DELETE FROM missions"); db()->exec("DELETE FROM missions_log"); db()->exec("DELETE FROM releves"); db()->exec("DELETE FROM contrats"); db()->exec("DELETE FROM contrats_brouillons"); db()->exec("DELETE FROM contrats_dpae"); o_schema(); db()->exec("DELETE FROM offres"); db()->exec("DELETE FROM offres_dest");
      if (!empty($in['docs'])) { $n['docs'] = (int)db()->query("SELECT COUNT(*) FROM g_docs")->fetchColumn(); db()->exec("DELETE FROM g_docs"); }
      pout(['ok' => true, 'n' => $n]);

    // ---------- Offres de mission ----------
    case 'offre_apercu':
    case 'offre_envoyer':
      $d = p_devis((int)($in['devis_id'] ?? 0)); o_schema();
      $sl = o_slots($d, array_map('strval', (array)($in['keys'] ?? []))); $sl = array_values(array_filter($sl, fn($x) => !$x['pris'])); if (!$sl) pfail('Choisissez des dates encore à pourvoir.');
      $rayon = (int)($in['rayon'] ?? 0) ?: null; $dest = o_destinataires($sl, $rayon, !empty($in['sans_adresse']));
      $ex = array_map('intval', (array)($in['exclus'] ?? [])); $dest = array_values(array_filter($dest, fn($x) => !in_array($x['id'], $ex, true)));
      if ($action === 'offre_apercu') pout(['slots' => $sl, 'destinataires' => $dest, 'test' => !p_ouvert()]);
      if (!$dest) pfail('Personne à qui envoyer avec ces critères.');
      @set_time_limit(300);
      db()->prepare("INSERT INTO offres (devis_id, slots, rayon, message, par, created_at) VALUES (?,?,?,?,?,?)")->execute([$d['id'], json_encode($sl, JSON_UNESCAPED_UNICODE), $rayon, clip($in['message'] ?? '', 1000), admin_nom(), now()]);
      $oid = (int)db()->lastInsertId(); $ins = db()->prepare("INSERT INTO offres_dest (offre_id, animatrice_id, token, distance) VALUES (?,?,?,?)"); $n = 0;
      $cli = $d['client']['raison'] ?? ''; $url = rtrim(BASE_URL, '/') . '/';
      foreach ($dest as $x) {
        $t = bin2hex(random_bytes(16)); $ins->execute([$oid, $x['id'], $t, $x['distance']]);
        $corps = '<p>' . AG_NOM . ' recherche un(e) intervenant(e) pour <b>' . h($cli) . '</b>' . ($d['objet'] ? ' (' . h($d['objet']) . ')' : '') . ' :</p>' . o_lignes($sl)
          . (trim((string)($in['message'] ?? '')) !== '' ? '<p>' . nl2br(h(clip($in['message'], 1000))) . '</p>' : '')
          . '<p>Intéressé(e) ? Positionnez-vous dans votre appli ' . AG_COURT . ', rubrique « Missions proposées » : vous choisissez la ou les dates qui vous conviennent.</p>'
          . '<p><a href="' . h($url) . '" style="display:inline-block;background:#1A1512;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-weight:bold">Ouvrir mon appli</a></p>';
        if (o_mail($x['id'], 'Mission proposée : ' . $cli . ', ' . date_fr($sl[0]['date']) . (count($sl) > 1 ? ' (' . count($sl) . ' dates)' : ''), $corps, 'of')) $n++;
      }
      p_log($d['id'], '', 'Offre envoyée à ' . count($dest) . ' personne' . (count($dest) > 1 ? 's' : '') . ($rayon ? ' (à moins de ' . $rayon . ' km)' : ' (toute la zone)') . ' pour ' . count($sl) . ' date' . (count($sl) > 1 ? 's' : ''));
      pout(['ok' => true, 'n' => count($dest), 'mails' => $n, 'test' => !p_ouvert()]);

    case 'offre_attribuer':
      // Attribue à un(e) candidat(e) une ou plusieurs dates de l'offre ; il (elle) est prévenu(e).
      $o = o_get((int)($in['offre_id'] ?? 0)) ?: pfail('Offre introuvable.'); $d = p_devis((int)$o['devis_id']); $aid = (int)($in['animatrice_id'] ?? 0);
      $keys = array_values(array_intersect(array_map('strval', (array)($in['keys'] ?? [])), array_column($o['slots'], 'key'))); if (!$keys) pfail('Cochez au moins une date.');
      $chg = [];
      foreach ($keys as $k) { $s = p_slot($d, $k); if ($s['statut'] === 'annulee') continue;
        if ($s['animatrice_id'] && $s['animatrice_id'] !== $aid) pfail('Le ' . g_date($s['date']) . ' est déjà attribué à ' . p_nom($s['animatrice_id']) . '.');
        if ($e = p_occupee($aid, $s['date'], $s['key'])) pfail($e); }
      foreach ($keys as $k) { $s = p_slot($d, $k); if ($s['statut'] === 'annulee' || $s['animatrice_id'] === $aid) continue;
        p_save_slot($d, $s, ['animatrice_id' => $aid, 'notifie_le' => null]); $chg[] = 'Mission confirmée le ' . p_ou($s) . '.';
        p_log($d['id'], $s['key'], g_date($s['date']) . ' ' . $s['magasin'] . ' : attribué à ' . p_nom($aid) . ' (offre de mission)'); }
      db()->prepare("UPDATE offres_dest SET retenu = 1 WHERE offre_id = ? AND animatrice_id = ?")->execute([$o['id'], $aid]);
      $ok = $chg ? p_mail($aid, '', array_merge(['Bonne nouvelle : vous êtes retenu(e) pour cette mission avec ' . ($d['client']['raison'] ?? 'notre client') . '.'], $chg)) : false;
      // Toutes les dates de l'offre sont pourvues : l'offre se clôt toute seule et les autres positionné(e)s sont remercié(e)s.
      $libres = array_filter(o_slots($d, array_column($o['slots'], 'key')), fn($x) => !$x['pris']); $clos = false;
      if (!$libres) { o_clore($o); $clos = true; }
      p_logprev((int)$d['id']); pout(['ok' => true, 'n' => count($chg), 'mail' => $ok, 'prevenus' => $GLOBALS['P_PREV'], 'clos' => $clos]);

    case 'offre_clore':
      $o = o_get((int)($in['offre_id'] ?? 0)) ?: pfail('Offre introuvable.');
      pout(['ok' => true, 'n' => o_clore($o)]);

    // ---------- Contrats de travail ----------
    case 'contrats':
      // Une ligne par personne et par opération, créée dès qu'une date lui est attribuée.
      c_schema(); $f = (int)($_GET['devis_id'] ?? 0);
      $q = db()->query("SELECT DISTINCT animatrice_id, devis_id FROM missions WHERE animatrice_id IS NOT NULL AND devis_id IS NOT NULL" . ($f ? " AND devis_id = $f" : "") . " UNION SELECT animatrice_id, devis_id FROM contrats" . ($f ? " WHERE devis_id = $f" : ""));
      $out = []; require_once __DIR__ . '/extras_lib.php'; x_schema(); $ft = []; foreach (db()->query("SELECT animatrice_id, devis_id, ft_le FROM contrats_fin")->fetchAll() as $z) $ft[$z['animatrice_id'] . '|' . $z['devis_id']] = $z['ft_le'];
      foreach ($q->fetchAll() as $x) { $E = c_etat((int)$x['animatrice_id'], (int)$x['devis_id']); if (empty($E['devis'])) continue; $L = $E['lignes'];
        $out[] = ['animatrice_id' => (int)$x['animatrice_id'], 'devis_id' => (int)$x['devis_id'], 'nom' => $E['salarie']['nom'] . ' ' . $E['salarie']['prenom'], 'client' => $E['params']['client'], 'operation' => $E['params']['operation'],
          'devis' => $E['devis']['numero'], 'du' => $L ? $L[0]['date'] : null, 'au' => $L ? end($L)['date'] : null, 'jours' => count(array_unique(array_column($L, 'date'))), 'etat' => $E['etat'], 'etat_txt' => C_ETATS[$E['etat']],
          'numero' => $E['last']['numero'] ?? null, 'avenants' => $E['last'] ? (int)$E['last']['avenant'] : 0, 'manque' => $E['manque'], 'dpae' => c_dpae((int)$x['animatrice_id'], (int)$x['devis_id'], $L),
          'termine' => $L && end($L)['date'] < date('Y-m-d') && $E['last'], 'ft_le' => $ft[$x['animatrice_id'] . '|' . $x['devis_id']] ?? null]; }
      usort($out, fn($a, $b) => [$a['du'] ?? '9', mb_strtolower($a['nom'])] <=> [$b['du'] ?? '9', mb_strtolower($b['nom'])]);
      pout(['contrats' => $out, 'etats' => C_ETATS, 'ouvert' => p_ouvert()]);

    case 'contrat':
      $E = c_etat((int)($_GET['animatrice_id'] ?? 0), (int)($_GET['devis_id'] ?? 0)); if (empty($E['devis'])) pfail('Opération introuvable.', 404);
      $V = array_map(fn($v) => ['id' => (int)$v['id'], 'titre' => c_titre($v), 'statut' => $v['statut'], 'envoye_le' => $v['envoye_le'], 'envoye_par' => $v['envoye_par'], 'signe_le' => $v['signe_le'], 'signe_nom' => $v['signe_nom'],
        'signe_test' => (int)$v['signe_test'], 'sha' => (string)($v['pdf_sha256'] ?? ''), 'jours' => count(json_decode($v['lignes'], true) ?: []), 'journal' => json_decode((string)$v['journal'], true) ?: []], $E['versions']);
      $Tj = null; try { $Tj = c_trajets((int)$_GET['animatrice_id'], $E['params'], $E['lignes'], $E['salarie']); } catch (Throwable $e) { error_log('Trajets : ' . $e->getMessage()); }
      pout(['trajets' => $Tj, 'dpae' => c_dpae((int)$_GET['animatrice_id'], (int)$_GET['devis_id'], $E['lignes']), 'etat' => $E['etat'], 'etat_txt' => C_ETATS[$E['etat']], 'lignes' => $E['lignes'], 'params' => $E['params'], 'salarie' => $E['salarie'], 'manque' => $E['manque'], 'versions' => $V, 'adresse_changee' => $E['adresse_changee'],
        'devis' => ['id' => (int)$E['devis']['id'], 'numero' => $E['devis']['numero'], 'objet' => $E['devis']['objet']]]);

    case 'contrat_save':
      // Conditions propres au contrat + identité de la personne (reportée sur sa fiche).
      $aid = (int)($in['animatrice_id'] ?? 0); $dv = (int)($in['devis_id'] ?? 0); c_schema();
      $o = c_brouillon($aid, $dv); foreach (['taux', 'essai', 'client', 'operation', 'coef'] as $k) if (isset($in[$k]) && trim((string)$in[$k]) !== '') $o[$k] = clip((string)$in[$k], 300); else unset($o[$k]);
      if (is_array($in['km_man'] ?? null)) { $km = []; foreach ($in['km_man'] as $m => $v) { $v = str_replace(',', '.', trim((string)$v)); if ($v !== '' && is_numeric($v) && $v >= 0 && $v < 2000) $km[clip((string)$m, 120)] = round((float)$v, 1); } if ($km) $o['km_man'] = $km; else unset($o['km_man']); }
      if (isset($in['transport'])) { if ($in['transport'] === 'tc') { $o['transport'] = 'tc'; $o['tc_jour'] = max(0, min(500, round((float)str_replace(',', '.', (string)($in['tc_jour'] ?? 0)), 2))); } else unset($o['transport'], $o['tc_jour']); }
      if (array_key_exists('depart', $in)) { $dp = is_array($in['depart']) ? c_depart_ok(array_map(fn($v) => clip((string)$v, 200), $in['depart'])) : null; if ($dp) $o['depart'] = $dp; else unset($o['depart']); } // vide : domicile habituel
      db()->prepare("REPLACE INTO contrats_brouillons (animatrice_id, devis_id, params) VALUES (?,?,?)")->execute([$aid, $dv, json_encode($o, JSON_UNESCAPED_UNICODE)]);
      if (is_array($in['salarie'] ?? null)) { $f = (array)fiche_get($aid)['data']; foreach (['civilite', 'adresse', 'code_postal', 'ville', 'secu', 'nationalite', 'naissance_date', 'naissance_lieu'] as $k) if (isset($in['salarie'][$k])) $f[$k] = $in['salarie'][$k];
        db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)")->execute([$aid, fiche_data_json(fiche_clean($f)), now()]); }
      pout(['ok' => true]);

    case 'contrat_dpae':
      // DPAE faite dans le TESE : date, n° de l'accusé de réception, date d'embauche déclarée. « retirer » pour annuler la saisie.
      $aid = (int)($in['animatrice_id'] ?? 0); $dv = (int)($in['devis_id'] ?? 0); c_schema();
      if (!empty($in['retirer'])) { db()->prepare("DELETE FROM contrats_dpae WHERE animatrice_id = ? AND devis_id = ?")->execute([$aid, $dv]); pout(['ok' => true]); }
      $d = (string)($in['fait_le'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) pfail('Indiquez la date de la DPAE.');
      $pl = (string)($in['pour_le'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pl)) $pl = null;
      db()->prepare("REPLACE INTO contrats_dpae (animatrice_id, devis_id, fait_le, pour_le, numero, par, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$aid, $dv, $d, $pl, clip($in['numero'] ?? '', 60), admin_nom(), now()]);
      pout(['ok' => true]);

    case 'contrat_envoyer':
      $aid = (int)($in['animatrice_id'] ?? 0); $dv = (int)($in['devis_id'] ?? 0);
      try { $c = c_envoyer($aid, $dv); } catch (RuntimeException $e) { pfail($e->getMessage()); }
      $ok = c_mail($c); pout(['ok' => true, 'titre' => c_titre($c), 'mail' => $ok, 'prevenus' => $GLOBALS['P_PREV'], 'test' => !p_ouvert()]);

    // ---------- Règle des 500 heures (CIDD) : proposition de CDI intermittent ----------
    case 'cdii':
      require_once __DIR__ . '/rh_lib.php'; $r = g_reg();
      pout(['suivi' => rh_suivi(), 'statuts' => RH_STATUTS, 'modele_ok' => (bool)$r['cdii_valide'], 'modele_perso' => trim((string)$r['cdii_modele']) !== '', 'seuil' => RH_SEUIL]);
    case 'cdii_calcul': case 'cdii_proposer':
      require_once __DIR__ . '/rh_lib.php'; $aid = (int)($in['animatrice_id'] ?? 0); $X = rh_suivi($aid)[0] ?? null; if (!$X) pfail('Personne introuvable ou sans heures en CIDD.');
      if (in_array($X['etat'], ['propose', 'cdii'], true)) pfail('Une proposition est déjà en cours pour cette personne.');
      $ref = isset($X['franchi_le']) && in_array($X['etat'], ['a_proposer', 'retard'], true) ? ['franchi_le' => $X['franchi_le'], 'heures' => $X['heures_ref'], 'ref_debut' => $X['ref_debut']]
        : ['franchi_le' => date('Y-m-d'), 'heures' => $X['heures_12m'], 'ref_debut' => rh_mois(date('Y-m-d'), -12)]; // proposition anticipée : toujours possible
      $K = rh_calcul($aid, $ref, $in);
      if ($action === 'cdii_calcul') pout(['calcul' => $K, 'ref' => $ref, 'suivi' => $X]);
      $r = g_reg(); if (!$r['cdii_valide']) pfail('Validez d\'abord le texte de la lettre dans Réglages (case « validé par notre avocat »).');
      $mode = ($in['mode'] ?? 'main') === 'lrar' ? 'lrar' : 'main'; $le = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['propose_le'] ?? '')) ? $in['propose_le'] : date('Y-m-d');
      $pres = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['presente_le'] ?? '')) ? $in['presente_le'] : ($mode === 'main' ? $le : null);
      rh_schema(); db()->prepare("INSERT INTO cdii (animatrice_id, ref_debut, ref_fin, heures, statut, propose_le, mode, presente_le, volume, taux, prise_effet, params, par, created_at) VALUES (?,?,?,?, 'propose', ?,?,?,?,?,?,?,?,?)")
        ->execute([$aid, $ref['ref_debut'], $ref['franchi_le'], $ref['heures'], $le, $mode, $pres, $K['volume'], $K['taux'], $K['prise_effet'], json_encode(['remuneration' => $K['remuneration'], 'periodes' => $K['periodes'], 'calcul' => $K], JSON_UNESCAPED_UNICODE), $_SESSION['admin_email'] ?? '', now()]);
      pout(['ok' => true, 'id' => (int)db()->lastInsertId()]);
    case 'cdii_suite':
      // Première présentation (recommandé), réponse de la personne, ou annulation d'une proposition saisie par erreur.
      require_once __DIR__ . '/rh_lib.php'; rh_schema(); $id = (int)($in['id'] ?? 0); $st = db()->prepare("SELECT * FROM cdii WHERE id = ?"); $st->execute([$id]); $p = $st->fetch() ?: pfail('Proposition introuvable.', 404);
      $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['le'] ?? '')) ? $in['le'] : date('Y-m-d');
      switch ((string)($in['quoi'] ?? '')) {
        case 'presente': db()->prepare("UPDATE cdii SET presente_le = ? WHERE id = ?")->execute([$d, $id]); break;
        case 'accepte': case 'refuse': db()->prepare("UPDATE cdii SET statut = ?, reponse_le = ? WHERE id = ?")->execute([$in['quoi'], $d, $id]); break;
        case 'annuler': if ($p['statut'] !== 'propose') pfail('Seule une proposition en attente peut être annulée.'); db()->prepare("DELETE FROM cdii WHERE id = ?")->execute([$id]); break;
        default: pfail('Action inconnue.');
      }
      pout(['ok' => true]);
    case 'cdii_lettre':
      require_once __DIR__ . '/rh_lib.php'; rh_schema(); $st = db()->prepare("SELECT * FROM cdii WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $p = $st->fetch() ?: pfail('Proposition introuvable.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Proposition_CDII_' . (int)$p['animatrice_id'] . '_' . $p['propose_le'] . '.pdf"'); echo rh_pdf(rh_lettre_html($p)); exit;

    // ---------- Fin de contrat : certificat de travail, solde de tout compte, données pour l'attestation France Travail ----------
    case 'fin_docs': case 'fin_certificat': case 'fin_solde':
      require_once __DIR__ . '/rh_lib.php'; require_once __DIR__ . '/paye_lib.php';
      try { $X = rh_fin((int)($_GET['animatrice_id'] ?? 0), (int)($_GET['devis_id'] ?? 0)); } catch (RuntimeException $e) { pfail($e->getMessage()); }
      if ($action === 'fin_docs') pout(['fin' => $X]);
      if ($action === 'fin_solde' && $X['net'] === null) pfail('Indiquez d\'abord le net versé (bulletin de paie) : il figure en chiffres et en lettres sur le reçu.');
      if ($action === 'fin_certificat' && ($X['mutuelle'] === '' || $X['prevoyance'] === '')) pfail('Indiquez d\'abord si la personne était affiliée à la mutuelle et à la prévoyance de l\'entreprise : la mention de maintien des garanties en dépend.');
      $nm = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $X['salarie']['nom'] . '_' . $X['salarie']['prenom']));
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . ($action === 'fin_solde' ? 'Solde_de_tout_compte_' : 'Certificat_de_travail_') . $nm . '_' . $X['numero'] . '.pdf"');
      echo rh_pdf($action === 'fin_solde' ? rh_solde_html($X) : rh_certificat_html($X)); exit;
    case 'fin_solde_net':
      require_once __DIR__ . '/rh_lib.php'; rh_schema(); $v = trim(str_replace([' ', ','], ['', '.'], (string)($in['net'] ?? '')));
      $ok = fn($k) => in_array($in[$k] ?? '', ['affilie', 'dispense'], true) ? $in[$k] : '';
      db()->prepare("INSERT INTO contrats_solde (animatrice_id, devis_id, net, le, par, mutuelle, prevoyance) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE net = VALUES(net), le = VALUES(le), par = VALUES(par), mutuelle = VALUES(mutuelle), prevoyance = VALUES(prevoyance)")
        ->execute([(int)$in['animatrice_id'], (int)$in['devis_id'], $v === '' ? null : round((float)$v, 2), date('Y-m-d'), (string)($_SESSION['admin_email'] ?? ''), $ok('mutuelle'), $ok('prevoyance')]);
      pout(['ok' => true]);

    case 'contrat_pdf':
      $id = (int)($_GET['id'] ?? 0); $c = $id ? c_row($id) : c_apercu((int)($_GET['animatrice_id'] ?? 0), (int)($_GET['devis_id'] ?? 0)); if (!$c) pfail('Contrat introuvable.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . c_fichier($c) . '"'); echo $id ? c_pdf_final($c) : c_pdf($c); exit;

    // ---------- Préparation de paye (du 1er au dernier jour du mois) ----------
    case 'paye':
    case 'paye_xlsx':
      require_once __DIR__ . '/paye_lib.php'; @set_time_limit(180);
      $mois = (string)($_GET['mois'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) $mois = date('Y-m');
      $X = pa_lignes($mois); $pl = (string)($_GET['pole'] ?? ''); if (isset(G_POLES[$pl])) $X = array_values(array_filter($X, fn($x) => ($x['pole'] ?? 'anim') === $pl));
      if ($action === 'paye_xlsx') { $bin = pa_xlsx($mois, $X);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . pa_fichier($mois) . '"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit; }
      pout(['mois' => $mois, 'titre' => r_mois_txt($mois), 'lignes' => array_map(fn($x) => array_diff_key($x, ['detail' => 1]) + pa_calc($x), $X)]);

    // ---------- Virements des salaires (SEPA) ----------
    case 'virements':
    case 'virements_save':
    case 'virements_coller':
    case 'sepa':
      require_once __DIR__ . '/sepa_lib.php'; @set_time_limit(180);
      $mois = (string)($in['mois'] ?? $_GET['mois'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mois)) pfail('Mois invalide.');
      if (in_array($action, ['virements_save', 'virements_coller', 'sepa'], true) && !est_titulaire()) pfail('Réservé à la direction (connectez-vous avec votre propre compte).', 403);
      if ($action === 'virements_save') { sp_enregistrer($mois, (array)($in['nets'] ?? [])); pout(['ok' => true, 'lignes' => sp_lignes($mois)]); }
      if ($action === 'virements_coller') { $r = sp_coller($mois, (string)($in['texte'] ?? '')); pout($r + ['lignes' => sp_lignes($mois)]); }
      if ($action === 'sepa') {
        try { $x = sp_xml($mois, (string)($_GET['date'] ?? '')); } catch (RuntimeException $e) { pfail($e->getMessage()); }
        header('Content-Type: application/xml; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $x['fichier'] . '"');
        header('X-Sepa-Nb: ' . $x['nb']); header('X-Sepa-Total: ' . $x['total']); header('X-Sepa-Exclus: ' . rawurlencode(implode(' | ', $x['exclus']))); echo $x['xml']; exit; }
      sp_schema(); $h = db()->prepare("SELECT msg_id, nb, total, execution, par, created_at FROM sepa_exports WHERE mois = ? ORDER BY id DESC"); $h->execute([$mois]);
      pout(['lignes' => sp_lignes($mois), 'exports' => $h->fetchAll(), 'banque' => ['iban' => sp_masque((string)g_reg()['iban']), 'ok' => sp_iban_ok((string)g_reg()['iban'])], 'titulaire' => est_titulaire()]);

    // ---------- Marges par opération et par marque ----------
    case 'marges':
    case 'marges_xlsx':
      require_once __DIR__ . '/marges_lib.php'; @set_time_limit(240);
      $ok = fn($m) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$m) ? $m : date('Y-m');
      $du = $ok($_GET['du'] ?? ''); $au = $ok($_GET['au'] ?? $du); if ($au < $du) [$du, $au] = [$au, $du];
      $R = m_calc($du, $au); $titre = $du === $au ? r_mois_nom($du) : r_mois_nom($du) . ' à ' . r_mois_nom($au);
      if ($action === 'marges_xlsx') { $bin = m_xlsx($R, $titre); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Marges_' . $du . ($au !== $du ? '_' . $au : '') . '.xlsx"'); echo $bin; exit; }
      $mq = []; foreach (db()->query("SELECT nom FROM marques ORDER BY nom")->fetchAll(PDO::FETCH_COLUMN) as $x) $mq[$x] = 1; foreach (db()->query("SELECT raison FROM g_clients WHERE actif = 1 ORDER BY raison")->fetchAll(PDO::FETCH_COLUMN) as $x) $mq[$x] = 1;
      pout($R + ['titre' => $titre, 'du' => $du, 'au' => $au, 'liste_marques' => array_keys($mq), 'ia' => defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY !== '']);

    case 'marges_rapport':
      // Rapport d'analyse financière (PDF), avec comparaison sur la période précédente et commentaire rédigé facultatif.
      require_once __DIR__ . '/marges_lib.php'; @set_time_limit(300);
      $ok = fn($m) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$m) ? $m : date('Y-m');
      $du = $ok($_GET['du'] ?? ''); $au = $ok($_GET['au'] ?? $du); if ($au < $du) [$du, $au] = [$au, $du];
      $X = m_rapport($du, $au, !empty($_GET['ia']), mb_substr(trim((string)($_GET['note'] ?? '')), 0, 3000));
      $bin = m_pdf($X['html']); header('Content-Type: application/pdf');
      header('Content-Disposition: inline; filename="Analyse_financiere_' . $du . ($au !== $du ? '_' . $au : '') . '.pdf"'); header('Content-Length: ' . strlen($bin)); echo $bin; exit;

    case 'marges_reel':
      // Réel du TESE (synthèse du mois) : brut et charges patronales par personne. Vide = retour à la simulation.
      require_once __DIR__ . '/marges_lib.php'; m_schema(); $m = (string)($in['mois'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) pfail('Mois invalide.');
      $n = 0; foreach ((array)($in['lignes'] ?? []) as $l) { $aid = (int)($l['animatrice_id'] ?? 0); $b = str_replace([',', ' '], ['.', ''], trim((string)($l['brut'] ?? ''))); $c = str_replace([',', ' '], ['.', ''], trim((string)($l['charges'] ?? '')));
        if (!$aid) continue;
        if ($b === '' && $c === '') { db()->prepare("DELETE FROM marges_reel WHERE mois = ? AND animatrice_id = ?")->execute([$m, $aid]); continue; }
        if (!is_numeric($b) || !is_numeric($c) || $b < 0 || $c < 0) pfail('Montants invalides : indiquez le brut et les charges patronales.');
        db()->prepare("REPLACE INTO marges_reel (mois, animatrice_id, brut, charges, par, modifie_le) VALUES (?,?,?,?,?,?)")->execute([$m, $aid, round((float)$b, 2), round((float)$c, 2), admin_nom(), now()]); $n++; }
      pout(['ok' => true, 'n' => $n]);

    case 'marges_tese':
      // Décompte de cotisations TESE (PDF) : 1er envoi = aperçu, 2e (confirmer) = enregistrement.
      require_once __DIR__ . '/marges_lib.php'; @set_time_limit(240);
      if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) pfail('Choisissez le PDF du décompte de cotisations.');
      $pdf = (string)file_get_contents($_FILES['fichier']['tmp_name']); if (!str_starts_with($pdf, '%PDF')) pfail('Ce fichier n\'est pas un PDF.');
      try { pout(m_import_tese($pdf, empty($_POST['confirmer']))); } catch (RuntimeException $e) { pfail($e->getMessage()); }

    case 'marges_salarie':
      // Nom du décompte TESE → personne de la liste, salarié permanent rattaché à une marque, ou frais de structure. Puis les décomptes déjà déposés sont relus.
      require_once __DIR__ . '/marges_lib.php'; m_schema(); $nom = clip(trim((string)($in['nom_tese'] ?? '')), 160); if ($nom === '') pfail('Nom manquant.');
      if (!empty($in['supprimer'])) { db()->prepare("DELETE FROM marges_salaries WHERE nom_tese = ?")->execute([$nom]); pout(['ok' => true, 'relus' => m_reappliquer()]); }
      $type = (string)($in['type'] ?? ''); if (!in_array($type, ['alias', 'permanent', 'structure'], true)) pfail('Choisissez ce que représente ce nom.');
      $aid = null; $marque = ''; $brut = null;
      if ($type === 'alias') { $aid = (int)($in['animatrice_id'] ?? 0); $ex = db()->prepare("SELECT 1 FROM animatrices WHERE id = ?"); $ex->execute([$aid]); if (!$ex->fetchColumn()) pfail('Choisissez la personne dans la liste.'); }
      else { $brut = str_replace([',', ' '], ['.', ''], (string)($in['brut'] ?? '')); if (!is_numeric($brut) || $brut <= 0) pfail('Indiquez son salaire brut mensuel.'); $brut = round((float)$brut, 2);
        if ($type === 'permanent') { $marque = clip(trim((string)($in['marque'] ?? '')), 160); if ($marque === '') pfail('Indiquez la marque à laquelle rattacher ce salarié.'); } }
      db()->prepare("REPLACE INTO marges_salaries (nom_tese, type, animatrice_id, marque, brut, libelle, par, modifie_le) VALUES (?,?,?,?,?,?,?,?)")->execute([$nom, $type, $aid, $marque, $brut, clip($in['libelle'] ?? '', 160), admin_nom(), now()]);
      pout(['ok' => true, 'relus' => m_reappliquer()]);

    case 'marges_cout':
      require_once __DIR__ . '/marges_lib.php'; m_schema();
      if (!empty($in['supprimer'])) { db()->prepare("DELETE FROM marges_couts WHERE id = ?")->execute([(int)$in['supprimer']]); pout(['ok' => true]); }
      $dv = (int)($in['devis_id'] ?? 0); $m = (string)($in['mois'] ?? ''); $lib = clip($in['libelle'] ?? '', 160); $mt = str_replace([',', ' '], ['.', ''], (string)($in['montant'] ?? ''));
      if (!$dv || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) || $lib === '' || !is_numeric($mt)) pfail('Indiquez l\'opération, le libellé et le montant.');
      db()->prepare("INSERT INTO marges_couts (devis_id, mois, libelle, montant, par, created_at) VALUES (?,?,?,?,?,?)")->execute([$dv, $m, $lib, round((float)$mt, 2), admin_nom(), now()]); pout(['ok' => true]);

    case 'marges_synthese':
      // Synthèse TESE du mois jointe (PDF ou photo), archivée chiffrée.
      require_once __DIR__ . '/marges_lib.php'; m_schema(); $m = (string)($_POST['mois'] ?? ''); if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) pfail('Mois invalide.');
      $f = doc_recu();
      $old = db()->prepare("SELECT fichier FROM marges_syntheses WHERE mois = ?"); $old->execute([$m]); if ($o = $old->fetchColumn()) @unlink(fiche_dir() . '/' . basename($o));
      db()->prepare("REPLACE INTO marges_syntheses (mois, fichier, nom, mime, par, created_at) VALUES (?,?,?,?,?,?)")->execute([$m, $f['fichier'], $f['nom'], $f['mime'], admin_nom(), now()]); pout(['ok' => true]);

    case 'marges_synthese_voir':
      require_once __DIR__ . '/marges_lib.php'; m_schema(); $st = db()->prepare("SELECT * FROM marges_syntheses WHERE mois = ?"); $st->execute([(string)($_GET['mois'] ?? '')]); $s = $st->fetch() ?: pfail('Aucune synthèse.', 404);
      $d = amy_dechiffre((string)@file_get_contents(fiche_dir() . '/' . basename($s['fichier']))); if ($d === null) pfail('Fichier illisible.', 404);
      header('Content-Type: ' . $s['mime']); header('X-Content-Type-Options: nosniff'); header('Content-Disposition: inline; filename="' . preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', $s['nom']) . '"'); echo $d; exit;

    // ---------- Relevés d'heures ----------
    case 'releves':
      // Toutes les intervenants qui ont des animations ce mois-ci (ou un relevé), avec l'état du relevé.
      $mois = r_mois_ok((string)($_GET['mois'] ?? '')); r_schema();
      $st = db()->prepare("SELECT DISTINCT animatrice_id FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission BETWEEN ? AND ? UNION SELECT animatrice_id FROM releves WHERE mois = ?");
      $st->execute(array_merge(r_range($mois), [$mois])); $out = [];
      $ids = $st->fetchAll(PDO::FETCH_COLUMN); $qui = (int)($_GET['animatrice_id'] ?? 0); if ($qui) $ids = [$qui]; // une seule personne, même sans animation
      foreach ($ids as $aid) { $R = r_get((int)$aid, $mois); unset($R['lignes'], $R['signature'], $R['journal']); $out[] = $R; }
      // Tous les relevés déjà faits pour cette personne (toutes périodes).
      $hist = []; if ($qui) { $h = db()->prepare("SELECT mois FROM releves WHERE animatrice_id = ? ORDER BY mois DESC LIMIT 60"); $h->execute([$qui]);
        foreach ($h->fetchAll(PDO::FETCH_COLUMN) as $m) { $R = r_get($qui, $m); unset($R['lignes'], $R['signature'], $R['journal']); $hist[] = $R; } }
      usort($out, fn($a, $b) => strcmp($a['animatrice']['nom'] . $a['animatrice']['prenom'], $b['animatrice']['nom'] . $b['animatrice']['prenom']));
      pout(['mois' => $mois, 'mois_txt' => r_mois_txt($mois), 'releves' => $out, 'historique' => $hist, 'ouvert' => p_ouvert()]);

    case 'releve':
      $R = r_get((int)($_GET['animatrice_id'] ?? 0), r_mois_ok((string)($_GET['mois'] ?? ''))); $R['signature'] = (bool)$R['signature']; pout(['releve' => $R]);

    case 'releve_save':
      $aid = (int)($in['animatrice_id'] ?? 0); $mois = r_mois_ok((string)($in['mois'] ?? '')); $row = r_ensure($aid, $mois);
      if ($row['statut'] !== 'brouillon') pfail('Ce relevé a déjà été envoyé. Repassez-le en brouillon pour le modifier.');
      $hm = fn($v, $d) => preg_match('/^\d{1,2}:\d{2}$/', (string)$v) ? substr('0' . $v, -5) : $d;
      $aj = []; foreach ((array)($in['ajust'] ?? []) as $mid => $a) $aj[(int)$mid] = ['debut' => $hm($a['debut'] ?? '', '10:00'), 'fin' => $hm($a['fin'] ?? '', '18:00'), 'pause' => max(0, min(600, (int)($a['pause'] ?? 0))), 'note' => clip($a['note'] ?? '', 200), 'exclu' => !empty($a['exclu'])];
      $ex = []; foreach ((array)($in['extras'] ?? []) as $x) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($x['date'] ?? ''))) continue;
        $ex[] = ['id' => 'x' . substr(md5(json_encode($x) . count($ex)), 0, 8), 'date' => $x['date'], 'magasin' => clip($x['magasin'] ?? '', 160), 'poste' => clip($x['poste'] ?? 'Hors planning', 160), 'debut' => $hm($x['debut'] ?? '', '10:00'), 'fin' => $hm($x['fin'] ?? '', '18:00'), 'pause' => max(0, min(600, (int)($x['pause'] ?? 0))), 'note' => clip($x['note'] ?? '', 200)]; }
      db()->prepare("UPDATE releves SET ajust = ?, extras = ?, commentaire = ?, modifie_le = ? WHERE id = ?")->execute([json_encode($aj, JSON_UNESCAPED_UNICODE), json_encode($ex, JSON_UNESCAPED_UNICODE), clip($in['commentaire'] ?? '', 2000), now(), $row['id']]);
      $R = r_get($aid, $mois); $R['signature'] = false; pout(['ok' => true, 'releve' => $R]);

    case 'releve_envoyer':
      // La direction a vérifié : le relevé est figé et part à l'intervenant(e) pour signature.
      $aid = (int)($in['animatrice_id'] ?? 0); $mois = r_mois_ok((string)($in['mois'] ?? '')); $row = r_ensure($aid, $mois);
      if ($row['statut'] === 'signe') pfail('Ce relevé est déjà signé.');
      $R = r_get($aid, $mois); if ($row['statut'] === 'brouillon' && !$R['lignes']) pfail('Aucune heure sur ce relevé.');
      [$R, $ok] = pl_releve_envoyer($aid, $mois); $R['signature'] = false; pout(['ok' => true, 'mail' => $ok, 'test' => !p_ouvert(), 'releve' => $R]);

    case 'releves_envoyer_tous':
      // Tous les relevés « à vérifier » de la période partent d'un coup (ceux sans heure sont laissés de côté).
      $mois = r_mois_ok((string)($in['mois'] ?? '')); r_schema(); $n = 0; $sans = 0; $mails = 0;
      $st = db()->prepare("SELECT DISTINCT animatrice_id FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission BETWEEN ? AND ? UNION SELECT animatrice_id FROM releves WHERE mois = ?");
      $st->execute(array_merge(r_range($mois), [$mois])); $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
      if (is_array($in['ids'] ?? null)) $ids = array_values(array_intersect($ids, array_map('intval', $in['ids'])));
      foreach ($ids as $aid) { $row = r_ensure($aid, $mois); if ($row['statut'] !== 'brouillon') continue; $R = r_get($aid, $mois); if (!$R['lignes']) { $sans++; continue; }
        [, $ok] = pl_releve_envoyer($aid, $mois); $n++; if ($ok) $mails++; }
      pout(['ok' => true, 'n' => $n, 'mails' => $mails, 'sans' => $sans, 'test' => !p_ouvert()]);

    case 'releve_rouvrir':
      // Retour en brouillon pour corriger ; une signature existante est annulée et gardée dans le journal.
      $aid = (int)($in['animatrice_id'] ?? 0); $mois = r_mois_ok((string)($in['mois'] ?? '')); $row = r_row($aid, $mois) ?: pfail('Relevé introuvable.');
      r_journal((int)$row['id'], 'Repassé en brouillon par ' . admin_nom() . ($row['signe_le'] ? ' (signature du ' . date('d/m/Y H:i', strtotime($row['signe_le'])) . ' par ' . $row['signe_nom'] . ' annulée, empreinte ' . substr($row['empreinte'], 0, 16) . '…)' : ''));
      db()->prepare("UPDATE releves SET statut = 'brouillon', lignes = NULL, signe_le = NULL, signe_nom = '', signe_ip = '', signe_ua = '', signature = NULL, signe_test = 0, empreinte = '', modifie_le = ? WHERE id = ?")->execute([now(), $row['id']]);
      $R = r_get($aid, $mois); $R['signature'] = false; pout(['ok' => true, 'releve' => $R]);

    case 'releve_pdf':
      $R = r_get((int)($_GET['animatrice_id'] ?? 0), r_mois_ok((string)($_GET['mois'] ?? '')));
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . r_fichier($R) . '"'); echo r_pdf($R); exit;

    default: pfail('Action inconnue', 404);
  }
} catch (Throwable $e) {
  pfail(erreur_publique($e, 'planning'), 500);
}
