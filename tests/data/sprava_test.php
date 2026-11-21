<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\Migrace as JadroMigrace;
use TWB\Data\{Tabulka, Vzdalene, Migrace};

date_default_timezone_set('Europe/Prague');

function tab(int $cas = 1_800_000_000): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    return [$h, new Tabulka($h->db, 'data_rows', fn() => $cas)];
}
function obsah(Tabulka $t): array { return array_map(fn($r) => [$r['grp'], $r['param'], $r['value']], $t->radky(true)); }

test('řádek: uložení nového a úprava, čas uložení se zapisuje', function () {
    [$h, $t] = tab();
    $id = $t->uloz(null, ' Sportka ', ' 2026-11-26 ', ' 45 500 000 ');
    eq([['Sportka', '2026-11-26', '45500000']], obsah($t));
    eq(1_800_000_000, (int)$h->db->q('SELECT updated_at FROM data_rows')->fetchColumn());
    eq($id, $t->uloz($id, 'Sportka', '2026-11-26', '50'));
    eq([['Sportka', '2026-11-26', '50']], obsah($t));
    throws(fn() => $t->uloz(null, 'Datum', 'x', 'y'), 'vyhrazený');
    throws(fn() => $t->uloz(null, 'a.b', 'x', 'y'), 'tečku');
    throws(fn() => $t->uloz(null, 'g', '', 'y'), 'parametr');
    eq(1, count($t->radky()), 'neplatný řádek se neuloží');
});
test('mazání: podle id a minulá data', function () {
    [, $t] = tab();
    $a = $t->uloz(null, 'S', '2026-11-20', '1'); $b = $t->uloz(null, 'S', '21. 11. 2026', '2'); $c = $t->uloz(null, 'S', '2026-11-22', '3'); $t->uloz(null, 'S', 'bez data', '4');
    eq(1, $t->smazMinule(new DateTimeImmutable('2026-11-21 15:00')), 'dnešní datum se nemaže');
    eq(['2', '3', '4'], array_column(obsah($t), 2));
    eq(2, $t->smaz([$b, $c]));
    eq(['4'], array_column(obsah($t), 2));
    ok($a > 0);
});
test('import z Excelu: oddělovače, záhlaví, chybné řádky, nahrazení skupiny', function () {
    [, $t] = tab();
    $r = $t->importuj("Skupina\tParametr\tHodnota\nCeník\tKáva\t59\nCeník;Čaj;49,5\nCeník|Kakao|70 Kč a víc\nbez oddělovače\nDatum\tx\ty\nSp\t2026-11-26\t1 000\n");
    eq(4, $r['imported']);
    eq(['řádek 5: očekávám 3 sloupce (Skupina, Parametr, Hodnota)', 'řádek 6: název „Datum“ je vyhrazený pro kalendář'], $r['errors']);
    eq([['Ceník', 'Káva', '59'], ['Ceník', 'Čaj', '49.5'], ['Ceník', 'Kakao', '70 Kč a víc'], ['Sp', '2026-11-26', '1000']], obsah($t));
    $t->importuj("ceník\tJediný\t1\n", false);
    eq(5, count($t->radky(true)), 'bez nahrazení se jen přidává');
    $t->importuj("CENÍK\tNový\t2\n", true);
    eq([['CENÍK', 'Nový', '2'], ['Sp', '2026-11-26', '1000']], obsah($t), 'nahrazená skupina (bez ohledu na velikost písmen) obsahuje jen nové řádky');
    throws(fn() => $t->importuj(''), 'Vložte řádky');
    throws(fn() => $t->importuj("jen jeden sloupec\nanebo dva\tsloupce"), 'Nic k vložení – řádek 1');
    eq(2, count($t->radky(true)), 'nic se nezměnilo');
});
test('import je atomický: chyba při vkládání vrátí i smazané řádky', function () {
    [$h, $t] = tab();
    $t->uloz(null, 'A', 'p', '1');
    $h->db->q('CREATE TRIGGER blok BEFORE INSERT ON data_rows WHEN NEW.value = \'boom\' BEGIN SELECT RAISE(ABORT, \'boom\'); END');
    try { $t->importuj("A\tx\t1\nA\ty\tboom\n", true); ok(false, 'měla být výjimka'); } catch (PDOException $e) { ok(true); }
    eq([['A', 'p', '1']], obsah($t));
});

