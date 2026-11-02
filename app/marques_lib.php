<?php
// Reprise d'historiques Excel de reporting et Excel sur l'année.

/** Texte simplifié pour comparer des noms : minuscules, sans accents, « Ste » = « Sainte », « BS » = « Beauty Success ». */
function mq_norm(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', '’' => "'"]);
  $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
  $s = preg_replace(['/\bste\b/', '/\bst\b/', '/\bbs\b/'], ['sainte', 'saint', 'beauty success'], $s);
  return trim(preg_replace('/\s+/', ' ', $s));
}
const MQ_MOIS = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];

/** Montant d'une cellule (0 compris) ; null si vide ou illisible. */
function mq_num(?array $c): ?float {
  if (!$c) return null; $v = $c['v'];
  if ($c['f'] !== null && preg_match('/^=?\s*\d+([.,]\d+)?(\s*\+\s*\d+([.,]\d+)?)*\s*$/', $c['f'])) return round(array_sum(array_map(fn($x) => (float)str_replace(',', '.', trim($x)), explode('+', ltrim(trim($c['f']), '=')))), 2);
  if (is_int($v) || is_float($v)) return round((float)$v, 2);
  $v = str_replace([' ', "\u{00A0}", "\u{202F}", '€', ','], ['', '', '', '', '.'], trim((string)$v));
  return ($v !== '' && is_numeric($v)) ? round((float)$v, 2) : null;
}
/** Date d'une cellule (nombre Excel ou JJ/MM/AAAA). */
function mq_date($v): ?string {
  if (is_int($v) || is_float($v)) return ($v > 20000 && $v < 80000) ? gmdate('Y-m-d', (int)round(($v - 25569) * 86400)) : null;
  if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string)$v), $x)) return checkdate((int)$x[2], (int)$x[3], (int)$x[1]) ? "$x[1]-$x[2]-$x[3]" : null;
  if (preg_match('/^(\d{1,2})\D+(\d{1,2})\D+(\d{2,4})/', trim((string)$v), $x)) { $y = (int)$x[3]; if ($y < 100) $y += 2000; return checkdate((int)$x[2], (int)$x[1], $y) ? sprintf('%04d-%02d-%02d', $y, $x[2], $x[1]) : null; }
  return null;
}
/** Nom de la personne dans un titre « REPORTING JULIE FEVRIER 2026 » ou « REPORTING G. RIZZO 2026 ». */
function mq_titre_personne(string $t): string {
  $t = trim(preg_replace('/^\s*reporting\s+/i', '', $t));
  $w = preg_split('/\s+/', $t); $out = [];
  foreach ($w as $x) { $n = mq_norm($x); if (preg_match('/^\d{4}$/', $n) || in_array($n, MQ_MOIS, true)) break; $out[] = $x; }
  return trim(implode(' ', $out));
}

/**
 * Lit un reporting de marque. Deux formats sont reconnus :
 * - « par mois » : un onglet par mois, titre « REPORTING PRÉNOM MOIS », colonnes DATE, Magasin, une colonne de CA par marque, objectifs, commentaires ;
 * - « sur l'année » : un onglet par intervenant(e), colonnes Date, Lieu, Réalisé, Remarques (et Nom pour les renforts).
 */
