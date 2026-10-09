---
name: tagging
description: Tags in DocCloud — the managed vocabulary and free tags, slugs and labels, aliases after a merge, folder auto-tag rules, proposals (a free tag used by three people; the Clerk's from the graph's subjects), which tag to apply and when a tag is the wrong tool.
---

# Tagging

## Two kinds, one list
- **Managed** tags are the vocabulary curators keep (`tags.manage`): `invoice`, `contract`, `policy`, `2026`, `client:acme`.
  They have a colour and a stable slug; a merge rewrites every document and keeps the old slug as an alias.
- **Free** tags are anyone's (`tags.apply`): a word a person needed. A free tag used by three people becomes a **proposal** for
  the curator to adopt into the vocabulary (`tag_proposals`).
- A slug is lowercase, `a-z0-9_-`, at most fifty characters; the label is what people see. `find_tags` finds a tag by a near word.

## Applying
- `tag_apply` on a document the asker may edit; `tag_remove` to take one off. Several tags at once are fine.
- A folder's **auto-tag rules** (its `auto_tags`) apply to every document added under it — the right place for "everything in
  Invoices is `invoice`". Prefer a rule to tagging by hand when the folder *is* the category.
- A document that came from a file request carries `request:<label>`; one that a sibling sent carries its record
  (`helpdesk:ticket:412`). Keep those; they are how the trail is followed.

## Proposing
- The Clerk and the expert propose tags for untagged documents from the graph's subjects (`document_subjects` → `tag_propose`;
  runbook `propose-tags-from-the-graph`). A proposal names the document, the tag and why; the owner or an editor accepts it.
- Propose a managed tag when one fits; a free tag only when none does, with the same slug rules.

## When a tag is the wrong tool
- A **place** is a folder, not a tag ("Finance", "2026 › Q3").
- **Who may see it** is a share, never a tag.
- A **property** with a value (a contract's end date) is a description line in v1, a metadata template later — not a tag.
- A tag for one document that nobody will search by is noise; skip it.
