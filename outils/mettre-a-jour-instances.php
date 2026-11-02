<?php
// Met à jour toutes les instances d'un serveur avec un paquet SIGNÉ par l'éditeur (même vérification que Maintenance › Mises à jour).
// Usage (root) : php outils/mettre-a-jour-instances.php paquet-signe.zip [/srv/amaya/*/www]
// Chaque instance est mise à jour dans un processus séparé, sous son propre utilisateur ; une erreur sur l'une n'arrête pas les autres.
if (PHP_SAPI !== 'cli') exit;
$zip = realpath((string)($argv[1] ?? '')); if (!$zip) { fwrite(STDERR, "Usage : php outils/mettre-a-jour-instances.php <paquet signé> [motif des dossiers]\n"); exit(2); }
$motif = $argv[2] ?? '/srv/amaya/*/www'; $ko = 0;
if (!empty($argv[3]) && $argv[3] === '--une') {
  // Processus enfant : une instance.
  chdir($motif); $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; require "$motif/lib.php"; require_once "$motif/maj_lib.php";
  try { $r = maj_appliquer($zip); echo 'version ' . ($r['version'] ?? '?') . ' installée'; exit(0); } catch (Throwable $e) { echo $e->getMessage(); exit(1); }
}
// Le script et le paquet sont copiés dans un dossier temporaire lisible par les utilisateurs des agences.
$moi = sys_get_temp_dir() . '/amaya-maj-' . bin2hex(random_bytes(4)) . '.php'; copy(__FILE__, $moi); chmod($moi, 0644);
foreach (glob($motif, GLOB_ONLYDIR) as $d) {
  if (!is_file("$d/config.php")) continue; $u = posix_getpwuid(fileowner("$d/config.php"))['name'] ?? 'root';
  $copie = sys_get_temp_dir() . '/amaya-maj-' . bin2hex(random_bytes(4)) . '.zip'; copy($zip, $copie); chmod($copie, 0644);
  $cmd = (posix_getuid() === 0 && $u !== 'root' ? 'runuser -u ' . escapeshellarg($u) . ' -- ' : '') . 'php ' . escapeshellarg($moi) . ' ' . escapeshellarg($copie) . ' ' . escapeshellarg($d) . ' --une';
  exec($cmd . ' 2>&1', $o, $rc); unlink($copie);
  echo ($rc === 0 ? 'ok    ' : 'ÉCHEC ') . "$d : " . mb_substr(implode(' ', $o), 0, 300) . "\n"; $o = []; if ($rc !== 0) $ko++;
}
unlink($moi);
exit($ko ? 1 : 0);
