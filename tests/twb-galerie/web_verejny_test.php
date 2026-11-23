<?php
declare(strict_types=1);
// TWB Media galerie 0.8.0: veřejný web (jen veřejné kategorie a média s veřejným odkazem), nastavení webu a hromadná komprese po krocích.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Web, Komprese, Chyba};

echo "Media galerie: veřejný web a komprese\n";

function fotka(int $w = 1600, int $h = 1000, int $seme = 1): string
{
    mt_srand($seme);
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 300; $i++) imagefilledrectangle($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, $w), mt_rand(0, $h), mt_rand(0, 0xFFFFFF));
    $f = docasna('foto') . '.jpg';
    imagejpeg($im, $f, 98);
    return $f;
}

/** Aplikace s kategoriemi: Veřejná (3 fotky: běžná, bez odkazu, v archivu + video) a Soukromá (1 fotka). @return array{App, int, int, array<string,int>} */
function webData(?App $app = null): array
{
    $app ??= novaApp();
    $k = $app->kategorie();
    $ver = $k->uloz(['name' => 'Příroda']);
    $sou = $k->uloz(['name' => 'Soukromé']);
    $app->web()->ulozKategorii($ver, ['web_public' => '1', 'web_description' => 'Lesy a hory', 'web_order' => '1']);
    $m = $app->media();
    $ids = [
        'ok' => $m->nahraj(fotka(seme: 1), 'Les.jpg', [], $ver)['id'],
        'bezodkazu' => $m->nahraj(fotka(seme: 2), 'Bez odkazu.jpg', [], $ver)['id'],
        'archiv' => $m->nahraj(fotka(seme: 3), 'Archiv.jpg', [], $ver)['id'],
        'soukrome' => $m->nahraj(fotka(seme: 4), 'Tajné.jpg', [], $sou)['id'],
    ];
    $f = docasna('v'); file_put_contents($f, mp4(2000));
    $ids['video'] = $m->nahraj($f, 'klip.mp4', [], $ver)['id'];
    $m->zrusOdkaz($ids['bezodkazu']);
    $app->galerie()->archivuj([$ids['archiv']], true);
    return [$app, $ver, $sou, $ids];
}

test('web: jen veřejná kategorie a v ní obrázky s odkazem mimo archiv', function () {
    [$app, $ver, $sou, $ids] = webData();
    $web = $app->web();
    $k = $web->kategorie();
    eq([[$ver, 'Příroda', 1]], array_map(fn($r) => [(int)$r['id'], $r['name'], (int)$r['pocet']], $k));
    eq($ids['ok'], (int)$k[0]['obalka']['id']);
    eq([$ids['ok']], array_map(fn($r) => (int)$r['id'], $web->fotky($ver)['polozky']));
    eq([], $web->fotky($sou)['polozky'], 'soukromá kategorie nic nevydá');
    eq(null, $web->jednaKategorie($sou));
    eq([$ids['ok']], array_map(fn($r) => (int)$r['id'], $web->nejnovejsi()));
    eq($ids['ok'], (int)$web->uvodni()['id'], 'úvodní = obálka první kategorie');
    ok(str_contains($web->obrazek($k[0]['obalka'], Web::PX_NAHLED), '/soubor.php/'), 'trvalý náhled přes veřejný odkaz');
    eq('5-mesta-a-mista', Web::slug(['id' => 5, 'name' => 'Města a místa']));
});