function zdroj(?callable $http = null): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    $v = new Vzdalene($h->db, $h->protokol, 'TWB', $http ?? fn() => [200, '{"a":1}'], fn() => 1_800_000_000);
    return [$h, $v];
}
test('zdroj: založení hned stahuje, kontrola názvu a adresy, mez intervalu', function () {
    [$h, $v] = zdroj();
    $r = $v->uloz(null, ' Allwyn ', ' https://x.cz/a.json ', 1);
    eq([true, null], [$r['ok'], $r['error']]);
    $s = $v->podleNazvu('allwyn');
    eq(['Allwyn', 'https://x.cz/a.json', 5, 'json', 1, '{"a":1}'], [$s['name'], $s['url'], (int)$s['interval_min'], $s['format'], (int)$s['csv_header'], $s['payload']]);
    eq(1_800_000_000, (int)$s['created_at']);
    $v->uloz(null, 'B', 'http://x.cz/b.csv', 99999, 'csv', false);
    $b = $v->podleNazvu('B');
    eq([1440, 'csv', 0], [(int)$b['interval_min'], $b['format'], (int)$b['csv_header']]);
    foreach ([['', 'https://x'], [str_repeat('a', 61), 'https://x'], ['a.b', 'https://x'], ['a:b', 'https://x'], ['a{b', 'https://x'], ['Datum', 'https://x'], ['ALLWYN', 'https://x'], ['ok', 'ftp://x'], ['ok2', 'x.cz']] as [$n, $u]) {
        try { $v->uloz(null, $n, $u); ok(false, "mělo selhat: $n $u"); } catch (InvalidArgumentException $e) { ok(true); }
    }
    eq(2, count($v->vsechny(true)));
});
test('zdroj: úprava smaže stažená data jen při změně adresy nebo formátu; vlastní název lze ponechat', function () {
    $pocet = 0;
    [$h, $v] = zdroj(function () use (&$pocet) { $pocet++; return [200, '{"a":' . $pocet . '}']; });
    $id = $v->uloz(null, 'Z', 'https://x/z.json')['id'];
    eq('{"a":1}', $v->podleNazvu('Z')['payload']);
    $v->uloz($id, 'Z', 'https://x/z.json', 30);   // stejný název a adresa – jen interval
    eq(30, (int)$v->podleNazvu('Z')['interval_min']);
    $h->db->q('UPDATE sources SET hash = ? WHERE id = ?', ['ZACHOVAT', $id]);
    $v->uloz($id, 'Z2', 'https://x/z.json', 30);
    eq('Z2', $v->podleNazvu('Z2')['name']);
    $v->uloz($id, 'Z2', 'https://x/jina.json', 30);
    eq(['https://x/jina.json', '{"a":4}'], [$v->podleNazvu('Z2')['url'], $v->podleNazvu('Z2')['payload']]);
});
test('zdroj: chyba stažení se vrátí, zdroj zůstane uložený; smazání', function () {
    [$h, $v] = zdroj(fn() => [404, '{"error":"Nenalezeno"}']);
    $r = $v->uloz(null, 'Chyba', 'https://x/c.json');
    eq([false, 'HTTP 404: Nenalezeno'], [$r['ok'], $r['error']]);
    ok($v->existuje('chyba'));
    $v->smaz($r['id']);
    ok(!$v->existuje('chyba'), 'po smazání zmizí i z mezipaměti');
    eq(0, count($v->vsechny(true)));
});

test('zdroj: změna adresy, formátu nebo záhlaví CSV zahodí starou chybu, takže nová chyba se znovu zapíše do protokolu', function () {
    foreach ([['url', ['https://x/jina.json', 'json', true]], ['format', ['https://x/z.json', 'csv', true]], ['hlavicka', ['https://x/z.json', 'json', false]], ['nic', ['https://x/z.json', 'json', true]]] as [$co, [$url, $fmt, $hl]]) {
        [$h, $v] = zdroj(fn() => [500, '']);
        $id = $v->uloz(null, 'Z', 'https://x/z.json')['id'];
        eq(1, count($h->protokol->zaznamy), 'první chyba');
        $v->uloz($id, 'Z', $url, 15, $fmt, $hl);
        eq($co === 'nic' ? 1 : 2, count($h->protokol->zaznamy), "po změně: $co");
    }
});
test('import: v chybové zprávě jsou nejvýš první tři chyby', function () {
    [, $t] = tab();
    try { $t->importuj("a\nb\nc\nd\n"); ok(false); } catch (InvalidArgumentException $e) {
        eq('Nic k vložení – řádek 1: očekávám 3 sloupce (Skupina, Parametr, Hodnota); řádek 2: očekávám 3 sloupce (Skupina, Parametr, Hodnota); řádek 3: očekávám 3 sloupce (Skupina, Parametr, Hodnota)', $e->getMessage());
    }
});
