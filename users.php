<?php
/**
 * User Management (Admin only)
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$auth = Auth::getInstance();
$auth->requireAdmin();

$db = Database::getInstance();
$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';

// Create user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    if (empty($_POST['username']) || empty($_POST['password']) || empty($_POST['name'])) {
        $error = __('required_fields');
    } else {
        $result = $auth->createUser([
            'username' => trim($_POST['username']),
            'password' => $_POST['password'],
            'name' => trim($_POST['name']),
            'email' => trim($_POST['email']) ?: null,
            'role' => $_POST['role'] ?? 'user',
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ]);
        
        if ($result['success']) {
            // Save port permissions
            $ports = [];
            if (!empty($_POST['ports'])) {
                foreach ($_POST['ports'] as $portId) {
                    $port = $db->fetchOne("SELECT gateway_id FROM gateway_ports WHERE id = ?", [$portId]);
                    if ($port) {
                        $ports[] = [
                            'gateway_id' => $port['gateway_id'],
                            'port_id' => $portId,
                            'can_send' => isset($_POST['can_send'][$portId]) ? 1 : 0,
                            'can_receive' => isset($_POST['can_receive'][$portId]) ? 1 : 0
                        ];
                    }
                }
            }
            $auth->setUserPorts($result['user_id'], $ports);
            
            header('Location: users.php?success=created');
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

// Update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $userId = (int)$_POST['user_id'];
    
    $result = $auth->updateUser($userId, [
        'name' => trim($_POST['name']),
        'email' => trim($_POST['email']) ?: null,
        'role' => $_POST['role'] ?? 'user',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'password' => $_POST['password'] ?: null
    ]);
    
    // Update port permissions
    $ports = [];
    if (!empty($_POST['ports'])) {
        foreach ($_POST['ports'] as $portId) {
            $port = $db->fetchOne("SELECT gateway_id FROM gateway_ports WHERE id = ?", [$portId]);
            if ($port) {
                $ports[] = [
                    'gateway_id' => $port['gateway_id'],
                    'port_id' => $portId,
                    'can_send' => isset($_POST['can_send'][$portId]) ? 1 : 0,
                    'can_receive' => isset($_POST['can_receive'][$portId]) ? 1 : 0
                ];
            }
        }
    }
    $auth->setUserPorts($userId, $ports);
    
    header('Location: users.php?success=updated');
    exit;
}

// Delete user
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $result = $auth->deleteUser((int)$_GET['delete']);
    header('Location: users.php?success=deleted');
    exit;
}

// Toggle active
if (isset($_GET['toggle'])) {
    $userId = (int)$_GET['toggle'];
    $user = $auth->getUserById($userId);
    if ($user && $userId != $auth->getUserId()) {
        $auth->updateUser($userId, ['is_active' => $user['is_active'] ? 0 : 1]);
    }
    header('Location: users.php');
    exit;
}

if (isset($_GET['success'])) {
    $success = __('user_' . $_GET['success']);
}

// Get all users
$users = $auth->getAllUsers();

// Get all ports grouped by gateway for permission selection
$allPorts = $db->fetchAll(
    "SELECT gp.*, g.name as gateway_name, g.type as gateway_type
     FROM gateway_ports gp
     LEFT JOIN gateways g ON gp.gateway_id = g.id
     ORDER BY g.name, gp.port_number"
);

// Group ports by gateway
$portsByGateway = [];
foreach ($allPorts as $p) {
    $portsByGateway[$p['gateway_id']]['name'] = $p['gateway_name'];
    $portsByGateway[$p['gateway_id']]['type'] = $p['gateway_type'];
    $portsByGateway[$p['gateway_id']]['ports'][] = $p;
}

// Edit user data
$editUser = null;
$editUserPorts = [];
if ($action === 'edit' && isset($_GET['id'])) {
    $editUser = $auth->getUserById((int)$_GET['id']);
    if ($editUser) {
        $userPorts = $auth->getUserPorts($editUser['id']);
        foreach ($userPorts as $up) {
            $editUserPorts[$up['port_id']] = $up;
        }
    }
}

renderHeader(__('users'), 'users');
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i><?= __('users') ?></h4>
    <a href="?action=add" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> <?= __('add_user') ?>
    </a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($action === 'add' || ($action === 'edit' && $editUser)): ?>
<!-- Add/Edit User Form -->
<div class="card">
    <div class="card-header">
        <i class="bi bi-person-<?= $editUser ? 'gear' : 'plus' ?> me-2"></i>
        <?= $editUser ? __('edit_user') : __('add_user') ?>
    </div>
    <div class="card-body">
        <form method="post">
            <?php if ($editUser): ?>
            <input type="hidden" name="update_user" value="1">
            <input type="hidden" name="user_id" value="<?= $editUser['id'] ?>">
            <?php else: ?>
            <input type="hidden" name="create_user" value="1">
            <?php endif; ?>
            
            <div class="row">
                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="bi bi-person me-2"></i><?= __('user_info') ?></h6>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('username') ?> *</label>
                        <input type="text" name="username" class="form-control" 
                               value="<?= htmlspecialchars($editUser['username'] ?? '') ?>"
                               <?= $editUser ? 'readonly' : 'required' ?>>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('password') ?> <?= $editUser ? '' : '*' ?></label>
                        <input type="password" name="password" class="form-control" <?= $editUser ? '' : 'required' ?>>
                        <?php if ($editUser): ?>
                        <small class="text-muted"><?= __('leave_empty_password') ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('name') ?> *</label>
                        <input type="text" name="name" class="form-control" 
                               value="<?= htmlspecialchars($editUser['name'] ?? '') ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('email') ?></label>
                        <input type="email" name="email" class="form-control" 
                               value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('role') ?></label>
                        <select name="role" class="form-select">
                            <option value="user" <?= ($editUser['role'] ?? '') === 'user' ? 'selected' : '' ?>><?= __('role_user') ?></option>
                            <option value="admin" <?= ($editUser['role'] ?? '') === 'admin' ? 'selected' : '' ?>><?= __('role_admin') ?></option>
                        </select>
                        <small class="text-muted"><?= __('admin_full_access') ?></small>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input type="checkbox" name="is_active" class="form-check-input" value="1" id="isActive"
                               <?= ($editUser['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive"><?= __('user_active') ?></label>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <h6 class="mb-3"><i class="bi bi-shield-check me-2"></i><?= __('port_permissions') ?></h6>
                    <p class="text-muted small"><?= __('select_ports_hint') ?></p>
                    
                    <?php foreach ($portsByGateway as $gwId => $gwData): ?>
                    <div class="card mb-3">
                        <div class="card-header py-2">
                            <strong><?= htmlspecialchars($gwData['name'] ?? 'Gateway') ?></strong>
                            <span class="badge bg-<?= ($gwData['type'] ?? '') === 'goip' ? 'info' : 'primary' ?> ms-1">
                                <?= strtoupper($gwData['type'] ?? 'N/A') ?>
                            </span>
                            <div class="float-end">
                                <a href="#" onclick="toggleAllPorts(<?= $gwId ?>, true); return false;" class="small"><?= __('select_all') ?></a> |
                                <a href="#" onclick="toggleAllPorts(<?= $gwId ?>, false); return false;" class="small"><?= __('deselect_all') ?></a>
                            </div>
                        </div>
                        <div class="card-body py-2">
                            <div class="row">
                                <?php foreach ($gwData['ports'] as $port): 
                                    $hasPerm = isset($editUserPorts[$port['id']]);
                                    $canSend = $hasPerm ? $editUserPorts[$port['id']]['can_send'] : 1;
                                    $canRecv = $hasPerm ? $editUserPorts[$port['id']]['can_receive'] : 1;
                                ?>
                                <div class="col-md-6 col-lg-4 mb-2">
                                    <div class="border rounded p-2 port-item" data-gateway="<?= $gwId ?>">
                                        <div class="form-check">
                                            <input type="checkbox" name="ports[]" value="<?= $port['id'] ?>" 
                                                   class="form-check-input port-check" id="port_<?= $port['id'] ?>"
                                                   data-gateway="<?= $gwId ?>" <?= $hasPerm ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-bold" for="port_<?= $port['id'] ?>">
                                                <?= __('port') ?> <?= $port['port_number'] ?>
                                            </label>
                                        </div>
                                        <div class="ms-4 mt-1 small">
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" name="can_send[<?= $port['id'] ?>]" value="1" 
                                                       class="form-check-input" <?= $canSend ? 'checked' : '' ?>>
                                                <label class="form-check-label"><?= __('send') ?></label>
                                            </div>
                                            <div class="form-check form-check-inline">
                                                <input type="checkbox" name="can_receive[<?= $port['id'] ?>]" value="1" 
                                                       class="form-check-input" <?= $canRecv ? 'checked' : '' ?>>
                                                <label class="form-check-label"><?= __('receive') ?></label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($portsByGateway)): ?>
                    <div class="alert alert-info">
                        <?= __('no_ports_available') ?>
                        <a href="gateways.php"><?= __('add_gateway') ?></a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <hr>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i><?= __('save') ?>
                </button>
                <a href="users.php" class="btn btn-secondary"><?= __('cancel') ?></a>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAllPorts(gatewayId, checked) {
    document.querySelectorAll(`.port-check[data-gateway="${gatewayId}"]`).forEach(cb => {
        cb.checked = checked;
    });
}
</script>

<?php else: ?>
<!-- Users List -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= __('username') ?></th>
                    <th><?= __('name') ?></th>
                    <th><?= __('role') ?></th>
                    <th><?= __('ports_access') ?></th>
                    <th><?= __('status') ?></th>
                    <th><?= __('last_login') ?></th>
                    <th><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr class="<?= $u['is_active'] ? '' : 'table-secondary' ?>">
                    <td><?= $u['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                        <?php if ($u['id'] == $auth->getUserId()): ?>
                        <span class="badge bg-info"><?= __('you') ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['name']) ?></td>
                    <td>
                        <span class="badge bg-<?= $u['role'] === 'admin' ? 'danger' : 'secondary' ?>">
                            <?= __('role_' . $u['role']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($u['role'] === 'admin'): ?>
                        <span class="text-success"><?= __('full_access') ?></span>
                        <?php else: ?>
                        <?= $u['ports_count'] ?> <?= __('ports') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $u['is_active'] ? 'success' : 'secondary' ?>">
                            <?= $u['is_active'] ? __('active') : __('inactive') ?>
                        </span>
                    </td>
                    <td><?= $u['last_login'] ? date('d.m.Y H:i', strtotime($u['last_login'])) : '-' ?></td>
                    <td>
                        <a href="?action=edit&id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" title="<?= __('edit') ?>">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <?php if ($u['id'] != $auth->getUserId()): ?>
                        <a href="?toggle=<?= $u['id'] ?>" class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>" title="<?= $u['is_active'] ? __('deactivate') : __('activate') ?>">
                            <i class="bi bi-<?= $u['is_active'] ? 'pause' : 'play' ?>-fill"></i>
                        </a>
                        <a href="?delete=<?= $u['id'] ?>&confirm=1" class="btn btn-sm btn-outline-danger" 
                           onclick="return confirm('<?= __('confirm_delete') ?>')" title="<?= __('delete') ?>">
                            <i class="bi bi-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
