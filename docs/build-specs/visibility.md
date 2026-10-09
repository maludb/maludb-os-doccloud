# Build spec: visibility — the walls as PHP uses them, sharing, the link and request doors (slice 3; every slice's gate)

The rules are the database's (db/008: `dc_my_principals`, `dc_folder_rank/level`, `dc_document_rank/level`, `dc_can_*`,
`dc_folder_restrict`, `dc_access_explain`, `dc_application_can_see_document`; db/011: `dc_link_mint/resolve/open/rate_ok`,
`dc_link_reaches_document`; db/014: `dc_shared_with_me`, `dc_my_shares`, `dc_access_history`). PHP never re-decides them: it asks,
answers **the same nothing** for what the caller may not see, shows why someone sees a thing, and logs. Design §3, §4, D5, D11.

## 1. The gate helpers (`app/doccloud/gate.php`, loaded by `app/bootstrap.php`; used by every slice)
```php
function dc_document_level(PDO $pdo, int $id): string            // 'none'|'view'|'upload'|'edit'|'manage' — SELECT dc_document_level(:id)
function dc_folder_level(PDO $pdo, int $id): string
function require_document_level(PDO $pdo, int $id, string $need): array   // the document row (documents, by the writer) or 404
function require_folder_level(PDO $pdo, int $id, string $need): array     // the folder row or 404
function dc_level_at_least(string $have, string $need): bool             // the rank order none < view < upload < edit < manage
function dc_my_principals(PDO $pdo): array                                // [[kind, id], …] once per request (static), for screens that group by principal
```
1. **404, never 403.** A record the caller may not see at the level asked is "Not found." (`respond_not_found()` / `refuse(404)`), whether
   it exists or not — a guest learns nothing from the difference. The one exception: a *lock* refusal (423) and a *quota/ceiling* refusal
   (413/422) on a thing the caller may see.
2. `require_document_level($id, 'view')` reads `documents` as the writer (`doccloud_rw` sees every row) and asks `dc_document_level(:id)`;
   `'upload'` is a folder-only level (`require_folder_level`); a trashed document answers `none` to everyone but its owner and managers
   (the SQL does this).
3. The admin's reach is logged: the first time per session a DocCloud admin opens a library they hold **no base level in**
   (`dc_library_base_level(lib, me) = 0`), log `library.admin_view` (`library_id`) and set `$_SESSION['admin_viewed'][lib] = true`.
   Every screen under that library then shows a quiet line: "You see this as the admin."
4. An action token (an agent) is judged exactly as a person: `app.member_id` is the run's member; the run-facts gate decides the tools;
   `is_eval` (run facts) refuses every write with 403 `eval_run`.

## 2. The share screen (`/f/{id}/share`, `/d/{id}/share`; `app/features/access/`)
1. **Gate**: `manage` on the folder or document. The screen: the library's base ("Everyone in the group *Board* can edit (the library)";
   "Everyone in the business can view (the library)"; a personal library: "Only you (your library)"), then **the inherited rows** greyed
   with "from *Contracts*" (`folders.access_root_id` ≠ this folder: the root's `folder_access` rows), then **this thing's own rows**
   (`folder_access WHERE folder_id = this` or `document_access`), each with the principal's name (`dc_principal_name`), kind badge (person,
   group, department, agent, guest, application, everyone), level, who granted it, when; a remove button; the level as an inline select.
2. **Add a share**: the **record picker** (`picker_field()`) with sources `people` (members, agents flagged "agent", externals flagged
   "guest" with a warning line "a guest sees only what is shared with them by name"), `groups` (live groups), `departments`; the level
   select (`view`, `upload` (folders only), `edit`, `manage`); an application share is the admin's: a select of the catalog keys the
   kernel's connections name (`reads` of §8 of the design — Knowledge, Spaces, Help Desk, Projects) — no picker.
3. **The additive rule works by itself**: the first `INSERT INTO folder_access` on a folder with no rows carries the inherited set along
   (the trigger). The screen says so before the first share: "Sharing this folder keeps what it inherits; use Restrict to narrow it."