test('web: úvodní fotka jen s veřejným odkazem; obálka jen z vlastní kategorie', function () {
    [$app, $ver, $sou, $ids] = webData();
    $web = $app->web();
    $web->nastavUvodni($ids['soukrome']);                         // odkaz má, kategorie neveřejná: smí, uživatel ji vybral výslovně
    eq($ids['soukrome'], (int)$web->uvodni()['id']);
    foreach (['bezodkazu', 'archiv', 'video'] as $spatne) {
        try { $web->nastavUvodni($ids[$spatne]); ok(false, $spatne); } catch (Chyba) { ok(true); }
    }
    $app->media()->zrusOdkaz($ids['soukrome']);
    eq($ids['ok'], (int)$web->uvodni()['id'], 'po vypnutí odkazu zpět na obálku');
    try { $web->ulozKategorii($ver, ['web_cover_id' => (string)$ids['soukrome']]); ok(false); } catch (Chyba) { ok(true); }
    $web->ulozKategorii($ver, ['web_public' => '0']);
    eq([], $web->kategorie());
    eq(null, $web->uvodni(), 'nic veřejného = bez úvodní fotky');
});

test('web: nastavení – výchozí hodnoty, uložení, kontrola odkazů reklamních ploch', function () {
    $app = novaApp();
    $web = $app->web();
    $n = $web->nastaveni();
    eq([true, 'Objevte svět v obrazech', 'Reklamní banner'], [$n['zapnuto'], $n['nadpis'], $n['banner1']['stitek']]);
    $p = ['nazev' => 'Moje fotky', 'nadpis' => 'Ahoj', 'tlacitko' => 'Dál', 'vybrane_nadpis' => 'Výběr', 'vybrane_pocet' => '3', 'o_galerii' => "**Ahoj**\n\nsvěte",
        'banner1_zobrazit' => '1', 'banner1_text' => 'Kup', 'banner1_tlacitko' => 'Ano', 'banner1_odkaz' => 'https://example.com/a?b=1'];
    $web->uloz($p);
    $n = $web->nastaveni();
    eq([false, 'Moje fotky', 3, 'https://example.com/a?b=1', true, false], [$n['zapnuto'], $n['nazev'], $n['vybrane_pocet'], $n['banner1']['odkaz'], $n['banner1']['zobrazit'], $n['banner2']['zobrazit']]);
    foreach (['javascript:alert(1)', '//evil.cz', 'data:text/html,x', 'https://a b'] as $spatny) {
        try { $web->uloz(['banner2_odkaz' => $spatny] + $p); ok(false, $spatny); } catch (Chyba) { ok(true); }
    }
    foreach (['mailto:a@b.cz', 'tel:+420123', '?s=o-galerii', '/kontakt'] as $dobry) ok(Web::platnyOdkaz($dobry), $dobry);
    try { $web->uloz(['nadpis' => ''] + $p); ok(false); } catch (Chyba) { ok(true); }
});

test('web na serveru: stránky, 404, soukromá data se neukážou, vypnutý web, index.php?s=', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ver, $sou, $ids] = webData($app);
    $anon = new Prohlizec($s);
    $r = $anon->jdi('verejne.php');
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Objevte svět v obrazech') && str_contains($r['body'], 'Příroda') && str_contains($r['body'], '1&nbsp;fotografie') || str_contains($r['body'], "1\u{00A0}fotografie"), 'karta kategorie');
    ok(str_contains($r['body'], 'index.php?p=prehled'), 'přihlášení do administrace');
    ok(!str_contains($r['body'], 'Soukromé') && !str_contains($r['body'], 'Tajné'));
    ok(str_contains($r['body'], 'Prostor pro reklamní sdělení'));
    $r = $anon->jdi('verejne.php?s=galerie&k=' . $ver . '-priroda');
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Les') && !str_contains($r['body'], 'Bez odkazu') && !str_contains($r['body'], 'Archiv') && !str_contains($r['body'], 'klip'));
    eq(404, $anon->jdi('verejne.php?s=galerie&k=' . $sou)['code']);
    eq(404, $anon->jdi('verejne.php?s=neco')['code']);
    eq(200, $anon->jdi('verejne.php?s=o-galerii')['code']);
    $r = $anon->jdi('index.php?s=galerie');
    ok($r['code'] === 200 && str_contains($r['body'], 'Nejnovější fotografie'), 'index.php?s= vede na veřejný web');
    ok(str_contains($anon->jdi('index.php')['body'], 'heslo'), 'index.php bez s= zůstává přihlášením');
    $app->nastaveni()->uloz('web', json_encode(['zapnuto' => false]));
    $r = $anon->jdi('verejne.php');
    eq([302, 'index.php'], [$r['code'], $r['location']]);
    // administrace: stránka Veřejný web a kategorie
    eq(200, $b->jdi('index.php?p=web')['code']);
    $r = $b->odesli('index.php?p=web', ['akce' => 'ulozit', 'zapnuto' => '1', 'nazev' => 'Galerie X', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4', 'uvod_id' => (string)$ids['ok']]);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    ok(str_contains($anon->jdi('verejne.php')['body'], 'Galerie X'));
    $b->jdi('index.php?p=kategorie&upravit=' . $sou);
    $b->odesli('index.php?p=kategorie&upravit=' . $sou, ['akce' => 'ulozit', 'name' => 'Soukromé', 'web_public' => '1']);
    ok(str_contains($anon->jdi('verejne.php?s=galerie')['body'], 'Soukromé'), 'zveřejněná kategorie je na webu');
});

