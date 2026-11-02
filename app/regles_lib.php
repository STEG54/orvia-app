<?php
// Règles de paye par pôle (Réglages) : heures supplémentaires, dimanche, jours fériés, nuit, soirée en zone touristique internationale (ZTI).
// Les taux viennent de la convention collective de l'agence (ou de son accord d'entreprise) : c'est elle qui les saisit et les fait valider.
// Seuls deux planchers légaux sont imposés : 1er mai travaillé (+100 %, art. L.3133-6) et soirée en ZTI de 21 h à minuit (+100 %, repos équivalent
// et transport de retour à la charge de l'employeur, art. L.3122-19).

const RG_CHAMPS = ['hs_seuil' => '35', 'hs_t1' => '25', 'hs_t1_max' => '43', 'hs_t2' => '50', 'dim' => '100', 'ferie' => '100', 'nuit' => '0', 'nuit_debut' => '21:00', 'nuit_fin' => '06:00', 'zti' => '100', 'cumul' => ''];
const RG_ZTI_DEBUT = 21 * 60, RG_ZTI_FIN = 24 * 60, RG_ZTI_MIN = 100.0;
// Communes qui comptent une ZTI (arrêtés de 2015 à 2019). Seul un périmètre de rues est classé : chaque point de vente se vérifie sur l'arrêté (Légifrance) avant d'être coché.
const RG_ZTI_COMMUNES = ['paris', 'puteaux', 'courbevoie', 'serris', 'nice', 'cannes', 'antibes', 'cagnes-sur-mer', 'saint-laurent-du-var', 'deauville', 'la baule-escoublac', 'la baule'];

/** Règles d'un pôle ('anim' ou 'hot'), nombres prêts à l'emploi. */
function rg_regles(string $pole, ?array $r = null): array {
  $r ??= g_reg(); $o = [];
  foreach (RG_CHAMPS as $k => $def) { $v = trim((string)($r["rg_{$pole}_$k"] ?? '')); $o[$k] = $v === '' ? $def : $v; }
  foreach (['hs_seuil', 'hs_t1', 'hs_t1_max', 'hs_t2', 'dim', 'ferie', 'nuit', 'zti'] as $k) $o[$k] = max(0.0, rg_num((string)$o[$k]));
  $o['zti'] = max(RG_ZTI_MIN, $o['zti']); $o['hs_t1_max'] = max($o['hs_seuil'], $o['hs_t1_max']); $o['cumul'] = $o['cumul'] === '1';
  $o['nd'] = g_hm((string)$o['nuit_debut']); $o['nf'] = g_hm((string)$o['nuit_fin']);
  return $o;
}
/** Le point de vente est-il dans une commune qui compte une ZTI ? ('verifier' ou '') */
function rg_zti_suggestion(string $ville): string {
  $v = mb_strtolower(trim(preg_replace('/\s+\d+(e|er|ème)?$/u', '', $ville)));
  return in_array($v, RG_ZTI_COMMUNES, true) ? 'verifier' : '';
}
/** Points de vente classés ZTI : nom => true. */
function rg_zti_map(): array {
  static $m = null; if ($m !== null) return $m; $m = [];
  try { rg_schema(); foreach (db()->query("SELECT nom FROM magasins WHERE zti = 1")->fetchAll(PDO::FETCH_COLUMN) as $n) $m[mb_strtolower(trim($n))] = true; } catch (Throwable $e) {}
  return $m;
}
function rg_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  if (!in_array('zti', db()->query("SHOW COLUMNS FROM magasins")->fetchAll(PDO::FETCH_COLUMN), true)) db()->exec("ALTER TABLE magasins ADD COLUMN zti TINYINT NOT NULL DEFAULT 0");
}
function rg_est_zti(string $magasin): bool { $m = rg_zti_map(); foreach (array_map('trim', explode(',', $magasin)) as $n) if (isset($m[mb_strtolower($n)])) return true; return false; }

/**
 * Une journée : heures et majorations (hors heures supplémentaires, calculées à la semaine).
 * Renvoie heures, h_dim, h_ferie, h_nuit, h_zti (heures concernées), eq (heures équivalentes de majoration : salaire = (heures + eq) × taux), txt (libellé court).
 */
