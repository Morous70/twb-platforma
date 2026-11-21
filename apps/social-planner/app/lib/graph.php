<?php
declare(strict_types=1);
require_once __DIR__ . '/recur.php';
require_once __DIR__ . '/vars.php';
require_once __DIR__ . '/health.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/gdrive.php';
require_once __DIR__ . '/inbox.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/library.php';
require_once __DIR__ . '/threads.php';
require_once __DIR__ . '/avatars.php';

/** Volání Graph API. Vrací dekódované pole, při chybě vyhazuje RuntimeException. */
function graph(string $method, string $path, array $params = []): array {
    if (cfg('dry_run')) {
        log_line("DRY-RUN $method $path " . json_encode(array_diff_key($params, ['access_token' => 1])));
        return ['id' => 'dry_' . bin2hex(random_bytes(4)), 'status_code' => 'FINISHED', 'data' => []];
    }
    $url = 'https://graph.facebook.com/' . cfg('graph_version', 'v23.0') . '/' . ltrim($path, '/');
    $ch = curl_init();
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
    } else {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        throw new RuntimeException('Síťová chyba: ' . $err);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Neplatná odpověď Graph API');
    if (isset($data['error'])) {
        $e = $data['error'];
        throw new RuntimeException(($e['message'] ?? 'Chyba Graph API') . ' (code ' . ($e['code'] ?? '?') . ')');
    }
    return $data;
}

