<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\Migrace as JadroMigrace;
use TWB\Data\{Format, Tabulka, Promenne, Zdroje, Pocasi, Migrace};

const TZ = 'Europe/Prague';
date_default_timezone_set(TZ);

function radky(): array
{
    return [
        ['Sportka', '2026-11-19', '30 000 000'], ['Sportka', '26. 11. 2026', '45 500 000'], ['Sportka', '2026-12-03 20:00', '51 000 000'],
        ['Sportka', '2026-11-12', '12 000 000'], ['Sportka', 'poznámka', 'text'],
        ['Ceník', 'Káva', '59'], ['Ceník', 'Čaj', '49,5'], ['Jedna', 'x', 'jediná hodnota'], ['Prázdná', '2020-01-01', ''],
        ['Čeština', 'Žluťoučký', 'kůň'],
    ];
}
function uzivatelske(): array
{
    return [
        'tip_tydne' => ['name' => 'tip_tydne', 'type' => 'text', 'value' => 'Pijte vodu'],
        'barvy' => ['name' => 'barvy', 'type' => 'list', 'value' => "červená\n\n modrá \nzelená"],
        'prazdny' => ['name' => 'prazdny', 'type' => 'list', 'value' => ''],
    ];
}
const ZDROJE = ['allwyn' => ['sportka.superjackpot' => '125000000', 'sportka.tah1' => '22 14 27 42 9 5', 'sportka.tah1.2' => '14']];
const POCASI = ['teplota' => '7', 'zítra.max' => '11', 'Brno.popis' => 'jasno'];

final class ZdrojeTest implements Zdroje
{
    public function existuje(string $n): bool { return isset(ZDROJE[mb_strtolower($n)]); }
    public function hodnota(string $n, string $c): ?string { return ZDROJE[mb_strtolower($n)][$c] ?? null; }
}
final class PocasiTest implements Pocasi
{
    public function hodnota(string $arg, DateTimeImmutable $t): ?string { return POCASI[$arg] ?? null; }
}
function promenne(): Promenne
{
    static $p = null;
    if ($p) return $p;
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    foreach (radky() as $r) $h->db->q('INSERT INTO data_rows (grp, param, value) VALUES (?,?,?)', $r);
    return $p = new Promenne(new Tabulka($h->db), 'uzivatelske_fn', new ZdrojeTest(), new PocasiTest());
}

