-- ============================================================
-- Smart Task and Reminder Management System Using AI
-- Database: smart_task_manager
-- MySQL / MariaDB (XAMPP)
-- ============================================================

SET NAMES utf8mb4;


CREATE DATABASE IF NOT EXISTS smart_task_manager
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE smart_task_manager;

-- Safe to import into an existing database; existing records are preserved.

-- ------------------------------------------------------------
-- 1. USERS
-- Authentication and basic user preferences.
-- password_hash is intentionally NOT pre-populated with a fake
-- password. The PHP application should hash passwords at signup.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Copenhagen',
    account_status ENUM('ACTIVE','INACTIVE','LOCKED') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    INDEX idx_users_status (account_status)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2. CATEGORIES
-- user_id NULL = built-in/system category.
-- user_id set  = category created by a particular user.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
    category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    category_name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_categories_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_categories_user (user_id),
    INDEX idx_categories_name (category_name)
) ENGINE=InnoDB;

-- Built-in categories available to all users.
INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'University', 'Assignments, dissertation work, study and academic tasks', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='University');

INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'Work', 'Employment and professional tasks', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='Work');

INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'Personal', 'Personal errands, goals and everyday responsibilities', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='Personal');

INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'Health', 'Health, fitness and wellbeing tasks', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='Health');

INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'Finance', 'Bills, payments and financial tasks', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='Finance');

INSERT INTO categories(user_id,category_name,description,is_system)
SELECT NULL, 'Other', 'Tasks that do not fit another category', TRUE
WHERE NOT EXISTS(SELECT 1 FROM categories WHERE user_id IS NULL AND category_name='Other');

