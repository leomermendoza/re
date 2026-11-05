-- Run on Admin PC / phpMyAdmin database: tracked_db
-- Skip statements already applied successfully.

USE tracked_db;

ALTER TABLE schedules
    ADD COLUMN IF NOT EXISTS time_in_start TIME NOT NULL DEFAULT '06:00:00' AFTER days_json,
    ADD COLUMN IF NOT EXISTS lunch_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER late_cutoff,
    ADD COLUMN IF NOT EXISTS lunch_out_start TIME NOT NULL DEFAULT '11:30:00' AFTER lunch_enabled,
    ADD COLUMN IF NOT EXISTS lunch_out_end TIME NOT NULL DEFAULT '12:15:00' AFTER lunch_out_start,
    ADD COLUMN IF NOT EXISTS lunch_in_start TIME NOT NULL DEFAULT '12:15:00' AFTER lunch_out_end,
    ADD COLUMN IF NOT EXISTS lunch_in_end TIME NOT NULL DEFAULT '13:15:00' AFTER lunch_in_start,
    ADD COLUMN IF NOT EXISTS time_out_start TIME NOT NULL DEFAULT '15:00:00' AFTER lunch_in_end,
    ADD COLUMN IF NOT EXISTS time_out_end TIME NOT NULL DEFAULT '18:00:00' AFTER time_out_start;

ALTER TABLE attendance
    ADD COLUMN IF NOT EXISTS lunch_out DATETIME NULL AFTER scan_time,
    ADD COLUMN IF NOT EXISTS lunch_in DATETIME NULL AFTER lunch_out;

ALTER TABLE rejected_scans
    MODIFY scan_type ENUM('IN','LUNCH_OUT','LUNCH_IN','OUT') NOT NULL;
