<?php
declare(strict_types=1);
// TWB Reklamy 0.1: databáze, kampaně, bannery, galerie, weby, zóny, zálohy.
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Schema, Vstup, Kampane, Zony, ProtokolDb, Chyba};
use TWB\Jadro\Migrace;

echo "Reklamy: správa\n";

test('migrace vytvoří tabulky aplikace i modulů a podruhé nic nemění', function () {
    $app = novaApp();
    foreach (App::TABULKY as $t) $app->db->q("SELECT COUNT(*) FROM $t")->fetchColumn();
    $m = new Migrace($app->db);
    eq(Schema::verze(), $m->verze('reklamy'));
    ok($m->verze('prihlaseni') > 0 && $m->verze('galerie') > 0, 'migrace modulů');
    eq((string)Schema::verze(), $app->nastaveni()->dej('schema_version'));
    eq('0', $app->nastaveni()->dej('img_auto'), 'galerie nesmí obrázky bannerů zmenšovat');
    eq(false, $app->migruj());
});

test('aplikace je nainstalovaná až s heslem', function () {
    $app = novaApp();
    eq(false, $app->nainstalovano());
    $app->heslo()->nastav('tajneheslo1');
    eq(true, $app->nainstalovano());
    eq(true, $app->heslo()->over('tajneheslo1'));
});

test('Vstup: datum a čas, adresy, čísla', function () {
    eq(mktime(10, 30, 0, 11, 21, 2026), Vstup::cas(['x' => '2026-11-21T10:30'], 'x', 'X'));
    eq(mktime(10, 30, 0, 11, 21, 2026), Vstup::cas(['x' => '21. 11. 2026 10:30'], 'x', 'X'));
    eq(null, Vstup::cas(['x' => ''], 'x', 'X'));
    throws(fn() => Vstup::cas(['x' => '2026-13-45T10:30'], 'x', 'X'), 'platné datum');
    eq('2026-11-21T10:30', Vstup::casDoPole(mktime(10, 30, 0, 11, 21, 2026)));
    eq('https://kazbo.eu/a?b=č', Vstup::adresa(['u' => ' https://kazbo.eu/a?b=č '], 'u', 'U'));
    foreach (['javascript:alert(1)', 'ftp://x.cz', 'https://', 'http:// x', 'data:text/html,1'] as $spatna) throws(fn() => Vstup::adresa(['u' => $spatna], 'u', 'U'), 'http');
    eq(1200, Vstup::cislo(['n' => '1 200'], 'n', 'N', 1, 5000));
    throws(fn() => Vstup::cislo(['n' => '1.5'], 'n', 'N', 1, 5), 'celé číslo');
    throws(fn() => Vstup::cislo(['n' => '9'], 'n', 'N', 1, 5), 'od 1 do 5');
});

