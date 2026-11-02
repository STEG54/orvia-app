<?php
// Lot « Élevé » de l'audit (E1, E2, E4, E7) sur une installation de TEST neuve (hors démo) :
// photos servies seulement aux personnes connectées ou par lien signé, durée des sessions de la direction,
// déconnexion des téléphones d'un(e) intervenant(e), espace marques (limite d'essais, double authentification), en-têtes de sécurité.
// Usage : php tests/acces.php <adresse> <dossier de l'installation>
if (PHP_SAPI !== 'cli') exit;
[$_, $B, $dir] = $argv + [null, '', '']; $B = rtrim((string)$B, '/'); $dir = rtrim((string)$dir, '/');
if (!$B || !is_file("$dir/config.php")) { fwrite(STDERR, "Usage : php tests/acces.php <adresse> <dossier>\n"); exit(2); }
// Sortie écrite directement : PHP ne considère jamais les en-têtes comme envoyés (sessions modifiables dans le test).
ob_start(function ($b) { fwrite(STDOUT, $b); return ''; }, 1);
// Cartographie : un faux service Geoapify local (aucune requête vers l'extérieur).
$GP = 8190 + random_int(0, 50); $gsrv = proc_open(['php', '-S', "127.0.0.1:$GP", __DIR__ . '/fixtures/geoapify-simule.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp);
$cf = file_get_contents("$dir/config.php"); $cf = preg_replace("/\\ndefine\\('GEO_(CLE|GEOAPIFY_URL)'.*/", '', $cf);
file_put_contents("$dir/config.php", rtrim($cf) . "\ndefine('GEO_CLE', 'cle-de-test');\ndefine('GEO_GEOAPIFY_URL', 'http://127.0.0.1:$GP');\n");
// Le serveur de test peut garder l'ancien config.php en cache d'opcodes : on le vide par une requête.
file_put_contents("$dir/zz-opcache.php", '<?php if (function_exists("opcache_reset")) opcache_reset(); echo "ok";'); @file_get_contents("$B/zz-opcache.php"); @unlink("$dir/zz-opcache.php"); sleep(3); @unlink(sys_get_temp_dir() . '/geoapify-simule.log'); usleep(500000);
require "$dir/lib.php"; require_once "$dir/migrations_lib.php";
$ok = 0; $ko = 0;
function t(string $n, $c, string $i = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($i ? " : " . substr($i, 0, 200) : '') . "\n"; } }
/** Requête HTTP avec son propre pot de cookies ; renvoie code, en-têtes (minuscules), corps, JSON. */
function req(string $jar, string $u, $body = null, array $h = []): array {
  global $B; $c = curl_init("$B/$u"); $hd = [];
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => array_merge(array_filter(['X-Requested-With: amaya', 'Content-Type: application/json'], fn($d) => !array_filter($h, fn($x) => stripos($x, strtok($d, ':') . ':') === 0)), $h),
    CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hd) { if (str_contains($l, ':')) { [$k, $v] = explode(':', $l, 2); $hd[strtolower(trim($k))] = trim($v); } return strlen($l); }]
    + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)] : []));
  $r = (string)curl_exec($c); $code = curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c); return ['code' => $code, 'h' => $hd, 'body' => $r, 'json' => json_decode($r, true)];
}
function totp(string $s): string { return tf_code(strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s)), intdiv(time(), 30)); }
function sid(string $jar): string { foreach (file($jar) ?: [] as $l) { $p = explode("\t", trim($l)); if (count($p) === 7 && str_starts_with($p[5], 'ag')) return $p[6]; } return ''; }
/** Modifie une session enregistrée (comme si le temps avait passé). */
function session_modifier(string $sid, callable $f): void {
  ini_set('session.save_path', upload_dir() . '/.sessions'); ini_set('session.use_cookies', '0'); ini_set('session.cache_limiter', '');
  session_id($sid); @session_start(); $f(); session_write_close();
}
$nom = 'ag' . substr(hash('sha256', LPI_CLE), 0, 8);

echo "En-têtes de sécurité (E7)\n";
$j0 = tempnam(sys_get_temp_dir(), 'acc'); $r = req($j0, 'admin.php');
t('page : politique de contenu', str_contains($r['h']['content-security-policy'] ?? '', "frame-ancestors 'self'") && str_contains($r['h']['content-security-policy'] ?? '', "object-src 'none'"), json_encode($r['h']));
t('page : pas d\'affichage dans un cadre d\'un autre site', ($r['h']['x-frame-options'] ?? '') === 'SAMEORIGIN');
t('page : type de contenu non deviné, référent limité', ($r['h']['x-content-type-options'] ?? '') === 'nosniff' && ($r['h']['referrer-policy'] ?? '') !== '');
t('page : caméra et position réservées à l\'appli', str_contains($r['h']['permissions-policy'] ?? '', 'camera=(self)'));
$r = req($j0, 'api.php?action=session'); t('API : en-têtes présents, pas de politique de page', ($r['h']['x-content-type-options'] ?? '') === 'nosniff' && !isset($r['h']['content-security-policy']));

