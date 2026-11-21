// Aplikace pro TV Samsung (extensions/tizen) v Chromiu s napodobeným rozhraním Tizenu, na jiném původu než server:
// párovací kód → Spárovat televizi v aplikaci → stažení a přehrávání, ovladač, okno stavu, nastavení, ikonka chyby,
// soubory přehrávané ze serveru, zrušení spárování. Navíc kontrola syntaxe pro starší televize (ES2017).
import { createRequire } from 'node:module';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import { B, TMP, test, eq, ok, done, login } from './helpers.mjs';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const APP = `${TMP}/app`;
const php = (code) => execFileSync('php', ['-r', `require 'lib/bootstrap.php'; require_once 'lib/schema.php'; require_once 'lib/sync.php'; require_once 'lib/devices.php'; ${code}`],
  { cwd: APP, env: process.env }).toString();
const FOLDER = 'tv-slozka';
const DRIVE = `${APP}/data/mock-drive/${FOLDER}`;
const exe = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};

// ---------- složka přehrávače ----------
const oldFolder = php(`echo setting('folder_id');`);
php(`set_setting('folder_id', '${FOLDER}'); set_setting('device_files_cache', null); set_setting('device_fails', null); set_setting('pair_starts', null); set_setting('pair_fails', null);`);
fs.rmSync(DRIVE, { recursive: true, force: true });
fs.mkdirSync(DRIVE, { recursive: true });
fs.copyFileSync(`${TMP}/fixtures/er_261024_01.jpg`, `${DRIVE}/plakat.jpg`);
fs.copyFileSync(`${TMP}/fixtures/logo.png`, `${DRIVE}/logo.png`);
fs.writeFileSync(`${DRIVE}/otevírací doba.txt`, 'Otevřeno\nPo–Pá 6:00–18:00\n');
fs.writeFileSync(`${DRIVE}/web.url`, '[InternetShortcut]\r\nURL=https://example.com\r\n');
const br = await chromium.launch({ ...exe, args: ['--autoplay-policy=no-user-gesture-required', '--lang=cs-CZ'] });
{
  const vctx = await br.newContext({ viewport: { width: 320, height: 180 }, recordVideo: { dir: `${TMP}/video-tv`, size: { width: 320, height: 180 } } });
  const vp = await vctx.newPage();
  await vp.setContent('<body style="margin:0;background:#c02050;height:100vh"></body>');
  await vp.waitForTimeout(1200);
  const path = await vp.video().path();
  await vctx.close();
  fs.copyFileSync(path, `${DRIVE}/reklama.webm`);
}
const PLAYABLE = 4;

// ---------- balíček aplikace (jako ke stažení z Nastavení → Zařízení) na vlastním serveru ----------
const TV = `${TMP}/tv`;
fs.rmSync(TV, { recursive: true, force: true });
php(`[$p] = extension_zip('tizen'); $z = new ZipArchive(); $z->open($p); $z->extractTo('${TV}'); $z->close(); unlink($p);`);
const TVDIR = `${TV}/twb-planovac-medii-tizen`;
const tvPort = 19000 + (process.pid % 900);
const tvSrv = spawn('php', ['-S', `127.0.0.1:${tvPort}`, '-t', TVDIR], { stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 600));
const TVURL = `http://127.0.0.1:${tvPort}/index.html`;

const errors = [];
const watch = (p) => { p.on('pageerror', (e) => errors.push(String(e))); p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|webapis/.test(m.text())) errors.push(m.text()); }); return p; };
const noErrors = (what) => ok(!errors.length, `${what}: chyba JavaScriptu: ${errors.splice(0).join(' / ').slice(0, 400)}`);

const tvCtx = await br.newContext({ viewport: { width: 1920, height: 1080 }, locale: 'cs-CZ' });
// napodobené rozhraní televize Samsung
await tvCtx.addInitScript(() => {
  window.__tv = { keys: null, saver: 0 };
  window.tizen = { tvinputdevice: { registerKeyBatch: (k) => { window.__tv.keys = k; } } };
  window.webapis = { appcommon: { AppCommonScreenSaverState: { SCREEN_SAVER_OFF: 0 }, setScreenSaver: () => { window.__tv.saver++; } } };
});
const tv = watch(await tvCtx.newPage());
const cur = () => tv.evaluate(() => window.twbTv.current());
const press = async (key) => { await tv.keyboard.press(key); await tv.waitForTimeout(150); };
const nextItem = async () => {
  const before = (await cur())?.name;
  await press('ArrowRight');
  await tv.waitForFunction((b) => { const c = window.twbTv.current(); return c && c.name !== b; }, before, { timeout: 10000 });
  return (await cur()).name;
};

