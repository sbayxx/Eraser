-- =============================================================================
-- GovernIT Data Eraser - migration 002: PDF report compilations.
-- =============================================================================
-- Adds report storage for server-side PDF generation (batch certificate
-- compilations). The PDF bytes are stored in a `report_blob` table so the
-- portal can serve them without touching the filesystem on shared hosting.

CREATE TABLE IF NOT EXISTS report (
    id            CHAR(36)    NOT NULL PRIMARY KEY,
    title         VARCHAR(255) NOT NULL,
    kind          ENUM('certificate_compilation','session_audit') NOT NULL DEFAULT 'certificate_compilation',
    filter_json   JSON        NULL,
    certificate_count INT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('pending','ready','failed') NOT NULL DEFAULT 'pending',
    created_by    CHAR(36)    NULL,
    created_at    VARCHAR(32) NOT NULL,
    generated_at  VARCHAR(32) NULL,
    error         VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS report_blob (
    report_id      CHAR(36)  NOT NULL PRIMARY KEY,
    mime_type      VARCHAR(64) NOT NULL DEFAULT 'application/pdf',
    filename       VARCHAR(255) NOT NULL,
    bytes          MEDIUMBLOB NOT NULL,
    size_bytes     BIGINT UNSIGNED NOT NULL,
    generated_at   VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
