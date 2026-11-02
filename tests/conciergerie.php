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

echo "Espace propriétaire\n";
$r = c('proprietaire_lien', ['id' => $pid]); preg_match('/#([a-f0-9]{48})$/', (string)($r['json']['lien'] ?? ''), $m); $pt = $m[1] ?? ''; t('lien propriétaire créé', $pt !== '', $r['body']);
t('page propriétaire', http('proprietaire.php', null, false, false)['code'] === 200);
$r = c('prop_vue', ['t' => $pt]); $V = $r['json'] ?? [];
t('le propriétaire voit son logement, ses séjours et l\'intervention', count($V['logements'] ?? []) === 1 && count($V['reservations'] ?? []) === 2 && count($V['missions'] ?? []) === 1, $r['body']);
t('aucune donnée de voyageur dans l\'espace propriétaire', !str_contains($r['body'], 'Alaoui') && !str_contains($r['body'], 'AB123456'));
$ph = $V['missions'][0]['photos'][0] ?? ''; t('photos de l\'intervention par lien signé', $ph !== '' && http($ph, null, false, false)['code'] === 200, $ph);
$r = c('prop_valider', ['t' => $pt, 'id' => $inc['id'] ?? 0, 'decision' => 'ok']); t('dépense acceptée par le propriétaire', ($r['json']['ok'] ?? false) === true, $r['body']);
t('lien propriétaire inventé refusé', c('prop_vue', ['t' => str_repeat('b', 48)])['code'] === 404);

echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