test('web 0.8.2: reklamní plocha jako kód z reklamního systému, kód do hlavičky, ads.txt, CSP', function () {
    $app = novaApp();
    $web = $app->web();
    $n = $web->nastaveni();
    eq(['text', '', '', ''], [$n['banner1']['typ'], $n['banner1']['kod'], $n['kod_hlavicka'], $n['ads_txt']], 'starší nastavení = vlastní text');
    eq(false, Web::maKod($n, ['banner1', 'banner2']));
    $adsense = "<script async src=\"https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1\" crossorigin=\"anonymous\"></script>\r\n"
        . "<ins class=\"adsbygoogle\" style=\"display:block\" data-ad-client=\"ca-pub-1\" data-ad-slot=\"2\"></ins>\r\n<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>";
    $p = ['nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'zapnuto' => '1',
        'banner1_zobrazit' => '1', 'banner1_typ' => 'kod', 'banner1_stitek' => 'Reklama', 'banner1_kod' => "  $adsense \n", 'banner1_text' => 'Starý text',
        'banner2_zobrazit' => '1', 'banner2_typ' => 'text', 'banner2_text' => 'Vlastní', 'banner2_kod' => '<b>schovaný</b>',
        'kod_hlavicka' => '<meta name="seznam-wmt" content="abc">', 'ads_txt' => "google.com, pub-1, DIRECT, f08c47fec0942fa0\r\n"];
    $web->uloz($p);
    $n = $web->nastaveni();
    eq(['kod', str_replace("\r\n", "\n", $adsense), 'Starý text'], [$n['banner1']['typ'], $n['banner1']['kod'], $n['banner1']['text']], 'kód beze změny, jen konce řádků');
    eq(['text', '<b>schovaný</b>'], [$n['banner2']['typ'], $n['banner2']['kod']], 'kód zůstane i u textové plochy');
    eq('google.com, pub-1, DIRECT, f08c47fec0942fa0', $n['ads_txt']);
    ok(Web::maKod($n, ['banner1']) && Web::maKod($n, []), 'kód v hlavičce stačí');
    ok(str_contains(Web::csp(true), "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:") && str_contains(Web::csp(true), 'frame-src https:'));
    ok(str_contains(Web::csp(true), "frame-ancestors 'self'") && str_contains(Web::csp(true), "base-uri 'self'"));
    eq("default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'", Web::csp(false));
    foreach ([['banner1_typ' => 'iframe'], ['banner1_kod' => ''], ['banner1_kod' => str_repeat('x', Web::MAX_KOD + 1)], ['ads_txt' => str_repeat('x', Web::MAX_KOD + 1)]] as $spatne) {
        try { $web->uloz($spatne + $p); ok(false, json_encode($spatne)); } catch (Chyba) { ok(true); }
    }
    $web->uloz(['banner1_zobrazit' => '', 'banner1_kod' => ''] + $p);
    eq([false, 'kod'], [$web->nastaveni()['banner1']['zobrazit'], $web->nastaveni()['banner1']['typ']], 'skrytá plocha s kódem smí být prázdná');
});

