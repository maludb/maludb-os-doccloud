<?php
declare(strict_types=1);
/** Curate (screen `curate`) — built by slice 9 (NAV_SLICES); until then the shell's placeholder: 200 after the right (groups.manage|libraries.shared|tags.manage), 501 for JSON and a POST. Removing this call is the slice's first step. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('curate', NAV_SLICES['curate'], 'groups.manage|libraries.shared|tags.manage');
