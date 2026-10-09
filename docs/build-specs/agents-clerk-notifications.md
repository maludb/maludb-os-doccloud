# Build spec: agents, the Filing Clerk and notifications (slice 8)

What exists at the end: the two shipped agents work here as the design says — the **expert** in the command bar finds, explains access, files,
tags and shares inside the business, and **pauses** on a link, a request, a guest or application share and every deletion; the **Filing
Clerk** runs its Monday store report and leaves **proposals** people accept or dismiss (untagged documents with suggested tags from the graph,
duplicates, stale guest shares, links expiring or opened from many addresses, open requests, the trash about to purge, long locks, extraction
failures, libraries near their quota, the graph budget nearly spent, documents owned by inactive members); dispatches wake the expert when
forty untagged documents arrive in a folder, when a file request receives uploads, when duplicates pile up; the **bell**, the preferences and
the outbox are whole; K6 texts reach a member who opted in. Phase 4 serves the tools; this slice builds the data, the screens and the dispatch
loop.

Schema (db/013, 014, 015, never modified): `agent_dispatches`, `clerk_proposals`, `notifications`, `notification_prefs`, `notification_outbox`,
`dc_notify()` (db/013); `tag_proposals`, `dc_tag_apply()` (db/009); `dc_untagged()`, `dc_duplicates()`, `dc_stale_guest_shares()`, `dc_links_expiring()`,
`dc_links_suspicious()`, `dc_trash_due()`, `dc_locked_documents()`, `dc_extraction_failures()`, `dc_quota_state()`, `dc_storage_by_library()` (db/011, 014);
`dc_graph_budget_state()`, `dc_graph_subjects()` (db/012); `mcp_clerk_proposals`, `mcp_agent_dispatches`, `mcp_notifications` (db/015). The kernel: the
chat endpoint (`POST {OS_INTERNAL_URL}/api/v1/agents/chat.php?agent=<key>` with the application token and `X-Acting-Member`), the approval hook, K6.

## Divergences from the design
1. **The Clerk proposes tags from the graph's subjects** (`tags` kind): for an untagged document in the graph, its `graph_documents.subjects`
   minus the structural ones (`folder:`, `library:`, `owner:`, `kind:`, `year:`) and, for a `full` document, the subject names from
   `document_subjects` — up to five, as a `tags` proposal the owner accepts (→ `tag_apply`, source `agent`) or dismisses. When the document is
   not in the graph the proposal is `untagged` with no suggestion.
2. **A proposal belongs to one person** (`for_member_id`): the owner for a document's, a group's first owner for a library's, the maker for a
   link's, the admin for quota and budget. The Clerk's note goes to the admin's assistant through the kernel's `message_send` (the Actions MCP
   tool the kernel grants; here only the dispatch that asks for it).
3. **Dispatches are chat turns** until K8: `agent_dispatches` rows the worker's `dispatches` step turns into one chat call each (`via chat`,
   `acting_member_id` = the person whose thing it is), status from the kernel's answer (`answered`, `awaiting_approval` with the request
   number, `refused`, `failed` with backoff: three attempts an hour apart). Kinds: `untagged_batch` (40 untagged documents in a folder within a
   day → the expert proposes tags for them, as the Clerk does but now), `request_received` (uploads on a file request → the expert files and tags
   them under the maker's folder rules), `duplicates_found` (a sha256 reaching five copies → a proposal to the owners), `ask` (a person's
   proposal "ask the expert about this folder" from the folder page).
4. **Agents are refused the bell, the feed and the settings** (as Spaces does): an agent's run token on `/notifications` → 403 in words; an
   agent's notifications are not made (`dc_notify()` for an agent member writes nothing — guarded in PHP's `notify()` wrapper, not the SQL).
5. **The bell polls** every 20 s while the page is visible (`/notifications/count`).

## Who may do what
| Action | Right | Rule |
|---|---|---|
| clerk_proposal_accept / dismiss | — | the proposal's person, or `groups.manage` (a curator) |
| clerk_propose, tag_propose, notification (the agents') | the Clerk's and the expert's grants | source `agent`; `clerk_propose` by a person is refused (it is the Clerk's) |
| agents_settings_save (the dispatch thresholds: 40, 5, the hour) | `agents.settings` | `other` for agents |
| dispatch_retry / dispatch_cancel | `agents.settings` | — |
| prefs, the bell | own | people only |
| the Clerk's duty | the kernel's schedule `50 6 * * 1` | the Clerk reads the report tools and writes proposals |

