<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require __DIR__ . '/stubs.php';

use TWB\Pisma\Pisma;
use TWB\Slidy\{Navrh, Vykresleni, Text, Zdroje, Promenne, Prvek, Kulicky, Fx};

const FORMATY = [
    'square'    => [1080, 1080, 'Čtverec 1 : 1 (příspěvek)'],
    'portrait'  => [1080, 1350, 'Na výšku 4 : 5 (Instagram)'],
    'story'     => [1080, 1920, 'Story / Reels 9 : 16'],
    'landscape' => [1920, 1080, 'Na šířku 16 : 9'],
    'link'      => [1200, 630, 'Odkaz / Facebook 1,91 : 1'],
];

/** Pracovní složka s fotkou, logem s průhledností a písmy (jako v Social Planneru: public/uploads, public/assets/fonts/slide). */
function prostredi(): array
{
    static $p = null;
    if ($p) return $p;
    $root = sys_get_temp_dir() . '/twb-slidy-' . bin2hex(random_bytes(4));
    foreach (['public/assets/fonts', 'public/uploads', 'data/fonts'] as $d) mkdir("$root/$d", 0775, true);
    symlink(KOREN . '/modules/pisma/fonty', "$root/public/assets/fonts/slide");
    // deterministická fotka (bez náhody)
    $im = imagecreatetruecolor(640, 480);
    for ($y = 0; $y < 480; $y++) for ($x = 0; $x < 640; $x++) imagesetpixel($im, $x, $y, ((int)($x * 255 / 640) << 16) | ((int)($y * 255 / 480) << 8) | (($x ^ $y) & 255));
    imagejpeg($im, "$root/public/uploads/photo.jpg", 92);
    $lg = imagecreatetruecolor(200, 120);
    imagealphablending($lg, false); imagesavealpha($lg, true);
    imagefill($lg, 0, 0, imagecolorallocatealpha($lg, 0, 0, 0, 127));
    imagefilledellipse($lg, 100, 60, 180, 100, imagecolorallocatealpha($lg, 220, 40, 40, 30));
    imagefilledrectangle($lg, 60, 40, 140, 80, imagecolorallocatealpha($lg, 255, 255, 255, 60));
    imagepng($lg, "$root/public/uploads/logo.png");
    file_put_contents("$root/public/uploads/clip.mp4", 'neni obrazek');
    $knihovna = [['id' => 1, 'file' => 'photo.jpg', 'type' => 'image'], ['id' => 2, 'file' => 'logo.png', 'type' => 'image'], ['id' => 3, 'file' => 'clip.mp4', 'type' => 'video']];
    return $p = ['root' => $root, 'knihovna' => $knihovna];
}

final class ZdrojeTest implements Zdroje
{
    public function __construct(private array $p) {}
    private function soubor(int $id): ?string
    {
        foreach ($this->p['knihovna'] as $r) if ($r['id'] === $id && $r['type'] === 'image') return $this->p['root'] . '/public/uploads/' . $r['file'];
        return null;
    }
    public function obrazek(?int $id): ?GdImage
    {
        if (!$id || !($f = $this->soubor($id)) || !is_file($f)) return null;
        $img = @imagecreatefromstring((string)file_get_contents($f));
        if (!$img) return null;
        if (!imageistruecolor($img)) imagepalettetotruecolor($img);
        imagealphablending($img, true); imagesavealpha($img, true);
        return $img;
    }
    public function podpis(int $id): string   // jako library_file_sig: otisk platí pro libovolný soubor knihovny
    {
        foreach ($this->p['knihovna'] as $r) if ($r['id'] === $id) { $f = $this->p['root'] . '/public/uploads/' . $r['file']; return is_file($f) ? filesize($f) . '-' . filemtime($f) : ''; }
        return '';
    }
}
final class PromenneTest implements Promenne
{
    public function vyres(string $text, DateTimeImmutable $t): array { return stub_resolve($text, $t); }
    public function splneno(array $podminky, DateTimeImmutable $t): bool { return stub_conds($podminky, $t); }
}

