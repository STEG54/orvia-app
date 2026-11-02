<?php
// Aperçu (HTML) ou téléchargement (PDF) d'un compte rendu ou d'une analyse. Réservé à l'administrateur.
require __DIR__ . '/lib.php';
start_session();
if (!admin_ok()) { http_response_code(401); exit('Accès réservé à l\'administrateur.'); }
require __DIR__ . '/report_lib.php';
@set_time_limit(180);
$p = report_params($_GET);
$d = collect($p);
if (!empty($_GET['photos_zip'])) {
  header('Content-Type: application/zip');
  header('Content-Disposition: attachment; filename="Photos_' . $p['from'] . '_' . $p['to'] . '.zip"');
  echo photos_zip(report_photo_list($d)); exit;
}
if (!empty($_GET['xlsx'])) {
  require __DIR__ . '/xlsx_lib.php';
  $slug = preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', brands_label($d))) . '_' . $p['from'] . '_' . $p['to'];
  $fs = xlsx_fichiers($d, $p['xfmt'], $p['xmag'], $slug);
  if (count($fs) > 1) { // un fichier par point de vente : on les rassemble dans un ZIP
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Reportings_' . $slug . '.zip"');
    $z = []; foreach ($fs as [$n, $x]) $z[$n] = $x; echo zip_build($z); exit;
  }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $fs[0][0] . '"');
  echo $fs[0][1]; exit;
}
$html = ($_GET['type'] ?? '') === 'analyse' ? render_analyse($d, !empty($_GET['ia'])) : render_cr($d);
if (!empty($_GET['pdf'])) {
  $name = (($_GET['type'] ?? '') === 'analyse' ? 'Analyse-ventes_' : 'Rapport-animation_') . $p['from'] . '_' . $p['to'] . '.pdf';
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="' . $name . '"');
  echo make_pdf($html);
} else {
  echo photos_html(str_replace('<body>', '<body style="max-width:800px;margin:24px auto;padding:0 16px">', str_replace('#footer{position:fixed', '#footer{display:none;position:fixed', $html)));
}
