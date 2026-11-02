<?php
// ORVIA : parcours conciergerie complet sur une installation de DÉMO (données fictives).
// Logement, propriétaire, réservations, fiche voyageur avec pièce, mission de ménage avec photos, feu vert, code d'accès, signalement, espace propriétaire.
// Usage : php tests/conciergerie.php <adresse de la démo> <AMAYA_DEMO_CLE>
if (PHP_SAPI !== 'cli') exit;
[$_, $B, $cle] = $argv + [null, '', '']; if (!$B || !$cle) { fwrite(STDERR, "Usage : php tests/conciergerie.php <adresse> <clé démo>\n"); exit(2); }
$B = rtrim($B, '/'); $jar = tempnam(sys_get_temp_dir(), 'orv'); $ok = 0; $ko = 0;
function t(string $n, $c, string $info = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($info ? " : " . substr($info, 0, 300) : '') . "\n"; } }
function http(string $url, $body = null, bool $form = false, bool $ajax = true): array {
  global $B, $jar; $c = curl_init(str_starts_with($url, 'http') ? $url : "$B/$url");
  $h = $ajax ? ['X-Requested-With: amaya'] : []; if ($body !== null && !$form) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $h]
    + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body] : []));
  $r = curl_exec($c); $i = curl_getinfo($c); curl_close($c); return ['code' => $i['http_code'], 'type' => (string)$i['content_type'], 'body' => (string)$r, 'json' => json_decode((string)$r, true)];
}
function c(string $action, $body = null, string $q = ''): array { return http('conciergerie_api.php?action=' . $action . ($q ? '&' . $q : ''), $body); }
function jpeg(): CURLFile { $im = imagecreatetruecolor(400, 260); imagefill($im, 0, 0, imagecolorallocate($im, 200, 210, 220)); imagestring($im, 5, 20, 20, 'PIECE TEST AB123456', imagecolorallocate($im, 0, 0, 0));
  $f = tempnam(sys_get_temp_dir(), 'orj') . '.jpg'; imagejpeg($im, $f); return new CURLFile($f, 'image/jpeg', 'photo.jpg'); }
$j = date('Y-m-d'); $j2 = date('Y-m-d', strtotime('+2 days')); $j4 = date('Y-m-d', strtotime('+4 days'));

echo "Démo et direction\n";
http("demo.php?cle=" . rawurlencode($cle) . "&neuf=1"); $r = http('demo.php?prep=1'); t('agence fictive créée', ($r['json']['ok'] ?? false) === true);
http('demo.php?role=direction'); $r = c('init'); t('écran conciergerie accessible', $r['code'] === 200 && isset($r['json']['logements']), $r['body']);
$agents = $r['json']['agents'] ?? [];

echo "Logement et propriétaire\n";
$r = c('proprietaire_save', ['nom' => 'Mme Propriétaire Test', 'email' => 'proprio@test.invalid', 'plafond' => '500']); $pid = (int)($r['json']['id'] ?? 0); t('propriétaire créé', $pid > 0, $r['body']);
$r = c('logement_save', ['nom' => 'Villa Test', 'ville' => 'Marrakech', 'adresse' => 'Route de Fès', 'proprietaire_id' => $pid, 'acces' => 'Boîte à clés à gauche du portail', 'code' => '4719',
  'checklist' => [['piece' => 'Chambre', 'photos' => 1], ['piece' => 'Cuisine', 'photos' => 1]], 'kit_linge' => 'Kit B']);
$lid = (int)($r['json']['id'] ?? 0); t('logement créé', $lid > 0, $r['body']);
$r = c('logement_save', ['nom' => 'Mauvais calendrier', 'ical' => 'http://exemple.invalid/cal.ics']); t('calendrier non https refusé', $r['code'] === 400);
$r = c('logement_get', null, "id=$lid"); t('code d\'accès relu (chiffré en base)', ($r['json']['logement']['code'] ?? '') === '4719');

