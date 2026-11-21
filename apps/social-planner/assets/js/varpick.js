'use strict';
// Výběr proměnné (od 1.5.0, převzato z TWB Plánovače médií): kalendář, vlastní proměnné, tabulka dat, vzdálené zdroje, počasí
// a dialogy Formát čísla / Formát data. → window.VarPick { open(cb), browseSource(id), pickDateFormat, pickNumFormat, load }
(function () {
  const { $, $$, esc, api, form, flash, modal, modalHide } = App;
  let D = null;   // { user, sources, data, weather }

  async function load(force = false) {
    if (D && !force) return D;
    const [v, s, d, w] = await Promise.all([api('variables'), api('source_tree'), api('data_list'), api('weather_settings')].map((p) => p.catch(() => null)));
    D = { user: v?.variables || [], sources: s?.sources || [], data: d?.rows || [], weather: w || { place: '', key_set: false } };
    return D;
  }
  const fmtBig = (v) => {
    const n = Number(String(v).replace(/\s/g, '').replace(',', '.'));
    if (v === '' || isNaN(n)) return '';
    if (Math.abs(n) >= 1e9) return (n / 1e9).toLocaleString('cs-CZ', { maximumFractionDigits: 2 }) + ' mld.';
    if (Math.abs(n) >= 1e6) return (n / 1e6).toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) + ' mil.';
    return n.toLocaleString('cs-CZ');
  };
  const fmtTime = (ts) => ts ? new Date(ts * 1000).toLocaleString('cs-CZ', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  const values = (expr, formats) => { const f = form({ expr }); formats.forEach((x, i) => f.append(`formats[${i}]`, x)); return api('datefmt', f).then((r) => r.values).catch(() => formats.map(() => null)); };

  /* ---------- formát data ---------- */
  const DATE_FORMATS = [
    ['|datum', 'číslem'], ['|datum:kratke', 'bez roku'], ['|datum:bezmezer', 'bez mezer'], ['|datum:nuly', 's nulami (05.03.2026)'],
    ['|datum:slovem', 'měsíc slovem, bez roku'], ['|datum:dlouhe', 'měsíc slovem'], ['|datum:den', 's dnem, bez roku'], ['|datum:den-plne', 's dnem'],
    ['|datum:den-slovem', 's dnem a měsícem slovem, bez roku'], ['|datum:den-dlouhe', 's dnem a měsícem slovem'],
    ['|datum:dentydne', 'jen den v týdnu'], ['|datum:mesic-rok', 'měsíc a rok'], ['|odpocet', 'odpočet (za 5 dní / zítra / dnes)'],
  ];
  const DATE_TOKENS_HELP = 'D 5 · DD 05 · M 3 · MM 03 · MĚSÍC březen · MĚSÍCE března · RR 26 · RRRR 2026 · DEN čtvrtek · DN čt';
  function fmtList(id, list, cur) {
    return `<div class="list-group vp-fmt" id="${id}">${list.map(([f, l]) => `<button type="button" class="list-group-item list-group-item-action${f === cur ? ' active' : ''}" data-f="${esc(f)}">
      <strong class="df-ex">…</strong><div class="small text-muted">${esc(l)}${f ? ` <code>${esc(f)}</code>` : ''}</div></button>`).join('')}</div>`;
  }
  function pickDateFormat(expr, cur, cb) {
    modal('Formát data', `<p class="text-muted small mb-2">Proměnná <code>{{${esc(expr)}}}</code>. Ukázky jsou s dnešními hodnotami; v příspěvku se použije den zveřejnění.</p>
      ${fmtList('dfList', DATE_FORMATS, cur)}
      <h6 class="mt-3">Vlastní formát</h6>
      <div class="input-group"><input type="text" class="form-control" id="dfCustom" maxlength="60" placeholder="např. DEN D. MĚSÍCE RRRR"><button class="btn btn-primary" type="button" id="dfUse">Použít</button></div>
      <div class="small mt-1" id="dfPrev">&nbsp;</div><div class="form-text">Zástupné znaky (velkými písmeny): ${esc(DATE_TOKENS_HELP)}. Ostatní znaky zůstanou, jak jsou.</div>`, 'md');
    values(expr, DATE_FORMATS.map(([f]) => f)).then((vals) => $$('#dfList .df-ex').forEach((el, i) => { el.textContent = vals[i] ?? '(hodnota teď chybí)'; }));
    $$('#dfList [data-f]').forEach((b) => b.onclick = () => cb(b.dataset.f));
    const clean = () => $('#dfCustom').value.replace(/[{}|]/g, '').trim();
    let t = null;
    $('#dfCustom').oninput = () => {
      clearTimeout(t); const c = clean();
      if (!c) { $('#dfPrev').innerHTML = '&nbsp;'; return; }
      t = setTimeout(async () => { const [v] = await values(expr, [`|datum:${c}`]); if (clean() === c) $('#dfPrev').textContent = `Ukázka: ${v ?? 'hodnota teď chybí'}`; }, 250);
    };
    $('#dfCustom').onkeydown = (e) => { if (e.key === 'Enter') $('#dfUse').click(); };
    $('#dfUse').onclick = () => { const c = clean(); if (c) cb(`|datum:${c}`); else $('#dfCustom').focus(); };
  }

  /* ---------- formát čísla ---------- */
  const NUM_FORMATS = [
    ['', 'jak je'], ['|cislo', 's mezerami (nejvýš 2 desetinná místa)'], ['|cislo:0', 'zaokrouhleno na celé'], ['|cislo:0,00', 'vždy 2 desetinná místa'],
    ['|kc', 'v Kč'], ['|kc:0,00', 'v Kč s haléři (45,50 Kč)'], ['|tis', 'v tisících'], ['|mil', 'v milionech'], ['|mil|pripona: Kč', 'v milionech Kč'],
    ['|milionu', 'v milionech slovem'], ['|milionu|pripona: Kč', 'v milionech slovem s Kč'], ['|mld', 'v miliardách'], ['|miliard', 'v miliardách slovem'],
    ['|cislo:0|bezmezer', 'celé bez mezer'],
  ];
  function numFilterFrom(o) {
    const dec = o.dec === '' ? '' : o.fixed && +o.dec > 0 ? `:0,${'0'.repeat(+o.dec)}` : `:${o.dec}`;
    let f = o.unit ? `|${o.unit}${dec}` : `|${o.kc ? 'kc' : 'cislo'}${dec}`;
    if (o.nosp) f += '|bezmezer';
    if (o.unit && o.kc) f += '|pripona: Kč';
    return f;
  }
  function pickNumFormat(expr, cur, cb) {
    const opt = (v, l) => `<option value="${v}">${l}</option>`;
    modal('Formát čísla', `<p class="text-muted small mb-2">Proměnná <code>{{${esc(expr)}}}</code>. Ukázky jsou s aktuální hodnotou.</p>
      ${fmtList('nfList', NUM_FORMATS, cur)}
      <h6 class="mt-3">Vlastní formát</h6>
      <div class="row g-2"><div class="col-6"><label class="form-label small mb-0">Jednotka</label><select class="form-select form-select-sm" id="nfUnit">${opt('', 'bez převodu')}${opt('tis', 'tisíce (tis.)')}${opt('mil', 'miliony (mil.)')}${opt('milionu', 'miliony slovem')}${opt('mld', 'miliardy (mld.)')}${opt('miliard', 'miliardy slovem')}</select></div>
        <div class="col-6"><label class="form-label small mb-0">Desetinná místa</label><select class="form-select form-select-sm" id="nfDec">${opt('', 'automaticky')}${opt('0', '0')}${opt('1', '1')}${opt('2', '2')}${opt('3', '3')}</select></div></div>
      <div class="form-check form-check-inline mt-2"><input class="form-check-input" type="checkbox" id="nfFixed"><label class="form-check-label small" for="nfFixed">vždy všechna místa</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="nfKc"><label class="form-check-label small" for="nfKc">Kč</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="nfNosp"><label class="form-check-label small" for="nfNosp">bez mezer</label></div>
      <div class="d-flex align-items-center gap-2 mt-2"><div class="small flex-grow-1" id="nfPrev">&nbsp;</div><button type="button" class="btn btn-primary btn-sm" id="nfUse">Použít</button></div>`, 'md');
    values(expr, NUM_FORMATS.map(([f]) => f)).then((vals) => $$('#nfList .df-ex').forEach((el, i) => { el.textContent = vals[i] ?? '(hodnota teď chybí)'; }));
    $$('#nfList [data-f]').forEach((b) => b.onclick = () => cb(b.dataset.f));
    const custom = () => numFilterFrom({ unit: $('#nfUnit').value, dec: $('#nfDec').value, fixed: $('#nfFixed').checked, kc: $('#nfKc').checked, nosp: $('#nfNosp').checked });
    let t = null;
    const prev = () => { clearTimeout(t); const f = custom(); t = setTimeout(async () => { const [v] = await values(expr, [f]); if (custom() === f) $('#nfPrev').innerHTML = `Ukázka: <b>${esc(v ?? 'hodnota teď chybí')}</b> <code>${esc(f)}</code>`; }, 200); };
    ['#nfUnit', '#nfDec', '#nfFixed', '#nfKc', '#nfNosp'].forEach((id) => { $(id).onchange = prev; });
    $('#nfUse').onclick = () => cb(custom());
    prev();
  }

  /* ---------- hlavní okno „Vložit proměnnou“ ---------- */
  const CAL = [
    ['@date:datum', 'Datum zveřejnění – vybrat formát…'], ['@date:datum:+1', 'Následující den – vybrat formát…'], ['{{den}}', 'Den v týdnu (pátek)'], ['{{Den}}', 'Den s velkým (Pátek)'],
    ['{{svatek}}', 'Kdo má svátek v den zveřejnění'], ['{{svatek:+1}}', 'Kdo má svátek následující den'], ['{{mesic}}', 'Měsíc (listopad)'], ['{{rok}}', 'Rok'],
    ['{{tyden}}', 'Číslo týdne'], ['{{cas}}', 'Čas zveřejnění (18:30)'], ['@ask:odpocet', 'Odpočet do data (za 5 dní / zítra / dnes)…'], ['@ask:dnu', 'Počet dní do data (číslo)…'],
  ];
  const WEATHER = [
    ['{{Počasí:teplota}}', 'Teplota (číslo, °C)'], ['{{Počasí:pocitová}}', 'Pocitová teplota'], ['{{Počasí:popis}}', 'Popis (Zataženo)'],
    ['{{Počasí:vlhkost}}', 'Vlhkost v %'], ['{{Počasí:vítr}}', 'Vítr v m/s'], ['{{Počasí:místo}}', 'Název místa'], ['{{Počasí:dnes.max}}', 'Dnes nejvyšší teplota'], ['{{Počasí:dnes.min}}', 'Dnes nejnižší teplota'],
    ['{{Počasí:zítra.max}}', 'Zítra nejvyšší teplota'], ['{{Počasí:zítra.min}}', 'Zítra nejnižší teplota'], ['{{Počasí:zítra.popis}}', 'Zítra popis'],
    ['{{Počasí:pozítří.den}}', 'Den pozítří (Sobota)'], ['{{Počasí:pozítří.max}}', 'Pozítří nejvyšší teplota'],
  ];
  const SRC_FORMATS = {
    number: [['', 'jak je'], ['|mil', 'v mil.'], ['|cislo', 's mezerami'], ['@num', 'jiný formát…']],
    date: [['', 'jak je'], ['|datum', 'datum'], ['@date', 'jiný formát…'], ['|odpocet', 'odpočet']],
    list: [['', 'mezery'], ['|cisla:, ', 'čárky']],
    text: [['', 'vložit']],
  };
  const btn = (v, l, primary) => `<button type="button" class="btn btn-sm ${primary ? 'btn-outline-primary' : 'btn-link'} py-0" data-v="${esc(v)}">${esc(l)}</button>`;
  const row = (title, code, val, btns) => `<div class="vp-item"><div class="flex-grow-1 min-w-0"><b>${esc(title)}</b>${code ? ` <code class="small">${esc(code)}</code>` : ''}${val !== undefined && val !== '' ? `<div class="vp-val">${esc(val)}</div>` : ''}</div><div class="vp-btns">${btns}</div></div>`;

  function srcBody(state, mode) {
    const s = D.sources.find((x) => x.id === state.sid);
    if (!s) return '<p class="text-muted">Žádný zdroj. Přidejte ho v nabídce Proměnné a data → Vzdálené zdroje.</p>';
    const info = s.error ? `<div class="alert alert-warning py-1 px-2 small mb-2">Poslední stahování selhalo: ${esc(s.error)}${s.ok_at ? ` Zobrazená data jsou z ${esc(fmtTime(s.ok_at))}.` : ''}</div>`
      : s.ok_at ? `<p class="text-muted small mb-2">Staženo ${esc(fmtTime(s.ok_at))}. ${mode === 'browse' ? 'Kliknutím zkopírujete zápis.' : 'Kliknutím na formát vložíte proměnnou.'}</p>` : '';
    if (!s.groups.length) return info + '<p class="text-muted">Zdroj zatím nemá stažená data.</p>';
    const q = state.q.toLowerCase();
    const html = s.groups.map((g) => {
      const items = g.items.filter((i) => (state.tech || !i.tech) && `${g.title} ${i.label} ${i.path} ${i.value}`.toLowerCase().includes(q));
      if (!items.length) return '';
      return `<h6 class="vp-group">${esc(g.title)}</h6>${items.map((i) => {
        const v = `${s.name}:${i.path}`;
        const val = i.kind === 'number' && !/id/.test(i.key) && Math.abs(+i.value) >= 1e6 ? `${i.value} (${fmtBig(i.value)})` : i.value;
        const b = mode === 'browse' ? btn(`{{${v}}}`, 'Kopírovat', true)
          : SRC_FORMATS[i.kind].map(([f, l], k) => btn(f.startsWith('@') ? `${f}:${v}` : `{{${v}${f}}}`, l, k === 0)).join('');
        return row(i.label, i.path, val, b);
      }).join('')}`;
    }).join('');
    return info + (html || '<p class="text-muted">Nic nenalezeno.</p>');
  }

  function dataBody() {
    const groups = [...new Set(D.data.map((r) => r.grp))].sort((a, b) => a.localeCompare(b, 'cs'));
    if (!groups.length) return '<p class="text-muted">Tabulka dat je prázdná. Řádky přidáte v nabídce Proměnné a data → Tabulka dat.</p>';
    return groups.map((g) => {
      const rows = D.data.filter((r) => r.grp === g);
      const dated = rows.some((r) => r.date);
      const items = dated
        ? [[`${g}.nejbližší`, 'hodnota s nejbližším datem (den zveřejnění a dál)', 'number'], [`${g}.nejbližší.parametr`, 'jeho datum', 'date'],
           [`${g}.další`, 'následující po nejbližším', 'number'], [`${g}.poslední`, 'poslední s datem v minulosti', 'number']]
        : rows.slice(0, 30).map((r) => [`${g}:${r.param}`, r.value, isNaN(Number(String(r.value).replace(',', '.'))) || r.value === '' ? 'text' : 'number']);
      return `<h6 class="vp-group">${esc(g)}</h6>` + items.map(([e, l, k]) => row(l, e, '', SRC_FORMATS[k].map(([f, t], i) => btn(f.startsWith('@') ? `${f}:${e}` : `{{${e}${f}}}`, t, i === 0)).join(''))).join('');
    }).join('');
  }

  async function open(cb, tab = 'cal') {
    try { await load(true); } catch (e) { flash(e.message, 'danger'); return; }
    const st = { tab, sid: (D.sources.find((s) => s.groups.length) || D.sources[0] || {}).id, q: '', tech: false };
    const TABS = [['cal', 'Kalendář'], ['user', 'Vlastní'], ['data', 'Tabulka dat'], ['src', 'Zdroje'], ['wx', 'Počasí']];
    const done = (v) => { modalHide(); cb(v); };
    const draw = () => {
      let body;
      if (st.tab === 'cal') body = CAL.map(([v, l]) => row(l, v.startsWith('@') ? '' : v, '', btn(v, 'Vložit', true))).join('');
      else if (st.tab === 'user') body = (D.user.length ? D.user.map((u) => row(u.type === 'list' ? 'seznam (při každém opakování další)' : u.value.split('\n')[0], `{{${u.name}}}`, '', btn(`{{${u.name}}}`, 'Vložit', true))).join('')
        : '<p class="text-muted">Zatím žádné vlastní proměnné.</p>') + row('Pořadí výskytu opakování (1, 2, 3 …)', '{{cislo}}', '', btn('{{cislo}}', 'Vložit', true));
      else if (st.tab === 'data') body = dataBody();
      else if (st.tab === 'src') body = `<div class="d-flex gap-2 mb-2">${D.sources.length > 1 ? `<select class="form-select form-select-sm w-auto" id="vpS">${D.sources.map((s) => `<option value="${s.id}"${s.id === st.sid ? ' selected' : ''}>${esc(s.name)}</option>`).join('')}</select>` : ''}
          <input type="search" class="form-control form-control-sm" id="vpQ" placeholder="Hledat…" value="${esc(st.q)}">
          <div class="form-check text-nowrap small ms-1"><input class="form-check-input" type="checkbox" id="vpT"${st.tech ? ' checked' : ''}><label class="form-check-label" for="vpT">i technické</label></div></div><div id="vpSrc">${srcBody(st, 'text')}</div>`;
      else body = `<p class="text-muted small mb-2">Výchozí místo: <b>${esc(D.weather.place || 'nenastaveno')}</b>${D.weather.key_set ? '' : ' · <span class="text-danger">chybí API klíč</span>'}. Jiné místo: <code>{{Počasí:Brno.teplota}}</code>. Hodnoty „teď“ jsou z posledního stažení před zveřejněním.</p>`
        + WEATHER.map(([v, l]) => row(l, v, '', btn(v, 'Vložit', true))).join('');
      $('#vpBody').innerHTML = body;
      $$('#vpTabs [data-tab]').forEach((b) => b.classList.toggle('active', b.dataset.tab === st.tab));
      if ($('#vpS')) $('#vpS').onchange = (e) => { st.sid = +e.target.value; $('#vpSrc').innerHTML = srcBody(st, 'text'); };
      if ($('#vpQ')) $('#vpQ').oninput = (e) => { st.q = e.target.value; $('#vpSrc').innerHTML = srcBody(st, 'text'); };
      if ($('#vpT')) $('#vpT').onchange = (e) => { st.tech = e.target.checked; $('#vpSrc').innerHTML = srcBody(st, 'text'); };
    };
    modal('Vložit proměnnou', `<ul class="nav nav-pills nav-fill mb-3 small" id="vpTabs">${TABS.map(([k, l]) => `<li class="nav-item"><button type="button" class="nav-link py-1" data-tab="${k}">${l}</button></li>`).join('')}</ul>
      <div id="vpBody" class="vp-body"></div>
      <p class="form-text mb-0">Hodnoty se dosadí až při zveřejnění, podle jeho data a času. Náhled příspěvku je ukazuje předem. Přehled zápisů a formátů je v Nápovědě.</p>`, 'xl');
    $('#vpTabs').onclick = (e) => { const b = e.target.closest('[data-tab]'); if (b) { st.tab = b.dataset.tab; draw(); } };
    $('#vpBody').onclick = (e) => {
      const b = e.target.closest('[data-v]'); if (!b) return;
      const v = b.dataset.v;
      if (v.startsWith('@date:')) { const x = v.slice(6); pickDateFormat(x, '', (f) => done(`{{${x}${f}}}`)); return; }
      if (v.startsWith('@num:')) { const x = v.slice(5); pickNumFormat(x, '', (f) => done(`{{${x}${f}}}`)); return; }
      if (v.startsWith('@ask:')) {
        const d = prompt('Datum, ke kterému se počítá (např. 24.12.2026):', `24.12.${new Date().getFullYear()}`);
        if (d && /^\s*\d{1,2}\.\s*\d{1,2}\.\s*\d{4}\s*$/.test(d)) done(`{{${v.slice(5)}:${d.replace(/\s+/g, '')}}}`);
        else if (d) flash('Datum zadejte ve tvaru 24.12.2026.', 'warning');
        return;
      }
      done(v);
    };
    draw();
  }

  /** Prohlížení dat zdroje s kopírováním zápisu (stránka Proměnné a data). */
  async function browseSource(id) {
    try { await load(true); } catch (e) { flash(e.message, 'danger'); return; }
    const s = D.sources.find((x) => x.id === id);
    const st = { sid: id, q: '', tech: false };
    modal(`Data zdroje ${s ? s.name : ''}`, `<div class="d-flex gap-2 mb-2"><input type="search" class="form-control form-control-sm" id="vpQ" placeholder="Hledat…">
      <div class="form-check text-nowrap small"><input class="form-check-input" type="checkbox" id="vpT"><label class="form-check-label" for="vpT">i technické údaje</label></div></div>
      <div id="vpSrc" class="vp-body">${srcBody(st, 'browse')}</div>`, 'xl');
    $('#vpQ').oninput = (e) => { st.q = e.target.value; $('#vpSrc').innerHTML = srcBody(st, 'browse'); };
    $('#vpT').onchange = (e) => { st.tech = e.target.checked; $('#vpSrc').innerHTML = srcBody(st, 'browse'); };
    $('#vpSrc').onclick = async (e) => {
      const b = e.target.closest('[data-v]'); if (!b) return;
      try { await navigator.clipboard.writeText(b.dataset.v); flash(`Zkopírováno: ${b.dataset.v}`); } catch { prompt('Zkopírujte zápis:', b.dataset.v); }
    };
  }

  window.VarPick = { load, open, browseSource, pickDateFormat, pickNumFormat, fmtBig, fmtTime };
})();
