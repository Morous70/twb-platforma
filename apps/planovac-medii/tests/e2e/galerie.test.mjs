// Nahrání obrázků s automatickou kompresí, výběr podle data (oprava 1.4.1), scénář a synchronizace.
import { B, TMP, browser, login, api, toasts, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);
const media = () => api(pg, 'media');
const byName = async (n) => (await media()).find((m) => m.original_name === n || m.upload_name === n);

await test('nahrání: velké PNG se zkomprimuje na JPG, průhledné logo zůstane PNG', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#fileIn', { state: 'attached' });
  await pg.setInputFiles('#fileIn', [`${TMP}/fixtures/plakat.png`, `${TMP}/fixtures/logo.png`, `${TMP}/fixtures/er_261024_01.jpg`, `${TMP}/fixtures/er_261031_02.jpg`]);
  for (let i = 0; i < 60 && (await media()).length < 4; i++) await pg.waitForTimeout(250);
  await pg.waitForTimeout(500);
  const p = await byName('plakat.jpg');
  ok(p, 'plakat.jpg v galerii: ' + JSON.stringify((await media()).map((m) => m.original_name)));
  eq(['image/jpeg', 1755, 1080], [p.mime, p.width, p.height]);
  eq([2600, 1600, 'png'], [p.compressed?.orig_w, p.compressed?.orig_h, p.compressed?.orig_ext], 'originál uchován');
  const l = await byName('logo.png');
  eq('image/png', l?.mime);
}, pg);

await test('datum z názvu souboru (dateFromName)', async () => {
  const r = await pg.evaluate(() => ['er_261024_01.jpg', 'akce-2026-11-03.png', '24.10.2026 renta.jpg', 'foto_20261231.jpg', 'IMG_1234.jpg', 'x_191024.jpg'].map(dateFromName));
  eq(['2026-10-24', '2026-11-03', '2026-10-24', '2026-12-31', null, null], r);
}, pg);

let poolId;
await test('výběr podle data: datum psané po číslicích se uloží celé (oprava 1.4.1)', async () => {
  const e1 = await byName('er_261024_01.jpg');
  const r = await api(pg, 'dated_save', { name: 'Extra renta', items: [{ id: e1.id, days: ['2026-10-24'] }] });
  poolId = r.id ?? (await byName('Extra renta')).id;
  await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector(`[data-edit="${poolId}"]`);
  await pg.click(`[data-edit="${poolId}"]`); await pg.waitForSelector('#dItems');
  const row = '#dItems .de-row >> nth=0';
  await pg.focus(`${row} >> .de-add`); await pg.keyboard.type('31102026', { delay: 40 });
  await pg.keyboard.press('Enter'); await pg.waitForTimeout(150);
  await pg.focus(`${row} >> .de-add`); await pg.keyboard.type('0211', { delay: 40 });
  await pg.click(`${row} >> .de-addbtn`); await pg.waitForTimeout(200);
  ok(/rok|datum/i.test(await toasts(pg)), 'neúplné datum se nepřidá: ' + await toasts(pg));
  await pg.click('#mSave'); await pg.waitForTimeout(800);
  const p = (await media()).find((m) => m.id === poolId);
  eq([{ id: e1.id, days: ['2026-10-24', '2026-10-31'] }], p.items);
}, pg);

await test('server odmítne rok mimo 2000–2099', async () => {
  const e1 = await byName('er_261024_01.jpg');
  const err = await api(pg, 'dated_save', { id: poolId, name: 'Extra renta', items: [{ id: e1.id, days: ['0002-10-22'] }] }).then(() => null, (e) => e.message);
  ok(err && err.includes('2000–2099'), String(err));
}, pg);

await test('výchozí scénář s výběrem podle data a synchronizace', async () => {
  const logo = await byName('logo.png');
  await api(pg, 'scenario_save', { name: 'Výchozí', is_default: 1, media_ids: [logo.id, poolId] });
  const prev = await api(pg, 'preview', undefined, '&from=2026-10-19');
  const e1 = await byName('er_261024_01.jpg');
  const ids = (date) => { const d = prev.days.find((x) => x.date === date); return prev.sets[d.hours[10]].media.map((m) => m.id); };
  ok(ids('2026-10-24').includes(e1.id), 'sobota 24. 10.: ' + JSON.stringify(ids('2026-10-24')));
  ok(!ids('2026-10-23').includes(e1.id), 'pátek 23. 10. bez položky');
  eq([logo.id], ids('2026-10-23'));
  const s = await api(pg, 'sync');
  ok(['ok', 'unchanged'].includes(s.status), JSON.stringify(s));
}, pg);

