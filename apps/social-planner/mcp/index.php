<?php
declare(strict_types=1);
// MCP server (Streamable HTTP, bez trvalého spojení): adresa konektoru je {base_url}/mcp/
// Přihlášení: OAuth 2.1 (Bearer token). Bez platného tokenu vrací 401 s odkazem na metadata.
require __DIR__ . '/../app/lib/bootstrap.php';
require __DIR__ . '/../app/lib/graph.php';
require_once __DIR__ . '/../app/lib/notify.php';
require_once __DIR__ . '/../app/lib/besttime.php';
require_once __DIR__ . '/../app/lib/oauth.php';
require_once __DIR__ . '/../app/lib/mcp.php';

oauth_log_request('mcp');
oauth_cors_preflight('POST, GET, DELETE');
$pdo = db();
$token = oauth_bearer();
$grant = oauth_check_access($pdo, $token);
if (!$grant) {
    $hdrs = function_exists('getallheaders') ? array_change_key_case((array)getallheaders()) : [];
    $hasHeader = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? $hdrs['authorization'] ?? '')) !== '';
    oauth_log_detail($token !== '' ? 'neplatný nebo prošlý token' : ($hasHeader ? 'hlavička Authorization bez Bearer tokenu' : 'bez tokenu (výzva k přihlášení)'));
    // metadata ke stejnému tvaru adresy, jaký klient použil (…/mcp nebo …/mcp/)
    $reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $base = (string)parse_url(oauth_base(), PHP_URL_PATH);   // instalace v podsložce
    $rel = substr($reqPath, strlen($base));
    $meta = oauth_base() . '/.well-known/oauth-protected-resource' . (in_array($rel, OAUTH_MCP_PATHS, true) ? $rel : '/mcp/');
    header('WWW-Authenticate: Bearer resource_metadata="' . $meta . '"' . ($token !== '' ? ', error="invalid_token", error_description="The access token is invalid or expired"' : '') . ', scope="read drafts"');
    oauth_json(['error' => 'unauthorized', 'error_description' => 'Chybí nebo neplatí přístupový token. Připojte konektor znovu.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'DELETE') { http_response_code(204); exit; }   // ukončení relace – server je bezstavový
if ($method !== 'POST') { oauth_log_detail('GET bez streamu (405 je v pořádku)'); header('Allow: POST, DELETE'); http_response_code(405); exit; }   // bez SSE streamu

$raw = (string)file_get_contents('php://input', false, null, 0, 2_000_001);
$msg = json_decode($raw, true);
if (!is_array($msg)) oauth_json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Neplatný JSON.']], 400);

$batch = array_is_list($msg) && $msg !== [];
$hdrVersion = trim((string)($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? ''));
foreach ($batch ? $msg : [$msg] as $m) if (is_array($m) && (!isset($m['params']) || is_array($m['params']))) oauth_log_detail((string)($m['method'] ?? '?')
    . (($m['method'] ?? '') === 'tools/call' ? ':' . (string)($m['params']['name'] ?? '') : '')
    . (($m['method'] ?? '') === 'initialize' ? ' ' . (string)($m['params']['clientInfo']['name'] ?? '') : '')
    . ' v' . (string)($m['params']['_meta']['io.modelcontextprotocol/protocolVersion'] ?? $m['params']['protocolVersion'] ?? $hdrVersion));
oauth_log_detail('[' . $grant['client_name'] . ']');
$out = []; $status = 200;
foreach ($batch ? $msg : [$msg] as $m) {
    [$st, $r] = is_array($m) ? mcp_handle($pdo, $grant, $m, $hdrVersion) : [400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid request']]];
    if ($r !== null) $out[] = $r;
    if (!$batch) $status = $st;
}
if (!$out) { http_response_code($status === 400 ? 400 : 202); exit; }   // jen notifikace
if (!$batch && $status >= 400) oauth_log_detail('→ chyba ' . $out[0]['error']['code']);
oauth_json($batch ? $out : $out[0], $batch ? 200 : $status);
