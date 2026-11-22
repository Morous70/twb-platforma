<?php
declare(strict_types=1);
// TWB Media galerie 0.5.1 ve skutečném prohlížeči (Playwright): tagy jako štítky, výběr médií tažením myši, nahrání s tagy.
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

test('výběr tažením myši z prázdného místa; Ctrl přidá; klik bez tažení otevře detail; hromadná akce s výběrem', function () use ($pw, $s) {
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
  // obyčejný klik na náhled otevře detail
  await Promise.all([p.waitForNavigation(), p.click('.galerie figure >> nth=1 >> .nahled-medium')]);
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
