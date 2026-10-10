"""Shared pieces for DocCloud's tool modules: annotations, the input bases, the where-builder, the refusals (errors are answers, not exceptions — surface "Errors"), the id-or-name resolvers
(through the mcp_* views, so a thing the caller may not see does not exist for them), the plain-list answer the kernel's entity resolver reads, and THE BRIDGE: the signed loopback post to
html/internal/bridge.php that reaches graph.php and the PHP-only reads (docs/doccloud-mcp-tool-surface.md "The bridge"). Every query runs as the asking member (db.fetch_scoped sets
app.member_id from the verified bearer): the views and the functions decide the rows; nothing here filters by who is asking."""
from __future__ import annotations

import datetime
import functools
import hashlib
import hmac
import json
import re
import time
from typing import Any

import asyncpg
import httpx
from pydantic import BaseModel, ConfigDict

import db
import server_common

RO = {"readOnlyHint": True, "openWorldHint": False}
Ref = int | str          # an id or a name


class _Base(BaseModel):
    model_config = ConfigDict(str_strip_whitespace=True, extra="forbid", populate_by_name=True)


class _Flat(BaseModel):
    # The kernel adds fields of its own to a share's call; a share ignores what it does not know.
    model_config = ConfigDict(str_strip_whitespace=True, extra="ignore", populate_by_name=True)


def dumps(obj) -> str:
    return db.to_json(obj)


class Refusal(Exception):
    """An answer, not a crash: the code, the sentence, whether trying again could help, and any extra keys (fields, candidates, detail)."""

    def __init__(self, code: str, message: str, retry: bool = False, **extra) -> None:
        super().__init__(message)
        self.code, self.message, self.retry, self.extra = code, message, retry, extra


def fail(code: str, message: str, retry: bool = False, **extra) -> str:
    return dumps({"ok": False, "error": {"code": code, "message": message, "retry": retry, **extra}})


def answers(fn):
    """Turn a Refusal (and the database's refusals) into the tool's error answer; the tool never raises."""
    @functools.wraps(fn)
    async def inner(*a, **k):
        try:
            return await fn(*a, **k)
        except Refusal as r:
            return fail(r.code, r.message, r.retry, **r.extra)
        except asyncpg.PostgresError as ex:
            return fail("invalid_argument" if isinstance(ex, (asyncpg.DataError, asyncpg.InvalidTextRepresentationError)) else "server_error", "The question could not be answered: " + ex.__class__.__name__ + ".", retry=not isinstance(ex, asyncpg.DataError))
    return inner


def lim(n: int | None, default: int = 25, top: int = 100) -> int:
    return max(1, min(int(n or default), top))


def off(n: int | None) -> int:
    return max(0, min(int(n or 0), 10000))


class Where:
    """Builds `a = $1 AND b = ANY($2)` with positional parameters in order."""

    def __init__(self) -> None:
        self.parts: list[str] = []
        self.args: list = []

    def p(self, value) -> str:
        self.args.append(value)
        return f"${len(self.args)}"

    def add(self, sql: str, *values) -> "Where":
        self.parts.append(sql.format(*[self.p(v) for v in values]))
        return self

    def raw(self, sql: str) -> "Where":
        self.parts.append(sql)
        return self

    def sql(self) -> str:
        return " AND ".join(self.parts) if self.parts else "TRUE"


def like(s: str) -> str:
    return "%" + s.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_") + "%"


def q_alone(p) -> bool:
    """True when only `q` (and perhaps `limit`) was given — the kernel's entity resolver's call, answered as a plain list."""
    return bool(getattr(p, "q", None)) and set(p.model_fields_set) <= {"q", "limit"}


def plain(rows: list[dict], id_field: str, label_field: str, *more: str) -> str:
    return dumps([{id_field: r[id_field], label_field: r[label_field], **{m: r.get(m) for m in more}} for r in rows[:6]])


def qmatch(w: "Where", q: str | None, id_col: str, *name_cols: str) -> None:
    """The resolvers' `q`: digits are an id (or a name made of digits), anything else is looked for in the names (ILIKE)."""
    if not q:
        return
    n = w.p(like(q))
    names = " OR ".join(f"{c} ILIKE {n}" for c in name_cols)
    if q.strip().isdigit():
        w.raw(f"({id_col} = {w.p(int(q))} OR {names})")
    else:
        w.raw(f"({names})")


