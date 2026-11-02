<?php
// Préparation de paye : un fichier par mois (du 1er au dernier jour), une ligne par contrat, au format du tableau « Salaires ».
// Rempli avec les journées des contrats de travail (dernière version : contrat ou avenant), les km depuis l'adresse de départ
// et les repas (un par journée de plus de 6 h). Les charges, impôts et nets restent à saisir d'après les bulletins.
require_once __DIR__ . '/contrats_lib.php';

/** Journées d'après le planning, pour une personne sans contrat envoyé. */
function pa_planning(int $aid, ?int $devis, string $du, string $au, float $taux, string $pole = 'anim'): array {
  $st = db()->prepare("SELECT date_mission AS date, debut, fin, pause, magasin, zone FROM missions WHERE animatrice_id = ? AND " . ($devis ? "devis_id = ?" : "devis_id IS NULL")
    . " AND statut <> 'annulee' AND date_mission BETWEEN ? AND ? ORDER BY date_mission, debut");
  $st->execute($devis ? [$aid, $devis, $du, $au] : [$aid, $du, $au]); $out = []; $abs = pa_absences($aid, $du, $au);
  foreach ($st->fetchAll() as $m) { if (isset($abs[$m['date'] . '|' . $m['magasin']])) continue;
    $J = rg_jour($m['date'], $m['debut'], $m['fin'], (int)$m['pause'], (string)$m['magasin'], (string)$m['zone'], $pole);
    $out[] = ['date' => $m['date'], 'debut' => $m['debut'], 'fin' => $m['fin'], 'pause' => (int)$m['pause'], 'magasin' => $m['magasin'], 'heures' => $J['heures'], 'maj' => $J['maj'], 'maj_txt' => $J['txt'], 'eq' => $J['eq'],
      'h_dim' => $J['h_dim'], 'h_ferie' => $J['h_ferie'], 'h_nuit' => $J['h_nuit'], 'h_zti' => $J['h_zti'], 'salaire' => round(($J['heures'] + $J['eq']) * $taux, 2)];
  }
  return $out;
}

