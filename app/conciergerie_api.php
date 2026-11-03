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
/** Mission de l'agent : par son lien personnel (ouvert depuis WhatsApp, sans compte), sinon par sa session dans l'appli. */
function c_mission_acces(int $mid): array {
  global $in; $t = (string)($in['t'] ?? ($_GET['t'] ?? ''));
  if ($t !== '') { limite('agent_lien', 120, 600); $m = cz_mission_par_jeton($t) ?: c_fail('Ce lien de mission n\'est plus valable. Demandez-en un nouveau.', 404); return $m; }
  return c_mission_agent($mid, c_agent());
}

/** Messages WhatsApp prêts à envoyer au voyageur (liens wa.me) : fiche, code (si feu vert), nouvelles à l'arrivée. */
function c_wa_voyageur(array $r): array {
  $tel = cz_wa_reservation($r); if ($tel === '') return ['tel' => false];
  $lien = cz_lien_voyageur($r); $fv = cz_feu_vert($r); $l = cz_logement((int)$r['logement_id']);
  $o = ['tel' => true, 'fiche' => cz_wa_lien($tel, "Bonjour, nous préparons votre arrivée à " . $r['logement'] . ". Merci de remplir la fiche de chaque voyageur sur ce lien sécurisé (ne nous envoyez pas vos pièces d'identité par WhatsApp) : " . $lien),
    'nouvelles' => cz_wa_lien($tel, "Bienvenue à " . $r['logement'] . ". Tout est-il en ordre ? Vous pouvez nous donner votre avis ou signaler un problème ici : " . $lien)];
  $carte = cz_liens_carte($l['lat'], $l['lng']);
  if ($fv['ok'] && can('tout')) $o['code'] = cz_wa_lien($tel, "Votre logement " . $r['logement'] . " est prêt. Code d'accès : " . cz_dec($l['code_enc']) . ($l['acces_enc'] ? "\n" . cz_dec($l['acces_enc']) : '') . ($carte ? "\nItinéraire : " . $carte['maps'] : '') . "\nPhotos de l'entrée et Wi-Fi : " . $lien);
  return $o;
}

