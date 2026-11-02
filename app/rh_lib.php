<?php
// Ressources humaines : règle des 500 heures en CIDD (proposition d'un contrat de travail intermittent à durée indéterminée)
// et documents de fin de contrat (certificat de travail, reçu pour solde de tout compte, récapitulatif pour l'attestation France Travail).
// Règle des 500 heures : article 12 de l'avenant du 13 février 2006 relatif à l'animation commerciale (CCN des prestataires de services du secteur tertiaire, IDCC 2098).
//  - 500 heures ou plus sur les 12 derniers mois, au titre de plusieurs interventions régulières dans l'entreprise ;
//  - proposition dans les 2 mois, par lettre recommandée avec AR ou remise en main propre contre décharge ;
//  - 15 jours calendaires pour répondre à compter de la première présentation, le silence vaut refus ;
//  - prise d'effet au plus tard dans le mois qui suit la fin du délai de 2 mois ;
//  - après un refus, pas de nouvelle demande avant une nouvelle période de 12 mois suivant la fin de la période de référence utilisée.
require_once __DIR__ . '/contrats_lib.php';

const RH_SEUIL = 500.0;
const RH_STATUTS = ['a_proposer' => 'à proposer', 'propose' => 'proposition remise', 'accepte' => 'acceptée (CDII)', 'refuse' => 'refusée', 'sans_reponse' => 'sans réponse (refus)'];
function rh_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; c_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS cdii (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, ref_debut DATE NOT NULL, ref_fin DATE NOT NULL, heures DECIMAL(8,2) NOT NULL DEFAULT 0,
    statut VARCHAR(12) NOT NULL DEFAULT 'propose', propose_le DATE NULL, mode VARCHAR(6) NOT NULL DEFAULT 'main', presente_le DATE NULL, reponse_le DATE NULL, volume DECIMAL(8,2) NOT NULL DEFAULT 0,
    taux DECIMAL(8,2) NOT NULL DEFAULT 0, prise_effet DATE NULL, params MEDIUMTEXT NULL, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (animatrice_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS contrats_solde (animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NOT NULL, net DECIMAL(10,2) NULL, le DATE NULL, par VARCHAR(120) NOT NULL DEFAULT '',
    PRIMARY KEY (animatrice_id, devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // Affiliation à la mutuelle et à la prévoyance de l'entreprise : décide de la mention de portabilité (art. L.911-8 CSS) sur le certificat de travail.
  if (!in_array('mutuelle', db()->query("SHOW COLUMNS FROM contrats_solde")->fetchAll(PDO::FETCH_COLUMN), true))
    db()->exec("ALTER TABLE contrats_solde ADD COLUMN mutuelle VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN prevoyance VARCHAR(10) NOT NULL DEFAULT ''");
}
/** Montant en toutes lettres (euros et centimes), pour le reçu pour solde de tout compte. */
function rh_lettres(float $v): string {
  $u = ['', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
  $d = ['', '', 'vingt', 'trente', 'quarante', 'cinquante', 'soixante', 'soixante', 'quatre-vingt', 'quatre-vingt'];
  $c99 = function (int $n) use ($u, $d): string { if ($n < 20) return $u[$n]; $t = intdiv($n, 10); $r = $n % 10;
    if ($t === 7 || $t === 9) { $x = $r + 10; return $d[$t] . ($t === 7 && $x === 11 ? '-et-' : '-') . $u[$x]; }
    if ($r === 0) return $d[$t] . ($t === 8 ? 's' : ''); return $d[$t] . ($r === 1 && $t !== 8 ? '-et-' : '-') . $u[$r]; };
  $c999 = function (int $n) use ($c99): string { $h = intdiv($n, 100); $r = $n % 100; $s = $h ? ($h > 1 ? $c99($h) . '-cent' . ($r === 0 ? 's' : '') : 'cent') : '';
    return trim($s . ($r ? ($s ? '-' : '') . $c99($r) : ''), '-'); };
  $mots = function (int $n) use ($c999): string { if ($n === 0) return 'zéro'; $o = []; $mi = intdiv($n, 1000000); $k = intdiv($n % 1000000, 1000); $r = $n % 1000;
    // Orthographe de 1990 : traits d'union partout ; « cent » et « vingt » restent invariables devant « mille ».
    if ($mi) $o[] = $c999($mi) . '-million' . ($mi > 1 ? 's' : ''); if ($k) $o[] = ($k > 1 ? preg_replace('/(cent|vingt)s$/', '$1', $c999($k)) . '-' : '') . 'mille'; if ($r) $o[] = $c999($r); return ltrim(implode('-', $o), '-'); };
  $e = (int)floor($v + 1e-6); $ct = (int)round(($v - $e) * 100);
  return $mots($e) . ($e >= 1000000 && $e % 1000000 === 0 ? ' d\'euros' : ' euro' . ($e > 1 ? 's' : '')) . ($ct ? ' et ' . $mots($ct) . ' centime' . ($ct > 1 ? 's' : '') : '');
}
function rh_mois(string $d, int $n): string { return date('Y-m-d', strtotime("$d $n month")); }

/** Heures par jour en CIDD (pôle animation), d'après le planning : absences et créneaux annulés exclus. */
function rh_jours(?int $aid = null): array {
  $poles = []; foreach (db()->query("SELECT id, pole FROM g_docs WHERE type = 'devis'")->fetchAll() as $d) $poles[(int)$d['id']] = (string)$d['pole'] ?: 'anim';
  $abs = []; foreach (db()->query("SELECT pp.animatrice_id, m.date_mission, m.magasin FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id WHERE pp.statut_admin = 'absent'")->fetchAll() as $x) $abs[$x['animatrice_id'] . '|' . $x['date_mission'] . '|' . $x['magasin']] = 1;
  $st = db()->prepare("SELECT animatrice_id, devis_id, date_mission, debut, fin, pause, magasin FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission >= ?" . ($aid ? " AND animatrice_id = ?" : "") . " ORDER BY date_mission");
  $st->execute($aid ? [rh_mois(date('Y-m-d'), -26), $aid] : [rh_mois(date('Y-m-d'), -26)]); $J = [];
  foreach ($st->fetchAll() as $m) { if (($poles[(int)$m['devis_id']] ?? 'anim') !== 'anim' || isset($abs[$m['animatrice_id'] . '|' . $m['date_mission'] . '|' . $m['magasin']])) continue;
    $a = (int)$m['animatrice_id']; $d = $m['date_mission']; $J[$a][$d]['h'] = ($J[$a][$d]['h'] ?? 0) + r_min($m['debut'], $m['fin'], (int)$m['pause']) / 60; $J[$a][$d]['op'][(int)$m['devis_id']] = 1; }
  return $J;
}
/** Premier jour où les 12 mois glissants atteignent le seuil, en partant de $depuis (jours triés). Renvoie [date, heures] ou null. */
function rh_franchit(array $jours, string $depuis): ?array {
  $D = array_values(array_filter(array_keys($jours), fn($d) => $d >= $depuis)); sort($D); $i0 = 0; $s = 0.0;
  foreach ($D as $i => $d) { $s += $jours[$d]['h']; $lim = rh_mois($d, -12);
    while ($D[$i0] <= $lim) { $s -= $jours[$D[$i0]]['h']; $i0++; }
    if ($s >= RH_SEUIL - 0.001) return [$d, round($s, 2), $D[$i0]]; }
  return null;
}
function rh_somme(array $jours, string $du, string $au): array { $h = 0; $op = []; foreach ($jours as $d => $x) if ($d > $du && $d <= $au) { $h += $x['h']; $op += $x['op']; } return [round($h, 2), count($op)]; }

/** Suivi de chaque personne en CIDD : heures sur 12 mois, franchissement des 500 h (réel ou prévu au planning), échéances, proposition. */
function rh_suivi(?int $aid = null): array {
  rh_schema(); $today = date('Y-m-d'); $alerte = max(0, c_num((string)(g_reg()['cdii_alerte'] ?? '400'))) ?: 400; $out = [];
  $P = []; foreach (db()->query("SELECT * FROM cdii ORDER BY id")->fetchAll() as $p) $P[(int)$p['animatrice_id']][] = $p;
  foreach (rh_jours($aid) as $a => $jours) {
    ksort($jours); $hist = $P[$a] ?? [];
    // Propositions sans réponse 15 jours après la première présentation : refus (silence).
    foreach ($hist as &$p) if ($p['statut'] === 'propose' && $p['presente_le'] && date('Y-m-d', strtotime($p['presente_le'] . ' +15 day')) < $today) {
      db()->prepare("UPDATE cdii SET statut = 'sans_reponse' WHERE id = ?")->execute([$p['id']]); $p['statut'] = 'sans_reponse'; } unset($p);
    $last = $hist ? end($hist) : null;
    if ($last && $last['statut'] === 'accepte') { $out[] = ['animatrice_id' => $a, 'etat' => 'cdii', 'proposition' => rh_prop_pub($last)] + rh_ident($a); continue; }
    $depuis = $last && in_array($last['statut'], ['refuse', 'sans_reponse'], true) ? date('Y-m-d', strtotime($last['ref_fin'] . ' +1 day')) : '0000-00-00';
    $passe = array_filter($jours, fn($d) => $d <= $today, ARRAY_FILTER_USE_KEY);
    $de = rh_mois($today, -12); if ($depuis !== '0000-00-00') $de = max($de, date('Y-m-d', strtotime($depuis . ' -1 day'))); [$h12, $nop] = rh_somme($passe, $de, $today);
    $F = rh_franchit($passe, $depuis); $prev = $F ? null : rh_franchit($jours, $depuis);
    $x = ['animatrice_id' => $a, 'heures_12m' => $h12, 'operations' => $nop, 'seuil' => RH_SEUIL, 'alerte' => $alerte, 'depuis' => $depuis !== '0000-00-00' ? $depuis : null] + rh_ident($a);
    if ($last && $last['statut'] === 'propose') $x += ['etat' => 'propose', 'proposition' => rh_prop_pub($last)];
    elseif ($F) { [$d, $h, $d0] = $F; $lim = rh_mois($d, 2);
      $x += ['etat' => $lim < $today ? 'retard' : 'a_proposer', 'franchi_le' => $d, 'ref_debut' => $d0, 'heures_ref' => $h, 'limite' => $lim, 'prise_effet_max' => rh_mois($lim, 1), 'jours_restants' => (int)round((strtotime($lim) - strtotime($today)) / 86400)]; }
    elseif ($prev) $x += ['etat' => 'prevu', 'franchi_le' => $prev[0], 'heures_ref' => $prev[1]];
    elseif ($h12 >= $alerte) $x += ['etat' => 'approche'];
    else $x += ['etat' => 'sous'];
    if ($last && in_array($last['statut'], ['refuse', 'sans_reponse'], true)) $x['dernier_refus'] = rh_prop_pub($last);
    $out[] = $x;
  }
  $ord = ['retard' => 0, 'a_proposer' => 1, 'propose' => 2, 'prevu' => 3, 'approche' => 4, 'cdii' => 5, 'sous' => 6];
  usort($out, fn($a, $b) => [$ord[$a['etat']], -($a['heures_12m'] ?? 0)] <=> [$ord[$b['etat']], -($b['heures_12m'] ?? 0)]);
  return $out;
}
function rh_ident(int $aid): array { $s = c_salarie($aid); return ['nom' => trim($s['nom'] . ' ' . $s['prenom']), 'matricule' => $s['matricule']]; }
function rh_prop_pub(array $p): array {
  return ['id' => (int)$p['id'], 'statut' => $p['statut'], 'statut_txt' => RH_STATUTS[$p['statut']] ?? $p['statut'], 'propose_le' => $p['propose_le'], 'mode' => $p['mode'], 'presente_le' => $p['presente_le'],
    'reponse_avant' => $p['presente_le'] ? date('Y-m-d', strtotime($p['presente_le'] . ' +15 day')) : null, 'reponse_le' => $p['reponse_le'], 'volume' => (float)$p['volume'], 'taux' => (float)$p['taux'],
    'prise_effet' => $p['prise_effet'], 'ref_debut' => $p['ref_debut'], 'ref_fin' => $p['ref_fin'], 'heures' => (float)$p['heures']];
}

/** Ce que l'on propose : volume annuel, taux, rémunération, et comparaison du coût CIDD / CDII. */
function rh_calcul(int $aid, array $ref, array $in = []): array {
  $r = g_reg();
  $st = db()->prepare("SELECT params FROM contrats WHERE animatrice_id = ? ORDER BY id DESC"); $st->execute([$aid]); $taux = 0.0;
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pj) { $pp = json_decode((string)$pj, true) ?: []; if (($pp['pole'] ?? 'anim') === 'anim' && !empty($pp['taux'])) { $taux = (float)$pp['taux']; break; } }
  $taux = isset($in['taux']) && c_num((string)$in['taux']) > 0 ? c_num((string)$in['taux']) : ($taux ?: c_num($r['ct_taux']));
  $smic = g_smic($r); if ($taux < $smic) { if (isset($in['taux']) && c_num((string)$in['taux']) > 0) throw new RuntimeException('Taux horaire inférieur au SMIC (' . number_format($smic, 2, ',', ' ') . ' €) : impossible.'); $taux = $smic; }
  $h = (float)$ref['heures']; $vol = isset($in['volume']) && c_num((string)$in['volume']) > 0 ? round(c_num((string)$in['volume'])) : max(RH_SEUIL, floor($h));
  $lisse = ($in['remuneration'] ?? 'lissee') !== 'reelle';
  $base = round($vol * $taux, 2); $cp = round($base * 0.1, 2);
  $cidd = round($base * 1.1 * 1.1, 2); // CIDD : + indemnité de fin de contrat 10 %, puis congés payés 10 % sur le tout
  $cdii = round($base + $cp, 2);
  $lim = rh_mois($ref['franchi_le'], 2);
  $pe = !empty($in['prise_effet']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['prise_effet']) ? $in['prise_effet'] : min(rh_mois($lim, 1), rh_mois(date('Y-m-d'), 1));
  return ['volume' => $vol, 'heures_ref' => $h, 'taux' => $taux, 'annuel_brut' => $base, 'mensuel_lisse' => round($base / 12, 2), 'lisse' => $lisse, 'au_dela_max' => round($vol / 3, 2),
    'cout_cidd' => $cidd, 'cout_cdii' => $cdii, 'ecart' => round($cidd - $cdii, 2), 'prise_effet' => $pe, 'prise_effet_max' => rh_mois($lim, 1), 'limite' => $lim,
    'remuneration' => $lisse ? 'lissée sur l\'année, soit ' . c_eur(round($base / 12, 2)) . ' brut par mois pour la durée minimale, les heures effectuées au-delà étant payées le mois où elles sont réalisées' : 'versée chaque mois selon les heures effectivement travaillées',
    'periodes' => trim((string)($in['periodes'] ?? ''))];
}

/** Lettre de proposition (PDF) : texte de l'agence, champs remplis, coupon-réponse et décharge de remise en main propre. */
function rh_lettre_html(array $p): string {
  $r = g_reg(); $Q = json_decode((string)$p['params'], true) ?: []; $S = c_salarie((int)$p['animatrice_id']);
  $F = ($S['civilite'] ?? '') === 'Mme'; $M = ($S['civilite'] ?? '') === 'M.';
  $emploi = $Q['emploi'] ?? ($F ? 'animatrice commerciale' : ($M ? 'animateur commercial' : 'animateur(trice) commercial(e)'));
  $V = ['{Civilite}' => $F ? 'Madame' : ($M ? 'Monsieur' : 'Madame, Monsieur'), '{civilite}' => $F ? 'madame' : ($M ? 'monsieur' : 'madame, monsieur'), '{prenom}' => $S['prenom'], '{nom}' => $S['nom'],
    '{societe}' => $r['societe'] ?: AG_NOM, '{convention}' => $r['ct_convention'] ?: 'convention collective nationale des prestataires de services dans le domaine du secteur tertiaire',
    '{debut_ref}' => g_date($p['ref_debut']), '{fin_ref}' => g_date($p['ref_fin']), '{heures}' => c_h((float)$p['heures']), '{emploi}' => $emploi, '{volume}' => number_format((float)$p['volume'], 0, ',', ' '),
    '{taux}' => c_eur((float)$p['taux']), '{remuneration}' => (string)($Q['remuneration'] ?? ''), '{periodes}' => (string)($Q['periodes'] ?? '') ?: 'selon les opérations commerciales confiées par nos clients, communiquées à l\'avance',
    '{prise_effet}' => g_date((string)$p['prise_effet']), '{date}' => g_date((string)$p['propose_le']), '{lieu}' => $r['ct_lieu'], '{signataire}' => $r['ct_representant'], '{qualite}' => $r['ct_qualite']];
  $txt = strtr(trim((string)$r['cdii_modele']) ?: RH_CDII_DEFAUT, $V); $corps = '';
  foreach (preg_split('/\n\s*\n/', $txt) as $bloc) { $lignes = array_filter(array_map('trim', explode("\n", $bloc)), 'strlen');
    if ($lignes && count(array_filter($lignes, fn($l) => str_starts_with($l, '- '))) === count($lignes)) $corps .= '<ul>' . implode('', array_map(fn($l) => '<li>' . h(substr($l, 2)) . '</li>', $lignes)) . '</ul>';
    else foreach ($lignes as $l) $corps .= (str_starts_with($l, '- ') ? '<ul><li>' . h(substr($l, 2)) . '</li></ul>' : '<p' . (str_starts_with($l, 'Objet') ? ' class="obj"' : '') . '>' . h($l) . '</p>'); }
  $dest = '<b>' . h(trim(($S['civilite'] ? $S['civilite'] . ' ' : '') . $S['prenom'] . ' ' . $S['nom'])) . '</b><br>' . nl2br(h(trim($S['adresse'] . "\n" . $S['code_postal'] . ' ' . $S['ville'])));
  $mode = $p['mode'] === 'lrar' ? 'Lettre recommandée avec accusé de réception' : 'Remise en main propre contre décharge';
  $sig = '<p style="margin-top:22pt">Fait à ' . h($r['ct_lieu'] ?: '…') . ', le ' . h(g_date((string)$p['propose_le'])) . '</p><p><b>' . h($r['ct_representant'] ?: '…') . '</b>' . ($r['ct_qualite'] ? ', ' . h($r['ct_qualite']) : '') . '</p>' . c_sig_gerant();
  $coupon = '<div class="cp"><div class="ct">Réponse de ' . h($S['prenom'] . ' ' . $S['nom']) . ' · à retourner avant le ' . h($p['presente_le'] ? g_date(date('Y-m-d', strtotime($p['presente_le'] . ' +15 day'))) : '…') . '</div>'
    . '<p>☐ J\'accepte la proposition de contrat de travail intermittent à durée indéterminée ci-dessus.<br>☐ Je refuse cette proposition.</p><p>Date : ……………………… Signature :</p>'
    . ($p['mode'] !== 'lrar' ? '<div class="ct" style="margin-top:12pt">Décharge de remise en main propre</div><p>Je reconnais avoir reçu ce courrier en main propre le ……………………… Signature :</p>' : '') . '</div>';
  return rh_cadre('Proposition de CDI intermittent', '<div class="dest">' . $dest . '</div><div class="mode">' . h($mode) . '</div>' . $corps . $sig . $coupon);
}
/** Mise en page commune des documents RH (en-tête de l'agence, police de l'appli). */
function rh_cadre(string $titre, string $body): string {
  $r = g_reg(); $p = ag_couleur();
  $ff = ''; foreach (['' => ['normal', 'normal'], '-Bold' => ['bold', 'normal']] as $sx => [$w, $st]) if (is_file(__DIR__ . "/assets/fonts/Figtree$sx.ttf")) $ff .= "@font-face{font-family:'Figtree';font-weight:$w;font-style:$st;src:url('assets/fonts/Figtree$sx.ttf') format('truetype')}";
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:40px">' : '<b style="font-size:13pt">' . h($r['societe'] ?: AG_NOM) . '</b>';
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $ff . '@page{margin:18mm 18mm 20mm}body{font-family:' . ($ff ? "'Figtree'," : '') . "'DejaVu Sans',sans-serif;font-size:10pt;color:#1A1512;line-height:1.5}"
    . '.hd{display:table;width:100%;margin-bottom:16pt}.hd>div{display:table-cell;vertical-align:top}.ag{text-align:right;font-size:8.5pt;color:#857869}.dest{margin:6pt 0 14pt 55%}.mode{font-size:8.5pt;color:#857869;margin-bottom:10pt}'
    . 'h1{font-size:15pt;color:' . $p . ';margin:0 0 12pt}p{margin:0 0 7pt}ul{margin:0 0 8pt 14pt;padding:0}li{margin:0 0 2pt}.obj{font-weight:bold}.cp{margin-top:20pt;border:1px solid #E6DDD2;border-radius:6pt;padding:10pt 12pt}.ct{font-weight:bold;margin-bottom:6pt}'
    . 'table.t{width:100%;border-collapse:collapse;margin:6pt 0 10pt}table.t td,table.t th{border-bottom:1px solid #E6DDD2;padding:4pt 6pt;text-align:left}table.t .n{text-align:right}.mut{color:#857869;font-size:8.5pt}</style></head><body>'
    . '<div class="hd"><div>' . $logo . '</div><div class="ag">' . h($r['societe'] ?: AG_NOM) . '<br>' . nl2br(h((string)$r['adresse'])) . ($r['siret'] ? '<br>SIRET ' . h($r['siret']) : '') . '</div></div>'
    . (str_starts_with($body, '<div class="dest">') ? '' : '<h1>' . h($titre) . '</h1>') . $body . '</body></html>';
}
function rh_pdf(string $html): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(120);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('isHtml5ParserEnabled', true); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
}

/* ---------- Fin de contrat ---------- */
/** Données de fin d'un contrat (dernière version) : dates, emploi, heures, sommes brutes. */
function rh_fin(int $aid, int $devis): array {
  rh_schema(); $E = c_etat($aid, $devis); $last = $E['last']; if (!$last) throw new RuntimeException('Aucun contrat envoyé pour cette opération.');
  $c = c_row((int)$last['id']); $L = $c['lignes']; if (!$L) throw new RuntimeException('Contrat sans journée.');
  $P = $c['params']; $pole = $P['pole'] ?? 'anim'; $S = $E['salarie'] + $c['salarie'];
  $abs = pa_absences_rh($aid, $L[0]['date'], end($L)['date'], $devis); $L = array_values(array_filter($L, fn($l) => !isset($abs[$l['date'] . '|' . $l['magasin']])));
  $d = g_doc($devis); $Rg = rg_regles($pole); $taux = (float)$P['taux']; $h = 0; $brut = 0;
  foreach ($L as $l) { $J = rg_jour($l['date'], $l['debut'], $l['fin'], (int)$l['pause'], (string)$l['magasin'], (string)($d['zone'] ?? ''), $pole, $Rg); $h += $J['heures']; $brut += ($J['heures'] + $J['eq']) * $taux; }
  $brut = round($brut, 2); $preca = $pole === 'hot' ? 0.0 : round($brut * 0.1, 2); $cp = round(($brut + $preca) * 0.1, 2);
  $F = ($S['civilite'] ?? '') === 'Mme'; $M = ($S['civilite'] ?? '') === 'M.';
  $role = trim((string)($L[0]['role'] ?? '')); $emploi = $role ? rh_genre($role, $F, $M) : ($pole === 'hot' ? c_emploi_hot($L, $F, $M, (string)($P['poste'] ?? '')) : ($F ? 'Animatrice commerciale' : ($M ? 'Animateur commercial' : 'Animateur(trice) commercial(e)')));
  $sd = db()->prepare("SELECT net, le, mutuelle, prevoyance FROM contrats_solde WHERE animatrice_id = ? AND devis_id = ?"); $sd->execute([$aid, $devis]); $so = $sd->fetch() ?: ['net' => null, 'le' => null, 'mutuelle' => '', 'prevoyance' => ''];
  // Frais du contrat sur les journées réellement faites : repas, km (ou transports en commun), panier de nuit et tenue.
  $Tj = $P['trajets'] ?? null; $fr = ['repas' => 0.0, 'km' => 0.0, 'autres' => 0.0];
  if ($Tj) { $Fr = g_frais($pole); $pj = c_par_jour($L); $fr['repas'] = round(count(array_filter($pj, fn($x) => $x > $Fr['seuil'])) * (float)($Tj['repas'] ?? $Fr['repas']), 2);
    $jm = []; foreach ($L as $l) $jm[(string)$l['magasin']][$l['date']] = 1;
    if (($Tj['mode'] ?? '') === 'tc') $fr['km'] = round(count($pj) * (float)($Tj['tc_jour'] ?? 0), 2);
    else foreach ((array)($Tj['lignes'] ?? []) as $t) if (empty($t['circuit']) && $t['jour'] !== null) $fr['km'] += (float)$t['jour'] * count($jm[(string)$t['magasin']] ?? []);
    $fr['km'] = round($fr['km'], 2); $fr['autres'] = round(($Fr['panier'] > 0 ? g_jours_tardifs($L, $Fr['panier_apres']) * $Fr['panier'] : 0) + ($Fr['tenue'] > 0 ? count($pj) * $Fr['tenue'] : 0), 2); }
  $ent = c_entree($aid);
  return ['animatrice_id' => $aid, 'devis_id' => $devis, 'numero' => (int)$c['numero'], 'avenant' => (int)$c['avenant'], 'pole' => $pole, 'type' => $pole === 'hot' ? 'contrat à durée déterminée d\'usage' : 'contrat d\'intervention à durée déterminée',
    'du' => $L[0]['date'], 'au' => end($L)['date'], 'jours' => count(array_unique(array_column($L, 'date'))), 'heures' => round($h, 2), 'emploi' => $emploi, 'taux' => $taux,
    'brut' => $brut, 'preca' => $preca, 'cp' => $cp, 'total_brut' => round($brut + $preca + $cp, 2), 'salarie' => $S, 'premiere_entree' => $ent, 'net' => $so['net'] !== null ? (float)$so['net'] : null, 'solde_le' => $so['le'], 'mutuelle' => (string)$so['mutuelle'], 'prevoyance' => (string)$so['prevoyance'], 'frais' => $fr, 'tc' => ($Tj['mode'] ?? '') === 'tc',
    'motif' => $pole === 'hot' ? 'Fin de contrat à durée déterminée d\'usage' : 'Fin de contrat à durée déterminée (contrat d\'intervention)', 'client' => $P['client'] ?? '', 'operation' => $P['operation'] ?? ''];
}
function pa_absences_rh(int $aid, string $du, string $au, int $devis): array {
  $st = db()->prepare("SELECT m.date_mission, m.magasin FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id WHERE pp.statut_admin = 'absent' AND pp.animatrice_id = ? AND m.date_mission BETWEEN ? AND ? AND m.devis_id = ?");
  $st->execute([$aid, $du, $au, $devis]); $o = []; foreach ($st->fetchAll() as $x) $o[$x['date_mission'] . '|' . $x['magasin']] = true; return $o;
}
/** Intitulé de poste accordé : « Animateur(trice) commercial(e) » devient « Animatrice commerciale » ou « Animateur commercial ». */
function rh_genre(string $t, bool $F, bool $M): string {
  if (!$F && !$M) return $t;
  $t = preg_replace_callback('/(\p{L}+)\((trice|drice|sse|se|euse|ice|e|ne|le)\)/u', function ($m) use ($F) { $b = $m[1]; $x = $m[2];
    if (!$F) return $b;
    if ($x === 'trice') return preg_replace('/teur$/u', 'trice', $b); if ($x === 'drice') return preg_replace('/deur$/u', 'drice', $b);
    if ($x === 'se' || $x === 'euse') return preg_replace('/eur$/u', 'euse', $b); return $b . $x; }, $t);
  return $t;
}
function rh_nom(array $S): string { return trim(($S['civilite'] ? $S['civilite'] . ' ' : '') . $S['prenom'] . ' ' . $S['nom']); }
function rh_signe(array $r, string $le): string { return '<p style="margin-top:18pt">Fait à ' . h($r['ct_lieu'] ?: '…') . ', le ' . h(g_date($le)) . '</p><p><b>' . h($r['ct_representant'] ?: '…') . '</b>' . ($r['ct_qualite'] ? ', ' . h($r['ct_qualite']) : '') . ', pour ' . h($r['societe'] ?: AG_NOM) . '</p>' . c_sig_gerant(); }

/** Certificat de travail (art. L.1234-19 et D.1234-6 du Code du travail ; maintien des garanties santé et prévoyance, art. L.911-8 du Code de la sécurité sociale). */
function rh_certificat_html(array $X): string {
  $r = g_reg(); $S = $X['salarie']; $F = ($S['civilite'] ?? '') === 'Mme'; $e = $F ? 'e' : '';
  $naiss = $S['naissance_date'] ? ', né' . $e . ' le ' . g_date($S['naissance_date']) . ($S['naissance_lieu'] ? ' à ' . $S['naissance_lieu'] : '') : '';
  $adr = trim($S['adresse'] . ', ' . $S['code_postal'] . ' ' . $S['ville'], ', ');
  $b = '<p>Je soussigné(e) ' . h($r['ct_representant'] ?: '…') . ($r['ct_qualite'] ? ', ' . h($r['ct_qualite']) : '') . ' de la société ' . h($r['societe'] ?: AG_NOM) . ($r['siret'] ? ' (SIRET ' . h($r['siret']) . ')' : '') . ', dont le siège est situé ' . h(str_replace("\n", ', ', (string)$r['adresse'])) . ', certifie que :</p>'
    . '<p><b>' . h(rh_nom($S)) . '</b>' . h($naiss) . ($adr ? ', demeurant ' . h($adr) : '') . ($S['secu'] ? ', n° de sécurité sociale ' . h($S['secu']) : '') . ',</p>'
    . '<p>a été employé' . $e . ' dans notre entreprise du <b>' . h(g_date($X['du'])) . '</b> au <b>' . h(g_date($X['au'])) . '</b> inclus, en qualité de <b>' . h($X['emploi']) . '</b>, dans le cadre d\'un ' . h($X['type']) . ' (contrat n°' . $X['numero'] . ').</p>'
    . '<p>' . ($F ? 'Elle' : 'Il') . ' nous quitte libre de tout engagement.</p>'
    . (function () use ($X, $F, $e, $r) { $sa = $X['mutuelle'] === 'affilie'; $pr = $X['prevoyance'] === 'affilie';
        // Portabilité (art. L.911-8 CSS) : seulement pour les garanties auxquelles la personne était affiliée ; aucune mention si elle était dispensée de tout.
        if (!$sa && !$pr) return '';
        $g = $sa && $pr ? 'des garanties de frais de santé et de prévoyance' : ($sa ? 'des garanties de frais de santé' : 'des garanties de prévoyance');
        return '<p>' . ($F ? 'Elle' : 'Il') . ' peut bénéficier, à titre gratuit, du maintien ' . $g . ' auxquelles ' . ($F ? 'elle' : 'il') . ' était affilié' . $e . ' dans l\'entreprise, dans les conditions de l\'article L.911-8 du Code de la sécurité sociale, sous réserve d\'être indemnisé' . $e . ' par l\'assurance chômage'
          . ($r['ct_caisse'] ? '. Organisme : ' . h(str_replace("\n", ', ', (string)$r['ct_caisse'])) : '') . '.</p>'; })()
    . '<p>Ce certificat lui est remis pour servir et valoir ce que de droit.</p>' . rh_signe($r, max($X['au'], date('Y-m-d')));
  return rh_cadre('Certificat de travail', $b);
}
/** Reçu pour solde de tout compte (art. L.1234-20 : dénonçable dans les six mois qui suivent sa signature). */
function rh_solde_html(array $X): string {
  $r = g_reg(); $S = $X['salarie']; $F = ($S['civilite'] ?? '') === 'Mme';
  $net = $X['net'] !== null ? '<span style="white-space:nowrap">' . c_eur($X['net']) . '</span> (' . rh_lettres((float)$X['net']) . ')' : '……………… €'; $fr = $X['frais'] ?? ['repas' => 0, 'km' => 0, 'autres' => 0];
  $rows = '<tr><td>Salaire de base et majorations (' . c_h($X['heures']) . ' h)</td><td class="n">' . c_eur($X['brut']) . '</td></tr>'
    . ($X['pole'] === 'hot' ? '' : '<tr><td>Indemnité de fin de contrat (10 %)</td><td class="n">' . c_eur($X['preca']) . '</td></tr>')
    . '<tr><td>Indemnité compensatrice de congés payés (10 %)</td><td class="n">' . c_eur($X['cp']) . '</td></tr><tr><th>Total brut</th><th class="n">' . c_eur($X['total_brut']) . '</th></tr>'
    . (array_sum($fr) > 0 ? ($fr['repas'] ? '<tr><td>Indemnités de repas</td><td class="n">' . c_eur($fr['repas']) . '</td></tr>' : '') . ($fr['km'] ? '<tr><td>' . (!empty($X['tc']) ? 'Indemnités de transport' : 'Indemnités kilométriques') . '</td><td class="n">' . c_eur($fr['km']) . '</td></tr>' : '')
      . ($fr['autres'] ? '<tr><td>Panier de nuit et entretien de la tenue</td><td class="n">' . c_eur($fr['autres']) . '</td></tr>' : '') . '<tr><th>Frais remboursés (non soumis à cotisations)</th><th class="n">' . c_eur(array_sum($fr)) . '</th></tr>' : '');
  $b = '<p>Je soussigné' . ($F ? 'e' : '') . ' <b>' . h(rh_nom($S)) . '</b>, reconnais avoir reçu de la société ' . h($r['societe'] ?: AG_NOM) . ' la somme nette de <b>' . $net . '</b> pour solde de tout compte, en paiement des salaires, indemnités et accessoires dus au titre de mon '
    . h($X['type']) . ' n°' . $X['numero'] . ', exécuté du ' . h(g_date($X['du'])) . ' au ' . h(g_date($X['au'])) . '.</p>'
    . '<p>Détail des sommes (salaire et indemnités en brut ; heures supplémentaires éventuelles et cotisations : voir le bulletin de paie) :</p><table class="t">' . $rows . '</table>'
    . '<p>Ce reçu, établi en deux exemplaires dont un m\'est remis, peut être dénoncé dans les six mois qui suivent sa signature, délai au-delà duquel il devient libératoire pour l\'employeur pour les sommes qui y sont mentionnées (article L.1234-20 du Code du travail).</p>'
    . '<p style="margin-top:18pt">Fait à ' . h($r['ct_lieu'] ?: '…') . ', le ……………………</p><p>Signature ' . ($F ? 'de la salariée' : 'du salarié') . ', précédée de la mention « Pour solde de tout compte » :</p>';
  return rh_cadre('Reçu pour solde de tout compte', $b);
}
