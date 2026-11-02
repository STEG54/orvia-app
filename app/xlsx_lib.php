<?php
// Écriture d'un classeur Excel (.xlsx) sans bibliothèque externe : formules, styles, cellules fusionnées, volets figés.

/** Archive ZIP minimale (méthode deflate), suffisante pour un .xlsx. */
function zip_build(array $files): string {
  $out = ''; $cd = ''; $n = 0;
  foreach ($files as $name => $data) {
    $crc = crc32($data); $comp = gzdeflate($data, 6); $off = strlen($out);
    $h = pack('vvvvvVVVvv', 20, 0x0800, 8, 0, 0x21, $crc, strlen($comp), strlen($data), strlen($name), 0);
    $out .= "PK\x03\x04" . $h . $name . $comp;
    $cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, 0, 0x21, $crc, strlen($comp), strlen($data), strlen($name), 0, 0, 0, 0, 32, $off) . $name;
    $n++;
  }
  return $out . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}

function xl_col(int $i): string { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - 1, 26); } return $s; }
function xl_esc(string $s): string { return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s), ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

// Styles (index => rôle). Couleurs : espresso 1A1512, terracotta B5653F, sable F4EEE7.
// Styles p_* et a_* : deux mises en page de tableau de reporting.
const XS_BASE = ['def', 'title', 'sub', 'head', 'date', 'txt', 'eur', 'eur_tot', 'input', 'pct', 'comment', 'tot_lbl', 'head_obj', 'pct_tot', 'hint', 'txt_c', 'kpi', 'kpi_lbl', 'head_pct', 'int', 'kpi_n', 'int_tot',
  'r_title', 'r_head', 'r_date', 'r_txt', 'r_num', 'r_num_b', 'r_pct', 'r_com', 'r_tot', 'r_pct_red',
  'p_title', 'p_grp', 'p_head', 'p_pmh', 'p_date', 'p_ens', 'p_txt', 'p_conf', 'p_num', 'p_sub', 'p_glob', 'p_pm', 'p_com', 'p_totl', 'p_tot', 'p_totpm', 'p_sec', 'p_hint', 'p_oh', 'p_oens', 'p_ocat', 'p_oin', 'p_ocalc', 'p_opct', 'p_gt', 'p_gtn', 'p_gtp',
  'a_title', 'a_head', 'a_headb', 'a_date', 'a_txt', 'a_num', 'a_numb', 'a_com', 'a_totl', 'a_ca', 'a_det',
  'y_title', 'y_head', 'y_txt', 'y_num', 'y_eur', 'y_tot', 'y_totn', 'y_rem', 'y_date', 'y_hint'];
define('XS', array_flip(XS_BASE));

