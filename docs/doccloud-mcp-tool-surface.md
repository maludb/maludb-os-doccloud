# DocCloud — MCP tool surface

2026-10-09 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/doccloud-design.md` §7 (L1–L5, D1–D8,
A1–A7, T1–T3, S1–S3, G1–G5, P1–P4, ACT1–ACT5), the four K7 shares, and the resolvers the kernel's actions server names. Writes are
never here: they are the action tools the kernel's Actions MCP builds from `docs/doccloud-action-manifest.md`. **The default
ask-me-anything never searches document content** (D4): `search` is keyword over the index, and `graph_find` is a tool the expert
calls only when the person asks for the graph.

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd
unit each (`doccloud-records-mcp`, `doccloud-activity-mcp`); `mcp/db.py` and `mcp/server_common.py` (already here, the kit's).
Every query runs through `db.fetch_scoped()` with `app.member_id` and `app.role` set **transaction-locally** from the verified
bearer, so the `mcp_*` views (db/015) and the read functions (db/014) decide the rows and answer as the caller. Tool names are
snake_case. Every tool takes one `params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field`
on every field). Every list tool takes `limit` (1–100, default 25) and, where it pages, `offset` (0–10,000). Every tool carries
`readOnlyHint: true` and `openWorldHint: false`; every result is a JSON string (`db.to_json`). Ids are integers, times ISO 8601 with
the zone, tag and group keys lower-case slugs. **THE SCALE RULE holds here too:** no tool asks for the caller's whole visible set;
each one reads a filtered, paged view or function whose wall is per row.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`.
It never raises, except for the run-facts gate's own refusal, which is the contract's ToolError. Codes:

| Code | Means |
|---|---|
| `not_found` | no such record, **or one the caller may not see**: the same words, so a tool never confirms that a document exists |
| `managers_only` | the record is readable, but this tool is a manager's on that thing (access rows, links, the trail of who could see) |
| `invalid_argument` | a parameter failed validation; `fields` names it |
| `ambiguous` | a name matched several records; `candidates[]` (≤ 6, id + label) so the agent asks one question |
| `graph_unavailable` | no `MALUDB_DC_API_*`, or MaluDB cannot be reached (`graph.php`); keyword search still works |
| `graph_timeout` | MaluDB did not answer in time |
| `graph_error` | any other engine failure (`graph_auth`, `graph_forbidden`, `model_failed` collapse here, `detail` = the engine's code) |
| `not_in_graph` | the document is under a `none` policy or not yet indexed (`graph_related`, `document_subjects`) |
| `bridge_unavailable` | the PHP side of the bridge did not answer (Apache down); `retry: true` |
| `too_large` | the answer would pass the tool's byte cap (a text over 1 MB through `document_text`): ask for a page or an excerpt |

Gates are what the view or function already enforces. The tool only trims and chooses the error. Words used below (db/004, db/008,
db/015, design §3):

| Word | Means |
|---|---|
| `view` | `dc_can_see_document()` / `dc_can_see_folder()` is true for the caller: the owner, the library's base level, an explicit row naming one of their principals, the admin |
| `manage` | `dc_can_manage_document()` / `dc_can_manage_folder()`: the owner, the maker, a `manage` row, the library's owner(s), the admin |
| `own` | the caller's own: their favourites, saved searches, notifications, tokens, upload sessions, trail |
| `insider` | `dc_is_member_here()`: admitted and not a guest |
| `curator` | `dc_has_right('groups.manage')` and its siblings (`reports.storage`, `reports.search`, `tags.manage`) |
| `admin` | `dc_is_admin()`: the super-admin, or a human holding `settings.manage`; sees every library (PHP logs `library.admin_view`) |
| `kernel` | the kernel's own token (`kernel.{exp}.{app}.{nonce}.{hmac}`): `app_roles` and the four shares, nothing else |

**Reach is the thing's, never the role's alone.** A right is a capability; whether it applies to this folder is the walls of db/008.
**A guest** gets what names them and the people who shared it. **An agent is a member**: the same functions, no special case. **A link
holder is nobody here**: `/s/` and `/r/` are PHP doors, never MCP.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `doccloud_records_mcp` | `doccloud_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8841) | `MCP_ACTIVITY_PORT` (pinned 8842) |
| Role / env | `doccloud_records_ro` | `doccloud_activity_ro` |
| Sees | every `mcp_*` view but `mcp_activity_log`; the db/006–014 read functions granted to it; `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_documents`, `mcp_folders`, `mcp_libraries`, `mcp_groups` (names beside a row) |
| Public path | `doccloud.<domain>/mcp/records` | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`, the kit's).

1. **A person's own token** (`mcp_` + 48 hex, scope `mcp`, shown once, sha256 kept): `mcp_resolve_token(hash, 'mcp')`, active and
   admitted. **A key (scope `api`) is refused here** (401): keys are for the token API. Never tool-filtered; its rows are the views'.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): the person's `{mid}.{exp}.{hmac}` or the agent run token `{mid}.{exp}.{run}.{hmac}`.
   The member id must be in the mirror and admitted; **an unknown id is refused, never created**. For a run token the server asks
   the kernel's run-facts (`POST {OS_INTERNAL_URL}/api/v1/runs/facts.php`, bearer `OS_APPLICATION_TOKEN`), caches 300 s, and offers
   exactly the tools named for `Records MCP` / `Activity MCP`; a vouched agent is admitted on first contact (`mcp_admit_agent`).
   **Fail closed** when the kernel is unreachable or `valid: false`. **`is_eval`:** these servers never write; `graph_find` under an
   eval run logs `search.graph` with `after.eval = true` and writes no `search_log` row.
3. **The kernel's own token** (`verify_kernel_token`, 60 s, bound to `APP_KEY = doccloud`): `KERNEL_TOOLS = {app_roles, folders_index,
   documents_index, document_text, document_link}`, `KERNEL_ONLY = {folders_index, documents_index, document_link}` (a person or an
   agent reads `document_text` as themselves; the other three answer only as an application principal). No member identity is set;
   the shares answer through the bridge as the **application principal** named by `X-OS-Consumer` (K26).

**Identity on every query.** `app.member_id` = the resolved member, `app.role` = `members.business_role`, transaction-local. The
server never filters rows in Python by who is asking: the views and functions do. Names resolve to ids through the same views.

### The bridge (how a Python tool reaches `graph.php` and PHP-only reads)

The MaluDB token is read in ONE file, `app/doccloud/graph.php` (CLAUDE.md). The read servers never call MaluDB. Every graph-backed
tool, `document_link` (a signed URL PHP mints) and the shares post to PHP on the **internal port only**:

```
POST http://127.0.0.1:{APP_INTERNAL_PORT}/internal/bridge.php
X-DC-Bridge: {unix_ts}.{hex hmac_sha256(ACTIONS_RELAY_KEY, "dc-bridge:" + unix_ts + "." + sha256_hex(body))}
Content-Type: application/json
{"op": "<op>", "caller": {"kind": "member", "member_id": 26} | {"kind": "application", "application": "knowledge", "agent_member_id": 70},
 "door": "mcp" | "agent" | "share", "run_id": 812 | null, "request_id": "…" | null, "is_eval": false, "args": {…}}
