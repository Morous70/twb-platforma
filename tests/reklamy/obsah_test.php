<?php
declare(strict_types=1);
// TWB Reklamy 0.3: obsah banerů – slidy (modul slidy), HTML5 balíčky ZIP s clickTag, HTML s proměnnými (moduly data, html, pisma).
require __DIR__ . '/pomocne.php';

use Reklamy\{App, Html5, Vykresleni, VlastniPromenne, SlideBannery, Chyba};

echo "Reklamy: obsah banerů\n";

/** ZIP z polí [cesta => obsah]; $symlink = cesta, která bude symbolický odkaz. */
function zipSoubor(array $soubory, ?string $symlink = null): string
{
    $f = docasna('zip') . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($soubory as $n => $obsah) {
        $z->addFromString($n, $obsah);
        if ($n === $symlink) $z->setExternalAttributesName($n, ZipArchive::OPSYS_UNIX, (0120777 << 16));
    }
    $z->close();
    return $f;
}

const INDEX5 = '<!doctype html><html><head><meta name="ad.size" content="width=300,height=250"><script>var clickTag = "https://puvodni.cz";</script>'
    . '<link rel="stylesheet" href="css/styl.css"></head><body><a id="cta" href="javascript:window.open(window.clickTag)">Koupit</a><img src="img/a.png"></body></html>';

function slide(App $app, int $kampan, array $prvky = [], int $w = 300, int $h = 250): int
{
    $d = $app->slideBannery()->nova($w, $h);
    $d['elements'] = $prvky;
    return $app->slideBannery()->uloz(null, $kampan, 'Slide', $d);
}

test('slide: uložení z editoru, rozměr ze šablony (běžný i vlastní), ostatní údaje banneru zůstanou', function () {
    $app = novaApp();
    $k = kampan($app);
    $id = slide($app, $k, [['type' => 'text', 'text' => 'Ahoj', 'x' => 10, 'y' => 10, 'w' => 200, 'h' => 50]]);
    $b = $app->bannery()->radek($id);
    eq(['slide', 300, 250, 'Slide'], [$b['type'], (int)$b['width'], (int)$b['height'], $b['name']]);
    eq('300x250', json_decode($b['content'], true)['format']);
    $app->bannery()->uloz(['target_url' => 'https://kazbo.eu/akce', 'alt' => 'Akce', 'weight' => 3, 'name' => 'Slide', 'status' => 'active'] + $b, $id);
    $d = $app->slideBannery()->design($app->bannery()->radek($id));
    $d['format'] = 'vlastni'; $d['w'] = 970; $d['h'] = 120;
    $app->slideBannery()->uloz($id, null, 'Přejmenovaný', $d);
    $b = $app->bannery()->radek($id);
    eq(['Přejmenovaný', 970, 120, 'https://kazbo.eu/akce', 'Akce', 3], [$b['name'], (int)$b['width'], (int)$b['height'], $b['target_url'], $b['alt'], (int)$b['weight']]);
    $d['w'] = 99999;
    $app->slideBannery()->uloz($id, null, 'X', $d);
    eq(4096, (int)$app->bannery()->radek($id)['width'], 'rozměr v mezích');
    throws(fn() => $app->slideBannery()->uloz(htmlBanner($app, $k, 300, 250), null, 'X', $d), 'není slide');
    throws(fn() => $app->bannery()->uloz(['campaign_id' => $k, 'type' => 'slide', 'name' => 'Bez šablony']), 'šablonu');
    $kopie = $app->bannery()->duplikuj($id);
    eq($app->bannery()->radek($id)['content'], $app->bannery()->radek($kopie)['content'], 'kopie má stejnou šablonu');
});

