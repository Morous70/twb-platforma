// Počasí (1.20.0): Nastavení → Počasí (klíč, výchozí místo, zkouška), prvek Počasí v editoru slidu (3 vzhledy, místo),
// proměnné {Počasí:…}; náhled v editoru odpovídá obrázku ze serveru. OpenWeather nahrazuje tests/mock/openweather.php.
import { B, TMP, browser, login, api, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);

const settings = async () => {
  await pg.goto(`${B}/#nastaveni/pocasi`); await pg.reload();
  await pg.waitForSelector('#wSave');
};

await test('Nastavení → Počasí: chybný klíč ukáže srozumitelnou chybu', async () => {
  await settings();
  ok((await pg.innerText('#setTabs')).includes('Počasí'), 'karta Počasí');
  // pravidlo: nová karta nesmí schovat stávající (dřív se poslední karta Systém odsunula mimo viditelnou část)
  for (const vw of [1400, 1100, 390]) {
    await pg.setViewportSize({ width: vw, height: 1000 });
    const hidden = await pg.$$eval('#setTabs [data-tab]', (bs) => bs.filter((b) => { const r = b.getBoundingClientRect(), p = b.parentElement.getBoundingClientRect(); return r.right > p.right + 1 || r.right > innerWidth || r.width < 20; }).map((b) => b.textContent));
    eq([], hidden, `skryté karty při šířce ${vw}`);
  }
  await pg.setViewportSize({ width: 1400, height: 1000 });
  eq(10, await pg.$$eval('#setTabs [data-tab]', (b) => b.length), 'všech 10 karet');
  eq('OpenWeather', await pg.$eval('#wProv', (s) => s.selectedOptions[0].textContent));
  await pg.fill('#wKey', 'spatny-klic-123');
  await pg.fill('#wPlace', 'Praha');
  await pg.click('#wTest');
  await pg.waitForSelector('#wResult .errbox');
  ok((await pg.innerText('#wResult')).includes('nepřijal API klíč'), await pg.innerText('#wResult'));
}, pg);

await test('Nastavení → Počasí: správný klíč, Uložit a vyzkoušet ukáže počasí a místo v tabulce', async () => {
  await settings();
  ok((await pg.innerText('#setBody')).includes('uložen – vyplňte jen pro změnu'), 'klíč je uložený');
  await pg.fill('#wKey', 'test-klic-123');
  await pg.fill('#wPlace', 'Praha');
  await pg.click('#wTest');
  await pg.waitForSelector('#wResult .w-test');
  const t = await pg.innerText('#wResult');
  ok(t.includes('12 °C') && t.includes('Zataženo') && t.includes('vlhkost 71 %') && t.includes('Zítra:'), t);
  ok(await pg.$eval('#wResult img', (i) => i.complete && i.naturalWidth > 0), 'ikona se načetla');
  await pg.waitForFunction(() => /Praha[\s\S]*v pořádku/.test(document.querySelector('#wPlaces')?.innerText || ''));
  if (TMP) await pg.screenshot({ path: `${TMP}/pocasi-nastaveni.png` });
  const w = await api(pg, 'weather');
  ok(w.key_set && w.place === 'Praha', JSON.stringify(w));
}, pg);

let slideId;
await test('editor: + Počasí přidá prvek s počasím pro výchozí místo; tlačítka přidání zůstala všechna', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#newSlide');
  await pg.click('#newSlide');
  await pg.waitForSelector('#addWeather');
  // pravidlo: nové tlačítko nesmí odsunout ani schovat stávající
  const boxes = await pg.$$eval('.se-layers .row:first-child button', (bs) => bs.map((b) => { const r = b.getBoundingClientRect(), p = b.closest('.panel').getBoundingClientRect(); return [b.id, r.width > 20 && r.right <= p.right + 1 && r.left >= p.left - 1]; }));
  eq(['addText', 'addImg', 'addRect', 'addBalls', 'addWeather'], boxes.map((b) => b[0]));
  ok(boxes.every((b) => b[1]), 'všechna tlačítka viditelná uvnitř panelu: ' + JSON.stringify(boxes));
  await pg.click('#addWeather');
  await pg.waitForSelector('#pLook');
  await pg.waitForFunction(() => /Počasí pro Praha/.test(document.querySelector('#wState')?.innerText || ''));
  ok((await pg.innerText('#layerList')).includes('Počasí Praha'), 'vrstva');
  eq(['Ikona + teplota', 'Karta s detaily', 'Předpověď na 3 dny'], await pg.$$eval('#pLook [data-look] small', (s) => s.map((x) => x.textContent)));
  ok(await pg.$eval('#pLook [data-look="mini"]', (b) => b.classList.contains('on')), 'výchozí vzhled');
  if (TMP) await pg.screenshot({ path: `${TMP}/pocasi-editor-mini.png` });
  if (TMP) await (await pg.$('#props')).screenshot({ path: `${TMP}/pocasi-props.png` });
}, pg);