def when(s, what: str = "The time", end_of_day: bool = False) -> datetime.datetime | None:
    """A YYYY-MM-DD day or an ISO 8601 time as an aware datetime (None stays None); anything else is a sentence."""
    if s is None or s == "":
        return None
    if isinstance(s, datetime.datetime):
        return s if s.tzinfo else s.replace(tzinfo=datetime.timezone.utc)
    t = str(s).strip()
    try:
        if re.fullmatch(r"\d{4}-\d{2}-\d{2}", t):
            d = datetime.date.fromisoformat(t)
            return datetime.datetime.combine(d + datetime.timedelta(days=1 if end_of_day else 0), datetime.time.min, tzinfo=datetime.timezone.utc)
        v = datetime.datetime.fromisoformat(t.replace("Z", "+00:00"))
    except ValueError as ex:
        raise Refusal("invalid_argument", f"{what} is a day (2026-03-02) or an ISO time (2026-03-02T09:30:00Z).", fields=[what]) from ex
    return v if v.tzinfo else v.replace(tzinfo=datetime.timezone.utc)


def ago(days: int) -> datetime.datetime:
    return datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=days)


def is_int(v) -> bool:
    return isinstance(v, int) or (isinstance(v, str) and v.strip().isdigit())


async def me(q) -> int | None:
    rows = await q("SELECT nullif(current_setting('app.member_id', true), '')::bigint AS m")
    return rows[0]["m"] if rows else None


async def flag(q, sql: str, *args) -> bool:
    rows = await q("SELECT " + sql + " AS ok", *args)
    return bool(rows and rows[0]["ok"])


async def has_right(q, right: str) -> bool:
    return await flag(q, "dc_has_right($1)", right)


def need_right(ok: bool, what: str) -> None:
    if not ok:
        raise Refusal("managers_only", what)


def candidates(rows: list[dict], id_field: str, label_field: str) -> list[dict]:
    return [{"id": r[id_field], "label": r[label_field]} for r in rows[:6]]


def ambiguous(what: str, v, rows: list[dict], id_field: str, label_field: str) -> Refusal:
    return Refusal("ambiguous", f"More than one {what} matches '{v}'; ask which (or give its id).", candidates=candidates(rows, id_field, label_field))


def not_found(what: str = "record") -> Refusal:
    return Refusal("not_found", f"No {what} you can see matches that.")


# ---- id-or-name resolvers: through the views, so what the caller may not see is simply not there ---------------------------------------

async def doc_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the document (its id, or its name).", fields=["document"])
    if is_int(v):
        rows = await q("SELECT document_id FROM mcp_documents WHERE document_id = $1", int(v))
        if rows:
            return int(rows[0]["document_id"])
        raise not_found("document")
    s = str(v).strip()
    rows = await q("SELECT document_id, name FROM mcp_documents WHERE lower(name) = lower($1) AND trashed_at IS NULL ORDER BY document_id LIMIT 7", s)
    if not rows:
        rows = await q("SELECT document_id, name FROM mcp_documents WHERE name ILIKE $1 AND trashed_at IS NULL ORDER BY name LIMIT 7", like(s))
    if len(rows) > 1:
        raise ambiguous("document", s, rows, "document_id", "name")
    if not rows:
        raise not_found("document")
    return int(rows[0]["document_id"])


async def folder_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the folder (its id, its path, or its name).", fields=["folder"])
    if is_int(v):
        rows = await q("SELECT folder_id FROM mcp_folders WHERE folder_id = $1", int(v))
        if rows:
            return int(rows[0]["folder_id"])
        raise not_found("folder")
    s = str(v).strip()
    last = re.split(r"\s*(?:/|›)\s*", s.strip("/ "))[-1]
    rows = await q("SELECT folder_id, name FROM mcp_folders WHERE lower(name) = lower($1) AND trashed_at IS NULL AND parent_id IS NOT NULL ORDER BY folder_id LIMIT 30", last)
    if "/" in s or "›" in s:
        want = [x.strip().lower() for x in re.split(r"/|›", s) if x.strip()]
        keep = []
        for r in rows:
            path = await q("SELECT lower(name) AS n FROM dc_folder_path($1) ORDER BY depth", r["folder_id"])
            names = [x["n"] for x in path]
            if names[-len(want):] == want:
                keep.append(r)
        rows = keep
    if len(rows) > 1:
        raise ambiguous("folder", s, [{"folder_id": r["folder_id"], "label": (await q("SELECT dc_folder_path_text($1) AS t", r["folder_id"]))[0]["t"]} for r in rows[:6]], "folder_id", "label")
    if not rows:
        raise not_found("folder")
    return int(rows[0]["folder_id"])


