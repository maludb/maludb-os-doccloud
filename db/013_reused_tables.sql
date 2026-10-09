-- 013: THE ESTATE'S TABLES this application keeps rows of — notes (comments), notifications and the outbox, agent dispatches,
-- exports and worker passes — and DocCloud's own small ones: favourites, saved searches, the search log, the Clerk's proposals
-- (design §6.3, §6.4).
--
-- THE ESTATE'S DATA MODEL (design §6.4):
--   notes — General Ledger db/009 CANONICAL verbatim; record_type widened to document | folder.
--   notifications, notification_prefs, notification_outbox — GL db/014 canonical in Spaces db/012's member-only form (every
--     recipient is a member: member_id NOT NULL, no external-party column, the pair CHECK dropped — the recorded substitution);
--     the `kind` lists are ours; notifications APPENDS document_id, folder_id, actor_member_id.
--   agent_dispatches — GL db/014 = CT db/013 CANONICAL verbatim; `kind` widened; APPENDS document_id, folder_id.
--   exports — Spaces db/016 (+ db/023 params) with the Spaces-specific keyed columns (page_id, space_id, channel_id,
--     include_subpages) replaced by folder_id and document_ids — recorded as the substitution a file store needs; `kind`
--     widened with 'folder' and 'documents'. worker_passes — Spaces db/016 verbatim.
--   favourites, saved_searches, search_log, clerk_proposals — new (Projects' saved_filters and Help Desk's saved_views bind
--     to a project or a queue; this binds to nothing).
BEGIN;

CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('document', 'folder')),
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('share_received', 'request_received', 'link_opened', 'version_added', 'comment', 'lock_broken', 'export_ready',
                                               'quota_near', 'proposal', 'monday_note', 'ownership_received', 'graph_budget', 'extraction_failed')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.3)
    document_id  bigint REFERENCES documents(id) ON DELETE CASCADE,
    folder_id    bigint REFERENCES folders(id) ON DELETE CASCADE,
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);
CREATE INDEX notifications_member_all_idx ON notifications (member_id, created_at DESC);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,
    kinds          text[] NOT NULL DEFAULT '{share_received,request_received,comment,lock_broken,export_ready,quota_near,proposal,monday_note,ownership_received}',
    text_kinds     text[] NOT NULL DEFAULT '{request_received}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);
CREATE INDEX notification_outbox_record_idx ON notification_outbox (record_type, record_id);

-- One notification: the bell row, and the outbox rows the person's preferences ask for (the worker delivers).
CREATE OR REPLACE FUNCTION dc_notify(p_member_id bigint, p_kind text, p_title text, p_body text, p_record_type text DEFAULT NULL, p_record_id bigint DEFAULT NULL,
                                     p_document_id bigint DEFAULT NULL, p_folder_id bigint DEFAULT NULL, p_actor bigint DEFAULT NULL, p_dedupe text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; p notification_prefs%ROWTYPE; em citext;
BEGIN
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe) THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, kind, title, body, record_type, record_id, document_id, folder_id, actor_member_id)
    VALUES (p_member_id, p_kind, p_title, p_body, p_record_type, p_record_id, p_document_id, p_folder_id, p_actor) RETURNING id INTO nid;
    SELECT * INTO p FROM notification_prefs WHERE member_id = p_member_id;
    IF NOT FOUND THEN INSERT INTO notification_prefs (member_id) VALUES (p_member_id) RETURNING * INTO p; END IF;
    SELECT email INTO em FROM members WHERE id = p_member_id;
    IF p.email_enabled AND p_kind = ANY (p.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, p_dedupe, p_title, COALESCE(p_body, p_title)) ON CONFLICT DO NOTHING;
    END IF;
    IF p.text_enabled AND p_kind = ANY (p.text_kinds) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, p_title, left(COALESCE(p_body, p_title), 300)) ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('untagged_batch', 'request_received', 'duplicates_found', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    -- appended (design §6.3)
    document_id      bigint REFERENCES documents(id) ON DELETE SET NULL,
    folder_id        bigint REFERENCES folders(id) ON DELETE SET NULL
);
CREATE INDEX agent_dispatches_record_idx ON agent_dispatches (record_type, record_id, created_at DESC);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_acting_idx ON agent_dispatches (acting_member_id);

