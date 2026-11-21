<?php
// Aktualizace z TWB Update Serveru (režim authenticated).
// Kontrola: latest.php → podpis Ed25519 (veřejný klíč napevno níže) → novější verze se nabídne.
// Instalace jen po potvrzení: stažení → SHA-256 → rozbalení do přípravné složky (kontrola cest) → záloha databáze
// a kopie současných souborů → přepsání (kromě config.php a data/) → kontrola health.php (provede i převod databáze)
// → při chybě návrat k předchozí verzi.

const UPDATE_APP = 'media-planner';
const UPDATE_SERVER = 'https://updates.trafikabr.cz';
const UPDATE_PUBLIC_KEY_B64 = 'JTvTWDW+eSP/GtT9zG3t+3UraawTk82vKteb2Dci8Cg=';
const UPDATE_ZIP_ROOT = 'media-planner/';          // složka uvnitř ZIP
const UPDATE_MAX_SIZE = 100 * 1048576;             // 100 MB
const UPDATE_DIR = DATA_DIR . '/update';           // přípravná složka, předchozí verze, stažený ZIP
const UPDATE_KEEP = ['config.php', 'data'];        // nikdy nepřepisovat ani nemazat (cesty od kořene aplikace)

/** Chyba, která už je zapsaná v protokolu (při zachycení se nezapisuje znovu). */
final class UpdateLoggedError extends RuntimeException {}

function update_server(): string { return rtrim((string)cfg('update_url', UPDATE_SERVER), '/'); }
function update_token(): string { return trim((string)cfg('update_token', '')); }
/** Veřejný klíč: napevno; config.php ho smí přepsat jen kvůli automatickým testům. */
function update_public_key(): string { return (string)cfg('update_public_key', UPDATE_PUBLIC_KEY_B64); }
function update_mask(string $t): string { return $t === '' ? '' : substr($t, 0, 4) . str_repeat('•', 8) . substr($t, -4); }

/** Proč aktualizace nejde (null = jde). */
function update_unsupported(): ?string {
    if (!function_exists('sodium_crypto_sign_verify_detached')) return 'PHP na serveru nemá rozšíření sodium (ověření podpisu).';
    if (!class_exists('ZipArchive')) return 'PHP na serveru nemá rozšíření zip.';
    if (!function_exists('curl_init')) return 'PHP na serveru nemá rozšíření curl.';
    if (update_token() === '') return 'V config.php chybí update_token (token upd_… z TWB Update Serveru).';
    return null;
}

/** Soubory a složky aplikace, do kterých PHP nemůže zapisovat (prázdné = vše v pořádku). */
function update_unwritable(): array {
    $bad = [];
    if (!is_writable(APP_DIR)) $bad[] = '/';
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator(APP_DIR, FilesystemIterator::SKIP_DOTS),
        fn($f) => !in_array(update_rel($f->getPathname()), UPDATE_KEEP, true)), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($it as $f) {
        if (!is_writable($f->getPathname())) $bad[] = update_rel($f->getPathname()) . ($f->isDir() ? '/' : '');
        if (count($bad) >= 10) break;
    }
    return $bad;
}
function update_rel(string $path): string { return ltrim(substr($path, strlen(APP_DIR)), '/'); }
function update_kept(string $rel): bool {
    foreach (UPDATE_KEEP as $k) if ($rel === $k || str_starts_with($rel, "$k/")) return true;
    return false;
}

function update_log(string $result, string $msg, string $level = 'info'): void {
    set_setting('update_result', json_encode(['result' => $result, 'message' => $msg, 'at' => time()], JSON_UNESCAPED_UNICODE));
    app_log($level, "Aktualizace ($result): $msg");
}