function modul(array $prvky = [], bool $vlastni = false): array
{
    $p = prostredi();
    $h = novyHost();
    $pisma = new Pisma($h->nastaveni, $h->protokol, KOREN . '/modules/pisma/fonty', $p['root'] . '/data/fonts');
    $navrh = new Navrh($pisma, FORMATY, 'square', $prvky, $vlastni);
    $v = new Vykresleni($navrh, new Text($pisma), new ZdrojeTest($p), new PromenneTest());
    return [$navrh, $v];
}

/** Originál (Social Planner 1.9.2) v samostatném procesu. @return array{design:array,key:string,jpeg:string} */
function original(array $raw, string $cas): array
{
    $p = prostredi();
    $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg(__DIR__ . '/original/harness.php');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['raw' => $raw, 't' => $cas, 'root' => $p['root'], 'library' => $p['knihovna']]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $j = json_decode($out, true);
    if (!is_array($j)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 400));
    return ['design' => $j['design'], 'key' => $j['key'], 'jpeg' => base64_decode($j['jpeg'])];
}

/** Porovná očištění, klíč a výsledný obrázek bajt po bajtu. */
function shoda(array $raw, string $cas = '2026-11-21 10:00:00', array $prvky = []): array
{
    [$navrh, $v] = modul($prvky);
    $o = original($raw, $cas);
    $d = $navrh->vycisti($raw);
    eq(json_encode($o['design']), json_encode($d), 'očištěná šablona');
    $t = new DateTimeImmutable($cas);
    eq($o['key'], $v->klic($d, $t), 'klíč obsahu');
    $jpeg = $v->vykresli($d, $t);
    eq(md5($o['jpeg']), md5($jpeg), 'obrázek se liší od originálu');
    ok(strlen($jpeg) > 1000, 'obrázek není prázdný');
    return [$d, $jpeg];
}

function txt(array $e = []): array
{
    return $e + ['id' => 't' . substr(md5(json_encode($e)), 0, 6), 'type' => 'text', 'text' => 'Ahoj světe', 'x' => 60, 'y' => 60, 'w' => 800, 'h' => 300, 'size' => 90, 'color' => '#ffffff'];
}

