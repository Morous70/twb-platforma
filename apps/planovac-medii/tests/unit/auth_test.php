<?php
// Přihlášení (verze 1.8.0): omezení pokusů, jednorázové tokeny, obnova hesla, Google, Passkey (WebAuthn).
require dirname(__DIR__) . '/lib.php';
require_once APP_DIR . '/lib/auth.php';

$B = getenv('BASE_URL');

// ---------- pomůcky: CBOR a „zařízení“ s Passkey ----------
function cb_head(int $major, int $n): string {
    if ($n < 24) return chr($major << 5 | $n);
    if ($n < 256) return chr($major << 5 | 24) . chr($n);
    if ($n < 65536) return chr($major << 5 | 25) . pack('n', $n);
    return chr($major << 5 | 26) . pack('N', $n);
}
function cb_int(int $n): string { return $n >= 0 ? cb_head(0, $n) : cb_head(1, -1 - $n); }
function cb_bytes(string $s): string { return cb_head(2, strlen($s)) . $s; }
function cb_text(string $s): string { return cb_head(3, strlen($s)) . $s; }
function cb_map(array $pairs): string { $o = cb_head(5, count($pairs)); foreach ($pairs as [$k, $v]) $o .= $k . $v; return $o; }

final class FakeKey {
    public $pkey; public string $credId; public int $counter = 0; public bool $rsa;
    public function __construct(bool $rsa = false) {
        $this->rsa = $rsa;
        $this->pkey = $rsa ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
                           : openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->credId = random_bytes(32);
    }
    private function cose(): string {
        $d = openssl_pkey_get_details($this->pkey);
        if ($this->rsa) return cb_map([[cb_int(1), cb_int(3)], [cb_int(3), cb_int(-257)], [cb_int(-1), cb_bytes($d['rsa']['n'])], [cb_int(-2), cb_bytes($d['rsa']['e'])]]);
        return cb_map([[cb_int(1), cb_int(2)], [cb_int(3), cb_int(-7)], [cb_int(-1), cb_int(1)],
            [cb_int(-2), cb_bytes(str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT))], [cb_int(-3), cb_bytes(str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT))]]);
    }
    private function authData(array $o, bool $attested): string {
        $flags = $o['flags'] ?? (0x01 | 0x04 | ($attested ? 0x40 : 0));
        $ad = hash('sha256', $o['rp'] ?? wa_rp_id(), true) . chr($flags) . pack('N', $o['counter'] ?? $this->counter);
        if ($attested) $ad .= str_repeat("\0", 16) . pack('n', strlen($this->credId)) . $this->credId . $this->cose();
        return $ad;
    }
    private function clientData(string $type, string $challenge, array $o): string {
        return json_encode(['type' => $type, 'challenge' => $o['challenge'] ?? $challenge, 'origin' => $o['origin'] ?? wa_origin(), 'crossOrigin' => false]);
    }
    public function create(string $challenge, array $o = []): array {
        $att = cb_map([[cb_text('fmt'), cb_text('none')], [cb_text('attStmt'), cb_map([])], [cb_text('authData'), cb_bytes($this->authData($o, true))]]);
        return ['id' => auth_b64u_enc($this->credId), 'rawId' => auth_b64u_enc($this->credId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => auth_b64u_enc($this->clientData('webauthn.create', $challenge, $o)), 'attestationObject' => auth_b64u_enc($att)]];
    }
    public function get(string $challenge, array $o = []): array {
        $this->counter = $o['counter'] ?? $this->counter + 1;
        $ad = $this->authData($o + ['counter' => $this->counter], false);
        $cd = $this->clientData('webauthn.get', $challenge, $o);
        openssl_sign($ad . hash('sha256', $cd, true), $sig, $this->pkey, OPENSSL_ALGO_SHA256);
        if (!empty($o['badsig'])) $sig[strlen($sig) - 1] = chr(ord($sig[strlen($sig) - 1]) ^ 1);
        return ['id' => auth_b64u_enc($this->credId), 'rawId' => auth_b64u_enc($this->credId), 'type' => 'public-key',
            'response' => ['clientDataJSON' => auth_b64u_enc($cd), 'authenticatorData' => auth_b64u_enc($ad), 'signature' => auth_b64u_enc($sig), 'userHandle' => null]];
    }
}
function register_key(FakeKey $k, string $name = 'Zkouška', array $o = []): int {
    $ch = wa_register_options()['publicKey']['challenge'];
    return wa_register_verify($k->create($ch, $o), $name);
}
function login_assert(FakeKey $k, array $o = []): array {
    $ch = wa_login_options()['publicKey']['challenge'];
    return wa_login_verify($k->get($ch, $o));
}

