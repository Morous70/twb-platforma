<?php
declare(strict_types=1);
// TWB Media galerie 0.8.8: pořadí kategorií přetažením, stránka Štítky, Naposledy nahrané 8 × 2, Základ a texty ve skupinách,
// reklama jen Pod úvodem a Před patičkou. Bez serveru, na serveru a ve skutečném prohlížeči (Playwright, když je k dispozici).
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Chyba, Media};

echo "Media galerie: 0.8.8\n";

// ---------- bez serveru ----------

test('kategorie: bez nastaveného pořadí podle názvu, nastavPoradi platí v seznamu, ve výběru i na webu', function () {
    $app = novaApp();
    $k = $app->kategorie();
    $c = $k->uloz(['name' => 'Cesty']);
    $a = $k->uloz(['name' => 'Auta']);
    $b = $k->uloz(['name' => 'Budovy']);
    $jmena = fn() => array_column($k->seznam(), 'name');
    eq(['Auta', 'Budovy', 'Cesty'], $jmena(), 'dokud nikdo pořadí nezměnil, česky podle názvu');
    $k->nastavPoradi([$c, $a, $b]);
    eq(['Cesty', 'Auta', 'Budovy'], $jmena());
    eq([$c, $a, $b], array_keys($k->volby()), 'výběr kategorií ve stejném pořadí');
    // nová kategorie po změně pořadí na konec; chybějící v požadavku se zařadí za uvedené; neznámé id a opakování se ignorují
    $d = $k->uloz(['name' => 'Akvária']);
    eq(['Cesty', 'Auta', 'Budovy', 'Akvária'], $jmena());
    $k->nastavPoradi([$b, 999, $b, $d]);
    eq(['Budovy', 'Akvária', 'Cesty', 'Auta'], $jmena());
    throws(fn() => $k->nastavPoradi([999]), 'pořadí');
    // web: veřejné kategorie ve stejném pořadí; formulář kategorie pořadí nemění
    $m = $app->media();
    foreach ([$a, $b, $c] as $i => $id) {
        $f = docasna('f'); file_put_contents($f, jpegMeta()); $m->nahraj($f, "f$i.jpg", [], $id);
        $app->web()->ulozKategorii($id, ['web_public' => '1', 'web_order' => '-500']);
    }
    eq(['Budovy', 'Cesty', 'Auta'], array_column($app->web()->kategorie(), 'name'));
});

test('štítky: seznam s počty (archiv zvlášť), hledání bez diakritiky, přejmenování, sloučení, smazání', function () {
    $app = novaApp();
    $g = $app->galerie();
    $id = [];
    foreach ([['Léto', 'auto'], ['léto', 'Auta'], ['automobil', 'Moře'], ['auto']] as $i => $t) {
        $f = docasna('f'); file_put_contents($f, jpegMeta());
        $id[$i] = $app->media()->nahraj($f, "s$i.jpg", $t)['id'];
    }
    $g->archivuj([$id[3]], true);
    $st = $app->stitky();
    $seznam = fn() => array_map(fn($t) => [$t['nazev'], $t['pocet'], $t['archiv']], $st->seznam());
    eq([['Auta', 1, 0], ['auto', 1, 1], ['automobil', 1, 0], ['Léto', 2, 0], ['Moře', 1, 0]], $seznam());
    eq(['Léto'], array_column($st->seznam('leto'), 'nazev'), 'hledání bez diakritiky a velikosti písmen');
    eq(2, $st->prejmenuj('Léto', 'Prázdniny'));
    eq(['Prázdniny', 'auto'], Media::tagy($app->media()->radek($id[0])));
    throws(fn() => $st->prejmenuj('neni', 'x'), 'nenalezen');
    throws(fn() => $st->prejmenuj('auto', str_repeat('x', 31)), '30');
    // sloučení tří do nového názvu; médium s víc sloučenými má výsledný štítek jen jednou
    $f = docasna('f'); file_put_contents($f, jpegMeta());
    $obe = $app->media()->nahraj($f, 'obe.jpg', ['auto', 'Auta'])['id'];
    eq(5, $st->slouc(['auto', 'Auta', 'automobil'], 'Auta a motorky'));
    eq(['Auta a motorky'], Media::tagy($app->media()->radek($obe)));
    eq([['Auta a motorky', 4, 1], ['Moře', 1, 0], ['Prázdniny', 2, 0]], $seznam());
    // sloučení do existujícího štítku jen s jedním vybraným; cíl v jiném zápisu velikosti písmen se přejmenuje
    eq(3, $st->slouc(['Moře'], 'prázdniny'), 'Moře (1) + změna zápisu cíle (2)');
    eq([['Auta a motorky', 4, 1], ['prázdniny', 3, 0]], $seznam());
    throws(fn() => $st->slouc(['prázdniny'], 'Nový'), 'aspoň dva');
    throws(fn() => $st->slouc(['prázdniny', 'Auta a motorky'], '  '), 'Zadejte');
    eq(5, $st->smaz('AUTA A MOTORKY'));
    eq([['prázdniny', 3, 0]], $seznam());
    throws(fn() => $st->smaz('neni'), 'nenalezen');
    ok(count($app->media()->seznam([], 50)['polozky']) === 4, 'média zůstala');
});

