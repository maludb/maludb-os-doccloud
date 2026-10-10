"""Tags and search (T1-T3, S1-S3): find_tags, tag_proposals, documents_by_tag, untagged, search, saved_searches, run_saved_search, search_gaps. `search` is KEYWORD search over the index — names, tags, descriptions and the words
of every document the asker may see (dc_search judges each row); it is not the graph (graph_find) and it needs no engine. A search leaves a search_log row through the bridge (source mcp) unless the run is an evaluation."""
from __future__ import annotations

import time
from typing import Literal

from pydantic import Field

import server_common
from dc_common import (qmatch, RO, Ref, Refusal, Where, _Base, answers, dumps, flag, folder_ref, has_right, is_int, library_ref, like, lim, member_ref, need_right, off, plain, q_alone, tag_ref, when)


class FindTagsIn(_Base):
    q: str | None = Field(None, max_length=100, description="A slug, label or alias (alone: resolves one tag)")
    kind: Literal["managed", "free"] | None = None
    include_archived: bool = False
    sort: Literal["usage", "label"] = "usage"
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class ProposalsIn(_Base):
    kind: Literal["promote", "apply", "merge"] | None = None
    status: Literal["proposed", "accepted", "dismissed"] = "proposed"
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class ByTagIn(_Base):
    tag: Ref = Field(..., description="The tag (slug, label or id)")
    library: Ref | None = None
    folder: Ref | None = None
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class UntaggedIn(_Base):
    folder: Ref | None = Field(None, description="Under this folder (id or path)")
    library: Ref | None = None
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class SearchIn(_Base):
    q: str = Field("", max_length=200, description="Words to find (quotes, - and OR work as in a web search). May be empty when a filter narrows it")
    library: Ref | None = None
    folder: Ref | None = Field(None, description="Only this folder and its subfolders")
    owner: Ref | None = Field(None, description="Only this owner (member id or name)")
    tag: str | None = Field(None, description="Only this tag (slug, label or alias)")
    kind: str | None = Field(None, description="image, pdf, text, spreadsheet …")
    min_bytes: int | None = Field(None, ge=0)
    max_bytes: int | None = Field(None, ge=0)
    before: str | None = Field(None, description="Updated before this day")
    after: str | None = Field(None, description="Updated on or after this day")
    mine: bool = False
    shared_with_me: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class SavedIn(_Base):
    q: str | None = Field(None, max_length=100, description="Part of the name (alone: resolves one saved search)")
    mine: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class RunSavedIn(_Base):
    saved_search: Ref = Field(..., description="The saved search (id or name)")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class GapsIn(_Base):
    since: str | None = Field(None, description="A day or time; default the last 30 days")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


async def keyword_search(q, bridge_, query: str, filters: dict, limit: int, offset: int) -> dict:
    """dc_search + the nearest names + the search_log row (not under an evaluation run). The shape of S1."""
    t0 = time.monotonic()
    rows = await q("SELECT document_id, name, folder_id, library_id, owner_member_id, mime_kind, byte_size, tags, excerpt, occurred_at, rank, total FROM dc_search($1, $2::jsonb, $3, $4)", query, filters, limit, offset)
    total = int(rows[0]["total"]) if rows else 0
    for r in rows:
        r.pop("total", None)
    names: list[dict] = []
    if query.strip() and total < 5 and not offset:
        seen = {r["document_id"] for r in rows}
        names = [{"document_id": n["document_id"], "name": n["name"], "similarity": round(float(n["similarity"]), 3)} for n in await q("SELECT document_id, name, similarity FROM dc_search_names($1, 5)", query) if n["document_id"] not in seen]
    ms = int((time.monotonic() - t0) * 1000)
    if bridge_ is not None:                                    # the door writes the search_log row — and none under an evaluation run (it is told: is_eval)
        try:
            await bridge_("log_search", {"kind": "keyword", "query": query[:120], "filters": filters, "results": total, "took_ms": ms})
        except Refusal:
            pass                                               # the log is the door's; a search is still answered when it is down
    return {"rows": rows, "total": total, "name_matches": names}


