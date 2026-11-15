-- Wearable devices (the OrbitCare vest; for now its Wokwi simulation) that send readings to
-- POST /api/device/readings. Each device is bound to one astronaut; only a SHA-256 hash of its
-- token is stored. Every received reading is kept as sent (payload), including fields OrbitCare
-- does not analyse (blood pressure, CO2, radiation, skin temperature, motion).
-- Seconds between samples of a source: BIDMC numerics are 1 Hz; the vest sends every 5 s.
-- The alert engine uses it so "10 seconds out of range" means the same for every source.
ALTER TABLE source_records ADD COLUMN IF NOT EXISTS sample_interval_seconds DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER duration_seconds;

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