function mq_parse(string $path, string $fname): array {
  require_once __DIR__ . '/xlsx_lib.php';
  $book = xlsx_all($path);
  $rows = []; $skipped = []; $fixes = []; $brandCols = []; $format = ''; $seen = []; $doublons = []; $titre = '';
  // Sous-marques connues (client ventilé) : seules colonnes lues comme CA d'une marque.
  $SOUS = []; foreach (db()->query("SELECT sous_marques FROM marques") as $m) foreach (explode(',', (string)$m['sous_marques']) as $x) if (trim($x) !== '') $SOUS[mq_norm($x)] = true;
  foreach ($book as $sheet => $R) {
    ksort($R);
    $ym = ba_imp_mois($sheet); // [année, mois] pour un onglet « Février 2026 »
    $person = ''; $map = null;
    $sheetPerson = (!$ym && !preg_match('/^\s*\d{4}\s*$/', $sheet) && !preg_match('/renfort|total|synth|feuil|sheet/i', $sheet)) ? trim($sheet) : '';
    foreach ($R as $rn => $cells) {
      ksort($cells);
      $first = null; foreach ($cells as $c) if ($c['v'] !== null && $c['v'] !== '') { $first = $c; break; }
      if (!$first) continue;
      // Titre : « REPORTING … »
      if (is_string($first['v']) && preg_match('/^\s*reporting\s+/i', $first['v'])) {
        $tp = mq_titre_personne($first['v']); $titre = trim(preg_replace('/^\s*reporting\s+/i', '', $first['v']));
        $person = $sheetPerson !== '' ? ($tp !== '' && mq_norm($tp) !== mq_norm($sheetPerson) ? $sheetPerson . ' · ' . $tp : $sheetPerson) : $tp;
        $map = null; continue;
      }
      // Ligne d'en-têtes : contient « Date ».
      $heads = []; foreach ($cells as $ci => $c) if (is_string($c['v'])) $heads[$ci] = mq_norm($c['v']);
      if (in_array('date', $heads, true)) {
        $map = ['date' => null, 'mag' => null, 'nom' => null, 'com' => null, 'ca' => [], 'obj' => [], 'qte' => null, 'qobj' => null, 'cols' => array_keys($heads)];
        $pieces = (bool)array_filter($heads, fn($h) => preg_match('/piece|quantite|qte/', $h) && !str_starts_with($h, 'objectif'));
        foreach ($heads as $ci => $h) {
          if ($h === 'date') $map['date'] = $ci;
          elseif (in_array($h, ['magasin', 'lieu', 'point de vente', 'pdv', 'animation', 'boutique', 'enseigne', 'magasins'], true) && $map['mag'] === null) $map['mag'] = $ci;
          elseif (in_array($h, ['nom', 'animatrice', 'animateur', 'prenom', 'nom prenom', 'prenom nom', 'ba'], true)) $map['nom'] = $ci;
          elseif (preg_match('/^(commentaire|remarque|observation)/', $h)) $map['com'] = $ci;
          elseif (preg_match('/heure|horaire|frequentation/', $h)) continue;
          elseif (preg_match('/piece|quantite|qte/', $h)) { if (str_starts_with($h, 'objectif')) $map['qobj'] = $ci; else $map['qte'] = $ci; }
          elseif (in_array($h, ['realise', 'ca', 'ca realise', 'realise en ca', 'realise ca', 'ca ttc', 'ca ht', 'ca total', 'chiffre d affaires', 'ventes'], true) || str_starts_with($h, 'ca ')) $map['ca']['_'] = $ci;
          elseif (str_starts_with($h, 'objectif') && !str_starts_with($h, 'objectif total')) {
            // « Objectifs » à côté de « Quantités vendues » : objectif en pièces, pas en euros.
            if ($pieces && !preg_match('/ca|euro/', $h)) $map['qobj'] = $ci; else { $nm = trim(substr($h, 8)); $nm = $nm === 's' ? '' : $nm; $map['obj'][$nm === '' ? '_' : $nm] = $ci; }
          }
          elseif (str_starts_with($h, 'total') || str_contains($h, 'atteinte') || str_contains($h, 'realisation') || str_starts_with($h, '%')) continue;
          elseif (isset($SOUS[$h])) $map['ca'][trim((string)$cells[$ci]['v'])] = $ci; // colonne de CA d'une marque (client ventilé)
        }
        if (!$map['ca'] || $map['date'] === null) $map = null; else { $format = $ym ? 'mois' : 'annee'; foreach (array_keys($map['ca']) as $b) if ($b !== '_') $brandCols[$b] = true; }
        continue;
      }
      if (!$map) continue;
      $dv = $cells[$map['date']]['v'] ?? null; $date = mq_date($dv);
      if (!$date) continue; // ligne « Total » ou vide
      if ($ym) { // faute de frappe sur l'année ou le mois : on se fie à l'onglet
        [$y, $m] = $ym; $fixed = ba_imp_date(is_string($dv) || is_numeric($dv) ? $dv : $date, $y, $m);
        if ($fixed && $fixed !== $date) $fixes[] = date('d/m/Y', strtotime($date)) . ' → ' . date('d/m/Y', strtotime($fixed)) . " (onglet $sheet)";
        $date = $fixed ?: $date;
      }
      $who = $map['nom'] !== null ? trim((string)($cells[$map['nom']]['v'] ?? '')) : '';
      // Prénom dans une colonne sans titre, à gauche de la date (« Julie | Parfumerie Centre | 20/5/26 »).
      if ($who === '' && $map['nom'] === null) foreach ($cells as $ci => $c) if ($ci < $map['date'] && $ci !== $map['mag'] && !in_array($ci, $map['cols'], true) && is_string($c['v']) && trim($c['v']) !== '') { $who = trim($c['v']); break; }
      if ($who === '') $who = $person;
      $mag = $map['mag'] !== null ? trim((string)($cells[$map['mag']]['v'] ?? '')) : '';
      $com = $map['com'] !== null ? trim((string)($cells[$map['com']]['v'] ?? '')) : '';
      $q = $map['qte'] !== null ? mq_num($cells[$map['qte']] ?? null) : null; $qo = $map['qobj'] !== null ? mq_num($cells[$map['qobj']] ?? null) : null;
      if ($q !== null || $qo !== null) $com = trim('Pièces vendues : ' . ($q === null ? '?' : (int)$q) . ($qo ? ' (objectif ' . (int)$qo . ')' : '') . '. ' . $com);
      $ca = []; foreach ($map['ca'] as $b => $ci) { $x = mq_num($cells[$ci] ?? null); if ($x !== null) $ca[$b] = $x; }
      $ob = []; foreach ($map['obj'] as $b => $ci) { $x = mq_num($cells[$ci] ?? null); if ($x !== null && $x > 0) $ob[$b] = $x; }
      if (!$ca) { $skipped[] = ['date' => $date, 'who' => $who, 'mag' => $mag, 'com' => $com]; continue; } // journée prévue ou non travaillée
      if ($mag === '') $mag = 'Point de vente à préciser · ' . ($titre !== '' ? $titre : preg_replace('/\.xlsx$/i', '', $fname)); // à choisir une fois pour tout le fichier dans l'aperçu
      if ($who === '') $who = 'Nom absent · ' . $sheet . ' · ' . $mag; // titre sans prénom : à choisir dans l'aperçu
      $key = $date . '|' . mq_norm($mag) . '|' . mq_norm($who);
      if (isset($seen[$key])) { $doublons[] = date('d/m/Y', strtotime($date)) . ' ' . $mag . ' (' . $who . ') : ' . number_format(array_sum($ca), 2, ',', ' ') . ' € non repris'; continue; }
      $seen[$key] = true;
      $rows[] = ['date' => $date, 'who' => $who, 'mag' => $mag, 'ca' => $ca, 'obj' => $ob, 'com' => clip($com, 2000), 'sheet' => $sheet];
    }
  }
  return ['fichier' => $fname, 'format' => $format, 'rows' => $rows, 'skipped' => $skipped, 'fixes' => $fixes, 'doublons' => $doublons, 'brand_cols' => array_keys($brandCols)];
}

