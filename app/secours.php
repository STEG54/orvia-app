<?php
// Procédure de secours de l'administration, hors de l'appli : réservée à qui a accès à l'hébergement (FTP).
// 1. Déposer par FTP un fichier nommé secours.txt (contenu libre) dans le dossier prive/ de l'installation.
// 2. Ouvrir cette page dans les 2 heures, saisir l'e-mail d'un(e) dirigeant(e) et le mot de passe de secours.
// Effet : le mot de passe personnel et la double authentification de ce compte sont réinitialisés ; à la connexion suivante,
// la personne choisit un nouveau mot de passe et réactive la double authentification. Le fichier est supprimé, l'opération journalisée.
require __DIR__ . '/lib.php';
if (!function_exists('fail')) { function fail(string $m, int $c = 400) { throw new RuntimeException($m); } } // utilisé par limite()
start_session();
header('Cache-Control: no-store'); header('X-Frame-Options: DENY'); header('X-Robots-Tag: noindex');
$actif = secours_actif(); $msg = ''; $err = '';
if ($actif && $_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    limite('secours', 5, 3600);
    $email = mb_strtolower(trim((string)($_POST['email'] ?? ''))); $pw = (string)($_POST['password'] ?? '');
    if (!isset(ADMINS_PRINCIPAUX[$email]) || !mdp_secours_ok($pw)) { sleep(2); throw new RuntimeException('E-mail de dirigeant ou mot de passe de secours incorrect.'); }
    config_mdp_migrer(); tf_schema();
    $st = db()->prepare("SELECT id FROM admins WHERE email = ?"); $st->execute([$email]); $id = (int)$st->fetchColumn();
    if ($id) db()->prepare("UPDATE admins SET pass_hash = ?, actif = 1, droits = 'tout', totp_secret = '', totp_actif = 0, totp_secours = NULL, totp_dernier = 0 WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
    else db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,'tout',1,?)")->execute([ADMINS_PRINCIPAUX[$email], $email, password_hash($pw, PASSWORD_DEFAULT), now()]);
    @unlink(__DIR__ . '/prive/secours.txt'); doc_journal('secours', $email);
    $msg = 'Compte rétabli. Connectez-vous à l\'administration avec cet e-mail et le mot de passe de secours : vous choisirez aussitôt un nouveau mot de passe et réactiverez la double authentification.';
    $actif = false;
  } catch (Throwable $e) { $err = $e->getMessage(); }
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Procédure de secours · <?= h(AG_COURT) ?></title>
<style>:root{--c:#6A4BFF;--t:#1A1512;--m:#6E6560;--l:#E3D9CD;--s:#F7F4EF}*{box-sizing:border-box}body{margin:0;background:var(--s);color:var(--t);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
.w{max-width:620px;margin:0 auto;padding:28px 16px 60px}h1{font-size:28px;margin:0 0 8px}.card{background:#fff;border:1px solid var(--l);border-radius:16px;padding:20px;margin-top:16px}
label{display:block;font-weight:600;font-size:14px;margin:12px 0 4px}input{width:100%;padding:11px 12px;border:1px solid var(--l);border-radius:10px;font:inherit}
button{margin-top:16px;padding:12px 18px;border:0;border-radius:10px;background:var(--c);color:#fff;font:inherit;font-weight:700;cursor:pointer}
.m{color:var(--m)}.err{background:#FFF0F0;color:#B42318;border-radius:10px;padding:10px 12px;margin-top:12px}.ok{background:#E3F8F0;color:#0C6B4E;border-radius:10px;padding:10px 12px;margin-top:12px}
ol{padding-left:20px}code{background:#F1ECE5;padding:1px 5px;border-radius:5px}</style></head>
<body><main class="w"><h1>Procédure de secours</h1>
<p class="m">Pour un(e) dirigeant(e) qui ne peut plus se connecter (mot de passe oublié, téléphone et codes de secours perdus).</p>
<?php if ($msg): ?><div class="ok"><?= h($msg) ?></div><p><a href="admin.php">Aller à l'administration</a></p>
<?php elseif (!$actif): ?><div class="card"><ol>
  <li>Avec FileZilla (ou l'espace client de l'hébergeur), déposez un fichier nommé <code>secours.txt</code>, contenu libre, dans le dossier <code>prive</code> de l'installation.</li>
  <li>Rechargez cette page dans les 2 heures.</li>
  <li>Saisissez l'e-mail d'un(e) dirigeant(e) et le mot de passe de secours choisi à l'installation.</li></ol>
  <p class="m">Le fichier prouve que vous avez accès à l'hébergement ; il est supprimé dès que le compte est rétabli.</p></div>
<?php else: ?><form class="card" method="post">
  <?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>
  <label>E-mail du dirigeant<input type="email" name="email" autocomplete="username" required></label>
  <label>Mot de passe de secours<input type="password" name="password" autocomplete="off" required></label>
  <button>Rétablir l'accès</button>
  <p class="m">Le mot de passe personnel et la double authentification de ce compte seront réinitialisés.</p></form>
<?php endif; ?></main></body></html>