// ---------- na serveru ----------

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
$app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$kat = [];
foreach (['Auta', 'Budovy', 'Cesty'] as $n) $kat[$n] = $app->kategorie()->uloz(['name' => $n]);
for ($i = 1; $i <= 20; $i++) {
    $f = docasna('img'); file_put_contents($f, jpegMeta());
    $app->media()->nahraj($f, sprintf('foto-%02d.jpg', $i), $i <= 3 ? ['Léto', 'moře'] : ($i <= 5 ? ['léto'] : []), $kat['Auta']);
}

test('server: pořadí kategorií – JSON odpověď pro přetažení, bez JS přesměrování, bez CSRF nic', function () use ($s, $b, $kat) {
    $r = $b->jdi('index.php?p=kategorie');
    ok(str_contains($r['body'], 'data-uchyt') && str_contains($r['body'], 'tbody data-poradi='), 'úchyty a tělo tabulky s CSRF');
    ok(!str_contains($r['body'], 'name="web_order"'), 'číselné pole Pořadí je pryč');
    $b->hlavicky = ['Accept: application/json'];
    $r = $b->jdi('index.php?p=kategorie', ['akce' => 'poradi', 'poradi' => "{$kat['Cesty']},{$kat['Auta']},{$kat['Budovy']}"]);
    eq(['ok' => true], json_decode($r['body'], true));
    eq('application/json', $r['type']);
    eq(['Cesty', 'Auta', 'Budovy'], $s->db()->query('SELECT name FROM categories ORDER BY web_order')->fetchAll(PDO::FETCH_COLUMN));
    $b->hlavicky = ['Accept: application/json'];
    eq(false, json_decode($b->jdi('index.php?p=kategorie', ['akce' => 'poradi', 'poradi' => 'x'])['body'], true)['ok']);
    $r = $b->odesli('index.php?p=kategorie', ['akce' => 'poradi', 'poradi' => "{$kat['Auta']},{$kat['Budovy']},{$kat['Cesty']}"]);
    ok(str_contains($r['body'], 'Pořadí kategorií uloženo'));
    $b->jdi('index.php?p=kategorie', ['akce' => 'poradi', 'poradi' => "{$kat['Cesty']}", 'csrf' => 'spatne']);
    eq('Auta', $s->db()->query('SELECT name FROM categories ORDER BY web_order LIMIT 1')->fetchColumn(), 'bez CSRF se nic nezmění');
});

