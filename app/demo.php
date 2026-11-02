<?php
// Démo d'Amaya : le visiteur laisse ses coordonnées, reçoit un lien par e-mail et obtient sa propre agence fictive (7 jours, visible de lui seul).
require __DIR__ . '/lib.php';
if (!demo_actif()) { http_response_code(404); exit; }
require_once __DIR__ . '/demo_lib.php';
start_session(); header('Cache-Control: no-store');
$cle = instance_graine();
$telLien = fn(int $id) => $id . '-' . substr(hash_hmac('sha256', 'tel' . $id, $cle), 0, 24);
$lead = (int)($_SESSION['demo_lead'] ?? 0); $etat = ''; $err = '';

// Accès direct de l'éditeur : demo.php?cle=… (AMAYA_DEMO_CLE dans config.php, 12 caractères minimum). &neuf=1 remet son agence fictive à neuf.
if (isset($_GET['cle'])) {
  if (defined('AMAYA_DEMO_CLE') && strlen((string)AMAYA_DEMO_CLE) >= 12 && hash_equals((string)AMAYA_DEMO_CLE, (string)$_GET['cle'])) {
    $id = demo_editeur(); if (isset($_GET['neuf'])) demo_effacer($id, false);
    session_regenerate_id(true); $_SESSION['demo_p'] = 'd' . $id . '_'; $_SESSION['demo_lead'] = $id; header('Location: demo.php'); exit; }
  sleep(1); $err = 'Lien d\'accès incorrect.';
}
// Lien reçu par e-mail, ou QR code (même démo, sur le téléphone).
if (isset($_GET['acces'])) { $lead = demo_ouvrir((string)$_GET['acces']); if ($lead) { header('Location: demo.php'); exit; } $err = 'Ce lien n\'est plus valable (démo de plus de ' . DEMO_JOURS . ' jours). Redemandez un accès ci-dessous.'; }
if (isset($_GET['tel']) && preg_match('/^(\d+)-([a-f0-9]{24})$/', (string)$_GET['tel'], $m) && hash_equals($telLien((int)$m[1]), (string)$_GET['tel'])) {
  session_regenerate_id(true); $_SESSION['demo_p'] = 'd' . (int)$m[1] . '_'; $_SESSION['demo_lead'] = (int)$m[1]; header('Location: demo.php?role=equipe'); exit; }
