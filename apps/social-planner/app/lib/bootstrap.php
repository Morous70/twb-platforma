<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
require_once __DIR__ . '/version.php';
$cfgFile = ROOT . '/config.php';
if (!is_file($cfgFile)) {
    http_response_code(500);
    exit('Chybí config.php – zkopírujte config.example.php a vyplňte ho.');
}
$GLOBALS['CFG'] = require $cfgFile;
// Veřejná (webová) složka: public/ nebo www/ (např. Wedos); lze přepsat v config.php klíčem 'public_dir'
define('PUBLIC_DIR', $GLOBALS['CFG']['public_dir'] ?? (is_dir(ROOT . '/www') ? ROOT . '/www' : (is_dir(ROOT . '/public') ? ROOT . '/public' : dirname(ROOT))));
date_default_timezone_set($GLOBALS['CFG']['timezone'] ?? 'Europe/Prague');

function cfg(string $k, $default = null) { return $GLOBALS['CFG'][$k] ?? $default; }

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . ROOT . '/data/planner.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS accounts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        platform TEXT NOT NULL CHECK (platform IN ('facebook','instagram','threads')),
        remote_id TEXT NOT NULL,          -- ID stránky nebo IG uživatele
        name TEXT NOT NULL,
        page_id TEXT NOT NULL,            -- FB stránka (u IG ta, ke které je účet připojen)
        token TEXT NOT NULL,              -- token stránky
        created_at INTEGER NOT NULL,
        UNIQUE (platform, remote_id)
    );
    CREATE TABLE IF NOT EXISTS posts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message TEXT NOT NULL DEFAULT '',
        media_file TEXT,                  -- název souboru v public/uploads
        media_type TEXT,                  -- image | video | NULL
        scheduled_at INTEGER NOT NULL,    -- UTC unix timestamp
        status TEXT NOT NULL DEFAULT 'scheduled', -- scheduled | done | partial | failed
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS targets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
        status TEXT NOT NULL DEFAULT 'pending', -- pending | publishing | done | failed
        remote_post_id TEXT,
        error TEXT,
        attempts INTEGER NOT NULL DEFAULT 0,
        published_at INTEGER
    );
    CREATE TABLE IF NOT EXISTS post_media (   -- další obrázky příspěvku (první je v posts.media_file)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
        file TEXT NOT NULL,
        position INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS variables (    -- vlastní proměnné do textu příspěvků
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        type TEXT NOT NULL DEFAULT 'text',     -- text | list
        value TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);   -- nastavení a drobné stavy
    CREATE TABLE IF NOT EXISTS templates (    -- šablony příspěvků
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        message TEXT NOT NULL DEFAULT '',
        link TEXT,
        tags TEXT,
        account_ids TEXT NOT NULL DEFAULT ''   -- ID účtů oddělená čárkou
    );
    CREATE TABLE IF NOT EXISTS hashtag_sets (    -- uložené sady hashtagů
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        tags TEXT NOT NULL DEFAULT ''             -- štítky oddělené mezerou, bez #
    );
    CREATE TABLE IF NOT EXISTS library (          -- knihovna médií (soubory v public/uploads)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        file TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        size INTEGER NOT NULL DEFAULT 0,
        w INTEGER, h INTEGER,
        created_at INTEGER NOT NULL
    );
    CREATE TABLE IF NOT EXISTS library_albums (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE
    );
    CREATE TABLE IF NOT EXISTS library_album_items (
        album_id INTEGER NOT NULL REFERENCES library_albums(id) ON DELETE CASCADE,
        library_id INTEGER NOT NULL REFERENCES library(id) ON DELETE CASCADE,
        PRIMARY KEY (album_id, library_id)
    );
    CREATE TABLE IF NOT EXISTS auth_tokens (      -- jednorázové tokeny (obnova hesla, výzvy Passkey)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        purpose TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE,
        expires INTEGER NOT NULL,
        used INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS passkeys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        cred_hash TEXT NOT NULL UNIQUE,
        cred_id TEXT NOT NULL,
        public_key TEXT NOT NULL,
        alg INTEGER NOT NULL,
        sign_count INTEGER NOT NULL DEFAULT 0,
        created_at INTEGER NOT NULL,
        last_used INTEGER
    );
    CREATE TABLE IF NOT EXISTS account_groups (   -- skupiny účtů pro rychlý výběr
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        account_ids TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_posts_due ON posts(status, scheduled_at);
    CREATE TABLE IF NOT EXISTS data_rows (          -- Proměnné a data: Skupina | Parametr | Hodnota (od 1.5.0)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        grp TEXT NOT NULL,
        param TEXT NOT NULL,
        value TEXT NOT NULL DEFAULT '',
        updated_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS sources (            -- vzdálené zdroje dat JSON / CSV (od 1.5.0)
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        url TEXT NOT NULL,
        format TEXT NOT NULL DEFAULT 'json',
        csv_header INTEGER NOT NULL DEFAULT 1,
        interval_min INTEGER NOT NULL DEFAULT 15,
        payload TEXT, hash TEXT,
        fetched_at INTEGER, ok_at INTEGER, error TEXT,
        created_at INTEGER NOT NULL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS weather (            -- mezipaměť počasí podle místa (od 1.5.0)
        place_key TEXT PRIMARY KEY,
        place TEXT NOT NULL,
        payload TEXT,
        fetched_at INTEGER, ok_at INTEGER, error TEXT
    );
    CREATE TABLE IF NOT EXISTS api_keys (          -- klíče pro přijímání konceptů z jiných aplikací
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        key_hash TEXT NOT NULL UNIQUE,
        prefix TEXT NOT NULL,
        created_at INTEGER NOT NULL,
        last_used INTEGER,
        uses INTEGER NOT NULL DEFAULT 0
    );
    ");
    // Migrace: opakování příspěvků (přidá sloupce do existující databáze)
    $cols = array_column($pdo->query('PRAGMA table_info(posts)')->fetchAll(), 'name');
    $add = [
        'repeat_mode'  => "TEXT NOT NULL DEFAULT 'none'", // none | daily | weekly | monthly
        'repeat_until' => 'INTEGER',                       // UTC timestamp konce opakování (volitelné)
        'repeat_day'   => 'INTEGER',                       // původní den v měsíci (pro měsíční opakování)
        'series_id'    => 'INTEGER',                       // ID prvního příspěvku série
        'next_created' => 'INTEGER NOT NULL DEFAULT 0',    // další výskyt už byl vytvořen
        'link'         => 'TEXT',                          // odkaz k příspěvku (volitelné)
        'kind'         => "TEXT NOT NULL DEFAULT 'feed'",    // feed | reel | story
        'first_comment'=> 'TEXT',                          // první komentář pod příspěvkem (volitelné)
        'tags'         => 'TEXT',                          // štítky/hashtagy oddělené mezerou, bez #
        'source'       => 'TEXT',                          // zdroj z API (název klíče), jinak NULL
        'external_id'  => 'TEXT',                          // ID podkladu v cizí aplikaci (proti duplicitám)
    ];
    foreach ($add as $c => $def) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE posts ADD COLUMN $c $def");
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_posts_ext ON posts(source, external_id) WHERE external_id IS NOT NULL');
    // Migrace: povolit platformu „threads“ (CHECK v SQLite nelze změnit, tabulku je třeba přestavět)
    $asql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='accounts'")->fetchColumn();
    if ($asql !== '' && !str_contains($asql, "'threads'")) {
        $new = str_replace("CHECK (platform IN ('facebook','instagram'))", "CHECK (platform IN ('facebook','instagram','threads'))", $asql);
        $new = preg_replace('/CREATE TABLE\s+"?accounts"?/i', 'CREATE TABLE accounts_new', $new, 1);
        if (str_contains($new, "'threads'") && str_contains($new, 'accounts_new')) {
            $pdo->exec('PRAGMA foreign_keys=OFF');
            try {
                $pdo->exec('BEGIN');
                $pdo->exec('DROP TABLE IF EXISTS accounts_new');
                $pdo->exec($new);
                $pdo->exec('INSERT INTO accounts_new SELECT * FROM accounts');
                $pdo->exec('DROP TABLE accounts');
                $pdo->exec('ALTER TABLE accounts_new RENAME TO accounts');
                $pdo->exec('COMMIT');
            } catch (Throwable $e) { $pdo->exec('ROLLBACK'); throw $e; }
            $pdo->exec('PRAGMA foreign_keys=ON');
        }
    }
    // Migrace: statistiky cílů
    $tcols = array_column($pdo->query('PRAGMA table_info(targets)')->fetchAll(), 'name');
    foreach (['stat_likes', 'stat_comments', 'stat_shares', 'stat_reach', 'stat_saves', 'stat_at'] as $c) {
        if (!in_array($c, $tcols, true)) $pdo->exec("ALTER TABLE targets ADD COLUMN $c INTEGER");
    }
    // Migrace: povolení/zákaz jednotlivých účtů v nastavení
    $acols = array_column($pdo->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    if (!in_array('token_expires', $acols, true)) $pdo->exec('ALTER TABLE accounts ADD COLUMN token_expires INTEGER');
    if (!in_array('avatar_at', $acols, true)) $pdo->exec('ALTER TABLE accounts ADD COLUMN avatar_at INTEGER');
    if (!in_array('enabled', $acols, true)) {
        $pdo->exec('ALTER TABLE accounts ADD COLUMN enabled INTEGER NOT NULL DEFAULT 1');
    }
    foreach (['health' => 'TEXT', 'health_msg' => 'TEXT', 'health_at' => 'INTEGER'] as $c => $def) {
        if (!in_array($c, $acols, true)) $pdo->exec("ALTER TABLE accounts ADD COLUMN $c $def");
    }
    // Migrace 1.4.0: knihovna médií – tagy místo alb a archiv (běží i po obnově starší zálohy bez sloupce tags).
    $lcols = array_column($pdo->query('PRAGMA table_info(library)')->fetchAll(), 'name');
    if (!in_array('archived_at', $lcols, true)) $pdo->exec('ALTER TABLE library ADD COLUMN archived_at INTEGER');
    // 1.6.0: slidy = položky knihovny se šablonou (design JSON) a klíčem posledního vykreslení
    if (!in_array('design', $lcols, true)) $pdo->exec('ALTER TABLE library ADD COLUMN design TEXT');
    if (!in_array('slide_key', $lcols, true)) $pdo->exec('ALTER TABLE library ADD COLUMN slide_key TEXT');
    // 1.8.0: obrázky z internetu – autor a licence (JSON jako v Kvízech) a stránka obrázku u zdroje
    if (!in_array('credit', $lcols, true)) $pdo->exec('ALTER TABLE library ADD COLUMN credit TEXT');
    if (!in_array('source_url', $lcols, true)) $pdo->exec('ALTER TABLE library ADD COLUMN source_url TEXT');
    if (!in_array('tags', $lcols, true)) migrate_albums_to_tags($pdo);
    // 1.5.0: předem nastavené zdroje (Allwyn – klíč doplní uživatel do adresy, svátky) a výchozí místo počasí; jen jednou
    if ($pdo->query("SELECT value FROM meta WHERE key = 'data_seeded'")->fetchColumn() === false) {
        $pdo->beginTransaction();
        if (!(int)$pdo->query("SELECT COUNT(*) FROM sources")->fetchColumn()) {
            $ins = $pdo->prepare("INSERT INTO sources (name, url, format, interval_min, created_at) VALUES (?, ?, 'json', ?, ?)");
            $ins->execute(['Allwyn', 'https://trafikabr.cz/allwyn/api.php?action=latest_all&key=', 15, time()]);
            $ins->execute(['Svátky', 'https://trafikabr.cz/pomucky/svatek-cesky/', 60, time()]);
        }
        $pdo->exec("INSERT OR IGNORE INTO meta (key, value) VALUES ('weather_place', '49.9884,17.4637')");
        $pdo->exec("INSERT OR REPLACE INTO meta (key, value) VALUES ('data_seeded', '1')");
        $pdo->commit();
    }
    app_upgrade($pdo);   // jednorázový úklid po nahrání nové verze
    return $pdo;
}

/**
 * Migrace 1.4.0: každé album → tag stejného jména (nejvýš 30 znaků, nejvýš 10 tagů na soubor), alba se vyprázdní.
 * Celé v jedné transakci (i přidání sloupce), aby se při chybě (např. během nahrávání nové verze) zopakovala příště.
 * Záměrně nezávisí na jiném souboru. Tabulky alb zůstávají kvůli obnově starších záloh.
 */
function migrate_albums_to_tags(PDO $pdo): void {
    $pdo->beginTransaction();
    try {
        $pdo->exec('ALTER TABLE library ADD COLUMN tags TEXT');
        $by = [];
        foreach ($pdo->query("SELECT i.library_id, a.name FROM library_album_items i JOIN library_albums a ON a.id = i.album_id ORDER BY a.name")->fetchAll() as $r) {
            $t = mb_substr(trim((string)preg_replace('/[\s,;]+/u', ' ', (string)$r['name'])), 0, 30);
            if ($t === '') continue;
            $id = (int)$r['library_id'];
            $by[$id] ??= [];
            if (count($by[$id]) < 10 && !in_array(mb_strtolower($t), array_map('mb_strtolower', $by[$id]), true)) $by[$id][] = $t;
        }
        $st = $pdo->prepare("UPDATE library SET tags = ? WHERE id = ?");
        foreach ($by as $id => $tags) $st->execute([json_encode($tags, JSON_UNESCAPED_UNICODE), $id]);
        $albums = (int)$pdo->query("SELECT COUNT(*) FROM library_albums")->fetchColumn();
        $pdo->exec("DELETE FROM library_album_items");
        $pdo->exec("DELETE FROM library_albums");
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    if ($albums) log_line("Knihovna médií: $albums alb převedeno na tagy (" . count($by) . ' souborů).');
}

function session_start_safe(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

function require_login(bool $json = false): void {
    session_start_safe();
    require_once __DIR__ . '/auth.php';
    auth_check_idle();
    if (empty($_SESSION['auth'])) {
        if ($json) { json_out(['error' => 'Nepřihlášen'], 401); }
        header('Location: login.php');
        exit;
    }
}

function csrf_token(): string {
    session_start_safe();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_check(): void {
    session_start_safe();
    $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        json_out(['error' => 'Neplatný CSRF token'], 403);
    }
}

function json_out(array $data, int $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function log_line(string $msg): void {
    @file_put_contents(ROOT . '/data/planner.log', date('c') . ' ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/** Všechna média příspěvku v pořadí: [['file','url','type'], …] (první je posts.media_file, další z post_media). */
function media_list(PDO $pdo, array $post): array {
    if (empty($post['media_file'])) return [];
    $out = [['file' => $post['media_file'], 'url' => 'uploads/' . rawurlencode($post['media_file']), 'type' => $post['media_type'] ?: 'image']];
    $q = $pdo->prepare("SELECT file FROM post_media WHERE post_id = ? ORDER BY position");
    $q->execute([$post['id']]);
    foreach ($q->fetchAll() as $r) $out[] = ['file' => $r['file'], 'url' => 'uploads/' . rawurlencode($r['file']), 'type' => 'image'];
    return $out;
}

/** Je soubor ještě použit nějakým příspěvkem? */
function media_in_use(PDO $pdo, string $file): bool {
    $a = $pdo->prepare("SELECT (SELECT COUNT(*) FROM posts WHERE media_file = ?) + (SELECT COUNT(*) FROM post_media WHERE file = ?)");
    $a->execute([$file, $file]);
    return (int)$a->fetchColumn() > 0;
}
/** Soubor, který je v knihovně, se s příspěvkem nemaže. */
function media_in_library(PDO $pdo, string $file): bool {
    $a = $pdo->prepare("SELECT COUNT(*) FROM library WHERE file = ?");
    $a->execute([$file]);
    return (int)$a->fetchColumn() > 0;
}
