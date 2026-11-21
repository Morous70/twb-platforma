<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/slidy/stubs.php';

use TWB\Jadro\Migrace as JadroMigrace;
use TWB\Pisma\Pisma;
use TWB\Slidy\{Navrh, Vykresleni, Text, Zdroje, Promenne as SlidyPromenne};
use TWB\Data\{Predpoved, PocasiPrvek, Migrace};

date_default_timezone_set('Europe/Prague');
const FORMATY = ['square' => [1080, 1080, 'Čtverec'], 'landscape' => [1920, 1080, 'Na šířku']];

function prostredi(): array
{
    static $p = null;
    if ($p) return $p;
    $root = sys_get_temp_dir() . '/twb-pocasi-' . bin2hex(random_bytes(4));
    foreach (['public/assets/fonts', 'public/uploads', 'data/fonts', 'data/weather-icons'] as $d) mkdir("$root/$d", 0775, true);
    symlink(KOREN . '/modules/pisma/fonty', "$root/public/assets/fonts/slide");
    foreach (['02d' => [255, 200, 40], '10d' => [60, 120, 220], '04d' => [150, 150, 160], '13d' => [200, 230, 255]] as $kod => [$r, $g, $b]) {
        if ($kod === '13d') continue;   // ikona „13d“ chybí a nedá se stáhnout – prvek ji má jen vynechat
        $im = imagecreatetruecolor(100, 100);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledellipse($im, 50, 50, 80, 80, imagecolorallocatealpha($im, $r, $g, $b, 20));
        imagepng($im, "$root/data/weather-icons/ow-$kod.png");
    }
    return $p = ['root' => $root];
}
function data(string $misto): array
{
    $dny = [];
    foreach ([[18.4, 9.5, '02d', 'skoro jasno'], [15.5, 8.2, '10d', 'slabý déšť'], [13.0, -0.4, '13d', 'sněžení'], [12, 3, '04d', 'zataženo']] as $i => [$mx, $mn, $ic, $ds]) {
        $dny[] = ['date' => (new DateTimeImmutable('2026-11-21'))->modify("+$i day")->format('Y-m-d'), 'max' => $mx, 'min' => $mn, 'icon' => $ic, 'desc' => $ds];
    }
    return ['place' => $misto, 'at' => 1_800_000_000, 'name' => $misto, 'now' => ['temp' => 16.4, 'feels' => 15.5, 'desc' => 'skoro jasno', 'icon' => '02d', 'humidity' => 62, 'wind' => 3.6], 'days' => $dny];
}
final class ZdrojeNic implements Zdroje { public function obrazek(?int $id): ?GdImage { return null; } public function podpis(int $id): string { return ''; } }
final class PromenneTest implements SlidyPromenne
{
    public function vyres(string $text, DateTimeImmutable $t): array { return stub_resolve($text, $t); }
    public function splneno(array $podminky, DateTimeImmutable $t): bool { return stub_conds($podminky, $t); }
}
function modul(): array
{
    $p = prostredi();
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    $pisma = new Pisma($h->nastaveni, $h->protokol, KOREN . '/modules/pisma/fonty', $p['root'] . '/data/fonts');
    foreach (['praha' => 'Praha', 'brno' => 'Brno'] as $k => $m) $h->db->q('INSERT INTO weather VALUES (?,?,?,?,?,NULL)', [$k, $m, json_encode(data($m), JSON_UNESCAPED_UNICODE), 1, 1]);
    $pocasi = new Predpoved($h->db, $h->protokol, fn() => '', fn() => 'Praha');
    $prvek = new PocasiPrvek($pocasi, $pisma, $p['root'] . '/data/weather-icons', 'https://x', function () { throw new RuntimeException('bez sítě'); });
    $navrh = new Navrh($pisma, FORMATY, 'square', [$prvek]);
    return [$navrh, new Vykresleni($navrh, new Text($pisma), new ZdrojeNic(), new PromenneTest()), $prvek, $pocasi];
}
function original(array $raw, string $cas): array
{
    $p = prostredi();
    $proc = proc_open(escapeshellarg(PHP_BINARY) . ' -d display_errors=1 ' . escapeshellarg(KOREN . '/tests/slidy/original/harness.php'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['raw' => $raw, 't' => $cas, 'root' => $p['root'], 'library' => [], 'pocasi' => ['' => data('Praha'), 'Brno' => data('Brno')]]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $j = json_decode($out, true);
    if (!is_array($j)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 500));
    return ['design' => $j['design'], 'key' => $j['key'], 'jpeg' => base64_decode($j['jpeg'])];
}
function shoda(array $raw, string $cas = '2026-11-21 10:00:00'): string
{
    [$navrh, $v] = modul();
    $o = original($raw, $cas);
    $d = $navrh->vycisti($raw);
    eq(json_encode($o['design']), json_encode($d), 'očištěná šablona');
    $t = new DateTimeImmutable($cas);
    eq($o['key'], $v->klic($d, $t), 'klíč obsahu');
    $jpeg = $v->vykresli($d, $t);
    eq(md5($o['jpeg']), md5($jpeg), 'obrázek se liší od originálu');
    return $jpeg;
}
function prvek(array $e): array { return $e + ['id' => 'w' . substr(md5(json_encode($e)), 0, 6), 'type' => 'weather', 'x' => 100, 'y' => 100, 'w' => 800, 'h' => 300]; }

foreach (['mini', 'card', 'days'] as $look) {
    test("shoda s originálem: prvek Počasí – vzhled $look (zarovnání, podklad, stín, průhlednost, malé rámečky)", function () use ($look) {
        foreach (['left', 'center', 'right'] as $align) {
            shoda(['format' => 'square', 'bg' => ['color' => '#204060'], 'elements' => [prvek(['look' => $look, 'align' => $align, 'bg' => ['on' => true, 'alpha' => 0.5, 'padding' => 20, 'radius' => 30], 'shadow' => ['on' => true, 'dx' => 5, 'dy' => 5]])]]);
        }
        shoda(['format' => 'landscape', 'elements' => [prvek(['look' => $look, 'place' => 'Brno', 'font' => 'oswald', 'weight' => 400, 'color' => '#ffee00', 'alpha' => 0.6, 'w' => 260, 'h' => 120, 'x' => 40, 'y' => 40])]]);
        shoda(['format' => 'square', 'bg' => ['color' => '#336699'], 'elements' => [prvek(['look' => $look, 'alpha' => 0.6, 'bg' => ['on' => true, 'alpha' => 0.8, 'padding' => 10, 'radius' => 10], 'shadow' => ['on' => true, 'alpha' => 0.7]])]]);
        shoda(['format' => 'landscape', 'elements' => [prvek(['look' => $look, 'w' => 330, 'h' => 400, 'x' => 30, 'y' => 30]), prvek(['look' => $look, 'w' => 900, 'h' => 80, 'x' => 30, 'y' => 500, 'font' => 'anton'])]]);
        shoda(['format' => 'square', 'elements' => [prvek(['look' => $look, 'w' => 2000, 'h' => 900, 'x' => -50, 'y' => 20])]], '2026-11-22 18:00:00');
    });
}
test('shoda s originálem: bez dat, mimo předpověď, skryté a podmíněné prvky', function () {
    shoda(['format' => 'square', 'elements' => [prvek(['look' => 'days', 'place' => 'Neznámé město'])]]);
    shoda(['format' => 'square', 'elements' => [prvek(['look' => 'days'])]], '2026-12-30 10:00:00');
    shoda(['format' => 'square', 'elements' => [prvek(['look' => 'card', 'hidden' => true]), prvek(['look' => 'mini', 'y' => 500, 'showIf' => [['l' => '{cislo}', 'op' => '=', 'r' => '42']]])]]);
});
test('shoda s originálem: očištění nesmyslných hodnot prvku', function () {
    [$navrh] = modul();
    foreach ([['look' => 'x', 'place' => str_repeat('m', 200), 'font' => 'nic', 'weight' => 5000, 'color' => 'červená', 'align' => '?', 'shadow' => ['on' => 1, 'dx' => 900, 'alpha' => 9], 'bg' => ['on' => 1, 'padding' => -4, 'radius' => 1e6]], [], ['look' => 'card', 'font' => 'oswald', 'bg' => 'text']] as $i => $e) {
        $raw = ['elements' => [prvek($e)]];
        eq(json_encode(original($raw, '2026-11-21 10:00:00')['design']), json_encode($navrh->vycisti($raw)), "vstup $i");
    }
});
test('prvek vyžaduje typ weather, ikona se stáhne jen jednou a chybějící se vynechá; ukázka pro editor', function () {
    [$navrh, $v, $prvek, $pocasi] = modul();
    eq('weather', $prvek->typ());
    $volani = [];
    $slozka = sys_get_temp_dir() . '/twb-ikony-' . bin2hex(random_bytes(3));
    $png = (function () { $im = imagecreatetruecolor(8, 8); ob_start(); imagepng($im); return ob_get_clean(); })();
    $pr = new PocasiPrvek($pocasi, (fn() => (new ReflectionProperty($prvek, 'pisma'))->getValue($prvek))(), $slozka, 'https://ikony.test/img/', function (string $url) use (&$volani, $png) {
        $volani[] = $url;
        if (str_contains($url, '99x')) return [404, 'x'];
        return [200, $png];
    });
    ok(is_file((string)$pr->ikona('04d')));
    $pr->ikona('04d');
    eq(['https://ikony.test/img/04d@4x.png'], $volani, 'druhé volání bere z uložené složky');
    eq(null, $pr->ikona('99x'));
    $pr->ikona('99x');
    eq(2, count($volani), 'neúspěšný pokus se v rámci běhu neopakuje');
    eq(2, count($volani));
    eq(null, $pr->ikona('../x'));
    eq(null, $pr->ikona('abcde'));
    ok(is_string($pr->ikona('ab')), 'dvouznakový kód platí');
    eq(null, $pr->ikona(''));
    $i = $prvek->infoProEditor(['place' => 'Neznámé', 'look' => 'days', 'x' => 0, 'y' => 0, 'w' => 600, 'h' => 200, 'font' => 'montserrat', 'weight' => 700, 'align' => 'center', 'bg' => ['on' => false, 'padding' => 0]], new DateTimeImmutable('2026-11-21'));
    ok($i['sample'] && count($i['parts']) === 9 && $i['error'] !== null, 'bez dat se ukáže vzorové rozvržení');
    eq(1, count(array_filter($prvek->rozvrh(['look' => 'mini', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 100, 'font' => 'montserrat', 'weight' => 700, 'align' => 'left', 'bg' => ['on' => false, 'padding' => 0]], PocasiPrvek::ukazka(new DateTimeImmutable('2026-11-21')), new DateTimeImmutable('2026-11-21')), fn($p) => $p['k'] === 'icon')));
});
