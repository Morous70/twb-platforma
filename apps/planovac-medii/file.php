<?php
// Zobrazení média z galerie (jen pro přihlášeného). Podporuje Range kvůli videím.
require __DIR__ . '/lib/bootstrap.php';
if (!is_logged_in()) { http_response_code(403); exit; }
session_write_close();

$m = q('SELECT filename, mime, original_name FROM media WHERE id = ?', [(int)($_GET['id'] ?? 0)])->fetch();
$path = $m ? MEDIA_DIR . '/' . $m['filename'] : null;
if (!$path || !is_file($path)) { http_response_code(404); exit; }

$size = filesize($path);
$start = 0; $end = $size - 1;
header('Content-Type: ' . $m['mime'] . (str_starts_with($m['mime'], 'text/') ? '; charset=utf-8' : ''));
header('X-Content-Type-Options: nosniff');
// HTML dokument z galerie běží izolovaně – bez přístupu k aplikaci a přihlášení
if ($m['mime'] === 'text/html') header('Content-Security-Policy: sandbox allow-scripts');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=604800');
header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode($m['original_name']));
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $r)) {
    if ($r[1] !== '') $start = (int)$r[1];
    if ($r[2] !== '') $end = min((int)$r[2], $size - 1);
    if ($r[1] === '' && $r[2] !== '') { $start = $size - (int)$r[2]; $end = $size - 1; }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($path, 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
