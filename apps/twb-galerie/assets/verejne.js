// Veřejný web TWB Galerie (0.8.0): menu na mobilu a okno náhledu fotek (šipky, Esc, posun prstem). Bez JS odkaz fotky otevře velký obrázek.
(function () {
  'use strict';
  var menuTl = document.querySelector('[data-menu]');
  var menu = document.getElementById('hlavni-menu');
  if (menuTl && menu) {
    menuTl.addEventListener('click', function () {
      var otevrit = !menu.classList.contains('otevrene');
      menu.classList.toggle('otevrene', otevrit);
      menuTl.setAttribute('aria-expanded', otevrit ? 'true' : 'false');
    });
  }

  var svg = function (d) {
    return '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
  };
  var okno = null, img, titulek, popis, polozky = [], aktualni = -1, predOknem = null;
  var vytvor = function () {
    okno = document.createElement('div');
    okno.className = 'okno';
    okno.hidden = true;
    okno.setAttribute('role', 'dialog');
    okno.setAttribute('aria-modal', 'true');
    okno.setAttribute('aria-label', 'Náhled fotografie');
    okno.innerHTML = '<figure><img alt=""><figcaption><strong></strong><span></span></figcaption></figure>'
      + '<button type="button" class="zavrit" aria-label="Zavřít">' + svg('<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>') + '</button>'
      + '<button type="button" class="predchozi" aria-label="Předchozí">' + svg('<polyline points="15 18 9 12 15 6"/>') + '</button>'
      + '<button type="button" class="dalsi" aria-label="Další">' + svg('<polyline points="9 18 15 12 9 6"/>') + '</button>';
    document.body.appendChild(okno);
    img = okno.querySelector('img');
    titulek = okno.querySelector('figcaption strong');
    popis = okno.querySelector('figcaption span');
    okno.querySelector('.zavrit').addEventListener('click', zavri);
    okno.querySelector('.predchozi').addEventListener('click', function () { ukaz(aktualni - 1); });
    okno.querySelector('.dalsi').addEventListener('click', function () { ukaz(aktualni + 1); });
    okno.addEventListener('click', function (e) { if (e.target === okno || e.target.tagName === 'FIGURE') zavri(); });
    var x0 = null;
    okno.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    okno.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 50) ukaz(aktualni + (dx < 0 ? 1 : -1));
      x0 = null;
    });
  };
  var ukaz = function (i) {
    if (!polozky.length) return;
    aktualni = (i + polozky.length) % polozky.length;
    var a = polozky[aktualni];
    img.src = a.href;
    img.alt = a.dataset.titulek || '';
    titulek.textContent = a.dataset.titulek || '';
    popis.textContent = [a.dataset.popis, (aktualni + 1) + ' / ' + polozky.length].filter(Boolean).join(' · ');
    var dalsi = polozky[(aktualni + 1) % polozky.length];
    if (dalsi) { var p = new Image(); p.src = dalsi.href; }
    okno.querySelector('.predchozi').hidden = okno.querySelector('.dalsi').hidden = polozky.length < 2;
  };
  var zavri = function () {
    if (!okno || okno.hidden) return;
    okno.hidden = true;
    img.removeAttribute('src');
    document.body.classList.remove('okno-otevrene');
    if (predOknem) predOknem.focus();
  };
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-foto]');
    if (!a || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    var skupina = a.closest('[data-fotky]');
    if (!skupina) return;
    e.preventDefault();
    if (!okno) vytvor();
    polozky = Array.prototype.slice.call(skupina.querySelectorAll('a[data-foto]'));
    predOknem = a;
    okno.hidden = false;
    document.body.classList.add('okno-otevrene');
    ukaz(polozky.indexOf(a));
    okno.querySelector('.zavrit').focus();
  });
  document.addEventListener('keydown', function (e) {
    if (!okno || okno.hidden) return;
    if (e.key === 'Escape') zavri();
    else if (e.key === 'ArrowLeft') ukaz(aktualni - 1);
    else if (e.key === 'ArrowRight') ukaz(aktualni + 1);
  });
})();
