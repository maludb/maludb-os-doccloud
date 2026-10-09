---
name: clean-up-duplicates
description: Runbook — the same bytes stored under two or more documents: decide which copy is the one to keep (the oldest in the most shared place, or the one the person names), propose the trash for the others or a move, never delete; explain that the bytes are stored once anyway so the cost is confusion, not disk. Run by the Clerk on Mondays or by the expert on request.
kind: runbook
---

# Clean up duplicates

**When**: `duplicates` finds the same sha256 under two or more documents, or a person asks. **Who runs it**: the Filing Clerk
(proposes) or the DocCloud Expert (proposes; moves or trashes only when the person says which).
**Writes**: `clerk_propose` kind `duplicate`; the expert's `document_trash` or `document_move` on the person's word.

## 1. Find them
`duplicates` (curators' tool; the expert gets it on a folder it manages): groups of documents with one sha256 — each with its
folder path, owner, tags, versions, shares, last opened.

## 2. What a duplicate costs
The bytes are stored **once** (a blob shared by all copies): the disk is not the problem. The problem is two trails, two sets of
tags and shares, and a person editing the wrong one. Say that; do not promise space back.

## 3. Which to keep
In this order, unless the person says: the copy in a **group or shared** library over a personal one; the copy with **more
shares, tags and versions**; the **older** one. A copy that is the only one its folder's readers can see is not a duplicate to
them — note it and keep it.

## 4. The proposal
`clerk_propose` kind `duplicate`: the group, the copy to keep, the copies to trash (or to replace by a share of the kept one),
the reason. One proposal per group of copies. Do not repeat an open or dismissed one (`clerk_proposals`).

## 5. On the person's word (the expert only)
`document_trash` the copies they name (30 days to restore); or `document_move` when the two are really one document in the wrong
place. Then `share_member`/`share_group` the kept one with whoever lost a copy, so nobody loses access. Report what changed.
