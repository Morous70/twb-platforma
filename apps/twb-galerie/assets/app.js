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

// Veřejný web → GDPR (0.8.3): vložit vzorový text zásad do pole
(function () {
  document.querySelectorAll('[data-vlozit-vzor]').forEach(function (b) {
    b.addEventListener('click', function () {
      var pole = document.getElementById(b.getAttribute('data-vlozit-vzor'));
      if (!pole) return;
      if (pole.value.trim() !== '' && !window.confirm('Pole už obsahuje text. Nahradit ho vzorovým textem?')) return;
      pole.value = b.getAttribute('data-vzor');
      pole.focus();
    });
  });
})();

// Kategorie (0.8.8): pořadí přetažením řádku za úchyt (myš, dotyk – pointer události) nebo šipkami na zaměřeném úchytu.
// Nové pořadí se hned uloží (POST akce=poradi, odpověď JSON); při chybě se vrátí původní pořadí.
(function () {
  var telo = document.querySelector('tbody[data-poradi]');
  if (!telo || !window.fetch) return;
  var stav = document.querySelector('[data-poradi-stav]');
  var radky = function () { return Array.prototype.slice.call(telo.querySelectorAll('tr[data-poradi-id]')); };
  var poradi = function () { return radky().map(function (r) { return r.getAttribute('data-poradi-id'); }).join(','); };
  var ulozene = poradi();
  var casovac = null;
  var hlaska = function (t, chyba) { if (stav) { stav.textContent = t; stav.classList.toggle('chyba', !!chyba); } };
  var obnovUlozene = function () {
    var podleId = {};
    radky().forEach(function (r) { podleId[r.getAttribute('data-poradi-id')] = r; });
    ulozene.split(',').forEach(function (id) { if (podleId[id]) telo.appendChild(podleId[id]); });
  };
  var uloz = function () {
    var nove = poradi();
    if (nove === ulozene) return;
    hlaska('Ukládám pořadí…');
    var fd = new FormData();
    fd.append('csrf', telo.getAttribute('data-poradi'));
    fd.append('akce', 'poradi');
    fd.append('poradi', nove);
    fetch(window.location.pathname + '?p=kategorie', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) throw new Error(j.error || 'Chyba');
        ulozene = nove;
        hlaska('Pořadí uloženo.');
      })
      .catch(function (e) {
        obnovUlozene();
        hlaska('Pořadí se nepodařilo uložit' + (e && e.message && e.message !== 'Chyba' ? ': ' + e.message : '') + '. Načtěte stránku znovu.', true);
      });
  };

  // tažení: řádek se přesouvá mezi ostatními podle polohy ukazatele, uloží se po puštění
  var tazeny = null;
  telo.addEventListener('pointerdown', function (e) {
    var uchyt = e.target.closest('[data-uchyt]');
    if (!uchyt || e.button > 0) return;
    e.preventDefault();
    tazeny = uchyt.closest('tr');
    tazeny.classList.add('tazeny');
    telo.classList.add('tazeni');
    try { uchyt.setPointerCapture(e.pointerId); } catch (x) { /* starší prohlížeč */ }
  });
  telo.addEventListener('pointermove', function (e) {
    if (!tazeny) return;
    e.preventDefault();
    var ostatni = radky().filter(function (r) { return r !== tazeny; });
    var pred = null;
    for (var i = 0; i < ostatni.length; i++) {
      var b = ostatni[i].getBoundingClientRect();
      if (e.clientY < b.top + b.height / 2) { pred = ostatni[i]; break; }
    }
    if (pred !== tazeny.nextElementSibling || (pred === null && telo.lastElementChild !== tazeny)) telo.insertBefore(tazeny, pred);
  });
  var pust = function () {
    if (!tazeny) return;
    tazeny.classList.remove('tazeny');
    telo.classList.remove('tazeni');
    tazeny = null;
    uloz();
  };
  telo.addEventListener('pointerup', pust);
  telo.addEventListener('pointercancel', pust);

  // klávesnice: šipka nahoru/dolů přesune řádek, uloží se chvíli po poslední změně
  telo.addEventListener('keydown', function (e) {
    var uchyt = e.target.closest('[data-uchyt]');
    if (!uchyt || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
    e.preventDefault();
    var r = uchyt.closest('tr');
    if (e.key === 'ArrowUp' && r.previousElementSibling) telo.insertBefore(r, r.previousElementSibling);
    else if (e.key === 'ArrowDown' && r.nextElementSibling) telo.insertBefore(r.nextElementSibling, r);
    else return;
    uchyt.focus();
    clearTimeout(casovac);
    casovac = setTimeout(uloz, 500);
  });
})();

