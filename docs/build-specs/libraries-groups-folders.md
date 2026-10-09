# Build spec: libraries, groups and folders — THE CRUD EXEMPLAR (slice 1)

What exists at the end: a member opens **My files** and sees their personal library's root — folders and documents in one listing table,
crumbs above, the access summary line, the actions by their level; they make folders, rename, move (the record picker over folders they may
edit), colour them, favourite them; a curator makes **groups** (custom, or one that mirrors a department) and **shared libraries**; a group's
owners keep its members (the picker over people and agents) and its default level; every library has a view with its usage and its root;
Recent and Favourites are real. Every screen is a full-page form or a listing; every save is a handler on the kit's shape; every row is logged.
This slice fixes the shape every later CRUD screen copies: `require_folder_level()` / `require_document_level()` in `handler.php`, the listing
table's partial, the picker sources `folder`, `group`, `member`, `department`.

Schema (db/001–015, never modified): `group-list`, `group_members`, `library-list`, `folders`, `dc_member_group_ids()`, `dc_is_group_owner()`,
`dc_group_sync_department()`, `dc_folder_trash()`/`dc_folder_restore()` (slice 6 uses them), `dc_folder_subtree_ids()`, `dc_folder_path()`,
`dc_library_make_root()`, `dc_library_ensure_personal()` (db/006); the walls `dc_folder_level()`, `dc_can_see_folder()`, `dc_can_add_to_folder()`,
`dc_can_manage_folder()`, `dc_library_base_level()`, `dc_access_explain()` (db/008); `dc_folder_listing()`, `dc_my_libraries()`, `dc_recent_documents()`,
`dc_storage_by_library()`, `dc_quota_state()` (db/014); `favourites` (db/013); `mcp_libraries`, `mcp_groups`, `mcp_group_members`, `mcp_folders`,
`mcp_documents`, `mcp_favourites`, `mcp_members`, `mcp_departments` (db/015). **The database is the referee**: a folder's path and depth by
trigger, a name unique among live siblings, no move under itself or across libraries, a root never moved or trashed, one personal library per
member, one live group per department, a group's library made with it and following its name and archiving, the department group's members
following the directory — the handlers call the write inside `dc_guard()` and present a `P0001`/`23505`/`23514` as its sentence on the field.

## Divergences from the design
1. **A library's `everyone_level` is `library_everyone_set`** (design §5, `external_send`): on a shared library only; a group library has no
   everyone level (the group is the base). Setting it to `none` is free; raising it pauses for an agent.
2. **A group's name is its library's name** (the trigger copies it); the library view shows "named after the group".
3. **Deleting a group** (`group_delete`, `deletion`) is allowed only when its library holds no live document (the handler checks
   `mcp_libraries.used_bytes = 0 AND no live documents`); else "Archive it, or move its documents first" — the database would cascade.
   Archiving (`group_archive`) keeps everything (D6: the library survives its members).
4. **Deleting a library** (`library_delete`, `deletion`): shared libraries only, and only when empty; a personal library is never deleted; a
   group library goes with its group.
5. **The admin's look at a personal library** (`library.admin_view`): logged once per session per library, by `library_from_request()`.

## Who may do what
| Action | Right | Level / rule |
|---|---|---|
| folder create / rename / move / colour | `documents.upload` (create) · `documents.edit` | `upload`+ to create in the folder; `edit`+ on the folder to rename or colour; a move needs `edit`+ on the folder AND `upload`+ on the target |
| library (shared) create / update / archive / restore / delete / everyone level | `libraries.shared` | the maker or a curator; `manage` on the library |
| group create / update / archive / restore / delete | `groups.manage` | curators; an owner of the group may update, add and remove members and set the level without the right |
| group member add / remove / owner set / level set | `groups.manage` OR owner of the group | — |
| favourite add / remove | any admitted member | `view`+ on the thing |

