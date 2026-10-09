<?php
declare(strict_types=1);
/**
 * The folder's share screen (`folder-share`, /folders/{id}/share; visibility.md §2): the library's base line, the inherited rows greyed with "from X",
 * this folder's own rows with a level select and Remove, Restrict, the four add forms (a person or an agent, a group, a department, a guest) and the
 * application form, "who can see this and why", the links and requests summaries. A manager's screen; a lesser holder sees only their own explanation.
 */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$folder = folder_at_level($pdo, request_integer('id') ?? request_integer('folder'), 'view');
$id = (int) $folder['id'];
require __DIR__ . '/../access/screen.php';
