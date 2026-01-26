<?php
/**
 * Bulk SMS / Массовая рассылка
 */

require_once __DIR__ . '/includes/campaign.php';
require_once __DIR__ . '/includes/contacts.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$campaign = new Campaign();
$contactsObj = new Contacts();

$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';

// Handle form submission - create campaign
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_campaign'])) {
    $numbers = [];
    
    // Parse pasted numbers
    if (!empty($_POST['numbers_text'])) {
        $numbers = Campaign::parseNumbers($_POST['numbers_text']);
    }
    
    // Handle CSV upload
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === 0) {
        $tmpPath = '/tmp/campaign_' . uniqid() . '.csv';
        move_uploaded_file($_FILES['csv_file']['tmp_name'], $tmpPath);
        $csvNumbers = Campaign::importFromCSV($tmpPath);
        unlink($tmpPath);
        $numbers = array_merge($numbers, $csvNumbers);
    }
    
    // Import from group
    if (!empty($_POST['import_group'])) {
        $groupContacts = $contactsObj->getByGroup((int)$_POST['import_group']);
        foreach ($groupContacts as $c) {
            $numbers[] = ['phone' => $c['phone_number'], 'name' => $c['name']];
        }
    }
    
    // Remove duplicates
    $uniquePhones = [];
    $uniqueNumbers = [];
    foreach ($numbers as $n) {
        if (!isset($uniquePhones[$n['phone']])) {
            $uniquePhones[$n['phone']] = true;
            $uniqueNumbers[] = $n;
        }
    }
    $numbers = $uniqueNumbers;
    
    if (empty($numbers)) {
        $error = __('phone_required');
    } elseif (empty($_POST['message'])) {
        $error = __('message_required');
    } else {
        $result = $campaign->create([
            'name' => $_POST['name'] ?: __('campaign_name_placeholder', ['date' => date('Y-m-d H:i')]),
            'message' => $_POST['message'],
            'numbers' => $numbers,
            'gateway_id' => !empty($_POST['gateway_id']) ? (int)$_POST['gateway_id'] : null,
            'port_mode' => $_POST['port_mode'] ?? 'random',
            'specific_port' => $_POST['specific_port'] ?? null,
            'send_delay' => (int)($_POST['send_delay'] ?? 1000)
        ]);
        
        if ($result['success']) {
            header('Location: bulk.php?action=view&id=' . $result['campaign_id'] . '&created=1');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

// Handle campaign actions
if (isset($_GET['start'])) {
    $result = $campaign->start((int)$_GET['start']);
    header('Location: bulk.php?action=view&id=' . $_GET['start']);
    exit;
}

if (isset($_GET['pause'])) {
    $campaign->pause((int)$_GET['pause']);
    header('Location: bulk.php?action=view&id=' . $_GET['pause']);
    exit;
}

if (isset($_GET['cancel'])) {
    $campaign->cancel((int)$_GET['cancel']);
    header('Location: bulk.php?action=view&id=' . $_GET['cancel']);
    exit;
}

if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $campaign->delete((int)$_GET['delete']);
    header('Location: bulk.php?success=deleted');
    exit;
}

if (isset($_GET['success'])) {
    $success = __('campaign_deleted');
}

if (isset($_GET['created'])) {
    $success = __('campaign_created');
}

// Get data based on action
$viewCampaign = null;
$campaignStats = null;
$campaignMessages = null;

if ($action === 'view' && isset($_GET['id'])) {
    $viewCampaign = $campaign->get((int)$_GET['id']);
    if ($viewCampaign) {
        $campaignStats = $campaign->getStats($viewCampaign['id']);
        $msgPage = max(1, (int)($_GET['msg_page'] ?? 1));
        $msgStatus = $_GET['msg_status'] ?? null;
        $campaignMessages = $campaign->getMessages($viewCampaign['id'], $msgPage, 50, $msgStatus);
    } else {
        $action = 'list';
    }
}

$campaigns = [];
if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $campaigns = $campaign->getAll($page, 20);
}

$groups = $contactsObj->getGroups();