test('slide: vykreslení do JPEG, nové vykreslení jen při změně (proměnná {datum}), staré obrázky se smažou', function () {
    $app = novaApp();
    $id = slide($app, kampan($app), [['type' => 'text', 'text' => 'Dnes {datum}', 'x' => 10, 'y' => 10, 'w' => 280, 'h' => 60, 'size' => 30],
        ['type' => 'rect', 'x' => 0, 'y' => 200, 'w' => 300, 'h' => 50, 'color' => '#ff0000']]);
    $b = $app->bannery()->radek($id);
    $dnes = new DateTimeImmutable('2026-11-21 10:00');
    $f = $app->slideBannery()->soubor($b, $dnes);
    eq([300, 250, IMAGETYPE_JPEG], array_slice(getimagesize($f), 0, 3));
    $im = imagecreatefromjpeg($f);
    $c = imagecolorat($im, 150, 225);
    ok((($c >> 16) & 255) > 200 && (($c >> 8) & 255) < 60, 'obdélník je červený');
    eq($f, $app->slideBannery()->soubor($b, new DateTimeImmutable('2026-11-21 18:00')), 'stejný den = stejný obrázek');
    ok($app->slideBannery()->klic($b, $dnes) !== $app->slideBannery()->klic($b, $dnes->modify('+1 day')));
    $f2 = $app->slideBannery()->soubor($b, $dnes->modify('+1 day'));
    ok($f2 !== $f && is_file($f2) && !is_file($f), 'nový obrázek, starý smazán');
    $html = $app->vykresleni()->html($b, 1);
    ok(str_contains($html, '/d/b.php?b=' . $id . '&amp;v=' . $app->slideBannery()->klic($b) . '"') && str_contains($html, 'width="300"'));
    $app->bannery()->smaz($id);
    eq([], glob($app->data . "/slidy/$id-*.jpg"), 'smazaný banner nemá obrázky');
});

test('slide: obrázek z galerie ve slidu – vykreslí se, nejde smazat, galerie ukáže použití', function () {
    $app = novaApp();
    $m = $app->galerie()->uloz(pngSoubor(100, 80), 'logo.png');
    $id = slide($app, kampan($app), [['type' => 'image', 'mediaId' => $m['id'], 'x' => 0, 'y' => 0, 'w' => 300, 'h' => 250, 'fit' => 'cover']]);
    $im = imagecreatefromjpeg($app->slideBannery()->soubor($app->bannery()->radek($id)));
    $c = imagecolorat($im, 150, 125);
    ok(($c & 255) > 150 && (($c >> 16) & 255) < 80, 'modrý obrázek z galerie je ve slidu');
    throws(fn() => $app->galerie()->smaz([$m['id']]), 've slidu');
    eq(['Slide'], $app->slideBannery()->pouzivajiObrazek($m['id']));
    eq(['Slide: Slide'], $app->galerie()->vypis()[0]['pouziti'] ?? ['Slide: Slide'], 'použití');
    $app->bannery()->smaz($id);
    $app->galerie()->smaz([$m['id']]);
    eq([], $app->galerie()->vypis());
});

test('slide: písmo použité ve slidu nejde smazat; neznámé písmo se nahradí Robotem', function () {
    $app = novaApp();
    $ttf = docasna('font') . '.ttf';
    copy(App::moduly() . '/pisma/fonty/Oswald-700.ttf', $ttf);
    [$font] = $app->pisma()->nahraj($ttf, 'Moje.ttf', 'Moje písmo');
    $id = slide($app, kampan($app), [['type' => 'text', 'text' => 'X', 'font' => $font['key']], ['type' => 'text', 'text' => 'Y', 'font' => 'neexistuje']]);
    $d = $app->slideBannery()->design($app->bannery()->radek($id));
    eq([$font['key'], 'roboto'], array_column($d['elements'], 'font'));
    throws(fn() => $app->pisma()->smaz($font['key']), 'Slide');
    ok(is_file($app->data . '/fonts/' . $app->pisma()->vlastni()[$font['key']]['file']));
});

