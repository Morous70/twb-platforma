<?php
declare(strict_types=1);
// TWB Reklamy 0.9: reklamy podle zařízení – responzivní zóny, banner jen pro některá zařízení, statistiky po zařízeních, obrázek 2×.
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Schema, Kontext, Zony, Bannery, Kody, Vydej, Statistiky};
use TWB\Jadro\{Db, Migrace};

echo "Reklamy: reklamy podle zařízení\n";

const UA_POCITAC = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const UA_MOBIL = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const UA_TABLET = 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

function kontext9(array $v = []): Kontext
{
    return new Kontext(prohlizec: $v['ua'] ?? UA_POCITAC, cas: $v['cas'] ?? time(), sirkaOkna: $v['sw'] ?? 0, zarizeniOdkazu: $v['d'] ?? '');
}

function zona9(App $app, array $v = []): array
{
    $web = $app->weby()->uloz(['name' => 'Web']);
    return $app->zony()->radek($app->zony()->uloz($v + ['site_id' => $web, 'name' => 'Zóna']));
}

/** Databáze ve stavu verze 0.8 (zmrazené schéma tests/reklamy/original/Schema-0.8.php) s daty. */
function databaze08(): string
{
    if (!class_exists('Reklamy08\\Schema', false)) {
        $kod = (string)file_get_contents(__DIR__ . '/original/Schema-0.8.php');
        eval(str_replace(['namespace Reklamy;', '<?php'], ['namespace Reklamy08;', ''], $kod));
    }
    $dir = docasna('v08');
    mkdir($dir, 0775, true);
    $db = Db::pripoj('sqlite:' . $dir . '/reklamy.sqlite');
    (new Migrace($db))->spust('reklamy', 'Reklamy08\\Schema');
    $t = time();
    $db->q("INSERT INTO campaigns (name, created_at, updated_at) VALUES ('Advent', $t, $t)");
    $db->q("INSERT INTO banners (campaign_id, name, type, width, height, html, created_at, updated_at) VALUES (1, 'A1', 'html', 300, 250, '<b>1</b>', $t, $t)");
    $db->q('INSERT INTO campaign_banners (campaign_id, banner_id) VALUES (1, 1)');
    $db->q("INSERT INTO sites (name, created_at, updated_at) VALUES ('Kazbo', $t, $t)");
    $db->q("INSERT INTO zones (site_id, name, width, height, created_at, updated_at) VALUES (1, 'Čtverec', 300, 250, $t, $t)");
    $db->q('INSERT INTO zone_banners (zone_id, banner_id) VALUES (1, 1)');
    $db->q("INSERT INTO stats (day, hour, banner_id, zone_id, campaign_id, impressions, clicks) VALUES ('2026-11-20', 10, 1, 1, 1, 100, 5), ('2026-11-20', 11, 1, 1, 1, 7, 0)");
    $db->q("INSERT INTO clicks (visitor, banner_id, zone_id, campaign_id, at) VALUES (NULL, 1, 1, 1, $t)");
    return $dir;
}

test('převod z 0.8: statistiky zůstanou (neznámé zařízení), nové sloupce, opakovatelný', function () {
    $dir = databaze08();
    $app = new App(KOREN . '/apps/reklamy', ['data_dir' => $dir]);
    ok($app->migruj());
    $db = $app->db;
    eq(6, Schema::verze());
    eq(6, (new Migrace($db))->verze('reklamy'));
    eq('6', $app->nastaveni()->dej('schema_version'));
    eq([[10, 100, 5, ''], [11, 7, 0, '']], array_map(fn($r) => [(int)$r['hour'], (int)$r['impressions'], (int)$r['clicks'], $r['device']],
        $db->q('SELECT * FROM stats ORDER BY hour')->fetchAll()));
    eq('', $db->q('SELECT device FROM clicks')->fetchColumn());
    eq([1], array_map('intval', $db->q('SELECT banner_id FROM zone_banners')->fetchAll(PDO::FETCH_COLUMN)), 'přiřazení zůstanou');
    $b = $app->bannery()->radek(1);
    eq([null, null, null], [$b['devices'], $b['media2x_id'], $b['image2x_url']]);
    eq(null, $app->zony()->radek(1)['sizes']);
    // nové zobrazení stejné hodiny a banneru má vlastní řádek se zařízením
    $app->dorucovani()->zobrazeni(1, 1, kontext9(['sw' => 390, 'cas' => mktime(10, 30, 0, 11, 20, 2026)]), 1);
    eq([['', 100], ['mobil', 1]], array_map(fn($r) => [$r['device'], (int)$r['impressions']], $db->q('SELECT * FROM stats WHERE hour = 10 ORDER BY device')->fetchAll()));
    eq(false, $app->migruj(), 'podruhé nic');
    // obnovená záloha z 0.8: tabulky už v novém tvaru, verze 5 → převod nic nezdvojí
    $db->q("UPDATE module_versions SET version = 5 WHERE module = 'reklamy'");
    ok($app->migruj());
    eq(3, (int)$db->q('SELECT COUNT(*) FROM stats')->fetchColumn());
});