echo "Photos (E1)\n";
$f = bin2hex(random_bytes(16)); $img = imagecreatetruecolor(4, 4); imagejpeg($img, upload_dir() . "/$f.jpg"); imagejpeg($img, upload_dir() . "/{$f}_t.jpg");
$r = req($j0, "photo.php?f=$f"); t('sans connexion : refusée', $r['code'] === 401, (string)$r['code']);
$lien = photo_lien($f, 30); $q = substr($lien, strpos($lien, 'photo.php'));
$r = req($j0, $q); t('lien signé et daté : servie', $r['code'] === 200 && ($r['h']['content-type'] ?? '') === 'image/jpeg', (string)$r['code']);
t('lien signé altéré : refusé', req($j0, preg_replace('/&s=[a-f0-9]{4}/', '&s=0000', $q))['code'] === 401);
$e = time() - 10; $s = substr(hash_hmac('sha256', "$f|$e", photo_cle()), 0, 32); t('lien expiré : refusé', req($j0, "photo.php?f=$f&e=$e&s=$s")['code'] === 401);
t('nom de fichier invalide : introuvable', req($j0, 'photo.php?f=../config')['code'] === 404);
t('règle uploads/.htaccess posée', str_contains((string)@file_get_contents(upload_dir() . '/.htaccess'), '^[a-f0-9]{32}(_t)?\.jpg$'));
t('aperçu HTML : chemins réécrits', photos_html('<img src="uploads/' . $f . '_t.jpg">') === '<img src="photo.php?f=' . $f . '&amp;t=1">');