/** Journées marquées « absent(e) » par l'agence : date|point de vente => true (ni payées ni facturées). */
function pa_absences(int $aid, string $du, string $au, ?int $devis = null): array {
  $st = db()->prepare("SELECT m.date_mission, m.magasin FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id WHERE pp.statut_admin = 'absent' AND pp.animatrice_id = ? AND m.date_mission BETWEEN ? AND ?" . ($devis ? " AND m.devis_id = ?" : ""));
  $st->execute($devis ? [$aid, $du, $au, $devis] : [$aid, $du, $au]); $o = []; foreach ($st->fetchAll() as $x) $o[$x['date_mission'] . '|' . $x['magasin']] = true; return $o;
}
/** Lignes de paye du mois. */
function pa_lignes(string $mois): array {
  c_schema(); require_once __DIR__ . '/geo_lib.php'; $GLOBALS['GEO_MAX'] = 200;
  [$du, $au] = r_range($mois); $r = g_reg(); $taux0 = c_num($r['ct_taux']);
  $out = []; $vus = [];
  $q = db()->query("SELECT c.id FROM contrats c JOIN (SELECT animatrice_id, devis_id, MAX(avenant) AS av FROM contrats GROUP BY animatrice_id, devis_id) x
    ON x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id AND x.av = c.avenant");
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $c = c_row((int)$id); $aid = (int)$c['animatrice_id']; $dv = (int)$c['devis_id']; $vus[$aid . '|' . $dv] = true;
    $L = array_values(array_filter($c['lignes'], fn($l) => $l['date'] >= $du && $l['date'] <= $au)); if (!$L) continue;
    $abs = pa_absences($aid, $du, $au, $dv); $absD = array_values(array_unique(array_map(fn($l) => g_date($l['date']), array_filter($L, fn($l) => isset($abs[$l['date'] . '|' . $l['magasin']])))));
    $L = array_values(array_filter($L, fn($l) => !isset($abs[$l['date'] . '|' . $l['magasin']]))); if (!$L) continue;
    // Majorations recalculées avec les règles du jour (taux de l'agence, points de vente classés ZTI depuis l'envoi) : la paye suit toujours les règles en vigueur.
    $dDoc = g_doc($dv); $pole = $c['params']['pole'] ?? g_pole($dDoc); $taux = (float)$c['params']['taux']; $Rg = rg_regles($pole);
    $L = array_map(function ($l) use ($pole, $dDoc, $taux, $Rg) { $J = rg_jour($l['date'], $l['debut'], $l['fin'], (int)$l['pause'], (string)$l['magasin'], (string)($dDoc['zone'] ?? ''), $pole, $Rg);
      return array_merge($l, ['heures' => $J['heures'], 'maj' => $J['maj'], 'maj_txt' => $J['txt'], 'eq' => $J['eq'], 'h_dim' => $J['h_dim'], 'h_ferie' => $J['h_ferie'], 'h_nuit' => $J['h_nuit'], 'h_zti' => $J['h_zti'], 'salaire' => round(($J['heures'] + $J['eq']) * $taux, 2)]); }, $L);
    $rem = ['Contrat n°' . $c['numero'] . ($c['avenant'] ? ' (avenant ' . $c['avenant'] . ')' : '')];
    if ($absD) $rem[] = count($absD) . ' journée' . (count($absD) > 1 ? 's' : '') . ' d\'absence non payée' . (count($absD) > 1 ? 's' : '') . ' (' . implode(', ', $absD) . ')';
    if ($c['statut'] !== 'signe') $rem[] = ($c['avenant'] ? 'avenant' : 'contrat') . ' pas encore signé';
    $E = c_etat($aid, $dv); if (in_array($E['etat'], ['a_mettre_a_jour', 'avenant'], true)) $rem[] = 'planning modifié depuis : ' . ($E['etat'] === 'avenant' ? 'avenant' : 'contrat') . ' à renvoyer';
    $out[] = pa_ligne($aid, $c['salarie'], $c['params'], $L, $rem, $c['params']['trajets'] ?? null) + ['devis_id' => $dv, 'pole' => $c['params']['pole'] ?? g_pole(g_doc($dv))];
  }
  // Journées au planning sans contrat envoyé : reprises pour ne rien oublier, signalées.
  $st = db()->prepare("SELECT DISTINCT animatrice_id, devis_id FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission BETWEEN ? AND ?"); $st->execute([$du, $au]);
  foreach ($st->fetchAll() as $x) {
    $aid = (int)$x['animatrice_id']; $dv = $x['devis_id'] ? (int)$x['devis_id'] : null; if (isset($vus[$aid . '|' . $dv])) continue;
    $d = $dv ? g_doc($dv) : null; $P = $d ? c_params($aid, $d, []) : ['taux' => $taux0, 'depart' => null, 'salarie_over' => []];
    $L = pa_planning($aid, $dv, $du, $au, (float)$P['taux'], $d ? g_pole($d) : 'anim'); if (!$L) continue;
    $ab = array_unique(array_map(fn($k) => g_date(explode('|', $k)[0]), array_keys(array_filter(pa_absences($aid, $du, $au, $dv), fn($v) => $v))));
    $out[] = pa_ligne($aid, c_salarie($aid, $P['salarie_over'] ?? []), $P, $L, array_merge(['pas de contrat envoyé : journées d\'après le planning'], $ab ? [count($ab) . ' journée' . (count($ab) > 1 ? 's' : '') . ' d\'absence non payée' . (count($ab) > 1 ? 's' : '') . ' (' . implode(', ', $ab) . ')'] : []), null) + ['devis_id' => (int)$dv] + ['pole' => g_pole($d)];
  }
  // Heures supplémentaires : par semaine civile et par personne, toutes opérations confondues (semaines à cheval sur deux mois comprises).
  $HS = pa_hs($du, $au);
  foreach ($out as &$x) { $aid = (int)$x['animatrice_id']; foreach ($x['detail'] as $l) { $h = $HS[$aid . '|' . $l['date'] . '|' . $l['debut'] . '|' . $l['magasin']] ?? null; if (!$h) continue;
      $x['hs1'] = round($x['hs1'] + $h['hs1'], 2); $x['hs2'] = round($x['hs2'] + $h['hs2'], 2); $x['t1'] = $h['t1']; $x['t2'] = $h['t2']; }
    if ($x['hs1'] || $x['hs2']) $x['remarques'] .= ($x['remarques'] ? ' · ' : '') . 'heures sup : ' . c_h($x['hs1']) . ' h à +' . rg_pct($x['t1']) . ($x['hs2'] ? ', ' . c_h($x['hs2']) . ' h à +' . rg_pct($x['t2']) : '');
    if ($x['h_zti']) $x['remarques'] .= ($x['remarques'] ? ' · ' : '') . 'soirée ZTI : ' . c_h($x['h_zti']) . ' h payées +' . rg_pct(rg_regles($x['pole'] ?? 'anim')['zti']) . ', repos compensateur de ' . c_h($x['h_zti']) . ' h dû, retour à domicile à la charge de l\'employeur (' . $x['soirees_zti'] . ' soirée' . ($x['soirees_zti'] > 1 ? 's' : '') . ')'; }
  unset($x);
  // Notes de frais validées du mois (date de la dépense) : remboursées avec la paye, sur la première ligne de la personne.
  require_once __DIR__ . '/extras_lib.php'; $vu = [];
  foreach ($out as &$x) { $aid = (int)$x['animatrice_id']; if (isset($vu[$aid])) continue; $vu[$aid] = 1; $N = ndf_mois($aid, $du, $au); if (!$N) continue;
    $x['ndf'] = round(array_sum(array_column($N, 'montant')), 2); $x['remarques'] .= ($x['remarques'] ? ' · ' : '') . count($N) . ' note' . (count($N) > 1 ? 's' : '') . ' de frais : ' . number_format($x['ndf'], 2, ',', ' ') . ' €'; } unset($x);
  // Personne sans journée ce mois-ci mais avec des notes de frais validées : une ligne pour les rembourser.
  $st = db()->prepare("SELECT DISTINCT animatrice_id FROM ndf WHERE statut IN ('validee','payee') AND jour BETWEEN ? AND ?"); $st->execute([$du, $au]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $aid) { if (isset($vu[(int)$aid])) continue; $N = ndf_mois((int)$aid, $du, $au); $S = c_salarie((int)$aid); $t = round(array_sum(array_column($N, 'montant')), 2);
    $out[] = ['animatrice_id' => (int)$aid, 'nom' => trim(mb_strtoupper((string)$S['nom']) . ' ' . $S['prenom']), 'matricule' => (string)$S['matricule'], 'du' => $du, 'au' => $au, 'jours' => 0, 'periode' => '', 'heures' => 0, 'heures_maj' => 0,
      'taux' => 0, 'repas_jours' => 0, 'repas' => 0, 'tkm' => c_num($r['km']), 'km' => [], 'km_inconnu' => false, 'tc_jour' => 0, 'tc_total' => 0, 'ndf' => $t, 'devis_id' => (int)($N[0]['devis_id'] ?? 0),
      'pole' => 'anim', 'remarques' => count($N) . ' note' . (count($N) > 1 ? 's' : '') . ' de frais seulement : ' . number_format($t, 2, ',', ' ') . ' €', 'detail' => []]; }
  usort($out, fn($a, $b) => [mb_strtolower($a['nom']), $a['du']] <=> [mb_strtolower($b['nom']), $b['du']]);
  return $out;
}

