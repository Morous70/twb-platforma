<?php
declare(strict_types=1);
// TWB Media galerie 0.8.11: vlastní grafické logo (web, přihlášení, administrace; logo.php) a až pět úvodních fotek, náhodně jedna.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Chyba, Web, Schema};

echo "Media galerie: 0.8.11\n";

function png0811(int $w = 240, int $h = 80): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 30, 90, 60));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}

/** Aplikace s veřejnou kategorií a šesti veřejnými fotkami. @return array{App, list<int>} */
function data0811(?App $app = null): array
{
    $app ??= novaApp();
    $k = $app->kategorie()->uloz(['name' => 'Krajina']);
    $app->web()->ulozKategorii($k, ['web_public' => '1']);
    $ids = [];
    for ($i = 1; $i <= 6; $i++) { $f = docasna('f'); file_put_contents($f, jpegMeta(w: 200 + $i, h: 120)); $ids[] = (int)$app->media()->nahraj($f, "Foto $i.jpg", [], $k)['id']; }
    return [$app, $ids];
}

test('úvodní fotky: nastavení z 0.8.10 (jedna uvod_id) se převede na seznam', function () {
    [$app, $ids] = data0811();
    $app->nastaveni()->uloz('web', json_encode(['uvod_id' => $ids[2], 'nadpis' => 'Starý']));
    $n = $app->web()->nastaveni();
    eq([[$ids[2]], $ids[2], 'Starý'], [$n['uvod_ids'], $n['uvod_id'], $n['nadpis']]);
    eq($ids[2], (int)$app->web()->uvodni()['id']);
    eq(true, $n['logo_nazev'], 'výchozí: název vedle loga');
    eq(9, Schema::verze());
});

test('úvodní fotky: až pět, náhodně jedna, neplatné se přeskočí; formulář i tlačítko v detailu', function () {
    [$app, $ids] = data0811();
    $web = $app->web();
    $zaklad = ['nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4', 'doporucene_nadpis' => 'D', 'doporucene_sloupce' => '4', 'doporucene_radky' => '2'];
    $web->uloz('zaklad', $zaklad + ['uvod_1' => (string)$ids[0], 'uvod_2' => '', 'uvod_3' => (string)$ids[1], 'uvod_4' => (string)$ids[0], 'uvod_5' => (string)$ids[2]]);
    eq([$ids[0], $ids[1], $ids[2]], $web->nastaveni()['uvod_ids'], 'prázdné místo a opakování vynechány, pořadí zachované');
    $videno = [];
    for ($i = 0; $i < 200; $i++) $videno[(int)$web->uvodni()['id']] = true;
    ksort($videno);
    eq([$ids[0], $ids[1], $ids[2]], array_keys($videno), 'náhodně se ukážou všechny tři');
    // fotka bez odkazu se přeskočí, nová přidaná na konec, víc než pět nejde
    $app->media()->zrusOdkaz($ids[1]);
    eq([$ids[0], $ids[2]], array_map(fn($m) => (int)$m['id'], $web->uvodniFotky()));
    throws(fn() => $web->uloz('zaklad', $zaklad + ['uvod_1' => (string)$ids[1]]), 'veřejným odkazem');
    $web->nastavUvodni($ids[3]); $web->nastavUvodni($ids[4]); $web->nastavUvodni($ids[5]);
    eq([$ids[0], $ids[2], $ids[3], $ids[4], $ids[5]], $web->nastaveni()['uvod_ids'], 'místo po fotce bez odkazu se uvolnilo');
    $web->nastavUvodni($ids[0]);   // už je mezi úvodními: nic
    $f = docasna('f'); file_put_contents($f, jpegMeta()); $sedma = (int)$app->media()->nahraj($f, 'Sedmá.jpg', [], null)['id'];
    throws(fn() => $web->nastavUvodni($sedma), 'víc nejde');
    ok($web->jeUvodni($ids[3]));
    $web->odeberUvodni($ids[3]);
    ok(!$web->jeUvodni($ids[3]));
    eq($ids[0], $web->nastaveni()['uvod_id'], 'uvod_id = první (starší klienti)');
    // starší formulář s jedním uvod_id; formulář bez úvodních polí seznam nemění
    $web->uloz('zaklad', $zaklad + ['uvod_id' => (string)$ids[4]]);
    eq([$ids[4]], $web->nastaveni()['uvod_ids']);
    $web->uloz('zaklad', $zaklad);
    eq([$ids[4]], $web->nastaveni()['uvod_ids']);
    // žádná zvolená = obálka první veřejné galerie
    $web->uloz('zaklad', $zaklad + ['uvod_1' => '']);
    eq([], $web->nastaveni()['uvod_ids']);
    ok($web->uvodni() !== null, 'automaticky obálka');
});

