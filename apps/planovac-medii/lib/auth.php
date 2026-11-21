<?php
// Přihlášení: omezení pokusů, jednorázové tokeny, obnova hesla, přihlášení přes Google a Passkey (WebAuthn).
// Bez externích knihoven (WebAuthn: vlastní CBOR a ověření podpisu přes OpenSSL).

require_once __DIR__ . '/drive.php';

const AUTH_MAX_FAILS = 5;
const AUTH_LOCK_SECONDS = 300;
const AUTH_RESET_TTL = 1800;      // platnost odkazu pro obnovu hesla (30 min)
const AUTH_RESET_GAP = 120;       // nejméně tolik sekund mezi dvěma žádostmi o obnovu
const AUTH_CHALLENGE_TTL = 300;   // platnost výzvy pro Passkey (5 min)

// ---------- Omezení pokusů (společné pro heslo, obnovu, Google i Passkey) ----------

/** Kolik sekund ještě trvá zámek po špatných pokusech (0 = přihlašovat lze). */
function auth_lock_remaining(): int {
    $fails = (int)setting('login_fails', 0);
    $last = (int)setting('login_last_fail', 0);
    if ($fails >= AUTH_MAX_FAILS && time() - $last < AUTH_LOCK_SECONDS) return AUTH_LOCK_SECONDS - (time() - $last);
    return 0;
}

function auth_lock_message(): string {
    return 'Příliš mnoho pokusů. Zkuste to za ' . max(1, (int)ceil(auth_lock_remaining() / 60)) . ' min.';
}

function auth_register_fail(string $how): void {
    $fails = (int)setting('login_fails', 0);
    if ($fails >= AUTH_MAX_FAILS && time() - (int)setting('login_last_fail', 0) >= AUTH_LOCK_SECONDS) $fails = 0;   // zámek vypršel
    $fails++;
    set_setting('login_fails', (string)$fails);
    set_setting('login_last_fail', (string)time());
    if ($fails === AUTH_MAX_FAILS) app_log('warning', "Přihlášení dočasně zablokováno na 5 minut po $fails neúspěšných pokusech ($how).");
}

/** Úspěšné přihlášení: nové ID relace, vynulování pokusů, záznam do Protokolu. */
function auth_login(string $how): void {
    start_session();
    session_regenerate_id(true);
    $_SESSION['auth'] = true;
    $_SESSION['auth_last'] = time();
    set_setting('login_fails', '0');
    app_log('info', "Přihlášení: $how.");
}

// ---------- Jednorázové tokeny (v databázi je jen jejich SHA-256) ----------

function auth_token_create(string $purpose, int $ttl, ?string $raw = null): string {
    q('DELETE FROM auth_tokens WHERE expires < ?', [time() - 3600]);
    $raw ??= bin2hex(random_bytes(32));
    q('INSERT INTO auth_tokens (purpose, token_hash, expires, used) VALUES (?, ?, ?, 0)', [$purpose, hash('sha256', $raw), time() + $ttl]);
    return $raw;
}

function auth_token_valid(string $purpose, string $raw): bool {
    if ($raw === '' || strlen($raw) > 512) return false;
    return (bool)q('SELECT 1 FROM auth_tokens WHERE purpose = ? AND token_hash = ? AND used = 0 AND expires >= ?', [$purpose, hash('sha256', $raw), time()])->fetchColumn();
}

/** Spotřebuje token (jen jednou). */
function auth_token_use(string $purpose, string $raw): bool {
    if ($raw === '' || strlen($raw) > 512) return false;
    return q('UPDATE auth_tokens SET used = 1 WHERE purpose = ? AND token_hash = ? AND used = 0 AND expires >= ?', [$purpose, hash('sha256', $raw), time()])->rowCount() === 1;
}

// ---------- E-mail a obnova hesla ----------

