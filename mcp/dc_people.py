"""Agents, proposals, keys, people, notes and what is mine (P1-P3 and the people/mine group): clerk_proposals, agent_dispatches, find_keys, clerk_report, find_members, find_departments, find_notes, my_favourites,
recent_documents, my_notifications, my_tokens, my_uploads, get_settings. Never a key's value or hash, never a token."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from dc_common import (qmatch, RO, Ref, Refusal, Where, _Base, answers, doc_ref, dumps, flag, folder_ref, has_right, is_int, like, lim, member_ref, need_right, off, plain, q_alone, thing_ref)

PROPOSAL_KINDS = ("untagged", "duplicate", "stale_share", "link_expiring", "link_suspicious", "request_open", "trash_due", "locked", "no_text", "quota", "budget", "orphaned", "tags")
REPORT_KEYS = {"untagged": "untagged", "duplicate": "duplicates", "stale_share": "stale_guest_shares", "link_expiring": "links_expiring", "link_suspicious": "links_suspicious", "request_open": "requests_open",
               "trash_due": "trash_due", "locked": "locked_long", "no_text": "extraction_failures", "quota": "quota_near", "budget": "budget", "orphaned": "orphaned"}


class ProposalsIn(_Base):
    kind: str | None = Field(None, description="untagged, duplicate, stale_share, link_expiring, link_suspicious, request_open, trash_due, locked, no_text, quota, budget, orphaned, tags")
    status: Literal["proposed", "accepted", "dismissed", "expired"] = "proposed"
    for_: Ref | None = Field(None, alias="for", description="Proposals for this member (id or name)")
    q: str | None = Field(None, max_length=100, description="Part of the title (alone: resolves one proposal)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class DispatchesIn(_Base):
    status: str | None = Field(None, description="pending, sent, answered, refused, failed, awaiting_approval …")
    agent: Ref | None = Field(None, description="The agent (id or name)")
    kind: Literal["untagged_batch", "request_received", "duplicates_found", "ask", "duty_proposal"] | None = None
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class KeysIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the key's label (alone: resolves one key)")
    member: Ref | None = Field(None, description="Whose keys (admin only; default mine)")
    live: bool = Field(True, description="Only keys not revoked or expired")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class ReportIn(_Base):
    week: int = Field(7, ge=1, le=60, description="The look-ahead in days for links expiring and the trash (default a week)")


class MembersIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the name (alone: resolves one person or agent)")
    kind: Literal["human", "agent"] | None = None
    department: Ref | None = Field(None, description="In this department (id or name)")
    group: Ref | None = Field(None, description="In this group (id, key or name)")
    guests: bool = Field(False, description="Only guests")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class DepartmentsIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the name (alone: resolves one department)")
    include_archived: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class NotesIn(_Base):
    document: Ref | None = None
    folder: Ref | None = None
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class LimitIn(_Base):
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class NotificationsIn(_Base):
    unread_only: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class TokensIn(_Base):
    scope: Literal["mcp", "api"] | None = None


class UploadsIn(_Base):
    status: str | None = None
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="clerk_proposals", annotations={"title": "What the Clerk proposed", **RO})
    @answers
    async def clerk_proposals(params: ProposalsIn) -> str:
        """What the Filing Clerk proposed, and what was accepted: for me, or by me, or — for groups.manage — all. Alone, `q` resolves one proposal by title (a plain list of {proposal_id, title})."""
        w = Where().add("p.status = {}", params.status)
        if params.kind:
            w.add("p.kind = {}", params.kind)
        if params.for_ not in (None, ""):
            w.add("p.for_member_id = {}", await member_ref(q, params.for_))
        qmatch(w, params.q, "p.clerk_proposal_id", "p.title")
        rows = await q(f"""SELECT p.clerk_proposal_id AS proposal_id, p.kind, p.document_id, p.folder_id, p.library_id, p.link_id, p.for_member_id, p.title, p.payload, p.proposed_by, p.status, p.decided_by, p.decided_at, p.created_at
                             FROM mcp_clerk_proposals p WHERE {w.sql()} ORDER BY p.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        return plain(rows, "proposal_id", "title", "kind") if q_alone(params) else dumps(rows)

    @mcp.tool(name="agent_dispatches", annotations={"title": "Dispatches to agents", **RO})
    @answers
    async def agent_dispatches(params: DispatchesIn | None = None) -> str:
        """Which dispatches to an agent are pending or failed — what was sent to the expert or the Clerk, how, and what came back (the reply cut at 300 characters). For agents.settings; otherwise the ones I caused or received."""
        p = params or DispatchesIn()
        w = Where()
        if p.status:
            w.add("a.status = {}", p.status)
        if p.kind:
            w.add("a.kind = {}", p.kind)
        if p.agent not in (None, ""):
            w.add("a.agent_member_id = {}", await member_ref(q, p.agent))
        rows = await q(f"""SELECT a.agent_dispatch_id AS dispatch_id, a.record_type, a.record_id, m.display_name AS agent_name, a.kind, a.via, a.status, left(a.reply_excerpt, 300) AS reply_excerpt, a.detail, a.attempts, a.run_id, a.request_id,
                                  a.document_id, a.folder_id, a.created_at, a.answered_at
                             FROM mcp_agent_dispatches a LEFT JOIN mcp_members m ON m.member_id = a.agent_member_id WHERE {w.sql()} ORDER BY a.created_at DESC LIMIT {lim(p.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="find_keys", annotations={"title": "API keys", **RO})
    @answers
    async def find_keys(params: KeysIn) -> str:
        """My API keys — label, limits, last use, today's count — or, for the admin, anyone's. NEVER a key's value or hash. Alone, `q` resolves a key by label (a plain list of {key_id, label})."""
        w = Where().raw("t.scope = 'api'")
        if params.member not in (None, ""):
            who = await member_ref(q, params.member)
            if who != int((await q("SELECT app_current_member_id() AS m"))[0]["m"]):
                need_right(await flag(q, "dc_is_admin()"), "Another person's keys are for the admin.")
            w.add("t.member_id = {}", who)
        elif not await flag(q, "dc_is_admin()"):
            w.raw("t.member_id = app_current_member_id()")
        qmatch(w, params.q, "t.token_id", "t.label")
        if params.live:
            w.raw("t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())")
        rows = await q(f"""SELECT t.token_id AS key_id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day, t.calls_today, t.last_used_at, t.expires_at, t.revoked_at, t.rotated_from,
                                  (t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())) AS is_live, t.created_at
                             FROM mcp_tokens t WHERE {w.sql()} ORDER BY t.created_at DESC LIMIT {lim(params.limit)}""", *w.args)
        return plain(rows, "key_id", "label") if q_alone(params) else dumps(rows)

    @mcp.tool(name="clerk_report", annotations={"title": "The Monday store report", **RO})
    @answers
    async def clerk_report(params: ReportIn | None = None) -> str:
        """The Monday store report in one read, for the Filing Clerk alone: untagged documents, duplicates, old guest shares, links expiring or opened from many addresses, open file requests, the trash due, long locks, documents with no text, libraries near their
        quota, the month's graph budget, documents of people who have left — counts, ids and names where the Clerk may see them, never a document's text. Anyone but the Clerk (and the admin) gets managers_only."""
        p = params or ReportIn()
        if not await flag(q, "dc_is_clerk_or_admin()"):
            raise Refusal("managers_only", "The Monday store report is the Filing Clerk's alone.")
        rows = await q("SELECT kind, count, ids, detail FROM dc_clerk_report($1)", p.week)
        out: dict = {"week_days": p.week}
        for r in rows:
            out[REPORT_KEYS.get(r["kind"], r["kind"])] = {"count": int(r["count"]), "ids": [i for i in (r["ids"] or []) if i], "detail": r["detail"]}
        un = out.get("untagged")
        if un and un["ids"]:
            named = await q("SELECT d.document_id, d.name, d.folder_id, dc_folder_path_text(d.folder_id) AS folder_path FROM mcp_documents d WHERE d.document_id = ANY($1::bigint[]) ORDER BY d.folder_id, lower(d.name)", un["ids"])
            by: dict = {}
            for d in named:
                by.setdefault(d["folder_id"], {"folder_id": d["folder_id"], "folder_path": d["folder_path"], "documents": []})["documents"].append({"document_id": d["document_id"], "name": d["name"]})
            un["folders"] = list(by.values())
        if not rows:
            out["note"] = "The report is switched off, or the Clerk is not named in the settings."
        return dumps(out)

    @mcp.tool(name="find_members", annotations={"title": "Find a person or an agent", **RO})
    @answers
    async def find_members(params: MembersIn) -> str:
        """A person or an agent by name — to share with, to ask about. Insiders see everyone admitted; a guest sees the people who shared with them. `email` only for oneself. Alone, `q` resolves one (a plain list of {member_id, display_name})."""
        w = Where()
        qmatch(w, params.q, "m.member_id", "m.display_name")
        if params.kind:
            w.add("m.member_kind = {}", params.kind)
        if params.guests:
            w.raw("m.is_guest")
        if params.department not in (None, ""):
            if is_int(params.department):
                w.add("{} = ANY (COALESCE(m.department_ids, '{{}}'))", int(params.department))
            else:
                r = await q("SELECT department_id FROM mcp_departments WHERE lower(name) = lower($1)", str(params.department).strip())
                if not r:
                    raise Refusal("not_found", "No department you can see matches that.")
                w.add("{} = ANY (COALESCE(m.department_ids, '{{}}'))", int(r[0]["department_id"]))
        if params.group not in (None, ""):
            from dc_common import group_ref
            w.add("{} = ANY (COALESCE(m.group_ids, '{{}}'))", await group_ref(q, params.group))
        rows = await q(f"""SELECT m.member_id, m.display_name, m.member_kind, m.is_agent, m.is_guest, m.job_title, m.roles, m.department_ids, m.group_ids, m.email FROM mcp_members m WHERE {w.sql()} ORDER BY lower(m.display_name) LIMIT {lim(params.limit)}""", *w.args)
        return plain(rows, "member_id", "display_name", "member_kind") if q_alone(params) else dumps(rows)

    @mcp.tool(name="find_departments", annotations={"title": "Find a department", **RO})
    @answers
    async def find_departments(params: DepartmentsIn) -> str:
        """A department by name, as someone to share with. Alone, `q` resolves one (a plain list of {department_id, name})."""
        w = Where()
        qmatch(w, params.q, "department_id", "name")
        if not params.include_archived:
            w.raw("archived_at IS NULL")
        rows = await q(f"SELECT department_id, name, description, parent_id, archived_at FROM mcp_departments WHERE {w.sql()} ORDER BY lower(name) LIMIT {lim(params.limit)}", *w.args)
        return plain(rows, "department_id", "name") if q_alone(params) else dumps(rows)

    @mcp.tool(name="find_notes", annotations={"title": "Comments on a document or folder", **RO})
    @answers
    async def find_notes(params: NotesIn) -> str:
        """The comments on a document or a folder, newest first."""
        kind, tid = await thing_ref(q, params.document, params.folder)
        rows = await q(f"""SELECT n.note_id, n.member_id, m.display_name AS member_name, n.body, n.created_at FROM mcp_notes n LEFT JOIN mcp_members m ON m.member_id = n.member_id
                            WHERE n.record_type = $1 AND n.record_id = $2 ORDER BY n.created_at DESC LIMIT {lim(params.limit, 50)}""", kind, tid)
        return dumps(rows)

    @mcp.tool(name="my_favourites", annotations={"title": "My favourites", **RO})
    @answers
    async def my_favourites() -> str:
        """My favourites — documents and folders I starred (only those I can still see)."""
        rows = await q("""SELECT 'document' AS kind, d.document_id AS id, d.name, f.created_at FROM mcp_favourites f JOIN mcp_documents d ON d.document_id = f.document_id WHERE f.document_id IS NOT NULL
                          UNION ALL SELECT 'folder', g.folder_id, g.name, f.created_at FROM mcp_favourites f JOIN mcp_folders g ON g.folder_id = f.folder_id WHERE f.folder_id IS NOT NULL ORDER BY 4 DESC LIMIT 100""")
        return dumps(rows)

    @mcp.tool(name="recent_documents", annotations={"title": "What I opened or changed lately", **RO})
    @answers
    async def recent_documents(params: LimitIn | None = None) -> str:
        """What I opened or changed lately — documents, newest first, with when I last opened each."""
        rows = await q("SELECT document_id, name, folder_id, mime_kind, updated_at, last_opened_at FROM dc_recent_documents($1)", lim(params.limit if params else 25))
        return dumps(rows)

    @mcp.tool(name="my_notifications", annotations={"title": "My notices", **RO})
    @answers
    async def my_notifications(params: NotificationsIn | None = None) -> str:
        """My notices, unread first, and my notification preferences."""
        p = params or NotificationsIn()
        w = Where()
        if p.unread_only:
            w.raw("read_at IS NULL")
        rows = await q(f"""SELECT notification_id, kind, title, body, record_type, record_id, document_id, folder_id, read_at, created_at FROM mcp_notifications WHERE {w.sql()}
                             ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT {lim(p.limit)}""", *w.args)
        prefs = await q("SELECT email_enabled, text_enabled, kinds, text_kinds, updated_at FROM mcp_notification_prefs")
        return dumps({"prefs": prefs[0] if prefs else None, "notifications": rows})

    @mcp.tool(name="my_tokens", annotations={"title": "My MCP tokens and keys", **RO})
    @answers
    async def my_tokens(params: TokensIn | None = None) -> str:
        """My MCP tokens and API keys: label, scope, last use, expiry, whether revoked. NEVER a value or a hash."""
        p = params or TokensIn()
        w = Where().raw("t.member_id = app_current_member_id()")
        if p.scope:
            w.add("t.scope = {}", p.scope)
        rows = await q(f"SELECT t.token_id, t.label, t.scope, t.last_used_at, t.expires_at, t.revoked_at, t.created_at, t.rate_per_minute, t.rate_per_day, t.rotated_from, t.calls_today FROM mcp_tokens t WHERE {w.sql()} ORDER BY t.created_at DESC LIMIT 100", *w.args)
        return dumps(rows)

    @mcp.tool(name="my_uploads", annotations={"title": "My uploads in flight", **RO})
    @answers
    async def my_uploads(params: UploadsIn | None = None) -> str:
        """My upload sessions: what is being sent, how many parts are done, whether it finished or failed."""
        p = params or UploadsIn()
        w = Where().raw("u.member_id = app_current_member_id()")
        if p.status:
            w.add("u.status = {}", p.status)
        rows = await q(f"""SELECT u.upload_session_id, u.folder_id, u.document_id, u.filename, u.byte_size, u.parts_total, u.parts_done, u.status, u.version_id, left(u.error, 300) AS error, u.created_at, u.updated_at, u.expires_at, u.document_name, u.mode, u.source
                             FROM mcp_upload_sessions u WHERE {w.sql()} ORDER BY u.created_at DESC LIMIT {lim(p.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="get_settings", annotations={"title": "The application's settings", **RO})
    @answers
    async def get_settings() -> str:
        """DocCloud's settings: ceilings, retention windows, link defaults, key rates, the graph's budget and default policy. The model fields are the admin's alone."""
        rows = await q("SELECT * FROM mcp_settings")
        return dumps(rows[0] if rows else {})