/** Marque devinée : colonnes de sous-marques → la marque qui les ventile ; sinon le nom du fichier. */
function mq_guess_marque(array $parsed): ?int {
  $M = db()->query("SELECT id, nom, sous_marques FROM marques ORDER BY actif DESC, ordre")->fetchAll();
  if ($parsed['brand_cols']) foreach ($M as $m) {
    $sous = array_map('mq_norm', array_filter(array_map('trim', explode(',', (string)$m['sous_marques']))));
    if ($sous && !array_diff(array_map('mq_norm', $parsed['brand_cols']), $sous)) return (int)$m['id'];
  }
  $f = mq_norm($parsed['fichier']);
  foreach ($M as $m) { $first = explode(' ', mq_norm($m['nom']))[0]; if ($first !== '' && preg_match('/\b' . preg_quote($first, '/') . '\b/', $f)) return (int)$m['id']; }
  return null;
}
/** Intervenant(e) correspondant à un nom de reporting (« JULIE », « G. RIZZO », « Tatiana · T. PAYET »). */
function mq_match_personne(string $label, array $A): array {
  $parts = array_map('trim', explode('·', $label));
  $tok = []; foreach ($parts as $p) foreach (explode(' ', mq_norm($p)) as $t) if ($t !== '') $tok[] = $t;
  $best = [];
  foreach ($A as $a) {
    $pn = mq_norm($a['prenom']); $nn = mq_norm($a['nom']); $score = 0;
    $pt = explode(' ', $pn); $nt = explode(' ', $nn);
    if (array_intersect($pt, $tok)) $score += 2;
    if ($nn !== '' && array_intersect($nt, $tok)) $score += 3;
    foreach ($tok as $t) if (strlen($t) === 1 && $pn !== '' && $pn[0] === $t) $score += 1;
    if ($score >= 2) $best[] = [$score, (int)$a['id']];
  }
  usort($best, fn($x, $y) => $y[0] <=> $x[0]);
  $id = ($best && (count($best) === 1 || $best[0][0] > $best[1][0])) ? $best[0][1] : 0;
  // Proposition pour une création : prénom en toutes lettres si on l'a, nom = le reste (initiales écartées).
  $split = function (string $p) { $I = []; $W = []; foreach (preg_split('/\s+/', trim($p)) as $w) { if ($w === '') continue; if (preg_match('/^\p{L}\.$/u', $w)) $I[] = $w; else $W[] = $w; } return [$I, $W]; };
  if (count($parts) > 1) { $pre = $parts[0]; [, $W] = $split($parts[1]); $nom = implode(' ', $W); }
  else { [$I, $W] = $split($parts[0]); if ($I) { $pre = $I[0]; $nom = implode(' ', $W); } elseif (count($W) === 1) { $pre = $W[0]; $nom = ''; } else { $pre = $W[0] ?? $label; $nom = implode(' ', array_slice($W, 1)); } }
  $pre = preg_match('/^\p{L}\.$/u', $pre) ? mb_strtoupper($pre) : mb_convert_case(mb_strtolower($pre), MB_CASE_TITLE);
  if (str_starts_with($label, 'Nom absent')) { $pre = ''; $nom = ''; }
  return ['label' => $label, 'match' => $id, 'prenom' => $pre, 'nom' => mb_strtoupper($nom)];
}
/** Point de vente correspondant à un libellé (« Parfumerie Centre », « Beauté Gare »). */
function mq_match_magasin(string $label, array $Mg): array {
  $l = mq_norm($label); $lt = explode(' ', $l); $best = [0, 0];
  foreach ($Mg as $m) {
    foreach ([mq_norm($m['nom']), mq_norm($m['enseigne'] . ' ' . $m['nom']), mq_norm($m['enseigne'] . ' ' . $m['ville'])] as $c) {
      if ($c === $l) { $best = [100, (int)$m['id']]; break 2; }
      $ct = explode(' ', $c); $common = count(array_intersect($lt, $ct));
      if ($common >= 2 && ($common === count($lt) || $common === count($ct))) { $s = 50 + $common; if ($s > $best[0]) $best = [$s, (int)$m['id']]; }
    }
  }
  $w = preg_split('/\s+/', trim($label), 2);
  $ens = ['mado' => 'MADO', 'nocibe' => 'NOCIBE', 'beauty' => 'BEAUTY SUCCESS', 'bs' => 'BEAUTY SUCCESS', 'sephora' => 'SEPHORA', 'marionnaud' => 'MARIONNAUD'][mq_norm($w[0])] ?? '';
  return ['label' => $label, 'match' => $best[1], 'enseigne' => $ens, 'nom' => trim($label), 'ville' => $ens && isset($w[1]) ? $w[1] : ''];
}

