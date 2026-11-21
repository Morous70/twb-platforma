<?php
// Vytvoření tabulek (MySQL i SQLite).
function create_schema(): void {
    $sqlite = is_sqlite();
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
    $fkInt = $sqlite ? 'INTEGER' : 'INT UNSIGNED';

    $stmts = [
        "CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT)$engine",
        "CREATE TABLE IF NOT EXISTS media (
            id $pk,
            filename VARCHAR(80) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime VARCHAR(100) NOT NULL,
            kind VARCHAR(10) NOT NULL,
            size BIGINT NOT NULL,
            md5 CHAR(32) NOT NULL,
            grp VARCHAR(64) NULL,
            created_at DATETIME NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS scenarios (
            id $pk,
            name VARCHAR(150) NOT NULL,
            note TEXT NULL,
            is_default TINYINT NOT NULL DEFAULT 0,
            priority TINYINT NOT NULL DEFAULT 2,
            exclusive TINYINT NOT NULL DEFAULT 0,
            paused TINYINT NOT NULL DEFAULT 0,
            date_from DATE NULL,
            date_to DATE NULL,
            weekdays VARCHAR(7) NOT NULL DEFAULT '1234567',
            hours CHAR(24) NOT NULL DEFAULT '111111111111111111111111',
            exceptions TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        )$engine",
        "CREATE TABLE IF NOT EXISTS scenario_media (
            scenario_id $fkInt NOT NULL,
            media_id $fkInt NOT NULL,
            PRIMARY KEY (scenario_id, media_id),
            FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE,
            FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
        )$engine",
        "CREATE TABLE IF NOT EXISTS logs (
            id $pk,
            at DATETIME NOT NULL,
            level VARCHAR(10) NOT NULL,
            message TEXT NOT NULL
        )$engine",
    ];
    foreach ($stmts as $s) db()->exec($s);
    migrate();
}

// ---------- Migrace existující instalace ----------
const SCHEMA_VERSION = 19;

function migrate(): void {
    if ((int)setting('schema_version', 1) >= SCHEMA_VERSION) return;

    // Aplikace posílá víc požadavků najednou – převod smí běžet jen jednou.
    // Ostatní požadavky počkají na zámek a pak už najdou hotovou databázi.
    $lock = fopen(DATA_DIR . '/migrate.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        setting('__reload');
        $v = (int)setting('schema_version', 1);
        if ($v < SCHEMA_VERSION) run_migrations($v);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Přidá sloupec, pokud ještě neexistuje (bezpečné i po částečně dokončeném převodu). */
function add_column(string $table, string $column, string $definition): void {
    if (in_array(strtolower($column), array_map('strtolower', column_names($table)), true)) return;
    try {
        db()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'duplicate column') === false) throw $e;
    }
}

