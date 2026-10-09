<?php
declare(strict_types=1);
/** The document's share screen (`document-share`, /documents/{id}/share): the same as a folder's, with "share this file only" (its own rows). */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$document = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
$id = (int) $document['id'];
require __DIR__ . '/../access/screen.php';