## Screens (375 px first; a TABLE for a listing; cards for libraries and groups; full-page forms; no modals but the picker)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `folder` (My files) | `/files` | 302 to `/f/<root of my personal library>`; a guest → `/shared` |
| `folder` | `/f/{id}?sort=name|updated|size&dir=&kind=&page=` | crumbs (`dc_folder_path()`, every crumb a link), the name, the colour dot, **the access summary line** ("Board · 12 people · 1 group · restricted" from `folder_access` counts and `access_root_id`; "Only you" on a personal root), the actions by my level: New folder (`upload`+), Upload (`upload`+; slice 2's drop zone — until then a disabled button), Share · Get link · Request files (`manage`; slice 3), Rename · Move · Colour (`edit`+), Trash (`manage`; slice 6), Favourite; **the listing table** (`dc_folder_listing()`: folders first; columns name (link), kind icon, size, modified, owner, tags as chips, lock icon; sort by the headers; a kind filter as chips; multi-select checkboxes with a bar: Move · Favourite (Tag, Trash, Download zip come with slices 4, 6, 2); 100 a page; **at 375 the table is a list**: name + kind icon, size · modified below, the owner's initials); an empty folder shows "Nothing here yet" and the Upload button |
| `folder-add` / `folder-edit` | `/f/{id}/new` · `/f/{id}/edit` | name (autofocus), colour (eight swatches + none), auto-tags (slugs, comma-separated — slice 4 makes them real; the field exists and saves); on edit also "Move" as a link |
| `folder-move` | `/f/{id}/move` | a record picker over folders the caller may add to (source `folder`, param `library` optional), the current path shown; Move |
| `library-list` | `/libraries` | my libraries as cards (`dc_my_libraries()`: kind chip, name, my level chip, used of quota as a bar, the group's name, documents count, "open" to the root); **New shared library** for a holder of `libraries.shared`; an admin sees **every** library (`mcp_libraries`) with `admin view` `danger` on personal ones not theirs |
| `library-view` | `/libraries/{id}` | name, kind, description, the owner or the group (a link), everyone level (shared), graph policy chip (slice 5 edits it), quota state (`dc_quota_state()`), the root folder's listing link, members (a group's: `mcp_group_members`), Edit · Archive/Restore · Delete by rule; the timeline |
| `library-add` / `library-edit` | `/libraries/new` · `/libraries/{id}/edit` | shared libraries: name, description, everyone level (none · view · upload · edit, one line each), quota (admin only: `quotas.manage`, slice 6 — disabled here with "the admin sets it") |
| `group-list` | `/groups` | groups as cards (`mcp_groups`: name, kind chip custom `info` / department `secondary`, members count, "you own" `primary`, archived `dark`); **New group** for a holder of `groups.manage`; filters: mine, archived |
| `group-view` | `/groups/{id}` | name, description, kind, the mirrored department (a link to the kernel's directory), member level, the library (a link), the members table (`mcp_group_members`: name, role chip owner `primary`, joined; Make owner · Remove for owners/curators — not on a department group, whose members are the directory's: "members follow the department"), Add members (the picker over `members_for_pick()`, people and agents, never guests, multi), Edit · Archive/Restore · Delete |
| `group-add` / `group-edit` | `/groups/new` · `/groups/{id}/edit` | name, key (blank = from the name), description, kind (custom · from a department — a select of live departments without a live group), member level (view · edit) |
| `recent` | `/recent` | `dc_recent_documents(50)` as the listing table (name, folder crumb, kind, modified, last opened) |
| `favourites` | `/favourites` | folders then documents I starred (`mcp_favourites` joined), the listing table; Remove |

**The listing table partial** (`app/views/files/partials/listing.php`, used by `folder`, `recent`, `favourites`, slice 3's shared-with-me,
slice 4's search results, slice 6's trash): one row shape, a `rows` array of `dc_folder_listing()`'s columns, options `select`, `crumbs`,
`actions`. At 375 it renders as the list described above. Every name links to `/f/{id}` or `/d/{id}` with `?back=`.

## Making and changing
- **Folder create** (`folder_create`): `require_right('documents.upload')`, `require_folder_level($parent, 'upload')`; INSERT with
  `owner_member_id = me`, `created_by = me`; a duplicate name (`23505` on `folders_sibling_name_live`) → the name field "A folder of that name is
  already here." Location `/f/{id}`.