// Štítky (0.8.8): u formuláře Sloučit ukázat, které štítky jsou vybrané; první vybraný nabídnout jako cíl
(function () {
  var form = document.getElementById('f-slouceni');
  if (!form) return;
  var info = form.querySelector('[data-stitky-vybrane]');
  var cil = form.querySelector('[name="cil"]');
  var vychozi = info ? info.textContent : '';
  var obnov = function () {
    var v = Array.prototype.slice.call(document.querySelectorAll('[data-stitek-vyber]:checked')).map(function (c) { return c.value; });
    if (info) info.textContent = v.length ? 'Vybráno ' + v.length + ': ' + v.join(', ') : vychozi;
    if (cil && v.length && (cil.value === '' || cil.dataset.auto === '1')) { cil.value = v[0]; cil.dataset.auto = '1'; }
  };
  if (cil) cil.addEventListener('input', function () { cil.dataset.auto = ''; });
  document.querySelectorAll('[data-stitek-vyber]').forEach(function (c) { c.addEventListener('change', obnov); });
  obnov();
})();

// 0.8.11: Veřejný web → Úvodní část – náhled vedle výběru úvodní fotky se mění s volbou
(function () {
  document.querySelectorAll('[data-uvod-vyber]').forEach(function (sel) {
    var nahled = document.querySelector('[data-uvod-nahled="' + sel.id + '"]');
    if (!nahled) return;
    var img = nahled.querySelector('img'), text = nahled.querySelector('span');
    sel.addEventListener('change', function () {
      var id = parseInt(sel.value, 10);
      if (id > 0) { img.src = 'media.php?id=' + id + '&nahled=1'; img.hidden = false; if (text) text.hidden = true; }
      else { img.removeAttribute('src'); img.hidden = true; if (text) { text.hidden = false; text.textContent = 'Volné místo'; } }
    });
  });
})();

