-- =============================================================================
-- GovernIT Data Eraser - migration 003: nullable certificate_hash.
-- =============================================================================
-- Certificates produced by legacy Boot builds (< 0.0.9) carry an empty content
-- hash. With a NOT NULL + UNIQUE certificate_hash, a second empty-hash
-- certificate could neither be inserted (duplicate key) nor safely upserted
-- (it would silently overwrite the first). Making the column nullable lets
-- such records coexist as NULL while future certificates keep a real hash.

ALTER TABLE certificate MODIFY certificate_hash CHAR(64) NULL;