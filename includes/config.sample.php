<?php
/**
 * SMS Panel Configuration
 * 
 * Copy this file to config.php and edit your settings
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'sms_panel');
define('DB_PASS', 'your_password_here');
define('DB_NAME', 'sms_panel');

// Anti-Spam Configuration
define('SPAM_INTERVAL', 60); // seconds between duplicate messages

// Application Settings
define('APP_NAME', 'SMS Panel');
define('APP_VERSION', '1.2');
define('TIMEZONE', 'Europe/Moscow');

date_default_timezone_set(TIMEZONE);

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
