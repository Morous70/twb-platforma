<?php
// Rozhraní pro zařízení pro přehrávání (plugin pro Chrome, později Android a TV Samsung). Jen čtení.
//   now.php                 → JSON: zařízení, délka zobrazení, co běží, soubory ve složce na Google Disku
//   now.php?file=<ID>       → obsah jednoho souboru (jen soubory, které jsou právě ve složce přehrávače)
//   POST now.php?ping=1     → zařízení hlásí stav (JSON v těle) a vyzvedne si příkaz z aplikace (od přehrávače 1.1.0)
// Klíč zařízení v hlavičce X-Device-Key (doporučeno), nebo v parametru key. Verzi klienta posílá hlavička X-Client.
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/devices.php';

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
// Zařízení (plugin, aplikace v TV) se přihlašují klíčem v hlavičce, ne cookies – volání z jiného původu je bezpečné.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Device-Key, X-Client, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Max-Age: 86400');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function now_json($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function now_fail(string $msg, int $code): void { now_json(['ok' => false, 'error' => $msg], $code); }

if (!installed()) now_fail('Aplikace není nainstalovaná.', 503);
migrate();

$ip = device_client_ip();
if (device_blocked($ip)) now_fail('Příliš mnoho pokusů se špatným klíčem. Zkuste to za 15 minut.', 429);
$device = device_auth($_SERVER['HTTP_X_DEVICE_KEY'] ?? ($_GET['key'] ?? null));
if (!$device) {
    device_fail($ip);
    now_fail('Neplatný klíč zařízení. Zkontrolujte klíč v nastavení pluginu, případně v Nastavení → Zařízení vytvořte nový.', 403);
}
session_write_close();

if (isset($_GET['ping'])) {
    device_seen($device, $_SERVER['HTTP_X_CLIENT'] ?? null);
    $raw = (string)file_get_contents('php://input', false, null, 0, 20000);
    now_json(device_ping($device, json_decode($raw, true)));
}

$fileId = (string)($_GET['file'] ?? '');
if ($fileId === '') {
    device_seen($device, $_SERVER['HTTP_X_CLIENT'] ?? null);
    try {
        now_json(device_now($device, $_SERVER['HTTP_X_CLIENT'] ?? null));
    } catch (Throwable $e) {
        app_log('warning', "Zařízení „{$device['name']}“: seznam souborů se nepodařilo načíst – " . $e->getMessage());
        now_fail('Seznam souborů se nepodařilo načíst: ' . $e->getMessage(), 502);
    }
}

// ---------- jeden soubor ----------
if (strlen($fileId) > 300) now_fail('Neplatné ID souboru.', 400);
try {
    $f = device_find_file($fileId);
} catch (Throwable $e) {
    now_fail('Seznam souborů se nepodařilo načíst: ' . $e->getMessage(), 502);
}
if (!$f) now_fail('Soubor už ve složce přehrávače není.', 404);

@set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level()) ob_end_clean();
try {
    drive_stream($f['id'], function () use ($f) {
        header('Content-Type: ' . $f['mime'] . (in_array($f['kind'], ['text', 'url', 'html'], true) ? '; charset=utf-8' : ''));
        if ($f['kind'] === 'html') header('Content-Security-Policy: sandbox allow-scripts');
        if ($f['size'] !== null) header('Content-Length: ' . $f['size']);
        if ($f['md5']) header('ETag: "' . $f['md5'] . '"');
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($f['name']));
    });
} catch (Throwable $e) {
    if (!headers_sent()) now_fail('Soubor se nepodařilo stáhnout z Google Disku: ' . $e->getMessage(), $e->getCode() === 404 ? 404 : 502);
}
