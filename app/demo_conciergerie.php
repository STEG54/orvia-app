<?php
// ORVIA : données fictives de la démo (conciergerie au Maroc). Appelé par demo_seed(), dans les tables préfixées du visiteur.
require_once __DIR__ . '/conciergerie_lib.php';

/** Photo de démonstration d'une pièce : un cadrage différent (selon la graine) d'une vraie photo d'intérieur de la démo. */
function cz_demo_photo(int $graine, string $piece): string {
  $p = mb_strtolower($piece);
  $src = match (true) {
    str_contains($p, 'chambre') => 'bed', str_contains($p, 'bain') || str_contains($p, 'linge') => 'detail',
    str_contains($p, 'piscine') || str_contains($p, 'terrasse') || str_contains($p, 'patio') || str_contains($p, 'jardin') => 'pool',
    str_contains($p, 'porte') || str_contains($p, 'entrée') || str_contains($p, 'boîte') || str_contains($p, 'rue') => 'door',
    default => 'hero' };
  static $sources = [];   // chaque photo source n'est décodée qu'une fois
  $im = $sources[$src] ??= (@imagecreatefromjpeg(__DIR__ . "/assets/demo/$src.jpg") ?: (function () { $i = imagecreatetruecolor(1200, 900); imagefill($i, 0, 0, imagecolorallocate($i, 236, 230, 220)); return $i; })());
  mt_srand($graine); $w = imagesx($im); $h = imagesy($im);
  $z = mt_rand(62, 92) / 100; $cw = (int)($w * $z); $ch = (int)min($h, $cw * 0.75); $cw = (int)min($cw, $ch / 0.75);
  $x = mt_rand(0, max(0, $w - $cw)); $y = mt_rand(0, max(0, $h - $ch));
  $out = imagecreatetruecolor(1200, 900); imagecopyresampled($out, $im, 0, 0, $x, $y, 1200, 900, $cw, $ch);
  $tmp = tempnam(sys_get_temp_dir(), 'czd'); imagejpeg($out, $tmp, 84); imagedestroy($out);
  $r = save_image($tmp); @unlink($tmp); return $r['fichier'];
}

