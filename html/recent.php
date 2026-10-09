<?php
declare(strict_types=1);
/** Recent (screen `recent`) — built by slice 1 (NAV_SLICES); until then the shell's placeholder: 200 after the right (library.personal), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 1) . '/app/bootstrap.php';
render_nav_stub('recent', NAV_SLICES['recent'], 'library.personal');
