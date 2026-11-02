<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#233446">
<title>Installer <?= h(AG_COURT) ?></title>
<meta name="description" content="Installez l'application <?= h(AG_COURT) ?> sur votre téléphone.">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="manifest" href="manifest.php">
<style>
:root{--navy:#233446;--esp:#1A1512;--terra:<?= h(ag_couleur()) ?>;--sand:#F4EEE7;--line:#E3D9CD;--muted:#7A6D62}
*{box-sizing:border-box}body{margin:0;background:var(--sand);color:var(--esp);font-family:"Figtree",system-ui,sans-serif;font-size:16px;line-height:1.5}
.hero{background:var(--navy);color:#fff;text-align:center;padding:40px 22px 34px}
.hero img.icon{width:96px;height:96px;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.hero h1{font-family:"Bricolage Grotesque",system-ui,sans-serif;font-weight:700;letter-spacing:-.02em;font-size:36px;line-height:1.1;margin:18px 0 8px}
.hero p{margin:0 auto;max-width:420px;color:#D9E0E8;font-size:15px}
.wrap{max-width:480px;margin:-18px auto 30px;padding:0 16px}
.card{background:#fff;border:1px solid var(--line);border-radius:18px;padding:20px;margin-bottom:12px}
.card h2{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--terra);margin:0 0 12px}
.tabs{display:flex;gap:6px;background:var(--sand);padding:4px;border-radius:12px;margin-bottom:14px}
.tabs button{flex:1;border:0;background:transparent;padding:10px;border-radius:9px;font:inherit;font-weight:600;color:var(--muted);cursor:pointer}
.tabs button[aria-pressed="true"]{background:#fff;color:var(--esp);box-shadow:0 1px 3px rgba(0,0,0,.08)}
ol{margin:0;padding:0;list-style:none;counter-reset:s}
ol li{counter-increment:s;position:relative;padding:0 0 14px 44px;min-height:32px}
ol li:before{content:counter(s);position:absolute;left:0;top:-2px;width:32px;height:32px;border-radius:50%;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:600}
.btn{display:block;text-align:center;text-decoration:none;background:var(--terra);color:#fff;font-weight:600;padding:15px;border-radius:12px;font-size:17px}
.btn.alt{background:#fff;color:var(--esp);border:1px solid var(--line);margin-top:8px}
.note{font-size:13px;color:var(--muted);margin-top:10px}
.feat{display:grid;gap:10px}
.feat div{padding-left:14px;border-left:2px solid var(--terra)}
.feat b{display:block}
.feat span{font-size:14px;color:var(--muted)}
footer{text-align:center;font-size:12px;color:var(--muted);padding:10px 0 30px}
</style>
<?= ag_style() ?>
</head>
<body>
<div class="hero">
  <img class="icon" src="assets/icon-512.png" alt="">
  <h1><?= h(AG_COURT) ?></h1>
  <p>L'application des intervenants <?= h(AG_NOM) ?>. Installez-la sur votre téléphone en moins d'une minute, sans passer par l'App Store ni Google Play.</p>
</div>
<div class="wrap">
  <div class="card">
    <button class="btn" data-install style="width:100%;border:0;font:inherit;font-weight:600;font-size:18px;cursor:pointer">Installer l'application</button>
    <a class="btn alt" href="./">Ouvrir sans installer</a>
    <div class="note">À la première ouverture, saisissez le code <?= h(AG_COURT) ?> transmis par votre responsable, puis vos prénom, nom, portable, e-mail et zone.</div>
  </div>
  <div class="card">
    <h2>Ce que vous y trouvez</h2>
    <div class="feat">
      <div><b>Vos comptes rendus de journée</b><span>Marque, CA, ressenti des clientes, photos du rayon : saisissez au fil de la journée, envoyez le soir.</span></div>
      <div><b>Votre historique</b><span>Retrouvez chaque compte rendu en entier et recevez-le en PDF.</span></div>
      <div><b>Vos formations</b><span>Fiches produit, vidéos et argumentaires, rangés par marque.</span></div>
    </div>
  </div>
  <footer><a href="confidentialite.php" style="color:<?= h(ag_couleur()) ?>;font-weight:600">Vos données personnelles</a><br>Une question ? <a href="mailto:<?= h(AG_EMAIL) ?>" style="color:<?= h(ag_couleur()) ?>;font-weight:600"><?= h(AG_EMAIL) ?></a><br><?= h(AG_NOM) ?></footer>
</div>
<script src="common.js?v=<?= filemtime(__DIR__ . "/common.js") ?>"></script>
</body>
</html>