- **Rename** (`folder_rename`), **colour** (`folder_colour_set`), **auto-tags** (`folder_auto_tags_set`, slugs validated with `dc_slugify()` —
  stored now, applied by db/009's trigger): `require_folder_level($id, 'edit')`; `folder_auto_tags_set` logs `after.auto_tags`.
- **Move** (`folder_move`): `edit` on the folder, `upload` on the target; the database's sentences for under-itself, across libraries, depth and
  a root → the target field. Logged `folder.move` with `before.parent_id` / `after.parent_id`.
- **Library create** (`library_create`): `require_right('libraries.shared')`; kind `shared`, `owner_member_id = me`; the root by trigger.
  **Update** (`library_update`): name, description; **everyone level** (`library_everyone_set`, a separate action — `external_send` when raised);
  **archive / restore** (`library_archive`, `library_restore`); **delete** (`library_delete`: divergence 4; `deletion`).
- **Group create** (`group_create`): `require_right('groups.manage')`; kind custom → the maker becomes owner by trigger; kind department → the
  department's members arrive by trigger and the manager owns it; "one live group per department" (`23505` on `groups_department_live`) → the
  department field. **Update** (`group_update`: name, description, member level — `group_level_set` is the level alone so an owner may post it);
  **members** (`group_member_add`: a list of member ids through the picker, people and agents, never a guest — 422 "A guest is never in a group";
  `group_member_remove`: dates `left_at`, never deletes; `group_owner_set`: role owner/member; **refused on a department group** with "members follow
  the department"); **archive / restore / delete** (`group_archive`, `group_restore`, `group_delete` — divergence 3).
- **Favourites** (`favourite_add`, `favourite_remove`): a folder or a document the caller may see.

## Files (exactly these)
- `html/files/index.php` (`folder` (My files)) · `html/f/index.php` (`folder`, routed from `/f/{id}` by `tests/dev_router.php` and the vhost) · `html/f/form.php`
  (`folder-add` / `folder-edit`) · `move.php` (`folder-move`) · `save.php` · `rename.php` · `move-save.php` · `colour.php` · `auto-tags.php`
- `html/libraries/index.php` (`library-list`) · `view.php` (`library-view`) · `form.php` (`library-add` / `library-edit`) · `save.php` · `everyone.php` · `archive.php` ·
  `restore.php` · `delete.php`
- `html/groups/index.php` (`group-list`) · `view.php` (`group-view`) · `form.php` (`group-add` / `group-edit`) · `save.php` · `level.php` · `archive.php` · `restore.php` ·
  `delete.php` · `html/groups/members/{add,remove,owner}.php`
- `html/recent.php` (`recent`) · `html/favourites.php` (`favourites`) · `html/favourites/{add,remove}.php`
- `app/features/files/{queries,present,write,handler}.php` (`handler.php`: `folder_from_request()`, `document_from_request()`,
  `require_folder_level()`, `require_document_level()`, `library_from_request()` with the admin-view log, `files_log()` — `library_id`, `folder_id`,
  `document_id` on every row), `app/features/libraries/{queries,write}.php`, `app/features/groups/{queries,write}.php`
- `app/pickers.php` gains `folder` (folders I may add to; params `library`), `group`, `member` (people and agents, no guests), `department`
- `app/views/files/{folder,form,move,partials/listing,partials/crumbs,partials/access-line,partials/select-bar}.php`, `app/views/libraries/{index,view,form,partials/card}.php`,
  `app/views/groups/{index,view,form,partials/card,partials/member-row}.php`, `app/views/files/{recent,favourites}.php`
- `tests/phase3/slice1/{run.sh,lib.php,folders.php,libraries.php,groups.php,favourites.php,json.php,browser.mjs}`

## Query functions (signatures fixed; reads through `mcp_*` and the read functions only)
- `folder_listing(PDO, int $folderId, array $opts): array` (sort, dir, kind, page, per = 100) → rows + total · `folder_crumbs(PDO, int $folderId): array`
- `folder_access_line(PDO, int $folderId): string` · `find_folder(PDO, int $id): ?array` (`mcp_folders`) · `folders_for_pick(PDO, string $q, array $params, int $page): array`
- `my_libraries(PDO): array` · `find_library(PDO, int $id): ?array` · `all_libraries(PDO, array $filters): array` (admin) · `library_members(PDO, int $libraryId): array`
- `find_groups(PDO, array $filters): array` · `find_group(PDO, int $id): ?array` · `group_members(PDO, int $groupId): array` · `groups_for_pick()` · `departments_without_group(PDO): array`
- `recent_documents(PDO, int $limit = 50): array` · `my_favourites(PDO): array`
- writes (`write.php`): `folder_create(PDO, int $parentId, array $f, int $by): int` · `folder_rename` · `folder_move` · `folder_colour_set` · `folder_auto_tags_set` ·
  `library_create(PDO, array $f, int $by): int` · `library_update` · `library_everyone_set` · `library_archive` · `library_restore` · `library_delete` ·
  `group_create(PDO, array $f, int $by): int` · `group_update` · `group_level_set` · `group_member_add(PDO, int $groupId, array $memberIds, int $by): int` ·
  `group_member_remove` · `group_owner_set` · `group_archive` · `group_restore` · `group_delete` · `favourite_add` · `favourite_remove`

## MCP tools this slice makes real (Phase 4 serves them; the slice proves the SQL they read)
Also: `my_favourites`, `recent_documents` (the surface's rows this slice's screens read).
`find_libraries`, `get_library`, `folder_listing`, `get_folder`, `folder_path`, `find_groups`, `get_group`, `group_members`, `my_groups`,
`storage_by_library`, `quota_state`, `my_level`, `find_folders` (the resolver), `find_members`, `find_departments`.

## Manifest rows claimed
Screens (15): `library-list`, `library-view`, `library-add`, `library-edit`, `group-list`, `group-view`, `group-add`, `group-edit`, `group-members`, `folder`, `folder-add`, `folder-edit`, `folder-move`, `recent`, `favourites`
Actions (21): `library_create`, `library_update`, `library_everyone_set`, `library_delete`, `group_create`, `group_update`, `group_member_add`, `group_member_remove`, `group_owner_set`, `group_archive`, `group_restore`, `group_delete`, `folder_create`, `folder_rename`, `folder_update`, `folder_auto_tags_set`, `folder_move`, `folder_trash`, `folder_restore`, `favourite_add`, `favourite_remove`

Folded (the manifest's rule, one key per form): a folder's colour is a field of `folder_update`; a library's archive and restore are the `archived` field of `library_update`; a group's member level is a field of `group_update`. *My files* is the `folder` screen opened at the personal library's root (`/folders/{root}`, the nav item `/f/{root}`).

## Activity log events
`library.create|update|everyone_set|archive|restore|delete|admin_view`, `group.create|update|level_set|member_add|member_remove|owner_set|archive|restore|delete`,
`folder.create|rename|move|colour_set|auto_tags_set`, `favourite.add|remove`, `screen.view`.

## Status vocabulary
Level chip: manage `primary`, edit `success`, upload `info`, view `secondary`, none —. Kind chips as Phase 2. Ids: `listing-table`, `listing-row-{kind}-{id}`,
`folder-crumbs`, `folder-access-line`, `folder-new-btn`, `folder-upload-btn`, `select-bar`, `library-card-{id}`, `group-card-{id}`, `group-members-table`.

## Out of scope for this slice
Uploads, documents, versions (slice 2); sharing, links (3); tags' effect (4); the trash (6 — `folder_delete` is slice 6's with the trash screens).

## Proof (`tests/phase3/slice1/run.sh`: scratch database `dc_dev1`; the app on 8701, the fake kernel on 8702, the fake MaluDB on 8706;
members through `bin/dev_handoff.php`; curl with signed action and run tokens; Playwright at 375 × 740 and 1280 × 800; the registry `--check`)
1. My files 302s a member to their root; a guest to Shared; the root's listing is empty with "Nothing here yet".
2. A folder created, renamed, coloured; a duplicate name refused on the field (422 in words); a slash refused; the crumbs after a nested create.
3. A move with the picker: under itself refused; to another library refused; the subtree's crumbs follow; the picker's search pages and keeps the label on a 422.
4. The listing sorts by name, updated and size both ways; filters by kind; pages at 100; multi-select moves two folders; at 375 the list form with no sideways scroll.
5. A shared library made by a curator (everyone view): a member lists it and views its root; `library_everyone_set` to edit by an agent run token pauses (the fake kernel's approval); to none is free.
6. A member without `libraries.shared` refused 403 in words; a guest 403 on every create.
7. A custom group: the maker owns it; members added with the picker (a guest refused); a member removed is dated out; an owner set; the level set by an owner without the right; the library renamed with the group; archived → the member loses the library; restored → back.
8. A department group: the members are the department's; add/remove refused with "members follow the department"; a feed change through the fake kernel + `directory_sync` moves a member in and out; the manager owns it.
9. `group_delete` refused while documents exist (seed one through `dc_version_add`), allowed when empty; `library_delete` on a personal library refused.
10. The admin opens a member's personal library: `library.admin_view` logged once per session; a second open adds no row.
11. Favourites: a folder and a document starred, listed, removed; Recent lists a seeded document after `last_opened_at` is set.
12. JSON mode: every screen answers `data` with the presenter's whitelist; every handler reports `emit_action_status` and a create's `location` ends in the id; an agent's run token runs `folder_create` and `group_member_add` and is refused `library_delete` without approval.
13. The access line reads "Only you" on a personal root, "SMOKE Board · 3 people · 1 group" on a group root.
14. Browser at 375 and 1280: the folder page, the form, the move picker, the libraries cards, a group's members table; screenshots; no console errors.
15. Registry `--check`: 12 screens built, 24 actions built, the placeholders for My files/Groups/Libraries/Recent/Favourites removed, 2 approvals in step.

## Open questions
None.
