// TWB Media galerie – drobnosti administrace: potvrzení akcí, menu, kopírování do schránky, nahrávání, tagy jako štítky,
// výběr tažením myši, větší náhled v okně, Passkey, odhlášení po nečinnosti.
(function () {
  'use strict';

  // Potvrzení před odesláním (smazání apod.)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset && f.dataset.potvrdit && !window.confirm(f.dataset.potvrdit)) e.preventDefault();
  });

  // Přepínač postranního menu: na počítači menu skryje (zapamatuje se), na mobilu ho vysune přes obsah (klik na závoj ho zavře)
  var uzky = window.matchMedia('(max-width: 991.98px)');
  document.querySelectorAll('[data-prepni="menu"]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (uzky.matches) {
        document.body.classList.toggle('menu-otevrene');
      } else {
        var skryte = document.documentElement.classList.toggle('menu-skryte');
        try { localStorage.setItem('twbg-menu', skryte ? 'skryte' : ''); } catch (e) { /* soukromé okno */ }
      }
      var prepinac = document.querySelector('.menu-prepinac');
      if (prepinac) prepinac.setAttribute('aria-expanded', String(uzky.matches ? document.body.classList.contains('menu-otevrene') : !document.documentElement.classList.contains('menu-skryte')));
    });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') document.body.classList.remove('menu-otevrene'); });

  // ---------- Karty stránky (Nastavení, 0.8.1): přepnutí bez načtení, adresa (?karta=…#kotva) se přepíše, takže obnovení
  // stránky i odkaz vedou na stejnou kartu. Kotva prvku uvnitř karty (např. #obnova) otevře jeho kartu. Šipky přepínají karty. ----------
  var kartyNav = document.querySelector('.karty-nav');
  if (kartyNav) {
    var zalozky = Array.prototype.slice.call(kartyNav.querySelectorAll('[role="tab"]'));
    var ukazKartu = function (id, fokus) {
      var nasel = false;
      zalozky.forEach(function (a) {
        var ano = a.getAttribute('aria-controls') === id;
        if (ano) nasel = true;
        a.classList.toggle('aktivni', ano);
        a.setAttribute('aria-selected', String(ano));
        if (ano) a.removeAttribute('tabindex'); else a.setAttribute('tabindex', '-1');
        var panel = document.getElementById(a.getAttribute('aria-controls'));
        if (panel) panel.hidden = !ano;
        if (ano && fokus) a.focus();
        if (ano && kartyNav.scrollWidth > kartyNav.clientWidth) kartyNav.scrollLeft = a.offsetLeft - kartyNav.offsetLeft - 16;
      });
      return nasel;
    };
    var podleKotvy = function () {
      var h = decodeURIComponent(location.hash.slice(1));
      if (!h) return;
      var el = document.getElementById(h);
      var panel = el && el.closest('[data-karta]');
      if (!panel || !ukazKartu(panel.id)) return;
      // kotva celé karty: zůstat nahoře u lišty karet; kotva uvnitř karty: posunout na ni
      if (el === panel) window.scrollTo(0, 0); else el.scrollIntoView();
    };
    zalozky.forEach(function (a) {
      a.addEventListener('click', function (e) {
        if (e.ctrlKey || e.metaKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault();
        ukazKartu(a.getAttribute('aria-controls'));
        try { history.replaceState(null, '', a.href); } catch (er) { /* starý prohlížeč */ }
      });
    });
    kartyNav.addEventListener('keydown', function (e) {
      var i = zalozky.indexOf(document.activeElement);
      if (i < 0) return;
      var j = e.key === 'ArrowRight' ? i + 1 : e.key === 'ArrowLeft' ? i - 1 : e.key === 'Home' ? 0 : e.key === 'End' ? zalozky.length - 1 : null;
      if (j === null) return;
      e.preventDefault();
      var a = zalozky[(j + zalozky.length) % zalozky.length];
      a.click();
      a.focus();
    });
    window.addEventListener('hashchange', podleKotvy);
    var aktivni = kartyNav.querySelector('.aktivni');
    if (aktivni) ukazKartu(aktivni.getAttribute('aria-controls'));
    podleKotvy();
  }

  // Kopírování kódu do schránky
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-kopirovat]');
    if (!b) return;
    var el = document.getElementById(b.dataset.kopirovat);
    if (!el) return;
    var text = el.value !== undefined ? el.value : el.textContent;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      var p = b.textContent; b.textContent = 'Zkopírováno'; setTimeout(function () { b.textContent = p; }, 1500);
    }, function () { if (el.select) el.select(); });
  });

  // Kopírování textu z atributu (veřejný odkaz v mřížce galerie)
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-kopirovat-text]');
    if (!b) return;
    e.preventDefault();
    var text = b.dataset.kopirovatText;
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      var p = b.textContent; b.textContent = 'Zkopírováno'; setTimeout(function () { b.textContent = p; }, 1500);
    }, function () { window.prompt('Odkaz:', text); });
  });

  // ---------- Galerie: hromadný výběr ----------
  var hromadne = document.getElementById('hromadne');
  if (hromadne) {
    var akce = hromadne.querySelector('[data-hromadna-akce]');
    var odeslat = hromadne.querySelector('[data-hromadne-odeslat]');
    var pocet = hromadne.querySelector('[data-pocet-vybranych]');
    var vse = hromadne.querySelector('[data-vybrat-vse]');
    var boxy = function () { return Array.prototype.slice.call(hromadne.querySelectorAll('input[name="ids[]"]')); };
    var obnov = function () {
      var n = boxy().filter(function (b) { return b.checked; }).length;
      pocet.textContent = n ? 'Vybráno: ' + n : 'Nic nevybráno';
      odeslat.disabled = !n || !akce.value;
      hromadne.querySelectorAll('[data-pro]').forEach(function (el) {
        el.hidden = el.dataset.pro.split(' ').indexOf(akce.value) < 0;
      });
      boxy().forEach(function (b) { b.closest('figure').classList.toggle('vybrano', b.checked); });
      if (vse) vse.checked = n > 0 && n === boxy().length;
      var g = hromadne.querySelector('.galerie');
      if (g) g.classList.toggle('neco-vybrano', n > 0);
    };
    hromadne.addEventListener('change', function (e) {
      if (e.target === vse) boxy().forEach(function (b) { b.checked = vse.checked; });
      obnov();
    });
    hromadne.addEventListener('submit', function (e) {
      var n = boxy().filter(function (b) { return b.checked; }).length;
      if (akce.value === 'smazat' && !window.confirm('Smazat ' + n + ' vybraných souborů? Veřejné odkazy přestanou fungovat.')) e.preventDefault();
      var ponechat = hromadne.querySelector('[name="komprese_ponechat"]');
      if (akce.value === 'komprimovat' && ponechat && !ponechat.checked
        && !window.confirm('Zkomprimovat ' + n + ' vybraných médií bez ponechání originálů? Původní soubory se smažou a nepůjde je vrátit.')) e.preventDefault();
    });
    obnov();
  }

  // ---------- Hromadná komprese (0.8.0): kroky po několika sekundách, dokud není hotovo ----------
  var komprese = document.querySelector('[data-komprese]');
  if (komprese && window.fetch) {
    var prubeh = komprese.querySelector('[data-komprese-prubeh]');
    var textKomprese = komprese.querySelector('[data-komprese-text]');
    var pokracovat = komprese.querySelector('[data-komprese-pokracovat]');
    if (pokracovat) pokracovat.hidden = true;
    var pokusu = 0;
    var krok = function () {
      var fd = new FormData();
      fd.append('csrf', komprese.dataset.csrf);
      fd.append('akce', 'krok');
      fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!j.ok) throw new Error(j.error || 'Chyba');
          pokusu = 0;
          prubeh.max = j.celkem; prubeh.value = j.zpracovano;
          if (j.hotovo) { window.location.reload(); return; }
          textKomprese.textContent = 'Zpracováno ' + j.zpracovano + ' z ' + j.celkem + ' obrázků, zatím ušetřeno ' + j.usetreno_text + '. Nechte stránku otevřenou, obrázky se zpracovávají po dávkách.';
          krok();
        })
        .catch(function () {
          // výpadek spojení nebo spadlý krok: zkusit znovu (spadlý obrázek server příště přeskočí), po 5 pokusech nechat na uživateli
          if (++pokusu <= 5) { setTimeout(krok, 2000 * pokusu); return; }
          textKomprese.textContent = 'Zpracování se zastavilo (spojení nebo server). Klikněte na Pokračovat.';
          if (pokracovat) pokracovat.hidden = false;
        });
    };
    krok();
  }

  // ---------- Okno náhledu (celá aplikace): obrázek, video, PDF v modálním okně. Šipky listují, Esc zavře. ----------
  // Spouští ho prvek s data-otevrit-okno uvnitř skupiny data-okno-skupina; položky (data-okno, data-okno-src…) vypisuje Ui::okno.
  // Nic se neotevírá v nové kartě: Ctrl/Cmd+klik i prostřední klik otevřou detail média ve stejné kartě (0.6.4).
  (function () {
    var okno = null, skupina = null, aktualni = -1, predOknem = null;
    var polozky = function () {
      var p = Array.prototype.slice.call(skupina.querySelectorAll('[data-okno]'));
      if (skupina.hasAttribute('data-okno')) p.unshift(skupina);
      return p;
    };
    var prvek = function (tag, trida) { var el = document.createElement(tag); if (trida) el.className = trida; return el; };
    var vytvor = function () {
      okno = prvek('div', 'okno-nahled');
      okno.hidden = true;
      okno.setAttribute('role', 'dialog'); okno.setAttribute('aria-modal', 'true'); okno.setAttribute('aria-label', 'Náhled média');
      okno.innerHTML = '<div class="okno-plocha" data-okno-plocha></div>'
        + '<button type="button" class="okno-sipka okno-predchozi" data-okno-krok="-1" aria-label="Předchozí" title="Předchozí (←)">‹</button>'
        + '<button type="button" class="okno-sipka okno-dalsi" data-okno-krok="1" aria-label="Další" title="Další (→)">›</button>'
        + '<button type="button" class="okno-zavrit" data-okno-zavrit aria-label="Zavřít" title="Zavřít (Esc)">×</button>'
        + '<div class="okno-popis"><div><strong data-okno-titulek></strong> <span class="okno-udaje" data-okno-udaje></span></div>'
        + '<div class="okno-akce"><a class="tlacitko maly" data-okno-na-detail>Detail</a> <a class="tlacitko maly" data-okno-na-stazeni>Stáhnout</a>'
        + ' <button type="button" class="maly" data-okno-kopirovat hidden>Kopírovat odkaz</button></div></div>';
      document.body.appendChild(okno);
      okno.addEventListener('click', function (e) {
        var t = e.target;
        if (t.closest('[data-okno-krok]')) ukaz(aktualni + parseInt(t.closest('[data-okno-krok]').dataset.oknoKrok, 10));
        else if (t.closest('[data-okno-zavrit]') || t === okno || t.hasAttribute('data-okno-plocha')) zavri();
      });
      okno.querySelector('[data-okno-kopirovat]').addEventListener('click', function () {
        var b = this, text = b.dataset.text;
        (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
          b.textContent = 'Zkopírováno'; setTimeout(function () { b.textContent = 'Kopírovat odkaz'; }, 1500);
        }, function () { window.prompt('Odkaz:', text); });
      });
    };
    var ukaz = function (i) {
      var p = polozky();
      if (!p.length) return zavri();
      aktualni = (i + p.length) % p.length;
      var d = p[aktualni].dataset, plocha = okno.querySelector('[data-okno-plocha]');
      plocha.textContent = '';
      okno.classList.remove('nacita');
      var m;
      if (d.okno === 'video') { m = prvek('video'); m.controls = true; m.autoplay = true; m.playsInline = true; }
      else if (d.okno === 'pdf') { m = prvek('iframe', 'okno-pdf'); m.title = d.oknoNazev; }
      else { m = prvek('img'); m.alt = d.oknoNazev; okno.classList.add('nacita'); m.onload = m.onerror = function () { okno.classList.remove('nacita'); }; }
      m.src = d.oknoSrc;
      plocha.appendChild(m);
      okno.querySelector('[data-okno-titulek]').textContent = d.oknoNazev;
      okno.querySelector('[data-okno-udaje]').textContent = d.oknoPopis + (p.length > 1 ? ' · ' + (aktualni + 1) + ' / ' + p.length : '');
      var det = okno.querySelector('[data-okno-na-detail]');
      det.hidden = !d.oknoDetail; if (d.oknoDetail) det.href = d.oknoDetail;
      var st = okno.querySelector('[data-okno-na-stazeni]');
      st.hidden = !d.oknoStahnout; if (d.oknoStahnout) st.href = d.oknoStahnout;
      var k = okno.querySelector('[data-okno-kopirovat]');
      k.hidden = !d.oknoOdkaz; k.dataset.text = d.oknoOdkaz || '';
      okno.querySelectorAll('.okno-sipka').forEach(function (b) { b.hidden = p.length < 2; });
      // sousední obrázky dopředu, ať listování nečeká
      if (p.length > 1) [aktualni - 1, aktualni + 1].forEach(function (j) {
        var s = p[(j + p.length) % p.length];
        if (s.dataset.okno === 'image') (new Image()).src = s.dataset.oknoSrc;
      });
    };
    var otevri = function (spoustec) {
      if (!okno) vytvor();
      skupina = spoustec.closest('[data-okno-skupina]');
      predOknem = document.activeElement;
      okno.hidden = false;
      document.body.classList.add('okno-otevrene');
      ukaz(polozky().indexOf(spoustec.closest('[data-okno]')));
      okno.querySelector('[data-okno-zavrit]').focus();
    };
    var zavri = function () {
      if (!okno || okno.hidden) return;
      okno.hidden = true;
      okno.querySelector('[data-okno-plocha]').textContent = '';   // zastaví video
      document.body.classList.remove('okno-otevrene');
      if (predOknem && predOknem.focus) predOknem.focus();
    };
    // Ctrl/Cmd+klik: detail média ve stejné kartě; kde detail není (už jsme v něm), otevře okno
    var naDetail = function (a) {
      var d = a.closest('[data-okno]').dataset.oknoDetail;
      if (d) window.location.href = d; else otevri(a);
    };
    var spoustec = function (e) {
      var a = e.target instanceof Element && e.target.closest('[data-otevrit-okno]');
      return a && a.closest('[data-okno-skupina]') && a.closest('[data-okno]') ? a : null;
    };
    document.addEventListener('click', function (e) {
      var a = spoustec(e);
      if (!a || e.button !== 0) return;
      e.preventDefault();
      if (e.ctrlKey || e.metaKey) naDetail(a); else otevri(a);
    });
    // prostřední tlačítko jako Ctrl+klik: detail ve stejné kartě, ne nová karta
    document.addEventListener('auxclick', function (e) {
      var a = spoustec(e);
      if (!a || e.button !== 1) return;
      e.preventDefault();
      naDetail(a);
    });
    document.addEventListener('mousedown', function (e) { if (e.button === 1 && spoustec(e)) e.preventDefault(); });   // bez automatického posunu
    document.addEventListener('keydown', function (e) {
      if (!okno || okno.hidden) return;
      if (e.key === 'Escape') { e.preventDefault(); zavri(); }
      else if (e.key === 'ArrowLeft' && e.target.tagName !== 'VIDEO') ukaz(aktualni - 1);
      else if (e.key === 'ArrowRight' && e.target.tagName !== 'VIDEO') ukaz(aktualni + 1);
      else if (e.key === 'Tab') {
        // fokus zůstává v okně
        var t = Array.prototype.filter.call(okno.querySelectorAll('button, a[href], video, iframe'), function (x) { return !x.hidden; });
        if (!t.length) return;
        var i = t.indexOf(document.activeElement);
        if (e.shiftKey && i <= 0) { e.preventDefault(); t[t.length - 1].focus(); }
        else if (!e.shiftKey && i === t.length - 1) { e.preventDefault(); t[0].focus(); }
      }
    });
  })();

  // ---------- Nahrávání po částech (galerie, nahrazení souboru) ----------
  var nahravani = document.getElementById('nahravani');
  if (nahravani) {
    var cast = parseInt(nahravani.dataset.cast, 10) || 2097152;
    var max = parseInt(nahravani.dataset.max, 10) || 0;
    var nahradit = nahravani.dataset.nahradit || '';
    var seznam = nahravani.querySelector('[data-seznam]');
    var plocha = nahravani.querySelector('[data-plocha]');
    var vyber = nahravani.querySelector('[data-vyber]');
    var fronta = [], bezi = false, chyb = 0, hotovych = 0;

    var uid = function () {
      var b = new Uint8Array(16); (window.crypto || window.msCrypto).getRandomValues(b);
      return Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
    };
    var mb = function (n) { return (n / 1048576).toFixed(n < 10485760 ? 1 : 0).replace('.', ',') + ' MB'; };
    var posliCast = function (data, pokus) {
      return fetch('nahrat.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': nahravani.dataset.csrf }, body: data })
        .then(function (r) { return r.json().catch(function () { throw new Error('Server vrátil neplatnou odpověď (HTTP ' + r.status + ').'); }); })
        .then(function (j) { if (!j.ok) { var e = new Error(j.error || 'Chyba'); e.trvala = true; throw e; } return j; })
        .catch(function (e) {
          if (e.trvala || pokus >= 3) throw e;
          return new Promise(function (ok) { setTimeout(ok, 1500 * pokus); }).then(function () { return posliCast(data, pokus + 1); });
        });
    };
    var nahraj = function (polozka) {
      var f = polozka.soubor, id = uid(), celkem = Math.max(1, Math.ceil(f.size / cast)), i = 0;
      var kategorie = document.getElementById('f-nahrat_kategorie'), tagy = document.getElementById('f-nahrat_tagy');
      var dalsi = function () {
        var fd = new FormData();
        fd.append('uid', id); fd.append('index', String(i)); fd.append('celkem', String(celkem)); fd.append('nazev', f.name);
        if (kategorie && kategorie.value) fd.append('kategorie', kategorie.value);
        if (tagy) { tagy.dispatchEvent(new Event('stitky-potvrdit')); if (tagy.value) fd.append('tagy', tagy.value); }
        if (nahradit) fd.append('nahradit', nahradit);
        fd.append('cast', f.slice(i * cast, Math.min(f.size, (i + 1) * cast)), 'cast');
        return posliCast(fd, 1).then(function (j) {
          i++;
          polozka.pruh.value = Math.round(i / celkem * 100);
          if (!j.hotovo && i < celkem) return dalsi();
          return j;
        });
      };
      return dalsi();
    };
    var spust = function () {
      if (bezi) return;
      var polozka = fronta.shift();
      if (!polozka) {
        if (hotovych && !chyb) location.reload();
        else if (hotovych) { var r = document.createElement('li'); r.innerHTML = '<a href="">Načíst galerii znovu</a>'; seznam.appendChild(r); }
        return;
      }
      bezi = true;
      polozka.stav.textContent = 'nahrávám…';
      nahraj(polozka).then(function (j) {
        hotovych++;
        polozka.stav.textContent = j.poznamka ? 'hotovo – ' + j.poznamka : 'hotovo';
        polozka.li.classList.add('ok');
      }, function (e) {
        chyb++;
        polozka.stav.textContent = e.message || 'chyba';
        polozka.li.classList.add('chyba');
      }).then(function () { bezi = false; spust(); });
    };
    var pridej = function (soubory) {
      Array.prototype.forEach.call(soubory, function (f, k) {
        if (nahradit && k > 0) return;
        var li = document.createElement('li');
        var nazev = document.createElement('span'); nazev.className = 'nazev'; nazev.textContent = f.name + ' (' + mb(f.size) + ')';
        var pruh = document.createElement('progress'); pruh.max = 100; pruh.value = 0;
        var stav = document.createElement('span'); stav.className = 'stav-nahravani slabe mala'; stav.textContent = 'čeká';
        li.appendChild(nazev); li.appendChild(pruh); li.appendChild(stav);
        seznam.appendChild(li);
        if (max && f.size > max) { stav.textContent = 'soubor je větší než ' + mb(max); li.classList.add('chyba'); chyb++; return; }
        if (f.size === 0) { stav.textContent = 'prázdný soubor'; li.classList.add('chyba'); chyb++; return; }
        fronta.push({ soubor: f, li: li, pruh: pruh, stav: stav });
      });
      spust();
    };
    if (vyber) vyber.addEventListener('change', function () { pridej(vyber.files); vyber.value = ''; });
    ['dragenter', 'dragover'].forEach(function (t) {
      plocha.addEventListener(t, function (e) { e.preventDefault(); plocha.classList.add('nad'); });
    });
    ['dragleave', 'drop'].forEach(function (t) {
      plocha.addEventListener(t, function (e) { e.preventDefault(); plocha.classList.remove('nad'); });
    });
    plocha.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) pridej(e.dataTransfer.files); });
    window.addEventListener('beforeunload', function (e) { if (bezi || fronta.length) { e.preventDefault(); e.returnValue = ''; } });
  }

  // ---------- Tagy jako štítky: Enter, Tab nebo čárka vytvoří štítek, × ho odebere, Backspace v prázdném poli smaže poslední ----------
  // Původní pole zůstane (skryté) a nese tagy oddělené čárkou – server i nahrávání s ním pracují beze změny.
  var MAX_TAGU = 10, MAX_DELKA = 30;
  document.querySelectorAll('input[data-stitky]').forEach(function (puvodni) {
    var obal = document.createElement('div');
    obal.className = 'stitky-pole';
    var vstup = document.createElement('input');
    vstup.type = 'text';
    vstup.placeholder = puvodni.placeholder || '';
    vstup.setAttribute('aria-label', 'Nový tag');
    vstup.autocomplete = 'off';
    if (puvodni.getAttribute('list')) vstup.setAttribute('list', puvodni.getAttribute('list'));
    var hlaska = document.createElement('small');
    hlaska.className = 'stitky-hlaska';
    var tagy = [];
    var volby = function () {
      var dl = puvodni.getAttribute('list') && document.getElementById(puvodni.getAttribute('list'));
      return dl ? Array.prototype.map.call(dl.options, function (o) { return o.value; }) : [];
    };
    var uloz = function () {
      puvodni.value = tagy.join(', ');
      puvodni.dispatchEvent(new Event('change', { bubbles: true }));
    };
    var vykresli = function () {
      obal.querySelectorAll('.stitek-upravitelny').forEach(function (el) { el.remove(); });
      tagy.forEach(function (t, i) {
        var el = document.createElement('span');
        el.className = 'stitek stitek-upravitelny';
        el.textContent = t;
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'stitek-zrusit';
        x.setAttribute('aria-label', 'Odebrat tag ' + t);
        x.textContent = '×';
        x.addEventListener('click', function () { tagy.splice(i, 1); vykresli(); uloz(); vstup.focus(); });
        el.appendChild(x);
        obal.insertBefore(el, vstup);
      });
      vstup.placeholder = tagy.length ? '' : (puvodni.placeholder || '');
      vstup.disabled = false;
    };
    var pridej = function (text) {
      var zmena = false;
      text.split(/[,;\n]+/).forEach(function (t) {
        t = t.replace(/\s+/g, ' ').trim();
        if (!t) return;
        if (t.length > MAX_DELKA) { hlaska.textContent = 'Tag může mít nejvýš ' + MAX_DELKA + ' znaků.'; return; }
        if (tagy.some(function (x) { return x.toLowerCase() === t.toLowerCase(); })) return;
        if (tagy.length >= MAX_TAGU) { hlaska.textContent = 'Nejvýš ' + MAX_TAGU + ' tagů.'; return; }
        // zápis podle už existujícího tagu (stejná velikost písmen jako jinde v galerii)
        var znamy = volby().filter(function (v) { return v.toLowerCase() === t.toLowerCase(); })[0];
        tagy.push(znamy || t);
        zmena = true;
      });
      vstup.value = '';
      if (zmena) { hlaska.textContent = ''; vykresli(); uloz(); }
    };
    tagy = puvodni.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean);
    puvodni.type = 'hidden';
    puvodni.removeAttribute('data-stitky');
    puvodni.parentNode.insertBefore(obal, puvodni);
    obal.appendChild(vstup);
    obal.parentNode.insertBefore(hlaska, obal.nextSibling);
    // kliknutí na popisek pole přenese kurzor do nového vstupu
    if (puvodni.id) { vstup.id = puvodni.id + '-novy'; var lbl = obal.closest('label'); if (lbl) lbl.setAttribute('for', vstup.id); }
    vykresli();
    obal.addEventListener('click', function (e) { if (e.target === obal) vstup.focus(); });
    vstup.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ',' || e.key === ';' || (e.key === 'Tab' && vstup.value.trim())) && !e.isComposing) {
        if (e.key === 'Enter' || vstup.value.trim()) e.preventDefault();
        pridej(vstup.value);
      } else if (e.key === 'Backspace' && vstup.value === '' && tagy.length) {
        tagy.pop(); vykresli(); uloz();
      }
    });
    // výběr z našeptávače (datalist) vytvoří štítek hned; vložení textu s čárkami rozdělí na víc štítků
    vstup.addEventListener('input', function (e) {
      if (!e.inputType || e.inputType === 'insertReplacementText') {
        if (volby().indexOf(vstup.value) >= 0) pridej(vstup.value);
      } else if (/[,;\n]/.test(vstup.value)) {
        pridej(vstup.value);
      }
    });
    // rozepsaný tag (bez Enteru) se přidá při odeslání formuláře nebo před nahráním souborů – ne při opuštění pole,
    // protože nový štítek by posunul tlačítko, na které uživatel právě kliká
    puvodni.addEventListener('stitky-potvrdit', function () { if (vstup.value.trim()) pridej(vstup.value); });
    // formulář odešle i rozepsaný tag
    var form = puvodni.form;
    if (form) form.addEventListener('submit', function () { if (vstup.value.trim()) pridej(vstup.value); }, true);
  });

  // ---------- Galerie: výběr tažením myši (obdélník). Bez klávesy nahradí výběr, s Ctrl/Cmd/Shift přidá. Esc zruší. ----------
  var mrizka = hromadne && hromadne.querySelector('.galerie');
  if (mrizka) {
    var zacatek = null, obdelnik = null, tahne = false, puvodniVyber = [], predTazenim = [], posun = 0, pozice = null;
    var figury = function () { return Array.prototype.slice.call(mrizka.querySelectorAll('figure')); };
    var box = function (f) { return f.querySelector('input[name="ids[]"]'); };
    var stranka = function (e) { return { x: e.clientX + window.scrollX, y: e.clientY + window.scrollY }; };
    var oznac = function () {
      var a = zacatek, b = stranka(pozice);
      var r = { l: Math.min(a.x, b.x), t: Math.min(a.y, b.y), r: Math.max(a.x, b.x), b: Math.max(a.y, b.y) };
      obdelnik.style.left = r.l + 'px'; obdelnik.style.top = r.t + 'px';
      obdelnik.style.width = (r.r - r.l) + 'px'; obdelnik.style.height = (r.b - r.t) + 'px';
      figury().forEach(function (f, i) {
        var o = f.getBoundingClientRect();
        var x1 = o.left + window.scrollX, y1 = o.top + window.scrollY;
        var uvnitr = x1 < r.r && x1 + o.width > r.l && y1 < r.b && y1 + o.height > r.t;
        box(f).checked = uvnitr || puvodniVyber[i];
      });
      hromadne.dispatchEvent(new Event('change', { bubbles: true }));
    };
    // u okraje okna se stránka posouvá, aby šlo označit i to, co není vidět
    var krok = function () {
      if (!tahne) return;
      var y = pozice.clientY, rychlost = 0;
      if (y < 60) rychlost = -Math.ceil((60 - y) / 4); else if (y > window.innerHeight - 60) rychlost = Math.ceil((y - window.innerHeight + 60) / 4);
      if (rychlost) { window.scrollBy(0, rychlost); oznac(); }
      posun = requestAnimationFrame(krok);
    };
    var konec = function (zrusit) {
      document.removeEventListener('pointermove', pohyb);
      document.removeEventListener('pointerup', pusteni);
      cancelAnimationFrame(posun);
      if (zrusit && tahne) {
        figury().forEach(function (f, i) { box(f).checked = predTazenim[i]; });
        hromadne.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (obdelnik) { obdelnik.remove(); obdelnik = null; }
      document.body.classList.remove('vybirani');
      if (tahne) {
        // klik po tažení neotevře detail ani nepřepne zaškrtávátko
        var zahod = function (e) { e.preventDefault(); e.stopPropagation(); };
        document.addEventListener('click', zahod, true);
        setTimeout(function () { document.removeEventListener('click', zahod, true); }, 0);
      }
      zacatek = null; tahne = false;
    };
    var pohyb = function (e) {
      pozice = e;
      if (!tahne) {
        var p = stranka(e);
        if (Math.abs(p.x - zacatek.x) < 6 && Math.abs(p.y - zacatek.y) < 6) return;
        tahne = true;
        obdelnik = document.createElement('div');
        obdelnik.className = 'vyber-obdelnik';
        document.body.appendChild(obdelnik);
        document.body.classList.add('vybirani');
        posun = requestAnimationFrame(krok);
      }
      e.preventDefault();
      oznac();
    };
    var pusteni = function () { konec(false); };
    // začít jde v mřížce i v prázdném místě kolem ní (okraj stránky), ne v ovládacích prvcích
    document.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse' || e.button !== 0) return;
      var t = e.target;
      if (!(t instanceof Element) || !(hromadne.contains(t) || t.contains(hromadne))) return;
      if (t.closest('.hromadne, input, select, textarea, button, .vyber-medium, .strankovani')) return;
      var pridat = e.ctrlKey || e.metaKey || e.shiftKey;
      predTazenim = figury().map(function (f) { return box(f).checked; });
      puvodniVyber = predTazenim.map(function (x) { return pridat && x; });
      zacatek = stranka(e); pozice = e;
      e.preventDefault();   // bez označení textu a bez tažení obrázku
      document.addEventListener('pointermove', pohyb);
      document.addEventListener('pointerup', pusteni);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && zacatek) konec(true); });
    mrizka.querySelectorAll('a, img').forEach(function (el) { el.setAttribute('draggable', 'false'); });
  }

  // Odhlášení po nečinnosti: po vypršení limitu načíst stránku znovu (zobrazí přihlášení)
  var main = document.querySelector('main[data-necinnost]');
  if (main) {
    var limit = parseInt(main.dataset.necinnost, 10) * 1000;
    var casovac = setTimeout(function () { location.reload(); }, limit + 5000);
  }

  // ---------- Passkey (WebAuthn) ----------
  function b64u(buf) {
    var s = '', b = new Uint8Array(buf);
    for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function zB64u(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/');
    while (s.length % 4) s += '=';
    var bin = atob(s), b = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) b[i] = bin.charCodeAt(i);
    return b.buffer;
  }
  function volej(akce, data) {
    return fetch('passkey.php?akce=' + akce, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data)
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'Chyba');
      return j;
    });
  }
  function chyba(e) {
    if (e && e.name === 'NotAllowedError') return;   // uživatel zrušil
    window.alert(e && e.message ? e.message : 'Passkey se nezdařil.');
  }

  var prihlasit = document.getElementById('passkey-prihlasit');
  if (prihlasit) {
    if (!window.PublicKeyCredential) prihlasit.hidden = true;
    prihlasit.addEventListener('click', function () {
      var csrf = prihlasit.dataset.csrf;
      volej('moznosti-prihlaseni', { csrf: csrf }).then(function (j) {
        var o = j.publicKey;
        o.challenge = zB64u(o.challenge);
        return navigator.credentials.get({ publicKey: o });
      }).then(function (c) {
        return volej('prihlasit', { csrf: csrf, credential: {
          id: c.id, rawId: b64u(c.rawId), type: c.type,
          response: { clientDataJSON: b64u(c.response.clientDataJSON), authenticatorData: b64u(c.response.authenticatorData),
            signature: b64u(c.response.signature), userHandle: c.response.userHandle ? b64u(c.response.userHandle) : null }
        } });
      }).then(function () { location.href = 'index.php'; }).catch(chyba);
    });
  }

  var registrovat = document.getElementById('passkey-pridat');
  if (registrovat) {
    if (!window.PublicKeyCredential) { registrovat.disabled = true; registrovat.title = 'Tento prohlížeč Passkey neumí.'; }
    registrovat.addEventListener('click', function () {
      var csrf = registrovat.dataset.csrf;
      var nazev = (document.getElementById('passkey-nazev') || {}).value || '';
      volej('moznosti-registrace', { csrf: csrf }).then(function (j) {
        var o = j.publicKey;
        o.challenge = zB64u(o.challenge);
        o.user.id = zB64u(o.user.id);
        o.excludeCredentials = (o.excludeCredentials || []).map(function (x) { return { type: x.type, id: zB64u(x.id) }; });
        return navigator.credentials.create({ publicKey: o });
      }).then(function (c) {
        return volej('registrovat', { csrf: csrf, nazev: nazev, credential: {
          id: c.id, rawId: b64u(c.rawId), type: c.type,
          response: { clientDataJSON: b64u(c.response.clientDataJSON), attestationObject: b64u(c.response.attestationObject) }
        } });
      }).then(function () { location.reload(); }).catch(chyba);
    });
  }
})();

// Detail média: vysvětlivka k vybrané licenci a pole verze jen u CC BY, CC BY-SA a Jiné
(function () {
  const vyber = document.querySelector('select[data-licence]');
  if (!vyber) return;
  const verze = document.querySelector('[data-licence-verze]');
  const sVerzi = ['cc-by', 'cc-by-sa', 'jine'];
  const obnov = () => {
    document.querySelectorAll('[data-licence-napoveda]').forEach((s) => { s.hidden = s.dataset.licenceNapoveda !== vyber.value; });
    if (verze) verze.disabled = !sVerzi.includes(vyber.value);
  };
  vyber.addEventListener('change', obnov);
  obnov();
})();
