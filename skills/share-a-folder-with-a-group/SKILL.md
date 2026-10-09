---
name: share-a-folder-with-a-group
description: Runbook for the expert — a person asks for a folder to be shared with a team: find or name the group, check what is shared already, choose the level, share the smallest folder that holds what they need, confirm with "who can see it now". Run when asked to share, give access, or let a team see a folder.
kind: runbook
---

# Share a folder with a group

**When**: a person asks you to share a folder (or a document) with a team, a group, a department or a person. **Who runs it**:
the DocCloud Expert. **Writes**: `share_group`, `share_department`, `share_member`, `share_agent`. A guest or an application
pauses; a link or a file request pauses.

## 1. What, exactly
- The folder: `find_documents`/`folder_path` if they named a document — share its folder only if they said the folder; else the
  document (`share_member` on the document).
- `my_level` on it: only `manage` shares. If you lack it, say who manages it (`access_list`).

## 2. With whom
- A team they name: `find_groups` (a custom group or a department mirror), else `find_departments`. If no group exists for a set
  of people who will need this again, say so — a curator makes groups (`groups.manage`); do not share with each person instead
  without saying why.
- A person: `find_members`. An external shows as a guest — that share pauses.

## 3. Already shared?
`access_list` on the folder: the inherited rows and the explicit ones. If the group already sees it at that level or higher by
inheritance, say so and stop. If the folder is restricted, say that a share here adds to the restricted set.

## 4. The level
Ask only if the person did not say: `view` to read, `edit` to add and change, `upload` for a drop box, `manage` to share onward.
Default `view` for a department, `edit` for a group that works in it.

## 5. Share
`share_group` (or `share_department`, `share_member`) with the level. Share the smallest folder that holds what they need.

## 6. Confirm
`access_explain` for the group: "Finance now sees *Contracts › 2026* at edit (a row on 2026)". Name who else sees it by inheritance
if that is a surprise. Skill `sharing-well`.
