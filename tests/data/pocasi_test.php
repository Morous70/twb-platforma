<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\Migrace as JadroMigrace;
use TWB\Data\{Predpoved, Migrace, Pocasi};

date_default_timezone_set('Europe/Prague');
const KLIC = 'TAJNYKLIC';
const MISTO = 'Praha,CZ';

function ow_aktualni(float $teplota = 4.6, string $nazev = 'Praha'): array
{
    return ['name' => $nazev, 'dt' => gmmktime(10, 0, 0, 11, 21, 2026), 'timezone' => 3600, 'weather' => [['description' => 'polojasno', 'icon' => '02d']],
        'main' => ['temp' => $teplota, 'feels_like' => 2.2, 'humidity' => 71], 'wind' => ['speed' => 3.6]];
}
function ow_predpoved(): array
{
    $list = [];
    $z = gmmktime(12, 0, 0, 11, 21, 2026);
    for ($i = 0; $i < 40; $i++) {
        $t = 3 + ($i % 8) * 1.25 - intdiv($i, 8) * 0.5;
        $list[] = ['dt' => $z + $i * 10800, 'main' => ['temp' => $t, 'temp_min' => $t - 0.7, 'temp_max' => $t + 0.9], 'weather' => [['description' => $i % 3 ? 'oblačno' : 'slabý déšť', 'icon' => ($i % 8 > 4 ? '10n' : '04d')]]];
    }
    return ['city' => ['timezone' => 3600], 'list' => $list];
}
function prispevky(): array
{
    return [['Venku je {{Počasí:teplota}} °C a v Brně {{ počasí : Brno.zítra.max }}', '', 'draft'], ['{{Počasí:Ostrava.den2.popis}}', 'a {{Počasí:49.9884,17.4637.misto}}', 'scheduled'], ['{{Počasí:Plzeň.popis}}', '', 'published'],
        ['{{Počasí:neznamepole}} {{Počasí:Brno,CZ.pozitri.min}}', '', 'failed']];
}
function radkyDb(): array
{
    $ted = time();
    $data = fn(string $m) => json_encode(['place' => $m, 'at' => $ted - 100] + ['name' => $m, 'now' => ['temp' => 9.5, 'feels' => 8.4, 'desc' => 'jasno', 'icon' => '01d', 'humidity' => 55, 'wind' => 1.4],
        'days' => [['date' => date('Y-m-d'), 'min' => 5.5, 'max' => 12.5, 'desc' => 'jasno', 'icon' => '01d'], ['date' => date('Y-m-d', strtotime('+1 day')), 'min' => -0.4, 'max' => 0.4, 'desc' => 'sníh', 'icon' => '13d']]], JSON_UNESCAPED_UNICODE);
    return [
        ['brno', 'Brno', $data('Brno'), $ted - 3600, $ted - 3600, null],
        ['ostrava', 'Ostrava', $data('Ostrava'), $ted - 3600, $ted - 7200, 'HTTP 500'],
        ['plzeň', 'Plzeň', $data('Plzeň'), $ted - 60, $ted - 60, null],
        ['stary', 'Stary', $data('Stary'), $ted - 172800, $ted - 172800, null],
        ['chybny', 'Chybny', null, $ted - 7200, null, 'Místo neznámé'],
    ];
}
function ok2(): array { return [[200, json_encode(ow_aktualni())], [200, json_encode(ow_predpoved())]]; }

