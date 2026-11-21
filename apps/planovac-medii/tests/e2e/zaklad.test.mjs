// Všechny stránky se otevřou bez chyby, přepínač vzhledu, karty nastavení, odhlášení.
import { B, browser, login, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();

await test('přihlášení', async () => { await login(pg); ok(await pg.isVisible('nav'), 'menu'); }, pg);

for (const [hash, sel] of [['prehled', '#main h1'], ['scenare', '#main h1'], ['galerie', '#gGrid'], ['data', '#main h1'], ['protokol', '#main h1']]) {
  await test(`stránka #${hash}`, async () => {
    await pg.goto(`${B}/#${hash}`);
    await pg.waitForSelector(sel);
    await pg.waitForLoadState('networkidle');
    ok(await pg.$eval(`nav a[data-view="${hash}"]`, (a) => a.classList.contains('active')), 'aktivní položka menu');
  }, pg);
}

for (const tab of ['disk', 'obrazky', 'prehravac', 'upozorneni', 'zalohy', 'system']) {
  await test(`nastavení – karta ${tab}`, async () => {
    await pg.goto(`${B}/#nastaveni/${tab}`);
    await pg.waitForLoadState('networkidle');
    await pg.waitForTimeout(150);
    ok((await pg.$$('#main .panel')).length > 0, 'karta má obsah');
  }, pg);
}

await test('verze v postranním panelu odpovídá souboru VERSION', async () => {
  const { readFileSync } = await import('node:fs');
  const v = readFileSync(new URL('../../VERSION', import.meta.url), 'utf8').trim();
  eq(`verze ${v}`, (await pg.innerText('.ver')).trim());
}, pg);

await test('přepínač vzhledu', async () => {
  await pg.click('[data-theme-v="dark"]');
  eq('dark', await pg.getAttribute('html', 'data-theme'));
  await pg.reload(); await pg.waitForSelector('#main');
  eq('dark', await pg.getAttribute('html', 'data-theme'), 'zůstane po obnovení');
  await pg.click('[data-theme-v="auto"]');
  eq(null, await pg.getAttribute('html', 'data-theme'));
}, pg);

await test('úzký displej (mobil) bez vodorovného posunu', async () => {
  await pg.setViewportSize({ width: 390, height: 844 });
  for (const h of ['prehled', 'galerie', 'scenare']) {
    await pg.goto(`${B}/#${h}`); await pg.waitForLoadState('networkidle');
    ok(!(await pg.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1)), `#${h} přetéká`);
  }
  await pg.setViewportSize({ width: 1400, height: 1000 });
}, pg);

await test('odhlášení', async () => {
  await pg.waitForLoadState('networkidle').catch(() => {});
  // stránka může právě obnovovat data; přerušená navigace se zkusí znovu
  for (let i = 0; i < 3; i++) {
    try { await pg.goto(`${B}/?logout=1`); break; } catch (e) { if (!/ERR_ABORTED/.test(String(e))) throw e; await pg.waitForTimeout(300); }
  }
  await pg.waitForSelector('input[name=password]');
  ok(await pg.isVisible('input[name=password]'), 'přihlašovací formulář');
}, pg);

await done(br);
