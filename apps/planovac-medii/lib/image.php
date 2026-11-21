<?php
// Komprese obrázků v galerii: zmenšení do maximálního rozměru a uložení jako JPG,
// obrázky se skutečnou průhledností jen zmenšené jako PNG. Originál se uchová (orig_*).

function img_settings(): array {
    return [
        'auto' => setting('img_auto', '1') === '1',
        'max_w' => max(320, (int)setting('img_max_w', 1920)),
        'max_h' => max(240, (int)setting('img_max_h', 1080)),
        'quality' => min(100, max(30, (int)setting('img_quality', 85))),
        'min_kb' => max(0, (int)setting('img_min_kb', 1024)),
    ];
}

/** Animovaný GIF (víc snímků) – nekomprimujeme, přišel by o animaci. */
function img_is_animated_gif(string $path): bool {
    $fh = @fopen($path, 'rb');
    if (!$fh) return false;
    $n = 0;
    $prev = '';
    while (!feof($fh) && $n < 2) {
        $chunk = $prev . fread($fh, 1024 * 100);
        $n += preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $chunk);
        $prev = substr($chunk, -20);
    }
    fclose($fh);
    return $n > 1;
}

/** Orientace z EXIF (1–8) u JPG; bez rozšíření exif ji přečte z hlavičky sám. */
function img_exif_orientation(string $path): int {
    if (function_exists('exif_read_data')) {
        $e = @exif_read_data($path);
        if ($e && isset($e['Orientation'])) return (int)$e['Orientation'];
    }
    $fh = @fopen($path, 'rb');
    if (!$fh) return 1;
    $data = fread($fh, 128 * 1024);
    fclose($fh);
    if (substr($data, 0, 2) !== "\xFF\xD8") return 1;
    $p = 2;
    $len = strlen($data);
    while ($p + 4 < $len && $data[$p] === "\xFF") {
        $marker = ord($data[$p + 1]);
        $size = unpack('n', substr($data, $p + 2, 2))[1];
        if ($marker === 0xE1 && substr($data, $p + 4, 6) === "Exif\0\0") {
            $t = $p + 10;
            $le = substr($data, $t, 2) === 'II';
            $u16 = fn($o) => unpack($le ? 'v' : 'n', substr($data, $o, 2))[1];
            $u32 = fn($o) => unpack($le ? 'V' : 'N', substr($data, $o, 4))[1];
            $ifd = $t + $u32($t + 4);
            if ($ifd + 2 > $len) return 1;
            $count = $u16($ifd);
            for ($i = 0; $i < $count; $i++) {
                $e = $ifd + 2 + $i * 12;
                if ($e + 12 > $len) break;
                if ($u16($e) === 0x0112) { $o = $u16($e + 8); return $o >= 1 && $o <= 8 ? $o : 1; }
            }
            return 1;
        }
        if ($marker === 0xDA) break;
        $p += 2 + $size;
    }
    return 1;
}

/** Otočí obrázek podle EXIF orientace. */
function img_apply_orientation($im, int $o) {
    switch ($o) {
        case 2: imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 3: $im = imagerotate($im, 180, 0); break;
        case 4: imageflip($im, IMG_FLIP_VERTICAL); break;
        case 5: $im = imagerotate($im, -90, 0); imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 6: $im = imagerotate($im, -90, 0); break;
        case 7: $im = imagerotate($im, 90, 0); imageflip($im, IMG_FLIP_HORIZONTAL); break;
        case 8: $im = imagerotate($im, 90, 0); break;
    }
    return $im;
}

/** Může mít soubor průhlednost? (PNG podle hlavičky; WebP/GIF raději ověřit pixely) */
function img_may_have_alpha(string $path, int $type): bool {
    if ($type === IMAGETYPE_JPEG || $type === IMAGETYPE_BMP) return false;
    if ($type !== IMAGETYPE_PNG) return true;
    $head = (string)@file_get_contents($path, false, null, 0, 64 * 1024);
    $colorType = strlen($head) > 25 ? ord($head[25]) : 6;
    if ($colorType === 4 || $colorType === 6) return true;   // šedá/RGB s alfa kanálem
    return str_contains($head, 'tRNS');                       // paleta nebo barva označená jako průhledná
}

