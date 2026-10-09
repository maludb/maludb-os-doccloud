# Build spec: the trash, locks, quotas and the worker's windows (slice 6)

What exists at the end: a deleted document or folder goes to the **trash** and comes back from it; after 30 days the worker purges it (the
engine forgets it, the bytes are freed when nobody holds them); old versions expire by the window; stale locks, abandoned uploads, expired links
and exports are cleaned; a library has a **quota** the admin sets and a bar everyone sees; the **outbox sends** (MaluMail for email, the kernel's
K6 for texts); the admin watches the worker's passes. Every pass is a SQL function of db/014 called by `bin/worker.php` in order, idempotent,
advisory-locked, timed into `worker_passes`.

Schema (db/006, 007, 013, 014, never modified): `dc_folder_trash()`, `dc_folder_restore()` (db/006); `dc_document_trash()`, `dc_document_restore()`,
`dc_document_purge()`, `dc_document_lock()`, `dc_document_unlock()`, `dc_quota_allows()` (db/007); `notification_outbox`, `worker_passes` (db/013);
`dc_trash()`, `dc_trash_due()`, `dc_locked_documents()`, `dc_quota_state()`, `dc_pass_versions_expire()`, `dc_pass_trash_purge()`,
`dc_pass_blobs_free()`, `dc_pass_locks_expire()`, `dc_pass_uploads_expire()`, `dc_pass_exports_expire()`, `dc_pass_housekeeping()`,
`dc_pass_graph_catch_up()` (db/014); `mcp_documents` (trashed rows for those who may), `mcp_exports`, `mcp_libraries` (db/015). **The data-owner
rule**: slice 2 owns `uploads_expire`, `exports_expire`, `locks_expire`, `extract`, `thumbs`, `exports`; slice 4 `search_catch_up`; slice 5
`graph` and `graph_catch_up`; this slice owns `versions_expire`, `trash_purge`, `blobs_free`, `housekeeping`, `outbox`, `quota_watch`, and the
order of all of them in `bin/worker.php`.

## Divergences from the design
1. **`folder_delete` is the design's key for trashing a folder** (`deletion` for agents, because a subtree is large); `document_trash` is free;
   `document_purge` and `trash_purge` (the admin emptying what is due, or everything) are `deletion`.
2. **Restore into a trashed parent** lands at the library root (db/006, db/007) and the notice says so.
3. **Quota at 90%** notifies the library's owners once (`quota_near`, deduped per month) — the `quota_watch` pass.
4. **The outbox sender** sends at most 50 emails and 20 texts a pass, three attempts with backoff (`send_after`), a MaluMail 4xx is `failed`
   with the detail, a K6 refusal (`no_sender`, `opted_out`, `limit`) is `skipped` with the code.

