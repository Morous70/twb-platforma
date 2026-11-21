'use strict';
const { $, esc, pad, ymd, api, flash, statusBadge, fmt, repeatInfo, icons, detail, modal, modalOpen, bindActions } = App;

const MONTHS = ['Leden','Únor','Březen','Duben','Květen','Červen','Červenec','Srpen','Září','Říjen','Listopad','Prosinec'];
const DAYS = ['Po','Út','St','Čt','Pá','So','Ne'];
let calMonth = new Date(); calMonth.setDate(1); calMonth.setHours(0, 0, 0, 0);
let items = [];

async function loadCalendar() {
  const first = new Date(calMonth);
  const dow = (first.getDay() + 6) % 7;                         // pondělí = 0
  const dim = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
  const weeks = Math.ceil((dow + dim) / 7);
  const start = new Date(first.getFullYear(), first.getMonth(), 1 - dow);
  const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + weeks * 7);

  $('#calTitle').textContent = `${MONTHS[first.getMonth()]} ${first.getFullYear()}`;
  items = (await api(`calendar&from=${Math.floor(start / 1000)}&to=${Math.floor(end / 1000)}`)).items;

  const byDay = {};
  items.forEach((it, i) => { (byDay[ymd(new Date(it.ts * 1000))] ??= []).push(i); });

  const todayKey = ymd(new Date());
  let html = DAYS.map(d => `<div class="dow">${d}</div>`).join('');
  for (let i = 0; i < weeks * 7; i++) {
    const d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
    const key = ymd(d), idx = byDay[key] || [];
    html += `<div class="cal-day${d.getMonth() !== first.getMonth() ? ' other' : ''}${key === todayKey ? ' today' : ''}${key < todayKey ? ' past' : ''}" data-day="${key}">
      <span class="num">${d.getDate()}</span>
      ${idx.slice(0, 3).map(n => {
        const it = items[n], t = new Date(it.ts * 1000);
        return `<button type="button" class="ev ev-${it.status}" data-ev="${n}" title="${esc(it.message)}">${pad(t.getHours())}:${pad(t.getMinutes())} ${esc(it.message.slice(0, 22)) || (it.media_type === 'video' ? '🎬' : '🖼')}</button>`;
      }).join('')}
      ${idx.length > 3 ? `<button type="button" class="more" data-more="${key}">+${idx.length - 3} další</button>` : ''}
    </div>`;
  }
  $('#cal').innerHTML = html;
}

async function loadStats() {
  const s = await api('overview');
  $('#stScheduled').textContent = s.scheduled;
  $('#stSent').textContent = s.sent7;
  $('#stAttention').textContent = s.attention;
  $('#stAttention').className = 'h2 mb-0 ' + (s.attention ? 'text-danger' : '');
  $('#badAccounts').innerHTML = s.bad_accounts.length ? `<div class="alert alert-danger" role="alert"><div class="alert-message">
    <strong>${s.bad_accounts.length === 1 ? 'Účet vyžaduje' : s.bad_accounts.length + ' účty vyžadují'} znovu propojit:</strong>
    ${s.bad_accounts.map(a => esc(a.name)).join(', ')}. Příspěvky na ně nebudou odeslány. <a href="connect.php" class="alert-link">Propojit účty</a></div></div>` : '';
  $('#stAccounts').textContent = `${s.accounts_on} z ${s.accounts_total}`;
  $('#nextRows').innerHTML = s.next.length ? s.next.map(p => `
    <tr>
      <td class="text-nowrap" style="width:190px"><strong>${fmt(p.scheduled_at)}</strong></td>
      <td>${esc(p.message.slice(0, 90)) || '<em class="text-muted">(bez textu)</em>'}</td>
      <td class="text-nowrap text-muted d-none d-md-table-cell">${p.targets_count} účtů</td>
      <td class="text-nowrap text-primary small">${esc(repeatInfo(p))}</td>
    </tr>`).join('') : '<tr><td class="text-center text-muted py-3">Žádné naplánované příspěvky.</td></tr>';
}

function showEvents(list) {
  modal(list.length === 1 ? 'Příspěvek' : `Příspěvky (${list.length})`,
    list.map(detail).join('<hr>'));
}

$('#cal').addEventListener('click', (e) => {
  const ev = e.target.closest('[data-ev]'), more = e.target.closest('[data-more]'), day = e.target.closest('.cal-day');
  if (ev) return showEvents([items[+ev.dataset.ev]]);
  if (more) return showEvents(items.filter(it => ymd(new Date(it.ts * 1000)) === more.dataset.more));
  if (day && !day.classList.contains('past')) location.href = 'novy.php?date=' + day.dataset.day;
});
$('#prev').onclick = () => { calMonth.setMonth(calMonth.getMonth() - 1); loadCalendar().catch(e => flash(e.message, 'danger')); };
$('#next').onclick = () => { calMonth.setMonth(calMonth.getMonth() + 1); loadCalendar().catch(e => flash(e.message, 'danger')); };
$('#today').onclick = () => { calMonth = new Date(); calMonth.setDate(1); calMonth.setHours(0, 0, 0, 0); loadCalendar().catch(e => flash(e.message, 'danger')); };

function refresh() {
  loadCalendar().catch(e => { $('#cal').textContent = e.message; });
  loadStats().catch(e => flash(e.message, 'danger'));
}
bindActions($('#appModal'), refresh);
refresh();
icons();
setInterval(() => { if (!modalOpen()) refresh(); }, 30000);
