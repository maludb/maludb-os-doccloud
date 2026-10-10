# Build spec: uploads, versions, blobs, serving and the windows (slice 2 — THE FIRST EXEMPLAR; the worker's file passes)

A file arrives in **parts**, is assembled and hashed once, becomes a **blob** (stored once per sha256) and a **version** of a
**document**; it is served through one gate; its text is extracted by the worker; its versions, its place in the trash, its lock
and its blob's life are the windows the settings name (design §6.1, §6.2; D9, D10). The database is the referee: `dc_blob_claim`,
`dc_version_add`, `dc_version_restore`, `dc_document_trash/restore/purge`, `dc_document_lock/unlock`, the `dc_pass_*` functions
(db/007, db/014). PHP decides the level first (`visibility.md` §1), moves bytes, and logs. Nothing here calls a model.

Conventions of the kit: handlers `require_post()` + `verify_csrf()` (an action token stands in), `dc_guard()` around the step,
`dc_done()` / `dc_refuse_fields()` to answer, `log_activity()` with `document_id` / `folder_id` / `library_id` in `$opts`, JSON mode
through `wants_json()`. Paths: `DC_STORAGE_DIR` (`env('DC_STORAGE_DIR')`, default `APP_ROOT/storage`) holds `blobs/`, `uploads/`,
`thumbs/`, `exports/` (ROOT_STEPS step 2b makes them, www-data 750). `storage_path` values are relative to it.

## 1. Files (exactly these)
| File | Concern |
|---|---|
| `app/doccloud/store.php` | `dc_storage_dir()`, `dc_storage_path(string $rel)`, `dc_sniff_mime()`, `dc_inline_safe()`, `dc_refused_kind()`, `dc_blob_store()`, `dc_blob_unlink()`, `dc_make_thumbnail()`, `dc_safe_filename()` |
| `app/features/uploads/queries.php` | `dc_upload_start()`, `dc_upload_part()`, `dc_upload_finish()`, `dc_upload_session()`, `dc_upload_discard()` |
| `html/upload/start.php`, `html/upload/part.php`, `html/upload/finish.php` | the three routes (vhost: `/upload/(start\|part\|finish)` → these) |
| `html/upload/index.php` | the upload screen (the drop zone and per-file list, `assets/js/upload.js`) |
| `html/d/index.php` | the gate: `/d/{id}` and `/d/{id}/v/{n}` |
| `html/d/thumb.php` | `/d/{id}/thumb` |
| `app/features/documents/queries.php`, `html/d/*.php` | the document screen and its actions (rename, describe, move, copy, versions, restore, trash, restore, purge, lock, unlock, download) |
| `html/api/v1/upload.php`, `html/api/v1/documents/*.php` | the single-request API upload; `GET /api/v1/documents/{id}`, `/download`, `/text` |
| `bin/worker.php` | `worker_pass_upload_sessions_expire`, `worker_pass_extract_text`, `worker_pass_thumbnails`, `worker_pass_versions_expire`, `worker_pass_trash_purge`, `worker_pass_blobs_free`, `worker_pass_locks_expire`, `worker_pass_exports` |
| `tests/slice2/run.sh` | the proof (§10) |

## 2. The upload in parts
Settings: `dc_settings.upload_part_bytes` (8 MB), `max_document_bytes` (NULL = no ceiling since db/024 — `large-uploads.md`, which also
writes each part at its offset in one file and hashes as the parts arrive; `DC_MAX_DOCUMENT_BYTES` may only lower it),
`upload_session_hours` (24). The browser (`upload.js`) slices a `File` into parts of `upload_part_bytes`, sends them in order (at
most 3 in flight), retries a failed part thrice, and finishes; a file no larger than one part is `start` + one `part` + `finish`
(three requests; the API door below is the one-request form).

