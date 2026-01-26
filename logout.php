<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
Auth::getInstance()->logout();
header('Location: login.php');
exit;