CREATE TABLE exports (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind          text NOT NULL CHECK (kind IN ('folder', 'documents')),
    format        text NOT NULL CHECK (format IN ('zip')),
    folder_id     bigint REFERENCES folders(id) ON DELETE SET NULL,
    document_ids  bigint[] NOT NULL DEFAULT '{}',
    status        text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'running', 'done', 'failed')),
    storage_path  text,
    byte_size     bigint,
    item_count    integer NOT NULL DEFAULT 0,
    log           text,
    created_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    expires_at    timestamptz NOT NULL DEFAULT now() + interval '7 days',
    downloaded_at timestamptz,
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,
    link_id       bigint REFERENCES share_links(id) ON DELETE SET NULL      -- a link holder's zip of a folder link
);
CREATE INDEX exports_owner_idx ON exports (created_by, created_at DESC);
CREATE INDEX exports_expiry_idx ON exports (expires_at) WHERE storage_path IS NOT NULL;
CREATE INDEX exports_queue_idx ON exports (status, created_at) WHERE status = 'queued';

CREATE TABLE worker_passes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    started_at    timestamptz NOT NULL DEFAULT now(),
    finished_at   timestamptz,
    steps         jsonb NOT NULL DEFAULT '{}'::jsonb,
    error         text
);

CREATE TABLE favourites (
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    document_id  bigint REFERENCES documents(id) ON DELETE CASCADE,
    folder_id    bigint REFERENCES folders(id) ON DELETE CASCADE,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CHECK ((document_id IS NULL) <> (folder_id IS NULL))
);
CREATE UNIQUE INDEX favourites_document_one ON favourites (member_id, document_id) WHERE document_id IS NOT NULL;
CREATE UNIQUE INDEX favourites_folder_one ON favourites (member_id, folder_id) WHERE folder_id IS NOT NULL;

CREATE TABLE saved_searches (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    owner_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    name        text NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
    query       text NOT NULL DEFAULT '',
    filters     jsonb NOT NULL DEFAULT '{}'::jsonb,
    kind        text NOT NULL DEFAULT 'keyword' CHECK (kind IN ('keyword', 'graph')),
    is_shared   boolean NOT NULL DEFAULT false,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (owner_id, name)
);
CREATE TRIGGER saved_searches_touch BEFORE UPDATE ON saved_searches FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE search_log (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id   bigint REFERENCES members(id) ON DELETE SET NULL,
    kind        text NOT NULL CHECK (kind IN ('keyword', 'graph', 'name')),
    query       text NOT NULL CHECK (length(query) <= 120),
    filters     jsonb NOT NULL DEFAULT '{}'::jsonb,
    results     integer NOT NULL DEFAULT 0,
    took_ms     integer,
    source      text NOT NULL DEFAULT 'web',
    created_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX search_log_time_idx ON search_log (created_at DESC);
CREATE INDEX search_log_empty_idx ON search_log (created_at DESC) WHERE results = 0;

CREATE TABLE clerk_proposals (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind         text NOT NULL CHECK (kind IN ('untagged', 'duplicate', 'stale_share', 'link_expiring', 'link_suspicious', 'request_open', 'trash_due', 'locked',
                                               'no_text', 'quota', 'budget', 'orphaned', 'tags')),
    document_id  bigint REFERENCES documents(id) ON DELETE CASCADE,
    folder_id    bigint REFERENCES folders(id) ON DELETE CASCADE,
    library_id   bigint REFERENCES libraries(id) ON DELETE CASCADE,
    link_id      bigint REFERENCES share_links(id) ON DELETE CASCADE,
    for_member_id bigint REFERENCES members(id) ON DELETE CASCADE,          -- whose decision it is (the owner, a curator, the admin)
    title        text NOT NULL,
    payload      jsonb NOT NULL DEFAULT '{}'::jsonb,
    proposed_by  bigint REFERENCES members(id) ON DELETE SET NULL,          -- the Clerk
    status       text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed', 'expired')),
    decided_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at   timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX clerk_proposals_open_idx ON clerk_proposals (for_member_id, created_at DESC) WHERE status = 'proposed';
CREATE INDEX clerk_proposals_kind_idx ON clerk_proposals (kind, status);

GRANT SELECT, INSERT, UPDATE, DELETE ON notes, notifications, notification_prefs, notification_outbox, agent_dispatches, exports, worker_passes,
    favourites, saved_searches, search_log, clerk_proposals TO doccloud_rw;
REVOKE ALL ON FUNCTION dc_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_notify(bigint, text, text, text, text, bigint, bigint, bigint, bigint, text) TO doccloud_rw;

COMMIT;