function c_messages(int $rid, int $n = 30): array { $st = db()->prepare("SELECT id, auteur, texte, a_traiter, created_at FROM c_messages WHERE reservation_id = ? ORDER BY id DESC LIMIT $n"); $st->execute([$rid]); return array_reverse($st->fetchAll()); }
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
        'sources' => CZ_SOURCES, 'types' => CZ_MISSION_TYPES, 'modes' => CZ_ARRIVEE_MODES,
        'admins' => db()->query("SELECT id, nom FROM admins WHERE actif = 1 ORDER BY nom")->fetchAll(), 'statuts' => CZ_MISSION_STATUTS, 'titulaire' => est_titulaire(), 'gerer' => can('tout'),
        'reglages' => ['cz_conservation_jours' => cz_conservation_jours(), 'cz_copie_piece' => cz_copie_piece_demandee() ? '1' : '0', 'cz_code_auto' => reglage('cz_code_auto', '1'), 'cz_heure_menage' => reglage('cz_heure_menage', '11:00'), 'cz_ia' => reglage('cz_ia', '1'), 'ia_dispo' => defined('ORVIA_IA_CLE') && ORVIA_IA_CLE !== '']]);

    case 'intendance':
      c_admin(); c_out(['linge' => cz_linge_etat(), 'entretiens' => cz_entretiens_liste(), 'modeles' => CZ_ENTRETIENS_MODELES]);

    case 'linge_article_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $nom = clip($in['nom'] ?? '', 80); if ($nom === '') c_fail('Indiquez le nom de l\'article.');
      $v = [$nom, max(0, (int)($in['stock'] ?? 0)), max(0, (float)(montant($in['prix_lavage'] ?? 0) ?? 0))];
      if ($id) { $v[] = empty($in['supprimer']) ? 1 : 0; $v[] = $id; db()->prepare("UPDATE c_linge_articles SET nom = ?, stock = ?, prix_lavage = ?, actif = ? WHERE id = ?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_linge_articles (nom, stock, prix_lavage, created_at) VALUES (?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    case 'linge_kit_save':
      c_admin('tout'); $lid = (int)($in['logement_id'] ?? 0); $st = db()->prepare("SELECT id FROM c_logements WHERE id = ?"); $st->execute([$lid]); $st->fetchColumn() ?: c_fail('Logement introuvable.', 404);
      $up = db()->prepare("REPLACE INTO c_linge_kits (logement_id, article_id, qte) VALUES (?,?,?)");
      foreach ((array)($in['kit'] ?? []) as $a => $q) $up->execute([$lid, (int)$a, max(0, min(99, (int)$q))]);
      c_out(['ok' => true]);

    case 'linge_envoyer':
      c_admin('tout'); try { $id = cz_linge_envoyer((string)($in['blanchisserie'] ?? ''), (string)($in['note'] ?? '')); } catch (RuntimeException $e) { c_fail($e->getMessage()); } c_out(['ok' => true, 'id' => $id]);

    case 'linge_retour':
      c_admin('tout'); $c = trim((string)($in['cout'] ?? '')) === '' ? null : (float)(montant($in['cout']) ?? 0);
      try { $r = cz_linge_retour((int)($in['id'] ?? 0), (array)($in['recus'] ?? []), $c); } catch (RuntimeException $e) { c_fail($e->getMessage()); } c_out(['ok' => true] + $r);

    case 'entretien_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $lid = (int)($in['logement_id'] ?? 0); $titre = clip($in['titre'] ?? '', 160);
      $st = db()->prepare("SELECT id FROM c_logements WHERE id = ?"); $st->execute([$lid]); $st->fetchColumn() ?: c_fail('Choisissez le logement.');
      if ($titre === '') c_fail('Indiquez ce qu\'il faut faire.');
      $fr = max(1, min(730, (int)($in['frequence_jours'] ?? 90))); $pro = c_date($in['prochaine'] ?? ''); $h = preg_match('/^\d{2}:\d{2}$/', (string)($in['heure'] ?? '')) ? $in['heure'] : '10:00';
      $ag = (int)($in['animatrice_id'] ?? 0) ?: null; $v = [$lid, $titre, $fr, $pro, $h, $ag, clip($in['prestataire'] ?? '', 160), clip($in['consignes'] ?? '', 2000)];
      if ($id) { $v[] = empty($in['supprimer']) ? 1 : 0; $v[] = $id; db()->prepare("UPDATE c_entretiens SET logement_id = ?, titre = ?, frequence_jours = ?, prochaine = ?, heure = ?, animatrice_id = ?, prestataire = ?, consignes = ?, actif = ? WHERE id = ?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_entretiens (logement_id, titre, frequence_jours, prochaine, heure, animatrice_id, prestataire, consignes, created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      $n = cz_entretiens_generer(); c_out(['ok' => true, 'id' => $id, 'missions_creees' => $n]);

    case 'paie':
      c_admin('tout'); try { c_out(cz_paie_mois((string)($_GET['mois'] ?? date('Y-m')))); } catch (RuntimeException $e) { c_fail($e->getMessage()); }

    case 'paie_tarifs':
      c_admin('tout'); cz_paie_tarifs_save($in); c_out(['ok' => true, 'tarifs' => cz_paie_tarifs()]);

    case 'paie_ajust':
      c_admin('tout'); $aid = (int)($in['animatrice_id'] ?? 0); $mois = (string)($in['mois'] ?? ''); if (!preg_match('/^\d{4}-\d{2}$/', $mois)) c_fail('Mois invalide.');
      $st = db()->prepare("SELECT COUNT(*) FROM c_paie_reglements WHERE animatrice_id = ? AND mois = ?"); $st->execute([$aid, $mois]); if ((int)$st->fetchColumn()) c_fail('Ce mois est déjà réglé : annulez d\'abord le règlement.');
      if (!empty($in['supprimer'])) { db()->prepare("DELETE FROM c_paie_ajust WHERE id = ? AND animatrice_id = ? AND mois = ?")->execute([(int)$in['id'], $aid, $mois]); c_out(['ok' => true]); }
      $lib = clip($in['libelle'] ?? '', 160); $mt = round((float)str_replace([',', ' '], ['.', ''], (string)($in['montant'] ?? 0)), 2);
      if ($lib === '' || $mt == 0) c_fail('Indiquez un libellé et un montant (négatif pour une avance ou une retenue).');
      db()->prepare("INSERT INTO c_paie_ajust (animatrice_id, mois, libelle, montant, par, created_at) VALUES (?,?,?,?,?,?)")->execute([$aid, $mois, $lib, $mt, admin_nom(), now()]); c_out(['ok' => true]);

    case 'paie_regler':
      c_admin('tout'); $aid = (int)($in['animatrice_id'] ?? 0); $mois = (string)($in['mois'] ?? '');
      try { $P = cz_paie_mois($mois); } catch (RuntimeException $e) { c_fail($e->getMessage()); }
      $p = array_values(array_filter($P['personnes'], fn($x) => $x['id'] === $aid))[0] ?? c_fail('Rien à régler pour cette personne ce mois-ci.', 404);
      if (!empty($in['annuler'])) { db()->prepare("DELETE FROM c_paie_reglements WHERE animatrice_id = ? AND mois = ?")->execute([$aid, $mois]); journal('paiement annulé', $p['nom'] . ' ' . $mois); c_out(['ok' => true]); }
      if ($p['total'] <= 0) c_fail('Rien à verser ce mois-ci.');
      if ($p['regle']) c_fail('Déjà réglé le ' . date('d/m/Y', strtotime($p['regle']['le'])) . '.');
      $moyen = in_array($in['moyen'] ?? '', ['especes', 'virement', 'autre'], true) ? $in['moyen'] : 'especes';
      db()->prepare("INSERT INTO c_paie_reglements (animatrice_id, mois, montant, moyen, detail, regle_at, par) VALUES (?,?,?,?,?,?,?)")->execute([$aid, $mois, $p['total'], $moyen, json_encode($p, JSON_UNESCAPED_UNICODE), now(), admin_nom()]);
      journal('paiement équipe', $p['nom'] . ' ' . $mois . ' ' . $p['total'] . ' DH'); c_out(['ok' => true, 'montant' => $p['total']]);

    case 'taxe_sejour':
      c_admin(); c_out(cz_taxe_sejour(c_date($_GET['from'] ?? ''), c_date($_GET['to'] ?? '')) + ['agence' => AG_NOM]);

    case 'taxe_reglages':
      c_admin('tout'); cz_ts_save($in); c_out(['ok' => true, 'reglages' => cz_ts_reglages()]);

    case 'equipe':
      // L'équipe terrain : fiche simple, activité des 30 derniers jours, propreté notée par les voyageurs, invitation par WhatsApp.
      c_admin(); $j = date('Y-m-d'); $d30 = date('Y-m-d', strtotime('-30 days')); $d7 = date('Y-m-d', strtotime('+7 days'));
      $L = db()->query("SELECT id, prenom, nom, telephone, email, zone ville, actif, statut, created_at FROM animatrices ORDER BY actif DESC, prenom, nom")->fetchAll();
      $st = db()->prepare("SELECT animatrice_id a, SUM(statut = 'faite' AND date BETWEEN ? AND ?) faites, SUM(statut <> 'faite' AND date < ?) retards, SUM(statut <> 'faite' AND date BETWEEN ? AND ?) a_venir FROM c_missions WHERE animatrice_id IS NOT NULL GROUP BY animatrice_id");
      $st->execute([$d30, $j, $j, $j, $d7]); $M = []; foreach ($st->fetchAll() as $r) $M[(int)$r['a']] = $r;
      $st = db()->prepare("SELECT animatrice_id a, AVG(proprete) p, COUNT(proprete) n FROM c_avis WHERE proprete IS NOT NULL AND animatrice_id IS NOT NULL AND created_at >= ? GROUP BY animatrice_id"); $st->execute([date('Y-m-d', strtotime('-90 days'))]);
      $A = []; foreach ($st->fetchAll() as $r) $A[(int)$r['a']] = $r;
      $code = strtoupper(reglage('code_agence', 'AMAYA')); $url = cz_base_url() . '/';
      c_out(['code' => $code, 'lien' => $url, 'equipe' => array_map(function ($a) use ($M, $A, $code, $url) { $i = (int)$a['id']; $m = $M[$i] ?? []; $v = $A[$i] ?? null;
        return ['id' => $i, 'prenom' => $a['prenom'], 'nom' => $a['nom'], 'telephone' => $a['telephone'], 'email' => $a['email'], 'ville' => $a['ville'] === 'Maroc' ? '' : $a['ville'], 'actif' => (bool)$a['actif'],
          'inscrit' => $a['created_at'] !== null, 'faites' => (int)($m['faites'] ?? 0), 'retards' => (int)($m['retards'] ?? 0), 'a_venir' => (int)($m['a_venir'] ?? 0),
          'proprete' => $v ? round((float)$v['p'], 1) : null, 'avis' => $v ? (int)$v['n'] : 0,
          'wa' => cz_wa_lien((string)$a['telephone'], "Bonjour " . $a['prenom'] . ",\nVoici l'appli de " . AG_NOM . " pour vos missions : $url\nCode : $code\nÀ la première connexion, indiquez votre prénom, votre nom, ce numéro et votre e-mail.")]; }, $L)]);

    case 'equipe_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); // numéros enregistrés en chiffres, au format international
      $prenom = clip($in['prenom'] ?? '', 80); $nom = clip($in['nom'] ?? '', 80); $tel = tel_norm((string)($in['telephone'] ?? '')); if (str_starts_with($tel, '0') && strlen($tel) === 10) $tel = '212' . substr($tel, 1);$email = mb_strtolower(trim((string)($in['email'] ?? ''))); $ville = clip($in['ville'] ?? '', 20);
      if ($prenom === '' || $nom === '') c_fail('Indiquez le prénom et le nom.');
      if (strlen($tel) < 9) c_fail('Indiquez le numéro de portable complet (WhatsApp).');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) c_fail('Adresse e-mail invalide.');
      $st = db()->prepare("SELECT id FROM animatrices WHERE telephone <> '' AND RIGHT(telephone, 9) = RIGHT(?, 9) AND id <> ? LIMIT 1"); $st->execute([$tel, $id]);
      if ($st->fetchColumn()) c_fail('Ce numéro est déjà celui d\'un membre de l\'équipe.');
      $actif = !isset($in['actif']) || !empty($in['actif']);
      if ($id) db()->prepare("UPDATE animatrices SET prenom = ?, nom = ?, telephone = ?, email = ?, zone = ?, actif = ? WHERE id = ?")->execute([$prenom, $nom, $tel, $email, $ville ?: array_key_first(ZONES), $actif ? 1 : 0, $id]);
      else { db()->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, actif, statut) VALUES (?,?,?,?,?,1,'actif')")->execute([$prenom, $nom, $tel, $email, $ville ?: array_key_first(ZONES)]); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    case 'aujourdhui':
      c_admin(); cz_entretiens_generer(); $j = date('Y-m-d'); $d = date('Y-m-d', strtotime('+1 day'));
      $st = db()->prepare("SELECT r.*, l.nom logement, l.ville FROM c_reservations r JOIN c_logements l ON l.id = r.logement_id WHERE r.statut = 'confirmee' AND r.arrivee BETWEEN ? AND ? ORDER BY r.arrivee, l.nom"); $st->execute([$j, $d]);
      $arr = array_map(fn($r) => ['id' => (int)$r['id'], 'logement' => $r['logement'], 'ville' => $r['ville'], 'arrivee' => $r['arrivee'], 'depart' => $r['depart'], 'source' => $r['source'], 'libelle' => $r['libelle'],
        'code_envoye' => $r['code_envoye_at'], 'fv' => cz_feu_vert($r), 'lien' => cz_lien_voyageur($r), 'heure' => cz_heure_arrivee($r), 'heure_connue' => $r['arrivee_heure'] !== '', 'vol' => $r['vol'], 'mode' => $r['arrivee_mode'],
        'wa' => c_wa_voyageur($r)], $st->fetchAll());
      $st = db()->prepare("SELECT m.id, m.type, m.date, m.heure, m.statut, l.nom logement, l.ville, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date = ? OR (m.date < ? AND m.statut <> 'faite') ORDER BY m.date, m.heure");
      $st->execute([$j, $j]);
      $inc = db()->query("SELECT i.id, i.description, i.bloquant, i.created_at, l.nom logement FROM c_incidents i JOIN c_logements l ON l.id = i.logement_id WHERE i.statut = 'ouvert' ORDER BY i.bloquant DESC, i.created_at DESC LIMIT 30")->fetchAll();
      $Q = db()->query("SELECT m.reservation_id, m.texte, m.created_at, l.nom logement FROM c_messages m JOIN c_reservations r ON r.id = m.reservation_id JOIN c_logements l ON l.id = r.logement_id WHERE m.a_traiter = 1 ORDER BY m.id DESC LIMIT 30")->fetchAll();
      $PM = db()->query("SELECT m.proprietaire_id, p.nom, m.texte, m.created_at FROM c_prop_messages m JOIN c_proprietaires p ON p.id = m.proprietaire_id WHERE m.auteur = 'proprietaire' AND m.lu_at IS NULL ORDER BY m.id DESC LIMIT 20")->fetchAll();
      c_out(['arrivees' => $arr, 'missions' => $st->fetchAll(), 'incidents' => $inc, 'questions' => $Q, 'prop_messages' => $PM, 'demarrage' => cz_demarrage()]);

    case 'demarrage_masquer':
      c_admin('tout'); reglage_set('cz_demarrage_masque', empty($in['afficher']) ? '1' : '0'); c_out(['ok' => true]);

    case 'logement_get':
      c_admin(); $l = cz_logement((int)($_GET['id'] ?? 0)) ?: c_fail('Logement introuvable.', 404);
      c_out(['logement' => cz_logement_public($l, can('tout'))]);

    case 'logement_photo':
      // Photo d'une étape du livret d'arrivée (rue, entrée, porte, boîte à clés).
      c_admin('tout'); $f = $_FILES['photo'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) c_fail('Photo non reçue, réessayez.');
      try { $p = save_image($f['tmp_name']); } catch (RuntimeException $e) { c_fail($e->getMessage()); }
      c_out(['photo' => $p['fichier'], 'url' => photo_url($p['fichier'], true)]);

    case 'logement_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0);
      $nom = clip($in['nom'] ?? '', 160); if ($nom === '') c_fail('Donnez un nom au logement.');
      $ical = implode("\n", array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string)($in['ical'] ?? ''))))));
      foreach (array_filter(explode("\n", $ical)) as $u) if (!preg_match('#^https://\S+$#i', $u)) c_fail('Chaque lien de calendrier doit commencer par https://');
      $pos = null; $ps = trim((string)($in['position'] ?? ''));
      if (is_numeric($in['lat'] ?? null) && is_numeric($in['lng'] ?? null)) $pos = cz_parse_position($in['lat'] . ',' . $in['lng']);
      elseif ($ps !== '' && !($pos = cz_parse_position($ps)) && !($pos = (function () use ($ps, $in) { require_once __DIR__ . '/geo_lib.php'; $g = geo_position_texte($ps, 'Maroc', (string)($in['ville'] ?? '')); return $g ? [round((float)$g['lat'], 6), round((float)$g['lng'], 6)] : null; })())) c_fail(preg_match('#goo\.gl|maps\.app#i', $ps) ? 'Lien Google Maps raccourci illisible : ouvrez-le dans le navigateur, puis copiez l\'adresse complète de la page (elle contient les coordonnées).' : 'Position non reconnue : collez un lien Google Maps, un Plus Code (« 8C9G+QX Marrakech ») ou des coordonnées (31.6295, -7.9811).');
      $chk = []; foreach ((array)($in['checklist'] ?? []) as $p) { $p = ['piece' => clip($p['piece'] ?? '', 60), 'consignes' => clip($p['consignes'] ?? '', 400), 'photos' => max(0, min(10, (int)($p['photos'] ?? 1)))]; if ($p['piece'] !== '') $chk[] = $p; }
      $v = [$nom, clip($in['ville'] ?? '', 80), clip($in['adresse'] ?? '', 300), ((int)($in['proprietaire_id'] ?? 0)) ?: null, ((int)($in['agent_id'] ?? 0)) ?: null,
        cz_enc(clip($in['acces'] ?? '', 1500)), cz_enc(clip($in['code'] ?? '', 40)), json_encode($chk ?: CZ_PIECES_DEFAUT, JSON_UNESCAPED_UNICODE), clip($in['kit_linge'] ?? '', 120),
        max(15, min(600, (int)($in['duree_menage'] ?? 120))), $ical, clip($in['notes'] ?? '', 2000), empty($in['actif']) && $id ? 0 : 1];
      if ($id) { $v[] = $id; db()->prepare("UPDATE c_logements SET nom=?, ville=?, adresse=?, proprietaire_id=?, agent_id=?, acces_enc=?, code_enc=?, checklist=?, kit_linge=?, duree_menage=?, ical=?, notes=?, actif=? WHERE id=?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_logements (nom, ville, adresse, proprietaire_id, agent_id, acces_enc, code_enc, checklist, kit_linge, duree_menage, ical, notes, actif, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      if ($pos) db()->prepare("UPDATE c_logements SET lat = ?, lng = ?, pos_fixe = 1 WHERE id = ?")->execute([$pos[0], $pos[1], $id]);
      if (isset($in['livret'])) db()->prepare("UPDATE c_logements SET livret_enc = ? WHERE id = ?")->execute([cz_enc(json_encode(cz_livret_nettoyer((array)$in['livret']), JSON_UNESCAPED_UNICODE)), $id]);
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
        'libelle' => $r['libelle'], 'statut' => $r['statut'], 'code_envoye' => $r['code_envoye_at'], 'lien' => cz_lien_voyageur($r), 'fv' => cz_feu_vert($r),
        'heure' => $r['arrivee_heure'], 'vol' => $r['vol'], 'mode' => $r['arrivee_mode'], 'wa' => c_wa_voyageur($r)], 'fiches' => $F, 'titulaire' => $tit,
        'commandes' => (function () use ($r) { $st = db()->prepare("SELECT c.quantite, c.prix, c.cout, c.statut, s.nom FROM c_commandes c JOIN c_services s ON s.id = c.service_id WHERE c.reservation_id = ?"); $st->execute([$r['id']]); return $st->fetchAll(); })(),
        'messages' => c_messages((int)$r['id']), 'assistant' => cz_ia_active(),
        'avis' => (function () use ($r) { $st = db()->prepare("SELECT moment, proprete, logement, accueil, commentaire, created_at FROM c_avis WHERE reservation_id = ?"); $st->execute([$r['id']]); return $st->fetchAll(); })()]);

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
      c_admin(); cz_entretiens_generer(); $from = c_date($_GET['from'] ?? date('Y-m-d')); $to = c_date($_GET['to'] ?? date('Y-m-d', strtotime('+7 days')));
      $st = db()->prepare("SELECT m.id, m.logement_id, m.reservation_id, m.type, m.date, m.heure, m.statut, m.animatrice_id, m.note, m.fin_at, l.nom logement, l.ville, CONCAT(a.prenom, ' ', a.nom) agent FROM c_missions m JOIN c_logements l ON l.id = m.logement_id LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date BETWEEN ? AND ? ORDER BY m.date, m.heure, l.nom");
      $st->execute([$from, $to]); c_out(['missions' => $st->fetchAll()]);

    case 'mission_get':
      c_admin(); $m = cz_mission((int)($_GET['id'] ?? 0)) ?: c_fail('Mission introuvable.', 404); $etat = cz_etat($m); $P = [];
      foreach (cz_checklist($m) as $i => $p) $P[] = $p + ['ok' => !empty($etat[$i]['ok']), 'urls' => array_map(fn($f) => photo_url($f, true), (array)($etat[$i]['photos'] ?? [])), 'grandes' => array_map(fn($f) => photo_url($f), (array)($etat[$i]['photos'] ?? []))];
      c_out(['mission' => ['id' => (int)$m['id'], 'logement' => $m['logement'], 'logement_id' => (int)$m['logement_id'], 'type' => $m['type'], 'date' => $m['date'], 'heure' => $m['heure'], 'statut' => $m['statut'], 'agent' => $m['agent'], 'animatrice_id' => $m['animatrice_id'] ? (int)$m['animatrice_id'] : null,
        'note' => $m['note'], 'debut' => $m['debut_at'], 'fin' => $m['fin_at'], 'pieces' => $P, 'manques' => cz_mission_manques($m),
        'debut_pos' => $m['debut_at'] ? cz_position_texte($m['debut_dist'] === null ? null : (int)$m['debut_dist']) : '', 'fin_pos' => $m['fin_at'] ? cz_position_texte($m['fin_dist'] === null ? null : (int)$m['fin_dist']) : '',
        'loin' => ($m['debut_dist'] !== null && (int)$m['debut_dist'] > 150) || ($m['fin_dist'] !== null && (int)$m['fin_dist'] > 150),
        'duree_reelle' => $m['debut_at'] && $m['fin_at'] ? (int)round((strtotime($m['fin_at']) - strtotime($m['debut_at'])) / 60) : null, 'duree_prevue' => cz_duree($m + ['duree_menage' => 120])]]);

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

    case 'prop_fiche':
      // Fiche d'un propriétaire : ses logements, le fil de messages (ses messages deviennent lus), les rapports envoyés.
      c_admin(); $id = (int)($_GET['id'] ?? 0);
      $st = db()->prepare("SELECT id, nom, email, telephone, plafond, actif, (jeton_hash IS NOT NULL) lien FROM c_proprietaires WHERE id = ?"); $st->execute([$id]); $p = $st->fetch() ?: c_fail('Propriétaire introuvable.', 404);
      $st = db()->prepare("SELECT id, nom, ville FROM c_logements WHERE proprietaire_id = ? AND actif = 1 ORDER BY nom"); $st->execute([$id]); $L = $st->fetchAll();
      $st = db()->prepare("SELECT id, logement_id, auteur, nom, texte, photos, lu_at, created_at FROM c_prop_messages WHERE proprietaire_id = ? ORDER BY id DESC LIMIT 100"); $st->execute([$id]);
      $M = array_reverse(array_map(fn($m) => array_merge($m, ['photos' => array_map(fn($f) => ['mini' => photo_url($f, true), 'url' => photo_url($f)], json_decode((string)$m['photos'], true) ?: [])]), $st->fetchAll()));
      db()->prepare("UPDATE c_prop_messages SET lu_at = ? WHERE proprietaire_id = ? AND auteur = 'proprietaire' AND lu_at IS NULL")->execute([now(), $id]);
      $st = db()->prepare("SELECT id, type, titre, periode_from, periode_to, par, lu_at, created_at FROM c_prop_rapports WHERE proprietaire_id = ? ORDER BY id DESC LIMIT 50"); $st->execute([$id]);
      $R = $st->fetchAll(); $st = db()->prepare("SELECT d.*, l.nom logement FROM c_prop_devis d LEFT JOIN c_logements l ON l.id = d.logement_id WHERE d.proprietaire_id = ? ORDER BY d.id DESC LIMIT 50"); $st->execute([$id]);
      $D = array_map(fn($d) => array_merge($d, ['photos' => array_map(fn($f) => ['mini' => photo_url($f, true), 'url' => photo_url($f)], json_decode((string)$d['photos'], true) ?: [])]), $st->fetchAll());
      c_out(['proprietaire' => $p, 'logements' => $L, 'messages' => $M, 'rapports' => $R, 'devis' => $D]);

    case 'prop_message':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $txt = trim(clip($in['texte'] ?? '', 4000));
      $st = db()->prepare("SELECT id FROM c_proprietaires WHERE id = ? AND actif = 1"); $st->execute([$id]); $st->fetchColumn() ?: c_fail('Propriétaire introuvable.', 404);
      $ph = cz_prop_photos_enregistrer(cz_fichiers('photos')); if ($txt === '' && !$ph) c_fail('Écrivez un message ou ajoutez une photo.');
      $lid = (int)($in['logement_id'] ?? 0) ?: null;
      db()->prepare("INSERT INTO c_prop_messages (proprietaire_id, logement_id, auteur, nom, texte, photos, created_at) VALUES (?,?,'conciergerie',?,?,?,?)")->execute([$id, $lid, admin_nom(), $txt, $ph ? json_encode($ph) : null, now()]);
      $n = cz_prop_notifier($id, 'Nouveau message de ' . AG_NOM, 'Vous avez un nouveau message de ' . AG_NOM . ($ph ? ', avec ' . count($ph) . ' photo' . (count($ph) > 1 ? 's' : '') : '') . '.');
      c_out(['ok' => true] + $n);

    case 'prop_rapport_apercu':
      c_admin(); c_out(cz_prop_rapport_contenu((int)($_GET['id'] ?? 0), c_date($_GET['from'] ?? ''), c_date($_GET['to'] ?? '')));

    case 'prop_rapport_envoyer':
      // Rapport figé à l'envoi : le propriétaire relit toujours ce qui lui a été envoyé, même si les données bougent ensuite.
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $note = trim(clip($in['note'] ?? '', 4000));
      $st = db()->prepare("SELECT id FROM c_proprietaires WHERE id = ? AND actif = 1"); $st->execute([$id]); $st->fetchColumn() ?: c_fail('Propriétaire introuvable.', 404);
      if (($in['type'] ?? '') === 'visite') {
        $m = cz_mission((int)($in['mission_id'] ?? 0)) ?: c_fail('Intervention introuvable.', 404);
        $st = db()->prepare("SELECT proprietaire_id FROM c_logements WHERE id = ?"); $st->execute([$m['logement_id']]); if ((int)$st->fetchColumn() !== $id) c_fail('Ce logement n\'appartient pas à ce propriétaire.');
        $pieces = []; $etat = cz_etat($m); foreach (cz_checklist($m) as $i => $pc) $pieces[] = ['piece' => $pc['piece'], 'ok' => !empty($etat[$i]['ok']), 'photos' => (array)($etat[$i]['photos'] ?? [])];
        $type = 'visite'; $titre = (CZ_MISSION_TYPES[$m['type']] ?? 'Intervention') . ' · ' . $m['logement'] . ' · ' . date('d/m/Y', strtotime($m['date'])); $from = $to = $m['date'];
        $contenu = ['logement' => $m['logement'], 'type' => $m['type'], 'date' => $m['date'], 'debut' => $m['debut_at'] ?? null, 'fin' => $m['fin_at'] ?? null, 'pieces' => $pieces]; $mid = (int)$m['id'];
      } else {
        $from = c_date($in['from'] ?? ''); $to = c_date($in['to'] ?? ''); if ($from > $to) c_fail('Période invalide.');
        $type = 'mensuel'; $mid = null; $contenu = cz_prop_rapport_contenu($id, $from, $to, empty($in['sans_photos']) ? 12 : 0);
        $titre = 'Rapport du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to));
      }
      db()->prepare("INSERT INTO c_prop_rapports (proprietaire_id, type, titre, periode_from, periode_to, mission_id, note, contenu, par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $type, $titre, $from, $to, $mid, $note, json_encode($contenu, JSON_UNESCAPED_UNICODE), admin_nom(), now()]);
      $rid = (int)db()->lastInsertId(); journal('rapport propriétaire', 'propriétaire ' . $id . ' rapport ' . $rid);
      c_out(['ok' => true, 'id' => $rid] + cz_prop_notifier($id, $titre, AG_NOM . ' vous a envoyé un rapport : ' . $titre . '.'));

    case 'prop_devis_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $titre = trim(clip($in['titre'] ?? '', 200)); $mt = (float)(montant($in['montant'] ?? 0) ?? 0);
      $st = db()->prepare("SELECT id FROM c_proprietaires WHERE id = ? AND actif = 1"); $st->execute([$id]); $st->fetchColumn() ?: c_fail('Propriétaire introuvable.', 404);
      if ($titre === '' || $mt <= 0) c_fail('Indiquez l\'objet des travaux et le montant.');
      $lid = (int)($in['logement_id'] ?? 0) ?: null; $ph = cz_prop_photos_enregistrer(cz_fichiers('photos'));
      db()->prepare("INSERT INTO c_prop_devis (proprietaire_id, logement_id, titre, description, montant, prestataire, photos, par, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$id, $lid, $titre, trim(clip($in['description'] ?? '', 4000)), $mt, clip($in['prestataire'] ?? '', 160), $ph ? json_encode($ph) : null, admin_nom(), now()]);
      $did = (int)db()->lastInsertId(); journal('devis propriétaire', 'devis ' . $did);
      c_out(['ok' => true, 'id' => $did] + cz_prop_notifier($id, 'Devis à valider : ' . $titre, 'Un devis attend votre accord avant travaux : ' . $titre . ', ' . number_format($mt, 2, ',', ' ') . ' DH.'));

    case 'prop_devis_regle':
      c_admin('tout'); $st = db()->prepare("SELECT * FROM c_prop_devis WHERE id = ? AND statut = 'accepte'"); $st->execute([(int)($in['id'] ?? 0)]); $d = $st->fetch() ?: c_fail('Devis introuvable ou pas encore accepté.', 404);
      db()->prepare("UPDATE c_prop_devis SET paiement = 'regle', regle_at = ? WHERE id = ?")->execute([now(), $d['id']]); journal('devis réglé', 'devis ' . $d['id']); c_out(['ok' => true]);

    case 'prop_devis_relancer':
      c_admin('tout'); $st = db()->prepare("SELECT * FROM c_prop_devis WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $d = $st->fetch() ?: c_fail('Devis introuvable.', 404);
      $txt = $d['statut'] === 'envoye' ? 'Pour rappel, un devis attend votre accord : ' . $d['titre'] . '.' : 'Pour rappel, le règlement des travaux « ' . $d['titre'] . ' » (' . number_format((float)$d['montant'], 2, ',', ' ') . ' DH) reste à effectuer.';
      c_out(['ok' => true] + cz_prop_notifier((int)$d['proprietaire_id'], 'Rappel : ' . $d['titre'], $txt));

    case 'proprietaire_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $nom = clip($in['nom'] ?? '', 160); if ($nom === '') c_fail('Le nom du propriétaire est obligatoire.');
      $em = clip($in['email'] ?? '', 160); if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) c_fail('Adresse e-mail invalide.');
      $v = [$nom, $em, clip($in['telephone'] ?? '', 40), (float)(montant($in['plafond'] ?? 0) ?? 0)];
      if ($id) { $v[] = empty($in['actif']) ? 0 : 1; $v[] = $id; db()->prepare("UPDATE c_proprietaires SET nom=?, email=?, telephone=?, plafond=?, actif=? WHERE id=?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_proprietaires (nom, email, telephone, plafond, created_at) VALUES (?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    case 'proprietaire_lien':
      // Nouveau lien personnel : l'ancien cesse de fonctionner. Affiché une seule fois.
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $url = cz_prop_lien($id, true); journal('lien propriétaire', 'propriétaire ' . $id);
      c_out(['lien' => $url]);

    case 'reglages_save':
      c_admin('tout'); if (!est_titulaire()) c_fail('Réservé aux dirigeant(e)s.', 403);
      reglage_set('cz_conservation_jours', (string)max(30, min(3650, (int)($in['cz_conservation_jours'] ?? 365))));
      reglage_set('cz_copie_piece', empty($in['cz_copie_piece']) ? '0' : '1'); reglage_set('cz_code_auto', empty($in['cz_code_auto']) ? '0' : '1'); if (isset($in['cz_ia'])) reglage_set('cz_ia', empty($in['cz_ia']) ? '0' : '1');
      reglage_set('cz_heure_menage', preg_match('/^\d{2}:\d{2}$/', (string)($in['cz_heure_menage'] ?? '')) ? $in['cz_heure_menage'] : '11:00');
      c_out(['ok' => true]);

    case 'wa_brouillon':
      // Brouillon de réponse au message WhatsApp reçu d'un voyageur : le gestionnaire relit, corrige, puis envoie.
      c_admin(); limite('ia_brouillon', 120, 3600); $r = cz_reservation((int)($in['id'] ?? 0)) ?: c_fail('Réservation introuvable.', 404);
      try { $b = cz_brouillon_whatsapp($r, (string)($in['message'] ?? '')); } catch (InvalidArgumentException $e) { c_fail($e->getMessage()); }
      if (!$b) c_fail(cz_ia_active() ? 'L\'assistant ne répond pas pour le moment, réessayez dans un instant.' : 'Assistant désactivé dans les réglages.');
      $tel = cz_wa_reservation($r); c_out($b + ['wa' => $tel !== '' ? cz_wa_lien($tel, $b['reponse']) : '']);

    case 'message_repondre':
      // Réponse du gestionnaire, affichée sur la page du voyageur ; la question est marquée traitée.
      c_admin(); $rid = (int)($in['id'] ?? 0); cz_reservation($rid) ?: c_fail('Réservation introuvable.', 404); $t = trim((string)($in['texte'] ?? ''));
      if ($t !== '') cz_message($rid, 'gestion', $t);
      db()->prepare("UPDATE c_messages SET a_traiter = 0, traite_at = ? WHERE reservation_id = ? AND a_traiter = 1")->execute([now(), $rid]); c_out(['ok' => true]);

    case 'rapport':
      c_admin(); $f = c_date($_GET['from'] ?? date('Y-m-01')); $t = c_date($_GET['to'] ?? date('Y-m-d')); if ($t < $f) [$f, $t] = [$t, $f];
      $par = in_array($_GET['par'] ?? '', ['logement', 'proprietaire', 'responsable'], true) ? $_GET['par'] : 'logement';
      c_out(cz_rapport($f, $t, $par));

    case 'planning':
      c_admin(); cz_entretiens_generer(); c_out(cz_planning(c_date($_GET['date'] ?? date('Y-m-d'))));

    case 'tache_save':
      // Mission ou tâche du gestionnaire (accueil, visite, transfert…), attribuée à un agent ou à un gestionnaire.
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $date = c_date($in['date'] ?? ''); $heure = preg_match('/^\d{2}:\d{2}$/', (string)($in['heure'] ?? '')) ? $in['heure'] : '';
      $qui = (string)($in['qui'] ?? ''); $aid = str_starts_with($qui, 'a') ? (int)substr($qui, 1) : null; $gid = str_starts_with($qui, 'g') ? (int)substr($qui, 1) : null;
      $duree = max(0, min(600, (int)($in['duree'] ?? 0)));
      if ($id) db()->prepare("UPDATE c_missions SET date = ?, heure = ?, animatrice_id = ?, admin_id = ?, duree = IF(? > 0, ?, duree) WHERE id = ?")->execute([$date, $heure, $aid ?: null, $gid ?: null, $duree, $duree, $id]);
      else { $lid = (int)($in['logement_id'] ?? 0); cz_logement($lid) ?: c_fail('Choisissez le logement.'); $t = isset(CZ_MISSION_TYPES[$in['type'] ?? '']) ? $in['type'] : 'autre';
        db()->prepare("INSERT INTO c_missions (logement_id, type, date, heure, animatrice_id, admin_id, duree, statut, etat, note, created_at) VALUES (?,?,?,?,?,?,?,'a_faire','[]',?,?)")->execute([$lid, $t, $date, $heure, $aid ?: null, $gid ?: null, $duree, clip($in['note'] ?? '', 500), now()]); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    case 'mission_lien':
      // Lien personnel de la mission, à envoyer à l'agent par WhatsApp (il l'ouvre sans compte).
      c_admin('tout'); $m = cz_mission((int)($in['id'] ?? 0)) ?: c_fail('Mission introuvable.', 404); $url = cz_mission_lien((int)$m['id']);
      $tel = ''; if ($m['animatrice_id']) { $st = db()->prepare("SELECT telephone FROM animatrices WHERE id = ?"); $st->execute([$m['animatrice_id']]); $tel = (string)$st->fetchColumn(); }
      $txt = (CZ_MISSION_TYPES[$m['type']] ?? 'Mission') . ' · ' . $m['logement'] . "\n" . date('d/m', strtotime($m['date'])) . ($m['heure'] ? ' à ' . $m['heure'] : '') . "\n" . trim($m['adresse'] . ' ' . $m['ville']) . (($c = cz_liens_carte($m['lat'], $m['lng'])) ? "\nItinéraire : " . $c['maps'] : '') . ($m['note'] ? "\n" . $m['note'] : '') . "\nVotre mission : " . $url;
      c_out(['lien' => $url, 'wa' => $tel !== '' ? cz_wa_lien($tel, $txt) : '', 'texte' => $txt]);

    case 'avis':
      c_admin(); $from = c_date($_GET['from'] ?? date('Y-m-d', strtotime('-90 days'))); $to = c_date($_GET['to'] ?? date('Y-m-d'));
      $st = db()->prepare("SELECT v.*, v.logement note_logement, l.nom logement, CONCAT(a.prenom, ' ', a.nom) agent FROM c_avis v JOIN c_logements l ON l.id = v.logement_id LEFT JOIN animatrices a ON a.id = v.animatrice_id WHERE DATE(v.created_at) BETWEEN ? AND ? ORDER BY v.created_at DESC"); $st->execute([$from, $to]); $L = $st->fetchAll();
      $moy = function (array $L, string $k, string $champ) { $g = []; foreach ($L as $v) if ($v[$champ] !== null && $v[$k]) $g[$v[$k]][] = (int)$v[$champ]; $o = []; foreach ($g as $n => $x) $o[] = ['nom' => $n, 'note' => round(array_sum($x) / count($x), 1), 'n' => count($x)]; usort($o, fn($a, $b) => $a['note'] <=> $b['note']); return $o; };
      c_out(['avis' => array_map(fn($v) => $v + ['photo_url' => $v['photo'] ? photo_url($v['photo'], true) : ''], $L), 'agents' => $moy($L, 'agent', 'proprete'), 'logements' => $moy($L, 'logement', 'proprete')]);

    case 'services':
      c_admin(); $f = c_date($_GET['from'] ?? date('Y-m-01')); $t = c_date($_GET['to'] ?? date('Y-m-d'));
      c_out(['services' => cz_services(true), 'marges' => cz_marges($f, $t), 'from' => $f, 'to' => $t]);

    case 'service_save':
      c_admin('tout'); $id = (int)($in['id'] ?? 0); $nom = clip($in['nom'] ?? '', 120); if ($nom === '') c_fail('Le nom du service est obligatoire.');
      $v = [$nom, clip($in['nom_en'] ?? '', 120), clip($in['nom_ar'] ?? '', 120), clip($in['description'] ?? '', 300), (float)(montant($in['prix'] ?? 0) ?? 0), (float)(montant($in['cout'] ?? 0) ?? 0), max(0, min(240, (int)($in['delai_h'] ?? 24))), empty($in['actif']) && $id ? 0 : 1, (int)($in['ordre'] ?? 0)];
      if ($id) { $v[] = $id; db()->prepare("UPDATE c_services SET nom=?, nom_en=?, nom_ar=?, description=?, prix=?, cout=?, delai_h=?, actif=?, ordre=? WHERE id=?")->execute($v); }
      else { $v[] = now(); db()->prepare("INSERT INTO c_services (nom, nom_en, nom_ar, description, prix, cout, delai_h, actif, ordre, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute($v); $id = (int)db()->lastInsertId(); }
      c_out(['ok' => true, 'id' => $id]);

    /* ================= Équipe terrain (appli) ================= */
    case 'mes_missions':
      $aid = c_agent(); $j = date('Y-m-d');
      $st = db()->prepare("SELECT m.id, m.type, m.date, m.heure, m.statut, m.note, m.etat, m.logement_id, l.nom logement, l.ville, l.adresse, l.checklist FROM c_missions m JOIN c_logements l ON l.id = m.logement_id WHERE m.animatrice_id = ? AND (m.date BETWEEN ? AND ? OR (m.date < ? AND m.statut <> 'faite')) ORDER BY m.date, m.heure");
      $st->execute([$aid, $j, date('Y-m-d', strtotime('+7 days')), $j]);
      // Avancement (pièces validées) et une photo du logement, pour la carte de la mission.
      $out = array_map(function ($m) { $etat = cz_etat($m); $P = cz_checklist($m); $ok = 0; $ph = null;
        foreach ($P as $i => $_) { if (!empty($etat[$i]['ok'])) $ok++; if (!$ph && !empty($etat[$i]['photos'][0])) $ph = photo_url($etat[$i]['photos'][0], true); }
        unset($m['etat'], $m['checklist'], $m['logement_id']); return $m + ['pieces' => count($P), 'pieces_ok' => $ok, 'vignette' => $ph]; }, $st->fetchAll());
      $mp = null; try { $T = cz_paie_tarifs(); if (array_sum($T['types']) > 0) { foreach (cz_paie_mois(date('Y-m'))['personnes'] as $x) if ($x['id'] === $aid) $mp = ['missions' => count($x['missions']), 'total' => $x['total'], 'regle' => (bool)$x['regle']]; $mp = $mp ?? ['missions' => 0, 'total' => 0, 'regle' => false]; } } catch (Throwable $e) {}
      c_out(['missions' => $out, 'aujourdhui' => $j, 'mois' => $mp]);

    case 'ma_mission':
      $m = c_mission_acces((int)($_GET['id'] ?? 0)); $etat = cz_etat($m); $P = [];
      foreach (cz_checklist($m) as $i => $p) $P[] = $p + ['ok' => !empty($etat[$i]['ok']), 'urls' => array_map(fn($f) => photo_url($f, true), (array)($etat[$i]['photos'] ?? []))];
      $jour = $m['date'] <= date('Y-m-d', strtotime('+1 day'));
      c_out(['mission' => ['id' => (int)$m['id'], 'logement' => $m['logement'], 'adresse' => $m['adresse'], 'ville' => $m['ville'], 'type' => $m['type'], 'date' => $m['date'], 'heure' => $m['heure'], 'statut' => $m['statut'],
        'note' => $m['note'], 'kit_linge' => $m['kit_linge'], 'kit' => cz_kit_logement((int)$m['logement_id']), 'acces' => $jour ? cz_dec($m['acces_enc']) : '', 'pieces' => $P, 'manques' => cz_mission_manques($m),
        'commence' => $m['debut_at'] !== null, 'agent' => $m['agent'], 'agence' => AG_NOM, 'livret' => cz_livret($m, $jour)]]);

    case 'mission_debut':
      $m = c_mission_acces((int)($in['id'] ?? 0));
      if ($m['statut'] !== 'faite') { db()->prepare("UPDATE c_missions SET statut = 'en_cours', debut_at = COALESCE(debut_at, ?) WHERE id = ?")->execute([now(), $m['id']]);
        if ($m['debut_lat'] === null) $d = cz_position($m, 'debut', $in['lat'] ?? null, $in['lng'] ?? null); }
      c_out(['ok' => true, 'position' => isset($d) ? cz_position_texte($d) : null]);

    case 'mission_photo':
      $m = c_mission_acces((int)($in['id'] ?? 0)); if ($m['statut'] === 'faite') c_fail('Mission déjà terminée.');
      $i = (int)($in['piece'] ?? -1); $chk = cz_checklist($m); if (!isset($chk[$i])) c_fail('Pièce inconnue.');
      $f = $_FILES['photo'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) c_fail('Photo non reçue, réessayez.');
      $etat = cz_etat($m); if (count((array)($etat[$i]['photos'] ?? [])) >= 12) c_fail('12 photos au plus par pièce.');
      $p = save_image($f['tmp_name']); $etat[$i]['photos'][] = $p['fichier'];
      db()->prepare("UPDATE c_missions SET etat = ?, statut = IF(statut = 'a_faire', 'en_cours', statut), debut_at = COALESCE(debut_at, ?) WHERE id = ?")->execute([json_encode($etat), now(), $m['id']]);
      c_out(['ok' => true, 'url' => photo_url($p['fichier'], true)]);

    case 'mission_piece':
      $m = c_mission_acces((int)($in['id'] ?? 0)); if ($m['statut'] === 'faite') c_fail('Mission déjà terminée.');
      $i = (int)($in['piece'] ?? -1); $chk = cz_checklist($m); if (!isset($chk[$i])) c_fail('Pièce inconnue.');
      $etat = cz_etat($m); $ok = !empty($in['ok']);
      if ($ok && count((array)($etat[$i]['photos'] ?? [])) < $chk[$i]['photos']) c_fail('Prenez d\'abord ' . $chk[$i]['photos'] . ' photo(s) de la pièce « ' . $chk[$i]['piece'] . ' ».');
      $etat[$i]['ok'] = $ok; db()->prepare("UPDATE c_missions SET etat = ? WHERE id = ?")->execute([json_encode($etat), $m['id']]);
      c_out(['ok' => true]);

    case 'mission_terminer':
      $m = c_mission_acces((int)($in['id'] ?? 0)); $man = cz_mission_manques($m);
      if ($man) c_out(['ok' => false, 'manques' => $man]);
      db()->prepare("UPDATE c_missions SET statut = 'faite', fin_at = ? WHERE id = ?")->execute([now(), $m['id']]);
      cz_position($m, 'fin', $in['lat'] ?? null, $in['lng'] ?? null);
      // Arrivée suivante dans ce logement : si tout est au vert, le code part tout de suite.
      if (reglage('cz_code_auto', '1') === '1') {
        $st = db()->prepare("SELECT id FROM c_reservations WHERE logement_id = ? AND statut = 'confirmee' AND code_envoye_at IS NULL AND arrivee BETWEEN ? AND ? ORDER BY arrivee LIMIT 1");
        $st->execute([$m['logement_id'], date('Y-m-d'), date('Y-m-d', strtotime('+1 day'))]); if ($rid = (int)$st->fetchColumn()) { try { cz_envoyer_code($rid); } catch (Throwable $e) { error_log('ORVIA code : ' . $e->getMessage()); } }
      }
      c_out(['ok' => true]);

    case 'incident_signaler':
      $m = c_mission_acces((int)($in['mission'] ?? 0)); $aid = (int)$m['animatrice_id'];
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
        'piece' => cz_copie_piece_demandee(), 'pays' => reglage('cz_pays', 'MA'), 'agence' => AG_NOM, 'types' => CZ_PIECE_TYPES,
        'arrivee_mode' => $r['arrivee_mode'], 'vol' => $r['vol'], 'arrivee_heure' => $r['arrivee_heure'], 'whatsapp' => cz_dec($r['whatsapp_enc'] ?? ''), 'avis' => cz_avis_ouverts($r),
        'livret' => cz_livret_voyageur($r), 'assistant' => cz_ia_active(), 'messages' => array_map(fn($m) => ['auteur' => $m['auteur'], 'texte' => $m['texte'], 'date' => $m['created_at']], c_messages((int)$r['id'])), 'en_sejour' => date('Y-m-d') >= $r['arrivee'] && date('Y-m-d') < $r['depart'], 'termine' => date('Y-m-d') >= $r['depart'],
        'services' => $r['arrivee'] >= date('Y-m-d') ? array_map(fn($x) => ['id' => (int)$x['id'], 'nom' => $x['nom'], 'nom_en' => $x['nom_en'], 'nom_ar' => $x['nom_ar'], 'description' => $x['description'], 'prix' => (float)$x['prix']], cz_services()) : [],
        'commandes' => (function () use ($r) { $st = db()->prepare("SELECT s.nom, s.nom_en, s.nom_ar, c.quantite FROM c_commandes c JOIN c_services s ON s.id = c.service_id WHERE c.reservation_id = ? AND c.statut <> 'annulee'"); $st->execute([$r['id']]); return $st->fetchAll(); })()]);

    case 'voy_question':
      limite('voy_q', 20, 3600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      if (date('Y-m-d') > date('Y-m-d', strtotime($r['depart'] . ' +3 days'))) c_fail('Ce séjour est terminé : contactez directement votre hôte.');
      try { c_out(['ok' => true] + cz_question_voyageur($r, (string)($in['question'] ?? ''))); } catch (InvalidArgumentException $e) { c_fail($e->getMessage()); }

    case 'voy_arrivee':
      limite('voy', 60, 600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $mode = isset(CZ_ARRIVEE_MODES[$in['mode'] ?? '']) ? $in['mode'] : ''; $vol = strtoupper(trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ]/', '', (string)($in['vol'] ?? '')))));
      $h = preg_match('/^\d{2}:\d{2}$/', (string)($in['heure'] ?? '')) ? $in['heure'] : ''; $wa = clip($in['whatsapp'] ?? '', 30);
      if ($wa !== '' && cz_wa_num($wa) === '') c_fail('Numéro WhatsApp invalide : indiquez-le avec l\'indicatif du pays (ex. +33 6 12 34 56 78).');
      db()->prepare("UPDATE c_reservations SET arrivee_mode = ?, vol = ?, arrivee_heure = ?, whatsapp_enc = ?, updated_at = ? WHERE id = ?")->execute([$mode, substr($vol, 0, 12), $h, cz_enc($wa), now(), $r['id']]);
      c_out(['ok' => true]);

    case 'voy_avis':
      limite('voy_avis', 10, 3600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $photo = null; $f = $_FILES['photo'] ?? null; if ($f && $f['error'] === UPLOAD_ERR_OK) $photo = save_image($f['tmp_name'])['fichier'];
      try { cz_avis_enregistrer($r, (string)($in['moment'] ?? ''), $in, $photo); } catch (InvalidArgumentException | RuntimeException $e) { c_fail($e->getMessage()); }
      c_out(['ok' => true]);

    case 'voy_probleme':
      limite('voy_avis', 10, 3600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      if (date('Y-m-d') < $r['arrivee'] || date('Y-m-d') > $r['depart']) c_fail('Le signalement est ouvert pendant le séjour.');
      $d = clip($in['description'] ?? '', 1000); if ($d === '') c_fail('champ:description');
      $photo = null; $f = $_FILES['photo'] ?? null; if ($f && $f['error'] === UPLOAD_ERR_OK) $photo = save_image($f['tmp_name'])['fichier'];
      cz_alerte_voyageur($r, $d, $photo, false); c_out(['ok' => true]);

    case 'voy_commande':
      limite('voy_commande', 10, 3600); $r = cz_reservation_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      try { c_out(['ok' => true, 'commande' => cz_commander($r, (array)($in['services'] ?? []))]); } catch (RuntimeException $e) { c_fail($e->getMessage()); }

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
      $R = db()->query("SELECT r.id, r.logement_id, r.arrivee, r.depart, r.source, r.nb_voyageurs nb FROM c_reservations r WHERE r.logement_id IN ($in_) AND r.statut = 'confirmee' AND r.depart >= CURDATE() - INTERVAL 60 DAY ORDER BY r.arrivee")->fetchAll();
      $M = array_map(function ($m) { $ph = []; foreach (json_decode((string)$m['etat'], true) ?: [] as $e) foreach ((array)($e['photos'] ?? []) as $f) $ph[] = photo_lien($f, 7, true); unset($m['etat']); return $m + ['photos' => array_slice($ph, 0, 24)]; },
        db()->query("SELECT m.logement_id, m.type, m.date, m.fin_at, m.etat FROM c_missions m WHERE m.logement_id IN ($in_) AND m.statut = 'faite' AND m.date >= CURDATE() - INTERVAL 60 DAY ORDER BY m.date DESC")->fetchAll());
      $I = array_map(fn($i) => $i + ['photo_url' => $i['photo'] ? photo_lien($i['photo'], 7, true) : ''],
        db()->query("SELECT i.id, i.logement_id, i.description, i.photo, i.cout, i.statut, i.proprio_statut, i.created_at FROM c_incidents i WHERE i.logement_id IN ($in_) AND (i.statut = 'ouvert' OR i.created_at >= CURDATE() - INTERVAL 90 DAY) ORDER BY i.created_at DESC")->fetchAll());
      $A = db()->query("SELECT logement_id, ROUND(AVG(proprete), 1) proprete, ROUND(AVG(logement), 1) logement, ROUND(AVG(accueil), 1) accueil, COUNT(*) n FROM c_avis WHERE logement_id IN ($in_) AND created_at >= CURDATE() - INTERVAL 180 DAY GROUP BY logement_id")->fetchAll();
      $C = db()->query("SELECT logement_id, commentaire, created_at FROM c_avis WHERE logement_id IN ($in_) AND commentaire <> '' ORDER BY created_at DESC LIMIT 10")->fetchAll();
      $st = db()->prepare("SELECT id, auteur, nom, texte, photos, created_at FROM c_prop_messages WHERE proprietaire_id = ? ORDER BY id DESC LIMIT 60"); $st->execute([$p['id']]);
      $MS = array_reverse(array_map(fn($m) => array_merge($m, ['photos' => array_map(fn($f) => ['mini' => photo_lien($f, 7, true), 'url' => photo_lien($f, 7)], json_decode((string)$m['photos'], true) ?: [])]), $st->fetchAll()));
      $nonlus = (int)db()->query("SELECT COUNT(*) FROM c_prop_messages WHERE proprietaire_id = " . (int)$p['id'] . " AND auteur = 'conciergerie' AND lu_at IS NULL")->fetchColumn();
      db()->prepare("UPDATE c_prop_messages SET lu_at = ? WHERE proprietaire_id = ? AND auteur = 'conciergerie' AND lu_at IS NULL")->execute([now(), $p['id']]);
      $st = db()->prepare("SELECT id, type, titre, created_at, lu_at FROM c_prop_rapports WHERE proprietaire_id = ? ORDER BY id DESC LIMIT 24"); $st->execute([$p['id']]); $RP = $st->fetchAll();
      // Taxe de séjour : trimestre en cours et trimestre précédent, pour les logements du propriétaire (c'est lui qui la doit à la commune).
      $q = (int)ceil(date('n') / 3); $debQ = date('Y-m-d', mktime(0, 0, 0, 3 * $q - 2, 1)); $TX = [];
      foreach ([[date('Y-m-d', strtotime($debQ . ' -3 months')), date('Y-m-d', strtotime($debQ . ' -1 day'))], [$debQ, date('Y-m-d')]] as [$f, $t]) {
        $T = cz_taxe_sejour($f, $t); $noms = array_column($L, 'nom'); $lg = array_values(array_filter($T['lignes'], fn($x) => in_array($x['logement'], $noms, true)));
        $TX[] = ['from' => $f, 'to' => $t, 'nuitees' => array_sum(array_column($lg, 'nuitees')), 'montant' => round(array_sum(array_column(array_filter($lg, fn($x) => !$x['plateforme']), 'montant')), 2), 'sans_tarif' => (bool)$T['sans_tarif']]; }
      $st = db()->prepare("SELECT d.id, d.logement_id, d.titre, d.description, d.montant, d.prestataire, d.photos, d.statut, d.reponse, d.decide_at, d.paiement, d.regle_at, d.created_at FROM c_prop_devis d WHERE d.proprietaire_id = ? ORDER BY d.id DESC LIMIT 30"); $st->execute([$p['id']]);
      $DV = array_map(fn($d) => array_merge($d, ['photos' => array_map(fn($f) => ['mini' => photo_lien($f, 7, true), 'url' => photo_lien($f, 7)], json_decode((string)$d['photos'], true) ?: [])]), $st->fetchAll());
      // État des lieux de chaque séjour : entrée = dernier ménage fait avant l'arrivée, sortie = ménage fait après le départ.
      $ent = db()->prepare("SELECT id FROM c_missions WHERE logement_id = ? AND type = 'menage' AND statut = 'faite' AND date <= ? ORDER BY date DESC, id DESC LIMIT 1");
      $sor = db()->prepare("SELECT id FROM c_missions WHERE reservation_id = ? AND type = 'menage' AND statut = 'faite' LIMIT 1");
      foreach ($R as &$r) { $ent->execute([$r['logement_id'], $r['arrivee']]); $sor->execute([$r['id']]); $r['edl_entree'] = (int)$ent->fetchColumn() ?: null; $r['edl_sortie'] = (int)$sor->fetchColumn() ?: null; unset($r['id']); } unset($r);
      $rib = ['banque' => g_reglage_val('banque'), 'rib' => g_reglage_val('iban'), 'societe' => g_reglage_val('societe') ?: AG_NOM];
      c_out(['proprietaire' => $p['nom'], 'agence' => AG_NOM, 'logements' => $L, 'reservations' => $R, 'missions' => $M, 'incidents' => $I, 'notes' => $A, 'commentaires' => $C, 'messages' => $MS, 'non_lus' => $nonlus, 'rapports' => $RP, 'taxe' => $TX, 'devis' => $DV, 'reglement' => $rib]);

    case 'prop_devis_decider':
      limite('prop', 60, 600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $st = db()->prepare("SELECT id, titre FROM c_prop_devis WHERE id = ? AND proprietaire_id = ? AND statut = 'envoye'"); $st->execute([(int)($in['id'] ?? 0), $p['id']]); $d = $st->fetch() ?: c_fail('Ce devis a déjà reçu une réponse.', 404);
      $ok = ($in['decision'] ?? '') === 'ok';
      db()->prepare("UPDATE c_prop_devis SET statut = ?, reponse = ?, decide_at = ?, paiement = ? WHERE id = ?")->execute([$ok ? 'accepte' : 'refuse', trim(clip($in['reponse'] ?? '', 2000)), now(), $ok ? 'a_regler' : '', $d['id']]);
      db()->prepare("INSERT INTO c_prop_messages (proprietaire_id, auteur, nom, texte, created_at) VALUES (?,'proprietaire',?,?,?)")->execute([$p['id'], $p['nom'], ($ok ? 'Devis accepté : ' : 'Devis refusé : ') . $d['titre'] . (trim((string)($in['reponse'] ?? '')) !== '' ? "\n" . trim(clip($in['reponse'], 2000)) : ''), now()]);
      journal($ok ? 'devis accepté' : 'devis refusé', 'devis ' . $d['id'], $p['nom'], 'propriétaire'); c_out(['ok' => true]);

    case 'prop_mission':
      // Une intervention d'un logement du propriétaire, pièce par pièce, avec ses photos (état des lieux d'entrée ou de sortie).
      limite('prop', 60, 600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $m = cz_mission((int)($in['id'] ?? 0)); $st = db()->prepare("SELECT proprietaire_id FROM c_logements WHERE id = ?"); $st->execute([$m['logement_id'] ?? 0]);
      if (!$m || (int)$st->fetchColumn() !== (int)$p['id'] || $m['statut'] !== 'faite') c_fail('Intervention introuvable.', 404);
      $etat = cz_etat($m); $P = []; foreach (cz_checklist($m) as $i => $pc) $P[] = ['piece' => $pc['piece'], 'ok' => !empty($etat[$i]['ok']), 'photos' => array_map(fn($f) => ['mini' => photo_lien($f, 7, true), 'url' => photo_lien($f, 7)], (array)($etat[$i]['photos'] ?? []))];
      c_out(['mission' => ['logement' => $m['logement'], 'type' => $m['type'], 'date' => $m['date'], 'fin' => $m['fin_at'], 'pieces' => $P]]);

    case 'prop_ecrire':
      limite('prop_ecrire', 20, 3600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $txt = trim(clip($in['texte'] ?? '', 3000)); $ph = array_slice(cz_prop_photos_enregistrer(cz_fichiers('photos')), 0, 4);
      if ($txt === '' && !$ph) c_fail('Écrivez votre message.');
      db()->prepare("INSERT INTO c_prop_messages (proprietaire_id, auteur, nom, texte, photos, created_at) VALUES (?,'proprietaire',?,?,?,?)")->execute([$p['id'], $p['nom'], $txt, $ph ? json_encode($ph) : null, now()]);
      c_out(['ok' => true]);

    case 'prop_rapport':
      limite('prop', 60, 600); $p = cz_proprietaire_par_jeton((string)($in['t'] ?? '')) ?: c_fail('Ce lien n\'est plus valable.', 404);
      $st = db()->prepare("SELECT * FROM c_prop_rapports WHERE id = ? AND proprietaire_id = ?"); $st->execute([(int)($in['id'] ?? 0), $p['id']]); $r = $st->fetch() ?: c_fail('Rapport introuvable.', 404);
      if (!$r['lu_at']) db()->prepare("UPDATE c_prop_rapports SET lu_at = ? WHERE id = ?")->execute([now(), $r['id']]);
      $c = json_decode((string)$r['contenu'], true) ?: []; $lien = fn($f) => ['mini' => photo_lien($f, 30, true), 'url' => photo_lien($f, 30)];
      if (isset($c['photos'])) $c['photos'] = array_map($lien, $c['photos']);
      if (isset($c['pieces'])) foreach ($c['pieces'] as &$pc) $pc['photos'] = array_map($lien, $pc['photos']); unset($pc);
      c_out(['rapport' => ['id' => (int)$r['id'], 'type' => $r['type'], 'titre' => $r['titre'], 'from' => $r['periode_from'], 'to' => $r['periode_to'], 'note' => $r['note'], 'par' => $r['par'], 'created_at' => $r['created_at'], 'contenu' => $c], 'agence' => AG_NOM, 'proprietaire' => $p['nom']]);

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
