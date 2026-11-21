// Nápověda: obsah, stránky, hledání, tlačítka „?“.
import { B, browser, login, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);

await test('Nápověda v menu otevře první stránku a obsah', async () => {
  await pg.click('nav a[data-view="napoveda"]');
  await pg.waitForSelector('#hBody h1');
  ok((await pg.$$('#hToc a')).length >= 60, 'odkazů v obsahu');
  ok((await pg.innerText('#hBody h1')).length > 3, 'nadpis stránky');
  ok(await pg.isVisible('#hBody .help-nav'), 'předchozí/další');
}, pg);

await test('přechod z obsahu a „Související stránky“', async () => {
  await pg.goto(`${B}/#napoveda/jak-nahrat-soubor`); await pg.waitForSelector('#hBody h1');
  ok(/nahr/i.test(await pg.innerText('#hBody h1')), await pg.innerText('#hBody h1'));
  ok(await pg.isVisible('#hBody ol li'), 'číslované kroky');
  const rel = await pg.$('.help-rel a');
  ok(rel, 'související');
  const href = await rel.getAttribute('href');
  await rel.click(); await pg.waitForFunction((h) => location.hash === h, href);
  await pg.waitForSelector('#hBody h1');
}, pg);

await test('tabulka, citace a snímek se vykreslí', async () => {
  await pg.goto(`${B}/#napoveda/hlasky-galerie`); await pg.waitForSelector('#hBody table');
  ok((await pg.$$('#hBody tbody tr')).length >= 3, 'řádky tabulky');
  const ok2 = await pg.evaluate(() => window.helpMarkdown('> Pozor\n\n[SNÍMEK: test]\n\n- a\n  - b').replace(/\s+/g, ' '));
  ok(ok2.includes('<blockquote>') && ok2.includes('help-shot') && ok2.includes('<ul><li>a<ul><li>b'), ok2);
}, pg);

await test('hledání bez diakritiky a se skloňováním', async () => {
  await pg.goto(`${B}/#napoveda`); await pg.waitForSelector('#hQ');
  await pg.fill('#hQ', 'heslo');
  await pg.waitForSelector('.help-hit');
  ok((await pg.$$('.help-hit')).length >= 2, 'nalezeno');
  await pg.fill('#hQ', 'synchronizace televize');
  await pg.waitForSelector('.help-hit');
  await pg.fill('#hQ', 'zzzxxqq');
  await pg.waitForSelector('#hBody .empty');
  await pg.fill('#hQ', '');
  await pg.waitForSelector('#hBody h1:not(:has-text("Výsledky"))');
}, pg);

await test('neznámá stránka', async () => {
  await pg.goto(`${B}/#napoveda/nic-takoveho`); await pg.waitForSelector('#hBody h1');
  ok((await pg.innerText('#hBody h1')).length > 0, 'zobrazí první stránku');
}, pg);

await test('tlačítka „?“ vedou na správnou stránku', async () => {
  const cases = [['prehled', 'prehled'], ['scenare', 'scenare'], ['galerie', 'galerie'], ['data', 'data'], ['protokol', 'protokol']];
  for (const [view, ctx] of cases) {
    await pg.goto(`${B}/#${view}`); await pg.waitForSelector('h1 .help-q');
    await pg.click('h1 .help-q');
    await pg.waitForFunction(() => location.hash.startsWith('#napoveda'));
    await pg.waitForSelector('#hToc a.on');
    const on = await pg.getAttribute('#hToc a.on', 'href');
    ok(on && on.length > 11, `${view}: ${on}`);
  }
  for (const [tab, id] of [['disk', 'nastaveni-google-disk'], ['obrazky', 'obrazky-komprese'], ['prehravac', 'nastaveni-prehravac'], ['system', 'nastaveni-system']]) {
    await pg.goto(`${B}/#nastaveni/${tab}`); await pg.waitForSelector('#setHelp');
    await pg.click('#setHelp'); await pg.waitForSelector('#hToc a.on');
    eq(await pg.getAttribute('#hToc a.on', 'href'), `#napoveda/${id}`, tab);
  }
}, pg);

await test('mobilní zobrazení: obsah za tlačítkem', async () => {
  await pg.setViewportSize({ width: 390, height: 800 });
  await pg.goto(`${B}/#napoveda`); await pg.waitForSelector('#hTocBtn');
  ok(await pg.isHidden('#hToc'), 'obsah skrytý');
  await pg.click('#hTocBtn'); ok(await pg.isVisible('#hToc'), 'obsah otevřen');
  await pg.setViewportSize({ width: 1280, height: 800 });
}, pg);

await done(br);
