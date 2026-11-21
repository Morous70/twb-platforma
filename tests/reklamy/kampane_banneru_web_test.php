<?php
declare(strict_types=1);
// TWB Reklamy 0.8 na webu: banner ve více kampaních (formuláře), náhled banneru v modálním okně (i ve skutečném prohlížeči), stránka Externí data.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\App;

echo "Reklamy: více kampaní, náhled a externí data na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);
$b->jdi('index.php');
$b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);

$advent = kampan($app, ['name' => 'Advent']);
$leto = kampan($app, ['name' => 'Léto']);
$html = htmlBanner($app, $advent, 300, 250, ['name' => 'HTML banner', 'html' => '<p id="t">Ahoj {datum}</p><script>document.title = "uvnitř";</script>']);
$m = $app->galerie()->uloz(pngSoubor(970, 310), 'velky.png');
$obr = $app->bannery()->uloz(['campaign_id' => $leto, 'type' => 'image', 'name' => 'Velký obrázek', 'media_id' => $m['id']]);

test('kampaň: přidat existující banner, odebrat ho, kampaně v úpravě banneru', function () use ($b, $app, $advent, $leto, $html) {
    $r = $b->jdi("index.php?p=kampan&id=$leto");
    ok(str_contains($r['body'], 'Přidat existující banner…') && str_contains($r['body'], '<option value="' . $html . '">HTML banner (300×250, Advent)</option>'), 'nabídka bannerů z jiných kampaní');
    $r = $b->odesli("index.php?p=kampan&id=$leto", ['akce' => 'banner-pridat', 'banner' => $html]);
    eq([$advent, $leto], $app->bannery()->kampane($html));
    ok(str_contains($r['body'], 'Také v: <a href="index.php?p=kampan&amp;id=' . $advent . '">Advent</a>'), 'u sdíleného banneru jsou vidět ostatní kampaně');
    ok(str_contains($r['body'], 'name="akce" value="banner-odebrat"'));
    $r = $b->jdi("index.php?p=banner&id=$html&kampan=$leto");
    ok(str_contains($r['body'], 'Kampaně: <a href="index.php?p=kampan&amp;id=' . $advent . '">Advent</a>, <a href="index.php?p=kampan&amp;id=' . $leto . '">Léto</a>'));
    eq(2, preg_match_all('/name="kampane\[\]" value="\d+" checked/', $r['body']));
    ok(str_contains($r['body'], 'href="index.php?p=kampan&amp;id=' . $leto . '">Zpět na kampaň</a>'), 'zpět do kampaně, ze které uživatel přišel');
    // uložení bez kampaně se odmítne, s jednou kampaní nahradí přiřazení
    $pole = ['akce' => 'ulozit', 'name' => 'HTML banner', 'width' => 300, 'height' => 250, 'html' => '<p>x</p>', 'target' => '_blank', 'status' => 'active', 'weight' => 1];
    $r = $b->jdi("index.php?p=banner&id=$html", $pole + ['kampane' => [0]]);   // nic nezaškrtnuto: formulář pošle jen skryté pole
    ok(str_contains($r['body'], 'Vyberte aspoň jednu kampaň'));
    $r = $b->jdi("index.php?p=banner&id=$html&kampan=$leto", $pole + ['kampane' => [$leto]]);
    eq(303, $r['code']);
    eq([$leto], $app->bannery()->kampane($html));
    $b->odesli("index.php?p=kampan&id=$leto", ['akce' => 'banner-odebrat', 'banner' => $html]);
    eq([$leto], $app->bannery()->kampane($html), 'z poslední kampaně banner odebrat nejde');
    $app->bannery()->nastavKampane($html, [$advent, $leto]);
    // smazání kampaně: potvrzení říká, co se smaže
    $r = $b->jdi("index.php?p=kampan&id=$advent");
    ok(str_contains($r['body'], 'Bannery sdílené s jinými kampaněmi zůstanou.'));
    // zóna ukáže všechny kampaně banneru
    $web = $app->weby()->uloz(['name' => 'Kazbo']);
    $z = $app->zony()->uloz(['site_id' => $web, 'name' => 'Čtverec', 'width' => 300, 'height' => 250]);
    $app->zony()->prirad($z, [$leto], []);
    $r = $b->jdi("index.php?p=zona&id=$z");
    ok(str_contains($r['body'], '<th>Kampaně</th>') && str_contains($r['body'], '>Advent</a>, <a href="index.php?p=kampan&amp;id=' . $leto . '">Léto</a>'));
});

