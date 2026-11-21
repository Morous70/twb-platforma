// Grafický text v editoru slidu: výběr stylu, doladění, rozestup písmen, uložení; náhled v editoru proti obrázku ze serveru;
// Nastavení → Obrázky → Písma pro slidy (nahrání, nabídka v editoru, ochrana použitého písma).
import { B, TMP, browser, login, api, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);

const openNewSlide = async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#newSlide');
  await pg.click('#newSlide');
  await pg.waitForSelector('#seName');
  await pg.waitForTimeout(400);
};
// klik na první text (výchozí slide má text „Nový slide“)
const selectFirstText = async () => {
  await pg.click('#layerList .layer[data-id] .nm');
  await pg.waitForSelector('#pFx');
};
/** Průměrný rozdíl barev (0–255) mezi náhledem v editoru a obrázkem ze serveru v oblasti textu. */
const compare = () => pg.evaluate(async () => {
  const cv = document.getElementById('seCanvas');
  const dz = JSON.parse(JSON.stringify(window.__lastDesign || {}));
  const img = await new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = window.__srvImg; });
  const W = 384, H = 216, a = document.createElement('canvas'), b = document.createElement('canvas');
  a.width = b.width = W; a.height = b.height = H;
  a.getContext('2d').drawImage(cv, 0, 0, W, H); b.getContext('2d').drawImage(img, 0, 0, W, H);
  const A = a.getContext('2d').getImageData(0, 0, W, H).data, Bd = b.getContext('2d').getImageData(0, 0, W, H).data;
  let sum = 0, n = 0;
  for (let y = 60; y < 160; y++) for (let x = 20; x < 364; x++) { const i = (y * W + x) * 4; sum += (Math.abs(A[i] - Bd[i]) + Math.abs(A[i + 1] - Bd[i + 1]) + Math.abs(A[i + 2] - Bd[i + 2])) / 3; n++; }
  return sum / n;
});

await test('editor: Grafický styl Zlato, doladění, rozestup písmen, uložení', async () => {
  await openNewSlide();
  await selectFirstText();
  ok(await pg.isVisible('#pColor'), 'bez stylu je pole Barva');
  eq(12, await pg.$$eval('#pFx [data-fx]', (b) => b.length), 'Žádný + 11 stylů');
  await pg.click('#pFx [data-fx="zlato"]');
  await pg.waitForSelector('#fxTune');
  ok(!(await pg.$('#pColor')) && !(await pg.$('#pShOn')), 'se stylem se Barva, Stín a Obrys schovají');
  ok((await pg.innerText('#fxTune summary')).includes('Doladit styl Zlato'), 'doladit');
  if (TMP) await (await pg.$('#props')).screenshot({ path: `${TMP}/fx-panel.png` });
  await pg.click('#fxTune summary');
  await pg.fill('#pText', '137 000 000 Kč');
  await pg.selectOption('#pFont', 'titan');
  await pg.waitForSelector('#pSpacing');
  await pg.selectOption('#pSpacing', '-0.04');
  await pg.evaluate(() => { const r = document.getElementById('fxR_depth'); r.value = '0.12'; r.dispatchEvent(new Event('input')); });
  await pg.fill('#seName', 'Zlatý jackpot');
  await pg.click('#seSave');
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'Zlatý jackpot.jpg'));
  const m = (await api(pg, 'media')).find((x) => x.original_name === 'Zlatý jackpot.jpg');
  const e = m.design.elements.find((x) => x.type === 'text');
  eq(['zlato', 'titan', -0.04, 0.12], [e.fx.style, e.font, e.spacing, e.fx.depth]);
  eq('#fffbd0', e.fx.grad[0]);
  await pg.click('#fxReset');
  await pg.waitForTimeout(200);
  eq('0.085', await pg.inputValue('#fxR_depth'), 'Vrátit styl');
  if (TMP) await pg.screenshot({ path: `${TMP}/fx-editor.png` });
}, pg);

