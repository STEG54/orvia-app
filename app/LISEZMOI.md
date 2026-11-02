# Amaya : installation d'une agence

Amaya est l'appli des agences d'animation commerciale et d'hôtesses : planning, contrats, relevés d'heures, paye, devis, factures, espace marques et appli mobile des animateurs(trices).

## 1. Créer une base de données vide
Chez OVH : Web Cloud › Hébergements › votre hébergement › « Bases de données » › Créer une base (MySQL 8 ou MariaDB).
Notez le serveur, le nom de la base, l'utilisateur et le mot de passe. **Une base vide par agence** : ne réutilisez jamais la base d'une autre appli.

## 2. Envoyer les fichiers
Avec FileZilla, créez un dossier dans `www` (par exemple `www/amaya`), puis déposez-y **tout le contenu** du dossier `amaya` de l'archive.

## 3. Lancer l'assistant
Ouvrez `https://votre-domaine/amaya/` dans le navigateur : l'assistant d'installation s'ouvre tout seul.
Renseignez la base, l'agence (raison sociale, nom court, e-mail, couleur, fuseau horaire, préfixe des matricules), les dirigeants et un mot de passe de secours (12 caractères au moins). Seule son empreinte est écrite dans `config.php` : notez-le hors du serveur.

L'assistant crée les tables et écrit `config.php`.

## 4. Après l'installation
- **Supprimez `installation.php`** du serveur (FileZilla, clic droit › Supprimer).
- **Gardez une copie de `config.php`** en lieu sûr : il contient la clé de chiffrement des documents. Sans elle, les pièces d'identité, IBAN et n° de sécurité sociale déjà enregistrés deviennent illisibles.
- Connectez-vous à `admin.php` avec l'e-mail d'un dirigeant et le mot de passe de secours : vous choisissez aussitôt votre mot de passe personnel et activez la double authentification (obligatoire, appli Google ou Microsoft Authenticator).
- Administration › Réglages : logo, signature du gérant, adresse, mentions légales, banque, contrats de travail, CGV.
- Ouvrez `diag.php` (connecté(e) en dirigeant(e)) pour vérifier l'installation, puis supprimez-le aussi. Si l'appli ne s'ouvre plus du tout : déposez par FTP un fichier vide `diag.txt` dans `prive`, le diagnostic s'ouvre alors pendant 2 heures.

