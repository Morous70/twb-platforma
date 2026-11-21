<?php
// Spouštění: z příkazové řádky `php cron.php`, nebo přes URL cron.php?key=<cron_key>
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/schema.php';
if (installed()) migrate();

$cli = PHP_SAPI === 'cli';
if (!$cli && !hash_equals((string)cfg('cron_key'), (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Neplatný klíč.');
}
if (!installed()) exit("Aplikace není nainstalovaná.\n");

@set_time_limit(300);
ignore_user_abort(true);
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/lib/sources.php';
try { sources_refresh_due(); } catch (Throwable $e) { app_log('error', 'Stahování zdrojů selhalo: ' . $e->getMessage()); }
require_once __DIR__ . '/lib/slide.php';
try { weather_refresh_due(); } catch (Throwable $e) { app_log('error', 'Stahování počasí selhalo: ' . $e->getMessage()); }
$r = run_sync(isset($_GET['force']) || in_array('--force', $argv ?? [], true));
echo date('Y-m-d H:i:s') . ' ' . $r['status'] . ': ' . $r['message'] . "\n";

// denní záloha (od 3:00, jednou za den)
require_once __DIR__ . '/lib/backup.php';
try {
    if ($b = backup_due()) echo date('Y-m-d H:i:s') . " záloha: {$b['name']}\n";
} catch (Throwable $e) {
    app_log('error', 'Automatická záloha selhala: ' . $e->getMessage());
}

// kontrola nové verze na TWB Update Serveru (jednou za 24 h, instaluje se jen ručně)
require_once __DIR__ . '/lib/update.php';
try { update_check_due(); } catch (Throwable $e) { app_log('error', 'Kontrola aktualizací selhala: ' . $e->getMessage()); }
