<?php
declare(strict_types=1);
// TWB Reklamy 0.4: proklik s tokenem twbr, cookie návštěvníka, pixel a postback konverze, stránky trackerů, t.js ve skutečném prohlížeči.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\App;

echo "Reklamy: konverze na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$app = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$app->migruj();
$app->heslo()->nastav('tajneheslo1');
$web = $app->weby()->uloz(['name' => 'Web']);
$zona = $app->zony()->uloz(['site_id' => $web, 'name' => 'Čtverec', 'width' => 300, 'height' => 250]);
$kid = kampan($app, ['name' => 'Eshop']);
$banner = htmlBanner($app, $kid, 300, 250, ['target_url' => 'http://obchod.test/akce?utm_source=twb#nahore']);
$app->zony()->prirad($zona, [$kid], []);
$admin = new Prohlizec($s);
$navstevnik = new Prohlizec($s);
$stat = fn(string $co) => (int)$app->db->q("SELECT COALESCE(SUM($co), 0) FROM stats WHERE banner_id = $banner")->fetchColumn();
$tracker = 0;
$token = '';

test('stránka Konverze: nový tracker, kódy pro web, úprava', function () use ($admin, $app, $kid, &$tracker) {
    $admin->jdi('index.php');
    eq(303, $admin->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1'])['code']);
    $r = $admin->jdi('index.php?p=konverze');
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Zatím žádný tracker'));
    $r = $admin->odesli('index.php?p=konverze', ['akce' => 'novy', 'name' => 'Objednávka', 'kind' => 'sale']);
    $tracker = (int)$app->db->q("SELECT id FROM trackers WHERE name = 'Objednávka'")->fetchColumn();
    ok($tracker > 0);
    ok(str_contains($r['presmerovano'], "p=tracker&id=$tracker"), $r['presmerovano']);
    ok(str_contains($r['body'], '/d/t.js') && str_contains($r['body'], "tracker: $tracker"), 'kódy trackeru');
    ok(str_contains($r['body'], 'Tracker vytvořen'));
    $r = $admin->odesli("index.php?p=tracker&id=$tracker", ['akce' => 'ulozit', 'name' => 'Objednávka', 'kind' => 'sale', 'status' => 'active',
        'value_default' => '150', 'click_days' => '30', 'view_days' => '0', 'kampane' => [$kid], 'note' => '']);
    ok(str_contains($r['body'], 'Uloženo'), mb_substr(strip_tags($r['body']), 0, 400));
    eq([15000, [$kid]], [(int)$app->konverze()->radek($tracker)['value_default'], $app->konverze()->kampane($tracker)]);
    $r = $admin->jdi("index.php?p=tracker&id=$tracker", ['akce' => 'ulozit', 'name' => 'Objednávka', 'click_days' => '0']);
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Okno po prokliku'), 'chyba formuláře');
    eq(404, $admin->jdi('index.php?p=tracker&id=999')['code']);
    eq(303, $admin->jdi("index.php?p=tracker&id=$tracker", ['akce' => 'smazat', 'csrf' => 'x'])['code']);
    eq('Objednávka', $app->konverze()->radek($tracker)['name'], 'bez platného CSRF se nesmaže');
    $admin->jdi("index.php?p=tracker&id=$tracker");
});

test('proklik: token twbr v cílové adrese a cookie návštěvníka', function () use ($navstevnik, $app, $banner, $zona, &$token) {
    $klik = $app->vykresleni()->klik($app->bannery()->radek($banner), $zona);
    $r = $navstevnik->jdi($klik);
    eq(302, $r['code']);
    ok(preg_match('~^http://obchod\.test/akce\?utm_source=twb&twbr=(\d+\.[a-f0-9]{10})#nahore$~', (string)$r['location'], $m) === 1, (string)$r['location']);
    $token = $m[1];
    ok(preg_match('/Set-Cookie: twbr_v=[a-f0-9]{32};[^\r\n]*HttpOnly/i', $navstevnik->posledniHlavicky) === 1, $navstevnik->posledniHlavicky);
    ok(str_contains($navstevnik->posledniHlavicky, 'SameSite=Lax'), 'na HTTP Lax (na HTTPS None; Secure)');
    $navstevnik->jdi($klik);
    ok(!str_contains($navstevnik->posledniHlavicky, 'twbr_v='), 'známý návštěvník novou cookie nedostane');
    eq(1, (int)$app->db->q('SELECT COUNT(DISTINCT visitor) FROM clicks')->fetchColumn(), 'oba prokliky má stejný návštěvník');
});

test('konverze.php: postback s tokenem (JSON), pixel podle cookie, duplicita, statistika', function () use ($navstevnik, $s, $app, $banner, $stat, &$tracker, &$token) {
    $cizi = new Prohlizec($s);   // server e-shopu: bez cookie, jen token
    $r = $cizi->jdi("d/konverze.php?t=$tracker&twbr=$token&hodnota=" . rawurlencode('1 299,90') . '&id=A-1&format=json');
    eq([200, 'application/json'], [$r['code'], $r['type']]);
    eq(['stav' => 'ok', 'via' => 'click', 'banner' => $banner], json_decode($r['body'], true));
    ok(str_contains($cizi->posledniHlavicky, 'no-store'));
    eq('duplicita', json_decode($cizi->jdi("d/konverze.php?t=$tracker&twbr=$token&id=A-1&format=json")['body'], true)['stav']);
    // pixel v prohlížeči návštěvníka: přiřazení podle cookie twbr_v, výchozí hodnota trackeru
    $r = $navstevnik->jdi("d/konverze.php?t=$tracker&id=A-2");
    eq(['image/gif', 43], [$r['type'], strlen($r['body'])]);
    $c = $app->db->q("SELECT * FROM conversions WHERE ref = 'A-2'")->fetch();
    eq(['click', $banner, 15000], [$c['via'], (int)$c['banner_id'], (int)$c['value']]);
    eq([2, 129990 + 15000], [$stat('conversions'), $stat('revenue')]);
    eq(['stav' => 'chyba', 'via' => '', 'banner' => null], json_decode($cizi->jdi('d/konverze.php?t=abc&format=json')['body'], true));
    eq('neaktivni', json_decode($cizi->jdi('d/konverze.php?t=999&format=json')['body'], true)['stav']);
    eq('ok', json_decode($cizi->jdi("d/konverze.php?t=$tracker&hodnota=xyz&format=json")['body'], true)['stav'], 'špatná hodnota = výchozí, konverze se neztratí');
});

test('přehled konverzí a trackeru ukazuje počty a poslední konverze', function () use ($admin, $kid, &$tracker) {
    $r = $admin->jdi("index.php?p=kampan&id=$kid");
    ok(str_contains($r['body'], 'Hodnota konverzí') && str_contains($r['body'], '1 449,90 Kč'), 'dlaždice konverzí kampaně');
    $r = $admin->jdi('index.php?p=konverze');
    ok(str_contains($r['body'], 'Objednávka') && str_contains($r['body'], '1 299,90 Kč'), 'poslední konverze s hodnotou');
    ok(str_contains($r['body'], 'nepřiřazená'));
    $r = $admin->jdi("index.php?p=tracker&id=$tracker");
    ok(str_contains($r['body'], 'A-2') && str_contains($r['body'], 'po prokliku'));
});

test('nastavení měření: bez parametru twbr a bez cookies', function () use ($admin, $s, $app, $banner, $zona) {
    $admin->jdi('index.php?p=nastaveni');
    $r = $admin->odesli('index.php?p=nastaveni', ['akce' => 'mereni']);
    ok(str_contains($r['body'], 'Uloženo') || $r['code'] === 200);
    eq(['0', '0'], [$app->nastaveni()->dej('visitor_cookies'), $app->nastaveni()->dej('click_param')]);
    $novy = new Prohlizec($s);
    $r = $novy->jdi($app->vykresleni()->klik($app->bannery()->radek($banner), $zona));
    eq('http://obchod.test/akce?utm_source=twb#nahore', $r['location']);
    ok(!str_contains($novy->posledniHlavicky, 'twbr_v='), 'bez cookie návštěvníka');
    eq(1, (int)$app->db->q('SELECT COUNT(*) FROM clicks WHERE visitor IS NULL')->fetchColumn(), 'proklik se uloží bez návštěvníka');
    $r = $admin->odesli('index.php?p=nastaveni', ['akce' => 'mereni', 'visitor_cookies' => '1', 'click_param' => '1']);
    ok(str_contains($r['body'], 'Uloženo'));
    eq(['1', '1'], $s->db()->query("SELECT v FROM settings WHERE k IN ('click_param', 'visitor_cookies') ORDER BY k")->fetchAll(PDO::FETCH_COLUMN));
});

test('cílení a frekvence z formuláře kampaně; zóna respektuje frekvenci podle cookie', function () use ($admin, $s, $app, $kid, $zona, $banner) {
    $admin->jdi("index.php?p=kampan&id=$kid");
    $r = $admin->odesli("index.php?p=kampan&id=$kid", ['name' => 'Eshop', 'kind' => 'standard', 'status' => 'active', 'priority' => '5', 'weight' => '1',
        'cil_zarizeni' => ['pocitac'], 'cil_jazyky' => 'cs', 'cap_impressions' => '1', 'cap_hours' => '12']);
    $k = $app->kampane()->radek($kid);
    eq(['{"zarizeni":["pocitac"],"jazyky":["cs"]}', 1, 12], [$k['targeting'], (int)$k['cap_impressions'], (int)$k['cap_hours']], mb_substr(strip_tags($r['body']), 0, 300));
    $r = $admin->jdi("index.php?p=kampan&id=$kid");
    ok(preg_match('/name="cil_zarizeni\[\]" value="pocitac"[^>]*checked/', $r['body']) === 1, 'formulář ukáže uložené cílení');
    $n = new Prohlizec($s);
    $n->hlavicky = ['Accept-Language: en-US,en;q=0.9'];
    eq([], array_filter(array_column(json_decode($n->jdi("d/data.php?zona=$zona")['body'], true)['zony'], 'banner')), 'jiný jazyk nedostane nic');
    $n->hlavicky = ['Accept-Language: cs-CZ,cs;q=0.9'];
    eq($banner, json_decode($n->jdi("d/data.php?zona=$zona")['body'], true)['zony'][0]['banner']['id']);
    ok(preg_match('/Set-Cookie: twbr_f=' . $kid . '-1-\d+/', $n->posledniHlavicky) === 1, $n->posledniHlavicky);
    $n->hlavicky = ['Accept-Language: cs-CZ,cs;q=0.9'];
    eq(null, json_decode($n->jdi("d/data.php?zona=$zona")['body'], true)['zony'][0]['banner'] ?? null, 'frekvence 1× za 12 h vyčerpána');
    $app->kampane()->uloz(['name' => 'Eshop'], $kid);
});

// ---------- skutečný prohlížeč: proklik → web inzerenta s t.js → konverze až na další stránce ----------
$node = trim((string)shell_exec('command -v node'));
$pw = $node !== '' && trim((string)shell_exec('node -e "require(\'playwright\'); console.log(1)" 2>/dev/null')) === '1';
test('ve skutečném prohlížeči: t.js si zapamatuje twbr a konverze se přiřadí i bez cookies třetích stran', function () use ($pw, $s, $app, $banner, $zona, $stat, &$tracker) {
    if (!$pw) { echo "      (přeskočeno: chybí node nebo playwright)\n"; return; }
    $pred = $stat('conversions');
    $skript = docasna('pw') . '.js';
    $url = $s->url;
    $klik = $app->vykresleni()->klik($app->bannery()->radek($banner), $zona);
    file_put_contents($skript, <<<JS
const { chromium } = require('playwright');
(async () => {
  // web inzerenta je „veřejná“ doména, reklamní server běží na 127.0.0.1: Chrome by požadavky z veřejné stránky na místní adresu blokoval
  const b = await chromium.launch({ args: ['--disable-features=BlockInsecurePrivateNetworkRequests,PrivateNetworkAccessSendPreflights,LocalNetworkAccessChecks'] });
  const ctx = await b.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
  const p = await ctx.newPage();
  const chyby = [];
  p.on('pageerror', e => chyby.push(e.message));
  // web inzerenta (jiná doména než reklamní server)
  await ctx.route('http://obchod.test/**', r => {
    const u = new URL(r.request().url());
    const html = u.pathname === '/dekujeme'
      ? '<!doctype html><title>Děkujeme</title><script>window.twbr = window.twbr || []; twbr.push({ tracker: $tracker, hodnota: "499.00", id: "PW-1" });</script><script async src="$url/d/t.js"></script>'
      : '<!doctype html><title>Akce</title><script async src="$url/d/t.js"></script>';
    r.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: html });
  });
  const v = {};
  // Playwright nezachytí přesměrování do vymyšlené domény: proklik projde bez přesměrování a prohlížeč jde na jeho cíl
  const k = await ctx.request.get('$klik', { maxRedirects: 0 });
  await p.goto(k.headers()['location']);
  v.adresa = p.url();
  await p.waitForFunction(() => { try { return !!localStorage.getItem('twbr'); } catch (e) { return false; } }, null, { timeout: 10000 });
  v.ulozeno = await p.evaluate(() => localStorage.getItem('twbr').split('|')[0]);
  const odpoved = p.waitForResponse(r => r.url().includes('/d/konverze.php'), { timeout: 10000 });
  await p.goto('http://obchod.test/dekujeme');
  const o = await odpoved;
  v.konverze = [o.status(), o.headers()['content-type'], o.url()];
  v.chyby = chyby;
  console.log(JSON.stringify(v));
  await b.close();
})().catch(e => { console.log(JSON.stringify({ chyba: String(e) })); process.exit(1); });
JS);
    $out = (string)shell_exec('node ' . escapeshellarg($skript) . ' 2>&1');
    $v = json_decode(trim((string)strrchr("\n" . trim($out), "\n")), true);
    ok(is_array($v) && !isset($v['chyba']), $out);
    ok(preg_match('~^http://obchod\.test/akce\?utm_source=twb&twbr=(\d+\.[a-f0-9]{10})#nahore$~', $v['adresa'], $m) === 1, $v['adresa']);
    eq($m[1], $v['ulozeno']);
    eq([200, 'image/gif'], [$v['konverze'][0], $v['konverze'][1]]);
    ok(str_contains($v['konverze'][2], 'twbr=' . rawurlencode($m[1])) && str_contains($v['konverze'][2], 'id=PW-1'), $v['konverze'][2]);
    $c = $app->db->q("SELECT * FROM conversions WHERE ref = 'PW-1'")->fetch();
    eq(['click', $banner, 49900], [$c['via'] ?? null, (int)($c['banner_id'] ?? 0), (int)($c['value'] ?? 0)]);
    eq($pred + 1, $stat('conversions'));
    eq([], $v['chyby']);
});