test('kampaň: uložení, kontrola vstupu, stav, tagy', function () {
    $app = novaApp();
    $k = $app->kampane();
    throws(fn() => $k->uloz(['name' => ' ']), 'Název');
    throws(fn() => $k->uloz(['name' => 'A', 'priority' => '11']), 'Priorita');
    throws(fn() => $k->uloz(['name' => 'A', 'kind' => 'vip']), 'Druh');
    throws(fn() => $k->uloz(['name' => 'A', 'starts_at' => '2026-12-01T00:00', 'ends_at' => '2026-11-01T00:00']), 'Konec');
    throws(fn() => $k->uloz(['name' => 'A', 'tags' => implode(',', range(1, 11))]), 'nejvýš 10');
    $id = $k->uloz(['name' => 'Vánoce', 'kind' => 'exclusive', 'priority' => '8', 'weight' => '3', 'starts_at' => '2026-12-01T00:00',
        'limit_clicks' => '500', 'tags' => 'sezóna, Vánoce, sezóna']);
    $r = $k->radek($id);
    eq(['Vánoce', 'exclusive', 8, 3, 500, null], [$r['name'], $r['kind'], $r['priority'], $r['weight'], $r['limit_clicks'], $r['limit_impressions']]);
    eq(['sezóna', 'Vánoce'], $k->tagy()->tagyPolozky($r));
    eq(false, Kampane::bezi($r, mktime(0, 0, 0, 11, 30, 2026)));
    eq(true, Kampane::bezi($r, mktime(0, 0, 0, 12, 2, 2026)));
    eq('od 1. 12. 2026 00:00', Kampane::termin($r));
    $k->uloz(['name' => 'Vánoce 2026', 'kind' => 'exclusive', 'starts_at' => '2026-12-01T00:00', 'tags' => 'Vánoce'], $id);
    eq('Vánoce 2026', $k->radek($id)['name']);
    $k->nastavStav($id, 'paused');
    eq(false, Kampane::bezi($k->radek($id), mktime(0, 0, 0, 12, 2, 2026)));
    throws(fn() => $k->nastavStav($id, 'smazana'), 'Neplatný stav');
    eq(1, count($k->seznam()));
    eq(1, count($k->seznam('', 'VÁNOCE')), 'filtr tagu bez ohledu na velikost písmen');
    $k->nastavStav($id, 'archived');
    eq(0, count($k->seznam()));
    eq(1, count($k->seznam('archived')));
    throws(fn() => $k->radek(999), 'nenalezena');
});

test('banner z galerie převezme rozměr obrázku; použitý obrázek nejde smazat', function () {
    $app = novaApp();
    $kid = kampan($app);
    $g = $app->galerie();
    $m = $g->uloz(pngSoubor(300, 250), 'ctverec.png');
    $b = $app->bannery();
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'B']), 'Vyberte obrázek');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'B', 'media_id' => 999]), 'v galerii není');
    $id = $b->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'B', 'media_id' => $m['id'], 'target_url' => 'https://kazbo.eu/akce']);
    $r = $b->radek($id);
    eq([300, 250, 'https://kazbo.eu/akce', '_blank'], [$r['width'], $r['height'], $r['target_url'], $r['target']]);
    eq(300, $g->radek($m['id'])['sirka'], 'obrázek se nezmenšil');
    throws(fn() => $g->smaz([$m['id']]), 'používá banner');
    $b->smaz($id);
    eq(['ctverec.png'], $g->smaz([$m['id']]));
});

test('banner: druhy, rozměry, cílová adresa', function () {
    $app = novaApp();
    $kid = kampan($app);
    $b = $app->bannery();
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'flash', 'name' => 'X']), 'Druh banneru');
    throws(fn() => $b->uloz(['campaign_id' => 999, 'type' => 'html', 'name' => 'X']), 'Kampaň nenalezena');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'external', 'name' => 'X', 'width' => 728, 'height' => 90]), 'Adresa obrázku');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'X', 'width' => 728, 'height' => 90, 'html' => '  ']), 'HTML kód');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'X', 'html' => '<b>x</b>']), 'šířku a výšku');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'X', 'width' => 5000, 'height' => 90, 'html' => 'x']), 'Šířka');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'X', 'width' => 1, 'height' => 1, 'html' => 'x', 'target_url' => 'javascript:alert(1)']), 'Cílová adresa');
    throws(fn() => $b->uloz(['campaign_id' => $kid, 'type' => 'html', 'name' => 'X', 'width' => 1, 'height' => 1, 'html' => str_repeat('x', 512 * 1024 + 1)]), '512 kB');
    $id = $b->uloz(['campaign_id' => $kid, 'type' => 'external', 'name' => 'Ext', 'width' => '728', 'height' => '90', 'image_url' => 'https://cdn.kazbo.eu/b.png', 'target' => '_top']);
    $r = $b->radek($id);
    eq(['external', 'https://cdn.kazbo.eu/b.png', '_top', null], [$r['type'], $r['image_url'], $r['target'], $r['html']]);
    // druh banneru po vytvoření nejde změnit
    $b->uloz(['type' => 'html', 'name' => 'Ext2', 'width' => 728, 'height' => 90, 'image_url' => 'https://cdn.kazbo.eu/c.png'], $id);
    eq(['external', 'Ext2', 'https://cdn.kazbo.eu/c.png'], [$b->radek($id)['type'], $b->radek($id)['name'], $b->radek($id)['image_url']]);
    $kopie = $b->duplikuj($id);
    eq('Ext2 (kopie)', $b->radek($kopie)['name']);
    $b->nastavStav($kopie, 'paused');
    eq('paused', $b->radek($kopie)['status']);
});

