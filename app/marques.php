<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); $v = fn($f) => filemtime(__DIR__ . '/' . $f); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= h(ag_theme()['ink']) ?>">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title><?= h(AG_NOM) ?> : espace marques</title>
<link rel="stylesheet" href="style.css?v=<?= $v('style.css') ?>">
<?= ag_style() ?>
</head>
<body>
<div class="top"><img src="<?= h(ag_logo_url(true)) ?>" alt="<?= h(AG_NOM) ?>"><div class="who" id="who"></div></div>
<main class="wrap wide" id="view"><div class="card"><div class="muted">Chargement…</div></div></main>
<div id="toast" class="toast" hidden></div>
<?= ag_head() ?>
<script src="assets/qrcode.js"></script>
<script src="common.js?v=<?= $v('common.js') ?>"></script>
<script src="marques.js?v=<?= $v('marques.js') ?>"></script>
</body>
</html>
