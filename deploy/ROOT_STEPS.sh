#!/usr/bin/env bash
# DocCloud — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0 (2026-10-09);
# later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database, php -S and fixtures, and
# never touch an installed application, Apache, systemd or the MaluDB API.
#   bash deploy/ROOT_STEPS.sh              prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them: 0, 0c, 2b, 3 and 4 (the ports, the media
#                                          tools, the storage directory, the memory database, its token)
# Steps 1, 2, 5, 6 and 7 are printed and never run: they are the installer's, the owner's, or put a secret somewhere.
set -euo pipefail
APP=/srv/apps/doccloud
KERNEL=/var/www
MODE=${1:-plan}
MEMDB=subello_doccloud_memory       # <tenant>_doccloud_memory (design §14); change the prefix for another tenant
MEMROLE=doccloud_mem                # the schema and the login role
MALUDB_API=${MALUDB_API:-http://127.0.0.1:8000}
STORAGE=${DC_STORAGE_DIR:-$APP/storage}   # the bytes: under the install by default, a mount when the owner names one (answer (c))
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }
env_has() { [ -f "$APP/config/.env" ] && grep -q "^$1=." "$APP/config/.env"; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (Help Desk's lesson of 2026-10-02). A config/.env holding ONLY the three
#    ports: the installer reads them, fills every other required key itself, and sets fresh role passwords because DB_PASSWORD
#    is absent. Never put anything else in this file by hand before apply — except DC_STORAGE_DIR when the bytes go on a mount.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "mkdir -p '$APP/config' && printf 'APP_INTERNAL_PORT=8190\nMCP_RECORDS_PORT=8841\nMCP_ACTIVITY_PORT=8842\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
  if [ "$STORAGE" != "$APP/storage" ]; then run bash -c "echo 'DC_STORAGE_DIR=$STORAGE' >> '$APP/config/.env'"; fi
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{...}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 0c. THE MEDIA TOOLS (ROOT; idempotent; added 2026-10-10, docs/build-specs/grid-and-media.md): ffmpeg for a video's thumbnail and its
#     length, poppler-utils' pdftoppm for a PDF's first page. Without them the grid shows the type's icon, and the worker fills the
#     thumbnails in once they exist (a version waiting on a missing tool is kept `no_tool` and retried). Installed on this host 2026-10-10.
echo "== 0c. The media tools: ffmpeg and pdftoppm (ROOT)"
if command -v ffmpeg >/dev/null 2>&1 && command -v ffprobe >/dev/null 2>&1 && command -v pdftoppm >/dev/null 2>&1; then
  echo "   (ffmpeg, ffprobe and pdftoppm are installed)"
else
  run env DEBIAN_FRONTEND=noninteractive apt-get install -y ffmpeg poppler-utils
fi
#    See that it worked:  ffmpeg -version | head -1; pdftoppm -v 2>&1 | head -1

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours — the row K31 seeded, db/176 —, /sso and /sso/logout, scope_kind none),
#    the application token, skills imported, approval policies, the installing super-admin's admin grant.
#    --grant-standing-departments gives every standing department the Member role (key user). --hire-agents hires the expert
#    and the Filing Clerk. Run it twice: the roles are read from app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 2b. THE STORAGE DIRECTORY (ROOT; idempotent; before the first upload). The bytes live under the install by default
#     ($APP/storage) or on the mount DC_STORAGE_DIR names (answer (c)); blobs/ is content-addressed (sha256), uploads/ holds the
#     parts of uploads in flight, thumbs/ the thumbnails, exports/ the zips. Owned by www-data, mode 750 — never under html/.
echo "== 2b. The storage directory $STORAGE (ROOT)"
for d in blobs uploads thumbs exports; do run install -d -o www-data -g www-data -m 750 "$STORAGE/$d"; done
run install -d -o www-data -g www-data -m 750 "$STORAGE"

# ------------------------------------------------------------------------------------------------------------------
# 3. THE MEMORY DATABASE (ROOT, any time; idempotent). The `maludb` database is only the extension's bootstrap — never a
#    tenant. The DOCUMENT GRAPH lives in a memory database of its own (design D3), one namespace `dc`, beside the tenant's
#    agent memory (certstudy_memory) — document content never enters what the agents remember. Same recipe as the kernel's
#    own: MaluDB README section 2 — a database with maludb_core, a login role and a schema of one name, enable_memory_schema,
#    grant_memory_access, and GRANT maludb_secret_consumer (found by Knowledge's engine proof; the README omits it).
#    The role's password is generated once into a root-only file, used to mint the token in step 4, and never printed.
echo "== 3. The memory database $MEMDB (ROOT)"
PW_FILE="$APP/config/doccloud_mem.pw"
psql_pg() { sudo -u postgres psql -v ON_ERROR_STOP=1 -X -q "$@"; }
if [ "$MODE" = apply ]; then
  if ! sudo -u postgres psql -X -tAc "SELECT 1 FROM pg_database WHERE datname='$MEMDB'" | grep -q 1; then
    echo "+ createdb $MEMDB"; sudo -u postgres createdb "$MEMDB"
  else echo "   ($MEMDB exists)"; fi
  if [ ! -s "$PW_FILE" ]; then
    echo "+ generate the role password into $PW_FILE (root only, never printed)"
    ( umask 077; mkdir -p "$APP/config"; openssl rand -hex 24 > "$PW_FILE" ); chown root:root "$PW_FILE"; chmod 600 "$PW_FILE"
  fi
  PW=$(cat "$PW_FILE")
  psql_pg -d "$MEMDB" -c "CREATE EXTENSION IF NOT EXISTS maludb_core CASCADE"
  psql_pg -d postgres -v pw="$PW" <<SQL
SELECT format('CREATE ROLE %I LOGIN', '$MEMROLE') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '$MEMROLE') \gexec
ALTER ROLE $MEMROLE PASSWORD :'pw';
SQL
  psql_pg -d "$MEMDB" <<SQL