/** Aperçu d'un ou plusieurs fichiers : correspondances proposées, sans rien écrire. */
function mq_preview(array $files): array {
  $A = db()->query("SELECT id, prenom, nom FROM animatrices ORDER BY nom, prenom")->fetchAll();
  $Mg = db()->query("SELECT id, zone, enseigne, nom, ville FROM magasins")->fetchAll();
  $out = []; $persons = []; $mags = [];
  foreach ($files as $f) {
    $p = mq_parse($f['path'], $f['name']);
    $dates = array_column($p['rows'], 'date'); sort($dates);
    $out[] = ['fichier' => $f['name'], 'id' => $f['id'], 'format' => $p['format'], 'lignes' => count($p['rows']), 'ignorees' => count($p['skipped']),
      'ignorees_detail' => array_slice(array_map(fn($s) => date('d/m', strtotime($s['date'])) . ' ' . $s['mag'] . ($s['com'] ? ' (' . mb_substr($s['com'], 0, 40) . ')' : ''), array_filter($p['skipped'], fn($s) => $s['date'] <= date('Y-m-d'))), 0, 12),
      'prevues' => count(array_filter($p['skipped'], fn($s) => $s['date'] > date('Y-m-d'))), 'fixes' => $p['fixes'], 'doublons' => $p['doublons'], 'colonnes' => $p['brand_cols'],
      'marque_id' => mq_guess_marque($p), 'du' => $dates[0] ?? null, 'au' => $dates ? end($dates) : null, 'ca' => round(array_sum(array_map(fn($r) => array_sum($r['ca']), $p['rows'])), 2)];
    foreach ($p['rows'] as $r) { $persons[$r['who']] = ($persons[$r['who']] ?? 0) + 1; $mags[$r['mag']] = ($mags[$r['mag']] ?? 0) + 1; }
  }
  ksort($persons); ksort($mags);
  return ['fichiers' => $out,
    'personnes' => array_map(fn($l, $n) => mq_match_personne($l, $A) + ['n' => $n], array_keys($persons), $persons),
    'magasins' => array_map(fn($l, $n) => mq_match_magasin($l, $Mg) + ['n' => $n], array_keys($mags), $mags)];
}

/**
 * Import définitif. $map = ['personnes' => [label => id ou ['prenom','nom']], 'magasins' => [label => id ou ['enseigne','nom','ville']], 'marques' => [fichierId => marque_id], 'zone' => 'France'].
 * Un compte rendu déjà repris (même intervenant(e), date, magasin) est mis à jour ; un compte rendu saisi dans l'appli n'est jamais écrasé.
 */
