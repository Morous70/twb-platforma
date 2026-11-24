<?php
declare(strict_types=1);
// TWB Media galerie 0.8.14: vyhledávání na veřejném webu (fulltext jako API q / q_rezim) místo tlačítka Přihlášení do administrace v záhlaví.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Web, VerejneRozvrzeni};

echo "Media galerie: 0.8.14\n";

function fotka0814(int $seme): string
{
    mt_srand($seme);
    $im = imagecreatetruecolor(320, 200);
    for ($i = 0; $i < 40; $i++) imagefilledrectangle($im, mt_rand(0, 320), mt_rand(0, 200), mt_rand(0, 320), mt_rand(0, 200), mt_rand(0, 0xFFFFFF));
    $f = docasna('foto') . '.jpg';
    imagejpeg($im, $f, 90);
    return $f;
}

/** Veřejná kategorie Příroda s fotkami (jedna bez odkazu, jedna v archivu), soukromá kategorie a fotka bez kategorie. @return array{App, array<string, int>} */
function data0814(?App $app = null): array
{
    $app ??= novaApp();
    $k = $app->kategorie();
    $ver = $k->uloz(['name' => 'Příroda']);
    $sou = $k->uloz(['name' => 'Soukromé']);
    $app->web()->ulozKategorii($ver, ['web_public' => '1']);
    $m = $app->media();
    $ids = [
        'kun' => $m->nahraj(fotka0814(1), 'Žlutý kůň na louce.jpg', ['zvířata', 'léto'], $ver)['id'],
        'les' => $m->nahraj(fotka0814(2), 'Les v mlze.jpg', ['příroda'], $ver)['id'],
        'autor' => $m->nahraj(fotka0814(3), 'IMG_0001.jpg', [], $ver)['id'],
        'bezodkazu' => $m->nahraj(fotka0814(4), 'Kůň bez odkazu.jpg', [], $ver)['id'],
        'archiv' => $m->nahraj(fotka0814(5), 'Kůň v archivu.jpg', [], $ver)['id'],
        'soukrome' => $m->nahraj(fotka0814(6), 'Kůň soukromý.jpg', [], $sou)['id'],
        'bezkat' => $m->nahraj(fotka0814(7), 'Kůň bez kategorie.jpg')['id'],
    ];
    $app->db->q('UPDATE media SET author = ?, note = ? WHERE id = ?', ['Jan Kuna', 'Ranní procházka', $ids['autor']]);
    $m->zrusOdkaz($ids['bezodkazu']);
    $app->galerie()->archivuj([$ids['archiv']], true);
    return [$app, $ids];
}

$idy = static fn(array $f): array => array_map(fn($r) => (int)$r['id'], $f['polozky']);

test('Web::hledej: fulltext bez diakritiky, jen co web smí ukázat, relevance', function () use ($idy) {
    [$app, $ids] = data0814();
    $web = $app->web();
    eq([$ids['kun']], $idy($web->hledej('zluty KUN')), 'bez diakritiky a velikosti písmen; ostatní koně nejsou veřejní');
    eq([$ids['kun'], $ids['autor']], $idy($web->hledej('kun')), 'název (slovo na začátku) před autorem; bez odkazu, archiv, soukromá a bez kategorie se nenajdou');
    eq([$ids['les']], $idy($web->hledej('priroda')), 'celý tag');
    eq([$ids['autor']], $idy($web->hledej('prochazka')), 'poznámka');
    eq([], $idy($web->hledej('kun mlha')), 'výchozí = všechna slova');
    eq([$ids['les']], $idy($web->hledej('"les v mlze"')), 'fráze v uvozovkách');
    eq([], $idy($web->hledej('"mlze les"')));
    $f = $web->hledej('kun mlze', false);
    eq([$ids['les'], $ids['kun'], $ids['autor']], $idy($f), 'kterékoli slovo, řazení podle skóre a při shodě nejnovější');
    eq(['kun', 'mlze'], $f['vyrazy']);
    eq([0, 1, 1, []], [($p = $web->hledej('   '))['celkem'], $p['strana'], $p['stran'], $p['polozky']], 'prázdný dotaz nic nehledá');
    ok(isset($web->hledej('kun')['polozky'][0]['kategorie_nazev']), 'řádek nese název kategorie');
    // stejný výsledek jako API se stejným q (omezený na veřejné)
    $api = $app->media()->seznam(['q' => 'kun mlze', 'q_vse' => false, 'razeni' => 'relevance'], 100);
    eq($idy($f), array_values(array_filter($idy($api), fn($id) => in_array($id, [$ids['kun'], $ids['les'], $ids['autor']], true))));
});