// ---------- tokeny a omezení pokusů ----------
test('token: v databázi je jen otisk, platí jednou a jen do vypršení', function () {
    $t = auth_token_create('reset', 60);
    ok(!q('SELECT 1 FROM auth_tokens WHERE token_hash = ?', [$t])->fetchColumn(), 'surový token se nesmí ukládat');
    ok(auth_token_valid('reset', $t));
    ok(!auth_token_valid('jiny', $t), 'jiný účel');
    ok(auth_token_use('reset', $t));
    ok(!auth_token_use('reset', $t), 'podruhé nejde');
    ok(!auth_token_valid('reset', 'nesmysl'));
    $e = auth_token_create('reset', 60);
    q('UPDATE auth_tokens SET expires = ? WHERE token_hash = ?', [time() - 1, hash('sha256', $e)]);
    ok(!auth_token_use('reset', $e), 'vypršel');
});

test('omezení pokusů: po 5 chybách zámek, vypršení a nulování', function () {
    set_setting('login_fails', '0');
    eq(0, auth_lock_remaining());
    for ($i = 0; $i < 4; $i++) auth_register_fail('zkouška');
    eq(0, auth_lock_remaining(), 'po 4 chybách ještě ne');
    auth_register_fail('zkouška');
    ok(auth_lock_remaining() > 280, 'zámek po 5. chybě');
    contains('Zkuste to za', auth_lock_message());
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE '%dočasně zablokováno%'")->fetchColumn(), 'záznam v Protokolu');
    set_setting('login_last_fail', (string)(time() - 301));
    eq(0, auth_lock_remaining(), 'zámek po 5 minutách vyprší');
    auth_register_fail('zkouška');
    eq(0, auth_lock_remaining(), 'po vypršení se počítá znovu od jedné');
    set_setting('login_fails', '0');
});

// ---------- obnova hesla ----------
function mails(): array { $m = glob(DATA_DIR . '/tmp/mail-*.txt') ?: []; sort($m); return $m; }
function clear_mails(): void { foreach (mails() as $f) @unlink($f); }
function reset_link(): string { preg_match('~\?reset=([0-9a-f]{64})~', file_get_contents(mails()[count(mails()) - 1]), $m); return $m[1] ?? ''; }

test('obnova hesla: špatná nebo chybějící adresa nic neodešle', function () {
    clear_mails();
    set_setting('notify_email', '');
    set_setting('reset_last_sent', '0');
    auth_reset_request('kdo@example.com');
    eq(0, count(mails()), 'bez nastaveného e-mailu');
    set_setting('notify_email', 'majitel@example.com');
    set_setting('reset_last_sent', '0');
    auth_reset_request('jiny@example.com');
    eq(0, count(mails()), 'jiná adresa');
});