// ---------- shoda s originálem: kreslení ----------
test('shoda s originálem: barevné a přechodové pozadí, ztmavení', function () {
    shoda(['format' => 'square', 'bg' => ['type' => 'color', 'color' => '#336699', 'dim' => 0.3], 'elements' => []]);
    shoda(['format' => 'portrait', 'bg' => ['type' => 'gradient', 'color' => '#102030', 'color2' => '#f0a000', 'dir' => 'v'], 'elements' => []]);
    shoda(['format' => 'link', 'bg' => ['type' => 'gradient', 'color' => '#ff0000', 'color2' => '#0000ff', 'dir' => 'h'], 'elements' => []]);
});
test('shoda s originálem: pozadí z obrázku (cover i contain)', function () {
    shoda(['format' => 'landscape', 'bg' => ['type' => 'image', 'mediaId' => 1, 'fit' => 'cover', 'dim' => 0.2], 'elements' => []]);
    shoda(['format' => 'square', 'bg' => ['type' => 'image', 'mediaId' => 1, 'fit' => 'contain'], 'elements' => []]);
    shoda(['format' => 'square', 'bg' => ['type' => 'image', 'mediaId' => 3, 'fit' => 'cover'], 'elements' => []]);   // video není obrázek
});
test('shoda s originálem: text – zarovnání, zalamování, česká diakritika, emoji, velká písmena', function () {
    shoda(['format' => 'square', 'elements' => [
        txt(['text' => "Příliš žluťoučký kůň úpěl ďábelské ódy 😀 a ještě delší věta, která se musí zalomit do více řádků", 'size' => 56, 'align' => 'center', 'valign' => 'middle', 'y' => 200, 'h' => 600]),
        txt(['text' => 'velká písmena', 'upper' => true, 'size' => 40, 'x' => 60, 'y' => 900, 'w' => 900, 'h' => 100, 'align' => 'right', 'font' => 'oswald', 'weight' => 700]),
    ]]);
});
test('shoda s originálem: text – stín, obrys, podklad, rozestup písmen, zmenšení do rámečku', function () {
    shoda(['format' => 'story', 'bg' => ['color' => '#202020'], 'elements' => [
        txt(['text' => 'Stín a obrys', 'shadow' => ['on' => true, 'dx' => 6, 'dy' => 6, 'alpha' => 0.6], 'outline' => ['on' => true, 'width' => 4, 'color' => '#aa0000'], 'font' => 'montserrat', 'weight' => 900]),
        txt(['text' => 'Podklad pod textem a rozestup', 'y' => 500, 'h' => 200, 'bg' => ['on' => true, 'color' => '#003366', 'alpha' => 0.7, 'padding' => 30, 'radius' => 40], 'spacing' => 0.08, 'size' => 60]),
        txt(['text' => str_repeat('Dlouhý text se zmenší, aby se vešel. ', 8), 'y' => 800, 'h' => 300, 'autoFit' => true, 'size' => 120, 'align' => 'center', 'font' => 'playfair']),
    ]]);
});
test('shoda s originálem: grafické efekty textu (zlato i světlý styl, s rozestupem)', function () {
    shoda(['format' => 'landscape', 'bg' => ['type' => 'gradient', 'color' => '#101820', 'color2' => '#304860'], 'elements' => [
        txt(['text' => 'JACKPOT', 'size' => 200, 'align' => 'center', 'valign' => 'middle', 'x' => 100, 'y' => 100, 'w' => 1700, 'h' => 400, 'font' => 'archivo', 'weight' => 900, 'fx' => ['style' => 'zlato']]),
        txt(['text' => "Dva řádky\nmodře", 'size' => 110, 'x' => 100, 'y' => 560, 'w' => 1000, 'h' => 450, 'font' => 'titan', 'fx' => ['style' => 'modra_l', 'glow' => 0.4], 'spacing' => 0.05, 'alpha' => 0.9]),
    ]]);
});
test('shoda s originálem: obdélníky a obrázky (průhlednost, zaoblení, cover/contain)', function () {
    shoda(['format' => 'square', 'bg' => ['color' => '#445566'], 'elements' => [
        ['id' => 'r1', 'type' => 'rect', 'x' => 40, 'y' => 40, 'w' => 500, 'h' => 300, 'color' => '#ff8800', 'radius' => 60, 'alpha' => 0.7],
        ['id' => 'r2', 'type' => 'rect', 'x' => 300, 'y' => 200, 'w' => 600, 'h' => 200, 'color' => '#0088ff', 'radius' => 0],
        ['id' => 'i1', 'type' => 'image', 'x' => 100, 'y' => 450, 'w' => 400, 'h' => 300, 'mediaId' => 1, 'fit' => 'cover'],
        ['id' => 'i2', 'type' => 'image', 'x' => 560, 'y' => 450, 'w' => 400, 'h' => 300, 'mediaId' => 2, 'fit' => 'contain', 'alpha' => 0.6],
        ['id' => 'i3', 'type' => 'image', 'x' => 560, 'y' => 800, 'w' => 300, 'h' => 200, 'mediaId' => 2, 'fit' => 'contain'],
        ['id' => 'i4', 'type' => 'image', 'x' => 10, 'y' => 800, 'w' => 300, 'h' => 200, 'mediaId' => 99],
    ]]);
});
test('shoda s originálem: kuličky (ploché, lesklé, mimořádné číslo, zmenšení při nedostatku místa)', function () {
    shoda(['format' => 'landscape', 'bg' => ['color' => '#1a1a2e'], 'elements' => [
        ['id' => 'b1', 'type' => 'balls', 'x' => 100, 'y' => 100, 'w' => 1700, 'h' => 200, 'src' => '{cisla}', 'extra' => '{dalsi}', 'style' => 'glossy', 'size' => 140, 'stroke' => ['on' => true, 'width' => 5]],
        ['id' => 'b2', 'type' => 'balls', 'x' => 100, 'y' => 400, 'w' => 500, 'h' => 300, 'src' => '1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18', 'extra' => '', 'style' => 'flat', 'size' => 120, 'align' => 'left', 'valign' => 'top'],
    ]]);
});
test('shoda s originálem: proměnné, varianty textu a podmínky zobrazení', function () {
    shoda(['format' => 'square', 'elements' => [
        txt(['id' => 'a', 'text' => 'Dnes je {datum}, {jmeno} a {{cislo}} {neznama}', 'size' => 50, 'variants' => [
            ['when' => [['l' => '{cislo}', 'op' => '>', 'r' => '100']], 'text' => 'nikdy'],
            ['when' => [['l' => '{jmeno}', 'op' => 'contains', 'r' => 'Čapek']], 'text' => 'Varianta pro {jmeno}'],
        ]]),
        txt(['id' => 'b', 'text' => 'Skryto', 'y' => 400, 'showIf' => [['l' => '{prazdne}', 'op' => 'notempty', 'r' => '']]]),
        txt(['id' => 'c', 'text' => 'Zobrazeno', 'y' => 600, 'showIf' => [['l' => '{cislo}', 'op' => '=', 'r' => '42']]]),
        txt(['id' => 'd', 'text' => 'Schováno natvrdo', 'y' => 800, 'hidden' => true]),
    ]]);
});
test('shoda s originálem: klíč obsahu se mění s časem jen pokud se mění texty', function () {
    [$navrh, $v] = modul();
    $d = $navrh->vycisti(['format' => 'square', 'elements' => [txt(['id' => 'x', 'text' => 'Pevný text'])]]);
    eq($v->klic($d, new DateTimeImmutable('2026-01-01 10:00')), $v->klic($d, new DateTimeImmutable('2026-06-01 10:00')));
    $d2 = $navrh->vycisti(['format' => 'square', 'elements' => [txt(['id' => 'x', 'text' => 'Jiný text'])]]);
    ok($v->klic($d, new DateTimeImmutable('2026-01-01')) !== $v->klic($d2, new DateTimeImmutable('2026-01-01')));
});

