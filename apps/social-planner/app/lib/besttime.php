<?php
declare(strict_types=1);
// Doporučení nejlepšího času k publikování podle vlastních statistik.
//
// Princip: každý změřený příspěvek dostane skóre = jeho zapojení / průměrné zapojení jeho účtu
// (1,0 = běžný příspěvek daného účtu). Díky tomu velký účet „nepřehluší“ malý.
// Skóre se seskupí podle dne v týdnu a hodiny (místní čas z config.php), vyhladí se se sousedními
// hodinami a přitáhne k 1,0, dokud je v čase málo příspěvků (aby jeden úspěšný příspěvek nerozhodl).

const BT_MIN_SAMPLES = 8;     // od kolika změřených příspěvků se doporučuje
const BT_MIN_AGE     = 86400; // příspěvek se počítá, až když byl změřen aspoň den po vydání
const BT_CAP         = 5.0;   // virální výjimka se počítá nejvýš jako 5× průměr
const BT_PRIOR       = 2.0;   // síla přitažení k průměru (počet „pomyslných“ běžných příspěvků)

/** Zapojení jednoho cíle (vážené: komentář a uložení víc než reakce, sdílení nejvíc). Null = nic nezměřeno. */
function bt_engagement(array $t): ?float {
    $l = $t['stat_likes']; $c = $t['stat_comments']; $s = $t['stat_shares']; $v = $t['stat_saves'];
    if ($l === null && $c === null && $s === null && $v === null) return null;
    return (float)((int)$l + 2 * (int)$c + 3 * (int)$s + 2 * (int)$v);
}

/**
 * @param int[] $accountIds prázdné = všechny povolené účty
 * @return array{enough:bool, samples:int, need:int, accounts:int, grid:array, days:array, hours:array, top:array}
 */
function best_times(PDO $pdo, array $accountIds = [], int $days = 120, ?int $now = null): array {
    $now ??= time();
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    $sql = "SELECT t.account_id, t.published_at, t.stat_at, t.stat_likes, t.stat_comments, t.stat_shares, t.stat_saves
            FROM targets t JOIN accounts a ON a.id = t.account_id
            WHERE t.status = 'done' AND t.published_at >= " . (int)($now - $days * 86400) . "
              AND t.stat_at IS NOT NULL AND t.stat_at - t.published_at >= " . (int)BT_MIN_AGE;   // čísla přímo: parametr by SQLite porovnal jako text
    $args = [];
    if ($accountIds) { $sql .= ' AND t.account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')'; $args = array_merge($args, $accountIds); }
    else $sql .= ' AND a.enabled = 1';
    $q = $pdo->prepare($sql);
    $q->execute($args);

    // 1) zapojení po účtech
    $byAcc = [];
    foreach ($q->fetchAll() as $t) {
        $e = bt_engagement($t);
        if ($e === null) continue;
        $byAcc[(int)$t['account_id']][] = ['ts' => (int)$t['published_at'], 'e' => $e];
    }
    // 2) normalizace na průměr účtu, rozdělení do slotů den × hodina
    $sum = array_fill(1, 7, array_fill(0, 24, 0.0));
    $cnt = array_fill(1, 7, array_fill(0, 24, 0));
    $samples = 0;
    foreach ($byAcc as $rows) {
        $mean = array_sum(array_column($rows, 'e')) / count($rows);
        if ($mean <= 0) continue;              // účet bez jakékoli interakce nic nevypovídá
        foreach ($rows as $r) {
            $d = (int)date('N', $r['ts']); $h = (int)date('G', $r['ts']);
            $sum[$d][$h] += min(BT_CAP, $r['e'] / $mean);
            $cnt[$d][$h]++;
            $samples++;
        }
    }

    // 3) souhrny po dnech a hodinách (pro zobrazení, když je dat méně)
    $daysOut = []; $hoursOut = [];
    for ($d = 1; $d <= 7; $d++) { $s = array_sum($sum[$d]); $n = array_sum($cnt[$d]); $daysOut[] = ['d' => $d, 'n' => $n, 's' => $n ? round($s / $n, 3) : null]; }
    for ($h = 0; $h < 24; $h++) {
        $s = 0.0; $n = 0;
        for ($d = 1; $d <= 7; $d++) { $s += $sum[$d][$h]; $n += $cnt[$d][$h]; }
        $hoursOut[] = ['h' => $h, 'n' => $n, 's' => $n ? round($s / $n, 3) : null];
    }

    // 4) mřížka: vlastní hodina váha 1, sousední hodiny téhož dne váha 0,5; přitažení k 1,0
    $grid = []; $cands = [];
    for ($d = 1; $d <= 7; $d++) for ($h = 0; $h < 24; $h++) {
        $ws = 0.0; $wn = 0.0;
        foreach ([-1 => 0.5, 0 => 1.0, 1 => 0.5] as $off => $w) {
            $hh = $h + $off; if ($hh < 0 || $hh > 23) continue;
            $ws += $w * $sum[$d][$hh]; $wn += $w * $cnt[$d][$hh];
        }
        if ($cnt[$d][$h] === 0 && $wn == 0.0) continue;
        $score = ($ws + BT_PRIOR) / ($wn + BT_PRIOR);
        $grid[] = ['d' => $d, 'h' => $h, 'n' => $cnt[$d][$h], 'raw' => $cnt[$d][$h] ? round($sum[$d][$h] / $cnt[$d][$h], 3) : null, 's' => round($score, 3)];
        // doporučovat jen hodinu, ve které opravdu něco vyšlo, a jen nadprůměrnou
        if ($cnt[$d][$h] > 0 && $score > 1.0) $cands[] = ['d' => $d, 'h' => $h, 's' => $score, 'n' => $cnt[$d][$h]];
    }

    // 5) tři nejlepší, které nejsou těsně vedle sebe v tentýž den
    usort($cands, fn($a, $b) => $b['s'] <=> $a['s'] ?: $b['n'] <=> $a['n']);
    $top = [];
    $enough = $samples >= BT_MIN_SAMPLES;
    if ($enough) foreach ($cands as $c) {
        foreach ($top as $t) if ($t['d'] === $c['d'] && abs($t['h'] - $c['h']) <= 1) continue 2;
        $top[] = ['d' => $c['d'], 'h' => $c['h'], 'n' => $c['n'], 'lift' => (int)round(($c['s'] - 1) * 100), 'next' => bt_next($c['d'], $c['h'], $now)];
        if (count($top) === 3) break;
    }
    return ['enough' => $enough, 'samples' => $samples, 'need' => BT_MIN_SAMPLES, 'accounts' => count($byAcc), 'days_back' => $days,
            'grid' => $grid, 'days' => $daysOut, 'hours' => $hoursOut, 'top' => $top];
}

/** Nejbližší budoucí okamžik daného dne v týdnu (1 = po) a hodiny v místním čase; aspoň 15 minut od teď. */
function bt_next(int $dow, int $hour, int $now): int {
    $base = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    for ($i = 0; $i <= 7; $i++) {
        $c = $base->modify("+$i day")->setTime($hour, 0);
        if ((int)$c->format('N') === $dow && $c->getTimestamp() >= $now + 900) return $c->getTimestamp();
    }
    return $base->modify('+7 day')->setTime($hour, 0)->getTimestamp();
}
