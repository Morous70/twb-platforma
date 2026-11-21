'use strict';
// Obrázky z internetu do Knihovny médií (od 1.8.0, převzato z aplikace Kvízy): Wikimedia Commons, Openverse, Pixabay, Pexels.
// Stock.open({ tags, title, onDone(ids) }) – okno s hledáním, vložením odkazu a nastavením klíčů. Obrázek se stáhne k nám
// (nenačítá se z cizího serveru) i s autorem a licencí, jak je uvádí zdroj.
(function () {
  const { $, $$, esc, api, form, flash, modal, modalHide } = App;
  let P = null;   // { providers, licenses }
  const LS = 'sp_stock_prov';

  async function providers(force) {
    if (!P || force) P = await api('stock_providers');
    return P;
  }

  async function open(o = {}) {
    try { await providers(true); } catch (e) { flash(e.message, 'danger'); return; }
    const ready = P.providers.filter((p) => p.ready);
    let prov = ready[0]?.key || 'commons';
    try { const l = localStorage.getItem(LS); if (ready.some((p) => p.key === l)) prov = l; } catch { /* soukromý režim */ }
    const tags = o.tags || [];
    modal(o.title || 'Obrázky z internetu', `
      <div class="row g-2 mb-2">
        <div class="col-md-7"><div class="input-group">
          <select class="form-select" id="stProv" style="max-width:45%" aria-label="Zdroj obrázků">${P.providers.map((p) => `<option value="${p.key}"${p.key === prov ? ' selected' : ''}${p.ready ? '' : ' disabled'}>${esc(p.name)}${p.ready ? '' : ' – potřebuje klíč'}</option>`).join('')}</select>
          <input type="search" class="form-control" id="stQ" placeholder="Hledat (např. Pražský hrad, káva, Vánoce)" autocomplete="off"></div></div>
        <div class="col-md-5"><div class="input-group"><input type="url" class="form-control" id="stUrl" placeholder="…nebo vložte odkaz na obrázek"><button type="button" class="btn btn-outline-primary" id="stUrlGo">Vložit</button></div></div>
      </div>
      <p class="small text-muted mb-2">Vybraný obrázek se stáhne do Knihovny médií${tags.length ? ` s tagem <b>${esc(tags.join(', '))}</b>` : ''} a převezme autora i licenci, jak je uvádí zdroj – zkontrolujte je u obrázku, zadávají je sami autoři.
        U licencí CC BY a CC BY-SA je potřeba autora uvést v příspěvku (Nový příspěvek to připomene). Výsledky poskytuje <a id="stProvLink" href="#" target="_blank" rel="noopener"></a>.
        <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="stMulti">Více odkazů najednou</button> · <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="stKeys">Klíče Pixabay / Pexels</button></p>
      <div id="stMultiBox" hidden class="mb-2"><textarea class="form-control font-monospace" id="stUrls" rows="4" placeholder="Jeden odkaz na řádek (nejvýš 20) – Wikimedia Commons, Openverse, Pixabay, Pexels"></textarea>
        <div class="text-end mt-1"><button type="button" class="btn btn-sm btn-primary" id="stUrlsGo">Vložit všechny</button></div></div>
      <div class="small mb-2" id="stMsg"></div>
      <div class="lib-grid stock-grid" id="stGrid"></div>`, 'xl');
    const msg = (t, cls = 'text-muted') => { $('#stMsg').className = 'small mb-2 ' + cls; $('#stMsg').textContent = t; };
    const syncLink = () => { const p = P.providers.find((x) => x.key === $('#stProv').value); $('#stProvLink').textContent = p.name; $('#stProvLink').href = p.url; };
    syncLink();
    let timer = null, seq = 0, items = [];
    const search = async () => {
      const q = $('#stQ').value.trim(), my = ++seq;
      $('#stGrid').innerHTML = '';
      if (!q) { msg(''); return; }
      msg('Hledám…');
      try {
        const r = await api(`stock_search&p=${encodeURIComponent($('#stProv').value)}&q=${encodeURIComponent(q)}`);
        if (my !== seq) return;
        items = r.items;
        msg(items.length ? '' : 'Nic nenalezeno. Zkuste jiná slova (i anglicky), jiný zdroj, nebo vložte odkaz na konkrétní obrázek.');
        $('#stGrid').innerHTML = items.map((it, i) => `<div class="lib-card" data-i="${i}" title="${esc(it.title + (it.credit_text ? ' — ' + it.credit_text : ''))}">
          ${it.in_library ? '<span class="lib-slide" style="left:6px">v knihovně</span>' : ''}
          <div class="lib-thumb"><img src="${esc(it.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer"></div>
          <div class="lib-meta"><div class="lib-name text-truncate">${esc(it.title)}</div><div class="small text-muted text-truncate">${esc(it.credit_text || 'autor a licence neuvedeny')}</div>
          ${it.width ? `<div class="small text-muted">${it.width}×${it.height}</div>` : ''}</div></div>`).join('');
      } catch (e) { if (my === seq) msg(e.message, 'text-danger'); }
    };
    const doImport = async (body) => {
      msg('Stahuji obrázek do knihovny…');
      $$('#appModal button').forEach((b) => { b.disabled = true; });
      try {
        const f = form(body); tags.forEach((t) => f.append('tags[]', t));
        const r = await api('stock_import', f);
        // další okno (náhled, výběr) až po úplném zavření tohoto – jinak by ho Bootstrap nechal skryté
        document.getElementById('appModal').addEventListener('hidden.bs.modal', () => o.onDone?.(r.ids), { once: true });
        modalHide();
        flash((r.new ? `Vloženo do knihovny: ${r.new}.` : '') + (r.had ? ` Už v knihovně bylo: ${r.had}.` : '') + (r.errors.length ? ` Nepovedlo se: ${r.errors.join('; ')}` : ''), r.errors.length ? 'warning' : 'success');
      } catch (e) { msg(e.message, 'text-danger'); $$('#appModal button').forEach((b) => { b.disabled = false; }); }
    };
    $('#stProv').onchange = () => { syncLink(); try { localStorage.setItem(LS, $('#stProv').value); } catch { /* */ } search(); };
    $('#stQ').oninput = () => { clearTimeout(timer); timer = setTimeout(search, 450); };
    $('#stQ').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); search(); } };
    $('#stUrlGo').onclick = () => { const u = $('#stUrl').value.trim(); if (u) doImport({ url: u }); else $('#stUrl').focus(); };
    $('#stUrl').onkeydown = (e) => { if (e.key === 'Enter') { e.preventDefault(); $('#stUrlGo').click(); } };
    $('#stMulti').onclick = () => { $('#stMultiBox').hidden = !$('#stMultiBox').hidden; if (!$('#stMultiBox').hidden) $('#stUrls').focus(); };
    $('#stUrlsGo').onclick = () => { if ($('#stUrls').value.trim()) doImport({ urls: $('#stUrls').value }); };
    $('#stKeys').onclick = () => keys(() => open(o));
    $('#stGrid').onclick = (e) => { const c = e.target.closest('[data-i]'); if (c) { const it = items[+c.dataset.i]; doImport({ url: it.ref || it.page_url }); } };
    setTimeout(() => $('#stQ')?.focus(), 300);
  }

  /** Klíče Pixabay a Pexels (zůstávají na serveru, do prohlížeče se nevrací). */
  async function keys(back) {
    let k;
    try { k = await api('stock_keys'); } catch (e) { flash(e.message, 'danger'); return; }
    const row = (p, name, url, hint) => `<div class="mb-3"><label class="form-label" for="k_${p}">API klíč ${name}</label>
      <div class="input-group"><input class="form-control" id="k_${p}" type="password" autocomplete="off" spellcheck="false" placeholder="${k[p] ? '•••••••• (uložený – nový zadáte přepsáním)' : 'vložte klíč'}">
      ${k[p] ? `<button type="button" class="btn btn-outline-danger" data-clear="${p}">Odebrat</button>` : ''}</div>
      <div class="form-text">${hint} <a href="${url}" target="_blank" rel="noopener">${url.replace('https://', '')}</a></div></div>`;
    modal('Klíče pro obrázky z internetu', `<p class="small text-muted">Wikimedia Commons a Openverse fungují bez registrace. Pixabay a Pexels se zapnou vložením vašeho klíče (zdarma po přihlášení). Klíče se používají jen na serveru.</p>
      ${row('pixabay', 'Pixabay', 'https://pixabay.com/api/docs/', 'Klíč najdete po přihlášení v modrém rámečku „Your API key“ na')}
      ${row('pexels', 'Pexels', 'https://www.pexels.com/api/', 'Tlačítko „Your API Key“ na (limit 200 hledání za hodinu)')}
      <div class="text-end"><button type="button" class="btn btn-outline-secondary" id="kBack">Zpět</button> <button type="button" class="btn btn-primary" id="kSave">Uložit</button></div>`, 'md');
    const save = async (extra = {}) => {
      try { await api('stock_keys', form({ pixabay_key: $('#k_pixabay').value.trim(), pexels_key: $('#k_pexels').value.trim(), ...extra })); flash('Klíče uloženy.'); back(); }
      catch (e) { flash(e.message, 'danger'); }
    };
    $('#kSave').onclick = () => save();
    $('#kBack').onclick = back;
    $$('#appModal [data-clear]').forEach((b) => b.onclick = () => { if (confirm('Odebrat klíč? Zdroj pak nebude k dispozici.')) save({ ['clear_' + b.dataset.clear]: 1 }); });
  }

  /** Úprava autora a licence souboru v knihovně. it = položka z api('library'). */
  async function editCredit(it, done) {
    try { await providers(); } catch (e) { flash(e.message, 'danger'); return; }
    const c = it.credit || {};
    modal(`Autor a licence: ${it.name}`, `<div class="row g-2">
      <div class="col-md-6"><label class="form-label" for="crA">Autor</label><input class="form-control" id="crA" maxlength="120" value="${esc(c.author || '')}"></div>
      <div class="col-md-6"><label class="form-label" for="crS">Zdroj</label><input class="form-control" id="crS" maxlength="200" value="${esc(c.source || '')}" placeholder="např. Wikimedia Commons"></div>
      <div class="col-md-6"><label class="form-label" for="crL">Licence</label><select class="form-select" id="crL"><option value="">— neuvedeno —</option>${P.licenses.map((l) => `<option value="${l.key}"${l.key === c.license ? ' selected' : ''}>${esc(l.name)}</option>`).join('')}</select>
        <div class="form-text" id="crHint"></div></div>
      <div class="col-md-6"><label class="form-label" for="crO">Verze / název licence</label><input class="form-control" id="crO" maxlength="120" value="${esc(c.license_other || '')}" placeholder="např. 4.0"></div>
      <div class="col-md-6"><label class="form-label" for="crSu">Odkaz na zdroj</label><input class="form-control" id="crSu" type="url" value="${esc(c.source_url || '')}"></div>
      <div class="col-md-6"><label class="form-label" for="crLu">Odkaz na licenci</label><input class="form-control" id="crLu" type="url" value="${esc(c.license_url || '')}"></div></div>
      <div class="text-end mt-3"><button type="button" class="btn btn-primary" id="crSave">Uložit</button></div>`, 'md');
    const hint = () => { const l = P.licenses.find((x) => x.key === $('#crL').value); $('#crHint').textContent = l ? l.hint : ''; $('#crO').disabled = !['cc-by', 'cc-by-sa', 'jine'].includes($('#crL').value); };
    $('#crL').onchange = hint; hint();
    $('#crSave').onclick = async () => {
      try {
        await api('library_credit', form({ id: it.id, author: $('#crA').value, source: $('#crS').value, license: $('#crL').value, license_other: $('#crO').value, source_url: $('#crSu').value, license_url: $('#crLu').value }));
        modalHide(); flash('Autor a licence uloženy.'); done?.();
      } catch (e) { flash(e.message, 'danger'); }
    };
  }

  /** Text uvedení autora do příspěvku: „Foto: Autor, Zdroj, CC BY-SA 4.0 (upraveno)“. */
  const creditLine = (it) => it.credit_text ? 'Foto: ' + it.credit_text.split(' · ').join(', ') : '';

  window.Stock = { open, keys, editCredit, creditLine };
})();