// ---------- shoda s originálem: očištění šablony ----------
test('očištění: nesmyslné vstupy dopadnou stejně jako v originálu', function () {
    [$navrh] = modul();
    $vstupy = [
        null, 'text', [], ['format' => 'neznamy'], ['format' => 'story', 'w' => 5, 'h' => 5],
        ['bg' => ['type' => 'video', 'color' => 'red', 'color2' => '#GGGGGG', 'dir' => 'x', 'mediaId' => '7', 'fit' => 'stretch', 'dim' => 9]],
        ['elements' => [1, 'a', null, ['type' => 'script', 'id' => 'q'], ['type' => 'text', 'id' => 'a<b>1', 'x' => 99999, 'y' => -99999, 'w' => 1, 'h' => 99999, 'alpha' => 5, 'size' => 1, 'weight' => 5000,
            'font' => 'neexistuje', 'align' => 'justify', 'valign' => 'x', 'lineHeight' => 100, 'spacing' => 9, 'text' => str_repeat('x', 3000),
            'fx' => ['style' => 'zlato', 'stroke' => 99, 'grad' => ['red', '#abcdef'], 'glowColor' => 'zelená'], 'variants' => array_fill(0, 15, ['when' => [['l' => 'a', 'op' => 'bad', 'r' => 'b']], 'text' => 'v']),
            'showIf' => array_fill(0, 20, ['l' => 1, 'op' => 'empty', 'r' => 2]), 'shadow' => ['on' => 1, 'dx' => 500], 'outline' => ['on' => 1, 'width' => 0], 'bg' => ['on' => 1, 'padding' => 900, 'radius' => -5]]]],
        ['elements' => [['id' => 'r', 'type' => 'rect', 'color' => 'blue', 'radius' => 99999], ['id' => 'i', 'type' => 'image', 'mediaId' => 'abc', 'fit' => 'cover'],
                        ['id' => 'b', 'type' => 'balls', 'src' => str_repeat('1', 400), 'size' => 1, 'gap' => 9999, 'style' => 'glossy', 'fill' => '#12345', 'numScale' => 5, 'font' => 'zzz']]],
        ['elements' => array_fill(0, 80, ['id' => 'same', 'type' => 'rect'])],
    ];
    foreach ($vstupy as $i => $raw) {
        // id bez zadání se v obou verzích generuje náhodně, proto je ve vstupech vždy zadané
        $o = original(is_array($raw) ? $raw : [], '2026-11-21 10:00:00')['design'];
        eq(json_encode($o), json_encode($navrh->vycisti($raw)), "vstup č. $i");
    }
});

