<?php
declare(strict_types=1);
// TWB Media galerie 0.6.1: hledání q přes název, tagy, autora a poznámku (bez diakritiky, relevance), režimy q_rezim a tagy_rezim.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Hledani};

echo "Media galerie: hledání\n";

test('normalizace: malá písmena bez diakritiky, fráze v uvozovkách, nejvýš 10 výrazů', function () {
    eq('zlutoucky kun upel dabelske ody', Hledani::normalizuj("  Žluťoučký   KŮŇ úpěl\tďábelské ódy "));
    eq('lodz strasse', Hledani::normalizuj('Łódź Straße'), 'znaky, které NFD nerozloží');
    eq(['hory', 'mlha', 'cerny les'], Hledani::vyrazy('Hory  mlha "Černý les" hory,'));
    eq([], Hledani::vyrazy('  ""  '));
    eq(Hledani::MAX_SLOV, count(Hledani::vyrazy(implode(' ', range(1, 15)))));
});

test('skóre: tag > slovo v názvu > část názvu > část tagu > autor > poznámka; všechna / kterékoli slovo', function () {
    $r = ['original_name' => 'Západ slunce nad Sněžkou.jpg', 'tags' => json_encode(['hory', 'Krkonoše']), 'author' => 'Eva Malá', 'note' => 'focení v červenci'];
    eq(5, Hledani::skore($r, ['hory']));
    eq(4, Hledani::skore($r, ['snezkou']));
    eq(3, Hledani::skore($r, ['lunce']));
    eq(3, Hledani::skore($r, ['krkonos']));
    eq(2, Hledani::skore($r, ['mala']));
    eq(1, Hledani::skore($r, ['cervenci']));
    eq(null, Hledani::skore($r, ['jpg']), 'přípona se nehledá');
    eq(5 + 2, Hledani::skore($r, ['hory', 'eva']));
    eq(null, Hledani::skore($r, ['hory', 'more']));
    eq(5, Hledani::skore($r, ['hory', 'more'], false));
    eq(null, Hledani::skore($r, ['more'], false));
});

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
$app = new App(KOREN . '/apps/twb-galerie', require $s->config);
$H = ['Authorization: Bearer ' . $app->apiKlice()->vytvor('Test', 'read')];

function vloz(App $app, string $nazev, array $tagy, ?string $autor = null, ?string $pozn = null): int
{
    $im = imagecreatetruecolor(40, 30); ob_start(); imagepng($im); $f = docasna('h'); file_put_contents($f, (string)ob_get_clean());
    $id = $app->media()->nahraj($f, $nazev, $tagy)['id'];
    if ($autor !== null) $app->media()->nastavAutora($id, $autor, null, null);
    if ($pozn !== null) $app->media()->nastavPoznamku($id, $pozn);
    return $id;
}
$a = vloz($app, 'Sněžka v mlze.png', ['hory', 'zima']);
$c = vloz($app, 'Pláž.png', ['moře', 'léto'], 'Jan Horák');
$d = vloz($app, 'Banner.png', ['web'], null, 'pozadí s horami a mořem');

function hledej(TestServer $s, array $H, string $dotaz): array
{
    $ch = curl_init($s->url . '/api.php/v1/media?' . $dotaz);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $H]);
    $j = json_decode((string)curl_exec($ch), true);
    return ['code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'ids' => array_column($j['media'] ?? [], 'id'), 'rel' => array_column($j['media'] ?? [], 'relevance'), 'json' => $j];
}

test('API: q hledá i v tazích, autorovi a poznámce, bez diakritiky, řazení podle relevance', function () use ($s, $H, $a, $c, $d) {
    $r = hledej($s, $H, 'q=hor');
    eq([$a, $c, $d], $r['ids'], 'tag „hory“ (část) 3, autor „Horák“ 2, poznámka „horami“ 1');
    eq([3, 2, 1], $r['rel']);
    eq([$a], hledej($s, $H, 'q=' . rawurlencode('snezka MLZE'))['ids']);
    eq([$c], hledej($s, $H, 'q=' . rawurlencode('more leto'))['ids'], 'všechna slova');
    eq([$c, $d], hledej($s, $H, 'q=' . rawurlencode('moře') . '&razeni=stare')['ids'], 'vlastní řazení');
    eq([$c, $a], hledej($s, $H, 'q=' . rawurlencode('zima léto') . '&q_rezim=kterykoli')['ids'], 'stejné skóre = nejnovější');
    eq([$c, $a], hledej($s, $H, 'q=' . rawurlencode('zima léto') . '&q_rezim=any')['ids']);
    eq([], hledej($s, $H, 'q=' . rawurlencode('zima léto'))['ids']);
    eq([$d], hledej($s, $H, 'q=' . rawurlencode('"s horami"'))['ids'], 'fráze');
    eq([$d, $c, $a], hledej($s, $H, 'razeni=relevance')['ids'], 'relevance bez q = nejnovější');
    ok(!isset(hledej($s, $H, '')['json']['media'][0]['relevance']), 'bez q pole relevance chybí');
    eq(400, hledej($s, $H, 'q=x&q_rezim=nekdy')['code']);
});

test('API: tagy_rezim any / all jako kterykoli / vse, spojení s q', function () use ($s, $H, $a, $c) {
    eq([$c, $a], hledej($s, $H, 'tagy=' . rawurlencode('zima,moře') . '&tagy_rezim=any')['ids']);
    eq([], hledej($s, $H, 'tagy=' . rawurlencode('zima,moře') . '&tagy_rezim=all')['ids']);
    eq([$c], hledej($s, $H, 'q=jan&tagy=' . rawurlencode('zima,moře') . '&tagy_rezim=any')['ids']);
});

test('galerie v administraci: hledání najde i tag a funguje bez diakritiky', function () use ($b) {
    $r = $b->jdi('index.php?p=galerie&q=more');
    ok(str_contains($r['body'], 'Pláž.png') && str_contains($r['body'], 'Banner.png') && !str_contains($r['body'], 'Sněžka v mlze.png'));
    ok(str_contains($b->jdi('index.php?p=galerie&q=zima')['body'], 'Sněžka v mlze.png'));
});
