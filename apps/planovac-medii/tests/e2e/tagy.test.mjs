// Tagy médií (1.17.0): pole v okně Upravit, štítky na kartě (3 + „+N“), filtr všechny/kterýkoli, hledání, hromadně,
// filtr ve výběru scénáře, editor slidu, Nastavení → Tagy (přejmenovat se sloučením, smazat), archiv, telefon.
import { B, TMP, browser, login, api, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);
const media = () => api(pg, 'media');
// nejnovější médium s tímto názvem (v celé sadě mohou zůstat stejně pojmenovaná z jiných testů)
const byName = async (n) => (await media()).filter((m) => m.original_name === n).sort((x, y) => y.id - x.id)[0];
const txt = (name, tags) => api(pg, 'text_save', { name, content: `Text ${name}`, tags });
const gallery = async () => { await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector('#gGrid .card'); };
const names = () => pg.$$eval('#gGrid .card .name', (e) => e.map((x) => x.textContent.replace(/\.txt$/, '')).sort());

await txt('Alfa', ['Vánoce', 'Sportka']);
await txt('Beta', ['Sportka']);
await txt('Gama', ['Vánoce', 'Akce', 'Loterie', 'Tabák', 'Noviny']);
await txt('Delta', []);

await test('okno Upravit: tag se přidá Enterem i čárkou, zápis převezme existující tag (vánoce → Vánoce), × odebere', async () => {
  await gallery();
  const id = (await byName('Delta.txt')).id;
  await pg.click(`[data-edit="${id}"]`);
  await pg.waitForSelector('#mTags .tag-in');
  await pg.fill('#mTags .tag-in', 'vánoce'); await pg.keyboard.press('Enter');
  await pg.type('#mTags .tag-in', 'Nový tag,');
  await pg.type('#mTags .tag-in', 'Smazat');
  await pg.keyboard.press('Enter');
  eq(['Vánoce×', 'Nový tag×', 'Smazat×'], await pg.$$eval('#mTags .tg', (e) => e.map((x) => x.textContent.trim())));
  await pg.click('#mTags .tg:has-text("Smazat") button');
  await pg.type('#mTags .tag-in', 'Rozepsaný'); // nepotvrzený tag se při uložení také uloží
  if (TMP) await pg.screenshot({ path: `${TMP}/tagy-upravit.png` });
  await pg.click('#mSave');
  await pg.waitForFunction(() => document.querySelector('#modal').hidden);
  eq(['Vánoce', 'Nový tag', 'Rozepsaný'], (await byName('Delta.txt')).tags);
}, pg);

await test('karta: nejvýš 3 štítky, zbytek jako „+2“ s názvy v popisku', async () => {
  await gallery();
  const id = (await byName('Gama.txt')).id;
  const t = await pg.$$eval(`#gGrid .card[data-id="${id}"] .tags .tg`, (e) => e.map((x) => [x.textContent, x.title]));
  eq([['Vánoce', 'Zobrazit jen média s tagem Vánoce'], ['Akce', 'Zobrazit jen média s tagem Akce'], ['Loterie', 'Zobrazit jen média s tagem Loterie'], ['+2', 'Tabák, Noviny']], t);
  if (TMP) await pg.screenshot({ path: `${TMP}/tagy-galerie.png` });
}, pg);

await test('filtr: jeden tag, dva tagy „všechny“ (A) a „kterýkoli“ (NEBO), zrušení; hledání najde i tag', async () => {
  await gallery();
  await pg.click('#gTags [data-tag="vánoce"]');
  eq(['Alfa', 'Delta', 'Gama'], await names());
  await pg.click('#gTags [data-tag="sportka"]');
  eq(['Alfa'], await names(), 'všechny');
  await pg.click('#gTags [data-mode="or"]');
  eq(['Alfa', 'Beta', 'Delta', 'Gama'], await names(), 'kterýkoli');
  await pg.click('#gTags [data-all]');
  const all = await names();
  ok(['Alfa', 'Beta', 'Delta', 'Gama'].every((n) => all.includes(n)) && !(await pg.$('#gTags .tagchip.on')), 'po zrušení zase vše: ' + all.join(', '));
  await pg.fill('#gQ', 'rozepsan');
  eq(['Delta'], await names(), 'hledání v tazích');
  await pg.fill('#gQ', '');
  await pg.click('#gTags [data-mode="and"]');
}, pg);

