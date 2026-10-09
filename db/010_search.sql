-- 010: KEYWORD SEARCH — the index over every live document and dc_search() with the wall as a per-row gate (design §6.1; D7).
--
-- search_index holds one row per live document: tsv = name (A) · tags and description (B) · the first 200,000 characters of
-- the current version's text (C); written by trigger from the document, its tags and its text, and caught up by the worker.
-- dc_search(q, filters, limit, offset): websearch syntax ('simple' + unaccent), the filters of §6.1, visibility as
-- dc_can_see_document() per candidate row AFTER the GIN match (THE SCALE RULE — never a set of every visible document), ranked by
-- ts_rank then recency, with ts_headline excerpts and a window count. A name search also tries pg_trgm on documents.name.
-- A query is logged by PHP into search_log (db/013) — 120 characters at most, never the full text of anything.
--
-- THE ESTATE'S DATA MODEL (design §6.4): search_index REUSED from Spaces db/013 verbatim (entity_kind, entity_uuid, entity_id,
-- page_id, space_id, channel_id, author_member_id, title, body, tsv, has_link, has_file, occurred_at, updated_at, the uuid/id
-- CHECK) with `entity_kind` widened by 'document' and APPENDING document_id, version_id, library_id, folder_id, owner_member_id,
-- mime_kind, byte_size, tags; the Spaces-specific columns stay (NULL here) so the shape is the catalogue's.
BEGIN;

CREATE TABLE search_index (
    entity_kind   text NOT NULL CHECK (entity_kind IN ('page', 'row', 'message', 'comment', 'document')),
    entity_uuid   uuid,
    entity_id     bigint,
    page_id       uuid,
    space_id      bigint,
    channel_id    bigint,
    author_member_id bigint,
    title         text,
    body          text,
    tsv           tsvector,
    has_link      boolean NOT NULL DEFAULT false,
    has_file      boolean NOT NULL DEFAULT false,
    occurred_at   timestamptz NOT NULL,
    updated_at    timestamptz NOT NULL DEFAULT now(),
    -- appended (design §6.1)
    document_id      bigint,
    version_id       bigint,
    library_id       bigint,
    folder_id        bigint,
    owner_member_id  bigint,
    mime_kind        text,
    byte_size        bigint,
    tags             text[] NOT NULL DEFAULT '{}',
    CHECK ((entity_uuid IS NULL) <> (entity_id IS NULL))
);
CREATE UNIQUE INDEX search_index_uuid ON search_index (entity_kind, entity_uuid) WHERE entity_uuid IS NOT NULL;
CREATE UNIQUE INDEX search_index_id   ON search_index (entity_kind, entity_id) WHERE entity_id IS NOT NULL;
CREATE INDEX search_index_tsv  ON search_index USING gin (tsv);
CREATE INDEX search_index_time ON search_index (occurred_at DESC);
CREATE INDEX search_index_page ON search_index (page_id);
CREATE INDEX search_index_channel ON search_index (channel_id);
CREATE INDEX search_index_library_idx ON search_index (library_id, occurred_at DESC);
CREATE INDEX search_index_folder_idx ON search_index (folder_id);
CREATE INDEX search_index_owner_idx ON search_index (owner_member_id, occurred_at DESC);
CREATE INDEX search_index_tags_idx ON search_index USING gin (tags);

CREATE OR REPLACE FUNCTION dc_search_tsv(p_title text, p_meta text, p_body text) RETURNS tsvector
    LANGUAGE sql IMMUTABLE AS $$
    SELECT setweight(to_tsvector('simple', unaccent(COALESCE(p_title, ''))), 'A')
        || setweight(to_tsvector('simple', unaccent(COALESCE(p_meta, ''))), 'B')
        || setweight(to_tsvector('simple', unaccent(left(COALESCE(p_body, ''), 200000))), 'C');
$$;

