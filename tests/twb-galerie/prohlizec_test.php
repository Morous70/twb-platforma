<?php
declare(strict_types=1);
// TWB Media galerie 0.5.1 ve skutečném prohlížeči (Playwright): tagy jako štítky, výběr médií tažením myši, nahrání s tagy;
// 0.6.2 zobrazení galerie (mřížka, seznam, jen média) a větší náhled v okně; 0.6.3 okno i v detailu a přehledu, nic v nové kartě.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\App;

echo "Media galerie: v prohlížeči\n";
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
for ($i = 1; $i <= 6; $i++) {
    $f = docasna('img');
    file_put_contents($f, jpegMeta());
    $v = $app->galerie()->uloz($f, "obrazek-$i.jpg", $i === 1 ? ['Léto'] : []);
    $app->media()->poNahrani($v['id'], null);
}

/** Spustí skript Playwright (tělo async funkce s proměnnými p = stránka, u = adresa, v = výsledky) a vrátí výsledky. */
function prohlizec(string $url, string $telo): array
{
    $skript = docasna('pw') . '.js';
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: 1360, height: 1600 } });
  const p = await ctx.newPage();
  const u = '$url/';
  const v = { chyby: [] };
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/favicon/.test(m.text())) v.chyby.push(m.text()); });
  await p.goto(u);
  await p.fill('input[name=heslo]', 'tajneheslo1');
  globalThis.krok = 'přihlášení';
  await Promise.all([p.waitForNavigation(), p.press('input[name=heslo]', 'Enter')]);
  globalThis.krok = 'po přihlášení ' + p.url();
  $telo
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e).slice(0, 200), krok: globalThis.krok || '' })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([], $v['chyby'] ?? [], 'chyby JavaScriptu');
    return $v;
}

test('tagy jako štítky: Enter, čárka, Tab, bez opakování, zápis podle existujícího tagu, křížek, Backspace', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec($s->url, <<<'JS'
  await p.goto(u + 'index.php?p=galerie');
  const t = '#f-nahrat_tagy-novy';
  v.skryte = await p.$eval('#f-nahrat_tagy', e => e.type);
  await p.fill(t, 'web'); await p.press(t, 'Enter');
  await p.type(t, 'kampaň,'); await p.type(t, 'LÉTO'); await p.press(t, 'Tab');
  await p.fill(t, 'WEB'); await p.press(t, 'Enter');
  v.po = await p.inputValue('#f-nahrat_tagy');
  v.stitky = await p.$$eval('.stitek-upravitelny', e => e.map(x => x.firstChild.textContent));
  await p.click('.stitek-zrusit >> nth=1');
  v.krizek = await p.inputValue('#f-nahrat_tagy');
  await p.click(t); await p.press(t, 'Backspace');
  v.backspace = await p.inputValue('#f-nahrat_tagy');
  await p.fill(t, 'x'.repeat(31)); await p.press(t, 'Enter');
  v.dlouhy = [await p.inputValue('#f-nahrat_tagy'), await p.textContent('.stitky-hlaska')];
JS);
    eq('hidden', $v['skryte']);
    eq('web, kampaň, Léto', $v['po'], 'LÉTO se zapíše jako existující Léto, WEB podruhé ne');
    eq(['web', 'kampaň', 'Léto'], $v['stitky']);
    eq('web, Léto', $v['krizek']);
    eq('web', $v['backspace']);
    eq('web', $v['dlouhy'][0]);
    ok(str_contains($v['dlouhy'][1], '30 znaků'));
});