## 5. Sécurité et exploitation (Administration › Maintenance)
- **Double authentification** : obligatoire pour tous les comptes d'administration, activée à la première connexion. Téléphone perdu : un code de secours, ou un dirigeant la réinitialise dans Comptes administrateurs.
- **Procédure de secours** (plus aucun dirigeant ne peut se connecter) : déposez par FTP un fichier `secours.txt` dans le dossier `prive`, ouvrez `secours.php` dans les 2 heures, saisissez l'e-mail d'un dirigeant et le mot de passe de secours. Le compte repart de zéro (nouveau mot de passe, nouvelle double authentification).
- **Clé des documents** (Maintenance › Clé des documents, dirigeants) : elle chiffre pièces, IBAN, n° de sécurité sociale, CV, contrats signés, secrets de double authentification et sauvegardes.
  - **La placer hors du dossier web** : bouton « Placer la clé hors du dossier web ». La clé part dans un fichier à côté de `www` (ex. `/home/compte/amaya-prive/`), config.php ne garde que son chemin (`AMAYA_CLE_FICHIER`). Une seule fois : chacun se reconnecte et l'adresse de surveillance change. La copie hors du serveur emporte aussi ce fichier.
  - **La changer** (fuite possible, départ d'une personne qui y avait accès) : tapez CHANGER puis « Changer la clé ». La nouvelle clé s'affiche une seule fois : notez-la hors du serveur. L'ancienne reste lue (`AMAYA_CLES_ANCIENNES`) pendant que tout est rechiffré par lots (tâche planifiée ou « Poursuivre maintenant »), puis « Retirer l'ancienne clé ». Personne ne perd sa double authentification.
  - Clé déjà dans un fichier : pour la changer, écrivez la nouvelle clé dans le fichier, ajoutez l'ancienne dans config.php : `define('AMAYA_CLES_ANCIENNES', ['ancienne_clé']);`, puis « Poursuivre maintenant » et « Retirer l'ancienne clé ».
  - Ne la modifiez jamais à la main sans garder l'ancienne : les documents déjà chiffrés deviendraient illisibles.
- **Dirigeant(e)s** : ceux inscrits dans config.php à l'installation le restent toujours (et eux seuls peuvent utiliser la procédure de secours). Un(e) dirigeant(e) peut nommer d'autres dirigeant(e)s, ou leur retirer ce rôle, dans Administrateurs.
- **Exports** : fiche d'un(e) intervenant(e) › « Exporter ses données » (demande d'accès ou de portabilité RGPD, documents déchiffrés) ; Maintenance › « Export complet de l'agence » (ZIP chiffré AES-256, mot de passe affiché une fois, fichier effacé après téléchargement ou au bout de 24 h).
- **Sessions** : la direction est déconnectée après 60 min sans activité, et au plus tard 12 h après la connexion (réglable dans config.php : `ADMIN_SESSION_INACTIF`, `ADMIN_SESSION_MAX`, en secondes). Les intervenant(e)s restent connecté(e)s sur leur téléphone tant qu'ils l'utilisent (90 jours sans utilisation au plus, `JETON_JOURS`) ; téléphone perdu ou départ : fiche de l'intervenant(e) › « Déconnecter ses téléphones ». Passer une personne en « Refusé(e) » la déconnecte aussi.
- **Photos** : elles ne sont plus lisibles directement dans `uploads/`, seulement par `photo.php` pour une personne connectée (direction, intervenant(e), compte marque). La règle est posée automatiquement dans `uploads/.htaccess` ; sur un serveur Nginx, elle figure dans `outils/modeles/nginx-agence.conf`.
- **Espace marques** : chaque compte peut activer la double authentification (bouton « Sécurité »). Code perdu : « Envoyer de nouveaux accès » depuis l'administration la retire en même temps que le mot de passe est renouvelé. Les essais de connexion sont limités par adresse et par compte.
- **Journal des accès** (Maintenance, dirigeants seulement) : connexions réussies et refusées, tout fichier téléchargé (contrats, CV, exports, fichier SEPA, sauvegardes, pièces), comptes administrateurs, effacements, mises à jour. Recherche, export CSV, conservation 12 mois.
- **Mots de passe** : 12 caractères au moins pour tous les comptes ; les mots de passe trop courants, répétitifs ou construits sur le nom de l'agence sont refusés.
- **Erreurs** : aucun détail technique n'est montré dans le navigateur ; la personne voit une référence, le détail est dans le journal d'erreurs de l'hébergement (OVH › Statistiques et logs).
- **E-mails** (Maintenance › E-mails) : vérifie que le domaine d'envoi est authentifié (SPF, DMARC) et envoie un e-mail de test. Pour une meilleure arrivée en boîte de réception, envoi possible par un serveur SMTP : ajoutez dans config.php `MAIL_SMTP_HOST` (ex. `ssl0.ovh.net`), `MAIL_SMTP_PORT` (465), `MAIL_SMTP_USER` (l'adresse de la boîte) et `MAIL_SMTP_PASS`. DKIM : à activer pour le domaine dans l'espace OVH.
- **Base de données** : les évolutions sont numérotées et appliquées d'elles-mêmes après une mise à jour ; Maintenance indique le niveau atteint (« à jour »).
- **Sauvegardes** : une sauvegarde chiffrée de la base chaque jour (14 jours + une par mois sur un an), téléchargeable dans Maintenance. Restauration : OVH › Bases de données › Importer.
- **Copie hors du serveur** (indispensable) : chez OVHcloud, créez un conteneur Object Storage S3 dans une **autre région** que l'hébergement, et un utilisateur S3 limité à ce conteneur. Générez la clé de copie avec `php outils/cle-externe.php` (dépôt de l'éditeur), puis ajoutez dans config.php : `SV_S3_ENDPOINT` (ex. https://s3.sbg.io.cloud.ovh.net), `SV_S3_REGION` (ex. sbg), `SV_S3_BUCKET`, `SV_S3_CLE_ACCES`, `SV_S3_SECRET`, `SV_CLE_EXTERNE`. La base, les photos, les documents et config.php partent alors chaque jour, chiffrés ; une vérification de restauration est faite chaque semaine (Maintenance). **Gardez la clé SV_CLE_EXTERNE et une copie de config.php hors du serveur** : sans elles, la copie est illisible.
- **Restauration complète** (serveur perdu) : `php outils/restaurer.php --parametres=… --cible=… --db-name=…` recrée la base, les fichiers et config.php depuis la copie. À tester une fois par trimestre dans une base vide.
- **Mises à jour** : déposez dans Maintenance le paquet .zip fourni par l'éditeur. Il est vérifié (signature), la base et les fichiers sont sauvegardés, puis installé. Plus besoin de FileZilla.
- **Surveillance** : l'adresse `sante.php?cle=…` (affichée dans Maintenance) répond « ok » ; branchez-la sur UptimeRobot ou Better Stack.

## 6. Facultatif
- Tâche planifiée (OVH › Hébergement › Tâches planifiées) : fichier `amaya/cron.php`, toutes les heures sur un hébergement mutualisé OVH (rappels, e-mails de présence automatiques, sauvegarde du jour). Sans elle, la sauvegarde se fait à la première ouverture du tableau de bord de la journée.
- **Cartographie** (kilomètres domicile ↔ point de vente, recherche d'un magasin par son nom) : créez un compte Geoapify (offre gratuite suffisante au départ) et ajoutez `define('GEO_CLE', 'votre_clé');` dans `config.php`. Seules des coordonnées GPS lui sont envoyées pour les trajets. Sans clé, les serveurs publics d'OpenStreetMap servent de secours, sans garantie de service. À inscrire dans la liste des sous-traitants.
- Clé API Claude dans `config.php` (ANTHROPIC_API_KEY) pour les synthèses rédigées des analyses. Désactivée par défaut ; si vous l'activez, les noms des intervenant(e)s, e-mails et téléphones sont retirés des textes envoyés, et Anthropic doit figurer comme sous-traitant (avenant à l'accord de traitement).

## Plusieurs installations sur le même domaine
Chaque dossier a ses propres cookies de connexion : une installation Amaya peut cohabiter avec une autre appli sur le même domaine sans mélanger les sessions.