/** @return list<array{fn: string, args: array, http?: list}> */
function volani(): array
{
    $v = [];
    foreach (['49.9884,17.4637', ' 49.9884 ; 17.4637 ', '91,10', '10,181', '-45.5,-170', 'Praha,CZ', '1,2,3', '49,5'] as $m) { $v[] = ['souradnice', ['m' => $m]]; $v[] = ['klic', ['m' => $m]]; }
    foreach (['  Praha ,  CZ ', 'PRAHA,cz', str_repeat('ž', 150), '', "Nové   Město"] as $m) { $v[] = ['klic', ['m' => $m]]; $v[] = ['misto', ['m' => $m]]; }
    foreach (['teplota', 'Teplota', 'pocitova', 'popis', 'vlhkost', 'vitr', 'misto', 'cas', 'Brno.teplota', 'Brno.zítra.max', 'zitra.min', 'dnes.popis', 'den3.datum', 'den6.den', 'den7.max', 'Brno,CZ.pozitri.max', 'a.b.dnes.max', 'Praha.neco', '', '  ', '.', 'zítra', 'max', 'Nové Město.teplota', 'dnes'] as $a) $v[] = ['rozbor', ['arg' => $a]];
    // bez dat a bez klíče se sem nesahá; skutečné stahování
    $v[] = ['mista', []];
    foreach (['teplota', 'pocitova', 'popis', 'vlhkost', 'vitr', 'misto', 'cas', 'dnes.max', 'dnes.min', 'zitra.max', 'zitra.min', 'zítra.popis', 'pozitri.den', 'den2.datum', 'den4.max', 'den6.max', 'neznamepole', 'dnes.den'] as $i => $a) {
        foreach (['2026-11-21 10:00:00', '2026-11-22 09:00:00', '2026-11-28 09:00:00'] as $t) $v[] = ['hodnota', ['arg' => $a, 't' => $t] , 'http' => $i === 0 && $t === '2026-11-21 10:00:00' ? ok2() : []];
    }
    $v[] = ['hodnota', ['arg' => '49.9884,17.4637.teplota', 't' => '2026-11-21 10:00:00'], 'http' => ok2()];
    $v[] = ['hodnota', ['arg' => '49.9884,17.4637.misto', 't' => '2026-11-21 10:00:00']];
    $v[] = ['hodnota', ['arg' => 'Brno.zítra.max', 't' => date('Y-m-d') . ' 10:00:00'], 'http' => ok2()];
    $v[] = ['hodnota', ['arg' => 'Brno.popis', 't' => '2026-11-21 10:00:00']];
    $v[] = ['hodnota', ['arg' => 'Ostrava.teplota', 't' => '2026-11-21 10:00:00'], 'http' => [[401, '{"message":"Invalid API key"}']]];
    $v[] = ['hodnota', ['arg' => 'Ostrava.popis', 't' => '2026-11-21 10:00:00']];
    $v[] = ['hodnota', ['arg' => 'Plzeň.popis', 't' => '2026-11-21 10:00:00']];
    $v[] = ['hodnota', ['arg' => 'Chybny.popis', 't' => '2026-11-21 10:00:00'], 'http' => [[404, '{"message":"city not found"}']]];
    $v[] = ['ziskej', ['m' => 'Chybny']];
    $v[] = ['ziskej', ['m' => 'Nikdyneslo'], 'http' => [['vyjimka' => 'Služba počasí neodpovídá: timeout.']]];
    $v[] = ['ziskej', ['m' => 'Plzeň', 'fetch' => false]];
    // přímé stahování a přechody stavů chyba / obnova
    $v[] = ['stahni', ['m' => 'Brno'], 'http' => [[404, '{"message":"city not found"}']]];
    $v[] = ['stahni', ['m' => 'Brno'], 'http' => [[429, '']]];
    $v[] = ['stahni', ['m' => 'Brno'], 'http' => ok2()];
    $v[] = ['stahni', ['m' => 'Brno'], 'http' => [[200, json_encode(ow_aktualni(-0.4, 'Brno'))], [503, '{"message":"Údržba"}']]];
    $v[] = ['stahni', ['m' => 'Brno'], 'http' => [[500, 'nejson']]];
    $v[] = ['stahni', ['m' => 'Nové Město'], 'http' => [[200, 'nejson']]];
    $v[] = ['stahni', ['m' => 'Nové  město'], 'http' => ok2()];
    $v[] = ['stahni', ['m' => ''], 'http' => ok2()];
    $v[] = ['stahni', ['m' => '50.1,14.4'], 'http' => [[200, json_encode(ow_aktualni(3.5, 'Praha 6'))], [200, json_encode(['list' => []])]]];
    $v[] = ['stahni', ['m' => '50.1,14.4'], 'http' => [[200, json_encode(['main' => ['temp' => 1.0], 'weather' => []])], [200, json_encode(ow_predpoved())]]];
    $v[] = ['hodnota', ['arg' => '50.1,14.4.misto', 't' => '2026-11-21 10:00:00']];
    $v[] = ['stahni', ['m' => 'Jeseník'], 'http' => [['vyjimka' => 'Služba počasí neodpovídá.']]];
    $v[] = ['obnov', [], 'http' => array_merge(ok2(), ok2(), ok2(), ok2(), ok2(), ok2(), ok2())];
    $v[] = ['mista', []];
    return array_map(fn($x) => ['fn' => $x[0], 'args' => $x[1] ?: new stdClass()] + (isset($x['http']) ? ['http' => $x['http']] : []), $v);
}

