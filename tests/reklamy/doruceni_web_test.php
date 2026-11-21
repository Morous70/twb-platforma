<?php
declare(strict_types=1);
// TWB Reklamy 0.2: veřejné soubory doručování (d/) na vestavěném serveru a vložení do stránky ve skutečném prohlížeči.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\{App, XmlRpc, Kody};

echo "Reklamy: doručování na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->nastaveni()->uloz('click_param', '0'); // token twbr v cíli prokliku zkouší konverze_web_test
$b = new Prohlizec($s);

// data: web, zóna 300×250 se dvěma obrázkovými bannery, zóna 728×90 s HTML bannerem, prázdná zóna s náhradním HTML
$web = $app->weby()->uloz(['name' => 'Test']);
$zCtverec = $app->zony()->uloz(['site_id' => $web, 'name' => 'Čtverec', 'width' => 300, 'height' => 250]);
$zPruh = $app->zony()->uloz(['site_id' => $web, 'name' => 'Pruh', 'width' => 728, 'height' => 90]);
$zPrazdna = $app->zony()->uloz(['site_id' => $web, 'name' => 'Prázdná', 'fallback' => '<p class="nic">Tady může být vaše reklama</p>']);
$kid = kampan($app, ['name' => 'Test']);
$m = $app->galerie()->uloz(pngSoubor(300, 250), 'a.png');
$obr1 = $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'Obr 1', 'media_id' => $m['id'], 'target_url' => 'https://kazbo.eu/jedna']);
$obr2 = $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'external', 'name' => 'Obr 2', 'width' => 300, 'height' => 250, 'image_url' => $s->url . '/assets/ikona.svg', 'target_url' => 'https://kazbo.eu/dva']);
$html = $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'HTML', 'width' => 728, 'height' => 90, 'target_url' => 'https://kazbo.eu/html',
    'html' => '<a id="a" href="{clickurl}">Cíl</a> <a id="b" href="{clickurl}https://partner.cz/x">Partner</a> <script>document.title = "banner {bannerid}";</script></script-ne>']);
$app->zony()->prirad($zCtverec, [$kid], []);
$app->zony()->prirad($zPruh, [$kid], []);
$stat = fn(string $co, ?int $banner = null) => (int)$app->db->q("SELECT COALESCE(SUM($co), 0) FROM stats" . ($banner ? " WHERE banner_id = $banner" : ''))->fetchColumn();

test('JSON pro víc zón: různé bannery, náhradní HTML, CORS, zobrazení se počítá', function () use ($b, $s, $zCtverec, $zPruh, $zPrazdna, $obr1, $obr2, $html, $stat) {
    $b->hlavicky = ['Origin: https://kazbo.eu'];
    $r = $b->jdi("d/data.php?zony=$zCtverec,$zCtverec,$zCtverec,$zPruh,$zPrazdna,999,x&loc=" . rawurlencode('https://kazbo.eu/clanek'));
    eq([200, 'application/json'], [$r['code'], $r['type']]);
    ok(str_contains($b->posledniHlavicky, 'Access-Control-Allow-Origin: https://kazbo.eu') && str_contains($b->posledniHlavicky, 'Access-Control-Allow-Credentials: true'));
    ok(str_contains($b->posledniHlavicky, 'no-store'));
    $j = json_decode($r['body'], true);
    eq(6, count($j['zony']), 'neplatné ID zóny se vynechá, neexistující vrátí prázdno');
    $ids = array_map(fn($z) => $z['banner']['id'] ?? null, $j['zony']);
    eq([$obr1, $obr2], [min($ids[0], $ids[1]), max($ids[0], $ids[1])], 'dva různé bannery');
    eq([null, $html, null, null], [$ids[2], $ids[3], $ids[4], $ids[5]], 'třetí čtverec už nemá co zobrazit');
    eq('<p class="nic">Tady může být vaše reklama</p>', $j['zony'][4]['html']);
    ok(str_contains($j['zony'][3]['html'], '<iframe src="' . $s->url . '/d/b.php?b=' . $html));
    eq(300, $j['zony'][0]['banner']['sirka']);
    eq(3, $stat('impressions'));
});

