<?php
declare(strict_types=1);
namespace TWB\Slidy;

/** Kuličky s čísly (tažená čísla loterií): rozmístění v rámečku a vyhlazené vykreslení. Stejný výpočet je v editoru (layoutBalls). */
final class Kulicky
{
    /** Čísla z textu („22 14 27“, „22, 14, 27“ …). Neznámá proměnná ve složených závorkách se ignoruje. @return list<string> */
    public static function cisla(string $s): array
    {
        $s = preg_replace('/\{[^{}]*\}/u', '', $s);
        preg_match_all('/\d+/', $s, $m);
        return array_slice($m[0], 0, 100);
    }

    /**
     * Rozmístění kuliček v rámečku. Čísla se řadí zleva, zalomí se podle šířky; když se nevejdou, kuličky se zmenší.
     * @return array{d: float|int, balls: list<array{x:float,y:float,n:string,extra:bool}>}
     */
    public static function rozvrh(array $e, array $nums, array $extras): array
    {
        $items = [];
        foreach ($nums as $n) $items[] = [(string)$n, false];
        foreach ($extras as $n) $items[] = [(string)$n, true];
        $d = $e['size'];
        while (true) {
            $g = $e['gap'] * $d / $e['size'];
            $eg = $e['extraGap'] * $d / $e['size'];
            $rows = []; $row = []; $rw = 0.0; $prevExtra = null; $maxW = 0.0;
            foreach ($items as [$n, $ex]) {
                $sp = !$row ? 0 : (($ex && $prevExtra === false) ? $eg : $g);
                if ($row && $rw + $sp + $d > $e['w'] + 0.01) { $rows[] = [$row, $rw]; $maxW = max($maxW, $rw); $row = []; $rw = 0.0; $sp = 0; }
                $row[] = [$n, $ex, $rw + $sp];
                $rw += $sp + $d;
                $prevExtra = $ex;
            }
            if ($row) { $rows[] = [$row, $rw]; $maxW = max($maxW, $rw); }
            $totalH = count($rows) * $d + max(0, count($rows) - 1) * $g;
            if (($totalH <= $e['h'] + 0.01 && $maxW <= $e['w'] + 0.01) || $d <= 12) break;
            $d = max(12, floor($d * 0.94));
        }
        $y0 = match ($e['valign']) { 'top' => $e['y'], 'bottom' => $e['y'] + $e['h'] - $totalH, default => $e['y'] + ($e['h'] - $totalH) / 2 };
        $balls = [];
        foreach ($rows as $ri => [$r, $w]) {
            $x0 = match ($e['align']) { 'left' => $e['x'], 'right' => $e['x'] + $e['w'] - $w, default => $e['x'] + ($e['w'] - $w) / 2 };
            foreach ($r as [$n, $ex, $off]) $balls[] = ['x' => $x0 + $off + $d / 2, 'y' => $y0 + $ri * ($d + $g) + $d / 2, 'n' => $n, 'extra' => $ex];
        }
        return ['d' => $d, 'balls' => $balls];
    }

    /** Barva bodu kuličky: plochá, nebo plastická (osvětlení zleva shora a odlesk). */
    public static function stin(array $rgb, float $nx, float $ny, bool $leskla): array
    {
        if (!$leskla) return $rgb;
        static $L = null, $H = null;
        if ($L === null) {
            $l = [-0.5, -0.65, 0.58]; $n = sqrt($l[0] ** 2 + $l[1] ** 2 + $l[2] ** 2); $L = [$l[0] / $n, $l[1] / $n, $l[2] / $n];
            $h = [$L[0], $L[1], $L[2] + 1]; $n = sqrt($h[0] ** 2 + $h[1] ** 2 + $h[2] ** 2); $H = [$h[0] / $n, $h[1] / $n, $h[2] / $n];
        }
        $rr = min(1.0, $nx * $nx + $ny * $ny);
        $nz = sqrt(1 - $rr);
        $diff = max(0.0, $nx * $L[0] + $ny * $L[1] + $nz * $L[2]);
        $spec = max(0.0, $nx * $H[0] + $ny * $H[1] + $nz * $H[2]) ** 40;
        $k = 0.38 + 0.72 * $diff;
        return [min(255, $rgb[0] * $k + 178 * $spec), min(255, $rgb[1] * $k + 178 * $spec), min(255, $rgb[2] * $k + 178 * $spec)];
    }

    /** Vyhlazená kulička kreslená po pixelech (GD kruhy nevyhlazuje). */
    public static function kulicka(\GdImage $im, float $cx, float $cy, float $r, array $e, bool $extra): void
    {
        $fill = sscanf($extra ? $e['extraFill'] : $e['fill'], '#%02x%02x%02x');
        $stroke = sscanf($e['stroke']['color'], '#%02x%02x%02x');
        $sw = $e['stroke']['on'] ? min($e['stroke']['width'], $r) : 0;
        $glossy = $e['style'] === 'glossy';
        $x1 = max(0, (int)floor($cx - $r - 1)); $x2 = min(imagesx($im) - 1, (int)ceil($cx + $r + 1));
        $y1 = max(0, (int)floor($cy - $r - 1)); $y2 = min(imagesy($im) - 1, (int)ceil($cy + $r + 1));
        for ($y = $y1; $y <= $y2; $y++) {
            for ($x = $x1; $x <= $x2; $x++) {
                $dx = $x + 0.5 - $cx; $dy = $y + 0.5 - $cy;
                $dist = sqrt($dx * $dx + $dy * $dy);
                $cov = max(0.0, min(1.0, $r - $dist + 0.5));
                if ($cov <= 0) continue;
                $c = self::stin($fill, $dx / $r, $dy / $r, $glossy);
                if ($sw > 0) {
                    $in = max(0.0, min(1.0, ($r - $sw) - $dist + 0.5));
                    $c = [$stroke[0] + ($c[0] - $stroke[0]) * $in, $stroke[1] + ($c[1] - $stroke[1]) * $in, $stroke[2] + ($c[2] - $stroke[2]) * $in];
                }
                $a = $cov * $e['alpha'];
                $dst = imagecolorat($im, $x, $y);
                $nr = (int)round($c[0] * $a + (($dst >> 16) & 0xff) * (1 - $a));
                $ng = (int)round($c[1] * $a + (($dst >> 8) & 0xff) * (1 - $a));
                $nb = (int)round($c[2] * $a + ($dst & 0xff) * (1 - $a));
                imagesetpixel($im, $x, $y, ($nr << 16) | ($ng << 8) | $nb);
            }
        }
    }

    public static function kresli(\GdImage $im, array $e, array $nums, array $extras, Text $text): void
    {
        $L = self::rozvrh($e, $nums, $extras);
        $font = $text->soubor($e['font'], $e['weight']);
        $pt = $L['d'] * $e['numScale'] * 0.75;
        foreach ($L['balls'] as $b) {
            self::kulicka($im, $b['x'], $b['y'], $L['d'] / 2, $e, $b['extra']);
            $bb = imagettfbbox($pt, 0, $font, $b['n']);
            $x = $b['x'] - ($bb[0] + $bb[2]) / 2;
            $y = $b['y'] - ($bb[1] + $bb[7]) / 2;
            imagettftext($im, $pt, 0, (int)round($x), (int)round($y), Gd::barva($im, $b['extra'] ? $e['extraColor'] : $e['color'], $e['alpha']), $font, $b['n']);
        }
    }
}