test('web 0.8.2 na serveru: kód se vloží bez úprav, volnější CSP jen se kódem, /ads.txt, administrace', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    webData($app);
    $anon = new Prohlizec($s);
    $csp = fn(Prohlizec $p): string => preg_match('/^Content-Security-Policy:\s*([^\r\n]+)/mi', $p->posledniHlavicky, $m) ? $m[1] : '';
    $anon->jdi('verejne.php');
    ok(str_contains($csp($anon), "script-src 'self';"), 'bez kódu přísná CSP');
    eq(404, $anon->jdi('verejne.php?s=ads.txt')['code'], 'prázdné ads.txt = 404');

    $b->jdi('index.php?p=web');
    $r = $b->jdi('index.php?p=web');
    ok(str_contains($r['body'], 'name="banner1_typ"') && str_contains($r['body'], 'name="banner2_kod"') && str_contains($r['body'], 'name="ads_txt"'), 'pole v administraci');
    $kod = '<div id="ssp-zone-123"></div><script src="https://ssp.seznam.cz/static/js/ssp.js"></script><script>sssp.getAds([{"zoneId":123,"id":"ssp-zone-123","width":728,"height":90}]);</script>';
    $r = $b->odesli('index.php?p=web', ['akce' => 'ulozit', 'zapnuto' => '1', 'nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4',
        'banner1_zobrazit' => '1', 'banner1_typ' => 'kod', 'banner1_stitek' => 'Reklama', 'banner1_kod' => $kod,
        'banner2_zobrazit' => '1', 'banner2_typ' => 'text', 'banner2_text' => 'Textová plocha',
        'kod_hlavicka' => '<script src="https://c.seznam.cz/js/rc.js"></script>', 'ads_txt' => 'seznam.cz, 123, DIRECT']);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    ok(str_contains($r['body'], htmlspecialchars($kod)), 'administrace kód ukáže escapovaný v poli');
    ok(!str_contains($r['body'], $kod), 'administrace kód nespustí');
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<div class="banner-kod-obsah">' . $kod . '</div>'), 'kód na webu beze změny');
    ok(str_contains($r['body'], 'Textová plocha'), 'druhá plocha s textem');
    ok(preg_match('~<script src="https://c\.seznam\.cz/js/rc\.js"></script>\s*</head>~', $r['body']) === 1, 'kód v hlavičce');
    ok(str_contains($csp($anon), "'unsafe-inline'") && str_contains($csp($anon), 'frame-src https:'), 'volnější CSP');
    $r = $anon->jdi('verejne.php?s=ads.txt');
    eq([200, 'text/plain', "seznam.cz, 123, DIRECT\n"], [$r['code'], $r['type'], $r['body']]);
    eq(200, $anon->jdi('index.php?s=ads.txt')['code'], 'i přes index.php');
    $r = $b->odesli('index.php?p=web', ['akce' => 'ulozit', 'zapnuto' => '1', 'nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4',
        'banner1_zobrazit' => '1', 'banner1_typ' => 'kod', 'banner1_kod' => '']);
    ok(str_contains($r['body'], 'Vložte kód z reklamního systému'), 'prázdný kód u zobrazené plochy');
});

