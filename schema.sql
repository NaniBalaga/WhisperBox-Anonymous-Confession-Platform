-- CONNECT SRMAP CONFESSIONS - DATABASE SETUP
-- Requires an existing `students` table with:
-- register_number, name
-- Import this file into the same database used by confessions.php.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `confessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `confession_text` LONGTEXT NOT NULL,
  `instagram_account` VARCHAR(255) DEFAULT NULL,
  `register_number` VARCHAR(100) DEFAULT NULL,
  `new_optional_text` TEXT DEFAULT NULL,
  `sender_register_number` VARCHAR(100) NOT NULL,
  `sender_name` VARCHAR(255) DEFAULT NULL,
  `display_date` DATE DEFAULT NULL,
  `reveal_at` DATETIME DEFAULT NULL,
  `share_token` VARCHAR(64) DEFAULT NULL,
  `like_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `fake_like_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_confessions_share_token` (`share_token`),
  KEY `idx_confessions_sender_created` (`sender_register_number`,`created_at`),
  KEY `idx_confessions_reveal_at` (`reveal_at`),
  KEY `idx_confessions_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `confession_likes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `confession_id` BIGINT UNSIGNED NOT NULL,
  `user_ip` VARCHAR(45) NOT NULL,
  `liker_register_number` VARCHAR(100) DEFAULT NULL,
  `liker_name` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_confession_like_ip` (`confession_id`,`user_ip`),
  KEY `idx_confession_likes_register` (`liker_register_number`),
  CONSTRAINT `fk_confession_likes_confession`
    FOREIGN KEY (`confession_id`) REFERENCES `confessions`(`id`)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `confession_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_name` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_confession_setting_name` (`setting_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `confession_settings` (`setting_name`,`setting_value`)
VALUES ('max_submissions','2'), ('submission_days','5,6')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

CREATE TABLE IF NOT EXISTS `confession_schedule_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_name` VARCHAR(150) NOT NULL,
  `rule_type` ENUM('weekly','this_week','date_range','monthly') NOT NULL DEFAULT 'weekly',
  `submission_days` VARCHAR(50) DEFAULT '5,6',
  `open_date` DATE DEFAULT NULL,
  `close_date` DATE DEFAULT NULL,
  `reveal_date` DATE DEFAULT NULL,
  `open_time` TIME NOT NULL DEFAULT '00:00:00',
  `close_time` TIME NOT NULL DEFAULT '23:59:59',
  `reveal_time` TIME NOT NULL DEFAULT '00:00:00',
  `weekly_reveal_day` TINYINT UNSIGNED DEFAULT 7,
  `month_open_day` TINYINT UNSIGNED DEFAULT 1,
  `month_close_day` TINYINT UNSIGNED DEFAULT 7,
  `month_reveal_day` TINYINT UNSIGNED DEFAULT 7,
  `max_submissions` TINYINT UNSIGNED NOT NULL DEFAULT 2,
  `priority` INT NOT NULL DEFAULT 100,
  `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_schedule_enabled_priority` (`is_enabled`,`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `confession_schedule_rules`
(`rule_name`,`rule_type`,`submission_days`,`open_time`,`close_time`,`reveal_time`,
 `weekly_reveal_day`,`max_submissions`,`priority`,`is_enabled`)
SELECT 'Standard Weekly Confessions','weekly','5,6','00:00:00','23:59:59',
       '00:00:00',7,2,100,1
WHERE NOT EXISTS (
  SELECT 1 FROM `confession_schedule_rules` WHERE `rule_type`='weekly'
);

-- Weekday numbers: 1=Mon ... 7=Sun.
-- Example:
-- UPDATE confession_settings SET setting_value='3'
-- WHERE setting_name='max_submissions';
--
-- UPDATE confession_settings SET setting_value='5,6,7'
-- WHERE setting_name='submission_days';