async def library_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the library (its id, or its name).", fields=["library"])
    if is_int(v):
        rows = await q("SELECT library_id FROM mcp_libraries WHERE library_id = $1", int(v))
    else:
        rows = await q("SELECT library_id, name FROM mcp_libraries WHERE lower(name) = lower($1) ORDER BY archived_at NULLS FIRST LIMIT 7", str(v).strip())
        if len(rows) > 1:
            raise ambiguous("library", v, rows, "library_id", "name")
    if not rows:
        raise not_found("library")
    return int(rows[0]["library_id"])


async def group_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the group (its id, its key, or its name).", fields=["group"])
    if is_int(v):
        rows = await q("SELECT group_id FROM mcp_groups WHERE group_id = $1", int(v))
    else:
        rows = await q("SELECT group_id, name FROM mcp_groups WHERE lower(name) = lower($1) OR key = lower($1) ORDER BY archived_at NULLS FIRST LIMIT 7", str(v).strip())
        if len(rows) > 1:
            raise ambiguous("group", v, rows, "group_id", "name")
    if not rows:
        raise not_found("group")
    return int(rows[0]["group_id"])


async def member_ref(q, v) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the member (their id, or their name).", fields=["member"])
    if is_int(v):
        rows = await q("SELECT member_id FROM mcp_members WHERE member_id = $1", int(v))
    else:
        rows = await q("SELECT member_id, display_name FROM mcp_members WHERE lower(display_name) = lower($1) LIMIT 7", str(v).strip())
        if len(rows) > 1:
            raise ambiguous("member", v, rows, "member_id", "display_name")
    if not rows:
        raise not_found("member")
    return int(rows[0]["member_id"])


async def tag_ref(q, v) -> dict:
    """{tag_id, slug} by id, slug, label or alias."""
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the tag (its slug, label, or id).", fields=["tag"])
    if is_int(v):
        rows = await q("SELECT tag_id, slug FROM mcp_tags WHERE tag_id = $1", int(v))
    else:
        rows = await q("SELECT t.tag_id, t.slug FROM mcp_tags t WHERE t.tag_id = dc_tag_resolve($1) OR lower(t.label) = lower($1) ORDER BY t.archived_at NULLS FIRST LIMIT 3", str(v).strip())
        if len({r["tag_id"] for r in rows}) > 1:
            raise ambiguous("tag", v, rows, "tag_id", "slug")
    if not rows:
        raise not_found("tag")
    return dict(rows[0])


async def link_ref(q, v, kind: str | None = None) -> int:
    if v is None or v == "":
        raise Refusal("invalid_argument", "Name the link (its id, or its label).", fields=["link"])
    if is_int(v):
        rows = await q("SELECT link_id FROM mcp_share_links WHERE link_id = $1", int(v))
    else:
        rows = await q("SELECT link_id, label FROM mcp_share_links WHERE lower(label) = lower($1) AND revoked_at IS NULL ORDER BY link_id LIMIT 7", str(v).strip())
        if len(rows) > 1:
            raise ambiguous("link", v, rows, "link_id", "label")
    if not rows:
        raise not_found("link")
    return int(rows[0]["link_id"])


async def thing_ref(q, document, folder) -> tuple[str, int]:
    """Exactly one of document or folder."""
    if (document in (None, "")) == (folder in (None, "")):
        raise Refusal("invalid_argument", "Give a document or a folder, not both and not neither.", fields=["document", "folder"])
    return ("document", await doc_ref(q, document)) if document not in (None, "") else ("folder", await folder_ref(q, folder))


# ---- the bridge ---------------------------------------------------------------------------------------------------------------------------

BRIDGE_WAIT = {"graph_find": 20, "graph_related": 20, "share_text": 55}


