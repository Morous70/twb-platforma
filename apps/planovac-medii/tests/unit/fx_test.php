<?php
// Grafický text (styly Zlato, Stříbro…), rozestup písmen a vestavěná písma.
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/slide.php';

$t = new DateTimeImmutable('2026-10-18 12:00');
function fx_design(array $text, string $bg = '#1d1c1a'): array {
    return sanitize_design(['bg' => ['type' => 'color', 'color' => $bg], 'elements' => [$text + ['type' => 'text', 'id' => 't1', 'x' => 160, 'y' => 340, 'w' => 1600, 'h' => 400,
        'text' => '137 000 000 Kč', 'font' => 'titan', 'weight' => 900, 'size' => 170, 'color' => '#ffffff', 'align' => 'center', 'valign' => 'middle', 'autoFit' => true]]]);
}
function px($im, int $x, int $y): array { $c = imagecolorat($im, $x, $y); return [$c >> 16 & 255, $c >> 8 & 255, $c & 255]; }

test('nová písma: Archivo Black, Titan One, Anton (soubory, české znaky)', function () {
    foreach (['archivo' => 'ArchivoBlack-900.ttf', 'titan' => 'TitanOne-900.ttf', 'anton' => 'Anton-700.ttf'] as $k => $file) {
        ok(font_exists($k), $k);
        eq(FONT_DIR . "/$file", font_file($k, 400));
        ok(imagettfbbox(40, 0, font_file($k, 400), 'Příliš žluťoučký kůň') !== false, "$k vykreslí češtinu");
    }
});

test('styl: očištění (neznámý styl = obyčejný text, rozsahy, barvy z předlohy)', function () {
    eq(null, sanitize_fx(null));
    eq(null, sanitize_fx(['style' => 'neexistuje']));
    $f = sanitize_fx(['style' => 'zlato', 'stroke' => 5, 'depth' => -1, 'glowColor' => 'red', 'grad' => ['#000000']]);
    eq(['#000000', '#ffd84a', '#f4a611', '#c86800'], $f['grad']);
    eq([0.15, 0.0, '#ff9d1a', 0.55], [$f['stroke'], $f['depth'], $f['glowColor'], $f['glow']]);
    eq(11, count(FX_STYLES));
    eq(5, count(array_filter(FX_STYLES, fn($s) => $s['light'])));
    $d = fx_design(['fx' => ['style' => 'stribro'], 'spacing' => 9]);
    eq(['stribro', 0.5], [$d['elements'][0]['fx']['style'], $d['elements'][0]['spacing']]);
    eq([null, 0.0], [fx_design([])['elements'][0]['fx'], fx_design([])['elements'][0]['spacing']], 'staré slidy beze změny');
});

test('rozestup písmen: širší text je širší, užší užší; zalamování i zmenšení s ním počítá', function () {
    $font = font_file('montserrat', 900);
    $n = text_width('137 000 Kč', 100, $font, 0);
    $w = text_width('137 000 Kč', 100, $font, 0.1);
    $u = text_width('137 000 Kč', 100, $font, -0.05);
    ok($w > $n + 80 && $u < $n - 30, "normální $n, širší $w, užší $u");
    eq(round(text_width('ab', 100, $font, 0.1) - text_width('ab', 100, $font, 0), 1) >= 9, true, 'jeden rozestup = 0,1 em');
    $e = fx_design(['spacing' => 0.3, 'w' => 900, 'autoFit' => false, 'font' => 'montserrat'])['elements'][0];
    ok(count(layout_text($e, '137 000 000 Kč')['lines']) >= 2, 'široký rozestup zalomí');
    $e2 = fx_design(['spacing' => 0, 'w' => 900, 'autoFit' => false, 'font' => 'montserrat', 'size' => 100])['elements'][0];
    eq(1, count(layout_text($e2, '137 000 000 Kč')['lines']));
});

test('Zlato: vykreslí výplň přechodem, tmavý obrys, záři; obyčejný text zůstal stejný', function () use ($t) {
    $plain = fx_design([]);
    $jpgPlain = render_slide($plain, $t);
    $old = imagecreatefromstring($jpgPlain);
    $d = fx_design(['fx' => ['style' => 'zlato']]);
    $t0 = microtime(true);
    $jpg = render_slide($d, $t);
    $sec = microtime(true) - $t0;
    file_put_contents(TMP_DIR . '/fx-zlato.jpg', $jpg);
    $im = imagecreatefromstring($jpg);
    $L = layout_text($d['elements'][0], '137 000 000 Kč');
    $ln = $L['lines'][0];
    // svislý řez prvním „0“ druhé skupiny: najít horní okraj výplně a její barvy
    $x = (int)round($ln['x'] + $ln['w'] * 0.36);
    $top = px($im, $x, (int)round($ln['y'] - $L['size'] * 0.62));
    $bot = px($im, $x, (int)round($ln['y'] - $L['size'] * 0.08));
    ok($top[0] > 200 && $top[1] > 170 && $top[2] < 200 && $top[0] - $top[2] > 40, 'nahoře světle žlutá: ' . json_encode($top));
    ok($bot[0] > 170 && $bot[1] < 150 && $bot[2] < 60, 'dole sytě oranžová: ' . json_encode($bot));
    // záře: kousek nad textem je pozadí oteplené (víc červené než modré)
    $glow = px($im, $x, (int)round($ln['y'] - $L['size'] * 0.72 - $L['size'] * 0.07));
    ok($glow[0] > $glow[2] + 20, 'záře nad textem: ' . json_encode($glow));
    $plainPx = px($old, $x, (int)round($ln['y'] - $L['size'] * 0.62));
    ok($plainPx[0] > 230 && $plainPx[1] > 230 && $plainPx[2] > 230, 'bez stylu bílý text: ' . json_encode($plainPx));
    ok($sec < 6, sprintf('vykreslení trvalo %.1f s', $sec));
});

test('všechny styly se vykreslí bez chyby i na světlém pozadí a s více řádky', function () use ($t) {
    foreach (array_keys(FX_STYLES) as $k) {
        $d = fx_design(['fx' => ['style' => $k], 'text' => "Akce\ntýdne", 'size' => 120, 'spacing' => -0.03], FX_STYLES[$k]['light'] ? '#f4f0e6' : '#1d1c1a');
        $jpg = render_slide($d, $t, 70);
        ok(strlen($jpg) > 10000, $k);
        if ($k === 'modra_l') file_put_contents(TMP_DIR . '/fx-modra-l.jpg', $jpg);
    }
});

test('render_key se změní se stylem i rozestupem (slide se přegeneruje)', function () use ($t) {
    $a = render_key(fx_design([]), $t);
    ok($a !== render_key(fx_design(['fx' => ['style' => 'zlato']]), $t), 'styl');
    ok($a !== render_key(fx_design(['spacing' => -0.05]), $t), 'rozestup');
});
