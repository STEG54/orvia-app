<?php
// ORVIA : le cœur conciergerie. Logements, réservations (calendriers iCal), missions de ménage, fiches voyageurs, incidents, feu vert.
// Les données sensibles (codes d'accès, fiches voyageurs, pièces d'identité) sont chiffrées avec la clé des documents (amy_chiffre).

const CZ_SOURCES = ['airbnb' => 'Airbnb', 'booking' => 'Booking.com', 'abritel' => 'Abritel', 'direct' => 'Réservation directe', 'ical' => 'Autre calendrier'];
const CZ_MISSION_TYPES = ['menage' => 'Ménage', 'controle' => 'Contrôle', 'maintenance' => 'Maintenance'];
const CZ_MISSION_STATUTS = ['a_faire' => 'À faire', 'en_cours' => 'En cours', 'faite' => 'Faite', 'refusee' => 'À reprendre'];
const CZ_PIECES_DEFAUT = [['piece' => 'Chambre', 'photos' => 2], ['piece' => 'Salle de bain', 'photos' => 2], ['piece' => 'Cuisine', 'photos' => 2], ['piece' => 'Salon', 'photos' => 2]];
// Champs de la fiche voyageur (déclaration aux services de la Sûreté nationale au Maroc, fiche individuelle de police en France).
const CZ_FICHE_CHAMPS = ['nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'profession', 'adresse', 'piece_type', 'piece_numero', 'piece_delivrance', 'venant_de', 'allant_a', 'email', 'telephone'];
const CZ_FICHE_OBLIG = ['nom', 'prenom', 'sexe', 'date_naissance', 'lieu_naissance', 'nationalite', 'adresse', 'piece_type', 'piece_numero'];
// Colonnes chiffrées avec la clé des documents (rechiffrées par cle_lib.php lors d'un changement de clé).
const CZ_COLONNES_CHIFFREES = ['c_logements' => ['acces_enc', 'code_enc'], 'c_reservations' => ['lien_enc'], 'c_voyageurs' => ['data_enc']];
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
  if ($avecCode) { $o['acces'] = cz_dec($l['acces_enc']); $o['code'] = cz_dec($l['code_enc']); }
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
  $st = db()->prepare("SELECT m.*, l.nom logement, l.adresse, l.ville, l.checklist, l.kit_linge, l.acces_enc, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.id = ?");
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
  $st = db()->prepare("SELECT r.*, l.nom logement, l.ville FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.id = ?"); $st->execute([$id]); return $st->fetch() ?: null;
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
