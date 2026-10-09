<?php /** The folder's crumbs: every one a link but the last. Data: crumbs ([{folder_id, name, depth}]), here (the folder id) */ ?>
<ul class="breadcrumb mb-0" id="folder-crumbs">
<?php foreach ($crumbs as $c): $cid = (int) $c['folder_id']; ?>
    <li class="breadcrumb-item<?= $cid === (int) $here ? ' active' : '' ?>" id="folder-crumb-<?= $cid ?>"><?= $cid === (int) $here ? e($c['name']) : hx_link('/f/' . $cid, e($c['name'])) ?></li>
<?php endforeach; ?>
</ul>
