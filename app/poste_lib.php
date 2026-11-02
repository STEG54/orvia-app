<?php
// Prise de poste géolocalisée : l'intervenant(e) valide son arrivée dans l'appli (heure et position comparées au point de vente).
// L'administration voit qui est en poste, en retard, absent ou n'a rien signalé.
require_once __DIR__ . '/gestion_lib.php';
require_once __DIR__ . '/geo_lib.php';

const PP_TZ = ['France' => 'Europe/Paris'];
const PP_ETATS = ['a_venir' => 'À venir', 'en_poste' => 'En poste', 'retard' => 'Retard', 'non_signale' => 'Non signalé', 'absent' => 'Absent (non payé, non facturé)', 'present' => 'Présent'];
const PP_TOLERANCE = 5;   // minutes de retard tolérées
const PP_RAYON = 500;     // mètres autour du point de vente
const PP_AVANT = 90;      // la prise de poste s'ouvre 1 h 30 avant le début

function pp_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; p_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS prises_poste (mission_id INT UNSIGNED NOT NULL, animatrice_id INT UNSIGNED NOT NULL, date_mission DATE NOT NULL, le DATETIME NULL, heure VARCHAR(5) NOT NULL DEFAULT '',
    ecart_min INT NULL, lat DOUBLE NULL, lng DOUBLE NULL, precision_m INT NULL, distance_m INT NULL, ip VARCHAR(60) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '',
    statut_admin VARCHAR(12) NOT NULL DEFAULT '', note VARCHAR(300) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', modifie_le DATETIME NULL, PRIMARY KEY (mission_id, animatrice_id), INDEX (date_mission), INDEX (animatrice_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Heure locale du point de vente (fuseau de la zone). */
function pp_now(string $zone): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone(PP_TZ[$zone] ?? 'Europe/Paris')); }
function pp_min(string $hm): int { return preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m) ? (int)$m[1] * 60 + (int)$m[2] : 0; }
/** Minutes écoulées depuis le début de la mission, dans le fuseau du point de vente (négatif : pas encore commencé). */
function pp_depuis_debut(array $m): int {
  $n = pp_now((string)$m['zone']); $j = $n->format('Y-m-d');
  $min = (int)$n->format('H') * 60 + (int)$n->format('i') - pp_min((string)$m['debut']);
  return $m['date_mission'] === $j ? $min : ($m['date_mission'] < $j ? 100000 : -100000);
}
/** État d'une journée : à venir, en poste, retard, non signalé, absent. */
function pp_etat(array $m, ?array $p): array {
  $dep = pp_depuis_debut($m); $duree = max(0, pp_min((string)$m['fin']) - pp_min((string)$m['debut']));
  $base = ['ecart' => null, 'heure' => '', 'distance' => null, 'hors_zone' => false, 'note' => (string)($p['note'] ?? ''), 'par' => (string)($p['par'] ?? '')];
  if ($p && $p['statut_admin'] === 'absent') return ['code' => 'absent'] + $base;
  if ($p && $p['statut_admin'] === 'present') return ['code' => 'present'] + $base;
  if ($p && $p['le']) {
    $e = (int)$p['ecart_min']; $d = $p['distance_m'] === null ? null : (int)$p['distance_m'];
    return ['code' => $e > PP_TOLERANCE ? 'retard' : 'en_poste', 'ecart' => $e, 'heure' => (string)$p['heure'], 'distance' => $d, 'hors_zone' => $d !== null && $d > PP_RAYON] + $base;
  }
  if ($dep < 0) return ['code' => 'a_venir'] + $base;
  // Rien de signalé : reste « non signalé » tant que l'agence n'a pas confirmé l'absence (seule une absence confirmée retire la journée de la paye et de la facture).
  return ['code' => 'non_signale', 'ecart' => $dep, 'termine' => $dep > $duree] + $base;
}
/** Peut-on prendre son poste maintenant ? (le jour même, de 1 h 30 avant le début jusqu'à la fin). */
function pp_ouvert(array $m): bool { $dep = pp_depuis_debut($m); $duree = max(0, pp_min((string)$m['fin']) - pp_min((string)$m['debut'])); return $dep >= -PP_AVANT && $dep <= $duree; }