test('zařízení: odkaz, šířka okna, prohlížeč; odhad šířky místa', function () {
    eq('pocitac', kontext9()->zarizeni());
    eq('mobil', kontext9(['ua' => UA_MOBIL])->zarizeni());
    eq('tablet', kontext9(['ua' => UA_TABLET])->zarizeni());
    eq(['mobil', 'mobil', 'tablet', 'tablet', 'pocitac'], array_map(fn($w) => kontext9(['sw' => $w])->zarizeni(), [320, 767, 768, 1023, 1024]), 'šířka okna má přednost před prohlížečem');
    eq('pocitac', kontext9(['ua' => UA_MOBIL, 'sw' => 1280])->zarizeni());
    eq('mobil', kontext9(['sw' => 1280, 'd' => 'mobil'])->zarizeni(), 'zařízení z odkazu (proklik) má přednost');
    eq([360, 768, 0, 500], [kontext9(['ua' => UA_MOBIL])->odhadSirky(), kontext9(['ua' => UA_TABLET])->odhadSirky(), kontext9()->odhadSirky(), kontext9(['sw' => 500])->odhadSirky()]);
    $k = Kontext::zPozadavku(['sw' => '414', 'd' => 'nesmysl'], ['HTTP_USER_AGENT' => UA_POCITAC]);
    eq([414, '', 'mobil'], [$k->sirkaOkna, $k->zarizeniOdkazu, $k->zarizeni()]);
    eq([0, 'tablet'], [($k = Kontext::zPozadavku(['sw' => '-5', 'd' => 'tablet'], ['HTTP_USER_AGENT' => UA_POCITAC]))->sirkaOkna, $k->zarizeni()]);
});

