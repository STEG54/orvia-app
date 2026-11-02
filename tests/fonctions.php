<?php
// Contrôle statique : toute fonction appelée dans app/*.php doit exister (définie dans l'appli, ou fonction PHP / extension).
// Empêche qu'une fonction supprimée lors d'un nettoyage reste appelée (plantage seulement à l'usage).
if (PHP_SAPI !== 'cli') exit;
$dir = $argv[1] ?? dirname(__DIR__) . '/app'; $def = []; $appels = [];
foreach (glob("$dir/*.php") as $f) {
  $t = token_get_all(file_get_contents($f)); $n = count($t);
  for ($i = 0; $i < $n; $i++) {
    if (!is_array($t[$i])) continue;
    if ($t[$i][0] === T_FUNCTION) { for ($j = $i + 1; $j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE; $j++); if (is_array($t[$j]) && $t[$j][0] === T_STRING) $def[strtolower($t[$j][1])] = 1; continue; }
    if ($t[$i][0] !== T_STRING) continue;
    for ($j = $i + 1; $j < $n && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE; $j++); if (($t[$j] ?? null) !== '(') continue;
    for ($k = $i - 1; $k >= 0 && is_array($t[$k]) && $t[$k][0] === T_WHITESPACE; $k--);
    $p = $t[$k] ?? null; if (is_array($p) && in_array($p[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST], true)) continue;
    $appels[strtolower($t[$i][1])][] = basename($f) . ':' . $t[$i][2];
  }
}
$ko = 0; foreach ($appels as $fn => $ou) if (!isset($def[$fn]) && !function_exists($fn) && !in_array($fn, ['exit', 'die', 'isset', 'empty', 'list', 'array', 'eval', 'unset', 'fastcgi_finish_request'], true)) { $ko++; echo "::error::fonction inconnue $fn() appelée dans " . implode(', ', array_slice(array_unique($ou), 0, 5)) . "\n"; }
echo $ko ? "$ko fonction(s) appelée(s) mais inexistante(s)\n" : "Fonctions : toutes définies (" . count($def) . ")\n"; exit($ko ? 1 : 0);
