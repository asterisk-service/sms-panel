<?php
/**
 * Layout Template with Localization and Auth
 */

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/lang.php';

function renderHeader($title, $active = '') {
    $auth = Auth::getInstance();
    $auth->requireLogin();
    
    $db = Database::getInstance();
    $user = $auth->getUser();
    $isAdmin = $auth->isAdmin();
    
    // Get unread count (filtered by user's allowed ports)
    if ($isAdmin) {
        $unreadCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM inbox WHERE is_read = 0")['cnt'] ?? 0;
    } else {
        $allowedPorts = $auth->getAllowedPorts('can_receive');
        if (empty($allowedPorts)) {
            $unreadCount = 0;
        } else {
            $portNumbers = array_column($allowedPorts, 'port_number');
            $placeholders = implode(',', array_fill(0, count($portNumbers), '?'));
            $unreadCount = $db->fetchOne(
                "SELECT COUNT(*) as cnt FROM inbox WHERE is_read = 0 AND port IN ({$placeholders})",
                $portNumbers
            )['cnt'] ?? 0;
        }
    }
?>
<!DOCTYPE html>
<html lang="<?= $GLOBALS['current_lang'] ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= __('app_name') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 250px;
            --primary-color: #2c3e50;
            --accent-color: #3498db;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f6fa;
        }
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: var(--primary-color); color: #fff;
            z-index: 1000; display: flex; flex-direction: column;
        }
        .sidebar-header {
            padding: 1.5rem; background: rgba(0,0,0,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1); flex-shrink: 0;
        }
        .sidebar-header h4 { margin: 0; font-weight: 600; }
        .sidebar-nav { padding: 1rem 0; flex: 1; overflow-y: auto; }
        .nav-section {
            padding: 0.5rem 1rem 0.25rem; font-size: 0.7rem;
            text-transform: uppercase; letter-spacing: 1px;
            color: rgba(255,255,255,0.4); margin-top: 0.5rem;
        }
        .nav-item { margin: 2px 8px; }
        .nav-link {
            color: rgba(255,255,255,0.7); padding: 0.75rem 1rem;
            border-radius: 8px; display: flex; align-items: center; transition: all 0.2s;
        }
        .nav-link:hover { color: #fff; background: rgba(255,255,255,0.1); }
        .nav-link.active { color: #fff; background: var(--accent-color); }
        .nav-link i { margin-right: 10px; font-size: 1.1rem; width: 24px; text-align: center; }
        .nav-link .badge { margin-left: auto; }
        .main-content { margin-left: var(--sidebar-width); padding: 2rem; min-height: 100vh; }
        .top-bar {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid #e0e0e0;
        }
        .card { border: none; box-shadow: 0 2px 12px rgba(0,0,0,0.08); border-radius: 12px; }
        .card-header { background: #fff; border-bottom: 1px solid #eee; font-weight: 600; padding: 1rem 1.25rem; }
        .stat-card { text-align: center; padding: 1.5rem; }
        .stat-card .icon { font-size: 2.5rem; margin-bottom: 0.5rem; opacity: 0.8; }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
        .stat-card .label { color: #666; font-size: 0.9rem; }
        .table th { border-top: none; font-weight: 600; color: #555; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .message-preview { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .status-badge { padding: 0.35rem 0.65rem; border-radius: 20px; font-size: 0.75rem; font-weight: 500; }
        .char-counter { font-size: 0.85rem; color: #666; }
        .char-counter.warning { color: #e67e22; }
        .char-counter.danger { color: #e74c3c; }
        .unread-row { background: #f0f7ff; font-weight: 500; }
        .btn-group-sm .btn { padding: 0.25rem 0.5rem; }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); transition: transform 0.3s; }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
        }
        .sidebar-footer {
            padding: 1rem; background: rgba(0,0,0,0.2);
            border-top: 1px solid rgba(255,255,255,0.1); flex-shrink: 0;
        }
        .user-info {
            display: flex; align-items: center; padding: 0.75rem 1rem;
            background: rgba(0,0,0,0.15); border-radius: 8px; margin: 0 8px 8px;
        }
        .user-info .avatar {
            width: 36px; height: 36px; background: var(--accent-color);
            border-radius: 50%; display: flex; align-items: center;
            justify-content: center; margin-right: 10px; font-weight: bold;
        }
        .user-info .name { flex: 1; font-weight: 500; font-size: 0.9rem; }
        .user-info .role { font-size: 0.7rem; opacity: 0.7; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h4><i class="bi bi-chat-dots me-2"></i><?= __('app_name') ?></h4>
        </div>
        
        <div class="user-info">
            <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
            <div>
                <div class="name"><?= htmlspecialchars($user['name']) ?></div>
                <div class="role"><?= $isAdmin ? __('role_admin') : __('role_user') ?></div>
            </div>
        </div>
        
        <nav class="sidebar-nav">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a href="index.php" class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2"></i> <?= __('nav_dashboard') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="inbox.php" class="nav-link <?= $active === 'inbox' ? 'active' : '' ?>">
                        <i class="bi bi-inbox"></i> <?= __('nav_inbox') ?>
                        <?php if ($unreadCount > 0): ?>
                        <span class="badge bg-danger"><?= $unreadCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="outbox.php" class="nav-link <?= $active === 'outbox' ? 'active' : '' ?>">
                        <i class="bi bi-send"></i> <?= __('nav_outbox') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="send.php" class="nav-link <?= $active === 'send' ? 'active' : '' ?>">
                        <i class="bi bi-pencil-square"></i> <?= __('nav_send') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="bulk.php" class="nav-link <?= $active === 'bulk' ? 'active' : '' ?>">
                        <i class="bi bi-broadcast"></i> <?= __('bulk_sms') ?>
                    </a>
                </li>
                
                <li class="nav-section"><?= __('nav_data') ?></li>
                <li class="nav-item">
                    <a href="templates.php" class="nav-link <?= $active === 'templates' ? 'active' : '' ?>">
                        <i class="bi bi-file-text"></i> <?= __('nav_templates') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="contacts.php" class="nav-link <?= $active === 'contacts' ? 'active' : '' ?>">
                        <i class="bi bi-person-lines-fill"></i> <?= __('nav_contacts') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="groups.php" class="nav-link <?= $active === 'groups' ? 'active' : '' ?>">
                        <i class="bi bi-people"></i> <?= __('nav_groups') ?>
                    </a>
                </li>
                
                <?php if ($isAdmin): ?>
                <li class="nav-section"><?= __('nav_admin') ?></li>
                <li class="nav-item">
                    <a href="users.php" class="nav-link <?= $active === 'users' ? 'active' : '' ?>">
                        <i class="bi bi-people-fill"></i> <?= __('users') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="gateways.php" class="nav-link <?= $active === 'gateways' ? 'active' : '' ?>">
                        <i class="bi bi-hdd-network"></i> <?= __('gateways') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="ports.php" class="nav-link <?= $active === 'ports' ? 'active' : '' ?>">
                        <i class="bi bi-diagram-3"></i> <?= __('gateway_ports') ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="settings.php" class="nav-link <?= $active === 'settings' ? 'active' : '' ?>">
                        <i class="bi bi-gear"></i> <?= __('nav_settings') ?>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <div class="d-flex justify-content-between align-items-center">
                <?= getLanguageSwitcher() ?>
                <a href="logout.php" class="btn btn-sm btn-outline-light" title="<?= __('logout') ?>">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </div>
    
    <div class="main-content">
<?php
}

function renderFooter() {
?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function updateCharCounter(textarea, counterId) {
            const counter = document.getElementById(counterId);
            if (!counter) return;
            const len = textarea.value.length;
            const parts = Math.ceil(len / 160) || 1;
            counter.textContent = len + ' <?= __('chars') ?>' + (parts > 1 ? ' / ' + parts + ' <?= __('sms_parts') ?>' : '');
            counter.classList.remove('warning', 'danger');
            if (len > 160 && len <= 320) counter.classList.add('warning');
            else if (len > 320) counter.classList.add('danger');
        }
        function confirmDelete(message) {
            return confirm(message || '<?= __('confirm_delete') ?>');
        }
        function showToast(message, type = 'success') {
            const toast = document.createElement('div');
            toast.className = `alert alert-${type} position-fixed top-0 end-0 m-3`;
            toast.style.zIndex = '9999';
            toast.innerHTML = message;
            document.body.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
    </script>
</body>
</html>
<?php
}
