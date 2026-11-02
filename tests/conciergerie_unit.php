<?php
// ORVIA : contrôles de la bibliothèque conciergerie sur une installation de test (jamais la production).
// Calendriers iCal, annulations, missions, chiffrement des champs, scellé et effacement, prise en compte au changement de clé.
// Usage : php tests/conciergerie_unit.php <dossier d'une installation de test>
if (PHP_SAPI !== 'cli') exit;
$dir = rtrim($argv[1] ?? '', '/'); if (!$dir || !is_file("$dir/config.php")) { fwrite(STDERR, "Usage : php tests/conciergerie_unit.php <dossier>\n"); exit(2); }
chdir($dir); $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require "$dir/lib.php"; require_once "$dir/conciergerie_lib.php";
$ok = 0; $ko = 0;
function t(string $n, $c, string $i = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($i ? " : $i" : '') . "\n"; } }
$d = fn(int $n) => date('Ymd', strtotime("+$n days"));
$ics = fn(array $ev) => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . implode('', array_map(fn($e) => "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:{$e[1]}\r\nDTEND;VALUE=DATE:{$e[2]}\r\nUID:{$e[0]}\r\nSUMMARY:{$e[3]}\r\n END\r\nEND:VEVENT\r\n", $ev)) . "END:VCALENDAR\r\n";

echo "Lecture des calendriers\n";
$E = cz_ical_parse($ics([['a1@airbnb', $d(3), $d(6), 'Reserved'], ['a2@airbnb', $d(8), $d(10), 'Airbnb (Not available)']]));
t('deux événements lus', count($E) === 2 && $E[0]['debut'] === date('Y-m-d', strtotime('+3 days')) && $E[0]['fin'] === date('Y-m-d', strtotime('+6 days')));
t('ligne repliée recollée', str_ends_with($E[0]['titre'], 'END') || $E[0]['titre'] === 'ReservedEND');
t('Airbnb : réservation reconnue', cz_ical_est_reservation($E[0], 'airbnb'));
t('Airbnb : date bloquée ignorée', !cz_ical_est_reservation($E[1], 'airbnb'));
t('source détectée par l\'adresse', cz_source_url('https://www.airbnb.fr/calendar/ical/1.ics?s=x') === 'airbnb' && cz_source_url('https://admin.booking.com/hotel/x.ics') === 'booking');

echo "Synchronisation et missions\n";
db()->prepare("INSERT INTO c_logements (nom, ville, ical, checklist, created_at) VALUES ('Riad Unitaire', 'Marrakech', 'https://www.airbnb.fr/calendar/ical/1.ics', '', ?)")->execute([now()]); $lid = (int)db()->lastInsertId();
$cal = $ics([['r1', $d(3), $d(6), 'Reserved'], ['r2', $d(6), $d(9), 'Reserved'], ['b1', $d(12), $d(14), 'Airbnb (Not available)']]);
$r = cz_sync_logement($lid, fn($u) => $cal); t('deux réservations importées, la date bloquée ignorée', $r === [2, 0, 0], json_encode($r));
$n = (int)db()->query("SELECT COUNT(*) FROM c_missions WHERE logement_id = $lid AND type = 'menage'")->fetchColumn(); t('une mission de ménage par départ', $n === 2);
$r = cz_sync_logement($lid, fn($u) => $cal); t('seconde lecture sans doublon', $r === [0, 0, 0], json_encode($r));
$cal2 = $ics([['r1', $d(3), $d(7), 'Reserved']]);
$r = cz_sync_logement($lid, fn($u) => $cal2); t('séjour prolongé mis à jour, séjour disparu annulé', $r === [0, 1, 1], json_encode($r));
$m = db()->query("SELECT date FROM c_missions m JOIN c_reservations r ON r.id = m.reservation_id WHERE r.uid = 'airbnb:r1'")->fetchColumn(); t('mission recalée sur le nouveau départ', $m === date('Y-m-d', strtotime('+7 days')));
$n = (int)db()->query("SELECT COUNT(*) FROM c_missions m JOIN c_reservations r ON r.id = m.reservation_id WHERE r.uid = 'airbnb:r2'")->fetchColumn(); t('mission du séjour annulé retirée', $n === 0);