test('výběr tažením myši z prázdného místa; Ctrl přidá; klik na název otevře detail; hromadná akce s výběrem', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec($s->url, <<<'JS'
  await p.goto(u + 'index.php?p=galerie');
  const ram = () => p.$$eval('.galerie figure', els => els.map(e => { const r = e.getBoundingClientRect(); return [r.left, r.top, r.width, r.height]; }));
  const vybrane = () => p.$$eval('input[name="ids[]"]', els => els.map(e => e.checked ? 1 : 0).join(''));
  let f = await ram();
  // od prázdného místa vlevo nad první kartou do středu šesté (2. řada, 2. sloupec při 4 sloupcích)
  await p.mouse.move(f[0][0] - 8, f[0][1] - 8); await p.mouse.down();
  await p.mouse.move(f[5][0] + f[5][2] / 2, f[5][1] + f[5][3] / 2, { steps: 12 });
  v.obdelnik = await p.$$eval('.vyber-obdelnik', e => e.length);
  await p.mouse.up();
  v.prvni = await vybrane();
  v.pocet = await p.textContent('[data-pocet-vybranych]');
  v.zustal = await p.$$eval('.vyber-obdelnik', e => e.length);
  v.adresa = p.url();
  // bez klávesy nový výběr nahradí starý
  f = await ram();
  await p.mouse.move(f[2][0] + f[2][2] / 2, f[2][1] - 10); await p.mouse.down(); await p.mouse.move(f[3][0] + 20, f[2][1] + 40, { steps: 6 }); await p.mouse.up();
  v.nahradil = await vybrane();
  // s Ctrl přidá
  await p.keyboard.down('Control');
  await p.mouse.move(f[0][0] + f[0][2] / 2, f[0][1] - 10); await p.mouse.down(); await p.mouse.move(f[0][0] + f[0][2] / 2 + 10, f[0][1] + 40, { steps: 6 }); await p.mouse.up();
  await p.keyboard.up('Control');
  v.pridal = await vybrane();
  // Esc během tažení vrátí výběr
  await p.mouse.move(f[0][0] - 8, f[0][1] - 8); await p.mouse.down(); await p.mouse.move(f[5][0] + 10, f[5][1] + 10, { steps: 6 });
  await p.keyboard.press('Escape'); await p.mouse.up();
  v.esc = await vybrane();
  // hromadná akce s vybranými
  await p.selectOption('[data-hromadna-akce]', 'tag-pridat');
  await p.fill('#hromadne input[name=tag]', 'vybrane');
  await Promise.all([p.waitForNavigation(), p.click('[data-hromadne-odeslat]')]);
  // klik na název otevře detail (klik na náhled otevře okno, viz další test)
  await Promise.all([p.waitForNavigation(), p.click('.galerie figure >> nth=1 >> figcaption a')]);
  v.detail = p.url();
JS);
    eq(1, $v['obdelnik'], 'obdélník při tažení');
    eq('110011', $v['prvni']);
    eq('Vybráno: 4', $v['pocet']);
    eq(0, $v['zustal'], 'obdélník po puštění zmizí');
    ok(str_ends_with($v['adresa'], 'p=galerie'), 'tažení neotevřelo detail');
    eq('001100', $v['nahradil']);
    eq('101100', $v['pridal']);
    eq('101100', $v['esc']);
    ok(str_contains($v['detail'], 'p=medium&id='), $v['detail']);
    $s2 = $s->db()->query("SELECT original_name FROM media WHERE tags LIKE '%vybrane%' ORDER BY original_name")->fetchAll(PDO::FETCH_COLUMN);
    eq(['obrazek-3.jpg', 'obrazek-4.jpg', 'obrazek-6.jpg'], $s2, 'tag jen u vybraných (mřížka je od nejnovějšího)');
});

