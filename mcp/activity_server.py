"""doccloud_activity_mcp — DocCloud's activity-memory server (activity_log through mcp_activity_log, read-only).

Tool surface: docs/doccloud-mcp-tool-surface.md, "Activity server". Every query runs as the asking member; the view decides which rows they see: their own, and the rows about a document or folder they may see — the admin sees every row.
The activity role reads mcp_activity_log plus mcp_members, mcp_departments, mcp_documents, mcp_folders, mcp_libraries and mcp_groups for names beside a row. A document's text, a passage, a query over 120 characters, a token, a link or a password
is never in a payload (the writers' rule), so nothing here can return one.
"""
from __future__ import annotations

from typing import Literal

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from dc_common import (RO, Ref, Refusal, Where, _Base, ago, answers, doc_ref, dumps, flag, folder_ref, has_right, is_int, library_ref, like, lim, member_ref, need_right, when)

mcp = FastMCP("doccloud_activity_mcp")

ENTITY_TYPES = {"key": "mcp_access_token", "proposal": "clerk_proposal", "dispatch": "agent_dispatch", "request": "link"}
COLS = "l.activity_id, l.occurred_at, l.actor_member_id, l.actor_name, l.actor_is_agent, l.source, l.action, l.entity_type, l.entity_id, l.library_id, l.document_id, l.folder_id, l.before, l.after, l.request_id, l.agent_run_id"


async def _pool():
    return await db.get_pool(db.ENV["MCP_ACTIVITY_DB_USER"], db.ENV["MCP_ACTIVITY_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


class HistoryIn(_Base):
    entity_type: Literal["document", "folder", "library", "group", "tag", "link", "request", "version", "access", "key", "proposal", "dispatch"] = Field(..., description="What kind of thing")
    entity_id: int = Field(..., ge=1, description="Its id")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class TimelineIn(_Base):
    member: Ref | None = Field(None, description="A person or an agent (id or name)")
    key: int | None = Field(None, description="An API key's id (find_keys)")
    link: int | None = Field(None, description="A link's id (find_links)")
    since: str | None = Field(None, description="A day or ISO time; the last 7 days by default")
    library: Ref | None = Field(None, description="Only this library (id or name)")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class RecentIn(_Base):
    since: str = Field(..., description="A day or ISO time")
    library: Ref | None = Field(None, description="A library (id or name)")
    folder: Ref | None = Field(None, description="A folder and its subfolders (id or path)")
    exclude_mine: bool = Field(True, description="Leave out what I did myself")
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class DownloadsIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")
    since: str | None = None
    limit: int = Field(100, ge=1, le=200, description="How many rows at most")


class ShareReadsIn(_Base):
    since: str | None = Field(None, description="A day or ISO time; the last 30 days by default")
    application: str | None = Field(None, description="Only this requesting application (its catalog key)")
    group: bool = Field(True, description="Group by application and tool (false: every row)")


class ActivitySearchIn(_Base):
    q: str = Field(..., min_length=2, max_length=200, description="Words to find in an action, a name, a label or a title")
    since: str | None = None
    library: Ref | None = None
    limit: int = Field(50, ge=1, le=200, description="How many rows at most")


class ActivityQueryIn(_Base):
    sql: str = Field(..., description="One SELECT over mcp_activity_log and the name views")


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


@mcp.tool(name="record_history", annotations={"title": "A record's history", **RO})
@answers
async def record_history(params: HistoryIn) -> str:
    """Who did what to one record, in order (oldest first): a document, a folder, a library, a group, a tag, a link, a file request, a version, an access change, a key, a proposal, a dispatch — each row worded with the actor (a person, an agent,
    a link holder, the worker) and the payload (ids, names and counts, never the text). You see the rows about what you may see, and your own."""
    t, i = params.entity_type, params.entity_id
    w = Where().raw("l.action <> 'screen.view'")
    if t == "document":
        w.add("(l.document_id = {} OR (l.entity_type = 'document' AND l.entity_id = {}))", i, i)
    elif t == "folder":
        w.add("(l.folder_id = {} OR (l.entity_type = 'folder' AND l.entity_id = {}))", i, i)
    elif t == "library":
        w.add("(l.library_id = {} OR (l.entity_type = 'library' AND l.entity_id = {}))", i, i)
    else:
        w.add("l.entity_type = {}", ENTITY_TYPES.get(t, t)).add("l.entity_id = {}", i)
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    rows.reverse()
    return dumps(rows)


@mcp.tool(name="actor_timeline", annotations={"title": "What a person, key, link or agent did", **RO})
@answers
async def actor_timeline(params: TimelineIn) -> str:
    """What a person, an API key, a link or an agent did here since a time (7 days by default), newest first. Anyone reads their own trail; another's is for reports.search or the admin — otherwise only the rows on things you manage."""
    given = [x for x in (params.member, params.key, params.link) if x not in (None, "")]
    if len(given) != 1:
        raise Refusal("invalid_argument", "Give exactly one of member, key or link.", fields=["member", "key", "link"])
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", when(params.since, "since") if params.since else ago(7))
    mine = int((await q("SELECT app_current_member_id() AS m"))[0]["m"])
    target = None
    if params.member not in (None, ""):
        target = await member_ref(q, params.member)
        w.add("l.actor_member_id = {}", target)
    elif params.key is not None:
        w.add("l.token_id = {}", params.key)
    else:
        w.add("(l.entity_type = 'link' AND l.entity_id = {})", params.link)
    if params.library not in (None, ""):
        w.add("l.library_id = {}", await library_ref(q, params.library))
    own = target == mine
    if not own and not (await flag(q, "dc_is_admin()") or await has_right(q, "reports.search")):
        w.raw("((l.document_id IS NOT NULL AND dc_can_manage_document(l.document_id)) OR (l.document_id IS NULL AND l.folder_id IS NOT NULL AND dc_can_manage_folder(l.folder_id)))")
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="recent_activity", annotations={"title": "What changed", **RO})
@answers
async def recent_activity(params: RecentIn) -> str:
    """What changed in a library or a folder (or everywhere I may see) since a time — newest first. Leaves out my own doing unless asked."""
    w = Where().raw("l.action <> 'screen.view'").add("l.occurred_at >= {}", when(params.since, "since"))
    if params.library not in (None, ""):
        w.add("l.library_id = {}", await library_ref(q, params.library))
    if params.folder not in (None, ""):
        w.add("l.folder_id IN (SELECT dc_folder_subtree_ids({}))", await folder_ref(q, params.folder))
    if params.exclude_mine:
        w.raw("l.actor_member_id IS DISTINCT FROM app_current_member_id()")
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 100, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="downloads", annotations={"title": "Who downloaded a document", **RO})
@answers
async def downloads(params: DownloadsIn) -> str:
    """Who downloaded or opened document X, and when — through the gate, a link or a share (a requesting application is named). For the people who manage the document."""
    did = await doc_ref(q, params.document)
    if not (await q("SELECT dc_can_manage_document($1) AS m", did))[0]["m"]:
        raise Refusal("managers_only", "Who downloaded a document is for the people who manage it.")
    w = Where().add("l.document_id = {}", did).raw("l.action IN ('document.download', 'document.open')")
    if params.since:
        w.add("l.occurred_at >= {}", when(params.since, "since"))
    rows = await q(f"""SELECT l.occurred_at, l.actor_member_id, l.actor_name, l.after->>'link_label' AS link_label, l.after->>'application' AS application, l.source, l.action, l.after->>'door' AS door, l.after->>'version_no' AS version_no
                         FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC LIMIT {lim(params.limit, 100, 200)}""", *w.args)
    for r in rows:
        r["what"] = "downloaded" if r["action"] == "document.download" else "opened"
    return dumps(rows)


