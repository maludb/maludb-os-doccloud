"""Libraries, groups and folders (L1-L5): find_libraries, get_library, folder_listing, get_folder, folder_path, find_folders, find_groups, get_group, group_members, my_groups,
storage_by_library, storage_by_owner, quota_state. Every read is a view or a dc_* read function; the wall is theirs."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from dc_common import (qmatch, RO, Ref, Refusal, Where, _Base, answers, bridge, dumps, flag, folder_ref, group_ref, has_right, library_ref, like, lim, off, plain, q_alone, need_right, not_found)


class FindLibrariesIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the library's name (alone: resolves one library by name)")
    kind: Literal["personal", "group", "shared"] | None = Field(None, description="personal, group or shared")
    include_archived: bool = Field(False, description="Include archived libraries")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class LibraryIn(_Base):
    library: Ref = Field(..., description="The library (id or name)")


class FolderListingIn(_Base):
    folder: Ref = Field(..., description="The folder (id, name or a /a/b/c path)")
    sort: Literal["name", "updated", "size"] = "name"
    dir: Literal["asc", "desc"] = "asc"
    kind: str | None = Field(None, description="folder, document, or a kind of file (image, pdf, text …)")
    limit: int = Field(100, ge=1, le=500, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class FolderIn(_Base):
    folder: Ref = Field(..., description="The folder (id, name or a /a/b/c path)")


class FindFoldersIn(_Base):
    q: str | None = Field(None, max_length=200, description="A name, or a /a/b/c path (alone: resolves one folder)")
    library: Ref | None = Field(None, description="Only this library (id or name)")
    under: Ref | None = Field(None, description="Only this folder's subtree (id or path)")
    include_trashed: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class FindGroupsIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the group's key or name (alone: resolves one group)")
    kind: Literal["custom", "department"] | None = None
    mine: bool = Field(False, description="Only groups I am in or own")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class GroupIn(_Base):
    group: Ref = Field(..., description="The group (id, key or name)")


class GroupMembersIn(_Base):
    group: Ref = Field(..., description="The group (id, key or name)")
    role: Literal["owner", "member"] | None = None
    include_left: bool = False
    limit: int = Field(100, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ByLibraryIn(_Base):
    near_only: bool = Field(False, description="Only libraries near their quota")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class ByOwnerIn(_Base):
    limit: int = Field(25, ge=1, le=500, description="How many rows at most")


async def _path(q, folder_id: int) -> list[dict]:
    return await q("SELECT folder_id, name, depth FROM dc_folder_path($1) ORDER BY depth", folder_id)


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_libraries", annotations={"title": "Libraries I can reach", **RO})
    @answers
    async def find_libraries(params: FindLibrariesIn) -> str:
        """Which libraries I may reach — personal, group or shared — how full each is, and with what graph policy; `my_level` is what I may do there. Alone, `q` resolves a library by name (a plain list of {library_id, name})."""
        w = Where()
        qmatch(w, params.q, "l.library_id", "l.name")
        if params.kind:
            w.add("l.kind = {}", params.kind)
        if not params.include_archived:
            w.raw("l.archived_at IS NULL")
        rows = await q(f"""SELECT l.library_id, l.kind, l.name, l.group_id, l.root_folder_id, l.my_level, l.used_bytes, l.quota_bytes, l.graph_policy, l.archived_at
                             FROM mcp_libraries l WHERE {w.sql()} ORDER BY (l.kind = 'personal') DESC, lower(l.name) LIMIT {lim(params.limit)}""", *w.args)
        return plain(rows, "library_id", "name", "kind") if q_alone(params) else dumps(rows)

    @mcp.tool(name="get_library", annotations={"title": "One library at a glance", **RO})
    @answers
    async def get_library(params: LibraryIn) -> str:
        """One library: its quota and how full it is, its policy, its group or owner, the root folder, how many folders and documents. An admin opening a personal library they hold no level on sees it and the opening is logged (library.admin_view)."""
        lid = await library_ref(q, params.library)
        lib = (await q("SELECT library_id, kind, name, description, owner_member_id, group_id, everyone_level, graph_policy, quota_bytes, used_bytes, root_folder_id, archived_at, my_level FROM mcp_libraries WHERE library_id = $1", lid))[0]
        if lib["my_level"] == "none":
            raise not_found("library")
        base = (await q("SELECT dc_library_base_level($1, app_current_member_id()) AS b", lid))[0]["b"]
        admin_view = bool(await flag(q, "dc_is_admin()")) and int(base or 0) == 0
        if admin_view and bridge_ is not None:
            try:
                await bridge_("admin_view", {"library_id": lid})
            except Refusal:
                pass                                           # the log is the door's; a library is still readable when it is down
        quota = (await q("SELECT used_bytes, quota_bytes, pct, near FROM dc_quota_state($1)", lid))[0]
        group = None
        if lib["group_id"]:
            g = await q("SELECT group_id, key, name, kind, member_count FROM mcp_groups WHERE group_id = $1", lib["group_id"])
            group = g[0] if g else None
        owner = await q("SELECT display_name FROM mcp_members WHERE member_id = $1", lib["owner_member_id"]) if lib["owner_member_id"] else []
        counts = (await q("SELECT count(*) AS folders, COALESCE(sum(document_count), 0) AS documents FROM mcp_folders WHERE library_id = $1 AND trashed_at IS NULL", lid))[0]
        out = {"library": lib, "quota": quota, "group": group, "owner_name": owner[0]["display_name"] if owner else None, "root_folder_id": lib["root_folder_id"],
               "document_count": int(counts["documents"]), "folder_count": int(counts["folders"])}
        if admin_view:
            out["admin_view"] = True
        return dumps(out)

    @mcp.tool(name="folder_listing", annotations={"title": "What is in a folder", **RO})
    @answers
    async def folder_listing(params: FolderListingIn) -> str:
        """What is in folder X: subfolders first, then documents — sorted, paged, with tags, sizes and my level on each. An `upload` holder sees only their own documents. The tool to browse with."""
        fid = await folder_ref(q, params.folder)
        f = (await q("SELECT folder_id, name, library_id, parent_id, my_level, trashed_at FROM mcp_folders WHERE folder_id = $1", fid))[0]
        rows = await q("SELECT kind, id, name, mime_kind, byte_size, owner_member_id, owner_name, updated_at, tags, level, locked_by, version_count, has_thumbnail, total FROM dc_folder_listing($1, $2, $3, $4, $5, $6)",
                       fid, params.sort, params.dir, params.limit, params.offset, params.kind)
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        return dumps({"folder": {"folder_id": fid, "name": f["name"], "path": await _path(q, fid), "my_level": f["my_level"]}, "rows": rows, "total": total})

    @mcp.tool(name="get_folder", annotations={"title": "One folder", **RO})
    @answers
    async def get_folder(params: FolderIn) -> str:
        """One folder: its crumbs, counts, auto-tags, the graph policy that applies to it, and an access summary (the counts by kind when I manage it; where it inherits its access from otherwise)."""
        fid = await folder_ref(q, params.folder)
        f = (await q("SELECT folder_id, library_id, parent_id, name, path, depth, access_root_id, owner_member_id, auto_tags, graph_policy, colour, trashed_at, created_at, updated_at, my_level, document_count FROM mcp_folders WHERE folder_id = $1", fid))[0]
        policy = (await q("SELECT dc_effective_graph_policy($1) AS p", fid))[0]["p"]
        sub = (await q("SELECT count(*) AS n FROM mcp_folders WHERE parent_id = $1 AND trashed_at IS NULL", fid))[0]["n"]
        bytes_ = (await q("SELECT COALESCE(sum(byte_size), 0) AS b FROM mcp_documents WHERE folder_id IN (SELECT dc_folder_subtree_ids($1)) AND trashed_at IS NULL", fid))[0]["b"]
        root = f["access_root_id"]
        inherited = None
        if root and root != fid:
            r = await q("SELECT folder_id, name FROM mcp_folders WHERE folder_id = $1", root)
            inherited = r[0] if r else {"folder_id": root, "name": None}
        summary: dict = {"inherited_from": inherited}
        if (await q("SELECT dc_can_manage_folder($1) AS m", fid))[0]["m"]:
            kinds = await q("SELECT principal_kind, count(*) AS n FROM mcp_folder_access WHERE folder_id = $1 AND level <> 'none' GROUP BY 1", root or fid)
            by = {r["principal_kind"]: int(r["n"]) for r in kinds}
            summary = {"people": by.get("member", 0) + by.get("guest", 0), "groups": by.get("group", 0), "departments": by.get("department", 0), "applications": by.get("application", 0),
                       "everyone": by.get("everyone", 0), "inherited_from": inherited}
        return dumps({"folder": f, "path": await _path(q, fid), "effective_graph_policy": policy, "subfolder_count": int(sub), "document_count": int(f["document_count"] or 0),
                      "subtree_bytes": int(bytes_), "access_summary": summary})

    @mcp.tool(name="folder_path", annotations={"title": "Where a folder is", **RO})
    @answers
    async def folder_path(params: FolderIn) -> str:
        """Where a folder is: its path from the library root, root first."""
        fid = await folder_ref(q, params.folder)
        return dumps(await _path(q, fid))

    @mcp.tool(name="find_folders", annotations={"title": "Find a folder", **RO})
    @answers
    async def find_folders(params: FindFoldersIn) -> str:
        """A folder by name or path (to move something into, to share). Alone, `q` resolves one folder: a plain list of {folder_id, path_text}."""
        w = Where().raw("f.parent_id IS NOT NULL")
        if not params.include_trashed:
            w.raw("f.trashed_at IS NULL")
        if params.q:
            qq = params.q.strip()
            last = qq.strip("/ ").split("/")[-1].split("›")[-1].strip()
            qmatch(w, last, "f.folder_id", "f.name")
        if params.library not in (None, ""):
            w.add("f.library_id = {}", await library_ref(q, params.library))
        if params.under not in (None, ""):
            w.add("f.folder_id IN (SELECT dc_folder_subtree_ids({}))", await folder_ref(q, params.under))
        rows = await q(f"""WITH c AS (SELECT f.folder_id, f.name, f.library_id, f.parent_id, f.depth, f.my_level, f.trashed_at FROM mcp_folders f WHERE {w.sql()} ORDER BY lower(f.name) LIMIT {lim(params.limit) * 4})
                           SELECT c.folder_id, c.name, dc_folder_path_text(c.folder_id) AS path_text, c.library_id, c.parent_id, c.depth, c.my_level, c.trashed_at FROM c ORDER BY lower(c.name) LIMIT {lim(params.limit)}""", *w.args)
        if params.q and ("/" in params.q or "›" in params.q):
            want = [x.strip().lower() for x in params.q.replace("›", "/").split("/") if x.strip()]
            rows = [r for r in rows if [x.strip().lower() for x in (r["path_text"] or "").split("›")][-len(want):] == want]
        for r in rows:
            r["path"] = r["path_text"]                         # the registry's resolver names `path` as the folder's label
        return dumps([{"folder_id": r["folder_id"], "path": r["path_text"], "path_text": r["path_text"]} for r in rows[:6]]) if q_alone(params) else dumps(rows)

    @mcp.tool(name="find_groups", annotations={"title": "Groups", **RO})
    @answers
    async def find_groups(params: FindGroupsIn) -> str:
        """Which groups exist; which I am in or own. Alone, `q` resolves a group by key or name (a plain list of {group_id, name, key})."""
        need_right(await flag(q, "dc_is_member_here()"), "Groups are a member's tool: a guest sees only what is shared with them.")
        w = Where()
        qmatch(w, params.q, "g.group_id", "g.key", "g.name")
        if params.kind:
            w.add("g.kind = {}", params.kind)
        if params.mine:
            w.raw("(g.i_am_member OR g.i_am_owner)")
        if not params.include_archived:
            w.raw("g.archived_at IS NULL")
        rows = await q(f"SELECT g.group_id, g.key, g.name, g.description, g.kind, g.department_id, g.member_level, g.member_count, g.i_am_member, g.i_am_owner, g.archived_at FROM mcp_groups g WHERE {w.sql()} ORDER BY lower(g.name) LIMIT {lim(params.limit)}", *w.args)
        return plain(rows, "group_id", "name", "key") if q_alone(params) else dumps(rows)

    @mcp.tool(name="get_group", annotations={"title": "One group", **RO})
    @answers
    async def get_group(params: GroupIn) -> str:
        """One group: its owners, its library, its level."""
        need_right(await flag(q, "dc_is_member_here()"), "Groups are a member's tool.")
        gid = await group_ref(q, params.group)
        g = (await q("SELECT group_id, key, name, description, kind, department_id, member_level, member_count, i_am_member, i_am_owner, archived_at FROM mcp_groups WHERE group_id = $1", gid))[0]
        owners = await q("SELECT member_id, display_name FROM mcp_group_members WHERE group_id = $1 AND role = 'owner' AND left_at IS NULL ORDER BY display_name", gid)
        lib = await q("SELECT library_id, root_folder_id, used_bytes, quota_bytes FROM mcp_libraries WHERE group_id = $1", gid)
        return dumps({"group": g, "owners": owners, "library": lib[0] if lib else None})

    @mcp.tool(name="group_members", annotations={"title": "Who is in a group", **RO})
    @answers
    async def group_members(params: GroupMembersIn) -> str:
        """Who is in group G, since when, and who added them."""
        need_right(await flag(q, "dc_is_member_here()"), "Groups are a member's tool.")
        gid = await group_ref(q, params.group)
        w = Where().add("group_id = {}", gid)
        if params.role:
            w.add("role = {}", params.role)
        if not params.include_left:
            w.raw("left_at IS NULL")
        rows = await q(f"SELECT member_id, display_name, role, added_by, joined_at, left_at FROM mcp_group_members WHERE {w.sql()} ORDER BY role DESC, lower(display_name) LIMIT {lim(params.limit, 100)} OFFSET {off(params.offset)}", *w.args)
        return dumps(rows)

    @mcp.tool(name="my_groups", annotations={"title": "My groups", **RO})
    @answers
    async def my_groups(params: LimitIn | None = None) -> str:
        """The groups I am a member of, with their kind, how many people are in each and whether I own them."""
        need_right(await flag(q, "dc_is_member_here()"), "Groups are a member's tool.")
        rows = await q(f"SELECT group_id, key, name, description, kind, department_id, member_level, member_count, i_am_member, i_am_owner, archived_at FROM mcp_groups WHERE i_am_member AND archived_at IS NULL ORDER BY lower(name) LIMIT {lim(params.limit if params else 25)}")
        return dumps(rows)

    @mcp.tool(name="storage_by_library", annotations={"title": "What each library uses", **RO})
    @answers
    async def storage_by_library(params: ByLibraryIn | None = None) -> str:
        """What each library uses against its quota, and which are near. For a curator holding reports.storage: every library; for anyone else: the libraries they manage."""
        p = params or ByLibraryIn()
        rows = await q(f"SELECT library_id, kind, name, used_bytes, quota_bytes, pct, documents, versions FROM dc_storage_by_library() {'WHERE quota_bytes IS NOT NULL AND pct >= 80' if p.near_only else ''} ORDER BY used_bytes DESC LIMIT {lim(p.limit, 50)}")
        return dumps(rows)

    @mcp.tool(name="storage_by_owner", annotations={"title": "Who holds the most", **RO})
    @answers
    async def storage_by_owner(params: ByOwnerIn | None = None) -> str:
        """Who holds the most bytes (live documents). Needs reports.storage; the function answers nothing else."""
        need_right(await has_right(q, "reports.storage"), "Storage by owner is for those who hold reports.storage.")
        rows = await q("SELECT member_id, display_name, documents, live_bytes FROM dc_storage_by_owner($1)", lim(params.limit if params else 25, 25, 500))
        return dumps(rows)

    @mcp.tool(name="quota_state", annotations={"title": "A library's quota", **RO})
    @answers
    async def quota_state(params: LibraryIn) -> str:
        """A library's quota now: used, allowed, the percentage and whether it is near."""
        lid = await library_ref(q, params.library)
        return dumps((await q("SELECT used_bytes, quota_bytes, pct, near FROM dc_quota_state($1)", lid))[0])
