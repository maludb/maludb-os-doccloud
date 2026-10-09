<?php
declare(strict_types=1);
/** Graph (screen `graph`) — built by slice 5 (NAV_SLICES); until then the shell's placeholder: 200 after the right (graph.use), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('graph', NAV_SLICES['graph'], 'graph.use');
