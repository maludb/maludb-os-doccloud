<?php /** The request page: who asks, the message, a name field, the drop zone (one file per request); nothing of the folder. Data: link, base, maxBytes */ ?>
<div class="card" id="public-request"><div class="card-body">
    <h5 class="mb-1" id="public-request-title"><?= e($link['made_by_name']) ?> is asking for files</h5>
    <div class="fs-12 text-muted mb-2"><?= e($link['label']) ?></div>
    <?php if (!empty($link['note'])): ?><div class="alert alert-light fs-12" id="public-request-message"><?= nl2br(e($link['note'])) ?></div><?php endif; ?>
    <form method="post" action="<?= e($base) ?>/upload" enctype="multipart/form-data" id="public-request-form"><?= csrf_field() ?>
        <label class="form-label" for="public-request-name">Your name (optional)</label>
        <input type="text" name="name" id="public-request-name" class="form-control btn-touch mb-3" maxlength="120" autocomplete="name">
        <div id="request-drop-zone" class="card border-dashed text-center p-4 mb-2" tabindex="0" role="button" aria-label="Drop files here, or choose">
            <i class="feather-upload-cloud fs-1 text-muted"></i>
            <div class="fw-semibold mt-1">Drop files here, or choose</div>
            <div class="fs-11 text-muted mt-1">Up to <?= e(format_bytes((int) $maxBytes)) ?> a file.</div>
            <input type="file" name="file" id="public-request-file" class="form-control btn-touch mt-2" multiple>
        </div>
        <div id="public-request-list" class="list-group mb-2" aria-live="polite"></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="public-request-send">Send</button>
    </form>
</div></div>
<script>
(function () {
  var form = document.getElementById('public-request-form'), input = document.getElementById('public-request-file'), list = document.getElementById('public-request-list'), zone = document.getElementById('request-drop-zone');
  var csrf = document.getElementById('csrf-token-meta').getAttribute('content'); var sent = 0, total = 0;
  function row(name) { var li = document.createElement('div'); li.className = 'list-group-item fs-12 d-flex justify-content-between'; li.innerHTML = '<span class="text-truncate"></span><span class="badge bg-soft-secondary text-secondary">sending</span>'; li.firstChild.textContent = name; list.appendChild(li); return li; }
  function send(file) {
    total++; var li = row(file.name); var fd = new FormData(); fd.append('csrf_token', csrf); fd.append('name', document.getElementById('public-request-name').value); fd.append('file', file);
    return fetch(form.action, { method: 'POST', body: fd, headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); })
      .then(function (r) { var b = li.lastChild; if (r.status === 200) { sent++; b.className = 'badge bg-soft-success text-success'; b.textContent = 'sent'; } else { b.className = 'badge bg-soft-danger text-danger'; b.textContent = (r.json.error && r.json.error.message) || 'not sent'; } })
      .catch(function () { var b = li.lastChild; b.className = 'badge bg-soft-danger text-danger'; b.textContent = 'not sent'; });
  }
  function all(files) { var p = Promise.resolve(); Array.prototype.forEach.call(files, function (f) { p = p.then(function () { return send(f); }); }); p.then(function () { if (sent > 0 && sent === total) { window.location.href = window.location.pathname + '?done=1'; } }); }
  form.addEventListener('submit', function (e) { if (!window.fetch || !input.files.length) return; e.preventDefault(); all(input.files); input.value = ''; });
  zone.addEventListener('dragover', function (e) { e.preventDefault(); zone.classList.add('border-primary'); });
  zone.addEventListener('dragleave', function () { zone.classList.remove('border-primary'); });
  zone.addEventListener('drop', function (e) { e.preventDefault(); zone.classList.remove('border-primary'); if (e.dataTransfer && e.dataTransfer.files.length) all(e.dataTransfer.files); });
})();
</script>
