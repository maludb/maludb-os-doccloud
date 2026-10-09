# DocCloud Action Manifest

2026-10-09 · **Phase 1, for the checkpoint**

> The registry that the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against.
> A screen or action missing here cannot be reached by voice or by an agent. That is unfinished design, like a question with no tool.
> Pairs with `db/` (tables), `docs/doccloud-mcp-tool-surface.md` (read tools) and the ten slice specs in `docs/build-specs/`.
> `bin/build_action_registry.php` generates `mcp/action_registry.json` from this file. An action whose file does not exist yet is
> registered but not exposed. **The approval categories here ARE `maludb-os.json` `approvals[]`**: `php bin/build_action_registry.php
> --approvals` (or `bin/sync_approvals.php`) rewrites that block from this file, and `--check` (in every proof) fails when the registry
> or the approvals are stale. The installer reads the resolve block from `deploy/kernel-registry-doccloud.json`.

## Conventions

### Screens
- **Ids** are kebab-case: `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-doccloud.conf` (and `tests/dev_router.php`): `/{x}/new` → `form.php`,
  `/{x}/{id}/edit` → `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`. **A folder's page is
  `/folders/{id}` and a document's `/documents/{id}`; the short `/f/{id}` and `/d/{id}` of design §9 are rewrites**: `/f/{id}` → the folder
  page, and **`/d/{id}` is the download gate** (design §6.1: the bytes, `/d/{id}/v/{n}` a version, `/d/{id}/thumb` the thumbnail),
  not a page. Every id is an integer. The folder listing, the document page, the upload and the share screen are read at 375 px
  first; the graph explorer is designed at 1280 px and works at 375. No screen is a modal; the record picker is the one sanctioned modal.
- **Prefill params** become query-string values that the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity` and `data-record-id` on `#page-content`, so "this folder", "share it
  with the board" and "tag that" resolve against the page the user is on.
- **Doors that are not screens** have no row here: the download gate (`/d/{id}`), the upload routes (`/upload/start`, `/upload/part`,
  `/upload/finish` — the action is `document_upload`), the token API (`/api/v1/*`), the bridge (`/internal/bridge.php`, loopback only),
  the polling partials (`/folders/{id}/uploads-status`, `/graph/queue-status`, `/exports/{id}/status`), the picker (`/pick/`), the
  public doors `/s/{token}` and `/r/{token}` (listed below as screens because a person reaches them, but they take no session and
  no action token: their POSTs are `link_password` and `request_upload`, routes of slice 3, never actions), `/sso` and `/sso/logout`.

