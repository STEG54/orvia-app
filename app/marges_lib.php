<?php
// Marges par opération et par marque : CA HT facturé (ou devisé) moins la masse salariale chargée, les frais et les autres coûts.
// Au départ, simulation : salaires d'après les contrats (comme la préparation de paye) et charges patronales à un taux moyen réglable.
// Ensuite, ajustement avec le réel du TESE : brut et charges patronales de chaque personne pour le mois, répartis sur ses opérations au prorata.
require_once __DIR__ . '/paye_lib.php';

function m_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  db()->exec("CREATE TABLE IF NOT EXISTS marges_reel (mois CHAR(7) NOT NULL, animatrice_id INT UNSIGNED NOT NULL, brut DECIMAL(10,2) NOT NULL, charges DECIMAL(10,2) NOT NULL,
    par VARCHAR(120) NOT NULL DEFAULT '', modifie_le DATETIME NOT NULL, PRIMARY KEY (mois, animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!in_array('cotis', db()->query("SHOW COLUMNS FROM marges_reel")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE marges_reel ADD COLUMN cotis DECIMAL(10,2) NULL, ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'saisie'");
  db()->exec("CREATE TABLE IF NOT EXISTS marges_hors (mois CHAR(7) NOT NULL, nom VARCHAR(160) NOT NULL, cotis DECIMAL(10,2) NOT NULL, PRIMARY KEY (mois, nom)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  if (!in_array('type', db()->query("SHOW COLUMNS FROM marges_hors")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE marges_hors ADD COLUMN type VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN marque VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN libelle VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN brut DECIMAL(10,2) NULL, ADD COLUMN charges DECIMAL(10,2) NULL");
  if (!in_array('aid', db()->query("SHOW COLUMNS FROM marges_hors")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE marges_hors ADD COLUMN aid INT UNSIGNED NULL");
  // Noms du décompte TESE : correspondance avec une personne de la liste (prénom différent…), ou salarié permanent (rattaché à une marque, ou frais de structure).
  db()->exec("CREATE TABLE IF NOT EXISTS marges_salaries (nom_tese VARCHAR(160) PRIMARY KEY, type VARCHAR(10) NOT NULL, animatrice_id INT UNSIGNED NULL, marque VARCHAR(160) NOT NULL DEFAULT '',
    brut DECIMAL(10,2) NULL, libelle VARCHAR(160) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', modifie_le DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS marges_couts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, devis_id INT UNSIGNED NOT NULL, mois CHAR(7) NOT NULL, libelle VARCHAR(160) NOT NULL,
    montant DECIMAL(10,2) NOT NULL, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS marges_syntheses (mois CHAR(7) PRIMARY KEY, fichier VARCHAR(80) NOT NULL, nom VARCHAR(200) NOT NULL, mime VARCHAR(60) NOT NULL, par VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function m_mois(string $du, string $au): array { $out = []; for ($t = strtotime($du . '-01'); $t <= strtotime($au . '-01') && count($out) < 36; $t = strtotime('+1 month', $t)) $out[] = date('Y-m', $t); return $out; }
function m_pct(string $pole = 'anim'): float { $r = g_reg(); return c_num((string)($pole === 'hot' ? ($r['charges_pat_hot'] ?? $r['charges_pat']) : ($r['charges_pat'] ?? '6,7'))) / 100; }

/** Lignes de paye (simulées) des mois demandés, avec le réel du TESE quand il est saisi. */
function m_lignes(array $mois): array {
  m_schema(); $pct = m_pct(); $out = [];
  foreach ($mois as $m) {
    $st = db()->prepare("SELECT animatrice_id, brut, charges FROM marges_reel WHERE mois = ?"); $st->execute([$m]); $reel = []; foreach ($st->fetchAll() as $r) $reel[(int)$r['animatrice_id']] = $r;
    $L = pa_lignes($m); $parPers = [];
    foreach ($L as $x) { $k = pa_calc($x); $parPers[$x['animatrice_id']] = ($parPers[$x['animatrice_id']] ?? 0) + $k['total_brut']; }
    foreach ($L as $x) {
      $k = pa_calc($x); $aid = (int)$x['animatrice_id']; $tb = $k['total_brut']; $ch = round($tb * m_pct($x['pole'] ?? 'anim'), 2); $re = isset($reel[$aid]);
      if ($re) { $part = $parPers[$aid] > 0 ? $tb / $parPers[$aid] : 0; $tb = round((float)$reel[$aid]['brut'] * $part, 2); $ch = round((float)$reel[$aid]['charges'] * $part, 2); } // réel TESE réparti au prorata
      // Détail par journée : salaire, charges et frais répartis sur chaque journée (repas et trajet comptés une fois par jour).
      $J = []; $sal = array_sum(array_map(fn($l) => (float)($l['salaire'] ?? 0), $x['detail'])) ?: 1; $vu = []; $hj = c_par_jour($x['detail']);
      foreach ($x['detail'] as $l) { $p = (float)($l['salaire'] ?? 0) / $sal; $f = 0;
        if (!isset($vu[$l['date']])) { $vu[$l['date']] = 1; $f += ($hj[$l['date']] ?? 0) > 6 ? $x['repas'] : 0; $f += !empty($x['tc_jour']) ? (float)$x['tc_jour'] : ($l['km_ar'] !== null ? round($l['km_ar'] * $x['tkm'], 2) : 0); }
        $J[] = ['date' => $l['date'], 'magasin' => (string)$l['magasin'], 'heures' => (float)$l['heures'], 'brut' => round($tb * $p, 2), 'charges' => round($ch * $p, 2), 'frais' => $f]; }
      $out[] = ['mois' => $m, 'animatrice_id' => $aid, 'nom' => $x['nom'], 'devis_id' => (int)($x['devis_id'] ?? 0), 'jours' => $x['jours'], 'heures' => $x['heures'],
        'brut_sim' => $k['total_brut'], 'brut' => $tb, 'charges' => $ch, 'frais' => $k['frais'], 'reel' => $re, 'detail' => $J];
    }
  }
  return $out;
}

/** Marges par opération et par marque sur la période (mois entiers). */
function m_calc(string $du, string $au): array {
  m_schema(); $mois = m_mois($du, $au); $X = m_lignes($mois); $ops = [];
  foreach ($X as $x) { $o = &$ops[$x['devis_id']]; $o ??= ['salaires' => 0, 'charges' => 0, 'frais' => 0, 'heures' => 0, 'jours' => 0, 'pers' => [], 'n' => 0, 'reel' => 0];
    $o['salaires'] += $x['brut']; $o['charges'] += $x['charges']; $o['frais'] += $x['frais']; $o['heures'] += $x['heures']; $o['jours'] += $x['jours']; $o['pers'][$x['animatrice_id']] = $x['nom']; $o['n']++; if ($x['reel']) $o['reel']++; unset($o); }
  // Opérations acceptées sans personne sur la période mais avec des coûts saisis
  $in = implode(',', array_fill(0, count($mois), '?'));
  $st = db()->prepare("SELECT devis_id, SUM(montant) s FROM marges_couts WHERE mois IN ($in) GROUP BY devis_id"); $st->execute($mois); $autres = [];
  foreach ($st->fetchAll() as $r) { $autres[(int)$r['devis_id']] = (float)$r['s']; $ops[(int)$r['devis_id']] ??= ['salaires' => 0, 'charges' => 0, 'frais' => 0, 'heures' => 0, 'jours' => 0, 'pers' => [], 'n' => 0, 'reel' => 0]; }
  $out = [];
  foreach ($ops as $dv => $o) {
    $row = ['devis_id' => $dv, 'numero' => '', 'objet' => 'Hors opération (sans devis)', 'client' => '', 'marque' => 'Hors opération', 'ca_total' => 0, 'ca_source' => '', 'prorata' => 1, 'ca' => 0];
    if ($dv) { $d = g_doc($dv); if ($d) {
      $row['numero'] = (string)$d['numero']; $row['objet'] = (string)($d['objet'] ?: $d['numero']); $row['client'] = (string)($d['client']['raison'] ?? '');
      $mq = db()->prepare("SELECT m.nom FROM g_clients c JOIN marques m ON m.id = c.marque_id WHERE c.id = ?"); try { $mq->execute([(int)$d['client_id']]); $row['marque'] = (string)($mq->fetchColumn() ?: $row['client']); } catch (Throwable $e) { $row['marque'] = $row['client']; }
      $fa = db()->prepare("SELECT COALESCE(SUM(total_ht), 0), COUNT(*) FROM g_docs WHERE type = 'facture' AND devis_id = ? AND numero IS NOT NULL AND statut <> 'brouillon'"); $fa->execute([$dv]); [$fht, $fn] = $fa->fetch(PDO::FETCH_NUM);
      $row['ca_total'] = $fn ? (float)$fht : (float)$d['total_ht']; $row['ca_source'] = $fn ? 'facturé' : ($d['statut'] === 'accepte' ? 'devis accepté' : 'devis ' . $d['statut']);
      // Opération à cheval sur plusieurs périodes : CA au prorata des journées travaillées comprises dans la période.
      $tj = db()->prepare("SELECT COUNT(DISTINCT animatrice_id, date_mission) FROM missions m WHERE devis_id = ? AND animatrice_id IS NOT NULL AND statut <> 'annulee'" . p_present_sql()); $tj->execute([$dv]); $tot = (int)$tj->fetchColumn();
      // Devis pas encore facturé : les journées d'absence (non facturables) sont retirées du CA.
      if (!$fn) { $tj2 = db()->prepare("SELECT COUNT(DISTINCT animatrice_id, date_mission) FROM missions WHERE devis_id = ? AND animatrice_id IS NOT NULL AND statut <> 'annulee'"); $tj2->execute([$dv]); $tous = (int)$tj2->fetchColumn();
        if ($tous > $tot) { $row['ca_total'] = round($row['ca_total'] * $tot / $tous, 2); $row['ca_source'] .= ', absences déduites'; } }
      $row['prorata'] = $tot > 0 && $o['jours'] > 0 ? min(1, $o['jours'] / $tot) : ($o['jours'] ? 1 : 0);
      $row['ca'] = round($row['ca_total'] * $row['prorata'], 2);
    } }
    $row['autres'] = round($autres[$dv] ?? 0, 2);
    $row += ['salaires' => round($o['salaires'], 2), 'charges' => round($o['charges'], 2), 'frais' => round($o['frais'], 2), 'heures' => round($o['heures'], 2), 'jours' => $o['jours'], 'personnes' => array_values($o['pers']),
      'reel' => $o['n'] ? ($o['reel'] === $o['n'] ? 'reel' : ($o['reel'] ? 'partiel' : 'simule')) : 'simule'];
    $row['couts'] = round($row['salaires'] + $row['charges'] + $row['frais'] + $row['autres'], 2); $row['marge'] = round($row['ca'] - $row['couts'], 2);
    $row['taux'] = $row['ca'] > 0 ? round($row['marge'] / $row['ca'] * 100, 1) : null;
    $out[] = $row;
  }
  // Salariés permanents (hors missions) : rattachés à une marque, ou frais de structure. Coût = brut mensuel + charges patronales déduites du décompte TESE.
  $pm = db()->prepare("SELECT nom, type, marque, libelle, SUM(brut) brut, SUM(charges) charges, COUNT(*) n FROM marges_hors WHERE mois IN ($in) AND type IN ('permanent', 'structure') GROUP BY nom, type, marque, libelle"); $pm->execute($mois);
  foreach ($pm->fetchAll() as $p) { $c = round((float)$p['brut'] + (float)$p['charges'], 2);
    $out[] = ['devis_id' => 0, 'numero' => '', 'objet' => ($p['libelle'] ?: 'Salarié permanent') . ' · ' . $p['nom'] . ' (' . $p['n'] . ' mois)', 'client' => '', 'marque' => $p['type'] === 'structure' ? 'Frais de structure' : $p['marque'],
      'ca_total' => 0, 'ca_source' => 'salarié permanent', 'prorata' => 1, 'ca' => 0, 'autres' => 0, 'salaires' => round((float)$p['brut'], 2), 'charges' => round((float)$p['charges'], 2), 'frais' => 0, 'heures' => 0, 'jours' => 0,
      'personnes' => [$p['nom']], 'reel' => 'reel', 'couts' => $c, 'marge' => -$c, 'taux' => null, 'permanent' => true]; }
  usort($out, fn($a, $b) => [$a['marque'], $a['numero']] <=> [$b['marque'], $b['numero']]);
  $M = []; foreach ($out as $r) { $k = $r['marque']; $M[$k] ??= ['marque' => $k, 'ops' => 0, 'ca' => 0, 'couts' => 0, 'marge' => 0];
    $M[$k]['ops']++; $M[$k]['ca'] += $r['ca']; $M[$k]['couts'] += $r['couts']; $M[$k]['marge'] += $r['marge']; }
  foreach ($M as &$m) { $m['ca'] = round($m['ca'], 2); $m['couts'] = round($m['couts'], 2); $m['marge'] = round($m['marge'], 2); $m['taux'] = $m['ca'] > 0 ? round($m['marge'] / $m['ca'] * 100, 1) : null; } unset($m);
  usort($M, fn($a, $b) => $b['ca'] <=> $a['ca']);
  // Réel du TESE : personnes de chaque mois, avec le simulé et le réel saisi
  $pers = []; foreach ($X as $x) { $k = $x['mois'] . '|' . $x['animatrice_id']; $pers[$k] ??= ['mois' => $x['mois'], 'animatrice_id' => $x['animatrice_id'], 'nom' => $x['nom'], 'brut_sim' => 0, 'charges_sim' => 0];
    $pers[$k]['brut_sim'] += $x['brut_sim']; $pers[$k]['charges_sim'] = round($pers[$k]['charges_sim'] + $x['brut_sim'] * m_pct(), 2); }
  $st = db()->prepare("SELECT mois, animatrice_id, brut, charges, cotis, source FROM marges_reel WHERE mois IN ($in)"); $st->execute($mois);
  foreach ($st->fetchAll() as $r) { $k = $r['mois'] . '|' . $r['animatrice_id']; if (isset($pers[$k])) { $pers[$k]['brut'] = (float)$r['brut']; $pers[$k]['charges'] = (float)$r['charges']; $pers[$k]['cotis'] = $r['cotis'] === null ? null : (float)$r['cotis']; $pers[$k]['source'] = $r['source']; } }
  $hs = db()->prepare("SELECT mois, nom, cotis, aid FROM marges_hors WHERE mois IN ($in) AND type = '' ORDER BY mois, nom"); $hs->execute($mois); $hors = $hs->fetchAll();
  $sal = db()->query("SELECT s.*, CONCAT(UPPER(a.nom), ' ', a.prenom) AS personne FROM marges_salaries s LEFT JOIN animatrices a ON a.id = s.animatrice_id ORDER BY s.nom_tese")->fetchAll();
  $pers = array_values($pers); usort($pers, fn($a, $b) => [$a['mois'], mb_strtolower($a['nom'])] <=> [$b['mois'], mb_strtolower($b['nom'])]);
  $sy = db()->prepare("SELECT mois, nom, created_at, par FROM marges_syntheses WHERE mois IN ($in)"); $sy->execute($mois);
  $tot = ['ca' => 0, 'salaires' => 0, 'charges' => 0, 'frais' => 0, 'autres' => 0, 'couts' => 0, 'marge' => 0]; foreach ($out as $r) foreach ($tot as $k => $_) $tot[$k] += $r[$k];
  foreach ($tot as &$v) $v = round($v, 2); unset($v); $tot['taux'] = $tot['ca'] > 0 ? round($tot['marge'] / $tot['ca'] * 100, 1) : null;
  $ct = db()->prepare("SELECT id, devis_id, mois, libelle, montant FROM marges_couts WHERE mois IN ($in) ORDER BY id"); $ct->execute($mois);
  // Regroupements libres (client, marque, point de vente, intervenant(e), jour, mois) à partir du détail par journée.
  // CA d'une opération réparti également sur ses journées-personne ; autres coûts répartis sur les journées de l'opération dans la période.
  $meta = []; foreach ($out as $r) if ($r['devis_id'] && empty($r['permanent'])) $meta[$r['devis_id']] = $r;
  $pj = []; foreach (array_keys($meta) as $dv) { $q = db()->prepare("SELECT COUNT(DISTINCT animatrice_id, date_mission) FROM missions m WHERE devis_id = ? AND animatrice_id IS NOT NULL AND statut <> 'annulee'" . p_present_sql()); $q->execute([$dv]); $pj[$dv] = max(1, (int)$q->fetchColumn()); }
  $nbj = []; foreach ($X as $x) foreach ($x['detail'] as $l) $nbj[$x['devis_id']][$x['animatrice_id'] . '|' . $l['date']] = 1;
  $G = []; $vu = [];
  foreach ($X as $x) { $dv = $x['devis_id']; $mt = $meta[$dv] ?? null;
    foreach ($x['detail'] as $l) { $pd = $x['animatrice_id'] . '|' . $l['date']; $first = !isset($vu[$dv][$pd]); $vu[$dv][$pd] = 1;
      $ca = $first && $mt ? $mt['ca_total'] / $pj[$dv] : 0; $au = $first && $mt && !empty($nbj[$dv]) ? $mt['autres'] / count($nbj[$dv]) : 0;
      $cles = ['client' => $mt['client'] ?? 'Hors opération', 'marque' => $mt['marque'] ?? 'Hors opération', 'pdv' => $l['magasin'] ?: 'Point de vente à préciser', 'personne' => $x['nom'], 'jour' => $l['date'], 'mois' => substr($l['date'], 0, 7)];
      foreach ($cles as $dim => $k) { $g = &$G[$dim][$k]; $g ??= ['cle' => $k, 'jours' => [], 'heures' => 0, 'ca' => 0, 'salaires' => 0, 'charges' => 0, 'frais' => 0, 'autres' => 0, 'ops' => []];
        $g['jours'][$pd] = 1; $g['heures'] += $l['heures']; $g['ca'] += $ca; $g['salaires'] += $l['brut']; $g['charges'] += $l['charges']; $g['frais'] += $l['frais']; $g['autres'] += $au; if ($dv) $g['ops'][$dv] = 1; unset($g); } } }
  foreach ($out as $r) if (!empty($r['permanent'])) foreach (['client' => $r['marque'], 'marque' => $r['marque'], 'pdv' => 'Salariés permanents', 'personne' => $r['personnes'][0], 'jour' => 'Salariés permanents', 'mois' => 'Salariés permanents'] as $dim => $k) {
    $g = &$G[$dim][$k]; $g ??= ['cle' => $k, 'jours' => [], 'heures' => 0, 'ca' => 0, 'salaires' => 0, 'charges' => 0, 'frais' => 0, 'autres' => 0, 'ops' => []]; $g['salaires'] += $r['salaires']; $g['charges'] += $r['charges']; unset($g); }
  $groupes = [];
  foreach ($G as $dim => $rows) { foreach ($rows as $g) { $c = $g['salaires'] + $g['charges'] + $g['frais'] + $g['autres'];
      $groupes[$dim][] = ['cle' => $g['cle'], 'jours' => count($g['jours']), 'heures' => round($g['heures'], 2), 'ops' => count($g['ops']), 'ca' => round($g['ca'], 2), 'salaires' => round($g['salaires'], 2), 'charges' => round($g['charges'], 2),
        'frais' => round($g['frais'], 2), 'autres' => round($g['autres'], 2), 'couts' => round($c, 2), 'marge' => round($g['ca'] - $c, 2), 'taux' => $g['ca'] > 0 ? round(($g['ca'] - $c) / $g['ca'] * 100, 1) : null]; }
    usort($groupes[$dim], in_array($dim, ['jour', 'mois'], true) ? fn($a, $b) => strcmp($a['cle'], $b['cle']) : fn($a, $b) => $b['ca'] <=> $a['ca'] ?: strcmp($a['cle'], $b['cle'])); }
  return ['groupes' => $groupes, 'salaries' => $sal, 'hors' => $hors, 'taux_sal' => c_num((string)(g_reg()['cotis_sal'] ?? '21,8')), 'mois' => $mois, 'pct' => m_pct() * 100, 'ops' => $out, 'marques' => array_values($M), 'total' => $tot, 'personnes' => $pers, 'syntheses' => $sy->fetchAll(), 'couts' => $ct->fetchAll()];
}

/** Import du décompte de cotisations TESE (PDF) : cotisations Urssaf par personne et par mois.
 * Le décompte ne sépare pas part salariale et part patronale : charges patronales = cotisations − part salariale (taux moyen réglable, 21,8 % du brut par défaut).
 * Le brut est celui des contrats (simulé). Les salariés sans journée dans l'appli ce mois-là sont listés à part (hors opérations). */
function m_import_tese(string $pdf, bool $simuler): array {
  require_once __DIR__ . '/tese_lib.php'; m_schema();
  $D = tese_decompte($pdf); if (!$D['lignes']) throw new RuntimeException('Aucune ligne de cotisations reconnue : est-ce bien le « Décompte de cotisations » du TESE ?');
  $norm = fn($s) => trim(preg_replace('/[^A-Z]+/', ' ', strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT', (string)$s))));
  $tok = fn($s) => array_values(array_filter(explode(' ', $norm($s))));
  $A = db()->query("SELECT id, nom, prenom FROM animatrices WHERE nom NOT LIKE 'effacée%' AND nom NOT LIKE '%(fusionné avec%'")->fetchAll();
  $S = []; foreach (db()->query("SELECT * FROM marges_salaries")->fetchAll() as $r) $S[$norm($r['nom_tese'])] = $r;
  $trouve = function (string $nom) use ($A, $norm, $tok, $S) {
    $n = $norm($nom); if (($S[$n]['type'] ?? '') === 'alias' && $S[$n]['animatrice_id']) return (int)$S[$n]['animatrice_id']; $hit = array_filter($A, fn($a) => $norm($a['nom'] . ' ' . $a['prenom']) === $n || $norm($a['prenom'] . ' ' . $a['nom']) === $n);
    if (count($hit) !== 1) { $t = $tok($nom); sort($t); $hit = array_filter($A, function ($a) use ($t, $tok) { $u = $tok($a['nom'] . ' ' . $a['prenom']); sort($u); return $u === $t; }); }
    if (count($hit) !== 1) { $t = $tok($nom); $hit = array_filter($A, function ($a) use ($t, $tok) { $nm = $tok($a['nom']); $pr = $tok($a['prenom']); return $nm && $pr && !array_diff($nm, $t) && in_array($pr[0], $t, true); }); }
    return count($hit) === 1 ? (int)reset($hit)['id'] : 0;
  };
  // Cotisations par personne et par mois (plusieurs périodes d'emploi possibles)
  $P = []; $regul = [];
  foreach ($D['lignes'] as $l) { $aid = $trouve($l['nom']); $c = $l['urssaf'] + $l['tiers'];
    if ($l['regul']) { $regul[] = $l + ['aid' => $aid]; continue; }
    $k = $l['mois'] . '|' . ($aid ?: 'x:' . $l['nom']); $P[$k] ??= ['mois' => $l['mois'], 'aid' => $aid, 'nom' => $l['nom'], 'cotis' => 0]; $P[$k]['cotis'] += $c; }
  $mois = array_values(array_unique(array_column($P, 'mois'))); sort($mois);
  // Brut simulé de chaque personne sur le mois (contrats / planning)
  $brut = []; foreach ($mois as $m) foreach (pa_lignes($m) as $x) { $k = pa_calc($x); $brut[$m][$x['animatrice_id']] = ($brut[$m][$x['animatrice_id']] ?? 0) + $k['total_brut']; }
  $tsal = c_num((string)(g_reg()['cotis_sal'] ?? '21,8')) / 100;
  $ok = []; $hors = [];
  foreach ($P as $p) {
    $cf = $S[$norm($p['nom'])] ?? null;
    if (!$p['aid'] && $cf && in_array($cf['type'], ['permanent', 'structure'], true)) { // salarié permanent : brut mensuel connu, charges patronales déduites du décompte
      $b = (float)$cf['brut']; $hors[] = ['mois' => $p['mois'], 'nom' => $p['nom'], 'cotis' => round($p['cotis'], 2), 'type' => $cf['type'], 'marque' => $cf['type'] === 'permanent' ? $cf['marque'] : '', 'libelle' => $cf['libelle'],
        'brut' => round($b, 2), 'charges' => max(0, round($p['cotis'] - $tsal * $b, 2)), 'raison' => $cf['type'] === 'permanent' ? 'salarié permanent · ' . $cf['marque'] : 'frais de structure']; continue; }
    $b = $p['aid'] ? ($brut[$p['mois']][$p['aid']] ?? 0) : 0;
    if (!$p['aid'] || $b <= 0) { $hors[] = ['mois' => $p['mois'], 'nom' => $p['nom'], 'aid' => $p['aid'] ?: null, 'cotis' => round($p['cotis'], 2), 'raison' => $p['aid'] ? 'aucune journée dans l\'appli ce mois-ci' : 'personne non retrouvée dans l\'appli']; continue; }
    $ch = max(0, round($p['cotis'] - $tsal * $b, 2)); $ok[] = ['mois' => $p['mois'], 'aid' => $p['aid'], 'nom' => $p['nom'], 'cotis' => round($p['cotis'], 2), 'brut' => round($b, 2), 'charges' => $ch];
  }
  if (!$simuler) {
    $pdo = db();
    foreach ($ok as $o) $pdo->prepare("REPLACE INTO marges_reel (mois, animatrice_id, brut, charges, cotis, source, par, modifie_le) VALUES (?,?,?,?,?,'tese',?,?)")->execute([$o['mois'], $o['aid'], $o['brut'], $o['charges'], $o['cotis'], admin_nom(), now()]);
    foreach ($mois as $m) $pdo->prepare("DELETE FROM marges_hors WHERE mois = ?")->execute([$m]);
    foreach ($hors as $h) $pdo->prepare("INSERT INTO marges_hors (mois, nom, cotis, type, marque, libelle, brut, charges, aid) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE cotis = cotis + VALUES(cotis), charges = COALESCE(charges, 0) + COALESCE(VALUES(charges), 0)")
      ->execute([$h['mois'], $h['nom'], $h['cotis'], $h['type'] ?? '', $h['marque'] ?? '', $h['libelle'] ?? '', $h['brut'] ?? null, $h['charges'] ?? null, $h['aid'] ?? null]);
    // Régularisations des mois antérieurs : ajoutées aux cotisations déjà enregistrées pour ce mois-là
    foreach ($regul as $r) if ($r['aid']) $pdo->prepare("UPDATE marges_reel SET cotis = COALESCE(cotis, 0) + ?, charges = charges + ? WHERE mois = ? AND animatrice_id = ?")->execute([$r['urssaf'] + $r['tiers'], $r['urssaf'] + $r['tiers'], $r['mois'], $r['aid']]);
    // Le PDF est archivé (chiffré) comme synthèse TESE du mois principal
    if ($mois) { $m = end($mois); $name = bin2hex(random_bytes(16)) . '.bin';
      if (file_put_contents(fiche_dir() . '/' . $name, amy_chiffre($pdf)) !== false) { $o = $pdo->prepare("SELECT fichier FROM marges_syntheses WHERE mois = ?"); $o->execute([$m]); if ($f = $o->fetchColumn()) @unlink(fiche_dir() . '/' . basename($f));
        $pdo->prepare("REPLACE INTO marges_syntheses (mois, fichier, nom, mime, par, created_at) VALUES (?,?,?,?,?,?)")->execute([$m, $name, 'Decompte_cotisations_TESE_' . $m . '.pdf', 'application/pdf', admin_nom(), now()]); } }
  }
  $somme = round(array_sum(array_map(fn($l) => $l['urssaf'] + $l['tiers'], $D['lignes'])), 2);
  return ['simule' => $simuler, 'mois' => $mois, 'ok' => $ok, 'hors' => $hors, 'regul' => array_map(fn($r) => ['nom' => $r['nom'], 'mois' => $r['mois'], 'cotis' => round($r['urssaf'] + $r['tiers'], 2), 'applique' => (bool)$r['aid']], $regul),
    'total_doc' => $D['total'], 'total_lu' => $somme, 'controle' => $D['total'] === null || abs($D['total'] - $somme) < 0.05, 'taux_sal' => $tsal * 100];
}

/** Réapplique les décomptes TESE déjà déposés (après une correspondance ou un salarié permanent ajouté). */
function m_reappliquer(): int {
  m_schema(); $n = 0;
  foreach (db()->query("SELECT mois, fichier FROM marges_syntheses WHERE nom LIKE 'Decompte_cotisations_TESE%' ORDER BY mois")->fetchAll() as $s) {
    $pdf = amy_dechiffre((string)@file_get_contents(fiche_dir() . '/' . basename($s['fichier']))); if ($pdf === null) continue;
    try { m_import_tese($pdf, false); $n++; } catch (Throwable $e) { error_log('Réapplication TESE ' . $s['mois'] . ' : ' . $e->getMessage()); }
  }
  return $n;
}

/** Classeur des marges : par marque, par opération. */
function m_xlsx(array $R, string $titre): string {
  require_once __DIR__ . '/xlsx_lib.php';
  $h = fn($cols) => array_map(fn($c) => ['v' => $c, 's' => 'y_head'], $cols);
  $rows = [1 => [0 => ['v' => 'Marges par marque · ' . $titre, 's' => 'y_title']], 3 => $h(['Marque', 'Opérations', 'CA HT', 'Coûts', 'Marge', 'Marge %'])]; $r = 4;
  foreach ($R['marques'] as $m) { $rows[$r] = [['v' => $m['marque'], 's' => 'y_txt'], ['v' => $m['ops'], 's' => 'y_num'], ['v' => $m['ca'], 's' => 'y_eur'], ['v' => $m['couts'], 's' => 'y_eur'], ['v' => $m['marge'], 's' => 'y_eur'], ['v' => $m['taux'] === null ? '' : $m['taux'] / 100, 's' => 'pct']]; $r++; }
  $s1 = ['name' => 'Par marque', 'rows' => $rows, 'widths' => [0 => 34, 1 => 11, 2 => 14, 3 => 14, 4 => 14, 5 => 10], 'heights' => [3 => 30], 'freeze' => 'A4'];
  $rows = [1 => [0 => ['v' => 'Marges par opération · ' . $titre . ' · charges patronales simulées à ' . str_replace('.', ',', (string)$R['pct']) . ' % du brut tant que le réel TESE n\'est pas saisi', 's' => 'y_title']],
    3 => $h(['Marque', 'Opération', 'N°', 'CA HT', 'Source CA', 'Prorata', 'Salaires bruts (préca et CP compris)', 'Charges patronales', 'Frais (km, repas, transport)', 'Autres coûts', 'Total coûts', 'Marge', 'Marge %', 'Heures', 'Personnes', 'Données'])]; $r = 4;
  foreach ($R['ops'] as $o) { $rows[$r] = [['v' => $o['marque'], 's' => 'y_txt'], ['v' => $o['objet'], 's' => 'y_rem'], ['v' => $o['numero'], 's' => 'y_txt'], ['v' => $o['ca'], 's' => 'y_eur'], ['v' => $o['ca_source'], 's' => 'y_txt'],
    ['v' => $o['prorata'], 's' => 'pct'], ['v' => $o['salaires'], 's' => 'y_eur'], ['v' => $o['charges'], 's' => 'y_eur'], ['v' => $o['frais'], 's' => 'y_eur'], ['v' => $o['autres'], 's' => 'y_eur'],
    ['f' => "G$r+H$r+I$r+J$r", 'v' => $o['couts'], 's' => 'y_eur'], ['f' => "D$r-K$r", 'v' => $o['marge'], 's' => 'y_eur'], ['f' => "IF(D$r=0,\"\",L$r/D$r)", 'v' => $o['taux'] === null ? '' : $o['taux'] / 100, 's' => 'pct'],
    ['v' => $o['heures'], 's' => 'y_num'], ['v' => implode(', ', $o['personnes']), 's' => 'y_rem'], ['v' => ['reel' => 'réel TESE', 'partiel' => 'en partie réel', 'simule' => 'simulé'][$o['reel']], 's' => 'y_txt']]; $r++; }
  $last = max(4, $r - 1); $rows[$r] = [0 => ['v' => 'TOTAL', 's' => 'y_tot']]; foreach ([3, 6, 7, 8, 9, 10, 11] as $c) $rows[$r][$c] = ['f' => 'SUM(' . xl_col($c) . "4:" . xl_col($c) . $last . ')', 's' => 'y_tot'];
  $rows[$r][12] = ['f' => "IF(D$r=0,\"\",L$r/D$r)", 's' => 'pct'];
  $s2 = ['name' => 'Par opération', 'rows' => $rows, 'widths' => [0 => 26, 1 => 40, 2 => 13, 3 => 13, 4 => 15, 5 => 9, 6 => 16, 7 => 13, 8 => 14, 9 => 12, 10 => 13, 11 => 13, 12 => 9, 13 => 9, 14 => 40, 15 => 14], 'heights' => [3 => 44], 'freeze' => 'C4'];
  $sh = [$s1, $s2];
  foreach (['client' => 'Par client', 'pdv' => 'Par point de vente', 'personne' => 'Par intervenant(e)', 'jour' => 'Par jour', 'mois' => 'Par mois'] as $dim => $nomS) {
    $rows = [1 => [0 => ['v' => $nomS . ' · ' . $titre, 's' => 'y_title']], 3 => $h([substr($nomS, 4), 'Jours', 'Heures', 'CA HT', 'Salaires', 'Charges patronales', 'Frais', 'Autres coûts', 'Total coûts', 'Marge', 'Marge %'])]; $r = 4;
    foreach (($R['groupes'][$dim] ?? []) as $g) { $cle = $dim === 'jour' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $g['cle']) ? ['d' => $g['cle'], 's' => 'y_date'] : ['v' => $g['cle'], 's' => 'y_txt'];
      $rows[$r] = [$cle, ['v' => $g['jours'], 's' => 'y_num'], ['v' => $g['heures'], 's' => 'y_num'], ['v' => $g['ca'], 's' => 'y_eur'], ['v' => $g['salaires'], 's' => 'y_eur'], ['v' => $g['charges'], 's' => 'y_eur'], ['v' => $g['frais'], 's' => 'y_eur'], ['v' => $g['autres'], 's' => 'y_eur'],
        ['f' => "E$r+F$r+G$r+H$r", 'v' => $g['couts'], 's' => 'y_eur'], ['f' => "D$r-I$r", 'v' => $g['marge'], 's' => 'y_eur'], ['f' => "IF(D$r=0,\"\",J$r/D$r)", 'v' => $g['taux'] === null ? '' : $g['taux'] / 100, 's' => 'pct']]; $r++; }
    $sh[] = ['name' => $nomS, 'rows' => $rows, 'widths' => [0 => 32, 1 => 8, 2 => 9, 3 => 13, 4 => 13, 5 => 13, 6 => 12, 7 => 12, 8 => 13, 9 => 13, 10 => 9], 'heights' => [3 => 30], 'freeze' => 'B4'];
  }
  return xl_book($sh);
}

// ---------- Rapport d'analyse financière (PDF) ----------
function m_eur($v): string { return number_format((float)$v, 0, ',', ' ') . ' €'; }
function m_pc($v): string { return $v === null ? 'n.c.' : number_format((float)$v, 1, ',', ' ') . ' %'; }
function m_evo(float $a, float $b): string { if (abs($b) < 0.01) return 'n.c.'; $e = ($a - $b) / abs($b) * 100; return ($e >= 0 ? '+' : '') . number_format($e, 0, ',', ' ') . ' %'; }
/** Commentaire rédigé (facultatif, si la clé API Claude est renseignée dans config.php). */
function m_commentaire(array $R, array $P, string $titre, array $pts): string {
  if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || !function_exists('curl_init')) return '';
  $data = ['periode' => $titre, 'total' => $R['total'], 'precedent' => $P['total'], 'marques' => $R['marques'], 'clients' => array_slice($R['groupes']['client'] ?? [], 0, 15),
    'points_de_vente' => array_slice($R['groupes']['pdv'] ?? [], 0, 15), 'mois' => $R['groupes']['mois'] ?? [], 'operations' => array_map(fn($o) => array_intersect_key($o, array_flip(['marque', 'objet', 'ca', 'couts', 'marge', 'taux', 'reel'])), $R['ops'])];
  $prompt = "Tu es directeur financier d'une agence d'animation commerciale et d'hôtessariat événementiel (France). "
    . "Rédige en français un commentaire d'analyse financière de 4 à 5 courts paragraphes pour la direction : niveau et évolution de la marge, marques et opérations qui tirent ou pèsent sur la rentabilité, poids des postes de coûts (salaires, charges, frais de déplacement et repas), points de vigilance, puis 3 recommandations concrètes (tarification, organisation des plannings, frais). "
    . "Ton direct et factuel, sans tiret cadratin, sans titre, sans liste à puces, sans inventer de chiffres absents des données. Précise si une partie des charges est encore simulée.\n\nPoints calculés :\n- " . implode("\n- ", $pts) . "\n\nDonnées (JSON) :\n" . mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 60000);
  $ch = curl_init('https://api.anthropic.com/v1/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . ANTHROPIC_API_KEY, 'anthropic-version: 2023-06-01'],
    CURLOPT_POSTFIELDS => json_encode(['model' => ANTHROPIC_MODEL, 'max_tokens' => 1500, 'messages' => [['role' => 'user', 'content' => $prompt]]])]);
  $res = json_decode((string)curl_exec($ch), true); curl_close($ch);
  return trim((string)($res['content'][0]['text'] ?? ''));
}
/** Rapport complet : chiffres clés et comparaison avec la période précédente, coûts, marques, clients, points de vente, équipe, mois, opérations, points d'attention. */
function m_rapport(string $du, string $au, bool $ia, string $note = ''): array {
  $R = m_calc($du, $au); $n = count($R['mois']);
  $pdu = date('Y-m', strtotime($du . '-01 -' . $n . ' month')); $pau = date('Y-m', strtotime($du . '-01 -1 month')); $P = m_calc($pdu, $pau);
  $titre = $du === $au ? r_mois_nom($du) : r_mois_nom($du) . ' à ' . r_mois_nom($au); $ptitre = $pdu === $pau ? r_mois_nom($pdu) : r_mois_nom($pdu) . ' à ' . r_mois_nom($pau);
  $T = $R['total']; $TP = $P['total']; $g = $R['groupes'];
  // Points d'attention calculés
  $pts = [];
  $pts[] = 'Marge ' . m_eur($T['marge']) . ' (' . m_pc($T['taux']) . ') pour ' . m_eur($T['ca']) . ' de CA HT ; période précédente : ' . m_eur($TP['marge']) . ' (' . m_pc($TP['taux']) . ').';
  if ($T['couts'] > 0) $pts[] = 'Coûts : salaires ' . round($T['salaires'] / $T['couts'] * 100) . ' %, charges patronales ' . round($T['charges'] / $T['couts'] * 100) . ' %, frais ' . round($T['frais'] / $T['couts'] * 100) . ' %' . ($T['autres'] ? ', autres ' . round($T['autres'] / $T['couts'] * 100) . ' %' : '') . '.';
  $faibles = array_values(array_filter($R['ops'], fn($o) => empty($o['permanent']) && $o['ca'] > 0 && $o['taux'] !== null && $o['taux'] < 15));
  if ($faibles) $pts[] = count($faibles) . ' opération(s) sous 15 % de marge : ' . implode(', ', array_map(fn($o) => $o['marque'] . ' (' . m_pc($o['taux']) . ')', array_slice($faibles, 0, 5))) . '.';
  $sansCa = array_values(array_filter($R['ops'], fn($o) => empty($o['permanent']) && $o['devis_id'] && $o['ca'] <= 0)); if ($sansCa) $pts[] = count($sansCa) . ' opération(s) avec des coûts mais sans CA (devis non accepté ou montant nul).';
  $sim = count(array_filter($R['ops'], fn($o) => empty($o['permanent']) && $o['reel'] !== 'reel')); if ($sim) $pts[] = $sim . ' opération(s) encore en charges simulées (' . m_pc($R['pct']) . ' du brut) : à confirmer avec le décompte TESE.';
  $hors = array_sum(array_map(fn($h) => (float)$h['cotis'], $R['hors'])); if ($hors > 0) $pts[] = m_eur($hors) . ' de cotisations TESE non réparties (salariés sans journée dans l\'appli).';
  $perm = array_values(array_filter($R['ops'], fn($o) => !empty($o['permanent']))); if ($perm) $pts[] = 'Salariés permanents : ' . m_eur(array_sum(array_column($perm, 'couts'))) . ' de coût sur la période.';
  $com = $ia ? m_commentaire($R, $P, $titre, $pts) : '';
  // Mise en page
  $c = ['p' => ag_couleur(), 'e' => '#1A1512', 's' => '#F6F1EA', 'l' => '#E6DDD2', 'm' => '#857869', 'ok' => '#2F7048', 'w' => '#A8641F', 'b' => '#B23B2E'];
  $ton = fn($t) => $t === null ? $c['m'] : ($t < 10 ? $c['b'] : ($t < 25 ? $c['w'] : $c['ok']));
  $ff = ''; foreach (['' => ['normal', 'normal'], '-Bold' => ['bold', 'normal']] as $sx => [$w, $st]) if (is_file(__DIR__ . "/assets/fonts/Figtree$sx.ttf")) $ff .= "@font-face{font-family:'Figtree';font-weight:$w;font-style:$st;src:url('assets/fonts/Figtree$sx.ttf') format('truetype')}";
  $css = $ff . '@page{margin:16mm 15mm 16mm 15mm}body{font-family:' . ($ff ? "'Figtree'," : '') . "'DejaVu Sans',sans-serif;font-size:9.4pt;color:{$c['e']};line-height:1.35}
    h1{font-size:22pt;margin:0;letter-spacing:.5pt}h2{font-size:8.6pt;text-transform:uppercase;letter-spacing:1.6pt;color:{$c['p']};border-bottom:.6pt solid {$c['l']};padding-bottom:3pt;margin:16pt 0 6pt}
    .m{color:{$c['m']}}.sm{font-size:7.8pt}table{width:100%;border-collapse:collapse}.t th{font-size:6.9pt;text-transform:uppercase;letter-spacing:.8pt;color:{$c['m']};text-align:left;padding:0 5pt 4pt;border-bottom:.8pt solid {$c['e']}}
    .t td{padding:4pt 5pt;border-bottom:.4pt solid {$c['l']};vertical-align:top}.n{text-align:right;white-space:nowrap}.tt td{font-weight:bold;border-top:.8pt solid {$c['e']};border-bottom:0}
    .k td{width:25%;background:{$c['s']};padding:10pt 11pt;border-left:2.5pt solid {$c['p']}}.k b{font-size:15pt;display:block}.k span{font-size:7.4pt;text-transform:uppercase;letter-spacing:1pt;color:{$c['m']}}
    .blk{page-break-inside:avoid}.bar{height:7pt;background:{$c['p']};display:block}.pt li{margin-bottom:3pt}p{margin:0 0 6pt;text-align:justify}";
  $kp = fn($v, $l, $s = '') => '<td><span>' . $l . '</span><b>' . $v . '</b>' . ($s ? '<div class="sm m">' . $s . '</div>' : '') . '</td>';
  $tab = function (array $L, string $lib, int $max = 0, bool $date = false) use ($c, $ton) {
    if (!$L) return '<p class="m">Aucune donnée.</p>'; if ($max) $L = array_slice($L, 0, $max);
    $mx = max(1, max(array_map(fn($x) => abs($x['marge']), $L)));
    $h = '<table class="t"><tr><th style="width:40%">' . $lib . '</th><th class="n">Jours</th><th class="n">CA HT</th><th class="n">Coûts</th><th class="n">Marge</th><th class="n">%</th><th style="width:14%"></th></tr>';
    foreach ($L as $x) { $k = $date && preg_match('/^\d{4}-\d{2}$/', $x['cle']) ? r_mois_nom($x['cle']) : $x['cle'];
      $h .= '<tr><td>' . h($k) . '</td><td class="n">' . ($x['jours'] ?: '') . '</td><td class="n">' . m_eur($x['ca']) . '</td><td class="n">' . m_eur($x['couts']) . '</td><td class="n"><b>' . m_eur($x['marge']) . '</b></td><td class="n" style="color:' . $ton($x['taux']) . ';font-weight:bold">' . m_pc($x['taux']) . '</td>'
        . '<td><span class="bar" style="width:' . max(1, round(abs($x['marge']) / $mx * 100)) . '%;background:' . ($x['marge'] < 0 ? $c['b'] : $c['p']) . '"></span></td></tr>'; }
    return $h . '</table>'; };
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:40px">' : '<b>' . h(AG_NOM) . '</b>';
  $h = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
  $h .= '<table><tr><td>' . $logo . '</td><td style="text-align:right" class="m sm">Confidentiel · direction<br>Édité le ' . date('d/m/Y') . '</td></tr></table>';
  $h .= '<div style="margin-top:14pt"><div class="sm" style="color:' . $c['p'] . ';letter-spacing:2pt;text-transform:uppercase;font-weight:bold">Rapport d\'analyse financière</div><h1>' . h(ucfirst($titre)) . '</h1><div class="m">Comparaison : ' . h($ptitre) . '</div></div>';
  $h .= '<h2>Chiffres clés</h2><table class="k" style="border-collapse:separate;border-spacing:5pt 0"><tr>' . $kp(m_eur($T['ca']), 'CA HT', 'préc. ' . m_eur($TP['ca']) . ' · ' . m_evo($T['ca'], $TP['ca']))
    . $kp(m_eur($T['couts']), 'Coûts', 'préc. ' . m_eur($TP['couts']) . ' · ' . m_evo($T['couts'], $TP['couts'])) . $kp('<span style="color:' . $ton($T['taux']) . ';font-size:15pt;text-transform:none;letter-spacing:0">' . m_eur($T['marge']) . '</span>', 'Marge', 'préc. ' . m_eur($TP['marge']) . ' · ' . m_evo($T['marge'], $TP['marge']))
    . $kp(m_pc($T['taux']), 'Taux de marge', 'préc. ' . m_pc($TP['taux'])) . '</tr></table>';
  if ($note !== '') $h .= '<h2>Note de la direction</h2><p>' . nl2br(h($note)) . '</p>';
  if ($com !== '') $h .= '<h2>Commentaire</h2>' . implode('', array_map(fn($x) => '<p>' . h($x) . '</p>', array_filter(array_map('trim', preg_split('/\n\s*\n/', $com)))));
  $h .= '<h2>Points d\'attention</h2><ul class="pt">' . implode('', array_map(fn($x) => '<li>' . h($x) . '</li>', $pts)) . '</ul>';
  $tc = max(0.01, $T['couts']); $poste = fn($l, $v) => '<tr><td>' . $l . '</td><td class="n">' . m_eur($v) . '</td><td class="n">' . round($v / $tc * 100) . ' %</td><td style="width:45%"><span class="bar" style="width:' . max(1, round($v / $tc * 100)) . '%"></span></td></tr>';
  $h .= '<div class="blk"><h2>Structure des coûts</h2><table class="t"><tr><th style="width:40%">Poste</th><th class="n">Montant</th><th class="n">Part</th><th style="width:35%"></th></tr>' . $poste('Salaires bruts (préca et CP compris)', $T['salaires']) . $poste('Charges patronales', $T['charges']) . $poste('Frais (trajets, repas)', $T['frais']) . ($T['autres'] ? $poste('Autres coûts', $T['autres']) : '')
    . '<tr class="tt"><td>Total</td><td class="n">' . m_eur($T['couts']) . '</td><td class="n">100 %</td><td></td></tr></table></div>';
  $h .= '<div class="blk"><h2>Par marque</h2>' . $tab(array_map(fn($m) => $m + ['cle' => $m['marque'], 'jours' => 0], $R['marques']), 'Marque') . '</div>';
  if (count($g['mois'] ?? []) > 1) $h .= '<div class="blk"><h2>Évolution par mois</h2>' . $tab($g['mois'], 'Mois', 0, true) . '</div>';
  $h .= '<div class="blk"><h2>Par client</h2>' . $tab($g['client'] ?? [], 'Client', 15) . '</div>';
  $h .= '<h2>Par point de vente (15 premiers)</h2>' . $tab($g['pdv'] ?? [], 'Point de vente', 15);
  $h .= '<h2>Par intervenant(e) (15 premiers)</h2>' . $tab($g['personne'] ?? [], 'Intervenant(e)', 15);
  $ops = array_values(array_filter($R['ops'], fn($o) => empty($o['permanent']) && $o['ca'] > 0)); usort($ops, fn($a, $b) => $b['marge'] <=> $a['marge']);
  $lo = fn($L) => $tab(array_map(fn($o) => ['cle' => $o['marque'] . ' · ' . $o['objet'], 'jours' => $o['jours'], 'ca' => $o['ca'], 'couts' => $o['couts'], 'marge' => $o['marge'], 'taux' => $o['taux']], $L), 'Opération');
  if ($ops) { $h .= '<h2>Opérations les plus rentables</h2>' . $lo(array_slice($ops, 0, 5)); if (count($ops) > 5) $h .= '<h2>Opérations les moins rentables</h2>' . $lo(array_slice(array_reverse($ops), 0, 5)); }
  $reel = count(array_filter($R['ops'], fn($o) => empty($o['permanent']) && $o['reel'] === 'reel')); $tot = count(array_filter($R['ops'], fn($o) => empty($o['permanent'])));
  $h .= '<div class="blk"><h2>Méthode et fiabilité</h2><p class="sm m">CA : facture de l\'opération, sinon devis ; réparti au prorata des journées comprises dans la période. Salaires : heures des contrats au taux du contrat, dimanches et fériés majorés, préca et CP à 10 %. Charges patronales : décompte TESE quand il est déposé (cotisations moins part salariale à ' . m_pc($R['taux_sal']) . ' du brut), sinon simulées à ' . m_pc($R['pct']) . ' du brut. Frais : indemnités kilométriques ou transports en commun et repas. '
    . $reel . ' opération(s) sur ' . $tot . ' au réel TESE.' . ($com !== '' ? ' Commentaire rédigé automatiquement à partir de ces chiffres, à relire.' : '') . '</p></div>';
  $h .= '</body></html>';
  return ['html' => $h, 'titre' => $titre];
}

function m_pdf(string $html): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(180);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('isHtml5ParserEnabled', true); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
  $cv = $pdf->getCanvas(); $f = $pdf->getFontMetrics()->getFont('Figtree') ?: $pdf->getFontMetrics()->getFont('DejaVu Sans');
  $cv->page_text($cv->get_width() - 15 * 2.835 - 30, $cv->get_height() - 16 * 2.835 + 18, '{PAGE_NUM} / {PAGE_COUNT}', $f, 7, [0.52, 0.47, 0.41]);
  $cv->page_text(15 * 2.835, $cv->get_height() - 16 * 2.835 + 18, AG_NOM . ' · Rapport d\'analyse financière · confidentiel', $f, 7, [0.52, 0.47, 0.41]);
  return $pdf->output();
}
