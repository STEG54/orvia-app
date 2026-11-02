import os
import os
OUT=os.path.join(os.path.dirname(os.path.abspath(__file__)), 'amaya-site')
EMAIL='sguellil@luxury-partner-international.com'; TEL='+33 6 20 92 68 61'
DEMO='https://luxury-partner-international.com/a-demo/demo.php'
F=lambda s: f'<mark class="fx">{s}</mark>'
ED={'nom':F('Amaya Logiciels SAS'),'forme':F('société par actions simplifiée au capital de 10 000 €'),'rcs':F('RCS Nancy 000 000 000'),'tva':F('FR00 000000000'),
    'siege':F('1 rue de l\'Exemple, 54000 Nancy, France'),'dir':F('Stéphane Guellil'),'trib':F('Nancy'),'maj':'16 octobre 2026'}
LOGO='''<svg viewBox="0 0 64 64" aria-hidden="true"><defs><linearGradient id="lg" x1="0" x2="1"><stop offset="0" stop-color="#6A4BFF"/><stop offset="1" stop-color="#FF5C8A"/></linearGradient></defs><rect width="64" height="64" rx="15" fill="url(#lg)"/><path d="M17 50 32 13 47 50" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M23 35a12 10 0 0 0 18 0" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round"/></svg>'''
PAGES=[('mentions-legales.html','Mentions légales'),('confidentialite.html','Confidentialité et cookies'),('cgv.html','Conditions générales'),('traitement-donnees.html','Accord de traitement des données')]
def page(fn,titre,lead,body):
    nav=''.join(f'<a href="{f}"{" aria-current=\"page\"" if f==fn else ""}>{t}</a>' for f,t in PAGES)
    return f'''<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="favicon-32.png">
<title>{titre} · Amaya</title>
<link rel="stylesheet" href="fonts/fonts.css">
<link rel="stylesheet" href="legal.css">
</head>
<body>
<header class="nav"><div class="w"><a class="logo" href="index.html" aria-label="Amaya, accueil">{LOGO}AMAYA</a><a class="btn" href="{DEMO}">Essayer la démo</a></div></header>
<main class="w doc">
<nav class="tabs" aria-label="Documents légaux">{nav}</nav>
<h1>{titre}</h1>
<p class="lead">{lead}</p>
<p class="maj">Dernière mise à jour : {ED['maj']}. <span class="fx-note">Les informations <mark class="fx">surlignées</mark> sont provisoires et seront remplacées avant la mise en service.</span></p>
{body}
</main>
<footer><div class="w"><span>Amaya, le logiciel de gestion des agences d'animation commerciale, d'hôtessariat et de merchandising.</span><span><a href="index.html">Retour au site</a> · <a href="mailto:{EMAIL}">{EMAIL}</a></span></div></footer>
</body>
</html>
'''
def sec(t,*ps): return f'<section><h2>{t}</h2>'+''.join(ps)+'</section>'
def ul(*xs): return '<ul>'+''.join(f'<li>{x}</li>' for x in xs)+'</ul>'
P=lambda s:f'<p>{s}</p>'
HEB='OVH SAS, 2 rue Kellermann, 59100 Roubaix, France (RCS Lille Métropole 424 761 419), téléphone 1007, ovhcloud.com'

