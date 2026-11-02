<?php
// Construction des comptes rendus, de l'analyse des ventes, des PDF et de l'envoi par e-mail.
require_once __DIR__ . '/dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const C_ESP = '#1A1512', C_SAND = '#F4EEE7', C_LINE = '#E3D9CD', C_MUTED = '#7A6D62';

function report_params(array $in): array {
  $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['from'] ?? '') ? $in['from'] : date('Y-m-01');
  $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['to'] ?? '') ? $in['to'] : date('Y-m-d');
  if ($to < $from) [$from, $to] = [$to, $from];
  $marques = is_array($in['marques'] ?? null) ? $in['marques'] : array_filter(explode(',', (string)($in['marques'] ?? '')));
  $zone = secteur_deps((string)($in['zone'] ?? '')) !== null ? (string)$in['zone'] : ''; // « d:75 » ou « s:Secteur » ; vide = toute la France
  // Photos choisies par l'administratrice (null = toutes).
  $ph = null;
  if (!empty($in['sel'])) { $ph = is_array($in['photos'] ?? null) ? $in['photos'] : array_filter(explode(',', (string)($in['photos'] ?? ''))); $ph = array_values(array_map('intval', $ph)); }
  // Comptes rendus cochés (null = tous).
  $rids = null;
  if (!empty($in['rsel'])) { $rids = is_array($in['rids'] ?? null) ? $in['rids'] : array_filter(explode(',', (string)($in['rids'] ?? ''))); $rids = array_values(array_map('intval', $rids)); }
  return ['from' => $from, 'to' => $to, 'marques' => array_values(array_map('intval', $marques)), 'zone' => $zone, 'photos' => $ph, 'rids' => $rids,
    'groupe' => mb_substr(trim((string)($in['groupe'] ?? '')), 0, 80),
    // Excel : un onglet par mois, ou un par intervenant(e) sur l'année ; un fichier par point de vente si demandé.
    'xfmt' => ($in['xfmt'] ?? '') === 'annee' ? 'annee' : 'mois', 'xmag' => !empty($in['xmag']), 'reco' => mb_substr(trim((string)($in['reco'] ?? '')), 0, 6000)];
}