echo "Réservations\n";
$r = c('reservation_save', ['logement_id' => $lid, 'arrivee' => $j, 'depart' => $j2, 'nb' => 2, 'libelle' => 'Séjour A']); $ra = (int)($r['json']['id'] ?? 0); t('réservation directe A', $ra > 0, $r['body']);
$r = c('reservation_save', ['logement_id' => $lid, 'arrivee' => $j2, 'depart' => $j4, 'nb' => 1, 'libelle' => 'Séjour B']); $rb = (int)($r['json']['id'] ?? 0); t('réservation directe B', $rb > 0, $r['body']);
$r = c('reservation_save', ['logement_id' => $lid, 'arrivee' => $j4, 'depart' => $j2]); t('départ avant arrivée refusé', $r['code'] === 400);
$r = c('missions', null, "from=$j&to=$j4"); $M = array_values(array_filter($r['json']['missions'] ?? [], fn($m) => (int)$m['logement_id'] === $lid));
t('mission de ménage créée au départ de chaque séjour', count($M) === 2 && $M[0]['date'] === $j2, json_encode($M));
$r = c('reservation_get', null, "id=$rb"); $fv = $r['json']['reservation']['fv'] ?? [];
t('feu vert B refusé : fiche et ménage manquants', isset($fv['ok']) && !$fv['ok'] && !$fv['fiches'] && !$fv['menage'], json_encode($fv));
$lien = (string)($r['json']['reservation']['lien'] ?? ''); preg_match('/t=([a-f0-9]{48})/', $lien, $m); $tok = $m[1] ?? ''; t('lien voyageur sécurisé', $tok !== '');

echo "Fiche voyageur\n";
t('page voyageur', http('voyageur.php?t=' . $tok, null, false, false)['code'] === 200);
$r = c('voy_info', null, 't=' . $tok); t('séjour visible avec le lien', ($r['json']['logement'] ?? '') === 'Villa Test' && ($r['json']['attendues'] ?? 0) === 1, $r['body']);
t('lien inventé refusé', c('voy_info', null, 't=' . str_repeat('a', 48))['code'] === 404);
$fiche = ['t' => $tok, 'nom' => 'Alaoui', 'prenom' => 'Samir', 'sexe' => 'M', 'date_naissance' => '1985-04-12', 'lieu_naissance' => 'Rabat', 'nationalite' => 'Marocaine', 'adresse' => '12 rue Test, Rabat',
  'piece_type' => 'cin', 'piece_numero' => 'AB123456', 'email' => 'voyageur@test.invalid'];
$r = http('conciergerie_api.php?action=voy_fiche', array_merge($fiche, ['nom' => '']), true); t('champ obligatoire manquant signalé', ($r['json']['error'] ?? '') === 'champ:nom', $r['body']);
$r = http('conciergerie_api.php?action=voy_fiche', $fiche, true); t('pièce exigée quand le réglage le demande', ($r['json']['error'] ?? '') === 'champ:piece', $r['body']);
$r = http('conciergerie_api.php?action=voy_fiche', $fiche + ['piece' => jpeg()], true); t('fiche et pièce reçues', ($r['json']['recues'] ?? 0) === 1, $r['body']);

echo "Direction : fiches et pièce\n";
$r = c('reservation_get', null, "id=$rb"); $F = $r['json']['fiches'] ?? []; t('fiche visible par la direction titulaire', count($F) === 1 && str_contains($F[0]['resume'] ?? '', 'ALAOUI'), json_encode($F));
$r = http('conciergerie_api.php?action=piece_voir&id=' . ($F[0]['id'] ?? 0)); t('pièce affichée floutée et filigranée (image)', $r['code'] === 200 && str_starts_with($r['type'], 'image/jpeg') && strlen($r['body']) > 1000, $r['type']);
$r = c('fiches_declaration', null, "id=$rb"); t('fiches complètes pour la déclaration', ($r['json']['fiches'][0]['piece_numero'] ?? '') === 'AB123456', $r['body']);
$r = c('code_envoyer', ['id' => $rb]); t('code refusé sans feu vert', ($r['json']['envoye'] ?? null) === false, $r['body']);

