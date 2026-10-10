"""Documents and versions (D1-D8): get_document, find_documents, document_versions, document_text (also the K7 share for the kernel's token), document_page, document_excerpt, duplicates,
locked_documents, trash, trash_due, extraction_failures. The wall is mcp_documents / mcp_document_text / dc_can_see_document() — a document one may not see is "not found", the same words
as one that does not exist."""
from __future__ import annotations

import re
from typing import Literal

from pydantic import Field

import db
from dc_common import (qmatch, RO, Ref, Refusal, Where, _Base, _Flat, answers, bridge, doc_ref, dumps, flag, folder_ref, is_int, library_ref, like, lim, member_ref, off, plain, q_alone, tag_ref, when)

TEXT_CAP_BYTES = 1048576
SORTS = {"name": "lower(d.name)", "updated": "d.updated_at DESC", "size": "d.byte_size DESC"}


class DocumentIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")


class FindDocumentsIn(_Base):
    q: str | None = Field(None, max_length=200, description="Part of the name (then the nearest names); alone it resolves a document by name. Not for words INSIDE documents — that is `search`")
    library: Ref | None = Field(None, description="Only this library (id or name)")
    folder: Ref | None = Field(None, description="Only this folder and its subfolders (id or path)")
    owner: Ref | None = Field(None, description="Only this owner (member id or name)")
    tag: str | None = Field(None, description="Only this tag (slug, label or alias)")
    kind: str | None = Field(None, description="A kind of file: image, pdf, text, spreadsheet …")
    min_bytes: int | None = Field(None, ge=0)
    max_bytes: int | None = Field(None, ge=0)
    before: str | None = Field(None, description="Updated before this day")
    after: str | None = Field(None, description="Updated on or after this day")
    mine: bool = False
    shared_with_me: bool = False
    recent: bool = Field(False, description="What I opened or changed lately")
    favourites: bool = False
    include_trashed: bool = False
    sort: Literal["name", "updated", "size"] = "name"
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class VersionsIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")
    include_purged: bool = False
    limit: int = Field(25, ge=1, le=100, description="How many rows at most")


class TextIn(_Flat):
    # The kernel's flat call (the K7 share) carries `document` (an id) and `max_chars`; it adds fields of its own, so this model ignores what it does not know.
    document: Ref = Field(..., description="The document (id or name)")
    version: int | None = Field(None, description="A version id (default: the current one)")
    max_chars: int = Field(200000, ge=1000, le=1000000, description="Cut the text here (it then says truncated)")


class PageIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")
    page: int = Field(..., ge=1, description="The page, sheet or slide number")
    version: int | None = Field(None, description="A version id (default: the current one)")


class ExcerptIn(_Base):
    document: Ref = Field(..., description="The document (id or name)")
    quote: str = Field(..., min_length=1, max_length=500, description="The words to find (case and spacing do not matter)")
    window: int = Field(400, ge=100, le=1500, description="Characters kept each side")
    version: int | None = Field(None, description="A version id (default: the current one)")


class DuplicatesIn(_Base):
    limit: int = Field(50, ge=1, le=1000, description="How many rows at most")


class LockedIn(_Base):
    library: Ref | None = None
    folder: Ref | None = None
    mine: bool = False
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class TrashIn(_Base):
    folder: Ref | None = Field(None, description="Only what came out of this folder")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")
    offset: int = Field(0, ge=0, le=10000, description="Skip this many rows (paging)")


class TrashDueIn(_Base):
    days: int = Field(7, ge=1, le=365, description="How many rows at most")
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


class FailuresIn(_Base):
    status: Literal["no_text", "unsupported", "failed"] | None = None
    library: Ref | None = None
    limit: int = Field(50, ge=1, le=100, description="How many rows at most")


