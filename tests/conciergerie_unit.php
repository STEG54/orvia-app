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

echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