function xl_styles(): string {
  $bd = fn($rgb) => '<border><left style="thin"><color rgb="' . $rgb . '"/></left><right style="thin"><color rgb="' . $rgb . '"/></right><top style="thin"><color rgb="' . $rgb . '"/></top><bottom style="thin"><color rgb="' . $rgb . '"/></bottom><diagonal/></border>';
  $bt = '<border><left style="thin"><color rgb="FFD9CFC3"/></left><right style="thin"><color rgb="FFD9CFC3"/></right><top style="medium"><color rgb="FF1A1512"/></top><bottom style="medium"><color rgb="FF1A1512"/></bottom><diagonal/></border>';
  $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>', $bd('FFD9CFC3'), $bt, $bd('FF000000'), $bd('FFC9CED4')];
  $fn = fn($name, $sz, $rgb, $b = false, $i = false) => '<font>' . ($b ? '<b/>' : '') . ($i ? '<i/>' : '') . '<sz val="' . $sz . '"/><color rgb="' . $rgb . '"/><name val="' . $name . '"/><family val="2"/></font>';
  $fonts = [
    $fn('Aptos', 11, 'FF1A1512'), $fn('Aptos', 18, 'FF' . strtoupper(ltrim(ag_couleur(), '#')), true), $fn('Aptos', 11, 'FF7A6D62'), $fn('Aptos', 11, 'FFFFFFFF', true), $fn('Aptos', 11, 'FF1A1512', true),
    $fn('Aptos', 9, 'FF7A6D62', false, true), $fn('Aptos', 16, 'FF1A1512', true), $fn('Aptos', 22, 'FFFF0000', true), $fn('Aptos', 11, 'FFFF0000', true),
    $fn('Segoe UI', 13, 'FF474A51', true), $fn('Segoe UI', 7, 'FFAAB0B8'), $fn('Segoe UI', 9, 'FF474A51', true), $fn('Segoe UI', 10, 'FF7A7E85'), $fn('Segoe UI', 10, 'FF474A51', true),
    $fn('Segoe UI', 10, 'FF474A51'), $fn('Cambria', 9, 'FF000000'), $fn('Cambria', 10, 'FF1F3864', true), $fn('Segoe UI', 11, 'FF474A51', true), $fn('Segoe UI', 8, 'FFAAB0B8'),
    $fn('Segoe UI', 9, 'FF7A7E85', true), $fn('Segoe UI', 11, 'FFFFFFFF', true),
    $fn('Calibri', 22, 'FFFF0000', true), $fn('Calibri', 11, 'FF000000'), $fn('Calibri', 11, 'FF000000', true), $fn('Calibri', 11, 'FFFF0000', true),
    $fn('Calibri', 10, 'FF595959', false, true),
  ];
  $solid = fn($rgb) => '<fill><patternFill patternType="solid"><fgColor rgb="' . $rgb . '"/></patternFill></fill>';
  $fills = ['<fill><patternFill patternType="none"/></fill>', '<fill><patternFill patternType="gray125"/></fill>', $solid('FF1A1512'), $solid('FFFFF6D5'), $solid('FFF4EEE7'), $solid('FF' . strtoupper(ltrim(ag_couleur(), '#'))), $solid('FF5C4F45'),
    $solid('FFFAFAFA'), $solid('FFCFD4D9'), $solid('FFFCE4D6'), $solid('FFF5EDDA'), $solid('FFE2E4E8'), $solid('FF474A51'), $solid('FFFFC000'), $solid('FFF2F2F2')];
  $c = 'horizontal="center" vertical="center" wrapText="1"'; $l = 'horizontal="left" vertical="center"'; $lw = 'horizontal="left" vertical="center" wrapText="1"';
  $xf = fn($num, $font, $fill, $border, $align) => '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment ' . $align . '/></xf>';
  $xfs = [
    $xf(0, 0, 0, 0, 'vertical="center"'), $xf(0, 1, 0, 0, 'vertical="center"'), $xf(0, 2, 0, 0, 'vertical="center"'), $xf(0, 3, 2, 1, $c), $xf(165, 0, 0, 1, $c),
    $xf(0, 0, 0, 1, 'vertical="center" wrapText="1"'), $xf(164, 0, 0, 1, $c), $xf(164, 4, 4, 2, $c), $xf(164, 0, 3, 1, $c), $xf(166, 4, 0, 1, $c),
    $xf(0, 0, 0, 1, 'vertical="top" wrapText="1"'), $xf(0, 4, 4, 2, 'vertical="center"'), $xf(0, 3, 5, 1, $c), $xf(166, 4, 4, 2, $c), $xf(0, 5, 0, 0, 'vertical="center" wrapText="0"'),
    $xf(0, 0, 0, 1, $c), $xf(164, 6, 4, 1, $c), $xf(0, 2, 4, 1, $c), $xf(0, 3, 6, 1, $c), $xf(3, 0, 0, 1, $c), $xf(3, 6, 4, 1, $c), $xf(3, 4, 4, 2, $c),
    $xf(0, 7, 0, 0, $c), $xf(0, 4, 0, 3, $c), $xf(167, 0, 0, 3, $c), $xf(0, 0, 0, 3, $c), $xf(3, 0, 0, 3, $c), $xf(3, 4, 0, 3, $c), $xf(168, 4, 0, 3, $c), $xf(0, 0, 0, 3, $c), $xf(0, 4, 0, 3, $c), $xf(168, 8, 0, 3, $c),
    // p_*
    $xf(0, 9, 7, 0, $l), $xf(0, 10, 7, 0, $l), $xf(0, 11, 8, 4, $c), $xf(0, 16, 9, 4, $c), $xf(165, 12, 0, 4, $c), $xf(0, 13, 0, 4, $l), $xf(0, 14, 0, 4, $lw), $xf(0, 12, 0, 4, $c),
    $xf(169, 14, 0, 4, $c), $xf(169, 13, 8, 4, $c), $xf(169, 13, 10, 4, $c), $xf(170, 15, 9, 4, $c), $xf(0, 14, 0, 4, 'horizontal="left" vertical="top" wrapText="1"'),
    $xf(0, 13, 8, 4, $l), $xf(169, 13, 8, 4, $c), $xf(170, 16, 9, 4, $c), $xf(0, 17, 0, 0, $l), $xf(0, 18, 0, 0, $l), $xf(0, 19, 8, 4, $l), $xf(0, 13, 11, 4, $l), $xf(0, 12, 0, 4, $l),
    $xf(169, 14, 8, 4, $c), $xf(169, 13, 8, 4, $c), $xf(166, 13, 0, 4, $c), $xf(0, 20, 12, 4, $l), $xf(169, 20, 12, 4, $c), $xf(166, 20, 12, 4, $c),
    // a_*
    $xf(0, 21, 0, 0, $c), $xf(0, 22, 0, 3, $c), $xf(0, 23, 0, 3, $c), $xf(171, 22, 0, 3, $c), $xf(0, 22, 0, 3, $c), $xf(169, 22, 0, 3, $c), $xf(169, 23, 0, 3, $c),
    $xf(0, 22, 0, 3, $lw), $xf(0, 23, 0, 3, $c), $xf(170, 24, 0, 3, $c), $xf(0, 22, 0, 3, $c),
    // y_* (préparation de paye, au format du tableau « Salaires »)
    $xf(0, 23, 0, 0, $l), $xf(0, 23, 13, 3, $c), $xf(0, 22, 0, 3, $l), $xf(0, 22, 0, 3, $c), $xf(170, 22, 0, 3, $c), $xf(170, 23, 14, 3, $c), $xf(0, 23, 14, 3, $c),
    $xf(0, 22, 0, 3, $lw), $xf(165, 22, 0, 3, $c), $xf(0, 25, 0, 0, 'horizontal="left" vertical="top" wrapText="1"'),
  ];
  return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="9"><numFmt numFmtId="164" formatCode="#,##0 &quot;€&quot;"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="0%"/><numFmt numFmtId="167" formatCode="dd/mm/yy"/><numFmt numFmtId="168" formatCode="0.00%"/>'
    . '<numFmt numFmtId="169" formatCode="#,##0"/><numFmt numFmtId="170" formatCode="#,##0.00&quot; €&quot;"/><numFmt numFmtId="171" formatCode="d/m/yy"/><numFmt numFmtId="172" formatCode="0.##"/></numFmts>'
    . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts><fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
    . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
}

