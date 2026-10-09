# Build spec: the document graph — THE SECOND EXEMPLAR (slice 5)

What exists at the end: the **Graph** screen finds documents by what they are about ("every document that names Acme and a termination
clause"), explores the subjects — by degree, a subject's neighbourhood, a path between two — and shows on a document's page the documents
like it; every document enters the graph under the policy of its library or folder (`none` · `index` · `full`), queued by the worker one at a
time under the monthly budget; the admin sets the policy, the budget and the model, sees the queue, the estimate before a `full` policy and the
month's usage. The engine is MaluDB in a memory database of DocCloud's own, read only through `app/doccloud/graph.php`; every result is a
document judged by the wall before ranking. The mechanics — the policies, the queue discipline, `dc_graph_find()`'s four steps, what the
engine answers (`tests/phase0/engine/FINDINGS.md`) — are **the build spec `document-graph.md`** (worker B's); this slice names the screens,
the handlers, the worker pass and the proof.

Schema (db/012, 014, 015, never modified): `graph_documents`, `graph_jobs`, `graph_usage`, `dc_effective_graph_policy()`, `dc_graph_queue_document()`,
`dc_graph_requeue_folder()`, `dc_graph_requeue_library()`, the triggers, `dc_graph_budget_state()`, `dc_graph_estimate()`, `dc_graph_next_job()`,
`dc_graph_job_done()`, `dc_graph_job_fail()`, `dc_graph_subjects()`, `dc_graph_filter()` (db/012); `dc_pass_graph_catch_up()` (db/014);
`mcp_graph_documents`, `mcp_graph_jobs`, `mcp_graph_usage` (db/015); `libraries.graph_policy`, `folders.graph_policy` (db/006). The engine:
`dc_graph_available()`, `dc_graph_index_document()`, `dc_graph_full_document()`, `dc_graph_forget()`, `dc_graph_resolve_subjects()`,
`dc_graph_candidates()`, `dc_graph_neighbors()`, `dc_graph_path()`, `dc_graph_god_nodes()`, `dc_graph_surprises()`, `dc_graph_stats()`,
`dc_graph_subject()`, `dc_graph_config_get/set()` (`app/doccloud/graph.php`, Phase 0).

## Divergences from the design
1. **The default ask-me-anything never runs the graph** (D4): the expert's grant carries `graph_find`, but `os/expert.md` says "only when the
   person asks for the graph, or says keyword search found nothing and they want more"; the search page's "Try the graph" link is the person
   asking. Nothing here changes that.
2. **The `full` policy's prompt** (`app/doccloud/graph_prompt.php`, sent with `dc_graph_config_set()` as the namespace's `prompt_template`)
   names **registered subject types only** — `person, stakeholder, project, time_period, event, process, equipment, software, concept, other`
   (FINDINGS.md: an unregistered type fails after the model was paid) — and asks for verbatim spans; the admin's "Test extraction" sends one
   document's first 2,000 characters and shows the edges.
3. **`graph_rebuild_all`** (`other`, admin) = forget then re-queue every document of a library (a `full` library may cost the month's budget — the
   estimate is shown first and the button says so).
4. **A find is logged `search.graph`** with the query cut at 120 and the count; a `find` call to the engine counts in `graph_usage` (`kind find`).
5. **Related documents** on the document page are computed on open for an indexed document (`dc_graph_candidates()` on its subjects, capped,
   the wall) and cached in the session for five minutes — never stored.

## Who may do what
| Action | Right | Level |
|---|---|---|
| graph_find, graph_related, the explorer | `graph.use` | — (a guest finds only what is named to them: the wall) |
| graph_policy_set on a library or folder | `graph.manage` (admin) | `other` for agents; a curator may set `none`/`index` on a library they manage (no model spend) |
| graph_budget_set, graph_model_set, graph_rebuild_all | `graph.manage` | `other` |
| graph_job_retry, the queue | `graph.manage` | — |
| graph_usage, the estimate | `costs.read` / `graph.manage` | — |

## Screens (the explorer designed at 1280 and works at 375; find is read on a phone)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `graph` | `/graph?q=&library=&folder=&tag=` | **Find**: the box ("documents about…"), the filters as chips, results (`dc_graph_find()`: name (link), folder crumb, the matched subjects as chips, the best passage's excerpt from DocCloud's own `document_text`, score bar), "N found of M candidates"; `graph_unavailable` → one sentence and the keyword search link; **Explore** (a tab): subjects by degree in the chosen library (`dc_graph_god_nodes()` filtered to subjects with a document the caller may see), a subject search, the policy and the queue state in a footer ("index: 3,412 documents · full: 120 · queued 14 · this month 38 MB of 100") |
| `subject-view` | `/graph/s/{id}` | the subject (name, type), its documents (the wall), its neighbours as a list (and at 1280 a small SVG neighbourhood: ≤ 40 nodes, documents as squares, subjects as circles, click to navigate), "path to…" (a subject picker → `graph-path`), "find documents about this" |
| `graph-path` | `/graph/path?a=&b=` | the path(s) between two subjects as a chain of chips; the documents along it |
| `graph-admin` | `/admin/graph` | the policy per library (a table: library, policy select, documents in the graph by policy, queued, Requeue); **the budget** (MB a month; the month's bar from `dc_graph_budget_state()`); **the model** (`dc_graph_config_get()`: provider, alias, embedding model, `secret_ref` — changed with `graph_model_set`, which pauses: provider base URL (the ledger proxy), the credential pasted once, the alias, the embedding model; "Test extraction" with a document picker); **the queue** (`mcp_graph_jobs`: document, kind, status, attempts, error, Retry · Skip); **usage** (`mcp_graph_usage` by month and kind); `graph_unavailable` shown plainly |
| the folder page gains | `/f/{id}` | for `graph.manage`: the effective policy chip and "Policy…" → `graph-admin` (the policy part) |
| `graph-admin` (the policy part) | `/f/{id}/graph` · `/libraries/{id}/graph` | the policy (none · index · full, one line each with the cost), inherit (folders), **the estimate** when `full` (`dc_graph_estimate()`: documents, characters, calls, over the budget → the button disabled with the sentence) |
| the document page gains | `/d/{id}` | "In the graph" chip (policy, indexed when) and **Related documents** (≤ 8 cards: name, shared subjects) |

## The worker's pass (`app/features/graph/worker_steps.php`: `worker_graph(PDO, int $maxJobs = 10, int $seconds = 50)`)
`dc_graph_next_job()` → by kind: `forget` → `dc_graph_forget(maludb_document_id)` (missing counts as done); `index` → the text (`document_text`,
first `graph_text_chars`), `dc_graph_subjects()` → `dc_graph_index_document()`; `full` → `dc_graph_full_document()` (chunks of
`graph_full_chunk_chars`; `no_edges` is done with no engine row — recorded in the job's error as a note) — then `dc_graph_job_done()` or
`dc_graph_job_fail()`; `graph_unavailable` fails the job without counting an attempt (the queue waits for the engine); one job at a time, up to
`maxJobs` per pass or the time budget; `dc_pass_graph_catch_up(200)` once a pass. Replace = the trigger queued a new job for the new version;
the done handler's upsert of `graph_documents` means the old engine document is forgotten by a `forget` job the worker queues when
`maludb_document_id` changes (in `worker_graph`, after done: if the previous id differs, insert a forget job for it).

## `dc_graph_find()` (`app/features/graph/find.php`: the one function behind the screen, the tool, the API and the expert)
`graph_find(PDO, string $q, array $filters, int $limit = 25): array{status, subjects, rows, candidates, took_ms}`: (1) `dc_graph_resolve_subjects($q)`
+ the filters' tag → subject names; (2) `dc_graph_candidates($subjects, $q, 200)`; (3) `dc_graph_filter(jsonb, $limit × 3)` — the wall in SQL;
(4) the filters (library, folder subtree) applied in PHP on the filtered rows, the excerpt from `document_text` around the first matched
subject's name or the query's words, ranked by score. `graph_unavailable` → status with no rows. `graph_related(PDO, int $documentId, int $limit = 8)`.

## Files (exactly these)
- `html/graph/index.php` (`graph`, replaces the placeholder) · `html/graph/s.php` (`subject-view`) · `html/graph/path.php` (`graph-path`) · `html/graph/policy.php` (`graph-admin` (the policy part)) · `html/graph/policy-save.php`
- `html/admin/graph.php` (`graph-admin`) · `html/admin/graph/{budget,model,test,retry,skip,rebuild}.php`
- `app/features/graph/{queries,find,present,write,worker_steps}.php`, `app/doccloud/graph_prompt.php`
- `app/views/graph/{index,subject,path,policy,partials/result,partials/subject-chip,partials/neighbourhood-svg,partials/related}.php`, `app/views/admin/graph.php`
- `app/pickers.php` gains `subject` (through `dc_graph_resolve_subjects()`; engine-backed, cached 60 s)
- `tests/phase3/slice5/{run.sh,lib.php,policy.php,queue.php,find.php,explore.php,admin.php,json.php,browser.mjs}` and **`tests/fake_engine.php`** (the fake
  MaluDB API: remember, documents, search, recall, graph/query, neighbors, subjects, documents/{id} DELETE, config — deterministic answers from the
  requests it saw; port 8703)

## Query functions (signatures fixed)
- `graph_find(...)` and `graph_related(...)` above · `graph_state(PDO): array{available, by_policy, queued, running, failed, budget}` · `library_policies(PDO): array` ·
  `effective_policy(PDO, int $folderId): string` · `graph_estimate(PDO, int $folderId): array` · `graph_jobs(PDO, array $filters, int $page): array` · `graph_usage(PDO): array` ·
  `graph_model(PDO): array` (never the credential) · `subject_page(PDO, int $subjectId): array` · `subject_path(PDO, int $a, int $b): array` · `god_nodes(PDO, ?int $libraryId): array`
- writes: `graph_policy_set(PDO, string $kind, int $id, ?string $policy, int $by): int` (requeues) · `graph_budget_set` · `graph_model_set(PDO, array $cfg, int $by)` ·
  `graph_test_extraction(PDO, int $documentId): array` · `graph_job_retry(PDO, int $jobId)` · `graph_job_skip` · `graph_rebuild_all(PDO, int $libraryId, int $by): int`

## MCP tools this slice makes real
`graph_find`, `graph_related`, `document_subjects`, `graph_subjects`, `get_subject`, `subject_neighbors`, `graph_path`, `graph_queue`, `graph_estimate`, `graph_usage`.

## Manifest rows claimed
Screens (5): `graph`, `graph-explore`, `subject-view`, `graph-path`, `graph-admin`
Actions (7): `graph_policy_set`, `graph_budget_set`, `graph_model_set`, `graph_rebuild_all`, `graph_job_retry`, `graph_job_skip`, `graph_test_extraction`

A find (`/graph/?q=`) and a related-documents refresh are GETs, never actions. The policy of a library or a folder is set from `graph-admin` and from the library and folder edit forms (`graph_policy_set` is the one action); there is no separate policy form screen.

## Activity log events
`graph.queue|index|full|forget|fail|skip|retry|policy_set|budget_set|model_set|test|rebuild|catch_up`, `search.graph`. The credential never; a document's text never; a
query cut at 120.

## Notifications this slice queues
`graph_budget` (the admin, once a month at 80% and at 100%).

## Status vocabulary
Policy chip: none `light`, index `info`, full `primary`, inherited `secondary`. Job: queued `secondary`, running `info`, done `success`, failed `danger`, skipped `dark`.
Ids: `graph-find-box`, `graph-results`, `graph-result-{id}`, `graph-explore-tab`, `graph-subject-{id}`, `graph-svg`, `graph-policy-select`, `graph-estimate`, `graph-queue-table`, `graph-budget-bar`.

## Out of scope for this slice
The Clerk's tag proposals from the graph's subjects (8), the API's `/graph/find` route (7), community detection (M6, upstream).

## Proof (`tests/phase3/slice5/run.sh`: scratch database `dc_dev5`; the app on 8701, the fake kernel on 8702, **the fake engine on 8703** (`tests/fake_engine.php`;
`tests/phase0/engine.sh` stays the proof against the real API), the fake MaluDB on 8706; `bin/worker.php` with `DC_WORKER_NOW`; Playwright; the registry `--check`)
1. The default policy is `index`: a document with text queues an index job; the worker sends `remember` with the eight subjects the design names (tags first); `graph_documents` records the engine id; usage counts.
2. A folder set `full` (the estimate shown: documents, characters, calls, budget) requeues its documents as full; the index jobs are skipped; the worker sends `documents` with the registered-type prompt; `no_edges` is done without an engine row.
3. The budget: a full job over the month's remainder waits (queued, the admin's screen says so); the budget raised releases it; `graph_budget` notified at 80%.
4. A new version replaces: a new job, the old engine document forgotten (a forget job for the old id); trash queues a forget; purge too; policy `none` forgets.
5. `graph_unavailable` (the fake engine stopped): a find answers the sentence, the keyword search still works, the worker leaves the jobs queued with attempts unchanged.
6. `dc_graph_find()`: "acme contract" resolves subjects from terms and recall; the candidates mapped by engine id; **the wall** — Bea, Priya, the guest and the admin each get their own set (the Phase 0 cases); an unknown candidate id dropped; the excerpt from the document's own text; `search.graph` logged cut at 120.
7. Related documents on a document page: shared subjects, the wall, ≤ 8, none for a document not in the graph.
8. The explorer: god nodes filtered to the caller's documents; a subject page with its documents and neighbours; a path between two subjects; the SVG at 1280 with ≤ 40 nodes.
9. The admin: the model shown without the credential; `graph_model_set` by an agent pauses, by the admin writes the config to the fake engine; "Test extraction" shows edges; a job retried and one skipped; `graph_rebuild_all` on a library forgets and requeues.
10. A curator sets `index` on their library (free), is refused `full` (403 in words); a guest finds only what is named.
11. JSON mode on every screen and handler; the run-token gate: an agent runs `graph_find` and `document_subjects`, and `graph_policy_set` pauses.
12. Browser at 375 and 1280: find with results and chips, the explore tab, a subject page with the SVG at 1280 and the list at 375, the policy form with the estimate; no console errors.
13. Registry `--check`: 5 screens built, 9 actions, 4 approvals in step.

## Open questions
None.
