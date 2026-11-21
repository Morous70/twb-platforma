<?php
// Google Disk přes REST API (cURL), bez externích knihoven. V testovacím režimu lokální složka.

const DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive';
const FOLDER_MIME = 'application/vnd.google-apps.folder';

function drive_mock(): bool { return (bool)cfg('drive_mock'); }
function oauth_redirect_uri(): string { return rtrim(cfg('base_url'), '/') . '/oauth.php'; }

function drive_status(): array {
    if (drive_mock()) return ['mode' => 'mock', 'configured' => true, 'connected' => true];
    return [
        'mode' => 'oauth',
        'configured' => cfg('google_client_id') && cfg('google_client_secret'),
        'connected' => (bool)setting('google_refresh_token'),
        'account' => setting('google_account'),
    ];
}

function oauth_auth_url(string $state): string {
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => cfg('google_client_id'),
        'redirect_uri' => oauth_redirect_uri(),
        'response_type' => 'code',
        'scope' => DRIVE_SCOPE . ' email',
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ]);
}

function http_req(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $opts['timeout'] ?? 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HEADER => true,
    ]);
    if (isset($opts['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opts['json']));
        $headers[] = 'Content-Type: application/json; charset=UTF-8';
    } elseif (isset($opts['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opts['form']));
    } elseif (isset($opts['file'])) {
        $fh = fopen($opts['file'], 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($opts['file'])]);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        throw new RuntimeException("Spojení s Googlem selhalo: $err");
    }
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (isset($fh)) fclose($fh);
    $rawHeaders = substr($raw, 0, $hsize);
    $body = substr($raw, $hsize);
    $hdr = [];
    foreach (explode("\r\n", $rawHeaders) as $line) {
        if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $hdr[strtolower(trim($k))] = trim($v); }
    }
    return ['code' => $code, 'headers' => $hdr, 'body' => $body, 'json' => json_decode($body, true)];
}

function oauth_exchange_code(string $code): void {
    $r = http_req('POST', 'https://oauth2.googleapis.com/token', ['form' => [
        'code' => $code, 'client_id' => cfg('google_client_id'), 'client_secret' => cfg('google_client_secret'),
        'redirect_uri' => oauth_redirect_uri(), 'grant_type' => 'authorization_code',
    ]]);
    if ($r['code'] !== 200 || empty($r['json']['refresh_token'])) {
        throw new RuntimeException('Google nevrátil přístupový klíč: ' . ($r['json']['error_description'] ?? $r['body']));
    }
    set_setting('google_refresh_token', $r['json']['refresh_token']);
    set_setting('google_access_token', $r['json']['access_token']);
    set_setting('google_access_expires', (string)(time() + (int)$r['json']['expires_in'] - 60));
    // e-mail účtu jen pro informaci
    $u = http_req('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', ['headers' => ['Authorization: Bearer ' . $r['json']['access_token']]]);
    set_setting('google_account', $u['json']['email'] ?? '');
}

function oauth_disconnect(): void {
    foreach (['google_refresh_token', 'google_access_token', 'google_access_expires', 'google_account'] as $k) set_setting($k, null);
}

function access_token(): string {
    $tok = setting('google_access_token');
    if ($tok && (int)setting('google_access_expires', 0) > time()) return $tok;
    $refresh = setting('google_refresh_token');
    if (!$refresh) throw new RuntimeException('Google Disk není připojen (Nastavení → Připojit Google Disk).');
    $r = http_req('POST', 'https://oauth2.googleapis.com/token', ['form' => [
        'refresh_token' => $refresh, 'client_id' => cfg('google_client_id'),
        'client_secret' => cfg('google_client_secret'), 'grant_type' => 'refresh_token',
    ]]);
    if ($r['code'] !== 200) {
        $e = $r['json']['error'] ?? '';
        if ($e === 'invalid_grant') throw new RuntimeException('Přístup ke Google Disku vypršel nebo byl odvolán – připojte Disk znovu v Nastavení.');
        throw new RuntimeException('Obnovení přístupu ke Googlu selhalo: ' . ($r['json']['error_description'] ?? $r['body']));
    }
    set_setting('google_access_token', $r['json']['access_token']);
    set_setting('google_access_expires', (string)(time() + (int)$r['json']['expires_in'] - 60));
    return $r['json']['access_token'];
}

/** Dočasná chyba Googlu, po které má smysl to za chvíli zkusit znovu (500, 502, 503, 504, 429, překročený limit). */
function gapi_transient(int $code, ?string $reason = null): bool {
    if (in_array($code, [429, 500, 502, 503, 504], true)) return true;
    return $code === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true);
}

