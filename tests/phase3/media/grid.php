<?php
/** Grid & media proof — the grid (spec "The grid"): the list by default, ?view=grid and the dc_view cookie, a tile per row with a thumbnail, a type icon with its extension, a video's play mark and length, a folder's tile, the placeholder loading no image, the switch only for an editor, the select bar, the sorts, 375-px classes, JSON. */
require __DIR__ . '/lib.php';
$s = media_seed();
$ids = $s['ids'];
$media = $s['media'];
worker('extract_text,thumbnails');
pdo()->exec("SELECT set_config('app.member_id', '27', false)");
$sub = one('SELECT id FROM folders WHERE parent_id = :p AND name = :n', ['p' => $media, 'n' => 'Clips']);
if ($sub === false) { post(27, '/folders/save.php', ['parent' => $media, 'name' => 'Clips']); $sub = one('SELECT id FROM folders WHERE parent_id = :p AND name = :n', ['p' => $media, 'n' => 'Clips']); }
$sub = (int) $sub;
post_j(27, '/access/share.php', ['folder' => $media, 'member' => 28, 'level' => 'view']);
$hidden = $ids['jpg'];
foreach (['png', 'mp4', 'mov', 'webm'] as $k) { post(27, '/documents/preview-show.php', ['document' => $ids[$k]]); }   // whatever an earlier file left hidden
post(27, '/documents/preview-hide.php', ['document' => $hidden]);

echo "1. The layout switch\n";
[$jar, ] = sign_on(27);
$r = page($jar, '/f/' . $media);
ok($r['code'] === 200 && str_contains($r['body'], 'id="listing-table"') && !str_contains($r['body'], 'id="listing-grid"'), 'the list by default');
ok(str_contains($r['body'], 'id="folder-view-list"') && str_contains($r['body'], 'id="folder-view-grid"') && str_contains($r['body'], 'href="/f/' . $media . '?view=grid"'), 'the List | Grid switch, each a link');
$r = page($jar, '/f/' . $media . '?view=grid');
ok(str_contains($r['body'], 'id="listing-grid"') && !str_contains($r['body'], 'id="listing-table"'), '?view=grid shows the grid');
ok(preg_match('/^Set-Cookie: dc_view=grid;[^\n]*HttpOnly[^\n]*SameSite=Lax/mi', $r['headers']) === 1, '...and remembers it in the dc_view cookie (HttpOnly, SameSite=Lax)');
$r = page($jar, '/f/' . $media);
ok(str_contains($r['body'], 'id="listing-grid"'), 'the next visit, with no ?view, keeps the grid (the cookie)');
ok(str_contains(page($jar, '/f/' . $media . '?kind=image')['body'], 'id="listing-grid"'), '...through the kind filters too');
$g = $r['body'];

echo "2. The tiles\n";
$t = tile_html($g, 'document', $ids['png']);
ok(str_contains($t, '<img src="/d/' . $ids['png'] . '/thumb?v=') && str_contains($t, 'loading="lazy"') && !str_contains($t, 'is-page'), 'an image: its thumbnail, lazy, cover');
$t = tile_html($g, 'document', $ids['pdf']);
ok(str_contains($t, '/d/' . $ids['pdf'] . '/thumb?v=') && str_contains($t, 'tile-img is-page'), 'a PDF: its first page, contained');
$t = tile_html($g, 'document', $ids['mp4']);
ok(str_contains($t, '/d/' . $ids['mp4'] . '/thumb?v=') && str_contains($t, 'tile-play') && str_contains($t, '>0:03<'), 'a video: its frame, the play mark and 0:03');
$t = tile_html($g, 'document', $ids['docx']);
ok(str_contains($t, 'tile-icon ft-word') && str_contains($t, 'feather-file-text') && str_contains($t, '>DOCX<') && !str_contains($t, '/thumb'), 'a DOCX: the Word icon and DOCX, no image');
$t = tile_html($g, 'document', $ids['xlsx']);
ok(str_contains($t, 'tile-icon ft-sheet') && str_contains($t, 'feather-grid') && str_contains($t, '>XLSX<'), 'an XLSX: the spreadsheet icon and XLSX');
$t = tile_html($g, 'folder', $sub);
ok(str_contains($t, 'tile-icon ft-folder') && str_contains($t, 'href="/f/' . $sub . '?back=') && !str_contains($t, 'Hide preview'), 'a folder: its icon, a link into it, no switch');
ok(str_contains($g, 'id="listing-select-all"') && substr_count($g, 'class="form-check-input listing-check tile-check"') >= 10, 'Select all and a checkbox on every tile (the select bar)');
ok(str_contains($g, 'id="grid-sort-name"') && str_contains($g, 'id="grid-sort-updated"') && str_contains($g, 'id="grid-sort-size"'), 'the three sorts as links');
ok(str_contains($g, 'row-cols-2 row-cols-sm-3 row-cols-md-4 row-cols-lg-5 row-cols-xxl-6'), 'two a row at 375 px, then 3 / 4 / 5 / 6');