test('obnova hesla: odkaz v e-mailu, nové heslo, odkaz jde použít jen jednou', function () {
    clear_mails();
    set_setting('notify_email', 'Majitel@Example.com');
    set_setting('password_hash', password_hash('stare-heslo', PASSWORD_DEFAULT));
    set_setting('reset_last_sent', '0');
    auth_reset_request('majitel@example.com');
    eq(1, count(mails()));
    $mail = file_get_contents(mails()[0]);
    contains('To: Majitel@Example.com', $mail);
    contains(rtrim(cfg('base_url'), '/') . '/?reset=', $mail, 'odkaz vede na adresu z base_url');
    $tok = reset_link();
    ok($tok !== '');
    throws(fn() => auth_reset_apply($tok, 'kratke'), 'aspoň 8 znaků');
    ok(auth_token_valid('reset', $tok), 'krátké heslo token nespotřebuje');
    auth_reset_apply($tok, 'nove-heslo-123');
    ok(password_verify('nove-heslo-123', (string)setting('password_hash')));
    ok(!password_verify('stare-heslo', (string)setting('password_hash')));
    throws(fn() => auth_reset_apply($tok, 'dalsi-heslo-123'), 'už neplatí');
});

test('obnova hesla: nejvýš jedna žádost za 2 minuty, nová žádost zneplatní starý odkaz', function () {
    clear_mails();
    set_setting('notify_email', 'majitel@example.com');
    set_setting('reset_last_sent', '0');
    auth_reset_request('majitel@example.com');
    $first = reset_link();
    auth_reset_request('majitel@example.com');
    eq(1, count(mails()), 'druhá žádost hned po první se ignoruje');
    set_setting('reset_last_sent', (string)(time() - 121));
    auth_reset_request('majitel@example.com');
    eq(2, count(mails()));
    ok(!auth_token_valid('reset', $first), 'starý odkaz neplatí');
    $valid = 0;
    foreach (mails() as $f) { preg_match('~\?reset=([0-9a-f]{64})~', file_get_contents($f), $mm); $valid += auth_token_valid('reset', $mm[1] ?? '') ? 1 : 0; }
    eq(1, $valid, 'platný je jen nejnovější odkaz');
    clear_mails();
});

// ---------- Google ----------
function fake_idtoken(array $claims): string { return 'h.' . auth_b64u_enc(json_encode($claims)) . '.s'; }
test('Google: kontrola ID tokenu (vydavatel, aplikace, platnost, nonce, ověřený e-mail)', function () {
    $GLOBALS['CFG']['google_client_id'] = 'klient-123';
    $good = ['iss' => 'https://accounts.google.com', 'aud' => 'klient-123', 'exp' => time() + 600, 'nonce' => 'abc', 'sub' => '1001', 'email' => 'ja@example.com', 'email_verified' => true];
    eq(['sub' => '1001', 'email' => 'ja@example.com'], auth_google_check_token(fake_idtoken($good), 'abc'));
    foreach ([['iss' => 'https://evil.example'], ['aud' => 'jina-aplikace'], ['exp' => time() - 5], ['nonce' => 'jiny'], ['email_verified' => false], ['sub' => ''], ['email' => '']] as $bad) {
        throws(fn() => auth_google_check_token(fake_idtoken($bad + $good), 'abc'), '', 'zamítnout ' . show($bad));
    }
    throws(fn() => auth_google_check_token(fake_idtoken($good), ''), 'nonce', 'prázdný nonce');
    throws(fn() => auth_google_check_token('nesmysl', 'abc'));
});

// ---------- CBOR ----------
test('CBOR: čísla, řetězce, pole a mapy (vzorky z RFC 8949)', function () {
    $d = function (string $hex) { $o = 0; return wa_cbor(hex2bin($hex), $o); };
    eq(0, $d('00')); eq(23, $d('17')); eq(500, $d('1901f4')); eq(-1, $d('20')); eq(-7, $d('26')); eq(-257, $d('390100'));
    eq('', $d('60')); eq('IETF', $d('6449455446')); eq("\x01\x02", $d('420102'));
    eq([1, 2, 3], $d('83010203'));
    eq([1 => 2, 3 => 4], $d('a201020304'));
    eq([-1 => 1, 'a' => [true, null]], $d('a22001' . '6161' . '82f5f6'));
    throws(fn() => $d('5a'), 'CBOR');
    throws(fn() => $d('4401'), 'CBOR');
});

