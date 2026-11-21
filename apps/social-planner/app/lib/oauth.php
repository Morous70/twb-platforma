<?php
declare(strict_types=1);
// OAuth 2.1 autorizační server pro MCP (Claude, ChatGPT):
//  - dynamická registrace klientů (RFC 7591), veřejní klienti bez tajemství
//  - authorization code + PKCE S256 (povinné), refresh tokeny s rotací
//  - metadata RFC 8414 (autorizační server) a RFC 9728 (chráněný zdroj)
// Tokeny i kódy se ukládají jen jako SHA-256. Jedno „připojení“ = jeden grant; jeho odebráním přestanou platit všechny tokeny.

const OAUTH_ACCESS_TTL  = 3600;            // 1 hodina
const OAUTH_REFRESH_TTL = 90 * 86400;      // 90 dní (při každém použití se obnoví)
const OAUTH_CODE_TTL    = 300;             // 5 minut
const OAUTH_SCOPES      = ['read' => 'Číst účty, plán, statistiky, sady hashtagů a šablony', 'drafts' => 'Vytvářet koncepty příspěvků (nic se nezveřejní bez vašeho potvrzení)'];
const OAUTH_MAX_CLIENTS = 100;

function oauth_tables(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS oauth_clients (
        client_id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        redirect_uris TEXT NOT NULL,      -- JSON pole
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS oauth_grants (  -- připojení (souhlas uživatele s klientem)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        client_id TEXT NOT NULL,
        client_name TEXT NOT NULL,
        redirect_host TEXT NOT NULL,
        scope TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        last_used INTEGER,
        uses INTEGER NOT NULL DEFAULT 0,
        revoked_at INTEGER
    );
    CREATE TABLE IF NOT EXISTS oauth_codes (
        code_hash TEXT PRIMARY KEY,
        grant_id INTEGER NOT NULL,
        client_id TEXT NOT NULL,
        redirect_uri TEXT NOT NULL,
        challenge TEXT NOT NULL,
        expires INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS oauth_tokens (
        token_hash TEXT PRIMARY KEY,
        kind TEXT NOT NULL,               -- access | refresh
        grant_id INTEGER NOT NULL,
        client_id TEXT NOT NULL,
        expires INTEGER NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_oauth_tokens_grant ON oauth_tokens(grant_id);
    ");
    $done = true;
}

/* ---------- diagnostika: záznam všech požadavků OAuth a MCP (posledních 300) ---------- */
$GLOBALS['oauth_log_detail'] = '';
function oauth_log_detail(string $d): void { $GLOBALS['oauth_log_detail'] = trim($GLOBALS['oauth_log_detail'] . ' ' . $d); }
/** Zavolat na začátku každého vstupního bodu; záznam se zapíše při ukončení skriptu (i po exit). */
function oauth_log_request(string $endpoint): void {
    register_shutdown_function(function () use ($endpoint) {
        try {
            $pdo = db();
            $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, endpoint TEXT, method TEXT, status INTEGER, ua TEXT, ip TEXT, detail TEXT)");
            $pdo->prepare("INSERT INTO mcp_log (ts, endpoint, method, status, ua, ip, detail) VALUES (?,?,?,?,?,?,?)")->execute([
                time(), $endpoint, (string)($_SERVER['REQUEST_METHOD'] ?? ''), (int)http_response_code(),
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160), (string)($_SERVER['REMOTE_ADDR'] ?? ''), mb_substr((string)$GLOBALS['oauth_log_detail'], 0, 300),
            ]);
            if (random_int(1, 20) === 1) $pdo->exec("DELETE FROM mcp_log WHERE id <= (SELECT MAX(id) - 300 FROM mcp_log)");
        } catch (Throwable $e) { /* diagnostika nesmí shodit odpověď */ }
    });
}
function oauth_log_list(PDO $pdo, int $limit = 80): array {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mcp_log (id INTEGER PRIMARY KEY AUTOINCREMENT, ts INTEGER NOT NULL, endpoint TEXT, method TEXT, status INTEGER, ua TEXT, ip TEXT, detail TEXT)");
    return $pdo->query("SELECT * FROM mcp_log ORDER BY id DESC LIMIT " . max(1, min(300, $limit)))->fetchAll();
}

/** Samotest: server zavolá svůj MCP endpoint s identifikacemi, jaké používají Claude a ChatGPT. Odhalí blokování AI robotů na hostingu. */
function oauth_selftest(): array {
    $url = oauth_resource();
    $out = [];
    foreach (['python-httpx/0.28.1' => 'Claude – přihlášení (OAuth)', 'Claude-User' => 'Claude – volání nástrojů', 'openai-mcp/1.0.0' => 'ChatGPT', 'Mozilla/5.0' => 'běžný prohlížeč'] as $ua => $label) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json, text/event-stream'], CURLOPT_USERAGENT => $ua,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false]);
        $resp = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        $auth = (bool)preg_match('/^WWW-Authenticate:\s*Bearer[^\r\n]*resource_metadata/mi', $resp);
        $out[] = ['ua' => $ua, 'label' => $label, 'status' => $code, 'ok' => $code === 401 && $auth, 'error' => $err];
    }
    return $out;
}

