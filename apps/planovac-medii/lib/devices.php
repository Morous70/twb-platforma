<?php
// Zařízení pro přehrávání (plugin pro Chrome, později Android a TV Samsung).
// Každé zařízení má vlastní klíč jen pro čtení. V databázi je jen jeho SHA-256, klíč se ukáže jen při vytvoření.
// Zařízení si přes now.php stáhne seznam souborů ve složce na Google Disku a soubory samotné a přehrává je samo.

const DEVICE_KINDS = ['chrome' => 'Chrome', 'android' => 'Android', 'tizen' => 'TV Samsung'];
const DEVICE_DURATION_DEFAULT = 20;      // s, výchozí délka zobrazení obrázku, slidu, PDF a textu
const DEVICE_REFRESH_MINUTES = 5;        // jak často si zařízení obnovuje seznam
const DEVICE_LIST_TTL = 60;              // s, jak dlouho server drží seznam souborů z Disku v paměti
const DEVICE_FAIL_LIMIT = 20;            // špatných klíčů z jedné adresy…
const DEVICE_FAIL_WINDOW = 900;          // …během 15 minut = blokace na 15 minut
const DEVICE_PING_SECONDS = 15;          // jak často se zařízení ptá na příkazy a hlásí stav
const DEVICE_CMD_TTL = 120;              // s, příkaz, který si zařízení do té doby nevyzvedne, propadne
const DEVICE_OFFLINE_MINUTES = 15;       // zařízení, které se tak dlouho neozvalo, se hlásí jako „neozývá se“
const DEVICE_CMDS = ['refresh' => 'Obnovit teď', 'restart' => 'Restartovat přehrávač', 'next' => 'Další soubor'];

/** Přípona → [druh, MIME] pro soubory ve složce přehrávače. */
const DEVICE_EXT = [
    'jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'],
    'gif' => ['image', 'image/gif'], 'webp' => ['image', 'image/webp'], 'bmp' => ['image', 'image/bmp'],
    'mp4' => ['video', 'video/mp4'], 'm4v' => ['video', 'video/mp4'], 'mov' => ['video', 'video/quicktime'],
    'webm' => ['video', 'video/webm'], 'avi' => ['video', 'video/x-msvideo'], 'mkv' => ['video', 'video/x-matroska'],
    'pdf' => ['pdf', 'application/pdf'], 'txt' => ['text', 'text/plain'], 'url' => ['url', 'text/plain'],
    'html' => ['html', 'text/html'], 'htm' => ['html', 'text/html'],
];

function device_hash(string $token): string { return hash('sha256', $token); }
function device_token_new(): string { return 'dev_' . bin2hex(random_bytes(16)); }

function device_default_duration(): int {
    $d = (int)setting('device_duration', DEVICE_DURATION_DEFAULT);
    return $d >= 3 && $d <= 3600 ? $d : DEVICE_DURATION_DEFAULT;
}

/** Délka zobrazení v sekundách: 3–3600, null = výchozí. */
function device_valid_duration($v): ?int {
    if ($v === null || $v === '') return null;
    if (!is_numeric($v) || (int)$v != $v || (int)$v < 3 || (int)$v > 3600) throw new InvalidArgumentException('Délka zobrazení musí být 3 až 3600 sekund.');
    return (int)$v;
}

function device_out(array $d): array {
    $st = isset($d['status']) && $d['status'] !== null ? json_decode((string)$d['status'], true) : null;
    $seen = $d['last_seen'] ? strtotime($d['last_seen']) : null;
    $stAt = !empty($d['status_at']) ? strtotime($d['status_at']) : null;
    $cmdLive = !empty($d['cmd']) && !empty($d['cmd_at']) && time() - strtotime($d['cmd_at']) < DEVICE_CMD_TTL;
    return [
        'id' => (int)$d['id'], 'name' => $d['name'], 'kind' => $d['kind'], 'kind_label' => DEVICE_KINDS[$d['kind']] ?? $d['kind'],
        'duration' => $d['duration'] !== null ? (int)$d['duration'] : null,
        'created_at' => $d['created_at'], 'last_seen' => $d['last_seen'], 'client' => $d['client'],
        // ovládání: zařízení hlásí stav jen od verze přehrávače 1.1.0
        'controllable' => $stAt !== null,
        'status' => is_array($st) ? $st : null,
        'status_at' => $d['status_at'] ?? null,
        // přehrávač běží (hlásil se během poslední minuty); u Chrome jinak běží jen služba na pozadí
        'playing' => $stAt !== null && time() - $stAt < 4 * DEVICE_PING_SECONDS + 5,
        'offline' => $seen !== null && time() - $seen >= DEVICE_OFFLINE_MINUTES * 60,
        'cmd' => $cmdLive ? $d['cmd'] : null,
    ];
}