@mcp.tool(name="share_reads", annotations={"title": "What siblings read of ours", **RO})
@answers
async def share_reads(params: ShareReadsIn | None = None) -> str:
    """Which sibling applications read DocCloud through the kernel (the four shares), how often, with what outcome — grouped by application and tool: calls, refusals, the last time. The admin's."""
    p = params or ShareReadsIn()
    need_right(await flag(q, "dc_is_admin()"), "What siblings read of ours is the admin's to see.")
    w = Where().raw("l.action IN ('share.read', 'share.refused')").add("l.occurred_at >= {}", when(p.since, "since") if p.since else ago(30))
    if p.application:
        w.add("l.after->>'consumer' = {}", p.application)
    if p.group:
        rows = await q(f"""SELECT l.after->>'consumer' AS application, l.after->>'tool' AS tool, count(*) FILTER (WHERE l.action = 'share.read') AS calls, count(*) FILTER (WHERE l.action = 'share.refused') AS refused, max(l.occurred_at) AS last_at
                             FROM mcp_activity_log l WHERE {w.sql()} GROUP BY 1, 2 ORDER BY last_at DESC LIMIT 200""", *w.args)
    else:
        rows = await q(f"SELECT {COLS}, l.after->>'consumer' AS application, l.after->>'tool' AS tool FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC LIMIT 200", *w.args)
    return dumps(rows)


@mcp.tool(name="activity_search", annotations={"title": "Search the trail", **RO})
@answers
async def activity_search(params: ActivitySearchIn) -> str:
    """The trail by a phrase: the words are looked for in the action (document.upload), the entity type, the names, labels and titles the payloads carry, and the actor's name. Newest first; only rows you may see."""
    w = Where().raw("l.action <> 'screen.view'")
    for word in [x for x in params.q.split() if x][:6]:
        n = w.p(like(word))
        w.raw(f"(l.action ILIKE {n} OR l.entity_type ILIKE {n} OR COALESCE(l.after->>'name', '') ILIKE {n} OR COALESCE(l.after->>'label', '') ILIKE {n} OR COALESCE(l.after->>'title', '') ILIKE {n} OR COALESCE(l.actor_name, '') ILIKE {n})")
    if params.since:
        w.add("l.occurred_at >= {}", when(params.since, "since"))
    if params.library not in (None, ""):
        w.add("l.library_id = {}", await library_ref(q, params.library))
    rows = await q(f"SELECT {COLS} FROM mcp_activity_log l WHERE {w.sql()} ORDER BY l.occurred_at DESC, l.activity_id DESC LIMIT {lim(params.limit, 50, 200)}", *w.args)
    return dumps(rows)


@mcp.tool(name="activity_query", annotations={"title": "Guarded read of the trail", **RO})
async def activity_query(params: ActivityQueryIn) -> str:
    """The long tail: one read-only SELECT over mcp_activity_log and the name views (mcp_members, mcp_departments, mcp_documents, mcp_folders, mcp_libraries, mcp_groups), run as the asking member — one statement, no ';', 5 seconds, at most 200 rows."""
    return await db.run_search(await _pool(), params.sql)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_ACTIVITY_DB_USER", "MCP_ACTIVITY_DB_PASSWORD", port=int(db.ENV["MCP_ACTIVITY_PORT"]), endpoint_name="Activity MCP")
