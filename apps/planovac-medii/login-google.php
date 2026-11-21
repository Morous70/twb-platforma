<?php
// Přihlášení přes Google (OpenID Connect). Bez parametrů přesměruje na Google, s ?code= dokončí přihlášení.
// S ?link=1 (jen přihlášený) propojí Google účet, kterým se bude smět přihlašovat.
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/schema.php';
require __DIR__ . '/lib/auth.php';

if (!installed()) { header('Location: install.php'); exit; }
migrate();
start_session();

$returning = isset($_GET['code']) || isset($_GET['error']);
$link = $returning ? !empty($_SESSION['gl_link']) : isset($_GET['link']);
$fail = function (string $msg) use ($link) {
    unset($_SESSION['gl_state'], $_SESSION['gl_nonce'], $_SESSION['gl_link']);
    if ($link) { header('Location: ./#nastaveni/zabezpeceni?google=error'); }
    else { $_SESSION['login_error'] = $msg; header('Location: ./'); }
    exit;
};

if ($link && !is_logged_in()) { header('Location: ./'); exit; }
if (!auth_google_configured() && !cfg('drive_mock')) $fail('V config.php chybí google_client_id a google_client_secret.');

if ($returning) {
    $state = (string)($_SESSION['gl_state'] ?? '');
    $nonce = (string)($_SESSION['gl_nonce'] ?? '');
    if (!$link && auth_lock_remaining() > 0) $fail(auth_lock_message());
    if (isset($_GET['error']) || $state === '' || !hash_equals($state, (string)($_GET['state'] ?? ''))) {
        if (!$link) auth_register_fail('Google');
        $fail('Přihlášení přes Google se nezdařilo.');
    }
    try {
        $c = auth_google_claims((string)$_GET['code'], $nonce);
    } catch (Throwable $e) {
        app_log('error', 'Přihlášení přes Google selhalo: ' . $e->getMessage());
        if (!$link) auth_register_fail('Google');
        $fail('Přihlášení přes Google se nezdařilo.');
    }
    unset($_SESSION['gl_state'], $_SESSION['gl_nonce'], $_SESSION['gl_link']);
    if ($link) {
        set_setting('login_google_sub', $c['sub']);
        set_setting('login_google_email', $c['email']);
        app_log('info', "Google účet pro přihlášení propojen ({$c['email']}).");
        header('Location: ./#nastaveni/zabezpeceni?google=linked');
        exit;
    }
    $sub = (string)setting('login_google_sub', '');
    if ($sub !== '' && hash_equals($sub, $c['sub'])) {
        auth_login('Google (' . $c['email'] . ')');
        header('Location: ./');
        exit;
    }
    auth_register_fail('Google');
    app_log('warning', "Pokus o přihlášení přes Google účtem, který není povolený ({$c['email']}).");
    $fail('Tento Google účet se nesmí přihlásit.');
}

if (!$link && (string)setting('login_google_sub', '') === '') $fail('Přihlášení přes Google není nastavené.');
$_SESSION['gl_state'] = bin2hex(random_bytes(16));
$_SESSION['gl_nonce'] = bin2hex(random_bytes(16));
$_SESSION['gl_link'] = $link ? 1 : 0;
header('Location: ' . auth_google_url($_SESSION['gl_state'], $_SESSION['gl_nonce']));
