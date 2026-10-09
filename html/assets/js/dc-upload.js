/**
 * The uploader (docs/build-specs/uploads-versions.md "The flow of an upload"; uploads-and-versions.md §2): files and dropped folders
 * (webkitGetAsEntry walks a tree; a subfolder becomes folder_create first), each file sliced into parts of part_bytes, at most three in
 * flight, a failed part retried thrice, then finish; a 409 at the start or the finish asks inline — New version · Keep both · Skip; a
 * refusal shows its sentence. Routes: POST /upload/start (JSON), POST /upload/part?session=&n= (the raw bytes), POST /upload/finish.
 * fetch with X-CSRF-Token; nothing of the file is read into memory beyond one part.
 * Events on the wrap element: dc-upload:done {document_id, name}, dc-upload:all-done {count}.
 */
window.DcUpload = (function () {
  'use strict';
  var wrap, list, summary, csrf, folderId, documentId, partBytes, maxBytes, queue = [], active = 0, finished = 0, rowNo = 0, folderCache = {};
  var MAX_INFLIGHT = 3;

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function fmt(n) { var u = ['B', 'KB', 'MB', 'GB']; var i = 0; var v = n; while (v >= 1024 && i < 3) { v /= 1024; i++; } return (i ? v.toFixed(1) : v) + ' ' + u[i]; }
  function headers(extra) { var h = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }; if (extra) { for (var k in extra) { h[k] = extra[k]; } } return h; }
  function postForm(url, fields) {
    var body = new URLSearchParams(); for (var k in fields) { if (fields[k] !== undefined && fields[k] !== null && fields[k] !== '') body.append(k, fields[k]); }
    return fetch(url, { method: 'POST', headers: headers({ 'Content-Type': 'application/x-www-form-urlencoded' }), body: body.toString(), credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); });
  }
  function state(row, cls, text) { row.badge.className = 'badge bg-soft-' + cls + ' text-' + cls + ' upload-state'; row.badge.textContent = text; row.el.dataset.state = text.split(' ')[0]; }
  function progress(row, pct) { row.bar.style.width = Math.max(0, Math.min(100, pct)) + '%'; row.bar.setAttribute('aria-valuenow', String(Math.round(pct))); }
  function summarize() { var n = queue.length; summary.textContent = n ? (finished + ' of ' + n + ' done') : ''; if (n && finished === n) { wrap.dispatchEvent(new CustomEvent('dc-upload:all-done', { detail: { count: n } })); } }

  function addRow(file, relPath) {
    rowNo++;
    var row = { file: file, relPath: relPath || '', n: rowNo, folder: null, documentId: documentId || null, mode: '', retries: {} };
    var li = el('div', 'list-group-item upload-row'); li.id = 'upload-row-' + rowNo; li.dataset.name = file.name;
    var top = el('div', 'd-flex align-items-center gap-2 min-w-0');
    var name = el('div', 'fw-semibold text-truncate flex-grow-1 min-w-0 upload-name', (relPath ? relPath + '/' : '') + file.name);
    var size = el('span', 'fs-11 text-muted text-nowrap', fmt(file.size));
    row.badge = el('span', 'badge bg-soft-secondary text-secondary upload-state', 'queued');
    top.appendChild(name); top.appendChild(size); top.appendChild(row.badge);
    var prog = el('div', 'progress mt-1'); prog.style.height = '6px';
    row.bar = el('div', 'progress-bar'); row.bar.setAttribute('role', 'progressbar'); row.bar.style.width = '0%'; prog.appendChild(row.bar);
    row.msg = el('div', 'fs-11 text-muted mt-1 upload-msg');
    row.choice = el('div', 'd-flex gap-1 mt-1 d-none upload-conflict');
    li.appendChild(top); li.appendChild(prog); li.appendChild(row.msg); li.appendChild(row.choice);
    row.el = li; list.appendChild(li);
    queue.push(row);
    return row;
  }

  function ensureFolder(relPath) {
    if (!relPath) return Promise.resolve(folderId);
    if (folderCache[relPath]) return folderCache[relPath];
    var parts = relPath.split('/'); var parentRel = parts.slice(0, -1).join('/'); var name = parts[parts.length - 1];
    folderCache[relPath] = ensureFolder(parentRel).then(function (parent) {
      return postForm('/folders/save.php', { parent: parent, name: name }).then(function (r) {
        if (r.status === 200 && r.json.record_id) return r.json.record_id;
        if (r.status === 422 && /already here/.test((r.json.error && r.json.error.message) || '')) {
          // it exists: find it through the picker endpoint
          return fetch('/pick/?source=folder&q=' + encodeURIComponent(name) + '&library=', { headers: headers(), credentials: 'same-origin' }).then(function (x) { return x.json(); })
            .then(function (j) { var rows = (j.rows || []).filter(function (f) { return (f.label || '').split(' › ').pop() === name; }); if (rows.length) return rows[0].id; throw new Error('Cannot find the folder ' + name); });
        }
        throw new Error((r.json.error && r.json.error.message) || 'Cannot make the folder ' + name);
      });
    });
    return folderCache[relPath];
  }

  function sendPart(row, session, n, attempt) {
    var start = (n - 1) * session.part_bytes; var blob = row.file.slice(start, Math.min(row.file.size, start + session.part_bytes));
    return fetch('/upload/part?session=' + session.session_id + '&n=' + n, { method: 'POST', headers: headers({ 'Content-Type': 'application/octet-stream' }), body: blob, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (r.status !== 200) { throw Object.assign(new Error((j.error && j.error.message) || 'part ' + n + ' failed'), { status: r.status }); } return j; }); })
      .catch(function (e) { if ((attempt || 0) < 3 && !(e.status && e.status < 500 && e.status !== 0)) { return new Promise(function (res) { setTimeout(res, 500 * ((attempt || 0) + 1)); }).then(function () { return sendPart(row, session, n, (attempt || 0) + 1); }); } throw e; });
  }

  function runSession(row, session) {
    var total = session.parts_total; var done = 0;
    if (total === 0) { progress(row, 100); state(row, 'info', 'assembling'); return finish(row, session, ''); }
    state(row, 'info', 'uploading 0/' + total);
    var next = 1; var inflight = 0; var failed = null;
    return new Promise(function (resolve, reject) {
      function pump() {
        if (failed) { if (inflight === 0) reject(failed); return; }
        if (next > total && inflight === 0) { return resolve(); }
        while (inflight < MAX_INFLIGHT && next <= total) {
          (function (n) { inflight++; sendPart(row, session, n).then(function () { inflight--; done++; progress(row, done / total * 100); state(row, 'info', 'uploading ' + done + '/' + total); pump(); }, function (e) { inflight--; failed = e; pump(); }); })(next++);
        }
      }
      pump();
    }).then(function () { state(row, 'info', 'assembling'); return finish(row, session, ''); });
  }

  function finish(row, session, conflict) {
    return postForm('/upload/finish', { session: session.session_id, conflict: conflict }).then(function (r) {
      if (r.status === 200 && r.json.ok) {
        progress(row, 100); state(row, 'success', 'done');
        var a = el('a', 'fw-semibold', r.json.new_document ? 'Open' : 'Open (version ' + r.json.version_no + ')'); a.href = '/documents/' + r.json.record_id; a.id = 'upload-open-' + row.n;
        row.msg.textContent = (r.json.deduplicated ? 'Already stored once: de-duplicated. ' : '') + (r.json.name !== row.file.name ? 'Stored as ' + r.json.name + '. ' : '');
        row.msg.appendChild(a);
        wrap.dispatchEvent(new CustomEvent('dc-upload:done', { detail: { document_id: r.json.record_id, name: r.json.name } }));
        return;
      }
      if (r.status === 409 && r.json.error && r.json.error.code === 'name_taken') { return askConflict(row, session, r.json.error); }
      throw new Error((r.json.error && r.json.error.message) || 'The upload could not be finished.');
    });
  }

  function askConflict(row, session, err) {
    state(row, 'warning', 'conflict'); row.msg.textContent = err.message + ' ';
    row.choice.className = 'd-flex gap-1 mt-1 flex-wrap upload-conflict';
    row.choice.innerHTML = '';
    return new Promise(function (resolve) {
      function button(label, id, fn) { var b = el('button', 'btn btn-sm btn-light btn-touch', label); b.type = 'button'; b.id = id + '-' + row.n; b.addEventListener('click', function () { row.choice.className = 'd-none'; fn(); }); row.choice.appendChild(b); }
      button('New version', 'upload-conflict-version', function () { resolve(session ? finish(row, session, 'version') : startFile(row, 'version', err.existing_document_id)); });
      button('Keep both', 'upload-conflict-both', function () { resolve(session ? finish(row, session, 'keep_both') : startFile(row, 'keep_both')); });
      button('Skip', 'upload-conflict-skip', function () { state(row, 'secondary', 'skipped'); row.msg.textContent = 'Skipped.'; resolve(); });
    });
  }

  function startFile(row, mode, versionOf) {
    return ensureFolder(row.relPath).then(function (fid) {
      row.folder = fid;
      return postForm('/upload/start', { folder_id: fid, filename: row.file.name, byte_size: row.file.size, mime_hint: row.file.type || '', document_id: versionOf || row.documentId || '', mode: mode || '' });
    }).then(function (r) {
      if (r.status === 200 && r.json.ok) { return runSession(row, r.json); }
      if (r.status === 409 && r.json.error && r.json.error.code === 'name_taken') { return askConflict(row, null, r.json.error); }
      throw new Error((r.json.error && r.json.error.message) || 'The upload could not start.');
    });
  }

  function runQueue() {
    var pending = queue.filter(function (r) { return !r.started; });
    if (!pending.length) return;
    var row = pending[0]; row.started = true;
    startFile(row, '', null).catch(function (e) { state(row, 'danger', 'refused'); row.msg.textContent = e.message || String(e); })
      .then(function () { finished++; summarize(); runQueue(); });
  }

  function addFiles(files, relPaths) {
    var any = false;
    for (var i = 0; i < files.length; i++) {
      var f = files[i]; if (!f || (f.size === 0 && f.type === '')) continue;
      var rel = relPaths ? relPaths[i] : (f.webkitRelativePath ? f.webkitRelativePath.split('/').slice(0, -1).join('/') : '');
      if (maxBytes && f.size > maxBytes) { var r = addRow(f, rel); state(r, 'danger', 'refused'); r.msg.textContent = 'Over the ceiling (' + fmt(maxBytes) + ').'; r.started = true; finished++; continue; }
      addRow(f, rel); any = true;
    }
    summarize();
    if (any) { runQueue(); runQueue(); }
  }

  function walkEntries(items) {
    var files = [], paths = [];
    function readEntry(entry, path) {
      return new Promise(function (resolve) {
        if (entry.isFile) { entry.file(function (f) { files.push(f); paths.push(path); resolve(); }, function () { resolve(); }); }
        else if (entry.isDirectory) {
          var reader = entry.createReader(); var all = [];
          (function readMore() { reader.readEntries(function (batch) { if (!batch.length) { Promise.all(all.map(function (e) { return readEntry(e, (path ? path + '/' : '') + entry.name); })).then(resolve); return; } all = all.concat(batch); readMore(); }, function () { resolve(); }); })();
        } else { resolve(); }
      });
    }
    var entries = [];
    for (var i = 0; i < items.length; i++) { var e = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null; if (e) entries.push(e); }
    return Promise.all(entries.map(function (e) { return readEntry(e, ''); })).then(function () { return { files: files, paths: paths }; });
  }

  function mount(root) {
    if (!root || root.dataset.mounted) return; root.dataset.mounted = '1';
    wrap = root; list = root.querySelector('#upload-list'); summary = root.querySelector('#upload-summary'); csrf = root.dataset.csrf;
    folderId = parseInt(root.dataset.folder, 10); documentId = root.dataset.document ? parseInt(root.dataset.document, 10) : null;
    partBytes = parseInt(root.dataset.partBytes, 10) || 8388608; maxBytes = parseInt(root.dataset.maxBytes, 10) || 0;
    var zone = root.querySelector('#drop-zone'); var fileInput = root.querySelector('#upload-files-input'); var dirInput = root.querySelector('#upload-folder-input');
    fileInput.addEventListener('change', function () { addFiles(fileInput.files); fileInput.value = ''; });
    if (dirInput) dirInput.addEventListener('change', function () { addFiles(dirInput.files); dirInput.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('border-primary'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('border-primary'); }); });
    zone.addEventListener('drop', function (e) {
      var dt = e.dataTransfer; if (!dt) return;
      if (dt.items && dt.items.length && dt.items[0].webkitGetAsEntry) { walkEntries(dt.items).then(function (r) { addFiles(r.files, r.paths); }); }
      else { addFiles(dt.files); }
    });
    zone.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); } });
  }
  return { mount: mount, addFiles: addFiles };
})();
