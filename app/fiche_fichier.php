<?php
// CV d'un(e) intervenant(e) : visible par elle-même et par l'administratrice uniquement.
require __DIR__ . '/lib.php';
start_session();
$id = (int)($_GET['id'] ?? 0);
if (empty($_SESSION['admin'])) { if (empty($_SESSION['anim'])) { http_response_code(401); exit('Connexion requise.'); } $id = (int)$_SESSION['anim']; }
$st = db()->prepare("SELECT cv, cv_nom FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$id]); $f = $st->fetch();
if (!$f || !$f['cv'] || !is_file($p = fiche_dir() . '/' . basename($f['cv']))) { http_response_code(404); exit('Aucun CV.'); }
$data = str_ends_with($p, '.bin') ? amy_dechiffre((string)file_get_contents($p)) : (string)file_get_contents($p); // CV chiffré
if ($data === null) { http_response_code(404); exit('CV illisible.'); }
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', (string)$f['cv_nom']) . '"');
header('Content-Length: ' . strlen($data));
echo $data;
