<?php
// Stockage objet compatible S3 (signature AWS v4) et chiffrement de la copie hors du serveur, sans dépendance :
// utilisé par l'appli (externe_lib.php) et par l'outil de restauration (outils/restaurer.php) sur une machine neuve.
function sx_actif(): bool {
  foreach (['SV_S3_ENDPOINT', 'SV_S3_REGION', 'SV_S3_BUCKET', 'SV_S3_CLE_ACCES', 'SV_S3_SECRET', 'SV_CLE_EXTERNE'] as $c) if (!defined($c) || (string)constant($c) === '') return false;
  return strlen((string)base64_decode((string)SV_CLE_EXTERNE, true)) === 32 && function_exists('curl_init');
}
function sx_cle(): string { return (string)base64_decode((string)SV_CLE_EXTERNE, true); }
function sx_chiffre(string $s): string { $iv = random_bytes(12); $ct = openssl_encrypt($s, 'aes-256-gcm', sx_cle(), OPENSSL_RAW_DATA, $iv, $tag); return 'AMX1' . $iv . $tag . $ct; }
function sx_dechiffre(string $b): ?string {
  if (strlen($b) < 33 || substr($b, 0, 4) !== 'AMX1') return null; $b = substr($b, 4);
  $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', sx_cle(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16)); return $r === false ? null : $r;
}
/* ---------- Client S3 minimal (signature AWS v4), sans bibliothèque ---------- */
/** Requête S3 signée. $cle = chemin de l'objet dans le conteneur. Renvoie [code HTTP, corps]. */
function sx_s3(string $methode, string $cle = '', string $corps = '', array $q = []): array {
  $ep = parse_url(rtrim((string)SV_S3_ENDPOINT, '/')); $hote = $ep['host'] . (isset($ep['port']) ? ':' . $ep['port'] : ''); $base = rtrim((string)($ep['path'] ?? ''), '/');
  $enc = fn($k) => implode('/', array_map('rawurlencode', explode('/', $k)));
  $hoteStyle = defined('SV_S3_STYLE') && SV_S3_STYLE === 'hote';
  if ($hoteStyle) { $hote = SV_S3_BUCKET . '.' . $hote; $chemin = $base . '/' . $enc($cle); } else $chemin = $base . '/' . rawurlencode((string)SV_S3_BUCKET) . ($cle !== '' ? '/' . $enc($cle) : '');
  if ($chemin === '') $chemin = '/';
  ksort($q); $qs = implode('&', array_map(fn($k, $v) => rawurlencode((string)$k) . '=' . rawurlencode((string)$v), array_keys($q), $q));
  $t = gmdate('Ymd\THis\Z'); $jour = substr($t, 0, 8); $ph = hash('sha256', $corps);
  $entetes = ['host' => $hote, 'x-amz-content-sha256' => $ph, 'x-amz-date' => $t];
  $canon = $methode . "\n" . $chemin . "\n" . $qs . "\n" . implode('', array_map(fn($k, $v) => "$k:$v\n", array_keys($entetes), $entetes)) . "\n" . implode(';', array_keys($entetes)) . "\n" . $ph;
  $portee = $jour . '/' . SV_S3_REGION . '/s3/aws4_request';
  $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', (string)SV_S3_REGION, hash_hmac('sha256', $jour, 'AWS4' . SV_S3_SECRET, true), true), true), true);
  $sig = hash_hmac('sha256', "AWS4-HMAC-SHA256\n$t\n$portee\n" . hash('sha256', $canon), $k);
  $h = ['Authorization: AWS4-HMAC-SHA256 Credential=' . SV_S3_CLE_ACCES . '/' . $portee . ', SignedHeaders=' . implode(';', array_keys($entetes)) . ', Signature=' . $sig,
    'x-amz-content-sha256: ' . $ph, 'x-amz-date: ' . $t, 'Host: ' . $hote, 'Expect:'];
  if ($methode === 'PUT') $h[] = 'Content-Type: application/octet-stream'; // sinon curl annonce un formulaire
  $c = curl_init(($ep['scheme'] ?? 'https') . '://' . $hote . $chemin . ($qs !== '' ? '?' . $qs : ''));
  curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $methode, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 300]
    + ($methode === 'PUT' ? [CURLOPT_POSTFIELDS => $corps] : []) + ($methode === 'HEAD' ? [CURLOPT_NOBODY => true] : []));
  $r = curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
  if ($r === false) throw new RuntimeException('Stockage externe injoignable : ' . $err);
  return [$code, (string)$r];
}
function sx_put(string $cle, string $donnees): void { [$c, $b] = sx_s3('PUT', $cle, $donnees); if ($c !== 200) throw new RuntimeException("Envoi refusé par le stockage externe ($c) : " . mb_substr(strip_tags($b), 0, 200)); }
function sx_get(string $cle): string { [$c, $b] = sx_s3('GET', $cle); if ($c !== 200) throw new RuntimeException("Lecture impossible sur le stockage externe ($c) : $cle"); return $b; }
function sx_del(string $cle): void { sx_s3('DELETE', $cle); }
/** Objets sous un préfixe : [clé => taille]. */
function sx_liste(string $prefixe): array {
  $o = []; $jeton = null;
  do { $q = ['list-type' => '2', 'prefix' => $prefixe, 'max-keys' => '1000']; if ($jeton) $q['continuation-token'] = $jeton;
    [$c, $b] = sx_s3('GET', '', '', $q); if ($c !== 200) throw new RuntimeException("Liste impossible sur le stockage externe ($c).");
    $x = @simplexml_load_string($b); if (!$x) throw new RuntimeException('Réponse illisible du stockage externe.');
    foreach ($x->Contents as $e) $o[(string)$e->Key] = (int)$e->Size;
    $jeton = (string)$x->IsTruncated === 'true' ? (string)$x->NextContinuationToken : null;
  } while ($jeton);
  return $o;
}