/** Průměrný rozdíl barev mezi plátnem editoru a obrázkem ze serveru (0–255). */
const diffWithServer = () => pg.evaluate(async () => {
  const d = JSON.parse(JSON.stringify(window.__wDesign));
  const src = (await api('slide_preview', { design: d })).image;
  const img = await new Promise((res) => { const i = new Image(); i.onload = () => res(i); i.src = src; });
  const cv = document.getElementById('seCanvas');
  const W = 480, H = 270, a = document.createElement('canvas'), b = document.createElement('canvas');
  a.width = b.width = W; a.height = b.height = H;
  a.getContext('2d').drawImage(cv, 0, 0, W, H); b.getContext('2d').drawImage(img, 0, 0, W, H);
  const A = a.getContext('2d').getImageData(0, 0, W, H).data, Bd = b.getContext('2d').getImageData(0, 0, W, H).data;
  let sum = 0;
  for (let i = 0; i < A.length; i += 4) sum += (Math.abs(A[i] - Bd[i]) + Math.abs(A[i + 1] - Bd[i + 1]) + Math.abs(A[i + 2] - Bd[i + 2])) / 3;
  return sum / (W * H);
});

await test('editor: vzhled Předpověď na 3 dny, jiné místo, uložení; náhled odpovídá serveru', async () => {
  await pg.click('#pLook [data-look="days"]');
  eq('1200', await pg.inputValue('#pW'), 'šířka podle vzhledu');
  ok(await pg.isChecked('#pBgOn'), 'podklad zapnutý');
  await pg.fill('#pPlace', 'Brno');
  await pg.press('#pPlace', 'Enter');
  await pg.waitForFunction(() => /Počasí pro Brno/.test(document.querySelector('#wState')?.innerText || ''));
  ok((await pg.innerText('#layerList')).includes('Počasí Brno'), 'vrstva s místem');
  await pg.fill('#seName', 'Počasí test');
  await pg.click('#seSave');
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'Počasí test.jpg'));
  const m = (await api(pg, 'media')).filter((x) => x.original_name === 'Počasí test.jpg').sort((a, b) => b.id - a.id)[0];
  slideId = m.id;
  const el = m.design.elements.find((e) => e.type === 'weather');
  ok(el && el.look === 'days' && el.place === 'Brno' && el.bg.on, JSON.stringify(el));
  await pg.evaluate((d) => { window.__wDesign = d; }, m.design);
  await pg.waitForTimeout(800); // ikony v editoru
  const diff = await diffWithServer();
  ok(diff < 6, `náhled v editoru se liší od serveru o ${diff.toFixed(2)}`);
  if (TMP) await pg.screenshot({ path: `${TMP}/pocasi-editor-dny.png` });
}, pg);

await test('editor: neznámé místo ukáže ukázku a důvod; po opravě zase skutečná data', async () => {
  await pg.fill('#pPlace', 'Neznámo');
  await pg.press('#pPlace', 'Enter');
  await pg.waitForFunction(() => /nezná/.test(document.querySelector('#wState')?.innerText || ''));
  const t = await pg.innerText('#wState');
  ok(t.includes('ukázka') && t.includes('Nastavení → Počasí'), t);
  ok(await pg.$eval('#wState', (x) => x.classList.contains('warnbox')), 'upozornění');
  await pg.fill('#pPlace', '');
  await pg.press('#pPlace', 'Enter');
  await pg.waitForFunction(() => /Počasí pro Praha/.test(document.querySelector('#wState')?.innerText || ''));
}, pg);

await test('proměnné: nabídka Počasí v + Vložit proměnnou…, dosazení bez neznámých proměnných', async () => {
  await pg.click('#addText');
  await pg.waitForSelector('#pVar');
  ok(await pg.$('#pVar optgroup[label="Počasí (Praha)"]'), 'skupina Počasí');
  await pg.fill('#pText', 'Venku je ');
  await pg.selectOption('#pVar', '{Počasí:teplota}');
  await pg.type('#pText', ' °C, zítra {Počasí:zítra.max}°');
  await pg.waitForTimeout(700);
  ok(await pg.$eval('#unk', (x) => x.hidden), 'žádná neznámá proměnná: ' + await pg.innerText('#unk'));
  const r = await pg.evaluate(async () => {
    const d = { elements: [{ id: 'x', type: 'text', text: 'Venku je {Počasí:teplota} °C, {Počasí:Brno.popis}', x: 0, y: 0, w: 900, h: 100 }] };
    return (await api('resolve', { design: d })).x.text;
  });
  eq('Venku je 12 °C, Zataženo', r);
  await pg.click('#pHelp');
  ok((await pg.innerText('#modalBox')).includes('{Počasí:zítra.max}'), 'přehled proměnných');
  await pg.click('#modalBox [data-close]');
}, pg);

await test('uložený slide: obrázek na serveru, místo Brno se stahuje', async () => {
  const w = await api(pg, 'weather');
  const brno = w.places.find((p) => p.place === 'Brno');
  ok(brno && brno.used && !brno.error, JSON.stringify(w.places));
  const m = (await api(pg, 'media')).find((x) => x.id === slideId);
  ok(m.url && m.size > 10000, 'vykreslený slide ' + JSON.stringify([m.url, m.size]));
  await pg.click('#seBack');
}, pg);

await done(br);
