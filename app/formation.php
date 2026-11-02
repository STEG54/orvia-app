<?php
// Téléchargement / lecture d'un support de formation : réservé aux intervenants connectées et à l'administratrice.
require __DIR__ . '/lib.php';
start_session();
if (empty($_SESSION['anim']) && empty($_SESSION['admin'])) { http_response_code(401); exit('Connectez-vous à l\'application ' . AG_COURT . ' pour ouvrir ce support.'); }
$st = db()->prepare("SELECT * FROM formations WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $f = $st->fetch();
if (!$f) { http_response_code(404); exit('Support introuvable.'); }
if (empty($_SESSION['admin']) && !formation_visible($f, (int)$_SESSION['anim'])) { http_response_code(403); exit('Ce support ne vous est pas ouvert. Contactez ' . AG_NOM . '.'); }
if ($f['type'] === 'lien') { header('Location: ' . $f['url']); exit; }
$path = formation_dir() . '/' . basename($f['fichier']);
if (!is_file($path)) { http_response_code(404); exit('Fichier introuvable.'); }
session_write_close();
$size = filesize($path); $start = 0; $end = $size - 1;
$disp = !empty($_GET['dl']) || $f['type'] === 'fichier' ? 'attachment' : 'inline';
header('Content-Type: ' . $f['mime']);
header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $f['nom_original']) . '"; filename*=UTF-8\'\'' . rawurlencode($f['nom_original']));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
// Lecture des vidéos sur iPhone : le navigateur demande le fichier par morceaux (Range).
if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'] ?? '', $m)) {
  if ($m[1] !== '') { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $end); }
  elseif ($m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
  if ($start > $end) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
  http_response_code(206); header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fp = fopen($path, 'rb'); fseek($fp, $start); $left = $end - $start + 1;
@set_time_limit(0);
while ($left > 0 && !feof($fp)) { $chunk = fread($fp, min(1048576, $left)); echo $chunk; flush(); $left -= strlen($chunk); }
fclose($fp);
