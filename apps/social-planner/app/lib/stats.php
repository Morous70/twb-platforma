<?php
declare(strict_types=1);
// Statistiky publikovaných příspěvků (reakce, komentáře, sdílení, dosah, uložení).

function stat_num($v): ?int { return is_numeric($v) ? (int)$v : null; }

/** Načte čísla jednoho cíle. Chyba u dosahu (chybí oprávnění) nevadí – dosah zůstane prázdný. */
function fetch_target_stats(array $t): array {
    $tok = ['access_token' => $t['token']];
    $out = ['likes' => null, 'comments' => null, 'shares' => null, 'reach' => null, 'saves' => null];
    if ($t['platform'] === 'threads') {
        // Threads: dosah = zobrazení (views), sdílení = repost + citace; vyžaduje oprávnění threads_manage_insights
        try {
            $i = threads_api('GET', $t['remote_post_id'] . '/insights', $tok + ['metric' => 'views,likes,replies,reposts,quotes']);
            $m = [];
            foreach ($i['data'] ?? [] as $d) $m[$d['name'] ?? ''] = stat_num($d['values'][0]['value'] ?? ($d['total_value']['value'] ?? null));
            $out['reach'] = $m['views'] ?? null; $out['likes'] = $m['likes'] ?? null; $out['comments'] = $m['replies'] ?? null;
            if (isset($m['reposts']) || isset($m['quotes'])) $out['shares'] = (int)($m['reposts'] ?? 0) + (int)($m['quotes'] ?? 0);
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Síťová chyba')) throw $e;
            $out['_warn'] = 'Threads: statistiky vyžadují oprávnění threads_manage_insights (přidejte ho v Metě a Threads propojte znovu). ' . mb_substr($e->getMessage(), 0, 120);
        }
        return $out;
    }
    if ($t['platform'] === 'facebook') {
        // Sdílení stačí oprávnění pages_read_engagement; reakce a komentáře (cizí obsah) vyžadují pages_read_user_content.
        $r = graph('GET', $t['remote_post_id'], $tok + ['fields' => 'shares']);
        $out['shares'] = stat_num($r['shares']['count'] ?? 0);
        try {
            $r = graph('GET', $t['remote_post_id'], $tok + ['fields' => 'reactions.summary(true).limit(0),comments.summary(true).limit(0)']);
            $out['likes']    = stat_num($r['reactions']['summary']['total_count'] ?? null);
            $out['comments'] = stat_num($r['comments']['summary']['total_count'] ?? null);
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Síťová chyba')) throw $e;
            $out['_warn'] = 'Facebook: reakce a komentáře vyžadují oprávnění pages_read_user_content (nebo funkci Page Public Content Access).';
        }
        try {
            $i = graph('GET', $t['remote_post_id'] . '/insights', $tok + ['metric' => 'post_impressions_unique']);
            $out['reach'] = stat_num($i['data'][0]['values'][0]['value'] ?? null);
        } catch (Throwable $e) { /* chybí oprávnění read_insights */ }
    } else {
        $r = graph('GET', $t['remote_post_id'], $tok + ['fields' => 'like_count,comments_count']);
        $out['likes']    = stat_num($r['like_count'] ?? null);
        $out['comments'] = stat_num($r['comments_count'] ?? null);
        try {
            $i = graph('GET', $t['remote_post_id'] . '/insights', $tok + ['metric' => 'reach,saved']);
            foreach ($i['data'] ?? [] as $m) {
                $v = stat_num($m['values'][0]['value'] ?? null);
                if (($m['name'] ?? '') === 'reach') $out['reach'] = $v;
                if (($m['name'] ?? '') === 'saved') $out['saves'] = $v;
            }
        } catch (Throwable $e) { /* chybí oprávnění instagram_manage_insights */ }
    }
    return $out;
}

/** Obnoví statistiky cílů publikovaných za posledních $days dní. Vrací [ok, chyb, první chyba]. */
function refresh_stats(PDO $pdo, int $days = 30, int $limit = 150): array {
    $q = $pdo->prepare("SELECT t.id, t.remote_post_id, a.platform, a.token
                        FROM targets t JOIN accounts a ON a.id = t.account_id
                        WHERE t.status = 'done' AND t.remote_post_id != '' AND t.published_at >= ? AND a.enabled = 1
                        ORDER BY t.published_at DESC LIMIT $limit");
    $q->execute([time() - $days * 86400]);
    $ok = 0; $bad = 0; $first = ''; $warn = '';
    $up = $pdo->prepare("UPDATE targets SET stat_likes=?, stat_comments=?, stat_shares=?, stat_reach=?, stat_saves=?, stat_at=? WHERE id=?");
    foreach ($q->fetchAll() as $t) {
        try {
            $s = fetch_target_stats($t);
            if (!empty($s['_warn']) && $warn === '') $warn = $s['_warn'];
            $up->execute([$s['likes'], $s['comments'], $s['shares'], $s['reach'], $s['saves'], time(), $t['id']]);
            $ok++;
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), 'Síťová chyba')) continue;
            $bad++; if ($first === '') $first = $e->getMessage();
        }
    }
    meta_set('last_stats', (string)time());
    return ['done' => $ok, 'bad' => $bad, 'error' => $first, 'warn' => $warn];
}

function stats_if_due(PDO $pdo): void {
    if (time() - (int)meta_get('last_stats', '0') < 86400) return;
    try { refresh_stats($pdo); } catch (Throwable $e) { /* příště */ }
}
