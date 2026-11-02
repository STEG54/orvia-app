<?php
// Administration (la direction seulement) : clients, devis, factures.
require_once __DIR__ . '/lib.php';

const G_PAIEMENTS = [
  'reception' => 'À réception de facture', 'comptant' => 'Comptant', '30j' => '30 jours date de facture', '30jfm' => '30 jours fin de mois',
  '45j' => '45 jours date de facture', '45jfm' => '45 jours fin de mois', '60j' => '60 jours date de facture',
];
/** Tarifs par défaut (modifiables dans Réglages) : prix d'une journée, horaires, fériés payés, lignes à ajouter (reporting, prime). */
const G_TARIFS = [
  ['id' => 'base', 'nom' => 'Animation (tarif de base)', 'prix' => 185, 'debut' => '10:00', 'fin' => '18:00', 'pause' => 60, 'poste' => 'Animation', 'feries_payes' => false, 'extras' => []],
];
/** Tarifs enregistrés, nettoyés. */
function g_tarifs(?string $json = null): array {
  $L = json_decode((string)($json ?? ''), true); if (!is_array($L) || !$L) $L = G_TARIFS; $out = [];
  foreach (array_slice($L, 0, 60) as $i => $t) {
    $nom = clip($t['nom'] ?? '', 100); if ($nom === '') continue;
    $out[] = ['id' => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($t['id'] ?? ''))) ?: 't' . $i . substr(md5($nom), 0, 4), 'nom' => $nom, 'prix' => g_num($t['prix'] ?? 0),
      'debut' => preg_match('/^\d{2}:\d{2}$/', $t['debut'] ?? '') ? $t['debut'] : '10:00', 'fin' => preg_match('/^\d{2}:\d{2}$/', $t['fin'] ?? '') ? $t['fin'] : '18:00',
      'pause' => max(0, min(240, (int)($t['pause'] ?? 60))), 'poste' => clip($t['poste'] ?? '', 160), 'feries_payes' => !empty($t['feries_payes']),
      'extras' => array_values(array_filter(array_map(fn($x) => ['designation' => clip($x['designation'] ?? '', 300), 'pu' => g_num($x['pu'] ?? 0)], (array)($t['extras'] ?? [])), fn($x) => $x['designation'] !== ''))];
  }
  return $out;
}
const G_REFS = ['' => 'Aucune', 'PO' => 'N° de PO', 'DED' => 'N° DED', 'ED' => 'N° ED', 'BC' => 'N° bon de commande'];
const G_TVA = ['20' => '20 %', '2.1' => '2,1 %', '0' => 'Exonéré (0 %)'];
// Mention obligatoire quand la TVA n'est pas facturée (choisie automatiquement d'après le pays du client, modifiable sur le document).
const G_TVA_MENTIONS = ['' => 'Automatique (selon le pays du client)', 'hors_ue' => 'Client hors UE : TVA non applicable, art. 259-1 du CGI', 'ue' => 'Client UE : autoliquidation', 'aucune' => 'Aucune mention'];
const G_TVA_TEXTES = ['hors_ue' => 'TVA non applicable – art. 259-1 du CGI', 'ue' => 'Autoliquidation – art. 283-2 du CGI / art. 196 de la directive 2006/112/CE'];
const G_UE = ['allemagne', 'autriche', 'belgique', 'bulgarie', 'chypre', 'croatie', 'danemark', 'espagne', 'estonie', 'finlande', 'grece', 'hongrie', 'irlande', 'italie', 'lettonie', 'lituanie', 'luxembourg', 'malte', 'pays-bas', 'pays bas', 'pologne', 'portugal', 'republique tcheque', 'tchequie', 'roumanie', 'slovaquie', 'slovenie', 'suede'];
/** Mention de TVA à imprimer (texte vide si la TVA est facturée). */
function g_tva_mention(array $d): string {
  if ((float)($d['tva'] ?? 20) > 0) return '';
  $m = (string)($d['tva_mention'] ?? '');
  if ($m === '') { $p = strtolower(trim(iconv('UTF-8', 'ASCII//TRANSLIT', (string)($d['client']['pays'] ?? 'France')))); $m = ($p === '' || $p === 'france') ? '' : (in_array($p, G_UE, true) ? 'ue' : 'hors_ue'); }
  return G_TVA_TEXTES[$m] ?? '';
}
/** Coordonnées bancaires du document : compte figé sur le document, sinon celui du client (« 2 » = second compte). */
function g_banque(array $d, array $r): array {
  $k = $d['compte'] ?? null;
  if ($k === null || $k === '') { $k = ''; if (!empty($d['client_id'])) { $st = db()->prepare("SELECT compte FROM g_clients WHERE id = ?"); $st->execute([(int)$d['client_id']]); $k = (string)$st->fetchColumn(); } }
  return $k === '2' && trim((string)$r['iban2']) !== '' ? [$r['banque2'], $r['iban2'], $r['bic2']] : [$r['banque'], $r['iban'], $r['bic']];
}
const G_STATUTS = ['brouillon' => 'Brouillon', 'envoye' => 'Envoyé', 'accepte' => 'Accepté', 'refuse' => 'Refusé', 'emise' => 'Émise', 'payee' => 'Payée'];
// Réglages de la société : modifiables dans l'onglet Administration > Réglages.
const G_REGLAGES = [
  // Identité de l'agence : saisie par l'assistant d'installation, modifiable dans Administration > Réglages.
  'societe' => AG_NOM, 'adresse' => '', 'contact' => '', 'telephone' => '', 'email' => AG_EMAIL, 'site' => '', 'legal' => '',
  // Compte principal ; second compte facultatif, au choix dans la fiche de chaque client.
  'banque' => '', 'iban' => '', 'bic' => '', 'banque2' => '', 'iban2' => '', 'bic2' => '',
  'repas' => '10,30', 'km' => '0,29', 'repas_seuil' => '6',
  // Frais propres à l'accueil et à l'hôtessariat (vide = comme l'animation) : panier de nuit pour les fins de service tardives, entretien de la tenue par journée.
  'repas_hot' => '', 'km_hot' => '', 'repas_seuil_hot' => '', 'panier_hot' => '', 'panier_apres_hot' => '22:00', 'tenue_hot' => '',
  'mention_presta' => "Prestation forfaitaire comprenant : recrutement, formation, reporting, suivi terrain.\nRepas ({repas} €) et forfait kilométrique ({km} €/km) compris.",
  'penalites' => "Pas d'escompte. Tout retard de paiement entraîne, de plein droit et dès le premier jour de retard, l'application de pénalités de retard équivalentes au taux d'intérêt appliqué par la Banque centrale européenne à son opération de refinancement la plus récente majoré de dix (10) points de pourcentage, et la facturation au Client d'une indemnité forfaitaire de quarante (40) euros H.T. pour frais de recouvrement (articles L.441-10 et D.441-5 du Code de commerce). Si les frais de recouvrement exposés sont supérieurs au montant de cette indemnité forfaitaire, le prestataire se réserve le droit de demander une indemnité complémentaire, sur justification.",
  'devis_prefixe' => 'DEV-', 'devis_prochain' => '1', 'facture_prefixe' => 'FAC-', 'facture_prochain' => '1', 'validite_jours' => '30',
  'signataire' => '', 'cgv' => '', 'tarifs' => '',
  'mail_devis' => "Bonjour {prenom},\n\nPour valider le devis {numero} relatif à {objet}, d'un montant de {montant_ht} HT, je vous invite à vous rendre directement sur notre espace clients afin de le valider en ligne :\n{lien}\n\nVous pourrez ainsi confirmer votre accord simplement et directement depuis la plateforme.\n\nJe reste à votre disposition si besoin.\n\nBien cordialement,\n\n{signature}",
  'mail_facture' => "Bonjour {prenom},\n\nVeuillez trouver ci-joint notre facture {numero} relative à {objet}, d'un montant de {montant_ttc} TTC, payable {paiement} (échéance le {echeance}).\n\nLes coordonnées bancaires figurent sur la facture.\n\nJe reste à votre disposition si besoin.\n\nBien cordialement,\n\n{signature}",
  'signature' => AG_NOM, 'notif_emails' => AG_EMAIL,
  // Contrats de travail
  // SMIC horaire brut (12,31 € au 1er juin 2026) : aucun taux horaire de contrat ni de proposition de CDII ne peut être inférieur.
  'smic_horaire' => '12,31',
  'ct_taux' => '12,31', 'ct_coef' => '140', 'ct_representant' => '', 'ct_qualite' => 'Gérant(e)', 'ct_lieu' => '', 'ct_urssaf' => '',
  'ct_forme' => '', 'ct_caisse' => '', 'ct_retraite' => '', 'ct_dernier' => '0', 'ct_poste' => 'Animateur(trice) commercial(e)', 'charges_pat' => '6,7', 'cotis_sal' => '21,8',
  // ct_caisse : mutuelle et prévoyance ; ct_retraite : retraite complémentaire (vide = même organisme).
  // Coefficient : débutant(e) puis confirmé(e) après quelques mois (personnes arrivées après la mise en place : id au-delà de ct_coef_id). Alerte km : aller-retour par jour.
  'ct_coef_debut' => '120', 'ct_coef_mois' => '6', 'ct_coef_id' => '0', 'km_alerte' => '250',
  // Pôles : animation commerciale (CIDD) et accueil & hôtessariat (CDDU). Postes proposés dans les devis, un par ligne.
  'poles' => 'anim,hot', 'siret' => '',
  'postes_anim' => "Animateur(trice) commercial(e)\nDémonstrateur(trice)\nAmbassadeur(drice) de marque\nConseiller(ère) de vente\nVendeur(se)\nChef de file\nFormateur(trice)\nMerchandiseur(se)",
  'postes_hot' => "Chef d'équipe\nHôte(sse) d'accueil\nHôte(sse) d'accueil bilingue\nHôte(sse) VIP\nHôte(sse) d'accueil en entreprise\nHôte(sse) vestiaire\nHôte(sse) de salon\nHôte(sse) événementiel(le)\nRégisseur(se)\nGuide\nHôte(sse) street marketing\nStandardiste\nMaître(sse) d'hôtel\nChef de service\nPersonnel annexe (manutention, badges, logistique)\nDistributeur(trice) de flyers\nAnimateur(trice) sampling",
  'charges_pat_hot' => '6,7', 'hot_taux' => '12,31',
  // Contrat CDDU : aucun texte fourni par Amaya. Chaque agence saisit son propre modèle, validé par son avocat et adapté à sa convention collective ;
  // l'envoi est bloqué tant que le modèle est vide ou que hot_valide n'est pas coché.
  'hot_convention' => '', 'hot_modele' => '', 'hot_valide' => '',
  'ct_convention' => '', 'ct_modele' => '', 'ct_valide' => '',
  // Règles de paye par pôle (regles_lib.php) : vide = valeur par défaut.
  'rg_anim_hs_seuil' => '', 'rg_anim_hs_t1' => '', 'rg_anim_hs_t1_max' => '', 'rg_anim_hs_t2' => '', 'rg_anim_dim' => '', 'rg_anim_ferie' => '', 'rg_anim_nuit' => '', 'rg_anim_nuit_debut' => '', 'rg_anim_nuit_fin' => '', 'rg_anim_zti' => '', 'rg_anim_cumul' => '', 'rg_hot_hs_seuil' => '', 'rg_hot_hs_t1' => '', 'rg_hot_hs_t1_max' => '', 'rg_hot_hs_t2' => '', 'rg_hot_dim' => '', 'rg_hot_ferie' => '', 'rg_hot_nuit' => '', 'rg_hot_nuit_debut' => '', 'rg_hot_nuit_fin' => '', 'rg_hot_zti' => '', 'rg_hot_cumul' => '',
  // Proposition de CDI intermittent (règle des 500 heures) : lettre de l'agence, à faire valider.
  'cdii_modele' => '', 'cdii_valide' => '', 'cdii_alerte' => '400',
];
// Lettre de proposition de CDI intermittent (règle des 500 heures, CIDD) : point de départ que l'agence relit, adapte et fait valider.
const RH_CDII_DEFAUT = "Objet : proposition d'un contrat de travail intermittent à durée indéterminée

