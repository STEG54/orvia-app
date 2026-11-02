<?php
// Photos (comptes rendus, événements, merchandising, fiches) : servies uniquement aux personnes connectées
// (direction, intervenant(e), compte marque), ou par un lien signé et daté (photo_lien) pour un envoi hors de l'appli.
// L'accès direct au dossier uploads/ est refusé pour ces fichiers (uploads/.htaccess, ou la règle Nginx équivalente).
require __DIR__ . '/lib.php';
$f = (string)($_GET['f'] ?? ''); $mini = !empty($_GET['t']);
if (!preg_match('/^[a-f0-9]{32}$/', $f)) { http_response_code(404); exit; }
$signe = isset($_GET['e'], $_GET['s']) && photo_signature_ok($f, (int)$_GET['e'], (string)$_GET['s']);
if (!$signe) {
  start_session();
  $ok = !empty($_SESSION['anim']) || !empty($_SESSION['client_id']) || admin_ok();
  if (!$ok) { http_response_code(401); header('Cache-Control: no-store'); exit; }
}
$p = upload_dir() . '/' . $f . ($mini ? '_t' : '') . '.jpg';
if (!is_file($p) && $mini) $p = upload_dir() . '/' . $f . '.jpg';
if (!is_file($p)) { http_response_code(404); exit; }
$mt = filemtime($p); $etag = '"' . substr(sha1($f . $mt . ($mini ? 't' : '')), 0, 16) . '"';
header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400'); header('ETag: ' . $etag);
header('Content-Disposition: inline; filename="' . $f . '.jpg"');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . filesize($p));
readfile($p);
