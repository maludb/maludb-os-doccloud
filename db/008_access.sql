-- 008: WHO SEES WHAT — the access rows, their inheritance, and the walls (design §3, D5).
--
-- The rules, in order of the function dc_document_level():
--   1. the OWNER of a document (or the maker of a folder) manages it, wherever it is;
--   2. the LIBRARY's base level: personal — its owner manages, nobody else; group — every live member at the group's member_level,
--      owners manage; shared — every member of the business (never a guest) at everyone_level, curators manage;
--   3. the nearest EXPLICIT rows above (folder_access at folders.access_root_id, or the document's own rows when own_access)
--      for any of the caller's PRINCIPALS: themselves, their live groups, their live departments, everyone; a guest matches only
--      rows naming them; an `application` row is a sibling's (K7) — people never match it;
--   4. `upload` on a folder gives its holder view of THEIR OWN documents there and nothing else; `none` denies;
--   5. the admin manages everything (logged by PHP: library.admin_view); an agent is its member id, no special case.
-- SHARING IS ADDITIVE: the first explicit row on a folder with no rows of its own carries the inherited set along (the library
-- base as a row: the group at member_level, everyone at everyone_level; the personal owner needs no row); RESTRICT is the same
-- write with app.dc_restrict = '1', which carries nothing. `folders.access_root_id` is the nearest ancestor-or-self with rows,
-- recomputed for the subtree by trigger when rows appear or vanish and when a folder moves — the resolver walks nothing.
-- THE SCALE RULE: no function returns the caller's visible set; dc_my_principals() is a dozen rows once per statement, and every
-- per-row check is one indexed EXISTS.
-- access_changes keeps who changed what access when — so "who could see this on June 3rd" is a question (§7 A7).
--
-- THE ESTATE'S DATA MODEL (design §6.4): new, canonical; the shape is Spaces' page_permissions with `group` and `application`.
BEGIN;

CREATE TABLE folder_access (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    folder_id      bigint NOT NULL REFERENCES folders(id) ON DELETE CASCADE,
    principal_kind text NOT NULL CHECK (principal_kind IN ('member', 'group', 'department', 'agent', 'guest', 'application', 'everyone')),
    principal_id   bigint,                                                 -- member/agent/guest: a member id; group; department
    principal_ref  text,                                                   -- application: the sibling's catalog key
    level          text NOT NULL CHECK (level IN ('none', 'view', 'upload', 'edit', 'manage')),
    granted_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (CASE principal_kind WHEN 'everyone' THEN principal_id IS NULL AND principal_ref IS NULL
                               WHEN 'application' THEN principal_id IS NULL AND principal_ref ~ '^[a-z][a-z0-9_]{0,39}$'
                               ELSE principal_id IS NOT NULL AND principal_ref IS NULL END)
);
CREATE UNIQUE INDEX folder_access_one ON folder_access (folder_id, principal_kind, COALESCE(principal_id, 0), COALESCE(principal_ref, ''));
CREATE INDEX folder_access_principal_idx ON folder_access (principal_kind, principal_id, folder_id);
CREATE INDEX folder_access_app_idx ON folder_access (principal_ref) WHERE principal_kind = 'application';

CREATE TABLE document_access (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    document_id    bigint NOT NULL REFERENCES documents(id) ON DELETE CASCADE,
    principal_kind text NOT NULL CHECK (principal_kind IN ('member', 'group', 'department', 'agent', 'guest', 'application', 'everyone')),
    principal_id   bigint,
    principal_ref  text,
    level          text NOT NULL CHECK (level IN ('none', 'view', 'edit', 'manage')),   -- upload means nothing on one document
    granted_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (CASE principal_kind WHEN 'everyone' THEN principal_id IS NULL AND principal_ref IS NULL
                               WHEN 'application' THEN principal_id IS NULL AND principal_ref ~ '^[a-z][a-z0-9_]{0,39}$'
                               ELSE principal_id IS NOT NULL AND principal_ref IS NULL END)
);
CREATE UNIQUE INDEX document_access_one ON document_access (document_id, principal_kind, COALESCE(principal_id, 0), COALESCE(principal_ref, ''));
CREATE INDEX document_access_principal_idx ON document_access (principal_kind, principal_id, document_id);

CREATE TABLE access_changes (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    folder_id      bigint,                                                 -- one of the two; no FK: the trail outlives the thing
    document_id    bigint,
    principal_kind text NOT NULL,
    principal_id   bigint,
    principal_ref  text,
    old_level      text,
    new_level      text,                                                   -- NULL = the row was removed
    changed_by     bigint,
    changed_at     timestamptz NOT NULL DEFAULT now(),
    CHECK ((folder_id IS NULL) <> (document_id IS NULL))
);
CREATE INDEX access_changes_folder_idx ON access_changes (folder_id, changed_at DESC);
CREATE INDEX access_changes_document_idx ON access_changes (document_id, changed_at DESC);

-- ---------------------------------------------------------------------------------------------
-- Levels as numbers.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_level_rank(p text) RETURNS smallint
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 'manage' THEN 4 WHEN 'edit' THEN 3 WHEN 'upload' THEN 2 WHEN 'view' THEN 1 ELSE 0 END::smallint;
$$;
CREATE OR REPLACE FUNCTION dc_rank_level(p smallint) RETURNS text
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE p WHEN 4 THEN 'manage' WHEN 3 THEN 'edit' WHEN 2 THEN 'upload' WHEN 1 THEN 'view' ELSE 'none' END;
$$;

-- ---------------------------------------------------------------------------------------------
-- The caller's principals, once per statement.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_my_principals() RETURNS TABLE (kind text, id bigint)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); guest boolean;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN; END IF;
    guest := dc_member_is_guest(me);
    RETURN QUERY SELECT 'member'::text, me;
    RETURN QUERY SELECT 'agent'::text, me;
    RETURN QUERY SELECT 'guest'::text, me;
    IF guest THEN RETURN; END IF;
    RETURN QUERY SELECT 'group'::text, g FROM dc_member_group_ids(me) g;
    RETURN QUERY SELECT 'department'::text, d FROM app_member_department_ids() d;
    RETURN QUERY SELECT 'everyone'::text, NULL::bigint;
END$$;

-- The library's base level for the caller (rule 2).
CREATE OR REPLACE FUNCTION dc_library_base_level(p_library_id bigint, p_member_id bigint) RETURNS smallint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE l libraries%ROWTYPE; r smallint := 0; guest boolean;
BEGIN
    SELECT * INTO l FROM libraries WHERE id = p_library_id;
    IF NOT FOUND OR p_member_id IS NULL THEN RETURN 0; END IF;
    IF NOT EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL) THEN RETURN 0; END IF;   -- not admitted: nothing
    IF l.kind = 'personal' THEN
        RETURN CASE WHEN l.owner_member_id = p_member_id THEN 4 ELSE 0 END;
    END IF;
    guest := dc_member_is_guest(p_member_id);
    IF l.kind = 'group' THEN
        IF l.archived_at IS NOT NULL THEN RETURN 0; END IF;
        SELECT CASE WHEN gm.role = 'owner' THEN 4 ELSE dc_level_rank(g.member_level) END INTO r
          FROM group_members gm JOIN groups g ON g.id = gm.group_id
         WHERE gm.group_id = l.group_id AND gm.member_id = p_member_id AND gm.left_at IS NULL AND g.archived_at IS NULL;
        RETURN COALESCE(r, 0);
    END IF;
    -- shared
    IF l.archived_at IS NOT NULL THEN RETURN 0; END IF;
    IF NOT guest THEN
        r := dc_level_rank(l.everyone_level);
        IF l.owner_member_id = p_member_id THEN r := 4; END IF;
    END IF;
    RETURN r;
