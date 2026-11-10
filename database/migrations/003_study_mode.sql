ALTER TABLE practice_questions
    MODIFY question_type ENUM('single','multiple','judge','fill') NOT NULL,
    MODIFY explanation MEDIUMTEXT NULL,
    ADD COLUMN source_order INT UNSIGNED NULL AFTER source_ref,
    ADD COLUMN section VARCHAR(80) NULL AFTER source_order,
    ADD COLUMN chapter VARCHAR(120) NULL AFTER section,
    ADD KEY idx_practice_source_order (source_order),
    ADD KEY idx_practice_taxonomy (section, chapter, question_type);

ALTER TABLE practice_sets
    MODIFY allowed_types SET('single','multiple','judge','fill') NOT NULL DEFAULT 'single,multiple,judge';

ALTER TABLE practice_attempt_questions
    MODIFY question_type ENUM('single','multiple','judge','fill') NOT NULL,
    MODIFY explanation MEDIUMTEXT NULL;

CREATE TABLE IF NOT EXISTS study_rounds (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    round_number INT UNSIGNED NOT NULL,
    status ENUM('in_progress','completed') NOT NULL DEFAULT 'in_progress',
    total_count INT UNSIGNED NOT NULL,
    completed_count INT UNSIGNED NOT NULL DEFAULT 0,
    correct_count INT UNSIGNED NOT NULL DEFAULT 0,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    UNIQUE KEY uq_study_round_student_number (student_id, round_number),
    KEY idx_study_round_student_status (student_id, status),
    CONSTRAINT fk_study_round_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS study_round_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    round_id BIGINT UNSIGNED NOT NULL,
    source_question_id BIGINT UNSIGNED NULL,
    display_order INT UNSIGNED NOT NULL,
    source_order INT UNSIGNED NULL,
    section VARCHAR(80) NULL,
    chapter VARCHAR(120) NULL,
    question_type ENUM('single','multiple','judge','fill') NOT NULL,
    title VARCHAR(180) NOT NULL,
    prompt MEDIUMTEXT NOT NULL,
    options_json JSON NOT NULL,
    correct_answers_json JSON NOT NULL,
    explanation MEDIUMTEXT NULL,
    answer_json JSON NULL,
    is_correct TINYINT(1) NULL,
    answered_at DATETIME NULL,
    self_reviewed_at DATETIME NULL,
    UNIQUE KEY uq_study_round_order (round_id, display_order),
    KEY idx_study_round_answered (round_id, answered_at, display_order),
    KEY idx_study_source_question (source_question_id),
    CONSTRAINT fk_study_item_round FOREIGN KEY (round_id) REFERENCES study_rounds(id) ON DELETE CASCADE,
    CONSTRAINT fk_study_item_source FOREIGN KEY (source_question_id) REFERENCES practice_questions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_question_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    correct_count INT UNSIGNED NOT NULL DEFAULT 0,
    wrong_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_answer_json JSON NULL,
    last_is_correct TINYINT(1) NULL,
    favorite TINYINT(1) NOT NULL DEFAULT 0,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    review_stage TINYINT UNSIGNED NOT NULL DEFAULT 0,
    first_wrong_at DATETIME NULL,
    last_answered_at DATETIME NULL,
    next_review_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_student_question_progress (student_id, question_id),
    KEY idx_student_review (student_id, needs_review, next_review_at),
    KEY idx_student_favorite (student_id, favorite),
    KEY idx_student_wrong (student_id, wrong_count, last_is_correct),
    CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_progress_question FOREIGN KEY (question_id) REFERENCES practice_questions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_question_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    round_item_id BIGINT UNSIGNED NULL,
    context ENUM('study','review') NOT NULL,
    answer_json JSON NOT NULL,
    is_correct TINYINT(1) NOT NULL,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_answer_history_student_question (student_id, question_id, answered_at),
    KEY idx_answer_history_student_time (student_id, answered_at),
    CONSTRAINT fk_answer_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    CONSTRAINT fk_answer_history_question FOREIGN KEY (question_id) REFERENCES practice_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_answer_history_round_item FOREIGN KEY (round_item_id) REFERENCES study_round_items(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
