<?php
// Offres de mission : quand personne n'est trouvé, on propose les dates aux intervenants autour du point de vente.
// Chacun(e) se positionne (e-mail ou appli), l'admin choisit, les non-retenu(e)s reçoivent un petit mot.
require_once __DIR__ . '/gestion_lib.php';

function o_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; p_schema();
  db()->exec("CREATE TABLE IF NOT EXISTS offres (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, devis_id INT UNSIGNED NOT NULL, slots MEDIUMTEXT NOT NULL, rayon INT NULL, message TEXT NULL,
    statut VARCHAR(8) NOT NULL DEFAULT 'ouvert', par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, clos_le DATETIME NULL, INDEX (devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS offres_dest (offre_id INT UNSIGNED NOT NULL, animatrice_id INT UNSIGNED NOT NULL, token CHAR(32) NOT NULL UNIQUE, distance DECIMAL(6,1) NULL,
    reponse VARCHAR(4) NULL, dates TEXT NULL, repondu_le DATETIME NULL, retenu TINYINT NOT NULL DEFAULT 0, refus_envoye DATETIME NULL, PRIMARY KEY (offre_id, animatrice_id), INDEX (animatrice_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Créneaux proposés (date, point de vente, horaires) d'après le devis. */
function o_slots(array $d, array $keys): array {
  $out = []; foreach (p_operation($d) as $s) if (in_array($s['key'], $keys, true) && $s['statut'] !== 'annulee')
    $out[] = ['key' => $s['key'], 'date' => $s['date'], 'magasin' => $s['magasin'], 'debut' => $s['debut'], 'fin' => $s['fin'], 'poste' => $s['poste'], 'zone' => $s['zone'], 'pris' => (bool)$s['animatrice_id']];
  return $out;
}
/** Destinataires : actifs de la zone, à moins de « rayon » km à vol d'oiseau d'un des points de vente (commune du domicile). Rayon nul : toute la zone. */
function o_destinataires(array $slots, ?int $rayon, bool $sansAdresse): array {
  $GLOBALS['GEO_MAX'] = 120; $zones = array_values(array_unique(array_column($slots, 'zone'))); if (!$zones) return [];
  $pts = []; foreach (array_unique(array_column($slots, 'magasin')) as $m) { $g = geo_magasin($m); if ($g && $g['pos']) $pts[] = $g['pos']; }
  $st = db()->prepare("SELECT a.id, a.prenom, a.nom, a.email, a.telephone, a.zone, fi.data FROM animatrices a LEFT JOIN fiches_animatrices fi ON fi.animatrice_id = a.id
    WHERE a.actif = 1 AND COALESCE(a.statut, 'actif') = 'actif' AND a.zone IN (" . implode(',', array_fill(0, count($zones), '?')) . ") ORDER BY a.nom, a.prenom");
  $st->execute($zones); $out = [];
  // Personnes déjà prises : on retire celles qui travaillent déjà toutes les dates proposées.
  $dates = array_values(array_unique(array_column($slots, 'date'))); $pris = [];
  $q = db()->prepare("SELECT animatrice_id, date_mission FROM missions WHERE animatrice_id IS NOT NULL AND statut <> 'annulee' AND date_mission IN (" . implode(',', array_fill(0, count($dates), '?')) . ")"); $q->execute($dates);
  foreach ($q->fetchAll() as $m) $pris[(int)$m['animatrice_id']][$m['date_mission']] = true;
  require_once __DIR__ . '/extras_lib.php'; $D = x_dispos($dates); // disponibilités déclarées dans l'appli
  foreach ($st->fetchAll() as $a) {
    if (count($pris[(int)$a['id']] ?? []) >= count($dates)) continue;
    $oui = count(array_filter($dates, fn($j) => ($D[$j][(int)$a['id']] ?? '') === 'o')); $non = count(array_filter($dates, fn($j) => ($D[$j][(int)$a['id']] ?? '') === 'n'));
    if ($non >= count($dates)) continue; // indisponible sur toutes les dates proposées
    $f = json_decode((string)$a['data'], true) ?: []; $cp = trim((string)($f['code_postal'] ?? '')); $vi = trim((string)($f['ville'] ?? ''));
    $dist = null; if ($pts && ($cp !== '' || $vi !== '')) { $h = geo_code(trim($cp . ' ' . $vi), $cp); if ($h) foreach ($pts as $p) { $k = geo_vol([$h[0], $h[1]], $p); $dist = $dist === null ? $k : min($dist, $k); } }
    if ($rayon) { if ($dist === null) { if (!$sansAdresse) continue; } elseif ($dist > $rayon) continue; }
    $out[] = ['id' => (int)$a['id'], 'tri' => mb_strtolower($a['nom'] . ' ' . $a['prenom']), 'nom' => $a['nom'] . ' ' . $a['prenom'], 'ville' => $vi, 'email' => $a['email'], 'telephone' => $a['telephone'], 'deja' => array_keys($pris[(int)$a['id']] ?? []), 'distance' => $dist === null ? null : round($dist, 1), 'dispo' => $oui, 'indispo' => $non];
  }
  usort($out, fn($x, $y) => strcmp($x['tri'], $y['tri'])); // par nom de famille
  return $out;
}
/** Créneaux de l'offre tombant un jour où la personne travaille déjà. */
function o_occupe(int $aid, array $slots): array {
  $dates = array_values(array_unique(array_column($slots, 'date'))); if (!$dates) return [];
  $q = db()->prepare("SELECT DISTINCT date_mission FROM missions WHERE animatrice_id = ? AND statut <> 'annulee' AND date_mission IN (" . implode(',', array_fill(0, count($dates), '?')) . ")"); $q->execute(array_merge([$aid], $dates));
  $d = $q->fetchAll(PDO::FETCH_COLUMN); return array_values(array_column(array_filter($slots, fn($s) => in_array($s['date'], $d, true)), 'key'));
}
function o_get(int $id): ?array { o_schema(); $st = db()->prepare("SELECT * FROM offres WHERE id = ?"); $st->execute([$id]); $o = $st->fetch(); if (!$o) return null; $o['slots'] = json_decode($o['slots'], true) ?: []; return $o; }
/** Texte des dates d'une offre, pour l'e-mail et l'appli. */
function o_lignes(array $slots): string {
  $h = ''; foreach ($slots as $s) $h .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2">' . h(date_fr($s['date'], true)) . '</td><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2"><b>' . h($s['magasin']) . '</b></td><td style="padding:6px 10px;border-bottom:1px solid #E6DDD2;white-space:nowrap">' . h($s['debut'] . ' à ' . $s['fin']) . '</td></tr>';
  return '<table style="border-collapse:collapse;font-size:14px;margin:10px 0">' . $h . '</table>';
}
/** Envoi d'un e-mail à une personne (en phase de test : à l'administrateur, sauf testeurs). */
function o_mail(int $aid, string $sujet, string $corps, string $vue = ''): bool {
  if ($vue !== '') { require_once __DIR__ . '/extras_lib.php'; lp_notif($aid, $sujet, $corps, $vue); } // notification sur le téléphone en plus de l'e-mail
  require_once __DIR__ . '/report_lib.php';
  $st = db()->prepare("SELECT prenom, nom, email FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch(); if (!$a) return false;
  $test = !p_ouvert() && !p_testeur($aid); $to = $test ? (string)($_SESSION['admin_email'] ?? '') : (string)$a['email']; if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">'
    . ($test ? '<p style="background:#FFF4D6;padding:8px 10px;border-radius:6px"><b>Test</b> : cet e-mail serait envoyé à ' . h($a['prenom'] . ' ' . $a['nom']) . ' (' . h($a['email'] ?: 'pas d\'e-mail') . ').</p>' : '')
    . '<p>Bonjour ' . h(mb_convert_case(mb_strtolower($a['prenom']), MB_CASE_TITLE)) . ',</p>' . $corps . '<p>Cordialement.</p><p>' . nl2br(h(g_reg()['signature'])) . '</p></div>';
  return mail_send($to, ($test ? '[Test pour ' . $a['prenom'] . ' ' . $a['nom'] . '] ' : '') . $sujet, $html);
}
