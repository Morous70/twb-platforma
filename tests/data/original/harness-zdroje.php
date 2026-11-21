<?php
declare(strict_types=1);
// Spouští zmrazené sources.php (a data.php) ze Social Planneru. Vstup (JSON, stdin): {tz, zdroje:[řádky tabulky], http:[odpovědi], volani:[{fn,args}]}
$in = json_decode((string)stream_get_contents(STDIN), true);
date_default_timezone_set($in['tz']);
define('NAMEDAYS_SOUBOR', dirname(__DIR__, 3) . '/modules/data/data/svatky.php');
define('APP_VERSION', 'test');
$GLOBALS['PDO'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['PDO']->exec("CREATE TABLE sources (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, url TEXT NOT NULL, format TEXT NOT NULL DEFAULT 'json', csv_header INTEGER NOT NULL DEFAULT 1, interval_min INTEGER NOT NULL DEFAULT 15, payload TEXT, hash TEXT, fetched_at INTEGER, ok_at INTEGER, error TEXT, created_at INTEGER NOT NULL DEFAULT 0)");
foreach ($in['zdroje'] as $z) {
    $cols = array_keys($z);
    $GLOBALS['PDO']->prepare('INSERT INTO sources (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($z));
}
$GLOBALS['LOG'] = []; $GLOBALS['HTTP'] = $in['http']; $GLOBALS['URL'] = [];
function db(): PDO { return $GLOBALS['PDO']; }
function log_line(string $m): void { $GLOBALS['LOG'][] = $m; }
function user_vars(PDO $p): array { return []; }
function weather_value(string $a, DateTimeImmutable $t): ?string { return null; }
function source_http(string $url, bool $csv): array {
    $GLOBALS['URL'][] = [$url, $csv];
    $r = array_shift($GLOBALS['HTTP']);
    if (isset($r['vyjimka'])) throw new RuntimeException($r['vyjimka']);
    return [$r[0], base64_decode($r[1])];
}
require __DIR__ . '/data-sp-1.9.2.php';
require __DIR__ . '/sources-sp-1.9.2.php';

function stav(): array {
    $o = [];
    foreach (db()->query('SELECT name, fetched_at, ok_at, error, payload, hash FROM sources ORDER BY id')->fetchAll() as $r)
        $o[] = [$r['name'], $r['fetched_at'] > 0, $r['ok_at'] > 0, $r['error'], $r['payload'], $r['hash']];
    return $o;
}
$out = [];
foreach ($in['volani'] as $c) {
    $a = (array)$c['args'];
    $GLOBALS['LOG'] = []; $GLOBALS['URL'] = [];
    try {
        $r = match ($c['fn']) {
            'csvUrl' => csv_url($a['url']),
            'csvNaRadky' => csv_to_rows(base64_decode($a['telo']), $a['zahlavi']),
            'hodnota' => source_value($a['nazev'], $a['cesta']),
            'existuje' => source_by_name($a['nazev']) !== null,
            'strom' => ($s = source_by_name($a['nazev'])) ? source_tree($s) : null,
            'bezpecna' => source_safe_url($a['url']),
            'stahni' => source_fetch(source_by_name($a['nazev'])),
            'stahniPo' => sources_refresh_due(),
        };
        $out[] = ['ok' => $r, 'stav' => in_array($c['fn'], ['stahni', 'stahniPo'], true) ? stav() : null, 'log' => $GLOBALS['LOG'], 'url' => $GLOBALS['URL']];
    } catch (RuntimeException $e) { $out[] = ['chyba' => $e->getMessage()]; }
}
echo json_encode($out, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