/** Vstupy pro porovnání. @return list<array{fn: string, args: array}> */
function volani(): array
{
    $v = [];
    $casy = ['2026-11-21 10:00:00', '2026-12-24 08:30:00', '2027-01-01 00:00:00', '2026-03-29 23:59:59', '2026-11-26 12:00:00', '2024-02-29 18:05:00'];
    foreach (['Čeština', '  Žluťoučký KŮŇ ', 'ŠěŘ', ''] as $s) $v[] = ['klic', ['s' => $s]];
    foreach (['2026-11-26', '2026-11-26 20:00', '2026-11-26T20:00:00+01:00', '26. 11. 2026', '1.2.2026 20:00', '26.11.26', '2026-02-30', 'dnes', '', '2026-1-5'] as $s) $v[] = ['datumZTextu', ['s' => $s]];
    foreach ([-5, -1, 0, 1, 2, 4, 5, 34, 365] as $n) $v[] = ['odpocet', ['n' => $n]];
    foreach (['125 000 000', '1,5', '1.5', "1\u{00A0}000", '', 'abc', '-3', '1e3', ' 42 '] as $s) $v[] = ['naCislo', ['s' => $s]];
    foreach ([[1234567.891, '', 2], [0.5, '3', 0], [-0.0001, '', 2], [12, '0,00', 1], [12.3456, '0.000', 1], [1e9, 'x', 0]] as [$n, $arg, $d]) $v[] = ['cisloFormat', ['n' => $n, 'arg' => $arg, 'def' => $d]];
    foreach ($casy as $t) foreach (['', 'kratke', 'bezmezer', 'nuly', 'den', 'den-plne', 'slovem', 'dlouhe', 'den-slovem', 'den-dlouhe', 'dentydne', 'mesic-rok', 'D. MĚSÍCE RRRR', 'DEN DD/MM/RR', 'DN M. MESIC', 'neznámé', 'DEN'] as $f) $v[] = ['datum', ['t' => $t, 'fmt' => $f]];
    $filtry = [['velka'], ['mala'], ['prvni'], ['tis'], ['mil'], ['mil:2'], ['mil:0,00'], ['mld'], ['milionu'], ['milionu:0'], ['miliard'], ['cislo'], ['cislo:0'], ['cislo:0,00'], ['kc'], ['kc:0'],
        ['bezmezer'], ['pripona: Kč'], ['datum'], ['datum:slovem'], ['datum:D. M.'], ['odpocet'], ['vychozi:nic'], ['cisla'], ['cisla:,'], ['mil', 'velka'], ['neznamy'], ['cislo', 'pripona:x', 'vychozi:y']];
    foreach (['125000000', '1 500 000', '2000000', '3000000', '4 500 000', '999', '', 'ahoj text', '2026-12-24', '26. 11. 2026', '22 14  27', '1 000 000 000', '5000000000', '-2500000', '1234,5'] as $val) foreach ($filtry as $f) $v[] = ['filtry', ['v' => $val, 'filtry' => $f, 't' => '2026-11-21 10:00:00']];
    $nazvy = [['datum', ''], ['datum', '+1'], ['datum', '-3'], ['den', ''], ['Den', '+2'], ['mesic', ''], ['rok', '+400'], ['tyden', ''], ['cas', ''], ['hodina', ''], ['cislodne', ''], ['cislo', ''],
        ['svatek', ''], ['svatek', '+1'], ['svatek', '-1'], ['odpocet', '24.12.2026'], ['odpocet', 'blbost'], ['dnu', '2026-12-24'], ['dnu', '2020-01-01'], ['dnu', 'x'],
        ['tip_tydne', ''], ['tip_tydne', 'x'], ['barvy', ''], ['prazdny', ''], ['TIP_TYDNE', ''], ['Allwyn', 'sportka.superjackpot'], ['allwyn', 'sportka.tah1.2'], ['Allwyn', 'neni'], ['Allwyn', ''],
        ['Počasí', 'teplota'], ['pocasi', 'zítra.max'], ['Počasí', 'jinak'], ['Sportka.nejbližší', ''], ['Sportka.nejbližší.parametr', ''], ['Sportka.další', ''], ['Sportka.poslední', ''], ['Sportka.poslední.datum', ''], ['Sportka', '26.11.2026'], ['Sportka', '2026-12-03'],
        ['Sportka', 'poznámka'], ['Sportka', 'neni'], ['Sportka', ''], ['Sportka.neco', ''], ['Ceník', 'Káva'], ['ceník', 'ČAJ'], ['Ceník', 'caj'], ['Jedna', ''], ['Prázdná.nejbližší', ''], ['Prázdná.poslední', ''], ['Čeština', 'žluťoučký'], ['Nic', '']];
    foreach (['2026-11-21 10:00:00', '2026-12-03 09:00:00', '2027-01-01 00:00:00'] as $t) foreach ($nazvy as [$n, $a]) foreach ([0, 2, 3] as $i) {
        if ($i && !in_array($n, ['barvy', 'cislo', 'prazdny'], true)) continue;
        $v[] = ['hodnota', ['nazev' => $n, 'arg' => $a, 't' => $t, 'index' => $i]];
    }
    $texty = ['Dnes je {{datum}} a svátek má {{svatek}}.', '{{Sportka.nejbližší|mil}} Kč, termín {{Sportka.nejbližší.parametr|datum:den-slovem}}', '{{Allwyn:sportka.superjackpot|milionu:0}} / {{Allwyn:sportka.tah1|cisla:,}}',
        '{{neznama}} {{ neznama2 |velka}} {{neznama3|vychozi:nic}}', '{{ datum : +1 | velka }}', '{{barvy}}, {{barvy}} #{{cislo}}', 'bez proměnných', '{ jedna } {{dvě}}', '{{Ceník:Káva|kc}} a {{Ceník:Čaj|cislo:0,00}}',
        '{{Počasí:teplota|pripona:°C}}', '{{odpocet:24.12.2026}} / {{dnu:24.12.2026}}', '{{{datum}}} {{datum}}}', '{{}} {{ }} {{|velka}}', "{{datum\n}}", '{{Sportka.poslední|bezmezer}}'];
    foreach (['2026-11-21 10:00:00', '2026-12-24 08:30:00'] as $t) foreach ($texty as $x) foreach ([false, true] as $s) $v[] = ['vyres', ['text' => $x, 't' => $t, 'index' => 1, 'slide' => $s]];
    foreach (['{datum}', '{ svatek }', '{x}', '{Ceník:Káva|kc} a {{datum}}', '{{{datum}}}', '{datum:+1|velka} {cislo}', '{a}{b}', '{Allwyn:sportka.tah1.2}'] as $x) $v[] = ['vyres', ['text' => $x, 't' => '2026-11-21 10:00:00', 'index' => 0, 'slide' => true]];
    $podm = [[], [['l' => '', 'op' => '=', 'r' => 'x']], [['l' => '{cislo}', 'op' => '=', 'r' => '1']], [['l' => 'den', 'op' => '=', 'r' => 'sobota']], [['l' => 'Sportka.nejbližší', 'op' => '>', 'r' => '40000000']],
        [['l' => 'Sportka.nejbližší', 'op' => '>', 'r' => '50 000 000']], [['l' => 'datum', 'op' => '<', 'r' => '24.12.2026']], [['l' => 'datum', 'op' => '>=', 'r' => '2026-11-21']], [['l' => 'tip_tydne', 'op' => 'contains', 'r' => 'VODU']],
        [['l' => '{prazdne}', 'op' => 'empty', 'r' => '']], [['l' => 'Prázdná', 'op' => 'empty', 'r' => '']], [['l' => 'Allwyn:sportka.superjackpot', 'op' => '>', 'r' => '100000000'], ['l' => 'den', 'op' => '!=', 'r' => 'pondělí']],
        [['l' => 'svatek', 'op' => 'notempty', 'r' => '']], [['l' => 'ahoj', 'op' => '=', 'r' => 'AHOJ']], [['l' => 'a', 'op' => '<', 'r' => 'b']], [['l' => '5', 'op' => 'neznamy', 'r' => '5']], [['l' => 'Počasí:teplota', 'op' => '<=', 'r' => '7']],
        [['l' => 'neexistuje', 'op' => '=', 'r' => 'neexistuje']], [['l' => '{{datum}}', 'op' => '=', 'r' => '{datum}']]];
    foreach (['2026-11-21 10:00:00', '2026-12-04 10:00:00'] as $t) foreach ($podm as $p) $v[] = ['splneno', ['podminky' => $p, 't' => $t]];
    foreach (['2026-11-21 10:00:00', '2026-12-04 10:00:00', '2030-01-01 00:00:00'] as $t) foreach ([['Sportka.nejbližší', ''], ['Sportka.další', ''], ['Sportka.poslední', ''], ['Sportka.poslední.parametr', ''], ['Sportka.další.parametr', ''], ['Sportka', '26.11.2026'], ['Sportka', 'Poznámka'], ['Ceník', ''], ['Ceník', 'neni'], ['Jedna', ''], ['neni', ''], ['Sportka.xyz', '']] as [$n, $a]) $v[] = ['tabulka', ['nazev' => $n, 'arg' => $a, 't' => $t]];
    foreach ([['Skupina', 'p', 'v'], ['  Sk ', ' 2026-01-01 ', ' 1 234,50 '], ['', 'p', 'v'], ['g', '', 'v'], ['a.b', 'p', 'v'], ['a:b', 'p', 'v'], ['a|b', 'p', 'v'], ['{a}', 'p', 'v'], ['Datum', 'p', 'v'], ['DEN', 'p', 'v'], ['Počasí', 'p', 'v'], ['pocasi', 'p', 'v'], ['Cislo', 'p', 'v'],
        ['g', 'p', '12 000 000'], ['g', 'p', '-5,5'], ['g', 'p', '1.234'], ['g', 'p', '007'], ['g', 'p', '0,00001'], ['g', 'p', '1 2 3'], [str_repeat('á', 150), str_repeat('p', 300), str_repeat('v', 3000)], [null, 1, 2.5]] as [$g, $p, $x]) $v[] = ['uklid', ['g' => $g, 'p' => $p, 'v' => $x]];
    foreach ([['10', '=', '10,0'], ['abc', '=', 'ABC'], ['2026-11-21', '<', '22. 11. 2026'], ['5', '>', '10'], ['b', '>', 'a'], ['', 'empty', ''], [' ', 'empty', ''], ['x', 'notempty', ''], ['Čaj', 'contains', 'čaj'], ['Čaj', 'contains', ''], ['1', '!=', '1'], ['1', '??', '1'], ['10', '>=', '10'], ['9', '<=', '10']] as [$a, $op, $b]) $v[] = ['porovnej', ['a' => $a, 'op' => $op, 'b' => $b]];
    $v[] = ['skupiny', []];
    return array_map(fn($x) => ['fn' => $x[0], 'args' => $x[1] ?: new stdClass()], $v);
}

