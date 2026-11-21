'use strict';
const { $, esc, api, form, flash, fmt } = App;
let accounts = [], groups = [], library = [], hsets = [], plan = [];
const HEAD = {
  text: ['text', 'prispevek', 'zprava', 'message', 'copy'],
  when: ['datum a cas', 'datum', 'cas', 'kdy', 'date', 'datetime', 'scheduled_at', 'termin'],
  accs: ['ucty', 'ucet', 'skupina', 'skupiny', 'accounts', 'group'],
  link: ['odkaz', 'link', 'url'],
  tags: ['stitky', 'hashtagy', 'tags', 'hashtags'],
  set: ['sada', 'sada hashtagu', 'tag_sets', 'sady'],
  fc: ['prvni komentar', 'komentar', 'first_comment', 'comment'],
  img: ['obrazky', 'obrazek', 'images', 'image', 'foto', 'media'],
};
const N = (s) => String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();

function parseDelimited(text, d) {
  const rows = []; let row = [], cell = '', q = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (q) { if (c === '"') { if (text[i + 1] === '"') { cell += '"'; i++; } else q = false; } else cell += c; }
    else if (c === '"' && cell === '') q = true;
    else if (c === d) { row.push(cell); cell = ''; }
    else if (c === '\n' || c === '\r') { if (c === '\r' && text[i + 1] === '\n') i++; row.push(cell); rows.push(row); row = []; cell = ''; }
    else cell += c;
  }
  if (cell !== '' || row.length) { row.push(cell); rows.push(row); }
  return rows.filter(r => r.some(x => x.trim() !== ''));
}
function parseData(text) {
  text = text.replace(/^﻿/, '');
  const first = text.split(/\r?\n/)[0] || '';
  const d = first.includes('\t') ? '\t' : (first.split(';').length >= first.split(',').length ? ';' : ',');
  return parseDelimited(text, d);
}
function parseWhen(s) {
  s = String(s || '').trim(); if (!s) return null;
  let m = s.match(/^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})(?:[ T]+(\d{1,2})[:.](\d{2}))?$/);
  let y, mo, d, h = 9, mi = 0;
  if (m) { d = +m[1]; mo = +m[2]; y = +m[3]; if (m[4] != null) { h = +m[4]; mi = +m[5]; } }
  else if ((m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T]+(\d{1,2}):(\d{2}))?/))) { y = +m[1]; mo = +m[2]; d = +m[3]; if (m[4] != null) { h = +m[4]; mi = +m[5]; } }
  else return null;
  const dt = new Date(y, mo - 1, d, h, mi);
  if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d || h > 23 || mi > 59) return null;
  const p = (n) => String(n).padStart(2, '0');
  return { value: `${y}-${p(mo)}-${p(d)}T${p(h)}:${p(mi)}`, date: dt };
}
function resolveAccounts(str, def) {
  const names = String(str || '').split(/[,|;\n]+/).map(x => x.trim()).filter(Boolean);
  if (!names.length) return { ids: def, missing: [] };
  const ids = new Set(), missing = [];
  names.forEach(n => {
    const g = groups.find(x => N(x.name) === N(n));
    if (g) { g.account_ids.split(',').filter(Boolean).forEach(id => { if (accounts.some(a => String(a.id) === id && +a.enabled)) ids.add(id); }); return; }
    const hits = accounts.filter(a => +a.enabled && (N(a.name) === N(n) || N(`${a.platform} ${a.name}`) === N(n)));
    if (hits.length) hits.forEach(a => ids.add(String(a.id))); else missing.push(n);
  });
  return { ids: [...ids], missing };
}
function resolveImages(str) {
  const parts = String(str || '').split(/[|;\n]+|\s+(?=https?:)/).map(x => x.trim()).filter(Boolean);
  return parts.map(p => {
    if (/^https?:\/\//i.test(p)) return { kind: 'url', v: p, label: p.split('/').pop().slice(0, 28) };
    const it = library.find(l => N(l.file) === N(p) || N(l.name) === N(p) || N(l.name + '.jpg') === N(p));
    return it ? { kind: 'lib', v: it.file, label: it.name } : { kind: 'bad', v: p, label: p };
  });
}
const compose = (m, l, t) => [m, l, t.length ? t.map(x => '#' + x).join(' ') : ''].filter(Boolean).join('\n\n');
const cp = (s) => [...s].length;
const pl = (n, a, b, c) => n === 1 ? a : (n >= 2 && n <= 4 ? b : c);

function build() {
  const rows = parseData($('#data').value);
  if (rows.length < 2) throw new Error('Vložte hlavičku a aspoň jeden řádek dat.');
  const head = rows[0].map(N), col = {};
  for (const [k, names] of Object.entries(HEAD)) { const i = head.findIndex(h => names.includes(h)); if (i >= 0) col[k] = i; }
  if (col.text == null && col.img == null && col.link == null) throw new Error('V hlavičce chybí sloupec „Text“.');
  const sched = $('#mSched').checked;
  const def = ($('#defAcc').value || '').split(',').filter(Boolean);
  return rows.slice(1).map((r, i) => {
    const g = (k) => col[k] == null ? '' : String(r[col[k]] ?? '').trim();
    const e = [];
    const message = g('text'), link = g('link'), fc = g('fc');
    const w = parseWhen(g('when'));
    if (sched && !w) e.push(g('when') ? 'neplatné datum' : 'chybí datum');
    if (sched && w && w.date.getTime() < Date.now() - 60000) e.push('termín je v minulosti');
    const acc = resolveAccounts(g('accs'), def);
    if (acc.missing.length) e.push('neznámý účet/skupina: ' + acc.missing.join(', '));
    if (sched && !acc.ids.length && !acc.missing.length) e.push('chybí účet (vyberte výchozí)');
    let tags = g('tags').split(/[\s,;]+/).map(t => t.replace(/^#/, '').replace(/[^\p{L}\p{N}_]/gu, '')).filter(Boolean);
    g('set').split(/[|;,]+/).map(x => x.trim()).filter(Boolean).forEach(n => { const s = hsets.find(h => N(h.name) === N(n)); if (!s) e.push('neznámá sada: ' + n); else tags = tags.concat(s.tags.split(' ')); });
    tags = tags.filter((t, j) => tags.findIndex(x => x.toLowerCase() === t.toLowerCase()) === j);
    if (tags.length > 30) e.push('víc než 30 štítků');
    const imgs = resolveImages(g('img'));
    imgs.filter(x => x.kind === 'bad').forEach(x => e.push('obrázek nenalezen: ' + x.v));
    if (imgs.length > 10) e.push('víc než 10 obrázků');
    if (link && !/^https?:\/\//i.test(link)) e.push('odkaz musí začínat http(s)://');
    if (!message && !imgs.length && !link) e.push('prázdný řádek');
    const sel = accounts.filter(a => acc.ids.includes(String(a.id)));
    if (sched && sel.some(a => a.platform === 'instagram')) { if (!imgs.length) e.push('Instagram vyžaduje obrázek'); if (cp(compose(message, link, tags)) > 2200) e.push('text pro Instagram přes 2 200 znaků'); }
    if (sched && sel.some(a => a.platform === 'threads') && cp(compose(message, link, tags)) > 500) e.push('text pro Threads přes 500 znaků');
    return { n: i + 1, message, link, fc, tags, imgs, when: w, ids: acc.ids, sel, errors: e, state: e.length ? 'err' : 'ok', note: '' };
  });
}
function render() {
  $('#rows').innerHTML = plan.map((p, i) => `<tr data-i="${i}">
    <td>${p.n}</td><td style="max-width:260px"><div class="text-truncate">${esc(p.message) || '<em class="text-muted">(bez textu)</em>'}</div>${p.tags.length ? `<div class="small text-muted text-truncate">${esc(p.tags.map(t => '#' + t).join(' '))}</div>` : ''}</td>
    <td class="text-nowrap">${p.when ? esc(fmt(p.when.date.getTime() / 1000)) : '–'}</td>
    <td class="small">${p.sel.map(a => `${App.netBadge(a.platform)} ${esc(a.name)}`).join('<br>') || '–'}</td>
    <td class="small">${p.imgs.length ? p.imgs.length + '×' : '–'}</td>
    <td class="small st">${p.state === 'ok' ? '<span class="text-success">OK</span>' : p.state === 'done' ? `<span class="text-success">✔ vytvořeno</span>` : p.state === 'run' ? 'vytvářím…' : `<span class="text-danger">${esc(p.errors.concat(p.note ? [p.note] : []).join('; '))}</span>`}</td></tr>`).join('');
  const ok = plan.filter(p => p.state === 'ok').length, bad = plan.filter(p => p.state === 'err').length, done = plan.filter(p => p.state === 'done').length;
  $('#sum').innerHTML = `<span class="text-success">${ok} v pořádku</span> · <span class="${bad ? 'text-danger' : ''}">${bad} s chybou</span>${done ? ` · ${done} vytvořeno` : ''}`;
  $('#go').disabled = !ok; $('#go').textContent = ok ? `Vytvořit ${ok} ${$('#mSched').checked ? pl(ok, 'příspěvek', 'příspěvky', 'příspěvků') : pl(ok, 'koncept', 'koncepty', 'konceptů')}` : 'Není co vytvořit';
}
$('#check').onclick = () => {
  try { plan = build(); $('#prevCard').hidden = false; render(); $('#prevCard').scrollIntoView({ behavior: 'smooth' }); }
  catch (e) { flash(e.message, 'danger'); }
};
$('#go').onclick = async () => {
  const sched = $('#mSched').checked;
  if (sched && !confirm(`Naplánovat ${plan.filter(p => p.state === 'ok').length} ${pl(plan.filter(p => p.state === 'ok').length, 'příspěvek', 'příspěvky', 'příspěvků')}?`)) return;
  $('#go').disabled = true; $('#check').disabled = true;
  for (const p of plan) {
    if (p.state !== 'ok') continue;
    p.state = 'run'; render();
    try {
      const order = [];
      for (const im of p.imgs) {
        if (im.kind === 'url') { const r = await api('import_image', form({ url: im.v })); order.push('e:' + r.file); }
        else order.push('e:' + im.v);
      }
      const fd = form({ message: p.message, link: p.link, tags: p.tags.join(' '), first_comment: p.fc, order: JSON.stringify(order), repeat: 'none', kind: 'feed' });
      if (p.when) fd.set('scheduled_at', p.when.value);
      if (!sched) fd.set('draft', '1');
      p.ids.forEach(id => fd.append('accounts[]', id));
      await api('create', fd);
      p.state = 'done';
    } catch (e) { p.state = 'err'; p.note = e.message; }
    render();
  }
  $('#check').disabled = false;
  const done = plan.filter(p => p.state === 'done').length, bad = plan.filter(p => p.state === 'err').length;
  flash(`Vytvořeno: ${done}${bad ? `, s chybou: ${bad}` : ''}.`, bad ? 'warning' : 'success');
  $('#go').textContent = 'Hotovo';
};
const SAMPLE = 'Text;Datum a čas;Účty;Odkaz;Štítky;Sada;První komentář;Obrázky\n"Nová kolekce je tady! 🎉";1.12.2026 10:00;;https://www.eshop.cz/kolekce;novinka eshop;;Objednávky do 15:00 odesíláme ještě dnes.;https://www.eshop.cz/img/kolekce-1.jpg\n"Víkendová sleva 20 %";4.12.2026 18:30;;https://www.eshop.cz/sleva;sleva;;;';
$('#sample').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['﻿' + SAMPLE], { type: 'text/csv;charset=utf-8' })); a.download = 'vzor-hromadne-planovani.csv'; a.click(); };
$('#fillSample').onclick = () => { $('#data').value = SAMPLE; };
$('#file').onchange = async (e) => { const f = e.target.files[0]; if (f) $('#data').value = await f.text(); };
(async () => {
  const [a, g, l, h] = await Promise.all([api('accounts'), api('groups'), api('library'), api('hashtag_sets')]);
  accounts = a.accounts || a; groups = g.groups; library = l.items || []; hsets = h.sets;
  const on = accounts.filter(x => +x.enabled);
  $('#defAcc').innerHTML = '<option value="">(žádné, účty uvedu v tabulce)</option>' + (on.length ? `<option value="${on.map(x => x.id).join(',')}">Všechny povolené účty (${on.length})</option>` : '') +
    groups.map(x => { const ids = x.account_ids.split(',').filter(id => on.some(a => String(a.id) === id)); return ids.length ? `<option value="${ids.join(',')}">Skupina: ${esc(x.name)}</option>` : ''; }).join('') +
    on.map(x => `<option value="${x.id}">${esc(x.platform)}: ${esc(x.name)}</option>`).join('');
})().catch(e => flash(e.message, 'danger'));
