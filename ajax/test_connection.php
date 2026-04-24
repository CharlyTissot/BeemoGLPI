<?php
include('../../../inc/includes.php');
Session::checkLoginUser();
if (!Session::haveRight('config', UPDATE)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}
header('Content-Type: application/json');

$saved = PluginBeemoconnectConfig::getAll();
$cfg   = [
    'mail_type'           => $_POST['mail_type']           ?? $saved['mail_type']           ?? 'imap',
    'imap_server'         => $_POST['imap_server']         ?? $saved['imap_server']         ?? '',
    'imap_port'           => $_POST['imap_port']           ?? $saved['imap_port']           ?? '993',
    'imap_user'           => $_POST['imap_user']           ?? $saved['imap_user']           ?? '',
    'imap_ssl'            => $_POST['imap_ssl']            ?? $saved['imap_ssl']            ?? '1',
    'graph_tenant_id'     => $_POST['graph_tenant_id']     ?? $saved['graph_tenant_id']     ?? '',
    'graph_client_id'     => $_POST['graph_client_id']     ?? $saved['graph_client_id']     ?? '',
    'graph_sender'        => $_POST['graph_sender']        ?? $saved['graph_sender']        ?? '',
];
$cfg['imap_password']       = !empty($_POST['imap_password'])       ? $_POST['imap_password']       : ($saved['imap_password']       ?? '');
$cfg['graph_client_secret'] = !empty($_POST['graph_client_secret']) ? $_POST['graph_client_secret'] : ($saved['graph_client_secret'] ?? '');

$out = ['success' => false, 'message' => ''];

try {
    if ($cfg['mail_type'] === 'imap') {
        if (!function_exists('imap_open')) throw new Exception("Extension PHP imap non disponible.");
        $ssl   = !empty($cfg['imap_ssl']);
        $flags = $ssl ? '/ssl/novalidate-cert' : '/notls';
        $mbox  = @imap_open(
            '{' . $cfg['imap_server'] . ':' . ($cfg['imap_port']??993) . '/imap' . $flags . '}INBOX',
            $cfg['imap_user'], $cfg['imap_password'], 0, 1
        );
        if (!$mbox) throw new Exception(implode(', ', imap_errors() ?: ['Erreur IMAP']));
        $check = imap_check($mbox);
        imap_close($mbox);
        $out['success'] = true;
        $out['message'] = '✅ Connexion IMAP réussie — ' . ($check->Nmsgs ?? '?') . ' message(s) dans INBOX.';
    } else {
        $url  = "https://login.microsoftonline.com/{$cfg['graph_tenant_id']}/oauth2/v2.0/token";
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $cfg['graph_client_id'],
            'client_secret' => $cfg['graph_client_secret'],
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);
        $ctx  = stream_context_create(['http' => ['method'=>'POST',
            'header'=>"Content-Type: application/x-www-form-urlencoded\r\n",
            'content'=>$body,'timeout'=>10]]);
        $resp = json_decode(@file_get_contents($url, false, $ctx), true) ?? [];
        if (empty($resp['access_token'])) throw new Exception($resp['error_description'] ?? 'Token refusé');

        $token  = $resp['access_token'];
        $sender = $cfg['graph_sender'];
        // Lister les dossiers racine ET les enfants de inbox
        $headers2 = "Authorization: Bearer {$token}\r\n";
        $ctx2 = stream_context_create(['http' => ['method'=>'GET','header'=>$headers2,'timeout'=>10]]);
        $fr1  = json_decode(@file_get_contents(
            "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders?\$top=50", false, $ctx2), true) ?? [];
        $fr2  = json_decode(@file_get_contents(
            "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders/inbox/childFolders?\$top=50", false, $ctx2), true) ?? [];
        $names = array_unique(array_merge(
            array_column($fr1['value'] ?? [], 'displayName'),
            array_column($fr2['value'] ?? [], 'displayName')
        ));
        $out['success'] = true;
        $out['message'] = '✅ Connexion Graph API réussie — Dossiers : ' . implode(', ', $names ?: ['(aucun)']);
    }
} catch (Exception $e) {
    $out['message'] = '❌ ' . $e->getMessage();
}
echo json_encode($out);