/** Une ligne : jours, période, heures (dont dimanches et fériés), taux, repas, km par point de vente. */
function pa_ligne(int $aid, array $S, array $P, array $L, array $rem, ?array $Tj): array {
  $r = g_reg(); $F = g_frais((string)($P['pole'] ?? (!empty($L[0]['devis_id']) ? g_pole(g_doc((int)$L[0]['devis_id'])) : 'anim')), $r);
  // Km : ceux figés dans le contrat envoyé ; à défaut (ancien contrat, pas de contrat), calculés depuis l'adresse de départ.
  $kmMag = []; $manuel = []; foreach ((array)($Tj['lignes'] ?? []) as $t) { $kmMag[(string)$t['magasin']] = $t['km_ar']; if (!empty($t['manuel'])) $manuel[(string)$t['magasin']] = true; }
  $dep = $Tj['depart'] ?? null; $perso = (bool)($Tj['perso'] ?? false);
  $mags = array_values(array_unique(array_map(fn($l) => (string)$l['magasin'], $L)));
  // Déménagement depuis le contrat : l'adresse révisée de la fiche fait foi, les km sont recalculés (les km saisis pour l'ancienne adresse ne valent plus).
  $live = c_salarie($aid, (array)($P['salarie_over'] ?? [])); $norm = fn($x) => mb_strtolower(preg_replace('/\s+/', ' ', c_adr($x)));
  if (!c_depart_ok($P['depart'] ?? null) && c_adr($live) !== '' && c_adr($S) !== '' && $norm($live) !== $norm($S)) {
    $rem[] = 'adresse changée depuis le contrat (' . c_adr($S) . ' → ' . c_adr($live) . ') : km recalculés, avenant à envoyer';
    $P2 = $P; unset($P2['km_man']); $T = c_trajets($aid, $P2, $L, $live); $kmMag = []; $manuel = []; foreach ($T['lignes'] as $t) $kmMag[(string)$t['magasin']] = $t['km_ar']; $dep = $T['depart']; $S = $live + $S;
  }
  if (array_diff($mags, array_keys($kmMag))) { $T = c_trajets($aid, $P, $L, $S); foreach ($T['lignes'] as $t) { $kmMag[(string)$t['magasin']] ??= $t['km_ar']; if (!empty($t['manuel'])) $manuel[(string)$t['magasin']] = true; } $dep ??= $T['depart']; $perso = $perso || $T['perso']; }
  // Distances anormales (autre zone que le domicile, ou au-delà du seuil) : non comptées, à vérifier.
  $depA = c_depart_ok($P['depart'] ?? null) ?: ['adresse' => (string)($S['adresse'] ?? ''), 'code_postal' => (string)($S['code_postal'] ?? ''), 'ville' => (string)($S['ville'] ?? '')];
  foreach ($mags as $m) { if (isset($manuel[$m])) continue; $ano = c_km_anormal($kmMag[$m] ?? null, $m, $depA);
    if ($ano !== '') { $kmMag[$m] = null; $rem[] = 'distance anormale vers ' . $m . ' (' . $ano . ') : km non comptés, vérifiez l\'adresse'; } }
  $jours = []; foreach ($L as $l) $jours[(string)$l['magasin']][$l['date']] = true;
  // Transports en commun : une somme par journée au lieu des km (figée dans le contrat envoyé, sinon d'après les conditions du contrat).
  $tc = ($Tj['mode'] ?? ($P['transport'] ?? 'voiture')) === 'tc'; $tcj = $tc ? (float)($Tj['tc_jour'] ?? ($P['tc_jour'] ?? 0)) : 0;
  if ($tc) { $kmMag = []; foreach ($jours as $m => $dd) $kmMag[$m] = null; }
  $km = []; $inconnu = false; if (!$tc) foreach ($jours as $m => $dd) { $k = $kmMag[$m] ?? null; if ($k === null) $inconnu = true; else $km[] = ['km' => (float)$k, 'n' => count($dd), 'magasin' => $m]; }
  // Journées partagées entre deux points de vente : domicile → A → B → domicile (écart appliqué une fois sur la journée).
  $circ = $tc ? [] : c_circuits($L, $kmMag);
  foreach ($circ as $d => $c) { $km[] = ['km' => $c['ecart'], 'n' => 1, 'magasin' => 'trajet entre points de vente le ' . g_date($d)];
    $rem[] = 'journée partagée le ' . g_date($d) . ' : ' . implode(' → ', $c['mags']) . ', ' . number_format($c['reel'], 0, ',', ' ') . ' km au lieu de ' . number_format($c['compte'], 0, ',', ' '); }
  $parJour = c_par_jour($L); $dates = array_keys($parJour); sort($dates);
  $d1 = (int)substr($dates[0], 8, 2); $d2 = (int)substr(end($dates), 8, 2);
  if (empty($S['matricule'])) { $mt = db()->prepare("SELECT matricule FROM animatrices WHERE id = ?"); $mt->execute([$aid]); $S['matricule'] = (string)$mt->fetchColumn(); }
  if ($perso) $rem[] = 'départ : ' . $dep;
  if ($tc) $rem[] = 'transports en commun : ' . number_format($tcj, 2, ',', ' ') . ' € par jour';
  if ($inconnu && !preg_grep('/^distance anormale/', $rem)) $rem[] = 'km à compléter (adresse manquante)';
  $np = $F['panier'] > 0 ? g_jours_tardifs($L, $F['panier_apres']) : 0; $nt = $F['tenue'] > 0 ? count($parJour) : 0; $extra = round($np * $F['panier'] + $nt * $F['tenue'], 2);
  if ($np) $rem[] = $np . ' panier' . ($np > 1 ? 's' : '') . ' de nuit à ' . number_format($F['panier'], 2, ',', ' ') . ' €'; if ($nt) $rem[] = 'entretien de tenue : ' . $nt . ' × ' . number_format($F['tenue'], 2, ',', ' ') . ' €';
  return ['animatrice_id' => $aid, 'nom' => trim(mb_strtoupper((string)$S['nom']) . ' ' . $S['prenom']), 'matricule' => (string)($S['matricule'] ?? ''), 'du' => $dates[0], 'au' => end($dates),
    'jours' => count($parJour), 'periode' => $d1 === $d2 ? (string)$d1 : $d1 . ' au ' . $d2,
    'heures' => round(array_sum(array_column($L, 'heures')), 2), 'heures_maj' => round(array_sum(array_map(fn($l) => $l['maj'] ? $l['heures'] : 0, $L)), 2),
    'h_dimfer' => round(array_sum(array_map(fn($l) => max((float)($l['h_dim'] ?? 0), (float)($l['h_ferie'] ?? 0)), $L)), 2), 'h_nuit' => round(array_sum(array_map(fn($l) => (float)($l['h_nuit'] ?? 0), $L)), 2),
    'h_zti' => round(array_sum(array_map(fn($l) => (float)($l['h_zti'] ?? 0), $L)), 2), 'soirees_zti' => count(array_unique(array_map(fn($l) => $l['date'], array_filter($L, fn($l) => ($l['h_zti'] ?? 0) > 0)))),
    'eq' => round(array_sum(array_map(fn($l) => isset($l['eq']) ? (float)$l['eq'] : ($l['maj'] ? (float)$l['heures'] : 0), $L)), 4), 'hs1' => 0, 'hs2' => 0, 't1' => 25, 't2' => 50,
    'taux' => max(g_smic($r), (float)$P['taux']), 'repas_jours' => count(array_filter($parJour, fn($h) => $h > $F['seuil'])), 'repas' => $F['repas'], 'tkm' => $F['km'], 'extra' => $extra,
    'km' => $km, 'km_inconnu' => $inconnu, 'tc_jour' => $tcj, 'tc_total' => round($tcj * count($parJour), 2), 'remarques' => implode(' · ', $rem),
    'detail' => (function () use ($L, $kmMag, $circ) { $vu = []; $o = [];
      foreach ($L as $l) { $k = $kmMag[(string)$l['magasin']] ?? null; if (isset($circ[$l['date']])) { $k = isset($vu[$l['date']]) ? 0.0 : $circ[$l['date']]['reel']; $vu[$l['date']] = 1; } $o[] = $l + ['km_ar' => $k]; }
      return $o; })()];
}