# ---------- Mentions légales
ml=''.join([
 sec('Éditeur',P(f"Le site Amaya et le logiciel Amaya sont édités par {ED['nom']}, {ED['forme']}, immatriculée au {ED['rcs']}, n° de TVA intracommunautaire {ED['tva']}, dont le siège est situé {ED['siege']}."),
   P(f'Contact : <a href="mailto:{EMAIL}">{EMAIL}</a> · {TEL}')),
 sec('Directeur de la publication',P(f"{ED['dir']}, en qualité de représentant légal de l'éditeur.")),
 sec('Hébergement',P(f'Le site et les services Amaya sont hébergés par {HEB}. Les données sont stockées dans des centres de données situés {F("en France")}, dans l\'Union européenne.')),
 sec('Propriété intellectuelle',P("La marque Amaya, le logo, les textes, la structure du site et le logiciel sont la propriété de l'éditeur. Toute reproduction ou réutilisation, totale ou partielle, sans autorisation écrite est interdite."),
   P("Les captures d'écran présentées montrent une agence fictive : noms, chiffres, marques et personnes y sont inventés. Polices de caractères : Bricolage Grotesque et Figtree, sous licence SIL Open Font License, hébergées sur nos serveurs.")),
 sec('Données personnelles et cookies',P('Le traitement des données collectées sur ce site et dans la démo est décrit dans la page <a href="confidentialite.html">Confidentialité et cookies</a>. Le site vitrine ne dépose aucun cookie et ne mesure pas l\'audience.')),
 sec('Liens',P("Le site peut renvoyer vers des sites tiers (CNIL, Légifrance, hébergeur). L'éditeur n'est pas responsable de leur contenu.")),
 sec('Droit applicable',P(f"Le présent site est soumis au droit français. En cas de litige, et à défaut de solution amiable, les tribunaux de {ED['trib']} sont compétents.")),
])
open(f'{OUT}/mentions-legales.html','w').write(page('mentions-legales.html','Mentions légales',"Qui édite Amaya, qui l'héberge et à qui s'adresser.",ml))

