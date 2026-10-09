-- 009: TAGS — a managed vocabulary (curators) and free tags (anyone), aliases from merges, proposals, a folder's auto-tags
-- (design §6.2; D12).
--
-- A free tag used by `free_tag_proposal_threshold` (3) different people becomes a proposal to the curator (tag_proposals kind
-- `promote`); the Clerk proposes tags for untagged documents (kind `apply`, db/013's clerk_proposals is the Clerk's own
-- ledger — this table is the tag vocabulary's). A merge rewrites document_tags and keeps the old slug as an alias so a saved
-- search or a request label still resolves. A folder's auto_tags (slugs) are applied to every document added under it, by the
-- nearest folder upward that names any — the trigger walks the path once at insert.
--
-- THE ESTATE'S DATA MODEL (design §6.4): `tags` REUSED from Help Desk db/009 verbatim (id, slug, label, usage_count, created_at),
-- APPENDING kind, colour, created_by, archived_at; `document_tags` NEW canonical (the shape of Help Desk's ticket_tags, with the
-- adder and the source — a sibling tagging several kinds appends record_type); tag_aliases and tag_proposals new.
BEGIN;

CREATE TABLE tags (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        text NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9][a-z0-9_-]{0,49}$'),
    label       text NOT NULL,
    usage_count integer NOT NULL DEFAULT 0,
    created_at  timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.2)
    kind        text NOT NULL DEFAULT 'free' CHECK (kind IN ('managed', 'free')),
    colour      text CHECK (colour IS NULL OR colour ~ '^#[0-9a-f]{6}$'),
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at timestamptz
);
CREATE INDEX tags_label_trgm_idx ON tags USING gin (label gin_trgm_ops);
CREATE INDEX tags_kind_idx ON tags (kind, usage_count DESC) WHERE archived_at IS NULL;

CREATE TABLE tag_aliases (
    alias     text PRIMARY KEY CHECK (alias ~ '^[a-z0-9][a-z0-9_-]{0,49}$'),
    tag_id    bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    merged_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE document_tags (
    document_id bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    tag_id      bigint NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    source      text NOT NULL DEFAULT 'person' CHECK (source IN ('person', 'agent', 'rule', 'request', 'merge')),
    created_at  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (document_id, tag_id)
);
CREATE INDEX document_tags_tag_idx ON document_tags (tag_id, document_id);

CREATE TABLE tag_proposals (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind         text NOT NULL CHECK (kind IN ('promote', 'apply', 'merge')),   -- promote a free tag; apply a tag to a document; merge two
    tag_id       bigint REFERENCES tags(id) ON DELETE CASCADE,
    into_tag_id  bigint REFERENCES tags(id) ON DELETE CASCADE,                   -- merge
    document_id  bigint REFERENCES documents(id) ON DELETE CASCADE,              -- apply
    proposed_by  bigint REFERENCES members(id) ON DELETE SET NULL,               -- NULL = the threshold rule
    note         text,
    status       text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    decided_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at   timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CHECK (CASE kind WHEN 'promote' THEN tag_id IS NOT NULL AND document_id IS NULL AND into_tag_id IS NULL
                     WHEN 'apply' THEN tag_id IS NOT NULL AND document_id IS NOT NULL
                     WHEN 'merge' THEN tag_id IS NOT NULL AND into_tag_id IS NOT NULL AND tag_id <> into_tag_id END)
);
CREATE UNIQUE INDEX tag_proposals_open_promote ON tag_proposals (tag_id) WHERE kind = 'promote' AND status = 'proposed';
CREATE UNIQUE INDEX tag_proposals_open_apply ON tag_proposals (tag_id, document_id) WHERE kind = 'apply' AND status = 'proposed';
CREATE INDEX tag_proposals_status_idx ON tag_proposals (status, created_at DESC);

-- A slug from a label: lowercase, accents gone, non-alphanumerics to '-', at most 50.
CREATE OR REPLACE FUNCTION dc_slugify(p text) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT left(btrim(regexp_replace(regexp_replace(lower(unaccent(COALESCE(p, ''))), '[^a-z0-9]+', '-', 'g'), '-+', '-', 'g'), '-'), 50);
$$;

-- A tag by slug or alias (never creating).
CREATE OR REPLACE FUNCTION dc_tag_resolve(p_slug text) RETURNS bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT id FROM tags WHERE slug = dc_slugify(p_slug) AND archived_at IS NULL),
                    (SELECT tag_id FROM tag_aliases WHERE alias = dc_slugify(p_slug)));
$$;

-- A tag by label, made as a free tag when missing (anyone may); returns its id.
CREATE OR REPLACE FUNCTION dc_tag_ensure(p_label text, p_by bigint, p_kind text DEFAULT 'free') RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE s text := dc_slugify(p_label); tid bigint;
BEGIN
    IF s = '' THEN RAISE EXCEPTION 'An empty tag' USING ERRCODE = 'check_violation'; END IF;
    tid := dc_tag_resolve(s);
    IF tid IS NOT NULL THEN RETURN tid; END IF;
    INSERT INTO tags (slug, label, kind, created_by) VALUES (s, btrim(p_label), p_kind, p_by)
    ON CONFLICT (slug) DO UPDATE SET archived_at = NULL RETURNING id INTO tid;
    RETURN tid;
