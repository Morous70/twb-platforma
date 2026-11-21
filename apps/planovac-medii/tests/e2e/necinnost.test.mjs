// Odhlášení po nečinnosti: upozornění, „Zůstat přihlášen“, trvalá práce, odhlášení po uplynutí, nastavení v Zabezpečení.
import { execFileSync } from 'node:child_process';
import { B, browser, login, test, eq, ok, done, api } from './helpers.mjs';
const { br, pg } = await browser();

const setting = (k, v) => execFileSync('php', ['-r', `require 'lib/bootstrap.php'; set_setting('${k}', ${v === null ? 'null' : `'${v}'`});`],
  { cwd: `${process.env.TEST_TMP}/app`, env: process.env });

setting('idle_seconds_test', '12');   // zkušební limit 12 s, upozornění 4 s před koncem
await login(pg);

await test('po nečinnosti se ukáže upozornění a „Zůstat přihlášen“ relaci udrží', async () => {
  await pg.goto(`${B}/#prehled`); await pg.waitForSelector('#main h1');
  await pg.waitForSelector('.idle-warn', { timeout: 15000 });
  ok((await pg.innerText('.idle-warn')).includes('budete odhlášeni z důvodu nečinnosti'), await pg.innerText('.idle-warn'));
  ok(/^\d:\d\d$/.test(await pg.innerText('#idleCnt')), 'odpočet');
  await pg.click('#idleStay');
  await pg.waitForSelector('.idle-warn', { state: 'detached' });
  const s = await api(pg, 'session');
  ok(s.remaining > 8, `po potvrzení zbývá ${s.remaining} s`);
}, pg);

await test('práce na stránce (psaní) upozornění neukáže a relaci udrží', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#main h1');
  for (let i = 0; i < 9; i++) { await pg.keyboard.press('Shift'); await pg.waitForTimeout(2000); }   // 18 s > limit 12 s
  ok(!(await pg.$('.idle-warn')), 'upozornění se neukázalo');
  const s = await api(pg, 'session');
  ok(s.remaining > 4, `zbývá ${s.remaining} s`);
}, pg);

await test('bez zásahu se po upozornění odhlásí a přihlašovací stránka to vysvětlí', async () => {
  await pg.waitForSelector('.idle-warn', { timeout: 15000 });
  await pg.waitForSelector('input[name=password]', { timeout: 15000 });
  ok((await pg.innerText('.errbox')).includes('Byli jste odhlášeni z důvodu nečinnosti.'), await pg.innerText('.login'));
}, pg);

await test('Zabezpečení: nastavení doby odhlášení', async () => {
  setting('idle_seconds_test', '0');
  await login(pg);
  await pg.goto(`${B}/#nastaveni/zabezpeceni`); await pg.waitForSelector('#idleMin');
  eq('60', await pg.inputValue('#idleMin'));
  await pg.fill('#idleMin', '3'); await pg.click('#idleSave');
  await pg.waitForFunction(() => /Zadejte 0/.test(document.querySelector('#toasts')?.innerText || ''));
  await pg.fill('#idleMin', '45'); await pg.click('#idleSave');
  await pg.waitForFunction(() => /Nastavení uloženo/.test(document.querySelector('#toasts')?.innerText || ''));
  eq(45, (await api(pg, 'security')).idle_minutes);
  eq(2700, (await api(pg, 'session')).limit);
  await pg.fill('#idleMin', '60'); await pg.click('#idleSave');
  await pg.waitForFunction(() => document.querySelectorAll('#toasts .toast').length >= 3);
}, pg);

await done(br);
