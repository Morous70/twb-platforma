<?php
declare(strict_types=1);
// TWB Reklamy 0.2: výběr banneru, váhy, limity, počítání, vykreslení, makra, XML-RPC, kódy pro vložení.
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Dorucovani, Kontext, Vykresleni, XmlRpc, Kody};

echo "Reklamy: doručování\n";

const PROHLIZEC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

function kontext(array $v = []): Kontext { return new Kontext(prohlizec: $v['ua'] ?? PROHLIZEC, cas: $v['cas'] ?? time(), pocitat: $v['pocitat'] ?? true); }

/** Web se zónou; vrací [app, zona(array)]. */
function zona(App $app, array $v = []): array
{
    $web = $app->weby()->uloz(['name' => 'Web']);
    $id = $app->zony()->uloz($v + ['site_id' => $web, 'name' => 'Zóna']);
    return $app->zony()->radek($id);
}

test('kandidáti: jen aktivní bannery aktivních kampaní v termínu, vhodného rozměru, přiřazené zóně', function () {
    $app = novaApp();
    $z = zona($app, ['width' => 300, 'height' => 250]);
    $ok = kampan($app);
    $b1 = htmlBanner($app, $ok, 300, 250);
    $pauza = htmlBanner($app, $ok, 300, 250, ['status' => 'paused']);
    $jinyRozmer = htmlBanner($app, $ok, 728, 90);
    $pozastavena = kampan($app, ['status' => 'paused']); htmlBanner($app, $pozastavena, 300, 250);
    $budouci = kampan($app, ['starts_at' => date('Y-m-d\TH:i', time() + 3600)]); htmlBanner($app, $budouci, 300, 250);
    $skoncila = kampan($app, ['ends_at' => date('Y-m-d\TH:i', time() - 60)]); htmlBanner($app, $skoncila, 300, 250);
    $mimo = kampan($app); htmlBanner($app, $mimo, 300, 250);
    $app->zony()->prirad((int)$z['id'], [$ok, $pozastavena, $budouci, $skoncila], []);
    $k = kontext();
    eq([$b1], array_map(fn($b) => (int)$b['id'], $app->dorucovani()->kandidati($z, $k)));
    $k->vynechat[$b1] = true;
    eq([], $app->dorucovani()->kandidati($z, $k), 'banner, který už na stránce je, se neopakuje');
    eq(null, $app->dorucovani()->vyber($z, $k));
    eq([], $app->dorucovani()->kandidati($z, kontext(), ['image', 'external']), 'filtr druhu banneru');
});

test('druh a priorita: exkluzivní před standardní, zbytková jen bez jiných, vyšší priorita vyhrává', function () {
    $app = novaApp();
    $z = zona($app);
    $d = $app->dorucovani();
    $zbytek = kampan($app, ['kind' => 'remnant', 'priority' => 10]); $bz = htmlBanner($app, $zbytek, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$zbytek], []);
    eq($bz, (int)$d->vyber($z, kontext())['id']);
    $std5 = kampan($app, ['priority' => 5]); $b5 = htmlBanner($app, $std5, 1, 1);
    $std7 = kampan($app, ['priority' => 7]); $b7 = htmlBanner($app, $std7, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$zbytek, $std5, $std7], []);
    for ($i = 0; $i < 20; $i++) eq($b7, (int)$d->vyber($z, kontext())['id']);
    $exk = kampan($app, ['kind' => 'exclusive', 'priority' => 1]); $be = htmlBanner($app, $exk, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$zbytek, $std5, $std7, $exk], []);
    eq($be, (int)$d->vyber($z, kontext())['id']);
    // na stránce už je exkluzivní → další zóna dostane standardní s nejvyšší prioritou
    $k = kontext();
    $d->vyber($z, $k);
    eq($b7, (int)$d->vyber($z, $k)['id']);
    eq($b5, (int)$d->vyber($z, $k)['id']);
    eq($bz, (int)$d->vyber($z, $k)['id']);
});

