<?php
declare(strict_types=1);
// Threads API (Meta). Vlastní aplikace/klíče a vlastní přihlášení, token na 60 dní (obnovuje se).

function threads_host(): string { return 'https://graph.' . cfg('threads_domain', 'threads.com'); }

/** Volání Threads API. $versioned=false pro /oauth/access_token, /access_token, /refresh_access_token. */
function threads_api(string $method, string $path, array $params = [], bool $versioned = true): array {
    if (cfg('dry_run')) {
        log_line("DRY-RUN THREADS $method $path " . json_encode(array_diff_key($params, ['access_token' => 1, 'client_secret' => 1])));
        return ['id' => 'dry_' . bin2hex(random_bytes(4)), 'status' => 'FINISHED', 'access_token' => 'dry_token', 'user_id' => 'dry_user', 'expires_in' => 5184000,
                'username' => 'dry', 'threads_profile_picture_url' => null];
    }
    $url = threads_host() . ($versioned ? '/v1.0/' : '/') . ltrim($path, '/');
    $ch = curl_init();
    if ($method === 'GET') $url .= '?' . http_build_query($params);
    else { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params)); }
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120]);
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Síťová chyba: ' . curl_error($ch));
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Neplatná odpověď Threads API');
    if (isset($data['error'])) {
        $e = $data['error'];
        throw new RuntimeException((is_array($e) ? ($e['message'] ?? 'Chyba Threads API') : (string)$e) . ' (code ' . (is_array($e) ? ($e['code'] ?? '?') : '?') . ')');
    }
    return $data;
}

/** Počká, až Threads zpracuje kontejner s médiem. */
function threads_wait(string $id, array $tok): void {
    $start = time();
    while (time() - $start < 120) {
        try {
            $r = threads_api('GET', $id, $tok + ['fields' => 'status,error_message']);
            $st = $r['status'] ?? 'FINISHED';
            if ($st === 'FINISHED' || $st === 'PUBLISHED') return;
            if ($st === 'ERROR' || $st === 'EXPIRED') throw new RuntimeException('Threads odmítly médium: ' . ($r['error_message'] ?? $st));
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Threads odmítly')) throw $e;   // chybu stavu ignorujeme, zkusíme znovu
        }
        sleep(3);
    }
}

function threads_publish_target(array $post, array $acc, string $msg, ?string $media, ?string $type, array $extra): string {
    $uid = $acc['remote_id'];
    $tok = ['access_token' => $acc['token']];
    if (($post['kind'] ?? 'feed') !== 'feed') throw new RuntimeException('Reels a Stories Threads nepodporují.');
    $withMedia = $media !== null;
    // text bez média: odkaz jde jako náhled (link_attachment), jinak je součástí textu
    $text = $withMedia ? $msg : compose_text($post, false);
    if (mb_strlen($text) > 500) throw new RuntimeException('Text je delší než 500 znaků (limit Threads).');
    if ($text === '' && !$withMedia) throw new RuntimeException('Threads vyžadují text nebo médium.');

    if ($type === 'image' && $extra) {
        $kids = [];
        foreach (array_merge([$media], $extra) as $u) {
            $c = threads_api('POST', "$uid/threads", $tok + ['media_type' => 'IMAGE', 'image_url' => $u, 'is_carousel_item' => 'true']);
            threads_wait($c['id'], $tok);
            $kids[] = $c['id'];
        }
        $p = ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids)];
        if ($text !== '') $p['text'] = $text;
        $c = threads_api('POST', "$uid/threads", $tok + $p);
    } elseif ($type === 'image') {
        $c = threads_api('POST', "$uid/threads", $tok + ['media_type' => 'IMAGE', 'image_url' => $media] + ($text !== '' ? ['text' => $text] : []));
    } elseif ($type === 'video') {
        $c = threads_api('POST', "$uid/threads", $tok + ['media_type' => 'VIDEO', 'video_url' => $media] + ($text !== '' ? ['text' => $text] : []));
    } else {
        $p = ['media_type' => 'TEXT', 'text' => $text];
        if (!empty($post['link'])) $p['link_attachment'] = $post['link'];
        $c = threads_api('POST', "$uid/threads", $tok + $p);
    }
    threads_wait($c['id'], $tok);
    $r = threads_api('POST', "$uid/threads_publish", $tok + ['creation_id' => $c['id']]);
    return (string)$r['id'];
}

/** Obnoví tokeny, kterým zbývá méně než 20 dní (token musí být starší než 24 hodin). */
function threads_refresh_tokens(PDO $pdo): int {
    $n = 0;
    $q = $pdo->prepare("SELECT * FROM accounts WHERE platform = 'threads' AND (token_expires IS NULL OR token_expires < ?)");
    $q->execute([time() + 20 * 86400]);
    foreach ($q->fetchAll() as $acc) {
        try {
            $r = threads_api('GET', 'refresh_access_token', ['grant_type' => 'th_refresh_token', 'access_token' => $acc['token']], false);
            if (empty($r['access_token'])) continue;
            $pdo->prepare("UPDATE accounts SET token = ?, token_expires = ? WHERE id = ?")
                ->execute([$r['access_token'], time() + (int)($r['expires_in'] ?? 5184000), $acc['id']]);
            $n++;
        } catch (Throwable $e) {
            if (!str_starts_with($e->getMessage(), 'Síťová chyba')) log_line("THREADS token {$acc['name']}: " . $e->getMessage());
        }
    }
    return $n;
}
