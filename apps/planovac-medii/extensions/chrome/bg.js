// Služba na pozadí: každých 5 minut (a na požádání) obnoví seznam médií, stáhne chybějící soubory a upraví odznak.
import { sync, updateBadge, getConfig } from './common.js';

const ALARM = 'refresh';
const PERIOD = 5; // minut

async function ensureAlarm() {
  const a = await chrome.alarms.get(ALARM);
  if (!a) await chrome.alarms.create(ALARM, { periodInMinutes: PERIOD, delayInMinutes: PERIOD });
}

async function refresh() {
  const s = await sync();
  await updateBadge(s);
  return s;
}

chrome.runtime.onInstalled.addListener(async (d) => {
  await ensureAlarm();
  const cfg = await getConfig();
  if (!cfg.base || !cfg.key) {
    await updateBadge(null);
    if (d.reason === 'install') chrome.runtime.openOptionsPage();
  } else {
    refresh();
  }
});
chrome.runtime.onStartup.addListener(async () => { await ensureAlarm(); refresh(); });
chrome.alarms.onAlarm.addListener((a) => { if (a.name === ALARM) refresh(); });

// zprávy z okénka, přehrávače a nastavení
chrome.runtime.onMessage.addListener((msg, sender, reply) => {
  if (msg?.type === 'refresh') {
    ensureAlarm().then(refresh).then((s) => reply({ ok: true, state: s }), (e) => reply({ ok: false, error: String(e.message || e) }));
    return true; // odpověď přijde později
  }
  if (msg?.type === 'next-refresh') {
    chrome.alarms.get(ALARM).then((a) => reply({ at: a ? a.scheduledTime : null }));
    return true;
  }
  return false;
});
