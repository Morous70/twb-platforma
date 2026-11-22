<?php
declare(strict_types=1);
// TWB Media galerie 0.7: MCP server (mcp.php) – přihlášení API klíčem i OAuth (souhlas v administraci), nástroje, médium z AI, stránka API a MCP.
require __DIR__ . '/pomocne.php';
require __DIR__ . '/server.php';

echo "Media galerie: MCP\n";

function obrazek(int $w, int $h): string
{
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, (int)imagecolorallocate($im, 200, 120, 30));
    ob_start(); imagepng($im); return (string)ob_get_clean();
}

$s = new TestServer();
register_shutdown_function(fn() => $s->zastav());
file_put_contents($s->config, '<?php $c = ' . var_export((require $s->config), true) . ";\n"
    . '$c["stahovani_http"] = function (string $u): array {'
    . ' \MediaGalerie\ZAdresy::over($u, fn($h) => ["93.184.216.34"]);'
    . ' $im = imagecreatetruecolor(64, 32); ob_start(); imagepng($im); return ["data" => (string)ob_get_clean(), "typ" => "image/png", "nazev" => ""]; };'
    . "\nreturn \$c;\n");
$b = new Prohlizec($s);
$b->jdi('install.php');
$b->jdi('install.php', ['heslo' => 'tajneheslo1', 'heslo2' => 'tajneheslo1']);

/** Požadavek na mcp.php. @return array{code:int, json:mixed, hlavicky:string} */
function mcp(TestServer $s, ?string $token, mixed $zprava, string $metoda = 'POST', array $hl = []): array
{
    $ch = curl_init($s->url . '/mcp.php');
    $h = array_merge(['Content-Type: application/json', 'Accept: application/json, text/event-stream'], $hl, $token !== null ? ['Authorization: Bearer ' . $token] : []);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($zprava !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($zprava) ? $zprava : json_encode($zprava));
    $r = (string)curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($r, $hs);
    if (str_contains($body, 'Fatal error') || str_contains($body, 'Warning:')) throw new RuntimeException('Chyba PHP: ' . mb_substr($body, 0, 500));
    return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($body, true), 'hlavicky' => substr($r, 0, $hs)];
}
/** Zavolá nástroj; vrací structuredContent, nebo text chyby. */
function nastroj(TestServer $s, string $token, string $nazev, array $arg = []): array
{
    $r = mcp($s, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $nazev, 'arguments' => (object)$arg]]);
    if ($r['code'] !== 200) throw new RuntimeException("HTTP {$r['code']}: " . json_encode($r['json']));
    $v = $r['json']['result'];
    return $v['isError'] ? ['_chyba' => $v['content'][0]['text']] : $v['structuredContent'];
}
function klic(Prohlizec $b, string $nazev, string $opravneni): string
{
    $r = $b->jdi('index.php?p=api', ['akce' => 'vytvorit', 'nazev' => $nazev, 'opravneni' => $opravneni]);
    preg_match('/id="novy-klic" readonly value="(tgk_[0-9a-f]{40})"/', $r['body'], $m);
    return $m[1];
}
/** POST formuláře bez cookies (klient OAuth). */
function form(TestServer $s, string $cesta, array $pole): array
{
    $ch = curl_init($s->url . '/' . $cesta);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($pole)]);
    $body = (string)curl_exec($ch);
    return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'json' => json_decode($body, true)];
}

$cteni = klic($b, 'Jen čtení', 'read');
$zapis = klic($b, 'Claude Code', 'write');