echo "Équipe terrain\n";
http('demo.php?role=equipe'); $s = http('api.php?action=session'); $aid = (int)($s['json']['animatrice']['id'] ?? 0); t('connexion équipe', $aid > 0, $s['body']);
t('équipe : administration refusée', c('init')['code'] === 401);
http('demo.php?role=direction'); $mid = (int)$M[0]['id'];
$r = c('mission_save', ['id' => $mid, 'animatrice_id' => $aid, 'date' => $j2, 'heure' => '11:00']); t('mission attribuée', ($r['json']['ok'] ?? false) === true, $r['body']);
http('demo.php?role=equipe');
$r = c('mes_missions'); t('mission visible dans l\'appli', in_array($mid, array_map(fn($m) => (int)$m['id'], $r['json']['missions'] ?? []), true), $r['body']);
$r = c('ma_mission', null, "id=$mid"); t('check-list de la mission', count($r['json']['mission']['pieces'] ?? []) === 2, $r['body']);
t('consignes d\'accès masquées avant la veille de la mission', ($r['json']['mission']['acces'] ?? 'x') === '', $r['body']);
$r = c('mission_terminer', ['id' => $mid]); t('fin refusée sans photos', ($r['json']['ok'] ?? null) === false && count($r['json']['manques'] ?? []) === 2, $r['body']);
$r = c('mission_piece', ['id' => $mid, 'piece' => 0, 'ok' => true]); t('pièce non validable sans sa photo', $r['code'] === 400);
foreach ([0, 1] as $i) { $r = http('conciergerie_api.php?action=mission_photo', ['id' => $mid, 'piece' => $i, 'photo' => jpeg()], true); t("photo de la pièce $i", ($r['json']['ok'] ?? false) === true, $r['body']);
  $r = c('mission_piece', ['id' => $mid, 'piece' => $i, 'ok' => true]); t("pièce $i validée", ($r['json']['ok'] ?? false) === true, $r['body']); }
$r = c('mission_terminer', ['id' => $mid]); t('mission terminée', ($r['json']['ok'] ?? false) === true, $r['body']);

echo "Feu vert et code\n";
http('demo.php?role=direction');
$r = c('reservation_get', null, "id=$rb"); t('feu vert B donné', ($r['json']['reservation']['fv']['ok'] ?? false) === true, json_encode($r['json']['reservation']['fv'] ?? []));
$r = c('code_envoyer', ['id' => $rb]); t('code envoyé', ($r['json']['envoye'] ?? false) === true && ($r['json']['code'] ?? '') === '4719', $r['body']);
http('demo.php?role=equipe');
$r = http('conciergerie_api.php?action=incident_signaler', ['mission' => $mid, 'description' => 'Fuite sous l\'évier', 'bloquant' => '1', 'photo' => jpeg()], true); t('signalement avec photo', ($r['json']['ok'] ?? false) === true, $r['body']);
http('demo.php?role=direction');
$r = c('reservation_get', null, "id=$rb"); t('incident bloquant : feu vert retiré', ($r['json']['reservation']['fv']['ok'] ?? true) === false);
$r = c('incidents'); $inc = $r['json']['incidents'][0] ?? []; t('signalement listé', ($inc['description'] ?? '') === 'Fuite sous l\'évier', $r['body']);
$r = c('incident_maj', ['id' => $inc['id'] ?? 0, 'bloquant' => true, 'cout' => '350', 'valider_proprio' => true]); t('dépense soumise au propriétaire', ($r['json']['ok'] ?? false) === true, $r['body']);

