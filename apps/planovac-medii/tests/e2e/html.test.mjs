// HTML dokumenty v galerii (1.16.0): editor Vizuálně / Kód, vložení hodnoty a obrázku, náhled se skriptem, nahrání souboru.
import { writeFileSync } from 'node:fs';
import { B, TMP, browser, login, api, toasts, test, eq, ok, done } from './helpers.mjs';
const { br, pg } = await browser();
await login(pg);
const media = () => api(pg, 'media');
const byName = async (n) => (await media()).find((m) => m.original_name === n);
const fileText = (url) => pg.evaluate(async (u) => { const r = await fetch(u); return { text: await r.text(), csp: r.headers.get('content-security-policy'), type: r.headers.get('content-type') }; }, url);
/** Uložení v editoru: čeká na hlášku právě tohoto uložení (staré hlášky se předem smažou). */
const save = async () => {
  await pg.evaluate(() => { document.querySelector('#toasts').innerHTML = ''; });
  await pg.click('#heSave');
  await pg.waitForFunction(() => /uložen/.test(document.querySelector('#toasts')?.innerText || ''));
};
const frameOf = async (sel) => { const h = await pg.waitForSelector(sel); return h.contentFrame(); };

await test('nový dokument: dlaždice HTML otevře vizuální editor s plochou 1920×1080', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#newHtml');
  await pg.click('#newHtml');
  await pg.waitForSelector('#heVisual .sun-editor');
  await pg.waitForFunction(() => document.querySelector('#heVisual iframe')?.contentDocument?.body?.innerText.includes('Nadpis'));
  const f = await frameOf('#heVisual iframe');
  const w = await f.evaluate(() => [document.body.offsetWidth, parseFloat(document.documentElement.style.zoom), getComputedStyle(document.body).backgroundColor]);
  eq(1920, w[0], 'šířka plochy');
  ok(w[1] > 0.2 && w[1] < 1, 'zmenšení ' + w[1]);
  eq('rgb(29, 28, 26)', w[2], 'tmavé pozadí z dokumentu');
  if (TMP) await pg.screenshot({ path: `${TMP}/html-editor.png` });
}, pg);

let docId;
await test('vizuálně: psaní, vložení hodnoty {den}, uložení – soubor má dosazený den a vložené písmo', async () => {
  const f = await frameOf('#heVisual iframe');
  await f.click('h1');
  await pg.keyboard.press('End');
  await pg.keyboard.type(' – otevírací doba');
  await pg.selectOption('#heVar', '{den}');
  await pg.fill('#heName', 'oteviraci-doba-vanoce');
  await save();
  const m = await byName('oteviraci-doba-vanoce.html');
  ok(m && m.kind === 'html', 'v galerii: ' + JSON.stringify(m));
  docId = m.id;
  const { source } = await api(pg, 'html_get', null, `&id=${m.id}`);
  ok(source.includes('Nadpis – otevírací doba') && source.includes('{den}'), source.slice(0, 600));
  ok(source.includes('<style>') && source.includes("font-family: 'Montserrat'"), 'hlavička zůstala');
  const r = await fileText(m.url);
  const day = await pg.evaluate(() => new Date().toLocaleDateString('cs-CZ', { weekday: 'long' }));
  ok(r.text.includes(day) && !r.text.includes('{den}'), 'dosazený den ' + day);
  ok(/@font-face \{ font-family: 'Montserrat'; font-weight: 700;[^}]*base64/.test(r.text), 'písmo v dokumentu');
  eq('sandbox allow-scripts', r.csp, 'izolace');
  ok(/^text\/html/.test(r.type), r.type);
}, pg);

