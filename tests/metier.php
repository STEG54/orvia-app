<?php
// Non-régression des fonctions métier sur une installation de DÉMO (données fictives) : planning, contrats, signature,
// paie, relevés, fins de contrat, devis et factures, comptes rendus, merchandising, exports.
// Usage : php tests/metier.php https://adresse-de-la-demo CLE_EDITEUR_DE_LA_DEMO
if (PHP_SAPI !== 'cli') exit;
[$_, $B, $cle] = $argv + [null, '', '']; if (!$B || !$cle) { fwrite(STDERR, "Usage : php tests/metier.php <adresse de la démo> <AMAYA_DEMO_CLE>\n"); exit(2); }
$B = rtrim($B, '/'); $jar = tempnam(sys_get_temp_dir(), 'amj'); $ok = 0; $ko = 0;
function t(string $n, $c, string $info = ''): void { global $ok, $ko; if ($c) { $ok++; echo "  ok   $n\n"; } else { $ko++; echo "  ÉCHEC $n" . ($info ? " : $info" : '') . "\n"; } }
function http(string $url, $body = null, bool $form = false): array {
  global $B, $jar; $c = curl_init(str_starts_with($url, 'http') ? $url : "$B/$url");
  $h = ['X-Requested-With: lpi']; if ($body !== null && !$form) { $h[] = 'Content-Type: application/json'; $body = json_encode($body); }
  curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => $h]
    + ($body !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body] : []));
  $r = curl_exec($c); $i = curl_getinfo($c); curl_close($c); return ['code' => $i['http_code'], 'type' => (string)$i['content_type'], 'body' => (string)$r, 'json' => json_decode((string)$r, true)];
}
$pdf = fn($r) => $r['code'] === 200 && str_starts_with($r['body'], '%PDF');
$xlsx = fn($r) => $r['code'] === 200 && str_starts_with($r['body'], 'PK');
$mois = date('Y-m'); $prec = date('Y-m', strtotime('first day of last month'));

echo "Démo neuve (environ 20 s)\n";
http("demo.php?cle=" . rawurlencode($cle) . "&neuf=1"); $r = http('demo.php?prep=1');
t('agence fictive créée', ($r['json']['ok'] ?? false) === true, substr($r['body'], 0, 200));
http('demo.php?role=direction'); $r = http('api.php?action=session'); t('connexion direction', !empty($r['json']['admin']));

echo "Devis et factures\n";
$r = http('gestion_api.php?action=docs&type=devis'); $dv = $r['json']['docs'] ?? []; t('liste des devis', count($dv) > 0);
if ($dv) t('PDF de devis', $pdf(http('gestion_api.php?action=pdf&id=' . $dv[0]['id'])));
$acc = array_values(array_filter($dv, fn($d) => in_array($d['statut'], ['accepte', 'signe'], true) && empty($d['facture_id']))) ?: $dv;
$f = http('gestion_api.php?action=facture_depuis_devis', ['id' => $acc[0]['id']]); t('facture créée depuis un devis', isset($f['json']['doc']) || isset($f['json']['id']) || ($f['json']['ok'] ?? false), substr($f['body'], 0, 200));
$r = http('gestion_api.php?action=docs&type=facture'); $fa = $r['json']['docs'] ?? []; t('liste des factures', count($fa) > 0);
if ($fa) t('PDF de facture', $pdf(http('gestion_api.php?action=pdf&id=' . $fa[0]['id'])));

echo "Planning\n";
$r = http('planning_api.php?action=operations'); $ops = $r['json']['operations'] ?? $r['json']['ops'] ?? []; t('liste des opérations', count($ops) > 0, substr($r['body'], 0, 150));
if ($ops) { $o = http('planning_api.php?action=operation&id=' . ($ops[0]['id'] ?? 0)); t('détail d\'une opération', $o['code'] === 200 && is_array($o['json']), substr($o['body'], 0, 150)); }

echo "Contrats et signature\n";
$r = http('planning_api.php?action=contrats'); $L = $r['json']['contrats'] ?? []; t('liste des contrats', count($L) > 0);
$signe = array_values(array_filter($L, fn($c) => ($c['etat'] ?? '') === 'signe'));
$aEnv = array_values(array_filter($L, fn($c) => ($c['etat'] ?? '') === 'a_envoyer'));
if ($aEnv) { $c0 = $aEnv[0]; t('PDF d\'aperçu de contrat', $pdf(http("planning_api.php?action=contrat_pdf&animatrice_id={$c0['animatrice_id']}&devis_id={$c0['devis_id']}")));
  $e = http('planning_api.php?action=contrat_envoyer', ['animatrice_id' => $c0['animatrice_id'], 'devis_id' => $c0['devis_id']]);
  // Règle métier : un contrat ne part pas si la fiche est incomplète (n° de sécurité sociale, etc.).
  t('envoi du contrat ou refus motivé si la fiche est incomplète', ($e['json']['ok'] ?? false) === true || ($c0['manque'] && str_contains((string)($e['json']['error'] ?? ''), 'manque') || $c0['manque'] && $e['code'] === 400), substr($e['body'], 0, 200)); }
