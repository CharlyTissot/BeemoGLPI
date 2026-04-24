<?php
include('../../../inc/includes.php');
Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}
header('Content-Type: application/json');

try {
    $p      = new PluginBeemoconnectProcessor();
    $result = $p->run(null);
    $stats  = $p->getLastStats();
    echo json_encode([
        'success' => ($result >= 0),
        'message' => $result >= 0
            ? "✅ Terminé — <strong>{$stats['created']}</strong> créé(s), <strong>{$stats['updated']}</strong> relance(s), <strong>{$stats['skipped']}</strong> ignoré(s)."
            : "❌ Erreur lors du traitement.",
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => '❌ ' . $e->getMessage()]);
}
