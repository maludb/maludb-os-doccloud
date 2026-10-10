-- db/024 — files of any size (docs/build-specs/large-uploads.md, 2026-10-10: the owner's "a file over 2 GB was refused, which is not
-- acceptable for this application"). Additive.
--
-- 1. dc_settings.max_document_bytes becomes OPTIONAL: NULL = no per-file ceiling (the new default); a number is a ceiling an admin chose.
--    The 2 GB seeded by db/005 (D9) is cleared — it was the default, never a choice. The free space of the store is the real limit now
--    (checked at the start of every upload and at every part, in PHP). dc_version_add's re-check (`p_byte_size > dc_setting_int(...)`)
--    is false against NULL, so it needs no change.
-- 2. upload_sessions learns the chunk-at-offset upload (KineticSeas' php-chunk-upload, the owner's reference): every part is written at
--    its place in ONE file (uploads/<session>/data) instead of a file per part assembled at the finish — no second copy of a large file,
--    no long finish. `writes_at_offset` marks the sessions written that way (a session started by the code before this one is refused
--    with "start again", never mixed); `hashed_parts` + `hash_state` carry the sha256 forward as the parts arrive in order (PHP's
--    HashContext serialized, base64), so the finish hashes at most the last few parts; `resume_key` lets the same person pick the same
--    file again and carry on (the browser's sha256 of name, size, modified time and the first and last megabyte).

ALTER TABLE dc_settings ALTER COLUMN max_document_bytes DROP NOT NULL;
ALTER TABLE dc_settings ALTER COLUMN max_document_bytes SET DEFAULT NULL;
ALTER TABLE dc_settings DROP CONSTRAINT IF EXISTS dc_settings_max_document_bytes_check;
ALTER TABLE dc_settings ADD CONSTRAINT dc_settings_max_document_bytes_check CHECK (max_document_bytes IS NULL OR max_document_bytes >= 1048576);
COMMENT ON COLUMN dc_settings.max_document_bytes IS 'The per-file ceiling an admin chose; NULL = none (db/024). DC_MAX_DOCUMENT_BYTES may only lower it. The store''s free space always limits.';
UPDATE dc_settings SET max_document_bytes = NULL WHERE max_document_bytes = 2147483648;

ALTER TABLE upload_sessions ADD COLUMN IF NOT EXISTS writes_at_offset boolean NOT NULL DEFAULT false;
ALTER TABLE upload_sessions ADD COLUMN IF NOT EXISTS hashed_parts integer NOT NULL DEFAULT 0;
ALTER TABLE upload_sessions ADD COLUMN IF NOT EXISTS hash_state text;
ALTER TABLE upload_sessions ADD COLUMN IF NOT EXISTS resume_key text;
DO $$ BEGIN
    ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_hashed_parts_check CHECK (hashed_parts >= 0 AND hashed_parts <= parts_total);
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
DO $$ BEGIN
    ALTER TABLE upload_sessions ADD CONSTRAINT upload_sessions_resume_key_check CHECK (resume_key IS NULL OR resume_key ~ '^[0-9a-f]{64}$');
EXCEPTION WHEN duplicate_object THEN NULL; END $$;
CREATE INDEX IF NOT EXISTS upload_sessions_resume_idx ON upload_sessions (member_id, folder_id, resume_key) WHERE status = 'open' AND resume_key IS NOT NULL;
COMMENT ON COLUMN upload_sessions.writes_at_offset IS 'Parts written at their offset in uploads/<id>/data (db/024); false = a session of the per-part-file layout, refused with "start again".';
COMMENT ON COLUMN upload_sessions.hashed_parts IS 'Parts 1..n already fed to the running sha256 (db/024).';
COMMENT ON COLUMN upload_sessions.hash_state IS 'The running sha256: PHP''s serialized HashContext, base64 (db/024). Written only by the application.';
COMMENT ON COLUMN upload_sessions.resume_key IS 'The browser''s sha256 of name, size, modified time, first and last MB: the same person choosing the same file again resumes this session (db/024).';
