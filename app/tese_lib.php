<?php
// Lecture du « Décompte de cotisations - Avis de prélèvement » du TESE (PDF) : cotisations Urssaf par salarié et par période d'emploi.
// Lecteur PDF minimal (flux FlateDecode, textes Tj / TJ positionnés par Tm), suffisant pour ce document généré par l'Urssaf.

/** Lignes de texte du PDF, page par page, dans l'ordre de lecture. */
function tese_lignes(string $pdf): array {
  $items = []; $page = 0;
  if (!preg_match_all('/stream\r?\n/', $pdf, $m, PREG_OFFSET_CAPTURE)) return [];
  foreach ($m[0] as [$tok, $pos]) {
    $s = $pos + strlen($tok); $e = strpos($pdf, 'endstream', $s); if ($e === false) continue;
    $raw = substr($pdf, $s, $e - $s); $z = @gzuncompress(rtrim($raw, "\r\n")); if ($z === false) $z = @gzinflate(substr(rtrim($raw, "\r\n"), 2)); if ($z === false || !str_contains($z, 'BT')) continue;
    $page++; $x = 0; $y = 0;
    // Chaque bloc de texte : position (Tm ou Td) puis chaîne(s)
    if (!preg_match_all('/(?:([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+([-\d.]+)\s+Tm)|(?:([-\d.]+)\s+([-\d.]+)\s+Td)|(\[(?:\\\\.|[^\]\\\\])*\]\s*TJ)|(\((?:\\\\.|[^)\\\\])*\)\s*Tj)/s', $z, $ops, PREG_SET_ORDER)) continue;
    foreach ($ops as $o) {
      if (($o[5] ?? '') !== '' && isset($o[6]) && $o[6] !== '') { $x = (float)$o[5]; $y = (float)$o[6]; continue; }
      if (($o[7] ?? '') !== '') { $x += (float)$o[7]; $y += (float)$o[8]; continue; }
      $t = '';
      if (($o[9] ?? '') !== '') { preg_match_all('/\((?:\\\\.|[^)\\\\])*\)|-?[\d.]+/s', substr($o[9], 1, strrpos($o[9], ']') - 1), $parts);
        foreach ($parts[0] as $p) { if ($p[0] === '(') $t .= tese_chaine(substr($p, 1, -1)); elseif ((float)$p < -150) $t .= ' '; } }
      elseif (($o[10] ?? '') !== '') $t = tese_chaine(substr($o[10], 1, strrpos($o[10], ')') - 1));
      if (trim($t) !== '') $items[] = [$page, round($y), $x, $t];
    }
  }
  $L = []; foreach ($items as [$p, $y, $x, $t]) $L[$p][-$y][] = [$x, $t];
  $out = []; ksort($L);
  foreach ($L as $p => $rows) { ksort($rows); foreach ($rows as $r) { usort($r, fn($a, $b) => $a[0] <=> $b[0]); $out[] = trim(preg_replace('/\s+/u', ' ', implode(' ', array_column($r, 1)))); } }
  return $out;
}
function tese_chaine(string $s): string {
  $s = preg_replace_callback('/\\\\([0-7]{1,3}|.)/s', function ($m) { $c = $m[1]; if (ctype_digit($c)) return chr(octdec($c)); return ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => '', 'f' => ''][$c] ?? $c; }, $s);
  return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
}
function tese_num(string $v): float { return (float)str_replace([' ', "\u{00a0}", ','], ['', '', '.'], $v); }

/** Décompte : une ligne par salarié et par période d'emploi (les régularisations des périodes antérieures sont signalées). */
function tese_decompte(string $pdf): array {
  $out = []; $nom = null; $ref = ''; $regul = false; $total = null;
  foreach (tese_lignes($pdf) as $l) {
    if (preg_match('/Montant total des cotisations dues\s*:\s*([\d \x{00a0}]+,\d{2})/u', $l, $m)) $total = tese_num($m[1]);
    if (preg_match('/^R.gul\./u', $l)) { $regul = true; continue; }
    if (preg_match('/^(.+?)\s+R.f\s*:\s*(\S+(?:\s*\/\s*\d+)?)/u', $l, $m)) { $nom = trim($m[1]); $ref = trim($m[2]); continue; }
    if ($nom && preg_match('/(\d{2})\/(\d{2})\/(\d{4})\s+au\s+(\d{2})\/(\d{2})\/(\d{4})\s+([\d \x{00a0}]+,\d{2})\s*euros\s+([\d \x{00a0}]+,\d{2})\s*euros\s+([\d \x{00a0}]+,\d{2})\s+([\d \x{00a0}]+,\d{2})\s*euros/u', $l, $m)) {
      $out[] = ['nom' => $nom, 'ref' => $ref, 'du' => "$m[3]-$m[2]-$m[1]", 'au' => "$m[6]-$m[5]-$m[4]", 'mois' => "$m[6]-$m[5]", 'urssaf' => tese_num($m[7]), 'tiers' => tese_num($m[8]), 'impot' => tese_num($m[9]), 'total' => tese_num($m[10]), 'regul' => $regul];
      $nom = null;
    }
  }
  return ['lignes' => $out, 'total' => $total];
}