await test('kód: JavaScript se uloží, v náhledu se spustí izolovaně a vizuální úprava ho nezahodí', async () => {
  await pg.click('#heModeC');
  const code = await pg.inputValue('#heCode');
  ok(code.includes('otevírací doba'), 'kód obsahuje text z vizuálního režimu');
  await pg.fill('#heCode', code.replace('</body>', '<p id="js">čeká</p>\n<script>var x; try { x = typeof parent.store; } catch (e) { x = "bez přístupu"; } document.getElementById("js").textContent = "skript běží " + x;</script>\n</body>'));
  await save();
  await pg.click('#hePreview');
  const f = await frameOf('#hePrevBox iframe');
  await f.waitForFunction(() => document.getElementById('js')?.textContent.startsWith('skript běží'));
  eq('skript běží bez přístupu', await f.evaluate(() => document.getElementById('js').textContent), 'skript bez přístupu k aplikaci');
  if (TMP) await pg.screenshot({ path: `${TMP}/html-nahled.png` });
  await pg.click('#modalBox [data-close]');
  // zpět do vizuálního režimu, úprava textu, uložení – skript zůstane
  await pg.click('#heModeV');
  ok((await pg.innerText('#heWarn')).includes('JavaScript'), 'upozornění na skript');
  const fv = await frameOf('#heVisual iframe');
  await fv.click('h1');
  await pg.keyboard.press('End');
  await pg.keyboard.type('!');
  await fv.waitForFunction(() => document.querySelector('h1').textContent.endsWith('!'), null, { timeout: 5000 }).catch(async () => { throw new Error('v editoru: ' + await fv.evaluate(() => document.body.innerHTML.slice(0, 300) + ' | aktivní: ' + document.activeElement?.tagName + ' | ' + parent.document.activeElement?.id)); });
  await save();
  const { source } = await api(pg, 'html_get', null, `&id=${docId}`);
  ok(source.includes('"skript běží " + x;</script>') && source.includes('doba{den}!'), source.slice(-700));
}, pg);

await test('znovu otevřený dokument se skriptem se otevře v režimu Kód; v galerii filtr HTML, náhled v okně', async () => {
  await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector(`[data-edit="${docId}"]`);
  await pg.click('#gTypes [data-t="html"]');
  eq(1, await pg.locator('#gGrid .card').count(), 'jen HTML');
  ok((await pg.innerText('#gGrid')).includes('oteviraci-doba-vanoce'), 'karta');
  await pg.click(`#gGrid [data-prev="${docId}"]`);
  const f = await frameOf('#modalBox .html-frame iframe');
  await f.waitForFunction(() => document.getElementById('js')?.textContent.startsWith('skript běží'));
  await pg.click('#modalBox [data-close]');
  await pg.click(`[data-edit="${docId}"]`);
  await pg.waitForSelector('#heCode:not([hidden])');
  ok((await pg.innerText('#heWarn')).includes('režimu Kód'), await pg.innerText('#heWarn'));
  await pg.click('#heBack');
}, pg);

await test('obrázek z galerie se vloží přímo do dokumentu (data:), externí adresa vyvolá upozornění', async () => {
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#fileIn', { state: 'attached' });
  await pg.setInputFiles('#fileIn', [`${TMP}/fixtures/logo.png`]);
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'logo.png'));
  await pg.click('#newHtml');
  await pg.waitForSelector('#heVisual .sun-editor');
  await pg.waitForFunction(() => document.querySelector('#heVisual iframe')?.contentDocument?.body?.innerText.includes('Text dokumentu'));
  const f = await frameOf('#heVisual iframe');
  await f.click('p');
  await pg.click('#heImg');
  await pg.click('#piGrid .card:has-text("logo.png")');
  await pg.waitForFunction(() => /vložen do dokumentu/.test(document.querySelector('#toasts')?.innerText || ''));
  await pg.fill('#heName', 'S obrázkem');
  await pg.click('#heModeC');
  await pg.fill('#heCode', (await pg.inputValue('#heCode')).replace('</body>', '<img src="https://example.com/logo.png">\n</body>'));
  await pg.click('#heSave');
  await pg.waitForSelector('#heWarn:not([hidden])');
  ok((await pg.innerText('#heWarn')).includes('example.com'), await pg.innerText('#heWarn'));
  const { source } = await api(pg, 'html_get', null, `&id=${(await byName('S obrázkem.html')).id}`);
  ok(/<img src="data:image\/png;base64,/.test(source), 'obrázek vložený jako data');
  await pg.click('#heBack');
}, pg);

