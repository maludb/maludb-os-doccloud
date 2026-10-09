# Build spec: the document graph (slice 5 — THE SECOND EXEMPLAR; the worker's graph pass; the explorer; the admin's model and queue)

The graph is MaluDB's, in a memory database of DocCloud's own (`<tenant>_doccloud_memory`, namespace `dc`; D3). The application
decides **what enters it** (the policy, the queue, the budget — db/012), **what it is told** (a document's known subjects, its
text), and **who sees what comes back** (`dc_graph_filter`, SQL — the wall). `app/doccloud/graph.php` is the ONE file that reads
`MALUDB_DC_API_URL` / `MALUDB_DC_API_TOKEN`; nothing else calls the engine; nothing here calls a model. What the engine actually
answers is `tests/phase0/engine/FINDINGS.md` — this spec builds on it, not on the API's README. Design §0.3, §6.3, D4, D8.

Facts that bind (FINDINGS.md): `source_type` is a controlled vocabulary — both policies store `'document'`; `document_type = 'dc'` is
set only by the `full` policy (`remember` sets none) — **the policy a document is under is `graph_documents.policy`, never asked of
the engine**; the `full` extraction may name only REGISTERED subject types (`project, person, ai_agent, equipment, software, network,
event, process, workflow, time_period, other, stakeholder, concept`) or it fails after the model was paid; `remember` takes at most
eight subjects (M12); `documents` reports no usage (M2 — K24 meters at the proxy); `memory/search` returns the engine's `document_id`
and no metadata, ignores `document_ids[]` (M10), needs a `subject`; `graph/query`'s `namespace` is a name prefix (not used); a
document the model extracts nothing from is `no_edges` (no document kept); no bulk or asynchronous ingest exists (M11).