-- ------------------------------------------------------------
-- 3. TASKS
-- Core task-management table.
-- AI fields allow the PHP recommendation engine to store its
-- calculated recommendation and explanation.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tasks (
    task_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    category_id BIGINT UNSIGNED NULL,

    title VARCHAR(180) NOT NULL,
    description TEXT NULL,

    due_at DATETIME NULL,
    importance ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
    status ENUM('PENDING','IN_PROGRESS','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PENDING',

    ai_priority_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    ai_priority_level ENUM('LOW','MEDIUM','HIGH','CRITICAL') NULL,
    ai_reason VARCHAR(500) NULL,
    ai_last_calculated_at DATETIME NULL,

    postpone_count INT UNSIGNED NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_tasks_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_tasks_category
        FOREIGN KEY (category_id) REFERENCES categories(category_id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_tasks_user (user_id),
    INDEX idx_tasks_category (category_id),
    INDEX idx_tasks_status (status),
    INDEX idx_tasks_due (due_at),
    INDEX idx_tasks_ai_score (ai_priority_score),
    INDEX idx_tasks_dashboard (user_id, status, due_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4. REMINDERS
-- Supports manual reminders and AI-suggested reminders.
-- Users can accept, change or ignore an AI suggestion.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
    reminder_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    reminder_at DATETIME NOT NULL,
    reminder_type ENUM('MANUAL','AI_SUGGESTED') NOT NULL DEFAULT 'MANUAL',
    suggestion_status ENUM('NOT_APPLICABLE','PENDING','ACCEPTED','CHANGED','IGNORED')
        NOT NULL DEFAULT 'NOT_APPLICABLE',

    message VARCHAR(500) NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    is_sent BOOLEAN NOT NULL DEFAULT FALSE,
    sent_at DATETIME NULL,
    dismissed_at DATETIME NULL,

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_reminders_task
        FOREIGN KEY (task_id) REFERENCES tasks(task_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_reminders_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    INDEX idx_reminders_task (task_id),
    INDEX idx_reminders_user (user_id),
    INDEX idx_reminders_due (reminder_at, is_enabled, is_sent)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5. TASK HISTORY
-- Audit/history data for created, edited, completed, postponed,
-- deleted/cancelled and AI-priority changes.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_history (
    history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,

    action_type ENUM(
        'CREATED',
        'UPDATED',
        'STATUS_CHANGED',
        'COMPLETED',
        'POSTPONED',
        'CANCELLED',
        'PRIORITY_RECALCULATED'
    ) NOT NULL,

    old_status VARCHAR(30) NULL,
    new_status VARCHAR(30) NULL,
    old_due_at DATETIME NULL,
    new_due_at DATETIME NULL,
    old_priority_score DECIMAL(5,2) NULL,
    new_priority_score DECIMAL(5,2) NULL,
    details VARCHAR(1000) NULL,

    action_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_history_task
        FOREIGN KEY (task_id) REFERENCES tasks(task_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_history_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    INDEX idx_history_task (task_id),
    INDEX idx_history_user (user_id),
    INDEX idx_history_action_at (action_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6. USER ACTIVITY
-- Behaviour information that may later be used by the local AI
-- recommendation engine without making decisions for the user.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_activity (
    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    task_id BIGINT UNSIGNED NULL,

    activity_type ENUM(
        'LOGIN',
        'LOGOUT',
        'TASK_VIEWED',
        'TASK_CREATED',
        'TASK_EDITED',
        'TASK_COMPLETED',
        'TASK_POSTPONED',
        'REMINDER_ACCEPTED',
        'REMINDER_CHANGED',
        'REMINDER_IGNORED',
        'REMINDER_DISMISSED'
    ) NOT NULL,

    activity_details VARCHAR(1000) NULL,
    activity_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_activity_user
        FOREIGN KEY (user_id) REFERENCES users(user_id)
        ON DELETE CASCADE ON UPDATE CASCADE,

    CONSTRAINT fk_activity_task
        FOREIGN KEY (task_id) REFERENCES tasks(task_id)
        ON DELETE SET NULL ON UPDATE CASCADE,

    INDEX idx_activity_user (user_id),
    INDEX idx_activity_task (task_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_activity_at (activity_at)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Helpful dashboard views
-- ------------------------------------------------------------
CREATE OR REPLACE VIEW v_pending_tasks AS
SELECT
    t.task_id,
    t.user_id,
    t.title,
    t.description,
    c.category_name,
    t.due_at,
    t.importance,
    t.status,
    t.ai_priority_score,
    t.ai_priority_level,
    t.ai_reason,
    t.postpone_count
FROM tasks t
LEFT JOIN categories c ON c.category_id = t.category_id
WHERE t.status IN ('PENDING','IN_PROGRESS');

CREATE OR REPLACE VIEW v_overdue_tasks AS
SELECT
    t.task_id,
    t.user_id,
    t.title,
    t.due_at,
    t.importance,
    t.status,
    t.ai_priority_score,
    t.ai_priority_level
FROM tasks t
WHERE t.status IN ('PENDING','IN_PROGRESS')
  AND t.due_at IS NOT NULL
  AND t.due_at < NOW();

CREATE OR REPLACE VIEW v_upcoming_reminders AS
SELECT
    r.reminder_id,
    r.user_id,
    r.task_id,
    t.title AS task_title,
    t.due_at,
    r.reminder_at,
    r.reminder_type,
    r.suggestion_status,
    r.message
FROM reminders r
INNER JOIN tasks t ON t.task_id = r.task_id
WHERE r.is_enabled = TRUE
  AND r.is_sent = FALSE
  AND r.suggestion_status NOT IN ('PENDING','IGNORED')
  AND t.status IN ('PENDING','IN_PROGRESS');



-- ============================================================
-- END
--
-- IMPORTANT:
-- No demo login is inserted because passwords should be created
-- by the PHP registration code and securely hashed.
--
-- PHP PDO database connection is configured in config.php.
-- ============================================================

-- Additive user preferences upgrade. Config creates this automatically for existing installs.
CREATE TABLE IF NOT EXISTS app_preferences (
 user_id BIGINT UNSIGNED PRIMARY KEY,
 quiet_start TIME NOT NULL DEFAULT '22:00:00',
 quiet_end TIME NOT NULL DEFAULT '08:00:00',
 preferred_hour TINYINT UNSIGNED NOT NULL DEFAULT 9,
 notifications_enabled BOOLEAN NOT NULL DEFAULT TRUE,
 CONSTRAINT fk_preferences_user FOREIGN KEY(user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;