test('responzivní zóna: rozměry z textu, kontroly, přiřazení, popis, kódy pro vložení', function () {
    eq(['970x250', '728x90', '300x600', '300x250'], Zony::rozmeryZTextu("300x250, 970×250; 728X90\n300x600 300x250"), 'od nejširšího, bez opakování');
    eq(null, Zony::rozmeryZTextu('  '));
    throws(fn() => Zony::rozmeryZTextu('300x'), 'šířka×výška');
    throws(fn() => Zony::rozmeryZTextu('5000x10'), 'šířka×výška');
    throws(fn() => Zony::rozmeryZTextu(implode(',', array_map(fn($i) => "{$i}x10", range(1, 11)))), 'nejvýš 10');
    $app = novaApp();
    throws(fn() => zona9($app, ['width' => 300, 'sizes' => '300x250']), 'nechte prázdné');
    $z = zona9($app, ['sizes' => '300x250, 970x250, 728x90']);
    eq([[970, 250], [728, 90], [300, 250]], Zony::rozmery($z));
    eq('responzivní 970×250, 728×90, 300×250', Zony::rozmer($z));
    ok(Zony::maRozmer($z) && Zony::jeResponzivni($z));
    eq([970, 250], $app->zony()->rozmerVlozeni($z));
    $kid = kampan($app);
    $velky = htmlBanner($app, $kid, 970, 250);
    $maly = htmlBanner($app, $kid, 300, 250);
    $jiny = htmlBanner($app, $kid, 160, 600);
    throws(fn() => $app->zony()->prirad((int)$z['id'], [], [$jiny]), 'nemá rozměr zóny responzivní');
    $app->zony()->prirad((int)$z['id'], [], [$velky, $maly]);
    eq([$maly, $velky], array_map(fn($b) => (int)$b['id'], $app->zony()->bannery((int)$z['id'])));
    eq([(int)$z['id']], array_map(fn($x) => (int)$x['id'], $app->zony()->zonyBanneru($app->bannery()->radek($maly))));
    // změna rozměru banneru: přímé přiřazení do responzivní zóny, kam už nesedí, se odebere; do jiného rozměru zóny ano
    $app->bannery()->uloz(['name' => 'x', 'width' => 728, 'height' => 90, 'html' => '<b>x</b>'], $maly);
    eq([$velky, $maly], $app->zony()->prirazeni((int)$z['id'])['bannery'], '728×90 do zóny pořád sedí');
    $app->bannery()->uloz(['name' => 'x', 'width' => 320, 'height' => 100, 'html' => '<b>x</b>'], $maly);
    eq([$velky], $app->zony()->prirazeni((int)$z['id'])['bannery']);
    // změna zóny: bez 970×250 se banner odebere; zpět na obyčejnou zónu
    $app->zony()->uloz(['name' => 'Zóna', 'sizes' => '728x90 300x250'], (int)$z['id']);
    eq([], $app->zony()->prirazeni((int)$z['id'])['bannery']);
    $app->zony()->uloz(['name' => 'Zóna', 'sizes' => ''], (int)$z['id']);
    eq(null, $app->zony()->radek((int)$z['id'])['sizes']);
    // kódy: asynchronní značka přes celou šířku místa, JavaScript posílá šířku okna a místa, obrázek bez pevného rozměru
    $z = zona9($app, ['sizes' => '728x90, 300x250']);
    $k = new Kody($app);
    ok(str_contains($k->kod($z, 'asyn'), '<ins data-twbr-zona="' . $z['id'] . '" style="display:block"></ins>'), $k->kod($z, 'asyn'));
    ok(str_contains($k->kod($z, 'js'), "'&sw='") && str_contains($k->kod($z, 'js'), "'&sirka=' + m"));
    ok(!str_contains($k->kod($z, 'odkaz'), 'width='), $k->kod($z, 'odkaz'));
    ok(str_contains($k->kod($z, 'iframe'), 'width="728" height="250"'));
    $pevna = zona9($app, ['width' => 300, 'height' => 250]);
    ok(str_contains($k->kod($pevna, 'asyn'), 'display:inline-block;width:300px;height:250px'), 'obyčejná zóna beze změny');
});

test('responzivní zóna: nejširší banner, který se vejde do místa; bez šířky odhad podle zařízení', function () {
    $app = novaApp();
    $z = zona9($app, ['sizes' => '970x250, 728x90, 300x250']);
    $kid = kampan($app);
    $b970 = htmlBanner($app, $kid, 970, 250);
    $b728 = htmlBanner($app, $kid, 728, 90);
    $b300 = htmlBanner($app, $kid, 300, 250);
    $b300b = htmlBanner($app, $kid, 300, 250);
    $app->zony()->prirad((int)$z['id'], [$kid], []);
    $d = $app->dorucovani();
    $vyber = fn(int $sirka, array $k = []) => ($b = $d->vyber($z, kontext9($k), [], $sirka)) ? (int)$b['id'] : null;
    eq($b970, $vyber(1200));
    eq($b970, $vyber(970), 'přesně na šířku');
    eq($b728, $vyber(969));
    eq($b728, $vyber(800));
    ok(in_array($vyber(400), [$b300, $b300b], true), 'stejný rozměr se losuje');
    eq(null, $vyber(299), 'nic se nevejde → nic (banner se nezmenšuje)');
    eq($b970, $vyber(0), 'bez šířky místa na počítači bez omezení');
    ok(in_array($vyber(0, ['ua' => UA_MOBIL]), [$b300, $b300b], true), 'bez šířky místa mobil podle prohlížeče (odhad 360 px)');
    eq($b728, $vyber(0, ['ua' => UA_TABLET]), 'tablet: odhad 768 px');
    eq($b728, $vyber(0, ['sw' => 800]), 'šířka okna místo šířky místa');
    eq('mobil', $d->vyber($z, kontext9(['sw' => 390]), [], 390)['zarizeni'], 'vybraný banner nese zařízení pro odkazy');
    // priorita má přednost před rozměrem: exkluzivní kampaň jen s malým rozměrem vyhraje i na počítači
    $ex = kampan($app, ['kind' => 'exclusive']);
    $bex = htmlBanner($app, $ex, 300, 250);
    $app->zony()->prirad((int)$z['id'], [$kid, $ex], []);
    eq($bex, $vyber(1200));
    // obyčejná zóna se šířkou místa neřídí
    $pevna = zona9($app, ['width' => 970, 'height' => 250]);
    $app->zony()->prirad((int)$pevna['id'], [$kid], []);
    eq($b970, (int)$d->vyber($pevna, kontext9(), [], 300)['id']);
});