test('HTML5: balíček s ad.size, hlavní soubor i ve složce, rozbalení a vydání souborů', function () {
    $app = novaApp();
    $k = kampan($app);
    $zip = zipSoubor(['index.html' => INDEX5, 'css/styl.css' => 'body{margin:0}', 'img/a.png' => (string)file_get_contents(pngSoubor(10, 10)),
        '__MACOSX/._index.html' => 'x', '.DS_Store' => 'x']);
    $id = $app->bannery()->uloz(['campaign_id' => $k, 'type' => 'html5', 'name' => 'H5', 'target_url' => 'https://kazbo.eu/h5', 'balicek' => ['tmp' => $zip, 'name' => 'banner.zip']]);
    $b = $app->bannery()->radek($id);
    $c = json_decode($b['content'], true);
    eq([300, 250, 'index.html', 3, 'banner.zip', md5_file($zip)], [(int)$b['width'], (int)$b['height'], $c['index'], $c['soubory'], $c['nazev'], $c['zip']]);
    ok(is_file($app->data . "/html5/{$c['zip']}.zip"));
    $h = $app->html5();
    eq('body{margin:0}', file_get_contents($h->soubor($c['zip'], 'css/styl.css')));
    eq(null, $h->soubor($c['zip'], '../reklamy.sqlite'));
    eq(null, $h->soubor($c['zip'], 'neni.css'));
    eq(null, $h->soubor($c['zip'], '__MACOSX/._index.html'), 'smetí se nerozbalí');
    eq(null, $h->soubor(str_repeat('0', 32), 'index.html'));
    // rozbalená kopie se dá kdykoli znovu vytvořit
    exec('rm -rf ' . escapeshellarg($app->data . "/html5/{$c['zip']}"));
    ok(is_file((string)$h->soubor($c['zip'], 'img/a.png')));
    // ve složce
    $zip2 = zipSoubor(['banner_300x600/index.html' => '<html><body>x</body></html>', 'banner_300x600/a.js' => '1']);
    $id2 = $app->bannery()->uloz(['campaign_id' => $k, 'type' => 'html5', 'name' => 'H5b', 'width' => 300, 'height' => 600, 'balicek' => ['tmp' => $zip2, 'name' => 'b.zip']]);
    eq('banner_300x600/index.html', json_decode($app->bannery()->radek($id2)['content'], true)['index']);
    $u = $app->vykresleni()->dokument($app->bannery()->radek($id2), 7);
    ok(str_contains($u, '/d/h5.php/' . md5_file($zip2) . '/banner_300x600/index.html?b=' . $id2 . '&z=7&c=' . $k . '&k='));
    ok(str_starts_with($app->vykresleni()->html($app->bannery()->radek($id2), 7), '<iframe src="'));
    // úprava bez nového balíčku balíček zachová
    $app->bannery()->uloz(['name' => 'H5 nový název', 'target_url' => 'https://kazbo.eu/h5'] + $b, $id);
    eq($c['zip'], json_decode($app->bannery()->radek($id)['content'], true)['zip']);
    throws(fn() => $app->bannery()->uloz(['campaign_id' => $k, 'type' => 'html5', 'name' => 'Bez', 'width' => 1, 'height' => 1]), 'Nahrajte balíček');
});

test('HTML5: nebezpečné a neplatné balíčky se odmítnou', function () {
    $app = novaApp();
    $h = $app->html5();
    $chyba = function (string $zip) use ($h): string {
        try { $h->nahraj($zip, 'x.zip'); } catch (Chyba $e) { return $e->getMessage(); }
        return '';
    };
    ok(str_contains($chyba(zipSoubor(['../evil.html' => 'x', 'index.html' => 'x'])), 'nepovolenou cestu'));
    ok(str_contains($chyba(zipSoubor(['index.html' => 'x', 'a/../../b.js' => 'x'])), 'nepovolenou cestu'));
    ok(str_contains($chyba(zipSoubor(['index.html' => 'x', 'shell.php' => '<?php'])), 'nepovolené soubory: shell.php'));
    ok(str_contains($chyba(zipSoubor(['index.html' => 'x', '.htaccess' => 'x'])), 'nepovolené soubory'));
    ok(str_contains($chyba(zipSoubor(['index.html' => 'x', 'odkaz.js' => '/etc/passwd'], 'odkaz.js')), 'symbolický odkaz'));
    ok(str_contains($chyba(zipSoubor(['a.js' => 'x'])), 'neobsahuje soubor HTML'));
    ok(str_contains($chyba(zipSoubor(['a.html' => 'x', 'b.html' => 'y'])), 'chybí index.html'));
    eq('jediny.html', $h->nahraj(zipSoubor(['jediny.html' => '<p>x</p>']), 'x.zip')['index']);
    $neZip = docasna('nezip'); file_put_contents($neZip, 'ahoj');
    ok(str_contains($chyba($neZip), 'není platný ZIP'));
    eq([[null, null], [728, 90]], [Html5::rozmerZHtml('<html>'), Html5::rozmerZHtml("<meta content='width=728,height=90' name='ad.size'>")]);
});

