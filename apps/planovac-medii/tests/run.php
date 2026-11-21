<?php
// Automatické testy TWB Plánovače médií. Jen pro vývoj, na serveru se nespouští (složka je zablokovaná .htaccess).
//
//   php tests/run.php                 testy PHP (SQLite v dočasné složce)
//   php tests/run.php --e2e           navíc testy v prohlížeči (Playwright)
//   php tests/run.php schedule        jen soubory, jejichž název obsahuje „schedule“
//   php tests/run.php --mysql         databáze MySQL/MariaDB z proměnných MP_TEST_DSN, MP_TEST_USER, MP_TEST_PASS
//   php tests/run.php --keep          dočasnou kopii aplikace po testech nesmazat
//
// Aplikace se zkopíruje do dočasné složky (bez config.php a data/), takže testy nikdy nesahají na skutečná data.
// Testy v prohlížeči potřebují Node.js s balíčkem playwright (NODE_PATH ukazující na node_modules) a Chromium
// (cestu lze zadat v CHROMIUM_PATH).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$args = array_slice($argv, 1);
$opt = fn(string $f) => in_array($f, $args, true);
$filters = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$e2e = $opt('--e2e') || $opt('--jen-e2e');
$unit = !$opt('--jen-e2e');
$src = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/twb-mp-test-' . getmypid();
$port = 18000 + getmypid() % 1000;

function copy_tree(string $from, string $to, array $skip): void {
    @mkdir($to, 0775, true);
    foreach (scandir($from) as $f) {
        if ($f === '.' || $f === '..' || in_array($f, $skip, true)) continue;
        is_dir("$from/$f") ? copy_tree("$from/$f", "$to/$f", []) : copy("$from/$f", "$to/$f");
    }
}
function rm_tree(string $d): void {
    if (!is_dir($d)) return;
    foreach (scandir($d) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$d/$f") && !is_link("$d/$f") ? rm_tree("$d/$f") : @unlink("$d/$f");
    }
    @rmdir($d);
}
$c = fn(string $code, string $s) => posix_isatty(STDOUT) ? "\e[{$code}m$s\e[0m" : $s;

// ---------- příprava kopie aplikace ----------
copy_tree($src, "$tmp/app", ['config.php', 'data', '.git']);
@mkdir("$tmp/app/data/media", 0775, true);
@mkdir("$tmp/app/data/tmp", 0775, true);
$mysql = $opt('--mysql');
if ($mysql && !getenv('MP_TEST_DSN')) { fwrite(STDERR, "Pro --mysql nastavte MP_TEST_DSN (a MP_TEST_USER, MP_TEST_PASS). Pozor: databáze se celá smaže!\n"); exit(2); }
$config = [
    'base_url' => "http://localhost:$port",
    'db_dsn' => $mysql ? getenv('MP_TEST_DSN') : "sqlite:$tmp/test.sqlite",
    'db_user' => $mysql ? (getenv('MP_TEST_USER') ?: null) : null,
    'db_pass' => $mysql ? (getenv('MP_TEST_PASS') ?: null) : null,
    'google_client_id' => '', 'google_client_secret' => '',
    'cron_key' => 'test-cron-klic',
    'mail_from' => 'test@example.invalid',
    'timezone' => 'Europe/Prague',
    'drive_mock' => true,
    'mail_to_file' => true,   // e-maily (obnova hesla) se ukládají do data/tmp
    // počasí: zkušební náhrada OpenWeather (tests/mock/openweather.php)
    'openweather_url' => "http://localhost:$port/tests/mock/openweather.php",
    'openweather_icon_url' => "http://localhost:$port/tests/mock/openweather.php/img/wn",
];
file_put_contents("$tmp/config.php", '<?php return ' . var_export($config, true) . ';');
$env = getenv() + ['MP_CONFIG' => "$tmp/config.php"];
$env['MP_CONFIG'] = "$tmp/config.php";
$env['MP_TEST_SRC'] = $src;

$total = ['pass' => 0, 'fail' => 0, 'fails' => []];
$run = function (array $cmd, string $label, array $extraEnv = []) use (&$total, &$env, $tmp, $c) {
    echo $c('1', $label) . "\n";
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$tmp/app", $extraEnv + $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($p);
    $res = null;
    foreach (explode("\n", $out) as $line) {
        if (str_starts_with($line, '##RESULT ')) { $res = json_decode(substr($line, 9), true); continue; }
        if ($line === '') continue;
        echo preg_replace(['/^(\s*)✓/u', '/^(\s*)✗/u'], ['$1' . $c('32', '✓'), '$1' . $c('31', '✗')], $line) . "\n";
    }
    if (!$res) {
        $res = ['pass' => 0, 'fail' => 1, 'fails' => ["$label: spadlo (kód $code) " . trim(mb_substr($err ?: $out, -1500))]];
        echo $c('31', "  ✗ soubor spadl (kód $code)") . "\n" . trim($err) . "\n";
    } elseif (trim($err) !== '') {
        echo $c('33', '  ! chybový výstup: ' . trim(mb_substr($err, 0, 800))) . "\n";
    }
    $total['pass'] += $res['pass'];
    $total['fail'] += $res['fail'];
    array_push($total['fails'], ...array_map(fn($f) => "$label › $f", $res['fails']));
};
// webový server pro testy přes HTTP (unit/http_test.php) a v prohlížeči
$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', "$tmp/app"], [1 => ['file', "$tmp/server.log", 'a'], 2 => ['file', "$tmp/server.log", 'a']],
    $pipes, "$tmp/app", $env + ['PHP_CLI_SERVER_WORKERS' => '4']);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
$env['BASE_URL'] = "http://localhost:$port";
$match = fn(string $f) => !$filters || array_filter($filters, fn($x) => str_contains(basename($f), $x));
$t0 = microtime(true);

// ---------- testy PHP ----------
if ($unit) {
    foreach (glob("$tmp/app/tests/unit/*_test.php") as $f) {
        if (!$match($f)) continue;
        $run([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $f], basename($f));
    }
}

// ---------- testy v prohlížeči ----------
if ($e2e) {
    // instalace: tabulky + heslo
    $setup = "require 'tests/e2e/setup.php';";
    $p = proc_open([PHP_BINARY, '-r', $setup], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, "$tmp/app", $env);
    stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    if (proc_close($p) !== 0) { echo $c('31', "Instalace testovací databáze selhala: $err") . "\n"; exit(1); }

    foreach (glob("$tmp/app/tests/e2e/*.test.mjs") as $f) {
        if (!$match($f)) continue;
        $run(['node', $f], basename($f), ['TEST_PASSWORD' => 'test-heslo-123', 'TEST_TMP' => $tmp]);
    }
}
$st = proc_get_status($server);
if ($st['running']) { posix_kill($st['pid'], SIGTERM); usleep(200000); }
proc_close($server);

// ---------- souhrn ----------
echo "\n";
$time = round(microtime(true) - $t0, 1);
if ($total['fail']) {
    echo $c('31;1', "Selhalo {$total['fail']} z " . ($total['pass'] + $total['fail']) . " testů ($time s):") . "\n";
    foreach ($total['fails'] as $f) echo "  • $f\n";
} else {
    echo $c('32;1', "Všech {$total['pass']} testů prošlo ($time s).") . "\n";
}
if ($opt('--keep')) echo "Kopie aplikace zůstala v $tmp\n"; else rm_tree($tmp);
exit($total['fail'] ? 1 : 0);
