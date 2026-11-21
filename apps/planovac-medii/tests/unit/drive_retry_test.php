<?php
// Dočasné chyby Google Disku (500, 503, 429, překročený limit): opakování s čekáním, ostatní chyby hned (oprava 1.16.1).
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/drive.php';
$GLOBALS['gapi_no_sleep'] = true;

$resp = fn(int $code, ?string $reason = null, string $msg = 'Internal Error') => ['code' => $code, 'headers' => [], 'body' => '',
    'json' => ['error' => ['message' => $msg, 'errors' => $reason ? [['reason' => $reason]] : []]]];

test('rozpoznání dočasných chyb Googlu', function () use ($resp) {
    foreach ([500, 502, 503, 504, 429] as $c) ok(gapi_transient($c), "$c");
    ok(gapi_transient(403, 'rateLimitExceeded') && gapi_transient(403, 'userRateLimitExceeded'), 'limit');
    ok(!gapi_transient(403, 'insufficientFilePermissions') && !gapi_transient(404) && !gapi_transient(400), 'trvalé chyby');
    $e = throws(fn() => gapi_check($resp(500)), 'dočasná chyba na straně Googlu');
    ok(str_contains($e, 'Google Disk (500): Internal Error'), $e);
    $e2 = throws(fn() => gapi_check($resp(403, 'insufficientFilePermissions', 'Insufficient permissions')), 'Google Disk (403)');
    ok(!str_contains($e2, 'dočasná'), $e2);
    eq(200, gapi_check(['code' => 200, 'headers' => [], 'body' => '{}', 'json' => []])['code']);
});

test('opakování: po dvou chybách 500 se napotřetí povede; trvalá chyba se vyhodí hned; nejvýš 4 pokusy', function () use ($resp) {
    $n = 0;
    eq('ok', gapi_retry(function () use (&$n, $resp) { if (++$n < 3) gapi_check($resp(500)); return 'ok'; }));
    eq(3, $n, 'pokusů');
    $n = 0;
    throws(function () use (&$n, $resp) { gapi_retry(function () use (&$n, $resp) { $n++; gapi_check($resp(404)); }); }, 'nebyla na Google Disku nalezena');
    eq(1, $n, 'trvalá chyba bez opakování');
    $n = 0;
    throws(function () use (&$n, $resp) { gapi_retry(function () use (&$n, $resp) { $n++; gapi_check($resp(503, null, 'Service Unavailable')); }); }, 'Service Unavailable');
    eq(4, $n, 'nejvýš 4 pokusy');
    $n = 0;
    eq('ok', gapi_retry(function () use (&$n) { if (++$n < 2) throw new RuntimeException('Spojení s Googlem selhalo: timeout'); return 'ok'; }), 'výpadek spojení');
});
