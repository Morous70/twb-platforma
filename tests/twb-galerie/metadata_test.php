<?php
declare(strict_types=1);
// TWB Media galerie 0.5: metadata obrázků (název, štítky, autor, licence), autor a licence u média, nahrání ZIPu s obrázky (web i API).
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

use MediaGalerie\{App, Media, Metadata};

echo "Media galerie: metadata a ZIP\n";

/** Metadata z obsahu souboru. */
function md(string $data): array
{
    $f = docasna('md');
    file_put_contents($f, $data);
    return Metadata::cti($f);
}

/** ZIP z položek jméno => obsah (jméno může být v jiném kódování než UTF-8). */
function zip(array $polozky): string
{
    $f = docasna('zip') . '.zip';
    $z = new ZipArchive();
    $z->open($f, ZipArchive::CREATE);
    foreach ($polozky as $jmeno => $obsah) $obsah === null ? $z->addEmptyDir((string)$jmeno) : $z->addFromString((string)$jmeno, $obsah);
    $z->close();
    return (string)file_get_contents($f);
}

const XMP_PLNE = '><dc:title><rdf:Alt><rdf:li xml:lang="cs">Hory</rdf:li><rdf:li xml:lang="x-default">Hory v mlze</rdf:li></rdf:Alt></dc:title>'
    . '<dc:subject><rdf:Bag><rdf:li>hory</rdf:li><rdf:li>mlha</rdf:li><rdf:li>HORY</rdf:li></rdf:Bag></dc:subject>'
    . '<dc:creator><rdf:Seq><rdf:li>Eva Malá</rdf:li><rdf:li>Petr Velký</rdf:li></rdf:Seq></dc:creator>'
    . '<dc:rights><rdf:Alt><rdf:li xml:lang="x-default">© 2026 Eva Malá</rdf:li></rdf:Alt></dc:rights>'
    . '<xmpRights:UsageTerms><rdf:Alt><rdf:li xml:lang="x-default">CC BY 4.0</rdf:li></rdf:Alt></xmpRights:UsageTerms>'
    . '<cc:license rdf:resource="https://creativecommons.org/licenses/by/4.0/"/>';

// ---------- čtení metadat ----------

test('XMP: název (x-default), štítky bez opakování, autoři, licence a odkaz na ni; zkrácený zápis v atributech', function () {
    eq(['nazev' => 'Hory v mlze', 'tagy' => ['hory', 'mlha'], 'autor' => 'Eva Malá, Petr Velký', 'licence' => 'CC BY 4.0',
        'licence_url' => 'https://creativecommons.org/licenses/by/4.0/'], md(jpegMeta([], XMP_PLNE)));
    $r = md(jpegMeta([], ' photoshop:Headline="Titulek" xmpRights:WebStatement="https://example.com/licence"><dc:rights><rdf:Alt><rdf:li xml:lang="x-default">Všechna práva vyhrazena</rdf:li></rdf:Alt></dc:rights>'));
    eq(['Titulek', 'Všechna práva vyhrazena', 'https://example.com/licence'], [$r['nazev'], $r['licence'], $r['licence_url']]);
    eq(null, md(jpegMeta([], ' xmpRights:WebStatement="javascript:alert(1)">'))['licence_url'], 'odkaz jen http(s)');
});

