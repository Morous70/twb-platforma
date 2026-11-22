<?php
declare(strict_types=1);
// JSON API: api.php/v1/… s klíčem v hlavičce Authorization: Bearer tgk_… (popis v nápovědě: Správa → API).
use MediaGalerie\{App, Api, Vydej};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$odpoved = static function (int $kod, array $data): never {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
};
try {
    /** @var App $app */
    $app = require __DIR__ . '/lib/bootstrap.php';
    if (!$app->nainstalovano()) $odpoved(503, ['ok' => false, 'chyba' => 'Aplikace není nainstalovaná.']);
    if ((int)$app->nastaveni()->dej('schema_version', 0) !== MediaGalerie\Schema::verze()) $app->migruj();
    $klic = null;
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) $klic = $m[1];
    elseif (isset($_SERVER['HTTP_X_API_KEY'])) $klic = trim((string)$_SERVER['HTTP_X_API_KEY']);
    $api = new Api($app);
    $metoda = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $cesta = (string)($_SERVER['PATH_INFO'] ?? ($_GET['cesta'] ?? ''));
    $jeJson = str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json');
    [$kod, $data] = $api->zpracuj($metoda, $cesta, $klic, $_GET, $_POST, $_FILES, $jeJson ? (string)file_get_contents('php://input') : '');
    if ($kod === 200 && $api->soubor !== null) {
        $f = $api->soubor;
        Vydej::posli($f['cesta'], $f['mime'], $f['nazev'], (!empty($_GET['original']) ? 'o' : '') . $f['md5'], 'private, no-store', !empty($_GET['stahnout']));
    }
    if ($kod === 405) header('Allow: GET, POST, PATCH, DELETE');
    $odpoved($kod, $data);
} catch (Throwable $e) {
    if (isset($app)) $app->log('error', 'API: ' . $e->getMessage());
    $odpoved(500, ['ok' => false, 'chyba' => 'Chyba serveru, podrobnosti jsou v protokolu aplikace.']);
}
