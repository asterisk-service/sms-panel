-- =====================================================
-- SMS Panel Database Schema
-- OpenVox GSM Gateway SMS Management System
-- =====================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- Database (create if needed)
-- -----------------------------------------------------
-- CREATE DATABASE IF NOT EXISTS sms_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE sms_panel;

-- -----------------------------------------------------
-- Table: inbox (Входящие SMS)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `inbox`;
CREATE TABLE `inbox` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `phone_number` VARCHAR(20) NOT NULL COMMENT 'Номер отправителя',
    `message` TEXT NOT NULL COMMENT 'Текст сообщения',
    `port` VARCHAR(20) DEFAULT NULL COMMENT 'Номер порта шлюза',
    `port_name` VARCHAR(50) DEFAULT NULL COMMENT 'Название порта',
    `imsi` VARCHAR(50) DEFAULT NULL COMMENT 'IMSI SIM-карты',
    `received_at` DATETIME NOT NULL COMMENT 'Время получения',
    `is_read` TINYINT(1) DEFAULT 0 COMMENT 'Прочитано: 0=нет, 1=да',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_phone` (`phone_number`),
    INDEX `idx_received` (`received_at`),
    INDEX `idx_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Входящие SMS';

-- -----------------------------------------------------
-- Table: outbox (Исходящие SMS)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `outbox`;
CREATE TABLE `outbox` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `phone_number` VARCHAR(20) NOT NULL COMMENT 'Номер получателя',
    `message` TEXT NOT NULL COMMENT 'Текст сообщения',
    `port` VARCHAR(20) DEFAULT NULL COMMENT 'Порт отправки',
    `status` ENUM('pending', 'sent', 'failed', 'delivered') DEFAULT 'pending' COMMENT 'Статус',
    `status_message` TEXT DEFAULT NULL COMMENT 'Ответ шлюза',
    `template_id` INT DEFAULT NULL COMMENT 'ID шаблона',
    `sent_at` DATETIME DEFAULT NULL COMMENT 'Время отправки',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_phone` (`phone_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sent` (`sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Исходящие SMS';

