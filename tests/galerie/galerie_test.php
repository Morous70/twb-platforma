<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\{Host, Migrace as JadroMigrace};
use TWB\Galerie\{Galerie, Sloupce, Nahravani, Migrace};

function galerie(array $nastaveni = [], ?callable $priSmazani = null, ?callable $pouziti = null): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('galerie', Migrace::class);
    foreach ($nastaveni as $k => $v) $h->nastaveni->uloz($k, $v);
    $g = new Galerie($h, $h->datovaSlozka . '/media', priSmazani: $priSmazani, pouziti: $pouziti, cas: fn() => 1_800_000_000);
    return [$h, $g];
}

/** Soubor ke „nahrání“ (vždy nový dočasný). Obrázek s šumem, aby se špatně komprimoval. */
function obrazek(int $w, int $h, string $typ = 'jpg'): string
{
    $im = imagecreatetruecolor($w, $h);
    for ($i = 0; $i < 400; $i++) imagefilledrectangle($im, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), random_int(0, 0xFFFFFF));
    $p = sys_get_temp_dir() . '/twb-up-' . bin2hex(random_bytes(4)) . ".$typ";
    $typ === 'png' ? imagepng($im, $p) : imagejpeg($im, $p, 100);
    return $p;
}
function pdf(): string { $p = sys_get_temp_dir() . '/twb-up-' . bin2hex(random_bytes(4)) . '.pdf'; file_put_contents($p, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n"); return $p; }
function text(string $obsah, string $ext): string { $p = sys_get_temp_dir() . '/twb-up-' . bin2hex(random_bytes(4)) . ".$ext"; file_put_contents($p, $obsah); return $p; }
function soubory(string $slozka): array { return array_map('basename', glob("$slozka/*") ?: []); }

test('nahraný obrázek se uloží s rozměry, druhem a náhodným názvem souboru', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $r = $g->uloz(obrazek(800, 600), 'Můj banner.jpg');
    $m = $g->radek($r['id']);
    eq('image', $m['druh']); eq('image/jpeg', $m['mime']); eq(800, (int)$m['sirka']); eq(600, (int)$m['vyska']);
    eq('Můj banner.jpg', $m['nazev']);
    ok(preg_match('/^[a-f0-9]{24}\.jpg$/', $m['soubor']) === 1);
    eq(md5_file($h->datovaSlozka . '/media/' . $m['soubor']), $m['md5']);
    eq('2027-01-15 08:00:00', $m['vytvoreno']);
});
test('nepovolená přípona, podvržený obsah a falešné PDF se odmítnou a dočasný soubor zmizí', function () {
    [$h, $g] = galerie();
    foreach ([[text('<?php echo 1;', 'php'), 'x.php', 'Nepodporovaný'], [text('jen text', 'jpg'), 'x.jpg', 'není obrázek'], [text('jen text', 'pdf'), 'x.pdf', 'není platné PDF'], [text('jen text', 'txt'), 'bez-pripony', 'Nepodporovaný typ souboru bez přípony (obsah: text/plain)']] as [$p, $n, $msg]) {
        throws(fn() => $g->uloz($p, $n), $msg);
        ok(!is_file($p), 'dočasný soubor smazán');
    }
    eq([], soubory($h->datovaSlozka . '/media'));
    eq(0, (int)$h->db->q('SELECT COUNT(*) FROM media')->fetchColumn());
});
test('název bez přípony (jen titulek): přípona se doplní podle obsahu, jen z povolených typů', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $m = $g->radek($g->uloz(obrazek(120, 80), 'Čelní pohled 30°')['id']);
    eq(['Čelní pohled 30°.jpg', 'image/jpeg', 'image', 120], [$m['nazev'], $m['mime'], $m['druh'], (int)$m['sirka']]);
    ok(str_ends_with($m['soubor'], '.jpg'));
    $m = $g->radek($g->uloz(obrazek(50, 40, 'png'), 'Boční pohled v1.5')['id']);
    eq(['Boční pohled v1.5.png', 'image/png'], [$m['nazev'], $m['mime']]);
    eq('Sken.pdf', $g->radek($g->uloz(pdf(), 'Sken.')['id'])['nazev']);
    // podvržený obsah se bez přípony nepřijme a chyba řekne, co soubor obsahuje
    throws(fn() => $g->uloz(text('<?php echo 1;', 'bin'), 'skript'), 'obsah: text/x-php');
    throws(fn() => $g->uloz(text('<?php echo 1;', 'bin'), 'x.php'), 'Nepodporovaný typ souboru .php (obsah: text/x-php)');
    eq(3, (int)$h->db->q('SELECT COUNT(*) FROM media')->fetchColumn());
});
test('typ podle obsahu: přípona pro zjištěný typ a rozpoznání podle prvních bajtů', function () {
    [, $g] = galerie();
    eq('jpg', $g->priponaProMime('image/jpeg')); eq('bmp', $g->priponaProMime('image/x-ms-bmp')); eq('mp4', $g->priponaProMime('video/mp4'));
    eq(null, $g->priponaProMime('image/svg+xml')); eq(null, $g->priponaProMime('application/zip')); eq(null, $g->priponaProMime(null));
    eq('a.jpg', $g->nazevSPriponou('a.jpg', 'image/png'), 'povolená přípona se nemění');
    eq('a.exe', $g->nazevSPriponou('a.exe', 'application/x-dosexec'));
    eq('image/png', Galerie::zjistiMime(obrazek(10, 10, 'png')));
    eq('application/pdf', Galerie::zjistiMime(pdf()));
});
test('lomítka v názvu se nahradí; PDF a video se uloží jako svůj druh', function () {
    [$h, $g] = galerie();
    $id = $g->uloz(pdf(), '../../etc/zprava.pdf')['id'];
    $m = $g->radek($id);
    eq('pdf', $m['druh']); eq('.._.._etc_zprava.pdf', $m['nazev']);
    ok(str_ends_with($m['soubor'], '.pdf') && !str_contains($m['soubor'], '/'));
});
test('velký obrázek se při nahrání zmenší, originál zůstane a jde ho vrátit', function () {
    [$h, $g] = galerie(['img_max_w' => '1000', 'img_max_h' => '1000', 'img_min_kb' => '100000']);
    $r = $g->uloz(obrazek(3000, 2000), 'foto.jpg');
    ok($r['poznamka'] !== null && str_contains($r['poznamka'], '3000×2000 → 1000×667'), (string)$r['poznamka']);
    $m = $g->radek($r['id']);
    eq(1000, (int)$m['sirka']); eq(3000, (int)$m['pvSirka']);
    ok($m['pvSoubor'] !== null && $m['soubor'] !== $m['pvSoubor']);
    eq(2, count(soubory($h->datovaSlozka . '/media')));
    ok($g->vypis()[0]['komprimovano']['orig_w'] === 3000);
    ok($g->obnovPuvodni($r['id']));
    $m = $g->radek($r['id']);
    eq(3000, (int)$m['sirka']); eq(null, $m['pvSoubor']);
    eq(1, count(soubory($h->datovaSlozka . '/media')), 'zmenšená verze se smaže');
    eq(false, $g->obnovPuvodni($r['id']), 'podruhé není co vracet');
});
test('opakovaná komprese vychází z originálu a nenechává sirotky', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $id = $g->uloz(obrazek(3000, 2000), 'foto.jpg')['id'];
    ok($g->komprimuj($id, true) !== null);
    $h->nastaveni->uloz('img_max_w', '800'); $h->nastaveni->uloz('img_max_h', '800');
    ok($g->komprimuj($id, true) !== null);
    $m = $g->radek($id);
    eq(800, (int)$m['sirka']); eq(3000, (int)$m['pvSirka']);
    eq(2, count(soubory($h->datovaSlozka . '/media')), 'originál + jedna zmenšená');
    $h->nastaveni->uloz('img_max_w', '2000'); $h->nastaveni->uloz('img_max_h', '2000');
    $g->komprimuj($id, true);
    eq(2000, (int)$g->radek($id)['sirka'], 'zvětšení limitu se projeví jen při kompresi z originálu');
    eq(null, $g->komprimuj($g->uloz(pdf(), 'a.pdf')['id'], true), 'PDF se nekomprimuje');
});
test('animovaný GIF se nekomprimuje', function () {
    [$h, $g] = galerie(['img_max_w' => '320', 'img_max_h' => '240']);
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');   // 1×1, jeden snímek
    $p = text($gif, 'gif');
    $r = $g->uloz($p, 'a.gif');
    eq(null, $r['poznamka']);
});
test('nahrazení souboru zachová položku a smaže staré soubory včetně originálu', function () {
    [$h, $g] = galerie(['img_max_w' => '1000', 'img_max_h' => '1000', 'img_min_kb' => '100000']);
    $id = $g->uloz(obrazek(3000, 2000), 'foto.jpg')['id'];
    eq(2, count(soubory($h->datovaSlozka . '/media')));
    $r = $g->uloz(obrazek(500, 400, 'png'), 'nova.png', [], $id);
    eq($id, $r['id']); ok($r['nahrazeno']);
    $m = $g->radek($id);
    eq('nova.png', $m['nazev'], 'nepřejmenované médium přebere název nového souboru');
    eq(500, (int)$m['sirka']); eq(null, $m['pvSoubor']);
    eq(1, count(soubory($h->datovaSlozka . '/media')));
    eq(1, (int)$h->db->q('SELECT COUNT(*) FROM media')->fetchColumn());
});
test('nahrazení: přejmenované médium si ponechá své jméno s novou příponou; jiný druh se odmítne', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $id = $g->uloz(obrazek(300, 300), 'foto.jpg')['id'];
    $g->prejmenuj($id, 'Hlavní banner');
    $g->uloz(obrazek(300, 300, 'png'), 'cokoli.png', [], $id);
    eq('Hlavní banner.png', $g->radek($id)['nazev']);
    throws(fn() => $g->uloz(pdf(), 'x.pdf', [], $id), 'stejného druhu');
    eq(1, count(soubory($h->datovaSlozka . '/media')), 'odmítnutý soubor nezůstal');
    throws(fn() => $g->uloz(obrazek(10, 10), 'x.jpg', [], 9999), 'nenalezeno');
});
test('přejmenování: přípona se zachová, lomítka a prázdný název ne', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $id = $g->uloz(obrazek(100, 100), 'a.jpg')['id'];
    $g->prejmenuj($id, 'Nový název'); eq('Nový název.jpg', $g->radek($id)['nazev']);
    $g->prejmenuj($id, 'Jiný.JPG'); eq('Jiný.JPG', $g->radek($id)['nazev']);
    throws(fn() => $g->prejmenuj($id, 'a/b'), 'Neplatný'); throws(fn() => $g->prejmenuj($id, '  '), 'Neplatný');
});
test('tagy při nahrání: vyčistí se; chybné tagy nahrání nezruší', function () {
    [$h, $g] = galerie(['img_auto' => '0']);
    $id = $g->uloz(obrazek(100, 100), 'a.jpg', ['Akce', 'akce', 'Léto'])['id'];
    eq(['Akce', 'Léto'], $g->vypis()[0]['tagy']);
    $id2 = $g->uloz(obrazek(100, 100), 'b.jpg', [str_repeat('x', 40)])['id'];
    eq([], array_values(array_filter($g->vypis(), fn($x) => $x['id'] === $id2))[0]['tagy']);
    eq(1, $g->tagy()->hromadne([$id], ['Nový'], ['Léto']));
    eq(['Akce', 'Nový'], array_values(array_filter($g->vypis(), fn($x) => $x['id'] === $id))[0]['tagy']);
});
test('mazání: smaže řádek, soubory i vazby aplikace; chyba ve vazbách nic nesmaže', function () {
    $vazby = [];
    [$h, $g] = galerie(['img_max_w' => '1000', 'img_max_h' => '1000', 'img_min_kb' => '100000'], function (array $r) use (&$vazby) { $vazby[] = $r['id']; });
    $a = $g->uloz(obrazek(3000, 2000), 'a.jpg')['id'];
    $b = $g->uloz(pdf(), 'b.pdf')['id'];
    eq(['a.jpg'], $g->smaz([$a, 9999]));
    eq([$a], array_map('intval', $vazby));
    eq(1, count(soubory($h->datovaSlozka . '/media')), 'zmenšený i originál pryč');
    [$h2, $g2] = galerie([], function () { throw new RuntimeException('vazba drží'); });
    $c = $g2->uloz(pdf(), 'c.pdf')['id'];
    throws(fn() => $g2->smaz([$c]), 'vazba drží');
    eq(1, (int)$h2->db->q('SELECT COUNT(*) FROM media')->fetchColumn());
    eq(1, count(soubory($h2->datovaSlozka . '/media')));
});
test('archiv: přepíná jen skutečné změny a u archivovaných ukáže, kde se používají', function () {
    [$h, $g] = galerie(['img_auto' => '0'], null, fn(int $id) => ['scenare' => ['Ráno']]);
    $id = $g->uloz(obrazek(100, 100), 'a.jpg')['id'];
    eq(['a.jpg'], $g->archivuj([$id], true));
    eq([], $g->archivuj([$id], true), 'podruhé beze změny');
    $v = $g->vypis()[0];
    ok($v['archivovano']); eq(['scenare' => ['Ráno']], $v['pouziti']);
    eq(['a.jpg'], $g->archivuj([$id], false));
    eq(null, $g->vypis()[0]['pouziti']);
});
test('soubor(): vydá cestu a mime; originál po kompresi; chybějící soubor srozumitelně', function () {
    [$h, $g] = galerie(['img_max_w' => '1000', 'img_max_h' => '1000', 'img_min_kb' => '100000']);
    $id = $g->uloz(obrazek(3000, 2000), 'a.jpg')['id'];
    $s = $g->soubor($id); $o = $g->soubor($id, true);
    ok($s['cesta'] !== $o['cesta'] && is_file($s['cesta']) && is_file($o['cesta']));
    unlink($s['cesta']);
    throws(fn() => $g->soubor($id), 'na disku chybí');
    throws(fn() => $g->soubor(999), 'nenalezeno');
});
test('soubory() pro zálohu: použité soubory včetně originálů, ne sirotci', function () {
    [$h, $g] = galerie(['img_max_w' => '1000', 'img_max_h' => '1000', 'img_min_kb' => '100000']);
    $g->uloz(obrazek(3000, 2000), 'a.jpg');
    file_put_contents($h->datovaSlozka . '/media/sirotek.jpg', 'x');
    $s = $g->soubory();
    eq(2, count($s)); ok(!isset($s['sirotek.jpg']));
});
test('jiná tabulka (Social Planner): vlastní názvy sloupců, čas jako unix, bez komprese s originálem', function () {
    $h = novyHost();
    $h->db->q('CREATE TABLE library (id INTEGER PRIMARY KEY AUTOINCREMENT, file TEXT NOT NULL UNIQUE, name TEXT NOT NULL, type TEXT NOT NULL, size INTEGER NOT NULL DEFAULT 0, w INTEGER, h INTEGER, created_at INTEGER NOT NULL, tags TEXT, archived_at TEXT)');
    $s = new Sloupce(soubor: 'file', nazev: 'name', mime: null, druh: 'type', md5: null, sirka: 'w', vyska: 'h', pvSoubor: null, pvMime: null, pvVelikost: null, pvSirka: null, pvVyska: null, nahranyNazev: null);
    $g = new Galerie($h, $h->datovaSlozka . '/uploads', $s, 'library', casFormat: 'unix', cas: fn() => 1_800_000_000);
    $r = $g->uloz(obrazek(3000, 2000), 'foto.jpg', ['Léto']);
    $row = $h->db->q('SELECT * FROM library')->fetch();
    eq('image/jpeg', $g->vypis()[0]['mime'], 'mime se odvodí z přípony'); eq('foto.jpg', $row['name']); eq('image', $row['type']); eq(1_800_000_000, (int)$row['created_at']); eq(3000, (int)$row['w']);
    eq(null, $r['poznamka'], 'bez sloupců pro originál se nekomprimuje');
    eq(['Léto'], $g->vypis()[0]['tagy']);
    eq(false, $g->obnovPuvodni($r['id']));
    $g->uloz(obrazek(100, 100, 'png'), 'nova.png', [], $r['id']);
    eq('foto.png', $h->db->q('SELECT name FROM library')->fetchColumn(), 'bez sloupce pro původní název zůstane stávající jméno');
});
test('neplatné názvy sloupců a tabulek se odmítnou', function () {
    throws(fn() => new Sloupce(soubor: 'file; DROP TABLE x'), 'Neplatný název');
    $h = novyHost();
    throws(fn() => new Galerie($h, sys_get_temp_dir(), null, 'media; DROP'), 'Neplatný název');
    throws(fn() => new Galerie($h, sys_get_temp_dir(), casFormat: 'jiny'), 'formát času');
});
test('migrace je opakovatelná a drží verzi', function () {
    $h = novyHost();
    $m = new JadroMigrace($h->db);
    eq(true, $m->spust('galerie', Migrace::class)); eq(false, $m->spust('galerie', Migrace::class));
    eq(1, $m->verze('galerie'));
});

