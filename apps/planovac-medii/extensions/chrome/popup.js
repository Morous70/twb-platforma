// Okénko po kliknutí na ikonu: náhled, co běží, počet médií, obnovení, tlačítka.
import { VERSION, getConfig, getState, cachedBlob, playable, openPlayer, hm, plural, esc } from './common.js';

const $ = (id) => document.getElementById(id);
$('ver').textContent = 'Přehrávač pro Chrome · ' + VERSION;
let prevKey = null, prevUrl = null;

async function render() {
  const [cfg, s] = await Promise.all([getConfig(), getState()]);
  $('app').hidden = !cfg.base;
  if (cfg.base) $('app').href = cfg.base + '/';
  const err = !s ? null : s.notConfigured ? 'Plugin není nastavený – v nastavení zadejte adresu aplikace a klíč zařízení.'
    : !s.ok ? `Seznam se nepodařilo obnovit${s.errorAt ? ' (' + hm(s.errorAt) + ')' : ''}: ${s.error}` + (s.files?.length ? ` Přehrává se poslední stažený obsah${s.at ? ' z ' + hm(s.at) : ''}.` : '')
    : null;
  $('alert').hidden = !err; $('alert').textContent = err || '';
  $('warn').hidden = !s?.warning; $('warn').textContent = s?.warning || '';

  const files = (s?.files || []).filter(playable);
  const videos = files.filter((f) => f.kind === 'video').length;
  const pl = s?.playlist;
  const running = pl ? [pl.active.join(', ') || (pl.mode === 'empty' ? 'nic' : '–'), pl.fixed.length ? 'pevný: ' + pl.fixed.join(', ') : ''].filter(Boolean).join(' · ') : '–';
  const next = await chrome.runtime.sendMessage({ type: 'next-refresh' }).catch(() => null);
  const rows = [];
  if (s?.device) rows.push(['Zařízení', esc(s.device.name)]);
  if (pl) rows.push(['Běží', esc(running)]);
  rows.push(['Médií', s?.files ? `${files.length}${videos ? ` (${videos} ${plural(videos, 'video', 'videa', 'videí')})` : ''} · staženo ${s.done ?? 0}/${s.total ?? 0}${s.syncing ? ' · stahuji…' : ''}` : '–']);
  rows.push(['Obnoveno', (s?.at ? hm(s.at) : '–') + (next?.at ? ' · další v ' + hm(new Date(next.at).toISOString()) : '')]);
  if (s?.device) rows.push(['Zobrazení', `${s.device.duration} s (videa celá)`]);
  $('meta').innerHTML = rows.map(([k, v]) => `<span>${k}</span><b>${v}</b>`).join('');

  // náhled: právě přehrávané médium, jinak první obrázek
  const { nowPlaying } = await chrome.storage.local.get('nowPlaying');
  const live = nowPlaying && Date.now() - nowPlaying.t < 120000 ? files.find((f) => f.key === nowPlaying.key) : null;
  const pick = live || files.find((f) => f.kind === 'image') || files[0];
  if (!pick) { setPrev(null, s?.notConfigured ? 'Plugin není nastavený.' : 'Ve složce přehrávače teď nic není.'); return; }
  if (pick.key === prevKey) return;
  const blob = await cachedBlob(pick.key);
  if (!blob) { setPrev(null, `${pick.name} se ještě stahuje…`); return; }
  prevKey = pick.key;
  if (prevUrl) URL.revokeObjectURL(prevUrl);
  prevUrl = URL.createObjectURL(blob);
  if (pick.kind === 'image') setPrev(`<img src="${prevUrl}" alt="">`);
  else if (pick.kind === 'video') setPrev(`<video src="${prevUrl}#t=0.5" muted preload="metadata"></video>`);
  else setPrev(null, `${pick.kind === 'pdf' ? 'PDF' : 'Text'}: ${pick.name}`);
}
function setPrev(html, txt) {
  if (!html) prevKey = null;
  $('prev').innerHTML = html || `<div class="ph">${esc(txt)}</div>`;
}

$('refresh').onclick = async () => {
  const b = $('refresh');
  b.disabled = true; b.textContent = '⟳ Obnovuji…';
  try { await chrome.runtime.sendMessage({ type: 'refresh' }); } finally { b.disabled = false; b.textContent = '⟳ Obnovit'; render(); }
};
$('play').onclick = async () => { await openPlayer(); window.close(); };
$('opts').onclick = () => { chrome.runtime.openOptionsPage(); window.close(); };
chrome.storage.onChanged.addListener((ch) => { if (ch.state || ch.config) render(); });
render();
