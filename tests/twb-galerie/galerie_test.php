<?php
declare(strict_types=1);
// TWB Media galerie 0.2: média z pohledu aplikace – kampaně, filtry, veřejné klíče, náhledy, rozsahy bajtů, převod schématu.
require __DIR__ . '/pomocne.php';

use MediaGalerie\{App, Media, Vydej, Schema, Chyba};
use TWB\Jadro\Migrace;

echo "Media galerie: galerie\n";

/** Nahraje soubor do galerie aplikace jako po skončení nahrávání. */

function nahraj(App $app, string $nazev, string $obsah, ?int $kampan = null, array $tagy = []): int
{
    $f = docasna('soubor');
    file_put_contents($f, $obsah);
    $v = $app->galerie()->uloz($f, $nazev, $tagy);
    $app->media()->poNahrani($v['id'], $kampan);
    return $v['id'];
}

function png(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 200, 40, 40));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}

test('schéma: převod z verze 1 doplní sloupce, veřejné klíče stávajícím médiím a tabulku API klíčů', function () {
    $dir = docasna('stare');
    mkdir($dir, 0775, true);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $dir]);
    $m = new Migrace($app->db);
    $m->spust('prihlaseni', \TWB\Prihlaseni\Migrace::class);
    $m->spust('galerie', \TWB\Galerie\Migrace::class);
    $app->db->q("INSERT INTO module_versions (module, version) VALUES ('twb-galerie', 1)");
    $app->db->q("INSERT INTO media (filename, original_name, mime, kind, size, md5, created_at) VALUES ('a.png', 'a.png', 'image/png', 'image', 1, 'x', '2026-11-22 10:00:00')");
    $app->migruj();
    eq(\MediaGalerie\Schema::verze(), $m->verze('twb-galerie'));
    $app->db->q('SELECT COUNT(*) FROM api_keys')->fetchColumn();
    $r = $app->db->q('SELECT * FROM media')->fetch();
    ok(preg_match('/^[0-9a-f]{24}$/', (string)$r['public_key']) === 1, 'klíč doplněn');
    ok(array_key_exists('campaign_id', $r) && array_key_exists('note', $r));
    eq('0', $app->nastaveni()->dej('img_auto'), 'bez nastavení se komprese po nahrání vypne');
});

test('nová instalace: komprese po nahrání vypnutá, nahrané médium má veřejný klíč', function () {
    $app = novaApp();
    eq('0', $app->nastaveni()->dej('img_auto'));
    $id = nahraj($app, 'velky.png', png(3000, 2000));
    $r = $app->media()->radek($id);
    eq([3000, 2000], [(int)$r['width'], (int)$r['height']], 'nezmenšeno');
    ok(preg_match('/^[0-9a-f]{24}$/', (string)$r['public_key']) === 1);
    eq($r, $app->media()->podleKlice($r['public_key']));
    eq(null, $app->media()->podleKlice('neplatny'));
    eq(null, $app->media()->podleKlice(str_repeat('0', 24)));
});

test('veřejný odkaz: adresa s názvem, nový klíč zneplatní starý, vypnutí', function () {
    $app = novaApp(['base_url' => 'https://galerie.kazbo.eu']);
    $id = nahraj($app, 'Léto 2026 (banner).png', png(10, 10));
    $r = $app->media()->radek($id);
    eq('https://galerie.kazbo.eu/soubor.php/' . $r['public_key'] . '/L%C3%A9to-2026-banner-.png', $app->media()->verejnaAdresa($r));
    $novy = $app->media()->novyOdkaz($id);
    ok($novy !== $r['public_key']);
    eq(null, $app->media()->podleKlice($r['public_key']), 'starý klíč neplatí');
    $app->media()->zrusOdkaz($id);
    eq(null, $app->media()->verejnaAdresa($app->media()->radek($id)));
    eq(null, $app->media()->podleKlice($novy));
});

test('kampaně: založení, duplicitní název, přiřazení, smazání nechá média bez kampaně', function () {
    $app = novaApp();
    $k = $app->kampane()->uloz(['name' => 'Vánoce 2026', 'note' => 'banery']);
    throws(fn() => $app->kampane()->uloz(['name' => 'vánoce 2026']), 'už existuje');
    throws(fn() => $app->kampane()->uloz(['name' => '  ']), 'Název');
    $a = nahraj($app, 'a.png', png(10, 10), $k);
    $b = nahraj($app, 'b.png', png(10, 10));
    eq((string)$k, (string)$app->media()->radek($a)['campaign_id']);
    eq(1, $app->media()->nastavKampan([$a, $b], $k), 'změní se jen b');
    throws(fn() => $app->media()->nastavKampan([$a], 999), 'nenalezena');
    $s = $app->kampane()->seznam();
    eq([1, 2], [count($s), (int)$s[0]['pocet']]);
    $app->kampane()->uloz(['name' => 'Vánoce 2026', 'note' => ''], $k);   // stejný název u sebe sama smí
    eq(2, $app->kampane()->smaz($k));
    eq(null, $app->media()->radek($a)['campaign_id']);
    throws(fn() => $app->kampane()->radek($k), 'nenalezena');
});

