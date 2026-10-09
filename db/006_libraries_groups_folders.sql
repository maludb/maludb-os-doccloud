-- 006: LIBRARIES, GROUPS and FOLDERS — the roots of the store and the tree under each (design §2, §3, §6.2; D5, D6).
--
-- A LIBRARY is a root: `personal` (one per member, made on first contact — dc_library_ensure_personal), `group` (one per
-- group, made WITH the group by trigger and surviving it), `shared` (business-wide, made by a curator). A GROUP is the
-- estate's first group table: a named set of members and agents with owners — `custom` (curated by its owners) or a
-- `department` mirror (its members follow the directory: dc_group_sync_department, called by the directory sync). A FOLDER
-- is a node of a library's tree: `path` ('/1/5/9/') and `depth` are kept by trigger so a subtree is one range query and a
-- move is one statement; a name is unique among LIVE siblings, case-insensitively; a folder trashed takes its subtree.
-- `access_root_id` (the nearest ancestor-or-self with explicit access rows; NULL = the library's base level) is kept by
-- db/008, which owns the access rows; `graph_policy` NULL = the library's (db/012).
--
-- THE ESTATE'S DATA MODEL (design §6.4): all NEW and canonical — departments are the kernel's, `space_members` and the like
-- are memberships of a thing, not a group; no sibling keeps a folder tree. The principal pattern of the access rows (db/008)
-- is Spaces' page_permissions with `group` added.
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- Groups.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE groups (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key             text NOT NULL UNIQUE CHECK (key ~ '^[a-z0-9][a-z0-9_-]{0,59}$'),
    name            text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    description     text,
    kind            text NOT NULL DEFAULT 'custom' CHECK (kind IN ('custom', 'department')),
    department_id   bigint REFERENCES departments(id) ON DELETE SET NULL,     -- the mirrored department (kind = department)
    member_level    text NOT NULL DEFAULT 'edit' CHECK (member_level IN ('view', 'edit')),   -- the base level the group library gives its members
    created_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    archived_at     timestamptz,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((kind = 'department') = (department_id IS NOT NULL))
);
CREATE UNIQUE INDEX groups_department_live ON groups (department_id) WHERE department_id IS NOT NULL AND archived_at IS NULL;
CREATE INDEX groups_name_trgm_idx ON groups USING gin (name gin_trgm_ops);
CREATE TRIGGER groups_touch BEFORE UPDATE ON groups FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE group_members (
    group_id    bigint NOT NULL REFERENCES groups(id) ON DELETE CASCADE,
    member_id   bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    role        text NOT NULL DEFAULT 'member' CHECK (role IN ('owner', 'member')),
    added_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    joined_at   timestamptz NOT NULL DEFAULT now(),
    left_at     timestamptz,                                               -- dated, never erased
    PRIMARY KEY (group_id, member_id)
);
CREATE INDEX group_members_member_idx ON group_members (member_id) WHERE left_at IS NULL;
CREATE INDEX group_members_group_live_idx ON group_members (group_id) WHERE left_at IS NULL;

-- The live groups of a member (a principal set; db/008 reads it once per statement).
CREATE OR REPLACE FUNCTION dc_member_group_ids(p_member_id bigint) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT gm.group_id FROM group_members gm JOIN groups g ON g.id = gm.group_id AND g.archived_at IS NULL
     WHERE gm.member_id = p_member_id AND gm.left_at IS NULL;
$$;

CREATE OR REPLACE FUNCTION dc_is_group_owner(p_group_id bigint, p_member_id bigint) RETURNS boolean
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = p_group_id AND gm.member_id = p_member_id AND gm.role = 'owner' AND gm.left_at IS NULL);
$$;