test('kopie kampaně i s bannery je pozastavená; smazání kampaně smaže bannery i přiřazení', function () {
    $app = novaApp();
    $kid = kampan($app, ['name' => 'Léto']);
    htmlBanner($app, $kid, 300, 250);
    htmlBanner($app, $kid, 728, 90);
    $web = $app->weby()->uloz(['name' => 'kazbo.eu']);
    $zona = $app->zony()->uloz(['site_id' => $web, 'name' => 'Vpravo']);
    $app->zony()->prirad($zona, [$kid], []);
    $nova = $app->kampane()->duplikuj($kid);
    $r = $app->kampane()->radek($nova);
    eq(['Léto (kopie)', 'paused'], [$r['name'], $r['status']]);
    eq(2, count($app->bannery()->seznam($nova)));
    eq([], $app->zony()->prirazeni($zona)['kampane'] === [$kid] ? [] : ['kopie nesmí převzít zóny']);
    $app->kampane()->smaz($kid);
    eq(0, count($app->bannery()->seznam($kid)));
    eq([], $app->zony()->prirazeni($zona)['kampane']);
    eq(2, count($app->bannery()->seznam($nova)));
});

test('zóny: rozměr, přiřazení kampaní a bannerů, změna rozměru', function () {
    $app = novaApp();
    $ids = function (array $rows): array { $a = array_map(fn($b) => (int)$b['id'], $rows); sort($a); return $a; };
    $web = $app->weby()->uloz(['name' => 'kazbo.eu', 'url' => 'https://kazbo.eu']);
    throws(fn() => $app->weby()->uloz(['name' => 'X', 'url' => 'kazbo.eu']), 'http');
    throws(fn() => $app->zony()->uloz(['site_id' => $web, 'name' => 'Z', 'width' => 300]), 'šířku i výšku');
    throws(fn() => $app->zony()->uloz(['site_id' => 999, 'name' => 'Z']), 'Web nenalezen');
    $zPevna = $app->zony()->uloz(['site_id' => $web, 'name' => 'Ctverec', 'width' => 300, 'height' => 250, 'fallback' => '<p>Reklama</p>']);
    $zVolna = $app->zony()->uloz(['site_id' => $web, 'name' => 'Cokoli']);
    eq('300×250', Zony::rozmer($app->zony()->radek($zPevna)));
    eq('libovolný', Zony::rozmer($app->zony()->radek($zVolna)));
    $kid = kampan($app);
    $b300 = htmlBanner($app, $kid, 300, 250);
    $b728 = htmlBanner($app, $kid, 728, 90);
    $jina = kampan($app);
    $j300 = htmlBanner($app, $jina, 300, 250);
    throws(fn() => $app->zony()->prirad($zPevna, [], [$b728]), 'nemá rozměr zóny');
    $app->zony()->prirad($zPevna, [$kid], [$j300, $j300]);
    eq(['kampane' => [$kid], 'bannery' => [$j300]], $app->zony()->prirazeni($zPevna));
    eq([$b300, $j300], $ids($app->zony()->bannery($zPevna)), 'z kampaně jen banner vhodného rozměru');
    $app->zony()->prirad($zVolna, [$kid], [$j300]);
    eq(3, count($app->zony()->bannery($zVolna)));
    // nový banner kampaně se v zóně objeví sám
    $novy = htmlBanner($app, $kid, 300, 250);
    eq(true, in_array($novy, array_map(fn($b) => (int)$b['id'], $app->zony()->bannery($zPevna)), true));
    eq(2, count($app->zony()->zonyBanneru($app->bannery()->radek($b300))));
    eq(1, count($app->zony()->zonyBanneru($app->bannery()->radek($b728))));
    // změna rozměru banneru odebere přímé přiřazení do zóny s jiným rozměrem (volná zóna zůstane)
    $app->bannery()->uloz(['name' => 'Jiny', 'width' => 320, 'height' => 100, 'html' => 'x'], $j300);
    eq([], $app->zony()->prirazeni($zPevna)['bannery']);
    eq([$j300], $app->zony()->prirazeni($zVolna)['bannery']);
    // změna rozměru zóny odebere nevhodné přímé bannery
    $app->zony()->uloz(['name' => 'Cokoli', 'width' => 728, 'height' => 90], $zVolna);
    eq([], $app->zony()->prirazeni($zVolna)['bannery']);
    eq([$b728], array_map(fn($b) => (int)$b['id'], $app->zony()->bannery($zVolna)));
    // smazání webu smaže zóny i přiřazení
    $app->weby()->smaz($web);
    eq(0, (int)$app->db->q('SELECT COUNT(*) FROM zones')->fetchColumn());
    eq(0, (int)$app->db->q('SELECT COUNT(*) FROM zone_campaigns')->fetchColumn());
});

