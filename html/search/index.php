<?php
declare(strict_types=1);
/** Search (screen `search`) — built by slice 4 (NAV_SLICES); until then the shell's placeholder: 200 after the right (search.use), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('search', NAV_SLICES['search'], 'search.use');
