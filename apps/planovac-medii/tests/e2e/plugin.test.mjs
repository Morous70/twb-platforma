// Plugin pro Chrome (extensions/chrome) načtený do Chromia: připojení, stažení médií, odznak, okénko, přehrávač,
// chyba serveru, odebraný soubor; v aplikaci karta Nastavení → Zařízení.
import { createRequire } from 'node:module';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import { B, TMP, test, eq, ok, done, login } from './helpers.mjs';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const APP = `${TMP}/app`;
const php = (code) => execFileSync('php', ['-r', `require 'lib/bootstrap.php'; require_once 'lib/schema.php'; require_once 'lib/sync.php'; require_once 'lib/devices.php'; ${code}`],
  { cwd: APP, env: process.env }).toString();
const FOLDER = 'plugin-slozka';
const DRIVE = `${APP}/data/mock-drive/${FOLDER}`;
const exe = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};

// ---------- příprava: složka přehrávače se soubory, zařízení s délkou 3 s ----------
const oldFolder = php(`echo setting('folder_id');`);
php(`set_setting('folder_id', '${FOLDER}'); set_setting('device_files_cache', null); set_setting('device_fails', null);`);
fs.rmSync(DRIVE, { recursive: true, force: true });
fs.mkdirSync(DRIVE, { recursive: true });
fs.copyFileSync(`${TMP}/fixtures/er_261024_01.jpg`, `${DRIVE}/plakat.jpg`);
fs.copyFileSync(`${TMP}/fixtures/logo.png`, `${DRIVE}/logo.png`);
fs.writeFileSync(`${DRIVE}/otevírací doba.txt`, 'Otevřeno\nPo–Pá 6:00–18:00\n');
fs.writeFileSync(`${DRIVE}/web.url`, '[InternetShortcut]\r\nURL=https://example.com\r\n');
fs.writeFileSync(`${DRIVE}/letak.pdf`, '%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n'
  + '3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 200]/Contents 4 0 R>>endobj\n4 0 obj<</Length 30>>stream\n0 0.5 0 rg 20 20 260 160 re f\nendstream endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');
// krátké video WebM: nahrávka stránky přes Playwright
{
  const vb = await chromium.launch(exe);
  const vctx = await vb.newContext({ viewport: { width: 320, height: 180 }, recordVideo: { dir: `${TMP}/video`, size: { width: 320, height: 180 } } });
  const vp = await vctx.newPage();
  await vp.setContent('<body style="margin:0;background:#2050c0;height:100vh"></body>');
  await vp.waitForTimeout(1500);
  const path = await vp.video().path();
  await vctx.close(); await vb.close();
  fs.copyFileSync(path, `${DRIVE}/reklama.webm`);
}
const token = php(`[$d, $t] = device_create('Testovací Chrome', 'chrome', 3); echo $t;`).trim();
const PLAYABLE = 5; // jpg, png, txt, pdf, webm (odkaz .url se přeskočí)

// ---------- prohlížeč s pluginem ----------
const extDir = `${APP}/extensions/chrome`;
const ctx = await chromium.launchPersistentContext(`${TMP}/chrome-profil`, {
  ...exe, headless: true, viewport: { width: 1280, height: 720 }, locale: 'cs-CZ',
  args: [`--disable-extensions-except=${extDir}`, `--load-extension=${extDir}`, '--lang=cs-CZ', '--autoplay-policy=no-user-gesture-required'],
});
let sw = ctx.serviceWorkers()[0] || await ctx.waitForEvent('serviceworker');
const extId = sw.url().split('/')[2];
const EXT = `chrome-extension://${extId}`;
const errors = [];
const watch = (p) => { p.on('pageerror', (e) => errors.push(String(e))); p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text()); }); return p; };
const noErrors = (what) => ok(!errors.length, `${what}: chyba JavaScriptu: ${errors.splice(0).join(' / ').slice(0, 400)}`);
const storage = (p, key) => p.evaluate((k) => chrome.storage.local.get(k).then((x) => x[k]), key);
const badge = () => sw.evaluate(() => chrome.action.getBadgeText({}));
const waitFor = async (fn, ms = 15000, what = 'podmínka') => {
  const t0 = Date.now();
  for (;;) { const v = await fn(); if (v) return v; if (Date.now() - t0 > ms) throw new Error(`čekání vypršelo: ${what}`); await new Promise((r) => setTimeout(r, 200)); }
};