// Load gateways and ports based on user permissions
require_once __DIR__ . '/includes/sms.php';
$auth = Auth::getInstance();
$gateways = $auth->getAllowedGateways('can_send');
$ports = $auth->getAllowedPorts(null, 'can_send');

renderHeader(__('bulk_sms'), 'bulk');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-broadcast me-2"></i> <?= __('bulk_sms') ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if ($action === 'list'): ?>
        <a href="?action=new" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= __('new_campaign') ?>
        </a>
        <?php else: ?>
        <a href="bulk.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> <?= __('back') ?>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle me-2"></i> <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'new'): ?>
<!-- New Campaign Form -->
<form method="post" enctype="multipart/form-data">
    <input type="hidden" name="create_campaign" value="1">
    
    <div class="row">
        <div class="col-lg-8">
            <!-- Campaign Name -->
            <div class="card mb-4">
                <div class="card-header"><?= __('campaign_name') ?></div>
                <div class="card-body">
                    <input type="text" name="name" class="form-control" 
                           placeholder="<?= __('campaign_name_placeholder', ['date' => date('Y-m-d H:i')]) ?>">
                </div>
            </div>
            
            <!-- Numbers Import -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-list-ol me-2"></i> <?= __('import_numbers') ?>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" data-bs-toggle="tab" href="#paste">
                                <i class="bi bi-clipboard"></i> <?= __('paste_numbers') ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#csv">
                                <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" data-bs-toggle="tab" href="#group">
                                <i class="bi bi-people"></i> <?= __('import_from_group') ?>
                            </a>
                        </li>
                    </ul>
                    
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="paste">
                            <textarea name="numbers_text" class="form-control font-monospace" rows="8" 
                                      placeholder="79001234567;Иван Иванов
