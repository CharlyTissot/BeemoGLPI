<?php
/**
 * PluginBeemoconnectBeemoconnect — Classe principale requise par GLPI 10+
 * GLPI cherche {plugin}::{methodname} pour les tâches cron,
 * soit beemoconnect::cronBeemoConnectProcess
 * Ce qui correspond à PluginBeemoconnectBeemoconnect::cronBeemoConnectProcess
 */
class PluginBeemoconnectBeemoconnect extends CommonGLPI {

    public static function getTypeName($nb = 0): string {
        return 'BeemoConnect';
    }

    /**
     * Méthode appelée par le cron GLPI.
     * Nom cherché : beemoconnect::cronBeemoConnectProcess
     */
    public static function cronBeemoConnectProcess(CronTask $task): int {
        $processor = new PluginBeemoconnectProcessor();
        $result    = $processor->run($task);
        $stats     = $processor->getLastStats();
        if ($result > 0) {
            $task->addVolume($stats['created'] + $stats['updated']);
            $task->log(sprintf(
                '%d créé(s), %d relance(s), %d ignoré(s)',
                $stats['created'],
                $stats['updated'],
                $stats['skipped']
            ));
        }
        return $result;
    }

    /**
     * Description de la tâche cron (affichée dans Configuration → Actions automatiques)
     */
    public static function cronInfo(string $name): array {
        return match ($name) {
            'BeemoConnectProcess' => [
                'description' => 'Traitement des mails BEEMO → Tickets GLPI',
            ],
            default => [],
        };
    }
}
