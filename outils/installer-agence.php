<?php
// Installation non interactive d'une agence, à partir du paquet, avec exactement la même logique que l'assistant installation.php.
// Usage : php outils/installer-agence.php --paquet=dist/amaya.zip --dossier=/srv/amaya/<agence>/www --parametres=agence.json
// agence.json : {"base_url": "https://agence.exemple.fr", "db": {"host","name","user","pass"},
//   "agence": {"nom","court","email","mail_from","couleur","matricule","tz"}, "dirigeants": [{"nom","email","pass_hash"}, …],
//   ("pass_hash" facultatif : empreinte du mot de passe choisi à l'inscription en ligne ; le compte est alors créé directement avec ce mot de passe)
//   "s3": {"endpoint","region","bucket","cle_acces","secret"}}   ("s3" facultatif mais recommandé)
// Affiche UNE SEULE FOIS le mot de passe de secours et la clé de copie externe : à ranger aussitôt dans le coffre de l'éditeur.
if (PHP_SAPI !== 'cli') exit;
$o = getopt('', ['paquet:', 'dossier:', 'parametres:']);
foreach (['paquet', 'dossier', 'parametres'] as $k) if (empty($o[$k])) { fwrite(STDERR, "Option --$k manquante.\n"); exit(2); }
$A = json_decode((string)@file_get_contents($o['parametres']), true); if (!is_array($A)) { fwrite(STDERR, "Paramètres illisibles (JSON).\n"); exit(2); }
$dir = rtrim($o['dossier'], '/'); if (is_dir($dir) && count(array_diff(scandir($dir), ['.', '..'])) > 0) { fwrite(STDERR, "Le dossier $dir doit être vide.\n"); exit(2); }
@mkdir($dir, 0750, true);
$z = new ZipArchive(); if ($z->open($o['paquet']) !== true) { fwrite(STDERR, "Paquet illisible.\n"); exit(2); }
$tmp = sys_get_temp_dir() . '/amaya-inst-' . bin2hex(random_bytes(4)); $z->extractTo($tmp); $z->close();
$src = is_dir("$tmp/amaya") ? "$tmp/amaya" : $tmp; exec('cp -a ' . escapeshellarg($src) . '/. ' . escapeshellarg($dir) . '/', $_o, $rc); exec('rm -rf ' . escapeshellarg($tmp));
if ($rc !== 0) { fwrite(STDERR, "Copie des fichiers impossible.\n"); exit(1); }

// Assistant d'installation exécuté tel quel, avec les réponses du fichier de paramètres.
$secours = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
$u = parse_url((string)($A['base_url'] ?? '')); if (empty($u['host'])) { fwrite(STDERR, "base_url invalide.\n"); exit(2); }
$D = array_values($A['dirigeants'] ?? []); if (!$D) { fwrite(STDERR, "Au moins un dirigeant.\n"); exit(2); }
$_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => $u['host'] . (isset($u['port']) ? ':' . $u['port'] : ''), 'SCRIPT_NAME' => rtrim($u['path'] ?? '', '/') . '/installation.php',
  'HTTPS' => ($u['scheme'] ?? 'https') === 'https' ? 'on' : '', 'REMOTE_ADDR' => '127.0.0.1'];
$_POST = ['db_host' => $A['db']['host'] ?? 'localhost', 'db_name' => $A['db']['name'] ?? '', 'db_user' => $A['db']['user'] ?? '', 'db_pass' => $A['db']['pass'] ?? '',
  'ag_nom' => $A['agence']['nom'] ?? '', 'ag_court' => $A['agence']['court'] ?? '', 'ag_email' => $A['agence']['email'] ?? '', 'mail_from' => $A['agence']['mail_from'] ?? '',
  'ag_couleur' => $A['agence']['couleur'] ?? '#43515C', 'tz' => $A['agence']['tz'] ?? 'Africa/Casablanca', 'ag_matricule' => $A['agence']['matricule'] ?? 'AG',
  'd1_nom' => $D[0]['nom'] ?? '', 'd1_email' => $D[0]['email'] ?? '', 'd2_nom' => $D[1]['nom'] ?? '', 'd2_email' => $D[1]['email'] ?? '', 'admin_pw' => $secours];
chdir($dir); ob_start(); include "$dir/installation.php"; $html = ob_get_clean();
if (empty($ok)) { fwrite(STDERR, 'Installation refusée : ' . ($err ?? trim(strip_tags($html))) . "\n"); exit(1); }

// Copie hors du serveur : clé distincte générée ici.
$cleX = '';
if (!empty($A['s3']['endpoint'])) { $cleX = base64_encode(random_bytes(32)); $ex = fn($v) => var_export((string)$v, true);
  $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT', (string)($A['agence']['court'] ?? $A['agence']['nom'])))), '-');
  file_put_contents("$dir/config.php", "\n// Copie des sauvegardes hors du serveur (stockage objet S3), clé distincte de AMAYA_CLE.\n"
    . "define('SV_S3_ENDPOINT', " . $ex($A['s3']['endpoint']) . ");\ndefine('SV_S3_REGION', " . $ex($A['s3']['region'] ?? '') . ");\ndefine('SV_S3_BUCKET', " . $ex($A['s3']['bucket'] ?? '') . ");\n"
    . "define('SV_S3_CLE_ACCES', " . $ex($A['s3']['cle_acces'] ?? '') . ");\ndefine('SV_S3_SECRET', " . $ex($A['s3']['secret'] ?? '') . ");\ndefine('SV_S3_PREFIXE', " . $ex($A['s3']['prefixe'] ?? $slug) . ");\n"
    . "define('SV_CLE_EXTERNE', " . $ex($cleX) . ");\n", FILE_APPEND); }
// Inscription en ligne : le dirigeant a choisi son mot de passe ; son compte est créé avec (la double authentification lui sera demandée à la première connexion).
if (!empty($D[0]['pass_hash']) && preg_match('/^\$2y\$|^\$argon2/', (string)$D[0]['pass_hash'])) {
  db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,'tout',1,?)")->execute([(string)$D[0]['nom'], mb_strtolower((string)$D[0]['email']), (string)$D[0]['pass_hash'], date('Y-m-d H:i:s')]);
}
// Durcissement après installation : l'assistant et le diagnostic ne restent pas en ligne.
@unlink("$dir/installation.php"); @unlink("$dir/diag.php"); @chmod("$dir/config.php", 0640);
echo "Agence installée dans $dir (" . $A['base_url'] . ")\n";
echo "À RANGER DANS LE COFFRE DE L'ÉDITEUR, n'est affiché qu'une fois :\n  mot de passe de secours : $secours\n" . ($cleX ? "  SV_CLE_EXTERNE          : $cleX\n" : "  (pas de copie hors du serveur réglée)\n");
echo "  + une copie de $dir/config.php (clé des documents AMAYA_CLE)\n";
