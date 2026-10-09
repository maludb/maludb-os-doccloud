# The document graph — what MaluDB answered (tests/phase0/engine.sh, 2026-10-09)

`maludb_core` 0.106.0, the MaluDB API 0.3.1 (115 routes, no `/v1/memory/answer`), run on a scratch memory database
(`doccloud_engine_scratch`) with a fake model provider; embeddings were the API's fallback (no embedder configured → sha256
hash vectors, deterministic, NOT semantic — similarity ranks are not judged by this proof). 74 checks, driven only through
`app/doccloud/graph.php`. These are the facts Phase 1's `docs/build-specs/document-graph.md`, the lead's `dc_graph_find()` and
§12's M-items build on.

## The routes, field by field

| Route | Sent | Answered | Consequence |
|---|---|---|---|
| `POST /v1/memory/remember` (the `index` policy) | `text`, `subjects[]` (≤ 8), `verb`, `namespace`, `title`, `source_type`, `metadata{}`, `projects[]`, `chunk{}` | 201 `{document_id, namespace, embedding_model, subjects, verb, chunk_count, statements, extractor: "none"}` — a 201-character text under 8 subjects: `chunk_count 1, statements 8` (one edge per subject × chunk) | **no model call** (the fake provider counted 0); `metadata` IS stored (the route passes it to `documents_core`) but **no read route returns it** (below); the route sets **no `document_type`** (hard-coded `None`); **at most 8 subjects** — `dc_graph_index_document()` cuts the list and names the rest in `subjects_dropped` (**M12**) |
| `POST /v1/memory/documents` (the `full` policy) | `title`, `text`, `namespace`, `source_type`, `document_type: 'dc'`, `metadata`, `subjects[]`, `chunk{max 2000, overlap 200}` | 201 `{document_id, namespace, embedding_model, extractor, chunk_count, edges[…]}`; **`usage` ABSENT** (M2) | one model call per chunk through the namespace's own `prompt_template`; a text the model extracts nothing from → **422 `no_edges`** (no document kept; `dc_graph_full_document()` answers `status: no_edges`, not an error); a provider answering nonsense → **502 `upstream_error`** (`model_failed`) |
| `source_type` | `'dc'` | **422 — a foreign key**: `malu$source_type` is a CONTROLLED vocabulary (`document, conversation, ticket, log, database_record, api_payload, source_control, observability, event_stream, note, llm-chat, skill_file`) | both policies store `source_type = 'document'`; the policy a document entered under is DocCloud's own record (`graph_documents.policy`); the `full` ones also carry `document_type = 'dc'`; the memory database is DocCloud's alone (D3), so "every document in it" is the application's |
| `subject_type` in an extraction | `entity` | **422 after the model was paid**: `unknown subject_type entity. Register it in malu$svpor_subject_type first` — the registered types: `project, person, ai_agent, equipment, software, network, event, process, workflow, time_period, other, stakeholder, concept` | the `full` policy's prompt (slice 5) MUST name registered types only (person, time_period, other …) or a type DocCloud registers first; a subject made from a tag is typed `concept` |
| `GET /v1/documents/{id}` | | `{document: {id, title, source_type, media_type, document_type, primary_project_id, description, content_size, content_hash, created_at, updated_at, tags[{tag_id, tag_kind, tag_value, tag_object_type, tag_object_id, provenance, confidence}]}}` | **metadata ABSENT** (only `description` = `metadata->>'description'`); a remembered document's `document_type` is NULL |
| `GET /v1/documents?source_type=eq.document&limit=&offset=&order=id.desc` | | `{documents: [{id, title, source_type, media_type, document_type, primary_project_id, description, content_size, created_at}]}` | lists the graph's documents for the admin's reconciliation with `graph_documents`; no metadata column |
| `DELETE /v1/documents/{id}` | | 2xx; a second delete 404 | the document, its edges and chunks are gone: `memory/search` no longer returns it (proven), `GET` answers 404; **the subjects stay** (vocabulary is tenant-wide) |
| `POST /v1/memory/search` | `query`, `subject`, `namespace`, `limit ≤ 200` | `{namespace, embedding_model, results[{chunk_id, statement_id, document_id, source_text, distance, similarity, rank_no, subject_name, verb_name}]}` | **`document_id` PRESENT, `metadata` ABSENT** → a candidate's DocCloud document is found by mapping `maludb_document_id` through `graph_documents`, then judged by `dc_can_see_document()` in SQL; **without `subject` or `verb` → 400 `missing_field`** (the compartment is required); **a `document_ids[]` body field is IGNORED** (2 of 2 rows came back) — **M10 owed**: the wall over-fetches ×3 to the cap until then |
| `POST /v1/memory/recall` | `query`, `namespaces: ['dc']`, `limit ≤ 50` | `{query, namespaces, subjects_tried, subjects_proposed[{name, score}], verb, embedding_model, results[… + namespace]}` | with no subject it proposes subjects by trigram (`word_similarity ≥ 0.45` on canonical names): "acme contract" → `acme` 1.0, `contracts` 0.8 — `dc_graph_resolve_subjects()` adds these to the term matches |
| `GET /v1/graph/query?q=` | `q`, `depth`, `seeds`, `max_nodes` | `{query, namespace, depth, seeds[{subject_id, canonical_name, score}], nodes[], edges[]}` | term matches on canonical names and aliases ("acme contract" → acme, contracts, Finance › Contracts, Acme Corporation); **`namespace=dc` matched NOTHING** — that parameter is a canonical-NAME prefix (graphify imports), not the memory namespace: not used |
| `GET /v1/graph/neighbors?kind=subject&id=` | | `{kind, id, direction, neighbors[{neighbor_kind, neighbor_id, rel, edge_store, confidence, provenance, label}]}` | a subject's documents arrive as `neighbor_kind 'document'`, rel `mentions` (the subject tag) and `contains` (remember's edge); the document side lists its subjects the same way |
| `GET /v1/subjects?q=` / `GET /v1/subjects/{id}` | | `{subjects: [{id, label, type, description, classifier_md, linked_verbs, related_subjects}]}` / `{subject: {id, label, type, description, classifier_md, verbs[], related_subjects[], documents[{id, title, rel}]}}` | the name field is `label`, the id `id`; `?q=` is a SUBSTRING match (filtered to the exact name in `dc_graph_subject_id()`); **a subject's documents come straight from `GET /v1/subjects/{id}`** — the cheaper read when only the documents are wanted |
| `GET /v1/graph/path`, `/god-nodes`, `/stats`, `/surprises` | | `{…, paths}`, `{limit, god_nodes}`, `{stats}`, `{…}` (surprises empty: communities are never computed, M6) | tenant-wide, which here means DocCloud-wide |
| `GET/POST /v1/memory/config` | provider, alias (`base_url` = the fake; in production the kernel's ledger proxy, K24), `embedding_model`, `prompt_template`, `secret_name` + `token` | `{namespace, config{namespace, secret_ref, prompt_template, embedding_model, …}}` | the credential is stored by MaluDB and never returned (`secret_ref` only); `config_get` before any set answers 200 with a null config |
| the engine failing | a hanging engine (2 s timeout) · a bad token · an unreachable host | `graph_timeout` within the timeout · `graph_auth` (401) · `graph_unavailable` | never a throw; every function answers `graph_unavailable` with no URL or token |

## What this settles for the design (§6.3, §12)

1. **Visibility stays DocCloud's.** No search route returns a document's metadata and no filter narrows a search to a set of
   documents: `dc_graph_find()` maps every candidate's `maludb_document_id` → `graph_documents` → `documents` and judges it with
   `dc_can_see_document()` in one SQL statement, over-fetching ×3 to the route's cap (200 for `search`, 50 for `recall`).
   **M10** (a `document_ids[]` pre-filter on `search` / `recall`) is confirmed owed.
2. **The `index` policy is free** (no model call) and gives: documents by subject (tag, folder, library, owner, kind, year,
   `doc:<id>`), passages near a query within a subject, a subject's neighbourhood. Its documents carry no `document_type`
   and the controlled `source_type 'document'` — the policy is recorded in `graph_documents.policy`.
3. **The `full` policy's prompt names registered subject types only** (`person, time_period, other, …`) — an unregistered
   type fails after the model was paid. Slice 5's compiled prompt and the admin's "test extraction" must enforce it.
4. **A subject's documents** can be read from `GET /v1/subjects/{id}` in one call; `dc_graph_candidates()` uses `neighbors`
   (one hop, both rels) — either works; the subject page in the explorer should use the former.
5. `remember` caps subjects at eight (**M12**); `documents` reports no usage (**M2**, K24 meters at the proxy); a bulk or
   asynchronous ingest route does not exist (**M11**): the worker holds one HTTP request per document.
6. `graph/query`'s `namespace` is a name prefix; the memory database being DocCloud's own (D3) is what scopes every graph read.