CREATE SCHEMA IF NOT EXISTS $MEMROLE AUTHORIZATION $MEMROLE;
SELECT * FROM maludb_core.enable_memory_schema('$MEMROLE');
SELECT * FROM maludb_core.grant_memory_access('$MEMROLE', 'write');
-- without this the namespace's provider secret cannot be read and every model-calling route answers 409 model_not_configured
GRANT maludb_secret_consumer TO $MEMROLE;
SQL
  unset PW
else
  echo "+ createdb $MEMDB   (if absent)"
  echo "+ role $MEMROLE with a generated password in $PW_FILE (root-only)"
  echo "+ CREATE EXTENSION IF NOT EXISTS maludb_core CASCADE   (in $MEMDB)"
  echo "+ CREATE SCHEMA $MEMROLE AUTHORIZATION $MEMROLE; SELECT maludb_core.enable_memory_schema('$MEMROLE'); SELECT maludb_core.grant_memory_access('$MEMROLE','write'); GRANT maludb_secret_consumer TO $MEMROLE"
fi

# ------------------------------------------------------------------------------------------------------------------
# 4. ITS API TOKEN (ROOT; after steps 0 and 3, and after the MaluDB API is up). Minted by proving the role's login
#    (POST /v1/tokens), written to config/.env as MALUDB_DC_API_URL and MALUDB_DC_API_TOKEN, read in ONE file of the application
#    (app/doccloud/graph.php). Skipped when the token is already there. The token is never echoed.
echo "== 4. Mint the memory database's API token into config/.env (ROOT)"
if [ "$MODE" = apply ]; then
  if env_has MALUDB_DC_API_TOKEN; then
    echo "   (MALUDB_DC_API_TOKEN already set — leaving it alone)"
  else
    [ -s "$PW_FILE" ] || { echo "   run step 3 first ($PW_FILE missing)"; exit 1; }
    TOKEN=$(python3 - "$MALUDB_API" "$MEMDB" "$MEMROLE" "$PW_FILE" <<'PY'
import json, sys, urllib.request
api, db, role, pwf = sys.argv[1:5]
body = json.dumps({"pg_dbname": db, "pg_user": role, "pg_password": open(pwf).read().strip(), "device_name": "DocCloud (application)"}).encode()
req = urllib.request.Request(api.rstrip("/") + "/v1/tokens", data=body, headers={"Content-Type": "application/json"})
r = json.load(urllib.request.urlopen(req, timeout=10))
print(r.get("token") or r.get("access_token") or "")
PY
)
    [ -n "$TOKEN" ] || { echo "   the API gave no token (is maludb-api up on $MALUDB_API?)"; exit 1; }
    { echo "MALUDB_DC_API_URL=$MALUDB_API"; echo "MALUDB_DC_API_TOKEN=$TOKEN"; } >> "$APP/config/.env"
    unset TOKEN
    chgrp www-data "$APP/config/.env"; chmod 640 "$APP/config/.env"
    echo "   token written to $APP/config/.env (not shown)"
  fi
