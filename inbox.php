<?php
/**
 * Inbox / Входящие
 */

require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';

$sms = new SMS();
$db = Database::getInstance();

$success = '';

// Handle delete multiple
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query("DELETE FROM inbox WHERE id IN ({$placeholders})", $ids);
        $success = __('messages_deleted', ['count' => count($ids)]);
    }
    if ($_POST['action'] === 'mark_read' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query("UPDATE inbox SET is_read = 1 WHERE id IN ({$placeholders})", $ids);
        $success = __('messages_marked_read');
    }
}

// Handle mark as read (single)
if (isset($_GET['read'])) {
    $sms->markAsRead((int)$_GET['read']);
    
    if (isset($_GET['ajax'])) {
        header('Content-Type: text/plain');
        echo 'OK';
        exit;
    }
    
    header('Location: inbox.php?success=marked');
    exit;
}

// Handle single delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->query("DELETE FROM inbox WHERE id = ?", [$id]);
    header('Location: inbox.php?success=deleted');
    exit;
}

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'marked') {
        $success = __('message_marked_read');
    } elseif ($_GET['success'] === 'deleted') {
        $success = __('message_deleted');
    }
}

require_once __DIR__ . '/templates/layout.php';

// Filters
$page = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? '';

$data = $sms->getInbox($page, 20, $search, $filter === 'unread');

renderHeader(__('inbox'), 'inbox');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-inbox me-2"></i> <?= __('inbox_title') ?>
    </h4>
    <div>
        <button type="button" class="btn btn-secondary me-1" id="markReadBtn" style="display:none" onclick="markReadSelected()">
            <i class="bi bi-check2-all"></i> <?= __('mark_read') ?>
        </button>
        <button type="button" class="btn btn-danger" id="deleteSelectedBtn" style="display:none" onclick="deleteSelected()">
            <i class="bi bi-trash"></i> <?= __('delete_selected') ?>
        </button>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control" 
                           placeholder="<?= __('search_inbox') ?>" 
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <select name="filter" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value=""><?= __('all_messages') ?></option>
                    <option value="unread" <?= $filter === 'unread' ? 'selected' : '' ?>><?= __('unread') ?></option>
                </select>
            </div>
            <?php if ($search || $filter): ?>
            <div class="col-auto">
                <a href="inbox.php" class="btn btn-sm btn-link"><?= __('clear_filters') ?></a>
            </div>
            <?php endif; ?>
            <div class="col-auto ms-auto">
                <small class="text-muted"><?= __('total') ?>: <?= $data['total'] ?></small>
            </div>
        </form>
    </div>
</div>