test('los podle vah kampaní a bannerů', function () {
    $app = novaApp();
    $z = zona($app);
    $a = kampan($app, ['weight' => 1]); $a1 = htmlBanner($app, $a, 1, 1); $a2 = htmlBanner($app, $a, 1, 1, ['weight' => 3]);
    $b = kampan($app, ['weight' => 2]); $b1 = htmlBanner($app, $b, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$a, $b], []);
    // projdeme všechna čísla losu: podíly musí být A1 : A2 : B1 = 1/3·1/4 : 1/3·3/4 : 2/3 = 1 : 3 : 8
    $kand = $app->dorucovani()->kandidati($z, kontext());
    $pocty = [];
    $x = 0;
    $d = new Dorucovani($app, function (int $n) use (&$x, &$celkem) { $celkem = $n; return $x; });
    $d->losuj($kand);
    for ($x = 0; $x < $celkem; $x++) { $id = (int)$d->losuj($kand)['id']; $pocty[$id] = ($pocty[$id] ?? 0) + 1; }
    $j = $celkem / 12;
    eq([$a1 => $j, $a2 => 3 * $j, $b1 => 8 * $j], $pocty);
});

test('limity kampaně celkem a za den', function () {
    $app = novaApp();
    $z = zona($app);
    $d = $app->dorucovani();
    $kid = kampan($app, ['limit_impressions' => 3]);
    $bid = htmlBanner($app, $kid, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$kid], []);
    for ($i = 0; $i < 3; $i++) { ok($d->vyber($z, kontext()) !== null, "zobrazení $i"); $d->zobrazeni($bid, (int)$z['id'], kontext()); }
    eq(null, $d->vyber($z, kontext()), 'po vyčerpání limitu nic');
    $k2 = kampan($app, ['daily_clicks' => 2]);
    $b2 = htmlBanner($app, $k2, 1, 1);
    $app->zony()->prirad((int)$z['id'], [$k2], []);
    $vcera = kontext(['cas' => time() - 86400]);
    $d->proklik($b2, (int)$z['id'], $vcera); $d->proklik($b2, (int)$z['id'], $vcera);
    ok($d->vyber($z, kontext()) !== null, 'včerejší prokliky se do denního limitu nepočítají');
    $d->proklik($b2, (int)$z['id'], kontext()); $d->proklik($b2, (int)$z['id'], kontext());
    eq(null, $d->vyber($z, kontext()));
    eq(['impressions' => 0, 'clicks' => 4, 'conversions' => 0], $d->pocty($k2, null));
});

test('počítání: po hodinách, roboti a náhled se nepočítají', function () {
    $app = novaApp();
    $d = $app->dorucovani();
    $t = mktime(14, 5, 0, 11, 21, 2026);
    $d->zobrazeni(7, 3, kontext(['cas' => $t]));
    $d->zobrazeni(7, 3, kontext(['cas' => $t + 60]));
    $d->zobrazeni(7, 3, kontext(['cas' => $t + 3600]));
    $d->proklik(7, 3, kontext(['cas' => $t]));
    $d->zobrazeni(7, 3, kontext(['cas' => $t, 'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']));
    $d->zobrazeni(7, 3, kontext(['cas' => $t, 'ua' => '']));
    $d->zobrazeni(7, 3, kontext(['cas' => $t, 'pocitat' => false]));
    $d->konverze(7, 3, $t, 12345);
    eq([['2026-11-21', 14, 2, 1, 1, 12345], ['2026-11-21', 15, 1, 0, 0, 0]],
        array_map('array_values', $app->db->q('SELECT day, hour, impressions, clicks, conversions, revenue FROM stats ORDER BY hour')->fetchAll()));
});

