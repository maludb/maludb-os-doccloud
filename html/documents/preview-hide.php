<?php
declare(strict_types=1);
/** Action `document_preview_hide` (document — log document.preview_hide): an image's or a video's preview shows a placeholder to everyone (docs/build-specs/grid-and-media.md). */
require_once dirname(__DIR__, 2) . '/app/features/files/handler.php';
dc_handler_begin();
require_right('documents.edit');
$pdo = db();
preview_hidden_action($pdo, document_at_level($pdo, request_integer('document') ?? request_integer('id'), 'edit'), true);