END$$;

-- Apply / remove, with the counts and the promotion rule.
CREATE OR REPLACE FUNCTION dc_tag_apply(p_document_id bigint, p_tag_id bigint, p_by bigint, p_source text DEFAULT 'person') RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer; t tags%ROWTYPE; users integer;
BEGIN
    INSERT INTO document_tags (document_id, tag_id, added_by, source) VALUES (p_document_id, p_tag_id, p_by, p_source) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n = 0 THEN RETURN false; END IF;
    UPDATE tags SET usage_count = usage_count + 1 WHERE id = p_tag_id RETURNING * INTO t;
    IF t.kind = 'free' THEN
        SELECT count(DISTINCT added_by) INTO users FROM document_tags WHERE tag_id = p_tag_id AND added_by IS NOT NULL;
        IF users >= dc_setting_int('free_tag_proposal_threshold') THEN
            INSERT INTO tag_proposals (kind, tag_id, note) VALUES ('promote', p_tag_id, users || ' people use it') ON CONFLICT DO NOTHING;
        END IF;
    END IF;
    RETURN true;
END$$;

CREATE OR REPLACE FUNCTION dc_tag_remove(p_document_id bigint, p_tag_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM document_tags WHERE document_id = p_document_id AND tag_id = p_tag_id;
    GET DIAGNOSTICS n = ROW_COUNT;
    IF n = 1 THEN UPDATE tags SET usage_count = GREATEST(0, usage_count - 1) WHERE id = p_tag_id; END IF;
    RETURN n = 1;
END$$;

-- Merge: every document of `from` carries `into`; `from`'s slug becomes an alias; `from` is archived.
CREATE OR REPLACE FUNCTION dc_tag_merge(p_from bigint, p_into bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer; f tags%ROWTYPE;
BEGIN
    IF p_from = p_into THEN RAISE EXCEPTION 'A tag is not merged into itself' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO f FROM tags WHERE id = p_from FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such tag' USING ERRCODE = 'no_data_found'; END IF;
    INSERT INTO document_tags (document_id, tag_id, added_by, source)
    SELECT dt.document_id, p_into, dt.added_by, 'merge' FROM document_tags dt WHERE dt.tag_id = p_from
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    DELETE FROM document_tags WHERE tag_id = p_from;
    UPDATE tags SET usage_count = (SELECT count(*) FROM document_tags WHERE tag_id = p_into) WHERE id = p_into;
    UPDATE tags SET usage_count = 0, archived_at = now() WHERE id = p_from;
    INSERT INTO tag_aliases (alias, tag_id) VALUES (f.slug, p_into) ON CONFLICT (alias) DO UPDATE SET tag_id = p_into, merged_at = now();
    UPDATE tag_aliases SET tag_id = p_into WHERE tag_id = p_from;
    UPDATE tag_proposals SET status = 'dismissed', decided_by = p_by, decided_at = now() WHERE tag_id = p_from AND status = 'proposed';
    RETURN n;
END$$;

-- The nearest folder upward naming auto-tags gives them to a new document (the trigger).
CREATE OR REPLACE FUNCTION dc_documents_auto_tags() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE slugs text[]; s text; tid bigint;
BEGIN
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    SELECT a.auto_tags INTO slugs FROM folders f JOIN folders a ON f.path LIKE a.path || '%'
     WHERE f.id = NEW.folder_id AND cardinality(a.auto_tags) > 0 ORDER BY a.depth DESC LIMIT 1;
    IF slugs IS NULL THEN RETURN NULL; END IF;
    FOREACH s IN ARRAY slugs LOOP
        tid := dc_tag_resolve(s);
        IF tid IS NULL THEN tid := dc_tag_ensure(s, NULL, 'free'); END IF;
        PERFORM dc_tag_apply(NEW.id, tid, NULL, 'rule');
    END LOOP;
    RETURN NULL;
END$$;
CREATE TRIGGER documents_auto_tags AFTER INSERT ON documents FOR EACH ROW EXECUTE FUNCTION dc_documents_auto_tags();

-- The tags of a document, as slugs (the index and the graph read this).
CREATE OR REPLACE FUNCTION dc_document_tag_slugs(p_document_id bigint) RETURNS text[]
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(array_agg(t.slug ORDER BY t.kind, t.usage_count DESC, t.slug), '{}') FROM document_tags dt JOIN tags t ON t.id = dt.tag_id WHERE dt.document_id = p_document_id;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON tags, tag_aliases, document_tags, tag_proposals TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_slugify(text), dc_tag_resolve(text), dc_document_tag_slugs(bigint) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_tag_ensure(text, bigint, text), dc_tag_apply(bigint, bigint, bigint, text), dc_tag_remove(bigint, bigint), dc_tag_merge(bigint, bigint, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_tag_ensure(text, bigint, text), dc_tag_apply(bigint, bigint, bigint, text), dc_tag_remove(bigint, bigint), dc_tag_merge(bigint, bigint, bigint) TO doccloud_rw;

COMMIT;
