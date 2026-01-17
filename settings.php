<?php
/**
 * Settings / Настройки
 */

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sms.php';
require_once __DIR__ . '/includes/lang.php';
require_once __DIR__ . '/templates/layout.php';

$db = Database::getInstance();
$sms = new SMS();

$error = '';
$success = '';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Save settings
    $settings = [
        'spam_interval' => (int)($_POST['spam_interval'] ?? 60)
    ];
    
    foreach ($settings as $key => $value) {
        $db->query(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
             ON DUPLICATE KEY UPDATE setting_value = ?",
            [$key, $value, $value]
        );
    }
    
    $success = __('settings_saved');
}

// Get current settings
$settingsData = $db->fetchAll("SELECT setting_key, setting_value FROM settings");
$settings = [];
foreach ($settingsData as $s) {
    $settings[$s['setting_key']] = $s['setting_value'];
}

// Get stats
$stats = [
    'inbox' => $db->fetchOne("SELECT COUNT(*) as cnt FROM inbox")['cnt'],
    'outbox' => $db->fetchOne("SELECT COUNT(*) as cnt FROM outbox")['cnt'],
    'contacts' => $db->fetchOne("SELECT COUNT(*) as cnt FROM contacts WHERE is_active = 1")['cnt'],
    'templates' => $db->fetchOne("SELECT COUNT(*) as cnt FROM templates WHERE is_active = 1")['cnt'],
    'gateways' => count($sms->getGateways(false)),
];

// Get receive URL
$serverUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$receiveUrl = $serverUrl . dirname($_SERVER['REQUEST_URI']) . '/api/receive.php';

// Get gateways for display
$gateways = $sms->getGateways(false);
$defaultGateway = $sms->getDefaultGateway();

renderHeader(__('settings'), 'settings');
?>

<div class="top-bar">
    <h4 class="mb-0">
        <i class="bi bi-gear me-2"></i> <?= __('settings') ?>
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

<div class="row">
    <div class="col-lg-8">
        <!-- Gateways Quick View -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-hdd-network me-2"></i> <?= __('gateways') ?></span>
                <a href="gateways.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-gear"></i> <?= __('manage') ?>
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($gateways)): ?>
                <div class="text-center text-muted py-4">
                    <i class="bi bi-hdd-network fs-1 d-block mb-2"></i>
                    <?= __('no_gateways') ?>
                    <br>
                    <a href="gateways.php" class="btn btn-primary mt-3">
                        <i class="bi bi-plus-lg"></i> <?= __('add_gateway') ?>
                    </a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th><?= __('name') ?></th>
                                <th><?= __('gateway_type') ?></th>
                                <th><?= __('host') ?></th>
                                <th><?= __('status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($gateways, 0, 5) as $gw): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($gw['name']) ?>
                                    <?php if ($gw['is_default']): ?>
                                    <i class="bi bi-star-fill text-warning" title="<?= __('default') ?>"></i>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $gw['type'] === 'goip' ? 'info' : 'primary' ?>">
                                        <?= strtoupper($gw['type']) ?>
                                    </span>
                                </td>
                                <td><code><?= htmlspecialchars($gw['host']) ?></code></td>
                                <td>
                                    <?php if ($gw['is_active']): ?>
                                    <span class="badge bg-success"><?= __('active') ?></span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary"><?= __('inactive') ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($gateways) > 5): ?>
                <div class="text-center mt-2">
                    <a href="gateways.php"><?= __('view_all') ?> (<?= count($gateways) ?>)</a>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Anti-Spam Settings -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-shield-check me-2"></i> <?= __('anti_spam_settings') ?>
            </div>
            <div class="card-body">
                <form method="post">
                    <div class="mb-3">
                        <label class="form-label"><?= __('spam_interval') ?></label>
                        <input type="number" name="spam_interval" class="form-control" style="max-width:200px"
                               value="<?= (int)($settings['spam_interval'] ?? SPAM_INTERVAL) ?>"
                               min="0" max="3600">
                        <small class="text-muted"><?= __('spam_interval_hint') ?></small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i> <?= __('save') ?>
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Receive SMS Configuration -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-inbox me-2"></i> <?= __('receive_sms_config') ?>
            </div>
            <div class="card-body">
                <p><?= __('receive_url_hint') ?>:</p>
                
                <div class="input-group mb-3">
                    <input type="text" class="form-control" value="<?= htmlspecialchars($receiveUrl) ?>" readonly id="receiveUrl">
                    <button class="btn btn-outline-secondary" type="button" onclick="copyUrl()">
                        <i class="bi bi-clipboard"></i> <?= __('copy') ?>
                    </button>
                </div>
                
                <div class="accordion" id="configAccordion">
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#openvoxConfig">
                                OpenVox <?= __('gateway_config_steps') ?>
                            </button>
                        </h2>
                        <div id="openvoxConfig" class="accordion-collapse collapse" data-bs-parent="#configAccordion">
                            <div class="accordion-body small">
                                <ol class="mb-0">
                                    <li><?= __('gateway_step_1') ?></li>
                                    <li><?= __('gateway_step_2') ?></li>
                                    <li><?= __('gateway_step_3') ?>:
                                        <code>phonenumber=${phonenumber}&amp;port=${port}&amp;message=${message}&amp;time=${time}</code>
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#goipConfig">
                                GoIP <?= __('gateway_config_steps') ?>
                            </button>
                        </h2>
                        <div id="goipConfig" class="accordion-collapse collapse" data-bs-parent="#configAccordion">
                            <div class="accordion-body small">
                                <ol class="mb-0">
                                    <li>GoIP Web → SMS → SMS Forwarding → SMS to HTTP</li>
                                    <li>Enable: ✓</li>
                                    <li>URL: <code><?= htmlspecialchars($receiveUrl) ?></code></li>
                                    <li>Parameters: <code>srcnum=${srcnum}&amp;msg=${msg}&amp;line=${line}&amp;time=${time}</code></li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- System Stats -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-bar-chart me-2"></i> <?= __('system_stats') ?>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('gateways') ?></span>
                        <strong><?= number_format($stats['gateways']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('total_received') ?></span>
                        <strong><?= number_format($stats['inbox']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('total_sent') ?></span>
                        <strong><?= number_format($stats['outbox']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('contacts') ?></span>
                        <strong><?= number_format($stats['contacts']) ?></strong>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('templates') ?></span>
                        <strong><?= number_format($stats['templates']) ?></strong>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- System Info -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i> <?= __('system_info') ?>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('php_version') ?></span>
                        <span><?= phpversion() ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('server_time') ?></span>
                        <span><?= date('Y-m-d H:i:s') ?></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><?= __('timezone') ?></span>
                        <span><?= date_default_timezone_get() ?></span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function copyUrl() {
    const input = document.getElementById('receiveUrl');
    input.select();
    document.execCommand('copy');
    alert('<?= __('copied') ?>');
}
</script>

<?php renderFooter(); ?>
