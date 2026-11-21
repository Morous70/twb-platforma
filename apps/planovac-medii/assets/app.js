// ================= Pomocné funkce =================
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const main = $('#main');
const CSRF = $('meta[name=csrf]').content;

/** passive = automatické obnovování na pozadí: server ho nepočítá jako aktivitu (kvůli odhlášení po nečinnosti). */
async function api(action, body, extra = '', passive = false) {
  const opts = body === undefined ? {} : {
    method: 'POST',
    headers: body instanceof FormData ? { 'X-CSRF': CSRF } : { 'X-CSRF': CSRF, 'Content-Type': 'application/json' },
    body: body instanceof FormData ? body : JSON.stringify(body),
  };
  if (passive) opts.headers = { ...(opts.headers || {}), 'X-Passive': '1' };
  else idle.touchServer();
  const ctrl = new AbortController();
  const tmo = setTimeout(() => ctrl.abort(), 90000);
  let res, text;
  try {
    res = await fetch(`api.php?a=${action}${extra}`, { ...opts, signal: ctrl.signal });
    text = await res.text();
  } catch (e) {
    throw new Error(e.name === 'AbortError' ? 'Server neodpověděl do 90 s. Zkuste to znovu.' : 'Nelze se spojit se serverem – zkontrolujte připojení k internetu.');
  } finally { clearTimeout(tmo); }
  if (res.status === 401) { location.reload(); throw new Error('Byli jste odhlášeni.'); }
  let data;
  try { data = JSON.parse(text); } catch {
    // server vrátil HTML (např. chybovou stránku PHP nebo hostingu) – ukážeme jeho začátek
    const snippet = text.replace(/<style[\s\S]*?<\/style>|<script[\s\S]*?<\/script>/gi, '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
    throw new Error(`Server vrátil neočekávanou odpověď (HTTP ${res.status})${snippet ? ': ' + snippet : '.'}`);
  }
  if (!res.ok || data.error) throw new Error(data.error || `Chyba serveru (HTTP ${res.status}).`);
  return data;
}

// Tlačítko ve stavu „pracuji“: zablokuje ho, ukáže text s točítkem a po dokončení vrátí zpět.
async function busy(btn, label, fn) {
  if (!btn || btn.disabled) return;
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.classList.add('busy');
  btn.innerHTML = `<span class="spin"></span>${label}`;
  try { return await fn(); } finally {
    if (btn.isConnected) { btn.disabled = false; btn.classList.remove('busy'); btn.innerHTML = orig; }
  }
}

document.addEventListener('dragstart', () => document.body.classList.add('dragging'));
document.addEventListener('dragend', () => document.body.classList.remove('dragging'));
window.addEventListener('error', (e) => {
  if (/ResizeObserver loop/i.test(e.message || '')) return; // neškodné upozornění prohlížeče, ne chyba
  toast('Chyba v aplikaci: ' + e.message, 'error');
});
window.addEventListener('unhandledrejection', (e) => toast(e.reason?.message || String(e.reason), 'error'));

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = `<span class="ti">${type === 'ok' ? '✓' : '!'}</span><span>${esc(msg)}</span>`;
  el.title = 'Kliknutím zavřete';
  el.onclick = () => el.remove();
  $('#toasts').append(el);
  setTimeout(() => el.remove(), type === 'error' ? 15000 : 4000);
}

const fmtSize = (b) => b < 1048576 ? Math.max(1, Math.round(b / 1024)) + ' kB' : (b / 1048576).toFixed(1) + ' MB';
const fmtDT = (iso) => iso ? new Date(iso).toLocaleString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtTime = (s) => s ? fmtDT(String(s).replace(' ', 'T')) : '—';
const fmtDate = (d) => d ? new Date(d + 'T12:00').toLocaleDateString('cs-CZ', { day: 'numeric', month: 'numeric', year: 'numeric' }) : '';
const DAYS = ['Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
const PRIO = { 1: 'nízká', 2: 'normální', 3: 'vysoká' };
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

function hoursText(mask) {
  if (mask === '1'.repeat(24)) return 'celý den';
  const out = [];
  for (let h = 0; h < 24; h++) {
    if (mask[h] !== '1') continue;
    let e = h;
    while (e + 1 < 24 && mask[e + 1] === '1') e++;
    out.push(`${h}–${e + 1} h`);
    h = e;
  }
  return out.join(', ') || 'žádné hodiny';
}
function daysText(wd) {
  if (wd === '1234567') return 'každý den';
  if (wd === '12345') return 'Po–Pá';
  if (wd === '67') return 'víkend';
  return wd.split('').map((d) => DAYS[d - 1]).join(', ');
}
function rangeText(s) {
  if (s.date_from && s.date_to) return s.date_from === s.date_to ? fmtDate(s.date_from) : `${fmtDate(s.date_from)} – ${fmtDate(s.date_to)}`;
  if (s.date_from) return `od ${fmtDate(s.date_from)}`;
  if (s.date_to) return `do ${fmtDate(s.date_to)}`;
  return '';
}

const ZOOM_ICON = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6"/><path d="m15 15 5 5M10.5 8v5M8 10.5h5"/></svg>';
const KIND = { image: 'Obrázek', video: 'Video', pdf: 'PDF', text: 'Text', url: 'Odkaz', random: 'Náhodný výběr', dated: 'Výběr podle data', slide: 'Slide', html: 'HTML' };
const todayIso = () => isoDate(new Date());
/**
 * HTML dokument v izolovaném rámu (sandbox bez přístupu k aplikaci) v ploše 1920×1080 zmenšené na šířku prvku.
 * opts.src = adresa (file.php…), nebo opts.html = hotový dokument.
 */
function htmlFrame(box, opts) {
  box.classList.add('html-frame');
  box.innerHTML = '';
  const f = document.createElement('iframe');
  f.setAttribute('sandbox', 'allow-scripts');
  f.setAttribute('title', 'Náhled HTML dokumentu');
  f.width = 1920; f.height = 1080;
  if (opts.html != null) f.srcdoc = opts.html; else f.src = opts.src;
  box.append(f);
  const fit = () => { const k = box.clientWidth / 1920; f.style.transform = `scale(${k})`; box.style.height = `${Math.round(1080 * k)}px`; };
  fit();
  const ro = new ResizeObserver(fit); ro.observe(box);
  return f;
}
/** Výběr podle data: dny seřazené, položky na den, nejbližší budoucí den. */
function datedInfo(m) {
  const byDay = {};
  (m.items || []).forEach((it) => it.days.forEach((d) => { (byDay[d] ||= []).push(it.id); }));
  const days = Object.keys(byDay).sort();
  const t = todayIso();
  return { byDay, days, today: byDay[t] || [], next: days.find((d) => d > t) || null, last: days[days.length - 1] || null };
}
const isTouch = matchMedia('(pointer: coarse)').matches;
const hostOf = (u) => { try { return new URL(u).hostname.replace(/^www\./, ''); } catch { return u || ''; } };

function thumb(m, zoom = false) {
  if (!m) return '<div class="tb"><div class="ph">?</div></div>';
  let inner;
  switch (m.kind) {
    case 'video': inner = `<video src="${m.url}#t=0.5" preload="metadata" muted playsinline></video>`; break;
    case 'pdf': inner = `<canvas class="pdfthumb" data-src="${m.url}"></canvas><span class="ph-ico">PDF</span>`; break;
    case 'text': inner = `<div class="txtthumb">${esc((m.content || '').slice(0, 140))}</div>`; break;
    case 'url': inner = `<div class="urlthumb"><span>🌐</span><b>${esc(hostOf(m.content))}</b></div>`; break;
    case 'html': inner = `<div class="urlthumb htmlthumb"><span>&lt;/&gt;</span><b>${esc(m.original_name.replace(/\.html?$/i, ''))}</b></div>`; break;
    case 'random': {
      const mem = (m.members || []).map(mediaById).filter(Boolean);
      inner = `<div class="poolthumb n${Math.min(mem.length, 4)}">${mem.slice(0, 4).map((x) => thumb(x)).join('')}</div><span class="dice">🎲 ${mem.length}</span>`;
      break;
    }
    case 'dated': {
      // náhled: dnešní položky, jinak nejbližší budoucí, jinak první
      const di = datedInfo(m);
      const showIds = di.today.length ? di.today : di.next ? di.byDay[di.next] : (m.members || []);
      const mem = showIds.map(mediaById).filter(Boolean);
      inner = `<div class="poolthumb n${Math.min(mem.length, 4)}">${mem.slice(0, 4).map((x) => thumb(x)).join('')}</div><span class="dice">📅 ${di.today.length ? 'dnes' : di.next ? fmtDate(di.next).replace(/ \d{4}$/, '') : '–'}</span>`;
      break;
    }
    default: inner = `<img src="${m.url}" loading="lazy" alt="">`;
  }
  return `<div class="tb k-${m.kind}"${zoom ? ` data-zoom="${m.id}" title="Zvětšit"` : ''}>${inner}</div>`;
}
function mediaInfo(m) {
  if (m.kind === 'text') return `${(m.content || '').length} znaků`;
  if (m.kind === 'url') return esc(hostOf(m.content));
  if (m.kind === 'random') return `${(m.members || []).length} médií`;
  if (m.kind === 'dated') {
    const di = datedInfo(m);
    return `${(m.members || []).length} médií · ${di.days.length} dní${di.today.length ? ' · <b>dnes</b>' : di.next ? ` · další ${fmtDate(di.next)}` : ' · <span style="color:var(--warn)">žádné další datum</span>'}`;
  }
  return fmtSize(m.size) + (m.compressed ? ` · <span title="Komprimováno z ${fmtSize(m.compressed.orig_size)}, ${m.compressed.orig_w}×${m.compressed.orig_h} px">🗜 z ${fmtSize(m.compressed.orig_size)}</span>` : '');
}

/** Nahraje jeden soubor po částech (obejde limit velikosti uploadu). extra = další pole (tags, replace_id). */
async function uploadOne(f, extra = {}, onProgress = () => {}, action = 'upload_chunk') {
  const CHUNK = 1.5 * 1024 * 1024;
  const uid = crypto.getRandomValues(new Uint8Array(12)).reduce((s, b) => s + b.toString(16).padStart(2, '0'), '');
  const parts = Math.max(1, Math.ceil(f.size / CHUNK));
  let r = null;
  for (let i = 0; i < parts; i++) {
    const fd = new FormData();
    fd.append('upload_id', uid); fd.append('index', i); fd.append('total', parts); fd.append('name', f.name);
    Object.entries(extra).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') fd.append(k, v); });
    fd.append('chunk', f.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk');
    r = await api(action, fd);
    onProgress(Math.min(CHUNK, f.size - i * CHUNK));
  }
  return r;
}

// Náhled první stránky PDF (pdf.js se načte až při potřebě)
let pdfjsP = null;
const pdfCache = new Map();
function loadPdfJs() {
  return pdfjsP ??= new Promise((res, rej) => {
    const sc = document.createElement('script');
    sc.src = 'assets/vendor/pdf.min.js';
    sc.onload = () => { pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/vendor/pdf.worker.min.js'; res(pdfjsLib); };
    sc.onerror = () => { pdfjsP = null; rej(new Error('pdf.js')); };
    document.head.append(sc);
  });
}
function pdfImage(src) {
  if (!pdfCache.has(src)) pdfCache.set(src, (async () => {
    const lib = await loadPdfJs();
    const page = await (await lib.getDocument(src).promise).getPage(1);
    const vp = page.getViewport({ scale: 360 / page.getViewport({ scale: 1 }).width });
    const c = document.createElement('canvas');
    c.width = vp.width; c.height = vp.height;
    await page.render({ canvasContext: c.getContext('2d'), viewport: vp }).promise;
    return c.toDataURL('image/jpeg', 0.8);
  })().catch(() => null));
  return pdfCache.get(src);
}
new MutationObserver(() => $$('canvas.pdfthumb:not([data-done])').forEach(async (cv) => {
  cv.dataset.done = '1';
  const url = await pdfImage(cv.dataset.src);
  if (url && cv.isConnected) { const img = new Image(); img.src = url; img.alt = ''; cv.replaceWith(img); }
})).observe(document.body, { childList: true, subtree: true });

function openModal(html) {
  $('#modalBox').classList.remove('wide', 'full'); $('#modalBox').innerHTML = html; $('#modal').hidden = false;
  const mt = $('#mTags'); // společná pole médií: pole pro tagy
  if (mt) mt._tf = tagField(mt, JSON.parse(mt.dataset.tags || '[]'));
}
function closeModal() { $('#modal').hidden = true; $('#modalBox').innerHTML = ''; $('#modalBox').classList.remove('wide', 'full'); }
$('#modal').addEventListener('click', (e) => { if (e.target.id === 'modal' || e.target.closest('[data-close]')) closeModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

/** Obsah zvětšeného náhledu jednoho média. */
function previewBody(m) {
  let body;
  if (m.kind === 'video') body = `<video src="${m.url}" controls autoplay></video>`;
  else if (m.kind === 'pdf') body = `<iframe src="${m.url}" class="pdfview" title="PDF"></iframe>`;
  else if (m.kind === 'text') body = `<p class="muted small">Přehrávač zobrazí text jako běžící řádek:</p><div class="marquee"><span>${esc(m.content)}</span></div>`;
  else if (m.kind === 'html') body = `<p class="muted small">Takto dokument uvidí přehrávač (plocha 1920 × 1080, hodnoty z Dat dosazené dnes):</p><div class="html-frame" data-src="${m.url}"></div>`;
  else if (m.kind === 'url') body = `<p class="muted small">Přehrávač zobrazí webovou stránku:</p><p><a href="${esc(m.content)}" target="_blank" rel="noopener">${esc(m.content)}</a></p>`;
  else if (m.kind === 'dated') {
    const di = datedInfo(m), t = todayIso();
    body = `<p class="muted small">Položka se zobrazí jen ve své dny (celý den, v čase podle scénáře). V ostatní dny výběr nedodá nic.</p>
      <div class="dated-days">${di.days.map((d) => `<div class="dd-row ${d === t ? 'today' : d < t ? 'past' : ''}"><div class="dd-date">${fmtDate(d)}${d === t ? ' · dnes' : ''}</div>
        <div class="dd-items">${di.byDay[d].map((id) => { const x = mediaById(id); return `<div class="card now-item"><div class="thumb">${thumb(x)}</div><div class="meta"><span class="name">${esc(x?.original_name)}</span></div></div>`; }).join('')}</div></div>`).join('')
        || '<div class="empty small">Žádné položky.</div>'}</div>`;
  }
  else if (m.kind === 'random') body = `<p class="muted small">Každou hodinu jde na obrazovku jedno z těchto médií. Postupně se vystřídají všechna, než se některé zopakuje.</p>
    <div class="now-grid">${m.members.map((id) => { const x = mediaById(id); return `<div class="card now-item ${id === m.current ? 'cur' : ''}">
      <div class="thumb">${thumb(x)}</div><div class="meta"><span class="name">${esc(x?.original_name)}</span>${id === m.current ? '<span class="why">poslední vylosované</span>' : ''}</div></div>`; }).join('')}</div>`;
  else body = `<img src="${m.url}" alt="">`;
  return body;
}
/**
 * Zvětšený náhled přes celou šířku. list = média, mezi kterými jde listovat (šipky ← →, klávesy, tažení prstem).
 */
function preview(m, list = null) {
  const items = (list && list.length ? list : [m]).filter(Boolean);
  let i = Math.max(0, items.findIndex((x) => x.id === m.id));
  const show = () => {
    const x = items[i];
    openModal(`<div class="row pv-head" style="margin-bottom:12px"><span class="badge b-paused">${KIND[x.kind]}</span><b class="grow pv-name">${esc(x.original_name)}</b>
      <span class="muted small">${mediaInfo(x)}</span>
      ${items.length > 1 ? `<span class="pv-nav" id="pvNav"><button class="btn sm" id="pvPrev" title="Předchozí (←)" aria-label="Předchozí">←</button><span class="small" id="pvPos">${i + 1} / ${items.length}</span><button class="btn sm" id="pvNext" title="Další (→)" aria-label="Další">→</button></span>` : ''}
      <button class="btn sm" data-close>Zavřít</button></div><div class="pv-body" id="pvBody">${previewBody(x)}</div>`);
    $('#modalBox').classList.add('full');
    $$('.html-frame[data-src]').forEach((el) => htmlFrame(el, { src: el.dataset.src }));
    $('#pvPrev')?.addEventListener('click', () => go(-1));
    $('#pvNext')?.addEventListener('click', () => go(1));
    // tažení prstem do strany = další / předchozí
    let sx = null, sy = null;
    const pb = $('#pvBody');
    pb.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
    pb.addEventListener('touchend', (e) => {
      if (sx === null || items.length < 2) return;
      const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
      sx = null;
      if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) go(dx < 0 ? 1 : -1);
    }, { passive: true });
  };
  const go = (d) => { i = (i + d + items.length) % items.length; show(); };
  previewKeys = (e) => { if (e.key === 'ArrowRight') go(1); else if (e.key === 'ArrowLeft') go(-1); };
  show();
}
let previewKeys = null;
document.addEventListener('keydown', (e) => {
  if (!previewKeys || $('#modal').hidden || !$('#pvBody') || e.target.matches('input, textarea, select')) return;
  if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') { e.preventDefault(); previewKeys(e); }
});
// Zvětšení kliknutím na miniaturu [data-zoom]; listuje se v rámci nejbližší skupiny [data-zoom-group]
// (seznam čísel v data-zoom-ids, jinak všechny miniatury ve skupině). Zachytává se dřív než klik na kartu (výběr).
document.addEventListener('click', (e) => {
  const z = e.target.closest('[data-zoom]');
  if (!z || z.closest('#modal')) return;
  const m = mediaById(+z.dataset.zoom);
  if (!m) return;
  e.stopPropagation(); e.preventDefault();
  const g = z.closest('[data-zoom-group]');
  const ids = g?.dataset.zoomIds ? g.dataset.zoomIds.split(',').map(Number) : [...(g || z.parentElement).querySelectorAll('[data-zoom]')].map((x) => +x.dataset.zoom);
  preview(m, [...new Set(ids)].map(mediaById).filter(Boolean));
}, true);

// ================= Stav a router =================
const store = { media: [], archive: [], scenarios: [], status: null };
const mediaById = (id) => store.media.find((m) => m.id === id) || store.archive.find((m) => m.id === id);
async function loadAll() {
  let all;
  [all, store.scenarios, store.status] = await Promise.all([api('media'), api('scenarios'), api('status')]);
  // média v archivu se nikde nenabízejí (store.media), ale dál se najdou podle čísla (mediaById) – ve scénářích dál hrají
  store.media = all.filter((m) => !m.archived);
  store.archive = all.filter((m) => m.archived);
  checkDevAlert();
  const d = store.status.drive;
  const lbl = d.mode === 'mock' ? 'Testovací režim' : d.connected ? 'Google Disk připojen' : 'Google Disk nepřipojen';
  $('#drivePill').innerHTML = `<span class="dot ${d.connected && store.status.folder.id ? 'on' : ''}"></span>${lbl}
    ${store.status.folder.name ? `<div style="margin-top:4px">Složka: <b>${esc(store.status.folder.name)}</b></div>` : ''}`;
}

const BASE_TITLE = document.title;
let timer = null;
let routeSeq = 0;
async function route() {
  const dirtyMsg = window.slideEditorDirty?.();
  if (dirtyMsg && !confirm(typeof dirtyMsg === 'string' ? dirtyMsg : 'Slide má neuložené změny. Opravdu odejít?')) return;
  window.slideEditorDirty = null;
  clearInterval(timer);
  const view = location.hash.slice(1).split(/[?/]/)[0] || 'prehled';
  $$('.sidebar nav a').forEach((a) => a.classList.toggle('active', a.dataset.view === view));
  const navA = $(`.sidebar nav a[data-view="${view}"]`) || $('.sidebar nav a');
  $('#tbTitle').textContent = navA.textContent;
  menu.close();
  if (view !== 'napoveda') document.title = BASE_TITLE;
  const seq = ++routeSeq;
  try { if (view !== 'napoveda' || !store.status) await loadAll(); } catch (e) { return toast(e.message, 'error'); }
  if (seq !== routeSeq) return;   // mezitím se přešlo jinam – nevykreslovat starou stránku
  ({ prehled: viewOverview, scenare: viewScenarios, galerie: viewGallery, data: viewData, protokol: viewLogs, nastaveni: viewSettings, napoveda: window.viewHelp }[view] || viewOverview)();
}
// změna adresy (i tlačítko Zpět na telefonu) zavře otevřené okno; route() volaný po akcích okna nezavírá
window.addEventListener('hashchange', () => { if (!$('#modal').hidden) closeModal(); route(); });

// ---------- Menu na telefonu (horní pruh ☰) ----------
const menu = {
  open() { document.body.classList.add('menu-open'); $('#menuBtn').setAttribute('aria-expanded', 'true'); $('#menuClose').focus(); },
  close() {
    if (!document.body.classList.contains('menu-open')) return;
    document.body.classList.remove('menu-open'); $('#menuBtn').setAttribute('aria-expanded', 'false');
  },
};
$('#menuBtn').onclick = () => menu.open();
$('#menuClose').onclick = () => menu.close();
$('#sideBackdrop').onclick = () => menu.close();
$('.sidebar nav').addEventListener('click', () => menu.close());
addEventListener('keydown', (e) => { if (e.key === 'Escape') menu.close(); });

// ---------- Vzhled: automaticky / světlý / tmavý (pamatuje si každý prohlížeč zvlášť) ----------
function initTheme() {
  let cur = 'auto';
  try { cur = localStorage.getItem('mp-theme') || 'auto'; } catch { /* soukromý režim */ }
  const apply = (v) => {
    if (v === 'auto') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', v);
    try { v === 'auto' ? localStorage.removeItem('mp-theme') : localStorage.setItem('mp-theme', v); } catch { /* nevadí */ }
    $$('#themeSw [data-theme-v]').forEach((b) => b.classList.toggle('on', b.dataset.themeV === v));
  };
  $$('#themeSw [data-theme-v]').forEach((b) => { b.onclick = () => apply(b.dataset.themeV); });
  apply(['light', 'dark'].includes(cur) ? cur : 'auto');
}
initTheme();

// ---------- Instalace jako aplikace (PWA) ----------
const pwa = {
  prompt: null,
  standalone: () => matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
  ios: () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),
  async install() {
    if (!pwa.prompt) return false;
    pwa.prompt.prompt();
    const { outcome } = await pwa.prompt.userChoice;
    pwa.prompt = null;
    pwa.update();
    return outcome === 'accepted';
  },
  update() {
    const b = $('#installBtn');
    if (b) b.hidden = !pwa.prompt || pwa.standalone();
    if (location.hash.startsWith('#nastaveni/system') && $('#pwaBox')) drawPwaBox();
  },
};
addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); pwa.prompt = e; pwa.update(); });
addEventListener('appinstalled', () => { pwa.prompt = null; pwa.update(); toast('Aplikace nainstalována – najdete ji na ploše a v nabídce aplikací.'); });
$('#installBtn')?.addEventListener('click', () => pwa.install());

/** Karta Systém: stav instalace a návod podle zařízení. */
function drawPwaBox() {
  const box = $('#pwaBox');
  if (!box) return;
  let html;
  if (pwa.standalone()) html = '<p class="small" style="margin:0"><span class="dot on"></span>Aplikace běží jako nainstalovaná aplikace.</p>';
  else if (pwa.prompt) html = '<p class="small" style="margin:0">Aplikaci můžete nainstalovat na plochu – otevře se ve vlastním okně bez lišty prohlížeče.</p><div><button class="btn primary" id="pwaInstall">⤓ Instalovat aplikaci</button></div>';
  else if (pwa.ios()) html = `<p class="small" style="margin:0">Na iPhonu a iPadu v Safari: klepněte na <b>Sdílet</b> (čtvereček se šipkou nahoru) → <b>Přidat na plochu</b> → <b>Přidat</b>.</p>`;
  else html = `<p class="small" style="margin:0">Pokud tlačítko pro instalaci chybí, aplikace už je nainstalovaná, nebo ji prohlížeč neumí instalovat:</p>
    <ul class="small" style="margin:6px 0 0;padding-left:20px;line-height:1.6">
      <li><b>Chrome / Edge na počítači:</b> ikona instalace vpravo v adresním řádku, nebo menu ⋮ → <b>Uložit a sdílet → Instalovat stránku jako aplikaci</b> (Edge: <b>Aplikace → Nainstalovat tento web jako aplikaci</b>).</li>
      <li><b>Chrome na Androidu:</b> menu ⋮ → <b>Přidat na plochu</b> / <b>Instalovat aplikaci</b>.</li>
      <li><b>Firefox na počítači</b> instalaci aplikací nepodporuje – použijte Chrome nebo Edge.</li></ul>`;
  box.innerHTML = html;
  $('#pwaInstall')?.addEventListener('click', () => pwa.install());
}

// ================= Přehled =================
const PALETTE = ['#d2502c', '#2d5fa8', '#2f7d4f', '#8e44ad', '#b7791f', '#16a3a3', '#c0392b', '#5d6d7e', '#e67e22', '#27ae60', '#7f8c8d', '#1abc9c'];
let weekStart = null;

function modeText(r) {
  if (r.mode === 'exclusive') return 'Výhradní scénář';
  if (r.mode === 'default') return 'Výchozí scénář';
  if (r.mode === 'empty') return (r.fixed || []).length ? 'Jen pevné' : 'Nic neběží';
  return 'Běží';
}
/** Názvy scénářů sestavy včetně pevných (pro krátké popisy). */
const resNames = (r) => [...r.active, ...(r.fixed || []).map((n) => `📌 ${n}`)];

