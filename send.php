<?php
/**
 * Send SMS / Отправить SMS
 */

require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/templates.php';
require_once __DIR__ . '/includes/contacts.php';
require_once __DIR__ . '/includes/campaign.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$sms = new SMS();
$templatesObj = new Templates();
$contactsObj = new Contacts();
$campaignObj = new Campaign();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phones = trim($_POST['phones'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $portMode = $_POST['port_mode'] ?? 'random';
    $specificPort = !empty($_POST['specific_port']) ? (int)$_POST['specific_port'] : null;
    $gatewayId = !empty($_POST['gateway_id']) ? (int)$_POST['gateway_id'] : null;
    
    if (empty($phones)) {
        $error = __('phone_required');
    } elseif (empty($message)) {
        $error = __('message_required');
    } else {
        // Parse phone numbers (comma or newline separated)
        $phoneList = preg_split('/[\s,;]+/', $phones, -1, PREG_SPLIT_NO_EMPTY);
        $phoneList = array_unique(array_filter($phoneList));
        
        // Determine port based on mode
        $port = null;
        if ($portMode === 'specific' && $specificPort) {
            $port = $specificPort;
        } elseif ($portMode === 'linear') {
            // Get next port in sequence
            $port = $sms->getNextLinearPort();
        } elseif ($portMode === 'least_used') {
            // Get least used port
            $port = $sms->getLeastUsedPort();
        }
        // random = null (gateway decides)
        
        if (count($phoneList) === 1) {
            // Single recipient
            $result = $sms->send($phoneList[0], $message, $port, null, $gatewayId);
            if ($result['success']) {
                $success = __('sms_sent_success');
            } else {
                $error = $result['error'] ?? __('sms_send_failed');
            }
        } else {
            // Multiple recipients
            $results = $sms->sendBulkWithPort($phoneList, $message, $portMode, $specificPort, $gatewayId);
            $sent = count(array_filter($results, fn($r) => $r['success']));
            $success = __('sms_sent_to', ['count' => $sent]);
        }
    }
}

// Pre-fill phone from URL
$prefillPhone = $_GET['to'] ?? '';

// Get templates, groups, gateways for selects
$templates = $templatesObj->getAll();
$groups = $contactsObj->getGroups();
$gateways = $sms->getGateways(true);

// Get ports with gateway info
$db = Database::getInstance();
$ports = $db->fetchAll(
    "SELECT gp.*, g.name as gateway_name, g.type as gateway_type 
     FROM gateway_ports gp 
     LEFT JOIN gateways g ON gp.gateway_id = g.id 
     WHERE gp.is_active = 1 
     ORDER BY g.name, gp.port_number"
);

renderHeader(__('send_sms'), 'send');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-pencil-square me-2"></i> <?= __('compose_sms') ?>
    </h4>
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

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form method="post" id="sendForm">
                    <!-- Recipients -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('recipients') ?> *</label>
                        <div class="input-group mb-2">
                            <textarea name="phones" id="phones" class="form-control" rows="2" 
                                      placeholder="<?= __('phone_placeholder') ?>"
                                      required><?= htmlspecialchars($prefillPhone) ?></textarea>
                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" 
                                    data-bs-target="#contactsModal">
                                <i class="bi bi-person-lines-fill"></i>
                            </button>
                        </div>
                        <small class="text-muted"><?= __('multiple_phones_hint') ?></small>
                        
                        <!-- Quick add from group -->
                        <div class="mt-2">
                            <select id="groupSelect" class="form-select form-select-sm" style="max-width:250px">
                                <option value=""><?= __('add_from_group') ?>...</option>
                                <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Template select -->
                    <div class="mb-3">
                        <label class="form-label"><?= __('use_template') ?></label>
                        <select id="templateSelect" class="form-select">
                            <option value=""><?= __('select_template') ?>...</option>
                            <?php foreach ($templates as $t): ?>
                            <option value="<?= htmlspecialchars($t['content']) ?>">
                                <?= htmlspecialchars($t['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Message -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold"><?= __('message_text') ?> *</label>
                        <textarea name="message" id="message" class="form-control" rows="5" 
                                  required oninput="updateCharCounter(this, 'charCounter')"></textarea>
                        <div class="d-flex justify-content-between mt-1">
                            <small id="charCounter" class="char-counter">0 <?= __('chars') ?></small>
                        </div>
                    </div>
                    
                    <!-- Gateway and Port Selection -->
                    <div class="card mb-4">
                        <div class="card-header py-2">
                            <i class="bi bi-hdd-network me-2"></i><?= __('sending_options') ?>
                        </div>
                        <div class="card-body py-3">
                            <!-- Gateway Selection -->
                            <div class="row mb-3">
                                <div class="col-12">
                                    <label class="form-label"><?= __('select_gateway') ?></label>
                                    <select name="gateway_id" class="form-select" id="gatewaySelect" onchange="filterPorts()">
                                        <option value=""><?= __('gateway_auto') ?> (<?= __('default') ?>)</option>
                                        <?php foreach ($gateways as $gw): ?>
                                        <option value="<?= $gw['id'] ?>" data-type="<?= $gw['type'] ?>" data-channels="<?= $gw['channels'] ?>">
                                            <?= htmlspecialchars($gw['name']) ?> 
                                            (<?= strtoupper($gw['type']) ?>, <?= $gw['channels'] ?> <?= __('channels') ?>)
                                            <?= $gw['is_default'] ? '★' : '' ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (empty($gateways)): ?>
                                    <small class="text-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <?= __('no_gateways') ?>. <a href="gateways.php"><?= __('add_gateway') ?></a>
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Port Selection -->
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label"><?= __('port_mode') ?></label>
                                    <select name="port_mode" class="form-select" id="portMode" onchange="togglePortSelect()">
                                        <option value="random"><?= __('port_random') ?></option>
                                        <option value="linear"><?= __('port_linear') ?></option>
                                        <option value="least_used"><?= __('port_least_used') ?? 'Least Used' ?></option>
                                        <option value="specific"><?= __('port_specific') ?></option>
                                    </select>
                                </div>
                                <div class="col-md-6" id="specificPortDiv" style="display:none">
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
                                    <?php if (empty($ports)): ?>
                                    <small class="text-warning">
                                        <i class="bi bi-exclamation-triangle"></i>
                                        <?= __('no_ports') ?>. <a href="ports.php"><?= __('generate_ports') ?></a>
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Anti-spam warning -->
                    <div class="alert alert-info py-2 mb-4">
                        <i class="bi bi-shield-check me-2"></i>
                        <?= __('anti_spam_warning', ['seconds' => SPAM_INTERVAL]) ?>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-send me-2"></i> <?= __('send') ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Sidebar with recent contacts -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><?= __('contacts') ?></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
                    <?php 
                    $recentContacts = $contactsObj->getAll(1, 10)['contacts'];
                    foreach ($recentContacts as $c): 
                    ?>
                    <a href="#" class="list-group-item list-group-item-action add-contact-btn"
                       data-phone="<?= htmlspecialchars($c['phone_number']) ?>">
                        <strong><?= htmlspecialchars($c['name']) ?></strong>
                        <br>
                        <small class="text-muted"><?= htmlspecialchars($c['phone_number']) ?></small>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($recentContacts)): ?>
                    <div class="text-center text-muted py-3">
                        <?= __('no_contacts') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer">
                <a href="contacts.php" class="btn btn-sm btn-outline-primary w-100">
                    <?= __('view_all') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Contacts Modal -->
<div class="modal fade" id="contactsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('select_contact') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="text" id="contactSearch" class="form-control mb-3" 
                       placeholder="<?= __('search_contacts') ?>">
                <div id="contactsList" style="max-height:400px;overflow-y:auto">
                    <!-- Loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle port select visibility
function togglePortSelect() {
    const mode = document.getElementById('portMode').value;
    document.getElementById('specificPortDiv').style.display = mode === 'specific' ? 'block' : 'none';
    if (mode === 'specific') {
        filterPorts();
    }
}

// Filter ports based on selected gateway
function filterPorts() {
    const gatewayId = document.getElementById('gatewaySelect').value;
    const portSelect = document.getElementById('specificPortSelect');
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

// Template select
document.getElementById('templateSelect').addEventListener('change', function() {
    if (this.value) {
        document.getElementById('message').value = this.value;
        updateCharCounter(document.getElementById('message'), 'charCounter');
    }
});

// Add contact from sidebar
document.querySelectorAll('.add-contact-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        const phones = document.getElementById('phones');
        const phone = this.dataset.phone;
        if (phones.value && !phones.value.endsWith('\n')) {
            phones.value += '\n';
        }
        phones.value += phone;
    });
});

