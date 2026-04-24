<?php
/**
 * BeemoConnect — Page de configuration
 * GLPI 11 : CSRF via meta[property="glpi:csrf_token"] + XHR header X-Glpi-Csrf-Token
 */
include('../../../inc/includes.php');
Session::checkRight('config', UPDATE);

// ── Traitement POST (soumis via XHR) ─────────────────────────────────────────
if (isset($_POST['save'])) {
    $isAjax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    $sections = [];
    if (!empty($_POST['section_name']) && is_array($_POST['section_name'])) {
        foreach ($_POST['section_name'] as $i => $name) {
            if ($name === '') continue;
            $sections[$name] = [
                'enabled' => isset($_POST['section_enabled'][$i]) ? 1 : 0,
                'urgency' => (int)($_POST['section_urgency'][$i] ?? 3),
            ];
        }
    }

    PluginBeemoconnectConfig::setValues([
        'mail_type'           => $_POST['mail_type']           ?? 'imap',
        'imap_server'         => $_POST['imap_server']         ?? '',
        'imap_port'           => $_POST['imap_port']           ?? '993',
        'imap_user'           => $_POST['imap_user']           ?? '',
        'imap_password'       => $_POST['imap_password']       ?? '',
        'imap_ssl'            => isset($_POST['imap_ssl']) ? '1' : '0',
        'graph_tenant_id'     => $_POST['graph_tenant_id']     ?? '',
        'graph_client_id'     => $_POST['graph_client_id']     ?? '',
        'graph_client_secret' => $_POST['graph_client_secret'] ?? '',
        'graph_sender'        => $_POST['graph_sender']        ?? '',
        'source_folder'       => $_POST['source_folder']       ?? 'INBOX',
        'done_folder'         => $_POST['done_folder']         ?? 'Traité',
        'subject_filter'      => $_POST['subject_filter']      ?? 'Parc BEEMO',
        'today_only'          => isset($_POST['today_only']) ? '1' : '0',
        'tag_prefixes'        => $_POST['tag_prefixes']        ?? '[B2B]',
        'scheduled_hour'      => $_POST['scheduled_hour']      ?? '8',
        'scheduled_minute'    => $_POST['scheduled_minute']    ?? '0',
        'glpi_group_id'       => $_POST['glpi_group_id']       ?? '9',
        'request_type_id'     => $_POST['request_type_id']     ?? '10',
        'urgency_default'     => $_POST['urgency_default']     ?? '3',
        'beehive_ignore'      => $_POST['beehive_ignore']      ?? '',
        'sections_config'     => json_encode($sections, JSON_UNESCAPED_UNICODE),
    ]);

    // ── Mise à jour automatique du crontab système ──────────────────────────
    $hour        = (int)($_POST['scheduled_hour'] ?? 8);
    $cronScript  = GLPI_ROOT . '/plugins/beemoconnect/front/runcron.php';
    $minute      = (int)($_POST['scheduled_minute'] ?? 0);
    $cronLine    = $minute . ' ' . $hour . ' * * * /usr/local/bin/php ' . $cronScript . ' >> /tmp/beemoconnect.log 2>&1';
    $marker      = 'beemoconnect/front/runcron.php';
    $currentCron = shell_exec('crontab -l 2>/dev/null') ?? '';

    // Supprimer l'ancienne ligne BeemoConnect si présente, puis réécrire
    $lines   = array_filter(explode("\n", $currentCron), fn($l) => !str_contains($l, $marker));
    $newCron = implode("\n", $lines);
    $newCron = rtrim($newCron) . "\n" . $cronLine . "\n";
    $escaped = escapeshellarg($newCron);
    shell_exec("echo $escaped | crontab -");

    Session::addMessageAfterRedirect('Configuration sauvegardée.', true, INFO);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
    Html::redirect(Plugin::getWebDir('beemoconnect') . '/front/config.php');
}

$cfg      = PluginBeemoconnectConfig::getAll();
$sections = PluginBeemoconnectConfig::getSections();
$webDir   = Plugin::getWebDir('beemoconnect');
$csrfToken = Session::getNewCSRFToken();
$urgL     = [1=>'Très basse',2=>'Basse',3=>'Moyenne',4=>'Haute',5=>'Très haute'];

