<?php
declare(strict_types=1);
// Verze aplikace. Při vydání zvyšte číslo a zapište změny do app/CHANGELOG.md.
// Schéma MAJOR.MINOR.PATCH: PATCH = opravy, MINOR = nové funkce, MAJOR = nekompatibilní změny (např. struktura).

const APP_VERSION = '1.9.2';
const APP_NAME    = 'Social Planner';

// Soubory z dřívějších verzí, které po přestavbě struktury (1.0.0) zůstaly v kořeni webu a už se nepoužívají.
// Mažou se jednorázově po nahrání nové verze, a jen pokud opravdu existují. Nic jiného se nemaže.
const APP_OBSOLETE_FILES = [
    'common.js', 'custom.css', 'bezpecnost.js', 'hromadne.js', 'integrace.js', 'kalendar.js', 'knihovna.js',
    'novy.js', 'oznameni.js', 'prehled.js', 'promenne.js', 'seznam.js', 'skupiny.js', 'statistiky.js',
    'ucty.js', 'webauthn.js', 'zaloha.js',
    'README.md',  // přesunuto do app/
    'diag.php',   // dočasná diagnostika, nemá na hostingu zůstat
];

/** Po nahrání nové verze: uklidí zastaralé soubory a zapíše verzi do databáze. Volá se z db(). */
function app_upgrade(PDO $pdo): void {
    $cur = $pdo->query("SELECT value FROM meta WHERE key = 'app_version'")->fetchColumn();
    if ($cur === APP_VERSION) return;
    $removed = [];
    foreach (APP_OBSOLETE_FILES as $f) {
        $p = PUBLIC_DIR . '/' . $f;
        if (is_file($p) && @unlink($p)) $removed[] = $f;
    }
    $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('app_version', ?)")->execute([APP_VERSION]);
    $pdo->prepare("INSERT OR REPLACE INTO meta (key, value) VALUES ('app_updated_at', ?)")->execute([(string)time()]);
    log_line('Aktualizace na verzi ' . APP_VERSION . ($cur ? " (z $cur)" : '') . ($removed ? '; smazány zastaralé soubory: ' . implode(', ', $removed) : ''));
}
