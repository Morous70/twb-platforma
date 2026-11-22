<?php
declare(strict_types=1);
// TWB Media galerie 0.2 na webovém serveru: nahrávání po částech, veřejný odkaz (Range, 304, CORS), detail, kampaně, hromadné akce.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

echo "Media galerie: galerie na webu\n";
$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);


function obrazek(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 20, 120, 220));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}

/** Nahraje soubor přes nahrat.php po částech; vrací poslední odpověď. @param array<string, string> $navic */
function nahrajWeb(Prohlizec $b, string $nazev, string $obsah, int $cast = 1000, array $navic = []): array
{
    $uid = bin2hex(random_bytes(16));
    $celkem = max(1, (int)ceil(strlen($obsah) / $cast));
    $j = [];
    for ($i = 0; $i < $celkem; $i++) {
        $f = docasna('cast');
        file_put_contents($f, substr($obsah, $i * $cast, $cast));
        $b->hlavicky = ['X-CSRF: ' . $b->csrf];
        $r = $b->jdi('nahrat.php', ['uid' => $uid, 'index' => $i, 'celkem' => $celkem, 'nazev' => $nazev, 'cast' => new CURLFile($f)] + $navic, csrf: false);
        $j = json_decode($r['body'], true) + ['_code' => $r['code']];
        if (!$j['ok']) return $j;
        eq($i === $celkem - 1, $j['hotovo'], "část $i");
    }
    return $j;
}

test('nahrávání: jen přihlášený a s CSRF', function () use ($s, $b) {
    $b->jdi('index.php?p=galerie');
    $anon = new Prohlizec($s);
    eq(401, $anon->jdi('nahrat.php', ['x' => 1], csrf: false)['code']);
    eq(405, $b->jdi('nahrat.php')['code']);
    $f = docasna('x'); file_put_contents($f, 'x');
    $b->hlavicky = ['X-CSRF: spatne'];
    eq(403, $b->jdi('nahrat.php', ['uid' => str_repeat('a', 32), 'index' => 0, 'celkem' => 1, 'nazev' => 'a.png', 'cast' => new CURLFile($f)], csrf: false)['code']);
});

test('nahrávání po částech: obrázek do kampaně s tagy, video, nepovolený typ', function () use ($s, $b) {
    $b->jdi('index.php?p=kampane');
    $b->odesli('index.php?p=kampane', ['akce' => 'ulozit', 'name' => 'Léto']);
    $k = (int)$s->db()->query("SELECT id FROM campaigns WHERE name = 'Léto'")->fetchColumn();
    ok($k > 0);
    $b->jdi('index.php?p=galerie');
    $png = obrazek(1200, 800);
    $j = nahrajWeb($b, 'plakat.png', $png, 4000, ['kampan' => (string)$k, 'tagy' => 'Plakát, léto']);
    ok($j['ok'], $j['error'] ?? '');
    $r = $s->db()->query('SELECT * FROM media WHERE id = ' . (int)$j['id'])->fetch();
    eq([(string)$k, '["Plakát","léto"]', md5($png)], [(string)$r['campaign_id'], $r['tags'], $r['md5']]);
    $video = mp4(25000);
    $j = nahrajWeb($b, 'spot.mp4', $video, 10000);
    ok($j['ok'], $j['error'] ?? '');
    eq('video', $s->db()->query('SELECT kind FROM media WHERE id = ' . (int)$j['id'])->fetchColumn());
    $j = nahrajWeb($b, 'skript.php', '<?php echo 1;');
    eq([false, 400], [$j['ok'], $j['_code']]);
    ok(str_contains($j['error'], 'Nepodporovaný typ'));
    $j = nahrajWeb($b, 'falesny.pdf', 'není pdf');
    ok(str_contains($j['error'], 'není platné PDF'));
    eq(2, (int)$s->db()->query('SELECT COUNT(*) FROM media')->fetchColumn());
    eq([], glob($s->data . '/data/tmp/*.part') ?: [], 'po nahrání nezůstaly části');
});

