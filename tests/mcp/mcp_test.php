<?php
declare(strict_types=1);
// Modul mcp: protokol MCP (JSON-RPC, generace s initialize i bezstavová 2026-07-28, oprávnění, chyby nástrojů) a OAuth 2.1 server.
require dirname(__DIR__) . '/lib.php';

use TWB\Jadro\{Migrace, ProtokolPamet};
use TWB\Mcp\{Server, Nastroj, ChybaNastroje, OAuth, ChybaOAuth};

echo "Modul mcp\n";

function server(?ProtokolPamet $p = null): Server
{
    return new Server('test', 'Test', '1.2.3', 'Instrukce.', [
        new Nastroj('cti', 'Čti', 'Vrátí argumenty.', ['type' => 'object', 'properties' => []], fn(array $a, array $k) => ['a' => $a, 'klient' => $k['klient']]),
        new Nastroj('zapis', 'Zápis', 'Zapíše.', ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]], fn(array $a) => ['ok' => true], 'write', zapis: true),
        new Nastroj('chyba', 'Chyba', 'Chyba pro uživatele.', ['type' => 'object'], fn() => throw new ChybaNastroje('Špatný vstup.')),
        new Nastroj('pad', 'Pád', 'Chyba serveru.', ['type' => 'object'], fn() => throw new \PDOException('SQL tajné')),
    ], $p);
}
function rpc(Server $s, array $msg, array $opr = ['read', 'write'], string $hdr = ''): array { return $s->zpracuj($msg + ['jsonrpc' => '2.0'], $opr, 'Claude', $hdr); }

test('Nastroj: kontrola názvu a schématu, anotace', function () {
    throws(fn() => new Nastroj('Špatně', 'x', 'x', ['type' => 'object'], fn() => []), 'Neplatný název');
    throws(fn() => new Nastroj('ok', 'x', 'x', ['type' => 'array'], fn() => []), 'object');
    $p = server()->nastroje()['zapis']->popisProKlienta();
    eq(['zapis', false, false], [$p['name'], $p['annotations']['readOnlyHint'], $p['annotations']['destructiveHint']]);
    ok(server()->nastroje()['cti']->popisProKlienta()['inputSchema']['properties'] instanceof stdClass, 'prázdné properties jako objekt');
    throws(fn() => new Server('a', 'A', '1', '', [new Nastroj('x', 'x', 'x', ['type' => 'object'], fn() => []), new Nastroj('x', 'x', 'x', ['type' => 'object'], fn() => [])]), 'dvakrát');
});