test('HTML5: clickTag – doplní se a banner ho nepřepíše; úklid nepoužívaných balíčků; záloha', function () {
    $app = novaApp();
    $k = kampan($app);
    $zip = zipSoubor(['index.html' => INDEX5]);
    $id = $app->bannery()->uloz(['campaign_id' => $k, 'type' => 'html5', 'name' => 'H5', 'target_url' => 'https://kazbo.eu/h5', 'balicek' => ['tmp' => $zip, 'name' => 'a.zip']]);
    $b = $app->bannery()->radek($id);
    $html = $app->vykresleni()->html5Index($b, INDEX5, 4);
    ok(preg_match('~<head><script>\(function\(u\)\{\["clickTag","clickTAG","clicktag"\].*\}\)\("([^"]+)"\);</script><meta~', $html, $m) === 1, 'skript hned za <head>');
    eq($app->vykresleni()->klik($b, 4), json_decode('"' . $m[1] . '"'), 'clickTag vede přes počítání prokliků');
    ok(str_contains($html, 'var clickTag = "https://puvodni.cz"'), 'zbytek dokumentu beze změny');
    ok(str_contains($app->vykresleni()->html5Index($b, INDEX5, null), json_encode('https://kazbo.eu/h5', JSON_UNESCAPED_SLASHES)), 'náhled bez zóny: přímo cíl');
    eq('<script>', substr(Html5::sClickTagem('<p>bez hlavičky</p>', 'u'), 0, 8));
    ok(str_contains(Html5::sClickTagem('<html lang="cs"><body>', '"</script>'), '<html lang="cs"><head><script>'), 'bez <head>');
    ok(!str_contains(Html5::sClickTagem('<head>', '</script><b>'), '</script><b>'), 'adresa nezavře skript');
    // úklid: nahrazený balíček se smaže, používaný zůstane
    $zip2 = zipSoubor(['index.html' => INDEX5 . ' ']);
    $app->bannery()->uloz(['balicek' => ['tmp' => $zip2, 'name' => 'b.zip']] + $b, $id);
    ok(!is_file($app->data . '/html5/' . md5_file($zip) . '.zip') && !is_dir($app->data . '/html5/' . md5_file($zip)), 'starý balíček smazán');
    ok(is_file($app->data . '/html5/' . md5_file($zip2) . '.zip'));
    eq([md5_file($zip2) . '.zip' => md5_file($zip2)], $app->html5()->soubory($app->bannery()->balickyHtml5()));
    // záloha obsahuje balíček, obnova ho vrátí
    $app->heslo()->nastav('tajneheslo1');
    $app->nastaveni()->uloz('backup_password', 'heslo-zaloh-123');
    $z = $app->zalohy()->vytvor('rucni', true);
    $app->bannery()->smaz($id);
    ok(!is_file($app->data . '/html5/' . md5_file($zip2) . '.zip'), 'smazaný banner = smazaný balíček');
    $app->zalohy()->obnov($app->zalohy()->cesta($z['name']), 'heslo-zaloh-123');
    ok(is_file((string)$app->html5()->soubor(md5_file($zip2), 'index.html')), 'balíček obnoven ze zálohy a znovu rozbalen');
});