test('banner jen pro některá zařízení; cílení kampaně podle šířky okna', function () {
    $app = novaApp();
    $z = zona9($app, ['width' => 300, 'height' => 250]);
    $kid = kampan($app);
    $mob = htmlBanner($app, $kid, 300, 250, ['zarizeni' => ['mobil']]);
    $pc = htmlBanner($app, $kid, 300, 250, ['zarizeni' => ['pocitac', 'tablet']]);
    $vse = htmlBanner($app, $kid, 300, 250, ['zarizeni' => ['pocitac', 'tablet', 'mobil']]);
    eq(['mobil', 'pocitac,tablet', null], [$app->bannery()->radek($mob)['devices'], $app->bannery()->radek($pc)['devices'], $app->bannery()->radek($vse)['devices']]);
    eq(['pocitac', 'tablet'], Bannery::zarizeni($app->bannery()->radek($pc)));
    throws(fn() => htmlBanner($app, $kid, 300, 250, ['zarizeni' => ['']]), 'aspoň jedno zařízení');
    $app->zony()->prirad((int)$z['id'], [$kid], []);
    $ids = fn(array $k) => array_map(fn($b) => (int)$b['id'], $app->dorucovani()->kandidati($z, kontext9($k)));
    eq([$pc, $vse], $ids(['sw' => 1280]));
    eq([$mob, $vse], $ids(['sw' => 390]));
    eq([$mob, $vse], $ids(['ua' => UA_MOBIL]), 'bez šířky okna podle prohlížeče');
    // úprava bez pole zarizeni (např. z editoru slidů) zařízení nezmění
    $app->bannery()->uloz(['name' => 'M', 'width' => 300, 'height' => 250, 'html' => '<b>m</b>'], $mob);
    eq('mobil', $app->bannery()->radek($mob)['devices']);
    // cílení kampaně na mobil: rozhoduje šířka okna ze skriptu
    $mk = kampan($app, ['cil_zarizeni' => ['mobil']]);
    $bm = htmlBanner($app, $mk, 300, 250);
    $z2 = zona9($app, ['width' => 300, 'height' => 250]);
    $app->zony()->prirad((int)$z2['id'], [$mk], []);
    eq(null, $app->dorucovani()->vyber($z2, kontext9(['ua' => UA_MOBIL, 'sw' => 1280])));
    eq($bm, (int)$app->dorucovani()->vyber($z2, kontext9(['sw' => 400]))['id']);
});

