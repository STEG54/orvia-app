<?php
// Documents chiffrés, déchiffrés à la volée (jamais servis directement par le serveur web).
// Documents de la personne (?id=) : visibles par elle-même ou par la direction. Documents de l'agence (?g=) : par toute personne connectée.
// Chaque consultation d'un document personnel par l'administration est notée dans le journal des accès.
require __DIR__ . '/lib.php';
start_session();
header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: no-referrer');
$moi = !empty($_SESSION['anim']) && empty($_SESSION['apercu']) ? (int)$_SESSION['anim'] : 0;
$introuvable = function () { http_response_code(404); exit('Document introuvable.'); };
if (isset($_GET['fa'])) {
  // Facture d'un(e) auto-entrepreneur : par elle-même ou par l'agence.
  require_once __DIR__ . '/ae_lib.php'; ae_schema(); $st = db()->prepare("SELECT * FROM factures_ae WHERE id = ?"); $st->execute([(int)$_GET['fa']]); $p = $st->fetch();
  if (!$p || !(admin_ok() || ($moi && $moi === (int)$p['animatrice_id']))) $introuvable();
} elseif (isset($_GET['g'])) {
  $st = db()->prepare("SELECT * FROM docs_agence WHERE id = ?"); $st->execute([(int)$_GET['g']]); $p = $st->fetch();
  if (!$p || !(!empty($_SESSION['anim']) || admin_ok())) $introuvable();
} else {
  $st = db()->prepare("SELECT * FROM pieces_identite WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $p = $st->fetch();
  if (!$p || !(est_titulaire() || ($moi && $moi === (int)$p['animatrice_id']))) $introuvable();
  if ($moi && $moi === (int)$p['animatrice_id']) { if (empty($p['vu_le'])) db()->prepare("UPDATE pieces_identite SET vu_le = ? WHERE id = ?")->execute([now(), $p['id']]); }
  else doc_journal('p:' . $p['id'], admin_nom());
}
$data = is_file($f = fiche_dir() . '/' . basename($p['fichier'])) ? amy_dechiffre((string)file_get_contents($f)) : null;
if ($data === null) { http_response_code(404); exit('Document illisible.'); }
$nom = preg_replace('/[^\p{L}\p{N} ._()-]+/u', '_', (string)$p['nom']) ?: 'document';
// Seuls des PDF et des photos sont acceptés au dépôt : tout autre type est servi en téléchargement brut, jamais affiché.
header('Content-Type: ' . (in_array($p['mime'], DOCS_MIMES, true) ? $p['mime'] : 'application/octet-stream'));
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $nom . '"'); header('Content-Length: ' . strlen($data));
echo $data;