// ---------- Passkey ----------
test('Passkey ES256: registrace a přihlášení, počítadlo roste', function () {
    q('DELETE FROM passkeys');
    $k = new FakeKey();
    $id = register_key($k, 'Telefon');
    $row = q('SELECT * FROM passkeys WHERE id = ?', [$id])->fetch();
    eq('Telefon', $row['name']);
    contains('BEGIN PUBLIC KEY', $row['public_key']);
    eq(-7, (int)$row['alg']);
    $r = login_assert($k);
    eq($id, (int)$r['id']);
    $r2 = login_assert($k);
    eq(2, (int)q('SELECT sign_count FROM passkeys WHERE id = ?', [$id])->fetchColumn());
    ok(q('SELECT last_used FROM passkeys WHERE id = ?', [$id])->fetchColumn() !== null, 'last_used');
});

test('Passkey RS256 (Windows Hello): registrace a přihlášení', function () {
    $k = new FakeKey(true);
    register_key($k, 'Počítač');
    login_assert($k);
    login_assert($k);
});

test('Passkey: zamítnutá přihlášení', function () {
    q('DELETE FROM passkeys');
    $k = new FakeKey();
    register_key($k);
    throws(fn() => login_assert($k, ['badsig' => true]), 'podpis');
    throws(fn() => login_assert($k, ['origin' => 'https://evil.example']), 'původ');
    throws(fn() => login_assert($k, ['rp' => 'evil.example']), 'jiné adrese');
    throws(fn() => login_assert($k, ['flags' => 0x01]), 'neověřilo uživatele');
    throws(fn() => login_assert($k, ['flags' => 0x04]), 'přítomnosti');
    throws(fn() => login_assert($k, ['challenge' => auth_b64u_enc(random_bytes(32))]), 'Výzva');
    throws(fn() => login_assert(new FakeKey()), 'není zaregistrované');
    // opakování stejné odpovědi (replay): výzva je jednorázová
    $ch = wa_login_options()['publicKey']['challenge'];
    $cred = $k->get($ch);
    wa_login_verify($cred);
    throws(fn() => wa_login_verify($cred), 'Výzva');
    // počítadlo se nesmí vrátit zpět (klonovaný klíč)
    throws(fn() => login_assert($k, ['counter' => 1]), 'Počítadlo');
    // výzva po vypršení
    $ch = wa_login_options()['publicKey']['challenge'];
    q('UPDATE auth_tokens SET expires = ? WHERE token_hash = ?', [time() - 1, hash('sha256', $ch)]);
    throws(fn() => wa_login_verify($k->get($ch)), 'Výzva');
    // výzva z registrace nejde použít k přihlášení
    $reg = wa_register_options()['publicKey']['challenge'];
    throws(fn() => wa_login_verify($k->get($reg)), 'Výzva');
});

test('Passkey: zamítnutá registrace', function () {
    $k = new FakeKey();
    throws(fn() => register_key($k, 'x', ['origin' => 'https://evil.example']), 'původ');
    throws(fn() => register_key($k, 'x', ['rp' => 'evil.example']), 'jiné adrese');
    throws(fn() => register_key($k, 'x', ['flags' => 0x41]), 'neověřilo uživatele');
    throws(fn() => register_key($k, 'x', ['flags' => 0x05]), 'údaje o klíči');
    $before = (int)q('SELECT COUNT(*) FROM passkeys')->fetchColumn();
    register_key($k, 'jednou');
    throws(fn() => register_key($k, 'podruhé'), 'už je zaregistrované');
    eq($before + 1, (int)q('SELECT COUNT(*) FROM passkeys')->fetchColumn());
    $opts = wa_register_options()['publicKey'];
    eq(wa_rp_id(), $opts['rp']['id']);
    eq('required', $opts['authenticatorSelection']['userVerification']);
    ok(count($opts['excludeCredentials']) === $before + 1, 'už zaregistrovaná zařízení se vylučují');
});