// ---------- nahrávání po částech ----------
function cast(string $obsah): string { $p = sys_get_temp_dir() . '/twb-cast-' . bin2hex(random_bytes(4)); file_put_contents($p, $obsah); return $p; }

test('nahrávání po částech složí soubor, vrací ho až po poslední části', function () {
    $n = new Nahravani(sys_get_temp_dir() . '/twb-nahr-' . bin2hex(random_bytes(3)));
    $uid = bin2hex(random_bytes(8));
    eq(null, $n->cast($uid, 0, 3, cast('AAA')));
    eq(null, $n->cast($uid, 1, 3, cast('BBB')));
    $hotovo = $n->cast($uid, 2, 3, cast('CCC'));
    eq('AAABBBCCC', file_get_contents($hotovo));
});
test('nahrávání: chybějící první část, špatné údaje a znovu od nuly', function () {
    $n = new Nahravani(sys_get_temp_dir() . '/twb-nahr-' . bin2hex(random_bytes(3)));
    $uid = bin2hex(random_bytes(8));
    throws(fn() => $n->cast($uid, 1, 3, cast('B')), 'přerušeno');
    foreach ([['abc', 0, 1], [$uid, -1, 2], [$uid, 2, 2], [$uid, 0, 0]] as [$u, $i, $c]) throws(fn() => $n->cast($u, $i, $c, cast('x')), 'Neplatný');
    $n->cast($uid, 0, 2, cast('stare'));
    $n->cast($uid, 0, 2, cast('nove'));   // nový pokus začíná znovu
    eq('novedruha', file_get_contents((string)$n->cast($uid, 1, 2, cast('druha'))));
});
test('nahrávání: příliš velký soubor se odmítne a nedokončený zmizí; staré zbytky se uklidí', function () {
    $dir = sys_get_temp_dir() . '/twb-nahr-' . bin2hex(random_bytes(3));
    $n = new Nahravani($dir, 10);
    $uid = bin2hex(random_bytes(8));
    $n->cast($uid, 0, 3, cast('123456'));
    throws(fn() => $n->cast($uid, 1, 3, cast('123456')), 'větší než');
    ok(!is_file("$dir/$uid.part"));
    $uid2 = bin2hex(random_bytes(8));
    $n->cast($uid2, 0, 2, cast('abc'));
    eq(0, $n->uklid(3600));
    eq(1, $n->uklid(3600, time() + 7200));
});
test('uid s cizími znaky nemůže vyjít ze složky', function () {
    $dir = sys_get_temp_dir() . '/twb-nahr-' . bin2hex(random_bytes(3));
    $n = new Nahravani($dir);
    $hotovo = $n->cast('../../' . bin2hex(random_bytes(8)), 0, 1, cast('x'));
    eq(realpath($dir), realpath(dirname((string)$hotovo)), 'soubor zůstal ve své složce');
    ok(!glob(dirname($dir) . '/*.part'), 'nic nevzniklo o úroveň výš');
});