/** Zařízení, která se ozvala, ale déle než DEVICE_OFFLINE_MINUTES se neozývají (pro upozornění v aplikaci). */
function devices_offline(): array {
    $limit = date('Y-m-d H:i:s', time() - DEVICE_OFFLINE_MINUTES * 60);
    return array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name'], 'last_seen' => $d['last_seen']],
        q('SELECT id, name, last_seen FROM devices WHERE last_seen IS NOT NULL AND last_seen < ? ORDER BY name', [$limit])->fetchAll());
}

/** Pošle zařízení příkaz (zařízení si ho vyzvedne při dalším dotazu, nejpozději do DEVICE_PING_SECONDS). */
function device_command(int $id, string $cmd): array {
    $d = device_get($id);
    if (!isset(DEVICE_CMDS[$cmd])) throw new InvalidArgumentException('Neznámý příkaz.');
    if (empty($d['status_at'])) throw new InvalidArgumentException('Zařízení má starou verzi přehrávače – ovládání nefunguje. Nainstalujte novou verzi.');
    q('UPDATE devices SET cmd = ?, cmd_id = cmd_id + 1, cmd_at = ? WHERE id = ?', [$cmd, date('Y-m-d H:i:s'), $id]);
    app_log('info', 'Zařízení „' . $d['name'] . '“: ' . DEVICE_CMDS[$cmd] . '.');
    return device_out(device_get($id));
}

/** Očistí stav, který zařízení posílá: {playing:{name,kind,age,paused}, files, done, version, error:{msg,age}}. */
function device_status_clean($in): array {
    $in = is_array($in) ? $in : [];
    $str = fn($v, int $n) => is_scalar($v) ? mb_substr(trim(preg_replace('/[\x00-\x1f]+/u', ' ', (string)$v)), 0, $n) : '';
    $age = fn($v) => max(0, min(86400 * 30, (int)(is_numeric($v) ? $v : 0)));
    $now = time();
    $out = [
        'files' => max(0, min(100000, (int)($in['files'] ?? 0))),
        'done' => max(0, min(100000, (int)($in['done'] ?? 0))),
        'version' => $str($in['version'] ?? '', 20),
        'playing' => null, 'error' => null,
    ];
    $p = $in['playing'] ?? null;
    if (is_array($p) && $str($p['name'] ?? '', 200) !== '') {
        $out['playing'] = ['name' => $str($p['name'], 200), 'kind' => $str($p['kind'] ?? '', 10),
            'since' => date('Y-m-d H:i:s', $now - $age($p['age'] ?? 0)), 'paused' => !empty($p['paused'])];
    }
    $e = $in['error'] ?? null;
    if (is_array($e) && $str($e['msg'] ?? '', 300) !== '') {
        $out['error'] = ['msg' => $str($e['msg'], 300), 'at' => date('Y-m-d H:i:s', $now - $age($e['age'] ?? 0))];
    }
    return $out;
}

/**
 * Dotaz zařízení každých DEVICE_PING_SECONDS: uloží stav, vydá čekající příkaz (jen jednou)
 * a číslo poslední změny složky přehrávače (zařízení si při změně samo obnoví seznam).
 */
