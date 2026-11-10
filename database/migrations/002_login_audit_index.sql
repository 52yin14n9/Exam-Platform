ALTER TABLE admins
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

ALTER TABLE students
    ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

ALTER TABLE audit_logs
    ADD KEY idx_audit_login (ip_hash, action, created_at);
