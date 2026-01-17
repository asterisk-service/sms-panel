<?php
/**
 * Dashboard / Главная
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$db = Database::getInstance();
$sms = new SMS();

// Get statistics
$stats = $sms->getStats();

// Recent inbox
$recentInbox = $db->fetchAll(
    "SELECT * FROM inbox ORDER BY received_at DESC LIMIT 5"
);

// Recent outbox
$recentOutbox = $db->fetchAll(
    "SELECT * FROM outbox ORDER BY sent_at DESC LIMIT 5"
);

renderHeader(__('dashboard'), 'dashboard');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-speedometer2 me-2"></i> <?= __('dashboard') ?>
    </h4>
    <a href="send.php" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> <?= __('nav_send') ?>
    </a>
</div>

<!-- Statistics -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="card stat-card bg-primary text-white">
            <div class="icon"><i class="bi bi-inbox-fill"></i></div>
            <div class="number"><?= number_format($stats['inbox_total']) ?></div>
            <div class="label"><?= __('received_sms') ?></div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card bg-success text-white">
            <div class="icon"><i class="bi bi-send-fill"></i></div>
            <div class="number"><?= number_format($stats['outbox_total']) ?></div>
            <div class="label"><?= __('sent_sms') ?></div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card bg-warning text-dark">
            <div class="icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="number"><?= number_format($stats['pending']) ?></div>
            <div class="label"><?= __('pending_sms') ?></div>
        </div>
    </div>
    <div class="col-md-3 mb-3">
        <div class="card stat-card bg-danger text-white">
            <div class="icon"><i class="bi bi-x-circle-fill"></i></div>
            <div class="number"><?= number_format($stats['failed']) ?></div>
            <div class="label"><?= __('failed_sms') ?></div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header"><?= __('quick_actions') ?></div>
            <div class="card-body">
                <div class="d-flex gap-2 flex-wrap">
                    <a href="send.php" class="btn btn-outline-primary">
                        <i class="bi bi-pencil-square me-1"></i> <?= __('nav_send') ?>
                    </a>
                    <a href="contacts.php?action=add" class="btn btn-outline-secondary">
                        <i class="bi bi-person-plus me-1"></i> <?= __('add_contact') ?>
                    </a>
                    <a href="templates.php?action=add" class="btn btn-outline-secondary">
                        <i class="bi bi-file-plus me-1"></i> <?= __('add_template') ?>
                    </a>
                    <a href="inbox.php?filter=unread" class="btn btn-outline-info">
                        <i class="bi bi-envelope me-1"></i> <?= __('unread') ?>
                        <?php if ($stats['unread'] > 0): ?>
                        <span class="badge bg-danger"><?= $stats['unread'] ?></span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Inbox -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <?= __('recent_inbox') ?>
                <a href="inbox.php" class="btn btn-sm btn-outline-primary"><?= __('view_all') ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentInbox)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    <?= __('no_recent_messages') ?>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <tbody>
                    <?php foreach ($recentInbox as $msg): ?>
                        <tr class="<?= $msg['is_read'] ? '' : 'unread-row' ?>">
                            <td>
                                <strong><?= htmlspecialchars($msg['phone_number']) ?></strong>
                                <br>
                                <small class="text-muted message-preview">
                                    <?= htmlspecialchars(mb_substr($msg['message'], 0, 50)) ?>...
                                </small>
                            </td>
                            <td class="text-end text-muted" style="white-space:nowrap">
                                <small><?= date('d.m H:i', strtotime($msg['received_at'])) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Outbox -->
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <?= __('recent_outbox') ?>
                <a href="outbox.php" class="btn btn-sm btn-outline-primary"><?= __('view_all') ?></a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentOutbox)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-send fs-1 d-block mb-2"></i>
                    <?= __('no_recent_messages') ?>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <tbody>
                    <?php foreach ($recentOutbox as $msg): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($msg['phone_number']) ?></strong>
                                <br>
                                <small class="text-muted message-preview">
                                    <?= htmlspecialchars(mb_substr($msg['message'], 0, 50)) ?>...
                                </small>
                            </td>
                            <td class="text-end" style="white-space:nowrap">
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
                                <br>
                                <small class="text-muted"><?= $msg['sent_at'] ? date('d.m H:i', strtotime($msg['sent_at'])) : date('d.m H:i', strtotime($msg['created_at'])) ?></small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php renderFooter(); ?>
