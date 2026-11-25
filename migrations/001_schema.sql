-- =============================================================================
-- GovernIT Data Eraser - Web / Online database schema (migration 001)
-- -----------------------------------------------------------------------------
-- Mirrors the local (SQLite, boot/management) master model so the online MySQL
-- database can act as an authoritative, synchronised copy.
--
-- Target: MySQL 8.x  (Gigahost: ckozkl4kj.mysql.service.one.com / db ckozkl4kj_eraser)
-- Charset: utf8mb4 (full Unicode incl. Danish + emoji-safe).
--
-- Sync model: every client-synced table carries `updated_at` (UTC, ISO-8601
-- string, matching the local model) plus a `deleted` tombstone flag so offline
-- clients can delta-sync without data loss. The local app pulls master data
-- (organisation/customers/projects/technicians/roles/profiles) and pushes
-- session/certificate results.
--
-- NOTE: run once via the web installer or `mysql < 001_schema.sql`.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS organisation (
    id               CHAR(36)        NOT NULL PRIMARY KEY,
    name             VARCHAR(255)    NOT NULL,
    organisation_id  VARCHAR(64)     NOT NULL,
    contact          VARCHAR(255)    NULL,
    updated_at       VARCHAR(32)     NOT NULL,
    deleted          TINYINT(1)      NOT NULL DEFAULT 0,
    UNIQUE KEY uq_org_orgid (organisation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS customer (
    id               CHAR(36)        NOT NULL PRIMARY KEY,
    name             VARCHAR(255)    NOT NULL,
    customer_number  VARCHAR(64)     NOT NULL,
    address          VARCHAR(255)    NULL,
    contact_person   VARCHAR(255)    NULL,
    contact_email    VARCHAR(255)    NULL,
    contact_phone    VARCHAR(64)     NULL,
    notes            TEXT            NULL,
    active           TINYINT(1)      NOT NULL DEFAULT 1,
    updated_at       VARCHAR(32)     NOT NULL,
    deleted          TINYINT(1)      NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cust_number (customer_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS project (
    id                       CHAR(36)    NOT NULL PRIMARY KEY,
    name                     VARCHAR(255) NOT NULL,
    project_number           VARCHAR(64) NOT NULL,
    customer_id              CHAR(36)    NOT NULL,
    case_number              VARCHAR(64) NULL,
    reference                VARCHAR(255) NULL,
    order_number             VARCHAR(64) NULL,
    start_date               VARCHAR(32) NULL,
    end_date                 VARCHAR(32) NULL,
    status                   ENUM('active','completed','archived') NOT NULL DEFAULT 'active',
    default_profile_id       CHAR(36)    NULL,
    requires_serial_number   TINYINT(1)  NOT NULL DEFAULT 0,
    requires_asset_tag       TINYINT(1)  NOT NULL DEFAULT 0,
    requires_extra_confirmation TINYINT(1) NOT NULL DEFAULT 0,
    certificate_text         TEXT        NULL,
    notes                    TEXT        NULL,
    updated_at               VARCHAR(32) NOT NULL,
    deleted                  TINYINT(1)  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_project (customer_id, project_number),
    KEY idx_project_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS technician (
    id                 CHAR(36)    NOT NULL PRIMARY KEY,
    name               VARCHAR(255) NOT NULL,
    technician_id      VARCHAR(64) NULL,
    organisation       VARCHAR(255) NULL,
    initials           VARCHAR(16) NULL,
    email              VARCHAR(255) NULL,
    preferred_language ENUM('da','en') NULL,
    active             TINYINT(1)  NOT NULL DEFAULT 1,
    updated_at         VARCHAR(32) NOT NULL,
    deleted            TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Roles (RBAC) - mirror of the local role table.
CREATE TABLE IF NOT EXISTS role (
    id          CHAR(36)   NOT NULL PRIMARY KEY,
    name        VARCHAR(64) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS technician_role (
    technician_id CHAR(36) NOT NULL,
    role_id       CHAR(36) NOT NULL,
    PRIMARY KEY (technician_id, role_id),
    KEY idx_tr_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Web login users. A user may map to a technician (for role inheritance) or
-- have an independent web-only role set. Passwords hashed with password_hash().
CREATE TABLE IF NOT EXISTS web_user (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    username      VARCHAR(64)  NOT NULL UNIQUE,
    email         VARCHAR(255) NULL,
    display_name  VARCHAR(255) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    technician_id CHAR(36)     NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    VARCHAR(32)  NOT NULL,
    updated_at    VARCHAR(32)  NOT NULL,
    last_login_at VARCHAR(32)  NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_user_role (
    user_id CHAR(36) NOT NULL,
    role_id CHAR(36) NOT NULL,
    PRIMARY KEY (user_id, role_id),
    KEY idx_ur_role (role_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS web_session (
    id            CHAR(36)    NOT NULL PRIMARY KEY,
    user_id       CHAR(36)    NOT NULL,
    token_hash    CHAR(64)    NOT NULL,
    expires_at    VARCHAR(32) NOT NULL,
    created_at    VARCHAR(32) NOT NULL,
    ip_address    VARCHAR(64) NULL,
    user_agent    VARCHAR(255) NULL,
    KEY idx_ws_token (token_hash),
    KEY idx_ws_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Erasure profiles (mirror of local erasure_profile).
CREATE TABLE IF NOT EXISTS erasure_profile (
    id                 CHAR(36)    NOT NULL PRIMARY KEY,
    name               VARCHAR(255) NOT NULL,
    version            VARCHAR(32) NOT NULL,
    method             ENUM('clear','purge','cryptographic_erase','device_sanitize','overwrite','custom') NOT NULL,
    allowed_media_types JSON        NOT NULL,
    standards          JSON        NOT NULL,
    overwrite_pattern  VARCHAR(255) NULL,
    overwrite_passes   INT         NULL,
    verification       ENUM('readback','device_status','readback_and_device_status','none') NOT NULL,
    required_fields    JSON        NOT NULL,
    approval_required  TINYINT(1)  NOT NULL DEFAULT 0,
    certificate_text   TEXT        NULL,
    active             TINYINT(1)  NOT NULL DEFAULT 1,
    updated_at         VARCHAR(32) NOT NULL,
    deleted            TINYINT(1)  NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Erasure sessions (synced UP from local -> web). Captures kunde/projekt/tekniker
-- plus the overall outcome. Detailed per-target data lives in the JSON payload
-- for future expansion without schema churn.
CREATE TABLE IF NOT EXISTS erasure_session (
    id                  CHAR(36)    NOT NULL PRIMARY KEY,
    boot_session_id     CHAR(36)    NOT NULL,
    usb_installation_id VARCHAR(64) NULL,
    phase               ENUM('initialising','discovery','selection','planning',
                             'confirmation','authorisation','executing','verifying',
                             'completed','failed','aborted') NOT NULL,
    operator_name       VARCHAR(255) NULL,
    operator_technician_id VARCHAR(64) NULL,
    customer_id         CHAR(36)    NULL,
    customer_name       VARCHAR(255) NULL,
    project_name        VARCHAR(255) NULL,
    project_number      VARCHAR(64) NULL,
    case_number         VARCHAR(64) NULL,
    reference           VARCHAR(255) NULL,
    order_number        VARCHAR(64) NULL,
    asset_tag           VARCHAR(255) NULL,
    started_at          VARCHAR(32) NOT NULL,
    finished_at         VARCHAR(32) NULL,
    final_status        ENUM('pass','partial','fail','aborted','unknown') NULL,
    payload_json        JSON        NULL,
    sync_key            VARCHAR(64)  NOT NULL,
    updated_at          VARCHAR(32) NOT NULL,
    deleted             TINYINT(1)  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_sync_key (sync_key),
    KEY idx_session_start (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Certificates (synced UP from local -> web), 1:1 with the local certificate
-- store. Enough columns to render a certificate/report without joining.
CREATE TABLE IF NOT EXISTS certificate (
    id                     CHAR(36)    NOT NULL PRIMARY KEY,
    session_id             CHAR(36)    NULL,
    certificate_hash       CHAR(64)    NOT NULL,
    status                 VARCHAR(32) NOT NULL,
    template_version       VARCHAR(32) NULL,
    generation_date        VARCHAR(32) NULL,
    qr_reference           VARCHAR(255) NULL,
    log_reference          VARCHAR(255) NULL,
    product_version        VARCHAR(32) NULL,
    customer_name          VARCHAR(255) NOT NULL,
    customer_number        VARCHAR(64) NOT NULL,
    project_name           VARCHAR(255) NULL,
    project_number         VARCHAR(64) NULL,
    case_number            VARCHAR(64) NULL,
    reference              VARCHAR(255) NULL,
    order_number           VARCHAR(64) NULL,
    computer_manufacturer  VARCHAR(255) NULL,
    computer_model         VARCHAR(255) NULL,
    computer_serial        VARCHAR(255) NULL,
    asset_tag              VARCHAR(255) NULL,
    drive_manufacturer     VARCHAR(255) NULL,
    drive_model            VARCHAR(255) NULL,
    drive_serial           VARCHAR(255) NULL,
    drive_firmware         VARCHAR(255) NULL,
    drive_capacity_bytes   BIGINT UNSIGNED NOT NULL DEFAULT 0,
    drive_media_type       VARCHAR(32) NULL,
    drive_interface        VARCHAR(32) NULL,
    profile_name           VARCHAR(255) NULL,
    actual_method          VARCHAR(64) NULL,
    started_at             VARCHAR(32) NULL,
    finished_at            VARCHAR(32) NULL,
    notes                  TEXT        NULL,
    verification_method    VARCHAR(64) NULL,
    verification_scope     VARCHAR(255) NULL,
    verification_result    VARCHAR(64) NULL,
    verification_deviations JSON       NULL,
    technician_name        VARCHAR(255) NULL,
    technician_id          VARCHAR(64) NULL,
    organisation           VARCHAR(255) NULL,
    sync_key               VARCHAR(64)  NOT NULL,
    updated_at             VARCHAR(32) NOT NULL,
    deleted                TINYINT(1)  NOT NULL DEFAULT 0,
    UNIQUE KEY uq_cert_hash (certificate_hash),
    KEY idx_cert_session (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sync journal: records every client sync so the local app can query "what has
-- changed since <cursor>" and the web can report last-seen per USB stick.
CREATE TABLE IF NOT EXISTS sync_log (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    stick_installation_id VARCHAR(64) NOT NULL,
    direction        ENUM('up','down') NOT NULL,
    entity           VARCHAR(64) NOT NULL,
    entity_id        CHAR(36)   NULL,
    cursor_after     VARCHAR(32) NULL,
    occurred_at      VARCHAR(32) NOT NULL,
    status           VARCHAR(32) NOT NULL,
    message          TEXT       NULL,
    KEY idx_sync_inst (stick_installation_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =============================================================================
-- Seed: default RBAC roles (idempotent-ish via unique name).
-- =============================================================================
INSERT IGNORE INTO role (id, name, description) VALUES
  ('00000000-0000-0000-0000-000000000001','Administrator','Fuld adgang: organisation, politikker, profiler, stik, rapporter.'),
  ('00000000-0000-0000-0000-000000000002','Koordinator','Opretter/redigerer kunder, projekter og teknikere; publicerer konfiguration.'),
  ('00000000-0000-0000-0000-000000000003','Tekniker','Kører Boot-workflow, import af resultater, egne certifikater.'),
  ('00000000-0000-0000-0000-000000000004','Revisor','Skrivebeskyttet adgang til sessioner, certifikater, audit-log og rapporter.');

-- Initial web admin login. Password MUST be set via the installer (CLI or web).
-- The hash is a valid bcrypt hash of the temporary password "changeme" so the
-- schema has a bootable admin; the installer forces a password change on first
-- login. Regenerate a fresh hash with:
--   php -r 'echo password_hash("NewPass", PASSWORD_BCRYPT);'
INSERT IGNORE INTO web_user (id, username, email, display_name, password_hash, active, created_at, updated_at)
VALUES ('00000000-0000-0000-0000-00000000000a','admin','admin@eraser.governit.dk','Administrator',
        '$2y$12$qe.xs581BOpl3ZRyYWgS7.Xy0CSmNJD9j/YuuQ3VMF693Fs8WTD3q', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');

INSERT IGNORE INTO web_user_role (user_id, role_id)
VALUES ('00000000-0000-0000-0000-00000000000a','00000000-0000-0000-0000-000000000001');