function oauth_base(): string { return rtrim((string)cfg('base_url'), '/'); }
function oauth_issuer(): string { return oauth_base(); }
/** Adresa MCP serveru; podle toho, jak ji klient zadal (/mcp nebo /mcp/), aby metadata seděla s adresou konektoru. */
/** Tvary adresy MCP serveru, které klient může použít: /mcp, /mcp/, /mcp/index.php (Claude odstraňuje koncové lomítko). */
const OAUTH_MCP_PATHS = ['/mcp', '/mcp/', '/mcp/index.php'];
function oauth_resource(?string $path = null): string { return oauth_base() . (in_array($path, OAUTH_MCP_PATHS, true) ? $path : '/mcp/'); }

function oauth_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function oauth_error(string $err, string $desc, int $code = 400): never { oauth_log_detail("$err: $desc"); oauth_json(['error' => $err, 'error_description' => $desc], $code); }

/** Odpověď na předběžný dotaz prohlížeče (CORS) – klienti v prohlížeči. */
function oauth_cors_preflight(string $methods): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: ' . $methods . ', OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Mcp-Protocol-Version, Mcp-Session-Id');
    header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function oauth_as_metadata(): array {
    $b = oauth_base();
    return [
        'issuer' => oauth_issuer(),
        'authorization_endpoint' => $b . '/oauth/authorize.php',
        'token_endpoint' => $b . '/oauth/token.php',
        'registration_endpoint' => $b . '/oauth/register.php',
        'revocation_endpoint' => $b . '/oauth/revoke.php',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'revocation_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => array_merge(array_keys(OAUTH_SCOPES), ['offline_access']),
        'service_documentation' => $b . '/napoveda.php#mcp',
    ];
}
function oauth_pr_metadata(): array {
    $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $suffix = preg_match('#/oauth-protected-resource(?:\.php)?(/mcp(?:/index\.php|/)?)$#', $uri, $m) ? $m[1] : null;
    return [
        'resource' => oauth_resource($suffix),
        'authorization_servers' => [oauth_issuer()],
        'scopes_supported' => array_keys(OAUTH_SCOPES),
        'bearer_methods_supported' => ['header'],
        'resource_name' => 'Social Planner',
    ];
}

/** Povolené návratové adresy: https, nebo http jen na localhost (desktopové aplikace). Žádné fragmenty. */
function oauth_valid_redirect(string $u): bool {
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment'])) return false;
    if ($p['scheme'] === 'https') return true;
    return $p['scheme'] === 'http' && in_array($p['host'], ['localhost', '127.0.0.1', '[::1]'], true);
}

