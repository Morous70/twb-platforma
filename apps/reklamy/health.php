<?php
declare(strict_types=1);
// Kontrola po aktualizaci: načte aplikaci, převede databázi a ohlásí verzi. Volá ji jen aktualizace s jednorázovým klíčem.
use Reklamy\{App, Schema};

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    /** @var App $app */
    $app = require __DIR__ . '/lib/bootstrap.php';
    $klic = (string)$app->nastaveni()->dej('update_health_key', '');
    if ($klic === '' || !hash_equals($klic, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Neplatný klíč.']));
    }
    $app->migruj();
    $app->nastaveni()->obnov();
    foreach (App::TABULKY as $t) $app->db->q('SELECT COUNT(*) FROM ' . $t)->fetchColumn();
    $schema = (int)$app->nastaveni()->dej('schema_version', 0);
    echo json_encode(['ok' => $schema === Schema::verze(), 'version' => $app->verze, 'schema' => $schema, 'expected_schema' => Schema::verze()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