async def filters_of(q, p) -> dict:
    f: dict = {}
    if p.library not in (None, ""):
        f["library"] = await library_ref(q, p.library)
    if p.folder not in (None, ""):
        f["folder"] = await folder_ref(q, p.folder)
    if p.owner not in (None, ""):
        f["owner"] = await member_ref(q, p.owner)
    if p.tag:
        r = await q("SELECT slug FROM mcp_tags WHERE tag_id = dc_tag_resolve($1)", p.tag)
        f["tag"] = r[0]["slug"] if r else "-"
    if p.kind:
        f["kind"] = p.kind
    if p.min_bytes is not None:
        f["min_bytes"] = p.min_bytes
    if p.max_bytes is not None:
        f["max_bytes"] = p.max_bytes
    if p.after:
        f["after"] = str(when(p.after, "after").date())
    if p.before:
        f["before"] = str(when(p.before, "before").date())
    if p.mine:
        f["mine"] = True
    if p.shared_with_me:
        f["shared_with_me"] = True
    return f


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="find_tags", annotations={"title": "Tags", **RO})
    @answers
    async def find_tags(params: FindTagsIn) -> str:
        """The tags — managed and free — with counts, colours and aliases. Alone, `q` resolves a tag by slug, label or alias (a plain list of {tag_id, label, slug})."""
        w = Where()
        if params.q:
            n = w.p(like(params.q))
            ids = f"t.tag_id = dc_tag_resolve({w.p(params.q)})" + (f" OR t.tag_id = {w.p(int(params.q))}" if params.q.strip().isdigit() else "")
            w.raw(f"({ids} OR t.slug ILIKE {n} OR t.label ILIKE {n} OR EXISTS (SELECT 1 FROM unnest(COALESCE(t.aliases, '{{}}')) a WHERE a ILIKE {n}))")
        if params.kind:
            w.add("t.kind = {}", params.kind)
        if not params.include_archived:
            w.raw("t.archived_at IS NULL")
        order = "t.usage_count DESC, t.slug" if params.sort == "usage" else "lower(t.label)"
        rows = await q(f"SELECT t.tag_id, t.slug, t.label, t.kind, t.colour, t.usage_count, t.aliases, t.created_by, t.archived_at FROM mcp_tags t WHERE {w.sql()} ORDER BY {order} LIMIT {lim(params.limit, 50)} OFFSET {off(params.offset)}", *w.args)
        return plain(rows, "tag_id", "label", "slug") if q_alone(params) else dumps(rows)

    @mcp.tool(name="tag_proposals", annotations={"title": "Proposed tag changes", **RO})
    @answers
    async def tag_proposals(params: ProposalsIn | None = None) -> str:
        """Which free tags are proposed for the vocabulary, which merges, which applications to a document. For tags.manage, and for an editor of the document in an `apply`."""
        p = params or ProposalsIn()
        w = Where().add("p.status = {}", p.status)
        if p.kind:
            w.add("p.kind = {}", p.kind)
        rows = await q(f"""SELECT p.tag_proposal_id, p.kind, p.tag_id, t.slug AS tag_slug, t.label AS tag_label, p.into_tag_id, p.document_id, p.proposed_by, m.display_name AS proposed_by_name, p.note, p.status, p.decided_by, p.decided_at, p.created_at
                             FROM mcp_tag_proposals p LEFT JOIN mcp_tags t ON t.tag_id = p.tag_id LEFT JOIN mcp_members m ON m.member_id = p.proposed_by WHERE {w.sql()} ORDER BY p.created_at DESC LIMIT {lim(p.limit, 50)}""", *w.args)
        for r in rows:
            r["tag"] = {"tag_id": r.pop("tag_id"), "slug": r.pop("tag_slug"), "label": r.pop("tag_label")}
        return dumps(rows)

    @mcp.tool(name="documents_by_tag", annotations={"title": "Documents carrying a tag", **RO})
    @answers
    async def documents_by_tag(params: ByTagIn) -> str:
        """Which documents carry tag T — in a library, or under a folder."""
        tag = await tag_ref(q, params.tag)
        w = Where().add("dt.tag_id = {}", tag["tag_id"]).raw("d.trashed_at IS NULL")
        if params.library not in (None, ""):
            w.add("d.library_id = {}", await library_ref(q, params.library))
        if params.folder not in (None, ""):
            w.add("d.folder_id IN (SELECT dc_folder_subtree_ids({}))", await folder_ref(q, params.folder))
        rows = await q(f"""SELECT d.document_id, d.name, d.folder_id, d.library_id, d.owner_member_id, d.owner_name, d.mime_kind, d.byte_size, d.tags, d.updated_at, d.my_level, d.locked_by, d.trashed_at, count(*) OVER () AS total
                             FROM mcp_document_tags dt JOIN mcp_documents d ON d.document_id = dt.document_id WHERE {w.sql()} ORDER BY lower(d.name) LIMIT {lim(params.limit)} OFFSET {off(params.offset)}""", *w.args)
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        return dumps({"rows": rows, "total": total})

    @mcp.tool(name="untagged", annotations={"title": "Documents with no tag", **RO})
    @answers
    async def untagged(params: UntaggedIn | None = None) -> str:
        """Which documents (under folder X, or a library) have no tag at all."""
        p = params or UntaggedIn()
        fid = await folder_ref(q, p.folder) if p.folder not in (None, "") else None
        w = Where()
        fp = w.p(fid)
        if p.library not in (None, ""):
            w.add("d.library_id = {}", await library_ref(q, p.library))
        rows = await q(f"""SELECT u.document_id, u.name, u.folder_id, u.owner_member_id, u.created_at FROM dc_untagged({fp}, 1000) u JOIN mcp_documents d ON d.document_id = u.document_id WHERE {w.sql()} LIMIT {lim(p.limit, 50)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="search", annotations={"title": "Search by keyword", **RO})
    @answers
    async def search(params: SearchIn) -> str:
        """Search by keyword across everything I may see: the names, tags, descriptions and the WORDS of every document, with excerpts (the match marked <mark>…</mark>) and filters. This is the search box. It is not the document graph (graph_find) and it
        never composes an answer — it finds documents; to read one, document_text. A phrase in quotes, - to exclude and OR work as in a web search."""
        f = await filters_of(q, params)
        if not params.q.strip() and not f:
            raise Refusal("invalid_argument", "Give words to find in q, or at least one filter.", fields=["q"])
        return dumps(await keyword_search(q, bridge_, params.q, f, lim(params.limit), off(params.offset)))

    @mcp.tool(name="saved_searches", annotations={"title": "Saved searches", **RO})
    @answers
    async def saved_searches(params: SavedIn | None = None) -> str:
        """My saved searches and the shared ones. Alone, `q` resolves one by name (a plain list of {saved_search_id, name})."""
        p = params or SavedIn()
        w = Where()
        qmatch(w, p.q, "s.saved_search_id", "s.name")
        if p.mine:
            w.raw("s.owner_id = app_current_member_id()")
        rows = await q(f"""SELECT s.saved_search_id, s.owner_id, m.display_name AS owner_name, s.name, s.query, s.filters, s.kind, s.is_shared, s.updated_at
                             FROM mcp_saved_searches s LEFT JOIN mcp_members m ON m.member_id = s.owner_id WHERE {w.sql()} ORDER BY (s.owner_id = app_current_member_id()) DESC, lower(s.name) LIMIT {lim(p.limit)}""", *w.args)
        return plain(rows, "saved_search_id", "name") if q_alone(p) else dumps(rows)

    @mcp.tool(name="run_saved_search", annotations={"title": "Run a saved search", **RO})
    @answers
    async def run_saved_search(params: RunSavedIn) -> str:
        """Run one of my saved searches (or a shared one) now: a keyword search answers as `search`, a graph search as `graph_find`."""
        if is_int(params.saved_search):
            rows = await q("SELECT name, query, filters, kind FROM mcp_saved_searches WHERE saved_search_id = $1", int(params.saved_search))
        else:
            rows = await q("SELECT name, query, filters, kind, saved_search_id FROM mcp_saved_searches WHERE lower(name) = lower($1) ORDER BY (owner_id = app_current_member_id()) DESC LIMIT 7", str(params.saved_search).strip())
            if len(rows) > 1 and rows[0].get("saved_search_id") == rows[1].get("saved_search_id"):
                rows = rows[:1]
        if not rows:
            raise Refusal("not_found", "No saved search you can see matches that.")
        s = rows[0]
        filters = s["filters"] if isinstance(s["filters"], dict) else {}
        if s["kind"] == "graph":
            import dc_graph
            return dumps(await dc_graph.graph_find_impl(q, bridge_, s["query"], filters, lim(params.limit, 25, 50)))
        return dumps(await keyword_search(q, bridge_, s["query"], filters, lim(params.limit), off(params.offset)))

    @mcp.tool(name="search_gaps", annotations={"title": "What searches found nothing", **RO})
    @answers
    async def search_gaps(params: GapsIn | None = None) -> str:
        """What people searched for lately that found nothing — the gaps in what is stored or how it is named. For those holding reports.search."""
        p = params or GapsIn()
        need_right(await has_right(q, "reports.search"), "What searches found nothing is for those who hold reports.search.")
        since = when(p.since, "since") if p.since else None
        import datetime
        since = since or datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=30)
        return dumps(await q("SELECT query, times, last_at FROM dc_search_gaps($1, $2)", since, lim(p.limit, 50)))
