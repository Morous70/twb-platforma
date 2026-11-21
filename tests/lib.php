<?php
// Společné pomůcky pro testy modulů (spouštějte přes: php tests/run.php [modul]).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

error_reporting(E_ALL);
set_error_handler(function (int $no, string $str, string $file, int $line) {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($str, 0, $no, $file, $line);   // upozornění a zastaralé funkce shodí test (kvůli PHP 8.5)
});

define('KOREN', dirname(__DIR__));
require KOREN . '/modules/jadro/boot.php';

use TWB\Jadro\{Db, Nastaveni, ProtokolPamet, Host};

final class TestFailure extends Exception {}

$GLOBALS['__T'] = ['pass' => 0, 'fail' => 0];

function test(string $name, callable $fn): void
{
    try {
        $fn();
        $GLOBALS['__T']['pass']++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__T']['fail']++;
        $where = '';
        if (str_contains($e->getFile(), '/tests/') && !str_ends_with($e->getFile(), '/tests/lib.php')) $where = basename($e->getFile()) . ':' . $e->getLine();
        else foreach ($e->getTrace() as $f) {
            if (isset($f['file']) && str_contains($f['file'], '/tests/') && !str_ends_with($f['file'], '/tests/lib.php')) { $where = basename($f['file']) . ':' . $f['line']; break; }
        }
        echo "  ✗ $name\n      " . ($e instanceof TestFailure ? '' : get_class($e) . ': ') . $e->getMessage() . ($where ? "  [$where]" : '') . "\n";
    }
}

function show(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) throw new TestFailure(($msg ? "$msg – " : '') . 'čekáno ' . show($expected) . ', je ' . show($actual));
}
function ok(mixed $cond, string $msg = 'podmínka neplatí'): void { if (!$cond) throw new TestFailure($msg); }
/** Očekává výjimku; vrátí její text. */
function throws(callable $fn, string $contains = ''): string
{
    try { $fn(); } catch (TestFailure $e) { throw $e; } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) throw new TestFailure('výjimka „' . $e->getMessage() . "“ neobsahuje „{$contains}“");
        return $e->getMessage();
    }
    throw new TestFailure('čekána výjimka' . ($contains ? " „{$contains}“" : ''));
}

/** Nový prázdný hostitel s databází v paměti. */
function novyHost(?string $datovaSlozka = null): Host
{
    $db = Db::pripoj('sqlite::memory:');
    $db->q('CREATE TABLE settings (k VARCHAR(64) PRIMARY KEY, v TEXT)');
    $slozka = $datovaSlozka ?? sys_get_temp_dir() . '/twb-test-' . bin2hex(random_bytes(4));
    @mkdir($slozka, 0775, true);
    return new Host($db, new Nastaveni($db), new ProtokolPamet(), $slozka);
}

register_shutdown_function(function () {
    $t = $GLOBALS['__T'];
    echo "  — úspěšných: {$t['pass']}, neúspěšných: {$t['fail']}\n";
    if ($t['fail'] > 0) exit(1);
});
