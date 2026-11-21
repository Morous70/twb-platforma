<?php
declare(strict_types=1);
namespace TWB\Slidy;

use DateTimeImmutable;
use RuntimeException;

/** Vykreslení šablony do obrázku (JPEG). Vyhodnocení podmínek a proměnných pro daný okamžik, klíč obsahu pro přegenerování. */
final class Vykresleni
{
    public function __construct(
        private Navrh $navrh,
        private Text $text,
        private Zdroje $zdroje,
        private Promenne $promenne,
    ) {}

    public function text(): Text { return $this->text; }

    /**
     * Vyhodnotí prvky šablony v daném okamžiku: viditelnost (podmínka „Zobrazit jen když“),
     * zvolená varianta textu a text po dosazení proměnných. @return array<string, array<string,mixed>>
     */
    public function vyhodnot(array $design, DateTimeImmutable $t): array
    {
        $res = [];
        foreach ($design['elements'] as $e) {
            $r = ['visible' => $this->promenne->splneno($e['showIf'] ?? [], $t)];
            if ($e['type'] === 'text') {
                $src = $e['text'];
                $r['variant'] = -1;
                foreach ($e['variants'] ?? [] as $i => $v) {
                    if (($v['when'] ?? []) && $this->promenne->splneno($v['when'], $t)) { $src = $v['text']; $r['variant'] = $i; break; }
                }
                [$r['text'], $r['unknown']] = $this->promenne->vyres($src, $t);
            } elseif ($e['type'] === 'balls') {
                [$a, $u1] = $this->promenne->vyres($e['src'], $t);
                [$b, $u2] = $this->promenne->vyres($e['extra'], $t);
                $r['nums'] = Kulicky::cisla($a);
                $r['extras'] = Kulicky::cisla($b);
                $r['unknown'] = array_values(array_unique([...$u1, ...$u2]));
            } elseif (isset($this->navrh->prvky()[$e['type']])) {
                $r += $this->navrh->prvky()[$e['type']]->vyhodnot($e, $t);
            }
            $res[$e['id']] = $r;
        }
        return $res;
    }

    /** Klíč obsahu: změní se, když se změní šablona, dosazené texty nebo použité obrázky. */
    public function klic(array $design, DateTimeImmutable $t): string
    {
        $md5s = [];
        foreach (Navrh::mediaIds($design) as $id) $md5s[$id] = $this->zdroje->podpis($id);
        return md5(json_encode([$design, $this->vyhodnot($design, $t), $md5s]));
    }

    /** Vykreslí šablonu a vrátí JPEG (data). */
    public function vykresli(array $design, DateTimeImmutable $t, int $kvalita = 90): string
    {
        if (!function_exists('imagettftext')) throw new RuntimeException('Na serveru chybí knihovna GD s podporou písem (FreeType).');
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && self::bajty((string)$limit) < 512 * 1048576) @ini_set('memory_limit', '512M');
        $W = (int)$design['w']; $H = (int)$design['h'];
        $im = imagecreatetruecolor($W, $H);
        imagealphablending($im, true);
        $bg = $design['bg'];
        imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, Gd::barva($im, $bg['color']));
        if ($bg['type'] === 'gradient') {
            [$r1, $g1, $b1] = sscanf($bg['color'], '#%02x%02x%02x');
            [$r2, $g2, $b2] = sscanf($bg['color2'], '#%02x%02x%02x');
            $n = $bg['dir'] === 'h' ? $W : $H;
            for ($i = 0; $i < $n; $i++) {
                $k = $n > 1 ? $i / ($n - 1) : 0;
                $c = imagecolorallocate($im, (int)round($r1 + ($r2 - $r1) * $k), (int)round($g1 + ($g2 - $g1) * $k), (int)round($b1 + ($b2 - $b1) * $k));
                $bg['dir'] === 'h' ? imageline($im, $i, 0, $i, $H - 1, $c) : imageline($im, 0, $i, $W - 1, $i, $c);
            }
        } elseif ($bg['type'] === 'image' && ($src = $this->zdroje->obrazek($bg['mediaId']))) {
            Gd::vlozObrazek($im, $src, 0, 0, $W, $H, $bg['fit']);
        }
        if ($bg['dim'] > 0) imagefilledrectangle($im, 0, 0, $W - 1, $H - 1, Gd::barva($im, '#000000', $bg['dim']));

        $ev = $this->vyhodnot($design, $t);
        foreach ($design['elements'] as $e) {
            if ($e['hidden'] || empty($ev[$e['id']]['visible'])) continue;
            if ($e['type'] === 'rect') {
                Gd::zaoblenyObdelnik($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['radius'], Gd::barva($im, $e['color'], $e['alpha']));
            } elseif ($e['type'] === 'image') {
                if ($src = $this->zdroje->obrazek($e['mediaId'])) Gd::vlozObrazek($im, $src, $e['x'], $e['y'], $e['w'], $e['h'], $e['fit'], $e['alpha']);
            } elseif ($e['type'] === 'balls') {
                Kulicky::kresli($im, $e, $ev[$e['id']]['nums'] ?? [], $ev[$e['id']]['extras'] ?? [], $this->text);
            } elseif (isset($this->navrh->prvky()[$e['type']])) {
                $this->navrh->prvky()[$e['type']]->kresli($im, $e, $ev[$e['id']], $this->text);
            } else {
                $this->text->kresli($im, $e, $ev[$e['id']]['text'] ?? $e['text']);
            }
        }
        ob_start();
        imagejpeg($im, null, $kvalita);
        return (string)ob_get_clean();
    }

    private static function bajty(string $v): int
    {
        $n = (int)$v;
        return match (strtolower(substr(trim($v), -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }
}
