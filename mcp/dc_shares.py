"""The shares[] of maludb-os.json (design §8, K7): folders_index, documents_index and document_link — the kernel's own token reaches them (and document_text, in dc_documents) over a super-admin-approved connection. ONE truth, two doors: the tools
do not rebuild the documents, they post to the application's internal door (the bridge, op share_*), which calls app/features/shares/queries.php. WHO is asking is the kernel's word, never an argument: the headers X-OS-Consumer and
X-OS-Consumer-Agent (K26) are read by the gate (server_common) and passed on; no header → consumer_unknown and nothing is shared. A person's or an agent's token never lists or calls these three."""
from __future__ import annotations

from typing import Literal

from pydantic import Field

from dc_common import RO, Ref, _Flat, answers, bridge, dumps


class FoldersIndexIn(_Flat):
    q: str | None = Field(None, description="Words of a folder's path")


class DocumentsIndexIn(_Flat):
    folder: int = Field(..., description="A folder id from folders_index")
    since: str | None = Field(None, description="Only documents updated since this time")
    limit: int = Field(200, ge=1, le=200, description="How many rows at most")
    offset: int = Field(0, ge=0, le=1000000, description="Skip this many rows (paging)")


class DocumentLinkIn(_Flat):
    document: int = Field(..., description="A document id from documents_index")
    disposition: Literal["attachment", "inline"] = "attachment"


import dc_common as _dc  # noqa: E402

_dc.describe_models(globals())


def register(mcp, q, bridge_=None) -> None:
    @mcp.tool(name="folders_index", annotations={"title": "Folders shared with the application (share)", **RO})
    @answers
    async def folders_index(params: FoldersIndexIn | None = None) -> str:
        """A shared tool (os.doccloud-folders/1): the folders shared with the requesting application — id, path, library, level, how many documents — for a folder whose access carries a row naming that application. Nothing else, and no person's id."""
        p = params or FoldersIndexIn()
        return dumps(await bridge("share_folders", {k: getattr(p, k) for k in p.model_fields_set}, application=True))

    @mcp.tool(name="documents_index", annotations={"title": "A shared folder's documents (share)", **RO})
    @answers
    async def documents_index(params: DocumentsIndexIn) -> str:
        """A shared tool (os.doccloud-documents/1): the live documents of a folder shared with the requesting application, its subtree included — id, name, path, kind, size, sha256, tags, owner's name, version, extraction status, updated — paged, with `since`. A folder
        not shared answers not_shared, the same words as one that does not exist."""
        return dumps(await bridge("share_documents", {k: getattr(params, k) for k in params.model_fields_set}, application=True))

    @mcp.tool(name="document_link", annotations={"title": "A signed download URL (share)", **RO})
    @answers
    async def document_link(params: DocumentLinkIn) -> str:
        """A shared tool (os.doccloud-link/1): a signed ten-minute download URL for one document in a shared folder — the bytes never cross the kernel's JSON, and the open is logged under the requesting application."""
        return dumps(await bridge("share_link", {k: getattr(params, k) for k in params.model_fields_set}, application=True))
