// TWB Media galerie – drobnosti administrace: potvrzení akcí, menu, kopírování do schránky, Passkey, odhlášení po nečinnosti.
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
    };
    hromadne.addEventListener('change', function (e) {
      if (e.target === vse) boxy().forEach(function (b) { b.checked = vse.checked; });
      obnov();
    });
    hromadne.addEventListener('submit', function (e) {
      var n = boxy().filter(function (b) { return b.checked; }).length;
      if (akce.value === 'smazat' && !window.confirm('Smazat ' + n + ' vybraných souborů? Veřejné odkazy přestanou fungovat.')) e.preventDefault();
    });
    obnov();
  }

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
        if (tagy && tagy.value) fd.append('tagy', tagy.value);
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
