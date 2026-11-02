<?php
// ORVIA : API conciergerie. Quatre publics : la direction (administration), l'équipe terrain (appli), le voyageur (lien sécurisé), le propriétaire (lien personnel).
register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
  error_log('ORVIA API : ' . $e['message'] . ' ' . $e['file'] . ':' . $e['line']);
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode(['error' => 'Erreur serveur. Réessayez dans un instant.'], JSON_UNESCAPED_UNICODE);
});
require __DIR__ . '/lib.php';
require_once __DIR__ . '/conciergerie_lib.php';
start_session();
header('Cache-Control: no-store');

function c_out($d, int $c = 200) { http_response_code($c); header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function c_fail(string $m, int $c = 400) { c_out(['error' => $m], $c); }
if (!function_exists('fail')) { function fail(string $m, int $c = 400) { c_fail($m, $c); } } // utilisée par limite()
function c_admin(string $d = 'lecture'): void { if (!admin_ok()) c_fail('Accès réservé à la direction', 401); if (!can($d)) c_fail('Votre compte ne permet pas cette action.', 403); }
function c_agent(): int { $id = (int)($_SESSION['anim'] ?? 0); if (!$id) c_fail('Connectez-vous à l\'appli.', 401); return $id; }
function c_date($v): string { $v = (string)$v; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) c_fail('Date invalide.'); return $v; }
function c_mission_agent(int $mid, int $aid): array {
  $m = cz_mission($mid); if (!$m || (int)$m['animatrice_id'] !== $aid) c_fail('Mission introuvable.', 404); return $m;
}

$action = (string)($_GET['action'] ?? ''); demo_bloquer($action);
$multipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $multipart ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !requete_appli()) c_fail('Requête refusée', 403);