await test('editor slidu: pole Skupina se uloží a po znovuotevření je vyplněné (oprava 1.12.1)', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#newSlide');
  await pg.click('#newSlide');
  await pg.waitForSelector('#seGrp');
  await pg.fill('#seName', 'Slide se skupinou');
  await pg.fill('#seGrp', 'ER');
  await pg.click('#seSave');
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'Slide se skupinou.jpg'));
  const m = await byName('Slide se skupinou.jpg');
  eq('ER', m && m.grp, 'skupina v galerii');
  await pg.evaluate(() => { main.innerHTML = ''; });
  await pg.evaluate((id) => editSlide(store.media.find((x) => x.id === id)), m.id);
  await pg.waitForSelector('#seGrp');
  eq('ER', await pg.inputValue('#seGrp'));
  ok(await pg.$('#groupList option[value="ER"]'), 'nabídka existujících skupin');
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.evaluate((id) => { main.innerHTML = ''; editSlide(store.media.find((x) => x.id === id)); }, m.id);
  await pg.waitForSelector('#seGrp');
  await pg.waitForTimeout(300);
  eq('ER', await pg.inputValue('#seGrp'), 'na telefonu');
  ok(await pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'na telefonu stránka nepřetéká do strany');
  if (TMP) await pg.screenshot({ path: `${TMP}/slide-skupina-mobil.png` });
  await pg.setViewportSize({ width: 1400, height: 1000 });
}, pg);

await test('archiv: médium zmizí z nabídky, ve scénáři dál hraje, z archivu se vrátí i trvale smaže', async () => {
  const logo = await byName('logo.png');
  await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector(`[data-arch="${logo.id}"]`);
  const vis = await pg.evaluate((id) => { const c = document.querySelector(`.card[data-id="${id}"]`).getBoundingClientRect(); return ['data-edit', 'data-arch', 'data-del'].map((a) => { const r = document.querySelector(`[${a}="${id}"]`).getBoundingClientRect(); return r.right <= c.right + 1 && r.left >= c.left; }); }, logo.id);
  eq([true, true, true], vis, 'ikony Upravit, Do archivu a Smazat jsou celé vidět na kartě');
  const bottoms = await pg.evaluate(() => [...document.querySelectorAll('#gGrid .card')].map((c) => Math.round(c.getBoundingClientRect().bottom - c.querySelector('[data-del]').getBoundingClientRect().bottom)));
  ok(bottoms.length > 1 && new Set(bottoms).size === 1, 'ikony jsou u spodku karty ve stejné výšce: ' + bottoms);
  eq(['Upravit', 'Do archivu', 'Smazat'], await pg.evaluate((id) => ['data-edit', 'data-arch', 'data-del'].map((a) => document.querySelector(`[${a}="${id}"]`).title.split(' – ')[0]), logo.id), 'popisky ikon');
  if (TMP) await pg.screenshot({ path: `${TMP}/galerie-karta.png` });
  await pg.click(`[data-arch="${logo.id}"]`);
  await pg.waitForFunction(() => /^1 ·/.test(document.querySelector('#archiveChip .cnt')?.textContent || ''));
  eq(0, await pg.locator(`#gGrid [data-id="${logo.id}"]`).count(), 'z galerie zmizelo');
  await pg.click('#archiveChip');
  await pg.waitForSelector(`#gGrid [data-id="${logo.id}"]`);
  ok((await pg.textContent(`[data-usedin="${logo.id}"]`)).includes('Výchozí'), await pg.textContent(`[data-usedin="${logo.id}"]`));
  if (TMP) await pg.screenshot({ path: `${TMP}/archiv.png` });
  // ve scénáři dál je, v nabídce k přidání ne
  const sc = (await api(pg, 'scenarios')).find((x) => x.name === 'Výchozí');
  ok(sc.media_ids.includes(logo.id), 'dál ve scénáři');
  await pg.goto(`${B}/#scenare`); await pg.reload();
  await pg.waitForFunction(() => store.scenarios.length);
  await pg.evaluate((id) => editScenario(store.scenarios.find((x) => x.id === id)), sc.id);
  await pg.waitForSelector('#inList');
  ok((await pg.textContent('#inList')).includes('🗄'), 've scénáři označeno');
  eq(0, await pg.evaluate(() => [...document.querySelectorAll('#main .card, .pick-card, [data-add]')].filter((e) => !e.closest('#inList') && e.textContent.includes('logo.png')).length), 'nenabízí se');
  await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector('#archiveChip');
  await pg.click('#archiveChip'); await pg.waitForSelector(`[data-unarch="${logo.id}"]`);
  await pg.click(`[data-unarch="${logo.id}"]`);
  await pg.waitForFunction((id) => store.media.some((x) => x.id === id), logo.id);
  ok(!(await media()).find((m) => m.id === logo.id).archived, 'vráceno');
  // hromadně do archivu a trvalé smazání
  await pg.click('#gTags [data-all]');
  await pg.waitForSelector(`[data-sel="${logo.id}"]`);
  await pg.check(`[data-sel="${logo.id}"]`);
  await pg.click('#bArch');
  await pg.waitForFunction((id) => store.archive.some((x) => x.id === id), logo.id);
  await pg.click('#archiveChip'); await pg.waitForSelector(`[data-del="${logo.id}"]`);
  await pg.click(`[data-del="${logo.id}"]`);
  await pg.waitForFunction((id) => !mediaById(id), logo.id);
  ok(!(await media()).find((m) => m.id === logo.id), 'trvale smazáno');
}, pg);

await done(br);
