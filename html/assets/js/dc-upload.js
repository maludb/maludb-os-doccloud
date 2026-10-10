/**
 * The uploader (docs/build-specs/uploads-versions.md "The flow of an upload"; uploads-and-versions.md §2; large-uploads.md): files and dropped
 * folders (webkitGetAsEntry walks a tree; a subfolder becomes folder_create first), each file sliced into parts of part_bytes and sent in small
 * pieces like KineticSeas' php-chunk-upload — the server writes each at its offset in one file, so a file of any size goes up — at most three in
 * flight, a part that fails on the network or a 5xx retried six times over about a minute, then finish; a 409 at the start or the finish asks
 * inline — New version · Keep both · Skip; a refusal shows its sentence. A file that cannot carry on now (the connection gone, the store full)
 * is PAUSED with a Resume button — and resumes by itself when the browser comes back online; choosing the same file again later (even after
 * the page was closed) carries on where it stopped: the start sends a resume_key (sha256 of the name, size, modified time and the first and
 * last megabyte) and the server answers the parts it already holds. Leaving the page while a file is going up asks first.
 * Routes: POST /upload/start (JSON), POST /upload/part?session=&n= (the raw bytes), POST /upload/finish. fetch with X-CSRF-Token; nothing of
 * the file is read into memory beyond one part (and the two megabytes of the key).
 * Events on the wrap element: dc-upload:done {document_id, name}, dc-upload:all-done {count} (when nothing is queued or going up).
 */
