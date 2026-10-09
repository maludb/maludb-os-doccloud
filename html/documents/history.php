<?php
declare(strict_types=1);
/** Access history for a document (`access-history`, /documents/{id}/history?at=). */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$document = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
$id = (int) $document['id'];
$thing = $document;
$kind = 'document';
require __DIR__ . '/../access/history-screen.php';
