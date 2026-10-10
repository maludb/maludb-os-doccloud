# DocCloud — CLAUDE.md

This repository is the **DocCloud** application of the MaluDB Business OS (`github.com/maludb/maludb-os-doccloud`, private until
the owner says otherwise; local clone and install path `/srv/apps/doccloud`; catalog key `doccloud`; DNS label `doccloud`). The
kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the directory, the agents, approvals
and the token ledger; this application owns the business's **files**: libraries (personal, group, shared) of folders and
documents with versions, de-duplicated blobs, tags, sharing by principal with inheritance (members, **groups**, departments,
agents, guests, applications), secure links and file requests, a **keyword search** in PostgreSQL, and a **dedicated document
graph** in a MaluDB memory database of its own. It is what Dropbox, Box and Nextcloud are to a business (the owner, 2026-10-09).

## Read first
1. `docs/doccloud-design.md` — the plan (Phase 0): the research (§0: the field, the estate's canonical tables, what MaluDB gives
   and lacks), what it is and is not (§1), the actors (§2), **roles and the walls** (§3 — ownership, library base levels,
   inherited additive rows, restrict, the four levels, the scale rule), the doors (§4), the agents and what pauses (§5), the
   memory model (§6 — uploads in parts, blobs, extraction, the keyword index, folders, groups, tags, versions, the trash, **the
   document graph and its three policies**, the tables, the shared-schema decisions), the question inventory with every tool
   named (§7), the kernel's part, the API and K7 (§8), the screens (§9), the build order (§10), Extended (§11), what the kernel,
   MaluDB and the siblings owe (§12), the decisions D1–D16 (§13), ports and files (§14), the owner's answers (§15), the state (§16).
2. The plugin `maludb-os-integration` (`~/maludb-os-integration`, skill `os-integration` and its references, `shared-schema.md`
   included) and `htmx-php-builder` 0.8.0 (`~/htmx-php-builder`: `os-application`, `php-patterns`, `design-system` with the record
   picker, `mcp-servers`, `chat-actions`).
3. `/srv/apps/spaces` (sharing by principal with inheritance — `page_permissions`, `permission_root_id`; `search_index`; the worker)
   and `/srv/apps/knowledge` (files in, the extractor `app/knowledge/extract/`, a memory database of its own, `engine.php`,
   `deploy/ROOT_STEPS.sh`) — the nearest built exemplars; `/srv/apps/consultant_tracking` for the kit.
4. The MaluDB API (`~/maludb-python-api-server`: README, `app/routers/memory.py`, `agent_memory.py`, `graph.py`, `documents.py`)
   and the kernel's `docs/build-specs/kernel-application-model-credentials.md` (K24).

## Rules that bind here
- `htmx-php-builder` skills govern the PHP; `os-integration` governs the fit. Where the two conflict, the integration contract
  wins: **no password, no login form, no account, no model key, no Twilio key, no shared tables, no actions server of our own, no
  writes to the directory, no writes to another application** (K7 is reads only).
- **Visibility is DocCloud's, enforced in SQL, never delegated** — not to the engine, not to PHP. The owner sees their own
  document; the library's base level; inherited additive rows and restrict; `upload` sees only its own; guests only by name; a
  link one thing; the admin everything, logged. `dc_can_see_document()` / `dc_document_level()` are the one gate behind every
  screen, view, tool, share, link, export and graph result. **The scale rule**: no function returns the caller's whole visible set
  per statement; the caller's principals once, then indexed per-row checks.
- **The document graph is a memory database of its own** (`<tenant>_doccloud_memory`), read in ONE file, `app/doccloud/graph.php`;
  document content never enters the tenant's agent memory; **the default ask-me-anything never searches document content** (the
  owner's rule): the expert answers about the store and runs keyword search; `graph_find` only when asked; an answer composed from
  a document's words is Knowledge's (a folder shared with it through K7). Every engine result is a document id judged by the wall
  before ranking or excerpting. A missing token answers `graph_unavailable`, never a crash; keyword search needs no engine.
- **The graph policy decides spend**: `none` / `index` (the default — `remember`, no model call) / `full` (model extraction, queued
  one document at a time under the monthly text budget, through K24). Never ingest with a model outside the queue and the budget.
- **Bytes are blobs**: content-addressed by sha256, de-duplicated with a reference count, under `DC_STORAGE_DIR`; `finfo` decides
  the type; executables, HTML and SVG are stored but never served inline; `storage_path` never leaves the database.
- **Vocabulary: "agent" means an OS AI agent**, always. A *member* is a kernel member mirrored here; a *guest* an external holding
  Guest; a *group* a named set with owners (custom or a department mirror); a *library* a root (personal, group, shared); a
  *folder* a tree node; a *document* one file with versions; a *version* one upload; a *blob* the bytes; a *tag* a label; a
  *share* an access row; a *link* a token; a *file request* an upload-only link; a *lock* an edit claim; the *trash* the window.
- **The checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + action manifest + the three build
  specs + slice specs together (Phase 1). Schema changes after that are numbered additive migrations. **Never modify a migration.**
- **The database is the referee**: a name unique among live siblings; `path` and `access_root_id` by trigger; a version's blob
  counted; a purge forgets the engine's document within the minute; a link's token hashed, never stored; a lock expires; a quota
  refuses at upload start. PHP presents refusals as field errors.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right (`require_right()`, SQL
  `dc_has_right()`) + the level on the thing (`dc_document_level()`, `dc_folder_level()`) + `log_activity()` + `emit_action_status()`;
  a create's `location` ends in the record id. **A document's text, a passage, a query over 120 characters, a token, a link or a
  password are never in a log payload.**
- The kernel's rules copied here: activity `source` is `web` for the UI, `api` for a key, `agent` under a run token, `cron` for
  the worker, `portal` for a link holder; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see; the
  shared-schema rule — survey the siblings before any `CREATE TABLE`, reuse verbatim, extend by appending, record every table as
  read / reuse / new (design §6.4).
- Commit after every finished step on `main` in the repository's message style; never push unless the owner asks.

## State
2026-10-10 — **PHASE 3 AND PHASE 4 COMPLETE** (design §16): 68/68 screens, 95/95 actions, records MCP 73 tools + activity 7, migrations
db/001–022. Built by the planning model through slice 5, by Sonnet 5.5 workers for slices 6–9 and Phase 4, every one verified and committed.
**Phase 5 is the owner's** (`deploy/ROOT_STEPS.sh`, then `bin/app_install.php apply` twice, DNS/TLS, the hires, naming the expert and the Clerk
in admin → agents, the end-to-end proof of design §10). Nothing pushed.
**For the owner to confirm (decisions taken in the specs):** the Clerk's reach is one report read; the link key derived from ACTION_TOKEN_KEY;
a signed share download URL reusable within its ten minutes; the API's upload answers 409 on a same name unless told version/keep both;
MaluMail 4xx fails at once, 5xx retried twice; a cancelled dispatch is recorded `refused`; the expert and the Clerk named in settings.
**Owed outside this repository:** the kernel's `/api/v1/apps/connections.php` (the admin's connections screen falls back without it);
M10/M11/M12 upstream in MaluDB. Proofs (one at a time — ports 8701–8707): `tests/phase0/run.sh`, `timing.sh`, `engine.sh`,
`tests/extract/run.sh`, `tests/phase1/check_specs.sh`, `tests/phase2/run.sh`, `tests/phase3/slice{1..9}/run.sh` (slice 5 also `DC_ENGINE=1`),
`tests/phase4/run.sh`.
