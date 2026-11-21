<?php
declare(strict_types=1);
// Pravidelné úlohy: <base_url>/cron.php?key=<cron_key> (nebo z příkazové řádky: php cron.php).
// Denní záloha, kontrola aktualizací, úklid protokolu.
use Reklamy\{App, ProtokolDb};

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (PHP_SAPI !== 'cli') {
    $klic = (string)$app->cfg('cron_key', '');
    if ($klic === '' || $klic === 'ZMENTE-NA-DLOUHY-NAHODNY-RETEZEC' || !hash_equals($klic, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit("Neplatný klíč.\n");
    }
}
if (!$app->nainstalovano()) exit("Aplikace není nainstalovaná.\n");
@set_time_limit(300);
$app->migruj();
$app->nastaveni()->uloz('cron_last_run', (string)time());
$vystup = [];

try {
    if ($app->nastaveni()->dej('backup_auto', '1') === '1' && (string)$app->nastaveni()->dej('backup_password', '') !== '' && !$app->zalohy()->podporovano()) {
        $z = $app->zalohy()->dojde();
        $vystup[] = $z ? "Záloha: {$z['name']}" : 'Záloha: dnes už proběhla.';
    }
} catch (Throwable $e) {
    $app->log('error', 'Automatická záloha selhala: ' . $e->getMessage());
    $vystup[] = 'Záloha selhala: ' . $e->getMessage();
}
try {
    $app->aktualizace()->kontrolaDojde();
} catch (Throwable $e) {
    $vystup[] = 'Kontrola aktualizací selhala: ' . $e->getMessage();
}
try {
    $app->dorucovani()->uklid();
    $app->html5()->uklid($app->bannery()->balickyHtml5());
} catch (Throwable $e) {
    $vystup[] = 'Úklid doručování selhal: ' . $e->getMessage();
}
$host = $app->host->protokol;
if ($host instanceof ProtokolDb) $host->uklid();
echo "OK\n" . implode("\n", $vystup) . "\n";
