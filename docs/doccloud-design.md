# DocCloud — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-09 · the business's **document cloud** — what Dropbox, Box and Nextcloud are to a business: **libraries** of **folders** and
**documents** with **versions**, **tags**, sharing to people, **groups**, departments, agents and the outside (secure links and
file requests), a **keyword search** over the words of every document, and — kept apart from everything the agents remember —
a **dedicated knowledge graph for document search** in a MaluDB memory database of its own. The owner's brief (2026-10-09): a
system like Dropbox, Box or Nextcloud, built with `htmx-php-builder` and the OS application guidelines; because it will hold a
very large number of documents, **document search by graph or vector is not part of the default ask-me-anything** — it is a
dedicated graph; tags, keyword search and **visibility by group membership and ownership** are required. Its own repository
`/srv/apps/doccloud` (origin `github.com/maludb/maludb-os-doccloud`, private), served as `doccloud.<domain>`, fitted to
`maludb-os-integration` 0.8.0, exactly as HR, Projects, Help Desk, Spaces, Knowledge and Inventory were.
`/srv/apps/knowledge/docs/knowledge-design.md` is the nearest plan (files in, a memory database of its own); `/srv/apps/spaces`
(sharing by principal with inheritance, `search_index`, the worker) and `/srv/apps/consultant_tracking` (the kit) are the
nearest built exemplars.

> **What this document is.** The plan, in the kernel's words, and the brief a worker model builds from. Phase 0's first half is
> this document and the owner's answers (§13, recorded in §15); the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and
> `deploy/` complete it. Phase 1 adds `docs/doccloud-mcp-tool-surface.md`, `docs/doccloud-action-manifest.md`,
> `docs/build-specs/uploads-and-versions.md`, `docs/build-specs/visibility.md`, `docs/build-specs/document-graph.md` and the
> slice specs. **No feature PHP is written until the owner approves them together.**

## 0. The research — what the field does, and what the estate and MaluDB give today

### 0.1 The field

**Dropbox** — a tree of folders under a personal root, *team folders* owned by the team, "shared with me", shared links with an
expiry (paid plans; recipients never see the date), *file requests* (an upload-only link into a folder of mine), version
history with a retention window, a trash with a window, selective sync, Dash (search across tools). *Kept:* the personal root
and the team folder as the two kinds of library, "shared with me" as a view, links with an expiry, file requests, versions and
the trash as windows a setting controls. *Dropped:* sync clients (the desktop companion is deferred in the kernel; WebDAV is
Extended), Dash.

**Box** — content is the record: a folder's *collaborators* each hold a role — viewer, previewer, uploader, previewer-uploader,
viewer-uploader, editor, co-owner, owner — and a role **waterfalls** down the folder tree; groups collaborate like people;
metadata templates and cascade policies; Box AI asks a folder. *Kept:* collaboration by role on a folder, inherited downward,
groups as first-class principals, co-ownership. *Changed:* four levels instead of eight (§3), and an **uploader sees their own
documents** — that is the ownership rule the owner asked for, in Box's own vocabulary. *Dropped:* metadata templates (Extended),
asking a folder (Knowledge's job, §0.3).

**Nextcloud** — user-centric sharing (share at will, recipients see it in their home) plus *group folders* with ACLs per
sub-folder (read-only above, write below, read-only again deeper — "inherit" the default, a rule removed returns to the parent's),
versions expired on a thinning schedule and never over half the free space, the full-text-search framework (providers extract
content, one platform — Elasticsearch or Solr — indexes it; permission-aware results), tags (system tags by admins, personal
tags), WebDAV for every client. *Kept:* group folders with per-folder rules and inheritance by default, managed and personal
tags, the versions schedule as a setting, the full-text pattern — but on PostgreSQL (§0.2), not a second server. *Extended:*
WebDAV with a per-person token as the password.

**Google Drive, SharePoint** — "shared drives" owned by the team not a person (so a leaver's files stay); Drive's "visible to
anyone with the link" was the cause of most leaks and is restricted by default now. *Kept:* a group library survives its
members; a link is a deliberate, logged, expiring act that **pauses for an agent**.

### 0.2 What the estate gives (the survey of the siblings' `db/*.sql`, 2026-10-09)

| Need | The estate has | Used here as |
|---|---|---|
| The directory, sign-on, roles, tokens, the activity log | the kit (Consultant Tracking `db/001`–`004`, Spaces `001`): `members` (+ `is_agent`, `is_external`, `roles text[]`), `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state`, `<x>_rights/_roles/_role_rights`, `mcp_access_tokens`, `activity_log`, `activity_ingest_state` | **reuse** verbatim |
| Sharing by principal with inheritance | Spaces `page_permissions (principal_kind member\|department\|agent\|guest\|everyone_in_space\|everyone, principal_id, level)` + `pages.permission_root_id` kept by trigger — the resolver walks nothing at read time; additive sharing (the first explicit row carries the inherited set along); `sp_visible_page_ids()` once per statement | the shape of **`folder_access`** and `folders.access_root_id` (§3) — with `group` as a principal kind, and **without** a per-statement set of every visible document (§3, the scale rule) |
| Keyword search | **Spaces `search_index`** (catalogued canonical): `tsv` weighted A title / B body (first 200,000 characters), `simple` + `unaccent`, GIN, `websearch_to_tsquery`, `ts_headline`, visibility by a join, `count(*) OVER ()`; `pg_trgm` on names everywhere | **reuse** `search_index` + append the document columns; the same weighting and query functions as `dc_` |
| Files on disk | GL `attachments` (canonical) and Spaces' copy (`record_uuid`, `width`, `height`, `thumbnail_path`): `storage/attachments/<record_type>/<id>/<sha12>-<name>`, `finfo`-decided mime, an allow-list, a 25 MB ceiling that env may only lower, the view hides `storage_path` | **not reused**: a DocCloud document *is* the record, not a file on one — `documents` / `document_versions` / `blobs` are **new canonical** ("the estate's file store", §6.4); the store's rules (finfo, the refused kinds, `storage_path` hidden) are copied |
| Secure links for outsiders | GL `invoice_links_secure` = Help Desk `ticket_links_secure` = Inventory `order_links_secure`: a 48-hex token, only its sha256 kept, `expires_at`, `rotated_at`, `view_count`; Spaces `page_publications` (`noindex`, revoked) | **reuse** the shape as `share_links` (+ `folder_id`/`document_id`, `kind`, `allow_download`, `password_hash`) |
| Tags | Help Desk `tags (slug, label, usage_count)` + `ticket_tags`; Projects `labels` per project | **reuse** `tags` + append (`kind`, `colour`, `created_by`); `document_tags` **new canonical** (the shape of `ticket_tags`) |
| Groups | **nothing** — departments are the only group the estate knows; Spaces and Knowledge name them as principals | **`groups` / `group_members` are new canonical** (§3): a hand-made set of members and agents, or a mirror of a department |
| Notes, notifications, dispatches, settings, exports, worker passes | GL `notes`; Spaces' member-only `notifications`/`_prefs`/`_outbox`; GL `agent_dispatches`; the `app_settings` skeleton; Spaces `exports` (+ `params`), `worker_passes` | **reuse** |
| Saved searches | Projects `saved_filters` (per project), Help Desk `saved_views` (per queue) | `saved_searches` **new** (bound to nothing or a library; recorded against both) |
| Text extraction | Knowledge `app/knowledge/extract/` (3,196 lines: PDF per page through `pdftotext`, DOCX/PPTX/XLSX from the zip, HTML read, Markdown, text, CSV, JSON; deterministic, never a model) | **copied as code** into `app/doccloud/extract/` (code is shared by copying; schema by the canonical table) |
| A memory database of the application's own | Knowledge: `<tenant>_knowledge_memory`, its own role and token, made by `deploy/ROOT_STEPS.sh`; the token read in one file; `engine_unavailable` never a crash; K24 meters the model calls | **the same pattern**: `<tenant>_doccloud_memory` (§6.3) |

Free prefixes: `dc_` (taken: `hr_ projects_ hd_ sp_ kn_ inv_ gl_ ct_ ts_`). Ports after ProcessCore's 8189/8839/8840: **8190 /
8841 / 8842**, scratch 8701–8707. The kernel's category check already admits **`storage`** (db/175), so the catalog row (K31)
widens nothing.

### 0.3 What MaluDB gives today (`maludb_core` 0.106.0; the API 0.3.1 live, 0.4.0 on `feat/memory-answer-m1`) — read 2026-10-09

| Need | MaluDB | Consequence here |
|---|---|---|
| Keyword / full-text search over document content | **none**: `GET /v1/documents?q=` is a title `ILIKE`; the core's `text_search()` covers claims, facts, memories and episodes, not documents | **keyword search is PostgreSQL's** (`search_index`, §6.2) — free, deterministic, filtered by visibility in SQL, no model, no second server |
| Text in, graph out | `POST /v1/memory/documents` (title, text, `namespace`, `document_type`, `metadata{}`, `projects[]`, `subjects[]`, `chunk{max 2000, overlap 200}`): one model call per chunk extracting subjects, verbs and statements with verbatim spans; one embedding call per span; **synchronous, one HTTP request, no queue**; 422 when nothing is extracted | the **`full`** graph policy (§6.3): DocCloud's own worker queues documents one at a time under a monthly text budget, through the kernel's ledger proxy (K24) |
| Graph without a model | `POST /v1/memory/remember` (text, `subjects[]` ≤ 8, `verb`, `namespace`, `metadata`, `projects[]`): **no model call**; one edge per subject × chunk, the chunk embedded | the **`index`** graph policy, the default: every document enters the graph under its *known* subjects — its tags, folder, library, owner, kind, year, the sibling record it came from — for nothing but an embedding |
| Finding by meaning | `POST /v1/memory/search` needs a `subject` or `verb` (exact vector scan per compartment, limit ≤ 200); `/v1/memory/recall` proposes subjects from the query by trigram (limit ≤ 50); `GET /v1/graph/query` matches terms against subject names and walks | **`dc_graph_find()`** (§6.3): resolve the query's subjects → the documents on their edges and the passages near the query → **filtered by visibility in SQL** → ranked; the explorer over `neighbors`, `walk`, `path`, `god-nodes`, `surprises` |
| Filtering by what the caller may see | **no search route filters on metadata or tags**; a document has ONE scope (its namespace) and principals are granted per scope; `GET /v1/documents` filters on a column allow-list only; `GET /v1/documents/{id}` returns no metadata | visibility is **never delegated to the engine**: every engine result is a document id mapped to DocCloud's row and judged by `dc_can_see_document()`; results are over-fetched (×3, to the route's cap) and cut after the filter. **M10 owed**: a `document_ids[]` (or metadata) pre-filter on `search`/`recall` |
| Many memory databases per tenant | yes — a PostgreSQL database with `maludb_core`, its own schema, role and API token (Knowledge does this) | `<tenant>_doccloud_memory` — document content never enters the tenant's agent memory (the owner's rule); activity episodes still go to the tenant's one memory, tagged `doccloud` |
| Forgetting | `DELETE /v1/documents/{id}` forgets the document, its chunks and edges | a purged document is forgotten within the minute |
| Answering in words | M1 `POST /v1/memory/answer` (branch; verified quotes) | **not in DocCloud's default AMA** (the owner): the expert answers about the store, not from the documents. A cited answer over a folder is Knowledge's: a folder is offered to Knowledge as a feed through K7 (§8). An "ask this document" door through M1 is Extended |
| What Knowledge found | M3 (graph routes tenant-wide / name-prefix namespaces), M5 (no dry run), M6 (no community detector), M7 (`MAX+1` ids on `/v1/subjects` and `/v1/projects`, no aliases route), `remember`'s cap of eight subjects, no usage on ingest, `documents` route takes no hints | inherited: the graph explorer is filtered to the application's own documents by `document_type = 'dc'`; subjects are never created through `/v1/subjects` (a `remember` with the subject names creates them); K24 meters |

What the application adds that MaluDB does not have: files, versions and blobs; folders, libraries, groups and the visibility
rules; keyword search; tags; links and file requests; the trash, locks and quotas; the queue, the budget and the policy that
decide what enters the graph; the mapping of every engine result back to a document the caller may see; the K7 shares; the two
agents. An application never holds a model key: MaluDB holds the provider credential, the kernel meters (K24).

## 1. What DocCloud is, and is not

DocCloud **owns the business's files** — every document a person or an agent keeps, where it is, who may see it, what it is
about, and every version of it — and **how they are found**: by browsing, by tag, by keyword, and by the document graph.

- **A document is the record.** It has an owner, a folder, a library, a name, a type, a size, a sha256, versions, tags, a
  description, locks, shares, links, a trail. Its bytes are a **blob** (content-addressed, de-duplicated); its words are
  extracted deterministically into **text** the keyword index and the graph read.
- **Two kinds of search, deliberately apart.** *Keyword* — PostgreSQL full text over title, tags, description and text, every
  result judged by visibility in the same statement, free and instant, the default of the search box. *The document graph* — a
  MaluDB memory database of DocCloud's own, asked "which documents are about X, near Y, like this one"; a dedicated screen,
  a dedicated tool, never the agents' memory, **never the default ask-me-anything** (the owner).
- **Visibility is ownership plus membership.** A document's owner always sees it; a library's members see what the library
  gives them; a folder's or a document's explicit access rows add to that; a guest sees only what names them; a link holder sees
  one thing. The rules are §3 and they are enforced in SQL, once, for every screen, tool, share, link and export.
- **Groups are first-class**, the estate's first: a named set of members and agents, hand-made or mirroring a department, with
  owners; a group library is theirs and survives them.
- **Nothing of identity lives here.** The kernel signs people in; a guest is a kernel external; an outside system holds a key; a
  link is a token.
- **Full MCP coverage** is a requirement (`maludb-os-integration`, `mcp-and-api.md`).
- **The easy path is three steps**: open *My files*, drop files in (many at once, folders too), share a folder with a group.

Not in version 1: desktop or mobile sync clients and WebDAV (Extended, §11); OCR of scanned PDFs and images (`no_text`;
Extended); Office previews rendered as pages (text previews only; LibreOffice headless Extended); metadata templates and
properties (Extended); retention and legal-hold policies beyond the trash and versions windows (Extended); real-time
co-editing of documents (Spaces owns authored content); e-signatures; **asking a folder in words** (Knowledge's, through K7);
a document shared between two installations.

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **guest** a member flagged external
holding Guest; a **group** a named set of members and agents with owners; a **library** a root — *personal* (one per member),
*group* (one per group), *shared* (business-wide, made by a curator); a **folder** a node of a library's tree; a **document**
one file in a folder with its versions; a **version** one upload of a document; a **blob** the bytes a version points at; a
**tag** a label from the managed vocabulary or a person's own; a **share** an access row on a folder or a document naming a
principal; a **link** a token that opens one folder or document to anyone holding it; a **file request** a link that only
uploads into a folder; a **lock** a claim on a document for editing; the **trash** where a deleted document waits.

| Actor | Who | How they reach the application |
|---|---|---|
| **Guest** (external) | A client, an auditor, a contractor: a kernel external holding Guest; sees only what is shared with them explicitly | The launcher, to a one-list home ("Shared with me") |
| **Member** | Everyone inside the business holding Member: a personal library, upload, folders, tags, share with members, groups and departments, links and file requests (paused for agents), the search, the graph | The launcher → `doccloud.<domain>`; the phone (375 px first: browse, upload, preview, share); the command bar |
| **Group owner** | Owns a group: its members, its library's root access, its default level | A group's settings (a row, not a role) |
| **Curator** | Makes groups and shared libraries; manages the tag vocabulary and auto-tag rules; sees storage and search reports; takes ownership of a leaver's documents | The curate area |
| **DocCloud admin** | Every library (private ones logged); settings; quotas; the versions and trash windows; the graph policy, budget and model (changed with a pause); every link; purge; costs. A super-admin holds it | The admin area |
| **An agent** | Any hired agent granted Member (the shipped **expert** and **Clerk** on install): browses, uploads, tags, searches, finds in the graph as its member id may | Records MCP (run token) · Actions MCP (relayed) · a chat turn · a duty |
| **A link holder** | Anyone with a link: views or downloads one folder or document; a file request uploads into one folder | `/s/<token>`, `/r/<token>` (no account; `auth_kind: none`) |
| **An outside system** | Holds a key: lists, uploads, downloads and searches what the key's person may, rate-limited, logged under the key's label | `/api/v1/*` (§8) |
| **A sibling application** | Knowledge feeding a base from a folder; Spaces linking a document; Help Desk, Projects, HR attaching one | K7 `documents_index` / `document_text` / `document_link` over an approved connection, only for folders shared with that application |
| **The kernel** | Sign-on, the directory, the agents, approvals, the command bar (chat endpoint), the ledger proxy (K24), the siblings' reads (K7), texts (K6) | — |
| **System** | The worker: blob assembly and hashing, text extraction, thumbnails, the keyword index catch-up, the graph queue, versions and trash expiry, link expiry, lock expiry, the outbox, quotas, the Clerk's wake | `source = 'cron'`, every row logged |

Tenancy: the business running the application is the tenant — one installation, one record database, **one memory database of
its own** for the document graph (§6.3, D3) beside the tenant's agent memory, which still receives this application's activity.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered). The base role's key is `user` so the
installer's `--grant-standing-departments` makes every insider a Member.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `guest` | Guest | read | `documents.guest` — nothing by default; reaches only folders and documents shared to them explicitly (and may upload or edit where the share says so); no personal library; never sees the directory beyond the people in what is shared |
| `user` | Member | write | `library.personal` (own root, unlimited depth), `documents.upload`, `documents.edit` (where the tree allows), `documents.share` (to a member, a group, a department, an agent), `links.make` (paused for agents), `requests.make`, `tags.apply` (managed and own), `search.use`, `graph.use`, `versions.restore`, `trash.own`, `export.own` |
| `curator` | Curator | write | Member's + `groups.manage` (create, owners, members, archive), `libraries.shared` (business-wide libraries and their root access), `tags.manage` (the vocabulary, merges, auto-tag rules), `ownership.transfer` (a leaver's documents to someone), `reports.storage`, `reports.search` |
| `admin` | DocCloud admin (is_admin) | admin | every right everywhere + `settings.manage`, `quotas.manage`, `retention.manage` (versions and trash windows), `graph.manage` (policy, budget, model — pauses), `links.all`, `trash.purge`, `agents.settings`, `costs.read`; **sees every library including personal ones, logged `library.admin_view`** |