test('Web::hledej: stránkování po 36 a dlouhý dotaz', function () {
    $app = novaApp();
    $k = $app->kategorie()->uloz(['name' => 'Mnoho']);
    $app->web()->ulozKategorii($k, ['web_public' => '1']);
    $f = fotka0814(9);
    for ($i = 1; $i <= 40; $i++) { $c = docasna('k') . '.jpg'; copy($f, $c); $app->media()->nahraj($c, "Strom $i.jpg", [], $k, nazevPevny: true); }
    eq(36, Web::NA_STRANKU);
    $p1 = $app->web()->hledej('strom');
    eq([40, 2, 36], [$p1['celkem'], $p1['stran'], count($p1['polozky'])]);
    $p2 = $app->web()->hledej('strom', true, 5);
    eq([2, 4], [$p2['strana'], count($p2['polozky'])], 'strana mimo rozsah = poslední');
    eq([], $app->web()->hledej(str_repeat('a', Web::MAX_DOTAZ) . ' strom')['polozky'], 'nad MAX_DOTAZ znaků se zbytek zahodí');
});

test('záhlaví: vyhledávací pole místo tlačítka administrace, bez menu bez pole', function () {
    $app = novaApp();
    $n = $app->web()->nastaveni();
    $h = VerejneRozvrzeni::zahlavi($n, '', true, true, '', 'kůň "a"');
    ok(str_contains($h, '<form class="hledani hlavicka-hledani" role="search" action="verejne.php" method="get"><input type="hidden" name="s" value="hledat">'));
    ok(str_contains($h, 'name="q" value="kůň &quot;a&quot;"'), 'předvyplněný a escapovaný dotaz');
    ok(str_contains($h, 'class="hledat-mobil" href="verejne.php?s=hledat"'), 'ikona hledání na mobilu');
    ok(!str_contains($h, 'Přihlášení do administrace') && !str_contains($h, 'index.php?p=prehled'), 'tlačítko administrace v záhlaví není');
    ok(!str_contains(VerejneRozvrzeni::zahlavi($n, '', false), 'hledani'), 'vypnutý web (jen logo) bez pole');
    ok(!str_contains(VerejneRozvrzeni::zahlavi($n, '', true, false), 'hledani'));
    ok(str_contains(VerejneRozvrzeni::paticka($n, '', false, true), 'index.php?p=prehled'), 'odkaz na přihlášení zůstal v patičce');
});

