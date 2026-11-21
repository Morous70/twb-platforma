<?php
// Zálohy (verze 1.3.0): šifrování, soubory jen při změně, ponechání 7 + poslední se soubory, obnovení.
require dirname(__DIR__) . '/lib.php';

if ($why = backup_supported()) { test('zálohy podporované', fn() => throw new TestFailure($why)); exit; }

test('bez hesla se nezálohuje', function () {
    throws(fn() => backup_create(), 'heslo');
});

set_setting('backup_password', 'Tajne-heslo-1');
/** Záloha. Starší zálohy se posunou o 2 s do minulosti – více záloh v jedné sekundě by se jinak řadilo jen podle názvu. */
function bk(string $kind = ''): array {
    foreach (glob(BACKUP_DIR . '/*.zip') ?: [] as $f) touch($f, filemtime($f) - 2);
    clearstatcache();
    return backup_create($kind);
}
$img = make_media('obr.jpg');
$sc = make_scenario('Výchozí', [$img], ['default' => 1]);

test('první záloha obsahuje soubory, druhá už jen databázi', function () {
    $b1 = bk();
    ok($b1['files'], 'první se soubory');
    contains('-soubory', $b1['name']);
    $b2 = bk();
    ok(!$b2['files'], 'druhá bez souborů');
    make_media('novy.jpg');
    ok(bk()['files'], 'po změně galerie zase se soubory');
});

test('ZIP je šifrovaný: bez hesla ani se špatným heslem nejde přečíst', function () {
    $p = backup_path(backup_list()[0]['name']);
    $z = new ZipArchive(); $z->open($p);
    eq(false, @$z->getFromName('database.json'), 'bez hesla');
    $z->setPassword('spatne');
    eq(false, @$z->getFromName('database.json'), 'špatné heslo');
    $z->close();
    throws(fn() => backup_info($p, 'spatne'), 'nesprávné heslo');
    $i = backup_info($p, 'Tajne-heslo-1');
    eq([2, 1], [$i['media'], $i['scenarios']]);
});

test('na serveru zůstane 7 posledních + poslední se soubory', function () {
    for ($i = 0; $i < 9; $i++) bk();
    $list = backup_list();
    eq(8, count($list), show(array_column($list, 'name')));
    eq(1, count(array_filter($list, fn($b) => $b['files'])), 'zálohy se soubory');
    ok($list[7]['files'], 'nejstarší ponechaná je ta se soubory');
});

test('obnovení vrátí data i soubory a předtím udělá pojistnou zálohu', function () use ($img) {
    $withFiles = array_values(array_filter(backup_list(), fn($b) => $b['files']))[0]['name'];
    $file = q('SELECT filename FROM media WHERE id = ?', [$img])->fetchColumn();
    // pokazit stav: smazat médium i soubor, přidat scénář
    q('DELETE FROM media WHERE id = ?', [$img]);
    @unlink(MEDIA_DIR . "/$file");
    make_scenario('Smetí');
    set_setting('backup_nas_at', '12345');
    $r = backup_restore(backup_path($withFiles), 'Tajne-heslo-1');
    setting('__reload');
    contains('-pred-obnovou', $r['safety']);
    eq([], $r['missing']);
    eq('obr.jpg', q('SELECT original_name FROM media WHERE id = ?', [$img])->fetchColumn());
    ok(is_file(MEDIA_DIR . "/$file"), 'soubor obnoven');
    eq(0, (int)q("SELECT COUNT(*) FROM scenarios WHERE name = 'Smetí'")->fetchColumn());
    eq('12345', setting('backup_nas_at'), 'místní údaj o Synology zůstal');
    eq(['obr.jpg'], playing('2026-11-05 10:00'));
});

test('zálohu z novější verze databáze odmítne', function () {
    $name = backup_list()[0]['name'];
    $p = backup_path($name);
    [$zip, $db] = backup_open($p, 'Tajne-heslo-1'); $zip->close();
    $db['schema_version'] = SCHEMA_VERSION + 1;
    $f = BACKUP_DIR . '/' . BACKUP_PREFIX . '2099-01-01-000000-nahrana.zip';
    $z = new ZipArchive(); $z->open($f, ZipArchive::CREATE);
    $z->setPassword('Tajne-heslo-1');
    $z->addFromString('database.json', json_encode($db));
    $z->setEncryptionName('database.json', ZipArchive::EM_AES_256, 'Tajne-heslo-1');
    $z->close();
    throws(fn() => backup_restore($f, 'Tajne-heslo-1'), 'novější');
});

test('název zálohy s cestou se odmítne', function () {
    throws(fn() => backup_path('../config.php'), 'nenalezena');
    throws(fn() => backup_path(BACKUP_PREFIX . '../../x.zip'), 'nenalezena');
});