4. **Restrict** (`POST /f/{id}/restrict`): the screen lists the effective principals with a checkbox each; the kept set is posted as
   `keep[]` (`kind:id` or `kind:ref`), levels as `level[kind:id]`; the handler builds the jsonb and calls `dc_folder_restrict(id, keep,
   by)`; the caller themselves is always kept at `manage` (added if absent — the function adds the maker when the set is empty). A
   document cannot be restricted (its own rows already replace the folder's: §2.6).
5. The actions (every one `require_post` + `verify_csrf` + the gate + `dc_guard` + `log_activity`):
   | Action key | Route | Body | Does | Pauses for an agent |
   |---|---|---|---|---|
   | `share_member` | `POST /f/{id}/share` or `/d/{id}/share` | `principal_kind=member`, `principal_id`, `level` | `INSERT … ON CONFLICT (…) DO UPDATE SET level` into `folder_access` / `document_access`; `dc_notify(principal, 'share_received', …)` | — |
   | `share_group` / `share_department` / `share_agent` | the same | kind group / department / agent | the same; a group share notifies its live members once each (dedupe key `share:<kind>:<thing>:<member>`) | — |
   | `share_guest` | the same | kind member where `dc_member_is_guest(id)` (the handler decides the key from the member) | the same | **external_send** |
   | `share_application` | the same | kind application, `principal_ref` | the same (folders only in v1) | **external_send** |
   | `share_level_set` | `POST …/share/level` | `access_id`, `level` | `UPDATE … SET level` (the trail by trigger) | — |
   | `share_remove` | `POST …/share/remove` | `access_id` | `DELETE`; when the folder's last row goes, inheritance resumes (the trigger recomputes the roots) | — |
   | `folder_restrict` | `POST /f/{id}/restrict` | `keep[]`, `level[]` | §2.4 | — |
   | `library_everyone_set` | `POST /libraries/{id}/everyone` | `everyone_level` | shared libraries only (`libraries.shared` right) | **external_send** |
6. **A document's own rows** (`document_access`): "Share this file only" on the document's share screen; the first row flips
   `documents.own_access` by trigger and the screen then says "This file no longer follows its folder's sharing (the folder's managers
   still manage it)"; removing the last row restores the folder's.
7. **Why** (`/d/{id}/access` and `/f/{id}/access`, the "who can see this" panel on the document and folder screens): for the caller,
   `dc_access_explain(document_id, NULL, me)` / `(NULL, folder_id, me)` → the sentences ("you own it", "member of the group *Board* → edit",
   "from *Contracts*: Bea → view"); for a manager, the same for **any member** picked (`?member=`) — the share screen's "check a person".
   **History** (`?at=YYYY-MM-DD`): `dc_access_history(document_id, folder_id, at)` — the explicit rows as they stood at the end of that day.
8. Logging: `folder.share` / `document.share` (`after` {principal_kind, principal_id|ref, level}), `folder.unshare` / `document.unshare`,
   `folder.restrict` (`after` {kept: n}), `library.everyone_set`; `folder_id` / `document_id` / `library_id` set; a principal's name is
   not logged (ids are).

## 3. Homes and lists that the walls shape
- **Shared with me** (`/shared`): `dc_shared_with_me(limit, offset)` — explicit rows naming me, my groups, my departments; the library
  base is not a share. A guest's home IS this list (plus "nothing has been shared with you yet").
- **My shares** (`/shares`): `dc_my_shares()` — what I granted; guests flagged; a "stale" marker past `stale_guest_share_days`.
- A folder listing (`dc_folder_listing`) and a search (`dc_search`) carry the wall in SQL — PHP adds nothing.
- The picker's `folders` source (for move/copy targets): folders where `dc_folder_rank(id) >= 2`, searched by name, paged — the source's
  query is `SELECT … FROM folders f WHERE f.trashed_at IS NULL AND f.name ILIKE … AND dc_can_add_to_folder(f.id) LIMIT 20` (never a set
  of every visible folder first).

## 4. Agents and the kernel's reach
- An agent holds what its member id holds. Its shares inside the business are free; `share_guest`, `share_application`, `link_make`,
  `link_rotate`, `request_make`, `library_everyone_set` pause through the kernel's approval hook (the registry's categories); the
  handler needs nothing for that beyond the registry entry.
- **K7** (slice 7, named here because it is a wall): the four shares (`folders_index`, `documents_index`, `document_text`,
  `document_link`) run on the records server with the consumer's key from the header `X-OS-Consumer` (K26); each row they answer passes
  `dc_application_can_see_document(id, consumer)` (or `dc_application_folder_rank(folder, consumer) > 0`); no header → `consumer_unknown`,
  nothing shared. `document_link` answers a **signed URL** `/d/{id}?sig=<hmac>&exp=<unix>&c=<consumer>` (HMAC-SHA256 over `id|exp|c` with
  `ACTIONS_RELAY_KEY`, 10 minutes); the gate (uploads-and-versions.md §4) accepts it as door `share`, re-checks
  `dc_application_can_see_document` at open time, logs `document.download` with `after.door = 'share'` and the consumer.

