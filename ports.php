<?php
/**
 * Gateway Ports / Порты шлюза
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$db = Database::getInstance();
$sms = new SMS();

$error = '';
$success = '';

// Ensure gateway_ports has gateway_id column
$db->query("ALTER TABLE gateway_ports ADD COLUMN IF NOT EXISTS gateway_id INT NOT NULL DEFAULT 0 AFTER id");

// Handle port update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update';
    
    if ($action === 'generate') {
        // Generate ports for gateway
        $gatewayId = (int)$_POST['gateway_id'];
        $channels = (int)$_POST['channels'];
        
        if ($gatewayId > 0 && $channels > 0) {
            for ($i = 1; $i <= $channels; $i++) {
                $db->query(
                    "INSERT IGNORE INTO gateway_ports (gateway_id, port_number, port_name) VALUES (?, ?, ?)",
                    [$gatewayId, $i, "Port $i"]
                );
            }
            $success = __('ports_generated', ['count' => $channels]);
        }
    } else {
        // Update port
        $portId = (int)$_POST['port_id'];
        $db->update('gateway_ports', [
            'port_name' => $_POST['port_name'] ?? '',
            'sim_number' => $_POST['sim_number'] ?: null,
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ], 'id = ?', [$portId]);
        $success = __('settings_saved');
    }
}

// Handle delete
if (isset($_GET['delete'])) {
    $db->query("DELETE FROM gateway_ports WHERE id = ?", [(int)$_GET['delete']]);
    header('Location: ports.php?success=deleted');
    exit;
}

if (isset($_GET['success'])) {
    $success = __('port_deleted');
}

// Get all gateways with their ports
$gateways = $sms->getGateways(false);
$portsData = [];

foreach ($gateways as $gw) {
    $ports = $db->fetchAll(
        "SELECT * FROM gateway_ports WHERE gateway_id = ? ORDER BY port_number",
        [$gw['id']]
    );
    $portsData[$gw['id']] = [
        'gateway' => $gw,
        'ports' => $ports
    ];
}

renderHeader(__('gateway_ports'), 'ports');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-diagram-3 me-2"></i> <?= __('gateway_ports') ?>
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

<?php if (empty($gateways)): ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle me-2"></i>
    <?= __('no_gateways') ?>. <a href="gateways.php"><?= __('add_gateway') ?></a>
</div>
<?php else: ?>

<?php foreach ($portsData as $gwId => $data): 
    $gw = $data['gateway'];
    $ports = $data['ports'];
?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-hdd-network me-2"></i>
            <strong><?= htmlspecialchars($gw['name']) ?></strong>
            <span class="badge bg-<?= $gw['type'] === 'goip' ? 'info' : 'primary' ?> ms-2">
                <?= strtoupper($gw['type']) ?>
            </span>
            <span class="text-muted ms-2"><?= $gw['channels'] ?> <?= __('channels') ?></span>
            <?php if (!$gw['is_active']): ?>
            <span class="badge bg-secondary ms-2"><?= __('inactive') ?></span>
            <?php endif; ?>
        </div>
        <div>
            <?php if (count($ports) < $gw['channels']): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="gateway_id" value="<?= $gw['id'] ?>">
                <input type="hidden" name="channels" value="<?= $gw['channels'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" 
                        onclick="return confirm('<?= __('generate_ports_confirm', ['count' => $gw['channels']]) ?>')">
                    <i class="bi bi-plus-lg"></i> <?= __('generate_ports') ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    
    <?php if (empty($ports)): ?>
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
        <?= __('no_ports') ?>
        <br>
        <form method="post" class="mt-3">
            <input type="hidden" name="action" value="generate">
            <input type="hidden" name="gateway_id" value="<?= $gw['id'] ?>">
            <input type="hidden" name="channels" value="<?= $gw['channels'] ?>">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> <?= __('generate_ports') ?> (<?= $gw['channels'] ?>)
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th style="width:80px"><?= __('port_number') ?></th>
                    <th><?= __('port_name') ?></th>
                    <th><?= __('sim_number') ?></th>
                    <th style="width:80px"><?= __('status') ?></th>
                    <th style="width:100px"><?= __('sent') ?></th>
                    <th style="width:120px"><?= __('last_used') ?></th>
                    <th style="width:80px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($ports as $p): 
                // Calculate GSM format for OpenVox
                if ($gw['type'] === 'openvox') {
                    $slot = ceil($p['port_number'] / 4);
                    $slotPort = (($p['port_number'] - 1) % 4) + 1;
                    $portFormat = "gsm-{$slot}.{$slotPort}";
                } else {
                    $portFormat = "Line " . $p['port_number'];
                }
            ?>
                <tr class="<?= $p['is_active'] ? '' : 'table-secondary' ?>">
                    <td>
                        <strong><?= $p['port_number'] ?></strong>
                        <br><small class="text-muted"><?= $portFormat ?></small>
                    </td>
                    <td><?= htmlspecialchars($p['port_name']) ?></td>
                    <td>
                        <?php if ($p['sim_number']): ?>
                        <code><?= htmlspecialchars($p['sim_number']) ?></code>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($p['is_active']): ?>
                        <span class="badge bg-success"><?= __('active') ?></span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= __('inactive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($p['messages_sent']) ?></td>
                    <td>
                        <small><?= $p['last_used_at'] ? date('d.m H:i', strtotime($p['last_used_at'])) : '-' ?></small>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-primary edit-port-btn"
                                data-id="<?= $p['id'] ?>"
                                data-port="<?= $p['port_number'] ?>"
                                data-name="<?= htmlspecialchars($p['port_name']) ?>"
                                data-sim="<?= htmlspecialchars($p['sim_number'] ?? '') ?>"
                                data-active="<?= $p['is_active'] ?>"
                                data-gateway="<?= htmlspecialchars($gw['name']) ?>">
                            <i class="bi bi-pencil"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; ?>

<!-- Edit Port Modal -->
<div class="modal fade" id="editPortModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="port_id" id="editPortId">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil me-2"></i>
                        <span id="editPortTitle"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= __('port_name') ?></label>
                        <input type="text" name="port_name" id="editPortName" class="form-control">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('sim_number') ?></label>
                        <input type="text" name="sim_number" id="editPortSim" class="form-control" 
                               placeholder="+79001234567">
                        <small class="text-muted"><?= __('sim_number_hint') ?></small>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="editPortActive" class="form-check-input" value="1">
                        <label class="form-check-label" for="editPortActive"><?= __('port_active') ?></label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i><?= __('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.edit-port-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('editPortTitle').textContent = 
            this.dataset.gateway + ' - Port ' + this.dataset.port;
        document.getElementById('editPortId').value = this.dataset.id;
        document.getElementById('editPortName').value = this.dataset.name;
        document.getElementById('editPortSim').value = this.dataset.sim;
        document.getElementById('editPortActive').checked = this.dataset.active === '1';
        new bootstrap.Modal(document.getElementById('editPortModal')).show();
    });
});
</script>

<?php renderFooter(); ?>