test('seznam bannerů: kampaně a tlačítko s okem; náhled jen přihlášenému a izolovaný', function () use ($b, $s, $app, $html, $obr, $m) {
    $r = $b->jdi('index.php?p=bannery');
    eq(2, substr_count($r['body'], 'data-nahled='));
    ok(str_contains($r['body'], 'data-nahled="ram" data-src="nahled.php?id=' . $html . '" data-w="300" data-h="250" data-nazev="HTML banner"'), 'HTML v rámu');
    ok(str_contains($r['body'], 'data-nahled="obrazek" data-src="media.php?id=' . $m['id'] . '" data-w="970" data-h="310"'), 'obrázek přímo');
    ok(str_contains($r['body'], 'aria-label="Náhled banneru Velký obrázek"'));
    $r = $b->jdi("nahled.php?id=$html");
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<p>x</p>') || str_contains($r['body'], 'Ahoj'), $r['body']);
    ok(str_contains($b->posledniHlavicky, 'Content-Security-Policy: sandbox allow-scripts'), 'vlastní prázdný původ');
    eq(404, $b->jdi("nahled.php?id=$obr")['code'], 'obrázky okno zobrazuje přímo');
    eq(404, $b->jdi('nahled.php?id=999')['code']);
    $cizi = new Prohlizec($s);
    eq(403, $cizi->jdi("nahled.php?id=$html")['code']);
    // stránka kampaně má oko u každého banneru
    $r = $b->jdi('index.php?p=kampan&id=' . $app->bannery()->kampane($html)[0]);
    ok(str_contains($r['body'], 'data-src="nahled.php?id=' . $html . '"'));
});

$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: oko otevře okno s bannerem, velký se zmenší, Esc i křížek zavřou', function () use ($pw, $s, $b, $html, $obr) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $cookies = [];
    foreach (file($b->jar()) ?: [] as $l) {
        $c = explode("\t", trim(preg_replace('/^#HttpOnly_/', '', $l)));
        if (count($c) === 7 && !str_starts_with($l, '# ')) $cookies[] = ['name' => $c[5], 'value' => $c[6], 'domain' => '127.0.0.1', 'path' => $c[2]];
    }
    $skript = docasna('pw') . '.js';
    $cookiesJs = json_encode($cookies);
    $url = $s->url;
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const v = { chyby: [] };
  const ctx = await b.newContext({ viewport: { width: 800, height: 700 } });
  await ctx.addCookies($cookiesJs);
  const p = await ctx.newPage();
  p.on('pageerror', e => v.chyby.push(e.message));
  p.on('console', m => { if (m.type() === 'error') v.chyby.push(m.text()); });
  await p.goto('$url/index.php?p=bannery');
  await p.click('[data-nahled="ram"]');
  await p.waitForSelector('dialog.nahled-okno[open] iframe');
  const f = p.frameLocator('dialog.nahled-okno iframe');
  v.text = await f.locator('p').first().textContent();
  v.nazev = await p.textContent('#nahled-okno-nazev');
  v.ram = await p.\$eval('dialog.nahled-okno iframe', e => [e.width, e.height, e.getAttribute('sandbox')]);
  await p.keyboard.press('Escape');
  await p.waitForTimeout(200);
  v.poEsc = await p.\$eval('dialog.nahled-okno', e => [e.open, e.querySelector('.nahled-okno-obsah').children.length]);
  await p.click('[data-nahled="obrazek"]');
  await p.waitForSelector('dialog.nahled-okno[open] img');
  await p.waitForFunction(() => document.querySelector('dialog.nahled-okno img').complete);
  v.obrazek = await p.\$eval('dialog.nahled-okno img', e => [e.naturalWidth, Math.round(e.getBoundingClientRect().width)]);
  v.rozmer = await p.textContent('.nahled-okno-rozmer');
  v.sirkaOkna = await p.\$eval('dialog.nahled-okno', e => Math.round(e.getBoundingClientRect().width));
  await p.click('[data-zavrit-nahled]');
  await p.waitForTimeout(200);
  v.poKrizku = await p.\$eval('dialog.nahled-okno', e => e.open);
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq('x', $v['text'], 'obsah HTML banneru v rámu');
    eq('HTML banner', $v['nazev']);
    eq(["300", "250", 'allow-scripts allow-popups'], $v['ram']);
    eq([false, 0], $v['poEsc'], 'Esc zavře okno a banner zastaví');
    eq(970, $v['obrazek'][0]);
    ok($v['obrazek'][1] < 800 && $v['obrazek'][1] > 600, 'velký banner se zmenší do okna: ' . json_encode($v));
    ok(str_contains($v['rozmer'], '970×310 px · zmenšeno na'), $v['rozmer']);
    ok($v['sirkaOkna'] <= 800, 'okno se vejde');
    eq(false, $v['poKrizku']);
    eq([], $v['chyby']);
});

