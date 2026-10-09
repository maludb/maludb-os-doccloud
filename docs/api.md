# DocCloud's API

A scanner, a script or a workflow tool reaches DocCloud with a **key** a person minted on *My settings → API keys*. A key **acts as that person**: it
sees exactly what they see and may do what they may do (the same walls, the same rights, the same locks), never more. Everything it does is logged
under the key's **label** with source `api` — never the key itself.

Base address: `https://doccloud.<your domain>/api/v1/`. Send the key as `Authorization: Bearer dck_…`. JSON in and out (`Content-Type: application/json`);
uploads are multipart.

## Keys

* Mint one on *My settings → API keys → Mint a key* (a label, the limits, an expiry). It is shown **once**; only its hash is kept.
* **Limits:** 60 calls a minute and 5,000 a day by default (the admin sets the defaults; you may lower them for a key). Over a limit the answer is
  `429` with a `Retry-After` header (seconds).
* **Rotate** makes a new key for the same person, label and limits; the old key keeps working for the overlap (24 hours by default), then answers `401`.
* **Revoke** closes a key now. An expired, revoked or unknown key, and a token of the other kind (the `mcp_…` tokens that read the two MCP servers),
  answer `401`.

## Errors

Always `{"error": {"code": "…", "message": "…"}}` (a field problem also carries `"errors"` and `"fields"`), and the status says which kind:

| Status | Code | Means |
|---|---|---|
| 400 | `invalid` | the body is not JSON |
| 401 | `unauthorized` | no key, or a key that is revoked, expired or not a key |
| 403 | `forbidden` | you see it but may not do that (the words say what is missing) |
| 404 | `not_found` | it does not exist **or you may not see it** — the same answer for both |
| 405 | `method_not_allowed` | the route does not take that method |
| 409 | `name_taken` | an upload whose name is taken (`existing_document_id`); say `conflict` |
| 413 | `too_large` | a one-request upload over 64 MB (use the parts form), or a file over the ceiling |
| 422 | `invalid` | a field is wrong (`fields` names it) |
| 423 | `locked` | someone else holds the document's lock |
| 429 | `rate_limited` | over the key's minute or day limit (`Retry-After`) |
| 503 | `graph_unavailable` | the document graph is not reachable (graph routes only; keyword search needs no engine) |

## The routes

| Route | Does |
|---|---|
| `GET /health` | no key: `{ok, application, storage, graph}` |
| `GET /me` | who the key acts as, its label and limits, today's count |
| `GET /libraries` | the libraries you reach, with your level |
| `GET /folders/{id}?sort=&dir=&page=&kind=&per=` | the folder, its crumbs and a page of its listing |
| `POST /folders` `{parent, name}` | a new folder → `201 {folder_id}` |
| `POST /upload` (multipart `file`, `folder`, `conflict`) | one request, up to 64 MB → `201 {document_id, version_id, version_no}`; `409` if the name is taken and `conflict` (`version`, `keep_both`, `refuse`) is not given |
| `POST /upload/start` · `PUT /upload/{session}/{n}` · `POST /upload/{session}/finish` | the same upload in parts (8 MB by default; resumable) |
| `GET /documents/{id}` | the document, its versions and its tags |
| `GET /documents/{id}/download?version=` | the bytes (attachment) |
| `GET /documents/{id}/text` | the extracted text and its pages, at most 5 MB |
| `PATCH /documents/{id}` `{name?, description?, folder?}` | rename, describe, move (you need `upload` on the target) |
| `PUT /documents/{id}/tags` `{tags: [slugs]}` | set the tags: the missing ones applied, the rest removed |
| `DELETE /documents/{id}` | to the trash → `204` |
| `POST /search` `{q, filters, page}` | keyword search over what you may see → `{rows, total, took_ms}` |
| `POST /graph/find` `{q, filters, limit}` | the document graph's find → `{status, rows, subjects}`; `503` when the engine is off |
| `POST /links` `{document \| folder, kind, label, days, allow_download, password}` | a link (or, `kind: request`, a file request) → `201 {link_id, url}` — the URL is shown **once** |

Filters for `/search`: `library`, `folder`, `owner`, `tag`, `kind`, `before`, `after`, `mine`, `shared_with_me`, `min_bytes`, `max_bytes` — the same as the search page.

## Examples

Each block below is run against the proof server by `tests/phase3/slice7/run.sh` (the variables are the proof's). Replace `$BASE` with your address and
`$KEY` with your key.

```sh
curl -sf "$BASE/api/v1/health"
curl -sf -H "Authorization: Bearer $KEY" "$BASE/api/v1/me"
curl -sf -H "Authorization: Bearer $KEY" "$BASE/api/v1/libraries"
curl -sf -H "Authorization: Bearer $KEY" "$BASE/api/v1/folders/$FOLDER?sort=updated&dir=desc"
curl -sf -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d "{\"parent\": $FOLDER, \"name\": \"Scans $STAMP\"}" "$BASE/api/v1/folders"
curl -sf -H "Authorization: Bearer $KEY" -F "folder=$FOLDER" -F "file=@$FILE" "$BASE/api/v1/upload"
curl -sf -H "Authorization: Bearer $KEY" "$BASE/api/v1/documents/$DOC"
curl -sf -H "Authorization: Bearer $KEY" -o /dev/null "$BASE/api/v1/documents/$DOC/download"
curl -sf -H "Authorization: Bearer $KEY" "$BASE/api/v1/documents/$DOC/text"
curl -sf -H "Authorization: Bearer $KEY" -X PATCH -H "Content-Type: application/json" -d '{"description": "Scanned in the mail room"}' "$BASE/api/v1/documents/$DOC"
curl -sf -H "Authorization: Bearer $KEY" -X PUT -H "Content-Type: application/json" -d '{"tags": ["scan", "mail-room"]}' "$BASE/api/v1/documents/$DOC/tags"
curl -sf -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d '{"q": "termination clause", "filters": {"kind": "pdf"}}' "$BASE/api/v1/search"
curl -sf -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d "{\"document\": $DOC, \"label\": \"For the auditor\", \"days\": 7, \"allow_download\": true}" "$BASE/api/v1/links"
curl -sf -H "Authorization: Bearer $KEY" -X DELETE "$BASE/api/v1/documents/$DOC"
```

The upload in parts, for a file over 64 MB (each part is the raw body, `parts_total` and `part_bytes` come from the first answer):

```text
curl -s -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d '{"folder": 12, "filename": "big.iso", "byte_size": 1073741824}' "$BASE/api/v1/upload/start"
  → {"ok": true, "session_id": 41, "part_bytes": 8388608, "parts_total": 128, …}
curl -s -H "Authorization: Bearer $KEY" -X PUT --data-binary @part-0001 "$BASE/api/v1/upload/41/1"        # repeat for 2 … 128; a repeat overwrites (resume)
curl -s -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -d '{"conflict": "version"}' "$BASE/api/v1/upload/41/finish"
```

## What a sibling application reads (K7)

A sibling application (Knowledge, Help Desk …) reads the folders **shared with it** — a folder's *Share* screen can name an application like a group — through the
kernel, never with a key: `folders_index`, `documents_index`, `document_text` and `document_link`. `document_link` answers a **signed ten-minute download URL**
(`/x/{document}/{version}/{expires}/{application}/{signature}`) for one document; the bytes never cross the kernel's JSON, and the open is logged under the
application. The admin sees which applications hold shares, and what they read, on *Admin → Connections*.
