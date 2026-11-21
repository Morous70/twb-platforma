// Odhlášení po nečinnosti: sleduje aktivitu v prohlížeči, drží relaci na serveru při dlouhé práci
// (např. psaní v editoru slidu) a před koncem ukáže upozornění s tlačítkem „Zůstat přihlášen“.
// Skutečný čas odhlášení hlídá server; tady se jen zobrazuje upozornění. Načítá se PŘED app.js, api() používá až při volání.
(() => {
  let limit = (+(document.querySelector('meta[name=idle]')?.content) || 0) * 1000;   // ms, 0 = vypnuto
  let lastAct = Date.now();      // poslední akce uživatele na této stránce
  let lastServer = Date.now();   // poslední požadavek, který server počítá jako aktivitu
  let warn = null;               // otevřené upozornění
  let checking = false;
  const warnMs = () => (limit >= 300000 ? 120000 : Math.floor(limit / 3));   // 2 minuty předem (v testech s krátkým limitem třetina)
  const fmt = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

  async function ping() {
    try { await api('session'); } catch { /* při 401 se stránka sama načte znovu */ }
  }

  function onActivity() {
    if (warn || !limit) return;
    lastAct = Date.now();
    if (Date.now() - lastServer > Math.min(60000, limit / 4)) ping();   // i dlouhé psaní bez požadavků drží relaci
  }

  function hideWarn() {
    if (!warn) return;
    clearInterval(warn.timer);
    warn.el.remove();
    warn = null;
  }

  function showWarn(remainingSec) {
    const el = document.createElement('div');
    el.className = 'idle-warn';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML = `<div class="panel stack"><h2 style="margin:0">Brzy budete odhlášeni</h2>
      <p style="margin:0">Za <b id="idleCnt">${fmt(remainingSec)}</b> budete odhlášeni z důvodu nečinnosti. Neuložené změny se ztratí.</p>
      <div class="row"><span class="grow"></span><button class="btn primary" id="idleStay">Zůstat přihlášen</button></div></div>`;
    document.body.appendChild(el);
    const deadline = Date.now() + remainingSec * 1000;
    warn = { el, timer: setInterval(() => {
      const left = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      const c = el.querySelector('#idleCnt');
      if (c) c.textContent = fmt(left);
      if (left === 0) { clearInterval(warn.timer); setTimeout(() => location.reload(), 1500); }   // server mezitím relaci ukončí
    }, 1000) };
    el.querySelector('#idleStay').onclick = async () => { hideWarn(); lastAct = Date.now(); await ping(); };
    el.querySelector('#idleStay').focus();
  }

  async function check() {
    if (!limit || warn || checking) return;
    if (Date.now() - Math.max(lastAct, lastServer) < limit - warnMs()) return;
    checking = true;
    try {
      const s = await api('session', undefined, '', true);   // pasivní: čas neobnovuje
      if (s.remaining == null) { limit = 0; return; }
      limit = s.limit * 1000;
      if (s.remaining * 1000 > warnMs()) lastServer = Date.now() - (limit - s.remaining * 1000);   // v jiné záložce se pracovalo
      else showWarn(s.remaining);
    } catch { /* výpadek spojení: zkusíme při dalším kroku */ } finally { checking = false; }
  }

  ['pointerdown', 'keydown', 'wheel', 'touchstart', 'input'].forEach((ev) => addEventListener(ev, onActivity, { passive: true, capture: true }));
  setInterval(check, 1000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) check(); });

  window.idle = {
    touchServer() { lastServer = Date.now(); },
    setLimit(sec) { limit = (+sec || 0) * 1000; lastAct = lastServer = Date.now(); },
  };
})();
