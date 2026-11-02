<?php
// Keylia : inscription en ligne. Le formulaire enregistre la demande ; l'e-mail confirme l'adresse ;
// la création de l'espace est faite ensuite par outils/traiter-inscriptions.php (tâche planifiée du serveur).
// Cette page ne crée rien elle-même sur le serveur et ne voit jamais de mot de passe en clair après le formulaire.
require __DIR__ . '/config.php';
header('X-Frame-Options: DENY'); header('Referrer-Policy: no-referrer'); header('X-Content-Type-Options: nosniff');
function db(): PDO { static $p; if (!$p) { $p = new PDO('mysql:host=' . INS_DB_HOST . ';dbname=' . INS_DB_NAME . ';charset=utf8mb4', INS_DB_USER, INS_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  foreach (array_filter(array_map('trim', explode(';', (string)file_get_contents(__DIR__ . '/schema.sql')))) as $q) $p->exec($q); } return $p; }
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
/** Au plus $n essais par adresse IP sur $s secondes. */
function trop(int $n, int $s): bool { db()->prepare("DELETE FROM inscriptions_essais WHERE t < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
  $st = db()->prepare("SELECT COUNT(*) FROM inscriptions_essais WHERE ip = ? AND t > ?"); $st->execute([ip(), date('Y-m-d H:i:s', time() - $s)]);
  if ((int)$st->fetchColumn() >= $n) return true; db()->prepare("INSERT INTO inscriptions_essais (ip, t) VALUES (?, ?)")->execute([ip(), date('Y-m-d H:i:s')]); return false; }
function envoyer(string $a, string $sujet, string $texte): bool {
  if (defined('INS_MAIL_TEST')) { file_put_contents(INS_MAIL_TEST, "À: $a\nSujet: $sujet\n\n$texte\n----\n", FILE_APPEND); return true; }
  $en = 'From: Keylia <' . INS_MAIL_FROM . ">\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
  return mail($a, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $texte, $en);
}
/** Problème dans le mot de passe choisi, ou null. Mêmes exigences que dans l'appli (lib.php, mdp_probleme). */
function mdp_probleme(string $pw, string $email): ?string {
  if (mb_strlen($pw) < 12) return 'Le mot de passe doit faire au moins 12 caractères.';
  if (count(array_unique(mb_str_split(mb_strtolower($pw)))) < 5) return 'Mot de passe trop répétitif : variez les caractères.';
  $b = mb_strtolower(preg_replace('/[\s_.\-!?*@#$%&0-9]+/u', '', $pw));
  foreach (['motdepasse', 'password', 'azerty', 'qwerty', 'abcdef', 'soleil', 'bonjour', 'keylia', 'admin', 'secret', 'marrakech', 'casablanca', 'maroc'] as $c)
    if ($b === $c || ($b !== '' && mb_strlen($b) <= mb_strlen($c) + 2 && str_contains($b, $c))) return 'Mot de passe trop courant : choisissez une phrase ou une suite de mots à vous.';
  $u = mb_strtolower(strtok($email, '@') ?: ''); if (mb_strlen($u) >= 4 && str_contains(mb_strtolower($pw), $u)) return 'Le mot de passe ne doit pas reprendre votre adresse e-mail.';
  return null;
}