/** Pošle prostý e-mail. V testech (mail_to_file) ho uloží do data/tmp. */
function auth_mail(string $to, string $subject, string $text): bool {
    if (cfg('mail_to_file')) {
        return file_put_contents(DATA_DIR . '/tmp/mail-' . date('His') . '-' . bin2hex(random_bytes(3)) . '.txt', "To: $to\nSubject: $subject\n\n$text") !== false;
    }
    $headers = [
        'From: ' . mb_encode_mimeheader(APP_NAME, 'UTF-8') . ' <' . cfg('mail_from') . '>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return (bool)@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, implode("\r\n", $headers));
}

/** Žádost o obnovu hesla. Odpověď uživateli je vždy stejná, aby nešlo zjistit, jaká adresa je nastavená. */
function auth_reset_request(string $email): void {
    $to = trim((string)setting('notify_email', ''));
    if (time() - (int)setting('reset_last_sent', 0) < AUTH_RESET_GAP) return;
    set_setting('reset_last_sent', (string)time());
    if ($to === '') { app_log('warning', 'Žádost o obnovu hesla: v Nastavení → Upozornění není vyplněný e-mail, odkaz nebyl odeslán.'); return; }
    if (strcasecmp($to, trim($email)) !== 0) { app_log('warning', 'Žádost o obnovu hesla: zadaná adresa nesouhlasí s adresou pro upozornění, odkaz nebyl odeslán.'); return; }
    q("UPDATE auth_tokens SET used = 1 WHERE purpose = 'reset'");
    $url = rtrim(cfg('base_url'), '/') . '/?reset=' . auth_token_create('reset', AUTH_RESET_TTL);
    $ok = auth_mail($to, 'Obnovení hesla – ' . APP_NAME,
        "Dobrý den,\n\nbyla podána žádost o obnovení hesla do aplikace " . APP_NAME . ".\nNové heslo nastavíte na tomto odkazu (platí 30 minut a jde použít jen jednou):\n\n$url\n\n"
        . "Pokud jste o obnovení nežádali, tento e-mail ignorujte. Heslo se nezměnilo.\n");
    app_log($ok ? 'info' : 'error', $ok ? 'Žádost o obnovu hesla: odkaz odeslán e-mailem.' : 'Žádost o obnovu hesla: e-mail se nepodařilo odeslat.');
}

/** Nastaví nové heslo podle odkazu z e-mailu. Vyhodí RuntimeException s textem pro uživatele. */
function auth_reset_apply(string $token, string $new): void {
    if (mb_strlen($new) < 8) throw new RuntimeException('Nové heslo musí mít aspoň 8 znaků.');
    if (!auth_token_use('reset', $token)) throw new RuntimeException('Odkaz už neplatí. Požádejte o nový.');
    set_setting('password_hash', password_hash($new, PASSWORD_DEFAULT));
    set_setting('login_fails', '0');
    q("UPDATE auth_tokens SET used = 1 WHERE purpose = 'reset'");
    app_log('info', 'Heslo bylo změněno odkazem pro obnovu hesla.');
}

// ---------- Přihlášení přes Google (OpenID Connect) ----------

function auth_google_redirect_uri(): string { return rtrim(cfg('base_url'), '/') . '/login-google.php'; }

function auth_google_configured(): bool { return (bool)(cfg('google_client_id') && cfg('google_client_secret')); }

function auth_google_url(string $state, string $nonce): string {
    if (drive_mock()) {   // zkušební režim: bez Googlu, účet z nastavení mock_google_account (sub:e-mail)
        [$sub, $mail] = array_pad(explode(':', (string)setting('mock_google_account', 'sub-test:test@example.com'), 2), 2, '');
        return auth_google_redirect_uri() . '?' . http_build_query(['code' => "mock:$sub:$mail", 'state' => $state]);
    }
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => cfg('google_client_id'), 'redirect_uri' => auth_google_redirect_uri(), 'response_type' => 'code',
        'scope' => 'openid email', 'state' => $state, 'nonce' => $nonce, 'prompt' => 'select_account',
    ]);
}

