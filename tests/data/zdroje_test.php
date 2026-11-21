<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\{Migrace as JadroMigrace, ProtokolPamet};
use TWB\Data\{Hledani, Vzdalene, Zdroje, Migrace};

date_default_timezone_set('Europe/Prague');

function allwyn(): array
{
    return ['success' => true, 'games' => [
        ['game' => 'sportka', 'label' => 'Sportka', 'draw' => ['tah1' => [22, 14, 27, 42, 9, 5], 'tah1_d' => 3, 'superjackpot' => 125000000, 'datum' => '2026-11-26', 'sance' => '123456', 'cas' => '19:00', 'draw_id' => 4711]],
        ['game' => 'eurojackpot', 'label' => 'Eurojackpot', 'data' => ['jackpot' => 9.5e7, 'cisla' => [1, 2, 3, 4, 5], 'ok' => true, 'nic' => null, 'd' => 1.5, 'mala' => 0.000012]],
    ], 'meta' => ['count' => 2]];
}
const CSV_CENIK = "N\u{00E1}zev;Cena;Pozn\u{00E1}mka\nK\u{00E1}va;59;hork\u{00E1}\n\u{010C}aj;49,5;\nKakao;70;\"se \"\"\u{0161}lehačkou\"\"\"\n";
function csv_radky(): array { return Hledani::csvNaRadky(CSV_CENIK, true); }

