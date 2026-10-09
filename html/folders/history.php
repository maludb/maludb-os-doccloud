<?php
declare(strict_types=1);
/** Access history (`access-history`, /folders/{id}/history?at=): who could see this folder at the end of a day, from the trail (dc_access_history); the changes below. */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$folder = folder_at_level($pdo, request_integer('id') ?? request_integer('folder'), 'view');
$id = (int) $folder['id'];
$thing = $folder;
$kind = 'folder';
require __DIR__ . '/../access/history-screen.php';
