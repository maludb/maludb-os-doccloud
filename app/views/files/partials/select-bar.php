<?php /** The multi-select bar above a listing (hidden until a row is ticked). Data: folderId, may (edit, manage) */ ?>
<form method="post" action="/folders/move.php" id="select-bar" class="select-bar d-none align-items-center gap-2 flex-wrap mb-2 p-2 border rounded bg-light" hx-post="/folders/move.php" hx-target="#flash">
    <?= csrf_field() ?><input type="hidden" name="return_to" value="/f/<?= (int) $folderId ?>">
    <span class="fs-12 fw-semibold" id="select-bar-count">0 selected</span>
    <?php if (!empty($may['edit'])): ?>
        <div class="d-flex align-items-center gap-1 flex-grow-1" style="max-width: 420px">
            <?= picker_field(['id' => 'select-bar-target', 'name' => 'target_folder', 'source' => 'folder', 'placeholder' => 'Move the selected folders to…', 'title' => 'Move to', 'required' => true]) ?>
            <button type="submit" class="btn btn-primary btn-touch" id="select-bar-move-btn">Move</button>
        </div>
    <?php endif; ?>
    <button type="button" class="btn btn-light btn-touch ms-auto" id="select-bar-clear-btn">Clear</button>
</form>
