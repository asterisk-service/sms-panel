<?php
/**
 * Gateways Management / Управление шлюзами
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$db = Database::getInstance();

$error = '';
$success = '';
$testResult = null;
$editGateway = null;

// Ensure gateways table exists
$db->query("CREATE TABLE IF NOT EXISTS `gateways` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `type` ENUM('openvox', 'goip') NOT NULL DEFAULT 'openvox',
    `host` VARCHAR(100) NOT NULL,
    `port` INT DEFAULT 80,
    `username` VARCHAR(100) DEFAULT NULL,
    `password` VARCHAR(100) DEFAULT NULL,
    `channels` INT DEFAULT 8,
    `is_active` TINYINT(1) DEFAULT 1,
    `is_default` TINYINT(1) DEFAULT 0,
    `priority` INT DEFAULT 0,
    `messages_sent` INT DEFAULT 0,
    `last_used_at` DATETIME DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $data = [
            'name' => trim($_POST['name'] ?? ''),
            'type' => $_POST['type'] ?? 'openvox',
            'host' => trim($_POST['host'] ?? ''),
            'port' => (int)($_POST['port'] ?? 80),
            'username' => trim($_POST['username'] ?? ''),
            'password' => trim($_POST['password'] ?? ''),
            'channels' => (int)($_POST['channels'] ?? 8),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'priority' => (int)($_POST['priority'] ?? 0)
        ];
        
        if (empty($data['name']) || empty($data['host'])) {
            $error = __('fill_required_fields');
        } else {
            // If setting as default, unset others
            if ($data['is_default']) {
                $db->query("UPDATE gateways SET is_default = 0");
            }
            
            if ($id > 0) {
                $db->update('gateways', $data, 'id = ?', [$id]);
                $success = __('gateway_updated');
            } else {
                $db->insert('gateways', $data);
                $success = __('gateway_added');
            }
        }
    }
    
    if ($action === 'test') {
        $type = $_POST['type'] ?? 'openvox';
        $host = $_POST['host'] ?? '';
        $port = $_POST['port'] ?? 80;
        $user = $_POST['username'] ?? '';
        $pass = $_POST['password'] ?? '';
        
        if ($type === 'goip') {
            $url = "http://{$host}:{$port}/default/en_US/status.xml";
        } else {
            $url = "http://{$host}:{$port}/";
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $testResult = ['success' => false, 'message' => __('connection_failed', ['error' => $curlError])];
        } elseif ($httpCode == 200 || $httpCode == 401 || $httpCode == 302) {
            $testResult = ['success' => true, 'message' => __('connection_success', ['code' => $httpCode])];
        } else {
            $testResult = ['success' => false, 'message' => __('connection_failed', ['error' => "HTTP $httpCode"])];
        }
        
        // Keep form data for re-display
        $editGateway = [
            'id' => $_POST['id'] ?? 0,
            'name' => $_POST['name'] ?? '',
            'type' => $type,
            'host' => $host,
            'port' => $port,
            'username' => $user,
            'password' => $pass,
            'channels' => $_POST['channels'] ?? 8,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'is_default' => isset($_POST['is_default']) ? 1 : 0,
            'priority' => $_POST['priority'] ?? 0
        ];
    }
}

// Handle GET actions
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->query("DELETE FROM gateways WHERE id = ?", [$id]);
    header('Location: gateways.php?success=deleted');
    exit;
}

if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $db->query("UPDATE gateways SET is_active = NOT is_active WHERE id = ?", [$id]);
    header('Location: gateways.php');
    exit;
}

if (isset($_GET['default'])) {
    $id = (int)$_GET['default'];
    $db->query("UPDATE gateways SET is_default = 0");
    $db->query("UPDATE gateways SET is_default = 1 WHERE id = ?", [$id]);
    header('Location: gateways.php?success=default');
    exit;
}

if (isset($_GET['edit'])) {
    $editGateway = $db->fetchOne("SELECT * FROM gateways WHERE id = ?", [(int)$_GET['edit']]);
}

if (isset($_GET['success'])) {
    $success = $_GET['success'] === 'deleted' ? __('gateway_deleted') : __('gateway_default_set');
}

// Get all gateways
$gateways = $db->fetchAll("SELECT * FROM gateways ORDER BY priority DESC, name ASC");

renderHeader(__('gateways'), 'gateways');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-hdd-network me-2"></i> <?= __('gateways') ?>
    </h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#gatewayModal" onclick="resetForm()">
        <i class="bi bi-plus-lg"></i> <?= __('add_gateway') ?>
    </button>
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

<?php if ($testResult): ?>
<div class="alert alert-<?= $testResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show">
    <i class="bi bi-<?= $testResult['success'] ? 'check' : 'x' ?>-circle me-2"></i>
    <?= htmlspecialchars($testResult['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Gateways Table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th><?= __('name') ?></th>
                    <th><?= __('gateway_type') ?></th>
                    <th><?= __('host') ?></th>
                    <th><?= __('channels') ?></th>
                    <th><?= __('sent') ?></th>
                    <th><?= __('status') ?></th>
                    <th style="width:150px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($gateways as $gw): ?>
                <tr class="<?= !$gw['is_active'] ? 'table-secondary' : '' ?>">
                    <td>
                        <?php if ($gw['is_default']): ?>
                        <i class="bi bi-star-fill text-warning" title="<?= __('default') ?>"></i>
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($gw['name']) ?></strong>
                        <?php if ($gw['priority'] > 0): ?>
                        <br><small class="text-muted"><?= __('priority') ?>: <?= $gw['priority'] ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $gw['type'] === 'goip' ? 'info' : 'primary' ?>">
                            <?= strtoupper($gw['type']) ?>
                        </span>
                    </td>
                    <td>
                        <code><?= htmlspecialchars($gw['host']) ?>:<?= $gw['port'] ?></code>
                    </td>
                    <td><?= $gw['channels'] ?></td>
                    <td>
                        <?= number_format($gw['messages_sent']) ?>
                        <?php if ($gw['last_used_at']): ?>
                        <br><small class="text-muted"><?= date('d.m H:i', strtotime($gw['last_used_at'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($gw['is_active']): ?>
                        <span class="badge bg-success"><?= __('active') ?></span>
                        <?php else: ?>
                        <span class="badge bg-secondary"><?= __('inactive') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="?edit=<?= $gw['id'] ?>" class="btn btn-outline-primary" 
                               data-bs-toggle="modal" data-bs-target="#gatewayModal"
                               onclick="editGateway(<?= htmlspecialchars(json_encode($gw)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="?toggle=<?= $gw['id'] ?>" class="btn btn-outline-<?= $gw['is_active'] ? 'warning' : 'success' ?>"
                               title="<?= $gw['is_active'] ? __('deactivate') : __('activate') ?>">
                                <i class="bi bi-<?= $gw['is_active'] ? 'pause' : 'play' ?>"></i>
                            </a>
                            <?php if (!$gw['is_default']): ?>
                            <a href="?default=<?= $gw['id'] ?>" class="btn btn-outline-warning" title="<?= __('set_default') ?>">
                                <i class="bi bi-star"></i>
                            </a>
                            <?php endif; ?>
                            <a href="?delete=<?= $gw['id'] ?>" class="btn btn-outline-danger" 
                               onclick="return confirm('<?= __('confirm_delete') ?>')" title="<?= __('delete') ?>">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($gateways)): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-hdd-network fs-1 d-block mb-2"></i>
                        <?= __('no_gateways') ?>
                        <br><small><?= __('add_first_gateway') ?></small>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Gateway Modal -->
<div class="modal fade" id="gatewayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="gatewayForm">
                <input type="hidden" name="action" value="save" id="formAction">
                <input type="hidden" name="id" id="gwId" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-hdd-network me-2"></i>
                        <span id="modalTitle"><?= __('add_gateway') ?></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label"><?= __('gateway_name') ?> *</label>
                            <input type="text" name="name" id="gwName" class="form-control" required 
                                   placeholder="<?= __('gateway_name_hint') ?>">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('gateway_type') ?></label>
                            <select name="type" id="gwType" class="form-select" onchange="updateTypeHint()">
                                <option value="openvox">OpenVox</option>
                                <option value="goip">GoIP</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('gateway_ip') ?> *</label>
                            <input type="text" name="host" id="gwHost" class="form-control" required
                                   placeholder="192.168.1.100">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><?= __('gateway_port') ?></label>
                            <input type="number" name="port" id="gwPort" class="form-control" value="80">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label"><?= __('channels') ?></label>
                            <input type="number" name="channels" id="gwChannels" class="form-control" value="8" min="1" max="64">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('gateway_user') ?></label>
                            <input type="text" name="username" id="gwUsername" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label"><?= __('gateway_pass') ?></label>
                            <input type="password" name="password" id="gwPassword" class="form-control">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label"><?= __('priority') ?></label>
                            <input type="number" name="priority" id="gwPriority" class="form-control" value="0" min="0">
                            <small class="text-muted"><?= __('priority_hint') ?></small>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_active" id="gwActive" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="gwActive"><?= __('active') ?></label>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="checkbox" name="is_default" id="gwDefault" class="form-check-input" value="1">
                                <label class="form-check-label" for="gwDefault"><?= __('set_default') ?></label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="alert alert-info py-2" id="typeHint">
                        <strong>OpenVox:</strong> VS-GW1202, VS-GW1600, SWG-2008
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" onclick="testConnection()">
                        <i class="bi bi-wifi me-1"></i> <?= __('test_connection') ?>
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> <?= __('save') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('gwId').value = 0;
    document.getElementById('gwName').value = '';
    document.getElementById('gwType').value = 'openvox';
    document.getElementById('gwHost').value = '';
    document.getElementById('gwPort').value = '80';
    document.getElementById('gwUsername').value = '';
    document.getElementById('gwPassword').value = '';
    document.getElementById('gwChannels').value = '8';
    document.getElementById('gwPriority').value = '0';
    document.getElementById('gwActive').checked = true;
    document.getElementById('gwDefault').checked = false;
    document.getElementById('modalTitle').textContent = '<?= __('add_gateway') ?>';
    document.getElementById('formAction').value = 'save';
    updateTypeHint();
}

function editGateway(gw) {
    document.getElementById('gwId').value = gw.id;
    document.getElementById('gwName').value = gw.name;
    document.getElementById('gwType').value = gw.type;
    document.getElementById('gwHost').value = gw.host;
    document.getElementById('gwPort').value = gw.port;
    document.getElementById('gwUsername').value = gw.username || '';
    document.getElementById('gwPassword').value = gw.password || '';
    document.getElementById('gwChannels').value = gw.channels;
    document.getElementById('gwPriority').value = gw.priority;
    document.getElementById('gwActive').checked = gw.is_active == 1;
    document.getElementById('gwDefault').checked = gw.is_default == 1;
    document.getElementById('modalTitle').textContent = '<?= __('edit_gateway') ?>';
    document.getElementById('formAction').value = 'save';
    updateTypeHint();
}

function updateTypeHint() {
    const type = document.getElementById('gwType').value;
    const hint = document.getElementById('typeHint');
    if (type === 'goip') {
        hint.innerHTML = '<strong>GoIP:</strong> GoIP-1, GoIP-4, GoIP-8, GoIP-16, GoIP-32';
    } else {
        hint.innerHTML = '<strong>OpenVox:</strong> VS-GW1202, VS-GW1600, VS-GW2120, SWG-2008';
    }
}

function testConnection() {
    document.getElementById('formAction').value = 'test';
    document.getElementById('gatewayForm').submit();
}

<?php if ($editGateway): ?>
// Auto-open modal for edit
document.addEventListener('DOMContentLoaded', function() {
    editGateway(<?= json_encode($editGateway) ?>);
    new bootstrap.Modal(document.getElementById('gatewayModal')).show();
});
<?php endif; ?>
</script>

<?php renderFooter(); ?>
