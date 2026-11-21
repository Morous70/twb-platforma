<?php
declare(strict_types=1);
namespace TWB\Slidy;

/** Základní kreslicí pomůcky nad GD. */
final class Gd
{
    public static function barva(\GdImage $im, string $hex, float $alfa = 1.0): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        return imagecolorallocatealpha($im, $r, $g, $b, (int)round(127 * (1 - max(0, min(1, $alfa)))));
    }

    public static function zaoblenyObdelnik(\GdImage $im, float $x1, float $y1, float $x2, float $y2, float $polomer, int $col): void
    {
        $x1 = (int)round($x1); $y1 = (int)round($y1); $x2 = (int)round($x2); $y2 = (int)round($y2);
        $w = $x2 - $x1; $h = $y2 - $y1;
        if ($w <= 0 || $h <= 0) return;
        $r = (int)max(0, min($polomer, $w / 2, $h / 2));
        // tvar se kreslí do pomocné vrstvy bez míchání barev, aby se poloprůhledné části nepřekrývaly
        $tmp = imagecreatetruecolor($w, $h);
        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        $c = imagecolorallocatealpha($tmp, ($col >> 16) & 0xff, ($col >> 8) & 0xff, $col & 0xff, ($col >> 24) & 0x7f);
        if ($r <= 0) {
            imagefilledrectangle($tmp, 0, 0, $w - 1, $h - 1, $c);
        } else {
            imagefilledrectangle($tmp, $r, 0, $w - 1 - $r, $h - 1, $c);
            imagefilledrectangle($tmp, 0, $r, $w - 1, $h - 1 - $r, $c);
            foreach ([[$r, $r], [$w - 1 - $r, $r], [$r, $h - 1 - $r], [$w - 1 - $r, $h - 1 - $r]] as [$cx, $cy]) {
                imagefilledellipse($tmp, $cx, $cy, 2 * $r, 2 * $r, $c);
            }
        }
        imagecopy($im, $tmp, $x1, $y1, 0, 0, $w, $h);
    }

    /** Vloží obrázek do obdélníku (cover = vyplnit a oříznout, contain = celý obrázek). */
    public static function vlozObrazek(\GdImage $dst, \GdImage $src, float $x, float $y, float $w, float $h, string $fit, float $alfa = 1.0): void
    {
        $sw = imagesx($src); $sh = imagesy($src);
        if ($fit === 'cover') {
            $s = max($w / $sw, $h / $sh);
            $cw = $w / $s; $ch = $h / $s;
            $sx = ($sw - $cw) / 2; $sy = ($sh - $ch) / 2;
            $dx = $x; $dy = $y; $dw = $w; $dh = $h;
        } else {
            $s = min($w / $sw, $h / $sh);
            $dw = $sw * $s; $dh = $sh * $s;
            $dx = $x + ($w - $dw) / 2; $dy = $y + ($h - $dh) / 2;
            $sx = 0; $sy = 0; $cw = $sw; $ch = $sh;
        }
        if ($alfa >= 0.999) {
            imagecopyresampled($dst, $src, (int)round($dx), (int)round($dy), (int)round($sx), (int)round($sy), (int)round($dw), (int)round($dh), (int)round($cw), (int)round($ch));
            return;
        }
        // průhlednost: zmenšená kopie s upraveným alfa kanálem
        $tmp = imagecreatetruecolor(max(1, (int)round($dw)), max(1, (int)round($dh)));
        imagealphablending($tmp, false); imagesavealpha($tmp, true);
        imagefill($tmp, 0, 0, imagecolorallocatealpha($tmp, 0, 0, 0, 127));
        imagecopyresampled($tmp, $src, 0, 0, (int)round($sx), (int)round($sy), imagesx($tmp), imagesy($tmp), (int)round($cw), (int)round($ch));
        $tw = imagesx($tmp); $th = imagesy($tmp);
        for ($yy = 0; $yy < $th; $yy++) for ($xx = 0; $xx < $tw; $xx++) {
            $c = imagecolorat($tmp, $xx, $yy);
            $a = ($c >> 24) & 0x7f;
            $na = 127 - (int)round((127 - $a) * $alfa);
            imagesetpixel($tmp, $xx, $yy, ($c & 0xffffff) | ($na << 24));
        }
        imagecopy($dst, $tmp, (int)round($dx), (int)round($dy), 0, 0, $tw, $th);
    }
}
