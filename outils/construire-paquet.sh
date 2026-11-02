#!/usr/bin/env bash
# Construit dist/amaya.zip à partir du dépôt (jamais à partir d'une copie de travail non versionnée).
# Usage : outils/construire-paquet.sh [--autorise-modifs]
set -euo pipefail
racine="$(cd "$(dirname "$0")/.." && pwd)"; cd "$racine"
if [ "${1:-}" != "--autorise-modifs" ] && [ -n "$(git status --porcelain -- app)" ]; then
  echo "Des fichiers de app/ ne sont pas validés (git commit) : paquet refusé." >&2; exit 1; fi
version=$(sed -n "s/.*AMAYA_VERSION = '\([^']*\)'.*/\1/p" app/version.php)
commit=$(git rev-parse --short HEAD)
rm -rf pkg && mkdir -p pkg dist
if [ "${1:-}" = "--autorise-modifs" ]; then
  # Contrôles avant commit : fichiers de travail suivis ou nouveaux (hors fichiers ignorés).
  mkdir -p pkg/amaya && git ls-files -co --exclude-standard -- app | sed 's#^app/##' | (cd app && while IFS= read -r f; do [ -e "$f" ] && printf '%s\n' "$f"; done) | (cd app && tar -cf - -T -) | tar -x -C pkg/amaya; commit="$commit+modifs"
else git archive --format=tar --prefix=amaya/ HEAD:app | tar -x -C pkg; fi
# Empreinte de chaque fichier suivi par diag.php (contrôle d'intégrité après dépôt FTP).
python3 - "$racine/pkg/amaya" <<'P'
import hashlib, json, os, glob, sys
root = sys.argv[1]
files = sorted([os.path.relpath(f, root) for f in glob.glob(root + '/*') if os.path.isfile(f) and f.rsplit('.', 1)[-1] in ('php', 'js', 'css', 'txt') and not f.endswith(('diag.php', 'installation.php', 'config.php'))] + ['.htaccess', 'assets/icon-192.png', 'assets/logo-amaya.png'])
m = {f: hashlib.md5(open(os.path.join(root, f), 'rb').read()).hexdigest() for f in files}
p = os.path.join(root, 'diag.php'); s = open(p).read().replace("/*MANIFEST*/", json.dumps(m)); open(p, 'w').write(s)
print(len(m), 'fichiers suivis')
P
echo "$version ($commit)" > pkg/amaya/BUILD.txt
rm -f dist/amaya.zip && (cd pkg && zip -qr ../dist/amaya.zip amaya)
echo "dist/amaya.zip : Amaya $version, commit $commit"