/** Heures supplémentaires du mois : clé personne|date|début|point de vente => hs1, hs2, taux. Les semaines entamées le mois précédent ou finies le mois suivant sont comptées en entier. */
function pa_hs(string $du, string $au): array {
  $d0 = date('Y-m-d', strtotime($du . ' -' . (date('N', strtotime($du)) - 1) . ' days')); $d1 = date('Y-m-d', strtotime($au . ' +' . (7 - date('N', strtotime($au))) . ' days'));
  $E = []; $vus = []; $abs = [];
  $ab = function (int $aid) use (&$abs, $d0, $d1) { return $abs[$aid] ??= pa_absences($aid, $d0, $d1); };
  $q = db()->query("SELECT c.id FROM contrats c JOIN (SELECT animatrice_id, devis_id, MAX(avenant) AS av FROM contrats GROUP BY animatrice_id, devis_id) x ON x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id AND x.av = c.avenant");
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) { $c = c_row((int)$id); $aid = (int)$c['animatrice_id']; $vus[$aid . '|' . $c['devis_id']] = true; $pole = $c['params']['pole'] ?? g_pole(g_doc((int)$c['devis_id']));
    foreach ($c['lignes'] as $l) if ($l['date'] >= $d0 && $l['date'] <= $d1 && !isset($ab($aid)[$l['date'] . '|' . $l['magasin']]))
      $E[$aid][] = ['date' => $l['date'], 'debut' => $l['debut'], 'heures' => (float)$l['heures'], 'pole' => $pole, 'k' => $aid . '|' . $l['date'] . '|' . $l['debut'] . '|' . $l['magasin']]; }
  $st = db()->prepare("SELECT animatrice_id, devis_id, date_mission, debut, fin, pause, magasin FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission BETWEEN ? AND ?"); $st->execute([$d0, $d1]);
  $poles = [];
  foreach ($st->fetchAll() as $m) { $aid = (int)$m['animatrice_id']; if (isset($vus[$aid . '|' . $m['devis_id']]) || isset($ab($aid)[$m['date_mission'] . '|' . $m['magasin']])) continue;
    $pole = $m['devis_id'] ? ($poles[$m['devis_id']] ??= g_pole(g_doc((int)$m['devis_id']))) : 'anim';
    $E[$aid][] = ['date' => $m['date_mission'], 'debut' => $m['debut'], 'heures' => r_min($m['debut'], $m['fin'], (int)$m['pause']) / 60, 'pole' => $pole, 'k' => $aid . '|' . $m['date_mission'] . '|' . $m['debut'] . '|' . $m['magasin']]; }
  $reg = g_reg(); $o = []; foreach ($E as $L) $o += rg_hs($L, $reg); return $o;
}

