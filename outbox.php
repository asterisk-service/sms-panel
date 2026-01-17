<?php
/**
 * Outbox / Исходящие
 */

require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';

$sms = new SMS();
$db = Database::getInstance();

$success = '';
$error = '';

// Handle delete multiple
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && !empty($_POST['ids'])) {
        $ids = array_map('intval', $_POST['ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $db->query("DELETE FROM outbox WHERE id IN ({$placeholders})", $ids);
        $success = __('messages_deleted', ['count' => count($ids)]);
    }
}

// Handle resend
if (isset($_GET['resend'])) {
    $id = (int)$_GET['resend'];
    $msg = $db->fetchOne("SELECT * FROM outbox WHERE id = ?", [$id]);
    if ($msg) {
        $sms->send($msg['phone_number'], $msg['message']);
        $success = __('message_resent');
    }
    header('Location: outbox.php?success=' . urlencode($success));
    exit;
}

// Handle single delete
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $db->query("DELETE FROM outbox WHERE id = ?", [$id]);
    header('Location: outbox.php?success=' . urlencode(__('message_deleted')));
    exit;
}

if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

require_once __DIR__ . '/templates/layout.php';

// Filters
$page = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

$data = $sms->getOutbox($page, 20, $search, $status);

renderHeader(__('outbox'), 'outbox');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-send me-2"></i> <?= __('outbox_title') ?>
    </h4>
    <div>
        <button type="button" class="btn btn-danger me-2" id="deleteSelectedBtn" style="display:none" onclick="deleteSelected()">
            <i class="bi bi-trash"></i> <?= __('delete_selected') ?>
        </button>
        <a href="send.php" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= __('nav_send') ?>
        </a>
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
                           placeholder="<?= __('search_outbox') ?>" 
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value=""><?= __('filter_status') ?></option>
                    <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>><?= __('status_sent') ?></option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>><?= __('status_pending') ?></option>
                    <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>><?= __('status_failed') ?></option>
                    <option value="delivered" <?= $status === 'delivered' ? 'selected' : '' ?>><?= __('status_delivered') ?></option>
                </select>
            </div>
            <?php if ($search || $status): ?>
            <div class="col-auto">
                <a href="outbox.php" class="btn btn-sm btn-link"><?= __('clear_filters') ?></a>
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
<input type="hidden" name="action" value="delete">
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:40px">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th><?= __('to') ?></th>
                    <th><?= __('message') ?></th>
                    <th><?= __('status') ?></th>
                    <th><?= __('sent_at') ?></th>
                    <th style="width:120px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['messages'] as $msg): ?>
                <tr>
                    <td>
                        <input type="checkbox" class="form-check-input msg-checkbox" name="ids[]" 
                               value="<?= $msg['id'] ?>" onchange="updateDeleteBtn()">
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($msg['phone_number']) ?></strong>
                    </td>
                    <td>
                        <span class="message-preview" title="<?= htmlspecialchars($msg['message']) ?>">
                            <?= htmlspecialchars(mb_substr($msg['message'], 0, 50)) ?><?= mb_strlen($msg['message']) > 50 ? '...' : '' ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $statusClass = match($msg['status']) {
                            'sent' => 'bg-success',
                            'pending' => 'bg-warning text-dark',
                            'failed' => 'bg-danger',
                            'delivered' => 'bg-info',
                            default => 'bg-secondary'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= __('status_' . $msg['status']) ?></span>
                        <?php if (!empty($msg['status_message'])): ?>
                        <br><small class="text-muted" title="<?= htmlspecialchars($msg['status_message']) ?>">
                            <?= htmlspecialchars(mb_substr($msg['status_message'], 0, 30)) ?>
                        </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <small><?= $msg['sent_at'] ? date('d.m.Y H:i:s', strtotime($msg['sent_at'])) : date('d.m.Y H:i:s', strtotime($msg['created_at'])) ?></small>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <?php if ($msg['status'] === 'failed'): ?>
                            <a href="?resend=<?= $msg['id'] ?>" class="btn btn-outline-primary" 
                               title="<?= __('resend') ?>">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                            <?php endif; ?>
                            <a href="send.php?to=<?= urlencode($msg['phone_number']) ?>" 
                               class="btn btn-outline-secondary" title="<?= __('send') ?>">
                                <i class="bi bi-send"></i>
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
                        <i class="bi bi-send fs-1 d-block mb-2"></i>
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
                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>">
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

<script>
function toggleSelectAll(checkbox) {
    document.querySelectorAll('.msg-checkbox').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateDeleteBtn();
}

function updateDeleteBtn() {
    const checked = document.querySelectorAll('.msg-checkbox:checked').length;
    document.getElementById('deleteSelectedBtn').style.display = checked > 0 ? 'inline-block' : 'none';
    document.getElementById('deleteSelectedBtn').innerHTML = 
        '<i class="bi bi-trash"></i> <?= __('delete') ?> (' + checked + ')';
}

function deleteSelected() {
    const checked = document.querySelectorAll('.msg-checkbox:checked').length;
    if (checked > 0 && confirm('<?= __('confirm_delete_multiple') ?>'.replace(':count', checked))) {
        document.getElementById('messagesForm').submit();
    }
}
</script>

<?php renderFooter(); ?>