function mq_import(array $files, array $map): array {
  $zone = isset(ZONES[$map['zone'] ?? '']) ? $map['zone'] : 'France';
  $pdo = db(); $res = ['crees' => 0, 'maj' => 0, 'appli' => 0, 'animatrices' => 0, 'magasins' => 0];
  $pid = []; $mid = [];
  $getP = function (string $l) use (&$pid, $map, $pdo, &$res) {
    if (isset($pid[$l])) return $pid[$l];
    $v = $map['personnes'][$l] ?? null;
    if (is_numeric($v) && (int)$v > 0) return $pid[$l] = (int)$v;
    if (!is_array($v) || trim($v['prenom'] ?? '') === '') throw new RuntimeException('Choisissez l\'intervenant(e) pour « ' . $l . ' ».');
    // Fiche créée sans téléphone : l'intervenant(e) la retrouvera à son inscription (même prénom et nom).
    $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, code, actif, created_at) VALUES (?,?,'','',?,NULL,1,NULL)")
      ->execute([clip($v['prenom'], 80), mb_strtoupper(clip($v['nom'] ?? '', 80)), $map['zone'] ?? 'France']);
    $res['animatrices']++; return $pid[$l] = (int)$pdo->lastInsertId();
  };
  $getM = function (string $l) use (&$mid, $map, $pdo, $zone, &$res) {
    if (isset($mid[$l])) return $mid[$l];
    $v = $map['magasins'][$l] ?? null;
    if (is_numeric($v) && (int)$v > 0) { $st = $pdo->prepare("SELECT * FROM magasins WHERE id = ?"); $st->execute([(int)$v]); if ($m = $st->fetch()) return $mid[$l] = $m; }
    if (!is_array($v) || trim($v['nom'] ?? '') === '') throw new RuntimeException('Choisissez le point de vente pour « ' . $l . ' ».');
    $pdo->prepare("INSERT INTO magasins (zone, enseigne, nom, ville, actif) VALUES (?,?,?,?,1)")->execute([$zone, clip($v['enseigne'] ?? '', 60), clip($v['nom'], 120), clip($v['ville'] ?? '', 80)]);
    $res['magasins']++; $st = $pdo->prepare("SELECT * FROM magasins WHERE id = ?"); $st->execute([(int)$pdo->lastInsertId()]); return $mid[$l] = $st->fetch();
  };
  $pdo->beginTransaction();
  try {
    foreach ($files as $f) {
      $mk = (int)($map['marques'][$f['id']] ?? 0);
      $st = $pdo->prepare("SELECT id, nom, sous_marques FROM marques WHERE id = ?"); $st->execute([$mk]); $marque = $st->fetch();
      if (!$marque) throw new RuntimeException('Choisissez la marque du fichier « ' . $f['name'] . ' ».');
      $sous = array_values(array_filter(array_map('trim', explode(',', (string)$marque['sous_marques']))));
      foreach (mq_parse($f['path'], $f['name'])['rows'] as $r) {
        $aid = $getP($r['who']); $m = $getM($r['mag']);
        // CA et objectifs : ventilés par marque  quand la marque l'est.
        $parts = null; $ca = array_sum($r['ca']); $obj = $r['obj']['_'] ?? null;
        if ($sous) {
          $parts = []; $byN = []; foreach ($r['ca'] as $b => $v) $byN[mq_norm($b)] = $v; $obN = []; foreach ($r['obj'] as $b => $v) $obN[mq_norm($b)] = $v;
          foreach ($sous as $n) $parts[] = ['nom' => $n, 'ca' => $byN[mq_norm($n)] ?? null, 'objectif' => $obN[mq_norm($n)] ?? null];
          $obj = array_filter($parts, fn($p) => $p['objectif'] === null) ? null : array_sum(array_column($parts, 'objectif'));
        }
        $st = $pdo->prepare("SELECT id, source FROM rapports WHERE animatrice_id = ? AND date_animation = ? AND magasin_id = ? LIMIT 1"); $st->execute([$aid, $r['date'], $m['id']]); $ex = $st->fetch();
        if ($ex && $ex['source'] !== 'import') { $res['appli']++; continue; }
        if ($ex) {
          // Même journée dans deux fichiers (deux marques) : on garde les deux commentaires.
          $rid = (int)$ex['id'];
          $pdo->prepare("UPDATE rapports SET faits = CASE WHEN ? = '' THEN faits WHEN IFNULL(faits, '') = '' THEN ? WHEN LOCATE(?, faits) > 0 THEN faits ELSE CONCAT(faits, '\n', ?) END, modifie_le = ? WHERE id = ?")
            ->execute([$r['com'], $r['com'], $r['com'], $r['com'], now(), $rid]);
          $pdo->prepare("DELETE FROM rapport_marques WHERE rapport_id = ? AND marque_id = ?")->execute([$rid, $mk]); $res['maj']++;
        } else {
          $pdo->prepare("INSERT INTO rapports (token, animatrice_id, zone, magasin_id, magasin, ville, devise, date_animation, frequentation, concurrence, concurrence_autre, offres, staff, faits, created_at, source)
            VALUES (NULL,?,?,?,?,?,?,?,'','[]','','','',?,?,'import')")->execute([$aid, $m['zone'], $m['id'], $m['nom'], $m['ville'], ZONES[$m['zone']] ?? 'EUR', $r['date'], $r['com'], now()]);
          $rid = (int)$pdo->lastInsertId(); if (!$rid) throw new RuntimeException('Création du compte rendu impossible.');
          $pdo->prepare("UPDATE rapports SET valide_le = ?, valide_par = 'Reprise Excel' WHERE id = ?")->execute([now(), $rid]); $res['crees']++;
        }
        $pdo->prepare("INSERT INTO rapport_marques (rapport_id, marque_id, ca, objectif, ventilation, ruptures, testeurs, reactions) VALUES (?,?,?,?,?,'','','')")
          ->execute([$rid, $mk, round($ca, 2), $obj, $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null]);
      }
    }
    $pdo->commit();
  } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
  return $res;
}

