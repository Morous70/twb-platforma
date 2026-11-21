<?php
declare(strict_types=1);
require_once __DIR__ . '/linkmeta.php';
// Profilové obrázky účtů: stáhnou se z Graph API a uloží do public/uploads/avatars/ID.jpg

function avatar_dir(): string {
    $d = PUBLIC_DIR . '/uploads/avatars';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    return $d;
}

function fetch_avatar(array $acc): ?string {
    $tok = ['access_token' => $acc['token']];
    if ($acc['platform'] === 'threads') {
        $r = threads_api('GET', 'me', $tok + ['fields' => 'threads_profile_picture_url']);
        return $r['threads_profile_picture_url'] ?? null;
    }
    if ($acc['platform'] === 'facebook') {
        $r = graph('GET', $acc['remote_id'] . '/picture', $tok + ['redirect' => 'false', 'type' => 'large']);
        return $r['data']['url'] ?? null;
    }
    $r = graph('GET', $acc['remote_id'], $tok + ['fields' => 'profile_picture_url']);
    return $r['profile_picture_url'] ?? null;
}

/** Obnoví profilovky; $force = všechny, jinak jen chybějící nebo starší 7 dní. */
function refresh_avatars(PDO $pdo, bool $force = false): int {
    $n = 0;
    $up = $pdo->prepare("UPDATE accounts SET avatar_at = ? WHERE id = ?");
    foreach ($pdo->query("SELECT * FROM accounts")->fetchAll() as $acc) {
        if (!$force && $acc['avatar_at'] && time() - (int)$acc['avatar_at'] < 7 * 86400) continue;
        try {
            $url = fetch_avatar($acc);
            if (!$url || !preg_match('#^https://#i', $url)) continue;
            $r = fetch_url($url, 2_000_000);
            if (!preg_match('#^image/#', $r['type']) || $r['body'] === '') continue;
            if (file_put_contents(avatar_dir() . '/' . (int)$acc['id'] . '.jpg', $r['body']) !== false) { $up->execute([time(), $acc['id']]); $n++; }
        } catch (Throwable $e) { /* účet bez obrázku nebo síťová chyba – zkusíme příště */ }
    }
    return $n;
}