test('komprese: úloha jen z obrázků, kroky, souhrn, bez originálu uvolní místo', function () {
    [$app, $ver, , $ids] = webData();
    $k = $app->komprese();
    try { $k->zaloz([$ids['video']], Komprese::VYCHOZI, 'x'); ok(false); } catch (Chyba) { ok(true); }
    $v = Komprese::volby(['komprese_format' => 'jpg', 'komprese_kvalita' => '70', 'komprese_rozmer' => '1280', 'komprese_metadata' => '1']);
    eq(false, $v['ponechat'], 'neodeslané zaškrtávátko = nepochat');
    $u = $k->zaloz([$ids['ok'], $ids['soukrome'], $ids['video']], $v, 'index.php?p=galerie');
    eq([2, 1], [count($u['ids']), $u['jine']]);
    $u = $k->krok(0.0);                                     // nulový čas: aspoň jeden obrázek
    eq([1, null], [$u['i'], $u['konec']]);
    $u = $k->krok();
    ok($u['konec'] !== null);
    eq(2, $u['zkomprimovano']);
    ok($u['po'] < $u['pred'] && $u['disk_po'] < $u['disk_pred']);
    $r = $app->media()->radek($ids['ok']);
    eq([1280, null], [(int)$r['width'], $r['orig_filename']]);
    ok(str_contains(Komprese::souhrn($u), 'na disku uvolněno'));
    foreach (['komprese_kvalita' => '10', 'komprese_rozmer' => '999', 'komprese_format' => 'gif'] as $pole => $hodnota) {
        try { Komprese::volby([$pole => $hodnota]); ok(false, $pole); } catch (Chyba) { ok(true); }
    }
});

test('komprese: spadlý krok se příště přeskočí jako chyba; ponechaný originál jde vrátit', function () {
    [$app, , , $ids] = webData();
    $k = $app->komprese();
    $k->zaloz([$ids['ok'], $ids['soukrome']], Komprese::VYCHOZI, 'x');
    // simulace pádu PHP uprostřed prvního obrázku
    $u = $k->uloha(); $u['rozpracovano'] = $ids['ok'];
    $app->nastaveni()->uloz('komprese_uloha', json_encode($u));
    $u = $k->krok();
    ok($u['konec'] !== null);
    eq([1, 1], [count($u['chyby']), $u['zkomprimovano']]);
    $r = $app->media()->radek($ids['soukrome']);
    ok($r['orig_filename'] !== null, 'originál zůstal');
    ok(str_contains(Komprese::souhrn($u), 'na disku přibylo'));
    ok($app->galerie()->obnovPuvodni($ids['soukrome']));
    $k->zrus();
    eq(null, $k->uloha());
});

test('komprese na serveru: hromadná akce → stránka průběhu → JSON kroky → souhrn', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, , , $ids] = webData($app);
    $b->jdi('index.php?p=galerie');
    $r = $b->jdi('index.php?p=galerie', ['akce' => 'komprimovat', 'ids' => [$ids['ok'], $ids['soukrome']], 'komprese_format' => 'jpg', 'komprese_kvalita' => '75',
        'komprese_rozmer' => '1280', 'komprese_ponechat' => '1', 'komprese_metadata' => '1']);
    eq([303, 'index.php?p=komprese'], [$r['code'], $r['location']]);
    $r = $b->jdi('index.php?p=komprese');
    ok(str_contains($r['body'], 'data-komprese'));
    for ($i = 0; $i < 5; $i++) {
        $b->hlavicky = ['Accept: application/json'];
        $r = $b->jdi('index.php?p=komprese', ['akce' => 'krok']);
        $j = json_decode($r['body'], true);
        ok($j['ok'] ?? false, $r['body']);
        if ($j['hotovo']) break;
    }
    eq([true, 2, 2], [$j['hotovo'], $j['zpracovano'], $j['zkomprimovano']]);
    $r = $b->jdi('index.php?p=komprese');
    ok(str_contains($r['body'], 'Hotovo:') && str_contains($r['body'], 'Zpět do galerie'));
    $r = $b->jdi('index.php?p=komprese', ['akce' => 'zavrit']);
    eq('index.php?p=galerie', $r['location']);
    eq(null, $app->komprese()->uloha());
});
