<?php
declare(strict_types=1);
/**
 * THE GRAPH'S ADMIN (screen `graph-admin`, GET /graph/admin?folder=&library=&month=): the policy per library (and the policy form for a folder or a library when
 * named, with the estimate before a `full`), the budget (the month's bar), the model (never the credential; "Test extraction" with a document picker), the queue
 * (retry, skip), usage by month, reconciliation on demand. graph.manage, except the policy part: a manager of the library may set none/index (no model spend).
 */
require_once dirname(__DIR__, 2) . '/app/features/graph/handler.php';
require_login();
$pdo = db();
$folderId = request_integer('folder');
$libraryId = request_integer('library');
$target = null;
if ($folderId !== null) {
    $f = folder_at_level($pdo, $folderId, 'manage');
    if (!may_set_policy($pdo, (int) $f['library_id'])) {
        refuse(403, 'Only the library\'s managers and the graph admin set a policy.');
    }
    $target = ['kind' => 'folder', 'id' => (int) $f['id'], 'name' => $f['name'], 'library_id' => (int) $f['library_id'], 'own_policy' => $f['graph_policy'], 'effective' => effective_policy($pdo, (int) $f['id']), 'estimate' => graph_estimate($pdo, (int) $f['id']), 'path' => folder_path_text($pdo, (int) $f['id']), 'is_root' => $f['parent_id'] === null];
} elseif ($libraryId !== null) {
    $l = library_from_request($pdo, 'library');
    if (!may_set_policy($pdo, (int) $l['id'])) {
        refuse(403, 'Only the library\'s managers and the graph admin set a policy.');
    }
    $target = ['kind' => 'library', 'id' => (int) $l['id'], 'name' => $l['name'], 'library_id' => (int) $l['id'], 'own_policy' => $l['graph_policy'], 'effective' => $l['graph_policy'], 'estimate' => graph_estimate($pdo, (int) $l['root_folder_id']), 'path' => $l['name'], 'is_root' => true];
} else {
    require_right('graph.manage');
}
$admin = has_right('graph.manage');
$state = graph_state($pdo);
$policies = $admin ? library_policies($pdo) : [];
$jobs = $admin ? graph_jobs($pdo, array_filter(['status' => request_string('status'), 'kind' => request_string('kind')]), request_integer('page') ?? 1) : ['rows' => [], 'total' => 0, 'page' => 1];
$usage = $admin && has_right('costs.read') ? graph_usage($pdo, 6) : [];
$model = $admin ? graph_model($pdo) : ['status' => 'hidden', 'config' => null, 'settings' => [], 'prompt' => ''];
$reconcile = null;
if ($admin && request_string('reconcile') === '1' && graph_engine_up()) {
    $reconcile = graph_reconcile($pdo);
}
log_activity($pdo, 'screen.view', 'graph', null, ['screen' => 'graph-admin', 'folder_id' => $folderId, 'library_id' => $libraryId]);
$notices = ['policy' => ['success', 'The policy was set; the queue follows within the minute.'], 'budget' => ['success', 'The budget was set.'], 'model' => ['success', 'The model was set; the credential is kept by MaluDB only.'],
            'rebuilt' => ['success', 'Everything under it will be re-sent to the graph.'], 'retried' => ['success', 'The job is queued again.'], 'skipped' => ['success', 'The job was skipped.'], 'tested' => ['success', 'The extraction ran; see the result below.']];
$test = isset($_GET['test_edges']) ? ['edges_count' => (int) $_GET['test_edges'], 'status' => request_string('test_status'), 'error' => request_string('test_error')] : null;
if (wants_json()) {
    respond_screen(['available' => $state['available'], 'target' => $target === null ? null : array_replace($target, ['estimate' => present_estimate($target['estimate'])]), 'policies' => array_map('present_policy_row', $policies), 'budget' => present_budget($state['budget']),
        'state' => ['by_policy' => $state['by_policy'], 'queued' => $state['queued'], 'running' => $state['running'], 'failed' => $state['failed'], 'skipped' => $state['skipped'], 'done' => $state['done']],
        'jobs' => array_map('present_job', $jobs['rows']), 'jobs_total' => $jobs['total'], 'usage' => $usage, 'model' => ['status' => $model['status'], 'config' => $model['config'], 'settings' => $model['settings'], 'secret_ref' => $model['secret_ref'] ?? null],
        'reconcile' => $reconcile, 'test' => $test, 'may' => ['manage' => $admin]]);
}
render_screen($target === null ? 'The document graph' : 'Graph policy: ' . $target['name'], view('graph/admin.php', ['target' => $target, 'policies' => $policies, 'state' => $state, 'jobs' => $jobs, 'usage' => $usage, 'model' => $model, 'reconcile' => $reconcile, 'test' => $test,
    'admin' => $admin, 'tz' => member_timezone(), 'notice' => dc_notice($_GET['notice'] ?? null, $notices), 'filters' => ['status' => request_string('status'), 'kind' => request_string('kind')]]), ['activeNav' => 'graph', 'screen' => 'graph-admin']);