// Add from group
document.getElementById('groupSelect').addEventListener('change', function() {
    if (!this.value) return;
    
    fetch('ajax/get_group_phones.php?group_id=' + this.value)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.phones.length > 0) {
                const phones = document.getElementById('phones');
                if (phones.value && !phones.value.endsWith('\n')) {
                    phones.value += '\n';
                }
                phones.value += data.phones.join('\n');
            }
        });
    
    this.value = '';
});

// Contact search in modal
let searchTimeout;
document.getElementById('contactSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value;
    
    if (query.length < 2) {
        document.getElementById('contactsList').innerHTML = '';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('ajax/search_contacts.php?q=' + encodeURIComponent(query))
            .then(r => r.json())
            .then(data => {
                let html = '';
                data.contacts.forEach(c => {
                    html += `<a href="#" class="list-group-item list-group-item-action modal-contact" 
                                data-phone="${c.phone}">
                                <strong>${c.name}</strong> - ${c.phone}
                            </a>`;
                });
                document.getElementById('contactsList').innerHTML = html || '<p class="text-muted p-3"><?= __('no_contacts') ?></p>';
                
                // Bind click events
                document.querySelectorAll('.modal-contact').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const phones = document.getElementById('phones');
                        if (phones.value && !phones.value.endsWith('\n')) {
                            phones.value += '\n';
                        }
                        phones.value += this.dataset.phone;
                    });
                });
            });
    }, 300);
});
</script>

<?php renderFooter(); ?>