### Actions
- **Name** `{entity}_{verb}`. The **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `library_id`,
  `document_id` and `folder_id` on every row that concerns one). It is also what the kernel's approval policies match
  (`approval_policies.action_pattern` = the registry's `log_event`). So **an action that pauses for an agent has a log event no free
  action shares**: `access.share_guest` and `access.share_application` beside the free `access.share`; `link.make`, `link.rotate` and
  `link.revoke` beside `link.open`; `folder.delete` beside `folder.trash`; `document.purge` beside `document.trash`; `version.purge`
  beside `version.restore`; `library.everyone_set` beside `library.update`; `graph.policy_set`, `graph.budget_set`, `graph.model_set`,
  `graph.rebuild` beside `graph.retry`; `quota.set`, `retention.set`, `agents_settings.save` beside `settings.save`.
- **Sharing is one entity, `access`**: the share actions take a folder OR a document; their events are `access.share`, `access.share_guest`,
  `access.share_application`, `access.level_set`, `access.unshare`, `access.restrict` — the row carries `folder_id` or `document_id`
  (a divergence from design §6.4's `folder.share` / `document.share`, recorded: one event per action is what the kernel's hook needs).
- **Endpoints** are POST to `/{base}/{file}` with `dc_handler_begin()` (`require_post()`, `require_login()`, `verify_csrf()` or the relayed
  action token), the right (`require_right()`), the thing's level (`require_level()`: `none` → 404 — the same nothing for a thing that
  does not exist — a lower level → 403), `dc_guard()` around the database's verb, `log_activity()`, `emit_action_status()` and
  `dc_done()`. PHP never re-implements a rule the database keeps (a name unique among live siblings, a version numbered, a blob
  counted, a lock coherent, a link's expiry, a quota). A create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record accepts an id or a name, resolved through the records tool named in the resolve block of
  `deploy/kernel-registry-doccloud.json`: `document`, `file`, `folder`, `parent`, `target_folder`, `to_folder`, `library`, `group`,
  `tag`, `link`, `request`, `saved_search`, `proposal`, `api_key`, `member`, `person`, `owner`, `agent`, `guest`, `expert`, `clerk`,
  `new_owner`, `department`. `version`, `access` (an access row), `upload_session`, `dispatch`, `notification`, `token`, `note`,
  `graph_job`, `tag_proposal` are ids only. `any field of X` means X's fields, all optional: a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person; a right (`documents.upload`, `documents.edit`, `documents.share`,
  `links.make`, `requests.make`, `tags.apply`, `versions.restore`, `trash.own`, `export.own`, `groups.manage`, `libraries.shared`,
  `tags.manage`, `ownership.transfer`, `settings.manage`, `quotas.manage`, `retention.manage`, `graph.manage`, `links.all`, `trash.purge`,
  `agents.settings`) is held through `dc_has_right()`; **a level** (`add`, `edit`, `manage`) is the caller's level on the folder or
  document through `dc_folder_level()` / `dc_document_level()` (db/008): the owner manages their own; an `upload` holder adds and
  touches only their own. A super-admin holds every right and manages everything. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies. `—` means it cannot be undone, and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, it reaches people or systems outside, or it spends).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5): `external_send`
  (content reaches someone outside, or a system: a link, a file request, a share to a guest or an application, a shared library
  opened to everyone), `deletion` (purging, deleting a folder or a group or a library or a tag, revoking a link, transferring
  ownership), `other` (configuration and spend: the graph's policy, budget and model, rebuilding, settings, quotas, retention, the
  named agents). People are never paused. **Browsing, uploading, organising, tagging, searching and sharing inside the business are
  free.** The categories are exactly `maludb-os.json` `approvals[]`.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`). An
  action that pauses is still `yes`: the pause comes first, then the call.
- **record_id** is what the JSON answer's `record_id` names: the record made (a create) or the record acted on.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface's activity vocabulary. **A document's text, a passage, a
  description's words, a comment's words, more than 120 characters of a query, a token, a link, a password or a storage path are never
  in a payload** (ids, names, counts, kinds, sizes, hashes, statuses and error codes are).

### Result contract
```json
{"status": "success", "did": "Shared Contracts with the Board at edit", "record_id": 88, "undo_id": "act_31", "refresh": "accessChanged"}
{"status": "pending_approval", "did": "A link to \"acme-msa.pdf\" waits for a person's approval", "approval_request_id": 17}
{"status": "refused", "did": "The library is over its quota", "errors": {"file": "over the quota by 120 MB"}}
```

## Home, me and the shell (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the start: my libraries and groups as cards, recent documents, shared with me this week, open requests, links expiring, the Clerk's note; a new person's one button, Upload your first file |
| `shared-with-me` | `/shared` | what was shared with me, by whom, when (params: `kind`) |
| `recent` | `/recent` | what I opened or changed lately |
| `favourites` | `/favourites` | my favourites: documents and folders |
| `notifications` | `/notifications` | my notices, unread first |
| `activity` | `/activity` | my own trail here |
| `settings` | `/settings/` | my preferences: notification kinds and channels |
| `settings-tokens` | `/settings/tokens/` | my MCP tokens and my API keys: mint (shown once), rotate, revoke |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (the kinds that notify), text_kinds[] | restore prior | | | `settings.prefs` | own | yes | the prefs |
| `notification_read` | `/notifications/read.php` | notification (one — or none for all) | — | | | `notification.read` | own | yes | the notification |
| `notifications_read_all` | `/notifications/read-all.php` | all (yes — every unread notice of mine) | — | | | `notification.read_all` | own | yes | the count |
| `token_mint` | `tokens/mint.php` | **label** (up to 80 characters), expires_in_days (blank for never) | token_revoke | | | `token.mint` | own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own | people | the token |
| `key_mint` | `tokens/key-mint.php` | **label** (up to 80 characters), rate_per_minute (1 to 100000 — the setting by default), rate_per_day (1 to 10000000), expires_in_days | key_revoke | | | `key.mint` | own | people | the key |
| `key_rotate` | `tokens/key-rotate.php` | **api_key** (the old one keeps 24 hours) | — | ✔ | | `key.rotate` | own | people | the new key |
| `key_revoke` | `tokens/key-revoke.php` | **api_key** | — | ✔ | | `key.revoke` | own or links.all | people | the key |
| `favourite_add` | `/favourites/add.php` | document or folder (one of them) | favourite_remove | | | `favourite.add` | view | yes | the favourite |
| `favourite_remove` | `/favourites/remove.php` | document or folder (one of them) | favourite_add | | | `favourite.remove` | own | yes | the favourite |

## Libraries, groups and folders (slice 1)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `library-list` | `/libraries` | the libraries I reach as cards: kind, used of quota, policy (params: `kind`) |
| `library-view` | `/libraries/{id}` | a library at a glance: its root folder, counts, quota, policy, its group or owner |
| `library-add` | `/libraries/new` | to make a shared library: a name, who gets what by default (curators) |
| `library-edit` | `/libraries/{id}/edit` | to rename a shared library, set its description, its everyone level, its graph policy (params: `everyone_level`) |
| `group-list` | `/groups` | the groups as cards: members, owners, kind; mine marked (params: `q`, `mine`, `kind`) |
| `group-view` | `/groups/{id}` | one group: its members and owners, its library, its default level |
| `group-add` | `/groups/new` | to make a group: a name, custom or from a department, the default level (params: `department`) |
| `group-edit` | `/groups/{id}/edit` | to rename a group, set its description or default level |
| `group-members` | `/groups/{id}/members` | who is in a group: add people and agents with the picker, make an owner, remove |
| `folder` | `/folders/{id}` | a folder: crumbs, the listing table (sort, filter, grid for images), multi-select, upload, new folder, share, link, request, the access summary (params: `sort`, `dir`, `kind`, `q`) |
| `folder-add` | `/folders/new` | to make a folder here (params: `parent`) |
| `folder-edit` | `/folders/{id}/edit` | to rename a folder, set its colour or auto-tags |
| `folder-move` | `/folders/{id}/move` | to move a folder: the picker over folders I may edit |

Actions (base `/libraries/`, groups under `/groups/`, folders under `/folders/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `library_create` | `/libraries/save.php` | **name**, description, everyone_level (none or view or upload or edit — view by default), graph_policy (none or index or full — index by default), quota_mb | library_delete | | | `library.create` | libraries.shared | yes | the library |
| `library_update` | `/libraries/save.php` | **library**, name, description, graph_policy (none or index or full) | restore prior | | | `library.update` | libraries.shared on a shared library or the admin | yes | the library |
| `library_everyone_set` | `/libraries/everyone.php` | **library**, **everyone_level** (none or view or upload or edit — every member of the business) | restore prior | ✔ | external_send | `library.everyone_set` | libraries.shared on a shared library | yes | the library |
| `library_delete` | `/libraries/delete.php` | **library** (a shared or group library — its documents go to the trash first), **confirm_name** (the library's name typed) | — | ✔ | deletion | `library.delete` | libraries.shared or the admin | yes | the library |
| `group_create` | `/groups/save.php` | **name**, key (lower-case letters digits and dashes — from the name by default), description, kind (custom or department — custom by default), department (when kind is department), member_level (view or edit — edit by default) | group_archive | | | `group.create` | groups.manage | yes | the group |
| `group_update` | `/groups/save.php` | **group**, any field of group_create | restore prior | | | `group.update` | groups.manage or a group owner | yes | the group |
| `group_member_add` | `/groups/members/add.php` | **group**, **member** (a person or an agent — never a guest), role (member or owner — member by default) | group_member_remove | | | `group.member_add` | groups.manage or a group owner (a custom group only) | yes | the group |
| `group_member_remove` | `/groups/members/remove.php` | **group**, **member** | group_member_add | | | `group.member_remove` | groups.manage or a group owner (a custom group only) | yes | the group |
| `group_owner_set` | `/groups/members/owner.php` | **group**, **member**, owner (yes or no) | restore prior | | | `group.owner_set` | groups.manage or a group owner | yes | the group |
| `group_archive` | `/groups/archive.php` | **group** (its library closes to members — the admin and each owner still reach the documents) | group_restore | ✔ | | `group.archive` | groups.manage | yes | the group |
| `group_restore` | `/groups/restore.php` | **group** | group_archive | | | `group.restore` | groups.manage | yes | the group |
| `group_delete` | `/groups/delete.php` | **group** (an archived group — its library's documents are transferred to new_owner first), **new_owner**, **confirm_name** | — | ✔ | deletion | `group.delete` | groups.manage | yes | the group |
| `folder_create` | `/folders/save.php` | **name**, **parent** (the folder to make it in), colour (a hex colour), auto_tags[] | folder_trash | | | `folder.create` | add on the parent | yes | the folder |
| `folder_rename` | `/folders/save.php` | **folder**, **name** | restore prior | | | `folder.rename` | edit on the folder | yes | the folder |
| `folder_update` | `/folders/save.php` | **folder**, colour, description | restore prior | | | `folder.update` | edit on the folder | yes | the folder |
| `folder_auto_tags_set` | `/folders/auto-tags.php` | **folder**, auto_tags[] (slugs applied to every document added under it — empty to clear) | restore prior | | | `folder.auto_tags` | manage on the folder | yes | the folder |
| `folder_move` | `/folders/move.php` | **folder**, **target_folder** (in the same library) | folder_move | | | `folder.move` | edit on the folder and add on the target | yes | the folder |
| `folder_trash` | `/folders/trash.php` | **folder** (its subtree goes with it — 30 days to restore) | folder_restore | ✔ | | `folder.trash` | manage on the folder | yes | the folder |
| `folder_restore` | `/folders/restore.php` | **folder** | folder_trash | | | `folder.restore` | manage on the folder or trash.own | yes | the folder |

## Uploads, versions, serving and preview — THE FIRST EXEMPLAR (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `document` | `/documents/{id}` | a document: the preview, name, description, tags, owner, path, size, the versions with restore, the lock, who can see it and why, links, comments, related documents, the trail, download |
| `document-edit` | `/documents/{id}/edit` | to rename a document or write its description |
| `document-move` | `/documents/{id}/move` | to move a document: the picker over folders I may add to |
| `document-versions` | `/documents/{id}/versions` | every version: who, when, size, restore, purge (admin) |
| `upload` | `/folders/{id}/upload` | to drop files or folders here: the per-file progress, keep both on a conflict (params: `document`) |
| `exports` | `/exports` | my zips: the ones ready to download, the ones still being made, when each expires |

Actions (base `/documents/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `document_upload` | `upload.php` | **folder**, **file** (the bytes — one request up to 64 MB or an upload_session for more), name (the document's name — the file name by default), document (to add a version to this one), on_conflict (version or keep_both or refuse — version by default), note (up to 500 characters), tags[] | document_trash | | | `document.upload` | add on the folder (a version: edit on the document) | yes | the document |
| `document_rename` | `save.php` | **document**, **name** | restore prior | | | `document.rename` | edit on the document | yes | the document |
| `document_describe` | `save.php` | **document**, description (up to 4000 characters) | restore prior | | | `document.describe` | edit on the document | yes | the document |
| `document_move` | `move.php` | **document**, **target_folder** | document_move | | | `document.move` | edit on the document and add on the target | yes | the document |
| `document_copy` | `copy.php` | **document**, **target_folder**, name (the copy's name — the same by default) | document_trash | | | `document.copy` | view on the document and add on the target | yes | the copy |
| `version_restore` | `versions/restore.php` | **document**, **version** (becomes a new version from the old bytes) | version_restore | | | `version.restore` | versions.restore and edit on the document | yes | the new version |
| `version_purge` | `versions/purge.php` | **document**, **version** (never the current one) | — | ✔ | deletion | `version.purge` | trash.purge | yes | the version |
| `document_lock` | `lock.php` | **document** (24 hours — the setting) | document_unlock | | | `document.lock` | edit on the document | yes | the document |
| `document_unlock` | `unlock.php` | **document** (one's own lock — a manager breaks another's) | document_lock | | | `document.unlock` | own lock or manage on the document | yes | the document |
| `export_folder` | `/folders/export.php` | **folder** (a zip of its subtree — ready within the minute and kept 7 days) | — | | | `folder.export` | export.own and view on the folder | yes | the export |
| `export_documents` | `/documents/export.php` | documents[] (a zip of these) | — | | | `document.export` | export.own and view on each | yes | the export |

## Access, sharing, links and file requests (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `folder-share` | `/folders/{id}/share` | who can see a folder and why: the inherited rows greyed, the explicit rows, add a principal with the picker, the level, restrict |
| `document-share` | `/documents/{id}/share` | who can see one document and why; share it alone |
| `folder-links` | `/folders/{id}/links` | the links and file requests on a folder: make, rotate, revoke, the opens |
| `document-links` | `/documents/{id}/links` | the links on a document |
| `link-add` | `/links/new` | to make a link: expiry, download allowed, a password (params: `folder`, `document`) |
| `request-add` | `/requests/new` | to ask for files: a label, a message, an expiry (params: `folder`) |
| `my-shares` | `/shares/mine` | what I shared out, to whom; the stale ones to guests |
| `public-link` | `/s/{token}` | a link holder's view: a document to preview and download, or a folder's read-only listing with download as zip; the password page |
| `public-request` | `/r/{token}` | a file request's drop zone for whoever holds the link |
| `requests` | `/requests` | my file requests, open and closed, with the uploads each received (params: `status`) |
| `access-history` | `/folders/{id}/history` | who could see this folder (or `/documents/{id}/history`, this document) on a day, from the access trail (params: `at`) |

Actions (base `/access/`, links under `/links/`, requests under `/requests/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `share_member` | `/access/share.php` | folder or document (one of them), **member** (a person — never a guest), **level** (view or upload or edit or manage — upload on a folder only) | share_remove | | | `access.share` | documents.share and manage on the thing | yes | the access row |
| `share_group` | `/access/share-group.php` | folder or document (one of them), **group**, **level** | share_remove | | | `access.share` | documents.share and manage on the thing | yes | the access row |
| `share_department` | `/access/share-department.php` | folder or document (one of them), **department**, **level** | share_remove | | | `access.share` | documents.share and manage on the thing | yes | the access row |
| `share_agent` | `/access/share-agent.php` | folder or document (one of them), **agent** (an OS agent), **level** | share_remove | | | `access.share` | documents.share and manage on the thing | yes | the access row |
| `share_guest` | `/access/share-guest.php` | folder or document (one of them), **guest** (an external member holding Guest), **level** (view or edit) | share_remove | ✔ | external_send | `access.share_guest` | documents.share and manage on the thing | yes | the access row |
| `share_application` | `/access/share-application.php` | **folder** (a sibling reads folders — never one document), **application** (a catalog key such as knowledge), level (view) | share_remove | ✔ | external_send | `access.share_application` | documents.share and manage on the folder | yes | the access row |
| `share_level_set` | `/access/level.php` | **access** (the row), **level** (view or upload or edit or manage or none) | restore prior | | | `access.level_set` | manage on the thing | yes | the access row |
| `share_remove` | `/access/remove.php` | **access** (the row — never the library's own owner) | — | ✔ | | `access.unshare` | manage on the thing | yes | the access row |
| `folder_restrict` | `/access/restrict.php` | **folder**, keep[] (the rows to keep as kind:id:level — the inherited principals are dropped) | — | ✔ | | `access.restrict` | manage on the folder | yes | the folder |
| `link_make` | `/links/make.php` | folder or document (one of them), **label** (what the log calls it — up to 120 characters), expires_in_days (30 by default — 0 for never when the admin allows), allow_download (yes or no — yes by default), password (optional — never logged), note (shown to the holder) | link_revoke | ✔ | external_send | `link.make` | links.make and manage on the thing | yes | the link |
| `link_rotate` | `/links/rotate.php` | **link** (a new token — the old one dies now) | — | ✔ | external_send | `link.rotate` | own or manage on the thing | yes | the new link |
| `link_revoke` | `/links/revoke.php` | **link** | — | ✔ | deletion | `link.revoke` | own or manage on the thing or links.all | yes | the link |
| `request_make` | `/requests/make.php` | **folder** (the uploads land here owned by me), **label** (up to 120 characters), message (shown to the uploader — up to 1000 characters), expires_in_days (30 by default), password | request_close | ✔ | external_send | `request.make` | requests.make and add on the folder | yes | the request |
| `request_close` | `/requests/close.php` | **request** | — | | | `request.close` | own or manage on the folder | yes | the request |
| `link_update` | `/links/update.php` | **link**, label (up to 120 characters), expires_in_days (0 never — only when the admin allows), allow_download (yes or no), password (blank keeps it and the word none removes it), note | restore prior | | | `link.update` | own or manage on the thing | yes | the link |

## Tags, the keyword index and search (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `tag-list` | `/tags` | the tags as chips with counts: managed and free; create; merge; the proposals (params: `kind`, `q`) |
| `tag-view` | `/tags/{id}` | one tag: its documents, aliases, colour (params: `library`) |
| `tag-add` | `/tags/new` | to make a managed tag: label, colour (curators) |
| `tag-edit` | `/tags/{id}/edit` | to relabel or recolour a tag, or merge it into another |
| `tag-proposals` | `/tags/proposals` | the free tags proposed for the vocabulary, the merges and the Clerk's applications: accept or dismiss |
| `tag-rules` | `/tags/rules` | every folder that applies tags on upload (its auto-tags), to review and change (params: `library`) |
| `search` | `/search` | to search by keyword with filters as chips, excerpts, save this search, try the graph (params: `q`, `library`, `folder`, `owner`, `tag`, `kind`, `before`, `after`, `mine`, `shared_with_me`) |
| `saved-searches` | `/search/saved` | my saved searches and the shared ones; run one |

Actions (base `/tags/`, searches under `/search/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `tag_create` | `/tags/save.php` | **label** (up to 50 characters), kind (managed or free — managed needs tags.manage), colour (a hex colour) | tag_delete | | | `tag.create` | tags.apply (managed: tags.manage) | yes | the tag |
| `tag_update` | `/tags/save.php` | **tag**, label, colour | restore prior | | | `tag.update` | tags.manage | yes | the tag |
| `tag_apply` | `/tags/apply.php` | **document**, tags[] (slugs or labels — a missing one becomes a free tag) | tag_remove | | | `tag.apply` | tags.apply and edit on the document | yes | the document |
| `tag_remove` | `/tags/remove.php` | **document**, tags[] | tag_apply | | | `tag.remove` | tags.apply and edit on the document | yes | the document |
| `tag_merge` | `/tags/merge.php` | **tag** (the one that goes), **into** (the tag that stays — its slug becomes an alias) | — | ✔ | | `tag.merge` | tags.manage | yes | the surviving tag |
| `tag_delete` | `/tags/delete.php` | **tag** (removed from every document — archived with no alias) | — | ✔ | deletion | `tag.delete` | tags.manage | yes | the tag |
| `tag_propose` | `/tags/propose.php` | **document**, **tag** (a slug or label — the Clerk's proposal to apply it), note (why — up to 500 characters) | — | | | `tag.propose` | view on the document | yes | the proposal |
| `tag_proposal_decide` | `/tags/proposals/decide.php` | **tag_proposal**, **decision** (accept or dismiss) | — | | | `tag.decide` | tags.manage (an apply: edit on the document) | yes | the proposal |
| `tag_archive` | `/tags/archive.php` | **tag** (kept on its documents and hidden from the pickers) | tag_restore | | | `tag.archive` | tags.manage | people | the tag |
| `tag_restore` | `/tags/restore.php` | **tag** | tag_archive | | | `tag.restore` | tags.manage | people | the tag |
| `saved_search_save` | `/search/save.php` | **name** (up to 80 characters), query, filters (a JSON object of the search's filters), kind (keyword or graph — keyword by default), is_shared (yes or no), saved_search (to change an existing one) | saved_search_delete | | | `saved_search.save` | own | yes | the saved search |
| `saved_search_delete` | `/search/delete.php` | **saved_search** | — | | | `saved_search.delete` | own | yes | the saved search |

## The document graph — THE SECOND EXEMPLAR (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `graph` | `/graph/` | to find documents by the graph: the box, filters, results with the matched subjects and the best passage; the policy and the queue state in a footer (params: `q`, `library`, `folder`, `tag`, `kind`) |
| `graph-explore` | `/graph/explore` | to explore: subjects by degree for a library, a subject's neighbourhood, a path between two subjects (params: `library`, `subject`, `from`, `to`) |
| `subject-view` | `/graph/subjects/{id}` | one subject: its type, its documents I may see, its neighbours |
| `graph-admin` | `/graph/admin` | the queue, the estimate for a folder, the budget, the policy per library and folder, the model (changed with a pause), usage by month (params: `folder`, `month`) |
| `graph-path` | `/graph/path` | how two subjects connect: the path between them, with the documents on the way (params: `from`, `to`) |

Actions (base `/graph/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `graph_policy_set` | `policy.php` | library or folder (one of them), **graph_policy** (none or index or full — a folder may say inherit) | restore prior | ✔ | other | `graph.policy_set` | graph.manage | yes | the library or folder |
| `graph_budget_set` | `budget.php` | **graph_monthly_text_mb** (1 to 100000 — megabytes of text extracted by the model per month) | restore prior | ✔ | other | `graph.budget_set` | graph.manage | yes | the settings |
| `graph_model_set` | `model.php` | model_alias (the provider's model id), embedding_model, rebuild_confirmed (yes — required when the embedding model changes) | restore prior | ✔ | other | `graph.model_set` | graph.manage | yes | the settings |
| `graph_rebuild_all` | `rebuild.php` | library or folder (one of them — every document re-sent under its policy), **confirm** (yes) | — | ✔ | other | `graph.rebuild` | graph.manage | yes | the library or folder |
| `graph_job_retry` | `retry.php` | **graph_job** (a failed one) | — | | | `graph.retry` | graph.manage | yes | the job |
| `graph_job_skip` | `skip.php` | **graph_job** (a queued or failed one — the document is marked current so the catch-up stops offering it) | — | ✔ | | `graph.skip` | graph.manage | people | the job |
| `graph_test_extraction` | `test.php` | **document** (one — a dry run of the full prompt on its text through the engine; the model is paid once and nothing is kept) | — | | | `graph.test` | graph.manage | people | the document |

## The trash, locks, quotas and the worker (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `trash` | `/trash/` | my trash, or a folder's for its managers: restore, purge (admin), when each purges (params: `folder`) |
| `admin-quotas` | `/admin/quotas` | every library's quota and use; set a quota (params: `near`) |
| `admin-worker` | `/admin/worker` | the worker's passes: what each step did, the last error, the expiry windows (params: `limit`) |

Actions (base `/trash/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `document_trash` | `/documents/trash.php` | **document** (30 days to restore) | document_restore | | | `document.trash` | manage on the document | yes | the document |
| `document_restore` | `restore.php` | **document** (back to its folder — or the library root when that folder is gone — renamed when the name is taken) | document_trash | | | `document.restore` | own or trash.own on the folder | yes | the document |
| `document_purge` | `purge.php` | **document** (a trashed one — its versions and bytes released and the graph forgets it), **confirm** (yes) | — | ✔ | deletion | `document.purge` | trash.purge | yes | the document |
| `folder_delete` | `delete.php` | **folder** (a trashed folder with its subtree — purged now), **confirm_name** (the folder's name typed) | — | ✔ | deletion | `folder.delete` | trash.purge | yes | the folder |
| `trash_purge` | `purge-all.php` | older_than_days (1 to 3650 — the window by default), library, **confirm** (yes) | — | ✔ | deletion | `trash.purge` | trash.purge | yes | the settings |
| `quota_set` | `/admin/quotas/save.php` | **library**, quota_mb (blank for none) | restore prior | ✔ | other | `quota.set` | quotas.manage | yes | the library |
| `document_lock_break` | `/documents/lock-break.php` | **document** (another's lock) | — | ✔ | | `document.lock_break` | manage on the document | yes | the document |
| `worker_run` | `/admin/worker/run.php` | step (one pass by name — or blank for all) | — | | | `worker.run` | settings.manage | people | the pass |

## The API, keys and K7 (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `admin-keys` | `/admin/keys` | every API key of every person: limits, last use, today; revoke (params: `member`, `status`) |
| `admin-connections` | `/admin/connections` | which siblings may read which folders through the kernel: the application rows, the kernel's connections, the reads |

Actions (base `/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |

## Agents, the Clerk and notifications (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `proposals` | `/curate/proposals` | the Clerk's proposals for me (or every one for a curator): accept or dismiss (params: `kind`, `status`) |
| `admin-agents` | `/admin/agents` | the agents this application declares, which member is the expert and which the Clerk, their dispatches, retry |

Actions (base `/curate/proposals/`, agents under `/agents/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `clerk_propose` | `/agents/propose.php` | **kind** (untagged or duplicate or stale_share or link_expiring or link_suspicious or request_open or trash_due or locked or no_text or quota or budget or orphaned or tags), **title** (up to 200 characters), **for_member** (whose decision it is), document, folder, library, link, payload (a JSON object — the facts), note | — | | | `clerk.propose` | an agent (the Clerk) or agents.settings | yes | the proposal |
| `clerk_proposal_decide` | `/curate/proposals/decide.php` | **proposal**, **decision** (accept or dismiss), note (why) | — | | | `clerk.decide` | own (for me) or groups.manage | yes | the proposal |
| `notification` | `/agents/notify.php` | **member** (the recipient — or group for every owner of it), **title** (up to 200 characters), **body** (Markdown up to 4000 characters), kind (monday_note or proposal) | — | | | `notification.send` | an agent or agents.settings | yes | the notification |
| `dispatch_retry` | `/admin/agents/retry.php` | **dispatch** (a failed or refused one) | — | | | `agent.dispatch` | agents.settings | yes | the dispatch |
| `tags_propose_request` | `/agents/propose-tags.php` | **folder** (its untagged documents — the expert proposes tags from the graph) | — | | | `agent.dispatch` | edit on the folder | people | the dispatch |
| `agents_settings_save` | `/admin/agents/save.php` | expert (an agent member — empty for none), clerk (an agent member — empty for none) | restore prior | | other | `agents_settings.save` | agents.settings | yes | the settings |
| `dispatch_cancel` | `/admin/agents/cancel.php` | **dispatch** (a sent or awaiting one — nothing is sent again) | — | ✔ | | `agent.dispatch_cancel` | agents.settings | people | the dispatch |

## Home, curate, admin, reports and tokens (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `curate` | `/curate/` | the curate area: groups, shared libraries, tags and rules, ownership, the storage report, the search report, the Clerk's proposals |
| `curate-ownership` | `/curate/ownership` | a leaver's documents: who owns what, transfer them to someone (params: `member`) |
| `curate-storage` | `/curate/storage` | storage by library and by owner, duplicates (params: `library`) |
| `curate-search` | `/curate/search` | what people searched for that found nothing; the saved searches people share (params: `since`) |
| `admin` | `/admin/` | the admin area: settings, quotas, the graph, links, libraries, keys, agents, connections, the worker |
| `admin-settings` | `/admin/settings` | the ceilings, the windows (versions, trash, locks), link defaults, rates, the graph's defaults, each its own form |
| `admin-links` | `/admin/links` | every link and request: live, expiring, revoked, suspicious; revoke (params: `kind`, `status`) |
| `admin-libraries` | `/admin/libraries` | every library, the personal ones marked (opening one logs the admin's look) (params: `kind`, `archived`) |
| `admin-storage` | `/admin/storage` | the disk: blobs, de-duplication, freed bytes, the storage directory |

Actions (base `/admin/`, curate under `/curate/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `settings_save` | `/admin/settings/save.php` | business_name, max_document_bytes, max_text_bytes, upload_part_bytes, link_default_days (1 to 3650), link_no_expiry_allowed (yes or no), link_opens_per_minute, request_uploads_per_hour, key_rate_per_minute, key_rate_per_day, key_rotation_overlap_hours (0 to 168), free_tag_proposal_threshold (1 to 100), stale_guest_share_days, graph_default_policy (none or index or full), graph_text_chars, timezone | restore prior | | other | `settings.save` | settings.manage | yes | the settings |
| `retention_set` | `/admin/settings/retention.php` | versions_keep_days (1 to 3650), versions_keep_count (1 to 1000), trash_days (1 to 3650), lock_hours (1 to 720) | restore prior | ✔ | other | `retention.set` | retention.manage | yes | the settings |
| `ownership_transfer` | `/curate/ownership/transfer.php` | **owner** (whose documents), **new_owner** (an admitted member), library (only this library's — every one by default) | — | ✔ | deletion | `document.transfer` | ownership.transfer | yes | the new owner |
| `note_add` | `/notes/add.php` | document or folder (one of them), **body** (up to 4000 characters) | note_delete | | | `note.add` | view on the thing | yes | the note |
| `note_delete` | `/notes/delete.php` | **note** | — | | | `note.delete` | own or manage on the thing | yes | the note |
| `storage_export` | `/curate/storage/export.php` | report (storage or search), format (csv) | — | | | `report.export` | reports.storage or reports.search | yes | the file |
