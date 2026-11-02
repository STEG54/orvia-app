<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= h(ag_theme()['ink']) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(AG_COURT) ?>">
<title><?= h(AG_COURT) ?></title>
<link rel="apple-touch-icon" href="assets/icon-180.png">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<link rel="manifest" href="manifest.php">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">
<?= ag_style() ?>
</head>
<body>
<div class="top"><img src="<?= h(ag_logo_url(true)) ?>" alt="<?= h(AG_NOM) ?>"><div class="who" id="who"></div></div>
<main class="wrap" id="view"><div class="card"><div class="muted">Chargement…</div></div></main>
<div id="toast" class="toast" hidden></div>
<?= ag_head() ?>
<script src="common.js?v=<?= filemtime(__DIR__ . "/common.js") ?>"></script>
<script src="app.js?v=<?= filemtime(__DIR__ . "/app.js") ?>"></script>
</body>
</html>
