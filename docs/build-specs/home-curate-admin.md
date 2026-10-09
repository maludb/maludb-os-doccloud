# Build spec: home, curate, admin, reports and tokens (slice 9)

What exists at the end: the **home** is each role's (a new member's one button; a member's libraries, recent, shared this week, open requests,
links expiring, the Clerk's note; a curator's untagged, duplicates and stale shares; the admin's storage, queue and spend); the **Curate** area
holds groups, shared libraries, tags and rules, ownership transfer (a leaver's documents to someone), the storage report and the search report,
the Clerk's proposals; the **Admin** area holds settings, quotas, graph admin, every link, every library (personal ones logged), every key, the
agents, the kernel's connections, the worker; *My settings* holds the preferences, tokens and keys; a document's comments have a composer.
Every action here has its own handler; the registry reads every screen and action of the manifest as built.

Schema (never modified): everything of db/001–016; `dc_ownership_transfer()` (db/007); `dc_storage_by_library()`, `dc_storage_by_owner()`,
`dc_search_gaps()`, `dc_find_links()` (db/014); `notes` (db/013); `mcp_settings`, `mcp_libraries`, `mcp_tokens`, `mcp_share_links`, `mcp_search_log`,
`mcp_notes` (db/015).

## Divergences from the design
1. **Comments are `notes`** on a document or folder (GL's canonical; `record_type` document|folder) with a composer on the document page
   (`note_add`, `edit`+ … no: `view`+ may comment (design §3: `view` — "see, preview, download, comment")); `note_delete` by its author or a manager.
   A mention is Extended. The owner is told `comment`.
2. **Ownership transfer** (`ownership_transfer`, `deletion` for agents): a leaver (an inactive member, or anyone a curator names) → a new owner,
   optionally one library only; logged per document count; the new owner told `ownership_received`.
3. **The settings form** is split by right: Settings (`settings.manage`: business name, time zone, link defaults, the free-tag threshold, the
   stale-share days, the thumbnail and preview switches), Limits (`quotas.manage`: per-file ceiling, text ceiling, upload part size, session hours,
   key rates, link rates), Retention (slice 6's screen, linked), Graph (slice 5's, linked).
4. **The connections screen** lists the K7 connections the kernel holds for DocCloud (read from the kernel's `GET /api/v1/apps/connections.php`
   with the application token when the route exists; else the manifest's shares and the sentence "approved in the kernel: `bin/app_connection.php`").
5. **"All links"** lets the admin revoke any (`link_revoke`, slice 3's handler with `links.all`).

## Who may do what
| Area | Right |
|---|---|
| Curate index, storage report, search report | `reports.storage` / `reports.search` (curators) |
| ownership transfer | `ownership.transfer` (curators); `deletion` for agents |
| Admin index, settings | `settings.manage` |
| limits | `quotas.manage` |
| all links, all libraries, all keys | `links.all` / `library.admin_view` / `links.all` (keys: admin) |
| connections, agents, worker | `agents.settings` / `settings.manage` |
| comments | `view`+ on the thing; delete: the author or `manage` |

## Screens (375 px is the design for Home; the reports usable at 375, designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | by role: **new member** — "Upload your first file"; **member** — my libraries as cards (used of quota), Recent (8), Shared with me this week, my open file requests, my links expiring this week, the Clerk's last note, my open proposals; **curator** adds untagged this week, duplicates (top 5), stale guest shares; **admin** adds storage by library (top 5), the graph queue and the month's spend, failed extractions; **guest** — Shared with me |
| `curate` | `/curate` | the index: Groups · Shared libraries · Tags and rules · Ownership · Storage report · Search report · Proposals, each with a count |
| `curate-ownership` | `/curate/ownership` | inactive members holding documents (`dc_storage_by_owner()` joined with `members.status`), any member (the `member` picker), the new owner (picker), optionally one library; a confirm with the count; posts `ownership_transfer` |
| `curate-storage` | `/curate/storage` | `dc_storage_by_library()` as a table with bars; `dc_storage_by_owner()`; the disk (`disk_free_space()` of the storage directory) vs the sum of blobs; duplicates' wasted bytes; CSV export |
| `curate-search` | `/curate/search` | `dc_search_gaps()` (what found nothing), the top queries, searches a day (from `mcp_search_log`), keyword vs graph |
| `admin` | `/admin` | the index: Settings · Limits · Retention · Quotas · Graph · Links · Libraries · Keys · Agents · Connections · Worker · the health line (`storage`, `graph`, the units' last pass) |
| `admin-settings` | `/admin/settings` | Settings and Limits as two forms (divergence 3), each field with its bounds |
| `admin-links` | `/admin/links?live=&kind=` | every link (`dc_find_links()` with `links.all`), suspicious flagged, Revoke |
| `admin-libraries` | `/admin/libraries` | every library (`mcp_libraries` for the admin): kind, name, owner/group, used, quota, documents; opening a personal one logs `library.admin_view` |
| `admin-keys` | `/admin/keys` | every key (`all_keys()`): person, label, limits, today, last used; Revoke |
| `admin-connections` | `/admin/connections` | divergence 4 |
| `settings` | `/settings/` (Phase 2's, completed) | prefs, the text channel, Tokens (mcp), Keys (slice 7), my activity |
| the document page gains | `/d/{id}` | the comments composer (`note_add`), delete |

## Files (exactly these)
- `html/index.php` (the home, replacing Phase 2's placeholder sections with `home_summary()` per role) · `html/curate/index.php` (`curate`, replaces the placeholder) ·
  `html/curate/{ownership,storage,search}.php` (`curate-ownership`, `curate-storage`, `curate-search`) · `html/curate/ownership-save.php` · `html/curate/storage.csv.php`
- `html/admin/index.php` (`admin`, replaces the placeholder) · `html/admin/settings.php` (`admin-settings`) · `html/admin/settings/{save,limits}.php` · `html/admin/{links,libraries,keys,connections}.php` ·
  `html/admin/keys/revoke.php` · `html/d/notes/{add,delete}.php` · `html/f/notes/{add,delete}.php`
- `app/features/home/queries.php` (completed), `app/features/curate/{queries,write}.php`, `app/features/admin/{queries,write}.php` (completed), `app/features/notes/{queries,write}.php`
- `app/views/home/{dashboard,partials/member,partials/curator,partials/admin,partials/guest}.php`, `app/views/curate/{index,ownership,storage,search}.php`,
  `app/views/admin/{index,settings,links,libraries,keys,connections}.php`, `app/views/files/partials/comments.php`
- `tests/phase3/slice9/{run.sh,lib.php,home.php,curate.php,admin.php,notes.php,json.php,browser.mjs}` — the worlds of slices 1–8 composed

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` (per role, the sections above) · `curate_counts(PDO): array` · `inactive_owners(PDO): array` · `storage_report(PDO): array` · `search_report(PDO, string $since): array`
- `admin_index(PDO): array` (counts + health) · `all_links(PDO, array $filters): array` · `all_libraries(PDO): array` · `all_keys(PDO): array` · `connections(PDO): array`
- `find_notes(PDO, string $kind, int $id): array`
- writes: `ownership_transfer(PDO, int $from, int $to, ?int $libraryId, int $by): int` · `settings_save(PDO, array $f, int $by): array` · `settings_save` (the limits) · `note_add(PDO, string $kind, int $id, string $body, int $by): int` · `note_delete`

## MCP tools this slice makes real
Also: `record_history`, `actor_timeline`, `recent_activity`, `activity_search` (the surface's rows this slice's screens read).
`storage_by_library`, `storage_by_owner`, `search_gaps`, `find_keys` (all, admin), `get_settings`, `find_notes` (the records server's), `record_history` (activity; every slice's rows).

## Manifest rows claimed
Screens (9): `curate`, `curate-ownership`, `curate-storage`, `curate-search`, `admin`, `admin-settings`, `admin-links`, `admin-libraries`, `admin-storage`
Actions (6): `settings_save`, `retention_set`, `ownership_transfer`, `note_add`, `note_delete`, `storage_export`

Folded: `limits_save` is `settings_save` (one settings form). `home` and `settings` are the shell's screens, completed here. `admin-keys` and `admin-connections` are slice 7's.

## Activity log events
`ownership.transfer` (with the count), `settings.save`, `limits.save`, `note.add|delete`, `library.admin_view`, `report.storage|search`, `screen.view`.

## Notifications this slice queues
`ownership_received` (the new owner), `comment` (the document's owner, not the author).

## Status vocabulary
Home cards as the slices'. Ids: `home-section-{name}`, `curate-index`, `ownership-form`, `storage-table`, `search-gaps`, `admin-index`, `admin-health`, `settings-form`, `limits-form`, `comments-list`, `comment-form`.

## Out of scope for this slice
Nothing of version 1; Extended is §11 of the design.

## Proof (`tests/phase3/slice9/run.sh`: scratch database `dc_dev9`; the worlds of slices 1–8 composed; ports as slice 5; Playwright; the registry `--check` reads every screen and action of the manifest as built)
1. The home per role: the new member's button; the member's sections with seeded data; the curator's three more; the admin's three more; the guest's Shared with me; every card's link resolves.
2. Ownership: an inactive member's 12 documents to another (optionally one library), logged with the count, the new owner told; an agent's call pauses; the old owner no longer manages them, the new one does.
3. The storage report's numbers equal the proof's sums; the CSV; the search report clusters the empty searches and counts keyword vs graph.
4. The admin: settings and limits saved within bounds and refused outside (in words, on the field); an agent's `settings_save` pauses; all links with a revoke; all libraries with `library.admin_view` logged once per session; all keys with a revoke; connections from the fake kernel's route and the fallback sentence; the health line.
5. Comments: a viewer adds one on a document, the owner is told, the author deletes theirs, a manager deletes another's, a guest comments on the document shared with them.
6. JSON mode on every screen and handler; the run-token gate.
7. Browser at 375 and 1280: the four homes, the curate index, the storage report, the admin index and settings; no console errors.
8. Registry `--check`: every screen of the manifest `built` (no stub left), every action `built`, the 23 approvals in step; `tests/phase3/*/run.sh` for slices 1–8 re-run green on their own databases.

## Open questions
None.
