# Build spec — the grid view, thumbnails, video streaming and hidden previews

2026-10-10 · asked by the owner after Phase 4; decided the same day (the four answers below). Built on `main` through the
worktree `grid-media`, additive to slices 2 and 6 (uploads, serving, the worker) and slice 1 (the folder screen).

## What the owner asked for
1. The folder's listing in two layouts: the **list** we have and a **grid** whose tiles show a **thumbnail** for an image or a
   video, and an **icon for the file's type** when no preview is possible.
2. **Watch a video in DocCloud** without downloading it.
3. **Hide an image's or a video's preview** behind a placeholder, so that someone walking past the screen sees nothing: a
   **toggle switch below the thumbnail**.

## The decisions (the owner, 2026-10-10)
| # | Question | Answer |
| --- | --- | --- |
| G1 | Whose screen does "hide preview" affect? | **Everyone's.** It is a property of the document, flipped by anyone with **edit** on it (`documents.edit`); every viewer, a link holder included, sees the placeholder. |
| G2 | Video thumbnails | **ffmpeg** on the server (installed on this host 2026-10-10; `deploy/ROOT_STEPS.sh` step 0c for others). A frame 10% in (at most 5 s), and the length for a badge. |
| G3 | Formats a browser cannot play | **Play what plays**: stream MP4, WebM, MOV (H.264) and whatever the browser reports it can; otherwise say so and offer Download. No transcoding (Extended). |
| G4 | The document page of a hidden preview | **A placeholder with Show** (reveals it for that one view; the setting stays) and the same switch for an editor. |

