'use strict';
const { $, esc, norm, api, flash, statusBadge, netBadge, fmt, repeatInfo, icons, detail, modal, modalOpen, bindActions } = App;

const PER_PAGE = 25;
let posts = [], page = 1, dir = -1;   // dir: -1 = nejnovější nahoře

function visible() {
  const q = norm($('#q').value.trim()), st = $('#fStatus').value;
  return posts.filter(p => {
    if (st === 'attention' && !['failed', 'partial'].includes(p.status)) return false;
    if (st === 'repeat' && (!p.repeat_mode || p.repeat_mode === 'none')) return false;
    if (st && !['attention', 'repeat'].includes(st) && p.status !== st) return false;
    if (!q) return true;
    return norm(p.message).includes(q) || p.targets.some(t => norm(t.name).includes(q));
  }).sort((a, b) => (a.scheduled_at - b.scheduled_at) * dir);
}

function render() {
  const list = visible();
  const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
  page = Math.min(page, pages);
  const slice = list.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  $('th[data-sort]').dataset.dir = dir;
  $('#foot').textContent = `Zobrazeno ${slice.length} z ${list.length} příspěvků.`;
  $('#rows').innerHTML = slice.length ? slice.map(p => {
    const done = p.targets.filter(t => t.status === 'done').length, bad = p.targets.filter(t => t.status === 'failed').length;
    return `<tr>
      <td class="text-nowrap">${fmt(p.scheduled_at)}</td>
      <td><div class="d-flex align-items-center gap-2">
        ${p.media_url ? (p.media_type === 'video' ? '<span class="thumb bg-light d-flex align-items-center justify-content-center">🎬</span>' : `<img class="thumb" src="${esc(p.media_url)}" alt="" loading="lazy">`) : ''}
        ${p.media && p.media.length > 1 && p.media_type !== 'video' ? `<span class="badge bg-secondary">+${p.media.length - 1}</span>` : ''}
        <div class="text-truncate" style="max-width:420px">${esc(p.message.slice(0, 120)) || '<em class="text-muted">(bez textu)</em>'}</div>
      </div></td>
      <td class="d-none d-md-table-cell text-nowrap">${p.targets.length} ${bad ? `<span class="badge bg-danger">${bad} selhalo</span>` : (done === p.targets.length && done ? '<span class="badge bg-success">vše OK</span>' : '')}</td>
      <td>${statusBadge(p.status)}${repeatInfo(p) ? `<div class="small text-primary">${esc(repeatInfo(p))}</div>` : ''}</td>
      <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-detail="${p.id}">Detail</button>
        ${['draft', 'scheduled', 'failed', 'partial'].includes(p.status) ? `<a class="btn btn-sm btn-outline-primary" href="novy.php?edit=${p.id}">Upravit</a>` : `<a class="btn btn-sm btn-outline-primary" href="novy.php?copy=${p.id}">Duplikovat</a>`}
        ${['failed', 'partial'].includes(p.status) ? `<button type="button" class="btn btn-sm btn-primary" data-retry="${p.id}">Znovu</button>` : ''}
        <button type="button" class="btn btn-sm btn-outline-danger" data-del="${p.id}" data-series="${p.status === 'scheduled' && p.repeat_mode !== 'none' ? 1 : 0}">Smazat</button>
      </td>
    </tr>`;
  }).join('') : '<tr><td colspan="5" class="text-center text-muted py-4">Žádné příspěvky.</td></tr>';

  let pg = '';
  if (pages > 1) {
    const btn = (n, label, off, active) => `<li class="page-item${off ? ' disabled' : ''}${active ? ' active' : ''}"><a class="page-link" href="#" data-page="${n}">${label}</a></li>`;
    pg += btn(page - 1, '‹', page === 1);
    for (let n = 1; n <= pages; n++) if (n === 1 || n === pages || Math.abs(n - page) <= 2) pg += btn(n, n, false, n === page);
      else if (Math.abs(n - page) === 3) pg += '<li class="page-item disabled"><span class="page-link">…</span></li>';
    pg += btn(page + 1, '›', page === pages);
  }
  $('#pager').innerHTML = pg;
}

async function load() {
  posts = (await api('posts')).posts;
  render();
}

$('#pager').addEventListener('click', (e) => {
  e.preventDefault();
  const n = +e.target.closest('[data-page]')?.dataset.page;
  if (n) { page = n; render(); }
});
$('th[data-sort]').addEventListener('click', () => { dir = -dir; render(); });
['input', 'change'].forEach(ev => { $('#q').addEventListener(ev, () => { page = 1; render(); }); $('#fStatus').addEventListener(ev, () => { page = 1; render(); }); });

$('#rows').addEventListener('click', (e) => {
  const d = e.target.closest('[data-detail]');
  if (!d) return;
  const p = posts.find(x => String(x.id) === d.dataset.detail);
  if (p) modal('Příspěvek', detail(p));
});
const refresh = () => load().catch(e => flash(e.message, 'danger'));
bindActions($('#rows'), refresh);
bindActions($('#appModal'), refresh);

if (new URLSearchParams(location.search).get('saved')) flash('Změny byly uloženy.');
const initial = new URLSearchParams(location.search).get('status');
if (initial && [...$('#fStatus').options].some(o => o.value === initial)) $('#fStatus').value = initial;
load().catch(e => { $('#rows').innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; });
icons();
setInterval(() => { if (!modalOpen()) refresh(); }, 30000);