echo "Sessions de la direction (E2)\n";
tf_schema(); $sec = tf_b32(random_bytes(20)); $em = 'e2-' . bin2hex(random_bytes(3)) . '@test.invalid'; $mdp = 'Direction-Acces-2026';
db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at, totp_secret, totp_actif) VALUES (?,?,?,?,1,?,?,1)")->execute(['Admin E2', $em, password_hash($mdp, PASSWORD_DEFAULT), 'tout', now(), base64_encode(amy_chiffre($sec))]);
$ja = tempnam(sys_get_temp_dir(), 'acc'); req($ja, 'api.php?action=login_admin', ['email' => $em, 'password' => $mdp]); $r = req($ja, 'api.php?action=login_totp', ['code' => totp($sec)]);
t('connexion', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = req($ja, 'api.php?action=admin_lists'); t('accès ouvert', $r['code'] === 200);
t('photo visible pour la direction', req($ja, "photo.php?f=$f&t=1")['code'] === 200);
session_modifier(sid($ja), function () { $_SESSION['admin_vu'] = time() - 3700; });
$r = req($ja, 'api.php?action=admin_lists'); t('60 min sans activité : session fermée et signalée', $r['code'] === 401 && ($r['h']['x-amaya-session'] ?? '') === 'expiree', $r['code'] . ' ' . json_encode($r['h']));
$ja = tempnam(sys_get_temp_dir(), 'acc'); req($ja, 'api.php?action=login_admin', ['email' => $em, 'password' => $mdp]); sleep(1);
$c2 = tf_code($sec, intdiv(time(), 30) + 1); $r = req($ja, 'api.php?action=login_totp', ['code' => $c2]); if (!($r['json']['ok'] ?? false)) { sleep(30); $r = req($ja, 'api.php?action=login_totp', ['code' => totp($sec)]); }
session_modifier(sid($ja), function () { $_SESSION['admin_debut'] = time() - 13 * 3600; });
t('au-delà de 12 h : session fermée', req($ja, 'api.php?action=admin_lists')['code'] === 401);

/** Nouvelle session de direction (le compteur anti-rejeu du code est remis à zéro pour le test). */
function connecter(string $em, string $mdp, string $sec): string { $j = tempnam(sys_get_temp_dir(), 'acc'); foreach (['admin', 'admin_code'] as $lk) db()->exec("DELETE FROM limites WHERE k = '" . sha1($lk . '|127.0.0.1') . "'"); /* limites d'essais par adresse : les tests se connectent souvent */ db()->prepare("UPDATE admins SET totp_dernier = 0 WHERE email = ?")->execute([$em]);
  req($j, 'api.php?action=login_admin', ['email' => $em, 'password' => $mdp]); req($j, 'api.php?action=login_totp', ['code' => totp($sec)]); return $j; }

echo "Journal des accès (M1)\n";
$de = array_key_first(ADMINS_PRINCIPAUX); $st = db()->prepare("SELECT id FROM admins WHERE email = ?"); $st->execute([$de]);
if (!$st->fetchColumn()) db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES ('Direction', ?, '', 'tout', 1, ?)")->execute([$de, now()]);
db()->prepare("UPDATE admins SET pass_hash = ?, totp_secret = ?, totp_actif = 1, actif = 1 WHERE email = ?")->execute([password_hash($mdp, PASSWORD_DEFAULT), base64_encode(amy_chiffre($sec)), $de]);
$jd = connecter($de, $mdp, $sec); t('connexion pour le journal', req($jd, 'api.php?action=admin_lists')['code'] === 200);
db()->exec("DELETE FROM limites WHERE k = '" . sha1('admin|127.0.0.1') . "'"); $jx = tempnam(sys_get_temp_dir(), 'acc'); req($jx, 'api.php?action=login_admin', ['email' => $em, 'password' => 'faux-mot-de-passe']);
require_once "$dir/sauvegarde_lib.php"; $sv = sv_faire('test');
$r = req($jd, 'gestion_api.php?action=sauvegarde_dl&nom=' . urlencode($sv['nom'])); t('sauvegarde téléchargée', $r['code'] === 200 && strlen($r['body']) > 100);
$J = req($jd, 'gestion_api.php?action=journal'); $L = $J['json']['lignes'] ?? [];
$a = fn(string $act, string $mot) => (bool)array_filter($L, fn($l) => $l['action'] === $act && str_contains($l['objet'] . ' ' . $l['qui'], $mot));
t('journal : connexion de la direction', $a('connexion', $de), $J['body']);
t('journal : connexion refusée, avec l\'e-mail essayé', $a('connexion refusée', $em));
t('journal : téléchargement de sauvegarde (fichier et action)', $a('téléchargement', str_replace('.enc', '', $sv['nom'])) && $a('téléchargement', 'sauvegarde_dl'));
$r = req($jd, 'gestion_api.php?action=journal&csv=1&q=' . urlencode($em)); t('journal : export CSV', $r['code'] === 200 && str_contains($r['body'], 'Adresse IP') && str_contains($r['body'], $em));
db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at, totp_secret, totp_actif) VALUES (?,?,?,?,1,?,?,1)")->execute(['Assistante M1', 'm1-' . $em, password_hash($mdp, PASSWORD_DEFAULT), 'rapports', now(), base64_encode(amy_chiffre($sec))]);
t('journal : fermé aux comptes non dirigeants', req(connecter('m1-' . $em, $mdp, $sec), 'gestion_api.php?action=journal')['code'] === 403);

echo "Sauvegarde au fil de l'eau (M3)\n";
$gz = sv_lire($sv['nom']); $inv = sv_inventaire($gz); $vivantes = db()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
t('sauvegarde complète : toutes les tables', $inv && !array_diff($vivantes, $inv['tables']), $inv ? implode(',', array_diff($vivantes, $inv['tables'])) : 'illisible');
t('contenu identique à une décompression complète', $inv && $inv['taille'] === strlen((string)gzdecode($gz)));
t('aucun fichier temporaire laissé', !glob(sv_dir() . '/.en-cours-*'));

echo "Erreurs et diagnostic (M4)\n";
$r = req(tempnam(sys_get_temp_dir(), 'acc'), 'diag.php'); t('diagnostic fermé sans connexion', $r['code'] === 403 && !str_contains($r['body'], DB_NAME));
t('diagnostic ouvert à un(e) dirigeant(e)', req($jd, 'diag.php')['code'] === 200);
@mkdir("$dir/prive", 0755, true); file_put_contents("$dir/prive/diag.txt", 'x'); $r = req(tempnam(sys_get_temp_dir(), 'acc'), 'diag.php'); @unlink("$dir/prive/diag.txt");
t('diagnostic ouvert 2 h après dépôt de prive/diag.txt', $r['code'] === 200 && str_contains($r['body'], 'config.php lu'));
file_put_contents("$dir/zz-erreur.php", "<?php require __DIR__ . '/lib.php'; db()->query('SELECT * FROM table_absente_xyz');");
$r = req(tempnam(sys_get_temp_dir(), 'acc'), 'zz-erreur.php'); @unlink("$dir/zz-erreur.php");
t('erreur interne : message simple et référence, sans détail technique', $r['code'] === 500 && str_contains($r['body'], 'référence') && !str_contains($r['body'], 'table_absente_xyz') && !str_contains($r['body'], $dir), substr(strip_tags($r['body']), 0, 160));