else
  echo "+ POST $MALUDB_API/v1/tokens {pg_dbname:$MEMDB, pg_user:$MEMROLE, pg_password:<from $PW_FILE>} -> MALUDB_DC_API_URL, MALUDB_DC_API_TOKEN in config/.env"
fi

# ------------------------------------------------------------------------------------------------------------------
# 5. MANUAL — the graph's model calls on the AI statement (K24). NOT automated: a secret is never put anywhere by a script.
#    (a) Every model the graph will call must be registered, with a price, in the kernel's model registry: the EMBEDDING model
#        (the `index` policy embeds every document — this is the one that always runs) and the EXTRACTION model (the `full`
#        policy only); api_key models only. Name the registry keys.
#    (b) Mint the application's credential (printed ONCE, only its hash is kept; minting again revokes the last):
echo "== 5. MANUAL — K24: the graph's credential at the ledger proxy"
echo "   php $KERNEL/bin/application_model_credential.php mint --app doccloud --models <embedding-model-key>,<extraction-model-key> [--budget <monthly dollars>]"
#    (c) The printed appm_ credential becomes the memory database's PROVIDER API KEY, and the provider base URL becomes
#        http://127.0.0.1:8816/openai (or http://127.0.0.1:8816/anthropic for an Anthropic extraction model). Set them with
#        POST /v1/memory/config against the memory database (bearer = MALUDB_DC_API_TOKEN) for the namespace "dc" — by hand or
#        from the admin's graph screen once built (slice 5); the config route names the fields (embedding_model, alias, provider).
echo "   then: the appm_ credential is the memory database's provider API key; the provider base URL is http://127.0.0.1:8816/openai (or /anthropic);"
echo "         set with POST /v1/memory/config (namespace dc) against $MEMDB. Put the credential nowhere else."
#    (d) The runner must serve the credential route (restart once after K24 if never done):
echo "   sudo systemctl restart certstudy-agent-runner        # K24 — the owner's, once"

# ------------------------------------------------------------------------------------------------------------------
# 6. MANUAL — before the first `full` policy: the audit log.
echo "== 6. MANUAL — logging"
echo "   - Settle pgaudit logging FIRST: the graph's calls carry documents' text; decide what the audit log may keep before the first"
echo "     full-policy folder is queued, not after (the disk filled once on 2026-10-05)."

# ------------------------------------------------------------------------------------------------------------------
# 7. The owner's keys and decisions (any time after 2):
echo "== 7. The owner's"
echo "   - DNS and TLS for doccloud.subello.com (until then /etc/hosts maps it to this host)"
echo "   - MaluMail: MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME into $APP/config/.env (the outbox sends the Monday notes, share and request mail) — the installer's mail step writes them from ~/.malumail when present"
echo "   - text extraction and thumbnails: pdftotext and pdftoppm (apt install poppler-utils) on the host; DC_PDFTOTEXT / DC_PDFTOPPM if not on PATH; php-gd for image thumbnails"
echo "   - the per-file ceiling: DC_MAX_DOCUMENT_BYTES in config/.env may only LOWER the setting (2 GB); the vhost's 64M/72M is per PART"
echo "   - the Filing Clerk's duty (50 6 * * 1) in Agent HR if the hire gave none (the kernel reads agents[].duty since 2026-10-08)"
echo "   - the connections a super-admin approves (bin/app_connection.php): Knowledge, Spaces, Help Desk and Projects reading folders_index,"
echo "     documents_index, document_text and document_link; DocCloud reads nothing in v1"
echo "   - the worker, the directory sync and the ingest timers are rendered and enabled by apply; by hand:"
echo "       sudo systemctl enable --now doccloud-worker.timer doccloud-directory-sync.timer doccloud-activity-ingest.timer"