// ---------- vlastnosti modulu ----------
test('plátno libovolné velikosti (banner): vlastní rozměr v mezích, ostatní formáty beze změny', function () {
    [$navrh, $v] = modul([], true);
    $d = $navrh->vycisti(['format' => 'vlastni', 'w' => 728, 'h' => 90, 'elements' => [txt(['id' => 'a', 'text' => 'Banner', 'w' => 700, 'h' => 80, 'size' => 50, 'x' => 10, 'y' => 10])]]);
    eq([728, 90, 'vlastni'], [$d['w'], $d['h'], $d['format']]);
    $jpg = $v->vykresli($d, new DateTimeImmutable('2026-11-21'));
    $im = imagecreatefromstring($jpg);
    eq([728, 90], [imagesx($im), imagesy($im)]);
    $mini = $navrh->vycisti(['format' => 'vlastni', 'w' => 1, 'h' => 99999]);
    eq([16, 4096], [$mini['w'], $mini['h']]);
    eq([1080, 1080], [$navrh->vycisti(['format' => 'square', 'w' => 10, 'h' => 10])['w'], $navrh->vycisti(['format' => 'square', 'w' => 10, 'h' => 10])['h']], 'rozměr pevného formátu se nedá přepsat');
    [$bez] = modul();
    eq('square', $bez->vycisti(['format' => 'vlastni', 'w' => 728, 'h' => 90])['format'], 'bez povolení vlastního rozměru se použije výchozí formát');
    
});
test('banner 300×250 se vykreslí celý (rozměry obrázku odpovídají šabloně)', function () {
    [$navrh, $v] = modul([], true);
    foreach ([[300, 250], [160, 600], [970, 250], [320, 50]] as [$w, $h]) {
        $d = $navrh->vycisti(['format' => 'vlastni', 'w' => $w, 'h' => $h, 'bg' => ['type' => 'gradient', 'color' => '#000000', 'color2' => '#ffffff']]);
        $im = imagecreatefromstring($v->vykresli($d, new DateTimeImmutable('2026-11-21')));
        eq([$w, $h], [imagesx($im), imagesy($im)]);
    }
});
test('další typ prvku: očištění, vyhodnocení a kreslení jdou přes rozhraní Prvek', function () {
    $prvek = new class implements Prvek {
        public array $kresleno = [];
        public function typ(): string { return 'cerveny'; }
        public function vycisti(array $e, Navrh $n): array { return ['barva' => Navrh::barva($e['barva'] ?? null, '#ff0000')]; }
        public function vyhodnot(array $e, DateTimeImmutable $t): array { return ['den' => $t->format('j')]; }
        public function kresli(GdImage $im, array $e, array $vyh, Text $text): void { $this->kresleno[] = $vyh['den']; imagefilledrectangle($im, (int)$e['x'], (int)$e['y'], (int)($e['x'] + $e['w']), (int)($e['y'] + $e['h']), 0xff0000); }
    };
    [$navrh, $v] = modul([$prvek]);
    $d = $navrh->vycisti(['format' => 'square', 'elements' => [['id' => 'p', 'type' => 'cerveny', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'barva' => 'nesmysl'], ['id' => 'u', 'type' => 'neznamy']]]);
    eq(1, count($d['elements'])); eq('#ff0000', $d['elements'][0]['barva']);
    $jpg = $v->vykresli($d, new DateTimeImmutable('2026-11-21'));
    eq(['21'], $prvek->kresleno);
    $im = imagecreatefromstring($jpg);
    ok((imagecolorat($im, 50, 50) >> 16 & 255) > 200, 'červený čtverec je vidět');
    $k1 = $v->klic($d, new DateTimeImmutable('2026-11-21')); $k2 = $v->klic($d, new DateTimeImmutable('2026-11-22'));
    ok($k1 !== $k2, 'vyhodnocení prvku je součástí klíče');
    throws(fn() => new Navrh($navrh->pisma(), FORMATY, 'square', [new class implements Prvek {
        public function typ(): string { return 'text'; } public function vycisti(array $e, Navrh $n): array { return []; }
        public function vyhodnot(array $e, DateTimeImmutable $t): array { return []; } public function kresli(GdImage $im, array $e, array $v, Text $t): void {}
    }]), 'vyhrazený');
});
test('média použitá v šabloně a použití písma', function () {
    [$navrh] = modul();
    $d = $navrh->vycisti(['format' => 'square', 'bg' => ['type' => 'image', 'mediaId' => 5], 'elements' => [
        ['id' => 'a', 'type' => 'image', 'mediaId' => 7], ['id' => 'b', 'type' => 'image', 'mediaId' => 5], ['id' => 'c', 'type' => 'text', 'font' => 'oswald']]]);
    eq([5, 7], Navrh::mediaIds($d));
    ok(Navrh::pouzivaPismo($d, 'oswald') && !Navrh::pouzivaPismo($d, 'anton'));
});
test('neplatné nastavení formátů a prvků se odmítne; kuličky čísla z textu', function () {
    [$navrh] = modul();
    throws(fn() => new Navrh($navrh->pisma(), FORMATY, 'neni'), 'Výchozí formát');
    eq(['22', '14', '27'], Kulicky::cisla('22, 14 a {neznama} 27'));
    eq(100, count(Kulicky::cisla(implode(' ', range(1, 150)))));
    eq(null, Fx::vycisti(['style' => 'neexistuje']));
});