1. **`POST /upload/start`** — body `folder_id`, `filename`, `byte_size`, `mime_hint?`, `sha256?`, `document_id?` (a new version of
   that document), `mode?` (`version` | `keep_both`, the answer to a conflict). Gate: `require_folder_level($folder_id, 'upload')`
   for a new document; `require_document_level($document_id, 'edit')` for a version (and the lock rule: `documents.locked_by` must be
   NULL, the caller or expired → else 423 `locked` with the holder's name). Refusals: `byte_size > max_document_bytes` → 413
   `too_large` ("A file is at most N GB."); `NOT dc_quota_allows(library, byte_size)` → 422 `quota` ("The library is over its
   quota."); the name: `dc_safe_filename()` (basename, `\\`→`/`, control characters out, ≤ 255, never `.`/`..`); **the same-name rule**:
   a live document of that name (case-insensitive) in the folder and no `document_id`/`mode` → **409** `{code: "name_taken",
   existing_document_id, existing_version_count}` — the browser asks "Add as a new version" (restart with `document_id`) or "Keep both"
   (restart with `mode: keep_both` → the name becomes `name (2)`, `(3)` … by the same rule `dc_document_restore` uses). `sha256` given and
   `dc_blob_claim` finds it with `existed = true` → **no parts are needed**: answer `{session_id, parts_total: 0, deduplicated: true}` and
   the browser calls `finish` at once. Else `INSERT upload_sessions (member_id, folder_id, document_id, filename, mime_hint, byte_size,
   part_bytes, parts_total = ceil(byte_size / part_bytes) (≥ 1), sha256_claimed, status 'open', expires_at = now() + upload_session_hours)`;
   `mkdir DC_STORAGE_DIR/uploads/<session_id>` (0750). Answer `{session_id, part_bytes, parts_total, expires_at}`. Log
   `document.upload_start` (folder_id, byte_size, parts_total; never the name's content beyond the name).
2. **`POST /upload/part`** — `session_id`, `part_no`, the bytes as the raw body (`php://input`; `Content-Type: application/octet-stream`)
   or a multipart `part` field (the browser sends raw). Gate: the session is the caller's (`member_id = current_member_id()`), `open`,
   not expired; `1 ≤ part_no ≤ parts_total`; the part's size = `part_bytes` except the last (`byte_size − (parts_total − 1) × part_bytes`)
   → else 422 `bad_part`. Written to `uploads/<session>/<part_no>` with `file_put_contents(..., LOCK_EX)` streamed in 1 MB reads (never the
   whole part in memory twice); a part sent again replaces its file (resume); `INSERT upload_parts … ON CONFLICT (session_id, part_no) DO
   UPDATE SET byte_size, received_at`; `parts_done = count(upload_parts)`. Answer `{part_no, parts_done, parts_total}`. Not logged (noise).
3. **`POST /upload/finish`** — `session_id`. Gate as above; `parts_done = parts_total` else 422 `missing_parts` `{missing: [n…]}`. Then,
   under `status = 'assembling'`: open `uploads/<session>/assembled` for writing, append the parts in order through `hash_init('sha256')`
   while copying (`stream_copy_to_stream` in 1 MB chunks, the hash updated per chunk), unlink each part as it is consumed; the total must
   equal `byte_size` → else 422 `size_mismatch` (and `status = 'failed'`); `sha256_claimed` given and different → 422 `hash_mismatch`.
   `dc_sniff_mime($assembled, $filename)`: `finfo` on the bytes; a text kind refined by extension among text kinds only (`.md` →
   `text/markdown`, `.csv` → `text/csv`, `.json` → `application/json`); `dc_refused_kind($mime, $filename)` (§3) marks the document but
   never refuses the store. Then one transaction: `dc_blob_claim(sha256, byte_size)` → `{blob_id, storage_path, existed}`; when `existed`
   is false, `rename` the assembled file to `dc_storage_path(storage_path)` (`mkdir -p` its two-level directory, 0750; the file 0640);
   when true, unlink the assembled file (the same bytes are already there — de-duplicated); `dc_version_add(document_id | NULL, folder_id,
   name, blob_id, filename, mime, byte_size, sha256, by, source, note)` → `{document_id, version_id, version_no}` — the function re-checks
   the ceiling, the quota and the lock (its refusals come back as `P0001` → 422 through `dc_guard`); `status = 'done'`, `version_id`;
   `rmdir uploads/<session>`. Answer `{document_id, version_id, version_no, deduplicated, location: "/d/<id>"}`. Log
   `document.upload` (a new document: `after` {name, byte_size, mime_kind, version_no 1, deduplicated}) or `document.version` (`after`
   {version_no, byte_size, sha256_changed}), `document_id`, `folder_id`, `library_id`. **A failure after the blob was renamed and before the
   version row** leaves a blob with `ref_count 0` — `dc_pass_blobs_free` collects it an hour later; nothing is lost, nothing leaks.
4. **Sources**: `document_versions.source` = `web` (a session), `api` (a key), `agent` (an action token), `request` (a file request,
   `visibility.md` §6), `restore`, `copy`.
5. **Abandoned**: `worker_pass_upload_sessions_expire` → `SELECT dc_pass_uploads_expire()` → for each id `rm -rf uploads/<id>`; answer
   `{expired: n}`. A `failed` session's directory is removed by the same pass after an hour (`status = 'failed' AND updated_at < now() − 1 h`).
6. **The API's one request**: `POST /api/v1/upload` (multipart `file` + `folder_id`, `document_id?`, `mode?`; ≤ 64 MB, Apache's
   ceiling; `api_authenticate()` → the key's person; the same gates) = start + the whole file as one part + finish in one handler;
   answers the finish's JSON; `source 'api'`. Over 64 MB → 413 with "use the parts API" (`/upload/start` with the key as bearer).

## 3. What is stored but never served inline (`dc_refused_kind`, `dc_inline_safe`)
Everything is stored — a file store keeps what it is given. **Served inline** (`Content-Disposition: inline`) only when the sniffed type
is `image/png|jpeg|gif|webp|avif`, `application/pdf`, `text/plain`, `text/markdown`, `text/csv`, `application/json` AND the extension
does not name a program or markup (`exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll|html?|htm|svg|php|phtml|xml|xhtml`). Everything else —
Office, archives, audio, video, `text/html`, `image/svg+xml`, unknown — is `attachment`. The rule is the same as Spaces' store (its
allow-list) with the refusal turned into a disposition: the bytes are never executed or rendered by the browser from our origin.

## 4. Serving: the gate (`html/d/index.php`)
1. `GET /d/{id}` (the current version) and `/d/{id}/v/{n}` (`document_versions.version_no = n`, not purged — a purged version is 404).
   Who may: a session or action-token caller with `dc_can_see_document(id)`; a link holder (`$_SESSION['link'][<link_id>]`, set by the
   `/s/` door) with `dc_link_reaches_document(link_id, id)` AND `share_links.allow_download` (else inline preview only: `?inline=1`
   honoured, a download refused 403 with the link's words); an API key as its person; a K7 signed URL (`visibility.md` §5). None →
   **404** (the same nothing). `?download=1` forces `attachment`.
2. Headers: `Content-Type` = the version's `mime_type` (for a refused kind: `application/octet-stream`), `Content-Disposition:
   inline|attachment; filename="<ascii>"; filename*=UTF-8''<encoded>`, `X-Content-Type-Options: nosniff`, `Cache-Control: private,
   max-age=0, must-revalidate`, `ETag: "<sha256>"` (a matching `If-None-Match` → 304), `Accept-Ranges: bytes`, `Content-Length`.
   **Range**: one range `bytes=a-b` honoured with 206 and `Content-Range`; multiple or unsatisfiable → 416; `If-Range` by ETag.
   Streamed with `fopen` + `fseek` + 64 KB `fread`s (`ob_end_clean()` first; `set_time_limit(0)`); never `readfile` into memory.
3. Logged once per request: `document.download` (attachment) or `document.preview` (inline), `after` {version_no, bytes, ranged,
   door: web|link|api|agent|share}; a link's open also through `dc_link_open(link_id, 'download'|'view', address_hash, document_id, bytes)`.
   `documents.last_opened_at = now()` for a session caller (not for a link or the API).
4. `GET /d/{id}/thumb` → `thumbs/<document>/<version_id>.jpg` when it exists (same gate, inline, `image/jpeg`, cacheable `max-age=3600`,
   `ETag`), else the kind's SVG icon from `assets/icons/<mime_kind>.svg` (302).

## 5. Thumbnails (`dc_make_thumbnail`, `worker_pass_thumbnails`)
For `mime_kind = 'image'` (png, jpeg, gif, webp; not heic/avif) GD scales the longer side to 320 px (`imagecreatefromstring` under a
`memory_limit` guard: refuse an image over 50 MP); for `pdf` when `DC_PDFTOPPM` (or `pdftoppm` on PATH) exists: `pdftoppm -jpeg -r 40
-f 1 -l 1 -scale-to 320 <blob> <out>` under a 20 s timeout. Written to `thumbs/<document_id>/<version_id>.jpg` (0640). Made by the
worker, not at upload (the finish answers fast): the pass takes versions with `extracted_status <> 'pending'` OR older than a minute,
of those kinds, without a file, newest first, `$limit` per pass. A purge removes `thumbs/<document_id>/`.

## 6. Text extraction (`worker_pass_extract_text`)
1. The pass: `SELECT v.id, v.document_id, b.storage_path, v.mime_type, v.filename FROM document_versions v JOIN blobs b … WHERE
   v.extracted_status = 'pending' AND v.purged_at IS NULL ORDER BY v.uploaded_at LIMIT $limit FOR UPDATE SKIP LOCKED`, each in its own
   transaction, each under a 50 s pass budget (stop taking new ones when exceeded).
2. `dc_extract_text(dc_storage_path(b.storage_path), v.mime_type, v.filename)` → `{status: ready|no_text|unsupported|failed, text, pages,
   extractor, char_count, error, note}` (the extractor's own cap `DC_MAX_TEXT_BYTES`, default 5 MB, = `dc_settings.max_text_bytes`; the
   worker passes the setting through `putenv`). On `ready` and `no_text`: `INSERT INTO document_text (version_id, document_id, text, pages,
   extractor, char_count, truncated = note LIKE 'truncated:%', error) ON CONFLICT (version_id) DO UPDATE …`; `document_versions.extracted_status =
   status`. On `unsupported`/`failed`: no text row (or a row with `error` and `char_count 0` so the screen can say why) and the status.
   The triggers (db/010, db/012) then index the document and queue its graph job. Log `document.text_extracted` (status, extractor,
   char_count, truncated) — **never the text**.
3. The document screen shows the state: "Text: 12,400 characters (pdftotext)", "No text layer (an image-only PDF)", "Not a kind we read
   (video)", "Extraction failed: <error code>" with a "try again" action (`document_reextract`: `extracted_status = 'pending'`, managers).

## 7. Versions, the trash, locks, ownership (the document's actions)
| Action | Route | Gate | Does | Log |
|---|---|---|---|---|
| `document_rename` | `POST /d/{id}/rename` | edit | `UPDATE documents SET name` (the unique index → 422 "That name is taken here") | `document.rename` before/after name |
| `document_describe` | `POST /d/{id}/describe` | edit | `description` ≤ 4,000 | `document.describe` |
| `document_move` | `POST /d/{id}/move` `{folder_id}` (the record picker over folders the caller may add to) | manage on the document, `upload`+ on the target | `UPDATE documents SET folder_id` (the triggers keep library and bytes; a lock by another refuses) | `document.move` from/to |
| `document_copy` | `POST /d/{id}/copy` `{folder_id, name?}` | view on the document, `upload`+ on the target | `dc_version_add(NULL, target, name, current blob, …, source 'copy')` — the same blob (`ref_count + 1`), owner = the copier, tags copied | `document.copy` |
| `version_restore` | `POST /d/{id}/versions/{n}/restore` | edit (+ not locked by another) | `dc_version_restore(version_id, by)` | `version.restore` {from_version_no, new_version_no} |
| `version_purge` | `POST /d/{id}/versions/{n}/purge` | admin (`retention.manage`); pauses for an agent (deletion) | `UPDATE document_versions SET purged_at = now()` (never the current: the trigger refuses) | `version.purge` |
| `document_trash` | `POST /d/{id}/trash` | manage (or the owner) | `dc_document_trash(id, by)`; the lock is dropped | `document.trash` |
| `document_restore` | `POST /d/{id}/restore` | the owner, the trasher, or manage | `dc_document_restore(id)` (a renamed "(2)" is told) | `document.restore` {name, folder_id} |
| `document_purge` | `POST /d/{id}/purge` | admin (`trash.purge`); pauses for an agent | `dc_document_purge(id)`; then `rm -rf thumbs/<id>` | `document.purge` {name, byte_size} — the row is gone, the trail keeps the name |
| `document_lock` | `POST /d/{id}/lock` | edit | `dc_document_lock(id, by)` (false → 409 "Locked by <name> until <when>") | `document.lock` |
| `document_unlock` | `POST /d/{id}/unlock` | the holder; a manager with `force=1` | `dc_document_unlock(id, by, force)`; a break notifies the holder (`dc_notify lock_broken`) | `document.unlock` / `document.lock_break` |
| `ownership_transfer` | `POST /curate/ownership` `{from, to, library_id?}` | `ownership.transfer`; pauses (deletion category) | `dc_ownership_transfer(from, to, library)`; the new owner is notified (`ownership_received`) | `document.transfer` {count} |
| `document_reextract` | `POST /d/{id}/reextract` | manage | §6.3 | `document.reextract` |
| `folder_export` | `POST /f/{id}/export` | view | `INSERT exports (kind 'folder', format 'zip', folder_id, created_by, params {sort})` → `{export_id}`; the page polls `/exports/{id}` every 5 s | `folder.export` {export_id}; `export_ready` notification when done |

The versions list (`/d/{id}/versions`): every version with its number, size, uploader, date, source, note, `extracted_status`,
"current", "purged (the window)", a download link `/d/{id}/v/{n}` and "restore" where allowed; the window sentence from the settings.

## 8. The worker's windows and files
| Pass | SQL | Then on disk |
|---|---|---|
| `versions_expire` | `dc_pass_versions_expire($limit)` | nothing (blobs are freed by the next pass) |
| `trash_purge` | `dc_pass_trash_purge($limit)` — each purge queues the engine's forget (db/012) | `rm -rf thumbs/<document_id>` for each purged id (the function returns a count; the pass reads the ids first: `SELECT id FROM documents WHERE trashed_at < now() − trash_days` before calling it) |
| `blobs_free` | `SELECT dc_pass_blobs_free($limit)` → paths | `unlink(dc_storage_path(path))` each (a missing file is not an error; logged `blob.free` with the count only); the two-level directory left in place |
| `locks_expire` | `dc_pass_locks_expire()` | — |
| `exports` | queued exports, one at a time: the folder's subtree as the MAKER sees it (`app.member_id` = `created_by` for the statement — `dc_folder_listing` recursively, or `documents WHERE folder_id IN subtree AND dc_can_see_document(id)`), written with `ZipArchive` to `exports/<id>.zip` streaming each blob (`addFile`), paths = the folder path below the exported folder, names de-duplicated; `status done`, `byte_size`, `item_count`; failure → `failed` with `log`; **a link holder's zip** (`exports.link_id`, `visibility.md` §6) uses `dc_link_reaches_document` instead | `dc_pass_exports_expire()` → unlink each path |
| `upload_sessions_expire` | §2.5 | `rm -rf uploads/<id>` |

Downloads of an export: `GET /exports/{id}` (the maker, or the admin; a link holder's through `/s/<token>/zip/{id}`) streams the zip as
`attachment` and sets `downloaded_at`.

## 9. The screens (slice 2's part; the shell's look)
- **Upload** (`/upload?folder=`): the drop zone (files and dropped folders: `webkitGetAsEntry` walks the tree; each subfolder becomes a
  `folder_create` first, then its files), the per-file list (name, size, progress, the conflict prompt inline, the refusal sentence),
  "Done" landing on the folder. At 375 px the drop zone is a button ("Choose files").
- **Document** (`/d/{id}` as a page is `/d/{id}/view`; the bare `/d/{id}` serves the bytes): preview by kind — an image (`/d/{id}`),
  a PDF in an `<iframe>` of `/d/{id}?inline=1`, text/Markdown/CSV rendered from `document_text` (CSV as a table of the first 500 rows),
  Office and the rest as the extracted text with "Download to open"; the name, description, owner, folder crumbs, size, kind, tags, the
  lock, the version list, "who can see this" (visibility.md), links, comments (notes), related documents (document-graph.md), the trail
  (`record_history`), the actions of §7 as buttons (no modals; confirmations by `hx-confirm`).

## 10. The proof (`tests/slice2/run.sh`, on a scratch database with the kit's fake kernel, php -S on 8701)
1. A 20 MB file as three parts: start → 3 parts → finish; the blob's file exists at `blobs/<aa>/<bb>/<sha>`, the version is 1, the
   document's `byte_size` 20 MB; the library's `used_bytes` grew.
2. Part 2 sent twice (a resume) — one `upload_parts` row, the finish succeeds; a finish with part 3 missing → 422 `missing_parts`.
3. The same 20 MB bytes into another folder → `deduplicated: true`, one blob with `ref_count 2`, no second file.
4. The same name again: 409 `name_taken`; restarted with `document_id` → version 2; restarted with `mode keep_both` → "name (2)".
5. An executable named `invoice.pdf`: stored, `mime_kind other`, served as `attachment` with `application/octet-stream`; an HTML file:
   stored, never inline.
6. `GET /d/{id}` as a viewer: 200, `ETag`, `nosniff`; `Range: bytes=0-99` → 206 with 100 bytes; `If-None-Match` → 304; as a member who
   may not see it → 404; anonymous → 401 to the launcher.
7. A PNG's thumbnail by the worker; a PDF's when `pdftoppm` is present (else skipped with a line).
8. The worker extracts a PDF (pages anchored) and a DOCX (headings in the text) → `document_text`, `extracted_status ready`, the
   search index row carries the text; an image → `no_text`; a `.bin` → `unsupported`.
9. Versions: 12 versions, the setting `versions_keep_count 10`, the oldest two aged 40 days → the pass purges two, the current never;
   `dc_pass_blobs_free` returns nothing while a copy still holds the blob; after the copy is purged and the trash window passes, the
   blob's file is unlinked exactly once.
10. A lock by A: B's version upload → 423; B's `unlock` → 409; a manager's `force` → the holder is notified; an expired lock released by
    the pass.
11. Trash: a document trashed, gone from the listing for others, restored into a folder where the name is taken → "(2)"; a trashed folder
    takes its documents; purge after the window frees the blob and the thumbnails.
12. A folder zip: the worker builds it with only what the maker may see (a document the maker cannot see is absent), the download sets
    `downloaded_at`, the expiry pass unlinks it.
13. Every action logged once with `document_id` and no text; an agent's `document_purge` pauses (the fake kernel's hook).