function auth_b64u_dec(string $s): string {
    $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    if ($r === false) throw new RuntimeException('Neplatná data.');
    return $r;
}
function auth_b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** Vymění kód za ID token a ověří ho. Vrátí ['sub' => …, 'email' => …]. */
function auth_google_claims(string $code, string $nonce): array {
    if (drive_mock() && str_starts_with($code, 'mock:')) {
        [, $sub, $mail] = array_pad(explode(':', $code, 3), 3, '');
        return ['sub' => $sub, 'email' => $mail];
    }
    $r = http_req('POST', 'https://oauth2.googleapis.com/token', ['form' => [
        'code' => $code, 'client_id' => cfg('google_client_id'), 'client_secret' => cfg('google_client_secret'),
        'redirect_uri' => auth_google_redirect_uri(), 'grant_type' => 'authorization_code',
    ]]);
    $idt = $r['json']['id_token'] ?? '';
    if ($r['code'] !== 200 || !is_string($idt) || substr_count($idt, '.') !== 2) throw new RuntimeException('Google nevrátil přihlašovací údaje' . (isset($r['json']['error']) ? ' (' . $r['json']['error'] . ')' : '') . '.');
    // Token přichází přímo od Googlu přes HTTPS spolu s tajným klíčem aplikace, podpis se proto neověřuje (OpenID Connect Core 3.1.3.7).
    return auth_google_check_token($idt, $nonce);
}

