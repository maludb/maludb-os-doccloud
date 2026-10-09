<?php
declare(strict_types=1);
/**
 * Required FIRST by every route of the token API (html/api/v1/*): the API speaks JSON and only JSON — the handlers it reuses (the slices' action handlers) answer by
 * wants_json(), which reads the Accept header once, so the header is made JSON before anything else runs. The download route sets its own bytes.
 */
$_SERVER['HTTP_ACCEPT'] = 'application/json';