function device_ping(array $device, $status): array {
    $st = device_status_clean($status);
    $cmd = null;
    $live = !empty($device['cmd']) && !empty($device['cmd_at']) && time() - strtotime($device['cmd_at']) < DEVICE_CMD_TTL;
    if ($live) $cmd = ['id' => (int)$device['cmd_id'], 'name' => $device['cmd']];
    q('UPDATE devices SET status = ?, status_at = ?, last_seen = ?' . (!empty($device['cmd']) ? ', cmd = NULL, cmd_at = NULL' : '') . ' WHERE id = ?',
        [json_encode($st, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$device['id']]);
    return ['ok' => true, 'cmd' => $cmd, 'change' => device_change_id(), 'ping_seconds' => DEVICE_PING_SECONDS];
}

/** Číslo poslední změny obsahu složky přehrávače (čas poslední synchronizace, která něco nahrála nebo odstranila). */
function device_change_id(): int { return (int)setting('last_change_at', 0); }

function devices_list(): array {
    return array_map('device_out', q('SELECT * FROM devices ORDER BY id')->fetchAll());
}

/** Vytvoří zařízení; vrátí [zařízení, klíč]. */
function device_create(string $name, string $kind, ?int $duration): array {
    $name = mb_substr(trim($name), 0, 100);
    if ($name === '') throw new InvalidArgumentException('Zadejte název zařízení.');
    if (!isset(DEVICE_KINDS[$kind])) $kind = 'chrome';
    $token = device_token_new();
    q('INSERT INTO devices (name, kind, token_hash, duration, created_at) VALUES (?, ?, ?, ?, ?)',
        [$name, $kind, device_hash($token), $duration, date('Y-m-d H:i:s')]);
    $id = (int)db()->lastInsertId();
    app_log('info', "Přidáno zařízení „{$name}“.");
    return [device_out(q('SELECT * FROM devices WHERE id = ?', [$id])->fetch()), $token];
}

function device_get(int $id): array {
    $d = q('SELECT * FROM devices WHERE id = ?', [$id])->fetch();
    if (!$d) throw new InvalidArgumentException('Zařízení nenalezeno.');
    return $d;
}

function device_update(int $id, string $name, string $kind, ?int $duration): array {
    $d = device_get($id);
    $name = mb_substr(trim($name), 0, 100);
    if ($name === '') throw new InvalidArgumentException('Zadejte název zařízení.');
    if (!isset(DEVICE_KINDS[$kind])) $kind = $d['kind'];
    q('UPDATE devices SET name = ?, kind = ?, duration = ? WHERE id = ?', [$name, $kind, $duration, $id]);
    return device_out(device_get($id));
}

/** Nový klíč: starý přestane fungovat. */
function device_new_token(int $id): string {
    $d = device_get($id);
    $token = device_token_new();
    q('UPDATE devices SET token_hash = ? WHERE id = ?', [device_hash($token), $id]);
    app_log('info', "Zařízení „{$d['name']}“ má nový klíč – starý přestal fungovat.");
    return $token;
}

function device_delete(int $id): void {
    $d = device_get($id);
    q('DELETE FROM devices WHERE id = ?', [$id]);
    q('DELETE FROM device_pairing WHERE device_id = ?', [$id]);
    app_log('info', "Odebráno zařízení „{$d['name']}“.");
}

// ---------- Ochrana proti hádání klíče (zvlášť od přihlašování) ----------
function device_client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'); }

function device_fails(): array {
    $all = json_decode((string)setting('device_fails', '{}'), true) ?: [];
    $now = time();
    return array_filter($all, fn($f) => is_array($f) && $now - (int)($f[1] ?? 0) < DEVICE_FAIL_WINDOW);
}
function device_blocked(string $ip): bool {
    $f = device_fails()[$ip] ?? null;
    return $f && (int)$f[0] >= DEVICE_FAIL_LIMIT;
}
function device_fail(string $ip): void {
    $all = device_fails();
    $n = (int)($all[$ip][0] ?? 0) + 1;
    $all[$ip] = [$n, time()];
    set_setting('device_fails', json_encode($all));
    if ($n === DEVICE_FAIL_LIMIT) app_log('warning', "Zařízení: $n špatných klíčů z adresy $ip – adresa je na 15 minut zablokovaná.");
}

/** Najde zařízení podle klíče (nebo null). Klíč je v hlavičce X-Device-Key, případně v parametru key. */
function device_auth(?string $token): ?array {
    $token = trim((string)$token);
    if ($token === '' || strlen($token) > 100) return null;
    return q('SELECT * FROM devices WHERE token_hash = ?', [device_hash($token)])->fetch() ?: null;
}

// ---------- Obsah složky přehrávače ----------
function device_file_kind(string $name, ?string $mime = null): array {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (isset(DEVICE_EXT[$ext])) return DEVICE_EXT[$ext];
    $mime = (string)$mime;
    if (str_starts_with($mime, 'image/')) return ['image', $mime];
    if (str_starts_with($mime, 'video/')) return ['video', $mime];
    if ($mime === 'application/pdf') return ['pdf', $mime];
    return ['other', $mime ?: 'application/octet-stream'];
}

/**
 * Soubory ve složce na Google Disku: [ {id, name, kind, mime, md5, size} ].
 * Seznam se drží DEVICE_LIST_TTL sekund (víc zařízení najednou = jeden dotaz na Disk).
 * Když Disk neodpoví, vrátí poslední známý seznam s příznakem stale.
 * @return array{files: array, at: int, stale: bool, error: ?string}
 */
function device_folder_files(bool $force = false): array {
    $folderId = (string)setting('folder_id', '');
    $cache = json_decode((string)setting('device_files_cache', ''), true);
    if (!is_array($cache) || ($cache['folder'] ?? null) !== $folderId) $cache = null;
    // po synchronizaci, která změnila obsah složky, se seznam načte z Disku znovu (zařízení se hned obnoví)
    if (!$force && $cache && time() - (int)$cache['at'] < DEVICE_LIST_TTL && (int)$cache['at'] >= device_change_id()) {
        return ['files' => $cache['files'], 'at' => (int)$cache['at'], 'stale' => false, 'error' => null];
    }
    if ($folderId === '') return ['files' => [], 'at' => time(), 'stale' => false, 'error' => 'Není nastavena cílová složka na Google Disku.'];
    try {
        $files = [];
        foreach (drive_list($folderId, true) as $f) {
            [$kind, $mime] = device_file_kind($f['name'], $f['mime'] ?? null);
            if ($kind === 'other') continue;
            $files[] = ['id' => $f['id'], 'name' => $f['name'], 'kind' => $kind, 'mime' => $mime,
                'md5' => $f['md5'] ?? null, 'size' => isset($f['size']) ? (int)$f['size'] : null];
        }
        usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
        set_setting('device_files_cache', json_encode(['folder' => $folderId, 'at' => time(), 'files' => $files], JSON_UNESCAPED_UNICODE));
        return ['files' => $files, 'at' => time(), 'stale' => false, 'error' => null];
    } catch (Throwable $e) {
        if ($cache) return ['files' => $cache['files'], 'at' => (int)$cache['at'], 'stale' => true, 'error' => $e->getMessage()];
        throw $e;
    }
}

/** Co teď běží (jen názvy, pro zobrazení v zařízení). */
function device_playlist(): array {
    try {
        $r = resolve_at(new DateTimeImmutable());
        return ['mode' => $r['mode'], 'active' => array_values($r['active']),
            'fixed' => array_values($r['fixed'] ?? [])];
    } catch (Throwable $e) {
        return ['mode' => 'unknown', 'active' => [], 'fixed' => []];
    }
}

/** Odpověď now.php pro zařízení. */
/** Verze přehrávače z hlavičky X-Client („tizen/1.2.0“), nebo null. */
function device_client_version(?string $client): ?string {
    return $client !== null && preg_match('~/(\d+\.\d+\.\d+)~', $client, $m) ? $m[1] : null;
}
/** HTML dokumenty umí přehrávač od verze 1.2.0; starším se v seznamu nenabízejí (přehrávají dál ostatní soubory). */
const DEVICE_HTML_SINCE = '1.2.0';

function device_now(array $device, ?string $client = null): array {
    $list = device_folder_files();
    $v = device_client_version($client);
    if ($v === null || version_compare($v, DEVICE_HTML_SINCE, '<')) $list['files'] = array_values(array_filter($list['files'], fn($f) => $f['kind'] !== 'html'));
    return [
        'ok' => true, 'api' => 1, 'app' => APP_NAME, 'version' => APP_VERSION,
        'server_time' => date(DATE_ATOM),
        'device' => ['id' => (int)$device['id'], 'name' => $device['name'], 'kind' => $device['kind'],
            'duration' => $device['duration'] !== null ? (int)$device['duration'] : device_default_duration()],
        'refresh_minutes' => DEVICE_REFRESH_MINUTES,
        'ping_seconds' => DEVICE_PING_SECONDS,
        'change' => device_change_id(),
        'playlist' => device_playlist(),
        'files' => $list['files'],
        'list_at' => date(DATE_ATOM, $list['at']),
        'stale' => $list['stale'],
        'warning' => $list['stale'] ? 'Google Disk teď neodpovídá, seznam je z ' . date('j. n. G:i', $list['at']) . '.' : $list['error'],
    ];
}

/** Zapíše, že se zařízení ozvalo (a verzi pluginu z hlavičky X-Client). */
function device_seen(array $device, ?string $client): void {
    $client = $client !== null ? mb_substr(preg_replace('/[^\w .\-\/()]/u', '', $client), 0, 100) : null;
    q('UPDATE devices SET last_seen = ?, client = COALESCE(?, client) WHERE id = ?', [date('Y-m-d H:i:s'), $client ?: null, (int)$device['id']]);
}

/** Najde soubor podle ID v aktuálním seznamu složky (jiné soubory z Disku se nevydají). */
function device_find_file(string $id): ?array {
    foreach (device_folder_files()['files'] as $f) if ($f['id'] === $id) return $f;
    // soubor mohl přibýt během poslední minuty
    foreach (device_folder_files(true)['files'] as $f) if ($f['id'] === $id) return $f;
    return null;
}

// ---------- Plugin ke stažení ----------
function extension_dir(string $kind): string { return APP_DIR . '/extensions/' . $kind; }

function extension_version(string $kind): ?string {
    $dir = extension_dir($kind);
    if (is_file("$dir/manifest.json")) return json_decode((string)file_get_contents("$dir/manifest.json"), true)['version'] ?? null;
    // aplikace pro Android: versionName '1.0.0' v app/build.gradle
    if (is_file("$dir/app/build.gradle") && preg_match("/versionName\\s+'([\\d.]+)'/", (string)file_get_contents("$dir/app/build.gradle"), $m)) return $m[1];
    // aplikace pro Tizen: <widget … version="1.0.0">
    if (is_file("$dir/config.xml") && preg_match('/<widget\b[^>]*\sversion="([\d.]+)"/', (string)file_get_contents("$dir/config.xml"), $m)) return $m[1];
    return null;
}

/** Obsah souboru config.js aplikace pro TV: předvyplněná adresa této aplikace. */
function tizen_config_js(): string {
    return "// Vytvořeno při stažení z TWB Plánovače médií. Adresa aplikace, ke které se televize páruje.\n"
        . 'window.TWB_CONFIG = ' . json_encode(['base' => rtrim((string)cfg('base_url'), '/')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ";\n";
}

/** Zabalí plugin do zipu v data/tmp; vrátí [cesta, název souboru]. */
function extension_zip(string $kind): array {
    $dir = extension_dir($kind);
    $ver = extension_version($kind);
    if (!$ver) throw new RuntimeException('Balíček chybí na serveru (složka extensions/' . $kind . ').');
    if (!class_exists('ZipArchive')) throw new RuntimeException('Na serveru chybí rozšíření PHP zip.');
    $root = $kind === 'android' ? 'twb-prehravac-android' : 'twb-planovac-medii-' . $kind;
    $path = DATA_DIR . '/tmp/' . $root . '-' . bin2hex(random_bytes(4)) . '.zip';
    @mkdir(DATA_DIR . '/tmp', 0775, true);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Zip se nepodařilo vytvořit.');
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    $files = [];
    foreach ($it as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
        if ($rel === '.htaccess') continue;
        $files[$rel] = $f->getPathname();
    }
    if ($kind === 'android') {
        // přehrávač je jen jeden (extensions/tizen) – do aplikace pro Android se vloží při stažení
        unset($files['app/src/main/assets/www/README.txt']);
        $tz = extension_dir('tizen');
        $it2 = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tz, FilesystemIterator::SKIP_DOTS));
        foreach ($it2 as $f) {
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($tz) + 1));
            if (in_array($rel, ['config.xml', '.project', '.tproject', '.htaccess'], true)) continue;
            $files['app/src/main/assets/www/' . $rel] = $f->getPathname();
        }
    }
    ksort($files);
    foreach ($files as $rel => $abs) {
        if (($kind === 'tizen' && $rel === 'config.js') || ($kind === 'android' && $rel === 'app/src/main/assets/www/config.js')) $zip->addFromString("$root/$rel", tizen_config_js());
        else $zip->addFile($abs, "$root/$rel");
    }
    $zip->close();
    return [$path, "$root-$ver.zip"];
}

