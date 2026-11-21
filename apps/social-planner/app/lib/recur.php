<?php
declare(strict_types=1);

const REPEAT_MODES = ['none', 'daily', 'weekly', 'monthly'];

/** Další termín po $ts (v časovém pásmu aplikace, hodina zůstává stejná i při změně letního času). */
function next_occurrence(int $ts, string $mode, ?int $day = null): int {
    $tz = new DateTimeZone(date_default_timezone_get());
    $d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
    switch ($mode) {
        case 'daily':
            $n = $d->modify('+1 day');
            break;
        case 'weekly':
            $n = $d->modify('+7 days');
            break;
        case 'monthly':
            // zachová původní den v měsíci; v kratším měsíci použije poslední den (31. → 28./30.)
            $first = $d->modify('first day of next month');
            $dim = (int)$first->format('t');
            $want = $day ?: (int)$d->format('j');
            $n = $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($want, $dim));
            break;
        default:
            throw new InvalidArgumentException("Neznámé opakování: $mode");
    }
    return $n->getTimestamp();
}

/** První termín v budoucnosti (po $now) – po výpadku cronu se zmeškané výskyty nedohánějí. */
function next_after(int $ts, string $mode, ?int $day, int $now): int {
    $n = next_occurrence($ts, $mode, $day);
    for ($i = 0; $n <= $now && $i < 5000; $i++) {
        $n = next_occurrence($n, $mode, $day);
    }
    return $n;
}

/** Po odeslání opakovaného příspěvku vytvoří jeho další výskyt (jen jednou). */
function spawn_next(PDO $pdo, array $post): void {
    if (($post['repeat_mode'] ?? 'none') === 'none' || !empty($post['next_created'])) return;

    $day  = $post['repeat_day'] ? (int)$post['repeat_day'] : null;
    $next = next_after((int)$post['scheduled_at'], $post['repeat_mode'], $day, time());

    $pdo->beginTransaction();
    try {
        // „next_created“ nastavíme vždy, i když série končí – brání duplicitám při opakování (retry)
        $mark = $pdo->prepare("UPDATE posts SET next_created = 1 WHERE id = ? AND next_created = 0");
        $mark->execute([$post['id']]);
        if ($mark->rowCount() === 0) { $pdo->commit(); return; }

        if (!$post['repeat_until'] || $next <= (int)$post['repeat_until']) {
            $pdo->prepare("INSERT INTO posts (message, media_file, media_type, scheduled_at, status, created_at,
                                              repeat_mode, repeat_until, repeat_day, series_id, link, tags, first_comment, kind)
                           VALUES (?,?,?,?, 'scheduled', ?, ?,?,?,?, ?,?,?,?)")
                ->execute([$post['message'], $post['media_file'], $post['media_type'], $next, time(),
                           $post['repeat_mode'], $post['repeat_until'], $post['repeat_day'],
                           $post['series_id'] ?: $post['id'], $post['link'] ?? null, $post['tags'] ?? null, $post['first_comment'] ?? null, $post['kind'] ?? 'feed']);
            $nid = (int)$pdo->lastInsertId();
            $pdo->prepare("INSERT INTO post_media (post_id, file, position) SELECT ?, file, position FROM post_media WHERE post_id = ?")
                ->execute([$nid, $post['id']]);
            $pdo->prepare("INSERT INTO targets (post_id, account_id) SELECT ?, account_id FROM targets WHERE post_id = ?")
                ->execute([$nid, $post['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        log_line('CHYBA při plánování dalšího výskytu příspěvku ' . $post['id'] . ': ' . $e->getMessage());
    }
}

/** Budoucí (ještě nevytvořené) výskyty příspěvku v intervalu <$from, $to) – pro zobrazení v kalendáři. */
function project_occurrences(array $p, int $from, int $to): array {
    $out = [];
    $ts  = (int)$p['scheduled_at'];
    $day = $p['repeat_day'] ? (int)$p['repeat_day'] : null;
    $now = time();
    for ($i = 0; $i < 3000; $i++) {
        $ts = next_occurrence($ts, $p['repeat_mode'], $day);
        if ($ts >= $to) break;
        if ($p['repeat_until'] && $ts > (int)$p['repeat_until']) break;
        if ($ts >= $from && $ts > $now) $out[] = $ts;
    }
    return $out;
}