/** Čekání před dalším pokusem (s, rostoucí: 1, 2, 4 … + náhodná část). V testech jde vypnout. */
function gapi_backoff(int $attempt): void {
    if (!empty($GLOBALS['gapi_no_sleep'])) return; // testy
    usleep((int)((2 ** $attempt + mt_rand(0, 1000) / 1000) * 1e6));
}

/**
 * Zavolá $fn a při dočasné chybě Googlu (nebo výpadku spojení) to zkusí znovu, celkem nejvýš $tries krát.
 * Ostatní chyby (špatná složka, chybějící práva…) se vyhodí hned.
 */
function gapi_retry(callable $fn, int $tries = 4) {
    for ($i = 0; ; $i++) {
        try {
            return $fn();
        } catch (RuntimeException $e) {
            $transient = $e instanceof GapiTransient || ($e->getCode() === 0 && str_starts_with($e->getMessage(), 'Spojení s Googlem selhalo'));
            if (!$transient || $i >= $tries - 1) throw $e;
            gapi_backoff($i);
        }
    }
}
final class GapiTransient extends RuntimeException {}

function gapi(string $method, string $url, array $opts = []): array {
    $retry = $opts['retry'] ?? true;
    unset($opts['retry']);
    $call = function () use ($method, $url, $opts) {
        $o = $opts;
        $o['headers'] = array_merge($o['headers'] ?? [], ['Authorization: Bearer ' . access_token()]);
        return gapi_check(http_req($method, $url, $o));
    };
    return $retry ? gapi_retry($call) : $call();
}

/** Odpověď Googlu → výjimka s kódem HTTP (dočasné chyby jako GapiTransient). */
function gapi_check(array $r): array {
    if ($r['code'] >= 400) {
        $reason = $r['json']['error']['errors'][0]['reason'] ?? null;
        $msg = $r['json']['error']['message'] ?? $r['body'];
        if ($r['code'] === 404) $msg = 'Soubor nebo složka nebyla na Google Disku nalezena (zkontrolujte ID složky a přístup).';
        if (gapi_transient($r['code'], $reason)) {
            throw new GapiTransient("Google Disk ({$r['code']}): $msg – dočasná chyba na straně Googlu, zkusilo se to několikrát znovu. Synchronizace to zopakuje sama při příštím běhu.", $r['code']);
        }
        throw new RuntimeException("Google Disk ({$r['code']}): $msg", $r['code']);
    }
    return $r;
}

// ---------- Operace ----------
function parse_folder_id(string $input): string {
    $v = trim($input);
    if (preg_match('~folders/([\w-]+)~', $v, $m) || preg_match('~[?&]id=([\w-]+)~', $v, $m)) return $m[1];
    return $v;
}

function drive_get_folder(string $id): array {
    if (drive_mock()) {
        @mkdir(DATA_DIR . "/mock-drive/$id", 0775, true);
        return ['id' => $id, 'name' => "Testovací složka $id"];
    }
    $r = gapi('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($id) . '?' . http_build_query([
        'fields' => 'id,name,mimeType,trashed', 'supportsAllDrives' => 'true']));
    $j = $r['json'];
    if ($j['mimeType'] !== FOLDER_MIME) throw new RuntimeException('Zadané ID není složka.');
    if (!empty($j['trashed'])) throw new RuntimeException('Složka je v koši.');
    return ['id' => $j['id'], 'name' => $j['name']];
}

/** Soubory (ne podsložky) v dané složce: [ ['id','name','md5'] ]; s $details navíc 'mime' a 'size'. */
function drive_list(string $folderId, bool $details = false): array {
    if (drive_mock()) {
        $dir = DATA_DIR . "/mock-drive/$folderId";
        @mkdir($dir, 0775, true);
        $out = [];
        foreach (scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || is_dir("$dir/$f")) continue;
            $out[] = ['id' => "$folderId/$f", 'name' => $f, 'md5' => md5_file("$dir/$f")]
                + ($details ? ['mime' => null, 'size' => filesize("$dir/$f")] : []);
        }
        return $out;
    }
    $files = [];
    $pageToken = null;
    do {
        $params = [
            'q' => "'" . str_replace("'", "\\'", $folderId) . "' in parents and trashed = false and mimeType != '" . FOLDER_MIME . "'",
            'fields' => 'nextPageToken, files(id,name,md5Checksum' . ($details ? ',mimeType,size' : '') . ')',
            'pageSize' => 1000, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ];
        if ($pageToken) $params['pageToken'] = $pageToken;
        $j = gapi('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params))['json'];
        foreach ($j['files'] as $f) $files[] = ['id' => $f['id'], 'name' => $f['name'], 'md5' => $f['md5Checksum'] ?? null]
            + ($details ? ['mime' => $f['mimeType'] ?? null, 'size' => isset($f['size']) ? (int)$f['size'] : null] : []);
        $pageToken = $j['nextPageToken'] ?? null;
    } while ($pageToken);
    return $files;
}