await test('syntaxe aplikace pro TV odpovídá ES2017 (Tizen 4.0, televize od roku 2018)', async () => {
  let acorn;
  try { acorn = require('acorn'); } catch (e) { throw new Error('chybí balíček acorn (npm i acorn do NODE_PATH)'); }
  for (const f of ['app.js', 'config.js']) acorn.parse(fs.readFileSync(`${TVDIR}/${f}`, 'utf8'), { ecmaVersion: 2017 });
  ok(/<tizen:privilege name="http:\/\/tizen.org\/privilege\/internet"\/>/.test(fs.readFileSync(`${TVDIR}/config.xml`, 'utf8')), 'oprávnění internet');
});

let code;
await test('první spuštění: párovací kód, klávesy ovladače a vypnutý spořič', async () => {
  await tv.goto(TVURL);
  await tv.waitForFunction(() => /^\d{3} \d{3}$/.test(document.getElementById('pairCode').textContent));
  code = (await tv.innerText('#pairCode')).replace(' ', '');
  const foot = await tv.innerText('#pairFoot');
  ok(foot.includes(B.replace(/^https?:\/\//, '')) && foot.includes('verze 1.2.0'), foot);
  const t = await tv.evaluate(() => window.__tv);
  eq(['MediaPlayPause', 'MediaPlay', 'MediaPause', 'ColorF1Green'], t.keys);
  ok(t.saver >= 1, 'spořič');
  if (TMP) await tv.screenshot({ path: `${TMP}/tv-parovani.png` });
  noErrors('párování');
});

const app = watch(await (await br.newContext({ viewport: { width: 1280, height: 900 }, locale: 'cs-CZ' })).newPage());
await test('aplikace: Spárovat televizi kódem z obrazovky → televize začne přehrávat', async () => {
  await login(app);
  await app.goto(`${B}/#nastaveni/zarizeni`);
  await app.waitForSelector('#devPair');
  ok((await app.innerText('#devZipTv')).includes('Stáhnout aplikaci pro TV Samsung (1.2.0)'), 'odkaz na aplikaci pro TV');
  await app.click('#devPair');
  await app.type('#prCode', code);
  eq(code.slice(0, 3) + ' ' + code.slice(3), await app.inputValue('#prCode'), 'kód se rozdělí mezerou');
  await app.fill('#prName', 'Televize u pokladny');
  await app.fill('#prDur', '3');
  await app.click('#prGo');
  await app.waitForFunction(() => document.querySelector('#devList')?.innerText.includes('Televize u pokladny'));
  ok((await app.innerText('#devList')).includes('TV Samsung · 3 s'), await app.innerText('#devList'));
  if (TMP) await app.screenshot({ path: `${TMP}/tv-zarizeni.png` });
  await tv.waitForSelector('#pair', { state: 'hidden', timeout: 10000 });
  await tv.waitForFunction((n) => { const s = window.twbTv.state(); return s.ok && s.total === n && s.done === n; }, PLAYABLE, { timeout: 20000 });
  await tv.waitForSelector('#stage > .on');
  ok(!!(await tv.evaluate(() => window.twbTv.cfg().key)).match(/^dev_/), 'klíč uložený v televizi');
  noErrors('spárování');
});

await test('ovladač: šipka vpravo = další bez opakování, odkaz se přeskočí, druhy médií', async () => {
  const seen = [(await cur()).name];
  const kinds = new Set([(await cur()).kind]);
  for (let i = 1; i < PLAYABLE; i++) { seen.push(await nextItem()); kinds.add((await cur()).kind); }
  eq(PLAYABLE, new Set(seen).size, seen.join(', '));
  ok(!seen.includes('web.url'), 'odkaz');
  eq(['image', 'text', 'video'], [...kinds].sort());
  noErrors('přehrávání');
});

await test('ovladač: Přehrát/Pauza pozastaví, obrázek se jinak po 3 s sám vymění', async () => {
  while ((await cur()).kind !== 'image') await nextItem();
  await press('Enter');                       // OK = pauza
  eq(true, (await cur()).paused);
  const n = (await cur()).name;
  await tv.waitForTimeout(3600);
  eq(n, (await cur()).name, 'pozastavené médium se nevyměnilo');
  await press('Enter');
  await tv.waitForFunction((b) => window.twbTv.current()?.name !== b, n, { timeout: 8000 });
});

await test('Zpět: okno stavu (zařízení, co běží, počet, obnovení), OK v něm obnoví seznam', async () => {
  await tv.keyboard.down('Escape'); await tv.waitForTimeout(100); await tv.keyboard.up('Escape');
  await tv.waitForSelector('#status:not([hidden])');
  const t = await tv.innerText('#status');
  ok(t.includes('Televize u pokladny') && t.includes(`${PLAYABLE} (1 video) · staženo ${PLAYABLE}/${PLAYABLE}`) && t.includes('3 s (videa celá)') && /Obnoveno\s+\d\d:\d\d · další v \d\d:\d\d/.test(t), t);
  eq('stRefresh', await tv.evaluate(() => document.activeElement.id), 'tlačítko Obnovit má zaměření');
  const at = await tv.evaluate(() => window.twbTv.state().at);
  await tv.waitForTimeout(50);
  await press('Enter');
  await tv.waitForFunction((a) => window.twbTv.state().at > a, at);
  if (TMP) await tv.screenshot({ path: `${TMP}/tv-stav.png` });
  await tv.keyboard.down('Escape'); await tv.waitForTimeout(100); await tv.keyboard.up('Escape');
  await tv.waitForSelector('#status', { state: 'hidden' });
  noErrors('okno stavu');
});

await test('chyba serveru: malá ikonka „!“ s časem, přehrává se dál; po opravě zmizí', async () => {
  php(`set_setting('device_fails', json_encode(['127.0.0.1' => [20, time()]]));`);
  await press('b');                          // zelené tlačítko B
  await tv.waitForSelector('#errIcon:not([hidden])');
  ok(/^! \d\d:\d\d$/.test(await tv.innerText('#errIcon')), await tv.innerText('#errIcon'));
  ok(await tv.$('#stage > .on'), 'přehrává se dál');
  if (TMP) await tv.screenshot({ path: `${TMP}/tv-chyba.png` });
  php(`set_setting('device_fails', null);`);
  await press('b');
  await tv.waitForSelector('#errIcon', { state: 'hidden' });
});

await test('soubor, který se do televize nevejde, se přehraje přímo ze serveru', async () => {
  fs.copyFileSync(`${TMP}/fixtures/plakat.png`, `${DRIVE}/velky.png`);
  php(`set_setting('device_files_cache', null);`);
  await tv.evaluate(() => localStorage.setItem('twb-max-cache', '1'));
  await press('b');
  await tv.waitForFunction(() => { const s = window.twbTv.state(); return s.files.some((f) => f.name === 'velky.png') && !s.syncing; });
  await tv.waitForFunction(() => Object.keys(window.twbTv.streamOnly()).length === 1);
  let c = await cur();
  for (let i = 0; i < PLAYABLE * 3 && c.name !== 'velky.png'; i++) { await nextItem(); c = await cur(); }
  eq('velky.png', c.name);
  ok(c.url.startsWith(`${B}/now.php?file=`) && c.url.includes('&key=dev_'), c.url);
  await tv.evaluate(() => localStorage.removeItem('twb-max-cache'));
  noErrors('přehrání ze serveru');
});

await test('podržené Zpět: nastavení, šipky přesouvají výběr, Zavřít', async () => {
  await tv.keyboard.down('Escape'); await tv.waitForTimeout(1300); await tv.keyboard.up('Escape');
  await tv.waitForSelector('#settings:not([hidden])');
  ok((await tv.innerText('#setInfo')).includes('Televize u pokladny'), await tv.innerText('#setInfo'));
  eq(B, await tv.inputValue('#setBase'));
  eq('setClose', await tv.evaluate(() => document.activeElement.id));
  await press('ArrowDown');
  eq('setBase', await tv.evaluate(() => document.activeElement.id), 'dolů z posledního na první');
  await press('ArrowUp');
  eq('setClose', await tv.evaluate(() => document.activeElement.id));
  await press('ArrowUp');
  eq('setUnpair', await tv.evaluate(() => document.activeElement.id));
  if (TMP) await tv.screenshot({ path: `${TMP}/tv-nastaveni.png` });
  await press('ArrowDown');
  await press('Enter');
  await tv.waitForSelector('#settings', { state: 'hidden' });
  noErrors('nastavení');
});

const devRow = () => app.innerText('#devList');
const openDevices = async () => { await app.goto(`${B}/#galerie`); await app.waitForSelector('#newSlide'); await app.goto(`${B}/#nastaveni/zarizeni`); await app.waitForSelector('#devList'); };
await test('ovládání z aplikace: stav v řádku zařízení (co hraje, soubory, verze)', async () => {
  await tv.evaluate(() => window.twbTv.ping());
  await openDevices();
  const t = await devRow();
  ok(/▶ .+ – od \d\d:\d\d/.test(t) && /soubor\S*, staženo \d+ · přehrávač 1\.2\.0/.test(t), t);
  for (const l of ['Obnovit teď', 'Restartovat přehrávač', 'Další soubor']) ok(t.includes(l), l);
  if (TMP) await (await app.$('#devList')).screenshot({ path: `${TMP}/tv-ovladani.png` });
  noErrors('stav v aplikaci');
});

await test('ovládání z aplikace: Další soubor, Restartovat přehrávač, Obnovit teď', async () => {
  const before = (await cur()).name;
  await app.click('[data-devcmd="next"]');
  await app.waitForFunction(() => document.getElementById('devList')?.innerText.includes('Odesláno: Další soubor'));
  ok(await app.isDisabled('[data-devcmd="restart"]'), 'tlačítka čekají');
  await tv.evaluate(() => window.twbTv.ping());
  await tv.waitForFunction((b) => window.twbTv.current()?.name !== b, before, { timeout: 8000 });
  // restart: přehrávač se znovu načte a hraje dál
  await app.evaluate(async () => { const d = (await api('devices')).devices[0]; await api('device_cmd', { id: d.id, cmd: 'restart' }); });
  const loaded = tv.waitForEvent('load', { timeout: 8000 });
  await tv.evaluate(() => window.twbTv.ping());
  await loaded;
  await tv.waitForSelector('#stage > .on', { timeout: 10000 });
  // obnovit teď: nový seznam ze serveru
  const at = await tv.evaluate(() => window.twbTv.state().at);
  await app.evaluate(async () => { const d = (await api('devices')).devices[0]; await api('device_cmd', { id: d.id, cmd: 'refresh' }); });
  await tv.evaluate(() => window.twbTv.ping());
  await tv.waitForFunction((a) => window.twbTv.state().at > a, at, { timeout: 8000 });
  noErrors('příkazy');
});

await test('změna obsahu složky: televize si seznam obnoví sama hned (ne až za 5 minut)', async () => {
  fs.copyFileSync(`${TMP}/fixtures/logo.png`, `${DRIVE}/novy.png`);
  await tv.waitForTimeout(1100);   // změna musí být novější než poslední obnovení televize
  php(`set_setting('last_change_at', (string)time());`);
  await tv.evaluate(() => window.twbTv.ping());
  await tv.waitForFunction(() => window.twbTv.state().files.some((f) => f.name === 'novy.png'), null, { timeout: 8000 });
  fs.rmSync(`${DRIVE}/novy.png`);
  noErrors('změna složky');
});

await test('chyba přehrání se objeví v řádku zařízení', async () => {
  fs.writeFileSync(`${DRIVE}/rozbity.jpg`, 'tohle neni obrazek');
  php(`set_setting('device_files_cache', null);`);
  await press('b');
  await tv.waitForFunction(() => window.twbTv.state().files.some((f) => f.name === 'rozbity.jpg') && window.twbTv.state().done === window.twbTv.state().total);
  for (let i = 0; i < PLAYABLE * 4 && !(await tv.evaluate(() => window.twbTv.report().error)); i++) await nextItem();
  const e = await tv.evaluate(() => window.twbTv.report().error);
  ok(e && e.msg.includes('rozbity.jpg'), JSON.stringify(e));
  await tv.evaluate(() => window.twbTv.ping());
  await openDevices();
  ok((await devRow()).includes('⚠') && (await devRow()).includes('Soubor nejde přehrát – rozbity.jpg'), await devRow());
  fs.rmSync(`${DRIVE}/rozbity.jpg`);
  php(`set_setting('device_files_cache', null);`);
  await press('b');
  errors.splice(0);   // konzole hlásí nenačtený obrázek
});

await test('HTML dokument: přehraje se v izolovaném rámu (skript běží, k aplikaci televize nemá přístup)', async () => {
  fs.writeFileSync(`${DRIVE}/oteviraci-doba.html`, '<!DOCTYPE html><html><head><meta charset="utf-8"><style>body{margin:0;background:#123;color:#fff;font:90px sans-serif}</style></head>'
    + '<body><h1 id="js">čeká</h1><script>var a, b; try { a = typeof parent.twbTv; } catch (e) { a = "blokováno"; } try { b = typeof localStorage.getItem("twb-tv-config"); } catch (e) { b = "bez úložiště"; }'
    + 'document.getElementById("js").textContent = "Otevřeno " + a + " " + b + " " + innerWidth + "×" + innerHeight;</script></body></html>');
  php(`set_setting('device_files_cache', null);`);
  try {
    await press('b');
    await tv.waitForFunction(() => window.twbTv.state().files.some((f) => f.name === 'oteviraci-doba.html' && f.kind === 'html') && window.twbTv.state().done === window.twbTv.state().total, null, { timeout: 10000 });
    let n = (await cur())?.name;
    for (let i = 0; i < PLAYABLE * 3 && n !== 'oteviraci-doba.html'; i++) n = await nextItem();
    eq('oteviraci-doba.html', n, 'HTML se přehrálo');
    await press(' '); // pauza, ať se po 3 s nevymění
    const fr = tv.frames().find((f) => f !== tv.mainFrame());
    ok(fr, 'rám: ' + tv.frames().map((f) => f.url()).join(', '));
    await fr.waitForFunction(() => document.getElementById('js') && document.getElementById('js').textContent.indexOf('Otevřeno') === 0);
    eq('Otevřeno blokováno bez úložiště 1920×1080', await fr.evaluate(() => document.getElementById('js').textContent));
    if (TMP) await tv.screenshot({ path: `${TMP}/tv-html.png` });
    await press(' ');
  } finally {
    fs.rmSync(`${DRIVE}/oteviraci-doba.html`);
    php(`set_setting('device_files_cache', null);`);
    await press('b');
  }
  noErrors('HTML dokument');
});

await test('zařízení se 15 minut neozvalo: červená tečka a pruh nahoře v celé aplikaci', async () => {
  php(`[$d] = device_create('Počítač ve skladu', 'chrome', null); q('UPDATE devices SET last_seen = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 20 * 60), $d['id']]);`);
  await app.goto(`${B}/#galerie`);
  await app.waitForSelector('#devAlert:not([hidden])');
  const t = await app.innerText('#devAlert');
  ok(t.includes('Zařízení Počítač ve skladu se neozývá od') && t.includes('Nastavení → Zařízení'), t);
  if (TMP) await app.screenshot({ path: `${TMP}/zarizeni-neozyva.png` });
  await app.click('#devAlert a');
  await app.waitForSelector('#devList');
  ok((await devRow()).includes('neozývá se od'), await devRow());
  php(`q("DELETE FROM devices WHERE name = 'Počítač ve skladu'");`);
  await app.goto(`${B}/#galerie`);
  await app.waitForSelector('#devAlert', { state: 'hidden' });
  noErrors('neozývá se');
});

await test('po novém spuštění televize hraje hned ze staženého obsahu (bez párování)', async () => {
  await tv.reload();
  await tv.waitForSelector('#stage > .on', { timeout: 10000 });
  ok(await tv.isHidden('#pair'), 'párovací obrazovka');
  ok((await cur()).url.startsWith('blob:') || (await cur()).name === 'velky.png', 'z úložiště televize');
  noErrors('nové spuštění');
});

await test('odebrání zařízení v aplikaci: televize se vrátí na párovací kód', async () => {
  const id = await app.evaluate(async () => (await api('devices')).devices.find((d) => d.name === 'Televize u pokladny').id);
  await app.evaluate(async (i) => api('device_delete', { id: i }), id);
  await press('b');
  await tv.waitForSelector('#pair:not([hidden])');
  ok((await tv.innerText('#pairErr')).includes('Televize už není spárovaná'), await tv.innerText('#pairErr'));
  await tv.waitForFunction(() => /^\d{3} \d{3}$/.test(document.getElementById('pairCode').textContent));
  eq('', await tv.evaluate(() => window.twbTv.cfg().key));
  ok(!(await tv.$('#stage > .on')), 'přehrávání se zastavilo');
  noErrors('zrušení spárování');
});

tvSrv.kill();
php(`set_setting('folder_id', '${oldFolder}'); set_setting('device_files_cache', null); set_setting('pair_starts', null); q('DELETE FROM devices'); q('DELETE FROM device_pairing');`);
await done(br);