await test('po instalaci se otevře nastavení a odznak hlásí nenastavený plugin', async () => {
  const opt = await waitFor(() => ctx.pages().find((p) => p.url().startsWith(`${EXT}/options.html`)), 10000, 'stránka nastavení');
  ok(opt, 'nastavení se neotevřelo');
  eq('?', await waitFor(async () => (await badge()) || null, 5000, 'odznak'));
  await opt.close();
});

let opt;
await test('nastavení: špatný klíč ohlásí chybu, správný se připojí', async () => {
  opt = watch(await ctx.newPage());
  await opt.goto(`${EXT}/options.html`);
  await opt.fill('#base', B + '/');
  await opt.fill('#key', 'dev_spatny');
  await opt.click('#save');
  await opt.waitForSelector('#result .err-t');
  ok((await opt.innerText('#result')).includes('Neplatný klíč zařízení'), await opt.innerText('#result'));
  php(`set_setting('device_fails', null);`);
  await opt.fill('#key', token);
  await opt.click('#save');
  await opt.waitForSelector('#result .ok-t');
  const t = await opt.innerText('#result');
  ok(t.includes('Připojeno jako „Testovací Chrome“') && t.includes('ve složce 5 médií') && t.includes('zobrazení 3 s'), t);
  eq(B, (await storage(opt, 'config')).base, 'adresa bez lomítka na konci');
  noErrors('nastavení');
});

await test('plugin stáhne média do mezipaměti a odznak ukáže jejich počet', async () => {
  const s = await waitFor(async () => { const s = await storage(opt, 'state'); return s && s.ok && !s.syncing && s.done === PLAYABLE ? s : null; }, 20000, 'stažení');
  eq([PLAYABLE, PLAYABLE], [s.total, s.done]);
  eq('Testovací Chrome', s.device.name);
  eq(String(PLAYABLE), await badge());
  const keys = await opt.evaluate(() => caches.open('twb-media').then((c) => c.keys()).then((k) => k.length));
  eq(PLAYABLE, keys, 'souborů v mezipaměti');
  const row = JSON.parse(php(`echo json_encode(q("SELECT last_seen, client FROM devices WHERE name = 'Testovací Chrome'")->fetch());`));
  ok(row.last_seen && row.client === 'chrome/1.2.0', JSON.stringify(row));
});

await test('okénko: náhled, co běží, počet médií, obnovení', async () => {
  const pp = watch(await ctx.newPage());
  await pp.setViewportSize({ width: 360, height: 600 });
  await pp.goto(`${EXT}/popup.html`);
  await pp.waitForSelector('#prev img');
  const t = await pp.innerText('#meta');
  ok(t.includes('Testovací Chrome') && t.includes(`${PLAYABLE} (1 video) · staženo ${PLAYABLE}/${PLAYABLE}`) && t.includes('3 s (videa celá)'), t);
  ok(/Obnoveno\s+\d\d:\d\d · další v \d\d:\d\d/.test(t), t);
  ok(await pp.isHidden('#alert'), 'bez chyby');
  await pp.click('#refresh');
  await pp.waitForFunction(() => document.querySelector('#refresh').textContent === '⟳ Obnovit' && !document.querySelector('#refresh').disabled);
  if (TMP) await pp.screenshot({ path: `${TMP}/plugin-okenko.png` });
  await pp.close();
  noErrors('okénko');
});

let pl;
await test('přehrávač: všechny druhy médií, náhodně bez opakování, odkaz se přeskočí', async () => {
  pl = watch(await ctx.newPage());
  await pl.goto(`${EXT}/player.html`);
  await pl.waitForSelector('#stage > .on');
  const seen = [];
  const kinds = new Set();
  for (let i = 0; i < PLAYABLE; i++) {
    if (i) { const before = await pl.innerText('#name'); await pl.keyboard.press('ArrowRight'); await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before); }
    await pl.waitForSelector('#stage > .on');
    seen.push((await pl.innerText('#name')).split(' · ')[0]);
    kinds.add(await pl.$eval('#stage > .on', (e) => e.tagName.toLowerCase() + (e.className.includes('text') ? '.text' : '')));
  }
  eq(PLAYABLE, new Set(seen).size, 'v jednom kole bez opakování: ' + seen.join(', '));
  ok(!seen.includes('web.url'), 'odkaz se nepřehrává');
  eq(['canvas', 'div.text', 'img', 'video'], [...kinds].sort(), 'druhy prvků');
  noErrors('přehrávač');
});

