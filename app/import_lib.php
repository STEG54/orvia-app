<?php
// Import des clients et des points de vente depuis Excel / CSV ou un copier-coller (reprise des données d'un autre logiciel).
// Avec une ligne d'en-tête, les colonnes sont reconnues dans n'importe quel ordre. Les doublons sont ignorés, rien n'est écrasé.
require_once __DIR__ . '/gestion_lib.php';

function im_lignes(string $texte, ?array $fichier): array {
  $rows = [];
  if ($fichier && $fichier['error'] === UPLOAD_ERR_OK) {
    $fn = $fichier['tmp_name'];
    if (file_get_contents($fn, false, null, 0, 2) === 'PK') $rows = xlsx_rows($fn); else $texte .= "\n" . file_get_contents($fn);
  }
  $texte = preg_replace('/^\xEF\xBB\xBF/', '', $texte); if (!mb_check_encoding($texte, 'UTF-8')) $texte = mb_convert_encoding($texte, 'UTF-8', 'Windows-1252');
  foreach (preg_split('/\r\n|\r|\n/', $texte) as $l) { if (trim($l) === '') continue; $sep = substr_count($l, "\t") ? "\t" : (substr_count($l, ';') >= substr_count($l, ',') ? ';' : ',');
    $rows[] = array_map(fn($c) => trim($c, " \"\u{a0}"), str_getcsv($l, $sep)); }
  return array_values(array_filter($rows, fn($r) => implode('', array_map('trim', array_map('strval', $r))) !== ''));
}
function im_norm(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', $s)))); }
/** Colonnes reconnues d'après l'en-tête (mots-clés), sinon l'ordre par défaut. */
function im_colonnes(array &$rows, array $cles, array $defaut): array {
  $h = array_map(fn($c) => im_norm((string)$c), $rows[0] ?? []); $col = []; $vu = 0;
  foreach ($cles as $k => $mots) foreach ($h as $i => $t) { if (isset($col[$k]) || in_array($i, $col, true)) continue; foreach ($mots as $m) if ($t === $m || str_contains($t, $m)) { $col[$k] = $i; $vu++; break; } }
  if ($vu >= 2) { array_shift($rows); return $col; }
  return $defaut;
}

function im_clients(array $rows, bool $simuler): array {
  $c = im_colonnes($rows, ['raison' => ['raison sociale', 'societe', 'client', 'entreprise', 'nom'], 'contact' => ['contact', 'interlocuteur'], 'emails' => ['email', 'mail', 'courriel'],
    'adresse' => ['adresse', 'rue'], 'cp' => ['code postal', 'cp'], 'ville' => ['ville', 'commune'], 'pays' => ['pays'], 'tva_intra' => ['tva intra', 'intracom', 'n tva']],
    ['raison' => 0, 'contact' => 1, 'emails' => 2, 'adresse' => 3, 'cp' => 4, 'ville' => 5]);
  $deja = []; foreach (db()->query("SELECT raison FROM g_clients")->fetchAll(PDO::FETCH_COLUMN) as $r) $deja[im_norm($r)] = true;
  $aj = []; $ign = [];
  foreach ($rows as $r) {
    $g = fn($k, $n = 160) => isset($c[$k]) ? mb_substr(trim((string)($r[$c[$k]] ?? '')), 0, $n) : '';
    $rs = $g('raison'); if ($rs === '') continue; $k = im_norm($rs);
    if (isset($deja[$k])) { $ign[] = $rs; continue; } $deja[$k] = true;
    $x = ['raison' => $rs, 'contact' => $g('contact'), 'emails' => implode(', ', emails_list($g('emails', 600))), 'adresse' => $g('adresse', 400), 'cp' => $g('cp', 20), 'ville' => $g('ville', 120), 'pays' => $g('pays', 80) ?: 'France', 'tva_intra' => $g('tva_intra', 40)];
    if (!$simuler) db()->prepare("INSERT INTO g_clients (raison, contact, emails, adresse, cp, ville, pays, tva_intra, created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([...array_values($x), now()]);
    $aj[] = $x;
  }
  return ['ajoutes' => $aj, 'ignores' => $ign];
}

function im_magasins(array $rows, string $zoneDef, bool $simuler): array {
  require_once __DIR__ . '/geo_lib.php'; geo_schema();
  $c = im_colonnes($rows, ['nom' => ['point de vente', 'magasin', 'nom', 'lieu', 'site'], 'enseigne' => ['enseigne', 'chaine', 'marque'], 'adresse' => ['adresse', 'rue'],
    'cp' => ['code postal', 'cp'], 'ville' => ['ville', 'commune'], 'zone' => ['zone', 'region', 'territoire']],
    ['nom' => 0, 'enseigne' => 1, 'adresse' => 2, 'cp' => 3, 'ville' => 4]);
  $deja = []; foreach (db()->query("SELECT nom, zone FROM magasins")->fetchAll() as $m) $deja[im_norm($m['nom']) . '|' . $m['zone']] = true;
  $zones = []; foreach (array_keys(ZONES) as $z) $zones[im_norm($z)] = $z;
  $aj = []; $ign = [];
  foreach ($rows as $r) {
    $g = fn($k, $n = 120) => isset($c[$k]) ? mb_substr(trim((string)($r[$c[$k]] ?? '')), 0, $n) : '';
    $nom = $g('nom'); $ville = $g('ville', 80); if ($nom === '') continue;
    $z = $zones[im_norm($g('zone'))] ?? ($zoneDef !== '' && isset(ZONES[$zoneDef]) ? $zoneDef : (zone_cp($g('cp', 10)) ?: array_key_first(ZONES)));
    $k = im_norm($nom) . '|' . $z; if (isset($deja[$k])) { $ign[] = $nom; continue; } $deja[$k] = true;
    $adr = trim($g('adresse', 200) . ($g('cp', 10) || $ville ? ', ' . trim($g('cp', 10) . ' ' . $ville) : ''), ', ');
    $x = ['zone' => $z, 'enseigne' => $g('enseigne', 60) ?: explode(' ', $nom)[0], 'nom' => $nom, 'ville' => $ville ?: '—', 'adresse' => mb_substr($adr, 0, 255)];
    if (!$simuler) db()->prepare("INSERT INTO magasins (zone, enseigne, nom, ville, adresse) VALUES (?,?,?,?,?)")->execute(array_values($x));
    $aj[] = $x;
  }
  return ['ajoutes' => $aj, 'ignores' => $ign];
}