/** Calculs de la ligne (les mêmes que les formules du classeur). */
function pa_calc(array $x): array {
  // CDD d'usage (pôle accueil) : pas d'indemnité de fin de contrat (art. L.1243-10 du Code du travail).
  $eq = isset($x['eq']) ? (float)$x['eq'] : (float)$x['heures_maj']; $hs = (float)($x['hs1'] ?? 0) * (float)($x['t1'] ?? 25) / 100 + (float)($x['hs2'] ?? 0) * (float)($x['t2'] ?? 50) / 100;
  $brut = round(($x['heures'] + $eq + $hs) * $x['taux'], 2); $preca = ($x['pole'] ?? 'anim') === 'hot' ? 0.0 : round($brut * 0.1, 2); $cp = round(($brut + $preca) * 0.1, 2);
  $repas = round($x['repas_jours'] * $x['repas'], 2); $kmj = $x['jours'] ? array_sum(array_map(fn($k) => $k['km'] * $k['n'], $x['km'])) / $x['jours'] : 0;
  $ikm = round($kmj * $x['tkm'] * $x['jours'], 2); $tc = (float)($x['tc_total'] ?? 0) + (float)($x['ndf'] ?? 0) + (float)($x['extra'] ?? 0); // frais divers : transports en commun, notes de frais, panier de nuit et tenue (accueil)
  return ['brut' => $brut, 'preca' => $preca, 'cp' => $cp, 'total_brut' => round($brut + $preca + $cp, 2), 'repas_eur' => $repas, 'km_jour' => round($kmj, 2), 'km_eur' => $ikm, 'tc' => $tc, 'frais' => round($repas + $ikm + $tc, 2)];
}

