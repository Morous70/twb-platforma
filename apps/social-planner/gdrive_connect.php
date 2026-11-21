<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_login();

if (!gd_configured()) {
    exit('V app/config.php chybí <code>google_client_id</code> a <code>google_client_secret</code> (Google Cloud Console → OAuth klient typu Web application). Postup je v Nápovědě. <a href="zaloha.php">Zpět</a>');
}
// 1) přesměrování na Google
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = bin2hex(random_bytes(12));
    $_SESSION['gd_state'] = $state;
    header('Location: ' . gd_host('google_auth_host') . '/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => cfg('google_client_id'), 'redirect_uri' => gd_redirect(), 'response_type' => 'code', 'scope' => GD_SCOPE,
        'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'include_granted_scopes' => 'false']));
    exit;
}
// 2) návrat
if (!hash_equals($_SESSION['gd_state'] ?? '', (string)($_GET['state'] ?? ''))) exit('Neplatný state. <a href="zaloha.php">Zpět</a>');
unset($_SESSION['gd_state']);
if (isset($_GET['error'])) exit('Přihlášení zrušeno: ' . h((string)$_GET['error']) . ' <a href="zaloha.php">Zpět</a>');
try {
    $t = gd_exchange((string)$_GET['code']);
    if (empty($t['refresh_token'])) throw new RuntimeException('Google nevrátil trvalý přístup (refresh token). Odeberte aplikaci v myaccount.google.com/permissions a propojte znovu.');
    meta_set('gd_refresh', (string)$t['refresh_token']);
    meta_set('gd_access', (string)$t['access_token']); meta_set('gd_access_exp', (string)(time() + (int)($t['expires_in'] ?? 3600)));
    meta_set('gd_folder', ''); meta_set('gd_error', ''); meta_set('gd_email', '');
    try { $a = gd_api('GET', '/drive/v3/about', ['fields' => 'user(emailAddress)']); meta_set('gd_email', (string)($a['user']['emailAddress'] ?? '')); } catch (Throwable $e) { /* e-mail je jen informativní */ }
    if (meta_get('gd_enabled', '') === '') meta_set('gd_enabled', '1');
} catch (Throwable $e) {
    exit('Chyba při propojení Google Drive: ' . h($e->getMessage()) . ' <a href="zaloha.php">Zpět</a>');
}
header('Location: zaloha.php?gd=1');