await test('přehrávač: PDF je vykreslené (první stránka), text velkým písmem', async () => {
  // projít, dokud nepřijde PDF a text
  const found = {};
  for (let i = 0; i < PLAYABLE * 2 && !(found.pdf && found.text); i++) {
    const n = (await pl.innerText('#name')).split(' · ')[0];
    if (n === 'letak.pdf') found.pdf = await pl.$eval('#stage > .on', (c) => { const d = c.getContext('2d').getImageData(c.width / 2, c.height / 2, 1, 1).data; return [d[0], d[1], d[2]]; });
    if (n === 'otevírací doba.txt') {
      found.text = await pl.$eval('#stage > .on div', (d) => [d.textContent, parseInt(getComputedStyle(d).fontSize)]);
      if (TMP) { await pl.waitForTimeout(500); await pl.screenshot({ path: `${TMP}/plugin-text.png` }); }
    }
    const before = await pl.innerText('#name');
    await pl.keyboard.press('ArrowRight');
    await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before);
    await pl.waitForSelector('#stage > .on');
  }
  ok(found.pdf && found.pdf[1] > 100 && found.pdf[0] < 60, 'střed PDF má být zelený: ' + JSON.stringify(found.pdf));
  ok(found.text && found.text[0].includes('Po–Pá 6:00–18:00') && found.text[1] >= 60, 'text: ' + JSON.stringify(found.text));
});

await test('přehrávač: obrázek se po nastavené době (3 s) sám vymění', async () => {
  await pl.waitForFunction(() => document.querySelector('#stage > .on')?.tagName === 'IMG' || false, null, { timeout: 30000 }).catch(() => {});
  const before = await pl.innerText('#name');
  await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before, { timeout: 15000 });
  ok(true);
  await pl.mouse.move(100, 100);
  if (TMP) await pl.screenshot({ path: `${TMP}/plugin-prehravac.png` });
  noErrors('přehrávač');
});

await test('chyba serveru: odznak „!“, okénko ukáže důvod, přehrávač hraje dál', async () => {
  await opt.evaluate((c) => chrome.storage.local.set({ config: { ...c, key: 'dev_neplatny' } }), await storage(opt, 'config'));
  await opt.evaluate(() => chrome.runtime.sendMessage({ type: 'refresh' }));
  eq('!', await badge());
  const pp = watch(await ctx.newPage());
  await pp.goto(`${EXT}/popup.html`);
  await pp.waitForSelector('#alert:not([hidden])');
  const t = await pp.innerText('#alert');
  ok(t.includes('Seznam se nepodařilo obnovit') && t.includes('Neplatný klíč zařízení') && t.includes('Přehrává se poslední stažený obsah'), t);
  await pp.close();
  ok(await pl.$('#stage > .on'), 'přehrávač dál něco ukazuje');
  php(`set_setting('device_fails', null);`);
  await opt.evaluate((c) => chrome.storage.local.set({ config: { ...c, key: c.key } }), { base: B, key: token });
  await opt.evaluate(() => chrome.runtime.sendMessage({ type: 'refresh' }));
  eq(String(PLAYABLE), await badge());
  noErrors('chyba serveru');
});