test('JavaScript (document.write), rám a náhled', function () use ($b, $s, $app, $zPruh, $zCtverec, $html, $stat) {
    $pred = $stat('impressions');
    $r = $b->jdi("d/js.php?zona=$zPruh");
    eq('application/javascript', $r['type']);
    ok(preg_match('/^document\.write\(".*\\\\u003Ciframe/', $r['body']) === 1, 'HTML zakódované, aby nezavřelo <script>');
    $r = $b->jdi("d/ram.php?zona=$zPruh");
    ok(str_contains($r['body'], '<a id="a" href="' . $s->url . '/d/klik.php?b=' . $html . '&z=' . $zPruh . '&k='), 'HTML banner je přímo obsahem rámu s makry');
    ok(str_contains($r['body'], '<script>document.title = "banner ' . $html . '";</script>'));
    $r = $b->jdi("d/ram.php?zona=$zCtverec");
    ok(str_contains($r['body'], '<base target="_blank">') && str_contains($r['body'], '<img src='));
    eq($pred + 3, $stat('impressions'));
    $nahled = (new Reklamy\Vydej($app))->podpisNahledu($zCtverec);
    $b->jdi("d/ram.php?zona=$zCtverec&nahled=$nahled");
    $b->jdi("d/ram.php?zona=$zCtverec&nahled=spatny");
    eq($pred + 4, $stat('impressions'), 'náhled z administrace se nepočítá, neplatný podpis ano');
    $b->ua = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
    $b->jdi("d/ram.php?zona=$zCtverec");
    $b->ua = (new Prohlizec($GLOBALS['s']))->ua;
    eq($pred + 4, $stat('impressions'), 'robot se nepočítá');
    eq('', $b->jdi('d/js.php?zona=abc')['body']);
});

test('proklik: podepsaný odkaz, cíl jen povolený, neplatný podpis', function () use ($b, $s, $app, $zPruh, $html, $obr1, $zCtverec, $stat) {
    $v = $app->vykresleni();
    $klik = $v->klik($app->bannery()->radek($html), $zPruh);
    $r = $b->jdi($klik);
    eq([302, 'https://kazbo.eu/html'], [$r['code'], $r['location']]);
    eq([302, 'https://partner.cz/x'], array_values(array_intersect_key($b->jdi($klik . '&cil=' . rawurlencode('https://partner.cz/x')), ['code' => 1, 'location' => 1])));
    eq('https://kazbo.eu/html', $b->jdi($klik . '&cil=' . rawurlencode('https://zlo.example/'))['location'], 'cizí adresa → cíl banneru');
    eq('https://kazbo.eu/html', $b->jdi($klik . '&cil=' . rawurlencode('javascript:alert(1)'))['location']);
    $obrKlik = $v->klik($app->bannery()->radek($obr1), $zCtverec);
    eq('https://kazbo.eu/jedna', $b->jdi($obrKlik . '&cil=' . rawurlencode('https://kazbo.eu/jinam'))['location'], 'u obrázku se cil ignoruje');
    eq(404, $b->jdi(str_replace('&k=', '&k=0', $klik))['code']);
    eq(404, $b->jdi("d/klik.php?b=$html&z=$zPruh")['code']);
    eq(5, $stat('clicks'));
    eq(4, $stat('clicks', $html));
});

