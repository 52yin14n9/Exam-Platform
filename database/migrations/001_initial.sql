CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    display_name VARCHAR(80) NOT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_access_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id BIGINT UNSIGNED NOT NULL,
    key_digest CHAR(128) NOT NULL,
    key_hint VARCHAR(12) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_admin_key_digest (key_digest),
    KEY idx_admin_key_owner (admin_id, revoked_at),
    CONSTRAINT fk_admin_key_owner FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_no VARCHAR(40) NOT NULL,
    display_name VARCHAR(80) NOT NULL,
    note VARCHAR(255) NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_students_no (student_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_access_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    key_digest CHAR(64) NOT NULL,
    key_hint VARCHAR(12) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_student_key_digest (key_digest),
    KEY idx_student_key_owner (student_id, revoked_at),
    CONSTRAINT fk_student_key_owner FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_type ENUM('subjective','practical') NOT NULL,
    title VARCHAR(180) NOT NULL,
    prompt MEDIUMTEXT NOT NULL,
    reference_answer MEDIUMTEXT NOT NULL,
    default_score DECIMAL(7,2) NOT NULL DEFAULT 10.00,
    difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    tags VARCHAR(255) NULL,
    target_url VARCHAR(2048) NULL,
    screenshot_min TINYINT UNSIGNED NOT NULL DEFAULT 0,
    screenshot_max TINYINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','active','disabled') NOT NULL DEFAULT 'draft',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assessment_bank (status, question_type, difficulty),
    FULLTEXT KEY ft_assessment_search (title, prompt, tags),
    CONSTRAINT fk_assessment_creator FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_type ENUM('single','multiple') NOT NULL,
    title VARCHAR(180) NOT NULL,
    prompt MEDIUMTEXT NOT NULL,
    options_json JSON NOT NULL,
    correct_answers_json JSON NOT NULL,
    explanation MEDIUMTEXT NOT NULL,
    difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    tags VARCHAR(255) NULL,
    status ENUM('draft','active','disabled') NOT NULL DEFAULT 'active',
    source_ref VARCHAR(255) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_practice_bank (status, question_type, difficulty),
    FULLTEXT KEY ft_practice_search (title, prompt, tags),
    CONSTRAINT fk_practice_creator FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_exams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    compose_mode ENUM('manual','random') NOT NULL,
    random_rules_json JSON NULL,
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    duration_minutes INT UNSIGNED NULL,
    answer_release ENUM('immediate','after_close','manual') NOT NULL DEFAULT 'after_close',
    result_release ENUM('manual','after_graded') NOT NULL DEFAULT 'manual',
    status ENUM('draft','published','closed','archived') NOT NULL DEFAULT 'draft',
    answers_released_at DATETIME NULL,
    results_released_at DATETIME NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_assessment_exam_window (status, opens_at, closes_at),
    CONSTRAINT fk_exam_creator FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_exam_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id BIGINT UNSIGNED NOT NULL,
    source_question_id BIGINT UNSIGNED NULL,
    display_order INT UNSIGNED NOT NULL,
    question_type ENUM('subjective','practical') NOT NULL,
    title VARCHAR(180) NOT NULL,
    prompt MEDIUMTEXT NOT NULL,
    reference_answer MEDIUMTEXT NOT NULL,
    score DECIMAL(7,2) NOT NULL,
    target_url VARCHAR(2048) NULL,
    screenshot_min TINYINT UNSIGNED NOT NULL DEFAULT 0,
    screenshot_max TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_exam_order (exam_id, display_order),
    CONSTRAINT fk_exam_question_exam FOREIGN KEY (exam_id) REFERENCES assessment_exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_exam_question_source FOREIGN KEY (source_question_id) REFERENCES assessment_questions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exam_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    status ENUM('assigned','in_progress','submitted','returned','graded') NOT NULL DEFAULT 'assigned',
    started_at DATETIME NULL,
    submitted_at DATETIME NULL,
    attempt_version INT UNSIGNED NOT NULL DEFAULT 1,
    total_score DECIMAL(9,2) NULL,
    general_feedback TEXT NULL,
    graded_at DATETIME NULL,
    UNIQUE KEY uq_exam_student (exam_id, student_id),
    KEY idx_assignment_student (student_id, status),
    CONSTRAINT fk_assignment_exam FOREIGN KEY (exam_id) REFERENCES assessment_exams(id) ON DELETE CASCADE,
    CONSTRAINT fk_assignment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS assessment_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assignment_id BIGINT UNSIGNED NOT NULL,
    exam_question_id BIGINT UNSIGNED NOT NULL,
    answer_text MEDIUMTEXT NULL,
    score DECIMAL(7,2) NULL,
    feedback TEXT NULL,
    review_status ENUM('ungraded','graded','review') NOT NULL DEFAULT 'ungraded',
    saved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    graded_at DATETIME NULL,
    UNIQUE KEY uq_assignment_question (assignment_id, exam_question_id),
    CONSTRAINT fk_answer_assignment FOREIGN KEY (assignment_id) REFERENCES assessment_assignments(id) ON DELETE CASCADE,
    CONSTRAINT fk_answer_question FOREIGN KEY (exam_question_id) REFERENCES assessment_exam_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS answer_uploads (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    answer_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(180) NOT NULL,
    storage_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(40) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    width INT UNSIGNED NOT NULL,
    height INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_upload_answer (answer_id),
    CONSTRAINT fk_upload_answer FOREIGN KEY (answer_id) REFERENCES assessment_answers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_sets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    question_count INT UNSIGNED NOT NULL,
    allowed_types SET('single','multiple') NOT NULL DEFAULT 'single,multiple',
    tags_filter VARCHAR(255) NULL,
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    status ENUM('draft','open','closed','archived') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_practice_set_window (status, opens_at, closes_at),
    CONSTRAINT fk_practice_set_creator FOREIGN KEY (created_by) REFERENCES admins(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    practice_set_id BIGINT UNSIGNED NOT NULL,
    student_id BIGINT UNSIGNED NOT NULL,
    question_mode ENUM('sequential','random') NOT NULL,
    status ENUM('in_progress','submitted') NOT NULL DEFAULT 'in_progress',
    correct_count INT UNSIGNED NULL,
    total_count INT UNSIGNED NOT NULL,
    score_percent DECIMAL(5,2) NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME NULL,
    KEY idx_practice_attempt_student (student_id, practice_set_id, started_at),
    CONSTRAINT fk_attempt_set FOREIGN KEY (practice_set_id) REFERENCES practice_sets(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_attempt_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    source_question_id BIGINT UNSIGNED NULL,
    display_order INT UNSIGNED NOT NULL,
    question_type ENUM('single','multiple') NOT NULL,
    title VARCHAR(180) NOT NULL,
    prompt MEDIUMTEXT NOT NULL,
    options_json JSON NOT NULL,
    correct_answers_json JSON NOT NULL,
    explanation MEDIUMTEXT NOT NULL,
    UNIQUE KEY uq_attempt_order (attempt_id, display_order),
    CONSTRAINT fk_attempt_question_attempt FOREIGN KEY (attempt_id) REFERENCES practice_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_attempt_question_source FOREIGN KEY (source_question_id) REFERENCES practice_questions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS practice_attempt_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_question_id BIGINT UNSIGNED NOT NULL,
    selected_answers_json JSON NOT NULL,
    is_correct TINYINT(1) NOT NULL,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_practice_answer_question (attempt_question_id),
    CONSTRAINT fk_practice_answer_question FOREIGN KEY (attempt_question_id) REFERENCES practice_attempt_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_setting_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_role ENUM('system','admin','student') NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_id BIGINT UNSIGNED NULL,
    ip_hash CHAR(64) NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_time (created_at),
    KEY idx_audit_target (target_type, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings(setting_key, setting_value) VALUES
('site_name', '网络安全学生考核平台'),
('site_footer', '网络安全教学考核'),
('default_answer_release', 'after_close')
ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key);

