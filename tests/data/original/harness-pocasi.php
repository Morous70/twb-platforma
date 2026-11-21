<?php
declare(strict_types=1);
// Spouští zmrazené weather.php (a data.php) ze Social Planneru. Vstup (JSON, stdin): {tz, now, klic, misto, url, prispevky, http:[odpovědi], volani:[{fn,args}]}
$in = json_decode((string)stream_get_contents(STDIN), true);
date_default_timezone_set($in['tz']);
define('NAMEDAYS_SOUBOR', dirname(__DIR__, 3) . '/modules/data/data/svatky.php');
define('APP_VERSION', 'test');
$GLOBALS['IN'] = $in;
$GLOBALS['PDO'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['PDO']->exec('CREATE TABLE weather (place_key TEXT PRIMARY KEY, place TEXT NOT NULL, payload TEXT, fetched_at INTEGER, ok_at INTEGER, error TEXT)');
$GLOBALS['PDO']->exec('CREATE TABLE posts (message TEXT, first_comment TEXT, status TEXT)');
$GLOBALS['PDO']->exec('CREATE TABLE templates (message TEXT)');
foreach ($in['prispevky'] as $p) $GLOBALS['PDO']->prepare('INSERT INTO posts VALUES (?,?,?)')->execute($p);
foreach ($in['radky'] as $r) $GLOBALS['PDO']->prepare('INSERT INTO weather VALUES (?,?,?,?,?,?)')->execute($r);
$GLOBALS['LOG'] = []; $GLOBALS['HTTP'] = $in['http']; $GLOBALS['URL'] = [];
function db(): PDO { return $GLOBALS['PDO']; }
function log_line(string $m): void { $GLOBALS['LOG'][] = $m; }
function meta_get(string $k, $d = '') { return $GLOBALS['IN'][$k === 'weather_api_key' ? 'klic' : 'misto'] ?? $d; }
function cfg(string $k, $d = null) { return $GLOBALS['IN']['url'] ?? $d; }
function user_vars(PDO $p): array { return []; }
function source_by_name(string $n) { return null; }
function weather_http(string $url): array {
    $GLOBALS['URL'][] = $url;
    $r = array_shift($GLOBALS['HTTP']);
    if (isset($r['vyjimka'])) throw new RuntimeException($r['vyjimka']);
    return [$r[0], $r[1]];
}
require __DIR__ . '/data-sp-1.9.2.php';
require __DIR__ . '/weather-sp-1.9.2.php';

function stav(): array {
    return array_map(fn($r) => [$r['place_key'], $r['place'], $r['payload'], $r['fetched_at'] > 0, $r['ok_at'] > 0, $r['error']], db()->query('SELECT * FROM weather ORDER BY place_key')->fetchAll());
}
$out = [];
foreach ($in['volani'] as $c) {
    $a = (array)$c['args'];
    $GLOBALS['LOG'] = []; $GLOBALS['URL'] = []; $GLOBALS['HTTP'] = $c['http'] ?? [];
    try {
        $r = match ($c['fn']) {
            'souradnice' => weather_coords($a['m']),
            'klic' => weather_place_key($a['m']),
            'misto' => weather_place($a['m']),
            'rozbor' => weather_parse_arg($a['arg']),
            'hodnota' => weather_value($a['arg'], new DateTimeImmutable($a['t'])),
            'ziskej' => weather_get($a['m']),
            'stahni' => weather_fetch($a['m']),
            'obnov' => (function () { weather_refresh_due(); return null; })(),
            'mista' => weather_places_used(),
        };
        $out[] = ['ok' => $r, 'stav' => stav(), 'log' => $GLOBALS['LOG'], 'url' => $GLOBALS['URL']];
    } catch (RuntimeException $e) { $out[] = ['chyba' => $e->getMessage()]; }
}
echo json_encode($out, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
