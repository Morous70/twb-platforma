// Telefon (390 px): horní pruh s menu ☰, Galerie (dlaždice Nahrát soubor, filtry), Přidat do scénáře, spodní lišta Uložit, karty Nastavení.
import { B, TMP, browser, login, api, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);
const noHScroll = () => pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth);

await test('počítač: přidávací dlaždice s popisem po najetí myší', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#addTiles');
  eq(['Nahrát soubor', 'Slide', 'Běžící text', 'Odkaz na web', 'Náhodný výběr', 'Podle data', 'HTML'], await pg.$$eval('#addTiles .lbl', (e) => e.map((x) => x.textContent)));
  ok(await pg.isVisible('.drop-txt'), 'na počítači zůstává i plocha pro přetažení');
  ok(await pg.isHidden('#newPoolTip'), 'bublina je skrytá');
  await pg.hover('#newPool'); await pg.waitForTimeout(500);
  ok(await pg.isVisible('#newPoolTip'), 'po najetí myší je bublina vidět');
  ok((await pg.innerText('#newPoolTip')).includes('každou hodinu'), 'popis');
  await pg.click('#newText'); await pg.waitForSelector('#modal:not([hidden])');
  await pg.evaluate(() => closeModal());
  const [ch] = await Promise.all([pg.waitForEvent('filechooser'), pg.click('#upTile')]);
  ok(ch.isMultiple(), 'Nahrát soubor otevře výběr souborů');
}, pg);

await test('počítač: horní pruh není, levý panel je vidět', async () => {
  await pg.goto(`${B}/#prehled`); await pg.waitForSelector('#main h1');
  ok(await pg.isHidden('#topbar'), 'horní pruh skrytý');
  ok(await pg.isVisible('.sidebar nav a[data-view="galerie"]'), 'menu vlevo');
}, pg);

await pg.setViewportSize({ width: 390, height: 844 });

await test('telefon: horní pruh s názvem stránky, menu je schované', async () => {
  await pg.goto(`${B}/#scenare`); await pg.waitForSelector('#main h1'); await pg.waitForTimeout(400);
  ok(await pg.isVisible('#topbar'), 'horní pruh');
  eq('Scénáře', await pg.innerText('#tbTitle'));
  const x = await pg.evaluate(() => document.querySelector('.sidebar').getBoundingClientRect().right);
  ok(x <= 0, `menu je mimo obrazovku (${x})`);
  const top = await pg.evaluate(() => document.querySelector('#main h1').getBoundingClientRect().top);
  ok(top < 120, `obsah začíná nahoře (${top} px)`);
  ok(await noHScroll(), 'bez vodorovného posunu');
}, pg);

await test('telefon: menu ☰ – otevření, výběr stránky, zavření klepnutím vedle a klávesou Esc', async () => {
  await pg.click('#menuBtn'); await pg.waitForTimeout(300);
  eq('true', await pg.getAttribute('#menuBtn', 'aria-expanded'));
  const x = await pg.evaluate(() => document.querySelector('.sidebar').getBoundingClientRect().left);
  eq(0, Math.round(x), 'menu vysunuté');
  ok(await pg.isVisible('#themeSw') && await pg.isVisible('.sidebar >> text=Odhlásit'), 'vzhled a Odhlásit v menu');
  await pg.click('.sidebar nav a[data-view="galerie"]'); await pg.waitForTimeout(300);
  await pg.waitForFunction(() => location.hash === '#galerie');
  eq('Galerie', await pg.innerText('#tbTitle'));
  ok(!(await pg.evaluate(() => document.body.classList.contains('menu-open'))), 'po výběru se zavře');
  await pg.click('#menuBtn'); await pg.waitForTimeout(300);
  await pg.mouse.click(370, 400); await pg.waitForTimeout(300);
  ok(!(await pg.evaluate(() => document.body.classList.contains('menu-open'))), 'klepnutí vedle zavře');
  await pg.click('#menuBtn'); await pg.keyboard.press('Escape'); await pg.waitForTimeout(300);
  ok(!(await pg.evaluate(() => document.body.classList.contains('menu-open'))), 'Esc zavře');
  await pg.click('#menuBtn'); await pg.click('#menuClose'); await pg.waitForTimeout(300);
  ok(!(await pg.evaluate(() => document.body.classList.contains('menu-open'))), '✕ zavře');
}, pg);

await test('telefon: Galerie – dlaždice Nahrát soubor, filtry v jednom řádku', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#upTile');
  ok(await pg.isVisible('#upTile'), 'dlaždice Nahrát soubor');
  const tiles = await pg.$$eval('#addTiles .add-tile', (els) => els.map((e) => { const r = e.getBoundingClientRect(); return [Math.round(r.width), Math.round(r.height), Math.round(r.top)]; }));
  eq(7, tiles.length, 'sedm dlaždic');
  ok(tiles.every(([w, h]) => Math.abs(w - h) <= 1), `čtverce: ${JSON.stringify(tiles)}`);
  eq(2, new Set(tiles.map((x) => x[2])).size, 'dva řádky po čtyřech');
  ok(await pg.isHidden('#newSlideTip'), 'na telefonu bez bubliny');
  ok(await pg.isHidden('.drop-txt'), 'text o přetažení skrytý');
  const rows = await pg.evaluate(() => { const b = [...document.querySelectorAll('#gTypes > *')].map((e) => Math.round(e.getBoundingClientRect().top)); return new Set(b).size; });
  eq(1, rows, 'typy v jednom řádku');
  const [chooser] = await Promise.all([pg.waitForEvent('filechooser'), pg.click('#upTile')]);
  ok(chooser.isMultiple(), 'více souborů najednou');
  await chooser.setFiles([`${TMP}/fixtures/plakat.png`]);
  // nahrání skončí až po kompresi (plakat.png → plakat.jpg); mezitím už může být v seznamu původní název
  for (let i = 0; i < 80 && !(await api(pg, 'media')).some((m) => m.original_name === 'plakat.jpg'); i++) await pg.waitForTimeout(250);
  ok((await api(pg, 'media')).some((m) => m.original_name === 'plakat.jpg'), 'nahráno');
  ok(await noHScroll(), 'bez vodorovného posunu');
}, pg);