echo "Livret d'arrivée\n";
$r = c('logement_save', ['id' => $lid, 'nom' => 'Villa Test', 'ville' => 'Marrakech', 'adresse' => 'Route de Fès', 'proprietaire_id' => $pid, 'acces' => 'Boîte à clés à gauche du portail', 'code' => '4719', 'checklist' => [['piece' => 'Chambre', 'photos' => 1], ['piece' => 'Cuisine', 'photos' => 1]], 'position' => 'https://maps.app.goo.gl/AbCdEf']);
t('lien Google Maps raccourci : explication donnée', $r['code'] === 400 && str_contains($r['json']['error'] ?? '', 'adresse complète'), $r['body']);
$r = http('conciergerie_api.php?action=logement_photo', ['photo' => jpeg()], true); $phe = (string)($r['json']['photo'] ?? ''); t('photo d\'étape enregistrée', $phe !== '', $r['body']);
$r = c('logement_save', ['id' => $lid, 'nom' => 'Villa Test', 'ville' => 'Marrakech', 'adresse' => 'Route de Fès', 'proprietaire_id' => $pid, 'acces' => 'Boîte à clés à gauche du portail', 'code' => '4719', 'checklist' => [['piece' => 'Chambre', 'photos' => 1], ['piece' => 'Cuisine', 'photos' => 1]],
  'actif' => true, 'position' => 'https://www.google.com/maps/place/Villa/@31.6295,-7.9811,17z/data=!3m1', 'livret' => ['repere' => 'Porte verte après la mosquée', 'wifi_nom' => 'VillaTest', 'wifi_mdp' => 'secret-wifi-99', 'etapes' => [['texte' => 'La porte', 'photo' => $phe], ['texte' => '', 'photo' => '']]]]);