// ---------- HTTP: index.php, login-google.php, passkey.php ----------
$jar = TMP_DIR . '/auth-cookies.txt';
function ahttp(string $method, string $url, array $o = []): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_PROXY => '', CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $o['headers'] ?? []]);
    if (isset($o['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($o['form']));
    if (isset($o['json'])) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($o['json'])); curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($o['headers'] ?? [], ['Content-Type: application/json'])); }
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $head = substr($raw, 0, $hs); $body = substr($raw, $hs);
    preg_match('/^Location:\s*(.+)$/mi', $head, $m);
    unset($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true), 'loc' => preg_match('~^(\./|#)~', trim($m[1] ?? '')) ? rtrim(getenv('BASE_URL'), '/') . '/' . ltrim(trim($m[1]), './') : trim($m[1] ?? '')];
}
function fresh_session(): void { global $jar; @unlink($jar); }
function logged_in_http(string $B): bool { return ahttp('GET', "$B/api.php?a=status")['code'] === 200; }

test('HTTP: zapomenuté heslo – formulář, jednotná odpověď, odkaz, nové heslo, přihlášení', function () use ($B) {
    fresh_session(); clear_mails();
    set_setting('login_fails', '0');
    set_setting('notify_email', 'majitel@example.com');
    set_setting('password_hash', password_hash('puvodni-heslo', PASSWORD_DEFAULT));
    set_setting('reset_last_sent', '0');
    contains('Zapomenuté heslo', ahttp('GET', "$B/")['body']);
    contains('name="email"', ahttp('GET', "$B/?forgot")['body']);
    $same = 'Pokud adresa souhlasí';
    contains($same, ahttp('POST', "$B/", ['form' => ['do' => 'forgot', 'email' => 'cizi@example.com']])['body']);
    eq(0, count(mails()), 'cizí adresa nic neodešle, odpověď je stejná');
    set_setting('reset_last_sent', '0');
    contains($same, ahttp('POST', "$B/", ['form' => ['do' => 'forgot', 'email' => 'majitel@example.com']])['body']);
    eq(1, count(mails()));
    $tok = reset_link();
    contains('name="new"', ahttp('GET', "$B/?reset=$tok")['body']);
    contains('už neplatí', ahttp('GET', "$B/?reset=" . str_repeat('a', 64))['body']);
    contains('neshodují', ahttp('POST', "$B/", ['form' => ['do' => 'reset', 'reset' => $tok, 'new' => 'nove-heslo-456', 'new2' => 'jine-heslo-456']])['body']);
    contains('aspoň 8', ahttp('POST', "$B/", ['form' => ['do' => 'reset', 'reset' => $tok, 'new' => 'abc', 'new2' => 'abc']])['body']);
    $r = ahttp('POST', "$B/", ['form' => ['do' => 'reset', 'reset' => $tok, 'new' => 'nove-heslo-456', 'new2' => 'nove-heslo-456']]);
    contains('Heslo je změněné', $r['body']);
    contains('už neplatí', ahttp('GET', "$B/?reset=$tok")['body'], 'odkaz je jednorázový');
    contains('Nesprávné heslo', ahttp('POST', "$B/", ['form' => ['password' => 'puvodni-heslo']])['body']);
    eq(302, ahttp('POST', "$B/", ['form' => ['password' => 'nove-heslo-456']])['code']);
    ok(logged_in_http($B), 'přihlášen novým heslem');
    ok((bool)q("SELECT 1 FROM logs WHERE message = 'Přihlášení: heslo.'")->fetchColumn(), 'Protokol: způsob přihlášení');
    set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
    set_setting('login_fails', '0');
});

test('HTTP: po 5 chybách zámek i na obnovu hesla a na správné heslo', function () use ($B) {
    fresh_session();
    set_setting('login_fails', '0');
    for ($i = 0; $i < 5; $i++) ahttp('POST', "$B/", ['form' => ['password' => 'spatne']]);
    contains('Příliš mnoho pokusů', ahttp('POST', "$B/", ['form' => ['password' => 'test-heslo-123']])['body']);
    $tok = auth_token_create('reset', 600);
    contains('Příliš mnoho pokusů', ahttp('POST', "$B/", ['form' => ['do' => 'reset', 'reset' => $tok, 'new' => 'nove-heslo-456', 'new2' => 'nove-heslo-456']])['body']);
    ok(auth_token_valid('reset', $tok), 'zablokovaný pokus token nespotřebuje');
    set_setting('login_fails', '0');
});

