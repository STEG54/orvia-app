<?php
// Registre unique du personnel (art. L1221-13 et D1221-23 du Code du travail), tenu à partir des contrats de travail.
// Une ligne par embauche (chaque CDD d'intervention), dans l'ordre chronologique des entrées. À présenter en cas de contrôle (inspection du travail, Urssaf).
require_once __DIR__ . '/contrats_lib.php';

function rup_lignes(): array {
  c_schema(); $r = g_reg();
  $q = db()->query("SELECT c.id FROM contrats c JOIN (SELECT animatrice_id, devis_id, MAX(avenant) av FROM contrats GROUP BY animatrice_id, devis_id) x
    ON x.animatrice_id = c.animatrice_id AND x.devis_id = c.devis_id AND x.av = c.avenant");
  $out = [];
  foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $c = c_row((int)$id); if (!$c || !$c['lignes']) continue;
    // Dates d'entrée et de sortie : 1re et dernière journée de la dernière version (contrat ou avenant).
    $d = array_column($c['lignes'], 'date'); sort($d);
    $st = db()->prepare("SELECT numero, statut FROM contrats WHERE animatrice_id = ? AND devis_id = ? AND avenant = 0"); $st->execute([$c['animatrice_id'], $c['devis_id']]); $c0 = $st->fetch() ?: [];
    $S = $c['salarie']; $f = (array)fiche_get((int)$c['animatrice_id'])['data'];
    $civ = (string)($S['civilite'] ?: ($f['civilite'] ?? ''));
    $nat = (string)($S['nationalite'] ?: ($f['nationalite'] ?? ''));
    if (empty($S['matricule'])) { $mt = db()->prepare("SELECT matricule FROM animatrices WHERE id = ?"); $mt->execute([$c['animatrice_id']]); $S['matricule'] = (string)$mt->fetchColumn(); }
    $out[] = ['matricule' => (string)($S['matricule'] ?? ''), 'nom' => mb_strtoupper((string)$S['nom']), 'nom_naissance' => (string)($S['nom_naissance'] ?? ''), 'prenom' => (string)$S['prenom'],
      'sexe' => $civ === 'Mme' ? 'F' : ($civ === 'M.' ? 'M' : ''), 'nationalite' => $nat, 'naissance' => (string)($S['naissance_date'] ?? ''),
      'emploi' => (string)($c['params']['poste'] ?? $r['ct_poste']), 'qualification' => 'Employé(e), position I, coefficient ' . ($c['params']['coef'] ?? $r['ct_coef']),
      'contrat' => 'CDD d\'intervention (animation commerciale)', 'numero' => (string)($c0['numero'] ?? $c['numero']) . ($c['avenant'] ? ' (avenant ' . $c['avenant'] . ')' : ''),
      'entree' => $d[0], 'sortie' => end($d), 'statut' => $c['statut'] === 'signe' ? 'signé' : 'envoyé, non signé',
      'titre_travail' => trim((string)($f['titre_sejour'] ?? '')), 'etranger' => $nat !== '' && !preg_match('/^(fran|europ|allem|belg|ital|espa|portu|luxem|néerl|neerl|autric|irland|grec|finl|suéd|sued|danois|polon|tchèq|tcheq|slovaq|slov|hongr|roum|bulgar|croat|chypr|maltai|eston|letton|litua|suisse|norvég|norveg|island|liechten)/iu', $nat)];
  }
  usort($out, fn($a, $b) => [$a['entree'], $a['nom'], $a['prenom']] <=> [$b['entree'], $b['nom'], $b['prenom']]);
  foreach ($out as $i => &$x) $x['ordre'] = $i + 1; unset($x);
  return $out;
}
const RUP_COLS = [['ordre', 'N°'], ['matricule', 'Matricule'], ['nom', 'Nom'], ['nom_naissance', 'Nom de naissance'], ['prenom', 'Prénom'], ['sexe', 'Sexe'], ['nationalite', 'Nationalité'], ['naissance', 'Date de naissance'],
  ['emploi', 'Emploi'], ['qualification', 'Qualification'], ['contrat', 'Type de contrat'], ['numero', 'N° de contrat'], ['entree', 'Date d\'entrée'], ['sortie', 'Date de sortie'], ['titre_travail', 'Titre de travail (étrangers hors UE)']];
function rup_html(array $R): string {
  $reg = g_reg(); $dt = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? date('d/m/Y', strtotime($v)) : (string)$v;
  $h = '<html><head><meta charset="utf-8"><style>@page{size:A4 landscape;margin:12mm 10mm}body{font-family:"DejaVu Sans",sans-serif;font-size:7pt;color:#1A1512}
    h1{font-size:13pt;letter-spacing:2pt;margin:0}.s{color:#857869;font-size:7.5pt;margin:2pt 0 8pt}table{border-collapse:collapse;width:100%}th{background:#F6F1EA;font-size:6.4pt;text-transform:uppercase;letter-spacing:.5pt;text-align:left;padding:4pt 3pt;border-bottom:1pt solid #1A1512}
    td{padding:3pt;border-bottom:.4pt solid #E6DDD2;vertical-align:top}.w{color:#A33}</style></head><body>
    <h1>REGISTRE UNIQUE DU PERSONNEL</h1><div class="s">' . h($reg['societe']) . ' · ' . h(str_replace("\n", ', ', $reg['adresse'])) . ' · ' . h($reg['legal']) . '<br>Articles L1221-13 et D1221-23 du Code du travail · ' . count($R) . ' embauche' . (count($R) > 1 ? 's' : '') . ' · édité le ' . date('d/m/Y à H:i') . '</div>
    <table><tr>' . implode('', array_map(fn($c) => '<th>' . h($c[1]) . '</th>', RUP_COLS)) . '</tr>';
  foreach ($R as $x) $h .= '<tr>' . implode('', array_map(fn($c) => '<td>' . ($c[0] === 'titre_travail' && $x['etranger'] && $x['titre_travail'] === '' ? '<span class="w">à compléter</span>' : h(in_array($c[0], ['naissance', 'entree', 'sortie'], true) ? $dt($x[$c[0]]) : (string)$x[$c[0]])) . '</td>', RUP_COLS)) . '</tr>';
  return $h . '</table></body></html>';
}
function rup_xlsx(array $R): string {
  require_once __DIR__ . '/xlsx_lib.php';
  $rows = [1 => [0 => ['v' => 'Registre unique du personnel · ' . g_reg()['societe'] . ' · édité le ' . date('d/m/Y'), 's' => 'y_title']]];
  foreach (RUP_COLS as $i => $c) $rows[3][$i] = ['v' => $c[1], 's' => 'y_head'];
  $r = 4; foreach ($R as $x) { foreach (RUP_COLS as $i => $c) { $v = $x[$c[0]];
      $rows[$r][$i] = in_array($c[0], ['naissance', 'entree', 'sortie'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? ['d' => $v, 's' => 'y_date'] : ['v' => is_int($v) ? $v : (string)$v, 's' => is_int($v) ? 'y_num' : 'y_txt']; } $r++; }
  return xl_book([['name' => 'Registre', 'rows' => $rows, 'widths' => [0 => 6, 1 => 11, 2 => 18, 3 => 16, 4 => 16, 5 => 6, 6 => 14, 7 => 12, 8 => 26, 9 => 30, 10 => 30, 11 => 12, 12 => 12, 13 => 12, 14 => 26], 'heights' => [3 => 30], 'freeze' => 'C4']]);
}
