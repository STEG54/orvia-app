<?php
// Factures des intervenants auto-entrepreneurs : déposées dans l'appli (PDF ou photo, chiffrées), suivies par l'agence jusqu'au paiement.
const AE_STATUTS = ['recue' => 'Reçue', 'validee' => 'Validée', 'payee' => 'Payée', 'refusee' => 'Refusée'];
function ae_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  db()->exec("CREATE TABLE IF NOT EXISTS factures_ae (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, animatrice_id INT UNSIGNED NOT NULL, numero VARCHAR(60) NOT NULL DEFAULT '', montant DECIMAL(10,2) NOT NULL,
    periode CHAR(7) NOT NULL DEFAULT '', devis_id INT UNSIGNED NULL, commentaire VARCHAR(500) NOT NULL DEFAULT '', fichier VARCHAR(80) NOT NULL, nom VARCHAR(200) NOT NULL DEFAULT '', mime VARCHAR(60) NOT NULL DEFAULT '',
    statut VARCHAR(10) NOT NULL DEFAULT 'recue', motif VARCHAR(300) NOT NULL DEFAULT '', par VARCHAR(120) NOT NULL DEFAULT '', decide_le DATETIME NULL, created_at DATETIME NOT NULL, INDEX (animatrice_id), INDEX (statut))
    ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
/** La personne est-elle auto-entrepreneur (fiche) ? */
function ae_est(int $aid): bool { $d = (array)fiche_get($aid)['data']; return in_array((string)($d['statut'] ?? ''), [FICHE_AE, 'Auto-entrepreneuse'], true); }
function ae_liste(array $w = [], array $a = []): array {
  ae_schema(); $st = db()->prepare("SELECT f.*, CONCAT(UPPER(an.nom), ' ', an.prenom) AS qui, an.matricule, d.numero AS devis, d.objet FROM factures_ae f JOIN animatrices an ON an.id = f.animatrice_id LEFT JOIN g_docs d ON d.id = f.devis_id"
    . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY f.created_at DESC LIMIT 1000"); $st->execute($a);
  return array_map(fn($x) => ['id' => (int)$x['id'], 'animatrice_id' => (int)$x['animatrice_id'], 'qui' => $x['qui'], 'matricule' => $x['matricule'], 'numero' => $x['numero'], 'montant' => (float)$x['montant'],
    'periode' => $x['periode'], 'devis' => $x['devis'], 'objet' => $x['objet'], 'commentaire' => $x['commentaire'], 'statut' => $x['statut'], 'statut_txt' => AE_STATUTS[$x['statut']] ?? $x['statut'], 'motif' => $x['motif'],
    'created_at' => $x['created_at'], 'url' => 'piece.php?fa=' . (int)$x['id']], $st->fetchAll());
}
