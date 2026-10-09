# Build spec: tags, the keyword index and search (slice 4)

What exists at the end: a document carries tags — managed ones from the curator's vocabulary and free ones anyone adds — edited inline on
the document page and in bulk from a listing; a folder's auto-tags apply on upload; a free tag three people use is proposed to the curator,
who promotes or merges it (the old slug kept as an alias); **the search box** on every screen answers from the keyword index with the
filters as chips, excerpts marked, every result something the asker may see; a misspelt name is found by trigram; a search is saved; what
found nothing feeds the search report (slice 9). The index is PostgreSQL's (D7); the worker's catch-up keeps it whole.

Schema (db/009, 010, 013, 014, never modified): `tag-list`, `tag_aliases`, `document_tags`, `tag_proposals`, `dc_slugify()`, `dc_tag_resolve()`,
`dc_tag_ensure()`, `dc_tag_apply()`, `dc_tag_remove()`, `dc_tag_merge()`, `dc_documents_auto_tags()` (trigger), `dc_document_tag_slugs()` (db/009);
`search_index`, `dc_search_tsv()`, `dc_index_document()`, the triggers, `dc_search_catch_up()`, `dc_search()`, `dc_search_names()` (db/010);
`saved_searches`, `search_log` (db/013); `dc_untagged()`, `dc_search_gaps()` (db/014); `mcp_tags`, `mcp_document_tags`, `mcp_tag_proposals`,
`mcp_saved_searches`, `mcp_search_log` (db/015). **The database is the referee**: a slug's shape, one row per document and tag, the usage
counts, the promotion at the threshold, a merge's alias, the index following the document by trigger, the wall inside `dc_search()`.

## Divergences from the design
1. **Tag editing on a document needs `edit`** (design §3 gives `tags.apply` to Members: the right says who may tag at all, the level says
   which documents). A `view` holder sees the chips and cannot change them.
2. **Bulk tagging** (`tag_apply` with `document_ids[]`) from a listing's select bar applies to the documents the caller may edit and reports
   how many were skipped.
3. **The search page is a GET** (`/search?q=&library=&folder=&owner=&tag=&kind=&after=&before=&mine=&shared=`); the log row is written by the
   page handler (`search.keyword`, the query cut at 120, the count, the ms), never by the tool (the records server logs `search` itself as
   source `mcp`).
4. **`tag_delete`** (`deletion`) is a hard delete allowed only for a tag with no document; an in-use tag is archived (`tag_archive`) and its
   rows stay (the chips show it `dark`).