-- A department group's members follow the directory: whoever is in the department now is in the group; whoever left is
-- dated out. The department's manager is the group's owner (a directory fact); a custom group is never touched.
CREATE OR REPLACE FUNCTION dc_group_sync_department(p_group_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE g groups%ROWTYPE; n integer := 0; k integer;
BEGIN
    SELECT * INTO g FROM groups WHERE id = p_group_id AND kind = 'department';
    IF NOT FOUND THEN RETURN 0; END IF;
    -- in: live department members not yet live in the group
    INSERT INTO group_members (group_id, member_id, role, added_by)
    SELECT g.id, dm.member_id,
           CASE WHEN EXISTS (SELECT 1 FROM departments d WHERE d.id = g.department_id AND d.manager_member_id = dm.member_id) THEN 'owner' ELSE 'member' END,
           NULL
      FROM department_members dm JOIN members m ON m.id = dm.member_id AND m.status = 'active'
     WHERE dm.department_id = g.department_id AND dm.left_at IS NULL
    ON CONFLICT (group_id, member_id) DO UPDATE SET left_at = NULL, joined_at = CASE WHEN group_members.left_at IS NULL THEN group_members.joined_at ELSE now() END,
        role = EXCLUDED.role;
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    -- out: live group members no longer in the department
    UPDATE group_members gm SET left_at = now()
     WHERE gm.group_id = g.id AND gm.left_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM department_members dm JOIN members m ON m.id = dm.member_id AND m.status = 'active'
                        WHERE dm.department_id = g.department_id AND dm.member_id = gm.member_id AND dm.left_at IS NULL);
    GET DIAGNOSTICS k = ROW_COUNT; n := n + k;
    RETURN n;
END$$;