test('0.6.2: přepínač zobrazení se pamatuje; seznam, jen média; výběr tažením i v nich', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec($s->url, <<<'JS'
  await p.goto(u + 'index.php?p=galerie');
  const trida = () => p.$eval('.galerie', e => e.className);
  v.vychozi = await trida();
  v.prepinac = await p.$$eval('.prepinac-zobrazeni a', e => e.map(x => x.textContent + (x.classList.contains('aktivni') ? '*' : '')));
  await Promise.all([p.waitForNavigation(), p.click('.prepinac-zobrazeni a >> text=Seznam')]);
  v.seznam = await trida();
  v.seznamRadek = await p.$$eval('.galerie figure', e => { const a = e[0].getBoundingClientRect(), b = e[1].getBoundingClientRect(); return [Math.round(a.left) === Math.round(b.left), b.top > a.top]; });
  v.seznamDatum = await p.$eval('.galerie figure .jen-seznam', e => getComputedStyle(e).display);
  await p.goto(u + 'index.php?p=galerie');
  v.zapamatovano = await trida();
  await Promise.all([p.waitForNavigation(), p.click('.prepinac-zobrazeni a >> text=Jen média')]);
  v.media = await trida();
  v.popisek = await p.$eval('.galerie figcaption', e => getComputedStyle(e).display);
  // tažení vybírá i bez popisků
  const f = await p.$$eval('.galerie figure', els => els.map(e => { const r = e.getBoundingClientRect(); return [r.left, r.top, r.width, r.height]; }));
  await p.mouse.move(f[0][0] - 4, f[0][1] - 4); await p.mouse.down();
  await p.mouse.move(f[1][0] + f[1][2] / 2, f[1][1] + f[1][3] / 2, { steps: 8 }); await p.mouse.up();
  v.vybrane = await p.$$eval('input[name="ids[]"]', els => els.map(e => e.checked ? 1 : 0).join(''));
  v.oknoPoTazeni = await p.$$eval('.okno-nahled:not([hidden])', e => e.length);
  await Promise.all([p.waitForNavigation(), p.click('.prepinac-zobrazeni a >> text=Mřížka')]);
  v.zpet = await trida();
JS);
    eq('galerie zobrazeni-mrizka', $v['vychozi']);
    eq(['Mřížka*', 'Seznam', 'Jen média'], $v['prepinac']);
    eq('galerie zobrazeni-seznam', $v['seznam']);
    eq([true, true], $v['seznamRadek'], 'v seznamu jsou položky pod sebou');
    eq('inline', $v['seznamDatum']);
    eq('galerie zobrazeni-seznam', $v['zapamatovano'], 'zobrazení se pamatuje');
    eq('galerie zobrazeni-media', $v['media']);
    eq('none', $v['popisek'], 'jen média: bez popisků');
    eq('110000', $v['vybrane']);
    eq(0, $v['oknoPoTazeni'], 'tažení neotevře okno');
    eq('galerie zobrazeni-mrizka', $v['zpet']);
    eq('mrizka', (string)$s->db()->query("SELECT v FROM settings WHERE k = 'galerie_zobrazeni'")->fetchColumn());
});

test('0.6.2: klik na náhled otevře větší obrázek v okně; šipky listují; Esc zavře; Ctrl+klik i prostřední klik detail ve stejné kartě, žádná nová karta', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec($s->url, <<<'JS'
  await p.goto(u + 'index.php?p=galerie');
  await p.click('.galerie figure >> nth=1 >> .nahled-medium');
  await p.waitForSelector('.okno-nahled:not([hidden]) img');
  const stav = () => p.evaluate(() => ({ src: document.querySelector('.okno-nahled img').getAttribute('src'),
    titulek: document.querySelector('[data-okno-titulek]').textContent, udaje: document.querySelector('[data-okno-udaje]').textContent }));
  v.otevreno = await stav();
  v.adresa = p.url();
  v.detail = await p.getAttribute('.okno-nahled [data-okno-na-detail]', 'href');
  v.original = await p.getAttribute('.okno-nahled [data-okno-na-stazeni]', 'href');
  v.detailTarget = await p.getAttribute('.okno-nahled [data-okno-na-detail]', 'target');
  v.obrazek = await p.evaluate(async src => (await fetch(src)).headers.get('content-type'), v.otevreno.src);
  await p.keyboard.press('ArrowRight'); v.dalsi = (await stav()).titulek;
  await p.keyboard.press('ArrowLeft'); await p.keyboard.press('ArrowLeft'); v.predchozi = (await stav()).titulek;
  await p.click('.okno-dalsi'); v.tlacitko = (await stav()).titulek;
  v.posun = await p.evaluate(() => getComputedStyle(document.body).overflow);
  await p.keyboard.press('Escape');
  v.zavreno = await p.$$eval('.okno-nahled[hidden]', e => e.length);
  v.vyber = await p.$$eval('input[name="ids[]"]:checked', e => e.length);
  await p.click('.galerie figure >> nth=0 >> .nahled-medium');
  await p.click('.okno-plocha', { position: { x: 5, y: 5 } });
  v.klikVedle = await p.$$eval('.okno-nahled[hidden]', e => e.length);
  let stranek = 0; p.context().on('page', () => stranek++);
  await Promise.all([p.waitForNavigation(), p.click('.galerie figure >> nth=0 >> .nahled-medium', { modifiers: ['Control'] })]);
  v.ctrl = p.url();
  await p.goto(u + 'index.php?p=galerie');
  await Promise.all([p.waitForNavigation(), p.click('.galerie figure >> nth=1 >> .nahled-medium', { button: 'middle' })]);
  v.prostredni = p.url();
  v.noveStranky = stranek;
JS);
    ok(str_contains($v['otevreno']['src'], 'nahled=1920'), $v['otevreno']['src']);
    eq('obrazek-5.jpg', $v['otevreno']['titulek'], 'mřížka je od nejnovějšího');
    ok(str_contains($v['otevreno']['udaje'], '2 / 6'), $v['otevreno']['udaje']);
    ok(str_ends_with($v['adresa'], 'p=galerie'), 'okno neopustí galerii');
    ok(str_contains($v['detail'], 'p=medium&id='));
    ok(str_contains($v['original'], 'stahnout=1') && !str_contains($v['original'], 'nahled='), $v['original']);
    eq(null, $v['detailTarget'], 'detail ve stejné kartě');
    eq('image/jpeg', $v['obrazek']);
    eq('obrazek-4.jpg', $v['dalsi']);
    eq('obrazek-6.jpg', $v['predchozi']);
    eq('obrazek-5.jpg', $v['tlacitko']);
    eq('hidden', $v['posun'], 'stránka pod oknem se neposouvá');
    eq(1, $v['zavreno']);
    eq(0, $v['vyber'], 'klik na náhled nic nevybere');
    eq(1, $v['klikVedle'], 'klik mimo obrázek okno zavře');
    ok(str_contains($v['ctrl'], 'p=medium&id=6'), 'Ctrl+klik: detail ve stejné kartě ' . $v['ctrl']);
    ok(str_contains($v['prostredni'], 'p=medium&id=5'), 'prostřední klik: detail ve stejné kartě ' . $v['prostredni']);
    eq(0, $v['noveStranky'], 'žádná nová karta');
});