/** Rassemble les comptes rendus de la période pour les marques choisies (toutes si aucune). */
function collect(array $p): array {
  $w = ['r.date_animation BETWEEN ? AND ?']; $a = [$p['from'], $p['to']];
  $cl = $p['client'] ?? null; // espace marques : garde-fous appliqués ici, côté serveur
  if ($p['zone'] && ($sq = secteur_sql($p['zone'], $a)) !== '') $w[] = $sq;
  if (!empty($p['zones'])) { $w[] = 'r.zone IN (' . implode(',', array_fill(0, count($p['zones']), '?')) . ')'; array_push($a, ...$p['zones']); }
  if (!empty($p['magasin'])) { $w[] = 'r.magasin = ?'; $a[] = $p['magasin']; }
  if (!empty($p['animatrice'])) { $w[] = 'r.animatrice_id = ?'; $a[] = (int)$p['animatrice']; }
  if ($cl) $w[] = 'r.valide_le IS NOT NULL';
  if (($p['rids'] ?? null) !== null) $w[] = 'r.id IN (' . ($p['rids'] ? implode(',', array_map('intval', $p['rids'])) : '0') . ')';
  $mf = '';
  if ($p['marques']) { $mf = ' AND rm.marque_id IN (' . implode(',', $p['marques']) . ')'; }
  $w[] = "EXISTS (SELECT 1 FROM rapport_marques rm WHERE rm.rapport_id = r.id$mf)";
  $st = db()->prepare("SELECT r.*, CONCAT(an.prenom, ' ', an.nom) AS animatrice, an.prenom AS anim_prenom, COALESCE(mg.circuit, '') AS circuit FROM rapports r JOIN animatrices an ON an.id = r.animatrice_id LEFT JOIN magasins mg ON mg.id = r.magasin_id
    WHERE " . implode(' AND ', $w) . " ORDER BY r.date_animation, r.magasin");
  $st->execute($a); $rapports = $st->fetchAll();
  $ids = array_column($rapports, 'id') ?: [0];
  $in = implode(',', array_map('intval', $ids));
  $lines = db()->query("SELECT rm.*, m.nom AS marque, m.logo, m.sous_marques, m.detail FROM rapport_marques rm JOIN marques m ON m.id = rm.marque_id WHERE rm.rapport_id IN ($in)$mf ORDER BY m.nom")->fetchAll();
  $photos = db()->query("SELECT * FROM photos WHERE rapport_id IN ($in)" . ($cl ? " AND client = 1 AND slot <> 'animatrice'" : '') . " ORDER BY id")->fetchAll();
  if ($cl && !in_array('photos', $cl['droits'], true)) $photos = [];
  $keepLine = array_flip(array_column($lines, 'id'));
  $byR = [];
  foreach ($rapports as $r) { $r['concurrence'] = json_decode($r['concurrence'] ?: '[]', true) ?: []; $r['lines'] = []; $r['photos'] = []; $byR[$r['id']] = $r; }
  foreach ($lines as $l) { $l['photos'] = []; $l['parts'] = json_decode((string)($l['ventilation'] ?? ''), true) ?: []; $byR[$l['rapport_id']]['lines'][$l['id']] = $l; }
  if (($p['photos'] ?? null) !== null) { $keep = array_flip($p['photos']); $photos = array_values(array_filter($photos, fn($x) => isset($keep[(int)$x['id']]))); }
  foreach ($photos as $ph) {
    if ($ph['rapport_marque_id'] === null) $byR[$ph['rapport_id']]['photos'][] = $ph;
    elseif (isset($keepLine[$ph['rapport_marque_id']])) $byR[$ph['rapport_id']]['lines'][$ph['rapport_marque_id']]['photos'][] = $ph;
  }
  $brands = [];
  foreach ($lines as $l) $brands[$l['marque_id']] = ['id' => $l['marque_id'], 'nom' => $l['marque'], 'logo' => $l['logo'], 'sous_marques' => $l['sous_marques'], 'detail' => $l['detail']];
  if (!$brands && $p['marques']) foreach (db()->query("SELECT id, nom, logo, sous_marques, detail FROM marques WHERE id IN (" . implode(',', $p['marques']) . ")") as $b) $brands[$b['id']] = $b;
  // Ordre choisi par l'administratrice (sinon alphabétique).
  $pos = array_flip($p['marques']);
  uasort($brands, fn($x, $y) => $p['marques'] ? (($pos[$x['id']] ?? 999) <=> ($pos[$y['id']] ?? 999)) : strcmp($x['nom'], $y['nom']));
  $rapports = array_values($byR);
  if ($cl) { // un compte sans marque (BA seulement) ne voit aucune animation
    // Périmètre exact du compte : une marque n'est visible que dans ses zones .
    foreach ($rapports as $i => $r) {
      foreach ($r['lines'] as $lid => $l) if (!perimetre_ok($cl['perimetre'] ?? [], (int)$l['marque_id'], (string)$r['zone'], (string)$r['circuit'])) unset($rapports[$i]['lines'][$lid]);
      if (!$rapports[$i]['lines']) unset($rapports[$i]);
    }
    $rapports = array_values($rapports);
    $keepB = []; foreach ($rapports as $r) foreach ($r['lines'] as $l) $keepB[$l['marque_id']] = true;
    $brands = array_filter($brands, fn($b) => isset($keepB[$b['id']]));
  }
  if ($cl) foreach ($rapports as &$r) {
    // Nom de l'intervenant(e) selon le réglage du compte, commentaires et chiffres selon ses droits.
    $r['animatrice'] = $cl['noms'] === 'complet' ? $r['animatrice'] : ($cl['noms'] === 'non' ? 'Intervenant(e)' : explode(' ', trim($r['anim_prenom']))[0]);
    if (!in_array('cr', $cl['droits'], true)) { foreach (['offres', 'staff', 'faits', 'concurrence_autre'] as $k) $r[$k] = ''; $r['concurrence'] = [];
      foreach ($r['lines'] as &$l) { $l['reactions'] = ''; $l['ruptures'] = ''; $l['testeurs'] = ''; } unset($l); }
    if (!in_array('ca', $cl['droits'], true)) { foreach ($r['lines'] as &$l) { $l['ca'] = null; $l['objectif'] = null; foreach ($l['parts'] as &$pp) { $pp['ca'] = null; $pp['objectif'] = null; } unset($pp); } unset($l); }
    $r['staff'] = ''; // remarques internes sur le personnel : jamais montrées aux marques
  }
  unset($r);
  return ['p' => $p, 'rapports' => $rapports, 'brands' => array_values($brands)];
}

function period_label(array $p): string {
  return $p['from'] === $p['to'] ? 'le ' . date_fr($p['from'], true) : 'du ' . date_fr($p['from'], true) . ' au ' . date_fr($p['to'], true);
}
/** « du 1er au 7 octobre 2026 », « du 28 septembre au 7 octobre 2026 »… */
function period_short(array $p): string {
  $m = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  $j = fn($t) => (int)date('j', $t) === 1 ? '1er' : (string)(int)date('j', $t);
  $a = strtotime($p['from']); $b = strtotime($p['to']);
  $fin = $j($b) . ' ' . $m[(int)date('n', $b) - 1] . ' ' . date('Y', $b);
  if ($p['from'] === $p['to']) return 'du ' . $fin;
  if (date('Y-m', $a) === date('Y-m', $b)) return 'du ' . $j($a) . ' au ' . $fin;
  return 'du ' . $j($a) . ' ' . $m[(int)date('n', $a) - 1] . (date('Y', $a) !== date('Y', $b) ? ' ' . date('Y', $a) : '') . ' au ' . $fin;
}
function mail_message_default(array $d): string {
  // Client ventilé : on nomme les sous-marques puis le client entre parenthèses.
  $n = []; $grp = [];
  foreach ($d['brands'] as $b) { $sous = array_values(array_filter(array_map('trim', explode(',', (string)($b['sous_marques'] ?? ''))))); if ($sous) { $n = array_merge($n, $sous); $grp[] = $b['nom']; } else $n[] = $b['nom']; }
  $m = count($n) > 1 ? 'des marques ' . implode(', ', array_slice($n, 0, -1)) . ' et ' . end($n) : 'de la marque ' . ($n[0] ?? '');
  if ($grp) $m .= ' (' . implode(', ', $grp) . ')';
  elseif (!empty($d['p']['groupe'])) $m .= ' (' . $d['p']['groupe'] . ')';
  return "Bonjour,\n\nVeuillez trouver ci-joint le rapport $m pour la période " . period_short($d['p']) . ".\n\nJe vous souhaite une bonne lecture et vous souhaite une belle journée.\n\nCordialement.\n\n" . AG_NOM;
}
function brands_label(array $d): string {
  $b = $d['brands'] ? implode(', ', array_column($d['brands'], 'nom')) : 'Toutes marques';
  return !empty($d['p']['groupe']) ? $d['p']['groupe'] . ' (' . $b . ')' : $b;
}

/** Indicateurs chiffrés, par devise (toutes les zones sont en euros aujourd'hui). */
function stats(array $d): array {
  $s = ['jours' => count($d['rapports']), 'pdv' => [], 'anims' => [], 'dev' => [], 'store' => [], 'brand' => [], 'anim' => [], 'wday' => [], 'freq' => [],
    'conc' => [], 'conc_ca' => [], 'ruptures' => [], 'testeurs' => [], 'zones' => []];
  $wd = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
  foreach ($d['rapports'] as $r) {
    $dev = $r['devise'];
    $ca = 0; $hasCa = false;
    foreach ($r['lines'] as $l) {
      if ($l['ca'] !== null) { $ca += (float)$l['ca']; $hasCa = true; }
      $b = &$s['brand'][$dev][$l['marque']]; $b['ca'] = ($b['ca'] ?? 0) + (float)$l['ca']; $b['n'] = ($b['n'] ?? 0) + 1; unset($b);
      if (trim((string)$l['ruptures']) !== '') $s['ruptures'][] = ['r' => $r, 'l' => $l];
      if (trim((string)$l['testeurs']) !== '') $s['testeurs'][] = ['r' => $r, 'l' => $l];
    }
    $s['pdv'][$r['magasin']] = 1; $s['anims'][$r['animatrice']] = 1; $s['zones'][$r['zone']] = 1;
    $x = &$s['dev'][$dev]; $x['ca'] = ($x['ca'] ?? 0) + $ca; $x['n'] = ($x['n'] ?? 0) + 1; $x['nca'] = ($x['nca'] ?? 0) + ($hasCa ? 1 : 0);
    if (!isset($x['best']) || $ca > $x['best']['ca']) $x['best'] = ['ca' => $ca, 'r' => $r]; unset($x);
    foreach (['store' => (mb_stripos($r['magasin'], $r['ville']) !== false ? $r['magasin'] : $r['magasin'] . ' (' . $r['ville'] . ')'), 'anim' => $r['animatrice'], 'wday' => $wd[(int)date('w', strtotime($r['date_animation']))], 'freq' => $r['frequentation']] as $k => $key) {
      $y = &$s[$k][$dev][$key]; $y['ca'] = ($y['ca'] ?? 0) + $ca; $y['n'] = ($y['n'] ?? 0) + 1; unset($y);
    }
    $present = $r['concurrence'];
    if ($r['concurrence_autre']) $present[] = $r['concurrence_autre'];
    foreach ($present as $c) $s['conc'][$c] = ($s['conc'][$c] ?? 0) + 1;
    foreach (concurrents() as $c) { $k = in_array($c, $r['concurrence'], true) ? 'avec' : 'sans'; $z = &$s['conc_ca'][$dev][$c][$k]; $z['ca'] = ($z['ca'] ?? 0) + $ca; $z['n'] = ($z['n'] ?? 0) + 1; unset($z); }
  }
  arsort($s['conc']);
  foreach (['store', 'brand', 'anim'] as $k) foreach ($s[$k] as $dev => $rows) { uasort($rows, fn($a, $b) => $b['ca'] <=> $a['ca']); $s[$k][$dev] = $rows; }
  $s['pdv'] = count($s['pdv']); $s['anims'] = count($s['anims']); $s['zones'] = array_keys($s['zones']);
  return $s;
}

function prev_params(array $p): array {
  $days = (strtotime($p['to']) - strtotime($p['from'])) / 86400 + 1;
  return ['from' => date('Y-m-d', strtotime($p['from'] . " -$days days")), 'to' => date('Y-m-d', strtotime($p['from'] . ' -1 day'))] + $p;
}

// ---------- Mise en page commune ----------
function doc_css(): string {
  return '@page{margin:20mm 15mm 18mm 15mm}
  body{font-family:"DejaVu Sans",sans-serif;font-size:9.5pt;color:' . C_ESP . ';line-height:1.45}
  .serif{font-family:"DejaVu Serif",serif}
  h1{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:24pt;margin:0 0 4pt;color:' . C_ESP . '}
  h2{font-family:"DejaVu Serif",serif;font-weight:normal;font-size:15pt;margin:18pt 0 6pt;color:' . C_ESP . ';border-bottom:1.2pt solid ' . ag_couleur() . ';padding-bottom:3pt}
  h3{font-size:10.5pt;margin:12pt 0 4pt;color:' . ag_couleur() . ';text-transform:uppercase;letter-spacing:.6pt}
  .muted{color:' . C_MUTED . '} .small{font-size:8.5pt}
  table{border-collapse:collapse;width:100%}
  .grid td,.grid th{border-bottom:.6pt solid ' . C_LINE . ';padding:4pt 5pt;text-align:left;vertical-align:top}
  .grid th{background:' . C_ESP . ';color:#fff;font-weight:bold;font-size:8.5pt}
  .grid tr:nth-child(even) td{background:#FAF7F3}
  .num{text-align:right;white-space:nowrap}
  .kpi td{width:25%;background:' . C_SAND . ';padding:8pt 9pt;border:3pt solid #fff;vertical-align:top}
  .kpi .n{font-family:"DejaVu Serif",serif;font-size:17pt;color:' . C_ESP . '}
  .kpi .l{font-size:7.5pt;color:' . C_MUTED . ';text-transform:uppercase;letter-spacing:.5pt}
  .band{background:' . C_ESP . ';color:#fff;padding:9pt 12pt;margin:14pt 0 8pt}
  .band td{color:#fff;vertical-align:middle}
  .card{border:.8pt solid ' . C_LINE . ';padding:8pt 10pt;margin:0 0 8pt;page-break-inside:avoid}
  .label{font-size:7.5pt;text-transform:uppercase;letter-spacing:.5pt;color:' . ag_couleur() . ';font-weight:bold}
  .pill{background:' . C_SAND . ';color:' . C_ESP . ';padding:1pt 6pt;font-size:8pt}
  .photos td{width:33%;padding:3pt;vertical-align:top}
  .photos img{width:100%;border:.6pt solid ' . C_LINE . '}
  .barbg{background:' . C_SAND . ';height:8pt;width:100%}
  .bar{background:' . ag_couleur() . ';height:8pt}
  .quote{border-left:2pt solid ' . ag_couleur() . ';padding:2pt 0 2pt 8pt;margin:4pt 0}
  #footer{position:fixed;bottom:-12mm;left:0;right:0;font-size:7.5pt;color:' . C_MUTED . ';border-top:.6pt solid ' . C_LINE . ';padding-top:3pt}
  #footer .pg:after{content:counter(page)}
  .cover-logo{height:20mm}
  .brandlogo{max-height:14mm;max-width:40mm}';
}
function img_src(string $rel): string { return $rel; } // chemins relatifs au dossier de l'application (PDF et aperçu)
function txt($s): string { return nl2br(h(trim((string)$s))); }
function footer(string $title): string {
  return '<div id="footer"><table><tr><td>' . AG_NOM . ' · ' . h($title) . '</td><td style="text-align:right">Page <span class="pg"></span></td></tr></table></div>';
}
function cover(array $d, string $title, string $subtitle): string {
  $logos = '';
  foreach ($d['brands'] as $b) $logos .= $b['logo'] ? '<td style="padding:0 10pt 0 0"><img class="brandlogo" src="' . img_src('uploads/' . $b['logo']) . '"></td>' : '<td style="padding:0 12pt 0 0" class="serif" ><span style="font-size:13pt">' . h($b['nom']) . '</span></td>';
  $zones = secteur_label((string)$d['p']['zone']);
  return '<table><tr><td><img class="cover-logo" src="' . img_src(ag_logo()) . '"></td>
    <td style="text-align:right" class="muted small">Édité le ' . date_fr(date('Y-m-d'), true) . '</td></tr></table>
    <div style="height:3pt;background:' . ag_couleur() . ';margin:8pt 0 16pt"></div>
    <div class="label">' . h($subtitle) . '</div><h1>' . h($title) . '</h1>
    <div style="font-size:11pt">' . h(brands_label($d)) . ' · ' . h($zones) . '</div>
    <div class="muted">' . h(ucfirst(period_label($d['p']))) . '</div>
    ' . ($logos ? '<table style="width:auto;margin-top:12pt"><tr>' . $logos . '</tr></table>' : '');
}
function kpi_row(array $cells): string {
  $h = '<table class="kpi"><tr>';
  foreach ($cells as [$n, $l]) $h .= '<td><div class="n">' . $n . '</div><div class="l">' . h($l) . '</div></td>';
  return $h . '</tr></table>';
}
function ca_total_label(array $s): string {
  if (!$s['dev']) return '0';
  return implode(' + ', array_map(fn($dev, $x) => money($x['ca'], $dev), array_keys($s['dev']), $s['dev']));
}
function ca_moy_label(array $s): string {
  if (!$s['dev']) return '0';
  return implode(' / ', array_map(fn($dev, $x) => money($x['nca'] ? $x['ca'] / $x['nca'] : 0, $dev), array_keys($s['dev']), $s['dev']));
}
function ventil_html(array $l, string $dev): string {
  if (empty($l['parts'])) return '';
  return '<div style="font-size:8pt;margin-top:2px">' . implode(' · ', array_map(fn($p) => h($p['nom']) . ' ' . ($p['ca'] !== null ? money($p['ca'], $dev) : '–'), $l['parts'])) . '</div>';
}
function photos_grid(array $ph): string {
  if (!$ph) return '';
  $h = '<table class="photos">';
  foreach (array_chunk($ph, 3) as $row) {
    $h .= '<tr>';
    foreach ($row as $p) $h .= '<td><img src="' . img_src('uploads/' . $p['fichier'] . '_t.jpg') . '"></td>';
    for ($i = count($row); $i < 3; $i++) $h .= '<td></td>';
    $h .= '</tr>';
  }
  return $h . '</table>';
}

// ---------- Compte rendu qualitatif ----------
/** Recommandations proposées d'après les comptes rendus : point de départ que l'agence relit et modifie avant l'envoi. */
function reco_auto(array $d): string {
  if (!$d['rapports']) return ''; $s = stats($d); $r = [];
  foreach ($s['store'] as $dev => $rows) { if (count($rows) >= 2) { $k = array_keys($rows); $best = $rows[$k[0]]; $low = $rows[end($k)];
    if ($best['n'] && $best['ca'] > 0) $r[] = 'Capitaliser sur ' . $k[0] . ' (meilleur CA moyen par journée : ' . money($best['ca'] / $best['n'], $dev) . ') : y maintenir ou renforcer la présence.';
    if ($low['n'] && $best['ca'] > 0 && $low['ca'] / max(1, $low['n']) < 0.5 * $best['ca'] / max(1, $best['n'])) $r[] = 'Revoir l\'intérêt de ' . end($k) . ' (CA moyen deux fois plus faible) : jours, horaires ou emplacement de l\'animation.'; } }
  foreach ($s['wday'] as $dev => $rows) { $m = []; foreach ($rows as $j => $x) if ($x['n']) $m[$j] = $x['ca'] / $x['n']; if (count($m) >= 2) { arsort($m); $r[] = 'Le ' . mb_strtolower(array_key_first($m)) . ' ressort comme le meilleur jour : à privilégier dans le prochain planning.'; } }
  if ($n = count($s['ruptures'])) $r[] = $n . ' rupture' . ($n > 1 ? 's' : '') . ' de produits signalée' . ($n > 1 ? 's' : '') . ' : prévoir un réassort avant les prochaines journées.';
  if ($n = count($s['testeurs'])) $r[] = 'Testeurs manquants sur ' . $n . ' journée' . ($n > 1 ? 's' : '') . ' : en prévoir pour les prochaines animations.';
  if ($s['conc']) { $c = array_key_first($s['conc']); $r[] = 'Concurrence la plus présente : ' . $c . ' (' . round(100 * $s['conc'][$c] / max(1, $s['jours'])) . ' % des journées) : adapter l\'argumentaire.'; }
  $f = 0; foreach ($d['rapports'] as $x) if ($x['frequentation'] === 'Mauvaise') $f++;
  if ($f && $f >= $s['jours'] / 3) $r[] = 'Fréquentation faible sur ' . $f . ' journée' . ($f > 1 ? 's' : '') . ' : caler les animations sur les temps forts commerciaux.';
  return implode("\n", $r);
}
function render_cr(array $d): string {
  $s = stats($d);
  $title = "Rapport d'animation";
  $h = '<html><head><meta charset="utf-8"><style>' . doc_css() . '</style></head><body>' . footer($title . ' · ' . brands_label($d));
  $h .= cover($d, $title, AG_NOM);
  $h .= '<h2>Synthèse</h2>' . kpi_row([[$s['jours'], "journées d'animation"], [$s['pdv'], 'points de vente'], [ca_total_label($s), 'CA réalisé'], [ca_moy_label($s), 'CA moyen par journée']]);
  if (!$d['rapports']) return $h . '<p class="muted">Aucune journée d\'animation sur cette période pour cette sélection.</p></body></html>';
  $reco = trim((string)($d['p']['reco'] ?? '')); if ($reco === '') $reco = reco_auto($d);
  if ($reco !== '' && $reco !== '-') $h .= '<h2>Recommandations</h2><ul>' . implode('', array_map(fn($x) => '<li style="margin-bottom:4pt">' . h(ltrim($x, "-•  ")) . '</li>', array_filter(array_map('trim', explode("\n", $reco))))) . '</ul>';

  // Une ligne par journée
  $h .= '<h2>Journées d\'animation</h2><table class="grid"><tr><th>Date</th><th>Point de vente</th><th>Intervenant(e)</th><th>Marque(s)</th><th>Fréquentation</th><th class="num">CA</th></tr>';
  foreach ($d['rapports'] as $r) {
    $ca = array_sum(array_map(fn($l) => (float)$l['ca'], $r['lines']));
    $h .= '<tr><td>' . date_fr($r['date_animation']) . '</td><td>' . h($r['magasin']) . '<br><span class="muted small">' . h($r['ville'] . ' · ' . $r['zone']) . '</span></td><td>' . h($r['animatrice']) . '</td><td>'
      . h(implode(', ', array_column($r['lines'], 'marque'))) . '</td><td>' . h($r['frequentation']) . '</td><td class="num">' . money($ca, $r['devise']) . '</td></tr>';
  }
  $h .= '</table>';

  // Par marque
  foreach ($d['brands'] as $b) {
    $h .= '<div class="band" style="page-break-before:always"><table><tr><td><span class="serif" style="font-size:16pt">' . h($b['nom']) . '</span><br><span style="font-size:8.5pt">Compte rendu par marque · ' . h(period_label($d['p'])) . '</span></td>'
      . ($b['logo'] ? '<td style="text-align:right;background:#fff;width:46mm;padding:4pt"><img class="brandlogo" src="' . img_src('uploads/' . $b['logo']) . '"></td>' : '') . '</tr></table></div>';
    foreach ($d['rapports'] as $r) foreach ($r['lines'] as $l) {
      if ((int)$l['marque_id'] !== (int)$b['id']) continue;
      $h .= '<div class="card"><table><tr><td><b>' . h($r['magasin']) . '</b> <span class="muted">· ' . h($r['ville']) . '</span><br><span class="muted small">' . h(ucfirst(date_fr($r['date_animation'], true))) . ' · ' . h($r['animatrice']) . ' · fréquentation ' . h(mb_strtolower($r['frequentation'])) . '</span></td>'
        . '<td class="num" style="width:35mm"><div class="label">CA réalisé</div><span class="serif" style="font-size:14pt">' . ($l['ca'] !== null ? money($l['ca'], $r['devise']) : '<span class="muted">non renseigné</span>') . '</span>'
        . ventil_html($l, $r['devise'])
        . (($l['objectif'] ?? null) !== null && (float)$l['objectif'] > 0 ? '<div style="font-size:8pt;margin-top:2px">Objectif ' . money($l['objectif'], $r['devise']) . ($l['ca'] !== null ? ' · ' . ((float)$l['ca'] >= (float)$l['objectif'] ? '+' : '') . round(((float)$l['ca'] / (float)$l['objectif'] - 1) * 100) . ' %' : '') . '</div>' : '') . '</td></tr></table>';
      foreach (['reactions' => 'Réactions des clients sur les nouveautés', 'ruptures' => 'Ruptures de produits', 'testeurs' => 'Testeurs manquants'] as $k => $lab)
        if (trim((string)$l[$k]) !== '') $h .= '<div class="label" style="margin-top:6pt">' . $lab . '</div><div class="quote">' . txt($l[$k]) . '</div>';
      $h .= photos_grid($l['photos']) . '</div>';
    }
  }

  // Le terrain : point de vente
  $h .= '<h2 style="page-break-before:always">Observations terrain</h2>';
  if ($s['conc']) {
    $h .= '<h3>Concurrence présente avec un(e) intervenant(e)</h3><table class="grid"><tr><th>Marque concurrente</th><th class="num">Journées observées</th><th class="num">Part des journées</th></tr>';
    foreach ($s['conc'] as $c => $n) $h .= '<tr><td>' . h($c) . '</td><td class="num">' . $n . '</td><td class="num">' . round(100 * $n / max(1, $s['jours'])) . ' %</td></tr>';
    $h .= '</table>';
  }
  foreach (['offres' => 'Offres en cours sur les points de vente', 'staff' => 'Relations avec le personnel et la direction', 'faits' => 'Faits marquants'] as $k => $lab) {
    $items = array_filter($d['rapports'], fn($r) => trim((string)$r[$k]) !== '');
    if (!$items) continue;
    $h .= '<h3>' . $lab . '</h3>';
    foreach ($items as $r) $h .= '<div class="quote"><span class="small muted">' . date_fr($r['date_animation']) . ' · ' . h($r['magasin']) . '</span><br>' . txt($r[$k]) . '</div>';
  }
  $ph = []; foreach ($d['rapports'] as $r) foreach ($r['photos'] as $p) $ph[] = $p;
  if ($ph) $h .= '<h3>Nos intervenants en point de vente</h3>' . photos_grid($ph);
  return $h . '</body></html>';
}

// ---------- Analyse des ventes ----------
function bars(array $rows, string $dev, string $label, bool $avg = false): string {
  if (!$rows) return '';
  $max = max(array_map(fn($x) => $avg ? $x['ca'] / max(1, $x['n']) : $x['ca'], $rows)) ?: 1;
  $h = '<table class="grid"><tr><th style="width:38%">' . h($label) . '</th><th class="num" style="width:10%">Journées</th><th class="num" style="width:16%">' . ($avg ? 'CA moyen' : 'CA') . '</th><th style="width:36%"></th></tr>';
  foreach ($rows as $k => $x) {
    $v = $avg ? $x['ca'] / max(1, $x['n']) : $x['ca']; $w = max(1, (int)round(100 * $v / $max));
    $h .= '<tr><td>' . h($k) . '</td><td class="num">' . $x['n'] . '</td><td class="num">' . money($v, $dev) . '</td><td style="vertical-align:middle"><div class="barbg"><div class="bar" style="width:' . $w . '%"></div></div></td></tr>';
  }
  return $h . '</table>';
}
function sect(string $title, string $body, string $tag = 'h2'): string {
  return $body === '' ? '' : '<div style="page-break-inside:avoid"><' . $tag . '>' . h($title) . '</' . $tag . '>' . $body . '</div>';
}
function key_points(array $s, array $sp): array {
  $pts = [];
  foreach ($s['dev'] as $dev => $x) {
    $prev = $sp['dev'][$dev]['ca'] ?? 0;
    if ($prev > 0) { $ev = ($x['ca'] - $prev) / $prev * 100; $pts[] = 'CA en ' . ($ev >= 0 ? 'hausse' : 'baisse') . ' de ' . abs(round($ev)) . ' % par rapport à la période précédente de même durée (' . money($x['ca'], $dev) . ' contre ' . money($prev, $dev) . ').'; }
    $st = $s['store'][$dev] ?? [];
    if (count($st) > 1) {
      $avg = []; foreach ($st as $k => $y) if ($y['n'] > 0) $avg[$k] = $y['ca'] / $y['n'];
      arsort($avg); $best = array_key_first($avg); $worst = array_key_last($avg);
      $pts[] = 'Meilleur point de vente en moyenne par journée : ' . $best . ' (' . money($avg[$best], $dev) . '). Le plus faible : ' . $worst . ' (' . money($avg[$worst], $dev) . ').';
    }
    $wd = $s['wday'][$dev] ?? [];
    if (count($wd) > 1) { $a = []; foreach ($wd as $k => $y) $a[$k] = $y['ca'] / $y['n']; arsort($a); $k = array_key_first($a); $pts[] = 'Jour le plus porteur : le ' . mb_strtolower($k) . ', avec ' . money($a[$k], $dev) . ' en moyenne par journée.'; }
    $fq = $s['freq'][$dev] ?? [];
    if (isset($fq['Excellente'], $fq['Mauvaise']) || isset($fq['Bonne'], $fq['Moyenne'])) {
      $hi = $fq['Excellente'] ?? $fq['Bonne']; $lo = $fq['Mauvaise'] ?? $fq['Moyenne'];
      $pts[] = 'La fréquentation pèse sur les ventes : ' . money($hi['ca'] / $hi['n'], $dev) . ' en moyenne les jours de forte affluence, contre ' . money($lo['ca'] / $lo['n'], $dev) . ' les jours calmes.';
    }
    if (!empty($x['best']['r'])) $pts[] = 'Meilleure journée : ' . money($x['best']['ca'], $dev) . ' le ' . date_fr($x['best']['r']['date_animation']) . ' chez ' . $x['best']['r']['magasin'] . '.';
  }
  if ($s['conc']) { $c = array_key_first($s['conc']); $pts[] = 'Concurrence la plus présente : ' . $c . ', animée sur ' . round(100 * $s['conc'][$c] / max(1, $s['jours'])) . ' % des journées.'; }
  if ($s['ruptures']) $pts[] = count($s['ruptures']) . ' signalement(s) de rupture de produits sur la période : le détail figure en fin d\'analyse.';
  if ($s['testeurs']) $pts[] = count($s['testeurs']) . ' signalement(s) de testeurs manquants.';
  return $pts;
}
function ai_synthesis(array $d, array $s, array $pts): string {
  if (ANTHROPIC_API_KEY === '' || !$d['rapports'] || !function_exists('curl_init')) return '';
  $quali = [];
  foreach ($d['rapports'] as $r) foreach ($r['lines'] as $l)
    $quali[] = ['date' => $r['date_animation'], 'magasin' => $r['magasin'], 'marque' => $l['marque'], 'ca' => $l['ca'], 'devise' => $r['devise'], 'frequentation' => $r['frequentation'],
      'reactions' => $l['reactions'], 'ruptures' => $l['ruptures'], 'testeurs' => $l['testeurs'], 'concurrence' => $r['concurrence'], 'offres' => $r['offres'], 'faits' => $r['faits']];
  $prompt = "Tu es directeur commercial d'une agence d'animation commerciale pour marques de parfumerie et de beauté de luxe. "
    . "Rédige en français une synthèse d'analyse des ventes de 3 à 4 courts paragraphes, à destination du client (la marque) : tendance des ventes, facteurs explicatifs observés sur le terrain (fréquentation, concurrence, ruptures, testeurs, offres, réactions clients), puis 3 recommandations concrètes. "
    . "Ton professionnel, factuel, sans exagération, sans tiret cadratin, sans titre, sans liste à puces, sans inventer de chiffres absents des données. Marques : " . brands_label($d) . ". Période : " . period_label($d['p']) . ".\n\n"
    . "Points clés calculés :\n- " . implode("\n- ", $pts) . "\n\nDonnées par journée (JSON) :\n" . mb_substr(json_encode($quali, JSON_UNESCAPED_UNICODE), 0, 60000);
  $prompt = ia_anonymiser($prompt, array_column($d['rapports'], 'animatrice'));
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
    CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS => json_encode(['model' => ANTHROPIC_MODEL, 'max_tokens' => 1200, 'messages' => [['role' => 'user', 'content' => $prompt]]])]);
  $res = json_decode((string)curl_exec($ch), true); curl_close($ch);
  return trim((string)($res['content'][0]['text'] ?? ''));
}
function render_analyse(array $d, bool $withAi = true): string {
  $s = stats($d); $pp = prev_params($d['p']); $sp = stats(collect($pp));
  $pts = key_points($s, $sp);
  $title = 'Analyse des ventes';
  $h = '<html><head><meta charset="utf-8"><style>' . doc_css() . '</style></head><body>' . footer($title . ' · ' . brands_label($d));
  $h .= cover($d, $title, AG_NOM);
  $evo = [];
  foreach ($s['dev'] as $dev => $x) { $prev = $sp['dev'][$dev]['ca'] ?? 0; $evo[] = $prev > 0 ? (($x['ca'] >= $prev ? '+' : '') . round(($x['ca'] - $prev) / $prev * 100) . ' %') : 'n.c.'; }
  $h .= '<h2>Chiffres clés</h2>' . kpi_row([[ca_total_label($s), 'CA réalisé'], [ca_moy_label($s), 'CA moyen par journée'], [$s['jours'] . ' <span style="font-size:10pt" class="muted">(' . $sp['jours'] . ')</span>', 'journées (période préc.)'], [$evo ? implode(' / ', $evo) : 'n.c.', 'évolution du CA']]);
  $h .= '<p class="small muted">Période précédente de comparaison : ' . h(period_label($pp)) . '.</p>';
  if (!$d['rapports']) return $h . '<p class="muted">Aucune donnée de vente sur cette période.</p></body></html>';
  if ($pts) { $h .= '<h2>Ce qu\'il faut retenir</h2>'; foreach ($pts as $pt) $h .= '<div class="quote">' . h($pt) . '</div>'; }
  if ($withAi && ($ai = ai_synthesis($d, $s, $pts)) !== '') $h .= '<h2>Lecture et recommandations</h2>' . implode('', array_map(fn($para) => '<p style="text-align:justify">' . h($para) . '</p>', array_filter(preg_split("/\n\s*\n/", $ai))));
  foreach ($s['dev'] as $dev => $x) {
    $sfx = count($s['dev']) > 1 ? ' (' . (DEVISE_SYMBOLE[$dev] ?? $dev) . ')' : '';
    $h .= '<div style="page-break-before:always"></div>' . sect('Ventes par point de vente' . $sfx, bars($s['store'][$dev] ?? [], $dev, 'Point de vente'));
    $h .= sect('CA moyen par journée et par point de vente', bars($s['store'][$dev] ?? [], $dev, 'Point de vente', true), 'h3');
    if (count($s['brand'][$dev] ?? []) > 1) $h .= sect('Ventes par marque' . $sfx, bars($s['brand'][$dev], $dev, 'Marque'));
    $h .= sect('Ventes par intervenant(e)' . $sfx, bars($s['anim'][$dev] ?? [], $dev, 'Intervenant(e)', true));
    $order = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    $wd = array_filter(array_replace(array_flip($order), $s['wday'][$dev] ?? []), 'is_array');
    $h .= sect('Jour de la semaine' . $sfx, bars($wd, $dev, 'Jour', true));
    $fq = array_filter(array_replace(array_flip(FREQUENTATION), $s['freq'][$dev] ?? []), 'is_array');
    $h .= sect('Fréquentation et ventes' . $sfx, bars($fq, $dev, 'Fréquentation du point de vente', true));
    $cc = [];
    foreach ($s['conc_ca'][$dev] ?? [] as $c => $z) if (($z['avec']['n'] ?? 0) >= 2 && ($z['sans']['n'] ?? 0) >= 2) $cc[$c] = $z;
    if ($cc) {
      $h .= '<h2>Effet de la concurrence' . $sfx . '</h2><p class="small muted">CA moyen par journée selon qu\'un(e) intervenant(e) de la marque concurrente était présente ou non (au moins deux journées dans chaque cas).</p><table class="grid"><tr><th>Concurrent</th><th class="num">Avec (journées)</th><th class="num">Sans (journées)</th><th class="num">Écart</th></tr>';
      foreach ($cc as $c => $z) { $a = $z['avec']['ca'] / $z['avec']['n']; $b = $z['sans']['ca'] / $z['sans']['n'];
        $h .= '<tr><td>' . h($c) . '</td><td class="num">' . money($a, $dev) . ' (' . $z['avec']['n'] . ')</td><td class="num">' . money($b, $dev) . ' (' . $z['sans']['n'] . ')</td><td class="num">' . ($b > 0 ? (($a >= $b ? '+' : '') . round(($a - $b) / $b * 100) . ' %') : 'n.c.') . '</td></tr>'; }
      $h .= '</table>';
    }
  }
  foreach (['ruptures' => 'Ruptures de produits signalées', 'testeurs' => 'Testeurs manquants signalés'] as $k => $lab) {
    if (!$s[$k]) continue;
    $h .= '<h2>' . $lab . '</h2><table class="grid"><tr><th style="width:16%">Date</th><th style="width:26%">Point de vente</th><th style="width:16%">Marque</th><th>Détail</th></tr>';
    foreach ($s[$k] as $x) $h .= '<tr><td>' . date_fr($x['r']['date_animation']) . '</td><td>' . h($x['r']['magasin']) . '</td><td>' . h($x['l']['marque']) . '</td><td>' . txt($x['l'][$k]) . '</td></tr>';
    $h .= '</table>';
  }
  return $h . '</body></html>';
}

// ---------- PDF et e-mail ----------
function make_pdf(string $html): string {
  $o = new Options();
  $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('isHtml5ParserEnabled', true);
  $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
  return $pdf->output();
}
function render_mail(array $d, bool $withAnalyse, string $message = '', int $nPh = 0, bool $withPdf = true, bool $withXlsx = true): string {
  $pj = [];
  if ($withPdf) $pj[] = 'le rapport d\'animation (PDF)';
  if ($withXlsx) $pj[] = 'le tableau de reporting (Excel)';
  if ($withAnalyse) $pj[] = 'l\'analyse des ventes (PDF)';
  if ($nPh) $pj[] = $nPh . ' photo' . ($nPh > 1 ? 's' : '') . ($nPh > 10 ? ' (fichier ZIP)' : '');
  $pjTxt = $pj ? 'En pièces jointes : ' . (count($pj) > 1 ? implode(', ', array_slice($pj, 0, -1)) . ' et ' . end($pj) : $pj[0]) . '.' : '';
  $s = stats($d);
  $rows = '';
  foreach ([["Journées d'animation", $s['jours']], ['Points de vente', $s['pdv']], ['CA réalisé', ca_total_label($s)], ['CA moyen par journée', ca_moy_label($s)]] as [$l, $v])
    $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid ' . C_LINE . ';color:' . C_MUTED . ';font-size:13px">' . h($l) . '</td><td style="padding:8px 0;border-bottom:1px solid ' . C_LINE . ';text-align:right;font-size:15px;font-family:Georgia,serif">' . h($v) . '</td></tr>';
  return '<!DOCTYPE html><html><body style="margin:0;background:' . C_SAND . ';font-family:Arial,Helvetica,sans-serif;color:' . C_ESP . '">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:' . C_SAND . '"><tr><td align="center" style="padding:24px 12px">
  <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff">
   <tr><td style="padding:22px 28px;border-bottom:3px solid ' . ag_couleur() . '"><img src="' . h(BASE_URL) . '/' . ag_logo() . '" alt="' . h(AG_NOM) . '" height="46" style="display:block"></td></tr>
   <tr><td style="padding:24px 28px 8px;font-size:15px;line-height:1.6;color:' . C_ESP . '">' . nl2br(h($message !== '' ? $message : mail_message_default($d))) . '</td></tr>
   <tr><td style="padding:16px 28px 0"></td></tr>
   <tr><td style="background:' . C_ESP . ';color:#ffffff;padding:22px 28px"><div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#E9C9B5">Rapport d\'animation</div>
     <div style="font-family:Georgia,serif;font-size:24px;margin-top:4px">' . h(brands_label($d)) . '</div>
     <div style="font-size:13px;margin-top:6px;color:#E8E1D9">' . h(ucfirst(period_label($d['p']))) . ' · ' . h(secteur_label((string)$d['p']['zone'])) . '</div></td></tr>
   <tr><td style="padding:22px 28px"><table width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table>
     ' . ($pjTxt ? '<p style="font-size:12px;line-height:1.6;margin:16px 0 0;color:' . C_MUTED . '">' . h($pjTxt) . '</p>' : '') . '</td></tr>
   <tr><td style="padding:16px 28px;background:' . C_SAND . ';font-size:11px;color:' . C_MUTED . '">' . h(mail_pied()) . '</td></tr>
  </table></td></tr></table></body></html>';
}
/** Pied des e-mails : nom et adresse de l'agence (Réglages). */
function mail_pied(): string {
  $adr = ''; try { if (!function_exists('g_reg')) require_once __DIR__ . '/gestion_lib.php'; $adr = trim(preg_replace('/\s*\n\s*/', ', ', (string)(g_reg()['adresse'] ?? ''))); } catch (Throwable $e) {}
  return AG_NOM . ($adr !== '' ? ' · ' . $adr : '');
}
/** Photos de la sélection, à plat, avec un nom de fichier parlant. */
function report_photo_list(array $d): array {
  $out = [];
  foreach ($d['rapports'] as $r) {
    $base = $r['date_animation'] . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $r['magasin'])) . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', explode(' ', $r['animatrice'])[0]));
    foreach ($r['photos'] as $ph) $out[] = ['id' => (int)$ph['id'], 'fichier' => $ph['fichier'], 'nom' => $base . '_selfie', 'type' => 'Selfie', 'r' => $r];
    foreach ($r['lines'] as $l) foreach ($l['photos'] as $ph) $out[] = ['id' => (int)$ph['id'], 'fichier' => $ph['fichier'], 'nom' => $base . '_rayon-' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $l['marque'])), 'type' => 'Rayon ' . $l['marque'], 'r' => $r];
  }
  $seen = [];
  foreach ($out as &$o) { $n = $o['nom']; $seen[$n] = ($seen[$n] ?? 0) + 1; $o['nom'] = $n . ($seen[$n] > 1 ? '-' . $seen[$n] : '') . '.jpg'; }
  return $out;
}
function photos_zip(array $list): string {
  require_once __DIR__ . '/xlsx_lib.php';
  $files = []; foreach ($list as $ph) { $f = upload_dir() . '/' . $ph['fichier'] . '.jpg'; if (is_file($f)) $files[$ph['nom']] = file_get_contents($f); }
  return zip_build($files);
}

