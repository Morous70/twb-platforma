'use strict';
// Knihovna médií (od 1.4.0): tagy místo alb, archiv, zvětšený náhled s listováním. Vzor: Galerie v TWB Plánovači médií.
const { $, $$, esc, norm, api, form, flash, icons, modal, modalHide, modalOpen } = App;
const T = window.Tags;
let data = { items: [], tags: [], total_size: 0, archive: { count: 0, size: 0 } };
let view = 'all';                 // 'all' | 'archive'
const tagSt = T.newState();
const sel = new Set();
const kb = (b) => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' kB';
const byId = (id) => data.items.find((x) => x.id === id);
const pool = () => data.items.filter((i) => view === 'archive' ? i.archived : !i.archived);
const knownTags = () => T.allTags(data.items);
const plural = (n, a, b, c) => n === 1 ? a : (n >= 2 && n <= 4 ? b : c);

function visible() {
  const q = norm($('#q').value.trim()), t = $('#typeF').value;
  return pool().filter((i) => (!q || norm(i.name).includes(q) || (i.tags || []).some((x) => norm(x).includes(q))) &&
    (!t || i.type === t) && T.ok(i, tagSt));
}
function usedText(i) {
  if (!i.used) return '';
  return ` · <span class="text-primary" title="${i.used_upcoming ? `z toho ${i.used_upcoming} čeká na zveřejnění` : 'všechny už zveřejněné'}">v ${i.used} ${plural(i.used, 'příspěvku', 'příspěvcích', 'příspěvcích')}${i.used_upcoming ? ` (${i.used_upcoming} čeká)` : ''}</span>`;
}
function renderBar() {
  const a = data.archive;
  T.bar($('#tagBar'), pool(), tagSt, render, {
    onDropTag: (name, ids) => addTags(ids, [name]),
    extra: `<span class="flex-grow-1"></span><button type="button" class="btn btn-sm tag-chip arch ${view === 'archive' ? 'on' : ''}" id="archBtn"
      title="${view === 'archive' ? 'Zpět na soubory v knihovně' : 'Soubory v archivu: dál se publikují, jen se nenabízejí ve výběru'}">🗄 Archiv <span class="cnt">${a.count}${a.count ? ' · ' + kb(a.size) : ''}</span></button>`,
  });
  $('#archBtn').onclick = () => { view = view === 'archive' ? 'all' : 'archive'; sel.clear(); tagSt.sel.clear(); tagSt.none = false; render(); };
}
function card(i) {
  return `<div class="lib-card${sel.has(i.id) ? ' sel' : ''}${i.archived ? ' archived' : ''}" data-id="${i.id}"${T.isTouch ? '' : ' draggable="true"'}>
    <label class="lib-check"><input type="checkbox" ${sel.has(i.id) ? 'checked' : ''} aria-label="Vybrat"></label>
    <button type="button" class="lib-zoom" data-zoom="${i.id}" title="Zvětšit" aria-label="Zvětšit">⤢</button>
    ${i.slide ? '<span class="lib-slide" title="Slide – obrázek z editoru; před zveřejněním se aktualizuje">Slide</span>' : ''}
    <div class="lib-thumb">${i.type === 'video' ? `<video src="${esc(i.url)}#t=0.1" muted preload="metadata"></video><span class="lib-vid">▶</span>` : `<img src="${esc(i.url)}" alt="" loading="lazy">`}</div>
    <div class="lib-meta">
      <div class="lib-name text-truncate" data-rename="${i.id}" title="Přejmenovat">${esc(i.name)}</div>
      <div class="small text-muted">${i.w ? i.w + '×' + i.h + ' · ' : ''}${kb(i.size)}${usedText(i)}</div>
      ${i.credit_text ? `<div class="small text-truncate ${i.credit_needed ? 'text-warning-emphasis' : 'text-muted'}" title="${esc(i.credit_text)}${i.credit_needed ? ' – v příspěvku uveďte autora' : ''}">© ${esc(i.credit_text)}</div>` : ''}
      ${T.chips(i)}
      <div class="lib-acts">
        <button type="button" class="btn btn-sm btn-link p-0" data-etags="${i.id}">🏷 Tagy</button>
        ${i.slide ? `<a class="btn btn-sm btn-link p-0" href="slide.php?id=${i.id}">✎ Upravit</a>` : ''}
        <button type="button" class="btn btn-sm btn-link p-0" data-arch="${i.id}">${i.archived ? '↩ Vrátit' : '🗄 Archiv'}</button>
      </div>
    </div></div>`;
}
function render() {
  renderBar();
  const list = visible();
  $('#grid').innerHTML = list.length ? list.map(card).join('')
    : `<div class="text-muted">${view === 'archive' ? 'Archiv je prázdný.' : pool().length ? 'Filtru nic neodpovídá.' : 'Tady zatím nic není.'}</div>`;
  $('#grid').dataset.ids = list.map((i) => i.id).join(',');
  $('#libInfo').textContent = `${data.items.length} souborů · ${kb(data.total_size)}`;
  $('#archNote').hidden = view !== 'archive';
  $('#selBar').hidden = !sel.size;
  $('#selN').textContent = `Vybráno ${sel.size}`;
  $('#selArch').textContent = view === 'archive' ? '↩ Vrátit z archivu' : '🗄 Do archivu';
}
async function load() {
  data = await api('library');
  sel.forEach((id) => { const it = byId(id); if (!it || it.archived !== (view === 'archive')) sel.delete(id); });
  render(); icons();
}
const fdIds = (ids, extra = {}) => { const f = form(extra); ids.forEach((i) => f.append('ids[]', i)); return f; };

