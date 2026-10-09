<?php
declare(strict_types=1);
/** Shared with me (screen `shared`) — built by slice 3 (NAV_SLICES); until then the shell's placeholder: 200 after the right (library.personal|documents.guest), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('shared', NAV_SLICES['shared'], 'library.personal|documents.guest');
