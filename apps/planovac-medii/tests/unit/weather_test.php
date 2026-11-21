<?php
// Počasí: OpenWeather (zkušební náhrada tests/mock/openweather.php), mezipaměť, výpadek, proměnné, prvek ve slidu.
require dirname(__DIR__) . '/lib.php';

$log = TMP_DIR . '/ow-mock-log';
$calls = function () use ($log): int { clearstatcache(); return is_file($log) ? count(file($log)) : 0; };
$reset = function () use ($log) {
    q('DELETE FROM weather');
    $GLOBALS['WEATHER_MEM'] = [];
    foreach (['ow-mock-down', 'ow-mock-temp', 'ow-mock-log'] as $f) @unlink(TMP_DIR . "/$f");
    foreach (glob(WEATHER_ICON_DIR . '/*') ?: [] as $f) @unlink($f);
};
set_setting('weather_api_key', 'test-klic-123');
set_setting('weather_place', 'Praha');
$t = new DateTimeImmutable();

test('rozbor proměnné: [místo.]pole a [místo.]den.pole', function () {
    eq(['place' => '', 'day' => null, 'field' => 'temp'], weather_parse_arg('teplota'));
    eq(['place' => 'Brno', 'day' => null, 'field' => 'humidity'], weather_parse_arg('Brno.vlhkost'));
    eq(['place' => '', 'day' => 1, 'field' => 'max'], weather_parse_arg('zítra.max'));
    eq(['place' => 'Praha,CZ', 'day' => 2, 'field' => 'day'], weather_parse_arg('Praha,CZ.den3.den'));
    eq(['place' => '', 'day' => 0, 'field' => 'desc'], weather_parse_arg('dnes.popis'));
    eq(null, weather_parse_arg('neznamepole'));
    eq(null, weather_parse_arg(''));
});

test('stažení z OpenWeather: aktuální počasí, 3 dny od dneška, ikony uložené', function () use ($reset, $t) {
    $reset();
    $r = weather_fetch('Praha');
    ok($r['ok'], (string)$r['error']);
    $d = $r['data'];
    eq('openweather', $d['provider']);
    eq(12.4, $d['now']['temp']);
    eq('zataženo', $d['now']['desc']);
    eq(71, $d['now']['humidity']);
    eq($t->format('Y-m-d'), $d['days'][0]['date'], 'první den je dnešek');
    ok(count($d['days']) >= 3, 'aspoň 3 dny');
    eq($t->modify('+1 day')->format('Y-m-d'), $d['days'][1]['date']);
    eq('04d', $d['days'][0]['icon'], 'dnešek s ikonou současného počasí');
    eq('01d', $d['days'][1]['icon'], 'ikona zítřka z poledne');
    eq('13d', $d['days'][2]['icon']);
    ok(is_file(WEATHER_ICON_DIR . '/openweather-04d.png'), 'ikona aktuálního počasí');
    ok(is_file(WEATHER_ICON_DIR . '/openweather-01d.png'), 'ikona zítřka');
});

test('proměnné {Počasí:…} v textu slidu', function () use ($t) {
    eq(['12 °C, Zataženo, 71 %, vítr 4 m/s, pocitově 11', []], resolve_text('{Počasí:teplota} °C, {Počasí:popis}, {Počasí:vlhkost} %, vítr {pocasi:vitr} m/s, pocitově {Počasí:pocitová}', $t));
    [$s] = resolve_text('{Počasí:zítra.max}/{Počasí:zítra.min} {Počasí:pozítří.den}', $t);
    ok(preg_match('/^-?\d+\/-?\d+ \S+$/u', $s), $s);
    eq('Zítra', resolve_text('{Počasí:zítra.den}', $t)[0]);
    eq('Praha', resolve_text('{Počasí:místo}', $t)[0]);
    eq('14', resolve_text('{Počasí:Brno.teplota}', $t)[0], 'jiné místo');
    [$s, $u] = resolve_text('{Počasí:nesmysl}', $t);
    eq(['{Počasí:nesmysl}'], $u, 'neznámé pole');
    eq(true, conds_ok([['l' => '{Počasí:teplota}', 'op' => '>', 'r' => '10']], $t), 'podmínka podle teploty');
});

test('mezipaměť: do 15 minut se znovu nestahuje', function () use ($calls) {
    $GLOBALS['WEATHER_MEM'] = [];
    $n = $calls();
    weather_get('Praha');
    weather_get('praha ');
    eq($n, $calls(), 'žádný nový dotaz');
    q("UPDATE weather SET fetched_at = ? WHERE place_key = 'praha'", [date('Y-m-d H:i:s', time() - 16 * 60)]);
    $GLOBALS['WEATHER_MEM'] = [];
    weather_get('Praha');
    eq($n + 2, $calls(), 'po 15 minutách nové stažení (aktuální + předpověď)');
});

