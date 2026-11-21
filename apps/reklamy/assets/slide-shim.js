'use strict';
// Vrstva pro editor slidů z modulu slidy (slide-editor.js): dodává pomocné funkce, které editor potřebuje
// (api, okna, hlášky, galerie, písma …), napojené na TWB Reklamy. Slide = banner, formáty plátna = rozměry banerů.
/* eslint-disable no-unused-vars */
function $(s, el = document) { return el.querySelector(s); }
function $$(s, el = document) { return [...el.querySelectorAll(s)]; }
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const isTouch = matchMedia('(pointer: coarse)').matches;
let timer = 0;
const main = document.getElementById('main');
const URL_P = new URLSearchParams(location.search);
let FORMATS = [{ key: '300x250', w: 300, h: 250, name: 'Medium rectangle' }];
/** Formát nového slidu: běžný rozměr („300x250“), jinak vlastní. */
const NEW_FORMAT = (() => {
  const w = +URL_P.get('w') || 0, h = +URL_P.get('h') || 0;
  if (!w || !h) return '300x250';
  return (window.REKLAMY.formaty || []).includes(`${w}x${h}`) ? `${w}x${h}` : 'vlastni';
})();

/** api(akce, tělo): tělo se posílá jako JSON (FormData beze změny). */
async function api(action, body) {
  let q = action;
  if (action === 'slide_formats') ['id', 'w', 'h'].forEach((k) => { if (URL_P.get(k)) q += `&${k}=${encodeURIComponent(URL_P.get(k))}`; });
  if (action === 'slide_save' && body && !body.id) body = { ...body, kampan: window.REKLAMY.kampan };
  const opt = body === undefined ? {} : {
    method: 'POST',
    headers: body instanceof FormData ? { 'X-CSRF': window.REKLAMY.csrf } : { 'X-CSRF': window.REKLAMY.csrf, 'Content-Type': 'application/json' },
    body: body instanceof FormData ? body : JSON.stringify(body),
  };
  let r, text;
  try { r = await fetch('api.php?a=' + q, opt); text = await r.text(); }
  catch { throw new Error('Nelze se spojit se serverem – zkontrolujte připojení k internetu.'); }
  if (r.status === 401) { location.href = 'index.php'; throw new Error('Přihlášení vypršelo.'); }
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

/* ---------- galerie jako „store.media“ ---------- */
const store = { media: [], archive: [] };
async function loadLibrary() { store.media = (await api('knihovna')).items; }
const mediaById = (id) => store.media.find((m) => m.id === id);
function thumb(m) { return m ? `<img src="${esc(m.url)}" alt="" loading="lazy">` : '<div class="tb"><div class="ph">?</div></div>'; }
const helpBtn = () => '';
const currentTags = () => [];
function route() { location.href = window.REKLAMY.zpet; }

/* ---------- tagy: bannery tagy nemají (třídí se kampaněmi), výběr obrázku je bez filtru ---------- */
const newTagState = () => ({});
const tagOk = () => true;
const tagText = (m) => (m.tags || []).join(' ');
function tagBar(box) { if (box) box.hidden = true; }
function editTagsModal(tags, cb) { cb(tags); }

/* ---------- čas náhledu a uložení ---------- */
function whenTs() { const v = $('#seWhen')?.value; const d = v ? new Date(v) : null; return d && !isNaN(d) ? Math.floor(d.getTime() / 1000) : 0; }
/** Po uložení: editor sám přepíše adresu na slide.php?id=…; tady jen titulek okna a odkaz na banner. */
function slideSaved(media) {
  document.title = `${media.name} – TWB Reklamy`;
  window.REKLAMY.banner = media.id;
}

/* ---------- vlastní písma ---------- */
async function manageFonts(onChange) {
  let list = [];
  const draw = async () => {
    list = await api('fonts');
    const custom = list.filter((f) => f.custom);
    openModal(`<div class="row" style="margin-bottom:10px"><h2 class="grow" style="margin:0">Písma pro slidy</h2><button class="btn sm" data-close>Zavřít</button></div>
      <p class="hint small">Vestavěná písma: ${list.filter((f) => !f.custom).map((f) => esc(f.name)).join(', ')}. Vlastní písmo nahrajte jako soubor TTF nebo OTF (nejvýš 5 MB) – vyzkoušejte, že má české znaky.
        Písma se ukládají do data/fonts a jsou i v zálohách.</p>
      <div class="row" style="gap:8px;margin-bottom:12px"><input type="file" id="fFile" accept=".ttf,.otf"><input type="text" id="fName" placeholder="Název (nepovinný)" style="max-width:220px"><button class="btn primary" id="fUp">Nahrát</button></div>
      ${custom.length ? custom.map((f) => `<div class="row" style="border-top:1px solid var(--line);padding:8px 0"><b class="grow">${esc(f.name)}</b>
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