/**
 * Feuille : ['name', 'rows' => [ligne => [col => cell]], 'widths' => [col => largeur], 'heights' => [ligne => hauteur],
 *  'merges' => ['A1:L1'], 'freeze' => 'A5', 'filter' => 'A4:L20']
 * Cellule : valeur simple, ou ['v' => valeur, 's' => style, 'f' => formule]. Les dates : ['d' => 'YYYY-MM-DD'].
 */
function xl_sheet(array $sh): string {
  $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
  $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView workbookViewId="0" zoomScale="100">';
  if (!empty($sh['freeze'])) {
    preg_match('/^([A-Z]+)(\d+)$/', $sh['freeze'], $m); $row = (int)$m[2] - 1;
    $col = 0; foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64; $col--;
    $pane = $col > 0 ? 'bottomRight' : 'bottomLeft';
    $x .= '<pane ' . ($col > 0 ? 'xSplit="' . $col . '" ' : '') . 'ySplit="' . $row . '" topLeftCell="' . $sh['freeze'] . '" activePane="' . $pane . '" state="frozen"/><selection pane="' . $pane . '" activeCell="' . $sh['freeze'] . '" sqref="' . $sh['freeze'] . '"/>';
  }
  $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="15"/>';
  if (!empty($sh['widths'])) { $x .= '<cols>'; ksort($sh['widths']); foreach ($sh['widths'] as $c => $w) $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>'; $x .= '</cols>'; }
  $x .= '<sheetData>';
  ksort($sh['rows']);
  foreach ($sh['rows'] as $r => $cells) {
    $ht = $sh['heights'][$r] ?? null;
    $x .= '<row r="' . $r . '"' . ($ht ? ' ht="' . $ht . '" customHeight="1"' : '') . '>';
    ksort($cells);
    foreach ($cells as $c => $cell) {
      if (!is_array($cell)) $cell = ['v' => $cell];
      $ref = xl_col($c) . $r; $s = XS[$cell['s'] ?? 'def'];
      $a = '<c r="' . $ref . '" s="' . $s . '"';
      if (isset($cell['d'])) { $days = (strtotime($cell['d'] . ' 00:00:00 UTC') / 86400) + 25569; $x .= $a . '><v>' . $days . '</v></c>'; continue; }
      $v = $cell['v'] ?? null;
      if (isset($cell['f'])) {
        $f = '<f>' . xl_esc($cell['f']) . '</f>';
        if (is_int($v) || is_float($v)) $x .= $a . '>' . $f . '<v>' . $v . '</v></c>';
        else $x .= $a . ' t="str">' . $f . '<v>' . xl_esc((string)$v) . '</v></c>';
      } elseif ($v === null || $v === '') $x .= $a . '/>';
      elseif (is_int($v) || is_float($v)) $x .= $a . '><v>' . $v . '</v></c>';
      else $x .= $a . ' t="inlineStr"><is><t xml:space="preserve">' . xl_esc((string)$v) . '</t></is></c>';
    }
    $x .= '</row>';
  }
  $x .= '</sheetData>';
  if (!empty($sh['filter'])) $x .= '<autoFilter ref="' . $sh['filter'] . '"/>';
  if (!empty($sh['merges'])) { $x .= '<mergeCells count="' . count($sh['merges']) . '">'; foreach ($sh['merges'] as $m) $x .= '<mergeCell ref="' . $m . '"/>'; $x .= '</mergeCells>'; }
  $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/><pageSetup orientation="landscape" fitToWidth="1" fitToHeight="0"/>';
  return $x . '</worksheet>';
}

/** Pré-calcule les SUM() pour que les totaux s'affichent aussi dans les aperçus (mail, téléphone). */
function xl_cache_sums(array $sh): array {
  $num = function ($col) { $n = 0; foreach (str_split($col) as $ch) $n = $n * 26 + ord($ch) - 64; return $n - 1; };
  ksort($sh['rows']);
  foreach ($sh['rows'] as $r => $cells) foreach ($cells as $c => $cell) {
    if (!is_array($cell) || empty($cell['f']) || !preg_match('/^SUM\(([A-Z]+)(\d+):([A-Z]+)(\d+)\)$/', $cell['f'], $m)) continue;
    $t = 0;
    for ($rr = (int)$m[2]; $rr <= (int)$m[4]; $rr++) for ($cc = $num($m[1]); $cc <= $num($m[3]); $cc++) {
      $x = $sh['rows'][$rr][$cc] ?? null; $v = is_array($x) ? ($x['v'] ?? null) : $x;
      if (is_int($v) || is_float($v)) $t += $v;
    }
    $sh['rows'][$r][$c]['v'] = round($t, 2);
  }
  return $sh;
}

function xl_book(array $sheets): string {
  $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
  $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView activeTab="0"/></bookViews><sheets>';
  $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
  $files = []; $defined = '';
  foreach (array_values($sheets) as $i => $sh) {
    $n = $i + 1;
    $ct .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    $name = mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $sh['name']), 0, 31);
    $wb .= '<sheet name="' . xl_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
    $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
    if (!empty($sh['filter'])) $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . xl_esc(str_replace("'", "''", $name)) . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '\$$1\$$2', $sh['filter']) . '</definedName>';
    $files['xl/worksheets/sheet' . $n . '.xml'] = xl_sheet(xl_cache_sums($sh));
  }
  $wb .= '</sheets>' . ($defined ? '<definedNames>' . $defined . '</definedNames>' : '') . '<calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
  $rels .= '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
  $files = ['[Content_Types].xml' => $ct . '</Types>',
    '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
    'xl/workbook.xml' => $wb, 'xl/_rels/workbook.xml.rels' => $rels, 'xl/styles.xml' => xl_styles()] + $files;
  return zip_build($files);
}

