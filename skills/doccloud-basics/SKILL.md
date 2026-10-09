---
name: doccloud-basics
description: What DocCloud is and how an agent works in it — what a library, folder, document, version, blob, tag, share, link, file request, lock and the trash are; the ownership rule; which tool answers which question; never link out without the asker, never answer from a document's words. The one page every agent that uses DocCloud reads first.
---

# DocCloud — the basics for an agent

DocCloud holds the business's **files**: libraries of folders and documents, every version kept, shared by rule, found by
keyword and — on request — by the document graph. You are a member here: you see what your member id may see, and a few
actions pause for a person.

## The words
- **Library** — a root. *Personal* (one per member, theirs alone until shared), *group* (one per group, its members' at the
  group's level; it outlives its members), *shared* (business-wide, made by a curator; everyone at its base level).
- **Folder** — a node of a library's tree. Its access is inherited from the nearest folder above it with explicit rows, else
  the library's base. A **restrict** cuts the inheritance and writes an explicit set.
- **Document** — one file with an owner, a folder, a name, a type, a size, a sha256, **versions**, tags, a description, a lock,
  shares, links and a trail. The document is the record; its bytes are a **blob** (stored once, shared by identical files).
- **Version** — one upload of a same-named document. All kept 30 days, then the last ten, the current always. A restore makes
  a new version from an old blob.
- **Tag** — a label: managed (the vocabulary, curators') or free (anyone's). A folder's auto-tag rules apply on upload.
- **Share** — an access row on a folder or a document naming a principal: a member, a group, a department, an agent, a guest,
  an application, everyone. Levels `view`, `upload` (adds, sees only their own), `edit`, `manage`.
- **Link** — a token that opens one folder (read-only) or one document to whoever holds it; expires (30 days by default); may
  carry a password. **File request** — a link that only uploads into one folder; the uploader sees nothing.
- **Lock** — an edit claim for 24 hours; a manager breaks it.
- **Trash** — where a deleted document waits 30 days; then the worker purges it and the graph forgets it.

## The ownership rule
The owner always sees and manages their own document, wherever it is. An `upload` holder sees only what they own in that folder.
A guest sees only what names them. A link holder sees one thing. The admin sees everything, logged.

## Which tool answers what
| You need | Tool |
|---|---|
| Which libraries may I reach; one library | `find_libraries`, `get_library` |
| What is in a folder; a folder; its path | `folder_listing`, `get_folder`, `folder_path` |
| Groups, one group, its members, mine | `find_groups`, `get_group`, `group_members`, `my_groups` |
| One document; find documents by name, tag, owner, kind, dates, place, mine, shared with me | `get_document`, `find_documents` |
| Versions; the extracted text, a page, an excerpt | `document_versions`, `document_text`, `document_page`, `document_excerpt` |
| Keyword search with excerpts (the search box) | `search` |
| Documents about a subject, near a phrase; like this one (ON REQUEST) | `graph_find`, `graph_related` |
| A document's subjects; a library's most connected; a subject; a path | `document_subjects`, `graph_subjects`, `get_subject`, `subject_neighbors`, `graph_path` |
| Who can see this and why; my level | `access_list`, `access_explain`, `my_level` |
| Shared with me; what I shared; old guest shares | `shared_with_me`, `my_shares`, `stale_guest_shares` |
| Links and requests | `find_links`, `links_expiring`, `links_suspicious`, `find_requests` |
| Tags, proposals, documents by tag, untagged | `find_tags`, `tag_proposals`, `documents_by_tag`, `untagged` |
| Locks; the trash | `locked_documents`, `trash`, `trash_due` |
| Who changed what | `record_history`, `recent_activity`, `actor_timeline` |

## The rules
1. **Say where, not what.** Name the document, its folder path, its owner, when it changed. Quote the search's excerpt to show the
   match. **Never answer a question from a document's content** — that is Knowledge's; name it.
2. **Keyword first, the graph on request.** `search` is free and instant; `graph_find` costs more and is run when asked.
3. **Scope to what the asker may see.** The tools already filter; never suggest a hidden document exists.
4. **Alone**: file, rename, move, copy, describe, tag, share inside the business, trash one document, restore, lock, unlock
   your own, save a search. **Pauses**: a link, a file request, a share to a guest or an application, every purge or delete,
   ownership transfer, the graph policy, budget or model, quotas, retention, the settings. Never ask for these unprompted.
5. **No secrets** in a document, a description, a tag, a note or a message; never a link's token or a password.