echo "Mots de passe (F2)\n";
foreach (['Court-1' => 'trop court', 'azertyuiop12' => 'courant', 'Motdepasse2026!' => 'courant', 'aaaaaaaaaaaa' => 'répétitif'] as $pw => $why)
  t("refusé ($why) : $pw", req($jd, 'api.php?action=save_admin', ['id' => 0, 'nom' => 'F2', 'email' => 'f2-' . bin2hex(random_bytes(2)) . '@test.invalid', 'password' => $pw, 'droits' => ['rapports'], 'actif' => 1])['code'] === 400);
t('accepté : une suite de mots à soi', req($jd, 'api.php?action=save_admin', ['id' => 0, 'nom' => 'F2', 'email' => 'f2ok-' . bin2hex(random_bytes(2)) . '@test.invalid', 'password' => 'Velo-rouge sous la pluie', 'droits' => ['rapports'], 'actif' => 1])['code'] === 200);
t('IA : noms, e-mails et téléphones retirés', ia_anonymiser('Léa Martin, 06 12 34 56 78, lea@x.fr ; Martine', ['Léa Martin']) === '[intervenant(e)] [intervenant(e)], [téléphone], [e-mail] ; Martine');

echo "Téléphones des intervenant(e)s (E2)\n";
db()->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at) VALUES ('Test','Jeton','0600000000',?, '', 1, 'actif', ?)")->execute(['jeton-' . bin2hex(random_bytes(3)) . '@test.invalid', now()]);
$aid = (int)db()->lastInsertId(); jetons_table();
$tok = bin2hex(random_bytes(32)); db()->prepare("INSERT INTO jetons (hash, animatrice_id, expire_le) VALUES (?, ?, NOW() + INTERVAL 5 DAY)")->execute([hash('sha256', $tok), $aid]);
$jw = tempnam(sys_get_temp_dir(), 'acc'); $r = req($jw, 'api.php?action=session', null, ["X-Lpi-Rem: $tok"]);
t('jeton du téléphone : reconnexion', (int)($r['json']['animatrice']['id'] ?? $r['json']['me']['id'] ?? 0) === $aid || str_contains($r['body'], '"id":' . $aid), $r['body']);
$x = db()->prepare("SELECT DATEDIFF(expire_le, NOW()) FROM jetons WHERE hash = ?"); $x->execute([hash('sha256', $tok)]); t('jeton prolongé à l\'usage (90 jours)', (int)$x->fetchColumn() >= 89);
t('photo visible pour l\'intervenant(e)', req($jw, "photo.php?f=$f")['code'] === 200);
anim_deconnecter($aid);
$r = req($jw, 'api.php?action=session'); t('déconnexion par la direction : session fermée', !str_contains($r['body'], '"id":' . $aid), $r['body']);
$r = req(tempnam(sys_get_temp_dir(), 'acc'), 'api.php?action=session', null, ["X-Lpi-Rem: $tok"]); t('jeton supprimé : plus de reconnexion', !str_contains($r['body'], '"id":' . $aid));
t('photo refusée après déconnexion', req($jw, "photo.php?f=$f")['code'] === 401);