/** Zkontroluje obsah ID tokenu (vydavatel, aplikace, platnost, nonce, ověřený e-mail). */
function auth_google_check_token(string $idt, string $nonce): array {
    if (substr_count($idt, '.') !== 2) throw new RuntimeException('Neplatný ID token.');
    $c = json_decode(auth_b64u_dec(explode('.', $idt)[1]), true);
    if (!is_array($c)) throw new RuntimeException('Neplatný ID token.');
    if (!in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)) throw new RuntimeException('ID token má jiného vydavatele.');
    if (($c['aud'] ?? '') !== cfg('google_client_id')) throw new RuntimeException('ID token je pro jinou aplikaci.');
    if ((int)($c['exp'] ?? 0) < time()) throw new RuntimeException('ID token vypršel.');
    if ($nonce === '' || !hash_equals($nonce, (string)($c['nonce'] ?? ''))) throw new RuntimeException('Neplatný požadavek (nonce).');
    if (empty($c['sub']) || empty($c['email']) || !filter_var($c['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) throw new RuntimeException('Google účet nemá ověřený e-mail.');
    return ['sub' => (string)$c['sub'], 'email' => (string)$c['email']];
}

// ---------- Passkey (WebAuthn) ----------

function wa_rp_id(): string { return (string)parse_url(cfg('base_url'), PHP_URL_HOST); }
function wa_origin(): string {
    $u = parse_url(cfg('base_url'));
    return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
}
/** Passkey funguje jen na HTTPS (nebo na localhostu). */
function wa_secure_context(): bool {
    $u = parse_url(cfg('base_url'));
    return ($u['scheme'] ?? '') === 'https' || in_array($u['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
}
function wa_available(): bool { return function_exists('openssl_verify') && wa_secure_context(); }

/** CBOR: čísla, řetězce bajtů a textu, pole, mapy, true/false/null (stačí pro WebAuthn). */
function wa_cbor(string $s, int &$o) {
    if ($o >= strlen($s)) throw new RuntimeException('Neplatná data (CBOR).');
    $b = ord($s[$o++]);
    $type = $b >> 5;
    $info = $b & 31;
    if ($type === 7) {
        if ($info === 20) return false;
        if ($info === 21) return true;
        if ($info === 22 || $info === 23) return null;
        throw new RuntimeException('Nepodporovaná hodnota CBOR.');
    }
    if ($info < 24) $n = $info;
    elseif ($info >= 24 && $info <= 27) {
        $len = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info];
        if ($o + $len > strlen($s)) throw new RuntimeException('Neplatná data (CBOR).');
        $n = 0;
        for ($i = 0; $i < $len; $i++) $n = ($n << 8) | ord($s[$o + $i]);
        $o += $len;
        if ($n < 0) throw new RuntimeException('Příliš velké číslo (CBOR).');
    } else throw new RuntimeException('Nepodporovaná délka CBOR.');
    switch ($type) {
        case 0: return $n;
        case 1: return -1 - $n;
        case 2: case 3:
            if ($o + $n > strlen($s)) throw new RuntimeException('Neplatná data (CBOR).');
            $v = substr($s, $o, $n);
            $o += $n;
            return $v;
        case 4:
            if ($n > 1000) throw new RuntimeException('Příliš dlouhé pole (CBOR).');
            $a = [];
            for ($i = 0; $i < $n; $i++) $a[] = wa_cbor($s, $o);
            return $a;
        case 5:
            if ($n > 1000) throw new RuntimeException('Příliš dlouhá mapa (CBOR).');
            $m = [];
            for ($i = 0; $i < $n; $i++) { $k = wa_cbor($s, $o); $m[is_scalar($k) ? $k : serialize($k)] = wa_cbor($s, $o); }
            return $m;
    }
    throw new RuntimeException('Nepodporovaný typ CBOR.');
}

function wa_der(int $tag, string $c): string {
    $n = strlen($c);
    $len = $n < 128 ? chr($n) : (chr(0x80 | strlen(ltrim(pack('N', $n), "\0"))) . ltrim(pack('N', $n), "\0"));
    return chr($tag) . $len . $c;
}
function wa_der_int(string $bin): string {
    $bin = ltrim($bin, "\0");
    if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\0" . $bin;
    return wa_der(0x02, $bin);
}
function wa_pem(string $spki): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"; }

/** Klíč COSE → [PEM, alg]. Podporuje ES256 (P-256) a RS256. */
function wa_cose_to_pem(array $k): array {
    $alg = $k[3] ?? null;
    if (($k[1] ?? null) === 2 && $alg === -7 && ($k[-1] ?? null) === 1 && strlen((string)($k[-2] ?? '')) === 32 && strlen((string)($k[-3] ?? '')) === 32) {
        return [wa_pem(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3]), -7];
    }
    if (($k[1] ?? null) === 3 && $alg === -257 && !empty($k[-1]) && !empty($k[-2])) {
        $rsa = wa_der(0x30, wa_der_int($k[-1]) . wa_der_int($k[-2]));
        $spki = wa_der(0x30, wa_der(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00") . wa_der(0x03, "\0" . $rsa));
        return [wa_pem($spki), -257];
    }
    throw new RuntimeException('Toto zařízení používá nepodporovaný typ klíče.');
}

/** authenticatorData: rpIdHash, příznaky, počítadlo a (při registraci) údaje o klíči. */
function wa_auth_data(string $ad, bool $attested): array {
    if (strlen($ad) < 37) throw new RuntimeException('Neplatná data zařízení.');
    $r = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'counter' => unpack('N', substr($ad, 33, 4))[1]];
    if ($attested) {
        if (!($r['flags'] & 0x40) || strlen($ad) < 55) throw new RuntimeException('Zařízení neposlalo údaje o klíči.');
        $idLen = unpack('n', substr($ad, 53, 2))[1];
        if (strlen($ad) < 55 + $idLen) throw new RuntimeException('Neplatná data zařízení.');
        $r['credId'] = substr($ad, 55, $idLen);
        $o = 55 + $idLen;
        $key = wa_cbor($ad, $o);
        if (!is_array($key)) throw new RuntimeException('Neplatný klíč zařízení.');
        $r['key'] = $key;
    }
    return $r;
}

function wa_challenge(string $purpose): string {
    if ((int)q('SELECT COUNT(*) FROM auth_tokens WHERE purpose = ? AND used = 0 AND expires >= ?', [$purpose, time()])->fetchColumn() > 100) {
        throw new RuntimeException('Příliš mnoho požadavků, zkuste to za chvíli.');
    }
    return auth_token_create($purpose, AUTH_CHALLENGE_TTL, auth_b64u_enc(random_bytes(32)));
}

/** Ověří clientDataJSON: typ, výzvu (jednorázově), původ. Vrátí surové bajty clientDataJSON. */
function wa_client_data(string $b64, string $type, string $purpose): string {
    $raw = auth_b64u_dec($b64);
    $cd = json_decode($raw, true);
    if (!is_array($cd) || ($cd['type'] ?? '') !== $type) throw new RuntimeException('Neplatná odpověď zařízení.');
    if (!empty($cd['crossOrigin'])) throw new RuntimeException('Neplatný původ.');
    if (!hash_equals(wa_origin(), (string)($cd['origin'] ?? ''))) throw new RuntimeException('Neplatný původ.');
    if (!auth_token_use($purpose, (string)($cd['challenge'] ?? ''))) throw new RuntimeException('Výzva vypršela nebo už byla použita.');
    return $raw;
}

function wa_check_flags(array $p): void {
    if (!hash_equals(hash('sha256', wa_rp_id(), true), $p['rpIdHash'])) throw new RuntimeException('Zařízení patří k jiné adrese.');
    if (!($p['flags'] & 0x01)) throw new RuntimeException('Chybí potvrzení přítomnosti uživatele.');
    if (!($p['flags'] & 0x04)) throw new RuntimeException('Zařízení neověřilo uživatele (otisk, obličej nebo PIN).');
}

const WA_USER_ID = 'twb-media-planner';

function wa_register_options(): array {
    $exclude = [];
    foreach (q('SELECT cred_id FROM passkeys')->fetchAll() as $r) $exclude[] = ['type' => 'public-key', 'id' => $r['cred_id']];
    return ['publicKey' => [
        'challenge' => wa_challenge('wa-reg'),
        'rp' => ['id' => wa_rp_id(), 'name' => APP_NAME],
        'user' => ['id' => auth_b64u_enc(WA_USER_ID), 'name' => 'twb-plánovač', 'displayName' => APP_NAME],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'excludeCredentials' => $exclude, 'attestation' => 'none', 'timeout' => 120000,
    ]];
}

/** Zaregistruje zařízení. Vrátí ID nového záznamu. */
function wa_register_verify(array $cred, string $name): int {
    $resp = $cred['response'] ?? [];
    wa_client_data((string)($resp['clientDataJSON'] ?? ''), 'webauthn.create', 'wa-reg');
    $o = 0;
    $att = wa_cbor(auth_b64u_dec((string)($resp['attestationObject'] ?? '')), $o);
    if (!is_array($att) || !is_string($att['authData'] ?? null)) throw new RuntimeException('Neplatná odpověď zařízení.');
    $p = wa_auth_data($att['authData'], true);
    wa_check_flags($p);
    [$pem, $alg] = wa_cose_to_pem($p['key']);
    $credB64 = auth_b64u_enc($p['credId']);
    if (isset($cred['rawId']) && auth_b64u_enc(auth_b64u_dec((string)$cred['rawId'])) !== $credB64) throw new RuntimeException('Neplatná odpověď zařízení.');
    $hash = hash('sha256', $p['credId']);
    if (q('SELECT 1 FROM passkeys WHERE cred_hash = ?', [$hash])->fetchColumn()) throw new RuntimeException('Toto zařízení už je zaregistrované.');
    $name = trim(mb_substr($name, 0, 100)) ?: 'Zařízení';
    q('INSERT INTO passkeys (name, cred_hash, cred_id, public_key, alg, sign_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$name, $hash, $credB64, $pem, $alg, $p['counter'], date('Y-m-d H:i:s')]);
    return (int)db()->lastInsertId();
}

function wa_login_options(): array {
    return ['publicKey' => [
        'challenge' => wa_challenge('wa-login'), 'rpId' => wa_rp_id(),
        'allowCredentials' => [], 'userVerification' => 'required', 'timeout' => 120000,
    ]];
}

/** Ověří přihlášení Passkey. Vrátí řádek zařízení; při chybě vyhodí RuntimeException. */
function wa_login_verify(array $cred): array {
    $resp = $cred['response'] ?? [];
    $row = q('SELECT * FROM passkeys WHERE cred_hash = ?', [hash('sha256', auth_b64u_dec((string)($cred['rawId'] ?? '')))])->fetch();
    if (!$row) throw new RuntimeException('Toto zařízení není zaregistrované.');
    $cdRaw = wa_client_data((string)($resp['clientDataJSON'] ?? ''), 'webauthn.get', 'wa-login');
    $ad = auth_b64u_dec((string)($resp['authenticatorData'] ?? ''));
    $p = wa_auth_data($ad, false);
    wa_check_flags($p);
    $sig = auth_b64u_dec((string)($resp['signature'] ?? ''));
    if (openssl_verify($ad . hash('sha256', $cdRaw, true), $sig, $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Neplatný podpis.');
    $stored = (int)$row['sign_count'];
    if (($p['counter'] > 0 || $stored > 0) && $p['counter'] <= $stored) throw new RuntimeException('Počítadlo zařízení se nezvýšilo (možná kopie klíče).');
    q('UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?', [$p['counter'], date('Y-m-d H:i:s'), $row['id']]);
    return $row;
}
