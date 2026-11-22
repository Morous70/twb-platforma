<?php
require dirname(__DIR__) . '/lib.php';
require KOREN . '/tools/lib/Sestavovac.php';

use TWB\Aktualizace\Balicek;

function zapisSoubor(string $cesta, string $obsah): void { @mkdir(dirname($cesta), 0775, true); file_put_contents($cesta, $obsah); }
function smazStrom(string $c): void { Balicek::smaz($c); }

/** Malé úložiště: moduly a, b (b vyžaduje a >=1.0.0), aplikace x. */
function uloziste(array $lock = ['a' => '1.2.0', 'b' => '0.3.1']): string
{
    $k = sys_get_temp_dir() . '/twb-sestav-' . bin2hex(random_bytes(4));
    zapisSoubor("$k/modules/a/module.json", json_encode(['name' => 'a', 'version' => '1.2.0', 'requires' => []]));
    zapisSoubor("$k/modules/a/src/A.php", "<?php\nnamespace TWB\\A;\nfinal class A {}\n");
    zapisSoubor("$k/modules/a/assets/a.css", 'body{}');
    zapisSoubor("$k/modules/b/module.json", json_encode(['name' => 'b', 'version' => '0.3.1', 'requires' => ['a' => '>=1.0.0']]));
    zapisSoubor("$k/modules/b/src/B.php", "<?php\nnamespace TWB\\B;\nfinal class B {}\n");
    zapisSoubor("$k/tests/a/a_test.php", '<?php // nepatří do balíčku');
    zapisSoubor("$k/apps/x/VERSION", "2.0.0\n");
    zapisSoubor("$k/apps/x/index.php", "<?php echo 'x';\n");
    zapisSoubor("$k/apps/x/install.php", "<?php // instalátor\n");
    zapisSoubor("$k/apps/x/config.sample.php", "<?php return [];\n");
    zapisSoubor("$k/apps/x/config.php", "<?php return ['tajne'];\n");
    zapisSoubor("$k/apps/x/lib/config.php", "<?php // knihovna se stejným jménem\n");
    zapisSoubor("$k/apps/x/data/.htaccess", "Require all denied\n");
    zapisSoubor("$k/apps/x/data/media/foto.jpg", 'jpg');
    zapisSoubor("$k/apps/x/data/app.sqlite", 'db');
    zapisSoubor("$k/apps/x/data/.gitkeep", '');
    zapisSoubor("$k/apps/x/data/tmp/pracovni.txt", 'tmp');
    zapisSoubor("$k/modules/a/tests/a_test.php", '<?php // test modulu');
    zapisSoubor("$k/apps/x/modules.lock", json_encode(['moduly' => $lock]));
    return $k;
}
function zipSeznam(string $zip): array
{
    $z = new ZipArchive(); $z->open($zip);
    $o = [];
    for ($i = 0; $i < $z->numFiles; $i++) $o[] = $z->getNameIndex($i);
    $z->close(); sort($o);
    return $o;
}

test('kontrola zámku: sedí verze i závislosti', function () {
    $k = uloziste();
    eq([], (new Sestavovac($k))->over('x'));
    smazStrom($k);
});

test('kontrola zámku: jiná verze modulu, chybějící modul i nesplněná závislost', function () {
    $k = uloziste(['a' => '1.1.0', 'b' => '0.3.1']);
    $ch = (new Sestavovac($k))->over('x');
    eq(1, count($ch)); ok(str_contains($ch[0], 'a má v zámku 1.1.0') && str_contains($ch[0], '1.2.0'), $ch[0]);
    smazStrom($k);

    $k = uloziste(['b' => '0.3.1']);
    $ch = (new Sestavovac($k))->over('x');
    ok(str_contains(implode(' ', $ch), 'vyžaduje modul a, který není v modules.lock'), implode('|', $ch));
    smazStrom($k);

    $k = uloziste(['a' => '1.2.0', 'neni' => '1.0.0']);
    ok(str_contains(implode(' ', (new Sestavovac($k))->over('x')), 'Modul neni neexistuje'));
    smazStrom($k);

    $k = uloziste();
    zapisSoubor("$k/modules/b/module.json", json_encode(['name' => 'b', 'version' => '0.3.1', 'requires' => ['a' => '>=2.0.0']]));
    ok(str_contains(implode(' ', (new Sestavovac($k))->over('x')), 'vyžaduje a >=2.0.0, v zámku je 1.2.0'));
    smazStrom($k);
});