-- Every department group at once (the directory sync's last step).
CREATE OR REPLACE FUNCTION dc_groups_sync_departments() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; n integer := 0;
BEGIN
    FOR r IN SELECT id FROM groups WHERE kind = 'department' AND archived_at IS NULL LOOP
        n := n + dc_group_sync_department(r.id);
    END LOOP;
    RETURN n;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Libraries.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE libraries (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    kind             text NOT NULL CHECK (kind IN ('personal', 'group', 'shared')),
    name             text NOT NULL CHECK (length(name) BETWEEN 1 AND 120),
    description      text,
    owner_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,     -- personal: whose; shared: who made it
    group_id         bigint REFERENCES groups(id) ON DELETE SET NULL,      -- group: whose
    everyone_level   text NOT NULL DEFAULT 'none' CHECK (everyone_level IN ('none', 'view', 'upload', 'edit')),   -- shared: what every member of the business gets
    graph_policy     text NOT NULL DEFAULT 'index' CHECK (graph_policy IN ('none', 'index', 'full')),               -- D8; a folder may override (folders.graph_policy)
    quota_bytes      bigint CHECK (quota_bytes IS NULL OR quota_bytes > 0),                                        -- NULL = none
    used_bytes       bigint NOT NULL DEFAULT 0 CHECK (used_bytes >= 0),                                            -- kept by trigger from the versions (db/007)
    root_folder_id   bigint,                                                                                       -- FK below, after folders
    archived_at      timestamptz,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (kind <> 'personal' OR (owner_member_id IS NOT NULL AND group_id IS NULL)),
    CHECK ((kind = 'group') = (group_id IS NOT NULL)),
    CHECK (kind = 'shared' OR everyone_level = 'none')
);
CREATE UNIQUE INDEX libraries_personal_one ON libraries (owner_member_id) WHERE kind = 'personal';
CREATE UNIQUE INDEX libraries_group_one ON libraries (group_id) WHERE kind = 'group';
CREATE INDEX libraries_kind_idx ON libraries (kind) WHERE archived_at IS NULL;
CREATE INDEX libraries_name_trgm_idx ON libraries USING gin (name gin_trgm_ops);
CREATE TRIGGER libraries_touch BEFORE UPDATE ON libraries FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- ---------------------------------------------------------------------------------------------
-- Folders.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE folders (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    library_id       bigint NOT NULL REFERENCES libraries(id) ON DELETE CASCADE,
    parent_id        bigint REFERENCES folders(id) ON DELETE CASCADE,       -- NULL = the library's root (one per library)
    name             text NOT NULL CHECK (length(name) BETWEEN 1 AND 255 AND name !~ '[/\\]' AND name NOT IN ('.', '..')),
    path             text NOT NULL DEFAULT '/',                             -- '/<root>/<...>/<id>/', kept by trigger
    depth            smallint NOT NULL DEFAULT 0 CHECK (depth BETWEEN 0 AND 32),
    access_root_id   bigint REFERENCES folders(id) ON DELETE SET NULL,      -- db/008 keeps it; NULL = the library's base
    owner_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,      -- who made it: manages it (design §3 rule 1, extended to folders)
    auto_tags        text[] NOT NULL DEFAULT '{}',                          -- slugs applied to every document added under it (db/009)
    graph_policy     text CHECK (graph_policy IS NULL OR graph_policy IN ('none', 'index', 'full')),   -- NULL = inherit (db/012)
    colour           text CHECK (colour IS NULL OR colour ~ '^#[0-9a-f]{6}$'),
    trashed_at       timestamptz,
    trashed_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (parent_id IS NULL OR parent_id <> id)
);
CREATE UNIQUE INDEX folders_root_one ON folders (library_id) WHERE parent_id IS NULL;
CREATE UNIQUE INDEX folders_sibling_name_live ON folders (parent_id, lower(name)) WHERE parent_id IS NOT NULL AND trashed_at IS NULL;
CREATE INDEX folders_parent_idx ON folders (parent_id) WHERE trashed_at IS NULL;
CREATE INDEX folders_library_idx ON folders (library_id);
CREATE INDEX folders_path_idx ON folders (path text_pattern_ops);           -- a subtree is `path LIKE '/1/5/%'`
CREATE INDEX folders_access_root_idx ON folders (access_root_id);
CREATE INDEX folders_name_trgm_idx ON folders USING gin (name gin_trgm_ops);
CREATE INDEX folders_trashed_idx ON folders (trashed_at) WHERE trashed_at IS NOT NULL;
CREATE TRIGGER folders_touch BEFORE UPDATE ON folders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

ALTER TABLE libraries ADD CONSTRAINT libraries_root_folder_fk FOREIGN KEY (root_folder_id) REFERENCES folders(id) ON DELETE SET NULL;

-- A folder's path and depth: its parent's path + its id. The parent is in the same library and live; depth ≤ 32; no cycles
-- (a folder may not move under its own subtree). On a move the whole subtree is rewritten in one statement.
CREATE OR REPLACE FUNCTION dc_folders_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p folders%ROWTYPE;
BEGIN
    IF NEW.parent_id IS NULL THEN
        IF TG_OP = 'UPDATE' AND OLD.parent_id IS NOT NULL THEN
            RAISE EXCEPTION 'A folder cannot become a root' USING ERRCODE = 'check_violation';
        END IF;
        NEW.path := '/' || NEW.id || '/';
        NEW.depth := 0;
        RETURN NEW;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.parent_id IS NULL THEN
        RAISE EXCEPTION 'A library''s root cannot be moved' USING ERRCODE = 'check_violation';
    END IF;
    SELECT * INTO p FROM folders WHERE id = NEW.parent_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such parent folder' USING ERRCODE = 'foreign_key_violation'; END IF;
    IF p.library_id <> NEW.library_id THEN
        IF TG_OP = 'INSERT' THEN NEW.library_id := p.library_id;
        ELSE RAISE EXCEPTION 'A folder moves within its library (copy it across libraries)' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    IF p.trashed_at IS NOT NULL AND NEW.trashed_at IS NULL THEN
        RAISE EXCEPTION 'The parent folder is in the trash' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'UPDATE' AND p.path LIKE OLD.path || '%' THEN
        RAISE EXCEPTION 'A folder cannot move under itself' USING ERRCODE = 'check_violation';
    END IF;
    IF p.depth + 1 > 32 THEN RAISE EXCEPTION 'Folders nest at most 32 deep' USING ERRCODE = 'check_violation'; END IF;
    NEW.path := p.path || NEW.id || '/';
    NEW.depth := p.depth + 1;
    RETURN NEW;
END$$;
CREATE TRIGGER folders_before BEFORE INSERT OR UPDATE OF parent_id, library_id, trashed_at ON folders FOR EACH ROW EXECUTE FUNCTION dc_folders_before();

-- After a move: the subtree's paths and depths follow (one statement); the access roots are recomputed by db/008's trigger.
CREATE OR REPLACE FUNCTION dc_folders_after_move() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.path IS DISTINCT FROM OLD.path THEN
        UPDATE folders f SET path = NEW.path || substr(f.path, length(OLD.path) + 1), depth = f.depth + (NEW.depth - OLD.depth)
         WHERE f.path LIKE OLD.path || '%' AND f.id <> NEW.id;
        IF EXISTS (SELECT 1 FROM folders f WHERE f.path LIKE NEW.path || '%' AND f.depth > 32) THEN
            RAISE EXCEPTION 'Folders nest at most 32 deep' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER folders_after_move AFTER UPDATE OF parent_id ON folders FOR EACH ROW EXECUTE FUNCTION dc_folders_after_move();

-- Trash a folder: its subtree goes with it, dated the same; restore brings back what was trashed WITH it (the same stamp),
-- never what was trashed on its own before (that stays in the trash with its own stamp).
CREATE OR REPLACE FUNCTION dc_folder_trash(p_folder_id bigint, p_by bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE f folders%ROWTYPE; stamp timestamptz := now(); n integer;
BEGIN
    SELECT * INTO f FROM folders WHERE id = p_folder_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such folder' USING ERRCODE = 'no_data_found'; END IF;
    IF f.parent_id IS NULL THEN RAISE EXCEPTION 'A library''s root cannot be trashed' USING ERRCODE = 'check_violation'; END IF;
    IF f.trashed_at IS NOT NULL THEN RETURN 0; END IF;
    UPDATE folders SET trashed_at = stamp, trashed_by = p_by WHERE path LIKE f.path || '%' AND trashed_at IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    UPDATE documents SET trashed_at = stamp, trashed_by = p_by, trashed_from_folder_id = folder_id
     WHERE folder_id IN (SELECT id FROM folders WHERE path LIKE f.path || '%') AND trashed_at IS NULL;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION dc_folder_restore(p_folder_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE f folders%ROWTYPE; n integer; target bigint;
BEGIN
    SELECT * INTO f FROM folders WHERE id = p_folder_id FOR UPDATE;
    IF NOT FOUND OR f.trashed_at IS NULL THEN RETURN 0; END IF;
    -- its parent must be live; else it lands at the library root
    IF EXISTS (SELECT 1 FROM folders p WHERE p.id = f.parent_id AND p.trashed_at IS NOT NULL) THEN
        SELECT root_folder_id INTO target FROM libraries WHERE id = f.library_id;
        UPDATE folders SET parent_id = target, trashed_at = NULL, trashed_by = NULL WHERE id = f.id;
    ELSE
        UPDATE folders SET trashed_at = NULL, trashed_by = NULL WHERE id = f.id;
    END IF;
    UPDATE folders SET trashed_at = NULL, trashed_by = NULL WHERE path LIKE f.path || '%' AND id <> f.id AND trashed_at = f.trashed_at;
    GET DIAGNOSTICS n = ROW_COUNT;
    UPDATE documents SET trashed_at = NULL, trashed_by = NULL, trashed_from_folder_id = NULL
     WHERE folder_id IN (SELECT id FROM folders WHERE path LIKE f.path || '%') AND trashed_at = f.trashed_at;
    RETURN n + 1;
END$$;

-- The folders of a subtree, root first (a listing's crumbs are the reverse: dc_folder_path).
CREATE OR REPLACE FUNCTION dc_folder_subtree_ids(p_folder_id bigint) RETURNS SETOF bigint
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT f.id FROM folders f WHERE f.path LIKE (SELECT path FROM folders WHERE id = p_folder_id) || '%';
$$;

CREATE OR REPLACE FUNCTION dc_folder_path(p_folder_id bigint)
    RETURNS TABLE (folder_id bigint, name text, depth smallint)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT a.id, a.name, a.depth FROM folders f
      JOIN folders a ON f.path LIKE a.path || '%'
     WHERE f.id = p_folder_id ORDER BY a.depth;
$$;

-- ---------------------------------------------------------------------------------------------
-- Making libraries: a personal one on first contact; a group's with the group; a shared one by a curator.
-- Each gets its root folder (named after the library, owned by nobody: the library's rules decide).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION dc_library_make_root(p_library_id bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE rid bigint; nm text;
BEGIN
    SELECT root_folder_id, name INTO rid, nm FROM libraries WHERE id = p_library_id;
    IF rid IS NOT NULL THEN RETURN rid; END IF;
    INSERT INTO folders (library_id, parent_id, name) VALUES (p_library_id, NULL, nm) RETURNING id INTO rid;
    UPDATE libraries SET root_folder_id = rid WHERE id = p_library_id;
    RETURN rid;
END$$;

CREATE OR REPLACE FUNCTION dc_library_ensure_personal(p_member_id bigint) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lid bigint; nm text;
BEGIN
    SELECT id INTO lid FROM libraries WHERE kind = 'personal' AND owner_member_id = p_member_id;
    IF FOUND THEN RETURN lid; END IF;
    SELECT display_name INTO nm FROM members WHERE id = p_member_id AND status = 'active' AND NOT is_external;
    IF NOT FOUND THEN RETURN NULL; END IF;                 -- a guest has no personal library (design §3)
    INSERT INTO libraries (kind, name, owner_member_id, created_by) VALUES ('personal', nm, p_member_id, p_member_id) RETURNING id INTO lid;
    PERFORM dc_library_make_root(lid);
    RETURN lid;
END$$;

CREATE OR REPLACE FUNCTION dc_groups_after_insert() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE lid bigint;
BEGIN
    INSERT INTO libraries (kind, name, group_id, created_by) VALUES ('group', NEW.name, NEW.id, NEW.created_by) RETURNING id INTO lid;
    PERFORM dc_library_make_root(lid);
    IF NEW.created_by IS NOT NULL AND NEW.kind = 'custom' THEN
        INSERT INTO group_members (group_id, member_id, role, added_by) VALUES (NEW.id, NEW.created_by, 'owner', NEW.created_by) ON CONFLICT DO NOTHING;
    END IF;
    IF NEW.kind = 'department' THEN PERFORM dc_group_sync_department(NEW.id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER groups_after_insert AFTER INSERT ON groups FOR EACH ROW EXECUTE FUNCTION dc_groups_after_insert();

-- A group's library follows its name and its archiving (the documents stay: the admin and each owner still reach them).
CREATE OR REPLACE FUNCTION dc_groups_after_update() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE libraries SET name = NEW.name, archived_at = NEW.archived_at WHERE group_id = NEW.id AND kind = 'group'
       AND (name IS DISTINCT FROM NEW.name OR archived_at IS DISTINCT FROM NEW.archived_at);
    RETURN NULL;
END$$;
CREATE TRIGGER groups_after_update AFTER UPDATE OF name, archived_at ON groups FOR EACH ROW EXECUTE FUNCTION dc_groups_after_update();

CREATE OR REPLACE FUNCTION dc_libraries_after_insert() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.root_folder_id IS NULL THEN PERFORM dc_library_make_root(NEW.id); END IF;
    RETURN NULL;
END$$;
CREATE TRIGGER libraries_after_insert AFTER INSERT ON libraries FOR EACH ROW EXECUTE FUNCTION dc_libraries_after_insert();

GRANT SELECT, INSERT, UPDATE, DELETE ON groups, group_members, libraries, folders TO doccloud_rw;
GRANT EXECUTE ON FUNCTION dc_member_group_ids(bigint), dc_is_group_owner(bigint, bigint), dc_folder_subtree_ids(bigint), dc_folder_path(bigint)
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;
REVOKE ALL ON FUNCTION dc_group_sync_department(bigint), dc_groups_sync_departments(), dc_folder_trash(bigint, bigint), dc_folder_restore(bigint),
    dc_library_make_root(bigint), dc_library_ensure_personal(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION dc_group_sync_department(bigint), dc_groups_sync_departments(), dc_folder_trash(bigint, bigint), dc_folder_restore(bigint),
    dc_library_make_root(bigint), dc_library_ensure_personal(bigint) TO doccloud_rw;

COMMIT;