/** Totéž v modulu. */
function modul(array $c): mixed
{
    $a = (array)$c['args'];
    $t = isset($a['t']) ? new DateTimeImmutable($a['t']) : null;
    $p = promenne();
    $tab = (fn() => $this->tabulka)->call($p);
    try {
        return match ($c['fn']) {
            'klic' => Format::klic($a['s']),
            'datumZTextu' => Format::datumZTextu($a['s'])?->format('Y-m-d'),
            'odpocet' => Format::odpocet($a['n']),
            'naCislo' => Format::naCislo($a['s']),
            'cisloFormat' => Format::cisloFormat($a['n'], $a['arg'], $a['def']),
            'datum' => Format::datum($t, $a['fmt']),
            'filtry' => Format::filtry($a['v'], $a['filtry'], $t),
            'hodnota' => $p->hodnota($a['nazev'], $a['arg'], $t, $a['index']),
            'vyres' => $p->vyres($a['text'], $t, $a['index'], $a['slide']),
            'splneno' => $p->splneno($a['podminky'], $t),
            'tabulka' => $tab->hodnota($a['nazev'], $a['arg'], $t),
            'uklid' => Tabulka::uklidRadek($a['g'], $a['p'], $a['v']),
            'porovnej' => Promenne::porovnej($a['a'], $a['op'], $a['b']),
            'skupiny' => $tab->skupiny(),
        };
    } catch (InvalidArgumentException $e) { return ['__chyba' => $e->getMessage()]; }
}
function uzivatelske_fn(): array { return uzivatelske(); }

