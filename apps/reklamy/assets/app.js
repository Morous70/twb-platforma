// TWB Reklamy – drobnosti administrace: potvrzení akcí, menu, Passkey, formulář banneru, odhlášení po nečinnosti.
(function () {
  'use strict';

  // Potvrzení před odesláním (smazání apod.)
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.dataset && f.dataset.potvrdit && !window.confirm(f.dataset.potvrdit)) e.preventDefault();
  });

  // Přepínač menu na mobilu
  document.querySelectorAll('[data-prepni]').forEach(function (b) {
    b.addEventListener('click', function () { document.getElementById(b.dataset.prepni).classList.toggle('otevreno'); });
  });

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

  // Rozměr z nabídky běžných rozměrů
  document.querySelectorAll('[data-rozmery]').forEach(function (s) {
    s.addEventListener('change', function () {
      if (!s.value) return;
      var r = s.value.split('x');
      document.getElementById(s.dataset.sirka || 'f-width').value = r[0];
      document.getElementById(s.dataset.vyska || 'f-height').value = r[1];
      s.value = '';
    });
  });

  // Statistiky: změna data přepne období na vlastní, výběr období formulář rovnou odešle
  var obdobi = document.querySelector('form.filtr #f-obdobi');
  if (obdobi) {
    obdobi.addEventListener('change', function () { if (obdobi.value !== 'vlastni') obdobi.form.submit(); });
    ['f-od', 'f-do'].forEach(function (id) {
      var d = document.getElementById(id);
      if (d) d.addEventListener('change', function () { obdobi.value = 'vlastni'; });
    });
  }

  // Formulář banneru: obrázek z galerie doplní rozměr
  var media = document.getElementById('f-media_id');
  if (media) media.addEventListener('change', function () {
    var o = media.options[media.selectedIndex];
    if (o && o.dataset.w) {
      document.getElementById('f-width').value = o.dataset.w;
      document.getElementById('f-height').value = o.dataset.h;
    }
    var n = document.getElementById('nahled-galerie');
    if (n) n.src = o && o.value ? 'media.php?id=' + o.value : '';
  });

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