test('vložení odkazem: obrázek a proklik se stejným n vedou na stejný banner', function () use ($b, $s, $app, $zCtverec, $zPruh, $stat) {
    $cile = [];
    for ($i = 0; $i < 6; $i++) {
        $o = $b->jdi("d/obr.php?zona=$zCtverec&n=mail$i");
        $banner = (int)$app->db->q("SELECT banner_id FROM view_map WHERE n = 'mail$i'")->fetchColumn();
        if ($banner === (int)$app->db->q("SELECT id FROM banners WHERE type = 'image'")->fetchColumn()) eq([200, 'image/png'], [$o['code'], $o['type']]);
        else eq([302, $s->url . '/assets/ikona.svg'], [$o['code'], $o['location']]);
        $k = $b->jdi("d/klik.php?zona=$zCtverec&n=mail$i");
        eq(302, $k['code']);
        $cile[$i] = $k['location'];
        eq($app->bannery()->radek($banner)['target_url'], $k['location']);
    }
    $r = $b->jdi("d/obr.php?zona=$zPruh&n=x");
    eq(['image/gif', 43], [$r['type'], strlen($r['body'])], 'zóna bez obrázkového banneru → průhledný pixel');
    eq(302, $b->jdi("d/klik.php?zona=$zCtverec&n=nikdy-nezobrazeno")['code'], 'bez záznamu se vybere banner zóny');
});

test('obsah banneru: obrázek s mezipamětí, HTML jen s podpisem', function () use ($b, $app, $obr1, $html, $zPruh) {
    $u = $app->vykresleni()->obrazek($app->bannery()->radek($obr1));
    $r = $b->jdi($u);
    eq([200, 'image/png'], [$r['code'], $r['type']]);
    ok(str_contains($b->posledniHlavicky, 'max-age=31536000'));
    preg_match('/ETag: (\S+)/i', $b->posledniHlavicky, $m);
    $b->hlavicky = ['If-None-Match: ' . $m[1]];
    eq(304, $b->jdi($u)['code']);
    $d = $b->jdi($app->vykresleni()->dokument($app->bannery()->radek($html), $zPruh));
    eq([200, 'text/html'], [$d['code'], $d['type']]);
    ok(str_contains($d['body'], 'Cíl</a>'));
    eq(404, $b->jdi("d/b.php?b=$html&z=$zPruh&k=0000000000000000")['code']);
    eq(404, $b->jdi("d/b.php?b=$html&v=x")['code']);
});

test('XML-RPC openads.view', function () use ($b, $zCtverec, $zPrazdna) {
    $x = $b->jdi('d/xmlrpc.php', telo: XmlRpc::volani('openads.view', [['remote_addr' => '1.2.3.4', 'user_agent' => $b->ua], "zone:$zCtverec", 0, '', '', false, []]));
    $d = new DOMDocument();
    ok($d->loadXML($x['body']), $x['body']);
    ok(preg_match('~<name>bannerid</name><value><int>([1-9]\d*)</int>~', $x['body']) === 1, $x['body']);
    ok(str_contains($x['body'], '<name>html</name><value><string>&lt;a href='));
    ok(str_contains($x['body'], '<name>contenttype</name><value><string>image</string>'));
    $x = $b->jdi('d/xmlrpc.php', telo: XmlRpc::volani('openads.view', [[], (string)$zPrazdna, 0, '', '', false, []]));
    ok(str_contains($x['body'], 'Tady může být vaše reklama'));
    ok(str_contains($b->jdi('d/xmlrpc.php', telo: XmlRpc::volani('jine.metoda', []))['body'], '<name>faultCode</name><value><int>3</int>'));
    ok(str_contains($b->jdi('d/xmlrpc.php', telo: '<neplatne')['body'], '<fault>'));
    eq(405, $b->jdi('d/xmlrpc.php')['code']);
});

test('vyskakovací okno a asynchronní skript', function () use ($b, $s, $zCtverec) {
    $r = $b->jdi("d/okno.php?zona=$zCtverec&kdy=ihned&jednou=1");
    ok(str_contains($r['body'], '"url":"' . $s->url . '/d/ram.php?zona=' . $zCtverec . '"') && str_contains($r['body'], 'width=300,height=250'));
    ok(str_contains($r['body'], '"klik":false') && str_contains($r['body'], '"jednou":true'));
    eq('', $b->jdi('d/okno.php?zona=999')['body']);
    $r = $b->jdi('d/asyn.php');
    ok(str_contains($r['body'], 'var zaklad = "' . $s->url . '/d/";'));
    ok(str_contains($b->posledniHlavicky, 'max-age=3600'));
});