## Who may do what
| Action | Right | Level |
|---|---|---|
| tag_apply / tag_remove on a document | `tags.apply` | `edit`+ on the document |
| tag_create (free) | `tags.apply` | — (anyone makes a free tag by typing it) |
| tag_create (managed), tag_update, tag_merge, tag_archive, tag_delete, proposals | `tags.manage` | curators |
| folder_auto_tags_set | `documents.edit` | `edit`+ on the folder (slice 1's handler; this slice proves the trigger) |
| search, saved searches | `search.use` | — (a guest searches what is named to them) |
| the search report | `reports.search` | slice 9's screen; `dc_search_gaps()` proven here |

## Screens (375 px first — the search box and its results are read on a phone)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `tag-list` | `/tags?q=&kind=&archived=` | the vocabulary as chips with counts (`mcp_tags`: managed first, then free by usage; a chip opens `tag-view`; colour); **New tag** (curators); the **proposals** list (`mcp_tag_proposals` open: "acme-corp — 3 people use it" Promote · Dismiss; "merge x into y" Accept · Dismiss; "apply t to document d" Accept · Dismiss) for curators |
| `tag-view` | `/tags/{id}` | the tag, its aliases, its documents as the listing table (the wall: `find_documents` by tag); Edit · Merge into (the `tag` picker) · Archive/Restore · Delete |
| `tag-add` / `tag-edit` | `/tags/new` · `/tags/{id}/edit` | label (the slug shown as it will be), kind (managed · free — curators), colour |
| `tag-rules` | `/f/{id}/tags` | the folder's auto-tags (the `tag` picker, multi; new slugs allowed) and the nearest upward rule shown; posts `folder_auto_tags_set` |
| `search` | `/search?q=…` | the box (autofocus; the typed modifiers `tag:x kind:pdf owner:me in:<folder id> after:2026-01-01 before: mine shared` parsed into the filters object), the filters as removable chips, the results (`dc_search()`: name (link), the folder crumb, kind, size, owner, tags, the excerpt with `<mark>`, modified; 25 a page; "N results"), a second strip "Did you mean…" from `dc_search_names()` when the first page is short, "Save this search", "Try the graph" (slice 5's link with the same query) when results < 3; an empty query with filters lists |
| `saved-searches` | `/searches` | mine and the shared ones (`mcp_saved_searches`): name, query, filters as chips, Run · Edit · Delete; Save from the search page names it |
| the document page gains | `/d/{id}` | the tag editor: chips with ×, an input with a datalist of the vocabulary (`tags_for_pick()`), Enter adds (a new label makes a free tag), posts `tag_apply` / `tag_remove` per chip (HTMX) |
| the listing's select bar gains | — | **Tag** (a chip input → `tag_apply` bulk) |

## The index and the search
- `dc_index_document()` runs by trigger on a document's name, description, folder, owner, version, trash and on its tags and text; the worker's
  `search_catch_up` step (`dc_search_catch_up(500)` a pass) repairs what a bulk load (`app.dc_bulk`) or a crash missed.
- `search_parse(string $typed): array{q, filters}` (`app/features/search/parse.php`): modifiers out, the rest the query; `tag:` resolves through
  `dc_tag_resolve()` (an alias works); `owner:me` → the caller; `in:` a folder id or a path fragment matched against the caller's visible
  folders (`folders_for_pick()`); unknown modifiers stay in the query.
- The page calls `dc_search(q, filters, 25, offset)`; the total from the window; `took_ms` measured; the log row `search.keyword` with the query
  cut at 120 and `results`; a `name` strip from `dc_search_names()`.
- The records tool `search` (Phase 4) takes the same `filters` object and answers the same rows (the wall is in the function).

## Files (exactly these)
- `html/tags/index.php` (`tag-list`, replaces the placeholder) · `view.php` (`tag-view`) · `form.php` (`tag-add` / `tag-edit`) · `save.php` · `merge.php` · `archive.php` · `restore.php` ·
  `delete.php` · `html/tags/proposals/{accept,dismiss}.php` · `html/d/tags/{apply,remove}.php` · `html/f/tags.php` (`tag-rules`)
- `html/search/index.php` (`search`, replaces the placeholder) · `html/searches/index.php` (`saved-searches`) · `html/searches/{save,delete}.php`
- `app/features/tags/{queries,present,write}.php`, `app/features/search/{queries,parse,present,write}.php`, `app/features/files/worker_steps.php` gains `search_catch_up`
- `app/views/tags/{index,view,form,rules,partials/chip,partials/proposal-row,partials/editor}.php`, `app/views/search/{index,partials/result,partials/chips,saved}.php`
- `app/pickers.php` gains `tag` (live tags; `allow_new`)
- `assets/js/tag-editor.js` (the chip input; HTMX posts)
- `tests/phase3/slice4/{run.sh,lib.php,tags.php,proposals.php,index.php,search.php,saved.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `find_tags(PDO, array $filters): array` · `find_tag(PDO, int $id): ?array` · `tag_documents(PDO, int $tagId, int $page): array` · `tags_for_pick(PDO, string $q, array $params, int $page): array` ·
  `document_tags(PDO, int $documentId): array` · `open_proposals(PDO): array` · `untagged(PDO, ?int $folderId, int $limit): array`
- `search(PDO, string $typed, int $page): array{q, filters, rows, total, took_ms, names}` · `my_saved_searches(PDO): array` · `search_gaps(PDO, string $since): array`
- writes: `tag_create(PDO, string $label, string $kind, ?string $colour, int $by): int` · `tag_update` · `tag_apply(PDO, array $documentIds, int|string $tag, int $by, string $source = 'person'): array{applied, skipped}` ·
  `tag_remove(PDO, int $documentId, int $tagId)` · `tag_merge(PDO, int $from, int $into, int $by): int` · `tag_archive` · `tag_restore` · `tag_delete` · `tag_propose(PDO, string $kind, array $f, int $by): int` ·
  `proposal_accept(PDO, int $id, int $by)` · `proposal_dismiss` · `saved_search_save(PDO, array $f, int $by): int` · `saved_search_delete` · `search_log_write(PDO, string $kind, string $q, array $filters, int $results, int $ms)`

## MCP tools this slice makes real
`find_tags` (the resolver), `tag_proposals`, `documents_by_tag`, `untagged`, `search` (the records server's guarded search IS this), `saved_searches`,
`run_saved_search`, `search_gaps`.

## Manifest rows claimed
Screens (8): `tag-list`, `tag-view`, `tag-add`, `tag-edit`, `tag-proposals`, `tag-rules`, `search`, `saved-searches`
Actions (12): `tag_create`, `tag_update`, `tag_apply`, `tag_remove`, `tag_merge`, `tag_archive`, `tag_restore`, `tag_delete`, `tag_propose`, `tag_proposal_decide`, `saved_search_save`, `saved_search_delete`

Folded: `tag_proposal_accept` and `tag_proposal_dismiss` are `tag_proposal_decide` with `decision`; `search_save` from the search page is `saved_search_save`. A search is a GET (`/search?q=`), never an action. `folder_auto_tags_set` is slice 1's row, proven again here.

## Activity log events
`tag.create|update|apply|remove|merge|archive|restore|delete|propose|accept|dismiss`, `search.keyword|name|save|delete`, `index.catch_up` (the worker, with the count).
A query is logged cut at 120 characters; a document's text never.

## Status vocabulary
Tag chip: managed `primary` (or its colour), free `secondary`, archived `dark`, alias `light`. Proposal: proposed `warning`, accepted `success`, dismissed `dark`.
Ids: `tag-chip-{id}`, `tag-editor`, `tag-input`, `proposals-list`, `search-box`, `search-chips`, `search-results`, `search-result-{id}`, `search-names`, `search-save-btn`.

## Out of scope for this slice
The graph (5), the Clerk's tag proposals from the graph (8 — `tag_propose` exists here for it), the search report screen (9).

## Proof (`tests/phase3/slice4/run.sh`: scratch database `dc_dev4`; ports as slice 2; seeded documents with text through `dc_version_add()` + `document_text`; Playwright; the registry `--check`)
1. A curator makes a managed tag; a member types a new label on a document → a free tag with the slug the design gives; a duplicate label resolves to the existing tag; a slug collision with an alias resolves to the merged tag.
2. Apply once counts once; remove recounts; bulk apply to three documents where one is view-only → applied 2, skipped 1.
3. Three people using a free tag → one promote proposal; accept makes it managed; dismiss closes it; a merge proposal accepted merges and keeps the alias; `tag:` with the alias in a search finds the documents.
4. A folder's auto-tags apply to a new upload (the nearest rule upward), source `rule`; a deeper folder's rule wins over the parent's.
5. The index: every seeded document has a row; renaming, tagging, a new version and trashing change it by trigger; a bulk load with `app.dc_bulk` and the catch-up repairs it; a trashed document leaves it.
6. The search: a word in the text, in the name, in a tag, in the description — ranked name first; a phrase; a `-word`; the filters (library, folder subtree, owner, mine, tag, kind, size, dates, shared) each narrow; the excerpt holds `<mark>`; the wall — Bea, Priya, the guest and an agent each get only their own set (the Phase 0 proof's cases re-run through the page); 25 a page with the total; `took_ms` logged, the query cut at 120.
7. "Did you mean" from a misspelt name; "Try the graph" shown under 3 results.
8. Saved searches: saved from the page, listed, run (the same results), shared one visible to another member, deleted.
9. `search_gaps` clusters the empty searches; an agent's run token runs `search` and `tag_apply`; `tag_delete` by an agent pauses.
10. JSON mode on every screen and handler; the typed modifiers parsed as the spec says (ten cases).
11. Browser at 375 and 1280: the tag editor adding and removing a chip, the search page with chips and a marked excerpt, the tags page's proposals; no console errors.
12. Registry `--check`: 6 screens built, 16 actions, 1 approval in step.

## Open questions
None open. Decisions taken while building (2026-10-09), the design's way:
1. **The manifest's URLs win over this spec's file list** (the consistency decision): the proposals screen is `/tags/proposals` (`html/tags/proposals/index.php`), the rules
   `/tags/rules` (every folder with a rule; `?folder=` opens one folder's form — slice 1's `folder_auto_tags_set` posts), saved searches `/search/saved`, the actions
   `/tags/apply.php`, `/tags/remove.php`, `/tags/proposals/decide.php`, `/tags/propose.php`, `/search/save.php`, `/search/delete.php`; a document's page is `/documents/{id}`.
2. **`tag_apply` takes `document` or `documents[]`** (the listing's select bar; the documents gained a checkbox), and `tags[]` as a list or a comma string (an agent's
   shape); a document the caller may not edit is skipped and counted in bulk, a 403 when it is the only one.
3. **A guest holds no `search.use`** (the roles of design §3), so `/search` is 403 for a guest; `dc_search()` itself walls a guest to what is shared with them (the
   records tool, when granted) and Shared with me lists it. The Phase 2 tab bar already said so.
4. **A member cannot see a promote or merge proposal** (`mcp_tag_proposals`: `tags.manage`, or edit on the document for an apply): deciding one answers 404,
   the same nothing. An apply proposal is decided by whoever may edit the document.
5. **`tag_proposal_decide` answers `outcome`** (promoted | applied | merged | dismissed) beside `did` (the sentence); the log event is `tag.decide` as the manifest says.
6. **`tag_update` never changes the slug** (every document carries it); label, colour and kind change. `tag_create` of an existing label answers the existing tag
   with `existed: true` (a curator asking for managed promotes it).
7. **The `in:` modifier** takes a folder id or a path fragment matched against the folders the caller may see (first by depth); an unknown fragment is a filter that
   matches nothing. `tag:` and `kind:` likewise: an unknown value stays a filter that matches nothing, shown as a chip "(unknown)".
8. **The capped total**: `dc_search()` cuts its candidates at 2,000 before the batch wall (db/016); the page says "about N" when the total reaches the cut.
9. **The editor is a form**: Enter submits (assets/js/tag-editor.js), the page lands on the document at `#document-tags`; no custom chip widget.
10. **The folder page shows its rule** as chips on the access line with "Tag on upload / change" for a manager (the rules screen's form).
11. **The worker's catch-up** runs `dc_search_catch_up(500)` every pass and logs `index.catch_up` only when it repaired something.
12. **The search report** (`dc_search_gaps`) is proven here through `search_gaps()`; its screen is slice 9's.
Found on the way: a seed text naming a word the proof negates ("Nothing about Acme here") is a trap — the texts are chosen so each word lands where the check says.
