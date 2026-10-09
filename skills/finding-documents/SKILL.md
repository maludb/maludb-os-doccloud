---
name: finding-documents
description: How to find a document in DocCloud — by place and name first, then tags, then keyword search, then the document graph only when asked; how to read a search result and a graph result; when to say nothing was found and what to suggest.
---

# Finding documents

## The order
1. **Place and name.** A person usually knows roughly where. `find_documents` with the name (trigram: misspellings match), the
   owner, the kind, the dates, "mine", "shared with me", "recent", "favourites"; `folder_listing` of the folder they name;
   `folder_path` to say where a hit lives.
2. **Tags.** `documents_by_tag` for a tag they name; `find_tags` to find the tag's slug when they use a near word.
3. **Keyword.** `search` with the words and the filters (library, folder subtree, owner, tag, kind, size, dates). It reads the
   title (highest weight), the tags and description, then the first 200,000 characters of the extracted text. Phrases in quotes,
   `-word` excludes, `OR` alternates. Every hit carries an excerpt with the match marked.
4. **The graph, when asked.** `graph_find` for "documents about X" or "near this phrase" when the person asks for the graph, for
   related documents, or says the keyword search found the wrong thing; `graph_related` for "documents like this one". A graph
   result names the subjects that matched and the best passage. The graph knows only documents under an `index` or `full`
   policy; a folder under `none` is not in it — say so when it matters.

## Reading a result
- A document is its name, its folder path, its owner, its kind and size, when it changed, its tags. Give those.
- An excerpt shows why it matched; quote it as the search returned it. Do not open the document to read more unless the person
  asks for a page (`document_page`) or the words around a match (`document_excerpt`).
- Several hits: list the five best with their paths; offer to narrow by folder, owner, date or tag.
- A document in the trash is found only by its owner or the folder's manager (`trash`).

## When nothing is found
Say what you searched (the words, the filters, the libraries you may see) and suggest: a different word, a wider folder, the owner,
or the graph. Never guess a name. If the person expected a document that may be in a library you cannot see, say that the search
covered only what you may see and name who can look wider (a curator, the admin).

## What you never do
Answer a question from a document's words. Say "the contract ends in March" — say "the contract is *Acme MSA 2026.pdf* in
Finance › Contracts; its text mentions a term; Knowledge answers questions from it when the folder is shared there".
