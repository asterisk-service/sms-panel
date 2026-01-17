<?php
/**
 * SMS Panel Configuration
 * GSM Gateway SMS Management System
 */

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'sms_panel');

// Anti-Spam Configuration (default, can be changed in Settings)
define('SPAM_INTERVAL', 60); // seconds between SMS to same number

// Application Settings
define('APP_NAME', 'SMS Panel');
define('APP_VERSION', '1.1');
define('TIMEZONE', 'Europe/Moscow');

date_default_timezone_set(TIMEZONE);

// Error reporting (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