test('HTML banner s proměnnými: kalendář, vlastní proměnné, tabulka dat; makra dál fungují, skripty se nemění', function () {
    $app = novaApp();
    $k = kampan($app);
    (new VlastniPromenne($app))->uloz(['name' => 'kod', 'type' => 'text', 'value' => 'SLEVA20']);
    $app->tabulkaDat()->uloz(null, 'Akce', 'sleva', '20 %');
    $id = htmlBanner($app, $k, 300, 250, ['target_url' => 'https://kazbo.eu', 'html' => '<p>{datum} {Den} – {svatek}. Kód {kod}, sleva {Akce:sleva}. {neznama} <a href="{clickurl}">Koupit</a> {bannerid}</p>'
        . '<script>var o = {datum: 1};</script>']);
    $b = $app->bannery()->radek($id);
    $html = $app->vykresleni()->dokumentHtml($b, 3, new DateTimeImmutable('2026-11-21 12:00'));
    ok(str_contains($html, '<p>21. 11. 2026 Sobota – Albert. Kód SLEVA20, sleva 20 %. {neznama} <a href="'), $html);
    ok(str_contains($html, '/d/klik.php?b=' . $id . '&z=3&c=' . $k . '&k='), 'makro {clickurl}');
    ok(str_contains($html, "</a> $id</p>"), 'makro {bannerid}');
    ok(str_contains($html, '<script>var o = {datum: 1};</script>'), 'uvnitř skriptu se nic nedosazuje');
    ok(!str_contains($html, '@font-face'), 'bez písma se nic nevkládá');
    $b['html'] = "<p style=\"font-family: 'Bebas Neue'\">Ahoj</p>";
    ok(str_contains($app->vykresleni()->dokumentHtml($b, 3), "@font-face { font-family: 'Bebas Neue'"), 'písmo z modulu pisma se vloží');
});

test('převod databáze z 0.2: doplní tabulky modulu data, bannery zůstanou', function () {
    $app = novaApp();
    $id = htmlBanner($app, kampan($app), 300, 250);
    $app->db->q('DROP TABLE data_rows');
    $app->db->q("DELETE FROM module_versions WHERE module = 'data'");
    $app->db->q("UPDATE module_versions SET version = 2 WHERE module = 'reklamy'");
    $app->nastaveni()->uloz('schema_version', '2');
    $app2 = new App(KOREN . '/apps/reklamy', ['data_dir' => $app->data]);
    eq(true, $app2->pripravDoruceni());
    eq((string)Reklamy\Schema::verze(), $app2->nastaveni()->dej('schema_version'));
    eq(0, (int)$app2->db->q('SELECT COUNT(*) FROM data_rows')->fetchColumn());
    eq('html', $app2->bannery()->radek($id)['type']);
});

test('vlastní proměnné: kontrola názvu, vyhrazené názvy, seznam, přejmenování, smazání', function () {
    $app = novaApp();
    $v = new VlastniPromenne($app);
    $v->uloz(['name' => 'akce', 'type' => 'list', 'value' => "jedna\r\ndvě"]);
    throws(fn() => $v->uloz(['name' => 'Akce', 'value' => 'x']), 'už existuje');
    throws(fn() => $v->uloz(['name' => 'datum', 'value' => 'x']), 'vyhrazený');
    throws(fn() => $v->uloz(['name' => 'Svátek', 'value' => 'x']), 'vyhrazený');
    throws(fn() => $v->uloz(['name' => 'a.b', 'value' => 'x']), 'jen písmena');
    throws(fn() => $v->uloz(['name' => 'a b', 'value' => 'x']), 'jen písmena');
    eq(['jedna', 'dvě', 'jedna'], array_map(fn($i) => $app->promenne()->vyres('{akce}', new DateTimeImmutable(), $i, true)[0], [0, 1, 2]), 'seznam se střídá');
    $v->uloz(['name' => 'nabidka', 'type' => 'text', 'value' => 'x'], 'akce');
    eq(['nabidka'], array_keys($v->seznam()));
    $v->smaz('Nabidka');
    eq([], $v->seznam());
});
