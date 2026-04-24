<?php
/**
 * BeemoConnect — setup.php
 * Règles obligatoires GLPI 10/11+ :
 *  - plugin_init_{nom}() et NON plugin_{nom}_init()
 *  - PAS de Plugin::registerClass() (déprécié, cassant)
 *  - PAS de CronTask::register() ici (uniquement dans hook.php)
 *  - csrf_compliant obligatoire
 */

define('PLUGIN_BEEMOCONNECT_VERSION',  '1.0.0');
define('PLUGIN_BEEMOCONNECT_MIN_GLPI', '10.0.0');
define('PLUGIN_BEEMOCONNECT_MAX_GLPI', '12.99.99');

function plugin_version_beemoconnect(): array {
    return [
        'name'         => 'BeemoConnect',
        'version'      => PLUGIN_BEEMOCONNECT_VERSION,
        'author'       => 'CharlyTissot',
        'license'      => 'GPL v3',
        'homepage'     => 'https://charlytissot.me',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_BEEMOCONNECT_MIN_GLPI,
                'max' => PLUGIN_BEEMOCONNECT_MAX_GLPI,
            ],
            'php'  => ['min' => '7.4'],
        ],
    ];
}

function plugin_beemoconnect_check_prerequisites(): bool {
    if (version_compare(GLPI_VERSION, PLUGIN_BEEMOCONNECT_MIN_GLPI, 'lt')) {
        echo 'BeemoConnect nécessite GLPI >= ' . PLUGIN_BEEMOCONNECT_MIN_GLPI;
        return false;
    }
    return true;
}

function plugin_beemoconnect_check_config(bool $verbose = false): bool {
    return true;
}

function plugin_init_beemoconnect(): void {
    global $PLUGIN_HOOKS;
    $PLUGIN_HOOKS['csrf_compliant']['beemoconnect'] = true;
    $PLUGIN_HOOKS['cron']['beemoconnect'] = true;
    if (Session::haveRight('config', UPDATE)) {
        $PLUGIN_HOOKS['config_page']['beemoconnect'] = 'front/config.php';
    }

    // S'assurer que la tâche cron est toujours enregistrée dans GLPI
    if (class_exists('CronTask')) {
        CronTask::register(
            'beemoconnect',
            'BeemoConnectProcess',
            DAY_TIMESTAMP,
            [
                'comment' => 'Traitement des mails BEEMO → Tickets GLPI',
                'mode'    => CronTask::MODE_INTERNAL,
            ]
        );
    }
}