function drive_upload(string $folderId, string $path, string $name, string $mime): string {
    if (!is_file($path)) throw new RuntimeException("Soubor média chybí na serveru: $name");
    if (drive_mock()) {
        copy($path, DATA_DIR . "/mock-drive/$folderId/$name");
        return "$folderId/$name";
    }
    // Resumable upload: 1) založit relaci s metadaty, 2) poslat obsah. Při dočasné chybě Googlu se celé nahrání zopakuje
    // s novou relací (jinak by mohl zůstat napůl nahraný soubor).
    return gapi_retry(function () use ($folderId, $path, $name, $mime) {
        $init = gapi('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true', [
            'json' => ['name' => $name, 'parents' => [$folderId]],
            'headers' => ['X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . filesize($path)],
            'retry' => false,
        ]);
        $session = $init['headers']['location'] ?? null;
        if (!$session) throw new GapiTransient('Google nevrátil adresu pro nahrání.');
        $r = gapi('PUT', $session, ['file' => $path, 'headers' => ['Content-Type: ' . $mime], 'timeout' => 600, 'retry' => false]);
        return $r['json']['id'] ?? '';
    });
}

function drive_remove(string $fileId, string $mode, string $folderId = ''): void {
    if (drive_mock()) { @unlink(DATA_DIR . "/mock-drive/$fileId"); return; }
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?supportsAllDrives=true';
    try {
        if ($mode === 'permanent') gapi('DELETE', $url);
        else gapi('PATCH', $url, ['json' => ['trashed' => true]]);
    } catch (RuntimeException $e) {
        // Soubor patří jinému účtu: smazat ani dát do koše ho nelze, ale jde ho vyjmout ze složky
        // (vlastníkovi zůstane, přehrávač ho už neuvidí).
        if ($e->getCode() !== 403 || $folderId === '') throw $e;
        gapi('PATCH', $url . '&removeParents=' . rawurlencode($folderId), ['json' => new stdClass()]);
    }
}

/**
 * Pošle obsah souboru z Disku rovnou na výstup (po kusech, i velká videa bez načtení do paměti).
 * $onStart se zavolá před prvním bajtem (pro hlavičky odpovědi). Vrací počet poslaných bajtů.
 */
function drive_stream(string $fileId, callable $onStart): int {
    if (drive_mock()) {
        $path = DATA_DIR . '/mock-drive/' . $fileId;
        if (str_contains($fileId, '..') || !is_file($path)) throw new RuntimeException('Soubor nebyl na Google Disku nalezen.', 404);
        $onStart();
        $fh = fopen($path, 'rb');
        $n = 0;
        while (!feof($fh)) { $chunk = fread($fh, 65536); echo $chunk; $n += strlen($chunk); flush(); }
        fclose($fh);
        return $n;
    }
    $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '?alt=media&supportsAllDrives=true';
    $ch = curl_init($url);
    $started = false; $code = 0; $n = 0; $errBody = '';
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . access_token()],
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 0, CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60,
        CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$code) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $h, $m)) $code = (int)$m[1];
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$started, &$code, &$n, &$errBody, $onStart) {
            if ($code >= 400) { $errBody .= $data; return strlen($data); }
            if (!$started) { $onStart(); $started = true; }
            echo $data; $n += strlen($data);
            if (connection_aborted()) return 0;
            return strlen($data);
        },
    ]);
    $ok = curl_exec($ch);
    if ($code >= 400) {
        $msg = json_decode($errBody, true)['error']['message'] ?? 'chyba';
        throw new RuntimeException("Google Disk ($code): $msg", $code);
    }
    if ($ok === false && !$started) throw new RuntimeException('Spojení s Googlem selhalo: ' . curl_error($ch));
    return $n;
}
