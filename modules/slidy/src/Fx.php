<?php
declare(strict_types=1);
namespace TWB\Slidy;

/**
 * Grafický text: hotové styly (zlato, stříbro … ), očištění nastavení a barevná matematika.
 * Hodnoty tlouštěk a hloubky jsou násobky velikosti písma; síly 0–1. Přechod: 4 barvy shora dolů (pozice STOPS).
 */
final class Fx
{
    public const STOPS = [0, 0.38, 0.62, 1];
    public const STYLY = [
        'zlato'    => ['name' => 'Zlato',    'light' => false, 'grad' => ['#fffbd0', '#ffd84a', '#f4a611', '#c86800'], 'stroke' => 0.035, 'strokeColor' => '#3b1c00', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.085, 'depthColor' => '#b86a00', 'glow' => 0.55, 'glowColor' => '#ff9d1a', 'shadow' => 0.7, 'gloss' => 0.38],
        'stribro'  => ['name' => 'Stříbro',  'light' => false, 'grad' => ['#ffffff', '#dfe6ee', '#9aa7b6', '#d8e0e8'], 'stroke' => 0.03, 'strokeColor' => '#1c2430', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.07, 'depthColor' => '#6c7886', 'glow' => 0.35, 'glowColor' => '#9fd3ff', 'shadow' => 0.7, 'gloss' => 0.4],
        'cervena'  => ['name' => 'Červená',  'light' => false, 'grad' => ['#ffd2c2', '#ff5a3c', '#d81e1e', '#8a0a0a'], 'stroke' => 0.035, 'strokeColor' => '#3a0000', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.08, 'depthColor' => '#8f0d0d', 'glow' => 0.5, 'glowColor' => '#ff3b2f', 'shadow' => 0.7, 'gloss' => 0.32],
        'modra'    => ['name' => 'Modrá',    'light' => false, 'grad' => ['#d6ecff', '#55a8ff', '#1f6fe0', '#0c3a8a'], 'stroke' => 0.035, 'strokeColor' => '#031a3a', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.08, 'depthColor' => '#0f4aa0', 'glow' => 0.5, 'glowColor' => '#3aa0ff', 'shadow' => 0.7, 'gloss' => 0.35],
        'zelena'   => ['name' => 'Zelená',   'light' => false, 'grad' => ['#e2ffd2', '#6fe05a', '#25a83a', '#0d5e20'], 'stroke' => 0.035, 'strokeColor' => '#032a0c', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.08, 'depthColor' => '#127a2a', 'glow' => 0.45, 'glowColor' => '#49e070', 'shadow' => 0.7, 'gloss' => 0.32],
        'bila'     => ['name' => 'Bílá se stínem', 'light' => false, 'grad' => ['#ffffff', '#ffffff', '#f0f0f0', '#d6d6d6'], 'stroke' => 0.03, 'strokeColor' => '#222222', 'stroke2' => 0, 'stroke2Color' => '#ffffff', 'depth' => 0.05, 'depthColor' => '#8a8a8a', 'glow' => 0, 'glowColor' => '#ffffff', 'shadow' => 0.8, 'gloss' => 0],
        'modra_l'  => ['name' => 'Tmavě modrá', 'light' => true, 'grad' => ['#4f8dff', '#2f6ae8', '#1f4fd1', '#0e2a7a'], 'stroke' => 0.05, 'strokeColor' => '#0b1a3a', 'stroke2' => 0.022, 'stroke2Color' => '#ffffff', 'depth' => 0.06, 'depthColor' => '#0b1a3a', 'glow' => 0, 'glowColor' => '#000000', 'shadow' => 0.45, 'gloss' => 0.22],
        'cerna_l'  => ['name' => 'Černá',    'light' => true, 'grad' => ['#4a4a4a', '#2a2a2a', '#1c1c1c', '#000000'], 'stroke' => 0.045, 'strokeColor' => '#000000', 'stroke2' => 0.02, 'stroke2Color' => '#ffd400', 'depth' => 0.05, 'depthColor' => '#000000', 'glow' => 0, 'glowColor' => '#000000', 'shadow' => 0.45, 'gloss' => 0.22],
        'cervena_l' => ['name' => 'Červená', 'light' => true, 'grad' => ['#ff6a4a', '#f03a2a', '#e01b1b', '#8a0505'], 'stroke' => 0.05, 'strokeColor' => '#4a0000', 'stroke2' => 0.022, 'stroke2Color' => '#ffffff', 'depth' => 0.06, 'depthColor' => '#5a0000', 'glow' => 0, 'glowColor' => '#000000', 'shadow' => 0.45, 'gloss' => 0.22],
        'zelena_l' => ['name' => 'Zelená',   'light' => true, 'grad' => ['#7be36b', '#3cc04a', '#22a83a', '#0d6a22'], 'stroke' => 0.05, 'strokeColor' => '#0a2a10', 'stroke2' => 0.022, 'stroke2Color' => '#ffffff', 'depth' => 0.06, 'depthColor' => '#0b3a14', 'glow' => 0, 'glowColor' => '#000000', 'shadow' => 0.45, 'gloss' => 0.22],
        'zlato_l'  => ['name' => 'Zlato s tmavým obrysem', 'light' => true, 'grad' => ['#fff3a0', '#ffc72c', '#e89400', '#a85a00'], 'stroke' => 0.05, 'strokeColor' => '#2a1300', 'stroke2' => 0.02, 'stroke2Color' => '#fff3b0', 'depth' => 0.07, 'depthColor' => '#6a3a00', 'glow' => 0, 'glowColor' => '#000000', 'shadow' => 0.45, 'gloss' => 0.22],
    ];
    /** Rozsahy doladitelných hodnot grafického stylu. */
    public const ROZSAHY = ['stroke' => [0, 0.15], 'stroke2' => [0, 0.08], 'depth' => [0, 0.25], 'glow' => [0, 1], 'shadow' => [0, 1], 'gloss' => [0, 1]];
    public const BARVY = ['strokeColor', 'stroke2Color', 'depthColor', 'glowColor'];