function update_http(string $url, ?string $saveTo = null): array {
    if (!str_starts_with($url, 'https://') && !preg_match('~^http://127\.0\.0\.1[:/]~', $url)) throw new RuntimeException('Update Server musí mít adresu https://.');
    $tok = update_token();
    $ch = curl_init($url);
    $opts = [CURLOPT_TIMEOUT => $saveTo ? 600 : 30, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => APP_NAME . '/' . APP_VERSION,
        CURLOPT_HTTPHEADER => ["Authorization: Bearer $tok", "X-Update-Token: $tok", 'Accept: application/json']];
    if ($saveTo) {
        $fh = fopen($saveTo, 'wb');
        $opts[CURLOPT_FILE] = $fh;
        $opts[CURLOPT_NOPROGRESS] = false;
        $opts[CURLOPT_PROGRESSFUNCTION] = fn($c, $dlTotal, $dl) => $dl > UPDATE_MAX_SIZE ? 1 : 0; // příliš velké → přerušit
    } else {
        $opts[CURLOPT_RETURNTRANSFER] = true;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if (isset($fh)) fclose($fh);
    if ($body === false) throw new RuntimeException("Update Server neodpovídá: $err");
    return ['code' => $code, 'body' => $saveTo ? '' : (string)$body];
}

/**
 * Ověří odpověď latest.php. Vrací podepsané údaje o vydání, nebo null (žádné vydání).
 * Výjimka s kódem: signature_error, invalid_response.
 */
function update_verify(string $body): ?array {
    try { $j = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Odpověď serveru není platný JSON.', 2); }
    if (!is_array($j)) throw new RuntimeException('Neočekávaná odpověď serveru.', 2);
    if (array_key_exists('update', $j) && $j['update'] === null) {
        if (($j['app'] ?? null) !== UPDATE_APP) throw new RuntimeException('Server odpověděl pro jinou aplikaci.', 2);
        return null;
    }
    if (($j['algorithm'] ?? '') !== 'Ed25519' || !is_string($j['payload_b64'] ?? null) || !is_string($j['signature'] ?? null)) {
        throw new RuntimeException('Odpověď serveru nemá podpis Ed25519.', 1);
    }
    $raw = base64_decode($j['payload_b64'], true);
    $sig = base64_decode($j['signature'], true);
    $key = base64_decode(update_public_key(), true);
    if ($raw === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
        || !sodium_crypto_sign_verify_detached($sig, $raw, $key)) {
        throw new RuntimeException('Podpis vydání nesouhlasí – aktualizace se nenabídne.', 1);
    }
    // údaje bereme jen z podepsaných bajtů, nikdy z nepodepsaného „payload“
    try { $p = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Podepsané údaje nejsou platný JSON.', 1); }
    $ok = is_array($p)
        && ($p['app'] ?? null) === UPDATE_APP
        && is_string($p['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $p['version'])
        && is_string($p['release_id'] ?? null) && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $p['release_id'])
        && is_string($p['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $p['sha256'])
        && is_int($p['size'] ?? null) && $p['size'] > 0 && $p['size'] <= UPDATE_MAX_SIZE
        && is_string($p['download_path'] ?? null) && preg_match('~^/api/v1/download\.php\?[A-Za-z0-9=&%._-]+$~', $p['download_path']);
    if (!$ok) throw new RuntimeException('Podepsané údaje o vydání nemají očekávaný tvar.', 1);
    return ['version' => $p['version'], 'release_id' => $p['release_id'], 'sha256' => $p['sha256'], 'size' => $p['size'],
            'released_at' => is_string($p['released_at'] ?? null) ? $p['released_at'] : null, 'download_path' => $p['download_path']];
}

/** Zjistí novou verzi. Vrací stav pro zobrazení. */
function update_check(): array {
    if ($why = update_unsupported()) { update_log('config_error', $why, 'error'); return update_status(); }
    set_setting('update_checked_at', (string)time());
    try {
        $r = update_http(update_server() . '/api/v1/latest.php?app=' . rawurlencode(UPDATE_APP));
        if ($r['code'] === 401 || $r['code'] === 403) { update_log('auth_error', 'Server odmítl token (HTTP ' . $r['code'] . '). Zkontrolujte update_token v config.php.', 'error'); return update_status(); }
        if ($r['code'] === 404) { set_setting('update_available', null); update_log('no_release', 'Aplikace na serveru nemá vydání (HTTP 404).'); return update_status(); }
        if ($r['code'] !== 200) { update_log('server_error', "Update Server vrátil HTTP {$r['code']}. Zůstává současná verze.", 'error'); return update_status(); }
        $rel = update_verify($r['body']);
        if (!$rel || version_compare($rel['version'], APP_VERSION, '<=')) {
            set_setting('update_available', null);
            update_log($rel ? 'ok' : 'no_release', $rel ? "Aplikace je aktuální (server: {$rel['version']})." : 'Na serveru není žádné vydání.');
        } else {
            $was = json_decode((string)setting('update_available', ''), true);
            set_setting('update_available', json_encode($rel));
            update_log('ok', "Je k dispozici verze {$rel['version']}.", ($was['version'] ?? '') === $rel['version'] ? 'info' : 'success');
        }
    } catch (RuntimeException $e) {
        update_log($e->getCode() === 1 ? 'signature_error' : 'server_error', $e->getMessage(), 'error');
    }
    return update_status();
}

/** Volá cron: kontrola jednou za 24 hodin. */
function update_check_due(): void {
    if (update_unsupported()) return;
    if (time() - (int)setting('update_checked_at', 0) < 86400) return;
    update_check();
}

function update_status(): array {
    $av = json_decode((string)setting('update_available', ''), true);
    if ($av && version_compare($av['version'], APP_VERSION, '<=')) $av = null; // mezitím nainstalováno
    $prev = is_file(UPDATE_DIR . '/previous/VERSION') ? trim((string)file_get_contents(UPDATE_DIR . '/previous/VERSION')) : null;
    return [
        'version' => APP_VERSION, 'app' => UPDATE_APP, 'server' => update_server(),
        'token' => update_mask(update_token()), 'unsupported' => update_unsupported(),
        'checked_at' => (int)setting('update_checked_at', 0) ?: null,
        'available' => $av ?: null,
        'result' => json_decode((string)setting('update_result', ''), true),
        'previous' => $prev,
    ];
}

// ---------- instalace ----------

function update_rm(string $path): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) as $f) if ($f !== '.' && $f !== '..') update_rm("$path/$f");
    @rmdir($path);
}

/** Seznam souborů aplikace (relativní cesty), bez config.php a data/. */
function update_app_files(string $root): array {
    $out = [];
    if (!is_dir($root)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $rel = ltrim(substr($f->getPathname(), strlen($root)), '/');
        if (!update_kept($rel) && $f->isFile()) $out[] = $rel;
    }
    sort($out);
    return $out;
}

/** Zapíše soubor bezpečně (dočasný soubor + přejmenování). */
function update_put(string $dest, string $src): void {
    @mkdir(dirname($dest), 0775, true);
    $tmp = $dest . '.upd-' . bin2hex(random_bytes(3));
    if (!@copy($src, $tmp) || !@rename($tmp, $dest)) { @unlink($tmp); throw new RuntimeException("Nelze zapsat soubor " . update_rel($dest) . '.'); }
    // OPcache by jinak mohl dál spouštět starou verzi souboru (stejný čas změny v rámci jedné sekundy)
    if (function_exists('opcache_invalidate') && str_ends_with($dest, '.php')) @opcache_invalidate($dest, true);
}

/** Rozbalí ZIP do přípravné složky; odmítne nebezpečné cesty a odkazy. Vrací seznam souborů. */
function update_extract(string $zipPath, string $dest): array {
    $z = new ZipArchive();
    if ($z->open($zipPath) !== true) throw new RuntimeException('Stažený soubor není platný ZIP.');
    $files = [];
    try {
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            $name = $st['name'];
            if ($name === UPDATE_ZIP_ROOT || $name === rtrim(UPDATE_ZIP_ROOT, '/')) continue;
            if (!str_starts_with($name, UPDATE_ZIP_ROOT)) throw new RuntimeException("Balíček obsahuje soubor mimo složku media-planner/: $name");
            $rel = substr($name, strlen(UPDATE_ZIP_ROOT));
            if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.(/|$)~', $rel) || preg_match('~^[A-Za-z]:~', $rel)) {
                throw new RuntimeException("Balíček obsahuje nepovolenou cestu: $name");
            }
            if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new RuntimeException("Balíček obsahuje symbolický odkaz: $name");
            }
            if (str_ends_with($rel, '/')) continue;                    // složka
            if (update_kept($rel)) continue;                           // config.php a data/ se nikdy nepřepisují
            $target = "$dest/$rel";
            @mkdir(dirname($target), 0775, true);
            $in = $z->getStream($name);
            $out = fopen($target, 'wb');
            if (!$in || !$out) throw new RuntimeException("Nelze rozbalit $name.");
            stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);
            if (filesize($target) !== $st['size']) throw new RuntimeException("Soubor $name se nerozbalil celý.");
            $files[] = $rel;
        }
    } finally {
        $z->close();
    }
    return $files;
}

