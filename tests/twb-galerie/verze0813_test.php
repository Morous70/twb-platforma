<?php
declare(strict_types=1);
// TWB Media galerie 0.8.13: úprava obrázku v detailu média – ořez (volný i v poměru stran s prohozením), otočení, překlopení; originál zůstává.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Schema};

echo "Media galerie: 0.8.13\n";

/** JPEG s barevnými polovinami (vlevo červená, vpravo modrá). */
function jpeg0813(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefilledrectangle($im, 0, 0, intdiv($w, 2) - 1, $h - 1, (int)imagecolorallocate($im, 255, 0, 0));
    imagefilledrectangle($im, intdiv($w, 2), 0, $w - 1, $h - 1, (int)imagecolorallocate($im, 0, 0, 255));
    ob_start(); imagejpeg($im, null, 95); return (string)ob_get_clean();
}

test('schéma 10: sloupec edits; galerie aplikace popis úprav ukládá', function () {
    $app = novaApp();
    ok(Schema::verze() >= 10);
    ok(in_array('edits', array_column($app->db->q('PRAGMA table_info(media)')->fetchAll(), 'name'), true));
    $app->nastaveni()->uloz('img_auto', '0');
    $f = docasna('f'); file_put_contents($f, jpeg0813(1600, 900));
    $id = (int)$app->media()->nahraj($f, 'Fotka.jpg')['id'];
    $app->galerie()->upravObrazek($id, TWB\Obrazky\Obrazky::upravaZFormulare(['otoceni' => '90', 'pomer' => '3:2']));
    $m = $app->media()->radek($id);
    eq([900, 600, 'otočeno o 90° doprava, oříznuto 3:2 (900×600 px)'], [(int)$m['width'], (int)$m['height'], $m['edits']]); // 900×1600 → výřez 3:2 na celou šířku
});