## 5. The link door `/s/<token>` (`html/s/index.php`; vhost `^/s/([a-f0-9]{48})(/.*)?$`)
1. No session is required and none of the directory is shown. The address hash: `hash('sha256', client_ip() . '|' . date('Y-m-d') . '|'
   . ACTION_TOKEN_KEY)` (never the address). First: `dc_link_rate_ok(hash, 'open_minute')` → false = 429 with a plain page ("Too many
   requests; try again in a minute") and `dc_link_open(link_id, 'rate_refused', hash)` when the token resolved.
2. `dc_link_resolve(token)` → nothing = **404** page ("This link is not available." — the same page for expired, revoked, rotated,
   never existed, a trashed target, a maker no longer admitted). `kind = 'request'` → redirect to `/r/<token>`.
3. **The password**: when `has_password` and `$_SESSION['link_ok'][link_id]` is not set, show the password page (one field, the link's
   label); `POST` with the password → `password_verify($p, $password_hash)` → set the session flag; wrong → `dc_link_open(…,
   'password_refused', hash)` and the page again (rate-limited by step 1). The session is PHP's ordinary anonymous session (the kit's
   `apiPost`-style CSRF token on the form).
4. **A document link**: the view page (the document's name, size, kind, the maker's display name as "shared by", `note`, the preview as
   uploads-and-versions.md §9 renders it through `/d/{id}?inline=1` with the link in the session), "Download" when `allow_download`;
   `dc_link_open(link_id, 'view', hash, document_id)` once per page view; the gate counts downloads (`'download'`, bytes).
