<?php
// Písma: nahrání, kontrola, české znaky, použití, přejmenování, odebrání, zálohy (přeneseno z testů Plánovače médií).
require dirname(__DIR__) . '/lib.php';

use TWB\Pisma\Pisma;

$FONTY = KOREN . '/modules/pisma/fonty';
$FIX = KOREN . '/tests/fixtures';

function pisma(?callable $pouziti = null): array
{
    $h = novyHost();
    $p = new Pisma($h->nastaveni, $h->protokol, $GLOBALS['FONTY'], $h->datovaSlozka . '/fonts', $pouziti);
    return [$h, $p];
}
function kopie(string $zdroj): string
{
    $t = sys_get_temp_dir() . '/up-' . bin2hex(random_bytes(3));
    copy($zdroj, $t);
    return $t;
}

test('vestavěná písma: všechny soubory existují a jdou použít', function () use ($FONTY) {
    [, $p] = pisma();
    eq(9, count($p->vsechna()));
    foreach ($p->vsechna() as $k => $f) foreach ($f['weights'] as $w) ok(is_file($p->soubor($k, $w)), "$k-$w");
    ok(function_exists('imagettfbbox') && imagettfbbox(20, 0, $p->soubor('roboto', 400), 'Příliš žluťoučký') !== false, 'vykreslí se');
});

test('nejbližší tloušťka; neznámé písmo = Roboto; adresy pro editor', function () use ($FONTY) {
    [, $p] = pisma();
    eq("$FONTY/Roboto-900.ttf", $p->soubor('roboto', 850));
    eq("$FONTY/Roboto-400.ttf", $p->soubor('roboto', 300));
    eq("$FONTY/Anton-700.ttf", $p->soubor('anton', 400), 'jediná tloušťka');
    eq("$FONTY/Roboto-700.ttf", $p->soubor('neexistuje', 700));
    eq('assets/fonts/Oswald-700.ttf', $p->vystup('oswald')['files'][700]);
});

test('nahrání TTF: v seznamu písem, soubor v datové složce, název z názvu souboru', function () use ($FONTY) {
    [$h, $p] = pisma();
    [$f, $chybi] = $p->nahraj(kopie("$FONTY/Anton-700.ttf"), 'Moje-Anton_Bold.ttf');
    eq('Moje Anton Bold', $f['name']);
    eq([], $chybi);
    ok(str_starts_with($f['key'], 'u_') && $f['custom'], 'klíč');
    ok(is_file($h->datovaSlozka . '/fonts/' . $p->vlastni()[$f['key']]['file']), 'soubor');
    eq('api.php?a=font&key=' . $f['key'], $f['files'][700]);
    ok($p->existuje($f['key']));
    eq($h->datovaSlozka . '/fonts/' . $p->vlastni()[$f['key']]['file'], $p->soubor($f['key'], 400));
    eq('info', $h->protokol->zaznamy[0][0]);
});

test('písmo bez českých znaků se nahraje s upozorněním', function () use ($FONTY, $FIX) {
    [$h, $p] = pisma();
    [$f, $chybi] = $p->nahraj(kopie("$FIX/ascii-jen.ttf"), 'ascii.ttf', 'Jen ASCII');
    eq('Jen ASCII', $f['name']);
    ok(count($chybi) >= 20 && in_array('ř', $chybi, true), 'chybí: ' . implode('', $chybi));
    ok(str_contains($h->protokol->zaznamy[0][1], 'Chybí české znaky'), 'v protokolu');
    eq([], $p->chybejiciZnaky("$FONTY/TitanOne-900.ttf"), 'Titan One má vše');
});

test('odmítne jiný soubor, prázdný, velký a poškozený', function () use ($FONTY) {
    [, $p] = pisma();
    throws(fn() => $p->nahraj(kopie("$FONTY/Anton-700.ttf"), 'pismo.woff'), 'TTF nebo OTF');
    $t = sys_get_temp_dir() . '/prazdne.ttf'; file_put_contents($t, '');
    throws(fn() => $p->nahraj($t, 'a.ttf'), 'prázdný');
    $t = sys_get_temp_dir() . '/text.ttf'; file_put_contents($t, str_repeat('neni pismo ', 100));
    throws(fn() => $p->nahraj($t, 'a.ttf'), 'není písmo');
    $t = sys_get_temp_dir() . '/velke.ttf'; file_put_contents($t, "\x00\x01\x00\x00" . str_repeat('x', Pisma::MAX_BAJTU));
    throws(fn() => $p->nahraj($t, 'a.ttf'), '5 MB');
    $t = sys_get_temp_dir() . '/rozbite.ttf'; file_put_contents($t, "\x00\x01\x00\x00" . str_repeat("\x00", 3000));
    throws(fn() => $p->nahraj($t, 'a.ttf'), 'nepodařilo načíst');
});

test('použité písmo nejde odebrat; přejmenování; odebrání nepoužitého smaže soubor', function () use ($FONTY) {
    $pouzite = [];
    [$h, $p] = pisma(function (string $klic) use (&$pouzite) { return $pouzite[$klic] ?? []; });
    [$f] = $p->nahraj(kopie("$FONTY/Anton-700.ttf"), 'Pouzite.ttf');
    $pouzite[$f['key']] = ['Akce.jpg'];
    throws(fn() => $p->smaz($f['key']), 'použité ve slidu „Akce.jpg“');
    eq('Nové jméno', $p->prejmenuj($f['key'], ' Nové jméno ')['name']);
    throws(fn() => $p->prejmenuj($f['key'], ''), 'Zadejte název');
    $pouzite = [];
    $soubor = $h->datovaSlozka . '/fonts/' . $p->vlastni()[$f['key']]['file'];
    $p->smaz($f['key']);
    ok(!is_file($soubor) && !$p->existuje($f['key']), 'smazáno');
    throws(fn() => $p->smaz('u_neni'), 'nenalezeno');
    eq('Odebráno písmo „Nové jméno“.', end($h->protokol->zaznamy)[1]);
});

test('seznam pro zálohu obsahuje jen existující vlastní soubory s kontrolním součtem', function () use ($FONTY) {
    [$h, $p] = pisma();
    [$f] = $p->nahraj(kopie("$FONTY/Anton-700.ttf"), 'Zalohovane.ttf');
    $soubor = $p->vlastni()[$f['key']]['file'];
    eq([$soubor => md5_file("$FONTY/Anton-700.ttf")], $p->souboryProZalohu());
    $n = $h->nastaveni->dej('custom_fonts');
    $h->nastaveni->uloz('custom_fonts', str_replace($soubor, '../../etc/passwd', $n));
    eq([], $p->souboryProZalohu(), 'podvržená cesta se nezahrne');
});

test('vlastní písmo s neplatným klíčem nebo chybějícím souborem se ignoruje', function () {
    [$h, $p] = pisma();
    $h->nastaveni->uloz('custom_fonts', json_encode(['x_zly' => ['name' => 'A', 'file' => 'a.ttf'], 'u_chybi' => ['name' => 'B', 'file' => 'u_chybi.ttf']]));
    eq(9, count($p->vsechna(true)));
});