const UPDATE_MANIFEST = UPDATE_DIR . '/installed-files.json';

/**
 * Přepíše aplikaci obsahem složky $src. Smaže jen soubory, které nainstalovala předchozí aktualizace
 * a v nové verzi už nejsou (cizí soubory, např. .user.ini od hostingu, nechá být).
 */
function update_apply(string $src): void {
    $new = update_app_files($src);
    foreach ($new as $rel) update_put(APP_DIR . "/$rel", "$src/$rel");
    $old = json_decode((string)@file_get_contents(UPDATE_MANIFEST), true) ?: [];
    $newSet = array_flip($new);
    foreach ($old as $rel) {
        if (is_string($rel) && !isset($newSet[$rel]) && !update_kept($rel) && !preg_match('~(^|/)\.\.(/|$)~', $rel)) @unlink(APP_DIR . "/$rel");
    }
    file_put_contents(UPDATE_MANIFEST, json_encode($new, JSON_UNESCAPED_SLASHES));
    if (function_exists('opcache_reset')) @opcache_reset();
    clearstatcache(true);
}

/** Nová verze odpovídá? (health.php běží už s novým kódem a provede převod databáze.) */
function update_health(string $expectVersion): ?string {
    $key = bin2hex(random_bytes(16));
    set_setting('update_health_key', $key);
    $url = rtrim((string)cfg('base_url'), '/') . '/health.php?key=' . $key;
    $err = null;
    for ($try = 0; $try < 3; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => 'TWB-update-health']);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $j = is_string($body) ? json_decode($body, true) : null;
        if ($code === 200 && ($j['ok'] ?? false) && ($j['version'] ?? '') === $expectVersion) { set_setting('update_health_key', null); return null; }
        $err = $body === false ? 'kontrola nové verze neodpověděla: ' . curl_error($ch) : "kontrola nové verze vrátila HTTP $code: " . mb_substr(trim(strip_tags((string)$body)), 0, 300);
        usleep(700000);
    }
    set_setting('update_health_key', null);
    return $err;
}