function modul(array $v, ?string $klic = KLIC): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    foreach (radkyDb() as $r) $h->db->q('INSERT INTO weather VALUES (?,?,?,?,?,?)', $r);
    $fronta = new ArrayObject(); $adresy = new ArrayObject();
    $p = new Predpoved($h->db, $h->protokol, fn() => $klic ?? '', fn() => MISTO,
        fn() => array_merge(...array_map(fn($x) => Predpoved::mistaVTextu($x[0] . ' ' . $x[1]), array_filter(prispevky(), fn($x) => in_array($x[2], ['draft', 'scheduled', 'failed', 'partial'], true)))),
        function (string $url) use ($fronta, $adresy) {
            $adresy[] = $url;
            $r = $fronta->count() ? $fronta->offsetGet(0) : null;
            if ($fronta->count()) { $a = $fronta->getArrayCopy(); array_shift($a); $fronta->exchangeArray($a); }
            if (isset($r['vyjimka'])) throw new RuntimeException($r['vyjimka']);
            return [$r[0], $r[1]];
        });
    return [$h, $p, $fronta, $adresy];
}
function stavM($h): array
{
    return array_map(fn($r) => [$r['place_key'], $r['place'], $r['payload'], $r['fetched_at'] > 0, $r['ok_at'] > 0, $r['error']], $h->db->q('SELECT * FROM weather ORDER BY place_key')->fetchAll());
}
/** Čas stažení je v datech a liší se o sekundy: porovnává se bez něj. */
function bezCasu(mixed $x): mixed { return is_string($x) ? preg_replace('/"at":\d+/', '"at":0', $x) : (is_array($x) ? array_map('bezCasu', $x) : $x); }

test('shoda s originálem (Social Planner 1.9.2): počasí – místa, proměnné, stahování OpenWeather, chyby, obnova, mezipaměť', function () {
    $v = volani();
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/original/harness-pocasi.php');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $texty = prispevky();
    fwrite($pipes[0], json_encode(['tz' => 'Europe/Prague', 'klic' => KLIC, 'misto' => MISTO, 'prispevky' => array_map(fn($p) => [$p[0], $p[1], $p[2]], $texty), 'radky' => radkyDb(), 'volani' => $v], JSON_PARTIAL_OUTPUT_ON_ERROR));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $o = json_decode($out, true);
    if (!is_array($o)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 700));

    [$h, $p, $fronta, $adresy] = modul($v);
    $spatne = [];
    foreach ($v as $i => $c) {
        $a = (array)$c['args'];
        $fronta->exchangeArray($c['http'] ?? []);
        $adresy->exchangeArray([]);
        $h->protokol->zaznamy = [];
        $r = null; $chyba = null;
        try {
            $r = match ($c['fn']) {
                'souradnice' => Predpoved::souradnice($a['m']),
                'klic' => Predpoved::klicMista($a['m']),
                'misto' => $p->misto($a['m']),
                'rozbor' => Predpoved::rozbor($a['arg']),
                'hodnota' => $p->hodnota($a['arg'], new DateTimeImmutable($a['t'])),
                'ziskej' => $p->ziskej($a['m'], $a['fetch'] ?? true),
                'stahni' => $p->stahni($a['m']),
                'obnov' => $p->obnovPo(),
                'mista' => $p->pouzitaMista(),
            };
        } catch (RuntimeException $e) { $chyba = $e->getMessage(); }
        $ocek = $o[$i];
        if (isset($ocek['chyba']) || $chyba !== null) { if (($ocek['chyba'] ?? null) !== $chyba) $spatne[] = "#$i {$c['fn']}: výjimka čekáno " . json_encode($ocek['chyba'] ?? null, JSON_UNESCAPED_UNICODE) . ', je ' . json_encode($chyba, JSON_UNESCAPED_UNICODE); continue; }
        $uprav = fn($z) => preg_replace('/\. (Příspěvky|Hodnoty) .*$/u', '.', $z);
        $zaznamy = array_map(fn($z) => $uprav($z[1]), $h->protokol->zaznamy);
        $stav = in_array($c['fn'], ['stahni', 'obnov', 'hodnota', 'ziskej'], true) ? stavM($h) : null;
        $ocekStav = $ocek['stav'];
        if ($stav === null) $ocekStav = null;
        $porovnej = [[bezCasu($ocek['ok']), bezCasu($r), 'výsledek'], [bezCasu($ocekStav), bezCasu($stav), 'stav tabulky'], [array_map($uprav, $ocek['log']), $zaznamy, 'protokol'], [$ocek['url'], $adresy->getArrayCopy(), 'adresy']];
        foreach ($porovnej as [$x, $y, $co]) if (json_encode($x, JSON_UNESCAPED_UNICODE) !== json_encode($y, JSON_UNESCAPED_UNICODE)) $spatne[] = "#$i {$c['fn']} " . json_encode($a, JSON_UNESCAPED_UNICODE) . " $co: čekáno " . substr(json_encode($x, JSON_UNESCAPED_UNICODE), 0, 260) . ', je ' . substr(json_encode($y, JSON_UNESCAPED_UNICODE), 0, 260);
    }
    ok(!$spatne, count($spatne) . " rozdílů, např.:\n    " . implode("\n    ", array_slice($spatne, 0, 5)));
    ok(count($v) > 100, 'dost vstupů: ' . count($v));
});