test('výpadek: zůstanou poslední data, do protokolu jedno varování, po obnově informace', function () use ($t) {
    q('DELETE FROM logs');
    file_put_contents(TMP_DIR . '/ow-mock-down', '1');
    q("UPDATE weather SET fetched_at = ? WHERE place_key = 'praha'", [date('Y-m-d H:i:s', time() - 3600)]);
    $GLOBALS['WEATHER_MEM'] = [];
    [$data, $err] = weather_get('Praha');
    ok($data && $data['now']['temp'] == 12.4, 'poslední data');
    contains('503', (string)$err);
    eq('12', resolve_text('{Počasí:teplota}', $t)[0]);
    q("UPDATE weather SET fetched_at = ? WHERE place_key = 'praha'", [date('Y-m-d H:i:s', time() - 3600)]);
    weather_fetch('Praha');
    $w = q("SELECT message FROM logs WHERE level = 'warning'")->fetchAll(PDO::FETCH_COLUMN);
    eq(1, count($w), 'jedno varování: ' . show($w));
    contains('Slidy používají poslední stažená data', $w[0]);
    unlink(TMP_DIR . '/ow-mock-down');
    file_put_contents(TMP_DIR . '/ow-mock-temp', '-3.6');
    $r = weather_fetch('Praha');
    ok($r['ok']);
    eq('-4', resolve_text('{Počasí:teplota}', $t)[0]);
    ok((bool)q("SELECT COUNT(*) FROM logs WHERE message LIKE '%opět dostupné%'")->fetchColumn(), 'informace o obnově');
    unlink(TMP_DIR . '/ow-mock-temp');
    weather_fetch('Praha');
});

test('chybný klíč a neznámé místo: srozumitelná chyba, bez dat; bez klíče se nestahuje', function () use ($calls) {
    set_setting('weather_api_key', 'spatny-klic-123');
    $r = weather_fetch('Ostrava');
    ok(!$r['ok'] && $r['data'] === null);
    contains('nepřijal API klíč', $r['error']);
    set_setting('weather_api_key', 'test-klic-123');
    $r = weather_fetch('Neznámo');
    contains('Místo „Neznámo“ OpenWeather nezná', $r['error']);
    $GLOBALS['WEATHER_MEM'] = [];
    $n = $calls();
    [$d, $e] = weather_get('Neznámo');
    eq(null, $d);
    eq($n, $calls(), 'po chybě se hned znovu nezkouší');
    set_setting('weather_api_key', '');
    $GLOBALS['WEATHER_MEM'] = [];
    [$d, $e] = weather_get('Liberec');
    eq(null, $d);
    contains('Chybí API klíč', (string)$e);
    eq($n, $calls());
    set_setting('weather_api_key', 'test-klic-123');
});

/** Slide s prvkem Počasí. */
function weather_slide(string $name, array $el, string $text = ''): int {
    $els = [array_merge(['id' => 'w1', 'type' => 'weather', 'x' => 100, 'y' => 100, 'w' => 900, 'h' => 300], $el)];
    if ($text !== '') $els[] = ['id' => 't1', 'type' => 'text', 'text' => $text, 'x' => 0, 'y' => 600, 'w' => 1920, 'h' => 200];
    $design = sanitize_design(['elements' => $els]);
    $id = make_media("$name.jpg", 'slide', ['mime' => 'image/jpeg']);
    q('UPDATE media SET content = ? WHERE id = ?', [json_encode(['design' => $design]), $id]);
    return $id;
}

test('místa ze slidů a HTML dokumentů; cron stáhne jen potřebná a smaže stará', function () use ($calls) {
    weather_slide('Počasí Brno', ['place' => 'Brno', 'look' => 'card'], 'V Ostravě {Počasí:Ostrava.teplota} °C');
    $h = make_media('akce.html', 'html');
    q('UPDATE media SET content = ? WHERE id = ?', [json_encode(['source' => '<p>Zítra {Počasí:Plzeň.zítra.max}°</p>']), $h]);
    $used = weather_places_used();
    sort($used);
    eq(['Brno', 'Ostrava', 'Plzeň', 'Praha'], $used);
    q("INSERT INTO weather (place_key, place, provider, fetched_at) VALUES ('stare', 'Staré', 'openweather', ?)", [date('Y-m-d H:i:s', time() - 2 * 86400)]);
    weather_refresh_due();
    $rows = q('SELECT place_key FROM weather ORDER BY place_key')->fetchAll(PDO::FETCH_COLUMN);
    ok(!in_array('stare', $rows, true), 'staré místo smazáno: ' . show($rows));
    foreach (['brno', 'ostrava', 'plzeň', 'praha'] as $k) ok(in_array($k, $rows, true), "staženo $k: " . show($rows));
    $n = $calls();
    weather_refresh_due();
    eq($n, $calls(), 'podruhé nic nestahuje');
});

