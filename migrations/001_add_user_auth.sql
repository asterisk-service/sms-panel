-- Migration: Add User Authentication System
-- Version: 1.2
-- Run this on existing databases to add user support

-- =====================================================
-- 1. Create users table
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Логин',
    `password` VARCHAR(255) NOT NULL COMMENT 'Хэш пароля',
    `name` VARCHAR(100) NOT NULL COMMENT 'Имя пользователя',
    `email` VARCHAR(100) DEFAULT NULL COMMENT 'Email',
    `role` ENUM('admin', 'user') DEFAULT 'user' COMMENT 'Роль',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активен',
    `last_login` DATETIME DEFAULT NULL COMMENT 'Последний вход',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_username` (`username`),
    INDEX `idx_role` (`role`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Пользователи системы';

-- =====================================================
-- 2. Create user_ports table
-- =====================================================
CREATE TABLE IF NOT EXISTS `user_ports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL COMMENT 'ID пользователя',
    `gateway_id` INT NOT NULL COMMENT 'ID шлюза',
    `port_id` INT NOT NULL COMMENT 'ID порта',
    `can_send` TINYINT(1) DEFAULT 1 COMMENT 'Может отправлять',
    `can_receive` TINYINT(1) DEFAULT 1 COMMENT 'Может получать',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_port` (`user_id`, `port_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_gateway` (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Доступ к портам';

-- =====================================================
-- 3. Add user_id to contacts table
-- =====================================================
ALTER TABLE `contacts` ADD COLUMN `user_id` INT DEFAULT NULL COMMENT 'ID владельца' AFTER `id`;
ALTER TABLE `contacts` ADD INDEX `idx_user` (`user_id`);

-- =====================================================
-- 4. Add user_id to contact_groups table
-- =====================================================
ALTER TABLE `contact_groups` ADD COLUMN `user_id` INT DEFAULT NULL COMMENT 'ID владельца' AFTER `id`;
ALTER TABLE `contact_groups` ADD INDEX `idx_user` (`user_id`);

-- =====================================================
-- 5. Add user_id to templates table
-- =====================================================
ALTER TABLE `templates` ADD COLUMN `user_id` INT DEFAULT NULL COMMENT 'ID владельца' AFTER `id`;
ALTER TABLE `templates` ADD INDEX `idx_user` (`user_id`);

-- =====================================================
-- 6. Add user_id to campaigns table
-- =====================================================
ALTER TABLE `campaigns` ADD COLUMN `user_id` INT DEFAULT NULL COMMENT 'ID владельца' AFTER `id`;
ALTER TABLE `campaigns` ADD INDEX `idx_user` (`user_id`);

-- =====================================================
-- 7. Create default admin user (password: admin123)
-- =====================================================
INSERT IGNORE INTO `users` (`username`, `password`, `name`, `role`, `is_active`) VALUES
    ('admin', '$2y$10$N9qo8uLOickgx2ZMRZoMyeNw/O3z5BKQXB1KdJH5LrM3F5z2DlWHW', 'Administrator', 'admin', 1);

-- =====================================================
-- Done!
-- Default login: admin / admin123
-- =====================================================
