<?php
// ORVIA : le cœur conciergerie. Logements, réservations (calendriers iCal), missions de ménage, fiches voyageurs, incidents, feu vert.
// Les données sensibles (codes d'accès, fiches voyageurs, pièces d'identité) sont chiffrées avec la clé des documents (amy_chiffre).

const CZ_SOURCES = ['airbnb' => 'Airbnb', 'booking' => 'Booking.com', 'abritel' => 'Abritel', 'direct' => 'Réservation directe', 'ical' => 'Autre calendrier'];
const CZ_MISSION_TYPES = ['menage' => 'Ménage', 'controle' => 'Contrôle', 'maintenance' => 'Maintenance', 'accueil' => 'Accueil voyageur', 'visite' => 'Visite propriétaire', 'transfert' => 'Transfert', 'autre' => 'Autre tâche'];
const CZ_ARRIVEE_MODES = ['avion' => 'Avion', 'voiture' => 'Voiture', 'train' => 'Train', 'autre' => 'Autre'];
const CZ_MISSION_STATUTS = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'faite' => 'Faite', 'refusee' => 'À reprendre'];
const CZ_PIECES_DEFAUT = [['piece' => 'Chambre', 'photos' => 2], ['piece' => 'Salle de bain', 'photos' => 2], ['piece' => 'Cuisine', 'photos' => 2], ['piece' => 'Salon', 'photos' => 2]];
// Champs de la fiche voyageur (déclaration aux services de la Sûreté nationale au Maroc, fiche individuelle de police en France).
const CZ_FICHE_CHAMPS = ['nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'profession', 'adresse', 'piece_type', 'piece_numero', 'piece_delivrance', 'venant_de', 'allant_a', 'email', 'telephone'];
const CZ_FICHE_OBLIG = ['nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'adresse', 'piece_type', 'piece_numero'];
// Colonnes chiffrées avec la clé des documents (rechiffrées par cle_lib.php lors d'un changement de clé).
const CZ_COLONNES_CHIFFREES = ['c_logements' => ['acces_enc', 'code_enc', 'livret_enc'], 'c_reservations' => ['lien_enc'], 'c_voyageurs' => ['data_enc']];
const CZ_PIECE_TYPES = ['cin' => 'Carte nationale d\'identité', 'passeport' => 'Passeport', 'sejour' => 'Carte de séjour'];

/* ---------- Chiffrement des champs ---------- */
function cz_enc(string $s): string { return $s === '' ? '' : base64_encode(amy_chiffre($s)); }
function cz_dec(?string $s): string { if (!$s) return ''; $b = base64_decode($s, true); return $b === false ? '' : (string)amy_dechiffre($b); }
function cz_jeton(): string { return bin2hex(random_bytes(24)); }
// Les jetons font 192 bits aléatoires : une empreinte simple suffit, et elle survit à un changement de clé des documents.
function cz_hash(string $t): string { return hash('sha256', $t); }
function cz_reglage_int(string $k, int $d): int { $v = (int)reglage($k, (string)$d); return $v > 0 ? $v : $d; }
/** Durée de conservation des fiches voyageurs, en jours (à valider avec votre conseil selon le pays). */
function cz_conservation_jours(): int { return cz_reglage_int('cz_conservation_jours', 365); }
function cz_copie_piece_demandee(): bool { return reglage('cz_copie_piece', '1') === '1'; }

/* ---------- Logements ---------- */
function cz_logement(int $id): ?array { $st = db()->prepare("SELECT * FROM c_logements WHERE id = ?"); $st->execute([$id]); return $st->fetch() ?: null; }
function cz_checklist(array $l): array {
  $c = json_decode((string)($l['checklist'] ?? ''), true);
  if (!is_array($c) || !$c) return CZ_PIECES_DEFAUT;
  return array_values(array_filter(array_map(fn($x) => ['piece' => clip($x['piece'] ?? '', 60), 'consignes' => clip($x['consignes'] ?? '', 400), 'photos' => max(0, min(10, (int)($x['photos'] ?? 1)))], $c), fn($x) => $x['piece'] !== ''));
}
function cz_logement_public(array $l, bool $avecCode = false): array {
  $o = ['id' => (int)$l['id'], 'nom' => $l['nom'], 'ville' => $l['ville'], 'adresse' => $l['adresse'], 'proprietaire_id' => $l['proprietaire_id'] ? (int)$l['proprietaire_id'] : null,
    'kit_linge' => $l['kit_linge'], 'duree_menage' => (int)$l['duree_menage'], 'checklist' => cz_checklist($l), 'actif' => (int)$l['actif'],
    'ical' => array_values(array_filter(array_map('trim', explode("\n", (string)$l['ical'])))), 'notes' => $l['notes'], 'ical_maj' => $l['ical_maj']];
  $o += ['lat' => $l['lat'] ?? null, 'lng' => $l['lng'] ?? null, 'pos_fixe' => (int)($l['pos_fixe'] ?? 0), 'carte' => cz_liens_carte($l['lat'] ?? null, $l['lng'] ?? null)];
  if ($avecCode) { $o['acces'] = cz_dec($l['acces_enc']); $o['code'] = cz_dec($l['code_enc']); $b = cz_livret_brut($l);
    $b['etapes'] = array_map(fn($e) => $e + ['url' => $e['photo'] !== '' ? photo_url($e['photo'], true) : ''], $b['etapes']); $o['livret'] = $b; }
  return $o;
}

/* ---------- Calendriers iCal (Airbnb, Booking, Abritel…) ---------- */
function cz_source_url(string $u): string {
  $h = strtolower((string)parse_url($u, PHP_URL_HOST));
  return str_contains($h, 'airbnb') ? 'airbnb' : (str_contains($h, 'booking') ? 'booking' : (str_contains($h, 'abritel') || str_contains($h, 'vrbo') || str_contains($h, 'homeaway') ? 'abritel' : 'ical'));
}
/** Lit un calendrier iCal : liste d'événements [uid, debut, fin, titre]. Dates au format AAAA-MM-JJ (fin = jour du départ). */
function cz_ical_parse(string $txt): array {
  $txt = preg_replace("/\r?\n[ \t]/", '', str_replace("\r\n", "\n", $txt)); // lignes repliées
  $out = [];
  foreach (preg_split('/BEGIN:VEVENT/', $txt) as $i => $bloc) {
    if ($i === 0) continue;
    $bloc = explode('END:VEVENT', $bloc)[0]; $e = [];
    foreach (explode("\n", $bloc) as $l) {
      if (!preg_match('/^([A-Z-]+)(;[^:]*)?:(.*)$/', trim($l), $m)) continue;
      $e[$m[1]] = trim($m[3]);
    }
    $d = fn($v) => preg_match('/^(\d{4})(\d{2})(\d{2})/', (string)$v, $x) ? "$x[1]-$x[2]-$x[3]" : null;
    $deb = $d($e['DTSTART'] ?? ''); $fin = $d($e['DTEND'] ?? '') ?: $deb;
    if (!$deb) continue;
    $out[] = ['uid' => clip($e['UID'] ?? md5($deb . $fin . ($e['SUMMARY'] ?? '')), 190), 'debut' => $deb, 'fin' => $fin, 'titre' => clip(str_replace(['\\,', '\\;', '\\n'], [',', ';', ' '], $e['SUMMARY'] ?? ''), 190)];
  }
  return $out;
}
/** Un événement correspond-il à une vraie réservation (et pas à une date bloquée par le propriétaire) ? */
function cz_ical_est_reservation(array $e, string $source): bool {
  $t = mb_strtolower($e['titre']);
  if ($source === 'airbnb') return !str_contains($t, 'not available') && !str_contains($t, 'indisponible');
  if (preg_match('/blocked|bloqu|closed|ferm|unavailable|indisponible|not available/u', $t)) return false;
  return true;
}
function cz_http_get(string $url): string {
  if (!preg_match('#^https://#i', $url)) throw new RuntimeException('Adresse de calendrier invalide : elle doit commencer par https://');
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'ORVIA-calendrier/1.0']);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($r === false || $code >= 400) throw new RuntimeException('Calendrier injoignable (code ' . $code . ').');
  if (!str_contains($r, 'BEGIN:VCALENDAR')) throw new RuntimeException('Ce lien ne renvoie pas un calendrier iCal.');
  return $r;
}
/** Synchronise un logement depuis ses calendriers. $lire permet de fournir le contenu (tests, démo). Renvoie [ajoutees, modifiees, annulees]. */
function cz_sync_logement(int $lid, ?callable $lire = null): array {
  $l = cz_logement($lid); if (!$l) throw new RuntimeException('Logement introuvable.');
  $urls = array_values(array_filter(array_map('trim', explode("\n", (string)$l['ical']))));
  $vus = []; $aj = 0; $mod = 0; $ann = 0; $auj = date('Y-m-d');
  foreach ($urls as $u) {
    $src = cz_source_url($u);
    $evts = cz_ical_parse($lire ? $lire($u) : cz_http_get($u));
    foreach ($evts as $e) {
      if (!cz_ical_est_reservation($e, $src) || $e['fin'] < $auj) continue;
      $uid = $src . ':' . $e['uid']; $vus[] = $uid;
      $st = db()->prepare("SELECT id, arrivee, depart, statut FROM c_reservations WHERE logement_id = ? AND uid = ?"); $st->execute([$lid, $uid]); $r = $st->fetch();
      if (!$r) {
        $tok = cz_jeton();
        db()->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, libelle, nb_voyageurs, statut, lien_hash, lien_enc, created_at, updated_at) VALUES (?,?,?,?,?,?,1,'confirmee',?,?,?,?)")
          ->execute([$lid, $src, $uid, $e['debut'], $e['fin'], $e['titre'], cz_hash($tok), cz_enc($tok), now(), now()]);
        $aj++; cz_mission_depart((int)db()->lastInsertId());
      } elseif ($r['arrivee'] !== $e['debut'] || $r['depart'] !== $e['fin'] || $r['statut'] !== 'confirmee') {
        db()->prepare("UPDATE c_reservations SET arrivee = ?, depart = ?, statut = 'confirmee', updated_at = ? WHERE id = ?")->execute([$e['debut'], $e['fin'], now(), $r['id']]);
        $mod++; cz_mission_depart((int)$r['id']);
      }
    }
  }
  // Réservations à venir disparues du calendrier : annulées (on ne touche pas aux réservations saisies à la main).
  if ($urls) {
    $st = db()->prepare("SELECT id, uid FROM c_reservations WHERE logement_id = ? AND source <> 'direct' AND statut = 'confirmee' AND arrivee > ?"); $st->execute([$lid, $auj]);
    foreach ($st->fetchAll() as $r) if (!in_array($r['uid'], $vus, true)) { cz_annuler((int)$r['id']); $ann++; }
  }
  db()->prepare("UPDATE c_logements SET ical_maj = ? WHERE id = ?")->execute([now(), $lid]);
  return [$aj, $mod, $ann];
}
function cz_annuler(int $rid): void {
  db()->prepare("UPDATE c_reservations SET statut = 'annulee', updated_at = ? WHERE id = ?")->execute([now(), $rid]);
  db()->prepare("DELETE FROM c_missions WHERE reservation_id = ? AND statut = 'a_faire'")->execute([$rid]);
}
/** Synchronise tous les logements actifs (tâche planifiée). */
function cz_sync_tout(): array {
  $res = [];
  foreach (db()->query("SELECT id, nom FROM c_logements WHERE actif = 1 AND ical <> ''")->fetchAll() as $l) {
    try { $res[$l['nom']] = cz_sync_logement((int)$l['id']); } catch (Throwable $e) { $res[$l['nom']] = $e->getMessage(); error_log('ORVIA calendrier ' . $l['nom'] . ' : ' . $e->getMessage()); }
  }
  return $res;
}

