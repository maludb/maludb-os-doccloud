"""Access, shares and links (A1-A7): access_list, access_explain, my_level, shared_with_me, my_shares, stale_guest_shares, find_links, links_expiring, links_suspicious, find_requests, link_opens,
access_history, access_changes. Who can see a thing is a MANAGER's question (the access views answer nothing else); what a person may do on it is anyone's. Never a token, a hash or a link's URL."""
from __future__ import annotations

from typing import Literal

import asyncpg
from pydantic import Field

from dc_common import (qmatch, RO, Ref, Refusal, Where, _Base, answers, doc_ref, dumps, flag, folder_ref, has_right, is_int, like, lim, link_ref, member_ref, need_right, off, plain, q_alone, thing_ref, when)

RANK = {"none": 0, "view": 1, "upload": 2, "edit": 3, "manage": 4}


class ThingIn(_Base):
    document: Ref | None = Field(None, description="A document (id or name) — or a folder, not both")
    folder: Ref | None = Field(None, description="A folder (id or path) — or a document, not both")


class ExplainIn(ThingIn):
    member: Ref | None = Field(None, description="Whose access (id or name); default: mine. Asking about another person needs manage on the thing")


class SharedIn(_Base):
    kind: Literal["folder", "document"] | None = None
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class MySharesIn(_Base):
    guests_only: bool = False
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class StaleIn(_Base):
    days: int | None = Field(None, ge=1, le=3650, description="Older than this many days (default: the setting, 90)")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class FindLinksIn(_Base):
    folder: Ref | None = None
    document: Ref | None = None
    kind: Literal["view", "request"] | None = None
    q: str | None = Field(None, max_length=100, description="Part of the link's label (alone: resolves one link by label)")
    mine: bool = False
    live_only: bool = True
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ExpiringIn(_Base):
    days: int = Field(7, ge=1, le=365, description="How many rows at most")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class SuspiciousIn(_Base):
    addresses: int = Field(10, ge=1, le=1000, description="More than this many different addresses")
    days: int = Field(7, ge=1, le=90, description="How many rows at most")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class FindRequestsIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the request's label (alone: resolves one request)")
    folder: Ref | None = None
    mine: bool = False
    live_only: bool = True
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class OpensIn(_Base):
    link: Ref = Field(..., description="The link (id or label)")
    since: str | None = None
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class HistoryIn(ThingIn):
    at: str = Field(..., description="A day or an ISO time: who could see it then")


class ChangesIn(ThingIn):
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


