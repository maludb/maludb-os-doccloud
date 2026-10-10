# Build spec — files of any size (large uploads)

2026-10-10 · asked by the owner after the grid: "I just tried to upload a file over 2 GB and it was refused, which is not acceptable for
this application. Look at https://github.com/KineticSeas/php-chunk-upload, which uploads files in small chunks, and change the code to work
properly." Built on `main` through the worktree `big-uploads`, additive to slice 2 (uploads, versions, blobs), slice 6 (the worker) and slice 9
(the settings). Amends D9 ("2 GB per file (a setting)").

## What was wrong
DocCloud already sent a file in 8 MB parts (slice 2). Three things stood in the way of a large file:
1. **The ceiling.** `dc_settings.max_document_bytes` defaulted to 2 GB (D9); the browser refused a larger file before sending a byte
   ("Over the ceiling (2.0 GB)"), the start answered 413, and `dc_version_add` refused it again.
2. **The finish.** Every part was its own file (`uploads/<session>/<n>`); the finish copied them all into `assembled`, hashing as it went,
   then moved that into the blobs — a second copy of the file on disk, and a finish request whose length grew with the file (on this VM PHP
   hashes about 60 MB/s: a 10 GB file's finish would have run for minutes and met the proxy's timeout).
3. **Fragility.** A session expired 24 hours after it began however much was still arriving; a part retried three times within 1.5 s, then
   the whole file was lost; a closed tab began again from zero.

## What the reference does, and what we took
`php-chunk-upload` slices the file in the browser and the server writes each chunk **at its offset in one file** (`fseek` + `fwrite`), so
there is no assembly and no ceiling but the disk. We took exactly that, inside slice 2's sessions: the parts are still numbered, checked
for size and recorded, but each is written at `(n - 1) × part_bytes` in **`uploads/<session>/data`**. Not taken: its absence of
authentication, sessions and checks, its "rename the old file" on a repeated name, and its serial one-chunk-at-a-time sending.

