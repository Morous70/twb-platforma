<?php
declare(strict_types=1);
namespace TWB\Obrazky;

use TWB\Jadro\Nastaveni;

/**
 * Komprese obrázků: zmenšení do maximálního rozměru a uložení jako JPG; obrázky se skutečnou průhledností
 * jen zmenšené jako PNG. Modul pracuje se soubory a daty; ukládání do databáze (původní soubor, obnovení)
 * řeší galerie. Objekty GD se neuvolňují imagedestroy() – od PHP 8.5 je zastaralé a nic nedělá.
 */
final class Obrazky
{
    /** Nastavení komprese z nastavení aplikace. @return array{auto: bool, max_w: int, max_h: int, quality: int, min_kb: int} */
    public static function nastaveni(Nastaveni $n): array
    {
        return [
            'auto' => $n->dej('img_auto', '1') === '1',
            'max_w' => max(320, (int)$n->dej('img_max_w', 1920)),
            'max_h' => max(240, (int)$n->dej('img_max_h', 1080)),
            'quality' => min(100, max(30, (int)$n->dej('img_quality', 85))),
            'min_kb' => max(0, (int)$n->dej('img_min_kb', 1024)),
        ];
    }

    /**
     * Animovaný GIF (víc snímků) se nekomprimuje, přišel by o animaci. Soubor se prochází po blocích podle specifikace GIF,
     * takže pozná i GIF s pouhými dvěma snímky (blikající banner).
     */
    public static function jeAnimovanyGif(string $cesta): bool
    {
        $fh = @fopen($cesta, 'rb');
        if (!$fh) return false;
        try {
            $hlavicka = (string)fread($fh, 13);
            if (strlen($hlavicka) < 13 || !in_array(substr($hlavicka, 0, 6), ['GIF87a', 'GIF89a'], true)) return false;
            $priznaky = ord($hlavicka[10]);
            if ($priznaky & 0x80) fseek($fh, 3 * (1 << (($priznaky & 7) + 1)), SEEK_CUR);   // globální paleta
            $snimku = 0;
            while (($b = fread($fh, 1)) !== false && $b !== '') {
                if ($b === "\x3B") break;                                                    // konec souboru
                if ($b === "\x21") {                                                         // rozšíření: značka + podbloky
                    fread($fh, 1);
                    self::preskocPodbloky($fh);
                } elseif ($b === "\x2C") {                                                   // snímek
                    if (++$snimku > 1) return true;
                    $popis = (string)fread($fh, 9);
                    if (strlen($popis) < 9) return false;
                    if (ord($popis[8]) & 0x80) fseek($fh, 3 * (1 << ((ord($popis[8]) & 7) + 1)), SEEK_CUR);   // lokální paleta
                    fread($fh, 1);                                                            // minimální velikost kódu LZW
                    self::preskocPodbloky($fh);
                } else {
                    return false;                                                             // poškozený soubor
                }
            }
            return false;
        } finally {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private static function preskocPodbloky($fh): void
    {
        while (($d = fread($fh, 1)) !== false && $d !== '') {
            $n = ord($d);
            if ($n === 0) return;
            fseek($fh, $n, SEEK_CUR);
        }
    }

    /** Orientace z EXIF (1–8) u JPG; bez rozšíření exif ji přečte z hlavičky sám. */
    public static function exifOrientace(string $cesta): int
    {
        if (function_exists('exif_read_data')) {
            $e = @exif_read_data($cesta);
            if ($e && isset($e['Orientation'])) return (int)$e['Orientation'];
        }
        $fh = @fopen($cesta, 'rb');
        if (!$fh) return 1;
        $data = (string)fread($fh, 128 * 1024);
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
    public static function otoc(\GdImage $im, int $o): \GdImage
    {
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
    public static function muzeMitPruhlednost(string $cesta, int $typ): bool
    {
        if ($typ === IMAGETYPE_JPEG || $typ === IMAGETYPE_BMP) return false;
        if ($typ !== IMAGETYPE_PNG) return true;
        $head = (string)@file_get_contents($cesta, false, null, 0, 64 * 1024);
        $colorType = strlen($head) > 25 ? ord($head[25]) : 6;
        if ($colorType === 4 || $colorType === 6) return true;   // šedá/RGB s alfa kanálem
        return str_contains($head, 'tRNS');                       // paleta nebo barva označená jako průhledná
    }

    /** Má obrázek opravdu nějaký průhledný pixel? */
    public static function maPruhlednost(\GdImage $im): bool
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $krok = ($w * $h > 2500000) ? 2 : 1;
        for ($y = 0; $y < $h; $y += $krok) {
            for ($x = 0; $x < $w; $x += $krok) {
                if ((imagecolorat($im, $x, $y) >> 24) & 0x7F) return true;
            }
        }
        return false;
    }

    private static function limitPameti(): int
    {
        $v = trim((string)ini_get('memory_limit'));
        if ($v === '' || $v === '-1') return PHP_INT_MAX;
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    /**
     * Zkomprimuje obrázek ze souboru. Vrací null (není potřeba / nevyplatí se) nebo
     * ['data', 'ext', 'mime', 'w', 'h', 'ow', 'oh']. $vynutit: i když nepřekračuje limity (ruční tlačítko).
     * Při nedostatku paměti hodí RuntimeException.
     * @param array{max_w: int, max_h: int, quality: int, min_kb: int} $s
     * @return array{data: string, ext: string, mime: string, w: int, h: int, ow: int, oh: int}|null
     */
    public static function komprimuj(string $cesta, array $s, bool $vynutit = false): ?array
    {
        $info = @getimagesize($cesta);
        if (!$info) return null;
        [$w, $h, $typ] = $info;
        if (!in_array($typ, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_BMP], true)) return null;
        if ($typ === IMAGETYPE_GIF && self::jeAnimovanyGif($cesta)) return null;

        $velikost = (int)filesize($cesta);
        $orient = $typ === IMAGETYPE_JPEG ? self::exifOrientace($cesta) : 1;
        [$rw, $rh] = $orient >= 5 ? [$h, $w] : [$w, $h];                 // rozměry po otočení
        $pres = $rw > $s['max_w'] || $rh > $s['max_h'];
        $velky = $velikost > $s['min_kb'] * 1024;
        if (!$pres && !$velky && !$vynutit && $orient === 1) return null;

        // paměť: zdroj + otočená kopie + výsledek (4–5 B na pixel)
        $meritko = min(1, $s['max_w'] / $rw, $s['max_h'] / $rh);
        $nw = max(1, (int)round($rw * $meritko));
        $nh = max(1, (int)round($rh * $meritko));
        $potreba = $w * $h * 5 * ($orient > 1 ? 2 : 1) + $nw * $nh * 5 + 16 * 1048576;
        if ($potreba > self::limitPameti() - memory_get_usage()) {
            @ini_set('memory_limit', (string)max(512, (int)ceil(($potreba + memory_get_usage()) / 1048576) + 32) . 'M');
            if ($potreba > self::limitPameti() - memory_get_usage()) {
                throw new \RuntimeException(sprintf('Obrázek %d×%d px je na zpracování na serveru příliš velký (nestačí paměť). Zmenšete ho prosím před nahráním.', $w, $h));
            }
        }

        $src = @imagecreatefromstring((string)file_get_contents($cesta));
        if (!$src) return null;
        if (!imageistruecolor($src)) imagepalettetotruecolor($src);
        if ($orient > 1) $src = self::otoc($src, $orient);

        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, imagesx($src), imagesy($src));

        $alfa = self::muzeMitPruhlednost($cesta, $typ) && self::maPruhlednost($dst);
        ob_start();
        if ($alfa) {
            imagepng($dst, null, 9);
        } else {
            // bez průhlednosti → JPG (případné poloprůhledné okraje na černém podkladu)
            $flat = imagecreatetruecolor($nw, $nh);
            imagefill($flat, 0, 0, imagecolorallocate($flat, 0, 0, 0));
            imagecopy($flat, $dst, 0, 0, 0, 0, $nw, $nh);
            imagejpeg($flat, null, $s['quality']);
        }
        $data = (string)ob_get_clean();

        // nevyplatí se → nechat originál (výjimka: fotka otočená podle EXIF, i kdyby byla o kousek větší)
        if ($data === '' || (strlen($data) >= $velikost && $orient === 1)) return null;
        return ['data' => $data, 'ext' => $alfa ? 'png' : 'jpg', 'mime' => $alfa ? 'image/png' : 'image/jpeg',
                'w' => $nw, 'h' => $nh, 'ow' => $rw, 'oh' => $rh];
    }

    /** Název s jinou příponou (zachová jméno, které uživatel zvolil). */
    public static function nazevSPriponou(string $nazev, string $pripona): string
    {
        $zaklad = pathinfo($nazev, PATHINFO_FILENAME);
        return ($zaklad !== '' ? $zaklad : 'obrazek') . '.' . $pripona;
    }

    /** Velikost souboru česky: „1,5 MB“, „230 kB“. */
    public static function velikost(int $bajtu): string
    {
        if ($bajtu >= 1048576) return number_format($bajtu / 1048576, 1, ',', ' ') . ' MB';
        return max(1, (int)round($bajtu / 1024)) . ' kB';
    }
}