/** Má obrázek opravdu nějaký průhledný pixel? */
function img_has_transparency($im): bool {
    $w = imagesx($im);
    $h = imagesy($im);
    $step = ($w * $h > 2500000) ? 2 : 1;
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if ((imagecolorat($im, $x, $y) >> 24) & 0x7F) return true;
        }
    }
    return false;
}

function img_memory_limit(): int {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/**
 * Zkomprimuje obrázek ze souboru $path.
 * Vrací null (není potřeba / nevyplatí se) nebo ['data', 'ext', 'mime', 'w', 'h', 'ow', 'oh'].
 * $force: i když nepřekračuje limity (ruční tlačítko). Výsledek se použije, jen když je menší.
 * Při nedostatku paměti hodí RuntimeException.
 */
function img_compress(string $path, array $s, bool $force = false): ?array {
    $info = @getimagesize($path);
    if (!$info) return null;
    [$w, $h, $type] = $info;
    if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)) return null;
    if ($type === IMAGETYPE_GIF && img_is_animated_gif($path)) return null;

    $size = (int)filesize($path);
    $orient = $type === IMAGETYPE_JPEG ? img_exif_orientation($path) : 1;
    [$rw, $rh] = $orient >= 5 ? [$h, $w] : [$w, $h];                 // rozměry po otočení
    $over = $rw > $s['max_w'] || $rh > $s['max_h'];
    $big = $size > $s['min_kb'] * 1024;
    if (!$over && !$big && !$force && $orient === 1) return null;

    // paměť: zdroj + otočená kopie + výsledek (4–5 B na pixel)
    $scale = min(1, $s['max_w'] / $rw, $s['max_h'] / $rh);
    $nw = max(1, (int)round($rw * $scale));
    $nh = max(1, (int)round($rh * $scale));
    $need = $w * $h * 5 * ($orient > 1 ? 2 : 1) + $nw * $nh * 5 + 16 * 1048576;
    if ($need > img_memory_limit() - memory_get_usage()) {
        @ini_set('memory_limit', (string)max(512, (int)ceil(($need + memory_get_usage()) / 1048576) + 32) . 'M');
        if ($need > img_memory_limit() - memory_get_usage()) {
            throw new RuntimeException(sprintf('Obrázek %d×%d px je na zpracování na serveru příliš velký (nestačí paměť). Zmenšete ho prosím před nahráním.', $w, $h));
        }
    }

    $src = @imagecreatefromstring((string)file_get_contents($path));
    if (!$src) return null;
    if (!imageistruecolor($src)) imagepalettetotruecolor($src);
    if ($orient > 1) $src = img_apply_orientation($src, $orient);

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));
    imagedestroy($src);

    $alpha = img_may_have_alpha($path, $type) && img_has_transparency($dst);
    ob_start();
    if ($alpha) {
        imagepng($dst, null, 9);
    } else {
        // bez průhlednosti → JPG (případné poloprůhledné okraje na černém podkladu)
        $flat = imagecreatetruecolor($nw, $nh);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 0, 0, 0));
        imagecopy($flat, $dst, 0, 0, 0, 0, $nw, $nh);
        imagejpeg($flat, null, $s['quality']);
        imagedestroy($flat);
    }
    $data = (string)ob_get_clean();
    imagedestroy($dst);

    // nevyplatí se → nechat originál (výjimka: fotka otočená podle EXIF, i kdyby byla o kousek větší)
    if ($data === '' || (strlen($data) >= $size && $orient === 1)) return null;
    return ['data' => $data, 'ext' => $alpha ? 'png' : 'jpg', 'mime' => $alpha ? 'image/png' : 'image/jpeg',
            'w' => $nw, 'h' => $nh, 'ow' => $rw, 'oh' => $rh];
}

