// Společné funkce pluginu: nastavení, stav, načtení seznamu z Plánovače a stažení souborů do mezipaměti.
// Používá ho služba na pozadí (bg.js), okénko (popup.js), přehrávač (player.js) i nastavení (options.js).

export const VERSION = chrome.runtime.getManifest().version;
export const CLIENT = 'chrome/' + VERSION;
const CACHE = 'twb-media';
// Cache API přijímá jen adresy http(s); tahle slouží jen jako klíč, nikam se nevolá
const KEY_URL = 'https://twb-media.invalid/';

/** Nastavení připojení: {base, key} */
export async function getConfig() {
  const { config } = await chrome.storage.local.get('config');
  return config || { base: '', key: '' };
}
export async function setConfig(base, key) {
  await chrome.storage.local.set({ config: { base: normBase(base), key: key.trim() } });
}
export function normBase(b) {
  b = String(b || '').trim().replace(/\/+(now\.php|index\.php)?$/i, '').replace(/\/+$/, '');
  if (b && !/^https?:\/\//i.test(b)) b = 'https://' + b;
  return b;
}

/** Stav: {at, ok, error, warning, device, playlist, files:[{id,name,kind,mime,md5,size,key}], total, done, syncing, next} */
export async function getState() {
  const { state } = await chrome.storage.local.get('state');
  return state || null;
}
async function patchState(p) {
  const s = (await getState()) || {};
  const n = { ...s, ...p };
  await chrome.storage.local.set({ state: n });
  return n;
}

/** Klíč souboru v mezipaměti: obsah (md5), aby se po přejmenování znovu nestahoval. */
export const fileKey = (f) => (f.md5 ? f.md5 : 'id-' + f.id + '-' + (f.size ?? '')) ;
export const playable = (f) => ['image', 'video', 'pdf', 'text', 'html'].includes(f.kind);

export async function cachedBlob(key) {
  const c = await caches.open(CACHE);
  const r = await c.match(KEY_URL + encodeURIComponent(key));
  return r ? r.blob() : null;
}

async function api(cfg, file) {
  const url = cfg.base + '/now.php' + (file ? '?file=' + encodeURIComponent(file) : '');
  let r;
  try {
    r = await fetch(url, { headers: { 'X-Device-Key': cfg.key, 'X-Client': CLIENT }, cache: 'no-store' });
  } catch (e) {
    throw new Error('Server neodpovídá (' + (navigator.onLine === false ? 'počítač je bez internetu' : 'zkontrolujte adresu aplikace') + ').');
  }
  if (!r.ok) {
    let msg = 'chyba HTTP ' + r.status;
    try { const j = await r.json(); if (j.error) msg = j.error; } catch (e) { /* není JSON */ }
    throw new Error(msg);
  }
  return r;
}

/** Hlášení stavu a dotaz na příkazy z aplikace (každých 15 s, když je otevřený přehrávač).
 *  Vrátí {cmd: {id, name} | null, change, ping_seconds}. */
export async function ping(status) {
  const cfg = await getConfig();
  if (!cfg.base || !cfg.key) return null;
  const r = await fetch(cfg.base + '/now.php?ping=1', {
    method: 'POST', cache: 'no-store', body: JSON.stringify(status),
    headers: { 'X-Device-Key': cfg.key, 'X-Client': CLIENT, 'Content-Type': 'application/json' },
  });
  if (!r.ok) throw new Error('chyba HTTP ' + r.status);
  return r.json();
}

/** Vyzkouší připojení (pro nastavení): vrátí odpověď now.php. */
export async function testConnection(base, key) {
  const r = await api({ base: normBase(base), key: key.trim() });
  const j = await r.json().catch(() => null);
  if (!j || !j.ok) throw new Error('Adresa neodpovídá jako TWB Plánovač médií (zkontrolujte adresu aplikace).');
  return j;
}

let running = null;
/** Obnoví seznam a stáhne chybějící soubory. Souběžná volání čekají na jedno. */
export function sync() {
  if (!running) running = doSync().finally(() => { running = null; });
  return running;
}

async function doSync() {
  const cfg = await getConfig();
  if (!cfg.base || !cfg.key) {
    await patchState({ ok: false, error: 'Plugin není nastavený – zadejte adresu aplikace a klíč zařízení.', notConfigured: true, syncing: false });
    return getState();
  }
  await patchState({ syncing: true });
  let j;
  try {
    j = await (await api(cfg)).json();
  } catch (e) {
    await patchState({ ok: false, error: e.message, errorAt: new Date().toISOString(), syncing: false, notConfigured: false });
    return getState();
  }
  const files = (j.files || []).map((f) => ({ ...f, key: fileKey(f) }));
  const want = files.filter(playable);
  const c = await caches.open(CACHE);
  const have = new Set((await c.keys()).map((r) => decodeURIComponent(r.url.slice(KEY_URL.length))));
  // nejdřív uložit seznam (přehrávač hned ví, co už má), pak dotahovat chybějící
  const isDone = (f) => have.has(f.key);
  await patchState({
    ok: true, error: null, notConfigured: false, at: new Date().toISOString(), device: j.device, playlist: j.playlist, change: j.change || 0,
    warning: j.warning || null, stale: !!j.stale, files, total: want.length, done: want.filter(isDone).length,
  });
  let failed = 0, lastErr = null;
  for (const f of want) {
    if (have.has(f.key)) continue;
    try {
      const r = await api(cfg, f.id);
      const blob = await r.blob();
      if (f.size != null && blob.size !== f.size) throw new Error('soubor nedorazil celý');
      await c.put(KEY_URL + encodeURIComponent(f.key), new Response(blob, { headers: { 'Content-Type': f.mime || blob.type } }));
      have.add(f.key);
      await patchState({ done: want.filter(isDone).length });
    } catch (e) {
      failed++; lastErr = `${f.name}: ${e.message}`;
    }
  }
  // uklidit soubory, které ve složce už nejsou
  const keep = new Set(want.map((f) => f.key));
  for (const r of await c.keys()) {
    if (!keep.has(decodeURIComponent(r.url.slice(KEY_URL.length)))) await c.delete(r);
  }
  return patchState({
    syncing: false, done: want.filter(isDone).length,
    failed, failMsg: failed ? `Soubor nejde stáhnout – ${lastErr}` : null, failAt: failed ? new Date().toISOString() : null,
    warning: failed ? `Nepodařilo se stáhnout ${failed} ${failed === 1 ? 'soubor' : failed < 5 ? 'soubory' : 'souborů'} (${lastErr}). Zkusí se to znovu při dalším obnovení.` : (j.warning || null),
  });
}

/** Odznak na ikoně: počet médií zeleně, při chybě červený „!“. */
export async function updateBadge(state) {
  state = state || (await getState());
  if (!state || state.notConfigured) {
    await chrome.action.setBadgeText({ text: '?' });
    await chrome.action.setBadgeBackgroundColor({ color: '#b7791f' });
    await chrome.action.setTitle({ title: 'TWB Plánovač médií – přehrávač: plugin není nastavený' });
    return;
  }
  if (!state.ok) {
    await chrome.action.setBadgeText({ text: '!' });
    await chrome.action.setBadgeBackgroundColor({ color: '#b42318' });
    await chrome.action.setTitle({ title: 'TWB Plánovač médií – přehrávač: ' + state.error });
    return;
  }
  await chrome.action.setBadgeText({ text: String(state.total ?? 0) });
  await chrome.action.setBadgeBackgroundColor({ color: '#2f7d4f' });
  await chrome.action.setTitle({ title: `TWB Plánovač médií – přehrávač: ${state.total ?? 0} médií` });
}

/** Otevře přehrávač (existující záložku jen přepne). */
export async function openPlayer() {
  const url = chrome.runtime.getURL('player.html');
  // getContexts nepotřebuje oprávnění „tabs“ (Chrome 116+)
  const ctx = await chrome.runtime.getContexts({ contextTypes: ['TAB'], documentUrls: [url] });
  if (ctx.length) {
    await chrome.tabs.update(ctx[0].tabId, { active: true });
    await chrome.windows.update(ctx[0].windowId, { focused: true });
  } else {
    await chrome.tabs.create({ url });
  }
}

export const hm = (iso) => (iso ? new Date(iso).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }) : '–');
export const plural = (n, a, b, c) => (n === 1 ? a : n >= 2 && n <= 4 ? b : c);
export const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[ch]);
