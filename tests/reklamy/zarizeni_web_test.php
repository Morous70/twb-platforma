<?php
declare(strict_types=1);
// TWB Reklamy 0.9 na webu: responzivní zóna a zařízení ve formulářích, doručování podle šířky místa (JSON i skutečný prohlížeč na počítači a mobilu),
// obrázek 2×, statistiky po zařízeních.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\{App, Kody};

echo "Reklamy: reklamy podle zařízení na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->nastaveni()->uloz('click_param', '0');
$app->heslo()->nastav('tajneheslo1');
$b = new Prohlizec($s);
$b->jdi('index.php');
$b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);

$kid = kampan($app, ['name' => 'Advent']);
$web = $app->weby()->uloz(['name' => 'Kazbo']);
$zid = $app->zony()->uloz(['site_id' => $web, 'name' => 'Pod článkem']);
$obrazek = function (int $w, int $h, string $nazev, ?int $m2 = null) use ($app, $kid): int {
    $m = $app->galerie()->uloz(pngSoubor($w, $h), "$nazev.png");
    return $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => $nazev, 'media_id' => $m['id'], 'target_url' => "https://kazbo.eu/$nazev"]
        + ($m2 ? ['media2x_id' => $m2] : []));
};
$m2x = $app->galerie()->uloz(pngSoubor(600, 500), 'ctverec@2x.png');
$b970 = $obrazek(970, 250, 'billboard');
$b728 = $obrazek(728, 90, 'leaderboard');
$b300 = $obrazek(300, 250, 'ctverec', (int)$m2x['id']);

test('formuláře: responzivní zóna, tabulka výběru rozměru, zařízení a obrázek 2× u banneru', function () use ($b, $app, $zid, $kid, $b300, $b728) {
    $pole = ['akce' => 'ulozit', 'name' => 'Pod článkem', 'kind' => 'banner', 'width' => '', 'height' => '', 'fallback' => '', 'note' => ''];
    $r = $b->jdi("index.php?p=zona&id=$zid", ['width' => '300', 'sizes' => '970x250, 728x90'] + $pole);
    ok(str_contains($r['body'], 'šířku a výšku nechte prázdné'), 'zóna nemůže mít pevnou šířku i seznam rozměrů');
    eq(303, $b->jdi("index.php?p=zona&id=$zid", ['sizes' => '300x250 970x250, 728×90'] + $pole)['code']);
    eq('["970x250","728x90","300x250"]', $app->zony()->radek($zid)['sizes']);
    $app->zony()->prirad($zid, [$kid], []);
    $r = $b->jdi("index.php?p=zona&id=$zid");
    ok(str_contains($r['body'], 'rozměr responzivní 970×250, 728×90, 300×250'));
    ok(str_contains($r['body'], 'value="970x250, 728x90, 300x250"'), 'pole s rozměry');
    ok(str_contains($r['body'], 'Výběr rozměru podle šířky místa'));
    ok(str_contains($r['body'], '<td>728–969 px</td>') && str_contains($r['body'], '<td>970 px a víc</td>') && str_contains($r['body'], '<td>300–727 px</td>'), 'rozsahy šířek');
    ok(str_contains($r['body'], 'méně než 300 px: nic'));
    ok(str_contains($r['body'], 'Responzivní zónu vložte <b>asynchronním JavaScriptem</b>'));
    ok(str_contains($r['body'], 'style=&quot;display:block&quot;'), 'kód pro vložení');
    ok(str_contains($r['body'], '<th>Zařízení</th>') && str_contains($r['body'], ' title="Má obrázek pro displeje s vyšší hustotou">2×</span>'));
    // banner: zařízení a obrázek 2×
    $r = $b->jdi("index.php?p=banner&id=$b728");
    eq(3, preg_match_all('/name="zarizeni\[\]" value="(pocitac|tablet|mobil)" checked/', $r['body']), 'nový i starý banner: všechna zařízení');
    ok(str_contains($r['body'], 'name="media2x_id"') && str_contains($r['body'], 'Bez obrázku 2×'));
    $bp = ['akce' => 'ulozit', 'name' => 'leaderboard', 'media_id' => $app->bannery()->radek($b728)['media_id'], 'target_url' => 'https://kazbo.eu/l', 'target' => '_blank',
        'status' => 'active', 'weight' => 1, 'kampane' => [0, $kid], 'media2x_id' => ''];
    $r = $b->jdi("index.php?p=banner&id=$b728", $bp + ['zarizeni' => ['']]);
    ok(str_contains($r['body'], 'Vyberte aspoň jedno zařízení'));
    eq(303, $b->jdi("index.php?p=banner&id=$b728", $bp + ['zarizeni' => ['', 'pocitac', 'tablet']])['code']);
    eq('pocitac,tablet', $app->bannery()->radek($b728)['devices']);
    eq(303, $b->jdi("index.php?p=banner&id=$b728", $bp + ['zarizeni' => ['', 'pocitac', 'tablet', 'mobil']])['code']);
    eq(null, $app->bannery()->radek($b728)['devices']);
    $r = $b->jdi("index.php?p=banner&id=$b300");
    ok(preg_match('~<option value="\d+" selected>ctverec@2x\.png \(600×500\)</option>~', $r['body']) === 1, 'vybraný obrázek 2×');
});

