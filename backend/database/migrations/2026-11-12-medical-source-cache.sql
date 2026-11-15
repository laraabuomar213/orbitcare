-- Cache for official medical sources (MedlinePlus, openFDA): NLM asks clients to cache for 12–24 h,
-- and the cache keeps the last answers available when the link to the ground is down.
-- Additive only. Apply with:
--   mysql -u root orbitcare < backend/database/migrations/2026-11-12-medical-source-cache.sql

CREATE TABLE IF NOT EXISTS medical_source_cache (
    cache_key   VARCHAR(191) NOT NULL PRIMARY KEY,   -- e.g. "medlineplus:dizziness", "openfda:ibuprofen"
    payload     JSON NOT NULL,                       -- the extracted facts (or {"found": false})
    fetched_at  DATETIME NOT NULL
) ENGINE=InnoDB;