    /** Očistí grafický styl textu; null = obyčejný text. */
    public static function vycisti(mixed $fx): ?array
    {
        if (!is_array($fx) || !isset(self::STYLY[$fx['style'] ?? ''])) return null;
        $p = self::STYLY[$fx['style']];
        $out = ['style' => $fx['style']];
        $g = array_values((array)($fx['grad'] ?? []));
        for ($i = 0; $i < 4; $i++) $out['grad'][] = Navrh::barva($g[$i] ?? null, $p['grad'][$i]);
        foreach (self::ROZSAHY as $k => [$lo, $hi]) $out[$k] = Navrh::cislo($fx[$k] ?? $p[$k], $lo, $hi, $p[$k]);
        foreach (self::BARVY as $k) $out[$k] = Navrh::barva($fx[$k] ?? null, $p[$k]);
        return $out;
    }

    public static function rgb(string $hex): array { return sscanf($hex, '#%02x%02x%02x'); }
    public static function mix(array $a, array $b, float $t): array { return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t]; }
    public static function hex(array $c): string { return sprintf('#%02x%02x%02x', max(0, min(255, (int)round($c[0]))), max(0, min(255, (int)round($c[1]))), max(0, min(255, (int)round($c[2])))); }

    /** Barva přechodu v poměru t (0 = nahoře, 1 = dole). */
    public static function prechod(array $grad, float $t): array
    {
        $t = max(0.0, min(1.0, $t));
        for ($i = 1; $i < 4; $i++) if ($t <= self::STOPS[$i]) {
            $p0 = self::STOPS[$i - 1]; $p1 = self::STOPS[$i];
            return self::mix(self::rgb($grad[$i - 1]), self::rgb($grad[$i]), $p1 > $p0 ? ($t - $p0) / ($p1 - $p0) : 0);
        }
        return self::rgb($grad[3]);
    }

    /** Rozmazání (zmenšení, Gauss, zvětšení) – rychlé i pro velký poloměr. */
    public static function rozmaz(\GdImage $img, float $radius): \GdImage
    {
        $w = imagesx($img); $h = imagesy($img); $f = max(1.0, $radius / 3);
        $s = imagecreatetruecolor(max(1, (int)round($w / $f)), max(1, (int)round($h / $f)));
        imagecopyresampled($s, $img, 0, 0, 0, 0, imagesx($s), imagesy($s), $w, $h);
        for ($i = 0; $i < 3; $i++) imagefilter($s, IMG_FILTER_GAUSSIAN_BLUR);
        // zpět na původní velikost po krocích nejvýš dvojnásobných, každý s dalším rozmazáním (jinak jsou vidět čtverečky a pruhy)
        $cur = $s;
        while (imagesx($cur) < $w || imagesy($cur) < $h) {
            $nw = min($w, max(imagesx($cur) + 1, imagesx($cur) * 2)); $nh = min($h, max(imagesy($cur) + 1, imagesy($cur) * 2));
            $n = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($n, $cur, 0, 0, 0, 0, $nw, $nh, imagesx($cur), imagesy($cur));
            imagefilter($n, IMG_FILTER_GAUSSIAN_BLUR);
            $cur = $n;
        }
        return $cur;
    }

    /** Přimíchá do $im barvu podle masky (oblast od rx, ry; posun ox, oy). $barvaNaRadku(y) volitelně podle řádku. */
    public static function nanes(\GdImage $im, \GdImage $maska, int $rx, int $ry, array $rgb, float $sila, int $ox = 0, int $oy = 0, ?callable $barvaNaRadku = null): void
    {
        if ($sila <= 0) return;
        $w = imagesx($maska); $h = imagesy($maska); $W = imagesx($im); $H = imagesy($im);
        for ($my = 0; $my < $h; $my++) {
            $y = $ry + $my + $oy; if ($y < 0 || $y >= $H) continue;
            $c = $barvaNaRadku ? $barvaNaRadku($my) : $rgb;
            for ($mx = 0; $mx < $w; $mx++) {
                $v = imagecolorat($maska, $mx, $my) >> 16 & 255;
                if ($v < 2) continue;
                $x = $rx + $mx + $ox; if ($x < 0 || $x >= $W) continue;
                $a = $v / 255 * $sila; if ($a > 1) $a = 1;
                $o = imagecolorat($im, $x, $y);
                imagesetpixel($im, $x, $y, ((int)(($o >> 16 & 255) * (1 - $a) + $c[0] * $a) << 16) | ((int)(($o >> 8 & 255) * (1 - $a) + $c[1] * $a) << 8) | (int)(($o & 255) * (1 - $a) + $c[2] * $a));
            }
        }
    }
}
