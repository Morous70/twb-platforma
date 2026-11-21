<?php
// Zálohy pro Synology (skript v místní síti): backup.php?key=<klíč>&list=1 → seznam „název velikost“ po řádcích,
// backup.php?key=<klíč>&get=<název> → stažení zálohy. Klíč je stejný jako u signal.php (Nastavení → Přehrávač).
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/backup.php';
header('Cache-Control: no-store');
if (!installed()) { http_response_code(503); exit('Aplikace není nainstalovaná.'); }

$key = (string)setting('player_key', '');
if ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? ''))) {
    http_response_code(403);
    exit('Neplatný klíč.');
}
if (isset($_GET['list'])) {
    header('Content-Type: text/plain; charset=utf-8');
    set_setting('backup_nas_at', (string)time());
    foreach (backup_list() as $b) echo $b['name'], ' ', $b['size'], "\n";
    exit;
}
$name = (string)($_GET['get'] ?? '');
try { $path = backup_path($name); } catch (Throwable $e) { http_response_code(404); exit('Záloha nenalezena.'); }
// evidence: nejnovější záloha, kterou si Synology stáhl (stahuje od nejnovější)
$prev = explode('|', (string)setting('backup_nas_file', ''))[0];
if (strcmp(substr($name, 0, strlen(BACKUP_PREFIX) + 17), substr($prev, 0, strlen(BACKUP_PREFIX) + 17)) >= 0) set_setting('backup_nas_file', $name . '|' . time());
header('Content-Type: application/zip');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $name . '"');
readfile($path);
