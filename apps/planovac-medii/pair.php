<?php
// Párování televize (aplikace pro TV Samsung) kódem. Bez přihlášení, jen pro tento účel.
//   pair.php?a=start[&kind=tizen|android] → {ok, code: "482913", poll: "<tajný token>", expires_in: 600, interval: 3}
//   pair.php?a=poll&t=<…>   → {ok, status: waiting|paired|expired, key?, device?}  (klíč zařízení se vydá jen jednou)
// Kód zadá uživatel v aplikaci: Nastavení → Zařízení → Spárovat televizi (api.php?a=device_pair).
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/devices.php';

ini_set('display_errors', '0');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

function pair_out($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!installed()) pair_out(['ok' => false, 'error' => 'Aplikace není nainstalovaná.'], 503);
migrate();
session_write_close();

$a = (string)($_GET['a'] ?? '');
if ($a === 'start') {
    try {
        [$code, $poll] = pair_start(device_client_ip(), (string)($_GET['kind'] ?? 'tizen'));
    } catch (RuntimeException $e) {
        pair_out(['ok' => false, 'error' => $e->getMessage()], $e->getCode() === 429 ? 429 : 500);
    }
    pair_out(['ok' => true, 'code' => $code, 'poll' => $poll, 'expires_in' => PAIR_TTL, 'interval' => PAIR_POLL_SECONDS, 'app' => APP_NAME, 'version' => APP_VERSION]);
}
if ($a === 'poll') {
    $t = (string)($_GET['t'] ?? '');
    if ($t === '' || strlen($t) > 100) pair_out(['ok' => false, 'error' => 'Chybí token.'], 400);
    pair_out(['ok' => true] + pair_poll($t));
}
pair_out(['ok' => false, 'error' => 'Neznámá akce.'], 404);