echo "3. A hidden preview\n";
$t = tile_html($g, 'document', $hidden);
ok(str_contains($t, 'listing-tile is-hidden') && str_contains($t, 'tile-placeholder') && str_contains($t, 'Preview hidden'), 'the hidden JPEG: the placeholder');
ok(!str_contains($t, '<img') && !str_contains($t, '/d/' . $hidden . '/thumb') && !str_contains($t, 'src="/d/' . $hidden), '...and no image requested at all (no <img>, no thumbnail URL)');
ok(preg_match('/id="tile-document-' . $hidden . '-switch" checked/', $t) === 1 && str_contains($t, 'hx-post="/documents/preview-show.php"') && str_contains($t, 'hx-trigger="change"') && str_contains($t, 'hx-swap="outerHTML"'), '...its switch on, posting to show on change, swapping the tile');

echo "4. Who sees the switch\n";
$g26 = page(actor(26), '/f/' . $media . '?view=grid')['body'];
ok(str_contains(tile_html($g26, 'document', $ids['png']), 'Hide preview'), 'Priya (edit) sees the switch');
ok(!str_contains(tile_html($g26, 'document', $ids['docx']), 'Hide preview') && !str_contains(tile_html($g26, 'document', $ids['pdf']), 'Hide preview'), '...only on images and videos (not a DOCX, not a PDF)');
$g28 = page(actor(28), '/f/' . $media . '?view=grid')['body'];
ok(str_contains($g28, 'id="tile-document-' . $ids['png'] . '"') && !str_contains($g28, 'Hide preview'), 'a viewer (28) sees the tiles and no switch');
ok(str_contains(tile_html($g28, 'document', $hidden), 'tile-placeholder') && !str_contains(tile_html($g28, 'document', $hidden), '<img'), '...and the hidden JPEG as a placeholder too: hidden for everyone');

echo "5. Back to the list, and JSON\n";
$r = page($jar, '/f/' . $media . '?view=list');
ok(str_contains($r['body'], 'id="listing-table"') && preg_match('/^Set-Cookie: dc_view=list/mi', $r['headers']) === 1, '?view=list goes back and is remembered');
$j = get_json(27, '/f/' . $media);
$row = array_values(array_filter($j['json']['data']['listing']['rows'] ?? $j['json']['listing']['rows'] ?? [], fn ($x) => $x['id'] === $hidden))[0] ?? [];
ok(($row['preview_hidden'] ?? null) === true && ($row['has_thumbnail'] ?? null) === true && ($row['mime_type'] ?? '') === 'image/jpeg', 'JSON rows carry preview_hidden, has_thumbnail, mime_type');
$mrow = array_values(array_filter($j['json']['data']['listing']['rows'] ?? $j['json']['listing']['rows'] ?? [], fn ($x) => $x['id'] === $ids['mp4']))[0] ?? [];
ok(abs((int) ($mrow['duration_ms'] ?? 0) - 3000) <= 300, '...and a video\'s duration_ms');
finish();
