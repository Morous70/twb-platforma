/* TWB Reklamy – měření konverzí na webu inzerenta.
   Na všech stránkách:  <script async src="https://…/d/t.js"></script>
   Po objednávce:       <script>window.twbr = window.twbr || []; twbr.push({ tracker: 1, hodnota: '1234.50', id: 'OBJ-1' });</script>
   Skript si zapamatuje parametr twbr z adresy (proklik z reklamy) a pošle ho s konverzí. */
(function (w, d) {
  'use strict';
  var s = d.currentScript;
  var zaklad = s && s.src ? s.src.replace(/t\.js(\?.*)?$/, '') : '';
  var KLIC = 'twbr', DNI = 90, RE = /^[0-9]{1,12}\.[a-f0-9]{10}$/;
  function uloz(t) {
    try { w.localStorage.setItem(KLIC, t + '|' + Date.now()); } catch (e) { /* soukromý režim */ }
    try { d.cookie = KLIC + '=' + t + ';path=/;max-age=' + DNI * 86400 + ';SameSite=Lax'; } catch (e) { /* bez cookies */ }
  }
  function nacti() {
    var t = '';
    try {
      var v = (w.localStorage.getItem(KLIC) || '').split('|');
      if (RE.test(v[0]) && Date.now() - (+v[1] || 0) < DNI * 864e5) t = v[0];
    } catch (e) { /* bez úložiště */ }
    if (!t) { var m = /(?:^|;\s*)twbr=([0-9]+\.[a-f0-9]+)/.exec(d.cookie || ''); if (m && RE.test(m[1])) t = m[1]; }
    return t;
  }
  var p = /[?&]twbr=([0-9]+\.[a-f0-9]+)/.exec(w.location.search);
  if (p && RE.test(p[1])) uloz(p[1]);
  function odesli(k) {
    if (!k || !k.tracker || !zaklad) return;
    var q = '?t=' + encodeURIComponent(k.tracker) + '&twbr=' + encodeURIComponent(nacti())
      + (k.hodnota != null && k.hodnota !== '' ? '&hodnota=' + encodeURIComponent(k.hodnota) : '')
      + (k.id ? '&id=' + encodeURIComponent(k.id) : '') + '&cb=' + Math.random().toString(36).slice(2);
    var i = new Image(1, 1);
    i.src = zaklad + 'konverze.php' + q;
    (w.twbrOdeslano = w.twbrOdeslano || []).push(i);
  }
  var fronta = w.twbr && w.twbr.length ? w.twbr : [];
  w.twbr = { push: odesli };
  for (var i = 0; i < fronta.length; i++) odesli(fronta[i]);
})(window, document);