END$$;

-- Explicit rows for the caller at a folder (its access root), as the highest rank; -1 = no row matched.
CREATE OR REPLACE FUNCTION dc_folder_rows_rank(p_root_folder_id bigint) RETURNS smallint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(max(dc_level_rank(fa.level)), -1)::smallint
      FROM folder_access fa
     WHERE fa.folder_id = p_root_folder_id
       AND ((fa.principal_kind = 'everyone' AND EXISTS (SELECT 1 FROM dc_my_principals() p WHERE p.kind = 'everyone'))
            OR (fa.principal_kind <> 'everyone' AND fa.principal_kind <> 'application'
                AND EXISTS (SELECT 1 FROM dc_my_principals() p WHERE p.kind = fa.principal_kind AND p.id = fa.principal_id)));
$$;

CREATE OR REPLACE FUNCTION dc_document_rows_rank(p_document_id bigint) RETURNS smallint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(max(dc_level_rank(da.level)), -1)::smallint
      FROM document_access da
     WHERE da.document_id = p_document_id
       AND ((da.principal_kind = 'everyone' AND EXISTS (SELECT 1 FROM dc_my_principals() p WHERE p.kind = 'everyone'))
            OR (da.principal_kind <> 'everyone' AND da.principal_kind <> 'application'
                AND EXISTS (SELECT 1 FROM dc_my_principals() p WHERE p.kind = da.principal_kind AND p.id = da.principal_id)));
