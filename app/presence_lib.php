<?php
// E-mail de présence aux marques : à partir de la prise de poste, un message court par client et par jour
// (seulement en cas d'incident, ou chaque jour), validé par la direction ou envoyé tout seul. Récapitulatif de fin d'opération.
require_once __DIR__ . '/poste_lib.php';

const PR_MODES = ['incident' => 'En cas de problème (retard, absence, remplacement)', 'aucun' => 'Jamais'];

function pr_schema(): void {
  static $ok = false; if ($ok) return; $ok = true; pp_schema(); g_schema(); $pdo = db();
  if (!in_array('presence_mode', $pdo->query("SHOW COLUMNS FROM g_clients")->fetchAll(PDO::FETCH_COLUMN), true))
    $pdo->exec("ALTER TABLE g_clients ADD COLUMN presence_mode VARCHAR(10) NOT NULL DEFAULT 'incident', ADD COLUMN presence_emails VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN presence_auto TINYINT(1) NOT NULL DEFAULT 0");
  $pdo->exec("CREATE TABLE IF NOT EXISTS presence_envois (client_id INT UNSIGNED NOT NULL, jour DATE NOT NULL, sujet VARCHAR(250) NOT NULL DEFAULT '', texte TEXT NULL, a VARCHAR(600) NOT NULL DEFAULT '',
    statut VARCHAR(10) NOT NULL DEFAULT 'envoye', envoye_le DATETIME NULL, par VARCHAR(120) NOT NULL DEFAULT '', PRIMARY KEY (client_id, jour)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function pr_prenom_nom(string $n): string { $p = explode(' ', trim($n), 2); return isset($p[1]) ? mb_convert_case(mb_strtolower($p[1]), MB_CASE_TITLE) . ' ' . mb_substr($p[0], 0, 1) . '.' : $n; } // « Linda D. » : discret pour la marque
function pr_h(string $hm): string { return preg_replace('/^0?(\d+):(\d\d)$/', '$1h$2', $hm); }

/** Messages du jour, un par client : lignes, incidents, texte proposé, état de l'envoi. */
function pr_jour(string $date): array {
  pr_schema(); $L = pp_jour($date); if (!$L) return [];
  $docs = []; $par = [];
  foreach ($L as $x) { if (!$x['devis_id']) continue; $docs[$x['devis_id']] ??= g_doc($x['devis_id']); $d = $docs[$x['devis_id']]; if (!$d || !$d['client_id']) continue; $par[(int)$d['client_id']][] = $x + ['objet' => $d['objet']]; }
  $out = []; $jt = g_date($date); $jl = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'][(int)date('w', strtotime($date))];
  foreach ($par as $cid => $X) {
    $st = db()->prepare("SELECT * FROM g_clients WHERE id = ?"); $st->execute([$cid]); $c = $st->fetch(); if (!$c) continue;
    $env = db()->prepare("SELECT * FROM presence_envois WHERE client_id = ? AND jour = ?"); $env->execute([$cid, $date]); $e = $env->fetch() ?: null;
    usort($X, fn($a, $b) => [$a['magasin'], $a['debut']] <=> [$b['magasin'], $b['debut']]);
    $lignes = []; $incL = []; $inc = 0; $prevus = 0; $ok = 0; $avenir = 0;
    foreach ($X as $x) { $n = pr_prenom_nom($x['nom']); $mag = $x['magasin'] ?: 'point de vente';
      if ($x['remplace_par']) { $inc++; $incL[] = $mag . ' : ' . $n . ' absente, remplacée par ' . pr_prenom_nom($x['remplace_par']) . '.'; continue; }
      $prevus++;
      $t = match ($x['code']) {
        'en_poste' => 'en poste à ' . pr_h($x['heure']) . '.',
        'present' => 'présente.',
        'retard' => 'arrivée à ' . pr_h($x['heure']) . ' (' . $x['ecart'] . ' min de retard).',
        'absent' => 'absente' . ($x['note'] ? ' (' . $x['note'] . ')' : '') . ', journée non facturée.',
        'non_signale' => 'arrivée non confirmée pour l\'instant.',
        default => 'à partir de ' . pr_h($x['debut']) . '.',
      };
      if (in_array($x['code'], ['en_poste', 'present', 'retard'], true)) $ok++; if ($x['code'] === 'a_venir') $avenir++;
      $li = $mag . ' : ' . $n . ($x['partie'] === 2 ? ' prend le relais à ' . pr_h($x['debut']) . ', ' : ', ') . ($x['remplace'] ? 'en remplacement, ' : '') . $t;
      if (in_array($x['code'], ['retard', 'absent'], true) || $x['partie'] === 2) { $inc++; $incL[] = $li; } elseif (!$x['remplace']) $lignes[] = $li; }
    $rs = trim((string)$c['contact']); $pre = $rs !== '' ? mb_convert_case(mb_strtolower(explode(' ', $rs)[0]), MB_CASE_TITLE) : '';
    $sujet = 'Présence de vos animatrices · ' . $jl . ' ' . $jt;
    // Seulement ce qui pose problème, puis une phrase pour dire que le reste est en place.
    $texte = 'Bonjour' . ($pre ? ' ' . $pre : '') . ",\n\n" . 'Pour information, ' . $jl . ' ' . $jt . " :\n\n" . implode("\n", array_map(fn($l) => '• ' . $l, $incL))
      . "\n\n" . ($ok ? 'Les autres animatrices sont en poste' . ($avenir ? ' ou arrivent plus tard selon le planning' : '') . '.' : '') . "\n\nCordialement.\n\n" . g_reg()['signature'];
    $mode = $c['presence_mode'] ?: 'incident';
    $out[] = ['client_id' => $cid, 'client' => $c['raison'] . ($c['contact'] ? ' · ' . $c['contact'] : ''), 'mode' => $mode, 'auto' => (int)$c['presence_auto'],
      'a' => trim((string)$c['presence_emails']) ?: (string)$c['emails'], 'incidents' => $inc, 'a_envoyer' => $mode === 'incident' && $inc > 0, // seulement en cas de problème : sinon, pas d'e-mail
      'sujet' => $e['sujet'] ?? $sujet, 'texte' => $e && $e['statut'] === 'envoye' ? $e['texte'] : $texte, 'envoi' => $e ? ['statut' => $e['statut'], 'le' => $e['envoye_le'], 'par' => $e['par'], 'a' => $e['a']] : null, 'n' => count($X)];
  }
  usort($out, fn($a, $b) => [$b['incidents'], $a['client']] <=> [$a['incidents'], $b['client']]);
  return $out;
}
function pr_envoyer(int $cid, string $date, string $a, string $sujet, string $texte, string $par): bool {
  pr_schema(); require_once __DIR__ . '/report_lib.php';
  $to = implode(', ', emails_list($a)); if ($to === '') throw new RuntimeException('Indiquez l\'adresse de la marque (fiche client).');
  $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1A1512;line-height:1.5">' . nl2br(h($texte)) . '</div>';
  $cc = implode(', ', emails_list((string)g_reg()['notif_emails']));
  $ok = mail_send($to, $sujet, $html, [], $cc);
  if ($ok) db()->prepare("REPLACE INTO presence_envois (client_id, jour, sujet, texte, a, statut, envoye_le, par) VALUES (?,?,?,?,?,'envoye',?,?)")->execute([$cid, $date, $sujet, $texte, $to, now(), $par]);
  return $ok;
}
/** Envoi automatique (clients réglés « automatique ») : une heure après le début de la première animation du jour, une fois par jour. */
function pr_auto(): void {
  try { pr_schema(); $d = date('Y-m-d');
    foreach (pr_jour($d) as $m) { if (!$m['auto'] || !$m['a_envoyer'] || $m['envoi']) continue;
      $st = db()->prepare("SELECT MIN(m.debut) d, MIN(m.zone) z FROM missions m JOIN g_docs g ON g.id = m.devis_id WHERE g.client_id = ? AND m.date_mission = ? AND m.statut <> 'annulee'"); $st->execute([$m['client_id'], $d]); $x = $st->fetch();
      $n = pp_now((string)$x['z']); if ((int)$n->format('H') * 60 + (int)$n->format('i') < pp_min((string)$x['d']) + 60) continue;
      pr_envoyer($m['client_id'], $d, $m['a'], $m['sujet'], $m['texte'], 'envoi automatique'); }
  } catch (Throwable $e) { error_log('Présence auto : ' . $e->getMessage()); }
}
/** Récapitulatif de présence d'une opération (fin d'opération). */
function pr_recap(int $devis): array {
  pr_schema(); $d = g_doc($devis); if (!$d) throw new RuntimeException('Opération introuvable.');
  $st = db()->prepare("SELECT DISTINCT date_mission FROM missions WHERE devis_id = ? AND statut <> 'annulee' AND date_mission <= CURDATE() ORDER BY date_mission"); $st->execute([$devis]);
  $tot = 0; $ok = 0; $ret = 0; $retMin = 0; $abs = 0; $rem = 0; $inc = [];
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $j) foreach (pp_jour($j) as $x) { if ((int)$x['devis_id'] !== $devis) continue;
    if ($x['remplace_par']) { $rem++; $inc[] = g_date($j) . ' · ' . ($x['magasin'] ?: '') . ' : ' . pr_prenom_nom($x['nom']) . ' absente, remplacée par ' . pr_prenom_nom($x['remplace_par']); continue; }
    $tot++; if (in_array($x['code'], ['en_poste', 'present'], true)) $ok++;
    if ($x['code'] === 'retard') { $ret++; $retMin += (int)$x['ecart']; $inc[] = g_date($j) . ' · ' . ($x['magasin'] ?: '') . ' : ' . pr_prenom_nom($x['nom']) . ', ' . $x['ecart'] . ' min de retard'; }
    if ($x['code'] === 'absent') { $abs++; $inc[] = g_date($j) . ' · ' . ($x['magasin'] ?: '') . ' : ' . pr_prenom_nom($x['nom']) . ' absente, journée non facturée'; } }
  $c = $d['client']; $pre = trim((string)($c['contact'] ?? '')) !== '' ? mb_convert_case(mb_strtolower(explode(' ', trim($c['contact']))[0]), MB_CASE_TITLE) : '';
  $pct = $tot ? round(($ok + $ret) / $tot * 100) : 0; $ponct = ($ok + $ret) ? round($ok / ($ok + $ret) * 100) : 0;
  $texte = 'Bonjour' . ($pre ? ' ' . $pre : '') . ",\n\nVoici le bilan de présence de l'opération « " . ($d['objet'] ?: $d['numero']) . " » :\n\n"
    . '• ' . $tot . ' journée' . ($tot > 1 ? 's' : '') . ' prévue' . ($tot > 1 ? 's' : '') . ', ' . ($ok + $ret + $rem) . ' assurée' . (($ok + $ret + $rem) > 1 ? 's' : '') . ($rem ? ' (dont ' . $rem . ' par remplacement)' : '') . ".\n"
    . '• Ponctualité : ' . $ponct . " % des prises de poste à l'heure" . ($ret ? ' (' . $ret . ' retard' . ($ret > 1 ? 's' : '') . ', ' . round($retMin / max(1, $ret)) . ' min en moyenne)' : '') . ".\n"
    . ($abs ? '• ' . $abs . ' absence' . ($abs > 1 ? 's' : '') . ' non remplacée' . ($abs > 1 ? 's' : '') . ", non facturée" . ($abs > 1 ? 's' : '') . ".\n" : '')
    . ($inc ? "\nDétail :\n" . implode("\n", array_map(fn($l) => '• ' . $l, $inc)) . "\n" : "\nAucun incident sur l'opération.\n")
    . "\nCordialement.\n\n" . g_reg()['signature'];
  $cl = db()->prepare("SELECT presence_emails, emails FROM g_clients WHERE id = ?"); $cl->execute([(int)$d['client_id']]); $e = $cl->fetch() ?: [];
  return ['client_id' => (int)$d['client_id'], 'a' => trim((string)($e['presence_emails'] ?? '')) ?: (string)($e['emails'] ?? ''), 'sujet' => 'Bilan de présence · ' . ($d['objet'] ?: $d['numero']), 'texte' => $texte, 'taux' => $pct];
}