function holyPocasi(callable $http, int $ted, ?callable $mista = null): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    return [$h, new Predpoved($h->db, $h->protokol, fn() => KLIC, fn() => 'Praha', $mista, $http, fn() => $ted)];
}
test('souřadnice: hranice 90 a 180 stupňů platí', function () {
    eq([90.0, 180.0], Predpoved::souradnice('90,180'));
    eq([-90.0, -180.0], Predpoved::souradnice('-90,-180'));
    eq(null, Predpoved::souradnice('90.5,10'));
    eq(null, Predpoved::souradnice('10,180.5'));
});
test('časování: data starší než interval minus 30 s se stahují znovu; nepoužívaná místa se mažou po 24 hodinách', function () {
    $ted = 1_800_000_000;
    [$h, $p] = holyPocasi(fn() => [500, ''], $ted);
    ok($p->jeCasStahnout(['fetched_at' => $ted - 870, 'error' => null]));
    ok(!$p->jeCasStahnout(['fetched_at' => $ted - 869, 'error' => null]));
    ok($p->jeCasStahnout(['fetched_at' => $ted - 870, 'error' => 'x']));
    ok(!$p->jeCasStahnout(['fetched_at' => $ted - 869, 'error' => 'x']));
    ok($p->jeCasStahnout(null));
    foreach ([['stary', $ted - 86401], ['hranice', $ted - 86400]] as [$k, $f]) $h->db->q('INSERT INTO weather (place_key, place, payload, fetched_at, ok_at, error) VALUES (?,?,NULL,?,NULL,NULL)', [$k, $k, $f]);
    $p->obnovPo();
    eq(['hranice', 'praha'], array_column($h->db->q('SELECT place_key FROM weather ORDER BY place_key')->fetchAll(), 'place_key'), 'výchozí místo zůstává, stará nepoužitá řádka zmizela');
});
test('předpověď dne bere ikonu a popis z doby nejblíž třinácté hodině, noční ikona se mění na denní', function () {
    $z = gmmktime(0, 0, 0, 11, 22, 2026);
    $fc = ['city' => ['timezone' => 0], 'list' => [
        ['dt' => $z + 11 * 3600, 'main' => ['temp' => 1, 'temp_min' => 0, 'temp_max' => 2], 'weather' => [['description' => 'ráno', 'icon' => '10n']]],
        ['dt' => $z + 14 * 3600, 'main' => ['temp' => 5, 'temp_min' => 4, 'temp_max' => 6], 'weather' => [['description' => 'odpoledne', 'icon' => '03n']]],
    ]];
    $odp = [[200, json_encode(ow_aktualni())], [200, json_encode($fc)]];
    [$h, $p] = holyPocasi(function () use (&$odp) { return array_shift($odp); }, 1_800_000_000);
    $r = $p->stahni('Praha');
    $den = $r['data']['days'][1];
    eq(['2026-11-22', 'odpoledne', '03d', 0.0, 6.0], [$den['date'], $den['desc'], $den['icon'], $den['min'], $den['max']]);
    eq('Odpoledne', $p->hodnota('zitra.popis', new DateTimeImmutable('2026-11-21 10:00')));
    eq('6', $p->hodnota('zitra.max', new DateTimeImmutable('2026-11-21 10:00')));
});
test('protokol: výpadek po úspěšném stažení je varování, obnova informace, další pokus nic nepřidá', function () {
    $odp = [...ok2(), [500, ''], [500, ''], ...ok2()];
    [$h, $p] = holyPocasi(function () use (&$odp) { return array_shift($odp); }, 1_800_000_000);
    $p->stahni('Praha');
    eq([], $h->protokol->zaznamy);
    $p->stahni('Praha');
    eq(['warn'], array_column($h->protokol->zaznamy, 0));
    $p->stahni('Praha');
    eq(1, count($h->protokol->zaznamy));
    $p->stahni('Praha');
    eq(['warn', 'info'], array_column($h->protokol->zaznamy, 0));
});
test('bez API klíče nic nestahuje a hlásí chybu; adresy ikon', function () {
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    $p = new Predpoved($h->db, $h->protokol, fn() => '  ', fn() => 'Praha', null, function () { throw new LogicException('nesmí se volat'); });
    eq([null, 'Chybí API klíč (Proměnné a data → Počasí).'], $p->ziskej('Brno'));
    eq('', $p->hodnota('teplota', new DateTimeImmutable()));
    eq(null, $p->hodnota('blbost', new DateTimeImmutable()));
    eq('https://openweathermap.org/img/wn/04d@2x.png', Predpoved::adresaIkony('04d'));
    ok($p instanceof Pocasi);
});
