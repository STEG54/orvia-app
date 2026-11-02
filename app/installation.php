<?php
// Amaya : assistant d'installation pour une nouvelle agence. Crée config.php, la base de données et l'agence. Inutilisable une fois l'installation faite.
header('Cache-Control: no-store'); header('X-Frame-Options: DENY');
$dir = __DIR__; $deja = is_file("$dir/config.php");
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$err = ''; $ok = false; $v = $_POST + ['db_host' => '', 'db_name' => '', 'db_user' => '', 'ag_nom' => '', 'ag_court' => '', 'ag_email' => '', 'ag_couleur' => '#43515C', 'tz' => 'Europe/Paris', 'ag_matricule' => 'AG',
  'd1_nom' => '', 'd1_email' => '', 'd2_nom' => '', 'd2_email' => '', 'mail_from' => ''];
$TZ = ['Africa/Casablanca' => 'Maroc', 'Europe/Paris' => 'France métropolitaine', 'Europe/Brussels' => 'Belgique', 'Europe/Zurich' => 'Suisse', 'Europe/Luxembourg' => 'Luxembourg'];
if (!$deja && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $t = fn($k, $max = 160) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    foreach (['db_host' => 'le serveur de la base', 'db_name' => 'le nom de la base', 'db_user' => 'l\'utilisateur de la base', 'ag_nom' => 'la raison sociale', 'ag_email' => 'l\'e-mail de l\'agence', 'd1_nom' => 'le nom du dirigeant', 'd1_email' => 'l\'e-mail du dirigeant'] as $k => $l)
      if ($t($k) === '') throw new RuntimeException('Indiquez ' . $l . '.');
    foreach (['ag_email', 'd1_email'] as $k) if (!filter_var($t($k), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide : ' . $t($k));
    if ($t('d2_email') !== '' && !filter_var($t('d2_email'), FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide : ' . $t('d2_email'));
    $pw = (string)($_POST['admin_pw'] ?? ''); if (mb_strlen($pw) < 12) throw new RuntimeException('Mot de passe de secours : 12 caractères au moins.');
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $t('ag_couleur'))) throw new RuntimeException('Couleur invalide (ex. #43515C).');
    if (!isset($TZ[$t('tz')])) throw new RuntimeException('Fuseau horaire inconnu.');
    // Connexion et création des tables
    $pdo = new PDO('mysql:host=' . $t('db_host') . ';dbname=' . $t('db_name') . ';charset=utf8mb4', $t('db_user'), (string)($_POST['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'reglages'")->fetchColumn()
      && (int)$pdo->query("SELECT COUNT(*) FROM reglages WHERE cle = 'code_agence'")->fetchColumn()) throw new RuntimeException('Cette base contient déjà une installation : utilisez une base vide pour une nouvelle agence.');
    foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', (string)file_get_contents("$dir/install.sql"))))) as $q) $pdo->exec($q);
    // config.php
    $ex = fn($s) => var_export((string)$s, true);
    $dir_ = [$t('d1_email') => $t('d1_nom')]; if ($t('d2_email') !== '') $dir_[mb_strtolower($t('d2_email'))] = $t('d2_nom') ?: $t('d2_email');
    $dir_ = array_combine(array_map('mb_strtolower', array_keys($dir_)), array_values($dir_));
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
    $base = $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $court = $t('ag_court', 40) ?: $t('ag_nom', 40);
    $cfg = "<?php\n// Amaya : réglages de l'agence " . str_replace(["\n", "?>"], ' ', $t('ag_nom')) . ", écrits par l'assistant d'installation le " . date('d/m/Y') . ".\n// Ce fichier n'est jamais accessible depuis le navigateur (voir .htaccess). Gardez-en une copie en lieu sûr.\n\n"
      . "define('DB_HOST', " . $ex($t('db_host')) . ");\ndefine('DB_NAME', " . $ex($t('db_name')) . ");\ndefine('DB_USER', " . $ex($t('db_user')) . ");\ndefine('DB_PASS', " . $ex((string)($_POST['db_pass'] ?? '')) . ");\n\n"
      . "// Mot de passe de secours (empreinte seulement) : première connexion d'un dirigeant et procédure de secours (secours.php)\ndefine('ADMIN_PASSWORD_HASH', " . $ex(password_hash($pw, PASSWORD_DEFAULT)) . ");\n\n"
      . "define('BASE_URL', " . $ex($base) . ");\n"
      . "define('MAIL_FROM', " . $ex($t('mail_from') ?: $t('ag_email')) . ");\ndefine('MAIL_FROM_NAME', " . $ex($t('ag_nom')) . ");\ndefine('MAIL_TO_DEFAULT', " . $ex($t('d1_email')) . ");\n\n"
      . "// Agence\ndefine('AG_NOM', " . $ex($t('ag_nom')) . ");\ndefine('AG_COURT', " . $ex($court) . ");\ndefine('AG_EMAIL', " . $ex($t('ag_email')) . ");\n"
      . "define('AG_COULEUR', " . $ex($t('ag_couleur')) . ");\ndefine('AG_MATRICULE', " . $ex(strtoupper(preg_replace('/[^A-Za-z]/', '', $t('ag_matricule', 6))) ?: 'AG') . ");\n"
      . "// Dirigeants : tous les droits, seuls à gérer les administrateurs, la paye, les contrats et la facturation.\nconst ADMINS_PRINCIPAUX = " . var_export($dir_, true) . ";\n"
      . "// Testeurs internes : voient leur planning et reçoivent leurs e-mails même avant l'ouverture du planning.\nconst AG_TESTEURS = " . var_export(array_keys($dir_), true) . ";\n\n"
      . "// Clé de chiffrement des documents (pièces d'identité, IBAN, n° de sécurité sociale). NE JAMAIS LA PERDRE NI LA CHANGER : sans elle, les documents sont illisibles.\ndefine('AMAYA_CLE', " . $ex(base64_encode(random_bytes(32))) . ");\n\n"
      . "// Facultatif : clé API Claude (console.anthropic.com) pour la synthèse rédigée des analyses.\ndefine('ANTHROPIC_API_KEY', '');\ndefine('ANTHROPIC_MODEL', 'claude-sonnet-5-5');\n\n"
      . "date_default_timezone_set(" . $ex($t('tz')) . ");\n";
    if (file_put_contents("$dir/config.php", $cfg) === false) throw new RuntimeException('Impossible d\'écrire config.php : vérifiez les droits du dossier.');
    @chmod("$dir/config.php", 0640);
    // Base à jour (migrations) et identité de l'agence dans les réglages
    require "$dir/gestion_lib.php"; db(); g_schema();
    $st = db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES (?, ?)");
    foreach (['societe' => $t('ag_nom'), 'email' => $t('ag_email'), 'signataire' => $t('d1_nom'), 'ct_representant' => $t('d1_nom'), 'contact' => $t('d1_nom'), 'signature' => $t('d1_nom') . "\n" . $t('ag_nom'), 'notif_emails' => implode(', ', array_keys($dir_))] as $k => $x) $st->execute([$k, $x]);
    db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('code_agence', ?)")->execute([strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)iconv('UTF-8', 'ASCII//TRANSLIT', $court)), 0, 4) ?: 'CODE') . date('Y')]);
    $ok = true;
  } catch (Throwable $e) { $err = $e->getMessage(); if (is_file("$dir/config.php") && !$ok) @unlink("$dir/config.php"); }
}
?><!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Installation · <?= h(PRODUIT_NOM) ?></title>
<link rel="icon" href="assets/favicon-32.png">
<style>:root{--c:#6A4BFF;--t:#1A1512;--m:#7A6D62;--l:#E3D9CD;--s:#F7F4EF}*{box-sizing:border-box}body{margin:0;background:var(--s);color:var(--t);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.w{max-width:760px;margin:0 auto;padding:28px 16px 60px}h1{font-size:30px;margin:0 0 4px}h2{font-size:13px;letter-spacing:.14em;text-transform:uppercase;color:var(--c);margin:26px 0 10px}
.card{background:#fff;border:1px solid var(--l);border-radius:16px;padding:20px;margin-top:16px}label{display:block;font-weight:600;font-size:14px;margin:10px 0 4px}
input,select{width:100%;padding:11px 12px;border:1px solid var(--l);border-radius:10px;font:inherit;background:#FFFDFA}.r{display:grid;grid-template-columns:1fr 1fr;gap:12px}@media(max-width:600px){.r{grid-template-columns:1fr}}
.h{color:var(--m);font-size:13px;font-weight:400}.e{background:#FBE3DF;border:1px solid #E9B4AA;color:#8E2A1F;padding:12px 14px;border-radius:12px;margin-top:16px}.ok{background:#E3F1E7;border:1px solid #9CCBAA;padding:14px;border-radius:12px;margin-top:16px}
button{margin-top:20px;background:var(--c);color:#fff;border:0;border-radius:12px;padding:14px 22px;font:inherit;font-weight:600;cursor:pointer;width:100%}a{color:var(--c)}</style></head><body><div class="w">
<h1><?= h(PRODUIT_NOM) ?></h1><div class="h" style="font-size:15px">Installation pour une nouvelle conciergerie</div>
<?php if ($ok): ?>
  <div class="ok"><b>Installation terminée.</b><br>Connectez-vous à l'administration avec l'e-mail d'un dirigeant et le mot de passe de secours : <a href="admin.php">ouvrir l'administration</a>.<br>
  Ensuite, dans Administration › Réglages : adresse, mentions légales, banque, logo, contrats de travail.</div>
  <div class="card">Par sécurité, <b>supprimez le fichier installation.php</b> du serveur (il ne peut plus servir : il refuse de fonctionner tant que config.php existe) et <b>gardez une copie de config.php</b> en lieu sûr : il contient la clé qui protège les documents.</div>
<?php elseif ($deja): ?>
  <div class="card">Cette installation est déjà faite. <a href="admin.php">Ouvrir l'administration</a>. Pour une nouvelle agence, copiez <?= h(PRODUIT_NOM) ?> dans un autre dossier, avec une autre base de données.</div>
<?php else: ?>
  <?php if ($err): ?><div class="e"><?= $h($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off"><div class="card">
    <h2 style="margin-top:0">1. Base de données</h2><div class="h">Créée chez l'hébergeur (OVH : Hébergements › Bases de données). Une base vide par agence.</div>
    <div class="r"><div><label>Serveur</label><input name="db_host" value="<?= $h($v['db_host']) ?>" placeholder="xxxxxx.mysql.db"></div><div><label>Nom de la base</label><input name="db_name" value="<?= $h($v['db_name']) ?>"></div></div>
    <div class="r"><div><label>Utilisateur</label><input name="db_user" value="<?= $h($v['db_user']) ?>"></div><div><label>Mot de passe</label><input type="password" name="db_pass"></div></div>
    <h2>2. Agence</h2>
    <div class="r"><div><label>Raison sociale</label><input name="ag_nom" value="<?= $h($v['ag_nom']) ?>" placeholder="Ex. AGENCE ÉCLAT SAS"></div><div><label>Nom court <span class="h">affiché dans l'appli</span></label><input name="ag_court" value="<?= $h($v['ag_court']) ?>" placeholder="Ex. Éclat"></div></div>
    <div class="r"><div><label>E-mail de l'agence</label><input name="ag_email" value="<?= $h($v['ag_email']) ?>" placeholder="contact@agence.fr"></div><div><label>Adresse d'envoi des e-mails <span class="h">même domaine que le site</span></label><input name="mail_from" value="<?= $h($v['mail_from']) ?>" placeholder="ne-pas-repondre@agence.fr"></div></div>
    <div class="r"><div><label>Couleur principale</label><input type="color" name="ag_couleur" value="<?= $h($v['ag_couleur']) ?>" style="height:46px;padding:4px"></div><div><label>Fuseau horaire du siège</label><select name="tz"><?php foreach ($TZ as $k => $l) echo '<option value="' . $h($k) . '"' . ($v['tz'] === $k ? ' selected' : '') . '>' . $h($l) . '</option>'; ?></select></div></div>
    <div class="r"><div><label>Préfixe des matricules</label><input name="ag_matricule" value="<?= $h($v['ag_matricule']) ?>" maxlength="6" placeholder="Ex. ECL"><div class="h">Ex. ECL-0001</div></div><div></div></div>
    <h2>3. Dirigeants</h2><div class="h">Tous les droits : paye, contrats, facturation, administrateurs. Ils se connectent avec leur e-mail.</div>
    <div class="r"><div><label>Dirigeant(e) : nom</label><input name="d1_nom" value="<?= $h($v['d1_nom']) ?>"></div><div><label>E-mail</label><input name="d1_email" value="<?= $h($v['d1_email']) ?>"></div></div>
    <div class="r"><div><label>Second(e) dirigeant(e) <span class="h">facultatif</span></label><input name="d2_nom" value="<?= $h($v['d2_nom']) ?>"></div><div><label>E-mail</label><input name="d2_email" value="<?= $h($v['d2_email']) ?>"></div></div>
    <label>Mot de passe de secours de l'administration <span class="h">12 caractères au moins, à garder précieusement</span></label><input type="password" name="admin_pw" minlength="12">
    <button>Installer</button></div></form>
<?php endif; ?>
</div></body></html>
