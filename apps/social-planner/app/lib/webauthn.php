<?php
declare(strict_types=1);
// Passkey (WebAuthn) bez knihoven. Podporuje ES256 a RS256, attestation none.

function b64u_enc(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true); }

function wa_rp_id(): string { return (string)parse_url((string)cfg('base_url'), PHP_URL_HOST); }
function wa_origin(): string {
    $u = parse_url((string)cfg('base_url'));
    return ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
}

function wa_cbor(string $s, int &$o) {
    if ($o >= strlen($s)) throw new RuntimeException('CBOR');
    $b = ord($s[$o++]); $type = $b >> 5; $info = $b & 31;
    if ($type === 7) {
        if ($info === 20) return false;
        if ($info === 21) return true;
        if ($info === 22 || $info === 23) return null;
        throw new RuntimeException('CBOR');
    }
    if ($info < 24) $n = $info;
    elseif ($info <= 27) {
        $len = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info];
        if ($o + $len > strlen($s)) throw new RuntimeException('CBOR');
        $n = 0;
        for ($i = 0; $i < $len; $i++) $n = ($n << 8) | ord($s[$o + $i]);
        $o += $len;
        if ($n < 0) throw new RuntimeException('CBOR');
    } else throw new RuntimeException('CBOR');
    switch ($type) {
        case 0: return $n;
        case 1: return -1 - $n;
        case 2: case 3:
            if ($o + $n > strlen($s)) throw new RuntimeException('CBOR');
            $v = substr($s, $o, $n); $o += $n; return $v;
        case 4:
            if ($n > 1000) throw new RuntimeException('CBOR');
            $a = []; for ($i = 0; $i < $n; $i++) $a[] = wa_cbor($s, $o); return $a;
        case 5:
            if ($n > 1000) throw new RuntimeException('CBOR');
            $m = []; for ($i = 0; $i < $n; $i++) { $k = wa_cbor($s, $o); $m[is_scalar($k) ? $k : serialize($k)] = wa_cbor($s, $o); } return $m;
    }
    throw new RuntimeException('CBOR');
}
function wa_der(int $tag, string $c): string {
    $n = strlen($c); $l = ltrim(pack('N', $n), "\0");
    return chr($tag) . ($n < 128 ? chr($n) : chr(0x80 | strlen($l)) . $l) . $c;
}
function wa_der_int(string $bin): string { $bin = ltrim($bin, "\0"); if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\0" . $bin; return wa_der(0x02, $bin); }
function wa_pem(string $spki): string { return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n"; }
function wa_cose_to_pem(array $k): array {
    if (($k[1] ?? null) === 2 && ($k[3] ?? null) === -7 && ($k[-1] ?? null) === 1 && strlen((string)($k[-2] ?? '')) === 32 && strlen((string)($k[-3] ?? '')) === 32)
        return [wa_pem(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3]), -7];
    if (($k[1] ?? null) === 3 && ($k[3] ?? null) === -257 && !empty($k[-1]) && !empty($k[-2])) {
        $rsa = wa_der(0x30, wa_der_int($k[-1]) . wa_der_int($k[-2]));
        return [wa_pem(wa_der(0x30, wa_der(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00") . wa_der(0x03, "\0" . $rsa))), -257];
    }
    throw new RuntimeException('Nepodporovaný typ klíče.');
}

/** Společná kontrola clientDataJSON; vrací bajty clientDataJSON. Výzva se spotřebuje (jednorázově). */
function wa_check_client(PDO $pdo, string $cdjB64, string $type, string $purpose): string {
    $cdj = b64u_dec($cdjB64);
    $c = json_decode($cdj, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type) throw new RuntimeException('Neplatná odpověď zařízení.');
    if (!hash_equals(wa_origin(), (string)($c['origin'] ?? ''))) throw new RuntimeException('Neplatný původ (adresa) požadavku.');
    if (!empty($c['crossOrigin'])) throw new RuntimeException('Neplatný požadavek.');
    $ch = b64u_dec((string)($c['challenge'] ?? ''));
    if ($ch === '' || !auth_token_use($pdo, $purpose, bin2hex($ch))) throw new RuntimeException('Výzva vypršela nebo už byla použita.');
    return $cdj;
}
function wa_check_authdata(string $ad, bool $needAt): void {
    if (strlen($ad) < 37) throw new RuntimeException('Neplatná data zařízení.');
    if (!hash_equals(hash('sha256', wa_rp_id(), true), substr($ad, 0, 32))) throw new RuntimeException('Neplatná doména (rp).');
    $fl = ord($ad[32]);
    if (!($fl & 0x01) || !($fl & 0x04)) throw new RuntimeException('Chybí ověření uživatele (UP/UV).');
    if ($needAt && !($fl & 0x40)) throw new RuntimeException('Chybí data klíče.');
}

/** Zpracuje registraci; vrací [cred_id(raw), pem, alg, signCount]. */
function wa_register(PDO $pdo, array $cred): array {
    $r = $cred['response'] ?? [];
    wa_check_client($pdo, (string)($r['clientDataJSON'] ?? ''), 'webauthn.create', 'wa-reg');
    $o = 0; $att = wa_cbor(b64u_dec((string)($r['attestationObject'] ?? '')), $o);
    $ad = (string)($att['authData'] ?? '');
    wa_check_authdata($ad, true);
    $idLen = (ord($ad[53]) << 8) | ord($ad[54]);
    $credId = substr($ad, 55, $idLen);
    if ($credId === '' || strlen($credId) !== $idLen) throw new RuntimeException('Neplatné ID klíče.');
    $o = 55 + $idLen; $key = wa_cbor($ad, $o);
    if (!is_array($key)) throw new RuntimeException('Neplatný klíč.');
    [$pem, $alg] = wa_cose_to_pem($key);
    $count = unpack('N', substr($ad, 33, 4))[1];
    return [$credId, $pem, $alg, (int)$count];
}

/** Ověří přihlášení; vrací řádek zařízení. */
function wa_authenticate(PDO $pdo, array $cred): array {
    $r = $cred['response'] ?? [];
    $raw = b64u_dec((string)($cred['rawId'] ?? ''));
    $st = $pdo->prepare("SELECT * FROM passkeys WHERE cred_hash = ?"); $st->execute([hash('sha256', $raw)]);
    $pk = $st->fetch();
    if (!$pk) throw new RuntimeException('Toto zařízení není registrované.');
    $cdj = wa_check_client($pdo, (string)($r['clientDataJSON'] ?? ''), 'webauthn.get', 'wa-login');
    $ad = b64u_dec((string)($r['authenticatorData'] ?? ''));
    wa_check_authdata($ad, false);
    $ok = openssl_verify($ad . hash('sha256', $cdj, true), b64u_dec((string)($r['signature'] ?? '')), $pk['public_key'], OPENSSL_ALGO_SHA256);
    if ($ok !== 1) throw new RuntimeException('Neplatný podpis.');
    $new = unpack('N', substr($ad, 33, 4))[1]; $old = (int)$pk['sign_count'];
    if (($old > 0 || $new > 0) && $new <= $old) throw new RuntimeException('Podezřelý čítač (možná kopie klíče).');
    $pdo->prepare("UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?")->execute([(int)$new, time(), $pk['id']]);
    return $pk;
}
