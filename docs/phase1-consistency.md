# Phase 1 — the reconciliation (2026-10-09)

Three writers worked in parallel on the Phase 1 documents — the tool surface and the action manifest, the three build specs, the ten
slice specs — and their names drifted. This records every reconciliation made, the decisions taken in the specs that the owner should
confirm, and the final counts. `tests/phase1/check_specs.sh` keeps it true: the registry matches the manifest, the approvals match,
every screen and action is claimed by exactly one slice, every tool of the surface is named by a slice and every tool a slice names
exists (55 checks, green).

## The rule
**The manifest (`docs/doccloud-action-manifest.md`) is canonical for screens and actions; the surface (`docs/doccloud-mcp-tool-surface.md`)
for tools.** A slice spec names them by the manifest's ids and the surface's names; a real button a slice needed that the manifest lacked
was ADDED to the manifest (with the eight columns) and the registry rebuilt; a GET navigation or an HTTP route a slice had listed as an
action was struck or marked "a route, not an action". Each slice spec now carries a `## Manifest rows claimed` section with two lines
(`Screens (N): …`, `Actions (N): …`) the check script reads.

## Rows added to the manifest (9 actions, 5 screens)
| Added | Where | Why |
|---|---|---|
| `notifications_read_all` | the shell | the bell's "mark all read" (slice 8 named it; the shell owns the bell) |
| `exports` (screen `/exports`) | slice 2 | my zips: ready, being made, expiring (slice 2's screen the manifest lacked) |
| `export_folder`, `export_documents` | MOVED from slice 7's section to slice 2's | the zip is built by the uploads slice; slice 7 is the API |
| `requests` (screen), `access-history` (screen) | slice 3 | my file requests; who could see this on a day (design §7 A6, A7) |
| `link_update` | slice 3 | a link's label, expiry, download flag and password change without rotating it |
| `tag-rules` (screen) | slice 4 | every folder that applies tags on upload |
| `tag_archive`, `tag_restore` | slice 4 | a tag hidden from the pickers and brought back (never deleted while in use) |
| `graph-path` (screen) | slice 5 | the path between two subjects (design §7 G4) |
| `graph_job_skip`, `graph_test_extraction` | slice 5 | the `no_edges` case; a dry run of the full prompt (people-only) |
| `worker_run` | slice 6 | the admin runs one pass by hand (people-only, no approval — see below) |
| `dispatch_cancel` | slice 8 | a sent or awaiting dispatch cancelled |
| `storage_export` | slice 9 | the storage and search reports as CSV |

## Folded (one key per form)
`folder_colour_set` → a field of `folder_update`; `library_archive` / `library_restore` → the `archived` field of `library_update`;
`group_level_set` → a field of `group_update`; `limits_save` → `settings_save`; `tag_proposal_accept` / `_dismiss` → `tag_proposal_decide`
with `decision`; `clerk_proposal_accept` / `_dismiss` → `clerk_proposal_decide`; `tag_propose` (the agents') → `tags_propose_request`;
`trash_empty` → `trash_purge` with `due_only = no`; `search_save` → `saved_search_save`.

## Struck from the slices' action lists (routes, GETs, doors)
`upload_start`, `upload_part`, `upload_finish` (the action is `document_upload`; the routes are `/upload/*`), `export_download`
(`/exports/{id}`), `request_upload` and `link_password` (the public doors' POSTs: no session, no action token, never on the Actions MCP),
`search_run` (`/search?q=`), `graph_find_run` (`/graph/?q=`), `graph_related_refresh` (a GET partial), `dispatch_ask` (the command bar —
a route to the kernel's chat endpoint).

## Screen ids renamed in the slices (the manifest's ids win)
`my-files` → the `folder` screen at the personal root; `libraries` → `library-list`; `library-form` → `library-add` / `library-edit`;
`groups` → `group-list`; `group-form` → `group-add` / `group-edit`; `folder-form` → `folder-add` / `folder-edit`; `share` →
`folder-share` / `document-share`; `links` → `folder-links` / `document-links`; `link-form` → `link-add`; `request-form` → `request-add`;
`tags` → `tag-list`; `tag-form` → `tag-add` / `tag-edit`; `graph-subject` → `subject-view`; `graph-policy-form` → the policy part of
`graph-admin` (and the library / folder edit forms); `quota-form` → `admin-quotas`; `admin-retention` → `admin-settings` (the windows are
settings; slice 9's `retention_set`, slice 6 proves the passes); `keys` / `key-form` → the shell's `settings-tokens` (slice 7 adds the
keys part); `agents` → `admin-agents`; `storage-report` → `curate-storage`; `search-report` → `curate-search`; `my-settings` →
`settings`; `tokens` → `settings-tokens`. The slice spec file `document-graph-slice.md` is renamed **`graph.md`** (the build spec stays
`document-graph.md`); `uploads-versions.md` stays beside the build spec `uploads-and-versions.md`.

## Tools
The surface gains **`clerk_report`** (P1; `dc_clerk_report()` of db/016, the Clerk's member id only): 71 records tools. The slices now name
the surface's rows their screens read but their lists omitted (`my_favourites`, `recent_documents`, `my_uploads`, `downloads`,
`link_opens`, `access_changes`, `record_history`, `actor_timeline`, `recent_activity`, `activity_search`; the shell names `app_roles`,
`my_notifications`, `my_tokens`, `get_settings`, `records_search`, `activity_query`). The surface's "what the schema lacks" is answered by
**db/016** (the lead's): `dc_folder_path_text`, `dc_document_page`, `dc_document_excerpt`, `mcp_notification_prefs`, the gated
`dc_links_suspicious`, `dc_application_folders` / `dc_application_documents`, `mcp_documents.owner_name`, `mcp_folders.document_count`,
`dc_graph_filter(candidates, limit, filters)`, `dc_graph_job_skip`, `doc:<id>` in `dc_graph_subjects`, the catch-up's failed-job skip —
and, for slice 8, `dc_settings`' clerk columns, `dc_is_clerk()`, `dc_clerk_report()`.

## Decisions taken in the specs — to confirm with the owner
1. **The Clerk's reach.** The Clerk is not made a member of every library. It holds ONE read, `dc_clerk_report()` (SECURITY DEFINER,
   answering only the member `dc_settings.clerk_member_id` the hire sets): names, counts and ids across the store — untagged by folder with
   the graph's suggested tags, duplicates, stale guest shares, links expiring or opened from many addresses, open requests, the trash due,
   long locks, extraction failures, quotas, the budget, orphaned documents — never a document's text, never a listing it could browse. The
   alternative (a `view` row on every library at the hire) makes an agent a reader of every file's name; the owner may prefer it for
   simplicity. (`docs/build-specs/agents-clerk-notifications.md`)
2. **The `document_link` signing key is derived, never installed:** `hash_hmac('sha256', 'doccloud-link-signing-v1', ACTION_TOKEN_KEY)`.
   No `DC_LINK_SIGNING_KEY` in `config/.env` or `maludb-os.json`; the key follows the kernel's and a URL can never be a token the kernel
   would accept. Rotating the kernel's key kills every live URL (ten minutes at most). (`docs/build-specs/api-keys-k7.md`)
3. **The routing form is the vhost's:** `/s/{token}` → `html/s/index.php`, `/r/{token}` → `html/r/index.php`, `/d/{id}` (+ `/v/{n}`) →
   `html/d/index.php`, `/d/{id}/thumb` → `html/d/thumb.php`, `/f/{id}` → `html/f/index.php` (to `/folders/{id}`), `/exports/{id}` →
   `html/exports/download.php`, `/upload/{x}` → `html/upload/{x}.php`; a document's page is `/documents/{id}`, a folder's `/folders/{id}`.
   `tests/dev_router.php` now mirrors these exactly (it pointed at `s.php`, `r.php`, `d.php` before). (`docs/build-specs/sso-shell.md`)
4. **GET navigations are not actions** (a search, a find, a related refresh): the voice surface reaches them as screens with params.
5. **`worker_run` and `graph_test_extraction` are people-only, with no approval**, so design §5's 23 approval categories stand unchanged
   (the lead's brief suggested `other` for `worker_run`; a 24th approval would have changed `maludb-os.json`'s block and the kernel's
   policies — the brief also fixed the count at 23, which wins).
6. **`key_revoke` never pauses** (a person-only action; `link_revoke` is the paused `deletion`).

## The counts
| | |
|---|---|
| Screens | 67 (62 + 5 added; 0 built until Phase 2) |
| Actions | 95 (86 + 9 added); 23 paused for agents; the people-only ones: the token and key actions, `tags_propose_request`, `graph_job_skip`, `graph_test_extraction`, `worker_run`, `dispatch_cancel`, `tag_archive`, `tag_restore` |
| Records tools | 71 (10 through the bridge); activity tools 7; 4 shares; 12 resolver entities; 8 token-API routes |
| Claims | 67 / 95 across the ten specs, each exactly once |
| The installer's plan | 54 steps, 9 notes, no errors, no warnings |