// ---------- skutečný prohlížeč (Chromium + Playwright), stránka na jiné adrese než reklamy ----------
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: asynchronní vložení, JavaScript, rám, proklik', function () use ($pw, $s, $app, $zCtverec, $zPruh, $zPrazdna, $stat) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $web = docasna('stranka');
    mkdir($web);
    $k = new Kody($app);
    $zCtv = $app->zony()->radek($zCtverec);
    file_put_contents("$web/index.html", "<!doctype html><html><head><meta charset=\"utf-8\"><title>Test</title></head><body>\n"
        . '<div id="asyn1">' . $k->kod($zCtv, 'asyn') . "</div>\n"
        . '<div id="asyn2">' . $k->kod($app->zony()->radek($zPruh), 'asyn') . "</div>\n"
        . '<div id="asyn3">' . $k->kod($app->zony()->radek($zPrazdna), 'asyn') . "</div>\n"
        . '<div id="js">' . $k->kod($zCtv, 'js') . "</div>\n"
        . '<div id="ram">' . $k->kod($zCtv, 'iframe') . "</div>\n</body></html>");
    $port = (int)substr(strrchr(stream_socket_get_name($sock = stream_socket_server('tcp://127.0.0.1:0'), false), ':'), 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $web], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400000);
    $skript = docasna('pw') . '.js';
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });   // HeadlessChrome by se nepočítal
  const navstiveno = [];
  ctx.on('request', r => { if (r.isNavigationRequest()) navstiveno.push(r.url()); });
  const p = await ctx.newPage();
  const chyby = [];
  p.on('pageerror', e => chyby.push(e.message));
  await p.goto('http://127.0.0.1:$port/index.html');
  await p.waitForSelector('#asyn3 ins[data-twbr-stav]:not([data-twbr-stav="nacita"])', { timeout: 10000 });
  const v = {};
  v.asyn1 = await p.\$eval('#asyn1 ins', e => [e.dataset.twbrStav, !!e.querySelector('a[href*="/d/klik.php"] img')]);
  v.asyn2 = await p.\$eval('#asyn2 ins', e => [e.dataset.twbrStav, !!e.querySelector('iframe[src*="/d/b.php"]')]);
  v.asyn3 = await p.\$eval('#asyn3 ins', e => [e.dataset.twbrStav, e.textContent.trim()]);
  v.js = await p.\$eval('#js', e => !!e.querySelector('a[href*="/d/klik.php"] img'));
  const ram = p.frames().find(f => f.url().includes('/d/ram.php'));
  v.ram = ram ? await ram.\$eval('body', e => !!e.querySelector('img')) : false;
  const ramec = p.frames().find(f => f.url().includes('/d/b.php'));
  await ramec.waitForSelector('#a');
  v.banner = await ramec.evaluate(() => document.title);
  const [popup] = await Promise.all([p.waitForEvent('popup'), p.click('#asyn1 a')]);
  await popup.waitForEvent('requestfailed', { timeout: 5000 }).catch(() => {});   // cíl mimo testovací prostředí není dostupný
  v.proklik = navstiveno.filter(u => u.startsWith('https://kazbo.eu/'));
  v.chyby = chyby;
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $pred = $stat('clicks');
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    proc_terminate($proc);
    proc_close($proc);
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq(['ok', true], $v['asyn1']);
    eq(['ok', true], $v['asyn2']);
    eq(['prazdne', 'Tady může být vaše reklama'], $v['asyn3']);
    eq(true, $v['js']);
    eq(true, $v['ram']);
    ok(str_starts_with($v['banner'], 'banner '), 'skript v HTML banneru běží');
    eq(1, count($v['proklik']), 'proklik vede na cíl banneru: ' . $out);
    eq([], $v['chyby']);
    eq($pred + 1, $stat('clicks'));
});
