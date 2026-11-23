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

test('web: nastavení – výchozí hodnoty, uložení po částech', function () {
    $app = novaApp();
    $web = $app->web();
    $n = $web->nastaveni();
    eq([true, 'Objevte svět v obrazech', true, '', false, true, false], [$n['zapnuto'], $n['nadpis'], $n['reklama_zapnuto'], $n['reklama']['pod_uvodem'], $n['mereni_zapnuto'], $n['cookies_souhlas'], $n['zasady_zapnuto']]);
    $p = ['nazev' => 'Moje fotky', 'nadpis' => 'Ahoj', 'tlacitko' => 'Dál', 'vybrane_nadpis' => 'Výběr', 'vybrane_pocet' => '3', 'o_galerii' => "**Ahoj**\n\nsvěte"];
    $web->uloz('zaklad', $p);
    $n = $web->nastaveni();
    eq([false, 'Moje fotky', 3, true], [$n['zapnuto'], $n['nazev'], $n['vybrane_pocet'], $n['reklama_zapnuto']], 'základ nesahá na reklamu');
    try { $web->uloz('zaklad', ['nadpis' => ''] + $p); ok(false); } catch (Chyba) { ok(true); }
    try { $web->uloz('neco', $p); ok(false); } catch (Chyba) { ok(true); }
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
    ok(!str_contains($r['body'], 'class="reklama') && !str_contains($r['body'], 'data-cookies'), 'bez kódu žádná reklama ani lišta');
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
    $r = $b->odesli('index.php?p=web', ['akce' => 'zaklad', 'zapnuto' => '1', 'nazev' => 'Galerie X', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4', 'uvod_id' => (string)$ids['ok']]);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    ok(str_contains($anon->jdi('verejne.php')['body'], 'Galerie X'));
    $b->jdi('index.php?p=kategorie&upravit=' . $sou);
    $b->odesli('index.php?p=kategorie&upravit=' . $sou, ['akce' => 'ulozit', 'name' => 'Soukromé', 'web_public' => '1']);
    ok(str_contains($anon->jdi('verejne.php?s=galerie')['body'], 'Soukromé'), 'zveřejněná kategorie je na webu');
});

test('web 0.8.3: reklama jen jako kód pro pozice, měření, převod nastavení z 0.8.2', function () {
    $app = novaApp();
    $web = $app->web();
    $adsense = "<ins class=\"adsbygoogle\" data-ad-slot=\"2\"></ins>\r\n<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>";
    $web->uloz('reklama', ['reklama_zapnuto' => '1', 'reklama_pod_uvodem' => "  $adsense \n", 'reklama_pred_patickou' => '<b>x</b>', 'ads_txt' => "google.com, pub-1, DIRECT\r\n"]);
    $n = $web->nastaveni();
    eq([str_replace("\r\n", "\n", $adsense), '', '<b>x</b>', 'google.com, pub-1, DIRECT'], [$n['reklama']['pod_uvodem'], $n['reklama']['pod_galeriemi'], $n['reklama']['pred_patickou'], $n['ads_txt']]);
    ok(Web::maReklamu($n));
    eq('', Web::reklamaKod(['reklama_zapnuto' => false] + $n, 'pod_uvodem'), 'vypnutá reklama');
    eq(['reklama' => 'Reklama'], Web::kategorieSouhlasu($n));
    try { $web->uloz('reklama', ['reklama_pod_galeriemi' => str_repeat('x', Web::MAX_KOD + 1)]); ok(false); } catch (Chyba) { ok(true); }

    $web->uloz('mereni', ['mereni_zapnuto' => '1', 'mereni_kod' => '<script>/* matomo */</script>', 'mereni_umisteni' => 'hlavicka']);
    $n = $web->nastaveni();
    ok(Web::meri($n) && $n['mereni_umisteni'] === 'hlavicka');
    eq(['mereni' => 'Měření návštěvnosti', 'reklama' => 'Reklama'], Web::kategorieSouhlasu($n));
    eq([], Web::kategorieSouhlasu(['cookies_souhlas' => false] + $n), 'bez souhlasu se lišta neptá');
    try { $web->uloz('mereni', ['mereni_umisteni' => 'jinam']); ok(false); } catch (Chyba) { ok(true); }
    ok(str_contains(Web::csp(true), "script-src 'self' 'unsafe-inline' 'unsafe-eval' https:") && str_contains(Web::csp(true), "frame-ancestors 'self'"));
    ok(str_contains(Web::csp(false), "script-src 'self';"));

    // nastavení uložené verzí 0.8.2: kód zobrazené plochy → pozice, textové plochy zmizí, kód do hlavičky → měření
    $app->nastaveni()->uloz('web', json_encode(['nazev' => 'Stará', 'banner1' => ['zobrazit' => true, 'typ' => 'kod', 'kod' => '<i>A</i>'],
        'banner2' => ['zobrazit' => true, 'typ' => 'text', 'text' => 'Text', 'kod' => '<i>B</i>'], 'kod_hlavicka' => '<meta name="x">', 'ads_txt' => 'a']));
    $n = $web->nastaveni();
    eq(['Stará', '<i>A</i>', '', true, '<meta name="x">', 'hlavicka', 'a'], [$n['nazev'], $n['reklama']['pod_uvodem'], $n['reklama']['pod_galeriemi'], $n['mereni_zapnuto'], $n['mereni_kod'], $n['mereni_umisteni'], $n['ads_txt']]);
    ok(!isset($n['banner1']) && !isset($n['kod_hlavicka']));
});

test('web 0.8.3: GDPR – kontrola údajů, vzorový text zásad podle nastavení, vlastní text', function () {
    $app = novaApp();
    $web = $app->web();
    try { $web->uloz('gdpr', ['zasady_zapnuto' => '1', 'email' => 'a@b.cz']); ok(false, 'zveřejněné zásady chtějí provozovatele'); } catch (Chyba) { ok(true); }
    foreach ([['ico' => '123'], ['email' => 'neni-email']] as $spatne) {
        try { $web->uloz('gdpr', $spatne + ['provozovatel' => 'X']); ok(false, json_encode($spatne)); } catch (Chyba) { ok(true); }
    }
    $web->uloz('gdpr', ['cookies_souhlas' => '1', 'zasady_zapnuto' => '1', 'provozovatel' => 'Jan Novák', 'ico' => '1234 5678', 'email' => 'jan@example.cz', 'adresa' => 'Hlavní 1, Praha', 'nazev_stranky' => 'galerie.example.cz']);
    $n = $web->nastaveni();
    eq('12345678', $n['ico']);
    $vzor = $web->vzorZasad($n);
    foreach (['galerie.example.cz', '**Jan Novák**, IČO 12345678, Hlavní 1, Praha', '[jan@example.cz](mailto:jan@example.cz)', Web::COOKIE_SOUHLAS, 'Nastavení cookies', 'uoou.gov.cz'] as $t) ok(str_contains($vzor, $t), $t);
    ok(!str_contains($vzor, 'Měření návštěvnosti') && !str_contains($vzor, '**Reklama**'), 'bez měření a reklamy o nich vzor nemluví');
    $web->uloz('mereni', ['mereni_zapnuto' => '1', 'mereni_kod' => '<script></script>']);
    $web->uloz('reklama', ['reklama_zapnuto' => '1', 'reklama_pod_uvodem' => '<ins></ins>']);
    $vzor = $web->vzorZasad($web->nastaveni());
    ok(str_contains($vzor, '**Měření návštěvnosti**') && str_contains($vzor, '**Reklama**') && str_contains($vzor, 'jen s vaším souhlasem'));
    eq($vzor, $web->textZasad($web->nastaveni()), 'prázdný vlastní text = vzor');
    $web->uloz('gdpr', ['zasady_zapnuto' => '1', 'provozovatel' => 'Jan Novák', 'email' => 'jan@example.cz', 'zasady_text' => "## Vlastní\n\nText"]);
    eq("## Vlastní\n\nText", $web->textZasad($web->nastaveni()));
    eq(false, $web->nastaveni()['cookies_souhlas'], 'neodeslaný vypínač = vypnuto');
});

test('web 0.8.3 na serveru: pozice reklamy, měření, cookie lišta, zásady, ads.txt, administrace v kartách', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ver] = webData($app);
    $anon = new Prohlizec($s);
    $csp = fn(Prohlizec $p): string => preg_match('/^Content-Security-Policy:\s*([^\r\n]+)/mi', $p->posledniHlavicky, $m) ? $m[1] : '';
    $anon->jdi('verejne.php');
    ok(str_contains($csp($anon), "script-src 'self';"), 'bez kódu přísná CSP');
    eq(404, $anon->jdi('verejne.php?s=ads.txt')['code'], 'prázdné ads.txt = 404');
    eq(404, $anon->jdi('verejne.php?s=' . Web::ZASADY)['code'], 'nezveřejněné zásady');

    $r = $b->jdi('index.php?p=web');
    foreach (['role="tablist"', 'name="reklama_pod_uvodem"', 'name="reklama_pod_galeriemi"', 'name="reklama_pred_patickou"', 'name="mereni_kod"', 'name="mereni_umisteni"',
        'name="cookies_souhlas"', 'name="provozovatel"', 'data-vlozit-vzor'] as $t) ok(str_contains($r['body'], $t), $t);
    ok(!str_contains($r['body'], '_typ"') && !str_contains($r['body'], 'reklamní plocha'), 'žádná volba text/kód');
    $kod = '<div id="ssp-zone-1"></div><script src="https://ssp.seznam.cz/static/js/ssp.js"></script>';
    $r = $b->odesli('index.php?p=web', ['akce' => 'reklama', 'reklama_zapnuto' => '1', 'reklama_pod_uvodem' => $kod, 'reklama_pred_patickou' => '<ins id="dole"></ins>', 'ads_txt' => 'seznam.cz, 1, DIRECT']);
    ok(str_contains($r['body'], 'Nastavení webu uloženo') && str_contains($r['body'], 'id="karta-reklama" role="tab" aria-controls="reklama" aria-selected="true"'), 'zpět na kartě Reklama');
    ok(str_contains($r['body'], htmlspecialchars($kod)) && !str_contains($r['body'], $kod), 'administrace kód jen ukáže, nespustí');
    $b->odesli('index.php?p=web', ['akce' => 'mereni', 'mereni_zapnuto' => '1', 'mereni_kod' => '<script>var _paq=[];</script>', 'mereni_umisteni' => 'konec']);
    $r = $b->odesli('index.php?p=web', ['akce' => 'gdpr', 'cookies_souhlas' => '1', 'zasady_zapnuto' => '1', 'provozovatel' => 'Jan Novák', 'email' => 'jan@example.cz']);
    ok(str_contains($r['body'], 'Lišta se bude ptát na: <strong>Měření návštěvnosti, Reklama</strong>'));

    // se souhlasem: kód jen jako neživý <template>, lišta, odkazy v patičce
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<aside class="reklama reklama-pod-uvodem" aria-label="Reklama"><template data-souhlas="reklama">' . $kod . '</template></aside>'), 'reklama čeká na souhlas');
    ok(str_contains($r['body'], '<template data-souhlas="reklama"><ins id="dole"></ins></template>'), 'před patičkou');
    ok(preg_match('~<template data-souhlas="mereni"><script>var _paq=\[\];</script></template>\s*</body>~', $r['body']) === 1, 'měření na konci stránky');
    ok(str_contains($r['body'], 'data-cookies ') && str_contains($r['body'], 'data-kategorie="mereni,reklama"') && str_contains($r['body'], 'data-cookies-nastaveni'), 'lišta a Nastavení cookies');
    ok(str_contains($r['body'], 'href="?s=' . Web::ZASADY . '"'), 'odkaz na zásady');
    ok(str_contains($csp($anon), "'unsafe-inline'") && str_contains($csp($anon), 'frame-src https:'), 'volnější CSP');
    $r = $anon->jdi('verejne.php?s=galerie&k=' . $ver . '-priroda');
    ok(str_contains($r['body'], 'reklama-pod-uvodem') && str_contains($r['body'], 'reklama-pred-patickou'), 'reklama i v galerii');
    $r = $anon->jdi('verejne.php?s=' . Web::ZASADY);
    ok($r['code'] === 200 && str_contains($r['body'], '<b>Jan Novák</b>') && str_contains($r['body'], 'Úřadu pro ochranu osobních údajů'), 'stránka zásad se vzorem');
    $r = $anon->jdi('verejne.php?s=ads.txt');
    eq([200, 'text/plain', "seznam.cz, 1, DIRECT\n"], [$r['code'], $r['type'], $r['body']]);

    // bez souhlasu s cookies a s měřením v hlavičce: kód rovnou, žádná lišta
    $b->odesli('index.php?p=web', ['akce' => 'gdpr', 'zasady_zapnuto' => '1', 'provozovatel' => 'Jan Novák', 'email' => 'jan@example.cz']);
    $b->odesli('index.php?p=web', ['akce' => 'mereni', 'mereni_zapnuto' => '1', 'mereni_kod' => '<script>var _paq=[];</script>', 'mereni_umisteni' => 'hlavicka']);
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<aside class="reklama reklama-pod-uvodem" aria-label="Reklama">' . $kod . '</aside>'), 'reklama rovnou');
    ok(preg_match('~<script>var _paq=\[\];</script>\s*</head>~', $r['body']) === 1, 'měření v hlavičce');
    ok(!str_contains($r['body'], 'data-cookies') && !str_contains($r['body'], '<template'), 'bez lišty');
    // vypnutá reklama a vypnutý web: zásady a ads.txt dál fungují
    $b->odesli('index.php?p=web', ['akce' => 'reklama', 'reklama_pod_uvodem' => $kod, 'ads_txt' => 'seznam.cz, 1, DIRECT']);
    ok(!str_contains($anon->jdi('verejne.php')['body'], 'class="reklama'), 'vypnutá reklama');
    $b->odesli('index.php?p=web', ['akce' => 'zaklad', 'nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4']);   // bez zapnuto = vypnout
    eq(302, $anon->jdi('verejne.php')['code']);
    eq(200, $anon->jdi('verejne.php?s=' . Web::ZASADY)['code'], 'zásady i s vypnutým webem');
    eq(200, $anon->jdi('index.php?s=ads.txt')['code']);
});