{Civilite} {nom},

Du {debut_ref} au {fin_ref}, vous avez travaillé {heures} heures au sein de {societe}, au titre de plusieurs contrats d'intervention à durée déterminée. Conformément à l'article 12 de l'avenant du 13 février 2006 relatif à l'animation commerciale ({convention}), nous vous proposons un contrat de travail intermittent à durée indéterminée.

Les conditions proposées sont les suivantes :
- Emploi : {emploi}
- Durée annuelle minimale de travail : {volume} heures
- Taux horaire brut : {taux}
- Rémunération : {remuneration}
- Périodes de travail : {periodes}
- Prise d'effet : {prise_effet}

Vous disposez de quinze jours calendaires à compter de la première présentation de ce courrier pour nous faire connaître votre réponse, par lettre recommandée avec accusé de réception ou par remise en main propre contre décharge. Sans réponse de votre part dans ce délai, cette proposition sera considérée comme refusée.

Nous restons à votre disposition pour tout renseignement.";
// Types d'opération, par pôle : affichés sur les opérations, les plannings et les rapports.
const G_TYPES = ['anim' => ['Animation commerciale', 'Démonstration, dégustation', 'Lancement produit', 'Formation, coaching', 'Merchandising', 'Merchandising : implantation et réassort', 'Merchandising : relevés de prix et de linéaire', 'Merchandising : nettoyage et entretien des meubles', 'Merchandising : promotion et PLV'],
  'hot' => ['Salon, foire, exposition', 'Événement d\'entreprise', 'Soirée, gala', 'Congrès, séminaire', 'Accueil en entreprise', 'Événement sportif ou culturel', 'Street marketing, sampling', 'Lancement, inauguration']];
/** Frais des intervenants pour un pôle : repas (et seuil d'heures), km, panier de nuit et entretien de tenue (accueil). */
function g_frais(string $pole, ?array $r = null): array {
  $r ??= g_reg(); $n = fn($k, $d = '0') => (float)str_replace([',', ' '], ['.', ''], trim((string)($r[$k] ?? '')) !== '' ? (string)$r[$k] : $d);
  $h = $pole === 'hot'; $base = ['repas' => $n('repas'), 'km' => $n('km'), 'seuil' => $n('repas_seuil', '6'), 'panier' => 0.0, 'panier_apres' => '22:00', 'tenue' => 0.0];
  if (!$h) return $base;
  return ['repas' => trim((string)($r['repas_hot'] ?? '')) !== '' ? $n('repas_hot') : $base['repas'], 'km' => trim((string)($r['km_hot'] ?? '')) !== '' ? $n('km_hot') : $base['km'],
    'seuil' => trim((string)($r['repas_seuil_hot'] ?? '')) !== '' ? $n('repas_seuil_hot') : $base['seuil'], 'panier' => $n('panier_hot'),
    'panier_apres' => preg_match('/^\d{2}:\d{2}$/', (string)($r['panier_apres_hot'] ?? '')) ? $r['panier_apres_hot'] : '22:00', 'tenue' => $n('tenue_hot')];
}
/** Journées dont la fin est tardive (après l'heure donnée ou après minuit). */
function g_jours_tardifs(array $L, string $apres): int { $o = []; foreach ($L as $l) { $f = g_hm((string)($l['fin'] ?? '')); $d = g_hm((string)($l['debut'] ?? '')); if ($f > g_hm($apres) || ($f < $d)) $o[$l['date']] = 1; } return count($o); }
/** Opération de merchandising : fiche de visite dans l'appli (photos avant/après, relevés) au lieu du compte rendu d'animation. */
function g_est_merch(?string $type_op, ?string $role = ''): bool { return str_starts_with((string)$type_op, 'Merchandising') || (bool)preg_match('/merchandis/i', (string)$role); }
const G_POLES = ['anim' => 'Animation commerciale (CIDD)', 'hot' => 'Accueil & Hôtessariat (CDDU)'];
/** Pôle d'un devis ou d'une facture (les documents d'avant les pôles sont de l'animation). */
function g_pole(?array $d): string { $p = (string)($d['pole'] ?? 'anim'); return isset(G_POLES[$p]) ? $p : 'anim'; }
/** Pôles proposés par l'agence (au moins un). */
function g_poles_actifs(array $r): array { $a = array_values(array_intersect(array_keys(G_POLES), array_map('trim', explode(',', (string)($r['poles'] ?? 'anim'))))); return $a ?: ['anim']; }