$$;

-- ---------------------------------------------------------------------------------------------
-- THE WALLS.
-- ---------------------------------------------------------------------------------------------
-- A folder's level for the caller: admin → manage; the maker → manage; else the library base combined with the explicit rows
-- at the access root (rows RAISE the base; after a restrict the base's principal is simply absent from the rows and only the
-- rows count — the base still applies to the library's OWNER/owners, who are never restricted out).
CREATE OR REPLACE FUNCTION dc_folder_rank(p_folder_id bigint) RETURNS smallint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); f folders%ROWTYPE; base smallint; rows_r smallint; l libraries%ROWTYPE;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN 0; END IF;
    SELECT * INTO f FROM folders WHERE id = p_folder_id;
    IF NOT FOUND THEN RETURN 0; END IF;
    IF dc_is_admin() THEN RETURN 4; END IF;
    IF f.owner_member_id = me AND f.trashed_at IS NULL THEN RETURN 4; END IF;
    base := dc_library_base_level(f.library_id, me);
    IF f.access_root_id IS NULL THEN
        RETURN CASE WHEN f.trashed_at IS NOT NULL AND base < 4 THEN 0 ELSE base END;
    END IF;
    rows_r := dc_folder_rows_rank(f.access_root_id);
    SELECT * INTO l FROM libraries WHERE id = f.library_id;
    -- restricted rows replace the base for everyone but the library's own owner(s)
    IF l.kind = 'personal' OR (l.kind = 'group' AND base = 4) OR (l.kind = 'shared' AND base = 4) THEN
        RETURN GREATEST(base, rows_r, 0);
    END IF;
    IF rows_r < 0 THEN
        -- no row matches me: the base applies only if the root's rows still carry the base's principal (not restricted out)
        IF EXISTS (SELECT 1 FROM folder_access fa WHERE fa.folder_id = f.access_root_id
                      AND ((l.kind = 'group' AND fa.principal_kind = 'group' AND fa.principal_id = l.group_id)
                           OR (l.kind = 'shared' AND fa.principal_kind = 'everyone'))) THEN
            RETURN CASE WHEN f.trashed_at IS NOT NULL THEN 0 ELSE base END;
        END IF;
        RETURN 0;
    END IF;
    RETURN CASE WHEN f.trashed_at IS NOT NULL AND rows_r < 4 THEN 0 ELSE rows_r END;
END$$;

