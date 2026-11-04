-- ============================================================
-- 002-fix-philippine-time.sql
-- ONE-TIME fix: old rows (before Oct 20, 2026) were saved in UTC,
-- newer rows are already Philippine time. This adds +8 hours to the
-- old DATETIME values so every record is in Philippine time.
--
-- 1) BACKUP the database first (phpMyAdmin > Export).
-- 2) Run this file ONCE in phpMyAdmin (SQL tab) on `buyawanongv4`.
--    It is protected by a marker table, so running it again does nothing.
-- TIMESTAMP columns are NOT touched (MySQL converts them automatically).
-- ============================================================

CREATE TABLE IF NOT EXISTS tz_migration_done (
  id INT PRIMARY KEY,
  done_at DATETIME NOT NULL
);

SET @cutoff = '2026-10-20 00:00:00';

START TRANSACTION;

UPDATE announcements SET
  approved_at = IF(approved_at < @cutoff, approved_at + INTERVAL 8 HOUR, approved_at),
  created_at  = IF(created_at  < @cutoff, created_at  + INTERVAL 8 HOUR, created_at),
  updated_at  = IF(updated_at  < @cutoff, updated_at  + INTERVAL 8 HOUR, updated_at)
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

UPDATE application_rejections SET
  rejected_at = IF(rejected_at < @cutoff, rejected_at + INTERVAL 8 HOUR, rejected_at)
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

UPDATE business_applications SET
  submitted_at   = IF(submitted_at   < @cutoff, submitted_at   + INTERVAL 8 HOUR, submitted_at),
  issued_at      = IF(issued_at      < @cutoff, issued_at      + INTERVAL 8 HOUR, issued_at),
  resubmitted_at = IF(resubmitted_at < @cutoff, resubmitted_at + INTERVAL 8 HOUR, resubmitted_at),
  updated_at     = updated_at
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

UPDATE fee_assessments SET
  assessed_at = IF(assessed_at < @cutoff, assessed_at + INTERVAL 8 HOUR, assessed_at)
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

UPDATE notifications SET
  read_at    = IF(read_at    < @cutoff, read_at    + INTERVAL 8 HOUR, read_at),
  created_at = IF(created_at < @cutoff, created_at + INTERVAL 8 HOUR, created_at),
  updated_at = IF(updated_at < @cutoff, updated_at + INTERVAL 8 HOUR, updated_at)
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

UPDATE payments SET
  paid_at    = IF(paid_at    < @cutoff, paid_at    + INTERVAL 8 HOUR, paid_at),
  created_at = IF(created_at < @cutoff, created_at + INTERVAL 8 HOUR, created_at),
  updated_at = IF(updated_at < @cutoff, updated_at + INTERVAL 8 HOUR, updated_at)
WHERE NOT EXISTS (SELECT 1 FROM tz_migration_done);

-- paypal_transactions id 1 was already edited to Philippine time, so skip it
UPDATE paypal_transactions SET
  paid_at    = IF(paid_at < @cutoff, paid_at + INTERVAL 8 HOUR, paid_at),
  updated_at = updated_at
WHERE id <> 1 AND NOT EXISTS (SELECT 1 FROM tz_migration_done);

INSERT IGNORE INTO tz_migration_done (id, done_at) VALUES (1, NOW());

COMMIT;

-- Check (optional): paid_at should now be Philippine time
-- SELECT id, reference_no, paid_at FROM payments WHERE paid_at IS NOT NULL ORDER BY paid_at DESC;