function oauth_register(PDO $pdo, array $in): array {
    oauth_tables($pdo);
    $uris = $in['redirect_uris'] ?? null;
    if (!is_array($uris) || !$uris || count($uris) > 10) oauth_error('invalid_redirect_uri', 'redirect_uris: očekává se 1–10 adres.');
    foreach ($uris as $u) if (!is_string($u) || strlen($u) > 500 || !oauth_valid_redirect($u)) oauth_error('invalid_redirect_uri', 'Nepovolená návratová adresa: ' . (is_string($u) ? $u : '?'));
    $auth = (string)($in['token_endpoint_auth_method'] ?? 'none');
    if ($auth !== 'none') oauth_error('invalid_client_metadata', 'Podporováni jsou jen veřejní klienti (token_endpoint_auth_method = none).');
    $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($in['client_name'] ?? '')));
    $name = mb_substr($name !== '' ? $name : 'MCP klient', 0, 80);
    // úklid: registrace bez připojení starší než den; strop počtu klientů
    $pdo->exec("DELETE FROM oauth_clients WHERE created_at < " . (time() - 86400) . " AND client_id NOT IN (SELECT client_id FROM oauth_grants WHERE revoked_at IS NULL)");
    if ((int)$pdo->query("SELECT COUNT(*) FROM oauth_clients")->fetchColumn() >= OAUTH_MAX_CLIENTS) oauth_error('temporarily_unavailable', 'Příliš mnoho registrovaných klientů, zkuste to později.', 429);
    $id = 'spc_' . bin2hex(random_bytes(16));
    $pdo->prepare("INSERT INTO oauth_clients (client_id, name, redirect_uris, created_at) VALUES (?,?,?,?)")
        ->execute([$id, $name, json_encode(array_values($uris), JSON_UNESCAPED_SLASHES), time()]);
    log_line("OAUTH registrace klienta „{$name}“ ($id)");
    oauth_log_detail("klient „{$name}“, návrat " . implode(', ', array_map(fn($u) => (string)parse_url($u, PHP_URL_HOST), $uris)));
    return [
        'client_id' => $id, 'client_id_issued_at' => time(), 'client_name' => $name,
        'redirect_uris' => array_values($uris), 'token_endpoint_auth_method' => 'none',
        'grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code'],
    ];
}

function oauth_client(PDO $pdo, string $id): ?array {
    oauth_tables($pdo);
    $q = $pdo->prepare("SELECT * FROM oauth_clients WHERE client_id = ?"); $q->execute([$id]);
    $c = $q->fetch();
    if (!$c) return null;
    $c['redirect_uris'] = json_decode($c['redirect_uris'], true) ?: [];
    return $c;
}

/** Normalizuje požadovaný rozsah; „read“ je vždy. */
function oauth_scope(string $req): array {
    $s = array_values(array_intersect(array_keys(OAUTH_SCOPES), preg_split('/\s+/', trim($req), -1, PREG_SPLIT_NO_EMPTY)));
    if (!$s) $s = array_keys(OAUTH_SCOPES);   // bez scope = vše, co server nabízí
    if (!in_array('read', $s, true)) array_unshift($s, 'read');
    return $s;
}

/** Po souhlasu: vytvoří připojení a jednorázový kód. Vrací kód (surový). */
function oauth_issue_code(PDO $pdo, array $client, string $redirect, string $challenge, array $scope): string {
    oauth_tables($pdo);
    $pdo->prepare("INSERT INTO oauth_grants (client_id, client_name, redirect_host, scope, created_at) VALUES (?,?,?,?,?)")
        ->execute([$client['client_id'], $client['name'], (string)parse_url($redirect, PHP_URL_HOST), implode(' ', $scope), time()]);
    $gid = (int)$pdo->lastInsertId();
    $code = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO oauth_codes (code_hash, grant_id, client_id, redirect_uri, challenge, expires) VALUES (?,?,?,?,?,?)")
        ->execute([hash('sha256', $code), $gid, $client['client_id'], $redirect, $challenge, time() + OAUTH_CODE_TTL]);
    log_line("OAUTH souhlas: připojení #$gid pro „{$client['name']}“ (" . implode(' ', $scope) . ')');
    return $code;
}