<!-- Messages Table -->
<form method="post" id="messagesForm">
<input type="hidden" name="action" value="delete" id="formAction">
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:40px">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th><?= __('from') ?></th>
                    <th><?= __('message') ?></th>
                    <th><?= __('port') ?></th>
                    <th><?= __('received_at') ?></th>
                    <th style="width:140px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['messages'] as $msg): ?>
                <tr class="<?= $msg['is_read'] ? '' : 'unread-row' ?>" id="row-<?= $msg['id'] ?>">
                    <td>
                        <input type="checkbox" class="form-check-input msg-checkbox" name="ids[]" 
                               value="<?= $msg['id'] ?>" onchange="updateButtons()">
                    </td>
                    <td>
                        <a href="#" class="text-decoration-none view-message-btn fw-bold" 
                           data-phone="<?= htmlspecialchars($msg['phone_number']) ?>"
                           data-message="<?= htmlspecialchars($msg['message']) ?>"
                           data-time="<?= date('d.m.Y H:i:s', strtotime($msg['received_at'])) ?>"
                           data-port="<?= htmlspecialchars($msg['port_name'] ?: $msg['port']) ?>"
                           data-id="<?= $msg['id'] ?>"
                           data-read="<?= $msg['is_read'] ? '1' : '0' ?>">
                            <?= htmlspecialchars($msg['phone_number']) ?>
                        </a>
                        <?php if (!empty($msg['contact_name'])): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($msg['contact_name']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="#" class="text-decoration-none view-message-btn" 
                           data-phone="<?= htmlspecialchars($msg['phone_number']) ?>"
                           data-message="<?= htmlspecialchars($msg['message']) ?>"
                           data-time="<?= date('d.m.Y H:i:s', strtotime($msg['received_at'])) ?>"
                           data-port="<?= htmlspecialchars($msg['port_name'] ?: $msg['port']) ?>"
                           data-id="<?= $msg['id'] ?>"
                           data-read="<?= $msg['is_read'] ? '1' : '0' ?>">
                            <?= htmlspecialchars(mb_substr($msg['message'], 0, 60)) ?><?= mb_strlen($msg['message']) > 60 ? '...' : '' ?>
                        </a>
                    </td>
                    <td>
                        <small class="text-muted"><?= htmlspecialchars($msg['port_name'] ?: $msg['port'] ?: '-') ?></small>
                    </td>
                    <td>
                        <small><?= date('d.m.Y H:i:s', strtotime($msg['received_at'])) ?></small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-info view-message-btn" title="<?= __('view') ?>"
                                    data-phone="<?= htmlspecialchars($msg['phone_number']) ?>"
                                    data-message="<?= htmlspecialchars($msg['message']) ?>"
                                    data-time="<?= date('d.m.Y H:i:s', strtotime($msg['received_at'])) ?>"
                                    data-port="<?= htmlspecialchars($msg['port_name'] ?: $msg['port']) ?>"
                                    data-id="<?= $msg['id'] ?>"
                                    data-read="<?= $msg['is_read'] ? '1' : '0' ?>">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="send.php?to=<?= urlencode($msg['phone_number']) ?>" 
                               class="btn btn-outline-primary" title="<?= __('reply') ?>">
                                <i class="bi bi-reply"></i>
                            </a>
                            <a href="?delete=<?= $msg['id'] ?>" class="btn btn-outline-danger" 
                               title="<?= __('delete') ?>" onclick="return confirm('<?= __('confirm_delete') ?>')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($data['messages'])): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-5">
                        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                        <?= __('no_messages') ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($data['pages'] > 1): ?>
    <div class="card-footer">
        <nav>
            <ul class="pagination pagination-sm mb-0 justify-content-center">
                <?php for ($i = 1; $i <= $data['pages']; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&filter=<?= $filter ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
</form>

<!-- View Message Modal -->
<div class="modal fade" id="viewMessageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-envelope me-2"></i>
                    <span id="modalPhone"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <small class="text-muted">
                        <i class="bi bi-clock me-1"></i> <span id="modalTime"></span>
                        <span class="ms-3"><i class="bi bi-broadcast me-1"></i> <span id="modalPort"></span></span>
                    </small>
                </div>
                <div class="p-3 bg-light rounded" style="white-space: pre-wrap; word-break: break-word;" id="modalMessage"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('close') ?></button>
                <a href="#" class="btn btn-primary" id="modalReplyBtn">
                    <i class="bi bi-reply me-1"></i> <?= __('reply') ?>
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSelectAll(checkbox) {
    document.querySelectorAll('.msg-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateButtons();
}

function updateButtons() {
    const checked = document.querySelectorAll('.msg-checkbox:checked').length;
    document.getElementById('deleteSelectedBtn').style.display = checked > 0 ? 'inline-block' : 'none';
    document.getElementById('markReadBtn').style.display = checked > 0 ? 'inline-block' : 'none';
    document.getElementById('deleteSelectedBtn').innerHTML = 
        '<i class="bi bi-trash"></i> <?= __('delete') ?> (' + checked + ')';
}

function deleteSelected() {
    const checked = document.querySelectorAll('.msg-checkbox:checked').length;
    if (checked > 0 && confirm('<?= __('confirm_delete_multiple') ?>'.replace(':count', checked))) {
        document.getElementById('formAction').value = 'delete';
        document.getElementById('messagesForm').submit();
    }
}

function markReadSelected() {
    document.getElementById('formAction').value = 'mark_read';
    document.getElementById('messagesForm').submit();
}

document.querySelectorAll('.view-message-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('modalPhone').textContent = this.dataset.phone;
        document.getElementById('modalMessage').textContent = this.dataset.message;
        document.getElementById('modalTime').textContent = this.dataset.time;
        document.getElementById('modalPort').textContent = this.dataset.port || '-';
        document.getElementById('modalReplyBtn').href = 'send.php?to=' + encodeURIComponent(this.dataset.phone);
        
        // Mark as read if not already read
        if (this.dataset.read === '0' && this.dataset.id) {
            fetch('?read=' + this.dataset.id + '&ajax=1');
            const row = document.getElementById('row-' + this.dataset.id);
            if (row) row.classList.remove('unread-row');
            document.querySelectorAll('.view-message-btn[data-id="' + this.dataset.id + '"]').forEach(b => {
                b.dataset.read = '1';
            });
        }
        
        new bootstrap.Modal(document.getElementById('viewMessageModal')).show();
    });
});
</script>

<?php renderFooter(); ?>
