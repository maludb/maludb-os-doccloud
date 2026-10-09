---
name: monday-store-report
description: Runbook for the Clerk's Monday duty — the store report (quotas, failed extractions, stale locks, the trash due, the graph budget, orphaned documents), the order report (untagged, duplicates) and the sharing report (stale guest shares, links expiring or suspicious, open requests); each finding a proposal, one note per group's owners and one to the admin's assistant. Run every Monday 06:50 or when an admin asks.
kind: runbook
---

# The Monday store report

**When**: every Monday 06:50 (the duty), or when an admin asks. **Who runs it**: the Filing Clerk.
**Writes**: proposals (`clerk_propose`, `tag_propose`), one note per group (`notification`), one message to the admin's assistant.

## 1. The libraries
`find_libraries`, `storage_by_library`. Work library by library; a personal library is reported to its owner only.

## 2. The store report
- `quota_state`: libraries over 80 percent of their quota.
- `extraction_failures`: documents with `failed`, `no_text`, `unsupported` — count by folder; name the kinds.
- `locked_documents`: locks older than a day, with the holder.
- `trash_due`: documents purging this week, by owner.
- `graph_usage`: the month's text against the budget; flag at 80 percent; `full` folders queued but not indexed.
- Orphaned: `find_documents` owned by members the directory marks inactive (`find_members`).

## 3. The order report
- `untagged` by folder (skip the last seven days) → runbook `propose-tags-from-the-graph`.
- `duplicates`: the same sha256 in two places → runbook `clean-up-duplicates`.

## 4. The sharing report
- `stale_guest_shares`: rows naming a guest older than 90 days, with the folder and who shared.
- `links_expiring` this week (only those opened this month are worth a line); `links_suspicious`: opened from many addresses.
- `find_requests` still open, with uploads so far.

## 5. Proposals
`clerk_propose` once per finding or cluster: the kind (`untagged`, `duplicate`, `stale_share`, `link_expiring`,
`link_suspicious`, `request_open`, `trash_due`, `locked`, `no_text`, `quota`, `budget`, `orphaned`), the evidence (ids and
counts; never a document's text), the suggested hand ("transfer Lee's 41 documents to the Design group"). First look at
`clerk_proposals`: do not repeat an open or dismissed proposal unless the facts changed.

## 6. The note
One note per group to its owners (`notification`): the counts, the five findings that matter most, how many proposals are new
and how many still open; one line for a library in order. Then one message to the admin's assistant (`message_send`) with every
library in a table: library, used of quota, failed extractions, untagged, duplicates, stale shares, open proposals.

## 7. Check yourself
You deleted nothing, linked nothing, shared nothing, spent nothing. Every finding names its record. The note reads in two minutes.