-- -----------------------------------------------------
-- Table: templates (Шаблоны SMS)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `templates`;
CREATE TABLE `templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Название шаблона',
    `content` TEXT NOT NULL COMMENT 'Текст шаблона',
    `variables` TEXT DEFAULT NULL COMMENT 'JSON массив переменных',
    `usage_count` INT DEFAULT 0 COMMENT 'Счетчик использований',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активен: 0=нет, 1=да',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Шаблоны SMS';

-- -----------------------------------------------------
-- Table: contact_groups (Группы контактов)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `contact_groups`;
CREATE TABLE `contact_groups` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Название группы',
    `description` TEXT DEFAULT NULL COMMENT 'Описание',
    `color` VARCHAR(7) DEFAULT '#3498db' COMMENT 'Цвет HEX',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Группы контактов';

-- -----------------------------------------------------
-- Table: contacts (Контакты / Телефонная книга)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `contacts`;
CREATE TABLE `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Имя контакта',
    `phone_number` VARCHAR(20) NOT NULL UNIQUE COMMENT 'Номер телефона',
    `company` VARCHAR(100) DEFAULT NULL COMMENT 'Компания',
    `email` VARCHAR(100) DEFAULT NULL COMMENT 'Email',
    `notes` TEXT DEFAULT NULL COMMENT 'Заметки',
    `group_id` INT DEFAULT NULL COMMENT 'ID группы',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активен: 0=нет, 1=да',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_phone` (`phone_number`),
    INDEX `idx_name` (`name`),
    INDEX `idx_group` (`group_id`),
    INDEX `idx_active` (`is_active`),
    CONSTRAINT `fk_contacts_group` FOREIGN KEY (`group_id`) 
        REFERENCES `contact_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Контакты';

-- -----------------------------------------------------
-- Table: spam_log (Лог анти-спама)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `spam_log`;
CREATE TABLE `spam_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `phone_number` VARCHAR(20) NOT NULL COMMENT 'Номер телефона',
    `last_sent` DATETIME NOT NULL COMMENT 'Время последней отправки',
    INDEX `idx_phone_time` (`phone_number`, `last_sent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог анти-спама';

-- -----------------------------------------------------
-- Table: gateways (SMS шлюзы)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `gateways`;
CREATE TABLE `gateways` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Название шлюза',
    `type` ENUM('openvox', 'goip') NOT NULL DEFAULT 'openvox' COMMENT 'Тип шлюза',
    `host` VARCHAR(100) NOT NULL COMMENT 'IP адрес',
    `port` INT DEFAULT 80 COMMENT 'HTTP порт',
    `username` VARCHAR(100) DEFAULT NULL COMMENT 'Логин',
    `password` VARCHAR(100) DEFAULT NULL COMMENT 'Пароль',
    `channels` INT DEFAULT 8 COMMENT 'Количество каналов/портов',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активен: 0=нет, 1=да',
    `is_default` TINYINT(1) DEFAULT 0 COMMENT 'По умолчанию',
    `priority` INT DEFAULT 0 COMMENT 'Приоритет (больше = выше)',
    `messages_sent` INT DEFAULT 0 COMMENT 'Отправлено сообщений',
    `last_used_at` DATETIME DEFAULT NULL COMMENT 'Последнее использование',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_active` (`is_active`),
    INDEX `idx_default` (`is_default`),
    INDEX `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='SMS шлюзы';

-- -----------------------------------------------------
-- Table: settings (Настройки системы)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(50) NOT NULL UNIQUE COMMENT 'Ключ настройки',
    `setting_value` TEXT DEFAULT NULL COMMENT 'Значение',
    `description` VARCHAR(255) DEFAULT NULL COMMENT 'Описание'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Настройки системы';

-- -----------------------------------------------------
-- Table: campaigns (Массовые рассылки)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `campaigns`;
CREATE TABLE `campaigns` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL COMMENT 'Название рассылки',
    `message` TEXT NOT NULL COMMENT 'Текст сообщения',
    `total_count` INT DEFAULT 0 COMMENT 'Всего сообщений',
    `sent_count` INT DEFAULT 0 COMMENT 'Отправлено',
    `failed_count` INT DEFAULT 0 COMMENT 'Ошибок',
    `delivered_count` INT DEFAULT 0 COMMENT 'Доставлено',
    `gateway_id` INT DEFAULT NULL COMMENT 'ID шлюза (NULL = все шлюзы)',
    `port_mode` ENUM('random', 'linear', 'specific') DEFAULT 'random' COMMENT 'Режим порта',
    `specific_port` INT DEFAULT NULL COMMENT 'Конкретный порт',
    `send_delay` INT DEFAULT 1000 COMMENT 'Задержка между SMS в мс',
    `status` ENUM('draft', 'running', 'paused', 'completed', 'cancelled') DEFAULT 'draft' COMMENT 'Статус',
    `started_at` DATETIME DEFAULT NULL COMMENT 'Время старта',
    `completed_at` DATETIME DEFAULT NULL COMMENT 'Время завершения',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_created` (`created_at`),
    INDEX `idx_gateway` (`gateway_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Массовые рассылки';

-- -----------------------------------------------------
-- Table: campaign_messages (Сообщения рассылки)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `campaign_messages`;
CREATE TABLE `campaign_messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id` INT NOT NULL COMMENT 'ID рассылки',
    `phone_number` VARCHAR(20) NOT NULL COMMENT 'Номер получателя',
    `contact_name` VARCHAR(255) DEFAULT NULL COMMENT 'Имя контакта',
    `message` TEXT NOT NULL COMMENT 'Персональный текст',
    `port` INT DEFAULT NULL COMMENT 'Порт отправки',
    `port_name` VARCHAR(50) DEFAULT NULL COMMENT 'Название порта',
    `status` ENUM('pending', 'sending', 'sent', 'failed', 'delivered') DEFAULT 'pending' COMMENT 'Статус',
    `gateway_response` TEXT DEFAULT NULL COMMENT 'Ответ шлюза',
    `gateway_message_id` VARCHAR(100) DEFAULT NULL COMMENT 'ID сообщения от шлюза',
    `sent_at` DATETIME DEFAULT NULL COMMENT 'Время отправки',
    `delivered_at` DATETIME DEFAULT NULL COMMENT 'Время доставки',
    `error_message` TEXT DEFAULT NULL COMMENT 'Текст ошибки',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_campaign` (`campaign_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_phone` (`phone_number`),
    INDEX `idx_message_id` (`gateway_message_id`),
    CONSTRAINT `fk_campaign_messages` FOREIGN KEY (`campaign_id`) 
        REFERENCES `campaigns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Сообщения рассылки';

-- -----------------------------------------------------
-- Table: gateway_ports (Порты шлюза)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `gateway_ports`;
CREATE TABLE `gateway_ports` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `gateway_id` INT NOT NULL COMMENT 'ID шлюза',
    `port_number` INT NOT NULL COMMENT 'Номер порта',
    `port_name` VARCHAR(50) NOT NULL COMMENT 'Название',
    `sim_number` VARCHAR(20) DEFAULT NULL COMMENT 'Номер SIM-карты',
    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Активен: 0=нет, 1=да',
    `last_used_at` DATETIME DEFAULT NULL COMMENT 'Последнее использование',
    `messages_sent` INT DEFAULT 0 COMMENT 'Отправлено сообщений',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_gateway_port` (`gateway_id`, `port_number`),
    INDEX `idx_gateway` (`gateway_id`),
    CONSTRAINT `fk_port_gateway` FOREIGN KEY (`gateway_id`) 
        REFERENCES `gateways` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Порты шлюза';

-- =====================================================
-- DEFAULT DATA
-- =====================================================

-- Default settings (gateway settings are now in gateways table)
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
    ('spam_interval', '60', 'Anti-spam interval in seconds')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;

-- Default contact group
INSERT INTO `contact_groups` (`id`, `name`, `description`, `color`) VALUES
    (1, 'General', 'Default contact group', '#3498db')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Note: Gateway ports are now created automatically when adding a gateway

-- Sample templates
INSERT INTO `templates` (`name`, `content`, `variables`) VALUES
    ('Welcome', 'Hello {name}! Welcome to our service.', '["name"]'),
    ('Verification', 'Your code: {code}. Valid 5 min.', '["code"]'),
    ('Reminder', 'Dear {name}, reminder: {event} on {date}', '["name","event","date"]')
ON DUPLICATE KEY UPDATE `name` = `name`;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- SCHEMA SUMMARY
-- =====================================================
-- Tables: 9
-- 
-- inbox            - Входящие SMS
-- outbox           - Исходящие SMS  
-- templates        - Шаблоны SMS
-- contacts         - Контакты
-- contact_groups   - Группы контактов
-- spam_log         - Лог анти-спама
-- settings         - Настройки системы
-- campaigns        - Массовые рассылки
-- campaign_messages - Сообщения рассылок
-- gateway_ports    - Порты шлюза
-- =====================================================
