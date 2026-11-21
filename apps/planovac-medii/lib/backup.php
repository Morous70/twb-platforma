<?php
// Zálohy: šifrovaný ZIP (AES-256) s databází a – jen když se změnily – se soubory galerie.
// Na Wedosu zůstává posledních BACKUP_KEEP záloh + vždy poslední záloha se soubory. Kopie si stahuje Synology (backup.php).

const BACKUP_DIR = DATA_DIR . '/backups';
const BACKUP_KEEP = 7;
const BACKUP_PREFIX = 'twb-planovac-zaloha-';
const BACKUP_HOUR = 3;
/** Tabulky v pořadí pro obnovení (nejdřív ty, na které ostatní odkazují). */
const BACKUP_TABLES = ['settings', 'folders', 'media', 'pool_members', 'random_state', 'dated_items', 'scenarios', 'scenario_media',
    'scenario_includes', 'scenario_fixed_off', 'data_rows', 'sources', 'logs'];
/** Nastavení, která patří k této instalaci a obnovením se nepřepisují (evidence záloh). */
const BACKUP_LOCAL_SETTINGS = ['backup_media_hash', 'last_backup_at', 'backup_nas_at', 'backup_nas_file', 'device_fails', 'device_files_cache', 'pair_starts', 'pair_fails'];

