<?php
// Synchronizace do složky na Google Disku (zde testovací režim drive_mock → data/mock-drive).
require dirname(__DIR__) . '/lib.php';

$dir = DATA_DIR . '/mock-drive/slozka';
$files = function () use ($dir) { $f = array_values(array_diff(scandir($dir) ?: [], ['.', '..'])); sort($f); return $f; };
$sync = fn(string $at, bool $force = true) => run_sync($force, new DateTimeImmutable($at));

test('bez cílové složky chyba', function () use ($sync) {
    $r = $sync('2026-11-05 10:00');
    eq('error', $r['status']);
    contains('cílová složka', $r['message']);
    contains('cílová složka', (string)setting('last_error'));
});

set_setting('folder_id', 'slozka');
$a = make_media('a.jpg'); $b = make_media('b.jpg');
$def = make_scenario('Výchozí', [$a], ['default' => 1]);
$plan = make_scenario('Akce', [$b], ['from' => '2026-11-10', 'to' => '2026-11-12']);

test('nahraje, co má běžet, a zaznamená změnu pro Synology', function () use ($sync, $files) {
    set_setting('last_change_at', null);
    $r = $sync('2026-11-05 10:00');
    eq(['ok', ['a.jpg'], []], [$r['status'], $r['uploaded'], $r['removed']]);
    eq(['a.jpg'], $files());
    ok((int)setting('last_change_at') > 0, 'last_change_at');
    eq(null, setting('last_error'));
});

test('beze změny nic nenahrává ani neposouvá čas změny', function () use ($sync) {
    set_setting('last_change_at', '1');
    eq('unchanged', $sync('2026-11-05 11:00', false)['status']);
    $r = $sync('2026-11-05 11:00', true);
    eq([[], []], [$r['uploaded'], $r['removed']]);
    eq('1', setting('last_change_at'));
});

test('přepnutí scénáře: nové nahraje, staré odebere', function () use ($sync, $files) {
    $r = $sync('2026-11-11 10:00');
    eq([['b.jpg'], ['a.jpg']], [$r['uploaded'], $r['removed']]);
    eq(['b.jpg'], $files());
});

test('cizí soubor ve složce se uklidí, změněný obsah se nahraje znovu', function () use ($sync, $files, $dir, $b) {
    file_put_contents("$dir/cizi.txt", 'x');
    file_put_contents("$dir/b.jpg", 'jiný obsah');
    $r = $sync('2026-11-11 10:00');
    eq(['b.jpg'], $r['uploaded']);
    ok(in_array('cizi.txt', $r['removed'], true));
    eq(['b.jpg'], $files());
    eq(md5_file(MEDIA_DIR . '/' . q('SELECT filename FROM media WHERE id = ?', [$b])->fetchColumn()), md5_file("$dir/b.jpg"));
});

test('chybějící soubor média → srozumitelná chyba a záznam v protokolu', function () use ($sync, $a) {
    @unlink(MEDIA_DIR . '/' . q('SELECT filename FROM media WHERE id = ?', [$a])->fetchColumn());
    $r = $sync('2026-11-20 10:00');
    eq('error', $r['status']);
    contains('a.jpg', $r['message']);
    contains('Synchronizace selhala', (string)q("SELECT message FROM logs WHERE level = 'error' ORDER BY id DESC")->fetchColumn());
});
