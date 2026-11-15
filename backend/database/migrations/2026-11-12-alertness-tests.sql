-- Alertness Check results (3-minute PVT-B reaction test + optional camera eye signs).
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-12-alertness-tests.sql

CREATE TABLE IF NOT EXISTS alertness_tests (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    taken_at           DATETIME(3) NOT NULL,                -- UTC, when the test finished
    test_version       VARCHAR(20) NOT NULL,                -- config/alertness.php version
    duration_seconds   SMALLINT UNSIGNED NOT NULL,
    stimuli            SMALLINT UNSIGNED NOT NULL,          -- numbers shown
    valid_responses    SMALLINT UNSIGNED NOT NULL,          -- RT ≥ 100 ms
    lapses             SMALLINT UNSIGNED NOT NULL,          -- RT ≥ 355 ms or no response
    false_starts       SMALLINT UNSIGNED NOT NULL,          -- response without a number, or RT < 100 ms
    median_rt_ms       DECIMAL(7,1) NULL,
    fastest10_rt_ms    DECIMAL(7,1) NULL,
    reaction_times     JSON NOT NULL,                       -- every response to a number, in ms, in order
    camera_used        TINYINT(1) NOT NULL DEFAULT 0,
    face_seconds       DECIMAL(6,1) NULL,                   -- time the eyes were found in the image
    blinks_per_minute  DECIMAL(5,1) NULL,                   -- estimate, on-device; NULL without enough camera data
    perclos_pct        DECIMAL(5,2) NULL,                   -- % of time eyes ≥ 80 % closed (estimate)
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY alertness_tests_user_time (user_id, taken_at),
    CONSTRAINT alertness_tests_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
