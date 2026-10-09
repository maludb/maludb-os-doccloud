/* The tag editor (slice 4): Enter in the chip input submits the add form (HTMX posts tag_apply and the page lands on the document);
   a datalist suggestion chosen submits too; the select bar's Tag field posts tag_apply with the ticked documents. */
(function () {
  function wire(root) {
    var input = root.querySelector('#tag-input');
    if (input && !input.dataset.wired) {
      input.dataset.wired = '1';
      input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); if (input.value.trim() !== '') { var f = input.form; if (f) { f.requestSubmit ? f.requestSubmit() : f.submit(); } } } });
      input.addEventListener('change', function () { var o = document.querySelector('#tag-vocabulary option[value="' + input.value.replace(/"/g, '\\"') + '"]'); if (o && input.value.trim() !== '') { var f = input.form; if (f) { f.requestSubmit ? f.requestSubmit() : f.submit(); } } });
    }
    var bulk = root.querySelector('#select-bar-tag-input');
    if (bulk && !bulk.dataset.wired) {
      bulk.dataset.wired = '1';
      bulk.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var b = document.getElementById('select-bar-tag-btn'); if (b) b.click(); } });
    }
  }
  wire(document);
  document.body.addEventListener('htmx:afterSwap', function (e) { wire(e.target || document); });
})();
