'use strict';
const { $, esc, api, form, flash, fmt } = App;
const mb = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' kB';
async function load() {
  const r = await api('backup_info');
  $('#info').textContent = `Databáze ${mb(r.db_size)}, ${r.uploads} nahraných souborů (${mb(r.uploads_size)}).` + (r.zip ? '' : ' Na hostingu chybí ZipArchive – stahujte jen databázi.');
  if (!r.zip) $('#dlZip').classList.add('disabled');
  $('#auto').checked = r.auto;
  $('#files').innerHTML = r.files.length ? r.files.map(f => `<a class="list-group-item list-group-item-action d-flex justify-content-between" href="zaloha.php?get=file&name=${encodeURIComponent(f.name)}"><span>${esc(f.name)}</span><span class="text-muted">${mb(f.size)}</span></a>`).join('')
    : '<div class="text-muted small">Zatím žádná uložená záloha.</div>';
}
$('#auto').addEventListener('change', async (e) => { try { await api('backup_set', form({ auto: e.target.checked ? 1 : '' })); flash(e.target.checked ? 'Automatická záloha zapnuta.' : 'Automatická záloha vypnuta.'); } catch (err) { flash(err.message, 'danger'); } });
$('#btnNow').onclick = async () => { try { await api('backup_now', form({})); flash('Záloha uložena.'); await load(); } catch (err) { flash(err.message, 'danger'); } };
load().catch(err => flash(err.message, 'danger'));

// ---------- Google Drive ----------
async function loadGd() {
  const g = await api('gd_info');
  const box = $('#gd');
  if (!g.configured) {
    box.innerHTML = `<p>Záloha na Google Drive zatím není nastavená. Do <code>app/config.php</code> doplňte <code>google_client_id</code> a <code>google_client_secret</code> (postup je v <a href="napoveda.php">Nápovědě</a>).</p>
      <p class="small text-muted mb-0">Do Google Cloud Console přidejte adresu pro přesměrování: <code>${esc(g.redirect)}</code></p>`;
    return;
  }
  if (!g.connected) {
    box.innerHTML = `${g.error ? `<div class="alert alert-danger"><div class="alert-message">${esc(g.error)}</div></div>` : ''}
      <p>Týdenní záloha se uloží do vašeho Google Drive do složky „Social planner – zálohy“. Aplikace vidí jen soubory, které sama vytvořila.</p>
      <a class="btn btn-primary" href="gdrive_connect.php">Propojit Google Drive</a>`;
    return;
  }
  box.innerHTML = `${g.error ? `<div class="alert alert-danger"><div class="alert-message"><strong>Poslední záloha selhala:</strong> ${esc(g.error)}</div></div>` : ''}
    <p class="mb-2">Propojeno${g.email ? ` s účtem <strong>${esc(g.email)}</strong>` : ''}. ${g.last ? `Poslední záloha: <strong>${esc(fmt(g.last))}</strong> (${esc(g.last_name)}).` : 'Zatím nebyla žádná záloha.'}</p>
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="gdOn" ${g.enabled ? 'checked' : ''}><label class="form-check-label" for="gdOn">Jednou týdně zálohovat na Google Drive</label></div>
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="gdUp" ${g.uploads ? 'checked' : ''}><label class="form-check-label" for="gdUp">Včetně nahraných obrázků a videí (zip může být velký)</label></div>
    <div class="d-flex align-items-center gap-2 mb-3"><label for="gdKeep" class="mb-0">Ponechat posledních</label><input type="number" class="form-control form-control-sm" style="width:80px" id="gdKeep" min="2" max="60" value="${g.keep}"><span>záloh</span></div>
    <div class="d-flex flex-wrap gap-2">
      <button class="btn btn-primary btn-sm" id="gdNow">Zálohovat na Drive teď</button>
      <a class="btn btn-outline-secondary btn-sm" href="gdrive_connect.php">Propojit znovu</a>
      <button class="btn btn-outline-danger btn-sm" id="gdOff">Odpojit</button>
    </div>`;
  const save = async () => { try { await api('gd_set', form({ enabled: $('#gdOn').checked ? 1 : '', uploads: $('#gdUp').checked ? 1 : '', keep: $('#gdKeep').value })); flash('Nastavení uloženo.'); } catch (e) { flash(e.message, 'danger'); } };
  ['#gdOn', '#gdUp', '#gdKeep'].forEach(sel => $(sel).addEventListener('change', save));
  $('#gdNow').onclick = async (ev) => { ev.target.disabled = true; ev.target.textContent = 'Nahrávám…'; try { const r = await api('gd_now', form({})); flash('Nahráno na Google Drive: ' + r.name); } catch (e) { flash(e.message, 'danger'); } await loadGd(); };
  $('#gdOff').onclick = async () => { if (!confirm('Odpojit Google Drive? Dosavadní zálohy na Drive zůstanou.')) return; try { await api('gd_disconnect', form({})); flash('Google Drive odpojen.'); } catch (e) { flash(e.message, 'danger'); } await loadGd(); };
}
loadGd().catch(err => { $('#gd').textContent = err.message; });
if (new URLSearchParams(location.search).get('gd')) flash('Google Drive propojen.');