await test('nahrání souboru .html do galerie a nahrazení obsahu v editoru', async () => {
  writeFileSync(`${TMP}/akce.html`, '<!DOCTYPE html><html><head><meta charset="utf-8"><title>A</title></head><body style="background:#123456;color:#fff"><h2>Akce {datum}</h2></body></html>');
  await pg.goto(`${B}/#galerie`); await pg.waitForSelector('#fileIn', { state: 'attached' });
  await pg.setInputFiles('#fileIn', [`${TMP}/akce.html`]);
  await pg.waitForFunction(() => store.media.some((x) => x.original_name === 'akce.html'));
  const m = await byName('akce.html');
  eq('html', m.kind);
  const r = await fileText(m.url);
  ok(!r.text.includes('{datum}') && /Akce \d{1,2}\. \d{1,2}\. \d{4}/.test(r.text), r.text.slice(0, 300));
  // nahrazení v editoru
  writeFileSync(`${TMP}/akce2.html`, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><h2>Nová akce</h2></body></html>');
  await pg.click(`[data-edit="${m.id}"]`);
  await pg.waitForSelector('#heVisual .sun-editor');
  await pg.setInputFiles('#heFile', [`${TMP}/akce2.html`]);
  await pg.waitForFunction(() => document.querySelector('#heVisual iframe')?.contentDocument?.body?.innerText.includes('Nová akce'));
  await save();
  const after = await byName('akce.html');
  eq(m.id, after.id, 'stejné médium');
  ok((await fileText(after.url)).text.includes('Nová akce'), 'nový obsah');
  await pg.click('#heBack');
}, pg);

await test('server odmítne prázdný dokument a dokument nad 5 MB; HTML jde do náhodného výběru', async () => {
  const e1 = await api(pg, 'html_save', { name: 'x', source: '<html><body>   </body></html>' }).then(() => null, (e) => e.message);
  ok(e1 && e1.includes('prázdný'), String(e1));
  const e2 = await api(pg, 'html_save', { name: 'x', source: '<p>' + 'a'.repeat(5 * 1024 * 1024) + '</p>' }).then(() => null, (e) => e.message);
  ok(e2 && e2.includes('5 MB'), String(e2));
  const other = (await byName('akce.html')).id;
  await api(pg, 'pool_save', { name: 'Náhodně s HTML', member_ids: [docId, other] });
  const pool = (await media()).find((x) => x.kind === 'random' && x.original_name.startsWith('Náhodně s HTML'));
  eq([docId, other].sort((a, b) => a - b), pool.members, 'členové výběru');
}, pg);

await test('na telefonu jde dokument otevřít a stránka nepřetéká do strany', async () => {
  await pg.setViewportSize({ width: 390, height: 844 });
  await pg.goto(`${B}/#galerie`); await pg.reload(); await pg.waitForSelector(`[data-edit="${docId}"]`);
  await pg.click(`[data-edit="${docId}"]`);
  await pg.waitForSelector('#heCode:not([hidden])');
  await pg.click('#heModeV');
  await pg.waitForSelector('#heVisual .sun-editor');
  await pg.waitForTimeout(500);
  ok(await pg.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'nepřetéká: ' + await pg.evaluate(() => document.documentElement.scrollWidth));
  if (TMP) await pg.screenshot({ path: `${TMP}/html-mobil.png` });
  await pg.setViewportSize({ width: 1400, height: 1000 });
  await pg.click('#heBack');
}, pg);

void toasts;
await done(br);
