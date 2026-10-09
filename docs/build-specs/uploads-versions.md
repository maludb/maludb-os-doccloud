# Build spec: uploads, versions, blobs, serving and preview — THE FIRST EXEMPLAR (slice 2)

What exists at the end: a member drops a hundred files — a folder tree among them, a 300 MB video too — onto a folder page and watches them
land, one progress row each, de-duplicated; a same-named file asks "a new version, or keep both?"; a document has a page with a preview per
kind, its description, tags (slice 4 makes them real), the version list with restore, a lock, download; a folder downloads as a zip the worker
builds; the worker extracts text from every new version so the keyword index (slice 4) and the graph (slice 5) have words to read. The
mechanics — parts, resume, assembly, hashing, the blob store, the type judged from the bytes, serving with ranges — are **the build spec
`uploads-and-versions.md`** (worker B's); this slice spec names the screens, the handlers and the proof, and points there for the rules.

Schema (db/007, 013, 014, never modified): `blobs`, `documents`, `document_versions`, `document_text`, `upload_sessions`, `upload_parts`,
`dc_mime_kind()`, `dc_blob_claim()`, `dc_quota_allows()`, `dc_version_add()`, `dc_version_restore()`, `dc_document_lock()`, `dc_document_unlock()`
(db/007); `exports`, `worker_passes` (db/013); `dc_pass_locks_expire()`, `dc_pass_uploads_expire()`, `dc_pass_exports_expire()` (db/014; the
trash and version windows are slice 6's — **the data-owner rule**: this slice never calls `dc_pass_versions_expire()` or
`dc_pass_trash_purge()`); the walls (db/008); `mcp_documents`, `mcp_document_versions`, `mcp_document_text`, `mcp_upload_sessions`,
`mcp_exports` (db/015).

## Divergences from the design
1. **The one-request upload** (`document_upload`: a multipart POST ≤ 64 MB) exists beside the parts, for the API (slice 7), an agent through the
   actions server, a file request's door (slice 3) and a browser without JavaScript. It runs the same finish code (`upload_finish_session()`
   on a one-part session made inline).
2. **"Keep both"** names the copy `name (2).ext` (as `dc_document_restore()` does); the uploader chooses once per conflict in the progress row;
   with JavaScript off the form asks on a page.