/** E-mail HTML avec pièces jointes [nom, contenu, type]. */
function mail_send(string $to, string $subject, string $html, array $files = [], string $cc = ''): bool {
  if (function_exists('demo_actif') && demo_actif() && empty($GLOBALS['DEMO_MAIL_OK'])) return true; // démo : aucun e-mail de l'appli ne part (seuls les accès à la démo)
  $b = 'amaya' . bin2hex(random_bytes(12));
  $body = "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html));
  foreach ($files as $fl) {
    [$name, $data] = $fl; $type = $fl[2] ?? 'application/pdf';
    // Noms avec accents (Génipa, Galléria) : version ASCII + version UTF-8 (RFC 2231), lue par Outlook, Gmail et Apple Mail.
    $ascii = str_replace('"', '', iconv('UTF-8', 'ASCII//TRANSLIT', $name)); $enc = "UTF-8''" . rawurlencode($name);
    $body .= "--$b\r\nContent-Type: $type; name=\"$ascii\"; name*=$enc\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$ascii\"; filename*=$enc\r\n\r\n" . chunk_split(base64_encode($data));
  }
  $body .= "--$b--";
  $headers = 'From: =?UTF-8?B?' . base64_encode(MAIL_FROM_NAME) . '?= <' . MAIL_FROM . ">\r\nReply-To: " . MAIL_TO_DEFAULT . ($cc !== '' ? "\r\nCc: " . $cc : '') . "\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$b\"";
  if (defined('MAIL_SMTP_HOST') && MAIL_SMTP_HOST !== '') {
    require_once __DIR__ . '/smtp_lib.php';
    try { smtp_envoyer(array_filter(array_merge(explode(',', $to), explode(',', $cc)), fn($x) => trim($x) !== ''), "To: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\nDate: " . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . '@' . (substr(strrchr(MAIL_FROM, '@') ?: '@amaya', 1)) . ">\r\n" . $headers, $body); smtp_noter(''); return true; }
    catch (Throwable $e) { error_log('Amaya SMTP : ' . $e->getMessage()); smtp_noter($e->getMessage()); return false; }
  }
  return mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers, '-f' . MAIL_FROM);
}

