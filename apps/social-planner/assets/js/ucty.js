'use strict';
const { $, esc, norm, api, flash, icons } = App;

let accounts = [];
let sort = { key: 'name', dir: 1 };

function visible() {
  const q = norm($('#q').value.trim());
  const st = $('#fState').value, net = $('#fNet').value;
  const list = accounts.filter(a =>
    (!q || norm(a.name).includes(q) || norm(a.page_name).includes(q)) &&
    (st === '' || String(+a.enabled) === st) &&
    (!net || a.platform === net));
  const k = sort.key;
  list.sort((x, y) => {
    const a = k === 'enabled' ? +x.enabled : norm(x[k]), b = k === 'enabled' ? +y.enabled : norm(y[k]);
    return (a < b ? -1 : a > b ? 1 : norm(x.name).localeCompare(norm(y.name))) * sort.dir;
  });
  return list;
}

function render() {
  const list = visible();
  const total = accounts.length, on = accounts.filter(a => +a.enabled).length;
  $('#statTotal').textContent = total;
  $('#statOn').textContent = on;
  $('#statFb').textContent = accounts.filter(a => a.platform === 'facebook').length;
  $('#statIg').textContent = accounts.filter(a => a.platform === 'instagram').length;
  $('#statTh').textContent = accounts.filter(a => a.platform === 'threads').length;
  $('#foot').textContent = `Zobrazeno ${list.length} z ${total} účtů, povoleno ${on}.`;
  $('#bulkOn').textContent = `Povolit zobrazené (${list.length})`;
  $('#bulkOff').textContent = `Zakázat zobrazené (${list.length})`;
  $('#bulkOn').disabled = $('#bulkOff').disabled = list.length === 0;

  document.querySelectorAll('th[data-sort]').forEach(th => {
    if (th.dataset.sort === sort.key) th.dataset.dir = sort.dir; else delete th.dataset.dir;
  });

  if (!total) {
    $('#rows').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Zatím nejsou propojené žádné účty. <a href="connect.php">Propojit účty</a></td></tr>';
    return;
  }
  if (!list.length) {
    $('#rows').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Žádný účet neodpovídá filtru.</td></tr>';
    return;
  }
  $('#rows').innerHTML = list.map(a => `
    <tr class="${+a.enabled ? '' : 'is-off'}">
      <td><div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch" data-acc="${a.id}" ${+a.enabled ? 'checked' : ''} aria-label="Povolit účet ${esc(a.name)}">
      </div></td>
      <td><span class="badge ${App.NET[a.platform][0]}">${App.NET[a.platform][2]}</span></td>
      <td class="fw-semibold"><div class="d-flex align-items-center gap-2">${App.avatar(a)} ${esc(a.name)}</div></td>
      <td class="d-none d-md-table-cell text-muted">${a.platform === 'instagram' ? esc(a.page_name || '–') : ''}</td>
      <td>${a.health === 'error' ? `<span class="badge bg-danger" title="${esc(a.health_msg || '')}">Chyba – propojit znovu</span>` : a.health === 'ok' ? `<span class="badge bg-success" title="Ověřeno ${a.health_at ? App.fmt(a.health_at) : ''}${a.platform === 'threads' && a.token_expires ? ' · token platí do ' + App.fmt(a.token_expires) : ''}">V pořádku</span>` : '<span class="text-muted small">neověřeno</span>'}</td>
    </tr>`).join('');
}

async function load() {
  accounts = (await api('accounts')).accounts;
  render();
}

$('#rows').addEventListener('change', async (e) => {
  const id = e.target.dataset.acc;
  if (!id) return;
  const acc = accounts.find(a => String(a.id) === id), en = e.target.checked ? 1 : 0;
  try {
    const f = new FormData(); f.append('id', id); f.append('enabled', en);
    await api('set_enabled', f);
    acc.enabled = en;
  } catch (err) {
    e.target.checked = !en;
    flash(err.message, 'danger');
  }
  render();
});

async function bulk(enabled) {
  const list = visible();
  if (!list.length) return;
  const txt = `${enabled ? 'Povolit' : 'Zakázat'} ${list.length} zobrazených účtů?`;
  if (list.length > 1 && !confirm(txt)) return;
  try {
    const f = new FormData();
    f.append('enabled', enabled);
    list.forEach(a => f.append('ids[]', a.id));
    await api('set_enabled', f);
    list.forEach(a => { a.enabled = enabled; });
    render();
    flash(`${enabled ? 'Povoleno' : 'Zakázáno'}: ${list.length} účtů.`);
  } catch (err) { flash(err.message, 'danger'); }
}
$('#bulkOn').addEventListener('click', () => bulk(1));
$('#bulkOff').addEventListener('click', () => bulk(0));

['input', 'change'].forEach(ev => {
  $('#q').addEventListener(ev, render);
  $('#fState').addEventListener(ev, render);
  $('#fNet').addEventListener(ev, render);
});
document.querySelectorAll('th[data-sort]').forEach(th => th.addEventListener('click', () => {
  const k = th.dataset.sort;
  sort = { key: k, dir: sort.key === k ? -sort.dir : 1 };
  render();
}));

load().catch(e => { $('#rows').innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${esc(e.message)}</td></tr>`; });
icons();
$('#btnCheck').addEventListener('click', async (e) => {
  e.target.disabled = true; flash('Kontroluji účty…', 'info');
  try {
    const r = await api('check_accounts', App.form({}));
    flash(r.bad ? `Zkontrolováno ${r.checked} účtů, ${r.bad} vyžaduje znovu propojit.` : `Zkontrolováno ${r.checked} účtů, vše v pořádku.`, r.bad ? 'warning' : 'success');
    accounts = (await api('accounts')).accounts; render();
  } catch (err) { flash(err.message, 'danger'); }
  e.target.disabled = false;
});