t('position lue dans le lien Google Maps', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('logement_get', null, "id=$lid"); $LG = $r['json']['logement'] ?? [];
t('position fixée et livret relu', (float)($LG['lat'] ?? 0) === 31.6295 && ($LG['pos_fixe'] ?? 0) === 1 && count($LG['livret']['etapes'] ?? []) === 1 && ($LG['livret']['wifi_mdp'] ?? '') === 'secret-wifi-99', json_encode($LG));
$r = c('voy_info', null, 't=' . $tok); $LV = $r['json']['livret'] ?? null;
t('voyageur : chemin visible avant l\'arrivée', ($LV['repere'] ?? '') === 'Porte verte après la mosquée' && !empty($LV['carte']['waze']), $r['body']);
t('voyageur : ni code ni Wi-Fi avant le jour d\'arrivée', ($LV['codes'] ?? true) === false && !str_contains($r['body'], '4719') && !str_contains($r['body'], 'secret-wifi-99'), $r['body']);

echo "Planning, WhatsApp et lien de mission\n";
$r = c('reservation_get', null, "id=$ra"); preg_match('/t=([a-f0-9]{48})/', (string)($r['json']['reservation']['lien'] ?? ''), $m); $tokA = $m[1] ?? '';
$r = c('voy_arrivee', ['t' => $tokA, 'mode' => 'avion', 'vol' => 'at 773', 'heure' => '16:40', 'whatsapp' => '06 61 23 45 67']); t('le voyageur donne son vol, son heure et son WhatsApp', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('aujourdhui'); $A = array_values(array_filter($r['json']['arrivees'] ?? [], fn($x) => (int)$x['id'] === $ra))[0] ?? [];
t('arrivée du jour : vol et heure', ($A['vol'] ?? '') === 'AT 773' && ($A['heure'] ?? '') === '16:40', json_encode($A));
t('WhatsApp : message de fiche vers le numéro du voyageur', str_starts_with($A['wa']['fiche'] ?? '', 'https://wa.me/212661234567?text='), json_encode($A['wa'] ?? []));
$r = c('planning', null, "date=$j"); t('planning : arrivée placée à 16:40', in_array('16:40', array_column($r['json']['arrivees'] ?? [], 'heure'), true), $r['body']);
$adm = (int)(c('init')['json']['admins'][0]['id'] ?? 0);
$r = c('tache_save', ['logement_id' => $lid, 'type' => 'accueil', 'date' => $j, 'heure' => '17:00', 'duree' => 45, 'qui' => 'g' . $adm, 'note' => 'Accueil test']); $tid = (int)($r['json']['id'] ?? 0); t('tâche d\'accueil confiée au gestionnaire', $tid > 0, $r['body']);
$r = c('planning', null, "date=$j"); $T = array_values(array_filter($r['json']['missions'] ?? [], fn($x) => $x['id'] === $tid))[0] ?? []; t('tâche au planning du gestionnaire', ($T['admin'] ?? 0) === $adm && ($T['fin'] - $T['debut']) === 45, json_encode($T));
$r = c('mission_save', ['logement_id' => $lid, 'type' => 'controle', 'date' => $j, 'heure' => '10:00', 'animatrice_id' => $aid]); $cid = (int)($r['json']['id'] ?? 0);
$r = c('mission_lien', ['id' => $cid]); preg_match('/#([a-f0-9]{48})$/', (string)($r['json']['lien'] ?? ''), $m); $mt = $m[1] ?? '';
t('lien de mission pour l\'agent, prêt pour WhatsApp', $mt !== '' && str_contains((string)$r['json']['texte'], 'Villa Test'), $r['body']);
// Lien ouvert sans compte d'équipe (la démo garde son cookie de démo ; seule la session direction est présente).
t('page de mission sans compte', http('agent.php', null, false, false)['code'] === 200);
$r = c('ma_mission', null, 't=' . $mt); t('mission ouverte avec le seul lien', (int)($r['json']['mission']['id'] ?? 0) === $cid, $r['body']);
$LV = $r['json']['mission']['livret'] ?? []; t('agent : itinéraire, repère et étapes en photos', str_contains($LV['carte']['maps'] ?? '', 'destination=31.6295,-7.9811') && ($LV['repere'] ?? '') === 'Porte verte après la mosquée' && str_contains($LV['etapes'][0]['photo'] ?? '', '&s='), json_encode($LV));
t('agent : code donné le jour de la mission', ($LV['code'] ?? '') === '4719');
t('lien de mission inventé refusé', c('ma_mission', null, 't=' . str_repeat('c', 48))['code'] >= 400);
$r = c('mission_debut', ['t' => $mt, 'id' => $cid, 'lat' => 31.6295, 'lng' => -7.9811]); t('« J\'arrive » avec la position', ($r['json']['ok'] ?? false) === true, $r['body']);
foreach ([0, 1] as $i) { http('conciergerie_api.php?action=mission_photo', ['t' => $mt, 'id' => $cid, 'piece' => $i, 'photo' => jpeg()], true); c('mission_piece', ['t' => $mt, 'id' => $cid, 'piece' => $i, 'ok' => true]); }
$r = c('mission_terminer', ['t' => $mt, 'id' => $cid, 'lat' => 31.6296, 'lng' => -7.9812]); t('« J\'ai fini » avec la position', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('mes_missions'); t('le lien ne donne pas accès au reste de l\'appli', $r['code'] === 401);
$r = c('mission_get', null, "id=$cid"); t('direction : positions d\'arrivée et de départ', ($r['json']['mission']['debut_pos'] ?? '') === 'sur place' && ($r['json']['mission']['fin_pos'] ?? '') === 'sur place', $r['body']);

echo "Avis et services à l'arrivée\n";
$r = c('voy_info', null, 't=' . $tokA); t('avis d\'arrivée proposé le jour même', in_array('arrivee', $r['json']['avis'] ?? [], true) && ($r['json']['en_sejour'] ?? false) === true, $r['body']);
$r = http('conciergerie_api.php?action=voy_avis', ['t' => $tokA, 'moment' => 'arrivee', 'proprete' => '5', 'commentaire' => 'Impeccable'], true); t('avis de propreté reçu', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = http('conciergerie_api.php?action=voy_avis', ['t' => $tokA, 'moment' => 'arrivee', 'proprete' => '4'], true); t('pas de second avis d\'arrivée', $r['code'] === 400, $r['body']);
$r = http('conciergerie_api.php?action=voy_probleme', ['t' => $tokA, 'description' => ''], true); t('problème vide refusé', ($r['json']['error'] ?? '') === 'champ:description', $r['body']);
$r = http('conciergerie_api.php?action=voy_probleme', ['t' => $tokA, 'description' => 'Plus d\'eau chaude', 'photo' => jpeg()], true); t('problème signalé par le voyageur', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('incidents'); t('signalement du voyageur reçu par la conciergerie', in_array('Plus d\'eau chaude', array_column($r['json']['incidents'] ?? [], 'description'), true));
$r = c('service_save', ['nom' => 'Corbeille de fruits', 'nom_en' => 'Fruit basket', 'prix' => '180', 'cout' => '70', 'delai_h' => 24]); $sid = (int)($r['json']['id'] ?? 0); t('service ajouté au menu', $sid > 0, $r['body']);
$r = c('voy_info', null, 't=' . $tok); $S = $r['json']['services'] ?? []; t('menu visible par le voyageur, sans le coût', count($S) >= 1 && !array_key_exists('cout', $S[0]), json_encode($S));
$r = c('voy_commande', ['t' => $tok, 'services' => [$sid => 2]]); t('le voyageur commande', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('reservation_get', null, "id=$rb"); t('commande visible sur la réservation', ($r['json']['commandes'][0]['quantite'] ?? 0) == 2, $r['body']);
$r = c('services'); t('marge du mois calculée', (float)($r['json']['marges']['marge'] ?? 0) >= 220.0, json_encode($r['json']['marges'] ?? []));
$r = c('avis'); t('avis listé avec la note par logement', in_array('Villa Test', array_column($r['json']['logements'] ?? [], 'nom'), true), $r['body']);

echo "Questions des voyageurs\n";
$r = c('voy_question', ['t' => $tokA, 'question' => 'Peut-on arriver à 11 h ?']); t('question du voyageur reçue (sans assistant : transmise)', ($r['json']['ok'] ?? false) === true && ($r['json']['transmis'] ?? false) === true, $r['body']);
$r = c('aujourdhui'); t('question à traiter sur l\'écran du jour', in_array('Peut-on arriver à 11 h ?', array_column($r['json']['questions'] ?? [], 'texte'), true), $r['body']);
$r = c('message_repondre', ['id' => $ra, 'texte' => 'Oui, le logement sera prêt à 11 h.']); t('réponse du gestionnaire', ($r['json']['ok'] ?? false) === true, $r['body']);
$r = c('voy_info', null, 't=' . $tokA); $Ms = $r['json']['messages'] ?? []; t('réponse visible sur la page du voyageur', (end($Ms)['texte'] ?? '') === 'Oui, le logement sera prêt à 11 h.' && (end($Ms)['auteur'] ?? '') === 'gestion', json_encode($Ms));
$r = c('aujourdhui'); t('question traitée', !in_array('Peut-on arriver à 11 h ?', array_column($r['json']['questions'] ?? [], 'texte'), true));
$r = c('wa_brouillon', ['id' => $ra, 'message' => 'Bonjour']); t('brouillon refusé proprement sans assistant', $r['code'] === 400, $r['body']);

echo "Espace propriétaire\n";
$r = c('proprietaire_lien', ['id' => $pid]); preg_match('/#([a-f0-9]{48})$/', (string)($r['json']['lien'] ?? ''), $m); $pt = $m[1] ?? ''; t('lien propriétaire créé', $pt !== '', $r['body']);
t('page propriétaire', http('proprietaire.php', null, false, false)['code'] === 200);
$r = c('prop_vue', ['t' => $pt]); $V = $r['json'] ?? [];
t('le propriétaire voit son logement, ses séjours et l\'intervention', count($V['logements'] ?? []) === 1 && count($V['reservations'] ?? []) === 2 && count($V['missions'] ?? []) === 2, $r['body']);
t('notes des voyageurs dans l\'espace propriétaire', ($V['notes'][0]['proprete'] ?? 0) == 5, json_encode($V['notes'] ?? []));
t('aucune donnée de voyageur dans l\'espace propriétaire', !str_contains($r['body'], 'Alaoui') && !str_contains($r['body'], 'AB123456'));
$ph = $V['missions'][0]['photos'][0] ?? ''; t('photos de l\'intervention par lien signé', $ph !== '' && http($ph, null, false, false)['code'] === 200, $ph);
$r = c('prop_valider', ['t' => $pt, 'id' => $inc['id'] ?? 0, 'decision' => 'ok']); t('dépense acceptée par le propriétaire', ($r['json']['ok'] ?? false) === true, $r['body']);
t('lien propriétaire inventé refusé', c('prop_vue', ['t' => str_repeat('b', 48)])['code'] === 404);

echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