test('editor v prohlížeči: kopie je shodná se Social Plannerem, je syntakticky v pořádku a popis zná všechny dodávané funkce', function () {
    $web = KOREN . '/modules/slidy/web';
    $sp = KOREN . '/apps/social-planner/assets';
    eq(md5_file("$sp/js/slide-editor.js"), md5_file("$web/slide-editor.js"));
    eq(md5_file("$sp/css/slide-editor.css"), md5_file("$web/slide-editor.css"));
    $shim = (string)file_get_contents("$sp/js/slide-shim.js");
    $js = (string)file_get_contents("$web/slide-editor.js");
    $popis = (string)file_get_contents("$web/HOST.md");
    preg_match_all('/^(?:async function|function|const|let) ([A-Za-z_$]+)/m', $shim, $m);
    $pouzite = array_filter(array_unique($m[1]), fn($n) => preg_match('/(?<![\w$])' . preg_quote($n, '/') . '(?![\w$])/', $js));
    ok(count($pouzite) > 20, 'nalezeny dodávané symboly');
    foreach ($pouzite as $n) ok(str_contains($popis, '`' . $n . '`'), "HOST.md nezmiňuje $n");
    if (trim((string)shell_exec('command -v node'))) {
        exec('node --check ' . escapeshellarg("$web/slide-editor.js") . ' 2>&1', $out, $rc);
        eq(0, $rc, implode("\n", $out));
    }
});