test('podmínky verzí: >=, ^ a přesná', function () {
    ok(Sestavovac::splnuje('1.2.0', '>=1.2.0')); ok(!Sestavovac::splnuje('1.1.9', '>=1.2.0'));
    ok(Sestavovac::splnuje('1.9.0', '^1.2.0')); ok(!Sestavovac::splnuje('2.0.0', '^1.2.0')); ok(!Sestavovac::splnuje('1.1.0', '^1.2.0'));
    ok(Sestavovac::splnuje('1.2.3', '1.2.3')); ok(!Sestavovac::splnuje('1.2.4', '1.2.3'));
    ok(Sestavovac::splnuje('10.0.0', '>=9.0.0'), 'číselné, ne textové porovnání');
    throws(fn() => Sestavovac::splnuje('1.0.0', '~1.0'), 'Neznámá podmínka');
});

test('FULL: soubory aplikace, moduly bez testů, BUILD.json; bez config.php a databáze', function () {
    $k = uloziste();
    $r = (new Sestavovac($k, fn() => 1700000000))->sestav('x', "$k/dist");
    $s = zipSeznam($r['full']['soubor']);
    eq([
        'x/BUILD.json', 'x/VERSION', 'x/config.sample.php', 'x/data/.htaccess', 'x/index.php', 'x/install.php', 'x/lib/config.php', 'x/modules.lock',
        'x/modules/a/assets/a.css', 'x/modules/a/module.json', 'x/modules/a/src/A.php',
        'x/modules/b/module.json', 'x/modules/b/src/B.php',
    ], $s);
    eq($r['full']['souboru'], count($s));
    eq('twb-x-2.0.0-full.zip', basename($r['full']['soubor']));
    smazStrom($k);
});

test('název balíčku: předpona twb- se nezdvojí', function () {
    eq('twb-reklamy-0.8.0-full.zip', Sestavovac::nazevBalicku('reklamy', '0.8.0', 'full'));
    eq('twb-galerie-0.4.0-update.zip', Sestavovac::nazevBalicku('twb-galerie', '0.4.0', 'update'));
});

test('UPDATE: bez instalátoru, vzorové konfigurace a složky data', function () {
    $k = uloziste();
    $r = (new Sestavovac($k, fn() => 1700000000))->sestav('x', "$k/dist");
    $s = zipSeznam($r['update']['soubor']);
    ok(!in_array('x/install.php', $s) && !in_array('x/config.sample.php', $s) && !in_array('x/data/.htaccess', $s), implode(',', $s));
    ok(in_array('x/index.php', $s) && in_array('x/modules/a/src/A.php', $s) && in_array('x/lib/config.php', $s));
    eq('twb-x-2.0.0-update.zip', basename($r['update']['soubor']));
    smazStrom($k);
});

test('BUILD.json: verze, moduly a SHA-256 každého souboru', function () {
    $k = uloziste();
    $r = (new Sestavovac($k, fn() => 1700000000))->sestav('x', "$k/dist");
    $z = new ZipArchive(); $z->open($r['update']['soubor']);
    $b = json_decode($z->getFromName('x/BUILD.json'), true);
    eq('x', $b['app']); eq('2.0.0', $b['verze']); eq('update', $b['druh']); eq(['a' => '1.2.0', 'b' => '0.3.1'], $b['moduly']);
    eq('2023-11-14T22:13:20+00:00', $b['sestaveno']);
    eq(hash('sha256', "<?php\nnamespace TWB\\A;\nfinal class A {}\n"), $b['soubory']['modules/a/src/A.php']);
    ok(!isset($b['soubory']['BUILD.json']));
    foreach ($b['soubory'] as $rel => $h) eq($h, hash('sha256', (string)$z->getFromName("x/$rel")), $rel);
    $z->close();
    smazStrom($k);
});