```

- PHP refuses with 401 unless `REMOTE_ADDR` is loopback, the timestamp is within ±30 s and the HMAC verifies. The path is **not** on
  the vhost's public allow-list.
- A `member` caller must be an active, admitted mirror row (403 `unknown_member`); PHP sets `app.member_id` (`db_apply_context`) and
  applies the **same walls** as the screens: `dc_can_see_document()`, `dc_can_manage_folder()`. An `application` caller is judged by
  `dc_application_folder_rank()` / `dc_application_can_see_document()` alone and is honoured only for the share ops.
- Ops: `graph_find`, `graph_related`, `document_subjects`, `graph_subjects`, `subject`, `subject_neighbors`, `graph_path`,
  `graph_estimate_check` (the engine's availability beside the SQL estimate), `admin_view`, `document_link`, `share_folders`,
  `share_documents`, `share_text`, `share_link`. Any other op answers 404.
- The answer is the tool's JSON, verbatim. The Python side waits 20 s for `graph_find` / `graph_related`, 55 s for `share_text`
  (the share declares 60), 10 s otherwise. Built by slice 5 (the graph ops), slice 7 (`document_link` and the share ops), slice 9
  (`admin_view`); Phase 4 wires the Python side.

**Every graph answer passes THE WALL** (design §6.3): a candidate is a `maludb_document_id` (or a `document_id` from metadata when a
route carries it); `dc_graph_filter(candidates, limit)` maps it through `graph_documents` to `documents` and keeps only what
`dc_can_see_document()` allows — in one statement, before ranking, before any excerpt. A tenant-wide count is never returned.

## Records server: `doccloud_records_mcp`

### Roles (kernel db/145)
`app_roles` takes no arguments. It answers `os.app-roles/1`: `{"schema", "application": "doccloud", "roles": [{key, name, description,
capability, is_admin, rights[]}], "rights": [{key, description}]}` from `mcp_app_roles` and `dc_rights` (db/004): 4 roles, 28 rights.
Callers: `kernel` and anyone admitted.

### Libraries, groups and folders (L1–L5)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_libraries` | Which libraries I may reach, of which kind, how full, with what graph policy. `q` alone resolves a library by name | L1 | `dc_my_libraries()`; `mcp_libraries` for `q` | `q?` (≤ 100, name ILIKE), `kind?` (personal, group, shared), `include_archived?` (false), `limit` | rows `{library_id, kind, name, group_id, root_folder_id, my_level, used_bytes, quota_bytes, graph_policy, archived_at}` | the function: own, a member's group, everyone's shared; the admin all |
| `get_library` | One library at a glance: counts, quota, policy, its group or owner, the root folder | L1 | `mcp_libraries`, `dc_quota_state(id)`, `mcp_groups` | `library` (id or name) | `{library, quota: {used_bytes, quota_bytes, pct, near}, group?, owner_name, root_folder_id, document_count, folder_count}`. An admin opening a personal library they hold no level on gets `admin_view: true` and is logged `library.admin_view` through the bridge (once per library per caller per hour) | view (`my_level <> 'none'`) |
| `folder_listing` | What is in folder X: subfolders and documents, sorted, paged, with tags, sizes and my level on each. The tool an agent browses with | L2 | `dc_folder_listing(folder, sort, dir, limit, offset, kind)` | `folder` (id or path), `sort?` (name, updated, size), `dir?` (asc, desc), `kind?` (folder, document, or a mime_kind), `limit` (≤ 500), `offset` | `{folder: {folder_id, name, path: [{folder_id, name}], my_level}, rows: [{kind, id, name, mime_kind, byte_size, owner_member_id, owner_name, updated_at, tags, level, locked_by, version_count, has_thumbnail}], total}` | the function (an `upload` holder sees only their own documents) |
| `get_folder` | One folder: its crumbs, counts, auto-tags, policy, its access summary | L2 | `mcp_folders`, `dc_folder_path(id)`, `dc_effective_graph_policy(id)`, counts from `mcp_folders`/`mcp_documents` | `folder` | `{folder, path[], effective_graph_policy, subfolder_count, document_count, subtree_bytes, access_summary: {people, groups, departments, applications, everyone, inherited_from}}` — the summary's counts come from `mcp_folder_access` when `manage`, else only `inherited_from` | view |
| `folder_path` | Where a folder is: its path from the library root | L3 | `dc_folder_path(id)` | `folder` | rows `{folder_id, name, depth}` root first | view |
| `find_folders` | A folder by name or path, e.g. to move something. `q` alone resolves one (the registry's `folder` resolver) | L2 | `mcp_folders`; `dc_folder_path_text(id)` for the label (owed, below) | `q?` (name ILIKE or a `/a/b/c` path), `library?`, `under?` (a folder: its subtree), `include_trashed?` (false), `limit` | rows `{folder_id, name, path_text, library_id, parent_id, depth, my_level, trashed_at}` | view |
| `find_groups` | Which groups exist; which I am in or own. `q` alone resolves a group | L4 | `mcp_groups` | `q?` (key or name ILIKE), `kind?` (custom, department), `mine?` (bool), `include_archived?`, `limit` | rows `{group_id, key, name, description, kind, department_id, member_level, member_count, i_am_member, i_am_owner, archived_at}` | insider |
| `get_group` | One group: its owners, its library, its level | L4 | `mcp_groups`, `mcp_group_members`, `mcp_libraries` | `group` | `{group, owners: [{member_id, display_name}], library: {library_id, root_folder_id, used_bytes, quota_bytes}}` | insider |
| `group_members` | Who is in group G, since when, who added them | L4 | `mcp_group_members` | `group`, `role?` (owner, member), `include_left?` (false), `limit`, `offset` | rows `{member_id, display_name, role, added_by, joined_at, left_at}` | insider |
| `my_groups` | The groups I am in | L4 | `mcp_groups WHERE i_am_member` | `limit` | rows as `find_groups` | insider |
| `storage_by_library` | What each library uses, against its quota; which are near | L5 | `dc_storage_by_library()` | `near_only?` (false), `limit` | rows `{library_id, kind, name, used_bytes, quota_bytes, pct, documents, versions}` | `reports.storage`, or the libraries the caller manages (the function) |
| `storage_by_owner` | Who holds the most bytes | L5 | `dc_storage_by_owner(limit)` | `limit` (≤ 500) | rows `{member_id, display_name, documents, live_bytes}` | `reports.storage` (the function answers nothing else) |
| `quota_state` | A library's quota now | L5 | `dc_quota_state(id)` | `library` | `{used_bytes, quota_bytes, pct, near}` | view |

### Documents and versions (D1–D8)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `get_document` | What document X is: name, folder, owner, kind, size, tags, versions, lock, shares, links. The tool an agent reads a record with | D1 | `mcp_documents`, `dc_folder_path(folder_id)`, `mcp_document_versions` (count, current), `mcp_document_tags`; for `manage`: `mcp_document_access`, `mcp_share_links` | `document` (id or name) | `{document (the view's row), path[], owner_name, current_version: {version_id, version_no, filename, uploaded_by_name, uploaded_at, extracted_status}, tags: [{tag_id, slug, label, kind}], lock?: {locked_by_name, locked_at, lock_expires_at}, access?: [...], links?: [...], in_graph, graph_policy}`. `sha256` and `storage_path`: the hash only, never a path | view |
| `find_documents` | Documents by name, tag, owner, kind, size, dates, folder subtree, mine, shared with me, recent, favourites. `q` alone resolves a document by name (the registry's `document` resolver). Not for words inside documents (`search`) | D2 | `mcp_documents` (+ `mcp_favourites`, `dc_recent_documents()` for those modes); `dc_search_names(q)` when nothing matched exactly | `q?` (name ILIKE, then trigram), `library?`, `folder?` (its subtree), `owner?` (member), `tag?` (slug), `kind?` (mime_kind), `min_bytes?`, `max_bytes?`, `before?`, `after?`, `mine?`, `shared_with_me?`, `recent?`, `favourites?`, `include_trashed?` (false), `sort?` (name, updated, size), `limit`, `offset` | rows `{document_id, name, folder_id, library_id, owner_member_id, owner_name, mime_kind, byte_size, tags, updated_at, my_level, locked_by, trashed_at}`, `total` | view (the view) |
| `document_versions` | A document's versions: who uploaded each, from where, what changed in size; which are purged | D3 | `mcp_document_versions` | `document`, `include_purged?` (false), `limit` | rows `{version_id, version_no, filename, mime_type, byte_size, sha256, uploaded_by, uploaded_by_name, uploaded_at, source, note, extracted_status, purged_at, is_current}` newest first | view |
| `document_text` | **What document X says**: its extracted text (the current version, or one named), its pages. The tool an agent reads content with. Capped at 1 MB per call (`too_large` → ask a page) | D4 | `mcp_document_text` (+ `mcp_document_versions` for a version) | `document`, `version?` (id), `max_chars?` (1,000–1,000,000, default 200,000) | `{document_id, version_id, extractor, char_count, truncated_by_extractor, text (cut to max_chars, `truncated` when cut), pages: [{page_no, char_from, char_to}], status}`. A version with no text: `status` (`no_text`, `unsupported`, `failed`, `pending`) and `text: ""`. **As a share (`kernel`): the same shape for a document under a folder shared with the consumer**, `os.doccloud-text/1` | view; `kernel` as a share |
| `document_page` | One page of a paged document (a PDF page, a sheet, a slide) | D4 | `dc_document_page(version, page_no)` (owed, below; until then `mcp_document_text.pages` sliced in Python) | `document`, `page` (≥ 1), `version?` | `{document_id, version_id, page, page_count, text, char_from, char_to}` | view |
| `document_excerpt` | The words around a match: a quote with a little before and after, for citing a document in an answer | D4 | `dc_document_excerpt(version, quote, window)` (owed; until then Python over the text) | `document`, `quote` (≤ 500), `window?` (100–1,500, default 400 characters each side), `version?` | `{document_id, found, before, match, after, page_no?}`. Matching ignores case and whitespace runs | view |
| `duplicates` | The same bytes in two places | D5 | `dc_duplicates(limit)` | `limit` (≤ 1,000) | rows `{sha256, byte_size, copies, documents: [{document_id, name, folder_id}]}` by wasted bytes | the function: a curator sees all; anyone else what they may see |
| `locked_documents` | What is locked, by whom, since when | D6 | `dc_locked_documents()` | `library?`, `folder?`, `mine?`, `limit` | rows `{document_id, name, locked_by, locked_by_name, locked_at, lock_expires_at, folder_id}` | view (the function) |
| `trash` | What is in my trash, or a folder's; when each purges | D7 | `dc_trash(folder)` | `folder?`, `limit`, `offset` | rows `{kind, id, name, trashed_at, trashed_by, trashed_by_name, purge_at, from_folder_id}` | own, or `manage` on the folder (the function) |
| `trash_due` | What purges this week | D7 | `dc_trash_due(days)` | `days?` (7), `limit` | rows `{kind, id, name, purge_at, owner_member_id}` | as `trash` |
| `extraction_failures` | Documents with no text, failed or unsupported extraction | D8 | `dc_extraction_failures(limit)` | `status?` (no_text, unsupported, failed), `library?`, `limit` | rows `{document_id, name, version_id, status, error (≤ 300), uploaded_at}` | view (the function) |

### Access, shares and links (A1–A7)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `access_list` | Who can see folder or document X, at what level: the explicit rows at its access root (or its own), the library's base, the owner | A1 | `mcp_folder_access` at `folders.access_root_id` (or `mcp_document_access` when `own_access`), `mcp_libraries`, `mcp_groups` | `folder` or `document` (one of them) | `{thing: {kind, id, name}, owner: {member_id, display_name}, library: {library_id, kind, name, base: {principal, level}}, inherited_from: {folder_id, name}?, rows: [{access_id, principal_kind, principal_id, principal_ref, principal_name, level, granted_by, granted_by_name, created_at, is_guest, inherited}]}` | manage (the views answer nothing else → `managers_only`) |
| `access_explain` | **Why does member M see this** (or not): owner, library, a row, inherited from where | A1 | `dc_access_explain(document, folder, member)` | `document` or `folder`, `member?` (default the caller) | `{member: {member_id, display_name}, level, reasons: [{reason (owner, admin, library, folder, inherited, document), via_kind, via_id, via_name, level}]}`; `level: "none"` with no reasons when they see nothing | view of the thing for oneself; `manage` to ask about another person |
| `my_level` | What I may do here | A2 | `dc_document_level(id)` / `dc_folder_level(id)` | `document` or `folder` | `{level, can: {view, add, edit, manage}}` | anyone admitted (`none` is an answer) |
| `shared_with_me` | What was shared with me, by whom, when (explicit rows naming me, my groups or departments) | A3 | `dc_shared_with_me(limit, offset)` | `kind?` (folder, document), `limit`, `offset` | rows `{kind, id, name, level, shared_by, shared_by_name, shared_at, via}` | own |
| `my_shares` | What I shared out, to whom; which are to guests | A4 | `dc_my_shares()` | `guests_only?` (false), `limit` | rows `{kind, id, name, principal_kind, principal_id, principal_ref, principal_name, level, created_at, is_guest}` | own |
| `stale_guest_shares` | Shares to guests older than N days (the Clerk's "stale") | A4 | `dc_stale_guest_shares(days)` | `days?` (the setting, 90), `limit` | rows `{kind, id, name, guest_member_id, guest_name, level, created_at, granted_by}` | manage on each (the function) |
| `find_links` | The links on folder or document X, or mine: expiry, opens, uploads. `q` alone resolves a link by label (the registry's `link` resolver) | A5 | `dc_find_links(folder, document, kind, live_only)`; `mcp_share_links` for `q` | `folder?`, `document?`, `kind?` (view, request), `q?` (label ILIKE), `mine?`, `live_only?` (true), `limit` | rows `{link_id, label, kind, folder_id, document_id, allow_download, has_password, expires_at, view_count, upload_count, last_used_at, made_by, made_by_name, created_at, revoked_at, rotated_at}`. **Never a token or a hash** | the function: `links.all`, the maker, a manager of the thing |
| `links_expiring` | Links expiring within N days | A5 | `dc_links_expiring(days)` | `days?` (7), `limit` | rows as above | as `find_links` |
| `links_suspicious` | Links opened from many addresses this week | A5 | `dc_links_suspicious(addresses, days)` — wrapped by `links.all` (owed, below) | `addresses?` (10), `days?` (7), `limit` | rows `{link_id, label, addresses, opens}` | `links.all` |
| `find_requests` | Open file requests, into which folders, how many uploads. `q` alone resolves one by label (the registry's `request` resolver) | A6 | `mcp_share_links WHERE kind = 'request'` | `q?`, `folder?`, `mine?`, `live_only?` (true), `limit` | rows as `find_links` + `note` | as `find_links` |
| `link_opens` | When a link was opened, for what, from how many addresses | A5 | `mcp_link_opens` | `link`, `since?`, `limit` | rows `{link_open_id, opened_at, what, document_id, byte_size, address_key}` | the link's manager (the view) |
| `access_history` | Who could see X on a given day, from the trail of access changes | A7 | `dc_access_history(document, folder, at)` | `document` or `folder`, `at` (a date-time) | rows `{principal_kind, principal_id, principal_ref, principal_name, level, since}` + `{note: "explicit rows only; the library's membership on that day is the directory's"}` | manage, or `reports.search` |
| `access_changes` | The trail of access changes on X, newest first | A7 | `mcp_access_changes` | `document` or `folder`, `limit` | rows `{access_change_id, principal_kind, principal_id, principal_ref, principal_name, old_level, new_level, changed_by, changed_by_name, changed_at}` | manage, or `reports.search` |

### Tags and search (T1–T3, S1–S3)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `find_tags` | The tags: managed and free, counts, colours, aliases. `q` alone resolves a tag by slug, label or alias (the registry's `tag` resolver) | T1 | `mcp_tags`; `dc_tag_resolve(q)` first for an exact slug or alias | `q?`, `kind?` (managed, free), `include_archived?` (false), `sort?` (usage, label), `limit`, `offset` | rows `{tag_id, slug, label, kind, colour, usage_count, aliases, created_by, archived_at}` | anyone admitted |
| `tag_proposals` | Which free tags are proposed for the vocabulary, which merges, which applications | T1 | `mcp_tag_proposals` | `kind?` (promote, apply, merge), `status?` (proposed, accepted, dismissed; default proposed), `limit` | rows `{tag_proposal_id, kind, tag: {tag_id, slug, label}, into_tag?, document_id?, proposed_by, proposed_by_name, note, status, decided_by, decided_at, created_at}` | `tags.manage`; an editor of the document for an `apply` (the view) |
| `documents_by_tag` | Which documents carry tag T, in library L or under folder F | T2 | `mcp_document_tags` ⋈ `mcp_documents` | `tag` (slug or id), `library?`, `folder?`, `limit`, `offset` | rows as `find_documents`, `total` | view |
| `untagged` | Which documents under folder X have no tag | T3 | `dc_untagged(folder, limit)` | `folder?`, `library?`, `limit` | rows `{document_id, name, folder_id, owner_member_id, created_at}` | view (the function) |
| `search` | **Search by keyword** across everything I may see: names, tags, descriptions and the words of every document, with excerpts and filters. The search box. Not the graph (`graph_find`) | S1 | `dc_search(q, filters, limit, offset)`; `dc_search_names(q)` beside it when `q` looks like a name and few rows came back | `q` (0–200; empty lists by the filters), `library?`, `folder?`, `owner?`, `tag?`, `kind?`, `min_bytes?`, `max_bytes?`, `before?`, `after?`, `mine?`, `shared_with_me?`, `limit` (≤ 100), `offset` | `{rows: [{document_id, name, folder_id, library_id, owner_member_id, mime_kind, byte_size, tags, excerpt (with `<mark>`), occurred_at, rank}], total, name_matches: [{document_id, name, similarity}]}`. Logged `search.keyword` by PHP's door only; through MCP the tool writes a `search_log` row through the bridge (`kind: keyword`, `source: mcp`) unless `is_eval` | the function (per row) |
| `saved_searches` | My saved searches, and the shared ones. `q` alone resolves one by name (the registry's `saved_search` resolver) | S2 | `mcp_saved_searches` | `q?`, `mine?`, `limit` | rows `{saved_search_id, owner_id, owner_name, name, query, filters, kind, is_shared, updated_at}` | own, or shared (the view) |
| `run_saved_search` | Run one of them now | S2 | `mcp_saved_searches` → `search` or `graph_find` | `saved_search`, `limit`, `offset` | as `search` or `graph_find` | as the search |
| `search_gaps` | What people searched for this month that found nothing | S3 | `dc_search_gaps(since, limit)` | `since?` (30 days), `limit` | rows `{query, times, last_at}` | `reports.search` (the function) |

### The document graph (G1–G5) — all through the bridge; never the expert's default
| Tool | Call it when | Answers | Engine call | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `graph_find` | **Which documents are about X / near this phrase** — the graph find. Call it when the person asks for the graph, or when keyword search found nothing and they want more. Never to compose an answer from a document's words | G1 | bridge `graph_find` → `dc_graph_resolve_subjects(query)` + `dc_graph_candidates(subjects, query, 200)` → `dc_graph_filter(candidates, limit)` | `query` (2–400), `library?`, `folder?`, `tag?`, `kind?`, `limit` (≤ 50) | `{query, subjects_used: [{name, via}], rows: [{document_id, name, folder_id, library_id, owner_member_id, mime_kind, score, subjects, hits, excerpt?}], candidates_read, note?}` — `excerpt` is the best passage from DocCloud's own `document_text`, never the engine's. `graph_unavailable` when the engine is down (keyword search still works) | the wall (`dc_graph_filter`) |
| `graph_related` | Which documents are like document X: shared subjects, near passages | G2 | bridge `graph_related` → the document's subjects (`graph_documents.subjects`, or the engine's neighbours) → their documents → `dc_graph_filter` | `document`, `limit` (≤ 50) | `{document_id, subjects, rows: [{document_id, name, folder_id, score, subjects, hits}]}`; `not_in_graph` for an unindexed document | view of X; the wall for the rows |
| `document_subjects` | Which subjects document X names | G3 | `mcp_graph_documents.subjects`; bridge `document_subjects` for a `full` document's extracted subjects | `document` | `{document_id, policy, indexed_at, subjects: [{name, type?, via (known, extracted)}]}` | view |
| `graph_subjects` | The most connected subjects in the graph (a library's when given: its documents' subjects by count) | G3 | bridge `graph_subjects` → `dc_graph_god_nodes(limit)`; for a library, `mcp_graph_documents` grouped | `library?`, `limit` (≤ 50) | rows `{name, type?, degree, documents_visible}` — `documents_visible` counts only what the caller may see | the wall |
| `get_subject` | What subject Y is: its type, its documents (that I may see), its neighbours | G4 | bridge `subject` → `dc_graph_subject(id)` → `dc_graph_filter` over its documents | `subject` (id or name) | `{subject_id, name, type, documents: [...filtered rows], neighbours: [{name, rel}]}` | the wall |
| `subject_neighbors` | Y's neighbourhood | G4 | bridge `subject_neighbors` → `dc_graph_neighbors('subject', id)` | `subject`, `kind?` (subject, document), `limit` | `{subject, neighbours: [{kind, id, name, rel}]}` with document nodes filtered | the wall |
| `graph_path` | How Y and Z are connected | G4 | bridge `graph_path` → `dc_graph_path(a, b, depth)` | `from`, `to`, `max_depth?` (1–6, default 4) | `{found, steps: [{kind, id, name, rel}]}`; a document node the caller may not see ends the answer at `found: false` | the wall |
| `graph_queue` | What is queued, running, done, failed; the month's budget | G5 | `mcp_graph_jobs`, `dc_graph_budget_state()` | `status?`, `kind?`, `document?`, `limit`, `offset` | `{budget: {month, full_chars, budget_chars, pct, full_documents, index_documents}, jobs: [{graph_job_id, document_id, name, kind, chars, status, attempts, error, created_at, started_at, finished_at}]}` | `graph.manage` (the view) |
| `graph_estimate` | What a `full` policy would cost on folder X now | G5 | `dc_graph_estimate(folder)`; bridge `graph_estimate_check` for the engine's state | `folder` | `{documents, chars, calls, budget_left_chars, over_budget, engine_available}` | view of the folder (`graph.manage` to see `budget_left_chars`) |
| `graph_usage` | What the graph spent this month and the last ones | G5 | `mcp_graph_usage` | `months?` (1–24, default 3) | rows `{month, kind, calls, chars, documents}`. **Never dollars**: prices are the kernel's AI statement (K24) | `costs.read` |

### Agents, proposals and keys (P1–P3)
| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `clerk_proposals` | What the Clerk proposed, and what was accepted. `q` alone resolves a proposal by title (the registry's `proposal` resolver) | P1 | `mcp_clerk_proposals` | `kind?` (untagged, duplicate, stale_share, link_expiring, link_suspicious, request_open, trash_due, locked, no_text, quota, budget, orphaned, tags), `status?` (proposed, accepted, dismissed, expired; default proposed), `for?` (member), `q?`, `limit` | rows `{proposal_id, kind, document_id, folder_id, library_id, link_id, for_member_id, title, payload, proposed_by, status, decided_by, decided_at, created_at}` | own (for me, or by me); `groups.manage` all (the view) |
| `agent_dispatches` | Which dispatches are pending or failed | P2 | `mcp_agent_dispatches` | `status?`, `agent?`, `kind?` (untagged_batch, request_received, duplicates_found, ask, duty_proposal), `limit` | rows `{dispatch_id, record_type, record_id, agent_name, kind, via, status, reply_excerpt (≤ 300), detail, attempts, run_id, request_id, document_id, folder_id, created_at, answered_at}` | `agents.settings`; else the ones the caller caused or received (the view) |
| `find_keys` | My keys (or every key, admin): limits, last use, today's count. `q` alone resolves a key by label (the registry's `api_key` resolver) | P3 | `mcp_tokens WHERE scope = 'api'` | `q?`, `member?` (admin), `live?` (true), `limit` | rows `{key_id, member_id, label, rate_per_minute, rate_per_day, calls_today, last_used_at, expires_at, revoked_at, rotated_from, is_live, created_at}`. **Never a value or a hash** | own; admin all (the view) |
| `clerk_report` | The Monday store report in one read, for the Clerk alone: untagged documents by folder with the tags the graph suggests, duplicates, stale guest shares, links expiring and links opened from many addresses, open requests, the trash due, long locks, extraction failures, libraries near their quota, the month's graph budget, documents owned by inactive members — names, counts and ids, never a document's text | P1 | `dc_clerk_report()` (db/016, SECURITY DEFINER; answers only when `dc_is_clerk()`: the caller is `dc_settings.clerk_member_id`) | `week?` (default this week) | `{untagged: [{folder_id, folder_path, documents: [{document_id, name, suggested_tags}]}], duplicates, stale_guest_shares, links_expiring, links_suspicious, requests_open, trash_due, locked_long, extraction_failures, quota_near, budget, orphaned}` | the Clerk's member id only; anyone else gets `managers_only` |

### People, notes, mine
| Tool | Call it when | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|
| `find_members` | A person or an agent by name, to share with. `q` alone resolves one | `mcp_members` | `q?`, `kind?` (human, agent), `department?`, `group?`, `guests?`, `limit` | rows `{member_id, display_name, member_kind, is_agent, is_guest, job_title, roles, department_ids, group_ids}`. `email` only one's own | the view: insiders see the admitted, a guest the people who shared with them |
| `find_departments` | A department by name, as a principal | `mcp_departments` | `q?`, `include_archived?`, `limit` | rows `{department_id, name, description, parent_id, archived_at}` | insider |
| `find_notes` | The comments on a document or folder | `mcp_notes` | `document` or `folder`, `limit` | rows `{note_id, member_id, member_name, body, created_at}` | view |
| `my_favourites` | My favourites | `mcp_favourites` ⋈ names | `limit` | rows `{kind, id, name, created_at}` | own |
| `recent_documents` | What I opened or changed lately | `dc_recent_documents(limit)` | `limit` | rows `{document_id, name, folder_id, mime_kind, updated_at, last_opened_at}` | own |
| `my_notifications` | My notices, unread first, and my preferences | `mcp_notifications`, `mcp_notification_prefs` (owed, below) | `unread_only?`, `limit` | `{prefs, notifications: [{notification_id, kind, title, body, record_type, record_id, document_id, folder_id, read_at, created_at}]}` | own |
| `my_tokens` | My MCP tokens and keys: label, scope, last use | `mcp_tokens` | `scope?` | rows of the view. **Never a value or a hash** | own |
| `my_uploads` | My upload sessions in flight | `mcp_upload_sessions` | `status?`, `limit` | rows of the view | own |
| `get_settings` | The application's settings: ceilings, windows, link defaults, rates, the graph's budget and default policy; the model (admin) | `mcp_settings` | none | the row; `model_alias`, `embedding_model` null unless admin | anyone admitted |

### The long tail
`records_search`: one read-only `SELECT`/`WITH` over the `mcp_*` views as the asking member — the contract's guarded search: one
statement, no `;`, the keyword deny-list, `SET LOCAL statement_timeout = '5s'`, wrapped `SELECT * FROM (…) _q LIMIT 200`, run by
`db.run_search` under `doccloud_records_ro`. The docstring lists the 33 readable views (db/004 `mcp_app_roles` and the 32 of db/015 but
`mcp_activity_log`) and the read functions. `SEARCH_DENY` keeps `mcp_admit_agent`, `mcp_resolve_token`, `set_config`, `pg_`, and adds
`dc_link_resolve`, `dc_resolve_key` (writer-only anyway). **It is not S1**: the search tool is `search`.

## The shares, for the kernel's token (K7, design §8)

**Who is asking comes from the kernel (K26):** `X-OS-Consumer` (the consumer's catalog key) and `X-OS-Consumer-Agent` (its expert's
member id); anything the consumer put in the arguments as `consumer` or `as_agent` is stripped by the kernel. The gate reads the
headers into request-scoped variables (Phase 4) and passes `consumer` to the bridge; no header → `consumer_unknown`, nothing shared.
`document_text` declares `timeout_seconds: 60`; the others keep 8.

| Tool | Document | Arguments (flat from the kernel) | Answers |
|---|---|---|---|
| `folders_index` | `os.doccloud-folders/1` | `q?` | the folders whose access root carries an `application` row naming the consumer (`dc_application_folders(consumer)`, owed): `{schema, application, folders: [{folder_id, path_text, library: {library_id, kind, name}, level, document_count, subtree_document_count, updated_at}]}` |
| `documents_index` | `os.doccloud-documents/1` | `folder` (id), `since?` (a time), `limit?` (≤ 200), `offset?` | the live documents of that shared folder's subtree (`dc_application_documents(consumer, folder, since, limit, offset)`, owed): `{schema, application, folder_id, documents: [{document_id, name, path_text, mime_type, mime_kind, byte_size, sha256, tags, owner_name, version_no, version_id, extracted_status, updated_at}], total}` — a folder not shared with the consumer answers `{"error": {"code": "not_shared"}}`, the same words as a missing folder |
| `document_text` | `os.doccloud-text/1` | `document` (id), `max_chars?` | the extracted text with its pages, as the records tool answers it, for a document the consumer may see (`dc_application_can_see_document`); ≤ 5 MB |
| `document_link` | `os.doccloud-link/1` | `document` (id), `disposition?` (attachment, inline) | `{schema, application, document_id, name, mime_type, byte_size, url, expires_at}` — a **signed URL** `https://doccloud.<domain>/d/{id}?x={exp}.{hmac}` good for 10 minutes, minted by PHP with `ACTIONS_RELAY_KEY` (`dc-link:{document_id}.{version_id}.{exp}.{consumer}`); the open is logged `document.download` with `source = application` under the consumer. Bytes never cross K7's JSON |

- **No member identity crosses**, in either direction: the answers name documents and folders, never a person beyond an owner's
  display name. **Logged:** `share.read` (source `application`, `after = {application, tool, folder_id or document_id, outcome, ms}`)
  on every call.
- The kernel refuses an answer over 256 kB and waits the declared timeout; `documents_index` pages, `document_text` cuts at 5 MB and
  says `truncated`.

## The token API (bearer = a key; `/api/v1/` on the vhost's allow-list; design §8)
A key acts as its person: `api_authenticate()` resolves it (`dc_resolve_key`), sets `app.member_id`, counts it (`dc_rate_ok` → 429
with `Retry-After`), and every route reads the same views and functions as the tools above: `GET /api/v1/folders/{id}` =
`folder_listing`; `GET /api/v1/documents/{id}` = `get_document`, `/download` = the gate, `/text` = `document_text`; `POST
/api/v1/search` = `search`; `POST /api/v1/graph/find` = `graph_find`; `POST /api/v1/upload` and `PUT /api/v1/documents/{id}/tags`,
`POST /api/v1/links` are the actions `document_upload`, `tag_apply`, `link_make` under the key's person (an agent's key pauses like
the agent). `GET /api/v1/health`. Logged with source `api` and `token_id`.

## Activity server: `doccloud_activity_mcp`

| Tool | Call it when | Answers | Reads | Params | Returns | Gate |
|---|---|---|---|---|---|---|
| `record_history` | Who did what to a document, a folder, a library, a group, a tag, a link, in order | ACT1 | `mcp_activity_log` | `entity_type` (document, folder, library, group, tag, link, request, version, access, key, proposal, dispatch), `entity_id`, `limit` (≤ 200) | rows `{activity_id, occurred_at, actor_member_id, actor_name, actor_is_agent, source, action, before, after, request_id, agent_run_id}` oldest first | the view: rows about what the caller may see, their own, the admin all |
| `actor_timeline` | What person, key, link or agent X did here since a time | ACT2 | `mcp_activity_log` | `member?` or `key?` or `link?` (one), `since?` (7 days), `library?`, `limit` | rows as above + `library_id, document_id, folder_id`, newest first | own trail: anyone; another's: `reports.search` or the admin (else only rows on things the caller manages, the view) |
| `recent_activity` | What changed in library L or folder F since I last looked | ACT3 | `mcp_activity_log` | `since` (required), `library?`, `folder?` (its subtree), `exclude_mine?` (true), `limit` | rows, newest first | the view |
| `downloads` | Who downloaded document X, and when (the gate's log, links and shares included) | ACT4 | `mcp_activity_log WHERE action IN ('document.download', 'document.open')` | `document`, `since?`, `limit` | rows `{occurred_at, actor_name or link_label or application, source, what}` | manage on the document (the view answers the rest; the tool says `managers_only`) |
| `share_reads` | Which siblings read us, how often, with what outcome | P4 | `mcp_activity_log WHERE action = 'share.read'` | `since?` (30 days), `application?`, `group?` (true) | grouped `{application, tool, calls, refused, last_at}` or rows | admin |
| `activity_search` | The trail by a phrase in actions, names and labels | ACT5 | `mcp_activity_log` (`action`, `entity_type`, `after->>'name'`, `->>'label'`, `->>'title'`) | `q` (2–200), `since?`, `library?`, `limit` | rows | the view |
| `activity_query` | The contract's guarded long-tail search over `mcp_activity_log` and the six name views | — | as listed | `sql` | ≤ 200 rows, 5 s | the view |

### The activity vocabulary (design §6.4): what the log must carry for these tools
Every row: `actor_member_id` (null for a link holder's or a share's), `source` (`web`, `agent`, `mcp`, `api`, `application`, `cron`,
`portal` for a link holder), `action` (`entity.verb`), `entity_type` + `entity_id`, **`library_id`, `document_id` and `folder_id`
whenever the event concerns one** (the visibility test reads them), `token_id` for a key's call. **Never a document's text, a passage, a
query over 120 characters, a token, a link, a password, a storage path.**
- `library.create|rename|archive|quota_set|policy_set|admin_view`: `after.name`, `kind`, `quota_bytes`, `graph_policy`, `everyone_level`.
- `group.create|update|archive|delete|member_add|member_remove|owner_set`: `group_id`, `key`, `name`, `kind`, `member_id`, `role`.
- `folder.create|rename|update|auto_tags|move|trash|restore|delete|export`: `folder_id`, `name`, `parent_id` (before/after on a move),
  `count` (a subtree). **Sharing is one entity, `access`** (the manifest's divergence from design §6.4): `access.share|share_guest|share_application|level_set|unshare|restrict`
  with `principal_kind`, `principal_id` or `principal_ref`, `level`, and `folder_id` or `document_id` on the row; the guest and application events pause.
- `document.upload|version|rename|move|copy|describe|open|download|preview|export|lock|unlock|lock_break|trash|restore|purge|transfer`:
  `document_id`, `name`, `version_no`, `byte_size`, `mime_type`, `sha256` (the hash is not a secret), `folder_id`, `owner_member_id`,
  `source` (web, api, agent, request, restore, copy), `link_id` (an open through a link), `application` (through a share).
- `version.restore|purge`: `version_id`, `version_no`, `document_id`. `upload.start|finish|fail|expire`: `upload_session_id`, `parts`.
- `tag.create|apply|remove|merge|propose|accept|dismiss|delete`: `tag_id`, `slug`, `document_id`, `into_tag_id`, `source`.
- `link.make|rotate|revoke|open|refused|expire`: `link_id`, `label`, `kind`, `folder_id` or `document_id`, `expires_at`, `what`,
  `address_key` (8 characters of the hash). `request.make|close|upload`: `link_id`, `label`, `document_id`, `byte_size`.
- `search.keyword|graph|name|save`: `query_excerpt` (≤ 120), `results`, `took_ms`, `kind`; `eval: true` under an eval run.
- `graph.queue|index|full|forget|fail|policy_set|budget_set|model_set|rebuild`: `graph_job_id`, `document_id`, `kind`, `chars`, `maludb_document_id`,
  `error_code`, `graph_policy`, `graph_monthly_text_mb`, `model_alias` (never a credential).
- `clerk.propose|accept|dismiss`: `proposal_id`, `kind`, `title`. `agent.dispatch|reply|fail`: `dispatch_id`, `agent_member_id`, `kind`, `via`, `run_id`, `status`.
- `settings.save`, `retention.set`, `quota.set`, `agents_settings.save`: the fields changed. `share.read` (the shares). `key.mint|rotate|revoke|rate_limited`:
  `key_id`, `label`, `rate_per_minute`, `rate_per_day`, `limit_hit`. `token.mint|revoke`. `notification.send|read`. `note.add|delete`.
  `favourite.add|remove`. `saved_search.save|delete`. `export.request|done|fail|expire`. `member.sign_on`, `directory.sync`, `screen.view`.

## Entity resolution for the action tools (the registry's `resolve` block, `deploy/kernel-registry-doccloud.json`)
Each tool answers the resolver's plain list when called with `q` alone: digits are an id, otherwise ILIKE with at most 6 candidates.

| Param(s) | Tool (with `q`) | id field | label |
|---|---|---|---|
| `document`, `file` | `find_documents` | `document_id` | `name` (folder path) |
| `folder`, `parent`, `target_folder`, `to_folder` | `find_folders` | `folder_id` | `path_text` |
| `library` | `find_libraries` | `library_id` | `name` (kind) |
| `group` | `find_groups` | `group_id` | `name` (key) |
| `tag` | `find_tags` | `tag_id` | `label` (slug) |
| `link` | `find_links` | `link_id` | `label` |
| `request` | `find_requests` | `link_id` | `label` |
| `saved_search` | `saved_searches` | `saved_search_id` | `name` |
| `proposal` | `clerk_proposals` | `proposal_id` | `title` |
| `api_key` | `find_keys` | `key_id` | `label` |
| `member`, `person`, `owner`, `agent`, `guest`, `expert`, `clerk`, `new_owner` | `find_members` | `member_id` | `display_name` |
| `department` | `find_departments` | `department_id` | `name` |
| `version`, `access` (an access row), `upload_session`, `dispatch`, `notification`, `token`, `note`, `graph_job`, `subject`, `tag_proposal` | **ids only**, never resolved by name (read from `document_versions`, `access_list`, `my_uploads`, `agent_dispatches`, `my_notifications`, `my_tokens`, `find_notes`, `graph_queue`, `get_subject`, `tag_proposals`) | | |

`find_links`' label is `label`, not `note` as the manifest fork's first draft of `deploy/kernel-registry-doccloud.json` said; the
registry file is corrected in this Phase (`label_field: "label"` for `link` and `request`, `folder` keeps `path_text`).

## Coverage

**Design §7 → tools**
| # | Tools |
|---|---|
| L1 `find_libraries`, `get_library` · L2 `folder_listing`, `get_folder`, `find_folders` · L3 `folder_path` · L4 `find_groups`, `get_group`, `group_members`, `my_groups` · L5 `storage_by_library`, `storage_by_owner`, `quota_state` |
| D1 `get_document` · D2 `find_documents` · D3 `document_versions` · D4 `document_text`, `document_page`, `document_excerpt` · D5 `duplicates` · D6 `locked_documents` · D7 `trash`, `trash_due` · D8 `extraction_failures` |
| A1 `access_list`, `access_explain` · A2 `my_level` · A3 `shared_with_me` · A4 `my_shares`, `stale_guest_shares` · A5 `find_links`, `links_expiring`, `links_suspicious`, `link_opens` · A6 `find_requests` · A7 `access_history`, `access_changes` |
| T1 `find_tags`, `tag_proposals` · T2 `documents_by_tag` · T3 `untagged` · S1 `search` · S2 `saved_searches`, `run_saved_search` · S3 `search_gaps` |
| G1 `graph_find` · G2 `graph_related` · G3 `document_subjects`, `graph_subjects` · G4 `get_subject`, `subject_neighbors`, `graph_path` · G5 `graph_queue`, `graph_estimate`, `graph_usage` |
| P1 `clerk_proposals` · P2 `agent_dispatches` · P3 `find_keys` · P4 `share_reads` (activity) |
| ACT1 `record_history` · ACT2 `actor_timeline` · ACT3 `recent_activity` · ACT4 `downloads` · ACT5 `activity_search` |

**Agents' grants** (`maludb-os.json`): every records tool above but `graph_queue`, `graph_usage`, `storage_by_owner`, `search_gaps`,
`links_suspicious`, `access_history`, `access_changes`, `agent_dispatches`, `my_uploads`, `records_search` is in the expert's list; the
Clerk's list is the report tools (`untagged`, `duplicates`, `stale_guest_shares`, `links_expiring`, `links_suspicious`, `find_requests`,
`trash_due`, `locked_documents`, `extraction_failures`, `quota_state`, `storage_by_library`, `graph_usage`, `graph_queue`), the reads it
needs to name things (`find_libraries`, `get_library`, `folder_listing`, `get_document`, `find_documents`, `find_tags`, `tag_proposals`,
`clerk_proposals`, `graph_find`, `graph_subjects`, `document_subjects`, `find_members`, `find_groups`) and `record_history`,
`recent_activity`. Phase 4 reconciles the manifest's `tool_grants` to these names (the manifest fork wrote them from the design; the
differences are noted at the end).

## What the schema lacked that a tool needs — built in db/016 (Phase 1, the lead)
Written by the surface's first draft and answered the same day by `db/016_phase1_reads.sql` (additive): **`dc_folder_path_text(folder_id)`**
(the `folder` resolver's label and `path_text`), **`dc_document_page(version_id, page_no)`** and **`dc_document_excerpt(version_id, quote,
window)`** (a page's text and a quote's surroundings cut in SQL), **`mcp_notification_prefs`** (own row), **`dc_links_suspicious()`** gated on
`links.all` in SQL, **`dc_application_folders(app_key)`** and **`dc_application_documents(app_key, folder_id, since, limit, offset)`** (the
shares' reads as the application principal — SECURITY DEFINER, the writer's), **`mcp_documents.owner_name`** and **`mcp_folders.document_count`**
(appended LAST), **`dc_graph_filter(candidates, limit, filters)`** (the wall with the find's filters inside its one statement — the two-argument
form replaced), **`dc_graph_job_skip(job, reason)`** (the `no_edges` case), `dc_graph_subjects()` prepending `doc:<id>`, the catch-up skipping a
document whose last job failed within a day; and, for slice 8, `dc_settings`' clerk columns, **`dc_is_clerk()`** and **`dc_clerk_report()`**.
Still through the bridge with no schema change: `search_log` (the read role cannot write it; the tool posts `log_search` to the bridge, which
inserts as the writer).

## Size
Records: **73 tools** — the 68 rows of the tables above (document_text among them, which is also the fourth share), `app_roles`, `records_search`, and the three
shares that answer only as an application principal (`folders_index`, `documents_index`, `document_link`); 10 of them call the engine through the bridge. (Phase 1's
count of 71 was a miscount of these same rows; Phase 4 counts the built tools: 73.) Activity: **7 tools**. The resolve block names 12 entities. Four share documents,
eight token-API routes. Phase 4 builds them as `mcp/records_server.py` with `dc_libraries`, `dc_documents`, `dc_access`, `dc_tags_search`, `dc_graph`, `dc_people`,
`dc_shares` (each `register(mcp, q, bridge)`; `dc_common` holds the shared pieces and the bridge client) plus `mcp/activity_server.py`.

## Open questions
(none)

## Phase 4 — built (2026-10-10, a Sonnet 5.5 worker; proof `tests/phase4/run.sh`)
The two servers, the bridge and the proof. **Records 73 tools, Activity 7**; every tool is a `FastMCP` tool with one `params` model (`extra="forbid"`, every field described —
`dc_common.describe_models` fills a field with no words of its own), `readOnlyHint`, `openWorldHint: false`. Files: `mcp/records_server.py`, `mcp/activity_server.py`,
`mcp/dc_common.py` (annotations, bases, `Where`, `Refusal`/`answers`, the id-or-name resolvers, `qmatch`, the bridge client), `dc_libraries.py`, `dc_documents.py`,
`dc_access.py`, `dc_tags_search.py`, `dc_graph.py`, `dc_people.py`, `dc_shares.py`; the kit changed in `db.py` (`request_consumer`, `request_consumer_agent`,
`APP_INTERNAL_PORT`) and `server_common.py` (`SHARES`, `KERNEL_TOOLS`, `KERNEL_ONLY`, the K26 headers, `run_context()`); PHP: `html/internal/bridge.php`,
`app/features/bridge/ops.php`; `deploy/apache-doccloud.conf` (the public vhost answers 404 for `/internal/`).

**Decisions taken** (the surface and the code could not settle them; none changes a wall):
1. *Counts.* 73, not 71 (above). An unexpected database failure answers `{"ok": false, "error": {"code": "server_error", "retry": true}}` — one code added to the table.
2. *Lists.* A list tool answers a plain array of rows; where the surface names a total (`folder_listing`, `find_documents`, `documents_by_tag`, `search`, `graph_queue`) it answers an
   object. With `q` alone every `find_*` / `saved_searches` / `clerk_proposals` tool answers the resolver's plain list, and **digits are an id** (or a name made of digits).
3. *Libraries.* `find_libraries` reads `mcp_libraries` (a superset of `dc_my_libraries()`: it also lists a library one reaches only through a folder shared inside it, `my_level: none`).
4. *Folders.* `find_folders` rows carry both `path` and `path_text` (the registry's `folder` resolver labels by `path`; the surface says `path_text`). `folder_ref` takes an id, a
   name, or a `/a/b/c` (or `a › b`) path and answers `ambiguous` with up to six candidates.
5. *Excerpts.* `document_excerpt` is cut in Python from `mcp_document_text` — `dc_document_excerpt()` matched only the exact or lower-cased string, and the surface promises case and
   whitespace runs are ignored; the window is characters each side. Still DocCloud's own text, never the engine's, and still behind the same view.
6. *Duplicates.* `dc_duplicates()` names every copy to a curator (`reports.storage`) whether or not they may see it. The tool keeps only the copies `mcp_documents` shows the asker and
   leaves a group with fewer than two; `copies` is the visible count.
7. *Evaluation runs.* The servers never refuse a read under `is_eval`; the tools that leave a trail (`search`, `run_saved_search`, `graph_find`) always call the bridge, and the bridge,
   told `is_eval`, writes **no `search_log` row** and marks the activity line `after.eval = true`.
8. *The bridge.* `POST /internal/bridge.php` with the header and envelope of "The bridge" above. Ops built: `graph_find`, `graph_related`, `document_subjects`, `graph_subjects`,
   `subject`, `subject_neighbors`, `graph_path`, `graph_estimate_check`, `admin_view` (admin only; logged once per library per admin per hour), `log_search` (the surface's
   "`search_log` writes"), and the shares `share_folders|share_documents|share_text|share_link` (`document_link` is accepted as the last one's other name). A member caller must be an
   admitted mirror row (403 `unknown_member`); an application caller is honoured for the share ops only (403 `forbidden` otherwise), and a member is refused them. The tool
   `document_subjects` reads `mcp_graph_documents` itself (the extracted subjects are already stored there); its bridge op exists for the surface's list. `graph_path` answers
   `found: false` with no steps when any step is a document the asker may not see; `get_subject` never reports a count of hidden documents.
9. *The shares.* `document_text` is both a person's tool and the fourth share: for the kernel's token it posts to the bridge as the consumer the kernel's headers name, with the
   kernel's flat arguments wrapped into `params` (`server_common` does that for every tool but `app_roles`) and a `_Flat` model that ignores what the kernel adds. The three
   kernel-only shares are listed and callable only with the kernel's token. `slice 7`'s `/api/v1/shares/read.php` stays (the same functions) but no tool uses it.
10. *`clerk_report`.* Answers `{<part>: {count, ids, detail}}` for the twelve parts of `dc_clerk_report()` (`untagged`, `duplicates`, `stale_guest_shares`, `links_expiring`,
    `links_suspicious`, `requests_open`, `trash_due`, `locked_long`, `extraction_failures`, `quota_near`, `budget`, `orphaned`) plus `untagged.folders` with names where the
    Clerk may see the document. `suggested_tags` is not in it (it needs the graph; the Clerk calls `document_subjects` / `graph_subjects`). The function lets the admin read it too; so does the tool.
11. *Access questions.* `access_list` / `access_explain` (about another person) / `access_history` / `access_changes` / `downloads` answer `managers_only` to one who sees the thing and
    `not_found` to one who does not.

**Defects found and fixed while building:** none in the schema. `deploy/apache-doccloud.conf` had no rule for `/internal/` (it would have been served from the public name as a plain file);
the bridge refuses a non-loopback caller and a bad signature anyway.

**Mismatches between the manifest and this surface — reported, not changed:** the expert's `tool_grants` include `records_search` (the "Agents' grants" paragraph says it is
excluded) and `links_suspicious` (it answers `managers_only` unless the asker holds `links.all`); the Filing Clerk's grants do not include `clerk_report`, the tool made for it.

**Phase 5 (the owner's) must know:** the vhost template changed (apply re-renders it); `APP_INTERNAL_PORT`, `ACTIONS_RELAY_KEY` and `OS_APPLICATION_TOKEN` are in `config/.env` (the
manifest lists them) and the units read it; `mcp/venv` needs `pip install -r mcp/requirements.txt` (the installer does it); both services start the files named in `deploy/`.

### Phase 4 — the grant mismatches, decided by the planning model (2026-10-10)
The worker reported two mismatches between `maludb-os.json`'s agent grants and this surface and changed nothing. Decided the surface's way:
the expert's grants lose `records_search` (the surface excludes the guarded catch-all search from the expert; it has `search` and the named
tools) and `links_suspicious` (an admin's report: `managers_only` for anyone without `links.all`); the Clerk gains `clerk_report`, the tool
built for it. The `dc_duplicates()` SQL function still names every copy to a holder of `reports.storage` — the curator's storage report by
design (§6.4 of the design: the curator's reports carry names and counts across the store, never content); the MCP tool filters by the wall.