await test('okno média: Přidat do scénáře', async () => {
  const s1 = (await api(pg, 'scenario_save', { name: 'Výchozí program', is_default: 1, media_ids: [] })).id;
  await api(pg, 'scenario_save', { name: 'Víkend', weekdays: '67', media_ids: [] });
  const mid = Math.max(...(await api(pg, 'media')).filter((m) => m.original_name === 'plakat.jpg').map((m) => m.id));
  await pg.goto(`${B}/#prehled`); await pg.waitForSelector('#main h1');
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector(`#gGrid [data-edit="${mid}"]`);
  await pg.click(`#gGrid [data-edit="${mid}"]`); await pg.waitForSelector('#mScenBox select, #mScenBox .muted');
  ok((await pg.innerText('#mScenBox')).includes('zatím není v žádném scénáři'));
  await pg.click('#mAddScen');
  await pg.waitForFunction(() => /Vyberte scénář/.test(document.querySelector('#toasts')?.innerText || ''));
  await pg.selectOption('#mScen', String(s1));
  await pg.click('#mAddScen');
  await pg.waitForFunction(() => /je ve scénářích/.test(document.querySelector('#mScenBox')?.innerText || ''));
  ok((await pg.innerText('#mScenBox')).includes('Výchozí program'));
  eq(0, await pg.$$eval(`#mScen option[value="${s1}"]`, (o) => o.length), 'scénář už v nabídce není');
  ok((await api(pg, 'scenarios')).find((s) => s.id === s1).media_ids.includes(mid), 'uloženo');
  // tlačítko Zpět (změna adresy) okno zavře
  await pg.evaluate(() => { location.hash = '#prehled'; });
  await pg.waitForFunction(() => document.querySelector('#modal').hidden);
}, pg);

await test('telefon: editor scénáře – lišta Zrušit/Uložit přilepená dole', async () => {
  await pg.goto(`${B}/#scenare`); await pg.waitForSelector('[data-act=edit]');
  await pg.click('[data-act=edit]'); await pg.waitForSelector('.save-bar #save');
  const r = await pg.evaluate(() => { const b = document.querySelector('.save-bar').getBoundingClientRect(); return [Math.round(b.bottom), innerHeight, Math.round(b.left)]; });
  eq([844, 844, 0], r);
  ok(await noHScroll(), 'editor bez vodorovného posunu');
  await pg.mouse.wheel(0, 3000); await pg.waitForTimeout(300);
  ok(await pg.isVisible('#save'), 'Uložit je vidět i po posunutí');
  await pg.click('#save');
  await pg.waitForFunction(() => /uložen/.test(document.querySelector('#toasts')?.innerText || ''));
  await pg.waitForSelector('[data-act=edit]');
}, pg);

await test('telefon: Přehled ukazuje obrázky dva vedle sebe', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#fileIn', { state: 'attached' });
  const before = (await api(pg, 'media')).length;
  await pg.setInputFiles('#fileIn', [`${TMP}/fixtures/er_261024_01.jpg`, `${TMP}/fixtures/logo.png`]);
  for (let i = 0; i < 60 && (await api(pg, 'media')).length < before + 2; i++) await pg.waitForTimeout(250);
  await pg.waitForTimeout(800);
  const ids = (await api(pg, 'media')).filter((m) => ['image'].includes(m.kind)).map((m) => m.id).slice(0, 3);
  await api(pg, 'scenario_save', { name: 'Přehled dvojice', is_default: 1, media_ids: ids });
  await pg.goto(`${B}/#scenare`); await pg.waitForSelector('#main h1');
  await pg.goto(`${B}/#prehled`); await pg.waitForSelector('.now-grid .now-item');
  const cols = await pg.$eval('.now-grid', (e) => getComputedStyle(e).gridTemplateColumns.split(' ').length);
  eq(2, cols, 'dva sloupce');
  const w = await pg.$eval('.now-grid .now-item', (e) => e.getBoundingClientRect().width);
  ok(w < 200, `šířka obrázku ${w}`);
}, pg);

await test('telefon: karty Nastavení se vejdou bez posouvání', async () => {
  await pg.goto(`${B}/#nastaveni`); await pg.waitForSelector('#setTabs button');
  const out = await pg.$$eval('#setTabs button', (bs) => bs.filter((b) => b.getBoundingClientRect().right > innerWidth).length);
  eq(0, out);
  await pg.click('#setTabs [data-tab=zabezpeceni]'); await pg.waitForSelector('#idleMin');
  ok(await noHScroll(), 'bez vodorovného posunu');
}, pg);

await done(br);
