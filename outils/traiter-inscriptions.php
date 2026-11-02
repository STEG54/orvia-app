<?php
// Keylia : crée les espaces des conciergeries inscrites en ligne (adresse confirmée), une par une.
// Tâche planifiée du serveur, en root (la page d'inscription, elle, ne peut rien créer) :
//   * * * * * root php /srv/keylia/outils/traiter-inscriptions.php --config=/srv/keylia/inscription/config.php --paquet=/srv/keylia/dist/amaya.zip >> /var/log/keylia-inscriptions.log 2>&1
// Pour chaque demande : identifiant (atlas, atlas-2…), provisionner-agence.sh avec le certificat générique *.INS_DOMAINE,
// compte du dirigeant créé avec le mot de passe choisi à l'inscription, e-mail de bienvenue avec l'adresse de l'espace.
// Le mot de passe de secours de chaque espace est rangé dans le coffre (--coffre, lisible par root seul), jamais envoyé.
// Options de test : --commande=<script qui remplace provisionner-agence.sh> --sans-recharge
if (PHP_SAPI !== 'cli') exit;
$o = getopt('', ['config:', 'paquet:', 'commande:', 'coffre:', 'sans-recharge']);
if (empty($o['config']) || empty($o['paquet'])) { fwrite(STDERR, "Usage : --config=… --paquet=…\n"); exit(2); }
$_SERVER['REMOTE_ADDR'] = '127.0.0.1'; require dirname($o['config']) . '/lib.php';
$verrou = fopen(sys_get_temp_dir() . '/keylia-inscriptions.lock', 'c'); if (!flock($verrou, LOCK_EX | LOCK_NB)) exit(0);   // une seule exécution à la fois
$ici = __DIR__; $cmd = $o['commande'] ?? "$ici/provisionner-agence.sh"; $coffre = rtrim($o['coffre'] ?? '/root/keylia-coffre', '/');
@mkdir($coffre, 0700, true);

// Données minimales : les demandes jamais confirmées sont effacées au bout de 7 jours.
db()->prepare("DELETE FROM inscriptions WHERE statut = 'a_confirmer' AND created_at < ?")->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);

$RESERVES = ['www', 'app', 'api', 'admin', 'demo', 'mail', 'smtp', 'inscription', 'aide', 'support', 'keylia', 'static', 'cdn', 'test'];
function slug_libre(string $nom, array $res): string {
  $b = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nom))), '-');
  $b = preg_replace('/^(conciergerie|conciergeries|agence|ste|societe|sarl)-/', '', $b) ?: 'espace';
  $b = substr(trim($b, '-'), 0, 24) ?: 'espace'; if (strlen($b) < 3) $b .= '-ma';
  for ($i = 1; $i < 200; $i++) { $s = $i === 1 ? $b : substr($b, 0, 21) . '-' . $i;
    if (in_array($s, $res, true) || is_dir("/srv/amaya/$s")) continue;
    $st = db()->prepare("SELECT COUNT(*) FROM inscriptions WHERE slug = ?"); $st->execute([$s]); if (!(int)$st->fetchColumn()) return $s; }
  return 'espace-' . bin2hex(random_bytes(3));
}

$st = db()->query("SELECT * FROM inscriptions WHERE statut = 'confirme' ORDER BY confirme_at LIMIT 5");
foreach ($st->fetchAll() as $r) {
  $slug = slug_libre($r['conciergerie'], $RESERVES); $dom = $slug . '.' . INS_DOMAINE;
  db()->prepare("UPDATE inscriptions SET statut = 'en_cours', slug = ? WHERE id = ?")->execute([$slug, $r['id']]);
  $json = tempnam(sys_get_temp_dir(), 'kins'); chmod($json, 0600);
  file_put_contents($json, json_encode(['base_url' => "https://$dom",
    'agence' => ['nom' => $r['conciergerie'], 'court' => mb_substr($r['conciergerie'], 0, 40), 'email' => $r['email'], 'mail_from' => INS_MAIL_FROM, 'couleur' => '#43515C',
      'matricule' => strtoupper(substr(preg_replace('/[^a-z]/', '', $slug), 0, 4)) ?: 'KEY', 'tz' => 'Africa/Casablanca'],
    'dirigeants' => [['nom' => $r['nom'], 'email' => $r['email'], 'pass_hash' => $r['pass_hash']]]], JSON_UNESCAPED_UNICODE));
  $sortie = []; $rc = 0;
  exec('CERT_DOMAINE=' . escapeshellarg(INS_DOMAINE) . ' ' . escapeshellarg($cmd) . ' ' . escapeshellarg($slug) . ' ' . escapeshellarg($dom) . ' ' . escapeshellarg($json) . ' ' . escapeshellarg($o['paquet']) . ' 2>&1', $sortie, $rc);
  @unlink($json); $txt = implode("\n", $sortie);
  if ($rc !== 0) {
    db()->prepare("UPDATE inscriptions SET statut = 'erreur', erreur = ? WHERE id = ?")->execute([mb_substr($txt, -500), $r['id']]);
    if (INS_MAIL_ALERTE !== '') envoyer(INS_MAIL_ALERTE, 'Inscription en échec : ' . $r['conciergerie'], "Espace $dom non créé.\n\n" . mb_substr($txt, -2000));
    echo date('c') . " ÉCHEC $slug\n"; continue;
  }
  // Ce que l'installation n'affiche qu'une fois (mot de passe de secours, clé de copie externe) part au coffre, pas dans le journal.
  file_put_contents("$coffre/$slug.txt", date('c') . " $dom\n$txt\n"); chmod("$coffre/$slug.txt", 0600);
  if (!isset($o['sans-recharge'])) exec('systemctl reload nginx $(systemctl list-units --type=service --no-legend "php*-fpm.service" | awk \'{print $1}\') 2>&1');
  db()->prepare("UPDATE inscriptions SET statut = 'pret', pret_at = ?, pass_hash = '' WHERE id = ?")->execute([date('Y-m-d H:i:s'), $r['id']]);
  envoyer($r['email'], 'Votre espace Keylia est prêt',
    "Bonjour " . $r['nom'] . ",\n\nL'espace Keylia de " . $r['conciergerie'] . " est prêt :\n\nhttps://$dom/admin.php\n\n"
    . "Connectez-vous avec votre adresse e-mail et le mot de passe choisi à l'inscription. À la première connexion, Keylia vous demandera d'activer la double authentification : gardez votre téléphone à portée de main.\n\n"
    . "Pour bien commencer : ajoutez votre premier logement avec son lien de calendrier Airbnb ou Booking, puis vos équipes.\n\nKeylia\nL'outil des professionnels de la conciergerie");
  echo date('c') . " PRÊT $slug\n";
}
