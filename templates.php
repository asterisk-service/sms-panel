<?php
/**
 * Templates / Шаблоны
 */

require_once __DIR__ . '/includes/templates.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$templatesObj = new Templates();

$error = '';
$success = '';
$action = $_GET['action'] ?? 'list';
$editId = (int)($_GET['id'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $content = trim($_POST['content'] ?? '');
    
    if (empty($name) || empty($content)) {
        $error = __('template_name') . ' ' . __('message_required');
    } else {
        if ($action === 'edit' && $editId) {
            $result = $templatesObj->update($editId, $name, $content);
            if ($result['success']) {
                $success = __('template_updated');
                $action = 'list';
            } else {
                $error = $result['error'];
            }
        } else {
            $result = $templatesObj->create($name, $content);
            if ($result['success']) {
                $success = __('template_created');
                $action = 'list';
            } else {
                $error = $result['error'];
            }
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && isset($_GET['confirm'])) {
    $templatesObj->delete((int)$_GET['delete']);
    header('Location: templates.php?success=deleted');
    exit;
}

// Handle duplicate
if (isset($_GET['duplicate'])) {
    $templatesObj->duplicate((int)$_GET['duplicate']);
    header('Location: templates.php?success=duplicated');
    exit;
}

if (isset($_GET['success'])) {
    $success = $_GET['success'] === 'deleted' ? __('template_deleted') : __('template_duplicated');
}

// Get template for edit
$editTemplate = null;
if ($action === 'edit' && $editId) {
    $editTemplate = $templatesObj->get($editId);
    if (!$editTemplate) {
        $action = 'list';
    }
}

$templates = $templatesObj->getAll();

renderHeader(__('templates'), 'templates');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-file-text me-2"></i> <?= __('templates_title') ?>
    </h4>
    <?php if ($action === 'list'): ?>
    <a href="?action=add" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> <?= __('add_template') ?>
    </a>
    <?php else: ?>
    <a href="templates.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> <?= __('back') ?>
    </a>
    <?php endif; ?>
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
        <?= $action === 'edit' ? __('edit_template') : __('add_template') ?>
    </div>
    <div class="card-body">
        <form method="post">
            <div class="mb-3">
                <label class="form-label fw-semibold"><?= __('template_name') ?> *</label>
                <input type="text" name="name" class="form-control" required
                       value="<?= htmlspecialchars($editTemplate['name'] ?? '') ?>"
                       placeholder="<?= __('template_name') ?>">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold"><?= __('template_text') ?> *</label>
                <textarea name="content" class="form-control" rows="5" required
                          oninput="updateCharCounter(this, 'tplCharCounter')"
                          placeholder="<?= __('variables_hint') ?>"><?= htmlspecialchars($editTemplate['content'] ?? '') ?></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-muted"><?= __('variables_hint') ?></small>
                    <small id="tplCharCounter" class="char-counter">0 <?= __('chars') ?></small>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i> <?= __('save') ?>
                </button>
                <a href="templates.php" class="btn btn-outline-secondary"><?= __('cancel') ?></a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Templates List -->
<div class="row">
    <?php foreach ($templates as $t): ?>
    <div class="col-md-6 col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?= htmlspecialchars($t['name']) ?></strong>
                <span class="badge bg-secondary"><?= $t['usage_count'] ?> <?= __('template_usage') ?></span>
            </div>
            <div class="card-body">
                <p class="card-text"><?= nl2br(htmlspecialchars($t['content'])) ?></p>
                <?php if ($t['variables']): ?>
                <p class="mb-0">
                    <small class="text-muted"><?= __('template_variables') ?>: 
                        <?= htmlspecialchars($t['variables']) ?>
                    </small>
                </p>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <div class="btn-group btn-group-sm">
                    <a href="send.php" class="btn btn-outline-primary" 
                       onclick="localStorage.setItem('template', '<?= htmlspecialchars(addslashes($t['content'])) ?>')">
                        <i class="bi bi-send"></i> <?= __('send') ?>
                    </a>
                    <a href="?action=edit&id=<?= $t['id'] ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-pencil"></i>
                    </a>
                    <a href="?duplicate=<?= $t['id'] ?>" class="btn btn-outline-secondary" 
                       title="<?= __('duplicate_template') ?>">
                        <i class="bi bi-copy"></i>
                    </a>
                    <a href="?delete=<?= $t['id'] ?>&confirm=1" class="btn btn-outline-danger" 
                       onclick="return confirmDelete('<?= __('confirm_delete_template') ?>')">
                        <i class="bi bi-trash"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($templates)): ?>
    <div class="col-12">
        <div class="text-center text-muted py-5">
            <i class="bi bi-file-text fs-1 d-block mb-2"></i>
            <?= __('no_templates') ?>
            <br>
            <a href="?action=add" class="btn btn-primary btn-sm mt-2"><?= __('create_first_template') ?></a>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php renderFooter(); ?>
