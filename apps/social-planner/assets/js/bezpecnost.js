'use strict';
const { $, esc, api, form, flash, fmt } = App;
async function load() {
  const r = await api('security_get');
  $('#noMail').classList.toggle('d-none', r.has_email);
  $('#idle').value = r.idle;
  $('#rp').textContent = 'Doména: ' + r.rp_id + '.';
  $('#pkList').innerHTML = r.passkeys.length ? r.passkeys.map(p => `<div class="list-group-item d-flex justify-content-between align-items-center gap-2">
      <div><div class="fw-semibold">${esc(p.name)}</div><div class="small text-muted">přidáno ${fmt(p.created_at)} · ${p.last_used ? 'naposledy ' + fmt(p.last_used) : 'nepoužito'}</div></div>
      <div class="text-nowrap"><button class="btn btn-sm btn-outline-primary" data-ren="${p.id}" data-name="${esc(p.name)}">Přejmenovat</button> <button class="btn btn-sm btn-outline-danger" data-del="${p.id}">Odebrat</button></div></div>`).join('')
    : '<div class="text-muted small">Zatím žádné zařízení.</div>';
  if (!WA.supported()) { $('#pkAdd').disabled = true; $('#pkNo').classList.remove('d-none'); }
}
$('#pwForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try { await api('password_change', form({ current: $('#pwCur').value, new: $('#pwNew').value, new2: $('#pwNew2').value })); flash('Heslo je změněno.'); e.target.reset(); }
  catch (err) { flash(err.message, 'danger'); }
});
$('#idleForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try { await api('idle_save', form({ minutes: $('#idle').value || 0 })); flash('Uloženo.'); } catch (err) { flash(err.message, 'danger'); }
});
$('#pkAdd').onclick = async () => {
  try { await WA.register(api, form, $('#pkName').value.trim()); $('#pkName').value = ''; flash('Passkey je přidáno.'); await load(); }
  catch (err) { flash(WA.msg(err), 'danger'); }
};
$('#pkList').addEventListener('click', async (e) => {
  const ren = e.target.closest('[data-ren]'), del = e.target.closest('[data-del]');
  try {
    if (ren) { const n = prompt('Nový název zařízení:', ren.dataset.name); if (n && n.trim()) { await api('passkey_rename', form({ id: ren.dataset.ren, name: n.trim() })); await load(); } }
    else if (del && confirm('Odebrat toto zařízení? Přihlášení jím přestane fungovat.')) { await api('passkey_delete', form({ id: del.dataset.del })); flash('Zařízení je odebráno.'); await load(); }
  } catch (err) { flash(err.message, 'danger'); }
});
load().catch(err => flash(err.message, 'danger'));