test('server: stránka Štítky – menu, seznam, hledání, přejmenování, sloučení, smazání, chyba zůstane ve formuláři', function () use ($s, $b) {
    $r = $b->jdi('index.php?p=stitky');
    ok(str_contains($r['body'], '>Štítky<'), 'v menu');
    ok(str_contains($r['body'], 'Léto') && str_contains($r['body'], 'moře'));
    ok(str_contains($r['body'], 'index.php?p=galerie&amp;tag=L%C3%A9to'), 'počet vede do Galerie');
    ok(!str_contains($r['body'], 'target="_blank"'), 'nic v nové kartě');
    $r = $b->jdi('index.php?p=stitky&q=more');
    ok(str_contains($r['body'], 'moře') && !str_contains($r['body'], 'name="vybrane[]" value="Léto"'), 'hledání');
    $r = $b->jdi('index.php?p=stitky&prejmenovat=L%C3%A9to');
    ok(str_contains($r['body'], 'Přejmenovat štítek') && str_contains($r['body'], 'id="prejmenovat"'));
    $r = $b->odesli('index.php?p=stitky&prejmenovat=L%C3%A9to', ['akce' => 'prejmenovat', 'z' => 'Léto', 'na' => 'Prázdniny']);
    ok(str_contains($r['body'], 'přejmenován na „Prázdniny“ (5 médií)'), mb_substr(strip_tags($r['body']), 0, 300));
    $r = $b->odesli('index.php?p=stitky', ['akce' => 'sloucit', 'vybrane' => ['Prázdniny'], 'cil' => 'Nové']);
    ok(str_contains($r['body'], 'aspoň dva') && str_contains($r['body'], 'value="Nové"'), 'chyba: formulář zůstane vyplněný');
    $r = $b->odesli('index.php?p=stitky', ['akce' => 'sloucit', 'vybrane' => ['Prázdniny', 'moře'], 'cil' => 'Dovolená']);
    ok(str_contains($r['body'], 'sloučeny do „Dovolená“ (změněno 5 médií)'));
    eq(5, (int)$s->db()->query("SELECT COUNT(*) FROM media WHERE tags = '[\"Dovolená\"]'")->fetchColumn());
    $r = $b->odesli('index.php?p=stitky', ['akce' => 'smazat', 'nazev' => 'Dovolená']);
    ok(str_contains($r['body'], 'smazán u 5 médií'));
    ok(str_contains($r['body'], 'Zatím žádné štítky'));
});

test('server: Přehled – Naposledy nahrané 16 nejnovějších v mřížce 8 × 2', function () use ($b) {
    $r = $b->jdi('index.php');
    ok(str_contains($r['body'], 'class="posledni-obal"'));
    preg_match_all('/<figure[^>]*data-okno=/', explode('Naposledy nahrané', $r['body'], 2)[1] ?? '', $m);
    eq(Media::NA_PREHLEDU, count($m[0]));
    eq(16, Media::NA_PREHLEDU);
    ok(str_contains($r['body'], 'foto-20.jpg') && str_contains($r['body'], 'foto-05.jpg') && !str_contains($r['body'], 'foto-04.jpg'), 'nejnovějších 16');
});

test('server: Základ a texty ve skupinách, reklama jen dvě pozice, uschovaný kód Pod galeriemi', function () use ($s, $b, $app) {
    $r = $b->jdi('index.php?p=web');
    foreach (['Stav webu', 'Logo a patička', 'Úvodní část hlavní stránky', 'Vybrané galerie', 'Doporučené fotografie', 'Stránka O galerii', 'class="web-zaklad"', 'ulozit-lista'] as $t) ok(str_contains($r['body'], $t), $t);
    ok(!str_contains($r['body'], 'name="reklama_pod_galeriemi"') && !str_contains($r['body'], 'Uschovaný kód'), 'bez zrušené pozice');
    $r = $b->odesli('index.php?p=web', ['akce' => 'zaklad', 'zapnuto' => '1', 'nazev' => 'Moje', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '3',
        'doporucene_nadpis' => 'D', 'doporucene_sloupce' => '5', 'doporucene_radky' => '2']);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    eq('Moje', json_decode((string)$s->db()->query("SELECT v FROM settings WHERE k = 'web'")->fetchColumn(), true)['nazev']);
    // stará instalace s kódem ve zrušené pozici (obě pozice obsazené) → uschovaný kód v kartě Reklama, web ho nevypíše
    $app->nastaveni()->uloz('web', json_encode(['reklama_zapnuto' => true, 'reklama' => ['pod_uvodem' => '<i id="u"></i>', 'pod_galeriemi' => '<i id="g"></i>', 'pred_patickou' => '<i id="p"></i>'], 'cookies_souhlas' => false]));
    $r = $b->jdi('index.php?p=web&karta=reklama');
    ok(str_contains($r['body'], 'Uschovaný kód') && str_contains($r['body'], htmlspecialchars('<i id="g"></i>')));
    $web = (new Prohlizec($s))->jdi('verejne.php')['body'];
    ok(str_contains($web, '<i id="u"></i>') && str_contains($web, '<i id="p"></i>') && !str_contains($web, '<i id="g"></i>') && !str_contains($web, 'reklama-pod-galeriemi'));
    $b->odesli('index.php?p=web&karta=reklama', ['akce' => 'reklama', 'reklama_zapnuto' => '1', 'reklama_pod_uvodem' => '<i id="u"></i>', 'reklama_pred_patickou' => '<i id="g"></i>', 'zahodit_odlozenou' => '1']);
    $r = $b->jdi('index.php?p=web&karta=reklama');
    ok(!str_contains($r['body'], 'Uschovaný kód'), 'zahozený');
    ok(str_contains((new Prohlizec($s))->jdi('verejne.php')['body'], '<i id="g"></i>'), 'přesunutý ručně Před patičku');
});

