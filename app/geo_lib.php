<?php
// Distances domicile ↔ point de vente : adresse géolocalisée (Base Adresse Nationale, service de l'État), trajet routier mis en cache.
// Itinéraires et recherche de magasins par leur nom : Geoapify (fournisseur sous contrat) si GEO_CLE est réglée dans config.php,
// sinon, ou s'il ne répond pas, les serveurs publics d'OpenStreetMap (OSRM, Nominatim, Photon) en secours.
// Geoapify ne reçoit que des coordonnées GPS pour les trajets : ni nom ni adresse de salarié.
function geo_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  $c = db()->query("SHOW COLUMNS FROM magasins")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('adresse', $c, true)) db()->exec("ALTER TABLE magasins ADD COLUMN adresse VARCHAR(255) NOT NULL DEFAULT '', ADD COLUMN lat DOUBLE NULL, ADD COLUMN lng DOUBLE NULL, ADD COLUMN geo_approx TINYINT NOT NULL DEFAULT 0");
  db()->exec("CREATE TABLE IF NOT EXISTS geo_cache (k CHAR(40) PRIMARY KEY, v VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function geo_cache(string $k, ?callable $f) {
  geo_schema(); $h = sha1($k); $st = db()->prepare("SELECT v FROM geo_cache WHERE k = ?"); $st->execute([$h]); $v = $st->fetchColumn();
  if ($v !== false) return json_decode($v, true);
  if (!$f) return null; $v = $f(); if ($v === null) return null;
  db()->prepare("REPLACE INTO geo_cache (k, v, created_at) VALUES (?,?,?)")->execute([$h, json_encode($v), now()]); return $v;
}
function geo_http(string $url): ?array {
  static $n = 0; if (++$n > ($GLOBALS['GEO_MAX'] ?? 25)) return null; // nombre d'appels limité par page
  if (function_exists('curl_init')) { $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'Amaya/1.0 (' . AG_EMAIL . ')']);
    $r = curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); $GLOBALS['GEO_CODE'] = $r === false ? 0 : $code; if ($r === false || $code !== 200) return null; }
  else { $r = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: Amaya/1.0\r\n"]])); if ($r === false) return null; }
  return json_decode($r, true) ?: null;
}
function geo_cle(): string { return defined('GEO_CLE') ? trim((string)GEO_CLE) : ''; }
function geo_geoapify(string $chemin, array $q): ?array {
  if (geo_cle() === '') return null; $base = defined('GEO_GEOAPIFY_URL') ? rtrim((string)GEO_GEOAPIFY_URL, '/') : 'https://api.geoapify.com';
  $GLOBALS['GEO_CODE'] = 0; $r = geo_http($base . $chemin . '?' . http_build_query($q + ['apiKey' => geo_cle()], '', '&', PHP_QUERY_RFC3986));
  geo_etat_noter((int)($GLOBALS['GEO_CODE'] ?? 0)); return $r;
}
/** Dernier échange avec Geoapify (affiché dans Maintenance) : gardé en base, jamais dans un fichier lisible depuis le web. */
function geo_etat_noter(int $code): void {
  try { geo_schema(); db()->prepare("REPLACE INTO geo_cache (k, v, created_at) VALUES (?,?,?)")->execute([sha1('etat:geoapify'), json_encode(['code' => $code, 'le' => date('Y-m-d H:i')]), now()]); } catch (Throwable $e) {}
}
function geo_etat(): ?array { geo_schema(); $st = db()->prepare("SELECT v FROM geo_cache WHERE k = ?"); $st->execute([sha1('etat:geoapify')]); $v = $st->fetchColumn(); return $v === false ? null : json_decode($v, true); }
function geo_etat_texte(int $code): string {
  return match (true) { $code === 200 => 'connecté', $code === 401 || $code === 403 => 'clé refusée : vérifiez la clé dans config.php, et retirez chez Geoapify toute restriction par site d\'origine',
    $code === 429 => 'quota du jour atteint : les serveurs publics prennent le relais jusqu\'à demain', $code === 0 => 'service injoignable depuis le serveur', default => 'réponse inattendue (code ' . $code . ')' };
}
/** Géolocalise une adresse : [lat, lng, précis (bool)]. */
function geo_code(string $q, string $cp = ''): ?array {
  $q = trim(preg_replace('/\s+/', ' ', $q)); if (mb_strlen($q) < 3) return null;
  return geo_cache('ban:' . mb_strtolower($q . '|' . $cp), function () use ($q, $cp) {
    $j = geo_http('https://api-adresse.data.gouv.fr/search/?limit=1&q=' . rawurlencode($q) . ($cp ? '&postcode=' . rawurlencode($cp) : ''));
    $f = $j['features'][0] ?? null; if (!$f) return null;
    [$lng, $lat] = $f['geometry']['coordinates']; $t = $f['properties']['type'] ?? '';
    return [(float)$lat, (float)$lng, in_array($t, ['housenumber', 'street'], true) && ($f['properties']['score'] ?? 0) > 0.5];
  });
}
/** Plus Code Google (ex. « 5HCQPGG4+FP », ou court « PGG4+FP » avec une ville de référence) : centre [lat, lng]. */
function geo_pluscode(string $code, ?array $ref = null): ?array {
  $A = '23456789CFGHJMPQRVWX'; $code = strtoupper(trim($code)); $p = strpos($code, '+'); if ($p === false || $p % 2) return null;
  if ($p < 8) { if (!$ref) return null; $la = $ref[0] + 90; $lo = $ref[1] + 180; $pre = $A[(int)floor($la / 20)] . $A[(int)floor($lo / 20)] . $A[(int)floor(fmod($la, 20))] . $A[(int)floor(fmod($lo, 20))] . $A[(int)floor(fmod($la, 1) / .05)] . $A[(int)floor(fmod($lo, 1) / .05)];
    $code = substr($pre, 0, 8 - $p) . $code; }
  $c = str_replace('+', '', $code); $res = [20, 1, .05, .0025, .000125]; $lat = -90; $lng = -180; $n = intdiv(strlen($c), 2); if ($n < 1 || $n > 5) return null;
  for ($i = 0; $i < $n; $i++) { $x = strpos($A, $c[2 * $i]); $y = strpos($A, $c[2 * $i + 1]); if ($x === false || $y === false) return null; $lat += $x * $res[$i]; $lng += $y * $res[$i]; }
  $lat += $res[$n - 1] / 2; $lng += $res[$n - 1] / 2;
  if ($p < 8) { $r = 20 ** (2 - (8 - $p) / 2); $h = $r / 2; // le plus proche de la ville de référence
    if ($ref[0] + $h < $lat) $lat -= $r; elseif ($ref[0] - $h > $lat) $lat += $r;
    if ($ref[1] + $h < $lng) $lng -= $r; elseif ($ref[1] - $h > $lng) $lng += $r; }
  return [round($lat, 6), round($lng, 6)];
}
/** Position lue dans un texte : coordonnées, lien Google Maps (même court), Plus Code (« PGG4+FP Le Tampon »). Avec l'adresse retrouvée (Base adresse nationale). */
function geo_position_texte(string $t, string $zone = '', string $ville = '', bool $strict = false): ?array {
  $t = trim($t); if ($t === '') return null; $GLOBALS['GEO_MAX'] = max((int)($GLOBALS['GEO_MAX'] ?? 25), 10);
  if (preg_match('#^https?://(maps\.app\.goo\.gl|goo\.gl|g\.co)/#i', $t) && function_exists('curl_init')) {
    for ($i = 0; $i < 4 && preg_match('#^https?://#', $t); $i++) { $c = curl_init($t); curl_setopt_array($c, [CURLOPT_NOBODY => true, CURLOPT_HEADER => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 6, CURLOPT_USERAGENT => 'Mozilla/5.0']);
      $h = (string)curl_exec($c); curl_close($c); if (!preg_match('/^location:\s*(\S+)/mi', $h, $m)) break; $t = urldecode($m[1]); if (preg_match('/[@!=]-?\d+\.\d+/', $t)) break; } }
  if (preg_match('#^https?://#i', $t)) $t = urldecode($t); // pas pour un Plus Code : le « + » y est voulu
  $lat = $lng = null;
  if (preg_match('/^\s*([23456789CFGHJMPQRVWX]{2,8}\+[23456789CFGHJMPQRVWX]{0,3})\b\s*,?\s*(.*)$/i', $t, $pc)) {
    $ctr = $zone === 'France' ? [46.6, 2.4] : null;
    $lieu = trim(preg_replace('/,?\s*France$/iu', '', $pc[2])) ?: $ville;
    $g = $lieu !== '' ? geo_code($lieu) : null; $ref = $g ? [$g[0], $g[1]] : $ctr;
    if ($x = geo_pluscode($pc[1], $ref)) [$lat, $lng] = $x; }
  $res = ['/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/', '/@(-?\d+\.\d+),(-?\d+\.\d+)/', '/[?&](?:q|query|ll|destination)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/', '/^\s*(-?\d{1,2}\.\d+)\s*[,; ]\s*(-?\d{1,3}\.\d+)\s*$/'];
  if ($lat === null) foreach ($res as $re) if (preg_match($re, $t, $m)) { $lat = (float)$m[1]; $lng = (float)$m[2]; break; }
  if ($lat === null || abs($lat) > 90 || abs($lng) > 180) return null;
  $r = geo_http('https://api-adresse.data.gouv.fr/reverse/?lon=' . $lng . '&lat=' . $lat); $p = $r['features'][0]['properties'] ?? [];
  return ['lat' => $lat, 'lng' => $lng, 'adresse' => (string)($p['name'] ?? ''), 'cp' => (string)($p['postcode'] ?? ''), 'ville' => (string)($p['city'] ?? '')];
}
function geo_vol(array $a, array $b): float { // à vol d'oiseau, km
  $r = 6371; $dl = deg2rad($b[0] - $a[0]); $dg = deg2rad($b[1] - $a[1]);
  $x = sin($dl / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dg / 2) ** 2; return 2 * $r * asin(min(1, sqrt($x)));
}
/** Distance routière aller simple en km : le plus court des itinéraires routiers proposés ; à défaut, vol d'oiseau × 1,3. */
function geo_route(array $a, array $b): array {
  $k = sprintf('osrm-court:%.4f,%.4f;%.4f,%.4f', $a[0], $a[1], $b[0], $b[1]);
  $v = geo_cache($k, function () use ($a, $b) {
    $g = geo_geoapify('/v1/routing', ['waypoints' => sprintf('%.6f,%.6f|%.6f,%.6f', $a[0], $a[1], $b[0], $b[1]), 'mode' => 'drive', 'format' => 'json']);
    $d = array_filter(array_map(fn($r) => $r['distance'] ?? null, (array)($g['results'] ?? [])), fn($x) => $x !== null);
    if ($d) return ['km' => round(min($d) / 1000, 1), 'route' => true];
    $j = geo_http(sprintf('https://router.project-osrm.org/route/v1/driving/%.6f,%.6f;%.6f,%.6f?overview=false&alternatives=3', $a[1], $a[0], $b[1], $b[0]));
    $d = array_filter(array_map(fn($r) => $r['distance'] ?? null, (array)($j['routes'] ?? [])), fn($x) => $x !== null);
    return $d ? ['km' => round(min($d) / 1000, 1), 'route' => true] : null;
  });
  return $v ?: ['km' => round(geo_vol($a, $b) * 1.3, 1), 'route' => false];
}
/** Coordonnées d'un point de vente (adresse saisie, sinon nom + ville), enregistrées sur sa fiche. */
function geo_magasin(string $nom): ?array {
  geo_schema(); $st = db()->prepare("SELECT id, nom, ville, adresse, lat, lng, geo_approx FROM magasins WHERE nom = ? LIMIT 1"); $st->execute([$nom]); $m = $st->fetch();
  if (!$m && str_contains($nom, ', ')) return geo_magasin(explode(', ', $nom)[0]); // tournée : le premier point de l'itinéraire
  if (!$m) return null;
  $dest = $m['nom'] . ($m['adresse'] ? ', ' . $m['adresse'] : '') . ', ' . $m['ville'];
  if ($m['lat'] === null) {
    $g = $m['adresse'] ? geo_code($m['adresse'] . ' ' . $m['ville']) : null; $approx = !$g || !$g[2];
    if (!$g) $g = geo_code($m['ville']); if (!$g) return ['dest' => $dest, 'pos' => null];
    db()->prepare("UPDATE magasins SET lat = ?, lng = ?, geo_approx = ? WHERE id = ?")->execute([$g[0], $g[1], $approx ? 1 : 0, $m['id']]);
    $m['lat'] = $g[0]; $m['lng'] = $g[1]; $m['geo_approx'] = $approx ? 1 : 0;
  }
  return ['dest' => $dest, 'pos' => [(float)$m['lat'], (float)$m['lng']], 'approx' => (bool)$m['geo_approx']];
}
/** Trajets aller-retour domicile ↔ points de vente pour une personne. */
function geo_trajets(int $aid, array $magasins, ?array $depart = null): array {
  // Adresse de départ : celle donnée pour l'opération (famille, logement temporaire…), sinon le domicile de la fiche.
  $f = $depart ?: (array)fiche_get($aid)['data']; $adr = trim(($f['adresse'] ?? '') . ' ' . ($f['code_postal'] ?? '') . ' ' . ($f['ville'] ?? ''));
  $home = $adr !== '' ? geo_code($adr, (string)($f['code_postal'] ?? '')) : null;
  $taux = (float)str_replace(',', '.', g_reg()['km']); $out = [];
  foreach (array_unique(array_filter($magasins)) as $mg) {
    $M = geo_magasin($mg); $dest = $M['dest'] ?? $mg;
    $t = ['dest' => $dest, 'km_ar' => null, 'montant' => null, 'approx' => false, 'taux' => $taux, 'sans_adresse' => !$home];
    if ($home && $M && $M['pos']) { $r = geo_route([$home[0], $home[1]], $M['pos']); $ar = round($r['km'] * 2);
      $t = ['dest' => $dest, 'km_ar' => $ar, 'montant' => round($ar * $taux, 2), 'approx' => !$r['route'] || !$home[2] || $M['approx'], 'taux' => $taux, 'sans_adresse' => false]; }
    $out[$mg] = $t;
  }
  return $out;
}
