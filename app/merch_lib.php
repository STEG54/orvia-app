<?php
// Merchandising : visites en point de vente saisies dans l'appli (photos avant/après, relevés par référence, linéaire, promotions, PLV, tâches),
// relues et validées par l'agence, puis restituées aux marques dans un rapport détaillé (PDF, Excel, espace marques).

require_once __DIR__ . '/gestion_lib.php';
const MC_TACHES = ['Implantation selon le planogramme', 'Facing et remise en ordre', 'Réassort depuis la réserve', 'Nettoyage du meuble et des testeurs', 'Remplacement des testeurs',
  'Étiquetage des prix', 'Mise en place de la promotion', 'Pose de PLV', 'Retrait des produits abîmés ou périmés', 'Relevé de linéaire', 'Formation rapide du personnel du magasin'];
const MC_EMPLACEMENTS = ['Meuble de la marque', 'Linéaire / gondole', 'Tête de gondole', 'Îlot ou présentoir', 'Zone caisse', 'Vitrine', 'Comptoir'];
const MC_HAUTEURS = ['Haut', 'Yeux', 'Mains', 'Sol'];
const MC_STATUTS = ['present' => 'Présent', 'rupture' => 'En rupture', 'absent' => 'Non référencé'];
const MC_RESERVE = ['ok' => 'Stock suffisant', 'faible' => 'Stock faible', 'vide' => 'Réserve vide', 'inconnu' => 'Pas d\'accès à la réserve'];
const MC_PHOTOS = 8; // par type (avant, après, concurrence)
const MC_SLOTS = ['avant' => 'Avant', 'apres' => 'Après', 'conc' => 'Concurrence'];

