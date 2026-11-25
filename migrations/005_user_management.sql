-- =============================================================================
-- GovernIT Data Eraser - migration 005: customer-scoped user management.
-- -----------------------------------------------------------------------------
-- Adds:
--   * user_customer_access : maps a web_user to the customers they may see.
--                            A user with no rows (and no admin role) sees nothing;
--                            SystemAdministrator is implicitly unrestricted.
--   * invitation_token     : one-time tokens for the invite / password-reset
--                            email flow. Invited users are created inactive and
--                            activated when they set a password via the link.
--   * Two new RBAC roles   : SystemAdministrator (unrestricted) and
--                            KundeAdministrator (scoped to their mapped
--                            customers). Existing roles are left untouched.
--   * web_user.needs_password_change : forces a password reset on next login
--                            (used immediately after an invitation is accepted
--                            and for the admin boot-strap row).
--
-- Target: MySQL 8.x  (Gigahost: ckozkl4kj.mysql.service.one.com / db ckozkl4kj_eraser)
-- =============================================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------------------------
-- Customer scoping: which customers may a web_user access?
-- A KundeAdministrator (or a lower role) may only read/write rows belonging to
-- the customers listed here. No FK constraints are added, consistent with the
-- rest of the schema (referential integrity is enforced in the application).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_customer_access (
    user_id     CHAR(36)    NOT NULL,
    customer_id CHAR(36)    NOT NULL,
    created_at  VARCHAR(32) NOT NULL,
    updated_at  VARCHAR(32) NOT NULL,
    PRIMARY KEY (user_id, customer_id),
    KEY idx_uca_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Invitation / password-reset tokens.
-- The raw token (random hex) is only emailed; the DB stores its sha256 hash,
-- matching how bearer auth tokens are stored. One user may have several pending
-- tokens (older ones are invalidated/used up by consuming a newer one).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS invitation_token (
    id            CHAR(36)     NOT NULL PRIMARY KEY,
    user_id       CHAR(36)     NOT NULL,
    email         VARCHAR(255) NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    type          ENUM('invite','reset') NOT NULL,
    created_at    VARCHAR(32)  NOT NULL,
    expires_at    VARCHAR(32)  NOT NULL,
    used_at       VARCHAR(32)  NULL,
    KEY idx_it_hash (token_hash),
    KEY idx_it_user (user_id, type),
    KEY idx_it_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- -----------------------------------------------------------------------------
-- Invited users have no usable password until they follow the link, so allow
-- the hash to be empty at creation time (only settable once the invite is
-- consumed). needs_password_change forces a re-set on next login.
-- -----------------------------------------------------------------------------
ALTER TABLE web_user
    MODIFY password_hash VARCHAR(255) NULL,
    ADD COLUMN needs_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

-- -----------------------------------------------------------------------------
-- Seed: new admin roles (idempotent via unique name). Existing roles
-- (Administrator/Koordinator/Tekniker/Revisor) are unchanged.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO role (id, name, description) VALUES
  ('00000000-0000-0000-0000-000000000005','SystemAdministrator','Fuld, uindskrænket adgang til systemet og alle kunder.'),
  ('00000000-0000-0000-0000-000000000006','KundeAdministrator','Adgang begrænset til de tildelte kunder; kan administrere brugere for disse.'),
  ('00000000-0000-0000-0000-000000000007','KundeRapporter','Skrivebeskyttet adgang til egne kunders sessioner, certifikater og rapporter.');

-- Mark the bootstrap admin so a fresh password must be set on first login.
UPDATE web_user SET needs_password_change = 1 WHERE username = 'admin';