## Screens (375 px first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `proposals` | `/proposals?status=&kind=` | my open proposals as cards (`mcp_clerk_proposals`: kind chip, title, the thing (a link), the suggestion — tags as chips to accept together or one by one —, Accept · Dismiss); decided below; a curator sees every open one with "for X" |
| `admin-agents` | `/admin/agents` | the two agents (from the mirror: name, status, last dispatch, the kernel's link), **the thresholds** (`agents_settings_save`: untagged batch size, duplicate copies, retry hour, the Clerk's report enabled), **the dispatches** (`mcp_agent_dispatches`: kind, via, the thing, acting person, status chip, the reply excerpt, attempts; Retry · Cancel), "Ask the expert about…" (a folder picker → an `ask` dispatch) |
| `notifications` (Phase 2's) gains | `/notifications` | the kinds' icons, "mark all read", the link to each thing; the bell's count polling |
| `settings` (Phase 2's) gains | `/settings/` | the text channel (K6: "the business's number texts your verified phone; the kernel keeps your opt-out") with the text kinds |
| the folder page gains | `/f/{id}` | "Ask the expert about this folder" (manage) → an `ask` dispatch; the Clerk's open proposals for the folder as a strip |
| the home gains | `/` | the Clerk's last Monday note (a card), my open proposals count |

## The Clerk's Monday report (`app/features/clerk/report.php`: what the duty's prompt (`os/clerk.md`, `skills/monday-store-report`) has the Clerk do with the report tools — the
data is this slice's; the Clerk itself runs in the kernel)
The report tools answer per caller (the Clerk is an agent holding Member: it sees what a Member in no group sees — so **the report tools answer the
Clerk ACROSS every library it may not see?** No: design §5 says the Clerk reads the report tools; they are the SQL of db/014, which honour the
wall. **The Clerk is therefore granted, at install, a `view` row on every shared library's root and is added to every group by the super-admin's
grant policy — or, simpler and what this slice does: `clerk_report(PDO)` is a SECURITY DEFINER read the records server exposes to the Clerk's
member id only (`dc_is_clerk()`: `members.id = dc_settings.clerk_member_id`, set by the hire), answering counts and ids across the store with
names only, never text.** `clerk_report()` → `{untagged: by folder with the suggested tags, duplicates, stale_guest_shares, links_expiring,
links_suspicious, requests_open, trash_due, locked_long, extraction_failures, quota_near, budget, orphaned (documents owned by inactive
members)}`. The Clerk writes one `clerk_propose` per item (deduped by kind + thing + week), one `notification` (`monday_note`) per group's
owners and one `message_send` to the admin's assistant. `clerk_member_id` is a column this slice **appends** to `dc_settings` (db/016, the
first migration after Phase 0 — additive), written by the installer's hire step through `agents_settings_save`.

## Files (exactly these)
- `html/proposals/index.php` (`proposals`) · `html/proposals/{accept,dismiss}.php` · `html/admin/agents.php` (`admin-agents`) · `html/admin/agents/{save,dispatch-retry,dispatch-cancel,ask}.php` ·
  `html/notifications/count.php` · `html/agents/{propose,tag-propose,notify}.php` (the agents' actions: `clerk_propose`, `tags_propose_request`, `notification`)
- `app/features/clerk/{queries,report,write}.php`, `app/features/agents/{queries,dispatch,write}.php`, `app/features/files/worker_steps.php` gains `dispatches`,
  `app/features/notifications/{queries,write}.php` (the wrapper `notify()` over `dc_notify()`, the agent guard)
- `db/016_phase1_reads.sql` (the lead's, written in Phase 1): appends `dc_settings.clerk_member_id`, `untagged_batch_size` 40, `duplicate_copies` 2, `dispatch_retry_minutes` 30, `clerk_report_enabled` true; `dc_is_clerk()`; the SECURITY DEFINER `dc_clerk_report()`; the `mcp_settings` view re-created with the columns LAST — this slice builds against those names and adds no migration
- `app/views/proposals/{index,partials/card}.php`, `app/views/admin/agents.php`, `app/views/home/partials/clerk-note.php`, `app/views/files/partials/proposals-strip.php`
- `tests/phase3/slice8/{run.sh,lib.php,proposals.php,report.php,dispatches.php,notifications.php,k6.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `my_proposals(PDO, array $filters): array` · `clerk_report(PDO): array` · `find_dispatches(PDO, array $filters, int $page): array` · `agents_summary(PDO): array` · `agent_settings(PDO): array`
- writes: `clerk_propose(PDO, array $f, int $by): ?int` (dedupe) · `proposal_accept(PDO, int $id, int $by, array $choice = []): array` · `proposal_dismiss` · `tag_propose(PDO, int $documentId, array $slugs, int $by): array` ·
  `dispatch_make(PDO, string $kind, array $f): int` · `dispatch_run(PDO, int $id): array` (the chat call) · `dispatch_retry` · `dispatch_cancel` · `agents_settings_save(PDO, array $f, int $by)` · `notify(PDO, int $memberId, string $kind, ...)`
- the worker: `worker_dispatches(PDO, int $limit = 10)` (also makes the `untagged_batch` and `duplicates_found` dispatches from the thresholds, once a day per folder / sha)

## MCP tools this slice makes real
Also: `clerk_report` (the surface's rows this slice's screens read).
`clerk_proposals`, `agent_dispatches`, `clerk_report` (the Clerk's), `untagged`, `duplicates`, `stale_guest_shares`, `links_expiring`, `links_suspicious`, `find_requests`,
`trash_due`, `locked_documents`, `extraction_failures`, `quota_state`, `graph_usage` (the report's parts, each honouring the wall for everyone but the Clerk's `clerk_report`).

## Manifest rows claimed
Screens (2): `proposals`, `admin-agents`
Actions (7): `clerk_propose`, `clerk_proposal_decide`, `notification`, `dispatch_retry`, `dispatch_cancel`, `tags_propose_request`, `agents_settings_save`

Folded: `clerk_proposal_accept` / `clerk_proposal_dismiss` are `clerk_proposal_decide` with `decision`; `tag_propose` (the agents' tag proposals) is the manifest's `tags_propose_request`; `notifications_read_all` is the shell's row. Asking the expert about a proposal (`dispatch_ask`) is the command bar — a route to the kernel's chat endpoint, never an action.

## Activity log events
`clerk.propose|accept|dismiss|report`, `agent.dispatch|reply|refuse|fail|cancel|retry`, `agents.settings_save`, `notification.read_all`, `tag.propose` (source agent).
A reply is kept as a 200-character excerpt; a document's text never.

## Notifications this slice queues
`proposal` (the proposal's person), `monday_note` (a group's owners; the admin), `request_received` (slice 3 queues; the dispatch here), `comment` (a note on one's document — slice 9 adds the composer; the kind is here).

## Status vocabulary
Proposal kind chips: untagged/tags `info`, duplicate `secondary`, stale_share `warning`, link_expiring `warning`, link_suspicious `danger`, request_open `info`, trash_due `warning`, locked `secondary`,
no_text `light`, quota `danger`, budget `danger`, orphaned `warning`. Dispatch: sent `info`, answered `success`, awaiting_approval `warning`, refused `dark`, failed `danger`.
Ids: `proposal-card-{id}`, `proposal-accept-btn`, `agents-thresholds`, `dispatches-table`, `bell-count`.

## Out of scope for this slice
The admin index and the connections screen (9); the Clerk's hire and duty (the kernel's `hire_application_agent.php` with the manifest's `duty`).

## Proof (`tests/phase3/slice8/run.sh`: scratch database `dc_dev8`; ports as slice 5; the fake kernel's chat endpoint answers canned replies and an approval; `DC_WORKER_NOW`; Playwright; the registry `--check`)
1. db/016 applies; `clerk_member_id` set by `agents_settings_save`; `dc_is_clerk()` true for that member and false for the expert.
2. `clerk_report()` as the Clerk answers every section from a seeded world (untagged with graph-suggested tags, a duplicate sha with 3 copies, a 100-day guest share, a link expiring in 3 days, a link opened from 12 addresses, an open request, a document purging in 2 days, a 30-hour lock, an unsupported file, a library at 92%, the budget at 85%, a document owned by an inactive member); as a Member it is refused.
3. `clerk_propose` by the Clerk's run token writes proposals deduped by week; by a person 403; the owner sees theirs, a curator every one; accept on a `tags` proposal applies the chosen tags (source agent) and tells nobody twice; dismiss closes; a 31-day-old proposal expires by housekeeping.
4. Dispatches: 40 untagged uploads in a folder within a day → one `untagged_batch` dispatch; the worker calls the fake chat endpoint as the folder's manager with the expert; `answered` with the excerpt; an approval → `awaiting_approval` with the request number; a 500 → `failed` with backoff and three attempts; `request_received` after an upload on a request; `duplicates_found` at five copies; `ask` from the folder page.
5. The expert's grants (Phase 4 proves the servers; here the registry): `link_make`, `request_make`, `share_guest`, `share_application`, every deletion pause under its run token through the fake kernel's hook; `tag_apply`, `document_move`, `share_member` run.
6. Notifications: the bell counts, `mark all read`, the count route; an agent is refused the bell (403) and gets no notification rows; prefs with the text channel; the outbox rows for email and text; K6 through the fake kernel (sent / opted_out skipped).
7. The home shows the Clerk's last Monday note; the folder page's proposals strip.
8. JSON mode on every screen and handler; the run-token gate for the three agent actions (a person refused `clerk_propose`).
9. Browser at 375 and 1280: proposals cards with accept of two tags, the agents screen's thresholds and dispatches, the bell; no console errors.
10. Registry `--check`: 2 screens built, 10 actions, 1 approval in step.

## Open questions
(decided in Phase 1's reconciliation, to confirm with the owner — docs/phase1-consistency.md)
1. **The Clerk's reach**: this spec gives the Clerk one SECURITY DEFINER report read (`dc_clerk_report()`, names and counts only) instead of granting it rows on every library. The alternative — the Clerk added to every group and shared library by the hire — makes the Clerk a member of everything, which the owner may prefer for simplicity but which means an agent sees every file's listing. The reconciliation (or the owner) picks; the spec builds the report read.