test('sestavení je opakovatelné: stejné vstupy a čas dají shodný soubor', function () {
    $k = uloziste();
    $s = new Sestavovac($k, fn() => 1700000000);
    $a = $s->sestav('x', "$k/d1"); $b = $s->sestav('x', "$k/d2");
    eq($a['full']['sha256'], $b['full']['sha256']); eq($a['update']['sha256'], $b['update']['sha256']);
    ok($a['full']['sha256'] !== $a['update']['sha256']);
    $c = (new Sestavovac($k, fn() => 1700000001))->sestav('x', "$k/d3");
    ok($c['full']['sha256'] !== $a['full']['sha256'], 'jiný čas sestavení se projeví');
    smazStrom($k);
});

test('balíček přijme modul aktualizace: rozbalí se a VERSION souhlasí', function () {
    $k = uloziste();
    $r = (new Sestavovac($k, fn() => 1700000000))->sestav('x', "$k/dist");
    $cil = "$k/cil";
    $soubory = Balicek::rozbal($r['update']['soubor'], $cil, 'x/', ['config.php', 'data']);
    ok(in_array('index.php', $soubory) && in_array('modules/b/src/B.php', $soubory) && in_array('BUILD.json', $soubory));
    eq('2.0.0', trim(file_get_contents("$cil/VERSION")));
    eq(hash_file('sha256', "$k/modules/a/src/A.php"), hash_file('sha256', "$cil/modules/a/src/A.php"));
    $f = Balicek::rozbal($r['full']['soubor'], "$k/cil2", 'x/', ['config.php', 'data']);
    ok(!in_array('data/.htaccess', $f), 'složku data instalátor nikdy nepřepisuje');
    smazStrom($k);
});

test('sestavení se zastaví: verze v zámku, chybějící povinný soubor, chybná VERSION', function () {
    $k = uloziste(['a' => '9.9.9']);
    throws(fn() => (new Sestavovac($k))->sestav('x', "$k/dist"), 'Balíček nelze sestavit');
    ok(!is_dir("$k/dist") || !glob("$k/dist/*.zip"), 'nic se nezapsalo');
    smazStrom($k);

    $k = uloziste(); unlink("$k/apps/x/index.php");
    throws(fn() => (new Sestavovac($k))->sestav('x', "$k/dist"), 'chybí povinný soubor index.php');
    smazStrom($k);

    $k = uloziste(); file_put_contents("$k/apps/x/VERSION", 'dev');
    throws(fn() => (new Sestavovac($k))->nacti('x'), 'VERSION');
    smazStrom($k);

    throws(fn() => (new Sestavovac(sys_get_temp_dir()))->nacti('../x'), 'Neplatný název');
    throws(fn() => (new Sestavovac(sys_get_temp_dir()))->nacti('neexistuje'), 'neexistuje');
});

test('build.json: vlastní složka v ZIPu, vlastní povinné a „jen FULL“ soubory', function () {
    $k = uloziste();
    zapisSoubor("$k/apps/x/build.json", json_encode(['koren' => 'twb-x/', 'povinne' => ['index.php', 'health.php'], 'jen_full' => ['install.php']]));
    throws(fn() => (new Sestavovac($k))->sestav('x', "$k/dist"), 'health.php');
    zapisSoubor("$k/apps/x/health.php", '<?php');
    $r = (new Sestavovac($k, fn() => 1))->sestav('x', "$k/dist");
    $u = zipSeznam($r['update']['soubor']);
    ok(in_array('twb-x/index.php', $u) && in_array('twb-x/config.sample.php', $u) && in_array('twb-x/data/.htaccess', $u) && !in_array('twb-x/install.php', $u), implode(',', $u));
    zapisSoubor("$k/apps/x/build.json", json_encode(['koren' => '../x']));
    throws(fn() => (new Sestavovac($k))->nacti('x'), 'koren');
    smazStrom($k);
});

