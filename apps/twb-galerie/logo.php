<?php
declare(strict_types=1);
// Vlastní logo webu (0.8.11) – veřejné, bez přihlášení: záhlaví veřejného webu, přihlášení a administrace. Nahrává se na stránce Veřejný web.
// Obsah je v databázi (tabulka web_files). ?v=<otisk> = adresa se mění s obsahem, proto smí zůstat dlouho v mezipaměti.
// SVG se vydává se sandboxem a bez skriptů (otevřené přímo jako stránka nic nespustí).
use MediaGalerie\App;

/** @var App $app */
$app = require __DIR__ . '/lib/bootstrap.php';
if (!$app->nainstalovano()) { http_response_code(404); exit; }
$app->migrujPodlePotreby();
$logo = $app->web()->logoSoubor();
if ($logo === null) { http_response_code(404); header('Cache-Control: no-store'); exit; }
$etag = '"' . $logo['md5'] . '"';
$trvale = isset($_GET['v']) && str_starts_with($logo['md5'], (string)$_GET['v']) && strlen((string)$_GET['v']) >= 8;
header('Content-Type: ' . $logo['mime']);
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox");
header('Cache-Control: public, ' . ($trvale ? 'max-age=31536000, immutable' : 'max-age=300'));
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
header('Content-Length: ' . strlen($logo['data']));
echo $logo['data'];