/** Classeur : onglet du mois au format habituel + détail des journées. */
function pa_xlsx(string $mois, array $X): string {
  require_once __DIR__ . '/xlsx_lib.php';
  $f = fn($v) => rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
  // Colonnes repérées par clé : les formules restent justes si l'ordre change.
  $C = ['nom' => ['NOM', 26], 'jours' => ['Nbre Jours', 9], 'periode' => ['Période', 11], 'heures' => ['Nbre heures', 9], 'hs1' => ['Heures sup 1er taux', 10], 'hs2' => ['Heures sup 2e taux', 10],
    'hdim' => ['Heures dimanche / férié', 11], 'hnuit' => ['Heures de nuit', 9], 'hzti' => ['Heures soirée ZTI (21 h - 24 h)', 12], 'eq' => ['Majorations (heures équivalentes)', 13], 'repos' => ['Repos compensateur ZTI dû (h)', 12],
    'taux' => ['Salaire (taux horaire)', 10], 'prime' => ['Prime', 9], 'brut' => ['Salaire brut', 12], 'cumul' => ['Cumul brut', 12], 'preca' => ['Préca', 10], 'cp' => ['CP', 10], 'total' => ['Total brut', 12],
    'repas' => ['Repas', 10], 'nkm' => ['Nbre Km', 9], 'km' => ['Km', 10], 'divers' => ['Frais divers', 10], 'frais' => ['Frais', 11], 'rem' => ['Remarques', 46],
    'chs' => ['Charges salariales', 12], 'imp' => ['Impôts', 9], 'chp' => ['Charges patronales', 12], 'net' => ['Nets versés', 12]];
  $K = array_keys($C); $I = array_flip($K); $L = fn($k) => xl_col($I[$k]);
  [$du, $au] = r_range($mois);
  $rows = [1 => [0 => ['v' => 'Préparation de paye · ' . r_mois_txt($mois) . ' (du ' . g_date($du) . ' au ' . g_date($au) . ')', 's' => 'y_title']],
    2 => [0 => ['v' => 'Rempli depuis ' . AG_COURT . ' : journées des contrats de travail, majorations selon vos règles de paye (Réglages), heures sup par semaine civile, km depuis l\'adresse de départ (' . str_replace('.', ',', $f($X[0]['tkm'] ?? 0.29)) . ' €/km), un repas par journée de plus de 6 h. Charges, impôts et nets à saisir d\'après les bulletins.', 's' => 'y_hint']]];
  foreach ($K as $i => $k) $rows[3][$i] = ['v' => $C[$k][0], 's' => 'y_head'];
  $r = 4;
  foreach ($X as $x) {
    $k = pa_calc($x); $n = $r; $c = fn($key) => $L($key) . $n; $hot = ($x['pole'] ?? 'anim') === 'hot';
    if (!$x['km']) $kmCell = ['v' => '', 's' => 'y_num'];
    elseif (count($x['km']) === 1) $kmCell = ['v' => $x['km'][0]['km'], 's' => 'y_num'];
    else $kmCell = ['f' => '(' . implode('+', array_map(fn($z) => $f($z['km']) . '*' . $z['n'], $x['km'])) . ")/" . $c('jours'), 'v' => $k['km_jour'], 's' => 'y_num']; // plusieurs points de vente : moyenne exacte par jour
    $z = fn($v) => ['v' => (float)$v ?: '', 's' => 'y_num'];
    $V = [
      'nom' => ['v' => $x['nom'] . ($hot ? ' (CDDU)' : ''), 's' => 'y_txt'], 'jours' => ['v' => $x['jours'], 's' => 'y_num'], 'periode' => ['v' => $x['periode'], 's' => 'y_num'], 'heures' => ['v' => $x['heures'], 's' => 'y_num'],
      'hs1' => $z($x['hs1'] ?? 0), 'hs2' => $z($x['hs2'] ?? 0), 'hdim' => $z($x['h_dimfer'] ?? 0), 'hnuit' => $z($x['h_nuit'] ?? 0), 'hzti' => $z($x['h_zti'] ?? 0), 'eq' => $z($x['eq'] ?? 0), 'repos' => $z($x['h_zti'] ?? 0),
      'taux' => ['v' => $x['taux'], 's' => 'y_eur'], 'prime' => ['v' => '', 's' => 'y_eur'],
      'brut' => ['f' => '(' . $c('heures') . '+' . $c('hs1') . '*' . $f(($x['t1'] ?? 25) / 100) . '+' . $c('hs2') . '*' . $f(($x['t2'] ?? 50) / 100) . '+' . $c('eq') . ')*' . $c('taux') . '+' . $c('prime'), 'v' => $k['brut'], 's' => 'y_eur'],
      'cumul' => ['f' => $c('brut'), 'v' => $k['brut'], 's' => 'y_eur'],
      'preca' => $hot ? ['v' => 0, 's' => 'y_eur'] : ['f' => $c('cumul') . '*10%', 'v' => $k['preca'], 's' => 'y_eur'], 'cp' => ['f' => '(' . $c('cumul') . '+' . $c('preca') . ')*10%', 'v' => $k['cp'], 's' => 'y_eur'],
      'total' => ['f' => $c('brut') . '+' . $c('preca') . '+' . $c('cp'), 'v' => $k['total_brut'], 's' => 'y_eur'],
      'repas' => $x['repas_jours'] ? ['f' => $f($x['repas']) . "*" . $x['repas_jours'], 'v' => $k['repas_eur'], 's' => 'y_eur'] : ['v' => 0, 's' => 'y_eur'],
      'nkm' => $kmCell, 'km' => ['f' => '(' . $c('nkm') . '*' . $f($x['tkm']) . ')*' . $c('jours'), 'v' => $k['km_eur'], 's' => 'y_eur'],
      'divers' => (function () use ($x, $f, $c, $k) { $p = []; if (!empty($x['tc_jour'])) $p[] = $f($x['tc_jour']) . '*' . $c('jours'); if (!empty($x['ndf'])) $p[] = $f($x['ndf']); if (!empty($x['extra'])) $p[] = $f($x['extra']);
        return $p ? ['f' => implode('+', $p), 'v' => $k['tc'], 's' => 'y_eur'] : ['v' => '', 's' => 'y_eur']; })(),
      'frais' => ['f' => $c('repas') . '+' . $c('km') . '+' . $c('divers'), 'v' => $k['frais'], 's' => 'y_eur'], 'rem' => ['v' => $x['remarques'], 's' => 'y_rem'],
      'chs' => ['v' => '', 's' => 'y_eur'], 'imp' => ['v' => '', 's' => 'y_eur'], 'chp' => ['v' => '', 's' => 'y_eur'],
      'net' => ['f' => $c('total') . '+' . $c('frais') . '-' . $c('chs') . '-' . $c('imp'), 'v' => round($k['total_brut'] + $k['frais'], 2), 's' => 'y_eur'],
    ];
    foreach ($V as $key => $cell) $rows[$r][$I[$key]] = $cell;
    $r++;
  }
  $last = max(4, $r - 1); $t = $r;
  $rows[$t] = [0 => ['v' => 'TOTAL', 's' => 'y_tot']];
  foreach (['jours', 'heures', 'hs1', 'hs2', 'hdim', 'hnuit', 'hzti', 'repos'] as $key) $rows[$t][$I[$key]] = ['f' => 'SUM(' . $L($key) . "4:" . $L($key) . $last . ')', 's' => 'y_totn'];
  foreach (['brut', 'cumul', 'preca', 'cp', 'total', 'repas', 'km', 'divers', 'frais', 'chs', 'imp', 'chp', 'net'] as $key) $rows[$t][$I[$key]] = ['f' => 'SUM(' . $L($key) . "4:" . $L($key) . $last . ')', 's' => 'y_tot'];
  $widths = []; foreach ($K as $i => $key) $widths[$i] = $C[$key][1];
  $main = ['name' => MOIS_FR[(int)substr($mois, 5, 2)] ?? 'Paye', 'rows' => $rows, 'widths' => $widths, 'heights' => [3 => 32], 'freeze' => 'B4'];
  // Détail des journées
  $D = [1 => [0 => ['v' => 'Détail des journées · ' . r_mois_txt($mois), 's' => 'y_title']]];
  foreach (['NOM', 'Date', 'Point de vente', 'Horaires', 'Heures', 'Majorations', 'Repas', 'Km A/R', 'Indemnité km'] as $i => $h) $D[3][$i] = ['v' => $h, 's' => 'y_head'];
  $r = 4;
  foreach ($X as $x) foreach ($x['detail'] as $l) {
    $D[$r] = [0 => ['v' => $x['nom'], 's' => 'y_txt'], 1 => ['d' => $l['date'], 's' => 'y_date'], 2 => ['v' => (string)$l['magasin'], 's' => 'y_txt'], 3 => ['v' => $l['debut'] . ' à ' . $l['fin'] . ($l['pause'] ? ' (pause ' . $l['pause'] . ' min)' : ''), 's' => 'y_txt'],
      4 => ['v' => (float)$l['heures'], 's' => 'y_num'], 5 => ['v' => $l['maj'] ? ((string)($l['maj_txt'] ?? '') ?: 'oui') : '', 's' => 'y_txt'], 6 => ['v' => '', 's' => 'y_num'],
      7 => ['v' => $l['km_ar'] ?? '', 's' => 'y_num'], 8 => $l['km_ar'] === null ? ['v' => '', 's' => 'y_eur'] : ['f' => "H$r*" . $f($x['tkm']), 'v' => round($l['km_ar'] * $x['tkm'], 2), 's' => 'y_eur']];
    $r++;
  }
  // Repas : marqué sur la première ligne de chaque journée de plus de 6 h (une journée peut avoir plusieurs créneaux).
  $seen = []; $tot = [];
  foreach ($D as $rr => $cells) if ($rr >= 4) { $key = $cells[0]['v'] . '|' . $cells[1]['d']; $tot[$key] = ($tot[$key] ?? 0) + $cells[4]['v']; }
  foreach ($D as $rr => $cells) if ($rr >= 4) { $key = $cells[0]['v'] . '|' . $cells[1]['d']; if (!isset($seen[$key]) && $tot[$key] > 6) $D[$rr][6]['v'] = 'oui'; $seen[$key] = true; }
  $det = ['name' => 'Détail des journées', 'rows' => $D, 'widths' => [0 => 26, 1 => 12, 2 => 30, 3 => 26, 4 => 9, 5 => 30, 6 => 9, 7 => 9, 8 => 12], 'heights' => [3 => 32], 'freeze' => 'A4'];
  return xl_book([$main, $det]);
}
function pa_fichier(string $mois): string { return 'Preparation_paye_' . $mois . '.xlsx'; }
