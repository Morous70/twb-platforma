<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_login();

$redirect = rtrim((string)cfg('base_url'), '/') . '/threads_connect.php';
$appId = trim((string)cfg('threads_app_id', '')); $secret = trim((string)cfg('threads_app_secret', ''));
if ($appId === '' || $secret === '') {
    exit('V app/config.php chybí <code>threads_app_id</code> a <code>threads_app_secret</code> (Meta → vaše aplikace → případ použití Threads → Nastavení). <a href="ucty.php">Zpět</a>');
}

// 1) přesměrování na přihlášení k Threads
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    $state = bin2hex(random_bytes(12));
    $_SESSION['threads_state'] = $state;
    $q = http_build_query(['client_id' => $appId, 'redirect_uri' => $redirect, 'scope' => 'threads_basic,threads_content_publish,threads_manage_insights,threads_manage_replies', 'response_type' => 'code', 'state' => $state]);
    header('Location: https://threads.com/oauth/authorize?' . $q);
    exit;
}

// 2) návrat
if (!hash_equals($_SESSION['threads_state'] ?? '', (string)($_GET['state'] ?? ''))) exit('Neplatný state.');
unset($_SESSION['threads_state']);
if (isset($_GET['error'])) exit('Přihlášení zrušeno: ' . h((string)($_GET['error_description'] ?? $_GET['error'])));

try {
    $code = explode('#', (string)$_GET['code'])[0];
    $short = threads_api('POST', 'oauth/access_token', [
        'client_id' => $appId, 'client_secret' => $secret, 'grant_type' => 'authorization_code',
        'redirect_uri' => $redirect, 'code' => $code,
    ], false);
    $long = threads_api('GET', 'access_token', ['grant_type' => 'th_exchange_token', 'client_secret' => $secret, 'access_token' => $short['access_token']], false);
    $token = $long['access_token'] ?? $short['access_token'];
    $me = threads_api('GET', 'me', ['access_token' => $token, 'fields' => 'id,username,name']);
} catch (Throwable $e) {
    exit('Chyba při propojení Threads: ' . h($e->getMessage()));
}

$pdo = db();
$uid = (string)($me['id'] ?? $short['user_id'] ?? '');
if ($uid === '') exit('Threads nevrátily ID účtu.');
$name = '@' . ($me['username'] ?? $uid);
$pdo->prepare("INSERT INTO accounts (platform, remote_id, name, page_id, token, created_at, token_expires)
               VALUES ('threads', ?, ?, ?, ?, ?, ?)
               ON CONFLICT(platform, remote_id) DO UPDATE SET name=excluded.name, token=excluded.token, token_expires=excluded.token_expires, health='ok', health_msg=NULL, health_at=excluded.created_at")
    ->execute([$uid, $name, $uid, $token, time(), time() + (int)($long['expires_in'] ?? 5184000)]);
// nově vložený účet: token byl právě ověřen voláním /me
$pdo->prepare("UPDATE accounts SET health = 'ok', health_at = ? WHERE platform = 'threads' AND remote_id = ? AND health IS NULL")->execute([time(), $uid]);
try { refresh_avatars($pdo, true); } catch (Throwable $e) { /* profilovka není nutná */ }
header('Location: ucty.php?connected=1');
