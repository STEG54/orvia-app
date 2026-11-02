<?php
// Mode démo (config.php : define('AMAYA_DEMO', true)) : chaque visiteur laisse ses coordonnées, reçoit un lien par e-mail
// et obtient SA propre agence fictive (tables à son nom, invisibles des autres), effacée au bout de DEMO_JOURS jours.
// E-mails et notifications de l'appli coupés, réglages sensibles bloqués.
require_once __DIR__ . '/contrats_lib.php';
require_once __DIR__ . '/report_lib.php';

const DEMO_DIRIGEANT = ['email' => 'direction@demo-amaya.fr', 'nom' => 'Alexandre Morin'];
const DEMO_CONCURRENTS = ['Atelier Solène', 'Belle Rive', 'Nordé', 'Verde Nature', 'Maison Iris', 'Kalista'];
const DEMO_MARQUE = ['email' => 'marque@demo-amaya.fr', 'nom' => 'Sophie Arnaud', 'societe' => 'Maison Orélia Parfums'];

/** Exécute $f sur les tables communes de la démo (sans préfixe de visiteur). */
function demo_base(callable $f) { $old = $GLOBALS['DEMO_P'] ?? null; $GLOBALS['DEMO_P'] = ''; try { return $f(); } finally { if ($old === null) unset($GLOBALS['DEMO_P']); else $GLOBALS['DEMO_P'] = $old; } }
function demo_leads_schema(): void {
  demo_base(fn() => db()->exec("CREATE TABLE IF NOT EXISTS demo_leads (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, prenom VARCHAR(80) NOT NULL, nom VARCHAR(80) NOT NULL, agence VARCHAR(160) NOT NULL,
    email VARCHAR(160) NOT NULL, telephone VARCHAR(30) NOT NULL, equipe VARCHAR(20) NOT NULL DEFAULT '', metier VARCHAR(20) NOT NULL DEFAULT '', jeton CHAR(64) NOT NULL, ip VARCHAR(60) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL, verifie_le DATETIME NULL, cree_le DATETIME NULL, derniere_visite DATETIME NULL, supprime_le DATETIME NULL, INDEX (email), UNIQUE (jeton)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"));
}
const DEMO_JOURS = 7;
/** E-mail réel (les e-mails de l'appli restent coupés dans la démo). */
function demo_mail(string $to, string $sujet, string $html): bool { $GLOBALS['DEMO_MAIL_OK'] = true; try { return mail_send($to, $sujet, $html); } finally { unset($GLOBALS['DEMO_MAIL_OK']); } }
/** Inscription : coordonnées gardées, lien d'accès personnel envoyé par e-mail, l'éditeur prévenu. */
function demo_inscrire(array $in): void {
  demo_leads_schema(); $t = fn($k, $n) => mb_substr(trim((string)($in[$k] ?? '')), 0, $n);
  $L = ['prenom' => $t('prenom', 80), 'nom' => $t('nom', 80), 'agence' => $t('agence', 160), 'email' => mb_strtolower($t('email', 160)), 'telephone' => $t('telephone', 30), 'equipe' => $t('equipe', 20), 'metier' => $t('metier', 20)];
  foreach (['prenom' => 'votre prénom', 'nom' => 'votre nom', 'agence' => 'le nom de votre agence', 'telephone' => 'votre téléphone'] as $k => $l) if ($L[$k] === '') throw new RuntimeException('Indiquez ' . $l . '.');
  if (!filter_var($L['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Adresse e-mail invalide.');
  if (strlen(preg_replace('/\D/', '', $L['telephone'])) < 9) throw new RuntimeException('Numéro de téléphone incomplet.');
  if (empty($in['rgpd'])) throw new RuntimeException('Cochez la case d\'accord pour recevoir votre accès.');
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $n = demo_base(function () use ($ip) { $st = db()->prepare("SELECT COUNT(*) FROM demo_leads WHERE ip = ? AND created_at > NOW() - INTERVAL 1 DAY"); $st->execute([$ip]); return (int)$st->fetchColumn(); });
  if ($n >= 5) throw new RuntimeException('Trop de demandes depuis cette connexion aujourd\'hui. Écrivez-nous directement.');
  $jeton = bin2hex(random_bytes(32));
  demo_base(fn() => db()->prepare("INSERT INTO demo_leads (prenom, nom, agence, email, telephone, equipe, metier, jeton, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([...array_values($L), hash('sha256', $jeton), $ip, now()]));
  $lien = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/demo.php?acces=' . $jeton;
  $css = 'font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#16123A;line-height:1.55';
  if (!demo_mail($L['email'], 'Votre démo ' . PRODUIT_NOM . ' est prête', '<div style="' . $css . '"><p>Bonjour ' . h($L['prenom']) . ',</p><p>Votre conciergerie de démonstration ' . PRODUIT_NOM . ' vous attend. Elle est à vous seul(e) pendant ' . DEMO_JOURS . ' jours : ce que vous y saisissez n\'est visible par personne d\'autre.</p>'
    . '<p><a href="' . h($lien) . '" style="display:inline-block;background:#6A4BFF;color:#fff;padding:12px 20px;border-radius:10px;text-decoration:none;font-weight:bold">Ouvrir ma démo</a></p><p style="color:#6E6A8F;font-size:13px">Ce lien est personnel. Si le bouton ne marche pas, copiez cette adresse : ' . h($lien) . '</p></div>'))
    throw new RuntimeException('L\'e-mail n\'a pas pu partir. Vérifiez votre adresse ou réessayez dans un instant.');
  $pour = defined('AMAYA_LEADS') ? AMAYA_LEADS : AG_EMAIL;
  if ($pour) demo_mail($pour, 'Nouvelle demande de démo : ' . $L['agence'], '<div style="' . $css . '"><p><b>' . h($L['prenom'] . ' ' . $L['nom']) . '</b>, ' . h($L['agence']) . '</p><p>E-mail : ' . h($L['email']) . '<br>Téléphone : ' . h($L['telephone'])
    . '<br>Taille de l\'équipe : ' . h($L['equipe'] ?: 'non précisée') . '<br>Métier : ' . h($L['metier'] ?: 'non précisé') . '</p><p>Le lien lui a été envoyé. Rappelez-le pendant qu\'il découvre la démo.</p></div>');
}
/** Lien reçu par e-mail : ouvre la démo personnelle (créée au premier passage). Renvoie l'id du visiteur, ou 0. */
function demo_ouvrir(string $jeton): int {
  if (!preg_match('/^[a-f0-9]{64}$/', $jeton)) return 0; demo_leads_schema();
  $L = demo_base(function () use ($jeton) { $st = db()->prepare("SELECT * FROM demo_leads WHERE jeton = ? AND supprime_le IS NULL"); $st->execute([hash('sha256', $jeton)]); return $st->fetch(); });
  if (!$L || strtotime($L['created_at']) < time() - DEMO_JOURS * 86400) return 0;
  demo_base(fn() => db()->prepare("UPDATE demo_leads SET verifie_le = COALESCE(verifie_le, NOW()), derniere_visite = NOW() WHERE id = ?")->execute([$L['id']]));
  session_regenerate_id(true); $_SESSION['demo_p'] = 'd' . (int)$L['id'] . '_'; $_SESSION['demo_lead'] = (int)$L['id'];
  return (int)$L['id'];
}
/** L'agence fictive du visiteur existe-t-elle déjà ? */
function demo_prete(int $id): bool { return (bool)demo_base(function () use ($id) { $st = db()->prepare("SELECT cree_le FROM demo_leads WHERE id = ? AND supprime_le IS NULL"); $st->execute([$id]); return $st->fetchColumn(); }); }
/** Crée les tables du visiteur et l'agence fictive (environ 15 secondes). */
function demo_creer(int $id): void {
  @set_time_limit(300); if (demo_prete($id)) return;
  $GLOBALS['DEMO_P'] = 'd' . $id . '_'; $pdo = db();
  foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', (string)file_get_contents(__DIR__ . '/install.sql'))))) as $q) $pdo->exec($q);
  demo_seed();
  unset($GLOBALS['DEMO_P']);
  demo_base(fn() => db()->prepare("UPDATE demo_leads SET cree_le = NOW() WHERE id = ?")->execute([$id]));
}
/** Démos de plus de DEMO_JOURS jours : tables et fichiers supprimés (les coordonnées restent, pour le suivi commercial). */
const DEMO_EDITEUR = 'editeur@amaya.local';
function demo_nettoyer(int $max = 3): void {
  demo_leads_schema();
  $ids = demo_base(fn() => db()->query("SELECT id FROM demo_leads WHERE supprime_le IS NULL AND cree_le IS NOT NULL AND email <> '" . DEMO_EDITEUR . "' AND created_at < NOW() - INTERVAL " . DEMO_JOURS . " DAY LIMIT " . (int)$max)->fetchAll(PDO::FETCH_COLUMN));
  foreach ($ids as $id) demo_effacer((int)$id, true);
}
/** Supprime les tables et photos d'une agence fictive. $fin : la démo est close (sinon elle sera recréée à la prochaine visite). */
function demo_effacer(int $id, bool $fin): void {
  $p = 'd' . $id . '_';
  try { $GLOBALS['DEMO_P'] = $p; foreach (db()->query("SELECT fichier FROM photos")->fetchAll(PDO::FETCH_COLUMN) as $f) if (preg_match('/^[a-f0-9]{32}$/', $f)) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); } } catch (Throwable $e) {}
  unset($GLOBALS['DEMO_P']);
  demo_base(function () use ($p, $id, $fin) { $pdo = db(); $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as [$t]) if (str_starts_with($t, $p)) $pdo->exec("DROP TABLE `$t`");
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    @unlink(upload_dir() . '/.migrations-' . $p); // tables effacées : les évolutions de la base seront rejouées pour la démo remise à neuf
    $pdo->prepare($fin ? "UPDATE demo_leads SET supprime_le = NOW() WHERE id = ?" : "UPDATE demo_leads SET cree_le = NULL WHERE id = ?")->execute([$id]); });
}
/** Accès de l'éditeur (config.php : AMAYA_DEMO_CLE) : sa propre agence fictive, sans e-mail ni limite de durée. */
function demo_editeur(): int {
  demo_leads_schema();
  $id = (int)demo_base(fn() => db()->query("SELECT id FROM demo_leads WHERE email = '" . DEMO_EDITEUR . "' AND supprime_le IS NULL ORDER BY id LIMIT 1")->fetchColumn());
  if (!$id) { demo_base(fn() => db()->prepare("INSERT INTO demo_leads (prenom, nom, agence, email, telephone, jeton, created_at, verifie_le) VALUES ('Éditeur', 'Amaya', 'Démo de l''éditeur', ?, '', ?, NOW(), NOW())")->execute([DEMO_EDITEUR, hash('sha256', random_bytes(32))]));
    $id = (int)demo_base(fn() => db()->query("SELECT MAX(id) FROM demo_leads")->fetchColumn()); }
  return $id;
}

function demo_j(int $n): string { return date('Y-m-d', strtotime("$n day")); }
/** Photo de démonstration (rayon ou selfie stylisé), enregistrée comme une vraie photo. */
function demo_photo(int $graine, bool $selfie, bool $evt = false, string $mc = ''): string {
  mt_srand($graine); $w = 1200; $h = 900; $im = imagecreatetruecolor($w, $h);
  $pal = [[106, 75, 255], [255, 92, 138], [14, 165, 160], [255, 176, 32], [47, 91, 255], [22, 18, 58]];
  $bg = $pal[mt_rand(0, 5)]; imagefill($im, 0, 0, imagecolorallocate($im, 246, 245, 255));
  if ($selfie) {
    imagefilledrectangle($im, 0, 0, $w, $h, imagecolorallocate($im, ...array_map(fn($c) => (int)($c * .25 + 190), $bg)));
    imagefilledellipse($im, 600, 380, 300, 340, imagecolorallocate($im, 236, 200, 170));
    imagefilledellipse($im, 600, 900, 620, 520, imagecolorallocate($im, ...$bg));
  } elseif ($evt) {
    // Événement : mur de stand, banque d'accueil, kakémono et silhouettes de visiteurs.
    $col = fn($c) => imagecolorallocate($im, ...$c);
    imagefilledrectangle($im, 0, 0, $w, 560, $col([232, 230, 246])); imagefilledrectangle($im, 0, 560, $w, $h, $col([214, 210, 228]));
    imagefilledrectangle($im, 140, 90, 1060, 470, $col($bg)); imagefilledrectangle($im, 420, 200, 780, 300, $col([255, 255, 255]));
    imagefilledrectangle($im, 60, 120, 150, 640, $col($pal[($graine + 1) % 6])); imagefilledrectangle($im, 52, 640, 158, 655, $col([60, 60, 80]));
    imagefilledrectangle($im, 380, 470, 820, 640, $col([255, 255, 255])); imagefilledrectangle($im, 380, 470, 820, 500, $col($bg));
    for ($i = 0, $n = mt_rand(4, 7); $i < $n; $i++) { $x = mt_rand(120, 1080); $y = mt_rand(560, 700); $c = $pal[mt_rand(0, 5)];
      imagefilledellipse($im, $x, $y, 70, 80, $col([236, 200, 170])); imagefilledrectangle($im, $x - 55, $y + 45, $x + 55, $y + 260, $col($c)); }
  } elseif ($mc !== '') {
    // Merchandising : rayon en désordre (trous, produits penchés) avant, rangé et complet après.
    $col = fn($c) => imagecolorallocate($im, ...$c); $ok = $mc === 'apres';
    imagefilledrectangle($im, 0, 0, $w, 120, $col($bg)); imagefilledrectangle($im, 40, 150, 1160, 880, $col([236, 233, 248]));
    for ($s = 0; $s < 4; $s++) { $y = 200 + $s * 170; imagefilledrectangle($im, 40, $y + 130, 1160, $y + 146, $col([190, 186, 214]));
      for ($x = 70, $k = 0; $x < 1120; $x += 70, $k++) { if (!$ok && mt_rand(0, 9) < 3) continue; $c = $ok ? $pal[intdiv($k, 3) % 6] : $pal[mt_rand(0, 5)]; $bh = $ok ? 110 : mt_rand(70, 125); $dx = $ok ? 0 : mt_rand(-10, 10);
        imagefilledpolygon($im, [$x + $dx, $y + 130 - $bh, $x + 48 + $dx, $y + 130 - $bh, $x + 48, $y + 130, $x, $y + 130], $col($c)); }
      if ($ok) imagefilledrectangle($im, 60, $y + 132, 150, $y + 144, $col([255, 255, 255])); }
  } else {
    for ($s = 0; $s < 3; $s++) { $y = 220 + $s * 230; imagefilledrectangle($im, 60, $y + 150, 1140, $y + 170, imagecolorallocate($im, 200, 196, 220));
      for ($x = 90; $x < 1110; $x += 90) { $c = $pal[mt_rand(0, 5)]; $bh = mt_rand(90, 145); imagefilledrectangle($im, $x, $y + 150 - $bh, $x + 55, $y + 150, imagecolorallocate($im, ...$c));
        imagefilledrectangle($im, $x + 18, $y + 150 - $bh - 22, $x + 37, $y + 150 - $bh, imagecolorallocate($im, 40, 40, 60)); } }
    imagefilledrectangle($im, 0, 0, $w, 120, imagecolorallocate($im, ...$bg));
  }
  $name = bin2hex(random_bytes(16));
  foreach (['' => 1, '_t' => 700 / 1200] as $sx => $r) { $d = imagecreatetruecolor((int)($w * $r), (int)($h * $r)); imagecopyresampled($d, $im, 0, 0, 0, 0, imagesx($d), imagesy($d), $w, $h); imagejpeg($d, upload_dir() . "/$name$sx.jpg", 80); imagedestroy($d); }
  imagedestroy($im); return $name;
}

function demo_seed(): void {
  $pdo = db(); g_schema(); p_schema(); c_schema(); r_schema(); require_once __DIR__ . '/geo_lib.php'; geo_schema(); $GLOBALS['GEO_MAX'] = 0;
  $_SESSION['admin_nom'] = DEMO_DIRIGEANT['nom'];
  $set = $pdo->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES (?, ?)");
  $modele = "Article 1 - Texte de démonstration\nDans ' . PRODUIT_NOM . ', chaque agence colle ici son propre contrat, rédigé ou validé par son avocat. Ce texte fictif sert seulement à montrer le fonctionnement.\n\nArticle 2 - Engagement\n{Salarie} est engagé{e} en qualité de {emploi} pour l'opération « {operation} » de la société {client}.\n\nArticle 3 - Durée\nLe présent contrat, {duree}\n\nArticle 4 - Horaires et rémunération\nTaux horaire brut : {taux}. Les journées sont les suivantes :\n{horaires}\n\n{frais}";
  foreach (['societe' => AG_NOM, 'adresse' => "12 rue de la Démo\n69002 Lyon", 'telephone' => '04 00 00 00 00', 'email' => AG_EMAIL, 'siret' => '000 000 000 00000', 'contact' => DEMO_DIRIGEANT['nom'],
    'signataire' => DEMO_DIRIGEANT['nom'], 'banque' => 'Banque de démonstration', 'iban' => 'FR76 3000 6000 0112 3456 7890 189', 'bic' => 'AGRIFRPP',
    'ct_representant' => DEMO_DIRIGEANT['nom'], 'ct_qualite' => 'Gérant', 'ct_lieu' => 'Lyon', 'ct_urssaf' => 'Rhône-Alpes', 'ct_caisse' => 'Caisse de prévoyance (fictive)', 'ct_retraite' => 'Caisse de retraite (fictive)',
    'ct_modele' => $modele, 'ct_valide' => '1', 'hot_modele' => $modele, 'hot_valide' => '1', 'ct_convention' => 'convention collective de démonstration', 'hot_convention' => 'convention collective de démonstration',
    'planning_ouvert' => '1', 'signature' => DEMO_DIRIGEANT['nom'] . "\n" . AG_NOM] as $k => $v) $set->execute([$k, $v]);
  $pdo->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('code_agence', 'DEMO'), ('pp_masque', '1')")->execute();
  $pdo->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,'tout',1,?)")->execute([DEMO_DIRIGEANT['nom'], DEMO_DIRIGEANT['email'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), now()]);

  // Points de vente et lieux de mission (coordonnées réelles approximatives, aucune requête externe).
  $M = [['Beauté Lumière', 'Beauté Lumière Lyon Part-Dieu', 'Lyon', '17 rue du Dr Bouchut, 69003 Lyon', 45.7610, 4.8570], ['Hyper Rhône', 'Hyper Rhône Lyon Bellecour', 'Lyon', '15 rue de la République, 69002 Lyon', 45.7590, 4.8355],
    ['Grand Bazar', 'Grand Bazar Bron', 'Bron', '1 avenue Franklin Roosevelt, 69500 Bron', 45.7360, 4.9180], ['Maison Clément', 'Maison Clément Villeurbanne', 'Villeurbanne', '20 cours Émile Zola, 69100 Villeurbanne', 45.7700, 4.8790],
    ['Pharmacie', 'Pharmacie des Terreaux', 'Lyon', '3 place des Terreaux, 69001 Lyon', 45.7675, 4.8335], ['Pharmacie', 'Pharmacie Grand Ouest', 'Écully', '5 chemin de Charbonnières, 69130 Écully', 45.7760, 4.7790],
    ['Beauté Lumière', 'Beauté Lumière Paris Rivoli', 'Paris', '70 rue de Rivoli, 75004 Paris', 48.8570, 2.3510], ['Grand Magasin', 'Grand Magasin Haussmann', 'Paris', '64 boulevard Haussmann, 75009 Paris', 48.8738, 2.3270],
    ['Parc des expositions', 'Parc des expositions de Lyon (hall 4)', 'Chassieu', 'Boulevard de l\'Europe, 69680 Chassieu', 45.7310, 4.9480], ['Centre de congrès', 'Centre de congrès de Lyon', 'Lyon', '50 quai Charles de Gaulle, 69006 Lyon', 45.7850, 4.8520],
    ['Espace public', 'Place Bellecour (espace public)', 'Lyon', 'Place Bellecour, 69002 Lyon', 45.7578, 4.8320], ['Gare', 'Gare de Lyon Part-Dieu (hall 1)', 'Lyon', '5 place Charles Béraudier, 69003 Lyon', 45.7605, 4.8597],
    ['Espace public', 'Rue de la République (zone piétonne)', 'Lyon', 'Rue de la République, 69002 Lyon', 45.7620, 4.8360], ['Siège', 'Siège Nomade Audio Lyon', 'Lyon', '40 rue Garibaldi, 69006 Lyon', 45.7660, 4.8520],
    ['Pharmacie', 'Pharmacie de la Croix-Rousse', 'Lyon', '4 place de la Croix-Rousse, 69004 Lyon', 45.7745, 4.8317], ['Pharmacie', 'Pharmacie Saxe-Gambetta', 'Lyon', '100 cours Gambetta, 69007 Lyon', 45.7505, 4.8495],
    ['Pharmacie', 'Grande Pharmacie de Villeurbanne', 'Villeurbanne', '10 cours Émile Zola, 69100 Villeurbanne', 45.7690, 4.8760]];
  $im = $pdo->prepare("INSERT INTO magasins (zone, enseigne, nom, ville, adresse, lat, lng, geo_approx) VALUES ('France',?,?,?,?,?,?,0)"); $MID = [];
  foreach ($M as $m) { $im->execute([$m[0], $m[1], $m[2], $m[3], $m[4], $m[5]]); $MID[$m[1]] = (int)$pdo->lastInsertId(); }
  dep_schema(); dep_remplir();

  // Marques (comptes rendus) et clients (devis, factures).
  $im = $pdo->prepare("INSERT INTO marques (nom, actif, ordre) VALUES (?,1,?)"); $MQ = [];
  foreach (['Maison Orélia', 'Nomade Audio', 'Dermalis'] as $i => $n) { $im->execute([$n, $i]); $MQ[$n] = (int)$pdo->lastInsertId(); }
  $ic = $pdo->prepare("INSERT INTO g_clients (raison, contact, adresse, cp, ville, emails, tva, paiement, created_at) VALUES (?,?,?,?,?,?,'20','30j',?)"); $CL = [];
  foreach ([['Maison Orélia Parfums', 'Sophie Arnaud', '8 avenue Montaigne', '75008', 'Paris'], ['Nomade Audio', 'Thomas Perrin', '22 rue de la Paix', '75002', 'Paris'],
    ['Laboratoires Dermalis', 'Claire Fabre', '4 rue Pasteur', '69007', 'Lyon'], ['Salon Mobilis', 'Julien Masson', 'Boulevard de l\'Europe', '69680', 'Chassieu'], ['Vinora', 'Hélène Roche', '2 quai Saint-Antoine', '69002', 'Lyon']] as $c) {
    $ic->execute([$c[0], $c[1], $c[2], $c[3], $c[4], 'contact@exemple.invalid', now()]); $CL[$c[0]] = (int)$pdo->lastInsertId(); }

  // Équipe : intervenant(e)s fictifs avec leur fiche (adresses géolocalisées à l'avance).
  $P = [['Léa', 'Martin', 'Mme', '25 rue Garibaldi', '69006', 'Lyon', 45.7680, 4.8530], ['Inès', 'Bernard', 'Mme', '8 rue Mercière', '69002', 'Lyon', 45.7620, 4.8320],
    ['Chloé', 'Petit', 'Mme', '40 avenue Jean Jaurès', '69007', 'Lyon', 45.7450, 4.8420], ['Manon', 'Robert', 'Mme', '12 rue Paul Bert', '69003', 'Lyon', 45.7570, 4.8550],
    ['Sarah', 'Richard', 'Mme', '5 place Bellecour', '69002', 'Lyon', 45.7578, 4.8320], ['Camille', 'Durand', 'Mme', '33 cours Tolstoï', '69100', 'Villeurbanne', 45.7640, 4.8800],
    ['Lou', 'Moreau', 'Mme', '18 rue Victor Hugo', '69500', 'Bron', 45.7380, 4.9130], ['Emma', 'Laurent', 'Mme', '7 rue de Marseille', '69007', 'Lyon', 45.7510, 4.8410],
    ['Lina', 'Simon', 'Mme', '60 rue de la Part-Dieu', '69003', 'Lyon', 45.7600, 4.8510], ['Zoé', 'Michel', 'Mme', '2 rue Duquesne', '69006', 'Lyon', 45.7710, 4.8470],
    ['Yasmine', 'Lefèvre', 'Mme', '11 avenue Berthelot', '69007', 'Lyon', 45.7470, 4.8400], ['Anaïs', 'Garcia', 'Mme', '9 rue Bossuet', '69006', 'Lyon', 45.7690, 4.8490],
    ['Nora', 'David', 'Mme', '14 rue Saint-Antoine', '75004', 'Paris', 48.8540, 2.3640], ['Clara', 'Bertrand', 'Mme', '3 rue de Provence', '75009', 'Paris', 48.8750, 2.3390],
    ['Maëlys', 'Roux', 'Mme', '21 rue Oberkampf', '75011', 'Paris', 48.8650, 2.3740], ['Lucas', 'Vincent', 'M.', '6 rue Dumenge', '69004', 'Lyon', 45.7780, 4.8300],
    ['Hugo', 'Fournier', 'M.', '30 cours Lafayette', '69003', 'Lyon', 45.7640, 4.8470], ['Nathan', 'Morel', 'M.', '4 rue Pierre Corneille', '69006', 'Lyon', 45.7660, 4.8450],
    ['Inaya', 'Girard', 'Mme', '17 avenue Félix Faure', '69003', 'Lyon', 45.7540, 4.8560], ['Eva', 'Bonnet', 'Mme', '10 rue de Créqui', '69006', 'Lyon', 45.7650, 4.8460],
    ['Julie', 'Dupuis', 'Mme', '1 rue Royale', '69001', 'Lyon', 45.7700, 4.8380], ['Amandine', 'Lambert', 'Mme', '22 rue Chevreul', '69007', 'Lyon', 45.7480, 4.8390],
    ['Sofia', 'Mercier', 'Mme', '5 rue Sainte-Hélène', '69002', 'Lyon', 45.7540, 4.8290], ['Rayan', 'Blanc', 'M.', '28 rue de Sèze', '69006', 'Lyon', 45.7690, 4.8510],
    ['Maya', 'Guerin', 'Mme', '13 rue Juliette Récamier', '69006', 'Lyon', 45.7680, 4.8500], ['Adam', 'Muller', 'M.', '6 rue Pierre Dupont', '69001', 'Lyon', 45.7710, 4.8270],
    ['Léna', 'Faure', 'Mme', '19 rue Saint-Jean', '69005', 'Lyon', 45.7620, 4.8270], ['Tom', 'André', 'M.', '41 rue Paul Bert', '69003', 'Lyon', 45.7580, 4.8600],
    ['Mila', 'Fontaine', 'Mme', '2 quai de Bondy', '69005', 'Lyon', 45.7650, 4.8280], ['Noah', 'Chevalier', 'M.', '15 rue Vendôme', '69006', 'Lyon', 45.7670, 4.8470],
    ['Clémence', 'Rolland', 'Mme', '8 rue d\'Austerlitz', '69004', 'Lyon', 45.7760, 4.8290], ['Bastien', 'Colin', 'M.', '25 avenue Lacassagne', '69003', 'Lyon', 45.7550, 4.8700]];
  $ia = $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at, rgpd_info_at, rgpd_image_at) VALUES (?,?,?,?,'France',1,'actif',?,?,?)");
  $if = $pdo->prepare("INSERT INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?,?,?)"); $A = [];
  foreach ($P as $i => $p) {
    $cree = date('Y-m-d H:i:s', strtotime('-' . (200 - $i * 7) . ' days'));
    $ia->execute([$p[0], $p[1], '06 00 00 ' . sprintf('%02d %02d', intdiv($i, 10), $i % 10 * 7), strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $p[0] . '.' . $p[1])) . '@exemple.invalid', $cree, $cree, $cree]);
    $aid = (int)$pdo->lastInsertId(); $A[] = $aid; $hot = $i >= 15 || $i % 4 === 0;
    $d = fiche_clean(['civilite' => $p[2], 'adresse' => $p[3], 'code_postal' => $p[4], 'ville' => $p[5], 'nationalite' => 'Française', 'naissance_date' => (1994 + $i % 9) . '-0' . (1 + $i % 9) . '-1' . ($i % 9),
      'naissance_lieu' => 'Lyon', 'iban' => 'FR7630006000011234567890189', 'bic' => 'AGRIFRPP', 'statut' => $i === 15 ? FICHE_AE : 'Salarié(e)', 'siret' => $i === 15 ? '73282932000074' : '', 'stature' => 158 + ($i * 3) % 25, 'certifs' => $hot && $i % 3 === 0 ? ['Secourisme (PSC1, SST)'] : [], 'mob_deps' => $p[4] < '70000' ? ['69'] : ['75', '92'], 'permis' => $i % 3 !== 0, 'vehicule' => $i % 3 === 1,
      'jours' => array_slice(FICHE_JOURS, $i % 3, 5), 'langues' => array_values(array_unique(['Français', ['Anglais', 'Espagnol', 'Italien', 'Anglais'][$i % 4]])), 'competences' => array_slice(FICHE_COMPETENCES, $i % 6, 3),
      'metiers' => $hot ? ['Animation commerciale', 'Accueil & hôtessariat'] : ['Animation commerciale'], 'accueil' => $hot ? array_slice(FICHE_ACCUEIL, $i % 5, 2) : [], 'experience' => 1 + $i % 8,
      'taille_haut' => FICHE_TAILLES[1 + $i % 3], 'taille_bas' => FICHE_TAILLES[1 + ($i + 1) % 3], 'pointure' => (string)(36 + $i % 6), 'marques' => $hot ? 'Salons professionnels, soirées de lancement, accueil en entreprise (fictif).' : 'Démonstrations en grande distribution, dégustations, conseil en magasin (fictif).']);
    $if->execute([$aid, fiche_data_json($d), now()]);
    geo_cache('ban:' . mb_strtolower(trim(preg_replace('/\s+/', ' ', $p[3] . ' ' . $p[4] . ' ' . $p[5])) . '|' . $p[4]), fn() => [$p[6], $p[7], true]);
  }
  matricules();
  // Candidatures en attente (antichambre).
  foreach ([['Margaux', 'Leroy'], ['Ethan', 'Picard']] as $x) $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at) VALUES (?,?,'06 00 00 99 99',?, 'France', 1, 'attente', ?)")
    ->execute([$x[0], $x[1], strtolower($x[0]) . '@exemple.invalid', date('Y-m-d H:i:s', strtotime('-1 day'))]);

  // Devis, opérations et planning.
  $jours = fn(int $de, int $a, array $ok = [1, 2, 3, 4, 5, 6]) => array_values(array_filter(array_map('demo_j', range($de, $a)), fn($d) => in_array((int)date('N', strtotime($d)), $ok, true)));
  $presta = fn(string $role, string $poste, array $mags, array $dates, float $prix, string $deb = '10:00', string $fin = '19:00') => ['type' => 'presta', 'poste' => $poste, 'role' => $role, 'zone' => 'France',
    'magasins' => array_map(fn($n) => ['id' => $MID[$n], 'nom' => $n], $mags), 'debut' => $deb, 'fin' => $fin, 'pause' => 60, 'dates' => $dates, 'maj' => [], 'fnt' => [], 'prix_jour' => $prix];
  $devis = function (string $client, string $pole, string $objet, array $lignes, string $statut, int $age) use ($pdo, $CL) {
    $L = g_lignes($lignes); $t = g_totaux($L, '20'); $c = $pdo->query("SELECT raison, contact, adresse, cp, ville, pays, emails FROM g_clients WHERE id = " . (int)$CL[$client])->fetch();
    $pdo->prepare("INSERT INTO g_docs (type, numero, client_id, client, date_doc, validite, objet, zone, paiement, tva, lignes, mention, total_ht, total_tva, total_ttc, statut, par, created_at, pole, envoye_le, accepte_le, accepte_par)
      VALUES ('devis',?,?,?,?,?,?,'France','30j','20',?,'',?,?,?,?,?,?,?,?,?,?)")->execute([g_numero('devis'), $CL[$client], json_encode($c, JSON_UNESCAPED_UNICODE), demo_j(-$age), demo_j(30 - $age), $objet,
      json_encode($L, JSON_UNESCAPED_UNICODE), $t['ht'], $t['tva'], $t['ttc'], $statut, DEMO_DIRIGEANT['nom'], now(), $pole, $statut !== 'brouillon' ? demo_j(-$age) . ' 10:00:00' : null,
      $statut === 'accepte' ? demo_j(-$age + 2) . ' 15:12:00' : null, $statut === 'accepte' ? 'Bon pour accord en ligne' : '']);
    return g_doc((int)$pdo->lastInsertId());
  };
  $ops = [
    $devis('Maison Orélia Parfums', 'anim', 'Maison Orélia · animations fêtes de fin d\'année', [$presta('Animateur(trice) commercial(e)', 'Animation parfum', ['Beauté Lumière Lyon Part-Dieu', 'Hyper Rhône Lyon Bellecour', 'Grand Bazar Bron'], $jours(-12, 24, [3, 5, 6]), 210)], 'accepte', 25),
    $devis('Laboratoires Dermalis', 'anim', 'Dermalis · conseil dermocosmétique en pharmacie', [$presta('Démonstrateur(trice)', 'Conseil dermocosmétique', ['Pharmacie des Terreaux', 'Pharmacie Grand Ouest'], $jours(-6, 16, [2, 4, 6]), 195, '09:30', '18:30')], 'accepte', 18),
    $devis('Salon Mobilis', 'hot', 'Salon Mobilis · accueil du salon', [$presta('Chef d\'équipe', 'Coordination de l\'accueil', ['Parc des expositions de Lyon (hall 4)'], $jours(5, 8, [1, 2, 3, 4, 5, 6, 7]), 300, '08:00', '18:30'), $presta('Hôte(sse) d\'accueil bilingue', 'Accueil visiteurs', ['Parc des expositions de Lyon (hall 4)'], $jours(5, 8, [1, 2, 3, 4, 5, 6, 7]), 260, '08:30', '18:30'),
      $presta('Hôte(sse) vestiaire', 'Vestiaire', ['Parc des expositions de Lyon (hall 4)'], $jours(5, 8, [1, 2, 3, 4, 5, 6, 7]), 230, '09:00', '19:00')], 'accepte', 12),
    $devis('Nomade Audio', 'anim', 'Nomade Audio · lancement d\'un casque à Paris', [$presta('Démonstrateur(trice)', 'Démonstration produit', ['Beauté Lumière Paris Rivoli', 'Grand Magasin Haussmann'], $jours(14, 27, [4, 5, 6]), 240)], 'envoye', 3),
    $devis('Vinora', 'hot', 'Vinora · soirée dégustation clients', [$presta('Hôte(sse) d\'accueil', 'Accueil soirée', ['Centre de congrès de Lyon'], [demo_j(20)], 280, '17:00', '23:00')], 'brouillon', 1),
  ];
  // Affectation : chaque opération a son équipe ; quelques créneaux restent à pourvoir.
  $equipe = [[0, 1, 2, 3, 4, 5, 6, 7, 8], [9, 10, 11, 19], [15, 16, 17, 18, 20, 21, 0, 12]];
  foreach ([0, 1, 2] as $k) { $d = $ops[$k]; $n = 0;
    foreach (p_base_slots($d) as $s) { $n++; $libre = $s['date'] >= demo_j(3) && $n % 9 === 0;
      p_save_slot($d, $s, ['animatrice_id' => $libre ? null : $A[$equipe[$k][$n % count($equipe[$k])]], 'notifie_le' => $libre ? null : now()]); } }
  // Types d'opération, marques concurrentes (fictives), secteurs.
  foreach ([[$ops[0], 'Animation commerciale'], [$ops[1], 'Démonstration, dégustation'], [$ops[2], 'Salon, foire, exposition'], [$ops[3], 'Lancement produit'], [$ops[4], 'Soirée, gala']] as [$d, $t]) $pdo->prepare("UPDATE g_docs SET type_op = ? WHERE id = ?")->execute([$t, $d['id']]);
  $set->execute(['concurrents', implode("\n", DEMO_CONCURRENTS)]);
  $set->execute(['secteurs', json_encode([['nom' => 'Lyon Métropole', 'deps' => ['69']], ['nom' => 'Île-de-France', 'deps' => ['75', '92', '93', '94']]], JSON_UNESCAPED_UNICODE)]);
  // Accueil : soirée de lancement passée pour Maison Orélia (chef d'équipe et trois hôtes), avec ses rapports d'événement.
  $pdo->prepare("UPDATE g_clients SET marque_id = ? WHERE id = ?")->execute([$MQ['Maison Orélia'], $CL['Maison Orélia Parfums']]);
  $soir = $devis('Maison Orélia Parfums', 'hot', 'Maison Orélia · soirée de lancement presse', [$presta('Chef d\'équipe', 'Coordination et accueil VIP', ['Centre de congrès de Lyon'], [demo_j(-9), demo_j(-8)], 320, '17:00', '23:30'),
    $presta('Hôte(sse) VIP', 'Accueil des invités', ['Centre de congrès de Lyon'], [demo_j(-9), demo_j(-8)], 280, '17:30', '23:30'), $presta('Hôte(sse) vestiaire', 'Vestiaire', ['Centre de congrès de Lyon'], [demo_j(-9), demo_j(-8)], 240, '17:30', '23:30')], 'accepte', 40);
  $pdo->prepare("UPDATE g_docs SET type_op = 'Soirée, gala' WHERE id = ?")->execute([$soir['id']]);
  foreach (p_base_slots($soir) as $s) p_save_slot($soir, $s, ['animatrice_id' => $A[[20, 15, 16][$s['ligne']]], 'notifie_le' => now()]);
  require_once __DIR__ . '/evt_lib.php'; ev_schema();
  foreach ([[-9, true, 'Bonne', 320, 85, 300], [-8, false, 'Excellente', 410, 112, 380]] as $k => [$j, $val, $aff, $vis, $con, $doc]) { $tok = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO evenements_cr (token, animatrice_id, devis_id, date_evt, lieu, role, affluence, visiteurs, contacts, documents, deroulement, demandes, points_forts, ameliorer, incidents, equipe, recommandations, valide_le, valide_par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$tok, $A[20], $soir['id'], demo_j($j), 'Centre de congrès de Lyon', 'Chef d\'équipe', $aff, $vis, $con, $doc,
        'Installation à 17 h, briefing de l\'équipe à 17 h 30. Ouverture des portes à 19 h, pic d\'arrivées entre 19 h 30 et 20 h 30. Discours à 21 h, cocktail jusqu\'à 23 h.',
        'Accès au vestiaire, horaires du discours, possibilité de recevoir le coffret presse.', 'Accueil fluide malgré l\'affluence, liste des invités à jour, vestiaire bien organisé.',
        'Prévoir une deuxième table d\'émargement à l\'ouverture et une signalétique vers le vestiaire.', 'Rien à signaler.', 'Équipe complète et à l\'heure, tenue conforme. Une hôtesse affectée au vestiaire en renfort à 20 h.',
        $val ? "Ouvrir une deuxième file d'émargement entre 19 h et 20 h 30.\nAjouter deux panneaux vers le vestiaire.\nRenouveler la même équipe pour l'édition suivante." : null,
        $val ? demo_j($j + 1) . ' 10:00:00' : null, $val ? DEMO_DIRIGEANT['nom'] : '', demo_j($j) . ' 23:45:00']);
    $eid = (int)$pdo->lastInsertId(); $ph = $pdo->prepare("INSERT INTO photos (token, slot, fichier, created_at, evt_id) VALUES (?,?,?,?,?)");
    foreach ([1, 2, 3] as $z) $ph->execute([$tok, 'evt', demo_photo(900 + $k * 10 + $z, false, true), now(), $eid]); }
  // Salon : stand Maison Orélia trois jours (chef d'équipe, hôtesse d'accueil, hôte animation du jeu-concours), rapports validés.
  $salon = $devis('Maison Orélia Parfums', 'hot', 'Maison Orélia · stand au salon Beauté & Bien-être', [$presta('Chef d\'équipe', 'Coordination du stand', ['Parc des expositions de Lyon (hall 4)'], [demo_j(-23), demo_j(-22), demo_j(-21)], 300, '09:00', '19:00'),
    $presta('Hôte(sse) d\'accueil', 'Accueil du stand et qualification des contacts', ['Parc des expositions de Lyon (hall 4)'], [demo_j(-23), demo_j(-22), demo_j(-21)], 250, '09:30', '19:00'),
    $presta('Hôte(sse) d\'accueil', 'Jeu-concours et remise des échantillons', ['Parc des expositions de Lyon (hall 4)'], [demo_j(-23), demo_j(-22), demo_j(-21)], 250, '09:30', '19:00')], 'accepte', 50);
  $pdo->prepare("UPDATE g_docs SET type_op = 'Salon, foire' WHERE id = ?")->execute([$salon['id']]);
  foreach (p_base_slots($salon) as $s) { try { p_save_slot($salon, $s, ['animatrice_id' => $A[[20, 15, 16][$s['ligne']]], 'notifie_le' => now()]); } catch (Throwable $e) {} }
  $jS = [[-23, 'Moyenne', 540, 96, 450, 'Montage terminé à 9 h 15, briefing à 9 h 30. Matinée calme (public professionnel), forte montée de 14 h à 17 h. Jeu-concours lancé à 11 h.',
      'Prix des coffrets, disponibilité en boutique, date de sortie du nouveau parfum.', 'Stand bien visible depuis l\'allée centrale, échantillons appréciés.', 'Le terminal de saisie des contacts a été lent en début d\'après-midi.', 'Rien à signaler.'],
    [-22, 'Excellente', 1180, 214, 1050, 'Journée grand public, file d\'attente au jeu-concours de 14 h à 16 h 30. Réassort d\'échantillons à 15 h.',
      'Points de vente proches, programme de fidélité, conseils de choix pour un cadeau.', 'Équipe réactive, file d\'attente bien gérée, 214 contacts qualifiés.', 'Prévoir un second stock d\'échantillons sur le stand et une tablette de plus.', 'Un visiteur mécontent de ne pas avoir gagné, géré par la cheffe d\'équipe.'],
    [-21, 'Bonne', 860, 151, 780, 'Fréquentation soutenue jusqu\'à 16 h puis baisse. Tirage du jeu-concours à 17 h, démontage à 19 h.',
      'Retour du salon l\'an prochain, possibilité de recevoir les nouveautés.', 'Tirage au sort très suivi, bonne couverture photo du stand.', 'Signalétique du stand peu visible depuis le hall 5.', 'Rien à signaler.']];
  foreach ($jS as $k => [$j, $aff, $vis, $con, $doc, $der, $dem, $pf, $am, $inc]) { $tok = bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO evenements_cr (token, animatrice_id, devis_id, date_evt, lieu, role, affluence, visiteurs, contacts, documents, deroulement, demandes, points_forts, ameliorer, incidents, equipe, recommandations, valide_le, valide_par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
      ->execute([$tok, $A[20], $salon['id'], demo_j($j), 'Parc des expositions de Lyon (hall 4)', 'Chef d\'équipe', $aff, $vis, $con, $doc, $der, $dem, $pf, $am, $inc, 'Équipe de trois au complet, tenue conforme, relais pause déjeuner organisé.',
        null, demo_j($j + 1) . ' 10:00:00', DEMO_DIRIGEANT['nom'], demo_j($j) . ' 19:30:00']);
    $eid = (int)$pdo->lastInsertId(); $ph = $pdo->prepare("INSERT INTO photos (token, slot, fichier, created_at, evt_id) VALUES (?,?,?,?,?)");
    foreach ([1, 2, 3] as $z) $ph->execute([$tok, 'evt', demo_photo(950 + $k * 10 + $z, false, true), now(), $eid]); }
  // Événementiel : plusieurs opérations passées (street marketing, sampling, congrès, inauguration, convention), équipes complètes et comptes rendus du chef d'équipe.
  // Merchandising : références suivies, tournées facturées à la visite ou à la journée avec kilomètres, visites avec photos avant/après.
  require_once __DIR__ . '/merch_lib.php'; mc_schema();
  $pdo->prepare("UPDATE g_clients SET marque_id = ? WHERE id = ?")->execute([$MQ['Dermalis'], $CL['Laboratoires Dermalis']]);
  $pdo->prepare("UPDATE g_clients SET marque_id = ? WHERE id = ?")->execute([$MQ['Nomade Audio'], $CL['Nomade Audio']]);
  $ir = $pdo->prepare("INSERT INTO merch_refs (marque_id, nom, ean, gamme, prix_conseille, ordre, actif) VALUES (?,?,?,?,?,?,1)"); $RF = [];
  // Catalogues fictifs : gencods EAN-13 valides en préfixe 2 (réservé à un usage interne, ne correspond à aucun produit du commerce).
  $CAT = ['Dermalis' => [['Crème hydratante peaux sèches 50 ml', 'Soin visage', 18.90], ['Sérum apaisant 30 ml', 'Soin visage', 24.50], ['Fluide matifiant 40 ml', 'Soin visage', 17.50], ['Contour des yeux 15 ml', 'Soin visage', 21.00],
      ['Baume lèvres réparateur', 'Lèvres', 6.90], ['Gel nettoyant douceur 200 ml', 'Hygiène', 11.20], ['Eau micellaire 400 ml', 'Hygiène', 12.90], ['Écran solaire SPF 50 50 ml', 'Solaire', 19.90],
      ['Lait solaire enfant SPF 50 200 ml', 'Solaire', 22.50], ['Crème mains intense 75 ml', 'Corps', 8.50], ['Baume corps nutritif 400 ml', 'Corps', 16.90], ['Déodorant peaux sensibles', 'Corps', 7.40]],
    'Nomade Audio' => [['Casque sans fil Voyage', 'Casques', 149.00], ['Casque enfant volume limité', 'Casques', 49.00], ['Écouteurs Sport', 'Écouteurs', 79.00], ['Écouteurs Essentiel', 'Écouteurs', 39.00],
      ['Enceinte de poche', 'Enceintes', 59.00], ['Enceinte étanche Plage', 'Enceintes', 89.00], ['Câble de charge tressé', 'Accessoires', 19.00], ['Étui de transport', 'Accessoires', 25.00]],
    'Maison Orélia' => [['Eau de parfum Orélia 30 ml', 'Parfum', 62.00], ['Eau de parfum Orélia 50 ml', 'Parfum', 89.00], ['Eau de parfum Orélia 100 ml', 'Parfum', 125.00], ['Brume parfumée cheveux', 'Parfum', 34.00],
      ['Fond de teint fluide', 'Maquillage', 39.00], ['Correcteur éclat', 'Maquillage', 27.00], ['Palette regard', 'Maquillage', 45.00], ['Mascara volume', 'Maquillage', 29.00], ['Rouge à lèvres satiné', 'Maquillage', 29.00], ['Vernis à ongles', 'Maquillage', 19.00]]];
  $bi = 0; foreach ($CAT as $mq => $L) { $bi++;
    foreach ($L as $k => [$n, $g, $px]) { $d12 = '2099' . sprintf('%02d%06d', $bi, $k + 1); $ir->execute([$MQ[$mq], $n, $d12 . mc_ean_cle($d12), $g, $px, $k]); $RF[$mq][] = ['id' => (int)$pdo->lastInsertId(), 'prix' => $px]; } }
  $mc = function (string $client, string $mq, string $objet, string $type, string $poste, array $pts, array $j, string $unite, float $prix, float $km, float $pkm, int $qui, int $graine) use ($pdo, $devis, $presta, $A, $RF) {
    $dates = array_map('demo_j', $j); $l = $presta('Merchandiseur(se)', $poste, $pts, $dates, $prix, '08:30', '16:30') + ['unite' => $unite, 'tournee' => 1, 'km' => $km, 'prix_km' => $pkm];
    $d = $devis($client, 'anim', $objet, [$l], 'accepte', 30 - min($j)); $pdo->prepare("UPDATE g_docs SET type_op = ? WHERE id = ?")->execute([$type, $d['id']]);
    foreach (p_base_slots($d) as $sl) { try { p_save_slot($d, $sl, ['animatrice_id' => $A[$qui], 'notifie_le' => now()]); } catch (Throwable $e) {} }
    $iv = $pdo->prepare("INSERT INTO merch_visites (token, animatrice_id, devis_id, marque_id, magasin, ville, date_visite, arrivee, depart, data, valide_le, valide_par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ph = $pdo->prepare("INSERT INTO photos (token, slot, fichier, created_at, merch_id, client) VALUES (?,?,?,?,?,1)");
    $passees = array_values(array_filter($dates, fn($x) => $x < date('Y-m-d'))); mt_srand($graine);
    foreach ($passees as $n => $dt) { $h = 8 * 60 + 40;
      foreach ($pts as $k => $p) { $refs = []; foreach ($RF[$mq] as $r) { $x = mt_rand(0, 99); $st = $x < 8 ? 'absent' : ($x < 20 ? 'rupture' : 'present');
          $refs[] = ['id' => $r['id'], 'statut' => $st, 'prix' => $st === 'absent' ? null : round($r['prix'] * [1, 1, 1, 0.97, 1.03, 1.05, 0.9][mt_rand(0, 6)], 2), 'facings' => $st === 'absent' ? null : mt_rand(1, 6),
            'etiquette' => mt_rand(0, 9) ? 1 : 0, 'promo' => mt_rand(0, 6) ? 0 : 1, 'promo_txt' => '']; }
        foreach ($refs as &$r) if ($r['promo']) $r['promo_txt'] = ['-20 % sur la gamme', '2e à -50 %', 'Coffret offert'][mt_rand(0, 2)]; unset($r);
        $tot = mt_rand(18, 40) * 10; $D = mc_clean(['refs' => $refs, 'taches' => array_slice(MC_TACHES, mt_rand(0, 3), mt_rand(3, 6)), 'lineaire_marque' => round($tot * mt_rand(12, 30) / 100), 'lineaire_total' => $tot, 'tablettes' => mt_rand(2, 5),
          'emplacement' => MC_EMPLACEMENTS[mt_rand(0, 3)], 'hauteur' => MC_HAUTEURS[mt_rand(0, 2)], 'plv_presente' => mt_rand(0, 2) ? 1 : 0, 'plv_posee' => mt_rand(0, 1), 'promo_en_place' => mt_rand(0, 2) ? 0 : 1,
          'reserve' => ['ok', 'ok', 'faible', 'vide', 'inconnu'][mt_rand(0, 4)], 'concurrence' => mt_rand(0, 2) ? '' : 'Une marque concurrente en tête de gondole avec une offre de lancement.',
          'interlocuteur' => ['Mme Perrin', 'M. Lopez', 'Mme Caron', 'M. Diallo'][mt_rand(0, 3)], 'interlocuteur_fonction' => ['Pharmacienne titulaire', 'Chef de rayon', 'Préparatrice', 'Directeur'][mt_rand(0, 3)],
          'commentaire' => mt_rand(0, 3) ? '' : 'Commande de réassort à prévoir pour la semaine prochaine.'], array_map(fn($r) => ['id' => $r['id']], $RF[$mq]));
        $tok = bin2hex(random_bytes(16)); $dur = mt_rand(35, 70); $ville = (string)$pdo->query("SELECT ville FROM magasins WHERE nom = " . $pdo->quote($p))->fetchColumn();
        $iv->execute([$tok, $A[$qui], $d['id'], $RF[$mq] ? (function () use ($pdo, $mq) { return (int)$pdo->query("SELECT id FROM marques WHERE nom = " . $pdo->quote($mq))->fetchColumn(); })() : null, $p, $ville, $dt,
          sprintf('%02d:%02d', intdiv($h, 60), $h % 60), sprintf('%02d:%02d', intdiv($h + $dur, 60), ($h + $dur) % 60), json_encode($D, JSON_UNESCAPED_UNICODE), $n < count($passees) - 1 ? date('Y-m-d', strtotime($dt . ' +1 day')) . ' 09:30:00' : null, $n < count($passees) - 1 ? DEMO_DIRIGEANT['nom'] : '', $dt . ' 17:00:00']);
        $vid = (int)$pdo->lastInsertId(); $h += $dur + 25;
        foreach (['avant', 'apres'] as $sl) $ph->execute([$tok, $sl, demo_photo($graine + $n * 37 + $k * 5 + ($sl === 'apres' ? 1 : 0), false, false, $sl), now(), $vid]); }
    }
    return $d;
  };
  $mc('Laboratoires Dermalis', 'Dermalis', 'Dermalis · merchandising en pharmacie, tournée hebdomadaire', 'Merchandising : implantation et réassort', 'Implantation, réassort et relevés',
    ['Pharmacie des Terreaux', 'Pharmacie de la Croix-Rousse', 'Grande Pharmacie de Villeurbanne', 'Pharmacie Saxe-Gambetta'], [-41, -34, -27, -20, -13, -6, 1, 8, 15], 'visite', 55, 24.5, 0.6, 30, 1100);
  $mc('Maison Orélia Parfums', 'Maison Orélia', 'Maison Orélia · nettoyage et entretien des meubles maquillage', 'Merchandising : nettoyage et entretien des meubles', 'Nettoyage des meubles et remplacement des testeurs',
    ['Beauté Lumière Lyon Part-Dieu', 'Hyper Rhône Lyon Bellecour', 'Grand Bazar Bron'], [-37, -23, -9, 5, 19], 'jour', 230, 31, 0.55, 31, 1200);
  $mc('Nomade Audio', 'Nomade Audio', 'Nomade Audio · relevés de prix et de linéaire', 'Merchandising : relevés de prix et de linéaire', 'Relevés de prix, linéaire et PLV',
    ['Hyper Rhône Lyon Bellecour', 'Grand Bazar Bron'], [-14, -7, 0, 12], 'visite', 45, 12, 0.6, 16, 1300);
  require_once __DIR__ . '/extras_lib.php'; require_once __DIR__ . '/contrats_lib.php'; x_schema(); c_schema();
  $evt = function (string $client, string $objet, string $type, string $lieu, array $j, array $eq, int $chef, array $R, int $graine) use ($pdo, $devis, $presta, $A) {
    $dates = array_map('demo_j', $j); $lignes = []; foreach ($eq as [$role, $poste, $prix, $deb, $fin]) $lignes[] = $presta($role, $poste, [$lieu], $dates, $prix, $deb, $fin);
    $d = $devis($client, 'hot', $objet, $lignes, 'accepte', 30 - min($j)); $pdo->prepare("UPDATE g_docs SET type_op = ? WHERE id = ?")->execute([$type, $d['id']]);
    foreach (p_base_slots($d) as $sl) { try { p_save_slot($d, $sl, ['animatrice_id' => $A[$eq[$sl['ligne']][5]], 'notifie_le' => now()]); } catch (Throwable $e) {} }
    $ph = $pdo->prepare("INSERT INTO photos (token, slot, fichier, created_at, evt_id) VALUES (?,?,?,?,?)");
    foreach ($R as $k => $r) { if ($r === null) continue; [$aff, $vis, $con, $doc, $der, $dem, $pf, $am, $inc, $equ, $val] = $r; $tok = bin2hex(random_bytes(16));
      $pdo->prepare("INSERT INTO evenements_cr (token, animatrice_id, devis_id, date_evt, lieu, role, affluence, visiteurs, contacts, documents, deroulement, demandes, points_forts, ameliorer, incidents, equipe, recommandations, valide_le, valide_par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$tok, $A[$chef], $d['id'], $dates[$k], $lieu, $eq[0][0], $aff, $vis, $con, $doc, $der, $dem, $pf, $am, $inc, $equ, null, $val ? date('Y-m-d', strtotime($dates[$k] . ' +1 day')) . ' 10:00:00' : null, $val ? DEMO_DIRIGEANT['nom'] : '', $dates[$k] . ' 20:30:00']);
      $eid = (int)$pdo->lastInsertId(); foreach ([1, 2, 3] as $z) $ph->execute([$tok, 'evt', demo_photo($graine + $k * 10 + $z, false, true), now(), $eid]); }
    // Opération passée : contrats signés, DPAE faites, attestations France Travail établies.
    foreach (array_unique(array_map(fn($x) => $A[$x[5]], $eq)) as $aid) { try { $c = c_envoyer($aid, (int)$d['id']); $S = $c['salarie'];
      $pdo->prepare("UPDATE contrats SET statut = 'signe', signe_le = ?, signe_nom = ?, signe_mention = 'Lu et approuvé', signe_ip = '0.0.0.0', empreinte = ? WHERE id = ?")->execute([date('Y-m-d', strtotime(min($dates) . ' -3 day')) . ' 18:00:00', trim($S['prenom'] . ' ' . $S['nom']), hash('sha256', 'demo' . $c['id']), $c['id']]); } catch (Throwable $e) {}
      $pdo->prepare("INSERT IGNORE INTO contrats_fin (animatrice_id, devis_id, ft_le, par) VALUES (?,?,?,?)")->execute([$aid, $d['id'], date('Y-m-d', strtotime(max($dates) . ' +2 day')), DEMO_DIRIGEANT['nom']]); }
  };
  $evt('Nomade Audio', 'Nomade Audio · street marketing, distribution de flyers et jeu-concours', 'Street marketing, sampling', 'Rue de la République (zone piétonne)', [-31, -30],
    [['Chef d\'équipe', 'Coordination de l\'équipe street', 260, '10:00', '19:00', 22], ['Hôte(sse) street marketing', 'Distribution de flyers', 190, '11:00', '19:00', 23], ['Hôte(sse) street marketing', 'Distribution de flyers', 190, '11:00', '19:00', 24],
      ['Distributeur(trice) de flyers', 'Distribution de flyers', 170, '11:00', '19:00', 25], ['Animateur(trice) sampling', 'Jeu-concours (QR code)', 200, '11:00', '19:00', 26]], 22,
    [['Bonne', 1850, 236, 3400, 'Briefing à 10 h 30 devant le magasin partenaire. Distribution en binômes sur la zone piétonne, jeu-concours sur tablette au point fixe. Pic de passage de 12 h à 14 h et après 17 h.',
        'Prix du casque, date de disponibilité, existence d\'une version enfant.', 'Bon accueil du public, le QR code du jeu-concours fonctionne bien sur téléphone.', 'Flyers épuisés à 17 h 30 : prévoir 20 % de stock en plus.', 'Rien à signaler.', 'Cinq personnes, tenue à la marque, relais pause organisé.', true],
      ['Excellente', 2600, 341, 4200, 'Samedi : très forte affluence toute la journée. Le point fixe a été déplacé à 14 h vers l\'entrée du métro pour suivre le flux.',
        'Points de vente proches, prix promotionnel, possibilité de tester le casque.', '341 participations au jeu-concours, beaucoup de demandes d\'essai.', 'Prévoir un casque de démonstration sur le point fixe.', 'Contrôle de la police municipale vers 15 h : autorisation présentée, aucun problème.', 'Équipe au complet, très dynamique.', true]], 960);
  $evt('Vinora', 'Vinora · sampling en gare, dégustation de jus de raisin', 'Street marketing, sampling', 'Gare de Lyon Part-Dieu (hall 1)', [-38, -37],
    [['Chef d\'équipe', 'Coordination du sampling', 250, '07:00', '14:00', 21], ['Animateur(trice) sampling', 'Dégustation et remise d\'échantillons', 190, '07:00', '14:00', 29], ['Animateur(trice) sampling', 'Dégustation et remise d\'échantillons', 190, '07:00', '14:00', 15]], 21,
    [['Excellente', 2100, 120, 1900, 'Installation du stand à 6 h 45, flux continu de voyageurs de 7 h 30 à 9 h 30. Réassort des gobelets à 9 h.', 'Où acheter le produit, ingrédients, sans sucres ajoutés ?', 'Très bon emplacement près des quais, échantillons partis vite.', 'Prévoir une poubelle supplémentaire près du stand.', 'Rien à signaler.', 'Trois personnes, tenue conforme.', true],
      ['Bonne', 1600, 95, 1500, 'Matinée plus calme (vacances scolaires), meilleur créneau entre 8 h et 9 h.', 'Prix, format familial.', 'Accueil souriant, bonnes explications produit.', 'Décaler le créneau d\'une heure (8 h à 15 h).', 'Rien à signaler.', 'Équipe complète.', false]], 980);
  $evt('Laboratoires Dermalis', 'Dermalis · congrès de dermatologie, accueil des congressistes', 'Congrès, séminaire', 'Centre de congrès de Lyon', [-17, -16, -15],
    [['Chef d\'équipe', 'Coordination de l\'accueil congrès', 300, '07:30', '19:00', 17], ['Chef de service', 'Vestiaire et badges', 260, '07:30', '19:00', 27], ['Hôte(sse) d\'accueil bilingue', 'Accueil et orientation', 240, '07:30', '18:30', 18],
      ['Hôte(sse) d\'accueil bilingue', 'Accueil et orientation', 240, '07:30', '18:30', 19], ['Hôte(sse) vestiaire', 'Vestiaire', 220, '07:30', '19:00', 23], ['Personnel annexe (manutention, badges, logistique)', 'Badges, signalétique et manutention', 190, '07:00', '18:00', 28]], 17,
    [['Excellente', 1150, 640, 1150, 'Ouverture des inscriptions à 8 h, file de 25 minutes entre 8 h 15 et 8 h 45. Plénière à 9 h, ateliers l\'après-midi.', 'Wifi, salle des ateliers, horaires des pauses, consigne bagages.', 'Badges préparés la veille, orientation efficace vers les ateliers.', 'Ouvrir un troisième poste d\'émargement de 8 h à 9 h.', 'Imprimante à badges en panne 20 minutes, badges écrits à la main en attendant.', 'Six personnes, accueil bilingue apprécié des intervenants étrangers.', true],
      ['Bonne', 980, 410, 980, 'Deuxième jour plus fluide, pic d\'arrivées à 8 h 30. Dîner de gala à 20 h (accueil assuré par le chef d\'équipe).', 'Navettes vers l\'hôtel, programme du dîner.', 'Vestiaire bien tenu malgré l\'affluence du soir.', 'Prévoir des panneaux vers le vestiaire au niveau 2.', 'Rien à signaler.', 'Équipe complète, relève du soir assurée.', true],
      ['Moyenne', 620, 180, 620, 'Dernière journée, départs étalés à partir de 15 h. Démontage de la signalétique à 17 h.', 'Attestations de présence, objets trouvés.', 'Restitution du vestiaire sans erreur.', 'Libérer une personne dès 16 h (activité faible).', 'Rien à signaler.', 'Cinq personnes l\'après-midi.', false]], 1000);
  $evt('Maison Orélia Parfums', 'Maison Orélia · inauguration de la boutique de Villeurbanne', 'Lancement, inauguration', 'Maison Clément Villeurbanne', [-46],
    [['Chef d\'équipe', 'Coordination de la soirée', 300, '17:00', '23:00', 20], ['Maître(sse) d\'hôtel', 'Service du cocktail', 280, '17:30', '23:00', 26], ['Hôte(sse) VIP', 'Accueil des invités', 260, '17:30', '23:00', 24],
      ['Hôte(sse) d\'accueil', 'Liste des invités', 230, '17:30', '23:00', 25], ['Personnel annexe (manutention, badges, logistique)', 'Logistique et vestiaire', 180, '16:30', '23:30', 28]], 20,
    [['Excellente', 280, 95, 260, 'Ouverture des portes à 18 h 30, discours à 19 h 30, cocktail jusqu\'à 22 h 30. 280 invités pour 250 attendus.', 'Horaires d\'ouverture de la boutique, offre de bienvenue.', 'Accueil personnalisé des invités VIP, coffrets remis à la sortie.', 'Prévoir 10 % d\'invités en plus que la liste.', 'Rien à signaler.', 'Cinq personnes, tenue noire.', true]], 1020);
  $evt('Nomade Audio', 'Nomade Audio · convention annuelle des revendeurs', 'Événement d\'entreprise', 'Siège Nomade Audio Lyon', [-53],
    [['Chef d\'équipe', 'Coordination', 280, '08:00', '18:00', 22], ['Hôte(sse) d\'accueil en entreprise', 'Accueil et émargement', 220, '08:00', '18:00', 29], ['Standardiste', 'Standard et orientation', 200, '08:00', '18:00', 28]], 22,
    [['Bonne', 160, 160, 160, 'Accueil des 160 revendeurs de 8 h 30 à 9 h 15, plénière, déjeuner, ateliers.', 'Codes wifi, horaires des navettes.', 'Émargement rapide grâce à la liste préparée.', 'Rien de particulier.', 'Rien à signaler.', 'Trois personnes.', true]], 1040);
  // Historique d'une année en CIDD : une personne a passé les 500 heures il y a peu (CDII à proposer), une autre en approche.
  $hist = $devis('Maison Orélia Parfums', 'anim', 'Maison Orélia · animations week-end de l\'année', [$presta('Animateur(trice) commercial(e)', 'Animation parfum', ['Maison Clément Villeurbanne'], $jours(-235, -15, [3, 6]), 210),
    $presta('Animateur(trice) commercial(e)', 'Animation parfum', ['Grand Bazar Bron'], array_values(array_unique(array_merge($jours(-340, -5, [6]), $jours(-60, -5, [3])))), 210)], 'accepte', 345);
  foreach (p_base_slots($hist) as $s) p_save_slot($hist, $s, ['animatrice_id' => $A[$s['ligne'] === 0 ? 13 : 14], 'notifie_le' => now()]);
  foreach ([13, 14] as $x) { try { $c = c_envoyer($A[$x], (int)$hist['id']); $S = $c['salarie'];
    $pdo->prepare("UPDATE contrats SET statut = 'signe', signe_le = ?, signe_nom = ?, signe_mention = 'Lu et approuvé', signe_ip = '0.0.0.0', empreinte = ? WHERE id = ?")->execute([demo_j(-340) . ' 09:00:00', trim($S['prenom'] . ' ' . $S['nom']), hash('sha256', 'demo' . $c['id']), $c['id']]); } catch (Throwable $e) {} }
  // DPAE déjà faites pour les opérations commencées (seules restent à faire celles des prochains jours).
  $pdo->prepare("INSERT IGNORE INTO contrats_dpae (animatrice_id, devis_id, fait_le, pour_le, numero, par, created_at) SELECT m.animatrice_id, m.devis_id, DATE_SUB(MIN(m.date_mission), INTERVAL 2 DAY), MIN(m.date_mission), 'DEMO', ?, NOW()
    FROM missions m WHERE m.animatrice_id IS NOT NULL AND m.devis_id IS NOT NULL GROUP BY m.animatrice_id, m.devis_id HAVING MIN(m.date_mission) < CURDATE()")->execute([DEMO_DIRIGEANT['nom']]);
  // Zone touristique internationale : le grand magasin du boulevard Haussmann (Paris) en fait partie.
  require_once __DIR__ . '/regles_lib.php'; rg_schema(); $pdo->prepare("UPDATE magasins SET zti = 1 WHERE nom = ?")->execute(['Grand Magasin Haussmann']);
  // Prise de poste ce matin : la plupart à l'heure, un retard, un non signalé.
  $st = $pdo->prepare("SELECT id, animatrice_id, debut FROM missions WHERE date_mission = ? AND animatrice_id IS NOT NULL ORDER BY id"); $st->execute([demo_j(0)]); $i = 0;
  foreach ($st->fetchAll() as $m) { if ($i++ === 2) continue; $h = date('H:i', strtotime($m['debut']) + ($i === 4 ? 17 * 60 : -mt_rand(3, 12) * 60));
    $pdo->prepare("INSERT INTO prises_poste (mission_id, animatrice_id, date_mission, le, heure, ecart_min, precision_m, distance_m) VALUES (?,?,?,?,?,?,?,?)")
      ->execute([$m['id'], $m['animatrice_id'], demo_j(0), demo_j(0) . ' ' . $h . ':00', $h, $i === 4 ? 17 : 0, 12, 35]); }
  // Contrats : envoyés, la plupart signés dans l'appli.
  $i = 0;
  foreach ([0, 1, 2] as $k) foreach (array_unique(array_map(fn($x) => $A[$x], $equipe[$k])) as $aid) {
    try { $c = c_envoyer($aid, (int)$ops[$k]['id']); } catch (Throwable $e) { continue; }
    if ($i++ % 5 !== 4) { $S = $c['salarie']; $pdo->prepare("UPDATE contrats SET statut = 'signe', signe_le = ?, signe_nom = ?, signe_mention = 'Lu et approuvé', signe_ip = '0.0.0.0', empreinte = ? WHERE id = ?")
      ->execute([date('Y-m-d H:i:s', strtotime('-' . (1 + $i % 6) . ' days')), trim($S['prenom'] . ' ' . $S['nom']), hash('sha256', 'demo' . $c['id']), $c['id']]); }
  }
  // Comptes rendus avec photos et chiffres des journées passées (opération Maison Orélia et Dermalis).
  $q = $pdo->prepare("SELECT m.*, mg.id AS mid, mg.ville FROM missions m JOIN magasins mg ON mg.nom = m.magasin WHERE m.devis_id IN (?, ?) AND m.animatrice_id IS NOT NULL AND m.date_mission < ? ORDER BY m.date_mission");
  $q->execute([$ops[0]['id'], $ops[1]['id'], demo_j(0)]); $g = 1;
  foreach ($q->fetchAll() as $m) {
    $tok = bin2hex(random_bytes(16)); $mq = (int)$m['devis_id'] === (int)$ops[0]['id'] ? $MQ['Maison Orélia'] : $MQ['Dermalis'];
    $pdo->prepare("INSERT INTO rapports (token, animatrice_id, zone, magasin_id, magasin, ville, devise, date_animation, frequentation, concurrence, offres, staff, faits, created_at, valide_le, valide_par) VALUES (?,?,'France',?,?,?,'EUR',?,?,?,?,?,?,?,?,?)")
      ->execute([$tok, $m['animatrice_id'], $m['mid'], $m['magasin'], $m['ville'], $m['date_mission'], FREQUENTATION[1 + $g % 3], json_encode(array_slice(DEMO_CONCURRENTS, $g % 4, 2), JSON_UNESCAPED_UNICODE),
        'Coffret offert dès 80 € d\'achat.', 'Équipe du magasin accueillante, bon relais en caisse.', ['Très bon accueil des clientes sur la nouvelle fragrance.', 'Rupture du format 50 ml en fin d\'après-midi.', 'Beaucoup de demandes de conseil, plusieurs ventes de coffrets.'][$g % 3],
        $m['date_mission'] . ' 19:20:00', $m['date_mission'] . ' 21:00:00', DEMO_DIRIGEANT['nom']]);
    $rid = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO rapport_marques (rapport_id, marque_id, ca, objectif, reactions) VALUES (?,?,?,?,?)")->execute([$rid, $mq, 380 + ($g * 137) % 900, 900, 'Clientes curieuses, bon taux de conversion après test.']);
    $rmid = (int)$pdo->lastInsertId();
    $ph = $pdo->prepare("INSERT INTO photos (token, rapport_id, rapport_marque_id, slot, fichier, created_at) VALUES (?,?,?,?,?,?)");
    $ph->execute([$tok, $rid, null, 'animatrice', demo_photo($g * 7, true), now()]); $ph->execute([$tok, $rid, $rmid, 'm0', demo_photo($g * 7 + 1, false), now()]);
    $g++;
  }
  // Espace marques : un compte client (Maison Orélia).
  $pdo->prepare("INSERT INTO comptes_clients (nom, societe, email, pass_hash, marques, zones, droits, actif, created_at) VALUES (?,?,?,?,?,'France','ca,photos,cr,telecharger,pvp',1,?)")
    ->execute([DEMO_MARQUE['nom'], DEMO_MARQUE['societe'], DEMO_MARQUE['email'], password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), (string)$MQ['Maison Orélia'], now()]);
  $pdo->prepare("UPDATE g_clients SET emails = ? WHERE id = ?")->execute([DEMO_MARQUE['email'], $CL['Maison Orélia Parfums']]);
  // Facture d'auto-entrepreneur reçue (Lucas, statut auto-entrepreneur).
  require_once __DIR__ . '/ae_lib.php'; ae_schema();
  $pdo->prepare("INSERT INTO factures_ae (animatrice_id, numero, montant, periode, devis_id, commentaire, fichier, nom, mime, created_at) VALUES (?,?,?,?,?,?,'','facture-demo.pdf','application/pdf',?)")
    ->execute([$A[15], 'F-2026-031', 560.00, date('Y-m', strtotime('-1 month')), $soir['id'], 'Soirée de lancement, deux jours', now()]);
  // Notes de frais à valider et disponibilités.
  require_once __DIR__ . '/extras_lib.php'; x_schema();
  foreach ([[0, 'parking', 12.5, 'Parking centre commercial'], [3, 'transport', 4.2, 'Ticket de tram'], [17, 'repas', 14.9, 'Déjeuner sur le salon']] as [$x, $type, $mt, $com])
    $pdo->prepare("INSERT INTO ndf (animatrice_id, jour, type, montant, devis_id, commentaire, statut, created_at) VALUES (?,?,?,?,?,?,'a_valider',?)")->execute([$A[$x], demo_j(-2), $type, $mt, $ops[0]['id'], $com, now()]);
  foreach ($A as $i => $aid) foreach (range(1, 14) as $j) if (($i + $j) % 4) $pdo->prepare("INSERT IGNORE INTO dispos (animatrice_id, jour, etat, modifie_le) VALUES (?,?,?,?)")->execute([$aid, demo_j($j), ($i + $j) % 5 ? 'o' : 'n', now()]);
  require_once __DIR__ . '/demo_conciergerie.php'; cz_demo_seed(); // ORVIA : conciergerie fictive au Maroc
  unset($_SESSION['admin_nom']);
}

/** Entrée en un clic : rôle « direction », « equipe » (appli mobile) ou « marque » (espace client). */
function demo_entrer(string $role): string {
  if (demo_prefixe() === '') return 'demo.php';
  $pdo = db(); $p = $_SESSION['demo_p']; $lead = $_SESSION['demo_lead'] ?? 0; session_regenerate_id(true);
  foreach (['admin', 'admin_id', 'admin_email', 'admin_nom', 'droits', 'anim', 'client_id', 'client_apercu'] as $k) unset($_SESSION[$k]);
  $_SESSION['demo_p'] = $p; $_SESSION['demo_lead'] = $lead;
  if ($role === 'equipe') {
    // ORVIA : de préférence l'agent qui a une mission aujourd'hui.
    try { $c = (int)$pdo->query("SELECT animatrice_id FROM c_missions WHERE animatrice_id IS NOT NULL AND date >= CURDATE() ORDER BY date, id LIMIT 1")->fetchColumn(); if ($c) { $_SESSION['anim'] = $c; return 'index.php'; } } catch (Throwable $e) {}
    // De préférence une personne qui a une mission à venir et un rapport d'événement à rédiger (hôtessariat des 30 derniers jours).
    $id = (int)$pdo->query("SELECT m.animatrice_id FROM missions m WHERE m.animatrice_id IS NOT NULL AND m.date_mission >= CURDATE() AND m.animatrice_id IN (SELECT m2.animatrice_id FROM missions m2 JOIN g_docs d ON d.id = m2.devis_id
      WHERE d.pole = 'hot' AND m2.date_mission BETWEEN CURDATE() - INTERVAL 30 DAY AND CURDATE() - INTERVAL 1 DAY AND m2.animatrice_id NOT IN (SELECT animatrice_id FROM evenements_cr))
      ORDER BY EXISTS (SELECT 1 FROM missions m3 JOIN g_docs d3 ON d3.id = m3.devis_id WHERE m3.animatrice_id = m.animatrice_id AND m3.date_mission = CURDATE() AND d3.type_op LIKE 'Merchandising%') DESC, m.date_mission, m.id LIMIT 1")->fetchColumn()
      ?: (int)$pdo->query("SELECT animatrice_id FROM missions WHERE animatrice_id IS NOT NULL AND date_mission >= CURDATE() ORDER BY date_mission, id LIMIT 1")->fetchColumn();
    $_SESSION['anim'] = $id ?: (int)$pdo->query("SELECT id FROM animatrices WHERE statut = 'actif' ORDER BY id LIMIT 1")->fetchColumn(); return 'index.php'; }
  if ($role === 'proprietaire') {
    require_once __DIR__ . '/conciergerie_lib.php'; $t = cz_jeton(); $pid = (int)$pdo->query("SELECT id FROM c_proprietaires ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare("UPDATE c_proprietaires SET jeton_hash = ? WHERE id = ?")->execute([cz_hash($t), $pid]); return 'proprietaire.php#' . $t; }
  if ($role === 'marque') { $_SESSION['client_id'] = (int)$pdo->query("SELECT id FROM comptes_clients ORDER BY id LIMIT 1")->fetchColumn(); return 'marques.php'; }
  $a = $pdo->query("SELECT * FROM admins WHERE email = " . $pdo->quote(DEMO_DIRIGEANT['email']))->fetch(); tf_schema();
  admin_ouvrir($a, ['tout']); return 'admin.php';
}