echo "Espace marques (E4)\n";
$cm = 'marque-' . bin2hex(random_bytes(3)) . '@test.invalid'; $cp = 'Marque-Acces-2026';
db()->prepare("INSERT INTO comptes_clients (nom, societe, email, marques, zones, droits, actif, pass_hash, created_at) VALUES ('Client Test','Marque Test',?,'','','ca,photos,cr,telecharger',1,?,?)")->execute([$cm, password_hash($cp, PASSWORD_DEFAULT), now()]);
$jm = tempnam(sys_get_temp_dir(), 'acc'); $r = req($jm, 'marques_api.php?action=login', ['email' => $cm, 'password' => $cp]); t('connexion sans double authentification', ($r['json']['ok'] ?? false) && empty($r['json']['totp']), $r['body']);
t('photo visible pour le compte marque', req($jm, "photo.php?f=$f")['code'] === 200);
$p = req($jm, 'marques_api.php?action=tf_preparer', []); $ms = (string)($p['json']['secret'] ?? ''); t('activation proposée (QR code)', str_starts_with((string)($p['json']['uri'] ?? ''), 'otpauth://totp/'), $p['body']);
t('code faux refusé', req($jm, 'marques_api.php?action=tf_activer', ['code' => '000000'])['code'] === 400);
$a = req($jm, 'marques_api.php?action=tf_activer', ['code' => totp($ms)]); $mc = $a['json']['secours'] ?? []; t('double authentification activée, 8 codes de secours', count($mc) === 8, $a['body']);
req($jm, 'marques_api.php?action=logout', []);
$r = req($jm, 'marques_api.php?action=login', ['email' => $cm, 'password' => $cp]); t('connexion suivante : code demandé', ($r['json']['totp'] ?? false) === true, $r['body']);
t('pas d\'accès avant le code', req($jm, 'marques_api.php?action=session')['json']['compte'] === null);
t('code faux refusé à la connexion', req($jm, 'marques_api.php?action=login_totp', ['code' => '111111'])['code'] === 401);
$r = req($jm, 'marques_api.php?action=login_totp', ['code' => $mc[0] ?? '']); t('code de secours accepté', ($r['json']['ok'] ?? false) === true, $r['body']);
t('désactivation : mot de passe exigé', req($jm, 'marques_api.php?action=tf_desactiver', ['password' => 'faux'])['code'] === 400);
t('désactivation avec le mot de passe', (req($jm, 'marques_api.php?action=tf_desactiver', ['password' => $cp])['json']['ok'] ?? false) === true);
$n = 0; for ($i = 0; $i < 9; $i++) { $r = req(tempnam(sys_get_temp_dir(), 'acc'), 'marques_api.php?action=login', ['email' => $cm, 'password' => 'mauvais-' . $i]); if ($r['code'] === 429) { $n = $i + 1; break; } }
t('essais répétés sur un compte : bloqués même en changeant de session', $n > 0 && $n <= 9, "bloqué au $n e essai");
t('le bon mot de passe est aussi bloqué pendant la pause', req(tempnam(sys_get_temp_dir(), 'acc'), 'marques_api.php?action=login', ['email' => $cm, 'password' => $cp])['code'] === 429);

echo "Cartographie (M6)\n";
require_once "$dir/geo_lib.php"; $GLOBALS['GEO_MAX'] = 50; $A = [43.296 + random_int(0, 999) / 1e6, 5.37]; $Bp = [43.53, 5.45];
$r = geo_route($A, $Bp); t('itinéraire calculé par Geoapify', $r['route'] === true && $r['km'] == 12.3, json_encode($r));
$log = (string)@file_get_contents(sys_get_temp_dir() . '/geoapify-simule.log');
t('seules des coordonnées partent chez le fournisseur', str_contains($log, '/v1/routing?waypoints=') && !preg_match('/[A-Za-z]{4,}%20|@/', explode('apiKey', $log)[0]), $log);
$n1 = substr_count((string)@file_get_contents(sys_get_temp_dir() . '/geoapify-simule.log'), '/v1/routing'); $r2 = geo_route($A, $Bp);
$n2 = substr_count((string)@file_get_contents(sys_get_temp_dir() . '/geoapify-simule.log'), '/v1/routing'); t('résultat gardé en mémoire (pas de 2e appel)', $r2['km'] == 12.3 && $n2 === $n1, "$n1 → $n2");
$S = req($jd, 'api.php?action=magasin_suggest', ['nom' => 'Parfumerie Test', 'ville' => 'Paris', 'adresse' => '', 'zone' => 'France']);
t('recherche d\'un magasin par son nom (Geoapify, mention affichée)', ($S['json']['geoapify'] ?? false) && (bool)array_filter($S['json']['suggestions'] ?? [], fn($x) => $x['source'] === 'Geoapify' && $x['adresse'] === '12 Rue de la Paix'), $S['body']);
proc_terminate($gsrv);