# ---------- Confidentialité
cf=''.join([
 sec('En bref',ul("Le site vitrine ne dépose aucun cookie, ne mesure pas l'audience et ne charge aucun service tiers (polices hébergées chez nous).",
   "Les coordonnées laissées pour la démo servent à vous envoyer votre accès et à vous recontacter au sujet d'Amaya, si vous l'avez accepté. Elles ne sont jamais vendues ni louées.",
   "Votre agence fictive de démonstration est supprimée automatiquement au bout de 7 jours.",
   "Les données de vos salariés saisies dans Amaya appartiennent à votre agence : nous les traitons uniquement pour votre compte (voir plus bas).")),
 sec('Responsable du traitement',P(f"Pour le site et la démo : {ED['nom']}, {ED['siege']}. Contact pour toute question relative à vos données : <a href=\"mailto:{EMAIL}\">{EMAIL}</a>.")),
 sec('Données collectées et finalités','<div class="tw"><table><tr><th>Traitement</th><th>Données</th><th>Base légale</th><th>Durée de conservation</th></tr>'
   '<tr><td>Envoi de votre accès à la démo</td><td>Prénom, nom, agence, e-mail professionnel, téléphone, taille de l\'équipe, métier</td><td>Mesures précontractuelles prises à votre demande (art. 6.1.b RGPD)</td><td>Agence fictive et ses contenus : 7 jours</td></tr>'
   '<tr><td>Recontact commercial au sujet d\'Amaya</td><td>Mêmes coordonnées</td><td>Votre accord, donné en cochant la case du formulaire (art. 6.1.a), retirable à tout moment</td><td>3 ans après le dernier échange</td></tr>'
   '<tr><td>Échanges par e-mail ou téléphone</td><td>Coordonnées, contenu des échanges</td><td>Intérêt légitime à répondre à vos demandes (art. 6.1.f)</td><td>3 ans après le dernier échange</td></tr>'
   '<tr><td>Sécurité et prévention des abus</td><td>Adresse IP, date et heure, pages appelées</td><td>Intérêt légitime à protéger le service (art. 6.1.f)</td><td>12 mois au plus</td></tr>'
   '<tr><td>Clients : contrat, facturation, assistance</td><td>Identité et coordonnées des interlocuteurs, factures</td><td>Exécution du contrat (art. 6.1.b) et obligations légales (art. 6.1.c)</td><td>Durée du contrat, puis 10 ans pour les pièces comptables</td></tr></table></div>'),
 sec('Destinataires',ul("L'équipe de l'éditeur, dans la limite de ses besoins.",f"Notre hébergeur, {HEB.split(',')[0]}, dans des centres de données situés {F('en France')}.",
   "Aucune donnée n'est transférée hors de l'Union européenne, vendue, louée ni utilisée à des fins publicitaires.")),
 sec('Cookies',P("<b>Site vitrine</b> : aucun cookie, aucun traceur, aucune mesure d'audience, aucun bouton de réseau social."),
   P("<b>Démo et logiciel</b> : un cookie de session, et dans l'appli des intervenants un cookie « rester connecté » sur le téléphone. Tous deux sont strictement nécessaires au service et exemptés de consentement (article 82 de la loi Informatique et Libertés). L'application mobile peut garder sur le téléphone vos brouillons de comptes rendus, pour ne rien perdre sans réseau.")),
 sec('Données traitées dans Amaya pour le compte des agences',P("Quand une agence utilise Amaya, elle est <b>responsable du traitement</b> des données de ses intervenants, candidats, clients et contacts. L'éditeur agit comme <b>sous-traitant</b> au sens de l'article 28 du RGPD : il ne traite ces données que sur instruction de l'agence, pour faire fonctionner le service. Les engagements correspondants sont détaillés dans l'<a href=\"traitement-donnees.html\">accord de traitement des données</a>, annexé au contrat."),
   P("Les intervenants disposent dans l'application d'une page « Vos données personnelles » qui leur explique ce que leur agence collecte et comment exercer leurs droits auprès d'elle.")),
 sec('Vos droits',P(f"Vous pouvez accéder à vos données, les faire rectifier ou effacer, vous opposer à leur traitement, en demander la limitation ou la portabilité, retirer votre accord à tout moment et définir des directives sur leur sort après votre décès. Écrivez à <a href=\"mailto:{EMAIL}\">{EMAIL}</a> : nous répondons sous un mois."),
   P('Si vous estimez que vos droits ne sont pas respectés, vous pouvez adresser une réclamation à la CNIL, 3 place de Fontenoy, TSA 80715, 75334 Paris Cedex 07, ou sur <a href="https://www.cnil.fr/fr/plaintes" rel="noopener">cnil.fr</a>.')),
 sec('Sécurité',P("Connexion chiffrée (HTTPS), mots de passe hachés, double authentification obligatoire pour tous les comptes d'administration, chiffrement des données les plus sensibles, sauvegardes chiffrées quotidiennes copiées hors du serveur avec une clé distincte, une base de données distincte par agence. Le détail figure dans l'accord de traitement des données.")),
])
open(f'{OUT}/confidentialite.html','w').write(page('confidentialite.html','Confidentialité et cookies',"Ce que nous collectons sur ce site et dans la démo, pourquoi, combien de temps, et vos droits.",cf))