await test('hromadně: přidat tag vybraným, odebrat tag z vybraných', async () => {
  await gallery();
  const a = (await byName('Alfa.txt')).id, b = (await byName('Beta.txt')).id;
  await pg.check(`[data-sel="${a}"]`); await pg.check(`[data-sel="${b}"]`);
  await pg.click('#bTags');
  await pg.waitForSelector('#btAdd .tag-in');
  await pg.type('#btAdd .tag-in', 'Hromadně');
  await pg.click('#btGo');
  await pg.waitForFunction(() => document.querySelector('#modal').hidden);
  ok((await byName('Alfa.txt')).tags.includes('Hromadně') && (await byName('Beta.txt')).tags.includes('Hromadně'), 'přidáno');
  await pg.waitForSelector(`[data-sel="${a}"]`);
  await pg.check(`[data-sel="${a}"]`); await pg.check(`[data-sel="${b}"]`);
  await pg.click('#bTags');
  await pg.click('#btRm [data-rmtag="Sportka"]');
  await pg.waitForFunction(() => document.querySelector('#modal').hidden);
  eq([['Vánoce', 'Hromadně'], ['Hromadně']], [(await byName('Alfa.txt')).tags, (await byName('Beta.txt')).tags]);
}, pg);

await test('výběr ve scénáři: filtr tagů zúží nabídku médií', async () => {
  await pg.goto(`${B}/#scenare`); await pg.reload();
  await pg.waitForFunction(() => typeof store !== 'undefined' && store.media.length);
  await pg.evaluate(() => editScenario(null));
  await pg.waitForSelector('#pTags [data-tag]');
  await pg.click('#pTags [data-tag="hromadně"]');
  await pg.waitForTimeout(200);
  const shown = await pg.evaluate(() => [...document.querySelectorAll('#main [data-add], #main .pick-item, #main .card')].map((c) => c.closest('[data-id]')?.dataset.id).filter(Boolean));
  const ids = [(await byName('Alfa.txt')).id, (await byName('Beta.txt')).id].map(String).sort();
  eq(ids, [...new Set(shown)].sort(), 'jen Alfa a Beta');
}, pg);

await test('editor slidu: tlačítko 🏷 Tagy, tagy se uloží se slidem', async () => {
  await gallery();
  await pg.click('#newSlide');
  await pg.waitForSelector('#seTags');
  await pg.fill('#seName', 'Slide s tagy');
  await pg.click('#seTags');
  await pg.type('#etTags .tag-in', 'Akce'); await pg.keyboard.press('Enter');
  await pg.click('#etOk');
  eq('1', await pg.textContent('#seTagCnt'));
  await pg.click('#seSave');
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'Slide s tagy.jpg'));
  eq(['Akce'], (await byName('Slide s tagy.jpg')).tags);
  await pg.click('#seBack');
}, pg);

await test('Nastavení → Tagy: počty, přejmenování na existující tag sloučí, smazání odebere ze všech', async () => {
  await pg.goto(`${B}/#nastaveni/tagy`); await pg.reload();
  await pg.waitForSelector('#tagTbl');
  const rows = await pg.$$eval('#tagTbl tbody tr', (r) => r.map((x) => [...x.querySelectorAll('td')].slice(0, 2).map((t) => t.textContent.trim()).join(':')));
  ok(rows.includes('Akce:2') && rows.includes('Vánoce:3'), rows.join(', '));
  if (TMP) await pg.screenshot({ path: `${TMP}/tagy-nastaveni.png` });
  pg.removeAllListeners('dialog');
  pg.once('dialog', (d) => d.accept('vánoce'));
  await pg.click('#tagTbl [data-tren="Akce"]');
  await pg.waitForFunction(() => !document.querySelector('#tagTbl [data-tren="Akce"]'));
  eq(['Vánoce', 'Loterie', 'Tabák', 'Noviny'], (await byName('Gama.txt')).tags, 'sloučeno bez zdvojení');
  eq(['Vánoce'], (await byName('Slide s tagy.jpg')).tags);
  pg.on('dialog', (d) => d.accept());
  await pg.click('#tagTbl [data-tdel="Noviny"]');
  await pg.waitForFunction(() => !document.querySelector('#tagTbl [data-tdel="Noviny"]'));
  eq(['Vánoce', 'Loterie', 'Tabák'], (await byName('Gama.txt')).tags);
}, pg);

await test('archiv: tagy zůstanou a filtr funguje i v pohledu Archiv', async () => {
  const id = (await byName('Gama.txt')).id;
  await api(pg, 'media_archive', { ids: [id], archive: true });
  await gallery();
  await pg.click('#archiveChip');
  await pg.waitForSelector(`#gGrid .card[data-id="${id}"] .tags`);
  await pg.click('#gTags [data-tag="tabák"]');
  eq(['Gama'], await names());
  await pg.click('#gTags [data-all]');
  await api(pg, 'media_archive', { ids: [id], archive: false });
}, pg);