3. **Thumbnails** are made by the worker, not on upload (an image's page shows the icon until the pass runs, within the minute).
4. **A zip export's content is decided at build time**: documents the maker may see *then*; a document shared out meanwhile is not in it.
5. **Locks' UI is here** (lock, unlock, break by a manager); their expiry is slice 6's pass.

## Who may do what
| Action | Right | Level |
|---|---|---|
| upload into a folder (new document) | `documents.upload` | `upload`+ on the folder |
| a new version, rename, move, copy (target `upload`+), describe, lock | `documents.edit` | `edit`+ on the document; a lock held by another refuses in words |
| restore a version | `versions.restore` | `edit`+ |
| unlock | — | the holder; `manage` breaks it (`document_lock_break`) |
| purge a version | `retention.manage` (admin) | `deletion` for agents |
| download, preview, open | — | `view`+ (an `upload` holder: their own only — the wall decides) |
| export a folder as zip | `export.own` | `view`+ on the folder |

## Screens (375 px first; the document page is read on a phone; the drop zone designed at 1280 and works at 375)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `upload` | `/f/{id}/upload` (also the folder page's drop zone, `?upload=1` opens it) | the drop zone ("Drop files or folders here, or choose"), the per-file progress list (name, size, a bar, the state: queued · uploading 3/40 · assembling · done (a link) · conflict (New version · Keep both · Skip) · refused (the sentence)); a Pause/Resume per file; "Done" returns to the folder; with JavaScript off a plain multiple-file form posting to `document_upload` one file at a time |
| `document` | `/d/{id}` | the name (editable inline for `edit`+), kind icon, size, the folder crumb, the owner, the description, tags (chips; slice 4 adds the editor), **the preview**: image inline (`/d/{id}/raw` with `inline_ok`), PDF in the browser's viewer (an `<iframe>` of the raw door with the sandbox CSP), text and Markdown rendered (escaped; Markdown through the kit's renderer), CSV as a table (first 200 rows), Office and everything else: the extracted text (first 20,000 characters) with "Download to open"; the lock state (held by X until Y; Lock · Unlock · Break); **the versions table** (`mcp_document_versions`: no, uploaded by, when, size, note, current chip; Restore for `edit`+; Purge for the admin); Download (`/d/{id}/download`); Related documents (slice 5's placeholder line); comments (notes, slice 8 adds the composer — here read-only); the trail |
| `document-edit` | `/d/{id}/edit` | name, description; Move (the folder picker, as slice 1's), Copy to (a folder picker; copies the current version's blob into a new document owned by me) |
| `exports` | `/exports` | my zips (`mcp_exports`): what, status, size, made, expires, Download; "Build again" |

## The flow of an upload (the build spec has the mechanics)
1. **Start** (`upload_start`, JSON): folder, filename, byte_size, mime_hint, sha256_claimed (optional) → `require_folder_level(upload)`,
   `dc_quota_allows()`, the per-file ceiling → an `upload_sessions` row (`part_bytes` from the settings) → `{session_id, part_bytes, parts_total}`.
   A one-part session is answered the same way.
2. **Part** (`upload_part`, raw body `PUT /upload/{session}/{n}` or multipart): written to `store_part_path()`, counted; a repeated part overwrites
   (resume); `{parts_done}`.
3. **Finish** (`upload_finish`): all parts present → assembled streaming into a temp file → sha256 → `dc_blob_claim()` (existed → the temp file
   discarded; new → `store_move_into_blobs()`) → `store_judge_type()` → `dc_version_add()` (NULL document → a new one; `conflict = 'version'` →
   the existing document's id; `'both'` → the `(2)` name) → the session `done` with `version_id`; a `23505` on the name → `{status: 'conflict',
   existing_document_id}` and the session stays open for the choice. Logged `document.upload` (a new document) or `document.version`.
4. **Serving**: `/d/{id}/raw` (inline when `inline_ok`, else attachment), `/d/{id}/download` (always attachment), `/d/{id}/v/{n}/download`;
   ranges honoured; `document.open` logged on the page, `document.download` on a download (with `byte_size`), `document.preview` on raw.
5. **The worker** (`app/features/files/worker_steps.php`, called by `bin/worker.php`): `extract` — the oldest `pending` versions (50 a pass):
   `dc_extract_text()` → `document_text` (status ready/no_text/unsupported/failed; the error kept) and `document_versions.extracted_status`;
   `thumbs` — images (GD 320 px) and PDFs' first page (`pdftoppm` when present) for documents without a thumbnail; `exports` — one queued zip
   at a time (ZipArchive streaming; ≤ 2 GB; documents the maker may see; `exports.storage_path`, `byte_size`, `item_count`; a notification
   `export_ready`); `uploads_expire` (sessions past `expires_at`: `dc_pass_uploads_expire()` then the parts unlinked); `exports_expire`
   (`dc_pass_exports_expire()` then unlinked); `locks_expire`. Each step idempotent and timed in `worker_passes.steps`.

## Files (exactly these)
- `html/f/upload.php` (`upload`) · `html/upload/{start,part,finish}.php` · `html/d/index.php` (`document`) · `html/d/{raw,download,edit}.php` ·
  `html/d/save.php` · `html/d/{move,copy,lock,unlock,lock-break}.php` · `html/d/versions/{restore,purge}.php` · `html/documents/upload.php` (the
  one-request `document_upload`) · `html/exports/index.php` (`exports`) · `html/exports/{make,download}.php`
- `app/features/files/{upload,documents,versions,exports}.php` (writes), `queries.php` gains the document reads, `present.php` the document presenter,
  `worker_steps.php`
- `app/doccloud/thumbs.php` (GD / pdftoppm), `app/doccloud/zip.php`
- `app/views/files/{document,document-edit,upload,exports,partials/preview,partials/versions,partials/lock,partials/progress-row}.php`
- `assets/js/uploader.js` (parts, resume, the progress list, the conflict choice; fetch with `X-CSRF-Token`)
- `app/pickers.php` gains `document` (documents I may see; params `folder`, `library`)
- `tests/phase3/slice2/{run.sh,lib.php,uploads.php,documents.php,versions.php,serving.php,worker.php,json.php,browser.mjs}` and the fixtures from `tests/extract/`

## Query functions (signatures fixed)
- `find_document(PDO, int $id): ?array` (`mcp_documents` + the current version) · `document_versions(PDO, int $documentId): array` · `document_text(PDO, int $documentId): ?array`
- `document_preview(PDO, array $doc): array{kind, mode: image|pdf|text|markdown|csv|extracted|none, text?, rows?}`
- `upload_session(PDO, int $id): ?array` · `my_upload_sessions(PDO): array` · `my_exports(PDO): array`
- writes: `upload_start(PDO, int $folderId, array $f, int $by): array` · `upload_part(PDO, int $sessionId, int $partNo, string $tmpPath): array` ·
  `upload_finish(PDO, int $sessionId, string $conflict = ''): array` · `upload_one(PDO, int $folderId, array $file, int $by, string $source = 'web'): array` ·
  `document_rename(PDO, int $id, string $name)` · `document_describe` · `document_move(PDO, int $id, int $folderId)` · `document_copy(PDO, int $id, int $folderId, int $by): int` ·
  `version_restore(PDO, int $versionId, int $by): int` · `version_purge(PDO, int $versionId)` · `document_lock(PDO, int $id, int $by)` · `document_unlock(PDO, int $id, int $by, bool $force)` ·
  `export_make(PDO, string $kind, ?int $folderId, array $documentIds, int $by): int`
- the worker: `worker_extract(PDO, int $limit = 50): array` · `worker_thumbs(PDO, int $limit = 50)` · `worker_exports(PDO)` · `worker_uploads_expire(PDO)` · `worker_exports_expire(PDO)` · `worker_locks_expire(PDO)`

## MCP tools this slice makes real
Also: `my_uploads`, `downloads` (the surface's rows this slice's screens read).
`get_document`, `find_documents` (the resolver; name, folder, kind, owner, mine), `document_versions`, `document_text`, `document_page`,
`document_excerpt`, `locked_documents`, `extraction_failures`, `duplicates`.

## Manifest rows claimed
Screens (6): `document`, `document-edit`, `document-move`, `document-versions`, `upload`, `exports`
Actions (11): `document_upload`, `document_rename`, `document_describe`, `document_move`, `document_copy`, `version_restore`, `version_purge`, `document_lock`, `document_unlock`, `export_folder`, `export_documents`

Routes, not actions: `/upload/start`, `/upload/part/{session}/{n}`, `/upload/finish/{session}` (the action is `document_upload`), `/d/{id}`, `/d/{id}/v/{n}`, `/d/{id}/thumb`, `/exports/{id}` (the download). `document_lock_break` is slice 6's row (the break is proven there).

## Activity log events (every row: `document_id`, `folder_id`, `library_id`)
`document.upload|version|rename|describe|move|copy|open|download|preview|lock|unlock|lock_break`, `version.restore|purge`, `upload.start|finish|conflict|refused|expire`,
`export.make|ready|download|expire`, `text.extracted|no_text|unsupported|failed`, `thumb.made`.

## Notifications this slice queues
`export_ready` (to the maker), `extraction_failed` (to the owner, once per version), `version_added` (to the owner when another adds a version).

## Status vocabulary
Version chip: current `primary`, purged `dark`. Extraction: ready `success`, pending `secondary`, no_text `warning`, unsupported `light`, failed `danger`.
Upload row: queued `secondary`, uploading `info`, assembling `info`, done `success`, conflict `warning`, refused `danger`. Ids: `drop-zone`,
`upload-row-{n}`, `document-preview`, `versions-table`, `lock-state`, `document-download-btn`.

## Out of scope for this slice
Sharing and links (3), tags' editor and search (4), the graph (5), the trash and the version/trash windows (6), the API routes (7).

## Proof (`tests/phase3/slice2/run.sh`: scratch database `dc_dev2`; the app on 8701, the fake kernel on 8702, the fake MaluDB on 8706; a scratch
storage directory under `$TMP` through `DC_STORAGE_DIR`; `bin/worker.php` with `DC_WORKER_NOW`; Playwright; the registry `--check`)
1. A 20 MB file in 8 MB parts: start, three parts, finish → one blob under `blobs/aa/bb/<sha>`, one document, one version, the folder lists it;
   the sha256 matches the file's; the library's used_bytes grew by 20 MB.
2. The same bytes again under another name in another library → the blob's ref_count 2, no second file on disk; the two documents' sha equal.
3. A part repeated (resume) overwrites and counts once; a missing part at finish → 422 "part 2 missing"; a part beyond `parts_total` refused.
4. A same-named upload → `conflict`; `version` makes version 2 (current, the old restorable); `both` makes "name (2).ext"; skip leaves the session expired by the worker.
5. The per-file ceiling and the quota refused at start, in words; a zero-byte file refused; an executable renamed `.pdf` stored as `other`/`octet-stream`, never inline.
6. The one-request `document_upload` (multipart) makes a document; an agent's run token does it too; a guest 403.
7. The document page: a PNG previews inline; a PDF in the viewer; a Markdown rendered and escaped (a `<script>` in it is text); a CSV as a table; a DOCX shows the extracted text once the worker ran.
8. Serving: `/d/{id}/raw` 200 inline for a PNG, attachment for the DOCX; `Range: bytes=0-99` answers 206; `nosniff` and the CSP present; 404 for a document the caller may not see; 401 anonymous; `storage/` unreachable by path.
9. Versions: restore makes version 3 from version 1's blob; purge by the admin releases the bytes (and is refused for the current); an agent's purge pauses.
10. Locks: lock, another's version refused in words, unlock by the holder, break by a manager (logged `document.lock_break`, the holder told `lock_broken`).
11. Rename, describe, move (the picker; into a folder where the caller holds only view → 403), copy (a new document, mine, the same blob, ref_count up).
12. The worker: `extract` sets ready/no_text/unsupported for the fixtures (PDF with text, PDF without, PNG, DOCX, XLSX, TXT, CSV, a binary); `thumbs` makes a 320 px JPEG for the PNG and the PDF; a zip export of the folder holds the right files and skips a document the maker may not see; `uploads_expire` removes an abandoned session's parts; `exports_expire` unlinks after the date.
13. JSON mode for every screen and handler; `location` of a create ends in the document id; the logs carry `document_id` and `byte_size` on downloads, never a filename of a refused upload beyond 120 characters.
14. Browser at 375 and 1280: the drop zone with three files and a folder dropped, the progress rows, a conflict chosen, the document page's preview, the versions table; no console errors.
15. Registry `--check`: 4 screens built, 16 actions built, 1 approval in step.

## Open questions
None open. Decisions taken while building (2026-10-09), the design's way:
1. **Routes, not actions, carry the parts**: `POST /upload/start` (JSON), `POST /upload/part?session=&n=` (the raw body or a multipart `part`), `POST /upload/finish`; the
   action `document_upload` is the one-request form (multipart `file`, or `file_base64` + `filename` for an agent that cannot post multipart). The URL form is the
   vhost's (`/upload/(start|part|finish)`), not a path-parameter form.
2. **The same-name rule is judged at the START** (409 `name_taken` with `existing_document_id` before any byte moves) **and again at the FINISH** (a race); the
   browser restarts with `document_id` or `mode: keep_both`, or answers the finish with `conflict: version|keep_both`. A session remembers the answer in db/018's
   `upload_sessions.mode` and `document_name` (and `source`, `note`), appended columns; `dc_free_name(folder, name)` is the "(2)" rule PHP and the trigger share.
3. **A start that names a sha256 already stored needs no parts**: `parts_total 0, deduplicated true`, the finish at once — the browser hashes only when it can cheaply.
4. **Everything is stored; nothing is served inline but the inline-safe kinds** (images, PDF, text kinds, audio, video) — an executable renamed `.pdf` is stored as
   `other` and served as `application/octet-stream` attachment; `?download=1` forces attachment; a link holder's download honours `allow_download` (slice 3 sets
   `$_SESSION['link'][id]`); the anonymous answer is `401` WITH a `Location` to the launcher (`header(..., true, 401)` — PHP would make a bare Location a 302).
5. **The gate URLs**: `/d/{id}` and `/d/{id}/v/{n}` serve bytes, `/d/{id}/thumb` the thumbnail (a generated SVG placeholder before the worker), `/documents/{id}` is the
   page (the consistency decision) — the slice spec's `/d/{id}/raw` and `/d/{id}/view` forms are not used.
6. **Extraction never runs the extractor on bytes we do not read**: images are `no_text` and audio, video, archives and unknown kinds `unsupported` without a call (a
   20 MB binary cost 14 s as "text"); the extractor reads PDF, Office, text kinds and HTML/XML only. A version's file missing is `failed: file missing`.
7. **Thumbnails are the worker's**, tried once per version (a `.none` marker beside the path stops retries); made after extraction or a minute after the upload.
8. **CSV previews read the raw bytes** (the first 256 KB; the extractor flattens a CSV into "name: value | …" lines for the index); Markdown through
   `app/doccloud/markdown.php` (escape-first, a `<script>` stays text); Office and other readable kinds show the extracted text's first 20,000 characters.
9. **Comments are read-only here** (notes shown; slice 8's composer); tags, "who can see this" and related documents are placeholder lines for slices 4, 3 and 5.
10. **`document_lock_break`** (slice 6's manifest row) is BUILT here because the lock UI is here; slice 6 proves the expiry pass (built here too: `locks_expire`).
11. `document_rename` refuses a slash in the name (never `basename()`s it away); `document_copy` names the copy with the free-name rule and copies the tags.
12. Agents: `document_upload` under a run token stores with `source 'agent'`; the paused actions (`version_purge`) are held by the kernel's hook — the proof checks the
    registry's category and that an agent without the right gets 403.
