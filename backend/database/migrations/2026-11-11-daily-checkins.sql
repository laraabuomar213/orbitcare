-- Daily check-ins (self-reported indicators) and the weekly PHQ-4 wellbeing check.
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-11-daily-checkins.sql

-- One check-in per astronaut per mission day (UTC). Values are exactly what the astronaut entered.
CREATE TABLE IF NOT EXISTS daily_checkins (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id            INT UNSIGNED NOT NULL,
    checkin_date       DATE NOT NULL,
    sleep_hours        DECIMAL(3,1) NOT NULL,
    sleep_quality      TINYINT UNSIGNED NOT NULL,          -- 1 very poor … 5 excellent
    fatigue_level      TINYINT UNSIGNED NOT NULL,          -- Samn-Perelli 1 … 7
    resistance_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    aerobic_minutes    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    radiation_dose_msv DECIMAL(8,3) NULL,                  -- optional dosimeter reading
    notes              VARCHAR(500) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY daily_checkins_user_date (user_id, checkin_date),
    CONSTRAINT daily_checkins_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Symptoms selected in a check-in ('none' is stored explicitly so "no symptoms" ≠ "not answered").
CREATE TABLE IF NOT EXISTS checkin_symptoms (
    checkin_id INT UNSIGNED NOT NULL,
    symptom    VARCHAR(40) NOT NULL,
    PRIMARY KEY (checkin_id, symptom),
    CONSTRAINT checkin_symptoms_checkin_fk FOREIGN KEY (checkin_id) REFERENCES daily_checkins (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Weekly PHQ-4: items scored 0–3; PHQ-2 (items 1–2) = depression, GAD-2 (items 3–4) = anxiety.
CREATE TABLE IF NOT EXISTS wellbeing_checks (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    completed_on     DATE NOT NULL,
    item_1           TINYINT UNSIGNED NOT NULL,
    item_2           TINYINT UNSIGNED NOT NULL,
    item_3           TINYINT UNSIGNED NOT NULL,
    item_4           TINYINT UNSIGNED NOT NULL,
    depression_score TINYINT UNSIGNED NOT NULL,
    anxiety_score    TINYINT UNSIGNED NOT NULL,
    total_score      TINYINT UNSIGNED NOT NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY wellbeing_user_date (user_id, completed_on),
    CONSTRAINT wellbeing_checks_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;