async function addTags(ids, tags) {
  try {
    const f = fdIds(ids); tags.forEach((t) => f.append('add[]', t));
    const r = await api('library_tags', f);
    flash(r.changed ? `Tag přidán: ${r.changed} ${plural(r.changed, 'soubor', 'soubory', 'souborů')}.` : 'Soubory už tag mají.');
    await load();
  } catch (err) { flash(err.message, 'danger'); }
}
async function archive(ids, on) {
  try {
    const r = await api('library_archive', fdIds(ids, { archive: on ? 1 : 0 }));
    flash(on ? `Do archivu: ${r.changed}. Naplánované příspěvky je dál použijí.` : `Vráceno z archivu: ${r.changed}.`);
    sel.clear(); await load();
  } catch (err) { flash(err.message, 'danger'); }
}

/* ---------- okno s tagy: jeden soubor (nastavit) nebo víc (přidat / odebrat) ---------- */
function tagsModal(ids) {
  const items = ids.map(byId).filter(Boolean);
  const one = items.length === 1;
  const present = T.allTags(items);
  modal(one ? `Tagy: ${items[0].name}` : `Tagy u vybraných souborů (${items.length})`, `
    <p class="text-muted small">Tagy slouží k třídění a hledání v knihovně a ve výběru v Novém příspěvku. Enter nebo čárka tag potvrdí; nejvýš 10 tagů po 30 znacích.</p>
    <label class="form-label">${one ? 'Tagy souboru' : 'Přidat tagy vybraným'}</label><div id="tmField"></div>
    <div class="text-end mt-2"><button type="button" class="btn btn-primary" id="tmOk">${one ? 'Uložit' : 'Přidat vybraným'}</button></div>
    ${!one && present.length ? `<hr><label class="form-label">Odebrat z vybraných <span class="text-muted small">(klikněte na tag)</span></label>
      <div class="d-flex flex-wrap gap-1">${present.map((t) => `<button type="button" class="btn btn-sm btn-outline-danger" data-rmtag="${esc(t)}">${esc(t)} ×</button>`).join('')}</div>` : ''}`, 'md');
  const f = T.field($('#tmField'), one ? items[0].tags : [], knownTags());
  $('#tmField .tag-in').focus();
  $('#tmOk').onclick = async () => {
    try {
      const tags = f.get();
      if (one) { const fd = form({ id: items[0].id }); tags.forEach((t) => fd.append('tags[]', t)); await api('library_set_tags', fd); flash('Tagy uloženy.'); }
      else {
        if (!tags.length) return flash('Napište tag, který chcete přidat.', 'warning');
        const fd = fdIds(ids); tags.forEach((t) => fd.append('add[]', t));
        const r = await api('library_tags', fd); flash(`Tagy přidány: ${r.changed} ${plural(r.changed, 'soubor', 'soubory', 'souborů')}.`);
      }
      modalHide(); await load();
    } catch (err) { flash(err.message, 'danger'); }
  };
  $$('#appModal [data-rmtag]').forEach((b) => b.onclick = async () => {
    try {
      const fd = fdIds(ids); fd.append('remove[]', b.dataset.rmtag);
      const r = await api('library_tags', fd); flash(`Tag „${b.dataset.rmtag}“ odebrán: ${r.changed}.`);
      modalHide(); await load();
    } catch (err) { flash(err.message, 'danger'); }
  });
}