test('0.6.3: v detailu a přehledu klik na obrázek otevře stejné okno, ne novou kartu', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $id = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'obrazek-2.jpg'")->fetchColumn();
    $v = prohlizec($s->url, <<<JS
  let stranek = 0; p.context().on('page', () => stranek++);
  await p.goto(u + 'index.php?p=medium&id=$id');
  v.target = await p.\$\$eval('.nahled-detail a', e => e.map(x => x.target));
  await p.click('.nahled-detail img');
  await p.waitForSelector('.okno-nahled:not([hidden]) img');
  v.detail = await p.evaluate(() => ({ src: document.querySelector('.okno-nahled img').getAttribute('src'), titulek: document.querySelector('[data-okno-titulek]').textContent,
    naDetail: document.querySelector('[data-okno-na-detail]').hidden, sipky: document.querySelector('.okno-dalsi').hidden }));
  v.adresa = p.url();
  await p.keyboard.press('Escape');
  await p.click('.nahled-detail img', { modifiers: ['Control'] });   // v detailu už detail je: okno
  v.ctrl = await p.\$\$eval('.okno-nahled:not([hidden])', e => e.length);
  await p.keyboard.press('Escape');
  await p.goto(u + 'index.php?p=prehled');
  await p.click('.galerie-mala figure >> nth=0 >> .nahled-medium');
  v.prehled = await p.\$\$eval('.okno-nahled:not([hidden]) img', e => e.length);
  v.prehledAdresa = p.url();
  v.stranek = stranek;
JS);
    eq(['', ], array_slice($v['target'], 0, 1), 'odkaz obrázku bez target=_blank');
    ok(str_contains($v['detail']['src'], "id=$id"), $v['detail']['src']);
    eq('obrazek-2.jpg', $v['detail']['titulek']);
    eq(true, $v['detail']['naDetail'], 'v detailu tlačítko Detail chybí');
    eq(true, $v['detail']['sipky'], 'jedno médium: bez šipek');
    ok(str_contains($v['adresa'], 'p=medium'), $v['adresa']);
    eq(1, $v['ctrl']);
    eq(1, $v['prehled'], 'přehled: okno');
    ok(str_contains($v['prehledAdresa'], 'p=prehled'), $v['prehledAdresa']);
    eq(0, $v['stranek'], 'žádná nová karta');
});

