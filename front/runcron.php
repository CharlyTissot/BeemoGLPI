<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Direct access not allowed.' . PHP_EOL);
}

$glpiRoot   = realpath(dirname(__FILE__) . '/../../..');
$pluginRoot = realpath(dirname(__FILE__) . '/..');
chdir($glpiRoot);

require_once($glpiRoot . '/vendor/autoload.php');
use Glpi\Kernel\Kernel;
$kernel = new Kernel('production', false);
$kernel->boot();
global $DB;

foreach (['config', 'processor'] as $cls) {
    $file = $pluginRoot . '/inc/' . $cls . '.class.php';
    if (file_exists($file)) {
        include_once($file);
    } else {
        echo date('Y-m-d H:i:s') . ' [BeemoConnect] ERREUR : fichier manquant ' . $file . PHP_EOL;
        exit(1);
    }
}

// ── Vérification heure et minutes planifiées ──────────────────────────────────
$scheduledHour   = (int)PluginBeemoconnectConfig::getValue('scheduled_hour',   '8');
$scheduledMinute = (int)PluginBeemoconnectConfig::getValue('scheduled_minute',  '0');
$currentHour     = (int)date('G');
$currentMinute   = (int)date('i');

if ($currentHour !== $scheduledHour || $currentMinute !== $scheduledMinute) {
    // Pas l'heure — sortie silencieuse
    exit(0);
}

// ── Verrou anti-double exécution ──────────────────────────────────────────────
$lockFile = '/tmp/beemoconnect.lock';
if (file_exists($lockFile) && (time() - filemtime($lockFile)) < 3600) {
    echo date('Y-m-d H:i:s') . ' [BeemoConnect] Déjà en cours, abandon.' . PHP_EOL;
    exit(0);
}
file_put_contents($lockFile, getmypid());

echo date('Y-m-d H:i:s') . ' [BeemoConnect] Démarrage traitement...' . PHP_EOL;

try {
    $processor = new PluginBeemoconnectProcessor();
    $result    = $processor->run(null);
    $stats     = $processor->getLastStats();

    if ($result >= 0) {
        echo date('Y-m-d H:i:s') . sprintf(
            ' [BeemoConnect] OK — Créés: %d | Relances: %d | Ignorés: %d' . PHP_EOL,
            $stats['created'],
            $stats['updated'],
            $stats['skipped']
        );
    } else {
        echo date('Y-m-d H:i:s') . ' [BeemoConnect] Erreur lors du traitement.' . PHP_EOL;
        exit(1);
    }
} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . ' [BeemoConnect] Exception : ' . $e->getMessage() . PHP_EOL;
    exit(1);
} finally {
    @unlink($lockFile);
}

exit(0);
