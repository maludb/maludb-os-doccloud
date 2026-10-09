<?php
declare(strict_types=1);
/** Trash (screen `trash`) — built by slice 6 (NAV_SLICES); until then the shell's placeholder: 200 after the right (trash.own), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('trash', NAV_SLICES['trash'], 'trash.own');