def _can(level: str) -> dict:
    r = RANK.get(level, 0)
    return {"view": r >= 1, "add": r >= 2, "edit": r >= 3, "manage": r >= 4}


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    async def manage_gate(kind: str, tid: int) -> None:
        """A manager's question: not found when the caller cannot even see it, managers_only when they can."""
        if (await q(f"SELECT dc_can_manage_{kind}($1) AS m", tid))[0]["m"]:
            return
        raise Refusal("managers_only", "Who can see this, and the links on it, is for the people who manage it.")

    @mcp.tool(name="access_list", annotations={"title": "Who can see this", **RO})
    @answers
    async def access_list(params: ThingIn) -> str:
        """Who can see folder or document X, at what level: the owner, the library's base level, the explicit rows at its access root (greyed `inherited` when they come from a folder above), who gave each. For the people who manage it."""
        kind, tid = await thing_ref(q, params.document, params.folder)
        await manage_gate(kind, tid)
        if kind == "folder":
            t = (await q("SELECT folder_id AS id, name, library_id, owner_member_id, access_root_id FROM mcp_folders WHERE folder_id = $1", tid))[0]
            root = t["access_root_id"] or tid
            rows = await q("SELECT folder_access_id AS access_id, folder_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at FROM mcp_folder_access WHERE folder_id = $1 ORDER BY created_at", root)
        else:
            t = (await q("SELECT document_id AS id, name, library_id, owner_member_id, folder_id, own_access FROM mcp_documents WHERE document_id = $1", tid))[0]
            if t["own_access"]:
                root = None
                rows = await q("SELECT document_access_id AS access_id, document_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at FROM mcp_document_access WHERE document_id = $1 ORDER BY created_at", tid)
            else:
                root = (await q("SELECT access_root_id FROM mcp_folders WHERE folder_id = $1", t["folder_id"]))[0]["access_root_id"] or t["folder_id"]
                rows = await q("SELECT folder_access_id AS access_id, folder_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at FROM mcp_folder_access WHERE folder_id = $1 ORDER BY created_at", root)
        for r in rows:
            r["is_guest"] = r["principal_kind"] == "guest" or (r["principal_kind"] == "member" and bool((await q("SELECT is_guest FROM mcp_members WHERE member_id = $1", r["principal_id"]) or [{"is_guest": False}])[0]["is_guest"]))
            r["inherited"] = kind == "folder" and root is not None and root != tid or (kind == "document" and root is not None)
            r["granted_by_name"] = ((await q("SELECT display_name FROM mcp_members WHERE member_id = $1", r["granted_by"])) or [{"display_name": None}])[0]["display_name"] if r["granted_by"] else None
        lib = (await q("SELECT library_id, kind, name, everyone_level, group_id, owner_member_id FROM mcp_libraries WHERE library_id = $1", t["library_id"]))[0]
        if lib["kind"] == "shared":
            base = {"principal": "everyone", "level": lib["everyone_level"]}
        elif lib["kind"] == "group":
            g = await q("SELECT name, member_level FROM mcp_groups WHERE group_id = $1", lib["group_id"])
            base = {"principal": "group", "principal_name": g[0]["name"] if g else None, "level": g[0]["member_level"] if g else "none"}
        else:
            base = {"principal": "owner", "level": "manage"}
        owner = ((await q("SELECT display_name FROM mcp_members WHERE member_id = $1", t["owner_member_id"])) or [{"display_name": None}])[0]["display_name"]
        inherited_from = None
        if root is not None and root != tid and not (kind == "document" and False):
            r = await q("SELECT folder_id, name FROM mcp_folders WHERE folder_id = $1", root)
            inherited_from = r[0] if r else None
        return dumps({"thing": {"kind": kind, "id": tid, "name": t["name"]}, "owner": {"member_id": t["owner_member_id"], "display_name": owner},
                      "library": {"library_id": lib["library_id"], "kind": lib["kind"], "name": lib["name"], "base": base}, "inherited_from": inherited_from, "rows": rows})

    @mcp.tool(name="access_explain", annotations={"title": "Why a person sees this", **RO})
    @answers
    async def access_explain(params: ExplainIn) -> str:
        """Why does member M see this (or not): owner, admin, the library, a row, inherited from where. Asked about oneself it needs only that I can see the thing; about another person, that I manage it. `level: none` with no reasons when they see nothing."""
        kind, tid = await thing_ref(q, params.document, params.folder)
        mine = await q("SELECT app_current_member_id() AS m")
        who = int(mine[0]["m"])
        if params.member not in (None, ""):
            who = await member_ref(q, params.member)
            if who != int(mine[0]["m"]):
                await manage_gate(kind, tid)
        rows = await q("SELECT reason, via_kind, via_id, via_name, level FROM dc_access_explain($1, $2, $3)", tid if kind == "document" else None, tid if kind == "folder" else None, who)
        level = max((r["level"] for r in rows), key=lambda x: RANK.get(x, 0), default="none")
        m = await q("SELECT member_id, display_name FROM mcp_members WHERE member_id = $1", who)
        return dumps({"member": m[0] if m else {"member_id": who, "display_name": None}, "level": level, "reasons": rows})

    @mcp.tool(name="my_level", annotations={"title": "What I may do here", **RO})
    @answers
    async def my_level(params: ThingIn) -> str:
        """What I may do on a document or folder: my level (none, view, upload, edit, manage) and what it allows. `none` is an answer, not an error."""
        if (params.document in (None, "")) == (params.folder in (None, "")):
            raise Refusal("invalid_argument", "Give a document or a folder, not both and not neither.", fields=["document", "folder"])
        if params.document not in (None, ""):
            tid = int(params.document) if is_int(params.document) else await doc_ref(q, params.document)
            level = (await q("SELECT dc_document_level($1) AS l", tid))[0]["l"] or "none"
        else:
            tid = int(params.folder) if is_int(params.folder) else await folder_ref(q, params.folder)
            level = (await q("SELECT dc_folder_level($1) AS l", tid))[0]["l"] or "none"
        return dumps({"level": level, "can": _can(level)})

    @mcp.tool(name="shared_with_me", annotations={"title": "Shared with me", **RO})
    @answers
    async def shared_with_me(params: SharedIn | None = None) -> str:
        """What was shared with me, by whom and when — explicit rows naming me, my groups or my departments."""
        p = params or SharedIn()
        rows = await q(f"""SELECT s.kind, s.id, s.name, s.level, s.shared_by, m.display_name AS shared_by_name, s.shared_at, s.via FROM dc_shared_with_me($1, $2) s LEFT JOIN mcp_members m ON m.member_id = s.shared_by
                            {'WHERE s.kind = $3' if p.kind else ''} ORDER BY s.shared_at DESC""", lim(p.limit, 50), off(p.offset), *([p.kind] if p.kind else []))
        return dumps(rows)

    @mcp.tool(name="my_shares", annotations={"title": "What I shared out", **RO})
    @answers
    async def my_shares(params: MySharesIn | None = None) -> str:
        """What I shared out, to whom, at what level; which are to guests."""
        p = params or MySharesIn()
        rows = await q(f"SELECT kind, id, name, principal_kind, principal_id, principal_ref, principal_name, level, created_at, is_guest FROM dc_my_shares() {'WHERE is_guest' if p.guests_only else ''} ORDER BY created_at DESC LIMIT {lim(p.limit, 50)}")
        return dumps(rows)

    @mcp.tool(name="stale_guest_shares", annotations={"title": "Old guest shares", **RO})
    @answers
    async def stale_guest_shares(params: StaleIn | None = None) -> str:
        """Shares to guests older than N days (the setting's 90 by default) on things I manage — what the Clerk calls stale."""
        p = params or StaleIn()
        rows = await q(f"SELECT kind, id, name, guest_member_id, guest_name, level, created_at, granted_by FROM dc_stale_guest_shares($1) LIMIT {lim(p.limit, 50)}", p.days)
        return dumps(rows)

    def _link_cols() -> str:
        return "l.link_id, l.label, l.kind, l.folder_id, l.document_id, l.allow_download, l.has_password, l.expires_at, l.view_count, l.upload_count, l.last_used_at, l.made_by, m.display_name AS made_by_name, l.created_at, l.revoked_at, l.rotated_at"

    @mcp.tool(name="find_links", annotations={"title": "Links on a folder or document", **RO})
    @answers
    async def find_links(params: FindLinksIn) -> str:
        """The links on folder or document X, or mine: expiry, opens, uploads. Never a token or a hash. Alone, `q` resolves a link by label (a plain list of {link_id, label})."""
        w = Where()
        if params.folder not in (None, ""):
            w.add("l.folder_id = {}", await folder_ref(q, params.folder))
        if params.document not in (None, ""):
            w.add("l.document_id = {}", await doc_ref(q, params.document))
        if params.kind:
            w.add("l.kind = {}", params.kind)
        qmatch(w, params.q, "l.link_id", "l.label")
        if params.mine:
            w.raw("l.made_by = app_current_member_id()")
        if params.live_only:
            w.raw("(l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now()))")
        rows = await q(f"SELECT {_link_cols()} FROM mcp_share_links l LEFT JOIN mcp_members m ON m.member_id = l.made_by WHERE {w.sql()} ORDER BY l.created_at DESC LIMIT {lim(params.limit)}", *w.args)
        return plain(rows, "link_id", "label", "kind") if q_alone(params) else dumps(rows)

    @mcp.tool(name="links_expiring", annotations={"title": "Links about to expire", **RO})
    @answers
    async def links_expiring(params: ExpiringIn | None = None) -> str:
        """Links expiring within N days (a week by default) — mine, those on things I manage, or all for links.all."""
        p = params or ExpiringIn()
        rows = await q(f"SELECT e.link_id, e.label, e.kind, e.folder_id, e.document_id, e.expires_at, e.made_by, m.display_name AS made_by_name, e.view_count FROM dc_links_expiring($1) e LEFT JOIN mcp_members m ON m.member_id = e.made_by LIMIT {lim(p.limit, 50)}", p.days)
        return dumps(rows)

    @mcp.tool(name="links_suspicious", annotations={"title": "Links opened from many addresses", **RO})
    @answers
    async def links_suspicious(params: SuspiciousIn | None = None) -> str:
        """Links opened from more than N different addresses this week — for those holding links.all."""
        p = params or SuspiciousIn()
        need_right(await has_right(q, "links.all"), "Links opened from many addresses is for those who hold links.all.")
        rows = await q(f"SELECT link_id, label, addresses, opens FROM dc_links_suspicious($1, $2) LIMIT {lim(p.limit, 50)}", p.addresses, p.days)
        return dumps(rows)

    @mcp.tool(name="find_requests", annotations={"title": "Open file requests", **RO})
    @answers
    async def find_requests(params: FindRequestsIn) -> str:
        """Open file requests — links that let someone upload into a folder — into which folder, how many uploads. Alone, `q` resolves one by label (a plain list of {link_id, label})."""
        w = Where().raw("l.kind = 'request'")
        qmatch(w, params.q, "l.link_id", "l.label")
        if params.folder not in (None, ""):
            w.add("l.folder_id = {}", await folder_ref(q, params.folder))
        if params.mine:
            w.raw("l.made_by = app_current_member_id()")
        if params.live_only:
            w.raw("(l.rotated_at IS NULL AND l.revoked_at IS NULL AND (l.expires_at IS NULL OR l.expires_at > now()))")
        rows = await q(f"SELECT {_link_cols()}, l.note FROM mcp_share_links l LEFT JOIN mcp_members m ON m.member_id = l.made_by WHERE {w.sql()} ORDER BY l.created_at DESC LIMIT {lim(params.limit)}", *w.args)
        return plain(rows, "link_id", "label") if q_alone(params) else dumps(rows)

    @mcp.tool(name="link_opens", annotations={"title": "When a link was opened", **RO})
    @answers
    async def link_opens(params: OpensIn) -> str:
        """When a link was opened, for what, and from how many addresses (an address is shown as 8 characters of its hash). For the link's managers."""
        lid = await link_ref(q, params.link)
        w = Where().add("link_id = {}", lid)
        if params.since:
            w.add("opened_at >= {}", when(params.since, "since"))
        rows = await q(f"SELECT link_open_id, opened_at, what, document_id, byte_size, address_key FROM mcp_link_opens WHERE {w.sql()} ORDER BY opened_at DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
        return dumps(rows)

    @mcp.tool(name="access_history", annotations={"title": "Who could see it on a day", **RO})
    @answers
    async def access_history(params: HistoryIn) -> str:
        """Who could see X on a given day, from the trail of access changes — explicit rows only (the library's membership on that day is the directory's). For the people who manage it, or reports.search."""
        kind, tid = await thing_ref(q, params.document, params.folder)
        if not await has_right(q, "reports.search"):
            await manage_gate(kind, tid)
        rows = await q("SELECT principal_kind, principal_id, principal_ref, principal_name, level, since FROM dc_access_history($1, $2, $3)", tid if kind == "document" else None, tid if kind == "folder" else None, when(params.at, "at"))
        return dumps({"rows": rows, "note": "explicit rows only; the library's membership on that day is the directory's"})

    @mcp.tool(name="access_changes", annotations={"title": "The trail of access changes", **RO})
    @answers
    async def access_changes(params: ChangesIn) -> str:
        """The trail of access changes on X, newest first: who was given or lost what level, by whom. For the people who manage it, or reports.search."""
        kind, tid = await thing_ref(q, params.document, params.folder)
        if not await has_right(q, "reports.search"):
            await manage_gate(kind, tid)
        rows = await q(f"""SELECT c.access_change_id, c.principal_kind, c.principal_id, c.principal_ref, dc_principal_name(c.principal_kind, c.principal_id, c.principal_ref) AS principal_name, c.old_level, c.new_level,
                                  c.changed_by, m.display_name AS changed_by_name, c.changed_at
                             FROM mcp_access_changes c LEFT JOIN mcp_members m ON m.member_id = c.changed_by WHERE c.{kind}_id = $1 ORDER BY c.changed_at DESC LIMIT {lim(params.limit, 50, 200)}""", tid)
        return dumps(rows)