test('0.8.13 na serveru: editor v detailu, uložení úpravy, nové md5 a náhledy, stejný veřejný odkaz, návrat k originálu, API', function () {
    $s = new TestServer();
    register_shutdown_function(fn() => $s->zastav());
    $b = new Prohlizec($s);
    $b->jdi('install.php');
    $b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
    $app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
    $app->nastaveni()->uloz('img_auto', '0');
    $f = docasna('f'); file_put_contents($f, jpeg0813(1600, 900));
    $id = (int)$app->media()->nahraj($f, 'Krajina.jpg')['id'];
    $pred = $app->media()->radek($id);
    $verejna = $app->media()->verejnaAdresa($pred);

    $r = $b->jdi("index.php?p=medium&id=$id");
    ok(str_contains($r['body'], 'data-otevrit-upravu>Upravit obrázek</a>') && str_contains($r['body'], 'id="uprava" data-uprava'), 'tlačítko a editor');
    foreach (['1:1', '16:9', '4:3', '3:2', '2:1'] as $p) ok(str_contains($r['body'], 'data-pomer="' . $p . '"'), "poměr $p");
    ok(str_contains($r['body'], 'data-prohodit') && str_contains($r['body'], 'data-uprava-akce="vlevo"') && str_contains($r['body'], 'data-uprava-akce="svisle"'));
    ok(!str_contains($r['body'], 'target="_blank"') || substr_count($r['body'], 'target="_blank"') === substr_count($b->jdi("index.php?p=prehled")['body'], 'target="_blank"'), 'editor nic neotevírá v nové kartě');
    $nahled = $b->jdi("media.php?id=$id&nahled=1");
    eq(200, $nahled['code']);

    // ořez 16:9 z levé poloviny (červená), překlopení vodorovně
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'upravit', 'otoceni' => '0', 'preklopit_h' => '1', 'preklopit_v' => '',
        'orez_x' => '0.5', 'orez_y' => '0', 'orez_w' => '0.5', 'orez_h' => '1', 'pomer' => '16:9']);
    ok(str_contains($r['body'], 'Obrázek upraven: překlopeno vodorovně, oříznuto 16:9 (800×450 px)'), mb_substr(strip_tags($r['body']), 0, 400));
    $m = $app->media()->radek($id);
    eq([800, 450, $pred['filename'], 1600, 900], [(int)$m['width'], (int)$m['height'], $m['orig_filename'], (int)$m['orig_w'], (int)$m['orig_h']]);
    ok($m['md5'] !== $pred['md5']);
    ok(str_contains($r['body'], '<dt>Upraveno</dt><dd>překlopeno vodorovně, oříznuto 16:9 (800×450 px)</dd>'));
    ok(str_contains($r['body'], 'media.php?id=' . $id . '&amp;v=' . substr((string)$m['md5'], 0, 8)), 'nová adresa s ?v=');
    ok(str_contains($r['body'], 'Úpravy (ořez, otočení) i komprese se zahodí'), 'návrat s upozorněním');
    $im = imagecreatefromstring($b->jdi("media.php?id=$id")['body']);
    eq([800, 450], [imagesx($im), imagesy($im)]);
    $c = imagecolorat($im, 400, 225);
    ok((($c >> 16) & 255) > 200 && ($c & 255) < 60, 'levá (červená) polovina, uprostřed výřezu červená');
    eq($verejna, $app->media()->verejnaAdresa($m), 'veřejný odkaz stejný');
    $soubor = $b->jdi(substr($verejna, strlen($s->url) + 1));
    eq(200, $soubor['code']);
    eq($m['md5'], md5($soubor['body']), 'veřejný odkaz vydá upravenou verzi');
    eq([], glob($s->data . '/data/nahledy/' . $id . '-' . $pred['md5'] . '-*') ?: [], 'staré náhledy uklizeny');
    // API vrací popis úprav
    $detail = (new MediaGalerie\Api($app))->medium($m);
    eq('překlopeno vodorovně, oříznuto 16:9 (800×450 px)', $detail['upraveno']);

    // chyby: prázdná úprava a nesmysl
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'upravit', 'otoceni' => '0']);
    ok(str_contains($r['body'], 'Úprava nic nemění'));
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'upravit', 'otoceni' => '33']);
    ok(str_contains($r['body'], 'Otočit jde jen o 90°'));
    eq($m['md5'], $app->media()->radek($id)['md5'], 'nic se nezměnilo');

    // druhá úprava se skládá, originál zůstává
    $b->odesli("index.php?p=medium&id=$id", ['akce' => 'upravit', 'otoceni' => '270']);
    $m2 = $app->media()->radek($id);
    eq([450, 800, $pred['filename']], [(int)$m2['width'], (int)$m2['height'], $m2['orig_filename']]);
    eq('překlopeno vodorovně, oříznuto 16:9 (800×450 px); otočeno o 90° doleva', $m2['edits']);

    // návrat k originálu
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'original']);
    ok(str_contains($r['body'], 'Vrácen původní soubor'));
    $m3 = $app->media()->radek($id);
    eq([$pred['filename'], $pred['md5'], 1600, 900, null, null], [$m3['filename'], $m3['md5'], (int)$m3['width'], (int)$m3['height'], $m3['orig_filename'], $m3['edits']]);
    ok(!str_contains($r['body'], '<dt>Upraveno</dt>'));

    // PDF a animovaný GIF editor nenabídnou
    $pdf = docasna('p'); file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $idPdf = (int)$app->media()->nahraj($pdf, 'a.pdf')['id'];
    ok(!str_contains($b->jdi("index.php?p=medium&id=$idPdf")['body'], 'data-uprava'));
    $snimek = "\x21\xF9\x04\x00\x0A\x00\x00\x00" . "\x2C\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02\x44\x01\x00";
    $gif = docasna('g'); file_put_contents($gif, "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xFF\xFF\xFF" . $snimek . $snimek . "\x3B");
    $idGif = (int)$app->media()->nahraj($gif, 'blik.gif')['id'];
    ok(!str_contains($b->jdi("index.php?p=medium&id=$idGif")['body'], 'data-uprava'), 'animovaný GIF bez editoru');
    $r = $b->odesli("index.php?p=medium&id=$idGif", ['akce' => 'upravit', 'otoceni' => '90']);
    ok(str_contains($r['body'], 'Animovaný GIF upravit nejde'));
});