function run_migrations(int $v): void {
    $sqlite = is_sqlite();
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_czech_ci';
    $int = $sqlite ? 'INTEGER' : 'INT UNSIGNED';

    if ($v < 2) {
        // složky v galerii, text/odkaz (content), náhodné výběry
        db()->exec("CREATE TABLE IF NOT EXISTS folders (id $pk, name VARCHAR(100) NOT NULL, created_at DATETIME NOT NULL)$engine");
        add_column('media', 'folder_id', "$int NULL");
        add_column('media', 'content', 'TEXT NULL');
        db()->exec("CREATE TABLE IF NOT EXISTS pool_members (
            pool_id $int NOT NULL, media_id $int NOT NULL,
            PRIMARY KEY (pool_id, media_id),
            FOREIGN KEY (pool_id) REFERENCES media(id) ON DELETE CASCADE,
            FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
        )$engine");
        db()->exec("CREATE TABLE IF NOT EXISTS random_state (
            pool_id $int NOT NULL PRIMARY KEY, deck TEXT NULL, current_id $int NULL, hour_key VARCHAR(16) NULL
        )$engine");
        set_setting('schema_version', '2');
        app_log('info', 'Databáze převedena na verzi 2 (složky, náhodné výběry, text a odkazy).');
    }
    if ($v < 3) {
        // vnořené scénáře
        add_column('scenarios', 'embed_only', 'TINYINT NOT NULL DEFAULT 0');
        db()->exec("CREATE TABLE IF NOT EXISTS scenario_includes (
            scenario_id $int NOT NULL, child_id $int NOT NULL, follow_rules TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (scenario_id, child_id),
            FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE,
            FOREIGN KEY (child_id) REFERENCES scenarios(id) ON DELETE CASCADE
        )$engine");
        set_setting('schema_version', '3');
        app_log('info', 'Databáze převedena na verzi 3 (vnořené scénáře).');
    }
    if ($v < 4) {
        // sekce Data: Skupina | Parametr | Hodnota
        db()->exec("CREATE TABLE IF NOT EXISTS data_rows (
            id $pk, grp VARCHAR(100) NOT NULL, param VARCHAR(200) NOT NULL, value TEXT NOT NULL,
            created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
        )$engine");
        set_setting('schema_version', '4');
        app_log('info', 'Databáze převedena na verzi 4 (sekce Data).');
    }
    if ($v < 5) {
        // vzdálené zdroje dat (JSON)
        $big = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        db()->exec("CREATE TABLE IF NOT EXISTS sources (
            id $pk, name VARCHAR(60) NOT NULL, url TEXT NOT NULL, interval_min INT NOT NULL DEFAULT 15,
            payload $big NULL, hash VARCHAR(32) NULL, fetched_at DATETIME NULL, ok_at DATETIME NULL, error TEXT NULL,
            created_at DATETIME NOT NULL
        )$engine");
        if (!(int)q('SELECT COUNT(*) FROM sources')->fetchColumn()) {
            q('INSERT INTO sources (name, url, interval_min, created_at) VALUES (?, ?, 15, ?)',
              ['Allwyn', 'https://trafikabr.cz/allwyn/api.php?action=latest_all', date('Y-m-d H:i:s')]);
        }
        set_setting('schema_version', '5');
        app_log('info', 'Databáze převedena na verzi 5 (vzdálené zdroje dat).');
    }
    if ($v < 6) {
        // pevné scénáře: zobrazují se vždy; v jednotlivých scénářích je lze vypnout
        add_column('scenarios', 'is_fixed', 'TINYINT NOT NULL DEFAULT 0');
        db()->exec("CREATE TABLE IF NOT EXISTS scenario_fixed_off (
            scenario_id $int NOT NULL, fixed_id $int NOT NULL,
            PRIMARY KEY (scenario_id, fixed_id),
            FOREIGN KEY (scenario_id) REFERENCES scenarios(id) ON DELETE CASCADE,
            FOREIGN KEY (fixed_id) REFERENCES scenarios(id) ON DELETE CASCADE
        )$engine");
        set_setting('schema_version', '6');
        app_log('info', 'Databáze převedena na verzi 6 (pevné scénáře).');
    }
    if ($v < 7) {
        // komprese obrázků: rozměry, uchovaný originál, název nahraného souboru (kvůli „Nahradit soubor“)
        add_column('media', 'width', 'INT NULL');
        add_column('media', 'height', 'INT NULL');
        add_column('media', 'orig_filename', 'VARCHAR(80) NULL');
        add_column('media', 'orig_mime', 'VARCHAR(100) NULL');
        add_column('media', 'orig_size', 'BIGINT NULL');
        add_column('media', 'orig_w', 'INT NULL');
        add_column('media', 'orig_h', 'INT NULL');
        add_column('media', 'upload_name', 'VARCHAR(255) NULL');
        q("UPDATE media SET upload_name = original_name WHERE upload_name IS NULL AND kind IN ('image', 'video', 'pdf')");
        set_setting('schema_version', '7');
        app_log('info', 'Databáze převedena na verzi 7 (komprese obrázků, nahrazení souboru).');
    }
    if ($v < 8) {
        // zdroje ve formátu CSV
        add_column('sources', 'format', "VARCHAR(10) NOT NULL DEFAULT 'json'");
        add_column('sources', 'csv_header', 'TINYINT NOT NULL DEFAULT 1');
        set_setting('schema_version', '8');
        app_log('info', 'Databáze převedena na verzi 8 (zdroje CSV).');
    }
    if ($v < 9) {
        // výběr podle data: položka galerie (kind = 'dated') → média s daty, kdy se zobrazí
        db()->exec("CREATE TABLE IF NOT EXISTS dated_items (
            pool_id $int NOT NULL, media_id $int NOT NULL, day DATE NOT NULL,
            PRIMARY KEY (pool_id, media_id, day),
            FOREIGN KEY (pool_id) REFERENCES media(id) ON DELETE CASCADE,
            FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
        )$engine");
        set_setting('schema_version', '9');
        app_log('info', 'Databáze převedena na verzi 9 (výběr podle data).');
    }
    if ($v < 10) {
        // oprava ve verzi 1.4.1: editor ukládal datum po první číslici roku (např. 0002-10-22) → doplnit aktuální rok
        $year = date('Y');
        $fixed = 0;
        foreach (q("SELECT pool_id, media_id, day FROM dated_items WHERE day < '2000-01-01'")->fetchAll() as $r) {
            $new = $year . substr((string)$r['day'], 4, 6);
            q('DELETE FROM dated_items WHERE pool_id = ? AND media_id = ? AND day = ?', [$r['pool_id'], $r['media_id'], $r['day']]);
            if (checkdate((int)substr($new, 5, 2), (int)substr($new, 8, 2), (int)$year)
                && !q('SELECT 1 FROM dated_items WHERE pool_id = ? AND media_id = ? AND day = ?', [$r['pool_id'], $r['media_id'], $new])->fetchColumn()) {
                q('INSERT INTO dated_items (pool_id, media_id, day) VALUES (?, ?, ?)', [$r['pool_id'], $r['media_id'], $new]);
            }
            $fixed++;
        }
        set_setting('schema_version', '10');
        app_log('info', 'Databáze převedena na verzi 10' . ($fixed ? " – opraveno $fixed dat ve výběrech podle data s chybným rokem (doplněn rok $year)." : '.'));
    }
    if ($v < 11) {
        // přihlášení: Passkey (WebAuthn) a jednorázové tokeny (obnova hesla, výzvy pro Passkey)
        db()->exec("CREATE TABLE IF NOT EXISTS passkeys (
            id $pk, name VARCHAR(100) NOT NULL, cred_hash CHAR(64) NOT NULL, cred_id TEXT NOT NULL,
            public_key TEXT NOT NULL, alg INT NOT NULL, sign_count BIGINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, last_used DATETIME NULL,
            UNIQUE (cred_hash)
        )$engine");
        db()->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
            id $pk, purpose VARCHAR(20) NOT NULL, token_hash CHAR(64) NOT NULL, expires BIGINT NOT NULL, used TINYINT NOT NULL DEFAULT 0,
            UNIQUE (token_hash)
        )$engine");
        set_setting('schema_version', '11');
        app_log('info', 'Databáze převedena na verzi 11 (Passkey, obnova hesla).');
    }
    if ($v < 12) {
        // zařízení pro přehrávání (plugin pro Chrome, později Android a TV): klíč jen pro čtení, uložený jako SHA-256
        db()->exec("CREATE TABLE IF NOT EXISTS devices (
            id $pk, name VARCHAR(100) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'chrome', token_hash CHAR(64) NOT NULL,
            duration INT NULL, created_at DATETIME NOT NULL, last_seen DATETIME NULL, client VARCHAR(100) NULL,
            UNIQUE (token_hash)
        )$engine");
        set_setting('schema_version', '12');
        app_log('info', 'Databáze převedena na verzi 12 (zařízení pro přehrávání).');
    }
    if ($v < 13) {
        // párování televize kódem: televize zná jen kód a tajný token pro dotazování; klíč dostane jednou po spárování
        db()->exec("CREATE TABLE IF NOT EXISTS device_pairing (
            id $pk, code_hash CHAR(64) NOT NULL, poll_hash CHAR(64) NOT NULL, device_id $int NULL, token VARCHAR(40) NULL,
            created_at BIGINT NOT NULL, expires BIGINT NOT NULL, ip VARCHAR(64) NULL,
            UNIQUE (poll_hash)
        )$engine");
        set_setting('schema_version', '13');
        app_log('info', 'Databáze převedena na verzi 13 (párování televize).');
    }
    if ($v < 14) {
        // párování: druh zařízení, které o kód požádalo (TV Samsung / Android TV)
        add_column('device_pairing', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'tizen'");
        set_setting('schema_version', '14');
        app_log('info', 'Databáze převedena na verzi 14 (párování Android TV).');
    }
    if ($v < 15) {
        // ovládání zařízení z aplikace: stav hlášený zařízením a jeden čekající příkaz (obnovit / restartovat / další)
        add_column('devices', 'status', 'TEXT NULL');
        add_column('devices', 'status_at', 'DATETIME NULL');
        add_column('devices', 'cmd', 'VARCHAR(20) NULL');
        add_column('devices', 'cmd_id', 'INT NOT NULL DEFAULT 0');
        add_column('devices', 'cmd_at', 'DATETIME NULL');
        set_setting('schema_version', '15');
        app_log('info', 'Databáze převedena na verzi 15 (ovládání zařízení).');
    }
    if ($v < 16) {
        // archiv v galerii: médium v archivu dál hraje, jen se nenabízí k novému použití
        add_column('media', 'archived_at', 'DATETIME NULL');
        add_column('media', 'archive_folder_id', 'INT NULL');
        set_setting('schema_version', '16');
        app_log('info', 'Databáze převedena na verzi 16 (archiv médií).');
    }
    if ($v < 17) {
        // tagy médií: JSON seznam štítků pro třídění a hledání
        add_column('media', 'tags', 'TEXT NULL');
        set_setting('schema_version', '17');
        app_log('info', 'Databáze převedena na verzi 17 (tagy médií).');
    }
    if ($v < 18) {
        // složky v galerii se ruší: každé médium dostane tag se jménem své složky (archivované podle složky před archivací).
        // Běží i při obnovení starší zálohy se složkami.
        $names = [];
        try {
            foreach (q('SELECT id, name FROM folders')->fetchAll() as $f) {
                $n = trim(mb_substr(trim(preg_replace('/[\s,;]+/u', ' ', (string)$f['name'])), 0, 30));
                if ($n !== '') $names[(int)$f['id']] = $n;
            }
        } catch (Throwable $e) { /* tabulka složek neexistuje */ }
        $hasArch = in_array('archive_folder_id', column_names('media'), true);
        $converted = 0;
        if ($names) {
            foreach (q('SELECT id, folder_id, ' . ($hasArch ? 'archive_folder_id' : 'NULL AS archive_folder_id') . ', tags FROM media')->fetchAll() as $m) {
                $fid = $m['folder_id'] ?? $m['archive_folder_id'];
                $tag = $fid !== null ? ($names[(int)$fid] ?? null) : null;
                if ($tag === null) continue;
                $tags = json_decode((string)$m['tags'], true);
                $tags = is_array($tags) ? $tags : [];
                $keys = array_map(fn($t) => mb_strtolower((string)$t, 'UTF-8'), $tags);
                if (in_array(mb_strtolower($tag, 'UTF-8'), $keys, true) || count($tags) >= 10) continue;
                $tags[] = $tag;
                q('UPDATE media SET tags = ? WHERE id = ?', [json_encode(array_values($tags), JSON_UNESCAPED_UNICODE), $m['id']]);
                $converted++;
            }
        }
        q('UPDATE media SET folder_id = NULL');
        if ($hasArch) q('UPDATE media SET archive_folder_id = NULL');
        try { q('DELETE FROM folders'); } catch (Throwable $e) { /* nic */ }
        set_setting('schema_version', '18');
        app_log('info', 'Databáze převedena na verzi 18 (složky galerie nahrazeny tagy' . ($names ? ': ' . implode(', ', $names) . ", $converted médií" : '') . ').');
    }
    if ($v < 19) {
        // počasí: mezipaměť stažených dat podle místa (stahuje cron každých 15 minut)
        $big = $sqlite ? 'TEXT' : 'MEDIUMTEXT';
        db()->exec("CREATE TABLE IF NOT EXISTS weather (
            place_key VARCHAR(100) NOT NULL PRIMARY KEY, place VARCHAR(100) NOT NULL, provider VARCHAR(30) NOT NULL,
            payload $big NULL, fetched_at DATETIME NULL, ok_at DATETIME NULL, error TEXT NULL
        )$engine");
        set_setting('schema_version', '19');
        app_log('info', 'Databáze převedena na verzi 19 (počasí).');
    }
}

function column_names(string $table): array {
    if (is_sqlite()) return array_column(q("PRAGMA table_info($table)")->fetchAll(), 'name');
    return array_column(q("SHOW COLUMNS FROM $table")->fetchAll(), 'Field');
}