function backup_supported(): ?string {
    if (!class_exists('ZipArchive')) return 'PHP na serveru nemá rozšíření zip.';
    if (!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256')) return 'PHP na serveru neumí šifrovat ZIP (AES). Požádejte Wedos o novější knihovnu libzip.';
    return null;
}

function backup_dir(): string {
    if (!is_dir(BACKUP_DIR)) @mkdir(BACKUP_DIR, 0775, true);
    return BACKUP_DIR;
}

/** Soubory galerie, které databáze používá: [název souboru => md5]. */
function backup_media_files(): array {
    $files = [];
    foreach (q('SELECT filename, orig_filename FROM media')->fetchAll() as $m) {
        foreach ([$m['filename'], $m['orig_filename'] ?? null] as $f) {
            if ($f && !isset($files[$f]) && is_file(MEDIA_DIR . "/$f")) $files[$f] = md5_file(MEDIA_DIR . "/$f");
        }
    }
    ksort($files);
    return $files;
}

/** Seznam záloh na Wedosu (nejnovější první). */
function backup_list(): array {
    $out = [];
    foreach (glob(backup_dir() . '/' . BACKUP_PREFIX . '*.zip') ?: [] as $p) {
        $n = basename($p);
        $out[] = ['name' => $n, 'size' => filesize($p), 'time' => filemtime($p),
                  'files' => str_contains($n, '-soubory'), 'before_restore' => str_contains($n, '-pred-obnovou'), 'uploaded' => str_contains($n, '-nahrana')];
    }
    // nejnovější první: podle času vytvoření, při shodě podle názvu (obsahuje i sekundy)
    usort($out, fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
    return $out;
}

function backup_path(string $name): string {
    if (!preg_match('/^' . preg_quote(BACKUP_PREFIX, '/') . '[a-z0-9-]+\.zip$/', $name) || !is_file(backup_dir() . "/$name")) {
        throw new RuntimeException('Záloha nenalezena.');
    }
    return backup_dir() . "/$name";
}

/**
 * Vytvoří zálohu. $kind: '' (běžná), 'pred-obnovou', 'rucni'.
 * Soubory galerie se přidají, když se od poslední zálohy se soubory změnily, nebo když na Wedosu žádná taková není ($forceFiles vždy).
 * Vrací údaje o záloze.
 */
function backup_create(string $kind = '', bool $forceFiles = false): array {
    if ($err = backup_supported()) throw new RuntimeException($err);
    $pass = (string)setting('backup_password', '');
    if ($pass === '') throw new RuntimeException('Nejdřív nastavte heslo záloh (Nastavení → Zálohy).');
    @set_time_limit(300);

    $files = backup_media_files();
    $mediaHash = md5(json_encode($files));
    $haveFiles = (bool)array_filter(backup_list(), fn($b) => $b['files']);
    $withFiles = $forceFiles || $mediaHash !== setting('backup_media_hash') || !$haveFiles;

    require_once __DIR__ . '/fonts.php';
    $fontFiles = font_backup_files();
    $db = ['app' => APP_NAME, 'app_version' => APP_VERSION, 'schema_version' => (int)setting('schema_version', 1),
           'created_at' => date('c'), 'media_files' => $files, 'with_files' => $withFiles, 'font_files' => $fontFiles, 'tables' => []];
    foreach (BACKUP_TABLES as $t) {
        try { $rows = q("SELECT * FROM $t")->fetchAll(); } catch (Throwable $e) { continue; } // tabulka ještě neexistuje
        $db['tables'][$t] = $rows;
    }
    $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Databázi se nepodařilo převést do zálohy.');

    $base = BACKUP_PREFIX . date('Y-m-d-His') . ($kind !== '' ? "-$kind" : '') . ($withFiles ? '-soubory' : '');
    $name = "$base.zip";
    for ($i = 2; is_file(backup_dir() . "/$name"); $i++) $name = "$base-v$i.zip"; // dvě zálohy ve stejné minutě
    $tmp = backup_dir() . "/.$name.part";
    @unlink($tmp);
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nelze vytvořit soubor zálohy.');
    $zip->setPassword($pass);
    $readme = APP_NAME . " – záloha\nVytvořeno: " . date('j. n. Y H:i') . "\nVerze aplikace: " . APP_VERSION . ", databáze: " . $db['schema_version']
        . "\nSoubory galerie: " . ($withFiles ? 'ano (' . count($files) . ')' : 'ne – jsou v poslední záloze „…-soubory.zip“') . "\n\n"
        . "Obnovení: TWB Plánovač médií → Nastavení → Zálohy → Obnovit ze souboru.\nZIP je šifrovaný (AES-256) – otevřete ho např. programem 7-Zip.\n";
    $zip->addFromString('ZALOHA.txt', $readme);
    $zip->addFromString('database.json', $json);
    $zip->setEncryptionName('database.json', ZipArchive::EM_AES_256, $pass);
    if ($withFiles) {
        foreach ($files as $f => $md5) {
            $zip->addFile(MEDIA_DIR . "/$f", "media/$f");
            $zip->setEncryptionName("media/$f", ZipArchive::EM_AES_256, $pass);
            $zip->setCompressionName("media/$f", ZipArchive::CM_STORE); // obrázky a videa už jsou komprimované
        }
    }
    // vlastní písma (malá) jsou v každé záloze
    foreach ($fontFiles as $f => $md5) {
        $zip->addFile(CUSTOM_FONT_DIR . "/$f", "fonts/$f");
        $zip->setEncryptionName("fonts/$f", ZipArchive::EM_AES_256, $pass);
    }
    if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('Zálohu se nepodařilo zapsat (málo místa?).'); }
    rename($tmp, backup_dir() . "/$name");

    if ($withFiles) set_setting('backup_media_hash', $mediaHash);
    set_setting('last_backup_at', (string)time());
    $size = filesize(backup_dir() . "/$name");
    backup_prune();
    app_log('info', "Záloha vytvořena: $name (" . fmt_size_short($size) . ($withFiles ? ', se soubory galerie' : ', jen databáze') . ').');
    return ['name' => $name, 'size' => $size, 'files' => $withFiles];
}

function fmt_size_short(int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : max(1, (int)round($b / 1024)) . ' kB';
}

/** Ponechá posledních BACKUP_KEEP záloh a navíc vždy nejnovější zálohu se soubory. */
function backup_prune(): void {
    $list = backup_list();
    $keep = array_slice(array_column($list, 'name'), 0, BACKUP_KEEP);
    foreach ($list as $b) if ($b['files']) { $keep[] = $b['name']; break; }
    foreach ($list as $b) if (!in_array($b['name'], $keep, true)) @unlink(backup_dir() . '/' . $b['name']);
    foreach (glob(backup_dir() . '/.*.part') ?: [] as $p) if (filemtime($p) < time() - 3600) @unlink($p);
}

/** Volá cron: jednou denně od BACKUP_HOUR. */
function backup_due(): ?array {
    if (setting('backup_auto', '1') !== '1' || (string)setting('backup_password', '') === '' || backup_supported()) return null;
    if ((int)date('G') < BACKUP_HOUR) return null;
    $last = (int)setting('last_backup_at', 0);
    if ($last && date('Y-m-d', $last) === date('Y-m-d') && (int)date('G', $last) >= BACKUP_HOUR) return null;
    return backup_create();
}

/** Otevře zálohu heslem a vrátí [ZipArchive, data databáze]. */
function backup_open(string $path, string $pass): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Soubor není platná záloha (ZIP).');
    $zip->setPassword($pass);
    $json = $zip->getFromName('database.json');
    if ($json === false) {
        $zip->close();
        throw new RuntimeException('Zálohu nelze otevřít – nesprávné heslo, nebo to není záloha TWB Plánovače médií.');
    }
    $db = json_decode($json, true);
    if (!is_array($db) || !isset($db['tables']['settings'], $db['tables']['media'])) { $zip->close(); throw new RuntimeException('Záloha je poškozená.'); }
    return [$zip, $db];
}