/* ---------- správa tagů: přejmenovat (i sloučit), smazat ---------- */
function manageTags() {
  const tags = data.tags;
  modal('Správa tagů', tags.length ? `
    <p class="text-muted small">Přejmenováním na název, který už existuje, se tagy sloučí. Smazáním tagu soubory zůstanou, jen ho ztratí.</p>
    <ul class="list-group">${tags.map((t) => `<li class="list-group-item d-flex align-items-center gap-2">
      <span class="flex-grow-1">🏷 ${esc(t.name)} <span class="badge bg-secondary">${t.count}</span></span>
      <button type="button" class="btn btn-sm btn-outline-primary" data-tren="${esc(t.name)}">Přejmenovat</button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-tdel="${esc(t.name)}">Smazat</button></li>`).join('')}</ul>`
    : '<p class="text-muted mb-0">Zatím nemáte žádné tagy. Přidáte je u souboru tlačítkem 🏷 Tagy nebo hromadně u vybraných souborů.</p>', 'md');
  $$('#appModal [data-tren]').forEach((b) => b.onclick = async () => {
    const n = prompt(`Nový název tagu „${b.dataset.tren}“:`, b.dataset.tren);
    if (!n || !n.trim() || n.trim() === b.dataset.tren) return;
    try { const r = await api('tag_rename', form({ from: b.dataset.tren, to: n.trim() })); flash(`Tag přejmenován (${r.changed} ${plural(r.changed, 'soubor', 'soubory', 'souborů')}).`); await load(); manageTags(); }
    catch (err) { flash(err.message, 'danger'); }
  });
  $$('#appModal [data-tdel]').forEach((b) => b.onclick = async () => {
    if (!confirm(`Smazat tag „${b.dataset.tdel}“? Soubory zůstanou v knihovně.`)) return;
    try { await api('tag_delete', form({ name: b.dataset.tdel })); flash('Tag smazán.'); await load(); manageTags(); }
    catch (err) { flash(err.message, 'danger'); }
  });
}