test('veřejný odkaz: bez přihlášení, Range, 304, CORS, vypnutí a nový odkaz', function () use ($s, $b) {
    $v = $s->db()->query("SELECT * FROM media WHERE original_name = 'spot.mp4'")->fetch();
    $anon = new Prohlizec($s);
    $url = 'soubor.php/' . $v['public_key'] . '/spot.mp4';
    $r = $anon->jdi($url);
    eq([200, 'video/mp4', 25000], [$r['code'], $r['type'], strlen($r['body'])]);
    eq(md5_file($s->data . '/data/media/' . $v['filename']), md5($r['body']));
    ok(str_contains($anon->posledniHlavicky, 'Access-Control-Allow-Origin: *'));
    ok(str_contains($anon->posledniHlavicky, 'sandbox') && str_contains($anon->posledniHlavicky, 'nosniff'));
    $anon->hlavicky = ['Range: bytes=100-199'];
    $r = $anon->jdi($url);
    eq([206, 100], [$r['code'], strlen($r['body'])]);
    ok(str_contains($anon->posledniHlavicky, 'Content-Range: bytes 100-199/25000'));
    $anon->hlavicky = ['Range: bytes=99999-'];
    eq(416, $anon->jdi($url)['code']);
    $anon->hlavicky = ['If-None-Match: "' . $v['md5'] . '"'];
    eq(304, $anon->jdi($url)['code']);
    eq(200, $anon->jdi('soubor.php?k=' . $v['public_key'])['code'], 'i s parametrem k');
    eq(404, $anon->jdi('soubor.php/' . str_repeat('0', 24) . '/x.mp4')['code']);
    eq(404, $anon->jdi('soubor.php/../config.php')['code']);
    eq(403, $anon->jdi('media.php?id=' . $v['id'])['code'], 'administrace jen přihlášenému');
    // vypnutí a nový odkaz z detailu
    $b->jdi('index.php?p=medium&id=' . $v['id']);
    $b->odesli('index.php?p=medium&id=' . $v['id'], ['akce' => 'odkaz-zrusit']);
    eq(404, $anon->jdi($url)['code']);
    $r = $b->odesli('index.php?p=medium&id=' . $v['id'], ['akce' => 'odkaz-novy']);
    ok(str_contains($r['body'], 'Předchozí odkaz už nefunguje'));
    $klic = $s->db()->query('SELECT public_key FROM media WHERE id = ' . (int)$v['id'])->fetchColumn();
    ok(str_contains($r['body'], 'soubor.php/' . $klic . '/spot.mp4'), 'nový odkaz na stránce');
    eq(200, $anon->jdi('soubor.php/' . $klic . '/cokoli')['code']);
});

test('administrace: náhled, originál, stažení, náhled velkého obrázku je menší', function () use ($s, $b) {
    $p = $s->db()->query("SELECT * FROM media WHERE original_name = 'plakat.png'")->fetch();
    $r = $b->jdi('media.php?id=' . $p['id']);
    eq(['image/png', $p['md5']], [$r['type'], md5($r['body'])]);
    $r = $b->jdi('media.php?id=' . $p['id'] . '&nahled=1');
    eq('image/jpeg', $r['type']);
    eq([480, 320], array_slice(getimagesizefromstring($r['body']), 0, 2));
    $b->jdi('media.php?id=' . $p['id'] . '&stahnout=1');
    ok(str_contains($b->posledniHlavicky, 'attachment; filename="plakat.png"'));
    eq(404, $b->jdi('media.php?id=9999')['code']);
});

