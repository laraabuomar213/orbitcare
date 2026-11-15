-- OrbitCare database schema (MySQL 8 / MariaDB 10.4+)
-- Load with:  mysql -u root < backend/database/schema.sql
-- Then:       mysql -u root orbitcare < backend/database/seed.sql

CREATE DATABASE IF NOT EXISTS orbitcare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE orbitcare;

-- ---------------------------------------------------------------------------
-- People and missions
-- ---------------------------------------------------------------------------

-- role = 'mission_admin' alone grants nothing: admin rights also need admin_approved_at,
-- set by an existing administrator (cli/approve-admin.php).
CREATE TABLE IF NOT EXISTS users (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name            VARCHAR(120) NOT NULL,
    email                VARCHAR(254) NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    role                 ENUM('astronaut', 'mission_admin') NOT NULL,
    admin_approved_at    DATETIME NULL,
    profile_completed_at DATETIME NULL,
    last_login_at        DATETIME NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS astronauts (
    user_id               INT UNSIGNED PRIMARY KEY,
    astronaut_code        VARCHAR(40) NOT NULL,
    age                   TINYINT UNSIGNED NULL,
    height_cm             DECIMAL(5,1) NULL,
    weight_kg             DECIMAL(5,1) NULL,
    -- Which recorded dataset record is replayed for this account (demo data source).
    data_source_record_id VARCHAR(20) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY astronauts_code_unique (astronaut_code),
    CONSTRAINT astronauts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Background that helps interpret measurements (entered by the astronaut at sign-up).
CREATE TABLE IF NOT EXISTS astronaut_medical_background (
    user_id                INT UNSIGNED PRIMARY KEY,
    has_chronic_conditions TINYINT(1) NOT NULL,
    chronic_conditions     TEXT NULL,
    takes_medications      TINYINT(1) NOT NULL,
    has_allergies          TINYINT(1) NOT NULL,
    allergies              TEXT NULL,
    medical_history_notes  TEXT NULL,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT medical_background_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS astronaut_medications (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    purpose    VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT medications_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS missions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(30) NOT NULL,
    name        VARCHAR(120) NOT NULL,
    status      ENUM('planning', 'active', 'completed') NOT NULL DEFAULT 'planning',
    description VARCHAR(500) NULL,
    created_by  INT UNSIGNED NULL,                       -- the admin who created the mission
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY missions_code_unique (code),
    CONSTRAINT missions_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_profiles (
    user_id              INT UNSIGNED PRIMARY KEY,
    job_title            VARCHAR(120) NOT NULL,
    organization         VARCHAR(150) NOT NULL,
    requested_mission_id INT UNSIGNED NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT admin_profiles_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT admin_profiles_mission_fk FOREIGN KEY (requested_mission_id) REFERENCES missions (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Who belongs to which mission. Mission admins only manage missions listed here.
CREATE TABLE IF NOT EXISTS mission_members (
    mission_id  INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    member_role ENUM('astronaut', 'mission_admin') NOT NULL,
    joined_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0,   -- "seen by" in the crew chat
    last_seen_at DATETIME(3) NULL,                          -- presence in the crew status bar
    PRIMARY KEY (mission_id, user_id),
    KEY mission_members_user (user_id),
    CONSTRAINT mission_members_mission_fk FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE CASCADE,
    CONSTRAINT mission_members_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mission_messages (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NOT NULL,
    body       VARCHAR(1000) NOT NULL,
    kind       ENUM('text', 'emergency', 'recovery', 'system', 'health_note') NOT NULL DEFAULT 'text',   -- chat cards
    payload    JSON NULL,                                                              -- ids a card refers to
    created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY mission_messages_mission (mission_id, id),
    CONSTRAINT mission_messages_mission_fk FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE CASCADE,
    CONSTRAINT mission_messages_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_settings (
    user_id    INT UNSIGNED PRIMARY KEY,
    settings   JSON NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT user_settings_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Health data (see docs/DATA_PROVENANCE.md)
-- ---------------------------------------------------------------------------

-- One row per ingested dataset record, with what the ingestion found.
CREATE TABLE IF NOT EXISTS source_records (
    record_id        VARCHAR(20) PRIMARY KEY,
    source           VARCHAR(80) NOT NULL,
    source_file      VARCHAR(120) NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL,
    sample_interval_seconds DECIMAL(5,2) NOT NULL DEFAULT 1.00, -- BIDMC numerics: 1 Hz; vest: every 5 s
    subject_age      VARCHAR(10) NULL,
    subject_gender   VARCHAR(10) NULL,
    care_unit        VARCHAR(20) NULL,
    rows_read        INT UNSIGNED NOT NULL,
    values_stored    INT UNSIGNED NOT NULL,
    values_missing   INT UNSIGNED NOT NULL,
    values_rejected  INT UNSIGNED NOT NULL,
    ingested_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Original measurements exactly as recorded. Never updated or deleted (see triggers below).
-- original_timestamp = seconds from the start of the recording ("Time [s]" in the source CSV).
CREATE TABLE IF NOT EXISTS raw_measurements (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source              VARCHAR(80) NOT NULL,
    source_record_id    VARCHAR(20) NOT NULL,
    source_file         VARCHAR(120) NOT NULL,
    source_row          INT UNSIGNED NOT NULL,
    original_timestamp  DECIMAL(10,3) NOT NULL,
    metric              ENUM('heart_rate', 'pulse_rate', 'respiratory_rate', 'spo2') NOT NULL,
    value               DECIMAL(7,2) NOT NULL,
    unit                VARCHAR(20) NOT NULL,
    ingestion_timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_status   ENUM('validated') NOT NULL DEFAULT 'validated',
    UNIQUE KEY raw_measurements_unique (source, source_record_id, original_timestamp, metric),
    KEY raw_measurements_replay (source_record_id, original_timestamp),
    CONSTRAINT raw_measurements_record_fk FOREIGN KEY (source_record_id) REFERENCES source_records (record_id)
) ENGINE=InnoDB;

CREATE TRIGGER IF NOT EXISTS raw_measurements_no_update BEFORE UPDATE ON raw_measurements
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_measurements is immutable';

CREATE TRIGGER IF NOT EXISTS raw_measurements_no_delete BEFORE DELETE ON raw_measurements
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'raw_measurements is immutable';

-- A replay of one recorded record for one astronaut account.
CREATE TABLE IF NOT EXISTS replay_sessions (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL,
    source_record_id      VARCHAR(20) NOT NULL,
    started_at            DATETIME(3) NOT NULL,
    speed                 DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    status                ENUM('running', 'completed', 'stopped') NOT NULL DEFAULT 'running',
    last_processed_offset DECIMAL(10,3) NOT NULL DEFAULT -1,
    engine_state          JSON NULL,
    completed_at          DATETIME(3) NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY replay_sessions_user (user_id, status),
    CONSTRAINT replay_sessions_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT replay_sessions_record_fk FOREIGN KEY (source_record_id) REFERENCES source_records (record_id)
) ENGINE=InnoDB;

-- Derived results. Each row points at the raw measurement it was computed from.
CREATE TABLE IF NOT EXISTS processed_measurements (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    raw_measurement_id BIGINT UNSIGNED NOT NULL,
    replay_session_id  INT UNSIGNED NOT NULL,
    user_id            INT UNSIGNED NOT NULL,
    metric             ENUM('heart_rate', 'pulse_rate', 'respiratory_rate', 'spo2') NOT NULL,
    classification     ENUM('GREEN', 'YELLOW', 'RED') NOT NULL,
    threshold_version  VARCHAR(30) NOT NULL,
    replayed_at        DATETIME(3) NOT NULL,
    processed_at       DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    UNIQUE KEY processed_unique (replay_session_id, raw_measurement_id),
    KEY processed_user_time (user_id, metric, replayed_at),
    CONSTRAINT processed_raw_fk FOREIGN KEY (raw_measurement_id) REFERENCES raw_measurements (id),
    CONSTRAINT processed_session_fk FOREIGN KEY (replay_session_id) REFERENCES replay_sessions (id) ON DELETE CASCADE,
    CONSTRAINT processed_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Alerts created by the deterministic alert engine. Each points at the measurement
-- and the rule that caused it.
CREATE TABLE IF NOT EXISTS alerts (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id               INT UNSIGNED NOT NULL,
    mission_id            INT UNSIGNED NULL,
    replay_session_id     INT UNSIGNED NULL,
    metric                ENUM('heart_rate', 'pulse_rate', 'respiratory_rate', 'spo2') NOT NULL,
    observed_value        DECIMAL(7,2) NOT NULL,
    unit                  VARCHAR(20) NOT NULL,
    rule                  VARCHAR(255) NOT NULL,
    threshold_version     VARCHAR(30) NOT NULL,
    severity              ENUM('YELLOW', 'RED') NOT NULL,
    title                 VARCHAR(160) NOT NULL,
    explanation           VARCHAR(500) NOT NULL,
    recommended_action    VARCHAR(500) NOT NULL,
    source_measurement_id BIGINT UNSIGNED NOT NULL,
    first_measurement_id  BIGINT UNSIGNED NOT NULL,
    occurred_at           DATETIME(3) NOT NULL,
    status                ENUM('active', 'acknowledged', 'resolved') NOT NULL DEFAULT 'active',
    acknowledged_by       INT UNSIGNED NULL,
    acknowledged_at       DATETIME NULL,
    resolved_at           DATETIME(3) NULL,
    resolution_note       VARCHAR(255) NULL,
    is_test_fixture       TINYINT(1) NOT NULL DEFAULT 0,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY alerts_user_status (user_id, status),
    KEY alerts_mission (mission_id, status),
    CONSTRAINT alerts_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT alerts_mission_fk FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE SET NULL,
    CONSTRAINT alerts_session_fk FOREIGN KEY (replay_session_id) REFERENCES replay_sessions (id) ON DELETE SET NULL,
    CONSTRAINT alerts_source_fk FOREIGN KEY (source_measurement_id) REFERENCES raw_measurements (id),
    CONSTRAINT alerts_first_fk FOREIGN KEY (first_measurement_id) REFERENCES raw_measurements (id),
    CONSTRAINT alerts_ack_fk FOREIGN KEY (acknowledged_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Wearable devices (the OrbitCare vest; for now its Wokwi simulation) that send readings to
-- POST /api/device/readings. Each device is bound to one astronaut; only a SHA-256 hash of its
-- token is stored. Every received reading is kept as sent (payload), including fields OrbitCare
-- does not analyse (blood pressure, CO2, radiation, skin temperature, motion).
CREATE TABLE IF NOT EXISTS devices (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id         VARCHAR(64) NOT NULL,                 -- the device's own name, e.g. orbitcare_vest_01
    user_id           INT UNSIGNED NOT NULL,                -- the astronaut wearing it
    token_hash        CHAR(64) NOT NULL,                    -- SHA-256 of the device token
    kind              ENUM('simulated', 'hardware') NOT NULL DEFAULT 'simulated',
    current_record_id VARCHAR(20) NULL,                     -- source_records row of the current live stream
    last_seen_at      DATETIME(3) NULL,
    revoked_at        DATETIME NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY devices_device_id (device_id),
    CONSTRAINT devices_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_readings (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_pk         INT UNSIGNED NOT NULL,
    client_reading_id VARCHAR(80) NOT NULL,                 -- unique per device: retries are not stored twice
    received_at       DATETIME(3) NOT NULL,
    device_timestamp  DATETIME NULL,                        -- the device's own clock, if it had one
    record_id         VARCHAR(20) NULL,                     -- live stream the vitals went into
    status            ENUM('received', 'analysed', 'late', 'no_vitals') NOT NULL DEFAULT 'received',
    payload           JSON NOT NULL,
    UNIQUE KEY device_readings_client (device_pk, client_reading_id),
    KEY device_readings_time (device_pk, received_at),
    CONSTRAINT device_readings_device_fk FOREIGN KEY (device_pk) REFERENCES devices (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- AI (kept separate from measurements)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS ai_analysis (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                INT UNSIGNED NOT NULL,
    analysis_type          VARCHAR(40) NOT NULL,
    agent                  VARCHAR(40) NOT NULL,
    provider               VARCHAR(40) NOT NULL,
    input_measurement_ids  JSON NULL,
    input_alert_id         INT UNSIGNED NULL,
    output                 JSON NULL,
    status                 ENUM('not_configured', 'success', 'error') NOT NULL,
    error_message          VARCHAR(255) NULL,
    created_at             DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY ai_analysis_user (user_id, created_at),
    CONSTRAINT ai_analysis_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT ai_analysis_alert_fk FOREIGN KEY (input_alert_id) REFERENCES alerts (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- One consultation with one AI (General Doctor or a specialist). A specialist consultation
-- opened from a General Doctor recommendation keeps a structured handoff of that context.
CREATE TABLE IF NOT EXISTS conversations (
    id                            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id                       INT UNSIGNED NOT NULL,
    specialist                    ENUM('general', 'respiratory', 'cardiology', 'neurology') NOT NULL DEFAULT 'general',
    status                        ENUM('active', 'closed') NOT NULL DEFAULT 'active',
    referred_from_conversation_id INT UNSIGNED NULL,
    handoff                       JSON NULL,
    created_at                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                    DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY conversations_user (user_id),
    KEY conversations_user_specialist (user_id, specialist, status),
    CONSTRAINT conversations_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT conversations_referral_fk FOREIGN KEY (referred_from_conversation_id) REFERENCES conversations (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- payload = structured assistant result (observed data / AI interpretation / next step).
CREATE TABLE IF NOT EXISTS conversation_messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT UNSIGNED NOT NULL,
    sender          ENUM('user', 'assistant', 'system') NOT NULL,
    body            TEXT NOT NULL,
    payload         JSON NULL,
    ai_analysis_id  INT UNSIGNED NULL,
    created_at      DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY conversation_messages_conversation (conversation_id, id),
    CONSTRAINT conversation_messages_conversation_fk FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT conversation_messages_analysis_fk FOREIGN KEY (ai_analysis_id) REFERENCES ai_analysis (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Self-reported indicators (daily check-in, weekly PHQ-4)
-- ---------------------------------------------------------------------------

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

-- Cache for official medical sources (MedlinePlus, openFDA); NLM asks for 12–24 h caching.
CREATE TABLE IF NOT EXISTS medical_source_cache (
    cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,   -- e.g. "medlineplus:dizziness", "openfda:ibuprofen"
    payload     JSON NOT NULL,                       -- the extracted facts (or {"found": false})
    fetched_at  DATETIME NOT NULL
) ENGINE=InnoDB;

-- Alertness Check: 3-minute PVT-B reaction test + optional on-device camera eye signs.
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

-- ---------------------------------------------------------------------------
-- Security and auditing
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id   VARCHAR(40) NULL,
    details     JSON NULL,
    client_hash CHAR(64) NULL,
    created_at  DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    KEY audit_logs_user (user_id, created_at),
    KEY audit_logs_action (action, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rate_limit_hits (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(40) NOT NULL,
    client_hash CHAR(64) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY rate_limit_lookup (bucket, client_hash, created_at)
) ENGINE=InnoDB;

-- Crew emergency alarms (critical alert or dangerous symptom → rings for the mission crew until each responds).
CREATE TABLE IF NOT EXISTS crew_emergencies (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id    INT UNSIGNED NOT NULL,
    astronaut_id  INT UNSIGNED NOT NULL,                 -- the astronaut in danger
    kind          ENUM('vital', 'symptom') NOT NULL,     -- critical measurement alert / reported symptom
    alert_id      INT UNSIGNED NULL,                     -- kind = vital
    symptom_key   VARCHAR(40) NULL,                      -- kind = symptom (SymptomDetector key)
    created_at    DATETIME(3) NOT NULL,
    resolved_at   DATETIME(3) NULL,                      -- the alert resolved (back in range)
    trigger_text  VARCHAR(2000) NULL,                    -- the astronaut's own words (never shown to the crew as is)
    report        JSON NULL,                             -- AI/rules report: what they feel now + what the crew should do
    report_source ENUM('ai', 'rules') NULL,
    report_claimed_at DATETIME NULL,
    KEY crew_emergencies_mission_time (mission_id, created_at),
    CONSTRAINT crew_emergencies_mission_fk FOREIGN KEY (mission_id) REFERENCES missions (id) ON DELETE CASCADE,
    CONSTRAINT crew_emergencies_astronaut_fk FOREIGN KEY (astronaut_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT crew_emergencies_alert_fk FOREIGN KEY (alert_id) REFERENCES alerts (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Each crew member's answer ("seen" / "on my way"); the alarm stops for them once they answer.
CREATE TABLE IF NOT EXISTS crew_emergency_responses (
    emergency_id  INT UNSIGNED NOT NULL,
    user_id       INT UNSIGNED NOT NULL,
    response      ENUM('seen', 'on_my_way') NOT NULL,
    responded_at  DATETIME(3) NOT NULL,
    PRIMARY KEY (emergency_id, user_id),
    CONSTRAINT crew_emergency_responses_emergency_fk FOREIGN KEY (emergency_id) REFERENCES crew_emergencies (id) ON DELETE CASCADE,
    CONSTRAINT crew_emergency_responses_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------- Open recorded data for the other vest / headband sensors (cli/ingest-open-sensors.php) ----------
-- One recording from one open dataset, with everything needed to cite it.
CREATE TABLE IF NOT EXISTS sensor_sources (
    source_id         VARCHAR(60) NOT NULL PRIMARY KEY,     -- e.g. "noneeg:Subject3", "radlab:ISS:DosTel1:2022-04-01"
    dataset           VARCHAR(160) NOT NULL,
    dataset_url       VARCHAR(255) NOT NULL,
    licence           VARCHAR(120) NOT NULL,
    description       VARCHAR(255) NOT NULL,
    recording_start   DATETIME NULL,                        -- real UTC start where the source has one (RadLab)
    duration_seconds  INT UNSIGNED NOT NULL,
    source_file       VARCHAR(160) NOT NULL,
    values_stored     INT UNSIGNED NOT NULL,
    values_rejected   INT UNSIGNED NOT NULL,
    ingested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Samples, as recorded or with the stated method (e.g. 1-second mean of an 8 Hz signal).
-- offset_seconds = seconds from the start of the recording. Never updated or deleted.
CREATE TABLE IF NOT EXISTS sensor_samples (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id       VARCHAR(60) NOT NULL,
    sensor          ENUM('skin_temperature', 'skin_conductance', 'movement', 'radiation_dose_rate') NOT NULL,
    offset_seconds  DECIMAL(10,3) NOT NULL,
    value           DECIMAL(12,4) NOT NULL,
    unit            VARCHAR(20) NOT NULL,
    method          VARCHAR(60) NOT NULL,
    UNIQUE KEY sensor_samples_unique (source_id, sensor, offset_seconds),
    CONSTRAINT sensor_samples_source_fk FOREIGN KEY (source_id) REFERENCES sensor_sources (source_id)
) ENGINE=InnoDB;

CREATE TRIGGER IF NOT EXISTS sensor_samples_no_update BEFORE UPDATE ON sensor_samples
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sensor_samples is immutable';

CREATE TRIGGER IF NOT EXISTS sensor_samples_no_delete BEFORE DELETE ON sensor_samples
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'sensor_samples is immutable';
