<?php
// Faux serveur SMTP pour les tests : accepte une connexion à la fois, note le dialogue et le message reçus.
[$_, $port, $journal] = $argv; $srv = stream_socket_server("tcp://127.0.0.1:$port", $en, $es); if (!$srv) exit(1);
while ($c = @stream_socket_accept($srv, 60)) {
  $w = function ($t) use ($c) { fwrite($c, $t . "\r\n"); }; $w('220 test ESMTP'); $data = false; $msg = '';
  while (($l = fgets($c)) !== false) {
    if ($data) { if (rtrim($l, "\r\n") === '.') { $data = false; file_put_contents($journal, "MESSAGE\n" . $msg, FILE_APPEND); $w('250 OK reçu'); } else $msg .= $l; continue; }
    file_put_contents($journal, $l, FILE_APPEND); $cmd = strtoupper(substr(trim($l), 0, 4));
    if ($cmd === 'EHLO') { fwrite($c, "250-test\r\n250 AUTH LOGIN\r\n"); }
    elseif ($cmd === 'AUTH') $w('334 VXNlcm5hbWU6');
    elseif (preg_match('/^[A-Za-z0-9+\/=]+$/', trim($l)) && !in_array($cmd, ['DATA', 'QUIT'], true)) $w(base64_decode(trim($l)) === 'mdp-test' ? '235 OK' : '334 UGFzc3dvcmQ6');
    elseif ($cmd === 'MAIL' || $cmd === 'RCPT') $w('250 OK');
    elseif ($cmd === 'DATA') { $data = true; $w('354 go'); }
    elseif ($cmd === 'QUIT') { $w('221 bye'); break; }
    else $w('250 OK');
  }
  fclose($c);
}
