<?php
declare(strict_types=1);
// TWB Media galerie 0.8.12: volitelný štítek „Reklama“ nad bannerem (každá pozice zvlášť) a vlastní CSS veřejného webu (samostatný soubor).
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Chyba, Web};

echo "Media galerie: 0.8.12\n";

test('štítek Reklama: výchozí vypnuto, ukládá se u každé pozice zvlášť', function () {
    $app = novaApp();
    $web = $app->web();
    $n = $web->nastaveni();
    eq(['pod_uvodem' => false, 'pod_galeriemi' => false, 'pred_patickou' => false], $n['reklama_stitek']);
    $web->uloz('reklama', ['reklama_zapnuto' => '1', 'reklama_pod_uvodem' => '<i>U</i>', 'reklama_pred_patickou' => '<i>P</i>', 'reklama_stitek_pred_patickou' => '1']);
    $n = $web->nastaveni();
    eq(['pod_uvodem' => false, 'pod_galeriemi' => false, 'pred_patickou' => true], $n['reklama_stitek']);
    ok(Web::reklamaStitek($n, 'pred_patickou') && !Web::reklamaStitek($n, 'pod_uvodem'));
    eq('<i>P</i>', $n['reklama']['pred_patickou'], 'kód zůstal');
    // jiná karta na štítky nesahá; nastavení z 0.8.11 (bez klíče) = vypnuto
    $web->uloz('css', ['vlastni_css_zapnuto' => '1', 'vlastni_css' => 'a{}']);
    ok($web->nastaveni()['reklama_stitek']['pred_patickou']);
    $app->nastaveni()->uloz('web', json_encode(['reklama' => ['pod_uvodem' => '<i>U</i>'], 'reklama_stitek' => ['pod_uvodem' => 1, 'cizi' => true]]));
    eq(['pod_uvodem' => true, 'pod_galeriemi' => false, 'pred_patickou' => false], $web->nastaveni()['reklama_stitek'], 'neznámý klíč se zahodí');
});

test('vlastní CSS: čištění, délka, vypínač, adresa s otiskem', function () {
    $app = novaApp();
    $web = $app->web();
    $n = $web->nastaveni();
    eq([true, '', ''], [$n['vlastni_css_zapnuto'], $n['vlastni_css'], Web::vlastniCssAdresa($n)]);
    $web->uloz('css', ['vlastni_css_zapnuto' => '1', 'vlastni_css' => "  body{color:red}\r\n\x01.a{}\n  "]);
    $n = $web->nastaveni();
    eq("body{color:red}\n.a{}", $n['vlastni_css']);
    ok(preg_match('/^verejne\.php\?s=vlastni\.css&v=[0-9a-f]{10}$/', Web::vlastniCssAdresa($n)) === 1);
    $adresa = Web::vlastniCssAdresa($n);
    $web->uloz('css', ['vlastni_css_zapnuto' => '1', 'vlastni_css' => 'body{color:blue}']);
    ok(Web::vlastniCssAdresa($web->nastaveni()) !== $adresa, 'změna CSS = nová adresa');
    $web->uloz('css', ['vlastni_css' => 'body{color:blue}']);
    $n = $web->nastaveni();
    eq(['body{color:blue}', '', ''], [$n['vlastni_css'], Web::vlastniCss($n), Web::vlastniCssAdresa($n)], 'vypnuté CSS zůstane uložené, ale nepoužije se');
    throws(fn() => $web->uloz('css', ['vlastni_css' => str_repeat('a', Web::MAX_CSS + 1)]), 'nejvýš');
    eq('Vlastní CSS', Web::CASTI['css']);
});

