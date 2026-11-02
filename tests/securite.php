<?php
// Connexions de l'administration sur une installation de TEST neuve (hors démo) : mot de passe de secours haché,
// plus d'accès sans e-mail, double authentification obligatoire, codes de secours, procédure de secours.
// Usage : php tests/securite.php <adresse> <dossier de l'installation>   (mot de passe de secours : Secours-Test-2026-long)
if (PHP_SAPI !== 'cli') exit;
[$_, $B, $dir] = $argv + [null, '', '']; $B = rtrim((string)$B, '/'); $dir = rtrim((string)$dir, '/');
if (!$B || !is_file("$dir/config.php")) { fwrite(STDERR, "Usage : php tests/securite.php <adresse> <dossier>\n"); exit(2); }
define('SECOURS', getenv('AMAYA_SECOURS') ?: 'Secours-Test-2026-long'); const DIR_EMAIL = 'direction@test.invalid'; const MDP = 'Mon-mot-de-passe-perso-1';
$jar = tempnam(sys_get_temp_dir(), 'ams'); $ok = 0; $ko = 0;
function t(string $n, $c, string $i = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($i ? " : $i" : '') . "\n"; } }
function http(string $u, $body = null, bool $form = false): array { global $B, $jar; $c = curl_init("$B/$u"); $h = $form ? [] : ['X-Requested-With: amaya', 'Content-Type: application/json'];
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $h] + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $form ? http_build_query($body) : json_encode($body)] : []));
  $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); return ['code' => $code, 'body' => $r, 'json' => json_decode($r, true)]; }
function totp(string $secret): string { $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret)); $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $b = ''; foreach (str_split($s) as $ch) $b .= str_pad(decbin(strpos($a, $ch)), 5, '0', STR_PAD_LEFT);
  $k = ''; foreach (str_split($b, 8) as $g) if (strlen($g) === 8) $k .= chr(bindec($g)); $h = hash_hmac('sha1', pack('J', intdiv(time(), 30)), $k, true); $o = ord($h[19]) & 15;
  return str_pad((string)((((ord($h[$o]) & 127) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3])) % 1000000), 6, '0', STR_PAD_LEFT); }
$cfg = "$dir/config.php";

echo "Mot de passe de secours\n";
t('config.php neuf : empreinte seulement', str_contains(file_get_contents($cfg), "ADMIN_PASSWORD_HASH") && !preg_match("/define\('ADMIN_PASSWORD',/", file_get_contents($cfg)));
// Ancienne installation simulée : mot de passe en clair, converti à la première connexion.
file_put_contents($cfg, preg_replace("/define\('ADMIN_PASSWORD_HASH', '[^']*'\);/", "define('ADMIN_PASSWORD', '" . SECOURS . "');", file_get_contents($cfg)));
sleep(3); // laisse expirer le cache d'opcodes de PHP-FPM (revalidation toutes les 2 s)
$r = http('api.php?action=login_admin', ['email' => '', 'password' => SECOURS]); t('connexion sans e-mail refusée', $r['code'] === 401, $r['body']);
t('mot de passe en clair converti en empreinte', str_contains(file_get_contents($cfg), "ADMIN_PASSWORD_HASH") && !str_contains(file_get_contents($cfg), SECOURS));

echo "Première connexion d'un dirigeant\n";
$r = http('api.php?action=login_admin', ['email' => DIR_EMAIL, 'password' => SECOURS]);
t('activation exigée (mot de passe + 2FA)', ($r['json']['inscription'] ?? false) && ($r['json']['mdp'] ?? false) && ($r['json']['tf'] ?? false), $r['body']);
t('administration fermée avant activation', http('api.php?action=admin_lists')['code'] === 401);
t('planning fermé avant activation', http('planning_api.php?action=operations')['code'] === 401);
$p = http('api.php?action=ins_preparer'); $sec = (string)($p['json']['secret'] ?? ''); t('secret 2FA fourni', strlen(preg_replace('/\s/', '', $sec)) >= 32);
t('mot de passe trop court refusé', http('api.php?action=ins_finir', ['password' => 'court', 'confirm' => 'court', 'code' => totp($sec)])['code'] === 400);
t('mot de passe de secours refusé comme mot de passe personnel', http('api.php?action=ins_finir', ['password' => SECOURS, 'confirm' => SECOURS, 'code' => totp($sec)])['code'] === 400);
t('code 2FA faux refusé', http('api.php?action=ins_finir', ['password' => MDP, 'confirm' => MDP, 'code' => '000000'])['code'] === 400);
$code1 = totp($sec); $f = http('api.php?action=ins_finir', ['password' => MDP, 'confirm' => MDP, 'code' => $code1]); $codes = $f['json']['secours'] ?? [];
t('activation terminée, 8 codes de secours', ($f['json']['ok'] ?? false) && count($codes) === 8, $f['body']);
t('administration ouverte après activation', http('api.php?action=admin_lists')['code'] === 200);
t('désactivation de la 2FA refusée', http('api.php?action=tf_desactiver', ['password' => MDP])['code'] === 400);
http('api.php?action=logout', []);

