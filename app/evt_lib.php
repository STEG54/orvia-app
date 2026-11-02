<?php
// Rapports d'événement (accueil et hôtessariat) : salon, soirée, congrès, accueil en entreprise.
// Rédigés dans l'appli par le chef d'équipe (ou chaque hôte/hôtesse), validés par l'agence, puis visibles par le client dans l'espace marques.
require_once __DIR__ . '/gestion_lib.php';

const EV_CHAMPS = [
  'deroulement' => ['Déroulement de la journée', 'Arrivées, temps forts, rythme de la journée'],
  'demandes' => ['Questions fréquentes des visiteurs', 'Ce que les visiteurs ont le plus demandé'],
  'points_forts' => ['Points forts', 'Ce qui a bien fonctionné'],
  'ameliorer' => ['Points à améliorer', 'Signalétique, flux, matériel, horaires…'],
  'incidents' => ['Incidents', 'Retards, problème technique, réclamation (ou rien à signaler)'],
  'equipe' => ['Équipe', 'Présence, ponctualité, tenue, répartition des postes (chef d\'équipe)'],
];
const EV_PHOTOS = 8;

function ev_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; g_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS evenements_cr (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(32) NOT NULL, animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NULL,
    date_evt DATE NOT NULL, lieu VARCHAR(160) NOT NULL DEFAULT '', role VARCHAR(120) NOT NULL DEFAULT '', affluence VARCHAR(40) NOT NULL DEFAULT '', visiteurs INT NULL, contacts INT NULL, documents INT NULL,
    deroulement TEXT NULL, demandes TEXT NULL, points_forts TEXT NULL, ameliorer TEXT NULL, incidents TEXT NULL, equipe TEXT NULL, recommandations TEXT NULL,
    valide_le DATETIME NULL, valide_par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, UNIQUE KEY (token), INDEX (devis_id, date_evt), INDEX (animatrice_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!in_array('evt_id', db()->query("SHOW COLUMNS FROM photos")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE photos ADD COLUMN evt_id INT UNSIGNED NULL, ADD INDEX (evt_id)");
}
function ev_chef(string $role): bool { return (bool)preg_match('/chef|responsable|régisseu/i', $role); }

/** Missions d'accueil de la personne (30 derniers jours et aujourd'hui), avec ou sans rapport. */
function ev_missions(int $aid): array {
  ev_schema(); p_schema();
  $st = db()->prepare("SELECT m.devis_id, m.date_mission, m.magasin, m.ligne, m.debut, m.fin FROM missions m JOIN g_docs d ON d.id = m.devis_id
    WHERE m.animatrice_id = ? AND d.pole = 'hot' AND m.statut <> 'annulee' AND m.date_mission BETWEEN ? AND ? ORDER BY m.date_mission DESC");
  $st->execute([$aid, date('Y-m-d', strtotime('-30 days')), date('Y-m-d')]); $out = []; $vus = [];
  $fait = db()->prepare("SELECT id FROM evenements_cr WHERE animatrice_id = ? AND devis_id = ? AND date_evt = ?");
  $eq = db()->prepare("SELECT CONCAT(a.prenom, ' ', a.nom) AS nom, a.telephone, m.ligne FROM missions m JOIN animatrices a ON a.id = m.animatrice_id WHERE m.devis_id = ? AND m.date_mission = ? AND m.statut <> 'annulee' AND m.animatrice_id <> ?");
  foreach ($st->fetchAll() as $m) {
    $k = $m['devis_id'] . '|' . $m['date_mission']; if (isset($vus[$k])) continue; $vus[$k] = 1;
    $d = g_doc((int)$m['devis_id']); $role = (string)($d['lignes'][(int)$m['ligne']]['role'] ?? '');
    $fait->execute([$aid, $m['devis_id'], $m['date_mission']]);
    $eq->execute([$m['devis_id'], $m['date_mission'], $aid]);
    $out[] = ['devis_id' => (int)$m['devis_id'], 'date' => $m['date_mission'], 'lieu' => $m['magasin'], 'role' => $role, 'chef' => ev_chef($role), 'client' => (string)($d['client']['raison'] ?? ''),
      'evenement' => (string)($d['objet'] ?: ($d['type_op'] ?? '')), 'type' => (string)($d['type_op'] ?? ''), 'fait' => (bool)$fait->fetchColumn(),
      'equipe' => array_map(fn($x) => ['nom' => $x['nom'], 'telephone' => $x['telephone'], 'role' => (string)($d['lignes'][(int)$x['ligne']]['role'] ?? '')], $eq->fetchAll())];
  }
  return $out;
}
/** Un rapport d'événement complet (avec l'événement, l'auteur et les photos). */
function ev_row(int $id): ?array {
  ev_schema();
  $st = db()->prepare("SELECT e.*, CONCAT(a.prenom, ' ', a.nom) AS auteur FROM evenements_cr e JOIN animatrices a ON a.id = e.animatrice_id WHERE e.id = ?"); $st->execute([$id]); $e = $st->fetch(); if (!$e) return null;
  $d = $e['devis_id'] ? g_doc((int)$e['devis_id']) : null;
  $e['client'] = (string)($d['client']['raison'] ?? ''); $e['evenement'] = (string)($d['objet'] ?? ''); $e['type'] = (string)($d['type_op'] ?? ''); $e['marque_id'] = null;
  if ($d && !empty($d['client_id'])) { $m = db()->prepare("SELECT marque_id FROM g_clients WHERE id = ?"); $m->execute([$d['client_id']]); $e['marque_id'] = ($v = $m->fetchColumn()) ? (int)$v : null; }
  $ph = db()->prepare("SELECT id, slot, fichier, client FROM photos WHERE evt_id = ? ORDER BY id"); $ph->execute([$id]);
  $e['photos'] = array_map(fn($p) => $p + ['url' => photo_url($p['fichier'], true), 'full' => photo_url($p['fichier'])], $ph->fetchAll());
  $e['equipe_jour'] = [];
  if ($e['devis_id']) { $q = db()->prepare("SELECT CONCAT(a.prenom, ' ', a.nom) AS nom, m.ligne FROM missions m JOIN animatrices a ON a.id = m.animatrice_id WHERE m.devis_id = ? AND m.date_mission = ? AND m.statut <> 'annulee'");
    $q->execute([$e['devis_id'], $e['date_evt']]); foreach ($q->fetchAll() as $x) $e['equipe_jour'][] = ['nom' => $x['nom'], 'role' => (string)($d['lignes'][(int)$x['ligne']]['role'] ?? '')]; }
  return $e;
}
/** Liste filtrée : du, au, devis_id, statut (avalider / valide), marques (ids, espace marques). */
function ev_liste(array $f): array {
  ev_schema(); $w = ['e.date_evt BETWEEN ? AND ?']; $a = [$f['from'] ?: '2000-01-01', $f['to'] ?: '2100-01-01'];
  if (!empty($f['devis_id'])) { $w[] = 'e.devis_id = ?'; $a[] = (int)$f['devis_id']; }
  if (($f['statut'] ?? '') === 'avalider') $w[] = 'e.valide_le IS NULL';
  if (($f['statut'] ?? '') === 'valide') $w[] = 'e.valide_le IS NOT NULL';
  if (isset($f['marques'])) { $m = array_map('intval', (array)$f['marques']); if (!$m) return [];
    $w[] = 'e.devis_id IN (SELECT d.id FROM g_docs d JOIN g_clients c ON c.id = d.client_id WHERE c.marque_id IN (' . implode(',', $m) . '))'; }
  $st = db()->prepare("SELECT e.id, e.date_evt, e.lieu, e.role, e.affluence, e.visiteurs, e.contacts, e.documents, e.valide_le, e.devis_id, CONCAT(a.prenom, ' ', a.nom) AS auteur, d.objet AS evenement, d.type_op AS type,
      JSON_UNQUOTE(JSON_EXTRACT(d.client, '$.raison')) AS client, (SELECT COUNT(*) FROM photos p WHERE p.evt_id = e.id) AS nb_photos
    FROM evenements_cr e JOIN animatrices a ON a.id = e.animatrice_id LEFT JOIN g_docs d ON d.id = e.devis_id WHERE " . implode(' AND ', $w) . " ORDER BY e.date_evt DESC, e.id DESC LIMIT 500");
  $st->execute($a); return $st->fetchAll();
}
/** Recommandations proposées d'après le rapport : point de départ que l'agence relit et complète avant l'envoi. */
function ev_reco_auto(array $e): string {
  $r = [];
  if (in_array($e['affluence'], ['Bonne', 'Excellente'], true)) $r[] = 'Affluence ' . mb_strtolower($e['affluence']) . ' : prévoir un renfort sur les créneaux de pointe lors de la prochaine édition.';
  if ($e['affluence'] === 'Mauvaise') $r[] = 'Affluence faible : revoir les horaires ou l\'emplacement du point d\'accueil.';
  if ((int)$e['visiteurs'] > 0 && (int)$e['contacts'] > 0) $r[] = 'Taux de contacts : ' . round(100 * (int)$e['contacts'] / max(1, (int)$e['visiteurs'])) . ' % des visiteurs accueillis ont laissé leurs coordonnées.';
  if (trim((string)$e['ameliorer']) !== '') $r[] = 'À traiter avant la prochaine édition : ' . (function ($t) { return mb_strtolower(mb_substr($t, 0, 1)) . mb_substr($t, 1); })(rtrim(trim(preg_replace('/\s+/', ' ', (string)$e['ameliorer'])), '.')) . '.';
  if (trim((string)$e['demandes']) !== '') $r[] = 'Préparer une réponse type ou un support pour les questions les plus fréquentes.';
  if (trim((string)$e['incidents']) !== '' && !preg_match('/^(rien|ras|aucun)/i', trim((string)$e['incidents']))) $r[] = 'Incident signalé : en faire le point avec le client.';
  return implode("\n", $r);
}
/** PDF du rapport d'événement (envoyé ou téléchargé par le client). */
function ev_html(array $e): string {
  $r = g_reg(); $p = ag_couleur(); $L = '';
  $kpi = fn($n, $l) => '<td><div class="k">' . ($n === null || $n === '' ? '–' : number_format((int)$n, 0, ',', ' ')) . '</div><div class="l">' . h($l) . '</div></td>';
  foreach (EV_CHAMPS as $k => [$lab]) if (trim((string)$e[$k]) !== '') $L .= '<h3>' . h($lab) . '</h3><p>' . nl2br(h(trim((string)$e[$k]))) . '</p>';
  $reco = trim((string)($e['recommandations'] ?? '')); if ($reco === '') $reco = ev_reco_auto($e);
  if ($reco !== '') $L .= '<h3>Recommandations</h3><ul>' . implode('', array_map(fn($x) => '<li>' . h(ltrim($x, "-• ")) . '</li>', array_filter(array_map('trim', explode("\n", $reco))))) . '</ul>';
  $ph = ''; foreach ($e['photos'] as $x) if ((int)$x['client'] && is_file(upload_dir() . '/' . $x['fichier'] . '_t.jpg')) $ph .= '<img src="uploads/' . h($x['fichier']) . '_t.jpg">';
  $eq = $e['equipe_jour'] ? '<p class="mut">Équipe : ' . h(implode(' · ', array_map(fn($x) => $x['nom'] . ($x['role'] ? ' (' . $x['role'] . ')' : ''), $e['equipe_jour']))) . '</p>' : '';
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:36px">' : '<b>' . h($r['societe'] ?: AG_NOM) . '</b>';
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:16mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#16123A;line-height:1.5}h1{font-size:17pt;color:' . $p . ';margin:14pt 0 2pt}
    h3{font-size:10.5pt;margin:12pt 0 3pt;color:' . $p . '}p{margin:0 0 6pt}.mut{color:#6E6A8F;font-size:8.5pt}table.kp{width:100%;border-collapse:separate;border-spacing:6pt 0;margin:10pt -6pt}table.kp td{background:#F4F2FF;border-radius:6pt;padding:8pt;text-align:center}
    .k{font-size:15pt;font-weight:bold}.l{font-size:7.5pt;color:#6E6A8F}.ph img{width:31%;margin:0 1% 6pt 0;border-radius:4pt}ul{margin:0 0 6pt 14pt;padding:0}</style></head><body>' . $logo
    . '<h1>Rapport d\'événement</h1><p><b>' . h($e['evenement'] ?: $e['type']) . '</b>' . ($e['client'] ? ' · ' . h($e['client']) : '') . '<br>' . h(g_date($e['date_evt'])) . ' · ' . h($e['lieu']) . '</p>'
    . '<p class="mut">Rédigé par ' . h($e['auteur']) . ($e['role'] ? ' (' . h($e['role']) . ')' : '') . ($e['affluence'] ? ' · affluence : ' . h(mb_strtolower($e['affluence'])) : '') . '</p>' . $eq
    . '<table class="kp"><tr>' . $kpi($e['visiteurs'], 'visiteurs accueillis') . $kpi($e['contacts'], 'contacts collectés') . $kpi($e['documents'], 'documents ou cadeaux remis') . '</tr></table>'
    . $L . ($ph ? '<h3>Photos</h3><div class="ph">' . $ph . '</div>' : '') . '</body></html>';
}
function ev_pdf(array $e): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(120);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml(ev_html($e), 'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
}

/* ---------- Rapport événementiel au client : bilan d'une opération (toutes ses journées) ---------- */
/** Opérations d'accueil qui ont des rapports sur la période : chiffres cumulés, journées validées. */
function ev_ops(string $from, string $to): array {
  ev_schema();
  $st = db()->prepare("SELECT d.id, d.numero, d.objet, d.type_op, JSON_UNQUOTE(JSON_EXTRACT(d.client, '$.raison')) AS client, COUNT(e.id) AS n, SUM(e.valide_le IS NOT NULL) AS nv,
      MIN(e.date_evt) AS du, MAX(e.date_evt) AS au, SUM(e.visiteurs) AS visiteurs, SUM(e.contacts) AS contacts, SUM(e.documents) AS documents
    FROM evenements_cr e JOIN g_docs d ON d.id = e.devis_id WHERE e.date_evt BETWEEN ? AND ? GROUP BY d.id, d.numero, d.objet, d.type_op, d.client ORDER BY au DESC");
  $st->execute([$from ?: '2000-01-01', $to ?: '2100-01-01']); return $st->fetchAll();
}
/** Bilan d'une opération : journées (rapports), composition de l'équipe par poste, totaux, recommandations proposées. $ids : rapports retenus (sinon tous les validés). */
function ev_bilan(int $devis, ?array $ids = null): array {
  ev_schema(); $d = g_doc($devis); if (!$d) throw new RuntimeException('Opération introuvable.');
  $st = db()->prepare("SELECT id FROM evenements_cr WHERE devis_id = ? ORDER BY date_evt, id"); $st->execute([$devis]);
  $all = array_map(fn($i) => ev_row((int)$i), $st->fetchAll(PDO::FETCH_COLUMN));
  $sel = array_values(array_filter($all, fn($e) => $ids === null ? (bool)$e['valide_le'] : in_array((int)$e['id'], $ids, true)));
  $q = db()->prepare("SELECT m.ligne, m.animatrice_id, CONCAT(a.prenom, ' ', a.nom) AS nom FROM missions m JOIN animatrices a ON a.id = m.animatrice_id WHERE m.devis_id = ? AND m.statut <> 'annulee'"); $q->execute([$devis]);
  $eq = []; foreach ($q->fetchAll() as $x) { $r = (string)($d['lignes'][(int)$x['ligne']]['role'] ?? '') ?: 'Équipe'; $eq[$r][$x['animatrice_id']] = $x['nom']; }
  uksort($eq, fn($a, $b) => [!ev_chef($a), $a] <=> [!ev_chef($b), $b]);
  $equipe = []; foreach ($eq as $r => $L) $equipe[] = ['role' => $r, 'noms' => array_values($L)];
  $sum = fn($k) => array_sum(array_map(fn($e) => (int)$e[$k], $sel));
  $tot = ['jours' => count($sel), 'visiteurs' => $sum('visiteurs'), 'contacts' => $sum('contacts'), 'documents' => $sum('documents'), 'personnes' => count(array_unique(array_merge(...array_map(fn($x) => array_values($x['noms']), $equipe ?: [['noms' => []]]))))];
  // Recommandations : celles des journées (validées par l'agence), sans doublon, puis le bilan chiffré.
  $R = [];
  if ($tot['visiteurs'] && $tot['contacts']) $R[] = 'Sur l\'opération : ' . number_format($tot['visiteurs'], 0, ',', ' ') . ' visiteurs accueillis, ' . number_format($tot['contacts'], 0, ',', ' ') . ' contacts collectés (' . round(100 * $tot['contacts'] / max(1, $tot['visiteurs'])) . ' %).';
  if (count($sel) > 1) { $tmp = $sel; usort($tmp, fn($a, $b) => (int)$b['visiteurs'] <=> (int)$a['visiteurs']); $best = $tmp[0];
    if ((int)$best['visiteurs']) $R[] = 'Journée la plus fréquentée : ' . g_date($best['date_evt']) . ' (' . number_format((int)$best['visiteurs'], 0, ',', ' ') . ' visiteurs) : à prendre comme référence pour dimensionner l\'équipe.'; }
  foreach ($sel as $e) foreach (array_filter(array_map('trim', explode("\n", trim((string)$e['recommandations']) !== '' ? (string)$e['recommandations'] : ev_reco_auto($e)))) as $l)
    if (!preg_match('/^(Taux de contacts|Affluence )/u', $l) && !in_array($l, $R, true)) $R[] = $l;
  return ['op' => ['id' => (int)$d['id'], 'numero' => $d['numero'], 'objet' => (string)$d['objet'], 'type' => (string)($d['type_op'] ?? ''), 'client' => (string)($d['client']['raison'] ?? ''),
      'emails' => trim((string)($d['client']['emails'] ?? '')), 'client_id' => (int)($d['client_id'] ?? 0)],
    'evts' => $all, 'sel' => $sel, 'equipe' => $equipe, 'tot' => $tot, 'reco_auto' => implode("\n", $R)];
}
function ev_bilan_html(array $B, string $reco): string {
  $r = g_reg(); $p = ag_couleur(); $O = $B['op']; $T = $B['tot']; $nf = fn($n) => number_format((int)$n, 0, ',', ' ');
  $kpi = fn($n, $l) => '<td><div class="k">' . h($n) . '</div><div class="l">' . h($l) . '</div></td>';
  $du = $B['sel'] ? $B['sel'][0]['date_evt'] : null; $au = $B['sel'] ? end($B['sel'])['date_evt'] : null;
  $lieux = implode(', ', array_unique(array_filter(array_map(fn($e) => $e['lieu'], $B['sel']))));
  $eq = ''; foreach ($B['equipe'] as $x) $eq .= '<tr><td><b>' . h($x['role']) . '</b></td><td class="n">' . count($x['noms']) . '</td><td>' . h(implode(', ', $x['noms'])) . '</td></tr>';
  $jr = ''; foreach ($B['sel'] as $e) $jr .= '<tr><td>' . h(g_date($e['date_evt'])) . '</td><td>' . h(mb_strtolower((string)$e['affluence'])) . '</td><td class="n">' . $nf($e['visiteurs']) . '</td><td class="n">' . $nf($e['contacts']) . '</td><td class="n">' . $nf($e['documents']) . '</td></tr>';
  $txt = ''; foreach ($B['sel'] as $e) { $s = ''; foreach (EV_CHAMPS as $k => [$lab]) if (trim((string)$e[$k]) !== '') $s .= '<p><b>' . h($lab) . ' :</b> ' . nl2br(h(trim((string)$e[$k]))) . '</p>';
    if ($s) $txt .= '<h3>' . h(g_date($e['date_evt'])) . ($e['lieu'] ? ' · ' . h($e['lieu']) : '') . '</h3>' . $s; }
  $ph = ''; $n = 0; foreach ($B['sel'] as $e) foreach ($e['photos'] as $x) if ((int)$x['client'] && $x['slot'] !== 'animatrice' && $n < 12 && is_file(upload_dir() . '/' . $x['fichier'] . '_t.jpg')) { $ph .= '<img src="uploads/' . h($x['fichier']) . '_t.jpg">'; $n++; }
  $rl = array_filter(array_map('trim', explode("\n", $reco)), fn($l) => $l !== '' && $l !== '-');
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:36px">' : '<b>' . h($r['societe'] ?: AG_NOM) . '</b>';
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:16mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:#16123A;line-height:1.5}h1{font-size:18pt;color:' . $p . ';margin:14pt 0 2pt}
    h2{font-size:12pt;color:' . $p . ';margin:16pt 0 4pt;border-bottom:1pt solid #E6E1F5;padding-bottom:2pt}h3{font-size:10pt;margin:10pt 0 2pt}p{margin:0 0 5pt}.mut{color:#6E6A8F;font-size:8.5pt}
    table.kp{width:100%;border-collapse:separate;border-spacing:6pt 0;margin:10pt -6pt}table.kp td{background:#F4F2FF;border-radius:6pt;padding:8pt;text-align:center}.k{font-size:15pt;font-weight:bold}.l{font-size:7.5pt;color:#6E6A8F}
    table.t{width:100%;border-collapse:collapse}table.t th{text-align:left;font-size:8pt;color:#6E6A8F;border-bottom:1pt solid #E6E1F5;padding:3pt}table.t th.n{text-align:right}table.t td{padding:4pt 3pt;border-bottom:.5pt solid #EEE}.n{text-align:right}
    .ph img{width:31%;margin:0 1% 6pt 0;border-radius:4pt}ul{margin:0 0 6pt 14pt;padding:0}</style></head><body>' . $logo
    . '<h1>Rapport événementiel</h1><p><b>' . h($O['objet'] ?: $O['type']) . '</b>' . ($O['client'] ? ' · ' . h($O['client']) : '') . '<br>'
    . ($du ? h($du === $au ? g_date($du) : 'Du ' . g_date($du) . ' au ' . g_date($au)) : '') . ($lieux ? ' · ' . h($lieux) : '') . '</p>' . ($O['type'] ? '<p class="mut">' . h($O['type']) . '</p>' : '')
    . '<table class="kp"><tr>' . $kpi($T['jours'], 'journée' . ($T['jours'] > 1 ? 's' : '')) . $kpi($T['personnes'], 'personnes mobilisées') . $kpi($nf($T['visiteurs']), 'visiteurs accueillis') . $kpi($nf($T['contacts']), 'contacts collectés') . $kpi($nf($T['documents']), 'documents ou cadeaux remis') . '</tr></table>'
    . ($rl ? '<h2>Recommandations</h2><ul>' . implode('', array_map(fn($x) => '<li>' . h(ltrim($x, "-• ")) . '</li>', $rl)) . '</ul>' : '')
    . ($eq ? '<h2>Équipe</h2><table class="t"><tr><th>Poste</th><th class="n">Nb</th><th>Personnes</th></tr>' . $eq . '</table>' : '')
    . ($jr ? '<h2>Journée par journée</h2><table class="t"><tr><th>Date</th><th>Affluence</th><th class="n">Visiteurs</th><th class="n">Contacts</th><th class="n">Documents</th></tr>' . $jr . '</table>' : '')
    . ($txt ? '<h2>Comptes rendus du terrain</h2>' . $txt : '') . ($ph ? '<h2>Photos</h2><div class="ph">' . $ph . '</div>' : '') . '</body></html>';
}
function ev_bilan_pdf(array $B, string $reco): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(120);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml(ev_bilan_html($B, $reco), 'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
}
