-- =============================================================================
-- schema.sql — Calendars App
-- =============================================================================
-- Exécutez ce fichier une seule fois pour initialiser la base de données.
-- Compatible MySQL 5.7+ / MariaDB 10.3+
-- =============================================================================

CREATE DATABASE IF NOT EXISTS `calendars_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `calendars_db`;

-- -----------------------------------------------------------------------------
-- Utilisateurs de l'application
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(150)    NOT NULL,
    `email`         VARCHAR(255)    NOT NULL,
    `password_hash` VARCHAR(255)    DEFAULT NULL,   -- NULL si aucun mot de passe défini
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Calendriers
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calendars` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `owner_id`   INT UNSIGNED    NOT NULL,
    `title`      VARCHAR(100)    NOT NULL,
    `color`      VARCHAR(7)      NOT NULL DEFAULT '#2d9cdb',  -- format #RRGGBB
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    CONSTRAINT `fk_calendars_owner` FOREIGN KEY (`owner_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Partages de calendrier (accès en lecture ou en écriture)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `calendar_shares` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `calendar_id` INT UNSIGNED    NOT NULL,
    `user_id`     INT UNSIGNED    NOT NULL,
    `can_edit`    TINYINT(1)      NOT NULL DEFAULT 0,  -- 0 = lecture, 1 = écriture
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_shares` (`calendar_id`, `user_id`),
    CONSTRAINT `fk_shares_calendar` FOREIGN KEY (`calendar_id`)
        REFERENCES `calendars` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shares_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Événements
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `events` (
    `id`               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `calendar_id`      INT UNSIGNED    NOT NULL,
    `user_id`          INT UNSIGNED    NOT NULL,            -- créateur
    `title`            VARCHAR(255)    NOT NULL,
    `description`      TEXT            DEFAULT NULL,
    `location`         VARCHAR(255)    DEFAULT NULL,
    `start`            DATETIME        NOT NULL,
    `end`              DATETIME        DEFAULT NULL,
    `all_day`          TINYINT(1)      NOT NULL DEFAULT 0,  -- 0 = horodaté, 1 = journée entière
    `color`            VARCHAR(7)      NOT NULL DEFAULT '#2d9cdb',
    `status`           ENUM('confirmed','pending','cancelled') NOT NULL DEFAULT 'confirmed',
    `priority`         ENUM('low','normal','high')            NOT NULL DEFAULT 'normal',
    `reminder_minutes` SMALLINT UNSIGNED DEFAULT NULL,      -- 5, 15, 30, 60 ou 1440
    `created_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    CONSTRAINT `fk_events_calendar` FOREIGN KEY (`calendar_id`)
        REFERENCES `calendars` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_events_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Participants internes (utilisateurs de l'application)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `event_participants` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `event_id`   INT UNSIGNED    NOT NULL,
    `user_id`    INT UNSIGNED    NOT NULL,
    `role`       VARCHAR(50)     NOT NULL DEFAULT 'participant',
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_participants` (`event_id`, `user_id`),
    CONSTRAINT `fk_participants_event` FOREIGN KEY (`event_id`)
        REFERENCES `events` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_participants_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Invités externes (adresses e-mail hors application)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `event_guests` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `event_id`    INT UNSIGNED    NOT NULL,
    `guest_email` VARCHAR(255)    NOT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_guests` (`event_id`, `guest_email`),
    CONSTRAINT `fk_guests_event` FOREIGN KEY (`event_id`)
        REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
