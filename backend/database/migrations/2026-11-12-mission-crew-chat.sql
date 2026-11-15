-- Missions created by admins + crew chat with emergency cards, "seen by" and presence.
-- Additive only (new columns; existing rows keep working). Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-12-mission-crew-chat.sql

-- Each mission belongs to the admin who created it.
ALTER TABLE missions
    ADD COLUMN created_by INT UNSIGNED NULL AFTER description,
    ADD CONSTRAINT missions_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL;

-- Existing missions: owned by their (approved) mission admin, if they have one.
UPDATE missions m SET created_by = (
    SELECT mm.user_id FROM mission_members mm JOIN users u ON u.id = mm.user_id
    WHERE mm.mission_id = m.id AND mm.member_role = 'mission_admin' AND u.admin_approved_at IS NOT NULL
    ORDER BY mm.joined_at LIMIT 1
) WHERE created_by IS NULL;

-- "Seen by" (last message each member has seen) and presence in the crew status bar.
ALTER TABLE mission_members
    ADD COLUMN last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN last_seen_at DATETIME(3) NULL;

-- Chat cards: 'emergency' (posted from the astronaut's account when they are in danger),
-- 'recovery' (back in range), 'system' (crew changes). payload holds the ids the card refers to.
ALTER TABLE mission_messages
    ADD COLUMN kind ENUM('text', 'emergency', 'recovery', 'system') NOT NULL DEFAULT 'text' AFTER body,
    ADD COLUMN payload JSON NULL AFTER kind;

-- The AI situation report for the crew: what the astronaut feels NOW + what the crew should do.
ALTER TABLE crew_emergencies
    ADD COLUMN trigger_text VARCHAR(2000) NULL,          -- the astronaut's own words (never shown to the crew as is)
    ADD COLUMN report JSON NULL,
    ADD COLUMN report_source ENUM('ai', 'rules') NULL,
    ADD COLUMN report_claimed_at DATETIME NULL;           -- one member's poll generates it, once
