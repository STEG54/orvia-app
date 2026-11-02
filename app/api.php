<?php
// Toute erreur PHP fatale renvoie un message lisible à l'appli au lieu d'une page blanche « Erreur 500 ».
register_shutdown_function(function () {
  $e = error_get_last();
  if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
  if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
  echo json_encode(['error' => 'Erreur serveur : ' . $e['message'] . ' (' . basename($e['file']) . ', ligne ' . $e['line'] . ')'], JSON_UNESCAPED_UNICODE);
});
require __DIR__ . '/lib.php';
start_session();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out($d, int $c = 200) { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $m, int $c = 400) { out(['error' => $m], $c); }
/** Aperçu : l'administrateur voit l'appli d'un(e) intervenant(e), en lecture seule. */
function apercu(): ?int { return !empty($_SESSION['apercu']) && admin_ok() ? (int)$_SESSION['apercu'] : null; }
function me(): ?int { if ($p = apercu()) return $p; return isset($_SESSION['anim']) ? (int)$_SESSION['anim'] : null; }
function need_anim(): int { $id = me(); if (!$id) fail('Code requis', 401); return $id; }
function need_admin(string $d = 'lecture') {
  if (!admin_ok()) fail('Accès réservé à l\'administrateur', 401);
  if (!can($d)) fail('Votre compte ne permet pas cette action (droit « ' . (ADMIN_DROITS[$d] ?? ($d === 'principal' ? 'administrateur principal' : $d)) . ' » nécessaire). Demandez-le à l\'administrateur principal.', 403);
}
function token_ok($t): string { $t = (string)$t; if (!preg_match('/^[a-f0-9]{32}$/', $t)) fail('Brouillon invalide, rechargez la page.'); return $t; }

$action = $_GET['action'] ?? ''; demo_bloquer($action);
$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isMultipart ? $_POST : (json_decode(file_get_contents('php://input') ?: '{}', true) ?: []);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !requete_appli()) fail('Requête refusée', 403);

function lists(bool $all = false): array {
  $w = $all ? '' : ' WHERE actif = 1';
  return [
    'zones' => array_keys(ZONES), 'devises' => ZONES, 'frequentation' => FREQUENTATION, 'concurrence' => concurrents(),
    'marques' => array_map(fn($m) => $m + ['sous' => array_values(array_filter(array_map('trim', explode(',', $m['sous_marques']))))],
      db()->query("SELECT id, nom, logo, actif, detail, sous_marques, ordre FROM marques$w ORDER BY actif DESC, ordre, nom")->fetchAll()),
    'secteurs' => secteurs(), 'departements' => DEPARTEMENTS,
    'magasins' => (function () use ($w) { require_once __DIR__ . '/regles_lib.php'; rg_schema(); dep_schema();
      return array_map(fn($m) => $m + ['zti_sug' => rg_zti_suggestion((string)$m['ville'])], db()->query("SELECT id, zone, enseigne, nom, ville, adresse, actif, circuit, (lat IS NOT NULL) AS geo, geo_approx, zti, dep FROM magasins$w ORDER BY dep, nom")->fetchAll()); })(),
  ];
}
function photos_of(string $where, array $args): array {
  $st = db()->prepare("SELECT id, slot, rapport_marque_id, fichier, client FROM photos WHERE $where ORDER BY id"); $st->execute($args);
  return array_map(fn($p) => $p + ['url' => photo_url($p['fichier'], true), 'full' => photo_url($p['fichier'])], $st->fetchAll());
}
function rapport_full(int $id): ?array {
  $st = db()->prepare("SELECT r.*, CONCAT(a.prenom,' ',a.nom) AS animatrice FROM rapports r JOIN animatrices a ON a.id = r.animatrice_id WHERE r.id = ?");
  $st->execute([$id]); $r = $st->fetch(); if (!$r) return null;
  $r['concurrence'] = json_decode($r['concurrence'] ?: '[]', true) ?: [];
  $st = db()->prepare("SELECT rm.*, m.nom AS marque, m.logo, m.detail, m.sous_marques FROM rapport_marques rm JOIN marques m ON m.id = rm.marque_id WHERE rm.rapport_id = ? ORDER BY rm.id");
  $st->execute([$id]); $r['marques'] = array_map(fn($m) => $m + ['parts' => json_decode((string)($m['ventilation'] ?? ''), true) ?: []], $st->fetchAll());
  $r['photos'] = photos_of('rapport_id = ?', [$id]);
  return $r;
}

