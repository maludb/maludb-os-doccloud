# Build spec: access, sharing, links and file requests (slice 3)

What exists at the end: a folder's or a document's **Share** screen shows who sees it and why — the inherited rows greyed with "from Contracts",
the explicit rows, the library's base as a line — and lets a manager add a person, a group, a department or an agent at a level, a guest
(pauses for an agent), a sibling application (pauses), **restrict** the folder, and explain any person's reach in one sentence;
**links** open a document or a folder to anyone holding them, with an expiry, optional password and download switch; **file requests**
receive uploads into a folder from outside; **Shared with me** and **My shares** are real; "who could see this on June 3rd" is answered from
the trail. The rules — the walls, inheritance, additive sharing, restrict, the ownership rule, the scale rule — are **the build spec
`visibility.md`** (worker B's); this slice names the screens, the handlers, the doors and the proof.

Schema (db/008, 011, 014, 015, never modified): `folder_access`, `document_access`, `access_changes`, `dc_my_principals()`, `dc_folder_level()`,
`dc_document_level()`, `dc_folder_restrict()`, `dc_access_explain()`, `dc_principal_name()`, `dc_application_*()` (db/008); `share_links`,
`link_opens`, `link_rate`, `dc_link_mint()`, `dc_link_resolve()`, `dc_link_open()`, `dc_link_rate_ok()`, `dc_link_reaches_document()`,
`dc_links_suspicious()` (db/011); `dc_shared_with_me()`, `dc_my_shares()`, `dc_stale_guest_shares()`, `dc_links_expiring()`, `dc_find_links()`,
`dc_access_history()` (db/014); `mcp_folder_access`, `mcp_document_access`, `mcp_access_changes`, `mcp_share_links`, `mcp_link_opens` (db/015).
**The database is the referee**: the first explicit row carries the inherited set, a restrict writes the explicit set, the access root is
recomputed by trigger, a link's token is hashed and never stored, a request is on a folder only, the rate per address, "never expires" only when
the admin allows.

## Divergences from the design
1. **A share to a person who is a guest is `share_guest`** even when posted from the person form: the handler judges `mcp_members.is_guest` and
   routes the write to the paused action (an agent's call then waits; a person's goes through). Design §5 names both keys; the share screen has
   one picker.
2. **A document's own rows** are made by the same handlers with `document` instead of `folder`; the first row on a document carries nothing
   (a document inherits by the folder's resolution until it has rows — then its rows alone, and the folder's managers keep managing).
3. **A link's password** is checked once per browser session on the public page (a signed cookie `DCL_<link>` for 12 hours).
4. **The request door's upload** uses slice 2's one-request `upload_one()` with `source = 'request'`, the request maker as the owner, the tag
   `request:<label>` applied through `dc_tag_ensure()` (slice 4's table; the function exists since Phase 0) and a notification
   `request_received` (K6 text when the maker opted in).
5. **`link_update`** (allow_download, note, expiry shortened) exists for a live link without rotating it; lengthening an expiry or adding
   "never" rotates (`link_rotate`, `external_send`).

## Who may do what
| Action | Right | Level |
|---|---|---|
| share (member, group, department, agent), level set, remove, restrict | `documents.share` | `manage` on the folder or document |
| share_guest, share_application | `documents.share` | `manage`; `external_send` for agents |
| link_make / link_rotate / link_update / link_revoke | `links.make` | `manage`; make and rotate pause for agents; revoke `deletion` |
| request_make / request_close | `requests.make` | `manage` on the folder; make pauses |
| access_explain, access_list | — | `manage` on the thing (a `view` holder sees only their own explanation: `my_level`) |
| access_history | `reports.search` or `manage` | — |
| the public doors `/s/`, `/r/` | — | the token is the authority; rate-limited per address |

## Screens (375 px first; the share screen is a table + four add forms; the public pages are bare)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `folder-share` / `document-share` | `/f/{id}/share` · `/d/{id}/share` | the library base line ("Everyone in SMOKE Board edits (the group's level)" / "Everyone in the business views" / "Only you"); **the inherited rows** greyed with "from <folder>" (when `access_root_id` is above); **the explicit rows** (`mcp_folder_access` / `mcp_document_access`: principal name with kind chip person · group `info` · department `secondary` · agent `info` · guest `warning` · application `light` · everyone `light`, level select (posts `share_level_set`), granted by, when, Remove); **Restrict** (a button with the sentence "stop inheriting: keep only the rows below" — a confirm; `folder_restrict` posts the current explicit set); four add forms: **A person or an agent** (the `member` picker, multi; level view · upload (folders) · edit · manage), **A group** (the `group` picker), **A department** (a select of live departments), **A guest** (the `guest` picker: externals holding Guest; level view · edit; the line "a guest sees only what is named to them"); **An application** (a catalog key with a datalist of the siblings; view only; "the kernel must also approve a connection"); **Who can see this and why** — a `member` picker + Explain → `dc_access_explain()` as sentences; **Links** and **File requests** summaries with links to their screens |
| `folder-links` / `document-links` | `/f/{id}/links` · `/d/{id}/links` | the live links (`dc_find_links()`: label, kind chip, expires, password `lock`, download yes/no, opens, uploads, last used; Rotate · Update · Revoke); revoked and rotated below; **Make a link** (`link-add`) |
| `link-add` | `/f/{id}/links/new` · `/d/{id}/links/new` · `/links/{id}/edit` | label, expires in (7 · 30 · 90 days · a date · never — "never" shown only when the setting allows), allow download, password (optional, shown once in the form only), note; after making: the URL shown ONCE with Copy |
| `requests` | `/f/{id}/requests` | the folder's file requests (label, message, expires, uploads, last upload; Close); **Request files** (`request-add`) |
| `request-add` | `/f/{id}/requests/new` | label, message to the uploader, expires in; after: the URL shown once |
| `shared-with-me` | `/shared` | `dc_shared_with_me()` as the listing table + "shared by X on …" and the level chip; a guest's home |
| `my-shares` | `/shares` | `dc_my_shares()`: what, to whom (guest rows flagged `warning` with "older than 90 days" when stale), level, when; Remove |
| `access-history` | `/d/{id}/access-history?at=` · `/f/{id}/access-history?at=` | a date picker; `dc_access_history()`: who could see it then and since when |
| `public-link` | `/s/{token}` | a document: name, size, kind, preview (as the document page, read-only), Download (when allowed); a folder: a read-only listing of the subtree the holder may browse, Download all (a zip export keyed by `link_id`); the maker's note; the password page when set; `noindex`; the business name only — no directory, no shell |
| `public-request` | `/r/{token}` | who asks (the maker's display name) and the message; the drop zone (one-request uploads, ≤ 64 MB each, the rate 20/hour per address); "Thank you" — the uploader sees nothing afterwards |

## Handlers
- `share_member` / `share_group` / `share_department` / `share_agent` (`html/access/add.php`, one handler with `principal_kind`; a guest routed to
  `share_guest`), `share_guest` (`add-guest.php`), `share_application` (`add-application.php`), `share_level_set` (`level.php`), `share_remove`
  (`remove.php`), `folder_restrict` (`restrict.php`: the kept rows as `rows[]`), `access_explain` (`explain.php`, GET, JSON too). Each:
  `dc_handler_begin()`, `require_right('documents.share')`, `require_folder_level($id, 'manage')` or the document's, `dc_guard()`, the write,
  `files_log()` with `after: {principal_kind, principal_id|principal_ref, level}`, a notification `share_received` to a person (and to a group's
  or department's live members, deduped `share:<thing>:<member>`), `emit_action_status()`.
- `link_make` (`html/links/make.php`), `link_rotate` (`rotate.php`), `link_update` (`update.php`), `link_revoke` (`revoke.php`), `request_make`
  (`html/requests/make.php`), `request_close` (`close.php` = revoke of a request link). The raw token is in the handler's response ONCE
  (`did.url`) and never logged; `log_activity('link.make', 'link', $id, ['after' => ['label', 'kind', 'expires_at', 'allow_download', 'has_password']])`.
- The public doors: `html/s/index.php` (`dc_link_resolve()` → nothing = 404 page "This link is not available"; `dc_link_rate_ok()` else 429;
  the password step; `dc_link_open()` on view/download; a folder link's documents through `dc_link_reaches_document()`; source `portal`;
  logged `link.open` with `after: {link_id, what, document_id}` — never the token, never the address), `html/s/download.php`, `html/s/zip.php`,
  `html/r/index.php`, `html/r/upload.php` (`request_upload`: `upload_one()` as the maker, `dc_link_open(..., 'upload')`, `request.upload`).
  Both doors send `X-Robots-Tag: noindex`, no session cookie but the password cookie, no CSRF (the token is the authority; the upload form
  carries the link id and a per-page nonce).

## Files (exactly these)
- `html/access/{index,add,add-guest,add-application,level,remove,restrict,explain,history}.php` (`folder-share` / `document-share`, `access-history`; `/f/{id}/share` and
  `/d/{id}/share` routed with `kind` + `id`)
- `html/links/{index,form,make,rotate,update,revoke}.php` (`folder-links` / `document-links`, `link-add`) · `html/requests/{index,form,make,close}.php` (`requests`, `request-add`)
- `html/shared/index.php` (`shared-with-me`, replaces the placeholder) · `html/shares.php` (`my-shares`)
- `html/s/{index,download,zip}.php` (`public-link`) · `html/r/{index,upload}.php` (`public-request`)
- `app/features/access/{queries,present,write,handler}.php`, `app/features/links/{queries,write,public}.php`
- `app/views/access/{share,history,partials/row,partials/explain}.php`, `app/views/links/{index,form,shown-once}.php`, `app/views/requests/{index,form}.php`,
  `app/views/public/{layout,link-document,link-folder,password,request,thanks,unavailable}.php`, `app/views/files/{shared,shares}.php`
- `app/pickers.php` gains `guest` (externals holding Guest), `application` (the known siblings' catalog keys, a static list + free text)
- `tests/phase3/slice3/{run.sh,lib.php,shares.php,restrict.php,links.php,requests.php,public.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `access_rows(PDO, string $kind, int $id): array{base_line, inherited_from, inherited, explicit}` · `access_explain(PDO, string $kind, int $id, int $memberId): array`
- `find_links(PDO, string $kind, int $id, bool $liveOnly): array` · `find_link(PDO, int $id): ?array` · `find_requests(PDO, int $folderId): array`
- `shared_with_me(PDO, int $page): array` · `my_shares(PDO): array` · `access_history(PDO, string $kind, int $id, string $at): array`
- public: `link_resolve(PDO, string $raw): ?array` · `link_listing(PDO, int $linkId, ?int $folderId): array` · `link_document(PDO, int $linkId, int $documentId): ?array`
- writes: `share_add(PDO, string $kind, int $id, string $principalKind, ?int $principalId, ?string $ref, string $level, int $by): array` · `share_level_set` ·
  `share_remove` · `folder_restrict(PDO, int $folderId, array $rows, int $by)` · `link_make(PDO, string $kind, int $id, array $f, int $by): array{link_id, url}` ·
  `link_rotate` · `link_update` · `link_revoke` · `request_make(PDO, int $folderId, array $f, int $by): array` · `request_close` ·
  `link_open(PDO, int $linkId, string $what, string $addressHash, ?int $documentId, ?int $bytes)` · `request_upload(PDO, int $linkId, array $file): array`

## MCP tools this slice makes real
Also: `link_opens`, `access_changes` (the surface's rows this slice's screens read).
`access_list`, `access_explain`, `my_level`, `shared_with_me`, `my_shares`, `stale_guest_shares`, `find_links` (the resolver), `links_expiring`,
`links_suspicious`, `find_requests`, `access_history`.

## Manifest rows claimed
Screens (12): `folder-share`, `document-share`, `folder-links`, `document-links`, `link-add`, `request-add`, `requests`, `access-history`, `shared-with-me`, `my-shares`, `public-link`, `public-request`
Actions (15): `share_member`, `share_group`, `share_department`, `share_agent`, `share_guest`, `share_application`, `share_level_set`, `share_remove`, `folder_restrict`, `link_make`, `link_rotate`, `link_update`, `link_revoke`, `request_make`, `request_close`

Routes, not actions: the public doors' POSTs — `link_password` (`/s/{token}/password`) and `request_upload` (`/r/{token}/upload`) — take no session and no action token and are never on the Actions MCP.

## Activity log events
`folder.share|unshare|level_set|restrict`, `document.share|unshare|level_set`, `access.explain` (not logged — a read), `link.make|rotate|update|revoke|open|refused|expire`,
`request.make|close|upload`, `share.guest`, `share.application`.

## Notifications this slice queues
`share_received` (a person; a group's and a department's live members, deduped), `request_received` (the maker, per upload; K6 text when
`text_kinds` holds it), `link_opened` (optional, off by default in `kinds`).

## Status vocabulary
Principal chips as above; link chips: live `success`, expiring (< 7 days) `warning`, expired/revoked/rotated `dark`, password `lock` icon.
Ids: `share-base-line`, `share-inherited`, `share-rows`, `share-restrict-btn`, `share-add-person`, `explain-result`, `links-table`, `link-url-once`,
`public-download-btn`, `request-drop-zone`.

## Out of scope for this slice
Tags on request uploads beyond the `request:` tag (4), the Clerk's stale-share proposals (8), the admin's "all links" (9), K7's application reach (7).

## Proof (`tests/phase3/slice3/run.sh`: scratch database `dc_dev3`; ports as slice 2; a scratch storage directory; Playwright; the registry `--check`)
1. A manager shares a folder with a person at view: the first row carries the group's base along; the person sees the folder and its documents; a non-member still cannot.
2. A share to a department reaches its live members and not a guest; to a group its live members; to an agent the agent (a run token lists the folder).
3. `share_guest` by a person goes through; by an agent's run token it pauses (the fake kernel's approval); the guest sees the one folder, nothing else, and Shared with me lists it.
4. `share_application` writes an application row; `dc_application_can_see_document()` answers for that key and not another; an agent's call pauses.
5. Restrict: the group loses the folder; the named rows keep it; the admin keeps it; the library owner keeps it; the trail holds every change; `access_history` at a timestamp between two changes answers the earlier set.
6. A document's own rows: shared with one person, the folder's group no longer sees it, its managers do, the owner does.
7. Explain: four people × a folder and a document → the sentences the design gives (owner, library, folder row, inherited from).
8. Links: made (the URL once, never in the log or the DB), opened on `/s/` (200, the preview, the download), counted, rate-limited at the 61st open from one address (429), expired after the date (404), password right/wrong (cookie / refused and logged), revoked (404), rotated (the old 404, the new 200), "never" refused unless the setting allows; a folder link lists its subtree and not a sibling folder; its zip holds the right files.
9. A file request: `/r/` shows the message; an upload lands in the folder owned by the maker with the `request:<label>` tag and source `request`; the maker is notified (and texted through the fake kernel's K6 when opted in); the uploader's second GET shows nothing of the upload; the 21st upload in an hour from one address 429; a closed request 404.
10. My shares lists what I granted; a guest row older than 90 days (seeded) flagged stale.
11. A `view` holder opening `/f/{id}/share` gets only their own explanation (no rows); JSON mode on every screen and handler; the run-token gate on the actions.
12. Browser at 375 and 1280: the share screen with the picker adding a person and a group, restrict with the confirm, the link made and shown once, the public page on a phone with the download, the request page with a drop; no console errors.
13. Registry `--check`: 10 screens built, 16 actions (15 on the registry; `request_upload` public), 6 approvals in step.

## Open questions
None.
