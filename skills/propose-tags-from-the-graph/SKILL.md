---
name: propose-tags-from-the-graph
description: Runbook — for the untagged documents of a folder, read the subjects the document graph extracted (people, organisations, kinds, dates) and the tags their folder siblings carry, and propose tags for an owner or editor to accept; one proposal per document, or one per folder when many. Run by the expert on request and by the Clerk on Mondays.
kind: runbook
---

# Propose tags from the graph

**When**: the expert is asked ("tag what arrived in Contracts"), or the Clerk's Monday duty finds untagged documents. **Who runs
it**: the DocCloud Expert or the Filing Clerk. **Writes**: `tag_propose` (a proposal, never the tag itself).

## 1. The untagged
`untagged` for the folder (or the library). Skip documents uploaded in the last seven days unless the person asked for them.
Group by folder.

## 2. What the graph knows
- For each document in a folder whose policy is `index` or `full`: `document_subjects` — the subjects on its edges. Under
  `index` those are its known facts (folder, owner, kind, year, the sibling record); under `full` the extracted ones too
  (organisations, people, products, topics, dates).
- `graph_subjects` for the library: the most connected subjects are the vocabulary people actually use.
- A folder under `none` has no subjects: propose from the siblings' tags only, and say so.

## 3. What the siblings carry
`folder_listing` of the folder: the tags most of its documents share are the folder's working vocabulary; the folder's
`auto_tags` should already cover them — if an auto-tag rule would do the job, propose the rule to the curator instead of fifty tags.

## 4. The proposal
- Managed tags first (`find_tags` by the subject's name; an alias matches). A free tag only when no managed one fits.
- `tag_propose` per document: the tag slug, the reason in one phrase ("names Acme Corp in three passages; the folder's siblings
  carry `client:acme`"). When a folder has more than twenty untagged, one `clerk_propose` of kind `untagged` for the folder with
  the suggested tags per document in its payload.
- Never propose a tag that is a place, a permission or a value with a number; never from a document's sensitive words (a
  person's name in a medical file). Skill `tagging`.

## 5. Report
How many documents, how many proposals, which folder would be served by an auto-tag rule.
