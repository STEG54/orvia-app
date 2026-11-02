<?php
// Virements des salaires : net à payer saisi d'après les bulletins (jamais calculé ici), fichier SEPA pain.001.001.03 à déposer dans l'espace banque.
require_once __DIR__ . '/paye_lib.php';

function sp_schema(): void {
  static $ok = false; if ($ok) return; $ok = true;
  db()->exec("CREATE TABLE IF NOT EXISTS paye_virements (mois CHAR(7) NOT NULL, animatrice_id INT UNSIGNED NOT NULL, net DECIMAL(10,2) NULL, par VARCHAR(120) NOT NULL DEFAULT '', maj DATETIME NULL,
    PRIMARY KEY (mois, animatrice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  db()->exec("CREATE TABLE IF NOT EXISTS sepa_exports (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, mois CHAR(7) NOT NULL, msg_id VARCHAR(35) NOT NULL, nb INT NOT NULL, total DECIMAL(12,2) NOT NULL,
    execution DATE NOT NULL, par VARCHAR(120) NOT NULL DEFAULT '', created_at DATETIME NOT NULL, INDEX (mois)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** IBAN valide (clé ISO 13616, modulo 97). */
function sp_iban_ok(string $iban): bool {
  $i = strtoupper(preg_replace('/\s+/', '', $iban)); if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $i)) return false;
  $r = substr($i, 4) . substr($i, 0, 4); $n = ''; foreach (str_split($r) as $ch) $n .= ctype_alpha($ch) ? (string)(ord($ch) - 55) : $ch;
  $m = 0; foreach (str_split($n, 7) as $part) $m = (int)(($m . $part) % 97); return $m === 1;
}
function sp_bic_ok(string $bic): bool { return (bool)preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', strtoupper(trim($bic))); }
function sp_masque(string $iban): string { $i = preg_replace('/\s+/', '', $iban); return $i === '' ? '' : substr($i, 0, 4) . ' •••• ' . substr($i, -4); }
/** Texte SEPA : caractères latins de base seulement (les banques refusent le reste). */
function sp_txt(string $s, int $max): string {
  $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT', $s); $s = preg_replace('#[^A-Za-z0-9/\-?:().,\'+ ]#', ' ', $s);
  return htmlspecialchars(mb_substr(trim(preg_replace('/\s+/', ' ', $s)), 0, $max), ENT_XML1);
}

/** Une ligne par personne payée le mois : brut et frais (estimés d'après la préparation de paye), net saisi, coordonnées bancaires. */
function sp_lignes(string $mois): array {
  sp_schema(); $X = pa_lignes($mois); $P = [];
  foreach ($X as $x) { $k = pa_calc($x); $a = (int)$x['animatrice_id'];
    $p = &$P[$a]; $p ??= ['animatrice_id' => $a, 'nom' => $x['nom'], 'matricule' => $x['matricule'], 'poles' => [], 'brut' => 0, 'frais' => 0];
    $p['poles'][$x['pole'] ?? 'anim'] = true; $p['brut'] += $k['total_brut']; $p['frais'] += $k['frais']; unset($p); }
  $st = db()->prepare("SELECT animatrice_id, net, maj, par FROM paye_virements WHERE mois = ?"); $st->execute([$mois]); $N = []; foreach ($st->fetchAll() as $r) $N[(int)$r['animatrice_id']] = $r;
  $taux = c_num((string)(g_reg()['cotis_sal'] ?? '21,8')) / 100; $out = [];
  foreach ($P as $a => $p) {
    $f = (array)fiche_get($a)['data']; $iban = strtoupper(preg_replace('/\s+/', '', (string)($f['iban'] ?? ''))); $bic = strtoupper(trim((string)($f['bic'] ?? '')));
    $out[] = ['animatrice_id' => $a, 'nom' => $p['nom'], 'matricule' => $p['matricule'], 'poles' => array_keys($p['poles']), 'brut' => round($p['brut'], 2), 'frais' => round($p['frais'], 2),
      'estimation' => round($p['brut'] * (1 - $taux) + $p['frais'], 2), 'net' => isset($N[$a]) && $N[$a]['net'] !== null ? (float)$N[$a]['net'] : null,
      'iban' => sp_masque($iban), 'iban_ok' => sp_iban_ok($iban), 'bic' => $bic, 'bic_ok' => $bic === '' || sp_bic_ok($bic)];
  }
  usort($out, fn($a, $b) => strcasecmp($a['nom'], $b['nom']));
  return $out;
}

/** Nets saisis à la main ou collés depuis le logiciel de paye (« matricule ou NOM Prénom ; montant », une ligne par personne). */
function sp_enregistrer(string $mois, array $nets): int {
  sp_schema(); $n = 0; $st = db()->prepare("REPLACE INTO paye_virements (mois, animatrice_id, net, par, maj) VALUES (?,?,?,?,?)");
  foreach ($nets as $aid => $v) { $v = trim(str_replace([' ', "\u{a0}", '€'], '', (string)$v)); $val = $v === '' ? null : round((float)str_replace(',', '.', $v), 2);
    if ($val !== null && ($val < 0 || $val > 100000)) continue; $st->execute([$mois, (int)$aid, $val, admin_nom(), now()]); $n++; }
  return $n;
}
function sp_coller(string $mois, string $texte): array {
  $L = sp_lignes($mois); $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string)iconv('UTF-8', 'ASCII//TRANSLIT', $s))));
  $nets = []; $inconnus = [];
  foreach (preg_split('/\R/', $texte) as $ln) { if (trim($ln) === '') continue;
    $c = preg_split('/[;\t]/', $ln); if (count($c) < 2) { $inconnus[] = trim($ln); continue; }
    $qui = $norm($c[0]); $mt = trim(end($c)); $trouve = null;
    foreach ($L as $x) if ($qui !== '' && ($norm($x['matricule']) === $qui || $norm($x['nom']) === $qui)) { $trouve = $x['animatrice_id']; break; }
    if ($trouve) $nets[$trouve] = $mt; else $inconnus[] = trim($c[0]); }
  return ['enregistres' => sp_enregistrer($mois, $nets), 'inconnus' => $inconnus];
}