/* ---------- zvětšený náhled s listováním (šipky, klávesy ←/→, tažení prstem) ---------- */
let pv = null;   // { ids, i }
function preview(id) {
  const ids = ($('#grid').dataset.ids || '').split(',').filter(Boolean).map(Number);
  pv = { ids: ids.includes(id) ? ids : [id], i: Math.max(0, ids.indexOf(id)) };
  showPreview();
}
function showPreview() {
  const it = byId(pv.ids[pv.i]); if (!it) return;
  const n = pv.ids.length;
  modal(it.name, `<div class="pv-head d-flex flex-wrap align-items-center gap-2 mb-2">
      <span class="text-muted small flex-grow-1">${it.w ? it.w + ' × ' + it.h + ' px · ' : ''}${kb(it.size)}${usedText(it)}${it.archived ? ' · <span class="badge bg-secondary">v archivu</span>' : ''}</span>
      ${T.chips(it, 10)}
      ${n > 1 ? `<span class="pv-nav"><button type="button" class="btn btn-sm btn-outline-secondary" id="pvPrev" title="Předchozí (←)" aria-label="Předchozí">←</button>
        <span class="small" id="pvPos">${pv.i + 1} / ${n}</span><button type="button" class="btn btn-sm btn-outline-secondary" id="pvNext" title="Další (→)" aria-label="Další">→</button></span>` : ''}
      ${it.slide ? `<a class="btn btn-sm btn-primary" href="slide.php?id=${it.id}">✎ Upravit slide</a> <button type="button" class="btn btn-sm btn-outline-secondary" id="pvDup">Duplikovat</button>` : ''}
      <a class="btn btn-sm btn-outline-secondary" href="${esc(it.url)}" target="_blank" rel="noopener">Otevřít originál</a></div>
    ${it.type === 'image' && !it.slide ? `<div class="pv-credit small mb-2">${it.credit_text ? `© ${esc(it.credit_text)}${it.credit?.source_url ? ` · <a href="${esc(it.credit.source_url)}" target="_blank" rel="noopener">stránka u zdroje</a>` : ''}${it.credit?.license_url ? ` · <a href="${esc(it.credit.license_url)}" target="_blank" rel="noopener">licence</a>` : ''}
      ${it.credit_needed ? ' <span class="badge bg-warning text-dark">v příspěvku uveďte autora</span>' : ''} <button type="button" class="btn btn-sm btn-link p-0 align-baseline" id="pvCopyCredit">Kopírovat uvedení autora</button>` : '<span class="text-muted">Autor a licence neuvedeny.</span>'}
      <button type="button" class="btn btn-sm btn-link p-0 align-baseline ms-2" id="pvCredit">Autor a licence…</button></div>` : ''}
    <div class="pv-body" id="pvBody">${it.type === 'video' ? `<video src="${esc(it.url)}" controls autoplay></video>` : `<img src="${esc(it.url)}" alt="">`}</div>`, 'xl');
  $('#pvPrev')?.addEventListener('click', () => go(-1));
  $('#pvCredit')?.addEventListener('click', () => Stock.editCredit(it, load));
  $('#pvCopyCredit')?.addEventListener('click', async () => {
    const t = Stock.creditLine(it);
    try { await navigator.clipboard.writeText(t); flash('Zkopírováno: ' + t); } catch { prompt('Zkopírujte:', t); }
  });
  $('#pvDup')?.addEventListener('click', async () => {
    try { const r = await api('slide_duplicate', form({ id: it.id })); modalHide(); flash('Kopie slidu vytvořena.'); location.href = `slide.php?id=${r.id}`; }
    catch (err) { flash(err.message, 'danger'); }
  });
  $('#pvNext')?.addEventListener('click', () => go(1));
  let sx = null, sy = null;
  const pb = $('#pvBody');
  pb.addEventListener('touchstart', (e) => { sx = e.touches[0].clientX; sy = e.touches[0].clientY; }, { passive: true });
  pb.addEventListener('touchend', (e) => {
    if (sx === null || n < 2) return;
    const dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy; sx = null;
    if (Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5) go(dx < 0 ? 1 : -1);
  }, { passive: true });
}
function go(d) { pv.i = (pv.i + d + pv.ids.length) % pv.ids.length; showPreview(); }
document.addEventListener('keydown', (e) => {
  if (!pv || !modalOpen() || !$('#pvBody') || e.target.matches('input, textarea, select')) return;
  if (e.key === 'ArrowRight') { e.preventDefault(); go(1); } else if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
});
document.getElementById('appModal').addEventListener('hidden.bs.modal', () => { pv = null; });

/* ---------- ovládání mřížky ---------- */
$('#q').addEventListener('input', render); $('#typeF').addEventListener('change', render);
$('#tagManage').onclick = manageTags;
$('#grid').addEventListener('click', async (e) => {
  const qt = e.target.closest('[data-qtag]');
  if (qt) { tagSt.none = false; tagSt.sel.clear(); tagSt.sel.add(qt.dataset.qtag); render(); return; }
  const card = e.target.closest('.lib-card'); if (!card) return;
  const id = +card.dataset.id, it = byId(id);
  if (e.target.closest('[data-zoom]')) { preview(id); return; }
  if (e.target.closest('[data-etags]')) { tagsModal([id]); return; }
  if (e.target.closest('[data-arch]')) { archive([id], !it.archived); return; }
  if (e.target.closest('[data-rename]')) {
    const n = prompt('Název souboru:', it.name);
    if (n && n.trim()) { try { await api('library_rename', form({ id, name: n.trim() })); await load(); } catch (err) { flash(err.message, 'danger'); } }
    return;
  }
  if (e.target.closest('.lib-check')) { if (e.target.matches('input')) { e.target.checked ? sel.add(id) : sel.delete(id); render(); } return; }
  if (sel.size) { sel.has(id) ? sel.delete(id) : sel.add(id); render(); return; }
  preview(id);
});
// přetažení karty (nebo vybraných karet) na tag v řadě nahoře = přidat tag
$('#grid').addEventListener('dragstart', (e) => {
  const card = e.target.closest('.lib-card'); if (!card) return;
  const id = +card.dataset.id;
  const ids = sel.has(id) ? [...sel] : [id];
  e.dataTransfer.setData('application/x-sp-items', JSON.stringify(ids));
  e.dataTransfer.effectAllowed = 'copy';
  document.body.classList.add('lib-dragging');
});
$('#grid').addEventListener('dragend', () => document.body.classList.remove('lib-dragging'));