function rg_jour(string $date, string $debut, string $fin, int $pause, string $magasin, string $zone, string $pole, ?array $R = null): array {
  $R ??= rg_regles($pole); $d = g_hm($debut); $f = g_hm($fin); if ($f <= $d && $f < 720) $f += 1440;
  $y = (int)substr($date, 0, 4); $ts = strtotime($date); $lend = date('Y-m-d', $ts + 86400);
  $fer0 = g_feries($y, $zone); $fer1 = substr($lend, 0, 4) == $y ? $fer0 : g_feries($y + 1, $zone); $zti = rg_est_zti($magasin);
  $mins = []; // taux de majoration de chaque minute
  for ($t = $d; $t < $f; $t++) {
    $jr = $t < 1440 ? $date : $lend; $m = $t % 1440; $fer = $t < 1440 ? $fer0 : $fer1; $c = [];
    $dim = date('N', strtotime($jr)) == 7; $fe = isset($fer[$jr]); $mai = substr($jr, 5) === '05-01';
    if ($dim) $c['dim'] = $R['dim'];
    if ($fe) $c['ferie'] = $mai ? max(100.0, $R['ferie']) : $R['ferie'];
    if ($R['nuit'] > 0 && $R['nd'] !== $R['nf'] && ($R['nd'] < $R['nf'] ? ($m >= $R['nd'] && $m < $R['nf']) : ($m >= $R['nd'] || $m < $R['nf']))) $c['nuit'] = $R['nuit'];
    if ($zti && $t >= RG_ZTI_DEBUT && $t < RG_ZTI_FIN) $c['zti'] = $R['zti'];
    $mins[] = $c;
  }
  // Pause : retirée sur les minutes les moins majorées (au bénéfice de la personne).
  $taux = fn($c) => $c ? ($R['cumul'] ? array_sum($c) : max($c)) : 0.0;
  if ($pause > 0 && $mins) { $ix = array_keys($mins); usort($ix, fn($a, $b) => $taux($mins[$a]) <=> $taux($mins[$b]) ?: abs($a - count($mins) / 2) <=> abs($b - count($mins) / 2)); foreach (array_slice($ix, 0, $pause) as $i) unset($mins[$i]); }
  $h = ['dim' => 0, 'ferie' => 0, 'nuit' => 0, 'zti' => 0]; $eq = 0.0;
  foreach ($mins as $c) { foreach ($c as $k => $_) $h[$k]++; $eq += $taux($c) / 100; }
  $o = ['heures' => round(count($mins) / 60, 2), 'h_dim' => round($h['dim'] / 60, 2), 'h_ferie' => round($h['ferie'] / 60, 2), 'h_nuit' => round($h['nuit'] / 60, 2), 'h_zti' => round($h['zti'] / 60, 2), 'eq' => round($eq / 60, 4), 'zti' => $zti];
  $t = []; if ($o['h_ferie']) $t[] = 'férié +' . rg_pct($R['ferie']); if ($o['h_dim']) $t[] = 'dimanche +' . rg_pct($R['dim']);
  if ($o['h_nuit']) $t[] = 'nuit ' . rg_h($o['h_nuit']) . ' h +' . rg_pct($R['nuit']); if ($o['h_zti']) $t[] = 'soirée ZTI ' . rg_h($o['h_zti']) . ' h +' . rg_pct($R['zti']);
  $o['txt'] = implode(' · ', $t); $o['maj'] = $o['eq'] > 0;
  return $o;
}
function rg_pct(float $p): string { return rtrim(rtrim(number_format($p, 2, ',', ''), '0'), ',') . ' %'; }

/**
 * Heures supplémentaires d'une personne, semaine civile par semaine civile (lundi au dimanche), toutes opérations confondues.
 * $E : liste de ['date','debut','heures','pole','k'] ; renvoie k => ['hs1' => h, 'hs2' => h] (les heures au-delà du seuil sont prises sur les dernières de la semaine).
 */
function rg_hs(array $E, ?array $reg = null): array {
  $reg ??= g_reg(); usort($E, fn($a, $b) => [$a['date'], $a['debut']] <=> [$b['date'], $b['debut']]); $sem = []; $out = [];
  foreach ($E as $e) { $w = date('o-W', strtotime($e['date'])); $R = rg_regles((string)$e['pole'], $reg); $avant = $sem[$w] ?? 0.0; $apres = $avant + (float)$e['heures']; $sem[$w] = $apres;
    $s = $R['hs_seuil']; $m = $R['hs_t1_max']; if ($s <= 0) continue;
    $hs1 = max(0, min($apres, $m) - max($avant, $s)); $hs2 = max(0, $apres - max($avant, $m));
    if ($hs1 > 0 || $hs2 > 0) $out[$e['k']] = ['hs1' => round($hs1, 2), 'hs2' => round($hs2, 2), 't1' => $R['hs_t1'], 't2' => $R['hs_t2']]; }
  return $out;
}
function rg_num(string $v): float { return (float)str_replace([',', ' '], ['.', ''], $v); }
function rg_h(float $h): string { return number_format($h, 2, ',', ''); }