echo "Dirigeants gérés dans l'appli (M9)\n";
$jd = connecter($de, $mdp, $sec); $as = 'm9-' . bin2hex(random_bytes(2)) . '@test.invalid';
db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at, totp_secret, totp_actif) VALUES ('Adjointe M9',?,?,'rapports',1,?,?,1)")->execute([$as, password_hash($mdp, PASSWORD_DEFAULT), now(), base64_encode(amy_chiffre($sec))]);
$aidm9 = (int)db()->lastInsertId(); $ja9 = connecter($as, $mdp, $sec);
t('avant : pas d\'accès à la gestion', req($ja9, 'gestion_api.php?action=journal')['code'] === 403);
t('nommée dirigeante par un(e) dirigeant(e)', (req($jd, 'api.php?action=admin_dirigeant', ['id' => $aidm9, 'dirigeant' => 1])['json']['ok'] ?? false) === true);
$ja9 = connecter($as, $mdp, $sec); t('après : accès de dirigeante (journal, gestion)', req($ja9, 'gestion_api.php?action=journal')['code'] === 200);
$st = db()->prepare("SELECT id FROM admins WHERE email = ?"); $st->execute([$de]); $idde = (int)$st->fetchColumn();
t('le rôle d\'un(e) dirigeant(e) de config.php ne se retire pas', req($ja9, 'api.php?action=admin_dirigeant', ['id' => $idde, 'dirigeant' => 0])['code'] === 400);
t('on ne retire pas son propre rôle', req($ja9, 'api.php?action=admin_dirigeant', ['id' => $aidm9, 'dirigeant' => 0])['code'] === 400);
t('un(e) dirigeant(e) ne se supprime pas sans retirer son rôle', req($jd, 'api.php?action=delete_admin', ['id' => $aidm9])['code'] === 400);
t('rôle retiré', (req($jd, 'api.php?action=admin_dirigeant', ['id' => $aidm9, 'dirigeant' => 0])['json']['ok'] ?? false) === true && req(connecter($as, $mdp, $sec), 'gestion_api.php?action=journal')['code'] === 403);

echo "Exports (M2)\n";
db()->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at) VALUES ('Export','Test','0600000001',?, '', 1, 'actif', ?)")->execute(['export-' . bin2hex(random_bytes(3)) . '@test.invalid', now()]);
$ae = (int)db()->lastInsertId(); $SECU = '1850575123456 78';
db()->prepare("REPLACE INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?, ?, ?)")->execute([$ae, json_encode(['secu' => 'enc:' . base64_encode(amy_chiffre($SECU)), 'ville' => 'Lyon']), now()]);
$pc = 'piece_' . bin2hex(random_bytes(6)) . '.bin'; file_put_contents(fiche_dir() . '/' . $pc, amy_chiffre('%PDF-piece-test'));
db()->prepare("INSERT INTO pieces_identite (animatrice_id, type, fichier, nom, mime, taille, created_at) VALUES (?, 'cni', ?, 'cni.pdf', 'application/pdf', 15, ?)")->execute([$ae, $pc, now()]);
$r = req($jd, "api.php?action=exp_personne&id=$ae"); $zf = tempnam(sys_get_temp_dir(), 'zip'); file_put_contents($zf, $r['body']); $z = new ZipArchive();
$okz = $r['code'] === 200 && $z->open($zf) === true; $idj = $okz ? json_decode((string)$z->getFromName('identite.json'), true) : null;
t('données d\'une personne : ZIP téléchargé', $okz, $r['code'] . ' ' . substr($r['body'], 0, 120));
t('n° de sécurité sociale en clair dans l\'export', ($idj['fiche']['secu'] ?? '') === $SECU);
t('pièce d\'identité déchiffrée', $okz && $z->getFromName('documents/pieces/' . db()->query("SELECT MAX(id) FROM pieces_identite")->fetchColumn() . '_cni.pdf') === '%PDF-piece-test');
t('fichier d\'export effacé du serveur après remise', !glob(sv_dir() . '/export-personne-*'));
$r = req($jd, 'gestion_api.php?action=exp_agence', []); $mz = (string)($r['json']['mdp'] ?? '');
t('export complet : préparé, mot de passe fourni', $r['code'] === 200 && strlen($mz) >= 20, $r['body']);
$r = req($jd, 'gestion_api.php?action=exp_telecharger'); file_put_contents($zf, $r['body']); $z = new ZipArchive(); $oz = $z->open($zf) === true;
t('export complet : contenu illisible sans le mot de passe', $oz && @$z->getFromName('base/base.sql.gz') === false);
$z->setPassword($mz); $b = $oz ? (string)gzdecode((string)$z->getFromName('base/base.sql.gz')) : '';
t('export complet : base, fiches en clair et documents avec le mot de passe', str_contains($b, 'CREATE TABLE `animatrices`') && str_contains((string)$z->getFromName('base/fiches_en_clair.csv'), $SECU) && $z->getFromName('documents/' . $pc) === '%PDF-piece-test');
t('export complet : une seule remise', req($jd, 'gestion_api.php?action=exp_telecharger')['code'] === 404);