echo "Connexions suivantes\n";
t('mot de passe de secours refusé une fois le compte personnel créé', http('api.php?action=login_admin', ['email' => DIR_EMAIL, 'password' => SECOURS])['code'] === 401);
$r = http('api.php?action=login_admin', ['email' => DIR_EMAIL, 'password' => MDP]); t('mot de passe personnel : code demandé', ($r['json']['code'] ?? false) === true, $r['body']);
t('code déjà utilisé refusé (rejeu)', http('api.php?action=login_totp', ['code' => $code1])['code'] === 401);
$r = http('api.php?action=login_totp', ['code' => $codes[0] ?? '']); t('code de secours accepté', ($r['json']['ok'] ?? false) === true, $r['body']);
t('accès ouvert', http('api.php?action=admin_lists')['code'] === 200);
$a = http('api.php?action=save_admin', ['id' => 0, 'nom' => 'Assistante Test', 'email' => 'assistante@test.invalid', 'password' => 'Assistante-2026-test', 'droits' => ['rapports'], 'actif' => 1]);
t('création d\'un compte administrateur', $a['code'] === 200, $a['body']); http('api.php?action=logout', []);
$r = http('api.php?action=login_admin', ['email' => 'assistante@test.invalid', 'password' => 'Assistante-2026-test']);
t('autre administrateur : 2FA exigée à la première connexion', ($r['json']['inscription'] ?? false) && ($r['json']['tf'] ?? false) && !($r['json']['mdp'] ?? true), $r['body']);
http('api.php?action=logout', []);

echo "Procédure de secours\n";
$s = http('secours.php'); t('sans fichier déposé, page inactive', str_contains($s['body'], 'secours.txt') && !str_contains($s['body'], 'name="password"'));
@mkdir("$dir/prive", 0755, true); file_put_contents("$dir/prive/secours.txt", 'test');
$s = http('secours.php', ['email' => DIR_EMAIL, 'password' => 'mauvais'], true); t('mauvais mot de passe de secours refusé', str_contains($s['body'], 'incorrect'));
$s = http('secours.php', ['email' => DIR_EMAIL, 'password' => SECOURS], true); t('compte rétabli', str_contains($s['body'], 'Compte rétabli'), substr(strip_tags($s['body']), 0, 200));
t('fichier de secours supprimé', !is_file("$dir/prive/secours.txt"));
file_put_contents("$dir/prive/secours.txt", 'test'); for ($i = 0; $i < 6; $i++) $s = http('secours.php', ['email' => DIR_EMAIL, 'password' => 'mauvais' . $i], true);
t('trop d\'essais : message clair, sans erreur technique', str_contains($s['body'], 'Trop d') && !str_contains($s['body'], 'undefined'), substr(strip_tags($s['body']), 0, 200)); @unlink("$dir/prive/secours.txt");
t('ancien mot de passe personnel refusé', http('api.php?action=login_admin', ['email' => DIR_EMAIL, 'password' => MDP])['code'] === 401);
$r = http('api.php?action=login_admin', ['email' => DIR_EMAIL, 'password' => SECOURS]); t('nouvelle activation exigée (mot de passe + 2FA)', ($r['json']['mdp'] ?? false) && ($r['json']['tf'] ?? false), $r['body']);

@unlink($jar); echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
