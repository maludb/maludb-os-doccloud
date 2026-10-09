-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.3 "Settings"; D9, D10, D11, D13).
--
-- THE ESTATE'S DATA MODEL (design §6.4): dc_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1,
-- updated_at) with this application's columns. The key functions are Knowledge db/011's verbatim minus the base binding
-- (dc_resolve_key, dc_rate_ok, dc_key_calls_today, dc_key_rotate, dc_prune_key_usage) — here because they read the settings.
--
-- The model alias and embedding model are MaluDB's own configuration (POST /v1/memory/config for the namespace `dc`); the two
-- columns here are the admin's CHOICE, NULL = whatever the memory database answers. Changing them pauses (§5). storage_dir NULL
-- = storage/blobs under the install; DC_STORAGE_DIR in config/.env overrides it (the owner's answer (c)).
BEGIN;

CREATE TABLE dc_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    max_document_bytes          bigint NOT NULL DEFAULT 2147483648 CHECK (max_document_bytes BETWEEN 1048576 AND 1099511627776),   -- D9: 2 GB per file (env may only lower)
    max_text_bytes              bigint NOT NULL DEFAULT 5242880 CHECK (max_text_bytes BETWEEN 65536 AND 1073741824),                -- §6.1: text extracted per version, capped
    versions_keep_days          integer NOT NULL DEFAULT 30 CHECK (versions_keep_days BETWEEN 0 AND 3650),                           -- D10: every version for 30 days
    versions_keep_count         integer NOT NULL DEFAULT 10 CHECK (versions_keep_count BETWEEN 1 AND 1000),                          -- D10: then the last 10 (the current always)
    trash_days                  integer NOT NULL DEFAULT 30 CHECK (trash_days BETWEEN 1 AND 3650),                                   -- D10: the trash's window
    lock_hours                  integer NOT NULL DEFAULT 24 CHECK (lock_hours BETWEEN 1 AND 720),                                    -- D10: an edit lock
    link_default_days           integer NOT NULL DEFAULT 30 CHECK (link_default_days BETWEEN 1 AND 3650),                            -- D11: a link's default expiry
    link_no_expiry_allowed      boolean NOT NULL DEFAULT false,                                                                        -- D11: "never" only when the admin allows
    upload_session_hours        integer NOT NULL DEFAULT 24 CHECK (upload_session_hours BETWEEN 1 AND 168),                          -- §6.1: an abandoned upload
    upload_part_bytes           integer NOT NULL DEFAULT 8388608 CHECK (upload_part_bytes BETWEEN 1048576 AND 67108864),             -- §6.1: 8 MB parts
    graph_monthly_text_mb       integer NOT NULL DEFAULT 100 CHECK (graph_monthly_text_mb BETWEEN 1 AND 1000000),                    -- D8: the `full` policy's budget
    graph_default_policy        text NOT NULL DEFAULT 'index' CHECK (graph_default_policy IN ('none', 'index', 'full')),              -- D8
    graph_text_chars            integer NOT NULL DEFAULT 20000 CHECK (graph_text_chars BETWEEN 1000 AND 200000),                     -- D8: what `index` remembers of a document
    graph_full_chunk_chars      integer NOT NULL DEFAULT 2000 CHECK (graph_full_chunk_chars BETWEEN 200 AND 8000),                   -- D8: the engine's chunk for `full`
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),                        -- D13
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),                       -- D13
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),                   -- D13: the old key keeps 24 h
    link_opens_per_minute       integer NOT NULL DEFAULT 60 CHECK (link_opens_per_minute BETWEEN 1 AND 100000),                      -- §4: per address
    request_uploads_per_hour    integer NOT NULL DEFAULT 20 CHECK (request_uploads_per_hour BETWEEN 1 AND 100000),                   -- §4: per address
    free_tag_proposal_threshold smallint NOT NULL DEFAULT 3 CHECK (free_tag_proposal_threshold BETWEEN 1 AND 1000),                  -- D12: a free tag used by three
    stale_guest_share_days      integer NOT NULL DEFAULT 90 CHECK (stale_guest_share_days BETWEEN 1 AND 3650),                       -- §5: the Clerk's report
    model_alias                 text,                                                                                                 -- NULL = MaluDB's configured default
    embedding_model             text,
    storage_dir                 text,                                                                                                 -- NULL = storage/blobs under the install
    timezone                    text NOT NULL DEFAULT 'UTC',
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO dc_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER dc_settings_touch BEFORE UPDATE ON dc_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION dc_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM dc_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION dc_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM dc_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION dc_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM dc_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, dc_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, dc_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION dc_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a revoked,
-- expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION dc_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION dc_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION dc_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (D13: 24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION dc_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := dc_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION dc_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON dc_settings TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_setting_int(text), dc_setting_text(text), dc_setting_bool(text), dc_key_calls_today(bigint) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_resolve_key(text), dc_rate_ok(bigint, timestamptz), dc_key_rotate(bigint, text), dc_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_resolve_key(text), dc_rate_ok(bigint, timestamptz), dc_key_rotate(bigint, text), dc_prune_key_usage() TO doccloud_rw;

COMMIT;
