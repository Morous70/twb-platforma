<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('Aplikace potřebuje PHP 8.0 nebo novější (běží ' . PHP_VERSION . '). Verzi PHP změníte v administraci Wedos u webhostingu.');
}

// Upozornění PHP se nevypisují do výstupu (mohla by poškodit obrázky nebo odpovědi API), jen do logu serveru.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('APP_DIR', dirname(__DIR__));
require_once __DIR__ . '/version.php';
define('DATA_DIR', APP_DIR . '/data');
define('MEDIA_DIR', DATA_DIR . '/media');
define('TMP_DIR', DATA_DIR . '/tmp');

$cfgFile = getenv('MP_CONFIG') ?: APP_DIR . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Chybí config.php – zkopírujte config.sample.php jako config.php a vyplňte ho.');
}
$GLOBALS['CFG'] = require $cfgFile;
function cfg(string $k, $default = null) { return $GLOBALS['CFG'][$k] ?? $default; }

date_default_timezone_set(cfg('timezone', 'Europe/Prague'));
foreach ([DATA_DIR, MEDIA_DIR, TMP_DIR] as $d) if (!is_dir($d)) @mkdir($d, 0775, true);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('db_dsn'), cfg('db_user'), cfg('db_pass'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (is_sqlite()) $pdo->exec('PRAGMA foreign_keys = ON');
    return $pdo;
}
function is_sqlite(): bool { return str_starts_with((string)cfg('db_dsn'), 'sqlite:'); }

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

// ---------- Nastavení (klíč → hodnota) ----------
function setting(string $k, $default = null) {
    static $cache = null;
    if ($cache === null || $k === '__reload') {
        $cache = [];
        foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) $cache[$r['k']] = $r['v'];
        if ($k === '__reload') return null;
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}
function set_setting(string $k, $v): void {
    $v = $v === null ? null : (string)$v;
    if (q('UPDATE settings SET v = ? WHERE k = ?', [$v, $k])->rowCount() === 0) {
        $exists = q('SELECT 1 FROM settings WHERE k = ?', [$k])->fetchColumn();
        if (!$exists) q('INSERT INTO settings (k, v) VALUES (?, ?)', [$k, $v]);
    }
    setting('__reload');
}

// ---------- Protokol ----------
function app_log(string $level, string $message): void {
    q('INSERT INTO logs (at, level, message) VALUES (?, ?, ?)', [date('Y-m-d H:i:s'), $level, $message]);
    // udržujeme posledních ~2000 záznamů
    if (random_int(1, 50) === 1) {
        $cut = q('SELECT id FROM logs ORDER BY id DESC LIMIT 1 OFFSET 2000')->fetchColumn();
        if ($cut) q('DELETE FROM logs WHERE id <= ?', [$cut]);
    }
}

// ---------- Přihlášení ----------
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('mpsess');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
    session_start();
}
/** Po kolika sekundách nečinnosti se uživatel odhlásí (0 = nikdy). Nastavení je v minutách (výchozí 60). */
function idle_limit_seconds(): int {
    try {
        if (cfg('drive_mock') && (int)setting('idle_seconds_test', 0) > 0) return (int)setting('idle_seconds_test');   // jen pro testy
        return max(0, (int)setting('idle_minutes', 60)) * 60;
    } catch (Throwable $e) { return 0; }
}
/** Pasivní požadavek (automatické obnovování Přehledu a Protokolu) nepočítá jako aktivitu uživatele. */
function request_is_passive(): bool { return ($_SERVER['HTTP_X_PASSIVE'] ?? '') === '1'; }

function is_logged_in(): bool {
    start_session();
    if (empty($_SESSION['auth'])) return false;
    $limit = idle_limit_seconds();
    $last = (int)($_SESSION['auth_last'] ?? time());
    if ($limit > 0 && time() - $last > $limit) {
        unset($_SESSION['auth'], $_SESSION['auth_last']);
        $_SESSION['login_error'] = 'Byli jste odhlášeni z důvodu nečinnosti.';
        try { app_log('info', 'Odhlášení z důvodu nečinnosti.'); } catch (Throwable $e) { /* bez databáze se nezapíše */ }
        return false;
    }
    if (!request_is_passive() || empty($_SESSION['auth_last'])) $_SESSION['auth_last'] = time();
    return true;
}
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function installed(): bool {
    try { return (bool)setting('password_hash'); } catch (Throwable $e) { return false; }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
