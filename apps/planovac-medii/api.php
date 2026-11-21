<?php
// JSON API pro webové rozhraní.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/slide.php';
require_once __DIR__ . '/lib/tags.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/image.php';
require_once __DIR__ . '/lib/backup.php';
require_once __DIR__ . '/lib/diag.php';
require_once __DIR__ . '/lib/update.php';
require_once __DIR__ . '/lib/help.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/devices.php';
require_once __DIR__ . '/lib/fonts.php';
if (installed()) migrate();

header('Content-Type: application/json; charset=utf-8');

// Jakákoli chyba PHP se vrátí jako JSON, aby ji aplikace mohla zobrazit (místo prázdné stránky).
ini_set('display_errors', '0');
set_exception_handler(function (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    app_log('error', 'Chyba serveru: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['error' => 'Chyba serveru: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['error' => 'Chyba serveru: ' . $e['message']], JSON_UNESCAPED_UNICODE);
    }
});

function out($data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400) { out(['error' => $msg], $code); }

if (!is_logged_in()) fail('Nejste přihlášeni.', 401);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), $_SERVER['HTTP_X_CSRF'] ?? '')) fail('Neplatný požadavek.', 403);
session_write_close();

$a = $_GET['a'] ?? '';
$in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

const ALLOWED_EXT = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
    'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
    'pdf' => 'application/pdf',
];
const KIND_EXT = ['text' => 'txt', 'url' => 'url'];

function kind_of_mime(string $mime): string {
    if (str_starts_with($mime, 'video/')) return 'video';
    if ($mime === 'application/pdf') return 'pdf';
    return 'image';
}

/** Název souboru: zbaví lomítek a vynutí příponu. */
function clean_name(string $name, string $ext): string {
    $name = trim(str_replace(['/', '\\', "\0"], '_', $name));
    $base = preg_replace('/\.' . preg_quote($ext, '/') . '$/i', '', $name);
    if ($base === '') fail('Zadejte název.');
    return mb_substr($base, 0, 200) . '.' . $ext;
}

/** Zapíše vygenerovaný soubor (text, odkaz) do galerie a vrátí [filename, size, md5]. */
function write_generated(string $ext, string $content, ?string $oldFile): array {
    $file = bin2hex(random_bytes(12)) . '.' . $ext;
    file_put_contents(MEDIA_DIR . "/$file", $content);
    if ($oldFile) @unlink(MEDIA_DIR . '/' . $oldFile);
    return [$file, strlen($content), md5($content)];
}

function check_url(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_RANGE => '0-2047',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 12) TWB-PlanovacMedii/' . APP_VERSION]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    if ($ok === false) return "Stránku se nepodařilo načíst ($err).";
    if ($code >= 400) return "Stránka vrátila chybu HTTP $code.";
    return null;
}