test('logo: PNG, SVG s rozměry, špatný soubor odmítne, smazání; volba název vedle loga', function () {
    $app = novaApp();
    $web = $app->web();
    eq([null, ''], [$web->logo(), $web->logoAdresa()]);
    $web->ulozLogoData(png0811());
    $l = $web->logo();
    eq(['image/png', 240, 80], [$l['mime'], $l['width'], $l['height']]);
    ok(preg_match('/^logo\.php\?v=[0-9a-f]{10}$/', $web->logoAdresa()) === 1);
    eq(png0811(), $web->logoSoubor()['data'], 'obsah beze změny');
    $svg = "\xEF\xBB\xBF<?xml version=\"1.0\"?>\n<!-- logo -->\n<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 300 100\"><rect width=\"300\" height=\"100\" fill=\"#285\"/></svg>";
    $web->ulozLogoData($svg);
    eq(['image/svg+xml', 300, 100], [$web->logo()['mime'], $web->logo()['width'], $web->logo()['height']]);
    throws(fn() => $web->ulozLogoData('<html><body>ne</body></html>'), 'PNG, JPG');
    throws(fn() => $web->ulozLogoData(mp4(3000)), 'PNG, JPG');
    throws(fn() => $web->ulozLogoData(png0811(8, 8)), 'malé');
    throws(fn() => $web->ulozLogoData(str_repeat('a', Web::LOGO_MAX + 1)), '1 MB');
    eq('image/svg+xml', $web->logo()['mime'], 'chybný soubor staré logo nesmaže');
    $web->uloz('zaklad', ['nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'doporucene_nadpis' => 'D', 'doporucene_sloupce' => '4', 'doporucene_radky' => '2', 'logo_nazev_pole' => '1']);
    eq(false, $web->nastaveni()['logo_nazev'], 'nezaškrtnuté = bez názvu');
    $web->smazLogo();
    eq(null, $web->logo());
    // záloha (JSON) logo přenese – obsah je v base64
    $web->ulozLogoData(png0811());
    $app->nastaveni()->uloz('backup_password', 'heslo-zaloh-1');
    $z = $app->zalohy()->vytvor();
    $web->smazLogo();
    $app->zalohy()->obnov($app->zalohy()->cesta($z['name']), 'heslo-zaloh-1');
    eq(png0811(), $app->web()->logoSoubor()['data'] ?? null, 'logo po obnově zálohy');
});

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);

test('na serveru: nahrání loga formulářem, logo.php, záhlaví webu, přihlášení a administrace', function () use ($s, $b) {
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ids] = data0811($app);
    $anon = new Prohlizec($s);
    eq(404, $anon->jdi('logo.php')['code'], 'bez loga 404');
    ok(str_contains($anon->jdi('verejne.php')['body'], 'class="logo-znak"'), 'bez loga znak');
    $r = $b->jdi('index.php?p=web');
    ok(str_contains($r['body'], 'enctype="multipart/form-data"') && str_contains($r['body'], 'name="logo"') && str_contains($r['body'], 'name="uvod_5"'));
    $png = docasna('logo') . '.png'; file_put_contents($png, png0811());
    $pole = ['akce' => 'zaklad', 'zapnuto' => '1', 'nazev' => 'Moje fotky', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4',
        'doporucene_nadpis' => 'D', 'doporucene_sloupce' => '4', 'doporucene_radky' => '2', 'logo_nazev_pole' => '1',
        'uvod_1' => (string)$ids[0], 'uvod_2' => (string)$ids[1], 'uvod_3' => '', 'uvod_4' => '', 'uvod_5' => ''];
    $r = $b->odesli('index.php?p=web', $pole + ['logo' => new CURLFile($png, 'image/png', 'logo.png')]);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'), mb_substr(strip_tags($r['body']), 0, 400));
    ok(str_contains($r['body'], 'class="logo logo-vlastni" href="index.php"><img class="logo-obrazek" src="logo.php?v='), 'logo v administraci');
    ok(!str_contains($r['body'], '<span>' . App::NAZEV . '</span></a><ul>'), 'bez názvu');
    $adresa = $app->web()->logoAdresa();
    $r = $anon->jdi($adresa);
    eq([200, 'image/png', png0811()], [$r['code'], $r['type'], $r['body']]);
    ok(str_contains($anon->posledniHlavicky, 'immutable') && str_contains($anon->posledniHlavicky, 'sandbox'));
    $anon->hlavicky = ['If-None-Match: "' . md5(png0811()) . '"'];
    eq(304, $anon->jdi($adresa)['code']);
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<img class="logo-obrazek" src="' . $adresa . '" alt="Moje fotky">') && !str_contains($r['body'], 'logo-znak'), 'web: logo bez názvu');
    ok(str_contains($anon->posledniHlavicky, 'Cache-Control: no-cache'), 'hlavní stránka se ověřuje');
    ok(preg_match('/class="hero-fotka" src="[^"]+"/', $r['body']) === 1);
    $prihl = new Prohlizec($s);
    ok(str_contains($prihl->jdi('index.php')['body'], 'class="logo-obrazek" src="' . $adresa . '"'), 'logo na přihlášení');
    // s názvem
    $r = $b->odesli('index.php?p=web', $pole + ['logo_nazev' => '1']);
    ok(str_contains($anon->jdi('verejne.php')['body'], '<img class="logo-obrazek" src="' . $adresa . '" alt=""><span class="logo-nazev">Moje fotky</span>'));
    ok(str_contains($r['body'], 'alt=""><span>' . App::NAZEV . '</span>'), 'administrace s názvem');
    // špatný soubor: chyba, nic se neuloží
    $txt = docasna('t') . '.png'; file_put_contents($txt, 'neni obrazek');
    $r = $b->odesli('index.php?p=web', ['nazev' => 'Jiný'] + $pole + ['logo' => new CURLFile($txt, 'image/png', 'x.png')]);
    ok(str_contains($r['body'], 'Logo musí být obrázek'));
    eq('Moje fotky', (new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]))->web()->nastaveni()['nazev']);
    // detail média: přidat / odebrat z úvodních
    $r = $b->jdi('index.php?p=medium&id=' . $ids[2]);
    ok(str_contains($r['body'], 'Přidat mezi úvodní fotky'));
    $r = $b->odesli('index.php?p=medium&id=' . $ids[2], ['akce' => 'web-uvodni']);
    ok(str_contains($r['body'], 'mezi úvodními fotkami webu (3 z 5)') && str_contains($r['body'], 'Odebrat z úvodních fotek'), mb_substr(strip_tags($r['body']), 0, 300));
    $r = $b->odesli('index.php?p=medium&id=' . $ids[2], ['akce' => 'web-uvodni']);
    ok(str_contains($r['body'], 'už není mezi úvodními'));
    // odebrání loga
    $b->odesli('index.php?p=web', $pole + ['logo_smazat' => '1']);
    eq(404, $anon->jdi('logo.php')['code']);
    ok(str_contains($anon->jdi('verejne.php')['body'], 'class="logo-znak"'));
});