test('bez tokenu 401 s odkazem na metadata; metadata OAuth; GET 405, OPTIONS 204', function () use ($s, $b) {
    $r = mcp($s, null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]);
    eq(401, $r['code']);
    ok(preg_match('/^WWW-Authenticate: Bearer resource_metadata="([^"]+)", scope="read write"/mi', $r['hlavicky'], $m) === 1, $r['hlavicky']);
    $z = json_decode($b->jdi($m[1])['body'], true);
    eq([$s->url . '/mcp.php', [$s->url]], [$z['resource'], $z['authorization_servers']]);
    $a = json_decode($b->jdi('.well-known/oauth-authorization-server.php')['body'], true);
    eq([$s->url, $s->url . '/oauth.php?akce=token', ['S256']], [$a['issuer'], $a['token_endpoint'], $a['code_challenge_methods_supported']]);
    eq($z, json_decode($b->jdi('.well-known/oauth-protected-resource.php')['body'], true));
    ok(str_contains(mcp($s, 'tgoa_' . str_repeat('0', 64), ['x' => 1])['hlavicky'], 'error="invalid_token"'));
    eq(401, mcp($s, 'tgk_' . str_repeat('a', 40), ['x' => 1])['code'], 'neplatný API klíč');
    eq(405, mcp($s, $GLOBALS['cteni'], null, 'GET')['code']);
    eq(204, mcp($s, null, null, 'OPTIONS')['code']);
});

test('API klíč: initialize, nástroje podle oprávnění, notifikace 202, DELETE 204', function () use ($s, &$cteni, &$zapis) {
    $r = mcp($s, $zapis, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'test']]]);
    eq([200, '2025-06-18', 'twb-galerie', trim((string)file_get_contents(dirname(__DIR__, 2) . '/apps/twb-galerie/VERSION'))], [$r['code'], $r['json']['result']['protocolVersion'], $r['json']['result']['serverInfo']['name'], $r['json']['result']['serverInfo']['version']]);
    ok(str_contains($r['json']['result']['instructions'], 'nahraj_medium'));
    eq(202, mcp($s, $zapis, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])['code']);
    $vse = array_column(mcp($s, $zapis, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['json']['result']['tools'], 'name');
    eq(['nahraj_medium', 'hledej_media', 'detail_media', 'uprav_medium', 'seznam_kategorii', 'vytvor_kategorii', 'seznam_licenci', 'seznam_tagu'], $vse);
    eq(['hledej_media', 'detail_media', 'seznam_kategorii', 'seznam_licenci', 'seznam_tagu'],
        array_column(mcp($s, $cteni, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])['json']['result']['tools'], 'name'));
    ok(str_contains(nastroj($s, $cteni, 'vytvor_kategorii', ['nazev' => 'X'])['_chyba'], 'oprávnění'), 'klíč pro čtení nezapisuje');
    eq(204, mcp($s, $zapis, null, 'DELETE')['code']);
    eq('2026-07-28', mcp($s, $zapis, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'server/discover'])['json']['result']['supportedVersions'][0]);
});

test('nahraj_medium: base64 s údaji o AI (licence ai, autor = model, prompt v poznámce), kategorie podle názvu', function () use ($s, &$zapis) {
    eq('AI obrázky', nastroj($s, $zapis, 'vytvor_kategorii', ['nazev' => 'AI obrázky'])['kategorie']['nazev']);
    ok(str_contains(nastroj($s, $zapis, 'vytvor_kategorii', ['nazev' => 'ai obrázky'])['_chyba'], 'Chyba'), 'duplicitní');
    $png = obrazek(300, 200);
    $r = nastroj($s, $zapis, 'nahraj_medium', ['data_base64' => 'data:image/png;base64,' . base64_encode($png), 'nazev' => 'Podzimní les', 'tagy' => ['podzim', 'les'],
        'kategorie' => 'ai OBRÁZKY', 'ai_model' => 'GPT Image 1', 'ai_prompt' => 'Mlhavý podzimní les při východu slunce']);
    $m = $r['medium'] ?? null;
    ok($m !== null, json_encode($r, JSON_UNESCAPED_UNICODE));
    eq(['Podzimní les.png', 'image', '300×200', 'AI obrázky', ['podzim', 'les'], 'GPT Image 1', 'Vygenerováno AI'],
        [$m['nazev'], $m['druh'], $m['rozmer'], $m['kategorie'], $m['tagy'], $m['autor'], $m['licence']]);
    ok(str_contains((string)$m['poznamka'], 'Vytvořeno AI (GPT Image 1).') && str_contains((string)$m['poznamka'], 'Prompt: Mlhavý podzimní les'), (string)$m['poznamka']);
    ok(str_contains((string)$m['verejny_odkaz'], '/soubor.php/') && str_contains((string)$m['nahled'], '/w640/') && str_contains($m['stranka_url'], 'p=medium&id='));
    eq(md5($png), $s->db()->query('SELECT md5 FROM media WHERE id = ' . (int)$m['id'])->fetchColumn());
    eq([], glob($s->data . '/data/tmp/mcp-*') ?: [], 'dočasný soubor uklizený');
    ok(str_contains((string)$s->db()->query("SELECT message FROM log WHERE message LIKE 'MCP (klíč Claude Code): nahraj_medium%'")->fetchColumn(), 'nahraj_medium'), 'protokol s klientem');
});