test('0.8.12 na serveru: štítek nad bannerem, se souhlasem uvnitř <template>; vlastní CSS jako soubor, přísné CSP; administrace', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    $app->nastaveni()->uloz('web', json_encode(['cookies_souhlas' => false, 'reklama_zapnuto' => true,
        'reklama' => ['pod_uvodem' => '<i id="r-u"></i>', 'pod_galeriemi' => '', 'pred_patickou' => '<i id="r-p"></i>'],
        'reklama_stitek' => ['pod_uvodem' => true, 'pred_patickou' => false]]));
    $anon = new Prohlizec($s);
    $html = $anon->jdi('verejne.php')['body'];
    ok(str_contains($html, '<aside class="reklama reklama-pod-uvodem" aria-label="Reklama"><p class="reklama-stitek">Reklama</p><i id="r-u"></i></aside>'), 'štítek nad bannerem Pod úvodem');
    ok(str_contains($html, '<aside class="reklama reklama-pred-patickou" aria-label="Reklama"><i id="r-p"></i></aside>'), 'Před patičkou bez štítku');
    eq(1, substr_count($html, 'reklama-stitek'));
    ok(!str_contains($html, 'vlastni.css'), 'bez CSS žádný odkaz');
    // se souhlasem s cookies je štítek uvnitř neživého kódu – neukáže se nad prázdným místem
    $n = json_decode((string)$app->nastaveni()->dej('web', ''), true);
    $app->nastaveni()->uloz('web', json_encode(['cookies_souhlas' => true] + $n));
    ok(str_contains($anon->jdi('verejne.php')['body'], '<template data-souhlas="reklama"><p class="reklama-stitek">Reklama</p><i id="r-u"></i></template>'));

    // administrace: karta Reklama má u každé pozice vypínač, karta Vlastní CSS formulář
    $r = $b->jdi('index.php?p=web&karta=reklama');
    eq(3, preg_match_all('/name="reklama_stitek_(pod_uvodem|pod_galeriemi|pred_patickou)"/', $r['body']));
    ok(preg_match('/name="reklama_stitek_pod_uvodem" value="1" checked/', $r['body']) === 1 && preg_match('/name="reklama_stitek_pred_patickou" value="1">/', $r['body']) === 1);
    $r = $b->odesli('index.php?p=web&karta=reklama', ['akce' => 'reklama', 'reklama_zapnuto' => '1', 'reklama_pod_uvodem' => '<i id="r-u"></i>', 'reklama_pred_patickou' => '<i id="r-p"></i>', 'reklama_stitek_pred_patickou' => '1']);
    ok(str_contains($r['body'], 'Nastavení webu uloženo'));
    eq(['pod_uvodem' => false, 'pod_galeriemi' => false, 'pred_patickou' => true], $app->web()->nastaveni()['reklama_stitek']);
    ok(str_contains($b->jdi('index.php?p=web&karta=css')['body'], 'name="vlastni_css"'));
    $zly = "body { background: #123456 }\n</style><script>alert(1)</script>";
    $r = $b->odesli('index.php?p=web&karta=css', ['akce' => 'css', 'vlastni_css_zapnuto' => '1', 'vlastni_css' => $zly]);
        ok(str_contains($r['body'], 'Nastavení webu uloženo') && str_contains($r['body'], 'vlastni.css'));
    ok(!str_contains($r['body'], '<script>alert(1)'), 'v administraci je CSS escapované');

    // web: CSS jen jako odkaz na soubor, nikde vložené do stránky; CSP zůstává přísné (bez reklamy)
    $b->odesli('index.php?p=web&karta=reklama', ['akce' => 'reklama']);   // reklama vypnutá (aplikace testu má nastavení v mezipaměti, proto přes administraci)
    foreach (['verejne.php', 'verejne.php?s=galerie', 'verejne.php?s=o-galerii'] as $cesta) {
        $html = $anon->jdi($cesta)['body'];
        ok(preg_match('#<link rel="stylesheet" href="verejne\.php\?s=vlastni\.css&amp;v=[0-9a-f]{10}">#', $html) === 1, "odkaz na CSS: $cesta");
        ok(!str_contains($html, 'alert(1)') && !str_contains($html, '#123456'), "CSS není v HTML: $cesta");
        ok(str_contains($anon->posledniHlavicky, "style-src 'self';"), "přísné CSP: $cesta");
    }
    preg_match('#href="(verejne\.php\?s=vlastni\.css&amp;v=[0-9a-f]{10})"#', $html, $m);
    $r = $anon->jdi(html_entity_decode($m[1]));
    eq([200, 'text/css'], [$r['code'], $r['type']]);
    eq($zly . "\n", $r['body']);
    ok(str_contains($anon->posledniHlavicky, 'nosniff') && str_contains($anon->posledniHlavicky, 'immutable'));
    // přihlášení vlastní CSS nemá (ať se jím nedá zablokovat přístup do administrace)
    ok(!str_contains((new Prohlizec($s))->jdi('index.php')['body'], 'vlastni.css'));
    // vypnuté CSS: bez odkazu, soubor 404
    $b->odesli('index.php?p=web&karta=css', ['akce' => 'css', 'vlastni_css' => $zly]);
    ok(!str_contains($anon->jdi('verejne.php')['body'], 'vlastni.css'));
    eq(404, $anon->jdi('verejne.php?s=vlastni.css')['code']);
});
