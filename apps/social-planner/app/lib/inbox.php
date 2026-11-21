<?php
declare(strict_types=1);
// Příjem konceptů z jiných aplikací přes API (inbox.php). Klíče se ukládají jen jako SHA-256.

require_once __DIR__ . '/linkmeta.php';

const INBOX_MAX_IMAGES = 10;
const INBOX_MAX_BYTES = 15_000_000;

/** Vytvoří klíč; vrací [id, surový klíč]. Surový klíč se ukáže jen jednou. */
function apikey_create(PDO $pdo, string $name): array {
    $raw = 'spk_' . bin2hex(random_bytes(24));
    $pdo->prepare("INSERT INTO api_keys (name, key_hash, prefix, created_at) VALUES (?,?,?,?)")
        ->execute([$name, hash('sha256', $raw), substr($raw, 0, 10), time()]);
    return [(int)$pdo->lastInsertId(), $raw];
}
/** Ověří klíč z hlavičky Authorization: Bearer … (nebo X-API-Key). Vrací řádek klíče, nebo null. */
function apikey_check(PDO $pdo): ?array {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $raw = preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($raw === '') return null;
    $q = $pdo->prepare("SELECT * FROM api_keys WHERE key_hash = ?");
    $q->execute([hash('sha256', $raw)]);
    $k = $q->fetch();
    if (!$k) return null;
    $pdo->prepare("UPDATE api_keys SET last_used = ?, uses = uses + 1 WHERE id = ?")->execute([time(), $k['id']]);
    return $k;
}

