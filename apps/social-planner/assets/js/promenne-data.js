'use strict';
// Proměnné a data (od 1.5.0): karty Tabulka dat, Vzdálené zdroje, Počasí. Vzor: sekce Data a Nastavení → Počasí v TWB Plánovači médií.
(function () {
  const { $, $$, esc, api, form, flash, modal, modalHide } = App;
  const { fmtBig, fmtTime } = window.VarPick;
  const plural = (n, a, b, c) => n === 1 ? a : (n >= 2 && n <= 4 ? b : c);
  const copy = async (v) => { try { await navigator.clipboard.writeText(v); flash(`Zkopírováno: ${v}`); } catch { prompt('Zkopírujte zápis:', v); } };

  // karta podle adresy (#data, #zdroje, #pocasi)
  const tabByHash = { '#data': '#tabData', '#zdroje': '#tabSrc', '#pocasi': '#tabWx', '#promenne': '#tabVars' };
  const showTab = () => { const t = tabByHash[location.hash]; if (t) window.bootstrap.Tab.getOrCreateInstance($(`#pdTabs [data-bs-target="${t}"]`)).show(); };
  $$('#pdTabs [data-bs-toggle="tab"]').forEach((a) => a.addEventListener('shown.bs.tab', () => history.replaceState(null, '', a.getAttribute('href'))));
  window.addEventListener('hashchange', showTab);
  showTab();

  /* ================= Tabulka dat ================= */
  let rows = [], group = 'all', q = '';
  const groups = () => [...new Set(rows.map((r) => r.grp))].sort((a, b) => a.localeCompare(b, 'cs'));
  const sortRows = (a, b) => a.grp.localeCompare(b.grp, 'cs') || ((a.date || '9') < (b.date || '9') ? -1 : (a.date || '9') > (b.date || '9') ? 1 : 0)
    || a.param.localeCompare(b.param, 'cs', { numeric: true });
  async function reload() { rows = (await api('data_list')).rows; draw(); }
  function draw() {
    const gs = groups();
    if (group !== 'all' && !gs.includes(group)) group = 'all';
    const chip = (k, l, n) => `<button type="button" class="btn btn-sm tag-chip ${group === k ? 'on' : ''}" data-g="${esc(k)}">${esc(l)} <span class="cnt">${n}</span></button>`;
    $('#dGroups').innerHTML = chip('all', 'Vše', rows.length) + gs.map((g) => chip(g, g, rows.filter((r) => r.grp === g).length)).join('');
    $('#dGrpList').innerHTML = gs.map((g) => `<option value="${esc(g)}">`).join('');
    const past = rows.filter((r) => r.past).length;
    $('#dPast').textContent = `Smazat prošlé (${past})`; $('#dPast').disabled = !past;
    const list = rows.filter((r) => (group === 'all' || r.grp === group) && `${r.grp} ${r.param} ${r.value}`.toLowerCase().includes(q)).sort(sortRows);
    $('#dBody').innerHTML = list.length ? list.map((r) => `<tr class="${r.past ? 'past' : ''}" data-id="${r.id}">
        <td><input class="form-control form-control-sm" data-k="grp" value="${esc(r.grp)}" list="dGrpList"></td>
        <td><input class="form-control form-control-sm" data-k="param" value="${esc(r.param)}">${r.past ? '<span class="badge bg-secondary">prošlé</span>' : ''}</td>
        <td><input class="form-control form-control-sm" data-k="value" value="${esc(r.value)}"><span class="small text-muted">${esc(fmtBig(r.value))}</span></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-del="${r.id}" title="Smazat řádek">×</button></td></tr>`).join('')
      : `<tr><td colspan="4" class="text-center text-muted py-4">${rows.length ? 'Nic nenalezeno.' : 'Zatím žádná data. Přidejte řádek nebo vložte data z Excelu.'}</td></tr>`;
    drawHint();
  }
  async function drawHint() {
    const box = $('#dHint');
    if (group === 'all') { box.innerHTML = ''; return; }
    const g = group, dated = rows.some((r) => r.grp === g && r.date);
    const btns = (list) => list.map((v) => `<button type="button" class="btn btn-sm btn-outline-secondary py-0" data-copy="${esc(v)}" title="Zkopírovat">${esc(v)} ⧉</button>`).join(' ');
    if (!dated) { box.innerHTML = `<div class="alert alert-light py-2 small">V textu: ${btns([`{{${g}:${rows.find((r) => r.grp === g)?.param || ''}}}`])}</div>`; return; }
    const p = await api(`data_preview&grp=${encodeURIComponent(g)}`).catch(() => ({}));
    if (group !== g) return;
    box.innerHTML = `<div class="alert alert-light py-2 small d-flex flex-wrap gap-3 align-items-center">
      <span>Nejbližší (dnes): <b>${esc(fmtBig(p.nearest) || p.nearest || '—')}</b> (${esc(p.nearestParam || '—')})</span>
      <span>Poslední prošlý: <b>${esc(fmtBig(p.last) || p.last || '—')}</b></span>
      <span class="flex-grow-1"></span>${btns([`{{${g}.nejbližší|mil}}`, `{{${g}.nejbližší.parametr|datum:den}}`])}</div>`;
  }
  $('#dHint').addEventListener('click', (e) => { const b = e.target.closest('[data-copy]'); if (b) copy(b.dataset.copy); });
  $('#dGroups').addEventListener('click', (e) => { const b = e.target.closest('[data-g]'); if (b) { group = b.dataset.g; draw(); } });
  $('#dQ').addEventListener('input', (e) => { q = e.target.value.toLowerCase(); draw(); });
  $('#dBody').addEventListener('change', async (e) => {
    const tr = e.target.closest('tr[data-id]'); if (!tr) return;
    const v = (k) => tr.querySelector(`[data-k=${k}]`).value;
    try { await api('data_save', form({ id: tr.dataset.id, grp: v('grp'), param: v('param'), value: v('value') })); flash('Uloženo.'); await reload(); }
    catch (err) { flash(err.message, 'danger'); }
  });
  $('#dBody').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.matches('input')) e.target.blur(); });
  $('#dBody').addEventListener('click', async (e) => {
    const b = e.target.closest('[data-del]'); if (!b) return;
    try { const f = form({}); f.append('ids[]', b.dataset.del); await api('data_delete', f); flash('Řádek smazán.'); await reload(); } catch (err) { flash(err.message, 'danger'); }
  });
  $('#dAdd').onclick = () => {
    modal('Nový řádek', `<div class="row g-2">
      <div class="col-md-4"><label class="form-label">Skupina</label><input class="form-control" id="nG" list="dGrpList" value="${group !== 'all' ? esc(group) : ''}" placeholder="Sportka"></div>
      <div class="col-md-4"><label class="form-label">Parametr <span class="text-muted small">datum nebo text</span></label><input class="form-control" id="nP" placeholder="26.11.2026"></div>
      <div class="col-md-4"><label class="form-label">Hodnota</label><input class="form-control" id="nV" placeholder="125 000 000"></div></div>
      <div class="text-end mt-3"><button type="button" class="btn btn-outline-primary" id="nMore">Uložit a další</button> <button type="button" class="btn btn-primary" id="nSave">Uložit</button></div>`, 'md');
    (group !== 'all' ? $('#nP') : $('#nG')).focus();
    const save = async (again) => {
      try {
        await api('data_save', form({ grp: $('#nG').value, param: $('#nP').value, value: $('#nV').value }));
        flash('Řádek přidán.'); await reload();
        if (again) { $('#nP').value = ''; $('#nV').value = ''; $('#nP').focus(); } else modalHide();
      } catch (err) { flash(err.message, 'danger'); }
    };
    $('#nSave').onclick = () => save(false); $('#nMore').onclick = () => save(true);
    $('#nV').onkeydown = (ev) => { if (ev.key === 'Enter') save(true); };
  };
  $('#dImport').onclick = () => {
    modal('Vložit z Excelu', `<p class="text-muted small">V Excelu označte tři sloupce <b>Skupina, Parametr, Hodnota</b> (bez nebo se záhlavím), zkopírujte (Ctrl+C) a vložte sem (Ctrl+V). Sloupce mohou být oddělené i středníkem.</p>
      <textarea class="form-control font-monospace" id="iText" rows="10" placeholder="Sportka&#9;26.11.2026&#9;125000000&#10;Sportka&#9;28.11.2026&#9;131000000&#10;Ceník&#9;Káva&#9;45"></textarea>
      <div class="small text-muted mt-1" id="iInfo"></div>
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="iReplace"><label class="form-check-label" for="iReplace">Nahradit stávající řádky ve skupinách, které vkládám (jinak se jen přidají)</label></div>
      <div class="text-end mt-3"><button type="button" class="btn btn-primary" id="iGo">Vložit</button></div>`, 'md');
    $('#iText').oninput = () => {
      const lines = $('#iText').value.split(/\r?\n/).filter((l) => l.trim());
      $('#iInfo').textContent = lines.length ? `Rozpoznáno řádků: ${lines.filter((l) => l.split(/\t|;|\|/).length >= 3).length} z ${lines.length}` : '';
    };
    $('#iGo').onclick = async () => {
      try {
        const r = await api('data_import', form({ text: $('#iText').value, replace: $('#iReplace').checked ? 1 : '' }));
        modalHide(); flash(`Vloženo řádků: ${r.imported}.` + (r.errors.length ? ` Přeskočeno: ${r.errors.length} (${r.errors.slice(0, 2).join('; ')}).` : ''), r.errors.length ? 'warning' : 'success');
        await reload();
      } catch (err) { flash(err.message, 'danger'); }
    };
  };
  $('#dPast').onclick = async () => {
    if (!confirm('Smazat všechny řádky s datem v minulosti?')) return;
    try { const r = await api('data_delete_past', form({})); flash(`Smazáno prošlých řádků: ${r.deleted}.`); await reload(); } catch (err) { flash(err.message, 'danger'); }
  };

  /* ================= Vzdálené zdroje ================= */
  let sources = [];
  async function drawSources() {
    try { sources = (await api('sources')).sources; } catch (e) { $('#srcList').innerHTML = `<div class="text-danger">${esc(e.message)}</div>`; return; }
    $('#srcList').innerHTML = sources.length ? sources.map((x) => `<div class="src-row" data-id="${x.id}">
      <div class="flex-grow-1 min-w-0"><b>${esc(x.name)}</b> <span class="badge bg-light text-dark">${x.format === 'csv' ? 'CSV' : 'JSON'}</span>
        <span class="small text-muted">každých ${x.interval_min} min${x.rows != null ? ` · ${x.rows} ${plural(x.rows, 'řádek', 'řádky', 'řádků')}` : ''}</span>
        <div class="small text-muted text-truncate src-url">${esc(x.url.replace(/([?&](?:key|apikey|api_key|appid|token)=)[^&#]+/gi, '$1•••'))}</div>
        <div class="small">${x.error ? `<span class="badge bg-danger">chyba</span> ${esc(x.error)}${x.ok_at ? ` <span class="text-muted">– příspěvky použijí data z ${esc(fmtTime(x.ok_at))}</span>` : ''}`
          : x.ok_at ? `<span class="badge bg-success">v pořádku</span> <span class="text-muted">staženo ${esc(fmtTime(x.ok_at))}</span>` : '<span class="text-muted">zatím nestaženo</span>'}</div></div>
      <div class="d-flex flex-wrap gap-1 align-items-start"><button class="btn btn-sm btn-outline-primary" data-sf="${x.id}">Stáhnout teď</button>
        <button class="btn btn-sm btn-outline-secondary" data-sv="${x.id}" ${x.has_data ? '' : 'disabled'}>Prohlédnout</button>
        <button class="btn btn-sm btn-outline-secondary" data-se="${x.id}">Upravit</button><button class="btn btn-sm btn-outline-danger" data-sd="${x.id}" title="Smazat zdroj">×</button></div></div>`).join('')
      : '<p class="text-muted mb-0">Žádný zdroj.</p>';
  }
  function editSource(x) {
    modal(x ? `Upravit zdroj ${x.name}` : 'Nový zdroj', `<div class="row g-2">
      <div class="col-md-4"><label class="form-label">Název <span class="text-muted small">použije se v proměnné</span></label><input class="form-control" id="sN" value="${esc(x?.name || '')}" placeholder="Allwyn"></div>
      <div class="col-md-8"><label class="form-label">Adresa</label><input class="form-control" type="url" id="sU" value="${esc(x?.url || '')}" placeholder="https://…"></div>
      <div class="col-md-4"><label class="form-label">Formát</label><div class="btn-group w-100"><input type="radio" class="btn-check" name="sF" id="sFj" value="json"><label class="btn btn-outline-secondary" for="sFj">JSON</label>
        <input type="radio" class="btn-check" name="sF" id="sFc" value="csv"><label class="btn btn-outline-secondary" for="sFc">CSV</label></div></div>
      <div class="col-md-4"><label class="form-label">Stahovat každých (minut)</label><input class="form-control" type="number" id="sI" value="${x?.interval_min || 15}" min="5" max="1440"></div></div>
      <div id="sCsv" class="alert alert-light small mt-2 mb-0">
        <div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="sH" ${x?.csv_header === false ? '' : 'checked'}><label class="form-check-label" for="sH">První řádek obsahuje názvy sloupců</label></div>
        Oddělovač (<code>;</code> <code>,</code> tabulátor) i kódování (UTF-8, Windows-1250 z Excelu) se poznají samy. <b>Tabulka Google:</b> stačí vložit její běžný odkaz – musí být sdílená „Kdokoli s odkazem“.
        V textu: <code>{{Ceník:Káva.Cena}}</code> – řádek podle 1. sloupce, sloupec podle záhlaví; <code>{{Ceník:3.Cena}}</code> – 3. řádek dat.</div>
      <p class="form-text">Klíč k API patří přímo do adresy (např. <code>…&amp;key=VÁŠ_KLÍČ</code>); v přehledu se skryje. Cron běží každou minutu, interval pod 5 minut nemá smysl. Po uložení se zdroj hned zkusí stáhnout.</p>
      <div class="alert alert-danger py-2" id="sErr" hidden></div>
      <div class="text-end"><button type="button" class="btn btn-primary" id="sSave">Uložit</button></div>`, 'md');
    let fmt = x?.format || 'json';
    const drawFmt = () => { $('#sFj').checked = fmt === 'json'; $('#sFc').checked = fmt === 'csv'; $('#sCsv').hidden = fmt !== 'csv'; };
    $$('#appModal [name=sF]').forEach((r) => r.onchange = () => { fmt = r.value; drawFmt(); });
    $('#sU').oninput = () => { if (/docs\.google\.com\/spreadsheets|\.csv(\?|$)|format=csv|output=csv/i.test($('#sU').value) && fmt !== 'csv') { fmt = 'csv'; drawFmt(); } };
    drawFmt(); $('#sN').focus();
    $('#sSave').onclick = async (ev) => {
      const sb = ev.currentTarget; $('#sErr').hidden = true; sb.disabled = true;
      try {
        const r = await api('source_save', form({ id: x?.id || 0, name: $('#sN').value, url: $('#sU').value, interval_min: $('#sI').value, format: fmt, csv_header: $('#sH').checked ? 1 : 0 }));
        modalHide();
        r.ok ? flash('Zdroj uložen a stažen.') : flash('Zdroj uložen, ale stažení selhalo: ' + r.error, 'warning');
        drawSources();
      } catch (err) { $('#sErr').textContent = err.message; $('#sErr').hidden = false; sb.disabled = false; }
    };
  }
  $('#srcAdd').onclick = () => editSource(null);
  $('#srcList').addEventListener('click', async (e) => {
    const b = e.target.closest('button'); if (!b) return;
    const id = +(b.dataset.sf || b.dataset.sv || b.dataset.se || b.dataset.sd), x = sources.find((s) => s.id === id);
    if (b.dataset.se) editSource(x);
    else if (b.dataset.sv) window.VarPick.browseSource(id);
    else if (b.dataset.sf) {
      b.disabled = true; b.textContent = 'Stahuji…';
      try { const r = await api('source_fetch', form({ id })); r.ok ? flash(r.changed ? 'Staženo, data se změnila.' : 'Staženo, data beze změny.') : flash('Stažení selhalo: ' + r.error, 'danger'); }
      catch (err) { flash(err.message, 'danger'); }
      drawSources();
    } else if (b.dataset.sd) {
      if (!confirm(`Smazat zdroj „${x.name}“? Příspěvky s jeho proměnnými pak nepůjde uložit, dokud je z textu neodstraníte.`)) return;
      try { await api('source_delete', form({ id })); flash('Zdroj smazán.'); drawSources(); } catch (err) { flash(err.message, 'danger'); }
    }
  });

  /* ================= Počasí ================= */
  async function drawWeather() {
    let w;
    try { w = await api('weather_settings'); } catch (e) { $('#wxForm').textContent = e.message; return; }
    $('#wxForm').innerHTML = `
      <div class="mb-3"><label class="form-label" for="wKey">API klíč OpenWeather</label>
        <div class="input-group"><input class="form-control" id="wKey" type="password" autocomplete="off" placeholder="${w.key_set ? '•••••••• (uložený – nový zadáte přepsáním)' : 'vložte klíč'}">
        ${w.key_set ? '<button type="button" class="btn btn-outline-danger" id="wClear">Odebrat</button>' : ''}</div>
        <div class="form-text">Zdarma po registraci na <a href="${esc(w.key_url)}" target="_blank" rel="noopener">openweathermap.org</a> (API keys). Nový klíč začne fungovat do 2 hodin. Na Wedos se klíč nedostane jinak než přes tuto stránku.</div></div>
      <div class="mb-3"><label class="form-label" for="wPlace">Výchozí místo</label><input class="form-control" id="wPlace" value="${esc(w.place)}" placeholder="49.9884,17.4637 nebo Praha,CZ">
        <div class="form-text">Souřadnice (zeměpisná šířka, délka) nebo název obce. Použije se v <code>{{Počasí:…}}</code> bez místa.</div></div>
      <button type="button" class="btn btn-primary" id="wSave">Uložit</button> <button type="button" class="btn btn-outline-primary" id="wTest" ${w.key_set ? '' : 'disabled'}>Vyzkoušet</button>
      <div id="wResult" class="mt-3"></div>
      ${w.places.length ? `<h6 class="mt-3">Stažená místa <span class="text-muted small">(obnovují se každých ${w.interval} min)</span></h6><ul class="list-unstyled small mb-0">${w.places.map((p) => `<li>${esc(p.place)}: ${p.error
        ? `<span class="text-danger">${esc(p.error)}</span>${p.ok_at ? ` <span class="text-muted">(použijí se data z ${esc(fmtTime(p.ok_at))})</span>` : ''}` : `<span class="text-success">v pořádku</span> <span class="text-muted">${esc(fmtTime(p.ok_at))}</span>`}</li>`).join('')}</ul>` : ''}`;
    const body = () => form({ api_key: $('#wKey').value.trim(), place: $('#wPlace').value.trim() });
    $('#wSave').onclick = async () => { try { await api('weather_save', body()); flash('Nastavení počasí uloženo.'); drawWeather(); } catch (err) { flash(err.message, 'danger'); } };
    if ($('#wClear')) $('#wClear').onclick = async () => { if (!confirm('Odebrat API klíč? Proměnné počasí pak zůstanou prázdné.')) return; try { const f = body(); f.set('api_key', ''); f.set('clear_key', 1); await api('weather_save', f); flash('API klíč odebrán.'); drawWeather(); } catch (err) { flash(err.message, 'danger'); } };
    $('#wTest').onclick = async (ev) => {
      const tb = ev.currentTarget; tb.disabled = true; $('#wResult').innerHTML = '<span class="text-muted">Stahuji…</span>';
      try {
        if ($('#wKey').value.trim() || $('#wPlace').value.trim() !== w.place) await api('weather_save', body());
        const r = await api('weather_test', form({ place: '' }));
        $('#wResult').innerHTML = `<div class="wx-card"><img src="${esc(r.icon)}" alt="" width="56" height="56"><div><div class="h4 mb-0">${esc(r.temp)} °C <span class="h6 text-muted">${esc(r.name)}</span></div>
          <div class="small">${esc(r.desc)} · pocitově ${esc(r.feels)} °C · vlhkost ${esc(r.humidity)} % · vítr ${esc(r.wind)} m/s</div></div></div>
          <div class="d-flex gap-3 mt-2 small">${r.days.map((d) => `<div class="text-center"><b>${esc(d.label)}</b><br><img src="${esc(d.icon)}" alt="" width="36" height="36"><br>${esc(d.max)}° / ${esc(d.min)}°</div>`).join('')}</div>`;
      } catch (err) { $('#wResult').innerHTML = `<div class="alert alert-danger py-2 mb-0">${esc(err.message)}</div>`; }
      tb.disabled = false;
    };
  }

  reload().catch((e) => flash(e.message, 'danger'));
  drawSources();
  drawWeather();
})();
