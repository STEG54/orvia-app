#!/usr/bin/env bash
# Instances isolées par agence (outils/provisionner-agence.sh) : deux agences sur Nginx + PHP-FPM, puis contrôles
# des fichiers exposés, de l'étanchéité entre agences, des connexions et des tests unitaires. À lancer en root
# sur une machine de TEST (crée /srv/amaya/ci-*, des comptes système et des bases).
set -euo pipefail
cd "$(dirname "$0")/.."
[ "$(id -u)" -eq 0 ] || { echo "À lancer en root (machine de test)."; exit 1; }
outils/construire-paquet.sh --autorise-modifs >/dev/null
tmp="$(mktemp -d)"; ok=0; ko=0
t() { if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "  ok   $1"; else ko=$((ko+1)); echo "  ÉCHEC $1 (attendu $3, obtenu $2)"; fi; }
for ag in ci-a ci-b; do
  port=$([ $ag = ci-a ] && echo 8401 || echo 8402)
  mysql -e "DROP DATABASE IF EXISTS amaya_${ag//-/_}; DROP USER IF EXISTS 'am_${ag//-/_}'@'localhost';"; rm -rf "/srv/amaya/$ag" "/etc/nginx/sites-enabled/amaya-$ag.conf"
  printf '{"base_url":"http://127.0.0.1:%s","agence":{"nom":"Agence %s","court":"%s","email":"c@%s.invalid","mail_from":"n@%s.invalid","couleur":"#6A4BFF","matricule":"TST"},"dirigeants":[{"nom":"Direction Test","email":"direction@test.invalid"}]}' "$port" "$ag" "$ag" "$ag" "$ag" > "$tmp/$ag.json"
  outils/provisionner-agence.sh "$ag" "$ag.test" "$tmp/$ag.json" dist/amaya.zip "$port" > "$tmp/$ag.log"
done
mkdir -p /run/php; pkill php-fpm || true; pkill nginx || true; sleep 1; php-fpm"$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" -D; nginx; sleep 1
echo "Fichiers exposés (agence A)"
d=/srv/amaya/ci-a/www/uploads; mkdir -p $d/.sauvegardes; echo x > $d/.sauvegardes/t.enc; echo '<?php echo 1;' > $d/x.php; echo x > $d/fiches/x.bin; P=0123456789abcdef0123456789abcdef; echo x > $d/$P.jpg; echo x > $d/${P}_t.jpg; chown -R amaya-ci-a:www-data $d
for u in config.php lib.php gestion_lib.php install.sql dompdf/autoload.inc.php prive/ uploads/fiches/x.bin uploads/.sauvegardes/t.enc uploads/.htaccess uploads/x.php uploads/$P.jpg uploads/${P}_t.jpg cron.php; do
  t "$u refusé" "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8401/$u)" 403; done
t "installation.php retiré" "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8401/installation.php)" 404
t "photo.php sans connexion refusée" "$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8401/photo.php?f=$P")" 401
t "admin.php servi" "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8401/admin.php)" 200
echo "Étanchéité entre agences"
t "A ne lit pas config.php de B (système)" "$(runuser -u amaya-ci-a -- cat /srv/amaya/ci-b/www/config.php >/dev/null 2>&1 && echo lu || echo refusé)" refusé
printf '<?php echo @file_get_contents("/srv/amaya/ci-b/www/config.php") === false ? "refusé" : "lu";' > /srv/amaya/ci-a/www/zz.php; chown amaya-ci-a:www-data /srv/amaya/ci-a/www/zz.php
t "A ne lit pas config.php de B (PHP)" "$(curl -s http://127.0.0.1:8401/zz.php)" refusé
printf '<?php echo function_exists("exec") && is_callable("exec") ? "actif" : "coupé";' > /srv/amaya/ci-a/www/zz.php
t "commandes système coupées" "$(curl -s http://127.0.0.1:8401/zz.php)" coupé; rm -f /srv/amaya/ci-a/www/zz.php $d/x.php
echo "Connexions et tests unitaires (agence A)"
AMAYA_SECOURS="$(sed -n "s/.*secours : //p" "$tmp/ci-a.log")" php tests/securite.php http://127.0.0.1:8401 /srv/amaya/ci-a/www | tee "$tmp/sec.log" | tail -1 | grep -q " 0 en échec" && t "tests de connexion" ok ok || t "tests de connexion" échec ok
php tests/unitaires.php /srv/amaya/ci-a/www | tail -1 | grep -q " 0 en échec" && t "tests unitaires" ok ok || t "tests unitaires" échec ok
t "tâche planifiée sous l'utilisateur de l'agence" "$(runuser -u amaya-ci-a -- php -d open_basedir=/srv/amaya/ci-a/ /srv/amaya/ci-a/www/cron.php | grep -c ok)" 1
echo "Mise à jour de toutes les instances"
t "paquet non signé refusé partout" "$(php outils/mettre-a-jour-instances.php dist/amaya.zip '/srv/amaya/ci-*/www' | grep -c 'pas un paquet')" 2
grep "ÉCHEC" "$tmp/sec.log" || true; rm -rf "$tmp"; echo; echo "$ok réussis, $ko en échec"; [ $ko -eq 0 ]
