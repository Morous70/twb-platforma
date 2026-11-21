'use strict';
const { $, esc, norm, api, form, flash, netBadge, avatar, icons } = App;
let accounts = [], groups = [];
const sel = new Set();

const visible = () => { const q = norm($('#q').value.trim()); return accounts.filter(a => !q || norm(a.name).includes(q)); };
function renderAcc() {
  $('#gCount').textContent = sel.size;
  $('#accList').innerHTML = visible().map(a => `
    <label class="list-group-item d-flex align-items-center gap-2 mb-0${+a.enabled ? '' : ' text-muted'}">
      <input class="form-check-input mt-0" type="checkbox" data-acc="${a.id}" ${sel.has(String(a.id)) ? 'checked' : ''}>
      ${avatar(a)} <span class="fw-semibold">${esc(a.name)}</span>${+a.enabled ? '' : ' <small>(zakázaný)</small>'}
    </label>`).join('') || '<div class="p-3 text-muted">Žádné účty.</div>';
}
function renderGroups() {
  $('#rows').innerHTML = groups.length ? groups.map(g => {
    const n = g.account_ids.split(',').filter(Boolean).length;
    return `<tr><td class="fw-semibold">${esc(g.name)}</td><td class="text-muted">${n} účtů</td>
      <td class="text-end text-nowrap"><button type="button" class="btn btn-sm btn-outline-primary" data-edit="${g.id}">Upravit</button>
      <button type="button" class="btn btn-sm btn-outline-danger" data-del="${g.id}">Smazat</button></td></tr>`;
  }).join('') : '<tr><td class="text-center text-muted py-4">Zatím žádné skupiny.</td></tr>';
}
function reset() { $('#gId').value = ''; $('#gName').value = ''; sel.clear(); $('#formTitle').textContent = 'Nová skupina'; $('#gReset').hidden = true; renderAcc(); }
async function load() { groups = (await api('groups')).groups; renderGroups(); }

$('#accList').addEventListener('change', (e) => { const id = e.target.dataset.acc; if (!id) return; e.target.checked ? sel.add(id) : sel.delete(id); $('#gCount').textContent = sel.size; });
$('#q').addEventListener('input', renderAcc);
$('#selAll').onclick = () => { visible().forEach(a => sel.add(String(a.id))); renderAcc(); };
$('#selNone').onclick = () => { sel.clear(); renderAcc(); };
$('#gReset').onclick = reset;
$('#form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = form({ id: $('#gId').value, name: $('#gName').value.trim() });
  sel.forEach(id => fd.append('accounts[]', id));
  try { await api('group_save', fd); flash('Skupina je uložena.'); reset(); await load(); } catch (err) { flash(err.message, 'danger'); }
});
$('#rows').addEventListener('click', async (e) => {
  const ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
  if (ed) {
    const g = groups.find(x => String(x.id) === ed.dataset.edit);
    $('#gId').value = g.id; $('#gName').value = g.name; sel.clear(); g.account_ids.split(',').filter(Boolean).forEach(i => sel.add(i));
    $('#formTitle').textContent = 'Upravit skupinu'; $('#gReset').hidden = false; renderAcc(); window.scrollTo({ top: 0, behavior: 'smooth' });
  } else if (del) {
    const g = groups.find(x => String(x.id) === del.dataset.del);
    if (!confirm(`Smazat skupinu „${g.name}“? Účty zůstanou zachované.`)) return;
    try { await api('group_delete', form({ id: g.id })); flash('Skupina je smazána.'); await load(); } catch (err) { flash(err.message, 'danger'); }
  }
});
api('accounts').then(r => { accounts = r.accounts; renderAcc(); }).then(load).catch(err => flash(err.message, 'danger'));
icons();
