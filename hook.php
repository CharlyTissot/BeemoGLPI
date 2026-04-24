<?php
/**
 * BeemoConnect — hook.php
 * Installation / désinstallation
 */

function plugin_beemoconnect_install(): bool {
    global $DB;

    if (!$DB->tableExists('glpi_plugin_beemoconnect_configs')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_beemoconnect_configs` (
                `id`    INT(11)      NOT NULL AUTO_INCREMENT,
                `name`  VARCHAR(255) NOT NULL DEFAULT '',
                `value` LONGTEXT              DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $defaults = [
            'mail_type'           => 'imap',
            'imap_server'         => '',
            'imap_port'           => '993',
            'imap_user'           => '',
            'imap_password'       => '',
            'imap_ssl'            => '1',
            'graph_tenant_id'     => '',
            'graph_client_id'     => '',
            'graph_client_secret' => '',
            'graph_sender'        => '',
            'source_folder'       => 'INBOX',
            'done_folder'         => 'Traité',
            'subject_filter'      => 'Parc BEEMO',
            'today_only'          => '1',
            'tag_prefixes'        => '[B2B]',
            'scheduled_hour'      => '8',
            'scheduled_minute'    => '0',
            'glpi_group_id'       => '9',
            'request_type_id'     => '10',
            'urgency_default'     => '3',
            'skip_beehive'        => '1',
            'beehive_ignore'      => '',
            'sections_config'     => '{}',
            'last_run'            => '',
            'last_run_result'     => '',
        ];

        foreach ($defaults as $name => $value) {
            $DB->insert('glpi_plugin_beemoconnect_configs', [
                'name'  => $name,
                'value' => $value,
            ]);
        }
    } else {
        // Ajouter les nouvelles colonnes si absentes (mise à jour)
        foreach (['beehive_ignore' => '', 'scheduled_hour' => '8', 'scheduled_minute' => '0'] as $name => $value) {
            $iter = $DB->request([
                'FROM'  => 'glpi_plugin_beemoconnect_configs',
                'WHERE' => ['name' => $name],
                'LIMIT' => 1,
            ]);
            if (!$iter->current()) {
                $DB->insert('glpi_plugin_beemoconnect_configs', [
                    'name'  => $name,
                    'value' => $value,
                ]);
            }
        }
    }

    if (!$DB->tableExists('glpi_plugin_beemoconnect_states')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_beemoconnect_states` (
                `id`            INT(11)      NOT NULL AUTO_INCREMENT,
                `state_key`     VARCHAR(500) NOT NULL DEFAULT '',
                `tickets_id`    INT(11)      NOT NULL DEFAULT 0,
                `ticket_status` INT(2)       NOT NULL DEFAULT 1,
                `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `state_key` (`state_key`(255))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    CronTask::register(
        'beemoconnect',
        'BeemoConnectProcess',
        DAY_TIMESTAMP,
        [
            'comment' => 'Traitement des mails BEEMO → Tickets GLPI',
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );

    return true;
}

function plugin_beemoconnect_uninstall(): bool {
    global $DB;
    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_beemoconnect_configs`");
    $DB->doQuery("DROP TABLE IF EXISTS `glpi_plugin_beemoconnect_states`");
    CronTask::unregister('beemoconnect');
    return true;
}

