<?php
/**
 * Database Schema Installation
 */

require_once __DIR__ . '/database.php';

function installSchema() {
    $db = Database::getInstance();
    $conn = $db->getConnection();

    $tables = [
        // Inbox - Received SMS
        "CREATE TABLE IF NOT EXISTS inbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            port VARCHAR(20),
            port_name VARCHAR(50),
            imsi VARCHAR(50),
            received_at DATETIME NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone_number),
            INDEX idx_received (received_at),
            INDEX idx_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Outbox - Sent SMS
        "CREATE TABLE IF NOT EXISTS outbox (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            port VARCHAR(20),
            status ENUM('pending', 'sent', 'failed', 'delivered') DEFAULT 'pending',
            status_message TEXT,
            template_id INT,
            sent_at DATETIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_phone (phone_number),
            INDEX idx_status (status),
            INDEX idx_sent (sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // SMS Templates
        "CREATE TABLE IF NOT EXISTS templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            content TEXT NOT NULL,
            variables TEXT COMMENT 'JSON array of variable names',
            usage_count INT DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Phone Book / Contacts
        "CREATE TABLE IF NOT EXISTS contacts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            phone_number VARCHAR(20) NOT NULL UNIQUE,
            company VARCHAR(100),
            email VARCHAR(100),
            notes TEXT,
            group_id INT,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_phone (phone_number),
            INDEX idx_name (name),
            INDEX idx_group (group_id),
            INDEX idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Contact Groups
        "CREATE TABLE IF NOT EXISTS contact_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            color VARCHAR(7) DEFAULT '#3498db',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Anti-Spam Log
        "CREATE TABLE IF NOT EXISTS spam_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            phone_number VARCHAR(20) NOT NULL,
            last_sent DATETIME NOT NULL,
            INDEX idx_phone_time (phone_number, last_sent)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // System Settings
        "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value TEXT,
            description VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Bulk SMS Campaigns
        "CREATE TABLE IF NOT EXISTS campaigns (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            total_count INT DEFAULT 0,
            sent_count INT DEFAULT 0,
            failed_count INT DEFAULT 0,
            delivered_count INT DEFAULT 0,
            port_mode ENUM('random', 'linear', 'specific') DEFAULT 'random',
            specific_port INT DEFAULT NULL,
            send_delay INT DEFAULT 1000 COMMENT 'Delay between messages in ms',
            status ENUM('draft', 'running', 'paused', 'completed', 'cancelled') DEFAULT 'draft',
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Campaign Messages
        "CREATE TABLE IF NOT EXISTS campaign_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            campaign_id INT NOT NULL,
            phone_number VARCHAR(20) NOT NULL,
            contact_name VARCHAR(255) DEFAULT NULL,
            message TEXT NOT NULL,
            port INT DEFAULT NULL,
            port_name VARCHAR(50) DEFAULT NULL,
            status ENUM('pending', 'sending', 'sent', 'failed', 'delivered') DEFAULT 'pending',
            gateway_response TEXT,
            gateway_message_id VARCHAR(100) DEFAULT NULL,
            sent_at DATETIME NULL,
            delivered_at DATETIME NULL,
            error_message TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_campaign (campaign_id),
            INDEX idx_status (status),
            INDEX idx_phone (phone_number),
            INDEX idx_message_id (gateway_message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // Gateway Ports
        "CREATE TABLE IF NOT EXISTS gateway_ports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            port_number INT NOT NULL,
            port_name VARCHAR(50) NOT NULL,
            sim_number VARCHAR(20) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            last_used_at DATETIME NULL,
            messages_sent INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_port (port_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];

    foreach ($tables as $sql) {
        try {
            $conn->exec($sql);
        } catch (PDOException $e) {
            echo "Error creating table: " . $e->getMessage() . "\n";
        }
    }

    // Insert default settings (gateway settings are now in gateways table)
    $defaultSettings = [
        ['spam_interval', '60', 'Anti-spam interval in seconds']
    ];

    foreach ($defaultSettings as $setting) {
        try {
            $conn->exec("INSERT IGNORE INTO settings (setting_key, setting_value, description) 
                         VALUES ('{$setting[0]}', '{$setting[1]}', '{$setting[2]}')");
        } catch (PDOException $e) {
            // Ignore duplicates
        }
    }

    // Insert sample templates
    $sampleTemplates = [
        ['Welcome', 'Hello {name}! Welcome to our service. Your account is ready.', '["name"]'],
        ['Reminder', 'Dear {name}, this is a reminder about {event} on {date}.', '["name","event","date"]'],
        ['Verification', 'Your verification code is: {code}. Valid for 5 minutes.', '["code"]'],
        ['Notification', 'Hello {name}, you have a new {type} notification.', '["name","type"]']
    ];

    foreach ($sampleTemplates as $tpl) {
        try {
            $conn->exec("INSERT IGNORE INTO templates (name, content, variables) 
                         VALUES ('{$tpl[0]}', '{$tpl[1]}', '{$tpl[2]}')");
        } catch (PDOException $e) {
            // Ignore
        }
    }

    // Insert default group
    try {
        $conn->exec("INSERT IGNORE INTO contact_groups (id, name, description, color) 
                     VALUES (1, 'General', 'Default contact group', '#3498db')");
    } catch (PDOException $e) {
        // Ignore
    }

    // Insert default gateway ports (8 ports for typical OpenVox gateway)
    for ($i = 1; $i <= 8; $i++) {
        try {
            $conn->exec("INSERT IGNORE INTO gateway_ports (port_number, port_name) 
                         VALUES ($i, 'Port $i')");
        } catch (PDOException $e) {
            // Ignore
        }
    }

    echo "Database schema installed successfully!\n";
}

// Run installation if called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    installSchema();
}
