<?php
declare(strict_types=1);
// TWB Reklamy 0.8: banner ve více kampaních (převod databáze, správa, doručování, statistiky, odkazy) a externí data (vzdálené zdroje).
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Schema, Kontext, Chyba};
use TWB\Jadro\{Db, Migrace};

echo "Reklamy: bannery ve více kampaních, externí data\n";

function kontext8(array $v = []): Kontext
{
    return new Kontext(prohlizec: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', cas: $v['cas'] ?? time());
}

function zona8(App $app, array $v = []): array
{
    $web = $app->weby()->uloz(['name' => 'Web']);
    return $app->zony()->radek($app->zony()->uloz($v + ['site_id' => $web, 'name' => 'Zóna']));
}

/** Databáze ve stavu verze 0.7 (zmrazené schéma tests/reklamy/original/Schema-0.7.php) s daty. @return array{0:string, 1:array<string,int>} */
function databaze07(): array
{
    if (!class_exists('Reklamy07\\Schema', false)) {
        $kod = (string)file_get_contents(__DIR__ . '/original/Schema-0.7.php');
        $kod = str_replace(['namespace Reklamy;', '<?php'], ['namespace Reklamy07;', ''], $kod);
        eval($kod);
    }
    $dir = docasna('v07');
    mkdir($dir, 0775, true);
    $db = Db::pripoj('sqlite:' . $dir . '/reklamy.sqlite');
    (new Migrace($db))->spust('reklamy', 'Reklamy07\\Schema');
    $t = time();
    $db->q("INSERT INTO campaigns (name, created_at, updated_at) VALUES ('Advent', $t, $t), ('Léto', $t, $t)");
    $db->q("INSERT INTO banners (campaign_id, name, type, width, height, html, created_at, updated_at) VALUES
        (1, 'A1', 'html', 300, 250, '<b>1</b>', $t, $t), (1, 'A2', 'html', 728, 90, '<b>2</b>', $t, $t), (2, 'L1', 'html', 300, 250, '<b>3</b>', $t, $t)");
    $db->q("INSERT INTO sites (name, created_at, updated_at) VALUES ('Kazbo', $t, $t)");
    $db->q("INSERT INTO zones (site_id, name, width, height, created_at, updated_at) VALUES (1, 'Čtverec', 300, 250, $t, $t)");
    $db->q('INSERT INTO zone_campaigns (zone_id, campaign_id) VALUES (1, 2)');
    $db->q('INSERT INTO zone_banners (zone_id, banner_id) VALUES (1, 1)');
    $db->q("INSERT INTO stats (day, hour, banner_id, zone_id, impressions, clicks) VALUES ('2026-11-20', 10, 1, 1, 100, 5), ('2026-11-20', 10, 3, 1, 40, 2), ('2026-11-20', 11, 99, 1, 7, 0)");
    $db->q("INSERT INTO view_map (n, zone_id, banner_id, at) VALUES ('x', 1, 1, $t)");
    return [$dir, ['a1' => 1, 'a2' => 2, 'l1' => 3]];
}

test('převod z 0.7: vazby do kampaní, zón i statistiky zůstanou, banner už nemá cizí klíč na kampaň', function () {
    [$dir] = databaze07();
    $app = new App(KOREN . '/apps/reklamy', ['data_dir' => $dir]);
    ok($app->migruj());
    $db = $app->db;
    eq(5, (new Migrace($db))->verze('reklamy'));
    eq('5', $app->nastaveni()->dej('schema_version'));
    eq([[1, 1], [1, 2], [2, 3]], array_map(fn($r) => [(int)$r['campaign_id'], (int)$r['banner_id']], $db->q('SELECT * FROM campaign_banners ORDER BY banner_id')->fetchAll()));
    eq([1], array_map('intval', $db->q('SELECT banner_id FROM zone_banners')->fetchAll(PDO::FETCH_COLUMN)), 'přiřazení banneru do zóny přestavba tabulky nesmaže');
    eq([[1, 1, 100], [3, 2, 40], [99, 0, 7]], array_map(fn($r) => [(int)$r['banner_id'], (int)$r['campaign_id'], (int)$r['impressions']],
        $db->q('SELECT * FROM stats ORDER BY banner_id')->fetchAll()), 'statistiky dostanou kampaň banneru (smazaný banner 0)');
    eq([], $db->q('PRAGMA foreign_key_list(banners)')->fetchAll());
    eq('1', (string)$db->q('PRAGMA foreign_keys')->fetchColumn(), 'cizí klíče jsou po převodu zase zapnuté');
    eq(0, (int)$db->q("SELECT campaign_id FROM view_map WHERE n = 'x'")->fetchColumn());
    eq(['A1', 'A2'], array_column($app->bannery()->seznam(1), 'name'));
    eq(false, $app->migruj(), 'podruhé nic');
    // smazání kampaně už nemaže bannery přes cizí klíč, jen ty, které nejsou jinde
    $app->bannery()->pridejDoKampane(1, 2);
    $app->kampane()->smaz(1);
    eq([1, 3], array_map('intval', $db->q('SELECT id FROM banners ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)));
    eq(null, $db->q('SELECT campaign_id FROM banners WHERE id = 1')->fetchColumn());
});

test('převod je opakovatelný: obnovená záloha z 0.7 (sloupec campaign_id, prázdné vazby) se doplní', function () {
    $app = novaApp();
    $k1 = kampan($app);
    $k2 = kampan($app);
    $b1 = htmlBanner($app, $k1, 300, 250);
    $b2 = htmlBanner($app, $k2, 300, 250);
    $db = $app->db;
    // tak vypadá databáze po obnově zálohy ze 0.7: tabulky v novém tvaru, vazby a kampaň ve statistikách chybí, verze 4
    $db->q('DELETE FROM campaign_banners');
    $db->q("INSERT INTO stats (day, hour, banner_id, zone_id, impressions) VALUES ('2026-11-20', 1, ?, 0, 3)", [$b2]);
    $db->q("UPDATE module_versions SET version = 4 WHERE module = 'reklamy'");
    $app->nastaveni()->uloz('schema_version', '4');
    ok($app->migruj());
    eq([$k1], $app->bannery()->kampane($b1));
    eq([$k2], $app->bannery()->kampane($b2));
    eq($k2, (int)$db->q('SELECT campaign_id FROM stats')->fetchColumn());
    ok(in_array('campaign_banners', App::TABULKY, true) && in_array('sources', App::TABULKY, true), 'nové tabulky jsou v zálohách');
});

test('správa: přidání do další kampaně, odebrání, aspoň jedna kampaň, seznamy s kampaněmi', function () {
    $app = novaApp();
    $a = kampan($app, ['name' => 'Advent']);
    $l = kampan($app, ['name' => 'Léto']);
    $x = kampan($app, ['name' => 'Archiv', 'status' => 'archived']);
    $bannery = $app->bannery();
    $b = htmlBanner($app, $a, 300, 250, ['name' => 'Společný']);
    $jen = htmlBanner($app, $l, 300, 250, ['name' => 'Jen léto']);
    $stary = htmlBanner($app, $x, 300, 250, ['name' => 'Starý']);
    $bannery->pridejDoKampane($b, $l);
    eq([$a, $l], $bannery->kampane($b));
    eq(['Advent', 'Léto'], array_column($bannery->radek($b)['kampane'], 'name'));
    eq(['Jen léto', 'Společný'], array_column($bannery->seznam($l), 'name'));
    eq(['Jen léto', 'Společný'], array_column($bannery->seznam(), 'name'), 'banner jen v archivované kampani se v seznamu neukáže');
    eq($l, $bannery->seznam($l)[1]['campaign_id'], 'v seznamu kampaně je campaign_id ta kampaň');
    eq($a, $bannery->radek($b)['campaign_id'], 'jinak kampaň, pro kterou banner vznikl');
    $pocty = array_column($app->kampane()->seznam(), 'banners', 'id');
    eq([1, 2], [(int)$pocty[$a], (int)$pocty[$l]], 'počet bannerů kampaně počítá i sdílené');
    throws(fn() => $bannery->odeberZKampane($jen, $l), 'jen v této kampani');
    throws(fn() => $bannery->nastavKampane($b, []), 'aspoň v jedné');
    throws(fn() => $bannery->odeberZKampane($b, $x), 'není');
    throws(fn() => $bannery->pridejDoKampane($b, 999), 'nenalezena');
    $bannery->odeberZKampane($b, $a);
    eq([$l], $bannery->kampane($b));
    // úprava formulářem: seznam kampaní nahradí dosavadní, prázdný se odmítne
    $bannery->uloz(['name' => 'Společný', 'width' => 300, 'height' => 250, 'html' => '<i>x</i>', 'kampane' => [$a, $l, $l]], $b);
    eq([$a, $l], $bannery->kampane($b));
    throws(fn() => $bannery->uloz(['name' => 'Společný', 'width' => 300, 'height' => 250, 'html' => '<i>x</i>', 'kampane' => []], $b), 'aspoň v jedné');
    // nový banner v kampani i rovnou v dalších
    $n = $bannery->uloz(['campaign_id' => $a, 'kampane' => [$l], 'type' => 'html', 'name' => 'Nový', 'width' => 1, 'height' => 1, 'html' => '1']);
    eq([$a, $l], $bannery->kampane($n));
    eq($a, (int)$app->db->q('SELECT campaign_id FROM banners WHERE id = ?', [$n])->fetchColumn());
    // uložení řádku tak, jak ho vrátí radek() (editor slidů), kampaně nezmění
    $bannery->uloz($bannery->radek($n), $n);
    eq([$a, $l], $bannery->kampane($n));
    // kopie banneru je ve stejných kampaních
    eq([$a, $l], $bannery->kampane($bannery->duplikuj($n)));
});

test('kampaň: kopie sdílí bannery, smazání smaže jen bannery, které nejsou jinde', function () {
    $app = novaApp();
    $a = kampan($app);
    $l = kampan($app);
    $spolecny = htmlBanner($app, $a, 300, 250);
    $vlastni = htmlBanner($app, $a, 300, 250);
    $app->bannery()->pridejDoKampane($spolecny, $l);
    $kopie = $app->kampane()->duplikuj($a);
    eq([$spolecny, $vlastni], array_map(fn($b) => (int)$b['id'], $app->bannery()->seznam($kopie)));
    eq(2, (int)$app->db->q('SELECT COUNT(*) FROM banners')->fetchColumn(), 'kopie kampaně bannery nekopíruje');
    eq([], $app->kampane()->jenVlastniBannery($a));
    $app->kampane()->smaz($kopie);
    $app->kampane()->smaz($l);
    eq([$spolecny, $vlastni], $app->kampane()->jenVlastniBannery($a), "po smazání ostatních kampaní je i společný banner jen v A");
    eq(2, (int)$app->db->q("SELECT COUNT(*) FROM banners")->fetchColumn(), "sdílený banner smazání kampaně přežil");
    $app->kampane()->smaz($a);
    eq(0, (int)$app->db->q('SELECT COUNT(*) FROM banners')->fetchColumn());
    eq(0, (int)$app->db->q('SELECT COUNT(*) FROM campaign_banners')->fetchColumn());
});

test('doručování: zóna kampaně bere její bannery, přímo přiřazený banner běží ve všech svých kampaních', function () {
    $app = novaApp();
    $a = kampan($app, ['name' => 'A']);
    $l = kampan($app, ['name' => 'L']);
    $b = htmlBanner($app, $a, 300, 250);
    $app->bannery()->pridejDoKampane($b, $l);
    $z = zona8($app, ['width' => 300, 'height' => 250]);
    $d = $app->dorucovani();
    $app->zony()->prirad((int)$z['id'], [$l], []);
    eq([[$b, $l]], array_map(fn($r) => [(int)$r['id'], (int)$r['campaign_id']], $d->kandidati($z, kontext8())), 'jen kampaň přiřazená zóně');
    eq([$b], array_map(fn($r) => (int)$r['id'], $app->zony()->bannery((int)$z['id'])));
    eq([(int)$z['id']], array_map(fn($r) => (int)$r['id'], $app->zony()->zonyBanneru($app->bannery()->radek($b))));
    $app->zony()->prirad((int)$z['id'], [], [$b]);
    eq([[$b, $a], [$b, $l]], array_map(fn($r) => [(int)$r['id'], (int)$r['campaign_id']], $d->kandidati($z, kontext8())));
    // pozastavená kampaň: banner dál běží v druhé
    $app->kampane()->nastavStav($l, 'paused');
    eq([[$b, $a]], array_map(fn($r) => [(int)$r['id'], (int)$r['campaign_id']], $d->kandidati($z, kontext8())));
    $app->kampane()->nastavStav($l, 'active');
    // vybraný banner se na stránce neopakuje ani za druhou kampaň
    $k = kontext8();
    ok($d->vyber($z, $k) !== null);
    eq(null, $d->vyber($z, $k));
});

test('doručování: limity a statistiky po kampaních, exkluzivní kampaň má přednost', function () {
    $app = novaApp();
    $a = kampan($app, ['name' => 'A', 'limit_impressions' => 2]);
    $l = kampan($app, ['name' => 'L', 'kind' => 'exclusive']);
    $b = htmlBanner($app, $a, 300, 250);
    $app->bannery()->pridejDoKampane($b, $l);
    $z = zona8($app);
    $app->zony()->prirad((int)$z['id'], [$a, $l], []);
    $d = $app->dorucovani();
    eq($l, (int)$d->vyber($z, kontext8())['campaign_id'], 'exkluzivní kampaň');
    $app->kampane()->nastavStav($l, 'paused');
    for ($i = 0; $i < 3; $i++) {
        $r = $d->vyber($z, kontext8());
        if ($r) $d->zobrazeni((int)$r['id'], (int)$z['id'], kontext8(), (int)$r['campaign_id']);
    }
    eq(['impressions' => 2, 'clicks' => 0, 'conversions' => 0], $d->pocty($a, null), 'limit kampaně A vyčerpán');
    eq(null, $d->vyber($z, kontext8()));
    $app->kampane()->nastavStav($l, 'active');
    $r = $d->vyber($z, kontext8());
    $d->zobrazeni((int)$r['id'], (int)$z['id'], kontext8(), (int)$r['campaign_id']);
    $d->proklik($b, (int)$z['id'], kontext8(), $l);
    eq(['impressions' => 1, 'clicks' => 1, 'conversions' => 0], $d->pocty($l, null));
    $st = $app->statistiky();
    eq([2, 1], [$st->souhrn(null, null, $a)['impressions'], $st->souhrn(null, null, $l)['impressions']]);
    eq([['A', 2], ['L', 1]], array_map(fn($r) => [$r['popis'], $r['impressions']], $st->prehled(date('Y-m-d'), date('Y-m-d'), 'kampan')));
    eq(3, $st->poBannerech()[$b]['impressions']);
    eq(1, $st->poBannerech(null, null, $l)[$b]['impressions']);
    eq($l, (int)$app->db->q('SELECT campaign_id FROM clicks')->fetchColumn(), 'proklik pro konverze si pamatuje kampaň');
});

test('odkazy: proklik a HTML dokument nesou podepsanou kampaň, starší odkazy bez kampaně platí dál', function () {
    $app = novaApp(['base_url' => 'https://ads.kazbo.eu']);
    $a = kampan($app);
    $l = kampan($app);
    $b = htmlBanner($app, $a, 300, 250, ['target_url' => 'https://kazbo.eu/']);
    $app->bannery()->pridejDoKampane($b, $l);
    $v = $app->vykresleni();
    $d = $app->dorucovani();
    $radek = $app->bannery()->radek($b);
    $radek['campaign_id'] = $l;
    parse_str((string)parse_url($v->klik($radek, 7), PHP_URL_QUERY), $q);
    eq([(string)$b, '7', (string)$l], [$q['b'], $q['z'], $q['c']]);
    eq([7, $l], $v->overOdkaz('klik', $q));
    eq(null, $v->overOdkaz('klik', ['c' => (string)$a] + $q), 'kampaň v odkazu nejde podvrhnout');
    eq(null, $v->overOdkaz('html', $q), 'podpis prokliku neplatí pro dokument');
    parse_str((string)parse_url($v->dokument($radek, 7), PHP_URL_QUERY), $h);
    eq([7, $l], $v->overOdkaz('html', $h));
    // odkaz ze 0.7 (bez c): kampaň, pro kterou banner vznikl
    $stary = ['b' => (string)$b, 'z' => '7', 'k' => $d->podpis("klik|$b|7")];
    eq([7, $a], $v->overOdkaz('klik', $stary));
    $app->bannery()->odeberZKampane($b, $a);
    eq([7, $l], $v->overOdkaz('klik', $stary), 'banner už v původní kampani není');
    eq($l, $d->hlavniKampan($b));
    eq(null, $v->overOdkaz('klik', ['c' => ''] + $stary));
    // makro {campaignid} = kampaň zobrazení
    $radek['html'] = '{campaignid}';
    ok(str_contains($v->dokumentHtml($radek, 7), "<body>$l</body>"));
});

test('vložení odkazem si pamatuje kampaň obrázku', function () {
    $app = novaApp();
    $d = $app->dorucovani();
    $d->zapamatuj('n1', 3, 5, time(), 8);
    eq([5, 8], $d->zapamatovany('n1', 3));
    eq(null, $d->zapamatovany('n2', 3));
});

test('externí data: zdroj JSON v proměnných banerů, výpadek drží poslední data, cron stahuje podle intervalu', function () {
    $odpoved = [200, json_encode(['kava' => ['cena' => 59, 'nazev' => 'Espresso'], 'kurz' => ['eur' => '25,10']])];
    $volani = [];
    $ted = 1_800_000_000;
    $app = novaApp([
        'zdroje_http' => function (string $url, bool $csv) use (&$odpoved, &$volani) { $volani[] = $url; return $odpoved; },
        'zdroje_cas' => function () use (&$ted) { return $ted; },
    ]);
    $z = $app->zdroje();
    $r = $z->uloz(null, 'Ceník', 'https://data.example.cz/cenik.json', 15);
    ok($r['ok'], (string)$r['error']);
    eq(1, count($volani), 'zdroj se po uložení hned stáhne');
    $t = new DateTimeImmutable('2026-11-21 12:00');
    eq('Espresso za 59 Kč, euro 25,10', $app->promenne()->vyres('{Ceník:kava.nazev} za {Ceník:kava.cena} Kč, euro {Ceník:kurz.eur}', $t, 0, true)[0]);
    // HTML banner i slide dostanou hodnotu při zobrazení
    $k = kampan($app);
    $hb = htmlBanner($app, $k, 300, 250, ['html' => '<p>{Ceník:kava.cena} Kč</p>']);
    ok(str_contains($app->vykresleni()->dokumentHtml($app->bannery()->radek($hb), 0, $t), '<p>59 Kč</p>'));
    // výpadek: poslední data zůstanou, jedno varování v protokolu
    $odpoved = [500, 'Chyba serveru'];
    $ted += 16 * 60;
    eq(false, $z->stahniPo());
    eq('59', $app->promenne()->vyres('{Ceník:kava.cena}', $t, 0, true)[0]);
    $ted += 16 * 60;
    $z->stahniPo();
    eq(1, (int)$app->db->q("SELECT COUNT(*) FROM log WHERE message LIKE '%Ceník%nepodařilo%'")->fetchColumn());
    // obnova a změna dat
    $odpoved = [200, json_encode(['kava' => ['cena' => 65]])];
    $ted += 16 * 60;
    eq(true, $z->stahniPo());
    eq('65', $app->promenne()->vyres('{Ceník:kava.cena}', $t, 0, true)[0]);
    eq(1, (int)$app->db->q("SELECT COUNT(*) FROM log WHERE message LIKE '%Ceník%opět dostupný%'")->fetchColumn());
    $n = count($volani);
    $ted += 60;
    $z->stahniPo();
    eq($n, count($volani), 'před uplynutím intervalu se nestahuje');
    // neznámý zdroj zůstane v textu, název se kontroluje
    eq('{Nic:x}', $app->promenne()->vyres('{Nic:x}', $t, 0, true)[0]);
    throws(fn() => $z->uloz(null, 'Ce:ník', 'https://x.cz/a.json'), 'nesmí obsahovat');
    throws(fn() => $z->uloz(null, 'ceník', 'https://x.cz/a.json'), 'už existuje');
});

test('externí data: CSV (tabulka Google) a strom hodnot pro editor', function () {
    $app = novaApp(['zdroje_http' => fn(string $url, bool $csv) => [200, "Produkt,Cena\nKáva,59\nČaj,45\n"]]);
    $r = $app->zdroje()->uloz(null, 'Tabulka', 'https://docs.google.com/spreadsheets/d/abc/edit#gid=0', 30, 'csv');
    ok($r['ok'], (string)$r['error']);
    eq('45', $app->promenne()->vyres('{Tabulka:Čaj.Cena}', new DateTimeImmutable(), 0, true)[0]);
    $s = $app->zdroje()->podleNazvu('tabulka');
    ok(str_contains((string)$s['url'], 'docs.google.com'));
    $strom = $app->zdroje()->strom($s);
    ok(count($strom) >= 2, 'skupina na řádek');
    ok(in_array('45', array_column(array_merge(...array_column($strom, 'items')), 'value'), true));
});