test('detail: údaje, kampaň, tagy, poznámka, komprese a návrat originálu, nahrazení souboru', function () use ($s, $b) {
    $id = (int)$s->db()->query("SELECT id FROM media WHERE original_name = 'plakat.png'")->fetchColumn();
    $klic = $s->db()->query("SELECT public_key FROM media WHERE id = $id")->fetchColumn();
    $r = $b->jdi("index.php?p=medium&id=$id");
    ok(str_contains($r['body'], '<h1>plakat.png') && str_contains($r['body'], '1200×800 px'));
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'ulozit', 'nazev' => 'Plakát léto', 'kampan' => '', 'tagy' => 'a, b, A', 'note' => 'tisk A3']);
    ok(str_contains($r['body'], 'Uloženo'), strip_tags($r['body']));
    $m = $s->db()->query("SELECT * FROM media WHERE id = $id")->fetch();
    eq(['Plakát léto.png', null, '["a","b"]', 'tisk A3'], [$m['original_name'], $m['campaign_id'], $m['tags'], $m['note']]);
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'ulozit', 'nazev' => 'X', 'tagy' => implode(',', range(1, 11))]);
    ok(str_contains($r['body'], 'nejvýš 10 tagů'));
    // komprese s malým limitem
    $s->db()->exec("INSERT OR REPLACE INTO settings (k, v) VALUES ('img_max_w', '600'), ('img_max_h', '600'), ('img_min_kb', '0')");
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'komprimovat']);
    ok(str_contains($r['body'], 'Zkomprimováno'), strip_tags($r['body']));
    $m = $s->db()->query("SELECT * FROM media WHERE id = $id")->fetch();
    eq([600, 400, 1200], [(int)$m['width'], (int)$m['height'], (int)$m['orig_w']]);
    ok(str_contains($b->jdi("index.php?p=medium&id=$id")['body'], 'Vrátit původní soubor'));
    $b->odesli("index.php?p=medium&id=$id", ['akce' => 'original']);
    eq(1200, (int)$s->db()->query("SELECT width FROM media WHERE id = $id")->fetchColumn());
    // nahrazení: stejný druh, odkaz a údaje zůstanou
    $j = nahrajWeb($b, 'novy.png', obrazek(300, 300), 5000, ['nahradit' => (string)$id]);
    ok($j['ok'] && $j['nahrazeno'], $j['error'] ?? '');
    $m = $s->db()->query("SELECT * FROM media WHERE id = $id")->fetch();
    eq([300, $klic, 'tisk A3', 'Plakát léto.png'], [(int)$m['width'], $m['public_key'], $m['note'], $m['original_name']]);
    $j = nahrajWeb($b, 'video.mp4', mp4(100), 5000, ['nahradit' => (string)$id]);
    ok(str_contains((string)($j['error'] ?? ''), 'stejného druhu'));
    eq(404, $b->jdi('index.php?p=medium&id=9999')['code']);
});

