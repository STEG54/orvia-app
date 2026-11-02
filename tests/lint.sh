#!/usr/bin/env bash
# Contrôle de syntaxe de tout le code PHP et JavaScript de l'application (bibliothèques tierces exclues).
set -uo pipefail
cd "$(dirname "$0")/.."
err=0
while IFS= read -r f; do out=$(php -l "$f" 2>&1) || { echo "$out"; err=1; }; done < <(find app outils tests -name '*.php' -not -path 'app/dompdf/*')
if command -v node >/dev/null; then
  while IFS= read -r f; do node --check "$f" 2>&1 || err=1; done < <(find app -maxdepth 1 -name '*.js')
else echo "node absent : JavaScript non contrôlé"; fi
# Les scripts de l'administration partagent la même page : un nom de fonction en double écrase l'autre sans prévenir.
dbl=$(grep -ho "^\(async \)\?function [A-Za-z_0-9]*" app/admin*.js app/common.js | sed 's/async //' | sort | uniq -d)
[ -n "$dbl" ] && { echo "Fonctions en double dans les scripts de l'administration : $dbl"; err=1; }
php tests/fonctions.php app || err=1
[ $err -eq 0 ] && echo "Syntaxe : OK" || { echo "Syntaxe : ERREURS"; exit 1; }
