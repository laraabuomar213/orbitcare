-- Open recorded data for the vest / headband sensors that the BIDMC recordings do not cover.
--   NASA OSDR RadLab   → personal dosimeter (ISS DosTel absorbed dose rate, real station data)
--   PhysioNet noneeg   → skin temperature, skin conductance (GSR) and movement (wrist sensors, healthy volunteers)
-- Filled by: php cli/ingest-open-sensors.php   (see docs/DATA_PROVENANCE.md, "Open sensor data")
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-15-open-sensor-data.sql

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
