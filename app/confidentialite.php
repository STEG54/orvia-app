<?php require __DIR__ . '/lib.php';
$adr = ''; try { $adr = trim(str_replace(["\r", "\n"], [' ', ', '], (string)db()->query("SELECT valeur FROM g_reglages WHERE cle = 'adresse'")->fetchColumn())); } catch (Throwable $e) {}
const CONSERVATION_ANS_TXT = 3; const RGPD_CONTACT_MAIL = AG_EMAIL; header('Cache-Control: no-store'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A1512">
<title>Vos données personnelles · <?= h(AG_COURT) ?></title>
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<style>
:root{--esp:#1A1512;--terra:<?= h(ag_couleur()) ?>;--sand:#F4EEE7;--line:#E3D9CD;--muted:#7A6D62}
*{box-sizing:border-box}body{margin:0;background:var(--sand);color:var(--esp);font-family:"Figtree",system-ui,sans-serif;font-size:15.5px;line-height:1.6}
header{background:var(--esp);color:#fff;padding:28px 20px 24px;border-bottom:3px solid var(--terra)}
header .k{font-size:11px;letter-spacing:.16em;text-transform:uppercase;color:#E9C9B5}
header h1{font-family:"Bricolage Grotesque",system-ui,sans-serif;font-weight:700;letter-spacing:-.02em;font-size:34px;line-height:1.1;margin:6px 0 0}
main{max-width:720px;margin:0 auto;padding:16px}
section{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px 18px;margin-bottom:10px}
h2{font-size:12px;letter-spacing:.12em;text-transform:uppercase;color:var(--terra);margin:0 0 6px}
p{margin:0 0 8px}ul{margin:0;padding-left:18px}li{margin-bottom:4px}
a{color:var(--terra);font-weight:600}
.back{display:inline-block;margin:6px 0 14px;font-weight:600}
footer{text-align:center;font-size:12px;color:var(--muted);padding:6px 0 30px}
</style>
<?= ag_style() ?>
</head>
<body>
<header><div class="k"><?= h(AG_COURT) ?></div><h1>Vos données personnelles</h1></header>
<main>
<a class="back" href="./">‹ Retour à l'application</a>
<section><h2>Qui est responsable</h2>
<p><?= h(AG_NOM) ?><?= $adr !== '' ? ', ' . h($adr) . ',' : '' ?> est responsable des données collectées par l'application <?= h(AG_COURT) ?>.</p>
<p>Contact pour toute question sur vos données : <a href="mailto:<?= RGPD_CONTACT_MAIL ?>"><?= RGPD_CONTACT_MAIL ?></a></p></section>
<section><h2>Les données collectées</h2><ul>
<li>Votre identité et votre fiche : prénom, nom, coordonnées, adresse, date et lieu de naissance, n° de sécurité sociale, IBAN, statut (salarié ou auto-entrepreneur et SIRET), langues, expériences, tailles, photos de profil et book, pièces justificatives.</li>
<li>Vos missions et comptes rendus : dates, lieux, heures, chiffres, observations et commentaires, y compris les comptes rendus d'événement et les visites de merchandising (relevés en rayon, photos avant et après).</li>
<li>Votre prise de poste : l'heure, la position de votre téléphone à ce moment-là (une seule fois par mission, pour vérifier que vous êtes sur place), l'adresse de connexion et le type d'appareil.</li>
<li>Vos photos de mission : un selfie sur place et des photos du point de vente ou de l'événement.</li>
<li>Vos signatures : contrats et relevés d'heures signés dans l'appli, avec l'heure, l'adresse de connexion et le type d'appareil (preuve de signature).</li>
<li>Les informations techniques nécessaires au fonctionnement : cookies de connexion, date d'envoi.</li></ul></section>
<section><h2>Pourquoi</h2><ul>
<li>Organiser vos missions (planning, contrats, paie) et en rendre compte aux clients pour lesquels vous intervenez (exécution de votre mission et intérêt légitime de <?= h(AG_NOM) ?>).</li>
<li>Justifier votre présence auprès du client, grâce au selfie et à la position relevée à la prise de poste.</li>
<li>Vous transmettre vos comptes rendus et vos supports de formation.</li>
<li>La transmission de votre selfie aux marques repose sur votre accord, que vous pouvez retirer à tout moment en écrivant à l'adresse ci-dessus.</li></ul>
<p>Vos données ne sont jamais vendues ni utilisées à des fins publicitaires.</p></section>
<section><h2>Qui les reçoit</h2><ul>
<li>L'équipe de <?= h(AG_NOM) ?>.</li>
<li>Les clients pour lesquels vous intervenez : ils reçoivent les comptes rendus, les chiffres et les photos qui les concernent.</li>
<li>Notre hébergeur, qui stocke les données et leurs sauvegardes sur des serveurs situés dans l'Union européenne. N° de sécurité sociale, IBAN, pièces d'identité et contrats signés sont chiffrés.</li></ul></section>
<section><h2>Combien de temps</h2><ul>
<li>Comptes rendus d'animation et leurs photos : <?= CONSERVATION_ANS_TXT ?> ans après la date de la mission, puis suppression automatique.</li>
<li>Comptes rendus d'événement, visites de merchandising et prises de poste : pendant votre collaboration avec <?= h(AG_NOM) ?>, puis suppression sur demande.</li>
<li>Contrats, relevés d'heures et documents de paie : pendant les durées imposées par le droit du travail et le droit social.</li>
<li>Brouillons non envoyés : supprimés après 60 jours.</li>
<li>Votre profil : tant que vous travaillez avec <?= h(AG_NOM) ?>, ou jusqu'à votre demande d'effacement.</li></ul></section>
<section><h2>Les photos</h2><ul>
<li>Le selfie sert uniquement à attester votre présence et à illustrer les comptes rendus envoyés aux clients.</li>
<li>Les photos transmises aux marques sont choisies une à une par <?= h(AG_NOM) ?>.</li>
<li>Les photos du point de vente ou de l'événement ne doivent montrer aucune personne reconnaissable sans son accord.</li></ul></section>
<section><h2>Vos droits</h2>
<p>Vous pouvez à tout moment accéder à vos données, les faire corriger ou effacer, vous opposer à leur utilisation, en limiter l'usage, en obtenir une copie, et retirer votre accord pour la transmission de vos photos. Écrivez à <a href="mailto:<?= RGPD_CONTACT_MAIL ?>"><?= RGPD_CONTACT_MAIL ?></a> : nous répondons sous un mois.</p>
<p>Vous pouvez aussi adresser une réclamation à la CNIL (cnil.fr).</p></section>
<section><h2>Sécurité</h2>
<p>L'accès à l'application passe par un code <?= h(AG_COURT) ?> et une connexion sécurisée (HTTPS). Les supports de formation et les comptes rendus ne sont accessibles qu'aux personnes connectées, et l'administration est protégée par un mot de passe et une double authentification.</p></section>
<footer>Dernière mise à jour : octobre 2026 · <?= h(AG_NOM) ?></footer>
</main>
</body>
</html>