# ---------- CGV
cg=''.join([
 sec('1. Objet',P(f"Les présentes conditions générales régissent l'accès au logiciel Amaya, édité par {ED['nom']} (« l'Éditeur »), par les entreprises qui le souscrivent (« le Client »). Elles s'adressent exclusivement à des professionnels. Elles prévalent sur tout autre document, sauf conditions particulières signées par les deux parties.")),
 sec('2. Le service',P("Amaya est un logiciel accessible en ligne (mode SaaS) qui couvre la gestion d'une agence d'animation commerciale, d'hôtessariat et de merchandising : planning, missions, contrats, prise de poste, comptes rendus, rapports aux marques, devis, factures et préparation de la paie. Toutes les fonctionnalités sont incluses dans chaque formule ; le prix dépend du nombre d'intervenants actifs, du nombre d'administrateurs et du niveau de service choisi."),
   P("Une démonstration gratuite de 7 jours, sur une agence fictive, est proposée sans engagement.")),
 sec('3. Commande et durée',ul("<b>Abonnement mensuel</b> : sans durée minimale, résiliable à tout moment, avec effet à la fin du mois en cours.",
   "<b>Abonnement annuel</b> : souscrit pour 12 mois, renouvelé par tacite reconduction pour la même durée, sauf résiliation notifiée au moins 1 mois avant l'échéance.",
   "Le contrat prend effet à l'ouverture des accès, après acceptation du devis ou du bon de commande.")),
 sec('4. Prix',P("Les prix sont indiqués hors taxes, en euros ; la TVA au taux en vigueur s'ajoute. Le nombre d'intervenants actifs et d'administrateurs est relevé chaque mois ; un dépassement de palier est facturé à partir du mois suivant. Les prix peuvent être révisés une fois par an, sur information écrite au moins 2 mois avant l'échéance ; le Client peut alors résilier sans frais.")),
 sec('5. Facturation et paiement',P("Les abonnements sont facturés d'avance, mensuellement ou annuellement selon la formule. Les factures sont payables à 30 jours date de facture, par virement ou prélèvement."),
   P("Tout retard entraîne de plein droit des pénalités égales à trois fois le taux d'intérêt légal, ainsi qu'une indemnité forfaitaire pour frais de recouvrement de 40 € (articles L.441-10 et D.441-5 du Code de commerce). Après mise en demeure restée sans effet pendant 15 jours, l'Éditeur peut suspendre l'accès au service.")),
 sec('6. Niveaux de service et assistance',P("L'Éditeur met en œuvre les moyens nécessaires à la disponibilité du service 24 h/24 et 7 j/7, hors maintenances, annoncées à l'avance et réalisées autant que possible en dehors des heures ouvrées. L'assistance est assurée par e-mail les jours ouvrés ; les délais de prise en charge dépendent du niveau de service souscrit et sont précisés au devis.")),
 sec('7. Obligations du Client',ul("Désigner ses administrateurs, garder confidentiels les identifiants et activer la double authentification pour les comptes de direction.",
   "Rester seul responsable du respect du droit du travail et du droit social : choix et rédaction de ses modèles de contrats, déclarations préalables à l'embauche, paie, attestations de fin de contrat. Amaya prépare des documents et des données à partir de ce que saisit le Client ; il ne se substitue ni à son conseil ni à son expert-comptable.",
   "Informer ses intervenants et ses contacts du traitement de leurs données et disposer d'une base légale pour chaque traitement (voir l'accord de traitement des données).",
   "Ne pas utiliser le service à des fins illicites, ni tenter d'en compromettre la sécurité.")),
 sec('8. Données et réversibilité',P("Les données saisies par le Client restent sa propriété. L'Éditeur les traite comme sous-traitant, selon l'<a href=\"traitement-donnees.html\">accord de traitement des données</a> qui fait partie du contrat."),
   P("À tout moment, et pendant 30 jours après la fin du contrat, le Client peut télécharger la sauvegarde de sa base et des exports Excel, et obtenir sur demande une copie de ses photos et documents. Les données sont ensuite supprimées ; les sauvegardes sont effacées au fil de leur rotation, au plus tard 12 mois après.")),
 sec('9. Responsabilité',P("L'Éditeur est tenu d'une obligation de moyens. Sa responsabilité, toutes causes confondues, est limitée aux sommes payées par le Client au titre des 12 mois précédant le fait générateur. Elle ne peut être engagée pour les dommages indirects (perte de chiffre d'affaires, de clientèle, d'image), ni pour les conséquences des informations saisies par le Client ou de l'usage qu'il fait des documents produits.")),
 sec('10. Propriété intellectuelle',P("Le logiciel, sa documentation et la marque Amaya restent la propriété de l'Éditeur. Le Client reçoit un droit d'utilisation personnel, non exclusif et non cessible, pour la durée du contrat et pour les besoins de son activité.")),
 sec('11. Confidentialité',P("Chaque partie garde confidentielles les informations de l'autre obtenues à l'occasion du contrat, pendant sa durée et 5 ans après.")),
 sec('12. Résiliation',P("En cas de manquement grave d'une partie, non réparé 30 jours après mise en demeure, l'autre partie peut résilier le contrat de plein droit par lettre recommandée ou e-mail avec accusé de réception.")),
 sec('13. Force majeure',P("Aucune partie n'est responsable d'un manquement causé par un événement de force majeure au sens de l'article 1218 du Code civil.")),
 sec('14. Droit applicable et litiges',P(f"Le contrat est soumis au droit français. Les parties recherchent d'abord une solution amiable ; à défaut, le litige est porté devant le tribunal de commerce de {ED['trib']}.")),
])
open(f'{OUT}/cgv.html','w').write(page('cgv.html','Conditions générales',"Conditions d'abonnement au logiciel Amaya, réservées aux professionnels.",cg))