test('web 0.8.4: doporučené – jen veřejné, náhodně, počet sloupce × řádky, nastavení 3–8 a 1–5', function () {
    [$app, $ver, $sou, $ids] = webData();
    $web = $app->web();
    $m = $app->media();
    $n = $web->nastaveni();
    eq(['Doporučené fotografie', 4, 2], [$n['doporucene_nadpis'], $n['doporucene_sloupce'], $n['doporucene_radky']], 'výchozí');
    eq([], $web->doporucene(), 'nic doporučeného');
    eq(5, $m->nastavDoporucene(array_values($ids), true), 'označí všechna');
    eq(0, $m->nastavDoporucene([$ids['ok']], true), 'už doporučená se nepočítá');
    eq([$ids['ok']], array_map(fn($r) => (int)$r['id'], $web->doporucene()), 'jen veřejný obrázek (ne bez odkazu, archiv, soukromá kategorie, video)');
    eq(['verejne' => 1, 'celkem' => 5], $web->pocetDoporucenych());
    eq('', $web->procNeniNaWebu($m->radek($ids['ok'])));
    ok(str_contains($web->procNeniNaWebu($m->radek($ids['soukrome'])), 'není zobrazená'), 'důvod: neveřejná kategorie');
    ok(str_contains($web->procNeniNaWebu($m->radek($ids['archiv'])), 'archivu'), 'důvod: archiv');
    ok(str_contains($web->procNeniNaWebu($m->radek($ids['bezodkazu'])), 'veřejný odkaz'), 'důvod: bez odkazu');
    // víc veřejných doporučených než míst: počet = sloupce × řádky, pořadí náhodné
    $nove = [];
    for ($i = 0; $i < 7; $i++) $nove[] = $m->nahraj(fotka(320, 200, 10 + $i), "Dop{$i}.jpg", [], $ver)['id'];
    $m->nastavDoporucene($nove, true);
    $p = ['nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4', 'zapnuto' => '1'];
    $web->uloz('zaklad', $p + ['doporucene_nadpis' => 'To nejlepší', 'doporucene_podnadpis' => 'Pod', 'doporucene_sloupce' => '3', 'doporucene_radky' => '1']);
    $n = $web->nastaveni();
    eq(['To nejlepší', 'Pod', 3, 1], [$n['doporucene_nadpis'], $n['doporucene_podnadpis'], $n['doporucene_sloupce'], $n['doporucene_radky']]);
    eq(3, count($web->doporucene()));
    $poradi = [];
    for ($i = 0; $i < 25; $i++) $poradi[implode(',', array_map(fn($r) => $r['id'], $web->doporucene(8)))] = true;
    ok(count($poradi) > 1, 'výběr je náhodný');
    foreach ([['doporucene_sloupce' => '2'], ['doporucene_sloupce' => '9'], ['doporucene_radky' => '0'], ['doporucene_radky' => '6'], ['doporucene_radky' => 'x'], ['doporucene_nadpis' => '']] as $spatne) {
        try { $web->uloz('zaklad', $spatne + $p); ok(false, json_encode($spatne)); } catch (Chyba) { ok(true); }
    }
    $web->uloz('zaklad', $p);   // formulář bez polí doporučených: zůstanou
    eq(['To nejlepší', 3, 1], [$web->nastaveni()['doporucene_nadpis'], $web->nastaveni()['doporucene_sloupce'], $web->nastaveni()['doporucene_radky']]);
    $app->nastaveni()->uloz('web', json_encode(['doporucene_sloupce' => 20, 'doporucene_radky' => -1]));
    eq([8, 1], [$web->nastaveni()['doporucene_sloupce'], $web->nastaveni()['doporucene_radky']], 'ručně pokažené nastavení se srovná do rozsahu');
    eq(7, $m->nastavDoporucene($nove, false));
    eq([$ids['ok'], $ids['bezodkazu'], $ids['soukrome'], $ids['video']], array_map(fn($r) => (int)$r['id'], array_reverse($m->seznam(['doporucene' => '1'])['polozky'])), 'filtr Jen doporučené (aktivní, ne archiv)');
});