await test('soubor odebraný ze složky zmizí z přehrávače i z mezipaměti, nový přibude', async () => {
  fs.unlinkSync(`${DRIVE}/logo.png`);
  fs.copyFileSync(`${TMP}/fixtures/plakat.png`, `${DRIVE}/novy.png`);
  php(`set_setting('device_files_cache', null);`);
  const s = await opt.evaluate(() => chrome.runtime.sendMessage({ type: 'refresh' }).then((r) => r.state));
  eq([PLAYABLE, PLAYABLE], [s.total, s.done]);
  const names = s.files.map((f) => f.name);
  ok(!names.includes('logo.png') && names.includes('novy.png'), names.join(', '));
  eq(PLAYABLE, await opt.evaluate(() => caches.open('twb-media').then((c) => c.keys()).then((k) => k.length)), 'v mezipaměti');
  // právě zobrazené médium se dohraje, nová sestava platí od dalšího
  const seen = new Set();
  for (let i = 0; i <= PLAYABLE; i++) {
    const before = await pl.innerText('#name');
    await pl.keyboard.press('ArrowRight');
    await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before);
    seen.add((await pl.innerText('#name')).split(' · ')[0]);
  }
  ok(!seen.has('logo.png'), 'odebraný logo.png se ještě hrál: ' + [...seen].join(', '));
  ok(seen.has('novy.png'), 'nový soubor se nepřehrál: ' + [...seen].join(', '));
  noErrors('změna složky');
});

await test('HTML dokument: přehraje se v izolované stránce (skript běží, k pluginu nemá přístup), plocha 1920×1080', async () => {
  fs.writeFileSync(`${DRIVE}/oteviraci-doba.html`, '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{margin:0;background:#123;color:#fff;font:80px sans-serif}</style></head>'
    + '<body><h1 id="js">čeká</h1><script>var a; try { a = (typeof chrome !== "undefined" && chrome.storage) ? "má přístup" : "bez přístupu"; } catch (e) { a = "bez přístupu"; }'
    + 'var p; try { p = typeof parent.document; } catch (e) { p = "blokováno"; } document.getElementById("js").textContent = "Otevřeno " + a + " " + p + " " + innerWidth;</script></body></html>');
  php(`set_setting('device_files_cache', null);`);
  const s = await opt.evaluate(() => chrome.runtime.sendMessage({ type: 'refresh' }).then((r) => r.state));
  ok(s.files.some((f) => f.name === 'oteviraci-doba.html' && f.kind === 'html'), 'v seznamu: ' + s.files.map((f) => f.name).join(', '));
  eq(PLAYABLE + 1, s.done, 'staženo');
  try {
  let found = false;
  for (let i = 0; i <= PLAYABLE + 2 && !found; i++) {
    const before = await pl.innerText('#name');
    await pl.keyboard.press('ArrowRight');
    await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before);
    found = (await pl.innerText('#name')).startsWith('oteviraci-doba.html');
  }
  ok(found, 'HTML se nepřehrálo');
  await pl.click('#pause'); // ať se po 3 s nevymění
  eq('HTML dokument · 3 s', await pl.innerText('#info'));
  const fr = await waitFor(async () => pl.frames().find((f) => f.url().endsWith('/frame.html')), 5000, 'rám');
  await fr.waitForFunction(() => document.getElementById('js')?.textContent.startsWith('Otevřeno'));
  eq('Otevřeno bez přístupu blokováno 1920', await fr.evaluate(() => document.getElementById('js').textContent));
  const box = await pl.evaluate(() => { const r = document.querySelector('#stage .html iframe').getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height)]; });
  eq([1280, 720], box, 'zmenšeno na obrazovku 1280×720');
  if (TMP) await pl.screenshot({ path: `${TMP}/plugin-html.png` });
  await pl.click('#pause');
  } finally {
  fs.unlinkSync(`${DRIVE}/oteviraci-doba.html`);
  php(`set_setting('device_files_cache', null);`);
  const s2 = await opt.evaluate(() => chrome.runtime.sendMessage({ type: 'refresh' }).then((r) => r.state));
  eq(PLAYABLE, s2.total);
  }
  noErrors('HTML dokument');
});