$('#selClear').onclick = () => { sel.clear(); render(); };
$('#selAll').onclick = () => { visible().forEach((i) => sel.add(i.id)); render(); };
$('#selTags').onclick = () => tagsModal([...sel]);
$('#selArch').onclick = () => archive([...sel], view !== 'archive');
$('#selDel').onclick = async () => {
  if (!confirm(`Opravdu smazat ${sel.size} souborů z knihovny i z disku? Soubory použité v příspěvcích se nesmažou – ty můžete dát do archivu.`)) return;
  try {
    const r = await api('library_delete', fdIds([...sel])); sel.clear();
    flash(r.kept.length ? `Smazáno ${r.deleted}. Nesmazáno (používá je příspěvek): ${r.kept.join(', ')}` : `Smazáno ${r.deleted}.`, r.kept.length ? 'warning' : 'success');
    await load();
  } catch (err) { flash(err.message, 'danger'); }
};

/* ---------- nahrávání (nové soubory dostanou vybraný tag) ---------- */
const loadImg = (src) => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('Obrázek se nepodařilo načíst.')); i.src = src; });
async function toJpeg(file) {
  const url = URL.createObjectURL(file);
  try {
    const img = await loadImg(url), k = Math.min(1, 2048 / Math.max(img.naturalWidth, img.naturalHeight));
    const c = document.createElement('canvas'); c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
    const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height); g.drawImage(img, 0, 0, c.width, c.height);
    const b = await new Promise(r => c.toBlob(r, 'image/jpeg', 0.92));
    return new File([b], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' });
  } finally { URL.revokeObjectURL(url); }
}
async function upload(list) {
  const files = [...list]; let ok = 0; const errs = [];
  const tags = view === 'archive' ? [] : T.current(tagSt, pool());
  for (let i = 0; i < files.length; i++) {
    $('#libInfo').textContent = `Nahrávám ${i + 1} / ${files.length}…`;
    try {
      let f = files[i];
      if (f.type.startsWith('image/')) f = await toJpeg(f);
      else if (!['video/mp4', 'video/quicktime'].includes(f.type)) throw new Error('nepodporovaný typ');
      const fd = form({}); fd.append('media[]', f, f.name);
      tags.forEach((t) => fd.append('tags[]', t));
      const r = await api('library_upload', fd); ok += r.added; (r.errors || []).forEach(x => errs.push(x));
    } catch (err) { errs.push(`${files[i].name}: ${err.message}`); }
  }
  if (view === 'archive' && ok) view = 'all';
  flash(errs.length ? `Nahráno ${ok}. Problémy: ${errs.join('; ')}` : `Nahráno ${ok} souborů${tags.length ? ` s tagem ${tags.join(', ')}` : ''}.`, errs.length ? 'warning' : 'success');
  await load();
}
$('#libUpload').onclick = () => $('#libFile').click();
$('#libWeb').onclick = () => Stock.open({ tags: view === 'archive' ? [] : T.current(tagSt, pool()), onDone: async (ids) => {
  if (view === 'archive') view = 'all';
  await load();
  if (ids.length === 1) preview(ids[0]);   // ukázat, co přišlo (autor, licence)
} });
$('#slideNew').addEventListener('click', (e) => { const t = view !== 'archive' ? T.current(tagSt, pool()) : []; if (t.length) { e.preventDefault(); location.href = `slide.php?tag=${encodeURIComponent(t[0])}`; } });
$('#libFile').addEventListener('change', (e) => { if (e.target.files.length) upload(e.target.files); e.target.value = ''; });
const drop = $('#libDrop');
const hasFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files');
['dragenter', 'dragover'].forEach(ev => document.addEventListener(ev, (e) => { if (hasFiles(e)) { e.preventDefault(); drop.classList.add('over'); } }));
['dragleave', 'drop'].forEach(ev => document.addEventListener(ev, () => drop.classList.remove('over')));
document.addEventListener('drop', (e) => { if (hasFiles(e) && e.dataTransfer.files.length) { e.preventDefault(); upload(e.dataTransfer.files); } });
load().catch(err => flash(err.message, 'danger'));