function resolutionHtml(r, big = true) {
  const items = r.media.map((x) => {
    const m = mediaById(x.id);
    return `<div class="card now-item">
      <div class="thumb">${thumb(m, true)}${m?.grp ? `<span class="tag">${esc(m.grp)}</span>` : ''}</div>
      <div class="meta"><span class="name" title="${esc(m?.original_name)}">${esc(m?.original_name)}</span>
        <span class="why">${x.pool ? `${mediaById(x.pool)?.kind === 'dated' ? '📅' : '🎲'} ${esc(mediaById(x.pool)?.original_name)} · ` : ''}ze scénáře ${esc(x.scenario)}</span></div>
    </div>`;
  });
  (r.pending || []).forEach((x) => {
    const m = mediaById(x.pool);
    items.push(`<div class="card now-item"><div class="thumb">${thumb(m, true)}</div>
      <div class="meta"><span class="name">${esc(m?.original_name)}</span><span class="why">vylosuje se při další synchronizaci · ze scénáře ${esc(x.scenario)}</span></div></div>`);
  });
  const supp = r.suppressed.map((x) => {
    const m = mediaById(x.id);
    return `<div class="card now-item supp">
      <div class="thumb">${thumb(m, true)}<span class="tag">${esc(x.group)}</span></div>
      <div class="meta"><span class="name">${esc(m?.original_name)}</span><span class="why">vytlačeno scénářem ${esc(x.by)}</span></div>
    </div>`;
  });
  return `<p class="small" style="margin:0 0 12px"><span class="badge b-${r.mode === 'empty' ? 'paused' : r.mode === 'default' ? 'default' : r.mode === 'exclusive' ? 'exclusive' : 'active'}">${modeText(r)}</span>
      ${r.active.length ? ` <b>${r.active.map(esc).join(', ')}</b>` : ''}
      ${(r.fixed || []).length ? ` <span class="badge b-fixed">Pevný</span> <b>${r.fixed.map(esc).join(', ')}</b>` : ''}
      ${r.ignored.length ? `<span class="muted"> · potlačeno: ${r.ignored.map(esc).join(', ')}</span>` : ''}
      ${(r.idle || []).length ? `<span class="muted"> · teď bez obsahu: ${r.idle.map(esc).join(', ')}</span>` : ''}
      ${(r.fixed_off || []).length ? `<span class="muted"> · pevný vypnut: ${r.fixed_off.map(esc).join(', ')}</span>` : ''}</p>
    ${items.length || supp.length ? `<div class="now-grid" data-zoom-group>${items.join('')}${supp.join('')}</div>`
      : `<div class="empty small">Složka bude prázdná.${!store.status.has_default ? ' Nastavte výchozí scénář, aby obrazovka nikdy nebyla prázdná.' : ''}</div>`}`;
}

async function viewOverview() {
  const st = store.status;
  const lastRun = st.last_run_at ? new Date(st.last_run_at) : null;
  const stale = !lastRun || (Date.now() - lastRun) > 20 * 60000;
  const problems = [];
  if (!st.drive.connected) problems.push('Google Disk není připojen – <a href="#nastaveni">Nastavení → Google Disk</a>.');
  if (!st.folder.id) problems.push('Není nastavena cílová složka – <a href="#nastaveni">Nastavení → Google Disk</a>.');
  if (!st.has_default) problems.push(store.scenarios.some((x) => x.is_fixed && !x.paused)
    ? 'Chybí výchozí scénář – když nic neběží, zobrazí se jen pevné scénáře.'
    : 'Chybí výchozí scénář – když nic neběží, složka zůstane prázdná.');
  store.media.filter((m) => m.kind === 'dated' && m.used).forEach((m) => {
    const di = datedInfo(m);
    if (!di.today.length && !di.next) problems.push(`Výběr podle data „${esc(m.original_name)}“ nemá žádné další datum${di.last ? ` (poslední ${fmtDate(di.last)})` : ''} – <a href="#galerie">Galerie</a>.`);
  });
  if (st.player_poll_at && Date.now() - new Date(st.player_poll_at) > 15 * 60000)
    problems.push(`Synology se neozval od ${fmtDT(st.player_poll_at)} – přehrávač si změny stáhne až při své pravidelné synchronizaci. <a href="#nastaveni/prehravac">Nastavení → Přehrávač</a>`);
  if (stale) problems.push(lastRun ? `Cron naposledy běžel ${fmtDT(st.last_run_at)} – zkontrolujte, že se spouští.` : 'Cron zatím neběžel – nastavte ho podle <a href="#nastaveni/system">Nastavení → Systém</a>.');

  main.innerHTML = `
    <div class="head">
      <div><h1>Přehled ${helpBtn('@prehled')}</h1><p class="sub">Co je teď na obrazovce a co poběží v následujících dnech.</p></div>
      <button class="btn primary" id="syncNow">Synchronizovat nyní</button>
    </div>
    <div class="stack">
      ${st.last_error ? `<div class="errbox">Poslední synchronizace selhala: ${esc(st.last_error)}</div>` : ''}
      ${problems.map((p) => `<div class="warnbox">${p}</div>`).join('')}
      ${st.update_available ? `<div class="infobox" id="updNote">⬆ Je k dispozici nová verze aplikace <b>${esc(st.update_available)}</b> – <a href="#nastaveni/system">Nastavení → Systém → Aktualizace</a>.</div>` : ''}
      <div class="panel">
        <div class="row" style="margin-bottom:6px"><h2 class="grow" style="margin:0">Právě teď</h2>
          <span class="muted small">Poslední kontrola: ${fmtDT(st.last_run_at)}</span></div>
        ${resolutionHtml(st.current)}
        ${st.next ? `<p class="small muted" style="margin:14px 0 0">Další změna: <b style="color:var(--ink)">${fmtDT(st.next.at)}</b> →
          ${modeText(st.next.resolution)}${resNames(st.next.resolution).length ? ': ' + resNames(st.next.resolution).map(esc).join(', ') : ''}
          (${st.next.resolution.media.map((x) => esc(mediaById(x.id)?.original_name)).join(', ') || 'prázdná složka'})</p>`
          : '<p class="small muted" style="margin:14px 0 0">V příštích 14 dnech se nic nemění.</p>'}
      </div>
      <div class="panel" id="weekPanel"></div>
    </div>`;
  $('#syncNow').onclick = (e) => busy(e.currentTarget, 'Synchronizuji…', async () => {
    try {
      const r = await api('sync', {});
      toast(r.status === 'ok' ? `${r.message} Nahráno ${r.uploaded.length}, odstraněno ${r.removed.length}.` : r.message, r.status === 'error' ? 'error' : 'ok');
    } catch (err) { toast(err.message, 'error'); }
    route();
  });
  if (!weekStart) {
    const d = new Date(); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); weekStart = d;
  }
  drawWeek();
  timer = setInterval(async () => {
    const fresh = await api('status', undefined, '', true).catch(() => null);
    if (fresh && JSON.stringify(fresh.current) !== JSON.stringify(store.status.current)) route();
  }, 60000);
}

async function drawWeek() {
  const p = $('#weekPanel');
  if (!p) return;
  const data = await api('preview', undefined, `&from=${isoDate(weekStart)}`);
  if (!p.isConnected) return; // mezitím se přešlo jinam
  const end = new Date(weekStart); end.setDate(end.getDate() + 6);
  const now = new Date();
  const color = (i) => data.sets[i].mode === 'empty' && !data.sets[i].media.length ? 'repeating-linear-gradient(45deg,var(--line) 0 4px,transparent 4px 8px)' : PALETTE[i % PALETTE.length];
  let sel = null;
  const cells = data.days.map((d, di) => {
    const dt = new Date(d.date + 'T12:00');
    const today = d.date === isoDate(now);
    return `<div class="dl ${today ? 'today' : ''}">${DAYS[di]} ${dt.getDate()}. ${dt.getMonth() + 1}.</div>` +
      d.hours.map((si, h) => `<div class="cell ${today && h === now.getHours() ? 'now' : ''}" style="background:${color(si)}" data-d="${di}" data-h="${h}" data-s="${si}"
        title="${DAYS[di]} ${h}:00–${h + 1}:00"></div>`).join('');
  }).join('');
  p.innerHTML = `
    <div class="row" style="margin-bottom:14px">
      <h2 class="grow" style="margin:0">Týdenní náhled</h2>
      <button class="btn sm" id="wPrev">‹</button>
      <span class="small" style="min-width:150px;text-align:center">${weekStart.toLocaleDateString('cs-CZ')} – ${end.toLocaleDateString('cs-CZ')}</span>
      <button class="btn sm" id="wNext">›</button>
      <button class="btn sm ghost" id="wToday">Tento týden</button>
    </div>
    <div class="week"><div></div>${Array.from({ length: 24 }, (_, h) => `<div class="hd">${h}</div>`).join('')}${cells}</div>
    <div class="legend">${data.sets.map((s, i) => `<div class="li" data-s="${i}"><span class="sw" style="background:${color(i)}"></span>
      <span><b>${modeText(s)}</b>${resNames(s).length ? ': ' + resNames(s).map(esc).join(', ') : ''}
      <span class="muted"> → ${s.media.map((x) => esc(mediaById(x.id)?.original_name)).join(', ') || 'prázdná složka'}</span></span></div>`).join('')}</div>
    <div id="cellDetail" style="margin-top:16px"></div>`;
  const shift = (n) => { weekStart = new Date(weekStart); weekStart.setDate(weekStart.getDate() + n); drawWeek(); };
  $('#wPrev').onclick = () => shift(-7);
  $('#wNext').onclick = () => shift(7);
  $('#wToday').onclick = () => { weekStart = null; const d = new Date(); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); weekStart = d; drawWeek(); };
  const showSet = (si, label) => {
    $$('.week .cell', p).forEach((c) => c.classList.toggle('sel', +c.dataset.s === si && label !== undefined && c === sel));
    $$('.legend .li', p).forEach((l) => l.classList.toggle('sel', +l.dataset.s === si));
    $('#cellDetail').innerHTML = `<h2>${label || 'Sestava'}</h2>${resolutionHtml(data.sets[si])}`;
  };
  $$('.week .cell', p).forEach((c) => c.onclick = () => {
    sel = c;
    const d = data.days[+c.dataset.d];
    const dt = new Date(d.date + 'T12:00');
    showSet(+c.dataset.s, `${DAYS[+c.dataset.d]} ${dt.getDate()}. ${dt.getMonth() + 1}., ${c.dataset.h}:00–${+c.dataset.h + 1}:00`);
  });
  $$('.legend .li', p).forEach((l) => l.onclick = () => { sel = null; showSet(+l.dataset.s); });
}

// ================= Scénáře =================
function scenBadges(s) {
  const cur = store.status.current;
  const active = (cur.active.includes(s.name) || (cur.fixed || []).includes(s.name)) && !s.paused;
  return [
    s.is_default ? '<span class="badge b-default">Výchozí</span>' : '',
    s.is_fixed ? '<span class="badge b-fixed">Pevný</span>' : '',
    s.embed_only ? '<span class="badge b-set">Sada</span>' : '',
    s.exclusive ? '<span class="badge b-exclusive">Výhradní</span>' : '',
    s.paused ? '<span class="badge b-paused">Pozastaveno</span>' : '',
    active ? '<span class="badge b-ok">Běží teď</span>' : '',
  ].join(' ');
}
const scenById = (id) => store.scenarios.find((x) => x.id === id);
const scenType = (s) => (s.is_default ? 1 : s.embed_only ? 2 : s.is_fixed ? 3 : 0);
/** Scénáře, ve kterých je pevný scénář vypnutý. */
const fixedOffIn = (f) => store.scenarios.filter((x) => (x.fixed_off || []).includes(f.id));
const usedIn = (s) => store.scenarios.filter((x) => x.includes.some((i) => i.id === s.id));
/** Vede z scénáře `from` cesta vložení do scénáře `target`? (ochrana proti kruhu) */
function reaches(from, target, seen = new Set()) {
  if (from === target) return true;
  if (seen.has(from)) return false;
  seen.add(from);
  return (scenById(from)?.includes || []).some((i) => reaches(i.id, target, seen));
}
function scenRules(s) {
  if (s.is_default) return 'Běží, když neběží žádný jiný scénář.';
  if (s.embed_only) return 'Sada – neběží samostatně, jen vložená do jiných scénářů.';
  if (s.is_fixed) {
    const off = fixedOffIn(s);
    return 'Zobrazuje se vždy ve všech scénářích' + (off.length ? ` kromě: ${off.map((x) => x.name).join(', ')}` : '') + '.';
  }
  const parts = [rangeText(s), daysText(s.weekdays), hoursText(s.hours), `priorita ${PRIO[s.priority]}`];
  if (s.exceptions.length) parts.push(`výjimky: ${s.exceptions.map(fmtDate).join(', ')}`);
  return parts.filter(Boolean).join(' · ');
}
function scenarioExpired(s) { return s.date_to && s.date_to < isoDate(new Date()); }