function oauth_tokens_for(PDO $pdo, int $gid, string $clientId, string $scope): array {
    $access = 'spa_' . bin2hex(random_bytes(32));
    $refresh = 'spr_' . bin2hex(random_bytes(32));
    $ins = $pdo->prepare("INSERT INTO oauth_tokens (token_hash, kind, grant_id, client_id, expires) VALUES (?,?,?,?,?)");
    $ins->execute([hash('sha256', $access), 'access', $gid, $clientId, time() + OAUTH_ACCESS_TTL]);
    $ins->execute([hash('sha256', $refresh), 'refresh', $gid, $clientId, time() + OAUTH_REFRESH_TTL]);
    $pdo->exec("DELETE FROM oauth_tokens WHERE expires < " . time());
    $pdo->exec("DELETE FROM oauth_codes WHERE expires < " . time());
    return ['access_token' => $access, 'token_type' => 'Bearer', 'expires_in' => OAUTH_ACCESS_TTL, 'refresh_token' => $refresh, 'scope' => $scope];
}

function oauth_grant(PDO $pdo, int $id): ?array {
    $q = $pdo->prepare("SELECT * FROM oauth_grants WHERE id = ? AND revoked_at IS NULL"); $q->execute([$id]);
    return $q->fetch() ?: null;
}

/** Token endpoint (application/x-www-form-urlencoded). */
function oauth_token(PDO $pdo, array $in): array {
    oauth_tables($pdo);
    $grantType = (string)($in['grant_type'] ?? '');
    $clientId = (string)($in['client_id'] ?? '');
    if ($grantType === 'authorization_code') {
        $q = $pdo->prepare("SELECT * FROM oauth_codes WHERE code_hash = ?"); $q->execute([hash('sha256', (string)($in['code'] ?? ''))]);
        $c = $q->fetch();
        if ($c) $pdo->prepare("DELETE FROM oauth_codes WHERE code_hash = ?")->execute([$c['code_hash']]);   // kód jen jednou
        if (!$c || $c['expires'] < time()) oauth_error('invalid_grant', 'Kód je neplatný nebo vypršel.');
        if ($clientId !== '' && $clientId !== $c['client_id']) oauth_error('invalid_grant', 'Kód patří jinému klientovi.');
        if ((string)($in['redirect_uri'] ?? $c['redirect_uri']) !== $c['redirect_uri']) oauth_error('invalid_grant', 'redirect_uri nesouhlasí.');
        $verifier = (string)($in['code_verifier'] ?? '');
        $calc = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if ($verifier === '' || !hash_equals($c['challenge'], $calc)) oauth_error('invalid_grant', 'PKCE: code_verifier nesouhlasí.');
        $g = oauth_grant($pdo, (int)$c['grant_id']);
        if (!$g) oauth_error('invalid_grant', 'Připojení bylo zrušeno.');
        oauth_log_detail("kód vyměněn za token (připojení #{$g['id']} „{$g['client_name']}“)");
        // opakované „Připojit“ ze stejné aplikace: starší připojení téže aplikace zrušit (až teď, kdy nové opravdu funguje)
        $q = $pdo->prepare("SELECT id FROM oauth_grants WHERE revoked_at IS NULL AND id <> ? AND client_name = ? AND redirect_host = ?");
        $q->execute([$g['id'], $g['client_name'], $g['redirect_host']]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $old) { oauth_revoke_grant($pdo, (int)$old); oauth_log_detail("starší připojení #$old zrušeno"); }
        return oauth_tokens_for($pdo, (int)$g['id'], $c['client_id'], $g['scope']);
    }
    if ($grantType === 'refresh_token') {
        $q = $pdo->prepare("SELECT * FROM oauth_tokens WHERE token_hash = ? AND kind = 'refresh'"); $q->execute([hash('sha256', (string)($in['refresh_token'] ?? ''))]);
        $t = $q->fetch();
        if (!$t || $t['expires'] < time()) oauth_error('invalid_grant', 'Refresh token je neplatný nebo vypršel.');
        if ($clientId !== '' && $clientId !== $t['client_id']) oauth_error('invalid_grant', 'Token patří jinému klientovi.');
        $pdo->prepare("DELETE FROM oauth_tokens WHERE token_hash = ?")->execute([$t['token_hash']]);   // rotace
        $g = oauth_grant($pdo, (int)$t['grant_id']);
        if (!$g) oauth_error('invalid_grant', 'Připojení bylo zrušeno.');
        oauth_log_detail("token obnoven (připojení #{$g['id']})");
        return oauth_tokens_for($pdo, (int)$g['id'], $t['client_id'], $g['scope']);
    }
    oauth_error('unsupported_grant_type', 'Podporováno: authorization_code, refresh_token.');
}