Taken in this spec (the owner may overrule): the layout is remembered **per browser** in a cookie (`dc_view`), so a phone can keep
the grid and a desk the list, and `?view=list|grid` switches it. Hiding is for **images and videos** only (the question's words); a
PDF keeps its first-page thumbnail. Hiding ignores a lock: a lock claims the content, and the preview setting is not content.
**Hiding is not a wall**: whoever may view the document may still open it. The placeholder protects a screen, not a secret.

## Schema — `db/023_grid_media.sql` (additive)
- `document_versions` gains `thumb_state` (`NULL` = still to do, `made`, `none` = not possible for these bytes, `no_tool` = the
  tool is missing, retried once it exists) and `duration_ms` (a video's length, from ffprobe). A partial
  index serves the worker's queue.
- **`document_preview_hidden`** (NEW): `document_id` PK → documents ON DELETE CASCADE, `hidden_by` → members, `hidden_at`. A row
  = hidden. A table, not a column, because every UPDATE of `documents` moves `updated_at` (the "Modified" date) and wakes the
  search trigger; hiding a preview must do neither. Shared-schema record: **new** (no sibling keeps one).
- `dc_folder_listing()` is replaced (DROP + CREATE, grants restored) with three columns appended: `mime_type`, `preview_hidden`,
  `duration_ms`; `has_thumbnail` now means a thumbnail was made (`thumb_state = 'made'`).
- `mcp_documents` appends `preview_hidden`; `mcp_document_versions` appends `thumb_state`, `duration_ms`.

## Thumbnails (the worker's `thumbnails` pass, `dc_make_thumbnail()`)
- Kinds: image (GD; a JPEG turned upright by its EXIF orientation), PDF (pdftoppm, first page), **video (ffmpeg)**. 320 px on
  the longer side, JPEG, `thumbs/<document>/<version>.jpg` as before.
- The queue is the current version of each live document of those kinds whose `thumb_state` is `NULL`, or `no_tool` when the tool
  now exists; newest first, at most 50 a pass. A thumbnail already on disk (made before db/023) is recorded `made` without being
  remade, unless it is a JPEG photo whose EXIF orientation is not upright, which is remade once. The old `.none` marker counts as `none`.
- ffmpeg and ffprobe run under `timeout` (60 s and 20 s), one thread, `nice`, with the input as an argument (never a shell
  string); the bytes are a person's upload, so a parser fault costs one thumbnail, never the pass.

## The grid (folder screen)
- A **List | Grid** switch beside the kind filters; the grid has its own sort links (Name, Modified, Size; the same `sort`/`dir`
  params) and keeps the pager, the kind filters and the select bar (each tile a checkbox; "Select all").
- Tiles: 2 a row at 375 px, then 3 / 4 / 5 / 6. A square preview area: the thumbnail (`object-fit: cover` for images and videos,
  `contain` for a PDF page), `loading="lazy"`; a video has a play badge and its length; a folder its icon; anything else a **type
  icon with the extension** (Word, spreadsheet, presentation, PDF, text, code, archive, audio, video, image, other — each its own
  colour). Below: the name, size · modified, star and lock marks.
- The thumbnail's URL carries the version (`/d/{id}/thumb?v={version}`) so a new version is never hidden behind an old one in the cache.
- **The switch** (`form-switch`, "Hide preview") sits below the preview area on an image or video tile for a caller who may edit
  the document; it posts on change and the tile is swapped in place (HTMX `outerHTML`; without JS, a Save button). A hidden tile
  shows the placeholder (the eye-off mark and "Preview hidden") and **loads no image at all**: no blur, no request.

## Video and the document page
- `/d/{id}` already serves ranges. Fixed for streaming: **the session is released before any bytes are sent**, so a playing video
  or a long download no longer blocks the person's other pages (PHP held the session file's lock for the whole stream); and **one
  log row per viewing**: the opening request (no Range, or one from byte 0) is logged and `last_opened_at` set, while the player's
  later range requests are not.
- The player: `controls playsinline preload="metadata"`, the poster from the thumbnail (unless hidden). A MOV is offered to the
  player as `video/mp4`. AVI, WMV/ASF, FLV and MPEG-PS are known not to play: the page says so with Download. Anything else the
  browser rejects (`canPlayType` empty, or an error on the source) switches to the same message.
- **Hidden:** the preview card shows the placeholder, **Show** (a `<template>` holds the preview, so nothing loads until asked) and
  for an editor the switch. The public link page (`/s/{token}`) shows the same placeholder and Show, without the switch.
- The thumbnail door `/d/{id}/thumb` serves the placeholder SVG while a document's preview is hidden, so every surface (search,
  recent, favourites, the API's `thumb_url`) shows it too.

## Actions (manifest, base `/documents/`)
| Action | File | Params | Undo | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `document_preview_hide` | `preview-hide.php` | **document** (an image or a video) | document_preview_show | `document.preview_hide` | documents.edit and edit on the document | yes | the document |
| `document_preview_show` | `preview-show.php` | **document** | document_preview_hide | `document.preview_show` | documents.edit and edit on the document | yes | the document |

Neither pauses for an agent (a display setting inside the business). Refusals: not an image or a video → 422 on `document`. HTMX
with `swap=tile` answers the re-rendered tile; JSON answers the result contract; a browser lands on `return_to` with a notice.
The expert is granted both (it files and tidies). Payload: the name and the kind; nothing else.

## Manifest rows claimed
Screens (0):
Actions (2): `document_preview_hide`, `document_preview_show`

The grid is the `folder` screen with `?view=grid` and the player and placeholder are the `document` screen — both claimed by their slices.

## MCP tools this slice names
`folder_listing` (its rows gain mime_type, preview_hidden and duration_ms; has_thumbnail now means made) and `get_document` (the view's
row gains preview_hidden). No new tool: hiding is an action, and the length and the thumbnail are facts on rows the tools already return.

## Proof — `tests/phase3/media/run.sh`
The schema (db/023 on the scratch database, the listing's new columns, the hidden row and the cascade); thumbnails for a PNG, an
EXIF-rotated JPEG (upright), a PDF and an MP4 made by ffmpeg (with its length), a `no_tool` version retried once the tool is
there, legacy files recorded without being remade; the grid (tiles, icons per type, the placeholder loading no image, the switch only
for an editor, the cookie); the two actions (rights, levels, 422 for a PDF, the tile swap, the JSON contract, an agent, the
log rows, `updated_at` untouched); serving (the session released during a stream, one log row for a range sequence, the poster,
the MOV source type, the unplayable message, the hidden placeholder with Show on the document page and the link page, the
thumbnail door's placeholder); the registry (97 actions). Slices 1, 2, 6, Phase 2 and Phase 4 re-run green.