CREATE OR REPLACE FUNCTION dc_index_document(p_document_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d documents%ROWTYPE; body text; slugs text[]; meta text;
BEGIN
    SELECT * INTO d FROM documents WHERE id = p_document_id;
    IF NOT FOUND OR d.trashed_at IS NOT NULL THEN
        DELETE FROM search_index WHERE entity_kind = 'document' AND entity_id = p_document_id;
        RETURN;
    END IF;
    SELECT left(t.text, 200000) INTO body FROM document_text t WHERE t.version_id = d.current_version_id;
    slugs := dc_document_tag_slugs(d.id);
    meta := array_to_string(slugs, ' ') || ' ' || COALESCE(d.description, '') || ' ' || replace(replace(d.name, '.', ' '), '_', ' ');
    INSERT INTO search_index (entity_kind, entity_id, author_member_id, title, body, tsv, has_file, occurred_at, updated_at,
                              document_id, version_id, library_id, folder_id, owner_member_id, mime_kind, byte_size, tags)
    VALUES ('document', d.id, d.owner_member_id, d.name, body, dc_search_tsv(d.name, meta, body), true, d.updated_at, now(),
            d.id, d.current_version_id, d.library_id, d.folder_id, d.owner_member_id, d.mime_kind, d.byte_size, slugs)
    ON CONFLICT (entity_kind, entity_id) WHERE entity_id IS NOT NULL DO UPDATE
       SET author_member_id = EXCLUDED.author_member_id, title = EXCLUDED.title, body = EXCLUDED.body, tsv = EXCLUDED.tsv, occurred_at = EXCLUDED.occurred_at,
           updated_at = now(), version_id = EXCLUDED.version_id, library_id = EXCLUDED.library_id, folder_id = EXCLUDED.folder_id,
           owner_member_id = EXCLUDED.owner_member_id, mime_kind = EXCLUDED.mime_kind, byte_size = EXCLUDED.byte_size, tags = EXCLUDED.tags;
END$$;

CREATE OR REPLACE FUNCTION dc_search_document_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN DELETE FROM search_index WHERE entity_kind = 'document' AND entity_id = OLD.id; RETURN NULL; END IF;
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;     -- a bulk load indexes by catch-up
    PERFORM dc_index_document(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER documents_search AFTER INSERT OR UPDATE OF name, description, folder_id, library_id, owner_member_id, current_version_id, trashed_at, mime_type OR DELETE
    ON documents FOR EACH ROW EXECUTE FUNCTION dc_search_document_trg();

CREATE OR REPLACE FUNCTION dc_search_tags_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    PERFORM dc_index_document(COALESCE(NEW.document_id, OLD.document_id));
    RETURN NULL;
END$$;
CREATE TRIGGER document_tags_search AFTER INSERT OR DELETE ON document_tags FOR EACH ROW EXECUTE FUNCTION dc_search_tags_trg();

CREATE OR REPLACE FUNCTION dc_search_text_trg() RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF current_setting('app.dc_bulk', true) = '1' THEN RETURN NULL; END IF;
    PERFORM dc_index_document(COALESCE(NEW.document_id, OLD.document_id));
    RETURN NULL;
END$$;
CREATE TRIGGER document_text_search AFTER INSERT OR UPDATE OF text OR DELETE ON document_text FOR EACH ROW EXECUTE FUNCTION dc_search_text_trg();

-- The catch-up (the worker's, and after a bulk load): documents whose index row is missing or older than the document.
CREATE OR REPLACE FUNCTION dc_search_catch_up(p_limit integer DEFAULT 500) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    FOR r IN SELECT d.id FROM documents d LEFT JOIN search_index si ON si.entity_id = d.id AND si.entity_kind = 'document'
              WHERE d.trashed_at IS NULL AND (si.entity_id IS NULL OR si.updated_at < d.updated_at
                                               OR si.version_id IS DISTINCT FROM d.current_version_id) LIMIT p_limit LOOP
        PERFORM dc_index_document(r.id); n := n + 1;
    END LOOP;
    DELETE FROM search_index si WHERE si.entity_kind = 'document' AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.id = si.entity_id AND d.trashed_at IS NULL);
    RETURN n;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The search. filters: {"library": id, "folder": id (its subtree), "owner": member id, "tag": slug, "kind": mime_kind,
-- "min_bytes", "max_bytes", "before": "YYYY-MM-DD", "after": "YYYY-MM-DD", "mine": true, "shared_with_me": true}.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_search(p_q text, p_filters jsonb DEFAULT '{}'::jsonb, p_limit integer DEFAULT 25, p_offset integer DEFAULT 0)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, library_id bigint, owner_member_id bigint, mime_kind text, byte_size bigint,
                   tags text[], excerpt text, occurred_at timestamptz, rank real, total bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE q tsquery; f jsonb := COALESCE(p_filters, '{}'::jsonb); me bigint := app_current_member_id();
        lib bigint; fld bigint; fpath text; own bigint; tg text; kd text; minb bigint; maxb bigint; before_d timestamptz; after_d timestamptz;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    IF p_q IS NULL OR btrim(p_q) = '' THEN q := NULL; ELSE q := websearch_to_tsquery('simple', unaccent(p_q)); END IF;
    IF (f->>'library') ~ '^[0-9]+$' THEN lib := (f->>'library')::bigint; END IF;
    IF (f->>'folder') ~ '^[0-9]+$' THEN fld := (f->>'folder')::bigint; SELECT path INTO fpath FROM folders WHERE id = fld; END IF;
    IF (f->>'owner') ~ '^[0-9]+$' THEN own := (f->>'owner')::bigint; END IF;
    IF COALESCE((f->>'mine')::boolean, false) THEN own := me; END IF;
    IF f->>'tag' IS NOT NULL THEN tg := (SELECT slug FROM tags WHERE id = dc_tag_resolve(f->>'tag')); IF tg IS NULL THEN tg := '-'; END IF; END IF;
    kd := f->>'kind';
    IF (f->>'min_bytes') ~ '^[0-9]+$' THEN minb := (f->>'min_bytes')::bigint; END IF;
    IF (f->>'max_bytes') ~ '^[0-9]+$' THEN maxb := (f->>'max_bytes')::bigint; END IF;
    IF f->>'before' IS NOT NULL THEN before_d := (f->>'before')::date::timestamptz; END IF;
    IF f->>'after' IS NOT NULL THEN after_d := ((f->>'after')::date + 1)::timestamptz; END IF;
    RETURN QUERY
    WITH hits AS (
        SELECT si.*, CASE WHEN q IS NULL THEN 0::real ELSE ts_rank(si.tsv, q) END AS r
          FROM search_index si
         WHERE si.entity_kind = 'document'
           AND (q IS NULL OR si.tsv @@ q)
           AND (lib IS NULL OR si.library_id = lib)
           AND (fld IS NULL OR si.folder_id IN (SELECT fo.id FROM folders fo WHERE fo.path LIKE fpath || '%'))
           AND (own IS NULL OR si.owner_member_id = own)
           AND (tg IS NULL OR si.tags @> ARRAY[tg])
           AND (kd IS NULL OR si.mime_kind = kd)
           AND (minb IS NULL OR si.byte_size >= minb)
           AND (maxb IS NULL OR si.byte_size <= maxb)
           AND (before_d IS NULL OR si.occurred_at < before_d)
           AND (after_d IS NULL OR si.occurred_at >= after_d)
           AND (NOT COALESCE((f->>'shared_with_me')::boolean, false) OR si.owner_member_id <> me)
           AND dc_can_see_document(si.document_id))
    SELECT h.document_id, h.title, h.folder_id, h.library_id, h.owner_member_id, h.mime_kind, h.byte_size, h.tags,
           CASE WHEN q IS NULL OR h.body IS NULL THEN left(COALESCE(h.body, ''), 200)
                ELSE ts_headline('simple', h.body, q, 'MaxFragments=2, MaxWords=24, MinWords=8, StartSel=<mark>, StopSel=</mark>') END,
           h.occurred_at, h.r, count(*) OVER () AS total
      FROM hits h
     ORDER BY h.r DESC, h.occurred_at DESC
     LIMIT greatest(1, least(p_limit, 100)) OFFSET greatest(0, p_offset);
END$$;

-- A misspelt name: trigram similarity on live documents the caller may see (the search box's second try).
CREATE OR REPLACE FUNCTION dc_search_names(p_q text, p_limit integer DEFAULT 10)
    RETURNS TABLE (document_id bigint, name text, folder_id bigint, similarity real)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT c.id, c.name, c.folder_id, c.sim
      FROM (SELECT d.id, d.name, d.folder_id, similarity(d.name, p_q) AS sim
              FROM documents d WHERE d.trashed_at IS NULL AND d.name % p_q
             ORDER BY similarity(d.name, p_q) DESC, d.updated_at DESC LIMIT 200) c      -- the index first, the wall on at most 200
     WHERE dc_can_see_document(c.id)
     ORDER BY c.sim DESC
     LIMIT greatest(1, least(p_limit, 50));
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON search_index TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_search(text, jsonb, integer, integer), dc_search_names(text, integer), dc_search_tsv(text, text, text) TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_index_document(bigint), dc_search_catch_up(integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_index_document(bigint), dc_search_catch_up(integer) TO doccloud_rw;

COMMIT;