echo "Chiffrement et conservation\n";
$c = cz_enc('Code 4719'); t('champ chiffré illisible en base', !str_contains(base64_decode($c), '4719') && cz_dec($c) === 'Code 4719');
$rid = (int)db()->query("SELECT id FROM c_reservations WHERE uid = 'airbnb:r1'")->fetchColumn();
db()->prepare("INSERT INTO c_voyageurs (reservation_id, data_enc, created_at) VALUES (?,?,?)")->execute([$rid, cz_enc('{"nom":"Test"}'), now()]);
$vieux = date('Y-m-d', strtotime('-' . (cz_conservation_jours() + 5) . ' days'));
db()->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, statut, lien_hash, lien_enc, created_at, updated_at) VALUES (?,'direct','', ?, ?, 'confirmee', ?, '', ?, ?)")
  ->execute([$lid, $vieux, $vieux, cz_hash(cz_jeton()), now(), now()]); $rv = (int)db()->lastInsertId();
$tmp = tempnam(sys_get_temp_dir(), 'pi'); $im = imagecreatetruecolor(50, 30); imagejpeg($im, $tmp); $piece = cz_piece_enregistrer($tmp);
t('pièce stockée chiffrée', !str_contains((string)file_get_contents(upload_dir() . "/voyageurs/$piece.bin"), 'JFIF'));
db()->prepare("INSERT INTO c_voyageurs (reservation_id, data_enc, piece, created_at) VALUES (?,?,?,?)")->execute([$rv, cz_enc('{"nom":"Ancien"}'), $piece, now()]);
$r = cz_sceller_et_purger(); t('fiche ancienne effacée avec sa pièce', $r['effacees'] === 1 && !is_file(upload_dir() . "/voyageurs/$piece.bin"), json_encode($r));
t('fiche en cours ni scellée ni effacée', (int)db()->query("SELECT COUNT(*) FROM c_voyageurs WHERE reservation_id = $rid AND scelle_at IS NULL")->fetchColumn() === 1);
require_once "$dir/cle_lib.php"; $types = array_count_values(array_map(fn($e) => $e[0], cle_elements()));
t('champs ORVIA inclus dans le changement de clé', ($types['colonnes'] ?? 0) >= 3, json_encode($types));
t('jeton : empreinte indépendante de la clé', cz_hash('abc') === hash('sha256', 'abc'));

echo "WhatsApp, lien de mission, position\n";
t('numéro marocain 06 normalisé en 2126', cz_wa_num('06 61 23 45 67') === '212661234567', cz_wa_num('06 61 23 45 67'));
t('numéro international conservé', cz_wa_num('+33 6 12 34 56 78') === '33612345678' && cz_wa_num('0033612345678') === '33612345678');
t('lien wa.me avec message encodé', str_starts_with(cz_wa_lien('0661234567', 'Bonjour à vous'), 'https://wa.me/212661234567?text=Bonjour%20%C3%A0%20vous'));
t('pas de lien sans numéro', cz_wa_lien('', 'x') === '');
db()->prepare("INSERT INTO c_missions (logement_id, type, date, heure, statut, etat, created_at) VALUES (?, 'menage', ?, '11:00', 'a_faire', '[]', ?)")->execute([$lid, date('Y-m-d'), now()]); $mid = (int)db()->lastInsertId();
$u = cz_mission_lien($mid); $jt = substr($u, strpos($u, '#') + 1);
t('lien de mission : jeton long, seule son empreinte est gardée', strlen($jt) === 48 && db()->query("SELECT lien_hash FROM c_missions WHERE id = $mid")->fetchColumn() === cz_hash($jt));
t('jeton de mission reconnu', (int)(cz_mission_par_jeton($jt)['id'] ?? 0) === $mid);
t('jeton faux refusé', cz_mission_par_jeton(str_repeat('a', 48)) === null && cz_mission_par_jeton('') === null);
db()->exec("UPDATE c_missions SET date = '" . date('Y-m-d', strtotime('-3 days')) . "' WHERE id = $mid");
t('jeton expiré après la mission', cz_mission_par_jeton($jt) === null);
db()->exec("UPDATE c_missions SET date = '" . date('Y-m-d') . "' WHERE id = $mid"); $m = cz_mission($mid);
t('première arrivée : position du logement apprise', cz_position($m, 'debut', 31.6295, -7.9811) === 0 && db()->query("SELECT lat FROM c_logements WHERE id = $lid")->fetchColumn() !== null);
$dl = cz_position($m, 'fin', 31.6300, -7.9811); t('départ sur place (moins de 150 m)', $dl !== null && $dl < 150, (string)$dl);
$dl = cz_position($m, 'fin', 31.6500, -7.9811); t('départ éloigné mesuré', $dl > 2000 && str_contains(cz_position_texte($dl), 'km'), (string)$dl);
t('position absurde ignorée', cz_position($m, 'debut', 'x', 500) === null && cz_position_texte(null) === 'position non transmise');