test('podpisy odkazů a vložení odkazem (n → banner)', function () {
    $app = novaApp();
    $d = $app->dorucovani();
    $p = $d->podpis('klik|1|2');
    eq(16, strlen($p));
    ok($d->overPodpis('klik|1|2', $p));
    ok(!$d->overPodpis('klik|1|3', $p));
    ok(!$d->overPodpis('klik|1|2', null));
    eq($p, (new Dorucovani($app))->podpis('klik|1|2'), 'tajemství je uložené');
    $d->zapamatuj('abc', 1, 5, time());
    $d->zapamatuj('abc', 1, 6, time());
    $d->zapamatuj('abc', 2, 9, time() - 40 * 86400);
    eq(6, $d->zapamatovany('abc', 1));
    eq(1, $d->uklid());
    eq(null, $d->zapamatovany('abc', 2));
});

test('vykreslení: obrázek s odkazem, bez cíle bez odkazu, HTML v rámu, makra', function () {
    $app = novaApp(['base_url' => 'https://ads.kazbo.eu']);
    $kid = kampan($app);
    $m = $app->galerie()->uloz(pngSoubor(300, 250), 'a.png');
    $bid = $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'Obr', 'media_id' => $m['id'], 'target_url' => 'https://kazbo.eu/', 'alt' => 'Sleva "50 %"']);
    $v = $app->vykresleni();
    $b = $app->bannery()->radek($bid);
    $html = $v->html($b, 4);
    ok(str_contains($html, 'href="https://ads.kazbo.eu/d/klik.php?b=' . $bid . '&amp;z=4&amp;k='), $html);
    ok(str_contains($html, 'src="https://ads.kazbo.eu/d/b.php?b=' . $bid . '&amp;v='), $html);
    ok(str_contains($html, 'alt="Sleva &quot;50 %&quot;"') && str_contains($html, 'width="300" height="250"'));
    ok(str_contains($html, 'rel="sponsored noopener"'));
    $app->bannery()->uloz(['name' => 'Obr', 'media_id' => $m['id']], $bid);
    ok(!str_contains($v->html($app->bannery()->radek($bid), 4), '<a '), 'bez cílové adresy není odkaz');
    $hid = htmlBanner($app, $kid, 728, 90, ['html' => '<a href="{clickurl}">A</a><a href="{clickurl}https://kazbo.eu/x">B</a> {random} {bannerid} {zoneid} {width}x{height} {targeturl}', 'target_url' => 'https://kazbo.eu/']);
    $h = $app->bannery()->radek($hid);
    ok(str_contains($v->html($h, 4), '<iframe src="https://ads.kazbo.eu/d/b.php?b=' . $hid . '&amp;z=4&amp;k='), 'HTML banner v rámu');
    $dok = $v->dokumentHtml($h, 4);
    ok(str_starts_with($dok, '<!doctype html>') && str_contains($dok, '<base target="_blank">'));
    ok(preg_match('~href="https://ads\.kazbo\.eu/d/klik\.php\?b=' . $hid . '&z=4&k=[0-9a-f]{16}&cil=">A</a>~', $dok) === 1, $dok);
    ok(str_contains($dok, '&cil=https://kazbo.eu/x">B</a>'));
    ok(preg_match('~</a> \d{9} ' . $hid . ' 4 728x90 https://kazbo\.eu/~', $dok) === 1, $dok);
    $plny = htmlBanner($app, $kid, 1, 1, ['html' => '<html><body>{zoneid}</body></html>']);
    eq('<html><body>9</body></html>', $v->dokumentHtml($app->bannery()->radek($plny), 9), 'úplný dokument se nebalí');
});

test('povolený cíl prokliku HTML banneru', function () {
    $b = ['target_url' => 'https://kazbo.eu/akce', 'html' => '<a href="{clickurl}https://partner.cz/produkt?id=1&amp;x=2">x</a>', 'content' => null];
    ok(Vykresleni::povolenyCil($b, 'https://kazbo.eu/jina-stranka'), 'stejná doména jako cíl');
    ok(Vykresleni::povolenyCil($b, 'https://partner.cz/produkt?id=1&x=2'), 'adresa uvedená v HTML');
    ok(!Vykresleni::povolenyCil($b, 'https://partner.cz/jinde'));
    ok(!Vykresleni::povolenyCil($b, 'https://zlo.example/'));
    ok(!Vykresleni::povolenyCil($b, 'javascript:alert(1)'));
    ok(!Vykresleni::povolenyCil(['target_url' => null, 'html' => ''], 'https://kazbo.eu/'));
});