test('XMP s DOCTYPE (externí entity) se nečte', function () {
    $jpg = jpegMeta([], '><dc:title><rdf:Alt><rdf:li>&xxe;</rdf:li></rdf:Alt></dc:title>');
    $jpg = str_replace('<x:xmpmeta', '<!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><x:xmpmeta', $jpg);
    // DOCTYPE před <x:xmpmeta> se do čteného bloku nedostane; vložený dovnitř se odmítne
    $jpg2 = str_replace('<x:xmpmeta xmlns:x="adobe:ns:meta/">', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>', jpegMeta([], '><dc:title><rdf:Alt><rdf:li>&xxe;</rdf:li></rdf:Alt></dc:title>'));
    foreach ([$jpg, $jpg2] as $j) { $r = md($j); ok($r['nazev'] === null || !str_contains((string)$r['nazev'], 'root:'), 'bez obsahu souboru'); }
});

test('IPTC: UTF-8 i Windows-1250, název, klíčová slova, autor, copyright', function () {
    $r = md(jpegMeta(['1#090' => "\x1B%G", '2#005' => 'Západ slunce', '2#025' => ['příroda', 'Léto', 'léto'], '2#080' => 'Jan Novák', '2#116' => '© 2026 Jan Novák']));
    eq(['Západ slunce', ['příroda', 'Léto'], 'Jan Novák', '© 2026 Jan Novák'], [$r['nazev'], $r['tagy'], $r['autor'], $r['licence']]);
    $cp = fn(string $s) => iconv('UTF-8', 'Windows-1250', $s);
    $r = md(jpegMeta(['2#105' => $cp('Žluťoučký kůň'), '2#025' => [$cp('kůň')], '2#080' => $cp('Řehoř')]));
    eq(['Žluťoučký kůň', ['kůň'], 'Řehoř'], [$r['nazev'], $r['tagy'], $r['autor']]);
});

test('EXIF: Artist, Copyright, Windows Název a Klíčová slova (UTF-16)', function () {
    $r = md(jpegMeta([], null, [0x013B => exifText('Petr Foto'), 0x8298 => exifText('Všechna práva'), 0x9C9B => exifXp('Žluťoučký kůň'), 0x9C9E => exifXp('kůň;louka')]));
    eq(['nazev' => 'Žluťoučký kůň', 'tagy' => ['kůň', 'louka'], 'autor' => 'Petr Foto', 'licence' => 'Všechna práva', 'licence_url' => null], $r);
});

test('přednost: XMP před IPTC před EXIF; štítky ze všech zdrojů; bez metadat nic', function () {
    $r = md(jpegMeta(['2#005' => 'IPTC název', '2#025' => ['iptc'], '2#080' => 'IPTC autor'], '><dc:title><rdf:Alt><rdf:li xml:lang="x-default">XMP název</rdf:li></rdf:Alt></dc:title><dc:subject><rdf:Bag><rdf:li>xmp</rdf:li></rdf:Bag></dc:subject>',
        [0x013B => exifText('EXIF autor'), 0x9C9E => exifXp('exif')]));
    eq(['XMP název', ['xmp', 'iptc', 'exif'], 'IPTC autor'], [$r['nazev'], $r['tagy'], $r['autor']]);
    eq(['nazev' => null, 'tagy' => [], 'autor' => null, 'licence' => null, 'licence_url' => null], md(jpegMeta()));
    eq(['nazev' => null, 'tagy' => [], 'autor' => null, 'licence' => null, 'licence_url' => null], md('není obrázek'));
});

test('název z titulku a spojení tagů', function () {
    eq('Hory v mlze.jpg', Media::nazevZTitulku('Hory v mlze', 'jpg'));
    eq('a b c.png', Media::nazevZTitulku('a/b\\c', 'png'));
    eq('foto.jpg', Media::nazevZTitulku('foto.jpg', 'jpg'));
    eq('obrazek.jpg', Media::nazevZTitulku(' / ', 'jpg'));
    eq(180 + 4, mb_strlen(Media::nazevZTitulku(str_repeat('á', 300), 'jpg')));
    eq(['web', 'Hory', 'mlha'], Media::spojTagy(['web'], ['Hory', 'WEB', 'mlha', str_repeat('x', 31)]));
    eq(10, count(Media::spojTagy([], array_map(fn($i) => "t$i", range(1, 25)))));
});

// ---------- aplikace ----------

/** Nahraje obsah přes Media::nahraj jako po skončení nahrávání. */
function nahrajMd(App $app, string $nazev, string $obsah, array $tagy = [], ?int $nahradit = null, bool $pevny = false): array
{
    $f = docasna('up');
    file_put_contents($f, $obsah);
    return $app->media()->nahraj($f, $nazev, $tagy, null, $nahradit, $pevny);
}

test('nahrání obrázku: název, štítky, autor a licence z metadat; zadaný název má přednost; nahrazení údaje nemění', function () {
    $app = novaApp();
    $v = nahrajMd($app, 'IMG_0001.jpg', jpegMeta([], XMP_PLNE), ['web']);
    $r = $app->media()->radek($v['id']);
    eq(['Hory v mlze.jpg', ['web', 'hory', 'mlha'], 'Eva Malá, Petr Velký', 'CC BY 4.0', 'https://creativecommons.org/licenses/by/4.0/', 'IMG_0001.jpg'],
        [$r['original_name'], Media::tagy($r), $r['author'], $r['license'], $r['license_url'], $v['nazev'] === $r['original_name'] ? 'IMG_0001.jpg' : '?']);
    $v2 = nahrajMd($app, 'muj-nazev.jpg', jpegMeta([], XMP_PLNE), [], null, true);
    eq('muj-nazev.jpg', $app->media()->radek($v2['id'])['original_name']);
    $v3 = nahrajMd($app, 'bez-metadat.jpg', jpegMeta());
    $r3 = $app->media()->radek($v3['id']);
    eq(['bez-metadat.jpg', null, null], [$r3['original_name'], $r3['author'], $r3['license']]);
    $app->media()->nastavAutora($v['id'], 'Ručně', null, null);
    nahrajMd($app, 'jiny.jpg', jpegMeta(['2#080' => 'Někdo jiný']), [], $v['id']);
    eq('Ručně', $app->media()->radek($v['id'])['author'], 'nahrazení souboru autora nepřepíše');
    eq([$v['id']], array_map(fn($r) => (int)$r['id'], $app->media()->seznam(['q' => 'ručně'])['polozky']), 'hledání podle autora');
    throws(fn() => $app->media()->nastavAutora($v['id'], null, null, 'ftp://x'), 'http');
});

test('ZIP: obrázky z podsložek, jména v CP852, přeskočí jiné typy a systémové soubory, chybný obrázek nahlásí', function () {
    $app = novaApp();
    $k = $app->kategorie()->uloz(['name' => 'Fotky']);
    $f = docasna('zip');
    file_put_contents($f, zip([
        'fotky/' => null,
        'fotky/hory.jpg' => jpegMeta([], XMP_PLNE),
        iconv('UTF-8', 'CP852', 'fotky/léto.jpg') => jpegMeta(),
        'obrazek.png' => (function () { $im = imagecreatetruecolor(20, 20); ob_start(); imagepng($im); return (string)ob_get_clean(); })(),
        'popis.txt' => 'text', 'video.mp4' => mp4(100),
        '__MACOSX/fotky/._hory.jpg' => 'x', '.DS_Store' => 'x', 'Thumbs.db' => 'x',
        'rozbity.jpg' => 'není obrázek',
    ]));
    $r = $app->media()->nahrajZip($f, ['import'], $k);
    ok(!is_file($f), 'ZIP smazán');
    eq(3, count($r['media']), json_encode($r, JSON_UNESCAPED_UNICODE));
    eq(['popis.txt', 'video.mp4'], $r['preskoceno']);
    eq(1, count($r['chyby']));
    ok(str_starts_with($r['chyby'][0], 'rozbity.jpg: '), $r['chyby'][0]);
    $nazvy = $app->db->q('SELECT original_name FROM media ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    eq(['Hory v mlze.jpg', 'léto.jpg', 'obrazek.png'], $nazvy);
    eq([$k, $k, $k], array_map('intval', $app->db->q('SELECT category_id FROM media')->fetchAll(PDO::FETCH_COLUMN)));
    eq(['import', 'hory', 'mlha'], Media::tagy($app->media()->radek($r['media'][0])));
    eq([], glob($app->data . '/tmp/zip-*') ?: [], 'dočasné soubory uklizeny');
    $f = docasna('zip'); file_put_contents($f, 'neni zip');
    throws(fn() => $app->media()->nahrajZip($f), 'platný ZIP');
});

test('schéma 5: sloupce autor a licence', function () {
    $app = novaApp();
    $sl = array_column($app->db->q('PRAGMA table_info(media)')->fetchAll(), 'name');
    ok(!array_diff(['author', 'license', 'license_url'], $sl));
    eq(5, \MediaGalerie\Schema::verze());
});

// ---------- web a API ----------

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);
$app = new App(KOREN . '/apps/twb-galerie', ['data_dir' => $s->data . '/data', 'base_url' => $s->url]);
$klic = $app->apiKlice()->vytvor('Test', 'write');

test('galerie: nahrání ZIPu po částech; detail ukáže a uloží autora a licenci', function () use ($s, $b) {
    $b->jdi('index.php?p=galerie');
    $obsah = zip(['a.jpg' => jpegMeta([], XMP_PLNE), 'b.jpg' => jpegMeta(['2#080' => 'Jan']), 'c.txt' => 'x']);
    $uid = bin2hex(random_bytes(16));
    $cast = 3000; $celkem = (int)ceil(strlen($obsah) / $cast); $j = [];
    for ($i = 0; $i < $celkem; $i++) {
        $f = docasna('cast'); file_put_contents($f, substr($obsah, $i * $cast, $cast));
        $b->hlavicky = ['X-CSRF: ' . $b->csrf];
        $j = json_decode($b->jdi('nahrat.php', ['uid' => $uid, 'index' => $i, 'celkem' => $celkem, 'nazev' => 'fotky.zip', 'tagy' => 'dávka', 'cast' => new CURLFile($f)], csrf: false)['body'], true);
        ok($j['ok'], $j['error'] ?? '');
    }
    eq([true, 2, ['c.txt']], [$j['zip'], count($j['media']), $j['preskoceno']]);
    ok(str_contains($j['poznamka'], 'obrázků 2'), $j['poznamka']);
    $id = $j['media'][0];
    $r = $b->jdi("index.php?p=medium&id=$id");
    ok(str_contains($r['body'], 'Eva Malá, Petr Velký') && str_contains($r['body'], 'CC BY 4.0') && str_contains($r['body'], 'podmínky licence'), 'detail s autorem a licencí');
    $r = $b->odesli("index.php?p=medium&id=$id", ['akce' => 'ulozit', 'nazev' => 'Hory.jpg', 'kategorie' => '', 'tagy' => 'hory', 'note' => '', 'author' => 'Já', 'license' => 'Vlastní dílo', 'license_url' => 'neplatny']);
    ok(str_contains($r['body'], 'musí začínat http'), 'neplatný odkaz');
    eq('Eva Malá, Petr Velký', $s->db()->query("SELECT author FROM media WHERE id = $id")->fetchColumn(), 'chyba nic neuloží');
    $b->odesli("index.php?p=medium&id=$id", ['akce' => 'ulozit', 'nazev' => 'Hory.jpg', 'kategorie' => '', 'tagy' => 'hory', 'note' => '', 'author' => 'Já', 'license' => 'Vlastní dílo', 'license_url' => '']);
    eq(['Já', 'Vlastní dílo', null], array_values($s->db()->query("SELECT author, license, license_url FROM media WHERE id = $id")->fetch()));
    ok(str_contains($b->jdi('index.php?p=galerie')['body'], '.zip'), 'výběr souborů přijme ZIP');
});

test('API: ZIP, autor a licence v detailu, úprava a mazání autora', function () use ($s, $klic) {
    $f = docasna('api') . '.zip';
    file_put_contents($f, zip(['x.jpg' => jpegMeta(['2#005' => 'Z API', '2#116' => 'CC0']), 'y.txt' => 'y']));
    $ch = curl_init($s->url . '/api.php/v1/media');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $klic],
        CURLOPT_POSTFIELDS => ['soubor' => new CURLFile($f, 'application/zip', 'balik.zip'), 'tagy' => 'api']]);
    $j = json_decode((string)curl_exec($ch), true);
    eq(201, curl_getinfo($ch, CURLINFO_HTTP_CODE), json_encode($j, JSON_UNESCAPED_UNICODE));
    eq([true, 1, ['y.txt'], 'Z API.jpg', 'CC0', ['api']], [$j['zip'], count($j['media']), $j['preskoceno'], $j['media'][0]['nazev'], $j['media'][0]['licence'], $j['media'][0]['tagy']]);
    $id = $j['media'][0]['id'];
    $patch = function (array $telo) use ($s, $klic, $id): array {
        $ch = curl_init($s->url . "/api.php/v1/media/$id");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_POSTFIELDS => json_encode($telo),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $klic, 'Content-Type: application/json']]);
        $j = json_decode((string)curl_exec($ch), true);
        return [null, $j['medium'] ?? $j, curl_getinfo($ch, CURLINFO_HTTP_CODE)];
    };
    [, $r, $kod] = $patch(['autor' => 'Eva', 'licence_url' => 'https://example.com/l']);
    eq(200, $kod, json_encode($r, JSON_UNESCAPED_UNICODE));
    eq(['Eva', 'CC0', 'https://example.com/l'], [$r['autor'], $r['licence'], $r['licence_url']]);
    [, $r, $kod] = $patch(['licence_url' => 'mailto:x']);
    eq(400, $kod);
    [, $r, $kod] = $patch(['autor' => null, 'licence' => '']);
    eq([200, null, null, 'https://example.com/l'], [$kod, $r['autor'], $r['licence'], $r['licence_url']]);
});