/* ---------- Excel au format des marques ---------- */

/** Format « sur l'année » : un onglet par intervenant(e), Date | Lieu | Réalisé | (Objectif | % atteinte) | Remarques. */
function make_xlsx_annee(array $d): string {
  $byA = [];
  foreach ($d['rapports'] as $r) $byA[$r['animatrice_id']][] = $r;
  $names = []; foreach ($byA as $aid => $rs) $names[$aid] = $rs[0]['animatrice'];
  $first = []; foreach ($names as $aid => $n) $first[$aid] = explode(' ', trim($n))[0];
  $cnt = array_count_values(array_map('mb_strtolower', $first));
  uksort($byA, fn($a, $b) => strcmp($names[$a], $names[$b]));
  $year = substr($d['p']['to'], 0, 4); $sheets = [];
  foreach ($byA as $aid => $rs) {
    usort($rs, fn($a, $b) => [$a['date_animation'], $a['magasin']] <=> [$b['date_animation'], $b['magasin']]);
    $pp = preg_split('/\s+/', trim($names[$aid])); $pre = $pp[0]; $nom = implode(' ', array_slice($pp, 1));
    $titre = 'REPORTING ' . mb_strtoupper(($nom !== '' ? mb_substr($pre, 0, 1) . '. ' . $nom : $pre)) . ' ' . $year;
    $hasObj = false; foreach ($rs as $r) foreach ($r['lines'] as $l) if ($l['objectif'] !== null && (float)$l['objectif'] > 0) $hasObj = true;
    $heads = $hasObj ? ['Date', 'Lieu', 'Réalisé', 'Objectif', '% atteinte', 'Remarques'] : ['Date', 'Lieu', 'Réalisé', 'Remarques'];
    $W = ['name' => $cnt[mb_strtolower($pre)] > 1 && $nom !== '' ? $pre . ' ' . mb_substr($nom, 0, 1) . '.' : $pre, 'rows' => [1 => [0 => ['v' => $titre, 's' => 'r_title']]], 'merges' => ['A1:' . xl_col(count($heads) - 1) . '1'], 'heights' => [1 => 28.5]];
    foreach ($heads as $i => $h) $W['rows'][3][$i] = ['v' => $h, 's' => 'r_head'];
    $W['heights'][3] = 24; $row = 3;
    foreach ($rs as $r) {
      $row++; $ca = 0.0; $ob = 0.0; $allObj = true;
      foreach ($r['lines'] as $l) { $ca += (float)$l['ca']; if ($l['objectif'] === null) $allObj = false; else $ob += (float)$l['objectif']; }
      $com = ($r['source'] ?? '') === 'import' ? trim((string)$r['faits']) : trim(implode(' ', array_filter([
        $r['concurrence'] ? 'BC ' . implode(', ', $r['concurrence']) . '.' : '', trim((string)$r['offres']), trim((string)$r['faits'])])));
      $R = [0 => ['d' => $r['date_animation'], 's' => 'r_date'], 1 => ['v' => $r['magasin'], 's' => 'r_txt'], 2 => ['v' => round($ca, 2), 's' => 'r_num']];
      if ($hasObj) { $R[3] = ['v' => $allObj && $ob > 0 ? round($ob, 2) : null, 's' => 'r_num']; $R[4] = ['f' => 'IFERROR(C' . $row . '/D' . $row . '-1,"")', 'v' => $allObj && $ob > 0 ? round($ca / $ob - 1, 4) : '', 's' => 'r_pct']; $R[5] = ['v' => $com, 's' => 'r_com']; }
      else $R[3] = ['v' => $com, 's' => 'r_com'];
      $W['rows'][$row] = $R; $W['heights'][$row] = max(18, min(120, 15 * (1 + intdiv(mb_strlen($com), 60))));
    }
    $row++;
    $T = [0 => ['v' => 'Total', 's' => 'r_tot'], 1 => ['v' => '', 's' => 'r_tot'], 2 => ['f' => 'SUM(C4:C' . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b']];
    if ($hasObj) { $T[3] = ['f' => 'SUM(D4:D' . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b']; $T[4] = ['f' => 'IFERROR(C' . $row . '/D' . $row . '-1,"")', 'v' => '', 's' => 'r_pct_red']; }
    $W['rows'][$row] = $T; $W['heights'][$row] = 21;
    $W['widths'] = $hasObj ? [0 => 11, 1 => 24, 2 => 12, 3 => 12, 4 => 11, 5 => 70] : [0 => 11, 1 => 24, 2 => 12, 3 => 70];
    $W['freeze'] = 'A4';
    $sheets[] = $W;
  }
  if (!$sheets) $sheets[] = ['name' => 'Reporting', 'rows' => [1 => [0 => ['v' => 'Aucune animation sur cette période', 's' => 'r_title']]]];
  return xl_book($sheets);
}

/** Fichiers Excel d'un rapport : format par mois ou sur l'année, un seul classeur ou un par point de vente. */
function xlsx_fichiers(array $d, string $fmt, bool $parMagasin, string $slug): array {
  $make = fn($dd) => $fmt === 'annee' ? make_xlsx_annee($dd) : make_xlsx($dd);
  if (!$parMagasin) return [['Reporting_' . $slug . '.xlsx', $make($d)]];
  $by = []; foreach ($d['rapports'] as $r) $by[$r['magasin']][] = $r;
  ksort($by); $out = [];
  $brand = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', brands_label($d)));
  foreach ($by as $mag => $rs) {
    $dd = $d; $dd['rapports'] = $rs;
    $out[] = ['Reporting_' . $brand . '_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $mag)), '_') . '_' . substr($d['p']['to'], 0, 4) . '.xlsx', $make($dd)];
  }
  return $out ?: [['Reporting_' . $slug . '.xlsx', $make($d)]];
}

/* ---------- Photothèque ---------- */
/** Toutes les photos des comptes rendus, avec date, zone, ville, magasin, intervenant(e) et marque. Filtres facultatifs. */
function phototheque(array $q, int $limit = 600): array {
  $ok = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
  $from = $ok($q['from'] ?? null) ? $q['from'] : date('Y-m-d', strtotime('-3 months')); $to = $ok($q['to'] ?? null) ? $q['to'] : date('Y-m-d');
  $A = db()->prepare("SELECT p.id, p.fichier, p.slot, r.date_animation AS date, r.zone, r.ville, r.magasin, r.animatrice_id, CONCAT(a.prenom, ' ', a.nom) AS animatrice,
      COALESCE(m.nom, (SELECT GROUP_CONCAT(m2.nom SEPARATOR ', ') FROM rapport_marques rm2 JOIN marques m2 ON m2.id = rm2.marque_id WHERE rm2.rapport_id = r.id)) AS marque,
      COALESCE(m.id, 0) AS marque_id, 'animation' AS source
    FROM photos p JOIN rapports r ON r.id = p.rapport_id JOIN animatrices a ON a.id = r.animatrice_id
    LEFT JOIN rapport_marques rm ON rm.id = p.rapport_marque_id LEFT JOIN marques m ON m.id = rm.marque_id
    WHERE r.date_animation BETWEEN ? AND ?");
  $A->execute([$from, $to]); $rows = $A->fetchAll();
  // Listes pour les filtres, avant filtrage.
  $opts = ['zones' => [], 'villes' => [], 'magasins' => [], 'animatrices' => [], 'marques' => []];
  foreach ($rows as $r) {
    if ($r['zone'] !== '') $opts['zones'][$r['zone']] = 1; if ($r['ville'] !== '') $opts['villes'][$r['ville']] = 1; if ($r['magasin'] !== '') $opts['magasins'][$r['magasin']] = 1;
    $opts['animatrices'][$r['animatrice_id']] = $r['animatrice'];
    foreach (array_map('trim', explode(',', (string)$r['marque'])) as $mq) if ($mq !== '') $opts['marques'][$mq] = 1;
  }
  $f = fn($k) => trim((string)($q[$k] ?? ''));
  $rows = array_values(array_filter($rows, fn($r) => ($f('zone') === '' || $r['zone'] === $f('zone')) && ($f('ville') === '' || $r['ville'] === $f('ville'))
    && ($f('magasin') === '' || $r['magasin'] === $f('magasin')) && ($f('animatrice') === '' || (int)$r['animatrice_id'] === (int)$f('animatrice'))
    && ($f('marque') === '' || in_array($f('marque'), array_map('trim', explode(',', (string)$r['marque'])), true))
    && ($f('type') === '' || ($f('type') === 'selfie' ? in_array($r['slot'], ['animatrice', 'selfie'], true) : !in_array($r['slot'], ['animatrice', 'selfie'], true)))));
  usort($rows, fn($a, $b) => [$b['date'], $a['magasin'], $a['id']] <=> [$a['date'], $b['magasin'], $b['id']]);
  $total = count($rows); $rows = array_slice($rows, 0, $limit);
  $seen = [];
  foreach ($rows as &$r) {
    $r['url'] = photo_url($r['fichier'], true); $r['full'] = photo_url($r['fichier']);
    $r['type'] = in_array($r['slot'], ['animatrice', 'selfie'], true) ? 'Selfie' : 'Rayon';
    $slug = fn($s) => trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', (string)$s)), '-');
    $n = $r['date'] . '_' . $slug($r['magasin']) . '_' . $slug(explode(' ', $r['animatrice'])[0]) . '_' . $slug($r['type'] === 'Selfie' ? 'selfie' : ($r['marque'] ?: 'rayon'));
    $seen[$n] = ($seen[$n] ?? 0) + 1; $r['nom'] = $n . ($seen[$n] > 1 ? '-' . $seen[$n] : '') . '.jpg';
  }
  unset($r);
  ksort($opts['zones']); ksort($opts['villes']); ksort($opts['magasins']); asort($opts['animatrices']); ksort($opts['marques']);
  return ['from' => $from, 'to' => $to, 'total' => $total, 'photos' => $rows,
    'options' => ['zones' => array_keys($opts['zones']), 'villes' => array_keys($opts['villes']), 'magasins' => array_keys($opts['magasins']),
      'animatrices' => array_map(fn($id, $n) => ['id' => $id, 'nom' => $n], array_keys($opts['animatrices']), $opts['animatrices']), 'marques' => array_keys($opts['marques'])]];
}
/** Place occupée par l'appli : photos et fichiers (dossier uploads) et base de données. */
function stockage(): array {
  $files = 0; $n = 0; $photos = 0;
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(upload_dir(), FilesystemIterator::SKIP_DOTS));
  foreach ($it as $f) { if (!$f->isFile()) continue; $s = $f->getSize(); $files += $s; $n++; if (preg_match('/\.jpg$/', $f->getFilename()) && $f->getPath() === upload_dir()) $photos += $s; }
  $dbs = (int)db()->query("SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()")->fetchColumn();
  $nb = (int)db()->query("SELECT COUNT(*) FROM photos")->fetchColumn();
  return ['fichiers' => $files, 'photos' => $photos, 'nb_fichiers' => $n, 'nb_photos' => $nb, 'base' => $dbs, 'total' => $files + $dbs];
}
/** Onglet « Février 2026 » : [année, mois, reste du titre]. */
function ba_imp_mois(string $name): ?array {
  $n = mb_strtolower(trim($name));
  foreach (MOIS_FR as $i => $m) if (preg_match('/^' . preg_quote(mb_strtolower($m), '/') . '\s+(\d{4})(.*)$/u', $n, $x)) return [(int)$x[1], $i, trim($x[2])];
  return null;
}
function ba_imp_date($v, int $y, int $m): ?string {
  if (is_int($v) || is_float($v)) { if ($v < 20000 || $v > 80000) return null; $d = gmdate('Y-m-d', (int)round(($v - 25569) * 86400)); }
  elseif (preg_match('/^(\d{1,2})\D+(\d{1,2})\D+(\d{2,4})/', trim((string)$v), $x)) { $yy = (int)$x[3]; if ($yy < 100) $yy += 2000; $d = sprintf('%04d-%02d-%02d', $yy, (int)$x[2], (int)$x[1]); if (!checkdate((int)$x[2], (int)$x[1], $yy)) return null; }
  elseif (preg_match('/^(\d{1,2})\D+(\d{1,2})/', trim((string)$v), $x) && checkdate((int)$x[2], (int)$x[1], $y)) $d = sprintf('%04d-%02d-%02d', $y, (int)$x[2], (int)$x[1]); // date mal tapée (21/05826)
  else return null;
  // Faute de frappe sur l'année (ex. 06/01/2025 dans l'onglet Janvier 2026) : on garde le jour, on prend le mois et l'année de l'onglet.
  if ((int)substr($d, 5, 2) === $m && (int)substr($d, 0, 4) !== $y) $d = sprintf('%04d-%s', $y, substr($d, 5));
  // Mois mal tapé (13/04 dans l'onglet Janvier) : on garde le jour, dans le mois de l'onglet.
  if ((int)substr($d, 5, 2) !== $m && checkdate($m, (int)substr($d, 8, 2), $y)) { $GLOBALS['ba_imp_fix'][] = date('d/m/Y', strtotime($d)) . ' → ' . sprintf('%02d/%02d/%04d', (int)substr($d, 8, 2), $m, $y); $d = sprintf('%04d-%02d-%s', $y, $m, substr($d, 8, 2)); }
  return (int)substr($d, 5, 2) === $m ? $d : null;
}
