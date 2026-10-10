"""doccloud_records_mcp — DocCloud's record-memory server (PostgreSQL, the mcp_* views and the dc_* read functions, read-only).

Tool surface: docs/doccloud-mcp-tool-surface.md. Every tool reads as the asking member, so a person or an agent sees what the screens show them: the libraries, folders and documents the walls admit (ownership, the library's base level,
inherited additive rows, restrict; an `upload` holder sees only their own; a guest what names them), never a document they may not see — not by search, not by the graph. The views and the functions decide; the server only trims. An agent
sees only the tools the kernel granted it (server_common). The kernel's own token reaches app_roles and the four shares (folders_index, documents_index, document_text, document_link) as the requesting application. The document graph is reached
only through the application's own door (the bridge). Writes are never here: they are the kernel's Actions MCP, built from mcp/action_registry.json.
"""
from __future__ import annotations

from mcp.server.fastmcp import FastMCP
from pydantic import Field

import db
from dc_common import RO, _Base, answers, bridge, dumps

mcp = FastMCP("doccloud_records_mcp")


async def _pool():
    return await db.get_pool(db.ENV["MCP_RECORDS_DB_USER"], db.ENV["MCP_RECORDS_DB_PASSWORD"])


async def q(sql: str, *args) -> list[dict]:
    return await db.fetch_scoped(await _pool(), sql, *args)


@mcp.tool(name="app_roles", annotations={"title": "DocCloud's roles and rights", **RO})
async def app_roles() -> str:
    """The roles DocCloud offers and the rights each gives — what the Business OS kernel reads to let a super-admin grant them (schema os.app-roles/1): Guest, Member, Curator and DocCloud admin, with the 28 rights. The catalogue is not about anyone,
    so the kernel's own token may read it; so may any person or agent."""
    pool = await _pool()
    async with pool.acquire() as con:
        rights = await con.fetch("SELECT right_key, description FROM dc_rights ORDER BY sort_order, right_key")
        roles = await con.fetch("SELECT role_key, name, description, capability, is_admin, sort_order, rights FROM mcp_app_roles ORDER BY sort_order")
    return dumps({
        "schema": "os.app-roles/1",
        "application": "doccloud",
        "rights": [{"key": r["right_key"], "description": r["description"]} for r in rights],
        "roles": [{"key": r["role_key"], "name": r["name"], "description": r["description"], "capability": r["capability"], "is_admin": r["is_admin"], "rights": list(r["rights"])} for r in roles],
    })


class SearchSqlIn(_Base):
    sql: str = Field(..., description="One SELECT (or WITH … SELECT) over the mcp_* views")


@mcp.tool(name="records_search", annotations={"title": "Guarded read of the views", **RO})
async def records_search(params: SearchSqlIn) -> str:
    """The long tail: one read-only SELECT (or WITH … SELECT) over the mcp_* views, run as the asking member — one statement, no ';', 5 seconds, at most 200 rows. The readable views: mcp_members, mcp_departments, mcp_settings, mcp_libraries, mcp_groups,
    mcp_group_members, mcp_folders, mcp_documents, mcp_document_versions, mcp_document_text, mcp_upload_sessions, mcp_tags, mcp_document_tags, mcp_tag_proposals, mcp_folder_access, mcp_document_access, mcp_access_changes, mcp_share_links,
    mcp_link_opens, mcp_favourites, mcp_saved_searches, mcp_notes, mcp_notifications, mcp_graph_documents, mcp_graph_jobs, mcp_graph_usage, mcp_clerk_proposals, mcp_agent_dispatches, mcp_exports, mcp_tokens, mcp_search_log, mcp_app_roles,
    dc_rights, dc_roles, dc_role_rights — and the dc_* read functions (dc_search, dc_folder_listing, dc_can_see_document …). It is NOT the search box: keyword search is `search`, the graph is `graph_find`. Anything that is not a plain read is refused."""
    return await db.run_search(await _pool(), params.sql)


import dc_access  # noqa: E402
import dc_documents  # noqa: E402
import dc_graph  # noqa: E402
import dc_libraries  # noqa: E402
import dc_people  # noqa: E402
import dc_shares  # noqa: E402
import dc_tags_search  # noqa: E402

for module in (dc_libraries, dc_documents, dc_access, dc_tags_search, dc_graph, dc_people, dc_shares):
    module.register(mcp, q, bridge)


if __name__ == "__main__":
    import server_common
    server_common.run(mcp, "MCP_RECORDS_DB_USER", "MCP_RECORDS_DB_PASSWORD", port=int(db.ENV["MCP_RECORDS_PORT"]), endpoint_name="Records MCP")
