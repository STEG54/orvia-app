<?php require __DIR__ . '/lib.php'; header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode(['name' => AG_COURT, 'short_name' => mb_substr(AG_COURT, 0, 12), 'description' => 'Appli des intervenants ' . AG_NOM, 'lang' => 'fr',
  'start_url' => './index.php', 'scope' => './', 'display' => 'standalone', 'orientation' => 'portrait', 'background_color' => '#18202A', 'theme_color' => '#18202A',
  'icons' => [['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ['src' => 'assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