try {
  switch ($action) {

    /* ================= Direction ================= */
    case 'init':
      c_admin();
      c_out(['logements' => array_map(fn($l) => cz_logement_public($l), db()->query("SELECT * FROM c_logements ORDER BY actif DESC, ville, nom")->fetchAll()),
        'proprietaires' => db()->query("SELECT id, nom, email, telephone, plafond, actif, (jeton_hash IS NOT NULL) lien FROM c_proprietaires ORDER BY nom")->fetchAll(),
        'agents' => db()->query("SELECT id, prenom, nom, telephone FROM animatrices WHERE actif = 1 AND statut IN ('actif','sans_offres') ORDER BY prenom, nom")->fetchAll(),
        'sources' => CZ_SOURCES, 'types' => CZ_MISSION_TYPES, 'statuts' => CZ_MISSION_STATUTS, 'titulaire' => est_titulaire(), 'gerer' => can('tout'),
        'reglages' => ['cz_conservation_jours' => cz_conservation_jours(), 'cz_copie_piece' => cz_copie_piece_demandee() ? '1' : '0', 'cz_code_auto' => reglage('cz_code_auto', '1'), 'cz_heure_menage' => reglage('cz_heure_menage', '11:00')]]);

    case 'aujourdhui':
      c_admin(); $j = date('Y-m-d'); $d = date('Y-m-d', strtotime('+1 day'));
      $st = db()->prepare("SELECT r.*, l.nom logement, l.ville FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.statut = 'confirmee' AND r.arrivee BETWEEN ? AND ? ORDER BY r.arrivee, l.nom"); $st->execute([$j, $d]);
      $arr = array_map(fn($r) => ['id' => (int)$r['id'], 'logement' => $r['logement'], 'ville' => $r['ville'], 'arrivee' => $r['arrivee'], 'depart' => $r['depart'], 'source' => $r['source'], 'libelle' => $r['libelle'],
        'code_envoye' => $r['code_envoye_at'], 'fv' => cz_feu_vert($r), 'lien' => cz_lien_voyageur($r)], $st->fetchAll());
      $st = db()->prepare("SELECT m.id, m.type, m.date, m.heure, m.statut, l.nom logement, l.ville, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date = ? OR (m.date < ? AND m.statut <> 'faite') ORDER BY m.date, m.heure");
      $st->execute([$j, $j]);
      $inc = db()->query("SELECT i.id, i.description, i.bloquant, i.created_at, l.nom logement FROM c_incidents i JOIN c_logements l ON l.id = i.logement_id WHERE i.statut = 'ouvert' ORDER BY i.bloquant DESC, i.created_at DESC LIMIT 30")->fetchAll();
      c_out(['arrivees' => $arr, 'missions' => $st->fetchAll(), 'incidents' => $inc]);

    case 'logement_get':
      c_admin(); $l = cz_logement((int)($_GET['id'] ?? 0)) ?: c_fail('Logement introuvable.', 404);
      c_out(['logement' => cz_logement_public($l, can('tout'))]);

    case 'logement_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0);
      $nom = clip($in['nom'] ?? '', 160); if ($nom === '') c_fail('Donnez un nom au logement.');
      $ical = implode("\n", array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($in['ical'] ?? ''))))));
      foreach (array_filter(explode("\n", $ical)) as $u) if (!preg_match('#^https://\S+$#i', $u)) c_fail('Chaque lien de calendrier doit commencer par https://');
      $chk = []; foreach ((array)($in['checklist'] ?? []) as $p) { $p = ['piece' => clip($p['piece'] ?? '', 60), 'consignes' => clip($p['consignes'] ?? '', 400), 'photos' => max(0, min(10, (int)($p['photos'] ?? 1)))]; if ($p['piece'] !== '') $chk[] = $p; }
      $v = [$nom, clip($in['ville'] ?? '', 80), clip($in['adresse'] ?? '', 300), ((int)($in['proprietaire_id'] ?? 0)) ?: null, ((int)($in['agent_id'] ?? 0)) ?: null,
        cz_enc(clip($in['acces'] ?? '', 1500)), cz_enc(clip($in['code'] ?? '', 40)), json_encode($chk ?: CZ_PIECES_DEFAUT, JSON_UNESCAPED_UNICODE), clip($in['kit_linge'] ?? '', 120),
        max(15, min(600, (int)($in['duree_menage'] ?? 120))), $ical, clip($in['notes'] ?? '', 2000), empty($in['actif']) && $id ? 0 : 1];
      if ($id) { $v[] = $id; db()->prepare("UPDATE c_logements SET nom=?, ville=?, adresse=?, proprietaire_id=?, agent_id=?, acces_enc=?, code_enc=?, checklist=?, kit_linge=?, duree_menage=?, ical=?, notes=?, actif=? WHERE id=?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_logements (nom, ville, adresse, proprietaire_id, agent_id, acces_enc, code_enc, checklist, kit_linge, duree_menage, ical, notes, actif, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      journal('logement', $nom);
      $sync = null; if ($ical !== '') { try { $sync = cz_sync_logement($id); } catch (Throwable $e) { $sync = $e->getMessage(); } }
      c_out(['ok' => true, 'id' => $id, 'sync' => $sync]);

    case 'logement_sync':
      c_admin('tout'); $r = cz_sync_logement((int)($in['id'] ?? 0));
      c_out(['ok' => true, 'ajoutees' => $r[0], 'modifiees' => $r[1], 'annulees' => $r[2]]);

    case 'reservations':
      c_admin(); $from = c_date($_GET['from'] ?? date('Y-m-d', strtotime('-3 days'))); $to = c_date($_GET['to'] ?? date('Y-m-d', strtotime('+30 days'))); $lid = (int)($_GET['logement'] ?? 0);
      $a = [$to, $from]; $w = ''; if ($lid) { $w = ' AND r.logement_id = ?'; $a[] = $lid; }
      $st = db()->prepare("SELECT r.*, l.nom logement, l.ville FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.arrivee <= ? AND r.depart >= ?$w ORDER BY r.arrivee, l.nom"); $st->execute($a);
      c_out(['reservations' => array_map(fn($r) => ['id' => (int)$r['id'], 'logement_id' => (int)$r['logement_id'], 'logement' => $r['logement'], 'ville' => $r['ville'], 'source' => $r['source'], 'libelle' => $r['libelle'],
        'arrivee' => $r['arrivee'], 'depart' => $r['depart'], 'nb' => (int)$r['nb_voyageurs'], 'statut' => $r['statut'], 'code_envoye' => $r['code_envoye_at'],
        'fv' => $r['statut'] === 'confirmee' ? cz_feu_vert($r) : null, 'lien' => $r['statut'] === 'confirmee' ? cz_lien_voyageur($r) : ''], $st->fetchAll())]);

    case 'reservation_save':
      c_admin('tout'); $lid = (int)($in['logement_id'] ?? 0); cz_logement($lid) ?: c_fail('Choisissez le logement.');
      $a = c_date($in['arrivee'] ?? ''); $d = c_date($in['depart'] ?? ''); if ($d <= $a) c_fail('Le départ doit être après l\'arrivée.');
      $nb = max(1, min(30, (int)($in['nb'] ?? 1))); $lib = clip($in['libelle'] ?? '', 190); $id = (int)($in['id'] ?? 0);
      if ($id) { db()->prepare("UPDATE c_reservations SET arrivee=?, depart=?, nb_voyageurs=?, libelle=?, updated_at=? WHERE id=?")->execute([$a, $d, $nb, $lib, now(), $id]); }
      else { $t = cz_jeton(); db()->prepare("INSERT INTO c_reservations (logement_id, source, uid, arrivee, depart, libelle, nb_voyageurs, statut, lien_hash, lien_enc, created_at, updated_at) VALUES (?,'direct','',?,?,?,?,'confirmee',?,?,?,?)")
        ->execute([$lid, $a, $d, $lib, $nb, cz_hash($t), cz_enc($t), now(), now()]); $id = (int)db()->lastInsertId(); }
      cz_mission_depart($id); c_out(['ok' => true, 'id' => $id]);

    case 'reservation_nb':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); db()->prepare("UPDATE c_reservations SET nb_voyageurs = ?, updated_at = ? WHERE id = ?")->execute([max(1, min(30, (int)($in['nb'] ?? 1))), now(), $id]); c_out(['ok' => true]);

    case 'reservation_annuler':
      c_admin('tout'); cz_annuler((int)($in['id'] ?? 0)); c_out(['ok' => true]);

    case 'reservation_get':
      c_admin(); $r = cz_reservation((int)($_GET['id'] ?? 0)) ?: c_fail('Réservation introuvable.', 404);
      $st = db()->prepare("SELECT id, data_enc, piece, created_at, scelle_at FROM c_voyageurs WHERE reservation_id = ? ORDER BY id"); $st->execute([$r['id']]); $tit = est_titulaire(); $F = [];
      foreach ($st->fetchAll() as $v) {
        $f = json_decode(cz_dec($v['data_enc']), true) ?: [];
        // L'équipe ne voit qu'un état : seule la direction titulaire lit le contenu, et jamais après la mise sous scellé sans le demander explicitement.
        $F[] = ['id' => (int)$v['id'], 'recue' => $v['created_at'], 'scelle' => (bool)$v['scelle_at'], 'piece' => (bool)$v['piece'],
          'resume' => $tit && !$v['scelle_at'] ? trim(($f['prenom'] ?? '') . ' ' . mb_strtoupper($f['nom'] ?? '')) . ' · ' . ($f['nationalite'] ?? '') : 'Fiche complète'];
      }
      c_out(['reservation' => ['id' => (int)$r['id'], 'logement' => $r['logement'], 'arrivee' => $r['arrivee'], 'depart' => $r['depart'], 'nb' => (int)$r['nb_voyageurs'], 'source' => $r['source'],
        'libelle' => $r['libelle'], 'statut' => $r['statut'], 'code_envoye' => $r['code_envoye_at'], 'lien' => cz_lien_voyageur($r), 'fv' => cz_feu_vert($r)], 'fiches' => $F, 'titulaire' => $tit]);

    case 'fiches_declaration':
      // Fiches complètes d'une réservation, pour la déclaration aux autorités. Direction titulaire seulement, journalisé.
      c_admin(); if (!est_titulaire()) c_fail('Réservé aux dirigeant(e)s.', 403);
      $r = cz_reservation((int)($_GET['id'] ?? 0)) ?: c_fail('Réservation introuvable.', 404);
      $st = db()->prepare("SELECT data_enc FROM c_voyageurs WHERE reservation_id = ? ORDER BY id"); $st->execute([$r['id']]);
      $L = array_map(fn($d) => json_decode(cz_dec($d), true) ?: [], $st->fetchAll(PDO::FETCH_COLUMN));
      doc_journal('fiches voyageurs réservation ' . $r['id'], admin_nom());
      c_out(['reservation' => ['logement' => $r['logement'], 'ville' => $r['ville'], 'arrivee' => $r['arrivee'], 'depart' => $r['depart']], 'fiches' => $L, 'types' => CZ_PIECE_TYPES]);

    case 'piece_voir':
      c_admin(); if (!est_titulaire()) c_fail('Réservé aux dirigeant(e)s.', 403);
      $st = db()->prepare("SELECT piece FROM c_voyageurs WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $p = (string)$st->fetchColumn();
      if ($p === '') c_fail('Aucune pièce pour cette fiche.', 404);
      $img = cz_piece_consultation($p, admin_nom());
      header('Content-Type: image/jpeg'); header('Content-Disposition: inline'); header('X-Content-Type-Options: nosniff'); echo $img; exit;

    case 'code_envoyer':
      c_admin('tout'); $forcer = !empty($in['forcer']); if ($forcer && !est_titulaire()) c_fail('Seul(e) un(e) dirigeant(e) peut passer outre le feu vert.', 403);
      c_out(cz_envoyer_code((int)($in['id'] ?? 0), $forcer));

    case 'missions':
      c_admin(); $from = c_date($_GET['from'] ?? date('Y-m-d')); $to = c_date($_GET['to'] ?? date('Y-m-d', strtotime('+7 days')));
      $st = db()->prepare("SELECT m.id, m.logement_id, m.reservation_id, m.type, m.date, m.heure, m.statut, m.animatrice_id, m.note, m.fin_at, l.nom logement, l.ville, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date BETWEEN ? AND ? ORDER BY m.date, m.heure, l.nom");
      $st->execute([$from, $to]); c_out(['missions' => $st->fetchAll()]);

    case 'mission_get':
      c_admin(); $m = cz_mission((int)($_GET['id'] ?? 0)) ?: c_fail('Mission introuvable.', 404); $etat = cz_etat($m); $P = [];
      foreach (cz_checklist($m) as $i => $p) $P[] = $p + ['ok' => !empty($etat[$i]['ok']), 'urls' => array_map(fn($f) => photo_url($f, true), (array)($etat[$i]['photos'] ?? [])), 'grandes' => array_map(fn($f) => photo_url($f), (array)($etat[$i]['photos'] ?? []))];
      c_out(['mission' => ['id' => (int)$m['id'], 'logement' => $m['logement'], 'type' => $m['type'], 'date' => $m['date'], 'heure' => $m['heure'], 'statut' => $m['statut'], 'agent' => $m['agent'], 'animatrice_id' => $m['animatrice_id'] ? (int)$m['animatrice_id'] : null,
        'note' => $m['note'], 'debut' => $m['debut_at'], 'fin' => $m['fin_at'], 'pieces' => $P, 'manques' => cz_mission_manques($m)]]);

    case 'mission_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $aid = ((int)($in['animatrice_id'] ?? 0)) ?: null; $date = c_date($in['date'] ?? ''); $heure = preg_match('/^\d{2}:\d{2}$/', (string)($in['heure'] ?? '')) ? $in['heure'] : '';
      if ($id) db()->prepare("UPDATE c_missions SET animatrice_id = ?, date = ?, heure = ? WHERE id = ?")->execute([$aid, $date, $heure, $id]);
      else { $lid = (int)($in['logement_id'] ?? 0); cz_logement($lid) ?: c_fail('Choisissez le logement.'); $t = isset(CZ_MISSION_TYPES[$in['type'] ?? '']) ? $in['type'] : 'controle';
        db()->prepare("INSERT INTO c_missions (logement_id, type, date, heure, animatrice_id, statut, etat, note, created_at) VALUES (?,?,?,?,?,'a_faire','[]',?,?)")->execute([$lid, $t, $date, $heure, $aid, clip($in['note'] ?? '', 500), now()]); $id = (int)db()->lastInsertId(); if (!$aid) cz_attribuer($id); }
      c_out(['ok' => true, 'id' => $id]);

    case 'mission_reprendre':
      c_admin('tout'); db()->prepare("UPDATE c_missions SET statut = 'refusee', note = ? WHERE id = ?")->execute([clip($in['note'] ?? 'À reprendre', 500), (int)($in['id'] ?? 0)]); c_out(['ok' => true]);

    case 'incidents':
      c_admin(); $s = ($_GET['statut'] ?? 'ouvert') === 'clos' ? 'clos' : 'ouvert';
      $st = db()->prepare("SELECT i.*, l.nom logement FROM c_incidents i JOIN c_logements l ON l.id = i.logement_id WHERE i.statut = ? ORDER BY i.created_at DESC LIMIT 200"); $st->execute([$s]);
      c_out(['incidents' => array_map(fn($i) => $i + ['photo_url' => $i['photo'] ? photo_url($i['photo'], true) : ''], $st->fetchAll())]);

    case 'incident_maj':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $cout = isset($in['cout']) && $in['cout'] !== '' ? montant($in['cout']) : null;
      db()->prepare("UPDATE c_incidents SET bloquant = ?, cout = ?, statut = ?, clos_at = IF(? = 'clos', ?, NULL), proprio_statut = IF(? = 1 AND proprio_statut = '', 'demande', proprio_statut) WHERE id = ?")
        ->execute([empty($in['bloquant']) ? 0 : 1, $cout, ($in['statut'] ?? '') === 'clos' ? 'clos' : 'ouvert', ($in['statut'] ?? '') === 'clos' ? 'clos' : '', now(), empty($in['valider_proprio']) ? 0 : 1, $id]);
      c_out(['ok' => true]);

    case 'proprietaire_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $nom = clip($in['nom'] ?? '', 160); if ($nom === '') c_fail('Le nom du propriétaire est obligatoire.');
      $em = clip($in['email'] ?? '', 160); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) c_fail('Adresse e-mail invalide.');
      $v = [$nom, $em, clip($in['telephone'] ?? '', 40), (float)(montant($in['plafond'] ?? 0) ?? 0)];
      if ($id) { $v[] = empty($in['actif']) ? 0 : 1; $v[] = $id; db()->prepare("UPDATE c_proprietaires SET nom=?, email=?, telephone=?, plafond=?, actif=? WHERE id=?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_proprietaires (nom, email, telephone, plafond, created_at) VALUES (?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    case 'proprietaire_lien':
      // Nouveau lien personnel : l'ancien cesse de fonctionner. Affiché une seule fois.
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $t = cz_jeton();
      db()->prepare("UPDATE c_proprietaires SET jeton_hash = ? WHERE id = ?")->execute([cz_hash($t), $id]); journal('lien propriétaire', 'propriétaire ' . $id);
      $base = preg_replace('#/[^/]*$#', '', cz_lien_voyageur(['lien_enc' => cz_enc('x')]));
      c_out(['lien' => $base . '/proprietaire.php#' . $t]);

    case 'reglages_save':
      c_admin('tout'); if (!est_titulaire()) c_fail('Réservé aux dirigeant(e)s.', 403);
      reglage_set('cz_conservation_jours', (string)max(30, min(3650, (int)($in['cz_conservation_jours'] ?? 365))));
      reglage_set('cz_copie_piece', empty($in['cz_copie_piece']) ? '0' : '1'); reglage_set('cz_code_auto', empty($in['cz_code_auto']) ? '0' : '1');
      reglage_set('cz_heure_menage', preg_match('/^\d{2}:\d{2}$/', (string)($in['cz_heure_menage'] ?? '')) ? $in['cz_heure_menage'] : '11:00');
      c_out(['ok' => true]);

    /* ================= Équipe terrain (appli) ================= */
    case 'mes_missions':
      $aid = c_agent(); $j = date('Y-m-d');
      $st = db()->prepare("SELECT m.id, m.type, m.date, m.heure, m.statut, m.note, l.nom logement, l.ville, l.adresse FROM c_missions m JOIN c_logements l ON l.id = m.logement_id WHERE m.animatrice_id = ? AND (m.date BETWEEN ? AND ? OR (m.date < ? AND m.statut <> 'faite')) ORDER BY m.date, m.heure");
      $st->execute([$aid, $j, date('Y-m-d', strtotime('+7 days')), $j]); c_out(['missions' => $st->fetchAll(), 'aujourdhui' => $j]);

    case 'ma_mission':
      $aid = c_agent(); $m = c_mission_agent((int)($_GET['id'] ?? 0), $aid); $etat = cz_etat($m); $P = [];
      foreach (cz_checklist($m) as $i => $p) $P[] = $p + ['ok' => !empty($etat[$i]['ok']), 'urls' => array_map(fn($f) => photo_url($f, true), (array)($etat[$i]['photos'] ?? []))];
      $jour = $m['date'] <= date('Y-m-d', strtotime('+1 day'));
      c_out(['mission' => ['id' => (int)$m['id'], 'logement' => $m['logement'], 'adresse' => $m['adresse'], 'ville' => $m['ville'], 'type' => $m['type'], 'date' => $m['date'], 'heure' => $m['heure'], 'statut' => $m['statut'],
        'note' => $m['note'], 'kit_linge' => $m['kit_linge'], 'acces' => $jour ? cz_dec($m['acces_enc']) : '', 'pieces' => $P, 'manques' => cz_mission_manques($m)]]);

    case 'mission_debut':
      $aid = c_agent(); $m = c_mission_agent((int)($in['id'] ?? 0), $aid);
      if ($m['statut'] !== 'faite') db()->prepare("UPDATE c_missions SET statut = 'en_cours', debut_at = COALESCE(debut_at, ?) WHERE id = ?")->execute([now(), $m['id']]);
      c_out(['ok' => true]);

    case 'mission_photo':
      $aid = c_agent(); $m = c_mission_agent((int)($in['id'] ?? 0), $aid); if ($m['statut'] === 'faite') c_fail('Mission déjà terminée.');
      $i = (int)($in['piece'] ?? -1); $chk = cz_checklist($m); if (!isset($chk[$i])) c_fail('Pièce inconnue.');
      $f = $_FILES['photo'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) c_fail('Photo non reçue, réessayez.');
      $etat = cz_etat($m); if (count((array)($etat[$i]['photos'] ?? [])) >= 12) c_fail('12 photos au plus par pièce.');
      $p = save_image($f['tmp_name']); $etat[$i]['photos'][] = $p['fichier'];
      db()->prepare("UPDATE c_missions SET etat = ?, statut = IF(statut = 'a_faire', 'en_cours', statut), debut_at = COALESCE(debut_at, ?) WHERE id = ?")->execute([json_encode($etat), now(), $m['id']]);
      c_out(['ok' => true, 'url' => photo_url($p['fichier'], true)]);

    case 'mission_piece':
      $aid = c_agent(); $m = c_mission_agent((int)($in['id'] ?? 0), $aid); if ($m['statut'] === 'faite') c_fail('Mission déjà terminée.');
      $i = (int)($in['piece'] ?? -1); $chk = cz_checklist($m); if (!isset($chk[$i])) c_fail('Pièce inconnue.');
      $etat = cz_etat($m); $ok = !empty($in['ok']);
      if ($ok && count((array)($etat[$i]['photos'] ?? [])) < $chk[$i]['photos']) c_fail('Prenez d\'abord ' . $chk[$i]['photos'] . ' photo(s) de la pièce « ' . $chk[$i]['piece'] . ' ».');
      $etat[$i]['ok'] = $ok; db()->prepare("UPDATE c_missions SET etat = ? WHERE id = ?")->execute([json_encode($etat), $m['id']]);
      c_out(['ok' => true]);

    case 'mission_terminer':
      $aid = c_agent(); $m = c_mission_agent((int)($in['id'] ?? 0), $aid); $man = cz_mission_manques($m);
      if ($man) c_out(['ok' => false, 'manques' => $man]);
      db()->prepare("UPDATE c_missions SET statut = 'faite', fin_at = ? WHERE id = ?")->execute([now(), $m['id']]);
      // Arrivée suivante dans ce logement : si tout est au vert, le code part tout de suite.
      if (reglage('cz_code_auto', '1') === '1') {
        $st = db()->prepare("SELECT id FROM c_reservations WHERE logement_id = ? AND statut = 'confirmee' AND code_envoye_at IS NULL AND arrivee BETWEEN ? AND ? ORDER BY arrivee LIMIT 1");
        $st->execute([$m['logement_id'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))]); if ($rid = (int)$st->fetchColumn()) { try { cz_envoyer_code($rid); } catch (Throwable $e) { error_log('ORVIA code : ' . $e->getMessage()); } }
      }
      c_out(['ok' => true]);

    case 'incident_signaler':
      $aid = c_agent(); $m = c_mission_agent((int)($in['mission'] ?? 0), $aid);
      $d = clip($in['description'] ?? '', 1000); if ($d === '') c_fail('Décrivez le problème en quelques mots.');
      $photo = null; $f = $_FILES['photo'] ?? null; if ($f && $f['error'] === UPLOAD_ERR_OK) $photo = save_image($f['tmp_name'])['fichier'];
      $st = db()->prepare("SELECT CONCAT(prenom, ' ', nom) FROM animatrices WHERE id = ?"); $st->execute([$aid]);
      db()->prepare("INSERT INTO c_incidents (logement_id, mission_id, auteur, description, photo, bloquant, created_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([$m['logement_id'], $m['id'], (string)$st->fetchColumn(), $d, $photo, empty($in['bloquant']) ? 0 : 1, now()]);
      c_out(['ok' => true]);

    /* ================= Voyageur (lien sécurisé) ================= */
    case 'voy_info':
      limite('voy', 60, 600); $r = cz_reservation_par_jeton((string)($_GET['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable. Contactez votre hôte.', 404);
      c_out(['logement' => $r['logement'], 'ville' => $r['ville'], 'arrivee' => $r['arrivee'], 'depart' => $r['depart'], 'attendues' => max(1, (int)$r['nb_voyageurs']), 'recues' => cz_nb_fiches((int)$r['id']),
        'piece' => cz_copie_piece_demandee(), 'pays' => reglage('cz_pays', 'MA'), 'agence' => AG_NOM, 'types' => CZ_PIECE_TYPES]);

    case 'voy_fiche':
      limite('voy_fiche', 20, 3600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      if ($r['depart'] < date('Y-m-d')) c_fail('Le séjour est terminé.');
      if (cz_nb_fiches((int)$r['id']) >= max(1, (int)$r['nb_voyageurs']) + 4) c_fail('Toutes les fiches de ce séjour sont déjà reçues.');
      try { $fiche = cz_fiche_propre($in); } catch (InvalidArgumentException $e) { c_fail($e->getMessage()); }
      $piece = null; $f = $_FILES['piece'] ?? null;
      if ($f && $f['error'] === UPLOAD_ERR_OK) $piece = cz_piece_enregistrer($f['tmp_name']);
      elseif (cz_copie_piece_demandee()) c_fail('champ:piece');
      db()->prepare("INSERT INTO c_voyageurs (reservation_id, data_enc, piece, created_at) VALUES (?,?,?,?)")->execute([$r['id'], cz_enc(json_encode($fiche, JSON_UNESCAPED_UNICODE)), $piece, now()]);
      $n = cz_nb_fiches((int)$r['id']); if ($n > (int)$r['nb_voyageurs']) db()->prepare("UPDATE c_reservations SET nb_voyageurs = ? WHERE id = ?")->execute([$n, $r['id']]);
      c_out(['ok' => true, 'recues' => $n, 'attendues' => max($n, (int)$r['nb_voyageurs'])]);

    case 'voy_nb':
      limite('voy', 60, 600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $nb = max(max(1, cz_nb_fiches((int)$r['id'])), min(20, (int)($in['nb'] ?? 1)));
      db()->prepare("UPDATE c_reservations SET nb_voyageurs = ?, updated_at = ? WHERE id = ?")->execute([$nb, now(), $r['id']]); c_out(['ok' => true, 'attendues' => $nb]);

    /* ================= Propriétaire (lien personnel) ================= */
    case 'prop_vue':
      limite('prop', 60, 600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable. Demandez-en un nouveau à votre conciergerie.', 404);
      $st = db()->prepare("SELECT id, nom, ville FROM c_logements WHERE proprietaire_id = ? AND actif = 1 ORDER BY nom"); $st->execute([$p['id']]); $L = $st->fetchAll(); $ids = array_column($L, 'id') ?: [0];
      $in_ = implode(',', array_map('intval', $ids));
      $R = db()->query("SELECT r.logement_id, r.arrivee, r.depart, r.source, r.nb_voyageurs nb FROM c_reservations r WHERE r.logement_id IN ($in_) AND r.statut = 'confirmee' AND r.depart >= CURDATE() - INTERVAL 60 DAY ORDER BY r.arrivee")->fetchAll();
      $M = array_map(function ($m) { $ph = []; foreach (json_decode((string)$m['etat'], true) ?: [] as $e) foreach ((array)($e['photos'] ?? []) as $f) $ph[] = photo_lien($f, 7, true); unset($m['etat']); return $m + ['photos' => array_slice($ph, 0, 24)]; },
        db()->query("SELECT m.logement_id, m.type, m.date, m.fin_at, m.etat FROM c_missions m WHERE m.logement_id IN ($in_) AND m.statut = 'faite' AND m.date >= CURDATE() - INTERVAL 60 DAY ORDER BY m.date DESC")->fetchAll());
      $I = array_map(fn($i) => $i + ['photo_url' => $i['photo'] ? photo_lien($i['photo'], 7, true) : ''],
        db()->query("SELECT i.id, i.logement_id, i.description, i.photo, i.cout, i.statut, i.proprio_statut, i.created_at FROM c_incidents i WHERE i.logement_id IN ($in_) AND (i.statut = 'ouvert' OR i.created_at >= CURDATE() - INTERVAL 90 DAY) ORDER BY i.created_at DESC")->fetchAll());
      c_out(['proprietaire' => $p['nom'], 'agence' => AG_NOM, 'logements' => $L, 'reservations' => $R, 'missions' => $M, 'incidents' => $I]);

    case 'prop_valider':
      limite('prop', 60, 600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $st = db()->prepare("SELECT i.id FROM c_incidents i JOIN c_logements l ON l.id = i.logement_id WHERE i.id = ? AND l.proprietaire_id = ? AND i.proprio_statut = 'demande'"); $st->execute([(int)($in['id'] ?? 0), $p['id']]);
      $id = (int)$st->fetchColumn() ?: c_fail('Rien à valider.', 404);
      db()->prepare("UPDATE c_incidents SET proprio_statut = ?, proprio_le = ? WHERE id = ?")->execute([($in['decision'] ?? '') === 'ok' ? 'accepte' : 'refuse', now(), $id]);
      journal('validation propriétaire', 'incident ' . $id, $p['nom'], 'propriétaire'); c_out(['ok' => true]);

    default: c_fail('Action inconnue', 404);
  }
} catch (Throwable $e) {
  c_fail(erreur_publique($e, 'conciergerie ' . $action), 500);
}
