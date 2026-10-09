<?php
declare(strict_types=1);
/**
 * POST /api/v1/upload — one request, up to 64 MB (slice 2 built it, slice 7 documents it): multipart `file` + `folder` (+ `conflict` version | keep_both | refuse, `document` for a new version of one,
 * `name`, `note`); bearer = a person's key. 201 {document_id, version_id, version_no, …}; 409 name_taken {existing_document_id} when the name is taken and `conflict` is not given; 413 over 64 MB (use the parts form).
 */
require_once dirname(__DIR__, 3) . '/app/api/prelude.php';
require_once dirname(__DIR__, 3) . '/app/api/routes.php';
require_once dirname(__DIR__, 3) . '/app/api/upload.php';
$member = api_route(['POST'], false);
api_upload_one(db(), $member);