test('statistiky po zařízeních: zobrazení, proklik se zařízením zobrazení, konverze, přehled a filtr', function () {
    $app = novaApp();
    $app->nastaveni()->uloz('click_param', '0');
    $z = zona9($app, ['width' => 300, 'height' => 250]);
    $kid = kampan($app);
    $b = htmlBanner($app, $kid, 300, 250, ['target_url' => 'https://kazbo.eu/']);
    $app->zony()->prirad((int)$z['id'], [$kid], []);
    $d = $app->dorucovani();
    $zid = (int)$z['id'];
    $vydej = new Vydej($app);
    // mobil (šířka okna 390): odkaz prokliku nese d=mobil
    $r = $vydej->proZonu($z, kontext9(['sw' => 390]));
    ok(str_contains($r['html'], '&amp;d=mobil'), $r['html']);
    eq('mobil', $vydej->udaje($r['banner'], $zid)['zarizeni']);
    $d->zobrazeni($b, $zid, kontext9(['sw' => 1300]), $kid);
    $d->zobrazeni($b, $zid, kontext9(['sw' => 1300]), $kid);
    $d->zobrazeni($b, $zid, kontext9(['ua' => UA_TABLET]), $kid);
    // proklik přijde z prohlížeče počítače, ale odkaz říká mobil (zobrazení bylo v úzkém okně)
    ok(str_contains($r['html'], '/d/b.php?') , 'HTML banner v rámu: dokument nese zařízení pro {clickurl}');
    parse_str((string)parse_url($app->vykresleni()->klik($r['banner'], $zid), PHP_URL_QUERY), $q);
    eq('mobil', $q['d']);
    ok($app->vykresleni()->overOdkaz('klik', $q) !== null, 'parametr d nepokazí podpis');
    $klik = $d->proklik($b, $zid, Kontext::zPozadavku($q, ['HTTP_USER_AGENT' => UA_POCITAC]), $kid);
    eq('mobil', $app->db->q('SELECT device FROM clicks WHERE id = ?', [$klik])->fetchColumn());
    // konverze po prokliku dostane zařízení prokliku
    $tr = $app->konverze()->uloz(['name' => 'Nákup', 'click_days' => '7']);
    eq('ok', $app->konverze()->zaznamenej($tr, $app->konverze()->token($klik), kontext9(), 50000)['stav']);
    $st = $app->statistiky();
    $den = date('Y-m-d');
    $rows = $st->prehled($den, $den, 'zarizeni');
    eq([['pocitac', 'Počítač', 2, 0, 0], ['mobil', 'Mobil', 1, 1, 1], ['tablet', 'Tablet', 1, 0, 0]],
        array_map(fn($x) => [$x['klic'], $x['popis'], $x['impressions'], $x['clicks'], $x['conversions']], $rows));
    eq(50000, $rows[1]['revenue']);
    eq(['impressions' => 1, 'clicks' => 1, 'conversions' => 1, 'revenue' => 50000], Statistiky::soucet($st->prehled($den, $den, 'den', ['zarizeni' => 'mobil'])));
    eq([['', 'neznámé (data před verzí 0.9)']], array_map(fn($x) => [$x['klic'], $x['popis']], (function () use ($app, $st) {
        $app->db->q("INSERT INTO stats (day, hour, banner_id, zone_id, campaign_id, impressions) VALUES ('2020-01-01', 1, 1, 1, 1, 5)");
        return $st->prehled('2020-01-01', '2020-01-01', 'zarizeni');
    })()));
    ok(str_contains(Statistiky::csv($rows, 'zarizeni'), "Zařízení;ID;"), 'CSV má sloupec Zařízení');
});

test('obrázek 2×: přesně dvojnásobný rozměr, srcset, b.php, JSON, galerie ho nedovolí smazat', function () {
    $app = novaApp();
    $kid = kampan($app);
    $m1 = $app->galerie()->uloz(pngSoubor(300, 250), 'a.png');
    $m2 = $app->galerie()->uloz(pngSoubor(600, 500), 'a@2x.png');
    $spatny = $app->galerie()->uloz(pngSoubor(600, 400), 'b.png');
    $v = ['campaign_id' => $kid, 'type' => 'image', 'name' => 'Obr', 'media_id' => $m1['id'], 'target_url' => 'https://kazbo.eu/'];
    throws(fn() => $app->bannery()->uloz($v + ['media2x_id' => $spatny['id']]), 'přesně dvojnásobný rozměr banneru: 600×500');
    throws(fn() => $app->bannery()->uloz($v + ['media2x_id' => $m1['id']]), 'jiný soubor');
    $b = $app->bannery()->uloz($v + ['media2x_id' => $m2['id']]);
    $r = $app->bannery()->radek($b);
    ok(Bannery::maRetinu($r));
    $html = $app->vykresleni()->html($r, 1);
    ok(preg_match('~<img src="([^"]+)" srcset="\1 1x, ([^"]+&amp;x=2) 2x" width="300" height="250"~', $html, $mm) === 1, $html);
    eq(substr((string)$app->db->q('SELECT md5 FROM media WHERE id = ?', [$m2['id']])->fetchColumn(), 0, 8), (function () use ($mm) { parse_str((string)parse_url(html_entity_decode($mm[2]), PHP_URL_QUERY), $q); return $q['v']; })());
    $u = (new Vydej($app))->udaje($r, 1);
    ok(str_contains((string)$u['obrazek2x'], 'x=2') && !str_contains((string)$u['obrazek'], 'x=2'));
    // galerie: obrázek 2× používá banner
    throws(fn() => $app->galerie()->smaz([(int)$m2["id"]]), "používá banner");
    // úprava bez pole media2x_id obrázek ponechá, prázdná volba ho odebere
    $app->bannery()->uloz(['name' => 'Obr', 'media_id' => $m1['id']], $b);
    eq((int)$m2['id'], (int)$app->bannery()->radek($b)['media2x_id']);
    $app->bannery()->uloz(['name' => 'Obr', 'media_id' => $m1['id'], 'media2x_id' => ''], $b);
    ok(!Bannery::maRetinu($app->bannery()->radek($b)));
    ok(!str_contains($app->vykresleni()->html($app->bannery()->radek($b), 1), 'srcset'));
    // obrázek z adresy
    $e = $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'external', 'name' => 'Ext', 'width' => 300, 'height' => 250,
        'image_url' => 'https://cdn.example.com/a.png', 'image2x_url' => 'https://cdn.example.com/a@2x.png']);
    ok(str_contains($app->vykresleni()->html($app->bannery()->radek($e), 1), 'srcset="https://cdn.example.com/a.png 1x, https://cdn.example.com/a@2x.png 2x"'));
    throws(fn() => $app->bannery()->uloz(['name' => 'Ext', 'width' => 300, 'height' => 250, 'image_url' => 'https://cdn.example.com/a.png', 'image2x_url' => 'ftp://x'], $e), 'Adresa obrázku 2×');
});

