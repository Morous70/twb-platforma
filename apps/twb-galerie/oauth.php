<?php
declare(strict_types=1);
// OAuth 2.1 pro MCP konektory (Claude, ChatGPT) – modul mcp (TWB\Mcp\OAuth). Koncové body podle ?akce=:
//   metadata          RFC 8414 (také /.well-known/oauth-authorization-server)
//   metadata-zdroje   RFC 9728 (také /.well-known/oauth-protected-resource)
//   register          dynamická registrace klienta (RFC 7591), volá ji Claude / ChatGPT sám
//   authorize         souhlas s připojením (vyžaduje přihlášení do administrace)
//   token             výměna kódu za tokeny a obnova tokenů
//   revoke            zrušení tokenu klientem (zruší celé připojení)
use MediaGalerie\{App, Ui};
use TWB\Mcp\{OAuth, ChybaOAuth};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
$akce = is_string($_GET['akce'] ?? null) ? (string)$_GET['akce'] : '';
$metoda = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$json = static function (array $data, int $kod = 200): never {
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};
if ($akce !== 'authorize') {
    // klienti v prohlížeči (CORS); stránka souhlasu se volá jen přesměrováním
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version');
    if ($metoda === 'OPTIONS') { http_response_code(204); exit; }
}

try {
    /** @var App $app */
    $app = require __DIR__ . '/lib/bootstrap.php';
    if (!$app->nainstalovano()) $json(['error' => 'temporarily_unavailable', 'error_description' => 'Aplikace není nainstalovaná.'], 503);
    $app->migrujPodlePotreby();
    $oauth = $app->oauth();

    switch ($akce) {
        case 'metadata':
            $json($oauth->metadataServeru());
        case 'metadata-zdroje':
            $json($oauth->metadataZdroje());
        case 'register':
            if ($metoda !== 'POST') throw new ChybaOAuth('invalid_request', 'Použijte POST s JSON.', 405);
            $vstup = json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
            if (!is_array($vstup)) throw new ChybaOAuth('invalid_client_metadata', 'Tělo musí být JSON objekt.');
            $json($oauth->registruj($vstup), 201);
        case 'token':
            if ($metoda !== 'POST') throw new ChybaOAuth('invalid_request', 'Použijte POST.', 405);
            $vstup = $_POST;
            if (!$vstup && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'json')) $vstup = (array)json_decode((string)file_get_contents('php://input', false, null, 0, 20000), true);
            $json($oauth->token($vstup));
        case 'revoke':
            if ($metoda === 'POST') $oauth->zrusToken(is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
            http_response_code(200);
            exit;
        case 'authorize':
            require __DIR__ . '/stranky/souhlas.php';
            exit;
    }
    throw new ChybaOAuth('invalid_request', 'Neznámá akce.', 404);
} catch (ChybaOAuth $e) {
    $json($e->json(), $e->http === 302 ? 400 : $e->http);
} catch (Throwable $e) {
    if (isset($app)) $app->log('error', 'OAuth: ' . $e->getMessage());
    $json(['error' => 'server_error', 'error_description' => 'Chyba serveru, podrobnosti jsou v protokolu aplikace.'], 500);
}