test('detail: štítky se uloží, rozepsaný tag se při uložení přidá', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $id = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'obrazek-1.jpg'")->fetchColumn();
    $v = prohlizec($s->url, <<<JS
  await p.goto(u + 'index.php?p=medium&id=$id');
  v.pred = await p.\$\$eval('.stitek-upravitelny', e => e.map(x => x.firstChild.textContent));
  await p.fill('#f-tagy-novy', 'hory'); await p.press('#f-tagy-novy', 'Enter');
  await p.fill('#f-tagy-novy', 'rozepsaný');
  await Promise.all([p.waitForNavigation(), p.click('form.formular button.hlavni')]);
  v.po = await p.inputValue('#f-tagy');
JS);
    eq(['Léto'], $v['pred']);
    eq('Léto, hory, rozepsaný', $v['po']);
    eq('["Léto","hory","rozepsaný"]', $s->db()->query("SELECT tags FROM media WHERE id = $id")->fetchColumn());
});

test('Nastavení v kartách (0.8.1): jedna karta vidět, přepnutí, adresa, kotva, šipky, návrat po uložení, mobil', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec($s->url, <<<'JS'
  const vidim = () => p.$$eval('.panel-karty', e => e.filter(x => !x.hidden).map(x => x.id));
  await p.goto(u + 'index.php?p=nastaveni');
  v.vychozi = await vidim();
  v.zalozky = await p.$$eval('.karty-nav [role=tab]', e => e.map(x => x.textContent.trim()));
  await p.click('#karta-zalohy');
  v.poKliku = await vidim();
  v.adresa = p.url();
  v.vybrana = await p.$$eval('[aria-selected=true]', e => e.map(x => x.id));
  await p.reload();
  v.poObnoveni = await vidim();
  await p.focus('#karta-zalohy'); await p.keyboard.press('ArrowRight');
  v.sipka = await vidim();
  await p.goto(u + 'index.php?p=nastaveni#obnova');
  v.kotvaUvnitr = await vidim();
  await p.goto(u + 'index.php?p=nastaveni#system');
  v.kotvaKarty = await vidim();
  await p.click('#karta-komprese');
  await p.fill('input[name=img_quality]', '77');
  await Promise.all([p.waitForNavigation(), p.click('#komprese button[type=submit]')]);
  v.poUlozeni = [await vidim(), p.url(), await p.textContent('.hlaska-ok')];
  await p.click('#karta-zabezpeceni');
  await p.fill('input[name=idle_minutes]', '3');
  await p.$eval('input[name=idle_minutes]', e => e.removeAttribute('min'));
  await Promise.all([p.waitForNavigation(), p.click('#zabezpeceni form:has(input[name=idle_minutes]) button')]);
  v.poChybe = await vidim();
  await p.setViewportSize({ width: 375, height: 800 });
  await p.goto(u + 'index.php?p=nastaveni&karta=zalohy');
  v.mobil = await p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
JS);
    eq(['zabezpeceni'], $v['vychozi']);
    eq(['Zabezpečení', 'Komprese obrázků', 'Zálohy', 'Aktualizace', 'Systém'], $v['zalozky']);
    eq(['zalohy'], $v['poKliku']);
    ok(str_contains($v['adresa'], 'karta=zalohy#zalohy'), $v['adresa']);
    eq(['karta-zalohy'], $v['vybrana']);
    eq(['zalohy'], $v['poObnoveni'], 'obnovení stránky zůstane na kartě');
    eq(['aktualizace'], $v['sipka']);
    eq(['zalohy'], $v['kotvaUvnitr'], '#obnova otevře kartu Zálohy');
    eq(['system'], $v['kotvaKarty']);
    eq(['komprese'], $v['poUlozeni'][0], 'po uložení zpět na kartě formuláře');
    ok(str_contains($v['poUlozeni'][1], 'karta=komprese#komprese'), $v['poUlozeni'][1]);
    ok(str_contains($v['poUlozeni'][2], 'Uloženo'));
    eq(['zabezpeceni'], $v['poChybe'], 'chyba formuláře: zůstane jeho karta');
    eq($v['mobil'][1], $v['mobil'][0], 'na mobilu bez vodorovného posuvu stránky');
});
