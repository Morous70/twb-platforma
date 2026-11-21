<?php
declare(strict_types=1);
// Spouštění: každou minutu
//   CLI:  * * * * * php /cesta/social-planner/public/cron.php
//   HTTP: https://vase-domena/.../cron.php?key=CRON_KEY
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_once __DIR__ . '/app/lib/data.php';

if (PHP_SAPI !== 'cli') {
    $key = (string)($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string)cfg('cron_key'), $key)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}
set_time_limit(300);
// 1.5.0: vzdálené zdroje a počasí podle intervalu – před zveřejněním, ať příspěvky dostanou čerstvá data
try { sources_refresh_due(); } catch (Throwable $e) { log_line('Cron – zdroje dat: ' . $e->getMessage()); }
try { weather_refresh_due(); } catch (Throwable $e) { log_line('Cron – počasí: ' . $e->getMessage()); }
$n = run_due();
meta_set('cron_last', (string)time());   // pro přehled: kdy naposledy běžel cron
check_accounts_if_due(db());   // jednou denně zkontroluje tokeny účtů
stats_if_due(db());            // jednou denně obnoví statistiky nedávných příspěvků
backup_if_due(db());           // týdenní záloha databáze
echo "OK, zpracováno cílů: $n\n";
