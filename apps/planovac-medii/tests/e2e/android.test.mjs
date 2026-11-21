// Aplikace pro Android TV: přehrávač z projektu pro Android (assets/www) s napodobeným rozhraním aplikace (window.TwbAndroid).
// Tlačítka Zpět, podržet Zpět, Přehrát/Pauza a zelené předává aplikace přes window.twbTv.key(…). Párování vytvoří zařízení typu Android.
import { createRequire } from 'node:module';
import { execFileSync, spawn } from 'node:child_process';
import fs from 'node:fs';
import { B, TMP, test, eq, ok, done, login } from './helpers.mjs';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const APP = `${TMP}/app`;
const php = (code) => execFileSync('php', ['-r', `require 'lib/bootstrap.php'; require_once 'lib/schema.php'; require_once 'lib/sync.php'; require_once 'lib/devices.php'; ${code}`],
  { cwd: APP, env: process.env }).toString();
const FOLDER = 'android-slozka';
const DRIVE = `${APP}/data/mock-drive/${FOLDER}`;
const exe = process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {};

const oldFolder = php(`echo setting('folder_id');`);
php(`set_setting('folder_id', '${FOLDER}'); set_setting('device_files_cache', null); set_setting('device_fails', null); set_setting('pair_starts', null); set_setting('pair_fails', null);`);
fs.rmSync(DRIVE, { recursive: true, force: true });
fs.mkdirSync(DRIVE, { recursive: true });
fs.copyFileSync(`${TMP}/fixtures/er_261024_01.jpg`, `${DRIVE}/plakat.jpg`);
fs.copyFileSync(`${TMP}/fixtures/logo.png`, `${DRIVE}/logo.png`);
fs.writeFileSync(`${DRIVE}/text.txt`, 'Android TV\n');

// projekt pro Android (jako ke stažení), přehrávač z assets/www na vlastním serveru
const OUT = `${TMP}/android`;
fs.rmSync(OUT, { recursive: true, force: true });
php(`[$p] = extension_zip('android'); $z = new ZipArchive(); $z->open($p); $z->extractTo('${OUT}'); $z->close(); unlink($p);`);
const WWW = `${OUT}/twb-prehravac-android/app/src/main/assets/www`;
const port = 19900 + (process.pid % 90);
const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', WWW], { stdio: 'ignore' });
await new Promise((r) => setTimeout(r, 600));

const br = await chromium.launch({ ...exe, args: ['--autoplay-policy=no-user-gesture-required'] });
const errors = [];
const watch = (p) => { p.on('pageerror', (e) => errors.push(String(e))); p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource|webapis/.test(m.text())) errors.push(m.text()); }); return p; };
const noErrors = (what) => ok(!errors.length, `${what}: chyba JavaScriptu: ${errors.splice(0).join(' / ').slice(0, 400)}`);
const ctx = await br.newContext({ viewport: { width: 1280, height: 720 }, locale: 'cs-CZ' });
await ctx.addInitScript(() => { window.TwbAndroid = { version: () => '1.2.0', kind: () => 'android' }; });
const tv = watch(await ctx.newPage());
const key = (k) => tv.evaluate((n) => window.twbTv.key(n), k);
const cur = () => tv.evaluate(() => window.twbTv.current());

let code;
await test('Android TV: párovací kód s druhem zařízení android', async () => {
  const req = tv.waitForRequest((r) => r.url().includes('/pair.php?a=start'));
  await tv.goto(`http://127.0.0.1:${port}/index.html`);
  ok((await req).url().includes('kind=android'), (await req).url());
  await tv.waitForFunction(() => /^\d{3} \d{3}$/.test(document.getElementById('pairCode').textContent));
  code = (await tv.innerText('#pairCode')).replace(' ', '');
  eq('android', await tv.evaluate(() => window.twbTv.kind));
  noErrors('párování');
});

await test('spárování v aplikaci vytvoří zařízení typu Android, televize začne přehrávat', async () => {
  const app = watch(await (await br.newContext({ viewport: { width: 1280, height: 900 } })).newPage());
  await login(app);
  await app.goto(`${B}/#nastaveni/zarizeni`);
  await app.waitForSelector('#devZipAndroid');
  ok((await app.innerText('#devZipAndroid')).includes('Stáhnout aplikaci pro Android TV (1.2.0)'), 'odkaz');
  await app.click('#devPair');
  await app.type('#prCode', code);
  await app.fill('#prName', 'Android ve výloze');
  await app.fill('#prDur', '3');
  await app.click('#prGo');
  await app.waitForFunction(() => document.querySelector('#devList')?.innerText.includes('Android ve výloze'));
  ok((await app.innerText('#devList')).includes('Android · 3 s'), await app.innerText('#devList'));
  await tv.waitForSelector('#stage > .on', { timeout: 20000 });
  const row = JSON.parse(php(`echo json_encode(q("SELECT kind, client FROM devices WHERE name = 'Android ve výloze'")->fetch());`));
  eq({ kind: 'android', client: 'android/1.2.0' }, row);
  await app.close();
  noErrors('spárování');
});

await test('tlačítka z aplikace: Zpět = stav, podržet Zpět = nastavení, Přehrát/Pauza, další', async () => {
  await key('back');
  await tv.waitForSelector('#status:not([hidden])');
  ok((await tv.innerText('#status')).includes('Android ve výloze'), 'stav');
  await key('back');
  await tv.waitForSelector('#status', { state: 'hidden' });
  await key('backLong');
  await tv.waitForSelector('#settings:not([hidden])');
  ok((await tv.innerText('#setInfo')).includes('1.2.0'), 'verze z aplikace');
  await key('pause');                       // v nastavení se nic nestane
  eq(false, (await cur()).paused);
  await key('back');
  await tv.waitForSelector('#settings', { state: 'hidden' });
  await key('pause');
  eq(true, (await cur()).paused);
  await key('pause');
  const n = (await cur()).name;
  await key('next');
  await tv.waitForFunction((b) => window.twbTv.current()?.name !== b, n);
  const at = await tv.evaluate(() => window.twbTv.state().at);
  await tv.waitForTimeout(20);
  await key('green');
  await tv.waitForFunction((a) => window.twbTv.state().at > a, at);
  noErrors('tlačítka');
});

srv.kill();
php(`set_setting('folder_id', '${oldFolder}'); set_setting('device_files_cache', null); set_setting('pair_starts', null); q('DELETE FROM devices'); q('DELETE FROM device_pairing');`);
await done(br);