// ── Vérification et auto-fix statut cron ─────────────────────────────────────
$cronOk   = false;
$cronTask = null;
if (class_exists('CronTask')) {
    $ct = new CronTask();
    // Toujours tenter l'enregistrement (sans effet si déjà présent)
    if (!$ct->getFromDBbyName('beemoconnect', 'BeemoConnectProcess')) {
        // Insertion directe en DB si CronTask::register() échoue
        global $DB;
        $DB->insertOrIgnore('glpi_crontasks', [
            'itemtype'      => 'beemoconnect',
            'name'          => 'BeemoConnectProcess',
            'frequency'     => DAY_TIMESTAMP,
            'param'         => null,
            'state'         => 1,
            'mode'          => 1,
            'allowmode'     => 3,
            'hourmin'       => 0,
            'hourmax'       => 24,
            'logs_lifetime' => 30,
            'lastrun'       => null,
            'lastcode'      => null,
            'comment'       => 'Traitement des mails BEEMO → Tickets GLPI',
        ]);
    }
    if ($ct->getFromDBbyName('beemoconnect', 'BeemoConnectProcess')) {
        $cronOk   = true;
        $cronTask = $ct->fields;
    }
}

// Vérifier + auto-créer le cron système
$sysCron    = false;
$cronLine   = '* * * * * php ' . GLPI_ROOT . '/front/cron.php > /dev/null 2>&1';
$cronOutput = shell_exec('crontab -l 2>/dev/null') ?? '';
if (str_contains($cronOutput, 'cron.php') || str_contains($cronOutput, 'glpi')) {
    $sysCron = true;
} else {
    // Tenter d'ajouter automatiquement
    $escaped = escapeshellarg($cronOutput . "
" . $cronLine . "
");
    $result  = shell_exec("echo $escaped | crontab - 2>&1");
    // Revérifier
    $cronOutput2 = shell_exec('crontab -l 2>/dev/null') ?? '';
    if (str_contains($cronOutput2, 'cron.php')) {
        $sysCron = true;
    }
}

Html::header('BeemoConnect', $_SERVER['PHP_SELF'], 'config', 'plugins');
Html::displayMessageAfterRedirect();
?>
<div class="bc-wrap">
  <div class="bc-header">
    <div class="bc-icon"><i class="fas fa-envelope-open-text"></i></div>
    <div><h1>BeemoConnect</h1><p>Intégration BEEMO Suivi de Parc → Tickets GLPI</p></div>
    <?php if (!empty($cfg['last_run'])): ?>
    <div class="bc-lastrun">
      <span>Dernier passage : <strong><?= htmlspecialchars($cfg['last_run']) ?></strong></span>
      <?php if (!empty($cfg['last_run_result'])): ?>
      <span class="bc-green"><?= htmlspecialchars($cfg['last_run_result']) ?></span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Statut cron -->
  <?php if (!$cronOk): ?>
  <div class="bc-alert bc-alert-warn">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Tâche GLPI introuvable</strong> — BeemoConnectProcess n'a pas pu être enregistrée. Rechargez la page ou contactez l'administrateur.
  </div>
  <?php endif; ?>

  <?php if (!$sysCron): ?>
  <div class="bc-alert bc-alert-warn">
    <i class="fas fa-exclamation-triangle"></i>
    <div>
      <strong>Cron système non configuré</strong> — La ligne suivante n'a pas pu être ajoutée automatiquement. Ajoutez-la manuellement (<code>crontab -e</code>) :
      <code class="bc-code-block">* * * * * /usr/local/bin/php <?= GLPI_ROOT ?>/plugins/beemoconnect/front/runcron.php &gt;&gt; /tmp/beemoconnect.log 2&gt;&amp;1</code>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" action="<?= $webDir ?>/front/config.php" id="bcForm">
    <input type="hidden" name="_glpi_csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="mail_type" id="mail_type" value="<?= htmlspecialchars($cfg['mail_type'] ?? 'imap') ?>">

    <!-- 1. CONNEXION MAIL -->
    <div class="bc-card">
      <h2><i class="fas fa-plug"></i> Connexion mail</h2>
      <div class="bc-tabs">
        <button type="button" class="bc-tab <?= ($cfg['mail_type']??'imap')==='imap'?'active':'' ?>" onclick="switchTab('imap',this)"><i class="fas fa-server"></i> IMAP classique</button>
        <button type="button" class="bc-tab <?= ($cfg['mail_type']??'imap')==='graph'?'active':'' ?>" onclick="switchTab('graph',this)"><i class="fab fa-microsoft"></i> Microsoft 365</button>
      </div>
      <div id="tab-imap" class="<?= ($cfg['mail_type']??'imap')!=='imap'?'bc-hidden':'' ?>">
        <div class="bc-row">
          <div class="bc-field"><label>Serveur IMAP</label><input type="text" name="imap_server" value="<?= htmlspecialchars($cfg['imap_server']??'') ?>" placeholder="ssl0.ovh.net"></div>
          <div class="bc-field bc-field-sm"><label>Port</label><input type="number" name="imap_port" value="<?= (int)($cfg['imap_port']??993) ?>"></div>
        </div>
        <div class="bc-row">
          <div class="bc-field"><label>Utilisateur</label><input type="text" name="imap_user" value="<?= htmlspecialchars($cfg['imap_user']??'') ?>" placeholder="beemo@exemple.fr"></div>
          <div class="bc-field"><label>Mot de passe <small>(vide = inchangé)</small></label><input type="password" name="imap_password" placeholder="••••••••"></div>
        </div>
        <label class="bc-check"><input type="checkbox" name="imap_ssl" <?= !empty($cfg['imap_ssl'])?'checked':'' ?>> SSL/TLS</label>
      </div>
      <div id="tab-graph" class="<?= ($cfg['mail_type']??'imap')!=='graph'?'bc-hidden':'' ?>">
        <div class="bc-row">
          <div class="bc-field"><label>Tenant ID</label><input type="text" name="graph_tenant_id" value="<?= htmlspecialchars($cfg['graph_tenant_id']??'') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
          <div class="bc-field"><label>Client ID</label><input type="text" name="graph_client_id" value="<?= htmlspecialchars($cfg['graph_client_id']??'') ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"></div>
        </div>
        <div class="bc-row">
          <div class="bc-field"><label>Client Secret <small>(vide = inchangé)</small></label><input type="password" name="graph_client_secret" placeholder="••••••••"></div>
          <div class="bc-field"><label>Adresse mail</label><input type="email" name="graph_sender" value="<?= htmlspecialchars($cfg['graph_sender']??'') ?>" placeholder="supervision@entreprise.fr"></div>
        </div>
        <div class="bc-info"><i class="fas fa-info-circle"></i> Permissions Azure requises : <strong>Mail.Read</strong> et <strong>Mail.ReadWrite</strong> (Application, consentement admin).</div>
      </div>
      <div class="bc-actions">
        <button type="button" class="bc-btn-sec" onclick="testConn(this)"><i class="fas fa-vial"></i> Tester la connexion</button>
        <span id="connResult"></span>
      </div>
    </div>

    <!-- 2. DOSSIERS -->
    <div class="bc-card">
      <h2><i class="fas fa-folder-open"></i> Dossiers mail</h2>
      <div class="bc-row">
        <div class="bc-field">
          <label>Dossier source</label>
          <input type="text" name="source_folder" value="<?= htmlspecialchars($cfg['source_folder']??'INBOX') ?>">
          <p class="bc-help">Dossier où arrivent les mails BEEMO (ex : <code>INBOX</code> ou <code>0001_suivisparcbeemo</code>).</p>
        </div>
        <div class="bc-field">
          <label>Dossier traité</label>
          <input type="text" name="done_folder" value="<?= htmlspecialchars($cfg['done_folder']??'Traité') ?>">
          <p class="bc-help">Dossier de destination après traitement (ex : <code>Traité</code> ou <code>traite</code>). IMAP avec accents : encodage UTF-7 requis (ex : <code>Trait&amp;AOk-</code>).</p>
        </div>
      </div>
      <div class="bc-row">
        <div class="bc-field"><label>Filtre objet mail</label><input type="text" name="subject_filter" value="<?= htmlspecialchars($cfg['subject_filter']??'Parc BEEMO') ?>"></div>
        <div class="bc-field" style="justify-content:flex-end;padding-bottom:8px"><label class="bc-check"><input type="checkbox" name="today_only" <?= !empty($cfg['today_only'])?'checked':'' ?>> Mails du jour uniquement</label></div>
      </div>
    </div>

    <!-- 3. TAG -->
    <div class="bc-card">
      <h2><i class="fas fa-tag"></i> Détection du TAG client</h2>
      <div class="bc-field">
        <label>Préfixes à ignorer <small>(un par ligne)</small></label>
        <textarea name="tag_prefixes" rows="4" placeholder="[B2B]&#10;[EVAL]&#10;[VM]"><?= htmlspecialchars($cfg['tag_prefixes']??'[B2B]') ?></textarea>
        <p class="bc-help">Le TAG GLPI est le <strong>premier mot après le dernier préfixe</strong>. Ex : <code>[B2B] CLIENT1 17879</code> → TAG = <strong>CLIENT1</strong>. Match exact avec le champ Tag de l'entité GLPI.</p>
      </div>
    </div>

    <!-- 4. GLPI -->
    <div class="bc-card">
      <h2><i class="fas fa-cog"></i> Paramètres GLPI</h2>
      <div class="bc-row bc-row-3">
        <div class="bc-field"><label>Groupe assigné (ID)</label><input type="number" name="glpi_group_id" value="<?= (int)($cfg['glpi_group_id']??9) ?>"><p class="bc-help">ID groupe Infrastructure</p></div>
        <div class="bc-field"><label>Source de la demande (ID)</label><input type="number" name="request_type_id" value="<?= (int)($cfg['request_type_id']??10) ?>"><p class="bc-help">ID "Supervision"</p></div>
        <div class="bc-field"><label>Urgence par défaut</label><select name="urgency_default"><?php foreach($urgL as $v=>$l): ?><option value="<?=$v?>" <?=($cfg['urgency_default']??3)==$v?'selected':''?>><?=$l?></option><?php endforeach; ?></select></div>
      </div>
      <div class="bc-row" style="margin-top:14px">
        <div class="bc-field">
          <label><i class="fas fa-clock"></i> Heure d'exécution automatique</label>
          <div style="display:flex;align-items:center;gap:8px">
            <select name="scheduled_hour" style="width:90px">
              <?php for($h=0;$h<=23;$h++): ?>
              <option value="<?=$h?>" <?=($cfg['scheduled_hour']??'8')==$h?'selected':''?>><?=str_pad($h,2,'0',STR_PAD_LEFT)?>h</option>
              <?php endfor; ?>
            </select>
            <select name="scheduled_minute" style="width:90px">
              <?php foreach([0,5,10,15,20,25,30,35,40,45,50,55] as $m): ?>
              <option value="<?=$m?>" <?=($cfg['scheduled_minute']??'0')==$m?'selected':''?>><?=str_pad($m,2,'0',STR_PAD_LEFT)?>min</option>
              <?php endforeach; ?>
            </select>
            <span class="bc-help">Exécuté une fois par jour à l'heure choisie.</span>
          </div>
        </div>
      </div>
      <div class="bc-field" style="margin-top:14px">
        <label>Licences à ignorer <small>(une par ligne)</small></label>
        <textarea name="beehive_ignore" rows="3" placeholder="6606018377&#10;6606019255"><?= htmlspecialchars($cfg['beehive_ignore']??'') ?></textarea>
        <p class="bc-help">Les entrées dont la licence figure ici seront ignorées. Laisser vide pour tout traiter.</p>
      </div>
    </div>

    <!-- 5. SECTIONS -->
    <div class="bc-card">
      <h2><i class="fas fa-list-ul"></i> Sections du rapport</h2>
      <table class="bc-table">
        <thead><tr><th>Section</th><th>Activer</th><th>Urgence</th></tr></thead>
        <tbody>
        <?php foreach ($sections as $name => $scfg): ?>
        <tr class="<?= empty($scfg['enabled'])?'bc-disabled':'' ?>">
          <input type="hidden" name="section_name[]" value="<?= htmlspecialchars($name) ?>">
          <td><?= htmlspecialchars($name) ?></td>
          <td><label class="bc-check"><input type="checkbox" name="section_enabled[]" value="1" <?= !empty($scfg['enabled'])?'checked':'' ?> onchange="this.closest('tr').classList.toggle('bc-disabled',!this.checked)"> Actif</label></td>
          <td><select name="section_urgency[]"><?php foreach($urgL as $v=>$l): ?><option value="<?=$v?>" <?=($scfg['urgency']??3)==$v?'selected':''?>><?=$l?></option><?php endforeach; ?></select></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="bc-footer">
      <button type="button" class="bc-btn-pri" onclick="saveConfig(this)"><i class="fas fa-save"></i> Enregistrer</button>
      <button type="button" class="bc-btn-sec" onclick="manualRun(this)"><i class="fas fa-play"></i> Lancer maintenant</button>
    </div>
  </form>
  <div id="runResult" style="margin-top:12px"></div>
</div>

<style>
.bc-wrap{max-width:960px;margin:20px auto;padding:0 16px 60px;font-family:system-ui,sans-serif}
.bc-header{display:flex;align-items:center;gap:14px;margin-bottom:24px}
.bc-icon{width:48px;height:48px;background:#2563eb;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;flex-shrink:0}
.bc-header h1{margin:0;font-size:20px;font-weight:700}.bc-header p{margin:2px 0 0;color:#6b7280;font-size:13px}
.bc-lastrun{margin-left:auto;text-align:right;font-size:12px;color:#6b7280;display:flex;flex-direction:column;gap:2px}
.bc-green{color:#16a34a}
.bc-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:22px;margin-bottom:18px;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.bc-card h2{margin:0 0 18px;font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px}.bc-card h2 i{color:#2563eb}
.bc-tabs{display:flex;gap:4px;background:#f3f4f6;border-radius:8px;padding:4px;margin-bottom:18px}
.bc-tab{flex:1;padding:7px 12px;border:none;border-radius:6px;font-size:13px;font-weight:600;color:#6b7280;background:transparent;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px}
.bc-tab.active{background:#fff;color:#2563eb;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.bc-hidden{display:none!important}
.bc-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}.bc-row-3{grid-template-columns:1fr 1fr 1fr}
.bc-field{display:flex;flex-direction:column;gap:5px}.bc-field-sm{max-width:140px}
.bc-field label{font-size:13px;font-weight:600;color:#374151}.bc-field label small{font-weight:400;color:#9ca3af}
.bc-field input,.bc-field select,.bc-field textarea{border:1px solid #d1d5db;border-radius:6px;padding:7px 10px;font-size:13px;color:#111;width:100%;box-sizing:border-box}
.bc-field input:focus,.bc-field select:focus,.bc-field textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.1)}
.bc-help{margin:2px 0 0;font-size:11.5px;color:#6b7280;line-height:1.5}
.bc-help code{background:#f3f4f6;padding:1px 4px;border-radius:3px;font-size:11px}
.bc-info{background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:9px 12px;font-size:12.5px;color:#1e40af;margin-top:12px}
.bc-check{display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:500;cursor:pointer;color:#374151}
.bc-actions{margin-top:14px;display:flex;align-items:center;gap:12px}
.bc-table{width:100%;border-collapse:collapse;font-size:13px}
.bc-table th{text-align:left;padding:8px 10px;background:#f9fafb;border-bottom:2px solid #e5e7eb;font-size:12px;font-weight:700;color:#6b7280;text-transform:uppercase}
.bc-table td{padding:9px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.bc-table tr.bc-disabled td:first-child{opacity:.4}
.bc-footer{display:flex;gap:12px;margin-top:6px}
.bc-btn-pri{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.bc-btn-pri:hover{background:#1d4ed8}
.bc-btn-sec{display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#f3f4f6;color:#374151;border:1px solid #d1d5db;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer}
.bc-btn-sec:hover{background:#e5e7eb}
.bc-alert{display:flex;align-items:flex-start;gap:12px;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:13px}
.bc-alert i{font-size:16px;flex-shrink:0;margin-top:1px}
.bc-alert-warn{background:#fffbeb;border:1px solid #fcd34d;color:#92400e}
.bc-alert-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.bc-alert-info{background:#eff6ff;border:1px solid #93c5fd;color:#1e40af}
.bc-link{color:inherit;font-weight:700;text-decoration:underline;margin-left:8px;cursor:pointer}
.bc-code-block{display:block;background:rgba(0,0,0,.08);padding:6px 10px;border-radius:4px;margin:6px 0;font-family:monospace;font-size:12px;word-break:break-all}
.bc-ok{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:9px 14px;border-radius:7px;font-size:13px;display:inline-block}
.bc-err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:9px 14px;border-radius:7px;font-size:13px;display:inline-block}
@media(max-width:600px){.bc-row,.bc-row-3{grid-template-columns:1fr}}
</style>
<script>
function getGlpiCsrfToken() {
  const meta = document.querySelector('meta[property="glpi:csrf_token"]');
  return meta ? meta.getAttribute('content') : '';
}
function switchTab(type,btn){
  document.getElementById('mail_type').value=type;
  document.querySelectorAll('.bc-tab').forEach(t=>t.classList.remove('active'));
  btn.classList.add('active');
  ['imap','graph'].forEach(t=>{document.getElementById('tab-'+t).classList.toggle('bc-hidden',t!==type);});
}
function saveConfig(btn) {
  btn.disabled=true;
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Enregistrement…';
  const form=document.getElementById('bcForm');
  const fd=new FormData(form);
  fd.append('save','1');
  fetch(window.location.href,{
    method:'POST',
    headers:{'X-Glpi-Csrf-Token':getGlpiCsrfToken(),'X-Requested-With':'XMLHttpRequest'},
    body:fd,
  })
  .then(r=>{if(r.ok||r.redirected){window.location.reload();}else{throw new Error('HTTP '+r.status);}})
  .catch(e=>{alert('Erreur : '+e.message);btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Enregistrer';});
}
function testConn(btn){
  const el=document.getElementById('connResult');
  el.className='';el.textContent='Test en cours…';btn.disabled=true;
  const fd=new FormData();
  ['imap_server','imap_port','imap_user','imap_password','graph_tenant_id','graph_client_id','graph_client_secret','graph_sender'].forEach(n=>{const f=document.querySelector('[name='+n+']');if(f)fd.append(n,f.value);});
  fd.append('imap_ssl',document.querySelector('[name=imap_ssl]')?.checked?'1':'0');
  fd.append('mail_type',document.getElementById('mail_type').value);
  fetch('<?= $webDir ?>/ajax/test_connection.php',{method:'POST',headers:{'X-Glpi-Csrf-Token':getGlpiCsrfToken(),'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.text()).then(txt=>{try{return JSON.parse(txt);}catch(e){throw new Error(txt.substring(0,300));}})
    .then(d=>{el.className=d.success?'bc-ok':'bc-err';el.textContent=d.message;})
    .catch(e=>{el.className='bc-err';el.textContent='❌ '+e.message;})
    .finally(()=>{btn.disabled=false;});
}
function manualRun(btn){
  const el=document.getElementById('runResult');
  el.className='';el.textContent='Traitement en cours…';btn.disabled=true;
  const fd=new FormData();
  fetch('<?= $webDir ?>/ajax/run.php',{method:'POST',headers:{'X-Glpi-Csrf-Token':getGlpiCsrfToken(),'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.text()).then(txt=>{try{return JSON.parse(txt);}catch(e){throw new Error(txt.substring(0,300));}})
    .then(d=>{el.className=d.success?'bc-ok':'bc-err';el.innerHTML=d.message;})
    .catch(e=>{el.className='bc-err';el.textContent='❌ '+e.message;})
    .finally(()=>{btn.disabled=false;});
}
</script>
<?php Html::footer(); ?>