test('prvek Počasí: očištění, tři vzhledy uvnitř rámečku, zmenšení do úzkého pole', function () use ($t) {
    $d = sanitize_design(['elements' => [['type' => 'weather', 'look' => 'xxx', 'place' => '  Brno ', 'color' => 'red', 'x' => 10, 'y' => 10, 'w' => 500, 'h' => 200]]]);
    $e = $d['elements'][0];
    eq('weather', $e['type']);
    eq('mini', $e['look'], 'neznámý vzhled');
    eq('Brno', $e['place']);
    eq('#ffffff', $e['color']);
    [$data] = weather_get('Praha');
    foreach (['mini' => [2, 1], 'card' => [4, 1], 'days' => [9, 3]] as $look => [$nParts, $nIcons]) {
        foreach ([[100, 100, 900, 300], [100, 100, 260, 300]] as [$x, $y, $w, $h]) {
            $el = sanitize_design(['elements' => [['type' => 'weather', 'look' => $look, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h]]])['elements'][0];
            $parts = weather_layout($el, $data, $t);
            eq($nParts, count($parts), "$look $w: počet částí " . show($parts));
            eq($nIcons, count(array_filter($parts, fn($p) => $p['k'] === 'icon')), "$look ikony");
            foreach ($parts as $p) {
                $pw = $p['k'] === 'icon' ? $p['s'] : text_width($p['text'], $p['size'], font_file('montserrat', 700));
                ok($p['x'] >= $x - 1 && $p['x'] + $pw <= $x + $w + 1, "$look $w: část mimo šířku " . show($p));
                ok($p['y'] >= $y - 1 && $p['y'] <= $y + $h + 1, "$look $w: část mimo výšku " . show($p));
            }
        }
    }
    $el = sanitize_design(['elements' => [['type' => 'weather', 'look' => 'days', 'w' => 1200, 'h' => 300]]])['elements'][0];
    $texts = array_column(array_filter(weather_layout($el, $data, $t), fn($p) => $p['k'] === 'text'), 'text');
    eq('Dnes', $texts[0]);
    eq('Zítra', $texts[2]);
    ok(preg_match('/^-?\d+° \/ -?\d+°$/u', $texts[1]), $texts[1]);
});

test('vykreslení slidu: ikona a text v obrázku; klíč se změní jen se změnou počasí', function () use ($t) {
    foreach (['mini', 'card', 'days'] as $look) {
        $design = sanitize_design(['bg' => ['color' => '#000000'], 'elements' => [['id' => 'w', 'type' => 'weather', 'look' => $look, 'place' => 'Praha', 'x' => 0, 'y' => 0, 'w' => 1920, 'h' => 1080]]]);
        $im = imagecreatefromstring(render_slide($design, $t));
        $lit = 0;
        for ($y = 0; $y < 1080; $y += 12) for ($x = 0; $x < 1920; $x += 12) if ((imagecolorat($im, $x, $y) & 0xffffff) > 0x202020) $lit++;
        ok($lit > 200, "$look: na slidu je něco vidět ($lit)");
    }
    $k1 = render_key($design, $t);
    $GLOBALS['WEATHER_MEM'] = [];
    eq($k1, render_key($design, $t), 'stejná data → stejný klíč');
    file_put_contents(TMP_DIR . '/ow-mock-temp', '25');
    weather_fetch('Praha');
    $GLOBALS['WEATHER_MEM'] = [];
    ok($k1 !== render_key($design, $t), 'jiná teplota → nový obrázek');
    unlink(TMP_DIR . '/ow-mock-temp');
    weather_fetch('Praha');
});

test('bez dat: prvek se do obrázku nekreslí, editor dostane ukázku a důvod', function () use ($t) {
    $el = sanitize_design(['elements' => [['type' => 'weather', 'look' => 'card', 'place' => 'Neznámo']]])['elements'][0];
    $GLOBALS['WEATHER_MEM'] = [];
    eq([], weather_eval($el, $t)['parts']);
    $i = weather_editor_info($el, $t);
    ok($i['sample'] && count($i['parts']) === 4, show($i));
    contains('nezná', (string)$i['error']);
});
