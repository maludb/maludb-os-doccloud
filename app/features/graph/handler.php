<?php
declare(strict_types=1);

/**
 * THE SECOND EXEMPLAR's prelude (slice 5, docs/build-specs/graph.md): what every graph controller requires first — the files prelude (the
 * walls' readers folder_at_level() / document_at_level(), library_from_request()), the engine module (app/doccloud/graph.php, the ONE file
 * that reads the MaluDB token), the prompt, the reads, the find, the presenters and the writes. Nothing here calls a model: the engine's
 * calls are MaluDB's, metered by the kernel (K24). Without the engine every screen answers the sentence of graph_unavailable_sentence().
 */
require_once dirname(__DIR__) . '/files/handler.php';
require_once dirname(__DIR__) . '/search/parse.php';
require_once dirname(__DIR__) . '/search/write.php';
require_once dirname(__DIR__) . '/search/present.php';
require_once dirname(__DIR__, 2) . '/doccloud/graph.php';
require_once dirname(__DIR__, 2) . '/doccloud/graph_prompt.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/find.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

const GRAPH_POLICIES = ['none' => 'None — keyword search only', 'index' => 'Index — under its known subjects, no model call (the default)', 'full' => 'Full — the model extracts its subjects (queued under the monthly budget)'];
const GRAPH_POLICY_BADGE = ['none' => 'light text-dark', 'index' => 'info text-info', 'full' => 'primary text-primary', 'inherit' => 'secondary text-secondary'];
const GRAPH_JOB_BADGE = ['queued' => 'secondary', 'running' => 'info', 'done' => 'success', 'failed' => 'danger', 'skipped' => 'dark'];

/** The sentence every screen says when the engine is not there (design §6.3 "without the engine"). */
function graph_unavailable_sentence(): string
{
    return 'The document graph is not available right now; keyword search still works.';
}

/** The engine's state once per request (dc_graph_available() is one HTTP call). */
function graph_engine_up(): bool
{
    static $up = null;
    return $up ??= dc_graph_available();
}

/** The caller holds graph.manage, or manages the library (its owner, a group owner, a curator for a shared one). */
function may_set_policy(PDO $pdo, int $libraryId): bool
{
    if (has_right('graph.manage')) {
        return true;
    }
    return (int) one_value($pdo, 'SELECT dc_library_base_level(:id, app_current_member_id())', ['id' => $libraryId]) === 4;
}

/** A job the request names (graph_job), for graph.manage. */
function job_from_request(PDO $pdo, string $param = 'graph_job'): array
{
    $id = request_integer($param) ?? request_integer('id');
    if ($id === null || $id <= 0) {
        refuse(422, 'Say which job.');
    }
    $st = $pdo->prepare('SELECT * FROM graph_jobs WHERE id = :id');
    $st->execute(['id' => $id]);
    $j = $st->fetch();
    if ($j === false) {
        dc_not_found('Not found.');
    }
    return $j;
}