function media_out(array $m): array {
    $m['id'] = (int)$m['id'];
    $m['size'] = (int)$m['size'];
    $m['compressed'] = !empty($m['orig_filename']) ? [
        'orig_size' => (int)$m['orig_size'], 'orig_w' => (int)$m['orig_w'], 'orig_h' => (int)$m['orig_h'],
        'orig_ext' => strtolower(pathinfo($m['orig_filename'], PATHINFO_EXTENSION)),
        'w' => (int)$m['width'], 'h' => (int)$m['height'],
    ] : null;
    $m['width'] = isset($m['width']) ? (int)$m['width'] : null;
    $m['height'] = isset($m['height']) ? (int)$m['height'] : null;
    unset($m['orig_filename'], $m['orig_mime'], $m['orig_size'], $m['orig_w'], $m['orig_h'], $m['upload_name']);
    unset($m['folder_id']); // složky galerie od 1.18.0 nahradily tagy
    $m['url'] = in_array($m['kind'], ['random', 'dated'], true) ? null : 'file.php?id=' . $m['id'] . '&v=' . substr($m['md5'], 0, 8);
    $m['used'] = (int)q('SELECT COUNT(*) FROM scenario_media WHERE media_id = ?', [$m['id']])->fetchColumn()
        + (int)q('SELECT COUNT(*) FROM pool_members WHERE media_id = ?', [$m['id']])->fetchColumn()
        + (int)q('SELECT COUNT(DISTINCT pool_id) FROM dated_items WHERE media_id = ?', [$m['id']])->fetchColumn();
    $m['tags'] = media_tags($m);
    $m['archived'] = !empty($m['archived_at']);
    unset($m['archive_folder_id']);
    if ($m['archived']) $m['used_in'] = media_used_in((int)$m['id']);
    if ($m['kind'] === 'dated') {
        // položky výběru: [{id, days: [RRRR-MM-DD…]}] seřazené podle prvního data
        $items = [];
        foreach (q('SELECT media_id, day FROM dated_items WHERE pool_id = ? ORDER BY day, media_id', [$m['id']])->fetchAll() as $r) {
            $items[(int)$r['media_id']][] = substr((string)$r['day'], 0, 10);
        }
        $m['items'] = array_map(fn($id, $days) => ['id' => $id, 'days' => $days], array_keys($items), array_values($items));
        $m['members'] = array_keys($items);
    }
    if ($m['kind'] === 'slide') {
        $c = json_decode((string)$m['content'], true) ?: [];
        $m['design'] = $c['design'] ?? null;
        $m['content'] = null;
    }
    if ($m['kind'] === 'html') {
        $m['content'] = null; // zdroj (až 5 MB) se načítá zvlášť přes html_get
    }
    if ($m['kind'] === 'random') {
        $m['members'] = array_map('intval', q('SELECT media_id FROM pool_members WHERE pool_id = ? ORDER BY media_id', [$m['id']])->fetchAll(PDO::FETCH_COLUMN));
        $cur = q('SELECT current_id FROM random_state WHERE pool_id = ?', [$m['id']])->fetchColumn();
        $m['current'] = $cur ? (int)$cur : null;
    }
    unset($m['filename']);
    return $m;
}
/** Kde se médium používá (pro archiv): scénáře, výběry a šablony slidů. */
function media_used_in(int $id): array {
    $sc = q('SELECT s.name FROM scenario_media sm JOIN scenarios s ON s.id = sm.scenario_id WHERE sm.media_id = ? ORDER BY s.name', [$id])->fetchAll(PDO::FETCH_COLUMN);
    $pools = array_merge(
        q('SELECT m.original_name FROM pool_members pm JOIN media m ON m.id = pm.pool_id WHERE pm.media_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN),
        q('SELECT DISTINCT m.original_name FROM dated_items di JOIN media m ON m.id = di.pool_id WHERE di.media_id = ?', [$id])->fetchAll(PDO::FETCH_COLUMN));
    $slides = [];
    foreach (q("SELECT original_name, content FROM media WHERE kind = 'slide'")->fetchAll() as $r) {
        $c = json_decode((string)$r['content'], true);
        if (!empty($c['design']) && in_array($id, design_media_ids(sanitize_design($c['design'])), true)) $slides[] = $r['original_name'];
    }
    return ['scenarios' => array_values($sc), 'pools' => array_values(array_unique($pools)), 'slides' => $slides];
}
/** Uloží HTML dokument (nový nebo existující). $name null = ponechat. Vrací id. */
function html_create_or_update(int $id, ?string $name, string $src, ?string $grp = null): int {
    $old = $id ? media_by_id($id) : null;
    if ($old && $old['kind'] !== 'html') fail('Soubor musí být stejného druhu: HTML dokument nahraďte opět HTML dokumentem.');
    $name = $name !== null ? clean_name(preg_replace('/\.html?$/i', '', $name), 'html') : $old['original_name'];
    if ($old) {
        q('UPDATE media SET original_name = ?' . ($grp !== null ? ', grp = ?' : '') . ' WHERE id = ?',
            array_merge([$name], $grp !== null ? [$grp ?: null] : [], [$id]));
        app_log('info', "HTML dokument „{$name}“ uložen.");
    } else {
        q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, content, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            ['', $name, 'text/html', 'html', 0, '', $grp ?: null, json_encode(['source' => ''], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]);
        $id = (int)db()->lastInsertId();
        app_log('info', "Do galerie přidán HTML dokument „{$name}“.");
    }
    html_store($id, $src, new DateTimeImmutable());
    return $id;
}
/** Tagy pro nově nahraný soubor (pole tags v POST jako JSON, podle filtru tagů v galerii). Chybné se tiše vynechají. */
function upload_tags(int $id): void {
    $t = json_decode((string)($_POST['tags'] ?? ''), true);
    if (!is_array($t) || !$t) return;
    try { media_set_tags($id, tags_clean($t)); } catch (InvalidArgumentException $e) { /* nahrání kvůli tagům neselže */ }
}
/** Tagy z požadavku ($in['tags']), pokud byly poslané: vyčistit a uložit. Chyba → 400. */
function tags_from_input(int $id, array $in): void {
    if (!array_key_exists('tags', $in)) return;
    try { media_set_tags($id, tags_clean($in['tags'])); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
}
function html_hosts_note(array $hosts): string {
    return 'Dokument načítá soubory z internetu (' . implode(', ', array_slice($hosts, 0, 5)) . (count($hosts) > 5 ? ' a další' : '')
        . '). Bez připojení k internetu se na obrazovce nemusí zobrazit celý.';
}
function media_by_id(int $id): array {
    return q('SELECT * FROM media WHERE id = ?', [$id])->fetch() ?: fail('Médium nenalezeno.', 404);
}

function valid_date(?string $d): ?string {
    if ($d === null || $d === '') return null;
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) fail("Neplatné datum: $d");
    return $d;
}

/** Popis sestavy pro UI */
function resolution_out(array $r): array {
    return [
        'mode' => $r['mode'],
        'active' => $r['active'],
        'fixed' => $r['fixed'] ?? [],
        'fixed_off' => $r['fixed_off'] ?? [],
        'ignored' => $r['ignored'],
        'idle' => $r['idle'] ?? [],
        'media' => array_values(array_map(fn($x) => ['id' => $x['media']['id'], 'scenario' => $x['scenario'],
            'pool' => $x['pool_id'] ?? null], $r['media'])),
        'pending' => $r['pending_pools'] ?? [],
        'suppressed' => array_map(fn($x) => ['id' => $x['media']['id'], 'scenario' => $x['scenario'], 'by' => $x['by'], 'group' => $x['group']], $r['suppressed']),
    ];
}
/** Očistí řádek dat; číselnou hodnotu uloží bez mezer (125 000 000 → 125000000). */
function data_clean_row($g, $p, $v): array {
    $g = mb_substr(trim((string)$g), 0, 100);
    $p = mb_substr(trim((string)$p), 0, 200);
    $v = trim((string)$v);
    if ($g === '') throw new InvalidArgumentException('chybí skupina');
    if ($p === '') throw new InvalidArgumentException('chybí parametr');
    if (preg_match('/^-?[\d\s\x{00A0}]+([.,]\d+)?$/u', $v) && ($n = to_number($v)) !== null) {
        $v = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }
    return [$g, $p, mb_substr($v, 0, 2000)];
}

/** Po změně obrázku: přegenerovat slidy, které ho používají (podle md5 v klíči vykreslení). */
function data_changed_slides(): array {
    try { return regenerate_slides(new DateTimeImmutable()); } catch (Throwable $e) { return []; }
}

/** Po změně dat: přegenerovat dotčené slidy (na Disk půjdou při příštím běhu cronu). */
function data_changed(): array {
    data_rows(true);
    try { return regenerate_slides(new DateTimeImmutable()); } catch (Throwable $e) { return []; }
}

function sig(array $r): string { return implode(',', array_keys($r['media'])) . '|' . $r['mode']; }

switch ($a) {
    // ---------- Přehled ----------
    case 'status': {
        $now = new DateTimeImmutable();
        $scen = load_scenarios();
        $mm = load_media_map();
        $cur = resolve_at($now, $scen, $mm);
        // nejbližší změna sestavy (prohledá 14 dní po hodinách)
        $next = null;
        $t = $now->setTime((int)$now->format('G'), 0);
        for ($i = 1; $i <= 24 * 14; $i++) {
            $tt = $t->modify("+$i hour");
            $r = resolve_at($tt, $scen, $mm);
            if (sig($r) !== sig($cur)) { $next = ['at' => $tt->format(DATE_ATOM), 'resolution' => resolution_out($r)]; break; }
        }
        out([
            'now' => $now->format(DATE_ATOM),
            'drive' => drive_status(),
            'folder' => ['id' => setting('folder_id', ''), 'name' => setting('folder_name', '')],
            'current' => resolution_out(expand_pools($cur, $now, false, $mm)),
            'next' => $next,
            'last_run_at' => setting('last_run_at') ? date(DATE_ATOM, (int)setting('last_run_at')) : null,
            'last_verified_at' => setting('last_verified_at') ? date(DATE_ATOM, (int)setting('last_verified_at')) : null,
            'last_error' => setting('last_error'),
            'has_default' => (bool)array_filter($scen, fn($s) => $s['is_default']),
            'player_poll_at' => setting('player_poll_at') ? date(DATE_ATOM, (int)setting('player_poll_at')) : null,
            'update_available' => update_status()['available']['version'] ?? null,
        ]);
    }

    case 'preview': {
        // 7 dní × 24 hodin od zadaného pondělí
        $start = new DateTimeImmutable(valid_date($_GET['from'] ?? '') ?? 'monday this week');
        $scen = load_scenarios();
        $mm = load_media_map();
        $sets = []; $index = []; $grid = [];
        for ($d = 0; $d < 7; $d++) {
            $day = $start->modify("+$d day");
            $row = [];
            for ($h = 0; $h < 24; $h++) {
                $r = resolve_at($day->setTime($h, 0), $scen, $mm);
                $k = sig($r);
                if (!isset($index[$k])) { $index[$k] = count($sets); $sets[] = resolution_out($r); }
                $row[] = $index[$k];
            }
            $grid[] = ['date' => $day->format('Y-m-d'), 'hours' => $row];
        }
        out(['days' => $grid, 'sets' => $sets]);
    }

    case 'sync':
        @set_time_limit(300);
        out(run_sync(true));

    // ---------- Nápověda ----------
    case 'help': {
        $id = (string)($_GET['page'] ?? '');
        if ($id === '') out(help_bundle());
        out(help_page($id) ?? fail('Stránka nápovědy nenalezena.', 404));
    }

    // ---------- Galerie ----------
    case 'media':
        out(array_map('media_out', q('SELECT * FROM media ORDER BY id DESC')->fetchAll()));

    case 'upload_chunk': {
        $uid = preg_replace('/[^a-f0-9]/', '', $_POST['upload_id'] ?? '');
        $idx = (int)($_POST['index'] ?? -1);
        $total = (int)($_POST['total'] ?? 0);
        $name = trim(str_replace(['/', '\\'], '_', (string)($_POST['name'] ?? '')));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (strlen($uid) < 16 || $idx < 0 || $total < 1 || $idx >= $total || $name === '') fail('Neplatný požadavek.');
        $isHtml = in_array($ext, ['html', 'htm'], true);
        if (!isset(ALLOWED_EXT[$ext]) && !$isHtml) fail("Nepodporovaný typ souboru .$ext – povoleny jsou obrázky, videa, PDF a HTML dokumenty.");
        if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) fail('Část souboru se nepodařilo přijmout (kód ' . ($_FILES['chunk']['error'] ?? '?') . ').');
        $part = TMP_DIR . "/$uid.part";
        if ($idx === 0) @unlink($part);
        file_put_contents($part, file_get_contents($_FILES['chunk']['tmp_name']), FILE_APPEND);
        if ($idx < $total - 1) out(['ok' => true]);

        // poslední část → uložit do galerie
        if ($isHtml) {
            // HTML dokument: uloží se zdroj, přehrávač dostane dokument s dosazenými proměnnými a písmy
            try { $src = html_check((string)file_get_contents($part)); } catch (InvalidArgumentException $e) { @unlink($part); fail("$name: " . $e->getMessage()); }
            @unlink($part);
            $replaceId = (int)($_POST['replace_id'] ?? 0);
            $id = $replaceId ? html_create_or_update($replaceId, null, $src) : html_create_or_update(0, $name, $src);
            if (!$replaceId) upload_tags($id);
            $hosts = html_external_hosts($src);
            out(media_out(media_by_id($id)) + ['note' => $hosts ? html_hosts_note($hosts) : null]);
        }
        $mime = ALLOWED_EXT[$ext];
        if (function_exists('finfo_open')) {
            $det = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $part) ?: '';
            $okType = $ext === 'pdf' ? $det === 'application/pdf'
                : (str_starts_with($det, 'image/') || str_starts_with($det, 'video/') || $det === 'application/octet-stream');
            if (!$okType) {
                @unlink($part);
                fail($ext === 'pdf' ? "Soubor $name není platné PDF." : "Soubor $name není obrázek ani video.");
            }
            if (str_starts_with($det, 'image/') || str_starts_with($det, 'video/')) $mime = $det;
        }
        $kind = kind_of_mime($mime);
        $replaceId = (int)($_POST['replace_id'] ?? 0);
        $old = null;
        if ($replaceId) {
            // „Nahradit soubor“: stejná položka galerie (scénáře, výběry i slidy zůstanou), jen nový soubor
            $old = q('SELECT * FROM media WHERE id = ?', [$replaceId])->fetch();
            if (!$old) { @unlink($part); fail('Médium nenalezeno.', 404); }
            if ($old['kind'] !== $kind) {
                @unlink($part);
                fail('Soubor musí být stejného druhu: ' . ['image' => 'obrázek', 'video' => 'video', 'pdf' => 'PDF'][$old['kind']] . ' nahraďte opět ' . ['image' => 'obrázkem', 'video' => 'videem', 'pdf' => 'PDF souborem'][$old['kind']] . '.');
            }
        }
        $file = bin2hex(random_bytes(12)) . '.' . $ext;
        rename($part, MEDIA_DIR . "/$file");
        $dim = $kind === 'image' ? (@getimagesize(MEDIA_DIR . "/$file") ?: [null, null]) : [null, null];
        $row = [$file, $mime, $kind, filesize(MEDIA_DIR . "/$file"), md5_file(MEDIA_DIR . "/$file"), $dim[0], $dim[1], $name];
        if ($old) {
            // název: když ho uživatel nepřejmenoval, převezme se z nového souboru; jinak zůstane jeho jméno s novou příponou
            $renamed = $old['upload_name'] === null || pathinfo($old['original_name'], PATHINFO_FILENAME) !== pathinfo($old['upload_name'], PATHINFO_FILENAME);
            $newName = $renamed ? name_with_ext($old['original_name'], $ext) : $name;
            media_unlink_files($old);
            q('UPDATE media SET filename = ?, mime = ?, kind = ?, size = ?, md5 = ?, width = ?, height = ?, upload_name = ?, original_name = ?,
               orig_filename = NULL, orig_mime = NULL, orig_size = NULL, orig_w = NULL, orig_h = NULL WHERE id = ?', [...$row, $newName, $old['id']]);
            $id = (int)$old['id'];
            app_log('info', "Soubor nahrazen: {$old['original_name']} → $newName");
        } else {
            q('INSERT INTO media (filename, mime, kind, size, md5, width, height, upload_name, original_name, grp, folder_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
              [...$row, $name, null, null, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
            upload_tags($id);
            app_log('info', "Do galerie nahráno: $name");
        }
        $note = null;
        if ($kind === 'image' && img_settings()['auto']) {
            try {
                if ($msg = media_compress(q('SELECT * FROM media WHERE id = ?', [$id])->fetch(), false)) { $note = $msg; app_log('info', "Obrázek zkomprimován: $msg"); }
            } catch (Throwable $e) {
                app_log('warning', "Obrázek $name se nepodařilo zkomprimovat: " . $e->getMessage());
                $note = 'Komprese se nepodařila: ' . $e->getMessage();
            }
        }
        if ($old) data_changed_slides();
        out(media_out(q('SELECT * FROM media WHERE id = ?', [$id])->fetch()) + ['note' => $note]);
    }

    case 'media_update': {
        $m = q('SELECT * FROM media WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Médium nenalezeno.', 404);
        if (array_key_exists('original_name', $in)) {
            $n = trim((string)$in['original_name']);
            if ($n === '' || preg_match('~[/\\\\]~', $n)) fail('Neplatný název.');
            $ext = pathinfo($m['original_name'], PATHINFO_EXTENSION);
            if (!in_array($m['kind'], ['random', 'dated'], true) && strtolower(pathinfo($n, PATHINFO_EXTENSION)) !== strtolower($ext)) $n .= '.' . $ext;
            q('UPDATE media SET original_name = ? WHERE id = ?', [$n, $m['id']]);
        }
        if (array_key_exists('grp', $in)) {
            $g = trim((string)$in['grp']);
            q('UPDATE media SET grp = ? WHERE id = ?', [$g === '' ? null : mb_substr($g, 0, 64), $m['id']]);
        }
        tags_from_input((int)$m['id'], $in);
        out(media_out(q('SELECT * FROM media WHERE id = ?', [$m['id']])->fetch()));
    }

    case 'media_delete': {
        $ids = isset($in['ids']) ? array_map('intval', (array)$in['ids']) : [(int)($in['id'] ?? 0)];
        $names = [];
        foreach ($ids as $id) {
            $m = q('SELECT * FROM media WHERE id = ?', [$id])->fetch();
            if (!$m) continue;
            q('DELETE FROM scenario_media WHERE media_id = ?', [$id]);
            q('DELETE FROM pool_members WHERE media_id = ? OR pool_id = ?', [$id, $id]);
            q('DELETE FROM dated_items WHERE media_id = ? OR pool_id = ?', [$id, $id]);
            q('DELETE FROM random_state WHERE pool_id = ?', [$id]);
            q('DELETE FROM media WHERE id = ?', [$id]);
            media_unlink_files($m);
            $names[] = $m['original_name'];
        }
        if ($names) app_log('info', 'Z galerie smazáno: ' . implode(', ', $names));
        out(['ok' => true, 'deleted' => count($names)]);
    }

    case 'media_compress': {
        // ruční „Zmenšit a komprimovat“ (i hromadně); vždy z originálu podle aktuálního nastavení
        @set_time_limit(300);
        $ids = array_map('intval', (array)($in['ids'] ?? []));
        $done = []; $skipped = 0; $errors = [];
        foreach ($ids as $id) {
            $m = q('SELECT * FROM media WHERE id = ?', [$id])->fetch();
            if (!$m || $m['kind'] !== 'image') { $skipped++; continue; }
            try {
                $msg = media_compress($m, true);
                $msg ? $done[] = $msg : $skipped++;
            } catch (Throwable $e) { $errors[] = "{$m['original_name']}: " . $e->getMessage(); }
        }
        if ($done) app_log('info', 'Obrázky zkomprimovány: ' . implode('; ', $done));
        if ($errors) app_log('warning', 'Komprese se nepodařila: ' . implode('; ', $errors));
        out(['done' => count($done), 'skipped' => $skipped, 'errors' => $errors, 'details' => $done, 'slides' => $done ? data_changed_slides() : []]);
    }

    case 'media_restore': {
        $m = q('SELECT * FROM media WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Médium nenalezeno.', 404);
        if (!media_restore($m)) fail('Původní soubor už není k dispozici.');
        app_log('info', "Obrázek vrácen na původní: {$m['original_name']}");
        $slides = data_changed_slides();
        out(media_out(q('SELECT * FROM media WHERE id = ?', [$m['id']])->fetch()) + ['slides' => $slides]);
    }

    case 'media_archive': {
        // do archivu / zpět; médium zůstává ve scénářích, výběrech i slidech, jen se nenabízí
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $ids = array_map('intval', (array)($in['ids'] ?? []));
        $arch = !empty($in['archive']);
        $names = [];
        foreach ($ids as $id) {
            $m = q('SELECT * FROM media WHERE id = ?', [$id])->fetch();
            if (!$m || ($arch === !empty($m['archived_at']))) continue;
            q('UPDATE media SET archived_at = ? WHERE id = ?', [$arch ? date('Y-m-d H:i:s') : null, $id]);
            $names[] = $m['original_name'];
        }
        if ($names) app_log('info', ($arch ? 'Do archivu přesunuto: ' : 'Z archivu vráceno: ') . implode(', ', $names));
        out(['ok' => true, 'changed' => count($names)]);
    }

    // ---------- Tagy ----------
    case 'tags':
        out(array_values(tags_all()));

    case 'media_tags': {
        // hromadně: {ids, add: [..], remove: [..]}
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { $n = tags_bulk(array_map('intval', (array)($in['ids'] ?? [])), (array)($in['add'] ?? []), (array)($in['remove'] ?? [])); }
        catch (InvalidArgumentException $e) { fail($e->getMessage()); }
        if ($n) app_log('info', 'Tagy upraveny u ' . $n . ' médií' . (($in['add'] ?? []) ? ' (přidáno: ' . implode(', ', (array)$in['add']) . ')' : '') . (($in['remove'] ?? []) ? ' (odebráno: ' . implode(', ', (array)$in['remove']) . ')' : '') . '.');
        out(['ok' => true, 'changed' => $n]);
    }

    case 'tag_rename': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { $n = tag_rename((string)($in['from'] ?? ''), (string)($in['to'] ?? '')); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
        app_log('info', "Tag „{$in['from']}“ přejmenován na „{$in['to']}“ ($n médií).");
        out(['ok' => true, 'changed' => $n]);
    }

    case 'tag_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $n = tag_delete((string)($in['name'] ?? ''));
        app_log('info', "Tag „{$in['name']}“ smazán ($n médií).");
        out(['ok' => true, 'changed' => $n]);
    }

    // ---------- Text, odkaz, náhodný výběr ----------
    case 'text_save':
    case 'url_save': {
        $kind = $a === 'text_save' ? 'text' : 'url';
        $ext = KIND_EXT[$kind];
        $id = (int)($in['id'] ?? 0);
        $old = $id ? media_by_id($id) : null;
        if ($old && $old['kind'] !== $kind) fail('Nesprávný typ média.');
        $name = clean_name((string)($in['name'] ?? ''), $ext);
        $warning = null;
        if ($kind === 'text') {
            $content = str_replace(["\r\n", "\r"], "\n", trim((string)($in['content'] ?? '')));
            if ($content === '') fail('Zadejte text.');
            if (!mb_check_encoding($content, 'UTF-8')) fail('Text není v kódování UTF-8.');
            $stored = $content;
            $fileContent = $content . "\n";
            $mime = 'text/plain';
        } else {
            $url = trim((string)($in['content'] ?? ''));
            if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
            if (!filter_var($url, FILTER_VALIDATE_URL)) fail('Neplatná adresa stránky.');
            $warning = check_url($url);
            $stored = $url;
            $fileContent = "[InternetShortcut]\r\nURL=$url\r\n";
            $mime = 'text/plain';
        }
        $folder = null; // složky galerie zrušeny (1.18.0)
        [$file, $size, $md5] = write_generated($ext, $fileContent, $old['filename'] ?? null);
        if ($old) {
            q('UPDATE media SET filename=?, original_name=?, size=?, md5=?, content=?, folder_id=? WHERE id=?', [$file, $name, $size, $md5, $stored, $folder, $id]);
        } else {
            q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, folder_id, content, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
              [$file, $name, $mime, $kind, $size, $md5, null, $folder, $stored, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        tags_from_input($id, $in);
        app_log('info', ($kind === 'text' ? 'Text' : 'Odkaz') . " „{$name}“ uložen.");
        out(['media' => media_out(media_by_id($id)), 'warning' => $warning]);
    }

    // ---------- HTML dokumenty ----------
    case 'html_get': {
        $m = media_by_id((int)($_GET['id'] ?? $in['id'] ?? 0));
        if ($m['kind'] !== 'html') fail('Nesprávný typ média.');
        out(['source' => html_source($m), 'media' => media_out($m)]);
    }

    case 'html_new':
        out(['source' => html_default()]);

    case 'html_save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { $src = html_check((string)($in['source'] ?? '')); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
        $id = (int)($in['id'] ?? 0);
        $grp = array_key_exists('grp', $in) ? mb_substr(trim((string)$in['grp']), 0, 64) : null;
        if (array_key_exists('tags', $in)) { try { tags_clean($in['tags']); } catch (InvalidArgumentException $e) { fail($e->getMessage()); } }
        $id = html_create_or_update($id, (string)($in['name'] ?? ''), $src, $grp);
        tags_from_input($id, $in);
        [, $unknown] = html_resolve($src, new DateTimeImmutable());
        $hosts = html_external_hosts($src);
        out(['media' => media_out(media_by_id($id)), 'warning' => $hosts ? html_hosts_note($hosts) : null, 'unknown' => $unknown]);
    }

    case 'html_preview': {
        // náhled před uložením: proměnné dosazené dnešními hodnotami, písma vložená do dokumentu
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $src = (string)($in['source'] ?? '');
        if (strlen($src) > HTML_MAX_BYTES) fail('Dokument je větší než 5 MB.');
        [$html, $unknown] = html_build($src, new DateTimeImmutable());
        out(['html' => $html, 'unknown' => $unknown, 'hosts' => html_external_hosts($src)]);
    }

    // ---------- Slidy (editor) ----------
    case 'fonts':
        out(array_map('font_out', array_keys(fonts_all())));

    case 'fx_styles':
        // hotové grafické styly textu (editor slidu)
        out(['styles' => FX_STYLES, 'stops' => FX_STOPS, 'ranges' => FX_RANGES]);

    case 'font': {
        // soubor vlastního písma (data/fonts je z internetu zablokovaná)
        $f = fonts_all()[(string)($_GET['key'] ?? '')] ?? null;
        if (!$f || !$f['custom']) fail('Písmo nenalezeno.', 404);
        $path = CUSTOM_FONT_DIR . '/' . $f['file'];
        header('Content-Type: ' . (str_ends_with($f['file'], '.otf') ? 'font/otf' : 'font/ttf'));
        header('Cache-Control: private, max-age=604800');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    case 'fonts_custom': {
        // Nastavení → Obrázky → Písma pro slidy
        $list = [];
        foreach (fonts_all() as $k => $f) $list[] = font_out($k) + ['used' => $f['custom'] ? count(font_usage($k)) : null];
        out(['fonts' => $list, 'max_mb' => FONT_MAX_BYTES / 1048576]);
    }

    case 'font_upload': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $u = $_FILES['file'] ?? null;
        if (!$u || ($u['error'] ?? 1) !== UPLOAD_ERR_OK) fail(($u['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Písmo je příliš velké.' : 'Soubor se nepodařilo nahrát.');
        try { [$font, $missing] = font_upload($u['tmp_name'], (string)$u['name'], (string)($_POST['name'] ?? '')); }
        catch (InvalidArgumentException $e) { fail($e->getMessage()); }
        out(['font' => $font, 'missing' => $missing]);
    }

    case 'font_rename': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { out(['font' => font_rename((string)($in['key'] ?? ''), (string)($in['name'] ?? ''))]); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    }

    case 'font_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { font_delete((string)($in['key'] ?? '')); out(['ok' => true]); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    }

    case 'datefmt': {
        // pro dialogy „Formát data“ a „Formát čísla“: jak bude vypadat proměnná v jednotlivých formátech
        $expr = trim((string)($in['expr'] ?? ''));
        $fmts = array_slice(array_map('strval', (array)($in['formats'] ?? [])), 0, 40);
        if ($expr === '' || mb_strlen($expr) > 300 || preg_match('/[{}|]/', $expr)) fail('Neplatná proměnná.');
        $t = new DateTimeImmutable();
        $res = [];
        foreach ($fmts as $f) {
            if (mb_strlen($f) > 150 || preg_match('/[{}]/', $f)) { $res[] = null; continue; }
            [$txt, $unk] = resolve_text('{' . $expr . $f . '}', $t);
            $res[] = $unk ? null : $txt;
        }
        out($res);
    }
    case 'resolve': {
        // pro editor: dosazené proměnné + přesné rozvržení textů tak, jak je vykreslí server
        $t = new DateTimeImmutable();
        $design = sanitize_design($in['design'] ?? []);
        $res = [];
        $ev = design_eval($design, $t);
        foreach ($design['elements'] as $e) {
            $r = $ev[$e['id']];
            if ($e['type'] === 'text') {
                $L = layout_text($e, $r['text']);
                unset($L['font']);
                $r['layout'] = $L;
            }
            if ($e['type'] === 'weather') $r = weather_editor_info($e, $t) + $r;
            $res[$e['id']] = $r;
        }
        out($res);
    }

    // ---------- Počasí ----------
    case 'weather_icon': {
        // ikona počasí pro editor (data/ je z internetu zablokovaná)
        $f = weather_icon_file((string)($_GET['code'] ?? ''));
        if (!$f) fail('Ikona nenalezena.', 404);
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=604800');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
    case 'weather': {
        $rows = [];
        try { $rows = q('SELECT place, provider, fetched_at, ok_at, error FROM weather ORDER BY place')->fetchAll(); } catch (Throwable $e) { /* před převodem */ }
        $used = array_map('weather_place_key', weather_places_used());
        out([
            'provider' => weather_provider(),
            'providers' => array_map(fn($k, $p) => ['key' => $k, 'name' => $p['name'], 'key_url' => $p['key_url']], array_keys(WEATHER_PROVIDERS), WEATHER_PROVIDERS),
            'key_set' => weather_configured(), 'place' => weather_default_place(), 'interval' => WEATHER_INTERVAL_MIN,
            'places' => array_map(fn($r) => $r + ['used' => in_array(weather_place_key($r['place']), $used, true)], $rows),
        ]);
    }
    case 'weather_save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $prov = (string)($in['provider'] ?? weather_provider());
        if (!isset(WEATHER_PROVIDERS[$prov])) fail('Neznámá služba počasí.');
        $key = trim((string)($in['api_key'] ?? ''));
        if ($key !== '' && !preg_match('/^[\x21-\x7e]{8,200}$/', $key)) fail('API klíč má nesprávný tvar.');
        $place = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in['place'] ?? weather_default_place()))), 0, 100);
        $changed = $prov !== weather_provider() || ($key !== '' && $key !== weather_api_key()) || !empty($in['clear_key']);
        set_setting('weather_provider', $prov);
        if ($key !== '') set_setting('weather_api_key', $key);
        if (!empty($in['clear_key'])) set_setting('weather_api_key', '');
        set_setting('weather_place', $place);
        // jiný klíč nebo služba: všechna místa stáhnout znovu (i ta s chybou „neplatný klíč“)
        if ($changed) q('UPDATE weather SET fetched_at = NULL');
        if ($changed || $place !== '') app_log('info', 'Počasí: nastavení uloženo (' . WEATHER_PROVIDERS[$prov]['name'] . ($place !== '' ? ", výchozí místo $place" : '') . ').');
        out(['ok' => true]);
    }
    case 'weather_test': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        if (!weather_configured()) fail('Nejdřív uložte API klíč.');
        $place = weather_place((string)($in['place'] ?? ''));
        if ($place === '') fail('Zadejte místo.');
        $r = weather_fetch($place);
        if (!$r['ok']) fail($r['error']);
        $t = new DateTimeImmutable();
        $d = $r['data'];
        $days = [];
        for ($i = 0; $i < WEATHER_DAYS; $i++) if ($x = weather_day($d, $i, $t)) $days[] = ['label' => weather_day_label($x['date'], $t), 'max' => weather_int($x['max']), 'min' => weather_int($x['min']), 'icon' => $x['icon'], 'desc' => weather_ucfirst($x['desc'])];
        regenerate_slides($t);
        out(['place' => $place, 'name' => $d['name'] ?? '', 'temp' => weather_int($d['now']['temp']), 'feels' => weather_int($d['now']['feels']),
             'desc' => weather_ucfirst($d['now']['desc']), 'icon' => $d['now']['icon'], 'humidity' => $d['now']['humidity'], 'wind' => weather_int($d['now']['wind']), 'days' => $days]);
    }

    case 'slide_preview': {
        @set_time_limit(60);
        $jpg = render_slide(sanitize_design($in['design'] ?? []), new DateTimeImmutable(), 85);
        out(['image' => 'data:image/jpeg;base64,' . base64_encode($jpg)]);
    }

    case 'slide_save': {
        @set_time_limit(60);
        $id = (int)($in['id'] ?? 0);
        $old = $id ? media_by_id($id) : null;
        if ($old && $old['kind'] !== 'slide') fail('Nesprávný typ média.');
        $name = clean_name((string)($in['name'] ?? ''), 'jpg');
        $design = sanitize_design($in['design'] ?? []);
        $folder = null; // složky galerie zrušeny (1.18.0)
        // skupina (média ve stejné skupině se nahrazují); bez parametru zůstává původní
        $grp = array_key_exists('grp', $in) ? (trim((string)$in['grp']) === '' ? null : mb_substr(trim((string)$in['grp']), 0, 64)) : ($old['grp'] ?? null);
        if (array_key_exists('tags', $in)) { try { tags_clean($in['tags']); } catch (InvalidArgumentException $e) { fail($e->getMessage()); } }
        if (!$old) {
            q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, folder_id, content, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
              ['', $name, 'image/jpeg', 'slide', 0, '', $grp, $folder, json_encode(['design' => $design]), date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        } else {
            q('UPDATE media SET original_name = ?, folder_id = ?, grp = ? WHERE id = ?', [$name, $folder, $grp, $id]);
        }
        tags_from_input($id, $in);
        try {
            store_slide($id, $design, new DateTimeImmutable());
        } catch (Throwable $e) {
            if (!$old) q('DELETE FROM media WHERE id = ?', [$id]);
            fail('Slide se nepodařilo vykreslit: ' . $e->getMessage());
        }
        app_log('info', "Slide „{$name}“ uložen.");
        out(['media' => media_out(media_by_id($id))]);
    }

    // ---------- Data (Skupina | Parametr | Hodnota) ----------
    case 'data_list': {
        $today = (new DateTimeImmutable())->setTime(0, 0);
        $rows = array_map(function ($r) use ($today) {
            $d = parse_any_date($r['param']);
            return ['id' => (int)$r['id'], 'grp' => $r['grp'], 'param' => $r['param'], 'value' => $r['value'],
                    'date' => $d ? $d->format('Y-m-d') : null, 'past' => $d ? $d < $today : false];
        }, data_rows(true));
        out($rows);
    }

    case 'data_save': {
        $rows = isset($in['rows']) ? (array)$in['rows'] : [$in];
        $saved = 0;
        foreach ($rows as $r) {
            try { [$g, $p, $v] = data_clean_row($r['grp'] ?? '', $r['param'] ?? '', $r['value'] ?? ''); }
            catch (InvalidArgumentException $e) { fail('Řádek nelze uložit: ' . $e->getMessage() . '.'); }
            $id = (int)($r['id'] ?? 0);
            if ($id) q('UPDATE data_rows SET grp = ?, param = ?, value = ?, updated_at = ? WHERE id = ?', [$g, $p, $v, date('Y-m-d H:i:s'), $id]);
            else q('INSERT INTO data_rows (grp, param, value, created_at, updated_at) VALUES (?,?,?,?,?)', [$g, $p, $v, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
            $saved++;
        }
        out(['saved' => $saved, 'slides' => data_changed()]);
    }

    case 'data_import': {
        $lines = preg_split('/\r?\n/', (string)($in['text'] ?? ''));
        $rows = [];
        $errors = [];
        foreach ($lines as $i => $line) {
            if (trim($line) === '') continue;
            $sep = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : (str_contains($line, '|') ? '|' : null));
            $cols = $sep ? array_map('trim', explode($sep, $line)) : [];
            if (count($cols) < 3) { $errors[] = 'řádek ' . ($i + 1) . ': očekávám 3 sloupce (Skupina, Parametr, Hodnota)'; continue; }
            if ($i === 0 && norm_key($cols[0]) === 'skupina') continue; // záhlaví
            try { $rows[] = data_clean_row($cols[0], $cols[1], implode(' ', array_slice($cols, 2))); }
            catch (Throwable $e) { $errors[] = 'řádek ' . ($i + 1) . ': ' . $e->getMessage(); }
        }
        if (!$rows) fail($errors ? 'Nic k importu – ' . implode('; ', array_slice($errors, 0, 3)) : 'Vložte řádky z Excelu (Skupina, Parametr, Hodnota).');
        db()->beginTransaction();
        if (!empty($in['replace'])) {
            foreach (array_unique(array_map(fn($r) => norm_key($r[0]), $rows)) as $gk) {
                foreach (data_rows() as $old) if (norm_key($old['grp']) === $gk) q('DELETE FROM data_rows WHERE id = ?', [$old['id']]);
            }
        }
        foreach ($rows as [$g, $p, $v]) q('INSERT INTO data_rows (grp, param, value, created_at, updated_at) VALUES (?,?,?,?,?)', [$g, $p, $v, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        db()->commit();
        app_log('info', 'Data: importováno řádků ' . count($rows) . (!empty($in['replace']) ? ' (skupiny nahrazeny)' : '') . '.');
        out(['imported' => count($rows), 'errors' => $errors, 'slides' => data_changed()]);
    }

    case 'data_delete': {
        $ids = array_map('intval', (array)($in['ids'] ?? []));
        foreach ($ids as $id) q('DELETE FROM data_rows WHERE id = ?', [$id]);
        out(['deleted' => count($ids), 'slides' => data_changed()]);
    }

    case 'data_delete_past': {
        $today = (new DateTimeImmutable())->setTime(0, 0);
        $n = 0;
        foreach (data_rows(true) as $r) {
            $d = parse_any_date($r['param']);
            if ($d && $d < $today) { q('DELETE FROM data_rows WHERE id = ?', [$r['id']]); $n++; }
        }
        if ($n) app_log('info', "Data: smazáno prošlých řádků: $n.");
        out(['deleted' => $n, 'slides' => data_changed()]);
    }

    case 'data_preview': {
        // ukázka hodnot pro skupinu (pro nápovědu v sekci Data)
        $t = new DateTimeImmutable();
        $g = (string)($in['grp'] ?? '');
        out(['nearest' => data_value("$g.nejbližší", '', $t), 'nearestParam' => data_value("$g.nejbližší.parametr", '', $t),
             'last' => data_value("$g.poslední", '', $t)]);
    }

    // ---------- Vzdálené zdroje ----------
    case 'sources': {
        out(array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'url' => $s['url'], 'interval_min' => (int)$s['interval_min'],
            'format' => $s['format'] ?? 'json', 'csv_header' => (int)($s['csv_header'] ?? 1) === 1,
            'rows' => ($s['format'] ?? 'json') === 'csv' && is_array($s['json']) ? count($s['json']) : null,
            'fetched_at' => $s['fetched_at'], 'ok_at' => $s['ok_at'], 'error' => $s['error'], 'has_data' => $s['json'] !== null], sources_all(true)));
    }

    case 'source_save': {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $url = trim((string)($in['url'] ?? ''));
        $interval = max(5, min(1440, (int)($in['interval_min'] ?? 15)));
        $format = ($in['format'] ?? 'json') === 'csv' ? 'csv' : 'json';
        $csvHeader = !array_key_exists('csv_header', $in) || !empty($in['csv_header']) ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 60) fail('Zadejte název zdroje (nejvýš 60 znaků).');
        if (preg_match('/[{}|:.]/u', $name)) fail('Název zdroje nesmí obsahovat znaky { } | : a tečku.');
        if (in_array(norm_key($name), ['datum', 'den', 'mesic', 'rok', 'tyden', 'svatek', 'hodina', 'cislodne', 'odpocet', 'dnu'], true)) {
            fail('Tento název je vyhrazený pro proměnnou kalendáře.');
        }
        foreach (sources_all(true) as $s) if ((int)$s['id'] !== $id && norm_key($s['name']) === norm_key($name)) fail('Zdroj s tímto názvem už existuje.');
        if (!preg_match('~^https?://~i', $url)) fail('Adresa musí začínat http:// nebo https://.');
        if ($id) {
            $old = q('SELECT url, format, csv_header FROM sources WHERE id = ?', [$id])->fetch();
            q('UPDATE sources SET name = ?, url = ?, interval_min = ?, format = ?, csv_header = ? WHERE id = ?', [$name, $url, $interval, $format, $csvHeader, $id]);
            if (!$old || $old['url'] !== $url || $old['format'] !== $format || (int)$old['csv_header'] !== $csvHeader) {
                q('UPDATE sources SET fetched_at = NULL, hash = NULL WHERE id = ?', [$id]);
            }
        } else {
            q('INSERT INTO sources (name, url, interval_min, format, csv_header, created_at) VALUES (?,?,?,?,?,?)', [$name, $url, $interval, $format, $csvHeader, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        app_log('info', "Zdroj „{$name}“ uložen.");
        // hned stáhnout, ať je vidět, jestli funguje
        $s = null;
        foreach (sources_all(true) as $x) if ((int)$x['id'] === $id) $s = $x;
        $r = source_fetch($s);
        out(['id' => $id, 'ok' => $r['ok'], 'error' => $r['error'], 'slides' => $r['changed'] ? data_changed() : []]);
    }

    case 'source_delete': {
        q('DELETE FROM sources WHERE id = ?', [(int)($in['id'] ?? 0)]);
        sources_all(true);
        out(['ok' => true]);
    }

    case 'source_fetch': {
        $s = null;
        foreach (sources_all(true) as $x) if ((int)$x['id'] === (int)($in['id'] ?? 0)) $s = $x;
        if (!$s) fail('Zdroj nenalezen.', 404);
        $r = source_fetch($s);
        out(['ok' => $r['ok'], 'error' => $r['error'], 'changed' => $r['changed'], 'slides' => $r['changed'] ? data_changed() : []]);
    }

    case 'source_tree': {
        // hodnoty všech zdrojů pro výběr v editoru
        $res = [];
        foreach (sources_all(true) as $s) {
            $res[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'ok_at' => $s['ok_at'], 'error' => $s['error'],
                      'groups' => $s['json'] !== null ? source_tree($s) : []];
        }
        out($res);
    }

    case 'pool_save': {
        $id = (int)($in['id'] ?? 0);
        $old = $id ? media_by_id($id) : null;
        if ($old && $old['kind'] !== 'random') fail('Nesprávný typ média.');
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') fail('Zadejte název náhodného výběru.');
        $members = array_values(array_unique(array_map('intval', (array)($in['member_ids'] ?? []))));
        $valid = $members ? q('SELECT id FROM media WHERE kind IN (\'image\', \'video\', \'slide\', \'html\') AND id IN (' . implode(',', $members) . ')')->fetchAll(PDO::FETCH_COLUMN) : [];
        $valid = array_map('intval', $valid);
        if (count($valid) < 2) fail('Náhodný výběr musí obsahovat aspoň 2 obrázky, videa nebo slidy.');
        $folder = null; // složky galerie zrušeny (1.18.0)
        db()->beginTransaction();
        if ($old) q('UPDATE media SET original_name = ?, folder_id = ? WHERE id = ?', [mb_substr($name, 0, 200), $folder, $id]);
        else {
            q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, folder_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
              ['', mb_substr($name, 0, 200), '', 'random', 0, '', null, $folder, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        q('DELETE FROM pool_members WHERE pool_id = ?', [$id]);
        foreach ($valid as $mid) q('INSERT INTO pool_members (pool_id, media_id) VALUES (?, ?)', [$id, $mid]);
        db()->commit();
        tags_from_input($id, $in);
        app_log('info', "Náhodný výběr „{$name}“ uložen (" . count($valid) . ' médií).');
        out(['media' => media_out(media_by_id($id))]);
    }

    case 'dated_save': {
        // výběr podle data: položky {id, days[]}; každá položka se zobrazí jen ve své dny
        $id = (int)($in['id'] ?? 0);
        $old = $id ? media_by_id($id) : null;
        if ($old && $old['kind'] !== 'dated') fail('Nesprávný typ média.');
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') fail('Zadejte název výběru podle data.');
        $rows = [];
        foreach ((array)($in['items'] ?? []) as $it) {
            $mid = (int)($it['id'] ?? 0);
            foreach ((array)($it['days'] ?? []) as $d) {
                $d = (string)$d;
                $dt = DateTime::createFromFormat('!Y-m-d', $d);
                if (!$dt || $dt->format('Y-m-d') !== $d) fail("Neplatné datum: $d");
                if ((int)$dt->format('Y') < 2000 || (int)$dt->format('Y') > 2099) fail("Neplatné datum {$dt->format('j. n. Y')} – rok musí být 2000–2099.");
                $rows[$mid][$d] = true;
            }
        }
        $ids = array_keys($rows);
        $valid = $ids ? array_map('intval', q("SELECT id FROM media WHERE kind IN ('image', 'video', 'slide', 'pdf', 'html') AND id IN (" . implode(',', $ids) . ')')->fetchAll(PDO::FETCH_COLUMN)) : [];
        $rows = array_intersect_key($rows, array_flip($valid));
        if (!$rows) fail('Přidejte aspoň jednu položku s datem.');
        $folder = null; // složky galerie zrušeny (1.18.0)
        db()->beginTransaction();
        if ($old) q('UPDATE media SET original_name = ?, folder_id = ? WHERE id = ?', [mb_substr($name, 0, 200), $folder, $id]);
        else {
            q('INSERT INTO media (filename, original_name, mime, kind, size, md5, grp, folder_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
              ['', mb_substr($name, 0, 200), '', 'dated', 0, '', null, $folder, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        q('DELETE FROM dated_items WHERE pool_id = ?', [$id]);
        $n = 0;
        foreach ($rows as $mid => $days) foreach (array_keys($days) as $d) { q('INSERT INTO dated_items (pool_id, media_id, day) VALUES (?, ?, ?)', [$id, $mid, $d]); $n++; }
        db()->commit();
        tags_from_input($id, $in);
        app_log('info', "Výběr podle data „{$name}“ uložen (" . count($rows) . " médií, $n dní).");
        out(['media' => media_out(media_by_id($id))]);
    }

    // ---------- Scénáře ----------
    case 'scenarios':
        out(load_scenarios());

    case 'scenario_save': {
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') fail('Zadejte název scénáře.');
        $isDefault = !empty($in['is_default']) ? 1 : 0;
        $embed = !$isDefault && !empty($in['embed_only']) ? 1 : 0;
        $fixed = !$isDefault && !$embed && !empty($in['is_fixed']) ? 1 : 0;
        $priority = (int)($in['priority'] ?? 2);
        if ($priority < 1 || $priority > 3) $priority = 2;
        $weekdays = implode('', array_unique(array_filter(str_split((string)($in['weekdays'] ?? '1234567')), fn($c) => $c >= '1' && $c <= '7')));
        $wd = $weekdays === '' ? [] : str_split($weekdays);
        sort($wd);
        $weekdays = implode('', $wd);
        $hours = (string)($in['hours'] ?? str_repeat('1', 24));
        if (!preg_match('/^[01]{24}$/', $hours)) fail('Neplatná časová okna.');
        $from = valid_date($in['date_from'] ?? null);
        $to = valid_date($in['date_to'] ?? null);
        if ($from && $to && $from > $to) fail('Datum „od“ je po datu „do“.');
        $exc = array_values(array_unique(array_map(fn($d) => valid_date($d), array_filter((array)($in['exceptions'] ?? [])))));
        sort($exc);
        if ($embed || $fixed) { $from = $to = null; $weekdays = '1234567'; $hours = str_repeat('1', 24); $exc = []; }
        if (!$isDefault && !$embed && !$fixed) {
            if ($weekdays === '') fail('Vyberte aspoň jeden den v týdnu.');
            if (!str_contains($hours, '1')) fail('Vyberte aspoň jednu hodinu.');
        }
        $mediaIds = array_values(array_unique(array_map('intval', (array)($in['media_ids'] ?? []))));

        // vložené scénáře + kontrola, že nevznikne kruh (A vkládá B, B vkládá A)
        $includes = [];
        foreach ((array)($in['includes'] ?? []) as $inc) {
            $cid = (int)($inc['id'] ?? 0);
            if ($cid <= 0 || $cid === $id || isset($includes[$cid])) continue;
            $child = q('SELECT name FROM scenarios WHERE id = ?', [$cid])->fetch() ?: fail('Vložený scénář neexistuje.');
            $includes[$cid] = ['follow' => !empty($inc['follow']) ? 1 : 0, 'name' => $child['name']];
        }
        if ($id && $includes) {
            $edges = [];
            foreach (q('SELECT scenario_id, child_id FROM scenario_includes WHERE scenario_id <> ?', [$id])->fetchAll() as $e) {
                $edges[(int)$e['scenario_id']][] = (int)$e['child_id'];
            }
            foreach ($includes as $cid => $inc) {
                $stack = [$cid]; $seen = [];
                while ($stack) {
                    $n = array_pop($stack);
                    if ($n === $id) fail("Scénář „{$inc['name']}“ nelze vložit – už (i nepřímo) obsahuje tento scénář. Vznikl by kruh.");
                    if (isset($seen[$n])) continue;
                    $seen[$n] = true;
                    foreach ($edges[$n] ?? [] as $next) $stack[] = $next;
                }
            }
        }

        // pevné scénáře, které se v tomto scénáři nezobrazí (jen u plánovaných a výchozího)
        $fixedOff = [];
        if (!$fixed && !$embed) {
            $fixedIds = array_flip(array_map('intval', q('SELECT id FROM scenarios WHERE is_fixed = 1')->fetchAll(PDO::FETCH_COLUMN)));
            foreach ((array)($in['fixed_off'] ?? []) as $fid) if (isset($fixedIds[(int)$fid]) && (int)$fid !== $id) $fixedOff[(int)$fid] = true;
        }

        $row = [$name, (string)($in['note'] ?? ''), $isDefault, $priority, ($isDefault || $embed || $fixed) ? 0 : (!empty($in['exclusive']) ? 1 : 0),
                !empty($in['paused']) ? 1 : 0, $isDefault ? null : $from, $isDefault ? null : $to,
                $weekdays ?: '1234567', $hours, json_encode($exc), $embed, $fixed, date('Y-m-d H:i:s')];

        db()->beginTransaction();
        if ($isDefault) q('UPDATE scenarios SET is_default = 0 WHERE id <> ?', [$id]);
        if ($id) {
            q('SELECT id FROM scenarios WHERE id = ?', [$id])->fetch() ?: fail('Scénář nenalezen.', 404);
            q('UPDATE scenarios SET name=?, note=?, is_default=?, priority=?, exclusive=?, paused=?, date_from=?, date_to=?,
               weekdays=?, hours=?, exceptions=?, embed_only=?, is_fixed=?, updated_at=? WHERE id=?', [...$row, $id]);
        } else {
            q('INSERT INTO scenarios (name, note, is_default, priority, exclusive, paused, date_from, date_to, weekdays, hours,
               exceptions, embed_only, is_fixed, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$row, date('Y-m-d H:i:s')]);
            $id = (int)db()->lastInsertId();
        }
        q('DELETE FROM scenario_media WHERE scenario_id = ?', [$id]);
        $known = array_flip(array_map('intval', q('SELECT id FROM media')->fetchAll(PDO::FETCH_COLUMN)));
        foreach ($mediaIds as $mid) if (isset($known[$mid])) q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$id, $mid]);
        q('DELETE FROM scenario_includes WHERE scenario_id = ?', [$id]);
        foreach ($includes as $cid => $inc) q('INSERT INTO scenario_includes (scenario_id, child_id, follow_rules) VALUES (?, ?, ?)', [$id, $cid, $inc['follow']]);
        q('DELETE FROM scenario_fixed_off WHERE scenario_id = ?', [$id]);
        foreach (array_keys($fixedOff) as $fid) q('INSERT INTO scenario_fixed_off (scenario_id, fixed_id) VALUES (?, ?)', [$id, $fid]);
        // scénář přestal být pevný → zrušit jeho vypnutí v ostatních scénářích; pevný sám nic nevypíná
        if (!$fixed) q('DELETE FROM scenario_fixed_off WHERE fixed_id = ?', [$id]);
        else q('DELETE FROM scenario_fixed_off WHERE scenario_id = ?', [$id]);
        db()->commit();
        app_log('info', "Scénář „{$name}“ uložen.");
        out(['id' => $id]);
    }

    case 'scenario_add_media': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $s = q('SELECT id, name FROM scenarios WHERE id = ?', [(int)($in['scenario_id'] ?? 0)])->fetch() ?: fail('Scénář nenalezen.', 404);
        $m = q('SELECT id, original_name, archived_at FROM media WHERE id = ?', [(int)($in['media_id'] ?? 0)])->fetch() ?: fail('Médium nenalezeno.', 404);
        if (!empty($m['archived_at'])) fail('Médium je v archivu. Nejdřív ho vraťte z archivu.');
        if (q('SELECT 1 FROM scenario_media WHERE scenario_id = ? AND media_id = ?', [$s['id'], $m['id']])->fetchColumn()) {
            out(['ok' => true, 'added' => false]);
        }
        q('INSERT INTO scenario_media (scenario_id, media_id) VALUES (?, ?)', [$s['id'], $m['id']]);
        q('UPDATE scenarios SET updated_at = ? WHERE id = ?', [date('Y-m-d H:i:s'), $s['id']]);
        app_log('info', "„{$m['original_name']}“ přidáno do scénáře „{$s['name']}“.");
        out(['ok' => true, 'added' => true]);
    }

    case 'scenario_delete': {
        $s = q('SELECT * FROM scenarios WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Scénář nenalezen.', 404);
        q('DELETE FROM scenario_media WHERE scenario_id = ?', [$s['id']]);
        q('DELETE FROM scenario_includes WHERE scenario_id = ? OR child_id = ?', [$s['id'], $s['id']]);
        q('DELETE FROM scenario_fixed_off WHERE scenario_id = ? OR fixed_id = ?', [$s['id'], $s['id']]);
        q('DELETE FROM scenarios WHERE id = ?', [$s['id']]);
        app_log('info', "Scénář „{$s['name']}“ smazán.");
        out(['ok' => true]);
    }

    case 'scenario_toggle': {
        $s = q('SELECT * FROM scenarios WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch() ?: fail('Scénář nenalezen.', 404);
        $p = $s['paused'] ? 0 : 1;
        q('UPDATE scenarios SET paused = ?, updated_at = ? WHERE id = ?', [$p, date('Y-m-d H:i:s'), $s['id']]);
        app_log('info', "Scénář „{$s['name']}“ " . ($p ? 'pozastaven.' : 'znovu spuštěn.'));
        out(['ok' => true]);
    }

    // ---------- Aktualizace (TWB Update Server) ----------
    case 'update_status':
        out(update_status());

    case 'update_check':
        @set_time_limit(60);
        out(update_check());

    case 'update_install': {
        $r = update_install((string)($in['version'] ?? ''));
        out($r + ['status' => update_status()]);
    }

    case 'update_rollback': {
        $r = update_restore_previous();
        out(['ok' => true, 'version' => $r['version'], 'db_restored' => $r['db_restored']]);
    }

    // ---------- Diagnostika ----------
    case 'diag': {
        @set_time_limit(60);
        $items = diag_run();
        out(['items' => $items, 'at' => date(DATE_ATOM), 'version' => APP_VERSION]);
    }

    // ---------- Nastavení ----------
    case 'settings':
        if (!setting('player_key')) set_setting('player_key', bin2hex(random_bytes(16)));
        out([
            'img' => img_settings(),
            'app_name' => APP_NAME, 'version' => APP_VERSION, 'schema' => (int)setting('schema_version', 1), 'php' => PHP_VERSION,
            'changelog' => APP_CHANGELOG,
            'backup_nas_dir' => setting('backup_nas_dir', '/volume1/zaloha/twb-media-planner'), 'backup_nas_days' => (int)setting('backup_nas_days', 90),
            'player_host' => setting('player_host', ''), 'player_user' => setting('player_user', 'admin'),
            'player_target' => setting('player_target', '/'), 'player_clear' => setting('player_clear', '1') === '1',
            'signal_url' => rtrim(cfg('base_url'), '/') . '/signal.php?key=' . setting('player_key'),
            'player_poll_at' => setting('player_poll_at') ? date(DATE_ATOM, (int)setting('player_poll_at')) : null,
            'player_done' => (int)setting('player_done', 0),
            'last_change_at' => setting('last_change_at') ? date(DATE_ATOM, (int)setting('last_change_at')) : null,
            'last_change_ts' => (int)setting('last_change_at', 0),
            'folder_id' => setting('folder_id', ''), 'folder_name' => setting('folder_name', ''),
            'delete_mode' => setting('delete_mode', 'trash'),
            'notify_email' => setting('notify_email', ''), 'notify_phone' => setting('notify_phone', ''),
            'bulkgate_app_id' => setting('bulkgate_app_id', ''),
            'bulkgate_token_set' => (bool)setting('bulkgate_token'),
            'cron_url' => rtrim(cfg('base_url'), '/') . '/cron.php?key=' . cfg('cron_key'),
            'cron_cli' => 'php ' . APP_DIR . '/cron.php',
            'redirect_uri' => oauth_redirect_uri(),
            'drive' => drive_status(),
        ]);

    case 'settings_save': {
        if (array_key_exists('folder', $in)) {
            $fid = parse_folder_id((string)$in['folder']);
            if ($fid === '') { set_setting('folder_id', ''); set_setting('folder_name', ''); }
            elseif ($fid !== setting('folder_id')) {
                try { $f = drive_get_folder($fid); } catch (Throwable $e) { fail($e->getMessage()); }
                set_setting('folder_id', $f['id']);
                set_setting('folder_name', $f['name']);
                set_setting('last_sig', null);
                app_log('info', "Cílová složka nastavena: {$f['name']}");
            }
        }
        if (isset($in['delete_mode'])) set_setting('delete_mode', $in['delete_mode'] === 'permanent' ? 'permanent' : 'trash');
        if (array_key_exists('notify_email', $in)) {
            $e = trim((string)$in['notify_email']);
            if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('Neplatný e-mail.');
            set_setting('notify_email', $e);
        }
        if (array_key_exists('notify_phone', $in)) set_setting('notify_phone', trim((string)$in['notify_phone']));
        if (array_key_exists('bulkgate_app_id', $in)) set_setting('bulkgate_app_id', trim((string)$in['bulkgate_app_id']));
        if (!empty($in['bulkgate_token'])) set_setting('bulkgate_token', trim((string)$in['bulkgate_token']));
        if (isset($in['img']) && is_array($in['img'])) {
            $i = $in['img'];
            set_setting('img_auto', !empty($i['auto']) ? '1' : '0');
            foreach (['max_w' => [320, 7680], 'max_h' => [240, 4320], 'quality' => [30, 100], 'min_kb' => [0, 100000]] as $k => [$lo, $hi]) {
                if (isset($i[$k])) set_setting("img_$k", (string)min($hi, max($lo, (int)$i[$k])));
            }
        }
        if (array_key_exists('player_host', $in)) {
            // adresa přehrávače v místní síti, např. 192.168.99.249:8080
            $h = rtrim(trim((string)$in['player_host']), '/');
            if ($h !== '' && !preg_match('~^https?://~i', $h)) $h = 'http://' . $h;
            if ($h !== '' && !preg_match('~^https?://[A-Za-z0-9.\-]+(:\d{1,5})?$~', $h)) fail('Adresa přehrávače má tvar 192.168.1.10:8080.');
            set_setting('player_host', $h);
        }
        if (array_key_exists('player_user', $in)) set_setting('player_user', mb_substr(trim((string)$in['player_user']), 0, 100));
        if (array_key_exists('player_target', $in)) set_setting('player_target', trim((string)$in['player_target']) ?: '/');
        if (array_key_exists('player_clear', $in)) set_setting('player_clear', !empty($in['player_clear']) ? '1' : '0');
        out(['ok' => true]);
    }

    // ---------- Zálohy ----------
    case 'backups': {
        $nasFile = explode('|', (string)setting('backup_nas_file', ''));
        out(['supported' => backup_supported(), 'password_set' => (string)setting('backup_password', '') !== '',
             'auto' => setting('backup_auto', '1') === '1', 'hour' => BACKUP_HOUR, 'keep' => BACKUP_KEEP,
             'last_at' => setting('last_backup_at') ? date(DATE_ATOM, (int)setting('last_backup_at')) : null,
             'nas_at' => setting('backup_nas_at') ? date(DATE_ATOM, (int)setting('backup_nas_at')) : null,
             'nas_file' => $nasFile[0] ?: null, 'nas_file_at' => isset($nasFile[1]) ? date(DATE_ATOM, (int)$nasFile[1]) : null,
             'nas_dir' => setting('backup_nas_dir', '/volume1/zaloha/twb-media-planner'), 'nas_days' => (int)setting('backup_nas_days', 90),
             'list' => array_map(fn($b) => $b + ['date' => date(DATE_ATOM, $b['time'])], backup_list())]);
    }

    case 'backup_settings': {
        if (isset($in['password']) && $in['password'] !== '') {
            $p = (string)$in['password'];
            if (mb_strlen($p) < 8) fail('Heslo záloh musí mít aspoň 8 znaků.');
            if (str_contains($p, "'")) fail('Heslo záloh nesmí obsahovat apostrof.');
            set_setting('backup_password', $p);
            app_log('info', 'Heslo záloh změněno – nové zálohy se šifrují novým heslem, starší se otevírají původním.');
        }
        if (array_key_exists('auto', $in)) set_setting('backup_auto', !empty($in['auto']) ? '1' : '0');
        if (array_key_exists('nas_dir', $in)) {
            $d = rtrim(trim((string)$in['nas_dir']), '/');
            if (!preg_match('~^/[A-Za-z0-9_./ -]+$~', $d) || str_contains($d, '..')) fail('Složka na Synology má tvar /volume1/zaloha/twb-media-planner.');
            set_setting('backup_nas_dir', $d);
        }
        if (array_key_exists('nas_days', $in)) set_setting('backup_nas_days', (string)max(0, min(3650, (int)$in['nas_days'])));
        out(['ok' => true]);
    }

    case 'backup_create': {
        try { out(backup_create(!empty($in['files']) ? 'rucni' : '', !empty($in['files']))); }
        catch (Throwable $e) { app_log('error', 'Záloha selhala: ' . $e->getMessage()); fail($e->getMessage()); }
    }

    case 'backup_delete': {
        try { $p = backup_path((string)($in['name'] ?? '')); } catch (Throwable $e) { fail($e->getMessage(), 404); }
        unlink($p);
        app_log('info', 'Záloha smazána: ' . basename($p));
        out(['ok' => true]);
    }

    case 'backup_download': {
        try { $p = backup_path((string)($_GET['name'] ?? '')); } catch (Throwable $e) { http_response_code(404); exit('Záloha nenalezena.'); }
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($p));
        header('Content-Disposition: attachment; filename="' . basename($p) . '"');
        readfile($p);
        exit;
    }

    case 'backup_upload': {
        // nahrání ZIP po částech (stejně jako média) → uloží se mezi zálohy jako „…-nahrana“
        $uid = preg_replace('/[^a-f0-9]/', '', $_POST['upload_id'] ?? '');
        $idx = (int)($_POST['index'] ?? -1);
        $total = (int)($_POST['total'] ?? 0);
        if (strlen($uid) < 16 || $idx < 0 || $total < 1 || $idx >= $total) fail('Neplatný požadavek.');
        if (empty($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) fail('Část souboru se nepodařilo přijmout.');
        $part = TMP_DIR . "/$uid.backup.part";
        if ($idx === 0) @unlink($part);
        file_put_contents($part, file_get_contents($_FILES['chunk']['tmp_name']), FILE_APPEND);
        if ($idx < $total - 1) out(['ok' => true]);
        $zip = new ZipArchive();
        if ($zip->open($part) !== true || $zip->locateName('database.json') === false) { @unlink($part); fail('Soubor není záloha TWB Plánovače médií.'); }
        $zip->close();
        $name = BACKUP_PREFIX . date('Y-m-d-His') . '-nahrana' . (preg_match('/-soubory/', (string)($_POST['name'] ?? '')) ? '-soubory' : '') . '.zip';
        rename($part, backup_dir() . "/$name");
        app_log('info', "Nahrána záloha k obnovení: " . mb_substr((string)($_POST['name'] ?? ''), 0, 120));
        out(['name' => $name]);
    }

    case 'backup_info': {
        try { out(backup_info(backup_path((string)($in['name'] ?? '')), (string)($in['password'] ?? '') ?: (string)setting('backup_password', ''))); }
        catch (Throwable $e) { fail($e->getMessage()); }
    }

    case 'backup_restore': {
        try {
            $r = backup_restore(backup_path((string)($in['name'] ?? '')), (string)($in['password'] ?? '') ?: (string)setting('backup_password', ''));
            try { regenerate_slides(new DateTimeImmutable()); } catch (Throwable $e) { /* slidy se přegenerují při příštím cronu */ }
            out($r);
        } catch (Throwable $e) { app_log('error', 'Obnovení zálohy selhalo: ' . $e->getMessage()); fail($e->getMessage()); }
    }

    case 'player_key_new': {
        set_setting('player_key', bin2hex(random_bytes(16)));
        app_log('info', 'Vygenerován nový klíč pro skript na Synology – starý skript přestal fungovat.');
        out(['ok' => true]);
    }

    // ---------- Zařízení pro přehrávání (plugin pro Chrome…) ----------
    case 'devices':
        out([
            'devices' => devices_list(), 'kinds' => DEVICE_KINDS,
            'duration' => device_default_duration(), 'refresh_minutes' => DEVICE_REFRESH_MINUTES,
            'ping_seconds' => DEVICE_PING_SECONDS, 'offline_minutes' => DEVICE_OFFLINE_MINUTES, 'commands' => DEVICE_CMDS,
            'server_time' => date('Y-m-d H:i:s'),
            'base_url' => rtrim((string)cfg('base_url'), '/'),
            'chrome_version' => extension_version('chrome'),
            'tizen_version' => extension_version('tizen'),
            'android_version' => extension_version('android'),
        ]);

    case 'devices_alert':
        // upozornění v aplikaci: zařízení, která se déle neozývají (aplikace se ptá každou minutu)
        out(['offline' => devices_offline(), 'minutes' => DEVICE_OFFLINE_MINUTES]);

    case 'device_cmd': {
        // příkaz zařízení: obnovit seznam, restartovat přehrávač, přeskočit na další soubor
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { out(['device' => device_command((int)($in['id'] ?? 0), (string)($in['cmd'] ?? ''))]); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    }

    case 'device_pair': {
        // kód z obrazovky televize → nové zařízení typu TV Samsung (televize si klíč vyzvedne přes pair.php)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try {
            out(['device' => pair_confirm((string)($in['code'] ?? ''), (string)($in['name'] ?? ''), device_valid_duration($in['duration'] ?? null))]);
        } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    }

    case 'device_save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try {
            $dur = device_valid_duration($in['duration'] ?? null);
            $id = (int)($in['id'] ?? 0);
            if ($id) out(['device' => device_update($id, (string)($in['name'] ?? ''), (string)($in['kind'] ?? ''), $dur)]);
            [$d, $token] = device_create((string)($in['name'] ?? ''), (string)($in['kind'] ?? 'chrome'), $dur);
            out(['device' => $d, 'token' => $token]);
        } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
    }

    case 'device_token': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { out(['token' => device_new_token((int)($in['id'] ?? 0))]); } catch (InvalidArgumentException $e) { fail($e->getMessage(), 404); }
    }

    case 'device_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { device_delete((int)($in['id'] ?? 0)); out(['ok' => true]); } catch (InvalidArgumentException $e) { fail($e->getMessage(), 404); }
    }

    case 'device_settings': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        try { $d = device_valid_duration($in['duration'] ?? null); } catch (InvalidArgumentException $e) { fail($e->getMessage()); }
        if ($d === null) fail('Zadejte délku zobrazení.');
        set_setting('device_duration', (string)$d);
        app_log('info', "Výchozí délka zobrazení na zařízeních: $d s.");
        out(['ok' => true, 'duration' => $d]);
    }

    case 'extension_zip': {
        // plugin pro Chrome nebo projekt aplikace pro TV Samsung ke stažení (složka extensions/ je jinak zablokovaná)
        $kind = in_array($_GET['kind'] ?? '', ['tizen', 'android'], true) ? $_GET['kind'] : 'chrome';
        try { [$path, $name] = extension_zip($kind); } catch (Throwable $e) { fail($e->getMessage(), 500); }
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($path));
        header("Content-Disposition: attachment; filename=\"$name\"");
        readfile($path);
        @unlink($path);
        exit;
    }

    case 'notify_test': {
        $r = notify_send(APP_NAME . ' – test', 'Zkušební upozornění. Pokud ho čtete, upozornění fungují.');
        if (!$r) fail('Není vyplněn e-mail ani SMS (telefon + BulkGate).');
        app_log('info', 'Zkušební upozornění: ' . implode('; ', $r));
        out(['result' => $r]);
    }

    case 'google_disconnect':
        oauth_disconnect();
        app_log('info', 'Google Disk odpojen.');
        out(['ok' => true]);

    case 'session': {
        // zbývající čas do odhlášení pro nečinnost; s hlavičkou X-Passive: 1 aktivitu neobnovuje
        $limit = idle_limit_seconds();
        out(['limit' => $limit, 'remaining' => $limit > 0 ? max(0, $limit - (time() - (int)($_SESSION['auth_last'] ?? time()))) : null]);
    }

    case 'idle_save': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $m = $in['minutes'] ?? null;
        if (!is_numeric($m) || (int)$m != $m || ((int)$m !== 0 && ((int)$m < 5 || (int)$m > 1440))) fail('Zadejte 0 (nikdy) nebo 5 až 1440 minut.');
        set_setting('idle_minutes', (string)(int)$m);
        app_log('info', (int)$m === 0 ? 'Odhlášení po nečinnosti vypnuto.' : "Odhlášení po nečinnosti nastaveno na $m min.");
        out(['ok' => true, 'limit' => idle_limit_seconds()]);
    }

    case 'security': {
        $pk = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'created_at' => $r['created_at'], 'last_used' => $r['last_used']],
            q('SELECT id, name, created_at, last_used FROM passkeys ORDER BY id')->fetchAll());
        out([
            'passkeys' => $pk, 'passkey_available' => wa_available(), 'passkey_secure' => wa_secure_context(), 'rp_id' => wa_rp_id(),
            'google_configured' => auth_google_configured() || drive_mock(),
            'google_linked' => (string)setting('login_google_sub', '') !== '', 'google_email' => setting('login_google_email', ''),
            'google_redirect_uri' => auth_google_redirect_uri(),
            'reset_email_set' => trim((string)setting('notify_email', '')) !== '',
            'idle_minutes' => (int)setting('idle_minutes', 60),
        ]);
    }

    case 'passkey_options':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        if (!wa_available()) fail('Passkey vyžaduje zabezpečené spojení (HTTPS).');
        try { out(wa_register_options()); } catch (RuntimeException $e) { fail($e->getMessage(), 429); }

    case 'passkey_register': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        if (!wa_available()) fail('Passkey vyžaduje zabezpečené spojení (HTTPS).');
        $name = trim((string)($in['name'] ?? ''));
        try { $id = wa_register_verify(is_array($in['credential'] ?? null) ? $in['credential'] : [], $name); }
        catch (RuntimeException $e) { fail($e->getMessage()); }
        app_log('info', 'Přidán Passkey: ' . (trim($name) ?: 'Zařízení') . '.');
        out(['ok' => true, 'id' => $id]);
    }

    case 'passkey_rename': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 100));
        if ($name === '') fail('Zadejte název zařízení.');
        q('UPDATE passkeys SET name = ? WHERE id = ?', [$name, (int)($in['id'] ?? 0)]);
        out(['ok' => true]);
    }

    case 'passkey_delete': {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        $row = q('SELECT name FROM passkeys WHERE id = ?', [(int)($in['id'] ?? 0)])->fetch();
        if (!$row) fail('Zařízení neexistuje.', 404);
        q('DELETE FROM passkeys WHERE id = ?', [(int)$in['id']]);
        app_log('info', "Odebrán Passkey: {$row['name']}.");
        out(['ok' => true]);
    }

    case 'google_login_unlink':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Neplatný požadavek.', 405);
        set_setting('login_google_sub', null);
        set_setting('login_google_email', null);
        app_log('info', 'Google účet pro přihlášení odpojen.');
        out(['ok' => true]);

    case 'password': {
        if (!password_verify((string)($in['old'] ?? ''), (string)setting('password_hash'))) fail('Současné heslo nesouhlasí.');
        if (mb_strlen((string)($in['new'] ?? '')) < 8) fail('Nové heslo musí mít aspoň 8 znaků.');
        set_setting('password_hash', password_hash((string)$in['new'], PASSWORD_DEFAULT));
        app_log('info', 'Heslo bylo změněno.');
        out(['ok' => true]);
    }

    case 'logs':
        out(q('SELECT * FROM logs ORDER BY id DESC LIMIT 300')->fetchAll());

    default:
        fail('Neznámá akce.', 404);
}
