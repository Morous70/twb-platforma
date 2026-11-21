'use strict';
const { $, esc, norm, api, form, flash, icons } = App;
let vars = [];

const slug = (s) => norm(s).replace(/[^a-z0-9_]+/g, '_').replace(/^_+|_+$/g, '').replace(/^[0-9]+/, '');
function typeHelp() {
  const list = $('#vType').value === 'list';
  $('#vValueLabel').textContent = list ? 'Hodnoty (každá na samostatném řádku)' : 'Hodnota';
  $('#vValueHelp').textContent = list ? 'Například tipy nebo akce týdne – při každém opakování se použije další řádek.' : '';
}
function reset() {
  $('#vId').value = ''; $('#vName').value = ''; $('#vValue').value = ''; $('#vType').value = 'text';
  $('#formTitle').textContent = 'Nová proměnná'; $('#vReset').hidden = true; $('#vNamePreview').textContent = 'nazev'; typeHelp();
}
function render() {
  $('#rows').innerHTML = vars.length ? vars.map(v => `
    <tr>
      <td><code>{{${esc(v.name)}}}</code></td>
      <td>${v.type === 'list' ? '<span class="badge bg-info">seznam (' + v.value.split(/\r?\n/).filter(x => x.trim()).length + ')</span>' : '<span class="badge bg-secondary">text</span>'}</td>
      <td class="text-truncate" style="max-width:280px">${esc(v.value.split(/\r?\n/)[0])}${v.value.includes('\n') ? ' …' : ''}</td>
      <td class="text-end text-nowrap">
        <button type="button" class="btn btn-sm btn-outline-primary" data-edit="${v.id}">Upravit</button>
        <button type="button" class="btn btn-sm btn-outline-danger" data-del="${v.id}">Smazat</button>
      </td>
    </tr>`).join('') : '<tr><td colspan="4" class="text-center text-muted py-4">Zatím žádné vlastní proměnné.</td></tr>';
}
async function load() {
  const r = await api('variables');
  vars = r.variables;
  $('#builtin').innerHTML = Object.entries(r.builtin).map(([k, d]) => `<tr><td><code>{{${esc(k)}}}</code></td><td class="text-muted">${esc(d)}</td></tr>`).join('');
  render();
}
$('#vName').addEventListener('input', (e) => { $('#vNamePreview').textContent = slug(e.target.value) || 'nazev'; });
$('#vName').addEventListener('blur', (e) => { e.target.value = slug(e.target.value); });
$('#vType').addEventListener('change', typeHelp);
$('#vReset').onclick = reset;
$('#varForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('var_save', form({ id: $('#vId').value, name: slug($('#vName').value), type: $('#vType').value, value: $('#vValue').value }));
    flash('Proměnná je uložena.'); reset(); await load();
  } catch (err) { flash(err.message, 'danger'); }
});
$('#rows').addEventListener('click', async (e) => {
  const ed = e.target.closest('[data-edit]'), del = e.target.closest('[data-del]');
  if (ed) {
    const v = vars.find(x => String(x.id) === ed.dataset.edit);
    $('#vId').value = v.id; $('#vName').value = v.name; $('#vType').value = v.type; $('#vValue').value = v.value;
    $('#formTitle').textContent = 'Upravit proměnnou'; $('#vReset').hidden = false; $('#vNamePreview').textContent = v.name; typeHelp();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } else if (del) {
    const v = vars.find(x => String(x.id) === del.dataset.del);
    if (!confirm(`Smazat proměnnou {{${v.name}}}? Příspěvky, které ji používají, pak půjdou odeslat jen po odstranění z textu.`)) return;
    try { await api('var_delete', form({ id: v.id })); flash('Proměnná je smazána.'); await load(); } catch (err) { flash(err.message, 'danger'); }
  }
});
typeHelp(); load().catch(err => flash(err.message, 'danger')); icons();
