<?php
declare(strict_types=1);
// TWB Reklamy 0.5: balíček pro Update Server – obsah, zámek modulů a samostatný běh rozbaleného balíčku (bez ../../modules).
require __DIR__ . '/pomocne.php';
require KOREN . '/tools/lib/Sestavovac.php';

echo "Reklamy: balíček\n";
$s = new Sestavovac(KOREN, null, false);

test('zámek modulů sedí a balíček obsahuje aplikaci, nápovědu a použité moduly', function () use ($s) {
    eq([], $s->over('reklamy'));
    $full = array_keys($s->soubory('reklamy', true));
    $update = array_keys($s->soubory('reklamy', false));
    foreach (['index.php', 'health.php', 'install.php', 'cron.php', 'VERSION', 'modules.lock', '.htaccess', 'd/t.js', 'd/konverze.php', 'stranky/statistiky.php',
              'stranky/napoveda.php', 'help/_obsah.json', 'help/jak-na/konverze.md', 'assets/slide-shim.js',
              'modules/napoveda/src/Markdown.php', 'modules/slidy/web/slide-editor.js', 'modules/aktualizace/src/Aktualizace.php'] as $f) ok(in_array($f, $full, true), "FULL bez $f");
    foreach ($full as $f) {
        ok(!str_starts_with($f, 'data/') || $f === 'data/.htaccess', "FULL obsahuje data: $f");
        ok($f !== 'config.php' && !str_contains($f, '/tests/') && !str_starts_with($f, 'tests/'), "nepatří do balíčku: $f");
    }
    ok(!in_array('install.php', $update, true) && !in_array('config.sample.php', $update, true), 'UPDATE bez instalátoru');
    foreach (json_decode((string)file_get_contents(KOREN . '/apps/reklamy/modules.lock'), true)['moduly'] as $m => $v) {
        eq($v, json_decode((string)file_get_contents(KOREN . "/modules/$m/module.json"), true)['version'], "verze modulu $m v zámku");
    }
});

test('rozbalený balíček FULL běží samostatně: instalace, nápověda, převod databáze', function () use ($s) {
    $cil = docasna('balicek');
    $r = $s->sestav('reklamy', $cil);
    $zip = new ZipArchive();
    eq(true, $zip->open($r['full']['soubor']));
    $koren = docasna('rozbaleno');
    mkdir($koren);
    $zip->extractTo($koren);
    $zip->close();
    $app = "$koren/reklamy";
    eq(trim((string)file_get_contents(KOREN . '/apps/reklamy/VERSION')), trim((string)file_get_contents("$app/VERSION")));
    ok(is_file("$app/modules/jadro/boot.php"), 'moduly vedle aplikace');
    // samostatný proces jen se soubory balíčku: nesmí sáhnout do ../../modules
    $skript = docasna('beh') . '.php';
    file_put_contents($skript, '<?php require ' . var_export("$app/lib/autoload.php", true) . ';
        $a = new Reklamy\App(' . var_export($app, true) . ', ["data_dir" => ' . var_export("$koren/data", true) . ']);
        $a->migruj();
        [$chyby] = $a->napoveda()->zkontroluj();
        echo json_encode(["moduly" => Reklamy\App::moduly(), "schema" => $a->nastaveni()->dej("schema_version"), "chyby" => $chyby, "stranek" => count($a->napoveda()->poradi()),
            "md" => (new TWB\Napoveda\Markdown())->html("**ok**")]);');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($skript) . ' 2>&1');
    $j = json_decode($out, true);
    ok(is_array($j), $out);
    eq("$app/modules", $j['moduly'], 'moduly z balíčku');
    eq((string)Reklamy\Schema::verze(), $j['schema']);
    eq([], $j['chyby']);
    ok($j['stranek'] >= 15);
    eq('<p><b>ok</b></p>', $j['md']);
});
