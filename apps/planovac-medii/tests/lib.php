<?php
// Společné pomůcky pro testy. Spouští se jen z příkazové řádky přes tests/run.php
// (ten aplikaci zkopíruje do dočasné složky a připraví testovací databázi).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!getenv('MP_CONFIG')) { fwrite(STDERR, "Spouštějte přes: php tests/run.php\n"); exit(2); }

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once APP_DIR . '/lib/sync.php';
require_once APP_DIR . '/lib/schema.php';
require_once APP_DIR . '/lib/image.php';
require_once APP_DIR . '/lib/backup.php';
require_once APP_DIR . '/lib/diag.php';

final class TestFailure extends Exception {}

$GLOBALS['__T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

/** Jeden test. Selhání se zapíše, další testy pokračují. */
function test(string $name, callable $fn): void {
    try {
        $fn();
        $GLOBALS['__T']['pass']++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $GLOBALS['__T']['fail']++;
        $where = '';
        foreach ($e->getTrace() as $f) {
            if (isset($f['file']) && str_contains($f['file'], '/tests/') && !str_ends_with($f['file'], '/tests/lib.php')) { $where = basename($f['file']) . ':' . $f['line']; break; }
        }
        if (!$where && str_contains($e->getFile(), '/tests/')) $where = basename($e->getFile()) . ':' . $e->getLine();
        $msg = ($e instanceof TestFailure ? '' : get_class($e) . ': ') . $e->getMessage();
        $GLOBALS['__T']['fails'][] = "$name ($where): $msg";
        echo "  ✗ $name\n      $msg" . ($where ? "  [$where]" : '') . "\n";
    }
}

function show($v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function eq($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) throw new TestFailure(($msg ? "$msg – " : '') . 'čekáno ' . show($expected) . ', je ' . show($actual));
}
function ok($cond, string $msg = 'podmínka neplatí'): void { if (!$cond) throw new TestFailure($msg); }
function contains(string $needle, string $hay, string $msg = ''): void {
    if (!str_contains($hay, $needle)) throw new TestFailure(($msg ? "$msg – " : '') . show($hay) . ' neobsahuje ' . show($needle));
}
/** Očekává výjimku; vrátí její text. */
function throws(callable $fn, string $contains = ''): string {
    try { $fn(); } catch (TestFailure $e) { throw $e; } catch (Throwable $e) {
        if ($contains !== '' && !str_contains($e->getMessage(), $contains)) throw new TestFailure('výjimka „' . $e->getMessage() . "“ neobsahuje „{$contains}“");
        return $e->getMessage();
    }
    throw new TestFailure('čekána výjimka' . ($contains ? " „{$contains}“" : ''));
}

/** Prázdná databáze v aktuální verzi (vše smaže, vytvoří tabulky a provede převody). */
function db_reset(): void {
    $tables = is_sqlite()
        ? q("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN)
        : q('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    db()->exec(is_sqlite() ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $t) if ($t !== 'settings') db()->exec("DROP TABLE $t");
    if (in_array('settings', $tables, true)) db()->exec('DELETE FROM settings');
    db()->exec(is_sqlite() ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
    if (in_array('settings', $tables, true)) setting('__reload');
    create_schema();
    setting('__reload');
    sources_all(true);
    data_rows(true);
    foreach ([MEDIA_DIR, DATA_DIR . '/backups', DATA_DIR . '/mock-drive'] as $d) {
        foreach (glob("$d/{,.}*", GLOB_BRACE) ?: [] as $f) if (is_file($f) && basename($f) !== '.keep') @unlink($f);
    }
}

// ---------- výroba testovacích dat ----------
/** Vloží médium (soubor se vytvoří v data/media). Vrací id. */
function make_media(string $name, string $kind = 'image', array $extra = []): int {
    $file = $extra['filename'] ?? (in_array($kind, ['image', 'video', 'pdf'], true) ? bin2hex(random_bytes(6)) . '.' . (pathinfo($name, PATHINFO_EXTENSION) ?: 'bin') : '');
    $data = $extra['data'] ?? "obsah $name";
    if ($file !== '' && !isset($extra['filename'])) file_put_contents(MEDIA_DIR . "/$file", $data);
    $mime = $extra['mime'] ?? match ($kind) { 'image' => 'image/jpeg', 'video' => 'video/mp4', 'pdf' => 'application/pdf', 'slide' => 'image/jpeg', default => '' };
    q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [$file, $name, $mime, $kind, strlen($data), md5($data), $extra['grp'] ?? null, date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

/** Vloží scénář. Vrací id. */
function make_scenario(string $name, array $media = [], array $o = []): int {
    $now = date('Y-m-d H:i:s');
    q('INSERT INTO scenarios (name, is_default, priority, exclusive, paused, date_from, date_to, weekdays, hours, exceptions, created_at, updated_at, embed_only, is_fixed)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $name, (int)($o['default'] ?? 0), (int)($o['priority'] ?? 2), (int)($o['exclusive'] ?? 0), (int)($o['paused'] ?? 0),
        $o['from'] ?? null, $o['to'] ?? null, $o['weekdays'] ?? '1234567', $o['hours'] ?? str_repeat('1', 24),
        isset($o['exceptions']) ? json_encode($o['exceptions']) : null, $now, $now, (int)($o['embed'] ?? 0), (int)($o['fixed'] ?? 0)]);
    $id = (int)db()->lastInsertId();
    foreach ($media as $mid) q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$id, $mid]);
    return $id;
}

/** Názvy médií, která mají v okamžiku $at běžet. */
function playing(string $at): array {
    $r = resolve_at(new DateTimeImmutable($at));
    return array_values(array_map(fn($x) => $x['media']['original_name'], $r['media']));
}

register_shutdown_function(function () {
    $t = $GLOBALS['__T'];
    echo '##RESULT ' . json_encode($t, JSON_UNESCAPED_UNICODE) . "\n";
    exit($t['fail'] ? 1 : 0);
});

db_reset();
