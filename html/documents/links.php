<?php
declare(strict_types=1);
/** The document's links (`document-links`, /documents/{id}/links). */
require_once dirname(__DIR__, 2) . '/app/features/access/handler.php';
require_login();
$pdo = db();
$thing = document_at_level($pdo, request_integer('id') ?? request_integer('document'), 'view');
$id = (int) $thing['id'];
$kind = 'document';
require __DIR__ . '/../access/links-screen.php';