/**
 * Nainstaluje nabízenou verzi. $version musí odpovídat nabídce (ochrana před dvojím kliknutím).
 * Vrací ['ok' => bool, 'message' => …, 'version' => …].
 */
function update_install(string $version): array {
    if ($why = update_unsupported()) throw new RuntimeException($why);
    $rel = json_decode((string)setting('update_available', ''), true);
    if (!$rel || $rel['version'] !== $version) throw new RuntimeException('Tato verze už není k dispozici. Zjistěte novou verzi znovu.');
    if (version_compare($version, APP_VERSION, '<=')) throw new RuntimeException("Verze $version není novější než nainstalovaná " . APP_VERSION . '.');
    if ($bad = update_unwritable()) throw new RuntimeException('PHP nemůže zapisovat do souborů aplikace: ' . implode(', ', $bad) . '. Aktualizujte ručně přes FTP.');
    if ((string)setting('backup_password', '') === '' || backup_supported()) throw new RuntimeException('Před aktualizací je potřeba záloha databáze – nastavte heslo záloh (Nastavení → Zálohy).');

    @set_time_limit(900);
    ignore_user_abort(true);
    @mkdir(UPDATE_DIR, 0775, true);
    $lock = fopen(UPDATE_DIR . '/install.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Aktualizace už probíhá.');
    $zip = UPDATE_DIR . "/twb-planovac-medii-$version.zip";
    $stage = UPDATE_DIR . '/staging';
    $prev = UPDATE_DIR . '/previous';
    $oldVersion = trim((string)@file_get_contents(APP_DIR . '/VERSION')) ?: APP_VERSION;
    $oldSchema = (int)setting('schema_version', 1);
    $applied = false;
    try {
        // 1) stažení a kontrola
        app_log('info', "Aktualizace na $version: stahuji balíček.");
        @unlink($zip);
        $r = update_http(update_server() . $rel['download_path'], $zip);
        if ($r['code'] !== 200) {
            @unlink($zip);
            update_log($r['code'] === 401 || $r['code'] === 403 ? 'auth_error' : 'install_error', "Stažení balíčku $version selhalo (HTTP {$r['code']}).", 'error');
            throw new UpdateLoggedError("Stažení balíčku selhalo (HTTP {$r['code']}).");
        }
        clearstatcache(true, $zip);
        if (filesize($zip) !== $rel['size'] || !hash_equals($rel['sha256'], (string)hash_file('sha256', $zip))) {
            @unlink($zip);
            update_log('hash_error', "Kontrolní součet staženého balíčku $version nesouhlasí – nic se neinstaluje.", 'error');
            throw new UpdateLoggedError('Kontrolní součet staženého balíčku nesouhlasí – nic se neinstaluje.');
        }
        // 2) rozbalení do přípravné složky
        update_rm($stage);
        @mkdir($stage, 0775, true);
        $files = update_extract($zip, $stage);
        $v = is_file("$stage/VERSION") ? trim((string)file_get_contents("$stage/VERSION")) : '';
        if ($v !== $version) throw new RuntimeException("Balíček obsahuje verzi „{$v}“ místo $version.");
        foreach (['index.php', 'api.php', 'health.php', 'lib/bootstrap.php', 'lib/version.php', 'assets/app.js'] as $need) {
            if (!in_array($need, $files, true)) throw new RuntimeException("V balíčku chybí $need.");
        }
        // 3) záloha databáze a kopie současné verze
        $b = backup_create('pred-aktualizaci');
        update_rm($prev);
        @mkdir($prev, 0775, true);
        foreach (update_app_files(APP_DIR) as $f) update_put("$prev/$f", APP_DIR . "/$f");
        file_put_contents("$prev/.update.json", json_encode(['version' => $oldVersion, 'schema' => $oldSchema, 'backup' => $b['name'], 'at' => time()]));
        // 4) přepsání a kontrola
        app_log('info', "Aktualizace na $version: přepisuji soubory (" . count($files) . ').');
        $applied = true;
        update_apply($stage);
        if ($err = update_health($version)) throw new RuntimeException("Nová verze nefunguje ($err).");
        update_rm($stage);
        @unlink($zip);
        set_setting('update_available', null);
        update_log('ok', "Nainstalována verze $version (předchozí $oldVersion je uložená pro návrat).", 'success');
        return ['ok' => true, 'version' => $version, 'message' => "Nainstalována verze $version."];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($applied) {
            try {
                update_restore_previous();
                update_log('rollback', "Aktualizace na $version selhala: $msg Vrácena verze $oldVersion.", 'error');
                $msg .= " Vrácena předchozí verze $oldVersion.";
            } catch (Throwable $e2) {
                update_log('install_error', "Aktualizace na $version selhala a návrat také: {$e2->getMessage()} Nahrajte aplikaci ručně přes FTP.", 'error');
                $msg .= ' Návrat se nepodařil: ' . $e2->getMessage() . ' Nahrajte aplikaci ručně přes FTP.';
            }
        } elseif (!$e instanceof UpdateLoggedError) {
            update_log('install_error', "Aktualizace na $version selhala: $msg", 'error');
        }
        update_rm($stage);
        return ['ok' => false, 'version' => $oldVersion, 'message' => $msg];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Vrátí uloženou předchozí verzi (soubory). Databáze se vrátí ze zálohy před aktualizací jen tehdy,
 * když ji nová verze převedla na vyšší verzi dat (stará aplikace by jí nerozuměla).
 */
function update_restore_previous(): array {
    $prev = UPDATE_DIR . '/previous';
    $meta = json_decode((string)@file_get_contents("$prev/.update.json"), true);
    if (!$meta || !is_file("$prev/VERSION")) throw new RuntimeException('Předchozí verze není uložená.');
    if ($bad = update_unwritable()) throw new RuntimeException('PHP nemůže zapisovat do souborů aplikace: ' . implode(', ', $bad) . '.');
    @set_time_limit(600);
    update_apply($prev);
    setting('__reload');
    $dbRestored = false;
    if ((int)setting('schema_version', 1) > (int)$meta['schema'] && !empty($meta['backup']) && is_file(BACKUP_DIR . '/' . $meta['backup'])) {
        backup_restore(BACKUP_DIR . '/' . $meta['backup'], (string)setting('backup_password'));
        $dbRestored = true;
    }
    update_rm($prev); // uložená verze je teď nainstalovaná
    app_log('info', "Vrácena verze {$meta['version']}" . ($dbRestored ? ' včetně databáze ze zálohy ' . $meta['backup'] : '') . '.');
    return ['version' => $meta['version'], 'db_restored' => $dbRestored];
}