function g_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  $E = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"; $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS g_reglages (cle VARCHAR(40) PRIMARY KEY, valeur MEDIUMTEXT NOT NULL) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS g_clients (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, raison VARCHAR(160) NOT NULL, contact VARCHAR(160) NOT NULL DEFAULT '',
    adresse VARCHAR(400) NOT NULL DEFAULT '', cp VARCHAR(20) NOT NULL DEFAULT '', ville VARCHAR(120) NOT NULL DEFAULT '', pays VARCHAR(80) NOT NULL DEFAULT 'France',
    emails VARCHAR(600) NOT NULL DEFAULT '', cc VARCHAR(600) NOT NULL DEFAULT '', tva_intra VARCHAR(40) NOT NULL DEFAULT '', tva VARCHAR(6) NOT NULL DEFAULT '20',
    paiement VARCHAR(10) NOT NULL DEFAULT '30j', marque_id INT UNSIGNED NULL, notes TEXT NULL, actif TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL) $E");
  $pdo->exec("CREATE TABLE IF NOT EXISTS g_docs (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type VARCHAR(10) NOT NULL, numero VARCHAR(30) NULL UNIQUE, devis_id INT UNSIGNED NULL,
    client_id INT UNSIGNED NULL, client MEDIUMTEXT NULL, date_doc DATE NOT NULL, validite DATE NULL, echeance DATE NULL, objet VARCHAR(300) NOT NULL DEFAULT '', zone VARCHAR(20) NOT NULL DEFAULT '',
    paiement VARCHAR(10) NOT NULL DEFAULT '30j', acompte DECIMAL(5,2) NOT NULL DEFAULT 0, tva VARCHAR(6) NOT NULL DEFAULT '20', lignes MEDIUMTEXT NULL, mention TEXT NULL, notes TEXT NULL,
    total_ht DECIMAL(12,2) NOT NULL DEFAULT 0, total_tva DECIMAL(12,2) NOT NULL DEFAULT 0, total_ttc DECIMAL(12,2) NOT NULL DEFAULT 0, statut VARCHAR(12) NOT NULL DEFAULT 'brouillon',
    envoye_le DATETIME NULL, envoye_a VARCHAR(600) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, modifie_le DATETIME NULL,
    INDEX (type, date_doc), INDEX (client_id)) $E");
  if (!in_array('tarif', $pdo->query("SHOW COLUMNS FROM g_clients")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE g_clients ADD COLUMN tarif VARCHAR(40) NOT NULL DEFAULT ''");
  $cols = $pdo->query("SHOW COLUMNS FROM g_docs")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('accepte_le', $cols, true)) {
    // Validation en ligne par la marque (espace marques) ; un devis et sa facture portent le même numéro.
    $pdo->exec("ALTER TABLE g_docs ADD COLUMN accepte_le DATETIME NULL, ADD COLUMN accepte_par VARCHAR(250) NOT NULL DEFAULT '', ADD COLUMN accepte_ip VARCHAR(60) NOT NULL DEFAULT '', ADD COLUMN refus_motif TEXT NULL");
    foreach ($pdo->query("SHOW INDEX FROM g_docs WHERE Column_name = 'numero' AND Non_unique = 0")->fetchAll() as $ix) $pdo->exec("ALTER TABLE g_docs DROP INDEX `" . $ix['Key_name'] . "`");
    $pdo->exec("ALTER TABLE g_docs ADD UNIQUE KEY type_numero (type, numero)");
  }
  $hm = (string)$pdo->query("SELECT valeur FROM g_reglages WHERE cle = 'hot_modele'")->fetchColumn();
  if ($hm !== '' && str_contains($hm, "## Nature du contrat") && str_contains($hm, "Rendre compte de sa mission dans l'application {appli}")) $pdo->exec("DELETE FROM g_reglages WHERE cle IN ('hot_modele', 'hot_valide')");
  if (!in_array('pole', $pdo->query("SHOW COLUMNS FROM g_docs")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE g_docs ADD COLUMN pole VARCHAR(4) NOT NULL DEFAULT 'anim'");
  if (!in_array('type_op', $pdo->query("SHOW COLUMNS FROM g_docs")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE g_docs ADD COLUMN type_op VARCHAR(80) NOT NULL DEFAULT ''");
  foreach ($pdo->query("SHOW COLUMNS FROM g_docs WHERE Field = 'type_op'")->fetchAll() as $col) if (stripos($col['Type'], '(40)') !== false) $pdo->exec("ALTER TABLE g_docs MODIFY type_op VARCHAR(80) NOT NULL DEFAULT ''");
  if (!in_array('ref_type', $pdo->query("SHOW COLUMNS FROM g_docs")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE g_docs ADD COLUMN ref_type VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN ref_num VARCHAR(80) NOT NULL DEFAULT ''");
  if (!in_array('compte', $pdo->query("SHOW COLUMNS FROM g_clients")->fetchAll(PDO::FETCH_COLUMN), true)) $pdo->exec("ALTER TABLE g_clients ADD COLUMN compte VARCHAR(4) NOT NULL DEFAULT ''");
  if (!in_array('tva_mention', $pdo->query("SHOW COLUMNS FROM g_docs")->fetchAll(PDO::FETCH_COLUMN), true))
    $pdo->exec("ALTER TABLE g_docs ADD COLUMN tva_mention VARCHAR(10) NOT NULL DEFAULT '', ADD COLUMN compte VARCHAR(4) NULL, ADD COLUMN avenant_de INT UNSIGNED NULL, ADD COLUMN accepte_preuve VARCHAR(400) NOT NULL DEFAULT ''");
  if (!$pdo->query("SELECT COUNT(*) FROM g_reglages WHERE cle = 'ct_coef_id'")->fetchColumn())
    $pdo->prepare("INSERT INTO g_reglages (cle, valeur) VALUES ('ct_coef_id', ?)")->execute([(string)(int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM animatrices")->fetchColumn()]);
  // (Aucun client pré-rempli : chaque agence crée les siens.)
}
/** Accès réservé : la direction, connectés avec leur propre compte (pas le mot de passe général). */
function g_need(): void {
  if (!admin_ok() || !est_dirigeant_email((string)($_SESSION['admin_email'] ?? ''))) { http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error' => 'Accès réservé à la direction.']); exit; }
  g_schema();
}
function g_acces(): bool { return admin_ok() && est_dirigeant_email((string)($_SESSION['admin_email'] ?? '')); }

function g_reg(): array {
  g_schema(); $r = G_REGLAGES;
  foreach (db()->query("SELECT cle, valeur FROM g_reglages") as $x) if (array_key_exists($x['cle'], $r)) $r[$x['cle']] = $x['valeur'];
  if (trim((string)$r['cdii_modele']) === '') $r['cdii_modele'] = RH_CDII_DEFAUT;
  if (trim($r['cgv']) === '') $r['cgv'] = str_replace(['{societe}', '{signataire}'], [mb_strtoupper($r['societe']), $r['signataire']], (string)@file_get_contents(__DIR__ . '/cgv_defaut.txt'));
  $r['tarifs'] = g_tarifs($r['tarifs']);
  return $r;
}
function g_smic(?array $r = null): float { $r ??= g_reg(); return (float)str_replace([',', ' '], ['.', ''], (string)($r['smic_horaire'] ?? '12,31')) ?: 12.31; }
function g_reg_save(array $in): void {
  // Taux horaires par défaut : jamais sous le SMIC.
  $sm = isset($in['smic_horaire']) ? (float)str_replace([',', ' '], ['.', ''], (string)$in['smic_horaire']) : g_smic();
  foreach (['ct_taux' => 'animation', 'hot_taux' => 'accueil'] as $k => $l) if (isset($in[$k]) && trim((string)$in[$k]) !== '' && (float)str_replace([',', ' '], ['.', ''], (string)$in[$k]) < $sm)
    throw new RuntimeException('Le taux horaire ' . $l . ' (' . $in[$k] . ' €) est inférieur au SMIC horaire (' . number_format($sm, 2, ',', ' ') . ' €).');
  $st = db()->prepare("REPLACE INTO g_reglages (cle, valeur) VALUES (?, ?)");
  foreach (G_REGLAGES as $k => $_) if (array_key_exists($k, $in)) $st->execute([$k, $k === 'tarifs' ? json_encode(g_tarifs(json_encode($in[$k])), JSON_UNESCAPED_UNICODE) : mb_substr(trim((string)$in[$k]), 0, 60000)]);
}

// ---------- Jours fériés ----------
function g_paques(int $y): int { // algorithme de Meeus (calendrier grégorien)
  $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
  $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
  $mo = intdiv($h + $l - 7 * $m + 114, 31); $da = (($h + $l - 7 * $m + 114) % 31) + 1;
  return mktime(12, 0, 0, $mo, $da, $y);
}
/** Jours fériés d'une année pour une zone : [date => libellé]. */
function g_feries(int $y, string $zone): array {
  $p = g_paques($y); $d = fn($t) => date('Y-m-d', $t);
  $f = ["$y-01-01" => "Jour de l'an", $d($p + 86400) => 'Lundi de Pâques', "$y-05-01" => 'Fête du travail', "$y-05-08" => 'Victoire 1945', $d($p + 39 * 86400) => 'Ascension',
    $d($p + 50 * 86400) => 'Lundi de Pentecôte', "$y-07-14" => 'Fête nationale', "$y-08-15" => 'Assomption', "$y-11-01" => 'Toussaint', "$y-11-11" => 'Armistice', "$y-12-25" => 'Noël'];
  ksort($f); return $f;
}

// ---------- Calcul ----------
function g_num($v): float { return round((float)str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string)$v), 2); }
function g_hm(string $s): int { return preg_match('/^(\d{1,2})[:h](\d{2})$/', trim($s), $m) ? (int)$m[1] * 60 + (int)$m[2] : 0; }
function g_dur(int $min): string { return intdiv($min, 60) . 'h' . ($min % 60 ? sprintf('%02d', $min % 60) : ''); }
/** Nettoie les lignes saisies et calcule chaque montant. Ligne « presta » : journées × tarif, les jours majorés à 100 % comptent double. */
function g_lignes(array $in): array {
  $out = [];
  foreach (array_slice($in, 0, 60) as $l) {
    $t = $l['type'] ?? 'libre';
    if ($t === 'presta') {
      $dates = array_values(array_unique(array_filter((array)($l['dates'] ?? []), fn($x) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$x)))); sort($dates);
      $maj = array_values(array_intersect($dates, (array)($l['maj'] ?? [])));
      $fnt = array_values(array_diff(array_intersect($dates, (array)($l['fnt'] ?? [])), $maj)); // fériés non travaillés mais payés
      $pj = g_num($l['prix_jour'] ?? 0);
      // Plusieurs points de vente possibles, toutes zones : mêmes dates et horaires dans chacun.
      $mags = [];
      foreach ((array)($l['magasins'] ?? []) as $m) { $n = clip(is_array($m) ? ($m['nom'] ?? '') : $m, 160); if ($n !== '') $mags[] = ['id' => is_array($m) ? ((int)($m['id'] ?? 0) ?: null) : null, 'nom' => $n]; }
      if (!$mags && trim((string)($l['magasin'] ?? '')) !== '') $mags[] = ['id' => (int)($l['magasin_id'] ?? 0) ?: null, 'nom' => clip($l['magasin'], 160)];
      $out[] = ['type' => 'presta', 'poste' => clip($l['poste'] ?? '', 160), 'role' => clip(trim((string)($l['role'] ?? '')) !== '' ? $l['role'] : 'Animateur(trice) commercial(e)', 120), 'zone' => isset(ZONES[$l['zone'] ?? '']) ? $l['zone'] : '',
        'magasins' => $mags, 'magasin' => implode(', ', array_column($mags, 'nom')), 'debut' => clip($l['debut'] ?? '10:00', 5), 'fin' => clip($l['fin'] ?? '18:00', 5),
        'pause' => max(0, min(240, (int)($l['pause'] ?? (g_hm((string)($l['fin'] ?? '18:00')) - g_hm((string)($l['debut'] ?? '10:00')) <= 360 ? 0 : 60)))), 'dates' => $dates, 'maj' => $maj, 'fnt' => $fnt, 'prix_jour' => $pj, 'tarif' => clip($l['tarif'] ?? '', 40),
        // Merchandising : facturation à la visite, tournée de plusieurs points dans la journée, kilomètres de l'itinéraire facturés.
        'unite' => ($l['unite'] ?? '') === 'visite' ? 'visite' : 'jour', 'tournee' => !empty($l['tournee']) && count($mags) > 1 ? 1 : 0,
        'km' => max(0, min(2000, g_num($l['km'] ?? 0))), 'prix_km' => max(0, min(50, g_num($l['prix_km'] ?? 0)))];
      $out[count($out) - 1]['total'] = g_presta_total($out[count($out) - 1]);
    } elseif ($t === 'texte') {
      $x = clip($l['texte'] ?? '', 2000); if ($x !== '') $out[] = ['type' => 'texte', 'texte' => $x];
    } else {
      $q = g_num($l['qte'] ?? 1); $pu = g_num($l['pu'] ?? 0); $de = clip($l['designation'] ?? '', 600);
      if ($de !== '' || $pu) $out[] = ['type' => 'libre', 'designation' => $de, 'qte' => $q, 'pu' => $pu, 'total' => round($q * $pu, 2)];
    }
  }
  return $out;
}
/** Multiplicateur d'une ligne : un point d'intervention = une journée ou une visite ; en tournée facturée à la journée, une journée pour tous les points. */
function g_presta_mult(array $l): int { $nb = max(1, count($l['magasins'] ?? [])); return !empty($l['tournee']) && ($l['unite'] ?? 'jour') !== 'visite' ? 1 : $nb; }
/** Jours de tournée travaillés (fériés non travaillés exclus) et montant des kilomètres facturés. */
function g_presta_km(array $l): array { $j = count(array_diff($l['dates'] ?? [], $l['fnt'] ?? [])); $km = !empty($l['tournee']) ? (float)($l['km'] ?? 0) : 0.0;
  return ['jours' => $j, 'km' => round($km * $j, 1), 'total' => round($km * $j * (float)($l['prix_km'] ?? 0), 2)]; }
function g_presta_total(array $l): float {
  $pj = (float)$l['prix_jour']; $n = count($l['dates']) - count($l['maj']);
  return round(($n * $pj + count($l['maj']) * 2 * $pj) * g_presta_mult($l) + g_presta_km($l)['total'], 2);
}
function g_totaux(array $lignes, string $tva): array {
  $ht = round(array_sum(array_map(fn($l) => (float)($l['total'] ?? 0), $lignes)), 2); $tv = round($ht * (float)$tva / 100, 2);
  return ['ht' => $ht, 'tva' => $tv, 'ttc' => round($ht + $tv, 2)];
}
/** Date d'échéance selon les conditions de paiement. */
function g_echeance(string $date, string $p): string {
  $t = strtotime($date);
  return match ($p) {
    '30j' => date('Y-m-d', strtotime('+30 days', $t)), '45j' => date('Y-m-d', strtotime('+45 days', $t)), '60j' => date('Y-m-d', strtotime('+60 days', $t)),
    '30jfm' => date('Y-m-t', strtotime('+30 days', $t)), '45jfm' => date('Y-m-t', strtotime('+45 days', $t)), default => $date,
  };
}
function g_eur(float $v): string { return number_format($v, 2, ',', "\u{202F}") . "\u{00A0}€"; }
function g_date(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : ''; }
/** Liste de dates lisible : « 05, 12, 19 et 26/09/2026 » par mois. */
function g_dates_txt(array $dates): string {
  $by = []; foreach ($dates as $d) $by[substr($d, 0, 7)][] = substr($d, 8, 2);
  $parts = []; foreach ($by as $ym => $ds) { $last = array_pop($ds); $parts[] = ($ds ? implode(', ', $ds) . ' et ' : '') . $last . '/' . substr($ym, 5, 2) . '/' . substr($ym, 0, 4); }
  return implode(' ; ', $parts);
}
function g_doc(int $id): ?array {
  g_schema(); $st = db()->prepare("SELECT * FROM g_docs WHERE id = ?"); $st->execute([$id]); $d = $st->fetch(); if (!$d) return null;
  $d['lignes'] = json_decode((string)$d['lignes'], true) ?: []; $d['client'] = json_decode((string)$d['client'], true) ?: [];
  return $d;
}
/** Numéro suivant (devis : à la création ; facture : à l'émission, sans trou). */
/** Numéro de devis : date du jour puis rang dans la journée (20261009/01, 20261009/02…). La facture reprend le numéro de son devis. */
function g_numero(string $type = 'devis'): string {
  $j = date('Ymd'); $chk = db()->prepare("SELECT COUNT(*) FROM g_docs WHERE numero = ?");
  for ($n = 1; $n < 1000; $n++) { $num = $j . '/' . sprintf('%02d', $n); $chk->execute([$num]); if (!$chk->fetchColumn()) return $num; }
  return $j . '/' . substr(uniqid(), -4);
}
/** Texte d'un e-mail type : {prenom}, {numero}, {objet}, {montant_ht}, {montant_ttc}, {paiement}, {echeance}, {lien}, {signature}. */
function g_mail_texte(array $d): string {
  $r = g_reg(); $c = $d['client'];
  $pre = explode(' ', trim((string)($c['contact'] ?? '')))[0] ?? ''; $pre = $pre !== '' ? mb_convert_case(mb_strtolower($pre), MB_CASE_TITLE) : 'Madame, Monsieur';
  $objet = trim((string)$d['objet']); $objet = $objet !== '' ? (preg_match('/^(l[\'’]|la |le |les )/iu', $objet) ? $objet : 'l\'opération ' . $objet) : 'notre prestation';
  return strtr($r[$d['type'] === 'facture' ? 'mail_facture' : 'mail_devis'], ['{prenom}' => $pre, '{numero}' => (string)$d['numero'], '{objet}' => $objet,
    '{montant_ht}' => g_eur((float)$d['total_ht']), '{montant_ttc}' => g_eur((float)$d['total_ttc']), '{paiement}' => mb_strtolower(G_PAIEMENTS[$d['paiement']] ?? ''),
    '{echeance}' => g_date($d['echeance'] ?? null), '{lien}' => rtrim(BASE_URL, '/') . '/marques.php', '{signature}' => $r['signature']]);
}
/** Le compte de l'espace marques voit ce devis s'il en est destinataire (e-mail du client ou adresse d'envoi). */
function g_devis_visible(array $d, string $email): bool {
  $email = mb_strtolower(trim($email)); if ($email === '' || $d['type'] !== 'devis' || $d['statut'] === 'brouillon') return false;
  $L = mb_strtolower(($d['client']['emails'] ?? '') . ',' . ($d['client']['cc'] ?? '') . ',' . ($d['envoye_a'] ?? ''));
  return in_array($email, array_map('trim', explode(',', $L)), true);
}

// ---------- Document PDF ----------
function g_render(array $d): string {
  $r = g_reg(); $c = $d['client']; $fac = $d['type'] === 'facture';
  $T = ['p' => ag_couleur(), 'esp' => '#1A1512', 'sand' => '#F6F1EA', 'line' => '#E6DDD2', 'mut' => '#857869'];
  $nl = fn($s) => nl2br(h($s));
  $tot = ['ht' => (float)$d['total_ht'], 'tva' => (float)$d['total_tva'], 'ttc' => (float)$d['total_ttc']];
  $aven = '';
  if (!empty($d['avenant_de'])) { $st = db()->prepare("SELECT numero, date_doc FROM g_docs WHERE id = ?"); $st->execute([(int)$d['avenant_de']]); if ($p = $st->fetch()) $aven = 'Avenant au devis n° ' . $p['numero'] . ' du ' . g_date($p['date_doc']); }
  $mention = str_replace(['{repas}', '{km}'], [$r['repas'], $r['km']], (string)($d['mention'] ?? $r['mention_presta']));
  $rows = '';
  foreach ($d['lignes'] as $l) {
    if ($l['type'] === 'texte') { $rows .= '<tr><td colspan="4" class="txt">' . $nl($l['texte']) . '</td></tr>'; continue; }
    if ($l['type'] === 'libre') { $rows .= '<tr><td><b>' . $nl($l['designation']) . '</b></td><td class="n">' . g_q((float)$l['qte']) . '</td><td class="n">' . g_eur((float)$l['pu']) . '</td><td class="n b">' . g_eur((float)$l['total']) . '</td></tr>'; continue; }
    $mags = $l['magasins'] ?? ($l['magasin'] ? [['nom' => $l['magasin']]] : []); $nb = g_presta_mult($l); $vis = ($l['unite'] ?? '') === 'visite'; $tour = !empty($l['tournee']);
    $fnt = $l['fnt'] ?? []; $norm = array_values(array_diff($l['dates'], $l['maj'], $fnt)); $amp = g_hm($l['fin']) - g_hm($l['debut']) - (int)$l['pause'];
    $titre = '<b>' . h($l['poste'] ?: 'Animation') . '</b>' . ($l['role'] ? '<span class="mut"> · ' . h($l['role']) . '</span>' : '');
    $lieu = $tour ? '<div class="sub"><b class="dk">Tournée de ' . count($mags) . ' points d\'intervention par jour :</b> ' . h(implode(' → ', array_column($mags, 'nom'))) . '</div>'
      : (count($mags) > 1 ? '<div class="sub"><b class="dk">' . count($mags) . ' points d\'intervention :</b> ' . h(implode(', ', array_column($mags, 'nom'))) . '</div>'
      : (count($mags) === 1 ? '<div class="sub">à <b class="dk">' . h($mags[0]['nom']) . '</b></div>' : ''));
    $hor = 'de ' . h($l['debut']) . ' à ' . h($l['fin']) . ($l['pause'] ? ', dont ' . g_dur($l['pause']) . ' de pause' : '') . ($amp > 0 ? ' · ' . g_dur($amp) . ' de travail' : '');
    $par = $nb > 1 && !$tour ? ' par point d\'intervention' : ''; $uj = fn($n) => $tour ? ($n > 1 ? 'jours de tournée' : 'jour de tournée') : ($vis ? ($n > 1 ? 'visites' : 'visite') : ($n > 1 ? 'journées' : 'journée'));
    $visTxt = $vis ? ' · facturé à la visite (' . ($tour ? count($mags) . ' visites par jour' : 'une visite par point et par date') . ')' : '';
    if ($norm) { $q = count($norm) * $nb;
      $rows .= '<tr><td>' . $titre . $lieu . '<div class="sub">' . count($norm) . ' ' . $uj(count($norm)) . $par . ' : ' . g_dates_txt($norm) . '<br>' . $hor . $visTxt . '</div></td>'
        . '<td class="n">' . $q . '</td><td class="n">' . g_eur((float)$l['prix_jour']) . '</td><td class="n b">' . g_eur($q * (float)$l['prix_jour']) . '</td></tr>'; }
    if ($fnt) { $q = count($fnt) * $nb; $fe = []; foreach ($fnt as $x) { $f = g_feries((int)substr($x, 0, 4), $l['zone'] ?: (string)$d['zone']); $fe[] = g_date($x) . (isset($f[$x]) ? ' (' . $f[$x] . ')' : ''); }
      $rows .= '<tr><td>' . $titre . ' ' . ($norm ? '' : $lieu) . '<div class="sub">Jour' . (count($fnt) > 1 ? 's' : '') . ' férié' . (count($fnt) > 1 ? 's' : '') . ' non travaillé' . (count($fnt) > 1 ? 's' : '') . ', payé' . (count($fnt) > 1 ? 's' : '') . $par . ' : ' . h(implode(', ', $fe)) . '</div></td>'
        . '<td class="n">' . $q . '</td><td class="n">' . g_eur((float)$l['prix_jour']) . '</td><td class="n b">' . g_eur($q * (float)$l['prix_jour']) . '</td></tr>'; }
    if ($l['maj']) { $q = count($l['maj']) * $nb;
      $fe = []; foreach ($l['maj'] as $x) { $f = g_feries((int)substr($x, 0, 4), $l['zone'] ?: (string)$d['zone']); $fe[] = g_date($x) . (isset($f[$x]) ? ' (' . $f[$x] . ')' : (date('N', strtotime($x)) == 7 ? ' (dimanche)' : '')); }
      $rows .= '<tr><td>' . $titre . ' ' . ($norm ? '' : $lieu) . '<div class="sub"><span class="maj" style="margin-left:0">Majoration 100 %</span> · Jour' . (count($l['maj']) > 1 ? 's' : '') . ' férié' . (count($l['maj']) > 1 ? 's' : '') . ' ou dimanche' . $par . ' : ' . h(implode(', ', $fe)) . '<br>' . $hor . '</div></td>'
        . '<td class="n">' . $q . '</td><td class="n">' . g_eur(2 * (float)$l['prix_jour']) . '</td><td class="n b">' . g_eur($q * 2 * (float)$l['prix_jour']) . '</td></tr>'; }
    $K = g_presta_km($l); if ($K['total'] > 0)
      $rows .= '<tr><td><b>Frais kilométriques de tournée</b><div class="sub">' . g_q((float)$l['km']) . ' km d\'itinéraire par jour × ' . $K['jours'] . ' jour' . ($K['jours'] > 1 ? 's' : '') . ' · ' . h(implode(' → ', array_column($mags, 'nom'))) . '</div></td>'
        . '<td class="n">' . g_q($K['km']) . '</td><td class="n">' . g_eur((float)$l['prix_km']) . '</td><td class="n b">' . g_eur($K['total']) . '</td></tr>';
  }
  // Une seule page : plus il y a de lignes, plus la mise en page se resserre.
  $n = substr_count($rows, '<tr>'); $k = $n <= 4 ? 1 : ($n <= 7 ? 0.8 : 0.62);
  $pt = fn($v) => round($v * $k, 1) . 'pt';
  $titreDoc = $fac ? 'FACTURE' : (!empty($d['avenant_de']) ? 'AVENANT' : 'DEVIS'); $num = $d['numero'] ?: ($fac ? 'provisoire' : 'brouillon');
  $meta = '<tr><td>Date</td><td>' . g_date($d['date_doc']) . '</td></tr>'
    . ($fac ? '<tr><td>Échéance</td><td><b>' . g_date($d['echeance']) . '</b></td></tr>' : '<tr><td>Valable jusqu\'au</td><td>' . g_date($d['validite']) . '</td></tr>')
    . (!empty($d['ref_type']) && trim((string)($d['ref_num'] ?? '')) !== '' ? '<tr><td>' . h(G_REFS[$d['ref_type']] ?? $d['ref_type']) . '</td><td><b>' . h($d['ref_num']) . '</b></td></tr>' : '')
    . ((float)$d['acompte'] > 0 ? '<tr><td>Acompte</td><td>' . g_q((float)$d['acompte']) . ' % à la commande</td></tr>' : '')
    . ($fac && !empty($d['devis_numero']) ? '<tr><td>Devis</td><td>' . h($d['devis_numero']) . '</td></tr>' : '')
    . ($aven && !$fac ? '<tr><td>Devis initial</td><td>' . h(preg_replace('/^Avenant au devis n° /', '', $aven)) . '</td></tr>' : '');
  $cli = '<div class="k">' . ($fac ? 'Facturé à' : 'Destinataire') . '</div><div class="cn">' . h($c['raison'] ?? '') . '</div>'
    . (!empty($c['contact']) ? '<div>À l\'attention de <b>' . h($c['contact']) . '</b></div>' : '')
    . '<div>' . $nl($c['adresse'] ?? '') . '<br>' . h(trim(($c['cp'] ?? '') . ' ' . ($c['ville'] ?? ''))) . (($c['pays'] ?? '') && ($c['pays'] ?? '') !== 'France' ? ', ' . h($c['pays']) : '') . '</div>'
    . (!empty($c['tva_intra']) ? '<div class="mut">N° TVA : ' . h($c['tva_intra']) . '</div>' : '');
  $tvaL = (float)$d['tva'] > 0 ? 'TVA ' . str_replace('.', ',', (string)(float)$d['tva']) . ' %' : 'TVA 0 %';
  $tvaM = g_tva_mention($d); [$bq, $ib, $bc] = g_banque($d, $r);
  $acpt = (float)$d['acompte'] > 0 ? '<div class="ac">Acompte à la commande (' . g_q((float)$d['acompte']) . ' %) : <b>' . g_eur(round($tot['ttc'] * (float)$d['acompte'] / 100, 2)) . '</b></div>' : '';
  $logo = is_file(__DIR__ . '/' . ag_logo()) ? '<img src="' . ag_logo() . '" style="height:50px">' : '<b>' . h($r['societe']) . '</b>';
  $foot = h($r['societe']) . ' · ' . h(str_replace("\n", ' · ', $r['adresse'])) . ' · ' . h($r['telephone']) . ' · ' . h($r['email']) . ' · ' . h($r['site']) . '<br>' . h($r['legal']);
  $ff = '';
  foreach (['' => ['normal', 'normal'], '-Bold' => ['bold', 'normal'], '-Italic' => ['normal', 'italic'], '-BoldItalic' => ['bold', 'italic']] as $sx => [$w, $st])
    if (is_file(__DIR__ . "/assets/fonts/Figtree$sx.ttf")) $ff .= "@font-face{font-family:'Figtree';font-weight:$w;font-style:$st;src:url('assets/fonts/Figtree$sx.ttf') format('truetype')}";
  $font = $ff ? "'Figtree','DejaVu Sans',sans-serif" : "'DejaVu Sans',sans-serif";
  $css = $ff . '@page{margin:14mm 16mm 20mm 16mm}body{font-family:' . $font . ';font-size:9.2pt;color:' . $T['esp'] . ';line-height:1}
    b{font-weight:bold}.mut{color:' . $T['mut'] . '}.dk{color:' . $T['esp'] . '}
    #footer{position:fixed;bottom:-13mm;left:0;right:0;text-align:center;font-size:6.8pt;line-height:1.05;color:' . $T['mut'] . ';border-top:.5pt solid ' . $T['line'] . ';padding-top:5pt}
    .co{font-size:8.2pt;color:' . $T['mut'] . ';margin-top:6pt;line-height:1}
    .doc{font-size:22pt;letter-spacing:4pt;font-weight:bold;color:' . $T['esp'] . '}.num{font-size:10.5pt;color:' . $T['p'] . ';letter-spacing:1.5pt;font-weight:bold;margin-top:1pt}
    .meta{margin-top:8pt}.meta td{padding:1.5pt 0;font-size:8.6pt}.meta td:first-child{color:' . $T['mut'] . ';padding-right:12pt}
    .cl{background:' . $T['sand'] . ';border-left:2.5pt solid ' . $T['p'] . ';padding:' . $pt(11) . ' 14pt;line-height:1.05}.k{font-size:7pt;text-transform:uppercase;letter-spacing:1.6pt;color:' . $T['p'] . ';font-weight:bold;margin-bottom:3pt}.cn{font-size:11.5pt;font-weight:bold;margin-bottom:2pt}
    .obj{margin:' . $pt(16) . ' 0 ' . $pt(6) . ';font-size:10pt}.obj .k{display:inline;margin-right:8pt}
    table{border-collapse:collapse;width:100%}.lg th{font-size:7pt;text-transform:uppercase;letter-spacing:1.4pt;color:' . $T['mut'] . ';font-weight:bold;text-align:left;border-bottom:1.2pt solid ' . $T['esp'] . ';padding:6pt 4pt}
    .lg th.n,.lg td.n{text-align:right;white-space:nowrap}.lg td{padding:' . $pt(9) . ' 4pt;border-bottom:.5pt solid ' . $T['line'] . ';vertical-align:top}.lg td.b{font-weight:bold}
    .sub{color:' . $T['mut'] . ';font-size:8.4pt;margin-top:3pt;line-height:.95}.txt{color:' . $T['mut'] . '}.maj{color:' . $T['p'] . ';font-size:7pt;text-transform:uppercase;letter-spacing:1pt;font-weight:bold;margin-left:4pt}
    .men{margin-top:' . $pt(9) . ';font-size:8.4pt;color:' . $T['mut'] . ';line-height:1}
    .tot{margin-top:' . $pt(14) . '}.tot td{padding:' . $pt(9) . ' 12pt}.tot .l{background:' . $T['sand'] . '}.tot .v{font-size:13pt;font-weight:bold;margin-top:2pt}
    .tot .ttc{background:' . $T['esp'] . ';color:#fff}.tot .ttc .k{color:#E2CDBD}.ac{text-align:right;font-size:8.6pt;margin-top:5pt}
    .bot{margin-top:' . $pt(18) . '}.bot td{vertical-align:top;font-size:8.4pt;line-height:1.05}.bank{background:' . $T['sand'] . ';padding:8pt 10pt}
    .sig{border:.8pt solid ' . $T['line'] . ';height:' . $pt(70) . ';padding:6pt 8pt;color:' . $T['mut'] . ';font-size:7.4pt}
    .pen{margin-top:' . $pt(12) . ';font-size:6.8pt;line-height:1;color:' . $T['mut'] . '}
    .cgv{font-size:7.4pt;line-height:1.05;color:#3a322c}.cgv h2{font-size:13pt;letter-spacing:3pt;text-align:center;margin:0 0 3pt;color:' . $T['esp'] . '}
    .cgv .art{font-weight:bold;color:' . $T['p'] . ';margin-top:7pt;text-transform:uppercase;letter-spacing:.8pt;font-size:7.6pt}.cgv p{margin:0 0 4pt;text-align:justify}';
  if (!$fac && !empty($d['accepte_le'])) $sig = '<td style="width:33%;padding-left:12pt"><div class="k">Bon pour accord</div><div class="sig" style="height:auto;color:' . $T['esp'] . ';font-size:8.4pt"><b style="color:#2F6B45">' . (($d['accepte_ip'] ?? '') === 'e-mail' ? 'Accord donné par e-mail' : 'Devis validé en ligne') . '</b><br>le ' . date('d/m/Y à H:i', strtotime($d['accepte_le'])) . '<br>par ' . h($d['accepte_par']) . '</div></td>';
  else $sig = $fac ? '' : '<td style="width:33%;padding-left:12pt"><div class="k">Bon pour accord</div><div class="mut" style="font-size:7.6pt;margin-bottom:5pt">Validez ce devis en ligne sur votre espace marques ' . AG_COURT . ', ou retournez-le daté, signé, avec la mention « Bon pour accord » et votre cachet.</div><div class="sig">Date, signature et cachet</div></td>';
  $html = '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body><div id="footer">' . $foot . '</div>
    <table><tr><td style="width:55%;vertical-align:bottom">' . $logo . '</td>
      <td style="text-align:right;vertical-align:bottom"><div class="doc">' . $titreDoc . '</div><div class="num">N° ' . h($num) . '</div></td></tr></table>
    <table style="margin-top:' . $pt(12) . '"><tr><td style="width:50%;vertical-align:top;padding-right:16pt"><div class="co" style="margin-top:0">' . $nl($r['adresse']) . '<br>' . h($r['telephone']) . ' · ' . h($r['email']) . '<br>Votre contact : <span class="dk">' . h($r['contact']) . '</span></div>
      <table class="meta" style="width:auto">' . $meta . '</table></td><td class="cl">' . $cli . '</td></tr></table>
    ' . ($d['objet'] ? '<div class="obj"><span class="k">Objet</span>' . h($d['objet']) . '</div>' : '<div style="height:' . $pt(14) . '"></div>') . '
    <table class="lg"><tr><th>Désignation</th><th class="n" style="width:8%">Qté</th><th class="n" style="width:15%">PU HT</th><th class="n" style="width:16%">Total HT</th></tr>' . $rows . '</table>
    ' . (trim($mention) !== '' ? '<div class="men">' . $nl($mention) . '</div>' : '') . '
    <table class="tot"><tr><td class="l" style="width:34%"><div class="k" style="color:' . $T['mut'] . '">Total HT</div><div class="v">' . g_eur($tot['ht']) . '</div></td>
      <td class="l" style="width:30%"><div class="k" style="color:' . $T['mut'] . '">' . $tvaL . '</div><div class="v">' . g_eur($tot['tva']) . '</div></td>
      <td class="ttc"><div class="k">' . ($fac ? 'Net à payer TTC' : 'Total TTC') . '</div><div class="v" style="font-size:16pt">' . g_eur($tot['ttc']) . '</div></td></tr></table>' . ($tvaM !== '' ? '<div class="ac" style="text-align:left"><b>' . h($tvaM) . '</b></div>' : '') . $acpt . '
    <table class="bot"><tr><td style="width:' . ($fac ? '45' : '27') . '%;padding-right:12pt"><div class="k">Règlement</div><b>' . h(G_PAIEMENTS[$d['paiement']] ?? $d['paiement']) . '</b>' . ($fac ? ',<br>soit au plus tard le <b>' . g_date($d['echeance']) . '</b>' : '') . ', par virement bancaire.' . ($fac && $d['numero'] ? '<br>Référence à rappeler : <b>' . h($d['numero']) . '</b>' : '') . '</td>
      <td style="padding-right:' . ($fac ? '0' : '12pt') . '"><div class="k">Coordonnées bancaires</div><div class="bank">' . h($bq) . '<br>IBAN <b style="white-space:nowrap">' . h($ib) . '</b><br>BIC <b>' . h($bc) . '</b></div></td>' . $sig . '</tr></table>
    <div class="pen">' . h($r['penalites']) . '</div>';
  if (!$fac && trim($r['cgv']) !== '') {
    $parts = [];
    foreach (preg_split('/\n\s*\n/', trim($r['cgv'])) as $p) {
      $lines = explode("\n", trim($p)); $first = array_shift($lines);
      if (preg_match('/^\d+\.\s*-/', $first)) $parts[] = '<div class="art">' . h($first) . '</div>' . ($lines ? '<p>' . nl2br(h(implode("\n", $lines))) . '</p>' : '');
      elseif (preg_match('/^(\d+(\.\d+)+\.)\s*(.*)$/s', $first, $m) && mb_strlen($m[3]) < 70) $parts[] = '<p><b>' . h($m[1] . ' ' . $m[3]) . '</b>' . ($lines ? '<br>' . nl2br(h(implode("\n", $lines))) : '') . '</p>';
      elseif (preg_match('/^(\d+(\.\d+)+\.)\s*(.*)$/s', $first, $m)) $parts[] = '<p><b>' . h($m[1]) . '</b> ' . nl2br(h($m[3] . ($lines ? "\n" . implode("\n", $lines) : ''))) . '</p>';
      else $parts[] = '<p>' . nl2br(h($p)) . '</p>';
    }
    $html .= '<div style="page-break-before:always" class="cgv"><h2>CONDITIONS GÉNÉRALES DE VENTE</h2><div class="mut" style="text-align:center;margin-bottom:10pt">' . h($r['societe']) . '</div>'
      . implode('', $parts) . '<div style="margin-top:12pt;text-align:right"><b>Pour ' . h($r['societe']) . '</b><br>' . h($r['signataire']) . '</div></div>';
  }
  return $html . '</body></html>';
}
function g_q(float $q): string { return rtrim(rtrim(number_format($q, 2, ',', ''), '0'), ','); }
function g_nom_fichier(array $d): string {
  $c = $d['client']['raison'] ?? 'client';
  return preg_replace('/[^A-Za-z0-9_.-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', ($d['type'] === 'facture' ? 'Facture_' : 'Devis_') . ($d['numero'] ?: 'brouillon') . '_' . $c)) . '.pdf';
}

// ---------- Planning des intervenants (par opération = par devis) ----------
function p_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; $pdo = db();
  $pdo->exec("CREATE TABLE IF NOT EXISTS missions (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, slot_key VARCHAR(255) NULL UNIQUE, animatrice_id INT UNSIGNED NULL, devis_id INT UNSIGNED NULL, ligne INT NULL,
    date_mission DATE NOT NULL, debut VARCHAR(5) NOT NULL DEFAULT '10:00', fin VARCHAR(5) NOT NULL DEFAULT '18:00', pause INT NOT NULL DEFAULT 60, zone VARCHAR(20) NOT NULL DEFAULT '',
    magasin VARCHAR(160) NOT NULL DEFAULT '', poste VARCHAR(160) NOT NULL DEFAULT '', client VARCHAR(160) NOT NULL DEFAULT '', notes TEXT NULL, par VARCHAR(120) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL, modifie_le DATETIME NULL, notifie_le DATETIME NULL, INDEX (animatrice_id, date_mission), INDEX (devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  $c = $pdo->query("SHOW COLUMNS FROM missions")->fetchAll(PDO::FETCH_COLUMN);
  if (!in_array('statut', $c, true)) {
    // Un créneau peut exister sans personne ; il peut être annulé, ou ajouté pour remplacer une date annulée (accord du client).
    $pdo->exec("ALTER TABLE missions MODIFY animatrice_id INT UNSIGNED NULL, ADD COLUMN statut VARCHAR(10) NOT NULL DEFAULT 'prevue', ADD COLUMN origine VARCHAR(10) NOT NULL DEFAULT 'devis',
      ADD COLUMN remplace DATE NULL, ADD COLUMN remplace_par DATE NULL, ADD COLUMN motif TEXT NULL, ADD COLUMN accord VARCHAR(250) NOT NULL DEFAULT ''");
    $pdo->exec("DELETE FROM missions WHERE devis_id IS NULL"); // plus de missions hors devis
  }
  // Prise de poste et absences constatées par l'agence (une absence n'est ni payée ni facturée).
  $pdo->exec("CREATE TABLE IF NOT EXISTS prises_poste (mission_id INT UNSIGNED NOT NULL, animatrice_id INT UNSIGNED NOT NULL, date_mission DATE NOT NULL, le DATETIME NULL, heure VARCHAR(5) NOT NULL DEFAULT '',
    ecart_min INT NULL, lat DOUBLE NULL, lng DOUBLE NULL, precision_m INT NULL, distance_m INT NULL, ip VARCHAR(60) NOT NULL DEFAULT '', ua VARCHAR(255) NOT NULL DEFAULT '',
    statut_admin VARCHAR(12) NOT NULL DEFAULT '', note VARCHAR(300) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', modifie_le DATETIME NULL, PRIMARY KEY (mission_id, animatrice_id), INDEX (date_mission), INDEX (animatrice_id))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  // Journée partagée : demi-journée dans un autre point de vente, ou relais par une autre personne en cours de journée (une seule journée facturée).
  if (!in_array('partie', $pdo->query("SHOW COLUMNS FROM missions")->fetchAll(PDO::FETCH_COLUMN), true))
    $pdo->exec("ALTER TABLE missions ADD COLUMN partie TINYINT NOT NULL DEFAULT 0, ADD COLUMN fin_orig VARCHAR(5) NULL, ADD COLUMN pause_orig INT NULL");
  // Tournée de merchandising : plusieurs points d'intervention sur une même journée.
  foreach ($pdo->query("SHOW COLUMNS FROM missions WHERE Field = 'magasin'")->fetchAll() as $col) if (stripos($col['Type'], '160') !== false) $pdo->exec("ALTER TABLE missions MODIFY magasin VARCHAR(600) NOT NULL DEFAULT ''");
  $pdo->exec("CREATE TABLE IF NOT EXISTS missions_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, devis_id INT UNSIGNED NOT NULL, slot_key VARCHAR(255) NOT NULL DEFAULT '', texte VARCHAR(600) NOT NULL,
    par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (devis_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** Condition SQL : la journée n'a pas été marquée « absent(e) » par l'agence. */
function p_present_sql(string $m = 'm'): string { return " AND NOT EXISTS (SELECT 1 FROM prises_poste pp WHERE pp.mission_id = $m.id AND pp.animatrice_id = $m.animatrice_id AND pp.statut_admin = 'absent')"; }
function p_log(int $devis, string $key, string $texte): void { db()->prepare("INSERT INTO missions_log (devis_id, slot_key, texte, par, created_at) VALUES (?,?,?,?,?)")->execute([$devis, $key, mb_substr($texte, 0, 600), admin_nom(), now()]); }
/** Créneaux prévus au devis : ligne de prestation × point de vente × date travaillée. */
function p_base_slots(array $d): array {
  $out = [];
  foreach ($d['lignes'] as $i => $l) {
    if ($l['type'] !== 'presta') continue;
    $mags = $l['magasins'] ?? []; if (!$mags && !empty($l['magasin'])) $mags = [['nom' => $l['magasin']]]; if (!$mags) $mags = [['nom' => '']];
    // Tournée : une seule personne fait tous les points dans la journée (un créneau par date, points dans l'ordre de l'itinéraire).
    $kt = !empty($l['tournee']) && count($mags) > 1; if ($kt) $mags = [['nom' => mb_substr(implode(', ', array_column($mags, 'nom')), 0, 600), 'k' => 'tournee']];
    foreach ($mags as $m) foreach (array_diff($l['dates'], $l['fnt'] ?? []) as $dt)
      $out[$d['id'] . '|' . $i . '|' . ($m['k'] ?? $m['nom']) . '|' . $dt] = ['key' => $d['id'] . '|' . $i . '|' . ($m['k'] ?? $m['nom']) . '|' . $dt, 'tournee' => $kt, 'ligne' => $i, 'magasin' => $m['nom'], 'date' => $dt,
        'zone' => $l['zone'] ?: $d['zone'], 'debut' => $l['debut'], 'fin' => $l['fin'], 'pause' => (int)$l['pause'], 'poste' => $l['poste'] ?: 'Animation', 'role' => (string)($l['role'] ?? ''), 'majore' => in_array($dt, $l['maj'], true), 'origine' => 'devis'];
  }
  return $out;
}
/** État de l'opération : créneaux du devis + reports, avec la personne, l'état, l'historique. */
function p_operation(array $d): array {
  p_schema(); $S = p_base_slots($d);
  $st = db()->prepare("SELECT m.*, CONCAT(a.nom, ' ', a.prenom) AS nom, (SELECT COUNT(*) FROM prises_poste pp WHERE pp.mission_id = m.id AND pp.animatrice_id = m.animatrice_id AND pp.statut_admin = 'absent') AS absent,
    (SELECT GROUP_CONCAT(CONCAT(UPPER(x.nom), ' ', x.prenom) SEPARATOR ', ') FROM prises_poste pp JOIN animatrices x ON x.id = pp.animatrice_id WHERE pp.mission_id = m.id AND pp.animatrice_id <> m.animatrice_id AND pp.statut_admin = 'absent') AS remplace_absent FROM missions m LEFT JOIN animatrices a ON a.id = m.animatrice_id WHERE m.devis_id = ?"); $st->execute([$d['id']]);
  foreach ($st->fetchAll() as $m) {
    $k = $m['slot_key'];
    if (!isset($S[$k])) { if (!in_array($m['origine'], ['ajout', 'partie'], true)) continue;
      $S[$k] = ['key' => $k, 'ligne' => (int)$m['ligne'], 'magasin' => $m['magasin'], 'date' => $m['date_mission'], 'zone' => $m['zone'], 'debut' => $m['debut'], 'fin' => $m['fin'], 'pause' => (int)$m['pause'], 'poste' => $m['poste'], 'majore' => false, 'origine' => $m['origine']]; }
    if ((int)$m['partie']) { $S[$k]['debut'] = $m['debut']; $S[$k]['fin'] = $m['fin']; $S[$k]['pause'] = (int)$m['pause']; $S[$k]['partie'] = (int)$m['partie']; $S[$k]['mission_id'] = (int)$m['id']; }
    $S[$k] += ['id' => (int)$m['id'], 'animatrice_id' => $m['animatrice_id'] ? (int)$m['animatrice_id'] : null, 'nom' => $m['nom'], 'statut' => $m['statut'], 'remplace' => $m['remplace'], 'remplace_par' => $m['remplace_par'], 'motif' => $m['motif'], 'accord' => $m['accord'], 'notifie_le' => $m['notifie_le'], 'absent' => (int)$m['absent'] > 0, 'remplace_absent' => (string)$m['remplace_absent']];
  }
  foreach ($S as &$x) $x += ['role' => (string)($d['lignes'][(int)($x['ligne'] ?? 0)]['role'] ?? '')];
  foreach ($S as &$x) $x += ['id' => null, 'animatrice_id' => null, 'nom' => null, 'statut' => 'prevue', 'remplace' => null, 'remplace_par' => null, 'motif' => null, 'accord' => '', 'notifie_le' => null, 'absent' => false, 'remplace_absent' => '', 'partie' => 0]; unset($x);
  uasort($S, fn($a, $b) => [$a['date'], $a['magasin']] <=> [$b['date'], $b['magasin']]);
  return array_values($S);
}
/** Enregistre l'état d'un créneau (créé à la première modification). */
function p_save_slot(array $d, array $s, array $set): void {
  p_schema(); $cols = ['slot_key' => $s['key'], 'devis_id' => $d['id'], 'ligne' => $s['ligne'], 'date_mission' => $s['date'], 'debut' => $s['debut'], 'fin' => $s['fin'], 'pause' => $s['pause'],
    'zone' => $s['zone'], 'magasin' => $s['magasin'], 'poste' => $s['poste'], 'client' => $d['client']['raison'] ?? '', 'origine' => $s['origine'], 'par' => admin_nom(), 'created_at' => now()] + $set;
  $upd = implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($set + ['modifie_le' => 1])));
  $cols['modifie_le'] = now();
  db()->prepare("INSERT INTO missions (" . implode(',', array_keys($cols)) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ") ON DUPLICATE KEY UPDATE $upd")->execute(array_values($cols));
}
/** Lignes de facture d'après ce qui a été réalisé : dates annulées retirées, reports ajoutés, et le texte qui justifie l'écart. */
function g_lignes_realisees(array $d): array {
  $op = p_operation($d); $ecarts = []; $par = [];
  // Journées partagées (demi-journée ailleurs, relais en cours de journée) : une seule journée facturée, au point de vente prévu ; le partage est indiqué.
  $parts = []; foreach ($op as $s) if (!empty($s['partie']) && $s['statut'] !== 'annulee') $parts[explode('|p', $s['key'])[0]][] = $s;
  foreach ($parts as $ps) { usort($ps, fn($a, $b) => strcmp($a['debut'], $b['debut']));
    $ecarts[] = g_date($ps[0]['date']) . ' : journée partagée, ' . implode(', ', array_map(fn($x) => trim(($x['nom'] ?? '') . ' de ' . $x['debut'] . ' à ' . $x['fin'] . ' à ' . $x['magasin']), $ps)) . ' (une journée facturée)'; }
  foreach ($op as $s) {
    if (($s['origine'] ?? '') === 'partie') continue;
    if ($s['statut'] === 'annulee') $ecarts[] = g_date($s['date']) . ($s['magasin'] ? ' à ' . $s['magasin'] : '') . ' annulé' . ($s['remplace_par'] ? ', remplacé par le ' . g_date($s['remplace_par']) : ', non facturé')
      . ($s['motif'] ? ' (' . $s['motif'] . ')' : '') . ($s['accord'] ? ', accord : ' . $s['accord'] : '');
    elseif (!empty($s['absent'])) $ecarts[] = g_date($s['date']) . ($s['magasin'] ? ' à ' . $s['magasin'] : '') . ' : intervenant(e) absent(e), journée non facturée';
    else { $par[$s['ligne']][$s['magasin']][] = $s['date'];
      // Absent(e) remplacé(e) en urgence : la journée est faite, donc facturée ; l'écart est indiqué.
      if (!empty($s['remplace_absent']) && $s['animatrice_id']) $ecarts[] = g_date($s['date']) . ($s['magasin'] ? ' à ' . $s['magasin'] : '') . ' : ' . $s['remplace_absent'] . ' absent(e), remplacé(e) par ' . $s['nom'] . ', journée assurée'; }
  }
  if (!$ecarts) return $d['lignes'];
  $out = [];
  foreach ($d['lignes'] as $i => $l) {
    if ($l['type'] !== 'presta') { $out[] = $l; continue; }
    // Regroupe les points de vente qui ont exactement les mêmes dates réalisées.
    $grp = []; foreach (($par[$i] ?? []) as $mag => $ds) { sort($ds); $grp[implode(',', $ds)][] = $mag; }
    foreach ($grp as $ds => $mags) {
      $dates = explode(',', $ds); $n = $l; $n['dates'] = array_values(array_unique(array_merge($dates, $l['fnt'] ?? [])));
      sort($n['dates']); $zone = $l['zone'] ?: $d['zone'];
      $n['maj'] = array_values(array_filter($dates, fn($x) => in_array($x, $l['maj'], true) || (!in_array($x, $l['dates'], true) && isset(g_feries((int)substr($x, 0, 4), $zone)[$x]))));
      if (empty($l['tournee'])) { $n['magasins'] = array_map(fn($m) => ['id' => null, 'nom' => $m], $mags); $n['magasin'] = implode(', ', $mags); }
      $n['total'] = g_presta_total($n);
      $out[] = $n;
    }
  }
  $out[] = ['type' => 'texte', 'texte' => 'Écarts avec le devis n° ' . $d['numero'] . ' : ' . implode(' ; ', $ecarts) . '.'];
  return $out;
}
/** Partage d'une journée à une heure donnée : la 2e partie peut aller à une autre personne (relais) et/ou dans un autre point de vente (demi-journée). */
function p_partager(int $mid, string $h, int $aid2, string $mag2, int $pause1, int $pause2): array {
  p_schema(); $st = db()->prepare("SELECT * FROM missions WHERE id = ? AND statut <> 'annulee'"); $st->execute([$mid]); $m = $st->fetch() ?: throw new RuntimeException('Mission introuvable.');
  if (!preg_match('/^\d{2}:\d{2}$/', $h) || g_hm($h) <= g_hm($m['debut']) || g_hm($h) >= g_hm($m['fin'])) throw new RuntimeException('L\'heure du relais doit être entre ' . $m['debut'] . ' et ' . $m['fin'] . '.');
  $aid2 = $aid2 ?: (int)$m['animatrice_id']; $mag2 = trim($mag2) !== '' ? clip($mag2, 160) : (string)$m['magasin'];
  if ($aid2 === (int)$m['animatrice_id'] && $mag2 === $m['magasin']) throw new RuntimeException('Choisissez une autre personne ou un autre point de vente pour la 2e partie.');
  if ($aid2 && $aid2 !== (int)$m['animatrice_id']) { $o = db()->prepare("SELECT magasin, debut, fin FROM missions WHERE animatrice_id = ? AND date_mission = ? AND statut <> 'annulee'"); $o->execute([$aid2, $m['date_mission']]);
    foreach ($o->fetchAll() as $x) if (g_hm($x['debut']) < g_hm($m['fin']) && g_hm($x['fin']) > g_hm($h)) throw new RuntimeException('Cette personne travaille déjà ce jour-là à ' . $x['magasin'] . ' (' . $x['debut'] . ' à ' . $x['fin'] . ').'); }
  $z = db()->prepare("SELECT zone FROM magasins WHERE nom = ? LIMIT 1"); $z->execute([$mag2]); $zone = (string)($z->fetchColumn() ?: $m['zone']);
  $base = explode('|p', (string)$m['slot_key'])[0]; $n = 2; $ck = db()->prepare("SELECT COUNT(*) FROM missions WHERE slot_key = ?"); while (true) { $ck->execute([$base . '|p' . $n]); if (!$ck->fetchColumn()) break; $n++; }
  db()->prepare("UPDATE missions SET partie = IF(partie = 0, 1, partie), fin_orig = COALESCE(fin_orig, fin), pause_orig = COALESCE(pause_orig, pause), fin = ?, pause = ?, modifie_le = ? WHERE id = ?")->execute([$h, max(0, $pause1), now(), $mid]);
  db()->prepare("INSERT INTO missions (slot_key, animatrice_id, devis_id, ligne, date_mission, debut, fin, pause, zone, magasin, poste, client, par, created_at, statut, origine, partie) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'prevue','partie',?)")
    ->execute([$base . '|p' . $n, $aid2 ?: null, $m['devis_id'], $m['ligne'], $m['date_mission'], $h, $m['fin'], max(0, $pause2), $zone, $mag2, $m['poste'], $m['client'], admin_nom(), now(), $n]);
  $nom = function ($id) { if (!$id) return 'à pourvoir'; $q = db()->prepare("SELECT CONCAT(prenom, ' ', nom) FROM animatrices WHERE id = ?"); $q->execute([$id]); return (string)$q->fetchColumn(); };
  if ($m['devis_id']) p_log((int)$m['devis_id'], $base, g_date($m['date_mission']) . ' : journée partagée à ' . $h . ', ' . $nom((int)$m['animatrice_id']) . ' à ' . $m['magasin'] . ' jusqu\'à ' . $h . ', puis ' . $nom($aid2) . ' à ' . $mag2 . ' jusqu\'à ' . $m['fin']);
  return ['ok' => true, 'id' => (int)db()->lastInsertId()];
}
/** Annule un partage : la partie ajoutée disparaît, la partie précédente retrouve sa fin. */
function p_departager(int $mid): void {
  p_schema(); $st = db()->prepare("SELECT * FROM missions WHERE id = ?"); $st->execute([$mid]); $m = $st->fetch() ?: throw new RuntimeException('Mission introuvable.');
  $base = explode('|p', (string)$m['slot_key'])[0];
  if ($m['origine'] === 'partie') { db()->prepare("DELETE FROM missions WHERE id = ?")->execute([$mid]); db()->prepare("UPDATE missions SET fin = ? WHERE (slot_key = ? OR slot_key LIKE ?) AND fin = ? AND date_mission = ?")->execute([$m['fin'], $base, $base . '|p%', $m['debut'], $m['date_mission']]); }
  else { db()->prepare("DELETE FROM missions WHERE slot_key LIKE ? AND origine = 'partie'")->execute([$base . '|p%']); }
  $left = db()->prepare("SELECT COUNT(*) FROM missions WHERE slot_key LIKE ? AND origine = 'partie'"); $left->execute([$base . '|p%']);
  if (!$left->fetchColumn()) db()->prepare("UPDATE missions SET fin = COALESCE(fin_orig, fin), pause = COALESCE(pause_orig, pause), partie = 0, fin_orig = NULL, pause_orig = NULL WHERE slot_key = ?")->execute([$base]);
  if ($m['devis_id']) p_log((int)$m['devis_id'], $base, g_date($m['date_mission']) . ' : partage de la journée annulé');
}
/** Missions d'un(e) intervenant(e) à partir d'une date (créneaux annulés exclus). */
function p_missions(int $aid, string $from): array {
  p_schema(); $st = db()->prepare("SELECT m.id, m.date_mission AS date, m.debut, m.fin, m.pause, m.zone, m.magasin, m.poste, m.client, m.notes, m.devis_id, d.numero AS devis, m.notifie_le
    FROM missions m LEFT JOIN g_docs d ON d.id = m.devis_id WHERE m.animatrice_id = ? AND m.statut <> 'annulee' AND m.date_mission >= ? ORDER BY m.date_mission, m.debut");
  try { $st->execute([$aid, $from]); } catch (PDOException $e) { g_schema(); $st->execute([$aid, $from]); }
  return $st->fetchAll();
}
/** Planning ouvert aux intervenants (appli et e-mails) ; sinon phase de test : visible seulement en aperçu, e-mails vers l'administrateur. */
/** Testeurs internes : voient leur planning et leurs relevés dans leur propre appli même en phase de test, et reçoivent leurs e-mails directement. */
const P_TESTEURS = AG_TESTEURS; // adresses des testeurs internes (config.php)
function p_testeur(int $aid): bool {
  $st = db()->prepare("SELECT LOWER(email) AS e, CONCAT(prenom, ' ', nom) AS n FROM animatrices WHERE id = ?"); $st->execute([$aid]); $a = $st->fetch(); if (!$a) return false;
  return in_array($a['e'], P_TESTEURS, true) ;
}
function p_visible(int $aid): bool { return p_ouvert() || (function_exists('apercu') && apercu()) || p_testeur($aid); }
function p_ouvert(): bool { g_schema(); return (string)db()->query("SELECT valeur FROM g_reglages WHERE cle = 'planning_ouvert'")->fetchColumn() === '1'; }
