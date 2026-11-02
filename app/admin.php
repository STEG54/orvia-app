<?php require __DIR__ . '/lib.php'; header('Cache-Control: no-store'); ?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="<?= h(ag_theme()['ink']) ?>">
<link rel="icon" type="image/png" href="assets/favicon-32.png">
<title><?= h(AG_COURT) ?> : administration</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . "/style.css") ?>">
<?= ag_style() ?>
</head>
<body>
<div class="top"><img src="<?= h(ag_logo_url(true)) ?>" alt="<?= h(AG_NOM) ?>" id="logo-home" style="cursor:pointer" title="Tableau de bord"><div class="who"><button id="logout" hidden>Se déconnecter</button></div></div>
<main class="wrap wide" id="view"><div class="card"><div class="muted">Chargement…</div></div></main>
<div id="toast" class="toast" hidden></div>
<?= ag_head() ?>
<script src="assets/qrcode.js"></script>
<script src="common.js?v=<?= filemtime(__DIR__ . "/common.js") ?>"></script>
<script src="admin_comptes.js?v=<?= filemtime(__DIR__ . "/admin_comptes.js") ?>"></script>
<script src="admin_mq.js?v=<?= filemtime(__DIR__ . "/admin_mq.js") ?>"></script>
<script src="admin_planning.js?v=<?= filemtime(__DIR__ . "/admin_planning.js") ?>"></script>
<script src="admin_releves.js?v=<?= filemtime(__DIR__ . "/admin_releves.js") ?>"></script>
<script src="admin_contrats.js?v=<?= filemtime(__DIR__ . "/admin_contrats.js") ?>"></script>
<script src="admin_paye.js?v=<?= filemtime(__DIR__ . "/admin_paye.js") ?>"></script>
<script src="admin_docs.js?v=<?= filemtime(__DIR__ . "/admin_docs.js") ?>"></script>
<script src="admin_marges.js?v=<?= filemtime(__DIR__ . "/admin_marges.js") ?>"></script>
<script src="admin_ops.js?v=<?= filemtime(__DIR__ . "/admin_ops.js") ?>"></script>
<script src="admin_maint.js?v=<?= filemtime(__DIR__ . "/admin_maint.js") ?>"></script>
<script src="admin_conciergerie.js?v=<?= filemtime(__DIR__ . "/admin_conciergerie.js") ?>"></script>
<script src="admin_gestion.js?v=<?= filemtime(__DIR__ . "/admin_gestion.js") ?>"></script>
<script src="admin.js?v=<?= filemtime(__DIR__ . "/admin.js") ?>"></script>
</body>
</html>