// ---------- Párování televize kódem ----------
const PAIR_TTL = 600;            // s, platnost kódu
const PAIR_POLL_SECONDS = 3;     // jak často se televize ptá
const PAIR_START_LIMIT = 10;     // žádostí o kód z jedné adresy za hodinu
const PAIR_CODE_FAILS = 5;       // špatných kódů v aplikaci → blokace
const PAIR_CODE_LOCK = 900;      // s

function pair_cleanup(): void {
    q('DELETE FROM device_pairing WHERE expires < ?', [time()]);
}

/** Nový párovací kód pro televizi. Vrátí [kód, token pro dotazování]. */
function pair_start(string $ip, string $kind = 'tizen'): array {
    if (!in_array($kind, ['tizen', 'android'], true)) $kind = 'tizen';
    pair_cleanup();
    $starts = json_decode((string)setting('pair_starts', '{}'), true) ?: [];
    $starts = array_filter($starts, fn($x) => is_array($x) && time() - (int)$x[1] < 3600);
    $n = (int)($starts[$ip][0] ?? 0);
    if ($n >= PAIR_START_LIMIT) throw new RuntimeException('Příliš mnoho žádostí o párovací kód. Zkuste to za hodinu.', 429);
    $starts[$ip] = [$n + 1, $starts[$ip][1] ?? time()];
    set_setting('pair_starts', json_encode($starts));
    for ($i = 0; $i < 20; $i++) {
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if (!q('SELECT 1 FROM device_pairing WHERE code_hash = ?', [device_hash($code)])->fetchColumn()) break;
    }
    $poll = bin2hex(random_bytes(24));
    q('INSERT INTO device_pairing (code_hash, poll_hash, created_at, expires, ip, kind) VALUES (?, ?, ?, ?, ?, ?)',
        [device_hash($code), device_hash($poll), time(), time() + PAIR_TTL, mb_substr($ip, 0, 64), $kind]);
    return [$code, $poll];
}