let flashScenario = null;
function viewScenarios() {
  const list = [...store.scenarios].sort((a, b) => (b.is_default - a.is_default) || (b.is_fixed - a.is_fixed) || (a.embed_only - b.embed_only) || (scenarioExpired(a) - scenarioExpired(b))
    || (b.priority - a.priority) || a.name.localeCompare(b.name, 'cs'));
  main.innerHTML = `
    <div class="head">
      <div><h1>Scénáře ${helpBtn('@scenare')}</h1><p class="sub">Aktivní scénáře se skládají dohromady. Ve skupině médií vyhrává scénář s vyšší prioritou, při shodě ten s omezenou (kratší) platností.</p></div>
      <button class="btn primary" id="newScen">+ Nový scénář</button>
    </div>
    <div class="scen-list">${list.length ? list.map((s) => `
      <div class="panel scen" data-scen="${s.id}" style="${scenarioExpired(s) ? 'opacity:.6' : ''}">
        <div>
          <div class="row"><h3>${esc(s.name)}</h3>${scenBadges(s)}${scenarioExpired(s) ? '<span class="badge b-paused">Skončil</span>' : ''}</div>
          <div class="facts"><span>${esc(scenRules(s))}</span><span>Médií: <b>${s.media_ids.length}</b></span>
            ${s.includes.length ? `<span>Vkládá: <b>${s.includes.map((i) => esc(scenById(i.id)?.name) + (i.follow ? ' (jen když platí)' : '')).join(', ')}</b></span>` : ''}
            ${usedIn(s).length ? `<span>Vloženo do: <b>${usedIn(s).map((x) => esc(x.name)).join(', ')}</b></span>` : ''}
            ${(s.fixed_off || []).length ? `<span>Bez pevného: <b>${s.fixed_off.map((id) => esc(scenById(id)?.name)).join(', ')}</b></span>` : ''}</div>
          ${s.note ? `<div class="muted small" style="margin-top:4px">${esc(s.note)}</div>` : ''}
          <div class="strip" data-zoom-group data-zoom-ids="${s.media_ids.filter((id) => mediaById(id)).join(',')}">${s.media_ids.slice(0, 14).map((id) => thumb(mediaById(id), true)).join('')}${s.media_ids.length > 14 ? `<div class="ph">+${s.media_ids.length - 14}</div>` : ''}</div>
        </div>
        <div class="row scen-actions">
          <button class="btn sm" data-act="edit" data-id="${s.id}">Upravit</button>
          <button class="btn sm" data-act="toggle" data-id="${s.id}">${s.paused ? 'Spustit' : 'Pozastavit'}</button>
          <button class="btn sm ghost" data-act="dup" data-id="${s.id}">Duplikovat</button>
          <button class="btn sm ghost danger" data-act="del" data-id="${s.id}">Smazat</button>
        </div>
      </div>`).join('') : '<div class="empty">Zatím žádné scénáře. Začněte výchozím scénářem – poběží vždy, když neběží nic jiného.</div>'}
    </div>`;
  $('#newScen').onclick = () => editScenario(null);
  if (flashScenario) {
    const card = $(`[data-scen="${flashScenario}"]`);
    flashScenario = null;
    if (card) { card.classList.add('flash'); card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }
  $$('[data-act]').forEach((b) => b.onclick = async () => {
    const s = store.scenarios.find((x) => x.id === +b.dataset.id);
    try {
      if (b.dataset.act === 'edit') return editScenario(s);
      if (b.dataset.act === 'dup') return editScenario({ ...JSON.parse(JSON.stringify(s)), id: 0, name: s.name + ' (kopie)', is_default: 0, is_fixed: 0 });
      if (b.dataset.act === 'toggle') {
        await busy(b, s.paused ? 'Spouštím…' : 'Pozastavuji…', () => api('scenario_toggle', { id: s.id }));
        toast(s.paused ? `Scénář „${s.name}“ znovu běží.` : `Scénář „${s.name}“ pozastaven.`);
        flashScenario = s.id;
      }
      if (b.dataset.act === 'del') {
        if (!confirm(`Smazat scénář „${s.name}“? Média v galerii zůstanou.${usedIn(s).length ? `\nScénář je vložen do: ${usedIn(s).map((x) => x.name).join(', ')} – odtud zmizí.` : ''}${s.is_fixed ? '\nJe to pevný scénář – přestane se zobrazovat ve všech scénářích.' : ''}`)) return;
        await busy(b, 'Mažu…', () => api('scenario_delete', { id: s.id }));
        toast(`Scénář „${s.name}“ smazán.`);
      }
      route();
    } catch (e) { toast(e.message, 'error'); }
  });
}

function editScenario(src) {
  clearInterval(timer);
  const s = src ? JSON.parse(JSON.stringify(src)) : {
    id: 0, name: '', note: '', is_default: store.scenarios.some((x) => x.is_default) ? 0 : 1, embed_only: 0, is_fixed: 0, fixed_off: [], includes: [], priority: 2, exclusive: 0, paused: 0,
    date_from: '', date_to: '', weekdays: '1234567', hours: '1'.repeat(24), exceptions: [], media_ids: [],
  };
  const sel = new Set(s.media_ids.filter((id) => mediaById(id)));
  s.fixed_off = s.fixed_off || [];

  main.innerHTML = `
    <div class="head">
      <div><h1>${s.id ? 'Upravit scénář' : 'Nový scénář'} ${helpBtn('@editor-scenare', true)}</h1></div>
      <div class="row save-bar"><a class="btn" href="#scenare" id="cancel">Zrušit</a><button class="btn primary" id="save">Uložit</button></div>
    </div>
    <div class="errbox" id="saveErr" hidden style="margin-bottom:16px"></div>
    <div class="editor">
      <div class="stack">
        <div class="panel stack">
          <label class="field">Název<input type="text" id="fName" value="${esc(s.name)}" placeholder="např. Eurojackpot – pondělí a čtvrtek"></label>
          <label class="field">Poznámka <span class="hint">(nepovinné)</span><input type="text" id="fNote" value="${esc(s.note)}"></label>
          <div class="field">Typ
            <div class="seg" id="fType"><button data-v="0">Plánovaný</button><button data-v="1">Výchozí</button><button data-v="3">Pevný</button><button data-v="2">Sada</button></div>
            <span class="hint" id="typeHint"></span>
          </div>
        </div>
        <div class="panel stack" id="rules">
          <h2 style="margin:0">Kdy běží</h2>
          <div class="cols2">
            <label class="field">Platnost od<input type="date" id="fFrom" value="${s.date_from || ''}"></label>
            <label class="field">Platnost do<input type="date" id="fTo" value="${s.date_to || ''}"></label>
          </div>
          <div class="field"><div class="row"><span class="grow">Dny v týdnu</span>
            <button class="btn sm ghost" data-wd="1234567">Vše</button><button class="btn sm ghost" data-wd="12345">Po–Pá</button><button class="btn sm ghost" data-wd="67">Víkend</button></div>
            <div class="toggles days" id="fDays">${DAYS.map((d, i) => `<div class="tg" data-i="${i + 1}">${d}</div>`).join('')}</div></div>
          <div class="field"><div class="row"><span class="grow">Hodiny <span class="hint" id="hoursHint"></span></span>
            <button class="btn sm ghost" id="hAll">Celý den</button><button class="btn sm ghost" id="hNone">Nic</button></div>
            <div class="toggles hours" id="fHours">${Array.from({ length: 24 }, (_, h) => `<div class="tg" data-h="${h}">${h}</div>`).join('')}</div>
            <span class="hint">Hodina „6“ = 6:00–6:59. Táhnutím myši označíte víc hodin najednou.</span></div>
          <div class="field">Výjimky <span class="hint">– dny, kdy scénář neběží</span>
            <div class="row"><input type="date" id="fExc" style="max-width:180px"><button class="btn sm" id="addExc">Přidat</button></div>
            <div class="exc-list" id="excList"></div></div>
          <div class="field">Priorita <span class="hint">– rozhoduje, kdo vyhraje ve skupině médií</span>
            <div class="seg" id="fPrio"><button data-v="1">Nízká</button><button data-v="2">Normální</button><button data-v="3">Vysoká</button></div></div>
          <label class="check-row"><input type="checkbox" id="fExcl" ${s.exclusive ? 'checked' : ''}>
            <span><b>Výhradní</b> – po dobu běhu potlačí všechny ostatní scénáře (např. mimořádné oznámení)</span></label>
        </div>
        <div class="panel stack" id="fixedPanel" style="display:none">
          <h2 style="margin:0">Pevné scénáře</h2>
          <p class="hint small" style="margin:0">Pevné scénáře se zobrazují vždy. Odškrtnutím je v tomto scénáři vypnete – dokud tento scénář běží, nezobrazí se.</p>
          <div id="fixedList"></div>
        </div>
        <label class="check-row panel"><input type="checkbox" id="fPaused" ${s.paused ? 'checked' : ''}><span>Pozastaveno (scénář se neuplatní)</span></label>
      </div>
      <div class="stack">
        <div class="panel droparea" id="inScen">
          <div class="row"><h2 class="grow" style="margin:0">Ve scénáři <span class="muted" id="selCount"></span></h2>
            <button class="btn sm ghost danger" id="clearSel">Odebrat vše</button></div>
          <p class="hint small" style="margin:6px 0 10px">${isTouch ? 'Média přidáte tlačítkem + v galerii níže.' : 'Přetáhněte sem média nebo celou složku z galerie. Odeberete je křížkem nebo přetažením zpět do galerie.'}</p>
          <div class="in-list" id="inList" data-zoom-group></div>
          <div class="inc-box">
            <div class="row"><b class="grow small">Vložené scénáře</b>
              <select id="incAdd" class="inc-add"></select></div>
            <div id="incList"></div>
          </div>
        </div>
        <div class="panel" id="galPanel">
          <div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Galerie</h2>
            <input type="search" id="pQ" placeholder="Hledat…" style="max-width:200px"></div>
          <div class="chips folder-bar tag-filter" id="pTags"></div>
          <div class="chips" id="pTypes" style="margin-top:8px"></div>
          <div class="pick-bar" id="pickBar"></div>
          <div class="grid" id="pGrid" data-zoom-group></div>
        </div>
      </div>
    </div>`;

  // typ
  const drawType = () => {
    $$('#fType button').forEach((b) => b.classList.toggle('on', +b.dataset.v === scenType(s)));
    $('#rules').style.display = scenType(s) ? 'none' : '';
    $('#typeHint').textContent = ['Běží podle pravidel níže.',
      'Běží vždy, když neběží žádný plánovaný scénář. Výchozí může být jen jeden.',
      'Sada médií pro vkládání do jiných scénářů – sama nikdy neběží. Kdy poběží, určuje scénář, do kterého ji vložíte.',
      'Zobrazuje se vždy ve všech scénářích, i ve výchozím a když nic neběží. V konkrétním scénáři ho lze vypnout v jeho nastavení.'][scenType(s)];
    drawFixed();
  };
  // pevné scénáře, které lze v tomto scénáři vypnout (jen u plánovaného a výchozího)
  const drawFixed = () => {
    const fixed = store.scenarios.filter((x) => x.is_fixed && x.id !== s.id);
    const show = [0, 1].includes(scenType(s)) && fixed.length > 0;
    $('#fixedPanel').style.display = show ? '' : 'none';
    if (!show) return;
    $('#fixedList').innerHTML = fixed.map((f) => `<label class="check-row"><input type="checkbox" data-fx="${f.id}" ${s.fixed_off.includes(f.id) ? '' : 'checked'}>
      <span>Zobrazovat <b>${esc(f.name)}</b>${f.paused ? ' <span class="badge b-paused">Pozastaveno</span>' : ''} <span class="hint">(médií: ${f.media_ids.length})</span></span></label>`).join('');
    $$('#fixedList [data-fx]').forEach((cb) => cb.onchange = () => {
      const id = +cb.dataset.fx;
      s.fixed_off = cb.checked ? s.fixed_off.filter((x) => x !== id) : [...new Set([...s.fixed_off, id])];
    });
  };
  $$('#fType button').forEach((b) => b.onclick = () => {
    const v = +b.dataset.v;
    s.is_default = v === 1 ? 1 : 0; s.embed_only = v === 2 ? 1 : 0; s.is_fixed = v === 3 ? 1 : 0;
    drawType();
  });
  // dny
  const drawDays = () => $$('#fDays .tg').forEach((t) => t.classList.toggle('on', s.weekdays.includes(t.dataset.i)));
  $$('#fDays .tg').forEach((t) => t.onclick = () => {
    s.weekdays = s.weekdays.includes(t.dataset.i) ? s.weekdays.replace(t.dataset.i, '') : [...s.weekdays + t.dataset.i].sort().join('');
    drawDays();
  });
  $$('[data-wd]').forEach((b) => b.onclick = () => { s.weekdays = b.dataset.wd; drawDays(); });
  // hodiny (s tažením)
  const drawHours = () => {
    $$('#fHours .tg').forEach((t) => t.classList.toggle('on', s.hours[t.dataset.h] === '1'));
    $('#hoursHint').textContent = '– ' + hoursText(s.hours);
  };
  const setHour = (h, v) => { s.hours = s.hours.slice(0, h) + v + s.hours.slice(h + 1); drawHours(); };
  let paint = null;
  $$('#fHours .tg').forEach((t) => {
    t.onmousedown = (e) => { e.preventDefault(); paint = s.hours[t.dataset.h] === '1' ? '0' : '1'; setHour(+t.dataset.h, paint); };
    t.onmouseenter = () => { if (paint !== null) setHour(+t.dataset.h, paint); };
    t.ontouchstart = (e) => { e.preventDefault(); setHour(+t.dataset.h, s.hours[t.dataset.h] === '1' ? '0' : '1'); };
  });
  document.onmouseup = () => { paint = null; };
  $('#hAll').onclick = () => { s.hours = '1'.repeat(24); drawHours(); };
  $('#hNone').onclick = () => { s.hours = '0'.repeat(24); drawHours(); };
  // výjimky
  const drawExc = () => {
    $('#excList').innerHTML = s.exceptions.map((d, i) => `<span class="chip">${fmtDate(d)}<button data-rx="${i}">×</button></span>`).join('');
    $$('[data-rx]').forEach((b) => b.onclick = () => { s.exceptions.splice(+b.dataset.rx, 1); drawExc(); });
  };
  $('#addExc').onclick = () => {
    const v = $('#fExc').value;
    if (v && !s.exceptions.includes(v)) { s.exceptions.push(v); s.exceptions.sort(); }
    $('#fExc').value = '';
    drawExc();
  };
  // priorita
  const drawPrio = () => $$('#fPrio button').forEach((b) => b.classList.toggle('on', +b.dataset.v === +s.priority));
  $$('#fPrio button').forEach((b) => b.onclick = () => { s.priority = +b.dataset.v; drawPrio(); });
  // média: „Ve scénáři“ + galerie (přetahování na počítači, tlačítka na mobilu)
  let pType = 'all', pq = '', lastIdx = null;
  const pTagSt = newTagState();
  const picked = new Set();
  const visible = () => store.media.filter((m) =>
    (pType === 'all' || m.kind === pType)
    && tagOk(m, pTagSt)
    && (m.original_name + ' ' + (m.grp || '') + ' ' + tagText(m)).toLowerCase().includes(pq));
  const addIds = (ids) => {
    let n = 0;
    ids.forEach((id) => { if (!sel.has(id) && mediaById(id) && !mediaById(id).archived) { sel.add(id); n++; } });
    picked.clear();
    drawIn(); drawPick();
    if (n) {
      toast(n === 1 ? 'Přidáno do scénáře.' : `Přidáno do scénáře: ${n}`);
      $('#inScen').classList.remove('pulse'); void $('#inScen').offsetWidth; $('#inScen').classList.add('pulse');
    }
  };
  const removeId = (id) => { sel.delete(id); drawIn(); drawPick(); };
  const dragImage = (e, n) => {
    if (n < 2) return;
    const g = document.createElement('div');
    g.className = 'drag-ghost'; g.textContent = `${n} médií`;
    document.body.append(g);
    e.dataTransfer.setDragImage(g, 20, 20);
    setTimeout(() => g.remove(), 0);
  };

  const drawInc = () => {
    const opts = store.scenarios.filter((x) => x.id !== s.id && !s.includes.some((i) => i.id === x.id) && !(s.id && reaches(x.id, s.id)));
    $('#incAdd').innerHTML = `<option value="">+ Vložit scénář…</option>${opts.map((x) =>
      `<option value="${x.id}">${esc(x.name)}${x.embed_only ? ' (sada)' : x.is_default ? ' (výchozí)' : x.is_fixed ? ' (pevný)' : ''}</option>`).join('')}`;
    $('#incAdd').disabled = !opts.length;
    $('#incList').innerHTML = s.includes.length ? s.includes.map((inc, i) => {
      const c = scenById(inc.id);
      if (!c) return '';
      const planned = !c.is_default && !c.embed_only && !c.is_fixed;
      const n = c.media_ids.length + c.includes.length;
      return `<div class="inc-item">
        <span class="inc-ico">📎</span>
        <div class="grow"><b>${esc(c.name)}</b> ${c.embed_only ? '<span class="badge b-set">Sada</span>' : ''}${c.paused ? ' <span class="badge b-paused">Pozastaveno</span>' : ''}
          <div class="hint small">${c.media_ids.length} médií${c.includes.length ? ` + ${c.includes.length} vložené` : ''}${planned ? ' · ' + esc(scenRules(c)) : ''}</div>
          ${planned ? `<label class="check-row small"><input type="checkbox" data-follow="${i}" ${inc.follow ? 'checked' : ''}><span>jen když platí i jeho vlastní pravidla</span></label>` : ''}
        </div>
        <button class="x" data-rmi="${i}" title="Odebrat vložený scénář">×</button>
      </div>`;
    }).join('') : '<div class="hint small">Žádné. Vložený scénář přidá svá média do tohoto scénáře.</div>';
    $$('[data-rmi]').forEach((b) => b.onclick = () => { s.includes.splice(+b.dataset.rmi, 1); drawInc(); });
    $$('[data-follow]').forEach((cb) => cb.onchange = () => { s.includes[+cb.dataset.follow].follow = cb.checked ? 1 : 0; });
  };
  const drawIn = () => {
    $('#selCount').textContent = `(${sel.size})`;
    const list = [...sel].map(mediaById).filter(Boolean).sort((a, b) => a.original_name.localeCompare(b.original_name, 'cs'));
    $('#inList').innerHTML = list.length ? list.map((m) => `
      <div class="in-item" draggable="${!isTouch}" data-id="${m.id}" title="${esc(m.original_name)}${m.archived ? ' (v archivu – dál hraje, jen se nenabízí)' : ''}">
        <div class="in-th">${thumb(m, true)}</div>
        <span class="nm">${m.archived ? '🗄 ' : ''}${esc(m.original_name)}</span>
        ${m.grp ? `<span class="tag">${esc(m.grp)}</span>` : ''}
        <button class="x" data-rm="${m.id}" title="Odebrat ze scénáře">×</button>
      </div>`).join('') : `<div class="empty small">${isTouch ? 'Zatím nic – přidejte média z galerie.' : 'Zatím nic – přetáhněte sem média z galerie.'}</div>`;
    $$('#inList [data-rm]').forEach((b) => b.onclick = () => removeId(+b.dataset.rm));
    $$('#inList .in-item').forEach((el) => el.ondragstart = (e) => {
      e.dataTransfer.setData('application/x-mp-remove', el.dataset.id);
      e.dataTransfer.effectAllowed = 'move';
    });
  };

  const drawPick = () => {
    tagBar($('#pTags'), store.media, pTagSt, () => { picked.clear(); drawPick(); }, { dragAdd: dragImage });
    $('#pTypes').innerHTML = [['all', 'Všechny typy'], ['slide', 'Slidy'], ['image', 'Obrázky'], ['video', 'Videa'], ['pdf', 'PDF'], ['text', 'Texty'], ['url', 'Odkazy'], ['html', 'HTML'], ['random', '🎲 Náhodné'], ['dated', '📅 Podle data']]
      .map(([k, l]) => `<button class="chip sm ${pType === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('');
    $$('#pTypes [data-t]').forEach((b) => b.onclick = () => { pType = b.dataset.t; picked.clear(); drawPick(); });

    const items = visible();
    const addable = items.filter((m) => !sel.has(m.id));
    $('#pickBar').innerHTML = [
      picked.size ? `<span><b>Vybráno ${picked.size}</b></span><button class="btn sm primary" id="addPicked">+ Přidat vybrané</button><button class="btn sm ghost" id="clrPicked">Zrušit výběr</button>` : '',
      addable.length && (pTagSt.sel.size || pTagSt.none || pType !== 'all' || pq)
        ? `<button class="btn sm" id="addAllVis">${pTagSt.sel.size === 1 && pType === 'all' && !pq ? `+ Přidat vše s tagem „${esc(allTags().find((t) => tagKey(t) === [...pTagSt.sel][0]) || '')}“` : '+ Přidat vše zobrazené'} (${addable.length})</button>` : '',
      !picked.size && !isTouch && items.length ? '<span class="hint small">Klik = označit (Shift = rozsah), dvojklik = přidat, nebo přetáhněte nahoru.</span>' : '',
    ].join('');
    $('#addPicked')?.addEventListener('click', () => addIds([...picked]));
    $('#clrPicked')?.addEventListener('click', () => { picked.clear(); drawPick(); });
    $('#addAllVis')?.addEventListener('click', () => addIds(addable.map((m) => m.id)));

    $('#pGrid').innerHTML = items.length ? items.map((m, i) => `
      <div class="card pick ${sel.has(m.id) ? 'in' : ''} ${picked.has(m.id) ? 'chosen' : ''}" data-id="${m.id}" data-i="${i}" draggable="${!isTouch && !sel.has(m.id)}">
        <div class="thumb">${thumb(m)}<span class="kind">${KIND[m.kind]}</span>${m.grp ? `<span class="tag">${esc(m.grp)}</span>` : ''}
          <button type="button" class="zoom-btn" data-zoom="${m.id}" title="Zvětšit" aria-label="Zvětšit">${ZOOM_ICON}</button></div>
        <div class="meta"><span class="name" title="${esc(m.original_name)}">${esc(m.original_name)}</span><span class="muted">${mediaInfo(m)}</span></div>
        ${sel.has(m.id) ? '<span class="add done" title="Už je ve scénáři">✓</span>' : `<button class="add" data-add="${m.id}" title="Přidat do scénáře">+</button>`}
      </div>`).join('') : `<div class="empty" style="grid-column:1/-1">${store.media.length ? 'Nic nenalezeno.' : 'Galerie je prázdná – nahrajte média v sekci <a href="#galerie">Galerie</a>.'}</div>`;
    $$('#pGrid [data-add]').forEach((b) => b.onclick = (e) => { e.stopPropagation(); addIds([+b.dataset.add]); });
    $$('#pGrid .card').forEach((c) => {
      const id = +c.dataset.id, i = +c.dataset.i;
      if (sel.has(id)) return;
      c.onclick = (e) => {
        if (isTouch) return addIds([id]);
        if (e.shiftKey && lastIdx !== null) {
          const [a, b] = [Math.min(lastIdx, i), Math.max(lastIdx, i)];
          items.slice(a, b + 1).forEach((m) => { if (!sel.has(m.id)) picked.add(m.id); });
        } else picked.has(id) ? picked.delete(id) : picked.add(id);
        lastIdx = i;
        drawPick();
      };
      c.ondblclick = () => addIds([id]);
      c.ondragstart = (e) => {
        const ids = picked.has(id) ? [...picked] : [id];
        e.dataTransfer.setData('application/x-mp-add', JSON.stringify(ids));
        e.dataTransfer.effectAllowed = 'copy';
        dragImage(e, ids.length);
      };
    });
  };

  // cíle přetahování
  $('#incAdd').onchange = (e) => {
    const id = +e.target.value;
    if (!id) return;
    s.includes.push({ id, follow: 0 });
    drawInc();
    toast(`Scénář „${scenById(id)?.name}“ vložen.`);
  };
  const zone = $('#inScen'), gal = $('#galPanel');
  const has = (e, t) => [...e.dataTransfer.types].includes(t);
  zone.ondragover = (e) => { if (has(e, 'application/x-mp-add')) { e.preventDefault(); zone.classList.add('over'); } };
  zone.ondragleave = (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('over'); };
  zone.ondrop = (e) => {
    zone.classList.remove('over');
    if (!has(e, 'application/x-mp-add')) return;
    e.preventDefault();
    addIds(JSON.parse(e.dataTransfer.getData('application/x-mp-add')));
  };
  gal.ondragover = (e) => { if (has(e, 'application/x-mp-remove')) { e.preventDefault(); gal.classList.add('over-rm'); } };
  gal.ondragleave = (e) => { if (!gal.contains(e.relatedTarget)) gal.classList.remove('over-rm'); };
  gal.ondrop = (e) => {
    gal.classList.remove('over-rm');
    if (!has(e, 'application/x-mp-remove')) return;
    e.preventDefault();
    removeId(+e.dataTransfer.getData('application/x-mp-remove'));
  };
  $('#pQ').oninput = (e) => { pq = e.target.value.toLowerCase(); drawPick(); };
  $('#clearSel').onclick = () => { if (sel.size && confirm('Odebrat ze scénáře všechna média?')) { sel.clear(); drawIn(); drawPick(); } };

  const showErr = (msg) => {
    const box = $('#saveErr');
    box.textContent = msg; box.hidden = false;
    box.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };
  $('#save').onclick = async () => {
    $('#saveErr').hidden = true;
    if (!$('#fName').value.trim()) { $('#fName').focus(); return showErr('Zadejte název scénáře.'); }
    const planned = scenType(s) === 0;
    if (planned && !s.weekdays) return showErr('Vyberte aspoň jeden den v týdnu.');
    if (planned && !s.hours.includes('1')) return showErr('Vyberte aspoň jednu hodinu.');
    if (planned && $('#fFrom').value && $('#fTo').value && $('#fFrom').value > $('#fTo').value) return showErr('Datum „od“ je po datu „do“.');
    const body = {
      id: s.id, name: $('#fName').value, note: $('#fNote').value, is_default: s.is_default, priority: s.priority,
      exclusive: $('#fExcl').checked, paused: $('#fPaused').checked, date_from: $('#fFrom').value || null,
      date_to: $('#fTo').value || null, weekdays: s.weekdays, hours: s.hours, exceptions: s.exceptions, media_ids: [...sel],
      embed_only: s.embed_only, includes: s.includes, is_fixed: s.is_fixed,
      fixed_off: [0, 1].includes(scenType(s)) ? s.fixed_off : [],
    };
    if (!sel.size && !s.includes.length && !confirm('Scénář nemá žádná média ani vložené scénáře. Uložit i tak?')) return;
    const other = store.scenarios.find((x) => x.is_default && x.id !== s.id);
    if (s.is_default && other && !confirm(`Výchozím scénářem je teď „${other.name}“. Nahradit ho tímto?`)) return;
    await busy($('#save'), 'Ukládám…', async () => {
      try {
        const r = await api('scenario_save', body);
        flashScenario = r.id;
        toast(`Scénář „${body.name.trim()}“ uložen.`);
        location.hash === '#scenare' ? route() : (location.hash = 'scenare');
      } catch (e) {
        showErr('Scénář se nepodařilo uložit: ' + e.message);
        toast('Scénář se nepodařilo uložit.', 'error');
      }
    });
  };
  $('#cancel').onclick = (e) => { e.preventDefault(); route(); };

  drawType(); drawDays(); drawHours(); drawExc(); drawPrio(); drawIn(); drawInc(); drawPick();
}

// ================= Tagy (od 1.17.0) =================
// Volné štítky pro třídění a hledání. Velikost písmen se nerozlišuje; nový tag přebírá zápis už existujícího.
const tagKey = (t) => String(t).toLocaleLowerCase('cs');
/** Všechny tagy v seznamu médií (výchozí: celá galerie i archiv), seřazené. */
function allTags(list = [...store.media, ...store.archive]) {
  const map = new Map();
  list.forEach((m) => (m?.tags || []).forEach((t) => { if (!map.has(tagKey(t))) map.set(tagKey(t), t); }));
  return [...map.values()].sort((a, b) => a.localeCompare(b, 'cs'));
}
const tagText = (m) => (m?.tags || []).join(' ');
/** Štítky na kartě: nejvýš 3, zbytek jako „+N“. S click = true jsou to tlačítka rychlého filtru. */
const tagsHtml = (m, click = false) => (m?.tags?.length ? `<span class="tags">${m.tags.slice(0, 3).map((t) => (click
  ? `<button type="button" class="tg" data-qtag="${esc(tagKey(t))}" title="Zobrazit jen média s tagem ${esc(t)}">${esc(t)}</button>`
  : `<span class="tg">${esc(t)}</span>`)).join('')}${m.tags.length > 3
  ? `<span class="tg more" title="${esc(m.tags.slice(3).join(', '))}">+${m.tags.length - 3}</span>` : ''}</span>` : '');
/** Pole pro tagy: štítky s × a vstup s nabídkou existujících tagů (Enter nebo čárka přidá). Vrací { get() }. */
function tagField(box, initial) {
  const tags = [...(initial || [])];
  const listId = 'tagList' + Math.random().toString(36).slice(2, 8);
  const add = (v) => {
    v = String(v).replace(/[,;]/g, ' ').replace(/\s+/g, ' ').trim();
    const inp = box.querySelector('.tag-in');
    if (!v) return true;
    if (v.length > 30) { toast('Tag může mít nejvýš 30 znaků.', 'warn'); return false; }
    v = allTags().find((t) => tagKey(t) === tagKey(v)) || v;
    if (!tags.some((t) => tagKey(t) === tagKey(v))) {
      if (tags.length >= 10) { toast('Médium může mít nejvýš 10 tagů.', 'warn'); return false; }
      tags.push(v);
    }
    if (inp) inp.value = '';
    draw(); box.querySelector('.tag-in')?.focus();
    return true;
  };
  function draw() {
    box.innerHTML = `<div class="tag-field">${tags.map((t, i) => `<span class="tg">${esc(t)}<button type="button" data-rm="${i}" aria-label="Odebrat tag ${esc(t)}">×</button></span>`).join('')}
      <input type="text" class="tag-in" list="${listId}" maxlength="30" placeholder="${tags.length ? '' : 'Přidat tag…'}" aria-label="Přidat tag"></div>
      <datalist id="${listId}">${allTags().filter((t) => !tags.some((x) => tagKey(x) === tagKey(t))).map((t) => `<option value="${esc(t)}">`).join('')}</datalist>`;
    box.querySelectorAll('[data-rm]').forEach((b) => b.onclick = () => { tags.splice(+b.dataset.rm, 1); draw(); box.querySelector('.tag-in').focus(); });
    const inp = box.querySelector('.tag-in');
    inp.onkeydown = (e) => {
      if (e.key === 'Enter' || e.key === ',' || e.key === ';') { e.preventDefault(); add(inp.value); }
      else if (e.key === 'Backspace' && !inp.value && tags.length) { tags.pop(); draw(); box.querySelector('.tag-in').focus(); }
    };
    // výběr z nabídky prohlížeče se přidá hned
    inp.oninput = () => { if (allTags().some((t) => t === inp.value)) add(inp.value); };
  }
  draw();
  return { get: () => { const p = box.querySelector('.tag-in')?.value; if (p?.trim() && !add(p)) throw new Error('Tag nejde přidat.'); return [...tags]; } };
}
/**
 * Řada tagů (od 1.18.0 místo složek): Vše, Bez tagu, tagy s počty, přepínač všechny / kterýkoli.
 * st = { sel: Set klíčů, mode: 'and' | 'or', none: jen média bez tagu }. Volby: o.onAll (klik na Vše), o.extra (HTML na konec),
 * o.dragAdd (tag jde přetáhnout: x-mp-add s čísly jeho médií), o.onDropTag(tag, ids) (na tag jde přetáhnout média).
 */
function tagBar(box, list, st, onChange, o = {}) {
  if (!box) return;
  const tags = allTags(list);
  [...st.sel].forEach((k) => { if (!tags.some((t) => tagKey(t) === k)) st.sel.delete(k); });
  const has = (m, k) => (m.tags || []).some((t) => tagKey(t) === k);
  const noTag = list.filter((m) => !(m.tags || []).length);
  const ids = (k) => (k === null ? noTag : list.filter((m) => has(m, k))).map((m) => m.id);
  const drag = o.dragAdd && !isTouch;
  box.innerHTML = `<button type="button" class="chip folder ${!st.sel.size && !st.none ? 'on' : ''}" data-all>Vše <span class="cnt">${list.length}</span></button>
    <button type="button" class="chip folder ${st.none ? 'on' : ''}" data-none ${drag ? 'draggable="true" title="Přetažením přidáte všechna média bez tagu"' : 'title="Média, která nemají žádný tag"'}>Bez tagu <span class="cnt">${noTag.length}</span></button>
    ${tags.map((t) => { const k = tagKey(t); return `<button type="button" class="chip folder tagchip ${st.sel.has(k) ? 'on' : ''}" data-tag="${esc(k)}" data-name="${esc(t)}"
      ${drag ? `draggable="true" title="Přetažením přidáte všechna média s tagem ${esc(t)}"` : ''}>🏷 ${esc(t)} <span class="cnt">${ids(k).length}</span></button>`; }).join('')}
    ${tags.length > 1 ? `<span class="seg tf-mode" title="Jak se kombinuje víc vybraných tagů"><button type="button" class="${st.mode === 'and' ? 'on' : ''}" data-mode="and" title="Médium musí mít všechny vybrané tagy">všechny</button><button type="button" class="${st.mode === 'or' ? 'on' : ''}" data-mode="or" title="Stačí kterýkoli z vybraných tagů">kterýkoli</button></span>` : ''}
    ${o.extra || ''}`;
  box.querySelector('[data-all]').onclick = () => { st.sel.clear(); st.none = false; o.onAll?.(); onChange(); };
  box.querySelector('[data-none]').onclick = () => { st.none = !st.none; st.sel.clear(); onChange(); };
  box.querySelectorAll('[data-tag]').forEach((b) => {
    const k = b.dataset.tag;
    b.onclick = () => { st.none = false; st.sel.has(k) ? st.sel.delete(k) : st.sel.add(k); onChange(); };
    if (drag) b.ondragstart = (e) => { const x = ids(k); e.dataTransfer.setData('application/x-mp-add', JSON.stringify(x)); o.dragAdd(e, x.length); };
    if (o.onDropTag) {
      b.ondragover = (e) => { if ([...e.dataTransfer.types].includes('application/x-mp-move')) { e.preventDefault(); b.classList.add('over'); } };
      b.ondragleave = () => b.classList.remove('over');
      b.ondrop = (e) => {
        b.classList.remove('over');
        const x = JSON.parse(e.dataTransfer.getData('application/x-mp-move') || '[]');
        if (x.length) { e.preventDefault(); o.onDropTag(b.dataset.name, x); }
      };
    }
  });
  if (drag) box.querySelector('[data-none]').ondragstart = (e) => { const x = ids(null); e.dataTransfer.setData('application/x-mp-add', JSON.stringify(x)); o.dragAdd(e, x.length); };
  box.querySelectorAll('[data-mode]').forEach((b) => b.onclick = () => { st.mode = b.dataset.mode; onChange(); });
}
const tagOk = (m, st) => {
  if (st.none) return !(m?.tags || []).length;
  if (!st.sel.size) return true;
  const k = new Set((m?.tags || []).map(tagKey));
  return st.mode === 'or' ? [...st.sel].some((x) => k.has(x)) : [...st.sel].every((x) => k.has(x));
};
const newTagState = () => ({ sel: new Set(), mode: 'and', none: false });
/** Tagy, které dostane nově nahrané nebo vytvořené médium: vybrané v galerii (víc jen při volbě „všechny“). */
function currentTags() {
  if (gTagSt.none || !gTagSt.sel.size || gView === 'archive' || (gTagSt.mode === 'or' && gTagSt.sel.size > 1)) return [];
  const names = allTags();
  return [...gTagSt.sel].map((k) => names.find((t) => tagKey(t) === k)).filter(Boolean);
}
let gTagSt = newTagState();
let gView = 'all'; // 'all' nebo 'archive'

/** Hromadně: přidat tagy vybraným médiím, nebo tag odebrat. */
function bulkTags(ids) {
  const sel = ids.map(mediaById).filter(Boolean);
  const present = allTags(sel);
  openModal(`<h2>Tagy u vybraných médií (${ids.length}) ${helpBtn('jak-pouzivat-tagy', true)}</h2><div class="stack">
    <div class="field">Přidat tagy <span class="hint">Enter nebo čárka tag potvrdí</span><div id="btAdd"></div></div>
    <div class="row"><span class="grow"></span><button class="btn primary" id="btGo">Přidat vybraným</button></div>
    ${present.length ? `<div class="field">Odebrat z vybraných <span class="hint">klikněte na tag</span>
      <div class="chips" id="btRm">${present.map((t) => `<button type="button" class="chip sm" data-rmtag="${esc(t)}" title="Odebrat tag ze všech vybraných médií">${esc(t)} ×</button>`).join('')}</div></div>` : ''}
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zavřít</button></div></div>`);
  const f = tagField($('#btAdd'), []);
  const done = (r, what) => { toast(`${what}: ${r.changed} ${plural(r.changed, 'médium', 'média', 'médií')}`); gSel.clear(); closeModal(); route(); };
  $('#btGo').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    try {
      const add = f.get();
      if (!add.length) { toast('Napište tag, který chcete přidat.', 'warn'); return; }
      done(await api('media_tags', { ids, add }), 'Tagy přidány');
    } catch (err) { toast(err.message, 'error'); }
  });
  $$('#btRm [data-rmtag]').forEach((b) => b.onclick = () => busy(b, '…', async () => {
    try { done(await api('media_tags', { ids, remove: [b.dataset.rmtag] }), `Tag „${b.dataset.rmtag}“ odebrán`); } catch (err) { toast(err.message, 'error'); }
  }));
}
/** Okno s tagy pro editor slidu a HTML dokumentu; cb(tags) při potvrzení. */
function editTagsModal(tags, cb) {
  openModal(`<h2>Tagy ${helpBtn('jak-pouzivat-tagy', true)}</h2><div class="stack">
    <p class="hint small" style="margin:0">Tagy slouží k třídění a hledání v Galerii a ve výběrech. Na to, co se promítá, nemají vliv.</p>
    <div id="etTags"></div>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="etOk">Použít</button></div></div>`);
  const f = tagField($('#etTags'), tags);
  $('#etTags .tag-in').focus();
  $('#etOk').onclick = () => { try { const t = f.get(); closeModal(); cb(t); } catch (e) { /* hláška už je */ } };
}

// ================= Galerie =================
const gSel = new Set();

const groupList = () => `<datalist id="groupList">${[...new Set(store.media.map((m) => m.grp).filter(Boolean))].sort().map((g) => `<option value="${esc(g)}">`).join('')}</datalist>`;
const commonFields = (m) => `
  <label class="field">Skupina <span class="hint">média ve stejné skupině se nahrazují</span>
    <input type="text" id="mGrp" value="${esc(m?.grp || '')}" list="groupList" placeholder="např. ER"></label>${groupList()}
  <div class="field">Tagy <span class="hint">pro třídění a hledání; Enter nebo čárka tag potvrdí</span><div id="mTags" data-tags="${esc(JSON.stringify(m ? m.tags || [] : currentTags()))}"></div></div>`;
async function saveCommon(id, m) {
  const grp = $('#mGrp').value.trim();
  const tags = $('#mTags')?._tf ? $('#mTags')._tf.get() : (m?.tags || []);
  const tagsChanged = JSON.stringify(tags) !== JSON.stringify(m?.tags || []);
  if (!m || (m.grp || '') !== grp || tagsChanged) await api('media_update', { id, grp, ...(tagsChanged ? { tags } : {}) });
}

function packResultToast(r) {
  const parts = [];
  if (r.done) parts.push(`Zkomprimováno: ${r.done}`);
  if (r.skipped) parts.push(`beze změny (už jsou dost malé): ${r.skipped}`);
  if (r.errors?.length) parts.push(`chyby: ${r.errors.join('; ')}`);
  toast(parts.join(' · ') || 'Nic ke kompresi.', r.errors?.length ? 'error' : 'ok');
}
/** Okno média: ve kterých scénářích je a tlačítko „Přidat do scénáře“. */
async function drawAddToScenario(m, fresh = true) {
  if (fresh) { try { store.scenarios = await api('scenarios'); } catch { /* zůstane poslední známý stav */ } }
  const box = $('#mScenBox');
  if (!box) return;
  const tag = (s) => (s.is_default ? ' (výchozí)' : s.is_fixed ? ' (pevný)' : s.embed_only ? ' (sada)' : '') + (s.paused ? ' – pozastaveno' : '');
  const inS = store.scenarios.filter((s) => s.media_ids.includes(m.id));
  const other = store.scenarios.filter((s) => !s.media_ids.includes(m.id));
  box.innerHTML = `<b>Scénáře</b> – ${inS.length ? `je ve scénářích: ${inS.map((s) => `<b>${esc(s.name)}</b>`).join(', ')}` : 'zatím není v žádném scénáři.'}
    ${other.length ? `<div class="row add-scen" style="margin-top:8px"><select id="mScen" aria-label="Scénář"><option value="">Vyberte scénář…</option>
      ${other.map((s) => `<option value="${s.id}">${esc(s.name + tag(s))}</option>`).join('')}</select>
      <button class="btn sm" id="mAddScen">Přidat do scénáře</button></div>`
      : store.scenarios.length ? '' : '<div class="muted" style="margin-top:6px">Zatím nemáte žádný scénář.</div>'}`;
  $('#mAddScen')?.addEventListener('click', (e) => busy(e.currentTarget, 'Přidávám…', async () => {
    const sid = +$('#mScen').value;
    if (!sid) { toast('Vyberte scénář.', 'error'); return; }
    try {
      await api('scenario_add_media', { scenario_id: sid, media_id: m.id });
      store.scenarios = await api('scenarios');
      toast(`Přidáno do scénáře „${store.scenarios.find((s) => s.id === sid)?.name || ''}“.`);
      drawAddToScenario(m, false);
    } catch (err) { toast(err.message, 'error'); }
  }));
}

function editMedia(m) {
  const c = m.compressed;
  const packBox = m.kind !== 'image' ? '' : `<div class="panel-sub small">${c
    ? `<b>🗜 Komprimováno</b>: ${fmtSize(c.orig_size)} → ${fmtSize(m.size)}, ${c.orig_w}×${c.orig_h} → ${c.w}×${c.h} px. Původní soubor (.${esc(c.orig_ext)}) je uchovaný.
       <div class="row" style="margin-top:8px"><button class="btn sm" id="mRestore">Vrátit původní</button><button class="btn sm ghost" id="mPack">Komprimovat znovu podle Nastavení</button></div>`
    : `${m.width ? `${m.width}×${m.height} px · ` : ''}${fmtSize(m.size)}
       <div class="row" style="margin-top:8px"><button class="btn sm" id="mPack">🗜 Zmenšit a komprimovat</button></div>`}</div>`;
  const accept = { image: 'image/*', video: 'video/*', pdf: 'application/pdf,.pdf' }[m.kind];
  openModal(`<h2>Upravit ${KIND[m.kind].toLowerCase()} ${helpBtn('jak-nahradit-soubor', true)}</h2><div class="stack">
    <label class="field">Název souboru <span class="hint">pod tímto názvem se objeví na Google Disku; přípona zůstane</span>
      <input type="text" id="mName" value="${esc(m.original_name)}"></label>
    ${packBox}
    ${accept ? `<div class="panel-sub small"><b>Nahradit soubor</b> – nový soubor stejného druhu; položka zůstane ve všech scénářích, náhodných výběrech i slidech.
      <div class="row" style="margin-top:8px"><button class="btn sm" id="mReplace">Vybrat nový soubor…</button><span class="muted" id="mRepState"></span></div>
      <input type="file" id="mRepFile" accept="${accept}" hidden></div>` : ''}
    <div class="panel-sub small" id="mScenBox"></div>
    ${commonFields(m)}
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="mSave">Uložit</button></div></div>`);
  drawAddToScenario(m);
  $('#mPack')?.addEventListener('click', (e) => busy(e.currentTarget, 'Komprimuji…', async () => {
    try { const r = await api('media_compress', { ids: [m.id] }); packResultToast(r); closeModal(); route(); } catch (err) { toast(err.message, 'error'); }
  }));
  $('#mRestore')?.addEventListener('click', (e) => busy(e.currentTarget, 'Vracím…', async () => {
    try { await api('media_restore', { id: m.id }); toast('Vrácen původní soubor.'); closeModal(); route(); } catch (err) { toast(err.message, 'error'); }
  }));
  $('#mReplace')?.addEventListener('click', () => $('#mRepFile').click());
  $('#mRepFile')?.addEventListener('change', async (e) => {
    const f = e.target.files[0];
    if (!f) return;
    if (!confirm(`Nahradit „${m.original_name}“ souborem „${f.name}“?${m.used ? `\nPoložka se používá ${m.used}× – všude se použije nový soubor.` : ''}`)) { e.target.value = ''; return; }
    const btn = $('#mReplace');
    await busy(btn, 'Nahrávám…', async () => {
      let sent = 0;
      try {
        const r = await uploadOne(f, { replace_id: m.id }, (n) => { sent += n; $('#mRepState').textContent = `${Math.round(sent / f.size * 100)} %`; });
        toast(`Soubor nahrazen: ${r.original_name}${r.compressed ? ' (zkomprimováno)' : ''}${r.note && !r.compressed ? ` · ${r.note}` : ''}`);
        closeModal(); route();
      } catch (err) { toast(err.message, 'error'); $('#mRepState').textContent = ''; }
    });
  });
  $('#mSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    try {
      await api('media_update', { id: m.id, original_name: $('#mName').value });
      await saveCommon(m.id, m);
      closeModal(); toast('Uloženo.'); route();
    } catch (err) { toast(err.message, 'error'); }
  });
}

function editText(m) {
  openModal(`<h2>${m ? 'Upravit text' : 'Nový běžící text'} ${helpBtn('jak-pridat-bezici-text', true)}</h2><div class="stack">
    <label class="field">Název <span class="hint">na Disku jako „název.txt“</span>
      <input type="text" id="mName" value="${esc(m ? m.original_name.replace(/\.txt$/i, '') : '')}" placeholder="např. Jackpot"></label>
    <label class="field">Text <span class="hint">přehrávač ho zobrazí jako běžící řádek</span>
      <textarea id="mText" rows="4" placeholder="Dnes jackpot Eurojackpot 120 mil. Kč!">${esc(m?.content || '')}</textarea></label>
    <div class="marquee" id="mPrev"><span></span></div>
    ${commonFields(m)}
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="mSave">Uložit</button></div></div>`);
  const upd = () => { $('#mPrev span').textContent = $('#mText').value || ' '; };
  $('#mText').oninput = upd; upd();
  $('#mSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    try {
      const r = await api('text_save', { id: m?.id || 0, name: $('#mName').value, content: $('#mText').value });
      await saveCommon(r.media.id, r.media);
      closeModal(); toast(`Text „${r.media.original_name}“ uložen.`); route();
    } catch (err) { toast(err.message, 'error'); }
  });
}

function editUrl(m) {
  openModal(`<h2>${m ? 'Upravit odkaz' : 'Nový odkaz na web'} ${helpBtn('jak-pridat-odkaz-na-web', true)}</h2><div class="stack">
    <label class="field">Název <span class="hint">na Disku jako „název.url“</span>
      <input type="text" id="mName" value="${esc(m ? m.original_name.replace(/\.url$/i, '') : '')}" placeholder="např. Výsledky losování"></label>
    <label class="field">Adresa stránky
      <input type="text" id="mUrl" value="${esc(m?.content || '')}" placeholder="https://…" inputmode="url"></label>
    ${commonFields(m)}
    <p class="hint small" style="margin:0">Přehrávač zobrazí jen stránky, které se mu podaří načíst. Při uložení adresu ověřím.</p>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="mSave">Uložit</button></div></div>`);
  $('#mSave').onclick = (e) => busy(e.currentTarget, 'Ověřuji a ukládám…', async () => {
    try {
      const r = await api('url_save', { id: m?.id || 0, name: $('#mName').value, content: $('#mUrl').value });
      await saveCommon(r.media.id, r.media);
      closeModal();
      if (r.warning) toast(`Odkaz uložen, ale pozor: ${r.warning}`, 'warn'); else toast(`Odkaz „${r.media.original_name}“ uložen, stránka odpovídá.`);
      route();
    } catch (err) { toast(err.message, 'error'); }
  });
}

function editPool(m) {
  const members = new Set(m?.members || []);
  let pq = '';
  const tSt = newTagState();
  openModal(`<h2>${m ? 'Upravit náhodný výběr' : 'Nový náhodný výběr'} ${helpBtn('jak-vytvorit-nahodny-vyber', true)}</h2><div class="stack">
    <p class="hint small" style="margin:0">Ve scénáři se chová jako jedno médium. Každou hodinu jde na obrazovku jedno z vybraných médií, postupně všechna bez opakování.</p>
    <label class="field">Název<input type="text" id="mName" value="${esc(m?.original_name || '')}" placeholder="např. Tipy Sportka"></label>
    ${commonFields(m)}
    <div class="field">Média ve výběru <span class="hint" id="poolCnt"></span>
      <div class="row"><span class="grow"></span><input type="search" id="poolQ" placeholder="Hledat…" style="max-width:180px"></div>
      <div class="chips folder-bar tag-filter" id="poolT" style="margin-top:6px"></div>
      <div class="grid small-grid" id="poolGrid"></div></div>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="mSave">Uložit</button></div></div>`);
  const draw = () => {
    $('#poolCnt').textContent = `– vybráno ${members.size} (aspoň 2)`;
    const cand = store.media.filter((x) => ['image', 'video', 'slide', 'html'].includes(x.kind));
    tagBar($('#poolT'), cand, tSt, draw);
    const items = cand.filter((x) => tagOk(x, tSt)
      && `${x.original_name} ${tagText(x)}`.toLowerCase().includes(pq));
    $('#poolGrid').innerHTML = items.map((x) => `<div class="card pick ${members.has(x.id) ? 'chosen' : ''}" data-id="${x.id}">
      ${members.has(x.id) ? '<span class="check">✓</span>' : ''}<div class="thumb">${thumb(x)}</div>
      <div class="meta"><span class="name">${esc(x.original_name)}</span></div></div>`).join('') || '<div class="empty small" style="grid-column:1/-1">Žádné obrázky ani videa.</div>';
    $$('#poolGrid .card').forEach((c) => c.onclick = () => { const id = +c.dataset.id; members.has(id) ? members.delete(id) : members.add(id); draw(); });
  };
  $('#poolQ').oninput = (e) => { pq = e.target.value.toLowerCase(); draw(); };
  draw();
  $('#mSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    try {
      const r = await api('pool_save', { id: m?.id || 0, name: $('#mName').value, member_ids: [...members] });
      await saveCommon(r.media.id, r.media);
      closeModal(); toast(`Náhodný výběr „${r.media.original_name}“ uložen.`); route();
    } catch (err) { toast(err.message, 'error'); }
  });
}

/** Datum z názvu souboru (2026-10-24, 2026_10_24, 20261024, 24.10.2026, 24-10-2026), nebo null. */
/** Platný den ve tvaru RRRR-MM-DD s rokem 2000–2099. */
const validDay = (d) => /^20\d\d-\d\d-\d\d$/.test(String(d || '')) && !isNaN(new Date(d + 'T12:00'));
function dateFromName(name) {
  const n = String(name || '');
  const ok = (y, mo, d) => { const dt = new Date(+y, +mo - 1, +d); return dt.getFullYear() === +y && dt.getMonth() === +mo - 1 && dt.getDate() === +d && +y >= 2020 && +y <= 2099; };
  const fmt = (y, mo, d) => `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
  let m = n.match(/(?:^|\D)(20[2-9]\d)[-_. ]?(\d{1,2})[-_. ]?(\d{1,2})(?!\d)/);
  if (m && ok(m[1], m[2], m[3])) return fmt(m[1], m[2], m[3]);
  m = n.match(/(?:^|\D)(\d{1,2})[-_. ](\d{1,2})[-_. ](20\d\d)(?!\d)/);
  if (m && ok(m[3], m[2], m[1])) return fmt(m[3], m[2], m[1]);
  m = n.match(/(?:^|\D)(\d\d)(\d\d)(\d\d)(?!\d)/); // RRMMDD, např. er_261022_01.jpg
  if (m && +m[1] >= 20 && ok('20' + m[1], m[2], m[3])) return fmt('20' + m[1], m[2], m[3]);
  return null;
}

function editDated(m) {
  // položky: Map id → Set dnů
  const items = new Map((m?.items || []).map((it) => [it.id, new Set(it.days)]));
  let pq = '', pt = 'all';
  const tSt = newTagState();
  openModal(`<h2>${m ? 'Upravit výběr podle data' : 'Nový výběr podle data'} ${helpBtn('jak-vytvorit-vyber-podle-data', true)}</h2><div class="stack">
    <p class="hint small" style="margin:0">Ve scénáři se chová jako jedno médium. Každá položka se zobrazí <b>jen ve své dny</b> (celý den, v čase podle scénáře); jedna položka může mít více dní a na jeden den může připadnout více položek.
      V den, kdy výběr nemá nic a scénář jinak nic nezobrazuje, nastoupí výchozí scénář.</p>
    <label class="field">Název<input type="text" id="mName" value="${esc(m?.original_name || '')}" placeholder="např. Extra renta"></label>
    ${commonFields(m)}
    <div class="field">Položky a dny <span class="hint" id="dCnt"></span><div id="dItems" class="dated-edit"></div></div>
    <details id="dAdd" ${items.size ? '' : 'open'}><summary><b>+ Přidat média</b> <span class="hint">– datum se předvyplní z názvu souboru, pokud ho obsahuje</span></summary>
      <div class="row" style="margin-top:8px"><div class="chips grow" id="dT"></div><input type="search" id="dQ" placeholder="Hledat…" style="max-width:180px"></div>
      <div class="chips folder-bar tag-filter" id="dTg" style="margin-top:6px"></div>
      <div class="grid small-grid" id="dGrid" style="margin-top:8px"></div></details>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="mSave">Uložit</button></div></div>`);
  const t = todayIso();
  const drawItems = () => {
    const list = [...items.entries()].map(([id, days]) => ({ id, days: [...days].sort() }))
      .sort((a, b) => (a.days[0] || '9999').localeCompare(b.days[0] || '9999') || a.id - b.id);
    const nDays = new Set(list.flatMap((x) => x.days)).size;
    $('#dCnt').textContent = `– ${list.length} médií, ${nDays} dní`;
    $('#dItems').innerHTML = list.length ? list.map((it) => {
      const x = mediaById(it.id);
      return `<div class="de-row" data-id="${it.id}"><div class="de-th">${thumb(x)}</div>
        <div class="grow"><div class="de-name">${esc(x?.original_name || '?')}</div>
          <div class="de-days">${it.days.map((d) => `<span class="chip sm ${!validDay(d) ? 'bad' : d < t ? 'past' : d === t ? 'on' : ''}" ${!validDay(d) ? 'title="Chybný rok – odeberte a zadejte znovu"' : ''}>${fmtDate(d)}<button data-rmday="${d}" title="Odebrat den">×</button></span>`).join('')}
            ${it.days.length ? '' : '<span class="tag err">bez data – nezobrazí se</span>'}
            <input type="date" class="de-add" min="2000-01-01" max="2099-12-31" title="Datum – pak klikněte na Přidat nebo stiskněte Enter"><button class="btn sm de-addbtn">+ Přidat</button></div></div>
        <button class="x" data-rmit title="Odebrat z výběru">×</button></div>`;
    }).join('') : '<div class="empty small">Zatím nic – přidejte média níže.</div>';
    $$('#dItems .de-row').forEach((row) => {
      const id = +row.dataset.id;
      row.querySelector('[data-rmit]').onclick = () => { items.delete(id); drawItems(); drawGrid(); };
      row.querySelectorAll('[data-rmday]').forEach((b) => b.onclick = () => { items.get(id).delete(b.dataset.rmday); drawItems(); });
      // den se přidá až tlačítkem nebo Enterem – při psaní data prohlížeč hlásí změnu už po první číslici roku
      const inp = row.querySelector('.de-add');
      const add = () => {
        if (!validDay(inp.value)) { toast('Zadejte celé datum (den, měsíc a čtyřmístný rok 2000–2099).', 'warn'); inp.focus(); return; }
        items.get(id).add(inp.value); drawItems();
        $(`#dItems .de-row[data-id="${id}"] .de-add`)?.focus();
      };
      row.querySelector('.de-addbtn').onclick = add;
      inp.onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); add(); } };
    });
  };
  const drawGrid = () => {
    $('#dT').innerHTML = [['all', 'Všechny typy'], ['image', 'Obrázky'], ['slide', 'Slidy'], ['video', 'Videa'], ['pdf', 'PDF'], ['html', 'HTML']]
      .map(([k, l]) => `<button class="chip sm ${pt === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('');
    $$('#dT [data-t]').forEach((b) => b.onclick = () => { pt = b.dataset.t; drawGrid(); });
    const cand = store.media.filter((x) => ['image', 'video', 'slide', 'pdf', 'html'].includes(x.kind));
    tagBar($('#dTg'), cand, tSt, drawGrid);
    const list = cand.filter((x) => (pt === 'all' || x.kind === pt) && tagOk(x, tSt) && `${x.original_name} ${tagText(x)}`.toLowerCase().includes(pq));
    $('#dGrid').innerHTML = list.map((x) => `<div class="card pick ${items.has(x.id) ? 'chosen' : ''}" data-id="${x.id}">
      ${items.has(x.id) ? '<span class="check">✓</span>' : ''}<div class="thumb">${thumb(x)}</div>
      <div class="meta"><span class="name">${esc(x.original_name)}</span>${dateFromName(x.original_name) ? `<span class="muted small">📅 ${fmtDate(dateFromName(x.original_name))}</span>` : ''}</div></div>`).join('')
      || '<div class="empty small" style="grid-column:1/-1">Žádná vhodná média.</div>';
    $$('#dGrid .card').forEach((c) => c.onclick = () => {
      const id = +c.dataset.id;
      if (items.has(id)) items.delete(id);
      else { const d = dateFromName(mediaById(id)?.original_name); items.set(id, new Set(d ? [d] : [])); }
      drawItems(); drawGrid();
    });
  };
  $('#dQ').oninput = (e) => { pq = e.target.value.toLowerCase(); drawGrid(); };
  drawItems(); drawGrid();
  $('#mSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    const bad = [...items.entries()].filter(([, d]) => [...d].some((x) => !validDay(x))).map(([id]) => mediaById(id)?.original_name);
    if (bad.length) { toast(`Chybné datum (rok mimo 2000–2099) u: ${bad.join(', ')}. Odeberte ho a zadejte znovu.`, 'error'); return; }
    const noDate = [...items.entries()].filter(([, d]) => !d.size).map(([id]) => mediaById(id)?.original_name);
    if (noDate.length && !confirm(`Bez data (nezobrazí se): ${noDate.join(', ')}.\nUložit i tak? Položky bez data se z výběru vynechají.`)) return;
    try {
      const r = await api('dated_save', { id: m?.id || 0, name: $('#mName').value,
        items: [...items.entries()].filter(([, d]) => d.size).map(([id, d]) => ({ id, days: [...d] })) });
      await saveCommon(r.media.id, r.media);
      closeModal(); toast(`Výběr podle data „${r.media.original_name}“ uložen.`); route();
    } catch (err) { toast(err.message, 'error'); }
  });
}

const editAny = (m) => ({ text: editText, url: editUrl, random: editPool, dated: editDated, slide: editSlide, html: editHtml }[m.kind] || editMedia)(m);

// Přidávací dlaždice v Galerii: [id, ikona, název, popis v bublině, je to nahrání]
const ADD_TILES = [
  ['upTile', 'upload', 'Nahrát soubor', 'Obrázky, videa, PDF nebo HTML dokumenty z počítače nebo telefonu (na telefonu i vyfotit). Na počítači je můžete také přetáhnout do Galerie.', true],
  ['newSlide', 'slide', 'Slide', 'Obrázek s texty a hodnotami z Dat, který si složíte v editoru (výsledky losování, ceník, akce). Texty se mohou samy měnit.'],
  ['newText', 'text', 'Běžící text', 'Text, který na obrazovce běží jako titulek.'],
  ['newUrl', 'url', 'Odkaz na web', 'Zobrazí webovou stránku podle zadané adresy.'],
  ['newPool', 'pool', 'Náhodný výběr', 'Několik médií, z nichž se každou hodinu zobrazí jedno náhodně (např. losy).'],
  ['newDated', 'dated', 'Podle data', 'Média, která se zobrazí jen ve vybrané dny (např. plakát slosování na daný den).'],
  ['newHtml', 'html', 'HTML', 'Dokument, který napíšete v editoru (otevírací doba, oznámení) nebo nahrajete jako soubor .html. Může obsahovat hodnoty z Dat.'],
];
// malé ikony tlačítek na kartě média (název je v popisku při najetí a pro čtečky)
const CARD_ICONS = {
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
  arch: '<rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9"/><path d="M10 13h4"/>',
  unarch: '<path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 010 12h-3"/>',
  del: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/><path d="M10 11v6M14 11v6"/>',
};
const cardBtn = (attr, id, tip, icon, cls = '') => `<button class="btn sm ghost icon ${cls}" ${attr}="${id}" title="${esc(tip)}" aria-label="${esc(tip.split(' – ')[0])}"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${CARD_ICONS[icon]}</svg></button>`;
const TILE_ICONS = {
  html: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="m9.5 9.5-3 2.5 3 2.5M14.5 9.5l3 2.5-3 2.5"/></svg>',
  upload: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/><path d="M12 15V4"/><path d="M7.5 8.5 12 4l4.5 4.5"/></svg>',
  slide: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="12" rx="2"/><path d="M7 9h6M7 12.5h9"/><path d="M9 21h6M12 17v4"/></svg>',
  text: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2.5" y="8" width="19" height="8" rx="2"/><path d="M6 12h7"/><path d="m16 10.2 2 1.8-2 1.8"/></svg>',
  url: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.8 5.6 3.8 9s-1.3 6.4-3.8 9c-2.5-2.6-3.8-5.6-3.8-9S9.5 5.6 12 3Z"/></svg>',
  pool: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="3.5"/><circle cx="8.5" cy="8.5" r="1.1" class="dot"/><circle cx="15.5" cy="15.5" r="1.1" class="dot"/><circle cx="12" cy="12" r="1.1" class="dot"/><circle cx="15.5" cy="8.5" r="1.1" class="dot"/><circle cx="8.5" cy="15.5" r="1.1" class="dot"/></svg>',
  dated: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/><path d="M8 14h2M11.5 14h2M15 14h1.5M8 17h2"/></svg>',
};

function viewGallery() {
  let type = 'all', q = '', lastIdx = null;
  [...gSel].forEach((id) => { if (!mediaById(id)) gSel.delete(id); });

  main.innerHTML = `
    <div class="head">
      <div><h1>Galerie ${helpBtn('@galerie')}</h1><p class="sub">${store.media.length} položek · ${fmtSize(store.media.reduce((a, m) => a + m.size, 0))}</p></div>
    </div>
    <div class="add-tiles" id="addTiles">${ADD_TILES.map(([id, ico, name, tip, up]) => `<button type="button" class="add-tile${up ? ' up' : ''}" id="${id}" aria-describedby="${id}Tip">
      ${up ? '' : '<span class="plus" aria-hidden="true">+</span>'}${TILE_ICONS[ico]}<span class="lbl">${name}</span><span class="tip" role="tooltip" id="${id}Tip"><b>${name}</b>${tip}</span></button>`).join('')}</div>
    <div class="folder-bar tag-filter" id="gTags"></div>
    <div class="row filter-row" style="margin:10px 0 14px"><div class="chips grow" id="gTypes"></div><input type="search" id="gQ" placeholder="Hledat…" style="max-width:220px"></div>
    <div class="drop" id="drop">
      <span class="drop-txt"><b>Přetáhněte sem obrázky, videa, PDF nebo HTML</b> nebo klikněte pro výběr</span><span id="dropTo"></span>
      <input type="file" id="fileIn" multiple accept="image/*,video/*,application/pdf,.pdf,.html,.htm,text/html" hidden>
      <div id="upStatus" class="small" style="margin-top:8px"></div>
      <div class="progress" id="prog" hidden><div></div></div>
    </div>
    <div class="bulk-bar" id="bulk" hidden></div>
    <div class="grid" id="gGrid" style="margin-top:16px"></div>`;

  // řada tagů nahoře (od 1.18.0 místo složek): Vše, Bez tagu, tagy s počty, všechny / kterýkoli, vpravo Archiv
  const drawTags = () => {
    const list = inArchive() ? store.archive : store.media;
    tagBar($('#gTags'), list, gTagSt, () => { gSel.clear(); drawTags(); render(); }, {
      onAll: () => { gView = 'all'; },
      onDropTag: (name, ids) => addTag(ids, name),
      extra: `<span class="grow"></span><button type="button" class="chip folder ${inArchive() ? 'on' : ''}" id="archiveChip" title="Média, která se už nenabízejí, ale dál hrají tam, kde jsou použitá">🗄 Archiv <span class="cnt">${store.archive.length} · ${fmtSize(store.archive.reduce((a, m) => a + m.size, 0))}</span></button>`,
    });
    $('#archiveChip').onclick = () => { gView = inArchive() ? 'all' : 'archive'; gSel.clear(); drawTags(); render(); };
    const t = currentTags();
    $('#dropTo').innerHTML = t.length ? ` – ${t.length > 1 ? 'dostanou tagy' : 'dostanou tag'} ${t.map((x) => `<b>${esc(x)}</b>`).join(', ')}` : '';
  };
  async function addTag(ids, name) {
    try {
      const r = await api('media_tags', { ids, add: [name] });
      toast(`Tag „${name}“ přidán: ${r.changed} ${plural(r.changed, 'médium', 'média', 'médií')}`);
      gSel.clear(); route();
    } catch (e) { toast(e.message, 'error'); }
  }

  async function archiveIds(ids, archive, btn) {
    try {
      const r = await busy(btn, archive ? 'Archivuji…' : 'Vracím…', () => api('media_archive', { ids, archive }));
      if (!r) return;
      toast(archive ? `Do archivu přesunuto: ${r.changed}` : `Z archivu vráceno: ${r.changed}`);
      gSel.clear(); route();
    } catch (e) { toast(e.message, 'error'); }
  }
  const usedInText = (m) => {
    const u = m.used_in || {}, p = [];
    if (u.scenarios?.length) p.push((u.scenarios.length > 1 ? 'scénáře ' : 'scénář ') + u.scenarios.map((n) => `„${esc(n)}“`).join(', '));
    if (u.pools?.length) p.push((u.pools.length > 1 ? 'výběry ' : 'výběr ') + u.pools.map((n) => `„${esc(n)}“`).join(', '));
    if (u.slides?.length) p.push((u.slides.length > 1 ? 'slidy ' : 'slide ') + u.slides.map((n) => `„${esc(n)}“`).join(', '));
    return p.length ? `Používá se: ${p.join('; ')}` : 'Nikde se nepoužívá';
  };

  const inArchive = () => gView === 'archive';
  const visible = () => (inArchive() ? store.archive : store.media).filter((m) =>
    (type === 'all' || (type === 'unused' ? !m.used : m.kind === type))
    && tagOk(m, gTagSt)
    && (m.original_name + ' ' + (m.grp || '') + ' ' + tagText(m) + ' ' + (m.content || '')).toLowerCase().includes(q));

  const drawBulk = () => {
    const b = $('#bulk');
    b.hidden = !gSel.size;
    if (!gSel.size) return;
    if (inArchive()) {
      b.innerHTML = `<b>Vybráno ${gSel.size}</b>
        <button class="btn sm" id="bUnarch">Vrátit z archivu</button>
        <button class="btn sm danger" id="bDel">Smazat trvale</button>
        <button class="btn sm ghost" id="bAll">Vybrat vše zobrazené</button>
        <button class="btn sm ghost" id="bNone">Zrušit výběr</button>`;
      $('#bUnarch').onclick = (e) => archiveIds([...gSel], false, e.currentTarget);
      $('#bAll').onclick = () => { visible().forEach((m) => gSel.add(m.id)); render(); };
      $('#bNone').onclick = () => { gSel.clear(); render(); };
      $('#bDel').onclick = (e) => {
        const used = [...gSel].map(mediaById).filter((m) => m?.used).length;
        if (!confirm(`Trvale smazat ${gSel.size} položek?${used ? `\n${used} z nich se ještě používá – budou odebrány ze scénářů, výběrů i slidů.` : ''}`)) return;
        busy(e.currentTarget, 'Mažu…', async () => {
          try { const r = await api('media_delete', { ids: [...gSel] }); toast(`Smazáno: ${r.deleted}`); gSel.clear(); route(); } catch (err) { toast(err.message, 'error'); }
        });
      };
      return;
    }
    b.innerHTML = `<b>Vybráno ${gSel.size}</b>
      ${[...gSel].some((id) => mediaById(id)?.kind === 'image') ? '<button class="btn sm" id="bPack" title="Zmenší a zkomprimuje vybrané obrázky podle Nastavení; originály zůstanou uchované">🗜 Zmenšit a komprimovat</button>' : ''}
      <button class="btn sm" id="bTags" title="Přidat nebo odebrat tagy u vybraných médií">🏷 Tagy</button>
      <button class="btn sm" id="bArch" title="Média se přestanou nabízet, ale dál hrají tam, kde jsou použitá">🗄 Do archivu</button>
      <button class="btn sm danger" id="bDel">Smazat</button>
      <button class="btn sm ghost" id="bAll">Vybrat vše zobrazené</button>
      <button class="btn sm ghost" id="bNone">Zrušit výběr</button>`;
    $('#bArch').onclick = (e) => archiveIds([...gSel], true, e.currentTarget);
    $('#bTags').onclick = () => bulkTags([...gSel]);
    $('#bAll').onclick = () => { visible().forEach((m) => gSel.add(m.id)); render(); };
    $('#bNone').onclick = () => { gSel.clear(); render(); };
    $('#bPack')?.addEventListener('click', (e) => {
      const ids = [...gSel].filter((id) => mediaById(id)?.kind === 'image');
      busy(e.currentTarget, 'Komprimuji…', async () => {
        try { const r = await api('media_compress', { ids }); packResultToast(r); gSel.clear(); route(); } catch (err) { toast(err.message, 'error'); }
      });
    });
    $('#bDel').onclick = (e) => {
      const used = [...gSel].map(mediaById).filter((m) => m?.used).length;
      if (!confirm(`Smazat ${gSel.size} položek?${used ? `\n${used} z nich se používá ve scénářích nebo náhodných výběrech – budou z nich odebrány.` : ''}`)) return;
      busy(e.currentTarget, 'Mažu…', async () => {
        try { const r = await api('media_delete', { ids: [...gSel] }); toast(`Smazáno: ${r.deleted}`); gSel.clear(); route(); } catch (err) { toast(err.message, 'error'); }
      });
    };
  };

  const render = () => {
    $('#gTypes').innerHTML = [['all', 'Vše'], ['slide', 'Slidy'], ['image', 'Obrázky'], ['video', 'Videa'], ['pdf', 'PDF'], ['text', 'Texty'], ['url', 'Odkazy'], ['html', 'HTML'], ['random', '🎲 Náhodné'], ['dated', '📅 Podle data'], ['unused', 'Nepoužité']]
      .filter(([k]) => !inArchive() || k !== 'unused')
      .map(([k, l]) => `<button class="chip ${type === k ? 'on' : ''}" data-t="${k}">${l}</button>`).join('');
    $$('#gTypes [data-t]').forEach((b) => b.onclick = () => { type = b.dataset.t; render(); });
    const items = visible();
    $('#gGrid').innerHTML = items.length ? items.map((m, i) => `
      <div class="card ${gSel.has(m.id) ? 'chosen' : ''}" data-id="${m.id}" data-i="${i}" draggable="${!isTouch}">
        <label class="selbox" title="Označit"><input type="checkbox" data-sel="${m.id}" ${gSel.has(m.id) ? 'checked' : ''}></label>
        <div class="thumb" data-prev="${m.id}">${thumb(m)}<span class="kind">${KIND[m.kind]}</span>${m.grp ? `<span class="tag">${esc(m.grp)}</span>` : ''}</div>
        <div class="meta">
          <span class="name" title="${esc(m.original_name)}">${esc(m.original_name)}</span>
          ${tagsHtml(m, true)}
          <span class="muted">${mediaInfo(m)} · ${m.used ? `použito ${m.used}×` : 'nepoužito'}</span>
          ${inArchive() ? `<span class="muted small" data-usedin="${m.id}">${usedInText(m)}</span>` : ''}
        </div>
        <div class="actions">
          ${inArchive() ? `${cardBtn('data-unarch', m.id, 'Vrátit z archivu – médium se zase začne nabízet', 'unarch')}${cardBtn('data-del', m.id, 'Smazat trvale', 'del', 'danger')}`
          : `${cardBtn('data-edit', m.id, 'Upravit', 'edit')}${cardBtn('data-arch', m.id, 'Do archivu – přestane se nabízet, ale dál hraje tam, kde je použité', 'arch')}${cardBtn('data-del', m.id, 'Smazat', 'del', 'danger')}`}
        </div>
      </div>`).join('') : `<div class="empty" style="grid-column:1/-1">${store.media.length ? 'Nic nenalezeno.' : 'Galerie je prázdná.'}</div>`;
    if (inArchive() && !items.length && !store.archive.length) $('#gGrid').innerHTML = '<div class="empty" style="grid-column:1/-1">Archiv je prázdný. Média, která už nechcete nabízet, sem přesunete tlačítkem <b>Do archivu</b>.</div>';
    $$('#gGrid [data-arch]').forEach((b) => b.onclick = () => archiveIds([+b.dataset.arch], true, b));
    $$('#gGrid [data-unarch]').forEach((b) => b.onclick = () => archiveIds([+b.dataset.unarch], false, b));
    $$('#gGrid [data-prev]').forEach((el) => el.onclick = () => preview(mediaById(+el.dataset.prev), items));
    // rychlý filtr: klik na tag na kartě ukáže jen média s tímto tagem, druhý klik filtr zruší
    $$('#gGrid [data-qtag]').forEach((b) => b.onclick = (e) => {
      e.stopPropagation();
      const k = b.dataset.qtag, only = !gTagSt.none && gTagSt.sel.size === 1 && gTagSt.sel.has(k);
      gTagSt.sel.clear(); gTagSt.none = false;
      if (!only) gTagSt.sel.add(k);
      gSel.clear(); drawTags(); render();
    });
    $$('#gGrid [data-edit]').forEach((b) => b.onclick = () => editAny(mediaById(+b.dataset.edit)));
    $$('#gGrid [data-sel]').forEach((cb) => cb.onclick = (e) => {
      const id = +cb.dataset.sel, i = +cb.closest('.card').dataset.i;
      if (e.shiftKey && lastIdx !== null) items.slice(Math.min(lastIdx, i), Math.max(lastIdx, i) + 1).forEach((m) => gSel.add(m.id));
      else cb.checked ? gSel.add(id) : gSel.delete(id);
      lastIdx = i;
      render();
    });
    $$('#gGrid .card').forEach((c) => c.ondragstart = (e) => {
      const id = +c.dataset.id;
      const ids = gSel.has(id) ? [...gSel] : [id];
      e.dataTransfer.setData('application/x-mp-move', JSON.stringify(ids));
      if (ids.length > 1) {
        const g = document.createElement('div'); g.className = 'drag-ghost'; g.textContent = `${ids.length} položek`;
        document.body.append(g); e.dataTransfer.setDragImage(g, 20, 20); setTimeout(() => g.remove(), 0);
      }
    });
    $$('#gGrid [data-del]').forEach((b) => b.onclick = async () => {
      const m = mediaById(+b.dataset.del);
      if (!confirm(`${inArchive() ? 'Trvale smazat' : 'Smazat'} „${m.original_name}“?${m.used ? '\nPoužívá se ve scénářích nebo náhodných výběrech – bude z nich odebráno.' : ''}`)) return;
      try { await busy(b, 'Mažu…', () => api('media_delete', { id: m.id })); toast(`„${m.original_name}“ smazáno.`); route(); } catch (e) { toast(e.message, 'error'); }
    });
    drawBulk();
  };

  $('#newSlide').onclick = () => editSlide(null);
  $('#newText').onclick = () => editText(null);
  $('#newUrl').onclick = () => editUrl(null);
  $('#newPool').onclick = () => editPool(null);
  $('#newDated').onclick = () => editDated(null);
  $('#newHtml').onclick = () => editHtml(null);

  const drop = $('#drop'), fileIn = $('#fileIn');
  drop.onclick = (e) => { if (e.target === drop || e.target.closest('.drop-txt')) fileIn.click(); };
  drop.ondragover = (e) => { if ([...e.dataTransfer.types].includes('Files')) { e.preventDefault(); drop.classList.add('over'); } };
  drop.ondragleave = () => drop.classList.remove('over');
  drop.ondrop = (e) => { e.preventDefault(); drop.classList.remove('over'); upload([...e.dataTransfer.files]); };
  $('#upTile').onclick = () => fileIn.click();
  fileIn.onchange = () => upload([...fileIn.files]);
  $('#gQ').oninput = (e) => { q = e.target.value.toLowerCase(); render(); };

  // nahrávání po částech (obejde limit velikosti uploadu na hostingu)
  async function upload(files) {
    if (!files.length) return;
    const total = files.reduce((a, f) => a + f.size, 0) || 1;
    let done = 0, ok = 0, packed = 0;
    const prog = $('#prog'), upStatus = $('#upStatus');   // při odchodu ze stránky zůstanou odpojené – zápis do nich nevadí
    prog.hidden = false;
    const tags = currentTags();
    for (const f of files) {
      upStatus.textContent = `Nahrávám ${f.name}…`;
      try {
        const r = await uploadOne(f, tags.length ? { tags: JSON.stringify(tags) } : {}, (n) => { done += n; prog.firstElementChild.style.width = (done / total * 100) + '%'; });
        ok++;
        if (r?.compressed) packed++;
        else if (r?.note) toast(`${f.name}: ${r.note}`, 'warn');
      } catch (e) { toast(`${f.name}: ${e.message}`, 'error'); }
    }
    prog.hidden = true;
    upStatus.textContent = '';
    if (ok) toast(`Nahráno souborů: ${ok}${packed ? ` · zkomprimováno obrázků: ${packed}` : ''}`);
    if (upStatus.isConnected) route();   // jinak by se překreslila stránka, na kterou uživatel mezitím přešel
  }

  drawTags();
  render();
}

// ================= Data =================
let dataGroup = 'all';
const fmtDataValue = (v) => {
  const n = Number(String(v).replace(/\s/g, '').replace(',', '.'));
  if (v === '' || isNaN(n)) return '';
  if (Math.abs(n) >= 1e9) return (n / 1e9).toLocaleString('cs-CZ', { maximumFractionDigits: 2 }) + ' mld.';
  if (Math.abs(n) >= 1e6) return (n / 1e6).toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) + ' mil.';
  return n.toLocaleString('cs-CZ');
};
function slideNote(r) { return r.slides?.length ? ` Přegenerováno slidů: ${r.slides.length} (na obrazovku do 5 min).` : ''; }

async function viewData() {
  let rows = await api('data_list');
  let q = '';
  const groups = () => [...new Set(rows.map((r) => r.grp))].sort((a, b) => a.localeCompare(b, 'cs'));
  main.innerHTML = `
    <div class="head">
      <div><h1>Data ${helpBtn('@data')}</h1><p class="sub">Hodnoty pro slidy ve tvaru <b>Skupina | Parametr | Hodnota</b>. Parametr může být datum (losování) nebo libovolný text (např. položka ceníku).</p></div>
      <div class="row">
        <button class="btn" id="dPast"></button>
        <button class="btn" id="dImport">Vložit z Excelu</button>
        <button class="btn primary" id="dAdd">+ Řádek</button>
      </div>
    </div>
    <div class="panel src-panel" id="srcPanel"></div>
    <h3 style="margin:18px 0 8px">Vlastní data</h3>
    <div class="row" style="margin-bottom:12px"><div class="chips grow" id="dGroups"></div><input type="search" id="dQ" placeholder="Hledat…" style="max-width:220px"></div>
    <div id="dHint"></div>
    <div class="panel" style="padding:0;overflow:auto"><table class="dtable"><thead><tr><th>Skupina</th><th>Parametr</th><th>Hodnota</th><th></th></tr></thead><tbody id="dBody"></tbody></table></div>
    <datalist id="dGrpList"></datalist>`;

  const sortRows = (a, b) => a.grp.localeCompare(b.grp, 'cs') || ((a.date || '9') < (b.date || '9') ? -1 : (a.date || '9') > (b.date || '9') ? 1 : 0)
    || a.param.localeCompare(b.param, 'cs', { numeric: true });
  const reload = async () => { rows = await api('data_list'); draw(); };

  const draw = () => {
    const gs = groups();
    if (dataGroup !== 'all' && !gs.includes(dataGroup)) dataGroup = 'all';
    $('#dGroups').innerHTML = [['all', `Vše ${rows.length}`], ...gs.map((g) => [g, `${g} ${rows.filter((r) => r.grp === g).length}`])]
      .map(([k, l]) => `<button class="chip ${dataGroup === k ? 'on' : ''}" data-g="${esc(k)}">${esc(l)}</button>`).join('');
    $$('#dGroups [data-g]').forEach((b) => b.onclick = () => { dataGroup = b.dataset.g; draw(); });
    $('#dGrpList').innerHTML = gs.map((g) => `<option value="${esc(g)}">`).join('');
    const past = rows.filter((r) => r.past).length;
    $('#dPast').textContent = `Smazat prošlé (${past})`;
    $('#dPast').disabled = !past;
    const list = rows.filter((r) => (dataGroup === 'all' || r.grp === dataGroup) && `${r.grp} ${r.param} ${r.value}`.toLowerCase().includes(q)).sort(sortRows);
    $('#dBody').innerHTML = list.length ? list.map((r) => `<tr class="${r.past ? 'past' : ''}" data-id="${r.id}">
        <td><input type="text" data-k="grp" value="${esc(r.grp)}" list="dGrpList"></td>
        <td><input type="text" data-k="param" value="${esc(r.param)}">${r.past ? '<span class="tag past-tag">prošlé</span>' : ''}</td>
        <td><input type="text" data-k="value" value="${esc(r.value)}"><span class="hint small fmtv">${esc(fmtDataValue(r.value))}</span></td>
        <td><button class="x" data-del="${r.id}" title="Smazat řádek">×</button></td></tr>`).join('')
      : `<tr><td colspan="4"><div class="empty small" style="margin:12px">${rows.length ? 'Nic nenalezeno.' : 'Zatím žádná data. Přidejte řádek nebo vložte data z Excelu.'}</div></td></tr>`;
    // uložení po úpravě buňky
    $$('#dBody tr[data-id]').forEach((tr) => tr.querySelectorAll('input').forEach((inp) => {
      inp.onchange = async () => {
        const r = rows.find((x) => x.id === +tr.dataset.id);
        const row = { id: r.id, grp: tr.querySelector('[data-k=grp]').value, param: tr.querySelector('[data-k=param]').value, value: tr.querySelector('[data-k=value]').value };
        try {
          tr.classList.add('saving');
          const res = await api('data_save', row);
          toast('Uloženo.' + slideNote(res));
          await reload();
        } catch (e) { toast(e.message, 'error'); tr.classList.remove('saving'); }
      };
      inp.onkeydown = (ev) => { if (ev.key === 'Enter') inp.blur(); };
    }));
    $$('#dBody [data-del]').forEach((b) => b.onclick = async () => {
      try { const res = await api('data_delete', { ids: [+b.dataset.del] }); toast('Řádek smazán.' + slideNote(res)); await reload(); }
      catch (e) { toast(e.message, 'error'); }
    });
    drawHint();
  };

  // nápověda pro vybranou skupinu: aktuální hodnoty a jak je vložit do slidu
  const drawHint = async () => {
    const box = $('#dHint');
    if (dataGroup === 'all') { box.innerHTML = ''; return; }
    const g = dataGroup;
    const dated = rows.some((r) => r.grp === g && r.date);
    if (!dated) {
      box.innerHTML = `<div class="dhint">Ve slidu: <code>{${esc(g)}:<i>parametr</i>}</code>, např. <code>{${esc(g)}:${esc(rows.find((r) => r.grp === g)?.param || '')}}</code></div>`;
      return;
    }
    const p = await api('data_preview', { grp: g });
    if (dataGroup !== g) return;
    const nd = p.nearestParam ? p.nearestParam : '—';
    box.innerHTML = `<div class="dhint">
      <span>Nejbližší: <b>${esc(fmtDataValue(p.nearest) || p.nearest || '—')}</b> (${esc(nd)})</span>
      <span>Poslední prošlý: <b>${esc(fmtDataValue(p.last) || p.last || '—')}</b></span>
      <span class="grow"></span>
      ${[`{${g}.nejbližší|mil}`, `{${g}.nejbližší.parametr|datum:den}`].map((v) => `<button class="btn sm ghost copyv" data-v="${esc(v)}" title="Zkopírovat">${esc(v)} ⧉</button>`).join('')}
    </div>`;
    $$('.copyv').forEach((b) => b.onclick = async () => { try { await navigator.clipboard.writeText(b.dataset.v); toast('Zkopírováno – vložte do textu slidu.'); } catch { toast(b.dataset.v); } });
  };

  // ---------- vzdálené zdroje ----------
  const drawSources = async () => {
    const box = $('#srcPanel');
    let list;
    try { list = await api('sources'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    if (!box.isConnected) return;   // mezitím jiná stránka
    box.innerHTML = `<div class="row" style="margin-bottom:8px"><h3 class="grow" style="margin:0">Vzdálené zdroje</h3><button class="btn sm" id="srcAdd">+ Zdroj</button></div>
      <p class="hint small" style="margin:0 0 10px">Data (JSON nebo CSV) stažená z jiné adresy, např. výsledky losování nebo ceník z tabulky Google. Stahují se automaticky; ve slidu je vložíte tlačítkem <b>Ze zdroje…</b>.</p>
      ${list.length ? list.map((x) => `<div class="src-row" data-id="${x.id}">
        <div class="grow"><b>${esc(x.name)}</b> <span class="tag">${x.format === 'csv' ? 'CSV' : 'JSON'}</span> <span class="hint small">každých ${x.interval_min} min${x.rows !== null && x.rows !== undefined ? ` · ${x.rows} ${x.rows === 1 ? 'řádek' : x.rows >= 2 && x.rows <= 4 ? 'řádky' : 'řádků'}` : ''}</span>
          <div class="small src-url">${esc(x.url)}</div>
          <div class="small">${x.error ? `<span class="tag err">chyba</span> ${esc(x.error)}${x.ok_at ? ` <span class="hint">– slidy používají data z ${esc(fmtTime(x.ok_at))}</span>` : ''}`
            : x.ok_at ? `<span class="tag ok">v pořádku</span> <span class="hint">staženo ${esc(fmtTime(x.ok_at))}</span>` : '<span class="hint">zatím nestaženo</span>'}</div></div>
        <div class="row" style="gap:6px"><button class="btn sm" data-sf="${x.id}">Stáhnout teď</button><button class="btn sm" data-sv="${x.id}" ${x.has_data ? '' : 'disabled'}>Prohlédnout</button>
          <button class="btn sm ghost" data-se="${x.id}">Upravit</button><button class="x" data-sd="${x.id}" title="Smazat zdroj">×</button></div></div>`).join('')
        : '<div class="empty small">Žádný zdroj.</div>'}`;
    $('#srcAdd').onclick = () => editSource(null);
    $$('[data-se]').forEach((b) => b.onclick = () => editSource(list.find((x) => x.id === +b.dataset.se)));
    $$('[data-sf]').forEach((b) => b.onclick = () => busy(b, 'Stahuji…', async () => {
      try {
        const r = await api('source_fetch', { id: +b.dataset.sf });
        r.ok ? toast((r.changed ? 'Staženo, data se změnila.' : 'Staženo, data beze změny.') + slideNote(r)) : toast('Stažení selhalo: ' + r.error, 'error');
      } catch (e) { toast(e.message, 'error'); }
      drawSources();
    }));
    $$('[data-sv]').forEach((b) => b.onclick = async () => {
      try { SOURCES = await api('source_tree'); } catch (e) { toast(e.message, 'error'); return; }
      pickSource('browse', async (v) => { try { await navigator.clipboard.writeText(v); toast(`Zkopírováno: ${v}`); } catch { toast(v); } }, +b.dataset.sv);
    });
    $$('[data-sd]').forEach((b) => b.onclick = async () => {
      const x = list.find((y) => y.id === +b.dataset.sd);
      if (!confirm(`Smazat zdroj „${x.name}“? Slidy, které ho používají, ukážou proměnné jako neznámé.`)) return;
      try { await api('source_delete', { id: x.id }); toast('Zdroj smazán.'); drawSources(); } catch (e) { toast(e.message, 'error'); }
    });
  };
  const editSource = (x) => {
    openModal(`<h2>${x ? 'Upravit zdroj' : 'Nový zdroj'} ${helpBtn('jak-nastavit-vzdaleny-zdroj', true)}</h2><div class="stack">
      <div class="cols3"><label class="field">Název <span class="hint">použije se ve slidu</span><input type="text" id="sN" value="${esc(x?.name || '')}" placeholder="Allwyn"></label>
      <label class="field" style="grid-column:span 2">Adresa<input type="url" id="sU" value="${esc(x?.url || '')}" placeholder="https://…"></label></div>
      <div class="cols3"><div class="field">Formát ${`<div class="seg" id="sF"><button data-v="json">JSON</button><button data-v="csv">CSV</button></div>`}</div>
        <label class="field">Stahovat každých (minut)<input type="number" id="sI" value="${x?.interval_min || 15}" min="5" max="1440"></label><div></div></div>
      <div id="sCsv" class="panel-sub small stack" style="gap:8px">
        <label class="check-row"><input type="checkbox" id="sH" ${x?.csv_header === false ? '' : 'checked'}><span>První řádek obsahuje názvy sloupců</span></label>
        <div>Oddělovač (<code>;</code> <code>,</code> tabulátor) i kódování (UTF-8, Windows-1250 z Excelu) se poznají samy.
          <b>Tabulka Google:</b> stačí vložit její běžný odkaz – musí být sdílená „Kdokoli s odkazem“; stáhne se list z odkazu.</div>
        <div>Ve slidu: <code>{Ceník:Káva.Cena}</code> – řádek podle 1. sloupce, sloupec podle záhlaví; <code>{Ceník:3.Cena}</code> – 3. řádek dat. Bez záhlaví se sloupce číslují: <code>{Ceník:Káva.2}</code>.</div>
      </div>
      <p class="hint small" style="margin:0">Cron běží každých 5 minut, kratší interval nemá smysl. Po uložení se zdroj hned zkusí stáhnout.</p>
      <div class="errbox" id="sErr" hidden></div>
      <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="sSave">Uložit</button></div></div>`);
    $('#sN').focus();
    let fmt = x?.format || 'json';
    const drawFmt = () => {
      $$('#sF button').forEach((b) => b.classList.toggle('on', b.dataset.v === fmt));
      $('#sCsv').hidden = fmt !== 'csv';
    };
    $$('#sF button').forEach((b) => b.onclick = () => { fmt = b.dataset.v; drawFmt(); });
    // odkaz na tabulku Google nebo .csv → rovnou přepnout na CSV
    $('#sU').oninput = () => { if (/docs\.google\.com\/spreadsheets|\.csv(\?|$)|format=csv|output=csv/i.test($('#sU').value) && fmt !== 'csv') { fmt = 'csv'; drawFmt(); } };
    drawFmt();
    $('#sSave').onclick = (ev) => busy(ev.currentTarget, 'Ukládám…', async () => {
      $('#sErr').hidden = true;
      try {
        const r = await api('source_save', { id: x?.id || 0, name: $('#sN').value, url: $('#sU').value, interval_min: +$('#sI').value, format: fmt, csv_header: $('#sH').checked });
        closeModal();
        r.ok ? toast('Zdroj uložen a stažen.' + slideNote(r)) : toast('Zdroj uložen, ale stažení selhalo: ' + r.error, 'warn');
        drawSources();
      } catch (e) { $('#sErr').textContent = e.message; $('#sErr').hidden = false; }
    });
  };
  drawSources();

  $('#dQ').oninput = (e) => { q = e.target.value.toLowerCase(); draw(); };
  $('#dAdd').onclick = () => {
    openModal(`<h2>Nový řádek</h2><div class="stack">
      <div class="cols3"><label class="field">Skupina<input type="text" id="nG" list="dGrpList" value="${dataGroup !== 'all' ? esc(dataGroup) : ''}" placeholder="Sportka"></label>
      <label class="field">Parametr <span class="hint">datum nebo text</span><input type="text" id="nP" placeholder="26.11.2026"></label>
      <label class="field">Hodnota<input type="text" id="nV" placeholder="125 000 000"></label></div>
      <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn" id="nMore">Uložit a další</button><button class="btn primary" id="nSave">Uložit</button></div></div>`);
    (dataGroup !== 'all' ? $('#nP') : $('#nG')).focus();
    const save = async (again) => {
      try {
        const res = await api('data_save', { grp: $('#nG').value, param: $('#nP').value, value: $('#nV').value });
        toast('Řádek přidán.' + slideNote(res));
        rows = await api('data_list'); draw();
        if (again) { $('#nP').value = ''; $('#nV').value = ''; $('#nP').focus(); } else closeModal();
      } catch (e) { toast(e.message, 'error'); }
    };
    $('#nSave').onclick = () => save(false);
    $('#nMore').onclick = () => save(true);
    $('#nV').onkeydown = (ev) => { if (ev.key === 'Enter') save(true); };
  };
  $('#dImport').onclick = () => {
    openModal(`<h2>Vložit z Excelu</h2><div class="stack">
      <p class="hint small" style="margin:0">V Excelu označte tři sloupce <b>Skupina, Parametr, Hodnota</b> (bez nebo se záhlavím), zkopírujte (Ctrl+C) a vložte sem (Ctrl+V). Sloupce mohou být oddělené i středníkem.</p>
      <textarea id="iText" rows="10" placeholder="Sportka&#9;26.11.2026&#9;125000000&#10;Sportka&#9;28.11.2026&#9;131000000&#10;Eurojackpot&#9;27.11.2026&#9;1256000000"></textarea>
      <div class="hint small" id="iInfo"></div>
      <label class="check-row"><input type="checkbox" id="iReplace"><span>Nahradit stávající řádky ve skupinách, které vkládám (jinak se jen přidají)</span></label>
      <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="iGo">Vložit</button></div></div>`);
    $('#iText').oninput = () => {
      const lines = $('#iText').value.split(/\r?\n/).filter((l) => l.trim());
      const ok = lines.filter((l) => l.split(/\t|;|\|/).length >= 3).length;
      $('#iInfo').textContent = lines.length ? `Rozpoznáno řádků: ${ok} z ${lines.length}` : '';
    };
    $('#iGo').onclick = (ev) => busy(ev.currentTarget, 'Vkládám…', async () => {
      try {
        const res = await api('data_import', { text: $('#iText').value, replace: $('#iReplace').checked });
        closeModal();
        toast(`Vloženo řádků: ${res.imported}.` + (res.errors.length ? ` Přeskočeno: ${res.errors.length}.` : '') + slideNote(res), res.errors.length ? 'warn' : 'ok');
        await reload();
      } catch (e) { toast(e.message, 'error'); }
    });
  };
  $('#dPast').onclick = async () => {
    if (!confirm('Smazat všechny řádky s datem v minulosti?')) return;
    try { const res = await api('data_delete_past', {}); toast(`Smazáno prošlých řádků: ${res.deleted}.` + slideNote(res)); await reload(); }
    catch (e) { toast(e.message, 'error'); }
  };
  draw();
}

// ================= Protokol =================
async function viewLogs() {
  const draw = async (passive = false) => {
    const logs = await api('logs', undefined, '', passive);
    main.innerHTML = `<div class="head"><div><h1>Protokol ${helpBtn('@protokol')}</h1><p class="sub">Změny na Google Disku, chyby a úpravy.</p></div></div>
      <div class="panel logs">${logs.length ? logs.map((l) => `<div class="log ${l.level}"><time>${new Date(l.at.replace(' ', 'T')).toLocaleString('cs-CZ')}</time><span class="msg">${esc(l.message)}</span></div>`).join('')
        : '<div class="muted">Zatím žádné záznamy.</div>'}</div>`;
  };
  await draw();
  timer = setInterval(() => draw(true), 30000);
}

// ================= Nastavení =================
const SET_TABS = [['disk', 'Google Disk'], ['obrazky', 'Obrázky'], ['tagy', 'Tagy'], ['pocasi', 'Počasí'], ['prehravac', 'Přehrávač'], ['zarizeni', 'Zařízení'], ['upozorneni', 'Upozornění'], ['zalohy', 'Zálohy'], ['zabezpeceni', 'Zabezpečení'], ['system', 'Systém']];

async function viewSettings() {
  const st = await api('settings');
  const flag = new URLSearchParams(location.hash.split('?')[1] || '').get('google');
  if (flag === 'ok') toast('Google Disk připojen.');
  const onSecurity = location.hash.startsWith('#nastaveni/zabezpeceni');
  if (flag === 'linked') toast('Google účet pro přihlášení je propojený.');
  if (flag === 'error') toast(onSecurity ? 'Propojení Google účtu se nezdařilo – podrobnosti v Protokolu.' : 'Připojení Google Disku se nezdařilo – podrobnosti v Protokolu.', 'error');
  let tab = location.hash.slice(1).split('?')[0].split('/')[1] || 'disk';
  if (!SET_TABS.some(([k]) => k === tab)) tab = 'disk';
  main.innerHTML = `
    <div class="head"><div><h1>Nastavení <a class="help-q" id="setHelp" href="#napoveda/@nastaveni-google-disk" title="Nápověda k této části" aria-label="Nápověda">?</a></h1></div></div>
    <div class="tabs" id="setTabs" role="tablist">${SET_TABS.map(([k, l]) => `<button role="tab" data-tab="${k}">${l}</button>`).join('')}</div>
    <div id="setBody" class="stack" style="max-width:760px"></div>`;
  let dirty = false;
  const show = (k) => {
    if (dirty && k !== tab && !confirm('Na této kartě jsou neuložené změny. Zahodit je?')) return;
    dirty = false;
    tab = k;
    history.replaceState(null, '', k === 'disk' ? '#nastaveni' : `#nastaveni/${k}`);
    $$('#setTabs [data-tab]').forEach((b) => { b.classList.toggle('on', b.dataset.tab === k); b.setAttribute('aria-selected', b.dataset.tab === k); });
    $('#setHelp').href = '#napoveda/@' + ({ disk: 'nastaveni-google-disk', obrazky: 'nastaveni-obrazky', tagy: 'nastaveni-tagy', pocasi: 'nastaveni-pocasi', prehravac: 'nastaveni-prehravac', zarizeni: 'nastaveni-zarizeni', upozorneni: 'nastaveni-upozorneni', zalohy: 'nastaveni-zalohy', zabezpeceni: 'nastaveni-zabezpeceni', system: 'nastaveni-system' }[k] || 'nastaveni');
    const box = $('#setBody');
    SET_RENDER[k](st, box);
    box.oninput = box.onchange = (e) => { if (e.target.matches('input:not([readonly]), select, textarea:not([readonly])') && !e.target.closest('[data-nodirty]')) dirty = true; };
  };
  window.settingsSaved = () => { dirty = false; };
  $$('#setTabs [data-tab]').forEach((b) => { b.onclick = () => show(b.dataset.tab); });
  show(tab);
}

/** Obnovení zálohy: heslo → náhled obsahu → potvrzení. */
function restoreDialog(name, passwordSet) {
  openModal(`<h2>Obnovit zálohu ${helpBtn('jak-zalohovat-a-obnovit', true)}</h2><div class="stack">
    <p class="small" style="margin:0"><code>${esc(name)}</code></p>
    <label class="field">Heslo zálohy <span class="hint">${passwordSet ? 'prázdné = aktuální heslo záloh' : ''}</span><input type="password" id="rPass" autocomplete="off"></label>
    <div id="rInfo"></div>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn" id="rCheck">Ověřit zálohu</button><button class="btn danger" id="rGo" disabled>Obnovit</button></div></div>`);
  $('#rPass').focus();
  $('#rCheck').onclick = (e) => busy(e.currentTarget, 'Ověřuji…', async () => {
    try {
      const i = await api('backup_info', { name, password: $('#rPass').value });
      $('#rInfo').innerHTML = `<div class="panel-sub small">Záloha z <b>${i.created_at ? fmtDT(i.created_at) : '?'}</b> (verze ${esc(i.app_version)}):
        ${i.media} položek galerie, ${i.scenarios} scénářů, ${i.data_rows} řádků dat; soubory galerie ${i.with_files ? 'jsou v záloze' : 'se doplní z poslední zálohy se soubory nebo ze serveru'}.
        <div class="warnbox" style="margin-top:8px">Obnovení nahradí <b>všechna</b> současná data (scénáře, galerii, slidy, data, nastavení i heslo do aplikace) stavem ze zálohy.
          Současný stav se předtím automaticky zazálohuje („před obnovením“), takže jde vrátit.</div></div>`;
      $('#rGo').disabled = false;
    } catch (err) { $('#rInfo').innerHTML = `<div class="errbox">${esc(err.message)}</div>`; $('#rGo').disabled = true; }
  });
  $('#rGo').onclick = (e) => {
    if (!confirm('Opravdu obnovit zálohu? Současná data budou nahrazena.')) return;
    busy(e.currentTarget, 'Obnovuji…', async () => {
      try {
        const r = await api('backup_restore', { name, password: $('#rPass').value });
        closeModal();
        toast(`Záloha obnovena. Pojistná záloha předchozího stavu: ${r.safety}.${r.missing.length ? ` Chybí soubory: ${r.missing.length} (podrobnosti v Protokolu).` : ''}`, r.missing.length ? 'warn' : 'ok');
        route();
      } catch (err) { $('#rInfo').innerHTML = `<div class="errbox">${esc(err.message)}</div>`; }
    });
  };
}

/** Uloží jednu kartu Nastavení a znovu ji načte. */
function saveTab(btn, body, msg = 'Nastavení uloženo.') {
  return busy(btn, 'Ukládám…', async () => {
    try { await api('settings_save', body); window.settingsSaved?.(); toast(msg); route(); } catch (err) { toast(err.message, 'error'); }
  });
}
const saveRow = (extra = '') => `<div class="row" style="margin-top:4px"><button class="btn primary" id="tSave">Uložit</button>${extra}</div>`;

/** Kdy se zařízení naposledy ozvalo: „před 3 min“, jinak datum a čas. */
function seenText(s, offline) {
  if (!s) return { txt: 'zatím se neozvalo', cls: 'muted' };
  const min = Math.round((Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 60000);
  if (offline) return { txt: `neozývá se od ${fmtTime(s)}`, cls: 'err' };
  if (min < 1) return { txt: 'právě teď', cls: 'ok' };
  return { txt: `před ${min} min`, cls: 'ok' };
}
const hhmm = (s) => s ? new Date(String(s).replace(' ', 'T')).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '';

/** Stav zařízení pod jeho názvem: co hraje, soubory, verze přehrávače, poslední chyba. */
function deviceStatusHtml(d) {
  if (!d.last_seen) return '';
  if (!d.controllable) return '<span class="muted">Stará verze přehrávače – ovládání nefunguje. Nainstalujte novou verzi.</span>';
  const st = d.status || {};
  const lines = [];
  if (!d.playing || d.offline) lines.push(`<span class="muted">${d.kind === 'chrome' && !d.offline ? 'Přehrávač není otevřený' : 'Nepřehrává'}</span>`);
  else if (st.playing) lines.push(`<span>▶ <b>${esc(st.playing.name)}</b> – od ${esc(hhmm(st.playing.since))}${st.playing.paused ? ' · pozastaveno' : ''}</span>`);
  else lines.push('<span class="muted">Čeká na soubory</span>');
  const parts = [`${st.files} ${plural(st.files, 'soubor', 'soubory', 'souborů')}, staženo ${st.done}`];
  if (st.version) parts.push(`přehrávač ${esc(st.version)}`);
  lines.push(`<span class="muted">${parts.join(' · ')}</span>`);
  if (st.error) lines.push(`<span class="dev-err">⚠ ${esc(hhmm(st.error.at))} ${esc(st.error.msg)}</span>`);
  return lines.join('');
}

// ---------- Upozornění: zařízení se neozývá (pruh nad stránkou, kontrola každou minutu) ----------
async function checkDevAlert() {
  const box = $('#devAlert');
  if (!box) return;
  let r;
  try { r = await api('devices_alert', undefined, '', true); } catch { return; }
  box.hidden = !r.offline.length;
  box.innerHTML = r.offline.map((d) => `⚠ Zařízení <b>${esc(d.name)}</b> se neozývá od ${esc(fmtTime(d.last_seen))}.`).join('<br>')
    + (r.offline.length ? ' <a href="#nastaveni/zarizeni">Nastavení → Zařízení</a>' : '');
}
setInterval(checkDevAlert, 60000);

/** Okno s klíčem zařízení (ukáže se jen jednou). */
function deviceKeyDialog(dev, token, baseUrl, fresh) {
  openModal(`<h2>${fresh ? 'Nové zařízení' : 'Nový klíč'} „${esc(dev.name)}“ ${helpBtn('jak-nainstalovat-plugin-chrome', true)}</h2><div class="stack">
    <p class="small" style="margin:0">Klíč se zobrazí <b>jen teď</b>. Vložte ho do nastavení pluginu spolu s adresou aplikace.${fresh ? '' : ' Starý klíč přestal fungovat.'}</p>
    <label class="field">Adresa aplikace<div class="row" style="gap:8px"><input type="text" readonly id="dkBase" value="${esc(baseUrl)}" class="grow"><button class="btn" data-copy="#dkBase">Kopírovat</button></div></label>
    <label class="field">Klíč zařízení<div class="row" style="gap:8px"><input type="text" readonly id="dkKey" value="${esc(token)}" class="grow mono"><button class="btn" data-copy="#dkKey">Kopírovat</button></div></label>
    <div class="row"><span class="grow"></span><button class="btn primary" data-close>Hotovo</button></div></div>`);
  $$('#modalBox [data-copy]').forEach((b) => { b.onclick = async () => {
    const v = $(b.dataset.copy).value;
    try { await navigator.clipboard.writeText(v); toast('Zkopírováno.'); } catch { $(b.dataset.copy).select(); }
  }; });
}

/** Okno Přidat / Upravit zařízení. */
function deviceDialog(dev, info, done) {
  const kinds = Object.entries(info.kinds).map(([k, l]) => `<option value="${k}" ${(dev?.kind || 'chrome') === k ? 'selected' : ''}>${esc(l)}</option>`).join('');
  openModal(`<h2>${dev ? 'Upravit zařízení' : 'Přidat zařízení'} ${helpBtn('@nastaveni-zarizeni', true)}</h2><div class="stack">
    <label class="field">Název <span class="hint">např. Počítač v kanceláři</span><input type="text" id="dvName" maxlength="100" value="${esc(dev?.name || '')}"></label>
    <label class="field">Typ<select id="dvKind">${kinds}</select></label>
    <label class="field">Délka zobrazení (s) <span class="hint">prázdné = výchozí (${info.duration} s), 3 až 3600</span>
      <input type="number" id="dvDur" min="3" max="3600" step="1" value="${dev?.duration ?? ''}" placeholder="${info.duration}" style="max-width:140px"></label>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="dvSave">${dev ? 'Uložit' : 'Přidat'}</button></div></div>`);
  $('#dvName').focus();
  $('#dvSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
    try {
      const r = await api('device_save', { id: dev?.id || 0, name: $('#dvName').value, kind: $('#dvKind').value, duration: $('#dvDur').value === '' ? null : $('#dvDur').value });
      closeModal();
      if (r.token) deviceKeyDialog(r.device, r.token, info.base_url, true); else toast('Zařízení uloženo.');
      done();
    } catch (err) { toast(err.message, 'error'); }
  });
}

/** Okno Spárovat televizi: kód z obrazovky televize + název. */
function pairDialog(info, done) {
  openModal(`<h2>Spárovat televizi ${helpBtn('jak-nainstalovat-aplikaci-tv', true)}</h2><div class="stack">
    <p class="small muted" style="margin:0">Na televizi (Samsung nebo Android TV) spusťte aplikaci <b>TWB přehrávač</b>. Ukáže šestimístný kód, který platí 10 minut.</p>
    <label class="field">Kód z televize<input type="text" id="prCode" inputmode="numeric" autocomplete="off" maxlength="7" placeholder="000 000" class="mono pair-code"></label>
    <label class="field">Název <span class="hint">např. Televize u pokladny</span><input type="text" id="prName" maxlength="100" value="Televize"></label>
    <label class="field">Délka zobrazení (s) <span class="hint">prázdné = výchozí (${info.duration} s)</span>
      <input type="number" id="prDur" min="3" max="3600" step="1" placeholder="${info.duration}" style="max-width:140px"></label>
    <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="prGo">Spárovat</button></div></div>`);
  $('#prCode').focus();
  $('#prCode').oninput = (e) => { const d = e.target.value.replace(/\D/g, '').slice(0, 6); e.target.value = d.length > 3 ? d.slice(0, 3) + ' ' + d.slice(3) : d; };
  $('#prGo').onclick = (e) => busy(e.currentTarget, 'Páruji…', async () => {
    try {
      const r = await api('device_pair', { code: $('#prCode').value, name: $('#prName').value, duration: $('#prDur').value === '' ? null : $('#prDur').value });
      closeModal();
      toast(`Televize „${r.device.name}“ je spárovaná. Přehrávání se na ní rozběhne do několika sekund.`);
      done();
    } catch (err) { toast(err.message, 'error'); }
  });
}

/** Nastavení → Obrázky → Písma pro slidy: seznam s náhledem, nahrání, přejmenování, odebrání vlastních písem. */
async function drawFontsPanel() {
  const box = $('#fontsBox');
  if (!box) return;
  let r;
  try { r = await api('fonts_custom'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
  if (!$('#fontsBox')) return;
  const sample = '137 000 Kč · Příliš žluťoučký kůň';
  box.innerHTML = `<h2 style="margin:0">Písma pro slidy ${helpBtn('jak-pridat-pismo', true)}</h2>
    <p class="small muted" style="margin:0">Vlastní písma TTF nebo OTF se hned objeví v nabídce <b>Písmo</b> v editoru slidu. Aktualizace aplikace je nepřepíše a jsou i v zálohách.</p>
    <div class="font-list">${r.fonts.map((f) => `<div class="font-row" data-fk="${f.key}">
      <b class="fname">${esc(f.name)}</b><span class="fprev" style="font-family:'mpf-${f.key}';font-weight:${f.weights.at(-1)}">${sample}</span>
      ${f.custom ? `<span class="muted small">${f.used ? `ve ${f.used} ${f.used === 1 ? 'slidu' : 'slidech'}` : 'nepoužité'}</span>
        <button class="btn sm" data-fren="${f.key}">Přejmenovat</button><button class="btn sm" data-fdel="${f.key}">Odebrat</button>` : '<span class="muted small">vestavěné</span>'}</div>`).join('')}</div>
    <div class="row" style="gap:10px;flex-wrap:wrap;align-items:center"><button class="btn primary" id="fontUp">⬆ Nahrát písmo</button>
      <span class="small muted">TTF nebo OTF, nejvýš ${r.max_mb} MB. Písmo by mělo obsahovat české znaky.</span>
      <input type="file" id="fontFile" accept=".ttf,.otf" hidden></div>`;
  for (const f of r.fonts) {
    const w = f.weights.at(-1);
    try { const ff = new FontFace(`mpf-${f.key}`, `url(${f.files[w]})`, { weight: String(w) }); ff.load().then((x) => document.fonts.add(x)).catch(() => {}); } catch { /* starý prohlížeč */ }
  }
  $('#fontUp').onclick = () => $('#fontFile').click();
  $('#fontFile').onchange = async (ev) => {
    const file = ev.target.files[0]; ev.target.value = '';
    if (!file) return;
    const fd = new FormData(); fd.append('file', file);
    try {
      const res = await api('font_upload', fd);
      if (window.FONT_LIST_RESET) window.FONT_LIST_RESET();
      toast(res.missing.length ? `Písmo „${res.font.name}“ nahráno, ale chybí v něm české znaky: ${res.missing.join(' ')}. Ve slidu se místo nich zobrazí prázdné místo nebo obdélníček.` : `Písmo „${res.font.name}“ nahráno.`, res.missing.length ? 'warn' : 'ok');
      drawFontsPanel();
    } catch (err) { toast(err.message, 'error'); }
  };
  box.querySelectorAll('[data-fren]').forEach((b) => { b.onclick = () => {
    const f = r.fonts.find((x) => x.key === b.dataset.fren);
    openModal(`<h2>Přejmenovat písmo</h2><div class="stack"><label class="field">Název<input type="text" id="fnName" maxlength="60" value="${esc(f.name)}"></label>
      <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="fnSave">Uložit</button></div></div>`);
    $('#fnName').focus();
    $('#fnSave').onclick = async () => {
      try { await api('font_rename', { key: f.key, name: $('#fnName').value }); closeModal(); if (window.FONT_LIST_RESET) window.FONT_LIST_RESET(); drawFontsPanel(); } catch (err) { toast(err.message, 'error'); }
    };
  }; });
  box.querySelectorAll('[data-fdel]').forEach((b) => { b.onclick = async () => {
    const f = r.fonts.find((x) => x.key === b.dataset.fdel);
    if (!confirm(`Odebrat písmo „${f.name}“?`)) return;
    try { await api('font_delete', { key: f.key }); toast('Písmo odebráno.'); if (window.FONT_LIST_RESET) window.FONT_LIST_RESET(); drawFontsPanel(); } catch (err) { toast(err.message, 'error'); }
  }; });
}

let devTimer = null;
const SET_RENDER = {
  async tagy(st, box) {
    let tags;
    try { tags = await api('tags'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    box.innerHTML = `<div class="panel stack" data-nodirty><h2 style="margin:0">Tagy ${helpBtn('@nastaveni-tagy', true)}</h2>
      <p class="hint small" style="margin:0">Tagy přidělujete médiím v Galerii (v okně <b>Upravit</b> nebo hromadně tlačítkem <b>🏷 Tagy</b>). Tady je můžete přejmenovat nebo smazat ve všech médiích najednou. Přejmenováním na už existující tag se tagy sloučí.</p>
      ${tags.length ? `<table class="dtable" id="tagTbl"><thead><tr><th>Tag</th><th>Médií</th><th></th></tr></thead><tbody>${tags.map((t) => `<tr>
        <td><span class="tg">${esc(t.name)}</span></td><td>${t.count}</td>
        <td style="text-align:right;white-space:nowrap"><button class="btn sm ghost" data-tren="${esc(t.name)}">Přejmenovat</button> <button class="btn sm ghost danger" data-tdel="${esc(t.name)}">Smazat</button></td></tr>`).join('')}</tbody></table>`
        : '<div class="empty small">Zatím žádné tagy. Přidáte je médiím v Galerii.</div>'}</div>`;
    const redraw = () => { loadAll().catch(() => {}); SET_RENDER.tagy(st, box); };
    $$('#tagTbl [data-tren]').forEach((b) => b.onclick = async () => {
      const from = b.dataset.tren;
      const to = prompt(`Nový název tagu „${from}“:`, from);
      if (!to?.trim() || to.trim() === from) return;
      try { const r = await api('tag_rename', { from, to }); toast(`Tag přejmenován (${r.changed} ${plural(r.changed, 'médium', 'média', 'médií')}).`); redraw(); } catch (e) { toast(e.message, 'error'); }
    });
    $$('#tagTbl [data-tdel]').forEach((b) => b.onclick = async () => {
      const name = b.dataset.tdel, n = tags.find((t) => t.name === name)?.count || 0;
      if (!confirm(`Smazat tag „${name}“? Odebere se z ${n} ${plural(n, 'média', 'médií', 'médií')}. Média zůstanou.`)) return;
      try { await api('tag_delete', { name }); toast(`Tag „${name}“ smazán.`); redraw(); } catch (e) { toast(e.message, 'error'); }
    });
  },
  async zarizeni(st, box) {
    box.innerHTML = '<div class="muted">Načítám…</div>';
    let info;
    try { info = await api('devices'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    if (!$('#setBody')) return;
    const again = () => SET_RENDER.zarizeni(st, $('#setBody'));
    const rows = info.devices.length ? info.devices.map((d) => {
      const seen = seenText(d.last_seen, d.offline);
      const canCmd = d.controllable && d.playing && !d.offline;
      return `<div class="pk-row dev-row" data-dev="${d.id}">
        <span class="pk-name">${esc(d.name)}<span class="muted small" style="font-weight:400"> · ${esc(d.kind_label)} · ${d.duration ?? info.duration} s${d.duration == null ? ' (výchozí)' : ''}</span></span>
        <span class="small ${seen.cls === 'ok' ? 'ok-text' : seen.cls === 'err' ? 'err-text' : 'muted'}" title="${d.client ? esc(d.client) : ''}">${seen.cls === 'ok' ? '<span class="dot on"></span>' : seen.cls === 'err' ? '<span class="dot"></span>' : ''}${esc(seen.txt)}</span>
        <button class="btn sm" data-devedit="${d.id}">Upravit</button><button class="btn sm" data-devkey="${d.id}">Nový klíč</button><button class="btn sm" data-devdel="${d.id}">Odebrat</button>
        ${d.last_seen ? `<div class="dev-st" data-devst="${d.id}">${deviceStatusHtml(d)}</div>` : ''}
        ${canCmd ? `<div class="dev-cmds">${Object.entries(info.commands).map(([k, l]) => `<button class="btn sm" data-devcmd="${k}" data-id="${d.id}" ${d.cmd ? 'disabled' : ''}>${{ refresh: '⟳', restart: '↻', next: '⏭' }[k]} ${esc(l)}</button>`).join('')}
          ${d.cmd ? `<span class="small muted">Odesláno: ${esc(info.commands[d.cmd])} – zařízení zareaguje do ${info.ping_seconds} s.</span>` : ''}</div>` : ''}</div>`;
    }).join('') : '<div class="empty small">Zatím není přidané žádné zařízení.</div>';
    box.innerHTML = `
      <div class="panel stack" data-nodirty><h2 style="margin:0">Zařízení pro přehrávání ${helpBtn('@nastaveni-zarizeni', true)}</h2>
        <p class="small muted" style="margin:0">Plugin pro Chrome a aplikace pro TV Samsung a Android TV přehrávají stejný obsah jako složka přehrávače${st.folder_name ? ` <b>${esc(st.folder_name)}</b>` : ''} na Google Disku. Každé zařízení má vlastní klíč, který umí jen číst. Seznam si zařízení obnovuje každých ${info.refresh_minutes} minut, a když se obsah složky změní, do ${info.ping_seconds} sekund.</p>
        <div class="pk-list" id="devList">${rows}</div>
        <div class="row" style="gap:8px;flex-wrap:wrap"><button class="btn primary" id="devAdd">+ Přidat zařízení</button>
          <button class="btn" id="devPair">📺 Spárovat televizi</button></div>
        <div class="row" style="gap:8px;flex-wrap:wrap">
          ${info.chrome_version ? `<a class="btn" id="devZip" href="api.php?a=extension_zip" download>⬇ Stáhnout plugin pro Chrome (${esc(info.chrome_version)})</a>` : ''}
          ${info.tizen_version ? `<a class="btn" id="devZipTv" href="api.php?a=extension_zip&amp;kind=tizen" download>⬇ Stáhnout aplikaci pro TV Samsung (${esc(info.tizen_version)})</a>` : ''}
          ${info.android_version ? `<a class="btn" id="devZipAndroid" href="api.php?a=extension_zip&amp;kind=android" download>⬇ Stáhnout aplikaci pro Android TV (${esc(info.android_version)})</a>` : ''}
          ${helpBtn('jak-nainstalovat-aplikaci-tv', true)}</div>
      </div>
      <div class="panel stack" data-nodirty><h2 style="margin:0">Výchozí délka zobrazení</h2>
        <p class="small muted" style="margin:0">Jak dlouho zařízení ukazuje obrázek, slide, PDF nebo text. Videa se přehrají vždy celá. Zařízení může mít vlastní délku (tlačítko <b>Upravit</b>).</p>
        <div class="row" style="gap:8px;align-items:flex-end"><label class="field" style="margin:0">Sekundy <span class="hint">3 až 3600</span>
          <input type="number" id="devDur" min="3" max="3600" step="1" value="${info.duration}" style="max-width:140px"></label>
          <button class="btn" id="devDurSave">Uložit</button></div>
      </div>`;
    $('#devAdd').onclick = () => deviceDialog(null, info, again);
    $('#devPair').onclick = () => pairDialog(info, again);
    $('#devDurSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
      try { await api('device_settings', { duration: $('#devDur').value }); toast('Nastavení uloženo.'); again(); } catch (err) { toast(err.message, 'error'); }
    });
    const byId = (id) => info.devices.find((d) => d.id === +id);
    box.querySelectorAll('[data-devedit]').forEach((b) => { b.onclick = () => deviceDialog(byId(b.dataset.devedit), info, again); });
    box.querySelectorAll('[data-devcmd]').forEach((b) => { b.onclick = async () => {
      const d = byId(b.dataset.id);
      try {
        await api('device_cmd', { id: d.id, cmd: b.dataset.devcmd });
        toast(`${info.commands[b.dataset.devcmd]}: odesláno do „${d.name}“. Zařízení zareaguje do ${info.ping_seconds} s.`);
        again();
      } catch (err) { toast(err.message, 'error'); }
    }; });
    // stav zařízení se obnovuje každých 15 s, dokud je karta otevřená
    clearInterval(devTimer);
    devTimer = setInterval(async () => {
      if (!$('#devList')) { clearInterval(devTimer); return; }
      if (!$('#modal').hidden || document.hidden) return;
      const fresh = await api('devices', undefined, '', true).catch(() => null);
      if (fresh && $('#devList') && JSON.stringify(fresh.devices) !== JSON.stringify(info.devices)) again();
    }, 15000);
    box.querySelectorAll('[data-devkey]').forEach((b) => { b.onclick = async () => {
      const d = byId(b.dataset.devkey);
      if (!confirm(`Vytvořit pro „${d.name}“ nový klíč? Starý klíč přestane fungovat a v pluginu bude potřeba zadat nový.`)) return;
      try { const r = await api('device_token', { id: d.id }); deviceKeyDialog(d, r.token, info.base_url, false); } catch (err) { toast(err.message, 'error'); }
    }; });
    box.querySelectorAll('[data-devdel]').forEach((b) => { b.onclick = async () => {
      const d = byId(b.dataset.devdel);
      if (!confirm(`Odebrat zařízení „${d.name}“? Jeho klíč přestane fungovat a zařízení přestane obnovovat obsah.`)) return;
      try { await api('device_delete', { id: d.id }); toast('Zařízení odebráno.'); again(); } catch (err) { toast(err.message, 'error'); }
    }; });
  },

  disk(st, box) {
    const d = st.drive;
    let driveBox;
    if (d.mode === 'mock') driveBox = '<p>Testovací režim (<code>drive_mock</code> v config.php) – soubory se kopírují do <code>data/mock-drive/</code>.</p>';
    else if (!d.configured) driveBox = `<div class="warnbox">V config.php chybí <code>google_client_id</code> a <code>google_client_secret</code>. V Google Cloud Console zadejte jako Authorized redirect URI: <code>${esc(st.redirect_uri)}</code></div>`;
    else if (d.connected) driveBox = `<div class="row"><span class="grow"><span class="dot on"></span>Připojeno${d.account ? `: <b>${esc(d.account)}</b>` : ''}</span><button class="btn danger" id="gDisc">Odpojit</button></div>`;
    else driveBox = `<div class="row"><span class="grow"><span class="dot"></span>Nepřipojeno</span><a class="btn primary" href="oauth.php">Připojit Google Disk</a></div>
      <p class="small muted">Redirect URI pro Google Cloud Console: <code>${esc(st.redirect_uri)}</code></p>`;
    box.innerHTML = `<div class="panel stack"><h2 style="margin:0">Google Disk</h2>${driveBox}
      <label class="field">Cílová složka <span class="hint">odkaz nebo ID složky, kterou čte přehrávač${st.folder_name ? ` · aktuálně <b>${esc(st.folder_name)}</b>` : ''}</span>
        <input type="text" id="sFolder" value="${esc(st.folder_id)}" placeholder="https://drive.google.com/drive/folders/…"></label>
      <div class="warnbox">Ze složky se odstraní všechny soubory, které právě nemají běžet. Podsložky zůstávají beze změny.</div>
      <label class="field">Odstraněné soubory
        <select id="sDel"><option value="trash" ${st.delete_mode !== 'permanent' ? 'selected' : ''}>přesunout do koše Google Disku (30 dní lze obnovit)</option>
        <option value="permanent" ${st.delete_mode === 'permanent' ? 'selected' : ''}>smazat trvale</option></select></label>
      ${saveRow()}</div>`;
    $('#tSave').onclick = (e) => saveTab(e.currentTarget, { folder: $('#sFolder').value, delete_mode: $('#sDel').value });
    $('#gDisc')?.addEventListener('click', async () => {
      if (!confirm('Odpojit Google Disk? Obrazovka se pak přestane aktualizovat.')) return;
      await api('google_disconnect', {}); route();
    });
  },

  obrazky(st, box) {
    box.innerHTML = `<div class="panel stack"><h2 style="margin:0">Obrázky</h2>
      <label class="check-row"><input type="checkbox" id="iAuto" ${st.img.auto ? 'checked' : ''}><span><b>Automaticky komprimovat</b> při nahrání a nahrazení souboru</span></label>
      <p class="small muted" style="margin:0">Větší obrázky se zmenší do zadaného rozměru. Obrázky nad hranicí velikosti se převedou na JPG i bez zmenšení.
        Obrázky s průhledností (např. loga) se jen zmenší a zůstanou PNG. Animované GIFy zůstanou beze změny. Originál se vždy uchová a jde vrátit.
        Dříve nahrané obrázky zkomprimujete v Galerii hromadně (označit → 🗜 Zmenšit a komprimovat).</p>
      <div class="cols4">
        <label class="field">Max. šířka (px)<input type="number" id="iW" min="320" max="7680" value="${st.img.max_w}"></label>
        <label class="field">Max. výška (px)<input type="number" id="iH" min="240" max="4320" value="${st.img.max_h}"></label>
        <label class="field">Kvalita JPG (%)<input type="number" id="iQ" min="30" max="100" value="${st.img.quality}"></label>
        <label class="field">Hranice velikosti (kB)<input type="number" id="iKb" min="0" max="100000" value="${st.img.min_kb}"></label>
      </div>
      ${saveRow()}</div>
      <div class="panel stack" data-nodirty id="fontsBox"><div class="muted small">Načítám písma…</div></div>`;
    $('#tSave').onclick = (e) => saveTab(e.currentTarget, { img: { auto: $('#iAuto').checked, max_w: +$('#iW').value, max_h: +$('#iH').value, quality: +$('#iQ').value, min_kb: +$('#iKb').value } });
    drawFontsPanel();
  },

  prehravac(st, box) {
    box.innerHTML = '<div class="panel stack" id="playerPanel"></div>';
    drawPlayerPanel(st);
  },

  async pocasi(st, box) {
    box.innerHTML = '<div class="panel"><div class="empty small">Načítám…</div></div>';
    let w;
    try { w = await api('weather'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    const prov = w.providers.find((p) => p.key === w.provider) || w.providers[0];
    box.innerHTML = `<div class="panel stack"><h2 style="margin:0">Počasí ${helpBtn('@nastaveni-pocasi', true)}</h2>
      <p class="small muted" style="margin:0">Počasí pro prvek <b>Počasí</b> v editoru slidu a pro proměnné <code>{Počasí:teplota}</code>. Stahuje se každých ${w.interval} minut; když služba nefunguje, slidy použijí poslední stažená data.</p>
      <label class="field">Služba<select id="wProv">${w.providers.map((p) => `<option value="${esc(p.key)}" ${p.key === w.provider ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
      <label class="field">API klíč <span class="hint">${w.key_set ? 'uložen – vyplňte jen pro změnu' : `najdete po přihlášení na <a href="${esc(prov.key_url)}" target="_blank" rel="noopener">${esc(prov.name)} → API keys</a>`}</span>
        <input type="password" id="wKey" placeholder="${w.key_set ? '••••••••' : ''}" autocomplete="off"></label>
      <label class="field">Výchozí místo <span class="hint">– použije se, když u prvku nebo proměnné místo nezadáte</span>
        <input type="text" id="wPlace" value="${esc(w.place)}" placeholder="např. Praha nebo Brno,CZ" maxlength="100"></label>
      <div class="row" style="margin-top:4px"><button class="btn primary" id="wSave">Uložit</button><button class="btn" id="wTest">Uložit a vyzkoušet</button>
        ${w.key_set ? '<span class="grow"></span><button class="btn ghost danger" id="wClear">Odebrat klíč</button>' : ''}</div>
      <div id="wResult"></div></div>
      <div class="panel stack" data-nodirty><h3 style="margin:0">Stažená místa</h3>
      ${w.places.length ? `<table class="dtable" id="wPlaces"><thead><tr><th>Místo</th><th>Stav</th><th>Staženo</th></tr></thead><tbody>${w.places.map((x) => `<tr>
        <td>${esc(x.place)}${x.used ? '' : ' <span class="hint small">(nepoužívá se)</span>'}</td>
        <td>${x.error ? `<span class="tag err">chyba</span> <span class="small">${esc(x.error)}</span>` : '<span class="tag ok">v pořádku</span>'}</td>
        <td class="small" style="white-space:nowrap">${x.ok_at ? esc(fmtTime(x.ok_at)) : '—'}</td></tr>`).join('')}</tbody></table>`
        : '<div class="empty small">Zatím žádné. Místo se objeví po vyzkoušení nebo po použití ve slidu.</div>'}</div>`;
    const body = () => ({ provider: $('#wProv').value, api_key: $('#wKey').value.trim(), place: $('#wPlace').value.trim() });
    const save = async () => { await api('weather_save', body()); window.settingsSaved?.(); };
    $('#wSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
      try { await save(); toast('Nastavení počasí uloženo.'); SET_RENDER.pocasi(st, box); } catch (err) { toast(err.message, 'error'); }
    });
    $('#wClear')?.addEventListener('click', async () => {
      if (!confirm('Odebrat API klíč? Slidy pak budou ukazovat poslední stažené počasí, dokud klíč znovu nezadáte.')) return;
      try { await api('weather_save', { ...body(), api_key: '', clear_key: true }); window.settingsSaved?.(); toast('API klíč odebrán.'); SET_RENDER.pocasi(st, box); } catch (err) { toast(err.message, 'error'); }
    });
    $('#wTest').onclick = (e) => busy(e.currentTarget, 'Zkouším…', async () => {
      const out = $('#wResult');
      try {
        await save();
        const r = await api('weather_test', { place: $('#wPlace').value.trim() });
        out.innerHTML = `<div class="w-test"><img src="api.php?a=weather_icon&code=${esc(r.icon)}" alt=""><div><b class="w-temp">${esc(r.temp)} °C</b> ${esc(r.desc)}
          <div class="small">${esc(r.place)}${r.name && r.name.toLowerCase() !== r.place.toLowerCase() ? ` (${esc(r.name)})` : ''} · pocitově ${esc(r.feels)} °C · vlhkost ${esc(r.humidity)} % · vítr ${esc(r.wind)} m/s</div>
          <div class="small">${r.days.map((d) => `${esc(d.label)}: ${esc(d.max)}° / ${esc(d.min)}°`).join(' · ')}</div></div></div>`;
        toast('Počasí staženo.');
        setTimeout(() => SET_RENDER.pocasi(st, box).then(() => { $('#wResult').innerHTML = out.innerHTML; }), 0);
      } catch (err) { out.innerHTML = `<div class="errbox">${esc(err.message)}</div>`; }
    });
  },

  upozorneni(st, box) {
    box.innerHTML = `<div class="panel stack"><h2 style="margin:0">Upozornění na chyby</h2>
      <p class="small muted" style="margin:0">Pošle se při první chybě synchronizace, pak nejvýš jednou za 6 hodin, a znovu, když se vše opraví.</p>
      <label class="field">E-mail<input type="email" id="sEmail" value="${esc(st.notify_email)}"></label>
      <div class="cols2">
        <label class="field">Telefon pro SMS<input type="tel" id="sPhone" value="${esc(st.notify_phone)}" placeholder="+420 777 123 456"></label>
        <label class="field">BulkGate Application ID<input type="text" id="sBgId" value="${esc(st.bulkgate_app_id)}"></label>
      </div>
      <label class="field">BulkGate Application token <span class="hint">${st.bulkgate_token_set ? 'uložen – vyplňte jen pro změnu' : 'najdete v portálu BulkGate → Modules & APIs → HTTP Simple API'}</span>
        <input type="password" id="sBgTok" placeholder="${st.bulkgate_token_set ? '••••••••' : ''}" autocomplete="off"></label>
      ${saveRow('<button class="btn" id="sTest">Uložit a odeslat zkušební upozornění</button>')}</div>`;
    const body = () => ({ notify_email: $('#sEmail').value, notify_phone: $('#sPhone').value, bulkgate_app_id: $('#sBgId').value, bulkgate_token: $('#sBgTok').value });
    $('#tSave').onclick = (e) => saveTab(e.currentTarget, body());
    $('#sTest').onclick = (e) => busy(e.currentTarget, 'Odesílám…', async () => {
      try {
        await api('settings_save', body()); window.settingsSaved?.();
        const r = await api('notify_test', {});
        toast(r.result.join(' · '), r.result.some((x) => /selhal|chyba/i.test(x)) ? 'error' : 'ok');
      } catch (err) { toast(err.message, 'error'); }
    });
  },

  async zalohy(st, box) {
    box.innerHTML = '<div class="panel"><div class="empty small">Načítám…</div></div>';
    let b;
    try { b = await api('backups'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    const ago = (iso) => (iso ? fmtDT(iso) : '—');
    box.innerHTML = `
      ${b.supported ? `<div class="errbox">${esc(b.supported)}</div>` : ''}
      <div class="panel stack"><h2 style="margin:0">Zálohy</h2>
        <p class="small muted" style="margin:0">Každý den od ${b.hour}:00 se vytvoří šifrovaná záloha (ZIP, AES-256): celá databáze včetně přístupových údajů a – jen když se od minula změnily – soubory galerie.
          Na serveru zůstává posledních ${b.keep} záloh a vždy poslední záloha se soubory. Kopie si stahuje Synology.</p>
        <dl class="kv"><dt>Poslední záloha</dt><dd>${ago(b.last_at)}</dd>
          <dt>Synology</dt><dd>${b.nas_at ? `naposledy se ptal ${fmtDT(b.nas_at)}${b.nas_file ? ` · stáhl ${esc(b.nas_file)} (${fmtDT(b.nas_file_at)})` : ''}` : '<span class="muted">zatím se neozval – vložte nový skript (karta Přehrávač)</span>'}</dd></dl>
        <label class="check-row"><input type="checkbox" id="bAuto" ${b.auto ? 'checked' : ''}><span><b>Automatické denní zálohy</b></span></label>
        <div class="cols2">
          <label class="field">Heslo záloh <span class="hint">${b.password_set ? 'nastaveno – vyplňte jen pro změnu' : 'aspoň 8 znaků, bez apostrofu – bez hesla se nezálohuje'}</span>
            <input type="password" id="bPass" autocomplete="new-password" placeholder="${b.password_set ? '••••••••' : ''}"></label>
          <div class="hint small" style="align-self:end">Heslo si uložte i mimo aplikaci – bez něj zálohu neotevřete. Po změně hesla se starší zálohy otevírají původním heslem.</div>
        </div>
        <div class="cols2">
          <label class="field">Složka na Synology<input type="text" id="bDir" value="${esc(b.nas_dir)}"></label>
          <label class="field">Mazat na Synology zálohy starší než (dní) <span class="hint">0 = nemazat</span><input type="number" id="bDays" min="0" max="3650" value="${b.nas_days}"></label>
        </div>
        <p class="hint small" style="margin:0">Po změně složky nebo počtu dní vložte na Synology nový skript (karta Přehrávač).</p>
        ${saveRow()}
      </div>
      <div class="panel stack"><div class="row"><h2 class="grow" style="margin:0">Uložené zálohy</h2>
          <button class="btn" id="bNow" ${b.supported || !b.password_set ? 'disabled' : ''}>Zálohovat teď</button>
          <button class="btn ghost" id="bUp" ${b.supported ? 'disabled' : ''}>Obnovit ze souboru…</button><input type="file" id="bUpFile" accept=".zip,application/zip" hidden></div>
        ${b.list.length ? `<div class="bk-list">${b.list.map((x) => `<div class="bk-row">
            <div class="grow"><b>${fmtDT(x.date)}</b> ${x.files ? '<span class="tag ok">se soubory</span>' : '<span class="tag">jen databáze</span>'}${x.before_restore ? ' <span class="tag">před obnovením</span>' : ''}${x.uploaded ? ' <span class="tag">nahraná</span>' : ''}
              <div class="hint small">${esc(x.name)} · ${fmtSize(x.size)}</div></div>
            <div class="row" style="gap:6px"><a class="btn sm" href="api.php?a=backup_download&name=${encodeURIComponent(x.name)}" download>Stáhnout</a>
              <button class="btn sm" data-brest="${esc(x.name)}">Obnovit…</button><button class="x" data-bdel="${esc(x.name)}" title="Smazat zálohu">×</button></div></div>`).join('')}</div>`
          : '<div class="empty small">Zatím žádná záloha.</div>'}
      </div>`;
    $('#tSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
      try {
        await api('backup_settings', { auto: $('#bAuto').checked, password: $('#bPass').value, nas_dir: $('#bDir').value, nas_days: +$('#bDays').value });
        window.settingsSaved?.(); toast('Nastavení záloh uloženo.'); route();
      } catch (err) { toast(err.message, 'error'); }
    });
    $('#bNow').onclick = (e) => busy(e.currentTarget, 'Zálohuji…', async () => {
      try { const r = await api('backup_create', {}); toast(`Záloha vytvořena (${fmtSize(r.size)}${r.files ? ', se soubory galerie' : ', jen databáze – soubory se nezměnily'}).`); SET_RENDER.zalohy(st, box); }
      catch (err) { toast(err.message, 'error'); }
    });
    $$('[data-bdel]').forEach((x) => x.onclick = async () => {
      if (!confirm(`Smazat zálohu ${x.dataset.bdel}?`)) return;
      try { await api('backup_delete', { name: x.dataset.bdel }); toast('Záloha smazána.'); SET_RENDER.zalohy(st, box); } catch (err) { toast(err.message, 'error'); }
    });
    $$('[data-brest]').forEach((x) => x.onclick = () => restoreDialog(x.dataset.brest, b.password_set));
    $('#bUp').onclick = () => $('#bUpFile').click();
    $('#bUpFile').onchange = async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      await busy($('#bUp'), 'Nahrávám…', async () => {
        try { const r = await uploadOne(f, {}, () => {}, 'backup_upload'); restoreDialog(r.name, b.password_set); }
        catch (err) { toast(err.message, 'error'); }
      });
      e.target.value = '';
    };
  },

  async zabezpeceni(st, box) {
    box.innerHTML = '<div class="muted">Načítám…</div>';
    let sec;
    try { sec = await api('security'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
    if (!$('#setBody')) return;
    const pkRows = sec.passkeys.length ? sec.passkeys.map((p) => `<div class="pk-row" data-pk="${p.id}">
        <span class="pk-name">🔑 ${esc(p.name)}</span>
        <span class="muted small">přidáno ${fmtDT(p.created_at)} · ${p.last_used ? 'naposledy ' + fmtDT(p.last_used) : 'zatím nepoužito'}</span>
        <button class="btn sm" data-pkren="${p.id}">Přejmenovat</button><button class="btn sm" data-pkdel="${p.id}">Odebrat</button></div>`).join('')
      : '<div class="empty small">Zatím není zaregistrované žádné zařízení.</div>';
    const canPk = sec.passkey_available && window.webauthn && webauthn.supported();
    box.innerHTML = `
      <div class="panel stack" data-nodirty><h2 style="margin:0">Heslo ${helpBtn('@nastaveni-zabezpeceni', true)}</h2>
        <p class="small muted" style="margin:0">Heslo funguje vždy, i když zapnete Google nebo Passkey. Je to záloha, kdyby se jiný způsob nedal použít.</p>
        <div class="cols2"><label class="field">Současné heslo<input type="password" id="pOld" autocomplete="current-password"></label><label class="field">Nové heslo <span class="hint">aspoň 8 znaků</span><input type="password" id="pNew" autocomplete="new-password"></label></div>
        <div><button class="btn" id="pSave">Změnit heslo</button></div>
        <p class="small muted" style="margin:0">Zapomenuté heslo jde obnovit na přihlašovací stránce. Odkaz přijde na e-mail z karty <b>Upozornění</b>.</p>
        ${sec.reset_email_set ? '' : '<div class="warnbox">V kartě Upozornění není vyplněný e-mail, takže obnovení zapomenutého hesla zatím nebude fungovat.</div>'}
      </div>
      <div class="panel stack" data-nodirty id="idleBox"><h2 style="margin:0">Automatické odhlášení</h2>
        <p class="small muted" style="margin:0">Když v aplikaci nic neděláte, po nastavené době vás odhlásí. Dvě minuty předem se objeví upozornění. Automatické obnovování Přehledu a Protokolu se za činnost nepočítá.</p>
        <div class="row" style="gap:8px;align-items:flex-end"><label class="field" style="margin:0">Odhlásit po nečinnosti (minuty) <span class="hint">5 až 1440, 0 = nikdy</span>
          <input type="number" id="idleMin" min="0" max="1440" step="1" value="${sec.idle_minutes}" style="max-width:140px"></label>
          <button class="btn" id="idleSave">Uložit</button></div>
      </div>
      <div class="panel stack" data-nodirty id="glBox"><h2 style="margin:0">Přihlášení přes Google</h2>
        ${sec.google_linked
          ? `<p class="small" style="margin:0">Propojený účet: <b>${esc(sec.google_email)}</b>. Na přihlašovací stránce je tlačítko <b>Přihlásit přes Google</b>.</p>
             <div class="row" style="gap:8px"><a class="btn" href="login-google.php?link=1">Propojit jiný účet</a><button class="btn" id="glUnlink">Odpojit</button></div>`
          : `<p class="small muted" style="margin:0">Přihlásíte se svým Google účtem. Smí to jen ten jeden účet, který propojíte.</p>
             ${sec.google_configured ? '<div><a class="btn" href="login-google.php?link=1">Propojit Google účet</a></div>' : '<div class="warnbox">V config.php chybí google_client_id a google_client_secret.</div>'}`}
        <details class="small"><summary>Co je potřeba nastavit v Google</summary>
          <p>V Google Cloud Console přidejte ke stejnému OAuth klientovi, který používá Google Disk, ještě tuto <b>autorizovanou adresu pro přesměrování</b>:</p>
          <p><code>${esc(sec.google_redirect_uri)}</code></p></details>
      </div>
      <div class="panel stack" data-nodirty id="pkBox"><h2 style="margin:0">Passkey</h2>
        <p class="small muted" style="margin:0">Přihlášení otiskem, obličejem, PINem zařízení nebo bezpečnostním klíčem, bez hesla. Zaregistrujte každé zařízení zvlášť (telefon, počítač).</p>
        ${canPk ? '' : `<div class="warnbox">${sec.passkey_secure ? 'Tento prohlížeč Passkey nepodporuje.' : 'Passkey funguje jen na adrese s HTTPS.'}</div>`}
        <div class="pk-list">${pkRows}</div>
        ${canPk ? `<div class="row" style="gap:8px;align-items:flex-end"><label class="field grow" style="margin:0">Název nového zařízení<input type="text" id="pkName" maxlength="100" placeholder="např. Telefon"></label>
          <button class="btn primary" id="pkAdd">Přidat zařízení</button></div>` : ''}
      </div>`;
    $('#pSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
      try { await api('password', { old: $('#pOld').value, new: $('#pNew').value }); toast('Heslo změněno.'); $('#pOld').value = $('#pNew').value = ''; }
      catch (err) { toast(err.message, 'error'); }
    });
    $('#idleSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
      try {
        const r = await api('idle_save', { minutes: $('#idleMin').value });
        idle.setLimit(r.limit);
        toast('Nastavení uloženo.');
      } catch (err) { toast(err.message, 'error'); }
    });
    $('#glUnlink')?.addEventListener('click', async () => {
      if (!confirm('Odpojit Google účet? Přihlášení přes Google přestane fungovat. Heslo zůstane.')) return;
      try { await api('google_login_unlink', {}); toast('Google účet odpojen.'); SET_RENDER.zabezpeceni(st, $('#setBody')); } catch (err) { toast(err.message, 'error'); }
    });
    $('#pkAdd')?.addEventListener('click', (e) => busy(e.currentTarget, 'Čekám na zařízení…', async () => {
      try {
        const name = $('#pkName').value.trim() || 'Zařízení';
        const cred = await webauthn.create(await api('passkey_options', {}));
        await api('passkey_register', { credential: cred, name });
        toast('Zařízení je zaregistrované.');
        SET_RENDER.zabezpeceni(st, $('#setBody'));
      } catch (err) {
        toast(err.name === 'NotAllowedError' ? 'Registrace byla zrušena nebo vypršela.' : err.name === 'InvalidStateError' ? 'Toto zařízení už je zaregistrované.' : err.message, 'error');
      }
    }));
    box.querySelectorAll('[data-pkdel]').forEach((b) => { b.onclick = async () => {
      const row = b.closest('[data-pk]');
      if (!confirm(`Odebrat zařízení „${row.querySelector('.pk-name').textContent.replace('🔑', '').trim()}“? Na něm se pak nepůjde přihlásit pomocí Passkey.`)) return;
      try { await api('passkey_delete', { id: +b.dataset.pkdel }); toast('Zařízení odebráno.'); SET_RENDER.zabezpeceni(st, $('#setBody')); } catch (err) { toast(err.message, 'error'); }
    }; });
    box.querySelectorAll('[data-pkren]').forEach((b) => { b.onclick = () => {
      const cur = b.closest('[data-pk]').querySelector('.pk-name').textContent.replace('🔑', '').trim();
      openModal(`<h2>Přejmenovat zařízení</h2><div class="stack"><label class="field">Název<input type="text" id="pkNew" maxlength="100" value="${esc(cur)}"></label>
        <div class="row"><span class="grow"></span><button class="btn" data-close>Zrušit</button><button class="btn primary" id="pkNewSave">Uložit</button></div></div>`);
      $('#pkNew').select();
      $('#pkNewSave').onclick = (e) => busy(e.currentTarget, 'Ukládám…', async () => {
        try { await api('passkey_rename', { id: +b.dataset.pkren, name: $('#pkNew').value }); closeModal(); SET_RENDER.zabezpeceni(st, $('#setBody')); }
        catch (err) { toast(err.message, 'error'); }
      });
    }; });
  },

  system(st, box) {
    const log = Object.entries(st.changelog || {});
    box.innerHTML = `
      <div class="panel stack"><h2 style="margin:0">${esc(st.app_name)}</h2>
        <dl class="kv"><dt>Verze aplikace</dt><dd><b>${esc(st.version)}</b></dd><dt>Verze databáze</dt><dd>${esc(String(st.schema))}</dd><dt>PHP</dt><dd>${esc(st.php)}</dd></dl>
      </div>
      <div class="panel stack" data-nodirty id="updPanel"><h2 style="margin:0">Aktualizace</h2><div id="updBox" class="stack"><span class="muted small">Načítám…</span></div></div>
      <div class="panel stack" data-nodirty><h2 style="margin:0">Diagnostika</h2>
        <p class="small muted" style="margin:0">Ověří server, Google Disk, cron, zálohy a Synology. Nic nemění – na Google Disk nic nenahrává.</p>
        <div class="row" style="gap:8px;flex-wrap:wrap"><button class="btn primary" id="dgRun">Spustit kontrolu</button><button class="btn" id="dgCopy" hidden>Kopírovat výsledek</button></div>
        <div id="dgOut"></div>
      </div>
      <div class="panel stack"><h2 style="margin:0">Aplikace na ploše</h2><div id="pwaBox" class="stack"></div></div>
      <div class="panel stack"><h2 style="margin:0">Novinky</h2>
        ${log.map(([v, x]) => `<div><b>Verze ${esc(v)}</b> <span class="muted small">${x.date ? fmtDate(x.date) : ''}</span>
          <ul class="small" style="margin:6px 0 0;padding-left:20px;line-height:1.6">${x.items.map((i) => `<li>${esc(i)}</li>`).join('')}</ul></div>`).join('')}
      </div>
      <div class="panel stack"><h2 style="margin:0">Cron</h2>
        <p class="small" style="margin:0">Spouštějte každých 5 minut (Wedos cron) a případně i z vlastního cronu jako zálohu. Souběžné spuštění nevadí.</p>
        <dl class="kv"><dt>URL</dt><dd><code>${esc(st.cron_url)}</code></dd><dt>Příkaz</dt><dd><code>${esc(st.cron_cli)}</code></dd></dl>
        <p class="small muted" style="margin:0">URL obsahuje tajný klíč – nesdílejte ji.</p>
      </div>
      `;
    drawPwaBox();
    drawUpdateBox();
    let dgText = '';
    $('#dgRun').onclick = (e) => busy(e.currentTarget, 'Kontroluji…', async () => {
      try {
        const r = await api('diag');
        if (!$('#dgOut')) return;
        $('#dgOut').innerHTML = drawDiag(r.items);
        dgText = diagText(r);
        $('#dgCopy').hidden = false;
      } catch (err) { toast(err.message, 'error'); }
    });
    $('#dgCopy').onclick = async () => {
      try { await navigator.clipboard.writeText(dgText); toast('Výsledek zkopírován.'); }
      catch { toast('Kopírování se nepodařilo.', 'error'); }
    };
  },
};

// ---------- Aktualizace z TWB Update Serveru ----------
async function drawUpdateBox(st) {
  const box = $('#updBox');
  if (!box) return;
  try { st ??= await api('update_status'); } catch (e) { box.innerHTML = `<div class="errbox">${esc(e.message)}</div>`; return; }
  if (!$('#updBox')) return;
  const r = st.result;
  const bad = r && ['auth_error', 'signature_error', 'server_error', 'config_error', 'hash_error', 'install_error', 'rollback'].includes(r.result);
  const av = st.available;
  box.innerHTML = `
    <dl class="kv"><dt>Nainstalovaná verze</dt><dd><b>${esc(st.version)}</b></dd>
      <dt>Poslední kontrola</dt><dd>${st.checked_at ? fmtDT(new Date(st.checked_at * 1000).toISOString()) : 'zatím ne'}${r && !bad ? ` <span class="muted small">· ${esc(r.message)}</span>` : ''}</dd>
      ${st.token ? `<dt>Token</dt><dd><code>${esc(st.token)}</code></dd>` : ''}</dl>
    ${st.unsupported ? `<div class="warnbox">${esc(st.unsupported)}</div>` : ''}
    ${bad ? `<div class="errbox">${esc(r.message)}</div>` : ''}
    ${av ? `<div class="infobox"><b>Je k dispozici verze ${esc(av.version)}</b>${av.released_at ? ` <span class="muted small">(vydána ${esc(fmtDT(av.released_at))})</span>` : ''} · ${fmtSize(av.size)}</div>` : ''}
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <button class="btn${av ? '' : ' primary'}" id="updCheck" ${st.unsupported ? 'disabled' : ''}>Zjistit novou verzi</button>
      ${av ? `<button class="btn primary" id="updInstall">Aktualizovat na ${esc(av.version)}</button>` : ''}
      ${st.previous ? `<button class="btn ghost" id="updBack">Vrátit předchozí verzi ${esc(st.previous)}</button>` : ''}
    </div>
    <p class="small muted" style="margin:0">Aplikace se ptá jednou denně (cron). Instaluje se jen po kliknutí: stáhne se podepsaný balíček, zazálohuje databáze
      a uloží současná verze; když nová verze nefunguje, vrátí se předchozí. <code>config.php</code> a data zůstávají.</p>`;
  $('#updCheck').onclick = (e) => busy(e.currentTarget, 'Zjišťuji…', async () => {
    try { const s = await api('update_check', {}); drawUpdateBox(s); toast(s.available ? `Je k dispozici verze ${s.available.version}.` : (s.result?.message || 'Hotovo.'), s.available ? 'success' : undefined); }
    catch (err) { toast(err.message, 'error'); }
  });
  if ($('#updInstall')) $('#updInstall').onclick = (e) => {
    if (!confirm(`Aktualizovat TWB Plánovač médií z verze ${st.version} na ${av.version}?\n\nPřed instalací se vytvoří záloha databáze a uloží se současná verze. Instalace trvá obvykle do minuty – nezavírejte stránku.`)) return;
    busy(e.currentTarget, 'Aktualizuji…', async () => {
      try {
        const res = await api('update_install', { version: av.version });
        if (res.ok) { toast(`${res.message} Stránka se načte znovu.`, 'success'); setTimeout(() => location.reload(), 1500); }
        else { toast(res.message, 'error'); drawUpdateBox(res.status); }
      } catch (err) { toast(err.message, 'error'); drawUpdateBox(); }
    });
  };
  if ($('#updBack')) $('#updBack').onclick = (e) => {
    if (!confirm(`Vrátit předchozí verzi ${st.previous}?\n\nSoubory aplikace se nahradí uloženou verzí. Pokud nová verze změnila databázi, obnoví se i databáze ze zálohy před aktualizací (změny od aktualizace se ztratí).`)) return;
    busy(e.currentTarget, 'Vracím…', async () => {
      try { const r = await api('update_rollback', {}); toast(`Vrácena verze ${r.version}${r.db_restored ? ' včetně databáze' : ''}. Stránka se načte znovu.`, 'success'); setTimeout(() => location.reload(), 1500); }
      catch (err) { toast(err.message, 'error'); }
    });
  };
}

// ---------- Diagnostika ----------
const plural = (n, a, b, c) => (n === 1 ? a : n >= 2 && n <= 4 ? b : c);
const DIAG_MARK = { ok: ['✓', 'V pořádku'], warn: ['!', 'Pozor'], error: ['✕', 'Chyba'], info: ['i', 'Informace'] };
function drawDiag(items) {
  const cnt = { ok: 0, warn: 0, error: 0, info: 0 };
  items.forEach((i) => cnt[i.status]++);
  const groups = [...new Set(items.map((i) => i.group))];
  const sum = cnt.error ? `<span class="dg-sum error">✕ ${cnt.error} ${plural(cnt.error, 'chyba', 'chyby', 'chyb')}</span>` : '';
  const sumW = cnt.warn ? `<span class="dg-sum warn">! ${cnt.warn} ${plural(cnt.warn, 'upozornění', 'upozornění', 'upozornění')}</span>` : '';
  return `<div class="dg-head">${sum}${sumW}${!cnt.error && !cnt.warn ? '<span class="dg-sum ok">✓ Vše v pořádku</span>' : ''}
      <span class="muted small">${cnt.ok} v pořádku · ${new Date().toLocaleString('cs-CZ')}</span></div>` +
    groups.map((g) => `<div class="dg-group"><h3>${esc(g)}</h3>${items.filter((i) => i.group === g).map((i) => `
      <div class="dg-item ${i.status}"><span class="dg-mark" title="${DIAG_MARK[i.status][1]}">${DIAG_MARK[i.status][0]}</span>
        <div><div><b>${esc(i.name)}</b> <span class="dg-val">${esc(i.value)}</span></div>${i.hint ? `<div class="small dg-hint">${esc(i.hint)}</div>` : ''}</div></div>`).join('')}</div>`).join('');
}
function diagText(r) {
  const lines = [`TWB Plánovač médií ${r.version} – diagnostika ${new Date().toLocaleString('cs-CZ')}`];
  let g = '';
  for (const i of r.items) {
    if (i.group !== g) { g = i.group; lines.push('', `== ${g} ==`); }
    lines.push(`[${DIAG_MARK[i.status][1].toUpperCase()}] ${i.name}: ${i.value}${i.hint ? ` → ${i.hint}` : ''}`);
  }
  return lines.join('\n');
}

// ---------- Přehrávač SlideShow: skript pro Synology ----------
/** Text v jednoduchých uvozovkách pro shell. */
const shq = (v) => `'${String(v).replace(/'/g, `'"'"'`)}'`;
function playerScript(st, v) {
  const q = `url=${encodeURIComponent('gdrive://' + st.folder_id)}&method=GET&target=${encodeURIComponent(v.target || '/')}&clearFolder=${v.clear ? 'true' : 'false'}`;
  const days = Number.isFinite(+st.backup_nas_days) ? +st.backup_nas_days : 90;
  return `#!/bin/sh
# TWB Plánovač médií – skript pro Synology (Plánovač úloh, každou minutu, uživatel root).
# 1) Po změně na Google Disku spustí synchronizaci přehrávače SlideShow (přehrávač zůstává jen v místní síti).
# 2) Jednou za hodinu si stáhne nové zálohy aplikace a smaže zálohy starší než ${days || 'nikdy'} ${days ? 'dní' : ''}.

PLAYER_PASS='SEM-DOPLNTE-HESLO'

SIGNAL=${shq(st.signal_url)}
PLAYER=${shq(v.host || 'http://ADRESA-PREHRAVACE:8080')}
PLAYER_USER=${shq(v.user)}
SYNC=${shq(q)}
STATE=/tmp/planovac-medii.stav

BACKUP_URL=${shq(st.signal_url.replace('/signal.php?', '/backup.php?'))}
BACKUP_DIR=${shq(st.backup_nas_dir || '/volume1/zaloha/twb-media-planner')}
BACKUP_DAYS=${days}
BACKUP_STAMP=/tmp/planovac-medii.zalohy

sync_player() {
  LAST=$(cat "$STATE" 2>/dev/null)
  case "$LAST" in ''|*[!0-9]*) LAST=0 ;; esac
  CHANGE=$(curl -fsS --max-time 20 "$SIGNAL&done=$LAST") || return
  case "$CHANGE" in ''|*[!0-9]*) return ;; esac
  [ "$CHANGE" -gt "$LAST" ] || return
  RESP=$(curl -sS --max-time 30 -u "$PLAYER_USER:$PLAYER_PASS" -X POST "$PLAYER/ajax/synchronize?$SYNC") || return
  case "$RESP" in
    *'"success":true'*|*'"success": true'*)
      echo "$CHANGE" > "$STATE"
      curl -fsS --max-time 20 -o /dev/null "$SIGNAL&done=$CHANGE" ;;
  esac
}

pull_backups() {
  HOUR=$(date +%Y%m%d%H)
  [ "$(cat "$BACKUP_STAMP" 2>/dev/null)" = "$HOUR" ] && return
  mkdir -p "$BACKUP_DIR" || return
  LIST=$(curl -fsS --max-time 30 "$BACKUP_URL&list=1") || return
  OK=1
  echo "$LIST" | while read -r NAME SIZE; do
    case "$NAME" in twb-planovac-zaloha-*.zip) ;; *) continue ;; esac
    [ -f "$BACKUP_DIR/$NAME" ] && continue
    if curl -fsS --max-time 900 -o "$BACKUP_DIR/$NAME.part" "$BACKUP_URL&get=$NAME" \
       && [ "$(wc -c < "$BACKUP_DIR/$NAME.part" | tr -d ' ')" = "$SIZE" ]; then
      mv "$BACKUP_DIR/$NAME.part" "$BACKUP_DIR/$NAME"
    else
      rm -f "$BACKUP_DIR/$NAME.part"; exit 1
    fi
  done || OK=0
  [ "$OK" = 1 ] && echo "$HOUR" > "$BACKUP_STAMP"
  [ "$BACKUP_DAYS" -gt 0 ] && find "$BACKUP_DIR" -maxdepth 1 -name 'twb-planovac-zaloha-*.zip' -type f -mtime +"$BACKUP_DAYS" -delete
}

sync_player
pull_backups
exit 0
`;
}
function drawPlayerPanel(st) {
  const box = $('#playerPanel');
  const ago = (iso) => { const m = Math.round((Date.now() - new Date(iso)) / 60000); return m < 1 ? 'právě teď' : m < 60 ? `před ${m} min` : fmtDT(iso); };
  const poll = st.player_poll_at
    ? `<span class="dot ${Date.now() - new Date(st.player_poll_at) < 5 * 60000 ? 'on' : ''}"></span>Synology se naposledy ozval <b>${ago(st.player_poll_at)}</b>`
    : '<span class="dot"></span>Synology se zatím neozval – skript ještě neběží.';
  const change = !st.last_change_at ? 'Na Disku zatím nebyla žádná změna.'
    : `Poslední změna na Disku: <b>${fmtDT(st.last_change_at)}</b> – ${st.player_done >= st.last_change_ts ? '<span style="color:var(--ok)">přehrávač spuštěn ✓</span>' : 'čeká na spuštění přehrávače'}`;
  box.innerHTML = `<h2 style="margin:0">Přehrávač SlideShow</h2>
    <p class="small" style="margin:0">Po každé změně ve složce spustí synchronizaci přehrávače, takže se nová média objeví do minuty. Přehrávač zůstává jen v místní síti:
      skript na Synology se každou minutu zeptá aplikace a přehrávač pak volá v místní síti. Heslo k přehrávači zůstává jen na Synology.</p>
    <div class="small">${poll}<br>${change}</div>
    <div class="cols2">
      <label class="field">Adresa přehrávače v místní síti<input type="text" id="plHost" value="${esc(st.player_host.replace(/^http:\/\//, ''))}" placeholder="192.168.99.249:8080"></label>
      <label class="field">Uživatel přehrávače<input type="text" id="plUser" value="${esc(st.player_user)}"></label>
    </div>
    <div class="cols2">
      <label class="field">Cílová složka v přehrávači<input type="text" id="plTarget" value="${esc(st.player_target)}"></label>
      <label class="check-row" style="align-self:end"><input type="checkbox" id="plClear" ${st.player_clear ? 'checked' : ''}><span>Mazat staré soubory v přehrávači (jako v jeho nastavení synchronizace)</span></label>
    </div>
    ${st.folder_id ? '' : '<div class="warnbox">Nejdřív nastavte cílovou složku na Google Disku – skript ji potřebuje.</div>'}
    <label class="field">Skript pro Synology <span class="hint">spouští synchronizaci přehrávače a stahuje zálohy (Nastavení → Zálohy) · doplňte heslo na řádku PLAYER_PASS</span>
      <textarea id="plScript" rows="12" readonly spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;white-space:pre"></textarea></label>
    <div class="row"><button class="btn primary" id="tSave">Uložit</button><button class="btn" id="plCopy" data-nodirty>Kopírovat skript</button><span class="grow"></span>
      <button class="btn ghost danger" id="plKey" title="Starý skript přestane fungovat">Nový klíč</button></div>
    <details><summary><b>Návod pro Synology (DSM 6.2)</b></summary><ol class="small" style="margin:8px 0 0;padding-left:20px;line-height:1.6">
      <li>Vyplňte výše adresu a uživatele přehrávače a klikněte na <b>Uložit</b>.</li>
      <li>Klikněte na <b>Kopírovat skript</b>.</li>
      <li>V DSM otevřete <b>Ovládací panel → Plánovač úloh → Vytvořit → Naplánovaná úloha → Uživatelem definovaný skript</b>.</li>
      <li>Karta <b>Obecné</b>: název např. „TWB Plánovač médií – přehrávač“, uživatel <b>root</b>, zaškrtnuto <b>Povoleno</b>.</li>
      <li>Karta <b>Plán</b>: <b>Spustit denně</b>, čas spuštění <b>00:00</b>, frekvence <b>Každou minutu</b>, čas posledního spuštění <b>23:59</b>.</li>
      <li>Karta <b>Nastavení úlohy</b>: do pole <b>Uživatelem definovaný skript</b> vložte skript a na řádku <code>PLAYER_PASS='…'</code> nahraďte text SEM-DOPLNTE-HESLO heslem k přehrávači (heslo nesmí obsahovat apostrof).</li>
      <li>Uložte. Do minuty by se tu mělo objevit „Synology se naposledy ozval právě teď“ (stránku obnovte).</li>
    </ol>
    <p class="small muted">Když se Synology neozve: úlohu označte a klikněte na <b>Spustit</b>. Pokud se pak stále neozve, Synology se nejspíš nedostane na internet nebo nedůvěřuje certifikátu webu – v DSM spusťte <b>Aktualizace a obnovení</b>.
      Pravidelnou synchronizaci v přehrávači (3600 s) nechte zapnutou jako zálohu.</p></details>`;
  const vals = () => ({ host: (() => { const h = $('#plHost').value.trim().replace(/\/+$/, ''); return h ? (/^https?:\/\//i.test(h) ? h : 'http://' + h) : ''; })(),
    user: $('#plUser').value.trim(), target: $('#plTarget').value.trim() || '/', clear: $('#plClear').checked });
  const upd = () => { $('#plScript').value = playerScript(st, vals()); };
  ['#plHost', '#plUser', '#plTarget'].forEach((id) => { $(id).oninput = upd; });
  $('#plClear').onchange = upd;
  upd();
  $('#tSave').onclick = (e) => saveTab(e.currentTarget, { player_host: $('#plHost').value, player_user: $('#plUser').value, player_target: $('#plTarget').value, player_clear: $('#plClear').checked });
  $('#plCopy').onclick = async () => {
    try { await navigator.clipboard.writeText($('#plScript').value); toast('Skript zkopírován. Nezapomeňte doplnit heslo.'); }
    catch { $('#plScript').select(); toast('Označeno – zkopírujte Ctrl+C.', 'warn'); }
  };
  $('#plKey').onclick = async () => {
    if (!confirm('Vygenerovat nový klíč? Skript na Synology pak přestane fungovat, dokud ho nenahradíte novým.')) return;
    await api('player_key_new', {}); toast('Nový klíč vytvořen – vložte do Synology nový skript.'); route();
  };
}

route();
