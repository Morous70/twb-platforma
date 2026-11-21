<?php
// Synchronizace cílové složky na Google Disku s tím, co má právě běžet.
require_once __DIR__ . '/schedule.php';
require_once __DIR__ . '/drive.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/slide.php';

/**
 * @param bool $force  vždy zkontrolovat obsah složky na Disku (jinak jen při změně sestavy nebo 1× za hodinu)
 * @return array  výsledek pro zobrazení
 */
function run_sync(bool $force = false, ?DateTimeInterface $now = null): array {
    $lock = fopen(DATA_DIR . '/sync.lock', 'c');
    if (!flock($lock, LOCK_EX | LOCK_NB)) return ['status' => 'busy', 'message' => 'Synchronizace už běží.'];

    try {
        $now ??= new DateTimeImmutable();
        $folderId = (string)setting('folder_id', '');
        if ($folderId === '') throw new RuntimeException('Není nastavena cílová složka na Google Disku.');

        // slidy s proměnnými (datum, svátek…) se přegenerují, když se změní jejich obsah
        regenerate_slides($now instanceof DateTimeImmutable ? $now : DateTimeImmutable::createFromInterface($now));
        $resolved = expand_pools(resolve_at($now), $now, true);
        $targets = target_files($resolved);
        $sig = md5($folderId . '|' . json_encode(array_map(fn($t) => [$t['name'], $t['md5']], $targets)));

        $recentlyVerified = (time() - (int)setting('last_verified_at', 0)) < 3600;
        if (!$force && $sig === setting('last_sig') && $recentlyVerified && !setting('fail_since')) {
            set_setting('last_run_at', (string)time());
            return ['status' => 'unchanged', 'message' => 'Beze změny.'];
        }

        $existing = drive_list($folderId);
        $keep = [];
        $uploaded = [];
        foreach ($targets as $t) {
            $match = null;
            foreach ($existing as $f) {
                if (!isset($keep[$f['id']]) && $f['name'] === $t['name'] && $f['md5'] === $t['md5']) { $match = $f; break; }
            }
            if ($match) { $keep[$match['id']] = true; continue; }
            try {
                $newId = drive_upload($folderId, $t['path'], $t['name'], $t['mime']);
            } catch (RuntimeException $e) {
                $hint = $e->getCode() === 403 ? ' Připojený Google účet nemá do cílové složky právo zapisovat – složku mu nasdílejte jako Editor.' : '';
                throw new RuntimeException("Nahrání souboru „{$t['name']}“ selhalo: " . $e->getMessage() . $hint, $e->getCode());
            }
            $keep[$newId] = true;
            $uploaded[] = $t['name'];
        }
        $mode = setting('delete_mode', 'trash');
        $removed = [];
        foreach ($existing as $f) {
            if (isset($keep[$f['id']])) continue;
            try {
                drive_remove($f['id'], $mode, $folderId);
            } catch (RuntimeException $e) {
                $hint = $e->getCode() === 403 ? ' Soubor patří jinému účtu a připojený účet ho nemůže ani vyjmout ze složky – odstraňte ho prosím ručně na Google Disku, nebo dejte připojenému účtu práva Editor.' : '';
                throw new RuntimeException("Odstranění souboru „{$f['name']}“ ze složky selhalo: " . $e->getMessage() . $hint, $e->getCode());
            }
            $removed[] = $f['name'];
        }

        $sigChanged = $sig !== setting('last_sig');
        set_setting('last_sig', $sig);
        // změna obsahu složky → pomocník v místní síti (signal.php) spustí synchronizaci přehrávače
        if ($uploaded || $removed) set_setting('last_change_at', (string)time());
        set_setting('last_verified_at', (string)time());
        set_setting('last_run_at', (string)time());
        set_setting('last_error', null);
        notify_recovery();

        if ($uploaded || $removed || $sigChanged) {
            $label = match ($resolved['mode']) {
                'default' => 'výchozí scénář', 'empty' => 'žádný scénář', 'exclusive' => 'výhradní',
                default => 'scénáře',
            };
            $msg = 'Složka aktualizována (' . $label . ': ' . (implode(', ', $resolved['active']) ?: '—')
                . (!empty($resolved['fixed']) ? '; pevné: ' . implode(', ', $resolved['fixed']) : '') . ')';
            if ($uploaded) $msg .= '. Nahráno: ' . implode(', ', $uploaded);
            if ($removed) $msg .= '. ' . ($mode === 'permanent' ? 'Smazáno' : 'Do koše') . ': ' . implode(', ', $removed);
            $picks = array_filter(array_map(fn($r) => isset($r['pool']) ? "{$r['pool']} → {$r['media']['original_name']}" : null, $resolved['media']));
            if ($picks) $msg .= '. Náhodný výběr: ' . implode(', ', $picks);
            if (!$uploaded && !$removed) $msg .= '. Soubory už odpovídaly.';
            app_log('success', $msg);
        }
        return ['status' => 'ok', 'uploaded' => $uploaded, 'removed' => $removed, 'kept' => count($targets) - count($uploaded),
                'message' => ($uploaded || $removed) ? 'Složka aktualizována.' : 'Složka odpovídá plánu.'];
    } catch (Throwable $e) {
        set_setting('last_run_at', (string)time());
        set_setting('last_error', $e->getMessage());
        app_log('error', 'Synchronizace selhala: ' . $e->getMessage());
        notify_failure($e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
