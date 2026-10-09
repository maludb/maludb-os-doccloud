---
name: sharing-well
description: How to share in DocCloud — groups before people, departments for standing teams, the four levels and what each gives, inheritance down the tree and restrict for the sensitive folder, guests by name only, links and file requests that expire and pause for an agent, and how to explain who can see what and why.
---

# Sharing well

## The walls, in one breath
The owner always sees their own document. A library gives a base level (personal: the owner alone; group: its members at the
group's level; shared: everyone at the library's level). A folder inherits the nearest explicit rows above it; explicit rows
**add**; a **restrict** writes an explicit set without the inherited principals. A document may carry rows of its own. A guest
holds nothing by base. A link opens one thing. The admin sees everything, logged.

## The levels
| Level | Gives |
|---|---|
| `view` | see, preview, download, comment, follow |
| `upload` | add here; see and manage only what they own in this folder — a drop box, an inbox |
| `edit` | view's + add, new version, rename, move within what they may edit, tag, lock |
| `manage` | edit's + share, link, restrict, trash, restore, transfer ownership, settings |

## The order of choices
1. **A group before a person.** If three people need Contracts, that is a group (or an existing one: `find_groups`). A share to a
   group follows its membership; a share to three people is three rows nobody maintains.
2. **A department for a standing team.** `share_department` reaches its live members, and follows the directory as people move.
3. **A person for a person.** `share_member` for the one reviewer; `share_agent` for an agent that must file here.
4. **Restrict for the sensitive folder** (`folder_restrict`): the payroll folder inside the HR library keeps HR's managers only.
5. **A guest by name only** (`share_guest`) — pauses for an agent. Never `everyone` for a guest; never a department.
6. **A link or a file request last**, with an expiry (30 days by default) and, for a document that matters, a password; both
   pause for an agent. Never a link to a personal library's root.

## Before you share
- `access_list` — is it already shared? `access_explain` — why does Sam see it already?
- `my_level` — do I manage this? Only `manage` shares.
- Share the **folder**, not twenty documents; share the **smallest folder** that holds what they need.

## Explaining access
One sentence per principal, with the reason: "Finance sees Contracts at edit because the Finance library gives edit"; "Sam sees it
at manage because of a row on Contracts"; "Ann (a guest) sees only *NDA.pdf*, by a row on the document"; "the link *for the
auditor* opens the folder read-only until the 30th". `access_history` says who could see it on a given day (curators).
