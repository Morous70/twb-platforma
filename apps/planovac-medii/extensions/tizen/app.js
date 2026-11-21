// TWB Plánovač médií – přehrávač pro TV Samsung (Tizen 4.0 a novější).
// Párování kódem, stažení médií ze složky přehrávače (přes now.php) do úložiště televize, náhodné přehrávání,
// ovládání ovladačem, hlášení stavu a příkazy z aplikace (každých 15 s). Psáno bez novější syntaxe JavaScriptu (žádné ?. ?? ani moduly), aby běželo i na starších televizích.
(function () {
  'use strict';
  // stejný přehrávač běží na TV Samsung (Tizen) i v aplikaci pro Android TV (rozhraní window.TwbAndroid)
  var ANDROID = !!window.TwbAndroid;
  var KIND = ANDROID ? 'android' : 'tizen';
  var VERSION = ANDROID ? String(window.TwbAndroid.version()) : '1.2.0';
  var CLIENT = KIND + '/' + VERSION;
  var REFRESH_MS = 5 * 60 * 1000;
  var KEYS = { ok: [13], back: [10009, 27, 8], right: [39], up: [38], down: [40], left: [37],
    pause: [10252, 415, 19, 32], green: [404, 66] };
  var KIND_LABEL = { image: 'obrázek', video: 'video', pdf: 'PDF – první stránka', text: 'text', html: 'HTML dokument' };

  function $(id) { return document.getElementById(id); }
  function has(list, code) { return list.indexOf(code) >= 0; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function hm(t) { if (!t) return '–'; var d = new Date(t); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2); }
  function plural(n, a, b, c) { return n === 1 ? a : n >= 2 && n <= 4 ? b : c; }
  function assign(a, b) { for (var k in b) if (Object.prototype.hasOwnProperty.call(b, k)) a[k] = b[k]; return a; }
  function store(k, v) { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* plné úložiště */ } }
  function load(k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } }
  function normBase(b) {
    b = String(b || '').trim().replace(/\/+(now\.php|index\.php)?$/i, '').replace(/\/+$/, '');
    if (b && !/^https?:\/\//i.test(b)) b = 'https://' + b;
    return b;
  }

  // ---------- nastavení: adresa aplikace (z config.js, lze změnit) a klíč zařízení ----------
  var cfg = load('twb-tv-config') || {};
  if (!cfg.base) cfg.base = normBase((window.TWB_CONFIG || {}).base || '');
  function saveCfg() { store('twb-tv-config', cfg); }

  // ---------- televize: spořič obrazovky, klávesy ovladače ----------
  function tvSetup() {
    try {
      tizen.tvinputdevice.registerKeyBatch(['MediaPlayPause', 'MediaPlay', 'MediaPause', 'ColorF1Green']);
    } catch (e) { /* mimo televizi */ }
    keepAwake();
  }
  function keepAwake() {
    try { webapis.appcommon.setScreenSaver(webapis.appcommon.AppCommonScreenSaverState.SCREEN_SAVER_OFF, function () {}, function () {}); } catch (e) { /* mimo televizi */ }
  }

  // ---------- úložiště souborů v televizi (IndexedDB) ----------
  var dbp = null;
  function db() {
    if (!dbp) dbp = new Promise(function (res, rej) {
      var r = indexedDB.open('twb-media', 1);
      r.onupgradeneeded = function () { r.result.createObjectStore('files'); };
      r.onsuccess = function () { res(r.result); };
      r.onerror = function () { rej(r.error); };
    });
    return dbp;
  }
  function tx(mode, fn) {
    return db().then(function (d) {
      return new Promise(function (res, rej) {
        var t = d.transaction('files', mode), st = t.objectStore('files'), out;
        var req = fn(st);
        if (req) req.onsuccess = function () { out = req.result; };
        t.oncomplete = function () { res(out); };
        t.onerror = function () { rej(t.error); };
        t.onabort = function () { rej(t.error || new Error('zápis přerušen')); };
      });
    });
  }
  function dbGet(k) { return tx('readonly', function (s) { return s.get(k); }); }
  function dbPut(k, blob) { return tx('readwrite', function (s) { return s.put(blob, k); }); }
  function dbDel(k) { return tx('readwrite', function (s) { return s.delete(k); }); }
  function dbKeys() { return tx('readonly', function (s) { return s.getAllKeys ? s.getAllKeys() : null; }).then(function (k) { return k || []; }); }

  // ---------- stav ----------
  var state = load('twb-tv-state') || {};   // {ok, error, errorAt, at, device, playlist, warning, files, total, done}
  var streamOnly = load('twb-tv-stream') || {};  // klíče souborů, které se nevešly do televize → přehrát ze serveru
  function saveState(p) { assign(state, p); store('twb-tv-state', state); renderStatus(); renderErrIcon(); }
  function fileKey(f) { return f.md5 ? f.md5 : 'id-' + f.id + '-' + (f.size == null ? '' : f.size); }
  function playable(f) { return ['image', 'video', 'pdf', 'text', 'html'].indexOf(f.kind) >= 0; }
  function files() { return (state.files || []).filter(playable); }
  function fileUrl(f) { return cfg.base + '/now.php?file=' + encodeURIComponent(f.id) + '&key=' + encodeURIComponent(cfg.key); }

  function api(path) {
    return fetch(cfg.base + path, { headers: { 'X-Device-Key': cfg.key || '', 'X-Client': CLIENT }, cache: 'no-store' })
      .catch(function () { throw new Error('Server neodpovídá (zkontrolujte připojení televize k internetu).'); })
      .then(function (r) {
        if (r.ok) return r;
        return r.json().catch(function () { return {}; }).then(function (j) {
          var e = new Error(j.error || 'chyba HTTP ' + r.status); e.status = r.status; throw e;
        });
      });
  }
  function maxCache() { var m = +(localStorage.getItem('twb-max-cache') || 0); return m > 0 ? m : Infinity; }

  var syncing = null;
  function sync() {
    if (!syncing) syncing = doSync().then(function (v) { syncing = null; return v; }, function (e) { syncing = null; throw e; });
    return syncing;
  }
  function doSync() {
    if (!cfg.key) { showPair(); return Promise.resolve(); }
    keepAwake();
    var list, want, have = {}, info;
    return api('/now.php').then(function (r) { return r.json(); }).then(function (j) {
      info = j;
      list = (j.files || []).map(function (f) { f.key = fileKey(f); return f; });
      want = list.filter(playable);
      return dbKeys();
    }).then(function (keys) {
      keys.forEach(function (k) { have[k] = true; });
      var doneCount = function () { return want.filter(function (f) { return have[f.key] || streamOnly[f.key]; }).length; };
      saveState({ ok: true, error: null, at: Date.now(), device: info.device, playlist: info.playlist, files: list, total: want.length, done: doneCount(), change: info.change || 0 });
      return list;
    }).then(function () {
      // stáhnout chybějící soubory jeden po druhém
      var used = 0, failed = 0, lastErr = '';
      var chain = Promise.resolve();
      want.forEach(function (f) {
        chain = chain.then(function () {
          if (have[f.key]) { used += f.size || 0; return; }
          if (used + (f.size || 0) > maxCache()) { streamOnly[f.key] = true; return; }
          return api('/now.php?file=' + encodeURIComponent(f.id)).then(function (r) { return r.blob(); }).then(function (b) {
            if (f.size != null && b.size !== f.size) throw new Error('soubor nedorazil celý');
            return dbPut(f.key, b).then(function () { have[f.key] = true; used += b.size; delete streamOnly[f.key]; },
              function () { streamOnly[f.key] = true; });   // nevešlo se → přehrát ze serveru
          }).then(function () {
            saveState({ done: want.filter(function (x) { return have[x.key] || streamOnly[x.key]; }).length });
          }, function (e) { failed++; lastErr = f.name + ': ' + e.message; });
        });
      });
      return chain.then(function () {
        // uklidit soubory, které ve složce už nejsou
        var keep = {};
        want.forEach(function (f) { keep[f.key] = true; });
        Object.keys(have).forEach(function (k) { if (!keep[k]) dbDel(k); });
        Object.keys(streamOnly).forEach(function (k) { if (!keep[k]) delete streamOnly[k]; });
        store('twb-tv-stream', streamOnly);
        saveState({ done: want.filter(function (x) { return have[x.key] || streamOnly[x.key]; }).length,
          failed: failed, failMsg: failed ? 'Soubor nejde stáhnout – ' + lastErr : null, failAt: failed ? Date.now() : null,
          warning: failed ? 'Nepodařilo se stáhnout ' + failed + ' ' + plural(failed, 'soubor', 'soubory', 'souborů') + ' (' + lastErr + '). Zkusí se to znovu při dalším obnovení.' : (info.warning || null) });
      });
    }).then(function () {
      if (!cur) next();
    }, function (e) {
      if (e.status === 403) { unpair('Televize už není spárovaná (zařízení bylo v aplikaci odebráno nebo má nový klíč).'); return; }
      saveState({ ok: false, error: e.message, errorAt: Date.now() });
      if (!cur) next();
    });
  }

  // ---------- párování ----------
  var pairTimer = null, pairPoll = null;
  function showPair(err) {
    stopAll();
    $('pair').hidden = false;
    $('pairFoot').textContent = (cfg.base ? cfg.base.replace(/^https?:\/\//, '') : 'adresa aplikace není nastavená') + ' · verze ' + VERSION;
    if (!cfg.base) { pairError('V aplikaci chybí adresa Plánovače. Podržte tlačítko Zpět a zadejte ji v nastavení.'); return; }
    if (err) pairError(err); else $('pairErr').hidden = true;
    clearTimeout(pairTimer);
    fetch(cfg.base + '/pair.php?a=start&kind=' + KIND, { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) throw new Error(j.error || 'kód se nepodařilo získat');
      $('pairCode').textContent = j.code.slice(0, 3) + ' ' + j.code.slice(3);
      pairPoll = j.poll;
      pairTimer = setTimeout(function () { poll(j.interval || 3); }, (j.interval || 3) * 1000);
    }).catch(function (e) {
      $('pairCode').textContent = '··· ···';
      pairError('Párovací kód se nepodařilo získat: ' + (e.message || e) + ' Zkusím to znovu za 15 s.');
      pairTimer = setTimeout(function () { showPair(); }, 15000);
    });
  }
  function pairError(t) { $('pairErr').textContent = t; $('pairErr').hidden = false; }
  function poll(interval) {
    if ($('pair').hidden) return;
    fetch(cfg.base + '/pair.php?a=poll&t=' + encodeURIComponent(pairPoll), { cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.status === 'paired') {
        cfg.key = j.key; saveCfg();
        $('pair').hidden = true;
        saveState({ ok: true, error: null, device: j.device });
        refreshNow();   // stáhne média a spustí pravidelné obnovování
        return;
      }
      if (j.status === 'expired') { showPair(); return; }
      pairTimer = setTimeout(function () { poll(interval); }, interval * 1000);
    }).catch(function () { pairTimer = setTimeout(function () { poll(interval); }, interval * 1000); });
  }
  function unpair(msg) {
    cfg.key = ''; saveCfg();
    saveState({ ok: true, error: null, files: [], total: 0, done: 0, device: null, playlist: null, warning: null });
    showPair(msg);
  }

  // ---------- přehrávání ----------
  var queue = [], played = {}, lastKey = null, cur = null, paused = false, waitTimer = null, loading = false;
  function shuffle(a) { for (var i = a.length - 1; i > 0; i--) { var j = Math.floor(Math.random() * (i + 1)); var t = a[i]; a[i] = a[j]; a[j] = t; } return a; }
  function reconcile() {
    var keys = {}; files().forEach(function (f) { keys[f.key] = true; });
    queue = queue.filter(function (k) { return keys[k]; });
    Object.keys(played).forEach(function (k) { if (!keys[k]) delete played[k]; });
    Object.keys(keys).forEach(function (k) {
      if (queue.indexOf(k) >= 0 || played[k]) return;
      queue.splice(Math.floor(Math.random() * (queue.length + 1)), 0, k);
    });
  }
  function pickKey() {
    reconcile();
    if (!queue.length) {
      played = {};
      queue = shuffle(files().map(function (f) { return f.key; }));
      if (queue.length > 1 && queue[0] === lastKey) { var t = queue[0]; queue[0] = queue[1]; queue[1] = t; }
    }
    var k = queue.shift();
    if (k) played[k] = true;
    return k;
  }
  function byKey(k) { var l = files(); for (var i = 0; i < l.length; i++) if (l[i].key === k) return l[i]; return null; }
  function message(html) { $('msg').innerHTML = html; $('msg').hidden = !html; }

  function next() {
    if (loading || !$('pair').hidden) return Promise.resolve();
    loading = true;
    return nextInner().then(function () { loading = false; }, function () { loading = false; });
  }
  function nextInner() {
    clearTimeout(waitTimer);
    var list = files();
    if (!list.length) {
      stop();
      message(!state.ok && state.error ? '<div><b>Seznam se nepodařilo načíst</b>' + esc(state.error) + '</div>'
        : '<div><b>Ve složce přehrávače teď nic není</b>Zkusím to znovu po obnovení seznamu.</div>');
      waitTimer = setTimeout(next, 10000);
      return Promise.resolve();
    }
    var tries = 0;
    function attempt() {
      if (tries++ >= list.length) {
        stop();
        message('<div><b>Stahuji média…</b>' + (state.done || 0) + ' z ' + (state.total || list.length) + '</div>');
        waitTimer = setTimeout(next, 3000);
        return Promise.resolve();
      }
      var f = byKey(pickKey());
      if (!f) return attempt();
      return source(f).then(function (src) {
        if (!src) return attempt();
        message('');
        return show(f, src).catch(function (e) { if (window.console) console.warn('Médium nejde zobrazit', f.name, e); playError(f, e); return attempt(); });
      });
    }
    return attempt();
  }
  /** {url, blob} z úložiště televize, nebo adresa na serveru u souborů, které se nevešly. */
  function source(f) {
    return dbGet(f.key).then(function (blob) {
      if (blob) return { url: URL.createObjectURL(blob), blob: blob, local: true };
      if (streamOnly[f.key] && cfg.key) return { url: fileUrl(f), blob: null, local: false };
      return null;
    }, function () { return null; });
  }
  function blobOf(src) {
    return src.blob ? Promise.resolve(src.blob) : fetch(src.url).then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.blob(); });
  }
  function readText(blob) { return new Response(blob).text(); }

  function stop() {
    if (!cur) return;
    clearTimeout(cur.timer);
    var old = cur; cur = null;
    if (old.video) { old.video.pause(); }
    old.el.className = old.el.className.replace(/\s*\bon\b/, '');
    setTimeout(function () {
      if (old.el.parentNode) old.el.parentNode.removeChild(old.el);
      if (old.video) { old.video.removeAttribute('src'); try { old.video.load(); } catch (e) { /* nic */ } }
      if (old.local) URL.revokeObjectURL(old.url);
    }, 600);
  }
  function stopAll() { clearTimeout(waitTimer); stop(); message(''); }

  function show(f, src) {
    var dur = ((state.device && state.device.duration) || 20) * 1000;
    var make;
    if (f.kind === 'image') {
      make = new Promise(function (res, rej) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = function () { rej(new Error('obrázek nejde načíst')); }; i.src = src.url; });
    } else if (f.kind === 'video') {
      var v = document.createElement('video');
      v.src = src.url;
      v.onended = function () { next(); };
      v.onerror = function () { playError(f, new Error('video nejde přehrát')); next(); };
      make = Promise.resolve(v);
    } else if (f.kind === 'pdf') {
      make = blobOf(src).then(renderPdf);
    } else if (f.kind === 'text') {
      make = blobOf(src).then(readText).then(function (t) {
        var el = document.createElement('div'); el.className = 'text';
        var d = document.createElement('div'); d.textContent = t.trim(); el.appendChild(d);
        return el;
      });
    } else if (f.kind === 'html') {
      make = blobOf(src).then(readText).then(htmlEl);
    } else {
      return Promise.reject(new Error('nepodporovaný typ'));
    }
    return make.then(function (el) {
      if (el.parentNode !== $('stage')) $('stage').appendChild(el); // HTML rám už na ploše je – přesunutí by ho načetlo znovu
      if (f.kind === 'text') fitText(el.firstChild);
      stop();
      var video = f.kind === 'video' ? el : null;
      cur = { file: f, el: el, url: src.url, local: src.local, video: video, timer: null, start: 0, remaining: dur, total: dur, at: Date.now() };
      lastKey = f.key;
      setTimeout(function () { el.className += ' on'; }, 20);
      renderStatus();
      if (video) {
        var p = video.play();
        if (p && p.catch) p.catch(function () { video.muted = true; var p2 = video.play(); if (p2 && p2.catch) p2.catch(function () { next(); }); });
        if (paused) video.pause();
      } else if (!paused) {
        startTimer();
      }
    });
  }
  // HTML dokument: izolovaný rám (sandbox jen se skripty – bez přístupu k přehrávači a klíči), plocha 1920×1080 zmenšená na obrazovku
  function htmlEl(html) {
    return new Promise(function (res, rej) {
      var box = document.createElement('div'); box.className = 'html';
      var fr = document.createElement('iframe');
      fr.setAttribute('sandbox', 'allow-scripts');
      fr.setAttribute('tabindex', '-1');
      fr.width = 1920; fr.height = 1080;
      var t = setTimeout(function () { rej(new Error('HTML dokument se nenačetl')); }, 15000);
      box.appendChild(fr);
      fitHtml(box);
      $('stage').appendChild(box);
      // dokument až do rámu, který je na ploše – skript v dokumentu jinak vidí velikost okna 0×0
      fr.onload = function () { clearTimeout(t); fr.onload = null; res(box); };
      // až po vykreslení plochy (rám už má rozměr)
      requestAnimationFrame(function () { setTimeout(function () { fr.srcdoc = html; }, 30); });
    });
  }
  function fitHtml(box) {
    var k = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
    box.firstChild.style.transform = 'translate(-50%, -50%) scale(' + k + ')';
  }
  window.addEventListener('resize', function () {
    var l = document.querySelectorAll('#stage .html');
    for (var i = 0; i < l.length; i++) fitHtml(l[i]);
  });
  function startTimer() {
    if (!cur || cur.video) return;
    cur.start = Date.now();
    cur.timer = setTimeout(next, cur.remaining);
  }
  function togglePause() {
    paused = !paused;
    if (!cur) return;
    if (cur.video) { if (paused) cur.video.pause(); else cur.video.play(); return; }
    if (paused) { clearTimeout(cur.timer); cur.remaining -= Date.now() - cur.start; } else startTimer();
    renderStatus();
  }

  var pdfLoad = null;
  function pdfjs() {
    // pdf.js potřebuje novější prohlížeč (televize zhruba od roku 2021); na starší televizi se PDF přeskočí
    if (!pdfLoad) pdfLoad = new Promise(function (res, rej) {
      var s = document.createElement('script');
      s.src = 'lib/pdf.min.js';
      s.onload = function () { if (window.pdfjsLib) { pdfjsLib.GlobalWorkerOptions.workerSrc = 'lib/pdf.worker.min.js'; res(window.pdfjsLib); } else rej(new Error('PDF na této televizi nejde zobrazit')); };
      s.onerror = function () { rej(new Error('pdf.js chybí')); };
      document.head.appendChild(s);
    });
    return pdfLoad;
  }
  function renderPdf(blob) {
    var pdf, page;
    return pdfjs().then(function (lib) { return new Response(blob).arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf, isEvalSupported: false }).promise; }); })
      .then(function (p) { pdf = p; return p.getPage(1); })
      .then(function (pg) {
        page = pg;
        var vp1 = page.getViewport({ scale: 1 });
        var vp = page.getViewport({ scale: Math.min(innerWidth / vp1.width, innerHeight / vp1.height) });
        var c = document.createElement('canvas');
        c.width = Math.floor(vp.width); c.height = Math.floor(vp.height);
        var ctx = c.getContext('2d');
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        return page.render({ canvasContext: ctx, viewport: vp }).promise.then(function () { pdf.destroy(); return c; });
      });
  }
  /** Největší písmo, které se vejde (nejdřív bez zalamování řádků). */
  function fitText(t) {
    function fit(wrap) {
      t.style.whiteSpace = wrap ? 'pre-wrap' : 'pre';
      var lo = 16, hi = 300;
      while (hi - lo > 1) {
        var mid = (lo + hi) >> 1;
        t.style.fontSize = mid + 'px';
        if (t.scrollWidth <= innerWidth * 0.9 && t.scrollHeight <= innerHeight * 0.8) lo = mid; else hi = mid;
      }
      t.style.fontSize = lo + 'px';
      return lo;
    }
    if (fit(false) < 64) fit(true);
  }

  // ---------- stav na obrazovce ----------
  var statusTimer = null;
  function renderErrIcon() {
    var bad = state.ok === false && cfg.key && $('pair').hidden;
    $('errIcon').hidden = !bad;
    if (bad) $('errIcon').textContent = '! ' + (state.at ? hm(state.at) : '–');
  }
  function renderStatus() {
    if ($('status').hidden) return;
    var l = files(), videos = l.filter(function (f) { return f.kind === 'video'; }).length;
    var pl = state.playlist;
    $('stTitle').textContent = 'TWB přehrávač' + (state.device ? ' · ' + state.device.name : '');
    $('stErr').hidden = state.ok !== false;
    $('stErr').textContent = state.ok === false ? 'Seznam se nepodařilo obnovit (' + hm(state.errorAt) + '): ' + state.error + (l.length ? ' Přehrává se poslední stažený obsah.' : '') : '';
    $('stWarn').hidden = !state.warning; $('stWarn').textContent = state.warning || '';
    var rows = [];
    if (pl) rows.push(['Běží', (pl.active.join(', ') || (pl.mode === 'empty' ? 'nic' : '–')) + (pl.fixed.length ? ' · pevný: ' + pl.fixed.join(', ') : '')]);
    var stream = l.filter(function (f) { return streamOnly[f.key]; }).length;
    rows.push(['Médií', l.length + (videos ? ' (' + videos + ' ' + plural(videos, 'video', 'videa', 'videí') + ')' : '') + ' · staženo ' + (state.done || 0) + '/' + (state.total || 0)
      + (stream ? ' · ' + stream + ' ze serveru (nevešlo se)' : '') + (syncing ? ' · stahuji…' : '') + (freeText ? ' · ' + freeText : '')]);
    rows.push(['Obnoveno', hm(state.at) + (nextAt ? ' · další v ' + hm(nextAt) : '')]);
    if (state.device) rows.push(['Zobrazení', state.device.duration + ' s (videa celá)']);
    if (cur) rows.push(['Médium', cur.file.name + ' · ' + KIND_LABEL[cur.file.kind] + (paused ? ' · pozastaveno' : '')]);
    $('stGrid').innerHTML = rows.map(function (r) { return '<span>' + esc(r[0]) + '</span><b>' + esc(r[1]) + '</b>'; }).join('');
  }
  var freeText = '';
  function updateFree() {
    try {
      if (navigator.storage && navigator.storage.estimate) navigator.storage.estimate().then(function (e) {
        if (e && e.quota) freeText = 'volno ' + ((e.quota - e.usage) / 1e9).toFixed(1).replace('.', ',') + ' GB';
      });
    } catch (e) { /* neumí */ }
  }
  function toggleStatus(show) {
    var open = show === undefined ? $('status').hidden : show;
    $('status').hidden = !open;
    clearTimeout(statusTimer);
    if (open) { updateFree(); renderStatus(); $('stRefresh').focus(); statusTimer = setTimeout(function () { toggleStatus(false); }, 10000); }
    else if (document.activeElement) document.activeElement.blur();
  }
  function refreshNow() {
    nextAt = Date.now() + REFRESH_MS;
    clearInterval(refreshTimer); refreshTimer = setInterval(timedRefresh, REFRESH_MS);
    var p = sync(); renderStatus();
    return p.then(renderStatus, renderStatus);
  }

  // ---------- nastavení (podržet Zpět) ----------
  function openSettings() {
    toggleStatus(false);
    $('settings').hidden = false;
    $('setBase').value = cfg.base || '';
    $('setNote').textContent = '';
    $('setInfo').innerHTML = '<span>Zařízení</span><b>' + esc(state.device ? state.device.name : (cfg.key ? '–' : 'nespárováno')) + '</b><span>Verze</span><b>' + VERSION + '</b>';
    $('setUnpair').hidden = !cfg.key;
    $('setClose').focus();
  }
  function closeSettings() { $('settings').hidden = true; if (document.activeElement) document.activeElement.blur(); }
  function focusables() { return [].slice.call($('settings').querySelectorAll('input, button')).filter(function (e) { return !e.hidden; }); }
  function moveFocus(d) {
    var f = focusables(), i = f.indexOf(document.activeElement);
    f[(i + d + f.length) % f.length].focus();
  }
  $('setSave').onclick = function () {
    var b = normBase($('setBase').value);
    if (!b) { $('setNote').textContent = 'Zadejte adresu aplikace, např. https://trafikabr.cz/media-planner'; return; }
    var changed = b !== cfg.base;
    cfg.base = b; saveCfg();
    $('setNote').textContent = 'Adresa uložena.';
    if (changed) { closeSettings(); unpair(); }
  };
  $('setUnpair').onclick = function () { closeSettings(); unpair('Spárování zrušeno. Pro nové spárování zadejte kód v aplikaci.'); };
  $('setClose').onclick = closeSettings;
  $('stRefresh').onclick = function () { refreshNow(); };

  // ---------- ovladač ----------
  var backDown = null, backTimer = null;
  document.addEventListener('keydown', function (e) {
    var k = e.keyCode;
    if (has(KEYS.back, k) && e.target && e.target.tagName === 'INPUT' && k === 8) return; // mazání v poli
    if (has(KEYS.back, k)) {
      e.preventDefault();
      if (backDown) return;                // opakování při podržení
      backDown = Date.now();
      backTimer = setTimeout(function () { backTimer = null; openSettings(); }, 1000);
      return;
    }
    if (!$('settings').hidden) {
      if (has(KEYS.up, k)) { e.preventDefault(); moveFocus(-1); }
      else if (has(KEYS.down, k)) { e.preventDefault(); moveFocus(1); }
      return;   // OK aktivuje tlačítko samo (výchozí chování)
    }
    if (!$('pair').hidden) return;
    if (has(KEYS.green, k)) { e.preventDefault(); refreshNow(); }
    else if (has(KEYS.pause, k)) { e.preventDefault(); togglePause(); }
    else if (has(KEYS.right, k)) { e.preventDefault(); next(); }
    else if (has(KEYS.ok, k)) {
      if (!$('status').hidden) return;     // v okně stavu OK stiskne „Obnovit seznam“
      e.preventDefault(); togglePause();
    }
  });
  document.addEventListener('keyup', function (e) {
    if (!has(KEYS.back, e.keyCode)) return;
    if (e.target && e.target.tagName === 'INPUT' && e.keyCode === 8) return;
    e.preventDefault();
    backDown = null;
    if (!backTimer) return;               // dlouhé podržení už otevřelo nastavení
    clearTimeout(backTimer); backTimer = null;
    backShort();
  });
  function backShort() {
    if (!$('settings').hidden) closeSettings();
    else if ($('pair').hidden) toggleStatus();
  }
  /** Tlačítka, která předává aplikace pro Android (Zpět, Přehrát/Pauza, zelené, další). */
  function remoteKey(name) {
    if (name === 'back') { backShort(); return; }
    if (name === 'backLong') { openSettings(); return; }
    if (!$('settings').hidden || !$('pair').hidden) return;
    if (name === 'pause') togglePause();
    else if (name === 'green') refreshNow();
    else if (name === 'next') next();
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { if (cur && cur.video) cur.video.pause(); }
    else { if (cur && cur.video && !paused) cur.video.play(); keepAwake(); if (cfg.key) sync(); }
  });

  // ---------- hlášení stavu a příkazy z aplikace (Obnovit teď, Restartovat přehrávač, Další soubor) ----------
  var playErr = null, lastAuto = 0, doneCmd = null, pingTimer = null;
  function playError(f, e) { playErr = { msg: 'Soubor nejde přehrát – ' + f.name + ': ' + ((e && e.message) || e), at: Date.now() }; }
  function currentError() {
    if (state.ok === false && state.error) return { msg: state.error, at: state.errorAt || Date.now() };
    if (state.failed && state.failMsg) return { msg: state.failMsg, at: state.failAt || Date.now() };
    if (playErr && Date.now() - playErr.at < 10 * 60000) return playErr;
    return null;
  }
  function statusReport() {
    var e = currentError();
    return {
      version: VERSION, files: state.total || 0, done: state.done || 0,
      playing: cur ? { name: cur.file.name, kind: cur.file.kind, age: Math.round((Date.now() - cur.at) / 1000), paused: paused } : null,
      error: e ? { msg: e.msg, age: Math.round((Date.now() - e.at) / 1000) } : null
    };
  }
  function pingOnce() {
    if (!cfg.key || !cfg.base) return Promise.resolve(null);
    return fetch(cfg.base + '/now.php?ping=1', { method: 'POST', cache: 'no-store', body: JSON.stringify(statusReport()),
      headers: { 'X-Device-Key': cfg.key, 'X-Client': CLIENT, 'Content-Type': 'application/json' } })
      .then(function (r) { if (r.status === 403) { unpair('Televize už není spárovaná (zařízení bylo v aplikaci odebráno nebo má nový klíč).'); return null; } return r.ok ? r.json() : null; })
      .then(function (r) {
        if (!r) return null;
        if (r.cmd && r.cmd.id !== doneCmd) {
          doneCmd = r.cmd.id;
          if (r.cmd.name === 'refresh') refreshNow();
          else if (r.cmd.name === 'next') { if (!$('pair').hidden) return r; next(); }
          else if (r.cmd.name === 'restart') setTimeout(function () { location.reload(); }, 300);
        } else if (r.change && state.ok && r.change > (state.change || 0) && Date.now() - lastAuto > 30000) {
          // obsah složky přehrávače se změnil → obnovit seznam hned, ne až za 5 minut
          lastAuto = Date.now(); refreshNow();
        }
        return r;
      })
      .catch(function () { return null; });
  }
  function schedulePing(sec) {
    clearTimeout(pingTimer);
    pingTimer = setTimeout(function () { pingOnce().then(function (r) { schedulePing((r && r.ping_seconds) || 15); }); }, sec * 1000);
  }

  // ---------- start ----------
  var nextAt = null, refreshTimer = null;
  function timedRefresh() { nextAt = Date.now() + REFRESH_MS; if (cfg.key) sync(); }
  tvSetup();
  // pro kontrolu na dálku a testy
  window.twbTv = { key: remoteKey, kind: KIND, state: function () { return state; }, cfg: function () { return cfg; }, sync: sync, streamOnly: function () { return streamOnly; },
    current: function () { return cur ? { name: cur.file.name, kind: cur.file.kind, url: cur.url, paused: paused } : null; },
    ping: pingOnce, report: statusReport };
  schedulePing(2);
  if (!cfg.key) showPair();
  else { $('pair').hidden = true; renderErrIcon(); next(); refreshNow(); }
})();
