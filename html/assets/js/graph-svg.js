// A subject's neighbourhood as a small inline SVG (slice 5): the subject in the middle, its neighbouring subjects as circles, its documents as
// squares, at most 40 nodes, drawn from the JSON in #graph-svg-data; no library; a click navigates. Shown at 1280 only (the list serves 375).
(function () {
  var dataEl = document.getElementById('graph-svg-data'), svg = document.getElementById('graph-svg');
  if (!dataEl || !svg) return;
  var d; try { d = JSON.parse(dataEl.textContent); } catch (e) { return; }
  var NS = 'http://www.w3.org/2000/svg', W = 400, H = 280, cx = W / 2, cy = H / 2;
  var nodes = [].concat(d.subjects.map(function (s) { return Object.assign({ kind: 'subject' }, s); }), d.documents.map(function (x) { return Object.assign({ kind: 'document' }, x); })).slice(0, 39);
  function el(name, attrs, text) { var e = document.createElementNS(NS, name); Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); }); if (text) e.textContent = text; return e; }
  function short(s) { return s.length > 18 ? s.slice(0, 17) + '…' : s; }
  var r = Math.min(cx, cy) - 36;
  nodes.forEach(function (n, i) {
    var a = (2 * Math.PI * i) / Math.max(1, nodes.length) - Math.PI / 2;
    n.x = cx + r * Math.cos(a); n.y = cy + r * Math.sin(a);
    svg.appendChild(el('line', { x1: cx, y1: cy, x2: n.x, y2: n.y, stroke: '#cbd5e1', 'stroke-width': 1 }));
  });
  nodes.forEach(function (n) {
    var a = el('a', { href: n.url, 'class': 'graph-svg-node graph-svg-' + n.kind }); a.setAttribute('id', 'graph-svg-' + n.kind + '-' + n.id);
    a.appendChild(n.kind === 'subject' ? el('circle', { cx: n.x, cy: n.y, r: 7, fill: '#3454d1' }) : el('rect', { x: n.x - 6, y: n.y - 6, width: 12, height: 12, fill: '#6b7280' }));
    a.appendChild(el('title', {}, n.name));
    a.appendChild(el('text', { x: n.x, y: n.y + 18, 'text-anchor': 'middle', 'font-size': '9', fill: '#334155' }, short(n.name)));
    svg.appendChild(a);
  });
  var c = el('g', { id: 'graph-svg-centre' });
  c.appendChild(el('circle', { cx: cx, cy: cy, r: 11, fill: '#0ea5e9' }));
  c.appendChild(el('text', { x: cx, y: cy + 24, 'text-anchor': 'middle', 'font-size': '10', 'font-weight': 'bold', fill: '#0f172a' }, short(d.centre.name)));
  svg.appendChild(c);
})();