test('HTTP: přihlášení přes Google (zkušební režim): povolený i cizí účet', function () use ($B) {
    fresh_session();
    set_setting('login_fails', '0');
    set_setting('login_google_sub', 'sub-test');
    set_setting('login_google_email', 'test@example.com');
    contains('Přihlásit přes Google', ahttp('GET', "$B/")['body']);
    // povolený účet
    set_setting('mock_google_account', 'sub-test:test@example.com');
    $r = ahttp('GET', "$B/login-google.php");
    eq(302, $r['code']);
    contains('code=mock', $r['loc']);
    $r = ahttp('GET', $r['loc']);
    eq(302, $r['code']);
    ok(logged_in_http($B), 'přihlášen přes Google');
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE 'Přihlášení: Google%'")->fetchColumn(), 'Protokol');
    // cizí účet
    fresh_session();
    set_setting('mock_google_account', 'jine-sub:cizi@example.com');
    $r = ahttp('GET', ahttp('GET', "$B/login-google.php")['loc']);
    eq(302, $r['code']);
    ok(!logged_in_http($B), 'cizí účet se nepřihlásí');
    contains('nesmí přihlásit', ahttp('GET', "$B/")['body']);
    ok(!str_contains(ahttp('GET', "$B/")['body'], 'nesmí přihlásit'), 'chyba se ukáže jen jednou');
    // podvržený state
    fresh_session();
    ahttp('GET', "$B/login-google.php");
    contains('nezdařilo', ahttp('GET', ahttp('GET', "$B/login-google.php?code=mock:sub-test:test@example.com&state=podvrh")['loc'])['body']);
    ok(!logged_in_http($B));
    // bez propojeného účtu tlačítko ani přihlášení není
    fresh_session();
    set_setting('login_google_sub', null);
    ok(!str_contains(ahttp('GET', "$B/")['body'], 'Přihlásit přes Google'), 'tlačítko je schované');
    contains('není nastavené', ahttp('GET', ahttp('GET', "$B/login-google.php")['loc'] ?: "$B/")['body']);
    set_setting('login_fails', '0');
});

