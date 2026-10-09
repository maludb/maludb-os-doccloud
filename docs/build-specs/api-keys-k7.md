# Build spec: the API, keys and the K7 shares (slice 7)

What exists at the end: an outside system — a scanner, a script, a workflow tool — holds a **key** a person minted on *My settings* and acts as
that person through `/api/v1/*`: lists a folder, uploads, downloads, reads a document's text, tags, searches, finds in the graph, makes a link;
every call rate-limited (60 a minute, 5,000 a day; 429 with `Retry-After`) and logged under the key's label; a key rotates with a 24-hour
overlap and is revoked; **the four K7 shares** — `folders_index`, `documents_index`, `document_text`, `document_link` — answer a sibling
application through the kernel for the folders shared with it, `document_link` as a **signed ten-minute download URL** so bytes never cross
K7's JSON; every share read is logged under the consumer. Phase 4 serves the shares as records-server tools; this slice builds the SQL and PHP
they call and the API's routes.

Schema (db/003, 005, 008, 011, 014, 015, never modified): `mcp_access_tokens` (+ the rate columns), `key_usage`, `mcp_resolve_token()` (db/003);
`dc_resolve_key()`, `dc_rate_ok()`, `dc_key_calls_today()`, `dc_key_rotate()`, `dc_prune_key_usage()` (db/005); `dc_application_folder_rank()`,
`dc_application_can_see_document()` (db/008); `mcp_tokens` (db/015). The kit: `app/api/bootstrap.php` (`api_authenticate()`, `api_rate_limit()`,
`api_json()`, `api_error()`).