echo "Livret d'arrivée\n";
t('coordonnées simples lues', cz_parse_position('31.6295, -7.9811') === [31.6295, -7.9811]);
t('lien Google Maps (@lat,lng) lu', cz_parse_position('https://www.google.com/maps/place/X/@31.62,-7.98,17z') === [31.62, -7.98]);
t('lien Google Maps (!3d!4d) prioritaire', cz_parse_position('https://www.google.com/maps/place/X/@31.0,-7.0,17z/data=!3d31.6295!4d-7.9811') === [31.6295, -7.9811]);
t('lien de partage (q=) lu', cz_parse_position('https://maps.google.com/?q=30.4105,-9.6020') === [30.4105, -9.602]);
t('lien raccourci et texte refusés', cz_parse_position('https://maps.app.goo.gl/xyz') === null && cz_parse_position('Derb Zitoun') === null);
t('itinéraires Google Maps et Waze', cz_liens_carte(31.6295, -7.9811)['waze'] === 'https://waze.com/ul?ll=31.6295,-7.9811&navigate=yes');
db()->prepare("UPDATE c_logements SET livret_enc = ? WHERE id = ?")->execute([cz_enc(json_encode(['wifi_mdp' => 'mdp-secret', 'repere' => 'Porte verte'])), $lid]);
t('livret chiffré en base', !str_contains((string)db()->query("SELECT livret_enc FROM c_logements WHERE id = $lid")->fetchColumn(), 'mdp-secret'));
$lv = cz_livret(cz_logement($lid), false); t('livret sans codes : ni code ni Wi-Fi', !isset($lv['wifi_mdp']) && !isset($lv['code']) && $lv['repere'] === 'Porte verte');
t('livret avec codes', cz_livret(cz_logement($lid), true)['wifi_mdp'] === 'mdp-secret');
$rv = ['logement_id' => $lid, 'arrivee' => date('Y-m-d', strtotime('+10 days')), 'depart' => date('Y-m-d', strtotime('+12 days')), 'code_envoye_at' => null];
t('voyageur : rien plus de 7 jours avant', cz_livret_voyageur($rv) === null);
$rv['arrivee'] = date('Y-m-d'); t('voyageur : pas de code sans envoi du code', cz_livret_voyageur($rv)['codes'] === false);
$rv['code_envoye_at'] = now(); t('voyageur : codes le jour d\'arrivée une fois le code envoyé', cz_livret_voyageur($rv)['codes'] === true);
$rv['arrivee'] = date('Y-m-d', strtotime('-5 days')); $rv['depart'] = date('Y-m-d', strtotime('-1 day')); t('voyageur : plus rien après le départ', cz_livret_voyageur($rv) === null);

echo "Avis, services, planning\n";
db()->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, nb_voyageurs, statut, lien_hash, lien_enc, arrivee_heure, created_at, updated_at) VALUES (?,'direct','', ?, ?, 2, 'confirmee', ?, '', '14:00', ?, ?)")
  ->execute([$lid, date('Y-m-d'), date('Y-m-d', strtotime('+3 days')), cz_hash(cz_jeton()), now(), now()]); $ra = cz_reservation((int)db()->lastInsertId());