test('JSON: šířka místa vybere rozměr, odkaz nese zařízení, proklik se počítá stejnému zařízení; b.php?x=2', function () use ($b, $s, $app, $zid, $b970, $b728, $b300, $m2x) {
    $j = fn(string $q) => json_decode($b->jdi("d/data.php?$q")['body'], true)['zony'];
    eq([$b970, $b728, $b300], array_map(fn($z) => $z['banner']['id'] ?? null, [
        $j("zona=$zid&sirka=1100&sw=1280")[0], $j("zona=$zid&sirka=800&sw=1280")[0], $j("zona=$zid&sirka=360&sw=390")[0]]));
    $z = $j("zony=$zid,$zid,$zid&sirky=1100,1100,1100&sw=1280");
    eq([$b970, $b728, $b300], array_map(fn($x) => $x['banner']['id'] ?? null, $z), 'na jedné stránce se banner neopakuje: další místo dostane menší rozměr');
    eq(null, $j("zona=$zid&sirka=200")[0]['banner'], 'do 200 px se nic nevejde');
    $m = $j("zona=$zid&sirka=360&sw=390")[0];
    eq('mobil', $m['banner']['zarizeni']);
    ok(str_contains($m['html'], 'srcset=') && str_contains((string)$m['banner']['obrazek2x'], '&x=2'), $m['html']);
    ok(str_contains((string)$m['banner']['klik'], '&d=mobil'));
    // proklik z mobilního zobrazení, prohlížeč počítače
    $pred = (int)$app->db->q("SELECT COALESCE(SUM(clicks), 0) FROM stats WHERE device = 'mobil'")->fetchColumn();
    $r = $b->jdi(substr((string)$m['banner']['klik'], strlen($s->url) + 1));
    eq(302, $r['code']);
    eq($pred + 1, (int)$app->db->q("SELECT COALESCE(SUM(clicks), 0) FROM stats WHERE device = 'mobil'")->fetchColumn());
    // obrázek 2× a hlavní obrázek
    $r2 = $b->jdi(substr((string)$m['banner']['obrazek2x'], strlen($s->url) + 1));
    eq([200, 'image/png'], [$r2['code'], $r2['type']]);
    eq([600, 500], array_slice(getimagesizefromstring($r2['body']) ?: [], 0, 2));
    $r1 = $b->jdi(substr((string)$m['banner']['obrazek'], strlen($s->url) + 1));
    eq([300, 250], array_slice(getimagesizefromstring($r1['body']) ?: [], 0, 2));
    // statistiky po zařízeních
    $r = $b->jdi('index.php?p=statistiky&podle=zarizeni');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<th>Zařízení</th>') && str_contains($r['body'], 'Mobil') && str_contains($r['body'], 'zarizeni=mobil'), 'tabulka a odkaz po dnech');
    $r = $b->jdi('index.php?p=statistiky&zarizeni=mobil&csv=1');
    ok(str_contains($r['body'], 'Zobrazení'));
});

