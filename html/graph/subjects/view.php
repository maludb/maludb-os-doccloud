<?php
declare(strict_types=1);
/** A SUBJECT (screen `subject-view`, GET /graph/subjects/{id}): its type, its documents the caller may see, its neighbours as a list (a small SVG at 1280), "find documents about this", "path to…". */
require_once dirname(__DIR__, 3) . '/app/features/graph/handler.php';
require_login();
require_right('graph.use');
$pdo = db();
$id = request_integer('id') ?? request_integer('subject');
if ($id === null || $id <= 0) {
    refuse(422, 'Say which subject.');
}
$s = subject_page($pdo, $id);
if ($s['status'] === 'not_found') {
    dc_not_found('Not found.');
}
log_activity($pdo, 'screen.view', 'subject', $id, ['screen' => 'subject-view']);
if (wants_json()) {
    respond_screen(['subject' => present_subject_page($s)]);
}
render_screen($s['status'] === 'ok' ? $s['name'] : 'Subject', view('graph/subject.php', ['s' => $s, 'tz' => member_timezone()]), ['activeNav' => 'graph', 'screen' => 'subject-view', 'entity' => 'subject', 'recordId' => $id]);
