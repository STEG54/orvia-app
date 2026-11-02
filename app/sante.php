<?php
// Surveillance (UptimeRobot, Better Stack…) : répond 200 « ok » si l'appli fonctionne, 503 sinon. Aucune donnée n'est exposée.
// Détail (version, âge de la dernière sauvegarde) avec ?cle= la clé indiquée dans Administration › Maintenance.
header('Cache-Control: no-store'); header('Content-Type: application/json; charset=utf-8'); header('X-Robots-Tag: noindex');
$r = ['ok' => false];
try {
  require __DIR__ . '/lib.php'; require_once __DIR__ . '/version.php';
  db()->query('SELECT 1')->fetchColumn();
  $w = is_writable(upload_dir()); $sv = (int)@file_get_contents(upload_dir() . '/.sauvegardes/.derniere');
  $r = ['ok' => $w];
  if (hash_equals(substr(hash('sha256', 'sante' . instance_graine()), 0, 24), (string)($_GET['cle'] ?? '')))
    $r += ['version' => AMAYA_VERSION, 'php' => PHP_VERSION, 'ecriture' => $w, 'sauvegarde_heures' => $sv ? round((time() - $sv) / 3600, 1) : null, 'heure' => date('c')];
} catch (Throwable $e) { $r = ['ok' => false]; }
http_response_code($r['ok'] ? 200 : 503); echo json_encode($r);
