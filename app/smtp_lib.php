<?php
// Envoi des e-mails par un serveur SMTP authentifié (boîte OVH, Brevo, Mailjet…), facultatif : meilleure arrivée en boîte de réception.
// Réglage dans config.php : MAIL_SMTP_HOST (ex. ssl0.ovh.net), MAIL_SMTP_PORT (465 en SSL, ou 587 en STARTTLS), MAIL_SMTP_USER, MAIL_SMTP_PASS.
// (MAIL_SMTP_TLS à false : sans chiffrement, uniquement pour un serveur de test local.)
// Sans ce réglage, l'appli utilise l'envoi intégré de l'hébergement (fonction mail()).

/** Envoie un message déjà composé (en-têtes + corps) à une liste de destinataires. Lève une exception claire en cas de refus. */
function smtp_envoyer(array $dest, string $entetes, string $corps): void {
  $port = defined('MAIL_SMTP_PORT') ? (int)MAIL_SMTP_PORT : 465; $hote = (string)MAIL_SMTP_HOST;
  $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
  $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $hote . ':' . $port, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
  if (!$s) throw new RuntimeException("connexion à $hote:$port impossible ($es)");
  stream_set_timeout($s, 30);
  $lire = function () use ($s): array { $t = ''; while (($l = fgets($s, 1024)) !== false) { $t .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return [(int)substr($t, 0, 3), trim($t)]; };
  $cmd = function (string $c, array $ok, string $quoi) use ($s, $lire): string { if ($c !== '') fwrite($s, $c . "\r\n"); [$code, $t] = $lire(); if (!in_array($code, $ok, true)) throw new RuntimeException("$quoi refusé : $t"); return $t; };
  try {
    $cmd('', [220], 'accueil du serveur'); $nom = gethostname() ?: 'amaya';
    $cmd("EHLO $nom", [250], 'EHLO');
    if ($port !== 465 && (!defined('MAIL_SMTP_TLS') || MAIL_SMTP_TLS)) { $cmd('STARTTLS', [220], 'STARTTLS'); if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('chiffrement TLS impossible'); $cmd("EHLO $nom", [250], 'EHLO'); }
    if (defined('MAIL_SMTP_USER') && MAIL_SMTP_USER !== '') { $cmd('AUTH LOGIN', [334], 'authentification'); $cmd(base64_encode((string)MAIL_SMTP_USER), [334], 'identifiant'); $cmd(base64_encode((string)(defined('MAIL_SMTP_PASS') ? MAIL_SMTP_PASS : '')), [235], 'mot de passe SMTP'); }
    $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250], 'expéditeur');
    foreach (array_unique(array_map(fn($x) => trim(preg_replace('/.*<([^>]+)>.*/', '$1', $x)), $dest)) as $d) $cmd("RCPT TO:<$d>", [250, 251], "destinataire $d");
    $cmd('DATA', [354], 'DATA');
    $msg = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $entetes . "\r\n\r\n" . $corps)); // points en début de ligne doublés (RFC 5321)
    fwrite($s, str_replace("\n", "\r\n", $msg) . "\r\n.\r\n"); [$code, $t] = $lire(); if ($code !== 250) throw new RuntimeException("message refusé : $t");
    $cmd('QUIT', [221], 'QUIT');
  } finally { fclose($s); }
}
/** Dernier résultat d'envoi SMTP (affiché dans Maintenance). */
function smtp_noter(string $erreur): void { try { db()->prepare("REPLACE INTO reglages (cle, valeur) VALUES ('smtp_dernier', ?)")->execute([json_encode(['le' => date('Y-m-d H:i'), 'erreur' => $erreur], JSON_UNESCAPED_UNICODE)]); } catch (Throwable $e) {} }