/* ---------- Missions ---------- */
/** Crée (ou recale) la mission de ménage du jour de départ d'une réservation. */
function cz_mission_depart(int $rid): void {
  $st = db()->prepare("SELECT r.*, l.checklist FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.id = ?"); $st->execute([$rid]); $r = $st->fetch();
  if (!$r || $r['statut'] !== 'confirmee') return;
  $st = db()->prepare("SELECT id, date, statut FROM c_missions WHERE reservation_id = ? AND type = 'menage'"); $st->execute([$rid]); $m = $st->fetch();
  if (!$m) {
    db()->prepare("INSERT INTO c_missions (logement_id, reservation_id, type, date, heure, statut, etat, created_at) VALUES (?,?,'menage',?,?,'a_faire','[]',?)")
      ->execute([$r['logement_id'], $rid, $r['depart'], reglage('cz_heure_menage', '11:00'), now()]);
    cz_attribuer((int)db()->lastInsertId());
  } elseif ($m['date'] !== $r['depart'] && $m['statut'] === 'a_faire') {
    db()->prepare("UPDATE c_missions SET date = ? WHERE id = ?")->execute([$r['depart'], $m['id']]);
  }
}
/** Attribue une mission sans intervenant : la personne habituelle du logement, sinon celle qui a le moins de missions ce jour-là. */
function cz_attribuer(int $mid): void {
  $st = db()->prepare("SELECT m.*, l.agent_id FROM c_missions m JOIN c_logements l ON l.id = m.logement_id WHERE m.id = ?"); $st->execute([$mid]); $m = $st->fetch();
  if (!$m || $m['animatrice_id']) return;
  $aid = (int)$m['agent_id'];
  if (!$aid) {
    $st = db()->prepare("SELECT a.id, (SELECT COUNT(*) FROM c_missions x WHERE x.animatrice_id = a.id AND x.date = ?) n FROM animatrices a WHERE a.actif = 1 AND a.statut IN ('actif','sans_offres') ORDER BY n, a.id LIMIT 1");
    $st->execute([$m['date']]); $aid = (int)$st->fetchColumn();
  }
  if ($aid) db()->prepare("UPDATE c_missions SET animatrice_id = ? WHERE id = ?")->execute([$aid, $mid]);
}
function cz_mission(int $id): ?array {
  $st = db()->prepare("SELECT m.*, l.nom logement, l.adresse, l.ville, l.checklist, l.kit_linge, l.acces_enc, l.code_enc, l.livret_enc, l.lat, l.lng, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.id = ?");
  $st->execute([$id]); return $st->fetch() ?: null;
}
function cz_etat(array $m): array { $e = json_decode((string)$m['etat'], true); return is_array($e) ? $e : []; }
/** Une mission est-elle complète : chaque pièce cochée avec le nombre de photos demandé ? Renvoie la liste de ce qui manque. */
function cz_mission_manques(array $m): array {
  $etat = cz_etat($m); $manque = [];
  foreach (cz_checklist($m) as $i => $p) {
    $e = $etat[$i] ?? []; $n = count((array)($e['photos'] ?? []));
    if (empty($e['ok'])) $manque[] = $p['piece'] . ' : non validée';
    elseif ($n < $p['photos']) $manque[] = $p['piece'] . ' : ' . ($p['photos'] - $n) . ' photo(s) manquante(s)';
  }
  return $manque;
}

