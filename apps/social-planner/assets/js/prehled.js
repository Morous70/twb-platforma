'use strict';
const { $, esc, api, flash, netBadge, fmt } = App;
const num = (v) => Number(v || 0).toLocaleString('cs-CZ');
function ago(ts) {
  if (!ts) return null;
  const m = Math.max(0, Math.round((Date.now() / 1000 - ts) / 60));
  if (m < 1) return 'právě teď';
  if (m < 60) return `před ${m} min`;
  if (m < 1440) return `před ${Math.round(m / 60)} h`;
  return `před ${Math.round(m / 1440)} dny`;
}
const card = (t, v, href, cls = '') => `<div class="col-6 col-lg-2"><a ${href ? `href="${href}"` : ''} class="text-reset text-decoration-none"><div class="card"><div class="card-body"><h6 class="card-title text-muted mb-2">${t}</h6><div class="h2 mb-0 ${cls}">${v}</div></div></div></a></div>`;
const NETNAME = { facebook: 'Facebook', instagram: 'Instagram', threads: 'Threads' };

async function load() {
  const s = await api('overview');
  // upozornění
  const al = [];
  if (s.bad_accounts.length) al.push(['danger', `<strong>${s.bad_accounts.length === 1 ? 'Účet vyžaduje' : s.bad_accounts.length + ' účty vyžadují'} znovu propojit:</strong> ${s.bad_accounts.map(a => esc(a.name)).join(', ')}. Příspěvky na ně nebudou odeslány. <a href="connect.php" class="alert-link">Propojit účty</a>`]);
  if (s.attention) al.push(['danger', `<strong>${s.attention} ${s.attention === 1 ? 'příspěvek vyžaduje' : 'příspěvků vyžaduje'} pozornost</strong> (nepodařilo se odeslat). ${s.failed.map(p => `<a href="novy.php?edit=${p.id}" class="alert-link">${esc((p.message || '(bez textu)').slice(0, 40))}</a>`).join(', ')}`]);
  if (s.expiring.length) al.push(['warning', `<strong>Brzy vyprší přístup:</strong> ${s.expiring.map(a => `${esc(a.name)} (${fmt(a.token_expires)})`).join(', ')}. Cron ho u Threads obnovuje sám, u ostatních účtů <a href="connect.php" class="alert-link">propojte znovu</a>.`]);
  if (s.api_drafts) al.push(['info', `<strong>${s.api_drafts} ${s.api_drafts === 1 ? 'koncept z aplikací čeká' : 'konceptů z aplikací čeká'} na kontrolu.</strong> <a href="seznam.php?status=draft" class="alert-link">Otevřít koncepty</a>`]);
  if (s.gd_error) al.push(['danger', `<strong>Záloha na Google Drive selhala:</strong> ${esc(s.gd_error)} <a href="zaloha.php" class="alert-link">Zálohy</a>`]);
  if (!s.times.cron) al.push(['warning', 'Zatím jsme nezaznamenali běh cronu. Bez něj se nic nepublikuje. Nastavení najdete v <a href="napoveda.php" class="alert-link">Nápovědě</a>.']);
  else if (Date.now() / 1000 - s.times.cron > 3600) al.push(['warning', `Cron naposledy běžel ${ago(s.times.cron)}. Zkontrolujte jeho nastavení na hostingu.`]);
  $('#alerts').innerHTML = al.map(([c, h]) => `<div class="alert alert-${c}" role="alert"><div class="alert-message">${h}</div></div>`).join('');

  $('#cards').innerHTML =
    card('Naplánované', s.scheduled, 'seznam.php?status=scheduled') +
    card('Koncepty', s.drafts, 'seznam.php?status=draft') +
    card('Odesláno za 7 dní', s.sent7, '', 'text-success') +
    card('Odesláno za 30 dní', s.sent30, '', 'text-success') +
    card('Vyžaduje pozornost', s.attention, 'seznam.php?status=attention', s.attention ? 'text-danger' : '') +
    card('Povolené účty', `${s.accounts_on} z ${s.accounts_total}`, 'ucty.php');

  // sloupcový graf
  const days = Object.entries(s.by_day), max = Math.max(1, ...days.map(d => d[1]));
  const dn = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
  $('#chart').innerHTML = days.map(([d, n]) => {
    const dt = new Date(d + 'T12:00:00');
    return `<div class="dash-col" title="${dt.toLocaleDateString('cs-CZ')}: ${n}"><span class="dash-val">${n || ''}</span><div class="dash-bar" style="height:${Math.round(n / max * 100)}%"></div><span class="dash-lbl">${dt.getDate()}.${dt.getMonth() + 1}.<br>${dn[dt.getDay()]}</span></div>`;
  }).join('');
  $('#byNet').innerHTML = s.by_net.length ? '<span class="text-muted me-2">Za 30 dní:</span>' + s.by_net.map(r => `${netBadge(r.platform)} ${num(r.n)}`).join(' &nbsp; ') : '<span class="text-muted">Za 30 dní zatím nic odesláno.</span>';

  const t = s.totals;
  $('#totals').innerHTML = `<table class="table table-sm mb-0"><tbody>
    <tr><td class="text-muted">Dosah</td><td class="text-end h5 mb-0">${num(t.reach)}</td></tr>
    <tr><td class="text-muted">Reakce</td><td class="text-end h5 mb-0">${num(t.likes)}</td></tr>
    <tr><td class="text-muted">Komentáře</td><td class="text-end h5 mb-0">${num(t.comments)}</td></tr>
    <tr><td class="text-muted">Sdílení</td><td class="text-end h5 mb-0">${num(t.shares)}</td></tr></tbody></table>`;

  $('#nextRows').innerHTML = s.next.length ? s.next.map(p => `<tr>
      <td class="text-nowrap" style="width:170px"><strong>${fmt(p.scheduled_at)}</strong></td>
      <td><a href="novy.php?edit=${p.id}" class="text-reset">${esc((p.message || '').slice(0, 70)) || '<em class="text-muted">(bez textu)</em>'}</a></td>
      <td class="text-nowrap text-muted small d-none d-md-table-cell">${p.targets_count} účtů</td></tr>`).join('')
    : '<tr><td class="text-center text-muted py-3">Žádné naplánované příspěvky. <a href="novy.php">Vytvořit</a></td></tr>';

  $('#topRows').innerHTML = s.top.length ? s.top.map(p => `<tr>
      <td>${netBadge(p.platform)} <span class="small text-muted">${esc(p.account)}</span><div>${esc((p.message || '').slice(0, 60)) || '<em class="text-muted">(bez textu)</em>'}</div></td>
      <td class="text-end text-nowrap small">👍 ${num(p.stat_likes)}<br>💬 ${num(p.stat_comments)}</td></tr>`).join('')
    : '<tr><td class="text-center text-muted py-3">Zatím bez statistik. Obnovte je na stránce <a href="statistiky.php">Statistiky</a>.</td></tr>';

  const row = (name, ts, hint) => `<div class="d-flex justify-content-between border-bottom py-1"><span>${name}</span><span class="${ts ? '' : 'text-muted'}">${ts ? `${ago(ts)} <small class="text-muted">(${fmt(ts)})</small>` : esc(hint)}</span></div>`;
  $('#sys').innerHTML = row('Cron (odesílání)', s.times.cron, 'zatím neběžel') + row('Kontrola účtů', s.times.health, 'zatím neproběhla') +
    row('Obnova statistik', s.times.stats, 'zatím neproběhla') + row('Záloha databáze', s.times.backup, 'zatím žádná');
}
load().catch(e => flash(e.message, 'danger'));
