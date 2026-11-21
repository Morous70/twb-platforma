'use strict';
// Vrstva pro editor slidů převzatý z TWB Plánovače médií (slide-editor.js): dodává pomocné funkce, které editor
// v Plánovači bral z app.js (api, modal, toast, knihovna médií, tagy …), napojené na Social Planner.
/* eslint-disable no-unused-vars */
function $(s, el = document) { return el.querySelector(s); }
function $$(s, el = document) { return [...el.querySelectorAll(s)]; }
const esc = App.esc;
const isTouch = matchMedia('(pointer: coarse)').matches;
let timer = 0;
const main = document.getElementById('main');
let FORMATS = [{ key: 'square', w: 1080, h: 1080, name: 'Čtverec 1 : 1' }];
const NEW_FORMAT = new URLSearchParams(location.search).get('format') || 'square';

/** api(akce, tělo): tělo se posílá jako JSON (FormData beze změny). */
async function api(action, body) {
  const opt = body === undefined ? {} : {
    method: 'POST',
    headers: body instanceof FormData ? { 'X-CSRF': window.CSRF } : { 'X-CSRF': window.CSRF, 'Content-Type': 'application/json' },
    body: body instanceof FormData ? body : JSON.stringify(body),
  };
  let r, text;
  try { r = await fetch('api.php?action=' + action, opt); text = await r.text(); }
  catch { throw new Error('Nelze se spojit se serverem – zkontrolujte připojení k internetu.'); }
  if (r.status === 401) { location.href = 'login.php'; throw new Error('Přihlášení vypršelo.'); }
  let j;
  try { j = JSON.parse(text); } catch { throw new Error(`Server vrátil neočekávanou odpověď (HTTP ${r.status}): ${text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200)}`); }
  if (!r.ok || (j && j.error)) throw new Error(j.error || `Chyba serveru (HTTP ${r.status}).`);
  return j;
}

function toast(msg, type = 'ok') {
  const el = document.createElement('div');
  el.className = 'toast ' + type;
  el.innerHTML = `<span class="ti">${type === 'ok' ? '✓' : '!'}</span><span>${esc(msg)}</span>`;
  el.title = 'Kliknutím zavřete';
  el.onclick = () => el.remove();
  $('#toasts').append(el);
  setTimeout(() => el.remove(), type === 'error' ? 15000 : 4000);
}
window.addEventListener('unhandledrejection', (e) => toast(e.reason?.message || String(e.reason), 'error'));

function openModal(html) { $('#modalBox').classList.remove('wide', 'full'); $('#modalBox').innerHTML = html; $('#modal').hidden = false; }
function closeModal() { $('#modal').hidden = true; $('#modalBox').innerHTML = ''; $('#modalBox').classList.remove('wide', 'full'); }
document.getElementById('modal').addEventListener('click', (e) => { if (e.target.id === 'modal' || e.target.closest('[data-close]')) closeModal(); });
document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#modal').hidden) closeModal(); });

async function busy(btn, label, fn) {
  if (!btn || btn.disabled) return;
  const orig = btn.innerHTML;
  btn.disabled = true; btn.classList.add('busy');
  btn.innerHTML = `<span class="spin"></span>${label}`;
  try { return await fn(); } finally { if (btn.isConnected) { btn.disabled = false; btn.classList.remove('busy'); btn.innerHTML = orig; } }
}

const fmtTime = (s) => s ? new Date(typeof s === 'number' ? s * 1000 : String(s).replace(' ', 'T')).toLocaleString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
const fmtDataValue = (v) => {
  const n = Number(String(v).replace(/\s/g, '').replace(',', '.'));
  if (v === '' || v == null || isNaN(n)) return v ?? '';
  if (Math.abs(n) >= 1e9) return (n / 1e9).toLocaleString('cs-CZ', { maximumFractionDigits: 2 }) + ' mld.';
  if (Math.abs(n) >= 1e6) return (n / 1e6).toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) + ' mil.';
  return n.toLocaleString('cs-CZ');
};

/* ---------- knihovna médií jako „store.media“ z Plánovače ---------- */
const store = { media: [], archive: [] };
async function loadLibrary() {
  const r = await App.api('library');
  const conv = (i) => ({ id: i.id, url: i.url, kind: i.slide ? 'slide' : i.type, original_name: i.name, tags: i.tags || [], archived: i.archived });
  store.media = r.items.filter((i) => !i.archived).map(conv);
  store.archive = r.items.filter((i) => i.archived).map(conv);
}
const mediaById = (id) => store.media.find((m) => m.id === id) || store.archive.find((m) => m.id === id);
function thumb(m) {
  if (!m) return '<div class="tb"><div class="ph">?</div></div>';
  return m.kind === 'video' ? `<video src="${esc(m.url)}#t=0.5" preload="metadata" muted playsinline></video>` : `<img src="${esc(m.url)}" alt="" loading="lazy">`;
}
const helpBtn = () => '<a class="btn ghost" href="napoveda.php#slidy" target="_blank" rel="noopener" title="Nápověda k editoru slidů">?</a>';
const groupList = () => '';
const currentTags = () => { const t = new URLSearchParams(location.search).get('tag'); return t ? [t] : []; };
function route() { location.href = new URLSearchParams(location.search).get('for') === 'novy' ? 'novy.php' : 'knihovna.php'; }