## Decisions (taken in this spec; the owner may overrule)
| # | Question | Answer |
| --- | --- | --- |
| L1 | The per-file ceiling | **None by default.** `max_document_bytes` becomes NULL-able (NULL = none); an admin may still set one on Admin → Settings (empty = no limit); `DC_MAX_DOCUMENT_BYTES` may still only lower it. The 2 GB seeded by db/005 is cleared by db/024. |
| L2 | What limits instead | **The store's room**: the filesystem's free space less a reserve always kept free (`DC_STORE_RESERVE_BYTES`, 1 GB) and less what open uploads still owe it — so two large uploads cannot both count the same free space. Over it: **507 `no_space`** in words, at the start and at a part. The library's quota still applies. |
| L3 | The sha256 | **Hashed as the parts arrive, in order**: PHP's `HashContext` is serialized after each part into `upload_sessions.hash_state` (base64) with `hashed_parts`; a part out of order waits until the gap is filled. The finish completes the hash from the state — it reads at most the last few parts. |
| L4 | Integrity | Parts are written, recorded and hashed **under the session's row lock**. The same bytes sent again change nothing; other bytes for a part not yet hashed replace it; other bytes for a part already hashed are refused (**409 `part_differs`**) — the stored sha256 is always the bytes stored, so de-duplication can never hand one person another's content. A part half-written is unrecorded and sent again. |
| L5 | Resume | **The same person choosing the same file for the same place carries on.** The browser sends `resume_key` = sha256 of the name, size, modified time and the first and last megabyte; the start answers the open session with `resumed: true` and `parts_received`. Never another person's session, never another size or part size. |
| L6 | Expiry | A part **moves the session's expiry on** (`upload_session_hours` from now): an upload that keeps arriving never expires; one abandoned is dropped as before. |
| L7 | The browser | Retries a part on the network or a 5xx six times over about a minute; then the file is **paused** with **Resume** (and resumes by itself when the browser comes back online); a full store pauses too. Leaving the page while a file goes up asks first. The upload screen's "Still arriving" says how to carry on. |
| L8 | Sessions begun before this change | Refused with **409 `restart`** ("This upload began before DocCloud was updated. Start it again.") — `writes_at_offset` marks the new ones; the two layouts are never mixed. |
| L9 | The folder export | Its fixed 2 GB cap goes (it silently left a large file out): a zip holds up to the store's room and 10,000 entries; ZipArchive writes Zip64 past 4 GB; a file of 64 MB or more is **stored** uncompressed. |
| L10 | Requests through a link | Unchanged: one request of at most 64 MB (or an admin's lower ceiling) — a file request is a single POST from someone with no account. |

## Schema — `db/024_large_uploads.sql` (additive)
- `dc_settings.max_document_bytes` drops NOT NULL, defaults to NULL, CHECK `IS NULL OR >= 1 MB` (no top); the seeded 2 GB is cleared.
- `upload_sessions` gains `writes_at_offset` (boolean, default false; new sessions true), `hashed_parts` (integer, CHECK 0..parts_total),
  `hash_state` (text), `resume_key` (text, CHECK 64 hex) and the partial index `upload_sessions_resume_idx` (member, folder, key) WHERE open.
- `dc_version_add`'s ceiling re-check is false against NULL: unchanged.

## Code
- `app/doccloud/store.php`: `store_upload_path()` (uploads/<session>/data) replaces `store_part_path()`/`store_assembled_path()`;
  `dc_store_room()`; `dc_file_ceiling()`; `store_move_into_blobs(..., $keepSource)` — the finish hard-links the upload's file into the blobs
  and removes it only after the commit (a refusal at the commit can be answered again); a copy fallback goes through `.part` + rename.
- `app/features/files/upload.php`: `upload_start` (the ceiling when set, the room less what is owed, the resume), `upload_part` (the
  write at the offset under the row lock, `part_differs`, the expiry moved on, `upload_hash_advance`), `upload_finish` (the missing parts,
  the hash completed from the state, no copy). `upload_one` (the API's one request, a file request, an agent's base64) uses the same path.
- `html/upload/start.php` passes `resume_key` and logs `resumed` and `parts_received` on `upload.start`.
- `html/assets/js/dc-upload.js`: the resume key, the parts already there skipped, the retries, paused + Resume, online, beforeunload.
- The zone, the upload screen, Admin → Settings (`ceiling`, a `bytes` field that may be emptied), the file request's 64 MB, the export.
- The worker's `upload_sessions_expire` also removes a part's body left in `uploads/tmp` for a day (`temp_removed`).

## Manifest rows claimed
Screens (0):
Actions (0):

No screen or action is new: the uploader is slice 2's `upload` screen and `document_upload` routes; the ceiling is slice 9's `settings_save`
(its `max_document_bytes` may now be emptied); the export is slice 2's `export_folder`.

## MCP tools this slice names
None changes. The settings a tool reads now show max_document_bytes null when there is no ceiling.

## Proof — `tests/phase3/bigfiles/run.sh` (php -S; `DC_APP=apache` for a real Apache — both green 2026-10-10)
`large.php` (40): a 2.5 GB file (321 parts) through the routes — in order, in reverse, the gap filled; one file in the session's directory;
the running hash keeping up, then catching up; the finish in under 2 s (0.42 s under php -S, 0.51 s under Apache) with the file's sha256 and
one blob link; a range at 2.6 GB and the last ten bytes; the folder export holding it (stored, Zip64); the resume (the same session, the parts
held, the expiry moved, logged; never another person's, never another size); a part sent again (the same bytes, other bytes after and before
the hash); a session from before db/024; the expiry moved by a part; 507 for a file larger than the room and for one the open uploads'
promises leave no room for; the zone's words with and without a ceiling; the worker's sweep. `browser.mjs` (17, Chromium): a 2.1 GB file
chosen goes to done with its sha256 (84 s on this VM); a page left mid-way asks first, and the same file chosen again carries on from where
it stopped in ONE session; a part refused as the store full pauses the file with Resume, which finishes it; a part lost on the network is
sent again. Slice 2, 3, 6, 7 and 9's suites and the schema proof re-run with the ceiling NULL.
Measured on this VM (busy, x86-64-v2): about 25–40 MB/s end to end with three parts in flight — the sha256 (PHP, ~60 MB/s here) is the bound.
