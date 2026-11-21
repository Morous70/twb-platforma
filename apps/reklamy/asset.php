<?php
declare(strict_types=1);
// Soubory z modulů platformy pro prohlížeč (složka modules/ není z internetu přístupná): jen vyjmenované soubory.
use Reklamy\App;

require __DIR__ . '/lib/autoload.php';
$soubory = [
    'slide-editor.js' => ['slidy/web/slide-editor.js', 'text/javascript; charset=utf-8'],
    'slide-editor.css' => ['slidy/web/slide-editor.css', 'text/css; charset=utf-8'],
];
$f = $soubory[(string)($_GET['f'] ?? '')] ?? null;
$cesta = $f ? App::moduly() . '/' . $f[0] : '';
header('X-Content-Type-Options: nosniff');
if (!$f || !is_file($cesta)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo "Nenalezeno.\n"; exit; }
header('Content-Type: ' . $f[1]);
header('Cache-Control: public, max-age=' . (isset($_GET['v']) ? '31536000, immutable' : '3600'));
header('Content-Length: ' . filesize($cesta));
readfile($cesta);