test('protokol: úrovně a úklid', function () {
    $app = novaApp();
    $p = $app->host->protokol;
    ok($p instanceof ProtokolDb);
    $app->db->q('DELETE FROM log');
    $p->zapis('warning', 'a');
    $p->zapis('success', 'b');
    $p->zapis('error', 'c');
    $p->zapis('cokoli', 'd');
    eq(['info', 'error', 'info', 'warn'], array_column($p->posledni(), 'level'));
    eq(['c'], array_column($p->posledni(10, 'error'), 'message'));
    eq(2, $p->uklid(2));
    eq(['d', 'c'], array_column($p->posledni(), 'message'));
});

test('záloha a obnova: databáze i obrázky galerie, místní nastavení zůstane', function () {
    $app = novaApp();
    $app->heslo()->nastav('tajneheslo1');
    $app->nastaveni()->uloz('backup_password', 'heslo-zaloh-123');
    $kid = kampan($app, ['name' => 'Zálohovaná']);
    $m = $app->galerie()->uloz(pngSoubor(120, 600), 'sloup.png');
    $app->bannery()->uloz(['campaign_id' => $kid, 'type' => 'image', 'name' => 'Sloup', 'media_id' => $m['id']]);
    $z = $app->zalohy()->vytvor('rucni', true);
    ok($z['files'], 'záloha se soubory');
    // změny po záloze
    $app->kampane()->smaz($kid);
    $soubor = $app->galerie()->soubor($m['id'])['cesta'];
    $app->galerie()->smaz([$m['id']]);
    ok(!is_file($soubor));
    $app->nastaveni()->uloz('login_fails', '3');
    $app->zalohy()->obnov($app->zalohy()->cesta($z['name']), 'heslo-zaloh-123');
    $app->nastaveni()->obnov();
    eq('Zálohovaná', $app->kampane()->radek($kid)['name']);
    eq(1, count($app->bannery()->seznam($kid)));
    ok(is_file($app->galerie()->soubor($m['id'])['cesta']), 'obrázek obnoven');
    eq('3', $app->nastaveni()->dej('login_fails'), 'místní nastavení se obnovou nepřepíše');
    eq(true, $app->heslo()->over('tajneheslo1'));
});
