# Composants tiers embarqués

À revoir à chaque version d'Amaya : vérifier les failles publiées (GitHub Security Advisories, CVE) et mettre à jour si besoin.

| Composant | Version | Licence | Emplacement | Rôle | Empreinte SHA-256 (fichier principal) |
|---|---|---|---|---|---|
| dompdf/dompdf | 3.1.6 | LGPL 2.1 | `app/dompdf/vendor/dompdf/dompdf` | PDF (contrats, rapports, documents RH, factures) | |
| dompdf/php-font-lib | 1.0.2 | LGPL 2.1 | `app/dompdf/vendor/dompdf/php-font-lib` | polices des PDF | |
| dompdf/php-svg-lib | 1.0.2 | LGPL 3.0 | `app/dompdf/vendor/dompdf/php-svg-lib` | images SVG dans les PDF | |
| masterminds/html5 | 2.10.1 | MIT | `app/dompdf/vendor/masterminds/html5` | lecture HTML5 pour dompdf | |
| sabberworm/php-css-parser | 8.9.0 | MIT | `app/dompdf/vendor/sabberworm/php-css-parser` | lecture CSS pour dompdf | |
| @zxing/library | 0.23.0 | Apache 2.0 | `app/assets/zxing.min.js` (= `umd/index.min.js`) | lecture des codes-barres EAN (relevés merchandising) | 3ede94153fb0c5b67a12d7adff6decd827c2b22714fdc6faecf27a8f20937ea6 |
| qrcode-generator (Kazuhiko Arase) | 1.4.4 | MIT | `app/assets/qrcode.js` | QR code d'activation de la double authentification | 18ae399f81182bc9de916e9c77b195df20cc58d6f2d55a62b085a299f1bf1780 |

Polices : Figtree et Bricolage Grotesque (SIL OFL 1.1, `app/assets/fonts`), DejaVu (dans dompdf). Récapitulatif livré avec l'appli : `app/LICENCES-TIERS.txt`.

## Mettre à jour dompdf

Packagist n'est pas indispensable : cloner les dépôts GitHub aux versions voulues, puis `composer install --no-dev` avec des dépôts de type `path` (`"packagist.org": false`), et remplacer `app/dompdf/vendor`. Les options utilisées par Amaya (`isRemoteEnabled` à false, `chroot` sur le dossier de l'appli) restent inchangées.

Historique : 3.1.0 jusqu'à Amaya 1.4.0 ; 3.1.6 depuis 1.5.0 (corrige les failles publiées sur 3.1.0 à 3.1.5).
