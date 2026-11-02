<?php require __DIR__ . '/lib.php';
// Keylia : information de l'équipe terrain sur ses données (loi 09-08). Texte à faire valider par le conseil de la conciergerie avant mise en service.
$adr = ''; try { $adr = trim(str_replace(["\r", "\n"], [' ', ', '], (string)db()->query("SELECT valeur FROM g_reglages WHERE cle = 'adresse'")->fetchColumn())); } catch (Throwable $e) {}
header('Cache-Control: no-store'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Vos données personnelles · <?= h(AG_COURT) ?></title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
:root{--ardoise:#43515C;--creme:#EAE6DA;--fond:#F4F1EA;--ink:#2C2F34;--line:#E6E1D6;--muted:#626A70}
*{box-sizing:border-box}body{margin:0;background:var(--fond);color:var(--ink);font:16px/1.6 "Jost",system-ui,sans-serif}
header{background:var(--ardoise);color:#fff;padding:26px 20px 24px}
header .k{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--creme)}
header h1{font-family:"Marcellus",Georgia,serif;font-weight:400;font-size:32px;line-height:1.15;margin:6px 0 0}
main{max-width:720px;margin:0 auto;padding:16px}
section{background:#fff;border-radius:14px;padding:16px 18px;margin-bottom:10px}
h2{font-size:13px;letter-spacing:.1em;text-transform:uppercase;color:var(--ardoise);margin:0 0 6px}
p{margin:0 0 8px}ul{margin:0;padding-left:18px}li{margin-bottom:4px}
a{color:var(--ardoise);font-weight:500}
.back{display:inline-block;margin:6px 0 14px}
footer{text-align:center;font-size:12px;color:var(--muted);padding:6px 0 30px}
</style>
</head>
<body>
<header><div class="k"><?= h(AG_NOM) ?></div><h1>Vos données personnelles</h1></header>
<main>
<a class="back" href="./">‹ Retour à l'application</a>
<section><h2>Qui est responsable</h2>
<p><?= h(AG_NOM) ?><?= $adr !== '' ? ', ' . h($adr) : '' ?>, est responsable des données collectées par l'application de son équipe terrain.</p>
<p>Pour toute question sur vos données : <a href="mailto:<?= h(AG_EMAIL) ?>"><?= h(AG_EMAIL) ?></a>.</p></section>
<section><h2>Les données collectées</h2><ul>
<li>Votre identité : prénom, nom, numéro de portable et adresse e-mail.</li>
<li>Vos missions : logements, dates, heures, pièces validées, consignes et signalements.</li>
<li>Les photos de vos interventions : les pièces du logement après votre passage.</li>
<li>Votre position, relevée seulement quand vous appuyez sur « J'arrive » et sur « Terminer la mission », pour confirmer votre passage au logement.</li>
<li>Les informations techniques nécessaires à la connexion : cookie de session, date et heure des envois.</li></ul></section>
<section><h2>Pourquoi</h2><ul>
<li>Vous confier vos missions et vous donner les accès des logements.</li>
<li>Montrer aux voyageurs et aux propriétaires que le logement est prêt.</li>
<li>Suivre la qualité des interventions.</li></ul>
<p>Vos données ne sont jamais vendues ni utilisées à des fins publicitaires.</p></section>
<section><h2>Qui les reçoit</h2><ul>
<li>L'équipe de direction de <?= h(AG_NOM) ?>.</li>
<li>Les propriétaires des logements concernés : ils voient les photos des interventions dans leur logement, avec votre accord donné à la première connexion. Vous pouvez retirer cet accord à tout moment en écrivant à l'adresse ci-dessus.</li>
<li>Le prestataire qui héberge l'application pour <?= h(AG_NOM) ?>.</li></ul></section>
<section><h2>Les photos</h2>
<p>Photographiez les pièces, jamais les personnes ni les affaires personnelles des voyageurs.</p></section>
<section><h2>Vos droits</h2>
<p>Conformément à la loi 09-08, vous pouvez accéder à vos données, les faire corriger et vous opposer à leur utilisation pour un motif légitime. Écrivez à <a href="mailto:<?= h(AG_EMAIL) ?>"><?= h(AG_EMAIL) ?></a>.</p>
<p>Vous pouvez aussi saisir la Commission nationale de contrôle de la protection des données à caractère personnel (CNDP).</p></section>
<section><h2>Sécurité</h2>
<p>L'accès à l'application passe par le code de la conciergerie, un code de confirmation envoyé par e-mail et une connexion chiffrée (HTTPS). L'administration est protégée par un mot de passe et une double authentification.</p></section>
<footer>Dernière mise à jour : novembre 2026 · <?= h(AG_NOM) ?></footer>
</main>
</body>
</html>