/** Fichier pain.001.001.03 (virements SEPA, catégorie salaires). Seules les personnes avec un net et un IBAN valide y figurent. */
function sp_xml(string $mois, string $execution): array {
  $r = g_reg(); $iban = strtoupper(preg_replace('/\s+/', '', (string)$r['iban'])); $bic = strtoupper(trim((string)$r['bic']));
  if (!sp_iban_ok($iban)) throw new RuntimeException('IBAN de l\'agence absent ou invalide : Réglages > Banque et règlement.');
  if ($bic !== '' && !sp_bic_ok($bic)) throw new RuntimeException('BIC de l\'agence invalide : Réglages > Banque et règlement.');
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $execution) || $execution < date('Y-m-d')) throw new RuntimeException('Date d\'exécution invalide (aujourd\'hui ou plus tard).');
  $T = []; $exclus = [];
  foreach (sp_lignes($mois) as $x) {
    if ($x['net'] === null || $x['net'] <= 0) { $exclus[] = $x['nom'] . ' (net non saisi)'; continue; }
    if (!$x['iban_ok']) { $exclus[] = $x['nom'] . ' (IBAN absent ou invalide)'; continue; }
    $f = (array)fiche_get($x['animatrice_id'])['data']; $x['iban_clair'] = strtoupper(preg_replace('/\s+/', '', (string)$f['iban'])); $T[] = $x;
  }
  if (!$T) throw new RuntimeException('Aucun virement à émettre : saisissez les nets à payer et vérifiez les IBAN.');
  $msg = 'SAL' . str_replace('-', '', $mois) . '-' . date('YmdHis'); $total = number_format(array_sum(array_column($T, 'net')), 2, '.', ''); $nb = count($T);
  $mtxt = r_mois_txt($mois); $nom = sp_txt($r['societe'] ?: AG_NOM, 70);
  $tx = '';
  foreach ($T as $i => $x) {
    $tx .= '<CdtTrfTxInf><PmtId><EndToEndId>' . sp_txt($msg . '-' . ($i + 1), 35) . '</EndToEndId></PmtId><Amt><InstdAmt Ccy="EUR">' . number_format($x['net'], 2, '.', '') . '</InstdAmt></Amt>'
      . ($x['bic'] !== '' && $x['bic_ok'] ? '<CdtrAgt><FinInstnId><BIC>' . $x['bic'] . '</BIC></FinInstnId></CdtrAgt>' : '')
      . '<Cdtr><Nm>' . sp_txt($x['nom'], 70) . '</Nm></Cdtr><CdtrAcct><Id><IBAN>' . $x['iban_clair'] . '</IBAN></Id></CdtrAcct>'
      . '<RmtInf><Ustrd>' . sp_txt('Salaire ' . $mtxt . ($x['matricule'] ? ' ' . $x['matricule'] : ''), 140) . '</Ustrd></RmtInf></CdtTrfTxInf>';
  }
  $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><CstmrCdtTrfInitn>'
    . '<GrpHdr><MsgId>' . $msg . '</MsgId><CreDtTm>' . date('Y-m-d\TH:i:s') . '</CreDtTm><NbOfTxs>' . $nb . '</NbOfTxs><CtrlSum>' . $total . '</CtrlSum><InitgPty><Nm>' . $nom . '</Nm></InitgPty></GrpHdr>'
    . '<PmtInf><PmtInfId>' . $msg . '</PmtInfId><PmtMtd>TRF</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>' . $nb . '</NbOfTxs><CtrlSum>' . $total . '</CtrlSum>'
    . '<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><CtgyPurp><Cd>SALA</Cd></CtgyPurp></PmtTpInf><ReqdExctnDt>' . $execution . '</ReqdExctnDt>'
    . '<Dbtr><Nm>' . $nom . '</Nm></Dbtr><DbtrAcct><Id><IBAN>' . $iban . '</IBAN></Id></DbtrAcct><DbtrAgt><FinInstnId>' . ($bic !== '' ? '<BIC>' . $bic . '</BIC>' : '<Othr><Id>NOTPROVIDED</Id></Othr>') . '</FinInstnId></DbtrAgt>'
    . '<ChrgBr>SLEV</ChrgBr>' . $tx . '</PmtInf></CstmrCdtTrfInitn></Document>';
  sp_schema(); db()->prepare("INSERT INTO sepa_exports (mois, msg_id, nb, total, execution, par, created_at) VALUES (?,?,?,?,?,?,?)")->execute([$mois, $msg, $nb, $total, $execution, admin_nom(), now()]);
  return ['xml' => $xml, 'fichier' => 'Virements_salaires_' . $mois . '.xml', 'nb' => $nb, 'total' => (float)$total, 'exclus' => $exclus];
}