// ---------- Le reporting, au format habituel (un onglet par mois) ----------

const MOIS_FR = [1 => 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

function xl_mag(array $r): string { return mb_stripos($r['magasin'], $r['ville']) !== false ? $r['magasin'] : $r['magasin'] . ' (' . $r['ville'] . ')'; }

/** Commentaire rédigé comme dans les reportings habituels, à partir de la saisie de l'animatrice. */
function xl_comment(array $r): string {
  $p = [];
  $conc = array_merge($r['concurrence'], $r['concurrence_autre'] !== '' ? [$r['concurrence_autre']] : []);
  if ($conc) $p[] = 'Intervenants concurrent(e)s : ' . implode(', ', $conc) . '.';
  $t = fn($s) => rtrim(trim(preg_replace('/\s+/', ' ', (string)$s)), '.');
  if (trim((string)$r['offres']) !== '') $p[] = 'Offres : ' . $t($r['offres']) . '.';
  foreach ($r['lines'] as $l) {
    $b = count($r['lines']) > 1 ? ' ' . $l['marque'] : '';
    if (trim((string)$l['reactions']) !== '') $p[] = 'Clientes' . $b . ' : ' . $t($l['reactions']) . '.';
    if (trim((string)$l['ruptures']) !== '') $p[] = 'Ruptures' . $b . ' : ' . $t($l['ruptures']) . '.';
    if (trim((string)$l['testeurs']) !== '') $p[] = 'Testeurs manquants' . $b . ' : ' . $t($l['testeurs']) . '.';
  }
  if (trim((string)$r['faits']) !== '') $p[] = 'Faits marquants : ' . $t($r['faits']) . '.';
  if (trim((string)$r['staff']) !== '') $p[] = 'Personnel / direction : ' . $t($r['staff']) . '.';
  return implode("\n", $p);
}

/** Colonnes de CA : une par marque, ou une par marque ventilée (client ventilé en sous-marques). */
function xl_columns(array $d): array {
  $cols = [];
  foreach ($d['brands'] as $b) {
    $sous = array_values(array_filter(array_map('trim', explode(',', (string)($b['sous_marques'] ?? '')))));
    if ($sous) foreach ($sous as $n) $cols[] = ['id' => $b['id'], 'sub' => $n, 'nom' => $n];
    else $cols[] = ['id' => $b['id'], 'sub' => null, 'nom' => $b['nom']];
  }
  return $cols;
}

function make_xlsx(array $d): string {
  $brands = xl_columns($d); $nb = count($brands);
  // CA (ou objectif) d'une colonne : la marque, ou une marque ventilée (sous-marque d'un client ventilé).
  $caOf = function (array $r, array $col, string $k = 'ca') {
    foreach ($r['lines'] as $l) {
      if ((int)$l['marque_id'] !== (int)$col['id']) continue;
      if ($col['sub'] !== null) { foreach ($l['parts'] ?? [] as $pp) if ($pp['nom'] === $col['sub'] && ($pp[$k] ?? null) !== null) return round((float)$pp[$k], 2); return null; }
      return ($l[$k] ?? null) !== null ? round((float)$l[$k], 2) : null;
    }
    return null;
  };
  // Nom affiché : le prénom, comme dans les reportings habituels (initiale du nom si deux prénoms identiques).
  $names = []; foreach ($d['rapports'] as $r) $names[$r['animatrice_id']] = $r['animatrice'];
  $prenoms = []; foreach ($names as $id => $n) $prenoms[$id] = explode(' ', trim($n))[0];
  $count = array_count_values($prenoms);
  $label = []; foreach ($names as $id => $n) { $pp = explode(' ', trim($n)); $label[$id] = mb_strtoupper($count[$prenoms[$id]] > 1 && isset($pp[1]) ? $pp[0] . ' ' . mb_substr($pp[1], 0, 1) . '.' : $pp[0]); }
  $months = []; foreach ($d['rapports'] as $r) $months[substr($r['date_animation'], 0, 7)][] = $r; ksort($months);
  // Colonnes : DATE | Magasin | marques… | TOTAL | Objectif marques… | TOTAL OBJECTIF | % atteinte marques… | % ATTEINTE | COMMENTAIRES | autres réponses…
  $cCA = 2; $cTot = $cCA + $nb; $cObj = $cTot + 1; $cObjT = $cObj + $nb; $cPct = $cObjT + 1; $cPctT = $cPct + $nb; $cCom = $cPctT + 1;
  $extra = ['Fréquentation', 'Réactions des clientes', 'Ruptures', 'Testeurs manquants', 'Faits marquants', 'Personnel / direction'];
  $lastCol = xl_col($cCom + count($extra));
  $t = fn($s) => rtrim(trim(preg_replace('/\s+/', ' ', (string)$s)), '.');
  $perBrand = function (array $r, string $k) use ($t) {
    $out = [];
    foreach ($r['lines'] as $l) if (trim((string)$l[$k]) !== '') $out[] = (count($r['lines']) > 1 ? $l['marque'] . ' : ' : '') . $t($l[$k]);
    return implode("\n", $out);
  };
  $sheets = []; $totRefs = [];
  foreach ($months as $ym => $rs) {
    [$y, $m] = explode('-', $ym);
    $W = ['name' => MOIS_FR[(int)$m] . ' ' . $y, 'rows' => [], 'heights' => [], 'merges' => []];
    $byA = []; foreach ($rs as $r) $byA[$r['animatrice_id']][] = $r;
    uksort($byA, fn($a, $b) => strcmp($label[$a], $label[$b]));
    $row = 1;
    foreach ($byA as $aid => $list) {
      usort($list, fn($a, $b) => [$a['date_animation'], $a['magasin']] <=> [$b['date_animation'], $b['magasin']]);
      $W['rows'][$row] = [0 => ['v' => 'REPORTING ' . $label[$aid] . ' ' . mb_strtoupper(MOIS_FR[(int)$m]) . ' ' . $y, 's' => 'r_title']];
      $W['merges'][] = 'A' . $row . ':' . xl_col($cCom) . $row; $W['heights'][$row] = 28.5;
      $hr = $row + 3;
      $h = [0 => 'DATE', 1 => 'Magasin'];
      foreach ($brands as $i => $b) { $h[$cCA + $i] = $b['nom']; $h[$cObj + $i] = 'Objectif ' . $b['nom']; $h[$cPct + $i] = '% atteinte ' . $b['nom']; }
      $h[$cTot] = 'TOTAL'; $h[$cObjT] = 'TOTAL OBJECTIF'; $h[$cPctT] = '% ATTEINTE'; $h[$cCom] = 'COMMENTAIRES';
      foreach ($extra as $k => $v) $h[$cCom + 1 + $k] = $v;
      foreach ($h as $c => $v) $W['rows'][$hr][$c] = ['v' => $v, 's' => 'r_head'];
      $W['heights'][$hr] = 31.5;
      $first = $hr + 1; $row = $hr;
      foreach ($list as $r) {
        $row++; $R = [0 => ['d' => $r['date_animation'], 's' => 'r_date'], 1 => ['v' => xl_mag($r), 's' => 'r_txt']];
        foreach ($brands as $i => $b) {
          $ca = $caOf($r, $b); $ob = $caOf($r, $b, 'objectif');
          $R[$cCA + $i] = ['v' => $ca, 's' => 'r_num']; $R[$cObj + $i] = ['v' => $ob, 's' => 'r_num'];
          $a = xl_col($cCA + $i) . $row; $o = xl_col($cObj + $i) . $row;
          $R[$cPct + $i] = ['f' => "IFERROR({$a}/{$o}-1,\"\")", 'v' => $ob ? round(($ca ?? 0) / $ob - 1, 4) : '', 's' => 'r_pct'];
        }
        $R[$cTot] = ['f' => 'SUM(' . xl_col($cCA) . $row . ':' . xl_col($cTot - 1) . $row . ')', 'v' => 0, 's' => 'r_num_b'];
        $R[$cObjT] = ['f' => 'SUM(' . xl_col($cObj) . $row . ':' . xl_col($cObjT - 1) . $row . ')', 'v' => 0, 's' => 'r_num_b'];
        // % global seulement si toutes les marques ont un objectif (sinon on compare des choses différentes).
        $objR = xl_col($cObj) . $row . ':' . xl_col($cObjT - 1) . $row;
        $R[$cPctT] = ['f' => 'IF(COUNTBLANK(' . $objR . ')>0,"",IFERROR(' . xl_col($cTot) . $row . '/' . xl_col($cObjT) . $row . '-1,""))', 'v' => '', 's' => 'r_pct'];
        $conc = array_merge($r['concurrence'], $r['concurrence_autre'] !== '' ? [$r['concurrence_autre']] : []);
        $com = trim(($conc ? 'Intervenants ' . implode(', ', $conc) . '. ' : '') . (trim((string)$r['offres']) !== '' ? $t($r['offres']) . '.' : ''));
        $imp = ($r['source'] ?? '') === 'import'; if ($imp) $com = trim((string)$r['faits']); // repris d'un Excel de marque : le commentaire d'origine
        $R[$cCom] = ['v' => $com, 's' => 'r_com'];
        $ex = [$r['frequentation'], $perBrand($r, 'reactions'), $perBrand($r, 'ruptures'), $perBrand($r, 'testeurs'), $imp ? '' : $t($r['faits']), $t($r['staff'])];
        foreach ($ex as $k => $v) $R[$cCom + 1 + $k] = ['v' => $v, 's' => $k === 0 ? 'r_txt' : 'r_com'];
        $W['rows'][$row] = $R;
        $len = max(array_map(fn($v) => mb_strlen((string)$v), array_merge([$com], $ex)));
        $W['heights'][$row] = max(45, min(180, 15 * (1 + intdiv($len, 45))));
      }
      $row++; $last = $row - 1;
      $T = [0 => ['v' => 'Total', 's' => 'r_tot'], 1 => ['v' => '', 's' => 'r_tot']];
      for ($c = $cCA; $c <= $cObjT; $c++) { $col = xl_col($c); $T[$c] = ['f' => "SUM({$col}{$first}:{$col}{$last})", 'v' => 0, 's' => 'r_num_b']; }
      foreach ($brands as $i => $b) { $a = xl_col($cCA + $i) . $row; $o = xl_col($cObj + $i) . $row; $T[$cPct + $i] = ['f' => "IFERROR({$a}/{$o}-1,\"\")", 'v' => '', 's' => 'r_pct']; }
      $T[$cPctT] = ['f' => 'IFERROR(' . xl_col($cTot) . $row . '/' . xl_col($cObjT) . $row . '-1,"")', 'v' => '', 's' => 'r_pct_red'];
      $W['rows'][$row] = $T; $W['heights'][$row] = 21;
      $totRefs[$ym][$aid] = $row;
      $row += 4;
    }
    $W['widths'] = [0 => 10.7, 1 => 24];
    for ($c = $cCA; $c <= $cPctT; $c++) $W['widths'][$c] = 11.5;
    $W['widths'][$cCom] = 60; $W['widths'][$cCom + 1] = 14;
    for ($k = 2; $k <= count($extra); $k++) $W['widths'][$cCom + $k] = 34;
    $sheets[] = $W;
  }
  if ($sheets) array_unshift($sheets, xl_synthese($d, $months, $totRefs, $label, $caOf));
  if (!$sheets) $sheets[] = ['name' => 'Reporting', 'rows' => [1 => [0 => ['v' => 'Aucune animation sur cette période', 's' => 'r_title']]]];
  // Le % atteinte se recalcule à l'ouverture ; on pré-remplit les cas où un objectif est connu.
  return xl_book($sheets);
}


/** Onglet Synthèse : reprend les totaux des onglets mensuels par formules (objectifs ajoutés à la main compris). */
function xl_synthese(array $d, array $months, array $totRefs, array $label, callable $caOf): array {
  $brands = xl_columns($d); $nb = count($brands);
  $cTot = 1 + $nb; $cObj = $cTot + 1; $cObjT = $cObj + $nb; $cPct = $cObjT + 1; $cPctT = $cPct + $nb; $cN = $cPctT + 1;
  $S = ['name' => 'Synthèse', 'rows' => [], 'heights' => [1 => 28.5], 'merges' => ['A1:' . xl_col($cN) . '1', 'A2:' . xl_col($cN) . '2']];
  $S['rows'][1] = [0 => ['v' => 'SYNTHÈSE ' . mb_strtoupper(brands_label($d)), 's' => 'r_title']];
  $S['rows'][2] = [0 => ['v' => ($d['p']['zone'] ?: 'Toutes zones') . ' · ' . period_short($d['p']) . ' · ' . count($d['rapports']) . ' journée' . (count($d['rapports']) > 1 ? 's' : '') . ' d\'animation', 's' => 'r_txt']];
  $S['heights'][2] = 20;
  $sheetName = fn($ym) => "'" . MOIS_FR[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4) . "'";
  $head = function ($first) use ($brands, $cTot, $cObj, $cObjT, $cPct, $cPctT, $cN) {
    $h = [0 => $first];
    foreach ($brands as $i => $b) { $h[1 + $i] = $b['nom']; $h[$cObj + $i] = 'Objectif ' . $b['nom']; $h[$cPct + $i] = '% atteinte ' . $b['nom']; }
    $h[$cTot] = 'TOTAL'; $h[$cObjT] = 'TOTAL OBJECTIF'; $h[$cPctT] = '% ATTEINTE'; $h[$cN] = 'Journées';
    return array_map(fn($v) => ['v' => $v, 's' => 'r_head'], $h);
  };
  $pct = function (&$R, $row) use ($nb, $cTot, $cObj, $cObjT, $cPct, $cPctT) {
    for ($i = 0; $i < $nb; $i++) $R[$cPct + $i] = ['f' => 'IFERROR(' . xl_col(1 + $i) . $row . '/' . xl_col($cObj + $i) . $row . '-1,"")', 'v' => '', 's' => 'r_pct'];
    $R[$cPctT] = ['f' => 'IFERROR(' . xl_col($cTot) . $row . '/' . xl_col($cObjT) . $row . '-1,"")', 'v' => '', 's' => 'r_pct'];
    $R[$cTot] = ['f' => 'SUM(B' . $row . ':' . xl_col($cTot - 1) . $row . ')', 'v' => 0, 's' => 'r_num_b'];
    $R[$cObjT] = ['f' => 'SUM(' . xl_col($cObj) . $row . ':' . xl_col($cObjT - 1) . $row . ')', 'v' => 0, 's' => 'r_num_b'];
  };
  // Valeur en cache pour l'aperçu, formule pour le calcul réel.
  $refSum = function (array $refs, int $col, ?float $cache) { return ['f' => implode('+', array_map(fn($r) => $r . xl_col($col)  . '', $refs)), 'v' => $cache ?? 0, 's' => 'r_num']; };
  $blocks = [
    ['Mois', array_keys($months), fn($ym) => MOIS_FR[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4)],
    ['Intervenant(e)', array_keys($label), fn($aid) => $label[$aid]],
  ];
  $row = 4;
  foreach ($blocks as $bi => [$title, $keys, $name]) {
    if ($bi === 1) uasort($keys, fn($a, $b) => strcmp($label[$a], $label[$b]));
    $S['rows'][$row] = $head($title); $S['heights'][$row] = 31.5; $first = $row + 1;
    foreach ($keys as $k) {
      $row++; $R = [0 => ['v' => $name($k), 's' => 'r_txt']];
      // Références vers les lignes Total des onglets mensuels
      $refs = []; $days = 0; $cache = array_fill(0, 2 * $nb, 0.0);
      foreach ($totRefs as $ym => $byA) foreach ($byA as $aid => $tr) {
        if (($bi === 0 && $ym !== $k) || ($bi === 1 && (int)$aid !== (int)$k)) continue;
        $refs[] = [$sheetName($ym), $tr];
      }
      foreach ($d['rapports'] as $r) {
        if (($bi === 0 && substr($r['date_animation'], 0, 7) !== $k) || ($bi === 1 && (int)$r['animatrice_id'] !== (int)$k)) continue;
        $days++;
        foreach ($brands as $i => $b) { $cache[$i] += (float)$caOf($r, $b); $cache[$nb + $i] += (float)$caOf($r, $b, 'objectif'); }
      }
      foreach ($brands as $i => $b) {
        // Colonnes dans les onglets mensuels : CA = C.., Objectif = après TOTAL
        $R[1 + $i] = ['f' => implode('+', array_map(fn($x) => $x[0] . '!' . xl_col(2 + $i) . $x[1], $refs)), 'v' => round($cache[$i], 2), 's' => 'r_num'];
        $R[$cObj + $i] = ['f' => implode('+', array_map(fn($x) => $x[0] . '!' . xl_col(2 + $nb + 1 + $i) . $x[1], $refs)), 'v' => round($cache[$nb + $i], 2), 's' => 'r_num'];
      }
      $pct($R, $row);
      $R[$cN] = ['v' => $days, 's' => 'r_num'];
      $S['rows'][$row] = $R; $S['heights'][$row] = 20;
    }
    $row++; $T = [0 => ['v' => 'Total', 's' => 'r_tot']];
    for ($c = 1; $c <= $cObjT; $c++) if ($c !== $cTot && $c !== $cObjT) { $col = xl_col($c); $T[$c] = ['f' => "SUM({$col}{$first}:{$col}" . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b']; }
    $pct($T, $row); $T[$cPctT]['s'] = 'r_pct_red';
    $T[$cN] = ['f' => 'SUM(' . xl_col($cN) . $first . ':' . xl_col($cN) . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b'];
    $S['rows'][$row] = $T; $S['heights'][$row] = 21;
    $row += 3;
  }
  // Par point de vente (valeurs)
  $S['rows'][$row] = $head('Point de vente'); $S['heights'][$row] = 31.5; $first = $row + 1;
  $stores = []; foreach ($d['rapports'] as $r) $stores[xl_mag($r)][] = $r; ksort($stores);
  foreach ($stores as $st => $rs) {
    $row++; $R = [0 => ['v' => $st, 's' => 'r_txt']];
    foreach ($brands as $i => $b) {
      $ca = 0; $ob = 0; foreach ($rs as $r) { $ca += (float)$caOf($r, $b); $ob += (float)$caOf($r, $b, 'objectif'); }
      $R[1 + $i] = ['v' => round($ca, 2), 's' => 'r_num']; $R[$cObj + $i] = ['v' => $ob ? round($ob, 2) : null, 's' => 'r_num'];
    }
    $pct($R, $row); $R[$cN] = ['v' => count($rs), 's' => 'r_num'];
    $S['rows'][$row] = $R; $S['heights'][$row] = 20;
  }
  $row++; $T = [0 => ['v' => 'Total', 's' => 'r_tot']];
  for ($c = 1; $c <= $cObjT; $c++) if ($c !== $cTot && $c !== $cObjT) { $col = xl_col($c); $T[$c] = ['f' => "SUM({$col}{$first}:{$col}" . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b']; }
  $pct($T, $row); $T[$cPctT]['s'] = 'r_pct_red';
  $T[$cN] = ['f' => 'SUM(' . xl_col($cN) . $first . ':' . xl_col($cN) . ($row - 1) . ')', 'v' => 0, 's' => 'r_num_b'];
  $S['rows'][$row] = $T; $S['heights'][$row] = 21;
  $S['widths'] = [0 => 44];
  for ($c = 1; $c <= $cN; $c++) $S['widths'][$c] = 11.5;
  return $S;
}
/** Classeur simple : [nom d'onglet => lignes], première ligne en en-tête ; nombres, dates (AAAA-MM-JJ) et textes reconnus. */
function xl_simple(array $tabs): string {
  $sheets = [];
  foreach ($tabs as $name => $rows) { $R = []; $w = [];
    foreach (array_values($rows) as $i => $row) foreach (array_values($row) as $j => $v) {
      $w[$j] = max($w[$j] ?? 8, min(45, mb_strlen((string)$v) + 2));
      $R[$i + 1][$j] = $i === 0 ? ['v' => (string)$v, 's' => 'y_head'] : ($v === null || $v === '' ? ['v' => '', 's' => 'y_txt']
        : (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? ['d' => $v, 's' => 'y_date'] : (is_int($v) || is_float($v) ? ['v' => $v, 's' => 'y_num'] : ['v' => (string)$v, 's' => 'y_txt']))); }
    $sheets[] = ['name' => $name, 'rows' => $R, 'widths' => $w, 'freeze' => 'A2']; }
  return xl_book($sheets);
}