function originaly(array $volani): array
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/original/harness.php');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['rows' => radky(), 'user' => array_values(uzivatelske()), 'zdroje' => ZDROJE, 'pocasi' => POCASI, 'tz' => TZ, 'volani' => $volani], JSON_PARTIAL_OUTPUT_ON_ERROR));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $j = json_decode($out, true);
    if (!is_array($j)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 500));
    return $j;
}

test('shoda s originálem (Social Planner 1.9.2): formáty, proměnné, tabulka dat, filtry, podmínky', function () {
    $v = volani();
    $o = originaly($v);
    eq(count($v), count($o));
    $spatne = [];
    foreach ($v as $i => $c) {
        $m = modul($c);
        $r = $o[$i]['chyba'] ?? null;
        $ocek = $r !== null ? ['__chyba' => $r] : $o[$i]['ok'];
        // číslo vs. celé číslo (JSON) – porovnání jako text
        if (json_encode($ocek) !== json_encode($m) && !(is_float($m) && abs($m - (float)$ocek) < 1e-9)) $spatne[] = $c['fn'] . json_encode($c['args'], JSON_UNESCAPED_UNICODE) . ' → čekáno ' . json_encode($ocek, JSON_UNESCAPED_UNICODE) . ', je ' . json_encode($m, JSON_UNESCAPED_UNICODE);
    }
    ok(!$spatne, count($spatne) . " rozdílů, např.:\n    " . implode("\n    ", array_slice($spatne, 0, 6)));
    ok(count($v) > 800, 'dost vstupů: ' . count($v));
});

test('zdroj se ptá jen s neprázdnou cestou; bez zdrojů a počasí jsou jejich proměnné neznámé', function () {
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    $vse = new class implements Zdroje { public function existuje(string $n): bool { return strtolower($n) === 'allwyn'; } public function hodnota(string $n, string $c): ?string { return 'ZDROJ'; } };
    $p = new Promenne(new Tabulka($h->db), fn() => [], $vse);
    eq(null, $p->hodnota('Allwyn', '', new DateTimeImmutable()));
    eq('ZDROJ', $p->hodnota('Allwyn', 'a.b', new DateTimeImmutable()));
    eq(null, $p->hodnota('Počasí', 'teplota', new DateTimeImmutable()));
    $holy = new Promenne(new Tabulka($h->db), fn() => []);
    eq(['{{Allwyn:x}}', ['{{Allwyn:x}}']], $holy->vyres('{{Allwyn:x}}', new DateTimeImmutable()));
    eq('1', $holy->hodnota('cislo', '', new DateTimeImmutable()));
    eq('3', $holy->hodnota('cislo', '', new DateTimeImmutable(), 2));
});
test('uložení a čtení tabulky dat: vlastní název tabulky a neplatný název se odmítne', function () {
    $h = novyHost();
    throws(fn() => new Tabulka($h->db, 'data rows; DROP'), '');
    $h->db->q('CREATE TABLE vlastni (id INTEGER PRIMARY KEY, grp TEXT, param TEXT, value TEXT)');
    $h->db->q("INSERT INTO vlastni (grp, param, value) VALUES ('X', 'a', '1')");
    eq(['X'], (new Tabulka($h->db, 'vlastni'))->skupiny());
});

test('napojení na slidy a html: stejný výsledek jako přímé volání (zápis {x} i {{x}})', function () {
    $p = promenne();
    $t = new DateTimeImmutable('2026-11-21 10:00:00');
    $s = new TWB\Data\ProSlidy($p); $h = new TWB\Data\ProHtml($p);
    ok($s instanceof TWB\Slidy\Promenne && $h instanceof TWB\Html\Promenne);
    eq($p->vyres('{datum} {{den}} {nic}', $t, 0, true), $s->vyres('{datum} {{den}} {nic}', $t));
    eq($s->vyres('{svatek}', $t), $h->vyres('{svatek}', $t));
    ok($s->splneno([['l' => 'den', 'op' => '=', 'r' => 'sobota']], $t));
    ok(!$s->splneno([['l' => 'den', 'op' => '=', 'r' => 'neděle']], $t));
});
