-- Amaya : structure complète de la base (créée par l'assistant installation.php).
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `droits` varchar(100) NOT NULL DEFAULT '',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `derniere_connexion` datetime DEFAULT NULL,
  `totp_secret` varchar(255) NOT NULL DEFAULT '',
  `totp_actif` tinyint(1) NOT NULL DEFAULT 0,
  `totp_secours` text DEFAULT NULL,
  `totp_dernier` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `animatrices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `prenom` varchar(80) NOT NULL,
  `nom` varchar(80) NOT NULL DEFAULT '',
  `telephone` varchar(20) NOT NULL DEFAULT '',
  `email` varchar(160) NOT NULL DEFAULT '',
  `zone` varchar(20) NOT NULL,
  `code` char(6) DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime DEFAULT NULL,
  `rgpd_info_at` datetime DEFAULT NULL,
  `rgpd_image_at` datetime DEFAULT NULL,
  `modele` varchar(30) NOT NULL DEFAULT '',
  `succede_a` int(10) unsigned DEFAULT NULL,
  `matricule` varchar(12) DEFAULT NULL,
  `statut` varchar(12) NOT NULL DEFAULT 'actif',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `matricule` (`matricule`),
  KEY `telephone` (`telephone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `brouillons` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `data` mediumtext NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `brouillons_ba` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `data` mediumtext NOT NULL,
  `updated_at` bigint(20) NOT NULL,
  PRIMARY KEY (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `cdii` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `ref_debut` date NOT NULL,
  `ref_fin` date NOT NULL,
  `heures` decimal(8,2) NOT NULL DEFAULT 0.00,
  `statut` varchar(12) NOT NULL DEFAULT 'propose',
  `propose_le` date DEFAULT NULL,
  `mode` varchar(6) NOT NULL DEFAULT 'main',
  `presente_le` date DEFAULT NULL,
  `reponse_le` date DEFAULT NULL,
  `volume` decimal(8,2) NOT NULL DEFAULT 0.00,
  `taux` decimal(8,2) NOT NULL DEFAULT 0.00,
  `prise_effet` date DEFAULT NULL,
  `params` mediumtext DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `comptes_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(120) NOT NULL,
  `societe` varchar(120) NOT NULL DEFAULT '',
  `email` varchar(160) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `marques` varchar(400) NOT NULL DEFAULT '',
  `zones` varchar(200) NOT NULL DEFAULT '',
  `droits` varchar(100) NOT NULL DEFAULT 'ca,photos,cr,telecharger',
  `noms` varchar(10) NOT NULL DEFAULT 'complet',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `derniere_connexion` datetime DEFAULT NULL,
  `perimetre` text DEFAULT NULL,
  `ba` varchar(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contrats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned NOT NULL,
  `numero` int(10) unsigned NOT NULL,
  `avenant` int(10) unsigned NOT NULL DEFAULT 0,
  `statut` varchar(10) NOT NULL DEFAULT 'envoye',
  `salarie` mediumtext DEFAULT NULL,
  `params` mediumtext DEFAULT NULL,
  `lignes` mediumtext DEFAULT NULL,
  `precedent` mediumtext DEFAULT NULL,
  `base_hash` char(40) NOT NULL DEFAULT '',
  `envoye_le` datetime DEFAULT NULL,
  `envoye_par` varchar(120) NOT NULL DEFAULT '',
  `signe_le` datetime DEFAULT NULL,
  `signe_nom` varchar(160) NOT NULL DEFAULT '',
  `signe_mention` varchar(60) NOT NULL DEFAULT '',
  `signe_ip` varchar(60) NOT NULL DEFAULT '',
  `signe_ua` varchar(255) NOT NULL DEFAULT '',
  `signature` mediumtext DEFAULT NULL,
  `signe_test` tinyint(4) NOT NULL DEFAULT 0,
  `empreinte` char(64) NOT NULL DEFAULT '',
  `journal` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `preuve` mediumtext DEFAULT NULL,
  `pdf_fichier` varchar(80) NOT NULL DEFAULT '',
  `pdf_sha256` char(64) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `animatrice_id` (`animatrice_id`,`devis_id`,`avenant`),
  KEY `devis_id` (`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contrats_brouillons` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned NOT NULL,
  `params` mediumtext DEFAULT NULL,
  PRIMARY KEY (`animatrice_id`,`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contrats_dpae` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned NOT NULL,
  `fait_le` date NOT NULL,
  `pour_le` date DEFAULT NULL,
  `numero` varchar(60) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`animatrice_id`,`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contrats_fin` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned NOT NULL,
  `ft_le` date NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`animatrice_id`,`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `contrats_solde` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned NOT NULL,
  `net` decimal(10,2) DEFAULT NULL,
  `le` date DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `mutuelle` varchar(10) NOT NULL DEFAULT '',
  `prevoyance` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`animatrice_id`,`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `dispos` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `jour` date NOT NULL,
  `etat` char(1) NOT NULL,
  `modifie_le` datetime NOT NULL,
  PRIMARY KEY (`animatrice_id`,`jour`),
  KEY `jour` (`jour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `docs_acces` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `doc` varchar(20) NOT NULL,
  `qui` varchar(160) NOT NULL,
  `ip` varchar(60) NOT NULL DEFAULT '',
  `le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `doc` (`doc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `docs_agence` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `categorie` varchar(12) NOT NULL,
  `titre` varchar(160) NOT NULL,
  `fichier` varchar(80) NOT NULL,
  `nom` varchar(200) NOT NULL DEFAULT '',
  `mime` varchar(60) NOT NULL DEFAULT '',
  `taille` int(11) NOT NULL DEFAULT 0,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `envois` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `marques` varchar(400) NOT NULL,
  `destinataire` text NOT NULL,
  `statut` varchar(20) NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `evenements_cr` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned DEFAULT NULL,
  `date_evt` date NOT NULL,
  `lieu` varchar(160) NOT NULL DEFAULT '',
  `role` varchar(120) NOT NULL DEFAULT '',
  `affluence` varchar(40) NOT NULL DEFAULT '',
  `visiteurs` int(11) DEFAULT NULL,
  `contacts` int(11) DEFAULT NULL,
  `documents` int(11) DEFAULT NULL,
  `deroulement` text DEFAULT NULL,
  `demandes` text DEFAULT NULL,
  `points_forts` text DEFAULT NULL,
  `ameliorer` text DEFAULT NULL,
  `incidents` text DEFAULT NULL,
  `equipe` text DEFAULT NULL,
  `recommandations` text DEFAULT NULL,
  `valide_le` datetime DEFAULT NULL,
  `valide_par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `devis_id` (`devis_id`,`date_evt`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `factures_ae` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `numero` varchar(60) NOT NULL DEFAULT '',
  `montant` decimal(10,2) NOT NULL,
  `periode` char(7) NOT NULL DEFAULT '',
  `devis_id` int(10) unsigned DEFAULT NULL,
  `commentaire` varchar(500) NOT NULL DEFAULT '',
  `fichier` varchar(80) NOT NULL,
  `nom` varchar(200) NOT NULL DEFAULT '',
  `mime` varchar(60) NOT NULL DEFAULT '',
  `statut` varchar(10) NOT NULL DEFAULT 'recue',
  `motif` varchar(300) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `decide_le` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`),
  KEY `statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `fiches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `visite_id` int(10) unsigned NOT NULL,
  `pdv` varchar(160) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `fiches_animatrices` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `data` mediumtext DEFAULT NULL,
  `photo` varchar(80) NOT NULL DEFAULT '',
  `cv` varchar(80) NOT NULL DEFAULT '',
  `cv_nom` varchar(200) NOT NULL DEFAULT '',
  `notes_admin` text DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `book` text DEFAULT NULL,
  PRIMARY KEY (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `formation_acces` (
  `formation_id` int(10) unsigned NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`formation_id`,`animatrice_id`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `formations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `marque_id` int(10) unsigned DEFAULT NULL,
  `titre` varchar(160) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(10) NOT NULL,
  `fichier` varchar(80) NOT NULL DEFAULT '',
  `nom_original` varchar(200) NOT NULL DEFAULT '',
  `mime` varchar(100) NOT NULL DEFAULT '',
  `taille` bigint(20) NOT NULL DEFAULT 0,
  `url` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `restreint` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `marque_id` (`marque_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `g_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `raison` varchar(160) NOT NULL,
  `contact` varchar(160) NOT NULL DEFAULT '',
  `adresse` varchar(400) NOT NULL DEFAULT '',
  `cp` varchar(20) NOT NULL DEFAULT '',
  `ville` varchar(120) NOT NULL DEFAULT '',
  `pays` varchar(80) NOT NULL DEFAULT 'France',
  `emails` varchar(600) NOT NULL DEFAULT '',
  `cc` varchar(600) NOT NULL DEFAULT '',
  `tva_intra` varchar(40) NOT NULL DEFAULT '',
  `tva` varchar(6) NOT NULL DEFAULT '20',
  `paiement` varchar(10) NOT NULL DEFAULT '30j',
  `marque_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `tarif` varchar(40) NOT NULL DEFAULT '',
  `compte` varchar(4) NOT NULL DEFAULT '',
  `presence_mode` varchar(10) NOT NULL DEFAULT 'incident',
  `presence_emails` varchar(600) NOT NULL DEFAULT '',
  `presence_auto` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `g_docs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(10) NOT NULL,
  `numero` varchar(30) DEFAULT NULL,
  `devis_id` int(10) unsigned DEFAULT NULL,
  `client_id` int(10) unsigned DEFAULT NULL,
  `client` mediumtext DEFAULT NULL,
  `date_doc` date NOT NULL,
  `validite` date DEFAULT NULL,
  `echeance` date DEFAULT NULL,
  `objet` varchar(300) NOT NULL DEFAULT '',
  `zone` varchar(20) NOT NULL DEFAULT '',
  `paiement` varchar(10) NOT NULL DEFAULT '30j',
  `acompte` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tva` varchar(6) NOT NULL DEFAULT '20',
  `lignes` mediumtext DEFAULT NULL,
  `mention` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `total_ht` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_tva` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_ttc` decimal(12,2) NOT NULL DEFAULT 0.00,
  `statut` varchar(12) NOT NULL DEFAULT 'brouillon',
  `envoye_le` datetime DEFAULT NULL,
  `envoye_a` varchar(600) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  `ref_type` varchar(10) NOT NULL DEFAULT '',
  `ref_num` varchar(80) NOT NULL DEFAULT '',
  `accepte_le` datetime DEFAULT NULL,
  `accepte_par` varchar(250) NOT NULL DEFAULT '',
  `accepte_ip` varchar(60) NOT NULL DEFAULT '',
  `refus_motif` text DEFAULT NULL,
  `tva_mention` varchar(10) NOT NULL DEFAULT '',
  `compte` varchar(4) DEFAULT NULL,
  `avenant_de` int(10) unsigned DEFAULT NULL,
  `accepte_preuve` varchar(400) NOT NULL DEFAULT '',
  `pole` varchar(4) NOT NULL DEFAULT 'anim',
  `type_op` varchar(80) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_numero` (`type`,`numero`),
  KEY `type` (`type`,`date_doc`),
  KEY `client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `g_reglages` (
  `cle` varchar(40) NOT NULL,
  `valeur` mediumtext NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `geo_cache` (
  `k` char(40) NOT NULL,
  `v` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `groupes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(80) NOT NULL,
  `marques` varchar(400) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `jetons` (
  `hash` char(64) NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `expire_le` datetime NOT NULL,
  PRIMARY KEY (`hash`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `limites` (
  `k` char(40) NOT NULL,
  `n` int(11) NOT NULL,
  `debut` int(11) NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS `listes_diffusion` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(120) NOT NULL,
  `emails` text NOT NULL,
  `marques` varchar(400) NOT NULL DEFAULT '',
  `zones` varchar(200) NOT NULL DEFAULT '',
  `modeles` varchar(200) NOT NULL DEFAULT '',
  `ordre` int(11) NOT NULL DEFAULT 100,
  `circuit` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `magasins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `zone` varchar(20) NOT NULL,
  `enseigne` varchar(60) NOT NULL,
  `nom` varchar(120) NOT NULL,
  `ville` varchar(80) NOT NULL,
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `circuit` varchar(10) NOT NULL DEFAULT '',
  `adresse` varchar(255) NOT NULL DEFAULT '',
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `geo_approx` tinyint(4) NOT NULL DEFAULT 0,
  `zti` tinyint(4) NOT NULL DEFAULT 0,
  `dep` varchar(3) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `zone` (`zone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marges_couts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devis_id` int(10) unsigned NOT NULL,
  `mois` char(7) NOT NULL,
  `libelle` varchar(160) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `devis_id` (`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marges_hors` (
  `mois` char(7) NOT NULL,
  `nom` varchar(160) NOT NULL,
  `cotis` decimal(10,2) NOT NULL,
  `type` varchar(10) NOT NULL DEFAULT '',
  `marque` varchar(160) NOT NULL DEFAULT '',
  `libelle` varchar(160) NOT NULL DEFAULT '',
  `brut` decimal(10,2) DEFAULT NULL,
  `charges` decimal(10,2) DEFAULT NULL,
  `aid` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`mois`,`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marges_reel` (
  `mois` char(7) NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `brut` decimal(10,2) NOT NULL,
  `charges` decimal(10,2) NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `modifie_le` datetime NOT NULL,
  `cotis` decimal(10,2) DEFAULT NULL,
  `source` varchar(10) NOT NULL DEFAULT 'saisie',
  PRIMARY KEY (`mois`,`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marges_salaries` (
  `nom_tese` varchar(160) NOT NULL,
  `type` varchar(10) NOT NULL,
  `animatrice_id` int(10) unsigned DEFAULT NULL,
  `marque` varchar(160) NOT NULL DEFAULT '',
  `brut` decimal(10,2) DEFAULT NULL,
  `libelle` varchar(160) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `modifie_le` datetime NOT NULL,
  PRIMARY KEY (`nom_tese`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marges_syntheses` (
  `mois` char(7) NOT NULL,
  `fichier` varchar(80) NOT NULL,
  `nom` varchar(200) NOT NULL,
  `mime` varchar(60) NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`mois`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `marques` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nom` varchar(80) NOT NULL,
  `logo` varchar(120) NOT NULL DEFAULT '',
  `actif` tinyint(1) NOT NULL DEFAULT 1,
  `detail` varchar(200) NOT NULL DEFAULT '',
  `sous_marques` varchar(200) NOT NULL DEFAULT '',
  `ordre` int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nom` (`nom`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `merch_refs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `marque_id` int(10) unsigned NOT NULL,
  `nom` varchar(160) NOT NULL,
  `ean` varchar(20) NOT NULL DEFAULT '',
  `gamme` varchar(80) NOT NULL DEFAULT '',
  `prix_conseille` decimal(10,2) DEFAULT NULL,
  `facings_cible` int(11) DEFAULT NULL,
  `ordre` int(11) NOT NULL DEFAULT 100,
  `actif` tinyint(4) NOT NULL DEFAULT 1,
  `code` varchar(40) NOT NULL DEFAULT '',
  `a_relever` tinyint(4) NOT NULL DEFAULT 1,
  `pvp_par` varchar(160) NOT NULL DEFAULT '',
  `pvp_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `marque_id` (`marque_id`),
  KEY `ean` (`ean`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `merch_selection` (
  `devis_id` int(10) unsigned NOT NULL,
  `ref_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`devis_id`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `merch_visites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `devis_id` int(10) unsigned DEFAULT NULL,
  `marque_id` int(10) unsigned DEFAULT NULL,
  `magasin` varchar(160) NOT NULL DEFAULT '',
  `ville` varchar(80) NOT NULL DEFAULT '',
  `date_visite` date NOT NULL,
  `arrivee` varchar(5) NOT NULL DEFAULT '',
  `depart` varchar(5) NOT NULL DEFAULT '',
  `data` mediumtext DEFAULT NULL,
  `recommandations` text DEFAULT NULL,
  `valide_le` datetime DEFAULT NULL,
  `valide_par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `date_visite` (`date_visite`),
  KEY `devis_id` (`devis_id`),
  KEY `marque_id` (`marque_id`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `missions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `slot_key` varchar(255) DEFAULT NULL,
  `animatrice_id` int(10) unsigned DEFAULT NULL,
  `devis_id` int(10) unsigned DEFAULT NULL,
  `ligne` int(11) DEFAULT NULL,
  `date_mission` date NOT NULL,
  `debut` varchar(5) NOT NULL DEFAULT '10:00',
  `fin` varchar(5) NOT NULL DEFAULT '18:00',
  `pause` int(11) NOT NULL DEFAULT 60,
  `zone` varchar(20) NOT NULL DEFAULT '',
  `magasin` varchar(600) NOT NULL DEFAULT '',
  `poste` varchar(160) NOT NULL DEFAULT '',
  `client` varchar(160) NOT NULL DEFAULT '',
  `notes` text DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  `notifie_le` datetime DEFAULT NULL,
  `statut` varchar(10) NOT NULL DEFAULT 'prevue',
  `origine` varchar(10) NOT NULL DEFAULT 'devis',
  `remplace` date DEFAULT NULL,
  `remplace_par` date DEFAULT NULL,
  `motif` text DEFAULT NULL,
  `accord` varchar(250) NOT NULL DEFAULT '',
  `partie` tinyint(4) NOT NULL DEFAULT 0,
  `fin_orig` varchar(5) DEFAULT NULL,
  `pause_orig` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slot_key` (`slot_key`),
  KEY `animatrice_id` (`animatrice_id`,`date_mission`),
  KEY `devis_id` (`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `missions_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devis_id` int(10) unsigned NOT NULL,
  `slot_key` varchar(255) NOT NULL DEFAULT '',
  `texte` varchar(600) NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `devis_id` (`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `ndf` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `jour` date NOT NULL,
  `type` varchar(12) NOT NULL,
  `montant` decimal(10,2) NOT NULL,
  `devis_id` int(10) unsigned DEFAULT NULL,
  `magasin` varchar(160) NOT NULL DEFAULT '',
  `commentaire` varchar(500) NOT NULL DEFAULT '',
  `fichier` varchar(80) NOT NULL DEFAULT '',
  `mime` varchar(60) NOT NULL DEFAULT '',
  `statut` varchar(10) NOT NULL DEFAULT 'a_valider',
  `motif` varchar(300) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `decide_le` datetime DEFAULT NULL,
  `paye_mois` char(7) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`,`jour`),
  KEY `statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `notifs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `titre` varchar(160) NOT NULL,
  `texte` varchar(400) NOT NULL DEFAULT '',
  `vue` varchar(20) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `affiche_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `objectifs_anim` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned DEFAULT NULL,
  `marque_id` int(10) unsigned NOT NULL,
  `magasin_id` int(10) unsigned DEFAULT NULL,
  `date_debut` date NOT NULL,
  `date_fin` date NOT NULL,
  `montant` decimal(12,2) DEFAULT NULL,
  `sous` text DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `marque_id` (`marque_id`,`date_debut`,`date_fin`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `objectifs_ba` (
  `animatrice_id` int(10) unsigned NOT NULL,
  `mois` char(7) NOT NULL,
  `enseigne` varchar(60) NOT NULL,
  `categorie` varchar(10) NOT NULL,
  `montant` decimal(12,2) DEFAULT NULL,
  `par_admin` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`animatrice_id`,`mois`,`enseigne`,`categorie`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `offres` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `devis_id` int(10) unsigned NOT NULL,
  `slots` mediumtext NOT NULL,
  `rayon` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `statut` varchar(8) NOT NULL DEFAULT 'ouvert',
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `clos_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `devis_id` (`devis_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `offres_dest` (
  `offre_id` int(10) unsigned NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `token` char(32) NOT NULL,
  `distance` decimal(6,1) DEFAULT NULL,
  `reponse` varchar(4) DEFAULT NULL,
  `dates` text DEFAULT NULL,
  `repondu_le` datetime DEFAULT NULL,
  `retenu` tinyint(4) NOT NULL DEFAULT 0,
  `refus_envoye` datetime DEFAULT NULL,
  PRIMARY KEY (`offre_id`,`animatrice_id`),
  UNIQUE KEY `token` (`token`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `paye_virements` (
  `mois` char(7) NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `net` decimal(10,2) DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `maj` datetime DEFAULT NULL,
  PRIMARY KEY (`mois`,`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) NOT NULL,
  `rapport_id` int(10) unsigned DEFAULT NULL,
  `rapport_marque_id` int(10) unsigned DEFAULT NULL,
  `slot` varchar(40) NOT NULL,
  `fichier` varchar(80) NOT NULL,
  `created_at` datetime NOT NULL,
  `ba_id` int(10) unsigned DEFAULT NULL,
  `client` tinyint(1) NOT NULL DEFAULT 1,
  `evt_id` int(10) unsigned DEFAULT NULL,
  `merch_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `token` (`token`),
  KEY `rapport_id` (`rapport_id`),
  KEY `ba_id` (`ba_id`),
  KEY `evt_id` (`evt_id`),
  KEY `merch_id` (`merch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `pieces_identite` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `type` varchar(12) NOT NULL,
  `fichier` varchar(80) NOT NULL,
  `nom` varchar(200) NOT NULL DEFAULT '',
  `mime` varchar(60) NOT NULL DEFAULT '',
  `taille` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `titre` varchar(160) NOT NULL DEFAULT '',
  `periode` varchar(7) DEFAULT NULL,
  `vu_le` datetime DEFAULT NULL,
  `expire_le` date DEFAULT NULL,
  `rappel_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `presence_envois` (
  `client_id` int(10) unsigned NOT NULL,
  `jour` date NOT NULL,
  `sujet` varchar(250) NOT NULL DEFAULT '',
  `texte` text DEFAULT NULL,
  `a` varchar(600) NOT NULL DEFAULT '',
  `statut` varchar(10) NOT NULL DEFAULT 'envoye',
  `envoye_le` datetime DEFAULT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`client_id`,`jour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `prises_poste` (
  `mission_id` int(10) unsigned NOT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `date_mission` date NOT NULL,
  `le` datetime DEFAULT NULL,
  `heure` varchar(5) NOT NULL DEFAULT '',
  `ecart_min` int(11) DEFAULT NULL,
  `lat` double DEFAULT NULL,
  `lng` double DEFAULT NULL,
  `precision_m` int(11) DEFAULT NULL,
  `distance_m` int(11) DEFAULT NULL,
  `ip` varchar(60) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `statut_admin` varchar(12) NOT NULL DEFAULT '',
  `note` varchar(300) NOT NULL DEFAULT '',
  `par` varchar(120) NOT NULL DEFAULT '',
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`mission_id`,`animatrice_id`),
  KEY `date_mission` (`date_mission`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `push_abos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `endpoint` varchar(700) NOT NULL,
  `p256dh` varchar(200) NOT NULL DEFAULT '',
  `auth` varchar(100) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  `dernier_envoi` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `endpoint` (`endpoint`(255)),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `rapport_marques` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `rapport_id` int(10) unsigned NOT NULL,
  `marque_id` int(10) unsigned NOT NULL,
  `ca` decimal(12,2) DEFAULT NULL,
  `objectif` decimal(12,2) DEFAULT NULL,
  `ruptures` text DEFAULT NULL,
  `testeurs` text DEFAULT NULL,
  `reactions` text DEFAULT NULL,
  `ventilation` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `rapport_id` (`rapport_id`),
  KEY `marque_id` (`marque_id`),
  CONSTRAINT `rapport_marques_ibfk_1` FOREIGN KEY (`rapport_id`) REFERENCES `rapports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rapport_marques_ibfk_2` FOREIGN KEY (`marque_id`) REFERENCES `marques` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `rapports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) DEFAULT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `zone` varchar(20) NOT NULL,
  `magasin_id` int(10) unsigned DEFAULT NULL,
  `magasin` varchar(120) NOT NULL,
  `ville` varchar(80) NOT NULL,
  `devise` varchar(5) NOT NULL DEFAULT 'EUR',
  `date_animation` date NOT NULL,
  `frequentation` varchar(20) NOT NULL DEFAULT '',
  `concurrence` text DEFAULT NULL,
  `concurrence_autre` varchar(200) NOT NULL DEFAULT '',
  `offres` text DEFAULT NULL,
  `staff` text DEFAULT NULL,
  `faits` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT '',
  `valide_le` datetime DEFAULT NULL,
  `valide_par` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `date_animation` (`date_animation`),
  KEY `zone` (`zone`),
  KEY `animatrice_id` (`animatrice_id`),
  CONSTRAINT `rapports_ibfk_1` FOREIGN KEY (`animatrice_id`) REFERENCES `animatrices` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `rapports_ba` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `token` char(32) DEFAULT NULL,
  `animatrice_id` int(10) unsigned NOT NULL,
  `modele` varchar(30) NOT NULL,
  `date_animation` date NOT NULL,
  `statut` varchar(20) NOT NULL DEFAULT 'Travaillé',
  `magasin` varchar(120) NOT NULL DEFAULT '',
  `ville` varchar(80) NOT NULL DEFAULT '',
  `data` mediumtext DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  `modifie_par` varchar(120) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `animatrice_id` (`animatrice_id`,`date_animation`),
  KEY `modele` (`modele`,`date_animation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `rapports_clients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `compte_id` int(10) unsigned NOT NULL,
  `nom` varchar(120) NOT NULL,
  `params` text NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `compte_id` (`compte_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `reglages` (
  `cle` varchar(40) NOT NULL,
  `valeur` varchar(200) NOT NULL,
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `releves` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `mois` varchar(21) NOT NULL,
  `statut` varchar(10) NOT NULL DEFAULT 'brouillon',
  `ajust` mediumtext DEFAULT NULL,
  `extras` mediumtext DEFAULT NULL,
  `lignes` mediumtext DEFAULT NULL,
  `minutes` int(11) NOT NULL DEFAULT 0,
  `commentaire` text DEFAULT NULL,
  `base_hash` char(40) NOT NULL DEFAULT '',
  `envoye_le` datetime DEFAULT NULL,
  `envoye_par` varchar(120) NOT NULL DEFAULT '',
  `signe_le` datetime DEFAULT NULL,
  `signe_nom` varchar(160) NOT NULL DEFAULT '',
  `signe_ip` varchar(60) NOT NULL DEFAULT '',
  `signe_ua` varchar(255) NOT NULL DEFAULT '',
  `signature` mediumtext DEFAULT NULL,
  `signe_test` tinyint(4) NOT NULL DEFAULT 0,
  `empreinte` char(64) NOT NULL DEFAULT '',
  `journal` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `modifie_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `animatrice_id` (`animatrice_id`,`mois`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `sepa_exports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mois` char(7) NOT NULL,
  `msg_id` varchar(35) NOT NULL,
  `nb` int(11) NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `execution` date NOT NULL,
  `par` varchar(120) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `mois` (`mois`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `verifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `animatrice_id` int(10) unsigned NOT NULL,
  `le` datetime NOT NULL,
  `email` varchar(190) NOT NULL DEFAULT '',
  `ip` varchar(60) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `animatrice_id` (`animatrice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;