/**
 * Televize se ptá, jestli už je spárovaná.
 * @return array{status: string, key?: string, device?: array}  status: waiting | paired | expired
 */
function pair_poll(string $poll): array {
    $r = q('SELECT * FROM device_pairing WHERE poll_hash = ?', [device_hash($poll)])->fetch();
    if (!$r || (int)$r['expires'] < time()) {
        if ($r) q('DELETE FROM device_pairing WHERE id = ?', [$r['id']]);
        return ['status' => 'expired'];
    }
    if (!$r['device_id'] || !$r['token']) return ['status' => 'waiting', 'expires_in' => (int)$r['expires'] - time()];
    // klíč se vydá jen jednou
    q('DELETE FROM device_pairing WHERE id = ?', [$r['id']]);
    $d = q('SELECT * FROM devices WHERE id = ?', [$r['device_id']])->fetch();
    if (!$d) return ['status' => 'expired'];
    return ['status' => 'paired', 'key' => $r['token'], 'device' => ['id' => (int)$d['id'], 'name' => $d['name']]];
}

/** Uživatel v aplikaci zadal kód z televize: vznikne zařízení typu TV Samsung nebo Android (podle toho, kdo o kód požádal). */
function pair_confirm(string $code, string $name, ?int $duration): array {
    $lock = json_decode((string)setting('pair_fails', '[]'), true) ?: [0, 0];
    if ((int)$lock[0] >= PAIR_CODE_FAILS && time() - (int)$lock[1] < PAIR_CODE_LOCK) {
        throw new InvalidArgumentException('Příliš mnoho špatných kódů. Zkuste to znovu za 15 minut.');
    }
    if (time() - (int)$lock[1] >= PAIR_CODE_LOCK) $lock = [0, 0];
    $code = preg_replace('/\D/', '', $code);
    pair_cleanup();
    $r = strlen($code) === 6 ? q('SELECT * FROM device_pairing WHERE code_hash = ? AND device_id IS NULL', [device_hash($code)])->fetch() : null;
    if (!$r) {
        set_setting('pair_fails', json_encode([(int)$lock[0] + 1, time()]));
        if ((int)$lock[0] + 1 === PAIR_CODE_FAILS) app_log('warning', 'Párování televize: ' . PAIR_CODE_FAILS . ' špatných kódů – párování je na 15 minut zablokované.');
        throw new InvalidArgumentException('Kód neplatí. Zkontrolujte kód na televizi (platí 10 minut, pak se ukáže nový).');
    }
    [$dev, $token] = device_create($name, in_array($r['kind'] ?? '', ['tizen', 'android'], true) ? $r['kind'] : 'tizen', $duration);
    q('UPDATE device_pairing SET device_id = ?, token = ? WHERE id = ?', [$dev['id'], $token, $r['id']]);
    set_setting('pair_fails', null);
    app_log('info', "Televize „{$dev['name']}“ (" . (DEVICE_KINDS[$dev['kind']] ?? $dev['kind']) . ') spárována.');
    return $dev;
}