test('kontrola syntaxe PHP zachytí chybný soubor (i v modulu)', function () {
    $k = uloziste();
    zapisSoubor("$k/modules/b/src/B.php", "<?php\nfinal class B { function ( }\n");
    throws(fn() => (new Sestavovac($k, null, true))->sestav('x', "$k/dist"), 'modules/b/src/B.php');
    (new Sestavovac($k, null, false))->sestav('x', "$k/dist2");   // bez lintu projde
    zapisSoubor("$k/modules/b/src/B.php", "<?php\nfinal class B {}\n");
    $r = (new Sestavovac($k, null, true))->sestav('x', "$k/dist3");
    ok(is_file($r['full']['soubor']));
    smazStrom($k);
});

test('symbolický odkaz v aplikaci nebo modulu sestavení zastaví', function () {
    $k = uloziste();
    symlink('/etc/passwd', "$k/apps/x/odkaz.txt");
    throws(fn() => (new Sestavovac($k))->sestav('x', "$k/dist"), 'Symbolický odkaz');
    smazStrom($k);
});

test('aktualizace zámku přepíše verze podle module.json', function () {
    $k = uloziste(['a' => '1.0.0', 'b' => '0.1.0']);
    $s = new Sestavovac($k);
    ok(count($s->over('x')) >= 2);
    $s->aktualizujZamek('x');
    eq([], $s->over('x'));
    $j = json_decode(file_get_contents("$k/apps/x/modules.lock"), true);
    eq(['a' => '1.2.0', 'b' => '0.3.1'], $j['moduly']); eq('x', $j['app']);
    smazStrom($k);
});

test('aplikace bez modules.lock se sestaví bez modulů (stávající aplikace beze změny)', function () {
    $k = uloziste(); unlink("$k/apps/x/modules.lock");
    $r = (new Sestavovac($k, fn() => 1))->sestav('x', "$k/dist");
    $s = zipSeznam($r['full']['soubor']);
    ok(!array_filter($s, fn($n) => str_contains($n, '/modules/')), implode(',', $s));
    smazStrom($k);
});

test('skutečné úložiště: Plánovač médií se sestaví beze změny jeho souborů', function () {
    $tmp = sys_get_temp_dir() . '/twb-sestav-real-' . bin2hex(random_bytes(3));
    $r = (new Sestavovac(KOREN, fn() => 1700000000))->sestav('planovac-medii', $tmp);
    $z = new ZipArchive(); $z->open($r['update']['soubor']);
    eq(trim(file_get_contents(KOREN . '/apps/planovac-medii/VERSION')), trim($z->getFromName('planovac-medii/VERSION')));
    ok($z->getFromName('planovac-medii/index.php') === file_get_contents(KOREN . '/apps/planovac-medii/index.php'));
    ok($z->getFromName('planovac-medii/config.php') === false);
    $z->close();
    smazStrom($tmp);
});

test('pořadí v ZIPu je řazené, BUILD.json poslední a nikdy dvakrát; čas souborů je čas sestavení', function () {
    $k = uloziste(['b' => '0.3.1', 'a' => '1.2.0']);
    zapisSoubor("$k/apps/x/zzz.php", '<?php');
    zapisSoubor("$k/apps/x/BUILD.json", '{"stary":true}');
    $r = (new Sestavovac($k, fn() => 1700000000))->sestav('x', "$k/dist");
    $z = new ZipArchive(); $z->open($r['full']['soubor']);
    $jmena = []; for ($i = 0; $i < $z->numFiles; $i++) $jmena[] = $z->getNameIndex($i);
    eq('x/BUILD.json', end($jmena)); eq(1, count(array_keys($jmena, 'x/BUILD.json')), 'jen jednou');
    $bez = array_slice($jmena, 0, -1); $razene = $bez; sort($razene, SORT_STRING);
    eq($razene, $bez, 'řazeno');
    eq(1700000000, $z->statIndex(0)['mtime']);
    $b = json_decode($z->getFromName('x/BUILD.json'), true);
    eq(['a' => '1.2.0', 'b' => '0.3.1'], $b['moduly'], 'moduly řazeny');
    eq(['a', 'b'], array_keys($b['moduly']));
    $z->close();
    smazStrom($k);
});
