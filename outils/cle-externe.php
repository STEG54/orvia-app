<?php
// Génère la clé de chiffrement de la copie hors du serveur (SV_CLE_EXTERNE), distincte de la clé de l'agence (AMAYA_CLE).
// À coller dans config.php ET à garder hors du serveur (gestionnaire de mots de passe, coffre) : sans elle, la copie est illisible.
if (PHP_SAPI !== 'cli') exit;
echo "define('SV_CLE_EXTERNE', '" . base64_encode(random_bytes(32)) . "');\n";