await test('rychlý filtr: klik na tag na kartě ukáže jen média s tagem, druhý klik filtr zruší; Bez tagu', async () => {
  await gallery();
  const id = (await byName('Alfa.txt')).id;
  await pg.click(`#gGrid .card[data-id="${id}"] [data-qtag="hromadně"]`);
  eq(['Alfa', 'Beta'], await names());
  ok(await pg.$('#gTags [data-tag="hromadně"].on'), 'tag v řadě vybraný');
  await pg.click(`#gGrid .card[data-id="${id}"] [data-qtag="hromadně"]`);
  ok((await names()).length > 2, 'filtr zrušen');
  ok(await pg.$('#gTags [data-all].on'), 'Vše');
  await txt('Bez tagu text', []);
  await gallery();
  await pg.click('#gTags [data-none]');
  ok((await names()).includes('Bez tagu text') && !(await names()).includes('Alfa'), (await names()).join(', '));
  await pg.click('#gTags [data-all]');
}, pg);

await test('vybraný tag dostane nově nahraný soubor i nové médium z dlaždice; přetažení média na tag ho přidá', async () => {
  await gallery();
  await pg.click('#gTags [data-tag="loterie"]');
  ok((await pg.innerText('#dropTo')).includes('dostanou tag Loterie'), await pg.innerText('#dropTo'));
  const before = (await media()).filter((m) => m.original_name === 'logo.png').length; // v celé sadě už může logo.png být
  await pg.setInputFiles('#fileIn', [`${TMP}/fixtures/logo.png`]);
  await pg.waitForFunction((n) => store.media.filter((x) => x.original_name === 'logo.png').length > n, before);
  eq(['Loterie'], (await byName('logo.png')).tags, 'nahraný soubor');
  await pg.click('#newText');
  await pg.waitForSelector('#mTags .tg');
  eq(['Loterie×'], await pg.$$eval('#mTags .tg', (e) => e.map((x) => x.textContent.trim())), 'předvyplněno');
  await pg.fill('#mName', 'S tagem z filtru'); await pg.fill('#mText', 'x');
  await pg.click('#mSave');
  await pg.waitForFunction(() => document.querySelector('#modal').hidden);
  eq(['Loterie'], (await byName('S tagem z filtru.txt')).tags);
  await pg.click('#gTags [data-all]');
  const b = (await byName('Beta.txt')).id;
  await pg.dragAndDrop(`#gGrid .card[data-id="${b}"]`, '#gTags [data-tag="tabák"]');
  await pg.waitForFunction((id) => store.media.find((x) => x.id === id)?.tags.includes('Tabák'), b);
  ok((await byName('Beta.txt')).tags.includes('Tabák'), 'přetažením');
}, pg);

await test('editor scénáře: místo složek řada tagů, tlačítko „+ Přidat vše s tagem“', async () => {
  await pg.goto(`${B}/#scenare`); await pg.reload();
  await pg.waitForFunction(() => typeof store !== 'undefined' && store.media.length);
  await pg.evaluate(() => editScenario(null));
  await pg.waitForSelector('#pTags [data-tag]');
  eq(0, await pg.locator('#pFolders').count(), 'bez složek');
  await pg.click('#pTags [data-tag="loterie"]');
  await pg.waitForSelector('#addAllVis');
  ok((await pg.innerText('#addAllVis')).startsWith('+ Přidat vše s tagem „Loterie“'), await pg.innerText('#addAllVis'));
  await pg.click('#addAllVis');
  const inList = await pg.innerText('#inList');
  ok(inList.includes('logo.png') && inList.includes('S tagem z filtru'), inList);
}, pg);

await test('telefon: filtr tagů v jednom posuvném řádku, stránka nepřetéká', async () => {
  await pg.setViewportSize({ width: 390, height: 844 });
  await gallery();
  const rows = await pg.evaluate(() => new Set([...document.querySelectorAll('#gTags > *')].map((e) => { const r = e.getBoundingClientRect(); return Math.round((r.top + r.bottom) / 20); })).size);
  eq(1, rows, 'jeden řádek');
  eq('nowrap', await pg.evaluate(() => getComputedStyle(document.querySelector('#gTags')).flexWrap));
  ok(await pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'bez vodorovného posunu');
  if (TMP) await pg.screenshot({ path: `${TMP}/tagy-mobil.png` });
  await pg.setViewportSize({ width: 1400, height: 1000 });
}, pg);

await done(br);
