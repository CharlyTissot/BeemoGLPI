<?php
/**
 * PluginBeemoconnectProcessor — Cœur du traitement BEEMO → GLPI
 */
class PluginBeemoconnectProcessor {

    private array  $cfg         = [];
    private array  $entityCache = [];
    private array  $userCache   = [];
    private array  $prefixes    = [];
    private array  $lastStats   = ['created' => 0, 'updated' => 0, 'skipped' => 0];

    private const CLOSED_STATUSES = [5, 6];
    private const STATUS_NEW      = 1;

    public function __construct() {
        $this->cfg      = PluginBeemoconnectConfig::getAll();
        $this->prefixes = array_filter(array_map('trim',
            explode("\n", $this->cfg['tag_prefixes'] ?? '[B2B]')
        ));
    }

    public function getLastStats(): array { return $this->lastStats; }

    // ─── Point d'entrée ───────────────────────────────────────────────────────
    public function run(?CronTask $task): int {
        // Vérification heure planifiée (ignorée si lancement manuel)
        if ($task !== null) {
            $scheduledHour = (int)PluginBeemoconnectConfig::getValue('scheduled_hour', '8');
            $currentHour   = (int)date('G');
            if ($currentHour !== $scheduledHour) {
                return 0;
            }
        }
        try {
            $mails = $this->fetchMails();
            if (empty($mails)) return 0;
            $sections = [];
            foreach ($mails as $html) {
                $sections = array_merge($sections, $this->parseEmail($html));
            }
            if (empty($sections)) return 0;
            $this->processEntries($sections);
            return 1;
        } catch (Throwable $e) {
            Toolbox::logError('BeemoConnect: ' . $e->getMessage());
            if ($task) $task->log('Erreur: ' . $e->getMessage());
            return -1;
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  RÉCUPÉRATION MAIL
    // ═══════════════════════════════════════════════════════════════════════════
    private function fetchMails(): array {
        return ($this->cfg['mail_type'] ?? 'imap') === 'graph'
            ? $this->fetchGraph()
            : $this->fetchImap();
    }

    // ── IMAP ──────────────────────────────────────────────────────────────────
    private function fetchImap(): array {
        if (!function_exists('imap_open')) {
            throw new RuntimeException('Extension PHP imap non disponible.');
        }
        $ssl   = !empty($this->cfg['imap_ssl']);
        $flags = $ssl ? '/ssl/novalidate-cert' : '/notls';
        $src   = $this->cfg['source_folder'] ?? 'INBOX';
        $done  = $this->cfg['done_folder']   ?? '';
        $subj  = $this->cfg['subject_filter'] ?? 'Parc BEEMO';
        $today = !empty($this->cfg['today_only']);

        $mbox = @imap_open(
            '{' . $this->cfg['imap_server'] . ':' . ($this->cfg['imap_port'] ?? 993) . '/imap' . $flags . '}' . $src,
            $this->cfg['imap_user'],
            $this->cfg['imap_password']
        );
        if (!$mbox) {
            throw new RuntimeException('IMAP: ' . implode(', ', imap_errors() ?: ['Erreur inconnue']));
        }

        $crit = 'SUBJECT "' . $subj . '"';
        if ($today) $crit .= ' SINCE "' . date('d-M-Y') . '"';
        $ids = imap_search($mbox, $crit) ?: [];

        $bodies = [];
        foreach ($ids as $id) {
            $html = $this->imapExtractHtml($mbox, $id, imap_fetchstructure($mbox, $id));
            if ($html) {
                $bodies[] = $html;
                imap_setflag_full($mbox, (string)$id, '\\Seen');
                if ($done) @imap_mail_move($mbox, (string)$id, $done);
            }
        }
        imap_expunge($mbox);
        imap_close($mbox);
        return $bodies;
    }

    private function imapExtractHtml($mbox, int $id, object $struct, string $pfx = ''): string {
        if (empty($struct->parts)) {
            if (strtolower($struct->subtype ?? '') === 'html') {
                $raw = imap_fetchbody($mbox, $id, $pfx ?: '1');
                return match ((int)$struct->encoding) {
                    3 => base64_decode($raw),
                    4 => quoted_printable_decode($raw),
                    default => $raw,
                };
            }
            return '';
        }
        foreach ($struct->parts as $i => $part) {
            $html = $this->imapExtractHtml($mbox, $id, $part, ($pfx ? $pfx . '.' : '') . ($i + 1));
            if ($html) return $html;
        }
        return '';
    }

    // ── Microsoft Graph API ───────────────────────────────────────────────────
    private function fetchGraph(): array {
        $token  = $this->graphToken();
        $sender = $this->cfg['graph_sender'];
        $src    = $this->cfg['source_folder'];
        $done   = $this->cfg['done_folder'];
        $subj   = $this->cfg['subject_filter'] ?? 'Parc BEEMO';
        $today  = !empty($this->cfg['today_only']);

        $srcId  = $this->graphFolderId($token, 'inbox', $src);
        if (!$srcId) throw new RuntimeException("Dossier source '{$src}' introuvable.");
        // Chercher le dossier traité comme enfant du dossier source
        // puis en fallback comme dossier racine de la boîte
        $doneId = $done ? ($this->graphChildFolderId($token, $srcId, $done)
                        ?? $this->graphFolderId($token, 'inbox', $done)) : null;

        $filter = "contains(subject,'" . addslashes($subj) . "')";
        if ($today) $filter .= ' and receivedDateTime ge ' . date('Y-m-d') . 'T00:00:00Z';

        $url = "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders/{$srcId}/messages"
             . '?$filter=' . rawurlencode($filter)
             . '&$select=id,subject,body&$top=50';

        $data = $this->graphGet($token, $url);
        $msgs = $data['value'] ?? [];

        // Dédupliquer par ID message (Graph peut retourner des doublons)
        $seen = [];
        $msgs = array_filter($msgs, function($m) use (&$seen) {
            if (isset($seen[$m['id']])) return false;
            $seen[$m['id']] = true;
            return true;
        });

        $bodies = [];
        foreach ($msgs as $msg) {
            $body = $msg['body'] ?? [];
            if (strtolower($body['contentType'] ?? '') !== 'html') continue;
            $bodies[] = $body['content'];

            $base = "https://graph.microsoft.com/v1.0/users/{$sender}/messages/{$msg['id']}";
            $this->graphReq('PATCH', $token, $base, ['isRead' => true]);
            if ($doneId) $this->graphReq('POST', $token, $base . '/move', ['destinationId' => $doneId]);
        }
        return $bodies;
    }

    private function graphToken(): string {
        $url  = "https://login.microsoftonline.com/{$this->cfg['graph_tenant_id']}/oauth2/v2.0/token";
        $body = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->cfg['graph_client_id'],
            'client_secret' => $this->cfg['graph_client_secret'],
            'scope'         => 'https://graph.microsoft.com/.default',
        ]);
        $ctx  = stream_context_create(['http' => ['method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body, 'timeout' => 15]]);
        $resp = json_decode(@file_get_contents($url, false, $ctx), true) ?? [];
        if (empty($resp['access_token'])) {
            throw new RuntimeException('Graph token: ' . ($resp['error_description'] ?? 'Échec'));
        }
        return $resp['access_token'];
    }

    private function graphChildFolderId(string $token, string $parentId, string $name): ?string {
        $sender = $this->cfg['graph_sender'];
        $url = "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders/{$parentId}/childFolders?\$top=50";
        while ($url) {
            $data = $this->graphGet($token, $url);
            foreach ($data['value'] ?? [] as $f) {
                if (strcasecmp($f['displayName'] ?? '', $name) === 0) return $f['id'];
            }
            $url = $data['@odata.nextLink'] ?? null;
        }
        return null;
    }

    private function graphFolderId(string $token, string $parent, string $name): ?string {
        $sender = $this->cfg['graph_sender'];

        // Si parent = 'inbox', chercher d'abord dans les dossiers racine de la boîte
        // car certains dossiers sont au niveau racine et non enfants de inbox
        if (strtolower($parent) === 'inbox') {
            $rootUrl = "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders?\$top=50";
            $url = $rootUrl;
            while ($url) {
                $data = $this->graphGet($token, $url);
                foreach ($data['value'] ?? [] as $f) {
                    if (strcasecmp($f['displayName'] ?? '', $name) === 0) return $f['id'];
                }
                $url = $data['@odata.nextLink'] ?? null;
            }
        }

        // Chercher ensuite dans les enfants du dossier parent
        $url = "https://graph.microsoft.com/v1.0/users/{$sender}/mailFolders/{$parent}/childFolders?\$top=50";
        while ($url) {
            $data = $this->graphGet($token, $url);
            foreach ($data['value'] ?? [] as $f) {
                if (strcasecmp($f['displayName'] ?? '', $name) === 0) return $f['id'];
            }
            $url = $data['@odata.nextLink'] ?? null;
        }
        return null;
    }

    private function graphGet(string $token, string $url): array {
        $ctx = stream_context_create(['http' => ['method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nConsistencyLevel: eventual\r\n",
            'timeout' => 20]]);
        return json_decode(@file_get_contents($url, false, $ctx), true) ?? [];
    }

    private function graphReq(string $method, string $token, string $url, array $body): void {
        $json = json_encode($body);
        $ctx  = stream_context_create(['http' => ['method' => $method,
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
            'content' => $json, 'timeout' => 10]]);
        @file_get_contents($url, false, $ctx);
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  PARSING HTML
    // ═══════════════════════════════════════════════════════════════════════════
    private function parseEmail(string $html): array {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $sections = [];
        foreach ($xpath->query('//h2|//h3') as $heading) {
            $raw   = trim($heading->textContent);
            $title = trim(preg_replace('/:\s*\d+.*$/', '', $raw));
            if (!$title) continue;

            $desc    = '';
            $advice  = [];
            $entries = [];
            $sib     = $heading->nextSibling;

            while ($sib) {
                if ($sib->nodeType === XML_ELEMENT_NODE) {
                    $tag = strtolower($sib->nodeName);
                    if (in_array($tag, ['h1','h2','h3'])) break;
                    if ($tag === 'p' && !$desc) $desc = trim($sib->textContent);
                    if (in_array($tag, ['ul','ol'])) {
                        foreach ($xpath->query('.//li', $sib) as $li) {
                            $liText  = trim($li->textContent);
                            $license = null;
                            foreach ($xpath->query('.//a', $li) as $a) {
                                if (preg_match('/^\d{10}$/', trim($a->textContent))) {
                                    $license = trim($a->textContent);
                                    break;
                                }
                            }
                            if ($license) {
                                $e = $this->parseEntry($license, $liText);
                                if ($e) $entries[] = $e;
                            } elseif ($liText) {
                                $advice[] = $liText;
                            }
                        }
                    }
                }
                $sib = $sib->nextSibling;
            }

            if ($entries) {
                $sections[] = ['section_title' => $title, 'description' => $desc,
                               'advice' => $advice, 'entries' => $entries];
            }
        }
        return $sections;
    }

    private function parseEntry(string $license, string $text): ?array {
        preg_match('/\((\[.*?\]\s+.+?)\)/', $text, $m);
        $rawTag   = $m[1] ?? '';
        $tagClean = $this->extractTag($rawTag);

        preg_match('/:\s*(depuis le .+)$/i', $text, $sm);
        $detail = $sm[1] ?? '';

        $isBeehive  = (bool)preg_match('/BEEHIVE/i', $rawTag);
        $beehiveTag = '';
        if ($isBeehive) {
            preg_match('/jeu de sauvegarde\s+"([^"]+)"/i', $text, $jm);
            if (!empty($jm[1]) && preg_match('/^([A-Za-z0-9]+)\s*[-–]/', $jm[1], $bm)) {
                $beehiveTag = strtoupper($bm[1]);
            }
        }

        return compact('license', 'rawTag', 'tagClean', 'detail', 'isBeehive', 'beehiveTag');
    }

    private function extractTag(string $raw): string {
        $str = $raw;
        foreach ($this->prefixes as $pfx) {
            $str = preg_replace('/^\s*' . preg_quote(trim($pfx), '/') . '\s*/i', '', $str);
        }
        $str   = preg_replace('/^\s*\[[^\]]*\]\s*/', '', $str);
        $parts = preg_split('/\s+/', trim($str));
        return strtoupper($parts[0] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  TRAITEMENT → GLPI
    // ═══════════════════════════════════════════════════════════════════════════
    private function processEntries(array $sections): void {
        $sectionsCfg    = PluginBeemoconnectConfig::getSections();
        $groupId        = (int)($this->cfg['glpi_group_id']   ?? 9);
        $reqTypeId      = (int)($this->cfg['request_type_id'] ?? 10);
        $urgencyDef     = (int)($this->cfg['urgency_default'] ?? 3);
        // Licences BEEHIVE à ignorer
        $ignoredLicenses = array_filter(array_map('trim',
            explode("\n", $this->cfg['beehive_ignore'] ?? '')
        ));

        $created = $updated = $skipped = 0;

        foreach ($sections as $section) {
            $sTitle  = $section['section_title'];
            $secCfg  = $this->matchSection($sectionsCfg, $sTitle);
            $urgency = (int)($secCfg['urgency'] ?? $urgencyDef);

            if ($secCfg !== null && empty($secCfg['enabled'])) {
                $skipped += count($section['entries']);
                continue;
            }

            foreach ($section['entries'] as $entry) {
                // Ignorer les licences listées dans beehive_ignore
                if (!empty($ignoredLicenses) && in_array($entry['license'], $ignoredLicenses)) {
                    $skipped++;
                    continue;
                }

                $searchKey = ($entry['isBeehive'] && $entry['beehiveTag'])
                    ? $entry['beehiveTag'] : $entry['tagClean'];

                $entityId    = $this->resolveEntity($searchKey);
                $entityFound = ($entityId > 0) || !$searchKey;
                [$title, $desc] = $this->buildContent($section, $entry, $entityFound);

                $stateKey = $sTitle . '|' . $entry['license'];
                $known    = $this->loadState($stateKey);

                if ($known) {
                    $status = $this->ticketStatus((int)$known['tickets_id']);
                    if ($status > 0 && !in_array($status, self::CLOSED_STATUSES)) {
                        // Ticket ouvert → tâche de relance SANS toucher au statut
                        $this->addTask((int)$known['tickets_id'], $entry);
                        $updated++;
                        continue;
                    }
                    // Fermé/Résolu → supprimer du state, recréer
                    $this->deleteState($stateKey);
                }

                $loginBase = preg_replace('/[^A-Z0-9]/', '', strtoupper($searchKey));
                $userId    = $this->resolveUser($loginBase . '_Portail');
                $ticketId  = $this->createTicket($title, $desc, $entityId, $urgency, $reqTypeId);
                if (!$ticketId) { $skipped++; continue; }

                if ($userId) $this->addActor($ticketId, 1, $userId, null);
                $this->addActor($ticketId, 2, null, $groupId);
                // Forcer Nouveau après assignation du groupe (GLPI passe en En cours sinon)
                $this->forceStatus($ticketId, self::STATUS_NEW);

                $this->saveState($stateKey, $ticketId, self::STATUS_NEW);
                $created++;
            }
        }

        $this->lastStats = compact('created', 'updated', 'skipped');
        PluginBeemoconnectConfig::setValue('last_run', date('Y-m-d H:i:s'));
        PluginBeemoconnectConfig::setValue('last_run_result',
            "{$created} créé(s), {$updated} relance(s), {$skipped} ignoré(s)");
    }

    private function matchSection(array $cfg, string $title): ?array {
        foreach ($cfg as $key => $val) {
            if (str_starts_with($title, substr($key, 0, 40))) return $val;
        }
        return null;
    }

    private function resolveEntity(string $tag): int {
        if (!$tag) return 0;
        $up = strtoupper(trim($tag));
        if (!$this->entityCache) {
            global $DB;
            $iter = $DB->request(['SELECT' => ['id', 'tag'], 'FROM' => 'glpi_entities']);
            foreach ($iter as $e) {
                $t = strtoupper(trim($e['tag'] ?? ''));
                if ($t) $this->entityCache[$t] = (int)$e['id'];
            }
        }
        return $this->entityCache[$up] ?? 0;
    }

    private function resolveUser(string $login): ?int {
        if (isset($this->userCache[$login])) return $this->userCache[$login];
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['name' => $login, 'is_deleted' => 0],
            'LIMIT'  => 1,
        ]);
        $row  = $iter->current();
        $id   = $row ? (int)$row['id'] : null;
        return $this->userCache[$login] = $id;
    }

    private function ticketStatus(int $id): int {
        $t = new Ticket();
        return $t->getFromDB($id) ? (int)$t->fields['status'] : 0;
    }

    private function forceStatus(int $id, int $status): void {
        $t = new Ticket();
        $t->update(['id' => $id, 'status' => $status]);
    }

    private function createTicket(string $name, string $desc,
                                  int $entityId, int $urgency, int $reqType): ?int {
        $t  = new Ticket();
        $id = $t->add([
            'name'            => $name,
            'content'         => $desc,
            'entities_id'     => $entityId,
            'type'            => Ticket::INCIDENT_TYPE,
            'status'          => self::STATUS_NEW,
            'urgency'         => $urgency,
            'priority'        => $urgency,
            'impact'          => $urgency,
            'requesttypes_id' => $reqType,
        ]);
        return $id ?: null;
    }

    private function addActor(int $ticketId, int $type, ?int $userId, ?int $groupId): void {
        if ($userId) {
            $tu = new Ticket_User();
            $tu->add(['tickets_id' => $ticketId, 'users_id'  => $userId,  'type' => $type]);
        }
        if ($groupId) {
            $gt = new Group_Ticket();
            $gt->add(['tickets_id' => $ticketId, 'groups_id' => $groupId, 'type' => $type]);
        }
    }

    private function addTask(int $ticketId, array $entry): void {
        $today = date('d/m/Y H:i');
        $tt    = new TicketTask();
        $tt->add([
            'tickets_id' => $ticketId,
            'content'    => "Relance automatique BEEMO - {$today}\n"
                          . "Problème toujours signalé dans le rapport BEEMO.\n"
                          . "Licence : {$entry['license']} — {$entry['rawTag']}\n"
                          . "Détail  : " . ($entry['detail'] ?: '-'),
            'state'      => 1,
            'is_private' => 0,
        ]);
    }

    private function buildContent(array $section, array $entry, bool $entityFound): array {
        $title = 'Beemo - ' . $section['section_title'];
        $lines = [];
        if ($section['description']) {
            $lines[] = '<h3>Problème</h3><p>' . htmlspecialchars($section['description']) . '</p>';
        }
        $lines[] = '<h3>Beemo concernée</h3>';
        $lines[] = '<p><strong>Licence :</strong> ' . htmlspecialchars($entry['license']) . '</p>';
        $lines[] = '<p><strong>Identifiant :</strong> ' . htmlspecialchars($entry['rawTag']) . '</p>';
        if ($entry['detail']) {
            $lines[] = '<p><strong>Depuis :</strong> ' . htmlspecialchars($entry['detail']) . '</p>';
        }
        if (!$entityFound) {
            $lines[] = '<p><em>⚠️ Entité GLPI non trouvée. TAG BEEMO : '
                     . htmlspecialchars($entry['rawTag']) . '</em></p>';
        }
        if ($section['advice']) {
            $lines[] = '<h3>Conseils de résolution Beemo</h3><ul>';
            foreach ($section['advice'] as $a) {
                $lines[] = '<li>' . htmlspecialchars($a) . '</li>';
            }
            $lines[] = '</ul>';
        }
        return [$title, implode("\n", $lines)];
    }

    // ─── State DB ─────────────────────────────────────────────────────────────
    private function loadState(string $key): ?array {
        global $DB;
        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_beemoconnect_states',
            'WHERE' => ['state_key' => $key],
            'LIMIT' => 1,
        ]);
        return $iter->current() ?: null;
    }

    private function saveState(string $key, int $ticketId, int $status): void {
        global $DB;
        if ($this->loadState($key)) {
            $DB->update('glpi_plugin_beemoconnect_states',
                ['tickets_id' => $ticketId, 'ticket_status' => $status],
                ['state_key'  => $key]);
        } else {
            $DB->insert('glpi_plugin_beemoconnect_states', [
                'state_key'     => $key,
                'tickets_id'    => $ticketId,
                'ticket_status' => $status,
            ]);
        }
    }

    private function deleteState(string $key): void {
        global $DB;
        $DB->delete('glpi_plugin_beemoconnect_states', ['state_key' => $key]);
    }
}
