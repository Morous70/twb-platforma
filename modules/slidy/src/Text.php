<?php
declare(strict_types=1);
namespace TWB\Slidy;

use TWB\Pisma\Pisma;

/**
 * Text na plátně: měření, zalamování a rozvržení (stejný postup jako v editoru, slide-editor.js), kreslení včetně grafických efektů.
 * Objekt drží cache šířek znaků pro svá písma.
 */
final class Text
{
    /** Emoji a podobné symboly písma neobsahují – vynechají se (stejně jako v editoru). */
    public const EMOJI = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

    /** @var array<string,float> */
    private array $cacheZnaku = [];

    public function __construct(private Pisma $pisma) {}

    public function pisma(): Pisma { return $this->pisma; }

    public function soubor(string $pismo, int $tloustka): string { return $this->pisma->soubor($pismo, $tloustka); }

    public static function bezEmoji(string $s): string { return preg_replace(self::EMOJI, '', $s); }

    public function sirka(string $s, float $velikost, string $pismo, float $rozestup = 0): float
    {
        if ($s === '') return 0;
        if ($rozestup != 0) {
            // s rozestupem písmen: součet šířek znaků + rozestupy (stejně jako v editoru)
            $znaky = mb_str_split($s);
            $w = 0;
            foreach ($znaky as $c) $w += $this->posun($c, $velikost, $pismo);
            return $w + $rozestup * $velikost * (count($znaky) - 1);
        }
        $b = imagettfbbox($velikost * 0.75, 0, $pismo, $s);
        return (float)($b[2] - $b[0]);
    }

    /** Šířka jednoho znaku i s okraji (rozdíl šířek „HxH“ a „HH“, funguje i pro mezeru). */
    public function posun(string $c, float $velikost, string $pismo): float
    {
        $k = "$pismo|$velikost|$c";
        if (!isset($this->cacheZnaku[$k])) {
            $pt = $velikost * 0.75;
            $a = imagettfbbox($pt, 0, $pismo, "H{$c}H"); $b = imagettfbbox($pt, 0, $pismo, 'HH');
            $this->cacheZnaku[$k] = (float)(($a[2] - $a[0]) - ($b[2] - $b[0]));
        }
        return $this->cacheZnaku[$k];
    }

    /** Napíše řádek textu; s rozestupem písmen po jednotlivých znacích. */
    public function radek(\GdImage $im, float $pt, float $x, float $y, int $col, string $pismo, string $text, float $rozestupPx = 0): void
    {
        if ($rozestupPx == 0) { imagettftext($im, $pt, 0, (int)round($x), (int)round($y), $col, $pismo, $text); return; }
        $velikost = $pt / 0.75;
        foreach (mb_str_split($text) as $c) {
            if ($c !== ' ') imagettftext($im, $pt, 0, (int)round($x), (int)round($y), $col, $pismo, $c);
            $x += $this->posun($c, $velikost, $pismo) + $rozestupPx;
        }
    }