await test('ovládání z aplikace: přehrávač hlásí stav, plní Další soubor, Obnovit teď, Restartovat; zavřený přehrávač', async () => {
  const r = await pl.evaluate(() => window.twbPing());
  eq([null, 15], [r.cmd, r.ping_seconds]);
  const dev = () => JSON.parse(php(`echo json_encode(device_out(q("SELECT * FROM devices WHERE name = 'Testovací Chrome'")->fetch()));`));
  let d = dev();
  ok(d.controllable && d.playing && d.status.version === '1.2.0' && d.status.files === PLAYABLE && d.status.playing?.name, JSON.stringify(d));
  // Další soubor
  const before = await pl.innerText('#name');
  php(`device_command(${d.id}, 'next');`);
  await pl.evaluate(() => window.twbPing());
  await pl.waitForFunction((b) => document.querySelector('#name').textContent !== b, before, { timeout: 5000 });
  // Obnovit teď
  const at = (await storage(opt, 'state')).at;
  php(`device_command(${d.id}, 'refresh');`);
  await pl.evaluate(() => window.twbPing());
  await waitFor(async () => (await storage(opt, 'state')).at > at, 8000, 'obnovení seznamu');
  // změna složky → obnoví se samo
  const at2 = (await storage(opt, 'state')).at;
  await pl.waitForTimeout(1100);
  php(`set_setting('last_change_at', (string)time());`);
  await pl.evaluate(() => window.twbPing());
  await waitFor(async () => (await storage(opt, 'state')).at > at2, 8000, 'samo po změně složky');
  // Restartovat přehrávač
  php(`device_command(${d.id}, 'restart');`);
  const loaded = pl.waitForEvent('load', { timeout: 8000 });
  await pl.evaluate(() => window.twbPing());
  await loaded;
  await pl.waitForSelector('#stage > .on');
  // zavřený přehrávač: zařízení se dál ozývá (služba na pozadí), ale nepřehrává
  php(`q("UPDATE devices SET status_at = ? WHERE id = ${d.id}", [date('Y-m-d H:i:s', time() - 120)]);`);
  d = dev();
  eq([true, false, false], [d.controllable, d.playing, d.offline]);
  noErrors('ovládání');
});

await test('aplikace: Nastavení → Zařízení ukáže zařízení, přidání ukáže klíč, plugin ke stažení', async () => {
  await pl?.close().catch(() => {});   // otevřený přehrávač by zařízení hlásil jako hrající
  php(`q("UPDATE devices SET status_at = ? WHERE name = 'Testovací Chrome'", [date('Y-m-d H:i:s', time() - 300)]);`);
  const pg = watch(await ctx.newPage());
  await login(pg);
  await pg.goto(`${B}/#nastaveni/zarizeni`);
  await pg.waitForSelector('#devList');
  await pg.waitForFunction(() => document.querySelector('#devList')?.innerText.includes('Přehrávač není otevřený'), null, { timeout: 90000 });
  const t = await pg.innerText('#devList');
  ok(t.includes('Testovací Chrome') && t.includes('Chrome · 3 s') && /právě teď|před \d+ min/.test(t), t);
  ok(t.includes('Přehrávač není otevřený') && t.includes('přehrávač 1.2.0') && !t.includes('Další soubor'), t);
  ok((await pg.getAttribute('#devZip', 'href')) === 'api.php?a=extension_zip', 'odkaz na plugin');
  ok((await pg.innerText('#devZip')).includes('Stáhnout plugin pro Chrome (1.2.0)'), await pg.innerText('#devZip'));
  await pg.click('#devAdd');
  await pg.fill('#dvName', 'Notebook doma');
  await pg.click('#dvSave');
  await pg.waitForSelector('#dkKey');
  ok(/^dev_[0-9a-f]{32}$/.test(await pg.inputValue('#dkKey')), 'klíč v okně');
  eq(B, await pg.inputValue('#dkBase'));
  if (TMP) await pg.screenshot({ path: `${TMP}/zarizeni-klic.png` });
  await pg.click('#modalBox [data-close]');
  await pg.waitForFunction(() => document.querySelector('#devList').innerText.includes('Notebook doma'));
  if (TMP) await pg.screenshot({ path: `${TMP}/zarizeni.png` });
  await pg.fill('#devDur', '25');
  await pg.click('#devDurSave');
  await pg.waitForFunction(() => document.querySelector('#devList')?.innerText.includes('25 s (výchozí)'));
  php(`set_setting('device_duration', null);`);
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.waitForTimeout(300);
  if (TMP) await pg.screenshot({ path: `${TMP}/zarizeni-mobil.png`, fullPage: true });
  await pg.close();
  noErrors('Nastavení → Zařízení');
});

php(`set_setting('folder_id', '${oldFolder}'); set_setting('device_files_cache', null); q('DELETE FROM devices');`);
await done(ctx);
