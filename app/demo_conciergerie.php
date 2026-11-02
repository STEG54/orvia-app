<?php
// ORVIA : données fictives de la démo (conciergerie au Maroc). Appelé par demo_seed(), dans les tables préfixées du visiteur.
require_once __DIR__ . '/conciergerie_lib.php';

/** Photo fictive d'une pièce : aplats sobres, sans personne. */
function cz_demo_photo(int $graine, string $piece): string {
  mt_srand($graine); $w = 1200; $h = 900; $im = imagecreatetruecolor($w, $h);
  $tons = [[236, 230, 220], [222, 214, 200], [240, 238, 234], [214, 222, 226], [230, 222, 214]]; $t = $tons[mt_rand(0, 4)];
  imagefill($im, 0, 0, imagecolorallocate($im, ...$t));
  imagefilledrectangle($im, 0, (int)($h * .68), $w, $h, imagecolorallocate($im, ...array_map(fn($c) => $c - 38, $t)));
  $f = imagecolorallocate($im, 252, 251, 248); $o = imagecolorallocate($im, 60, 58, 54);
  if (str_contains($piece, 'Chambre')) { imagefilledrectangle($im, 260, 460, 940, 700, $f); imagefilledrectangle($im, 240, 380, 960, 470, imagecolorallocate($im, 120, 108, 92)); imagefilledrectangle($im, 300, 470, 520, 540, imagecolorallocate($im, 245, 243, 238)); imagefilledrectangle($im, 680, 470, 900, 540, imagecolorallocate($im, 245, 243, 238)); }
  elseif (str_contains($piece, 'bain')) { imagefilledellipse($im, 600, 600, 520, 200, $f); imagefilledrectangle($im, 520, 180, 680, 420, imagecolorallocate($im, 200, 210, 214)); }
  elseif (str_contains($piece, 'Cuisine')) { imagefilledrectangle($im, 120, 500, 1080, 700, imagecolorallocate($im, 90, 84, 76)); imagefilledrectangle($im, 120, 470, 1080, 500, $f); imagefilledrectangle($im, 200, 160, 520, 360, imagecolorallocate($im, 206, 200, 190)); }
  elseif (str_contains($piece, 'Terrasse') || str_contains($piece, 'Piscine')) { imagefilledrectangle($im, 0, 0, $w, (int)($h * .5), imagecolorallocate($im, 196, 214, 226)); imagefilledrectangle($im, 160, 560, 1040, 760, imagecolorallocate($im, 110, 170, 190)); }
  else { imagefilledrectangle($im, 220, 470, 980, 640, imagecolorallocate($im, 140, 128, 112)); imagefilledrectangle($im, 220, 400, 980, 480, imagecolorallocate($im, 160, 148, 132)); imagefilledrectangle($im, 760, 200, 960, 360, $f); }
  imagestring($im, 5, 30, 30, iconv('UTF-8', 'ASCII//TRANSLIT', 'Photo de demonstration - ' . $piece), $o);
  $tmp = tempnam(sys_get_temp_dir(), 'czd'); imagejpeg($im, $tmp, 82); imagedestroy($im);
  $r = save_image($tmp); @unlink($tmp); return $r['fichier'];
}

