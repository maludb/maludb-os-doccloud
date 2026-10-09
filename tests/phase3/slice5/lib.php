<?php
/**
 * Slice 5's helpers on top of slice 4's (seed_texts(): the Board with acme-msa.pdf (Marco), minutes-jan.docx, Priya's my-notes.md, Marco's for-ann.pdf
 * shared with the guest Ann, the handbook in a shared library everyone views, Engineering's design.pdf): the engine's mode (DC_ENGINE), the fake model
 * provider's counters, the model set through the admin's handler, the worker's graph pass, the queue and the engine rows as the database holds them,
 * a find as a member through the page's JSON branch, the engine read directly (the proof's own process, with the scratch env) for a subject's id.
 */
require dirname(__DIR__) . '/slice4/lib.php';
function engine(): bool { return getenv('DC_ENGINE') === '1'; }
function fake_url(): string { return rtrim((string) getenv('DC_FAKE_PROVIDER'), '/'); }
/** The fake provider's counters / last requests / reset. */
function fake(string $path, string $method = 'GET'): array
{
    $ch = curl_init(fake_url() . $path);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $r = (string) curl_exec($ch);
    return json_decode($r, true) ?: [];
}
/** The engine module in the proof's own process (the scratch env is exported). */
function engine_php(): void
{
    static $loaded = false;
    if (!$loaded) { require_once dirname(__DIR__, 3) . '/app/bootstrap.php'; require_once dirname(__DIR__, 3) . '/app/doccloud/graph.php'; $loaded = true; }
}
/** The engine's document by its id, straight from the API (404 → not_found). */
function engine_doc(string $maludbId): string { engine_php(); return (string) dc_graph_document($maludbId)['status']; }
function engine_subject_id(string $name): ?int { engine_php(); return dc_graph_subject_id($name); }
/** The model set through the admin's handler (the fake provider, the default prompt, a credential typed once). */
function graph_ready(): array
{
    static $done = null;
    if ($done !== null) { return $done; }
    $done = post_json(1, '/graph/model.php', ['provider_name' => 'dc-fake', 'provider_kind' => 'local_http', 'model_alias' => 'dc-extract', 'model_identifier' => 'fake-model', 'base_url' => fake_url() . '/v1',
        'embedding_model' => 'maludb-local-dev', 'token' => 'fake-secret-' . bin2hex(random_bytes(4))]);
    return $done;
}
/** The worker's graph pass; returns the pass's report. */
function run_graph(array $env = []): array { $r = worker('graph_jobs', $env); return $r['ran']['graph_jobs'] ?? $r; }
function jobs_of(int $doc): array { return q('SELECT * FROM graph_jobs WHERE document_id = :d ORDER BY id', ['d' => $doc]); }
function job_status(int $doc, string $kind): ?string { $r = q("SELECT status FROM graph_jobs WHERE document_id = :d AND kind = :k ORDER BY id DESC LIMIT 1", ['d' => $doc, 'k' => $kind]); return $r[0]['status'] ?? null; }
function graph_row(int $doc): ?array { $r = q('SELECT * FROM graph_documents WHERE document_id = :d', ['d' => $doc]); return $r[0] ?? null; }
function forget_jobs(): array { return q("SELECT * FROM graph_jobs WHERE kind = 'forget' ORDER BY id"); }
/** A find as a member through the page's JSON branch: [names[], status, json]. */
function find_as(int $m, string $q, array $get = []): array
{
    $r = get_json($m, '/graph/?' . http_build_query(['q' => $q] + $get));
    $rows = $r['json']['data']['find']['rows'] ?? [];
    $names = array_map(static fn (array $x): string => $x['name'], $rows);
    sort($names);
    return ['names' => $names, 'status' => (string) ($r['json']['data']['find']['status'] ?? ''), 'json' => $r['json'], 'code' => $r['code'], 'rows' => $rows];
}
/** The seed with texts and the tags the graph will know: acme-corp on acme, the minutes, Priya's notes and for-ann; contract on acme. */
function seed_graph(): array
{
    $s = seed_texts();
    $p = pdo();
    $tag = static function (int $doc, string $label, int $by) use ($p): void {
        $p->exec("SELECT set_config('app.member_id', '$by', false)");
        $id = (int) one('SELECT dc_tag_ensure(:l, :b)', ['l' => $label, 'b' => $by]);
        $p->prepare('SELECT dc_tag_apply(:d, :t, :b)')->execute(['d' => $doc, 't' => $id, 'b' => $by]);
    };
    $tag($s['board_doc'], 'Acme Corp', 27); $tag($s['board_doc'], 'Contract', 27); $tag($s['minutes'], 'Acme Corp', 27); $tag($s['priya_doc'], 'Acme Corp', 26); $tag($s['guest_doc'], 'Acme Corp', 27);
    return $s;
}
