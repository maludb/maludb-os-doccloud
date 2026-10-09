-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "doccloud" first). A RECORD'S HISTORY IS THIS TABLE (design §6.4): there is no audit table;
-- `before`/`after` carry what the timeline shows: ids, names, counts, a kind, a status, a byte size, 120 characters of a search
-- query at most. A DOCUMENT'S TEXT, A PASSAGE, A TOKEN, A LINK OR A PASSWORD IS NEVER HERE (design §6.4); a key or a link is
-- named by its label.
--
-- THE ESTATE'S DATA MODEL (design §6.4): the contract table (memory.md / Consultant Tracking db/002) with DocCloud's audit
-- keys APPENDED: library_id, document_id, folder_id — every event about a library, a folder, a document, a version, a share, a
-- link, a tag or a graph job carries the records it concerns, so a document's trail, a folder's and a library's are one index
-- each; token_id as Knowledge db/002 appended it (the key an API call ran under). No FK: history outlives a record. Consultant
-- Tracking's own appended keys are its domain and are not copied.
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns (a share to it, a group mirroring it)
    location_id     bigint,                        -- the contract's (unused: scope none)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.4): DocCloud's audit keys (no FK — history outlives a record)
    library_id      bigint,                        -- the library the event concerns
    document_id     bigint,                        -- the document (a version's, a share's, a link's, a tag's, a graph job's)
    folder_id       bigint,                        -- the folder (its own event, a share on it, a link to it, a file request into it)
    token_id        bigint                         -- the key (mcp_access_tokens.id) a token-API event ran under; its LABEL is in `after`, never the key
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_library_idx  ON activity_log (library_id, occurred_at DESC) WHERE library_id IS NOT NULL;
CREATE INDEX activity_log_document_idx ON activity_log (document_id, occurred_at DESC) WHERE document_id IS NOT NULL;
CREATE INDEX activity_log_folder_idx   ON activity_log (folder_id, occurred_at DESC) WHERE folder_id IS NOT NULL;
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO doccloud_rw;
REVOKE ALL ON activity_log FROM doccloud_records_ro, doccloud_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO doccloud_rw;

COMMIT;
