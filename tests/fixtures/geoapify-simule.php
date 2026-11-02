<?php
// Faux service Geoapify pour les tests : réponses fixes, chaque requête notée (pour vérifier ce qui part chez le fournisseur).
file_put_contents(sys_get_temp_dir() . '/geoapify-simule.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND);
header('Content-Type: application/json');
if (($_GET['apiKey'] ?? '') !== 'cle-de-test') { http_response_code(401); echo '{"error":"Unauthorized"}'; return; }
if (str_starts_with($_SERVER['REQUEST_URI'], '/v1/routing')) { echo json_encode(['results' => [['distance' => 12345, 'time' => 900]]]); return; }
if (str_starts_with($_SERVER['REQUEST_URI'], '/v1/geocode/search')) {
  echo json_encode(['results' => [['name' => 'Parfumerie Test', 'housenumber' => '12', 'street' => 'Rue de la Paix', 'postcode' => '75002', 'city' => 'Paris', 'lat' => 48.869, 'lon' => 2.331, 'formatted' => 'Parfumerie Test, 12 Rue de la Paix, 75002 Paris', 'rank' => ['confidence' => 0.9]]]]); return; }
http_response_code(404); echo '{}';
