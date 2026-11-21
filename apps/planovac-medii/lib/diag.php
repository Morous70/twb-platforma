<?php
// Diagnostika: ověří, že server, Google Disk, cron, zálohy a Synology fungují. Nic trvale nemění
// (na Google Disk nic nezapisuje, zkušební soubory vytváří jen v data/tmp a hned maže).

/** Jedna položka výsledku. $status: ok | warn | error | info */
function diag_item(string $group, string $name, string $status, string $value, string $hint = ''): array {
    return compact('group', 'name', 'status', 'value', 'hint');
}

function diag_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

function diag_ago(?int $ts): string {
    if (!$ts) return 'nikdy';
    $m = (int)round((time() - $ts) / 60);
    if ($m < 1) return 'právě teď';
    if ($m < 60) return "před $m min";
    if ($m < 48 * 60) return 'před ' . round($m / 60) . ' h';
    return date('j. n. Y H:i', $ts);
}

function diag_dir_size(string $dir): int {
    $s = 0;
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $f) if (is_file($f)) $s += filesize($f);
    return $s;
}

/** Požadavek na vlastní adresu aplikace (kontrola, že chráněné soubory nejsou vidět z internetu). */
function diag_http_code(string $url): ?int {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'TWB-PlanovacMedii-Diagnostika']);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return $ok === false || $code === 0 ? null : $code;
}