test('initialize: dohodnutá verze, serverInfo, instrukce; neznámá verze → nejnovější', function () {
    [$st, $r] = rpc(server(), ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']]);
    eq([200, '2025-06-18', 'test', '1.2.3', 'Instrukce.'], [$st, $r['result']['protocolVersion'], $r['result']['serverInfo']['name'], $r['result']['serverInfo']['version'], $r['result']['instructions']]);
    eq(Server::VERZE[0], rpc(server(), ['id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])[1]['result']['protocolVersion']);
    eq([202, null], rpc(server(), ['method' => 'notifications/initialized']));
    eq([400, -32600], (fn($x) => [$x[0], $x[1]['error']['code']])(server()->zpracujTelo('{"jsonrpc":"1.0","id":1,"method":"ping"}', ['read'])));
});

test('tools/list podle oprávnění; tools/call: výsledek, chyba pro uživatele, chyba serveru do protokolu', function () {
    $p = new ProtokolPamet();
    $s = server($p);
    eq(['cti', 'chyba', 'pad'], array_column(rpc($s, ['id' => 1, 'method' => 'tools/list'], ['read'])[1]['result']['tools'], 'name'));
    eq(4, count(rpc($s, ['id' => 1, 'method' => 'tools/list'])[1]['result']['tools']));
    $r = rpc($s, ['id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'cti', 'arguments' => ['x' => 'ž']]])[1]['result'];
    eq([false, ['x' => 'ž'], 'Claude'], [$r['isError'], $r['structuredContent']['a'], $r['structuredContent']['klient']]);
    ok(str_contains($r['content'][0]['text'], '"ž"'), 'text JSON bez escapování');
    $r = rpc($s, ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'zapis', 'arguments' => []]], ['read'])[1]['result'];
    ok($r['isError'] && str_contains($r['content'][0]['text'], 'oprávnění „write“'), 'bez oprávnění');
    eq([], $p->zaznamy, 'nezapsáno');
    rpc($s, ['id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'zapis']]);
    eq([['info', 'MCP (Claude): zapis']], $p->zaznamy);
    eq('Chyba: Špatný vstup.', rpc($s, ['id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'chyba']])[1]['result']['content'][0]['text']);
    $r = rpc($s, ['id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'pad']])[1]['result'];
    ok($r['isError'] && !str_contains($r['content'][0]['text'], 'SQL'), 'chyba serveru bez podrobností');
    ok(str_contains($p->zaznamy[1][1], 'SQL tajné'), 'podrobnost v protokolu');
    eq(-32602, rpc($s, ['id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'neni']])[1]['error']['code']);
    eq([200, -32601], [rpc($s, ['id' => 7, 'method' => 'neni'])[0], rpc($s, ['id' => 7, 'method' => 'neni'])[1]['error']['code']]);
    ok(rpc($s, ['id' => 8, 'method' => 'ping'])[1]['result'] instanceof stdClass, 'ping');
});

test('tělo: neplatný JSON, dávka, jen notifikace', function () {
    $s = server();
    [$st, $r] = $s->zpracujTelo('{x', ['read']);
    eq([400, -32700], [$st, $r['error']['code']]);
    [$st, $r] = $s->zpracujTelo('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]', ['read']);
    eq([200, [1, 2]], [$st, array_column($r, 'id')]);
    eq([202, null], $s->zpracujTelo('{"jsonrpc":"2.0","method":"notifications/initialized"}', ['read']));
    eq(400, $s->zpracujTelo('{"jsonrpc":"2.0","id":1}', ['read'])[0]);
});

test('bezstavová verze 2026-07-28: server/discover, resultType, kontrola verze a hlavičky, 404 neznámé metody', function () {
    $s = server();
    $r = rpc($s, ['id' => 1, 'method' => 'server/discover'])[1]['result'];
    eq(['complete', Server::MODERNI[0]], [$r['resultType'], $r['supportedVersions'][0]]);
    $meta = ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
    eq('complete', rpc($s, ['id' => 2, 'method' => 'tools/list', 'params' => $meta])[1]['result']['resultType']);
    eq('complete', rpc($s, ['id' => 2, 'method' => 'tools/list'], hdr: '2026-07-28')[1]['result']['resultType']);
    ok(!isset(rpc($s, ['id' => 2, 'method' => 'tools/list'], hdr: '2025-06-18')[1]['result']['resultType']), 'starší hlavička = starší generace');
    eq([400, -32020], (fn($x) => [$x[0], $x[1]['error']['code']])(rpc($s, ['id' => 3, 'method' => 'tools/list', 'params' => $meta], hdr: '2026-08-01')));
    eq([400, -32022], (fn($x) => [$x[0], $x[1]['error']['code']])(rpc($s, ['id' => 3, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2027-01-01']]])));
    eq([404, -32601], (fn($x) => [$x[0], $x[1]['error']['code']])(rpc($s, ['id' => 4, 'method' => 'neni', 'params' => $meta])));
    eq(400, rpc($s, ['id' => 5, 'method' => 'tools/call', 'params' => $meta + ['name' => 'neni']])[0]);
});

// ---------- OAuth ----------

function oauth(int &$cas, ?ProtokolPamet $p = null): OAuth
{
    $h = novyHost();
    (new Migrace($h->db))->spust('mcp', \TWB\Mcp\Migrace::class);
    return new OAuth($h->db, $p ?? new ProtokolPamet(), 'https://g.example', 'https://g.example/mcp.php',
        ['authorize' => 'https://g.example/oauth.php?akce=authorize', 'token' => 'https://g.example/oauth.php?akce=token', 'register' => 'https://g.example/r', 'revoke' => 'https://g.example/v'],
        ['read' => 'Číst', 'write' => 'Zapisovat'], 'Galerie', 'tgo', 'https://g.example/napoveda', function () use (&$cas) { return $cas; });
}
function pkce(): array { $v = bin2hex(random_bytes(32)); return [$v, rtrim(strtr(base64_encode(hash('sha256', $v, true)), '+/', '-_'), '=')]; }

test('OAuth: metadata a výzva 401', function () {
    $cas = 1_000_000;
    $o = oauth($cas);
    $m = $o->metadataServeru();
    eq(['https://g.example', ['S256'], ['none'], ['read', 'write', 'offline_access']], [$m['issuer'], $m['code_challenge_methods_supported'], $m['token_endpoint_auth_methods_supported'], $m['scopes_supported']]);
    $z = $o->metadataZdroje();
    eq(['https://g.example/mcp.php', ['https://g.example'], 'Galerie'], [$z['resource'], $z['authorization_servers'], $z['resource_name']]);
    eq('Bearer resource_metadata="https://g.example/m", scope="read write"', $o->vyzva('https://g.example/m'));
    ok(str_contains($o->vyzva('x', true), 'error="invalid_token"'));
    eq('abc', OAuth::bearer(['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer abc']));
    eq('abc', OAuth::bearer([], ['authorization' => 'bearer abc']));
    eq('', OAuth::bearer(['HTTP_AUTHORIZATION' => 'Basic abc']));
});

test('OAuth: registrace – návratové adresy, jen veřejní klienti, jméno', function () {
    $cas = 1_000_000;
    $o = oauth($cas);
    $k = $o->registruj(['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'], 'client_name' => "Claude\x01"]);
    ok(str_starts_with($k['client_id'], 'tgoc_') && $k['client_name'] === 'Claude' && $k['token_endpoint_auth_method'] === 'none');
    eq(['https://claude.ai/api/mcp/auth_callback'], $o->klient($k['client_id'])['redirect_uris']);
    ok($o->registruj(['redirect_uris' => ['http://localhost:3333/cb']])['client_name'] === 'MCP klient');
    foreach ([[], ['http://example.com/cb'], ['https://x.example/cb#f'], ['javascript:alert(1)'], ['https://u:p@x.example/']] as $u) {
        eq('invalid_redirect_uri', (function () use ($o, $u) { try { $o->registruj(['redirect_uris' => $u]); } catch (ChybaOAuth $e) { return $e->kod; } return null; })(), show($u));
    }
    throws(fn() => $o->registruj(['redirect_uris' => ['https://x.example/'], 'token_endpoint_auth_method' => 'client_secret_basic']), 'veřejní');
    eq(null, $o->klient('neni'));
});

test('OAuth: souhlas, výměna kódu s PKCE (jednou), obnova s rotací, zrušení', function () {
    $cas = 1_000_000;
    $p = new ProtokolPamet();
    $o = oauth($cas, $p);
    $k = $o->registruj(['redirect_uris' => ['https://claude.ai/cb'], 'client_name' => 'Claude']);
    [$ver, $ch] = pkce();
    $pozadavek = ['client_id' => $k['client_id'], 'redirect_uri' => 'https://claude.ai/cb', 'response_type' => 'code', 'code_challenge' => $ch, 'code_challenge_method' => 'S256', 'scope' => 'read write'];
    [$klient, $navrat, $vyzva, $rozsah] = $o->overPozadavek($pozadavek);
    eq(['https://claude.ai/cb', $ch, ['read', 'write']], [$navrat, $vyzva, $rozsah]);
    eq(['read', 'write'], $o->rozsah('write'), 'čtení se přidá vždy');
    eq(['read', 'write'], $o->rozsah(''));
    eq(['read'], $o->rozsah('read neznamy'));
    // chyby požadavku
    $kod = fn(array $x) => (function () use ($o, $x) { try { $o->overPozadavek($x); } catch (ChybaOAuth $e) { return [$e->kod, $e->http]; } return null; })();
    eq(['invalid_client', 400], $kod(['client_id' => 'neni'] + $pozadavek));
    eq(['invalid_request', 400], $kod(['redirect_uri' => 'https://zlo.example/cb'] + $pozadavek));
    eq(['unsupported_response_type', 302], $kod(['response_type' => 'token'] + $pozadavek));
    eq(['invalid_request', 302], $kod(['code_challenge_method' => 'plain'] + $pozadavek));

    $c = $o->vydejKod($klient, $navrat, $vyzva, ['read']);
    eq('https://claude.ai/cb?code=' . $c . '&state=s1&iss=https%3A%2F%2Fg.example', $o->navrat($navrat, ['code' => $c, 'state' => 's1']));
    $chyba = fn(array $x) => (function () use ($o, $x) { try { $o->token($x); } catch (ChybaOAuth $e) { return $e->getMessage(); } return null; })();
    $zakl = ['grant_type' => 'authorization_code', 'code' => $c, 'client_id' => $k['client_id'], 'redirect_uri' => 'https://claude.ai/cb'];
    ok(str_contains((string)$chyba($zakl + ['code_verifier' => 'spatny']), 'PKCE'), 'špatný verifier');
    ok(str_contains((string)$chyba($zakl + ['code_verifier' => $ver]), 'neplatný'), 'kód po chybě už neplatí (jen jednou)');

    $c = $o->vydejKod($klient, $navrat, $vyzva, ['read', 'write']);
    $t = $o->token(['code' => $c, 'code_verifier' => $ver] + $zakl);
    ok(str_starts_with($t['access_token'], 'tgoa_') && str_starts_with($t['refresh_token'], 'tgor_') && $t['scope'] === 'read write' && $t['expires_in'] === 3600);
    ok(str_contains((string)$chyba(['code' => $c, 'code_verifier' => $ver] + $zakl), 'neplatný'), 'kód jen jednou');
    eq(['read', 'write'], $o->over($t['access_token'])['scopes']);
    ok($o->jeToken($t['access_token']) && !$o->jeToken('tgk_x'));
    eq(null, $o->over($t['access_token'] . 'x'));
    // jen jedno platné připojení (první kód s chybou se nepočítá – nemá tokeny)
    $cas += 601;
    eq(['Claude'], array_column($o->pripojeni(), 'client_name'));
    eq(1, $o->pripojeni()[0]['uses']);

    // obnova s rotací
    $t2 = $o->token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
    ok($t2['access_token'] !== $t['access_token']);
    ok(str_contains((string)$chyba(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]), 'Refresh'), 'starý refresh neplatí');
    ok(str_contains((string)$chyba(['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token'], 'client_id' => 'jiny']), 'jinému'));
    ok(str_contains((string)$chyba(['grant_type' => 'password']), 'Podporováno'));
    // vypršení přístupového tokenu
    $cas += 3601;
    eq(null, $o->over($t2['access_token']));
    $t3 = $o->token(['grant_type' => 'refresh_token', 'refresh_token' => $t2['refresh_token']]);
    ok($o->over($t3['access_token']) !== null);
    // nové připojení téže aplikace zruší staré
    $c = $o->vydejKod($klient, $navrat, $vyzva, ['read']);
    $t4 = $o->token(['code' => $c, 'code_verifier' => $ver] + $zakl);
    eq(null, $o->over($t3['access_token']), 'staré připojení zrušené');
    eq([['read']], array_map(fn($g) => explode(' ', $g['scope']), $o->pripojeni()));
    // zrušení tokenem (RFC 7009) i z administrace
    $o->zrusToken($t4['refresh_token']);
    eq([null, []], [$o->over($t4['access_token']), $o->pripojeni()]);
    ok(in_array(['info', 'OAuth: odebráno připojení „Claude“.'], $p->zaznamy, true));
    ok(!str_contains(json_encode($p->zaznamy), $t4['access_token']), 'tokeny nejsou v protokolu');
});

test('OAuth: kód vyprší po 5 minutách; tokeny jen jako otisk', function () {
    $cas = 1_000_000;
    $o = oauth($cas);
    $k = $o->registruj(['redirect_uris' => ['https://claude.ai/cb']]);
    [$ver, $ch] = pkce();
    $c = $o->vydejKod($o->klient($k['client_id']), 'https://claude.ai/cb', $ch, ['read']);
    $cas += 301;
    throws(fn() => $o->token(['grant_type' => 'authorization_code', 'code' => $c, 'code_verifier' => $ver]), 'vypršel');
    $c = $o->vydejKod($o->klient($k['client_id']), 'https://claude.ai/cb', $ch, ['read', 'neznamy']);
    $t = $o->token(['grant_type' => 'authorization_code', 'code' => $c, 'code_verifier' => $ver]);
    eq('read', $t['scope'], 'neznámý rozsah se zahodí');
    $r = new ReflectionProperty($o, 'db');
    $vse = json_encode($r->getValue($o)->q('SELECT * FROM oauth_tokens')->fetchAll()) . json_encode($r->getValue($o)->q('SELECT * FROM oauth_codes')->fetchAll());
    ok(!str_contains($vse, $t['access_token']) && !str_contains($vse, $t['refresh_token']) && str_contains($vse, hash('sha256', $t['access_token'])));
});