async def version_of(q, doc: int, version: int | None) -> dict:
    """The version row (through the view) of a document: the current one, or the one named (it must be the document's)."""
    if version is None:
        rows = await q("SELECT version_id, version_no, extracted_status, filename FROM mcp_document_versions WHERE document_id = $1 AND is_current", doc)
    else:
        rows = await q("SELECT version_id, version_no, extracted_status, filename FROM mcp_document_versions WHERE document_id = $1 AND version_id = $2", doc, version)
    if not rows:
        raise Refusal("not_found", "No such version of that document.")
    return rows[0]


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="get_document", annotations={"title": "One document", **RO})
    @answers
    async def get_document(params: DocumentIn) -> str:
        """What document X is: name, folder and path, owner, kind, size, tags, current version, lock, whether it is in the graph; for a manager also its access rows and links. The tool to read a record with. Never a storage path; the hash only."""
        did = await doc_ref(q, params.document)
        d = (await q("""SELECT document_id, library_id, folder_id, name, description, owner_member_id, owner_name, current_version_id, own_access, mime_type, mime_kind, byte_size, sha256, version_count,
                               locked_by, locked_at, lock_expires_at, trashed_at, created_at, updated_at, last_opened_at, tags, my_level, in_graph FROM mcp_documents WHERE document_id = $1""", did))[0]
        path = await q("SELECT folder_id, name, depth FROM dc_folder_path($1) ORDER BY depth", d["folder_id"])
        v = await q("""SELECT v.version_id, v.version_no, v.filename, v.uploaded_by, u.display_name AS uploaded_by_name, v.uploaded_at, v.extracted_status
                         FROM mcp_document_versions v LEFT JOIN mcp_members u ON u.member_id = v.uploaded_by WHERE v.document_id = $1 AND v.is_current""", did)
        tags = await q("SELECT tag_id, slug, label, (SELECT kind FROM mcp_tags t WHERE t.tag_id = x.tag_id) AS kind FROM mcp_document_tags x WHERE document_id = $1 ORDER BY slug", did)
        out: dict = {"document": d, "path": path, "owner_name": d["owner_name"], "current_version": v[0] if v else None, "tags": tags}
        if d["locked_by"]:
            ln = await q("SELECT display_name FROM mcp_members WHERE member_id = $1", d["locked_by"])
            out["lock"] = {"locked_by_name": ln[0]["display_name"] if ln else None, "locked_at": d["locked_at"], "lock_expires_at": d["lock_expires_at"]}
        if (await q("SELECT dc_can_manage_document($1) AS m", did))[0]["m"]:
            out["access"] = await q("SELECT document_access_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, created_at FROM mcp_document_access WHERE document_id = $1 ORDER BY created_at", did)
            out["links"] = await q("SELECT link_id, label, kind, allow_download, has_password, expires_at, view_count, revoked_at FROM mcp_share_links WHERE document_id = $1 AND revoked_at IS NULL AND rotated_at IS NULL ORDER BY created_at DESC LIMIT 20", did)
        g = await q("SELECT policy FROM mcp_graph_documents WHERE document_id = $1", did)
        out["in_graph"] = bool(g) and not str(g[0]["policy"]).startswith("none")
        out["graph_policy"] = g[0]["policy"] if g else (await q("SELECT dc_effective_graph_policy($1) AS p", d["folder_id"]))[0]["p"]
        return dumps(out)

    @mcp.tool(name="find_documents", annotations={"title": "Find documents", **RO})
    @answers
    async def find_documents(params: FindDocumentsIn) -> str:
        """Documents by name, tag, owner, kind, size, dates, folder subtree — or mine, shared with me, recent, favourites. Alone, `q` resolves a document by name (a plain list of {document_id, name, folder_path}). NOT for words inside documents (that is `search`)."""
        w = Where()
        if not params.include_trashed:
            w.raw("d.trashed_at IS NULL")
        if params.library not in (None, ""):
            w.add("d.library_id = {}", await library_ref(q, params.library))
        if params.folder not in (None, ""):
            w.add("d.folder_id IN (SELECT dc_folder_subtree_ids({}))", await folder_ref(q, params.folder))
        if params.owner not in (None, ""):
            w.add("d.owner_member_id = {}", await member_ref(q, params.owner))
        if params.tag:
            w.add("d.document_id IN (SELECT document_id FROM mcp_document_tags WHERE tag_id = {})", (await tag_ref(q, params.tag))["tag_id"])
        if params.kind:
            w.add("d.mime_kind = {}", params.kind)
        if params.min_bytes is not None:
            w.add("d.byte_size >= {}", params.min_bytes)
        if params.max_bytes is not None:
            w.add("d.byte_size <= {}", params.max_bytes)
        if params.after:
            w.add("d.updated_at >= {}", when(params.after, "after"))
        if params.before:
            w.add("d.updated_at < {}", when(params.before, "before"))
        if params.mine:
            w.raw("d.owner_member_id = app_current_member_id()")
        if params.shared_with_me:
            w.raw("(d.document_id IN (SELECT id FROM dc_shared_with_me(500, 0) WHERE kind = 'document') OR d.folder_id IN (SELECT dc_folder_subtree_ids(id) FROM dc_shared_with_me(500, 0) WHERE kind = 'folder'))")
            w.raw("d.owner_member_id <> app_current_member_id()")
        if params.recent:
            w.raw("d.document_id IN (SELECT document_id FROM dc_recent_documents(100))")
        if params.favourites:
            w.raw("d.document_id IN (SELECT document_id FROM mcp_favourites WHERE document_id IS NOT NULL)")
        qmatch(w, params.q, "d.document_id", "d.name")
        order = SORTS[params.sort]
        cols = "d.document_id, d.name, d.folder_id, d.library_id, d.owner_member_id, d.owner_name, d.mime_kind, d.byte_size, d.tags, d.updated_at, d.my_level, d.locked_by, d.trashed_at"
        rows = await q(f"SELECT {cols}, count(*) OVER () AS total FROM mcp_documents d WHERE {w.sql()} ORDER BY {order} LIMIT {lim(params.limit)} OFFSET {off(params.offset)}", *w.args)
        nearest = False
        if not rows and params.q and not params.offset:
            ids = [r["document_id"] for r in await q("SELECT document_id FROM dc_search_names($1, $2)", params.q, lim(params.limit, 25, 25))]
            if ids:
                rows = await q(f"SELECT {cols}, count(*) OVER () AS total FROM mcp_documents d WHERE d.document_id = ANY($1::bigint[]) AND d.trashed_at IS NULL", ids)
                nearest = True
        total = int(rows[0]["total"]) if rows else 0
        for r in rows:
            r.pop("total", None)
        if q_alone(params):
            for r in rows:
                r["folder_path"] = (await q("SELECT dc_folder_path_text($1) AS t", r["folder_id"]))[0]["t"]
            return plain(rows, "document_id", "name", "folder_path")
        return dumps({"rows": rows, "total": total, **({"note": "No exact match: these are the nearest names."} if nearest else {})})

    @mcp.tool(name="document_versions", annotations={"title": "A document's versions", **RO})
    @answers
    async def document_versions(params: VersionsIn) -> str:
        """A document's versions, newest first: who uploaded each, from where, its size; which are purged."""
        did = await doc_ref(q, params.document)
        w = Where().add("v.document_id = {}", did)
        if not params.include_purged:
            w.raw("v.purged_at IS NULL")
        rows = await q(f"""SELECT v.version_id, v.version_no, v.filename, v.mime_type, v.byte_size, v.sha256, v.uploaded_by, u.display_name AS uploaded_by_name, v.uploaded_at, v.source, v.note, v.extracted_status, v.purged_at, v.is_current
                             FROM mcp_document_versions v LEFT JOIN mcp_members u ON u.member_id = v.uploaded_by WHERE {w.sql()} ORDER BY v.version_no DESC LIMIT {lim(params.limit)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="document_text", annotations={"title": "What a document says", **RO})
    @answers
    async def document_text(params: TextIn) -> str:
        """What document X says: its extracted text (the current version, or one named) and its pages. The tool an agent reads content with — capped at 1 MB a call (ask a page with document_page, or an excerpt, when it says too_large).
        A version with no text says why (status no_text, unsupported, failed, pending) and text ''. As the K7 share os.doccloud-text/1 the kernel's token reads it for a document under a folder shared with the requesting application."""
        if db.request_is_kernel.get():
            doc = await bridge("share_text", {"document": params.document, "max_chars": params.max_chars}, application=True)
            return dumps(doc)
        did = await doc_ref(q, params.document)
        v = await version_of(q, did, params.version)
        t = await q("SELECT left(text, $2) AS text, char_count, pages, extractor, truncated FROM mcp_document_text WHERE version_id = $1", v["version_id"], params.max_chars)
        text = (t[0]["text"] if t else "") or ""
        char_count = int(t[0]["char_count"] or 0) if t else 0
        if len(text.encode()) > TEXT_CAP_BYTES:
            raise Refusal("too_large", "That text is over 1 MB; ask a page (document_page) or an excerpt (document_excerpt), or lower max_chars.")
        cut = char_count > len(text)
        pages = [p for p in (t[0]["pages"] or [] if t else []) if int(p.get("char_from", 0)) < len(text)]
        status = "ready" if (t and v["extracted_status"] == "ready") else v["extracted_status"]
        return dumps({"document_id": did, "version_id": v["version_id"], "extractor": t[0]["extractor"] if t else None, "char_count": char_count, "truncated_by_extractor": bool(t[0]["truncated"]) if t else False,
                      "text": text, "truncated": cut, "pages": [{"page_no": p.get("page_no"), "char_from": p.get("char_from"), "char_to": p.get("char_to")} for p in pages], "status": status})

    @mcp.tool(name="document_page", annotations={"title": "One page of a document", **RO})
    @answers
    async def document_page(params: PageIn) -> str:
        """One page of a paged document — a PDF page, a sheet, a slide."""
        did = await doc_ref(q, params.document)
        v = await version_of(q, did, params.version)
        r = await q("SELECT page_no, char_from, char_to, text, page_count FROM dc_document_page($1, $2)", v["version_id"], params.page)
        if not r:
            raise Refusal("not_found", "That document has no such page (or no text by pages).")
        return dumps({"document_id": did, "version_id": v["version_id"], "page": r[0]["page_no"], "page_count": r[0]["page_count"], "text": r[0]["text"], "char_from": r[0]["char_from"], "char_to": r[0]["char_to"]})

    @mcp.tool(name="document_excerpt", annotations={"title": "The words around a quote", **RO})
    @answers
    async def document_excerpt(params: ExcerptIn) -> str:
        """The words around a match: the quote with a little before and after, to cite a document in an answer. Matching ignores case; the passage is DocCloud's own text, never the graph's."""
        did = await doc_ref(q, params.document)
        v = await version_of(q, did, params.version)
        t = await q("SELECT text, pages FROM mcp_document_text WHERE version_id = $1", v["version_id"])
        text = (t[0]["text"] if t else "") or ""
        words = params.quote.split()
        m = re.compile(r"\s+".join(re.escape(x) for x in words), re.I | re.U).search(text) if words else None
        if not m:
            return dumps({"document_id": did, "found": False, "before": "", "match": "", "after": "", "page_no": None})
        w = params.window
        page = next((p.get("page_no") for p in (t[0]["pages"] or []) if int(p.get("char_from", 0)) <= m.start() < int(p.get("char_to", 0))), None)
        return dumps({"document_id": did, "found": True, "before": text[max(0, m.start() - w):m.start()], "match": m.group(0), "after": text[m.end():m.end() + w], "page_no": page})

    @mcp.tool(name="duplicates", annotations={"title": "The same bytes in two places", **RO})
    @answers
    async def duplicates(params: DuplicatesIn | None = None) -> str:
        """The same bytes in two places: by wasted bytes. A curator reads every group, but only the copies the asker may see are named — a group with fewer than two visible copies is left out."""
        n = lim(params.limit if params else 50, 50, 1000)
        rows = await q("SELECT sha256, byte_size, copies, document_ids, names FROM dc_duplicates($1)", n)
        out = []
        for r in rows:
            vis = await q("SELECT document_id, name, folder_id FROM mcp_documents WHERE document_id = ANY($1::bigint[]) AND trashed_at IS NULL ORDER BY document_id", r["document_ids"])
            if len(vis) >= 2:
                out.append({"sha256": r["sha256"], "byte_size": r["byte_size"], "copies": len(vis), "documents": vis})
        return dumps(out)

    @mcp.tool(name="locked_documents", annotations={"title": "What is locked", **RO})
    @answers
    async def locked_documents(params: LockedIn | None = None) -> str:
        """What is locked, by whom, since when and until when."""
        p = params or LockedIn()
        w = Where()
        if p.library not in (None, ""):
            w.add("d.library_id = {}", await library_ref(q, p.library))
        if p.folder not in (None, ""):
            w.add("d.folder_id IN (SELECT dc_folder_subtree_ids({}))", await folder_ref(q, p.folder))
        if p.mine:
            w.raw("l.locked_by = app_current_member_id()")
        rows = await q(f"""SELECT l.document_id, l.name, l.locked_by, m.display_name AS locked_by_name, l.locked_at, l.lock_expires_at, l.folder_id
                             FROM dc_locked_documents() l JOIN mcp_documents d ON d.document_id = l.document_id LEFT JOIN mcp_members m ON m.member_id = l.locked_by WHERE {w.sql()} ORDER BY l.locked_at LIMIT {lim(p.limit, 50)}""", *w.args)
        return dumps(rows)

    @mcp.tool(name="trash", annotations={"title": "My trash", **RO})
    @answers
    async def trash(params: TrashIn | None = None) -> str:
        """What is in my trash (or, for a folder's managers, that folder's), and when each purges."""
        p = params or TrashIn()
        fid = await folder_ref(q, p.folder) if p.folder not in (None, "") else None
        rows = await q(f"SELECT t.kind, t.id, t.name, t.trashed_at, t.trashed_by, m.display_name AS trashed_by_name, t.purge_at, t.from_folder_id FROM dc_trash($1) t LEFT JOIN mcp_members m ON m.member_id = t.trashed_by ORDER BY t.trashed_at DESC LIMIT {lim(p.limit, 50)} OFFSET {off(p.offset)}", fid)
        return dumps(rows)

    @mcp.tool(name="trash_due", annotations={"title": "What purges soon", **RO})
    @answers
    async def trash_due(params: TrashDueIn | None = None) -> str:
        """What purges within N days (a week by default) — so an owner can restore it in time."""
        p = params or TrashDueIn()
        rows = await q(f"SELECT kind, id, name, purge_at, owner_member_id FROM dc_trash_due($1) LIMIT {lim(p.limit, 50)}", p.days)
        return dumps(rows)

    @mcp.tool(name="extraction_failures", annotations={"title": "Documents with no readable text", **RO})
    @answers
    async def extraction_failures(params: FailuresIn | None = None) -> str:
        """Documents with no text — extraction failed, found nothing, or is unsupported — and why (the error cut at 300 characters)."""
        p = params or FailuresIn()
        w = Where()
        if p.status:
            w.add("f.status = {}", p.status)
        if p.library not in (None, ""):
            w.add("d.library_id = {}", await library_ref(q, p.library))
        rows = await q(f"""SELECT f.document_id, f.name, f.version_id, f.status, left(f.error, 300) AS error, f.uploaded_at FROM dc_extraction_failures({lim(p.limit, 50, 1000)}) f
                             JOIN mcp_documents d ON d.document_id = f.document_id WHERE {w.sql()} ORDER BY f.uploaded_at DESC""", *w.args)
        return dumps(rows)