/** Stáhne obrázek z URL, případně převede na JPEG (Instagram), uloží do uploads a do knihovny. Vrací název souboru. */
function inbox_fetch_image(PDO $pdo, string $url): string {
    if (!preg_match('#^https?://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('neplatná adresa obrázku');
    $r = fetch_url($url, INBOX_MAX_BYTES, 40);
    $info = @getimagesizefromstring($r['body']);
    if (!$info) throw new RuntimeException('soubor není obrázek');
    $mime = $info['mime'];
    $data = $r['body']; $ext = 'jpg';
    if ($mime === 'image/jpeg') { /* beze změny */ }
    elseif (in_array($mime, ['image/png', 'image/webp', 'image/gif'], true)) {
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('na serveru chybí GD, nelze převést ' . $mime . ' na JPEG');
        $im = @imagecreatefromstring($r['body']);
        if (!$im) throw new RuntimeException('obrázek nelze zpracovat');
        $w = imagesx($im); $h = imagesy($im);
        $bg = imagecreatetruecolor($w, $h); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
        imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
        ob_start(); imagejpeg($bg, null, 90); $data = (string)ob_get_clean();
        imagedestroy($im); imagedestroy($bg);
    } else throw new RuntimeException('nepodporovaný formát ' . $mime . ' (JPEG, PNG, WebP, GIF)');
    $fn = bin2hex(random_bytes(12)) . '.' . $ext;
    if (@file_put_contents(PUBLIC_DIR . '/uploads/' . $fn, $data) === false) throw new RuntimeException('obrázek se nepodařilo uložit');
    $orig = urldecode(basename((string)parse_url($url, PHP_URL_PATH)));
    library_register($pdo, $fn, 'image', $orig);
    return $fn;
}

/** Vytvoří koncept z požadavku $in. Vrací [id, duplicate]. Při chybě vyhodí InvalidArgumentException (422). */
function inbox_create(PDO $pdo, array $key, array $in): array {
    $source = (string)$key['name'];
    $ext = isset($in['external_id']) ? mb_substr(trim((string)$in['external_id']), 0, 200) : '';
    if ($ext !== '') {
        $q = $pdo->prepare("SELECT id FROM posts WHERE source = ? AND external_id = ?"); $q->execute([$source, $ext]);
        if ($id = $q->fetchColumn()) return [(int)$id, true];
    }
    $message = trim((string)($in['message'] ?? ''));
    if (mb_strlen($message) > 5000) throw new InvalidArgumentException('message: nejvýše 5 000 znaků.');
    $images = $in['images'] ?? [];
    if (is_string($images) && $images !== '') $images = [$images];
    if (!is_array($images)) throw new InvalidArgumentException('images: očekává se pole adres.');
    $images = array_values(array_filter(array_map('strval', $images), fn($u) => trim($u) !== ''));
    if (count($images) > INBOX_MAX_IMAGES) throw new InvalidArgumentException('images: nejvýše ' . INBOX_MAX_IMAGES . ' obrázků.');
    $link = trim((string)($in['link'] ?? ''));
    if ($link !== '' && (!filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) throw new InvalidArgumentException('link: musí začínat http:// nebo https://.');
    $fc = trim((string)($in['first_comment'] ?? ''));
    if (mb_strlen($fc) > 2000) throw new InvalidArgumentException('first_comment: nejvýše 2 000 znaků.');
    // 1.7.0: soubory z Knihovny médií podle ID (obrázky a slidy, nebo jedno video samotné)
    $libFiles = []; $libVideo = false;
    $libIds = array_values(array_unique(array_map('intval', (array)($in['library_ids'] ?? []))));
    foreach ($libIds as $lid) {
        $q = $pdo->prepare("SELECT file, type FROM library WHERE id = ?"); $q->execute([$lid]);
        $row = $q->fetch();
        if (!$row || !is_file(PUBLIC_DIR . '/uploads/' . basename($row['file']))) throw new InvalidArgumentException("library_ids: soubor $lid v knihovně není.");
        if ($row['type'] === 'video') $libVideo = true;
        $libFiles[] = $row['file'];
    }
    if ($libVideo && (count($libFiles) > 1 || $images)) throw new InvalidArgumentException('library_ids: video musí být v příspěvku samo (bez dalších obrázků).');
    if (count($libFiles) + count($images) > INBOX_MAX_IMAGES) throw new InvalidArgumentException('Nejvýše ' . INBOX_MAX_IMAGES . ' obrázků celkem (knihovna + adresy).');
    if ($message === '' && !$images && !$libFiles && $link === '') throw new InvalidArgumentException('Zadejte alespoň message, images nebo link.');
    $tagsIn = $in['tags'] ?? '';
    $tagStr = is_array($tagsIn) ? implode(' ', array_map('strval', $tagsIn)) : (string)$tagsIn;
    foreach ((array)($in['tag_sets'] ?? []) as $sn) {   // uložené sady hashtagů podle názvu
        $q = $pdo->prepare("SELECT tags FROM hashtag_sets WHERE name = ? COLLATE NOCASE"); $q->execute([(string)$sn]);
        $st = $q->fetchColumn();
        if ($st === false) throw new InvalidArgumentException('tag_sets: sada „' . $sn . '“ neexistuje.');
        $tagStr .= ' ' . $st;
    }
    $tagList = [];
    foreach (preg_split('/[\s,;]+/u', $tagStr, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $t = preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim($t, '#'));
        if ($t !== '' && !in_array(mb_strtolower($t), array_map('mb_strtolower', $tagList), true)) $tagList[] = $t;
    }
    if (count($tagList) > 30) throw new InvalidArgumentException('tags: nejvýše 30 štítků.');
    $ts = time();
    if (!empty($in['suggested_at'])) {
        $t = strtotime((string)$in['suggested_at']);
        if ($t === false) throw new InvalidArgumentException('suggested_at: neplatné datum (použijte např. 2026-12-01T10:00:00+01:00).');
        $ts = $t;
    }
    // účty: account_ids (čísla) a/nebo group (název skupiny); jen povolené účty
    $ids = [];
    foreach ((array)($in['account_ids'] ?? []) as $a) $ids[] = (int)$a;
    if (!empty($in['group'])) {
        $q = $pdo->prepare("SELECT account_ids FROM account_groups WHERE name = ? COLLATE NOCASE"); $q->execute([(string)$in['group']]);
        $g = $q->fetchColumn();
        if ($g === false) throw new InvalidArgumentException('group: skupina „' . $in['group'] . '“ neexistuje.');
        foreach (explode(',', (string)$g) as $a) if ($a !== '') $ids[] = (int)$a;
    }
    $ids = array_values(array_unique($ids));
    if ($ids) {
        $q = $pdo->prepare("SELECT id FROM accounts WHERE enabled = 1 AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"); $q->execute($ids);
        $ok = array_map('intval', array_column($q->fetchAll(), 'id'));
        $bad = array_diff($ids, $ok);
        if ($bad) throw new InvalidArgumentException('account_ids: neznámý nebo zakázaný účet ' . implode(', ', $bad) . '.');
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM posts WHERE source IS NOT NULL AND status = 'draft'")->fetchColumn() >= 500) throw new InvalidArgumentException('Ve frontě čeká příliš mnoho konceptů z API (500). Zkontrolujte je nebo smažte.');

    $files = [];
    try {
        foreach ($images as $i => $u) {
            try { $files[] = inbox_fetch_image($pdo, $u); }
            catch (Throwable $e) { throw new InvalidArgumentException('images[' . $i . ']: ' . $e->getMessage()); }
        }
        $all = array_merge($libFiles, $files);   // nejdřív soubory z knihovny, pak stažené
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO posts (message, media_file, media_type, scheduled_at, status, created_at, link, tags, first_comment, kind, source, external_id)
                       VALUES (?,?,?,?, 'draft', ?, ?,?,?, 'feed', ?, ?)")
            ->execute([$message, $all[0] ?? null, $all ? ($libVideo ? 'video' : 'image') : null, $ts, time(), $link ?: null, implode(' ', $tagList) ?: null, $fc ?: null, $source, $ext !== '' ? $ext : null]);
        $pid = (int)$pdo->lastInsertId();
        $pm = $pdo->prepare("INSERT INTO post_media (post_id, file, position) VALUES (?,?,?)");
        foreach (array_slice($all, 1) as $i => $fn) $pm->execute([$pid, $fn, $i + 1]);
        $ti = $pdo->prepare("INSERT INTO targets (post_id, account_id) VALUES (?,?)");
        foreach ($ids as $aid) $ti->execute([$pid, $aid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($files as $fn) { if (!media_in_use($pdo, $fn)) { @unlink(PUBLIC_DIR . '/uploads/' . $fn); $pdo->prepare("DELETE FROM library WHERE file = ?")->execute([$fn]); } }
        throw $e;
    }
    log_line("API koncept #$pid ze zdroje $source" . ($ext !== '' ? " ($ext)" : ''));
    return [$pid, false];
}
