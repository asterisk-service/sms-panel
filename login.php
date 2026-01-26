<?php
/**
 * Login Page
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/lang.php';

$auth = Auth::getInstance();

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $auth->login(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? ''
    );
    
    if ($result['success']) {
        header('Location: index.php');
        exit;
    } else {
        $error = __('login_error');
    }
}
?>
<!DOCTYPE html>
<html lang="<?= getCurrentLanguage() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('login') ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { 
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            width: 100%;
            max-width: 400px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .login-header {
            background: #2c3e50;
            color: white;
            text-align: center;
            padding: 2rem;
        }
        .login-header i { font-size: 3rem; }
        .login-body { padding: 2rem; }
        .btn-login {
            background: #3498db;
            border: none;
            padding: 0.75rem;
            font-weight: 600;
        }
        .btn-login:hover { background: #2980b9; }
        .lang-switch {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        .lang-switch a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            padding: 0.25rem 0.5rem;
        }
        .lang-switch a:hover, .lang-switch a.active {
            color: white;
            background: rgba(255,255,255,0.1);
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="lang-switch">
        <a href="?lang=en" class="<?= getCurrentLanguage() === 'en' ? 'active' : '' ?>">EN</a>
        <a href="?lang=ru" class="<?= getCurrentLanguage() === 'ru' ? 'active' : '' ?>">RU</a>
    </div>
    
    <div class="login-box">
        <div class="login-header">
            <i class="bi bi-chat-dots"></i>
            <h4 class="mt-2 mb-0"><?= APP_NAME ?></h4>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
            <div class="alert alert-danger py-2">
                <i class="bi bi-exclamation-circle me-2"></i><?= $error ?>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <div class="mb-3">
                    <label class="form-label"><?= __('username') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" name="username" class="form-control" 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autofocus required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label"><?= __('password') ?></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" name="password" class="form-control" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i><?= __('login') ?>
                </button>
            </form>
            
            <p class="text-center text-muted mt-4 mb-0 small">
                <?= APP_NAME ?> v<?= APP_VERSION ?>
            </p>
        </div>
    </div>
</body>
</html>
