<?php
// Kontrola po aktualizaci: načte všechny části aplikace, provede převod databáze a ohlásí verzi.
// Volá ji jen instalace aktualizace s jednorázovým klíčem.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/slide.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/image.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/diag.php';
require_once __DIR__ . '/lib/update.php';
require_once __DIR__ . '/lib/devices.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$key = (string)setting('update_health_key', '');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Neplatný klíč.']));
}
migrate();
setting('__reload');
$schema = (int)setting('schema_version', 1);
q('SELECT COUNT(*) FROM media')->fetchColumn();
q('SELECT COUNT(*) FROM devices')->fetchColumn();
q('SELECT COUNT(*) FROM device_pairing')->fetchColumn();
resolve_at(new DateTimeImmutable());
echo json_encode(['ok' => $schema === SCHEMA_VERSION, 'version' => APP_VERSION, 'schema' => $schema, 'expected_schema' => SCHEMA_VERSION]);
