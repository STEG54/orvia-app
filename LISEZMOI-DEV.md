# Amaya : développement

Le dépôt est la seule référence du code. Un paquet livré aux agences se construit toujours depuis un commit, jamais depuis une copie de travail.

## Organisation
- `app/` : l'application déployée chez chaque agence (contenu du paquet `amaya.zip`).
- `site/` : le site de présentation et ses pages légales.
- `outils/` : construction du paquet, installation et mise à jour des instances.
- `tests/` : contrôles automatiques.

## Construire le paquet
    outils/construire-paquet.sh        # refuse si app/ contient des modifications non validées
Résultat : `dist/amaya.zip`, avec la version et le commit dans `amaya/BUILD.txt`.

## Contrôles
    tests/lint.sh                                        # syntaxe PHP et JavaScript
    php tests/unitaires.php <dossier d'installation>     # chiffrement, 2FA, sauvegarde, mises à jour, droits
    php tests/securite.php <adresse> <dossier>           # connexions et double authentification
    php tests/acces.php <adresse> <dossier>              # photos protégées, durée des sessions, espace marques, en-têtes de sécurité
    php tests/metier.php <adresse démo> <clé éditeur>    # planning, contrats, signatures, paie, relevés, exports
    php tests/externe.php <dossier> <base vide> <user> <mdp>  # copie hors du serveur puis restauration complète (S3 de test)
    DB_HOST=… DB_USER=… DB_PASS=… tests/ci.sh            # tout, sur deux installations neuves
`tests/ci.sh` exige un compte MySQL/MariaDB autorisé à créer les bases `amaya_ci*`, et `moto_server` (pip install "moto[server]" boto3) pour tester la copie hors du serveur.
Le fichier `.github/workflows/controles.yml` lance `tests/ci.sh` à chaque envoi sur GitHub.

## Règles
- Une modification = un commit avec un message clair ; une version publiée = un tag `vX.Y.Z`.
- `tests/ci.sh` doit être vert avant de construire un paquet pour les agences.
- Jamais de `config.php`, de données d'agence ni de clé privée de signature dans le dépôt.

## Évolutions de la base (depuis la 1.8.0)

Toute modification de la structure de la base passe par `app/migrations_lib.php` : un nouveau numéro à la fin de `MIGRATIONS` et la fonction `mig_<numéro>(PDO $pdo)`. Jamais de modification d'une évolution déjà publiée. Chaque fonction doit pouvoir être relancée sans dommage, et écrire les noms de tables avec le préfixe de la démo (`demo_prefixe()`). Les évolutions sont appliquées à la première connexion à la base (et pour chaque agence fictive de la démo), notées dans la table `migrations`, et affichées dans Maintenance.

