<?php
declare(strict_types=1);
// TWB Reklamy 0.5: přehled statistik (časové řady, kampaně, bannery, zóny, weby, filtry) a export CSV.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use Reklamy\{App, Statistiky};

echo "Reklamy: statistiky\n";

/** Řádek statistik přímo do tabulky stats. */
function zapisStat(App $app, string $den, int $hodina, int $banner, int $zona, int $z, int $k, int $konv = 0, int $hodnota = 0): void
{
    $app->db->q('INSERT INTO stats (day, hour, banner_id, zone_id, impressions, clicks, conversions, revenue) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$den, $hodina, $banner, $zona, $z, $k, $konv, $hodnota]);
}

$app = novaApp();
$w1 = $app->weby()->uloz(['name' => 'Kazbo']);
$w2 = $app->weby()->uloz(['name' => 'Jiný']);
$z1 = $app->zony()->uloz(['site_id' => $w1, 'name' => 'Čtverec']);
$z2 = $app->zony()->uloz(['site_id' => $w2, 'name' => 'Pruh']);
$k1 = kampan($app, ['name' => 'Advent']);
$k2 = kampan($app, ['name' => '=Léto']);
$b1 = htmlBanner($app, $k1, 300, 250, ['name' => 'A1']);
$b2 = htmlBanner($app, $k1, 300, 250, ['name' => 'A2']);
$b3 = htmlBanner($app, $k2, 300, 250, ['name' => 'L1']);
zapisStat($app, '2026-11-02', 9, $b1, $z1, 100, 10, 2, 59800);   // pondělí, 45. týden
zapisStat($app, '2026-11-02', 10, $b2, $z1, 50, 5);
zapisStat($app, '2026-11-08', 10, $b3, $z2, 30, 1);               // neděle, stále 45. týden
zapisStat($app, '2026-11-09', 23, $b1, 0, 20, 2, 1, 10000);       // pondělí, 46. týden, bez zóny
zapisStat($app, '2026-12-01', 0, $b3, $z1, 5, 0);
zapisStat($app, '2026-10-31', 12, $b1, $z1, 999, 99);             // mimo období

test('časové řady: dny včetně prázdných, týdny, měsíce, hodiny', function () use ($app) {
    $st = $app->statistiky();
    $dny = $st->prehled('2026-11-01', '2026-12-01', 'den');
    eq(31, count($dny));
    eq(['2026-11-01', 'ne 1. 11. 2026', 0], [$dny[0]['klic'], $dny[0]['popis'], $dny[0]['impressions']]);
    eq([150, 15, 2, 59800], [$dny[1]['impressions'], $dny[1]['clicks'], $dny[1]['conversions'], $dny[1]['revenue']]);
    eq(['impressions' => 205, 'clicks' => 18, 'conversions' => 3, 'revenue' => 69800], Statistiky::soucet($dny));
    $tydny = $st->prehled('2026-11-01', '2026-12-01', 'tyden');
    eq(['2026-W44', '2026-W45', '2026-W46', '2026-W47', '2026-W48', '2026-W49'], array_column($tydny, 'klic'));
    eq([180, 20], [$tydny[1]['impressions'], $tydny[2]['impressions']]);
    eq('45. týden (od 2. 11.)', $tydny[1]['popis']);
    $mesice = $st->prehled('2026-11-01', '2026-12-01', 'mesic');
    eq([['2026-11', 'listopad 2026', 200], ['2026-12', 'prosinec 2026', 5]], array_map(fn($r) => [$r['klic'], $r['popis'], $r['impressions']], $mesice));
    $hod = $st->prehled('2026-11-01', '2026-12-01', 'hodina');
    eq(24, count($hod));
    eq([5, 100, 80, 20], [$hod[0]['impressions'], $hod[9]['impressions'], $hod[10]['impressions'], $hod[23]['impressions']]);
    eq('23–24 h', $hod[23]['popis']);
    throws(fn() => $st->prehled('2026-11-01', '2026-11-30', 'rok'), 'Neznámé');
});

test('kampaně, bannery, zóny a weby: řazení podle zobrazení, odkazy, bez zóny', function () use ($app, $k1, $k2, $b1, $b2, $z1, $z2, $w1, $w2) {
    $st = $app->statistiky();
    $k = $st->prehled('2026-11-01', '2026-12-01', 'kampan');
    eq([[(string)$k1, 'Advent', 170, ['kampan', ['id' => $k1]]], [(string)$k2, '=Léto', 35, ['kampan', ['id' => $k2]]]],
        array_map(fn($r) => [$r['klic'], $r['popis'], $r['impressions'], $r['odkaz']], $k));
    eq([120, 50, 35], array_column($st->prehled('2026-11-01', '2026-12-01', 'banner'), 'impressions'));
    $z = $st->prehled('2026-11-01', '2026-12-01', 'zona');
    eq([['Čtverec', 155], ['Pruh', 30], ['bez zóny (vložení ze serveru)', 20]], array_map(fn($r) => [$r['popis'], $r['impressions']], $z));
    eq(null, $z[2]['odkaz']);
    $w = $st->prehled('2026-11-01', '2026-12-01', 'web');
    eq([['Kazbo', 155], ['Jiný', 30], ['bez zóny (vložení ze serveru)', 20]], array_map(fn($r) => [$r['popis'], $r['impressions']], $w));
    // filtry
    eq(170, Statistiky::soucet($st->prehled('2026-11-01', '2026-12-01', 'den', ['kampan' => $k1]))['impressions']);
    eq(120, Statistiky::soucet($st->prehled('2026-11-01', '2026-12-01', 'den', ['banner' => $b1]))['impressions']);
    eq(155, Statistiky::soucet($st->prehled('2026-11-01', '2026-12-01', 'banner', ['zona' => $z1]))['impressions']);
    eq([30], array_column($st->prehled('2026-11-01', '2026-12-01', 'banner', ['web' => $w2]), 'impressions'));
    eq([], $st->prehled('2026-11-01', '2026-12-01', 'banner', ['web' => $w2, 'kampan' => $k1]));
    // smazaný banner zůstane ve statistikách
    $app->bannery()->smaz($b2);
    eq('#' . $b2 . ' (smazáno)', $st->prehled('2026-11-01', '2026-12-01', 'banner')[1]['popis']);
});

test('CSV pro Excel: BOM, středníky, desetinná čárka, součet, ochrana před vzorci', function () use ($app) {
    $csv = Statistiky::csv($app->statistiky()->prehled('2026-11-01', '2026-12-01', 'kampan'), 'kampan');
    ok(str_starts_with($csv, "\u{FEFF}Kampaně;ID;Zobrazení;Prokliky;CTR %;Konverze;Konverzní poměr %;Hodnota Kč\r\n"), $csv);
    $r = explode("\r\n", trim($csv));
    eq('Advent;1;120;12;10,00;3;25,00;698,00', $r[1]);
    eq('smazané bannery;0;50;5;10,00;0;0,00;0,00', $r[2], 'smazaný banner A2 už ke kampani nepatří, ve statistikách zůstane');
    eq("'=Léto;2;35;1;2,86;0;0,00;0,00", $r[3], 'text začínající = Excel nespustí');
    eq('Celkem;;205;18;8,78;3;16,67;698,00', $r[4]);
    eq('"a;b";1;0;0;;0;;0,00', explode("\r\n", Statistiky::csv([['klic' => '1', 'popis' => 'a;b', 'impressions' => 0, 'clicks' => 0, 'conversions' => 0, 'revenue' => 0]], 'banner'))[1]);
});

// ---------- stránka ----------
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$web = new App(KOREN . '/apps/reklamy', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$web->migruj();
$web->heslo()->nastav('tajneheslo1');
$wk = kampan($web, ['name' => 'Advent']);
$wb = htmlBanner($web, $wk, 300, 250);
zapisStat($web, date('Y-m-d'), 10, $wb, 0, 40, 4, 1, 12345);
zapisStat($web, date('Y-m-d', strtotime('-40 days')), 10, $wb, 0, 7, 0);
$b = new Prohlizec($s);

test('stránka Statistiky: období, seskupení, graf, tabulka a stažení CSV', function () use ($b, $wk) {
    $b->jdi('index.php');
    $b->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);
    $r = $b->jdi('index.php?p=statistiky');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<svg class="graf"') && substr_count($r['body'], 'class="g-bod"') === 30, 'graf 30 dní');
    ok(str_contains($r['body'], '123,45 Kč'));
    $r = $b->jdi('index.php?p=statistiky&obdobi=90&podle=kampan&graf=revenue');
    ok(str_contains($r['body'], '>Advent</a>') && str_contains($r['body'], '<th class="cisla">47</th>'), 'kampaň za 90 dní');
    $r = $b->jdi('index.php?p=statistiky&obdobi=vlastni&od=2020-02-30&do=x&podle=mesic');
    eq(200, $r['code'], 'neplatné datum = výchozí období');
    $r = $b->jdi("index.php?p=statistiky&obdobi=7&podle=den&kampan=$wk&csv=1");
    eq('text/csv', $r['type']);
    ok(str_contains($b->posledniHlavicky, 'attachment; filename="twb-reklamy-statistiky-'));
    eq(9, count(explode("\r\n", trim($r['body']))), 'hlavička, 7 dní a součet');
    ok(str_contains($r['body'], "\r\nCelkem;;40;4;10,00;1;25,00;123,45"));
    eq(200, $b->jdi('index.php?p=statistiky&podle=neznamo&kampan=abc')['code']);
});

test('nápověda: obsah projde kontrolou, stránky, hledání, tlačítko „?“ podle stránky', function () use ($b) {
    $n = (new App(KOREN . '/apps/reklamy', ['data_dir' => docasna('nap')]))->napoveda();
    [$chyby, $overit] = $n->zkontroluj();
    eq([], $chyby);
    eq(0, $overit);
    ok(count($n->poradi()) >= 15);
    foreach (array_keys(Reklamy\Ui::MENU) as $p) ok(isset($n->obsah()['context'][$p]), "stránka $p nemá nápovědu");
    $r = $b->jdi('index.php?p=napoveda');
    eq(200, $r['code']);
    ok(str_contains($r['body'], '<h1>Co aplikace umí</h1>') && str_contains($r['body'], 'Kampaně a bannery'), 'první stránka a obsah');
    $r = $b->jdi('index.php?p=napoveda&s=konverze');
    ok(str_contains($r['body'], '<h1>Konverze a trackery</h1>') && str_contains($r['body'], '<code>t.js</code>'));
    ok(str_contains($r['body'], 'href="index.php?p=napoveda&amp;s=konverze-se-neprirazuji"'), 'související stránka');
    eq(404, $b->jdi('index.php?p=napoveda&s=../config')['code']);
    $r = $b->jdi('index.php?p=napoveda&q=' . rawurlencode('frekvence cookies'));
    ok(str_contains($r['body'], 'Cílení a frekvence'), 'hledání');
    $r = $b->jdi('index.php?p=statistiky');
    ok(str_contains($r['body'], 'class="pomoc" href="index.php?p=napoveda&amp;s=statistiky"'), 'tlačítko ? u nadpisu');
});