CREATE OR REPLACE FUNCTION dc_folder_level(p_folder_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_rank_level(dc_folder_rank(p_folder_id)); $$;
CREATE OR REPLACE FUNCTION dc_can_see_folder(p_folder_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_folder_rank(p_folder_id) > 0; $$;

-- A document's level: admin → manage; owner → manage; own rows when it has them, else the folder's level — where `upload`
-- on the folder means NONE on another's document; a trashed document: its owner and the folder's managers only.
CREATE OR REPLACE FUNCTION dc_document_rank(p_document_id bigint) RETURNS smallint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); d documents%ROWTYPE; r smallint;
BEGIN
    IF me IS NULL OR NOT app_is_active_member() THEN RETURN 0; END IF;
    SELECT * INTO d FROM documents WHERE id = p_document_id;
    IF NOT FOUND THEN RETURN 0; END IF;
    IF dc_is_admin() OR d.owner_member_id = me THEN RETURN 4; END IF;
    IF d.own_access THEN
        r := GREATEST(dc_document_rows_rank(d.id), 0);
        -- the folder's managers keep managing (a row on one document never hides it from those who run the folder)
        IF r < 4 AND dc_folder_rank(d.folder_id) = 4 THEN r := 4; END IF;
    ELSE
        r := dc_folder_rank(d.folder_id);
        IF r = 2 THEN r := 0; END IF;                  -- upload: only one's own
    END IF;
    IF d.trashed_at IS NOT NULL AND r < 4 THEN RETURN 0; END IF;
    RETURN r;
END$$;

CREATE OR REPLACE FUNCTION dc_document_level(p_document_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_rank_level(dc_document_rank(p_document_id)); $$;
CREATE OR REPLACE FUNCTION dc_can_see_document(p_document_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_document_rank(p_document_id) > 0; $$;
CREATE OR REPLACE FUNCTION dc_can_edit_document(p_document_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_document_rank(p_document_id) >= 3; $$;
CREATE OR REPLACE FUNCTION dc_can_manage_document(p_document_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_document_rank(p_document_id) >= 4; $$;
CREATE OR REPLACE FUNCTION dc_can_add_to_folder(p_folder_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_folder_rank(p_folder_id) >= 2; $$;
CREATE OR REPLACE FUNCTION dc_can_manage_folder(p_folder_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT dc_folder_rank(p_folder_id) >= 4; $$;

-- A sibling application's reach (K7): the folders whose access root carries an `application` row naming it (subtrees included).
CREATE OR REPLACE FUNCTION dc_application_folder_rank(p_folder_id bigint, p_app_key text) RETURNS smallint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT max(dc_level_rank(fa.level)) FROM folders f JOIN folder_access fa ON fa.folder_id = f.access_root_id
                      WHERE f.id = p_folder_id AND f.trashed_at IS NULL AND fa.principal_kind = 'application' AND fa.principal_ref = p_app_key), 0)::smallint;
$$;
CREATE OR REPLACE FUNCTION dc_application_can_see_document(p_document_id bigint, p_app_key text) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM documents d WHERE d.id = p_document_id AND d.trashed_at IS NULL
                      AND ((NOT d.own_access AND dc_application_folder_rank(d.folder_id, p_app_key) > 0)
                           OR (d.own_access AND EXISTS (SELECT 1 FROM document_access da WHERE da.document_id = d.id AND da.principal_kind = 'application'
                                                          AND da.principal_ref = p_app_key AND da.level <> 'none'))));
$$;

-- ---------------------------------------------------------------------------------------------
-- Inheritance: the access root, kept by trigger.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_recompute_access_roots(p_folder_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    WITH RECURSIVE t AS (
        SELECT f.id, f.parent_id,
               CASE WHEN EXISTS (SELECT 1 FROM folder_access fa WHERE fa.folder_id = f.id) THEN f.id
                    ELSE (SELECT p.access_root_id FROM folders p WHERE p.id = f.parent_id) END AS root
          FROM folders f WHERE f.id = p_folder_id
        UNION ALL
        SELECT c.id, c.parent_id,
               CASE WHEN EXISTS (SELECT 1 FROM folder_access fa WHERE fa.folder_id = c.id) THEN c.id ELSE t.root END
          FROM folders c JOIN t ON c.parent_id = t.id
    )
    UPDATE folders f SET access_root_id = t.root FROM t WHERE f.id = t.id AND f.access_root_id IS DISTINCT FROM t.root;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

-- Sharing is additive: the first explicit row on a folder with none carries the inherited set along — unless app.dc_restrict
-- is '1' (a restrict). Then the trail.
CREATE OR REPLACE FUNCTION dc_folder_access_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE f folders%ROWTYPE; l libraries%ROWTYPE; root bigint;
BEGIN
    IF TG_OP = 'INSERT' AND NOT EXISTS (SELECT 1 FROM folder_access fa WHERE fa.folder_id = NEW.folder_id)
       AND COALESCE(current_setting('app.dc_restrict', true), '') <> '1'
       AND COALESCE(current_setting('app.dc_inheriting', true), '') <> '1' THEN        -- the copies below never copy again
        SELECT * INTO f FROM folders WHERE id = NEW.folder_id;
        root := f.access_root_id;
        PERFORM set_config('app.dc_inheriting', '1', true);
        IF root IS NOT NULL THEN
            INSERT INTO folder_access (folder_id, principal_kind, principal_id, principal_ref, level, granted_by)
            SELECT NEW.folder_id, fa.principal_kind, fa.principal_id, fa.principal_ref, fa.level, fa.granted_by
              FROM folder_access fa WHERE fa.folder_id = root
               AND NOT (fa.principal_kind = NEW.principal_kind AND fa.principal_id IS NOT DISTINCT FROM NEW.principal_id AND fa.principal_ref IS NOT DISTINCT FROM NEW.principal_ref);
        ELSE
            SELECT * INTO l FROM libraries WHERE id = f.library_id;
            IF l.kind = 'group' AND NOT (NEW.principal_kind = 'group' AND NEW.principal_id = l.group_id) THEN
                INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES (NEW.folder_id, 'group', l.group_id, (SELECT member_level FROM groups WHERE id = l.group_id), NEW.granted_by);
            ELSIF l.kind = 'shared' AND l.everyone_level <> 'none' AND NEW.principal_kind <> 'everyone' THEN
                INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES (NEW.folder_id, 'everyone', NULL, l.everyone_level, NEW.granted_by);
            END IF;
        END IF;
        PERFORM set_config('app.dc_inheriting', '', true);
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER folder_access_before BEFORE INSERT ON folder_access FOR EACH ROW EXECUTE FUNCTION dc_folder_access_before();

CREATE OR REPLACE FUNCTION dc_folder_access_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE fid bigint := COALESCE(NEW.folder_id, OLD.folder_id);
BEGIN
    INSERT INTO access_changes (folder_id, principal_kind, principal_id, principal_ref, old_level, new_level, changed_by)
    VALUES (fid, COALESCE(NEW.principal_kind, OLD.principal_kind), COALESCE(NEW.principal_id, OLD.principal_id), COALESCE(NEW.principal_ref, OLD.principal_ref),
            CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.level END, CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.level END,
            COALESCE(NEW.granted_by, app_current_member_id()));
    IF TG_OP <> 'UPDATE' THEN PERFORM dc_recompute_access_roots(fid); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER folder_access_after AFTER INSERT OR UPDATE OF level OR DELETE ON folder_access FOR EACH ROW EXECUTE FUNCTION dc_folder_access_after();

CREATE OR REPLACE FUNCTION dc_document_access_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE did bigint := COALESCE(NEW.document_id, OLD.document_id);
BEGIN
    INSERT INTO access_changes (document_id, principal_kind, principal_id, principal_ref, old_level, new_level, changed_by)
    VALUES (did, COALESCE(NEW.principal_kind, OLD.principal_kind), COALESCE(NEW.principal_id, OLD.principal_id), COALESCE(NEW.principal_ref, OLD.principal_ref),
            CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.level END, CASE WHEN TG_OP = 'DELETE' THEN NULL ELSE NEW.level END,
            COALESCE(NEW.granted_by, app_current_member_id()));
    UPDATE documents d SET own_access = EXISTS (SELECT 1 FROM document_access da WHERE da.document_id = did) WHERE d.id = did
       AND d.own_access IS DISTINCT FROM EXISTS (SELECT 1 FROM document_access da WHERE da.document_id = did);
    RETURN NULL;
END$$;
CREATE TRIGGER document_access_after AFTER INSERT OR UPDATE OF level OR DELETE ON document_access FOR EACH ROW EXECUTE FUNCTION dc_document_access_after();

-- A moved folder's subtree takes the roots of its new place.
CREATE OR REPLACE FUNCTION dc_folders_after_move_roots() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM dc_recompute_access_roots(NEW.id);
    RETURN NULL;
END$$;
CREATE TRIGGER folders_after_move_roots AFTER UPDATE OF parent_id ON folders FOR EACH ROW EXECUTE FUNCTION dc_folders_after_move_roots();

-- Restrict: write the explicit set without the inherited principals (PHP sets app.dc_restrict = '1' around the inserts; this
-- is the one-call form: keep only these rows).
CREATE OR REPLACE FUNCTION dc_folder_restrict(p_folder_id bigint, p_keep jsonb, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer := 0; r record;
BEGIN
    PERFORM set_config('app.dc_restrict', '1', true);
    DELETE FROM folder_access WHERE folder_id = p_folder_id;
    FOR r IN SELECT * FROM jsonb_to_recordset(p_keep) AS x(principal_kind text, principal_id bigint, principal_ref text, level text) LOOP
        INSERT INTO folder_access (folder_id, principal_kind, principal_id, principal_ref, level, granted_by)
        VALUES (p_folder_id, r.principal_kind, r.principal_id, r.principal_ref, r.level, p_by);
        n := n + 1;
    END LOOP;
    IF n = 0 THEN
        -- an empty restrict still needs a marker row so inheritance stops: the maker at manage
        INSERT INTO folder_access (folder_id, principal_kind, principal_id, level, granted_by) VALUES (p_folder_id, 'member', p_by, 'manage', p_by);
        n := 1;
    END IF;
    PERFORM set_config('app.dc_restrict', '', true);
    RETURN n;
END$$;

-- Why does a member see this? One row per reason (the share screen's "who can see this and why", §7 A1).
CREATE OR REPLACE FUNCTION dc_access_explain(p_document_id bigint, p_folder_id bigint, p_member_id bigint)
    RETURNS TABLE (reason text, via_kind text, via_id bigint, via_name text, level text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE d documents%ROWTYPE; f folders%ROWTYPE; l libraries%ROWTYPE; root bigint; base smallint;
BEGIN
    IF p_document_id IS NOT NULL THEN
        SELECT * INTO d FROM documents WHERE id = p_document_id; IF NOT FOUND THEN RETURN; END IF;
        SELECT * INTO f FROM folders WHERE id = d.folder_id;
        IF d.owner_member_id = p_member_id THEN
            RETURN QUERY SELECT 'owner'::text, 'member'::text, p_member_id, 'owns the document'::text, 'manage'::text;
        END IF;
    ELSE
        SELECT * INTO f FROM folders WHERE id = p_folder_id; IF NOT FOUND THEN RETURN; END IF;
        IF f.owner_member_id = p_member_id THEN
            RETURN QUERY SELECT 'owner'::text, 'member'::text, p_member_id, 'made the folder'::text, 'manage'::text;
        END IF;
    END IF;
    IF EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND ((m.member_kind = 'human' AND m.business_role = 'super_admin') OR 'admin' = ANY (m.roles))) THEN
        RETURN QUERY SELECT 'admin'::text, 'role'::text, NULL::bigint, 'DocCloud admin'::text, 'manage'::text;
    END IF;
    SELECT * INTO l FROM libraries WHERE id = f.library_id;
    base := dc_library_base_level(l.id, p_member_id);
    IF base > 0 THEN
        RETURN QUERY SELECT 'library'::text, l.kind, l.id, l.name, dc_rank_level(base);
    END IF;
    IF p_document_id IS NOT NULL AND d.own_access THEN
        RETURN QUERY
            SELECT 'document'::text, da.principal_kind, da.principal_id, dc_principal_name(da.principal_kind, da.principal_id, da.principal_ref), da.level
              FROM document_access da WHERE da.document_id = d.id AND dc_principal_matches(da.principal_kind, da.principal_id, p_member_id);
        RETURN;
    END IF;
    root := f.access_root_id;
    IF root IS NOT NULL THEN
        RETURN QUERY
            SELECT CASE WHEN fa.folder_id = f.id THEN 'folder' ELSE 'inherited' END, fa.principal_kind, fa.principal_id,
                   dc_principal_name(fa.principal_kind, fa.principal_id, fa.principal_ref) || CASE WHEN fa.folder_id = f.id THEN '' ELSE ' (from ' || (SELECT name FROM folders WHERE id = root) || ')' END, fa.level
              FROM folder_access fa WHERE fa.folder_id = root AND dc_principal_matches(fa.principal_kind, fa.principal_id, p_member_id);
    END IF;
END$$;

-- Does this principal reach this member? (the explain's helper; the live walls use dc_my_principals for the caller)
CREATE OR REPLACE FUNCTION dc_principal_matches(p_kind text, p_id bigint, p_member_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_kind
        WHEN 'member' THEN p_id = p_member_id
        WHEN 'agent' THEN p_id = p_member_id
        WHEN 'guest' THEN p_id = p_member_id
        WHEN 'group' THEN EXISTS (SELECT 1 FROM dc_member_group_ids(p_member_id) g WHERE g = p_id)
        WHEN 'department' THEN NOT dc_member_is_guest(p_member_id) AND EXISTS (SELECT 1 FROM department_members dm WHERE dm.member_id = p_member_id AND dm.department_id = p_id AND dm.left_at IS NULL)
        WHEN 'everyone' THEN NOT dc_member_is_guest(p_member_id) AND EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL)
        ELSE false END;
$$;

CREATE OR REPLACE FUNCTION dc_principal_name(p_kind text, p_id bigint, p_ref text) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT CASE p_kind
        WHEN 'everyone' THEN 'everyone in the business'
        WHEN 'application' THEN 'the application ' || p_ref
        WHEN 'group' THEN COALESCE((SELECT 'group ' || name FROM groups WHERE id = p_id), 'a group')
        WHEN 'department' THEN COALESCE((SELECT 'department ' || name FROM departments WHERE id = p_id), 'a department')
        ELSE COALESCE((SELECT display_name FROM members WHERE id = p_id), 'someone') END;
$$;

GRANT SELECT, INSERT, UPDATE, DELETE ON folder_access, document_access, access_changes TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_level_rank(text), dc_rank_level(smallint), dc_my_principals(), dc_library_base_level(bigint, bigint), dc_folder_rows_rank(bigint),
    dc_document_rows_rank(bigint), dc_folder_rank(bigint), dc_folder_level(bigint), dc_can_see_folder(bigint), dc_document_rank(bigint), dc_document_level(bigint),
    dc_can_see_document(bigint), dc_can_edit_document(bigint), dc_can_manage_document(bigint), dc_can_add_to_folder(bigint), dc_can_manage_folder(bigint),
    dc_application_folder_rank(bigint, text), dc_application_can_see_document(bigint, text), dc_access_explain(bigint, bigint, bigint),
    dc_principal_matches(text, bigint, bigint), dc_principal_name(text, bigint, text)
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_recompute_access_roots(bigint), dc_folder_restrict(bigint, jsonb, bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_recompute_access_roots(bigint), dc_folder_restrict(bigint, jsonb, bigint) TO doccloud_rw;

COMMIT;