test('0.8.14 na serveru: stránka výsledků, režim slov, stránkování, prázdný výsledek, CSP, noindex, přihlášení', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    [, $ids] = data0814($app);
    $anon = new Prohlizec($s);

    $r = $anon->jdi('verejne.php');
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'class="hledani hlavicka-hledani"') && !str_contains($r['body'], 'tlacitko admin'), 'hlavní stránka: pole v záhlaví');
    ok(str_contains($r['body'], '<a href="index.php?p=prehled">'), 'přihlášení v patičce');

    $r = $anon->jdi('verejne.php?s=hledat&q=' . rawurlencode('žlutý kůň'));
    eq(200, $r['code']);
    ok(str_contains($r['body'], 'Výsledky hledání „žlutý kůň“') && str_contains($r['body'], 'Nalezeno 1' . "\u{00A0}" . 'fotografie.'));
    eq(1, substr_count($r['body'], 'data-foto '), 'jedna fotka v mřížce');
    ok(str_contains($r['body'], 'data-titulek="Žlutý kůň na louce"') && str_contains($r['body'], 'data-popis="Příroda"'), 'fotka s kategorií v popisu');
    ok(str_contains($r['body'], 'name="q" value="žlutý kůň"'), 'pole předvyplněné');
    ok(str_contains($r['body'], '<meta name="robots" content="noindex, follow">'));
    ok(str_contains($r['body'], 'Všechna slova</span>') && str_contains($r['body'], 'q_rezim=kterykoli">Kterékoli slovo</a>'), 'přepínač režimu');
    ok(str_contains($anon->posledniHlavicky, "Content-Security-Policy: default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self';"), 'přísné CSP');
    ok(str_contains($anon->posledniHlavicky, "form-action 'self'") && str_contains($anon->posledniHlavicky, 'X-Robots-Tag: noindex, follow'));

    $r = $anon->jdi('verejne.php?s=hledat&q=kun');
    eq(2, substr_count($r['body'], 'data-foto '), 'neveřejné koně web nenajde');
    ok(!str_contains($r['body'], 'Kůň soukromý') && !str_contains($r['body'], 'Kůň v archivu') && !str_contains($r['body'], 'Kůň bez'));
    ok(!str_contains($r['body'], 'hledani-rezim'), 'jedno slovo = bez přepínače');

    $r = $anon->jdi('verejne.php?s=hledat&q=' . rawurlencode('kun mlze'));
    ok(str_contains($r['body'], 'Nic jsme nenašli') && str_contains($r['body'], 'q_rezim=kterykoli">Hledat kterékoli ze slov</a>'));
    $r = $anon->jdi('verejne.php?s=hledat&q=' . rawurlencode('kun mlze') . '&q_rezim=kterykoli');
    eq(3, substr_count($r['body'], 'data-foto '));
    ok(str_contains($r['body'], 'Kterékoli slovo</span>'));

    $r = $anon->jdi('verejne.php?s=hledat');
    ok(str_contains($r['body'], 'Zadejte, co hledáte') && !str_contains($r['body'], 'Nic jsme nenašli') && !str_contains($r['body'], 'data-foto '));
    $r = $anon->jdi('verejne.php?s=hledat&q=' . rawurlencode('<script>x</script>'));
    ok(!str_contains($r['body'], '<script>x') && str_contains($r['body'], '&lt;script&gt;x'), 'dotaz je escapovaný');
    $r = $anon->jdi('index.php?s=hledat&q=les');
    eq(1, substr_count($r['body'], 'data-foto '), 'funguje i přes index.php');

    // přihlašovací stránka má stejné záhlaví s polem; vypnutý web pole nemá a hledání přesměruje na přihlášení
    ok(str_contains($anon->jdi('index.php')['body'], 'class="hledani hlavicka-hledani"'));
    $n = json_decode((string)$app->nastaveni()->dej('web', '{}'), true) ?: [];
    $app->nastaveni()->uloz('web', json_encode(['zapnuto' => false] + $n));
    eq(302, $anon->jdi('verejne.php?s=hledat&q=les')['code']);
    ok(!str_contains($anon->jdi('index.php')['body'], 'hlavicka-hledani'));

    // administrace: vypínač odkazu je teď jen pro patičku
    ok(str_contains($b->jdi('index.php?p=web')['body'], 'Odkaz „Přihlášení do administrace“ v patičce'));
});

test('verze 0.8.14 a CSS stránky výsledků', function () {
    eq('0.8.14', trim((string)file_get_contents(KOREN . '/apps/twb-galerie/VERSION')));
    $css = (string)file_get_contents(KOREN . '/apps/twb-galerie/assets/verejne.css');
    foreach (['.hlavicka-hledani', '.hledani-velke', '.hledani-rezim', '.hledat-mobil', '.sr-only'] as $t) ok(str_contains($css, $t), $t);
    ok(!preg_match('/style="/', (string)file_get_contents(KOREN . '/apps/twb-galerie/lib/VerejneRozvrzeni.php')), 'bez style="" (CSP)');
});
