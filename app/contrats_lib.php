<?php
// Contrats de travail (CDD d'intervention d'animation commerciale) : construits depuis le planning, envoyés par l'admin, signés dans l'appli.
// Après signature, tout changement de date donne un avenant à signer.
require_once __DIR__ . '/releves_lib.php';
require_once __DIR__ . '/regles_lib.php';

function c_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; p_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS contrats (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NOT NULL, numero INT UNSIGNED NOT NULL,
    avenant INT UNSIGNED NOT NULL DEFAULT 0, statut VARCHAR(10) NOT NULL DEFAULT 'envoye', salarie MEDIUMTEXT NULL, params MEDIUMTEXT NULL, lignes MEDIUMTEXT NULL, precedent MEDIUMTEXT NULL,
    base_hash CHAR(40) NOT NULL DEFAULT '', envoye_le DATETIME NULL, envoye_par VARCHAR(120) NOT NULL DEFAULT '',
    signe_le DATETIME NULL, signe_nom VARCHAR(160) NOT NULL DEFAULT '', signe_mention VARCHAR(60) NOT NULL DEFAULT '', signe_ip VARCHAR(60) NOT NULL DEFAULT '', signe_ua VARCHAR(255) NOT NULL DEFAULT '',
    signature MEDIUMTEXT NULL, signe_test TINYINT NOT NULL DEFAULT 0, empreinte CHAR(64) NOT NULL DEFAULT '', journal MEDIUMTEXT NULL, created_at DATETIME NOT NULL,
    UNIQUE KEY (animatrice_id, devis_id, avenant), INDEX (devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // Preuve de signature : PDF signé figé (chiffré) avec son empreinte, et dossier de preuve.
  if (!in_array('pdf_sha256', db()->query("SHOW COLUMNS FROM contrats")->fetchAll(PDO::FETCH_COLUMN), true))
    db()->exec("ALTER TABLE contrats ADD COLUMN preuve MEDIUMTEXT NULL, ADD COLUMN pdf_fichier VARCHAR(80) NOT NULL DEFAULT '', ADD COLUMN pdf_sha256 CHAR(64) NOT NULL DEFAULT ''");
  db()->exec("CREATE TABLE IF NOT EXISTS verifications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, le DATETIME NOT NULL, email VARCHAR(190) NOT NULL DEFAULT '',
    ip VARCHAR(60) NOT NULL DEFAULT '', INDEX (animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS contrats_dpae (animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NOT NULL, fait_le DATE NOT NULL, pour_le DATE NULL, numero VARCHAR(60) NOT NULL DEFAULT '',
    par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, PRIMARY KEY (animatrice_id, devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS contrats_brouillons (animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NOT NULL, params MEDIUMTEXT NULL, PRIMARY KEY (animatrice_id, devis_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function c_num(string $v): float { return (float)str_replace([',', ' '], ['.', ''], $v); }
function c_eur(float $v): string { return number_format($v, 2, ',', ' ') . ' €'; }
function c_h(float $h): string { return number_format($h, 2, ',', ''); }

/** Journées de la personne sur l'opération, d'après le planning (créneaux annulés exclus). */
function c_live(int $aid, array $d, float $taux): array {
  c_schema(); require_once __DIR__ . '/regles_lib.php'; $pole = g_pole($d); $R = rg_regles($pole);
c_schema(); $st = db()->prepare("SELECT date_mission AS date, debut, fin, pause, magasin, zone, ligne FROM missions WHERE animatrice_id = ? AND devis_id = ? AND statut <> 'annulee' ORDER BY date_mission, debut");
  $st->execute([$aid, $d['id']]); $rows = $st->fetchAll();
  // Journée où la personne était absente puis remplacée : elle reste au contrat (ce n'est pas un changement de planning), mais n'est pas payée.
  $ab = db()->prepare("SELECT m.date_mission AS date, m.debut, m.fin, m.pause, m.magasin, m.zone, m.ligne FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id
    WHERE pp.animatrice_id = ? AND m.devis_id = ? AND pp.statut_admin = 'absent' AND (m.animatrice_id IS NULL OR m.animatrice_id <> pp.animatrice_id) AND m.statut <> 'annulee'");
  $ab->execute([$aid, $d['id']]); $rows = array_merge($rows, $ab->fetchAll()); usort($rows, fn($a, $b) => [$a['date'], $a['debut']] <=> [$b['date'], $b['debut']]); $out = [];
  foreach ($rows as $m) {
    $J = rg_jour($m['date'], $m['debut'], $m['fin'], (int)$m['pause'], (string)$m['magasin'], $m['zone'] ?: (string)$d['zone'], $pole, $R);
    $out[] = ['date' => $m['date'], 'debut' => $m['debut'], 'fin' => $m['fin'], 'pause' => (int)$m['pause'], 'magasin' => $m['magasin'], 'heures' => $J['heures'],
      'maj' => $J['maj'], 'maj_txt' => $J['txt'], 'eq' => $J['eq'], 'h_dim' => $J['h_dim'], 'h_ferie' => $J['h_ferie'], 'h_nuit' => $J['h_nuit'], 'h_zti' => $J['h_zti'],
      'salaire' => round(($J['heures'] + $J['eq']) * $taux, 2)] + ['role' => (string)($d['lignes'][(int)$m['ligne']]['role'] ?? '')];
  }
  return $out;
}
function c_hash(array $L): string { return sha1(json_encode(array_map(fn($l) => [$l['date'], $l['debut'], $l['fin'], (int)$l['pause'], $l['magasin']], $L))); }

/** Identité de la personne : appli + fiche (+ corrections de l'admin). */
function c_salarie(int $aid, array $over = []): array {
  $st = db()->prepare("SELECT id, prenom, nom, telephone, email, zone FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch() ?: [];
  $f = (array)fiche_get($aid)['data'];
  $mt = db()->prepare("SELECT matricule FROM animatrices WHERE id = ?"); $mt->execute([$aid]);
  $s = ['matricule' => (string)$mt->fetchColumn(), 'civilite' => $f['civilite'] ?? '', 'prenom' => mb_convert_case(mb_strtolower($a['prenom'] ?? ''), MB_CASE_TITLE), 'nom' => mb_strtoupper($a['nom'] ?? ''), 'adresse' => $f['adresse'] ?? '',
    'code_postal' => $f['code_postal'] ?? '', 'ville' => $f['ville'] ?? '', 'secu' => $f['secu'] ?? '', 'telephone' => $a['telephone'] ?? '', 'nationalite' => $f['nationalite'] ?? '',
    'naissance_date' => $f['naissance_date'] ?? '', 'naissance_lieu' => $f['naissance_lieu'] ?? '', 'email' => $a['email'] ?? '', 'nom_naissance' => $f['nom_naissance'] ?? ''];
  foreach ($over as $k => $v) if (array_key_exists($k, $s) && $v !== '') $s[$k] = $v;
  return $s;
}
function c_manque(array $s): array {
  $lab = ['civilite' => 'civilité', 'adresse' => 'adresse', 'ville' => 'ville', 'secu' => 'n° de sécurité sociale', 'nationalite' => 'nationalité', 'naissance_date' => 'date de naissance', 'naissance_lieu' => 'lieu de naissance'];
  return array_values(array_map(fn($k) => $lab[$k], array_filter(array_keys($lab), fn($k) => trim((string)$s[$k]) === '')));
}
function c_brouillon(int $aid, int $devis): array { c_schema(); $st = db()->prepare("SELECT params FROM contrats_brouillons WHERE animatrice_id = ? AND devis_id = ?"); $st->execute([$aid, $devis]); return json_decode((string)$st->fetchColumn(), true) ?: []; }

/** Adresse de départ propre à l'opération (null : domicile habituel de la fiche). */
function c_depart_ok($d): ?array {
  if (!is_array($d)) return null; $d = ['adresse' => trim((string)($d['adresse'] ?? '')), 'code_postal' => trim((string)($d['code_postal'] ?? '')), 'ville' => trim((string)($d['ville'] ?? ''))];
  return ($d['adresse'] !== '' || $d['ville'] !== '') ? $d : null;
}
function c_adr(array $d): string { return trim(trim((string)($d['adresse'] ?? '')) . ', ' . trim(($d['code_postal'] ?? '') . ' ' . ($d['ville'] ?? '')), ', '); }
/** Heures travaillées par date (pour les repas : un par journée de plus de 6 h). */
function c_par_jour(array $L): array { $h = []; foreach ($L as $l) $h[$l['date']] = ($h[$l['date']] ?? 0) + (float)$l['heures']; return $h; }
/** Journées partagées entre plusieurs points de vente : trajet réel domicile → A → B → domicile, au lieu de deux allers-retours.
 *  Renvoie par date : km comptés (somme des A/R), km réels, et l'écart à appliquer. */
function c_circuits(array $L, array $kmMag): array {
  require_once __DIR__ . '/geo_lib.php'; $par = [];
  foreach ($L as $l) $par[$l['date']][] = $l;
  $out = [];
  foreach ($par as $d => $ls) { usort($ls, fn($a, $b) => strcmp((string)$a['debut'], (string)$b['debut'])); $mags = []; foreach ($ls as $l) if (end($mags) !== (string)$l['magasin']) $mags[] = (string)$l['magasin'];
    if (count(array_unique($mags)) < 2) continue;
    $compte = 0; foreach (array_unique($mags) as $m) { if (($kmMag[$m] ?? null) === null) continue 2; $compte += (float)$kmMag[$m]; }
    $reel = (float)$kmMag[$mags[0]] / 2 + (float)$kmMag[end($mags)] / 2;
    for ($i = 1; $i < count($mags); $i++) { $a = geo_magasin($mags[$i - 1]); $b = geo_magasin($mags[$i]); if (!$a || !$a['pos'] || !$b || !$b['pos']) continue 2; $reel += geo_route($a['pos'], $b['pos'])['km']; }
    $out[$d] = ['mags' => $mags, 'compte' => round($compte, 1), 'reel' => round($reel, 1), 'ecart' => round($reel - $compte, 1)];
  }
  return $out;
}
/** Trajets aller-retour adresse de départ ↔ points de vente, indemnités kilométriques et repas estimés sur les journées du contrat. */
function c_trajets(int $aid, array $P, array $L, array $S): array {
  require_once __DIR__ . '/geo_lib.php'; $r = g_reg(); $GLOBALS['GEO_MAX'] = max((int)($GLOBALS['GEO_MAX'] ?? 25), 60);
  $dep = c_depart_ok($P['depart'] ?? null) ?: ['adresse' => (string)($S['adresse'] ?? ''), 'code_postal' => (string)($S['code_postal'] ?? ''), 'ville' => (string)($S['ville'] ?? '')];
  $jours = []; foreach ($L as $l) $jours[(string)$l['magasin']][$l['date']] = true;
  $T = []; if ($jours) try { $T = geo_trajets($aid, array_keys($jours), $dep); } catch (Throwable $e) { error_log('Trajets contrat : ' . $e->getMessage()); }
  $F = g_frais((string)($P['pole'] ?? 'anim'), $r); $tk = $F['km']; $rows = []; $km = 0; $eur = 0; $inc = false; $approx = false;
  $tc = ($P['transport'] ?? '') === 'tc'; $tcj = round((float)($P['tc_jour'] ?? 0), 2);
  foreach ($jours as $m => $dd) {
    if ($tc) { $n = count($dd); $eur += $tcj * $n; if ($tcj <= 0) $inc = true; // transports en commun : une somme par journée (aller-retour), pas de km
      $rows[] = ['magasin' => $m, 'km_ar' => null, 'km_calc' => $T[$m]['km_ar'] ?? null, 'manuel' => false, 'jour' => $tcj ?: null, 'jours' => $n, 'total' => $tcj ? round($tcj * $n, 2) : null, 'dest' => $T[$m]['dest'] ?? $m]; continue; }
    $t = $T[$m] ?? null; $n = count($dd); $ar = $t['km_ar'] ?? null; $calc = $ar;
    $man = $P['km_man'][$m] ?? null; if ($man !== null && $man !== '') { $ar = (float)$man; $t = ['approx' => false]; } // km relevés sur Mappy par l'agence
    $manu = $man !== null && $man !== ''; $ano = $manu ? '' : c_km_anormal($ar, (string)$m, $dep); // distance anormale : non comptée tant que l'agence n'a pas vérifié (km Mappy)
    $j = $ar === null || $ano !== '' ? null : round($ar * $tk, 2);
    if ($ar === null || $ano !== '') $inc = true; else { $km += $ar * $n; $eur += $j * $n; } if (!empty($t['approx'])) $approx = true;
    $rows[] = ['magasin' => $m, 'km_ar' => $ano !== '' ? null : $ar, 'km_calc' => $calc, 'manuel' => $manu, 'anormal' => $ano, 'jour' => $j, 'jours' => $n, 'total' => $j === null ? null : round($j * $n, 2), 'dest' => $t['dest'] ?? ($T[$m]['dest'] ?? $m)];
  }
  if (!$tc) { $kmM = []; foreach ($rows as $x) $kmM[$x['magasin']] = $x['km_ar'];
    foreach (c_circuits($L, $kmM) as $d => $c) { $j = round($c['ecart'] * $tk, 2); $km += $c['ecart']; $eur += $j;
      $rows[] = ['magasin' => 'Journée partagée le ' . g_date($d) . ' (' . implode(' → ', $c['mags']) . ')', 'circuit' => true, 'km_ar' => $c['ecart'], 'km_calc' => $c['reel'], 'manuel' => false, 'anormal' => '', 'jour' => $j, 'jours' => 1, 'total' => $j, 'dest' => '']; } }
  $pj = c_par_jour($L); $nr = count(array_filter($pj, fn($x) => $x > $F['seuil'])); $rp = $F['repas'];
  $np = $F['panier'] > 0 ? g_jours_tardifs($L, $F['panier_apres']) : 0; $nt = $F['tenue'] > 0 ? count($pj) : 0; $ex = round($np * $F['panier'] + $nt * $F['tenue'], 2);
  return ['mode' => $tc ? 'tc' : 'voiture', 'tc_jour' => $tcj, 'depart' => c_adr($dep), 'manuel' => (bool)array_filter(array_column($rows, 'manuel')), 'perso' => (bool)c_depart_ok($P['depart'] ?? null), 'taux' => $tk, 'lignes' => $rows, 'km' => $km, 'km_eur' => round($eur, 2), 'incomplet' => $inc, 'approx' => $approx,
    'repas_jours' => $nr, 'repas' => $rp, 'repas_eur' => round($nr * $rp, 2), 'repas_seuil' => $F['seuil'],
    'panier' => $F['panier'], 'panier_apres' => $F['panier_apres'], 'panier_jours' => $np, 'tenue' => $F['tenue'], 'tenue_jours' => $nt, 'extra' => $ex, 'total' => round($eur + $nr * $rp + $ex, 2)];
}

/** Date d'entrée dans l'agence pour le coefficient : 1re mission des personnes arrivées après la mise en place de la règle (les anciens restent au coefficient confirmé). */
function c_entree(int $aid): ?string {
  $lim = (int)(g_reg()['ct_coef_id'] ?? 0); if (!$lim || $aid <= $lim) return null;
  $st = db()->prepare("SELECT MIN(date_mission) FROM missions WHERE animatrice_id = ? AND statut <> 'annulee' AND devis_id IS NOT NULL"); $st->execute([$aid]);
  return $st->fetchColumn() ?: null;
}
/** Coefficient : débutant(e) (120) dès le 1er jour, confirmé (140) six mois plus tard jour pour jour, d'après la 1re journée du contrat. */
function c_coef(int $aid, array $L): array {
  $r = g_reg(); $e = c_entree($aid); $prem = $L ? min(array_column($L, 'date')) : date('Y-m-d');
  if (!$e) return ['coef' => (string)$r['ct_coef'], 'entree' => null, 'passage' => null];
  $pass = date('Y-m-d', strtotime($e . ' +' . max(1, (int)$r['ct_coef_mois']) . ' months'));
  return ['coef' => (string)($prem < $pass ? $r['ct_coef_debut'] : $r['ct_coef']), 'entree' => $e, 'passage' => $pass];
}
/** Distance anormale : domicile et point de vente dans deux zones différentes, ou aller-retour au-delà du seuil des réglages. */
function c_km_anormal($ar, string $mag, array $dep): string {
  $r = g_reg(); $zd = zone_cp((string)($dep['code_postal'] ?? ''));
  $st = db()->prepare("SELECT zone FROM magasins WHERE nom = ? LIMIT 1"); $st->execute([$mag]); $zm = (string)$st->fetchColumn();
  if ($zd && $zm && $zd !== $zm) return 'domicile en ' . ($zd === 'France' ? 'métropole' : $zd) . ', point de vente en ' . ($zm === 'France' ? 'métropole' : $zm);
  $max = (float)str_replace(',', '.', (string)($r['km_alerte'] ?? '250'));
  if ($ar !== null && $max > 0 && (float)$ar > $max) return number_format((float)$ar, 0, ',', ' ') . ' km aller-retour par jour';
  return '';
}

/** Conditions du contrat : réglages + corrections propres à ce contrat. */
function c_params(int $aid, array $d, array $L): array {
  $r = g_reg(); $o = c_brouillon($aid, (int)$d['id']);
  $mags = array_values(array_unique(array_filter(array_column($L, 'magasin'))));
  $lieux = []; foreach ($mags as $m) { $st = db()->prepare("SELECT ville, zone FROM magasins WHERE nom = ? LIMIT 1"); $st->execute([$m]); $x = $st->fetch(); $lieux[] = $m . ($x && $x['ville'] ? ', ' . $x['ville'] : '') . ', ' . ($x['zone'] ?? $d['zone'] ?: 'France'); }
  $n = count(array_unique(array_column($L, 'date')));
  $pole = g_pole($d);
  return [
    'pole' => $pole, 'taux' => max(g_smic($r), c_num($o['taux'] ?? ($pole === 'hot' ? $r['hot_taux'] : $r['ct_taux']))), 'essai' => (int)($o['essai'] ?? max(1, min(14, intdiv($n, 5)))), 'coef' => (string)($o['coef'] ?? c_coef($aid, $L)['coef']), 'coef_regle' => c_coef($aid, $L), 'poste' => $r['ct_poste'],
    'client' => $o['client'] ?? ($d['client']['raison'] ?? ''), 'operation' => $o['operation'] ?? ($d['objet'] ?: 'Opération ' . $d['numero']), 'devis' => (string)$d['numero'],
    'magasins' => $mags, 'lieux' => $lieux, 'representant' => $r['ct_representant'], 'qualite' => $r['ct_qualite'], 'lieu' => $r['ct_lieu'], 'urssaf' => $r['ct_urssaf'], 'caisse' => $r['ct_caisse'], 'retraite' => trim((string)($r['ct_retraite'] ?? '')) ?: $r['ct_caisse'],
    'salarie_over' => $o['salarie'] ?? [], 'depart' => c_depart_ok($o['depart'] ?? null), 'km_man' => (array)($o['km_man'] ?? []), 'transport' => ($o['transport'] ?? '') === 'tc' ? 'tc' : 'voiture', 'tc_jour' => round((float)($o['tc_jour'] ?? 0), 2),
  ];
}

/** État du contrat d'une personne sur une opération. */
function c_etat(int $aid, int $devis): array {
  c_schema(); $d = g_doc($devis); if (!$d) return ['etat' => 'aucun'];
  $st = db()->prepare("SELECT * FROM contrats WHERE animatrice_id = ? AND devis_id = ? ORDER BY avenant"); $st->execute([$aid, $devis]); $V = $st->fetchAll();
  $P = c_params($aid, $d, []); $L = c_live($aid, $d, $P['taux']); $P = c_params($aid, $d, $L); $h = c_hash($L); if ($P['depart']) $h = sha1($h . json_encode($P['depart'], JSON_UNESCAPED_UNICODE)); if ($P['km_man']) $h = sha1($h . json_encode($P['km_man'], JSON_UNESCAPED_UNICODE)); if ($P['transport'] === 'tc') $h = sha1($h . 'tc' . $P['tc_jour']); // changer l'adresse de départ après signature donne un avenant
  $last = $V ? end($V) : null; $signe = null; foreach ($V as $v) if ($v['statut'] === 'signe') $signe = $v;
  if (!$last) $etat = $L ? 'a_envoyer' : 'aucun';
  elseif ($last['statut'] === 'envoye') $etat = $last['base_hash'] === $h ? 'envoye' : 'a_mettre_a_jour';
  else $etat = $last['base_hash'] === $h ? 'signe' : 'avenant';
  $sal = c_salarie($aid, $P['salarie_over']);
  // Déménagement depuis l'envoi : le domicile du contrat n'est plus le bon. Avenant (ou contrat renvoyé) s'il reste des journées à venir, et km recalculés.
  $adr = null;
  if ($last) { $ls = json_decode((string)$last['salarie'], true) ?: []; $av = c_adr($ls); $ap = c_adr($sal);
    if ($ap !== '' && $av !== '' && mb_strtolower(preg_replace('/\s+/', ' ', $av)) !== mb_strtolower(preg_replace('/\s+/', ' ', $ap))) {
      $adr = ['avant' => $av, 'apres' => $ap, 'le' => $last['envoye_le']];
      if (array_filter($L, fn($l) => $l['date'] >= date('Y-m-d'))) { if ($etat === 'signe') $etat = 'avenant'; elseif ($etat === 'envoye') $etat = 'a_mettre_a_jour'; } } }
  return ['etat' => $etat, 'adresse_changee' => $adr, 'devis' => $d, 'lignes' => $L, 'params' => $P, 'salarie' => $sal, 'manque' => c_manque($sal), 'versions' => $V, 'last' => $last, 'signe' => $signe, 'hash' => $h];
}
/** DPAE (faite dans le TESE) : à faire avant la première journée, au plus tôt 8 jours avant. Alerte à 3 jours ; si la 1re date est avancée, une nouvelle DPAE est nécessaire. */
function c_dpae(int $aid, int $devis, array $L): array {
  c_schema(); $st = db()->prepare("SELECT fait_le, pour_le, numero, par FROM contrats_dpae WHERE animatrice_id = ? AND devis_id = ?"); $st->execute([$aid, $devis]); $r = $st->fetch() ?: null;
  $prem = $L ? $L[0]['date'] : null; $j = $prem ? (int)floor((strtotime($prem) - strtotime(date('Y-m-d'))) / 86400) : null;
  if (!$prem) $etat = $r ? 'ok' : 'sans';
  elseif ($r && (!$r['pour_le'] || $r['pour_le'] <= $prem)) $etat = 'ok';
  elseif ($r) $etat = $j < 0 ? 'retard' : ($j <= 3 ? 'urgent' : 'refaire'); // date avancée depuis la déclaration
  else $etat = $j < 0 ? 'retard' : ($j <= 3 ? 'urgent' : ($j <= 8 ? 'possible' : 'trop_tot'));
  return ['etat' => $etat, 'premiere' => $prem, 'jours' => $j, 'fait_le' => $r['fait_le'] ?? null, 'pour_le' => $r['pour_le'] ?? null, 'numero' => $r['numero'] ?? '', 'par' => $r['par'] ?? '', 'avancee' => $r && $prem && $r['pour_le'] && $r['pour_le'] > $prem];
}
const C_ETATS = ['aucun' => 'Aucune date', 'a_envoyer' => 'À envoyer', 'envoye' => 'Envoyé, à signer', 'a_mettre_a_jour' => 'Planning changé : à renvoyer', 'signe' => 'Signé', 'avenant' => 'Signé, avenant à envoyer'];

/** Envoi : premier contrat, contrat mis à jour (pas encore signé) ou avenant (après signature). */
function c_envoyer(int $aid, int $devis): array {
  $rg = g_reg(); [$k, $nm] = g_pole(g_doc($devis)) === 'hot' ? ['hot', 'CDDU'] : ['ct', 'CIDD'];
  if (trim((string)$rg[$k . '_modele']) === '') throw new RuntimeException('Contrat ' . $nm . ' : saisissez d\'abord le modèle de contrat de votre agence dans Réglages > Contrat ' . $nm . '.');
  if (!$rg[$k . '_valide']) throw new RuntimeException('Contrat ' . $nm . ' : cochez dans Réglages > Contrat ' . $nm . ' que votre modèle a été validé par votre avocat.');
  $E = c_etat($aid, $devis); if (in_array($E['etat'], ['aucun', 'envoye', 'signe'], true) && $E['etat'] !== 'envoye') throw new RuntimeException($E['etat'] === 'signe' ? 'Contrat déjà signé, rien de changé.' : 'Aucune date pour cette personne.');
  $pdo = db(); $now = now(); $last = $E['last'];
  $E['params']['trajets'] = c_trajets($aid, $E['params'], $E['lignes'], $E['salarie']); // figés dans le document envoyé
  $snap = [fiche_data_json($E['salarie']), json_encode($E['params'], JSON_UNESCAPED_UNICODE), json_encode($E['lignes'], JSON_UNESCAPED_UNICODE), $E['hash'], $now, admin_nom()];
  if (!$last) {
    $r = g_reg(); $max = (int)$pdo->query("SELECT COALESCE(MAX(numero), 0) FROM contrats")->fetchColumn(); $num = max($max, (int)$r['ct_dernier']) + 1;
    $pdo->prepare("INSERT INTO contrats (animatrice_id, devis_id, numero, avenant, statut, salarie, params, lignes, base_hash, envoye_le, envoye_par, created_at) VALUES (?,?,?,0,'envoye',?,?,?,?,?,?,?)")
      ->execute(array_merge([$aid, $devis, $num], $snap, [$now])); $id = (int)$pdo->lastInsertId(); c_journal($id, 'Contrat n°' . $num . ' envoyé par ' . admin_nom());
  } elseif ($last['statut'] === 'envoye') {
    $pdo->prepare("UPDATE contrats SET salarie = ?, params = ?, lignes = ?, base_hash = ?, envoye_le = ?, envoye_par = ? WHERE id = ?")->execute(array_merge($snap, [$last['id']]));
    $id = (int)$last['id']; c_journal($id, ($last['avenant'] ? 'Avenant' : 'Contrat') . ' mis à jour et renvoyé par ' . admin_nom());
  } else {
    $pdo->prepare("INSERT INTO contrats (animatrice_id, devis_id, numero, avenant, statut, salarie, params, lignes, precedent, base_hash, envoye_le, envoye_par, created_at) VALUES (?,?,?,?,'envoye',?,?,?,?,?,?,?,?)")
      ->execute([$aid, $devis, $last['numero'], $last['avenant'] + 1, $snap[0], $snap[1], $snap[2], $last['lignes'], $snap[3], $now, $snap[5], $now]);
    $id = (int)$pdo->lastInsertId(); c_journal($id, 'Avenant n°' . ($last['avenant'] + 1) . ' envoyé par ' . admin_nom());
  }
  return c_row($id);
}
function c_row(int $id): ?array { c_schema(); $st = db()->prepare("SELECT * FROM contrats WHERE id = ?"); $st->execute([$id]); $c = $st->fetch(); if (!$c) return null;
  foreach (['salarie', 'params', 'lignes', 'precedent', 'preuve'] as $k) $c[$k] = json_decode((string)$c[$k], true) ?: []; if (($c['salarie']['secu'] ?? '') !== '') $c['salarie']['secu'] = secu_clair($c['salarie']['secu']); $c['journal'] = json_decode((string)$c['journal'], true) ?: []; return $c; }
function c_journal(int $id, string $t): void { $st = db()->prepare("SELECT journal FROM contrats WHERE id = ?"); $st->execute([$id]); $j = json_decode((string)$st->fetchColumn(), true) ?: [];
  array_unshift($j, ['le' => now(), 'texte' => mb_substr($t, 0, 400)]); db()->prepare("UPDATE contrats SET journal = ? WHERE id = ?")->execute([json_encode(array_slice($j, 0, 60), JSON_UNESCAPED_UNICODE), $id]); }
function c_titre(array $c): string { return ($c['avenant'] ? 'Avenant n°' . $c['avenant'] . ' au contrat n°' : 'Contrat n°') . $c['numero']; }
function c_empreinte(array $c, string $nom, string $le): string { return hash('sha256', json_encode([$c['id'], $c['numero'], $c['avenant'], $c['salarie'], $c['params']['taux'] ?? 0, $c['lignes'], $nom, $le], JSON_UNESCAPED_UNICODE)); }
function c_fichier(array $c): string { return preg_replace('/[^A-Za-z0-9_.-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', ($c['avenant'] ? 'Avenant_' . $c['avenant'] . '_contrat_' : 'Contrat_') . $c['numero'] . '_' . ($c['salarie']['prenom'] ?? '') . '_' . ($c['salarie']['nom'] ?? ''))) . '.pdf'; }

// ---------- Rendu PDF ----------
/** Signature scannée du gérant (dossier protégé, jamais servie sur le web). */
function c_sig_gerant(): string { $f = __DIR__ . '/prive/signature-gerant.png'; return is_file($f) ? '<img src="data:image/png;base64,' . base64_encode((string)file_get_contents($f)) . '" style="height:88px;margin:4pt 0">' : ''; }
/** Emploi du contrat CDDU : le poste le plus fréquent des journées (rôle de la prestation du devis), accordé. */
function c_emploi_hot(array $L, bool $F, bool $M, string $defaut = ''): string {
  $n = []; foreach ($L as $l) if (($l['role'] ?? '') !== '') $n[$l['role']] = ($n[$l['role']] ?? 0) + 1;
  arsort($n); $e = (string)(array_key_first($n) ?? ($defaut !== '' ? $defaut : 'Hôte(sse) d\'accueil'));
  if ($F) return preg_replace(['/teur\(trice\)/u', '/eur\(drice\)/u', '/eur\(se\)/u', '/\(sse\)/u', '/\(e\)/u', '/\([^)]*\)/u'], ['trice', 'rice', 'euse', 'sse', 'e', ''], $e);
  if ($M) return preg_replace('/\([^)]*\)/u', '', $e);
  return $e;
}
/** Corps d'un contrat à partir d'un modèle texte : « ## Titre » ouvre un article, « - » fait une puce, {repère} est remplacé ; {horaires} et {frais} insèrent les tableaux. */
function c_corps_modele(string $modele, callable $A, callable $p, array $V, string $tab, string $frais): string {
  $rep = []; foreach ($V as $k => $v) $rep['{' . $k . '}'] = $v;
  $out = ''; $titre = null; $buf = ''; $ul = [];
  $flushUl = function () use (&$ul, &$buf) { if ($ul) { $buf .= '<ul>' . implode('', array_map(fn($x) => '<li>' . $x . '</li>', $ul)) . '</ul>'; $ul = []; } };
  $flush = function () use (&$titre, &$buf, &$out, $A, $flushUl) { $flushUl(); if ($titre !== null && trim($buf) !== '') $out .= $A($titre, $buf); elseif (trim($buf) !== '') $out .= $buf; $titre = null; $buf = ''; };
  foreach (preg_split('/\R/', $modele) as $ln) {
    $t = trim($ln);
    if (str_starts_with($t, '## ')) { $flush(); $titre = trim(substr($t, 3)); continue; }
    if (preg_match('/^article\s+\d+\s*(?:er)?\s*[-–—:.·]?\s*(.{2,120})$/iu', $t, $mm)) { $flush(); $titre = trim($mm[1]); continue; } // « Article 3 - Durée du contrat » collé depuis Word
    if ($t === '') { $flushUl(); continue; }
    if ($t === '{horaires}') { $flushUl(); $buf .= $tab; continue; }
    if ($t === '{frais}') { $flushUl(); if ($frais !== '') { $flush(); $out .= $A('Adresse de départ et frais de déplacement', $frais); } continue; }
    $x = strtr(h($t), array_combine(array_map('h', array_keys($rep)), array_keys($rep))); $x = strtr($x, $rep); // texte échappé, repères remplacés par leur valeur (déjà sûre)
    if (preg_match('/^[-•–]\s/u', $t)) { $ul[] = ltrim(preg_replace('/^[-•–]\s*/u', '', $x)); continue; }
    $flushUl(); $buf .= $p($x);
  }
  $flush();
  return $out;
}

function c_render(array $c): string {
  $r = g_reg(); $S = $c['salarie']; $P = $c['params']; $L = $c['lignes']; $av = (int)$c['avenant'];
  $T = ['p' => ag_couleur(), 'esp' => '#1A1512', 'sand' => '#F6F1EA', 'line' => '#E6DDD2', 'mut' => '#857869'];
  $F = ($S['civilite'] ?? '') === 'Mme'; $M = ($S['civilite'] ?? '') === 'M.';
  $Sal = $F ? 'la Salariée' : ($M ? 'le Salarié' : 'le(a) Salarié(e)'); $SalM = ucfirst($Sal); $e = $F ? 'e' : ($M ? '' : '(e)');
  $emploi = $F ? 'animatrice commerciale' : ($M ? 'animateur commercial' : 'animateur(trice) commercial(e)');
  $nomS = trim(($S['civilite'] ? $S['civilite'] . ' ' : '') . $S['prenom'] . ' ' . $S['nom']);
  $d1 = $L ? $L[0] : null; $d2 = $L ? end($L) : null; $nj = count(array_unique(array_column($L, 'date')));
  $ff = ''; foreach (['' => ['normal', 'normal'], '-Bold' => ['bold', 'normal'], '-Italic' => ['normal', 'italic']] as $sx => [$w, $st])
    if (is_file(__DIR__ . "/assets/fonts/Figtree$sx.ttf")) $ff .= "@font-face{font-family:'Figtree';font-weight:$w;font-style:$st;src:url('assets/fonts/Figtree$sx.ttf') format('truetype')}";
  $font = $ff ? "'Figtree','DejaVu Sans',sans-serif" : "'DejaVu Sans',sans-serif";
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:44px">' : '<b>' . h($r['societe']) . '</b>';
  $A = function (string $t, string $body) use (&$n) { $n++; return '<div class="art"><div class="at">Article ' . $n . ' · ' . h($t) . '</div>' . $body . '</div>'; }; $n = 0;
  $p = fn($s) => '<p>' . $s . '</p>';
  // Tableau des horaires
  $rows = ''; $tot = 0; $th = 0; $cur = null;
  foreach ($L as $l) {
    if ($l['magasin'] !== $cur) { $cur = $l['magasin']; $rows .= '<tr><td colspan="5" class="mg">' . h($cur ?: 'Point de vente à préciser') . '</td></tr>'; }
    $rows .= '<tr><td>' . g_date($l['date']) . ($l['maj'] ? ' <span class="mj">' . h((string)($l['maj_txt'] ?? '') ?: '+100 %') . '</span>' : '') . '</td><td>' . h($l['debut']) . ' à ' . h($l['fin']) . '</td><td>' . ($l['pause'] ? g_dur((int)$l['pause']) : '') . '</td><td class="n">' . c_h($l['heures']) . ' h</td><td class="n">' . c_eur($l['salaire']) . '</td></tr>';
    $tot += $l['salaire']; $th += $l['heures'];
  }
  $rows .= '<tr class="tt"><td colspan="3">' . $nj . ' jour' . ($nj > 1 ? 's' : '') . ' · rôle : ' . h(c_emploi_hot($L, $F, $M, ($P['pole'] ?? 'anim') === 'hot' ? '' : (string)$P['poste'])) . '</td><td class="n">' . c_h($th) . ' h</td><td class="n">' . c_eur($tot) . '</td></tr>';
  $tab = '<table class="hr"><tr><th>Jour</th><th>Horaires</th><th>Pause</th><th class="n">Heures</th><th class="n">Salaire brut</th></tr>' . $rows . '</table>';
  $duree = $L ? 'conclu pour une durée de ' . $nj . ' jour(s), prend effet le ' . g_date($d1['date']) . ' à ' . h($d1['debut']) . ', et prendra fin automatiquement à l\'échéance du terme, soit le ' . g_date($d2['date']) . ' à ' . h($d2['fin']) . '.' : 'ne comporte plus aucune journée de travail.';
  // Adresse de départ et frais de déplacement (estimation figée à l'envoi)
  $Tj = $P['trajets'] ?? null; $frais = '';
  if ($Tj && $L) {
    $km = fn($v) => number_format((float)$v, 0, ',', ' ') . ' km'; $tr = '';
    foreach ($Tj['lignes'] as $t) $tr .= '<tr><td>' . h($t['magasin'] ?: 'Point de vente à préciser') . '</td><td class="n">' . ($t['km_ar'] === null ? 'à préciser' : $km($t['km_ar'])) . '</td><td class="n">' . ($t['jour'] === null ? '' : c_eur($t['jour'])) . '</td><td class="n">' . $t['jours'] . '</td><td class="n">' . ($t['total'] === null ? '' : c_eur($t['total'])) . '</td></tr>';
    $sj = str_replace('.', ',', (string)(float)($Tj['repas_seuil'] ?? 6));
    $tr .= '<tr><td colspan="3">Repas : ' . c_eur($Tj['repas']) . ' par journée de plus de ' . $sj . ' h de travail</td><td class="n">' . $Tj['repas_jours'] . '</td><td class="n">' . c_eur($Tj['repas_eur']) . '</td></tr>';
    if (!empty($Tj['panier_jours'])) $tr .= '<tr><td colspan="3">Panier de nuit : ' . c_eur($Tj['panier']) . ' par service terminé après ' . h($Tj['panier_apres']) . '</td><td class="n">' . $Tj['panier_jours'] . '</td><td class="n">' . c_eur($Tj['panier'] * $Tj['panier_jours']) . '</td></tr>';
    if (!empty($Tj['tenue_jours'])) $tr .= '<tr><td colspan="3">Entretien de la tenue : ' . c_eur($Tj['tenue']) . ' par journée</td><td class="n">' . $Tj['tenue_jours'] . '</td><td class="n">' . c_eur($Tj['tenue'] * $Tj['tenue_jours']) . '</td></tr>';
    $tr .= '<tr class="tt"><td colspan="3">Total estimé des frais' . ($Tj['km'] ? ' · ' . $km($Tj['km']) . ' au total' : '') . '</td><td></td><td class="n">' . c_eur($Tj['total']) . '</td></tr>';
    $frais = $p('Pour la présente mission, l\'adresse de départ retenue pour le calcul des indemnités kilométriques est : <b>' . h($Tj['depart'] ?: 'à préciser') . '</b>'
        . ($Tj['perso'] ? '. Il s\'agit d\'une adresse déclarée par ' . $Sal . ' pour cette mission, distincte de son domicile habituel indiqué en tête du présent contrat, qui reste l\'adresse de référence du contrat.' : ', soit le domicile habituel indiqué en tête du présent contrat.'))
      . $p('Distances aller-retour par la route (itinéraire le plus court' . (!empty($Tj['manuel']) ? ', relevé sur Mappy' : '') . ') entre l\'adresse de départ et chaque point de vente, indemnisées à ' . h(str_replace('.', ',', (string)(float)($Tj['taux'] ?? c_num($r['km'])))) . ' €/km si ' . $Sal . ' utilise son véhicule :')
      . '<table class="hr"><tr><th>Point de vente</th><th class="n">Km aller-retour par jour</th><th class="n">Indemnité par jour</th><th class="n">Jours</th><th class="n">Total estimé</th></tr>' . $tr . '</table>'
      . $p('Ces montants sont une estimation : les frais sont versés sur la base des journées réellement effectuées, avec le salaire du mois concerné.' . ($Tj['incomplet'] ? ' Une distance reste à préciser, faute d\'adresse complète.' : '') . ' Tout changement d\'adresse de départ doit être signalé à ' . AG_NOM . ' avant la mission ; à défaut, l\'adresse ci-dessus fait foi.');
    if (($Tj['mode'] ?? '') === 'tc') { // transports en commun : une indemnité par journée au lieu des km
      $tr = ''; foreach ($Tj['lignes'] as $t) $tr .= '<tr><td>' . h($t['magasin'] ?: 'Point de vente à préciser') . '</td><td class="n">' . ($t['jour'] === null ? 'à préciser' : c_eur($t['jour'])) . '</td><td class="n">' . $t['jours'] . '</td><td class="n">' . ($t['total'] === null ? '' : c_eur($t['total'])) . '</td></tr>';
      $tr .= '<tr><td colspan="2">Repas : ' . c_eur($Tj['repas']) . ' par journée de plus de ' . $sj . ' h de travail</td><td class="n">' . $Tj['repas_jours'] . '</td><td class="n">' . c_eur($Tj['repas_eur']) . '</td></tr>';
      if (!empty($Tj['panier_jours'])) $tr .= '<tr><td colspan="2">Panier de nuit : ' . c_eur($Tj['panier']) . ' par service terminé après ' . h($Tj['panier_apres']) . '</td><td class="n">' . $Tj['panier_jours'] . '</td><td class="n">' . c_eur($Tj['panier'] * $Tj['panier_jours']) . '</td></tr>';
      if (!empty($Tj['tenue_jours'])) $tr .= '<tr><td colspan="2">Entretien de la tenue : ' . c_eur($Tj['tenue']) . ' par journée</td><td class="n">' . $Tj['tenue_jours'] . '</td><td class="n">' . c_eur($Tj['tenue'] * $Tj['tenue_jours']) . '</td></tr>';
      $tr .= '<tr class="tt"><td colspan="3">Total estimé des frais</td><td class="n">' . c_eur($Tj['total']) . '</td></tr>';
      $frais = $p('Pour la présente mission, ' . $Sal . ' se rend sur son lieu de travail en <b>transports en commun</b>, au départ de : <b>' . h($Tj['depart'] ?: 'à préciser') . '</b>'
          . ($Tj['perso'] ? ', adresse déclarée pour cette mission, distincte de son domicile habituel indiqué en tête du présent contrat, qui reste l\'adresse de référence du contrat.' : ', soit le domicile habituel indiqué en tête du présent contrat.'))
        . $p('Une indemnité de transport en commun de ' . c_eur((float)$Tj['tc_jour']) . ' par journée travaillée (trajet aller-retour) lui est versée, en lieu et place des indemnités kilométriques :')
        . '<table class="hr"><tr><th>Point de vente</th><th class="n">Transport par jour</th><th class="n">Jours</th><th class="n">Total estimé</th></tr>' . $tr . '</table>'
        . $p('Ces montants sont une estimation : les frais sont versés sur la base des journées réellement effectuées, avec le salaire du mois concerné. Tout changement de mode de transport ou d\'adresse de départ doit être signalé à ' . AG_NOM . ' avant la mission.');
    }
  }
  $mags = $P['magasins'] ? implode(', ', array_map('h', $P['magasins'])) : 'points de vente à préciser';
  $parties = '<table class="pt"><tr><td class="pb"><div class="k">L\'employeur</div><b>' . h(mb_convert_case(mb_strtolower($r['societe']), MB_CASE_TITLE)) . '</b><br>' . (trim((string)($r['ct_forme'] ?? '')) !== '' ? h($r['ct_forme']) . '<br>' : '') . nl2br(h($r['adresse'])) . '<br>Représentée par ' . h($P['representant']) . ', ' . h($P['qualite']) . '<div class="ci">Ci-après « l\'Employeur »</div></td>
    <td style="width:4%"></td><td class="pb"><div class="k">' . ($F ? 'La salariée' : ($M ? 'Le salarié' : 'Le(a) salarié(e)')) . '</div><b>' . h($nomS) . '</b>' . (($S['nom_naissance'] ?? '') !== '' && $S['nom_naissance'] !== $S['nom'] ? ' <span class="mut">(né' . $e . ' ' . h($S['nom_naissance']) . ')</span>' : '') . '<br>' . h($S['adresse']) . '<br>' . h(trim($S['code_postal'] . ' ' . $S['ville'])) . '<br>Matricule : ' . h($S['matricule'] ?? '') . '<br>N° SS : ' . h($S['secu'] ?: '…') . ' · Tél. : ' . h($S['telephone']) . '<br>Nationalité : ' . h($S['nationalite'] ?: '…') . '<br>Né' . $e . ' le ' . ($S['naissance_date'] ? g_date($S['naissance_date']) : '…') . ' à ' . h($S['naissance_lieu'] ?: '…') . '<div class="ci">Ci-après « ' . h($SalM) . ' »</div></td></tr></table>';
  $sigE = '<b>Pour ' . h(mb_convert_case(mb_strtolower($r['societe']), MB_CASE_TITLE)) . '</b><br>' . h($P['representant']) . ', ' . h($P['qualite']) . '<br>Lu et approuvé<br>' . c_sig_gerant() . '<br><span class="mut sm">Émis et signé électroniquement le ' . ($c['envoye_le'] ? date('d/m/Y', strtotime($c['envoye_le'])) : '…') . '</span>';
  if ($c['statut'] === 'signe') {
    $img = $c['signature'] && str_starts_with($c['signature'], 'data:image/png;base64,') ? '<img src="' . $c['signature'] . '" style="height:50px;margin:3pt 0">' : '';
    $sigS = '<i>« ' . h($c['signe_mention']) . ' »</i><br>' . $img . '<br><b>' . h($c['signe_nom']) . '</b>, le ' . date('d/m/Y à H:i', strtotime($c['signe_le'])) . '<br><span class="mut sm">Signature électronique interne ' . AG_COURT . ' · IP ' . h($c['signe_ip']) . '<br>Empreinte SHA-256 : ' . h($c['empreinte']) . '</span>' . ($c['signe_test'] ? '<br><b style="color:#B23B2E">TEST : signature d\'essai, sans valeur.</b>' : '');
  } else $sigS = '<span class="mut">Faire précéder la signature de la mention « lu et approuvé ».<br>À signer dans l\'appli ' . AG_COURT . ', rubrique « Mes contrats ».</span>';
  $sig = '<table class="sg"><tr><td class="pb"><div class="k">Signature de l\'Employeur</div>' . $sigE . '</td><td style="width:4%"></td><td class="pb"><div class="k">Signature ' . ($F ? 'de la Salariée' : ($M ? 'du Salarié' : 'du(de la) Salarié(e)')) . '</div>' . $sigS . '</td></tr></table>';
  $titre = $av ? 'AVENANT N°' . $av . ' AU CONTRAT N°' . $c['numero'] : 'CONTRAT N°' . $c['numero'];
  $hot = ($P['pole'] ?? 'anim') === 'hot';
  $nature = $hot ? 'contrat de travail à durée déterminée d\'usage' : 'contrat d\'intervention à durée déterminée d\'animation commerciale';
  $sous = ucfirst($nature) . ' · Opération ' . h($P['devis']);
  if ($av) {
    // Avenant : ce qui change par rapport à la version signée précédente.
    $k = fn($l) => $l['date'] . '|' . $l['magasin'] . '|' . $l['debut'] . '|' . $l['fin'] . '|' . $l['pause'];
    $avant = array_map($k, $c['precedent']); $apres = array_map($k, $L);
    $txt = fn($l) => g_date($l['date']) . ' · ' . h($l['magasin'] ?: 'point de vente à préciser') . ' · ' . h($l['debut']) . ' à ' . h($l['fin']);
    $ret = array_filter($c['precedent'], fn($l) => !in_array($k($l), $apres, true)); $aj = array_filter($L, fn($l) => !in_array($k($l), $avant, true));
    $body = $A('Objet de l\'avenant', $p('Le présent avenant modifie les journées de travail prévues au ' . $nature . ' n°' . $c['numero'] . ($av > 1 ? ' et à ses avenants précédents' : '') . ', conclu entre les parties dans le cadre de l\'opération « ' . h($P['operation']) . ' » pour le compte de la société ' . h($P['client']) . ', à la suite d\'une modification du planning de l\'opération.')
        . ($ret ? '<div class="sub">Journées retirées</div><ul>' . implode('', array_map(fn($l) => '<li>' . $txt($l) . '</li>', $ret)) . '</ul>' : '')
        . ($aj ? '<div class="sub">Journées ajoutées ou modifiées</div><ul>' . implode('', array_map(fn($l) => '<li>' . $txt($l) . '</li>', $aj)) . '</ul>' : ''))
      . $A('Durée du contrat', $p('L\'article 4 du contrat est modifié comme suit : le contrat, ' . $duree))
      . $A('Durée de travail', $p('L\'article 9 du contrat est modifié comme suit : la durée du travail ' . ($F ? 'de la salariée' : ($M ? 'du salarié' : 'du(de la) salarié(e)')) . ' est répartie de la façon suivante, à un taux horaire brut de ' . c_eur($P['taux']) . ' :') . $tab)
      . ($frais ? $A('Adresse de départ et frais de déplacement', $p('Les dispositions relatives à l\'adresse de départ et aux frais de déplacement sont fixées comme suit :') . $frais) : '')
      . $A('Autres dispositions', $p('Toutes les autres clauses du contrat initial demeurent inchangées et continuent de produire leurs effets.'));
  } else {
    // Contrat initial : texte fourni par l'agence (Réglages), jamais par Amaya. Amaya ajoute les parties, le tableau des horaires, les frais et la signature.
    $mod = (string)($hot ? $r['hot_modele'] : $r['ct_modele']); $conv = trim((string)($hot ? $r['hot_convention'] : $r['ct_convention']));
    if (trim($mod) === '') $body = '<div style="margin-top:14pt;padding:12pt;border:1pt dashed ' . $T['p'] . '"><b>Modèle de contrat ' . ($hot ? 'CDDU' : 'CIDD') . ' non renseigné.</b><br>Saisissez le texte du contrat de votre agence, validé par votre avocat, dans Administration › Réglages.</div>';
    else {
      if ($hot) $frais = str_replace(['chaque point de vente', 'Point de vente', 'point de vente'], ['chaque lieu de mission', 'Lieu de mission', 'lieu de mission'], $frais);
      $body = c_corps_modele($mod, $A, $p, [
        'salarie' => $Sal, 'Salarie' => $SalM, 'e' => $e, 'emploi' => h(c_emploi_hot($L, $F, $M, $hot ? '' : (string)$P['poste'])), 'coef' => h((string)$P['coef']), 'client' => h($P['client']), 'operation' => h($P['operation']),
        'magasins' => $mags, 'lieux' => implode(' ; ', array_map('h', $P['lieux'])) ?: 'lieux à préciser', 'duree' => $duree, 'essai' => (string)(int)$P['essai'], 'taux' => c_eur($P['taux']),
        'repas' => h($r['repas']), 'km' => h($r['km']), 'convention' => h($conv ?: '[convention collective à compléter dans Réglages]'), 'societe' => h($r['societe']),
        'appli' => h(AG_COURT), 'retraite' => h($P['retraite'] ?: '…'), 'caisse' => h($P['caisse'] ?: '…'), 'urssaf' => h($P['urssaf'] ?: '…'),
        'dpae' => $d1 ? g_date($d1['date']) : '…'], $tab, $frais);
    }
  }
  $css = $ff . '@page{margin:15mm 16mm 18mm 16mm}body{font-family:' . $font . ';font-size:9.3pt;color:' . $T['esp'] . ';line-height:1.32}b{font-weight:bold}.mut{color:' . $T['mut'] . '}.sm{font-size:7.4pt}
    #footer{position:fixed;bottom:-12mm;left:0;right:0;font-size:6.8pt;color:' . $T['mut'] . ';border-top:.5pt solid ' . $T['line'] . ';padding-top:5pt}
    table{width:100%;border-collapse:collapse}.doc{font-size:17pt;letter-spacing:3pt;font-weight:bold}.num{font-size:8.6pt;color:' . $T['p'] . ';letter-spacing:.6pt;font-weight:bold;margin-top:3pt}
    .k{font-size:7pt;text-transform:uppercase;letter-spacing:1.5pt;color:' . $T['p'] . ';font-weight:bold;margin-bottom:4pt}.pb{background:' . $T['sand'] . ';border-left:2.5pt solid ' . $T['p'] . ';padding:9pt 12pt;vertical-align:top;width:48%;font-size:8.8pt;line-height:1.35}
    .ci{margin-top:5pt;color:' . $T['mut'] . ';font-size:8pt}.art{margin-top:10pt}.at{font-size:8pt;text-transform:uppercase;letter-spacing:1.2pt;color:' . $T['p'] . ';font-weight:bold;border-bottom:.5pt solid ' . $T['line'] . ';padding-bottom:2pt;margin-bottom:4pt}
    p{margin:0 0 4pt;text-align:justify}ul{margin:2pt 0 5pt 14pt;padding:0}li{margin-bottom:1.5pt}.sub{font-weight:bold;margin:6pt 0 2pt}
    .hr{margin:5pt 0 7pt;font-size:8.4pt}.hr th{font-size:6.8pt;text-transform:uppercase;letter-spacing:1pt;color:' . $T['mut'] . ';text-align:left;padding:0 5pt 4pt;border-bottom:.8pt solid ' . $T['esp'] . '}
    .hr td{padding:3.5pt 5pt;border-bottom:.4pt solid ' . $T['line'] . '}.hr .n{text-align:right;white-space:nowrap}.hr .mg{background:' . $T['sand'] . ';font-weight:bold;padding-top:5pt}.hr .tt td{font-weight:bold;border-bottom:0;border-top:.8pt solid ' . $T['esp'] . '}
    .mj{color:' . $T['p'] . ';font-size:7pt;font-weight:bold}.sg{margin-top:14pt;page-break-inside:avoid}';
  return '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body><div id="footer"><table><tr><td>' . h($r['societe']) . ' · ' . h(str_replace("\n", ' · ', $r['adresse'])) . (trim((string)($r['siret'] ?? '')) !== '' ? ' · SIRET ' . h($r['siret']) : '') . '</td><td style="width:14mm"></td></tr></table></div>
    <table><tr><td style="vertical-align:bottom">' . $logo . '</td><td style="text-align:right;vertical-align:bottom"><div class="doc">' . $titre . '</div><div class="num">' . $sous . '</div></td></tr></table>
    <div style="margin-top:12pt">' . $parties . '</div>' . ($av ? '<p style="margin-top:9pt">Il est convenu ce qui suit :</p>' : '') . $body . '
    <p style="margin-top:10pt">' . ($av ? 'Avenant' : 'Contrat') . ' signé électroniquement par les deux parties et conservé dans l\'application ' . AG_COURT . '. Fait à ' . h($P['lieu']) . ', le ' . ($c['envoye_le'] ? date('d/m/Y', strtotime($c['envoye_le'])) : date('d/m/Y')) . '.</p>' . $sig . c_dossier_preuve($c, $T) . '</body></html>';
}
/** Fige le PDF signé : enregistré chiffré, avec son empreinte SHA-256. Plus jamais refabriqué ensuite. */
function c_figer(array $c): void {
  $pdf = c_pdf($c); $sha = hash('sha256', $pdf); $name = bin2hex(random_bytes(16)) . '.bin';
  if (file_put_contents(fiche_dir() . '/' . $name, amy_chiffre($pdf)) === false) return;
  db()->prepare("UPDATE contrats SET pdf_fichier = ?, pdf_sha256 = ? WHERE id = ?")->execute([$name, $sha, $c['id']]);
  c_journal((int)$c['id'], 'PDF signé figé et archivé · empreinte SHA-256 ' . $sha);
}
/** Le PDF à remettre : la version figée à la signature si elle existe, sinon fabriquée (contrat pas encore signé). */
function c_pdf_final(array $c): string {
  if (!empty($c['pdf_fichier']) && is_file($f = fiche_dir() . '/' . basename($c['pdf_fichier']))) { $d = amy_dechiffre((string)file_get_contents($f)); if ($d !== null) return $d; }
  return c_pdf($c);
}
/** Dossier de preuve (dernière page du document signé) : qui, quoi, quand, comment. */
function c_dossier_preuve(array $c, array $T): string {
  if (($c['statut'] ?? '') !== 'signe') return '';
  $pv = (array)($c['preuve'] ?? []); $d = fn($x) => $x ? date('d/m/Y à H:i:s', strtotime($x)) : '…';
  $rows = [
    ['Document', h(c_titre($c)) . ' · opération ' . h($c['params']['devis'] ?? '') . ' · ' . h($c['params']['client'] ?? '')],
    ['Émis et envoyé par l\'employeur', $d($c['envoye_le']) . ' · ' . h($c['envoye_par']) . ', depuis l\'administration ' . AG_COURT . ' (accès réservé, mot de passe personnel)'],
    ['Mise à disposition', 'Dans l\'application ' . AG_COURT . ' de ' . h(trim(($c['salarie']['prenom'] ?? '') . ' ' . ($c['salarie']['nom'] ?? ''))) . ', rubrique « Mes contrats », avec notification par e-mail'],
    ['Identification du signataire', !empty($pv['verif_le']) ? 'Appareil vérifié par un code à usage unique envoyé à l\'adresse e-mail du compte (' . h($pv['verif_email'] ?? '') . ') le ' . $d($pv['verif_le']) : 'Session de l\'application ' . AG_COURT . ' propre à la personne (appareil enregistré)'],
    ['Consentement', 'Case « J\'ai lu le document et j\'en accepte les termes » cochée, mention « ' . h($c['signe_mention']) . ' » saisie, nom saisi : « ' . h($c['signe_nom']) . ' », signature tracée à la main sur l\'écran'],
    ['Signature', $d($c['signe_le']) . ' (heure du serveur) · adresse IP ' . h($c['signe_ip'])],
    ['Appareil', h(mb_substr((string)$c['signe_ua'], 0, 220))],
    ['Empreinte du contenu signé (SHA-256)', '<span style="font-family:DejaVu Sans Mono,monospace;font-size:7pt">' . h($c['empreinte']) . '</span>'],
    ['Conservation', 'Le présent PDF est figé au moment de la signature, archivé chiffré, et son empreinte SHA-256 est enregistrée dans l\'application : toute modification ultérieure du fichier serait détectable.'],
  ];
  $j = array_reverse((array)($c['journal'] ?? []));
  return '<div style="page-break-before:always"></div><div class="at" style="margin-top:0">Dossier de preuve de la signature électronique</div>'
    . '<p class="mut">Signature électronique simple au sens du règlement (UE) n° 910/2014 (eIDAS) et de l\'article 1367 du Code civil.</p>'
    . '<table class="hr">' . implode('', array_map(fn($r) => '<tr><td style="width:32%;font-weight:bold;vertical-align:top">' . $r[0] . '</td><td>' . $r[1] . '</td></tr>', $rows)) . '</table>'
    . ($j ? '<div class="sub">Journal du document</div><table class="hr">' . implode('', array_map(fn($x) => '<tr><td style="width:32%;white-space:nowrap">' . $d($x['le']) . '</td><td>' . h($x['texte']) . '</td></tr>', array_slice($j, -25))) . '</table>' : '');
}
/** PDF avec « page x / n » en pied de page. */
function c_pdf(array $c): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(120);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('isHtml5ParserEnabled', true); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml(c_render($c), 'UTF-8'); $pdf->setPaper('A4'); $pdf->render();
  $cv = $pdf->getCanvas(); $f = $pdf->getFontMetrics()->getFont('Figtree') ?: $pdf->getFontMetrics()->getFont('DejaVu Sans');
  $cv->page_text($cv->get_width() - 16 * 2.835 - 30, $cv->get_height() - 18 * 2.835 + 20, '{PAGE_NUM} / {PAGE_COUNT}', $f, 7, [0.52, 0.47, 0.41]);
  return $pdf->output();
}
/** Aperçu d'un contrat pas encore envoyé (ou de l'avenant à venir). */
function c_apercu(int $aid, int $devis): array {
  $E = c_etat($aid, $devis); $last = $E['last'];
  $av = !$last ? 0 : ($last['statut'] === 'envoye' ? (int)$last['avenant'] : (int)$last['avenant'] + 1);
  return ['id' => 0, 'numero' => $last['numero'] ?? (max((int)db()->query("SELECT COALESCE(MAX(numero), 0) FROM contrats")->fetchColumn(), (int)g_reg()['ct_dernier']) + 1), 'avenant' => $av,
    'statut' => 'apercu', 'salarie' => $E['salarie'], 'params' => $E['params'] + ['trajets' => c_trajets($aid, $E['params'], $E['lignes'], $E['salarie'])], 'lignes' => $E['lignes'], 'precedent' => $av && $E['signe'] ? (json_decode($E['signe']['lignes'], true) ?: []) : [], 'envoye_le' => null, 'signature' => null];
}
