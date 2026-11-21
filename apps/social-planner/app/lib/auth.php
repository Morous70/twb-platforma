<?php
declare(strict_types=1);
// Přihlášení: heslo (config nebo databáze), zámek pokusů, obnova hesla, odhlášení po nečinnosti.
require_once __DIR__ . '/notify.php';

const AUTH_MAX_FAILS = 5;
const AUTH_LOCK_SECONDS = 300;

function auth_token_create(PDO $pdo, string $purpose, int $ttl, ?string $raw = null): string {
    $pdo->prepare("DELETE FROM auth_tokens WHERE expires < ?")->execute([time()]);
    $raw = $raw ?? bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO auth_tokens (purpose, token_hash, expires) VALUES (?,?,?)")->execute([$purpose, hash('sha256', $raw), time() + $ttl]);
    return $raw;
}
function auth_token_use(PDO $pdo, string $purpose, string $raw): bool {
    $st = $pdo->prepare("UPDATE auth_tokens SET used = 1 WHERE purpose = ? AND token_hash = ? AND used = 0 AND expires >= ?");
    $st->execute([$purpose, hash('sha256', $raw), time()]);
    return $st->rowCount() === 1;
}

function auth_password_hash(): string { return (string)(meta_get('password_hash', '') ?: cfg('admin_hash', '')); }

/** Zbývající vteřiny zámku (0 = nezamčeno). Zámek je v databázi, platí pro všechny způsoby přihlášení. */
function auth_lock_remaining(): int {
    $fails = (int)meta_get('login_fails', '0');
    if ($fails < AUTH_MAX_FAILS) return 0;
    $left = (int)meta_get('login_last_fail', '0') + AUTH_LOCK_SECONDS - time();
    if ($left <= 0) { meta_set('login_fails', '0'); return 0; }
    return $left;
}
function auth_fail(string $why): void {
    $n = (int)meta_get('login_fails', '0') + 1;
    meta_set('login_fails', (string)$n); meta_set('login_last_fail', (string)time());
    log_line("PŘIHLÁŠENÍ selhalo ($why), pokus $n" . ($n >= AUTH_MAX_FAILS ? ' – zámek na 5 minut' : ''));
}
function auth_login(string $how): void {
    session_start_safe();
    session_regenerate_id(true);
    $_SESSION['auth'] = true; $_SESSION['auth_last'] = time();
    meta_set('login_fails', '0');
    log_line("PŘIHLÁŠENÍ: $how");
}

function auth_idle_minutes(): int { return max(0, min(1440, (int)meta_get('idle_minutes', '0'))); }

/** Odhlásí po nečinnosti; jinak obnoví čas aktivity. */
function auth_check_idle(): void {
    if (empty($_SESSION['auth'])) return;
    $idle = auth_idle_minutes();
    $last = (int)($_SESSION['auth_last'] ?? time());
    if ($idle > 0 && time() - $last > $idle * 60) {
        unset($_SESSION['auth']);
        $_SESSION['login_error'] = 'Byli jste odhlášeni z důvodu nečinnosti.';
        log_line('ODHLÁŠENÍ pro nečinnost');
        return;
    }
    $_SESSION['auth_last'] = time();
}

/** Odkaz pro obnovu hesla pošle jen na nastavený e-mail; odpověď je vždy stejná. */
function auth_send_reset(PDO $pdo, string $email): void {
    $conf = notify_settings()['email'];
    if ($conf === '' || strcasecmp(trim($email), $conf) !== 0) { log_line('OBNOVA HESLA: neshoda e-mailu'); return; }
    if (time() - (int)meta_get('reset_last_sent', '0') < 120) return;
    meta_set('reset_last_sent', (string)time());
    $pdo->prepare("DELETE FROM auth_tokens WHERE purpose = 'reset'")->execute();
    $tok = auth_token_create($pdo, 'reset', 1800);
    send_mail('Plánovač: obnova hesla', "Nové heslo nastavíte na odkazu (platí 30 minut, jen jednou):\n" . rtrim((string)cfg('base_url'), '/') . "/login.php?reset=$tok\n\nPokud jste o obnovu nežádali, e-mail ignorujte.\n");
}
