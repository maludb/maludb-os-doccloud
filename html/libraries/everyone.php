<?php
declare(strict_types=1);
/** Action `library_everyone_set` (library, everyone_level none|view|upload|edit — every member of the business; log library.everyone_set; external_send when raised — the kernel pauses an agent before this is called): a shared library, libraries.shared. */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('libraries.shared');
$pdo = db();
$lib = library_from_request($pdo, 'library');
if ($lib['kind'] !== 'shared') {
    refuse(422, 'Only a shared library has an everyone level: a group library is its group\'s, a personal one its owner\'s.');
}
$level = (string) (req_val('everyone_level') ?? '');
if (!isset(EVERYONE_LEVELS[$level])) {
    dc_refuse_fields(['everyone_level' => 'The everyone level is none, view, upload or edit.']);
}
$id = (int) $lib['id'];
if ($level !== $lib['everyone_level']) {
    dc_guard($pdo, static function () use ($pdo, $lib, $id, $level): void {
        $pdo->beginTransaction();
        library_everyone_set($pdo, $id, $level);
        files_log($pdo, 'library.everyone_set', 'library', $id, ['library_id' => $id, 'before' => ['everyone_level' => $lib['everyone_level']], 'after' => ['everyone_level' => $level, 'name' => $lib['name']]]);
        $pdo->commit();
    });
}
dc_done('Everyone in the business now has ' . $level . ' on ' . $lib['name'], $id, dc_land(return_path('/libraries/' . $id), 'everyone'), 'libraryChanged', ['everyone_level' => $level]);