test('HTTP: propojení Google účtu jen z přihlášené relace', function () use ($B) {
    fresh_session();
    eq("$B/", ahttp('GET', "$B/login-google.php?link=1")['loc'], 'nepřihlášený je poslán na přihlášení');
    ahttp('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    set_setting('login_google_sub', null);
    set_setting('mock_google_account', 'sub-novy:novy@example.com');
    $r = ahttp('GET', ahttp('GET', "$B/login-google.php?link=1")['loc']);
    contains('zabezpeceni?google=linked', $r['loc']);
    setting('__reload');
    eq('sub-novy', setting('login_google_sub'));
    eq('novy@example.com', setting('login_google_email'));
    $s = ahttp('GET', "$B/api.php?a=security")['json'];
    ok($s['google_linked'] && $s['google_email'] === 'novy@example.com', show($s));
    set_setting('login_google_sub', null); set_setting('login_google_email', null);
});

test('HTTP: Passkey – výzva, přihlášení, chybná odpověď, zámek', function () use ($B) {
    fresh_session();
    set_setting('login_fails', '0');
    q('DELETE FROM passkeys');
    $k = new FakeKey();
    register_key($k, 'Zkušební');
    contains('id="pkLogin"', ahttp('GET', "$B/")['body']);
    eq(405, ahttp('GET', "$B/passkey.php?a=options")['code']);
    $o = ahttp('POST', "$B/passkey.php?a=options", ['json' => []]);
    eq(200, $o['code']);
    $ch = $o['json']['publicKey']['challenge'];
    eq(wa_rp_id(), $o['json']['publicKey']['rpId']);
    eq('required', $o['json']['publicKey']['userVerification']);
    // špatný podpis: 401 a započítá se pokus
    $bad = ahttp('POST', "$B/passkey.php?a=verify", ['json' => $k->get($ch, ['badsig' => true])]);
    eq(401, $bad['code']);
    setting('__reload');
    eq(1, (int)setting('login_fails'));
    ok(!logged_in_http($B));
    // správná odpověď (nová výzva, protože ta předchozí je spotřebovaná)
    $ch = ahttp('POST', "$B/passkey.php?a=options", ['json' => []])['json']['publicKey']['challenge'];
    $ok = ahttp('POST', "$B/passkey.php?a=verify", ['json' => $k->get($ch)]);
    eq(200, $ok['code'], $ok['body']);
    ok(logged_in_http($B), 'přihlášen Passkey');
    setting('__reload');
    eq(0, (int)setting('login_fails'));
    ok((bool)q("SELECT 1 FROM logs WHERE message = 'Přihlášení: Passkey (Zkušební).'")->fetchColumn(), 'Protokol');
    // zámek po 5 chybách
    fresh_session();
    for ($i = 0; $i < 5; $i++) {
        $ch = ahttp('POST', "$B/passkey.php?a=options", ['json' => []])['json']['publicKey']['challenge'] ?? '';
        ahttp('POST', "$B/passkey.php?a=verify", ['json' => $k->get($ch ?: 'x', ['badsig' => true])]);
    }
    eq(429, ahttp('POST', "$B/passkey.php?a=options", ['json' => []])['code']);
    set_setting('login_fails', '0');
});

test('HTTP API: správa Passkey a Google (přihlášený, jen POST)', function () use ($B) {
    fresh_session();
    q('DELETE FROM passkeys');
    ahttp('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    preg_match('/name="csrf" content="([^"]+)"/', ahttp('GET', "$B/")['body'], $m);
    $h = ['X-CSRF: ' . $m[1]];
    $sec = ahttp('GET', "$B/api.php?a=security")['json'];
    eq([], $sec['passkeys']);
    ok($sec['passkey_available'], 'na localhostu je Passkey povolený');
    eq(405, ahttp('GET', "$B/api.php?a=passkey_delete")['code'], 'mazání jen POSTem');
    eq(403, ahttp('POST', "$B/api.php?a=passkey_options", ['json' => []])['code'], 'bez CSRF');
    $opt = ahttp('POST', "$B/api.php?a=passkey_options", ['json' => [], 'headers' => $h]);
    eq(200, $opt['code'], $opt['body']);
    $k = new FakeKey();
    $reg = ahttp('POST', "$B/api.php?a=passkey_register", ['json' => ['credential' => $k->create($opt['json']['publicKey']['challenge']), 'name' => 'Můj telefon'], 'headers' => $h]);
    eq(200, $reg['code'], $reg['body']);
    $id = $reg['json']['id'];
    $sec = ahttp('GET', "$B/api.php?a=security")['json'];
    eq('Můj telefon', $sec['passkeys'][0]['name']);
    ahttp('POST', "$B/api.php?a=passkey_rename", ['json' => ['id' => $id, 'name' => 'Služební telefon'], 'headers' => $h]);
    eq('Služební telefon', ahttp('GET', "$B/api.php?a=security")['json']['passkeys'][0]['name']);
    eq(400, ahttp('POST', "$B/api.php?a=passkey_rename", ['json' => ['id' => $id, 'name' => ' '], 'headers' => $h])['code']);
    eq(404, ahttp('POST', "$B/api.php?a=passkey_delete", ['json' => ['id' => 99999], 'headers' => $h])['code']);
    eq(200, ahttp('POST', "$B/api.php?a=passkey_delete", ['json' => ['id' => $id], 'headers' => $h])['code']);
    eq([], ahttp('GET', "$B/api.php?a=security")['json']['passkeys']);
    ok((bool)q("SELECT 1 FROM logs WHERE message LIKE 'Odebrán Passkey%'")->fetchColumn());
});

