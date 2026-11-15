-- Crew emergency alarms: a critical (RED) alert or a dangerous symptom reported in the AI chat
-- rings for the whole mission crew until each member responds. Only the name and the problem are
-- shared with the crew — never the medical record.
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-12-crew-emergencies.sql

CREATE TABLE IF NOT EXISTS crew_emergencies (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mission_id    INT UNSIGNED NOT NULL,
    astronaut_id  INT UNSIGNED NOT NULL,                 -- the astronaut in danger
    kind          ENUM('vital', 'symptom') NOT NULL,     -- critical measurement alert / reported symptom
    alert_id      INT UNSIGNED NULL,                     -- kind = vital
    symptom_key   VARCHAR(40) NULL,                      -- kind = symptom (SymptomDetector key)
    created_at    DATETIME(3) NOT NULL,
    resolved_at   DATETIME(3) NULL,                      -- the alert resolved (back in range)
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
