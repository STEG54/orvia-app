<?php
// Copie hors du serveur, de bout en bout, sur un stockage S3 de TEST : envoi, vérification, puis restauration complète
// dans une autre base et un autre dossier, et comparaison avec l'original (tables, lignes, fichiers).
// Usage : php tests/externe.php <dossier de l'installation> <base de restauration> <db-user> <db-pass> [db-host]
// L'installation doit avoir SV_S3_* et SV_CLE_EXTERNE dans config.php (tests/ci.sh s'en charge avec un serveur S3 local).
if (PHP_SAPI !== 'cli') exit;
[$_, $dir, $dbr, $u, $pw, $hote] = $argv + [null, '', '', '', '', 'localhost']; $dir = rtrim($dir, '/');
chdir($dir); $_SERVER['REMOTE_ADDR'] = '127.0.0.1'; require "$dir/lib.php"; require_once "$dir/externe_lib.php";
$ok = 0; $ko = 0; function t(string $n, $c, string $i = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($i ? " : $i" : '') . "\n"; } }
t('copie hors du serveur réglée', sx_actif());
// Données de test : une photo et un document chiffré comme ceux de l'appli.
@mkdir(upload_dir() . '/fiches', 0755, true); $photo = bin2hex(random_bytes(16)) . '.jpg'; file_put_contents(upload_dir() . "/$photo", random_bytes(50000));
$doc = bin2hex(random_bytes(16)) . '.bin'; file_put_contents(fiche_dir() . "/$doc", amy_chiffre('%PDF-1.4 pièce de test'));
sv_faire('test copie externe');
$b = sx_envoyer(120); t('envoi : base et fichiers', $b['base'] >= 1 && $b['fichiers'] >= 2, json_encode($b));
$b2 = sx_envoyer(60); t('deuxième envoi incrémental : rien à renvoyer', $b2['fichiers'] === 0 && $b2['base'] === 0, json_encode($b2));
$P = sx_prefixe(); $brut = sx_get("$P/fichiers/fiches/$doc.amx"); t('objet distant chiffré avec la clé externe', str_starts_with($brut, 'AMX1') && sx_dechiffre($brut) === file_get_contents(fiche_dir() . "/$doc"));
$v = sx_verifier(); t('vérification de restauration', $v['ok'], implode(' | ', $v['details']));
// Restauration complète dans une autre base et un dossier vide.
$par = tempnam(sys_get_temp_dir(), 'par') . '.php'; $cible = sys_get_temp_dir() . '/restau-' . bin2hex(random_bytes(4));
file_put_contents($par, "<?php\n" . implode("\n", array_map(fn($c) => "define('$c', " . var_export(constant($c), true) . ");", ['SV_S3_ENDPOINT', 'SV_S3_REGION', 'SV_S3_BUCKET', 'SV_S3_CLE_ACCES', 'SV_S3_SECRET', 'SV_CLE_EXTERNE'])) . "\ndefine('SV_S3_PREFIXE', " . var_export($P, true) . ");\n");
$cmd = 'php ' . escapeshellarg(__DIR__ . '/../outils/restaurer.php') . ' --parametres=' . escapeshellarg($par) . ' --cible=' . escapeshellarg($cible) . ' --db-host=' . escapeshellarg($hote) . ' --db-name=' . escapeshellarg($dbr) . ' --db-user=' . escapeshellarg($u) . ' --db-pass=' . escapeshellarg($pw);
exec($cmd . ' 2>&1', $sortie, $rc); echo '      ' . implode("\n      ", $sortie) . "\n"; t('outil de restauration', $rc === 0);
$R = new PDO("mysql:host=$hote;dbname=$dbr;charset=utf8mb4", $u, $pw); $orig = db();
$tabs = $orig->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN); $diff = [];
foreach ($tabs as $tb) { if (in_array($tb, ['limites'], true)) continue; $a = (int)$orig->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn(); $c = (int)$R->query("SELECT COUNT(*) FROM `$tb`")->fetchColumn(); if ($a !== $c) $diff[] = "$tb ($a/$c)"; }
t('base restaurée : mêmes tables, mêmes nombres de lignes (' . count($tabs) . ' tables)', !$diff, implode(', ', $diff));
t('photo restaurée à l\'identique', hash_file('sha256', "$cible/uploads/$photo") === hash_file('sha256', upload_dir() . "/$photo"));
$c = (string)file_get_contents("$cible/config.php.restaure"); t('config.php restauré, document relisible avec sa clé', preg_match('/(LPI|AMAYA)_CLE/', $c) && amy_dechiffre((string)file_get_contents("$cible/uploads/fiches/$doc")) === '%PDF-1.4 pièce de test');
@unlink(upload_dir() . "/$photo"); @unlink(fiche_dir() . "/$doc"); @unlink($par); exec('rm -rf ' . escapeshellarg($cible));
echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
