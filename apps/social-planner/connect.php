<?php
declare(strict_types=1);
require __DIR__ . '/app/lib/bootstrap.php';
require __DIR__ . '/app/lib/graph.php';
require_login();

$redirect = rtrim(cfg('base_url'), '/') . '/connect.php';
$ver = cfg('graph_version', 'v23.0');

// 1) Přesměrování na přihlášení k Metě
if (!isset($_GET['code'])) {
    $state = bin2hex(random_bytes(12));
    $_SESSION['oauth_state'] = $state;
    $scopes = implode(',', [
        'pages_show_list', 'pages_read_engagement', 'pages_manage_posts',
        'instagram_basic', 'instagram_content_publish', 'business_management',
        'pages_read_user_content', 'pages_manage_engagement', 'read_insights', 'instagram_manage_comments', 'instagram_manage_insights',
    ]);
    $params = ['client_id' => cfg('app_id'), 'redirect_uri' => $redirect, 'state' => $state, 'response_type' => 'code'];
    $configId = trim((string)cfg('login_config_id', ''));
    if ($configId !== '') {
        // Facebook Login for Business: oprávnění jsou v „konfiguraci“ vytvořené v Metě, parametr scope se nepoužívá
        $params['config_id'] = $configId;
        $params['override_default_response_type'] = 'true';
    } else {
        $params['scope'] = $scopes;
    }
    header('Location: https://www.facebook.com/' . $ver . '/dialog/oauth?' . http_build_query($params));
    exit;
}

// 2) Návrat z Mety
if (!hash_equals($_SESSION['oauth_state'] ?? '', (string)($_GET['state'] ?? ''))) {
    exit('Neplatný state.');
}
unset($_SESSION['oauth_state']);
if (isset($_GET['error'])) { exit('Přihlášení zrušeno: ' . h((string)($_GET['error_description'] ?? $_GET['error']))); }

try {
    $short = graph('GET', 'oauth/access_token', [
        'client_id' => cfg('app_id'), 'client_secret' => cfg('app_secret'),
        'redirect_uri' => $redirect, 'code' => $_GET['code'],
    ]);
    $long = graph('GET', 'oauth/access_token', [
        'grant_type' => 'fb_exchange_token', 'client_id' => cfg('app_id'),
        'client_secret' => cfg('app_secret'), 'fb_exchange_token' => $short['access_token'],
    ]);
    // Tokeny stránek získané z dlouhodobého uživatelského tokenu nevyprší.
    $pages = graph('GET', 'me/accounts', [
        'access_token' => $long['access_token'], 'limit' => 100,
        'fields' => 'id,name,access_token,instagram_business_account{id,username}',
    ]);
} catch (Throwable $e) {
    exit('Chyba při propojení: ' . h($e->getMessage()));
}

$pdo = db();
$ins = $pdo->prepare("INSERT INTO accounts (platform, remote_id, name, page_id, token, created_at)
    VALUES (?,?,?,?,?,?)
    ON CONFLICT(platform, remote_id) DO UPDATE SET name=excluded.name, page_id=excluded.page_id, token=excluded.token, health='ok', health_msg=NULL, health_at=excluded.created_at");
$count = 0;
foreach ($pages['data'] ?? [] as $p) {
    $ins->execute(['facebook', $p['id'], $p['name'], $p['id'], $p['access_token'], time()]);
    $count++;
    if (!empty($p['instagram_business_account']['id'])) {
        $ig = $p['instagram_business_account'];
        $ins->execute(['instagram', $ig['id'], '@' . ($ig['username'] ?? $ig['id']), $p['id'], $p['access_token'], time()]);
        $count++;
    }
}
// tokeny stránek právě vrátil Graph API → platné
$pdo->prepare("UPDATE accounts SET health = 'ok', health_at = ? WHERE platform IN ('facebook','instagram') AND health IS NULL")->execute([time()]);
try { refresh_avatars($pdo, true); } catch (Throwable $e) { /* profilovky nejsou nutné */ }
header('Location: ucty.php?connected=' . $count);