## 1. Files (exactly these)
| File | Concern |
|---|---|
| `app/doccloud/graph.php` | built in Phase 0 — the engine functions (§2); slice 5 adds nothing to it but may fix a defect it proves |
| `app/features/graph/queries.php` | `dc_graph_find()`, `dc_graph_related()`, `dc_graph_explore_*()`, `dc_graph_policy_set()`, `dc_graph_text_for()`, the queue and usage reads |
| `html/graph/index.php` (find and explore), `html/graph/subject.php`, `html/graph/path.php`, `html/graph/admin.php` (policy, budget, queue, usage, the model) | the screens (§7) |
| `html/f/graph_policy.php`, `html/libraries/graph_policy.php` | the policy actions |
| `bin/worker.php` → `worker_pass_graph_jobs`, `worker_pass_graph_catch_up` (added to `WORKER_PASSES` after `search_catch_up`) | the pass (§4) |
| `skills/finding-documents/SKILL.md` | already names the tools; the expert's rule: `graph_find` only when asked (D4) |
| `tests/slice5/run.sh` + `tests/slice5/fake_provider.py` (Phase 0's `tests/phase0/engine/fake_provider.py` reused) | the proof (§10) against a scratch memory database, as `tests/phase0/engine.sh` does it |

## 2. The engine functions (graph.php, as built) and how the slice calls them
| Function | Route | The slice uses it for |
|---|---|---|
| `dc_graph_available(): bool` | — (the token and URL are set) | every screen and the pass: false → `graph_unavailable` (§8) |
| `dc_graph_config_get()`, `dc_graph_config_set(cfg)` | `GET/POST /v1/memory/config` (namespace `dc`) | the admin's model screen (§6) |
| `dc_graph_index_document(doc, text, subjects, meta)` → `{status: indexed, maludb_document_id, chunk_count, statements, subjects, subjects_dropped, text_chars}` | `POST /v1/memory/remember` | the `index` policy: no model call; ≤ 8 subjects (the dropped named) |
| `dc_graph_full_document(doc, text, subjects, meta)` → `{status: extracted\|no_edges, maludb_document_id, chunk_count, edges_count}` | `POST /v1/memory/documents` (`document_type 'dc'`, chunks 2,000/200) | the `full` policy: the namespace's prompt, one model call per chunk |
| `dc_graph_forget(maludb_document_id)` → `{status: forgotten\|missing}` | `DELETE /v1/documents/{id}` | a forget job; a replace's first half |
| `dc_graph_resolve_subjects(query, limit 8)` → `{subjects: [{subject_id, name, score, via: terms\|recall}]}` | `GET /v1/graph/query` + `POST /v1/memory/recall` | step 1 of a find |
| `dc_graph_candidates(subjects, query, limit 200)` → `{candidates: [{maludb_document_id, document_id: null, subject, similarity, chunk_id, via: search\|neighbors}], subjects_used}` | `POST /v1/memory/search` per subject (≤ 8) + `GET /v1/graph/neighbors` | step 2 of a find — **`chunk_text` is dropped before anything leaves `queries.php`**: a caller is shown excerpts from `document_text` only |
| `dc_graph_subject(id)`, `dc_graph_subject_id(name)`, `dc_graph_neighbors(kind, id, direction, rel)`, `dc_graph_path(a, b, depth)`, `dc_graph_god_nodes(limit)`, `dc_graph_surprises(limit)`, `dc_graph_stats()` | the graph routes | the explorer (§7) — every `document` node is judged (§3.4) before display |
| `dc_graph_document(id)`, `dc_graph_documents(limit, offset)` | `GET /v1/documents…` | the admin's reconciliation (§6.4) |
Every failure is `{status: graph_unavailable|graph_timeout|graph_auth|graph_forbidden|not_found|no_edges|model_failed|invalid_argument|graph_error,
http, error}` — never a throw, never the token or the URL (`dc_graph_scrub`).

## 3. Finding (`dc_graph_find(PDO $pdo, string $q, array $filters = [], int $limit = 25): array`)
The one function behind `/graph` (find), the records tool `graph_find`, `POST /api/v1/graph/find`, and the expert when the asker
asks for it. `$filters`: `library` (id), `folder` (id → its subtree), `tag` (slug), `kind` (mime_kind), `owner` (member id).
1. **Gate**: `app_is_active_member()` else nothing; `dc_graph_available()` else `['status' => 'graph_unavailable', 'documents' => []]`
   (the screen says "The document graph is not available; keyword search still works" and links `/search?q=`).
2. **Subjects**: `dc_graph_resolve_subjects($q, 8)`; add the filters' own: a `tag` filter adds `tag:<slug>`, a `folder` adds `folder:<name>`,
   a `library` adds `library:<name>`, an `owner` adds `owner:<display_name>` (the exact subject strings `dc_graph_subjects()` makes, db/012);
   dedupe; keep ≤ 8 (the filters' first, then by score). None → `status: no_subjects` (the screen: "Nothing in the graph matches those
   words; try the keyword search") — no engine call for candidates, `search_log` written with 0 results.
3. **Candidates**: `dc_graph_candidates($subjects, $q, min(200, $limit * 3 + 50))` — over-fetched ×3 against the wall (M10). The
   engine's `document_id` is `maludb_document_id`; DocCloud's `document_id` is NOT in the answer (no metadata on search): the map is SQL.
4. **The wall**: one statement — `SELECT * FROM dc_graph_filter(:candidates::jsonb, :limit)` with the candidates as
   `[{maludb_document_id, similarity, subject}]` → the documents the caller may see (`dc_can_see_document`), with `score`, `subjects`,
   `hits`, best first. A filter on `library`/`folder`/`kind`/`owner` is applied in the same statement (the function's rows joined to
   `documents`; the slice may add a `p_filters jsonb` parameter to `dc_graph_filter` in db/016 — a schema gap, see §11).
5. **Excerpts**: for each document kept, the best passage from **DocCloud's own text**: `dc_graph_text_for($pdo, $document_id, $q,
   $subjects)` → `ts_headline('simple', left(text, 200000), websearch_to_tsquery('simple', unaccent(q)), 'MaxFragments=1, MaxWords=30,
   MinWords=10')` over `document_text` of the current version (NULL → the description, else the name). The engine's `chunk_text` never
   reaches a caller.
6. **Answer**: `{status: ok, query, subjects: [{name, via}], documents: [{document_id, name, folder_id, folder_path, library_id,
   owner_member_id, owner_name, mime_kind, tags, score, subjects, hits, excerpt}], total, over_fetched: n, took_ms}`; the page's "why":
   each document's matched subjects as chips.
7. **Log**: `INSERT search_log (member_id, kind 'graph', query left 120, filters, results, took_ms, source)` and `log_activity('search.graph',
   NULL, NULL, ['after' => ['length' => strlen(q), 'results' => n, 'subjects' => count]])` — the query's words never in the activity log.
8. `dc_graph_related(PDO $pdo, int $document_id, int $limit = 10)`: gate `require_document_level(view)`; the document's `graph_documents.subjects`
   (none → `[]` with "not in the graph yet"); for each subject (≤ 8) `dc_graph_subject_id(name)` → `dc_graph_subject(id)['documents']`
   (one call each, the cheaper read per FINDINGS.md §5) → candidates `{maludb_document_id, similarity: 1 / rank, subject}` without the
   document itself → `dc_graph_filter` → ranked by shared subjects; cached in the page for 60 s (`$_SESSION`), never stored.

## 4. The worker's graph pass (`worker_pass_graph_jobs(PDO, int $limit, DateTimeImmutable $now)`)
1. `dc_graph_available()` false → `['deferred' => queued count, 'reason' => 'graph_unavailable']` and nothing taken (the jobs wait).
2. Up to `$limit` jobs per pass under a **50-second budget** (stop taking jobs when `microtime` passes it; a `full` job's own call may take
   up to 60 s — one such job is the pass's last). Each: `SELECT * FROM dc_graph_next_job()` (forgets first; the oldest hour, smallest
   text; a `full` job waits when `chars > the month's budget left`; marked `running`, `attempts + 1`); none → done.
3. By `kind`:
   - `forget`: `dc_graph_forget(job.maludb_document_id)` → `forgotten` or `missing` both succeed → `dc_graph_job_done(job.id, NULL, '{}')`.
   - `index`: the document (`documents` by `job.document_id`; gone or trashed → `dc_graph_job_fail(id, 'gone')` and a forget is queued by
     the triggers), its text (`document_text` of `job.version_id`, first `dc_setting_int('graph_text_chars')` characters), its subjects
     `SELECT dc_graph_subjects(:id)` (tags first, then folder, library, owner, kind, year; plus `doc:<id>` as the first subject so the
     document is always its own subject — the function's list may be eight already: `doc:<id>` replaces the eighth). **Replace**: when
     `graph_documents` holds a `maludb_document_id` for this document, `dc_graph_forget` it first (a `missing` is fine). Then
     `dc_graph_index_document(['id' => id, 'name' => name], $text, $subjects, ['document_id', 'library_id', 'version_id', 'folder_id'])`
     → `indexed` → `dc_graph_job_done(job.id, maludb_document_id, subjects_kept, usage NULL)`; `subjects_dropped` non-empty → logged
     `graph.index` with `dropped: n` (M12); a failure → `dc_graph_job_fail(job.id, status . ': ' . error)` (three → `failed`).
   - `full`: as `index` but `dc_graph_full_document(...)`, the whole text (the engine chunks it); `extracted` → done with `edges_count` in
     `usage`; `no_edges` → `dc_graph_job_done(job.id, NULL, '{}')` with `status 'skipped'` instead (`UPDATE graph_jobs SET status = 'skipped',
     error = 'no_edges'` — a document the model found nothing in is not retried) and NO `graph_documents` row (nothing is in the engine);
     `model_failed` / timeouts → fail (retried thrice).
4. Log per job: `graph.index` / `graph.full` / `graph.forget` / `graph.fail` (`document_id`, `after` {job_id, chars, maludb_document_id,
   dropped, error}); the pass answers `{done, failed, skipped, deferred, budget_pct}`. **The month's budget** is read once per pass
   (`dc_graph_budget_state()`); `budget_pct ≥ 90` the first time in a month → `dc_notify` the admins (`graph_budget`, dedupe per month).
5. `worker_pass_graph_catch_up`: `SELECT dc_pass_graph_catch_up($limit)` once per pass (a bulk load, a failed trigger, a policy raised while
   the engine was down).
6. Triggers already queue: text extracted → index/full; a new version → replace; a move → re-evaluated policy; trash → forget; purge →
   forget by engine id; tags changed → re-index (db/012). The pass never decides a policy: `dc_graph_queue_document` did.

## 5. The `full` policy's prompt and the model (`dc_graph_config_set`)
1. The namespace's `prompt_template` (must contain `{{chunk}}`): the compiled extraction instruction — "From the text, extract the
   people, organisations, places, products, amounts, dates and topics it names as subjects, each with a type from THIS LIST ONLY:
   `person, stakeholder, project, equipment, software, event, time_period, process, workflow, concept, other`; an organisation is
   `stakeholder`, a date or period `time_period`, a product or contract term `concept`; each statement with a verbatim `source_span`
   copied from the text; answer `{"candidate_edges": [{subject_text, subject_type, verb_text, predicate, source_span, confidence}]}`" —
   written in `app/doccloud/graph_prompt.php` as `dc_graph_prompt_template(): string`; the admin may edit it on the model screen (textarea),
   the type list is validated on save (`dc_graph_prompt_types_ok()`: every type named is registered) — an unregistered type fails the
   extraction AFTER the model was paid (FINDINGS.md).
2. The model screen (`/graph/admin`, `graph.manage`): what `dc_graph_config_get()` answers (provider, alias, embedding model, prompt;
   `secret_ref` only — never a credential), a form (`provider_name`, `provider_kind` `openai|anthropic`, `model_alias`, `model_identifier`,
   `base_url` — the kernel's ledger proxy `http://127.0.0.1:8816/openai` or `/anthropic` (K24), `embedding_model`, `prompt_template`,
   `token` = the K24 credential pasted once, stored by MaluDB only); `graph_model_set` **pauses for an agent**; saved with
   `dc_graph_config_set()`; logged `graph.model_set` (alias, embedding model; never the token). `dc_settings.model_alias` /
   `embedding_model` mirror the admin's choice for the settings view.
3. "Test extraction": `dc_graph_full_document` on a 1,500-character synthetic text under `doc:test` into the namespace, then
   `dc_graph_forget` it; the screen shows `edges_count` or the failure's words. Costs one model call; logged `graph.test`.

## 6. Policy, estimate, the queue (the admin's and the managers' screens)
1. **Policy**: `libraries.graph_policy` (`none|index|full`) on the library's settings (manage of the library: its owner, a group owner, a
   curator for shared ones; `graph_policy_set` **pauses for an agent**); `folders.graph_policy` (NULL = inherit) on a folder's settings
   (manage). The handler: `UPDATE … SET graph_policy` then `SELECT dc_graph_requeue_folder(root|folder)` (queues or forgets the subtree;
   the pass does the engine work); the screen shows `dc_graph_estimate(folder)` **before** a `full` is chosen ("1,240 documents, 38 MB of
   text, about 22,000 model calls; this month's budget has 61 MB left — over budget: the queue will hold the rest until next month") and
   asks for the words "full" typed to confirm when over budget. Logged `graph.policy_set` {library_id|folder_id, from, to, queued}.
2. **Budget**: `dc_settings.graph_monthly_text_mb` on the graph admin screen (`graph_budget_set`, pauses); `dc_graph_budget_state()` as a
   meter (`full_chars`, `budget_chars`, `pct`, documents by policy this month).
3. **The queue** (`/graph/admin#queue`, `mcp_graph_jobs`): counts by status and kind, the oldest queued, the running one, failures with
   their error and a "retry" (`graph_job_retry`: `UPDATE graph_jobs SET status = 'queued', attempts = 0 WHERE id AND status = 'failed'`),
   "requeue all failed", `graph_rebuild_all` (pauses: every `graph_documents` row forgotten and every document requeued — `UPDATE
   graph_documents SET version_id = NULL` then `dc_pass_graph_catch_up(100000)`).
4. **Reconciliation** (admin, on demand): `dc_graph_documents(limit, offset)` paged against `graph_documents` — engine documents
   DocCloud does not know (orphans: offer "forget"), DocCloud rows the engine lost (`not_found` → `UPDATE graph_documents SET version_id =
   NULL` so the catch-up re-sends). Logged `graph.reconcile` with counts.
5. **Usage** (`/graph/admin#usage`, `mcp_graph_usage`, `costs.read`): `graph_usage` by month and kind (calls, chars, documents); dollars
   are the kernel's AI statement (K24) — a link to it.

## 7. The explorer (`/graph` "explore", `/graph/subject/{id}`, `/graph/path`; `graph.use`)
1. **Subjects by degree**: `dc_graph_god_nodes(50)` → for each subject, its documents (`dc_graph_subject(id)['documents']`) mapped through
   `graph_documents` and judged — a subject shown only with the count of documents the caller may see (zero → hidden). Filter by library:
   the count over `documents.library_id`.
2. **A subject** (`/graph/subject/{id}`): `dc_graph_subject(id)` — name, type, verbs, related subjects; its documents through the wall
   (`dc_graph_filter` over `[{maludb_document_id: d.id, similarity: 1}]`); a neighbourhood as a list at 375 px and a small inline SVG
   (≤ 40 nodes, drawn by `assets/js/graph-svg.js` from the JSON, no library) at 1280 px; "find documents about this" → `/graph?q=<name>`.
3. **A path** (`/graph/path?a=&b=`): two subjects picked (the record picker source `subjects`: `GET /v1/subjects?q=` through
   `dc_graph_subject_id`-style substring match, ≤ 20), `dc_graph_path(['subject', a], ['subject', b], 6)` → the chain; document nodes on it
   judged, those not visible shown as "a document you cannot see".
4. **Surprises**: `dc_graph_surprises(25)` — empty until MaluDB computes communities (M6); the screen says so.
5. **Every document node, everywhere**: `maludb_document_id` → `graph_documents` → `dc_can_see_document()` — one `dc_graph_filter` call per
   screen, never a per-node query.

## 8. Without the engine
`dc_graph_available()` false (no token, no URL) or every call `graph_unavailable`/`graph_timeout`: the find screen and tool answer
`status graph_unavailable` with the sentence and the keyword search link; `graph_related` answers `[]`; the explorer shows the sentence;
the pass defers; the policy screens still save (the queue waits); keyword search, uploads, sharing — everything else — works. The health
endpoint (`/api/v1/health`) reports `graph: ok|unavailable` (one cached `GET /v1/memory/config` a minute, never on every call).

## 9. Tools and actions this slice owns (names fixed by the manifest and the tool surface)
Records tools: `graph_find` (§3), `graph_related`, `document_subjects` (`graph_documents.subjects` of a visible document), `graph_subjects`
(§7.1), `get_subject`, `subject_neighbors`, `graph_path`, `graph_queue`, `graph_estimate`, `graph_usage`. Actions: `graph_policy_set`
(library and folder — two routes, one key with a `target`), `graph_budget_set`, `graph_model_set`, `graph_rebuild_all`, `graph_job_retry`,
`graph_test`. Pauses: `graph_policy_set`, `graph_budget_set`, `graph_model_set`, `graph_rebuild_all` (category `other`).

## 10. The proof (`tests/slice5/run.sh`: the schema on a scratch record database, the engine proof's scratch memory database and fake
provider (`tests/phase0/engine.sh`'s recipe: `doccloud_engine_scratch`, ports 8703/8704), php -S on 8701, the fake kernel)
1. Three documents with text under `index` (the default), one folder set to `full` by Marco: the queue holds three index jobs and one
   full; the pass runs: three `indexed` (`graph_documents` rows with `maludb_document_id`, `policy index`, subjects incl. `doc:<id>`,
   `tag:…`, `folder:…`), one `extracted` (the fake provider's edges), `graph_usage` counts the month.
2. `/graph?q=acme contract` as Bea: the subjects resolved (`acme`, `contracts`, `tag:contract`); the documents answered are exactly those
   `dc_can_see_document` allows — **a document the engine returned (Priya's notes, under `tag:acme`) is absent for Bea and present for
   Priya**; the excerpt comes from `document_text` (contains `<mark>`), never the engine's chunk text (the fake provider's canned span is not
   in the page); `search_log` has a `graph` row with the query cut at 120 and the activity log has `search.graph` without the words.
3. `/graph?q=` with words the graph never met → `no_subjects` and no candidates call (the fake API's request log shows none).
4. `graph_related` on the contract: the minutes (shared subjects) for Marco; for the guest, `[]`.
5. A new version of the contract: a replace — the fake API log shows a `DELETE` then a `POST` for it; `graph_documents.version_id` moved.
6. A purge: a `forget` job with the engine id, done; `GET /v1/documents/{id}` 404 at the API.
7. The budget: `graph_monthly_text_mb = 1` and a 2 MB text under `full` → the job stays `queued`, the pass answers `deferred 1`; the
   estimate screen says over budget; the index jobs still run.
8. The policy lowered to `none` on the folder → forget jobs for its documents; raised to `index` → index jobs; `graph.policy_set` logged
   with the counts.
9. The admin's model screen: `dc_graph_config_get` shows `secret_ref` and no token; a prompt naming an unregistered type (`entity`) is
   refused on save; "test extraction" answers `edges_count` and leaves nothing (the test document forgotten).
10. Without `MALUDB_DC_API_TOKEN`: `/graph` answers the sentence and links keyword search; `/search?q=` works; the pass defers; health
    says `graph: unavailable`; nothing throws (the error log is empty of exceptions).
11. The explorer: the subjects list hides a subject whose only document the caller cannot see; the subject page's documents pass the
    wall; a path between `acme` and `contracts` renders; surprises empty with the M6 sentence.
12. An agent's `graph_policy_set` pauses (the fake kernel's hook); its `graph_find` as a records tool answers what its member id may see.

## 11. Schema gaps found while writing this (for db/016, not this spec)
- `dc_graph_filter(p_candidates, p_limit)` takes no filters: a `p_filters jsonb` (library, folder subtree, kind, owner, tag) applied in the
  same statement would keep the find's filters inside the wall's one statement (today the slice would filter the function's rows again).
- `graph_jobs.status` admits `skipped` but no function sets it: `dc_graph_job_skip(p_job_id, p_reason)` (the `no_edges` case, §4.3) would keep
  the status rule in SQL; until then the slice's `UPDATE` does it.
- `dc_graph_subjects()` never includes `doc:<id>` (the design's "the document itself"); either the function adds it first (and keeps eight)
  or the pass prepends it as §4.3 says — one of the two, decided in db/016.
- `documents_graph` trigger fires on `UPDATE OF current_version_id` — fine; but `dc_graph_queue_document` returns `'current'` when
  `g.version_id = d.current_version_id AND g.policy = pol` and the tags trigger sets `version_id = NULL` to force a re-send: a NULL
  `version_id` also makes `dc_pass_graph_catch_up` queue it every pass until done — intended, but a job failing thrice then stays `failed`
  while catch-up keeps re-queueing (the unique index only blocks `queued|running`): catch-up should skip documents with a `failed` job
  younger than a day.
