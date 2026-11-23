<?php
declare(strict_types=1);
// Asynchronní načítání reklam: <ins data-twbr-zona="ID"></ins> + <script async src=".../d/asyn.php"></script>.
// Skript najde všechny značky na stránce, jedním požadavkem (data.php) načte bannery a vloží je.
// Posílá šířku každého místa (sirky), šířku okna (sw): responzivní zóna podle nich vybere banner, který se vejde.
use Reklamy\App;

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
$zaklad = json_encode($app->url('d/'), JSON_UNESCAPED_SLASHES);
echo <<<JS
/* TWB Reklamy – asynchronní vložení */
(function (w, d) {
  'use strict';
  if (w.twbReklamy && w.twbReklamy.nacti) { w.twbReklamy.nacti(); return; }
  var zaklad = $zaklad;
  function nacti() {
    var prvky = [].slice.call(d.querySelectorAll('ins[data-twbr-zona]:not([data-twbr-stav])'));
    if (!prvky.length || !w.fetch) return;
    var zony = prvky.map(function (p) { p.setAttribute('data-twbr-stav', 'nacita'); return parseInt(p.getAttribute('data-twbr-zona'), 10) || 0; });
    // šířka místa: značka jako blok zabere celou šířku rodiče (inline-block s pevnou šířkou zůstane, jak je)
    var sirky = prvky.map(function (p) {
      try { if (w.getComputedStyle(p).display === 'inline') p.style.display = 'block'; } catch (e) {}
      return Math.floor(p.getBoundingClientRect().width) || 0;
    });
    var sw = w.innerWidth || (d.documentElement && d.documentElement.clientWidth) || 0;
    var u = zaklad + 'data.php?zony=' + zony.join(',') + '&sirky=' + sirky.join(',') + '&sw=' + sw
      + '&loc=' + encodeURIComponent(location.href)
      + (d.referrer ? '&ref=' + encodeURIComponent(d.referrer) : '') + '&cb=' + Math.floor(Math.random() * 1e9);
    w.fetch(u, { credentials: 'include' }).then(function (r) { return r.json(); }).then(function (j) {
      prvky.forEach(function (p, i) {
        var z = (j.zony || [])[i] || {};
        p.innerHTML = z.html || '';
        p.setAttribute('data-twbr-stav', z.banner ? 'ok' : 'prazdne');
        if (!z.html) p.style.display = 'none';
        try { p.dispatchEvent(new CustomEvent('twbr:nacteno', { bubbles: true, detail: z })); } catch (e) {}
      });
    }).catch(function () {
      prvky.forEach(function (p) { p.setAttribute('data-twbr-stav', 'chyba'); });
    });
  }
  w.twbReklamy = { nacti: nacti };
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', nacti); else nacti();
})(window, document);

JS;