function cz_demo_seed(): void {
  $pdo = db(); require_once __DIR__ . '/migrations_lib.php'; mig_4($pdo); $now = now();
  reglage_set('theme', 'orvia'); reglage_set('cz_pays', 'MA'); reglage_set('cz_demarrage_masque', '1');
  // Taxe de séjour : montants fictifs de la démo (chaque conciergerie reprend ceux de l'arrêté de sa commune).
  reglage_set('cz_taxe_sejour', json_encode(['communes' => ['Marrakech' => ['tarif' => 30, 'age' => 12], 'Agadir' => ['tarif' => 25, 'age' => 12], 'Casablanca' => ['tarif' => 25, 'age' => 12]], 'plateformes' => []], JSON_UNESCAPED_UNICODE)); reglage_set('cz_code_auto', '0');
  // Équipe terrain (fictive)
  $ia = $pdo->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut, created_at, rgpd_info_at, rgpd_image_at) VALUES (?,?,?,?,'Marrakech',1,'actif',?,?,?)"); $E = [];
  foreach ([['Fatima Zahra', 'Benali'], ['Youssef', 'Amrani'], ['Khadija', 'Ouazzani']] as $i => [$p, $n]) { $ia->execute([$p, $n, '06 00 00 1' . $i . ' 0' . $i, 'equipe' . $i . '@demo-keylia.invalid', $now, $now, $now]); $E[] = (int)$pdo->lastInsertId(); }
  // Propriétaires
  $ip = $pdo->prepare("INSERT INTO c_proprietaires (nom, email, telephone, plafond, created_at) VALUES (?,?,?,?,?)"); $P = [];
  foreach ([['M. et Mme Lefèvre', 'lefevre@demo-keylia.invalid', 1500, '+33 6 00 00 00 01'], ['Mme Nadia Berrada', 'berrada@demo-keylia.invalid', 2000, '+212 6 61 00 00 02'], ['M. Karim Tazi', 'tazi@demo-keylia.invalid', 1000, '+212 6 61 00 00 03']] as [$n, $e, $pl, $tl]) { $ip->execute([$n, $e, $tl, $pl, $now]); $P[] = (int)$pdo->lastInsertId(); }
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
  // Deux mois d'historique par logement, pour des rapports pleins : séjours, commandes, avis, quelques dépenses.
  mt_srand(42); $H = []; $debut = [0 => -6, 1 => -3, 2 => -1, 3 => -4, 4 => 0];
  $srcs = [['airbnb', 'Reserved'], ['airbnb', 'Reserved'], ['booking', 'Booking'], ['booking', 'Booking'], ['direct', 'Réservation directe'], ['abritel', 'Réservation']];
  foreach ($LID as $li => $lid) { $j = -64 + mt_rand(0, 3);
    while (true) { $n = mt_rand(2, 6); if ($j + $n > $debut[$li]) break; [$src, $lib] = $srcs[mt_rand(0, 5)];
      $t = cz_jeton(); $arr = demo_j($j); $dep = demo_j($j + $n);
      $ir->execute([$lid, $src, $src === 'direct' ? '' : $src . ':hist' . $li . '-' . $j, $arr, $dep, $lib, mt_rand(2, $li === 3 || $li === 0 ? 6 : 3), cz_hash($t), cz_enc($t), $arr . ' 09:00:00', $now, $now]);
      $rid = (int)$pdo->lastInsertId(); cz_mission_depart($rid); $H[] = [$rid, $li, $j, $n]; $j += $n + (mt_rand(0, 3) === 0 ? mt_rand(1, 3) : 0); } }
  // Missions passées : faites, avec photos ; mission du jour en cours.
  $g = 100; $pool = []; $recent = demo_j(-2);
  foreach ($pdo->query("SELECT m.id, m.date, l.checklist FROM c_missions m JOIN c_logements l ON l.id = m.logement_id ORDER BY m.date")->fetchAll() as $m) {
    if ($m['date'] > demo_j(0)) continue; $etat = [];
    foreach (cz_checklist($m) as $i => $p) { $ph = [];
      for ($n = 0; $n < $p['photos']; $n++) { $cle = $p['piece'] . '|' . $n;   // l'historique ancien réutilise les mêmes clichés, les missions récentes ont les leurs
        $ph[] = $m['date'] >= $recent || !isset($pool[$cle]) ? ($pool[$cle] = cz_demo_photo($g++, $p['piece'])) : $pool[$cle]; }
      $etat[$i] = ['ok' => true, 'photos' => $ph]; }
    $fait = $m['date'] < demo_j(0);
    if (!$fait) $etat = array_slice($etat, 0, 2, true); // mission du jour : deux pièces faites, le reste à faire
    $h0 = sprintf('%02d:%02d', mt_rand(10, 11), mt_rand(0, 59)); $h1 = sprintf('%02d:%02d', mt_rand(12, 14), mt_rand(0, 59));
    $pdo->prepare("UPDATE c_missions SET etat = ?, statut = ?, debut_at = ?, fin_at = ? WHERE id = ?")->execute([json_encode($etat), $fait ? 'faite' : 'en_cours', $m['date'] . " $h0:00", $fait ? $m['date'] . " $h1:00" : null, $m['id']]);
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
  $ii->execute([$LID[1], 'Youssef Amrani', 'Mitigeur de la douche qui fuit', cz_demo_photo(900, 'Salle de bain'), 0, 'ouvert', 650, '', demo_j(-1) . ' 12:10:00', null]);
  $ii->execute([$LID[0], 'Fatima Zahra Benali', 'Ampoule de l\'entrée remplacée', null, 0, 'clos', 35, '', demo_j(-5) . ' 14:00:00', demo_j(-5) . ' 16:00:00']);
  // Historique : environ quatre séjours sur dix commandent quelque chose, la plupart laissent un avis.
  $coms = ['Logement impeccable, on reviendra.', 'Très propre, accueil chaleureux.', 'Superbe terrasse, literie parfaite.', 'Rien à redire.', 'Le petit-déjeuner du premier matin était une très bonne idée.', 'Calme, propre, bien situé.', 'Parfait pour notre famille.', ''];
  foreach ($H as [$rid, $li, $j, $n]) {
    if (mt_rand(1, 10) <= 4) foreach ((array)array_rand($SID, mt_rand(1, 2)) as $si) $ic->execute([$rid, $si === 0 ? 2 : 1, 'faite', demo_j($j - 1) . ' 19:00:00', $SID[$si]]);
    $pr = mt_rand(1, 12) === 1 ? 4 : 5; if ($li === 1 && mt_rand(1, 4) === 1) $pr = 4;
    if (mt_rand(1, 10) <= 7) $iav->execute(['arrivee', $pr, null, null, '', demo_j($j) . ' 18:00:00', $rid]);
    if (mt_rand(1, 10) <= 5) $iav->execute(['sejour', null, mt_rand(1, 6) === 1 ? 4 : 5, 5, $coms[mt_rand(0, 7)], demo_j($j + $n) . ' 15:00:00', $rid]);
  }
  foreach ([[3, 'Robinet extérieur de la piscine changé', 280, -38], [0, 'Climatisation du salon rechargée', 650, -27], [2, 'Serrure de la boîte à clés remplacée', 220, -19], [4, 'Store de la chambre réparé', 180, -12]] as [$li, $d, $c, $jj])
    $ii->execute([$LID[$li], 'Équipe Keylia', $d, null, 0, 'clos', $c, 'accepte', demo_j($jj) . ' 10:00:00', demo_j($jj) . ' 17:00:00']);
  // Relation avec les propriétaires (M. et Mme Lefèvre) : messages, devis avant travaux, rapports envoyés.
  require_once __DIR__ . '/migrations_lib.php'; mig_8($pdo); $P0 = $P[0]; $dir = DEMO_DIRIGEANT['nom'];
  $im = $pdo->prepare("INSERT INTO c_prop_messages (proprietaire_id, logement_id, auteur, nom, texte, photos, lu_at, created_at) VALUES (?,?,?,?,?,?,?,?)");
  $im->execute([$P0, $LID[0], 'conciergerie', $dir, "Bonjour,\nLa villa est prête pour la famille Martin, qui arrive aujourd'hui en fin d'après-midi. La piscine a été nettoyée ce matin.", json_encode([cz_demo_photo(950, 'Terrasse et piscine'), cz_demo_photo(951, 'Salon')]), demo_j(0) . ' 13:40:00', demo_j(0) . ' 13:30:00']);
  $im->execute([$P0, null, 'proprietaire', 'M. et Mme Lefèvre', "Merci, c'est parfait. Pouvez-vous vérifier la climatisation de la chambre d'amis ? Les derniers voyageurs l'ont trouvée bruyante.", null, demo_j(0) . ' 14:10:00', demo_j(0) . ' 14:02:00']);
  $im->execute([$P0, $LID[0], 'conciergerie', $dir, "C'est noté : contrôle prévu mercredi à 10 h. Je vous envoie le rapport de visite juste après.", null, null, demo_j(0) . ' 14:20:00']);
  $idv = $pdo->prepare("INSERT INTO c_prop_devis (proprietaire_id, logement_id, titre, description, montant, prestataire, photos, statut, reponse, decide_at, paiement, regle_at, par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $idv->execute([$P0, $LID[1], 'Remplacement du mitigeur de la douche', "Le mitigeur fuit au goutte-à-goutte depuis le dernier séjour. Remplacement par un modèle thermostatique, pose comprise. Intervention possible jeudi entre deux séjours.", 650, 'Plomberie Atlas, Marrakech', json_encode([cz_demo_photo(952, 'Salle de bain')]), 'envoye', null, null, '', null, $dir, demo_j(-1) . ' 16:00:00']);
  $idv->execute([$P0, $LID[0], 'Traitement anti-moustiques du jardin', "Deux passages à quinze jours d'intervalle, produits sans danger pour les enfants.", 400, 'Jardins de la Palmeraie', null, 'accepte', 'D\'accord, merci.', demo_j(-6) . ' 09:30:00', 'a_regler', null, $dir, demo_j(-7) . ' 18:00:00']);
  $idv->execute([$P0, $LID[0], 'Recharge de la climatisation du salon', "Recharge en gaz et nettoyage des filtres.", 650, 'Froid Service', null, 'accepte', '', demo_j(-29) . ' 11:00:00', 'regle', demo_j(-24) . ' 10:00:00', $dir, demo_j(-30) . ' 09:00:00']);
  $prev0 = date('Y-m-01', strtotime('first day of last month')); $prev1 = date('Y-m-t', strtotime('last day of last month'));
  $irp = $pdo->prepare("INSERT INTO c_prop_rapports (proprietaire_id, type, titre, periode_from, periode_to, mission_id, note, contenu, par, lu_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
  $irp->execute([$P0, 'mensuel', 'Rapport du ' . date('d/m/Y', strtotime($prev0)) . ' au ' . date('d/m/Y', strtotime($prev1)), $prev0, $prev1, null,
    "Un très bon mois pour vos deux logements. Les voyageurs saluent la propreté et l'accueil. La climatisation du salon a été rechargée, la facture est dans vos devis.",
    json_encode(cz_prop_rapport_contenu($P0, $prev0, $prev1), JSON_UNESCAPED_UNICODE), $dir, date('Y-m-02') . ' 10:00:00', date('Y-m-01') . ' 09:00:00']);
  $mv = $pdo->query("SELECT id FROM c_missions WHERE logement_id = " . (int)$LID[0] . " AND type = 'menage' AND statut = 'faite' ORDER BY date DESC LIMIT 1")->fetchColumn();
  if ($mv) { $m = cz_mission((int)$mv); $etat = cz_etat($m); $pc = []; foreach (cz_checklist($m) as $i => $x) $pc[] = ['piece' => $x['piece'], 'ok' => !empty($etat[$i]['ok']), 'photos' => (array)($etat[$i]['photos'] ?? [])];
    $irp->execute([$P0, 'visite', 'Ménage et contrôle · Villa Palmeraie · ' . date('d/m/Y', strtotime($m['date'])), $m['date'], $m['date'], $mv, 'Logement remis en état après le départ des voyageurs. Rien à signaler.',
      json_encode(['logement' => $m['logement'], 'type' => 'menage', 'date' => $m['date'], 'fin' => $m['fin_at'], 'pieces' => $pc], JSON_UNESCAPED_UNICODE), $dir, null, $m['date'] . ' 14:00:00']); }
  // Linge : articles, kits par logement, un envoi revenu (deux serviettes manquantes) et un envoi en cours.
  mig_9($pdo); $ia = $pdo->prepare("INSERT INTO c_linge_articles (nom, stock, prix_lavage, ordre, created_at) VALUES (?,?,?,?,?)"); $AR = [];
  foreach ([['Drap double', 28, 12], ['Housse de couette', 22, 15], ['Taie d\'oreiller', 44, 4], ['Serviette de bain', 56, 6], ['Serviette de main', 40, 4], ['Tapis de bain', 14, 5], ['Torchon', 24, 3]] as $o => [$n, $st, $px]) { $ia->execute([$n, $st, $px, $o, $now]); $AR[] = (int)$pdo->lastInsertId(); }
  $ik = $pdo->prepare("INSERT INTO c_linge_kits (logement_id, article_id, qte) VALUES (?,?,?)");
  foreach ([[3, 3, 6, 8, 6, 2, 2], [2, 2, 4, 4, 4, 1, 1], [1, 1, 2, 4, 2, 1, 1], [4, 4, 8, 10, 8, 2, 2], [1, 1, 2, 4, 2, 1, 1]] as $li => $q) foreach ($q as $k => $n) $ik->execute([$LID[$li], $AR[$k], $n]);
  $lg = fn(array $q, bool $r) => json_encode(array_combine($AR, array_map(fn($k) => ['nom' => ['Drap double', 'Housse de couette', 'Taie d\'oreiller', 'Serviette de bain', 'Serviette de main', 'Tapis de bain', 'Torchon'][$k], 'envoye' => $q[$k], 'recu' => $r ? ($k === 3 ? $q[$k] - 2 : $q[$k]) : null, 'prix' => [12, 15, 4, 6, 4, 5, 3][$k]], array_keys($q))), JSON_UNESCAPED_UNICODE);
  $ie = $pdo->prepare("INSERT INTO c_linge_envois (blanchisserie, envoye_at, retour_at, statut, lignes, cout, par) VALUES (?,?,?,?,?,?,?)");
  $ie->execute(['Pressing Gueliz', demo_j(-10) . ' 15:00:00', demo_j(-8) . ' 11:00:00', 'retourne', $lg([18, 14, 36, 42, 30, 9, 9], true), 963, $dir]);
  $pdo->prepare("UPDATE c_linge_articles SET stock = stock - 2 WHERE id = ?")->execute([$AR[3]]);
  $ie->execute(['Pressing Gueliz', demo_j(-4) . ' 15:00:00', null, 'envoye', $lg([12, 10, 22, 28, 20, 6, 6], false), 0, $dir]);
  // Entretiens programmés : la climatisation de la villa tombe dans trois jours, la piscine chaque semaine.
  $ient = $pdo->prepare("INSERT INTO c_entretiens (logement_id, titre, frequence_jours, prochaine, heure, animatrice_id, prestataire, consignes, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
  $ient->execute([$LID[0], 'Entretien de la climatisation', 90, demo_j(3), '10:00', null, 'Froid Service, Marrakech', 'Nettoyer les filtres des quatre splits, vérifier la chambre d\'amis (bruit signalé)', $now]);
  $ient->execute([$LID[0], 'Nettoyage de la piscine', 7, demo_j(1), '09:00', $E[0], '', 'Épuisette, robot, contrôle du pH et du chlore', $now]);
  $ient->execute([$LID[3], 'Nettoyage de la piscine', 7, demo_j(2), '09:00', $E[1], '', 'Épuisette, robot, contrôle du pH et du chlore', $now]);
  $ient->execute([$LID[1], 'Désinsectisation', 90, demo_j(40), '11:00', null, 'Hygiène Services Maroc', 'Patio et cuisine', $now]);
  $ient->execute([$LID[2], 'Détartrage du chauffe-eau', 365, demo_j(120), '10:00', $E[1], '', '', $now]);
  cz_entretiens_generer();
}