test('XML-RPC: čtení volání a zápis odpovědi', function () {
    $xml = XmlRpc::volani('openads.view', [['remote_addr' => '1.2.3.4', 'user_agent' => 'X <y>'], 'zone:5', 0, '', '', false, []]);
    [$m, $p] = XmlRpc::ctiVolani($xml);
    eq('openads.view', $m);
    eq([['remote_addr' => '1.2.3.4', 'user_agent' => 'X <y>'], 'zone:5', 0, '', '', false, []], $p);
    [, $p] = XmlRpc::ctiVolani('<?xml version="1.0"?><methodCall><methodName>x</methodName><params><param><value>bez typu</value></param>'
        . '<param><value><i4>7</i4></value></param><param><value><double>1.5</double></value></param><param><value><base64>QUhPSg==</base64></value></param></params></methodCall>');
    eq(['bez typu', 7, 1.5, 'AHOJ'], $p);
    throws(fn() => XmlRpc::ctiVolani('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><methodCall><methodName>&e;</methodName></methodCall>'), 'DOCTYPE');
    throws(fn() => XmlRpc::ctiVolani('<a/>'), 'Neplatné');
    $o = XmlRpc::odpoved(['html' => '<a href="x?a=1&b=2">č</a>', 'bannerid' => 3, 'ok' => true]);
    $d = new DOMDocument();
    ok($d->loadXML($o), 'platné XML');
    ok(str_contains($o, '<name>html</name><value><string>&lt;a href="x?a=1&amp;b=2"&gt;č&lt;/a&gt;</string></value>'), $o);
    ok(str_contains(XmlRpc::chyba(3, 'Neznámá'), '<name>faultCode</name><value><int>3</int></value>'));
});

test('kódy pro vložení: všechny způsoby, PHP kód je platný', function () {
    $app = novaApp(['base_url' => 'https://ads.kazbo.eu']);
    $z = zona($app, ['width' => 728, 'height' => 90]);
    $k = new Kody($app);
    foreach (array_keys(Kody::ZPUSOBY) as $zp) {
        $kod = $k->kod($z, $zp);
        ok(str_contains($kod, 'https://ads.kazbo.eu/d/'), $zp);
        ok(str_contains($kod, (string)$z['id']), $zp);
    }
    ok(str_contains($k->kod($z, 'asyn'), '<ins data-twbr-zona="' . $z['id'] . '" style="display:inline-block;width:728px;height:90px"></ins>'));
    ok(str_contains($k->kod($z, 'odkaz'), 'width="728" height="90"'));
    foreach (['php', 'xmlrpc'] as $zp) {
        $f = docasna('kod') . '.php';
        file_put_contents($f, $k->kod($z, $zp));
        exec('php -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
        eq(0, $rc, "$zp: " . implode("\n", $out));
        @unlink($f);
    }
    throws(fn() => $k->kod($z, 'flash'), 'Neznámý způsob');
});

test('převod databáze z 0.1: doručování si ho provede samo', function () {
    $app = novaApp();
    $app->db->q('DROP TABLE stats');
    $app->db->q('DROP TABLE view_map');
    $app->db->q("UPDATE module_versions SET version = 1 WHERE module = 'reklamy'");
    $app->nastaveni()->uloz('schema_version', '1');
    $app2 = new App(KOREN . '/apps/reklamy', ['data_dir' => $app->data]);
    eq(true, $app2->pripravDoruceni());
    eq('2', $app2->nastaveni()->dej('schema_version'));
    $app2->db->q('SELECT COUNT(*) FROM stats')->fetchColumn();
    $app2->db->q('SELECT COUNT(*) FROM view_map')->fetchColumn();
});