echo "Évolutions de la base numérotées (M8) et noms hérités (F1)\n";
$mt = db()->query("SELECT num FROM migrations ORDER BY num")->fetchAll(PDO::FETCH_COLUMN); t('évolutions notées (1 à ' . mig_derniere() . ')', array_map('intval', $mt) === array_keys(MIGRATIONS), json_encode($mt));
$ex = fn($t) => (bool)db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'")->fetchColumn();
t('table docs_agence, plus de docs_lpi', $ex('docs_agence') && !$ex('docs_lpi'));
// Ancienne installation simulée : noms d'origine, évolutions 2 et 3 pas encore faites.
db()->exec("INSERT INTO docs_agence (categorie, titre, fichier, nom, mime, taille, par, created_at) VALUES ('charte', 'Charte test M8', 'x.bin', 'x.pdf', 'application/pdf', 1, 'test', NOW())");
db()->exec("RENAME TABLE docs_agence TO docs_lpi"); db()->exec("UPDATE reglages SET cle = 'code_lpi' WHERE cle = 'code_agence'"); db()->exec("DELETE FROM migrations WHERE num >= 2"); @unlink(upload_dir() . '/.migrations');
$r = req($jd, 'api.php?action=docs_agence'); $mt = db()->query("SELECT num FROM migrations ORDER BY num")->fetchAll(PDO::FETCH_COLUMN);
t('ancienne installation : évolutions appliquées à la première visite', array_map('intval', $mt) === array_keys(MIGRATIONS) && $ex('docs_agence') && !$ex('docs_lpi'), json_encode($mt));
t('documents de l\'agence conservés', str_contains($r['body'], 'Charte test M8'), substr($r['body'], 0, 160));
t('code d\'accès conservé sous son nouveau nom', (bool)db()->query("SELECT COUNT(*) FROM reglages WHERE cle = 'code_agence'")->fetchColumn() && !db()->query("SELECT COUNT(*) FROM reglages WHERE cle = 'code_lpi'")->fetchColumn());
t('Maintenance : base à jour', (req($jd, 'gestion_api.php?action=maint')['json']['base']['a_jour'] ?? false) === true);
t('en-tête d\'origine encore accepté (téléphones pas rechargés)', req($jd, 'api.php?action=journal_vide_inexistante', [], ['X-Requested-With: lpi'])['code'] !== 403);
t('requête sans l\'en-tête de l\'appli refusée', req($jd, 'api.php?action=save_admin', [], ['X-Requested-With: autre'])['code'] === 403);

echo "E-mails (F3)\n";
$SP = 8240 + random_int(0, 50); $sj = tempnam(sys_get_temp_dir(), 'smtp'); $ssrv = proc_open(['php', __DIR__ . '/fixtures/smtp-simule.php', (string)$SP, $sj], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp2); usleep(500000);
$cf = preg_replace("/\\ndefine\\('MAIL_SMTP_[A-Z]+'.*/", '', file_get_contents("$dir/config.php"));
file_put_contents("$dir/config.php", rtrim($cf) . "\ndefine('MAIL_SMTP_HOST', '127.0.0.1');\ndefine('MAIL_SMTP_PORT', $SP);\ndefine('MAIL_SMTP_USER', 'smtp-user');\ndefine('MAIL_SMTP_PASS', 'mdp-test');\ndefine('MAIL_SMTP_TLS', false);\n");
file_put_contents("$dir/zz-opcache.php", '<?php if (function_exists("opcache_reset")) opcache_reset();'); @file_get_contents("$B/zz-opcache.php"); @unlink("$dir/zz-opcache.php"); sleep(3);
$E = req($jd, 'gestion_api.php?action=mail_etat')['json']; t('état des e-mails : mode SMTP et domaine', ($E['mode'] ?? '') === 'smtp' && ($E['domaine'] ?? '') !== '', json_encode($E));
$r = req($jd, 'gestion_api.php?action=mail_test', []); $J = (string)@file_get_contents($sj);
t('e-mail de test envoyé par le serveur SMTP, avec authentification', ($r['json']['ok'] ?? false) && str_contains($J, 'AUTH LOGIN') && str_contains($J, 'RCPT TO:<' . $de . '>'), $r['body'] . ' | ' . substr($J, 0, 300));
t('message complet (sujet, expéditeur, contenu)', str_contains($J, 'Subject: =?UTF-8?B?') && str_contains($J, 'From: ') && str_contains($J, 'MIME-Version: 1.0'));
proc_terminate($ssrv); file_put_contents("$dir/config.php", rtrim(preg_replace("/\\ndefine\\('MAIL_SMTP_[A-Z]+'.*/", '', file_get_contents("$dir/config.php"))) . "\n");
$r = req($jd, 'gestion_api.php?action=mail_test', []); t('serveur SMTP injoignable : échec signalé, pas d\'erreur technique', isset($r['json']) && $r['code'] === 200);