test('galerie: filtry a stránky, hromadné akce (kampaň, tagy, archiv, smazání)', function () use ($s, $b) {
    for ($i = 1; $i <= 3; $i++) ok(nahrajWeb($b, "obr$i.png", obrazek(50 + $i, 50))['ok']);
    $r = $b->jdi('index.php?p=galerie');
    ok(str_contains($r['body'], 'obr3.png') && str_contains($r['body'], 'spot.mp4') && str_contains($r['body'], 'Kopírovat odkaz'));
    ok(str_contains($b->jdi('index.php?p=galerie&druh=video')['body'], 'spot.mp4'));
    ok(!str_contains($b->jdi('index.php?p=galerie&druh=video')['body'], 'obr3.png'));
    ok(str_contains($b->jdi('index.php?p=galerie&q=nic-takoveho')['body'], 'Filtru nic neodpovídá'));
    $ids = array_map('intval', $s->db()->query("SELECT id FROM media WHERE original_name LIKE 'obr%' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $k = (int)$s->db()->query("SELECT id FROM campaigns WHERE name = 'Léto'")->fetchColumn();
    $b->jdi('index.php?p=galerie');
    $r = $b->odesli('index.php?p=galerie&druh=image', ['akce' => 'kampan', 'kampan' => (string)$k, 'ids' => $ids]);
    ok(str_contains($r['body'], 'Kampaň změněna u 3 médií'));
    ok(str_contains($r['body'], 'name="druh"') && str_contains($r['body'], '<option value="image" selected'), 'filtr zůstal');
    eq(3, (int)$s->db()->query("SELECT COUNT(*) FROM media WHERE campaign_id = $k")->fetchColumn());
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'tag-pridat', 'tag' => 'Výběr', 'ids' => $ids]);
    ok(str_contains($r['body'], 'Tag přidán u 3 médií'));
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'tag-odebrat', 'tag' => 'výběr', 'ids' => [$ids[0]]]);
    ok(str_contains($r['body'], 'Tag odebrán u 1 médií'));
    ok(str_contains($b->jdi('index.php?p=galerie&tag=V%C3%BDb%C4%9Br')['body'], 'obr2.png'));
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'archivovat', 'ids' => [$ids[1]]]);
    ok(str_contains($r['body'], 'Do archivu přesunuto: 1'));
    ok(!str_contains($r['body'], 'obr2.png'));
    ok(str_contains($b->jdi('index.php?p=galerie&archiv=1')['body'], 'obr2.png'));
    $klic = $s->db()->query('SELECT public_key FROM media WHERE id = ' . $ids[1])->fetchColumn();
    eq(200, (new Prohlizec($s))->jdi("soubor.php/$klic/x")['code'], 'archivované médium má odkaz dál');
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'smazat', 'ids' => [$ids[2]]]);
    ok(str_contains($r['body'], 'Smazáno: 1'));
    $r = $b->odesli('index.php?p=galerie', ['akce' => 'smazat']);
    ok(str_contains($r['body'], 'Vyberte aspoň jedno'));
    $r = $b->jdi('index.php?p=galerie&kampan=' . $k);
    ok(str_contains($r['body'], 'obr1.png') && !str_contains($r['body'], 'spot.mp4'));
});

test('kampaně: přehled, úprava, smazání nechá média', function () use ($s, $b) {
    $k = (int)$s->db()->query("SELECT id FROM campaigns WHERE name = 'Léto'")->fetchColumn();
    $r = $b->jdi('index.php?p=kampane');
    ok(str_contains($r['body'], 'Léto'));
    $b->jdi("index.php?p=kampane&upravit=$k");
    $r = $b->odesli("index.php?p=kampane&upravit=$k", ['akce' => 'ulozit', 'name' => 'Léto 2027', 'note' => 'x']);
    ok(str_contains($r['body'], 'Léto 2027'));
    $n = (int)$s->db()->query("SELECT COUNT(*) FROM media WHERE campaign_id = $k")->fetchColumn();
    $r = $b->odesli('index.php?p=kampane', ['akce' => 'smazat', 'id' => $k]);
    ok(str_contains($r['body'], "{$n} médií zůstalo"));
    eq(0, (int)$s->db()->query("SELECT COUNT(*) FROM media WHERE campaign_id = $k")->fetchColumn());
    eq(404, $b->jdi('index.php?p=kampane&upravit=999')['code']);
});

test('přehled a nastavení komprese', function () use ($s, $b) {
    $r = $b->jdi('index.php');
    ok(str_contains($r['body'], 'Naposledy nahrané'));
    $b->jdi('index.php?p=nastaveni');
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'komprese', 'img_auto' => '1', 'img_max_w' => '100', 'img_max_h' => '1080', 'img_quality' => '80', 'img_min_kb' => '0']);
    ok(str_contains($r['body'], 'musí být od 320'));
    $r = $b->odesli('index.php?p=nastaveni', ['akce' => 'komprese', 'img_auto' => '1', 'img_max_w' => '400', 'img_max_h' => '400', 'img_quality' => '80', 'img_min_kb' => '0']);
    ok(str_contains($r['body'], 'Uloženo'));
    $j = nahrajWeb($b, 'auto.png', obrazek(900, 450), 50000);
    ok($j['ok'] && str_contains((string)$j['poznamka'], '900×450 → 400×200'), json_encode($j, JSON_UNESCAPED_UNICODE));
});