// Préparation de l'agence fictive (appelée par la page, environ 15 secondes).
if (isset($_GET['prep'])) { header('Content-Type: application/json'); if (!$lead) { http_response_code(401); echo '{}'; exit; } try { demo_creer($lead); echo '{"ok":true}'; } catch (Throwable $e) { error_log('Amaya démo : ' . $e->getMessage()); http_response_code(500); echo '{"ok":false}'; } exit; }
$role = (string)($_GET['role'] ?? '');
if ($lead && demo_prete($lead) && in_array($role, ['direction', 'equipe', 'marque'], true)) { header('Location: ' . demo_entrer($role)); exit; }
if (isset($_GET['sortir'])) { unset($_SESSION['demo_p'], $_SESSION['demo_lead']); header('Location: demo.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (trim((string)($_POST['site'] ?? '')) !== '') { $etat = 'envoye'; } // champ piège : robots
  else { try { demo_inscrire($_POST); $etat = 'envoye'; } catch (RuntimeException $e) { $err = $e->getMessage(); } }
}
demo_nettoyer(2);
$pret = $lead && demo_prete($lead);
$v = fn($k) => h((string)($_POST[$k] ?? ''));
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Démo Amaya</title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<?= ag_style() ?>
<style>
body{min-height:100vh}
.dm{max-width:1080px;margin:0 auto;padding:32px 16px 64px}
.dm-h{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:36px}
.dm-h img{height:40px}
.dm-h a{color:var(--esp);font-weight:600;text-decoration:none}
.dm h1{font-size:clamp(32px,5vw,52px);line-height:1.05;margin:0 0 12px;max-width:18ch}
.dm-lead{font-size:18px;color:var(--muted);max-width:60ch;margin:0 0 28px}
.dm-g{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
@media (max-width:860px){.dm-g{grid-template-columns:1fr}}
.dm-c{background:#fff;border:1px solid var(--line);border-radius:22px;padding:22px;display:flex;flex-direction:column;gap:12px}
.dm-c.main{background:linear-gradient(in oklch 140deg,var(--terra),var(--pop));color:#fff;border:0}
.dm-c.main p{color:rgba(255,255,255,.9)}
.dm-c h2{font-size:22px;color:inherit;margin:0}
.dm-c p{margin:0;color:var(--muted);font-size:15px}
.dm-c ul{margin:0;padding-left:18px;font-size:14px;line-height:1.6}
.dm-c .btn{margin-top:auto;border-radius:14px;padding:13px 16px;font-weight:700}
.dm-c.main .btn{background:#fff;color:var(--esp);border-color:#fff}
.dm-qr{display:flex;gap:12px;align-items:center;font-size:13px;color:var(--muted)}
.dm-qr div{background:#fff;padding:6px;border-radius:10px;border:1px solid var(--line);flex:none}
.dm-f{margin-top:28px;font-size:13px;color:var(--muted)}
.dm-form{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:start}
@media (max-width:860px){.dm-form{grid-template-columns:1fr}}
.dm-form form{background:#fff;border:1px solid var(--line);border-radius:22px;padding:24px;display:flex;flex-direction:column;gap:14px}
.dm-form ul{margin:0;padding-left:20px;line-height:1.8;color:var(--esp)}
.dm-err{background:var(--bad-soft);color:var(--bad);border-radius:12px;padding:10px 14px;font-weight:600}
.dm-ok{background:#fff;border:1px solid var(--line);border-radius:22px;padding:28px;max-width:640px}
.hp{position:absolute;left:-5000px}
.dm-spin{width:42px;height:42px;border-radius:50%;border:4px solid var(--terra-soft);border-top-color:var(--terra);animation:sp 1s linear infinite}
@keyframes sp{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.dm-spin{animation:none}}
</style>
</head>
<body>
<main class="dm">
  <div class="dm-h"><img src="assets/logo-amaya.png?v=<?= @filemtime(__DIR__ . '/assets/logo-amaya.png') ?>" alt="Amaya"><?php if (defined('AMAYA_SITE') && AMAYA_SITE): ?><a href="<?= h(AMAYA_SITE) ?>">Retour au site</a><?php endif; ?></div>
<?php if ($etat === 'envoye'): ?>
  <div class="dm-ok"><h1 style="font-size:36px">Regardez vos e-mails.</h1>
    <p class="dm-lead" style="margin:12px 0 0">Nous venons de vous envoyer le lien de votre démo personnelle. Ouvrez-le pour entrer dans votre agence fictive. Pensez à regarder dans les indésirables si rien n'arrive d'ici quelques minutes.</p></div>
<?php elseif ($lead && !$pret): ?>
  <div class="dm-ok" id="prep"><div class="dm-spin" aria-hidden="true"></div><h1 style="font-size:36px;margin-top:18px">Nous préparons votre agence.</h1>
    <p class="dm-lead" style="margin:12px 0 0">Opérations, planning, contrats, comptes rendus et paye : tout est créé pour vous seul(e). Comptez une quinzaine de secondes.</p></div>
  <script>fetch("demo.php?prep=1", {credentials:"same-origin"}).then(r => { if (!r.ok) throw 0; location.reload(); }).catch(() => { document.getElementById("prep").innerHTML = "<h1 style='font-size:32px'>La préparation n'a pas abouti.</h1><p class='dm-lead'>Rechargez la page pour réessayer.</p>"; });</script>
<?php elseif ($pret):
  $url = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/demo.php?tel=' . $telLien($lead); ?>
  <h1>Votre agence de démonstration.</h1>
  <p class="dm-lead">Elle est à vous seul(e) pendant <?= DEMO_JOURS ?> jours : testez tout, saisissez ce que vous voulez, personne d'autre ne le voit. Choisissez votre point de vue, vous pourrez en changer à tout moment.</p>
  <div class="dm-g">
    <section class="dm-c main"><h2>Je dirige l'agence</h2><p>Le tableau de bord, les devis, le planning, les contrats, la paye et les virements.</p>
      <ul><li>Créer un devis et l'envoyer</li><li>Pourvoir un créneau en un clic</li><li>Préparer la paye du mois</li></ul>
      <a class="btn" href="demo.php?role=direction">Entrer comme dirigeant</a></section>
    <section class="dm-c"><h2>Je suis sur le terrain</h2><p>L'appli de l'intervenant(e), en animation, en hôtessariat ou en merchandising, pensée pour le téléphone.</p>
      <ul><li>Mes missions et ma prise de poste</li><li>Signer mon contrat</li><li>Rapport d'événement, compte rendu ou visite merchandising (scan des gencods)</li></ul>
      <div class="dm-qr"><div id="qr"></div><span>Scannez avec votre téléphone pour la vivre en vrai.</span></div>
      <a class="btn" href="demo.php?role=equipe">Ouvrir ici</a></section>
    <section class="dm-c"><h2>Je suis la marque</h2><p>L'espace client : rapports d'événement, d'animation et de merchandising, photos et devis à valider.</p>
      <ul><li>Voir les événements et les photos</li><li>Suivre visiteurs, contacts et ventes</li><li>Valider un devis en ligne</li></ul>
      <a class="btn" href="demo.php?role=marque">Entrer comme marque</a></section>
  </div>
  <p class="dm-f">Agence, personnes, marques et chiffres sont fictifs. Les e-mails, notifications et réglages de sécurité sont désactivés dans la démo. <a href="demo.php?sortir=1">Quitter la démo</a></p>
  <script src="assets/qrcode.js"></script>
  <script>try { const q = qrcode(0, "M"); q.addData(<?= json_encode($url) ?>); q.make(); document.getElementById("qr").innerHTML = q.createSvgTag({cellSize:3, margin:0}); } catch (e) {}</script>
<?php else: ?>
  <div class="dm-form">
    <div><h1>Votre démo Amaya, rien que pour vous.</h1>
      <p class="dm-lead">Laissez vos coordonnées : vous recevez par e-mail l'accès à votre propre agence fictive, déjà remplie. Elle est à vous seul(e) pendant <?= DEMO_JOURS ?> jours.</p>
      <ul><li>Le tableau de bord du dirigeant, le planning, les contrats, la paye</li><li>L'appli de vos intervenants, sur votre téléphone</li><li>L'espace de vos clients, les marques</li><li>Ce que vous saisissez reste privé</li></ul></div>
    <form method="post" action="demo.php" novalidate>
      <?php if ($err): ?><div class="dm-err" role="alert"><?= h($err) ?></div><?php endif; ?>
      <div class="row"><label>Prénom<input name="prenom" id="d-prenom" autocomplete="given-name" value="<?= $v('prenom') ?>" required></label><label>Nom<input name="nom" id="d-nom" autocomplete="family-name" value="<?= $v('nom') ?>" required></label></div>
      <label>Agence<input name="agence" id="d-agence" autocomplete="organization" value="<?= $v('agence') ?>" required></label>
      <div class="row"><label>E-mail professionnel<input name="email" id="d-email" type="email" autocomplete="email" value="<?= $v('email') ?>" required></label><label>Téléphone<input name="telephone" id="d-tel" type="tel" autocomplete="tel" value="<?= $v('telephone') ?>" required></label></div>
      <div class="row"><label>Taille de votre équipe<select name="equipe" id="d-equipe"><?php foreach (['' => 'Choisir…', '1-30' => 'Jusqu\'à 30 intervenants', '31-100' => '31 à 100', '101-300' => '101 à 300', '300+' => 'Plus de 300'] as $k => $l): ?><option value="<?= h($k) ?>" <?= ($_POST['equipe'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
        <label>Votre métier<select name="metier" id="d-metier"><?php foreach (['' => 'Choisir…', 'animation' => 'Animation commerciale', 'accueil' => 'Accueil et hôtessariat', 'les deux' => 'Les deux'] as $k => $l): ?><option value="<?= h($k) ?>" <?= ($_POST['metier'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label></div>
      <label class="hp" aria-hidden="true">Site<input name="site" tabindex="-1" autocomplete="off"></label>
      <label class="ck"><input type="checkbox" name="rgpd" id="d-rgpd" value="1" <?= !empty($_POST['rgpd']) ? 'checked' : '' ?>><span>J'accepte d'être recontacté(e) au sujet d'Amaya. Mes coordonnées ne sont jamais revendues.<?php if (defined('AMAYA_SITE') && AMAYA_SITE): ?> <a href="<?= h(rtrim((string)AMAYA_SITE, '/')) ?>/confidentialite.html" target="_blank" rel="noopener">Confidentialité</a><?php endif; ?></span></label>
      <button class="primary" type="submit">Recevoir mon accès</button>
      <p class="hint">Déjà inscrit(e) ? Ouvrez le lien reçu par e-mail.</p>
    </form>
  </div>
<?php endif; ?>
</main>
</body>
</html>
