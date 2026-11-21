<?php
declare(strict_types=1);
// Zálohy: konzistentní snímek SQLite (VACUUM INTO) + volitelně nahrané soubory.

function backup_dir(): string {
    $d = ROOT . '/data/backups';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (is_dir($d) && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    return $d;
}

/** Vytvoří snímek databáze do souboru $dest. */
function backup_db_snapshot(PDO $pdo, string $dest): void {
    @unlink($dest);
    $pdo->exec('VACUUM INTO ' . $pdo->quote($dest));
}

function backup_uploads_list(): array {
    $dir = PUBLIC_DIR . '/uploads';
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f[0] === '.' || !is_file("$dir/$f")) continue;
        $out[] = $f;
    }
    return $out;
}

/** Zip s databází a (volitelně) uploady. Vrací cestu k souboru, nebo vyhodí výjimku. */
function backup_make_zip(PDO $pdo, bool $withUploads): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('Na hostingu chybí rozšíření ZipArchive.');
    $tmpDb = tempnam(sys_get_temp_dir(), 'spdb');
    backup_db_snapshot($pdo, $tmpDb);
    $zipPath = tempnam(sys_get_temp_dir(), 'spzip');
    $z = new ZipArchive();
    if ($z->open($zipPath, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nelze vytvořit zip.');
    $z->addFile($tmpDb, 'planner.sqlite');
    $n = 0;
    if ($withUploads) foreach (backup_uploads_list() as $f) { $z->addFile(PUBLIC_DIR . '/uploads/' . $f, 'uploads/' . $f); $n++; }
    // 1.6.0: vlastní písma slidů (app/data/fonts) – jsou malá, ukládají se vždy
    foreach (array_merge(glob(ROOT . '/data/fonts/u_*.ttf') ?: [], glob(ROOT . '/data/fonts/u_*.otf') ?: []) as $f) $z->addFile($f, 'fonts/' . basename($f));
    $z->addFromString('README.txt', "Záloha plánovače z " . date('c') . "\nplanner.sqlite = databáze (obsahuje přístupové tokeny účtů – uchovejte v bezpečí).\nObnova: nahraďte app/data/planner.sqlite, složku uploads/ v kořeni webu a obsah fonts/ zkopírujte do app/data/fonts/.\n");
    $z->close();
    @unlink($tmpDb);
    return $zipPath;
}

/** Automatická záloha databáze do data/backups (max. $keep nejnovějších). */
function backup_auto(PDO $pdo, int $keep = 4): string {
    $file = backup_dir() . '/planner-' . date('Ymd-His') . '.sqlite';
    backup_db_snapshot($pdo, $file);
    $all = glob(backup_dir() . '/planner-*.sqlite') ?: [];
    rsort($all);
    foreach (array_slice($all, $keep) as $old) @unlink($old);
    return $file;
}

/** Jednou za 7 dní, pokud je zapnuto (meta backup_auto = 1). */
function backup_if_due(PDO $pdo): void {
    if (function_exists('gd_if_due')) gd_if_due($pdo);   // záloha na Google Drive má vlastní plán
    if (meta_get('backup_auto', '1') !== '1') return;
    $last = (int)meta_get('backup_last', '0');
    if (time() - $last < 7 * 86400) return;
    try { backup_auto($pdo); meta_set('backup_last', (string)time()); } catch (Throwable $e) { /* příště znovu */ }
}