/** Prises de poste d'une liste de missions (id => ligne). */
function pp_lignes(array $ids): array {
  pp_schema(); if (!$ids) return [];
  $st = db()->prepare("SELECT * FROM prises_poste WHERE mission_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $st->execute(array_values($ids));
  $o = []; foreach ($st->fetchAll() as $p) $o[(int)$p['mission_id']][(int)$p['animatrice_id']] = $p; return $o; // une ligne par mission et par personne (absent(e) puis remplaçant(e))
}

/** Enregistre la prise de poste de la personne connectée. */
function pp_prendre(int $aid, int $mid, ?float $lat, ?float $lng, ?int $acc): array {
  pp_schema(); $st = db()->prepare("SELECT * FROM missions WHERE id = ? AND animatrice_id = ? AND statut <> 'annulee'"); $st->execute([$mid, $aid]); $m = $st->fetch();
  if (!$m) throw new RuntimeException('Mission introuvable.');
  $ex = pp_lignes([$mid])[$mid][$aid] ?? null; if ($ex && $ex['le']) return ['deja' => true] + pp_etat($m, $ex);
  if (!pp_ouvert($m)) throw new RuntimeException(pp_depuis_debut($m) < 0 ? 'Trop tôt : la prise de poste s\'ouvre 1 h 30 avant le début de la mission.' : 'La mission est terminée.');
  $n = pp_now((string)$m['zone']); $ecart = (int)$n->format('H') * 60 + (int)$n->format('i') - pp_min((string)$m['debut']);
  $dist = null;
  if ($lat !== null && $lng !== null && abs($lat) <= 90 && abs($lng) <= 180) { $g = geo_magasin((string)$m['magasin']); if ($g && $g['pos']) $dist = (int)round(geo_vol([$lat, $lng], $g['pos']) * 1000); }
  db()->prepare("INSERT INTO prises_poste (mission_id, animatrice_id, date_mission, le, heure, ecart_min, lat, lng, precision_m, distance_m, ip, ua) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE le = VALUES(le), heure = VALUES(heure), ecart_min = VALUES(ecart_min), lat = VALUES(lat), lng = VALUES(lng), precision_m = VALUES(precision_m), distance_m = VALUES(distance_m), ip = VALUES(ip), ua = VALUES(ua)")
    ->execute([$mid, $aid, $m['date_mission'], now(), $n->format('H:i'), $ecart, $lat, $lng, $acc, $dist, clip($_SERVER['REMOTE_ADDR'] ?? '', 60), clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255)]);
  $r = pp_etat($m, pp_lignes([$mid])[$mid][$aid]);
  try { require_once __DIR__ . '/presence_lib.php'; pr_auto(); } catch (Throwable $e) {} // e-mails de présence automatiques
  return $r;
}

