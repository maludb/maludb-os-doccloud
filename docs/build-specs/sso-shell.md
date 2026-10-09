# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks DocCloud on the launcher — or types `doccloud.<domain>` on a phone — and lands, signed in, on a
home that is finished though it holds no file yet: **one button for a new person ("Upload your first file")**, their libraries as cards for
everyone else, the bell, the command bar, the bottom tab bar; their personal library exists before they look for it; the mirror refreshes
every minute and department groups follow it; every request is logged; the command bar answers through the kernel; the record picker is in
the shell for every later form. **The kit is in the repo already (Phase 0) and proven without a kernel (`tests/phase0/run.sh`, 71 checks,
the schema proof's 160 among them)**; this phase wires the shell around it and proves it under Apache at 375 and 1280. It is copied from
Knowledge (closest: files in, a memory database of its own, the picker in the shell), Spaces and Consultant Tracking (the kit's lineage);
every file keeps its sibling's contract so sign-on, the directory sync and the MCP servers interoperate.

Schema it runs on (db/001–015, never modified): `members` (+ generated `is_agent`), `departments`, `department_members`, `directory_sync_state`,
`sso_nonces`, `member_sessions` (db/001); `activity_log` (+ `library_id`, `document_id`, `folder_id`, `token_id`), `activity_ingest_state`
(db/002); `mcp_access_tokens` (+ the rate columns; a key is a PERSON's) + `mcp_resolve_token()` (db/003); `dc_rights`, `dc_roles`,
`dc_role_rights`, `dc_has_right()`, `dc_is_admin()`, `dc_is_guest()`, `dc_member_is_guest()`, `dc_is_member_here()`, `mcp_app_roles` (db/004);
`dc_settings`, `dc_setting_int/text/bool()`, `dc_resolve_key()`, `dc_rate_ok()`, `dc_key_rotate()` (db/005); `groups`, `group_members`,
`libraries`, `folders`, `dc_library_ensure_personal()`, `dc_groups_sync_departments()`, `dc_folder_path()` (db/006); `documents`,
`document_versions`, `blobs`, `document_text`, `upload_sessions`, `dc_blob_claim()`, `dc_version_add()` (db/007); the walls
`dc_my_principals()`, `dc_folder_level()`, `dc_document_level()`, `dc_can_see_*()` (db/008); `notes`, `notifications`, `notification_prefs`,
`notification_outbox`, `dc_notify()`, `worker_passes`, `favourites` (db/013); `dc_my_libraries()`, `dc_recent_documents()` (db/014); the
`mcp_*` views (db/015).

## Files
**In the repo (proven by `tests/phase0/run.sh`):**
- `app/bootstrap.php` (env from `config/.env` — an exported variable wins; session cookie `DCSID`; `json_mode_begin()`; the acting member on
  the PDO connection; the action-token / approval-replay path; the per-request mirror and session re-check), `app/db.php`, `app/http.php`,
  `app/directory.php` (the mirror appliers; `mirror_apply_roles()` keeps only `dc_roles` keys; `mirror_apply_access()` for `access[]`),
  `app/mail.php`, `app/partial_update.php`.
- `app/auth.php`: the verifiers (hand-off token, claims, sign-out notice, action and run tokens), sessions, `has_right()`, `require_right()`,
  `is_dc_admin()`, `is_guest()`, `require_admin()`, `require_super()`, `require_human()`, `require_visible()`.
- `app/handler.php`: `dc_handler_begin()` (POST, login, CSRF — in that order), `req_val()`, `request_list()`, `dc_yes()`, `dc_int()`, `dc_ref()`,
  `dc_guard()` (a DomainException or the database's RAISE → 422 on the field; a duplicate → 422), `dc_refuse_fields()`, `dc_land()`,
  `dc_done()`, `dc_diff()`, `members_for_pick()`, `find_live_departments()`.
- `app/activity.php`: `log_activity(PDO, $action, $entityType, $entityId, $opts)` — opts incl. **`library_id`, `document_id`, `folder_id`**
  (defaulted from the entity when it is one of those) and `token_id` (defaults to the key of an API call). Never throws. `log_screen_view()`.
- `app/api/bootstrap.php`: the API gate — `api_authenticate()` resolves an `api` bearer through `dc_resolve_key()` **as its person** (the key
  sees what they see), `api_rate_limit()` → 429 with `Retry-After`; an `mcp` token is a 401 here; `api_json()`, `api_error()`.
- `app/doccloud/extract/` (the extractor, `dc_extract_text()`), `app/doccloud/graph.php` (the ONE reader of `MALUDB_DC_*`; every function
  answers `graph_unavailable` without the token), `tests/extract/`, `tests/phase0/engine.sh`.
- `bin/{directory_sync,dev_handoff,mint_mcp_token,make_brand_images,worker}.php`, `bin/dev_directory.json`, `mcp/{activity_ingest.py,db.py,
  server_common.py,requirements.txt}`, `tests/{dev_router,fake_kernel,fake_maludb,fake_malumail}.php`, `tests/setup_dev.sh`, `tests/phase0/`.

**Phase 2 adds:**
- `app/features/shell/{nav,queries}.php` (`NAV_ITEMS`, `NAV_SLICES` naming who builds each placeholder; `sidebar_libraries()` ≤ 12 from
  `dc_my_libraries()`; `bell_count()`; `business_name()`), `app/features/home/queries.php`, `app/features/settings/{queries,present}.php`,
  `app/features/activity/queries.php`, `app/features/assistant/queries.php`.
- **`app/doccloud/store.php`** — the blob store (every later slice calls it, nothing else touches the disk): `dc_storage_dir()`
  (`DC_STORAGE_DIR` or `dc_settings.storage_dir` or `APP_ROOT/storage`), `store_blob_path(string $relative): string`, `store_part_path(int
  $session, int $part)`, `store_thumb_path(int $documentId)`, `store_export_path(int $exportId)`, `store_judge_type(string $path, string
  $filename): array{mime, kind, inline_ok, refused_reason?}` (finfo decides; executables, scripts, HTML, SVG and anything beginning `<?php`,
  `#!/`, `MZ`, `\x7fELF` are **stored but never served inline** — `inline_ok = false` — nothing is refused for storage but a zero-byte file),
  `store_move_into_blobs(string $tmp, string $sha256): string` (hard-links or moves into `blobs/aa/bb/<sha>`, 0640 www-data),
  `store_stream(array $doc, array $version, array $opts)` (ranges, `Content-Disposition`, `nosniff`, `Cache-Control: private`, a sandboxing
  CSP on inline kinds), `store_unlink(string $relative): bool`.
- `app/picker.php` (`picker_field()`, `picker_source()`, `picker_label()`), **`app/pickers.php` EMPTY** (each slice adds its sources over the
  feature's own `find_*()`; `roles` there are DocCloud rights, any one admits), `html/pick/index.php` (`GET /pick/?source=&q=&page=`: login,
  the source's rights, `q` cut at 80, declared params only, refusals as fragments, nothing logged), `shared/picker-rows.php`,
  `assets/js/record-picker.js`, the RECORD PICKER section of `app-overrides.css`, the one `#record-picker` modal in `layout.php` after `</main>`.
- Views: `layout.php`, `shared/{header,flash,notice,message,tab-bar,timeline,assistant-bar,assistant-reply,picker-rows}.php`,
  `settings/{index,tokens}.php` + `partials/prefs.php`, `notifications/{page.php,partials/bell.php}`, `activity/{page.php,partials/rows.php}`,
  `home/dashboard.php`, `sso/refused.php`, `shared/nav-stub.php`.
- Entry points: `html/{index,logout,sso,activity,notifications}.php`, `html/sso/logout.php`, `html/api/v1/health.php`,
  `html/settings/{index,prefs}.php`, `html/settings/tokens/{index,mint,revoke}.php`, `html/settings/notifications/read.php`,
  `html/assistant/ask.php`, `html/robots.txt`, `html/manifest.webmanifest`, `html/assets/` (the design system's, verbatim).
- **The placeholders** — one line each calling `render_nav_stub()`: `html/files/index.php` (My files, slice 1), `html/shared/index.php`
  (Shared with me, 3), `html/groups/index.php` (1), `html/libraries/index.php` (1), `html/recent.php` (1), `html/favourites.php` (1),
  `html/tags/index.php` (4), `html/search/index.php` (4), `html/graph/index.php` (5), `html/trash/index.php` (6), `html/curate/index.php` (9),
  `html/admin/index.php` (9): after the login and the right, a page in the shell with one dashed card saying which slice builds it (200; the
  HTMX partial carries `HX-Title` and `X-Screen`), **501 `not_built` for a JSON caller and 501 for a POST — not a 404**; the registry marks
  them `stub`, never `built`, so the voice surface never offers them (removing the call is the slice's first step).
- `tests/phase2/{run.sh,servers.sh,lib.php,seed.php,gates.php,vhost.php,browser.mjs,picker.php}`.

## DocCloud is not Knowledge
- **The sidebar at 1280**: Home · **My files** · Shared with me · Groups · Libraries · Recent · Favourites · Tags · Search · Graph · Trash ·
  then **Curate** for a holder of `groups.manage` and **Admin** for a holder of `settings.manage`; under Libraries the person's libraries
  (`sidebar_libraries()`: personal first, then groups, then shared; ≤ 12). **The tab bar at 375**: Home · Files · Search · Me. The menu
  button opens the offcanvas with the full list.
- **A guest's shell**: Home (= Shared with me), Shared with me, Me; no My files (a guest has no personal library — `dc_library_ensure_personal()`
  answers NULL), no Groups, no Tags, no Graph, no Trash; the Files tab reads Shared.
- **Reach is a document's or a folder's, decided by the database.** PHP asks `dc_folder_level()` / `dc_document_level()`; the `mcp_*` views
  judge per row. The admin sees every library; PHP logs `library.admin_view` once per session the first time they open a personal library
  that is not theirs (slice 1).
- **Roles**: Guest, Member (`user`), Curator, DocCloud admin; the badge shows the highest held.
- **The first-run path**: a Member whose personal library holds no document and who is in no group with documents sees one button,
  **"Upload your first file"** (→ `/f/<root of my files>?upload=1`, slice 2; until then the My files placeholder); a guest with nothing shared
  is told so and shown no button.
- **A person's own tokens** (`mcp`, read-only) and **keys** (`api`, acting as them, slice 7) are both theirs: the Tokens screen lists and revokes
  `mcp` rows now; slice 7 adds the keys beside them.
- **Notification kinds are the schema's** (db/013): share_received, request_received, link_opened, version_added, comment, lock_broken,
  export_ready, quota_near, proposal, monday_note, ownership_received, graph_budget, extraction_failed.
- **On sign-on and on every sync**: `dc_library_ensure_personal($memberId)` for the signed-on member and, in `bin/directory_sync.php`, for every
  synced non-external active human; the sync ends with `dc_groups_sync_departments()`. A library made is logged `library.create` with
  `after.kind = 'personal'`, source `web` or `cron`.
- **The engine and the store are wired, not used**: `store.php` resolves its directory and refuses to start the app when it is not writable
  (health answers `storage: unwritable`); `graph.php`'s `dc_graph_available()` is shown on the health page as `graph: ok | unavailable`.

## The receiver (`/sso`) — in order
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time; both must pass; the member ids must match.
2. Expiry (60 s), audience (`doccloud`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. One transaction: `mirror_apply_member()` (with `capability`), `mirror_apply_roles()` from `claims.roles`, `dc_library_ensure_personal()`.
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; a `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; 302 to `/`.
6. Any failure: one page, `sso/refused.php`, logged `member.sign_on.refused` with `after.reason` — never shown.

## Every request
- Cookie `DCSID`, `SameSite=Lax`, `Secure` over TLS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; a member the kernel revoked has the session ended (`ended_by = 'directory'`) and is sent to the launcher with `?app=doccloud`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; an id with no mirror row is
  refused and logged `member.refused`, never created; a known, active agent with no grant is admitted at first contact only when the kernel's
  run-facts call vouches for it.
- `/sso/logout` (POST from the kernel): every session of the member ended (`ended_by = 'kernel'`); 204 always.
- A write handler: `dc_handler_begin()`, the right (`require_right()`), the level on the thing (`require_folder_level()` /
  `require_document_level()` — added in slice 1's handler.php, used by every later slice: `none` → 404 "Not found."; below the needed level →
  403 in words), `dc_guard()`, `log_activity()`, `emit_action_status()`, `dc_done()`. **A document's text, a passage, a search query over 120
  characters, a token, a link, a password are never in a log payload.**

## MCP tools this slice names (Phase 4 serves them; the shell's screens read the same SQL)
`app_roles`, `my_notifications`, `my_tokens`, `get_settings`, `records_search` (the guarded long tail), `activity_query` (the activity server's).

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId): array` → `['libraries' => rows of dc_my_libraries(), 'recent' => dc_recent_documents(8), 'shared_count',
  'first_run' => bool, 'may' => ['upload', 'curate', 'admin']]`
- `sidebar_libraries(PDO, int $limit = 12): array` · `bell_count(PDO, int $memberId): int` · `business_name(PDO): string`
- `my_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $fields): array`
- `find_my_notifications(PDO, int $memberId, bool $unreadOnly, int $limit, int $page): array` · `mark_notifications_read(PDO, int $memberId, ?int $id): int`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): array` · `revoke_token(PDO, int $memberId, int $id): bool`
- `find_my_activity(PDO, int $memberId, int $page, array $filters): array` · `find_record_activity(PDO, string $type, int $id, int $limit = 30): array` · `activity_sentence(array $row): string`
- `ask_assistant(PDO, int $memberId, string $utterance, array $context): array`

## Handlers (built)
`settings/prefs.php` (`prefs_save`), `settings/notifications/read.php` (`notification_read`), `settings/tokens/mint.php` (`token_mint`: own,
people only, scope `mcp` only here — `api` is slice 7's `key_mint`), `settings/tokens/revoke.php` (`token_revoke`), `logout.php` (POST + CSRF;
`member.sign_out`), `assistant/ask.php` (`assistant.ask`: the length and the screen, never the words).

## Manifest rows claimed
Screens (5): `home`, `notifications`, `activity`, `settings`, `settings-tokens`
Actions (5): `prefs_save`, `notification_read`, `notifications_read_all`, `token_mint`, `token_revoke`

Routes, not actions: `/sso`, `/sso/logout`, `/api/v1/health`, `/pick/`, the command bar's post to the kernel's chat endpoint. The twelve placeholders (My files, Shared, Groups, Libraries, Recent, Favourites, Tags, Search, Graph, Trash, Curate, Admin) are the slices' screens whose files call `render_nav_stub()`: the registry marks them `stub`; they are claimed by the slices that build them, never here. `settings-tokens` is extended by slice 7 (the keys).

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `directory.sync`, `directory.sync.failed`, `library.create`
(kind personal), `group.member_add` / `group.member_remove` (source `cron`, a department group following the directory), `cron.run`,
`prefs.save`, `notification.read`, `token.mint`, `token.revoke`, `assistant.ask`, `screen.view`.

## Status vocabulary
Role badge: DocCloud admin / Super-admin `danger`, Curator `primary`, Guest `warning`, Member `secondary`. Library kind chip: personal `light`,
group `info`, shared `secondary`. Ids: `home-upload-first-btn`, `home-libraries-list`, `home-library-{id}`, `home-recent`, `nav-{id}`,
`nav-library-{id}`, `app-tabbar`, `tab-{id}`, `assistant-bar`, `header-bell`, `record-picker`.

## Out of scope for this phase
Every file, folder, group, share, link, tag, search, graph, trash, curate and admin screen (Phase 3's slices); the MCP servers and the API's
routes (Phase 4 and slice 7 — the gate is ready).

## Proof (`tests/phase2/run.sh`: scratch database `dc_dev`, php -S on 8701, the fake kernel on 8702, the fake MaluDB + MaluMail on 8706;
`DC_APP=apache bash tests/phase2/run.sh` for a real Apache serving the rendered deploy vhost on 8701/8707)
Re-run both; exit non-zero on any failure; Phase 0's `run.sh` re-run green afterwards.
1. **browser (Playwright from `/var/www/web/node_modules`, screenshots in `$SHOTS`)**: at 375 × 740 a new Member (the tab bar on the bottom
   edge with Home · Files · Search · Me, the command bar above it, every control ≥ 44 px, the one "Upload your first file" button inside the
   screen, no sideways scroll, the badge, the offcanvas with the full menu and no Curate/Admin, the Me tab swapping `#page-content` and pushing
   `/settings/`, prefs saved with the notice, the Files tab opening the My files placeholder, the command bar answering the fake expert); a guest
   (Guest badge, Home = Shared with me, told nothing is shared, no button, no Groups/Tags/Graph/Trash); a member with a personal library holding a
   seeded document and a group (cards the width of the phone, Your libraries in the sidebar, Recent with the document); at 1280 × 800 the admin
   (no tab bar; the sidebar with Curate and Admin; the command bar right of the sidebar; HTMX navigation to `/admin/` with the highlight; back
   button; a token minted and shown once then revoked; the bell); JavaScript off (plain links carrying `?back=`); installable; **the record
   picker** (`tests/phase2/picker.php`: the modal opens from a test field in a hidden proof page, searches a seeded source, pages, keeps the
   label on a 422 re-render, refuses an undeclared param, 403s a source the role lacks).
2. **gates**: 17 screens (5 built + 12 placeholders) × 5 roles answer 200 or 403 as the rights say; the placeholders' card and slice, 501 for
   JSON and POST, `stub` in the registry; the menu and the tab bar per role; no session → the launcher with `?app=doccloud` (401 for JSON);
   the home's JSON (first_run true for a new member, false once a document exists; the libraries per role; a guest none); the person-own screens
   as JSON; the command bar (the reply, the log with the length and never the words, the acting person seen by the kernel, CSRF, the kernel's 404
   and 409, an approval shown with its request number, a navigate followed by HX-Location, over 2,000 characters refused); the action-token and
   run-token gate (a person's token opens one read with no cookie; expired and tampered 401; an admitted agent reads its activity; an unknown id
   refused, never created, logged `member.refused`).
3. **the mirror**: a sign-on makes the personal library (and its root); a sync makes one for every synced human and none for a guest; a department
   group follows a membership change in the feed within one sync; a revoked grant ends the session within one sync.
4. **store and graph wiring**: health answers `storage: ok` and `graph: unavailable` (no token in the proof) and `application: doccloud`; with an
   unwritable storage directory the health answers `storage: unwritable` and the home still renders; `store_judge_type()` judges an executable
   renamed `.pdf`, an SVG named `.txt`, a PDF, a PNG and an empty file as the design says.
5. **vhost (Apache mode)**: the receivers at their extension-less names, every canonical URL and the placeholders, `/d/`, `/f/`, `/s/`, `/r/`,
   `/api/v1/*` (404 until slice 7 but for health), `/pick/`, the MCP proxies 502, the manifest and the assets with their types, nothing outside
   `html/`, `storage/` unreachable by path, `LimitRequestBody` and the PHP upload limits as deploy says.
6. **registry**: `bin/build_action_registry.php --check` — every screen and action of the manifest present, 5 built screens, 12 stubs, 4 built
   actions, the 23 approvals in step with `maludb-os.json`.

## The routing form (settled in Phase 1's reconciliation)
The vhost's rewrites are the form, and `tests/dev_router.php` mirrors them exactly: `/s/{token}[/…]` → `html/s/index.php?token=&rest=`, `/r/{token}[/…]` → `html/r/index.php`, `/d/{id}` and `/d/{id}/v/{n}` → `html/d/index.php?id=&v=`, `/d/{id}/thumb` → `html/d/thumb.php`, `/f/{id}` → `html/f/index.php` (which sends the browser to `/folders/{id}`), `/f/{id}/{page}` → `html/f/{page}.php?id=`, `/exports/{id}` → `html/exports/download.php`, `/upload/{start|part|finish}` → `html/upload/{x}.php`; a document's page is `/documents/{id}`, a folder's `/folders/{id}`. No `s.php`, `r.php` or `d.php` at the web root.

## Open questions
None.
