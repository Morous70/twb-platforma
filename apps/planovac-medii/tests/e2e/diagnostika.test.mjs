// Diagnostika v Nastavení → Systém.
import { B, browser, login, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);

await test('spuštění kontroly a kopírování výsledku', async () => {
  await pg.goto(`${B}/#nastaveni/system`); await pg.waitForSelector('#dgRun');
  ok(await pg.isHidden('#dgCopy'), 'kopírování skryté před kontrolou');
  await pg.click('#dgRun'); await pg.waitForSelector('.dg-item');
  ok((await pg.$$('.dg-item')).length > 20, 'položek');
  ok(await pg.isVisible('.dg-item.ok'), 'zelené značky');
  await pg.click('#dgCopy'); await pg.waitForTimeout(200);
  const txt = await pg.evaluate(() => navigator.clipboard.readText());
  ok(/^TWB Plánovač médií \d+\.\d+\.\d+ – diagnostika/.test(txt), txt.slice(0, 80));
  ok(txt.includes('== Server a PHP ==') && txt.includes('[V POŘÁDKU] Verze PHP'), 'obsah textu');
}, pg);

await test('karta Systém: verze a novinky', async () => {
  const v = await pg.innerText('.kv dd b');
  ok(/^\d+\.\d+\.\d+$/.test(v), v);
  ok((await pg.innerText('#main')).includes(`Verze ${v}`), 'novinky k aktuální verzi');
}, pg);

await test('karta Systém: aktualizace bez tokenu', async () => {
  await pg.waitForSelector('#updBox dl');
  const t = await pg.innerText('#updBox');
  ok(t.includes('Nainstalovaná verze') && t.includes('update_token'), t);
  ok(await pg.isDisabled('#updCheck'), 'tlačítko Zjistit novou verzi je bez tokenu vypnuté');
}, pg);

await done(br);