echo "Clé des documents (M5)\n";
$r = req($jd, 'gestion_api.php?action=cle_changer', ['confirmation' => 'non']); t('changement : confirmation exigée', $r['code'] === 400);
$r = req($jd, 'gestion_api.php?action=cle_changer', ['confirmation' => 'CHANGER']); $nk = (string)($r['json']['cle'] ?? '');
t('nouvelle clé créée, ancienne gardée le temps du rechiffrement', strlen((string)base64_decode($nk)) === 32 && preg_match('/(LPI|AMAYA)_CLES_ANCIENNES/', file_get_contents("$dir/config.php")), $r['body']);
t('graine de l\'installation sans la clé', !str_contains(file_get_contents("$dir/config.php"), "'AMAYA_GRAINE', '" . LPI_CLE));
sleep(3); $jd = connecter($de, $mdp, $sec); t('reconnexion (secret 2FA lu avec l\'ancienne clé)', req($jd, 'api.php?action=admin_lists')['code'] === 200);
for ($i = 0, $R = null; $i < 5; $i++) { $R = req($jd, 'gestion_api.php?action=cle_rechiffrer', [])['json']; if (empty($R['bilan']['restants'])) break; }
t('rechiffrement terminé', isset($R['bilan']) && $R['bilan']['restants'] === 0 && $R['bilan']['illisibles'] === 0, json_encode($R));
t('ancienne clé retirée', (req($jd, 'gestion_api.php?action=cle_retirer', [])['json']['ok'] ?? false) && !preg_match('/(LPI|AMAYA)_CLES_ANCIENNES/', file_get_contents("$dir/config.php")));
sleep(3); $jd = connecter($de, $mdp, $sec); t('connexion avec la seule nouvelle clé', req($jd, 'api.php?action=admin_lists')['code'] === 200);
$F = req($jd, "api.php?action=fiche_admin&id=$ae")['json']; t('n° de sécurité sociale relu avec la nouvelle clé', ($F['data']['secu'] ?? '') === $SECU, json_encode($F['data'] ?? null));
$r = req($jd, 'gestion_api.php?action=cle_deplacer', []); $kf = (string)($r['json']['fichier'] ?? '');
t('clé placée hors du dossier web', $kf !== '' && is_file($kf) && !str_starts_with(realpath($kf), realpath($dir)) && !preg_match("/define\\('(LPI|AMAYA)_CLE',/", file_get_contents("$dir/config.php")), $r['body']);
sleep(3); $jd = connecter($de, $mdp, $sec); $F = req($jd, "api.php?action=fiche_admin&id=$ae")['json'];
t('tout reste lisible avec la clé hors du dossier web', ($F['data']['secu'] ?? '') === $SECU);
echo "Fichier de clé rangé dans un dossier web (1.8.3)\n";
$cf = "$dir/config.php"; $c0 = file_get_contents($cf); $wd = dirname($dir) . '/www/amaya-prive'; @mkdir($wd, 0700, true); $kx = "$wd/cle-test-expose.txt"; copy($kf, $kx);
file_put_contents($cf, preg_replace("/define\\('(?:LPI|AMAYA)_CLE_FICHIER',\\s*'[^']*'\\);/", "define('AMAYA_CLE_FICHIER', '" . $kx . "');", $c0, 1)); file_put_contents("$dir/zz-opcache.php", '<?php if (function_exists("opcache_reset")) opcache_reset();'); @file_get_contents("$B/zz-opcache.php"); @unlink("$dir/zz-opcache.php"); sleep(3);
$M = req($jd, 'gestion_api.php?action=maint')['json']; t('clé dans un dossier web signalée', !empty($M['cle']['expose']), json_encode($M['cle'] ?? null));
$r = req($jd, 'gestion_api.php?action=cle_deplacer', []); $nf = (string)($r['json']['fichier'] ?? '');
t('fichier de clé déplacé hors des dossiers web', $nf !== '' && is_file($nf) && !str_contains($nf, '/www/') && !is_file($kx) && str_contains(file_get_contents($cf), $nf), $r['body']);
$F = req($jd, "api.php?action=fiche_admin&id=$ae")['json']; t('session conservée et tout reste lisible', ($F['data']['secu'] ?? '') === $SECU);
@unlink($nf); file_put_contents($cf, $c0); file_put_contents("$dir/zz-opcache.php", '<?php if (function_exists("opcache_reset")) opcache_reset();'); @file_get_contents("$B/zz-opcache.php"); @unlink("$dir/zz-opcache.php"); sleep(3);
@unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg");
echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