    /** Zalomí text do šířky (stejný postup jako v editoru). @return list<string> */
    public function zalom(string $text, float $velikost, string $pismo, float $maxW, float $rozestup = 0): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $text) as $para) {
            $words = preg_split('/ +/', $para);
            $line = '';
            foreach ($words as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->sirka($try, $velikost, $pismo, $rozestup) > $maxW) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
                // slovo delší než řádek: rozdělit po znacích
                while ($this->sirka($line, $velikost, $pismo, $rozestup) > $maxW && mb_strlen($line) > 1) {
                    $cut = mb_strlen($line) - 1;
                    while ($cut > 1 && $this->sirka(mb_substr($line, 0, $cut), $velikost, $pismo, $rozestup) > $maxW) $cut--;
                    $lines[] = mb_substr($line, 0, $cut);
                    $line = mb_substr($line, $cut);
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /** Spočítá rozvržení textu: velikost, řádky a jejich pozice. */
    public function rozvrh(array $e, string $text): array
    {
        $font = $this->soubor($e['font'], $e['weight']);
        $pad = $e['bg']['on'] ? $e['bg']['padding'] : 0;
        $ix = $e['x'] + $pad; $iy = $e['y'] + $pad;
        $iw = max(1, $e['w'] - 2 * $pad); $ih = max(1, $e['h'] - 2 * $pad);
        $text = self::bezEmoji($text);
        if ($e['upper']) $text = mb_strtoupper($text);
        $size = $e['size'];
        $sp = (float)($e['spacing'] ?? 0);
        $lines = $this->zalom($text, $size, $font, $iw, $sp);
        if ($e['autoFit']) {
            while ($size > 8 && count($lines) * $size * $e['lineHeight'] > $ih) {
                $size = max(8, floor($size * 0.94));
                $lines = $this->zalom($text, $size, $font, $iw, $sp);
            }
        }
        $lh = $size * $e['lineHeight'];
        $blockH = count($lines) * $lh;
        $top = match ($e['valign']) { 'middle' => $iy + ($ih - $blockH) / 2, 'bottom' => $iy + $ih - $blockH, default => $iy };
        $out = [];
        foreach ($lines as $i => $line) {
            $w = $this->sirka($line, $size, $font, $sp);
            $x = match ($e['align']) { 'center' => $ix + ($iw - $w) / 2, 'right' => $ix + $iw - $w, default => $ix };
            $out[] = ['text' => $line, 'x' => $x, 'y' => $top + $i * $lh + ($lh - $size) / 2 + $size * 0.8, 'w' => $w];
        }
        return ['font' => $font, 'size' => $size, 'lines' => $out, 'overflow' => $blockH > $ih + 0.5];
    }

    public function kresli(\GdImage $im, array $e, string $text): void
    {
        if ($e['bg']['on']) Gd::zaoblenyObdelnik($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['bg']['radius'], Gd::barva($im, $e['bg']['color'], $e['bg']['alpha'] * $e['alpha']));
        $L = $this->rozvrh($e, $text);
        if (!empty($e['fx'])) { $this->kresliFx($im, $e, $L); return; }
        $pt = $L['size'] * 0.75;
        $sp = (float)($e['spacing'] ?? 0) * $L['size'];
        $col = Gd::barva($im, $e['color'], $e['alpha']);
        foreach ($L['lines'] as $ln) {
            if ($ln['text'] === '') continue;
            $x = (int)round($ln['x']); $y = (int)round($ln['y']);
            if ($e['shadow']['on']) {
                $this->radek($im, $pt, $x + (int)$e['shadow']['dx'], $y + (int)$e['shadow']['dy'], Gd::barva($im, $e['shadow']['color'], $e['shadow']['alpha'] * $e['alpha']), $L['font'], $ln['text'], $sp);
            }
            if ($e['outline']['on']) {
                $oc = Gd::barva($im, $e['outline']['color'], $e['alpha']);
                $r = $e['outline']['width'];
                $steps = max(12, (int)ceil($r * 4));
                for ($k = 0; $k < $steps; $k++) {
                    $a = 2 * M_PI * $k / $steps;
                    $this->radek($im, $pt, $x + (int)round($r * cos($a)), $y + (int)round($r * sin($a)), $oc, $L['font'], $ln['text'], $sp);
                }
            }
            $this->radek($im, $pt, $x, $y, $col, $L['font'], $ln['text'], $sp);
        }
    }

    // ---------- Grafický text (záře, stín, 3D hloubka, obrysy, výplň přechodem, lesk) ----------
    // Stejné vrstvy kreslí editor na plátně (drawTextFx v slide-editor.js), aby náhled odpovídal.

    /** Řádky textu bíle na černém plátně oblasti, „ztučněné“ o r px (maska v červeném kanálu). */
    private function maska(int $w, int $h, array $L, float $rx, float $ry, float $r, float $dy, float $spPx): \GdImage
    {
        $m = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($m, 255, 255, 255);
        $this->kresliRadky($m, $L, $rx, $ry, $r, $dy, $white, $spPx);
        return $m;
    }

    /** Nakreslí řádky (s posunem dy) barvou $col, při r > 0 v kruhu kolem (obrys). */
    private function kresliRadky(\GdImage $im, array $L, float $ox, float $oy, float $r, float $dy, int $col, float $spPx): void
    {
        $pt = $L['size'] * 0.75;
        $steps = $r > 0 ? max(12, (int)ceil($r * 3)) : 0;
        foreach ($L['lines'] as $ln) {
            if ($ln['text'] === '') continue;
            $x = $ln['x'] - $ox; $y = $ln['y'] - $oy + $dy;
            for ($k = 0; $k < $steps; $k++) {
                $a = 2 * M_PI * $k / $steps;
                $this->radek($im, $pt, $x + $r * cos($a), $y + $r * sin($a), $col, $L['font'], $ln['text'], $spPx);
            }
            $this->radek($im, $pt, $x, $y, $col, $L['font'], $ln['text'], $spPx);
        }
    }

    private function kresliFx(\GdImage $im, array $e, array $L): void
    {
        $fx = $e['fx']; $size = $L['size']; $al = $e['alpha'];
        $lines = array_values(array_filter($L['lines'], fn($l) => $l['text'] !== ''));
        if (!$lines) return;
        $spPx = (float)($e['spacing'] ?? 0) * $size;
        $sw = $fx['stroke'] > 0 ? max(1.0, $size * $fx['stroke']) : 0;
        $sw2 = $fx['stroke2'] > 0 ? max(1.0, $size * $fx['stroke2']) : 0;
        $depth = (int)round($size * $fx['depth']);
        $glowR = $size * 0.06; $shX = (int)round($size * 0.03); $shY = $depth + (int)round($size * 0.05);
        // oblast kolem textu (všechny vrstvy se vejdou)
        $m = (int)ceil($sw + $glowR * 3 + $depth + $shY + 8);
        $x1 = min(array_map(fn($l) => $l['x'], $lines)) - $m; $x2 = max(array_map(fn($l) => $l['x'] + $l['w'], $lines)) + $m;
        $y1 = min(array_map(fn($l) => $l['y'], $lines)) - $size - $m; $y2 = max(array_map(fn($l) => $l['y'], $lines)) + $size * 0.35 + $m;
        $rx = (int)max(0, floor($x1)); $ry = (int)max(0, floor($y1));
        $rw = (int)min(imagesx($im), ceil($x2)) - $rx; $rh = (int)min(imagesy($im), ceil($y2)) - $ry;
        if ($rw <= 0 || $rh <= 0) return;
        // 1) záře
        if ($fx['glow'] > 0) {
            $g = Fx::rozmaz($this->maska($rw, $rh, $L, $rx, $ry, $sw + $glowR, $depth / 2, $spPx), $glowR * 2);
            Fx::nanes($im, $g, $rx, $ry, Fx::rgb($fx['glowColor']), $fx['glow'] * $al);
        }
        // 2) stín
        if ($fx['shadow'] > 0) {
            $s = Fx::rozmaz($this->maska($rw, $rh, $L, $rx, $ry, $sw, 0, $spPx), $size * 0.08);
            Fx::nanes($im, $s, $rx, $ry, [0, 0, 0], $fx['shadow'] * $al, $shX, $shY);
        }
        // 3) 3D hloubka: obrys posunutý dolů, směrem dolů tmavne
        $dc = Fx::rgb($fx['depthColor']);
        for ($k = $depth; $k >= 1; $k--) {
            $c = Fx::mix($dc, Fx::mix($dc, [0, 0, 0], 0.55), $k / max(1, $depth));
            $this->kresliRadky($im, $L, 0, 0, $sw, $k, Gd::barva($im, Fx::hex($c), $al), $spPx);
        }
        // 4) obrys a druhý (vnitřní) obrys
        if ($sw > 0) $this->kresliRadky($im, $L, 0, 0, $sw, 0, Gd::barva($im, $fx['strokeColor'], $al), $spPx);
        if ($sw2 > 0) $this->kresliRadky($im, $L, 0, 0, $sw2, 0, Gd::barva($im, $fx['stroke2Color'], $al), $spPx);
        // 5) výplň přechodem po řádcích (od výšky velkých písmen po účaří) + 6) lesk v horní části
        $rowBase = [];
        foreach ($lines as $ln) $rowBase[] = $ln['y'];
        $fill = $this->maska($rw, $rh, $L, $rx, $ry, 0, 0, $spPx);
        $barvaNaRadku = function (int $my) use ($rowBase, $ry, $size, $fx) {
            $y = $my + $ry; $base = $rowBase[0];
            foreach ($rowBase as $b) { $base = $b; if ($y <= $b + $size * 0.3) break; }
            $top = $base - $size * 0.72;
            $t = ($y - $top) / ($size * 0.72);
            $c = Fx::prechod($fx['grad'], $t);
            if ($fx['gloss'] > 0 && $t >= 0 && $t < 0.48) $c = Fx::mix($c, [255, 255, 245], $fx['gloss'] * (0.25 + 0.75 * (1 - $t / 0.48)));
            return $c;
        };
        Fx::nanes($im, $fill, $rx, $ry, [0, 0, 0], $al, 0, 0, $barvaNaRadku);
    }
}