function diag_run(): array {
    $out = [];
    $add = function (array $item) use (&$out) { $out[] = $item; };
    $safe = function (string $group, string $name, callable $fn) use ($add) {
        try { $r = $fn(); if ($r) $add($r); }
        catch (Throwable $e) { $add(diag_item($group, $name, 'error', 'chyba kontroly', $e->getMessage())); }
    };

    // ---------- Server a PHP ----------
    $G = 'Server a PHP';
    $add(diag_item($G, 'Verze PHP', PHP_VERSION_ID >= 80000 ? 'ok' : 'error', PHP_VERSION, PHP_VERSION_ID >= 80000 ? '' : 'Aplikace potřebuje PHP 8.0 nebo novější (administrace Wedos → webhosting → PHP).'));
    $have = [];
    foreach (['pdo_mysql' => 'databáze', 'mbstring' => 'čeština v textech', 'curl' => 'Google Disk, zdroje, upozornění', 'gd' => 'slidy, komprese obrázků',
              'zip' => 'zálohy', 'fileinfo' => 'rozpoznání typu nahraných souborů', 'iconv' => 'CSV z Excelu (Windows-1250)', 'openssl' => 'HTTPS spojení'] as $ext => $why) {
        if (extension_loaded($ext)) { $have[] = $ext; continue; }
        $add(diag_item($G, "Rozšíření $ext", in_array($ext, ['iconv', 'fileinfo'], true) ? 'warn' : 'error', 'chybí', "Potřeba pro: $why. Zapněte rozšíření v administraci hostingu."));
    }
    if ($have) $add(diag_item($G, 'Rozšíření PHP', 'ok', implode(', ', $have)));
    $add(diag_item($G, 'Rozšíření exif', extension_loaded('exif') ? 'ok' : 'info', extension_loaded('exif') ? 'ano' : 'není (nevadí)', extension_loaded('exif') ? '' : 'Otočení fotek z mobilu si aplikace přečte sama.'));
    $safe($G, 'GD a písma', function () use ($G) {
        if (!function_exists('gd_info')) return null;
        $gi = gd_info();
        $miss = [];
        if (empty($gi['FreeType Support'])) $miss[] = 'FreeType (texty ve slidech)';
        if (empty($gi['JPEG Support'])) $miss[] = 'JPEG';
        if (empty($gi['PNG Support'])) $miss[] = 'PNG';
        if (empty($gi['WebP Support'])) $miss[] = 'WebP (jen nahrávání WebP)';
        $fonts = glob(APP_DIR . '/assets/fonts/*.ttf') ?: [];
        $im = imagecreatetruecolor(40, 20);
        $txt = !empty($gi['FreeType Support']) && $fonts ? @imagettftext($im, 10, 0, 2, 15, imagecolorallocate($im, 255, 255, 255), $fonts[0], 'Žluť') : false;
        imagedestroy($im);
        $st = $miss && (in_array('JPEG', $miss, true) || str_starts_with($miss[0], 'FreeType')) ? 'error' : ($miss ? 'warn' : ($txt ? 'ok' : 'error'));
        return diag_item($G, 'GD a písma', $st, ($gi['GD Version'] ?? '?') . ', písem: ' . count($fonts) . ($txt ? ', test textu OK' : ''),
            $miss ? 'Chybí: ' . implode(', ', $miss) . '.' : ($txt ? '' : 'Vykreslení textu selhalo – slidy by byly bez textu.'));
    });
    $safe($G, 'Paměť pro obrázky', function () use ($G) {
        $lim = diag_bytes((string)ini_get('memory_limit'));
        $raised = false;
        if ($lim !== -1 && $lim < 512 << 20) {
            $old = ini_get('memory_limit');
            $raised = @ini_set('memory_limit', '512M') !== false && diag_bytes((string)ini_get('memory_limit')) >= 512 << 20;
            if ($raised) @ini_set('memory_limit', (string)$old);
        }
        $eff = $lim === -1 ? PHP_INT_MAX : ($raised ? 512 << 20 : $lim);
        $mpx = $eff === PHP_INT_MAX ? 999 : (int)floor(($eff - (24 << 20)) / 5 / 1e6 / 1.3);
        $val = ($lim === -1 ? 'bez omezení' : round($lim / 1048576) . ' MB') . ($raised ? ' (aplikace si umí zvýšit na 512 MB)' : '') . " · obrázek do cca $mpx Mpx";
        return diag_item($G, 'Paměť pro obrázky', $mpx >= 24 ? 'ok' : ($mpx >= 12 ? 'warn' : 'error'), $val,
            $mpx >= 24 ? '' : "Větší obrázky (např. fotky z mobilu ~12–50 Mpx) se nemusí podařit zkomprimovat. Zmenšete je před nahráním nebo zvyšte memory_limit.");
    });
    $add(diag_item($G, 'Časový limit skriptu', 'info', ((int)ini_get('max_execution_time') ? (int)ini_get('max_execution_time') . ' s' : 'bez omezení'), 'Aplikace si u dlouhých úloh (synchronizace, zálohy) limit sama prodlužuje.'));
    $add(diag_item($G, 'Časové pásmo', date_default_timezone_get() === 'Europe/Prague' ? 'ok' : 'warn', date_default_timezone_get() . ' · ' . date('j. n. Y H:i'),
        date_default_timezone_get() === 'Europe/Prague' ? '' : 'Plán by běžel v jiném čase. V config.php nastavte timezone na Europe/Prague.'));

    // ---------- Úložiště ----------
    $G = 'Úložiště';
    foreach ([DATA_DIR => 'data', MEDIA_DIR => 'data/media', TMP_DIR => 'data/tmp'] as $d => $label) {
        $ok = is_dir($d) && is_writable($d);
        $add(diag_item($G, "Zápis do $label", $ok ? 'ok' : 'error', $ok ? 'ano' : 'ne', $ok ? '' : "Složka $label musí být zapisovatelná pro PHP (práva 775)."));
    }
    $safe($G, 'Místo na disku', function () use ($G) {
        $free = function_exists('disk_free_space') ? @disk_free_space(DATA_DIR) : false; // Wedos funkci zakazuje
        $media = diag_dir_size(MEDIA_DIR);
        $bk = diag_dir_size(DATA_DIR . '/backups');
        $val = 'galerie ' . fmt_size_short($media) . ', zálohy ' . fmt_size_short($bk) . ($free !== false ? ', volno ' . fmt_size_short((int)$free) : ' (volné místo hosting nesděluje)');
        $st = $free === false ? 'info' : ($free < 200 << 20 ? 'error' : ($free < 1 << 30 ? 'warn' : 'ok'));
        return diag_item($G, 'Místo na disku', $st, $val, $st === 'ok' || $st === 'info' ? '' : 'Na serveru dochází místo – smažte nepoužívaná média nebo staré zálohy.');
    });
    $safe($G, 'Ochrana dat', function () use ($G) {
        $base = rtrim((string)cfg('base_url'), '/');
        if (!preg_match('~^https?://~', $base)) return diag_item($G, 'Ochrana dat', 'warn', 'nelze ověřit', 'V config.php chybí base_url.');
        $probe = 'diag-' . bin2hex(random_bytes(6)) . '.txt';
        @file_put_contents(TMP_DIR . "/$probe", 'TWB');
        $codes = ['data/' => diag_http_code("$base/data/tmp/$probe"), 'lib/' => diag_http_code("$base/lib/version.php")];
        @unlink(TMP_DIR . "/$probe");
        if (in_array(null, $codes, true)) return diag_item($G, 'Ochrana dat', 'info', 'nelze ověřit z tohoto serveru', '');
        $open = array_keys(array_filter($codes, fn($c) => $c === 200));
        return diag_item($G, 'Ochrana dat', $open ? 'error' : 'ok', $open ? 'přístupné z internetu: ' . implode(', ', $open) : 'data/ (média, zálohy) a lib/ nejsou přístupné z internetu',
            $open ? 'Zkontrolujte, že se na server nahrály soubory .htaccess (jsou skryté).' : '');
    });

    $safe($G, 'Zápis do souborů aplikace', function () use ($G) {
        require_once APP_DIR . '/lib/update.php';
        $bad = update_unwritable();
        return diag_item($G, 'Zápis do souborů aplikace', $bad ? 'warn' : 'ok', $bad ? 'nelze: ' . implode(', ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? '…' : '') : 'ano – aktualizace se může nainstalovat sama',
            $bad ? 'Automatická aktualizace nepůjde; aplikaci aktualizujte přes FTP, nebo nastavte práva souborů na 664 a složek na 775.' : '');
    });

    // ---------- Databáze ----------
    $G = 'Databáze';
    $safe($G, 'Databáze', function () use ($G) {
        $ver = is_sqlite() ? 'SQLite ' . q('select sqlite_version()')->fetchColumn() : (string)q('SELECT VERSION()')->fetchColumn();
        $sv = (int)setting('schema_version', 1);
        return diag_item($G, 'Databáze', $sv === SCHEMA_VERSION ? 'ok' : 'error', "$ver · verze dat $sv" . ($sv === SCHEMA_VERSION ? '' : ' (aplikace čeká ' . SCHEMA_VERSION . ')'),
            $sv === SCHEMA_VERSION ? '' : 'Převod databáze neproběhl. Otevřete úvodní stránku aplikace, převod se spustí sám; jinak se podívejte do Protokolu.');
    });
    $safe($G, 'Obsah', function () use ($G) {
        $c = fn($t) => (int)q("SELECT COUNT(*) FROM $t")->fetchColumn();
        return diag_item($G, 'Obsah', 'info', $c('media') . ' médií, ' . $c('scenarios') . ' scénářů, ' . $c('data_rows') . ' řádků dat, ' . $c('logs') . ' záznamů protokolu');
    });
    $safe($G, 'Soubory galerie', function () use ($G) {
        $miss = [];
        foreach (q("SELECT original_name, filename FROM media WHERE kind IN ('image', 'video', 'pdf') AND filename <> ''")->fetchAll() as $m) {
            if (!is_file(MEDIA_DIR . '/' . $m['filename'])) $miss[] = $m['original_name'];
        }
        return diag_item($G, 'Soubory galerie', $miss ? 'error' : 'ok', $miss ? 'chybí ' . count($miss) . ': ' . implode(', ', array_slice($miss, 0, 5)) . (count($miss) > 5 ? '…' : '') : 'všechny na místě',
            $miss ? 'Tyto položky se nedostanou na obrazovku. Nahraďte jim soubor (Upravit → Nahradit soubor) nebo je obnovte ze zálohy.' : '');
    });

    // ---------- Cron a synchronizace ----------
    $G = 'Cron a synchronizace';
    $last = (int)setting('last_run_at', 0);
    $age = $last ? time() - $last : PHP_INT_MAX;
    $add(diag_item($G, 'Poslední běh cronu', $age < 15 * 60 ? 'ok' : ($age < 60 * 60 ? 'warn' : 'error'), diag_ago($last ?: null),
        $age < 15 * 60 ? '' : 'Cron má běžet každých 5 minut (Wedos → Cron, adresa v Nastavení → Systém). Bez něj se obrazovka nemění.'));
    $err = setting('last_error');
    $add(diag_item($G, 'Poslední synchronizace', $err ? 'error' : 'ok', $err ? (string)$err : 'bez chyby', $err ? 'Podrobnosti v Protokolu.' : ''));

    // ---------- Google Disk ----------
    $G = 'Google Disk';
    $ds = drive_status();
    if ($ds['mode'] === 'mock') {
        $add(diag_item($G, 'Režim', 'warn', 'testovací režim (drive_mock)', 'Soubory se nekopírují na Google Disk, ale do data/mock-drive. Pro ostrý provoz vypněte drive_mock v config.php.'));
    } elseif (!$ds['configured']) {
        $add(diag_item($G, 'Připojení', 'error', 'chybí přihlašovací údaje Google', 'Doplňte google_client_id a google_client_secret do config.php.'));
    } elseif (!$ds['connected']) {
        $add(diag_item($G, 'Připojení', 'error', 'nepřipojeno', 'Nastavení → Google Disk → Připojit Google Disk.'));
    } else {
        $add(diag_item($G, 'Připojení', 'ok', 'připojeno' . (!empty($ds['account']) ? ' jako ' . $ds['account'] : '')));
    }
    $fid = (string)setting('folder_id', '');
    if (!$fid) {
        $add(diag_item($G, 'Cílová složka', 'error', 'není nastavena', 'Nastavení → Google Disk → Cílová složka.'));
    } elseif ($ds['mode'] !== 'mock' && $ds['connected']) {
        $safe($G, 'Cílová složka', function () use ($G, $fid) {
            $j = gapi('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fid) . '?' . http_build_query([
                'fields' => 'name,trashed,capabilities(canAddChildren,canRemoveChildren,canDeleteChildren,canTrashChildren)', 'supportsAllDrives' => 'true']))['json'];
            $cap = $j['capabilities'] ?? [];
            $okAdd = !empty($cap['canAddChildren']);
            $okRm = !empty($cap['canRemoveChildren']) || !empty($cap['canTrashChildren']) || !empty($cap['canDeleteChildren']);
            $st = !empty($j['trashed']) || !$okAdd ? 'error' : ($okRm ? 'ok' : 'warn');
            return diag_item($G, 'Cílová složka', $st, ($j['name'] ?? '?') . ' · přidávat: ' . ($okAdd ? 'ano' : 'ne') . ', odebírat: ' . ($okRm ? 'ano' : 'ne'),
                !empty($j['trashed']) ? 'Složka je v koši Google Disku.' : (!$okAdd ? 'Připojený účet nemá do složky právo zápisu – sdílejte ji s ním jako Editor.' : (!$okRm ? 'Účet nemůže ze složky odebírat soubory – staré soubory by na obrazovce zůstaly.' : '')));
        });
        $safe($G, 'Soubory ve složce', function () use ($G, $fid) {
            $files = [];
            $tok = null;
            do {
                $p = ['q' => "'" . str_replace("'", "\\'", $fid) . "' in parents and trashed = false and mimeType != '" . FOLDER_MIME . "'",
                      'fields' => 'nextPageToken, files(name,ownedByMe,capabilities(canTrash,canDelete,canRemoveMyDriveParent))', 'pageSize' => 1000,
                      'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true'];
                if ($tok) $p['pageToken'] = $tok;
                $j = gapi('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($p))['json'];
                array_push($files, ...($j['files'] ?? []));
                $tok = $j['nextPageToken'] ?? null;
            } while ($tok);
            $foreign = array_values(array_filter($files, fn($f) => empty($f['ownedByMe'])));
            $stuck = array_values(array_filter($foreign, fn($f) => empty($f['capabilities']['canTrash']) && empty($f['capabilities']['canDelete'])));
            $n = count($files);
            $val = $n . ' ' . ($n === 1 ? 'soubor' : ($n >= 2 && $n <= 4 ? 'soubory' : 'souborů')) . ($foreign ? ', z toho ' . count($foreign) . ' patří jinému účtu' : '');
            return diag_item($G, 'Soubory ve složce', $stuck ? 'info' : 'ok', $val,
                $stuck ? 'Soubory jiného účtu nejde smazat ani dát do koše; aplikace je při úklidu jen vyjme ze složky (oprava chyby 403): ' . implode(', ', array_slice(array_column($stuck, 'name'), 0, 4)) . (count($stuck) > 4 ? '…' : '') . '.' : '');
        });
    }

    // ---------- Zálohy ----------
    $G = 'Zálohy';
    $sup = backup_supported();
    $add(diag_item($G, 'Šifrovaný ZIP (AES)', $sup ? 'error' : 'ok', $sup ? 'nepodporováno' : 'podporováno' . (defined('ZipArchive::LIBZIP_VERSION') ? ' (libzip ' . ZipArchive::LIBZIP_VERSION . ')' : ''), $sup ?: ''));
    if (!$sup) {
        $safe($G, 'Zkušební záloha', function () use ($G) {
            $f = TMP_DIR . '/diag-' . bin2hex(random_bytes(4)) . '.zip';
            $z = new ZipArchive();
            $z->open($f, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $z->setPassword('diag-heslo');
            $z->addFromString('test.txt', 'TWB');
            $z->setEncryptionName('test.txt', ZipArchive::EM_AES_256, 'diag-heslo');
            $z->close();
            $r = new ZipArchive();
            $ok = $r->open($f) === true && $r->setPassword('diag-heslo') && $r->getFromName('test.txt') === 'TWB';
            $r->close();
            $bad = new ZipArchive();
            $bad->open($f); $bad->setPassword('spatne');
            $leak = @$bad->getFromName('test.txt') === 'TWB';
            $bad->close();
            @unlink($f);
            return diag_item($G, 'Zkušební záloha', $ok && !$leak ? 'ok' : 'error', $ok && !$leak ? 'zašifrovat a otevřít heslem jde' : 'selhala',
                $ok && !$leak ? '' : 'Šifrování ZIP na serveru nefunguje správně – zálohy by nebyly bezpečné.');
        });
    }
    $pw = (string)setting('backup_password', '') !== '';
    $add(diag_item($G, 'Heslo záloh', $pw ? 'ok' : 'warn', $pw ? 'nastaveno' : 'není', $pw ? '' : 'Bez hesla se nezálohuje. Nastavení → Zálohy.'));
    $lb = (int)setting('last_backup_at', 0);
    $bAge = $lb ? time() - $lb : PHP_INT_MAX;
    $auto = setting('backup_auto', '1') === '1';
    $add(diag_item($G, 'Poslední záloha', !$pw || !$auto ? 'info' : ($bAge < 26 * 3600 ? 'ok' : ($bAge < 3 * 86400 ? 'warn' : 'error')), diag_ago($lb ?: null) . ($auto ? '' : ' · automatické zálohy vypnuté'),
        $pw && $auto && $bAge >= 26 * 3600 ? 'Denní záloha neproběhla – zkontrolujte cron a Protokol.' : ''));

    // ---------- Synology ----------
    $G = 'Synology a přehrávač';
    $poll = (int)setting('player_poll_at', 0);
    $pAge = $poll ? time() - $poll : PHP_INT_MAX;
    $add(diag_item($G, 'Skript na Synology', !$poll ? 'warn' : ($pAge < 10 * 60 ? 'ok' : ($pAge < 60 * 60 ? 'warn' : 'error')), $poll ? 'naposledy se ozval ' . diag_ago($poll) : 'zatím se neozval',
        !$poll ? 'Nastavení → Přehrávač: vložte skript do Plánovače úloh na Synology.' : ($pAge < 10 * 60 ? '' : 'Synology se neozývá – je zapnutý a připojený k internetu? Běží úloha v Plánovači úloh?')));
    $chg = (int)setting('last_change_at', 0);
    $done = (int)setting('player_done', 0);
    if ($chg) $add(diag_item($G, 'Spuštění přehrávače', $done >= $chg ? 'ok' : (time() - $chg > 10 * 60 ? 'warn' : 'info'), $done >= $chg ? 'poslední změna doručena' : 'poslední změna (' . diag_ago($chg) . ') zatím nedoručena',
        $done >= $chg || time() - $chg <= 10 * 60 ? '' : 'Synology nedokázal spustit synchronizaci přehrávače – je přehrávač zapnutý a je ve skriptu správné heslo?'));
    $nas = (int)setting('backup_nas_at', 0);
    if ($pw) $add(diag_item($G, 'Zálohy na Synology', !$nas ? 'warn' : (time() - $nas < 3 * 3600 ? 'ok' : 'warn'), $nas ? 'naposledy se ptal ' . diag_ago($nas) : 'zatím se neptal',
        !$nas ? 'Vložte na Synology nový skript (verze s funkcí pull_backups) – karta Přehrávač.' : (time() - $nas < 3 * 3600 ? '' : 'Synology si zálohy delší dobu nestahuje.')));

    // ---------- Zdroje dat ----------
    $G = 'Zdroje dat';
    foreach (sources_all(true) as $s) {
        $okAt = $s['ok_at'] ? strtotime($s['ok_at']) : 0;
        $add(diag_item($G, $s['name'] . ' (' . strtoupper($s['format'] ?? 'json') . ')', $s['error'] ? ($okAt && time() - $okAt < 86400 ? 'warn' : 'error') : ($okAt ? 'ok' : 'warn'),
            $s['error'] ? 'chyba: ' . $s['error'] : ($okAt ? 'staženo ' . diag_ago($okAt) : 'zatím nestaženo'),
            $s['error'] ? 'Slidy používají poslední stažená data' . ($okAt ? ' z ' . date('j. n. H:i', $okAt) : '') . '.' : ($okAt ? '' : 'Stáhne se při nejbližším běhu cronu, nebo hned tlačítkem „Stáhnout teď“ u zdroje.')));
    }

    // ---------- Aktualizace ----------
    $G = 'Aktualizace';
    $safe($G, 'TWB Update Server', function () use ($G, $add) {
        require_once APP_DIR . '/lib/update.php';
        $st = update_status();
        if ($st['unsupported']) return diag_item($G, 'TWB Update Server', 'warn', 'nenastaveno', $st['unsupported']);
        $add(diag_item($G, 'Token', 'ok', $st['token']));
        $r = $st['result'] ?? null;
        $bad = $r && in_array($r['result'], ['auth_error', 'signature_error', 'server_error', 'config_error'], true);
        $age = $st['checked_at'] ? time() - $st['checked_at'] : PHP_INT_MAX;
        $val = 'kontrola ' . diag_ago($st['checked_at']) . ($r ? ': ' . $r['message'] : '');
        return diag_item($G, 'Poslední kontrola', $bad ? 'error' : ($age > 2 * 86400 ? 'warn' : 'ok'), $val,
            $bad ? 'Nastavení → Systém → Zjistit novou verzi; podrobnosti v Protokolu.' : ($age > 2 * 86400 ? 'Kontrola běží jednou denně z cronu.' : ''));
    });

    // ---------- Upozornění ----------
    $G = 'Upozornění';
    $mail = (string)setting('notify_email', '');
    $sms = setting('notify_phone') && setting('bulkgate_app_id') && setting('bulkgate_token');
    $add(diag_item($G, 'Kam chodí upozornění', $mail || $sms ? 'ok' : 'warn', implode(', ', array_filter([$mail ? "e-mail $mail" : '', $sms ? 'SMS' : ''])) ?: 'nikam',
        $mail || $sms ? 'Funkčnost ověříte tlačítkem na kartě Upozornění.' : 'Při chybě synchronizace se nikdo nedozví. Nastavení → Upozornění.'));
    return $out;
}
