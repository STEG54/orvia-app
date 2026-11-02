<?php
// Tâche planifiée OVH (facultative) : toutes les 15 min, envoie les e-mails de présence automatiques, les rappels d'échéance des documents, et fait la sauvegarde du jour.
// Dans l'espace OVH : Hébergement › Tâches planifiées (Cron) › Ajouter, fichier « amaya/cron.php » (le dossier de l'appli), toutes les 15 minutes.
// En ligne de commande uniquement : rien n'est exécuté si on ouvre ce fichier dans un navigateur.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/presence_lib.php'; require_once __DIR__ . '/extras_lib.php';
if (demo_actif()) { require_once __DIR__ . '/demo_lib.php'; demo_nettoyer(20); }
require_once __DIR__ . '/sauvegarde_lib.php';
x_rappels(); pr_auto(); sv_auto(); @file_put_contents(sv_dir() . '/.cron', (string)time());
// Copie hors du serveur (si réglée dans config.php) : envoi incrémental, vérification de restauration chaque semaine.
require_once __DIR__ . '/externe_lib.php'; sx_auto(240);
// Changement de clé en cours : rechiffrement par lots.
require_once __DIR__ . '/cle_lib.php'; cle_auto(120);
// ORVIA : calendriers, mise sous scellé et effacement des fiches voyageurs, envoi des codes d'accès (au plus toutes les 30 min).
require_once __DIR__ . '/conciergerie_lib.php';
try { if (time() - (int)reglage('cz_cron_le', '0') >= 1800) { reglage_set('cz_cron_le', (string)time()); cz_cron(); } } catch (Throwable $e) { error_log('ORVIA tâche planifiée : ' . $e->getMessage()); }
echo date('c'), " ok\n";