async def bridge(op: str, args: dict | None = None, *, application: bool = False) -> dict:
    """POST to the application's own loopback door (html/internal/bridge.php), signed with ACTIONS_RELAY_KEY: the HMAC covers the timestamp and the body's sha256. The caller is the verified
    member (door mcp or agent) or, for the kernel's token, the consumer the kernel named in X-OS-Consumer (K26) — never an argument. Returns the decoded answer; raises Refusal with the PHP side's code."""
    key = db.ENV.get("ACTIONS_RELAY_KEY", "")
    port = db.ENV.get("APP_INTERNAL_PORT", "")
    if len(key) < 32 or not port:
        raise Refusal("bridge_unavailable", "The application's own door is not configured (ACTIONS_RELAY_KEY and APP_INTERNAL_PORT).", retry=False)
    ctx = await server_common.run_context()
    if application:
        consumer = db.request_consumer.get()
        caller: dict = {"kind": "application", "application": consumer, "agent_member_id": db.request_consumer_agent.get()}
        ctx = {"run_id": None, "request_id": None, "is_eval": False, "door": "share"}
    else:
        member = db.request_member_id.get()
        if member is None:
            raise Refusal("not_found", "Who is asking? No member is behind this call.")
        caller = {"kind": "member", "member_id": int(member)}
    body = json.dumps({"op": op, "caller": caller, "door": ctx["door"], "run_id": ctx["run_id"], "request_id": ctx["request_id"], "is_eval": ctx["is_eval"], "args": args or {}},
                      separators=(",", ":"), default=str).encode()
    ts = str(int(time.time()))
    sig = hmac.new(key.encode(), f"dc-bridge:{ts}.{hashlib.sha256(body).hexdigest()}".encode(), hashlib.sha256).hexdigest()
    try:
        async with httpx.AsyncClient(timeout=BRIDGE_WAIT.get(op, 10)) as client:
            r = await client.post(f"http://127.0.0.1:{port}/internal/bridge.php", content=body, headers={"Content-Type": "application/json", "X-DC-Bridge": f"{ts}.{sig}"})
    except httpx.TimeoutException as ex:
        raise Refusal("graph_timeout" if op.startswith(("graph", "subject")) else "bridge_unavailable", "The application's own door did not answer in time.", retry=True) from ex
    except httpx.HTTPError as ex:
        raise Refusal("bridge_unavailable", "The application's own door could not be reached.", retry=True) from ex
    try:
        data = r.json()
    except ValueError:
        data = {}
    if r.status_code == 200 and isinstance(data, dict):
        return data
    err = (data.get("error") if isinstance(data, dict) else None) or {}
    code = str(err.get("code") or "")
    if r.status_code >= 500 or r.status_code == 401:
        raise Refusal("bridge_unavailable", "The application's own door refused or failed (" + str(r.status_code) + ").", retry=r.status_code >= 500)
    if code in ("consumer_unknown", "not_shared", "invalid", "unknown_op", "unknown_member", "forbidden"):
        raise Refusal(code, str(err.get("message") or code))
    raise Refusal("not_found" if r.status_code == 404 else "invalid_argument", str(err.get("message") or "Refused."))


# ---- every field of every parameter model carries a description (the surface: "a described Field on every field") --------------------------------------------------------------------------------

DESCRIBE = {
    "sort": "How to order the rows", "dir": "Ascending or descending", "include_trashed": "Include what is in the trash", "include_archived": "Include archived ones", "include_left": "Include people who have left",
    "include_purged": "Include purged versions", "min_bytes": "Only files at least this big", "max_bytes": "Only files at most this big", "mine": "Only mine", "shared_with_me": "Only what was shared with me",
    "favourites": "Only my favourites", "library": "Only this library (id or name)", "folder": "Only this folder (id or path)", "document": "The document (id or name)", "since": "A day or ISO time — only what is newer",
    "live_only": "Only links that are still live", "guests_only": "Only shares to guests", "unread_only": "Only unread notices", "role": "owner or member", "scope": "mcp for a token, api for a key",
}
KIND = {"FindGroupsIn": "custom or department", "SharedIn": "folder or document", "FindLinksIn": "view or request", "FindTagsIn": "managed or free", "ProposalsIn": "The kind of proposal", "QueueIn": "The kind of job (index, full, forget)",
        "DispatchesIn": "The kind of dispatch", "MembersIn": "human or agent"}
STATUS = {"FailuresIn": "no_text, unsupported or failed", "ProposalsIn": "proposed, accepted, dismissed (or expired)", "QueueIn": "queued, running, done, failed or skipped", "UploadsIn": "open, done, failed …"}


def describe_models(ns: dict) -> None:
    """Give every field without a description one (called at the foot of each tool module, before register() builds the tools)."""
    for obj in list(ns.values()):
        if isinstance(obj, type) and issubclass(obj, BaseModel) and obj.__module__ == ns["__name__"] and obj not in (_Base, _Flat):
            changed = False
            for name, f in obj.model_fields.items():
                if not f.description:
                    f.description = (KIND.get(obj.__name__) if name == "kind" else STATUS.get(obj.__name__) if name == "status" else None) or DESCRIBE.get(name) or name.replace("_", " ").capitalize()
                    changed = True
            if changed:
                obj.model_rebuild(force=True, _types_namespace=ns)