/** Un compte rendu seul, mis en page soigneusement (PDF pour l'intervenant(e)). */
function render_single(array $r): string {
  $l = $r['lines'] ? array_values($r['lines'])[0] : null;
  $all = []; foreach ($r['lines'] as $x) foreach ($x['photos'] as $p) $all[] = $p;
  $selfie = $r['photos'];
  $dev = $r['devise'];
  $css = doc_css() . '
  .hero{background:' . C_ESP . ';color:#fff;padding:16pt 18pt 14pt}
  .hero td{color:#fff;vertical-align:middle}
  .hero .t{font-family:"DejaVu Serif",serif;font-size:21pt;line-height:1.15}
  .hero .s{font-size:9pt;color:#E8DCCF;margin-top:4pt}
  .hero .k{font-size:7pt;letter-spacing:1.2pt;text-transform:uppercase;color:#E9C9B5}
  .meta td{padding:7pt 9pt;background:' . C_SAND . ';border:2.5pt solid #fff;vertical-align:top;width:25%}
  .meta .l{font-size:7pt;text-transform:uppercase;letter-spacing:.6pt;color:' . C_MUTED . '}
  .meta .v{font-size:10pt;margin-top:2pt}
  .big td{padding:10pt 12pt;border:2.5pt solid #fff;background:#FBF8F4;vertical-align:top}
  .big .n{font-family:"DejaVu Serif",serif;font-size:15pt;white-space:nowrap}
  .big .l{font-size:7pt;text-transform:uppercase;letter-spacing:.6pt;color:' . C_MUTED . '}
  .sec{margin-top:13pt;page-break-inside:avoid}
  .sec .h{font-size:7.5pt;text-transform:uppercase;letter-spacing:.8pt;color:' . ag_couleur() . ';font-weight:bold;border-bottom:.6pt solid ' . C_LINE . ';padding-bottom:3pt;margin-bottom:5pt}
  .chip{display:inline-block;background:' . C_SAND . ';padding:2pt 7pt;margin:0 3pt 3pt 0;font-size:8.5pt}
  .empty{color:' . C_MUTED . ';font-style:italic}';
  $h = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . footer('Compte rendu d\'animation · ' . $r['animatrice']);
  $h .= '<table><tr><td><img src="' . img_src(ag_logo()) . '" style="height:15mm"></td><td style="text-align:right" class="muted small">Envoyé le ' . date_fr(substr($r['created_at'], 0, 10), true) . '</td></tr></table>';
  $h .= '<div style="height:2.5pt;background:' . ag_couleur() . ';margin:8pt 0 0"></div>';
  $h .= '<div class="hero"><table><tr><td><div class="k">Compte rendu d\'animation</div><div class="t">' . h($l ? $l['marque'] : '') . '</div>'
    . ($l && $l['detail'] ? '<div class="s">' . h($l['detail']) . '</div>' : '') . '<div class="s">' . h(ucfirst(date_fr($r['date_animation'], true))) . ' · ' . h($r['magasin']) . ', ' . h($r['ville']) . '</div></td>'
    . ($l && $l['logo'] ? '<td style="width:42mm;text-align:right"><div style="background:#fff;padding:5pt;display:inline-block"><img src="' . img_src('uploads/' . $l['logo']) . '" style="max-height:13mm;max-width:36mm"></div></td>' : '') . '</tr></table></div>';
  $h .= '<table class="meta"><tr><td><div class="l">Intervenant(e)</div><div class="v">' . h($r['animatrice']) . '</div></td><td><div class="l">Zone</div><div class="v">' . h($r['zone']) . '</div></td>'
    . '<td><div class="l">Point de vente</div><div class="v">' . h($r['magasin']) . '</div></td><td><div class="l">Fréquentation</div><div class="v">' . h($r['frequentation']) . '</div></td></tr></table>';
  if ($l) {
    $cells = [];
    if (!empty($l['parts'])) foreach ($l['parts'] as $p) $cells[] = [$p['ca'] !== null ? money($p['ca'], $dev) : '–', 'CA ' . $p['nom'] . ($p['objectif'] !== null && (float)$p['objectif'] > 0 ? ' · objectif ' . money($p['objectif'], $dev) . ($p['ca'] !== null ? ' · ' . ((float)$p['ca'] >= (float)$p['objectif'] ? '+' : '') . round(((float)$p['ca'] / (float)$p['objectif'] - 1) * 100) . ' %' : '') : '')];
    $cells[] = [$l['ca'] !== null ? money($l['ca'], $dev) : '–', !empty($l['parts']) ? 'CA total' : 'CA réalisé'];
    if ($l['objectif'] !== null && (float)$l['objectif'] > 0) {
      $pc = $l['ca'] !== null ? round(((float)$l['ca'] / (float)$l['objectif'] - 1) * 100) : null;
      $cells[] = [money($l['objectif'], $dev), 'Objectif du jour']; if ($pc !== null) $cells[] = [($pc >= 0 ? '+' : '') . $pc . ' %', 'Atteinte'];
    }
    $h .= '<table class="big" style="margin-top:8pt"><tr>' . implode('', array_map(fn($c) => '<td><div class="n">' . h($c[0]) . '</div><div class="l">' . h($c[1]) . '</div></td>', $cells)) . '</tr></table>';
    foreach (['reactions' => 'Réactions des clientes sur les nouveautés', 'ruptures' => 'Ruptures de produits', 'testeurs' => 'Testeurs manquants'] as $k => $lab)
      $h .= '<div class="sec"><div class="h">' . $lab . '</div>' . (trim((string)$l[$k]) !== '' ? txt($l[$k]) : '<span class="empty">Rien à signaler</span>') . '</div>';
  }
  $conc = array_merge($r['concurrence'], $r['concurrence_autre'] !== '' ? [$r['concurrence_autre']] : []);
  $h .= '<div class="sec"><div class="h">Intervenants concurrent(e)s présentes</div>' . ($conc ? implode('', array_map(fn($c) => '<span class="chip">' . h($c) . '</span>', $conc)) : '<span class="empty">Aucune</span>') . '</div>';
  foreach (['offres' => 'Offres en cours sur le point de vente', 'faits' => 'Faits marquants', 'staff' => 'Personnel et direction'] as $k => $lab)
    $h .= '<div class="sec"><div class="h">' . $lab . '</div>' . (trim((string)$r[$k]) !== '' ? txt($r[$k]) : '<span class="empty">Rien à signaler</span>') . '</div>';
  if ($selfie || $all) $h .= '<div class="sec" style="page-break-inside:avoid"><div class="h">Photos</div>' . photos_grid(array_merge($selfie, $all)) . '</div>';
  return $h . '</body></html>';
}

function send_single(int $id, string $to, string $prenom): array {
  $st = db()->prepare("SELECT date_animation FROM rapports WHERE id = ?"); $st->execute([$id]); $date = $st->fetchColumn();
  $d = collect(['from' => $date, 'to' => $date, 'marques' => [], 'zone' => '', 'photos' => null, 'rids' => [$id], 'groupe' => '']);
  if (!$d['rapports']) throw new RuntimeException('Compte rendu introuvable.');
  $r = $d['rapports'][0]; $l = $r['lines'] ? array_values($r['lines'])[0] : ['marque' => ''];
  $name = 'Compte-rendu_' . $date . '_' . preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $r['magasin'])) . '.pdf';
  $html = '<!DOCTYPE html><html><body style="margin:0;background:' . C_SAND . ';font-family:Arial,Helvetica,sans-serif;color:' . C_ESP . '"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
    <table width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff"><tr><td style="padding:20px 26px;border-bottom:3px solid ' . ag_couleur() . '"><img src="' . h(BASE_URL) . '/' . ag_logo() . '" height="42" alt="' . h(AG_NOM) . '"></td></tr>
    <tr><td style="padding:22px 26px;font-size:15px;line-height:1.6">Bonjour ' . h($prenom) . ',<br><br>Vous trouverez ci-joint votre compte rendu d\'animation ' . h($l['marque']) . ' du ' . h(date_fr($date, true)) . ' chez ' . h($r['magasin']) . '.<br><br>Merci pour votre travail.<br><br>' . AG_NOM . '</td></tr></table></td></tr></table></body></html>';
  $ok = mail_send($to, 'Votre compte rendu du ' . date_fr($date) . ' · ' . $r['magasin'], $html, [[$name, make_pdf(render_single($r))]]);
  if (!$ok) throw new RuntimeException('L\'e-mail n\'a pas pu partir. Réessayez plus tard.');
  return ['ok' => true, 'message' => 'Compte rendu envoyé à ' . $to . '.'];
}

function send_report(array $p, string $to, bool $withAnalyse, bool $ia = true, string $message = '', bool $withPhotos = true, bool $withPdf = true, bool $withXlsx = true): array {
  require_once __DIR__ . '/xlsx_lib.php';
  @set_time_limit(180);
  $d = collect($p);
  if (!$d['rapports']) throw new RuntimeException('Aucun compte rendu sur cette période pour cette sélection : rien à envoyer.');
  $slug = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', brands_label($d))) . '_' . $p['from'] . '_' . $p['to'];
  $files = [];
  if ($withPdf) $files[] = ['Rapport-animation_' . $slug . '.pdf', make_pdf(render_cr($d))];
  if ($withXlsx) foreach (xlsx_fichiers($d, $p['xfmt'], $p['xmag'], $slug) as [$n, $x]) $files[] = [$n, $x, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
  if ($withAnalyse) $files[] = ['Analyse-ventes_' . $slug . '.pdf', make_pdf(render_analyse($d, $ia))];
  $nPh = 0;
  if ($withPhotos) {
    $list = report_photo_list($d); $nPh = count($list);
    if ($nPh > 10) $files[] = ['Photos_' . $slug . '.zip', photos_zip($list), 'application/zip'];
    else foreach ($list as $ph) { $f = upload_dir() . '/' . $ph['fichier'] . '.jpg'; if (is_file($f)) $files[] = [$ph['nom'], file_get_contents($f), 'image/jpeg']; }
  }
  $size = array_sum(array_map(fn($f) => strlen($f[1]), $files));
  if ($size > 18 * 1048576) throw new RuntimeException('Les pièces jointes font ' . round($size / 1048576, 1) . ' Mo, trop lourd pour un e-mail (18 Mo maximum). Retirez des photos de la sélection.');
  $subject = 'Rapport d\'animation · ' . brands_label($d) . ' · ' . period_label($p);
  $ok = mail_send($to, $subject, render_mail($d, $withAnalyse, $message, $nPh, $withPdf, $withXlsx), $files);
  db()->prepare("INSERT INTO envois (created_at, date_debut, date_fin, marques, destinataire, statut, par) VALUES (?,?,?,?,?,?,?)")
    ->execute([now(), $p['from'], $p['to'], mb_substr(brands_label($d), 0, 400), $to, demo_actif() ? 'démo' : ($ok ? 'envoyé' : 'échec'), admin_nom()]);
  if (!$ok) throw new RuntimeException('Le serveur n\'a pas pu envoyer l\'e-mail. Vérifiez l\'adresse d\'expédition dans config.php.');
  if (demo_actif()) return ['ok' => true, 'message' => 'Démo : l\'e-mail n\'est pas réellement envoyé, mais l\'envoi est noté dans Envois. Téléchargez le PDF pour voir le rapport.'];
  return ['ok' => true, 'message' => 'E-mail envoyé à ' . (substr_count($to, ',') ? (substr_count($to, ',') + 1) . ' destinataires' : $to) . ' (' . count($files) . ' pièce' . (count($files) > 1 ? 's' : '') . ' jointe' . (count($files) > 1 ? 's' : '') . ', ' . round($size / 1048576, 1) . ' Mo).'];
}