/* ---------- Réservations et feu vert ---------- */
function cz_reservation(int $id): ?array {
  $st = db()->prepare("SELECT r.*, l.nom logement, l.ville, l.adresse FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.id = ?"); $st->execute([$id]); return $st->fetch() ?: null;
}
function cz_reservation_par_jeton(string $t): ?array {
  if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
  $st = db()->prepare("SELECT r.*, l.nom logement, l.ville, l.adresse FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.lien_hash = ? AND r.statut = 'confirmee'"); $st->execute([cz_hash($t)]);
  return $st->fetch() ?: null;
}
function cz_nb_fiches(int $rid): int { $st = db()->prepare("SELECT COUNT(*) FROM c_voyageurs WHERE reservation_id = ?"); $st->execute([$rid]); return (int)$st->fetchColumn(); }
/** Le feu vert d'une réservation : les trois conditions et ce qui bloque. */
function cz_feu_vert(array $r): array {
  $att = max(1, (int)$r['nb_voyageurs']); $n = cz_nb_fiches((int)$r['id']);
  $fiches = $n >= $att;
  // Le dernier ménage avant l'arrivée (mission du départ précédent, ou mission de contrôle) doit être fait.
  $st = db()->prepare("SELECT id, statut, date FROM c_missions WHERE logement_id = ? AND type IN ('menage','controle') AND date <= ? ORDER BY date DESC, id DESC LIMIT 1");
  $st->execute([$r['logement_id'], $r['arrivee']]); $m = $st->fetch();
  $menage = $m ? $m['statut'] === 'faite' : true; // premier séjour du logement : pas de ménage précédent connu
  $st = db()->prepare("SELECT COUNT(*) FROM c_incidents WHERE logement_id = ? AND statut = 'ouvert' AND bloquant = 1"); $st->execute([$r['logement_id']]);
  $incidents = (int)$st->fetchColumn();
  $bloque = [];
  if (!$fiches) $bloque[] = "Fiches voyageurs : $n sur $att";
  if (!$menage) $bloque[] = 'Ménage du ' . date('d/m', strtotime($m['date'])) . ' pas encore validé';
  if ($incidents) $bloque[] = $incidents . ' incident(s) bloquant(s) ouvert(s)';
  return ['ok' => !$bloque, 'fiches' => $fiches, 'nb_fiches' => $n, 'attendues' => $att, 'menage' => $menage, 'incidents' => $incidents, 'bloque' => $bloque];
}
/** Lien du formulaire voyageur (adresse complète de l'instance). */
function cz_lien_voyageur(array $r): string {
  $base = (isset($_SERVER['HTTP_HOST']) ? ((($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname((string)$_SERVER['SCRIPT_NAME']), '/') : (defined('APP_URL') ? APP_URL : ''));
  return $base . '/voyageur.php?t=' . cz_dec($r['lien_enc']);
}
/** Envoie le code d'accès au voyageur si le feu vert est donné. */
function cz_envoyer_code(int $rid, bool $forcer = false): array {
  $r = cz_reservation($rid); if (!$r) throw new RuntimeException('Réservation introuvable.');
  $fv = cz_feu_vert($r); if (!$fv['ok'] && !$forcer) return ['envoye' => false, 'bloque' => $fv['bloque']];
  $l = cz_logement((int)$r['logement_id']); $code = cz_dec($l['code_enc']); $acces = cz_dec($l['acces_enc']);
  $email = cz_email_reservation($rid);
  if ($email !== '') {
    require_once __DIR__ . '/report_lib.php';
    $html = '<div style="font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#111">'
      . '<p>Bonjour,</p><p>Votre logement <b>' . h($l['nom']) . '</b> est prêt à vous accueillir le ' . h(date('d/m/Y', strtotime($r['arrivee']))) . '.</p>'
      . ($code !== '' ? '<p style="font-size:22px;letter-spacing:4px"><b>' . h($code) . '</b></p>' : '')
      . ($acces !== '' ? '<p>' . nl2br(h($acces)) . '</p>' : '') . '<p>' . h(AG_NOM) . '</p></div>';
    mail_send($email, 'Votre arrivée : ' . $l['nom'], $html);
  }
  db()->prepare("UPDATE c_reservations SET code_envoye_at = ? WHERE id = ?")->execute([now(), $rid]);
  journal('code_acces', 'réservation ' . $rid . ($forcer ? ' (envoi forcé)' : ''));
  return ['envoye' => true, 'email' => $email !== '' ? email_masque($email) : '', 'code' => $code];
}
function cz_email_reservation(int $rid): string {
  $st = db()->prepare("SELECT data_enc FROM c_voyageurs WHERE reservation_id = ? ORDER BY id"); $st->execute([$rid]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $f = json_decode(cz_dec($d), true) ?: []; if (filter_var($f['email'] ?? '', FILTER_VALIDATE_EMAIL)) return $f['email']; }
  return '';
}

/* ---------- Fiches voyageurs ---------- */
function cz_fiche_propre(array $in): array {
  $f = [];
  foreach (CZ_FICHE_CHAMPS as $k) $f[$k] = clip($in[$k] ?? '', $k === 'adresse' ? 300 : 120);
  $f['sexe'] = in_array($f['sexe'], ['F', 'M'], true) ? $f['sexe'] : '';
  $f['piece_type'] = isset(CZ_PIECE_TYPES[$f['piece_type']]) ? $f['piece_type'] : '';
  foreach (['date_naissance', 'piece_delivrance'] as $k) if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
  if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $f['email'] = '';
  foreach (CZ_FICHE_OBLIG as $k) if ($f[$k] === '') throw new InvalidArgumentException('champ:' . $k);
  return $f;
}
function cz_pieces_dir(): string {
  $d = upload_dir() . '/voyageurs'; if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\n"); } return $d;
}
/** Enregistre la photo d'une pièce : ré-encodée (métadonnées retirées), chiffrée, jamais lisible directement. */
function cz_piece_enregistrer(string $tmp): string {
  $info = @getimagesize($tmp);
  if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Photo de la pièce : format JPEG, PNG ou WebP.');
  $src = match ($info[2]) { IMAGETYPE_JPEG => imagecreatefromjpeg($tmp), IMAGETYPE_PNG => imagecreatefrompng($tmp), IMAGETYPE_WEBP => imagecreatefromwebp($tmp) };
  $w = imagesx($src); $hh = imagesy($src); $r = min(1, 1600 / max($w, $hh));
  $dst = imagecreatetruecolor((int)round($w * $r), (int)round($hh * $r)); imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $hh);
  ob_start(); imagejpeg($dst, null, 85); $jpg = (string)ob_get_clean(); imagedestroy($src); imagedestroy($dst);
  $nom = bin2hex(random_bytes(16)); file_put_contents(cz_pieces_dir() . '/' . $nom . '.bin', amy_chiffre($jpg)); @unlink($tmp);
  return $nom;
}
/** Image d'une pièce pour un dirigeant : floutée, filigranée à son nom, avec la date et l'heure. Chaque ouverture est journalisée. */
function cz_piece_consultation(string $nom, string $qui): string {
  if (!preg_match('/^[a-f0-9]{32}$/', $nom)) throw new RuntimeException('Pièce introuvable.');
  $b = @file_get_contents(cz_pieces_dir() . '/' . $nom . '.bin'); if ($b === false) throw new RuntimeException('Pièce introuvable ou déjà effacée.');
  $img = imagecreatefromstring((string)amy_dechiffre($b)); if (!$img) throw new RuntimeException('Pièce illisible.');
  for ($i = 0; $i < 6; $i++) imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
  $w = imagesx($img); $hh = imagesy($img); $c = imagecolorallocatealpha($img, 20, 20, 20, 70);
  $txt = 'Consulté par ' . $qui . ' · ' . date('d.m.Y H:i'); $txt = iconv('UTF-8', 'ASCII//TRANSLIT', $txt);
  for ($y = 10; $y < $hh; $y += 28) for ($x = -200 + ($y % 56); $x < $w; $x += 9 * strlen($txt) + 40) imagestring($img, 4, $x, $y, $txt, $c);
  ob_start(); imagejpeg($img, null, 80); imagedestroy($img);
  doc_journal('pièce voyageur ' . substr($nom, 0, 8), $qui);
  return (string)ob_get_clean();
}
/** Après le départ : les fiches passent sous scellé (plus visibles de l'équipe). Après la durée de conservation : effacées. */
function cz_sceller_et_purger(): array {
  $auj = date('Y-m-d');
  $n1 = db()->prepare("UPDATE c_voyageurs v JOIN c_reservations r ON r.id = v.reservation_id SET v.scelle_at = ? WHERE v.scelle_at IS NULL AND r.depart < ?");
  $n1->execute([now(), $auj]);
  $limite = date('Y-m-d', strtotime('-' . cz_conservation_jours() . ' days'));
  $st = db()->prepare("SELECT v.id, v.piece FROM c_voyageurs v JOIN c_reservations r ON r.id = v.reservation_id WHERE r.depart < ?"); $st->execute([$limite]); $n2 = 0;
  foreach ($st->fetchAll() as $v) {
    if ($v['piece'] && preg_match('/^[a-f0-9]{32}$/', $v['piece'])) @unlink(cz_pieces_dir() . '/' . $v['piece'] . '.bin');
    db()->prepare("DELETE FROM c_voyageurs WHERE id = ?")->execute([$v['id']]); $n2++;
  }
  if ($n2) journal('effacement', $n2 . ' fiche(s) voyageur arrivée(s) au terme de la conservation');
  return ['scellees' => $n1->rowCount(), 'effacees' => $n2];
}

/* ---------- Propriétaires ---------- */
function cz_proprietaire_par_jeton(string $t): ?array {
  if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
  $st = db()->prepare("SELECT * FROM c_proprietaires WHERE jeton_hash = ? AND actif = 1"); $st->execute([cz_hash($t)]); return $st->fetch() ?: null;
}

/* ---------- WhatsApp (liens « cliquer pour discuter » : aucun compte ni coût) ---------- */
/** Numéro au format international sans « + » : 06 12 34 56 78 (Maroc) devient 212612345678. */
function cz_wa_num(string $t): string {
  $d = preg_replace('/\D/', '', $t); if ($d === '') return '';
  if (str_starts_with($d, '00')) $d = substr($d, 2);
  if (strlen($d) === 10 && $d[0] === '0') $d = (reglage('cz_pays', 'MA') === 'MA' ? '212' : '33') . substr($d, 1);
  return strlen($d) >= 10 && strlen($d) <= 15 ? $d : '';
}
function cz_wa_lien(string $tel, string $texte): string { $n = cz_wa_num($tel); return $n === '' ? '' : 'https://wa.me/' . $n . '?text=' . rawurlencode($texte); }
function cz_base_url(): string { return preg_replace('#/[^/]*$#', '', cz_lien_voyageur(['lien_enc' => cz_enc('x')])); }
/** Numéro WhatsApp d'un séjour : celui donné à l'arrivée, sinon le téléphone de la première fiche. */
function cz_wa_reservation(array $r): string {
  $w = cz_dec($r['whatsapp_enc'] ?? ''); if ($w !== '') return $w;
  $st = db()->prepare("SELECT data_enc FROM c_voyageurs WHERE reservation_id = ? ORDER BY id"); $st->execute([$r['id']]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $f = json_decode(cz_dec($d), true) ?: []; if (!empty($f['telephone'])) return (string)$f['telephone']; }
  return '';
}

/* ---------- Lien de mission (agent sans compte, ouvert depuis WhatsApp) ---------- */
function cz_mission_lien(int $mid): string {
  $t = cz_jeton(); db()->prepare("UPDATE c_missions SET lien_hash = ? WHERE id = ?")->execute([cz_hash($t), $mid]);
  return cz_base_url() . '/agent.php#' . $t;
}
function cz_mission_par_jeton(string $t): ?array {
  if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
  $st = db()->prepare("SELECT id, date FROM c_missions WHERE lien_hash = ?"); $st->execute([cz_hash($t)]); $m = $st->fetch();
  if (!$m || $m['date'] < date('Y-m-d', strtotime('-1 day'))) return null; // le lien expire le lendemain de la mission
  return cz_mission((int)$m['id']);
}

/* ---------- Position à l'arrivée et au départ de l'agent ---------- */
/** Note la position (début ou fin de mission) et sa distance au logement, en mètres. Aucun suivi entre les deux. */
function cz_position(array $m, string $k, $lat, $lng): ?int {
  if (!in_array($k, ['debut', 'fin'], true) || !is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) return null;
  $lat = round((float)$lat, 6); $lng = round((float)$lng, 6);
  $st = db()->prepare("SELECT lat, lng FROM c_logements WHERE id = ?"); $st->execute([$m['logement_id']]); $l = $st->fetch();
  $dist = null;
  if ($l && $l['lat'] !== null) { require_once __DIR__ . '/geo_lib.php'; $dist = (int)round(geo_vol([(float)$l['lat'], (float)$l['lng']], [$lat, $lng]) * 1000); }
  elseif ($k === 'debut') { db()->prepare("UPDATE c_logements SET lat = ?, lng = ? WHERE id = ?")->execute([$lat, $lng, $m['logement_id']]); $dist = 0; } // première arrivée : la position du logement est apprise
  db()->prepare("UPDATE c_missions SET {$k}_lat = ?, {$k}_lng = ?, {$k}_dist = ? WHERE id = ?")->execute([$lat, $lng, $dist, $m['id']]);
  return $dist;
}
function cz_position_texte(?int $d): string { return $d === null ? 'position non transmise' : ($d <= 150 ? 'sur place' : 'à ' . ($d < 1000 ? $d . ' m' : number_format($d / 1000, 1, ',', '') . ' km') . ' du logement'); }

/* ---------- Avis des voyageurs ---------- */
function cz_heure_arrivee(array $r): string { return preg_match('/^\d{2}:\d{2}$/', (string)($r['arrivee_heure'] ?? '')) ? $r['arrivee_heure'] : reglage('cz_heure_arrivee', '15:00'); }
/** Quels avis le voyageur peut donner maintenant : « arrivee » le jour de l'arrivée et pendant le séjour, « sejour » du départ à 14 jours après. */
function cz_avis_ouverts(array $r): array {
  $auj = date('Y-m-d'); $o = [];
  $st = db()->prepare("SELECT moment FROM c_avis WHERE reservation_id = ?"); $st->execute([$r['id']]); $faits = $st->fetchAll(PDO::FETCH_COLUMN);
  if ($auj >= $r['arrivee'] && $auj < $r['depart'] && !in_array('arrivee', $faits, true)) $o[] = 'arrivee';
  if ($auj >= $r['depart'] && $auj <= date('Y-m-d', strtotime($r['depart'] . ' +14 days')) && !in_array('sejour', $faits, true)) $o[] = 'sejour';
  return $o;
}
function cz_note($v): ?int { $v = (int)$v; return $v >= 1 && $v <= 5 ? $v : null; }
function cz_avis_enregistrer(array $r, string $moment, array $in, ?string $photo): void {
  if (!in_array($moment, cz_avis_ouverts($r), true)) throw new RuntimeException('Cet avis n\'est pas ouvert, ou a déjà été donné.');
  $pr = cz_note($in['proprete'] ?? 0); if ($moment === 'arrivee' && !$pr) throw new InvalidArgumentException('champ:proprete');
  $mid = null; $aid = null;
  // L'avis de propreté est rattaché au dernier ménage avant l'arrivée, donc à l'agent qui l'a fait.
  $st = db()->prepare("SELECT id, animatrice_id FROM c_missions WHERE logement_id = ? AND type = 'menage' AND date <= ? ORDER BY date DESC, id DESC LIMIT 1"); $st->execute([$r['logement_id'], $r['arrivee']]);
  if ($x = $st->fetch()) { $mid = (int)$x['id']; $aid = $x['animatrice_id'] ? (int)$x['animatrice_id'] : null; }
  $com = clip($in['commentaire'] ?? '', 1000);
  db()->prepare("INSERT INTO c_avis (reservation_id, logement_id, moment, proprete, logement, accueil, commentaire, photo, mission_id, animatrice_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([$r['id'], $r['logement_id'], $moment, $pr, cz_note($in['logement'] ?? 0), cz_note($in['accueil'] ?? 0), $com, $photo, $mid, $aid, now()]);
  if ($moment === 'arrivee' && $pr <= 3) cz_alerte_voyageur($r, 'Note de propreté à l\'arrivée : ' . $pr . '/5' . ($com !== '' ? '. « ' . $com . ' »' : ''), $photo, false);
}
/** Problème signalé par un voyageur : un signalement ouvert et un e-mail immédiat à la conciergerie. */
function cz_alerte_voyageur(array $r, string $texte, ?string $photo, bool $bloquant): void {
  db()->prepare("INSERT INTO c_incidents (logement_id, auteur, description, photo, bloquant, created_at) VALUES (?,?,?,?,?,?)")->execute([$r['logement_id'], 'Voyageur', clip($texte, 1000), $photo, $bloquant ? 1 : 0, now()]);
  try { require_once __DIR__ . '/report_lib.php'; mail_send(AG_EMAIL, 'Alerte voyageur : ' . $r['logement'], '<p style="font:15px sans-serif"><b>' . h($r['logement']) . '</b><br>' . nl2br(h($texte)) . '</p>'); } catch (Throwable $e) {}
}

/* ---------- Services à l'arrivée (eau, fruits, courses, transfert…) ---------- */
function cz_services(bool $tous = false): array { return db()->query("SELECT * FROM c_services" . ($tous ? '' : ' WHERE actif = 1') . " ORDER BY ordre, nom")->fetchAll(); }
/** Commande d'un voyageur : prix et coût figés au moment de la commande ; la préparation s'ajoute à la mission qui précède l'arrivée. */
function cz_commander(array $r, array $lignes): array {
  if ($r['arrivee'] < date('Y-m-d')) throw new RuntimeException('Les services à l\'arrivée se commandent avant le jour d\'arrivée.');
  $S = []; foreach (cz_services() as $s) $S[(int)$s['id']] = $s; $faites = []; $heures = (strtotime($r['arrivee'] . ' ' . cz_heure_arrivee($r)) - time()) / 3600;
  foreach ($lignes as $sid => $q) {
    $sid = (int)$sid; $q = max(0, min(20, (int)$q)); if (!$q || !isset($S[$sid])) continue;
    if ($heures < (int)$S[$sid]['delai_h']) throw new RuntimeException('« ' . $S[$sid]['nom'] . ' » se commande au moins ' . (int)$S[$sid]['delai_h'] . ' h avant l\'arrivée.');
    db()->prepare("INSERT INTO c_commandes (reservation_id, service_id, quantite, prix, cout, statut, created_at) VALUES (?,?,?,?,?,'confirmee',?)")->execute([$r['id'], $sid, $q, $S[$sid]['prix'], $S[$sid]['cout'], now()]);
    $faites[] = $q . ' × ' . $S[$sid]['nom'];
  }
  if (!$faites) throw new RuntimeException('Choisissez au moins un service.');
  // La préparation va dans la mission qui précède l'arrivée (ménage), sinon une mission « accueil » le jour d'arrivée.
  $st = db()->prepare("SELECT id, note FROM c_missions WHERE logement_id = ? AND type IN ('menage','accueil') AND date <= ? AND statut <> 'faite' ORDER BY date DESC, id DESC LIMIT 1"); $st->execute([$r['logement_id'], $r['arrivee']]); $m = $st->fetch();
  $txt = 'À préparer pour l\'arrivée du ' . date('d/m', strtotime($r['arrivee'])) . ' : ' . implode(', ', $faites);
  if ($m) { db()->prepare("UPDATE c_missions SET note = ? WHERE id = ?")->execute([clip(trim($m['note'] . "\n" . $txt), 500), $m['id']]); $mid = (int)$m['id']; }
  else { db()->prepare("INSERT INTO c_missions (logement_id, reservation_id, type, date, heure, statut, etat, note, created_at) VALUES (?,?,'accueil',?,?,'a_faire','[]',?,?)")->execute([$r['logement_id'], $r['id'], $r['arrivee'], cz_heure_arrivee($r), clip($txt, 500), now()]); $mid = (int)db()->lastInsertId(); cz_attribuer($mid); }
  db()->prepare("UPDATE c_commandes SET mission_id = ? WHERE reservation_id = ? AND mission_id IS NULL")->execute([$mid, $r['id']]);
  return $faites;
}
/** Chiffre d'affaires, coût et marge des services sur une période. */
function cz_marges(string $from, string $to): array {
  $st = db()->prepare("SELECT s.nom, SUM(c.quantite) n, SUM(c.quantite * c.prix) ca, SUM(c.quantite * c.cout) cout FROM c_commandes c JOIN c_services s ON s.id = c.service_id WHERE c.statut <> 'annulee' AND DATE(c.created_at) BETWEEN ? AND ? GROUP BY s.id ORDER BY ca DESC");
  $st->execute([$from, $to]); $L = $st->fetchAll(); $t = ['ca' => 0, 'cout' => 0];
  foreach ($L as &$l) { $l['marge'] = round($l['ca'] - $l['cout'], 2); $t['ca'] += $l['ca']; $t['cout'] += $l['cout']; }
  return ['lignes' => $L, 'ca' => round($t['ca'], 2), 'cout' => round($t['cout'], 2), 'marge' => round($t['ca'] - $t['cout'], 2)];
}

/* ---------- Planning du jour ---------- */
function cz_hm(string $h): int { return preg_match('/^(\d{2}):(\d{2})$/', $h, $x) ? (int)$x[1] * 60 + (int)$x[2] : 0; }
function cz_duree(array $m): int { return (int)($m['duree'] ?? 0) ?: ($m['type'] === 'menage' ? (int)($m['duree_menage'] ?? 120) : 60); }
function cz_planning(string $date): array {
  $st = db()->prepare("SELECT m.*, l.nom logement, l.ville, l.duree_menage FROM c_missions m JOIN c_logements l ON l.id = m.logement_id WHERE m.date = ? ORDER BY m.heure"); $st->execute([$date]); $M = $st->fetchAll();
  $st = db()->prepare("SELECT r.*, l.nom logement FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.statut = 'confirmee' AND (r.arrivee = ? OR r.depart = ?)"); $st->execute([$date, $date]); $R = $st->fetchAll();
  $items = []; $conf = [];
  foreach ($M as $m) {
    $d = cz_duree($m); $deb = cz_hm($m['heure'] ?: '09:00');
    $items[] = ['id' => (int)$m['id'], 'type' => $m['type'], 'logement' => $m['logement'], 'logement_id' => (int)$m['logement_id'], 'heure' => $m['heure'] ?: '09:00', 'debut' => $deb, 'fin' => $deb + $d, 'duree' => $d,
      'statut' => $m['statut'], 'agent' => $m['animatrice_id'] ? (int)$m['animatrice_id'] : null, 'admin' => $m['admin_id'] ? (int)$m['admin_id'] : null, 'note' => $m['note']];
    if (!$m['animatrice_id'] && !$m['admin_id'] && $m['statut'] !== 'faite') $conf[] = ['niveau' => 'warn', 'texte' => ($m['heure'] ?: '') . ' · ' . $m['logement'] . ' : ' . (CZ_MISSION_TYPES[$m['type']] ?? $m['type']) . ' non attribué(e)', 'mission' => (int)$m['id']];
  }
  $arr = []; $dep = [];
  foreach ($R as $r) {
    if ($r['arrivee'] === $date) { $h = cz_heure_arrivee($r); $arr[] = ['id' => (int)$r['id'], 'logement' => $r['logement'], 'logement_id' => (int)$r['logement_id'], 'heure' => $h, 'minute' => cz_hm($h), 'vol' => $r['vol'], 'mode' => $r['arrivee_mode'], 'prevue' => $r['arrivee_heure'] === ''];
      // Ménage du jour qui finit après l'arrivée du voyageur
      foreach ($items as $it) if ($it['logement_id'] === (int)$r['logement_id'] && $it['type'] === 'menage' && $it['statut'] !== 'faite' && $it['fin'] > cz_hm($h))
        $conf[] = ['niveau' => 'bad', 'texte' => $r['logement'] . ' : le ménage finit vers ' . sprintf('%02d:%02d', intdiv($it['fin'], 60), $it['fin'] % 60) . ', après l\'arrivée prévue à ' . $h, 'mission' => $it['id']]; }
    if ($r['depart'] === $date) $dep[] = ['id' => (int)$r['id'], 'logement' => $r['logement'], 'heure' => reglage('cz_heure_depart', '11:00')];
  }
  // Deux missions qui se chevauchent pour la même personne
  $par = []; foreach ($items as $it) { $k = $it['agent'] ? 'a' . $it['agent'] : ($it['admin'] ? 'g' . $it['admin'] : ''); if ($k) $par[$k][] = $it; }
  foreach ($par as $L) { usort($L, fn($a, $b) => $a['debut'] <=> $b['debut']); for ($i = 1; $i < count($L); $i++) if ($L[$i]['debut'] < $L[$i - 1]['fin'])
    $conf[] = ['niveau' => 'bad', 'texte' => 'Chevauchement : ' . $L[$i - 1]['logement'] . ' (' . $L[$i - 1]['heure'] . ') et ' . $L[$i]['logement'] . ' (' . $L[$i]['heure'] . ')', 'mission' => $L[$i]['id']]; }
  return ['date' => $date, 'missions' => $items, 'arrivees' => $arr, 'departs' => $dep, 'conflits' => $conf];
}

/* ---------- Tâche planifiée ---------- */
function cz_cron(): void {
  cz_sync_tout();
  cz_sceller_et_purger();
  // Arrivées du jour et du lendemain : envoi automatique du code dès que le feu vert est donné.
  if (reglage('cz_code_auto', '1') === '1') {
    $st = db()->prepare("SELECT id FROM c_reservations WHERE statut = 'confirmee' AND code_envoye_at IS NULL AND arrivee BETWEEN ? AND ?");
    $st->execute([date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) { try { cz_envoyer_code((int)$rid); } catch (Throwable $e) { error_log('ORVIA code : ' . $e->getMessage()); } }
  }
}

/* ---------- Livret d'arrivée ---------- */
// Au Maroc l'adresse ne suffit pas : le logement a une position fixée (lien Google Maps ou position prise sur place),
// un repère, des étapes en photos (rue, entrée, porte, boîte à clés) et ses codes. Le chemin se montre tôt, les codes au dernier moment.
const CZ_LIVRET_CHAMPS = ['repere' => 300, 'parking' => 300, 'serrure' => 300, 'wifi_nom' => 60, 'wifi_mdp' => 60, 'depart' => 600, 'urgence' => 60];
/** Coordonnées lues dans « 31.6295, -7.9811 » ou dans un lien Google Maps / Waze complet. Lien raccourci : null (il faut l'ouvrir puis copier l'adresse longue). */
function cz_parse_position(string $s): ?array {
  $s = trim(rawurldecode($s));
  foreach (['/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', '/@(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/', '/[?&](?:q|ll|query|destination|center)=(-?\d{1,2}\.\d+),\s*(-?\d{1,3}\.\d+)/', '/^(-?\d{1,2}\.\d+)\s*[,; ]\s*(-?\d{1,3}\.\d+)$/'] as $re)
    if (preg_match($re, $s, $x) && abs((float)$x[1]) <= 90 && abs((float)$x[2]) <= 180) return [round((float)$x[1], 6), round((float)$x[2], 6)];
  return null;
}
function cz_livret_brut(array $l): array {
  $j = json_decode(cz_dec($l['livret_enc'] ?? ''), true); $j = is_array($j) ? $j : [];
  $o = []; foreach (CZ_LIVRET_CHAMPS as $k => $n) $o[$k] = (string)($j[$k] ?? '');
  $o['etapes'] = array_values(array_filter(array_map(fn($e) => ['texte' => (string)($e['texte'] ?? ''), 'photo' => preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', (string)($e['photo'] ?? '')) ? $e['photo'] : ''], (array)($j['etapes'] ?? [])), fn($e) => $e['texte'] !== '' || $e['photo'] !== ''));
  return $o;
}
function cz_livret_nettoyer(array $in): array {
  $o = []; foreach (CZ_LIVRET_CHAMPS as $k => $n) $o[$k] = clip($in[$k] ?? '', $n);
  $o['etapes'] = [];
  foreach (array_slice((array)($in['etapes'] ?? []), 0, 12) as $e) { $t = clip($e['texte'] ?? '', 400); $ph = preg_match('/^[a-zA-Z0-9_.-]{1,80}$/', (string)($e['photo'] ?? '')) ? $e['photo'] : ''; if ($t !== '' || $ph !== '') $o['etapes'][] = ['texte' => $t, 'photo' => $ph]; }
  return $o;
}
function cz_liens_carte($lat, $lng): array {
  if ($lat === null || $lat === '') return [];
  $ll = rtrim(rtrim(number_format((float)$lat, 6, '.', ''), '0'), '.') . ',' . rtrim(rtrim(number_format((float)$lng, 6, '.', ''), '0'), '.');
  return ['maps' => 'https://www.google.com/maps/dir/?api=1&destination=' . $ll, 'waze' => 'https://waze.com/ul?ll=' . $ll . '&navigate=yes', 'll' => $ll];
}
/** Livret pour quelqu'un sans compte (voyageur, agent par lien). $codes : y joindre code, consignes et Wi-Fi. Photos par liens signés de courte durée. */
function cz_livret(array $l, bool $codes, int $jours = 3): array {
  $b = cz_livret_brut($l);
  $o = ['adresse' => (string)($l['adresse'] ?? ''), 'ville' => (string)($l['ville'] ?? ''), 'carte' => cz_liens_carte($l['lat'] ?? null, $l['lng'] ?? null),
    'repere' => $b['repere'], 'parking' => $b['parking'], 'wifi_nom' => $b['wifi_nom'], 'urgence' => $b['urgence'],
    'etapes' => array_map(fn($e) => ['texte' => $e['texte'], 'photo' => $e['photo'] !== '' ? photo_lien($e['photo'], $jours) : ''], $b['etapes']), 'codes' => $codes];
  if ($codes) $o += ['code' => cz_dec($l['code_enc'] ?? ''), 'acces' => cz_dec($l['acces_enc'] ?? ''), 'serrure' => $b['serrure'], 'wifi_mdp' => $b['wifi_mdp'], 'depart_consignes' => $b['depart']];
  return $o;
}
/** Ce que le voyageur voit : le chemin dès 7 jours avant l'arrivée ; les codes une fois le code envoyé (feu vert), du jour d'arrivée au départ. Rien après le départ. */
function cz_livret_voyageur(array $r): ?array {
  $auj = date('Y-m-d');
  if ($auj < date('Y-m-d', strtotime($r['arrivee'] . ' -7 days')) || $auj > $r['depart']) return null;
  $l = cz_logement((int)$r['logement_id']); if (!$l) return null;
  return cz_livret($l, $r['code_envoye_at'] !== null && $auj >= $r['arrivee']);
}