test('externí data: přidání zdroje, stažení, hodnoty se zápisem, smazání; editor slidů je vidí', function () use ($b, $s, $app, $html) {
    // zdroj na druhém vestavěném serveru (první server je jednovláknový a sám sebe by nestáhl)
    $dir = docasna('zdroj');
    mkdir($dir);
    file_put_contents("$dir/cenik.json", json_encode(['kava' => ['cena' => 59, 'nazev' => 'Espresso']]));
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int)substr(strrchr(stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $dir], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $es, 0.1); $i++) usleep(100000);
    try {
        $r = $b->jdi('index.php?p=zdroje');
        eq(200, $r['code']);
        ok(str_contains($r['body'], 'Zatím žádný zdroj.') && str_contains($r['body'], 'href="index.php?p=zdroje" class="aktivni"'), 'stránka v menu');
        $r = $b->odesli('index.php?p=zdroje', ['akce' => 'ulozit', 'id' => 0, 'name' => 'Ceník', 'url' => "http://127.0.0.1:$port/cenik.json", 'format' => 'json', 'interval_min' => 30]);
        ok(str_contains($r['body'], 'Zdroj uložen a stažen.'), mb_substr(strip_tags($r['body']), 0, 2000));
        ok(str_contains($r['body'], '<code>{Ceník:cena}</code></td><td>59</td>'), mb_substr($r["body"], (int)strpos($r["body"], "<code>{Cen"), 300));
        $id = (int)$s->db()->query("SELECT id FROM sources WHERE name = 'Ceník'")->fetchColumn();
        // banner používající zdroj: hodnota v dokumentu a varování při mazání
        $app->bannery()->uloz(['name' => 'HTML banner', 'width' => 300, 'height' => 250, 'html' => '<p>Káva {Ceník:kava.cena} Kč</p>'], $html);
        ok(str_contains($b->jdi("nahled.php?id=$html")['body'], '<p>Káva 59 Kč</p>'));
        $r = $b->jdi('index.php?p=zdroje');
        ok(str_contains($r['body'], 'Používají ho bannery: HTML banner'));
        // výpadek: data zůstanou, stránka ukáže chybu
        unlink("$dir/cenik.json");
        $r = $b->odesli('index.php?p=zdroje', ['akce' => 'stahnout', 'id' => $id]);
        ok(str_contains($r['body'], 'Stažení selhalo: HTTP 404'), mb_substr(strip_tags($r['body']), 0, 1500));
        ok(str_contains($r['body'], 'Bannery používají poslední stažená data.'));
        ok(str_contains($b->jdi("nahled.php?id=$html")['body'], '<p>Káva 59 Kč</p>'));
        // editor slidů: nabídka zdrojů
        $j = json_decode($b->jdi('api.php?a=source_tree')['body'], true);
        eq('Ceník', $j['sources'][0]['name']);
        ok(in_array('cena', array_column($j['sources'][0]['groups'][0]['items'], 'path'), true), json_encode($j));
        // neplatný název
        $r = $b->jdi('index.php?p=zdroje', ['akce' => 'ulozit', 'id' => 0, 'name' => 'a:b', 'url' => 'https://x.cz/a.json', 'format' => 'json', 'interval_min' => 30]);
        ok(str_contains($r['body'], 'nesmí obsahovat'));
        $b->odesli('index.php?p=zdroje', ['akce' => 'smazat', 'id' => $id]);
        eq(0, (int)$s->db()->query('SELECT COUNT(*) FROM sources')->fetchColumn());
    } finally {
        proc_terminate($proc);
        proc_close($proc);
    }
});
