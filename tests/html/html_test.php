<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';
require dirname(__DIR__) . '/slidy/stubs.php';

use TWB\Html\{Dokument, Promenne};
use TWB\Pisma\Pisma;

final class PromenneTest implements Promenne
{
    public function vyres(string $text, DateTimeImmutable $t): array { return stub_resolve($text, $t); }
}

function pisma(): Pisma
{
    static $p = null;
    if ($p) return $p;
    $h = novyHost();
    $slozka = sys_get_temp_dir() . '/twb-html-' . bin2hex(random_bytes(4));
    mkdir($slozka, 0775, true);
    return $p = new Pisma($h->nastaveni, $h->protokol, KOREN . '/modules/pisma/fonty', $slozka);
}
function dok(): Dokument { return new Dokument(pisma(), new PromenneTest()); }

/** Písma ve tvaru, který čeká původní kód. */
function fonty(): array
{
    $o = [];
    foreach (pisma()->vsechna() as $k => $f) {
        $files = [];
        foreach ($f['custom'] ? [700] : $f['weights'] as $w) $files[$w] = pisma()->soubor($k, $w);
        $o[$k] = ['name' => $f['name'], 'weights' => $f['weights'], 'custom' => $f['custom'], 'files' => $files];
    }
    return $o;
}
function original(string $op, string $src = '', string $t = '2026-11-21 10:00:00'): mixed
{
    $proc = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/original/harness.php'), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['op' => $op, 'src' => base64_encode($src), 't' => $t, 'fonts' => fonty()]));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $j = json_decode($out, true);
    if (!is_array($j)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 400));
    return $j;
}

function vzorky(): array { return [
    'plný' => "<!DOCTYPE html>\n<html><head><title>x</title><style>body{font-family:'Montserrat',sans-serif} .a{color:red}</style></head><body><h1>Dnes {datum}</h1><p>{jmeno} &amp; {cislo}</p><script>var x = '{datum}';</script><!-- {datum} --></body></html>",
    'nbsp' => "<html><body><p>{&nbsp;datum&nbsp;}</p><p>{neznama}</p><p>{cislo} {nevime}</p></body></html>",
    'bez hlavičky' => '<p style="font-family: Oswald">Text s písmem</p>',
    'bez html' => "<!doctype html>\n<body><b style=\"font-family:&quot;Anton&quot;\">Ahoj</b></body>",
    'html bez head' => '<html lang="cs"><body style="font-family: Playfair Display">Čeština příliš žluťoučký</body></html>',
    'řízené znaky' => "<p>{jmeno}</p>\r\n<p>Tag <b>{cislo}</b> \"uvozovky\" <i>&lt;</i></p>",
    'vnořené' => '<p>{ {datum} } {{datum}} {a<b>c} {' . str_repeat('x', 400) . '}</p>',
    'externí' => '<img src="https://cdn.example.com/a.png"><link href="//fonts.x.org/c.css"><div style="background:url(\'http://IMG.test/x.jpg\')"></div><style>@import "https://a.b/c.css";</style><video poster=https://v.test/p.jpg></video><a href="https://ignore.me">x</a> <img src="local.png">',
]; }

