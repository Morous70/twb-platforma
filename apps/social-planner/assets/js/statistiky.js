'use strict';
const { $, esc, api, form, flash, netBadge, fmt } = App;
const n = (v) => v == null ? '<span class="text-muted">–</span>' : Number(v).toLocaleString('cs-CZ');
const sum = (rows, k) => rows.reduce((a, r) => a + (+r[k] || 0), 0);
async function load() {
  const r = await api('stats&days=' + $('#days').value);
  const rows = r.rows;
  $('#stamp').textContent = r.last ? 'Naposledy obnoveno ' + fmt(r.last) : 'Zatím neobnoveno – klikněte na „Obnovit“.';
  const card = (t, v) => `<div class="col-6 col-lg-3"><div class="card"><div class="card-body"><h5 class="card-title text-muted">${t}</h5><h1 class="mt-1 mb-0">${v}</h1></div></div></div>`;
  $('#cards').innerHTML = card('Příspěvků', rows.length) + card('Dosah', sum(rows, 'stat_reach').toLocaleString('cs-CZ')) + card('Reakce', sum(rows, 'stat_likes').toLocaleString('cs-CZ')) + card('Komentáře', sum(rows, 'stat_comments').toLocaleString('cs-CZ'));
  $('#rows').innerHTML = rows.length ? rows.map(x => `<tr>
    <td style="max-width:340px"><div class="d-flex gap-2 align-items-center">${x.media_url ? `<img src="${x.media_url}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px">` : ''}
      <div class="text-truncate"><a href="seznam.php#p${x.post_id}">${esc((x.message || '(bez textu)').slice(0, 90))}</a><div class="small text-muted">${fmt(x.published_at)}</div></div></div></td>
    <td class="text-nowrap">${netBadge(x.platform)} ${esc(x.account)}</td>
    <td class="text-end">${n(x.stat_reach)}</td><td class="text-end">${n(x.stat_likes)}</td><td class="text-end">${n(x.stat_comments)}</td><td class="text-end">${n(x.stat_shares)}</td><td class="text-end">${n(x.stat_saves)}</td></tr>`).join('')
    : '<tr><td colspan="7" class="text-center text-muted py-4">V tomto období nic nevyšlo.</td></tr>';
}
$('#days').onchange = () => load().catch(e => flash(e.message, 'danger'));
$('#btnRefresh').onclick = async (e) => {
  const b = e.currentTarget; b.disabled = true; b.textContent = 'Načítám…';
  try {
    const r = await api('stats_refresh', form({ days: $('#days').value }));
    flash((r.bad ? `Obnoveno ${r.done}, nepodařilo se ${r.bad}: ${r.error}` : `Obnoveno: ${r.done} příspěvků.`) + (r.warn ? ' ' + r.warn : ''), (r.bad || r.warn) ? 'warning' : 'success');
    await load();
  } catch (err) { flash(err.message, 'danger'); }
  b.disabled = false; b.textContent = 'Obnovit ze sítí'; loadBest().catch(() => {});
};

/* ---------- nejlepší čas ---------- */
const DOW = ['', 'Po', 'Út', 'St', 'Čt', 'Pá', 'So', 'Ne'];
const DOW_LONG = ['', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota', 'neděle'];
const pct = (s) => { const v = Math.round((s - 1) * 100); return (v > 0 ? '+' : '') + v + ' %'; };
function cellColor(s) {
  if (s >= 1) return `rgba(59,125,221,${(0.18 + Math.min(1, (s - 1) / 1.2) * 0.75).toFixed(2)})`;
  return `rgba(232,140,48,${(0.15 + Math.min(1, (1 - s) / 0.6) * 0.45).toFixed(2)})`;
}
async function loadBest() {
  const r = await api('best_time&accounts=' + encodeURIComponent($('#btAcc').value));
  const top = $('#btTop');
  if (!r.samples) top.innerHTML = '<div class="text-muted">Zatím žádný změřený příspěvek. Statistiky se načítají jednou denně z cronu, případně tlačítkem „Obnovit ze sítí“.</div>';
  else if (!r.enough) top.innerHTML = `<div class="text-muted">Na doporučení je zatím málo dat: změřeno ${r.samples} z potřebných ${r.need} příspěvků. Mapa níže už ukazuje, co je k dispozici.</div>`;
  else if (!r.top.length) top.innerHTML = '<div class="text-muted">Žádný čas zatím výrazně nevyčnívá, příspěvky dopadají podobně bez ohledu na čas.</div>';
  else top.innerHTML = '<div class="d-flex flex-wrap gap-2">' + r.top.map((t, i) => `<div class="border rounded px-3 py-2">
      <div class="small text-muted">${i + 1}. doporučení</div>
      <div class="fs-4 fw-bold">${DOW_LONG[t.d]} ${t.h}:00</div>
      <div class="small"><span class="text-success fw-bold">${t.lift > 0 ? '+' : ''}${t.lift} %</span> oproti běžnému příspěvku · ${t.n}× vyšlo</div>
      <a class="small" href="novy.php?at=${t.next}">Naplánovat na ${fmt(t.next)}</a></div>`).join('') + '</div>';
  const map = {}; r.grid.forEach(g => { map[g.d + '-' + g.h] = g; });
  let html = '<thead><tr><th></th>' + [...Array(24)].map((_, h) => `<th>${h}</th>`).join('') + '</tr></thead><tbody>';
  for (let d = 1; d <= 7; d++) {
    html += `<tr><th>${DOW[d]}</th>`;
    for (let h = 0; h < 24; h++) {
      const g = map[d + '-' + h];
      if (!g || !g.n) { html += `<td title="${DOW_LONG[d]} ${h}:00 – nic nevyšlo"></td>`; continue; }
      const best = r.top.some(t => t.d === d && t.h === h);
      html += `<td class="${best ? 'bt-best' : ''}" style="background:${cellColor(g.s)}" title="${DOW_LONG[d]} ${h}:00 · ${g.n} příspěvků · ${pct(g.s)} oproti průměru">${g.n}</td>`;
    }
    html += '</tr>';
  }
  $('#btMap').innerHTML = html + '</tbody>';
}
async function loadBestAccounts() {
  const acc = (await api('accounts')).accounts.filter(a => +a.enabled);
  const label = { facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads' };
  $('#btAcc').innerHTML = '<option value="">Všechny účty</option>' + Object.keys(label).map(p => {
    const list = acc.filter(a => a.platform === p); if (!list.length) return '';
    return `<optgroup label="${label[p]}"><option value="${list.map(a => a.id).join(',')}">Všechny ${label[p]}</option>` + list.map(a => `<option value="${a.id}">${esc(a.name)}</option>`).join('') + '</optgroup>';
  }).join('');
}
$('#btAcc').onchange = () => loadBest().catch(e => flash(e.message, 'danger'));
load().catch(e => flash(e.message, 'danger'));
loadBestAccounts().catch(() => {}).finally(() => loadBest().catch(e => flash(e.message, 'danger')));