function zdrojeRadky(): array
{
    $ted = time();
    $mk = fn(string $n, string $url, string $fmt, ?array $data, int $f, int $iv = 15, ?string $err = null) => [
        'name' => $n, 'url' => $url, 'format' => $fmt, 'csv_header' => 1, 'interval_min' => $iv,
        'payload' => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'hash' => $data === null ? null : md5(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'fetched_at' => $f, 'ok_at' => $f, 'error' => $err, 'created_at' => 1];
    return [
        $mk('Allwyn', 'https://api.example.cz/allwyn?key=TAJNE', 'json', allwyn(), $ted - 3600),
        $mk('Ceník', 'https://docs.google.com/spreadsheets/d/ABC123/edit#gid=7', 'csv', csv_radky(), $ted - 10),
        $mk('nový', 'https://x.example/n.json', 'json', null, 0, 5),
        $mk('Rozbitý', 'https://x.example/r.json', 'json', allwyn(), $ted - 7200, 15, 'HTTP 500'),
        $mk('Čerstvý', 'https://x.example/c.json', 'json', ['a' => 1], $ted - 800),
        $mk('Hluboký', 'https://x.example/h.json', 'json', ['a' => ['b' => ['c' => ['d' => ['e' => 'hluboko']]]]], $ted - 800),
        $mk('Seznam', 'https://x.example/s.json', 'json', [['name' => 'Alfa', 'v' => [1, 2]], ['name' => 'Beta', 'v' => 'b'], ['x' => ['y' => 'z']]], $ted - 800, 60),
    ];
}
function novaData(): array { $a = allwyn(); $a['games'][0]['draw']['superjackpot'] = 150000000; return $a; }

/** @return list<array{fn: string, args: array}> */
function volani(): array
{
    $v = [];
    foreach (['https://docs.google.com/spreadsheets/d/ABC-_1/edit#gid=5', 'https://docs.google.com/spreadsheets/d/ABC/edit', 'https://docs.google.com/spreadsheets/d/ABC/export?format=csv&gid=2', 'https://docs.google.com/spreadsheets/d/ABC/pub?output=csv', 'https://example.cz/data.csv', 'https://docs.google.com/spreadsheets/d/ABC/edit?gid=9&x=1'] as $u) $v[] = ['csvUrl', ['url' => $u]];
    foreach (['https://a.cz/x?key=TAJNE&b=1', 'https://a.cz/x?b=1&apikey=ABC#h', 'https://a.cz/x?token=1&appid=2', 'https://a.cz/x?keyboard=1'] as $u) $v[] = ['bezpecna', ['url' => $u]];
    $csv = ["a;b\n1;2\n", "a,b\n1,2\n", "a\tb\n1\t2", "a|b\n1|2", "\xEF\xBB\xBFx;y\n1;2", "N\xE1zev;Cena\nK\xE1va;59", '', "  \n ", '<!DOCTYPE html><html></html>', "\n\n", "a;b\n", "a;a;;b.c\n1;2;3;4\n5;6", "\"a;b\";c\n\"1;2\";3", "x;y\n\n\n1;2\n;\n3;4", "jedna;dve\n" . implode("\n", array_fill(0, 5100, '1;2')), "1;2\n3;4;5"];
    $csv[] = 'h' . implode(';h', range(1, 105)) . "\n" . implode(';', range(1, 105));
    foreach ($csv as $c) foreach ([true, false] as $h) $v[] = ['csvNaRadky', ['telo' => base64_encode($c), 'zahlavi' => $h]];
    $cesty = ['Allwyn' => ['sportka.superjackpot', 'sportka.tah1', 'sportka.tah1.2', 'sportka.tah1.9', 'sportka.draw.tah1', 'Sportka.Sance', 'eurojackpot.jackpot', 'eurojackpot.cisla', 'eurojackpot.ok', 'eurojackpot.nic', 'eurojackpot.d', 'eurojackpot.mala', 'eurojackpot', 'sportka', '1.draw.superjackpot', '2.jackpot', '3.jackpot', 'games.1.draw.datum', 'meta.count', 'count', 'neni', '', '.', 'sportka.draw_id', 'Eurojackpot.data.cisla.3', 'sportka.cas'],
        'ceník' => ['Káva.Cena', 'čaj.cena', '2.Cena', '3.Pozn%C3%A1mka', 'Kakao.Poznámka', '1', 'Káva', 'Neni.Cena', 'Káva.Poznámka'], 'nový' => ['a'], 'Rozbitý' => ['sportka.tah1'], 'Seznam' => ['Alfa.v', 'alfa.v.2', 'beta.v', '3.x.y', 'y', 'x.y'], 'Hluboký' => ['d.e', 'e', 'c.d.e', 'a.b.c.d.e'], 'Neexistuje' => ['a'], 'ALLWYN' => ['sportka.tah1_d']];
    foreach ($cesty as $n => $cs) foreach ($cs as $c) $v[] = ['hodnota', ['nazev' => $n, 'cesta' => $c]];
    foreach (['Allwyn', 'Ceník', 'Seznam', 'Hluboký', 'nový', 'Neexistuje', 'čerstvý'] as $n) { $v[] = ['existuje', ['nazev' => $n]]; $v[] = ['strom', ['nazev' => $n]]; }
    // stahování (odpovědi viz http()): nejdřív podle intervalu, pak jednotlivě
    $v[] = ['stahniPo', []];
    foreach (['Allwyn', 'Allwyn', 'nový', 'Rozbitý', 'Rozbitý', 'Seznam', 'Seznam', 'Seznam', 'Seznam', 'Seznam', 'Seznam', 'Ceník', 'Ceník', 'Ceník', 'Ceník', 'Ceník', 'Rozbitý', 'Čerstvý'] as $n) $v[] = ['stahni', ['nazev' => $n]];
    $v[] = ['stahniPo', []];
    return array_map(fn($x) => ['fn' => $x[0], 'args' => $x[1] ?: new stdClass()], $v);
}
/** Fronta odpovědí serveru (pořadí odpovídá pořadí stahování v volani()). */
function http(): array
{
    $json = fn($d) => [200, json_encode($d)];
    return [
        // stahniPo č. 1: Allwyn (fetched před hodinou), Rozbitý (před 2 h), nový (nikdy) – pořadí podle názvu bez ohledu na velikost: Allwyn, nový, Rozbitý
        $json(novaData()), $json(['x' => 1]), ['vyjimka' => 'Zdroj neodpovídá: timeout'],
        // stahni: Allwyn (beze změny), Allwyn (změna → 2. volání už stejné), nový, Rozbitý (obnova), Rozbitý (HTTP 500 s chybou), Seznam (404 html), Seznam (nevalidní JSON), Seznam (success false), Seznam (success false bez textu), Seznam (JSON s chybou), Seznam (HTTP 503 JSON error)
        $json(novaData()), $json(novaData()), $json([1, 2, 3]), $json(allwyn()), [500, '{"error":"Interní chyba"}'],
        [404, '<html><body><h1>Nenalezeno</h1> stránka</body></html>'], [200, 'tohle není json'], [200, '{"success":false,"error":"Limit"}'], [200, '{"success":false}'], [200, '"jen text"'], [503, '{"error":"Údržba"}'],
        // Ceník (CSV): 404, html, prázdný, Windows-1250, v pořádku
        [404, 'x'], [200, '<html>stránka</html>'], [200, ''], [200, "N\xE1zev;Cena\nK\xE1va;59\n"], [200, CSV_CENIK],
        // Rozbitý po obnově znovu, Čerstvý
        ['vyjimka' => 'Zdroj neodpovídá: spojení'], $json(['a' => 2]),
        // stahniPo č. 2 (všichni už jsou čerství kromě těch, kterým interval neběží – nic se nestahuje)
    ];
}

function modul(): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    foreach (zdrojeRadky() as $z) $h->db->q('INSERT INTO sources (' . implode(',', array_keys($z)) . ') VALUES (' . implode(',', array_fill(0, count($z), '?')) . ')', array_values($z));
    $fronta = http();
    $adresy = new ArrayObject();
    $protokol = $h->protokol;
    $vz = new Vzdalene($h->db, $protokol, 'TWB', function (string $url, bool $csv) use (&$fronta, $adresy) {
        $adresy[] = [$url, $csv];
        $r = array_shift($fronta);
        if (isset($r['vyjimka'])) throw new RuntimeException($r['vyjimka']);
        return [$r[0], $r[1]];
    });
    return [$h, $vz, $adresy];
}
function stavModulu($h): array
{
    $o = [];
    foreach ($h->db->q('SELECT name, fetched_at, ok_at, error, payload, hash FROM sources ORDER BY id')->fetchAll() as $r)
        $o[] = [$r['name'], $r['fetched_at'] > 0, $r['ok_at'] > 0, $r['error'], $r['payload'], $r['hash']];
    return $o;
}

