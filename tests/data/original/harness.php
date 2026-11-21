<?php
declare(strict_types=1);
// Spouští zmrazené data.php ze Social Planneru. Vstup (JSON, stdin): {rows, user, zdroje, pocasi, tz, volani:[{fn, args}]}.
$in = json_decode((string)stream_get_contents(STDIN), true);
date_default_timezone_set($in['tz']);
define('NAMEDAYS_SOUBOR', dirname(__DIR__, 3) . '/modules/data/data/svatky.php');
$GLOBALS['PDO'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['PDO']->exec('CREATE TABLE data_rows (id INTEGER PRIMARY KEY AUTOINCREMENT, grp TEXT, param TEXT, value TEXT, updated_at INTEGER DEFAULT 0)');
$GLOBALS['PDO']->exec('CREATE TABLE variables (name TEXT, type TEXT, value TEXT)');
foreach ($in['rows'] as $r) $GLOBALS['PDO']->prepare('INSERT INTO data_rows (grp, param, value) VALUES (?,?,?)')->execute($r);
foreach ($in['user'] as $u) $GLOBALS['PDO']->prepare('INSERT INTO variables (name, type, value) VALUES (?,?,?)')->execute([$u['name'], $u['type'], $u['value']]);
function db(): PDO { return $GLOBALS['PDO']; }
function user_vars(PDO $pdo): array { $o = []; foreach ($pdo->query('SELECT name, type, value FROM variables')->fetchAll() as $r) $o[$r['name']] = $r; return $o; }
function source_by_name(string $n) { return isset($GLOBALS['IN']['zdroje'][mb_strtolower($n)]) ? ['name' => $n] : null; }
function source_value(string $n, string $a): ?string { return $GLOBALS['IN']['zdroje'][mb_strtolower($n)][$a] ?? null; }
function weather_value(string $a, DateTimeImmutable $t): ?string { return $GLOBALS['IN']['pocasi'][$a] ?? null; }
$GLOBALS['IN'] = $in;
require __DIR__ . '/data-sp-1.9.2.php';

$out = [];
foreach ($in['volani'] as $c) {
    $a = $c['args'];
    $t = isset($a['t']) ? new DateTimeImmutable($a['t']) : null;
    try {
        $out[] = ['ok' => match ($c['fn']) {
            'klic' => norm_key($a['s']),
            'datumZTextu' => (parse_any_date($a['s']))?->format('Y-m-d'),
            'odpocet' => countdown_text($a['n']),
            'naCislo' => to_number($a['s']),
            'cisloFormat' => num_fmt($a['n'], $a['arg'], $a['def']),
            'datum' => fmt_date($t, $a['fmt']),
            'filtry' => apply_filters($a['v'], $a['filtry'], $t),
            'hodnota' => var_value($a['nazev'], $a['arg'], $t, ['index' => $a['index'], 'user' => user_vars(db())]),
            'vyres' => resolve_vars($a['text'], $t, ['index' => $a['index'], 'user' => user_vars(db())], $a['slide']),
            'splneno' => conds_ok($a['podminky'], $t),
            'tabulka' => data_value($a['nazev'], $a['arg'], $t),
            'uklid' => data_clean_row($a['g'], $a['p'], $a['v']),
            'porovnej' => cond_compare($a['a'], $a['op'], $a['b']),
            'skupiny' => data_groups(),
        }];
    } catch (InvalidArgumentException $e) { $out[] = ['chyba' => $e->getMessage()]; }
}
echo json_encode($out, JSON_PARTIAL_OUTPUT_ON_ERROR);