**The principals** a caller is: themselves (`member`, and `agent` is a member), every group they are a live member of, every
department they are in (not for a guest), `everyone` (not for a guest). `dc_my_principals()` computes that set **once per
statement** (STABLE, the kernel's db/160 lesson).

**The walls** (`dc_can_see_document(id)`, `dc_document_level(id)` → `none|view|upload|edit|manage`; `dc_can_see_folder(id)`,
`dc_folder_level(id)`):

1. **The owner sees and manages their own document, wherever it is**, until it is purged or ownership is transferred. A document
   uploaded by an agent is owned by the agent's member id, like anyone's.
2. **A library grants a base level.** Personal: its owner `manage`, no one else. Group: every live member of the group at the
   group's `member_level` (default `edit`; owners `manage`). Shared: `everyone` at the library's `everyone_level` (default
   `view`; `none` for an invitation-only shared library), curators `manage`.
3. **A folder inherits the nearest explicit access above it**: `folder_access (folder_id, principal_kind member|group|department|
   agent|guest|application|everyone, principal_id, level)`; a folder with no rows of its own resolves at its parent's; a trigger
   keeps `folders.access_root_id` (the nearest ancestor with rows, else NULL = the library's base), so the resolver walks nothing
   at read time. **Sharing is additive**: the first explicit row on a folder carries the inherited set along (Spaces'
   `sp_page_permissions_before`); **restricting** a folder writes an explicit set without the inherited principals. A document may
   carry rows of its own (`document_access`, the same shape) — a single file shared with one person; `documents.access_root_id`
   points at itself then, else at the nearest folder.
4. **Levels, and what each gives** (Box's roles reduced to four): `view` — see, preview, download, comment, follow; `upload` —
   view's rights on **what they own** in that folder and nothing else, plus add (a drop box, a file request's folder, an inbox);
   `edit` — view's + add, new version, rename, move within what they may edit, tag, lock; `manage` — edit's + share, link, restrict,
   delete to the trash, restore, transfer ownership, settings. The library's base level is the floor; an explicit row raises or,
   after a restrict, replaces.
5. **A guest** holds nothing by base; only `member`-kind rows naming them (or a `guest` row), never `everyone`, never a department.
6. **A link** opens exactly one folder (and its subtree, read-only) or one document to its holder; a file request opens one folder
   for upload only, and the uploader sees nothing, not even their own upload afterwards (Dropbox's rule).
7. **The admin sees everything**, every personal library included, **logged** `library.admin_view` on first entry per session.
8. **An application principal** (a sibling's catalog key, as Knowledge's `base_members`) is what K7 checks: a folder shared with
   `knowledge` is what its feed may read.
9. **The trash** shows a person their own deleted documents and, to a folder's managers, the folder's; versions follow their
   document; the keyword index and the graph **answer only what the caller may see** — the index row carries the document, the
   graph result is mapped to the document and judged.
10. **An agent sees what its member id may see** — the same functions, no special case; the run-facts gate decides the tools;
    `is_eval` refuses every write. **Scope: none.** One business, one installation; departments are principals, never a wall.

**The scale rule.** DocCloud is built for hundreds of thousands of documents, so **no function returns the caller's whole visible
set per statement** (Spaces' `sp_visible_page_ids()` would be a 500,000-row set). Every list is a filtered, indexed query: the
caller's principals once (a dozen rows), then one indexed `EXISTS` per candidate row against `folder_access` /
`document_access` by `access_root_id`, the owner column and the library's base level. `mcp_documents` is defined that way, and
Phase 0's proof includes a 200,000-document scratch set with timings (a folder list, a search page and a graph find each under
200 ms on the planning host).

## 4. Doors for those who are not inside — guests, links, file requests, keys and the siblings

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the application's
own*; for a reach with no account, **a token is the authority**.

**A guest** is a kernel member flagged external, granted Guest by the super-admin; they land on "Shared with me".

**A link** (`share_links`: the secure-link shape — a 48-hex token, sha256 kept, `expires_at`, `rotated_at`, `revoked_at`,
`view_count`, `last_used_at` — appended with `folder_id`/`document_id` (one of), `kind view|request`, `allow_download`,
`password_hash` (optional; `password_hash()`; a link with a password asks for it once per browser session), `made_by`,
`note`): made by a manager of the thing (or its owner); **default expiry 30 days, a setting; never "no expiry" for a guest-reachable
document unless the admin allows it**; shown once; every open logged `link.open` with the token's label, never the token;
**making one pauses for an agent** (`external_send`). The public pages `/s/<token>` (a document: preview and download; a folder:
a read-only listing with download-as-zip) and `/r/<token>` (a file request: name, optional message, drop files; the uploads land
in the folder owned by the request's maker, tagged `request:<label>`) are `auth_kind: none` endpoints in the vhost's allow-list,
rate-limited per address (60 opens a minute, 20 uploads an hour), `noindex`, nothing of the directory shown.

**A key** (`mcp_access_tokens` with `scope = 'api'`, Knowledge's appended columns `rate_per_minute`, `rate_per_day`,
`rotated_from`) is a person's own, minted on *My settings*, acts **as that person** with their visibility, rate-limited (60/min,
5,000/day), logged under the key's label. The API (§8) is what an outside system — a scanner, a script, a workflow tool — uses.

**A sibling application** reads through **K7**: `documents_index`, `document_text`, `document_link`, `folders_index` are
`shares[]`; the kernel calls them over an approved connection; the answer covers only folders with an `application` principal
naming the consumer (and their subtrees).

**Rejected:** inviting outsiders by email into accounts; a link that never expires by default; a link to a personal library's
root; handing the MaluDB token to anyone; WebDAV with a password of its own (Extended, with a key as the password).

## 5. Agents and the application — the OS requirement, in detail

**Browsing, uploading, organising, tagging, searching and sharing inside the business are free; opening anything to the outside,
destroying anything and changing the budget are a person's.** For every agent:

| Action | Category | Why |
|---|---|---|
| `link_make`, `link_rotate`, `request_make`, `share_guest` (a row naming a guest), `share_application` (a row naming a sibling), `library_everyone_set` (shared library opened to everyone) | `external_send` | Content reaches someone outside, or a system |
| `document_purge`, `trash_purge`, `folder_delete` (to the trash with its subtree — because it is large), `version_purge`, `group_delete`, `library_delete`, `tag_delete`, `link_revoke` (not its own), `ownership_transfer` | `deletion` | |
| `graph_policy_set`, `graph_budget_set`, `graph_model_set`, `settings_save`, `quota_set`, `retention_set`, `graph_rebuild_all` | `other` | Configuration, spend |
| `folder_create`, `document_upload` (one or many, a new version too), `document_rename`, `document_move`, `document_copy`, `document_describe`, `tag_apply`, `tag_remove`, `tag_propose`, `share_member`, `share_group`, `share_department`, `share_agent`, `folder_restrict`, `document_lock`, `document_unlock` (own), `document_trash` (one document; to the trash, 30 days to restore), `document_restore`, `version_restore`, `group_create` (curator agents), `group_member_add`, `saved_search_save`, `clerk_proposal_*`, `export_folder` (zip, to the maker) | — | An agent must do these alone — this is what makes it a member of the store |

**Working.** (a) **The command bar and Spaces**: a person's turn on the expert through the kernel's chat endpoint; the expert
answers with the records tools — *where is the Acme contract, who can see the board folder, what changed in Finance this week,
how much space does Marketing use* — runs `search` (keyword) and, when the person asks for it or the keyword search is empty,
**`graph_find`**; it never composes an answer *from* a document's words (the owner's rule; that is Knowledge's). (b) **A duty**
(cron) for the Clerk. (c) **A proposal needing a hand** writes an `agent_dispatches` row: "forty documents arrived in Contracts
with no tag — propose tags" wakes the expert through one chat turn (K8 when it lands).

**Shipped agents** (`maludb-os.json` `agents[]`; **hired on install**):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — DocCloud Expert | The command bar and the house name: finds documents by place, name, tag, keyword and, on request, the graph; explains who may see a folder and why (`access_explain`); files a document where the asker says; proposes tags for untagged documents from the graph's subjects; makes shares inside the business; drafts a link or a file request and **pauses**; never purges | every records tool, `search`, `graph_find`, `graph_related`, `access_explain`, `record_history` | `document_upload`, `folder_create`, `document_move`, `tag_apply`, `share_member/group/department`, `document_trash`, `link_make` (paused), `request_make` (paused) | `link_make`, `request_make`, `share_guest`, `share_application`, every `deletion` |
| `clerk` — Filing Clerk | A duty every Monday 06:50 (`50 6 * * 1`): the **store report** — documents with no tag (clustered by folder, with the tags the graph suggests), duplicates by sha256 across libraries, shares to guests older than 90 days, links expiring this week and links opened from many addresses, file requests still open, the trash about to purge, documents locked for over a day, extraction failures, libraries near their quota, the graph budget nearly spent, documents owned by inactive members; each a **proposal** a curator or the owner accepts or dismisses; one Monday note per group to its owners, one to the admin's assistant | the report tools of §7, `graph_find`, `graph_subjects` | `clerk_propose`, `tag_propose`, `notification` to owners, `message_send` (the kernel's, to the admin's assistant) | nothing (it never deletes, links, shares out or spends) |

**Skills** (`skills/`): `doccloud-basics` (what a library, folder, document, version, tag, share, link, request, lock and the
trash *are*; which tool answers what; the ownership rule; never link out without the asker), `finding-documents` (keyword first,
then tags and folders, then the graph; how to read a graph result; when to say nothing was found), `sharing-well` (groups before
people, departments for standing teams, restrict for the sensitive folder, expiries), `tagging` (the vocabulary, when a free tag
becomes managed); runbooks (`kind: runbook`): `file-an-incoming-document`, `propose-tags-from-the-graph`, `share-a-folder-with-a-group`,
`monday-store-report`, `clean-up-duplicates`.

## 6. Memory model — what DocCloud remembers

Three stores, deliberately apart: **record memory** (PostgreSQL 17, `<tenant>_doccloud`) holds libraries, groups, folders,
documents, versions, blobs, text, tags, access, links, locks and the keyword index; **the blob store** (`DC_STORAGE_DIR`,
default `storage/blobs/`, content-addressed) holds the bytes; **the document graph** lives in MaluDB — **a memory database of
its own** (`<tenant>_doccloud_memory`, `maludb_core` enabled, its own API token; D3), one namespace `dc` with the library in
every document's metadata. The tenant's memory keeps receiving this application's activity episodes as every sibling's does.

### 6.1 From an upload to a document (`app/doccloud/store.php`, `app/doccloud/extract/`; the worker)

**Upload**: the browser sends a file in **parts of 8 MB** (`upload_sessions (id, member_id, folder_id, filename, byte_size,
mime_hint, parts_total, parts_done, sha256_claimed, status, expires_at)`; `POST /upload/start`, `/upload/part`, `/upload/finish`),
so a 2 GB file passes Apache's and PHP's per-request limits (64 MB, as Spaces) and a broken connection resumes; a file under 8 MB is
one part. **Finish** assembles the parts, hashes them (sha256), decides the type with `finfo` (never the extension), refuses the
executable kinds and HTML/SVG for inline serving (stored, but served as attachments), **de-duplicates**: an existing `blobs` row
with the same sha256 is reused (`ref_count + 1`), else the file moves to `storage/blobs/<aa>/<bb>/<sha256>`; then the
`document_versions` row and, for a new path, the `documents` row; a same-named document in the folder makes **a new version**
(the uploader may say "keep both" → `name (2)`). Folders dropped in the browser upload as their tree. Per-file ceiling
`dc_settings.max_document_bytes` (default 2 GB; env may only lower); per-library `quota_bytes` (NULL = none) checked at start;
abandoned sessions expire in 24 h.

**Serving**: `GET /d/<id>` (the current version) and `/d/<id>/v/<n>` stream the blob through the gate with
`Content-Disposition: attachment` unless the kind is on the inline allow-list (images, PDF, text kinds), `X-Content-Type-Options:
nosniff`, `Cache-Control: private`, range requests honoured (video, large PDFs). A folder downloads as a zip built by the worker
(`exports`, Spaces' table, kind `folder`), ready within the minute, expiring in 7 days.

**Text extraction** (the worker, within the minute of a version): Knowledge's extractor copied — PDF per page (`pdftotext -layout`;
no text layer → `no_text`), DOCX/PPTX/XLSX from the zip, HTML read, Markdown and text verbatim, CSV and JSON as rows, images
`no_text` (EXIF title and description kept), everything else `unsupported` — into `document_text (version_id, text, pages[],
extractor, char_count, status)`; capped at 5 MB of text per version (a setting). **Thumbnails**: images through GD (320 px), PDFs'
first page through `pdftoppm` (when present), else the kind's icon.

**The keyword index** (`search_index`, Spaces' canonical + appended `document_id`, `version_id`, `library_id`, `folder_id`,
`owner_member_id`, `mime_kind`, `byte_size`, `tags text[]`): one row per live document — `tsv` = title (A) · tags and description
(B) · the first 200,000 characters of the text (C); written by trigger on the document and by the worker after extraction;
`dc_search(q, filters, limit, offset)` = `websearch_to_tsquery('simple', unaccent(q))`, filters (library, folder subtree, owner,
tag, kind, size, dates, "shared with me", "mine"), **visibility as a per-row gate** (`dc_can_see_document()` — never a visible set),
`ts_rank`, `ts_headline` excerpts, `count(*) OVER ()`, limit ≤ 100. Phrase, `-word`, `"quoted"` and `OR` as `websearch` gives them;
a name search also runs `pg_trgm` on `documents.name` for misspellings.

### 6.2 Folders, groups, tags, versions, the trash (the rules the database keeps)

- **Folders**: `folders (id, library_id, parent_id, name, path text /1/5/9/, depth ≤ 32, access_root_id, owner_member_id,
  auto_tags text[], colour, archived_at, trashed_at)`; a name is unique among live siblings case-insensitively; a move rewrites the
  subtree's `path` and recomputes `access_root_id` for the subtree (one statement); a folder trashed takes its subtree.
- **Groups**: `groups (id, key, name, description, kind custom|department, department_id, member_level view|edit, archived_at)`,
  `group_members (group_id, member_id, role owner|member, added_by, joined_at, left_at)`; a `department` group's members are the
  department's live members, kept by the directory sync (a person leaving the department leaves the group); a `custom` group is
  curated by its owners; a group's library is created with it and archived with it (its documents stay, visible to the admin and
  to the owner of each, until a curator transfers them).
- **Tags**: `tags` (Help Desk's + `kind managed|free`, `colour`, `created_by`), `document_tags (document_id, tag_id, added_by,
  source person|agent|rule|request, created_at)`; a free tag used by three people becomes a managed proposal for the curator;
  **auto-tag rules**: a folder's `auto_tags` are applied to every document added under it; a merge rewrites `document_tags` and
  keeps the old slug as an alias (`tag_aliases`).
- **Versions**: `document_versions (id, document_id, version_no, blob_id, filename, mime_type, byte_size, sha256, uploaded_by,
  uploaded_at, note, extracted_status, purged_at)`; `documents.current_version_id`; **restore** makes a new version from an old
  blob; the window: all versions for `versions_keep_days` (30), then the last `versions_keep_count` (10), the current always;
  expiry by the worker, blobs freed when `ref_count` reaches zero (the file deleted the next pass).
- **The trash**: `documents.trashed_at` / `trashed_by`; `trash_days` (30) then **purge** by the worker (versions, text, index row,
  the graph's document forgotten, blobs freed); restore returns it to its folder (or the library root when the folder is gone).
- **Locks**: `documents.locked_by`, `locked_at`, `lock_expires_at` (24 h, a setting); an editor locks; a manager or the admin breaks.
- **Favourites and recents**: `favourites (member_id, document_id|folder_id)`; recents from the activity log (`document.open`).
- **Saved searches**: `saved_searches (id, owner_id, name, query, filters jsonb, is_shared, created_at)`.
- **Quotas**: `libraries.quota_bytes`, `used_bytes` kept by trigger from versions (a blob shared by two libraries counts in both —
  honest to the person, not to the disk; the admin's storage report shows the real disk).

### 6.3 The document graph (`app/doccloud/graph.php` — the ONE file that reads the MaluDB token)

**Why a graph of its own.** The tenant's one memory is what the agents remember of the business; the contents of half a million
files must not become their memory, and a search over them must be filtered by who is asking — which MaluDB cannot do on its own
(§0.3). So the graph is **a separate memory database, read only through DocCloud, every result judged by DocCloud's walls**.

**What enters it, and at what cost — the graph policy** (`libraries.graph_policy`, overridable per folder; D8):

| Policy | Engine call | Model cost | What the graph then knows |
|---|---|---|---|
| `none` | — | — | nothing; keyword search only |
| **`index`** (the default) | `POST /v1/memory/remember` per document: the text's first 20,000 characters, `subjects[]` = up to eight *known* subjects — its managed tags, its folder, its library, its owner, its kind, its year, the sibling record it came from (`request:`/`helpdesk:ticket:`…), the document itself (`doc:<id>`) — `verb = 'contains'`, `metadata {document_id, library_id, version_id}` | **none** (an embedding per chunk only) | which documents share a subject; passages near a query *within* a subject; "documents like this one" (shared subjects and near passages) |
| `full` | `POST /v1/memory/documents` per document (chunks of 2,000): the model extracts the people, organisations, places, amounts, dates, products and topics the text names, with verbatim spans | one extraction call per ~1,800 characters + an embedding per span — **queued** by DocCloud's worker (`graph_jobs`, one document at a time, retried thrice), under `graph_monthly_text_mb` (default 100 MB a month, a setting), oldest-first within a folder, priority to the smallest | the real knowledge graph: entities across documents, the explorer, "every document that names Acme and a termination clause" |

A policy raised on a folder queues its documents; lowered, it forgets them in the engine. A version replaces the previous one
in the graph (forget, then ingest). The admin sees the queue, the month's spend (K24's statement) and the estimate before a
`full` policy is set (`graph_estimate(folder)`: documents, characters, calls, the budget).

**Finding** — `dc_graph_find(query, filters, limit)` (one function behind the screen, the tool, the expert and the API):
1. **Subjects**: the query's terms matched against subject names and aliases (`GET /v1/graph/query`, `document_type = 'dc'`),
   plus the subjects `recall` proposes by trigram, plus any tag or folder the filters name;
2. **Candidates**: the documents on those subjects' edges (`/v1/graph/neighbors` kind `subject` → `document`), and the passages
   near the query within each subject (`/v1/memory/search {query, subject, namespace 'dc', limit 200}`), over-fetched;
3. **The wall**: every candidate's `metadata.document_id` → `documents` → `dc_can_see_document()` in one SQL statement; what the
   caller may not see is dropped **before ranking and before any excerpt is read**;
4. **Rank**: subjects matched × passages' similarity × recency; the result is documents (name, folder, owner, the matched subjects,
   the best passage's excerpt from DocCloud's own `document_text`, never the engine's), paged, limit ≤ 50.
`graph_related(document)`: the document's subjects → their other documents → the wall → ranked by shared subjects. The
**explorer** (`/graph`): subjects by degree (`god-nodes`, filtered to `dc`), a subject's neighbourhood, a path between two
subjects, surprises — every document node judged before display. **M10 owed**: a `document_ids[]` pre-filter on `search`/`recall`
so step 3 stops over-fetching; **M11 owed**: an async/bulk ingest route so the worker stops holding an HTTP request per document.

**Not here**: an answer in words from the documents (M1) — the owner's rule; a folder shared with Knowledge becomes a base there
and is asked there (§8). The graph never leaves `graph.php`; a missing token makes every find and every job answer
`graph_unavailable`, never a crash; keyword search works without the engine.

**Record memory** (PostgreSQL 17, `<tenant>_doccloud`, db/001–0NN; prefix `dc_`; views `mcp_*`):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members` (+ `is_agent`, `is_external`), `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` |
| Roles | `dc_rights`, `dc_roles`, `dc_role_rights`, `mcp_app_roles` | `dc_has_right(right)` |
| Settings | `dc_settings` (one row: `max_document_bytes` 2 GB, `max_text_bytes` 5 MB, `versions_keep_days` 30, `versions_keep_count` 10, `trash_days` 30, `lock_hours` 24, `link_default_days` 30, `link_no_expiry_allowed` false, `upload_session_hours` 24, `graph_monthly_text_mb` 100, `graph_default_policy` index, `graph_text_chars` 20,000, key defaults 60/min 5,000/day, the model alias and embedding model — read from MaluDB, changed with a pause, `storage_dir`) | seeded |
| Libraries and groups | `libraries` (id, `kind` personal/group/shared, name, `owner_member_id`, `group_id`, `everyone_level`, `graph_policy`, `quota_bytes`, `used_bytes`, `root_folder_id`, `archived_at`), `groups`, `group_members` | a personal library is made on first sign-on; a group's with the group |
| Folders and access | `folders`, `folder_access`, `document_access`, `access_changes` (who changed what access when — the trail Spaces keeps in `activity_log`; here a table because "who could see this on June 3rd" is a question) | `access_root_id` by trigger |
| Documents | `documents` (id, `library_id`, `folder_id`, `name`, `description`, `owner_member_id`, `current_version_id`, `access_root_id`, `mime_type`, `mime_kind` image/pdf/office/text/audio/video/archive/other, `byte_size`, `sha256`, `locked_by/at`, `lock_expires_at`, `trashed_at/by`, `created_by/at`, `updated_at`, `last_opened_at`), `document_versions`, `blobs` (id, `sha256` unique, `byte_size`, `storage_path`, `ref_count`, `created_at`, `deleted_at`), `document_text`, `upload_sessions`, `upload_parts` | the file store's canonical tables |
| Tags | `tags`, `tag_aliases`, `document_tags`, `tag_proposals` | §6.2 |
| Links and requests | `share_links`, `link_opens` (link, at, address hash, what) | the token never stored |
| Search | `search_index` (reused + appended), `saved_searches`, `search_log` (member, query, results, took_ms, kind keyword/graph — the search report; the query kept 90 days) | |
| Graph | `graph_jobs` (document, version, `kind` index/full/forget/replace, status, attempts, error, `usage jsonb`, times), `graph_documents` (document_id, version_id, `maludb_document_id`, policy, `indexed_at`), `graph_usage` (month, kind, calls, chars, documents) | the application's own count; dollars are the kernel's (K24) |
| Exports | `exports` (Spaces' + `params`; kind `folder`), `worker_passes` | GL/Spaces canonical |
| Favourites | `favourites` | |
| Agents | `agent_dispatches` (+ `document_id`, `folder_id`), `clerk_proposals` (kind untagged/duplicate/stale_share/link_expiring/link_suspicious/request_open/trash_due/locked/no_text/quota/budget/orphaned, payload, status, decided_by/at) | §5 |
| Notifications | `notifications` (share_received, request_received, link_opened (optional), version_added (followed), comment, lock_broken, export_ready, quota_near, proposal, monday_note), `notification_prefs`, `notification_outbox` | Spaces' member-only shape |
| Notes | `notes` (on a document, a folder) — comments | GL canonical |
| Keys and tokens | `mcp_access_tokens` (+ Knowledge's rate columns), `key_usage` | a person's own |

### 6.4 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | **reuse** | Consultant Tracking `db/001` / Spaces `001`; `members` **appends** `is_agent`, `is_external` (Spaces') |
| `activity_log`, `activity_ingest_state` | **reuse** | `memory.md` / CT `db/002`; **appends** `library_id`, `document_id`, `folder_id` |
| `mcp_access_tokens`, `key_usage` | **reuse** | HR `db/003` + Knowledge's appended rate columns |
| `dc_rights`, `dc_roles`, `dc_role_rights`, `mcp_app_roles` | **reuse** (shape) | CT `db/004` (`ct_` → `dc_`) |
| `dc_settings` | **reuse** (skeleton) | the `app_settings` singleton |
| `search_index` | **reuse** | **Spaces `db/013` canonical**, verbatim; **appends** `document_id`, `version_id`, `library_id`, `folder_id`, `owner_member_id`, `mime_kind`, `byte_size`, `tags text[]`; `entity_kind` widened with `document` |
| `tags` | **reuse** | Help Desk `db/009`; **appends** `kind`, `colour`, `created_by` |
| `share_links` | **reuse** (shape) | GL `invoice_links_secure` = Inventory `order_links_secure`: the token columns verbatim; **appends** `folder_id`, `document_id`, `kind`, `allow_download`, `password_hash`, `made_by`, `note`, `revoked_at` |
| `notes` | **reuse** | GL `db/009`; `record_type` widened to `document`, `folder` |
| `notifications`, `notification_prefs`, `notification_outbox` | **reuse** | Spaces `db/012` (member-only); `kind` lists ours |
| `agent_dispatches` | **reuse** | GL `db/014` = CT `db/013`; **appends** `document_id`, `folder_id` |
| `exports`, `worker_passes` | **reuse** | Spaces `db/016` + `023`; `kind` widened with `folder` |
| `attachments` | **not used** | a document is the record, not a file on one; the store's rules (finfo, refused kinds, hidden path, thumbnails) are copied into `store.php` |
| `documents`, `document_versions`, `blobs`, `document_text`, `upload_sessions`, `upload_parts` | **new** — canonical, "the estate's file store" | Knowledge's `sources`/`sections` are an *ingested* document with anchors (compared; different concept: no versions, no bytes of its own); GL's `attachments` a file on a record |
| `libraries`, `folders`, `folder_access`, `document_access`, `access_changes` | **new** — canonical | Spaces' `page_permissions` is the model for the access rows (compared; a page is not a folder); `group` principal added |
| `groups`, `group_members` | **new** — canonical, **the estate's first group** | nothing close: departments are the kernel's, `space_members`/`queue_members`/`engagement_members` are memberships of a thing, not a group |
| `document_tags`, `tag_aliases`, `tag_proposals` | **new** — canonical (the polymorphic tagging the catalogue lacks: `record_type`-free because a document is the only taggable here; a sibling that tags several kinds appends `record_type`) | Help Desk's `ticket_tags`, Projects' `issue_labels` compared |
| `saved_searches` | **new** | Projects' `saved_filters` and Help Desk's `saved_views` bind to a project or a queue; this binds to nothing |
| `graph_jobs`, `graph_documents`, `graph_usage`, `search_log`, `link_opens`, `favourites`, `clerk_proposals` | **new** | Knowledge's `builds`/`build_items` compared (a build is per base, all-at-once; a job is per document, continuous) |
| HR's employment, Help Desk's tickets, Projects' issues, Spaces' pages, Knowledge's bases | **read** | never copied; a sibling's record is at most a tag (`helpdesk:ticket:412`) on a document that came from it |

**Owed to the catalogue**: `documents`/`document_versions`/`blobs`, `groups`/`group_members`, `folders`/`folder_access`,
`document_tags` as DocCloud's canonical tables; `tags`' appended columns; `share_links` as the generalised secure-link shape.

**Views and functions**: `mcp_*` over every table above with `security_barrier` and the per-row gate; `storage_path`, `password_hash`
and token hashes never selected; the reads that matter are SQL functions — `dc_my_principals()`, `dc_can_see_document(id)`,
`dc_document_level(id)`, `dc_folder_level(id)`, `dc_folder_listing(folder, sort, page)`, `dc_search(...)`, `dc_access_explain(document
| folder, member)` ("Sam sees this because: member of Finance → Finance library → edit; and a row on Contracts → manage"),
`dc_storage_by_library()`, `dc_duplicates()`, `dc_quota_state(library)`, `dc_rate_ok(token)`, `dc_graph_estimate(folder)`; the
engine reads are **one PHP module** (`app/doccloud/graph.php`).

**Activity memory**: `activity_log` through one `log_activity()`, shipped to the tenant's one MaluDB as `activity` episodes tagged
`doccloud`. **A document's text, a passage, a search query over 120 characters, a token, a link, a password are never in a log
payload** (ids, names, counts, kinds, statuses, byte sizes). Events: `library.create|rename|archive|quota_set|policy_set|admin_view`,
`group.create|update|archive|delete|member_add|member_remove|owner_set`, `folder.create|rename|move|restrict|share|unshare|trash|
restore|delete|export`, `document.upload|version|rename|move|copy|describe|open|download|preview|share|unshare|lock|unlock|
lock_break|trash|restore|purge|transfer`, `version.restore|purge`, `tag.create|apply|remove|merge|propose|accept|delete`, `link.make|
rotate|revoke|open|refused|expire`, `request.make|close|upload`, `search.keyword|graph|save`, `graph.queue|index|full|forget|fail|
policy_set|budget_set|model_set|rebuild`, `clerk.propose|accept|dismiss`, `agent.dispatch|reply|fail`, `settings.save`, `share.read`,
`key.mint|rotate|revoke|rate_limited`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`.

## 7. The question inventory — what DocCloud exists to answer

Kind R = record (the records MCP), A = activity (the activity MCP). Who = the lowest role that may ask (every answer is filtered by
what the asker may see). The tool name is fixed here so Phase 1 is a transcription.

**Libraries, groups and folders**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| L1 | Which libraries may I reach, of which kind, how full, with what graph policy? | R | Guest | `find_libraries`, `get_library` |
| L2 | What is in folder X — subfolders and documents, sorted, paged, with tags and sizes? | R | Guest | `folder_listing`, `get_folder` |
| L3 | Where is a folder — its path from the library root? | R | Guest | `folder_path` |
| L4 | Which groups exist, who is in group G and who owns it; which groups am I in? | R | Member | `find_groups`, `get_group`, `group_members`, `my_groups` |
| L5 | What storage does each library, group and person use; which libraries are near their quota? | R | Curator | `storage_by_library`, `storage_by_owner`, `quota_state` |

**Documents and versions**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| D1 | What is document X — name, folder, owner, kind, size, tags, versions, lock, shares, links? | R | Guest | `get_document` |
| D2 | Find documents by name, tag, owner, kind, size, dates, folder subtree, "mine", "shared with me", "recent", "favourites" | R | Guest | `find_documents` |
| D3 | What are document X's versions, who uploaded each, what changed in size? | R | Guest | `document_versions` |
| D4 | What does document X say — its extracted text, one page, the excerpt around a match? | R | Guest | `document_text`, `document_page`, `document_excerpt` |
| D5 | Which documents are duplicates (the same bytes in two places)? | R | Curator | `duplicates` |
| D6 | Which documents are locked, by whom, since when? | R | Member | `locked_documents` |
| D7 | What is in my trash; what is in folder X's trash; what purges this week? | R | Member | `trash`, `trash_due` |
| D8 | Which documents have no text, failed extraction, or are unsupported? | R | Curator | `extraction_failures` |

**Access, shares and links**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| A1 | **Who can see document or folder X, at what level, and why** (owner, library, group, department, row, link)? | R | Member (what they manage) | `access_list`, `access_explain` |
| A2 | What do I see here and what may I do (my level)? | R | Guest | `my_level` |
| A3 | What is shared with me, by whom, when? | R | Guest | `shared_with_me` |
| A4 | What have I shared out, to whom; which shares to guests are old? | R | Member | `my_shares`, `stale_guest_shares` |
| A5 | Which links exist on folder or document X; which expire this week; which were opened from many addresses? | R | Member (managers) | `find_links`, `links_expiring`, `links_suspicious` |
| A6 | Which file requests are open, into which folders, how many uploads? | R | Member | `find_requests` |
| A7 | Who could see document X on a given day? | R | Curator | `access_history` |

**Tags and search**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| T1 | What tags exist — managed and free, counts, colours, aliases; which are proposed? | R | Guest | `find_tags`, `tag_proposals` |
| T2 | Which documents carry tag T (in library L)? | R | Guest | `documents_by_tag` |
| T3 | Which documents in folder X have no tag? | R | Member | `untagged` |
| S1 | **Search by keyword** across everything I may see, with filters and excerpts — the search box | R | Guest | `search` |
| S2 | What are my saved searches; run one | R | Member | `saved_searches`, `run_saved_search` |
| S3 | What did people search for this month that found nothing (the search report)? | R | Curator | `search_gaps` |

**The document graph** (the dedicated screen and tools — never run by the expert unless asked)
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| G1 | **Which documents are about X / near this phrase** — the graph find | R | Guest | `graph_find` |
| G2 | Which documents are like document X (shared subjects, near passages)? | R | Guest | `graph_related` |
| G3 | Which subjects does document X name; which subjects are the most connected in library L? | R | Guest | `document_subjects`, `graph_subjects` |
| G4 | What is subject Y — its documents, neighbours; how are Y and Z connected? | R | Guest | `get_subject`, `subject_neighbors`, `graph_path` |
| G5 | What is queued, indexed, failed; what would a `full` policy cost on folder X; what has the month spent? | R | Admin | `graph_queue`, `graph_estimate`, `graph_usage` |

**Agents, proposals, keys**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| P1 | What did the Clerk propose, and what was accepted? | R | Member (own) / Curator | `clerk_proposals` |
| P2 | Which dispatches are pending or failed? | R | Admin | `agent_dispatches` |
| P3 | Which keys exist, their limits, last use, today's count? | R | Member (own) / Admin | `find_keys` |
| P4 | Which siblings read which folders, and how often? | A | Admin | `share_reads` |

**Activity**
| # | Question | Kind | Who | Tool |
|---|---|---|---|---|
| ACT1 | Who did what to this document or folder, in order (uploads, moves, shares, opens, downloads)? | A | Guest (what they see) | `record_history` |
| ACT2 | What did person, key, link or agent X do here this week? | A | Curator | `actor_timeline` |
| ACT3 | What changed in library L / folder X since I last looked? | A | Guest | `recent_activity` |
| ACT4 | Who downloaded document X, and when? | A | Member (managers) | `downloads` |
| ACT5 | Search the activity for a phrase | A | Guest | `activity_search` |

Plus the contract's: `app_roles`, one guarded `search` on each server (the records one IS S1), `record_history`. Records tools:
about 55; activity tools: 7.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit from Consultant Tracking; a personal library is
  created on a member's first sign-on (and for every member on the first directory sync, so an agent's upload "to Sam" lands).
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it and the `shares[]` alone.
- **Agents**: run tokens on the two read servers (the run-facts gate, fail closed); the Actions MCP from the registry; the
  approval hook before every action with a category; the chat endpoint for the command bar and dispatched proposals; the duty
  for the Clerk.
- **The engine**: `MALUDB_DC_API_URL` and `MALUDB_DC_API_TOKEN` (the document graph's own token, minted at install by
  `deploy/ROOT_STEPS.sh`), read only by `app/doccloud/graph.php`; a missing token makes every graph job and find answer
  `graph_unavailable`, never a crash; keyword search needs nothing of it.
- **Metering (K24, built)**: MaluDB's provider `base_url` for this memory database points at the kernel's ledger proxy with the
  application's model credential (`bin/application_model_credential.php mint --app doccloud --models … [--budget …]`), so every
  extraction and embedding call is priced, ledgered and lands on the AI statement; the embedding model registered with a price.
- **Texts (K6)**: a file request's maker may be texted "3 files arrived in Invoices from the request *Supplier uploads*" (opt-in).
- **The API** (bearer = a key, acting as its person; allow-listed in the vhost): `GET /api/v1/folders/{id}` (listing), `POST
  /api/v1/upload` (one request ≤ 64 MB; parts beyond), `GET /api/v1/documents/{id}` (+ `/download`, `/text`), `PUT
  /api/v1/documents/{id}/tags`, `POST /api/v1/search {q, filters}`, `POST /api/v1/graph/find`, `POST /api/v1/links` (a link, as
  the person), `GET /api/v1/health`. 401, 403, 413, 429 with `Retry-After`, 503 `graph_unavailable` (the graph routes only).
  Logged with source `api`.
- **K7 — DocCloud is a provider.** Shares, every one a records-server tool, people-free:
  | Tool | For | Document | Content |
  |---|---|---|---|
  | `folders_index` | Knowledge (a folder as a feed), Spaces, Help Desk, Projects, HR | `os.doccloud-folders/1` | the folders shared with the consumer application: id, path, document count, updated |
  | `documents_index` | the same | `os.doccloud-documents/1` | the documents of a shared folder (subtree): id, name, kind, size, sha256, tags, owner, version, updated — paged, `since` |
  | `document_text` | Knowledge's feed | `os.doccloud-text/1` | the extracted text of one document with its pages (60 s timeout; ≤ 5 MB) |
  | `document_link` | a sibling embedding or attaching a document | `os.doccloud-link/1` | a **signed, 10-minute download URL** for one document (bytes never cross K7's JSON); the open is logged under the consumer |
  Reads (`reads[]`): none in v1 — the siblings keep their own `attachments` (the canonical); "save this to DocCloud" from a
  sibling is each sibling's Extended item (HD4, P4, SP3, H3: a `reads[]` on `document_link` and an upload through the API as the
  acting person).
- **Public doors** (allow-listed in the vhost): `/s/`, `/r/` (token in the path, `auth_kind: none`), `/api/v1/*` (key-checked),
  `/mcp/records`, `/mcp/activity`.
- **Live-ness without a socket**: uploads report per part; the folder page polls every 5 s while an upload or export of its own is
  running; the graph queue page every 5 s while visible.

## 9. Screens (the nxl look; the folder listing, preview and upload are read at 375 px first; the explorer is designed at 1280 px and
works at 375; no modals but the record picker; cards for libraries, groups and tags, **a table for a folder's listing** (a listing is
line items: name, kind, size, modified, owner, tags — with a grid toggle for images); every name a link; every page a way back)

**The shell**: a sidebar at 1280 (Home · **My files** · Shared with me · Groups (each group: its library) · Libraries · Recent ·
Favourites · Tags · **Search** · **Graph** · Trash · Curate · Admin), a bottom tab bar at 375 (Home · Files · Search · Me). The
command bar at the top of every screen. **Drag-and-drop upload on every folder page**, with a progress list per file.

**Home** — my libraries and groups as cards (used of quota, policy); recent documents; shared with me this week; open file requests;
links expiring; the Clerk's last note; curators: untagged this week, duplicates, stale guest shares; admin: storage, the graph
queue and the month's spend, failed extractions. **A new person's home is one button: "Upload your first file"** (and "Create a
group" for curators).

**Files**: `folder` (`/f/<id>`: crumbs, the listing as a table or a **grid of tiles** (2026-10-10, `docs/build-specs/grid-and-media.md`:
thumbnails for images, videos and PDF pages, a type icon with the extension otherwise, a video's length, the "Hide preview" switch below an
image or a video for an editor — a placeholder for everyone; `?view=list|grid`, remembered per browser) with sort and filters, multi-select → move/copy/tag/trash/download zip,
"New folder", "Upload", "Share", "Get link", "Request files", the folder's access summary ("Finance · 12 people · 1 group"),
the subtree's size) · `document` (`/d/<id>`: preview (image, PDF in the browser's viewer, text and Markdown rendered, CSV as a
table, Office as its extracted text with "download to open"), name, description, tags (add inline), owner, folder path, size,
version list with restore, lock, "who can see this" (the explanation), links, comments (notes), related documents from the graph; a video
streams in the page's player (2026-10-10: playsinline, the poster, a format no browser plays says so with Download; a hidden preview shows
a placeholder with Show)
(when indexed), the trail, download) · `folder-form` · `move` (the record picker over folders I may edit) · `upload` (the drop
zone, the per-file list, "keep both" on a conflict).

**Sharing**: `share` (`/f/<id>/share`, `/d/<id>/share`: the inherited rows (greyed, "from Contracts"), the explicit rows, add a
principal (the picker: people, groups, departments, agents; guests flagged), the level, **restrict**; "who can see this and why")
· `links` (make: expiry, download allowed, password; the list; rotate; revoke) · `request` (make: label, message, expiry; the
list, uploads so far) · `shared-with-me` · `my-shares`.

**Groups**: `groups` (cards; create: name, kind custom or from a department) · `group-view` (members, owners, the library's
root, default level, archive) · `group-members` (the picker).

**Tags**: `tags` (managed and free as chips with counts; create; merge; the proposals) · `tag-view` (its documents) · `rules`
(a folder's auto-tags).

**Search**: `search` (`/search?q=`: the box, the filters as chips, results with excerpts, "save this search", "try the graph" when
few) · `saved-searches`.

**Graph**: `graph` (`/graph`: **find** — the box, filters, results as documents with the matched subjects and the best passage;
"explore" — subjects by degree for a library, a subject's neighbourhood as a small SVG at 1280 and a list at 375, a path between
two subjects, surprises; the policy and the queue state in a footer) · `subject-view` · `graph-admin` (the queue, the estimate,
the budget, the policy per library and folder, the model with a pause, usage by month).

**Trash**: `trash` (mine; a folder's for managers; restore, purge (admin)).

**Curate**: Groups · Shared libraries · Tags and rules · Ownership (a leaver's documents) · Storage report · Search report ·
Clerk proposals.

**Admin**: Settings (ceilings, windows, link defaults) · Quotas · Graph (policy, budget, model) · Links (all) · Libraries (all;
personal ones logged) · Keys (all) · Agents · Connections · Worker · My settings · My MCP tokens and keys.

**Public**: `/s/<token>` (a document: name, size, preview, download; a folder: the listing read-only, download zip; the password
page when set) · `/r/<token>` (the request: who asks and why, the drop zone, done).

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers; the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2; a personal, a group and a shared library and who sees each for nine actors incl. a guest, an agent, a link holder and an application principal; the ownership rule (an uploader at `upload` sees their own file and no one else's; the owner sees it after a move); inheritance, additive sharing and a restrict; a move rewriting `path` and `access_root_id`; a group from a department following the directory sync; a document with three versions, a restore, the windows expiring; dedupe (two uploads, one blob, `ref_count` 2, freed at 0); the trash and the purge; a lock and its break; a link, its opens, its expiry, a password, a file request's upload; tags, an alias merge, an auto-tag rule; the keyword index catching up and `dc_search` honouring the wall; `search_index` and `tags` verbatim against the siblings; **the 200,000-document timing set**); the kit proven without a kernel (`tests/phase0/run.sh`); **the extractor against fixtures** (`tests/extract/`: Knowledge's fixtures, copied); **the engine proof against the live MaluDB API on a scratch memory database** (`tests/phase0/engine.sh`: `remember` a document under eight subjects, `search` within a subject, `graph/query`, `neighbors`, `documents` route once with a credential, forget — recording what the API returns and the over-fetch wall working); `maludb-os.json`, `os/{expert,clerk}.md`, nine skills (five runbooks), `deploy/` with `ROOT_STEPS.sh` (ports, the storage directory, then the memory database, its token and K24's credential); the installer's `plan` clean | the owner's go |
| 1 | `docs/doccloud-mcp-tool-surface.md`, `docs/doccloud-action-manifest.md`, `mcp/action_registry.json`, `docs/build-specs/uploads-and-versions.md`, `docs/build-specs/visibility.md`, `docs/build-specs/document-graph.md`, and the slice specs (`sso-shell` and nine slices; **uploads-and-versions and the document graph are the exemplars**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer (with the personal library per member), `members.roles`, `dc_has_right()`, the ingest bridge, the shell with the record picker, `/api/v1/health`, the vhost allow-list, `store.php` and `graph.php` with `graph_unavailable` | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) libraries, groups and folders** (the CRUD exemplar: the personal library, groups custom and from a department, group libraries, shared libraries, folders, move with the picker, the listing table, favourites, recents) → **(2) uploads, versions, blobs, serving and preview — THE FIRST EXEMPLAR** (parts, resume, finish, dedupe, conflicts, the version list and restore, download with ranges, zip export, thumbnails, text extraction by the worker, the no-text and unsupported states) → **(3) access, sharing, links and file requests** (`folder_access`/`document_access`, inheritance and restrict, the share screen with "why", guests, the link and request doors, expiries, the password, `access_explain`, `access_history`) → **(4) tags, the keyword index and search** (the vocabulary, free tags, aliases and merges, auto-tag rules, proposals, the index and its catch-up, `dc_search`, the search screen, saved searches, the search log) → **(5) the document graph — THE SECOND EXEMPLAR** (`graph.php`, the policy per library and folder, the queue and the worker's jobs under the budget, `index` through `remember`, `full` through `documents`, replace and forget, `dc_graph_find` with the wall, related documents, the explorer, the estimate, usage) → **(6) the trash, locks, quotas and the worker's windows** (trash and purge, versions expiry, blob freeing, locks and breaks, quotas and `used_bytes`, upload-session expiry, link expiry, the outbox, worker passes) → **(7) the API, keys and K7** (the routes of §8, keys with rates, the four shares with the application principal) → **(8) agents, the Clerk and notifications** (the two agents' tools and actions, dispatches, the Clerk's report and proposals, notifications and prefs, K6 texts) → **(9) home, curate, admin, reports and tokens** (the homes, ownership transfer, storage and search reports, settings, quotas, graph admin, keys and tokens) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database and namespace |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the four `shares[]` admitted to the kernel's token (the K26 headers read), the registry wrapper, the two agents with their grants and the duty; the Actions-MCP contract proved against the kernel's `application_actions.register()` | the command bar finds a document by keyword and says who may see it; an agent's `link_make` pauses; the Clerk links nothing, deletes nothing, spends nothing |
| 5 | the owner's `app_install.php apply` (ports pinned first; the storage directory on the right disk), the memory database and its token, the K24 credential, DNS and TLS, the hires, **any folder of the owner's real files** | **a member drags a folder of a hundred files (PDF, Word, images, a 300 MB video) onto *My files* on a desktop and watches them land, de-duplicated; shares it with a group; a group member on a phone searches a phrase and opens the match at the excerpt; a guest sees only the one folder shared with them; a file request receives a supplier's upload the maker is texted about; a link with a password opens from another machine and expires on its day; a `full` policy on one folder queues, spends within the budget and the graph finds "every document that names <a supplier>" — the guest's search finds none of it; the Monday note names the duplicates; the kernel's statement shows the embedding and extraction tokens** |

**Extended** (§11). Size: ten specs (sso-shell + nine slices), about 48 screens, 85 actions, 55 records tools, 7 activity tools.
**Three to four days is the honest estimate**: libraries and uploads one, sharing and search one, the graph and the worker one,
the rest and Phase 5 with the owner the remainder — the Spaces division (D16).

## 11. Extended, later, and what other applications owe

**Extended** (each its own spec, in the likely order): **WebDAV** (`/dav/`, a per-person key as the password, so OS-native mounts
and sync clients work — class 1 + locks) · **OCR** (tesseract, PDFs without a text layer and images) · **Office previews** as
pages (LibreOffice headless to PDF, cached per version) · **metadata templates** (a library's typed properties, Box's; searchable)
· **retention and legal hold** (a folder's policy: delete or archive after N years; a hold that stops the trash) · **"ask this
document / this folder"** through M1 on the document graph (the owner's rule lifted deliberately, on request) · **the desktop
companion** when the kernel's lands · **sibling "save to DocCloud"** (HD4, P4, SP3, H3) · zip import (a zip exploded into a folder)
· comments with mentions (Spaces' shape) · a public gallery link · `full` policy by the Clerk's recommendation · **evals**: a set of
queries with expected documents run before and after a policy or model change.

**Later**: a document shared between two installations; federation (Nextcloud's); training of anything (never).

## 12. What the kernel, MaluDB and the siblings owe this application

| # | Item | State |
|---|---|---|
| **K31** | **Catalog entry `doccloud`** (kind `ours`, Operations, category `storage` — already admitted by db/175 —, `feather-hard-drive`); on request, not a default (D15) | owed: kernel `db/176`, after the owner's go |
| K24 | An application's credential at the ledger proxy | **built** (db/171) — used for the graph's calls |
| K26 | K7 tells a provider who asks; `timeout_seconds` | **built** (db/173) — `document_text` declares 60 |
| K16 | K8 (wake an agent from an application) — owed to every sibling | the chat-turn dispatch until then |
| K6 | texts to a member | **built** — the file request's text |
| **M10** | **A `document_ids[]` (or metadata) pre-filter on `POST /v1/memory/search` and `/recall`** so a visibility-filtered search stops over-fetching | owed upstream; the wall over-fetches ×3 to the cap until then |
| **M11** | **An asynchronous or bulk ingest route** (`POST /v1/memory/documents` as a job) so a worker is not one HTTP request per document | owed upstream; DocCloud's queue holds the request, one at a time |
| M3 / M6 / M7 | graph routes scoped by namespace; community detection; sequence-allocated subject ids and aliases | inherited from Knowledge; `document_type = 'dc'` filtering; subjects made through `remember` |
| M12 | `remember` admits more than eight subjects per document (a document with twelve tags loses four) | owed upstream; the eight most used win until then (`subjects_dropped` names the rest) |
| M2 | usage on `POST /v1/memory/documents` | owed upstream; K24 meters at the proxy |
| found | `source_type` and `subject_type` are CONTROLLED vocabularies (`malu$source_type`: document, conversation, ticket, …; the subject types project, person, ai_agent, equipment, software, network, event, process, workflow, time_period, other, stakeholder, concept): an unknown one is a 422 — for a subject type, AFTER the model was paid | both policies store `source_type = 'document'` (`full` adds `document_type = 'dc'`; `remember` sets none — the policy is `graph_documents.policy`); the `full` prompt names registered types only (`docs/build-specs/document-graph.md`) |
| found | `GET /v1/subjects/{id}` answers a subject's documents directly; `graph/query`'s `namespace` is a canonical-name prefix (useless here); `/v1/memory/search` returns `document_id` but no metadata; a `document_ids[]` body field is ignored | the memory database being DocCloud's own (D3) is what scopes every graph read; the wall maps `maludb_document_id` → `graph_documents` → `dc_can_see_document()` (`tests/phase0/engine/FINDINGS.md`) |
| SP3 / HD4 / P4 / H3 | the siblings' "save to DocCloud" and a `reads[]` on `document_link` | nothing reads us yet |
| KN1 | Knowledge's feed over `doccloud.documents_index` + `document_text` (a folder as a base) | Knowledge's `feeds` already consume K7 reads; the provider is this plan |

## 13. The decisions (recommendations; the owner's answers are §15 — every one taken, 2026-10-09)

| # | Decision | Recommendation | Alternative |
|---|---|---|---|
| D1 | Name, key, label | **"DocCloud", key `doccloud`, DNS label `doccloud`** (`doccloud.<domain>`), databases `<tenant>_doccloud` and `<tenant>_doccloud_memory`, roles `doccloud_rw` / `_records_ro` / `_activity_ro`, prefix `dc_` | label `files` (`files.<domain>`) or `docs` — the key stays `doccloud` |
| D2 | Roles | **Four**: Guest, Member (`user`), Curator, DocCloud admin; group ownership a row; the admin sees personal libraries, logged | a fifth "Library owner" role (Spaces' `space_owner`) — not needed: a group's owners are rows |
| D3 | The document graph's home | **A MaluDB memory database of DocCloud's own**, one namespace `dc`, never the tenant's agent memory; content never enters what agents remember | a namespace in the tenant's memory — rejected: the owner's rule, and no per-document visibility in the engine |
| D4 | What the default AMA does | **The expert answers about the store and runs keyword search; the graph only when asked**; never an answer composed from a document's words (that is Knowledge's, through K7) | the expert runs `graph_find` on every empty keyword search automatically |
| D5 | Visibility | **Ownership + library base + inherited additive rows + restrict; four levels (view, upload, edit, manage); an `upload` holder sees only their own; guests only by name; links one thing; the admin everything, logged; the scale rule (no visible set per statement)** | Box's eight roles; Nextcloud's per-folder ACL grid |
| D6 | Groups | **New canonical `groups`/`group_members`: custom (curated) or a department mirror (synced); a group library created with the group and surviving it** | departments only (no groups) — rejected: the owner asked for groups |
| D7 | Keyword search | **PostgreSQL, Spaces' `search_index` reused: title A, tags and description B, text C (200,000 characters), `websearch`, trigram on names; free, no server** | an Elasticsearch/Solr platform (Nextcloud's) — rejected: a second server for what PostgreSQL does |
| D8 | The graph policy and its cost | **Three policies per library and folder — `none`, `index` (default: `remember`, no model, known subjects), `full` (model extraction, queued, under 100 MB of text a month)**; the estimate before `full`; replace on a new version; forget on purge | `full` everywhere — rejected: one model call per ~1,800 characters, synchronous, across hundreds of thousands of documents |
| D9 | Uploads | **Parts of 8 MB, resumable; 2 GB per file (a setting); content-addressed blobs de-duplicated by sha256; `finfo` decides the type; executables stored but never served inline; folders dropped as trees** | a per-request ceiling only (Spaces' 64 MB) — rejected: a file store takes big files |
| D10 | Versions, trash, locks | **Every upload of a same-named document is a version; all for 30 days then the last 10; the trash 30 days then purge by the worker; locks 24 h, breakable by a manager** | Nextcloud's thinning schedule — a setting later |
| D11 | Links and file requests | **Links expire in 30 days by default (a setting; "never" only when the admin allows), optional password, download allowed by default, every open logged; file requests upload-only and blind; both pause for an agent** | links that never expire by default (Drive's old way) — rejected |
| D12 | Tags | **A managed vocabulary (curators) plus free tags (anyone); a free tag used by three becomes a proposal; folder auto-tag rules; merges keep aliases; the Clerk proposes from the graph** | managed only |
| D13 | The API, keys and K7 | **A key acts as its person (60/min, 5,000/day); the routes of §8; four shares — `folders_index`, `documents_index`, `document_text`, `document_link` (a signed 10-minute URL for bytes)** | an application-wide key — rejected: visibility must be a person's |
| D14 | Agents | **Expert and Filing Clerk hired on install; the pause list of §5; the Clerk Monday 06:50** | one agent |
| D15 | Default or on request; visibility of the repository | **On request, not a default; the repository private until the owner says** | a fifth default install (K21's list): defensible — every business keeps files — the owner's call |
| D16 | Ports and the division | **8190 / 8841 / 8842**, scratch 8701–8707; **the Spaces division**: the planning model builds K31, Phase 0, Phase 1, Phase 2, slice 1 and the exemplars (slice 2 uploads, slice 5 the graph, with 3 and 4 between); Sonnet 5.5 builds slices 6–9 and Phases 4–5 | — |

**Open questions for the owner beyond the table**: (a) should the siblings' attachments (Help Desk, Projects, HR, Spaces) *eventually*
live in DocCloud — a later decision that would make `document_link` their door; (b) WebDAV's priority — first Extended item, or v1
(a day more); (c) the storage directory: `/srv/apps/doccloud/storage` or a mount the owner names at install (`DC_STORAGE_DIR`).

## 14. Ports, names and files

- Repository `/srv/apps/doccloud` (origin `github.com/maludb/maludb-os-doccloud`, private); record database `<tenant>_doccloud`
  (`subello_doccloud`); roles `doccloud_rw`, `doccloud_records_ro`, `doccloud_activity_ro`; **memory database
  `<tenant>_doccloud_memory`** (`subello_doccloud_memory`; schema and role `doccloud_mem`; `maludb_core` + `enable_memory_schema`
  + `grant_memory_access` + `GRANT maludb_secret_consumer`; its API token minted by `ROOT_STEPS.sh` into `config/.env` as
  `MALUDB_DC_API_TOKEN`); the tenant's memory (`certstudy_memory`) keeps the activity episodes tagged `doccloud`.
- `doccloud.<domain>` (`vhost.label = "doccloud"`); `APP_INTERNAL_PORT=8190`, `MCP_RECORDS_PORT=8841`, `MCP_ACTIVITY_PORT=8842`
  **all three in `env.required`** and written to `config/.env` before `apply`; `deploy/` files are templates (`{{DOMAIN}}`,
  `{{APP_DIR}}`, `{{APP_KEY}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`); the vhost sets
  `LimitRequestBody` 70 MB, PHP `upload_max_filesize` 64M / `post_max_size` 72M (a part is 8 MB; the API's one-request upload 64 MB).
- Units: `doccloud-records-mcp`, `doccloud-activity-mcp`, `doccloud-activity-ingest` (+ timer), `doccloud-directory-sync` (+ timer),
  `doccloud-worker` (+ timer, every minute: upload-session expiry, text extraction, thumbnails, the index catch-up, graph jobs under
  the budget, versions and trash windows, blob freeing, link and lock expiry, exports, the outbox, quotas, proposals, dispatch
  retries).
- `maludb-os.json`: `catalog_key doccloud`, `name "DocCloud"`, `business_area "Operations"`, `category storage`, `icon
  feather-hard-drive`, `criticality medium`, `vhost.label doccloud`, no `scopes`, `sso`, `directory {reads: true, writes: false}`,
  `assistant {command_bar: true, agent: expert}`, `agents [expert, clerk]` each `hired_on_install: true`, `approvals` (§5),
  endpoints (incl. `/s/` and `/r/` as `ui`, `auth_kind none`), services, `shares` (four; `document_text` 60 s), `reads` (none).
- Env: the contract's required keys plus `MALUDB_DC_API_URL`, `MALUDB_DC_API_TOKEN`, `DC_STORAGE_DIR` (default `storage`),
  `DC_MAX_DOCUMENT_BYTES` (may only lower the setting), `DC_PDFTOTEXT`, `DC_PDFTOPPM`, `MALUMAIL_API_KEY`, `MAIL_FROM`,
  `MAIL_FROM_NAME` (optional).
- Vhost allow-list (public): `/s/`, `/r/`, `/api/v1/`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/doccloud.json` (made by the installer), db/176 (K31).
- Proof scratch ports: 8701–8707; scratch databases `dc_dev`, `dc_proof`; fixtures `tests/extract/` (Knowledge's synthetic
  documents copied; no third-party text committed) and `tests/fixtures/timing/` (a generator for the 200,000-document set).

## 15. The owner's answers

**2026-10-09 — every recommendation of §13 taken, and the three open questions answered**, walked through one by one.

| # | Decision | Where it lives |
|---|---|---|
| D1 | **"DocCloud", key `doccloud`, DNS label `doccloud`**, databases `<tenant>_doccloud` and `<tenant>_doccloud_memory`, roles `doccloud_rw` / `_records_ro` / `_activity_ro`, prefix `dc_` | `maludb-os.json`; §14 |
| D2 | **Four roles**: Guest, Member (`user`), Curator, DocCloud admin; group ownership a row; the admin sees personal libraries, logged `library.admin_view` | §3; `dc_roles`; `app_roles` |
| D3 | **A memory database of its own**, one namespace `dc`, the token read in `graph.php` alone; document content never enters the tenant's agent memory | §6.3; §14 |
| D4 | **The expert answers about the store and runs keyword search; `graph_find` only when asked**; never an answer composed from a document's words (Knowledge's, through K7) | §5; `os/expert.md` |
| D5 | **Ownership + library base + inherited additive rows + restrict; four levels (view, upload, edit, manage); `upload` sees only its own; guests by name; a link one thing; the admin everything, logged; the scale rule** | §3; `docs/build-specs/visibility.md` |
| D6 | **New canonical `groups` / `group_members`** — custom or a department mirror; a group library created with the group and surviving it | §6.2; §6.4 |
| D7 | **PostgreSQL full text on Spaces' `search_index`** (title A, tags and description B, 200,000 characters of text C), `websearch`, trigram on names; no second server | §6.1; slice 4 |
| D8 | **Three graph policies per library and folder — `none` / `index` (default, `remember`, no model) / `full` (queued model extraction under 100 MB of text a month via K24, estimate first)**; replace on a new version, forget on purge | §6.3; `docs/build-specs/document-graph.md` |
| D9 | **Resumable 8 MB parts; 2 GB per file (a setting); sha256-deduplicated blobs with a reference count; `finfo`; executables, HTML and SVG never inline; folders dropped as trees** | §6.1; `docs/build-specs/uploads-and-versions.md` |
| D10 | **Versions: all for 30 days, then the last 10, the current always; the trash 30 days then purge; locks 24 h, breakable by a manager** — all settings | §6.2; slice 6 |
| D11 | **Links expire in 30 days by default** ("never" only when the admin allows), download allowed by default, optional password, every open logged; **file requests upload-only and blind**; both pause for an agent | §4; slice 3 |
| D12 | **Managed + free tags**; a free tag used by three becomes a proposal; folder auto-tag rules; merges keep aliases; the Clerk proposes from the graph | §6.2; slice 4 |
| D13 | **A key acts as its person** (60/min, 5,000/day); the routes of §8; **four K7 shares** `folders_index`, `documents_index`, `document_text`, `document_link` (a signed 10-minute URL) | §8; slice 7 |
| D14 | **Expert and Filing Clerk** hired on install; the pause list of §5; the Clerk Monday 06:50 | §5; `agents[]` |
| D15 | **On request, not a default; the repository private** until the owner says | §12; the org |
| D16 | **Ports 8190 / 8841 / 8842**, scratch 8701–8707; **the Spaces division** — the planning model builds K31, Phases 0–2, slice 1 and the exemplars (slice 2 uploads, slice 5 the graph, with 3 and 4 between); Sonnet 5.5 builds slices 6–9 and Phases 4–5 | §14; `CLAUDE.md` |
| (a) | **The siblings' attachments stay theirs; "save to DocCloud" is each sibling's Extended item** (HD4, P4, SP3, H3: a `reads[]` on `document_link` and an upload through the API as the acting person) | §8; §11 |
| (b) | **WebDAV is the first Extended item**, not version 1 | §11 |
| (c) | **The bytes live under the install by default** (`/srv/apps/doccloud/storage/blobs`), `DC_STORAGE_DIR` pointing at a mount when the owner names one at install; `ROOT_STEPS.sh` makes it | §6; §14 |

## 16. State

**2026-10-10 — THE GRID, THUMBNAILS, VIDEO AND HIDDEN PREVIEWS** (the owner's request after Phase 4; `docs/build-specs/grid-and-media.md`,
decisions G1–G4 the owner's, built in the worktree `grid-media` and merged). The folder listing as a grid beside the list; video thumbnails
and lengths through ffmpeg (installed on this host; `deploy/ROOT_STEPS.sh` step 0c), JPEG thumbnails turned upright by EXIF, the worker's
queue moved from files on disk to `document_versions.thumb_state`; a video streamed in the document page's player; **"Hide preview"** — a
row in the NEW table `document_preview_hidden`, for everyone, flipped by anyone with edit (`document_preview_hide` / `_show`, free for
agents, the expert granted both) — a screen's privacy, never a wall. **db/023**. Two streaming defects fixed on the way: PHP held the
session's lock for the whole of a stream or download (the person's other pages waited — proven: a folder page took 30 s beside a stream
without the fix, 0.2 s with it), and every range request of a player wrote an activity row (now one per viewing). Also found: `.gitignore`'s
`storage/` had swallowed `html/curate/storage/export.php` (slice 9's `storage_export`, never committed — now anchored to `/storage/`);
`tests/setup_dev.sh` would have reset the cluster roles' passwords from a worktree of the installed application (it reuses the
install's now). Proof: `tests/phase3/media/run.sh` — thumbs 23 + actions 30 + grid 25 + serving 29 + browser 23 (also under a real
Apache); every earlier suite re-run. **Observed on this host 2026-10-10: DocCloud is installed** (the kernel's application 69,
`subello_doccloud`, the vhost, both MCP units and the three timers running); db/023 applied to it with the merge.

**2026-10-10 — PHASE 3 and PHASE 4 COMPLETE; Phase 5 is the owner's.** Slices 6–9 and Phase 4 built by Sonnet 5.5 workers one at a time, each
verified by the planning model's rerun and committed: slice 6 the trash, locks, quotas and the worker's windows (0a9a945; 323 checks; db/021 —
used_bytes now follows a move); slice 7 the API, keys and the K7 shares (d090660; 413 checks; fixed: a link's token reached activity_log.route);
slice 8 the agents, the Filing Clerk and notifications (0524d84; 433 checks; db/022 — FIXED A LEAK: with no Clerk named, dc_clerk_report()'s guard
was NULL and any caller could read the whole report); slice 9 the homes, curate and admin (b4f2034; 457 checks) — **68/68 screens and 95/95
actions built, 23 paused, no stub**; Phase 4 the two MCP servers (records 73 tools — the surface's "71" was a miscount — and activity 7; the
bridge `/internal/bridge.php`, 404 on the public vhost; 606 checks, also under `DC_ENGINE=1` and a real Apache; the registry accepted by the
kernel's own loader). Decisions each worker took are under its spec's "Open questions"; those for the owner to confirm are listed in the
repository's CLAUDE.md. **Phase 5 (the owner's):** `deploy/ROOT_STEPS.sh` — pin the ports 8190/8841/8842, the storage directory, the memory
database `<tenant>_doccloud_memory` and its token, the K24 credential, `bin/app_install.php apply` (twice: the roles are read from `app_roles`
once the records server answers; db/001–022 applied), DNS/TLS for `doccloud.<domain>`, hire `expert` and `clerk` and name both in DocCloud's
admin → agents settings, the end-to-end proof of §10.

**2026-10-09 — THE HANDOFF POINT: Phase 2, slices 1–5 BUILT by the planning model** (commits 3d452f2, 9315b00, db91ebc, ab59a23, 58eef53,
f0f082a; not pushed). Phase 2 the shell (php -S and a real Apache: 161 + 71 + 74 + 44 + 22 + 12 + 14, vhost 47); slice 1 libraries, groups and
folders, THE CRUD EXEMPLAR (59 + 40 + 54 + 21 + 35 + 24); slice 2 uploads, versions, blobs, serving, preview and extraction, THE FIRST EXEMPLAR
(45 + 36 + 19 + 28 + 38 + 26 + 18); slice 3 access, sharing, links, requests and the public doors (234 over nine actors); slice 4 tags and keyword
search (51 + 27 + 13 + 45 + 15 + 31 + 14); slice 5 the document graph, THE SECOND EXEMPLAR (without the engine 31 + 5 + 6 + 5 + 24 + 25 + 17; with
the real MaluDB API on a scratch memory database 31 + 31 + 27 + 17 + 36 + 27 + 24). db/017–020 appended. Each slice's decisions are under its
spec's "Open questions". The slice 5 builder was cut off by a usage limit and a second builder finished it (the defects it found: the
excerpt helper never loaded, the god-nodes field names, the path's string nodes, the chip links, the budget notice's dedupe).
**Next — Sonnet 5.5 workers, one slice at a time, each verified by the planning model's rerun before the next:** slice 6
(`trash-locks-quotas-worker.md`), slice 7 (`api-keys-k7.md`), slice 8 (`agents-clerk-notifications.md`), slice 9 (`home-curate-admin.md`),
then Phase 4 (the two MCP servers, `docs/doccloud-mcp-tool-surface.md`). Phase 5 is the owner's.

**2026-10-09 — Phase 0 COMPLETE and Phase 1 WRITTEN, the same day as the plan** (the owner: "proceed doing the rest of the early tasks … stop
when we are ready to delegate to Sonnet 5.5" — so Phase 1 was built on and its checkpoint stays open for the owner to read; nothing of it
is PHP). **Phase 0:** db/001–015 (the kit's five from Knowledge; libraries/groups/folders, the file store, the access rows and the walls,
tags, the keyword index, links and requests, the graph's bookkeeping, the reused tables, the reads and the worker's passes, 33 views)
proven by `db/proof/phase0_proof.sql` (**160 checks**: every actor, the ownership rule, inheritance and restrict, dedupe, versions and
their windows, the trash and the purge freeing a blob, locks, tags and merges, the index honouring the wall for four callers, links,
requests and rates, the graph's queue, budget and THE WALL over the engine's answers, keys, notifications, the read roles seeing views
only) and `db/proof/phase0_timing.sql` (the 200,000-document set — the numbers below); the kit from Inventory/Knowledge proven without a
kernel (`tests/phase0/run.sh`, **71**); the extractor copied from Knowledge (`tests/extract/run.sh`, **34**); **`app/doccloud/graph.php`
proven against the real MaluDB API on a scratch memory database** (`tests/phase0/engine.sh`, **74**; `tests/phase0/engine/FINDINGS.md`,
§12); `maludb-os.json`, `os/{expert,clerk}.md`, nine skills, `deploy/` with `ROOT_STEPS.sh`; the installer's `plan` clean. Found on the way:
a proof that runs an action and inspects the result in one expression fails — an uncorrelated subquery is evaluated before the function
beside it (Spaces' lesson, met again); a bulk load must skip the per-row counts (`app.dc_bulk`, `dc_recount_bulk()`); a trigger that
copies inherited rows must guard against its own recursion (`app.dc_inheriting`); a read over 200,000 rows must put the wall AFTER an
indexed cut (the trigram name search, recents, duplicates — the curator's report runs with no per-row wall). **Phase 1:**
`docs/doccloud-mcp-tool-surface.md` (71 records tools, 7 activity), `docs/doccloud-action-manifest.md` (67 screens, 95 actions, 23
paused), `mcp/action_registry.json` (`--check` green; `bin/sync_approvals.php` in step), the three build specs
(`uploads-and-versions.md`, `visibility.md`, `document-graph.md`), ten slice specs (`sso-shell` + nine; `uploads-versions` and `graph`
the exemplars), `tests/phase1/check_specs.sh` (**55**: every manifest row claimed once, every tool named, every approval a manifest
action), `docs/phase1-consistency.md`; **db/016** adds the reads Phase 1 asked for (the folder path text, a page and an excerpt of a
version, the shares' reads, the graph filter with the find's filters, the Clerk's settings and report). **The scale rule, measured** (`tests/phase0/timing.sh`, 200,000 documents in 2,460 folders of 60 libraries, 620 explicit access rows,
as an ordinary member with a few groups and a department; the planning host, nothing cached): the principals 10 ms · my libraries 33 ·
a folder listing page of 100 (a group library) 3 · (a shared library) 16 · a keyword search page matching 20,000 documents 231 (it was
21,000 before the batch wall `dc_visible_among()`) · the same with filters 8 · the nearest name by trigram 1,107 (every synthetic name
is `doc-N`; a GiST `<->` scan, the index-ordered worst case) · the graph filter over 150 candidates 270 · shared with me 11 ·
`mcp_documents` for one folder 99 · recents 48 · the admin's storage by library 374 · duplicates 627 · a folder move with its subtree
11 — the ceiling is 2,000 ms a step and every step is under it. **Decisions taken in the
specs, for the owner to confirm:** the Clerk's reach is ONE report read (`dc_clerk_report()`: names, counts, ids — never content), not
membership of every library; the `document_link` signing key is derived from `ACTION_TOKEN_KEY` (`hash_hmac('sha256',
'doccloud-link-signing-v1', …)`), nothing new to install; the routing form is the vhost's (`/s/`, `/r/`, `/d/`, `/f/{id}` →
`/folders/{id}`, a document's page `/documents/{id}`); GET navigations are not actions; `key_revoke` never pauses. **Next:** Phase 2
(`sso-shell.md`), slice 1, slice 2 (THE FIRST EXEMPLAR), slices 3–4, slice 5 (THE SECOND) — the handoff point.

**2026-10-09 — planned and APPROVED the same day (§15: D1–D16 and (a)–(c), every recommendation).** The research of §0 done: the field (Dropbox, Box, Nextcloud, Drive),
the estate's canonical tables surveyed in every sibling's `db/*.sql` (Spaces' `search_index` and `page_permissions`, GL's secure-link
shape, Help Desk's `tags`, Knowledge's extractor and memory-database pattern reused; `groups`, `documents`/`versions`/`blobs`,
`folders`/`folder_access` and `document_tags` new canonical), MaluDB read for what it gives (no full-text route; no metadata or ACL
filter on any search route; `remember` without a model; `documents` with a model call per ~1,800 characters, synchronous; several
memory databases per tenant). Repository `maludb/maludb-os-doccloud` created private and cloned to `/srv/apps/doccloud`. **K31 built**
the same day as the kernel's db/176 (the catalog row under Operations, category `storage`). **Next:** Phase 0 (schema and proofs, the
kit, the extractor, the engine proof, `maludb-os.json`, agents, skills, `deploy/`) → Phase 1 for approval → Phase 2 → slice 1 →
the exemplars (slices 2 and 5) → the handoff to Sonnet 5.5.
