-- 004: DocCloud's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles; the claims and
-- the feed carry them back into members.roles. What a role lets its holder do is the application's to enforce:
-- dc_has_right(right) answers from the catalogue and the mirror. The base role's KEY IS `user` on purpose: the installer's
-- --grant-standing-departments gives the standing departments the role keyed member/user/write, and everyone inside the
-- business keeps a personal library and uploads (§3) — so the switch is right here.
--
-- Rights are application-wide capabilities; WHO SEES WHICH FOLDER OR DOCUMENT is the walls of db/010 (ownership, the library's
-- base level, inherited access rows, restrict): `curator` is the capability to make groups and shared libraries and keep the
-- vocabulary; group ownership is a row (group_members.role = owner), not a role. A DocCloud admin sees every library, personal
-- ones included — logged `library.admin_view` (D2). A guest (an external holding Guest and nothing above it) reaches only what
-- names them.
--
-- THE ESTATE'S DATA MODEL (design §6.4): the shape is Consultant Tracking db/004 = Knowledge db/004 (kn_ → dc_); the roles and
-- rights are this application's; dc_member_roles(member) is Inventory db/004's convenience (the roles a member holds, resolved).
BEGIN;

CREATE TABLE dc_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE dc_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX dc_roles_one_admin ON dc_roles (is_admin) WHERE is_admin;
CREATE TABLE dc_role_rights (
    role_key  text NOT NULL REFERENCES dc_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES dc_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO dc_rights (right_key, description, sort_order) VALUES
 ('documents.guest',    'Reach only the folders and documents shared with them explicitly; upload or edit where the share says so', 1),
 ('library.personal',   'A personal library of their own, unlimited depth', 2),
 ('documents.upload',   'Upload documents (one or many, folders as trees) where the tree allows', 3),
 ('documents.edit',     'Rename, move, describe, add a version and lock documents where the tree allows', 4),
 ('documents.share',    'Share a folder or a document they manage with a member, a group, a department or an agent', 5),
 ('links.make',         'Make a link that opens one folder or document to anyone holding it (pauses for an agent)', 6),
 ('requests.make',      'Make a file request: an upload-only link into a folder they manage (pauses for an agent)', 7),
 ('tags.apply',         'Apply managed tags and their own free tags', 8),
 ('search.use',         'Search by keyword across what they may see', 9),
 ('graph.use',          'Find documents through the document graph and browse the explorer', 10),
 ('versions.restore',   'Restore an earlier version of a document they may edit', 11),
 ('trash.own',          'Trash and restore their own documents and those of folders they manage', 12),
 ('export.own',         'Download a folder they may see as a zip', 13),
 ('groups.manage',      'Create groups (custom or from a department), name their owners, archive them', 14),
 ('libraries.shared',   'Create business-wide shared libraries and set their root access', 15),
 ('tags.manage',        'Keep the managed vocabulary: create, merge, delete tags; accept proposals; set auto-tag rules', 16),
 ('ownership.transfer', 'Transfer the documents of a leaver, or of anyone, to someone else', 17),
 ('reports.storage',    'See storage by library, group and owner; the duplicates', 18),
 ('reports.search',     'See what people searched for and what found nothing', 19),
 ('settings.manage',    'Run DocCloud: the application settings', 20),
 ('quotas.manage',      'Set a library''s quota', 21),
 ('retention.manage',   'Set the versions, trash, lock and link windows', 22),
 ('graph.manage',       'Set the graph policy per library and folder, the monthly budget and the model (pauses)', 23),
 ('links.all',          'See and revoke every link and file request', 24),
 ('trash.purge',        'Purge the trash before its time', 25),
 ('agents.settings',    'See every agent''s dispatches and the Clerk''s proposals; link to the kernel for hires and grants', 26),
 ('costs.read',         'See the graph''s engine usage by month', 27),
 ('library.admin_view', 'Open any library, personal ones included — logged', 28);

INSERT INTO dc_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('guest',   'Guest',          'Nothing by default: reaches only folders and documents shared to them explicitly. An external holding only this role.', 'read',  false, 1),
 ('user',    'Member',         'Everyone inside the business: a personal library, upload, folders, tags, sharing inside, links and file requests, the search and the graph.', 'write', false, 2),
 ('curator', 'Curator',        'A Member who also makes groups and shared libraries, keeps the tag vocabulary, transfers ownership and reads the storage and search reports.', 'write', false, 3),
 ('admin',   'DocCloud admin', 'Every right everywhere: settings, quotas, the windows, the graph policy and budget, every link, purge, costs; sees every library, logged. A super-admin holds this role.', 'admin', true, 4);

INSERT INTO dc_role_rights (role_key, right_key)
SELECT 'guest', 'documents.guest'
UNION ALL SELECT 'user', r FROM unnest(ARRAY['library.personal','documents.upload','documents.edit','documents.share','links.make','requests.make','tags.apply',
                                             'search.use','graph.use','versions.restore','trash.own','export.own']) r
UNION ALL SELECT 'curator', r FROM unnest(ARRAY['library.personal','documents.upload','documents.edit','documents.share','links.make','requests.make','tags.apply',
                                                'search.use','graph.use','versions.restore','trash.own','export.own',
                                                'groups.manage','libraries.shared','tags.manage','ownership.transfer','reports.storage','reports.search']) r
UNION ALL SELECT 'admin', right_key FROM dc_rights;

-- The roles a member holds, resolved: the kernel's list; a super-admin always holds admin; a member the kernel has not yet sent
-- roles for is read from the capability (admin = admin, read = guest, anything else = user). Empty for nobody.
CREATE OR REPLACE FUNCTION dc_member_roles(p_member_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(m.roles || ARRAY['admin']) x)
             WHEN cardinality(m.roles) > 0 THEN m.roles
             ELSE ARRAY[CASE m.capability WHEN 'admin' THEN 'admin' WHEN 'read' THEN 'guest' ELSE 'user' END]
           END
      INTO r
      FROM members m
     WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN COALESCE(r, '{}');
END$$;

-- The rule: a right the member's roles give. The member must be active and admitted.
CREATE OR REPLACE FUNCTION dc_has_right(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM dc_role_rights rr
         WHERE rr.right_key = p_right AND rr.role_key = ANY (dc_member_roles(app_current_member_id())));
END$$;

-- Runs DocCloud: the super-admin, or a human holding settings.manage.
CREATE OR REPLACE FUNCTION dc_is_admin() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_super_admin() OR (app_member_kind() = 'human' AND dc_has_right('settings.manage'));
END$$;

-- A guest: admitted, external, and holding no role above Guest (every default denies them; they reach only what names them).
CREATE OR REPLACE FUNCTION dc_member_is_guest(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM members m WHERE m.id = p_member_id AND m.status = 'active' AND m.capability IS NOT NULL
                     AND m.is_external
                     AND NOT (m.roles && ARRAY['user', 'curator', 'admin'])
                     AND NOT (cardinality(m.roles) = 0 AND m.capability IN ('write', 'admin')));
END$$;

CREATE OR REPLACE FUNCTION dc_is_guest() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN dc_member_is_guest(app_current_member_id());
END$$;

-- Someone who belongs in the business here: admitted and not a guest (a Member, a Curator, an admin, an agent granted a role).
-- The `everyone` principal reaches exactly these.
CREATE OR REPLACE FUNCTION dc_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND NOT dc_is_guest();
END$$;

-- What app_roles answers, in one read.
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM dc_role_rights rr
                   JOIN dc_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM dc_roles r;

GRANT SELECT ON dc_rights, dc_roles, dc_role_rights, mcp_app_roles TO doccloud_rw, doccloud_records_ro;
GRANT EXECUTE ON FUNCTION dc_member_roles(bigint), dc_has_right(text), dc_is_admin(), dc_is_guest(), dc_member_is_guest(bigint), dc_is_member_here()
    TO doccloud_rw, doccloud_records_ro, doccloud_activity_ro;

COMMIT;
