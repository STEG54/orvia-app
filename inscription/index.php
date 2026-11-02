<?php
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
$v = ['conciergerie' => '', 'ville' => '', 'nom' => '', 'email' => '', 'telephone' => '', 'logements' => '1'];
$err = ''; $fait = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
  try {
    if (($_POST['site'] ?? '') !== '') { $fait = true; throw new Exception(''); }               // champ piège : rempli seulement par les robots
    if (trop(5, 3600)) throw new RuntimeException('Trop de demandes depuis cette connexion. Réessayez dans une heure.');
    foreach (['conciergerie' => 'le nom de votre conciergerie', 'nom' => 'votre nom', 'email' => 'votre adresse e-mail'] as $k => $l) if ($v[$k] === '') throw new RuntimeException('Indiquez ' . $l . '.');
    $v['email'] = mb_strtolower($v['email']);
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide.');
    if ($v['telephone'] !== '' && !preg_match('/^\+?[0-9 .()-]{8,20}$/', $v['telephone'])) throw new RuntimeException('Numéro de téléphone invalide.');
    $pw = (string)($_POST['mdp'] ?? '');
    if ($e = mdp_probleme($pw, $v['email'])) throw new RuntimeException($e);
    if ($pw !== (string)($_POST['mdp2'] ?? '')) throw new RuntimeException('Les deux mots de passe ne sont pas identiques.');
    if (empty($_POST['cgu'])) throw new RuntimeException('Acceptez les conditions générales pour continuer.');
    $st = db()->prepare("SELECT COUNT(*) FROM inscriptions WHERE email = ? AND statut IN ('confirme','en_cours','pret')"); $st->execute([$v['email']]);
    if ((int)$st->fetchColumn()) throw new RuntimeException('Un espace existe déjà pour cette adresse. Connectez-vous depuis le lien reçu par e-mail, ou contactez-nous.');
    $jeton = bin2hex(random_bytes(24));
    db()->prepare("INSERT INTO inscriptions (conciergerie, ville, nom, email, telephone, logements, pass_hash, jeton_hash, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
      ->execute([mb_substr($v['conciergerie'], 0, 120), mb_substr($v['ville'], 0, 80), mb_substr($v['nom'], 0, 120), $v['email'], mb_substr($v['telephone'], 0, 30), max(1, min(5000, (int)$v['logements'])),
        password_hash($pw, PASSWORD_DEFAULT), hash('sha256', $jeton), ip(), date('Y-m-d H:i:s')]);
    envoyer($v['email'], 'Confirmez votre adresse pour créer votre espace Keylia',
      "Bonjour " . $v['nom'] . ",\n\nPour créer l'espace Keylia de " . $v['conciergerie'] . ", confirmez votre adresse en ouvrant ce lien (valable 48 heures) :\n\n"
      . rtrim(INS_URL, '/') . '/confirmer.php?t=' . $jeton . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez ce message.\n\nKeylia\nL'outil des professionnels de la conciergerie");
    $fait = true;
  } catch (RuntimeException $e) { $err = $e->getMessage(); } catch (Exception $e) {}
}
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title>Créer votre espace · Keylia</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Marcellus&family=Montserrat:wght@400;500&family=Jost:wght@400;500&display=swap">
<style>
:root{--ardoise:#43515C;--creme:#EAE6DA;--ink:#1C1F1D;--grey:#545955;--line:#DCD9D1;--bad:#8E3B2F;color-scheme:light}
*{box-sizing:border-box}body{margin:0;background:#F6F4EF;color:var(--ink);font:400 17px/1.6 "Jost",system-ui,sans-serif}
header{background:var(--creme);border-bottom:1px solid #D9D4C6;padding:14px 20px}
.logo{display:inline-block;color:var(--ardoise);text-decoration:none}.logo svg{height:46px;width:auto;display:block}
main{max-width:620px;margin:0 auto;padding:40px 20px 72px}
h1{font:400 clamp(2rem,5vw,2.6rem)/1.15 "Marcellus",Georgia,serif;color:var(--ardoise);margin:0 0 12px}
p.lead{color:var(--grey);margin:0 0 28px}
form{background:#fff;border:1px solid var(--line);padding:28px;display:grid;gap:16px}
label{display:grid;gap:6px;font-weight:500;font-size:.95rem}label small{font-weight:400;color:var(--grey)}
input{font:inherit;padding:12px 14px;border:1px solid var(--line);background:#fff;width:100%}input:focus{outline:2px solid var(--ardoise);outline-offset:1px}
.two{display:grid;grid-template-columns:1fr 1fr;gap:16px}@media(max-width:560px){.two{grid-template-columns:1fr}}
.chk{display:flex;gap:10px;align-items:flex-start;font-weight:400}.chk input{width:auto;margin-top:5px}
button{font:500 1rem "Jost",sans-serif;background:var(--ardoise);color:#fff;border:0;padding:15px 22px;cursor:pointer}
.err{background:#F6E1DC;color:var(--bad);border:1px solid #EBC8C0;padding:12px 14px}
.ok{background:#fff;border:1px solid var(--line);padding:28px}
.piege{position:absolute;left:-5000px}
.note{color:var(--grey);font-size:.9rem;margin-top:18px}
</style></head><body>
<header><a class="logo" href="<?= h(dirname(INS_URL)) ?>" aria-label="Keylia"><svg viewBox="0 0 300 72"><text x="0" y="46" textLength="300" lengthAdjust="spacing" font-family="Montserrat, Jost, sans-serif" font-weight="500" font-size="56" fill="currentColor">KEYLIA</text><text x="0" y="68" textLength="300" lengthAdjust="spacing" font-family="Montserrat, Jost, sans-serif" font-size="13.2" fill="currentColor">L'outil des professionnels de la conciergerie</text></svg></a></header>
<main>
<?php if ($fait): ?>
  <h1>Vérifiez votre boîte e-mail.</h1>
  <div class="ok"><p>Nous venons de vous envoyer un lien de confirmation. Dès que vous l'ouvrez, votre espace Keylia est créé et vous recevez son adresse.</p>
  <p class="note">Rien reçu après quelques minutes ? Regardez dans les courriers indésirables, ou recommencez avec une autre adresse.</p></div>
<?php else: ?>
  <h1>Créer votre espace Keylia.</h1>
  <p class="lead">Gratuit pour un logement, sans carte bancaire. Vous pourrez ajouter vos logements, vos équipes et vos propriétaires dès la confirmation de votre adresse.</p>
  <?php if ($err): ?><p class="err" role="alert"><?= h($err) ?></p><?php endif; ?>
  <form method="post" autocomplete="on" novalidate>
    <label>Nom de votre conciergerie<input name="conciergerie" required maxlength="120" value="<?= h($v['conciergerie']) ?>"></label>
    <div class="two"><label>Ville<input name="ville" maxlength="80" value="<?= h($v['ville']) ?>" placeholder="Marrakech"></label>
      <label>Nombre de logements gérés<input name="logements" type="number" min="1" max="5000" value="<?= h($v['logements']) ?>"></label></div>
    <div class="two"><label>Votre nom<input name="nom" required maxlength="120" autocomplete="name" value="<?= h($v['nom']) ?>"></label>
      <label>Téléphone WhatsApp <small>facultatif</small><input name="telephone" type="tel" autocomplete="tel" value="<?= h($v['telephone']) ?>" placeholder="+212 6 …"></label></div>
    <label>Adresse e-mail<input name="email" type="email" required autocomplete="email" value="<?= h($v['email']) ?>"></label>
    <div class="two"><label>Mot de passe <small>12 caractères au moins</small><input name="mdp" type="password" required minlength="12" autocomplete="new-password"></label>
      <label>Confirmation<input name="mdp2" type="password" required minlength="12" autocomplete="new-password"></label></div>
    <input class="piege" name="site" tabindex="-1" autocomplete="off" aria-hidden="true">
    <label class="chk"><input type="checkbox" name="cgu" value="1"> J'accepte les conditions générales et la politique de confidentialité de Keylia.</label>
    <button type="submit">Créer mon espace</button>
  </form>
  <p class="note">Vous préférez une présentation avec nos équipes ? Demandez une démonstration depuis notre site.</p>
<?php endif; ?>
</main></body></html>
