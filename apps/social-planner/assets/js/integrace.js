'use strict';
const { $, esc, api, form, flash, fmt } = App;
async function load() {
  const r = await api('apikeys');
  $('#alertNew').innerHTML = r.drafts ? `<div class="alert alert-info"><div class="alert-message"><strong>${r.drafts} ${r.drafts === 1 ? 'koncept z aplikací čeká' : 'konceptů z aplikací čeká'} na kontrolu.</strong> <a href="seznam.php?status=draft" class="alert-link">Otevřít koncepty</a></div></div>` : '';
  $('#rows').innerHTML = r.keys.length ? r.keys.map(k => `<tr><td><strong>${esc(k.name)}</strong></td><td><code>${esc(k.prefix)}…</code></td><td>${fmt(k.created_at)}</td><td>${k.last_used ? fmt(k.last_used) : '<span class="text-muted">nikdy</span>'}</td><td class="text-end">${k.uses}</td><td class="text-end"><button class="btn btn-outline-danger btn-sm" data-del="${k.id}" data-name="${esc(k.name)}">Odebrat</button></td></tr>`).join('')
    : '<tr><td colspan="6" class="text-muted">Zatím žádný klíč. Vytvořte první pro svou aplikaci.</td></tr>';
}
$('#frm').addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    const r = await api('apikey_create', form({ name: $('#kname').value }));
    $('#kname').value = '';
    $('#newKeyBox').innerHTML = `<div class="alert alert-warning"><div class="alert-message"><strong>Zkopírujte si klíč, znovu se už nezobrazí:</strong>
      <div class="input-group mt-2"><input class="form-control font-monospace" readonly id="keyVal" value="${esc(r.key)}"><button class="btn btn-outline-secondary" type="button" id="keyCopy">Kopírovat</button></div></div></div>`;
    $('#keyCopy').onclick = async () => { $('#keyVal').select(); try { await navigator.clipboard.writeText(r.key); } catch (_) { document.execCommand('copy'); } flash('Klíč zkopírován.'); };
    await load();
  } catch (err) { flash(err.message, 'danger'); }
});
$('#rows').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-del]'); if (!b) return;
  if (!confirm(`Odebrat klíč „${b.dataset.name}“? Aplikace, která ho používá, přestane fungovat. Už přijaté koncepty zůstanou.`)) return;
  try { await api('apikey_delete', form({ id: b.dataset.del })); flash('Klíč odebrán.'); await load(); } catch (err) { flash(err.message, 'danger'); }
});
/* ---------- MCP (AI asistenti) ---------- */
const SCOPE_LABEL = { read: 'čtení', drafts: 'koncepty' };
async function loadMcp() {
  const r = await api('mcp_grants');
  $('#mcpRows').innerHTML = r.grants.length ? r.grants.map(g => `<tr>
    <td><strong>${esc(g.client_name)}</strong></td><td><code>${esc(g.redirect_host)}</code></td>
    <td>${g.scope.split(' ').map(s => `<span class="badge ${s === 'drafts' ? 'bg-primary' : 'bg-secondary'} me-1">${SCOPE_LABEL[s] || esc(s)}</span>`).join('')}</td>
    <td>${fmt(g.created_at)}</td><td>${g.last_used ? fmt(g.last_used) : '<span class="text-muted">zatím ne</span>'}</td><td class="text-end">${g.uses}</td>
    <td class="text-end"><button class="btn btn-outline-danger btn-sm" data-revoke="${g.id}" data-name="${esc(g.client_name)}">Odebrat přístup</button></td></tr>`).join('')
    : '<tr><td colspan="7" class="text-muted">Zatím není připojen žádný asistent. Postup je výše.</td></tr>';
}
$('#mcpRows').addEventListener('click', async (e) => {
  const b = e.target.closest('[data-revoke]'); if (!b) return;
  if (!confirm(`Odebrat přístup aplikaci „${b.dataset.name}“? Přestane okamžitě fungovat.`)) return;
  try { await api('mcp_revoke', form({ id: b.dataset.revoke })); flash('Přístup odebrán.'); await loadMcp(); } catch (err) { flash(err.message, 'danger'); }
});
$('#mcpCopy').onclick = async () => { $('#mcpUrl').select(); try { await navigator.clipboard.writeText($('#mcpUrl').value); } catch (_) { document.execCommand('copy'); } flash('Adresa zkopírována.'); };
const EP = { mcp: 'MCP', token: 'token', authorize: 'souhlas', register: 'registrace', revoke: 'zrušení', 'metadata AS': 'metadata', 'metadata PR': 'metadata' };
async function loadLog() {
  const r = await api('mcp_log');
  const t = (ts) => new Date(ts * 1000).toLocaleString('cs-CZ', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });
  const badge = (s) => `<span class="badge ${s >= 500 ? 'bg-danger' : s >= 400 && s !== 401 ? 'bg-warning text-dark' : s === 401 ? 'bg-secondary' : 'bg-success'}">${s}</span>`;
  $('#mcpLog').innerHTML = r.log.length ? r.log.map(l => `<tr><td class="text-nowrap">${t(l.ts)}</td><td>${esc(EP[l.endpoint] || l.endpoint)} <span class="text-muted">${esc(l.method)}</span></td><td>${badge(+l.status)}</td><td>${esc(l.detail || '')}</td><td class="text-muted" style="max-width:260px;word-break:break-all">${esc(l.ua)}</td></tr>`).join('')
    : '<tr><td colspan="5" class="text-muted">Zatím žádné požadavky.</td></tr>';
}
$('#mcpLogReload').onclick = () => loadLog().catch(e => flash(e.message, 'danger'));
$('#mcpLogClear').onclick = async () => { if (!confirm('Vymazat záznam požadavků?')) return; await api('mcp_log_clear', form({})); loadLog(); };
$('#mcpTest').onclick = async () => {
  const b = $('#mcpTest'); b.disabled = true; $('#mcpTestOut').innerHTML = '<div class="text-muted small mb-2">Testuji…</div>';
  try {
    const r = await api('mcp_selftest', form({}));
    const bad = r.tests.filter(x => !x.ok);
    $('#mcpTestOut').innerHTML = `<div class="alert ${bad.length ? 'alert-warning' : 'alert-success'} mb-3"><div class="alert-message small">
      <strong>${bad.length ? 'Některé požadavky hosting nepropustil:' : 'Server je dostupný pro Claude i ChatGPT.'}</strong>
      <ul class="mb-0 mt-1">${r.tests.map(x => `<li>${esc(x.label)} (<code>${esc(x.ua)}</code>): ${x.ok ? '✓ v pořádku (401 s výzvou k přihlášení)' : `✗ ${x.status || 'bez odpovědi'} ${esc(x.error || '')}`}</li>`).join('')}</ul>
      ${bad.length ? '<div class="mt-2">Hosting pravděpodobně blokuje AI roboty podle identifikace. Na Wedosu zkontrolujte ochranu webu (WEDOS Global / blokování robotů) nebo napište podpoře, ať pro tuto doménu povolí User-Agent <code>Claude-User</code> a <code>python-httpx</code>.</div>' : ''}
    </div></div>`;
    loadLog();
  } catch (err) { flash(err.message, 'danger'); $('#mcpTestOut').innerHTML = ''; }
  b.disabled = false;
};
load().catch(e => flash(e.message, 'danger'));
loadLog().catch(() => {});
loadMcp().catch(e => flash(e.message, 'danger'));
