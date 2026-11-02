#!/usr/bin/env bash
# Crée une instance Amaya isolée pour une agence sur un serveur Debian/Ubuntu (Nginx, PHP-FPM 8.2+, MariaDB), en root.
# Isolation : utilisateur système dédié, dossier lisible par lui seul (et Nginx), pool PHP-FPM enfermé (open_basedir,
# fonctions shell coupées), base et compte MariaDB dédiés, tâche planifiée sous son utilisateur.
# Usage : outils/provisionner-agence.sh <agence> <domaine> <agence.json> <paquet.zip> [port, défaut 443]
#   agence  : identifiant court (a-z, 0-9, tirets), ex. eclat
#   agence.json : comme pour installer-agence.php ; "db" est rempli ici (base et compte créés)
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "À lancer en root."; exit 1; }
ag="$1"; dom="$2"; par="$3"; paq="$4"; port="${5:-443}"
[[ "$ag" =~ ^[a-z0-9-]{2,30}$ ]] || { echo "Identifiant d'agence invalide."; exit 1; }
ici="$(cd "$(dirname "$0")" && pwd)"; racine="/srv/amaya/$ag"; user="amaya-$ag"
phpv="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
[ -e "$racine" ] && { echo "$racine existe déjà."; exit 1; }

# 1. Utilisateur et dossiers (Nginx lit, seul l'utilisateur de l'agence écrit ; aucune autre agence ne lit)
id "$user" >/dev/null 2>&1 || useradd --system --home-dir "$racine" --shell /usr/sbin/nologin "$user"
mkdir -p "$racine"/{www,tmp,journaux}
# 2. Base et compte MariaDB dédiés
db="amaya_${ag//-/_}"; dbu="am_${ag//-/_}"; dbu="${dbu:0:32}"; dbp="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-28)"
mysql -e "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '$dbu'@'localhost' IDENTIFIED BY '$dbp'; GRANT ALL ON \`$db\`.* TO '$dbu'@'localhost'; FLUSH PRIVILEGES;"
tmpj="$(mktemp)"; php -r '$a=json_decode(file_get_contents($argv[1]),true); $a["db"]=["host"=>"localhost","name"=>$argv[2],"user"=>$argv[3],"pass"=>$argv[4]]; file_put_contents($argv[5], json_encode($a));' "$par" "$db" "$dbu" "$dbp" "$tmpj"
# 3. Installation de l'appli (même logique que l'assistant)
php "$ici/installer-agence.php" --paquet="$paq" --dossier="$racine/www" --parametres="$tmpj"; rm -f "$tmpj"
# 4. Droits : propriétaire = l'agence, groupe = www-data (lecture des fichiers statiques), rien pour les autres
chown -R "$user:www-data" "$racine"; chmod 0750 "$racine" "$racine/www"
find "$racine/www" -type d -exec chmod 0750 {} +; find "$racine/www" -type f -exec chmod 0640 {} +
chmod 0700 "$racine/tmp" "$racine/journaux"; chmod 0640 "$racine/www/config.php"
chmod 2770 "$racine/www/uploads"; find "$racine/www/uploads" -type d -exec chmod g+s {} +   # nouveaux fichiers : groupe www-data (lecture des photos par Nginx)
# 5. PHP-FPM et Nginx
sed -e "s#__AGENCE__#$ag#g" -e "s#__RACINE__#$racine#g" "$ici/modeles/fpm-agence.conf" > "/etc/php/$phpv/fpm/pool.d/amaya-$ag.conf"
cert="${CERT_DOMAINE:-$dom}"   # certificat générique (*.keylia.ma) pour les espaces créés à l'inscription en ligne
tls=""; [ "$port" = "443" ] && tls="ssl_certificate /etc/letsencrypt/live/$cert/fullchain.pem; ssl_certificate_key /etc/letsencrypt/live/$cert/privkey.pem; add_header Strict-Transport-Security \"max-age=31536000\" always;"
ecoute="$port"; [ "$port" = "443" ] && ecoute="443 ssl"
sed -e "s#__AGENCE__#$ag#g" -e "s#__RACINE__#$racine#g" -e "s#__DOMAINE__#$dom#g" -e "s#__PORT__#$ecoute#g" -e "s#__TLS__#$tls#g" "$ici/modeles/nginx-agence.conf" > "/etc/nginx/sites-available/amaya-$ag.conf"
ln -sf "/etc/nginx/sites-available/amaya-$ag.conf" "/etc/nginx/sites-enabled/amaya-$ag.conf"
[ -f /etc/nginx/conf.d/amaya-https.conf ] || echo 'map $scheme $amaya_https { https on; default ""; }' > /etc/nginx/conf.d/amaya-https.conf
# 6. Tâche planifiée sous l'utilisateur de l'agence (rappels, présence, sauvegarde, copie hors du serveur)
echo "*/15 * * * * $user php -d open_basedir=$racine/ -d sys_temp_dir=$racine/tmp $racine/www/cron.php >> $racine/journaux/cron.log 2>&1" > "/etc/cron.d/amaya-$ag"
chmod 0644 "/etc/cron.d/amaya-$ag"
echo "Instance $ag prête : https://$dom (pensez au certificat : certbot --nginx -d $dom), base $db, utilisateur $user."
echo "Rechargez : systemctl reload php$phpv-fpm nginx"