/** Journée vue par l'administration : toutes les missions attribuées d'une date, avec leur état ; les absent(e)s remplacé(e)s restent visibles. */
function pp_jour(string $date): array {
  pp_schema(); $sel = "SELECT m.id, m.date_mission, m.debut, m.fin, m.zone, m.magasin, m.client, m.poste, m.devis_id, m.partie, a.id AS aid, a.prenom, a.nom, a.telephone,
    (SELECT COUNT(*) FROM rapports r WHERE r.animatrice_id = a.id AND r.date_animation = m.date_mission) AS cr";
  $st = db()->prepare("$sel, 0 AS remplace FROM missions m JOIN animatrices a ON a.id = m.animatrice_id WHERE m.date_mission = ? AND m.statut <> 'annulee'
    UNION ALL $sel, 1 AS remplace FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id JOIN animatrices a ON a.id = pp.animatrice_id
    WHERE m.date_mission = ? AND pp.statut_admin = 'absent' AND (m.animatrice_id IS NULL OR m.animatrice_id <> pp.animatrice_id)");
  $st->execute([$date, $date]); $L = $st->fetchAll(); usort($L, fn($a, $b) => [$a['debut'], $a['magasin'], $a['remplace']] <=> [$b['debut'], $b['magasin'], $b['remplace']]);
  $P = pp_lignes(array_unique(array_column($L, 'id'))); $out = []; $noms = [];
  foreach ($L as $m) if (!$m['remplace']) $noms[(int)$m['id']] = mb_strtoupper($m['nom']) . ' ' . $m['prenom'];
  $abs = []; foreach ($L as $m) if ($m['remplace']) $abs[(int)$m['id']][] = mb_strtoupper($m['nom']) . ' ' . $m['prenom'];
  foreach ($L as $m) { $p = $P[(int)$m['id']][(int)$m['aid']] ?? null; $e = pp_etat($m, $p);
    if ($e['code'] === 'non_signale' && !empty($e['termine']) && (int)$m['cr']) $e['code'] = 'present'; // compte rendu envoyé : la personne était là
    $out[] = ['id' => (int)$m['id'], 'nom' => mb_strtoupper($m['nom']) . ' ' . $m['prenom'], 'telephone' => $m['telephone'], 'animatrice_id' => (int)$m['aid'], 'devis_id' => (int)$m['devis_id'],
      'debut' => $m['debut'], 'fin' => $m['fin'], 'zone' => $m['zone'], 'magasin' => $m['magasin'], 'client' => $m['client'], 'cr' => (int)$m['cr'] > 0, 'partie' => (int)$m['partie'],
      'remplace_par' => $m['remplace'] ? ($noms[(int)$m['id']] ?? '') : '', 'remplace' => !$m['remplace'] ? implode(', ', $abs[(int)$m['id']] ?? []) : '',
      'heure_locale' => pp_now((string)$m['zone'])->format('H:i')] + $e + ['etat' => PP_ETATS[$e['code']]]; }
  return $out;
}

/** Remplacement en urgence d'une personne absente : la mission passe au (à la) remplaçant(e) ; l'absence reste à la personne absente (relevé, paye). */
function pp_remplacer(int $mid, int $nouv): array {
  pp_schema(); $st = db()->prepare("SELECT * FROM missions WHERE id = ? AND statut <> 'annulee'"); $st->execute([$mid]); $m = $st->fetch() ?: throw new RuntimeException('Mission introuvable.');
  $old = (int)$m['animatrice_id']; if ($old === $nouv) throw new RuntimeException('C\'est déjà cette personne.');
  $a = db()->prepare("SELECT id, prenom, nom, actif FROM animatrices WHERE id = ?"); $a->execute([$nouv]); $p = $a->fetch(); if (!$p || !(int)$p['actif']) throw new RuntimeException('Personne introuvable ou inactive.');
  $o = db()->prepare("SELECT magasin FROM missions WHERE animatrice_id = ? AND date_mission = ? AND statut <> 'annulee' AND id <> ? LIMIT 1"); $o->execute([$nouv, $m['date_mission'], $mid]);
  if ($x = $o->fetchColumn()) throw new RuntimeException($p['prenom'] . ' ' . $p['nom'] . ' travaille déjà ce jour-là (' . $x . ').');
  // L'absence de la personne remplacée est confirmée (si ce n'était pas déjà fait).
  if ($old) db()->prepare("INSERT INTO prises_poste (mission_id, animatrice_id, date_mission, statut_admin, note, par, modifie_le) VALUES (?,?,?,'absent',?,?,?)
    ON DUPLICATE KEY UPDATE statut_admin = 'absent', par = VALUES(par), modifie_le = VALUES(modifie_le)")->execute([$mid, $old, $m['date_mission'], 'Remplacé(e) en urgence', admin_nom(), now()]);
  db()->prepare("UPDATE missions SET animatrice_id = ?, notifie_le = NULL, modifie_le = ?, par = ? WHERE id = ?")->execute([$nouv, now(), admin_nom(), $mid]);
  $n = db()->prepare("SELECT CONCAT(prenom, ' ', nom) FROM animatrices WHERE id = ?"); $n->execute([$old]); $on = (string)$n->fetchColumn();
  if ($m['devis_id']) p_log((int)$m['devis_id'], (string)$m['slot_key'], g_date($m['date_mission']) . ' à ' . $m['magasin'] . ' : ' . $on . ' absent(e), remplacé(e) en urgence par ' . $p['prenom'] . ' ' . $p['nom']);
  return ['ok' => true, 'nom' => $p['prenom'] . ' ' . $p['nom'], 'absent' => $on, 'devis_id' => (int)$m['devis_id']];
}