/* ---------- tagy (Tags z tags.js) ---------- */
const newTagState = () => Tags.newState();
const tagOk = (m, st) => Tags.ok(m, st);
const tagText = (m) => (m.tags || []).join(' ');
const tagBar = (box, list, st, cb) => Tags.bar(box, list, st, cb);
function editTagsModal(tags, cb) {
  openModal(`<div class="row" style="margin-bottom:12px"><h2 class="grow" style="margin:0">Tagy slidu</h2><button class="btn sm" data-close>Zavřít</button></div>
    <p class="hint small">Tagy slouží k třídění a hledání v Knihovně médií. Enter nebo čárka tag potvrdí.</p>
    <div id="stField"></div><div class="row" style="justify-content:flex-end;margin-top:12px"><button class="btn primary" id="stOk">Použít</button></div>`);
  const known = Tags.allTags([...store.media, ...store.archive]);
  const f = Tags.field($('#stField'), tags, known);
  $('#stOk').onclick = () => { try { cb(f.get()); closeModal(); } catch { /* hláška už je */ } };
}

/* ---------- čas náhledu a uložení ---------- */
function whenTs() { const v = $('#seWhen')?.value; const d = v ? new Date(v) : null; return d && !isNaN(d) ? Math.floor(d.getTime() / 1000) : 0; }
/** Po uložení: když editor otevřel Nový příspěvek (?for=novy), pošle mu slide k vložení. */
function slideSaved(media) {
  if (new URLSearchParams(location.search).get('for') === 'novy' && window.opener && !window.opener.closed) {
    window.opener.postMessage({ type: 'sp-slide-saved', id: media.id, file: media.file }, location.origin);
  }
  loadLibrary().catch(() => {});
}

/* ---------- vlastní písma ---------- */
async function manageFonts(onChange) {
  let list = [];
  const draw = async () => {
    list = await api('fonts');
    const custom = list.filter((f) => f.custom);
    openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Písma pro slidy</h2><button class="btn sm" data-close>Zavřít</button></div>
      <p class="hint small">Vestavěná písma: ${list.filter((f) => !f.custom).map((f) => esc(f.name)).join(', ')}. Vlastní písmo nahrajte jako soubor TTF nebo OTF (nejvýš 5 MB) – vyzkoušejte, že má české znaky.
        Písma se ukládají do app/data/fonts a jsou i v zálohách.</p>
      <div class="row" style="gap:8px;margin-bottom:12px"><input type="file" id="fFile" accept=".ttf,.otf"><input type="text" id="fName" placeholder="Název (nepovinný)" style="max-width:220px"><button class="btn primary" id="fUp">Nahrát</button></div>
      ${custom.length ? custom.map((f) => `<div class="row" style="border-top:1px solid var(--line);padding:8px 0"><b class="grow" style="font-family:'mp-${esc(f.key)}'">${esc(f.name)}</b>
        <button class="btn sm" data-fren="${esc(f.key)}">Přejmenovat</button><button class="btn sm danger" data-fdel="${esc(f.key)}">Smazat</button></div>`).join('') : '<p class="hint">Zatím žádná vlastní písma.</p>'}`);
    $('#fUp').onclick = (ev) => busy(ev.currentTarget, 'Nahrávám…', async () => {
      const file = $('#fFile').files[0]; if (!file) { toast('Vyberte soubor s písmem.', 'error'); return; }
      const fd = new FormData(); fd.append('font', file); fd.append('name', $('#fName').value);
      try {
        const r = await api('font_upload', fd);
        toast(r.missing.length ? `Písmo „${r.font.name}“ nahráno, ale chybí mu české znaky: ${r.missing.join(' ')}` : `Písmo „${r.font.name}“ nahráno.`, r.missing.length ? 'warn' : 'ok');
        await onChange(); await draw();
      } catch (e) { toast(e.message, 'error'); }
    });
    $$('#modalBox [data-fren]').forEach((b) => b.onclick = async () => {
      const f = list.find((x) => x.key === b.dataset.fren); const n = prompt('Nový název písma:', f.name);
      if (!n || !n.trim()) return;
      try { await api('font_rename', { key: f.key, name: n.trim() }); await onChange(); await draw(); } catch (e) { toast(e.message, 'error'); }
    });
    $$('#modalBox [data-fdel]').forEach((b) => b.onclick = async () => {
      const f = list.find((x) => x.key === b.dataset.fdel);
      if (!confirm(`Smazat písmo „${f.name}“?`)) return;
      try { await api('font_delete', { key: f.key }); await onChange(); await draw(); } catch (e) { toast(e.message, 'error'); }
    });
  };
  try { await draw(); } catch (e) { toast(e.message, 'error'); }
}
