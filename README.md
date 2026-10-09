# DocCloud

The MaluDB Business OS's document cloud — what Dropbox, Box and Nextcloud are to a business: libraries (personal, group, shared)
of folders and documents with versions, de-duplicated storage, tags, sharing to people, groups, departments, agents and the
outside (secure links and file requests), a keyword search over every document's words, and a dedicated knowledge graph for
finding documents, kept apart from what the OS's agents remember.

An application of the kernel [`maludb-os-core`](https://github.com/maludb/maludb-os-core), built with
[`maludb-os-htmx-php-guidelines`](https://github.com/maludb/maludb-os-htmx-php-guidelines) and fitted by
[`maludb-os-integration`](https://github.com/maludb/maludb-os-integration). Installed by the kernel's installer at
`/srv/apps/doccloud`, served as `doccloud.<domain>`, signed in by the kernel, reached by its agents through MCP.

- The plan: `docs/doccloud-design.md` (planned 2026-10-09; awaiting the owner's decisions).
- The rules for working here: `CLAUDE.md`.
- Catalog key `doccloud`; ports 8190 (Apache, internal), 8841 (records MCP), 8842 (activity MCP).
- On request, not a default install.
