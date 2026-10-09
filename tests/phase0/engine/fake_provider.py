"""A FAKE OpenAI-compatible model provider for the DocCloud document-graph proof (tests/phase0/engine.sh). Synthetic, deterministic.

    python fake_provider.py <port>

POST /v1/chat/completions answers two contracts, told apart by the prompt:
  * MaluDB M1's answer contract (system prompt carries "You answer a question from EVIDENCE"):
      {"sufficient", "answer_md", "citations": [{"n", "quote"}]} — the quote is copied verbatim from the evidence item that
      shares the most words with the question; "FABRICATE" in the question cites words found in no evidence;
      "NOEVIDENCE" answers sufficient=false.
  * /v1/memory/documents' candidate_edges contract (the namespace prompt_template; the user message carries
      "candidate_edges"): one edge per sentence of the text after the template's last "Text:" line. The subject is the
      sentence's leading Capitalised Words, the verb the next word; the span is the sentence verbatim. The compiled
      guideline lines the Knowledge application writes into the template are obeyed the way a model would obey them:
        SUBJECT TYPE: <type>        every subject gets this type
        ALIAS: <alias> => <name>    a subject written as the alias is extracted under the canonical name
        EXCLUDE: <word>             a sentence containing the word is not extracted
  * "FAILPROVIDER" anywhere in the request answers HTTP 500; "BADJSON" in the text answers prose that is not JSON.
GET  /calls    {"extract": n, "answer": n, "other": n}      POST /reset   zero the counts and forget the last requests
GET  /last     {"answer": {...}, "extract": {...}}          the last request of each kind (system + user text)
ANY  /hang/... sleeps 10 s before answering (the module's timeout proof)
"""
import asyncio
import json
import re
import sys

import uvicorn
from starlette.applications import Starlette
from starlette.requests import Request
from starlette.responses import JSONResponse, PlainTextResponse
from starlette.routing import Route

CALLS = {"extract": 0, "answer": 0, "other": 0}
LAST: dict = {}
STOP = set("a an the is are be of for to in on at by and or what which who how long does do it its this that with from "
           "as was were has have must should can may under".split())
SUBJ = re.compile(r"^((?:[A-Z][A-Za-z0-9-]*)(?:\s+[A-Z][A-Za-z0-9-]*)*)\s+([a-z][a-z-]*)")


def words(text: str) -> set:
    return {w for w in re.findall(r"[a-z0-9-]+", text.lower()) if w not in STOP and len(w) > 2}


def completion(content: str, usage=(120, 30)) -> JSONResponse:
    return JSONResponse({"id": "fake", "object": "chat.completion",
                         "choices": [{"index": 0, "message": {"role": "assistant", "content": content}}],
                         "usage": {"prompt_tokens": usage[0], "completion_tokens": usage[1]}})


def answer(system: str, user: str) -> JSONResponse:
    q = re.search(r"QUESTION:\n(.+?)(?:\n\n|$)", user, re.S)
    question = q.group(1).strip() if q else ""
    items = re.findall(r"^\[(\d+)\][^\n]*\n(.+?)(?=\n\n\[\d+\]|\n\nYOUR PREVIOUS|\Z)", user.split("EVIDENCE:", 1)[-1], re.S | re.M)
    if not items:
        return completion(json.dumps({"sufficient": False, "answer_md": "", "citations": []}))
    qw = words(question)
    n, text = max(items, key=lambda it: (len(qw & words(it[1])), -int(it[0])))
    sentence = re.split(r"(?<=[.!?])\s+", text.strip())[0]
    quote = sentence[:160]
    if "FABRICATE" in question:
        quote = "the device is guaranteed for one hundred and seven years"
    out = {"sufficient": "NOEVIDENCE" not in question, "answer_md": f"According to the sources, {quote.rstrip('.')} [{n}].",
           "citations": [{"n": int(n), "quote": quote}]}
    return completion(json.dumps(out), (321, 45))


def extract(user: str) -> JSONResponse:
    text = user.rsplit("Text:\n", 1)[-1]
    if "BADJSON" in text:
        return completion("I am sorry, I cannot produce JSON today.")
    stype = re.search(r"^SUBJECT TYPE:\s*(\S+)", user, re.M)
    aliases = {a.strip(): c.strip() for a, c in re.findall(r"^ALIAS:\s*(.+?)\s*=>\s*(.+?)\s*$", user, re.M)}
    excludes = [w.strip().lower() for w in re.findall(r"^EXCLUDE:\s*(.+?)\s*$", user, re.M)]
    edges = []
    for sentence in re.split(r"(?<=[.!?])\s+", text.strip()):
        s = sentence.strip()
        if not s or any(x in s.lower() for x in excludes):
            continue
        m = SUBJ.match(s)
        if not m:
            continue
        subject = m.group(1).strip()
        for alias, canonical in aliases.items():
            if subject == alias or subject.startswith(alias + " "):
                subject = canonical
        edges.append({"subject_text": subject, "subject_type": stype.group(1) if stype else "other",
                      "verb_text": m.group(2), "predicate": [{"attr_name": "detail", "value_text": s[:200]}],
                      "source_span": s, "confidence": 0.9})
    return completion(json.dumps({"candidate_edges": edges}), (200, 60))


async def chat(request: Request):
    body = await request.json()
    msgs = body.get("messages") or []
    system = "\n".join(m.get("content", "") for m in msgs if m.get("role") == "system")
    user = "\n".join(m.get("content", "") for m in msgs if m.get("role") == "user")
    raw = json.dumps(body)
    if "You answer a question from EVIDENCE" in system:
        kind = "answer"
    elif "candidate_edges" in user:
        kind = "extract"
    else:
        kind = "other"
    CALLS[kind] += 1
    LAST[kind] = {"system": system, "user": user, "model": body.get("model"),
                  "authorization": "present" if request.headers.get("authorization") else "absent"}
    if "FAILPROVIDER" in raw:
        return PlainTextResponse("provider exploded", status_code=500)
    if kind == "answer":
        return answer(system, user)
    if kind == "extract":
        return extract(user)
    return completion(json.dumps({"ok": True}))


async def calls(_request):
    return JSONResponse(CALLS)


async def last(_request):
    return JSONResponse(LAST)


async def reset(_request):
    for k in CALLS:
        CALLS[k] = 0
    LAST.clear()
    return JSONResponse({"reset": True})


async def hang(_request):
    await asyncio.sleep(10)
    return JSONResponse({"late": True})


async def health(_request):
    return JSONResponse({"status": "ok", "fake": True})


app = Starlette(routes=[
    Route("/v1/chat/completions", chat, methods=["POST"]),
    Route("/calls", calls), Route("/last", last), Route("/reset", reset, methods=["POST"]),
    Route("/health", health),
    Route("/hang/{rest:path}", hang, methods=["GET", "POST", "PUT", "DELETE", "PATCH"]),
])

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=int(sys.argv[1] if len(sys.argv) > 1 else 8704), log_level="warning")
