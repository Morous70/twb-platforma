// Nastavení pluginu: adresa aplikace a klíč zařízení, ověření připojení.
import { VERSION, getConfig, setConfig, testConnection, openPlayer, esc } from './common.js';

const $ = (id) => document.getElementById(id);
$('ver').textContent = 'Přehrávač pro Chrome · ' + VERSION;
const cfg = await getConfig();
$('base').value = cfg.base || '';
$('key').value = cfg.key || '';

$('f').onsubmit = async (e) => {
  e.preventDefault();
  const base = $('base').value.trim(), key = $('key').value.trim();
  const res = $('result');
  if (!base || !key) { res.innerHTML = '<span class="err-t">Vyplňte adresu aplikace i klíč zařízení.</span>'; return; }
  $('save').disabled = true;
  res.innerHTML = '<span class="muted">Zkouším připojení…</span>';
  try {
    const j = await testConnection(base, key);
    await setConfig(base, key);
    $('base').value = (await getConfig()).base;
    const n = (j.files || []).filter((f) => f.kind !== 'url').length;
    res.innerHTML = `<span class="ok-t">✓ Připojeno jako „${esc(j.device.name)}“</span> <span class="muted">· ve složce ${n} médií · zobrazení ${j.device.duration} s</span>`;
    $('play').hidden = false;
    chrome.runtime.sendMessage({ type: 'refresh' }).catch(() => {});
  } catch (err) {
    res.innerHTML = `<span class="err-t">✕ ${esc(err.message)}</span>`;
  } finally {
    $('save').disabled = false;
  }
};
$('play').onclick = () => openPlayer();