test('nahraj_medium: z adresy, bez přípony podle typu, chyby se nic neuloží', function () use ($s, &$zapis) {
    $pred = (int)$s->db()->query('SELECT COUNT(*) FROM media')->fetchColumn();
    $m = nastroj($s, $zapis, 'nahraj_medium', ['url' => 'https://example.com/obrazky/vygenerovano', 'licence_kod' => 'cc0'])['medium'];
    eq(['vygenerovano.png', '64×32', 'CC0 (volné dílo)', null], [$m['nazev'], $m['rozmer'], $m['licence'], $m['autor']]);
    $m = nastroj($s, $zapis, 'nahraj_medium', ['data_base64' => base64_encode(obrazek(20, 20))])['medium'];
    ok(preg_match('/^ai-\d{4}-\d\d-\d\d-\d{6}\.png$/', $m['nazev']) === 1, $m['nazev']);
    // klient udá obecný typ a název bez přípony: rozhodne obsah
    $m = nastroj($s, $zapis, 'nahraj_medium', ['data_base64' => base64_encode(obrazek(20, 20)), 'mime' => 'application/octet-stream', 'nazev' => 'Čelní pohled 30°'])['medium'];
    eq('Čelní pohled 30°.png', $m['nazev']);
    $pred += 3;
    foreach ([
        [[], 'právě jeden zdroj'],
        [['url' => 'https://example.com/a.png', 'data_base64' => 'AAAA'], 'právě jeden zdroj'],
        [['data_base64' => '###'], 'base64'],
        [['data_base64' => base64_encode('<?php echo 1;'), 'nazev' => 'x.php'], 'Nepodporovaný typ'],
        [['data_base64' => base64_encode('jen text')], 'Nepodporovaný typ'],
        [['url' => 'http://127.0.0.1/config.php'], 'vnitřní sít'],
        [['url' => 'https://example.com/a.png', 'kategorie' => 'Neexistuje'], 'neexistuje'],
        [['url' => 'https://example.com/a.png', 'licence_kod' => 'mit'], 'Neznámý kód licence'],
        [['url' => 'https://example.com/a.png', 'licence_url' => 'ftp://x'], 'licence_url'],
    ] as [$arg, $text]) {
        $r = nastroj($s, $zapis, 'nahraj_medium', $arg);
        ok(str_contains($r['_chyba'] ?? '', $text), json_encode($arg) . ' → ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    }
    eq($pred, (int)$s->db()->query('SELECT COUNT(*) FROM media')->fetchColumn(), 'při chybě nic nepřibylo');
});

test('hledej_media, detail_media (id i odkaz), uprav_medium, seznamy', function () use ($s, &$zapis, &$cteni) {
    $r = nastroj($s, $cteni, 'hledej_media', ['q' => 'mlhavy']);
    eq([1, 'Podzimní les.png'], [$r['celkem'], $r['media'][0]['nazev'] ?? null], 'hledání v promptu bez diakritiky');
    eq(4, nastroj($s, $cteni, 'hledej_media')['celkem']);
    eq(1, nastroj($s, $cteni, 'hledej_media', ['kategorie' => 'AI obrázky', 'tagy' => ['les']])['celkem']);
    eq(2, nastroj($s, $cteni, 'hledej_media', ['na_stranku' => 2])['stran']);
    ok(isset(nastroj($s, $cteni, 'hledej_media', ['na_stranku' => 51])['_chyba']));
    $id = nastroj($s, $cteni, 'hledej_media', ['q' => 'les'])['media'][0]['id'];
    $d = nastroj($s, $cteni, 'detail_media', ['id' => $id])['medium'];
    eq(['GPT Image 1', 'ai'], [$d['autor'], $d['licence_kod']]);
    ok(isset($d['nahledy']['1920']) && $d['atribuce']['povinne'] === false);
    eq($id, nastroj($s, $cteni, 'detail_media', ['odkaz' => $d['verejny_odkaz']])['medium']['id']);
    ok(str_contains(nastroj($s, $cteni, 'detail_media', ['id' => 999])['_chyba'], 'nenalezen'));
    ok(str_contains(nastroj($s, $cteni, 'detail_media', [])['_chyba'], 'Zadejte id'));
    $u = nastroj($s, $zapis, 'uprav_medium', ['id' => $id, 'nazev' => 'Les v mlze.png', 'tagy' => ['mlha'], 'kategorie' => null, 'autor' => null])['medium'];
    eq(['Les v mlze.png', ['mlha'], null, null, 'Vygenerováno AI'], [$u['nazev'], $u['tagy'], $u['kategorie'], $u['autor'], $u['licence']]);
    ok(str_contains(nastroj($s, $zapis, 'uprav_medium', ['id' => $id, 'barva' => 'x'])['_chyba'], 'Neznámé pole'));
    ok(str_contains(nastroj($s, $zapis, 'uprav_medium', ['id' => $id])['_chyba'], 'aspoň jedno pole'));
    eq('AI obrázky', nastroj($s, $cteni, 'seznam_kategorii')['kategorie'][0]['nazev']);
    ok(in_array('ai', array_column(nastroj($s, $cteni, 'seznam_licenci')['licence'], 'kod'), true));
    ok(in_array('mlha', array_column(nastroj($s, $cteni, 'seznam_tagu')['tagy'], 'nazev'), true));
});

test('OAuth: registrace, souhlas po přihlášení, výměna kódu, volání MCP tokenem, připojení na stránce API a odebrání', function () use ($s, $b) {
    $reg = (function () use ($s) {
        $ch = curl_init($s->url . '/oauth.php?akce=register');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['client_name' => 'Claude', 'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']])]);
        return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: 0, json_decode((string)curl_exec($ch), true), (int)curl_getinfo($ch, CURLINFO_HTTP_CODE)];
    })();
    eq(201, $reg[2]);
    $klient = $reg[1]['client_id'];
    $ver = bin2hex(random_bytes(32));
    $ch = rtrim(strtr(base64_encode(hash('sha256', $ver, true)), '+/', '-_'), '=');
    $q = 'oauth.php?' . http_build_query(['akce' => 'authorize', 'client_id' => $klient, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code',
        'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'state' => 'stav-1', 'scope' => 'read write', 'resource' => $s->url . '/mcp.php']);

    // nepřihlášený: na přihlášení a po něm zpět na souhlas
    $n = new Prohlizec($s);
    $r = $n->jdi($q);
    eq([303, 'index.php'], [$r['code'], $r['location']]);
    $n->jdi('index.php');
    $r = $n->jdi('index.php', ['akce' => 'prihlasit', 'heslo' => 'tajneheslo1']);
    eq(303, $r['code']);
    $r = $n->jdi((string)$r['location']);
    eq([303, $q], [$r['code'], $r['location']], 'po přihlášení zpět na souhlas');
    $r = $n->jdi($q);
    ok(str_contains($r['body'], 'Povolit přístup?') && str_contains($r['body'], '<strong>Claude</strong> chce') && str_contains($r['body'], 'claude.ai'), mb_substr(strip_tags($r['body']), 0, 400));

    // zamítnutí
    $pole = ['client_id' => $klient, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'response_type' => 'code', 'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'state' => 'stav-1', 'scope' => 'read write'];
    $r = $n->jdi('oauth.php?akce=authorize', $pole + ['rozhodnuti' => 'zamitnout']);
    ok(str_starts_with((string)$r['location'], 'https://claude.ai/api/mcp/auth_callback?error=access_denied') && str_contains((string)$r['location'], 'state=stav-1'));
    // bez CSRF se nepovolí
    $r = $n->jdi('oauth.php?akce=authorize', $pole + ['rozhodnuti' => 'povolit', 'povolit_zapis' => '1', 'csrf' => 'spatne']);
    ok(!str_contains((string)$r['location'], 'code='), 'bez CSRF žádný kód');
    // povolení se zápisem
    $n->jdi($q);
    $r = $n->jdi('oauth.php?akce=authorize', $pole + ['rozhodnuti' => 'povolit', 'povolit_zapis' => '1']);
    ok(preg_match('/^https:\/\/claude\.ai\/api\/mcp\/auth_callback\?code=([0-9a-f]{64})&state=stav-1&iss=/', (string)$r['location'], $m) === 1, (string)$r['location']);
    $t = form($s, 'oauth.php?akce=token', ['grant_type' => 'authorization_code', 'code' => $m[1], 'client_id' => $klient, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_verifier' => $ver]);
    eq([200, 'read write'], [$t['code'], $t['json']['scope'] ?? null], json_encode($t['json']));
    eq(['invalid_grant', 400], [form($s, 'oauth.php?akce=token', ['grant_type' => 'authorization_code', 'code' => $m[1], 'code_verifier' => $ver])['json']['error'], 400]);
    $token = $t['json']['access_token'];
    eq(8, count(mcp($s, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])['json']['result']['tools']));
    eq('Z Claude', nastroj($s, $token, 'vytvor_kategorii', ['nazev' => 'Z Claude'])['kategorie']['nazev']);
    // obnova tokenu
    $t2 = form($s, 'oauth.php?akce=token', ['grant_type' => 'refresh_token', 'refresh_token' => $t['json']['refresh_token']]);
    ok(isset($t2['json']['access_token']));
    eq(200, mcp($s, $t2['json']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['code'], 'nový token platí');

    // stránka API a MCP: adresa, připojení, odebrání
    $r = $b->jdi('index.php?p=api');
    ok(str_contains($r['body'], 'id="adresa-mcp" readonly value="' . $s->url . '/mcp.php"') && str_contains($r['body'], '<strong>Claude</strong>') && str_contains($r['body'], 'Čtení i nahrávání'));
    $gid = (int)$s->db()->query('SELECT id FROM oauth_grants WHERE revoked_at IS NULL')->fetchColumn();
    $r = $b->odesli('index.php?p=api', ['akce' => 'odebrat-pripojeni', 'id' => $gid]);
    ok(str_contains($r['body'], 'Připojení odebráno') && str_contains($r['body'], 'Zatím žádné připojení'));
    eq(401, mcp($s, $t2['json']['access_token'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['code'], 'po odebrání neplatí');
    // neznámý klient: chybová stránka bez přesměrování
    $r = $n->jdi('oauth.php?akce=authorize&client_id=neni&redirect_uri=https%3A%2F%2Fzlo.example%2F');
    eq([400, null], [$r['code'], $r['location']]);
    ok(str_contains($r['body'], 'Neznámý klient'));
    // připojení nejsou v záloze
    ok(!in_array('oauth_grants', MediaGalerie\App::TABULKY, true));
});

test('nápověda MCP: stránka, odkazy, zmínka na stránce API', function () use ($b) {
    $r = $b->jdi('index.php?p=napoveda&s=mcp');
    ok(str_contains($r['body'], 'Připojení AI (MCP)') && str_contains($r['body'], 'nahraj_medium') && str_contains($r['body'], 'Přidat vlastní konektor'));
    $app = novaApp();
    eq([], $app->napoveda()->zkontroluj()[0]);
    // nástroje v nápovědě odpovídají serveru
    $md = (string)file_get_contents(KOREN . '/apps/twb-galerie/help/sprava/mcp.md');
    foreach (array_keys($app->mcp()->server()->nastroje()) as $n) ok(str_contains($md, "`{$n}`"), "nápověda zmiňuje {$n}");
});