/** Název s jinou příponou (zachová jméno, které uživatel zvolil). */
function name_with_ext(string $name, string $ext): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    return ($base !== '' ? $base : 'obrazek') . '.' . $ext;
}

/**
 * Zkomprimuje obrázek z galerie (vždy z originálu, pokud existuje).
 * Vrací popis změny, nebo null, když se nic nezměnilo.
 */
function media_compress(array $m, bool $force): ?string {
    if ($m['kind'] !== 'image') return null;
    $s = img_settings();
    $srcFile = $m['orig_filename'] ?: $m['filename'];
    $srcPath = MEDIA_DIR . '/' . $srcFile;
    if (!is_file($srcPath)) return null;
    $r = img_compress($srcPath, $s, $force);
    if (!$r) return null;

    $file = bin2hex(random_bytes(12)) . '.' . $r['ext'];
    file_put_contents(MEDIA_DIR . "/$file", $r['data']);
    $origSize = $m['orig_filename'] ? (int)$m['orig_size'] : (int)$m['size'];
    if ($m['orig_filename']) {
        // už komprimovaný → starou zmenšenou verzi nahradit, originál zůstává
        if ($m['filename'] !== $m['orig_filename']) @unlink(MEDIA_DIR . '/' . $m['filename']);
        q('UPDATE media SET filename = ?, mime = ?, size = ?, md5 = ?, original_name = ?, width = ?, height = ? WHERE id = ?', [
            $file, $r['mime'], strlen($r['data']), md5($r['data']), name_with_ext($m['original_name'], $r['ext']), $r['w'], $r['h'], $m['id']]);
    } else {
        q('UPDATE media SET filename = ?, mime = ?, size = ?, md5 = ?, original_name = ?, width = ?, height = ?,
           orig_filename = ?, orig_mime = ?, orig_size = ?, orig_w = ?, orig_h = ? WHERE id = ?', [
            $file, $r['mime'], strlen($r['data']), md5($r['data']), name_with_ext($m['original_name'], $r['ext']), $r['w'], $r['h'],
            $m['filename'], $m['mime'], (int)$m['size'], $r['ow'], $r['oh'], $m['id']]);
    }
    return sprintf('%s: %s → %s, %d×%d → %d×%d', $m['original_name'], fmt_bytes($origSize), fmt_bytes(strlen($r['data'])), $r['ow'], $r['oh'], $r['w'], $r['h']);
}

/** Vrátí původní (nekomprimovaný) soubor. */
function media_restore(array $m): bool {
    if (!$m['orig_filename'] || !is_file(MEDIA_DIR . '/' . $m['orig_filename'])) return false;
    if ($m['filename'] !== $m['orig_filename']) @unlink(MEDIA_DIR . '/' . $m['filename']);
    $path = MEDIA_DIR . '/' . $m['orig_filename'];
    $dim = @getimagesize($path) ?: [null, null];
    q('UPDATE media SET filename = ?, mime = ?, size = ?, md5 = ?, original_name = ?, width = ?, height = ?,
       orig_filename = NULL, orig_mime = NULL, orig_size = NULL, orig_w = NULL, orig_h = NULL WHERE id = ?', [
        $m['orig_filename'], $m['orig_mime'], filesize($path), md5_file($path),
        name_with_ext($m['original_name'], strtolower(pathinfo($m['orig_filename'], PATHINFO_EXTENSION))), $dim[0], $dim[1], $m['id']]);
    return true;
}

/** Smaže soubory média (aktuální i uchovaný originál). */
function media_unlink_files(array $m): void {
    if (!empty($m['filename'])) @unlink(MEDIA_DIR . '/' . $m['filename']);
    if (!empty($m['orig_filename']) && $m['orig_filename'] !== $m['filename']) @unlink(MEDIA_DIR . '/' . $m['orig_filename']);
}

function fmt_bytes(int $b): string {
    if ($b >= 1048576) return number_format($b / 1048576, 1, ',', ' ') . ' MB';
    return max(1, (int)round($b / 1024)) . ' kB';
}