window.DcUpload = (function () {
  'use strict';
  var wrap, list, summary, csrf, folderId, documentId, partBytes, maxBytes, queue = [], rowNo = 0, folderCache = {};
  var MAX_INFLIGHT = 3, MAX_FILES = 2;
  var RETRY_WAITS = [1000, 2000, 4000, 8000, 16000, 30000];
  var RETRYABLE = [0, 408, 429, 500, 502, 503, 504];

  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
  function fmt(n) { var u = ['B', 'KB', 'MB', 'GB', 'TB']; var i = 0; var v = n; while (v >= 1024 && i < 4) { v /= 1024; i++; } return (i ? v.toFixed(1) : v) + ' ' + u[i]; }
  function headers(extra) { var h = { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }; if (extra) { for (var k in extra) { h[k] = extra[k]; } } return h; }
  function postForm(url, fields) {
    var body = new URLSearchParams(); for (var k in fields) { if (fields[k] !== undefined && fields[k] !== null && fields[k] !== '') body.append(k, fields[k]); }
    return fetch(url, { method: 'POST', headers: headers({ 'Content-Type': 'application/x-www-form-urlencoded' }), body: body.toString(), credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }, function () { return { status: r.status, json: {} }; }); });
  }
  function failure(message, status, code) { return Object.assign(new Error(message), { status: status || 0, code: code || '' }); }
  function errorOf(r, fallback) { var e = r.json && r.json.error; return failure((e && e.message) || fallback, r.status, e && e.code); }
  function state(row, cls, text) { row.badge.className = 'badge bg-soft-' + cls + ' text-' + cls + ' upload-state'; row.badge.textContent = text; row.el.dataset.state = text.split(' ')[0]; }
  function progress(row, pct) { row.bar.style.width = Math.max(0, Math.min(100, pct)) + '%'; row.bar.setAttribute('aria-valuenow', String(Math.round(pct))); }
  function busy() { return queue.some(function (r) { return r.running || !r.started; }); }
  function summarize() {
    var n = queue.length; var done = queue.filter(function (r) { return r.final; }).length; var paused = queue.filter(function (r) { return r.paused && !r.running; }).length;
    summary.textContent = n ? (done + ' of ' + n + ' done' + (paused ? ' · ' + paused + ' paused' : '')) : '';
    if (n && !busy()) { wrap.dispatchEvent(new CustomEvent('dc-upload:all-done', { detail: { count: done } })); }
  }

  function addRow(file, relPath) {
    rowNo++;
    var row = { file: file, relPath: relPath || '', n: rowNo, folder: null, documentId: documentId || null, mode: '', versionOf: null, key: null };
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

  /** The file's fingerprint for a resume: sha256 of name, size, modified time and the first and last megabyte ('' when the browser cannot). */
  function resumeKey(row) {
    if (row.key !== null) return Promise.resolve(row.key);
    var f = row.file;
    if (!(window.crypto && crypto.subtle && window.TextEncoder && f.slice && Blob.prototype.arrayBuffer)) { row.key = ''; return Promise.resolve(''); }
    var mb = 1048576;
    return Promise.all([f.slice(0, mb).arrayBuffer(), f.slice(Math.max(0, f.size - mb)).arrayBuffer()]).then(function (b) {
      var meta = new TextEncoder().encode(f.name + '\n' + f.size + '\n' + (f.lastModified || 0) + '\n');
      var all = new Uint8Array(meta.length + b[0].byteLength + b[1].byteLength);
      all.set(meta, 0); all.set(new Uint8Array(b[0]), meta.length); all.set(new Uint8Array(b[1]), meta.length + b[0].byteLength);
      return crypto.subtle.digest('SHA-256', all);
    }).then(function (d) { row.key = Array.prototype.map.call(new Uint8Array(d), function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); return row.key; },
      function () { row.key = ''; return ''; });
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
        throw errorOf(r, 'Cannot make the folder ' + name);
      });
    });
    folderCache[relPath].catch(function () { delete folderCache[relPath]; });
    return folderCache[relPath];
  }

  function sendPart(row, session, n, attempt) {
    attempt = attempt || 0;
    var start = (n - 1) * session.part_bytes; var blob = row.file.slice(start, Math.min(row.file.size, start + session.part_bytes));
    return fetch('/upload/part?session=' + session.session_id + '&n=' + n, { method: 'POST', headers: headers({ 'Content-Type': 'application/octet-stream' }), body: blob, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }, function () { return { status: r.status, json: {} }; }); },
        function () { throw failure('The connection dropped.', 0); })
      .then(function (r) { if (r.status !== 200) { throw errorOf(r, 'Part ' + n + ' failed.'); } return r.json; })
      .catch(function (e) {
        if (attempt < RETRY_WAITS.length && RETRYABLE.indexOf(e.status) >= 0 && !row.stopping) {
          state(row, 'warning', 'retrying ' + (row.done || 0) + '/' + session.parts_total);
          return new Promise(function (res) { setTimeout(res, RETRY_WAITS[attempt]); }).then(function () { return sendPart(row, session, n, attempt + 1); });
        }
        throw e;
      });
  }

  function runSession(row, session) {
    var total = session.parts_total;
    if (total === 0) { progress(row, 100); state(row, 'info', 'finishing'); return finish(row, session, ''); }
    var have = {}; (session.parts_received || []).forEach(function (p) { have[p] = true; });
    row.done = Object.keys(have).length; row.stopping = false;
    progress(row, row.done / total * 100);
    state(row, 'info', 'uploading ' + row.done + '/' + total);
    if (session.resumed) { row.msg.textContent = 'Carrying on from ' + Math.round(row.done / total * 100) + '%: ' + row.done + ' of ' + total + ' parts were already here.'; }
    var next = 1; var inflight = 0; var failed = null;
    return new Promise(function (resolve, reject) {
      function pump() {
        if (failed) { if (inflight === 0) reject(failed); return; }
        while (next <= total && have[next]) next++;
        if (next > total && inflight === 0) { return resolve(); }
        while (inflight < MAX_INFLIGHT && next <= total) {
          (function (n) {
            inflight++;
            sendPart(row, session, n).then(function () { inflight--; have[n] = true; row.done++; progress(row, row.done / total * 100); state(row, 'info', 'uploading ' + row.done + '/' + total); pump(); },
              function (e) { inflight--; failed = failed || e; row.stopping = true; pump(); });
          })(next++);
          while (next <= total && have[next]) next++;
        }
      }
      pump();
    }).then(function () { state(row, 'info', 'finishing'); return finish(row, session, ''); });
  }

  function finish(row, session, conflict) {
    return postForm('/upload/finish', { session: session.session_id, conflict: conflict }).then(function (r) {
      if (r.status === 200 && r.json.ok) {
        progress(row, 100); state(row, 'success', 'done'); row.final = true; row.paused = false;
        var a = el('a', 'fw-semibold', r.json.new_document ? 'Open' : 'Open (version ' + r.json.version_no + ')'); a.href = '/documents/' + r.json.record_id; a.id = 'upload-open-' + row.n;
        row.msg.textContent = (r.json.deduplicated ? 'Already stored once: de-duplicated. ' : '') + (r.json.name !== row.file.name ? 'Stored as ' + r.json.name + '. ' : '');
        row.msg.appendChild(a);
        wrap.dispatchEvent(new CustomEvent('dc-upload:done', { detail: { document_id: r.json.record_id, name: r.json.name } }));
        return;
      }
      if (r.status === 409 && r.json.error && r.json.error.code === 'name_taken') { return askConflict(row, session, r.json.error); }
      throw errorOf(r, 'The upload could not be finished.');
    }, function () { throw failure('The connection dropped.', 0); });
  }

  function askConflict(row, session, err) {
    state(row, 'warning', 'conflict'); row.msg.textContent = err.message + ' ';
    row.choice.className = 'd-flex gap-1 mt-1 flex-wrap upload-conflict';
    row.choice.innerHTML = '';
    return new Promise(function (resolve) {
      function button(label, id, fn) { var b = el('button', 'btn btn-sm btn-light btn-touch', label); b.type = 'button'; b.id = id + '-' + row.n; b.addEventListener('click', function () { row.choice.className = 'd-none'; fn(); }); row.choice.appendChild(b); }
      button('New version', 'upload-conflict-version', function () { row.mode = 'version'; row.versionOf = err.existing_document_id || null; resolve(session ? finish(row, session, 'version') : startFile(row)); });
      button('Keep both', 'upload-conflict-both', function () { row.mode = 'keep_both'; resolve(session ? finish(row, session, 'keep_both') : startFile(row)); });
      button('Skip', 'upload-conflict-skip', function () { state(row, 'secondary', 'skipped'); row.final = true; row.msg.textContent = 'Skipped.'; resolve(); });
    });
  }

  function startFile(row) {
    return Promise.all([ensureFolder(row.relPath), resumeKey(row)]).then(function (v) {
      row.folder = v[0];
      return postForm('/upload/start', { folder_id: v[0], filename: row.file.name, byte_size: row.file.size, mime_hint: row.file.type || '', document_id: row.versionOf || row.documentId || '', mode: row.mode || '', resume_key: v[1] })
        .then(null, function () { throw failure('The connection dropped.', 0); });
    }).then(function (r) {
      if (r.status === 200 && r.json.ok) { return runSession(row, r.json); }
      if (r.status === 409 && r.json.error && r.json.error.code === 'name_taken') { return askConflict(row, null, r.json.error); }
      throw errorOf(r, 'The upload could not start.');
    });
  }

  /**
   * What went wrong decides: the network, a busy server, a full store or a session that can no longer take parts (expired, begun before an
   * update — Resume starts it afresh) pause the file; anything else is a refusal in its words.
   */
  function stopped(row, e) {
    var pausable = RETRYABLE.indexOf(e.status || 0) >= 0 || e.status === 507 || e.status === 410 || e.code === 'restart';
    if (!pausable) { state(row, 'danger', 'refused'); row.final = true; row.paused = false; row.msg.textContent = e.message || String(e); return; }
    row.paused = true;
    state(row, 'warning', 'paused');
    row.msg.textContent = (e.message || 'The connection dropped.') + ' ' + (row.done ? row.done + ' parts are safe on the server. ' : '');
    var b = el('button', 'btn btn-sm btn-primary btn-touch', 'Resume'); b.type = 'button'; b.id = 'upload-resume-' + row.n;
    b.addEventListener('click', function () { resume(row); });
    row.msg.appendChild(b);
  }

  function run(row) {
    row.started = true; row.running = true;
    return startFile(row).catch(function (e) { stopped(row, e); })
      .then(function () { row.running = false; summarize(); runQueue(); });
  }

  function resume(row) {
    if (row.running || !row.paused) return;
    row.paused = false; row.msg.textContent = ''; state(row, 'info', 'resuming');
    run(row);
  }

  function runQueue() {
    var running = queue.filter(function (r) { return r.running; }).length;
    var pending = queue.filter(function (r) { return !r.started; });
    while (running < MAX_FILES && pending.length) { run(pending.shift()); running++; }
  }

  function addFiles(files, relPaths) {
    for (var i = 0; i < files.length; i++) {
      var f = files[i]; if (!f || (f.size === 0 && f.type === '')) continue;
      var rel = relPaths ? relPaths[i] : (f.webkitRelativePath ? f.webkitRelativePath.split('/').slice(0, -1).join('/') : '');
      if (maxBytes && f.size > maxBytes) { var r = addRow(f, rel); state(r, 'danger', 'refused'); r.msg.textContent = 'Over the limit an admin set (' + fmt(maxBytes) + ').'; r.started = true; r.final = true; continue; }
      addRow(f, rel);
    }
    summarize();
    runQueue();
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
    window.addEventListener('online', function () { queue.forEach(function (r) { if (r.paused) resume(r); }); });
    window.addEventListener('beforeunload', function (e) { if (queue.some(function (r) { return r.running; })) { e.preventDefault(); e.returnValue = ''; return ''; } });
  }
  return { mount: mount, addFiles: addFiles };
})();
