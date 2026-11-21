<?php
declare(strict_types=1);
// Záloha na Google Drive (bez knihoven). Rozsah drive.file = aplikace vidí jen soubory, které sama vytvořila.
// Přihlašovací údaje: google_client_id / google_client_secret v app/config.php (typ klienta „Web application“).

const GD_SCOPE = 'https://www.googleapis.com/auth/drive.file';

function gd_host(string $k): string {
    return rtrim((string)cfg($k, ['google_auth_host' => 'https://accounts.google.com', 'google_oauth_host' => 'https://oauth2.googleapis.com', 'google_api_host' => 'https://www.googleapis.com'][$k]), '/');
}
function gd_redirect(): string { return rtrim((string)cfg('base_url'), '/') . '/gdrive_connect.php'; }
function gd_configured(): bool { return trim((string)cfg('google_client_id', '')) !== '' && trim((string)cfg('google_client_secret', '')) !== ''; }
function gd_connected(): bool { return meta_get('gd_refresh', '') !== ''; }

/** Jeden HTTP požadavek. $opts: headers[], body (string), infile (path) – streamovaný PUT. Vrací [status, dekódované JSON, hlavičky]. */
function gd_http(string $method, string $url, array $opts = []): array {
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HTTPHEADER => $opts['headers'] ?? [],
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { $p = explode(':', $l, 2); if (count($p) === 2) $hdr[strtolower(trim($p[0]))] = trim($p[1]); return strlen($l); },
    ]);
    $fh = null;
    if (isset($opts['infile'])) {
        $fh = fopen($opts['infile'], 'rb');
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($opts['infile'])]);
    } elseif (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    $raw = curl_exec($ch);
    if ($raw === false) { $e = curl_error($ch); if ($fh) fclose($fh); throw new RuntimeException('Síťová chyba: ' . $e); }
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($fh) fclose($fh);
    $j = json_decode((string)$raw, true);
    return [$st, is_array($j) ? $j : [], $hdr];
}
function gd_err(array $j, int $st): string {
    $e = $j['error'] ?? null;
    if (is_array($e)) return ($e['message'] ?? 'chyba') . ' (HTTP ' . $st . ')';
    return ($j['error_description'] ?? (is_string($e) ? $e : 'chyba')) . ' (HTTP ' . $st . ')';
}

/** Výměna kódu z přihlášení za tokeny. */
function gd_exchange(string $code): array {
    [$st, $j] = gd_http('POST', gd_host('google_oauth_host') . '/token', ['headers' => ['Content-Type: application/x-www-form-urlencoded'], 'body' => http_build_query([
        'code' => $code, 'client_id' => cfg('google_client_id'), 'client_secret' => cfg('google_client_secret'),
        'redirect_uri' => gd_redirect(), 'grant_type' => 'authorization_code'])]);
    if ($st !== 200 || empty($j['access_token'])) throw new RuntimeException('Google odmítl kód: ' . gd_err($j, $st));
    return $j;
}

/** Platný přístupový token (obnoví se z refresh tokenu). */
function gd_token(): string {
    $at = meta_get('gd_access', '');
    if ($at !== '' && (int)meta_get('gd_access_exp', '0') > time() + 60) return $at;
    $rt = meta_get('gd_refresh', '');
    if ($rt === '') throw new RuntimeException('Google Drive není propojený.');
    [$st, $j] = gd_http('POST', gd_host('google_oauth_host') . '/token', ['headers' => ['Content-Type: application/x-www-form-urlencoded'], 'body' => http_build_query([
        'client_id' => cfg('google_client_id'), 'client_secret' => cfg('google_client_secret'), 'refresh_token' => $rt, 'grant_type' => 'refresh_token'])]);
    if ($st !== 200 || empty($j['access_token'])) {
        if (($j['error'] ?? '') === 'invalid_grant') {
            meta_set('gd_refresh', ''); meta_set('gd_access', '');
            throw new RuntimeException('Propojení s Google Drive vypršelo nebo bylo zrušeno. Propojte Drive znovu. (Pokud má aplikace v Google stav „Testing“, platí propojení jen 7 dní, viz Nápověda.)');
        }
        throw new RuntimeException('Nelze obnovit přístup ke Google Drive: ' . gd_err($j, $st));
    }
    meta_set('gd_access', (string)$j['access_token']); meta_set('gd_access_exp', (string)(time() + (int)($j['expires_in'] ?? 3600)));
    return (string)$j['access_token'];
}
function gd_api(string $method, string $path, array $query = [], ?array $json = null): array {
    $url = gd_host('google_api_host') . $path . ($query ? '?' . http_build_query($query) : '');
    $h = ['Authorization: Bearer ' . gd_token()];
    $o = ['headers' => $h];
    if ($json !== null) { $o['headers'][] = 'Content-Type: application/json; charset=UTF-8'; $o['body'] = json_encode($json); }
    elseif ($method === 'POST') $o['body'] = '';
    [$st, $j] = gd_http($method, $url, $o);
    if ($st >= 400) throw new RuntimeException('Google Drive: ' . gd_err($j, $st));
    return $j;
}