/** Hodnota hlavičky Authorization (Apache ji někdy předá jen v REDIRECT_…). */
function oauth_bearer(): string {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($h === '' && function_exists('getallheaders')) {
        foreach ((array)getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) $h = (string)$v;
    }
    return preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : '';
}

/** Ověří přístupový token; vrací grant (s polem scopes), nebo null. */
function oauth_check_access(PDO $pdo, string $token): ?array {
    if ($token === '') return null;
    oauth_tables($pdo);
    $q = $pdo->prepare("SELECT g.* FROM oauth_tokens t JOIN oauth_grants g ON g.id = t.grant_id
                        WHERE t.token_hash = ? AND t.kind = 'access' AND t.expires >= ? AND g.revoked_at IS NULL");
    $q->execute([hash('sha256', $token), time()]);
    $g = $q->fetch();
    if (!$g) return null;
    $pdo->prepare("UPDATE oauth_grants SET last_used = ?, uses = uses + 1 WHERE id = ?")->execute([time(), $g['id']]);
    $g['scopes'] = explode(' ', $g['scope']);
    return $g;
}

function oauth_revoke_grant(PDO $pdo, int $id): void {
    oauth_tables($pdo);
    $pdo->prepare("UPDATE oauth_grants SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL")->execute([time(), $id]);
    $pdo->prepare("DELETE FROM oauth_tokens WHERE grant_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM oauth_codes WHERE grant_id = ?")->execute([$id]);
    log_line("OAUTH připojení #$id odebráno");
}

/** Revokace tokenu klientem (RFC 7009) – zruší celé připojení. */
function oauth_revoke_token(PDO $pdo, string $token): void {
    oauth_tables($pdo);
    $q = $pdo->prepare("SELECT grant_id FROM oauth_tokens WHERE token_hash = ?"); $q->execute([hash('sha256', $token)]);
    if ($gid = $q->fetchColumn()) oauth_revoke_grant($pdo, (int)$gid);
}

function oauth_grants_list(PDO $pdo): array {
    oauth_tables($pdo);
    // nedokončená připojení (souhlas bez vyzvednutí tokenu) a připojení bez platných tokenů zmizí
    $pdo->exec("UPDATE oauth_grants SET revoked_at = " . time() . " WHERE revoked_at IS NULL AND created_at < " . (time() - 600) . "
                AND NOT EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.grant_id = oauth_grants.id AND t.expires >= " . time() . ")
                AND NOT EXISTS (SELECT 1 FROM oauth_codes c WHERE c.grant_id = oauth_grants.id AND c.expires >= " . time() . ")");
    return $pdo->query("SELECT id, client_name, redirect_host, scope, created_at, last_used, uses FROM oauth_grants WHERE revoked_at IS NULL ORDER BY COALESCE(last_used, created_at) DESC")->fetchAll();
}
