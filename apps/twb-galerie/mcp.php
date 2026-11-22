<?php
declare(strict_types=1);
// MCP server (Streamable HTTP bez trvalého spojení) pro Claude, ChatGPT a další AI klienty: adresa konektoru {base_url}/mcp.php.
// Přihlášení: OAuth 2.1 (konektor v Claude.ai / ChatGPT, token tgoa_…), nebo API klíč galerie (Claude Code a jiné klienty, hlavička Authorization: Bearer tgk_…).
// Bez platného tokenu vrací 401 s odkazem na metadata OAuth. Popis v nápovědě: Správa → Připojení AI (MCP).
use MediaGalerie\App;
use TWB\Mcp\{OAuth, Server};

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Mcp-Protocol-Version, Mcp-Session-Id, Mcp-Method, Last-Event-ID');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
$json = static function (int $kod, ?array $data): never {
    http_response_code($kod);
    if ($data !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    exit;
};
$metoda = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($metoda === 'OPTIONS') $json(204, null);
try {
    /** @var App $app */
    $app = require __DIR__ . '/lib/bootstrap.php';
    if (!$app->nainstalovano()) $json(503, ['error' => 'unavailable', 'error_description' => 'Aplikace není nainstalovaná.']);
    $app->migrujPodlePotreby();

    // ---- přihlášení: OAuth token, nebo API klíč ----
    $oauth = $app->oauth();
    $token = OAuth::bearer($_SERVER, function_exists('getallheaders') ? (array)getallheaders() : []);
    $opravneni = null;
    $klient = '';
    if (str_starts_with($token, \MediaGalerie\ApiKlice::PREDPONA)) {
        $klice = $app->apiKlice();
        if (($s = $klice->zamceno()) > 0) { header("Retry-After: {$s}"); $json(429, ['error' => 'too_many_requests', 'error_description' => "Příliš mnoho neplatných klíčů, zkuste to za {$s} s."]); }
        $k = $klice->over($token);
        if ($k) {
            [, $pockej] = $klice->pocitej($k);
            if ($pockej > 0) { header("Retry-After: {$pockej}"); $json(429, ['error' => 'too_many_requests', 'error_description' => "Příliš mnoho požadavků s tímto klíčem, zkuste to za {$pockej} s."]); }
            $opravneni = $k['scope'] === 'write' ? ['read', 'write'] : ['read'];
            $klient = 'klíč ' . $k['name'];
        } else {
            $klice->neuspech();
        }
    } elseif ($token !== '' && ($g = $oauth->over($token))) {
        $opravneni = $g['scopes'];
        $klient = (string)$g['client_name'];
    }
    if ($opravneni === null) {
        header('WWW-Authenticate: ' . $oauth->vyzva($app->url('oauth.php?akce=metadata-zdroje'), $token !== ''));
        $json(401, ['error' => 'unauthorized', 'error_description' => 'Chybí nebo neplatí přístupový token. Připojte konektor znovu (nebo pošlete API klíč v Authorization: Bearer tgk_…).']);
    }

    // ---- MCP ----
    if ($metoda === 'DELETE') $json(204, null);   // ukončení relace – server je bezstavový
    if ($metoda !== 'POST') { header('Allow: POST, DELETE'); $json(405, null); }   // bez SSE streamu (GET → 405 je podle specifikace v pořádku)
    $telo = (string)file_get_contents('php://input', false, null, 0, Server::MAX_TELO + 1);
    if (strlen($telo) > Server::MAX_TELO) $json(413, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Požadavek je příliš velký (nejvýš 30 MB). Větší soubor pošlete přes url.']]);
    [$kod, $odpoved] = $app->mcp()->server()->zpracujTelo($telo, $opravneni, $klient, trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '')));
    $json($kod, $odpoved);
} catch (Throwable $e) {
    if (isset($app)) $app->log('error', 'MCP: ' . $e->getMessage());
    $json(500, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'Chyba serveru, podrobnosti jsou v protokolu aplikace.']]);
}