foreach (vzorky() as $nazev => $src) {
    test("shoda s originálem: $nazev", function () use ($src) {
        $t = new DateTimeImmutable('2026-11-21 10:00:00');
        $d = dok();
        eq(original('check', $src)['ok'] ?? null, $d->zkontroluj($src));
        eq(original('resolve', $src)['ok'], $d->dosad($src, $t));
        eq(original('fonts', $src)['ok'], $d->pismaCss($src));
        eq(original('build', $src)['ok'], $d->sestav($src, $t));
        eq(original('hosts', $src)['ok'], Dokument::externiHosty($src));
    });
}
test('shoda s originálem: výchozí dokument', function () {
    eq(original('default')['ok'], Dokument::vychozi());
});
test('shoda s originálem: chybné vstupy', function () {
    $d = dok();
    $vstupy = ['', "  \n ", '<style>a{}</style><script>1</script>', "\xEF\xBB\xBF<p>BOM</p>", "<p>p\xF8\xEDli\x9A</p>", "<p>\xFF\xFE</p>", '<img src="a.png">', '<p>' . str_repeat('a', Dokument::MAX_BAJTU) . '</p>'];
    foreach ($vstupy as $i => $v) {
        $o = original('check', $v);
        try { $m = ['ok' => $d->zkontroluj($v)]; } catch (InvalidArgumentException $e) { $m = ['chyba' => $e->getMessage()]; }
        eq(md5(json_encode($o)), md5(json_encode($m)), "vstup č. $i");
    }
});
test('výchozí dokument má zadanou velikost (banner 300×250)', function () {
    $s = Dokument::vychozi(300, 250);
    ok(str_contains($s, 'width: 300px; height: 250px'));
    eq($s, (new Dokument(pisma(), new PromenneTest()))->zkontroluj($s));
});
test('písmo se vloží jen když ho dokument používá; vlastní písmo jako variabilní tloušťka', function () {
    $d = dok();
    eq('', $d->pismaCss('<p>bez písma</p>'));
    $css = $d->pismaCss("<p style=\"font-family: 'Oswald'\">x</p>");
    ok(str_contains($css, "font-family: 'Oswald'") && str_contains($css, 'base64,'));
    ok(!str_contains($css, "'Anton'"));
});
test('klíč dokumentu se mění jen s obsahem, ne s časem, pokud proměnné nejsou časové', function () {
    $d = dok();
    $s = '<p>{cislo}</p>';
    eq($d->klic($s, new DateTimeImmutable('2026-01-01')), $d->klic($s, new DateTimeImmutable('2026-06-01')));
    ok($d->klic($s, new DateTimeImmutable('2026-01-01')) !== $d->klic('<p>{jmeno}</p>', new DateTimeImmutable('2026-01-01')));
});
test('hodnoty proměnných se escapují jako HTML', function () {
    $p = new class implements Promenne { public function vyres(string $t, DateTimeImmutable $d): array { return ['<b>"&', []]; } };
    [$h] = (new Dokument(pisma(), $p))->dosad('<p>{x}</p><script>{x}</script>', new DateTimeImmutable());
    eq('<p>&lt;b&gt;&quot;&amp;</p><script>{x}</script>', $h);
});

test('mez velikosti dokumentu je přesně 5 MB a proměnná má nejvýše 300 znaků', function () {
    $d = dok();
    $zaklad = '<p></p>';
    $ok = $zaklad . str_repeat('a', 5 * 1024 * 1024 - strlen($zaklad));
    eq(5 * 1024 * 1024, strlen($d->zkontroluj($ok)));
    throws(fn() => $d->zkontroluj($ok . 'a'), '5 MB');
    $videno = [];
    $p = new class($videno) implements Promenne {
        public function __construct(private array &$v) {}
        public function vyres(string $t, DateTimeImmutable $d): array { $this->v[] = strlen($t); return ['', []]; }
    };
    (new Dokument(pisma(), $p))->dosad('<p>{' . str_repeat('x', 300) . '} {' . str_repeat('y', 301) . '}</p>', new DateTimeImmutable());
    eq([302], $videno, 'zpracuje se výraz s 300 znaky mezi závorkami, se 301 už ne');
});
test('vlastní písmo se do dokumentu vloží s proměnlivou tloušťkou', function () {
    $tmp = sys_get_temp_dir() . '/twb-vl-' . bin2hex(random_bytes(3)) . '.ttf';
    copy(pisma()->soubor('oswald', 700), $tmp);
    $info = pisma()->nahraj($tmp, 'moje.ttf', 'Moje Pismo');
    $css = dok()->pismaCss("<p style=\"font-family: 'Moje Pismo'\">x</p>");
    ok(str_contains($css, "font-family: 'Moje Pismo'; font-weight: 100 900;"), $css === '' ? 'prázdné' : substr($css, 0, 80));
    eq(1, substr_count($css, '@font-face'));
});
