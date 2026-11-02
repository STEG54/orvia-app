<?php
require __DIR__ . '/lib.php';
header('Cache-Control: no-store');
$t = (string)($_GET['t'] ?? ''); $etat = 'invalide'; $r = null;
if (preg_match('/^[a-f0-9]{48}$/', $t) && !trop(30, 3600)) {
  $st = db()->prepare("SELECT * FROM inscriptions WHERE jeton_hash = ?"); $st->execute([hash('sha256', $t)]); $r = $st->fetch();
  if ($r && $r['statut'] === 'a_confirmer' && strtotime($r['created_at']) < time() - 172800) $etat = 'expire';
  elseif ($r && $r['statut'] === 'a_confirmer') {
    db()->prepare("UPDATE inscriptions SET statut = 'confirme', confirme_at = ? WHERE id = ? AND statut = 'a_confirmer'")->execute([date('Y-m-d H:i:s'), $r['id']]);
    if (defined('INS_MAIL_ALERTE') && INS_MAIL_ALERTE !== '') envoyer(INS_MAIL_ALERTE, 'Nouvelle inscription : ' . $r['conciergerie'], $r['conciergerie'] . ' (' . $r['ville'] . "), " . $r['logements'] . " logement(s)\n" . $r['nom'] . ', ' . $r['email'] . ', ' . $r['telephone']);
    $etat = 'confirme';
  } elseif ($r) $etat = $r['statut'];
}
$msg = [
  'confirme' => ['Adresse confirmée.', "Votre espace est en cours de création. Vous recevrez dans quelques minutes un e-mail avec son adresse ; vous vous y connecterez avec votre e-mail et le mot de passe choisi."],
  'en_cours' => ['Création en cours.', "Votre espace est en cours de création. L'e-mail avec son adresse arrive dans quelques minutes."],
  'pret' => ['Votre espace est prêt.', "Il vous attend à l'adresse reçue par e-mail. Connectez-vous avec votre e-mail et votre mot de passe."],
  'erreur' => ['Un incident est survenu.', "La création de votre espace n'a pas abouti. Nous avons été prévenus et revenons vers vous rapidement."],
  'expire' => ['Ce lien a expiré.', "Il était valable 48 heures. Recommencez votre inscription : cela ne prend qu'une minute."],
  'invalide' => ['Lien non valable.', "Vérifiez que vous avez ouvert le lien complet reçu par e-mail, ou recommencez votre inscription."],
][$etat] ?? ['Lien non valable.', ''];
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
<title><?= h($msg[0]) ?> · Keylia</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Marcellus&family=Jost:wght@400;500&display=swap">
<style>body{margin:0;background:#F6F4EF;color:#1C1F1D;font:400 17px/1.6 "Jost",system-ui,sans-serif}main{max-width:620px;margin:0 auto;padding:72px 20px}
h1{font:400 2.2rem/1.2 "Marcellus",Georgia,serif;color:#43515C;margin:0 0 14px}p{color:#545955}a{color:#43515C}</style></head>
<body><main><h1><?= h($msg[0]) ?></h1><p><?= h($msg[1]) ?></p><?php if (in_array($etat, ['expire', 'invalide'], true)): ?><p><a href="./">Recommencer l'inscription</a></p><?php endif; ?></main></body></html>
