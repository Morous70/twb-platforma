<?php
// Připojení Google Disku: bez parametrů přesměruje na Google, s ?code= dokončí připojení.
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/drive.php';

if (!is_logged_in()) { header('Location: ./'); exit; }
start_session();

if (isset($_GET['error'])) { header('Location: ./#nastaveni?google=error'); exit; }

if (isset($_GET['code'])) {
    if (!hash_equals($_SESSION['oauth_state'] ?? '', (string)($_GET['state'] ?? ''))) exit('Neplatný požadavek (state).');
    unset($_SESSION['oauth_state']);
    try {
        oauth_exchange_code($_GET['code']);
        app_log('info', 'Google Disk připojen (' . setting('google_account') . ').');
        header('Location: ./#nastaveni?google=ok');
    } catch (Throwable $e) {
        app_log('error', 'Připojení Google Disku selhalo: ' . $e->getMessage());
        header('Location: ./#nastaveni?google=error');
    }
    exit;
}

if (!cfg('google_client_id')) exit('V config.php chybí google_client_id a google_client_secret.');
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
header('Location: ' . oauth_auth_url($_SESSION['oauth_state']));