/** Text příspěvku = zpráva + (odkaz) + hashtagy. */
function compose_text(array $post, bool $withLink = true): string {
    $text = trim((string)$post['message']);
    if (!empty($post['scheduled_at']) && strpos($text, '{{') !== false) {      // proměnné se dosazují při odeslání
        $pdo = db();
        $text = expand_vars($text, (int)$post['scheduled_at'], series_index($pdo, $post), user_vars($pdo));
    }
    $parts = [$text];
    if ($withLink && !empty($post['link'])) $parts[] = $post['link'];
    $tags = preg_split('/\s+/', trim((string)($post['tags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY);
    if ($tags) $parts[] = implode(' ', array_map(fn($t) => '#' . $t, $tags));
    return implode("\n\n", array_filter($parts, fn($x) => $x !== ''));
}

/** Text prvního komentáře (s dosazenými proměnnými). */
function comment_text(array $post): string {
    $t = trim((string)($post['first_comment'] ?? ''));
    if ($t !== '' && !empty($post['scheduled_at']) && strpos($t, '{{') !== false) {
        $pdo = db();
        $t = expand_vars($t, (int)$post['scheduled_at'], series_index($pdo, $post), user_vars($pdo));
    }
    return $t;
}

/** Přidá první komentář pod publikovaný příspěvek. */
function post_first_comment(array $post, array $acc, string $remoteId): void {
    if (($post['kind'] ?? 'feed') === 'story') return;   // story komentář nepřijímají
    $t = comment_text($post);
    if ($t === '' || $remoteId === '') return;
    if ($acc['platform'] === 'threads') {   // na Threads je komentář odpověď na vlastní příspěvek (oprávnění threads_manage_replies)
        if (mb_strlen($t) > 500) throw new RuntimeException('Komentář je delší než 500 znaků (limit Threads).');
        $tok = ['access_token' => $acc['token']];
        $c = threads_api('POST', $acc['remote_id'] . '/threads', $tok + ['media_type' => 'TEXT', 'text' => $t, 'reply_to_id' => $remoteId]);
        threads_wait($c['id'], $tok);
        threads_api('POST', $acc['remote_id'] . '/threads_publish', $tok + ['creation_id' => $c['id']]);
        return;
    }
    graph('POST', "$remoteId/comments", ['access_token' => $acc['token'], 'message' => $t]);
}

/** Počká, až Instagram zpracuje médium (u videa to trvá). */
function ig_wait(string $creation, array $tok): void {
    for ($i = 0; $i < 40; $i++) {
        $s = graph('GET', $creation, $tok + ['fields' => 'status_code,status']);
        $code = $s['status_code'] ?? '';
        if ($code === 'FINISHED') return;
        if ($code === 'ERROR' || $code === 'EXPIRED') {
            throw new RuntimeException('Instagram zpracování selhalo: ' . ($s['status'] ?? $code));
        }
        if (!cfg('dry_run')) sleep(3);
    }
}

/** Veřejná adresa souboru z uploads/; ?v= podle změny souboru, aby sítě nevzaly starou kopii přegenerovaného slidu. */
function media_public_url(string $file): string {
    $path = PUBLIC_DIR . '/uploads/' . basename($file);
    $v = is_file($path) ? '?v=' . filemtime($path) : '';
    return rtrim(cfg('base_url'), '/') . '/uploads/' . rawurlencode($file) . $v;
}

/** Odešle jeden cíl (FB stránka nebo IG účet). Vrací ID příspěvku na síti. */
function publish_target(array $post, array $acc): string {
    $msg   = compose_text($post);
    $type  = $post['media_type'];
    $files = [];
    if ($type === 'image') {
        $q = db()->prepare("SELECT file FROM post_media WHERE post_id = ? ORDER BY position");
        $q->execute([$post['id']]);
        $files = array_column($q->fetchAll(), 'file');
        // 1.6.0: slidy přegenerovat pro čas zveřejnění (jen když se jejich obsah změnil)
        require_once __DIR__ . '/slide.php';
        slides_refresh_files(array_merge([(string)$post['media_file']], $files), dt_from_ts((int)$post['scheduled_at']));
    }
    $media = $post['media_file'] ? media_public_url((string)$post['media_file']) : null;
    $tok   = ['access_token' => $acc['token']];
    $extra = array_map('media_public_url', $files);   // další obrázky (galerie / carousel)

    if ($acc['platform'] === 'threads') {
        return threads_publish_target($post, $acc, $msg, $media, $type, $extra);
    }
    $kind = $post['kind'] ?? 'feed';
    if ($kind === 'story') {
        if (!$media) throw new RuntimeException('Story vyžaduje obrázek nebo video.');
        if ($acc['platform'] === 'facebook') {
            if ($type !== 'image') throw new RuntimeException('Video story na Facebooku zatím aplikace nepodporuje.');
            $ph = graph('POST', "{$acc['remote_id']}/photos", $tok + ['url' => $media, 'published' => 'false']);
            $r = graph('POST', "{$acc['remote_id']}/photo_stories", $tok + ['photo_id' => $ph['id']]);
            return (string)($r['post_id'] ?? $r['id'] ?? '');
        }
        $params = $tok + ['media_type' => 'STORIES'] + ($type === 'video' ? ['video_url' => $media] : ['image_url' => $media]);
        $c = graph('POST', "{$acc['remote_id']}/media", $params);
        ig_wait($c['id'], $tok);
        $p = graph('POST', "{$acc['remote_id']}/media_publish", $tok + ['creation_id' => $c['id']]);
        return (string)$p['id'];
    }
    if ($kind === 'reel' && $acc['platform'] === 'facebook') throw new RuntimeException('Reels na Facebooku zatím aplikace nepodporuje.');

    if ($acc['platform'] === 'facebook') {
        if ($type === 'image' && $extra) {
            // více fotek: nahrát nepublikované a připojit k jednomu příspěvku
            $att = [];
            foreach (array_merge([$media], $extra) as $i => $u) {
                $ph = graph('POST', "{$acc['remote_id']}/photos", $tok + ['url' => $u, 'published' => 'false']);
                $att[$i] = json_encode(['media_fbid' => $ph['id']]);
            }
            $r = graph('POST', "{$acc['remote_id']}/feed", $tok + ['message' => $msg, 'attached_media' => $att]);
        } elseif ($type === 'image') {
            $r = graph('POST', "{$acc['remote_id']}/photos", $tok + ['url' => $media, 'caption' => $msg]);
        } elseif ($type === 'video') {
            $r = graph('POST', "{$acc['remote_id']}/videos", $tok + ['file_url' => $media, 'description' => $msg]);
        } else {
            // text bez média: odkaz předáme Facebooku, ten z něj udělá náhled
            $params = $tok + ['message' => compose_text($post, false)];
            if (!empty($post['link'])) $params['link'] = $post['link'];
            $r = graph('POST', "{$acc['remote_id']}/feed", $params);
        }
        return (string)($r['post_id'] ?? $r['id'] ?? '');
    }

    // Instagram: vždy je potřeba médium (obrázek JPEG nebo video)
    if (!$media) throw new RuntimeException('Instagram vyžaduje obrázek nebo video.');
    if ($extra) {
        // carousel: každý obrázek je dítě, pak se vytvoří společný kontejner
        $kids = [];
        foreach (array_merge([$media], $extra) as $u) {
            $c = graph('POST', "{$acc['remote_id']}/media", $tok + ['image_url' => $u, 'is_carousel_item' => 'true']);
            ig_wait($c['id'], $tok);
            $kids[] = $c['id'];
        }
        $c = graph('POST', "{$acc['remote_id']}/media", $tok + ['media_type' => 'CAROUSEL', 'children' => implode(',', $kids), 'caption' => $msg]);
        ig_wait($c['id'], $tok);
        $p = graph('POST', "{$acc['remote_id']}/media_publish", $tok + ['creation_id' => $c['id']]);
        return (string)$p['id'];
    }
    $params = $tok + ['caption' => $msg];
    if ($type === 'video') { $params += ['media_type' => 'REELS', 'video_url' => $media]; }
    else                   { $params += ['image_url' => $media]; }
    $c = graph('POST', "{$acc['remote_id']}/media", $params);
    $creation = $c['id'];

    ig_wait($creation, $tok);
    $p = graph('POST', "{$acc['remote_id']}/media_publish", $tok + ['creation_id' => $creation]);
    return (string)$p['id'];
}

/** Zpracuje všechny splatné příspěvky. Vrací počet zpracovaných cílů. */
function run_due(): int {
    $pdo = db();
    $n = 0;
    $now = time();
    // Příspěvek zaseknutý v „publishing“ (pád skriptu) neodesíláme automaticky znovu,
    // aby nevznikly duplicity – označíme ho jako selhaný a uživatel zvolí „Zkusit znovu“.
    $pdo->prepare("UPDATE posts SET status='failed' WHERE status='publishing' AND scheduled_at < ?")->execute([$now - 900]);
    $posts = $pdo->prepare("SELECT * FROM posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT 20");
    $posts->execute([$now]);
    foreach ($posts->fetchAll() as $post) {
        // „zamknout“ příspěvek proti souběžnému spuštění cronu
        $lock = $pdo->prepare("UPDATE posts SET status = 'publishing' WHERE id = ? AND status = 'scheduled'");
        $lock->execute([$post['id']]);
        if ($lock->rowCount() === 0) continue;

        $ts = $pdo->prepare("SELECT t.id AS tid, a.* FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ? AND t.status IN ('pending','failed')");
        $ts->execute([$post['id']]);
        $failedLines = [];
        foreach ($ts->fetchAll() as $acc) {
            $tid = $acc['tid'];
            if (isset($acc['enabled']) && !(int)$acc['enabled']) {
                $pdo->prepare("UPDATE targets SET status='failed', error=? WHERE id=?")
                    ->execute(['Účet je v nastavení zakázán (po povolení použijte „Zkusit znovu“).', $tid]);
                log_line("PŘESKOČENO post {$post['id']} -> {$acc['platform']}:{$acc['name']} (zakázáno)");
                continue;
            }
            try {
                $rid = publish_target($post, $acc);
                $pdo->prepare("UPDATE targets SET status='done', remote_post_id=?, error=NULL, attempts=attempts+1, published_at=? WHERE id=?")
                    ->execute([$rid, time(), $tid]);
                log_line("OK post {$post['id']} -> {$acc['platform']}:{$acc['name']} ($rid)");
                try { post_first_comment($post, $acc, $rid); }
                catch (Throwable $ce) {   // příspěvek je venku; chybu komentáře jen zaznamenáme
                    $pdo->prepare("UPDATE targets SET error=? WHERE id=?")->execute([mb_substr('Příspěvek vyšel, ale první komentář se nepodařilo přidat: ' . $ce->getMessage(), 0, 500), $tid]);
                    log_line("KOMENTÁŘ post {$post['id']} -> {$acc['platform']}:{$acc['name']}: " . $ce->getMessage());
                }
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE targets SET status='failed', error=?, attempts=attempts+1 WHERE id=?")
                    ->execute([mb_substr($e->getMessage(), 0, 500), $tid]);
                log_line("CHYBA post {$post['id']} -> {$acc['platform']}:{$acc['name']}: " . $e->getMessage());
                $failedLines[] = "{$acc['platform']}:{$acc['name']} – " . mb_substr($e->getMessage(), 0, 300);
                if (str_contains($e->getMessage(), '(code 190)')) set_account_health($pdo, $acc, false, $e->getMessage());   // neplatný token
            }
            $n++;
        }
        $st = $pdo->prepare("SELECT status, COUNT(*) c FROM targets WHERE post_id = ? GROUP BY status");
        $st->execute([$post['id']]);
        $cnt = array_column($st->fetchAll(), 'c', 'status');
        $status = empty($cnt['failed']) ? 'done' : (empty($cnt['done']) ? 'failed' : 'partial');
        $pdo->prepare("UPDATE posts SET status = ? WHERE id = ?")->execute([$status, $post['id']]);
        if ($status !== 'done') {
            if (!$failedLines) {   // cíl přeskočen (zakázaný účet) – vypsat z databáze
                $f = $pdo->prepare("SELECT a.platform, a.name, t.error FROM targets t JOIN accounts a ON a.id = t.account_id WHERE t.post_id = ? AND t.status = 'failed'");
                $f->execute([$post['id']]);
                foreach ($f->fetchAll() as $r) $failedLines[] = "{$r['platform']}:{$r['name']} – {$r['error']}";
            }
            notify_failure($post, $failedLines);
        }
        // opakovaný příspěvek: naplánovat další výskyt (i když tento selhal – série pokračuje)
        spawn_next($pdo, $post);
    }
    return $n;
}
