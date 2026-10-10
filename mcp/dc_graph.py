"""The document graph (G1-G5): graph_find, graph_related, document_subjects, graph_subjects, get_subject, subject_neighbors, graph_path, graph_queue, graph_estimate, graph_usage. The engine (a MaluDB memory database of its
own) is reached ONLY through the application's own door (the bridge → app/doccloud/graph.php, the one file that holds its token); every result is a document id judged by THE WALL (dc_graph_filter) before it is ranked or excerpted.
The default ask-me-anything never searches document content: the expert calls graph_find only when the person asks for the graph. A missing engine answers graph_unavailable and keyword search (`search`) still works."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from dc_common import (RO, Ref, Refusal, Where, _Base, answers, bridge, doc_ref, dumps, flag, folder_ref, has_right, is_int, library_ref, lim, need_right, off)

SENTENCES = {"graph_unavailable": "The document graph is not available right now; keyword search (search) still works.", "graph_timeout": "The document graph did not answer in time; try again, or use keyword search.",
             "not_in_graph": "That document is not in the document graph (its policy is none, or it is not indexed yet)."}
KNOWN = ("tag:", "folder:", "library:", "owner:", "doc:")


def judged(answer: dict) -> dict:
    """Turn the door's graph status into the tool's error codes; return the answer when it is a real one."""
    s = str(answer.get("status", "ok"))
    if s in ("ok", "no_subjects"):
        return answer
    if s in SENTENCES:
        raise Refusal(s, SENTENCES[s], retry=s == "graph_timeout")
    if s == "no_query":
        raise Refusal("invalid_argument", "Say what the documents should be about (2 to 400 characters).", fields=["query"])
    if s == "refused":
        raise Refusal("not_found", "Who is asking? No member is behind this call.")
    raise Refusal("graph_error", "The document graph could not answer (" + str(answer.get("detail") or s) + "); keyword search still works.", detail=str(answer.get("detail") or s))


class FindIn(_Base):
    query: str = Field(..., min_length=2, max_length=400, description="What the documents should be about, or a phrase they are near")
    library: Ref | None = None
    folder: Ref | None = Field(None, description="Only this folder and its subfolders")
    tag: str | None = Field(None, description="Only documents with this tag (slug)")
    kind: str | None = Field(None, description="image, pdf, text, spreadsheet …")
    limit: int = Field(25, ge=1, le=50, description="How many rows at most")


class RelatedIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")
    limit: int = Field(10, ge=1, le=50, description="How many rows at most")


class DocIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")


class SubjectsIn(_Base):
    library: Ref | None = Field(None, description="Only this library's documents' subjects")
    limit: int = Field(25, ge=1, le=50, description="How many rows at most")


class SubjectIn(_Base):
    subject: Ref = Field(..., description="A subject (id, or its name as the graph holds it)")


class NeighborsIn(_Base):
    subject: Ref = Field(..., description="A subject (id or name)")
    kind: Literal["subject", "document"] | None = Field(None, description="Only subjects, or only documents")
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class PathIn(_Base):
    from_: Ref = Field(..., alias="from", description="A subject (id or name)")
    to: Ref = Field(..., description="A subject (id or name)")
    max_depth: int = Field(4, ge=1, le=6, description="How many rows at most")


class QueueIn(_Base):
    status: Literal["queued", "running", "done", "failed", "skipped"] | None = None
    kind: str | None = None
    document: Ref | None = None
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class EstimateIn(_Base):
    folder: Ref = Field(..., description="The folder (id or path)")


class UsageIn(_Base):
    months: int = Field(3, ge=1, le=24, description="How many rows at most")


async def graph_find_impl(q, bridge_, query: str, filters: dict, limit: int) -> dict:
    """G1 — one find through the bridge; the filters were resolved to ids by the caller."""
    ans = judged(await bridge("graph_find", {"query": query, "filters": filters, "limit": limit}))
    out = {"query": ans.get("query", query), "subjects_used": ans.get("subjects", []), "rows": ans.get("rows", []), "candidates_read": ans.get("candidates", 0)}
    if ans.get("status") == "no_subjects":
        out["note"] = "Nothing in the graph matches those words; try the keyword search (search)."
    return out


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="graph_find", annotations={"title": "Find documents in the graph", **RO})
    @answers
    async def graph_find(params: FindIn) -> str:
        """Which documents are ABOUT X, or near this phrase — the document graph's find. Call it ONLY when the person asks for the graph, or when keyword search (search) found nothing and they want more. Never to compose an answer from a document's words
        (an answer cut from a document is Knowledge's). Every row is a document the asker may see; the excerpt is DocCloud's own text. graph_unavailable when the engine is down — keyword search still works."""
        f: dict = {}
        if params.library not in (None, ""):
            f["library"] = await library_ref(q, params.library)
        if params.folder not in (None, ""):
            f["folder"] = await folder_ref(q, params.folder)
        if params.tag:
            r = await q("SELECT slug FROM mcp_tags WHERE tag_id = dc_tag_resolve($1)", params.tag)
            f["tag"] = r[0]["slug"] if r else "-"
        if params.kind:
            f["kind"] = params.kind
        return dumps(await graph_find_impl(q, bridge_, params.query, f, lim(params.limit, 25, 50)))

    @mcp.tool(name="graph_related", annotations={"title": "Documents like this one", **RO})
    @answers
    async def graph_related(params: RelatedIn) -> str:
        """Which documents are like document X — they share subjects with it. Needs X in the graph (not_in_graph otherwise); every row is a document the asker may see."""
        did = await doc_ref(q, params.document)
        ans = judged(await bridge("graph_related", {"document": did, "limit": lim(params.limit, 10, 50)}))
        return dumps({"document_id": did, "subjects": ans.get("subjects", []), "rows": ans.get("rows", [])})

    @mcp.tool(name="document_subjects", annotations={"title": "A document's subjects", **RO})
    @answers
    async def document_subjects(params: DocIn) -> str:
        """Which subjects document X names in the graph — the ones DocCloud knows (its tags, folder, library, owner) and, under a `full` policy, the ones the model extracted."""
        did = await doc_ref(q, params.document)
        g = await q("SELECT policy, indexed_at, subjects FROM mcp_graph_documents WHERE document_id = $1", did)
        if not g or not g[0]["subjects"]:
            raise Refusal("not_in_graph", SENTENCES["not_in_graph"])
        subs = [{"name": s, "via": "known" if s.startswith(KNOWN) else "extracted"} for s in g[0]["subjects"] if not s.startswith("doc:")]
        return dumps({"document_id": did, "policy": g[0]["policy"], "indexed_at": g[0]["indexed_at"], "subjects": subs})

    @mcp.tool(name="graph_subjects", annotations={"title": "The most connected subjects", **RO})
    @answers
    async def graph_subjects(params: SubjectsIn | None = None) -> str:
        """The most connected subjects in the graph (a library's when given), each with how many of its documents the asker may see — a subject none of whose documents they may see is not listed."""
        p = params or SubjectsIn()
        a = {"limit": lim(p.limit, 25, 50)}
        if p.library not in (None, ""):
            a["library"] = await library_ref(q, p.library)
        ans = judged(await bridge("graph_subjects", a))
        return dumps(ans.get("subjects", []))

    @mcp.tool(name="get_subject", annotations={"title": "One subject", **RO})
    @answers
    async def get_subject(params: SubjectIn) -> str:
        """What subject Y is in the graph: its type, the documents about it that the asker may see, and its neighbour subjects."""
        ans = judged(await bridge("subject", {"subject": params.subject}))
        return dumps({k: ans.get(k) for k in ("subject_id", "name", "type", "documents", "neighbours") if k in ans})

    @mcp.tool(name="subject_neighbors", annotations={"title": "A subject's neighbourhood", **RO})
    @answers
    async def subject_neighbors(params: NeighborsIn) -> str:
        """Subject Y's neighbourhood — related subjects and documents; a document node the asker may not see is not listed."""
        ans = judged(await bridge("subject_neighbors", {"subject": params.subject, "kind": params.kind, "limit": lim(params.limit, 25)}))
        return dumps({"subject": ans.get("subject"), "neighbours": ans.get("neighbours", [])})

    @mcp.tool(name="graph_path", annotations={"title": "How two subjects connect", **RO})
    @answers
    async def graph_path(params: PathIn) -> str:
        """How subjects Y and Z are connected, step by step; a path through a document the asker may not see ends the answer at found: false."""
        ans = judged(await bridge("graph_path", {"from": params.from_, "to": params.to, "max_depth": params.max_depth}))
        return dumps({"found": bool(ans.get("found")), "steps": ans.get("steps", [])})

    @mcp.tool(name="graph_queue", annotations={"title": "The graph's queue and budget", **RO})
    @answers
    async def graph_queue(params: QueueIn | None = None) -> str:
        """What is queued, running, done, failed or skipped for the graph, and this month's text budget. For graph.manage."""
        p = params or QueueIn()
        need_right(await has_right(q, "graph.manage"), "The graph's queue is for those who hold graph.manage.")
        w = Where()
        if p.status:
            w.add("j.status = {}", p.status)
        if p.kind:
            w.add("j.kind = {}", p.kind)
        if p.document not in (None, ""):
            w.add("j.document_id = {}", await doc_ref(q, p.document))
        jobs = await q(f"""SELECT j.graph_job_id, j.document_id, d.name, j.kind, j.chars, j.status, j.attempts, left(j.error, 300) AS error, j.created_at, j.started_at, j.finished_at
                             FROM mcp_graph_jobs j LEFT JOIN mcp_documents d ON d.document_id = j.document_id WHERE {w.sql()} ORDER BY j.graph_job_id DESC LIMIT {lim(p.limit, 50)} OFFSET {off(p.offset)}""", *w.args)
        b = (await q("SELECT month, full_chars, budget_chars, pct, full_documents, index_documents FROM dc_graph_budget_state()"))[0]
        return dumps({"budget": b, "jobs": jobs})

    @mcp.tool(name="graph_estimate", annotations={"title": "What a full policy would cost", **RO})
    @answers
    async def graph_estimate(params: EstimateIn) -> str:
        """What a `full` graph policy would cost on folder X now: documents, characters, model calls, whether it passes the month's budget, and whether the engine is up. The budget figures are for graph.manage."""
        fid = await folder_ref(q, params.folder)
        e = (await q("SELECT documents, chars, calls, budget_left_chars, over_budget FROM dc_graph_estimate($1)", fid))[0]
        manage = await has_right(q, "graph.manage")
        try:
            up = bool((await bridge("graph_estimate_check", {})).get("engine_available"))
        except Refusal:
            up = False
        return dumps({"documents": e["documents"], "chars": e["chars"], "calls": e["calls"], "budget_left_chars": e["budget_left_chars"] if manage else None, "over_budget": e["over_budget"] if manage else None, "engine_available": up})

    @mcp.tool(name="graph_usage", annotations={"title": "What the graph spent", **RO})
    @answers
    async def graph_usage(params: UsageIn | None = None) -> str:
        """What the graph spent this month and the last ones — calls, characters, documents. Never dollars: prices are the kernel's AI statement. For those holding costs.read."""
        p = params or UsageIn()
        need_right(await has_right(q, "costs.read"), "The graph's usage is for those who hold costs.read.")
        rows = await q("SELECT month, kind, calls, chars, documents FROM mcp_graph_usage WHERE month >= (date_trunc('month', now()) - make_interval(months => $1 - 1))::date ORDER BY month DESC, kind", p.months)
        return dumps(rows)
