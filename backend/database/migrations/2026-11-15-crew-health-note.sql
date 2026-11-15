-- Adds the calm crew "health note" chat card for sustained YELLOW alerts (not an emergency, no alarm).
-- The AI/rules report posts this to the crew chat so the crew knows what is happening and how to help.
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-15-crew-health-note.sql

ALTER TABLE mission_messages
    MODIFY kind ENUM('text', 'emergency', 'recovery', 'system', 'health_note') NOT NULL DEFAULT 'text';
