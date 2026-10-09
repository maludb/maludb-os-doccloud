---
name: file-an-incoming-document
description: Runbook for the expert — a person hands over a document (pasted text, or one that just arrived in an inbox folder or by file request) and asks for it to be filed: decide the folder, the name, the tags and who should see it; upload or move; report. Run when asked to file, put away, or sort documents.
kind: runbook
---

# File an incoming document

**When**: a person asks you to file, put away or sort one or several documents. **Who runs it**: the DocCloud Expert.
**Writes**: `document_upload` (text), `document_move`, `document_rename`, `document_describe`, `tag_apply`, `folder_create`;
shares only inside the business.

## 1. What is it
- If it already exists (an inbox folder, a file request's folder): `get_document`, `document_excerpt` for the first lines,
  `document_subjects` when the folder is in the graph. If it is text the person pasted: it becomes `document_upload`.
- Name the kind in one phrase: an invoice from Acme for March, a signed NDA, the board deck. Ask one question if you cannot.

## 2. Where it goes
- The person's words first ("into Contracts"). Else the folder whose auto-tags and name fit: `find_documents` for siblings of
  the same kind ("where do Acme invoices live?"), `folder_path` to confirm. Else propose a folder and ask.
- Never into a personal library but the asker's own; never into a folder the asker cannot edit (`my_level`).
- A new folder (`folder_create`) only when the person says so or the pattern is clear (a new client, a new year).

## 3. The name and the description
- `document_rename` to the house pattern you see in the folder (`Acme — Invoice 2026-03.pdf`). Keep the extension.
- `document_describe` in one line: what it is, from whom, the date — the words a search will need.

## 4. The tags
- Apply what the folder's siblings carry (`folder_listing` shows their tags), managed tags first (`find_tags`); add the sibling
  record tag when it came from one. Skill `tagging`.

## 5. Who should see it
- Usually the folder decides. If the person names someone: `share_member`/`share_group`/`share_department` on the document,
  not the folder, unless they say the folder. A guest or a link pauses — say so, draft it only when asked.

## 6. Report
One line per document: the new name, the path, the tags, who sees it. A document you could not place: say why and where it is now.