await test('náhled v editoru odpovídá obrázku ze serveru (Zlato, Tmavě modrá ☀, bez stylu)', async () => {
  const fx = await api(pg, 'fx_styles');
  const diffs = {};
  for (const [style, bg] of [['zlato', '#1d1c1a'], ['modra_l', '#f4f0e6'], ['', '#1d1c1a']]) {
    const st = style ? { style, ...fx.styles[style] } : null;
    const design = { bg: { type: 'color', color: bg }, elements: [{ type: 'text', id: 't1', x: 160, y: 340, w: 1600, h: 400, text: '137 000 000 Kč', font: 'titan', weight: 900,
      size: 170, color: '#ffffff', align: 'center', valign: 'middle', autoFit: true, spacing: -0.04, fx: st }] };
    const r = await api(pg, 'slide_save', { name: `Porovnání ${style || 'bez'}`, design });
    await pg.evaluate((m) => { main.innerHTML = ''; editSlide(m); }, r.media);
    await pg.waitForSelector('#seCanvas');
    await pg.waitForTimeout(1500);   // písma a rozvržení ze serveru
    await pg.evaluate(async (d) => { window.__srvImg = (await api('slide_preview', { design: d })).image; }, r.media.design);
    diffs[style || 'žádný'] = Math.round(await compare() * 10) / 10;
    if (TMP && style === 'zlato') {
      await pg.evaluate(() => { const c = document.createElement('canvas'); c.width = 960; c.height = 540; c.getContext('2d').drawImage(document.getElementById('seCanvas'), 0, 0, 960, 540); window.__ed = c.toDataURL('image/png'); });
      const fs = await import('node:fs');
      fs.writeFileSync(`${TMP}/fx-editor-zlato.png`, Buffer.from((await pg.evaluate(() => window.__ed)).split(',')[1], 'base64'));
      fs.writeFileSync(`${TMP}/fx-server-zlato.jpg`, Buffer.from((await pg.evaluate(() => window.__srvImg)).split(',')[1], 'base64'));
    }
  }
  console.log('      rozdíl editor × server: ' + JSON.stringify(diffs));
  ok(Object.values(diffs).every((v) => v < 14), 'průměrný rozdíl (0–255): ' + JSON.stringify(diffs));
}, pg);

await test('Nastavení → Obrázky: nahrání písma, náhled, nabídka v editoru, použité nejde odebrat', async () => {
  await pg.goto(`${B}/#nastaveni/obrazky`);
  await pg.waitForSelector('#fontUp');
  ok((await pg.innerText('#fontsBox')).includes('Titan One'), 'vestavěná písma');
  await pg.setInputFiles('#fontFile', { name: 'Jen ASCII.ttf', mimeType: 'font/ttf', buffer: (await import('node:fs')).readFileSync(`${TMP}/app/tests/fixtures/ascii-jen.ttf`) });
  await pg.waitForFunction(() => document.getElementById('fontsBox').innerText.includes('Jen ASCII'));
  ok((await pg.innerText('#toasts')).includes('chybí v něm české znaky'), await pg.innerText('#toasts'));
  if (TMP) await pg.screenshot({ path: `${TMP}/fx-pisma.png`, fullPage: true });
  const key = await pg.$eval('#fontsBox [data-fdel]', (b) => b.dataset.fdel);
  // použít ve slidu
  const m = (await api(pg, 'media')).find((x) => x.original_name === 'Zlatý jackpot.jpg');
  const design = m.design; design.elements.find((x) => x.type === 'text').font = key;
  const saved = await api(pg, 'slide_save', { id: m.id, name: 'Zlatý jackpot', design });
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#newSlide');
  await pg.goto(`${B}/#nastaveni/obrazky`);
  await pg.waitForSelector(`[data-fdel="${key}"]`);
  ok((await pg.innerText(`[data-fk="${key}"]`)).includes('ve 1 slidu'), 'počet použití: ' + await pg.innerText(`[data-fk="${key}"]`));
  await pg.click(`[data-fdel="${key}"]`);
  await pg.waitForFunction(() => document.getElementById('toasts').innerText.includes('použité ve slidu'));
  // editor nabízí nové písmo
  await pg.evaluate((id) => { main.innerHTML = ''; editSlide(store.media.find((x) => x.id === id) || null); }, m.id);
  await pg.waitForSelector('#seName');
  ok(await pg.evaluate(async (k) => (await api('fonts')).some((f) => f.key === k), key), 'v nabídce');
}, pg);

await done(br);