// ---------- odhlášení po nečinnosti ----------
function idle_http(string $B, string $action = 'status', bool $passive = false): array {
    return ahttp('GET', "$B/api.php?a=$action", ['headers' => $passive ? ['X-Passive: 1'] : []]);
}
function idle_login(string $B, int $seconds): void {
    fresh_session();
    set_setting('login_fails', '0');
    set_setting('password_hash', password_hash('test-heslo-123', PASSWORD_DEFAULT));
    set_setting('idle_seconds_test', (string)$seconds);
    ahttp('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
}

test('nečinnost: po uplynutí limitu odhlášení, hláška na přihlašovací stránce a záznam v Protokolu', function () use ($B) {
    idle_login($B, 3);
    eq(200, idle_http($B)['code']);
    sleep(5);
    eq(401, idle_http($B)['code'], 'po limitu 401');
    $page = ahttp('GET', "$B/")['body'];
    contains('Byli jste odhlášeni z důvodu nečinnosti.', $page);
    contains('name="password"', $page);
    ok(!str_contains(ahttp('GET', "$B/")['body'], 'z důvodu nečinnosti'), 'hláška se ukáže jen jednou');
    ok((bool)q("SELECT 1 FROM logs WHERE message = 'Odhlášení z důvodu nečinnosti.'")->fetchColumn(), 'Protokol');
});

test('nečinnost: běžný požadavek čas obnovuje, pasivní ne', function () use ($B) {
    idle_login($B, 4);
    sleep(2); eq(200, idle_http($B)['code'], 'běžný požadavek ve 2 s');
    sleep(2); eq(200, idle_http($B, 'session', true)['code'], 'pasivní požadavek ve 4 s (od posledního běžného 2 s)');
    sleep(3);
    eq(401, idle_http($B, 'status', true)['code'], 'pasivní požadavek čas neprodloužil');
    idle_login($B, 4);
    sleep(3); eq(200, idle_http($B)['code']);
    sleep(3); eq(200, idle_http($B)['code'], 'běžný požadavek ve 3 s relaci prodloužil');
});

test('nečinnost: akce session vrací zbývající čas, 0 minut znamená nikdy', function () use ($B) {
    idle_login($B, 100);
    $s = idle_http($B, 'session', true)['json'];
    eq(100, $s['limit']);
    ok($s['remaining'] > 95 && $s['remaining'] <= 100, show($s));
    set_setting('idle_seconds_test', '0');
    set_setting('idle_minutes', '0');
    $s = idle_http($B, 'session')['json'];
    eq([0, null], [$s['limit'], $s['remaining']]);
    set_setting('idle_minutes', '60');
});

test('nečinnost: nastavení v Zabezpečení (kontrola rozsahu, jen POST)', function () use ($B) {
    fresh_session();
    set_setting('idle_seconds_test', '0');
    set_setting('login_fails', '0');
    ahttp('POST', "$B/", ['form' => ['password' => 'test-heslo-123']]);
    preg_match('/name="csrf" content="([^"]+)"/', ahttp('GET', "$B/")['body'], $m);
    $h = ['X-CSRF: ' . $m[1]];
    $save = fn($v) => ahttp('POST', "$B/api.php?a=idle_save", ['json' => ['minutes' => $v], 'headers' => $h]);
    eq(405, ahttp('GET', "$B/api.php?a=idle_save")['code']);
    foreach ([4, 1441, -1, 'abc', null, 5.5] as $bad) eq(400, $save($bad)['code'], 'zamítnout ' . show($bad));
    foreach ([5, 1440, 0, 90] as $good) eq(200, $save($good)['code'], 'povolit ' . show($good));
    eq(90, idle_http($B, 'security')['json']['idle_minutes']);
    eq(5400, $save(90)['json']['limit']);
    $save(60);
    contains('<meta name="idle" content="3600">', ahttp('GET', "$B/")['body']);
    $save(0);
    contains('<meta name="idle" content="0">', ahttp('GET', "$B/")['body']);
    $save(60);
});
