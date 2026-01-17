<?php
/**
 * Contacts / Телефонная книга
 */

require_once __DIR__ . '/includes/contacts.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$contactsObj = new Contacts();

$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// Handle export
if (isset($_GET['export'])) {
    $format = $_GET['export'];
    $groupId = $_GET['group'] ?? null;
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=contacts_' . date('Y-m-d') . '.csv');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM
        echo $contactsObj->exportCSV($groupId);
        exit;
    } elseif ($format === 'vcf') {
        header('Content-Type: text/vcard; charset=utf-8');
        header('Content-Disposition: attachment; filename=contacts_' . date('Y-m-d') . '.vcf');
        echo $contactsObj->exportVCard($groupId);
        exit;
    }
}

// Handle import
if (isset($_FILES['import_file']) && $_FILES['import_file']['error'] === 0) {
    $file = $_FILES['import_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $tmpPath = '/tmp/import_' . uniqid() . '.' . $ext;
    move_uploaded_file($file['tmp_name'], $tmpPath);
    
    if ($ext === 'csv') {
        $result = $contactsObj->importCSV($tmpPath);
    } elseif ($ext === 'vcf') {
        $result = $contactsObj->importVCard($tmpPath);
    } else {
        $error = __('supported_formats');
        $result = null;
    }
    
    unlink($tmpPath);
    
    if ($result && $result['success']) {
        $success = __('import_success', ['imported' => $result['imported'], 'skipped' => $result['skipped']]);
    } elseif ($result) {
        $error = $result['error'] ?? __('import_failed');
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['import_file'])) {
    $data = [
        'name' => trim($_POST['name'] ?? ''),
        'phone_number' => trim($_POST['phone_number'] ?? ''),
        'company' => trim($_POST['company'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'notes' => trim($_POST['notes'] ?? ''),
        'group_id' => (int)($_POST['group_id'] ?? 1)
    ];
    
    if (empty($data['name']) || empty($data['phone_number'])) {
        $error = __('name_phone_required');
    } else {
        if ($action === 'edit' && $editId) {
            $result = $contactsObj->update($editId, $data);
        } else {
            $result = $contactsObj->create($data);
        }
        
        if ($result['success']) {
            $success = $action === 'edit' ? __('contact_updated') : __('contact_created');
            $action = 'list';
        } else {
            $error = $result['error'] ?? __('error_occurred');
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $contactsObj->delete((int)$_GET['delete']);
    header('Location: contacts.php?success=deleted');
    exit;
}

if (isset($_GET['success'])) {
    $success = __('contact_deleted');
}

// Get contact for edit
$editContact = null;
if ($action === 'edit' && $editId) {
    $editContact = $contactsObj->get($editId);
    if (!$editContact) {
        $action = 'list';
    }
}

// Get contacts list
$page = max(1, (int)($_GET['page'] ?? 1));
$search = $_GET['search'] ?? '';
$groupFilter = $_GET['group'] ?? null;
$data = $contactsObj->getAll($page, 20, $search, $groupFilter);
$groups = $contactsObj->getGroups();

renderHeader(__('phonebook'), 'contacts');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-person-lines-fill me-2"></i> <?= __('phonebook') ?>
    </h4>
    <div class="d-flex gap-2">
        <?php if ($action === 'list'): ?>
        <div class="dropdown">
            <button class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="bi bi-download"></i> <?= __('export') ?>
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="?export=csv"><?= __('export_csv') ?></a></li>
                <li><a class="dropdown-item" href="?export=vcf"><?= __('export_vcard') ?></a></li>
            </ul>
        </div>
        <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-upload"></i> <?= __('import') ?>
        </button>
        <a href="?action=add" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> <?= __('add_contact') ?>
        </a>
        <?php else: ?>
        <a href="contacts.php" class="btn btn-outline-secondary">
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

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- Add/Edit Form -->
<div class="card">
    <div class="card-header">
        <?= $action === 'edit' ? __('edit_contact') : __('add_contact') ?>
    </div>
    <div class="card-body">
        <form method="post">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold"><?= __('contact_name') ?> *</label>
                    <input type="text" name="name" class="form-control" required
                           value="<?= htmlspecialchars($editContact['name'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label fw-semibold"><?= __('contact_phone') ?> *</label>
                    <input type="text" name="phone_number" class="form-control" required
                           value="<?= htmlspecialchars($editContact['phone_number'] ?? '') ?>"
                           placeholder="<?= __('phone_placeholder') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?= __('contact_company') ?></label>
                    <input type="text" name="company" class="form-control"
                           value="<?= htmlspecialchars($editContact['company'] ?? '') ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?= __('contact_email') ?></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($editContact['email'] ?? '') ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?= __('contact_group') ?></label>
                    <select name="group_id" class="form-select">
                        <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>" <?= ($editContact['group_id'] ?? 1) == $g['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label"><?= __('contact_notes') ?></label>
                    <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($editContact['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> 
                    <?= $action === 'edit' ? __('save') : __('add_contact') ?>
                </button>
                <a href="contacts.php" class="btn btn-outline-secondary"><?= __('cancel') ?></a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Filters -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="get" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group input-group-sm">
                    <input type="text" name="search" class="form-control" 
                           placeholder="<?= __('search_contacts') ?>" 
                           value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div class="col-md-3">
                <select name="group" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value=""><?= __('all_groups') ?></option>
                    <?php foreach ($groups as $g): ?>
                    <option value="<?= $g['id'] ?>" <?= $groupFilter == $g['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($g['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($search || $groupFilter): ?>
            <div class="col-auto">
                <a href="contacts.php" class="btn btn-sm btn-link"><?= __('clear_filters') ?></a>
            </div>
            <?php endif; ?>
            <div class="col-auto ms-auto">
                <small class="text-muted"><?= __('contacts_count', ['count' => $data['total']]) ?></small>
            </div>
        </form>
    </div>
</div>

<!-- Contacts Table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th><?= __('contact_name') ?></th>
                    <th><?= __('contact_phone') ?></th>
                    <th><?= __('contact_company') ?></th>
                    <th><?= __('contact_group') ?></th>
                    <th style="width:150px"><?= __('actions') ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($data['contacts'] as $c): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($c['name']) ?></strong>
                        <?php if ($c['email']): ?>
                        <br><small class="text-muted"><?= htmlspecialchars($c['email']) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c['phone_number']) ?></td>
                    <td><?= htmlspecialchars($c['company'] ?: '-') ?></td>
                    <td>
                        <?php if ($c['group_name']): ?>
                        <span class="badge" style="background:<?= $c['group_color'] ?>">
                            <?= htmlspecialchars($c['group_name']) ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <a href="send.php?to=<?= urlencode($c['phone_number']) ?>" 
                               class="btn btn-outline-primary" title="<?= __('send_sms') ?>">
                                <i class="bi bi-send"></i>
                            </a>
                            <a href="?action=edit&id=<?= $c['id'] ?>" 
                               class="btn btn-outline-secondary" title="<?= __('edit') ?>">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <a href="?delete=<?= $c['id'] ?>&confirm=1" 
                               class="btn btn-outline-danger" title="<?= __('delete') ?>" 
                               onclick="return confirmDelete('<?= __('confirm_delete_contact') ?>')">
                                <i class="bi bi-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($data['contacts'])): ?>
                <tr>
                    <td colspan="5" class="text-center text-muted py-5">
                        <i class="bi bi-person-lines-fill fs-1 d-block mb-2"></i>
                        <?= __('no_contacts') ?>
                        <br>
                        <a href="?action=add" class="btn btn-primary btn-sm mt-2"><?= __('add_first_contact') ?></a>
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
                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?><?= $groupFilter ? "&group=$groupFilter" : '' ?>">
                        <?= $i ?>
                    </a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= __('import_contacts') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?= __('select_file') ?></label>
                        <input type="file" name="import_file" class="form-control" accept=".csv,.vcf" required>
                        <small class="text-muted"><?= __('supported_formats') ?></small>
                    </div>
                    <div class="alert alert-info py-2">
                        <strong><?= __('csv_format') ?>:</strong><br>
                        <code><?= __('csv_format_hint') ?></code>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= __('import') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