t('avis d\'arrivée ouvert le jour même', cz_avis_ouverts($ra) === ['arrivee'], json_encode(cz_avis_ouverts($ra)));
try { cz_avis_enregistrer($ra, 'sejour', ['logement' => 5], null); t('avis de séjour refusé pendant le séjour', false); } catch (RuntimeException $e) { t('avis de séjour refusé pendant le séjour', true); }
try { cz_avis_enregistrer($ra, 'arrivee', [], null); t('note de propreté exigée', false); } catch (InvalidArgumentException $e) { t('note de propreté exigée', $e->getMessage() === 'champ:proprete'); }
$inc0 = (int)db()->query("SELECT COUNT(*) FROM c_incidents")->fetchColumn();
cz_avis_enregistrer($ra, 'arrivee', ['proprete' => 2, 'commentaire' => 'Poussière'], null);
$av = db()->query("SELECT * FROM c_avis WHERE reservation_id = {$ra['id']}")->fetch();
t('avis rattaché au dernier ménage', (int)$av['mission_id'] === $mid && (int)$av['proprete'] === 2);
t('note basse : signalement ouvert', (int)db()->query("SELECT COUNT(*) FROM c_incidents")->fetchColumn() === $inc0 + 1);
t('un seul avis d\'arrivée', cz_avis_ouverts($ra) === []);
db()->prepare("INSERT INTO c_services (nom, prix, cout, delai_h, actif, ordre, created_at) VALUES ('Eau', 60, 25, 2, 1, 0, ?), ('Transfert', 450, 300, 48, 1, 1, ?)")->execute([now(), now()]); $s1 = (int)db()->lastInsertId();
db()->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, nb_voyageurs, statut, lien_hash, lien_enc, arrivee_heure, created_at, updated_at) VALUES (?,'direct','', ?, ?, 2, 'confirmee', ?, '', '16:00', ?, ?)")
  ->execute([$lid, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+4 days')), cz_hash(cz_jeton()), now(), now()]); $rb = cz_reservation((int)db()->lastInsertId());
try { cz_commander($rb, [$s1 + 1 => 1]); t('délai de commande respecté', false); } catch (RuntimeException $e) { t('délai de commande respecté', str_contains($e->getMessage(), '48 h')); }
$f = cz_commander($rb, [$s1 => 3]); t('commande enregistrée', $f === ['3 × Eau']);
db()->exec("UPDATE c_services SET prix = 99 WHERE id = $s1");
t('prix figé au moment de la commande', (float)db()->query("SELECT prix FROM c_commandes WHERE reservation_id = {$rb['id']}")->fetchColumn() === 60.0);
t('préparation ajoutée à une mission', str_contains((string)db()->query("SELECT m.note FROM c_commandes c JOIN c_missions m ON m.id = c.mission_id WHERE c.reservation_id = {$rb['id']}")->fetchColumn(), '3 × Eau'));
$mg = cz_marges(date('Y-m-d'), date('Y-m-d')); t('marge calculée', (float)$mg['ca'] === 180.0 && (float)$mg['marge'] === 105.0, json_encode($mg));
db()->prepare("INSERT INTO c_missions (logement_id, type, date, heure, duree, statut, etat, created_at) VALUES (?, 'menage', ?, '13:00', 120, 'a_faire', '[]', ?)")->execute([$lid, date('Y-m-d'), now()]);
$P = cz_planning(date('Y-m-d')); $tx = implode(' | ', array_column($P['conflits'], 'texte'));
t('planning : arrivée du jour à son heure', in_array('14:00', array_column($P['arrivees'], 'heure'), true));
t('planning : ménage qui finit après l\'arrivée signalé', str_contains($tx, 'après l\'arrivée prévue à 14:00'), $tx);
t('planning : mission non attribuée signalée', str_contains($tx, 'non attribué'), $tx);

echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
