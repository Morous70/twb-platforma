<?php
// Signál pro pomocníka v místní síti (skript na Synology): vrací čas poslední změny ve složce na Google Disku.
// Volá se každou minutu: signal.php?key=<klíč>&done=<čas změny, pro kterou už byl přehrávač spuštěn>
// Pomocník při novější změně spustí synchronizaci přehrávače SlideShow (POST /ajax/synchronize).
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!installed()) { http_response_code(503); exit('Aplikace není nainstalovaná.'); }

$key = (string)setting('player_key', '');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Neplatný klíč.');
}
set_setting('player_poll_at', (string)time());
$done = (string)($_GET['done'] ?? '');
if ($done !== '' && ctype_digit($done) && strlen($done) <= 12) set_setting('player_done', $done);
echo (int)setting('last_change_at', 0);
