<?php
// Tests unitaires d'Amaya, en ligne de commande, sur une installation de test (jamais sur la production).
// Usage : php tests/unitaires.php /chemin/vers/installation
if (PHP_SAPI !== 'cli') exit;
$dir = rtrim($argv[1] ?? '', '/'); if (!$dir || !is_file("$dir/config.php")) { fwrite(STDERR, "Usage : php tests/unitaires.php <dossier d'une installation de test>\n"); exit(2); }
chdir($dir); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require "$dir/lib.php";
$ok = 0; $ko = 0;
function t(string $nom, $cond): void { global $ok, $ko; if ($cond) { $ok++; echo "  ok   $nom\n"; } else { $ko++; echo "  ÉCHEC $nom\n"; } }

echo "Chiffrement\n";
$c = amy_chiffre('%PDF-1.4 essai'); t('le chiffré ne contient pas le clair', strpos($c, '%PDF') === false);
t('aller-retour exact', amy_dechiffre($c) === '%PDF-1.4 essai');
$c[20] = chr(ord($c[20]) ^ 1); t('un chiffré altéré est refusé', amy_dechiffre($c) === null);
$d = json_decode(fiche_data_json(['secu' => '2 99 01 75 123 456 78', 'iban' => 'FR7630006000011234567890189']), true);
t('n° de sécurité sociale stocké chiffré', str_starts_with($d['secu'], 'enc:'));
t('IBAN stocké chiffré', str_starts_with($d['iban'], 'enc:'));
t('n° de sécurité sociale relu à l\'identique', secu_clair($d['secu']) === '2 99 01 75 123 456 78');

echo "Double authentification (RFC 6238)\n";
$s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
t('vecteur T=59', tf_code($s, 1) === '287082');
t('vecteur T=1111111109', tf_code($s, intdiv(1111111109, 30)) === '081804');
t('vecteur T=2000000000', tf_code($s, intdiv(2000000000, 30)) === '279037');
$code = tf_code($s, intdiv(time(), 30));
t('code courant accepté', tf_verifier($s, $code, 0) !== null);
t('même code refusé une 2e fois', tf_verifier($s, $code, intdiv(time(), 30)) === null);

echo "Mises à jour\n";
require_once "$dir/maj_lib.php";
$z = tempnam(sys_get_temp_dir(), 'amz'); $za = new ZipArchive(); $za->open($z, ZipArchive::OVERWRITE); $za->addFromString('amaya/version.php', '<?php const AMAYA_VERSION = "9.9.9";'); $za->close();
$refus = false; try { maj_verifier($z); } catch (Throwable $e) { $refus = true; } @unlink($z);
t('paquet non signé refusé', $refus);

echo "Sauvegarde\n";
require_once "$dir/sauvegarde_lib.php";
$r = sv_faire('test automatique'); $gz = sv_lire($r['nom']); $sql = gzdecode($gz);
t('sauvegarde chiffrée relue', $sql !== false && str_contains($sql, 'CREATE TABLE'));
t('fichier de sauvegarde illisible en clair', !str_contains((string)file_get_contents(sv_dir() . '/' . $r['nom']), 'CREATE TABLE'));
@unlink(sv_dir() . '/' . $r['nom']);

echo "Documents de fin de contrat\n";
require_once "$dir/rh_lib.php";
t('montant en lettres', rh_lettres(1234.56) === 'mille-deux-cent-trente-quatre euros et cinquante-six centimes');
t('quatre-vingts invariable devant mille', rh_lettres(80000) === 'quatre-vingt-mille euros');

echo "Contrôles d'accès (lecture du code)\n";
$publiques = ['api.php' => ['login', 'login_code', 'login_admin', 'login_totp', 'ins_preparer', 'ins_finir', 'logout', 'apercu_stop', 'push_cle', 'session'], 'marques_api.php' => ['login', 'login_totp', 'logout', 'session']];
foreach (['api.php' => '/need_admin|need_anim|admin_ok|me\(\)|est_titulaire|need_|tf_ok/', 'marques_api.php' => '/cl_compte/'] as $f => $re) {
  $src = (string)file_get_contents("$dir/$f"); $p = preg_split("/\n\s*case '([a-z0-9_]+)':/", $src, -1, PREG_SPLIT_DELIM_CAPTURE); $sans = [];
  for ($i = 1; $i < count($p); $i += 2) { $noms = [$p[$i]]; $b = $p[$i + 1]; while (trim($b) === '' && $i + 2 < count($p)) { $i += 2; $noms[] = $p[$i]; $b = $p[$i + 1]; }
    if (!preg_match($re, substr($b, 0, 700)) && !array_intersect($noms, $publiques[$f])) $sans[] = implode('/', $noms); }
  t("$f : chaque action vérifie l'accès" . ($sans ? ' (sans contrôle : ' . implode(', ', $sans) . ')' : ''), !$sans);
}
foreach (['gestion_api.php' => 'g_need()', 'planning_api.php' => 'g_acces()'] as $f => $g) t("$f : accès direction vérifié en tête", str_contains(substr((string)file_get_contents("$dir/$f"), 0, 1500), $g));

echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
