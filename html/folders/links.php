<?php
declare(strict_types=1);
/** The folder's links and requests (`folder-links`, /folders/{id}/links). */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$thing = folder_at_level($pdo, request_integer('id') ?? request_integer('folder'), 'view');
$id = (int) $thing['id'];
$kind = 'folder';
require __DIR__ . '/../access/links-screen.php';