5. **A folder link**: a read-only listing of the folder's subtree (`documents WHERE folder_id IN dc_folder_subtree_ids(folder) AND
   trashed_at IS NULL`, subfolders as crumbs — the link's own gate, not the member walls), each document opening `/s/<token>/d/{id}`
   (the view page) and `/d/{id}` through the gate with `dc_link_reaches_document`; "Download all" → `exports` with `link_id` (the worker
   builds the zip of the subtree; `/s/<token>/zip/{export_id}` serves it; `allow_download` required).
6. Every page is `noindex` (`<meta name="robots" content="noindex">` and `X-Robots-Tag`), carries no navigation, no other names.
7. Logged as `link.open` (`source = 'portal'`, `after` {link_id, what, document_id}) by the door, counted by `dc_link_open`; refusals as
   `link.refused` {reason: not_found|expired|revoked|password|rate}.

## 6. The request door `/r/<token>` (`html/r/index.php`)
1. Steps 1–3 as §5 (the rate rule for the page is `open_minute`; for an upload `upload_hour` → `dc_link_rate_ok(hash, 'upload_hour')`
   per file, 20 an hour per address).
2. The page: the maker's display name ("<Marco> is asking for files"), the `note`, a name field (the uploader's, ≤ 120, kept in the
   version's `note` as "from <name> via the request <label>"), the drop zone; **nothing of the folder is shown** — not its name, not its
   contents, not earlier uploads, not even the uploader's own.
3. An upload = the parts API (`/upload/start|part|finish` with `request_token` instead of a session; `upload_sessions.link_id` set,
   `member_id = share_links.made_by`): the document lands in the request's folder **owned by the maker**, `source 'request'`, tagged
   `request:<slug of label>` (`dc_tag_ensure` free tag, `dc_tag_apply(…, 'request')`), a same-name conflict is resolved as "keep both"
   automatically; `dc_link_open(link_id, 'upload', hash, document_id, bytes)`; `share_links.upload_count` grows (the function).
4. After each file: `dc_notify(made_by, 'request_received', '<n> files arrived in <folder> from <label>', …, dedupe per hour
   `request:<link>:<hour>`)`; the text channel when the maker opted in (`notification_prefs.text_enabled` and `request_received` in
   `text_kinds`) goes through the kernel's K6 (`POST /api/v1/notify/sms.php` by the outbox pass).
5. The maker closes a request with `link_revoke` (or it expires; `worker_pass_requests_close` revokes requests past `expires_at` and
   notifies the maker once). Logged `request.upload` {link_id, document_id, bytes}, `request.close`.

## 7. Links and requests, the maker's side (`/f/{id}/links`, `/d/{id}/links`, `/requests`)
| Action | Route | Gate | Does | Pauses |
|---|---|---|---|---|
| `link_make` | `POST /f/{id}/links` or `/d/{id}/links` | manage | `dc_link_mint(folder|NULL, document|NULL, 'view', label, days (NULL = default; 0 = never, admin-allowed), allow_download, password_hash (PHP `password_hash()` of the given password or NULL), by, note)` → the raw token **shown once** on the next screen (`?made=<link_id>` with the raw in the session for one render); the full URL `app_url('/s/' . raw)` | external_send |
| `link_rotate` | `POST /links/{id}/rotate` | manage of the thing | `dc_link_mint(… p_rotate_of = id)` — the old link dies now, the new shown once | external_send |
| `link_revoke` | `POST /links/{id}/revoke` | the maker, manage, or `links.all` | `UPDATE share_links SET revoked_at = now(), revoked_by` | deletion (not its own) |
| `request_make` | `POST /f/{id}/requests` | manage | `dc_link_mint(folder, NULL, 'request', label, days, false, password_hash, by, note)` | external_send |
The list: `dc_find_links(folder, document, kind, live_only)` with opens (`link_opens` grouped: views, downloads, uploads, distinct
address keys), `last_used_at`, "expires in N days", rotate / revoke / copy the URL (the URL is never shown again after the make — the
list shows the label and a "rotate to get a new URL" action). The admin's `/admin/links` lists all (`links.all`).

## 8. The proof (`tests/slice3/run.sh`; the actors of `db/proof/phase0_proof.sql` through HTTP, php -S on 8701 with the fake kernel)
1. The schema proof's wall checks as HTTP: for each of Priya (26), Marco (27), Bea (28), Ann the guest (29), Dana (30), Seamus the agent
   (40, an action token), the Watcher (41, not admitted), the admin (1): `GET /d/{id}/view` and `GET /f/{id}` on the Board's Contracts,
   2026, Minutes, Priya's notes, the handbook → 200 or 404 exactly as `dc_document_level` says; the Watcher 404 everywhere; the admin 200
   everywhere with `library.admin_view` logged once per library per session.
2. Share Contracts with Bea at view (Marco): the trigger's group row appears; Bea's `/f/{2026}` 200, `/f/{Minutes}` 404; `share_received`
   rang Bea's bell and queued her email once (dedupe); Bea's `/shared` lists it.
3. Restrict 2026 to Bea and Marco: Priya's `/d/{acme}/view` → 404, `/f/{Contracts}` → 200; the trail (`/f/{2026}/access?at=today`) lists
   the two; the admin still 200.
4. Priya at `upload` on 2026: her upload lands (owner Priya), she sees it (200) and not acme (404); Bea sees both.
5. A file shared only with the guest: Ann's `/shared` lists one, `/d/{minutes}/view` 200, `/f/{Minutes}` 404, `/search?q=termination`
   answers only it; Priya (a group member) now gets 404 on that file; Marco (manager) 200.
6. `share_guest` and `share_application` by Seamus (an action token): the fake kernel's hook pauses them (the registry's category); by
   Marco (a person): done.
7. A link on acme: the raw token shown once; `GET /s/<raw>` 200 with the preview and a download; a wrong token, the rotated one, the
   revoked one, an expired one → the same 404 page; a password link asks once per session and refuses a wrong password (counted); the
   61st open in a minute from one address → 429; `link.open` rows carry an address hash, never the address.
8. A folder link on Contracts: lists acme and 2026's documents, never Minutes'; `/d/{minutes}` with that link in the session → 404;
   "Download all" builds a zip of the subtree only.
9. A file request on Minutes: the page shows the maker and the note and nothing of the folder; an upload lands owned by Marco, source
   `request`, tagged `request:supplier-uploads`, Marco's bell rings; the uploader's second visit shows nothing; the 21st upload in an
   hour → 429; after `expires_at` the worker revokes it and the page is 404.
10. `document_link` (K7, slice 7's proof repeats it): a signed URL for a document shared with `knowledge` opens for 10 minutes and
    re-checks the application row; one for `helpdesk` → 404.
11. Every refusal is a 404 page with no name on it; every share, unshare, restrict, link and request is in the activity log with ids only.
