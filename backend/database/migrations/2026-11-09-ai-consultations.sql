-- AI Assistant consultations (General Doctor AI + specialists).
-- Additive only: existing conversations become active General Doctor consultations,
-- existing messages are kept unchanged.
-- Apply with:  mysql -u root orbitcare < backend/database/migrations/2026-11-09-ai-consultations.sql

ALTER TABLE conversations
    ADD COLUMN specialist ENUM('general', 'respiratory', 'cardiology', 'neurology') NOT NULL DEFAULT 'general' AFTER user_id,
    ADD COLUMN status ENUM('active', 'closed') NOT NULL DEFAULT 'active' AFTER specialist,
    ADD COLUMN referred_from_conversation_id INT UNSIGNED NULL AFTER status,
    ADD COLUMN handoff JSON NULL AFTER referred_from_conversation_id,
    ADD COLUMN updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) AFTER created_at,
    ADD KEY conversations_user_specialist (user_id, specialist, status),
    ADD CONSTRAINT conversations_referral_fk FOREIGN KEY (referred_from_conversation_id) REFERENCES conversations (id) ON DELETE SET NULL;

-- Keep the last activity time of existing conversations.
UPDATE conversations c
SET c.updated_at = COALESCE((SELECT MAX(m.created_at) FROM conversation_messages m WHERE m.conversation_id = c.id), c.created_at);

-- Structured result of an assistant reply (observed data / interpretation / next step).
ALTER TABLE conversation_messages
    ADD COLUMN payload JSON NULL AFTER body;