function cz_demo_seed(): void {
  $pdo = db(); require_once __DIR__ . '/migrations_lib.php'; mig_4($pdo); $now = now();
  reglage_set('theme', 'orvia'); reglage_set('cz_pays', 'MA'); reglage_set('cz_code_auto', '0');
  // Équipe terrain (fictive)
  $ia = $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at, rgpd_info_at, rgpd_image_at) VALUES (?,?,?,?,'France',1,'actif',?,?,?)"); $E = [];
  foreach ([['Fatima Zahra', 'Benali'], ['Youssef', 'Amrani'], ['Khadija', 'Ouazzani']] as $i => [$p, $n]) { $ia->execute([$p, $n, '06 00 00 1' . $i . ' 0' . $i, 'equipe' . $i . '@demo-orvia.invalid', $now, $now, $now]); $E[] = (int)$pdo->lastInsertId(); }
  // Propriétaires
  $ip = $pdo->prepare("INSERT INTO c_proprietaires (nom, email, telephone, plafond, created_at) VALUES (?,?,?,?,?)"); $P = [];
  foreach ([['M. et Mme Lefèvre', 'lefevre@demo-orvia.invalid', 1500], ['Mme Nadia Berrada', 'berrada@demo-orvia.invalid', 2000], ['M. Karim Tazi', 'tazi@demo-orvia.invalid', 1000]] as [$n, $e, $pl]) { $ip->execute([$n, $e, '', $pl, $now]); $P[] = (int)$pdo->lastInsertId(); }
  // Logements
  $L = [
    ['Villa Palmeraie', 'Marrakech', 'Circuit de la Palmeraie', 0, 0, [['Chambre principale', 3], ['Chambre d\'amis', 2], ['Salle de bain', 2], ['Cuisine', 2], ['Salon', 2], ['Terrasse et piscine', 2]], '3 lits doubles, 8 serviettes, 6 draps de bain', 240, '7341'],
    ['Riad Zitoun', 'Marrakech', 'Derb Zitoun, Médina', 0, 1, [['Chambre', 2], ['Salle de bain', 2], ['Patio', 2], ['Terrasse', 1]], '2 lits doubles, 6 serviettes', 150, '2580'],
    ['Appartement Gueliz', 'Marrakech', 'Rue de la Liberté, Gueliz', 1, 0, [['Chambre', 2], ['Salle de bain', 2], ['Cuisine', 2], ['Salon', 2]], '1 lit double, 4 serviettes', 90, '1966'],
    ['Villa Founty', 'Agadir', 'Quartier Founty', 1, 2, [['Chambres', 3], ['Salles de bain', 2], ['Cuisine', 2], ['Piscine', 2]], '4 lits doubles, 10 serviettes', 210, '4402'],
    ['Appartement Anfa', 'Casablanca', 'Boulevard d\'Anfa', 2, 1, [['Chambre', 2], ['Salle de bain', 2], ['Cuisine', 2], ['Salon', 2]], '1 lit double, 1 canapé-lit, 4 serviettes', 100, '8815'],
  ];
  $il = $pdo->prepare("INSERT INTO c_logements (nom, ville, adresse, proprietaire_id, agent_id, acces_enc, code_enc, checklist, kit_linge, duree_menage, ical, notes, actif, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,'','',1,?)"); $LID = [];
  foreach ($L as [$nom, $ville, $adr, $pi, $ei, $chk, $kit, $duree, $code]) {
    $il->execute([$nom, $ville, $adr, $P[$pi], $E[$ei], cz_enc("Boîte à clés à droite de la porte d'entrée.\nWi-Fi : " . str_replace(' ', '', $nom) . " / mot de passe dans le livret."), cz_enc($code),
      json_encode(array_map(fn($x) => ['piece' => $x[0], 'consignes' => '', 'photos' => $x[1]], $chk), JSON_UNESCAPED_UNICODE), $kit, $duree, $now]);
    $LID[] = (int)$pdo->lastInsertId();
  }
  // Livret d'arrivée : position, repère, étapes en photos, Wi-Fi.
  $pos = [[31.6694, -7.9744], [31.6236, -7.9862], [31.6346, -8.0102], [30.4105, -9.6020], [33.5893, -7.6620]];
  $rep = ['Portail blanc après le golf, le gardien vous ouvre', 'Derrière le palais Bahia : deuxième ruelle à gauche, porte verte cloutée', 'Immeuble au-dessus de la pharmacie, 3e étage', 'Face à l\'hôtel Sofitel, résidence Les Dunes, villa 12', 'Résidence Anfa Garden, bâtiment B, 5e étage'];
  $ul = $pdo->prepare("UPDATE c_logements SET lat = ?, lng = ?, pos_fixe = 1, livret_enc = ? WHERE id = ?");
  foreach ($LID as $i => $id) $ul->execute([$pos[$i][0], $pos[$i][1], cz_enc(json_encode(['repere' => $rep[$i], 'parking' => $i === 1 ? 'Parking gardé de la place des Ferblantiers, 20 DH la nuit, puis 4 minutes à pied' : 'Devant le logement',
    'serrure' => 'Boîte à clés grise à droite de la porte : composer le code, abaisser le levier', 'wifi_nom' => str_replace(' ', '', $L[$i][0]), 'wifi_mdp' => 'orvia-' . (1000 + $i * 137), 'urgence' => '+212 6 00 00 10 00',
    'depart' => 'Remettre les clés dans la boîte, éteindre la climatisation, fermer les fenêtres',
    'etapes' => [['texte' => 'La rue, depuis le dernier carrefour', 'photo' => cz_demo_photo(700 + $i * 3, 'Rue')], ['texte' => 'La porte d\'entrée', 'photo' => cz_demo_photo(701 + $i * 3, 'Porte')], ['texte' => 'La boîte à clés, à droite', 'photo' => cz_demo_photo(702 + $i * 3, 'Boîte à clés')]]], JSON_UNESCAPED_UNICODE)), $id]);
  // Réservations : passées, du jour, à venir. [logement, arrivée (jours), nuits, source, libellé, voyageurs]
  $R = [[0, -6, 4, 'airbnb', 'Reserved', 4], [0, -2, 2, 'booking', 'Booking', 2], [0, 0, 5, 'airbnb', 'Reserved', 4], [1, -3, 3, 'airbnb', 'Reserved', 2], [1, 1, 4, 'booking', 'Booking', 2],
    [2, -1, 2, 'direct', 'M. Durand', 1], [2, 4, 3, 'airbnb', 'Reserved', 2], [3, -4, 7, 'abritel', 'Réservation', 6], [3, 9, 6, 'airbnb', 'Reserved', 5], [4, 0, 2, 'booking', 'Booking', 2], [4, 6, 3, 'direct', 'Agence Atlas Voyages', 2]];
  $ir = $pdo->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, libelle, nb_voyageurs, statut, lien_hash, lien_enc, code_envoye_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,'confirmee',?,?,?,?,?)"); $RID = [];
  foreach ($R as $k => [$li, $a, $n, $src, $lib, $nb]) {
    $t = cz_jeton(); $arr = demo_j($a); $dep = demo_j($a + $n);
    $ir->execute([$LID[$li], $src, $src === 'direct' ? '' : $src . ':demo' . $k, $arr, $dep, $lib, $nb, cz_hash($t), cz_enc($t), $a < 0 ? $arr . ' 09:00:00' : null, $now, $now]);
    $RID[$k] = (int)$pdo->lastInsertId(); cz_mission_depart($RID[$k]);
  }
  // Missions passées : faites, avec photos ; mission du jour en cours.
  $g = 100;
  foreach ($pdo->query("SELECT m.id, m.date, l.checklist FROM c_missions m JOIN c_logements l ON l.id = m.logement_id ORDER BY m.date")->fetchAll() as $m) {
    if ($m['date'] > demo_j(0)) continue; $etat = [];
    foreach (cz_checklist($m) as $i => $p) { $ph = []; for ($n = 0; $n < $p['photos']; $n++) $ph[] = cz_demo_photo($g++, $p['piece']); $etat[$i] = ['ok' => true, 'photos' => $ph]; }
    $fait = $m['date'] < demo_j(0);
    if (!$fait) $etat = array_slice($etat, 0, 2, true); // mission du jour : deux pièces faites, le reste à faire
    $pdo->prepare("UPDATE c_missions SET etat = ?, statut = ?, debut_at = ?, fin_at = ? WHERE id = ?")->execute([json_encode($etat), $fait ? 'faite' : 'en_cours', $m['date'] . ' 11:05:00', $fait ? $m['date'] . ' 13:20:00' : null, $m['id']]);
  }
  // Une mission de contrôle à venir.
  $pdo->prepare("INSERT INTO c_missions (logement_id, type, date, heure, animatrice_id, statut, etat, note, created_at) VALUES (?, 'controle', ?, '10:00', ?, 'a_faire', '[]', 'Vérifier la climatisation de la chambre d''amis', ?)")->execute([$LID[0], demo_j(2), $E[0], $now]);
  // Fiches voyageurs fictives (sans pièce) pour les séjours en cours et du jour.
  $iv = $pdo->prepare("INSERT INTO c_voyageurs (reservation_id, data_enc, piece, created_at) VALUES (?,?,NULL,?)");
  $V = [[2, ['Martin', 'Claire', 'F', '1984-03-12', 'Lyon', 'Française', 'Architecte', '12 quai Saint-Antoine, Lyon', 'passeport', '18FV00000', '2020-05-04', 'Lyon', 'Marrakech']],
    [2, ['Martin', 'Paul', 'M', '1982-11-02', 'Lyon', 'Française', 'Médecin', '12 quai Saint-Antoine, Lyon', 'passeport', '18FV00001', '2020-05-04', 'Lyon', 'Marrakech']],
    [7, ['Alaoui', 'Samir', 'M', '1979-06-21', 'Fès', 'Marocaine', 'Ingénieur', 'Rue Ibn Khaldoun, Rabat', 'cin', 'AB000000', '2019-02-10', 'Rabat', 'Agadir']],
    [9, ['Schmidt', 'Anna', 'F', '1991-01-30', 'Munich', 'Allemande', 'Juriste', 'Leopoldstraße 1, München', 'passeport', 'C0000000', '2022-07-15', 'Munich', 'Casablanca']]];
  $cles = ['nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'profession', 'adresse', 'piece_type', 'piece_numero', 'piece_delivrance', 'venant_de', 'allant_a'];
  foreach ($V as [$k, $f]) $iv->execute([$RID[$k], cz_enc(json_encode(array_combine($cles, $f) + ['email' => '', 'telephone' => ''], JSON_UNESCAPED_UNICODE)), $now]);
  // Positions relevées à l'arrivée et au départ des missions faites ; heure et mode d'arrivée des voyageurs.
  $pdo->exec("UPDATE c_missions SET debut_dist = 40, fin_dist = 35 WHERE debut_at IS NOT NULL");
  $pdo->prepare("UPDATE c_missions SET debut_dist = 2300 WHERE id = (SELECT id FROM (SELECT id FROM c_missions WHERE statut = 'faite' ORDER BY date DESC LIMIT 1) x)")->execute();
  $ia = $pdo->prepare("UPDATE c_reservations SET arrivee_mode = ?, vol = ?, arrivee_heure = ?, whatsapp_enc = ? WHERE id = ?");
  $ia->execute(['avion', 'AT 773', '16:40', cz_enc('+33612345678'), $RID[2]]); $ia->execute(['voiture', '', '13:00', cz_enc('+212661000000'), $RID[9]]); $ia->execute(['avion', 'TO 3010', '21:15', cz_enc('+491701234567'), $RID[4]]);
  // Le menu « À votre arrivée » (prix voyageur, coût réel).
  $is = $pdo->prepare("INSERT INTO c_services (nom, nom_en, nom_ar, description, prix, cout, delai_h, actif, ordre, created_at) VALUES (?,?,?,?,?,?,?,1,?,?)"); $SID = [];
  foreach ([['Eau minérale, six bouteilles', 'Mineral water, six bottles', 'ماء معدني، ستة قراعي', 'Posées au frais avant votre arrivée', 60, 25, 12],
    ['Corbeille de fruits de saison', 'Seasonal fruit basket', 'سلة ديال الفواكه ديال الموسم', 'Fruits du marché, sur la table de la cuisine', 180, 70, 24],
    ['Petit-déjeuner du premier matin', 'First-morning breakfast', 'فطور الصباح الأول', 'Pain, msemen, confiture, jus d\'orange, café', 250, 110, 24],
    ['Courses selon votre liste', 'Groceries from your list', 'التقدية حسب اللائحة ديالك', 'Hors prix des produits, réglés sur ticket', 200, 80, 24],
    ['Transfert depuis l\'aéroport', 'Airport transfer', 'النقل من المطار', 'Chauffeur à l\'arrivée, suivi de votre vol', 450, 300, 24]] as $o => $x) { $is->execute(array_merge($x, [$o, $now])); $SID[] = (int)$pdo->lastInsertId(); }
  $ic = $pdo->prepare("INSERT INTO c_commandes (reservation_id, service_id, quantite, prix, cout, statut, created_at) SELECT ?, id, ?, prix, cout, ?, ? FROM c_services WHERE id = ?");
  foreach ([[2, 0, 1, 'faite'], [2, 1, 1, 'faite'], [2, 4, 1, 'faite'], [4, 0, 2, 'demandee'], [4, 2, 1, 'demandee'], [9, 1, 1, 'faite'], [0, 4, 1, 'faite'], [3, 0, 1, 'faite']] as [$k, $si, $q, $st])
    $ic->execute([$RID[$k], $q, $st, demo_j(-3) . ' 10:00:00', $SID[$si]]);
  // Avis des voyageurs : à l'arrivée (propreté) et après le départ.
  $iav = $pdo->prepare("INSERT INTO c_avis (reservation_id, logement_id, moment, proprete, logement, accueil, commentaire, mission_id, animatrice_id, created_at) SELECT r.id, r.logement_id, ?, ?, ?, ?, ?, m.id, m.animatrice_id, ? FROM c_reservations r LEFT JOIN c_missions m ON m.logement_id = r.logement_id AND m.type = 'menage' AND m.date <= r.arrivee WHERE r.id = ? ORDER BY m.date DESC LIMIT 1");
  foreach ([[0, 'arrivee', 5, null, null, 'Impeccable, merci.'], [0, 'sejour', null, 5, 5, 'Très bel appartement, accueil parfait.'], [1, 'arrivee', 4, null, null, ''], [3, 'arrivee', 5, null, null, ''],
    [3, 'sejour', null, 4, 5, 'Quartier calme, literie excellente.'], [5, 'arrivee', 3, null, null, 'Salle de bain à reprendre, le reste était bien.'], [7, 'arrivee', 5, null, null, ''], [2, 'arrivee', 5, null, null, 'Tout est parfait, et les fruits étaient là.']] as [$k, $mo, $pr, $lo, $ac, $co])
    $iav->execute([$mo, $pr, $lo, $ac, $co, demo_j($R[$k][1] + ($mo === 'sejour' ? $R[$k][2] : 0)) . ($mo === 'sejour' ? ' 15:00:00' : ' 18:00:00'), $RID[$k]]);
  // Une tâche du gestionnaire : accueil du voyageur qui arrive en avion.
  $gid = (int)$pdo->query("SELECT id FROM admins ORDER BY id LIMIT 1")->fetchColumn();
  $pdo->prepare("INSERT INTO c_missions (logement_id, reservation_id, type, date, heure, admin_id, duree, statut, etat, note, created_at) VALUES (?, ?, 'accueil', ?, '17:30', ?, 45, 'a_faire', '[]', 'Accueillir la famille Martin, remettre les clés, montrer la terrasse', ?)")->execute([$LID[0], $RID[2], demo_j(0), $gid ?: null, $now]);
  // Signalements
  $ii = $pdo->prepare("INSERT INTO c_incidents (logement_id, auteur, description, photo, bloquant, statut, cout, proprio_statut, created_at, clos_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
  $ii->execute([$LID[1], 'Youssef Amrani', 'Mitigeur de la douche qui fuit', cz_demo_photo(900, 'Salle de bain'), 0, 'ouvert', 450, 'demande', demo_j(-1) . ' 12:10:00', null]);
  $ii->execute([$LID[0], 'Fatima Zahra Benali', 'Ampoule de l\'entrée remplacée', null, 0, 'clos', 35, '', demo_j(-5) . ' 14:00:00', demo_j(-5) . ' 16:00:00']);
}
