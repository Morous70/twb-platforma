<?php
declare(strict_types=1);
// TWB Media galerie 0.4: balíček pro Update Server – název, obsah, zámek modulů a samostatný běh rozbaleného balíčku.
require __DIR__ . '/pomocne.php';
require KOREN . '/tools/lib/Sestavovac.php';

echo "Media galerie: balíček\n";
$s = new Sestavovac(KOREN, null, false);

test('zámek modulů sedí a balíček obsahuje aplikaci, nápovědu a použité moduly', function () use ($s) {
    eq([], $s->over('twb-galerie'));
    $full = array_keys($s->soubory('twb-galerie', true));
    $update = array_keys($s->soubory('twb-galerie', false));
    foreach (['index.php', 'health.php', 'install.php', 'cron.php', 'api.php', 'soubor.php', 'nahrat.php', 'media.php', 'google.php', 'passkey.php',
              'VERSION', 'modules.lock', '.htaccess', 'data/.htaccess', 'stranky/nastaveni.php', 'help/_obsah.json', 'help/sprava/zalohy.md',
              'modules/galerie/src/Galerie.php', 'modules/prihlaseni/src/Passkey.php', 'modules/zalohy/src/Zalohy.php', 'modules/aktualizace/src/Aktualizace.php'] as $f) {
        ok(in_array($f, $full, true), "FULL bez $f");
    }
    foreach ($full as $f) {
        ok(!str_starts_with($f, 'data/') || $f === 'data/.htaccess', "FULL obsahuje data: $f");
        ok($f !== 'config.php' && !str_contains($f, '/tests/') && !str_starts_with($f, 'tests/'), "nepatří do balíčku: $f");
    }
    ok(!in_array('install.php', $update, true) && !in_array('config.sample.php', $update, true), 'UPDATE bez instalátoru');
    foreach (json_decode((string)file_get_contents(KOREN . '/apps/twb-galerie/modules.lock'), true)['moduly'] as $m => $v) {
        eq($v, json_decode((string)file_get_contents(KOREN . "/modules/$m/module.json"), true)['version'], "verze modulu $m v zámku");
    }
});

test('rozbalený balíček FULL (twb-galerie-<verze>-full.zip) běží samostatně', function () use ($s) {
    $verze = trim((string)file_get_contents(KOREN . '/apps/twb-galerie/VERSION'));
    $r = $s->sestav('twb-galerie', docasna('balicek'));
    eq("twb-galerie-$verze-full.zip", basename($r['full']['soubor']));
    eq("twb-galerie-$verze-update.zip", basename($r['update']['soubor']));
    $zip = new ZipArchive();
    eq(true, $zip->open($r['full']['soubor']));
    $koren = docasna('rozbaleno');
    mkdir($koren);
    $zip->extractTo($koren);
    $zip->close();
    $app = "$koren/twb-galerie";
    eq($verze, trim((string)file_get_contents("$app/VERSION")));
    ok(is_file("$app/modules/jadro/boot.php"), 'moduly vedle aplikace');
    $skript = docasna('beh') . '.php';
    file_put_contents($skript, '<?php require ' . var_export("$app/lib/autoload.php", true) . ';
        $a = new MediaGalerie\App(' . var_export($app, true) . ', ["data_dir" => ' . var_export("$koren/data", true) . ']);
        $a->migruj();
        [$chyby] = $a->napoveda()->zkontroluj();
        echo json_encode(["moduly" => dirname((new ReflectionClass(TWB\Galerie\Galerie::class))->getFileName(), 3), "schema" => $a->nastaveni()->dej("schema_version"), "chyby" => $chyby, "stranek" => count($a->napoveda()->poradi())]);');
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($skript) . ' 2>&1');
    $j = json_decode($out, true);
    ok(is_array($j), $out);
    eq("$app/modules", $j['moduly'], 'moduly z balíčku');
    eq((string)MediaGalerie\Schema::verze(), $j['schema']);
    eq([], $j['chyby']);
    ok($j['stranek'] >= 11, 'stránek nápovědy: ' . $j['stranek']);
});