test('web 0.8.4 na serveru: doporučení v detailu a hromadně, sekce na hlavní stránce, nastavení, API', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ver, $sou, $ids] = webData($app);
    $anon = new Prohlizec($s);
    ok(!str_contains($anon->jdi('verejne.php')['body'], 'sekce-doporucene'), 'bez doporučených sekce není');

    $r = $b->jdi('index.php?p=medium&id=' . $ids['ok']);
    ok(str_contains($r['body'], 'Označit jako doporučenou'));
    $r = $b->odesli('index.php?p=medium&id=' . $ids['ok'], ['akce' => 'doporucit']);
    ok(str_contains($r['body'], 'Fotografie je doporučená.') && str_contains($r['body'], 'Doporučená – zrušit'));
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<section class="sekce sekce-doporucene">') && str_contains($r['body'], 'Doporučené fotografie')
        && str_contains($r['body'], 'class="fotky doporucene sloupce-4" data-fotky') && str_contains($r['body'], 'data-titulek="Les"'), 'sekce na hlavní stránce');
    ok(str_contains($r['body'], "script-src 'self';") || !str_contains($anon->posledniHlavicky, 'unsafe-inline'), 'přísná CSP zůstává (bez style=)');
    ok(!preg_match('~sekce-doporucene.*style="~s', $r['body']), 'mřížka bez inline stylu');

    // hromadně: soukromou fotku doporučit jde, na webu ale není; detail řekne proč
    $b->jdi('index.php?p=galerie');
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'doporucit', 'ids' => [$ids['soukrome'], $ids['ok']]]);
    ok(str_contains($r['body'], 'Označeno jako doporučené: 1.'), 'hromadná akce');
    ok(str_contains($r['body'], '★ Doporučená'), 'štítek v galerii');
    $r = $b->jdi('index.php?p=galerie&doporucene=1');
    ok(str_contains($r['body'], 'Tajné.jpg') && str_contains($r['body'], 'Les.jpg') && !str_contains($r['body'], 'Bez odkazu.jpg'), 'filtr Jen doporučené');
    ok(str_contains($b->jdi('index.php?p=medium&id=' . $ids['soukrome'])['body'], 'Kategorie média není zobrazená na veřejném webu.'));
    ok(!str_contains($anon->jdi('verejne.php')['body'], 'Tajné'), 'soukromá doporučená se na webu neukáže');

    // nastavení v administraci
    $r = $b->jdi('index.php?p=web');
    ok(str_contains($r['body'], 'name="doporucene_sloupce"') && str_contains($r['body'], 'name="doporucene_radky"') && str_contains($r['body'], '<strong>1</strong> na webu (dalších 1 není veřejných)'));
    ok(str_contains($r['body'], '<option value="8"') && !str_contains($r['body'], 'name="doporucene_radky"><option value="0"'));
    $r = $b->odesli('index.php?p=web', ['akce' => 'zaklad', 'zapnuto' => '1', 'nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4',
        'doporucene_nadpis' => 'Tipy', 'doporucene_podnadpis' => 'Vybrali jsme', 'doporucene_sloupce' => '6', 'doporucene_radky' => '3']);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    $r = $anon->jdi('verejne.php');
    ok(str_contains($r['body'], '<h2>Tipy</h2><p>Vybrali jsme</p>') && str_contains($r['body'], 'fotky doporucene sloupce-6'), 'nadpis, podnadpis a mřížka');
    $r = $b->odesli('index.php?p=web', ['akce' => 'zaklad', 'zapnuto' => '1', 'nazev' => 'G', 'nadpis' => 'N', 'tlacitko' => 'T', 'vybrane_nadpis' => 'V', 'vybrane_pocet' => '4',
        'doporucene_nadpis' => 'Tipy', 'doporucene_sloupce' => '12', 'doporucene_radky' => '3']);
    ok(str_contains($r['body'], 'musí být 3 až 8'), 'mimo rozsah');

    // zrušení v detailu a hromadně
    $b->jdi('index.php?p=medium&id=' . $ids['ok']);
    $r = $b->odesli('index.php?p=medium&id=' . $ids['ok'], ['akce' => 'nedoporucit']);
    ok(str_contains($r['body'], 'Doporučení zrušeno.'));
    ok(!str_contains($anon->jdi('verejne.php')['body'], 'sekce-doporucene'), 'po zrušení sekce zmizí');
    $b->jdi('index.php?p=galerie');
    ok(str_contains($b->odesli('index.php?p=galerie', ['akce' => 'nedoporucit', 'ids' => [$ids['soukrome']]])['body'], 'Doporučení zrušeno: 1.'));
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