test('shoda s originálem (Social Planner 1.9.2): zdroje JSON a CSV – hledání, strom, stahování, chyby, obnova', function () {
    $v = volani();
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/original/harness-zdroje.php');
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode(['tz' => 'Europe/Prague', 'zdroje' => zdrojeRadky(), 'http' => array_map(fn($r) => isset($r[1]) ? [$r[0], base64_encode($r[1])] : $r, http()), 'volani' => $v], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    proc_close($proc);
    $o = json_decode($out, true);
    if (!is_array($o)) throw new TestFailure('originál selhal: ' . substr($err . $out, 0, 600));

    [$h, $vz, $adresy] = modul();
    $spatne = [];
    foreach ($v as $i => $c) {
        $a = (array)$c['args'];
        $adresy->exchangeArray([]);
        $h->protokol->zaznamy = [];
        $chyba = null; $r = null; $stav = null;
        try {
            switch ($c['fn']) {
                case 'csvUrl': $r = Hledani::csvUrl($a['url']); break;
                case 'bezpecna': $r = Vzdalene::bezpecnaAdresa($a['url']); break;
                case 'csvNaRadky': $r = Hledani::csvNaRadky(base64_decode($a['telo']), $a['zahlavi']); break;
                case 'hodnota': $r = $vz->hodnota($a['nazev'], $a['cesta']); break;
                case 'existuje': $r = $vz->existuje($a['nazev']); break;
                case 'strom': $s = $vz->podleNazvu($a['nazev']); $r = $s ? $vz->strom($s) : null; break;
                case 'stahni': $r = $vz->stahni($vz->podleNazvu($a['nazev'])); $stav = stavModulu($h); break;
                case 'stahniPo': $r = $vz->stahniPo(); $stav = stavModulu($h); break;
            }
        } catch (RuntimeException $e) { $chyba = $e->getMessage(); }
        $log = array_map(fn($z) => preg_replace('/\. (Příspěvky|Texty|Hodnoty) .*$/u', '.', $z[1]), $h->protokol->zaznamy);
        $ocek = $o[$i];
        if (isset($ocek['chyba']) || $chyba !== null) { if (($ocek['chyba'] ?? null) !== $chyba) $spatne[] = "#$i {$c['fn']}: výjimka čekáno " . json_encode($ocek['chyba'] ?? null, JSON_UNESCAPED_UNICODE) . ', je ' . json_encode($chyba, JSON_UNESCAPED_UNICODE); continue; }
        $ologu = array_map(fn($z) => preg_replace('/\. (Příspěvky|Texty|Hodnoty) .*$/u', '.', $z), $ocek['log']);
        $porovnej = [[$ocek['ok'], $r, 'výsledek'], [$ocek['stav'], $stav, 'stav tabulky'], [$ologu, $log, 'protokol'], [$ocek['url'], $adresy->getArrayCopy(), 'stažené adresy']];
        foreach ($porovnej as [$x, $y, $co]) if (json_encode($x, JSON_UNESCAPED_UNICODE) !== json_encode($y, JSON_UNESCAPED_UNICODE)) $spatne[] = "#$i {$c['fn']} " . json_encode($a, JSON_UNESCAPED_UNICODE) . " $co: čekáno " . substr(json_encode($x, JSON_UNESCAPED_UNICODE), 0, 200) . ', je ' . substr(json_encode($y, JSON_UNESCAPED_UNICODE), 0, 200);
    }
    ok(!$spatne, count($spatne) . " rozdílů, např.:\n    " . implode("\n    ", array_slice($spatne, 0, 5)));
    ok(count($v) > 100, 'dost vstupů: ' . count($v));
});

function holy(callable $http, callable $cas, ?array $radek = null): array
{
    $h = novyHost();
    (new JadroMigrace($h->db))->spust('data', Migrace::class);
    $z = $radek ?? ['name' => 'Z', 'url' => 'https://x/z.json', 'format' => 'json', 'csv_header' => 1, 'interval_min' => 15, 'payload' => null, 'hash' => null, 'fetched_at' => 0, 'ok_at' => 0, 'error' => null, 'created_at' => 1];
    $h->db->q('INSERT INTO sources (' . implode(',', array_keys($z)) . ') VALUES (' . implode(',', array_fill(0, count($z), '?')) . ')', array_values($z));
    return [$h, new Vzdalene($h->db, $h->protokol, 'TWB', $http, $cas)];
}
test('interval stahování: zdroj se stahuje 30 s před uplynutím intervalu; čas dodává aplikace', function () {
    $ted = 1_800_000_000;
    foreach ([[870, true], [869, false], [3600, true]] as [$stari, $stahne]) {
        $pocet = 0;
        [$h, $vz] = holy(function () use (&$pocet) { $pocet++; return [200, '{"a":1}']; }, fn() => $ted, ['name' => 'Z', 'url' => 'u', 'format' => 'json', 'csv_header' => 1, 'interval_min' => 15, 'payload' => null, 'hash' => null, 'fetched_at' => $ted - $stari, 'ok_at' => 0, 'error' => null, 'created_at' => 1]);
        eq($stahne, $vz->stahniPo(), "stáří $stari s");
        eq($stahne ? 1 : 0, $pocet);
        if ($stahne) eq($ted, (int)$h->db->q('SELECT fetched_at FROM sources')->fetchColumn(), 'čas zápisu z dodané funkce');
    }
});
test('protokol: výpadek jako varování jen jednou, obnova jako informace; chyby se zkracují', function () {
    $odp = [[500, '{"error":"' . str_repeat('x', 800) . '"}'], [500, '<html><body>' . str_repeat('y', 400) . '</body></html>'], [200, '{"a":1}'], [200, '{"a":2}']];
    [$h, $vz] = holy(function () use (&$odp) { return array_shift($odp); }, fn() => 1000);
    $r = $vz->stahni($vz->podleNazvu('Z'));
    ok(!$r['ok'] && strlen($r['error']) <= 500 && mb_strlen($r['error']) === 500, 'chyba zkrácena na 500 znaků');
    eq([['warn', true]], array_map(fn($z) => [$z[0], str_contains($z[1], 'se nepodařilo stáhnout')], $h->protokol->zaznamy));
    $r = $vz->stahni($vz->podleNazvu('Z'));
    eq('HTTP 500: ' . str_repeat('y', 150), $r['error'], 'text stránky zkrácen na 150 znaků');
    eq(1, count($h->protokol->zaznamy), 'další pokus o chybě už neloguje');
    $r = $vz->stahni($vz->podleNazvu('Z'));
    eq([true, true], [$r['ok'], $r['changed']]);
    eq(['info', true], [$h->protokol->zaznamy[1][0], str_contains($h->protokol->zaznamy[1][1], 'opět dostupný')]);
    $r = $vz->stahni($vz->podleNazvu('Z'));
    eq(2, count($h->protokol->zaznamy));
});