# ---------- DPA
dp=''.join([
 sec('Parties et objet',P(f"Le présent accord, conclu en application de l'article 28 du règlement (UE) 2016/679 (RGPD), fait partie du contrat d'abonnement à Amaya. Il lie l'agence cliente, <b>responsable du traitement</b>, et {ED['nom']}, <b>sous-traitant</b>, pour les données personnelles traitées dans Amaya.")),
 sec('Description du traitement','<div class="tw"><table>'
   '<tr><th>Nature et finalités</th><td>Hébergement et fonctionnement du logiciel de gestion de l\'agence : recrutement et fiches des intervenants, planning et missions, contrats, prise de poste, comptes rendus et photos, rapports aux marques, formation, préparation de la paie et des frais, facturation.</td></tr>'
   '<tr><th>Personnes concernées</th><td>Intervenants (salariés, candidats, auto-entrepreneurs), utilisateurs de l\'agence, interlocuteurs des clients et des marques, personnel des points de vente cité dans les comptes rendus.</td></tr>'
   '<tr><th>Catégories de données</th><td>Identité, coordonnées, date et lieu de naissance, n° de sécurité sociale et IBAN (chiffrés), pièces justificatives (chiffrées), situation professionnelle, disponibilités, missions, heures, rémunérations et frais, photos (profil, selfie de prise de poste, rayons), position au moment de la prise de poste, comptes rendus, échanges. Aucune donnée de santé n\'est demandée par le logiciel.</td></tr>'
   '<tr><th>Durée</th><td>Celle du contrat d\'abonnement, puis la période de restitution prévue ci-dessous.</td></tr></table></div>'),
 sec('Engagements du sous-traitant',ul("Traiter les données uniquement sur instruction documentée du responsable du traitement (le contrat, le paramétrage et l'usage du logiciel valent instructions), et l'informer si une instruction lui paraît contraire à la réglementation.",
   "Garantir la confidentialité : seules les personnes habilitées de l'éditeur y accèdent, sous engagement de confidentialité, et uniquement pour l'assistance demandée ou la maintenance.",
   "Ne jamais utiliser les données pour son propre compte, ni les vendre, ni les croiser avec celles d'une autre agence.",
   "Aider le responsable du traitement à répondre aux demandes des personnes : le logiciel permet de consulter et corriger la fiche d'un intervenant, d'exporter les données (exports Excel, sauvegarde complète) et d'effacer un intervenant en un clic (fiche, pièces, photos de ses comptes rendus d'animation ; ses comptes rendus conservés sont anonymisés), de choisir photo par photo celles transmises aux marques et de purger automatiquement les comptes rendus d'animation au terme de la durée de conservation.",
   "Aider le responsable du traitement pour ses analyses d'impact et la consultation éventuelle de la CNIL, en lui fournissant les informations utiles sur le service.",
   "Tenir un registre des traitements effectués pour le compte de ses clients (article 30.2 du RGPD).")),
 sec('Mesures de sécurité (article 32)',ul("Connexion chiffrée (HTTPS) et cookies de session sécurisés.","Chiffrement AES-256 du n° de sécurité sociale, de l'IBAN, des pièces d'identité, des contrats et des documents de paie archivés.",
   "Mots de passe hachés, double authentification obligatoire pour tous les comptes d'administration, droits d'accès par administrateur, procédure de secours réservée à qui a accès à l'hébergement.","Une installation et une base de données distinctes par agence.",
   "Journal des consultations, par la direction, des pièces d'identité et des documents salariaux.",f"Sauvegarde chiffrée quotidienne conservée 14 jours et une sauvegarde mensuelle conservée un an ; base, fichiers et configuration copiés chaque jour hors du serveur, {F('dans un autre centre de données')}, chiffrés avec une clé distincte, restauration vérifiée chaque semaine.",
   "Mises à jour signées par l'éditeur, vérifiées avant installation.")),
 sec('Sous-traitants ultérieurs',P(f"Le responsable du traitement autorise le recours au sous-traitant ultérieur suivant : <b>OVH SAS</b> (hébergement, stockage de la copie des sauvegardes et envoi des e-mails, centres de données {F('en France')}). Tout ajout ou remplacement lui est notifié au moins 30 jours à l'avance ; il peut s'y opposer et, à défaut d'accord, résilier sans frais. Chaque sous-traitant ultérieur est tenu aux mêmes obligations."),
   P("Le logiciel interroge aussi des services publics de cartographie (Base Adresse Nationale, OpenStreetMap, calcul d'itinéraires) avec les seules adresses des points de vente, sans donnée sur les personnes."),
   P("Aucune donnée personnelle n'est transférée hors de l'Union européenne. La fonction facultative de rédaction des synthèses par IA (Anthropic, États-Unis) est désactivée par défaut ; elle n'est activée qu'à la demande écrite de l'agence, après signature d'un avenant précisant les données transmises et les garanties du transfert.")),
 sec('Violation de données',P("Le sous-traitant notifie au responsable du traitement toute violation de données personnelles dans un délai maximal de 48 heures après en avoir pris connaissance, avec les informations disponibles (nature, catégories et volume approximatif de données, conséquences probables, mesures prises), afin qu'il puisse, le cas échéant, notifier la CNIL dans les 72 heures et informer les personnes concernées.")),
 sec('Fin du contrat',P("Pendant 30 jours après la fin du contrat, le responsable du traitement peut télécharger la sauvegarde de sa base et des exports Excel, et obtenir sur demande une copie de ses photos et documents. Les données sont ensuite supprimées de la base et des fichiers ; elles disparaissent des sauvegardes, y compris de la copie hors du serveur, au fil de leur rotation, au plus tard 12 mois après. Une attestation de suppression est remise sur demande.")),
 sec('Audits',P("Le sous-traitant met à disposition les informations nécessaires pour démontrer le respect de ses obligations. Le responsable du traitement peut faire réaliser un audit, à ses frais, une fois par an, sur préavis de 30 jours, par lui-même ou par un auditeur tenu au secret et non concurrent.")),
 sec('Obligations du responsable du traitement',ul("Informer les personnes concernées (les intervenants disposent dans l'appli de la page « Vos données personnelles », à compléter de sa propre politique).",
   "Disposer d'une base légale pour chaque traitement et recueillir les consentements nécessaires, notamment pour la diffusion des photos aux marques.",
   "Ne saisir que les données nécessaires et fixer les durées de conservation adaptées.",
   "Désigner les administrateurs et gérer leurs droits d'accès.")),
 sec('Contact',P(f"Pour toute question relative à cet accord : <a href=\"mailto:{EMAIL}\">{EMAIL}</a>.")),
])
open(f'{OUT}/traitement-donnees.html','w').write(page('traitement-donnees.html','Accord de traitement des données',"Les engagements d'Amaya, sous-traitant au sens de l'article 28 du RGPD, pour les données de votre agence.",dp))