## Divergences from the design
1. **The download URL is signed with a DERIVED key, never installed**: `dc_link_signing_key() = hash_hmac('sha256', 'doccloud-link-signing-v1', ACTION_TOKEN_KEY)` — one line in `app/features/shares/gate.php`; nothing new in `config/.env` or `maludb-os.json`; the key follows the kernel's (rotating `ACTION_TOKEN_KEY` kills every live URL, which is right), and the label keeps a URL from ever being a token the kernel would accept. The URL: `/x/{document}/{version}/{expires}/{consumer}/{sig}` — `sig = hmac_sha256(dc_link_signing_key(), "document|version|expires|consumer")`, ten minutes, one document, logged `document.download` with `source = 'application'` and the consumer.
2. **A key's visibility is its person's and no more**; `api_authenticate()` sets `app.member_id` to the key's member; an agent never holds a key.
3. **`POST /api/v1/upload`** is one request ≤ 64 MB; parts through the API are Extended (the design's §8 says "parts beyond" — a `PUT
   /api/v1/upload/{session}/{n}` mirror of slice 2's exists for completeness: `api_upload_start/part/finish`).
4. **Shares answer only folders with an `application` row** naming the consumer, their subtrees, and documents under them that do not carry
   rows of their own (db/008's `dc_application_can_see_document()`); a document with its own rows needs its own application row.
5. **A share read is logged `share.read`** with `after: {tool, consumer, consumer_agent, rows}` — the consumer from the kernel's headers
   `X-OS-Consumer` / `X-OS-Consumer-Agent` (K26); no header → `consumer_unknown`, nothing shared.

## Who may do what
| Action | Right | Rule |
|---|---|---|
| key_mint / key_rotate / key_revoke (own) | `search.use` (any Member) | people only; a guest may not; an agent may not; `key_revoke` of another's key: `links.all` (admin) — `deletion` for agents |
| the API routes | the key's person's rights and levels | — |
| the shares | the kernel's token (and the consumer's row) | — |

## The API (`html/api/v1/…`; bearer = a key; `api_cors()` none; JSON in and out; errors `{error, message, field?}`)
| Route | Does | Answer |
|---|---|---|
| `GET /api/v1/me` | who the key acts as, its label and limits, today's count | `{member, label, rate_per_minute, rate_per_day, calls_today}` |
| `GET /api/v1/libraries` | `dc_my_libraries()` | `{libraries[]}` |
| `GET /api/v1/folders/{id}?sort&dir&page&kind` | `dc_folder_listing()` + crumbs | `{folder, crumbs, rows[], total}` |
| `POST /api/v1/folders` `{parent, name}` | `folder_create` | `{folder_id}` 201 |
| `POST /api/v1/upload` (multipart `file`, `folder`, `conflict`) | `upload_one()` | `{document_id, version_id, version_no}` 201; `409 {existing_document_id}` on a conflict without a choice |
| `POST /api/v1/upload/start` · `PUT /api/v1/upload/{session}/{n}` · `POST /api/v1/upload/{session}/finish` | slice 2's parts | as slice 2 |
| `GET /api/v1/documents/{id}` | `find_document()` + tags + versions | `{document, versions[], tags[]}` |
| `GET /api/v1/documents/{id}/download` (+ `?version=`) | `store_stream()` | the bytes (attachment) |
| `GET /api/v1/documents/{id}/text` | `document_text()` | `{text, pages[], char_count, status}` (≤ 5 MB) |
| `PATCH /api/v1/documents/{id}` `{name?, description?, folder?}` | rename / describe / move | `{document}` |
| `PUT /api/v1/documents/{id}/tags` `{tags: [slugs]}` | set the tags (apply the missing, remove the rest) | `{tags[]}` |
| `DELETE /api/v1/documents/{id}` | `document_trash` | 204 |
| `POST /api/v1/search` `{q, filters, page}` | `search()` | `{rows[], total, took_ms}` |
| `POST /api/v1/graph/find` `{q, filters, limit}` | `graph_find()` | `{status, rows[], subjects[]}`; 503 `graph_unavailable` |
| `POST /api/v1/links` `{document|folder, kind, label, days, allow_download, password?}` | `link_make` (the person's own `links.make`) | `{link_id, url}` 201 — shown once |
| `GET /api/v1/health` | no key | `{application, storage, graph}` |
Every route: `api_authenticate()` → `api_rate_limit()` (429 + `Retry-After`) → the handler's own rights and levels → logged with `source = 'api'`
and `token_id`. 401 for a missing, revoked, expired or `mcp`-scope bearer; 403 in words; 404 for what the person may not see; 413 over 64 MB.

## Keys (on *My settings*; `html/settings/keys/…`)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `settings-tokens` (the keys part) | `/settings/keys` | my keys (`mcp_tokens` scope api: label, made, last used, today's count, limits, rotated-from chip, expires (after a rotation)); **Mint a key** (`settings-tokens`); Rotate · Revoke per row; the admin's "all keys" is slice 9's |
| `settings-tokens` | `/settings/keys/new` | label; the limits shown (the settings' defaults; an admin may lower them per key); after: the key shown ONCE with Copy, "it acts as you" |

## The shares (`app/features/shares/{queries,gate}.php`; Phase 4 wraps them as records tools; `html/api/v1/shares/read.php` is the signed loopback
door Spaces uses — copy that shape: the records server calls PHP over loopback with the application token so one code path answers both)
- `share_gate(array $headers): array{consumer, consumer_agent}` — `X-OS-Consumer` (a catalog key `^[a-z][a-z0-9_]{0,39}$`) and `X-OS-Consumer-Agent`;
  missing → `consumer_unknown` (nothing shared, logged `share.refused`).
- `shares_folders_index(PDO, string $consumer): array` — `os.doccloud-folders/1`: every live folder whose access root carries an `application`
  row naming the consumer, plus their subtrees: `{folder_id, path (names), library, document_count, updated_at}`.
- `shares_documents_index(PDO, string $consumer, int $folderId, ?string $since, int $page): array` — `os.doccloud-documents/1`: the documents of
  that folder's subtree the consumer may see: `{document_id, name, mime_kind, byte_size, sha256, tags, owner_name, version_no, updated_at}`, paged 200,
  `since` on `updated_at`.
- `shares_document_text(PDO, string $consumer, int $documentId): array` — `os.doccloud-text/1`: `{document_id, version_id, text, pages, char_count, status}`
  (≤ 5 MB; `timeout_seconds 60`); a document the consumer may not see → `not_shared`.
- `shares_document_link(PDO, string $consumer, int $documentId): array` — `os.doccloud-link/1`: `{document_id, version_id, name, mime_type, byte_size, url, expires_at}`
  with the signed URL of divergence 1; `html/x/index.php` serves it (no session; the signature, the expiry and `dc_application_can_see_document()`
  re-checked at serve time; logged `document.download` under the consumer).
- Every share logs `share.read` through `files_log()` with `source = 'application'`, `after.consumer`, `after.consumer_agent`, `after.rows`.

## Files (exactly these)
- `html/api/v1/{me,libraries,folders,upload,documents,search,links}.php` (+ `html/api/v1/upload/{start,part,finish}.php`, `html/api/v1/graph/find.php`; the vhost's
  `/api/v1/` rewrites `documents/{id}/{download|text}` and `folders/{id}` to them), `html/api/v1/shares/read.php` (the loopback door), `html/x/index.php` (the signed download)
- `html/settings/keys/{index,form,mint,rotate,revoke}.php` (`settings-tokens` (the keys part), `settings-tokens`)
- `app/api/{routes,documents,upload,search,links}.php`, `app/features/keys/{queries,write}.php`, `app/features/shares/{queries,gate,sign}.php`
- `app/views/settings/{keys,key-form,key-once}.php`
- `docs/api.md` (the routes, the errors, curl examples — the owner's outside systems read it)
- `tests/phase3/slice7/{run.sh,lib.php,keys.php,api.php,upload.php,shares.php,signed.php,json.php,browser.mjs}`

## Query functions (signatures fixed)
- `my_keys(PDO): array` · `all_keys(PDO): array` (admin, slice 9) · `key_mint(PDO, int $memberId, string $label, ?int $perMinute, ?int $perDay): array{token_id, raw}` ·
  `key_rotate(PDO, int $tokenId): array{token_id, raw}` · `key_revoke(PDO, int $tokenId, int $by)`
- `signed_download_url(array $doc, array $version, string $consumer, int $ttl = 600): string` · `signed_download_verify(string $path): ?array`
- the shares above

## MCP tools this slice makes real
`find_keys`, `share_reads` (activity), and the four shares `folders_index`, `documents_index`, `document_text`, `document_link` (the kernel's token admitted to these
and `app_roles` alone — Phase 4).

## Manifest rows claimed
Screens (2): `admin-keys`, `admin-connections`
Actions (3): `key_mint`, `key_rotate`, `key_revoke`

The keys live on the shell's `settings-tokens` screen (this slice adds the keys part to it). The API's writes reuse the slices' action keys (`folder_create`, `document_upload`, `document_rename`, `document_describe`, `document_move`, `tag_apply`, `tag_remove`, `document_trash`, `link_make`): the manifest marks them `api: yes`; nothing new is registered. `key_revoke` is people-only and never pauses.

## Activity log events
`key.mint|rotate|revoke|rate_limited`, `share.read|refused`, `document.download` (source application), every API call's own event with `source = 'api'` and `token_id`.
A key is never logged; its label is.

## Status vocabulary
Key chip: live `success`, rotating (expires soon) `warning`, revoked `dark`. Ids: `keys-table`, `key-once`, `key-mint-btn`.

## Out of scope for this slice
Parts through the API beyond the mirror (Extended), WebDAV (Extended), the admin's all-keys screen (9), the connections screen (9, the kernel's).

## Proof (`tests/phase3/slice7/run.sh`: scratch database `dc_dev7`; ports as slice 5; the fake kernel answers the K7 door's loopback as the kernel would (the
`X-OS-Consumer` headers) and the application token; Playwright; the registry `--check`)
1. A key minted (shown once; the hash in the table; the label logged, never the value); `GET /api/v1/me` as it; an `mcp` token 401; a revoked key 401; a guest and an agent refused the mint.
2. The rate: the 61st call in a minute 429 with `Retry-After`; the day limit; `calls_today` on the screen.
3. The routes: a folder listed as the person sees it (another's personal library 404); an upload (201) and a conflict (409 then `conflict=version` 201); a download with the bytes and `nosniff`; text; a rename and a move (a target at view → 403); tags set; a search and a graph find (503 when the engine is off); a link made (the URL once); a trash (204); 413 over 64 MB; parts through the mirror.
4. A rotation: the new key works, the old one for 24 hours, then 401; the screen shows the overlap.
5. The shares through the loopback door with the kernel's headers: `folders_index` lists only folders with the consumer's row (and subtrees); `documents_index` pages and honours `since`, omits a document with its own rows; `document_text` answers the text and `not_shared` for another folder; `document_link` gives a URL that serves the bytes once within ten minutes and 404s after, with a wrong signature, for another consumer, and for a document unshared meanwhile; no header → `consumer_unknown` and `share.refused` logged; every read logged `share.read` with the consumer.
6. `ACTION_TOKEN_KEY` rewritten → every live `document_link` URL answers 404; every other share works.
7. JSON mode on the keys screens; the run-token gate (an agent never mints).
8. Browser at 375 and 1280: the keys screen, a key minted and shown once, rotated, revoked; no console errors.
9. Registry `--check`: 2 screens built, 3 actions, 1 approval in step; `docs/api.md`'s curl examples run green against the proof server.

## Open questions
None.
