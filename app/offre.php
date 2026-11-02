<?php
// Ancien lien de réponse par e-mail : on se positionne désormais dans l'appli.
require __DIR__ . '/lib.php';
header('Location: ' . rtrim(BASE_URL, '/') . '/'); exit;