const APERCU_BLOQUE = ['login', 'offre_repondre', 'piece_upload', 'piece_delete', 'consent', 'upload_photo', 'delete_photo', 'submit', 'save_draft', 'drop_draft', 'mine_pdf', 'save_fiche', 'fiche_photo', 'fiche_cv'];
try {
  if (apercu() && (in_array($action, APERCU_BLOQUE, true)))
    fail('Aperçu : vous voyez l\'appli de cet(te) intervenant(e) en lecture seule, rien n\'est enregistré ni envoyé.', 403);
  switch ($action) {
    // ---------- commun ----------
    case 'session':
      $a = null;
      if (me()) { $st = db()->prepare("SELECT id, prenom, nom, zone, email, (rgpd_info_at IS NOT NULL AND rgpd_image_at IS NOT NULL) AS rgpd FROM animatrices WHERE id = ?" . (apercu() ? "" : " AND actif = 1")); $st->execute([me()]); $a = $st->fetch() ?: null; if (!$a && !apercu()) unset($_SESSION['anim']); }
      if ($a) { $st = db()->prepare("SELECT modele FROM animatrices WHERE id = ?"); $st->execute([$a['id']]); $a['modele'] = (string)$st->fetchColumn(); }
      if ($a && apercu()) $a['rgpd'] = 1;
      $ba = [];
      $ins = !admin_ok() && !empty($_SESSION['tf_ins']) && time() - $_SESSION['tf_ins']['t'] <= 1200 ? ['mdp' => !empty($_SESSION['tf_ins']['mdp']), 'tf' => !empty($_SESSION['tf_ins']['tf'])] : null;
      out(['inscription' => $ins, 'animatrice' => $a, 'apercu' => (bool)apercu(), 'admin' => admin_ok(), 'droits' => admin_ok() ? ($_SESSION['droits'] ?? []) : [], 'admin_nom' => admin_ok() ? admin_nom() : ''] + $ba + lists());

    case 'login':
      // 1re étape : code de l'appli + identité. Un code à 6 chiffres part ensuite par e-mail : à l'adresse déjà connue si le compte existe
      // (jamais à celle qu'on vient de taper), sinon à l'adresse saisie. Sans ce code, pas d'accès : connaître le code de l'appli et un portable ne suffit plus.
      limite('login', 20, 900);
      $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
      if ($_SESSION['tries'] > 10) { sleep(3); fail('Trop d\'essais. Réessayez plus tard.', 429); }
      $code = strtoupper(trim((string)($in['code'] ?? '')));
      if (!hash_equals(strtoupper(reglage('code_agence', 'AMAYA')), $code)) { sleep(1); fail('Code ' . AG_COURT . ' incorrect. Demandez-le à votre responsable.', 401); }
      $prenom = clip($in['prenom'] ?? '', 80); $nom = clip($in['nom'] ?? '', 80); $tel = tel_norm((string)($in['telephone'] ?? ''));
      $zone = (string)($in['zone'] ?? '');
      if ($prenom === '' || $nom === '') fail('Indiquez votre prénom et votre nom.');
      if (strlen($tel) < 9) fail('Indiquez votre numéro de portable complet.');
      $email = mb_strtolower(trim((string)($in['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Indiquez une adresse e-mail valide.');
      if (!isset(ZONES[$zone])) fail('Choisissez votre zone.');
      if (empty($in['rgpd_info']) || empty($in['rgpd_image'])) fail('Merci de cocher les deux cases sur vos données et vos photos.');
      $st = db()->prepare("SELECT id, actif, email FROM animatrices WHERE telephone <> '' AND RIGHT(telephone, 9) = RIGHT(?, 9) ORDER BY id LIMIT 1"); $st->execute([$tel]);
      $a = $st->fetch(); $telMaj = false;
      if (!$a) {
        // Personne importée (liste Plany, Excel) pas encore inscrite : on la retrouve par son e-mail, puis par son prénom et son nom.
        $st = db()->prepare("SELECT id, actif, email FROM animatrices WHERE created_at IS NULL AND email <> '' AND email = ? ORDER BY id LIMIT 1"); $st->execute([$email]);
        if (!($a = $st->fetch())) { $st = db()->prepare("SELECT id, actif, email FROM animatrices WHERE created_at IS NULL AND prenom = ? AND nom = ? ORDER BY id LIMIT 1"); $st->execute([$prenom, $nom]); $a = $st->fetch(); }
        $telMaj = (bool)$a;
      }
      if ($a && !(int)$a['actif']) fail('Votre accès a été désactivé. Contactez ' . AG_NOM . '.', 403);
      $vers = $a ? mb_strtolower(trim((string)$a['email'])) : $email;
      if ($a && $vers === '') {
        // Compte sans e-mail connu : s'il contient déjà des données sensibles, l'agence doit d'abord renseigner l'e-mail de la personne.
        $f = (array)fiche_get((int)$a['id'])['data']; $pc = db()->prepare("SELECT COUNT(*) FROM pieces_identite WHERE animatrice_id = ?"); $pc->execute([$a['id']]);
        if (($f['secu'] ?? '') !== '' || ($f['iban'] ?? '') !== '' || (int)$pc->fetchColumn()) fail('Pour protéger vos données, votre compte doit d\'abord être activé : contactez ' . AG_NOM . '.', 403);
        $vers = $email;
      }
      $c = (string)random_int(100000, 999999); $sel = bin2hex(random_bytes(8));
      $_SESSION['otp'] = ['h' => hash('sha256', $sel . $c), 's' => $sel, 'exp' => time() + 900, 'n' => 0, 'id' => $a ? (int)$a['id'] : 0, 'telmaj' => $telMaj, 'vers' => $vers,
        'd' => ['prenom' => $prenom, 'nom' => $nom, 'tel' => $tel, 'email' => $email, 'zone' => $zone]];
      require_once __DIR__ . '/report_lib.php';
      $ok = mail_send($vers, 'Votre code de connexion ' . AG_COURT . ' : ' . $c, '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5"><p>Bonjour ' . h($prenom) . ',</p>'
        . '<p>Voici votre code pour vous connecter à ' . AG_COURT . ' :</p><p style="font-size:30px;letter-spacing:6px;font-weight:bold;margin:14px 0">' . $c . '</p>'
        . '<p>Il est valable 15 minutes. Si vous n\'êtes pas à l\'origine de cette demande, ignorez cet e-mail et prévenez ' . AG_NOM . '.</p><p>Cordialement.</p></div>');
      if (defined('AMAYA_DEBUG_OTP')) @file_put_contents(sys_get_temp_dir() . '/amaya_otp.txt', $c); // uniquement en test local
      if (!$ok && !defined('AMAYA_DEBUG_OTP')) fail('L\'e-mail avec votre code n\'a pas pu partir. Réessayez dans un instant.');
      out(['otp' => true, 'vers' => email_masque($vers), 'autre' => $a && $vers !== $email]);

    case 'login_code':
      // 2e étape : le code reçu par e-mail. 6 essais par code, valable 15 minutes.
      limite('login_code', 30, 900);
      $o = $_SESSION['otp'] ?? null; if (!$o || $o['exp'] < time()) { unset($_SESSION['otp']); fail('Code expiré : appuyez sur « Recevoir un nouveau code ».', 401); }
      if (++$_SESSION['otp']['n'] > 6) { unset($_SESSION['otp']); fail('Trop d\'essais : demandez un nouveau code.', 429); }
      if (!hash_equals($o['h'], hash('sha256', $o['s'] . preg_replace('/\D/', '', (string)($in['code'] ?? ''))))) { sleep(1); fail('Code incorrect.', 401); }
      unset($_SESSION['otp']); $d = $o['d']; $now = now();
      if ($o['id']) {
        $id = $o['id']; $st = db()->prepare("SELECT actif, email FROM animatrices WHERE id = ?"); $st->execute([$id]); $a = $st->fetch();
        if (!$a || !(int)$a['actif']) fail('Votre accès a été désactivé. Contactez ' . AG_NOM . '.', 403);
        if ($o['telmaj']) db()->prepare("UPDATE animatrices SET telephone = ? WHERE id = ?")->execute([$d['tel'], $id]);
        // L'e-mail déjà connu n'est jamais remplacé à la connexion (seulement complété s'il manquait).
        db()->prepare("UPDATE animatrices SET prenom = ?, nom = ?, zone = ?, email = IF(email = '', ?, email), rgpd_info_at = COALESCE(rgpd_info_at, ?), rgpd_image_at = COALESCE(rgpd_image_at, ?), created_at = COALESCE(created_at, ?) WHERE id = ?")
          ->execute([$d['prenom'], $d['nom'], $d['zone'], $o['vers'], $now, $now, $now, $id]);
      } else {
        db()->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, code, actif, statut, created_at, rgpd_info_at, rgpd_image_at) VALUES (?,?,?,?,?,NULL,1,'attente',?,?,?)")->execute([$d['prenom'], $d['nom'], $d['tel'], $d['email'], $d['zone'], $now, $now, $now]);
        $id = (int)db()->lastInsertId(); matricules();
      }
      // Trace de la vérification par e-mail (reprise dans le dossier de preuve des contrats signés).
      try { require_once __DIR__ . '/contrats_lib.php'; c_schema(); db()->prepare("INSERT INTO verifications (animatrice_id, le, email, ip) VALUES (?,?,?,?)")->execute([$id, now(), email_masque($o['vers']), clip($_SERVER['REMOTE_ADDR'] ?? '', 60)]); } catch (Throwable $e) {}
      session_regenerate_id(true); $_SESSION['anim'] = $id; $_SESSION['anim_t'] = time(); $_SESSION['tries'] = 0; out(['ok' => true, 'rem' => remember_set((int)$id)]);

    case 'apercu_start':
      need_admin(); $st = db()->prepare("SELECT id FROM animatrices WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]);
      $_SESSION['apercu'] = (int)$st->fetchColumn() ?: fail('Intervenant(e) introuvable.'); out(['ok' => true]);

    case 'apercu_stop':
      unset($_SESSION['apercu']); out(['ok' => true]);

    case 'consent':
      $aid = need_anim();
      if (empty($in['rgpd_info']) || empty($in['rgpd_image'])) fail('Merci de cocher les deux cases.');
      db()->prepare("UPDATE animatrices SET rgpd_info_at = COALESCE(rgpd_info_at, ?), rgpd_image_at = COALESCE(rgpd_image_at, ?) WHERE id = ?")->execute([now(), now(), $aid]);
      out(['ok' => true]);

    case 'erase_animatrice':
      need_admin('tout'); effacer_animatrice((int)($in['id'] ?? 0)); journal('effacement', 'intervenant(e) n° ' . (int)($in['id'] ?? 0)); out(['ok' => true]);

    case 'login_admin':
      // Connexion nominative uniquement : e-mail + mot de passe personnel, puis code de double authentification (obligatoire hors démo).
      // Le mot de passe de secours de config.php ne sert qu'à la toute première connexion d'un dirigeant et à la procédure de secours (secours.php).
      limite('admin', 10, 900);
      $_SESSION['atries'] = ($_SESSION['atries'] ?? 0) + 1;
      if ($_SESSION['atries'] > 10) { sleep(3); fail('Trop d\'essais. Réessayez plus tard.', 429); }
      config_mdp_migrer(); tf_schema(); unset($_SESSION['tf'], $_SESSION['tf_ins']);
      $email = mb_strtolower(trim((string)($in['email'] ?? ''))); $pw = (string)($in['password'] ?? '');
      if ($email === '' || $pw === '') fail('Indiquez votre e-mail et votre mot de passe.', 401);
      $dirigeant = isset(ADMINS_PRINCIPAUX[$email]); // fondateurs (config.php) : premier accès avec le mot de passe de secours
      $st = db()->prepare("SELECT * FROM admins WHERE email = ?"); $st->execute([$email]); $a = $st->fetch();
      $viaSecours = false;
      if (!$a && $dirigeant && mdp_secours_ok($pw)) {
        // Première connexion d'un(e) dirigeant(e) : le compte se crée ; un mot de passe personnel et la double authentification sont exigés aussitôt.
        db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,'tout',1,?)")->execute([ADMINS_PRINCIPAUX[$email], $email, password_hash($pw, PASSWORD_DEFAULT), now()]);
        $st->execute([$email]); $a = $st->fetch(); $viaSecours = true;
      } else {
        if (!$a || (!(int)$a['actif'] && !$dirigeant) || !password_verify($pw, (string)$a['pass_hash'])) { journal('connexion refusée', 'administration', mb_substr($email, 0, 160), 'anonyme'); sleep(1); fail('E-mail ou mot de passe incorrect', 401); }
        // Compte créé ou rétabli avec le mot de passe de secours : il doit être remplacé par un mot de passe personnel.
        if ($dirigeant && mdp_secours_ok($pw)) $viaSecours = true;
      }
      if ($dirigeant) db()->prepare("UPDATE admins SET actif = 1, droits = 'tout' WHERE id = ?")->execute([$a['id']]);
      $droits = $dirigeant ? ['tout'] : array_values(array_filter(explode(',', $a['droits'])));
      $_SESSION['atries'] = 0;
      if ((int)$a['totp_actif'] && !demo_actif()) { $_SESSION['tf'] = ['id' => (int)$a['id'], 'droits' => $droits, 't' => time(), 'n' => 0, 'mdp' => $viaSecours]; out(['ok' => true, 'code' => true]); }
      if (tf_obligatoire() || $viaSecours) { session_regenerate_id(true); $_SESSION['tf_ins'] = ['id' => (int)$a['id'], 'droits' => $droits, 't' => time(), 'mdp' => $viaSecours, 'tf' => tf_obligatoire() && !(int)$a['totp_actif']];
        out(['ok' => true, 'inscription' => true, 'mdp' => $viaSecours, 'tf' => $_SESSION['tf_ins']['tf']]); }
      admin_ouvrir($a, $droits); out(['ok' => true]);

    case 'login_totp':
      // 2e étape : code à 6 chiffres de l'appli d'authentification, ou un code de secours (usage unique).
      limite('admin_code', 10, 900);
      $p = $_SESSION['tf'] ?? null; if (!$p || time() - $p['t'] > 300) { unset($_SESSION['tf']); fail('Session expirée : saisissez à nouveau votre e-mail et votre mot de passe.', 401); }
      if (++$_SESSION['tf']['n'] > 5) { unset($_SESSION['tf']); fail('Trop d\'essais : recommencez la connexion.', 429); }
      tf_schema(); $st = db()->prepare("SELECT * FROM admins WHERE id = ? AND actif = 1"); $st->execute([$p['id']]); $a = $st->fetch() ?: fail('Compte introuvable.', 401);
      $code = trim((string)($in['code'] ?? ''));
      if (($pas = tf_verifier(tf_secret_clair($a), $code, (int)$a['totp_dernier'])) !== null) db()->prepare("UPDATE admins SET totp_dernier = ? WHERE id = ?")->execute([$pas, $a['id']]);
      else {
        $sec = json_decode((string)$a['totp_secours'], true) ?: []; $k = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)); $ok = false;
        foreach ($sec as $i => $h) if ($k !== '' && password_verify($k, $h)) { unset($sec[$i]); $ok = true; break; }
        if (!$ok) { sleep(1); fail('Code incorrect.', 401); }
        db()->prepare("UPDATE admins SET totp_secours = ? WHERE id = ?")->execute([json_encode(array_values($sec)), $a['id']]);
      }
      unset($_SESSION['tf']);
      if (!empty($p['mdp'])) { session_regenerate_id(true); $_SESSION['tf_ins'] = ['id' => (int)$a['id'], 'droits' => $p['droits'], 't' => time(), 'mdp' => true, 'tf' => false]; out(['ok' => true, 'inscription' => true, 'mdp' => true, 'tf' => false]); }
      admin_ouvrir($a, $p['droits']); out(['ok' => true]);

    // ---------- Activation obligatoire : nouveau mot de passe personnel et/ou double authentification, avant tout accès ----------
    case 'ins_preparer':
      $I = $_SESSION['tf_ins'] ?? null; if (!$I || time() - $I['t'] > 1200) { unset($_SESSION['tf_ins']); fail('Session expirée : reconnectez-vous.', 401); }
      if (empty($I['tf'])) out(['secret' => '', 'uri' => '']);
      $st = db()->prepare("SELECT email FROM admins WHERE id = ?"); $st->execute([$I['id']]); $em = (string)$st->fetchColumn();
      $sec = tf_b32(random_bytes(20)); $_SESSION['tf_ins']['sec'] = $sec; $label = rawurlencode(AG_COURT . ':' . $em);
      out(['secret' => trim(chunk_split($sec, 4, ' ')), 'uri' => 'otpauth://totp/' . $label . '?secret=' . $sec . '&issuer=' . rawurlencode(AG_COURT) . '&digits=6&period=30']);
    case 'ins_finir':
      limite('admin_ins', 15, 900);
      $I = $_SESSION['tf_ins'] ?? null; if (!$I || time() - $I['t'] > 1200) { unset($_SESSION['tf_ins']); fail('Session expirée : reconnectez-vous.', 401); }
      tf_schema(); $st = db()->prepare("SELECT * FROM admins WHERE id = ?"); $st->execute([$I['id']]); $a = $st->fetch() ?: fail('Compte introuvable.', 401);
      $set = []; $args = []; $codes = [];
      if (!empty($I['mdp'])) {
        $n = (string)($in['password'] ?? '');
        if ($e = mdp_probleme($n, (string)$a['email'])) fail($e);
        if ($n !== (string)($in['confirm'] ?? '')) fail('Les deux mots de passe ne sont pas identiques.');
        if (mdp_secours_ok($n)) fail('Choisissez un mot de passe différent du mot de passe de secours.');
        $set[] = 'pass_hash = ?'; $args[] = password_hash($n, PASSWORD_DEFAULT);
      }
      if (!empty($I['tf'])) {
        $sec = (string)($I['sec'] ?? ''); if ($sec === '') fail('Scannez d\'abord le QR code.');
        if (($pas = tf_verifier($sec, (string)($in['code'] ?? ''), 0)) === null) fail('Code incorrect : vérifiez l\'heure du téléphone et réessayez.');
        $h = []; for ($i = 0; $i < 8; $i++) { $c = strtoupper(bin2hex(random_bytes(4))); $codes[] = substr($c, 0, 4) . '-' . substr($c, 4); $h[] = password_hash($c, PASSWORD_DEFAULT); }
        array_push($set, 'totp_secret = ?', 'totp_actif = 1', 'totp_secours = ?', 'totp_dernier = ?'); array_push($args, base64_encode(amy_chiffre($sec)), json_encode($h), $pas);
      }
      if ($set) { $args[] = $a['id']; db()->prepare("UPDATE admins SET " . implode(', ', $set) . " WHERE id = ?")->execute($args); }
      unset($_SESSION['tf_ins']); $st->execute([$I['id']]); admin_ouvrir($st->fetch(), $I['droits']);
      out(['ok' => true, 'secours' => $codes]);

    case 'tf_etat':
      need_admin(); if (empty($_SESSION['admin_id'])) out(['possible' => false]);
      tf_schema(); $st = db()->prepare("SELECT totp_actif, totp_secours FROM admins WHERE id = ?"); $st->execute([(int)$_SESSION['admin_id']]); $a = $st->fetch();
      out(['possible' => true, 'actif' => (bool)$a['totp_actif'], 'obligatoire' => tf_obligatoire(), 'secours' => count(json_decode((string)$a['totp_secours'], true) ?: [])]);
    case 'tf_preparer':
      // Nouveau secret (pas encore actif) : affiché en QR code, activé seulement après un premier code correct.
      need_admin(); if (empty($_SESSION['admin_id'])) fail('Connectez-vous avec votre e-mail.');
      tf_schema(); $sec = tf_b32(random_bytes(20)); $_SESSION['tf_nouveau'] = $sec;
      $label = rawurlencode(AG_COURT . ':' . ($_SESSION['admin_email'] ?? 'admin'));
      out(['secret' => trim(chunk_split($sec, 4, ' ')), 'uri' => 'otpauth://totp/' . $label . '?secret=' . $sec . '&issuer=' . rawurlencode(AG_COURT) . '&digits=6&period=30']);
    case 'tf_activer':
      need_admin(); $sec = (string)($_SESSION['tf_nouveau'] ?? ''); if ($sec === '' || empty($_SESSION['admin_id'])) fail('Recommencez l\'activation.');
      if (($pas = tf_verifier($sec, (string)($in['code'] ?? ''), 0)) === null) fail('Code incorrect : vérifiez l\'heure du téléphone et réessayez.');
      $codes = []; $h = []; for ($i = 0; $i < 8; $i++) { $c = strtoupper(bin2hex(random_bytes(4))); $codes[] = substr($c, 0, 4) . '-' . substr($c, 4); $h[] = password_hash($c, PASSWORD_DEFAULT); }
      db()->prepare("UPDATE admins SET totp_secret = ?, totp_actif = 1, totp_secours = ?, totp_dernier = ? WHERE id = ?")->execute([base64_encode(amy_chiffre($sec)), json_encode($h), $pas, (int)$_SESSION['admin_id']]);
      unset($_SESSION['tf_nouveau']); out(['ok' => true, 'secours' => $codes]);
    case 'tf_reinit':
      // Un(e) dirigeant(e) réinitialise la double authentification d'un autre compte (téléphone perdu) : nouvelle activation à sa prochaine connexion.
      need_admin('principal'); $id = (int)($in['id'] ?? 0); if (!$id || $id === (int)$_SESSION['admin_id']) fail('Pour votre propre compte, utilisez « Changer de téléphone ».');
      db()->prepare("UPDATE admins SET totp_secret = '', totp_actif = 0, totp_secours = NULL, totp_dernier = 0 WHERE id = ?")->execute([$id]);
      doc_journal('2fa:' . $id, admin_nom()); out(['ok' => true]);
    case 'tf_desactiver':
      need_admin(); if (empty($_SESSION['admin_id'])) fail('Connectez-vous avec votre e-mail.');
      if (tf_obligatoire()) fail('La double authentification est obligatoire : utilisez « Changer de téléphone ».');
      $st = db()->prepare("SELECT pass_hash FROM admins WHERE id = ?"); $st->execute([(int)$_SESSION['admin_id']]);
      if (!password_verify((string)($in['password'] ?? ''), (string)$st->fetchColumn())) fail('Mot de passe incorrect.');
      db()->prepare("UPDATE admins SET totp_secret = '', totp_actif = 0, totp_secours = NULL, totp_dernier = 0 WHERE id = ?")->execute([(int)$_SESSION['admin_id']]);
      out(['ok' => true]);

    case 'logout':
      if (!empty($_SESSION['anim']) && empty($_SESSION['admin'])) remember_clear();
      // Démo : on garde l'agence fictive du visiteur (sinon il retombe sur le formulaire d'accès).
      if (demo_actif() && demo_prefixe() !== '') { foreach (['admin', 'admin_id', 'admin_email', 'admin_nom', 'droits', 'anim'] as $k) unset($_SESSION[$k]); out(['ok' => true]); }
      $_SESSION = []; session_destroy(); out(['ok' => true]);

    // ---------- intervenant(e) ----------
    case 'upload_photo':
      $aid = need_anim(); $tok = token_ok($in['token'] ?? '');
      $slot = preg_match('/^(animatrice|m\d{1,2}|evt|avant|apres|conc)$/', $in['slot'] ?? '') ? $in['slot'] : fail('Emplacement de photo inconnu.');
      if (isset(['avant' => 1, 'apres' => 1, 'conc' => 1][$slot])) {
        // Visite de merchandising : photos avant, après et concurrence (8 de chaque au plus).
        require_once __DIR__ . '/merch_lib.php'; mc_schema();
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('La photo n\'a pas été reçue. Réessayez.');
        $n = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ? AND slot = ? AND merch_id IS NULL"); $n->execute([$tok, $slot]); if ($n->fetchColumn() >= MC_PHOTOS) fail(MC_PHOTOS . ' photos maximum.');
        $img = save_image($_FILES['photo']['tmp_name']);
        db()->prepare("INSERT INTO photos (token, slot, fichier, created_at) VALUES (?,?,?,?)")->execute([$tok, $slot, $img['fichier'], now()]);
        out(['photos' => photos_of('token = ? AND rapport_id IS NULL AND merch_id IS NULL', [$tok])]);
      }
      if ($slot === 'evt' || !empty($in['evt'])) {
        // Rapport d'événement : un selfie facultatif et jusqu'à 8 photos.
        require_once __DIR__ . '/evt_lib.php'; ev_schema();
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('La photo n\'a pas été reçue. Réessayez.');
        $n = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ? AND slot = ? AND evt_id IS NULL"); $n->execute([$tok, $slot]);
        if ($n->fetchColumn() >= ($slot === 'animatrice' ? 1 : EV_PHOTOS)) fail($slot === 'animatrice' ? 'Un seul selfie : supprimez le précédent pour le remplacer.' : EV_PHOTOS . ' photos maximum.');
        $img = save_image($_FILES['photo']['tmp_name']);
        db()->prepare("INSERT INTO photos (token, slot, fichier, created_at) VALUES (?,?,?,?)")->execute([$tok, $slot, $img['fichier'], now()]);
        out(['photos' => photos_of('token = ? AND rapport_id IS NULL AND evt_id IS NULL', [$tok])]);
      }
      if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('La photo n\'a pas été reçue. Réessayez.');
      $n = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ?"); $n->execute([$tok]); if ($n->fetchColumn() >= MAX_PHOTOS) fail(MAX_PHOTOS . ' photos maximum par compte rendu. Supprimez-en une pour en ajouter une autre.');
      if ($slot !== 'animatrice') { $n = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ? AND slot <> 'animatrice'"); $n->execute([$tok]); if ($n->fetchColumn() >= 4) fail('4 photos du rayon maximum. Supprimez-en une pour en mettre une autre.'); }
      if ($slot === 'animatrice') { $n = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ? AND slot = 'animatrice'"); $n->execute([$tok]); if ($n->fetchColumn() >= 1) fail('Un seul selfie : supprimez le précédent pour le remplacer.'); }
      $img = save_image($_FILES['photo']['tmp_name']);
      db()->prepare("INSERT INTO photos (token, slot, fichier, created_at) VALUES (?,?,?,?)")->execute([$tok, $slot, $img['fichier'], now()]);
      out(['photos' => photos_of('token = ? AND rapport_id IS NULL', [$tok])]);

    case 'photos':
      need_anim(); $tok = token_ok($_GET['token'] ?? '');
      out(['photos' => photos_of('token = ? AND rapport_id IS NULL AND ba_id IS NULL', [$tok])]);

    case 'delete_photo':
      need_anim(); $tok = token_ok($in['token'] ?? '');
      $st = db()->prepare("SELECT fichier FROM photos WHERE id = ? AND token = ? AND rapport_id IS NULL AND ba_id IS NULL"); $st->execute([(int)($in['id'] ?? 0), $tok]);
      if ($f = $st->fetchColumn()) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); db()->prepare("DELETE FROM photos WHERE id = ?")->execute([(int)$in['id']]); }
      out(['photos' => photos_of('token = ? AND rapport_id IS NULL AND ba_id IS NULL', [$tok])]);

    // ---------- Rapports d'événement (accueil et hôtessariat) ----------
    case 'evt_missions':
      $aid = need_anim(); require_once __DIR__ . '/evt_lib.php';
      out(['missions' => ev_missions($aid), 'affluence' => FREQUENTATION, 'champs' => EV_CHAMPS, 'max_photos' => EV_PHOTOS]);
    case 'evt_submit':
      $aid = need_anim(); $tok = token_ok($in['token'] ?? ''); require_once __DIR__ . '/evt_lib.php'; ev_schema();
      $dup = db()->prepare("SELECT id FROM evenements_cr WHERE token = ?"); $dup->execute([$tok]); if ($v = $dup->fetchColumn()) out(['ok' => true, 'id' => (int)$v]);
      $M = null; foreach (ev_missions($aid) as $m) if ($m['devis_id'] === (int)($in['devis_id'] ?? 0) && $m['date'] === (string)($in['date'] ?? '')) $M = $m;
      if (!$M) fail('Choisissez l\'événement.'); if ($M['fait']) fail('Vous avez déjà envoyé le rapport de cet événement pour ce jour.', 409);
      $aff = in_array($in['affluence'] ?? '', FREQUENTATION, true) ? $in['affluence'] : fail('Indiquez l\'affluence.');
      $nb = fn($k) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : (preg_match('/^\d{1,6}$/', $v) ? (int)$v : fail('Nombre invalide : « ' . $v . ' ».'));
      $ph = db()->prepare("SELECT COUNT(*) FROM photos WHERE token = ? AND slot = 'evt' AND evt_id IS NULL"); $ph->execute([$tok]); if (!$ph->fetchColumn()) fail('Ajoutez au moins une photo de l\'événement.');
      if (trim((string)($in['deroulement'] ?? '')) === '') fail('Décrivez le déroulement de la journée.');
      $T = []; foreach (array_keys(EV_CHAMPS) as $k) $T[$k] = clip($in[$k] ?? '', 4000);
      db()->prepare("INSERT INTO evenements_cr (token, animatrice_id, devis_id, date_evt, lieu, role, affluence, visiteurs, contacts, documents, deroulement, demandes, points_forts, ameliorer, incidents, equipe, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$tok, $aid, $M['devis_id'], $M['date'], clip($M['lieu'], 160), clip($M['role'], 120), $aff, $nb('visiteurs'), $nb('contacts'), $nb('documents'), $T['deroulement'], $T['demandes'], $T['points_forts'], $T['ameliorer'], $T['incidents'], $T['equipe'], now()]);
      $id = (int)db()->lastInsertId(); db()->prepare("UPDATE photos SET evt_id = ? WHERE token = ? AND rapport_id IS NULL AND evt_id IS NULL")->execute([$id, $tok]);
      out(['ok' => true, 'id' => $id]);
    case 'evts':
      need_admin(); require_once __DIR__ . '/evt_lib.php';
      out(['evts' => ev_liste(['from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'statut' => (string)($_GET['statut'] ?? ''), 'devis_id' => (int)($_GET['devis_id'] ?? 0)])]);
    case 'evt':
      need_admin(); require_once __DIR__ . '/evt_lib.php'; $e = ev_row((int)($_GET['id'] ?? 0)) ?: fail('Rapport introuvable.', 404);
      out(['evt' => $e, 'champs' => EV_CHAMPS, 'reco_auto' => ev_reco_auto($e), 'affluence' => FREQUENTATION]);
    case 'evt_save':
      need_admin('rapports'); require_once __DIR__ . '/evt_lib.php'; $id = (int)($in['id'] ?? 0); $e = ev_row($id) ?: fail('Rapport introuvable.', 404);
      $nb = fn($k) => ($v = trim((string)($in[$k] ?? ''))) === '' ? null : (int)preg_replace('/\D/', '', $v);
      $T = []; foreach (array_keys(EV_CHAMPS) as $k) $T[] = clip($in[$k] ?? '', 4000);
      db()->prepare("UPDATE evenements_cr SET affluence = ?, visiteurs = ?, contacts = ?, documents = ?, deroulement = ?, demandes = ?, points_forts = ?, ameliorer = ?, incidents = ?, equipe = ?, recommandations = ? WHERE id = ?")
        ->execute([in_array($in['affluence'] ?? '', FREQUENTATION, true) ? $in['affluence'] : $e['affluence'], $nb('visiteurs'), $nb('contacts'), $nb('documents'), ...$T, clip($in['recommandations'] ?? '', 4000), $id]);
      foreach ((array)($in['photos_client'] ?? []) as $pid => $v) db()->prepare("UPDATE photos SET client = ? WHERE id = ? AND evt_id = ?")->execute([$v ? 1 : 0, (int)$pid, $id]);
      if (isset($in['valide'])) db()->prepare("UPDATE evenements_cr SET valide_le = ?, valide_par = ? WHERE id = ?")->execute([$in['valide'] ? now() : null, $in['valide'] ? admin_nom() : '', $id]);
      out(['ok' => true]);
    // ---------- Merchandising : visites saisies dans l'appli ----------
    case 'mc_missions':
      $aid = need_anim(); require_once __DIR__ . '/merch_lib.php'; $M = mc_missions($aid); $R = [];
      // Par opération : la liste à relever ; par marque : le reste du catalogue (un gencod scanné hors liste s'ajoute au relevé).
      $mini = fn($r) => ['id' => (int)$r['id'], 'nom' => $r['nom'], 'gamme' => $r['gamme'], 'ean' => $r['ean'], 'prix' => $r['prix']]; $Cat = [];
      foreach ($M as $m) if ($m['marque_id'] && !isset($R[$m['devis_id']])) $R[$m['devis_id']] = array_map($mini, mc_refs_op($m['devis_id'], $m['marque_id']));
      foreach (array_unique(array_filter(array_column($M, 'marque_id'))) as $mq) $Cat[$mq] = array_values(array_map($mini, array_filter(mc_refs((int)$mq), fn($r) => $r['ean'] !== '')));
      out(['missions' => $M, 'refs' => (object)$R, 'catalogue' => (object)$Cat, 'taches' => MC_TACHES, 'emplacements' => MC_EMPLACEMENTS, 'hauteurs' => MC_HAUTEURS, 'statuts' => MC_STATUTS, 'reserve' => MC_RESERVE, 'max_photos' => MC_PHOTOS]);
    case 'mc_submit':
      $aid = need_anim(); $tok = token_ok($in['token'] ?? ''); require_once __DIR__ . '/merch_lib.php'; mc_schema();
      $dup = db()->prepare("SELECT id FROM merch_visites WHERE token = ?"); $dup->execute([$tok]); if ($v = $dup->fetchColumn()) out(['ok' => true, 'id' => (int)$v]);
      $M = null; foreach (mc_missions($aid) as $m) if ($m['devis_id'] === (int)($in['devis_id'] ?? 0) && $m['date'] === (string)($in['date'] ?? '') && $m['magasin'] === (string)($in['magasin'] ?? '')) $M = $m;
      if (!$M) fail('Choisissez la visite.'); if ($M['fait']) fail('Cette visite est déjà envoyée.', 409);
      $c = db()->prepare("SELECT slot, COUNT(*) n FROM photos WHERE token = ? AND merch_id IS NULL GROUP BY slot"); $c->execute([$tok]); $n = $c->fetchAll(PDO::FETCH_KEY_PAIR);
      if (empty($n['avant'])) fail('Ajoutez au moins une photo « avant ».'); if (empty($n['apres'])) fail('Ajoutez au moins une photo « après ».');
      $refs = mc_refs_op($M['devis_id'], $M['marque_id']); $D = mc_clean((array)($in['data'] ?? []), $M['marque_id'] ? mc_refs((int)$M['marque_id']) : []);
      $D['refs'] = array_values(array_filter($D['refs'], fn($r) => $r['statut'] !== '' || in_array($r['id'], array_map('intval', array_column($refs, 'id')), true)));
      $faits = array_column(array_filter($D['refs'], fn($r) => $r['statut'] !== ''), 'id');
      if ($refs && count(array_filter($refs, fn($r) => !in_array((int)$r['id'], $faits, true)))) fail('Indiquez pour chaque référence : présente, en rupture ou non référencée.');
      $hm = fn($k) => preg_match('/^\d{2}:\d{2}$/', (string)($in[$k] ?? '')) ? $in[$k] : '';
      $ville = (string)(db()->query("SELECT ville FROM magasins WHERE nom = " . db()->quote($M['magasin']) . " LIMIT 1")->fetchColumn() ?: '');
      db()->prepare("INSERT INTO merch_visites (token, animatrice_id, devis_id, marque_id, magasin, ville, date_visite, arrivee, depart, data, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$tok, $aid, $M['devis_id'], $M['marque_id'], clip($M['magasin'], 160), $ville, $M['date'], $hm('arrivee'), $hm('depart') ?: date('H:i'), json_encode($D, JSON_UNESCAPED_UNICODE), now()]);
      $id = (int)db()->lastInsertId(); db()->prepare("UPDATE photos SET merch_id = ? WHERE token = ? AND rapport_id IS NULL AND merch_id IS NULL AND slot IN ('avant','apres','conc')")->execute([$id, $tok]);
      out(['ok' => true, 'id' => $id]);
    // ---------- Merchandising : administration ----------
    case 'mc_visites':
      need_admin(); require_once __DIR__ . '/merch_lib.php';
      $f = ['from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'statut' => (string)($_GET['statut'] ?? ''), 'zone' => (string)($_GET['zone'] ?? '')];
      if (!empty($_GET['marque'])) $f['marques'] = [(int)$_GET['marque']];
      out(['visites' => mc_liste($f)]);
    case 'mc_visite':
      need_admin(); require_once __DIR__ . '/merch_lib.php'; $v = mc_row((int)($_GET['id'] ?? 0)) ?: fail('Visite introuvable.', 404);
      $refs = $v['marque_id'] ? mc_refs((int)$v['marque_id'], true) : [];
      out(['v' => $v, 'refs' => $refs, 'taches' => MC_TACHES, 'emplacements' => MC_EMPLACEMENTS, 'hauteurs' => MC_HAUTEURS, 'statuts' => MC_STATUTS, 'reserve' => MC_RESERVE, 'reco_auto' => mc_reco_auto(mc_stats([$v]))]);
    case 'mc_save':
      need_admin('rapports'); require_once __DIR__ . '/merch_lib.php'; $v = mc_row((int)($in['id'] ?? 0)) ?: fail('Visite introuvable.', 404);
      $D = mc_clean((array)($in['data'] ?? []), $v['marque_id'] ? mc_refs((int)$v['marque_id'], true) : []); $D['inconnus'] = $v['data']['inconnus'] ?? []; $D['scannes'] = $v['data']['scannes'] ?? 0;
      db()->prepare("UPDATE merch_visites SET data = ?, recommandations = ?, modifie_le = ? WHERE id = ?")->execute([json_encode($D, JSON_UNESCAPED_UNICODE), clip($in['recommandations'] ?? '', 4000), now(), $v['id']]);
      foreach ((array)($in['photos_client'] ?? []) as $pid => $ok) db()->prepare("UPDATE photos SET client = ? WHERE id = ? AND merch_id = ?")->execute([$ok ? 1 : 0, (int)$pid, $v['id']]);
      if (isset($in['valide'])) db()->prepare("UPDATE merch_visites SET valide_le = ?, valide_par = ? WHERE id = ?")->execute([$in['valide'] ? now() : null, $in['valide'] ? admin_nom() : '', $v['id']]);
      out(['ok' => true]);
    case 'mc_valider':
      need_admin('rapports'); require_once __DIR__ . '/merch_lib.php'; mc_schema(); $ids = array_map('intval', (array)($in['ids'] ?? [])); if (!$ids) fail('Aucune visite choisie.');
      $st = db()->prepare("UPDATE merch_visites SET valide_le = ?, valide_par = ? WHERE id = ? AND valide_le IS NULL"); $n = 0; foreach ($ids as $id) { $st->execute([now(), admin_nom(), $id]); $n += $st->rowCount(); }
      out(['ok' => true, 'n' => $n]);
    case 'mc_refs':
      need_admin(); require_once __DIR__ . '/merch_lib.php'; $mq = (int)($_GET['marque'] ?? 0); out(['refs' => mc_refs($mq, true), 'ops' => $mq ? mc_ops_marque($mq) : []]);
    case 'mc_refs_import':
      // Fichier de la marque (Excel ou CSV) : colonnes repérées, l'agence vérifie la correspondance puis fusionne dans le catalogue avant d'enregistrer.
      need_admin('tout'); require_once __DIR__ . '/merch_lib.php'; $f = $_FILES['fichier'] ?? null; if (!$f || $f['error'] !== UPLOAD_ERR_OK) fail('Choisissez le fichier de la marque.');
      if ($f['size'] > 15 * 1024 * 1024) fail('Fichier trop lourd (15 Mo au plus).');
      try { $A = mc_import_analyse(mc_fichier_lignes($f['tmp_name'], (string)$f['name'])); } catch (RuntimeException $e) { fail($e->getMessage()); }
      if (!$A['lignes']) fail('Aucune ligne lue dans ce fichier.'); out($A);
    case 'mc_sel_save':
      need_admin('tout'); require_once __DIR__ . '/merch_lib.php'; mc_schema(); $dv = (int)($in['devis'] ?? 0) ?: fail('Choisissez l\'opération.');
      db()->prepare("DELETE FROM merch_selection WHERE devis_id = ?")->execute([$dv]); $ins = db()->prepare("INSERT IGNORE INTO merch_selection (devis_id, ref_id) VALUES (?, ?)");
      foreach (array_slice(array_unique(array_map('intval', (array)($in['ids'] ?? []))), 0, 5000) as $i) if ($i) $ins->execute([$dv, $i]);
      out(['ok' => true]);
    case 'mc_refs_save':
      need_admin('tout'); require_once __DIR__ . '/merch_lib.php'; mc_schema(); $mq = (int)($in['marque'] ?? 0) ?: fail('Choisissez la marque.');
      $keep = [];
      $avant = []; foreach (mc_refs($mq, true) as $x) $avant[(int)$x['id']] = $x['prix'];
      foreach (array_slice((array)($in['refs'] ?? []), 0, 5000) as $i => $r) { $nom = clip($r['nom'] ?? '', 160); if ($nom === '') continue;
        $pc = trim(str_replace([',', ' ', '€'], ['.', '', ''], (string)($r['prix'] ?? ''))); $pc = $pc === '' || !is_numeric($pc) ? null : round((float)$pc, 2);
        $ean = preg_replace('/\D/', '', (string)($r['ean'] ?? '')); if ($ean !== '' && !mc_ean_ok($ean)) fail('Gencod invalide pour « ' . $nom . ' » : ' . $ean . ' (vérifiez les 13 chiffres).'); $fc = (int)($r['facings_cible'] ?? 0) ?: null; $id = (int)($r['id'] ?? 0);
        if (strlen($ean) === 12) $ean = '0' . $ean; $ar = isset($r['a_relever']) ? (empty($r['a_relever']) ? 0 : 1) : 1; $code = clip($r['code'] ?? '', 40);
        if ($id && array_key_exists($id, $avant)) { db()->prepare("UPDATE merch_refs SET nom = ?, ean = ?, gamme = ?, code = ?, prix_conseille = ?, facings_cible = ?, ordre = ?, actif = 1, a_relever = ? WHERE id = ? AND marque_id = ?")->execute([$nom, substr($ean, 0, 20), clip($r['gamme'] ?? '', 80), $code, $pc, $fc, $i, $ar, $id, $mq]);
          if ($avant[$id] !== $pc) db()->prepare("UPDATE merch_refs SET pvp_par = ?, pvp_le = ? WHERE id = ?")->execute([$pc === null ? '' : admin_nom() . ' (agence)', $pc === null ? null : now(), $id]); }
        else { db()->prepare("INSERT INTO merch_refs (marque_id, nom, ean, gamme, code, prix_conseille, pvp_par, pvp_le, facings_cible, ordre, actif, a_relever) VALUES (?,?,?,?,?,?,?,?,?,?,1,?)")
          ->execute([$mq, $nom, substr($ean, 0, 20), clip($r['gamme'] ?? '', 80), $code, $pc, $pc === null ? '' : admin_nom() . ' (agence)', $pc === null ? null : now(), $fc, $i, $ar]); $id = (int)db()->lastInsertId(); }
        $keep[] = $id; $pos[$i] = $id; }
      // Références retirées : désactivées (les anciens relevés restent lisibles).
      db()->prepare("UPDATE merch_refs SET actif = 0 WHERE marque_id = ?" . ($keep ? " AND id NOT IN (" . implode(',', $keep) . ")" : ''))->execute([$mq]);
      out(['ok' => true, 'refs' => mc_refs($mq, true), 'ids' => (object)($pos ?? [])]);
    case 'mc_planche':
      need_admin(); require_once __DIR__ . '/merch_lib.php'; require_once __DIR__ . '/report_lib.php'; mc_schema(); $mq = (int)($_GET['marque'] ?? 0) ?: fail('Choisissez la marque.');
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Gencods_' . $mq . '.pdf"'); echo mc_pdf(mc_planche_html($mq)); exit;
    case 'mc_refs_exemple':
      // Catalogue fictif d'exemple : la forme attendue pour coller ou importer un catalogue de marque.
      need_admin(); require_once __DIR__ . '/merch_lib.php'; require_once __DIR__ . '/xlsx_lib.php';
      $rows = [['Code article', 'Désignation', 'Gamme', 'EAN', 'PVP conseillé TTC (€)']]; $k = 0;
      foreach ([['Soin visage', [['Crème hydratante 50 ml', 18.90], ['Sérum éclat 30 ml', 26.50], ['Contour des yeux 15 ml', 21.00]]], ['Hygiène', [['Gel douche surgras 400 ml', 7.90], ['Savon doux 100 g', 3.50]]],
        ['Solaire', [['Lait solaire SPF 30 200 ml', 16.90], ['Stick lèvres SPF 50', 6.50]]], ['Maquillage', [['Mascara volume', 14.90], ['Fond de teint 30 ml', 22.00], ['Rouge à lèvres mat', 12.90]]]] as [$g, $L])
        foreach ($L as [$n, $px]) { $d12 = '2099' . sprintf('%08d', 1000 + ++$k); $rows[] = ['EX' . sprintf('%04d', $k), 'Marque Exemple · ' . $n, $g, $d12 . mc_ean_cle($d12), $px]; }
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Catalogue-exemple-gencods.xlsx"'); echo xl_simple(['Catalogue' => $rows]); exit;
    case 'mc_rapport': case 'mc_rapport_pdf': case 'mc_xlsx': case 'mc_send':
      need_admin($action === 'mc_send' ? 'envoi' : 'lecture'); require_once __DIR__ . '/merch_lib.php'; require_once __DIR__ . '/report_lib.php';
      $src = $action === 'mc_send' ? $in : $_GET; $mk = array_values(array_filter(array_map('intval', is_array($src['marques'] ?? null) ? $src['marques'] : explode(',', (string)($src['marques'] ?? '')))));
      $p = ['from' => (string)($src['from'] ?? date('Y-m-01')), 'to' => (string)($src['to'] ?? date('Y-m-d')), 'zone' => (string)($src['zone'] ?? ''), 'statut' => 'valide'] + ($mk ? ['marques' => $mk] : []);
      $V = mc_visites_completes($p);
      if ($action === 'mc_rapport') { $S = mc_stats($V); out(['stats' => $S['tot'], 'refs' => $S['refs'], 'pdv' => $S['pdv'], 'visites' => count($V), 'reco_auto' => mc_reco_auto($S)]); }
      if (!$V) fail('Aucune visite validée sur cette sélection.');
      $reco = clip($src['reco'] ?? '', 6000); if (trim($reco) === '') $reco = mc_reco_auto(mc_stats($V)); if (trim($reco) === '-') $reco = '';
      $slug = trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', implode('-', array_unique(array_filter(array_column($V, 'marque')))) ?: 'merch')), '-') . '_' . $p['from'] . '_' . $p['to'];
      if ($action === 'mc_rapport_pdf') { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Rapport-merchandising_' . $slug . '.pdf"'); echo mc_pdf(mc_report_html($V, $p, $reco)); exit; }
      if ($action === 'mc_xlsx') { header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="Releves-merchandising_' . $slug . '.xlsx"'); echo mc_xlsx($V); exit; }
      $to = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($in['dest'] ?? ''))), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL))); if ($to === '') fail('Indiquez au moins une adresse e-mail valide.');
      $files = [['Rapport-merchandising_' . $slug . '.pdf', mc_pdf(mc_report_html($V, $p, $reco))]]; if (!empty($in['xlsx'])) $files[] = ['Releves-merchandising_' . $slug . '.xlsx', mc_xlsx($V), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
      $S = mc_stats($V)['tot']; $mq = implode(', ', array_unique(array_filter(array_column($V, 'marque')))); $msg = trim((string)($in['message'] ?? ''));
      $rows = ''; foreach ([['Visites', $S['visites']], ['Points d\'intervention', $S['pdv']], ['Taux de rupture', $S['taux_rupture'] === null ? '–' : str_replace('.', ',', (string)$S['taux_rupture']) . ' %'], ['Prix conformes', $S['conformite_prix'] === null ? '–' : str_replace('.', ',', (string)$S['conformite_prix']) . ' %']] as [$l, $v])
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E6E1F5;color:#6E6A8F;font-size:13px">' . h($l) . '</td><td style="padding:8px 0;border-bottom:1px solid #E6E1F5;text-align:right;font-size:15px">' . h((string)$v) . '</td></tr>';
      $html = '<!DOCTYPE html><html><body style="margin:0;background:#F6F5FF;font-family:Arial,Helvetica,sans-serif;color:#16123A"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff"><tr><td style="padding:22px 28px;border-bottom:3px solid ' . ag_couleur() . '"><img src="' . h(BASE_URL) . '/' . ag_logo() . '" alt="' . h(AG_NOM) . '" height="46" style="display:block"></td></tr>
        <tr><td style="padding:24px 28px 8px;font-size:15px;line-height:1.6">' . nl2br(h($msg !== '' ? $msg : "Bonjour,\n\nVeuillez trouver ci-joint le rapport de merchandising du " . g_date($p['from']) . " au " . g_date($p['to']) . ".\n\nCordialement.\n\n" . AG_NOM)) . '</td></tr>
        <tr><td style="background:#16123A;color:#ffffff;padding:22px 28px"><div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#C9C2FF">Rapport merchandising</div><div style="font-size:22px;margin-top:4px">' . h($mq) . '</div><div style="font-size:13px;margin-top:6px;color:#DDD8FF">Du ' . h(g_date($p['from'])) . ' au ' . h(g_date($p['to'])) . '</div></td></tr>
        <tr><td style="padding:22px 28px"><table width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr><tr><td style="padding:16px 28px;background:#F6F5FF;font-size:11px;color:#6E6A8F">' . h(mail_pied()) . '</td></tr></table></td></tr></table></body></html>';
      $ok = mail_send($to, 'Rapport merchandising · ' . $mq . ' · ' . g_date($p['from']) . ' au ' . g_date($p['to']), $html, $files);
      db()->prepare("INSERT INTO envois (created_at, date_debut, date_fin, marques, destinataire, statut, par) VALUES (?,?,?,?,?,?,?)")->execute([now(), $p['from'], $p['to'], mb_substr('Merchandising · ' . $mq, 0, 400), $to, demo_actif() ? 'démo' : ($ok ? 'envoyé' : 'échec'), admin_nom()]);
      if (!$ok) fail('Le serveur n\'a pas pu envoyer l\'e-mail.');
      out(['ok' => true, 'message' => demo_actif() ? 'Démo : l\'e-mail n\'est pas réellement envoyé, mais l\'envoi est noté dans Envois.' : 'Rapport envoyé à ' . $to . '.']);

    // Rapport événementiel au client : une opération d'accueil, toutes ses journées, en un PDF envoyé par e-mail.
    case 'evt_ops':
      need_admin(); require_once __DIR__ . '/evt_lib.php';
      out(['ops' => ev_ops((string)($_GET['from'] ?? ''), (string)($_GET['to'] ?? ''))]);
    case 'evt_bilan':
      need_admin(); require_once __DIR__ . '/evt_lib.php';
      try { $B = ev_bilan((int)($_GET['devis_id'] ?? 0)); } catch (RuntimeException $e) { fail($e->getMessage()); }
      $B['evts'] = array_map(fn($e) => ['id' => (int)$e['id'], 'date_evt' => $e['date_evt'], 'lieu' => $e['lieu'], 'auteur' => $e['auteur'], 'role' => $e['role'], 'affluence' => $e['affluence'], 'visiteurs' => $e['visiteurs'],
        'contacts' => $e['contacts'], 'documents' => $e['documents'], 'valide_le' => $e['valide_le'], 'photos' => count(array_filter($e['photos'], fn($p) => (int)$p['client']))], $B['evts']);
      unset($B['sel']); out($B);
    case 'evt_bilan_pdf': case 'evt_bilan_send':
      need_admin($action === 'evt_bilan_send' ? 'envoi' : 'lecture'); require_once __DIR__ . '/evt_lib.php'; require_once __DIR__ . '/report_lib.php';
      $src = $action === 'evt_bilan_send' ? $in : $_GET;
      $ids = array_values(array_filter(array_map('intval', is_array($src['ids'] ?? null) ? $src['ids'] : explode(',', (string)($src['ids'] ?? '')))));
      try { $B = ev_bilan((int)($src['devis_id'] ?? 0), $ids ?: null); } catch (RuntimeException $e) { fail($e->getMessage()); }
      if (!$B['sel']) fail('Aucune journée retenue : cochez au moins un rapport.');
      $reco = clip($src['reco'] ?? '', 6000); if (trim($reco) === '') $reco = $B['reco_auto']; if (trim($reco) === '-') $reco = '';
      $nomf = 'Rapport-evenementiel_' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $B['op']['objet'] ?: $B['op']['client'])), '-') . '.pdf';
      if ($action === 'evt_bilan_pdf') { header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $nomf . '"'); echo ev_bilan_pdf($B, $reco); exit; }
      $to = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($in['to'] ?? ''))), fn($x) => filter_var($x, FILTER_VALIDATE_EMAIL)));
      if ($to === '') fail('Indiquez au moins une adresse e-mail valide.');
      $files = [[$nomf, ev_bilan_pdf($B, $reco)]];
      if (!empty($in['jours'])) foreach ($B['sel'] as $e) $files[] = ['Rapport-evenement_' . $e['date_evt'] . '_' . (int)$e['id'] . '.pdf', ev_pdf($e)];
      $O = $B['op']; $T = $B['tot']; $msg = trim((string)($in['message'] ?? ''));
      $rows = ''; foreach ([['Journées', $T['jours']], ['Personnes mobilisées', $T['personnes']], ['Visiteurs accueillis', number_format($T['visiteurs'], 0, ',', ' ')], ['Contacts collectés', number_format($T['contacts'], 0, ',', ' ')]] as [$l, $v])
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #E6E1F5;color:#6E6A8F;font-size:13px">' . h($l) . '</td><td style="padding:8px 0;border-bottom:1px solid #E6E1F5;text-align:right;font-size:15px">' . h((string)$v) . '</td></tr>';
      $html = '<!DOCTYPE html><html><body style="margin:0;background:#F6F5FF;font-family:Arial,Helvetica,sans-serif;color:#16123A"><table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">
        <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff"><tr><td style="padding:22px 28px;border-bottom:3px solid ' . ag_couleur() . '"><img src="' . h(BASE_URL) . '/' . ag_logo() . '" alt="' . h(AG_NOM) . '" height="46" style="display:block"></td></tr>
        <tr><td style="padding:24px 28px 8px;font-size:15px;line-height:1.6">' . nl2br(h($msg !== '' ? $msg : "Bonjour,\n\nVeuillez trouver ci-joint le rapport de l'opération « " . ($O['objet'] ?: $O['type']) . " ».\n\nCordialement.\n\n" . AG_NOM)) . '</td></tr>
        <tr><td style="background:#16123A;color:#ffffff;padding:22px 28px"><div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#C9C2FF">Rapport événementiel</div><div style="font-size:22px;margin-top:4px">' . h($O['objet'] ?: $O['type']) . '</div><div style="font-size:13px;margin-top:6px;color:#DDD8FF">' . h($O['client']) . '</div></td></tr>
        <tr><td style="padding:22px 28px"><table width="100%" cellpadding="0" cellspacing="0">' . $rows . '</table></td></tr><tr><td style="padding:16px 28px;background:#F6F5FF;font-size:11px;color:#6E6A8F">' . h(mail_pied()) . '</td></tr></table></td></tr></table></body></html>';
      $ok = mail_send($to, 'Rapport événementiel · ' . ($O['objet'] ?: $O['type']), $html, $files);
      $du = $B['sel'][0]['date_evt']; $au = end($B['sel'])['date_evt'];
      db()->prepare("INSERT INTO envois (created_at, date_debut, date_fin, marques, destinataire, statut, par) VALUES (?,?,?,?,?,?,?)")
        ->execute([now(), $du, $au, mb_substr('Événementiel · ' . $O['client'] . ' · ' . ($O['objet'] ?: $O['type']), 0, 400), $to, demo_actif() ? 'démo' : ($ok ? 'envoyé' : 'échec'), admin_nom()]);
      if (!$ok) fail('Le serveur n\'a pas pu envoyer l\'e-mail.');
      out(['ok' => true, 'message' => demo_actif() ? 'Démo : l\'e-mail n\'est pas réellement envoyé, mais l\'envoi est noté dans Envois.' : 'Rapport envoyé à ' . $to . '.']);
    case 'evt_pdf':
      need_admin(); require_once __DIR__ . '/evt_lib.php'; $e = ev_row((int)($_GET['id'] ?? 0)) ?: fail('Rapport introuvable.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="Rapport_evenement_' . $e['date_evt'] . '_' . (int)$e['id'] . '.pdf"'); echo ev_pdf($e); exit;

    case 'submit':
      $aid = need_anim(); $tok = token_ok($in['token'] ?? '');
      $zone = (string)($in['zone'] ?? ''); if (!isset(ZONES[$zone])) fail('Choisissez la zone.');
      $date = (string)($in['date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) > time() + 86400) fail('Date d\'animation invalide.');
      if (($in['magasin_id'] ?? '') === 'autre') {
        $mag = clip($in['magasin_autre'] ?? '', 120); $ville = clip($in['ville_autre'] ?? '', 80); $mid = null;
        if ($mag === '' || $ville === '') fail('Indiquez le nom et la ville du point de vente.');
      } else {
        $st = db()->prepare("SELECT id, nom, ville FROM magasins WHERE id = ? AND zone = ?"); $st->execute([(int)($in['magasin_id'] ?? 0), $zone]);
        $m = $st->fetch(); if (!$m) fail('Choisissez le point de vente.'); $mid = (int)$m['id']; $mag = $m['nom']; $ville = $m['ville'];
      }
      $freq = in_array($in['frequentation'] ?? '', FREQUENTATION, true) ? $in['frequentation'] : fail('Indiquez la fréquentation.');
      $conc = array_values(array_intersect(concurrents(), (array)($in['concurrence'] ?? [])));
      $lignes = array_values(array_filter((array)($in['marques'] ?? []), fn($x) => !empty($x['marque_id'])));
      if (!$lignes) fail('Ajoutez au moins une marque animée.');
      $ph = db()->prepare("SELECT slot FROM photos WHERE token = ? AND rapport_id IS NULL"); $ph->execute([$tok]); $slots = $ph->fetchAll(PDO::FETCH_COLUMN);
      if (!in_array('animatrice', $slots, true)) fail('Ajoutez votre selfie sur le point de vente.');
      if (!array_filter($slots, fn($x) => $x !== 'animatrice')) fail('Ajoutez au moins une photo du rayon.');
      $dup = db()->prepare("SELECT id FROM rapports WHERE token = ?"); $dup->execute([$tok]);
      if ($prev = $dup->fetchColumn()) out(['ok' => true, 'id' => (int)$prev]);
      // Une seule journée par intervenant(e), date et point de vente.
      $same = db()->prepare("SELECT id FROM rapports WHERE animatrice_id = ? AND date_animation = ? AND " . ($mid ? "magasin_id = ?" : "magasin_id IS NULL AND magasin = ?") . " LIMIT 1");
      $same->execute([$aid, $date, $mid ?: $mag]);
      if ($same->fetchColumn()) fail('Vous avez déjà envoyé le compte rendu du ' . date('d/m/Y', strtotime($date)) . ' pour ' . $mag . '. Pour le corriger, contactez ' . AG_NOM . '.', 409);
      // CA : chiffres, avec au plus deux décimales.
      foreach ($lignes as $l) foreach (array_merge([$l['ca'] ?? ''], array_map(fn($x) => $x['ca'] ?? '', (array)($l['sub'] ?? []))) as $x) {
        $x = str_replace([' ', "\u{00A0}", '€'], '', (string)$x); if ($x !== '' && !preg_match('/^\d+([.,]\d{1,2})?$/', $x)) fail('CA invalide : « ' . $x . ' ». Exemple : 1250 ou 1250,50.');
      }
      $pdo = db(); $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO rapports (token, animatrice_id, zone, magasin_id, magasin, ville, devise, date_animation, frequentation, concurrence, concurrence_autre, offres, staff, faits, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([$tok, $aid, $zone, $mid, $mag, $ville, ZONES[$zone], $date, $freq, json_encode($conc, JSON_UNESCAPED_UNICODE),
        clip($in['concurrence_autre'] ?? '', 200), clip($in['offres'] ?? ''), clip($in['staff'] ?? ''), clip($in['faits'] ?? ''), now()]);
      $rid = (int)$pdo->lastInsertId();
      $chk = $pdo->prepare("SELECT sous_marques FROM marques WHERE id = ?");
      $ins = $pdo->prepare("INSERT INTO rapport_marques (rapport_id, marque_id, ca, objectif, ventilation, ruptures, testeurs, reactions) VALUES (?,?,?,?,?,?,?,?)");
      $link = $pdo->prepare("UPDATE photos SET rapport_id = ?, rapport_marque_id = ? WHERE token = ? AND slot = ? AND rapport_id IS NULL");
      foreach ($lignes as $i => $l) {
        $chk->execute([(int)$l['marque_id']]); $sous = $chk->fetchColumn(); if ($sous === false) { $pdo->rollBack(); fail('Marque inconnue.'); }
        // Objectif fixé par l'agence : il remplace toujours ce que l'intervenant(e) aurait saisi.
        if ($fx = objectif_fixe($aid, (int)$l['marque_id'], $date, $mid ?: null)) {
          $l['objectif'] = $fx['montant'] === null ? '' : (string)$fx['montant']; $fs = (array)$fx['sub'];
          foreach (array_filter(array_map('trim', explode(',', (string)$sous))) as $n) { $l['sub'][$n] = (array)($l['sub'][$n] ?? []); $l['sub'][$n]['objectif'] = isset($fs[$n]) ? (string)$fs[$n] : ''; }
        }
        try { $v = ventiler((string)$sous, $l); } catch (RuntimeException $e) { $pdo->rollBack(); fail($e->getMessage()); }
        if ($v) { $l['ca'] = $v['ca']; $l['objectif'] = $v['objectif']; }
        $ca = ($l['ca'] ?? '') === '' ? null : round((float)str_replace([',', ' '], ['.', ''], (string)$l['ca']), 2);
        if ($ca !== null && ($ca < 0 || $ca > 10000000)) { $pdo->rollBack(); fail('Montant de CA invalide.'); }
        try { $obj = montant($l['objectif'] ?? ''); } catch (RuntimeException $e) { $pdo->rollBack(); fail('Objectif invalide.'); }
        $ins->execute([$rid, (int)$l['marque_id'], $ca, $obj, $v ? $v['json'] : null, clip($l['ruptures'] ?? ''), clip($l['testeurs'] ?? ''), clip($l['reactions'] ?? '')]);
        $link->execute([$rid, (int)$pdo->lastInsertId(), $tok, 'm' . (int)($l['slot'] ?? $i)]);
      }
      $link->execute([$rid, null, $tok, 'animatrice']);
      $pdo->prepare("DELETE FROM brouillons WHERE animatrice_id = ?")->execute([$aid]);
      $pdo->commit();
      out(['ok' => true, 'id' => $rid]);

    case 'objectif_fixe':
      $aid = need_anim();
      $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
      $mid = ctype_digit((string)($_GET['magasin_id'] ?? '')) ? (int)$_GET['magasin_id'] : null;
      out(['fixe' => objectif_fixe($aid, (int)($_GET['marque_id'] ?? 0), $date, $mid)]);

    // ---------- Objectifs fixés par l'agence (administration) ----------
    case 'objectifs_anim':
      need_admin();
      $rows = db()->query("SELECT o.*, m.nom AS marque, CONCAT(a.prenom, ' ', a.nom) AS animatrice, mg.nom AS magasin, mg.zone FROM objectifs_anim o JOIN marques m ON m.id = o.marque_id
        LEFT JOIN animatrices a ON a.id = o.animatrice_id LEFT JOIN magasins mg ON mg.id = o.magasin_id WHERE o.date_fin >= CURDATE() - INTERVAL 60 DAY ORDER BY o.date_debut DESC, o.id DESC")->fetchAll();
      foreach ($rows as &$r) $r['sous'] = (object)(json_decode((string)$r['sous'], true) ?: []); unset($r);
      out(['objectifs' => $rows]);

    case 'objectif_anim_save':
      need_admin('rapports');
      $d1 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date_debut'] ?? '') ? $in['date_debut'] : fail('Indiquez la date de début.');
      $d2 = preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['date_fin'] ?? '') ? $in['date_fin'] : $d1; if ($d2 < $d1) fail('La date de fin est avant la date de début.');
      $mk = (int)($in['marque_id'] ?? 0); $st = db()->prepare("SELECT sous_marques FROM marques WHERE id = ?"); $st->execute([$mk]); $sm = $st->fetchColumn();
      if ($sm === false) fail('Choisissez la marque.');
      $sous = [];
      try {
        foreach (array_filter(array_map('trim', explode(',', (string)$sm))) as $n) { $x = montant(((array)($in['sous'] ?? []))[$n] ?? ''); if ($x !== null) $sous[$n] = $x; }
        $mt = $sous ? array_sum($sous) : montant($in['montant'] ?? '');
      } catch (RuntimeException $e) { fail('Montant invalide.'); }
      if ($mt === null) fail("Indiquez l'objectif.");
      db()->prepare("INSERT INTO objectifs_anim (animatrice_id, marque_id, magasin_id, date_debut, date_fin, montant, sous, par, created_at) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([(int)($in['animatrice_id'] ?? 0) ?: null, $mk, (int)($in['magasin_id'] ?? 0) ?: null, $d1, $d2, $mt, $sous ? json_encode($sous, JSON_UNESCAPED_UNICODE) : null, admin_nom(), now()]);
      out(['ok' => true]);

    case 'objectif_anim_delete':
      need_admin('rapports'); db()->prepare("DELETE FROM objectifs_anim WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'save_draft':
      $aid = need_anim(); $tok = token_ok($in['d']['token'] ?? '');
      $json = json_encode($in['d'], JSON_UNESCAPED_UNICODE); if (strlen($json) > 200000) fail('Brouillon trop long.');
      $at = (int)($in['at'] ?? 0) ?: (int)(microtime(true) * 1000);
      $sent = db()->prepare("SELECT id FROM rapports WHERE token = ?"); $sent->execute([$tok]);
      if ($sent->fetchColumn()) out(['ok' => false, 'sent' => true]);
      // On ne remplace jamais un brouillon plus récent saisi sur un autre appareil.
      $cur = db()->prepare("SELECT updated_at FROM brouillons WHERE animatrice_id = ?"); $cur->execute([$aid]); $c = $cur->fetchColumn();
      if ($c !== false && (int)$c > $at) out(['ok' => false, 'newer' => true]);
      db()->prepare("REPLACE INTO brouillons (animatrice_id, token, data, updated_at) VALUES (?,?,?,?)")->execute([$aid, $tok, $json, $at]);
      out(['ok' => true, 'at' => $at]);

    case 'get_draft':
      $aid = need_anim();
      $st = db()->prepare("SELECT data, updated_at FROM brouillons WHERE animatrice_id = ?"); $st->execute([$aid]); $r = $st->fetch();
      out(['d' => $r ? json_decode($r['data'], true) : null, 'at' => $r ? (int)$r['updated_at'] : 0]);

    case 'drop_draft':
      // L'intervenant(e) abandonne son brouillon : on efface aussi les photos déjà envoyées pour ce brouillon.
      $aid = need_anim();
      if (preg_match('/^[a-f0-9]{32}$/', (string)($in['token'] ?? ''))) {
        foreach (photos_of('token = ? AND rapport_id IS NULL', [$in['token']]) as $p) { @unlink(upload_dir() . "/{$p['fichier']}.jpg"); @unlink(upload_dir() . "/{$p['fichier']}_t.jpg"); }
        db()->prepare("DELETE FROM photos WHERE token = ? AND rapport_id IS NULL")->execute([$in['token']]);
      }
      db()->prepare("DELETE FROM brouillons WHERE animatrice_id = ?")->execute([$aid]); out(['ok' => true]);

    case 'mine_detail':
      $aid = need_anim(); $r = rapport_full((int)($_GET['id'] ?? 0));
      if (!$r || (int)$r['animatrice_id'] !== $aid) fail('Compte rendu introuvable', 404);
      out($r);

    case 'mine_pdf':
      $aid = need_anim(); $id = (int)($in['id'] ?? 0);
      $st = db()->prepare("SELECT date_animation FROM rapports WHERE id = ? AND animatrice_id = ?"); $st->execute([$id, $aid]);
      $date = $st->fetchColumn(); if (!$date) fail('Compte rendu introuvable', 404);
      $email = mb_strtolower(trim((string)($in['email'] ?? '')));
      if ($email !== '') { if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.'); db()->prepare("UPDATE animatrices SET email = ? WHERE id = ?")->execute([$email, $aid]); }
      $st = db()->prepare("SELECT prenom, email FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch();
      if (!filter_var($a['email'], FILTER_VALIDATE_EMAIL)) fail('Indiquez votre adresse e-mail.');
      require __DIR__ . '/report_lib.php';
      out(send_single($id, $a['email'], $a['prenom']));

    // ---------- formations ----------
    case 'formations':
      $isAdmin = !empty($_SESSION['admin']) && ($_GET['admin'] ?? '') === '1';
      if (!me() && !$isAdmin) fail('Code requis', 401);
      $rows = db()->query("SELECT f.id, f.marque_id, f.titre, f.description, f.type, f.nom_original, f.mime, f.taille, f.url, f.created_at, f.restreint, m.nom AS marque, m.logo
        FROM formations f LEFT JOIN marques m ON m.id = f.marque_id ORDER BY m.ordre IS NULL DESC, m.ordre, m.nom, f.created_at DESC")->fetchAll();
      if ($isAdmin) {
        $acc = []; foreach (db()->query("SELECT formation_id, animatrice_id FROM formation_acces") as $a) $acc[$a['formation_id']][] = (int)$a['animatrice_id'];
        foreach ($rows as &$r) $r['acces'] = $acc[$r['id']] ?? []; unset($r);
      } else {
        $aid = me(); $rows = array_values(array_filter($rows, fn($r) => formation_visible($r, $aid)));
      }
      out(['formations' => $rows, 'limite' => upload_limit()]);

    case 'formation_acces':
      need_admin('tout'); $fid = (int)($in['id'] ?? 0);
      $ids = array_values(array_unique(array_map('intval', (array)($in['animatrices'] ?? []))));
      $restreint = !empty($in['restreint']) ? 1 : 0;
      if ($restreint && !$ids) fail('Choisissez au moins un(e) intervenant(e), ou ouvrez le support à tous.');
      $pdo = db(); $pdo->beginTransaction();
      $pdo->prepare("UPDATE formations SET restreint = ? WHERE id = ?")->execute([$restreint, $fid]);
      $pdo->prepare("DELETE FROM formation_acces WHERE formation_id = ?")->execute([$fid]);
      $ins = $pdo->prepare("INSERT INTO formation_acces (formation_id, animatrice_id) VALUES (?, ?)");
      if ($restreint) foreach ($ids as $a) $ins->execute([$fid, $a]);
      $pdo->commit(); out(['ok' => true]);

    case 'save_formation':
      need_admin('tout');
      $titre = clip($in['titre'] ?? '', 160); if ($titre === '') fail('Donnez un titre au support.');
      $mid = (int)($in['marque_id'] ?? 0) ?: null;
      $desc = clip($in['description'] ?? '', 1000);
      $url = trim((string)($in['url'] ?? ''));
      $accIds = array_values(array_unique(array_map('intval', array_filter(explode(',', (string)($in['acces'] ?? ''))))));
      $restr = !empty($in['restreint']) ? 1 : 0;
      if ($restr && !$accIds) fail('Choisissez au moins un(e) intervenant(e), ou ouvrez le support à tous.');
      if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] !== UPLOAD_ERR_NO_FILE) {
        $e = $_FILES['fichier']['error'];
        if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE) fail('Fichier trop lourd pour l\'hébergement (' . round(upload_limit() / 1048576) . ' Mo maximum). Pour une vidéo, mettez-la sur YouTube ou Vimeo et collez le lien.');
        if ($e !== UPLOAD_ERR_OK) fail('Le fichier n\'a pas été reçu. Réessayez.');
        $orig = clip(basename($_FILES['fichier']['name']), 200); $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!isset(FORMATION_TYPES[$ext])) fail('Format non accepté. Formats possibles : ' . implode(', ', array_keys(FORMATION_TYPES)) . '.');
        $name = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['fichier']['tmp_name'], formation_dir() . '/' . $name)) fail('Enregistrement du fichier impossible.');
        $type = str_starts_with(FORMATION_TYPES[$ext], 'video/') ? 'video' : (str_starts_with(FORMATION_TYPES[$ext], 'image/') ? 'image' : ($ext === 'pdf' ? 'pdf' : 'fichier'));
        db()->prepare("INSERT INTO formations (marque_id, titre, description, type, fichier, nom_original, mime, taille, created_at, restreint) VALUES (?,?,?,?,?,?,?,?,?,?)")
          ->execute([$mid, $titre, $desc, $type, $name, $orig, FORMATION_TYPES[$ext], filesize(formation_dir() . '/' . $name), now(), $restr]);
      } elseif ($url !== '') {
        if (!preg_match('~^https?://~i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) fail('Lien invalide : il doit commencer par https://');
        db()->prepare("INSERT INTO formations (marque_id, titre, description, type, url, created_at, restreint) VALUES (?,?,?,?,?,?,?)")->execute([$mid, $titre, $desc, 'lien', mb_substr($url, 0, 500), now(), $restr]);
      } else fail('Ajoutez un fichier ou collez un lien.');
      $fid = (int)db()->lastInsertId();
      if ($restr) { $ins = db()->prepare("INSERT IGNORE INTO formation_acces (formation_id, animatrice_id) VALUES (?, ?)"); foreach ($accIds as $a) $ins->execute([$fid, $a]); }
      out(['ok' => true]);

    case 'delete_formation':
      need_admin('tout'); $id = (int)($in['id'] ?? 0);
      $st = db()->prepare("SELECT fichier FROM formations WHERE id = ?"); $st->execute([$id]);
      if (($f = $st->fetchColumn()) !== false && $f !== '') @unlink(formation_dir() . '/' . basename($f));
      db()->prepare("DELETE FROM formation_acces WHERE formation_id = ?")->execute([$id]);
      db()->prepare("DELETE FROM formations WHERE id = ?")->execute([$id]); out(['ok' => true]);

    // ---------- fiche intervenant(e) ----------
    case 'ma_fiche':
      $aid = need_anim(); $f = fiche_get($aid); unset($f['notes_admin']);
      out($f + ['pieces' => pieces_liste($aid), 'nationalites' => NATIONALITES, 'listes' => ['certifs' => FICHE_CERTIFS, 'etudes' => FICHE_ETUDES, 'departements' => DEPARTEMENTS, 'ae' => FICHE_AE, 'statuts' => FICHE_STATUTS, 'jours' => FICHE_JOURS, 'langues' => FICHE_LANGUES, 'competences' => FICHE_COMPETENCES, 'tailles' => FICHE_TAILLES, 'metiers' => FICHE_METIERS, 'accueil' => FICHE_ACCUEIL]]);

    case 'save_fiche':
      $aid = need_anim();
      $fc = fiche_clean((array)($in['data'] ?? []));
      $ph = db()->prepare("SELECT photo FROM fiches_animatrices WHERE animatrice_id = ?"); $ph->execute([$aid]); if (!$ph->fetchColumn()) fail('Ajoutez votre photo de profil : elle est obligatoire.');
      if ($fc['statut'] === FICHE_AE && !siret_ok($fc['siret'])) fail('Indiquez votre numéro SIRET (14 chiffres) : il est obligatoire pour les auto-entrepreneurs.');
      $data = fiche_data_json($fc);
      if ($z = zone_cp((string)($fc['code_postal'] ?? ''))) db()->prepare("UPDATE animatrices SET zone = ? WHERE id = ?")->execute([$z, $aid]);
      db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)")->execute([$aid, $data, now()]);
      out(['ok' => true]);

    case 'fiche_photo':
      $aid = need_anim();
      if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('La photo n\'a pas été reçue.');
      $img = save_image($_FILES['photo']['tmp_name'], 1000);
      $st = db()->prepare("SELECT photo FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$aid]);
      if ($old = $st->fetchColumn()) { @unlink(upload_dir() . "/$old.jpg"); @unlink(upload_dir() . "/{$old}_t.jpg"); }
      db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, photo, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE photo = VALUES(photo), updated_at = VALUES(updated_at)")->execute([$aid, $img['fichier'], now()]);
      out(['photo' => photo_url($img['fichier'], true)]);

    case 'fiche_book':
      $aid = need_anim(); $B = array_column(fiche_book($aid), 'f'); if (count($B) >= FICHE_BOOK_MAX) fail(FICHE_BOOK_MAX . ' photos maximum dans le book : retirez-en une.');
      if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) fail('La photo n\'a pas été reçue.');
      $img = save_image($_FILES['photo']['tmp_name'], 1400); $B[] = $img['fichier'];
      db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, book, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE book = VALUES(book), updated_at = VALUES(updated_at)")->execute([$aid, json_encode($B), now()]);
      out(['book' => fiche_book($aid)]);
    case 'fiche_book_del':
      $aid = need_anim(); $f = (string)($in['f'] ?? ''); $B = array_column(fiche_book($aid), 'f');
      if (in_array($f, $B, true)) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); $B = array_values(array_diff($B, [$f])); db()->prepare("UPDATE fiches_animatrices SET book = ? WHERE animatrice_id = ?")->execute([json_encode($B), $aid]); }
      out(['book' => fiche_book($aid)]);

    case 'fiche_cv':
      $aid = need_anim();
      if (empty($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) fail('Le CV n\'a pas été reçu (10 Mo maximum).');
      $orig = clip(basename($_FILES['cv']['name']), 200);
      if (strtolower(pathinfo($orig, PATHINFO_EXTENSION)) !== 'pdf' || $_FILES['cv']['size'] > 10485760) fail('Le CV doit être un PDF de 10 Mo maximum.');
      if ((string)(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['cv']['tmp_name']) !== 'application/pdf') fail('Le CV doit être un PDF.');
      $name = bin2hex(random_bytes(12)) . '.bin'; // chiffré, comme les autres documents
      if (file_put_contents(fiche_dir() . '/' . $name, amy_chiffre((string)file_get_contents($_FILES['cv']['tmp_name']))) === false) fail('Enregistrement impossible.');
      $st = db()->prepare("SELECT cv FROM fiches_animatrices WHERE animatrice_id = ?"); $st->execute([$aid]);
      if ($old = $st->fetchColumn()) @unlink(fiche_dir() . '/' . basename($old));
      db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, cv, cv_nom, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE cv = VALUES(cv), cv_nom = VALUES(cv_nom), updated_at = VALUES(updated_at)")->execute([$aid, $name, $orig, now()]);
      out(['ok' => true, 'cv_nom' => $orig]);

    case 'piece_upload':
      // Documents administratifs déposés par la personne (pièce d'identité, IBAN, Carte Vitale, titre de séjour, photo pour badge…) : chiffrés sur le serveur.
      $aid = need_anim(); $type = (string)($_POST['type'] ?? ''); if (!isset(PIECES_TYPES[$type])) fail('Type de document inconnu.');
      $st = db()->prepare("SELECT COUNT(*) FROM pieces_identite WHERE animatrice_id = ? AND type IN ('" . implode("','", array_keys(PIECES_TYPES)) . "')"); $st->execute([$aid]); if ((int)$st->fetchColumn() >= 25) fail('Trop de documents : supprimez-en un d\'abord.');
      $f = doc_recu();
      db()->prepare("INSERT INTO pieces_identite (animatrice_id, type, fichier, nom, mime, taille, titre, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$aid, $type, $f['fichier'], $f['nom'], $f['mime'], $f['taille'], clip($_POST['titre'] ?? '', 160), now()]);
      out(['ok' => true, 'pieces' => pieces_liste($aid)]);

    case 'piece_delete':
      // La personne ne retire que ses propres documents administratifs (jamais ses bulletins ni attestations).
      $aid = need_anim(); $st = db()->prepare("SELECT fichier FROM pieces_identite WHERE id = ? AND animatrice_id = ? AND type IN ('" . implode("','", array_keys(PIECES_TYPES)) . "')"); $st->execute([(int)($in['id'] ?? 0), $aid]);
      if ($f = $st->fetchColumn()) { @unlink(fiche_dir() . '/' . basename($f)); db()->prepare("DELETE FROM pieces_identite WHERE id = ?")->execute([(int)$in['id']]); }
      out(['ok' => true, 'pieces' => pieces_liste($aid)]);

    // ---------- Notifications sur le téléphone ----------
    case 'push_cle':
      require_once __DIR__ . '/extras_lib.php'; $K = push_cles(); out(['cle' => $K['pub'] ?? null]);

    case 'push_abonner':
      require_once __DIR__ . '/extras_lib.php'; $aid = need_anim(); if (apercu()) fail('Aperçu : les notifications se règlent sur le téléphone de la personne.'); x_schema();
      $ep = (string)($in['endpoint'] ?? ''); if (!preg_match('#^https://[a-z0-9.-]+/#i', $ep) || strlen($ep) > 700) fail('Abonnement invalide.');
      db()->prepare("INSERT INTO push_abos (animatrice_id, endpoint, p256dh, auth, ua, created_at) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE animatrice_id = VALUES(animatrice_id), p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua)")
        ->execute([$aid, $ep, clip($in['keys']['p256dh'] ?? '', 200), clip($in['keys']['auth'] ?? '', 100), clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255), now()]);
      out(['ok' => true]);

    case 'notif_derniere':
      // Appelé par le téléphone à la réception d'une notification : le dernier message pas encore affiché.
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $aid = me(); if (!$aid) out(['notif' => null]);
      $st = db()->prepare("SELECT id, titre, texte, vue FROM notifs WHERE animatrice_id = ? AND affiche_le IS NULL AND created_at >= ? ORDER BY id"); $st->execute([$aid, date('Y-m-d H:i:s', strtotime('-2 days'))]); $L = $st->fetchAll();
      if (!$L) { $st = db()->prepare("SELECT id, titre, texte, vue FROM notifs WHERE animatrice_id = ? ORDER BY id DESC LIMIT 1"); $st->execute([$aid]); $L = $st->fetchAll(); }
      if (!$L) out(['notif' => null]);
      db()->prepare("UPDATE notifs SET affiche_le = ? WHERE id IN (" . implode(',', array_map('intval', array_column($L, 'id'))) . ")")->execute([now()]);
      $n = end($L); if (count($L) > 1) $n = ['id' => $n['id'], 'titre' => count($L) . ' nouveautés ' . AG_COURT, 'texte' => implode(' · ', array_column($L, 'titre')), 'vue' => $n['vue']];
      out(['notif' => $n]);

    // ---------- Disponibilités ----------
    case 'mes_dispos':
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $aid = need_anim();
      $st = db()->prepare("SELECT jour, etat FROM dispos WHERE animatrice_id = ? AND jour >= ? ORDER BY jour"); $st->execute([$aid, date('Y-m-01', strtotime('-1 month'))]);
      $m = db()->prepare("SELECT DISTINCT date_mission FROM missions WHERE animatrice_id = ? AND statut <> 'annulee' AND date_mission >= ?"); $m->execute([$aid, date('Y-m-01', strtotime('-1 month'))]);
      out(['dispos' => array_column($st->fetchAll(), 'etat', 'jour'), 'missions' => $m->fetchAll(PDO::FETCH_COLUMN)]);

    case 'dispo_set':
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $aid = need_anim(); if (apercu()) fail('Aperçu : lecture seule.');
      $e = (string)($in['etat'] ?? ''); if (!in_array($e, ['o', 'n', ''], true)) fail('Choix inconnu.');
      $n = 0; foreach (array_slice((array)($in['jours'] ?? []), 0, 400) as $j) { if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$j) || $j < date('Y-m-d')) continue; $n++;
        if ($e === '') db()->prepare("DELETE FROM dispos WHERE animatrice_id = ? AND jour = ?")->execute([$aid, $j]);
        else db()->prepare("REPLACE INTO dispos (animatrice_id, jour, etat, modifie_le) VALUES (?,?,?,?)")->execute([$aid, $j, $e, now()]); }
      out(['ok' => true, 'n' => $n]);

    // ---------- Notes de frais ----------
    case 'mes_factures':
      $aid = need_anim(); require_once __DIR__ . '/ae_lib.php';
      $m = db()->prepare("SELECT DISTINCT m.devis_id, d.objet, d.numero FROM missions m JOIN g_docs d ON d.id = m.devis_id WHERE m.animatrice_id = ? AND m.statut <> 'annulee' AND m.date_mission >= ? ORDER BY m.devis_id DESC");
      $m->execute([$aid, date('Y-m-d', strtotime('-120 days'))]);
      out(['ae' => ae_est($aid), 'factures' => ae_liste(['f.animatrice_id = ?'], [$aid]), 'operations' => $m->fetchAll()]);
    case 'facture_ae_ajouter':
      $aid = need_anim(); if (apercu()) fail('Aperçu : lecture seule.'); require_once __DIR__ . '/ae_lib.php'; ae_schema();
      if (!ae_est($aid)) fail('Réservé aux auto-entrepreneurs : indiquez ce statut et votre SIRET dans « Ma fiche ».');
      $mt = round((float)str_replace([',', ' '], ['.', ''], (string)($_POST['montant'] ?? '')), 2); if ($mt <= 0 || $mt > 100000) fail('Indiquez le montant de la facture.');
      $per = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['periode'] ?? '')) ? $_POST['periode'] : fail('Indiquez le mois facturé.');
      if (empty($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? 4) === UPLOAD_ERR_NO_FILE) fail('Joignez la facture (PDF ou photo).');
      $f = doc_recu();
      db()->prepare("INSERT INTO factures_ae (animatrice_id, numero, montant, periode, devis_id, commentaire, fichier, nom, mime, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$aid, clip($_POST['numero'] ?? '', 60), $mt, $per, (int)($_POST['devis_id'] ?? 0) ?: null, clip($_POST['commentaire'] ?? '', 500), $f['fichier'], $f['nom'], $f['mime'], now()]);
      out(['ok' => true, 'factures' => ae_liste(['f.animatrice_id = ?'], [$aid])]);
    case 'facture_ae_suppr':
      $aid = need_anim(); require_once __DIR__ . '/ae_lib.php'; ae_schema();
      $st = db()->prepare("SELECT fichier FROM factures_ae WHERE id = ? AND animatrice_id = ? AND statut = 'recue'"); $st->execute([(int)($in['id'] ?? 0), $aid]); $fi = $st->fetchColumn();
      if ($fi === false) fail('Seule une facture pas encore traitée peut être retirée.');
      db()->prepare("DELETE FROM factures_ae WHERE id = ?")->execute([(int)$in['id']]); @unlink(fiche_dir() . '/' . basename((string)$fi));
      out(['ok' => true, 'factures' => ae_liste(['f.animatrice_id = ?'], [$aid])]);

    case 'mes_ndf':
      require_once __DIR__ . '/extras_lib.php'; $aid = need_anim();
      $m = db()->prepare("SELECT DISTINCT m.devis_id, m.magasin, m.date_mission, d.objet FROM missions m LEFT JOIN g_docs d ON d.id = m.devis_id WHERE m.animatrice_id = ? AND m.statut <> 'annulee' AND m.date_mission BETWEEN ? AND ? ORDER BY m.date_mission DESC");
      $m->execute([$aid, date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+7 days'))]);
      out(['ndf' => ndf_liste(['n.animatrice_id = ?'], [$aid]), 'types' => NDF_TYPES, 'statuts' => NDF_STATUTS, 'missions' => $m->fetchAll()]);

    case 'ndf_ajouter':
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $aid = need_anim(); if (apercu()) fail('Aperçu : lecture seule.');
      $jour = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['jour'] ?? '')) ? $_POST['jour'] : ''; if ($jour === '' || $jour > date('Y-m-d')) fail('Indiquez la date de la dépense.');
      $type = (string)($_POST['type'] ?? ''); if (!isset(NDF_TYPES[$type])) fail('Choisissez le type de dépense.');
      $mt = round((float)str_replace([',', ' '], ['.', ''], (string)($_POST['montant'] ?? '')), 2); if ($mt <= 0 || $mt > 2000) fail('Indiquez le montant TTC du ticket.');
      if (empty($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? 4) === UPLOAD_ERR_NO_FILE) fail('Prenez le ticket en photo : il est obligatoire.');
      $f = doc_recu();
      $dv = (int)($_POST['devis_id'] ?? 0) ?: null; $mag = clip($_POST['magasin'] ?? '', 160);
      db()->prepare("INSERT INTO ndf (animatrice_id, jour, type, montant, devis_id, magasin, commentaire, fichier, mime, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$aid, $jour, $type, $mt, $dv, $mag, clip($_POST['commentaire'] ?? '', 500), $f['fichier'], $f['mime'], now()]);
      out(['ok' => true, 'ndf' => ndf_liste(['n.animatrice_id = ?'], [$aid])]);

    case 'ndf_supprimer':
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $aid = need_anim(); if (apercu()) fail('Aperçu : lecture seule.');
      $st = db()->prepare("SELECT fichier FROM ndf WHERE id = ? AND animatrice_id = ? AND statut = 'a_valider'"); $st->execute([(int)($in['id'] ?? 0), $aid]); $fi = $st->fetchColumn();
      if ($fi === false) fail('Seule une note pas encore validée peut être supprimée.');
      db()->prepare("DELETE FROM ndf WHERE id = ?")->execute([(int)$in['id']]); if ($fi) @unlink(fiche_dir() . '/' . basename((string)$fi));
      out(['ok' => true, 'ndf' => ndf_liste(['n.animatrice_id = ?'], [$aid])]);

    case 'ndf_ticket':
      // Le ticket (chiffré) : visible par la personne, ou par la direction.
      require_once __DIR__ . '/extras_lib.php'; x_schema(); $st = db()->prepare("SELECT animatrice_id, fichier, mime FROM ndf WHERE id = ?"); $st->execute([(int)($_GET['id'] ?? 0)]); $n = $st->fetch();
      $moi = !empty($_SESSION['anim']) && empty($_SESSION['apercu']) ? (int)$_SESSION['anim'] : 0;
      if (!$n || !(est_titulaire() || ($moi && $moi === (int)$n['animatrice_id']))) fail('Ticket introuvable.', 404);
      $data = is_file($p = fiche_dir() . '/' . basename($n['fichier'])) ? amy_dechiffre((string)file_get_contents($p)) : null; if ($data === null) fail('Ticket illisible.', 404);
      header('Content-Type: ' . (in_array($n['mime'], DOCS_MIMES, true) ? $n['mime'] : 'application/octet-stream')); header('Content-Disposition: inline; filename="ticket"'); header('X-Content-Type-Options: nosniff'); echo $data; exit;

    case 'piece_expiration':
      // Date d'expiration d'une pièce (identité, titre de séjour) : un rappel part 30 jours avant.
      $id = (int)($in['id'] ?? 0); $d = (string)($in['date'] ?? ''); if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) fail('Date invalide.');
      $st = db()->prepare("SELECT animatrice_id FROM pieces_identite WHERE id = ?"); $st->execute([$id]); $own = (int)$st->fetchColumn();
      $moi = !empty($_SESSION['anim']) && empty($_SESSION['apercu']) ? (int)$_SESSION['anim'] : 0; if (!$own || !(est_titulaire() || $moi === $own)) fail('Document introuvable.', 404);
      pieces_liste($own); db()->prepare("UPDATE pieces_identite SET expire_le = ?, rappel_le = NULL WHERE id = ?")->execute([$d ?: null, $id]); out(['ok' => true, 'pieces' => pieces_liste($own)]);

    case 'mes_documents':
      $aid = need_anim(); out(['pieces' => pieces_liste($aid), 'agence' => docs_agence(), 'types' => PIECES_TYPES, 'sal' => DOCS_SAL, 'cats' => DOCS_AGENCE]);

    // ---------- Documents : gestion par la direction ----------
    case 'doc_admin_upload':
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      $aid = (int)($_POST['animatrice_id'] ?? 0); $type = (string)($_POST['type'] ?? ''); if (!isset(DOCS_TYPES[$type])) fail('Type de document inconnu.');
      $ex = db()->prepare("SELECT 1 FROM animatrices WHERE id = ?"); $ex->execute([$aid]); if (!$ex->fetchColumn()) fail('Personne introuvable.');
      $per = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_POST['periode'] ?? '')) ? $_POST['periode'] : null;
      $f = doc_recu();
      db()->prepare("INSERT INTO pieces_identite (animatrice_id, type, fichier, nom, mime, taille, titre, periode, par, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$aid, $type, $f['fichier'], $f['nom'], $f['mime'], $f['taille'], clip($_POST['titre'] ?? '', 160), $per, admin_nom(), now()]);
      require_once __DIR__ . '/extras_lib.php'; lp_notif($aid, 'Nouveau document : ' . DOCS_TYPES[$type], AG_COURT . ' a déposé un document dans votre espace' . ($per ? ' (' . $per . ')' : '') . '.', 'docs');
      out(['ok' => true, 'pieces' => pieces_liste($aid)]);

    case 'doc_admin_delete':
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      $st = db()->prepare("SELECT fichier, animatrice_id FROM pieces_identite WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $d = $st->fetch(); if (!$d) fail('Document introuvable.');
      @unlink(fiche_dir() . '/' . basename($d['fichier'])); db()->prepare("DELETE FROM pieces_identite WHERE id = ?")->execute([(int)$in['id']]);
      out(['ok' => true, 'pieces' => pieces_liste((int)$d['animatrice_id'])]);

    case 'docs_agence':
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      $mois = date('Y-m', strtotime('first day of last month'));
      $st = db()->prepare("SELECT p.periode, p.type, COUNT(*) n, SUM(p.vu_le IS NOT NULL) vus FROM pieces_identite p WHERE p.type IN ('bulletin', 'ft') GROUP BY p.periode, p.type ORDER BY p.periode DESC LIMIT 24"); $st->execute();
      out(['docs' => docs_agence(), 'cats' => DOCS_AGENCE, 'sal' => DOCS_SAL, 'envois' => $st->fetchAll(), 'mois' => $mois]);

    case 'docs_agence_upload':
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      $cat = (string)($_POST['categorie'] ?? ''); if (!isset(DOCS_AGENCE[$cat])) fail('Catégorie inconnue.');
      $f = doc_recu(); $titre = clip($_POST['titre'] ?? '', 160) ?: DOCS_AGENCE[$cat];
      db()->prepare("INSERT INTO docs_agence (categorie, titre, fichier, nom, mime, taille, par, created_at) VALUES (?,?,?,?,?,?,?,?)")->execute([$cat, $titre, $f['fichier'], $f['nom'], $f['mime'], $f['taille'], admin_nom(), now()]);
      out(['ok' => true, 'docs' => docs_agence()]);

    case 'docs_agence_delete':
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      $st = db()->prepare("SELECT fichier FROM docs_agence WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]);
      if ($f = $st->fetchColumn()) { @unlink(fiche_dir() . '/' . basename($f)); db()->prepare("DELETE FROM docs_agence WHERE id = ?")->execute([(int)$in['id']]); }
      out(['ok' => true, 'docs' => docs_agence()]);

    case 'docs_lot':
      // Bulletins ou attestations du mois en une fois : chaque fichier est rattaché à la personne d'après son nom (matricule, ou NOM et prénom).
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403); @set_time_limit(300);
      $type = (string)($_POST['type'] ?? ''); if (!isset(DOCS_SAL[$type])) fail('Type de document inconnu.');
      $per = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)($_POST['periode'] ?? '')) ? $_POST['periode'] : fail('Indiquez le mois.');
      $simu = empty($_POST['confirmer']);
      $norm = fn($x) => preg_replace('/[^a-z0-9]+/', ' ', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', (string)$x)));
      $P = db()->query("SELECT id, nom, prenom, matricule FROM animatrices WHERE nom NOT LIKE 'effacée%' AND nom NOT LIKE '%(fusionné avec%'")->fetchAll();
      $files = $_FILES['fichiers'] ?? null; if (!$files || !is_array($files['name'])) fail('Choisissez les fichiers.');
      $ok = []; $ko = [];
      foreach ($files['name'] as $i => $nm) {
        $f = ['name' => $nm, 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]; $n = ' ' . $norm(pathinfo($nm, PATHINFO_FILENAME)) . ' ';
        $hit = [];
        foreach ($P as $a) { $m = $a['matricule'] ? ' ' . $norm($a['matricule']) . ' ' : null;
          if (($m && str_contains($n, $m)) || (str_contains($n, ' ' . trim($norm($a['nom'])) . ' ') && str_contains($n, ' ' . trim($norm($a['prenom'])) . ' '))) $hit[$a['id']] = $a; }
        if (count($hit) !== 1) { $ko[] = $nm . (count($hit) ? ' (plusieurs personnes possibles)' : ' (personne non reconnue)'); continue; }
        $a = reset($hit); $ok[] = $nm . ' → ' . mb_strtoupper($a['nom']) . ' ' . $a['prenom'];
        if (!$simu) { $d = doc_recu('', $f); db()->prepare("INSERT INTO pieces_identite (animatrice_id, type, fichier, nom, mime, taille, periode, par, created_at) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$a['id'], $type, $d['fichier'], $d['nom'], $d['mime'], $d['taille'], $per, admin_nom(), now()]); }
      }
      out(['ok' => true, 'simule' => $simu, 'reconnus' => $ok, 'non_reconnus' => $ko]);

    case 'fiche_admin':
      need_admin(); $id = (int)($_GET['id'] ?? 0);
      $st = db()->prepare("SELECT COUNT(DISTINCT r.id) nb, SUM(rm.ca) ca, MAX(r.date_animation) derniere, MIN(r.date_animation) premiere FROM rapports r LEFT JOIN rapport_marques rm ON rm.rapport_id = r.id WHERE r.animatrice_id = ?");
      $st->execute([$id]); $s = $st->fetch();
      $st = db()->prepare("SELECT m.nom, COUNT(DISTINCT r.id) n FROM rapports r JOIN rapport_marques rm ON rm.rapport_id = r.id JOIN marques m ON m.id = rm.marque_id WHERE r.animatrice_id = ? GROUP BY m.nom ORDER BY n DESC"); $st->execute([$id]);
      $fo = db()->prepare("SELECT COUNT(*) FROM formations f WHERE f.restreint = 0 OR EXISTS (SELECT 1 FROM formation_acces a WHERE a.formation_id = f.id AND a.animatrice_id = ?)"); $fo->execute([$id]);
      // N° de sécurité sociale et pièces d'identité : en clair pour la direction seulement.
      $F = fiche_get($id); $F['data'] = (array)$F['data']; if (!est_titulaire() && ($F['data']['secu'] ?? '') !== '') $F['data']['secu'] = secu_masque($F['data']['secu']); if (!est_titulaire() && ($F['data']['iban'] ?? '') !== '') $F['data']['iban'] = iban_masque($F['data']['iban']); $F['data'] = (object)$F['data'];
      $mt = db()->prepare("SELECT matricule FROM animatrices WHERE id = ?"); $mt->execute([$id]);
      out($F + ['matricule' => $mt->fetchColumn(), 'pieces' => est_titulaire() ? pieces_liste($id) : null, 'stats' => $s, 'par_marque' => $st->fetchAll(), 'formations' => (int)$fo->fetchColumn()]);

    case 'fiche_notes':
      need_admin('rapports');
      db()->prepare("INSERT INTO fiches_animatrices (animatrice_id, notes_admin, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE notes_admin = VALUES(notes_admin)")->execute([(int)($in['id'] ?? 0), clip($in['notes'] ?? '', 4000), now()]);
      out(['ok' => true]);

    case 'mine':
      $aid = need_anim();
      $st = db()->prepare("SELECT r.id, r.date_animation, r.magasin, r.ville, r.devise, r.frequentation, GROUP_CONCAT(m.nom SEPARATOR ', ') AS marques, SUM(rm.ca) AS ca, MAX(m.logo) AS logo
        FROM rapports r JOIN rapport_marques rm ON rm.rapport_id = r.id JOIN marques m ON m.id = rm.marque_id
        WHERE r.animatrice_id = ? GROUP BY r.id ORDER BY r.date_animation DESC, r.id DESC LIMIT 30");
      $st->execute([$aid]); out(['rapports' => $st->fetchAll()]);

    // ---------- administrateur ----------
    case 'admin_lists':
      need_admin(); purge_rgpd(); matricules();
      out(lists(true) + ['animatrices' => db()->query("SELECT a.id, a.matricule, a.statut, a.prenom, a.nom, a.telephone, a.email, a.zone, a.actif, a.created_at, a.rgpd_image_at, a.modele,
          (SELECT COUNT(*) FROM rapports r WHERE r.animatrice_id = a.id) AS nb, (SELECT MAX(r.date_animation) FROM rapports r WHERE r.animatrice_id = a.id) AS derniere, fi.data AS fiche, fi.photo
          FROM animatrices a LEFT JOIN fiches_animatrices fi ON fi.animatrice_id = a.id ORDER BY a.actif DESC, a.nom, a.prenom")->fetchAll(),
        'doublons' => can('tout') ? doublons() : [], 'statuts_anim' => STATUTS_ANIM, 'fiche_listes' => ['ae' => FICHE_AE, 'jours' => FICHE_JOURS, 'competences' => FICHE_COMPETENCES, 'langues' => FICHE_LANGUES, 'metiers' => FICHE_METIERS, 'accueil' => FICHE_ACCUEIL], 'code_agence' => reglage('code_agence', 'AMAYA'),
        'groupes' => array_map(fn($g) => ['id' => (int)$g['id'], 'nom' => $g['nom'], 'marques' => array_map('intval', array_filter(explode(',', $g['marques'])))], db()->query("SELECT * FROM groupes ORDER BY nom")->fetchAll()),
        'envois' => db()->query("SELECT * FROM envois ORDER BY id DESC LIMIT 50")->fetchAll(), 'mail_to' => MAIL_TO_DEFAULT,
        'listes' => db()->query("SELECT * FROM listes_diffusion ORDER BY ordre, nom")->fetchAll(),
        'ba_modeles' => (object)[],
        'droits_admin' => ADMIN_DROITS, 'moi' => ['id' => (int)($_SESSION['admin_id'] ?? 0), 'nom' => admin_nom(), 'droits' => $_SESSION['droits'] ?? [], 'principal' => est_principal(), 'gestion' => isset(ADMINS_PRINCIPAUX[$_SESSION['admin_email'] ?? ''])],
        'ia' => ANTHROPIC_API_KEY !== '']);

    case 'rapports':
    case 'export':
      need_admin();
      $w = ['r.date_animation BETWEEN ? AND ?']; $a = [($_GET['from'] ?? '') ?: '2000-01-01', ($_GET['to'] ?? '') ?: '2100-01-01'];
      if (!empty($_GET['zone']) && ($sq = secteur_sql((string)$_GET['zone'], $a)) !== '') $w[] = $sq;
      if (!empty($_GET['animatrice'])) { $w[] = 'r.animatrice_id = ?'; $a[] = (int)$_GET['animatrice']; }
      if (!empty($_GET['marque'])) { $w[] = 'rm.marque_id = ?'; $a[] = (int)$_GET['marque']; }
      if (($_GET['statut'] ?? '') === 'avalider') $w[] = 'r.valide_le IS NULL';
      if (($_GET['statut'] ?? '') === 'valide') $w[] = 'r.valide_le IS NOT NULL';
      // Une ligne par animation : une marque animée, un jour, un point de vente, un(e) intervenant(e).
      $st = db()->prepare("SELECT r.id, rm.id AS ligne_id, r.date_animation, (SELECT dep FROM magasins mg WHERE mg.id = r.magasin_id) AS zone, r.magasin, r.ville, r.devise, r.frequentation, CONCAT(an.prenom,' ',an.nom) AS animatrice,
        m.nom AS marque, rm.ca, r.valide_le, r.valide_par, r.source, rm.reactions, rm.ruptures, rm.testeurs, r.concurrence, r.concurrence_autre, r.offres, r.staff, r.faits, r.created_at,
        (SELECT COUNT(*) FROM photos p WHERE p.rapport_id = r.id AND (p.rapport_marque_id = rm.id OR p.rapport_marque_id IS NULL)) AS nb_photos
        FROM rapport_marques rm JOIN rapports r ON r.id = rm.rapport_id JOIN marques m ON m.id = rm.marque_id JOIN animatrices an ON an.id = r.animatrice_id
        WHERE " . implode(' AND ', $w) . " ORDER BY r.date_animation DESC, r.id DESC, rm.id LIMIT 2000");
      $st->execute($a); $rows = $st->fetchAll();
      if ($action === 'rapports') out(['rapports' => array_map(fn($r) => array_diff_key($r, array_flip(['reactions','ruptures','testeurs','concurrence','concurrence_autre','offres','staff','faits'])), $rows)]);
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="animations_' . date('Y-m-d') . '.csv"');
      $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
      fputcsv($f, ['Date', 'Intervenant(e)', 'Zone', 'Point de vente', 'Ville', 'Marque', 'CA (€)', 'Fréquentation', 'Réactions clients', 'Ruptures', 'Testeurs manquants',
        'Concurrence présente', 'Offres en cours', 'Personnel et direction', 'Faits marquants', 'Photos', 'Saisi le'], ';');
      foreach ($rows as $r) {
        $conc = implode(', ', array_filter(array_merge(json_decode($r['concurrence'] ?: '[]', true) ?: [], [$r['concurrence_autre']])));
        fputcsv($f, [date('d/m/Y', strtotime($r['date_animation'])), $r['animatrice'], $r['zone'], $r['magasin'], $r['ville'], $r['marque'],
          $r['ca'] === null ? '' : str_replace('.', ',', (string)(float)$r['ca']), $r['frequentation'], $r['reactions'], $r['ruptures'], $r['testeurs'],
          $conc, $r['offres'], $r['staff'], $r['faits'], $r['nb_photos'], $r['created_at']], ';');
      }
      exit;

    case 'valider':
      // Validation par l'agence : seuls les comptes rendus validés sont visibles dans l'espace marques.
      need_admin('rapports');
      $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))); if (!$ids) fail('Aucun compte rendu choisi.');
      if (!empty($in['valide'])) db()->prepare("UPDATE rapports SET valide_le = ?, valide_par = ? WHERE valide_le IS NULL AND id IN (" . implode(',', $ids) . ")")->execute([now(), admin_nom()]);
      else db()->exec("UPDATE rapports SET valide_le = NULL, valide_par = '' WHERE id IN (" . implode(',', $ids) . ")");
      out(['ok' => true, 'n' => count($ids)]);

    case 'photo_client':
      need_admin('rapports');
      db()->prepare("UPDATE photos SET client = ? WHERE id = ?")->execute([empty($in['client']) ? 0 : 1, (int)($in['id'] ?? 0)]); out(['ok' => true]);

    // ---------- Comptes de l'espace marques ----------
    case 'clients_comptes':
      need_admin('tout');
      $rows = db()->query("SELECT id, nom, societe, email, marques, zones, perimetre, ba, droits, noms, actif, created_at, derniere_connexion FROM comptes_clients ORDER BY societe, nom")->fetchAll();
      foreach ($rows as &$c) { $c['perimetre'] = compte_perimetre($c); $c['ba'] = compte_ba($c); } unset($c);
      out(['comptes' => $rows]);

    case 'clients_depuis_listes':
      need_admin('tout'); out(['propositions' => comptes_depuis_listes()]);

    case 'clients_creer_lot':
      // Création en une fois des comptes cochés ; un mot de passe est tiré au hasard pour chacun et affiché une seule fois.
      need_admin('tout'); $res = [];
      $ins = db()->prepare("INSERT INTO comptes_clients (nom, societe, email, marques, zones, perimetre, ba, droits, noms, actif, pass_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?,1,?,?)");
      foreach ((array)($in['comptes'] ?? []) as $c) {
        $email = mb_strtolower(trim((string)($c['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $dup = db()->prepare("SELECT id FROM comptes_clients WHERE email = ?"); $dup->execute([$email]); if ($dup->fetchColumn()) continue;
        $per = compte_perimetre(['perimetre' => json_encode($c['perimetre'] ?? [])]); $ba = compte_ba(['ba' => implode(',', (array)($c['ba'] ?? []))]); if (!$per && !$ba) continue;
        $pw = substr(str_replace(['+', '/', '='], ['k', 'm', ''], base64_encode(random_bytes(12))), 0, 12);
        $ins->execute([clip($c['nom'] ?? $email, 120), clip($c['societe'] ?? '', 120), $email, implode(',', array_unique(array_column($per, 'm'))), '', json_encode($per), implode(',', $ba),
          'ca,photos,cr,telecharger,pvp', 'complet', password_hash($pw, PASSWORD_DEFAULT), now()]);
        $res[] = ['email' => $email, 'nom' => clip($c['nom'] ?? $email, 120), 'password' => $pw];
      }
      out(['crees' => $res]);

    case 'client_envoyer_acces':
      // Nouveau mot de passe envoyé par e-mail au client, avec l'adresse de l'espace marques.
      need_admin('tout'); require_once __DIR__ . '/report_lib.php';
      $st = db()->prepare("SELECT * FROM comptes_clients WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $c = $st->fetch(); if (!$c) fail('Compte introuvable.');
      $pw = substr(str_replace(['+', '/', '='], ['k', 'm', ''], base64_encode(random_bytes(12))), 0, 12);
      $url = rtrim(BASE_URL, '/') . '/marques.php';
      $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5"><p>Bonjour ' . h(explode(' ', $c['nom'])[0]) . ',</p>'
        . '<p>Nous avons le plaisir de vous donner accès à votre espace marques ' . AG_NOM . ', une plateforme dédiée au suivi et à l’analyse de vos opérations d’animation.</p>'
        . '<p>Vous y retrouvez l’ensemble de vos reportings validés, les résultats chiffrés, les photos terrain et les comptes rendus, accessibles à tout moment.</p>'
        . '<p>Conçu pour vous offrir une vision claire et exploitable de nos interventions, cet espace vous permet de consulter, télécharger et exploiter librement les données dont vous avez besoin, avec la possibilité de générer les rapports adaptés à vos besoins et à vos objectifs.</p>'
        . '<p><b>Vos accès :</b><br>Adresse : <a href="' . h($url) . '">' . h($url) . '</a><br>Identifiant : ' . h($c['email']) . '<br>Mot de passe : ' . h($pw) . '</p>'
        . '<p>Nous mettons cet outil à votre disposition pour vous apporter davantage de visibilité, de réactivité et d’autonomie dans le pilotage de vos opérations.</p>'
        . '<p>Nous restons naturellement à votre disposition pour vous accompagner dans son utilisation.</p><p>Cordialement.</p><p>La direction<br>' . AG_NOM . '</p></div>';
      if (!mail_send($c['email'], 'Votre accès à l\'espace marques ' . AG_NOM, $html)) fail('L\'e-mail n\'a pas pu partir. Réessayez.');
      db()->prepare("UPDATE comptes_clients SET pass_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $c['id']]);
      // Nouveaux accès = remise à zéro complète : la double authentification (téléphone perdu) est aussi retirée, le client la réactive s'il le souhaite.
      if (in_array('totp_actif', db()->query("SHOW COLUMNS FROM comptes_clients")->fetchAll(PDO::FETCH_COLUMN), true)) db()->prepare("UPDATE comptes_clients SET totp_secret = '', totp_actif = 0, totp_secours = NULL, totp_dernier = 0 WHERE id = ?")->execute([$c['id']]);
      journal('accès marque', 'nouveaux accès envoyés : ' . $c['email']); out(['ok' => true]);

    case 'client_compte_save':
      need_admin('tout');
      $id = (int)($in['id'] ?? 0); $email = mb_strtolower(trim((string)($in['email'] ?? '')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.');
      $nom = clip($in['nom'] ?? '', 120); if ($nom === '') fail('Indiquez le nom.');
      $per = compte_perimetre(['perimetre' => json_encode($in['perimetre'] ?? [])]); $ba = implode(',', compte_ba(['ba' => implode(',', (array)($in['ba'] ?? []))]));
      if (!$per && $ba === '') fail('Choisissez au moins une marque ou un reporting BA.');
      $mk = implode(',', array_unique(array_column($per, 'm'))); $zs = ''; $perJ = json_encode($per, JSON_UNESCAPED_UNICODE);
      $dr = implode(',', array_values(array_intersect(['ca', 'photos', 'cr', 'telecharger', 'pvp'], (array)($in['droits'] ?? []))));
      $noms = in_array($in['noms'] ?? '', ['complet', 'prenom', 'non'], true) ? $in['noms'] : 'complet';
      $pw = (string)($in['password'] ?? '');
      if ($pw !== '' && ($e = mdp_probleme($pw))) fail($e);
      if (!$id && $pw === '') fail('Choisissez un mot de passe.');
      $dup = db()->prepare("SELECT id FROM comptes_clients WHERE email = ? AND id <> ?"); $dup->execute([$email, $id]); if ($dup->fetchColumn()) fail('Cette adresse a déjà un compte.');
      $vals = [$nom, clip($in['societe'] ?? '', 120), $email, $mk, $zs, $perJ, $ba, $dr, $noms, empty($in['actif']) && $id ? 0 : 1];
      if ($id) {
        db()->prepare("UPDATE comptes_clients SET nom=?, societe=?, email=?, marques=?, zones=?, perimetre=?, ba=?, droits=?, noms=?, actif=? WHERE id=?")->execute([...$vals, $id]);
        if ($pw !== '') db()->prepare("UPDATE comptes_clients SET pass_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
      } else db()->prepare("INSERT INTO comptes_clients (nom, societe, email, marques, zones, perimetre, ba, droits, noms, actif, pass_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([...$vals, password_hash($pw, PASSWORD_DEFAULT), now()]);
      out(['ok' => true]);

    case 'client_apercu':
      // Aperçu : l'administrateur ouvre l'espace marques exactement comme ce client le voit (sans son mot de passe).
      need_admin('tout');
      $st = db()->prepare("SELECT id FROM comptes_clients WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $id = (int)$st->fetchColumn(); if (!$id) fail('Compte introuvable.');
      $_SESSION['client_id'] = $id; $_SESSION['client_apercu'] = 1; out(['ok' => true]);

    case 'client_compte_delete':
      need_admin('tout'); db()->prepare("DELETE FROM comptes_clients WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); db()->prepare("DELETE FROM rapports_clients WHERE compte_id = ?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'rapport':
      need_admin(); $r = rapport_full((int)($_GET['id'] ?? 0)); if (!$r) fail('Compte rendu introuvable', 404); out($r);

    case 'update_rapport':
      // Correction par l'administratrice avant envoi aux marques.
      need_admin('rapports'); $id = (int)($in['id'] ?? 0);
      $cur = rapport_full($id); if (!$cur) fail('Compte rendu introuvable', 404);
      $zone = (string)($in['zone'] ?? ''); if (!isset(ZONES[$zone])) fail('Zone invalide.');
      $date = (string)($in['date_animation'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) fail('Date invalide.');
      if (($in['magasin_id'] ?? '') === 'autre' || ($in['magasin_id'] ?? '') === '') {
        $mag = clip($in['magasin'] ?? '', 120); $ville = clip($in['ville'] ?? '', 80); $mid = null;
        if ($mag === '' || $ville === '') fail('Indiquez le nom et la ville du point de vente.');
      } else {
        $st = db()->prepare("SELECT id, nom, ville FROM magasins WHERE id = ?"); $st->execute([(int)$in['magasin_id']]);
        $m = $st->fetch(); if (!$m) fail('Point de vente inconnu.'); $mid = (int)$m['id']; $mag = $m['nom']; $ville = $m['ville'];
      }
      $freq = in_array($in['frequentation'] ?? '', FREQUENTATION, true) ? $in['frequentation'] : fail('Fréquentation invalide.');
      $conc = array_values(array_intersect(concurrents(), (array)($in['concurrence'] ?? [])));
      $pdo = db(); $pdo->beginTransaction();
      $pdo->prepare("UPDATE rapports SET zone = ?, devise = ?, magasin_id = ?, magasin = ?, ville = ?, date_animation = ?, frequentation = ?, concurrence = ?, concurrence_autre = ?, offres = ?, staff = ?, faits = ?, modifie_le = ? WHERE id = ?")
        ->execute([$zone, ZONES[$zone], $mid, $mag, $ville, $date, $freq, json_encode($conc, JSON_UNESCAPED_UNICODE), clip($in['concurrence_autre'] ?? '', 200),
          clip($in['offres'] ?? ''), clip($in['staff'] ?? ''), clip($in['faits'] ?? ''), now(), $id]);
      $own = array_column($cur['marques'], 'id');
      $up = $pdo->prepare("UPDATE rapport_marques SET marque_id = ?, ca = ?, objectif = ?, ventilation = ?, reactions = ?, ruptures = ?, testeurs = ? WHERE id = ? AND rapport_id = ?");
      $sq = $pdo->prepare("SELECT sous_marques FROM marques WHERE id = ?");
      foreach ((array)($in['marques'] ?? []) as $l) {
        if (!in_array((int)($l['id'] ?? 0), array_map('intval', $own), true)) continue;
        $sq->execute([(int)$l['marque_id']]);
        try { $v = ventiler((string)$sq->fetchColumn(), $l); $ca = $v ? $v['ca'] : montant($l['ca'] ?? ''); $obj = $v ? $v['objectif'] : montant($l['objectif'] ?? ''); } catch (RuntimeException $e) { $pdo->rollBack(); fail($e->getMessage()); }
        $up->execute([(int)$l['marque_id'], $ca, $obj, $v ? $v['json'] : null, clip($l['reactions'] ?? ''), clip($l['ruptures'] ?? ''), clip($l['testeurs'] ?? ''), (int)$l['id'], $id]);
      }
      foreach (array_map('intval', (array)($in['photos_del'] ?? [])) as $pid) {
        $st = $pdo->prepare("SELECT fichier FROM photos WHERE id = ? AND rapport_id = ?"); $st->execute([$pid, $id]);
        if ($f = $st->fetchColumn()) { @unlink(upload_dir() . "/$f.jpg"); @unlink(upload_dir() . "/{$f}_t.jpg"); $pdo->prepare("DELETE FROM photos WHERE id = ?")->execute([$pid]); }
      }
      $pdo->commit();
      out(['ok' => true, 'rapport' => rapport_full($id)]);

    case 'delete_rapport':
      need_admin('rapports'); $id = (int)($in['id'] ?? 0);
      foreach (photos_of('rapport_id = ?', [$id]) as $p) { @unlink(upload_dir() . "/{$p['fichier']}.jpg"); @unlink(upload_dir() . "/{$p['fichier']}_t.jpg"); }
      db()->prepare("DELETE FROM photos WHERE rapport_id = ?")->execute([$id]);
      db()->prepare("DELETE FROM rapports WHERE id = ?")->execute([$id]); out(['ok' => true]);

    case 'import_animatrices':
      // Liste collée depuis Excel ou fichier Excel / CSV. Avec une ligne d'en-tête (Nom, Prénom, Email, Téléphone, Code postal, Pays…), les colonnes sont reconnues dans n'importe quel ordre.
      // Sans en-tête : Prénom, Nom, Portable, E-mail, Zone. Ville et nationalité (export Plany) vont sur la fiche, sans écraser ce qui est déjà rempli.
      need_admin('tout');
      @set_time_limit(120);
      $rows = [];
      $txt = (string)($in['texte'] ?? '');
      if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $fn = $_FILES['fichier']['tmp_name'];
        if (file_get_contents($fn, false, null, 0, 2) === 'PK') $rows = xlsx_rows($fn);
        else $txt .= "\n" . file_get_contents($fn);
      }
      $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
      if (!mb_check_encoding($txt, 'UTF-8')) $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
      foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        if (trim($line) === '') continue;
        $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : (substr_count($line, ',') >= 1 ? ',' : null));
        $rows[] = $sep ? str_getcsv($line, $sep) : [$line];
      }
      $rows = array_values(array_filter(array_map(fn($r) => array_map(fn($x) => trim((string)$x), $r), $rows), fn($r) => implode('', $r) !== ''));
      // Repérage de la ligne d'en-tête (parmi les premières lignes : un titre peut la précéder)
      $X0 = ['cp' => -1, 'ville' => -1, 'nat' => -1, 'adr' => [], 'secu' => -1, 'nomn' => -1, 'iban' => -1, 'bic' => -1, 'ndate' => -1, 'nlieu' => -1, 'npays' => -1, 'civ' => -1, 'siret' => -1];
      $col = ['prenom' => 0, 'nom' => 1, 'tel' => 2, 'mail' => 3, 'zone' => 4] + $X0;
      $low = fn($x) => trim(preg_replace('/\s+/', ' ', strtr(mb_strtolower((string)$x), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '-' => ' ', '_' => ' ', '°' => ' ', '.' => ' ', "'" => ' ', '’' => ' '])));
      $hRow = -1; foreach (array_slice($rows, 0, 8, true) as $i => $rr) { $hh = array_map($low, $rr); if (preg_grep('/^pre ?nom/', $hh) && preg_grep('/^nom\b/', $hh)) { $hRow = $i; break; } }
      if ($hRow >= 0) {
        $col = ['prenom' => -1, 'nom' => -1, 'tel' => -1, 'mail' => -1, 'zone' => -1] + $X0;
        foreach ($rows[$hRow] as $k => $h) { $h = $low($h); if ($h === '') continue;
          if (preg_match('/^pre ?nom/', $h)) { if ($col['prenom'] < 0) $col['prenom'] = $k; }
          elseif (preg_match('/naissance|^ne le|^nee le|^ne a|^nee a/', $h)) {
            if (preg_match('/date|^ne le|^nee le|^naissance$/', $h) && $col['ndate'] < 0) $col['ndate'] = $k;
            elseif (preg_match('/pays/', $h) && $col['npays'] < 0) $col['npays'] = $k;
            elseif (preg_match('/^nom/', $h)) { if ($col['nomn'] < 0) $col['nomn'] = $k; }
            elseif (preg_match('/lieu|ville|commune|^ne a|^nee a/', $h) && $col['nlieu'] < 0) $col['nlieu'] = $k; }
          elseif (preg_match('/^nom (de jeune fille|patronymique|de naissance)$/', $h)) { if ($col['nomn'] < 0) $col['nomn'] = $k; }
          elseif (preg_match('/^nom( de famille| d usage)?$/', $h)) { if ($col['nom'] < 0) $col['nom'] = $k; }
          elseif (preg_match('/^iban/', $h)) { if ($col['iban'] < 0) $col['iban'] = $k; }
          elseif (preg_match('/^(bic|swift|code bic)/', $h)) { if ($col['bic'] < 0) $col['bic'] = $k; }
          elseif (preg_match('/secu|securite sociale|^nir\b|^n ss\b|^no ss\b|^num(ero)? ss\b|^ss$|insee/', $h)) { if ($col['secu'] < 0) $col['secu'] = $k; }
          elseif (preg_match('/tel|portable|mobile|gsm/', $h)) { if ($col['tel'] < 0) $col['tel'] = $k; }
          elseif (preg_match('/mail/', $h)) { if ($col['mail'] < 0) $col['mail'] = $k; }
          elseif (preg_match('/code postal|^cp\b|postal/', $h)) { if ($col['cp'] < 0) $col['cp'] = $k; }
          elseif (preg_match('/^(adresse|adr|rue|voie|complement|numero et rue|n et rue)/', $h) && !preg_match('/mail|ip\b/', $h)) $col['adr'][] = $k;
          elseif (preg_match('/^(ville|commune|localite)/', $h)) { if ($col['ville'] < 0) $col['ville'] = $k; }
          elseif (preg_match('/^nationalit/', $h)) { if ($col['nat'] < 0) $col['nat'] = $k; }
          elseif (preg_match('/^(civilite|sexe|genre|titre)$/', $h)) { if ($col['civ'] < 0) $col['civ'] = $k; }
          elseif (preg_match('/siret/', $h)) { if ($col['siret'] < 0) $col['siret'] = $k; }
          elseif (preg_match('/^(zone|pays|territoire|region)/', $h)) { if ($col['zone'] < 0) $col['zone'] = $k; }
        }
        if ($col['nom'] < 0 && $col['nomn'] >= 0) { $col['nom'] = $col['nomn']; $col['nomn'] = -1; } // seul nom donné : le nom de naissance
        $pris = []; foreach ($col as $v) foreach ((array)$v as $x) if ($x >= 0) $pris[] = $x;
        $lus = []; $ign_c = []; foreach ($rows[$hRow] as $k => $h) { if (trim((string)$h) === '') continue; (in_array($k, $pris, true) ? $lus[] = trim($h) : $ign_c[] = trim($h)); }
        $rows = array_slice($rows, $hRow + 1);
        if ($col['prenom'] < 0 || $col['nom'] < 0) fail('Colonnes « Prénom » et « Nom » introuvables dans la ligne d\'en-tête.');
      }
      $zones = array_keys(ZONES); $zoneDef = in_array($in['zone'] ?? '', $zones, true) ? $in['zone'] : 'France';
      $zoneDe = function (string $z, string $cp) use ($zones, $zoneDef, $low) {
        $z = $low($z);
        foreach ($zones as $zz) if ($z !== '' && str_contains($z, $low($zz))) return $zz;
        $cp = preg_replace('/\D/', '', $cp);
        if (strlen($cp) === 5 || $z !== '') return 'France';
        return $zoneDef;
      };
      $n = 0; $deja = 0; $ign = 0; $compl = 0; $simu = !empty($_POST['simuler']) || !empty($in['simuler']); $pdo = db(); $pdo->beginTransaction();
      // Complète la fiche (adresse, n° SS, naissance, nationalité…) et le portable / l'e-mail manquants. Sans « remplacer », rien de déjà rempli n'est écrasé.
      $ecraser = !empty($_POST['ecraser']) || !empty($in['ecraser']); $champs = []; $conflits = [];
      $completer = function (int $id, string $tel, string $mail, array $F) use ($pdo, &$compl, &$champs, $ecraser) {
        $a = $pdo->prepare("SELECT telephone, email FROM animatrices WHERE id = ?"); $a->execute([$id]); $a = $a->fetch(); $chg = false;
        if ($a && $a['telephone'] === '' && strlen($tel) >= 9) { $pdo->prepare("UPDATE animatrices SET telephone = ? WHERE id = ?")->execute([$tel, $id]); $chg = true; }
        if ($a && $a['email'] === '' && $mail !== '') { $u = $pdo->prepare("SELECT 1 FROM animatrices WHERE email = ? AND id <> ?"); $u->execute([$mail, $id]); if (!$u->fetchColumn()) { $pdo->prepare("UPDATE animatrices SET email = ? WHERE id = ?")->execute([$mail, $id]); $chg = true; } }
        $f = (array)fiche_get($id)['data']; $d = $f;
        foreach ($F as $k => $v) if ($v !== '' && ($ecraser || trim((string)($d[$k] ?? '')) === '') && (string)($d[$k] ?? '') !== $v) { $d[$k] = $v; $champs[$k] = ($champs[$k] ?? 0) + 1; }
        if ($d !== $f) { $pdo->prepare("INSERT INTO fiches_animatrices (animatrice_id, data, updated_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)")->execute([$id, fiche_data_json(fiche_clean($d)), now()]); $chg = true; }
        if ($chg) { $compl++; if ($zz = zone_cp((string)($d['code_postal'] ?? ''))) $pdo->prepare("UPDATE animatrices SET zone = ? WHERE id = ?")->execute([$zz, $id]); }
      };
      // Valeurs lues dans le fichier, remises au format de la fiche.
      $date = function (string $v): string { $v = trim($v); if ($v === '') return '';
        if (preg_match('/^\d{5}(\.\d+)?$/', $v) && (int)$v > 1000 && (int)$v < 80000) return gmdate('Y-m-d', ((int)$v - 25569) * 86400); // date Excel
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) [$y, $mo, $j] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})[\/.\- ](\d{1,2})[\/.\- ](\d{2,4})/', $v, $m)) { [$j, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]]; if ($y < 100) $y += $y > (int)date('y') ? 1900 : 2000; }
        else return '';
        return checkdate($mo, $j, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $j) : ''; };
      $natN = function (string $v) use ($low): string { $v = trim($v); if ($v === '') return ''; $l = $low($v);
        if (in_array($l, ['france', 'fr', 'fra', 'francais', 'francaise'], true)) return 'Française';
        foreach (NATIONALITES as $n) if ($low($n) === $l || rtrim($low($n), 'e') === $l) return $n; return mb_convert_case(mb_strtolower($v), MB_CASE_TITLE); };
      $civN = function (string $v, string $secu) use ($low): string { $l = $low($v);
        if (preg_match('/^(mme|madame|mlle|mademoiselle|f|femme|feminin|2)$/', $l)) return 'Mme'; if (preg_match('/^(m|mr|monsieur|h|homme|masculin|1)$/', $l)) return 'M.';
        return $secu !== '' ? ($secu[0] === '2' ? 'Mme' : ($secu[0] === '1' ? 'M.' : '')) : ''; };
      $tc = fn($v) => mb_convert_case(mb_strtolower(trim($v)), MB_CASE_TITLE);
      $byTel = db()->prepare("SELECT id FROM animatrices WHERE telephone <> '' AND RIGHT(telephone, 9) = RIGHT(?, 9) LIMIT 1");
      $byMail = db()->prepare("SELECT id FROM animatrices WHERE email <> '' AND email = ? LIMIT 1");
      $byNom = db()->prepare("SELECT id FROM animatrices WHERE prenom = ? AND nom = ? LIMIT 1");
      $ins = db()->prepare("INSERT INTO animatrices (prenom, nom, telephone, email, zone, code, actif, created_at) VALUES (?,?,?,?,?,NULL,1,NULL)");
      $get = fn($r, $k) => $k >= 0 ? ($r[$k] ?? '') : '';
      foreach ($rows as $r) {
        if (count($r) === 1 || (count(array_filter($r, fn($x) => $x !== '')) === 1 && $r[0] !== '')) { // « Prénom NOM » dans une seule cellule
          $w = preg_split('/\s+/', $r[0]); if (count($w) < 2) { $ign++; continue; }
          $caps = array_values(array_filter($w, fn($x) => mb_strtoupper($x) === $x && preg_match('/\p{L}/u', $x)));
          if ($caps && count($caps) < count($w)) { $nom = implode(' ', $caps); $prenom = implode(' ', array_diff($w, $caps)); }
          else { $nom = array_pop($w); $prenom = implode(' ', $w); }
          $r = [$prenom, $nom]; $c = ['prenom' => 0, 'nom' => 1, 'tel' => -1, 'mail' => -1, 'zone' => -1] + $X0;
        } else $c = $col;
        $prenom = clip($get($r, $c['prenom']), 80); $nom = clip($get($r, $c['nom']), 80);
        if (preg_match('/^pr[ée]nom$/iu', $prenom)) continue;
        if ($prenom === '' || $nom === '') { $ign++; continue; }
        $prenom = mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE); $nom = mb_strtoupper($nom);
        $tel = tel_norm($get($r, $c['tel'])); if (strlen($tel) === 9 && $tel[0] !== '0') $tel = '0' . $tel; // 0692… lu comme nombre par Excel
        $m = mb_strtolower($get($r, $c['mail'])); $mail = filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : '';
        $z = $zoneDe($get($r, $c['zone']), preg_replace('/\s+/', '', $get($r, $c['cp'])));
        if (strlen($tel) < 9) $tel = '';
        $secu = strtoupper(preg_replace('/[^0-9AB]/i', '', $get($r, $c['secu']))); if (strlen($secu) < 13) $secu = '';
        $cp = preg_replace('/\s+/', '', $get($r, $c['cp'])); if (preg_match('/^\d{4}$/', $cp)) $cp = '0' . $cp; // 06000 lu comme nombre
        $lieu = trim($get($r, $c['nlieu'])); $pays = trim($get($r, $c['npays'])); if ($pays !== '' && !preg_match('/^france$/i', $pays)) $lieu = trim($lieu . ($lieu !== '' ? ' (' . $tc($pays) . ')' : $tc($pays)));
        $F = ['adresse' => trim(implode(', ', array_filter(array_map(fn($k) => trim($r[$k] ?? ''), $c['adr'])))), 'code_postal' => clip($cp, 10), 'ville' => $tc(clip($get($r, $c['ville']), 80)),
          'nationalite' => clip($natN($get($r, $c['nat'])), 60), 'secu' => $secu, 'naissance_date' => $date($get($r, $c['ndate'])), 'naissance_lieu' => clip($tc($lieu), 120),
          'civilite' => $civN($get($r, $c['civ']), $secu), 'siret' => preg_replace('/\D/', '', $get($r, $c['siret'])),
          'nom_naissance' => mb_strtoupper(clip($get($r, $c['nomn']), 80)), 'iban' => strtoupper(preg_replace('/[^0-9A-Z]/i', '', $get($r, $c['iban']))), 'bic' => strtoupper(preg_replace('/[^0-9A-Z]/i', '', $get($r, $c['bic'])))];
        if (strlen($F['iban']) < 14) $F['iban'] = ''; if (strlen($F['bic']) < 8) $F['bic'] = ''; if ($F['nom_naissance'] === mb_strtoupper($nom)) $F['nom_naissance'] = '';
        $id = 0;
        if ($tel !== '') { $byTel->execute([$tel]); $id = (int)$byTel->fetchColumn(); }
        if (!$id && $mail !== '') { $byMail->execute([$mail]); $id = (int)$byMail->fetchColumn(); }
        if (!$id) { $byNom->execute([$prenom, $nom]); $id = (int)$byNom->fetchColumn(); }
        if ($id) { $deja++;
          // Même portable ou e-mail mais un autre nom : on ne mélange pas les identités, la ligne est signalée.
          $ex = $pdo->prepare("SELECT nom, prenom FROM animatrices WHERE id = ?"); $ex->execute([$id]); $ex = $ex->fetch();
          if ($ex && $low($ex['nom']) !== $low($nom) && !str_contains($low($ex['nom']), $low($nom)) && !str_contains($low($nom), $low($ex['nom']))) { $conflits[] = $nom . ' ' . $prenom . ' (fichier) / ' . $ex['nom'] . ' ' . $ex['prenom'] . ' (même portable ou e-mail)'; continue; }
          $completer($id, $tel, $mail, $F); continue; }
        $ins->execute([$prenom, $nom, $tel, $mail, $z]); $n++; $completer((int)$pdo->lastInsertId(), '', '', $F);
      }
      if ($simu) $pdo->rollBack(); else { $pdo->commit(); matricules(); }
      out(['ok' => true, 'simule' => $simu, 'message' => ($simu ? 'Aperçu, rien n\'est encore enregistré : ' : '') . $n . ' personne' . ($n > 1 ? 's' : '') . ' ' . ($simu ? 'à ajouter' : 'ajoutée' . ($n > 1 ? 's' : '')) . ($deja ? ', ' . $deja . ' déjà dans la liste (' . $compl . ' fiche' . ($compl > 1 ? 's' : '') . ' ' . ($simu ? 'à compléter' : 'complétée' . ($compl > 1 ? 's' : '')) . ')' : '') . ($ign ? ', ' . $ign . ' ligne' . ($ign > 1 ? 's' : '') . ' ignorée' . ($ign > 1 ? 's' : '') : '') . '.' . ($conflits ? ' Non complétées car le nom ne correspond pas (à vérifier) : ' . implode(' ; ', array_slice($conflits, 0, 15)) . (count($conflits) > 15 ? '…' : '') . '.' : '') . ($champs ? ' Informations ' . ($simu ? 'à reporter' : 'reportées') . ' sur les fiches : ' . implode(', ', array_map(fn($k, $v) => ['adresse' => 'adresse', 'code_postal' => 'code postal', 'ville' => 'ville', 'nationalite' => 'nationalité', 'secu' => 'n° SS', 'naissance_date' => 'date de naissance', 'naissance_lieu' => 'lieu de naissance', 'civilite' => 'civilité', 'siret' => 'SIRET', 'nom_naissance' => 'nom de naissance', 'iban' => 'IBAN', 'bic' => 'BIC'][$k] . ' ' . $v, array_keys($champs), $champs)) . '.' : ($deja ? ' Aucune information nouvelle pour les fiches' . ($ecraser ? '' : ' (déjà remplies : cochez « Remplacer » pour les mettre à jour)') . '.' : '')) . (isset($lus) ? ' Colonnes lues : ' . implode(', ', $lus) . '.' . ($ign_c ? ' Non utilisée' . (count($ign_c) > 1 ? 's' : '') . ' : ' . implode(', ', $ign_c) . '.' : '') : ''), 'n' => $n, 'deja' => $deja, 'compl' => $compl]); // fin
      out(['ok' => true, 'message' => $n . ' personne' . ($n > 1 ? 's' : '') . ' ajoutée' . ($n > 1 ? 's' : '') . ($deja ? ', ' . $deja . ' déjà dans la liste' : '') . ($ign ? ', ' . $ign . ' ligne' . ($ign > 1 ? 's' : '') . ' ignorée' . ($ign > 1 ? 's' : '') . ' (prénom ou nom manquant)' : '') . '.']);

    case 'nettoyer_liste':
      // Garder seulement les personnes du bon fichier : retire celles qui n'y figurent pas, jamais inscrites dans l'appli et sans aucune activité
      // (compte rendu, planning, contrat, relevé, objectif). Les autres sont gardées et signalées. 1er envoi : aperçu, rien n'est retiré.
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403); @set_time_limit(180);
      $rows = [];
      if (!empty($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
        $fn = $_FILES['fichier']['tmp_name'];
        if (file_get_contents($fn, false, null, 0, 2) === 'PK') $rows = xlsx_rows($fn);
        else { $t = (string)file_get_contents($fn); $t = preg_replace('/^\xEF\xBB\xBF/', '', $t); if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
          foreach (preg_split('/\r\n|\r|\n/', $t) as $l) if (trim($l) !== '') $rows[] = str_getcsv($l, str_contains($l, "\t") ? "\t" : (str_contains($l, ';') ? ';' : ',')); }
      }
      if (!$rows) fail('Choisissez le fichier des bonnes personnes (Excel ou CSV).');
      $low = fn($x) => trim(preg_replace('/\s+/', ' ', strtr(mb_strtolower(trim((string)$x)), ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c', '-' => ' ', "'" => ' '])));
      $h = -1; $c = ['p' => -1, 'n' => -1, 't' => -1, 'm' => -1];
      foreach (array_slice($rows, 0, 8, true) as $i => $r) { $hh = array_map($low, $r); if (preg_grep('/^pre ?nom/', $hh) && preg_grep('/^nom$/', $hh)) { $h = $i; foreach ($hh as $k => $x) {
        if (preg_match('/^pre ?nom/', $x) && $c['p'] < 0) $c['p'] = $k; elseif ($x === 'nom' && $c['n'] < 0) $c['n'] = $k; elseif (preg_match('/tel|portable|mobile/', $x) && $c['t'] < 0) $c['t'] = $k; elseif (preg_match('/mail/', $x) && $c['m'] < 0) $c['m'] = $k; } break; } }
      if ($h < 0) fail('Ligne d\'en-tête introuvable : il faut au moins les colonnes « Nom » et « Prénom ».');
      $tels = []; $mails = []; $noms = []; $nb = 0;
      foreach (array_slice($rows, $h + 1) as $r) { $n = $low($r[$c['n']] ?? ''); $pr = $low($r[$c['p']] ?? ''); if ($n === '' && $pr === '') continue; $nb++;
        $noms[$n . '|' . $pr] = true; $t = tel_norm((string)($c['t'] >= 0 ? ($r[$c['t']] ?? '') : '')); if (strlen($t) >= 9) $tels[substr($t, -9)] = true;
        $m = $c['m'] >= 0 ? mb_strtolower(trim((string)($r[$c['m']] ?? ''))) : ''; if ($m !== '') $mails[$m] = true; }
      if ($nb < 10) fail('Le fichier ne contient que ' . $nb . ' personne(s) : vérifiez que c\'est le bon.');
      $actT = ['rapports', 'rapports_ba', 'missions', 'contrats', 'releves', 'contrats_dpae', 'objectifs_anim', 'objectifs_ba'];
      $tous = db()->query("SELECT DISTINCT table_name FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'animatrice_id'")->fetchAll(PDO::FETCH_COLUMN);
      $actT = array_values(array_intersect($actT, $tous));
      $retirer = []; $garde = []; require_once __DIR__ . '/gestion_lib.php';
      $admMails = array_flip(array_map('mb_strtolower', db()->query("SELECT email FROM admins")->fetchAll(PDO::FETCH_COLUMN)));
      foreach (db()->query("SELECT id, nom, prenom, telephone, email, zone, created_at FROM animatrices WHERE nom NOT LIKE '%(fusionné avec%' ORDER BY nom, prenom")->fetchAll() as $a) {
        $t = tel_norm((string)$a['telephone']);
        if ((strlen($t) >= 9 && isset($tels[substr($t, -9)])) || ($a['email'] !== '' && isset($mails[mb_strtolower($a['email'])])) || isset($noms[$low($a['nom']) . '|' . $low($a['prenom'])])) continue; // dans le fichier
        if (preg_match('/^(effac|anonym)/i', (string)$a['nom'])) continue;
        if (p_testeur((int)$a['id']) || ($a['email'] !== '' && isset($admMails[mb_strtolower($a['email'])]))) { $garde[] = mb_strtoupper($a['nom']) . ' ' . $a['prenom'] . ' (' . $a['zone'] . ') : équipe ' . AG_COURT; continue; }
        $act = 0; foreach ($actT as $tb) { $q = db()->prepare("SELECT COUNT(*) FROM `$tb` WHERE animatrice_id = ?"); $q->execute([$a['id']]); $act += (int)$q->fetchColumn(); }
        $nm = mb_strtoupper($a['nom']) . ' ' . $a['prenom'] . ' (' . $a['zone'] . ')';
        if ($a['created_at'] || $act) $garde[] = $nm . ($a['created_at'] ? ' : inscrite dans l\'appli' : ' : a de l\'activité'); else $retirer[] = ['id' => (int)$a['id'], 'nom' => $nm];
      }
      $simu = empty($_POST['confirmer']);
      if (!$simu && isset($_POST['ids'])) { $ok = array_flip(array_map('intval', (array)json_decode((string)$_POST['ids'], true))); $retirer = array_values(array_filter($retirer, fn($x) => isset($ok[$x['id']]))); } // décochées : gardées
      if (!$simu) { $pdo = db();
        foreach ($retirer as $x) { effacer_animatrice($x['id']); foreach ($tous as $tb) $pdo->prepare("DELETE FROM `$tb` WHERE animatrice_id = ?")->execute([$x['id']]); $pdo->prepare("DELETE FROM animatrices WHERE id = ?")->execute([$x['id']]); }
      }
      out(['ok' => true, 'simule' => $simu, 'fichier' => $nb, 'retirer' => $retirer, 'garde' => $garde]);

    case 'save_identite':
      // Correction du nom affiché (prénom d'usage, faute de frappe…), du portable, de l'e-mail ou de la zone.
      need_admin('tout'); $id = (int)($in['id'] ?? 0);
      $prenom = clip($in['prenom'] ?? '', 80); $nom = clip($in['nom'] ?? '', 80); if ($prenom === '' || $nom === '') fail('Prénom et nom obligatoires.');
      $prenom = mb_convert_case(mb_strtolower($prenom), MB_CASE_TITLE); $nom = mb_strtoupper($nom);
      $tel = tel_norm((string)($in['telephone'] ?? '')); if ($tel !== '' && strlen($tel) < 9) fail('Numéro de portable incomplet.');
      if ($tel !== '') { $st = db()->prepare("SELECT CONCAT(prenom, ' ', nom) FROM animatrices WHERE id <> ? AND telephone <> '' AND RIGHT(telephone, 9) = RIGHT(?, 9)"); $st->execute([$id, $tel]); if ($o = $st->fetchColumn()) fail('Ce portable est déjà celui de ' . $o . '.'); }
      $email = mb_strtolower(trim((string)($in['email'] ?? ''))); if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Adresse e-mail invalide.');
      $zone = isset(ZONES[$in['zone'] ?? '']) ? $in['zone'] : fail('Zone invalide.');
      db()->prepare("UPDATE animatrices SET prenom = ?, nom = ?, telephone = ?, email = ?, zone = ? WHERE id = ?")->execute([$prenom, $nom, $tel, $email, $zone, $id]);
      out(['ok' => true]);

    case 'fusionner':
      need_admin('tout'); $n = fusionner((int)($in['garder'] ?? 0), array_map('intval', (array)($in['absorber'] ?? []))); out(['ok' => true, 'n' => $n]);

    case 'statut_animatrice':
      // Actif : tout. Sans missions groupées : contrats, planning… mais pas les offres envoyées à plusieurs. Stand-by : ni planning ni offres. Refusé(e) : plus d'accès à l'appli.
      need_admin('tout'); $s = (string)($in['statut'] ?? ''); if (!isset(STATUTS_ANIM[$s])) fail('Statut inconnu.');
      db()->prepare("UPDATE animatrices SET statut = ?, actif = ? WHERE id = ?")->execute([$s, $s === 'refuse' ? 0 : 1, (int)($in['id'] ?? 0)]);
      if ($s === 'refuse') anim_deconnecter((int)($in['id'] ?? 0)); matricules(); out(['ok' => true]);

    case 'exp_personne':
      // Droit d'accès / portabilité : toutes les données d'une personne, documents déchiffrés. Dirigeants seulement.
      need_admin('tout'); if (!est_titulaire()) fail('Réservé à la direction.', 403);
      require_once __DIR__ . '/export_lib.php'; @set_time_limit(300); [$f, $tel] = exp_personne((int)($_GET['id'] ?? 0)); exp_remettre(basename($f), $tel);

    case 'anim_deconnecter':
      // Téléphone perdu, départ, doute : tous les appareils de l'intervenant(e) sont déconnectés ; reconnexion possible par code e-mail si son accès reste actif.
      need_admin('tout'); $id = (int)($in['id'] ?? 0); if (!$id) fail('Intervenant(e) introuvable.');
      anim_deconnecter($id); doc_journal('deconnexion:' . $id, admin_nom()); out(['ok' => true]);

    case 'toggle_animatrice':
      need_admin('tout');
      db()->prepare("UPDATE animatrices SET actif = 1 - actif, statut = IF(actif = 1, 'actif', 'refuse') WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
      $st = db()->prepare("SELECT actif FROM animatrices WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); if (!(int)$st->fetchColumn()) anim_deconnecter((int)($in['id'] ?? 0));
      matricules(); out(['ok' => true]);

    case 'save_groupe':
      need_admin('tout');
      $nom = clip($in['nom'] ?? '', 80); if ($nom === '') fail('Donnez un nom au groupe.');
      $ids = array_values(array_unique(array_map('intval', (array)($in['marques'] ?? [])))); if (count($ids) < 2) fail('Choisissez au moins deux marques.');
      if (empty($in['id'])) { $ex = db()->prepare("SELECT id FROM groupes WHERE nom = ?"); $ex->execute([$nom]); $in['id'] = (int)$ex->fetchColumn(); }
      if (!empty($in['id'])) db()->prepare("UPDATE groupes SET nom = ?, marques = ? WHERE id = ?")->execute([$nom, implode(',', $ids), (int)$in['id']]);
      else db()->prepare("INSERT INTO groupes (nom, marques) VALUES (?, ?)")->execute([$nom, implode(',', $ids)]);
      out(['ok' => true]);

    case 'delete_groupe':
      need_admin('tout'); db()->prepare("DELETE FROM groupes WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'save_code':
      need_admin('tout');
      $c = strtoupper(preg_replace('/\s+/', '', (string)($in['code'] ?? '')));
      if (!preg_match('/^[A-Z0-9]{4,20}$/', $c)) fail('Le code doit faire 4 à 20 lettres ou chiffres, sans espace.');
      db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('code_agence', ?)")->execute([$c]); out(['ok' => true, 'code' => $c]);

    case 'save_marque':
      need_admin('tout');
      $nom = clip($in['nom'] ?? '', 80); if ($nom === '') fail('Nom de la marque obligatoire.');
      $detail = clip($in['detail'] ?? '', 200);
      $sous = implode(',', array_values(array_filter(array_map(fn($x) => clip($x, 40), explode(',', (string)($in['sous_marques'] ?? '')))))); if (substr_count($sous, ',') === 0) $sous = '';
      $logo = null;
      if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $info = @getimagesize($_FILES['logo']['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) fail('Logo : PNG, JPEG ou WebP uniquement.');
        $src = match ($info[2]) { IMAGETYPE_JPEG => imagecreatefromjpeg($_FILES['logo']['tmp_name']), IMAGETYPE_PNG => imagecreatefrompng($_FILES['logo']['tmp_name']), IMAGETYPE_WEBP => imagecreatefromwebp($_FILES['logo']['tmp_name']) };
        $r = min(1, 600 / max(imagesx($src), imagesy($src)));
        $dst = imagecreatetruecolor((int)round(imagesx($src) * $r), (int)round(imagesy($src) * $r));
        imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, imagesx($dst), imagesy($dst), imagesx($src), imagesy($src));
        $logo = 'logo_' . bin2hex(random_bytes(8)) . '.png'; imagepng($dst, upload_dir() . '/' . $logo);
      }
      if (!empty($in['id'])) {
        db()->prepare("UPDATE marques SET nom = ?, detail = ?, sous_marques = ?, actif = ?" . ($logo ? ", logo = ?" : "") . " WHERE id = ?")
          ->execute(array_merge([$nom, $detail, $sous, empty($in['actif']) ? 0 : 1], $logo ? [$logo] : [], [(int)$in['id']]));
      } else {
        db()->prepare("INSERT INTO marques (nom, logo, detail, sous_marques, ordre) VALUES (?, ?, ?, ?, 50)")->execute([$nom, $logo ?? '', $detail, $sous]);
      }
      out(['ok' => true]);

    case 'save_magasin':
      need_admin('tout');
      $zone = (string)($in['zone'] ?? ''); $nom = clip($in['nom'] ?? '', 120); $ville = clip($in['ville'] ?? '', 80);
      if (!isset(ZONES[$zone]) || $nom === '' || $ville === '') fail('Zone, nom et ville obligatoires.');
      $ens = clip($in['enseigne'] ?? '', 60) ?: explode(' ', $nom)[0];
      $circ = ($in['circuit'] ?? '') === 'tr' ? 'tr' : '';
      $adr = clip($in['adresse'] ?? '', 255); geo_schema();
      // Plus Code, coordonnées ou lien Google Maps tapés dans le champ Adresse : position précise, et adresse retrouvée si possible.
      $pos = geo_position_texte($adr, $zone, $ville, true);
      if ($pos) { $adr = $pos['adresse'] !== '' ? clip($pos['adresse'], 255) : $adr; if ($pos['ville'] !== '') $ville = clip($pos['ville'], 80); }
      if (!empty($in['id'])) {
        $o = db()->prepare("SELECT adresse, ville FROM magasins WHERE id = ?"); $o->execute([(int)$in['id']]); $o = $o->fetch() ?: [];
        $regeo = ($o['adresse'] ?? '') !== $adr || ($o['ville'] ?? '') !== $ville; // adresse changée : on relocalise
        db()->prepare("UPDATE magasins SET zone = ?, enseigne = ?, nom = ?, ville = ?, adresse = ?, actif = ?, circuit = ?" . ($regeo ? ", lat = NULL, lng = NULL" : "") . " WHERE id = ?")->execute([$zone, $ens, $nom, $ville, $adr, empty($in['actif']) ? 0 : 1, $circ, (int)$in['id']]);
        $mid = (int)$in['id'];
      } else { db()->prepare("INSERT INTO magasins (zone, enseigne, nom, ville, adresse, circuit) VALUES (?,?,?,?,?,?)")->execute([$zone, $ens, $nom, $ville, $adr, $circ]); $mid = (int)db()->lastInsertId(); }
      if ($pos) db()->prepare("UPDATE magasins SET lat = ?, lng = ?, geo_approx = 0 WHERE id = ?")->execute([$pos['lat'], $pos['lng'], $mid]);
      dep_schema(); $dep = strtoupper(trim((string)($in['dep'] ?? ''))); if (!isset(DEPARTEMENTS[$dep])) $dep = dep_cp($adr . ' ' . $ville);
      if ($dep !== '') db()->prepare("UPDATE magasins SET dep = ? WHERE id = ?")->execute([$dep, $mid]);
      out(['ok' => true, 'id' => $mid, 'position' => (bool)$pos, 'adresse' => $adr]);

    case 'concurrents_save':
      need_admin('tout'); concurrents(); db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES ('concurrents', ?)")->execute([mb_substr((string)($in['liste'] ?? ''), 0, 4000)]); out(['ok' => true]);
    case 'secteurs_save':
      // Secteurs : groupes de départements propres à l'agence (filtres des comptes rendus et des rapports).
      need_admin('tout'); secteurs(); $o = [];
      foreach ((array)($in['secteurs'] ?? []) as $x) { $n = clip((string)($x['nom'] ?? ''), 60); $d = array_values(array_unique(array_filter(array_map(fn($k) => strtoupper(trim((string)$k)), (array)($x['deps'] ?? [])), fn($k) => isset(DEPARTEMENTS[$k]))));
        if ($n !== '' && $d) $o[] = ['nom' => $n, 'deps' => $d]; }
      db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES ('secteurs', ?)")->execute([json_encode($o, JSON_UNESCAPED_UNICODE)]); out(['ok' => true, 'secteurs' => $o]);
    case 'magasin_dep':
      need_admin('tout'); dep_schema(); $d = strtoupper(trim((string)($in['dep'] ?? ''))); if ($d !== '' && !isset(DEPARTEMENTS[$d])) fail('Département inconnu (ex. 75, 2A).');
      db()->prepare("UPDATE magasins SET dep = ? WHERE id = ?")->execute([$d, (int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'magasin_zti':
      // Zone touristique internationale : soirées de 21 h à minuit payées double, repos équivalent, retour à domicile pris en charge.
      need_admin('tout'); require_once __DIR__ . '/regles_lib.php'; rg_schema();
      db()->prepare("UPDATE magasins SET zti = ? WHERE id = ?")->execute([empty($in['zti']) ? 0 : 1, (int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'magasin_suggest':
      // Adresse exacte d'un point de vente : Base adresse nationale (par l'adresse), Geoapify ou OpenStreetMap (par le nom du magasin).
      need_admin('tout'); require_once __DIR__ . '/geo_lib.php'; $GLOBALS['GEO_MAX'] = 10;
      $nom = clip($in['nom'] ?? '', 160); $adr = clip($in['adresse'] ?? '', 255); $ville = clip($in['ville'] ?? '', 80); $zone = (string)($in['zone'] ?? '');
      $S = []; $vu = [];
      $add = function ($src, $label, $adresse, $cp, $vi, $lat, $lng, $score) use (&$S, &$vu) { $k = mb_strtolower($adresse . '|' . $vi); if ($adresse === '' || isset($vu[$k])) return; $vu[$k] = 1;
        $S[] = ['source' => $src, 'label' => $label, 'adresse' => $adresse, 'cp' => $cp, 'ville' => $vi, 'lat' => $lat, 'lng' => $lng, 'score' => $score]; };
      $codes = [];
      if (trim($adr . ' ' . $ville) !== '') { $j = geo_http('https://api-adresse.data.gouv.fr/search/?limit=5&q=' . rawurlencode(trim($adr . ' ' . $ville)));
        foreach ((array)($j['features'] ?? []) as $f) { $p = $f['properties'] ?? []; if (isset($codes[$zone]) && !str_starts_with((string)($p['postcode'] ?? ''), $codes[$zone])) continue;
          $add('Base adresse nationale', (string)($p['label'] ?? ''), (string)($p['name'] ?? ''), (string)($p['postcode'] ?? ''), (string)($p['city'] ?? ''), $f['geometry']['coordinates'][1] ?? null, $f['geometry']['coordinates'][0] ?? null, round((float)($p['score'] ?? 0), 2)); } }
      // Geoapify (sous contrat, si GEO_CLE est réglée) : commerces et adresses par leur nom. Les serveurs publics d'OpenStreetMap ne servent qu'en secours.
      $geo = false;
      if ($nom !== '' && geo_cle() !== '') { $j = geo_geoapify('/v1/geocode/search', ['text' => trim($nom . ' ' . $ville), 'lang' => 'fr', 'limit' => 6, 'format' => 'json']);
        foreach ((array)($j['results'] ?? []) as $f) { $rue = trim(($f['housenumber'] ?? '') . ' ' . ($f['street'] ?? '')); $geo = true;
          $add('Geoapify', (string)($f['formatted'] ?? ''), $rue !== '' ? $rue : (string)($f['address_line1'] ?? ''), (string)($f['postcode'] ?? ''), (string)($f['city'] ?? ''), isset($f['lat']) ? (float)$f['lat'] : null, isset($f['lon']) ? (float)$f['lon'] : null, isset($f['rank']['confidence']) ? round((float)$f['rank']['confidence'], 2) : null); } }
      if ($nom !== '' && !$geo) { $cc = 'fr';
        $j = geo_http('https://nominatim.openstreetmap.org/search?format=jsonv2&addressdetails=1&limit=5&countrycodes=' . $cc . '&q=' . rawurlencode(trim($nom . ' ' . $ville)));
        foreach ((array)$j as $f) { $a = $f['address'] ?? []; $rue = trim(($a['house_number'] ?? '') . ' ' . ($a['road'] ?? ($a['pedestrian'] ?? ($a['square'] ?? ''))));
          $vi = (string)($a['city'] ?? ($a['town'] ?? ($a['village'] ?? ($a['municipality'] ?? ''))));
          $add('OpenStreetMap', (string)($f['display_name'] ?? ''), $rue, (string)($a['postcode'] ?? ''), $vi, (float)$f['lat'], (float)$f['lon'], null); } }
      // Photon (OpenStreetMap, recherche de commerces par leur nom), centré sur la zone.
      if ($nom !== '' && !$geo) { $ctr = [46.6, 2.4];
        $j = geo_http('https://photon.komoot.io/api/?limit=6&lang=fr&lat=' . $ctr[0] . '&lon=' . $ctr[1] . '&q=' . rawurlencode(trim($nom . ' ' . $ville)));
        foreach ((array)($j['features'] ?? []) as $f) { $p = $f['properties'] ?? []; $c = $f['geometry']['coordinates'] ?? [null, null];
          if ($zone !== 'France' && $c[1] !== null && geo_vol([(float)$c[1], (float)$c[0]], $ctr) > 120) continue;
          $rue = trim(($p['housenumber'] ?? '') . ' ' . ($p['street'] ?? ''));
          $add('OpenStreetMap (Photon)', trim(($p['name'] ?? '') . ', ' . $rue . ', ' . ($p['postcode'] ?? '') . ' ' . ($p['city'] ?? ''), ', '), $rue !== '' ? $rue : (string)($p['name'] ?? ''), (string)($p['postcode'] ?? ''), (string)($p['city'] ?? ''), $c[1], $c[0], null); } }
      out(['suggestions' => array_slice($S, 0, 10), 'geoapify' => $geo, 'google' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim($nom . ' ' . $ville))]);

    case 'magasin_coller':
      // Position collée depuis Google Maps (coordonnées, lien, Plus Code) : enregistrée comme précise, l'adresse est retrouvée par la Base adresse nationale.
      need_admin('tout'); require_once __DIR__ . '/geo_lib.php'; geo_schema(); $id = (int)($in['id'] ?? 0);
      $st = db()->prepare("SELECT zone, ville FROM magasins WHERE id = ?"); $st->execute([$id]); $mg = $st->fetch() ?: fail('Point de vente introuvable.');
      $P = geo_position_texte((string)($in['texte'] ?? ''), (string)$mg['zone'], (string)$mg['ville']);
      if (!$P) fail('Position non reconnue. Collez les coordonnées (clic droit sur le magasin dans Google Maps, puis clic sur les chiffres), le lien Google Maps, ou le Plus Code (ex. PGG4+FP Le Tampon).');
      db()->prepare("UPDATE magasins SET lat = ?, lng = ?, geo_approx = 0" . ($P['adresse'] !== '' ? ", adresse = ?" : "") . ($P['ville'] !== '' ? ", ville = ?" : "") . " WHERE id = ?")
        ->execute(array_merge([$P['lat'], $P['lng']], $P['adresse'] !== '' ? [$P['adresse']] : [], $P['ville'] !== '' ? [$P['ville']] : [], [$id]));
      out(['ok' => true] + $P);

    case 'magasin_position':
      // Position choisie parmi les propositions : enregistrée comme précise.
      need_admin('tout'); require_once __DIR__ . '/geo_lib.php'; geo_schema(); $lat = (float)($in['lat'] ?? 0); $lng = (float)($in['lng'] ?? 0);
      if (!$lat || !$lng || abs($lat) > 90 || abs($lng) > 180) fail('Position invalide.');
      db()->prepare("UPDATE magasins SET lat = ?, lng = ?, geo_approx = 0 WHERE id = ?")->execute([$lat, $lng, (int)($in['id'] ?? 0)]); out(['ok' => true]);

    case 'send':
      need_admin('envoi');
      require __DIR__ . '/report_lib.php';
      $p = report_params($in);
      $dest = emails_list($in['dest'] ?? MAIL_TO_DEFAULT); if (!$dest) fail('Indiquez au moins un destinataire.');
      $res = send_report($p, implode(', ', $dest), !empty($in['analyse']), !empty($in['ia']), mb_substr(trim((string)($in['message'] ?? '')), 0, 4000), !empty($in['joindre_photos']), !isset($in['joindre_pdf']) || !empty($in['joindre_pdf']), !isset($in['joindre_xlsx']) || !empty($in['joindre_xlsx']));
      out($res);

    case 'reco_auto':
      need_admin(); require __DIR__ . '/report_lib.php'; out(['reco' => reco_auto(collect(report_params($_GET)))]);

    case 'report_photos':
      need_admin();
      require __DIR__ . '/report_lib.php';
      $q = $_GET; unset($q['sel'], $q['photos']); // garde la sélection de comptes rendus (rsel / rids)
      $list = report_photo_list(collect(report_params($q)));
      out(['photos' => array_map(fn($x) => ['id' => $x['id'], 'url' => photo_url($x['fichier'], true), 'full' => photo_url($x['fichier']),
        'type' => $x['type'], 'date' => $x['r']['date_animation'], 'magasin' => $x['r']['magasin'], 'animatrice' => $x['r']['animatrice'],
        'poids' => (int)@filesize(upload_dir() . '/' . $x['fichier'] . '.jpg')], $list)]);

    case 'mail_message':
      need_admin();
      require __DIR__ . '/report_lib.php';
      out(['message' => mail_message_default(collect(report_params($_GET)))]);

    case 'mes_missions':
      // Planning de l'intervenant(e) : animations attribuées par l'agence (depuis 7 jours).
      require_once __DIR__ . '/gestion_lib.php'; $aid = need_anim();
      // Phase de test : rien n'apparaît aux intervenants tant que le planning n'est pas ouvert (visible en aperçu seulement).
      if (!p_visible($aid)) out(['missions' => [], 'visible' => false]);
      require_once __DIR__ . '/contrats_lib.php';
      $raw = p_missions($aid, date('Y-m-01', strtotime('first day of -3 months')));
      $M = array_map(fn($m) => array_intersect_key($m, array_flip(['id', 'date', 'debut', 'fin', 'pause', 'zone', 'magasin', 'poste', 'client', 'notes'])), $raw);
      // Trajet aller-retour (domicile, ou adresse de départ propre à l'opération) ↔ point de vente et indemnité kilométrique indicative.
      $T = []; $parId = []; $kmMan = []; $manId = []; $tcJ = []; $tcId = [];
      try {
        $G = ['' => ['dep' => null, 'mags' => [], 'ids' => []]]; $deps = [];
        foreach ($raw as $m) { if ($m['date'] < date('Y-m-d', strtotime('-7 days'))) continue; $dv = (int)$m['devis_id'];
          if ($dv && !array_key_exists($dv, $deps)) { $bo = c_brouillon($aid, $dv); $deps[$dv] = c_depart_ok($bo['depart'] ?? null); $kmMan[$dv] = (array)($bo['km_man'] ?? []); $tcJ[$dv] = ($bo['transport'] ?? '') === 'tc' ? round((float)($bo['tc_jour'] ?? 0), 2) : null; }
          if ($dv && $tcJ[$dv] !== null) $tcId[$m['id']] = $tcJ[$dv];
          if ($dv && isset($kmMan[$dv][$m['magasin']])) $manId[$m['id']] = (float)$kmMan[$dv][$m['magasin']];
          $dp = $dv ? $deps[$dv] : null; $k = $dp ? json_encode($dp) : ''; $G[$k] ??= ['dep' => $dp, 'mags' => [], 'ids' => []]; $G[$k]['mags'][] = $m['magasin']; $G[$k]['ids'][$m['id']] = $m['magasin']; }
        foreach ($G as $k => $g) { if (!$g['mags'] && $k !== '') continue; $t = geo_trajets($aid, $g['mags'], $g['dep']);
          if ($k === '') $T = $t; else foreach ($g['ids'] as $id => $mg) if (isset($t[$mg])) $parId[$id] = $t[$mg] + ['depart' => c_adr($g['dep'])]; }
      } catch (Throwable $e) { error_log('Trajets : ' . $e->getMessage()); }
      foreach ($M as &$m) { $m['trajet'] = $parId[$m['id']] ?? $T[$m['magasin']] ?? null;
        if (isset($manId[$m['id']]) && $m['trajet']) { $m['trajet']['km_ar'] = $manId[$m['id']]; $m['trajet']['montant'] = round($manId[$m['id']] * (float)$m['trajet']['taux'], 2); $m['trajet']['approx'] = false; $m['trajet']['sans_adresse'] = false; }
        if (isset($tcId[$m['id']])) { $m['trajet'] = ['mode' => 'tc', 'montant' => $tcId[$m['id']], 'dest' => $m['trajet']['dest'] ?? $m['magasin'], 'depart' => $m['trajet']['depart'] ?? null]; } } unset($m); // km relevés sur Mappy, ou transports en commun
      // Prise de poste : ouverte le jour même (heure du point de vente), déjà faite ou non.
      try { require_once __DIR__ . '/poste_lib.php'; $ids = []; foreach ($raw as $m) if ($m['date'] >= date('Y-m-d', strtotime('-1 day')) && $m['date'] <= date('Y-m-d', strtotime('+1 day'))) $ids[] = (int)$m['id'];
        $PP = pp_lignes($ids); $byId = []; foreach ($raw as $m) $byId[(int)$m['id']] = $m;
        foreach ($M as &$m) { if (!in_array((int)$m['id'], $ids, true)) continue; $r = $byId[(int)$m['id']] + ['date_mission' => $m['date']]; $p = $PP[(int)$m['id']][$aid] ?? null;
          $m['poste_pris'] = $p && $p['le'] ? ['heure' => $p['heure'], 'ecart' => (int)$p['ecart_min']] : null; $m['poste_ouvert'] = !$m['poste_pris'] && pp_ouvert($r); } unset($m);
      } catch (Throwable $e) { error_log('Prise de poste : ' . $e->getMessage()); }
      $manque = []; try { $manque = c_manque(c_salarie($aid)); } catch (Throwable $e) {}
      out(['visible' => true, 'missions' => $M, 'manque' => $manque]);

    case 'prise_poste':
      require_once __DIR__ . '/poste_lib.php'; $aid = need_anim(); if (apercu()) fail('Aperçu : seule la personne peut prendre son poste depuis son téléphone.');
      limite('poste', 30, 3600);
      $num = fn($k) => isset($in[$k]) && is_numeric($in[$k]) ? (float)$in[$k] : null;
      try { $e = pp_prendre($aid, (int)($in['mission_id'] ?? 0), $num('lat'), $num('lng'), $num('acc') === null ? null : (int)$num('acc')); } catch (RuntimeException $x) { fail($x->getMessage()); }
      out(['ok' => true] + $e);

    case 'mes_offres':
      // Missions proposées encore ouvertes, avec ma réponse.
      require_once __DIR__ . '/offres_lib.php'; $aid = need_anim(); o_schema();
      if (!p_visible($aid)) out(['offres' => [], 'visible' => false]);
      $st = db()->prepare("SELECT o.id, o.devis_id, o.slots, o.message, o.created_at, od.reponse, od.dates, od.retenu FROM offres o JOIN offres_dest od ON od.offre_id = o.id WHERE od.animatrice_id = ? AND o.statut = 'ouvert' ORDER BY o.id DESC LIMIT 30");
      $st->execute([$aid]); $out = [];
      foreach ($st->fetchAll() as $o) { $d = g_doc((int)$o['devis_id']); $sl = json_decode($o['slots'], true) ?: []; if (!$sl || end($sl)['date'] < date('Y-m-d')) continue;
        $out[] = ['id' => (int)$o['id'], 'client' => $d['client']['raison'] ?? '', 'objet' => $d['objet'] ?? '', 'message' => $o['message'], 'slots' => array_map(fn($x) => array_intersect_key($x, array_flip(['key', 'date', 'magasin', 'debut', 'fin', 'poste'])), $sl),
          'reponse' => $o['reponse'], 'dates' => json_decode((string)$o['dates'], true) ?: [], 'retenu' => (int)$o['retenu'], 'occupe' => o_occupe($aid, $sl)]; }
      out(['visible' => true, 'offres' => $out]);

    case 'offre_repondre':
      require_once __DIR__ . '/offres_lib.php'; $aid = need_anim(); o_schema();
      $o = o_get((int)($in['offre_id'] ?? 0)); if (!$o || $o['statut'] !== 'ouvert') fail('Cette offre est close.');
      $r = ($in['reponse'] ?? '') === 'oui' ? 'oui' : 'non'; $dates = $r === 'oui' ? array_values(array_intersect(array_map('strval', (array)($in['dates'] ?? [])), array_column($o['slots'], 'key'))) : [];
      $dates = array_values(array_diff($dates, o_occupe($aid, $o['slots']))); // pas deux missions le même jour
      $jour = []; foreach ($o['slots'] as $x) if (in_array($x['key'], $dates, true) && !isset($jour[$x['date']])) $jour[$x['date']] = $x['key']; $dates = array_values($jour); // un seul magasin par jour
      if ($r === 'oui' && !$dates) fail('Cochez au moins une date où vous êtes libre.');
      $u = db()->prepare("UPDATE offres_dest SET reponse = ?, dates = ?, repondu_le = ? WHERE offre_id = ? AND animatrice_id = ?"); $u->execute([$r, $dates ? json_encode($dates) : null, now(), $o['id'], $aid]);
      if (!$u->rowCount()) fail('Cette offre ne vous était pas adressée.');
      out(['ok' => true]);

    case 'mes_releves':
      require_once __DIR__ . '/releves_lib.php'; $aid = need_anim(); r_schema();
      if (!p_visible($aid)) out(['releves' => [], 'visible' => false]);
      $st = db()->prepare("SELECT mois FROM releves WHERE animatrice_id = ? AND statut IN ('envoye','signe') ORDER BY mois DESC LIMIT 24"); $st->execute([$aid]); $out = [];
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $m) { $R = r_get($aid, $m); unset($R['signature'], $R['journal'], $R['signe_ip'], $R['change']); $out[] = $R; }
      out(['visible' => true, 'releves' => $out]);

    case 'releve_signer':
      // Signature interne : nom saisi, case de certification, tracé au doigt ; horodatage, adresse IP et empreinte du contenu.
      require_once __DIR__ . '/releves_lib.php'; $aid = need_anim(); $mois = r_mois_ok((string)($in['mois'] ?? ''));
      if (!p_visible($aid)) fail('Relevé indisponible.');
      $row = r_row($aid, $mois); if (!$row || $row['statut'] !== 'envoye') fail('Ce relevé n\'est pas à signer.');
      if (empty($in['certifie'])) fail('Cochez la case pour certifier l\'exactitude des heures.');
      $nom = clip(trim((string)($in['nom'] ?? '')), 160); if (mb_strlen($nom) < 3) fail('Écrivez votre prénom et votre nom.');
      $sig = (string)($in['signature'] ?? ''); if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 400000 || strlen($sig) < 800) fail('Signez dans le cadre avec le doigt.');
      $R = r_get($aid, $mois); $le = now(); $emp = r_empreinte($R, $nom, $le);
      db()->prepare("UPDATE releves SET statut = 'signe', signe_le = ?, signe_nom = ?, signe_ip = ?, signe_ua = ?, signature = ?, signe_test = ?, empreinte = ?, modifie_le = ? WHERE id = ? AND statut = 'envoye'")
        ->execute([$le, $nom, clip($_SERVER['REMOTE_ADDR'] ?? '', 60), clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255), $sig, (apercu() || !p_ouvert()) ? 1 : 0, $emp, $le, $row['id']]);
      r_journal((int)$row['id'], 'Signé par ' . $nom . (apercu() ? ' (test en aperçu par ' . admin_nom() . ')' : '') . ' depuis ' . clip($_SERVER['REMOTE_ADDR'] ?? '', 60));
      // Copie signée à l'agence.
      require_once __DIR__ . '/report_lib.php'; $R = r_get($aid, $mois);
      $to = apercu() ? (string)($_SESSION['admin_email'] ?? '') : implode(',', emails_list(g_reg()['notif_emails']));
      if ($to) mail_send($to, (apercu() ? '[Test] ' : '') . 'Relevé d\'heures signé : ' . $R['animatrice']['prenom'] . ' ' . $R['animatrice']['nom'] . ', ' . $R['mois_txt'],
        '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px">Relevé d\'heures de <b>' . h($R['mois_txt']) . '</b> signé par <b>' . h($nom) . '</b> le ' . date('d/m/Y à H:i', strtotime($le)) . ' : ' . g_dur($R['minutes']) . '. Le PDF signé est joint.</div>', [[r_fichier($R), r_pdf($R)]]);
      out(['ok' => true]);

    case 'mes_contrats':
      require_once __DIR__ . '/contrats_lib.php'; $aid = need_anim(); c_schema();
      if (!p_visible($aid)) out(['contrats' => [], 'visible' => false]);
      $st = db()->prepare("SELECT id FROM contrats WHERE animatrice_id = ? ORDER BY envoye_le DESC LIMIT 60"); $st->execute([$aid]); $out = [];
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $c = c_row((int)$id); $L = $c['lignes'];
        $out[] = ['id' => (int)$c['id'], 'titre' => c_titre($c), 'avenant' => (int)$c['avenant'], 'statut' => $c['statut'], 'client' => $c['params']['client'] ?? '', 'operation' => $c['params']['operation'] ?? '',
          'du' => $L ? $L[0]['date'] : null, 'au' => $L ? end($L)['date'] : null, 'lignes' => $L, 'taux' => $c['params']['taux'] ?? 0, 'envoye_le' => $c['envoye_le'], 'signe_le' => $c['signe_le'], 'signe_nom' => $c['signe_nom'], 'signe_test' => (int)$c['signe_test'],
          'nom' => trim(($c['salarie']['prenom'] ?? '') . ' ' . ($c['salarie']['nom'] ?? ''))]; }
      out(['visible' => true, 'contrats' => $out]);

    case 'contrat_signer':
      require_once __DIR__ . '/contrats_lib.php'; $aid = need_anim(); if (!p_visible($aid)) fail('Contrat indisponible.');
      $c = c_row((int)($in['id'] ?? 0)); if (!$c || (int)$c['animatrice_id'] !== $aid || $c['statut'] !== 'envoye') fail('Ce document n\'est pas à signer.');
      // Une version plus récente existe (contrat mis à jour) : signer la dernière.
      if (empty($in['certifie'])) fail('Cochez la case : j\'ai lu le document et j\'en accepte les termes.');
      $mention = trim((string)($in['mention'] ?? '')); if (mb_strtolower(preg_replace('/\s+/', ' ', $mention)) !== 'lu et approuvé') fail('Écrivez « lu et approuvé ».');
      $nom = clip(trim((string)($in['nom'] ?? '')), 160); if (mb_strlen($nom) < 3) fail('Écrivez votre prénom et votre nom.');
      $sig = (string)($in['signature'] ?? ''); if (!preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 400000 || strlen($sig) < 800) fail('Signez dans le cadre avec le doigt.');
      $le = now(); $emp = c_empreinte($c, $nom, $le);
      $vf = db()->prepare("SELECT le, email FROM verifications WHERE animatrice_id = ? ORDER BY id DESC LIMIT 1"); $vf->execute([$aid]); $vf = $vf->fetch() ?: [];
      db()->prepare("UPDATE contrats SET preuve = ? WHERE id = ?")->execute([json_encode(['verif_le' => $vf['le'] ?? null, 'verif_email' => $vf['email'] ?? '', 'certifie' => true], JSON_UNESCAPED_UNICODE), $c['id']]);
      db()->prepare("UPDATE contrats SET statut = 'signe', signe_le = ?, signe_nom = ?, signe_mention = 'Lu et approuvé', signe_ip = ?, signe_ua = ?, signature = ?, signe_test = ?, empreinte = ? WHERE id = ? AND statut = 'envoye'")
        ->execute([$le, $nom, clip($_SERVER['REMOTE_ADDR'] ?? '', 60), clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255), $sig, (apercu() || !p_ouvert()) ? 1 : 0, $emp, $c['id']]);
      c_journal((int)$c['id'], 'Signé par ' . $nom . (apercu() ? ' (test en aperçu par ' . admin_nom() . ')' : '') . ' depuis ' . clip($_SERVER['REMOTE_ADDR'] ?? '', 60));
      require_once __DIR__ . '/report_lib.php'; $c = c_row((int)$c['id']);
      try { c_figer($c); } catch (Throwable $e) { error_log('PDF figé : ' . $e->getMessage()); } // PDF signé archivé une fois pour toutes
      $to = apercu() ? (string)($_SESSION['admin_email'] ?? '') : implode(',', emails_list(g_reg()['notif_emails']));
      if ($to) mail_send($to, (apercu() ? '[Test] ' : '') . c_titre($c) . ' signé : ' . $nom, '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px">' . h(c_titre($c)) . ' (' . h($c['params']['operation'] ?? '') . ') signé par <b>' . h($nom) . '</b> le ' . date('d/m/Y à H:i', strtotime($le)) . '. Le PDF signé est joint.</div>', [[c_fichier($c), c_pdf_final($c)]]);
      out(['ok' => true]);

    case 'contrat_pdf_mien':
      require_once __DIR__ . '/contrats_lib.php'; $aid = need_anim(); $c = c_row((int)($_GET['id'] ?? 0));
      if (!$c || (int)$c['animatrice_id'] !== $aid || !p_visible($aid)) fail('Contrat indisponible.', 404);
      header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . c_fichier($c) . '"'); echo c_pdf_final($c); exit;

    case 'releve_pdf_mien':
      require_once __DIR__ . '/releves_lib.php'; $aid = need_anim(); $mois = r_mois_ok((string)($_GET['mois'] ?? ''));
      $row = r_row($aid, $mois); if (!$row || $row['statut'] === 'brouillon' || (!p_visible($aid))) fail('Relevé indisponible.', 404);
      $R = r_get($aid, $mois); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . r_fichier($R) . '"'); echo r_pdf($R); exit;

    // ---------- Photothèque ----------
    case 'phototheque':
      need_admin(); out(phototheque($_GET) + ['stockage' => stockage()]);

    case 'phototheque_delete':
      // Suppression définitive des photos choisies (fichier, miniature et fiche).
      need_admin('rapports');
      $ids = array_values(array_filter(array_map('intval', (array)($in['ids'] ?? [])))); if (!$ids) fail('Aucune photo choisie.');
      if (count($ids) > 1000) fail('1 000 photos au plus à la fois.');
      $st = db()->query("SELECT id, fichier FROM photos WHERE id IN (" . implode(',', $ids) . ")"); $n = 0;
      foreach ($st->fetchAll() as $p) { @unlink(upload_dir() . '/' . $p['fichier'] . '.jpg'); @unlink(upload_dir() . '/' . $p['fichier'] . '_t.jpg'); $n++; }
      db()->exec("DELETE FROM photos WHERE id IN (" . implode(',', $ids) . ")");
      out(['ok' => true, 'n' => $n]);

    case 'phototheque_zip':
      need_admin(); require_once __DIR__ . '/report_lib.php'; @set_time_limit(300);
      $all = phototheque($_GET, 5000)['photos'];
      if (!empty($_GET['ids'])) { $keep = array_flip(array_map('intval', explode(',', (string)$_GET['ids']))); $all = array_values(array_filter($all, fn($p) => isset($keep[(int)$p['id']]))); }
      if (!$all) fail('Aucune photo à télécharger.');
      if (count($all) > 400) fail('Trop de photos d\'un coup (' . count($all) . ') : réduisez la période ou ajoutez un filtre (400 au plus).');
      header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="Phototheque_' . date('Y-m-d') . '.zip"'); header('Cache-Control: no-store');
      echo photos_zip($all); exit;

    // ---------- Reprise d'historiques Excel ----------
    case 'mq_preview':
      need_admin('rapports'); @set_time_limit(120);
      $dir = upload_dir() . '/.imports'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
      if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\nDeny from all\n");
      foreach (glob("$dir/*", GLOB_ONLYDIR) ?: [] as $old) if (filemtime($old) < time() - 86400) { array_map('unlink', glob("$old/*") ?: []); @rmdir($old); }
      $f = $_FILES['fichiers'] ?? null; if (!$f || !is_array($f['name'])) fail('Aucun fichier reçu.');
      $tok = bin2hex(random_bytes(12)); @mkdir("$dir/$tok", 0700); $files = [];
      foreach ($f['name'] as $i => $n) {
        if ($f['error'][$i] !== UPLOAD_ERR_OK) continue;
        if (!preg_match('/\.xlsx$/i', $n)) fail('« ' . $n . ' » n\'est pas un fichier Excel .xlsx.');
        move_uploaded_file($f['tmp_name'][$i], "$dir/$tok/$i.xlsx"); $files[] = ['path' => "$dir/$tok/$i.xlsx", 'name' => clip($n, 160), 'id' => "f$i"];
      }
      if (!$files) fail('Aucun fichier lisible.');
      file_put_contents("$dir/$tok/noms.json", json_encode(array_map(fn($x) => ['name' => $x['name'], 'id' => $x['id']], $files), JSON_UNESCAPED_UNICODE));
      try { out(['token' => $tok] + mq_preview($files)); } catch (RuntimeException $e) { fail($e->getMessage()); }

    case 'mq_import':
      need_admin('rapports'); @set_time_limit(180);
      $tok = (string)($in['token'] ?? ''); if (!preg_match('/^[a-f0-9]{24}$/', $tok)) fail('Aperçu expiré : rechargez les fichiers.');
      $dir = upload_dir() . "/.imports/$tok"; $noms = json_decode((string)@file_get_contents("$dir/noms.json"), true);
      if (!$noms) fail('Aperçu expiré : rechargez les fichiers.');
      $files = array_map(fn($x) => ['path' => "$dir/" . substr($x['id'], 1) . '.xlsx', 'name' => $x['name'], 'id' => $x['id']], $noms);
      try { $r = mq_import($files, (array)($in['map'] ?? [])); } catch (RuntimeException $e) { fail($e->getMessage()); }
      array_map('unlink', glob("$dir/*") ?: []); @rmdir($dir);
      out(['ok' => true] + $r);

    // ---------- Listes de destinataires ----------
    case 'save_liste':
      need_admin('tout');
      $nom = clip($in['nom'] ?? '', 120); if ($nom === '') fail('Donnez un nom à la liste.');
      $emails = emails_list($in['emails'] ?? ''); if (!$emails) fail('Ajoutez au moins une adresse.');
      $marques = implode(',', array_unique(array_map('intval', (array)($in['marques'] ?? []))));
      $zones = implode(',', array_values(array_intersect(array_keys(ZONES), (array)($in['zones'] ?? []))));
      $modeles = implode(',', array_values(array_intersect(array_keys(BA_MODELES), (array)($in['modeles'] ?? []))));
      if (!empty($in['id'])) db()->prepare("UPDATE listes_diffusion SET nom = ?, emails = ?, marques = ?, zones = ?, modeles = ? WHERE id = ?")->execute([$nom, implode(', ', $emails), $marques, $zones, $modeles, (int)$in['id']]);
      else db()->prepare("INSERT INTO listes_diffusion (nom, emails, marques, zones, modeles, ordre) VALUES (?,?,?,?,?,100)")->execute([$nom, implode(', ', $emails), $marques, $zones, $modeles]);
      out(['ok' => true]);

    case 'delete_liste':
      need_admin('tout'); db()->prepare("DELETE FROM listes_diffusion WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); out(['ok' => true]);

    // ---------- Comptes administrateurs ----------
    case 'admins':
      need_admin(); if (!est_titulaire()) fail('Réservé à la direction (connectez-vous avec votre e-mail).', 403);
      $L = array_map(fn($x) => $x + ['principal' => est_dirigeant_email($x['email']), 'fondateur' => isset(ADMINS_PRINCIPAUX[$x['email']])], (function () { tf_schema(); return db()->query("SELECT id, nom, email, droits, actif, created_at, derniere_connexion, totp_actif FROM admins ORDER BY actif DESC, nom")->fetchAll(); })());
      // Administrateurs principaux pas encore connectés : affichés pour pouvoir leur donner un mot de passe.
      foreach (ADMINS_PRINCIPAUX as $e => $n) if (!in_array($e, array_column($L, 'email'), true)) array_unshift($L, ['id' => 0, 'nom' => $n, 'email' => $e, 'droits' => 'tout', 'actif' => 1, 'created_at' => null, 'derniere_connexion' => null, 'fondateur' => true, 'principal' => true]);
      out(['admins' => $L, 'moi' => $_SESSION['admin_email'] ?? '']);

    case 'save_admin':
      need_admin(); if (!est_titulaire()) fail('Réservé à la direction (connectez-vous avec votre e-mail).', 403);
      $nom = clip($in['nom'] ?? '', 120); $email = mb_strtolower(trim((string)($in['email'] ?? '')));
      if ($nom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Nom et e-mail obligatoires.');
      $droits = implode(',', array_values(array_intersect(array_keys(ADMIN_DROITS), (array)($in['droits'] ?? []))));
      $pw = (string)($in['password'] ?? '');
      if ($pw !== '' && ($e = mdp_probleme($pw))) fail($e);
      $ex = db()->prepare("SELECT id FROM admins WHERE email = ? AND id <> ?"); $ex->execute([$email, (int)($in['id'] ?? 0)]); if ($ex->fetchColumn()) fail('Cet e-mail a déjà un compte.');
      if (!empty($in['id'])) {
        db()->prepare("UPDATE admins SET nom = ?, email = ?, droits = ?, actif = ? WHERE id = ?")->execute([$nom, $email, $droits, empty($in['actif']) ? 0 : 1, (int)$in['id']]);
        if ($pw !== '') db()->prepare("UPDATE admins SET pass_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$in['id']]);
      } else {
        if ($pw === '') fail('Choisissez un mot de passe (12 caractères minimum).');
        db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,?,1,?)")->execute([$nom, $email, password_hash($pw, PASSWORD_DEFAULT), $droits, now()]);
      }
      journal('compte administrateur', 'enregistré : ' . clip($in['email'] ?? '', 160)); out(['ok' => true]);

    case 'admin_principal_pw':
      // Mot de passe d'un administrateur principal  défini par l'autre : crée le compte s'il n'existe pas encore.
      need_admin(); if (!est_titulaire()) fail('Réservé à la direction (connectez-vous avec votre e-mail).', 403);
      $email = mb_strtolower(trim((string)($in['email'] ?? ''))); if (!isset(ADMINS_PRINCIPAUX[$email])) fail('Compte inconnu.');
      $pw = (string)($in['password'] ?? ''); if ($e = mdp_probleme($pw)) fail($e);
      db()->prepare("INSERT INTO admins (nom, email, pass_hash, droits, actif, created_at) VALUES (?,?,?,'tout',1,?) ON DUPLICATE KEY UPDATE pass_hash = VALUES(pass_hash), actif = 1, droits = 'tout'")
        ->execute([ADMINS_PRINCIPAUX[$email], $email, password_hash($pw, PASSWORD_DEFAULT), now()]);
      journal('compte administrateur', 'enregistré : ' . clip($in['email'] ?? '', 160)); out(['ok' => true]);

    case 'admin_dirigeant':
      // Nommer ou retirer un(e) dirigeant(e) depuis l'appli. Les dirigeants de config.php (fondateurs) restent toujours dirigeants.
      need_admin(); if (!est_titulaire()) fail('Réservé à la direction (connectez-vous avec votre e-mail).', 403);
      tf_schema(); $st = db()->prepare("SELECT id, email, nom, actif, totp_actif FROM admins WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); $a = $st->fetch(); if (!$a) fail('Compte introuvable.');
      if (isset(ADMINS_PRINCIPAUX[$a['email']])) fail('Dirigeant(e) inscrit(e) dans config.php : ce rôle ne se retire pas depuis l\'appli.');
      $oui = !empty($in['dirigeant']);
      if ($oui && !(int)$a['actif']) fail('Réactivez d\'abord ce compte.');
      if (!$oui && mb_strtolower($a['email']) === mb_strtolower((string)($_SESSION['admin_email'] ?? ''))) fail('Vous ne pouvez pas retirer votre propre rôle de dirigeant(e).');
      db()->prepare("UPDATE admins SET dirigeant = ?" . ($oui ? ", droits = 'tout'" : "") . " WHERE id = ?")->execute([$oui ? 1 : 0, $a['id']]); dirigeants(true);
      journal('compte administrateur', ($oui ? 'nommé(e) dirigeant(e) : ' : 'n\'est plus dirigeant(e) : ') . $a['email']); out(['ok' => true]);

    case 'delete_admin':
      need_admin(); if (!est_titulaire()) fail('Réservé à la direction (connectez-vous avec votre e-mail).', 403);
      $st = db()->prepare("SELECT email FROM admins WHERE id = ?"); $st->execute([(int)($in['id'] ?? 0)]); if (est_dirigeant_email((string)$st->fetchColumn())) fail('Retirez d\'abord le rôle de dirigeant(e) de ce compte.');
      db()->prepare("DELETE FROM admins WHERE id = ?")->execute([(int)($in['id'] ?? 0)]); journal('compte administrateur', 'supprimé : n° ' . (int)($in['id'] ?? 0)); out(['ok' => true]);

    case 'my_password':
      need_admin();
      if (empty($_SESSION['admin_id'])) fail('Le mot de passe du compte principal se change dans config.php.');
      $st = db()->prepare("SELECT pass_hash FROM admins WHERE id = ?"); $st->execute([(int)$_SESSION['admin_id']]);
      if (!password_verify((string)($in['actuel'] ?? ''), (string)$st->fetchColumn())) fail('Mot de passe actuel incorrect.');
      $pw = (string)($in['nouveau'] ?? ''); if ($e = mdp_probleme($pw, (string)($_SESSION['admin_email'] ?? ''))) fail($e);
      db()->prepare("UPDATE admins SET pass_hash = ? WHERE id = ?")->execute([password_hash($pw, PASSWORD_DEFAULT), (int)$_SESSION['admin_id']]);
      out(['ok' => true]);

    default:
      fail('Action inconnue', 404);
  }
} catch (Throwable $e) {
  try { if (db()->inTransaction()) db()->rollBack(); } catch (Throwable $x) {}
  fail(erreur_publique($e, 'api'), 500);
}
