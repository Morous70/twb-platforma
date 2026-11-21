<?php
// Diagnostika (verze 1.5.0).
require dirname(__DIR__) . '/lib.php';

test('vrátí položky ve správném tvaru', function () {
    $items = diag_run();
    ok(count($items) > 20, 'položek: ' . count($items));
    foreach ($items as $i) {
        eq(['group', 'name', 'status', 'value', 'hint'], array_keys($i));
        ok(in_array($i['status'], ['ok', 'warn', 'error', 'info'], true), "stav {$i['status']}");
        ok($i['value'] !== '' && $i['name'] !== '', 'prázdný text: ' . show($i));
        ok($i['status'] === 'ok' || $i['status'] === 'info' || $i['hint'] !== '' || $i['value'] === 'chyba kontroly', 'problém bez rady: ' . show($i));
    }
});

$find = function (string $name) { foreach (diag_run() as $i) if ($i['name'] === $name) return $i; return null; };

test('základní kontroly v testovacím prostředí', function () use ($find) {
    foreach (['Verze PHP', 'GD a písma', 'Databáze', 'Soubory galerie', 'Zápis do data', 'Šifrovaný ZIP (AES)'] as $n) eq('ok', $find($n)['status'] ?? null, $n);
    eq('warn', $find('Režim')['status'], 'testovací režim Disku');
    eq('error', $find('Poslední běh cronu')['status'], 'cron nikdy neběžel');
});

test('cron, zálohy a Synology podle času', function () use ($find) {
    set_setting('last_run_at', (string)(time() - 60));
    eq('ok', $find('Poslední běh cronu')['status']);
    set_setting('last_run_at', (string)(time() - 30 * 60));
    eq('warn', $find('Poslední běh cronu')['status']);
    set_setting('player_poll_at', (string)time());
    eq('ok', $find('Skript na Synology')['status']);
    set_setting('player_poll_at', (string)(time() - 2 * 3600));
    eq('error', $find('Skript na Synology')['status']);
    set_setting('backup_password', 'x');
    eq('error', $find('Poslední záloha')['status'], 'nikdy nezálohováno');
});

test('chybějící soubor galerie', function () use ($find) {
    make_media('ztraceny.jpg', 'image', ['filename' => 'neexistuje.jpg']);
    $i = $find('Soubory galerie');
    eq('error', $i['status']);
    contains('ztraceny.jpg', $i['value']);
});

test('bez funkce disk_free_space (Wedos) jen informace', function () {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=disk_free_space -r ' . escapeshellarg(
        'putenv("MP_CONFIG=' . getenv('MP_CONFIG') . '"); require "' . APP_DIR . '/lib/bootstrap.php"; require_once "' . APP_DIR . '/lib/sync.php"; require_once "' . APP_DIR . '/lib/backup.php"; require_once "' . APP_DIR . '/lib/image.php"; require "' . APP_DIR . '/lib/diag.php";'
        . 'foreach (diag_run() as $i) if ($i["name"] === "Místo na disku") echo $i["status"], "|", $i["value"];') . ' 2>&1');
    contains('info|', (string)$out);
    contains('hosting nesděluje', (string)$out);
});