// ---------- v prohlížeči ----------

$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';

/** Playwright: přihlásí se a spustí tělo (p = stránka, u = adresa, v = výsledky). */
function prohlizec088(string $url, string $telo, int $sirka = 1360): array
{
    $skript = docasna('pw') . '.js';
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ viewport: { width: $sirka, height: 1000 } });
  const p = await ctx.newPage();
  const u = '$url/';
  const v = { chyby: [], stranek: 0 };
  ctx.on('page', () => v.stranek++);
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error' && !/favicon/.test(m.text())) v.chyby.push(m.text()); });
  await p.goto(u + 'index.php');
  await p.fill('input[name=heslo]', 'tajneheslo1');
  await Promise.all([p.waitForNavigation(), p.press('input[name=heslo]', 'Enter')]);
  $telo
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e).slice(0, 300) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([], $v['chyby'] ?? [], 'chyby JavaScriptu');
    eq(0, $v['stranek'] ?? 0, 'žádná nová karta');
    return $v;
}

test('prohlížeč: kategorie přetažením myší i šipkami, pořadí se uloží a drží po načtení', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $v = prohlizec088($s->url, <<<'JS'
  const jmena = () => p.$$eval('tbody[data-poradi] tr td:nth-child(2) strong', e => e.map(x => x.textContent));
  await p.goto(u + 'index.php?p=kategorie');
  v.pred = await jmena();
  const uchyt = await p.$('tbody[data-poradi] tr:nth-child(3) [data-uchyt]');
  const cil = await p.$('tbody[data-poradi] tr:nth-child(1)');
  const a = await uchyt.boundingBox(), c = await cil.boundingBox();
  await p.mouse.move(a.x + a.width / 2, a.y + a.height / 2);
  await p.mouse.down();
  await p.mouse.move(a.x + a.width / 2, c.y + 5, { steps: 8 });
  await p.mouse.up();
  await p.waitForFunction(() => /uloženo/.test(document.querySelector('[data-poradi-stav]').textContent));
  v.poTazeni = await jmena();
  await p.reload();
  v.poNacteni = await jmena();
  await p.focus('tbody[data-poradi] tr:nth-child(1) [data-uchyt]');
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('ArrowDown');
  v.fokus = await p.evaluate(() => document.activeElement.closest('tr').querySelector('strong').textContent);
  await p.waitForFunction(() => /uloženo/.test(document.querySelector('[data-poradi-stav]').textContent));
  await p.reload();
  v.poSipkach = await jmena();
  v.adresa = p.url();
JS);
    eq(['Auta', 'Budovy', 'Cesty'], $v['pred']);
    eq(['Cesty', 'Auta', 'Budovy'], $v['poTazeni']);
    eq(['Cesty', 'Auta', 'Budovy'], $v['poNacteni']);
    eq('Cesty', $v['fokus'], 'úchyt zůstane zaměřený');
    eq(['Auta', 'Budovy', 'Cesty'], $v['poSipkach']);
    ok(str_contains($v['adresa'], 'p=kategorie'));
});

test('prohlížeč: Naposledy nahrané 8 × 2 na počítači, 4 × 4 a 2 × 8 na užší ploše, všech 16 vidět; Základ a texty bez vodorovného posuvu', function () use ($pw, $s) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $mrizka = <<<'JS'
  await p.goto(u + 'index.php');
  v.m = await p.$$eval('.posledni-mrizka figure', e => {
    const top = [...new Set(e.map(x => Math.round(x.getBoundingClientRect().top)))];
    const left = [...new Set(e.map(x => Math.round(x.getBoundingClientRect().left)))];
    return [e.length, left.length, top.length, e.filter(x => x.getBoundingClientRect().width > 0).length];
  });
  await p.goto(u + 'index.php?p=web');
  v.web = await p.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
JS;
    foreach ([[1600, 8, 2], [1000, 4, 4], [400, 2, 8]] as [$sirka, $sloupcu, $radku]) {
        $v = prohlizec088($s->url, $mrizka, $sirka);
        eq([16, $sloupcu, $radku, 16], $v['m'], "šířka $sirka");
        eq($v['web'][1], $v['web'][0], "Veřejný web bez vodorovného posuvu při $sirka px");
    }
});
