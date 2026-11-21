// Zvětšení náhledu (1.19.0): Přehled (Právě teď), seznam scénářů, editor scénáře (Ve scénáři, lupa v galerii výběru),
// Galerie; listování šipkami, klávesami i tažením prstem; okno přes celou šířku.
import { B, TMP, browser, login, api, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);
const txt = (name) => api(pg, 'text_save', { name, content: `Text ${name}` }).then((r) => r.media);
const a = await txt('Jedna'), b = await txt('Dva'), c = await txt('Tři');
// obrázek kvůli kontrole velikosti v okně
await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#fileIn', { state: 'attached' });
await pg.setInputFiles('#fileIn', [`${TMP}/fixtures/plakat.png`]);
await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'plakat.jpg'), null, { timeout: 30000 });
const img = (await api(pg, 'media')).find((x) => x.original_name === 'plakat.jpg');
await api(pg, 'scenario_save', { name: 'Zvětšení', is_default: 1, media_ids: [a.id, b.id, c.id, img.id] });
const pos = () => pg.textContent('#pvPos');
const name = () => pg.textContent('.pv-name');
const closeIt = async () => { await pg.click('#modalBox [data-close]'); await pg.waitForFunction(() => document.querySelector('#modal').hidden); };

await test('Přehled: klik na náhled v Právě teď otevře okno přes celou šířku, šipky a klávesy listují', async () => {
  await pg.goto(`${B}/#prehled`); await pg.reload();
  await pg.waitForSelector(`.now-grid [data-zoom="${img.id}"]`);
  const n = await pg.evaluate(() => new Set([...document.querySelectorAll('.now-grid [data-zoom]')].map((x) => x.dataset.zoom)).size);
  ok(n >= 4, 'v Právě teď ' + n);
  await pg.click(`.now-grid [data-zoom="${img.id}"]`);
  await pg.waitForSelector('#pvBody img');
  ok(await pg.$('#modalBox.full'), 'celá šířka');
  const w = await pg.evaluate(() => document.querySelector('#modalBox').getBoundingClientRect().width);
  ok(w > 1250, 'šířka okna ' + w);
  eq('plakat.jpg', await name());
  await pg.waitForFunction(() => document.querySelector('#pvBody img')?.complete);
  const [ih, vh] = await pg.evaluate(() => [document.querySelector('#pvBody img').getBoundingClientRect().height, innerHeight]);
  ok(ih > 500 && ih <= vh * 0.8, 'obrázek velký, ale celý vidět: ' + ih);
  if (TMP) await pg.screenshot({ path: `${TMP}/zvetseni-obrazek.png` });
  const p0 = +(await pos()).split(' / ')[0];
  const at = (k) => `${((p0 - 1 + k + n * 4) % n) + 1} / ${n}`;
  await pg.click('#pvNext'); eq(at(1), await pos());
  ok(!(await pg.$('#pvBody img[src*="plakat"]')) && (await name()) !== 'plakat.jpg', 'jiné médium');
  await pg.keyboard.press('ArrowRight'); eq(at(2), await pos());
  await pg.keyboard.press('ArrowLeft'); await pg.keyboard.press('ArrowLeft'); await pg.keyboard.press('ArrowLeft'); eq(at(-1), await pos(), 'dokola');
  await closeIt();
}, pg);

await test('Scénáře: klik na miniaturu v seznamu otevře náhled se všemi médii scénáře', async () => {
  await pg.goto(`${B}/#scenare`); await pg.reload();
  await pg.waitForSelector('.strip [data-zoom]');
  const ids = (await api(pg, 'scenarios')).find((s) => s.name === 'Zvětšení').media_ids;
  await pg.click(`.scen:has-text("Zvětšení") .strip [data-zoom="${b.id}"]`);
  await pg.waitForSelector('#pvBody');
  eq([`${ids.indexOf(b.id) + 1} / 4`, 'Dva.txt'], [await pos(), await name()]);
  await closeIt();
}, pg);

await test('editor scénáře: klik na miniaturu ve „Ve scénáři“ zvětší; lupa v galerii zvětší a kartu neoznačí', async () => {
  const sc = (await api(pg, 'scenarios')).find((s) => s.name === 'Zvětšení');
  await pg.evaluate((id) => editScenario(store.scenarios.find((s) => s.id === id)), sc.id);
  await pg.waitForSelector('#inList [data-zoom]');
  await pg.click(`#inList [data-zoom="${c.id}"]`);
  await pg.waitForSelector('#pvBody');
  eq('Tři.txt', await name());
  await closeIt();
  const d = await txt('Čtyři');
  await pg.evaluate(() => { store.media = []; });
  await pg.goto(`${B}/#scenare`); await pg.reload();
  await pg.waitForFunction(() => store.media.length >= 4);
  await pg.evaluate((id) => editScenario(store.scenarios.find((s) => s.id === id)), sc.id);
  await pg.waitForSelector(`#pGrid .card[data-id="${d.id}"] .zoom-btn`);
  await pg.hover(`#pGrid .card[data-id="${d.id}"]`);
  await pg.click(`#pGrid .card[data-id="${d.id}"] .zoom-btn`);
  await pg.waitForSelector('#pvBody');
  eq('Čtyři.txt', await name());
  ok(/\/ \d+$/.test(await pos()), 'listování v galerii výběru');
  await closeIt();
  ok(!(await pg.$(`#pGrid .card[data-id="${d.id}"].chosen`)), 'karta se neoznačila');
  ok(!(await pg.textContent('#inList')).includes('Čtyři'), 'nepřidalo se do scénáře');
}, pg);

await test('Galerie: náhled listuje mezi zobrazenými médii', async () => {
  await pg.goto(`${B}/#galerie`); await pg.reload();
  await pg.waitForSelector('#gGrid [data-prev]');
  const n = await pg.locator('#gGrid .card').count();
  await pg.click('#gGrid [data-prev] >> nth=1');
  await pg.waitForSelector('#pvBody');
  eq(`2 / ${n}`, await pos());
  await closeIt();
}, pg);

await test('telefon: tažení prstem do strany listuje, okno se vejde na šířku', async () => {
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.goto(`${B}/#prehled`); await pg.reload();
  await pg.waitForSelector('.now-grid [data-zoom]');
  await pg.click('.now-grid [data-zoom] >> nth=0');
  await pg.waitForSelector('#pvBody');
  const swipe = (dx) => pg.evaluate((dx) => {
    const el = document.querySelector('#pvBody');
    const t = (x) => new Touch({ identifier: 1, target: el, clientX: x, clientY: 300 });
    el.dispatchEvent(new TouchEvent('touchstart', { touches: [t(250)], bubbles: true }));
    el.dispatchEvent(new TouchEvent('touchend', { changedTouches: [t(250 + dx)], bubbles: true }));
  }, dx);
  const p0 = await pos();
  await swipe(-150); ok((await pos()) !== p0 && (await pos()).startsWith('2 / '), 'doleva = další: ' + await pos());
  await swipe(150); eq(p0, await pos(), 'doprava = předchozí');
  ok(await pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'bez vodorovného posunu');
  if (TMP) await pg.screenshot({ path: `${TMP}/zvetseni-mobil.png` });
  await closeIt();
  await pg.setViewportSize({ width: 1400, height: 1000 });
}, pg);

await done(br);
