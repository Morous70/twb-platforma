<?php
declare(strict_types=1);
// Obsah banneru: ?b=ID&v=VERZE – obrázek z galerie nebo vykreslený slide (dlouhá mezipaměť),
// ?b=ID&z=ZONA&k=PODPIS – HTML dokument banneru pro iframe (izolovaný: vlastní původ díky CSP sandbox).
use Reklamy\{App, Vydej};

/** @var App $app */
$app = require dirname(__DIR__) . '/lib/bootstrap.php';
header('X-Content-Type-Options: nosniff');
try {
    $v = new Vydej($app);
    $id = (string)($_GET['b'] ?? '');
    $b = $v->pripraveno && ctype_digit($id) ? ($app->db->q('SELECT * FROM banners WHERE id = ?', [(int)$id])->fetch() ?: null) : null;
    if ($b && $b['type'] === 'image' && isset($_GET['v'])) {
        $f = $app->galerie()->soubor((int)$b['media_id']);
        $etag = '"' . ($f['md5'] ?? md5_file($f['cesta'])) . '"';
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . filesize($f['cesta']));
        readfile($f['cesta']);
        exit;
    }
    if ($b && $b['type'] === 'slide' && isset($_GET['v'])) {
        $cesta = $app->slideBannery()->soubor($b);
        $etag = '"' . basename($cesta, '.jpg') . '"';
        // verze v adrese je klíč obsahu: když už neplatí (změnil se text s datem), krátká mezipaměť
        header('Cache-Control: public, max-age=' . (str_ends_with(basename($cesta, '.jpg'), '-' . $_GET['v']) ? '31536000, immutable' : '300'));
        header('ETag: ' . $etag);
        if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . filesize($cesta));
        readfile($cesta);
        exit;
    }
    $z = (string)($_GET['z'] ?? '');
    if ($b && $b['type'] === 'html' && ctype_digit($z) && $app->dorucovani()->overPodpis("html|$id|$z", $_GET['k'] ?? null)) {
        Vydej::bezCache();
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Security-Policy: ' . Vydej::SANDBOX);
        echo $app->vykresleni()->dokumentHtml($b, (int)$z);
        exit;
    }
} catch (Throwable $e) {
    if (isset($v)) $v->chyba($e, 'b.php');
}
http_response_code(404);
Vydej::bezCache();
header('Content-Type: text/plain; charset=utf-8');
echo "Banner nenalezen.\n";
