<?php
// Relevés d'heures mensuels : construits à partir des attributions du planning, vérifiés par la direction, signés par l'intervenant(e) dans son appli (signature interne).
require_once __DIR__ . '/gestion_lib.php';

function r_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; p_schema();
  try { $t = db()->query("SHOW COLUMNS FROM releves LIKE 'mois'")->fetch(); if ($t && stripos($t['Type'], 'char(7)') !== false) db()->exec("ALTER TABLE releves MODIFY mois VARCHAR(21) NOT NULL"); } catch (Throwable $e) {}
  db()->exec("CREATE TABLE IF NOT EXISTS releves (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, mois VARCHAR(21) NOT NULL,
    statut VARCHAR(10) NOT NULL DEFAULT 'brouillon', ajust MEDIUMTEXT NULL, extras MEDIUMTEXT NULL, lignes MEDIUMTEXT NULL, minutes INT NOT NULL DEFAULT 0, commentaire TEXT NULL,
    base_hash CHAR(40) NOT NULL DEFAULT '', envoye_le DATETIME NULL, envoye_par VARCHAR(120) NOT NULL DEFAULT '',
    signe_le DATETIME NULL, signe_nom VARCHAR(160) NOT NULL DEFAULT '', signe_ip VARCHAR(60) NOT NULL DEFAULT '', signe_ua VARCHAR(255) NOT NULL DEFAULT '', signature MEDIUMTEXT NULL,
    signe_test TINYINT NOT NULL DEFAULT 0, empreinte CHAR(64) NOT NULL DEFAULT '', journal MEDIUMTEXT NULL, created_at DATETIME NOT NULL, modifie_le DATETIME NULL,
    UNIQUE KEY (animatrice_id, mois)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Période d'un relevé : un mois (2026-11) ou une période libre (2026-11-03_2026-11-16). */
function r_mois_ok(string $m): string {
  if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) return $m;
  if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $m, $x) && strtotime($x[1]) && strtotime($x[2]) && $x[1] <= $x[2] && strtotime($x[2]) - strtotime($x[1]) < 400 * 86400) return $m;
  return date('Y-m');
}
function r_range(string $m): array { return strlen($m) === 7 ? ["$m-01", date('Y-m-t', strtotime("$m-01"))] : explode('_', $m); }
function r_mois_nom(string $m): string { $n = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre']; return $n[(int)substr($m, 5, 2)] . ' ' . substr($m, 0, 4); }
function r_mois_txt(string $m): string {
  if (strlen($m) === 7) return r_mois_nom($m);
  [$a, $b] = r_range($m); $n = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
  $j = fn($d) => ((int)substr($d, 8, 2) === 1 ? '1er' : (int)substr($d, 8, 2));
  if (substr($a, 0, 7) === substr($b, 0, 7)) return 'du ' . $j($a) . ' au ' . $j($b) . ' ' . r_mois_nom(substr($b, 0, 7));
  return 'du ' . $j($a) . ' ' . (substr($a, 0, 4) === substr($b, 0, 4) ? $n[(int)substr($a, 5, 2)] : r_mois_nom(substr($a, 0, 7))) . ' au ' . $j($b) . ' ' . r_mois_nom(substr($b, 0, 7));
}
function r_min(string $deb, string $fin, int $pause): int { $d = g_hm($deb); $f = g_hm($fin); if ($f <= $d && $f < 12 * 60) $f += 1440; return max(0, $f - $d - $pause); } // fin après minuit : le lendemain

/** Animations attribuées à l'intervenant(e) ce mois-ci (créneaux annulés exclus). */
function r_auto(int $aid, string $mois): array {
  r_schema(); $st = db()->prepare("SELECT m.id, m.date_mission AS date, m.debut, m.fin, m.pause, m.magasin, m.poste, m.client, d.numero AS devis,
    (SELECT COUNT(*) FROM prises_poste pp WHERE pp.mission_id = m.id AND pp.animatrice_id = m.animatrice_id AND pp.statut_admin = 'absent') AS absent FROM missions m LEFT JOIN g_docs d ON d.id = m.devis_id
    WHERE m.animatrice_id = ? AND m.statut <> 'annulee' AND m.date_mission BETWEEN ? AND ? ORDER BY m.date_mission, m.debut");
  $st->execute(array_merge([$aid], r_range($mois))); $L = $st->fetchAll();
  // Journées où la personne était absente puis remplacée en urgence : la mission est passée au (à la) remplaçant(e), l'absence reste sur son relevé.
  $st = db()->prepare("SELECT m.id, m.date_mission AS date, m.debut, m.fin, m.pause, m.magasin, m.poste, m.client, d.numero AS devis, 1 AS absent
    FROM prises_poste pp JOIN missions m ON m.id = pp.mission_id LEFT JOIN g_docs d ON d.id = m.devis_id
    WHERE pp.animatrice_id = ? AND pp.statut_admin = 'absent' AND (m.animatrice_id IS NULL OR m.animatrice_id <> pp.animatrice_id) AND m.date_mission BETWEEN ? AND ?");
  $st->execute(array_merge([$aid], r_range($mois)));
  foreach ($st->fetchAll() as $x) { $x['id'] = 'a' . $x['id']; $L[] = $x; }
  usort($L, fn($a, $b) => [$a['date'], $a['debut']] <=> [$b['date'], $b['debut']]); return $L;
}
function r_hash(array $auto): string { return sha1(json_encode(array_map(fn($m) => (int)($m['absent'] ?? 0) ? [$m['id'], $m['date'], $m['debut'], $m['fin'], (int)$m['pause'], $m['magasin'], 'absent'] : [$m['id'], $m['date'], $m['debut'], $m['fin'], (int)$m['pause'], $m['magasin']], $auto))); }
function r_row(int $aid, string $mois): ?array { r_schema(); $st = db()->prepare("SELECT * FROM releves WHERE animatrice_id = ? AND mois = ?"); $st->execute([$aid, $mois]); return $st->fetch() ?: null; }

/** Lignes du relevé : en brouillon, recalculées à chaque attribution (avec les corrections par la direction) ; une fois envoyé, figées. */
function r_calc(int $aid, string $mois, ?array $row): array {
  if ($row && $row['statut'] !== 'brouillon' && $row['lignes']) return json_decode($row['lignes'], true) ?: [];
  $aj = json_decode($row['ajust'] ?? '', true) ?: []; $out = [];
  foreach (r_auto($aid, $mois) as $m) {
    $a = $aj[$m['id']] ?? []; $l = ['src' => 'm', 'id' => (string)$m['id'], 'date' => $m['date'], 'magasin' => $m['magasin'], 'poste' => $m['poste'], 'client' => $m['client'], 'devis' => $m['devis'],
      'debut' => $a['debut'] ?? $m['debut'], 'fin' => $a['fin'] ?? $m['fin'], 'pause' => (int)($a['pause'] ?? $m['pause']), 'note' => $a['note'] ?? '', 'exclu' => !empty($a['exclu'])];
    // Absence constatée par l'agence : la journée figure sur le relevé, sans être comptée (ni payée, ni facturée).
    if ((int)$m['absent']) { $l['absent'] = true; $l['exclu'] = true; $l['note'] = trim('Absent(e) : journée non payée' . ($l['note'] !== '' ? ' · ' . $l['note'] : '')); }
    $l['modifie'] = $l['debut'] !== $m['debut'] || $l['fin'] !== $m['fin'] || $l['pause'] !== (int)$m['pause'];
    $out[] = $l;
  }
  foreach (json_decode($row['extras'] ?? '', true) ?: [] as $x) $out[] = ['src' => 'x', 'client' => '', 'devis' => '', 'exclu' => false, 'modifie' => false] + $x;
  foreach ($out as &$l) $l['minutes'] = r_min($l['debut'], $l['fin'], (int)$l['pause']); unset($l);
  usort($out, fn($a, $b) => [$a['date'], $a['debut']] <=> [$b['date'], $b['debut']]);
  return $out;
}
function r_total(array $lignes): array {
  $ok = array_filter($lignes, fn($l) => empty($l['exclu']));
  return ['minutes' => array_sum(array_column($ok, 'minutes')), 'jours' => count(array_unique(array_column($ok, 'date')))];
}
/** Relevé complet pour l'écran ou le PDF. */
function r_get(int $aid, string $mois): array {
  $row = r_row($aid, $mois); $L = r_calc($aid, $mois, $row); $t = r_total($L);
  $st = db()->prepare("SELECT id, prenom, nom, email, zone FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch() ?: ['id' => $aid, 'prenom' => '', 'nom' => '', 'email' => '', 'zone' => ''];
  $chg = $row && $row['statut'] !== 'brouillon' && $row['base_hash'] !== r_hash(r_auto($aid, $mois));
  return ['id' => $row ? (int)$row['id'] : null, 'animatrice' => $a, 'mois' => $mois, 'mois_txt' => r_mois_txt($mois), 'statut' => $row['statut'] ?? 'brouillon', 'lignes' => $L, 'minutes' => $t['minutes'], 'jours' => $t['jours'],
    'commentaire' => $row['commentaire'] ?? '', 'envoye_le' => $row['envoye_le'] ?? null, 'envoye_par' => $row['envoye_par'] ?? '', 'signe_le' => $row['signe_le'] ?? null, 'signe_nom' => $row['signe_nom'] ?? '',
    'signe_ip' => $row['signe_ip'] ?? '', 'signe_test' => (int)($row['signe_test'] ?? 0), 'empreinte' => $row['empreinte'] ?? '', 'signature' => $row['signature'] ?? null, 'change' => $chg,
    'journal' => json_decode($row['journal'] ?? '', true) ?: []];
}
function r_ensure(int $aid, string $mois): array {
  r_schema(); db()->prepare("INSERT IGNORE INTO releves (animatrice_id, mois, created_at) VALUES (?,?,?)")->execute([$aid, $mois, now()]); return r_row($aid, $mois);
}
function r_journal(int $id, string $txt): void {
  $st = db()->prepare("SELECT journal FROM releves WHERE id = ?"); $st->execute([$id]); $j = json_decode((string)$st->fetchColumn(), true) ?: [];
  array_unshift($j, ['le' => now(), 'texte' => mb_substr($txt, 0, 400)]); db()->prepare("UPDATE releves SET journal = ? WHERE id = ?")->execute([json_encode(array_slice($j, 0, 60), JSON_UNESCAPED_UNICODE), $id]);
}
/** Empreinte du contenu signé : toute modification ultérieure se voit. */
function r_empreinte(array $R, string $nom, string $le): string {
  return hash('sha256', json_encode([$R['animatrice']['id'], $R['mois'], array_map(fn($l) => [$l['date'], $l['magasin'], $l['debut'], $l['fin'], (int)$l['pause'], $l['minutes'], !empty($l['exclu'])], $R['lignes']), $R['minutes'], $nom, $le], JSON_UNESCAPED_UNICODE));
}

function r_render(array $R): string {
  $r = g_reg(); $T = ['p' => AG_COULEUR, 'esp' => '#1A1512', 'sand' => '#F6F1EA', 'line' => '#E6DDD2', 'mut' => '#857869']; $a = $R['animatrice'];
  $ff = ''; foreach (['' => ['normal', 'normal'], '-Bold' => ['bold', 'normal'], '-Italic' => ['normal', 'italic']] as $sx => [$w, $st])
    if (is_file(__DIR__ . "/assets/fonts/Figtree$sx.ttf")) $ff .= "@font-face{font-family:'Figtree';font-weight:$w;font-style:$st;src:url('assets/fonts/Figtree$sx.ttf') format('truetype')}";
  $font = $ff ? "'Figtree','DejaVu Sans',sans-serif" : "'DejaVu Sans',sans-serif";
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:46px">' : '<b>' . h($r['societe']) . '</b>';
  $rows = '';
  foreach ($R['lignes'] as $l) {
    $x = !empty($l['exclu']);
    $rows .= '<tr' . ($x ? ' class="x"' : '') . '><td>' . h(date_fr($l['date'])) . '</td><td><b>' . h($l['magasin'] ?: 'Point de vente à préciser') . '</b><div class="sub">' . h($l['poste'] . ($l['client'] ? ' · ' . $l['client'] : '')) . ($l['note'] ? ' · ' . h($l['note']) : '') . ($x ? ' · non compté' : '') . '</div></td>'
      . '<td class="n">' . h($l['debut']) . '</td><td class="n">' . h($l['fin']) . '</td><td class="n">' . ($l['pause'] ? g_dur((int)$l['pause']) : '') . '</td><td class="n b">' . g_dur((int)$l['minutes']) . '</td></tr>';
  }
  if (!$rows) $rows = '<tr><td colspan="6" class="mut">Aucune animation sur cette période.</td></tr>';
  if ($R['statut'] === 'signe') {
    $img = $R['signature'] && str_starts_with($R['signature'], 'data:image/png;base64,') ? '<img src="' . $R['signature'] . '" style="height:52px;margin:4pt 0">' : '';
    $sig = '<div class="k">Signature de l\'intervenant(e)</div>' . $img . '<div><b>' . h($R['signe_nom']) . '</b>, le ' . date('d/m/Y à H:i', strtotime($R['signe_le'])) . '</div>'
      . '<div class="mut sm">Signature électronique interne ' . AG_COURT . ' · adresse IP ' . h($R['signe_ip']) . '<br>Empreinte du relevé (SHA-256) : ' . h($R['empreinte']) . '</div>' . ($R['signe_test'] ? '<div style="color:#B23B2E;font-weight:bold">TEST : signé en aperçu par l\'administrateur, sans valeur.</div>' : '');
  } else $sig = '<div class="k">Signature de l\'intervenant(e)</div><div class="mut sm" style="margin-top:4pt">À signer dans l\'appli ' . AG_COURT . ', rubrique « Mes relevés d\'heures ».</div>';
  $css = $ff . '@page{margin:14mm 16mm 18mm 16mm}body{font-family:' . $font . ';font-size:9.4pt;color:' . $T['esp'] . ';line-height:1.15}b,.b{font-weight:bold}.mut{color:' . $T['mut'] . '}.sm{font-size:7.6pt}
    #footer{position:fixed;bottom:-12mm;left:0;right:0;text-align:center;font-size:6.8pt;color:' . $T['mut'] . ';border-top:.5pt solid ' . $T['line'] . ';padding-top:5pt}
    table{width:100%;border-collapse:collapse}.doc{font-size:19pt;letter-spacing:3pt;font-weight:bold}.num{font-size:10pt;color:' . $T['p'] . ';letter-spacing:1.2pt;font-weight:bold;margin-top:2pt}
    .k{font-size:7pt;text-transform:uppercase;letter-spacing:1.5pt;color:' . $T['p'] . ';font-weight:bold;margin-bottom:3pt}.cl{background:' . $T['sand'] . ';border-left:2.5pt solid ' . $T['p'] . ';padding:10pt 14pt}
    .lg{margin-top:14pt}.lg th{font-size:7pt;text-transform:uppercase;letter-spacing:1.2pt;color:' . $T['mut'] . ';text-align:left;padding:0 6pt 5pt;border-bottom:.8pt solid ' . $T['esp'] . '}
    .lg td{padding:6pt;border-bottom:.5pt solid ' . $T['line'] . ';vertical-align:top}.n{text-align:right;white-space:nowrap}.lg th.n{text-align:right}.sub{font-size:7.8pt;color:' . $T['mut'] . ';margin-top:1pt}
    tr.x td{color:' . $T['mut'] . '}.tot td{padding:8pt 6pt;font-size:11pt}.box{border:.6pt solid ' . $T['line'] . ';padding:10pt 12pt;vertical-align:top}';
  $html = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body><div id="footer">' . h($r['societe']) . ' · ' . h(str_replace("\n", ' · ', $r['adresse'])) . ' · ' . h($r['legal']) . '</div>
    <table><tr><td style="vertical-align:bottom">' . $logo . '</td><td style="text-align:right;vertical-align:bottom"><div class="doc">RELEVÉ D\'HEURES</div><div class="num">' . h(mb_strtoupper($R['mois_txt'])) . '</div></td></tr></table>
    <table style="margin-top:14pt"><tr><td style="width:50%;vertical-align:top;padding-right:16pt" class="mut">' . nl2br(h($r['adresse'])) . '<br>' . h($r['telephone']) . ' · ' . h($r['email']) . '</td>
      <td class="cl"><div class="k">Intervenant(e)</div><b style="font-size:11.5pt">' . h(mb_convert_case(mb_strtolower($a['prenom']), MB_CASE_TITLE) . ' ' . mb_strtoupper($a['nom'])) . '</b><div class="mut">' . h($a['zone']) . '</div></td></tr></table>
    <table class="lg"><tr><th style="width:15%">Date</th><th style="width:47%">Point de vente</th><th class="n">Début</th><th class="n">Fin</th><th class="n">Pause</th><th class="n">Heures</th></tr>' . $rows . '</table>
    <table class="tot" style="margin-top:6pt"><tr><td>' . $R['jours'] . ' jour' . ($R['jours'] > 1 ? 's' : '') . ' travaillé' . ($R['jours'] > 1 ? 's' : '') . '</td><td class="n"><span class="k" style="color:' . $T['mut'] . '">Total</span> <b style="font-size:15pt">' . g_dur($R['minutes']) . '</b></td></tr></table>
    ' . (trim($R['commentaire']) !== '' ? '<div style="margin-top:8pt"><span class="k">Commentaire</span><br>' . nl2br(h($R['commentaire'])) . '</div>' : '') . '
    <table style="margin-top:16pt"><tr><td class="box" style="width:50%"><div class="k">Vérifié par ' . AG_NOM . '</div>' . ($R['envoye_le'] ? h($R['envoye_par']) . ', le ' . date('d/m/Y', strtotime($R['envoye_le'])) : '<span class="mut">En cours de vérification</span>') . '</td>
      <td class="box">' . $sig . '</td></tr></table>
    <div class="mut sm" style="margin-top:10pt">En signant, l\'intervenant(e) certifie l\'exactitude des heures ci-dessus.</div></body></html>';
  return $html;
}
function r_pdf(array $R): string { require_once __DIR__ . '/report_lib.php'; @set_time_limit(120); return make_pdf(r_render($R)); }
function r_fichier(array $R): string { return preg_replace('/[^A-Za-z0-9_.-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', 'Releve_heures_' . $R['mois'] . '_' . $R['animatrice']['prenom'] . '_' . $R['animatrice']['nom'])) . '.pdf'; }
