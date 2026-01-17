<?php
/**
 * Groups / Группы контактов
 */

require_once __DIR__ . '/includes/contacts.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$contactsObj = new Contacts();
$smsObj = new SMS();
$db = Database::getInstance();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = $_POST['color'] ?? '#3498db';
    $id = (int)($_POST['id'] ?? 0);
    
    if (empty($name)) {
        $error = __('group_name_required');
    } else {
        if ($action === 'edit' && $id) {
            $contactsObj->updateGroup($id, $name, $description, $color);
            $success = __('group_updated');
        } else {
            $contactsObj->createGroup($name, $description, $color);
            $success = __('group_created');
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $id = (int)$_GET['delete'];
    if ($id > 1) { // Don't delete default group
        $contactsObj->deleteGroup($id);
        header('Location: groups.php?success=deleted');
        exit;
    }
}

// Handle bulk SMS to group
if (isset($_POST['send_bulk'])) {
    $groupId = (int)$_POST['group_id'];
    $message = $_POST['message'] ?? '';
    
    if (empty($message)) {
        $error = __('message_required');
    } else {
        $contacts = $contactsObj->getByGroup($groupId);
        if (empty($contacts)) {
            $error = __('no_contacts_in_group');
        } else {
            $phones = array_column($contacts, 'phone_number');
            $results = $smsObj->sendBulk($phones, $message);
            $sent = count(array_filter($results, fn($r) => $r['success']));
            $success = __('sent_to_contacts', ['sent' => $sent, 'total' => count($phones)]);
        }
    }
}

if (isset($_GET['success'])) {
    $success = __('group_deleted');
}

$groups = $contactsObj->getGroups();

// Get contact counts per group
$groupCounts = [];
$counts = $db->fetchAll("SELECT group_id, COUNT(*) as cnt FROM contacts WHERE is_active = 1 GROUP BY group_id");
foreach ($counts as $c) {
    $groupCounts[$c['group_id']] = $c['cnt'];
}

renderHeader(__('groups'), 'groups');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-people me-2"></i> <?= __('contact_groups') ?>
    </h4>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
        <i class="bi bi-plus-lg"></i> <?= __('add_group') ?>
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

<div class="row">
    <?php foreach ($groups as $group): ?>
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center" 
                 style="border-left: 4px solid <?= $group['color'] ?>">
                <h5 class="mb-0"><?= htmlspecialchars($group['name']) ?></h5>
                <span class="badge bg-secondary">
                    <?= __('group_contacts_count', ['count' => $groupCounts[$group['id']] ?? 0]) ?>
                </span>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    <?= htmlspecialchars($group['description'] ?: '-') ?>
                </p>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="contacts.php?group=<?= $group['id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-eye"></i> <?= __('view') ?>
                    </a>
                    <button class="btn btn-sm btn-outline-success send-group-btn" 
                            data-id="<?= $group['id'] ?>" 
                            data-name="<?= htmlspecialchars($group['name']) ?>"
                            data-count="<?= $groupCounts[$group['id']] ?? 0 ?>">
                        <i class="bi bi-send"></i> <?= __('send') ?>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary edit-group-btn"
                            data-id="<?= $group['id'] ?>"
                            data-name="<?= htmlspecialchars($group['name']) ?>"
                            data-description="<?= htmlspecialchars($group['description']) ?>"
                            data-color="<?= $group['color'] ?>">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <?php if ($group['id'] > 1): ?>
                    <a href="?delete=<?= $group['id'] ?>&confirm=1" class="btn btn-sm btn-outline-danger" 
                       onclick="return confirmDelete('<?= __('delete_group_confirm') ?>')">
                        <i class="bi bi-trash"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add/Edit Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title" id="groupModalTitle"><?= __('add_group') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="groupAction" value="add">
                    <input type="hidden" name="id" id="groupId">
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('group_name') ?> *</label>
                        <input type="text" name="name" id="groupName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= __('group_description') ?></label>
                        <textarea name="description" id="groupDescription" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?= __('group_color') ?></label>
                        <input type="color" name="color" id="groupColor" class="form-control form-control-color" value="#3498db">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= __('save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Send SMS to Group Modal -->
<div class="modal fade" id="sendGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><?= __('send_to_group') ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="send_bulk" value="1">
                    <input type="hidden" name="group_id" id="sendGroupId">
                    
                    <div class="alert alert-info py-2">
                        <i class="bi bi-info-circle me-2"></i>
                        <?= __('sending_to_group', ['name' => '<strong id="sendGroupName"></strong>']) ?>
                        (<span id="sendGroupCount"></span> <?= __('contacts') ?>)
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label"><?= __('message_text') ?> *</label>
                        <textarea name="message" class="form-control" rows="4" required
                                  oninput="updateCharCounter(this, 'bulkCharCounter')"></textarea>
                        <small id="bulkCharCounter" class="char-counter">0 <?= __('chars') ?></small>
                    </div>
                    
                    <div class="alert alert-warning py-2">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <?= __('bulk_send_delay') ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i> <?= __('send_to_all') ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit group
document.querySelectorAll('.edit-group-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('groupModalTitle').textContent = '<?= __('edit_group') ?>';
        document.getElementById('groupAction').value = 'edit';
        document.getElementById('groupId').value = this.dataset.id;
        document.getElementById('groupName').value = this.dataset.name;
        document.getElementById('groupDescription').value = this.dataset.description;
        document.getElementById('groupColor').value = this.dataset.color;
        new bootstrap.Modal(document.getElementById('addGroupModal')).show();
    });
});

// Reset modal on close
document.getElementById('addGroupModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('groupModalTitle').textContent = '<?= __('add_group') ?>';
    document.getElementById('groupAction').value = 'add';
    document.getElementById('groupId').value = '';
    document.getElementById('groupName').value = '';
    document.getElementById('groupDescription').value = '';
    document.getElementById('groupColor').value = '#3498db';
});

// Send to group
document.querySelectorAll('.send-group-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        if (parseInt(this.dataset.count) === 0) {
            alert('<?= __('no_contacts_in_group') ?>');
            return;
        }
        document.getElementById('sendGroupId').value = this.dataset.id;
        document.getElementById('sendGroupName').textContent = this.dataset.name;
        document.getElementById('sendGroupCount').textContent = this.dataset.count;
        new bootstrap.Modal(document.getElementById('sendGroupModal')).show();
    });
});
</script>

<?php renderFooter(); ?>