// ---------- skutečný prohlížeč: stejná stránka na počítači a na mobilu ----------
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: na počítači billboard a v postranním sloupci čtverec, na mobilu čtverec 2×', function () use ($pw, $app, $zid, $b970, $b300) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $web = docasna('stranka9');
    mkdir($web);
    $kod = (new Kody($app))->kod($app->zony()->radek($zid), 'asyn');
    file_put_contents("$web/index.html", "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>Test</title>"
        . "<style>body{margin:0} #hlavni{max-width:1100px} #bok{width:320px}</style></head><body>\n"
        . '<div id="hlavni">' . $kod . "</div>\n<div id=\"bok\">" . $kod . "</div>\n</body></html>");
    $port = (int)substr(strrchr(stream_socket_get_name($sock = stream_socket_server('tcp://127.0.0.1:0'), false), ':'), 1);
    fclose($sock);
    $proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $web], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    usleep(400000);
    $skript = docasna('pw9') . '.js';
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch();
  const ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
  const uaMobil = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';
  const v = {};
  for (const [nazev, o] of [['pc', { userAgent: ua, viewport: { width: 1280, height: 900 } }], ['mobil', { userAgent: uaMobil, viewport: { width: 390, height: 800 }, deviceScaleFactor: 3, isMobile: true }]]) {
    const ctx = await b.newContext(o);
    const p = await ctx.newPage();
    await p.goto('http://127.0.0.1:$port/index.html');
    await p.waitForFunction(() => [...document.querySelectorAll('ins[data-twbr-zona]')].every(e => e.dataset.twbrStav && e.dataset.twbrStav !== 'nacita'), null, { timeout: 10000 });
    await p.waitForFunction(() => [...document.querySelectorAll('ins img')].every(i => i.complete), null, { timeout: 10000 });
    v[nazev] = await p.\$\$eval('ins[data-twbr-zona]', l => l.map(e => {
      const i = e.querySelector('img');
      return i ? [i.getAttribute('width') + 'x' + i.getAttribute('height'), Math.round(i.getBoundingClientRect().width), i.naturalWidth, i.currentSrc.includes('x=2')] : null;
    }));
    await ctx.close();
  }
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    proc_terminate($proc);
    proc_close($proc);
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    eq([['970x250', 970, 970, false], ['300x250', 300, 300, false]], $v['pc'], 'počítač: billboard v hlavním sloupci, čtverec v úzkém postranním (nic se nezmenšuje)');
    eq(['300x250', 300, 300, true], $v['mobil'][0], 'mobil: čtverec v plné ostrosti (prohlížeč si vzal obrázek 2×), ne zmenšený billboard');
    eq(null, $v['mobil'][1], 'druhé místo na mobilu: čtverec už na stránce je, jiný rozměr se do 320 px nevejde');
    ok((int)$app->db->q("SELECT COALESCE(SUM(impressions), 0) FROM stats WHERE device = 'pocitac' AND banner_id IN ($b970, $b300)")->fetchColumn() >= 2, 'zobrazení na počítači');
    ok((int)$app->db->q("SELECT COALESCE(SUM(impressions), 0) FROM stats WHERE device = 'mobil' AND banner_id = $b300")->fetchColumn() >= 1, 'zobrazení na mobilu');
});

test('stránka zóny: prázdný rozměr v tabulce a v poli', function () use ($b, $app, $web) {
    $id = $app->zony()->uloz(['site_id' => $web, 'name' => 'S mezerou', 'sizes' => '970x250, 728x0, 300x250']);
    $r = $b->jdi("index.php?p=zona&id=$id");
    ok(str_contains($r['body'], 'rozměr responzivní 970×250, 728 px nic, 300×250'));
    ok(str_contains($r['body'], 'value="970x250, 728x0, 300x250"'));
    ok(str_contains($r['body'], '<td><b>prázdné</b></td>') && str_contains($r['body'], '<td>728–969 px</td>'), 'řádek prázdného rozměru');
    ok(str_contains($r['body'], '<td>300–727 px</td>'));
});