/** Složka pro zálohy (vytvoří se při prvním použití). */
function gd_folder(): string {
    $id = meta_get('gd_folder', '');
    if ($id !== '') {
        try { $f = gd_api('GET', '/drive/v3/files/' . rawurlencode($id), ['fields' => 'id,trashed']); if (empty($f['trashed'])) return $id; }
        catch (RuntimeException $e) { if (!str_contains($e->getMessage(), '404')) throw $e; }
    }
    $f = gd_api('POST', '/drive/v3/files', ['fields' => 'id'], ['name' => 'Social planner – zálohy', 'mimeType' => 'application/vnd.google-apps.folder']);
    meta_set('gd_folder', (string)$f['id']);
    return (string)$f['id'];
}

/** Nahraje soubor (streamovaný resumable upload) do složky záloh. */
function gd_upload(string $path, string $name): array {
    $folder = gd_folder();
    $tok = gd_token();
    [$st, $j, $h] = gd_http('POST', gd_host('google_api_host') . '/upload/drive/v3/files?uploadType=resumable&fields=id,name,size', ['headers' => [
        'Authorization: Bearer ' . $tok, 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . filesize($path)],
        'body' => json_encode(['name' => $name, 'parents' => [$folder]])]);
    if ($st >= 400 || empty($h['location'])) throw new RuntimeException('Google Drive: nelze zahájit nahrávání: ' . gd_err($j, $st));
    [$st, $j] = gd_http('PUT', $h['location'], ['headers' => ['Content-Type: application/octet-stream'], 'infile' => $path]);
    if ($st >= 400 || empty($j['id'])) throw new RuntimeException('Google Drive: nahrávání selhalo: ' . gd_err($j, $st));
    return $j;
}

/** Ponechá $keep nejnovějších souborů ve složce záloh. */
function gd_prune(int $keep): int {
    $folder = gd_folder(); $n = 0;
    $r = gd_api('GET', '/drive/v3/files', ['q' => "'$folder' in parents and trashed = false", 'orderBy' => 'createdTime desc', 'pageSize' => 200, 'fields' => 'files(id,name)']);
    foreach (array_slice($r['files'] ?? [], $keep) as $f) { try { gd_api('DELETE', '/drive/v3/files/' . rawurlencode((string)$f['id'])); $n++; } catch (Throwable $e) { /* příště */ } }
    return $n;
}

/** Záloha na Drive: zip s databází (a volitelně s obrázky). Zapisuje stav do meta. */
function gd_backup(PDO $pdo): string {
    set_time_limit(600);
    $withUp = meta_get('gd_uploads', '0') === '1';
    $zip = backup_make_zip($pdo, $withUp);
    try {
        $name = 'planner-' . date('Ymd-His') . ($withUp ? '-s-obrazky' : '') . '.zip';
        $f = gd_upload($zip, $name);
        try { gd_prune(max(2, (int)meta_get('gd_keep', '8'))); } catch (Throwable $e) { /* úklid není kritický */ }
        meta_set('gd_last', (string)time()); meta_set('gd_error', ''); meta_set('gd_last_name', (string)($f['name'] ?? $name));
        return (string)($f['name'] ?? $name);
    } catch (Throwable $e) {
        meta_set('gd_error', $e->getMessage()); meta_set('gd_try', (string)time());
        throw $e;
    } finally { @unlink($zip); }
}

/** Z cronu: po zapnutí jednou za 7 dní; po chybě znovu nejdřív za hodinu. */
function gd_if_due(PDO $pdo): void {
    if (meta_get('gd_enabled', '0') !== '1' || !gd_connected()) return;
    $now = time();
    if ($now - (int)meta_get('gd_last', '0') < 7 * 86400) return;
    if ($now - (int)meta_get('gd_try', '0') < 3600) return;
    meta_set('gd_try', (string)$now);
    try { gd_backup($pdo); } catch (Throwable $e) { log_line('GDRIVE záloha selhala: ' . $e->getMessage()); }
}

function gd_disconnect(): void {
    $rt = meta_get('gd_refresh', '');
    if ($rt !== '') { try { gd_http('POST', gd_host('google_oauth_host') . '/revoke', ['headers' => ['Content-Type: application/x-www-form-urlencoded'], 'body' => http_build_query(['token' => $rt])]); } catch (Throwable $e) { /* i bez odvolání */ } }
    foreach (['gd_refresh', 'gd_access', 'gd_access_exp', 'gd_folder', 'gd_email', 'gd_error'] as $k) meta_set($k, '');
}