test('prázdný rozměr (0.9.1): 728x0 = od 728 px do dalšího širšího rozměru nic', function () {
    eq(['970x250', '728x0', '300x250'], Zony::rozmeryZTextu('300x250, 728x0, 970x250'));
    throws(fn() => Zony::rozmeryZTextu('728x0, 728x90'), 'stejnou šířku');
    throws(fn() => Zony::rozmeryZTextu('728x0'), 'aspoň jeden rozměr banneru');
    throws(fn() => Zony::rozmeryZTextu('0x0, 300x250'), 'šířka×výška');
    $app = novaApp();
    $z = zona9($app, ['sizes' => '970x250, 728x0, 300x250']);
    eq('responzivní 970×250, 728 px nic, 300×250', Zony::rozmer($z));
    eq([[970, 250], [300, 250]], Zony::rozmeryBanneru($z));
    eq([970, 250], $app->zony()->rozmerVlozeni($z));
    eq([0, 728, 728, 728], [Zony::prazdnaHranice($z, 727), Zony::prazdnaHranice($z, 728), Zony::prazdnaHranice($z, 2000), Zony::prazdnaHranice($z, 0)]);
    $kid = kampan($app);
    $b970 = htmlBanner($app, $kid, 970, 250);
    $b300 = htmlBanner($app, $kid, 300, 250);
    $app->zony()->prirad((int)$z['id'], [$kid], []);
    $d = $app->dorucovani();
    $vyber = fn(int $sirka, array $k = []) => ($b = $d->vyber($z, kontext9($k), [], $sirka)) ? (int)$b['id'] : null;
    eq([$b970, null, null, $b300, $b300, null], [$vyber(1200), $vyber(969), $vyber(728), $vyber(727), $vyber(300), $vyber(299)]);
    eq($b970, $vyber(0), 'počítač bez šířky místa: billboard');
    eq(null, $vyber(0, ['ua' => UA_TABLET]), 'tablet (odhad 768 px) padne do prázdného rozměru');
    eq($b300, $vyber(0, ['ua' => UA_MOBIL]));
    // obyčejná logika „rozměr bez bannerů se přeskočí“ prázdný rozměr nepřeskočí
    $app->bannery()->nastavStav($b970, 'paused');
    eq(null, $vyber(1200), 'bez billboardu se na široké místo čtverec nepoužije – je za prázdným rozměrem');
    $prazdne = zona9($app, ['sizes' => '970x250, 500x0, 300x600']);
    throws(fn() => zona9($app, ['sizes' => '300x0, 300x250']), 'stejnou šířku');
    ok(!Zony::pasuje($prazdne, ['width' => 300, 'height' => 0]));
});