## Who may do what
| Action | Right | Level |
|---|---|---|
| document_trash / document_restore | `trash.own` | `manage` on the document (the owner always) |
| folder_delete / folder_restore | `documents.edit` | `manage` on the folder; `deletion` for agents (folder_delete) |
| document_purge / trash_purge | `trash.purge` (admin) | `deletion` |
| quota_set | `quotas.manage` (admin) | `other` |
| retention_set (the windows: versions days/count, trash days, lock hours) | `retention.manage` (admin) | `other` |
| document_lock_break | — | `manage` (slice 2's handler; the expiry pass is here) |
| the worker screen | `settings.manage` | — |

## Screens (375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `trash` | `/trash` | mine: `dc_trash()` as the listing table (name, kind, from folder, trashed when and by, **purges on**), Restore · Purge (admin); "Empty what is due" (admin: `trash_purge`); the folder page's Trash tab (`/f/{id}/trash`) shows the folder's for its managers |
| `admin-quotas` | `/libraries/{id}/quota` | the quota in GB (blank = none), the bar, the top owners in the library (`dc_storage_by_owner()` filtered); posts `quota_set` |
| `admin-settings` (slice 9: the windows) | `/admin/retention` | versions keep days and count, trash days, lock hours, upload session hours, link default days, "never expires" allowed; each field with its bounds; posts `retention_set` |
| `admin-worker` | `/admin/worker` | the last 50 passes (`worker_passes`: started, took, steps as chips with counts, error `danger`), the queues at a glance (outbox queued/failed, graph jobs queued, uploads open, exports queued, blobs to free), "Run a pass now" (`worker_run`, admin; runs `bin/worker.php` in the request with a 50 s budget) |
| the library view gains | `/libraries/{id}` | the quota bar with "near" `warning` at 90% and "full" `danger` |
| the document page gains | `/d/{id}` | Trash (manage) → `document_trash` with a confirm; a trashed document's page shows the banner "In the trash — purges on …" with Restore |

## `bin/worker.php` (the order, every minute; `DC_WORKER_NOW` fixes the clock in proofs; `--only=<step>`)
1. `uploads_expire` (2) · 2. `extract` (2) · 3. `thumbs` (2) · 4. `search_catch_up` (4) · 5. `graph` (5) · 6. `graph_catch_up` (5) · 7. `exports` (2) ·
8. **`versions_expire`** · 9. **`trash_purge`** · 10. **`blobs_free`** (unlink each path answered; a missing file is fine) · 11. `locks_expire` (2) ·
12. `links_expire` (nothing to write: `expires_at` decides; logs `link.expire` for links that expired since the last pass, once) · 13. `exports_expire` (2) ·
14. **`quota_watch`** · 15. **`outbox`** · 16. **`housekeeping`** · 17. `dispatches` (8). One `worker_passes` row per pass with `steps = {name: {count, ms}}`;
`cron.run` logged with the totals; an exception in a step is caught, logged `cron.step_failed` with the step, and the pass continues.

## Files (exactly these)
- `html/trash/index.php` (`trash`, replaces the placeholder) · `html/trash/{restore,purge,empty}.php` · `html/f/trash.php` · `html/f/delete.php` (`folder_delete`) · `html/f/restore.php` ·
  `html/d/trash.php` · `html/d/restore.php` · `html/d/purge.php`
- `html/libraries/quota.php` (`admin-quotas`) · `html/libraries/quota-save.php` · `html/admin/retention.php` (`admin-settings` (slice 9: the windows)) · `html/admin/retention-save.php` ·
  `html/admin/worker.php` (`admin-worker`) · `html/admin/worker-run.php`
- `app/features/trash/{queries,write}.php`, `app/features/admin/{queries,write}.php` gain retention and the worker reads, `app/features/outbox/{sender,k6}.php`,
  `app/features/files/worker_steps.php` gains `versions_expire`, `trash_purge`, `blobs_free`, `quota_watch`, `housekeeping`; `bin/worker.php` the order
- `app/views/trash/{index,partials/row}.php`, `app/views/libraries/quota.php`, `app/views/admin/{retention,worker}.php`, `app/views/files/partials/trash-banner.php`
- `tests/phase3/slice6/{run.sh,lib.php,trash.php,windows.php,quotas.php,outbox.php,worker.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `my_trash(PDO, ?int $folderId): array` · `trash_due(PDO, int $days): array` · `quota_state(PDO, int $libraryId): array` · `retention_settings(PDO): array` ·
  `worker_passes(PDO, int $limit = 50): array` · `worker_queues(PDO): array`
- writes: `document_trash(PDO, int $id, int $by)` · `document_restore(PDO, int $id)` · `document_purge(PDO, int $id)` (admin; the engine's forget by trigger) ·
  `folder_delete(PDO, int $id, int $by): int` (→ `dc_folder_trash`) · `folder_restore(PDO, int $id): int` · `trash_purge(PDO, bool $dueOnly): int` · `quota_set(PDO, int $libraryId, ?int $bytes, int $by)` ·
  `retention_set(PDO, array $f, int $by): array` · `worker_run(PDO): array`
- the worker: `worker_versions_expire(PDO)` · `worker_trash_purge(PDO)` · `worker_blobs_free(PDO): int` · `worker_quota_watch(PDO)` · `worker_outbox(PDO): array{sent, skipped, failed}` · `worker_housekeeping(PDO)`
- the sender: `outbox_send_email(array $row): array{status, detail, ref}` (MaluMail through `app/mail.php`) · `outbox_send_text(array $row): array` (K6: `POST {OS_INTERNAL_URL}/api/v1/notify/sms.php` with the application token)

## MCP tools this slice makes real
`trash`, `trash_due`, `locked_documents`, `quota_state`, `storage_by_library`, `storage_by_owner` (the data; slice 9 the screens).

## Manifest rows claimed
Screens (3): `trash`, `admin-quotas`, `admin-worker`
Actions (8): `document_trash`, `document_restore`, `document_purge`, `folder_delete`, `trash_purge`, `quota_set`, `document_lock_break`, `worker_run`

`trash_purge` with `due_only = no` empties the trash (one key, one field). The retention windows are set on `admin-settings` (slice 9's `retention_set`); this slice proves the passes. `worker_run` is people-only (no approval: the 23 of design §5 stand).

## Activity log events
`document.trash|restore|purge`, `folder.trash|restore|purge`, `version.expire`, `blob.free`, `quota.set|near`, `retention.set`, `outbox.sent|skipped|failed`, `cron.run|step_failed`, `link.expire`.

## Notifications this slice sends (the outbox) and queues
Sends every kind queued by every slice; queues `quota_near` (the library's owners; monthly dedupe) and `lock_broken` (slice 2 queues it; sent here).

## Status vocabulary
Trash row: purges in > 7 days `secondary`, ≤ 7 `warning`, due `danger`. Quota bar: < 90% `primary`, ≥ 90% `warning`, 100% `danger`. Pass chip: ok `success`, failed `danger`.
Ids: `trash-table`, `trash-row-{kind}-{id}`, `trash-empty-btn`, `quota-bar`, `retention-form`, `worker-passes`, `worker-run-btn`.

## Out of scope for this slice
The Clerk's trash-due proposals (8), the admin index (9).

## Proof (`tests/phase3/slice6/run.sh`: scratch database `dc_dev6`; ports as slice 5 (the fake engine for the forgets); a scratch storage directory; `DC_WORKER_NOW`; Playwright; the registry `--check`)
1. A document trashed by its owner: gone from the folder for a group member, in the owner's trash with "purges on", restored to its folder; restored after its folder was trashed → the root with the notice; a same name present → "(2)".
2. A folder trashed (`folder_delete`): its subtree and documents with it; a viewer loses it; restored whole; a document trashed on its own earlier stays in the trash.
3. The purge: a document past 30 days purged by the pass (the fake engine received the forget; the blob freed and unlinked by `blobs_free`; a blob still held elsewhere kept); `document_purge` by the admin now; `trash_purge` due-only and all; an agent's purge pauses.
4. Versions: all kept within 30 days; past it, the last 10 kept, the rest purged by the pass, the current never; `retention_set` to 2 and the pass follows.
5. Locks expire by the pass after `lock_hours`; a broken lock's holder is told; uploads expire and their parts vanish; exports expire and unlink; links log `link.expire` once.
6. Quotas: set in GB by the admin (an agent pauses), the bar on the library view, an upload refused at the quota in words, `quota_near` once at 90% and not again that month; `used_bytes` follows moves across libraries and purges.
7. The outbox: an email sent through the fake MaluMail (status sent, the provider ref), a 4xx failed with the detail after three attempts with backoff, a text through the fake kernel's K6 (sent), a K6 `opted_out` skipped with the code; at most 50 + 20 a pass.
8. The worker: the order, a step throwing (a fixture) logged `cron.step_failed` and the pass continuing, `worker_passes.steps` with counts and ms, `--only=outbox`, "Run a pass now" from the admin screen.
9. JSON mode on every screen and handler; the run-token gate.
10. Browser at 375 and 1280: the trash with restore, the quota form and bar, the worker screen; no console errors.
11. Registry `--check`: 4 screens built, 11 actions, 5 approvals in step.

## Open questions
None.
