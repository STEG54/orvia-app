#!/usr/bin/env bash
# Chaîne de contrôle complète, en local ou en intégration continue :
# syntaxe, paquet, installation neuve, tests unitaires, sécurité des connexions, non-régression métier sur une démo.
# Variables : DB_HOST DB_USER DB_PASS (un compte MySQL/MariaDB autorisé à créer des bases), PORT_BASE (défaut 8180).
set -euo pipefail
racine="$(cd "$(dirname "$0")/.." && pwd)"; cd "$racine"
: "${DB_HOST:=127.0.0.1}" "${DB_USER:=root}" "${DB_PASS:=}" "${PORT_BASE:=8180}"
tmp="$(mktemp -d)"; pids=()
fin() { for p in "${pids[@]:-}"; do kill "$p" 2>/dev/null || true; done; rm -rf "$tmp"; }; trap fin EXIT
my() { MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -u "$DB_USER" "$@"; }
# Sur GitHub : chaque échec devient une annotation lisible sans ouvrir le journal complet.
annote() { if [ -n "${GITHUB_ACTIONS:-}" ]; then while IFS= read -r l; do printf '%s\n' "$l"; case "$l" in *ÉCHEC*|*Fatal*|*Warning*) echo "::error::$l";; esac; done; else cat; fi; }
[ -n "${GITHUB_ACTIONS:-}" ] && trap 'echo "::error::ci.sh ligne $LINENO : $BASH_COMMAND"' ERR

echo "== Syntaxe"; tests/lint.sh
echo "== Paquet"; outils/construire-paquet.sh --autorise-modifs

installe() { # $1 dossier, $2 base, $3 port, $4 e-mail du dirigeant
  rm -rf "$1" && mkdir -p "$1" && unzip -q dist/amaya.zip -d "$1.zip" && mv "$1.zip/amaya/"* "$1.zip/amaya/".htaccess "$1/" && rm -rf "$1.zip"
  my -e "DROP DATABASE IF EXISTS \`$2\`; CREATE DATABASE \`$2\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  php -S "127.0.0.1:$3" -t "$1" >"$tmp/srv$3.log" 2>&1 & pids+=($!); sleep 1
  curl -sf -X POST "http://127.0.0.1:$3/installation.php" \
    --data-urlencode "db_host=$DB_HOST" --data-urlencode "db_name=$2" --data-urlencode "db_user=$DB_USER" --data-urlencode "db_pass=$DB_PASS" \
    --data-urlencode "ag_nom=Agence Test" --data-urlencode "ag_court=Test" --data-urlencode "ag_email=contact@test.invalid" --data-urlencode "mail_from=noreply@test.invalid" \
    --data-urlencode "ag_couleur=#6A4BFF" --data-urlencode "tz=Europe/Paris" --data-urlencode "ag_matricule=TST" --data-urlencode "d1_nom=Direction Test" --data-urlencode "d1_email=${4:-direction@test.invalid}" \
    --data-urlencode "d2_nom=" --data-urlencode "d2_email=" --data-urlencode "admin_pw=Secours-Test-2026-long" | grep -q "Installation terminée" || { echo "Installation impossible"; exit 1; }
}
echo "== Installation neuve"; installe "$tmp/agence" amaya_ci "$PORT_BASE"; echo "installation : OK"
echo "== Tests unitaires"; php tests/unitaires.php "$tmp/agence" 2>&1 | annote
echo "== ORVIA : calendriers, missions, chiffrement, conservation"; php tests/conciergerie_unit.php "$tmp/agence" 2>&1 | annote
if [ -f tests/securite.php ]; then echo "== Connexions et accès"; php tests/securite.php "http://127.0.0.1:$PORT_BASE" "$tmp/agence" 2>&1 | annote; fi
if [ -f tests/acces.php ]; then echo "== Photos, sessions, espace marques, en-têtes"; php tests/acces.php "http://127.0.0.1:$PORT_BASE" "$tmp/agence" 2>&1 | annote; fi
if command -v moto_server >/dev/null || [ -n "${S3_TEST_ENDPOINT:-}" ]; then
  echo "== Copie hors du serveur et restauration (stockage S3 de test)"
  if [ -z "${S3_TEST_ENDPOINT:-}" ]; then moto_server -p "$((PORT_BASE + 9))" >"$tmp/s3.log" 2>&1 & pids+=($!); sleep 3; S3_TEST_ENDPOINT="http://127.0.0.1:$((PORT_BASE + 9))"; fi
  curl -sf -X PUT "$S3_TEST_ENDPOINT/amaya-test" -o /dev/null || { echo "Conteneur de test S3 non créé"; exit 1; }
  printf "\ndefine('SV_S3_ENDPOINT', '%s');\ndefine('SV_S3_REGION', 'us-east-1');\ndefine('SV_S3_BUCKET', 'amaya-test');\ndefine('SV_S3_CLE_ACCES', 'test');\ndefine('SV_S3_SECRET', 'test');\n%s\n" "$S3_TEST_ENDPOINT" "$(php outils/cle-externe.php)" >> "$tmp/agence/config.php"
  my -e "DROP DATABASE IF EXISTS amaya_ci_restau; CREATE DATABASE amaya_ci_restau CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  php tests/externe.php "$tmp/agence" amaya_ci_restau "$DB_USER" "$DB_PASS" "$DB_HOST" 2>&1 | annote
else echo "== Copie hors du serveur : non testée (ni moto_server ni S3_TEST_ENDPOINT)"; fi
echo "== Démo et non-régression métier"
# La démo se connecte avec le dirigeant fictif de demo_lib.php (DEMO_DIRIGEANT).
installe "$tmp/demo" amaya_ci_demo "$((PORT_BASE + 1))" direction@demo-amaya.fr
cle="cle-ci-$(date +%s)-demo"
printf "\ndefine('AMAYA_DEMO', true);\ndefine('AMAYA_LEADS', 'leads@test.invalid');\ndefine('AMAYA_DEMO_CLE', '%s');\n" "$cle" >> "$tmp/demo/config.php"
# Le moteur d'origine (devis, paie, contrats…) est testé sur son jeu de démo complet, Keylia sur sa conciergerie marocaine seule.
echo "define('DEMO_JEU_COMPLET', true); // ci-jeu-complet" >> "$tmp/demo/config.php"
php tests/metier.php "http://127.0.0.1:$((PORT_BASE + 1))" "$cle" 2>&1 | annote
sed -i '/ci-jeu-complet/d' "$tmp/demo/config.php"
echo "== ORVIA : parcours conciergerie complet"; php tests/conciergerie.php "http://127.0.0.1:$((PORT_BASE + 1))" "$cle" 2>&1 | annote
echo "== Tout est vert"