test('seznam: hledání, druh, kampaň, bez kampaně, tag, archiv, řazení a stránkování', function () {
    $app = novaApp();
    $k = $app->kampane()->uloz(['name' => 'Jaro']);
    $a = nahraj($app, 'jaro-banner.png', png(10, 10), $k, ['Banner']);
    $b = nahraj($app, 'video.mp4', mp4(5000), $k);
    $c = nahraj($app, 'leták 50%.png', png(20, 20), null, ['banner', 'Tisk']);
    $app->media()->nastavPoznamku($b, 'úvodní video na web');
    $id = fn(array $f, int $n = 48) => array_map(fn($r) => (int)$r['id'], $app->media()->seznam($f, $n)['polozky']);
    eq([$c, $b, $a], $id([]));
    eq([$a], $id(['q' => 'JARO']));
    eq([$b], $id(['q' => 'na web']), 'hledá i v poznámce');
    eq([$c], $id(['q' => '50%']), 'zástupné znaky LIKE se berou doslova');
    eq([], $id(['q' => '_']));
    eq([$b], $id(['druh' => 'video']));
    eq([$b, $a], $id(['kampan' => (string)$k]));
    eq([$c], $id(['kampan' => '0']));
    eq([$c, $a], $id(['tag' => 'BANNER']));
    eq([$a, $b, $c], $id(['razeni' => 'stare']));
    eq([$a, $c, $b], $id(['razeni' => 'nazev']));
    eq($b, $id(['razeni' => 'velikost'])[0]);
    $app->galerie()->archivuj([$a], true);
    eq([$c, $b], $id([]));
    eq([$a], $id(['archiv' => true]));
    $s = $app->media()->seznam(['strana' => 9], 1);
    eq([2, 2, 2], [$s['celkem'], $s['stran'], $s['strana']], 'strana za koncem = poslední');
    eq(['image' => 1, 'video' => 1, 'pdf' => 0, 'archiv' => 1], array_intersect_key($app->media()->souhrn(), array_flip(['image', 'video', 'pdf', 'archiv'])));
});

test('náhled obrázku: zmenšený JPEG jen pro velké obrázky, po nahrazení nový, úklid starých', function () {
    $app = novaApp();
    $maly = nahraj($app, 'maly.png', png(100, 80));
    eq(null, $app->media()->nahled($app->media()->radek($maly)), 'malý obrázek se posílá celý');
    $id = nahraj($app, 'velky.png', png(1600, 900));
    $n = $app->media()->nahled($app->media()->radek($id));
    ok($n !== null && is_file($n));
    eq([480, 270, IMAGETYPE_JPEG], array_slice(getimagesize($n), 0, 3));
    $f = docasna('novy');
    file_put_contents($f, png(900, 1600));
    $app->galerie()->uloz($f, 'novy.png', [], $id);
    $n2 = $app->media()->nahled($app->media()->radek($id));
    ok($n2 !== $n && !is_file($n), 'starý náhled smazán');
    eq([270, 480], array_slice(getimagesize($n2), 0, 2));
    $app->galerie()->smaz([$id]);
    eq(1, $app->media()->uklidNahledy());
    eq(null, $app->media()->nahled(['kind' => 'video'] + $app->media()->radek($maly)));
});

test('rozsah bajtů (Range)', function () {
    eq([0, 99], Vydej::rozsah('bytes=0-99', 1000));
    eq([500, 999], Vydej::rozsah('bytes=500-', 1000));
    eq([900, 999], Vydej::rozsah('bytes=-100', 1000));
    eq([0, 999], Vydej::rozsah('bytes=-5000', 1000));
    eq([990, 999], Vydej::rozsah('bytes=990-5000', 1000));
    eq([0, 9], Vydej::rozsah('bytes=0-9,20-29', 1000), 'víc rozsahů: první');
    eq(null, Vydej::rozsah('bytes=1000-', 1000));
    eq(null, Vydej::rozsah('bytes=50-10', 1000));
    eq(null, Vydej::rozsah('bytes=-', 1000));
    eq(null, Vydej::rozsah('items=0-1', 1000));
    eq(null, Vydej::rozsah('bytes=0-1', 0));
});

test('kampaň se zálohuje, Passkey ne', function () {
    ok(in_array('campaigns', App::TABULKY, true));
    ok(array_search('campaigns', App::TABULKY, true) < array_search('media', App::TABULKY, true));
});