// 0.8.13: Detail média → Upravit obrázek. Bez knihoven: náhled na <canvas> (otočení a překlopení), nad ním výřez s úchyty
// (myš, dotyk – pointer události; šipky na zaměřeném výřezu). Výřez se počítá jako podíl 0–1 z otočeného obrázku,
// server (PHP GD) ho přepočte na pixely a dodrží přesný poměr stran. Nic se neodesílá, dokud uživatel neklikne na Uložit úpravu.
(function () {
  const box = document.querySelector('[data-uprava]');
  if (!box) return;
  const plocha = box.querySelector('[data-plocha-upravy]');
  const platno = box.querySelector('[data-platno]');
  const vyrezEl = box.querySelector('[data-vyrez]');
  const info = box.querySelector('[data-uprava-info]');
  const form = box.querySelector('[data-uprava-formular]');
  const ulozit = box.querySelector('[data-uprava-ulozit]');
  const prohodit = box.querySelector('[data-prohodit]');
  const img = new Image();
  // stav: otočení po směru hodin, překlopení (po otočení), výřez {x, y, w, h} v podílech zobrazeného obrázku (null = celý), poměr [a, b]
  let s = { rot: 0, fh: false, fv: false, vyrez: null };
  let pomerZaklad = null; // [a, b] podle tlačítka (bez prohození)
  let nacteno = false;

  const W = () => (s.rot % 180 ? img.naturalHeight : img.naturalWidth);
  const H = () => (s.rot % 180 ? img.naturalWidth : img.naturalHeight);
  const pomer = () => (pomerZaklad ? (prohodit.checked ? [pomerZaklad[1], pomerZaklad[0]] : pomerZaklad) : null);
  // poměr w/h výřezu v podílech (podíly šířky a výšky mají jiné měřítko)
  const pomerF = () => { const p = pomer(); return p ? (p[0] / p[1]) * (H() / W()) : null; };
  const omez = (v, a, b) => Math.min(b, Math.max(a, v));

  function nejvetsi(rf, stredX, stredY) {
    let w = 1, h = 1 / rf;
    if (h > 1) { h = 1; w = rf; }
    return { x: omez(stredX - w / 2, 0, 1 - w), y: omez(stredY - h / 2, 0, 1 - h), w: w, h: h };
  }

  function prizpusobPomeru() {
    const rf = pomerF();
    if (!rf) return;
    const v = s.vyrez || { x: 0, y: 0, w: 1, h: 1 };
    s.vyrez = nejvetsi(rf, v.x + v.w / 2, v.y + v.h / 2);
  }

  function vykresli() {
    if (!nacteno) return;
    const maxW = Math.max(200, box.clientWidth - 40);
    const maxH = Math.max(200, window.innerHeight * 0.6);
    const k = Math.min(1, maxW / W(), maxH / H());
    const cw = Math.max(1, Math.round(W() * k)), ch = Math.max(1, Math.round(H() * k));
    const dpr = window.devicePixelRatio || 1;
    platno.width = Math.round(cw * dpr); platno.height = Math.round(ch * dpr);
    platno.style.width = cw + 'px'; platno.style.height = ch + 'px';
    const c = platno.getContext('2d');
    c.setTransform(dpr, 0, 0, dpr, 0, 0);
    c.clearRect(0, 0, cw, ch);
    c.translate(cw / 2, ch / 2);
    c.scale(s.fh ? -1 : 1, s.fv ? -1 : 1);
    c.rotate(s.rot * Math.PI / 180);
    const dw = img.naturalWidth * k, dh = img.naturalHeight * k;
    c.drawImage(img, -dw / 2, -dh / 2, dw, dh);
    obnovVyrez();
  }

  function obnovVyrez() {
    const v = s.vyrez;
    vyrezEl.hidden = !v;
    vyrezEl.classList.toggle('s-pomerem', !!pomer());
    if (v) {
      vyrezEl.style.left = (v.x * 100) + '%'; vyrezEl.style.top = (v.y * 100) + '%';
      vyrezEl.style.width = (v.w * 100) + '%'; vyrezEl.style.height = (v.h * 100) + '%';
    }
    const p = pomer();
    let w = W(), h = H();
    if (v) {
      w = Math.max(1, Math.round(v.w * W())); h = Math.max(1, Math.round(v.h * H()));
      if (p) { const g = nsd(p[0], p[1]), a = p[0] / g, b = p[1] / g, n = Math.max(1, Math.min(Math.floor(w / a), Math.floor(h / b))); w = a * n; h = b * n; }
    }
    const zmena = s.rot !== 0 || s.fh || s.fv || (v && (v.w < 0.999 || v.h < 0.999));
    info.textContent = 'Výsledek: ' + w + ' × ' + h + ' px' + (p ? ' (' + p[0] + ':' + p[1] + ')' : '') + (zmena ? '' : ' – zatím beze změny');
    ulozit.disabled = !zmena;
  }
  function nsd(a, b) { while (b) { const t = b; b = a % b; a = t; } return Math.max(1, a); }

  // otočení a překlopení výřez přenesou, ať zůstane na stejném místě fotky
  function otoc(oKolik) {
    const v = s.vyrez;
    if (v) s.vyrez = oKolik === 90 ? { x: 1 - v.y - v.h, y: v.x, w: v.h, h: v.w } : { x: v.y, y: 1 - v.x - v.w, w: v.h, h: v.w };
    s.rot = (s.rot + oKolik + 360) % 360;
    // otočení překlopeného obrázku = překlopení v druhém směru (rot∘flipH = flipV∘rot)
    if (s.fh !== s.fv) { const t = s.fh; s.fh = s.fv; s.fv = t; }
    if (pomerZaklad) prohodit.checked = !prohodit.checked;
  }
  box.querySelectorAll('[data-uprava-akce]').forEach((b) => b.addEventListener('click', () => {
    const a = b.dataset.upravaAkce, v = s.vyrez;
    if (a === 'vlevo') otoc(-90);
    else if (a === 'vpravo') otoc(90);
    else if (a === 'vodorovne') { s.fh = !s.fh; if (v) v.x = 1 - v.x - v.w; }
    else if (a === 'svisle') { s.fv = !s.fv; if (v) v.y = 1 - v.y - v.h; }
    vykresli();
  }));
  box.querySelectorAll('[data-pomer]').forEach((b) => b.addEventListener('click', () => {
    box.querySelectorAll('[data-pomer]').forEach((x) => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
    pomerZaklad = b.dataset.pomer ? b.dataset.pomer.split(':').map(Number) : null;
    prizpusobPomeru();
    obnovVyrez();
  }));
  prohodit.addEventListener('change', () => { if (pomerZaklad) { prizpusobPomeru(); obnovVyrez(); } });
  box.querySelector('[data-uprava-zrusit-vyrez]').addEventListener('click', () => {
    s.vyrez = null; pomerZaklad = null;
    box.querySelectorAll('[data-pomer]').forEach((x) => x.setAttribute('aria-pressed', x.dataset.pomer === '' ? 'true' : 'false'));
    obnovVyrez();
  });
  box.querySelector('[data-uprava-reset]').addEventListener('click', () => {
    s = { rot: 0, fh: false, fv: false, vyrez: null }; pomerZaklad = null; prohodit.checked = false;
    box.querySelectorAll('[data-pomer]').forEach((x) => x.setAttribute('aria-pressed', x.dataset.pomer === '' ? 'true' : 'false'));
    vykresli();
  });

  // tažení: nový výřez (mimo výřez), posun (uvnitř), změna velikosti (úchyt)
  let tah = null;
  function bod(e) {
    const r = platno.getBoundingClientRect();
    return { x: omez((e.clientX - r.left) / r.width, 0, 1), y: omez((e.clientY - r.top) / r.height, 0, 1) };
  }
  // výřez z pevného rohu (kotvy) k bodu; s poměrem se větší strana dopočítá a nevyjede z obrázku
  function zKotvy(ax, ay, px, py) {
    const rf = pomerF(), sx = px >= ax ? 1 : -1, sy = py >= ay ? 1 : -1;
    let w = Math.abs(px - ax), h = Math.abs(py - ay);
    if (rf) {
      w = Math.max(w, h * rf); h = w / rf;
      const maxW = sx > 0 ? 1 - ax : ax, maxH = sy > 0 ? 1 - ay : ay;
      if (w > maxW) { w = maxW; h = w / rf; }
      if (h > maxH) { h = maxH; w = h * rf; }
    }
    return { x: sx > 0 ? ax : ax - w, y: sy > 0 ? ay : ay - h, w: w, h: h };
  }
  plocha.addEventListener('pointerdown', (e) => {
    if (!nacteno || e.button > 0) return;
    e.preventDefault();
    const p = bod(e), v = s.vyrez, u = e.target.dataset ? e.target.dataset.uchyt : null;
    if (u && v) {
      // kotva = protilehlý roh / hrana
      tah = { typ: 'uchyt', u: u, ax: u.includes('w') ? v.x + v.w : v.x, ay: u.includes('n') ? v.y + v.h : v.y, puvodni: { ...v } };
    } else if (v && e.target === vyrezEl) {
      tah = { typ: 'posun', dx: p.x - v.x, dy: p.y - v.y };
    } else {
      tah = { typ: 'novy', ax: p.x, ay: p.y };
    }
    plocha.setPointerCapture(e.pointerId);
  });
  plocha.addEventListener('pointermove', (e) => {
    if (!tah) return;
    const p = bod(e);
    let v = s.vyrez;
    if (tah.typ === 'posun') {
      v.x = omez(p.x - tah.dx, 0, 1 - v.w); v.y = omez(p.y - tah.dy, 0, 1 - v.h);
    } else if (tah.typ === 'novy') {
      if (Math.abs(p.x - tah.ax) < 0.01 && Math.abs(p.y - tah.ay) < 0.01) return;
      s.vyrez = zKotvy(tah.ax, tah.ay, p.x, p.y);
    } else {
      const u = tah.u, o = tah.puvodni;
      if (u.length === 2 || pomer()) {
        s.vyrez = zKotvy(tah.ax, tah.ay, u.includes('e') || u.includes('w') ? p.x : (u.includes('w') ? o.x : o.x + o.w), u.includes('n') || u.includes('s') ? p.y : o.y + o.h);
      } else if (u === 'n' || u === 's') {
        const y1 = u === 'n' ? p.y : o.y, y2 = u === 's' ? p.y : o.y + o.h;
        s.vyrez = { x: o.x, w: o.w, y: Math.min(y1, y2), h: Math.abs(y2 - y1) };
      } else {
        const x1 = u === 'w' ? p.x : o.x, x2 = u === 'e' ? p.x : o.x + o.w;
        s.vyrez = { y: o.y, h: o.h, x: Math.min(x1, x2), w: Math.abs(x2 - x1) };
      }
    }
    obnovVyrez();
  });
  const konec = () => {
    if (!tah) return;
    tah = null;
    // příliš malý výřez (pouhé kliknutí) = žádný výřez
    if (s.vyrez && (s.vyrez.w * W() < 8 || s.vyrez.h * H() < 8)) s.vyrez = pomer() ? nejvetsi(pomerF(), .5, .5) : null;
    obnovVyrez();
  };
  plocha.addEventListener('pointerup', konec);
  plocha.addEventListener('pointercancel', konec);
  vyrezEl.addEventListener('keydown', (e) => {
    const v = s.vyrez, d = e.shiftKey ? 0.1 : 0.01;
    const posun = { ArrowLeft: [-d, 0], ArrowRight: [d, 0], ArrowUp: [0, -d], ArrowDown: [0, d] }[e.key];
    if (!v || !posun) return;
    e.preventDefault();
    v.x = omez(v.x + posun[0], 0, 1 - v.w); v.y = omez(v.y + posun[1], 0, 1 - v.h);
    obnovVyrez();
  });

  form.addEventListener('submit', () => {
    const v = s.vyrez, p = pomer();
    const hodnoty = { otoceni: s.rot, preklopit_h: s.fh ? 1 : '', preklopit_v: s.fv ? 1 : '', pomer: p && v ? p[0] + ':' + p[1] : '',
      orez_x: v ? v.x.toFixed(6) : '', orez_y: v ? v.y.toFixed(6) : '', orez_w: v ? v.w.toFixed(6) : '', orez_h: v ? v.h.toFixed(6) : '' };
    Object.keys(hodnoty).forEach((k) => { form.elements[k].value = hodnoty[k]; });
    ulozit.disabled = true;
    ulozit.textContent = 'Ukládám…';
  });

  function otevri() {
    box.open = true;
    if (img.src) return;
    img.onload = () => { nacteno = true; vykresli(); };
    img.onerror = () => { info.textContent = 'Obrázek se nepodařilo načíst.'; };
    img.src = box.dataset.src;
  }
  box.addEventListener('toggle', () => { if (box.open) otevri(); });
  document.querySelectorAll('[data-otevrit-upravu]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); otevri(); box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  if (location.hash === '#uprava') otevri();
  let cekani = null;
  window.addEventListener('resize', () => { clearTimeout(cekani); cekani = setTimeout(vykresli, 150); });
})();

// ---------- 0.8.15: ohnisko fotky pro web (detail média) ----------
// Kliknutí (nebo tažení) do fotky nastaví bod v procentech; náhledy ukazují výřez úvodní fotky na počítači a mobilu, mřížky a karty galerie.
(() => {
  const box = document.querySelector('[data-ohnisko]');
  if (!box) return;
  const plocha = box.querySelector('[data-ohnisko-plocha]');
  const bod = box.querySelector('[data-ohnisko-bod]');
  const px = box.querySelector('[data-ohnisko-x]'), py = box.querySelector('[data-ohnisko-y]');
  const cela = box.querySelector('[data-ohnisko-cela]');
  const nahledy = box.querySelector('[data-ohnisko-nahledy]');
  const cislo = (el) => { const v = el.value.trim(); if (v === '') return null; const n = Math.round(Number(v.replace(',', '.'))); return Number.isFinite(n) ? Math.max(0, Math.min(100, n)) : null; };
  function ukaz() {
    const x = cislo(px), y = cislo(py), ma = x !== null && y !== null;
    bod.hidden = !ma;
    if (ma) { bod.style.left = x + '%'; bod.style.top = y + '%'; }
    nahledy.classList.toggle('cela', cela.checked);
    const bez = box.querySelector('[data-ohnisko-bez]');
    if (bez) bez.hidden = ma || cela.checked;
    nahledy.querySelectorAll('img.popredi').forEach((i) => { i.style.objectPosition = (ma ? x : 50) + '% ' + (ma ? y : Number(i.dataset.vychoziY || 50)) + '%'; });
  }
  function nastav(e) {
    const r = plocha.querySelector('img').getBoundingClientRect();
    if (!r.width || !r.height) return;
    px.value = Math.round(Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)) * 100);
    py.value = Math.round(Math.max(0, Math.min(1, (e.clientY - r.top) / r.height)) * 100);
    ukaz();
  }
  let tazeni = false;
  plocha.addEventListener('pointerdown', (e) => { tazeni = true; plocha.setPointerCapture(e.pointerId); nastav(e); e.preventDefault(); });
  plocha.addEventListener('pointermove', (e) => { if (tazeni) nastav(e); });
  plocha.addEventListener('pointerup', () => { tazeni = false; });
  plocha.addEventListener('pointercancel', () => { tazeni = false; });
  [px, py].forEach((el) => el.addEventListener('input', ukaz));
  cela.addEventListener('change', ukaz);
  document.querySelectorAll('[data-otevrit-ohnisko]').forEach((a) => a.addEventListener('click', (e) => {
    e.preventDefault(); box.open = true; box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }));
  if (location.hash === '#ohnisko') box.open = true;
  ukaz();
})();