/** Informace o záloze (pro potvrzení před obnovením). */
function backup_info(string $path, string $pass): array {
    [$zip, $db] = backup_open($path, $pass);
    $zip->close();
    $count = fn($t) => count($db['tables'][$t] ?? []);
    return ['created_at' => $db['created_at'] ?? null, 'app_version' => $db['app_version'] ?? '?', 'schema_version' => (int)($db['schema_version'] ?? 0),
            'with_files' => !empty($db['with_files']), 'media' => $count('media'), 'scenarios' => $count('scenarios'),
            'data_rows' => $count('data_rows'), 'files' => count($db['media_files'] ?? [])];
}

/**
 * Obnoví zálohu. Před obnovením vytvoří pojistnou zálohu současného stavu (se soubory).
 * Soubory galerie: ze stejné zálohy, jinak z jiné zálohy na Wedosu, jinak ze souborů, které už na Wedosu jsou (kontrola md5).
 * Vrací ['safety' => název pojistné zálohy, 'missing' => [chybějící soubory]].
 */
function backup_restore(string $path, string $pass): array {
    @set_time_limit(600);
    [$zip, $db] = backup_open($path, $pass);
    $schema = (int)($db['schema_version'] ?? 0);
    if ($schema > SCHEMA_VERSION) { $zip->close(); throw new RuntimeException("Záloha je z novější verze aplikace ({$db['app_version']}). Nejdřív aktualizujte aplikaci."); }

    // 1) soubory galerie připravit do dočasné složky (nic se zatím nemění)
    $need = (array)($db['media_files'] ?? []);
    $stage = TMP_DIR . '/restore-' . bin2hex(random_bytes(4));
    @mkdir($stage, 0775, true);
    $missing = [];
    $fromZip = function (ZipArchive $z, string $f, string $md5) use ($stage): bool {
        $data = $z->getFromName("media/$f");
        if ($data === false || md5($data) !== $md5) return false;
        return file_put_contents("$stage/$f", $data) !== false;
    };
    $others = null;
    foreach ($need as $f => $md5) {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $f)) continue;
        if (is_file(MEDIA_DIR . "/$f") && md5_file(MEDIA_DIR . "/$f") === $md5) continue; // už je na místě
        if ($fromZip($zip, $f, $md5)) continue;
        // jiné zálohy se soubory (stejné heslo), nejnovější první
        if ($others === null) {
            $others = [];
            foreach (backup_list() as $b) {
                if (!$b['files'] || backup_dir() . '/' . $b['name'] === realpath($path)) continue;
                $o = new ZipArchive();
                if ($o->open(backup_dir() . '/' . $b['name']) === true) { $o->setPassword($pass); $others[] = $o; }
            }
        }
        $ok = false;
        foreach ($others as $o) if ($fromZip($o, $f, $md5)) { $ok = true; break; }
        if (!$ok) $missing[] = $f;
    }
    foreach ((array)$others as $o) $o->close();
    // vlastní písma ze zálohy připravit vedle souborů galerie
    $fontStage = [];
    foreach ((array)($db['font_files'] ?? []) as $f => $md5) {
        if (!preg_match('/^u_[a-z0-9]+\.(ttf|otf)$/', $f)) continue;
        $data = $zip->getFromName("fonts/$f");
        if ($data !== false && md5($data) === $md5) $fontStage[$f] = $data;
    }
    $zip->close();

    // 2) pojistná záloha současného stavu
    $safety = backup_create('pred-obnovou', true)['name'];

    // 3) databáze
    $local = [];
    foreach (BACKUP_LOCAL_SETTINGS as $k) $local[$k] = setting($k);
    $sqlite = is_sqlite();
    db()->exec($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
    try {
        db()->beginTransaction();
        foreach (array_reverse(BACKUP_TABLES) as $t) { try { db()->exec("DELETE FROM $t"); } catch (Throwable $e) { /* tabulka neexistuje */ } }
        foreach (BACKUP_TABLES as $t) {
            $rows = $db['tables'][$t] ?? [];
            if (!$rows) continue;
            $cols = array_values(array_intersect(array_keys($rows[0]), column_names($t)));
            if (!$cols) continue;
            $sql = "INSERT INTO $t (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
            $st = db()->prepare($sql);
            foreach ($rows as $r) $st->execute(array_map(fn($c) => $r[$c] ?? null, $cols));
        }
        foreach ($local as $k => $v) set_setting($k, $v);
        set_setting('schema_version', (string)max(1, $schema));
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        db()->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        array_map('unlink', glob("$stage/*") ?: []); @rmdir($stage);
        throw new RuntimeException('Obnovení databáze selhalo, nic se nezměnilo: ' . $e->getMessage());
    }
    db()->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');

    // 4) soubory na místo, nepoužívané odstranit (jsou v pojistné záloze)
    foreach (glob("$stage/*") ?: [] as $p) rename($p, MEDIA_DIR . '/' . basename($p));
    @rmdir($stage);
    setting('__reload');
    $used = backup_media_files();
    foreach (glob(MEDIA_DIR . '/*') ?: [] as $p) {
        $b = basename($p);
        if (is_file($p) && $b[0] !== '.' && !isset($used[$b])) @unlink($p);
    }

    if ($fontStage) {
        require_once __DIR__ . '/slide.php';
        @mkdir(CUSTOM_FONT_DIR, 0775, true);
        foreach ($fontStage as $f => $data) file_put_contents(CUSTOM_FONT_DIR . "/$f", $data);
    }

    // 5) převod databáze na aktuální verzi, vynutit synchronizaci, přegenerovat slidy
    if ($schema < SCHEMA_VERSION) run_migrations($schema);
    set_setting('last_sig', null);
    setting('__reload');
    app_log('info', 'Obnovena záloha z ' . ($db['created_at'] ? date('j. n. Y H:i', strtotime($db['created_at'])) : '?') . " (verze {$db['app_version']}). Pojistná záloha: $safety."
        . ($missing ? ' Chybějící soubory: ' . count($missing) . '.' : ''));
    return ['safety' => $safety, 'missing' => $missing];
}