function mc_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; $pdo = db(); $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  $pdo->exec("CREATE TABLE IF NOT EXISTS merch_refs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, marque_id INT UNSIGNED NOT NULL, nom VARCHAR(160) NOT NULL, ean VARCHAR(20) NOT NULL DEFAULT '',
    gamme VARCHAR(80) NOT NULL DEFAULT '', prix_conseille DECIMAL(10,2) NULL, facings_cible INT NULL, ordre INT NOT NULL DEFAULT 100, actif TINYINT NOT NULL DEFAULT 1, INDEX (marque_id)) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS merch_visites (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(32) NOT NULL, animatrice_id INT UNSIGNED NOT NULL, devis_id INT UNSIGNED NULL,
    marque_id INT UNSIGNED NULL, magasin VARCHAR(160) NOT NULL DEFAULT '', ville VARCHAR(80) NOT NULL DEFAULT '', date_visite DATE NOT NULL, arrivee VARCHAR(5) NOT NULL DEFAULT '', depart VARCHAR(5) NOT NULL DEFAULT '',
    data MEDIUMTEXT NULL, recommandations TEXT NULL, valide_le DATETIME NULL, valide_par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, modifie_le DATETIME NULL,
    UNIQUE KEY (token), INDEX (date_visite), INDEX (devis_id), INDEX (marque_id), INDEX (animatrice_id)) $E");
  if (!in_array('merch_id', $pdo->query("SHOW COLUMNS FROM photos")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE photos ADD COLUMN merch_id INT UNSIGNED NULL, ADD INDEX (merch_id)");
  // Catalogue de la marque : code article, case « à relever » (liste par défaut), PVP saisi par qui et quand (agence ou marque).
  if (!in_array('a_relever', $pdo->query("SHOW COLUMNS FROM merch_refs")->fetchAll(PDO::FETCH_COLUMN), true))
    $pdo->exec("ALTER TABLE merch_refs ADD COLUMN code VARCHAR(40) NOT NULL DEFAULT '', ADD COLUMN a_relever TINYINT NOT NULL DEFAULT 1, ADD COLUMN pvp_par VARCHAR(160) NOT NULL DEFAULT '', ADD COLUMN pvp_le DATETIME NULL, ADD INDEX (ean)");
  // Liste à relever propre à une opération (sinon : les références cochées « à relever » du catalogue).
  $pdo->exec("CREATE TABLE IF NOT EXISTS merch_selection (devis_id INT UNSIGNED NOT NULL, ref_id INT UNSIGNED NOT NULL, PRIMARY KEY (devis_id, ref_id)) $E");
}
/** Références à relever pour une opération : sa liste propre si l'agence en a fait une, sinon la liste par défaut de la marque. */
function mc_refs_op(?int $devis, ?int $marque): array {
  if (!$marque) return []; mc_schema(); $all = mc_refs($marque);
  if ($devis) { $st = db()->prepare("SELECT ref_id FROM merch_selection WHERE devis_id = ?"); $st->execute([$devis]); $sel = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    if ($sel) return array_values(array_filter($all, fn($r) => in_array((int)$r['id'], $sel, true))); }
  return array_values(array_filter($all, fn($r) => (int)$r['a_relever'] === 1));
}
/** Opérations de merchandising d'une marque (devis de ses clients), avec leur liste propre éventuelle. */
function mc_ops_marque(int $marque): array {
  mc_schema(); $st = db()->prepare("SELECT d.id, d.numero, d.objet, d.type_op, d.date_doc, d.statut FROM g_docs d JOIN g_clients c ON c.id = d.client_id
    WHERE d.type = 'devis' AND c.marque_id = ? AND d.statut NOT IN ('refuse','annule') AND (d.type_op LIKE 'Merchandising%' OR d.lignes LIKE '%erchandis%') ORDER BY d.date_doc DESC LIMIT 40");
  $st->execute([$marque]); $sel = db()->prepare("SELECT ref_id FROM merch_selection WHERE devis_id = ?");
  return array_map(function ($d) use ($sel) { $sel->execute([$d['id']]); return ['id' => (int)$d['id'], 'nom' => trim(($d['numero'] ?: 'Devis') . ' · ' . ($d['objet'] ?: $d['type_op'])), 'sel' => array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN))]; }, $st->fetchAll());
}
/** Lit un fichier de la marque (Excel .xlsx ou CSV) : lignes de cellules texte. */
function mc_fichier_lignes(string $tmp, string $nom): array {
  $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
  if ($ext === 'xls') throw new RuntimeException('Ancien format Excel (.xls) : ouvrez le fichier dans Excel et enregistrez-le en .xlsx ou en CSV.');
  if ($ext === 'xlsx') { if (!class_exists('ZipArchive')) throw new RuntimeException('Lecture Excel indisponible sur ce serveur : enregistrez le fichier en CSV.');
    if (!function_exists('xlsx_rows')) require_once __DIR__ . '/lib.php'; return xlsx_rows($tmp); }
  $t = (string)file_get_contents($tmp); $t = preg_replace('/^\xEF\xBB\xBF/', '', $t); if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
  $l1 = strtok($t, "\n"); $sep = ';'; foreach (["\t", ';', ','] as $c) if (substr_count((string)$l1, $c) > substr_count((string)$l1, $sep)) $sep = $c;
  $R = []; $f = fopen('php://memory', 'r+'); fwrite($f, $t); rewind($f); while (($r = fgetcsv($f, 0, $sep, '"', '')) !== false) if (array_filter($r, fn($x) => trim((string)$x) !== '')) $R[] = array_map(fn($x) => trim((string)$x), $r); fclose($f);
  return $R;
}
/** Repère la ligne de titres et les colonnes (référence, gamme, gencod, PVP, code article) dans un fichier de marque. */
function mc_import_analyse(array $rows): array {
  $K = ['ean' => '/\b(ean|gencod|gen\s*cod|code[\s-]*barre|gtin|upc)/iu', 'prix' => '/\b(pvp|pvc|ppc|ppi|prix\s*(de\s*vente\s*)?(public|conseill)|prix\s*ttc|prix\s*de\s*vente|retail|rrp)/iu',
    'code' => '/\b(code\s*article|cod\.?\s*art|r[ée]f\.?\s*(interne|article|fournisseur|produit)?$|sku|cip|acl|article\s*code|item\s*code|code\s*produit)/iu',
    'gamme' => '/\b(gamme|famille|cat[ée]gorie|ligne|univers|segment|marque\s*fille|range|axe)/iu', 'nom' => '/\b(libell[ée]|d[ée]signation|produit|r[ée]f[ée]rence|description|nom|article|intitul[ée]|product|name)/iu'];
  $best = -1; $bi = 0; $bm = [];
  foreach (array_slice($rows, 0, 20, true) as $i => $r) { $m = []; foreach ($K as $k => $re) foreach ($r as $j => $c) { if (in_array($j, $m, true)) continue; if (preg_match($re, (string)$c)) { $m[$k] = $j; break; } }
    if (count($m) > $best) { $best = count($m); $bi = $i; $bm = $m; } }
  // Sans titres reconnus : la colonne dont les valeurs sont des gencods, et la plus longue colonne de texte pour le nom.
  if (!isset($bm['ean']) || !isset($bm['nom'])) { $n = max(array_map('count', $rows ?: [[]])); $ech = array_slice($rows, $best > 0 ? $bi + 1 : 0, 30);
    for ($j = 0; $j < $n; $j++) { $v = array_filter(array_map(fn($r) => (string)($r[$j] ?? ''), $ech));
      if (!isset($bm['ean']) && $v && count(array_filter($v, fn($x) => preg_match('/^\d{8,14}$/', preg_replace('/\s/', '', $x)))) >= 0.6 * count($v)) $bm['ean'] = $j; }
    if (!isset($bm['nom'])) { $lg = []; for ($j = 0; $j < $n; $j++) if (!in_array($j, $bm, true)) $lg[$j] = array_sum(array_map(fn($r) => preg_match('/[a-z]/i', (string)($r[$j] ?? '')) ? mb_strlen((string)$r[$j]) : 0, $ech)); if ($lg) { arsort($lg); $bm['nom'] = array_key_first($lg); } } }
  $head = $best > 0 ? $bi : -1; $cols = $head >= 0 ? array_map(fn($x) => (string)$x, $rows[$head]) : [];
  $n = max(array_map('count', $rows ?: [[]])); for ($j = 0; $j < $n; $j++) if (($cols[$j] ?? '') === '') $cols[$j] = 'Colonne ' . xl_lettre($j);
  ksort($cols);
  return ['colonnes' => array_values($cols), 'map' => $bm, 'lignes' => array_values(array_slice($rows, $head + 1, 5000))];
}
function xl_lettre(int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
/** Prix lu dans un fichier (« 18,90 € », « 18.9 ») : nombre ou null. */
function mc_prix_lu($v): ?float { $v = str_replace(["\xc2\xa0", ' ', '€', 'EUR'], '', (string)$v); $v = str_replace(',', '.', $v); return $v !== '' && is_numeric($v) ? round((float)$v, 2) : null; }
/** Applique un fichier de la marque au catalogue (espace marques) : PVP des références connues, nouvelles références ajoutées non cochées. */
function mc_import_marque(int $marque, array $A, array $map, string $par): array {
  mc_schema(); $col = fn($r, $k) => isset($map[$k]) && $map[$k] !== '' && $map[$k] !== null ? trim((string)($r[(int)$map[$k]] ?? '')) : '';
  $ex = []; foreach (mc_refs($marque, true) as $r) { if ($r['ean'] !== '') $ex['e' . $r['ean']] = $r; if ($r['code'] !== '') $ex['c' . mb_strtolower($r['code'])] = $r; $ex['n' . mb_strtolower($r['nom'])] ??= $r; }
  $n = ['maj' => 0, 'ajout' => 0, 'ignore' => 0, 'invalides' => [] ]; $o = (int)db()->query("SELECT COALESCE(MAX(ordre), 0) FROM merch_refs WHERE marque_id = " . $marque)->fetchColumn();
  foreach ($A['lignes'] as $r) { $nom = clip($col($r, 'nom'), 160); $ean = preg_replace('/\D/', '', $col($r, 'ean')); if (strlen($ean) === 12) $ean = '0' . $ean; $code = clip($col($r, 'code'), 40); $px = mc_prix_lu($col($r, 'prix'));
    if ($nom === '' && $ean === '') { $n['ignore']++; continue; } if ($ean !== '' && !mc_ean_ok($ean)) { $n['invalides'][] = $ean; $ean = ''; }
    $e = ($ean !== '' ? ($ex['e' . $ean] ?? null) : null) ?? ($code !== '' ? ($ex['c' . mb_strtolower($code)] ?? null) : null) ?? ($nom !== '' ? ($ex['n' . mb_strtolower($nom)] ?? null) : null);
    if ($e) { if ($px !== null && (float)$e['prix_conseille'] !== $px) { db()->prepare("UPDATE merch_refs SET prix_conseille = ?, pvp_par = ?, pvp_le = ? WHERE id = ?")->execute([$px, $par, now(), $e['id']]); $n['maj']++; } continue; }
    if ($nom === '') { $n['ignore']++; continue; }
    db()->prepare("INSERT INTO merch_refs (marque_id, nom, ean, gamme, code, prix_conseille, pvp_par, pvp_le, ordre, actif, a_relever) VALUES (?,?,?,?,?,?,?,?,?,1,0)")
      ->execute([$marque, $nom, $ean, clip($col($r, 'gamme'), 80), $code, $px, $px !== null ? $par : '', $px !== null ? now() : null, ++$o]); $n['ajout']++;
    $ex['e' . $ean] = $ex['n' . mb_strtolower($nom)] = ['id' => db()->lastInsertId(), 'prix_conseille' => $px] + ['ean' => $ean]; }
  $n['invalides'] = array_slice(array_unique($n['invalides']), 0, 10); return $n;
}
function mc_refs(int $marque, bool $tous = false): array {
  mc_schema(); $st = db()->prepare("SELECT * FROM merch_refs WHERE marque_id = ?" . ($tous ? '' : ' AND actif = 1') . " ORDER BY ordre, gamme, nom"); $st->execute([$marque]);
  return array_map(fn($r) => $r + ['prix' => $r['prix_conseille'] === null ? null : (float)$r['prix_conseille']], $st->fetchAll());
}
/** Marque suivie pour une opération : celle du client du devis (Clients › marque liée). */
function mc_marque_devis(int $devis): ?int {
  $st = db()->prepare("SELECT c.marque_id FROM g_docs d JOIN g_clients c ON c.id = d.client_id WHERE d.id = ?"); $st->execute([$devis]); $v = $st->fetchColumn(); return $v ? (int)$v : null;
}
/** Visites de merchandising de la personne : aujourd'hui et les 7 derniers jours (une ligne par point de la tournée). */
function mc_missions(int $aid): array {
  mc_schema(); p_schema();
  $st = db()->prepare("SELECT m.id, m.devis_id, m.date_mission, m.magasin, m.ligne, m.debut, m.fin, d.type_op FROM missions m JOIN g_docs d ON d.id = m.devis_id
    WHERE m.animatrice_id = ? AND m.statut <> 'annulee' AND m.date_mission BETWEEN ? AND ? ORDER BY m.date_mission DESC, m.debut");
  $st->execute([$aid, date('Y-m-d', strtotime('-7 days')), date('Y-m-d')]);
  $fait = db()->prepare("SELECT id FROM merch_visites WHERE animatrice_id = ? AND devis_id = ? AND date_visite = ? AND magasin = ?"); $out = [];
  foreach ($st->fetchAll() as $m) {
    $d = g_doc((int)$m['devis_id']); $l = $d['lignes'][(int)$m['ligne']] ?? []; if (!g_est_merch($d['type_op'] ?? '', $l['role'] ?? '')) continue;
    $tour = !empty($l['tournee']); $pts = $tour ? array_column($l['magasins'] ?? [], 'nom') : [$m['magasin']]; $mq = mc_marque_devis((int)$m['devis_id']);
    foreach ($pts as $k => $p) { $fait->execute([$aid, $m['devis_id'], $m['date_mission'], $p]);
      $out[] = ['devis_id' => (int)$m['devis_id'], 'date' => $m['date_mission'], 'magasin' => $p, 'ordre' => $k + 1, 'nb' => count($pts), 'tournee' => $tour, 'debut' => $m['debut'], 'fin' => $m['fin'],
        'client' => (string)($d['client']['raison'] ?? ''), 'operation' => (string)($d['objet'] ?: $d['type_op']), 'type' => (string)($d['type_op'] ?? ''), 'poste' => (string)($l['poste'] ?? ''),
        'marque_id' => $mq, 'fait' => (bool)$fait->fetchColumn()]; }
  }
  return $out;
}
/** Nettoie la saisie d'une visite. */
function mc_clean(array $in, array $refs): array {
  $n = fn($v, $max = 100000) => ($v = trim(str_replace([',', ' '], ['.', ''], (string)$v))) === '' || !is_numeric($v) ? null : max(0, min($max, (float)$v));
  $R = []; $ids = array_column($refs, 'id');
  foreach ((array)($in['refs'] ?? []) as $r) { $id = (int)($r['id'] ?? 0); if (!in_array($id, array_map('intval', $ids), true)) continue;
    $s = isset(MC_STATUTS[$r['statut'] ?? '']) ? $r['statut'] : '';
    $R[$id] = ['id' => $id, 'statut' => $s, 'prix' => $s === 'absent' ? null : $n($r['prix'] ?? '', 100000), 'facings' => $s === 'absent' ? null : ($n($r['facings'] ?? '', 500) === null ? null : (int)$n($r['facings'], 500)),
      'etiquette' => !empty($r['etiquette']) ? 1 : 0, 'promo' => !empty($r['promo']) ? 1 : 0, 'promo_txt' => clip($r['promo_txt'] ?? '', 120)]; }
  return ['refs' => array_values($R), 'taches' => array_values(array_intersect(MC_TACHES, (array)($in['taches'] ?? []))),
    'lineaire_marque' => $n($in['lineaire_marque'] ?? '', 10000), 'lineaire_total' => $n($in['lineaire_total'] ?? '', 100000), 'tablettes' => ($t = $n($in['tablettes'] ?? '', 50)) === null ? null : (int)$t,
    'emplacement' => in_array($in['emplacement'] ?? '', MC_EMPLACEMENTS, true) ? $in['emplacement'] : '', 'hauteur' => in_array($in['hauteur'] ?? '', MC_HAUTEURS, true) ? $in['hauteur'] : '',
    'plv_presente' => !empty($in['plv_presente']) ? 1 : 0, 'plv_posee' => !empty($in['plv_posee']) ? 1 : 0, 'promo_en_place' => !empty($in['promo_en_place']) ? 1 : 0, 'promo_txt' => clip($in['promo_txt'] ?? '', 200),
    'reserve' => isset(MC_RESERVE[$in['reserve'] ?? '']) ? $in['reserve'] : '', 'concurrence' => clip($in['concurrence'] ?? '', 1500),
    'inconnus' => array_values(array_slice(array_unique(array_filter(array_map(fn($x) => preg_replace('/\D/', '', (string)(is_array($x) ? ($x['ean'] ?? '') : $x)), (array)($in['inconnus'] ?? [])), fn($x) => strlen($x) >= 8 && strlen($x) <= 14)), 0, 50)),
    'scannes' => max(0, min(500, (int)($in['scannes'] ?? 0))),
    'interlocuteur' => clip($in['interlocuteur'] ?? '', 120), 'interlocuteur_fonction' => clip($in['interlocuteur_fonction'] ?? '', 80), 'commentaire' => clip($in['commentaire'] ?? '', 3000)];
}
/** Une visite complète, avec ses références (nom, prix conseillé) et ses photos. */
function mc_row(int $id): ?array {
  mc_schema(); $st = db()->prepare("SELECT v.*, CONCAT(a.prenom, ' ', a.nom) AS auteur FROM merch_visites v JOIN animatrices a ON a.id = v.animatrice_id WHERE v.id = ?"); $st->execute([$id]); $v = $st->fetch(); if (!$v) return null;
  $v['data'] = json_decode((string)$v['data'], true) ?: []; $refs = $v['marque_id'] ? mc_refs((int)$v['marque_id'], true) : []; $byId = []; foreach ($refs as $r) $byId[(int)$r['id']] = $r;
  foreach ($v['data']['refs'] ?? [] as $k => $r) $v['data']['refs'][$k] += ['nom' => $byId[$r['id']]['nom'] ?? '?', 'gamme' => $byId[$r['id']]['gamme'] ?? '', 'ean' => $byId[$r['id']]['ean'] ?? '', 'conseille' => $byId[$r['id']]['prix'] ?? null];
  $d = $v['devis_id'] ? g_doc((int)$v['devis_id']) : null; $v['operation'] = (string)($d['objet'] ?? ''); $v['client'] = (string)($d['client']['raison'] ?? '');
  $m = $v['marque_id'] ? db()->query("SELECT nom FROM marques WHERE id = " . (int)$v['marque_id'])->fetchColumn() : ''; $v['marque'] = (string)$m;
  $ph = db()->prepare("SELECT id, slot, fichier, client FROM photos WHERE merch_id = ? ORDER BY slot, id"); $ph->execute([$id]);
  $v['photos'] = array_map(fn($p) => $p + ['url' => photo_url($p['fichier'], true), 'full' => photo_url($p['fichier'])], $ph->fetchAll());
  $v['duree'] = $v['arrivee'] && $v['depart'] ? max(0, g_hm($v['depart']) - g_hm($v['arrivee'])) : null;
  return $v;
}
/** Liste filtrée : du, au, marques (ids), statut (avalider / valide), secteur, devis_id. */
function mc_liste(array $f): array {
  mc_schema(); $w = ['v.date_visite BETWEEN ? AND ?']; $a = [$f['from'] ?: '2000-01-01', $f['to'] ?: '2100-01-01'];
  if (!empty($f['devis_id'])) { $w[] = 'v.devis_id = ?'; $a[] = (int)$f['devis_id']; }
  if (($f['statut'] ?? '') === 'avalider') $w[] = 'v.valide_le IS NULL'; if (($f['statut'] ?? '') === 'valide') $w[] = 'v.valide_le IS NOT NULL';
  if (isset($f['marques'])) { $m = array_values(array_filter(array_map('intval', (array)$f['marques']))); if (!$m) return []; $w[] = 'v.marque_id IN (' . implode(',', $m) . ')'; }
  if (!empty($f['zone'])) { $sql = ''; if (function_exists('secteur_sql')) { $z = secteur_sql((string)$f['zone'], $a, '(SELECT mg.id FROM magasins mg WHERE mg.nom = v.magasin LIMIT 1)'); if ($z) $w[] = $z; } }
  $st = db()->prepare("SELECT v.id, v.date_visite, v.magasin, v.ville, v.arrivee, v.depart, v.marque_id, v.devis_id, v.valide_le, v.data, CONCAT(a.prenom, ' ', a.nom) AS auteur,
      (SELECT nom FROM marques WHERE id = v.marque_id) AS marque, (SELECT COUNT(*) FROM photos p WHERE p.merch_id = v.id) AS nb_photos
    FROM merch_visites v JOIN animatrices a ON a.id = v.animatrice_id WHERE " . implode(' AND ', $w) . " ORDER BY v.date_visite DESC, v.id DESC LIMIT 1000");
  $st->execute($a);
  return array_map(function ($r) { $d = json_decode((string)$r['data'], true) ?: []; unset($r['data']); $R = $d['refs'] ?? [];
    $r['refs'] = count($R); $r['ruptures'] = count(array_filter($R, fn($x) => $x['statut'] === 'rupture')); $r['absents'] = count(array_filter($R, fn($x) => $x['statut'] === 'absent'));
    $r['taches'] = count($d['taches'] ?? []); return $r; }, $st->fetchAll());
}

/* ---------- Analyse pour le rapport ---------- */
/** Indicateurs d'un ensemble de visites (validées) : par référence, par point d'intervention, globaux. */
function mc_stats(array $V): array {
  $refs = []; $pdv = []; $T = ['visites' => count($V), 'pdv' => 0, 'releves' => 0, 'present' => 0, 'rupture' => 0, 'absent' => 0, 'prix_ok' => 0, 'prix_n' => 0, 'ecart' => [], 'etiquette' => 0, 'etiq_n' => 0,
    'facings' => 0, 'promo' => 0, 'plv' => 0, 'plv_posee' => 0, 'lin' => [], 'taches' => [], 'duree' => [], 'reserve' => []];
  foreach ($V as $v) { $d = $v['data'];
    $p = &$pdv[$v['magasin']]; $p ??= ['nom' => $v['magasin'], 'ville' => $v['ville'], 'visites' => 0, 'derniere' => '', 'present' => 0, 'rupture' => 0, 'absent' => 0, 'releves' => 0, 'lin' => null, 'promo' => 0, 'plv' => 0, 'facings' => 0];
    $p['visites']++; $p['derniere'] = max($p['derniere'], $v['date_visite']); $p['promo'] = max($p['promo'], (int)($d['promo_en_place'] ?? 0)); $p['plv'] = max($p['plv'], (int)(($d['plv_presente'] ?? 0) || ($d['plv_posee'] ?? 0)));
    if (($d['lineaire_marque'] ?? null) && ($d['lineaire_total'] ?? null)) { $part = 100 * $d['lineaire_marque'] / max(1, $d['lineaire_total']); $T['lin'][] = $part; $p['lin'] = round($part, 1); }
    foreach ($d['taches'] ?? [] as $t) $T['taches'][$t] = ($T['taches'][$t] ?? 0) + 1;
    if ($v['duree'] !== null) $T['duree'][] = $v['duree']; if ($d['reserve'] ?? '') $T['reserve'][$d['reserve']] = ($T['reserve'][$d['reserve']] ?? 0) + 1;
    $T['promo'] += (int)($d['promo_en_place'] ?? 0); $T['plv'] += (int)($d['plv_presente'] ?? 0); $T['plv_posee'] += (int)($d['plv_posee'] ?? 0);
    foreach ($d['refs'] ?? [] as $r) { if (!$r['statut']) continue; $T['releves']++; $T[$r['statut']]++; $p['releves']++; $p[$r['statut']]++;
      $q = &$refs[$r['id']]; $q ??= ['id' => $r['id'], 'nom' => $r['nom'], 'gamme' => $r['gamme'], 'ean' => $r['ean'], 'conseille' => $r['conseille'], 'n' => 0, 'present' => 0, 'rupture' => 0, 'absent' => 0, 'prix' => [], 'facings' => [], 'promo' => 0];
      $q['n']++; $q[$r['statut']]++; if ($r['statut'] !== 'absent') { $T['etiq_n']++; $T['etiquette'] += (int)$r['etiquette']; }
      if ($r['prix'] !== null) { $q['prix'][] = (float)$r['prix']; if ($r['conseille']) { $T['prix_n']++; $e = 100 * ((float)$r['prix'] - $r['conseille']) / $r['conseille']; $T['ecart'][] = $e; if (abs($e) <= 2) $T['prix_ok']++; } }
      if ($r['facings'] !== null) { $q['facings'][] = (int)$r['facings']; $T['facings'] += (int)$r['facings']; $p['facings'] += (int)$r['facings']; }
      $q['promo'] += (int)$r['promo']; unset($q); }
    unset($p); }
  $T['pdv'] = count($pdv); $avg = fn($L) => $L ? array_sum($L) / count($L) : null;
  $T['distribution'] = $T['releves'] ? round(100 * ($T['present'] + $T['rupture']) / $T['releves'], 1) : null; // référencé (présent ou en rupture)
  $T['taux_rupture'] = ($T['present'] + $T['rupture']) ? round(100 * $T['rupture'] / ($T['present'] + $T['rupture']), 1) : null;
  $T['conformite_prix'] = $T['prix_n'] ? round(100 * $T['prix_ok'] / $T['prix_n'], 1) : null; $T['ecart_moyen'] = $T['ecart'] ? round($avg($T['ecart']), 1) : null;
  $T['etiquetage'] = $T['etiq_n'] ? round(100 * $T['etiquette'] / $T['etiq_n'], 1) : null; $T['part_lineaire'] = $T['lin'] ? round($avg($T['lin']), 1) : null;
  $T['duree_moy'] = $T['duree'] ? (int)round($avg($T['duree'])) : null; arsort($T['taches']);
  foreach ($refs as &$q) { $q['prix_moy'] = $q['prix'] ? round($avg($q['prix']), 2) : null; $q['prix_min'] = $q['prix'] ? min($q['prix']) : null; $q['prix_max'] = $q['prix'] ? max($q['prix']) : null;
    $q['ecart'] = $q['prix_moy'] !== null && $q['conseille'] ? round(100 * ($q['prix_moy'] - $q['conseille']) / $q['conseille'], 1) : null; $q['facings_moy'] = $q['facings'] ? round($avg($q['facings']), 1) : null;
    $q['dn'] = $q['n'] ? round(100 * ($q['present'] + $q['rupture']) / $q['n']) : null; $q['tx_rupture'] = ($q['present'] + $q['rupture']) ? round(100 * $q['rupture'] / ($q['present'] + $q['rupture'])) : null; } unset($q);
  usort($refs, fn($a, $b) => [$b['tx_rupture'] ?? -1, $a['nom']] <=> [$a['tx_rupture'] ?? -1, $b['nom']]);
  $pdv = array_values($pdv); usort($pdv, fn($a, $b) => [$b['rupture'] + $b['absent'], $a['nom']] <=> [$a['rupture'] + $a['absent'], $b['nom']]);
  return ['tot' => $T, 'refs' => $refs, 'pdv' => $pdv];
}
/** Recommandations proposées : point de départ que l'agence relit avant l'envoi. */
function mc_reco_auto(array $S): string {
  $T = $S['tot']; $R = []; if (!$T['visites']) return '';
  if ($T['taux_rupture'] !== null && $T['taux_rupture'] >= 5) { $top = array_slice(array_filter($S['refs'], fn($q) => ($q['tx_rupture'] ?? 0) >= 10), 0, 3);
    $R[] = 'Ruptures sur ' . $T['taux_rupture'] . ' % des relevés' . ($top ? ', surtout ' . implode(', ', array_map(fn($q) => $q['nom'] . ' (' . $q['tx_rupture'] . ' %)', $top)) : '') . ' : renforcer le réassort ou la fréquence de passage.'; }
  $abs = array_filter($S['pdv'], fn($p) => $p['absent'] > 0); if ($abs) $R[] = 'Références non référencées dans ' . count($abs) . ' point' . (count($abs) > 1 ? 's' : '') . ' d\'intervention (' . implode(', ', array_slice(array_column($abs, 'nom'), 0, 3)) . ') : à remonter à l\'équipe commerciale.';
  if ($T['conformite_prix'] !== null && $T['conformite_prix'] < 90) $R[] = 'Prix conformes au PVP conseillé (±2 %) dans ' . $T['conformite_prix'] . ' % des relevés' . ($T['ecart_moyen'] !== null ? ', écart moyen ' . ($T['ecart_moyen'] > 0 ? '+' : '') . $T['ecart_moyen'] . ' %' : '') . ' : vérifier les étiquettes et les accords tarifaires.';
  if ($T['etiquetage'] !== null && $T['etiquetage'] < 95) $R[] = 'Étiquette prix présente pour ' . $T['etiquetage'] . ' % des références en rayon : prévoir un passage étiquetage.';
  if ($T['part_lineaire'] !== null) $R[] = 'Part de linéaire moyenne de la marque : ' . str_replace('.', ',', (string)$T['part_lineaire']) . ' %.';
  if ($T['visites'] && $T['promo'] < $T['visites']) $R[] = 'Promotion en place dans ' . round(100 * $T['promo'] / $T['visites']) . ' % des visites : vérifier la remontée des mises en avant.';
  if (($T['reserve']['vide'] ?? 0) + ($T['reserve']['faible'] ?? 0)) $R[] = 'Réserve vide ou faible lors de ' . (($T['reserve']['vide'] ?? 0) + ($T['reserve']['faible'] ?? 0)) . ' visite(s) : anticiper les commandes.';
  return implode("\n", $R);
}
function mc_visites_completes(array $f): array { return array_values(array_filter(array_map(fn($r) => mc_row((int)$r['id']), mc_liste($f)))); }
/** Rapport merchandising (PDF) : synthèse chiffrée, références, points d'intervention, avant/après, recommandations. */
function mc_report_html(array $V, array $p, string $reco): string {
  $r = g_reg(); $c = ag_couleur(); $S = mc_stats($V); $T = $S['tot']; $nf = fn($v, $d = 0) => $v === null ? '–' : number_format((float)$v, $d, ',', ' ');
  $pc = fn($v) => $v === null ? '–' : str_replace('.', ',', (string)$v) . ' %'; $eur = fn($v) => $v === null ? '–' : number_format((float)$v, 2, ',', ' ') . ' €';
  $kpi = fn($n, $l) => '<td><div class="k">' . $n . '</div><div class="l">' . h($l) . '</div></td>';
  $marques = implode(', ', array_unique(array_filter(array_column($V, 'marque')))) ?: 'Merchandising';
  $per = $p['from'] && $p['to'] ? 'Du ' . g_date($p['from']) . ' au ' . g_date($p['to']) : '';
  $bar = fn($v, $col) => '<div class="bar"><i style="width:' . max(0, min(100, (float)$v)) . '%;background:' . $col . '"></i></div>';
  $rl = array_filter(array_map('trim', explode("\n", $reco)), fn($l) => $l !== '' && $l !== '-');
  $refRows = ''; foreach ($S['refs'] as $q) $refRows .= '<tr><td><b>' . h($q['nom']) . '</b>' . ($q['gamme'] ? '<div class="mut">' . h($q['gamme']) . ($q['ean'] ? ' · ' . h($q['ean']) : '') . '</div>' : '') . '</td>'
    . '<td class="n">' . $pc($q['dn']) . '</td><td class="n' . (($q['tx_rupture'] ?? 0) >= 10 ? ' red' : '') . '">' . $pc($q['tx_rupture']) . '</td><td class="n">' . $eur($q['conseille']) . '</td><td class="n">' . $eur($q['prix_moy'])
    . ($q['prix_min'] !== null && $q['prix_min'] != $q['prix_max'] ? '<div class="mut">' . $eur($q['prix_min']) . ' à ' . $eur($q['prix_max']) . '</div>' : '') . '</td>'
    . '<td class="n' . ($q['ecart'] !== null && abs($q['ecart']) > 2 ? ' red' : '') . '">' . ($q['ecart'] === null ? '–' : ($q['ecart'] > 0 ? '+' : '') . str_replace('.', ',', (string)$q['ecart']) . ' %') . '</td><td class="n">' . $nf($q['facings_moy'], 1) . '</td></tr>';
  $pdvRows = ''; foreach ($S['pdv'] as $q) $pdvRows .= '<tr><td><b>' . h($q['nom']) . '</b>' . ($q['ville'] ? '<div class="mut">' . h($q['ville']) . '</div>' : '') . '</td><td class="n">' . $q['visites'] . '</td><td>' . h(g_date($q['derniere'])) . '</td>'
    . '<td class="n">' . $q['present'] . '</td><td class="n' . ($q['rupture'] ? ' red' : '') . '">' . $q['rupture'] . '</td><td class="n' . ($q['absent'] ? ' red' : '') . '">' . $q['absent'] . '</td><td class="n">' . ($q['lin'] === null ? '–' : $pc($q['lin'])) . '</td>'
    . '<td class="c">' . ($q['promo'] ? 'oui' : 'non') . '</td><td class="c">' . ($q['plv'] ? 'oui' : 'non') . '</td></tr>';
  $tch = ''; $mx = max(1, (int)max([1] + array_values($T['taches']))); foreach ($T['taches'] as $t => $n) $tch .= '<tr><td>' . h($t) . '</td><td class="n">' . $n . '</td><td style="width:45%">' . $bar(100 * $n / $mx, $c) . '</td></tr>';
  // Avant / après : une paire par visite (jusqu'à 8 visites)
  $aa = ''; $nP = 0; foreach ($V as $v) { if ($nP >= 8) break; $av = array_values(array_filter($v['photos'], fn($x) => $x['slot'] === 'avant' && (int)$x['client'])); $ap = array_values(array_filter($v['photos'], fn($x) => $x['slot'] === 'apres' && (int)$x['client']));
    if (!$av || !$ap || !is_file(upload_dir() . '/' . $av[0]['fichier'] . '_t.jpg') || !is_file(upload_dir() . '/' . $ap[0]['fichier'] . '_t.jpg')) continue; $nP++;
    $aa .= '<table class="aa"><tr><td><img src="uploads/' . h($av[0]['fichier']) . '_t.jpg"><div class="cap">Avant</div></td><td><img src="uploads/' . h($ap[0]['fichier']) . '_t.jpg"><div class="cap">Après</div></td></tr>'
      . '<tr><td colspan="2" class="mut">' . h($v['magasin']) . ' · ' . h(g_date($v['date_visite'])) . ($v['data']['taches'] ? ' · ' . h(implode(', ', array_slice($v['data']['taches'], 0, 3))) : '') . '</td></tr></table>'; }
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:36px">' : '<b>' . h($r['societe'] ?: AG_NOM) . '</b>';
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:15mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9pt;color:#16123A;line-height:1.45}h1{font-size:19pt;color:' . $c . ';margin:12pt 0 2pt}
    h2{font-size:12pt;color:' . $c . ';margin:16pt 0 5pt;border-bottom:1pt solid #E6E1F5;padding-bottom:2pt}.mut{color:#6E6A8F;font-size:7.8pt}
    table.kp{width:100%;border-collapse:separate;border-spacing:5pt 5pt;margin:8pt -5pt}table.kp td{background:#F4F2FF;border-radius:6pt;padding:7pt;text-align:center;width:25%}.k{font-size:15pt;font-weight:bold}.l{font-size:7.3pt;color:#6E6A8F}
    table.t{width:100%;border-collapse:collapse}table.t th{text-align:left;font-size:7.3pt;color:#6E6A8F;border-bottom:1pt solid #E6E1F5;padding:3pt}table.t th.n{text-align:right}table.t td{padding:3.5pt 3pt;border-bottom:.5pt solid #EEE;vertical-align:top}
    .n{text-align:right}.c{text-align:center}.red{color:#C0392B;font-weight:bold}.bar{background:#EEEAFB;height:7pt;border-radius:4pt}.bar i{display:block;height:7pt;border-radius:4pt}
    table.aa{width:100%;margin:0 0 8pt;page-break-inside:avoid}table.aa td{width:50%;vertical-align:top;padding:2pt}table.aa img{width:100%;border-radius:4pt}.cap{font-size:7.5pt;font-weight:bold;color:' . $c . '}ul{margin:0 0 6pt 14pt;padding:0}</style></head><body>' . $logo
    . '<h1>Rapport merchandising</h1><p><b>' . h($marques) . '</b><br>' . h($per) . (!empty($p['zone']) && function_exists('secteur_label') ? ' · ' . h(secteur_label((string)$p['zone'])) : '') . '</p>'
    . '<table class="kp"><tr>' . $kpi($T['visites'], 'visites') . $kpi($T['pdv'], 'points d\'intervention') . $kpi($pc($T['distribution']), 'distribution (références présentes ou en rupture)') . $kpi($pc($T['taux_rupture']), 'taux de rupture') . '</tr>'
    . '<tr>' . $kpi($pc($T['conformite_prix']), 'prix conformes (±2 %)') . $kpi($T['ecart_moyen'] === null ? '–' : ($T['ecart_moyen'] > 0 ? '+' : '') . str_replace('.', ',', (string)$T['ecart_moyen']) . ' %', 'écart moyen au prix conseillé')
    . $kpi($pc($T['part_lineaire']), 'part de linéaire moyenne') . $kpi($T['visites'] ? round(100 * $T['promo'] / $T['visites']) . ' %' : '–', 'visites avec promotion en place') . '</tr>'
    . '<tr>' . $kpi($pc($T['etiquetage']), 'étiquetage prix présent') . $kpi($nf($T['facings']), 'facings relevés') . $kpi($T['visites'] ? round(100 * $T['plv'] / $T['visites']) . ' %' : '–', 'PLV présente à l\'arrivée') . $kpi($T['duree_moy'] === null ? '–' : g_dur($T['duree_moy']), 'durée moyenne de visite') . '</tr></table>'
    . ($rl ? '<h2>Recommandations</h2><ul>' . implode('', array_map(fn($x) => '<li>' . h(ltrim($x, "-• ")) . '</li>', $rl)) . '</ul>' : '')
    . ($refRows ? '<h2>Par référence</h2><table class="t"><tr><th>Référence</th><th class="n">Distribution</th><th class="n">Rupture</th><th class="n">PVP conseillé</th><th class="n">Prix relevé</th><th class="n">Écart</th><th class="n">Facings</th></tr>' . $refRows . '</table>' : '')
    . ($pdvRows ? '<h2>Par point d\'intervention</h2><table class="t"><tr><th>Point d\'intervention</th><th class="n">Visites</th><th>Dernière</th><th class="n">Présentes</th><th class="n">Ruptures</th><th class="n">Non réf.</th><th class="n">Linéaire</th><th class="c">Promo</th><th class="c">PLV</th></tr>' . $pdvRows . '</table>' : '')
    . ($tch ? '<h2>Travaux réalisés</h2><table class="t">' . $tch . '</table>' : '')
    . ($aa ? '<h2>Avant / après</h2>' . $aa : '') . '</body></html>';
}
function mc_pdf(string $html): string {
  require_once __DIR__ . '/report_lib.php'; @set_time_limit(180);
  $o = new Dompdf\Options(); $o->set('isRemoteEnabled', false); $o->set('chroot', __DIR__); $o->set('defaultFont', 'DejaVu Sans'); $o->set('tempDir', sys_get_temp_dir()); $o->set('fontCache', sys_get_temp_dir());
  $pdf = new Dompdf\Dompdf($o); $pdf->setBasePath(__DIR__ . '/'); $pdf->loadHtml($html, 'UTF-8'); $pdf->setPaper('A4'); $pdf->render(); return $pdf->output();
}
/** Excel : une ligne par visite et par référence relevée, plus un onglet de synthèse par point d'intervention. */
function mc_xlsx(array $V): string {
  require_once __DIR__ . '/xlsx_lib.php'; $S = mc_stats($V);
  $rows = [['Date', 'Point d\'intervention', 'Ville', 'Intervenant(e)', 'Marque', 'Référence', 'EAN', 'Statut', 'Prix relevé', 'PVP conseillé', 'Écart %', 'Facings', 'Étiquette', 'Promotion', 'Linéaire marque (cm)', 'Linéaire total (cm)', 'Emplacement', 'Tâches']];
  foreach ($V as $v) { $d = $v['data']; foreach ($d['refs'] ?: [['nom' => '', 'ean' => '', 'statut' => '', 'prix' => null, 'conseille' => null, 'facings' => null, 'etiquette' => 0, 'promo' => 0, 'promo_txt' => '']] as $r)
    $rows[] = [$v['date_visite'], $v['magasin'], $v['ville'], $v['auteur'], $v['marque'], $r['nom'], $r['ean'], MC_STATUTS[$r['statut']] ?? '', $r['prix'], $r['conseille'],
      $r['prix'] !== null && $r['conseille'] ? round(100 * ($r['prix'] - $r['conseille']) / $r['conseille'], 1) : null, $r['facings'], $r['statut'] === 'absent' ? '' : ($r['etiquette'] ? 'oui' : 'non'),
      $r['promo'] ? ($r['promo_txt'] ?: 'oui') : '', $d['lineaire_marque'] ?? null, $d['lineaire_total'] ?? null, $d['emplacement'] ?? '', implode(', ', $d['taches'] ?? [])]; }
  $pdv = [['Point d\'intervention', 'Ville', 'Visites', 'Dernière visite', 'Références présentes', 'Ruptures', 'Non référencées', 'Part de linéaire %', 'Promotion', 'PLV']];
  foreach ($S['pdv'] as $q) $pdv[] = [$q['nom'], $q['ville'], $q['visites'], $q['derniere'], $q['present'], $q['rupture'], $q['absent'], $q['lin'], $q['promo'] ? 'oui' : 'non', $q['plv'] ? 'oui' : 'non'];
  $ref = [['Référence', 'Gamme', 'EAN', 'Relevés', 'Distribution %', 'Rupture %', 'PVP conseillé', 'Prix moyen', 'Prix min', 'Prix max', 'Écart %', 'Facings moyens']];
  foreach ($S['refs'] as $q) $ref[] = [$q['nom'], $q['gamme'], $q['ean'], $q['n'], $q['dn'], $q['tx_rupture'], $q['conseille'], $q['prix_moy'], $q['prix_min'], $q['prix_max'], $q['ecart'], $q['facings_moy']];
  return xl_simple(['Relevés' => $rows, 'Par point' => $pdv, 'Par référence' => $ref]);
}

/* ---------- Gencods (EAN-13) ---------- */
/** Clé de contrôle d'un EAN-13 à partir des 12 premiers chiffres. */
function mc_ean_cle(string $d12): int { $s = 0; for ($i = 0; $i < 12; $i++) $s += (int)$d12[$i] * ($i % 2 ? 3 : 1); return (10 - $s % 10) % 10; }
/** Gencod valide : EAN-13 (ou EAN-8 / UPC-A ramenés sur 13 chiffres). */
function mc_ean_norm(string $c): string { $c = preg_replace('/\D/', '', $c); if (strlen($c) === 12) $c = '0' . $c; return $c; }
function mc_ean_ok(string $c): bool {
  $c = mc_ean_norm($c);
  if (strlen($c) === 13) return mc_ean_cle(substr($c, 0, 12)) === (int)$c[12];
  if (strlen($c) === 8) { $s = 0; for ($i = 0; $i < 7; $i++) $s += (int)$c[$i] * ($i % 2 ? 1 : 3); return (10 - $s % 10) % 10 === (int)$c[7]; }
  return false;
}
/** Image PNG d'un code-barres EAN-13 (planche de test, étiquettes). */
function mc_ean_png(string $c, int $m = 3, int $h = 90): string {
  $c = mc_ean_norm($c); if (strlen($c) !== 13) return '';
  $L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
  $G = array_map(fn($x) => strrev(strtr($x, '01', '10')), $L); $R = array_map(fn($x) => strtr($x, '01', '10'), $L);
  $par = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'][(int)$c[0]];
  $b = '101'; for ($i = 1; $i <= 6; $i++) $b .= ($par[$i - 1] === 'L' ? $L : $G)[(int)$c[$i]]; $b .= '01010'; for ($i = 7; $i <= 12; $i++) $b .= $R[(int)$c[$i]]; $b .= '101';
  $q = 11 * $m; $w = strlen($b) * $m + 2 * $q; $im = imagecreatetruecolor($w, $h + 22); $wh = imagecolorallocate($im, 255, 255, 255); $bk = imagecolorallocate($im, 0, 0, 0); imagefill($im, 0, 0, $wh);
  for ($i = 0, $n = strlen($b); $i < $n; $i++) if ($b[$i] === '1') { $garde = $i < 3 || ($i >= 45 && $i < 50) || $i >= 92; imagefilledrectangle($im, $q + $i * $m, 4, $q + ($i + 1) * $m - 1, $h + ($garde ? 10 : 0), $bk); }
  $f = 5; $cw = imagefontwidth($f); imagestring($im, $f, $q - $cw - 2, $h + 4, $c[0], $bk);
  imagestring($im, $f, $q + 3 * $m + (int)((42 * $m - 6 * $cw) / 2), $h + 4, substr($c, 1, 6), $bk); imagestring($im, $f, $q + 50 * $m + (int)((42 * $m - 6 * $cw) / 2), $h + 4, substr($c, 7, 6), $bk);
  ob_start(); imagepng($im); imagedestroy($im); return (string)ob_get_clean();
}
/** Planche des gencods d'une marque (PDF) : pour imprimer et tester la scanette, ou coller sur un présentoir. */
function mc_planche_html(int $marque): string {
  $refs = mc_refs($marque); $nom = (string)db()->query("SELECT nom FROM marques WHERE id = " . $marque)->fetchColumn(); $c = ag_couleur(); $cells = '';
  foreach ($refs as $i => $r) { $png = mc_ean_ok((string)$r['ean']) ? mc_ean_png((string)$r['ean']) : '';
    $cells .= ($i % 2 ? '' : '<tr>') . '<td><b>' . h($r['nom']) . '</b><div class="mut">' . h($r['gamme']) . ($r['prix'] !== null ? ' · ' . number_format($r['prix'], 2, ',', ' ') . ' €' : '') . '</div>'
      . ($png ? '<img src="data:image/png;base64,' . base64_encode($png) . '">' : '<div class="mut">Gencod absent ou invalide</div>') . '</td>' . ($i % 2 ? '</tr>' : ''); }
  if (count($refs) % 2) $cells .= '<td></td></tr>';
  return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:12mm}body{font-family:"DejaVu Sans",sans-serif;font-size:9pt;color:#16123A}h1{font-size:15pt;color:' . $c . ';margin:0 0 2pt}.mut{color:#6E6A8F;font-size:7.5pt;margin:1pt 0 4pt}
    table{width:100%;border-collapse:collapse}td{width:50%;border:.5pt dashed #CCC;padding:8pt;vertical-align:top;page-break-inside:avoid}img{width:62mm}</style></head><body>
    <h1>Gencods · ' . h($nom) . '</h1><div class="mut">Planche de test : scannez ces codes depuis l\'appli (Visite merchandising › Scanner) pour vérifier la reconnaissance des références.</div><table>' . $cells . '</table></body></html>';
}
