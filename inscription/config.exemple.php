<?php
// Keylia : réglages de la page d'inscription en ligne (copier en config.php, hors dépôt).
define('INS_DB_HOST', 'localhost');
define('INS_DB_NAME', 'keylia_inscriptions');
define('INS_DB_USER', 'keylia_ins');
define('INS_DB_PASS', '');
define('INS_URL', 'https://keylia.ma/inscription');      // adresse publique de cette page
define('INS_DOMAINE', 'keylia.ma');                       // les espaces sont créés en <identifiant>.keylia.ma
define('INS_MAIL_FROM', 'bienvenue@keylia.ma');
define('INS_MAIL_ALERTE', '');                            // facultatif : adresse prévenue à chaque nouvelle inscription
date_default_timezone_set('Africa/Casablanca');
