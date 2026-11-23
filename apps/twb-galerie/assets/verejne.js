// Veřejný web TWB Galerie (0.8.0): menu na mobilu a okno náhledu fotek (šipky, Esc, posun prstem). Bez JS odkaz fotky otevře velký obrázek.
// 0.8.3: cookie lišta – měřicí a reklamní kód čeká jako neživý <template data-souhlas="…"> a oživí se až po souhlasu.
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

  // ---------- Souhlas s cookies (0.8.3) ----------
  var lista = document.querySelector('[data-cookies]');
  if (lista) {
    var jmeno = lista.getAttribute('data-cookie');
    var kategorie = lista.getAttribute('data-kategorie').split(',');
    var volby = lista.querySelector('[data-cookies-volby]');
    // uložená volba: „mereni=1&reklama=0“; null = návštěvník se ještě nerozhodl
    var nacti = function () {
      var m = document.cookie.match(new RegExp('(?:^|; )' + jmeno + '=([^;]*)'));
      if (!m) return null;
      var v = {};
      decodeURIComponent(m[1]).split('&').forEach(function (p) { var c = p.split('='); if (c[0]) v[c[0]] = c[1] === '1'; });
      return v;
    };
    // <template> nahradí jeho obsahem; skripty se musí vytvořit znovu, jinak by se nespustily
    var ozivene = {};
    var ozivit = function (kat) {
      if (ozivene[kat]) return;
      ozivene[kat] = true;
      Array.prototype.forEach.call(document.querySelectorAll('template[data-souhlas="' + kat + '"]'), function (t) {
        var obsah = document.importNode(t.content, true);
        Array.prototype.forEach.call(obsah.querySelectorAll('script'), function (st) {
          var nove = document.createElement('script');
          Array.prototype.forEach.call(st.attributes, function (a) { nove.setAttribute(a.name, a.value); });
          nove.text = st.text;
          st.parentNode.replaceChild(nove, st);
        });
        t.parentNode.replaceChild(obsah, t);
      });
    };
    var uplatni = function (v) { kategorie.forEach(function (k) { if (v[k]) ozivit(k); }); };
    var uloz = function (v) {
      var pred = nacti() || {};
      document.cookie = jmeno + '=' + encodeURIComponent(kategorie.map(function (k) { return k + '=' + (v[k] ? 1 : 0); }).join('&'))
        + '; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
      lista.hidden = true;
      // odvolaný souhlas: už spuštěný kód nejde zastavit, stránka se načte znovu bez něj
      if (kategorie.some(function (k) { return pred[k] && !v[k] && ozivene[k]; })) { location.reload(); return; }
      uplatni(v);
    };
    var ukazNastaveni = function (ano) {
      volby.hidden = !ano;
      lista.querySelector('[data-cookies-akce="nastaveni"]').hidden = ano;
      lista.querySelector('[data-cookies-akce="vyber"]').hidden = !ano;
    };
    var otevri = function (sNastavenim) {
      var v = nacti() || {};
      kategorie.forEach(function (k) { var i = volby.querySelector('input[name="' + k + '"]'); if (i) i.checked = !!v[k]; });
      ukazNastaveni(sNastavenim);
      lista.hidden = false;
    };
    lista.addEventListener('click', function (e) {
      var tl = e.target.closest('[data-cookies-akce]');
      if (!tl) return;
      var akce = tl.getAttribute('data-cookies-akce'), v = {};
      if (akce === 'nastaveni') { ukazNastaveni(true); return; }
      kategorie.forEach(function (k) {
        v[k] = akce === 'vse' || (akce === 'vyber' && volby.querySelector('input[name="' + k + '"]').checked);
      });
      uloz(v);
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-cookies-nastaveni]'), function (b) {
      b.addEventListener('click', function () { otevri(true); });
    });
    var ulozene = nacti();
    if (ulozene) uplatni(ulozene); else otevri(false);
  }
})();