79002345678;Петр Петров
79003456789"
                                      oninput="countNumbers(this)"></textarea>
                            <div class="d-flex justify-content-between mt-2">
                                <small class="text-muted"><?= __('paste_numbers_hint') ?></small>
                                <small id="numbersCount" class="text-muted">0 <?= __('numbers_count', ['count' => '']) ?></small>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="csv">
                            <div class="mb-3">
                                <input type="file" name="csv_file" class="form-control" accept=".csv,.txt">
                                <small class="text-muted"><?= __('csv_format_hint') ?></small>
                            </div>
                        </div>
                        
                        <div class="tab-pane fade" id="group">
                            <select name="import_group" class="form-select">
                                <option value=""><?= __('select_group') ?>...</option>
                                <?php foreach ($groups as $g): 
                                    $count = $contactsObj->getAll(1, 1, '', $g['id'])['total'];
                                ?>
                                <option value="<?= $g['id'] ?>">
                                    <?= htmlspecialchars($g['name']) ?> (<?= $count ?> <?= __('contacts') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Message -->
            <div class="card mb-4">
                <div class="card-header"><?= __('message_text') ?> *</div>
                <div class="card-body">
                    <textarea name="message" class="form-control" rows="5" required
                              oninput="updateCharCounter(this, 'msgCharCounter')"></textarea>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted"><?= __('personalization_hint') ?></small>
                        <small id="msgCharCounter" class="char-counter">0 <?= __('chars') ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-4">
            <!-- Gateway Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-hdd-network me-2"></i> <?= __('gateway_settings') ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label"><?= __('select_gateway') ?></label>
                        <select name="gateway_id" class="form-select" id="gatewaySelect" onchange="filterPorts()">
                            <option value=""><?= __('all_gateways') ?></option>
                            <?php foreach ($gateways as $gw): ?>
                            <option value="<?= $gw['id'] ?>" data-type="<?= $gw['type'] ?>" data-channels="<?= $gw['channels'] ?>">
                                <?= htmlspecialchars($gw['name']) ?> 
                                (<?= strtoupper($gw['type']) ?>, <?= $gw['channels'] ?> ch)
                                <?= $gw['is_default'] ? '★' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?= __('gateway_campaign_hint') ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Port Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-diagram-3 me-2"></i> <?= __('port_settings') ?>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label"><?= __('port_mode') ?></label>
                        <select name="port_mode" class="form-select" id="portMode" onchange="togglePortSelect()">
                            <option value="random"><?= __('port_random') ?></option>
                            <option value="linear"><?= __('port_linear') ?></option>
                            <option value="specific"><?= __('port_specific') ?></option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="specificPortDiv" style="display:none">
                        <label class="form-label"><?= __('select_port') ?></label>
                        <select name="specific_port" class="form-select" id="specificPortSelect">
                            <option value=""><?= __('select_port') ?>...</option>
                            <?php 
                            $currentGw = '';
                            foreach ($ports as $p): 
                                // Format port display based on gateway type
                                if ($p['gateway_type'] === 'openvox') {
                                    $slot = ceil($p['port_number'] / 4);
                                    $slotPort = (($p['port_number'] - 1) % 4) + 1;
                                    $portFormat = "gsm-{$slot}.{$slotPort}";
                                } else {
                                    $portFormat = "Line " . $p['port_number'];
                                }
                                
                                // Group header
                                if ($currentGw !== $p['gateway_name']):
                                    if ($currentGw !== '') echo '</optgroup>';
                                    $currentGw = $p['gateway_name'];
                            ?>
                            <optgroup label="<?= htmlspecialchars($p['gateway_name'] ?: 'Unknown') ?>">
                            <?php endif; ?>
                            <option value="<?= $p['port_number'] ?>" 
                                    data-gateway-id="<?= $p['gateway_id'] ?>">
                                <?= $portFormat ?> - <?= htmlspecialchars($p['port_name']) ?>
                                <?= $p['sim_number'] ? " ({$p['sim_number']})" : '' ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if ($currentGw !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('send_delay') ?></label>
                        <div class="input-group">
                            <input type="number" name="send_delay" class="form-control" 
                                   value="1000" min="100" max="60000">
                            <span class="input-group-text"><?= __('delay_ms') ?></span>
                        </div>
                        <small class="text-muted"><?= __('delay_hint') ?></small>
                    </div>
                </div>
            </div>
            
            <!-- Submit -->
            <div class="card">
                <div class="card-body">
                    <button type="submit" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-check-lg me-2"></i> <?= __('save') ?>
                    </button>
                    <p class="text-muted small mt-2 mb-0 text-center">
                        <?= __('campaign_status') ?>: <?= __('status_draft') ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function togglePortSelect() {
    const mode = document.getElementById('portMode').value;
    document.getElementById('specificPortDiv').style.display = mode === 'specific' ? 'block' : 'none';
    if (mode === 'specific') {
        filterPorts();
    }
}

function filterPorts() {
    const gatewayId = document.getElementById('gatewaySelect').value;
    const portSelect = document.getElementById('specificPortSelect');
    if (!portSelect) return;
    
    const options = portSelect.querySelectorAll('option[data-gateway-id]');
    const optgroups = portSelect.querySelectorAll('optgroup');
    
    // Show/hide options based on gateway
    options.forEach(opt => {
        if (!gatewayId || opt.dataset.gatewayId === gatewayId) {
            opt.style.display = '';
            opt.disabled = false;
        } else {
            opt.style.display = 'none';
            opt.disabled = true;
        }
    });
    
    // Show/hide optgroups (hide if all children are hidden)
    optgroups.forEach(grp => {
        const visibleOptions = grp.querySelectorAll('option:not([disabled])');
        grp.style.display = visibleOptions.length > 0 ? '' : 'none';
    });
    
    // Reset selection if current selection is hidden
    const selectedOpt = portSelect.options[portSelect.selectedIndex];
    if (selectedOpt && selectedOpt.disabled) {
        portSelect.value = '';
    }
}

function countNumbers(textarea) {
    const lines = textarea.value.trim().split('\n').filter(l => l.trim().length > 0);
    document.getElementById('numbersCount').textContent = lines.length + ' <?= __('numbers_count', ['count' => '']) ?>';
}
</script>

<?php elseif ($action === 'view' && $viewCampaign): ?>
<!-- View Campaign -->
<div class="row">
    <div class="col-lg-8">
        <!-- Campaign Info -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <strong><?= htmlspecialchars($viewCampaign['name']) ?></strong>
                </span>
                <?php
                $statusClass = match($viewCampaign['status']) {
                    'draft' => 'bg-secondary',
                    'running' => 'bg-primary',
                    'paused' => 'bg-warning text-dark',
                    'completed' => 'bg-success',
                    'cancelled' => 'bg-danger',
                    default => 'bg-secondary'
                };
                ?>
                <span class="badge <?= $statusClass ?>"><?= __('status_' . $viewCampaign['status']) ?></span>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label text-muted small"><?= __('message_text') ?>:</label>
                    <div class="p-3 bg-light rounded">
                        <?= nl2br(htmlspecialchars($viewCampaign['message'])) ?>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <?php 
                $progress = $viewCampaign['total_count'] > 0 
                    ? round(($viewCampaign['sent_count'] + $viewCampaign['failed_count']) / $viewCampaign['total_count'] * 100) 
                    : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1">
                        <span><?= __('progress') ?></span>
                        <span><?= __('sent_of_total', ['sent' => $viewCampaign['sent_count'], 'total' => $viewCampaign['total_count']]) ?></span>
                    </div>
                    <div class="progress" style="height: 25px;">
                        <div class="progress-bar bg-success" style="width: <?= round($viewCampaign['sent_count'] / max(1, $viewCampaign['total_count']) * 100) ?>%">
                            <?= $viewCampaign['sent_count'] ?>
                        </div>
                        <div class="progress-bar bg-danger" style="width: <?= round($viewCampaign['failed_count'] / max(1, $viewCampaign['total_count']) * 100) ?>%">
                            <?= $viewCampaign['failed_count'] ?>
                        </div>
                    </div>
                </div>
                
                <!-- Actions -->
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($viewCampaign['status'] === 'draft' || $viewCampaign['status'] === 'paused'): ?>
                    <a href="?start=<?= $viewCampaign['id'] ?>" class="btn btn-success" 
                       onclick="return confirm('<?= __('confirm_start_campaign', ['count' => $viewCampaign['total_count']]) ?>')">
                        <i class="bi bi-play-fill"></i> 
                        <?= $viewCampaign['status'] === 'paused' ? __('resume_campaign') : __('start_campaign') ?>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($viewCampaign['status'] === 'running'): ?>
                    <a href="?pause=<?= $viewCampaign['id'] ?>" class="btn btn-warning">
                        <i class="bi bi-pause-fill"></i> <?= __('pause_campaign') ?>
                    </a>
                    <a href="?cancel=<?= $viewCampaign['id'] ?>" class="btn btn-danger"
                       onclick="return confirm('<?= __('confirm_cancel_campaign') ?>')">
                        <i class="bi bi-x-lg"></i> <?= __('cancel_campaign') ?>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($viewCampaign['status'] !== 'running'): ?>
                    <a href="?delete=<?= $viewCampaign['id'] ?>&confirm=1" class="btn btn-outline-danger"
                       onclick="return confirm('<?= __('confirm_delete_campaign') ?>')">
                        <i class="bi bi-trash"></i> <?= __('delete') ?>
                    </a>
                    <?php endif; ?>
                    
                    <button class="btn btn-outline-secondary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> <?= __('refresh_status') ?>
                    </button>
                </div>
                
                <?php if ($viewCampaign['status'] === 'running'): ?>
                <!-- Live sending status -->
                <div class="mt-4" id="liveStatus">
                    <div class="alert alert-info">
                        <i class="bi bi-broadcast-pin me-2"></i>
                        <span id="sendingStatus"><?= __('sending_in_progress') ?></span>
                    </div>
                </div>
                
                <script>
                let sendingActive = true;
                
                async function sendNextMessage() {
                    if (!sendingActive) return;
                    
                    try {
                        const response = await fetch('ajax/campaign_send.php?campaign_id=<?= $viewCampaign['id'] ?>');
                        const data = await response.json();
                        
                        if (data.completed) {
                            document.getElementById('sendingStatus').innerHTML = '<?= __('campaign_completed') ?>';
                            setTimeout(() => location.reload(), 1000);
                            return;
                        }
                        
                        if (data.success) {
                            document.getElementById('sendingStatus').innerHTML = 
                                'Sent to: ' + data.phone + 
                                ' <span class="badge bg-' + (data.status === 'sent' ? 'success' : 'danger') + '">' + 
                                data.status + '</span>';
                            
                            // Schedule next send with delay
                            setTimeout(sendNextMessage, data.delay || 1000);
                        } else {
                            sendingActive = false;
                            document.getElementById('sendingStatus').innerHTML = 
                                '<span class="text-danger">Error: ' + (data.error || 'Unknown') + '</span>';
                        }
                    } catch (err) {
                        console.error('Error:', err);
                        setTimeout(sendNextMessage, 3000);
                    }
                }
                
                // Start sending
                setTimeout(sendNextMessage, 500);
                </script>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Messages List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <?= __('campaign_messages') ?>
                <form method="get" class="d-flex gap-2">
                    <input type="hidden" name="action" value="view">
                    <input type="hidden" name="id" value="<?= $viewCampaign['id'] ?>">
                    <select name="msg_status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <option value=""><?= __('all') ?></option>
                        <option value="pending" <?= ($_GET['msg_status'] ?? '') === 'pending' ? 'selected' : '' ?>><?= __('pending_messages') ?></option>
                        <option value="sent" <?= ($_GET['msg_status'] ?? '') === 'sent' ? 'selected' : '' ?>><?= __('sent_messages') ?></option>
                        <option value="failed" <?= ($_GET['msg_status'] ?? '') === 'failed' ? 'selected' : '' ?>><?= __('failed_messages') ?></option>
                        <option value="delivered" <?= ($_GET['msg_status'] ?? '') === 'delivered' ? 'selected' : '' ?>><?= __('delivered_messages') ?></option>
                    </select>
                </form>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?= __('contact_phone') ?></th>
                            <th><?= __('contact_name') ?></th>
                            <th><?= __('port') ?></th>
                            <th><?= __('status') ?></th>
                            <th><?= __('sent_at') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($campaignMessages['messages'] as $msg): ?>
                        <tr>
                            <td><?= $msg['id'] ?></td>
                            <td><?= htmlspecialchars($msg['phone_number']) ?></td>
                            <td><?= htmlspecialchars($msg['contact_name'] ?: '-') ?></td>
                            <td><?= $msg['port_name'] ?: '-' ?></td>
                            <td>
                                <?php
                                $msgStatusClass = match($msg['status']) {
                                    'pending' => 'bg-secondary',
                                    'sending' => 'bg-info',
                                    'sent' => 'bg-success',
                                    'failed' => 'bg-danger',
                                    'delivered' => 'bg-primary',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?= $msgStatusClass ?>"><?= __('status_' . $msg['status']) ?></span>
                                <?php if ($msg['error_message']): ?>
                                <br><small class="text-danger"><?= htmlspecialchars($msg['error_message']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $msg['sent_at'] ? date('d.m H:i:s', strtotime($msg['sent_at'])) : '-' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($campaignMessages['pages'] > 1): ?>
            <div class="card-footer">
                <nav>
                    <ul class="pagination pagination-sm mb-0 justify-content-center">
                        <?php for ($i = 1; $i <= min(10, $campaignMessages['pages']); $i++): ?>
                        <li class="page-item <?= $i == ($_GET['msg_page'] ?? 1) ? 'active' : '' ?>">
                            <a class="page-link" href="?action=view&id=<?= $viewCampaign['id'] ?>&msg_page=<?= $i ?>&msg_status=<?= $_GET['msg_status'] ?? '' ?>">
                                <?= $i ?>
                            </a>
                        </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Statistics -->
        <div class="card mb-4">
            <div class="card-header"><?= __('campaign_stats') ?></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('total') ?></span>
                        <strong><?= $campaignStats['total'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-hourglass text-secondary me-2"></i><?= __('pending_messages') ?></span>
                        <strong><?= $campaignStats['pending'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-arrow-right-circle text-info me-2"></i><?= __('sending_messages') ?></span>
                        <strong><?= $campaignStats['sending'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-check-circle text-success me-2"></i><?= __('sent_messages') ?></span>
                        <strong><?= $campaignStats['sent'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-x-circle text-danger me-2"></i><?= __('failed_messages') ?></span>
                        <strong><?= $campaignStats['failed'] ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><i class="bi bi-check-all text-primary me-2"></i><?= __('delivered_messages') ?></span>
                        <strong><?= $campaignStats['delivered'] ?></strong>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Campaign Settings -->
        <div class="card">
            <div class="card-header"><?= __('gateway_settings') ?></div>
            <div class="card-body">
                <?php 
                // Get gateway name if set
                $gatewayName = __('all_gateways');
                if ($viewCampaign['gateway_id']) {
                    $gw = $db->fetchOne("SELECT name, type FROM gateways WHERE id = ?", [$viewCampaign['gateway_id']]);
                    if ($gw) {
                        $gatewayName = $gw['name'] . ' (' . strtoupper($gw['type']) . ')';
                    }
                }
                ?>
                <p><strong><?= __('gateway') ?? 'Gateway' ?>:</strong> <?= htmlspecialchars($gatewayName) ?></p>
                <p><strong><?= __('port_mode') ?>:</strong> <?= __('port_' . $viewCampaign['port_mode']) ?></p>
                <?php if ($viewCampaign['port_mode'] === 'specific'): ?>
                <p><strong><?= __('port') ?>:</strong> Port <?= $viewCampaign['specific_port'] ?></p>
                <?php endif; ?>
                <p><strong><?= __('send_delay') ?>:</strong> <?= $viewCampaign['send_delay'] ?> <?= __('delay_ms') ?></p>
                <p><strong><?= __('created_at') ?? 'Created' ?>:</strong> <?= date('d.m.Y H:i', strtotime($viewCampaign['created_at'])) ?></p>
                <?php if ($viewCampaign['started_at']): ?>
                <p><strong><?= __('started_at') ?? 'Started' ?>:</strong> <?= date('d.m.Y H:i', strtotime($viewCampaign['started_at'])) ?></p>
                <?php endif; ?>
                <?php if ($viewCampaign['completed_at']): ?>
                <p><strong><?= __('completed_at') ?? 'Completed' ?>:</strong> <?= date('d.m.Y H:i', strtotime($viewCampaign['completed_at'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<!-- Campaigns List -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= __('campaign_name') ?></th>
                    <th><?= __('progress') ?></th>
                    <th><?= __('status') ?></th>
                    <th><?= __('created_at') ?? 'Created' ?></th>
                    <th style="width:100px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campaigns['campaigns'] as $c): ?>
                <tr>
                    <td><?= $c['id'] ?></td>
                    <td>
                        <a href="?action=view&id=<?= $c['id'] ?>">
                            <strong><?= htmlspecialchars($c['name']) ?></strong>
                        </a>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:8px; min-width:100px">
                                <?php $pct = $c['total_count'] > 0 ? round($c['sent_count'] / $c['total_count'] * 100) : 0; ?>
                                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                            </div>
                            <small class="text-muted"><?= $c['sent_count'] ?>/<?= $c['total_count'] ?></small>
                        </div>
                    </td>
                    <td>
                        <?php
                        $statusClass = match($c['status']) {
                            'draft' => 'bg-secondary',
                            'running' => 'bg-primary',
                            'paused' => 'bg-warning text-dark',
                            'completed' => 'bg-success',
                            'cancelled' => 'bg-danger',
                            default => 'bg-secondary'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= __('status_' . $c['status']) ?></span>
                    </td>
                    <td>
                        <small><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="?action=view&id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="<?= __('view_details') ?>">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($c['status'] !== 'running'): ?>
                            <a href="?delete=<?= $c['id'] ?>&confirm=1" class="btn btn-outline-danger" 
                               onclick="return confirm('<?= __('confirm_delete_campaign') ?>')" title="<?= __('delete') ?>">
                                <i class="bi bi-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($campaigns['campaigns'])): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="bi bi-broadcast fs-1 d-block mb-2"></i>
                        <?= __('no_campaigns') ?>
                        <br>
                        <a href="?action=new" class="btn btn-primary btn-sm mt-2"><?= __('create_first_campaign') ?></a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($campaigns['pages'] > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php for ($i = 1; $i <= $campaigns['pages']; $i++): ?>
                <li class="page-item <?= $i == ($_GET['page'] ?? 1) ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
