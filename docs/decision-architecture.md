# Décision C3 : comment héberger 50 à 100 agences

## Décision proposée
**Une instance isolée par agence, sur un ou plusieurs serveurs privés (VPS ou serveurs dédiés OVHcloud, en Europe), installée et mise à jour par des scripts.** Le code métier d'Amaya ne change pas : planning, contrats, paie, signatures et exports restent identiques. La refonte en base partagée (option b) n'est pas retenue : 40 à 80 jours de travail et un risque de régression sur tout le métier.

## Pourquoi pas l'hébergement mutualisé actuel
Sur un hébergement mutualisé, toutes les installations tournent sous le même utilisateur système : une faille dans une agence donne accès aux fichiers, aux bases et aux clés de chiffrement de toutes les autres. L'hébergement actuel peut garder le site et la démo (données fictives), pas les agences clientes.

## Ce que fait l'outillage (dossier outils/)
| Outil | Rôle |
| --- | --- |
| `provisionner-agence.sh` | crée l'agence : utilisateur système dédié, dossier fermé aux autres, base et compte MariaDB dédiés, pool PHP-FPM enfermé (open_basedir, commandes système coupées, erreurs masquées), site Nginx reprenant les interdictions du .htaccess, tâche planifiée sous l'utilisateur de l'agence |
| `installer-agence.php` | installe l'appli sans intervention (même logique que l'assistant), génère le mot de passe de secours et la clé de copie externe, règle la copie hors du serveur, retire installation.php et diag.php |
| `mettre-a-jour-instances.php` | applique un paquet **signé** à toutes les instances, chacune sous son utilisateur, avec la sauvegarde automatique d'avant mise à jour |
| `restaurer.php` | recrée une agence depuis la copie hors du serveur (C2) |

Vérifié sur une machine de test (Nginx 1.24, PHP-FPM 8.3, MariaDB) par `tests/instances.sh` : fichiers privés refusés par Nginx, une agence ne peut lire ni les fichiers ni la configuration d'une autre, commandes système coupées, connexions et double authentification, tâche planifiée, refus d'un paquet non signé. Non vérifié : l'installation sur un vrai VPS OVHcloud, le certificat HTTPS (certbot), la mise à jour avec un vrai paquet signé (la clé de signature reste chez vous).

## Ce qu'il reste à décider (vous)
1. **Le serveur** : un VPS ou un serveur dédié OVHcloud dans un centre de données européen, différent de la région du stockage des copies. Gabarit à chiffrer sur ovhcloud.com selon le nombre d'agences visé ; prévoir un second serveur au-delà d'une cinquantaine d'agences, ou pour séparer les plus grosses.
2. **Les adresses** : un sous-domaine par agence (agence.amaya-app.fr) ou leur propre domaine (marque blanche complète). Le script accepte les deux ; le certificat se crée avec certbot.
3. **Qui administre le serveur** : mises à jour du système, surveillance, sauvegarde de la machine. Un prestataire d'infogérance est recommandé.