css=''':root{--bg:#F6F5FF;--paper:#FFFFFF;--ink:#16123A;--ink-2:#4A4570;--muted:#6E6A8F;--line:#E4E1F5;--violet:#6A4BFF;--rose:#FF5C8A;--soft:#EEEAFF;--band:#16123A;--band-ink:#FFFFFF;--band-mute:#BDB6EA;--fx:#FFF1B8;--fx-ink:#5A4300;
--display:"Bricolage Grotesque","Figtree",system-ui,sans-serif;--body:"Figtree",system-ui,-apple-system,"Segoe UI",sans-serif;color-scheme:light}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0F0D24;--paper:#1A1738;--ink:#F1EFFF;--ink-2:#CFCAF2;--muted:#A6A0CF;--line:#2E2A55;--soft:#251F52;--violet:#8B72FF;--rose:#FF7AA0;--band:#07061A;--fx:#4A3D0B;--fx-ink:#FFE8A3;color-scheme:dark}}
:root[data-theme="dark"]{--bg:#0F0D24;--paper:#1A1738;--ink:#F1EFFF;--ink-2:#CFCAF2;--muted:#A6A0CF;--line:#2E2A55;--soft:#251F52;--violet:#8B72FF;--rose:#FF7AA0;--band:#07061A;--fx:#4A3D0B;--fx-ink:#FFE8A3;color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:400 16.5px/1.6 var(--body);-webkit-font-smoothing:antialiased}
a{color:var(--violet);font-weight:600;text-underline-offset:3px}
a:focus-visible{outline:3px solid var(--rose);outline-offset:3px;border-radius:4px}
.w{max-width:880px;margin:0 auto;padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px))}
.nav{position:sticky;top:0;z-index:5;background:var(--band);color:var(--band-ink)}
.nav .w{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:64px;max-width:1180px}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none;font:800 22px/1 var(--display);letter-spacing:.02em;color:var(--band-ink)}
.logo svg{width:32px;height:32px}
.btn{display:inline-flex;align-items:center;padding:10px 16px;border-radius:12px;background:linear-gradient(120deg,var(--violet),var(--rose));color:#fff;text-decoration:none;font-weight:700;font-size:15px;white-space:nowrap}
.doc{padding-top:28px;padding-bottom:56px}
.tabs{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:28px}
.tabs a{padding:8px 12px;border-radius:999px;border:1px solid var(--line);background:var(--paper);color:var(--ink-2);text-decoration:none;font-size:14px}
.tabs a[aria-current=page]{background:var(--ink);color:var(--bg);border-color:var(--ink)}
h1{font:800 clamp(30px,5vw,44px)/1.08 var(--display);letter-spacing:-.025em;margin:0 0 10px}
.lead{font-size:18px;color:var(--ink-2);margin:0 0 6px}
.maj{font-size:14px;color:var(--muted);margin:0 0 24px}
section{background:var(--paper);border:1px solid var(--line);border-radius:16px;padding:18px 20px;margin-bottom:12px}
h2{font:700 19px/1.25 var(--display);margin:0 0 8px}
p{margin:0 0 8px}p:last-child{margin-bottom:0}
ul{margin:0;padding-left:20px}li{margin-bottom:6px}
.tw{overflow-x:auto;margin:4px -4px 0}
table{border-collapse:collapse;width:100%;font-size:14.5px;min-width:560px}
th,td{text-align:left;vertical-align:top;padding:9px 8px;border-top:1px solid var(--line)}
th{font-weight:700;color:var(--ink)}tr:first-child th,tr:first-child td{border-top:0}
mark.fx{background:var(--fx);color:var(--fx-ink);padding:0 3px;border-radius:4px}
footer{padding:28px 0 40px;font-size:14px;color:var(--muted)}
footer .w{display:flex;flex-wrap:wrap;justify-content:space-between;gap:10px;max-width:1180px}
@media (max-width:560px){.btn{padding:9px 12px;font-size:14px}.logo{font-size:19px}}
'''
open(f'{OUT}/legal.css','w').write(css)
print('ok')