$env = array_values(array_filter($L, fn($c) => ($c['etat'] ?? '') === 'envoye'));
if ($env) { $c0 = $env[0];
  // Signature dans l'appli, en aperçu (test), avec un tracé PNG.
  $im = imagecreatetruecolor(300, 120); imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255)); for ($i = 0; $i < 40; $i++) imageline($im, 10 + 6 * $i, 60 + (int)(30 * sin($i)), 16 + 6 * $i, 60 + (int)(30 * sin($i + 1)), imagecolorallocate($im, 0, 0, 0));
  ob_start(); imagepng($im); $sig = 'data:image/png;base64,' . base64_encode(ob_get_clean());
  http('api.php?action=apercu_start', ['id' => $c0['animatrice_id']]); $mc = http('api.php?action=mes_contrats'); $a = array_values(array_filter($mc['json']['contrats'] ?? [], fn($x) => $x['statut'] === 'envoye'));
  t('contrat à signer visible dans l\'appli', count($a) > 0, substr($mc['body'], 0, 200));
  if ($a) { $s = http('api.php?action=contrat_signer', ['id' => $a[0]['id'], 'certifie' => 1, 'mention' => 'Lu et approuvé', 'nom' => 'Test Signature', 'signature' => $sig]); t('signature du contrat', ($s['json']['ok'] ?? false) === true, substr($s['body'], 0, 200));
    http('api.php?action=apercu_stop', []); t('PDF du contrat signé (archive chiffrée)', $pdf(http('planning_api.php?action=contrat_pdf&id=' . $a[0]['id']))); }
  else http('api.php?action=apercu_stop', []);
} else t('un contrat envoyé à signer dans la démo', false);
$g = http('gestion_api.php?action=preuve&id=' . ($signe[0]['contrats'][0]['id'] ?? 0)); // dossier de preuve d'un contrat déjà signé, s'il existe

echo "Paie, relevés, fins de contrat\n";
$p = http("planning_api.php?action=paye&mois=$prec"); t('préparation de paie', isset($p['json']['lignes']), substr($p['body'], 0, 150));
t('Excel de paie', $xlsx(http("planning_api.php?action=paye_xlsx&mois=$prec")));
$v = http("planning_api.php?action=virements&mois=$prec"); t('écran des virements', $v['code'] === 200 && is_array($v['json']), substr($v['body'], 0, 150));
$rl = http("planning_api.php?action=releves&mois=$prec"); t('relevés d\'heures', $rl['code'] === 200 && is_array($rl['json']), substr($rl['body'], 0, 150));
$aid = (int)($rl['json']['releves'][0]['animatrice']['id'] ?? 0); t('au moins un relevé à préparer', $aid > 0);
if ($aid) { t('PDF de relevé', $pdf(http("planning_api.php?action=releve_pdf&animatrice_id=$aid&mois=$prec")));
  $e = http('planning_api.php?action=releve_envoyer', ['animatrice_id' => $aid, 'mois' => $prec]); t('envoi du relevé à signer', ($e['json']['ok'] ?? false) === true, substr($e['body'], 0, 200));
  http('api.php?action=apercu_start', ['id' => $aid]);
  $s = http('api.php?action=releve_signer', ['mois' => $prec, 'certifie' => 1, 'nom' => 'Test Signature', 'signature' => $sig ?? '']); t('signature du relevé', ($s['json']['ok'] ?? false) === true, substr($s['body'], 0, 200));
  http('api.php?action=apercu_stop', []); }
$fin = array_values(array_filter($L, fn($c) => $c['termine'] ?? false));
if ($fin) { $q = "animatrice_id={$fin[0]['animatrice_id']}&devis_id={$fin[0]['devis_id']}";
  http('planning_api.php?action=fin_solde_net', ['animatrice_id' => $fin[0]['animatrice_id'], 'devis_id' => $fin[0]['devis_id'], 'net' => '1234,56', 'mutuelle' => 'affilie', 'prevoyance' => 'dispense']);
  t('certificat de travail', $pdf(http("planning_api.php?action=fin_certificat&$q"))); t('reçu pour solde de tout compte', $pdf(http("planning_api.php?action=fin_solde&$q"))); }
t('Excel des marges', $xlsx(http("planning_api.php?action=marges_xlsx&mois=$prec")));

echo "Comptes rendus, merchandising, exports\n";
$x = http('api.php?action=export&from=2000-01-01&to=2100-01-01'); t('export CSV des comptes rendus', $x['code'] === 200 && str_contains($x['type'], 'csv') && substr_count($x['body'], "\n") > 5);
$mv = http('api.php?action=mc_visites&from=2000-01-01&to=2100-01-01'); t('visites merchandising', count($mv['json']['visites'] ?? []) > 0, substr($mv['body'], 0, 150));
t('Excel merchandising', $xlsx(http('api.php?action=mc_xlsx&from=2000-01-01&to=2100-01-01')));
t('rapport merchandising PDF', $pdf(http('api.php?action=mc_rapport_pdf&from=2000-01-01&to=2100-01-01')));

@unlink($jar); echo "\n$ok réussis, $ko en échec\n"; exit($ko ? 1 : 0);
