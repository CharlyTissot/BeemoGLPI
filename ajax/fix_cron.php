<?php
/**
 * BeemoConnect — Enregistrement forcé de la tâche cron GLPI
 * Formulaire POST classique (pas AJAX) pour éviter les problèmes CSRF
 */
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

CronTask::register(
    'beemoconnect',
    'BeemoConnectProcess',
    DAY_TIMESTAMP,
    [
        'comment' => 'Traitement des mails BEEMO → Tickets GLPI',
        'mode'    => CronTask::MODE_EXTERNAL,
    ]
);

Session::addMessageAfterRedirect('Tâche BeemoConnectProcess enregistrée dans les actions automatiques.', true, INFO);
Html::redirect(Plugin::getWebDir('beemoconnect') . '/front/config.php');
