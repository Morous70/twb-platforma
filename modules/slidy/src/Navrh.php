<?php
declare(strict_types=1);
namespace TWB\Slidy;

use TWB\Pisma\Pisma;

/**
 * Šablona slidu/banneru (JSON): plátno, pozadí a prvky. Očištění dat z prohlížeče (jen známé vlastnosti, rozsahy čísel).
 * Formáty plátna určuje aplikace (Social Planner: čtverec, na výšku, story …; Reklamy: bannery); volitelně i vlastní rozměr.
 */
final class Navrh
{
    public const OPERATORY = ['=', '!=', '>', '<', '>=', '<=', 'contains', 'empty', 'notempty'];
    public const ZAKLADNI_PRVKY = ['text', 'rect', 'image', 'balls'];
    public const MAX_PRVKU = 60;
    public const VLASTNI = 'vlastni';

    /** @var array<string, Prvek> */
    private array $prvky = [];

    /**
     * @param array<string, array{0:int,1:int,2?:string}> $formaty klíč => [šířka, výška, název]
     * @param list<Prvek> $prvky další typy prvků
     * @param bool $vlastniRozmer povolit formát „vlastni“ s libovolnou šířkou a výškou (v mezích $min–$max)
     */
    public function __construct(
        private Pisma $pisma,
        private array $formaty,
        private string $vychoziFormat,
        array $prvky = [],
        private bool $vlastniRozmer = false,
        private int $min = 16,
        private int $max = 4096,
    ) {
        if (!isset($formaty[$vychoziFormat])) throw new \InvalidArgumentException('Výchozí formát plátna není mezi formáty.');
        foreach ($prvky as $p) {
            if (in_array($p->typ(), self::ZAKLADNI_PRVKY, true)) throw new \InvalidArgumentException("Typ prvku „{$p->typ()}“ je vyhrazený.");
            $this->prvky[$p->typ()] = $p;
        }
    }

    /** @return array<string, Prvek> */
    public function prvky(): array { return $this->prvky; }
    public function formaty(): array { return $this->formaty; }
    public function pisma(): Pisma { return $this->pisma; }

    public static function cislo(mixed $v, float $min, float $max, float $vychozi): float
    {
        if (!is_numeric($v)) return $vychozi;
        return max($min, min($max, (float)$v));
    }

    public static function barva(mixed $v, string $vychozi): string
    {
        return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $vychozi;
    }

    /** @return array{0:int,1:int} */
    public function rozmer(string $format): array
    {
        $f = $this->formaty[$format] ?? $this->formaty[$this->vychoziFormat];
        return [(int)$f[0], (int)$f[1]];
    }

    public function vychozi(?string $format = null, ?int $w = null, ?int $h = null): array
    {
        return $this->vycisti(['format' => $format ?? $this->vychoziFormat, 'w' => $w, 'h' => $h]);
    }

    /** Očistí šablonu z prohlížeče. */
    public function vycisti(mixed $d): array
    {
        $d = is_array($d) ? $d : [];
        $bg = $d['bg'] ?? [];
        $format = (string)($d['format'] ?? '');
        if ($this->vlastniRozmer && $format === self::VLASTNI) {
            $W = (int)self::cislo($d['w'] ?? null, $this->min, $this->max, 1080);
            $H = (int)self::cislo($d['h'] ?? null, $this->min, $this->max, 1080);
        } else {
            if (!isset($this->formaty[$format])) $format = $this->vychoziFormat;
            [$W, $H] = $this->rozmer($format);
        }
        $out = ['v' => 1, 'format' => $format, 'w' => $W, 'h' => $H, 'bg' => [
            'type' => in_array($bg['type'] ?? '', ['color', 'gradient', 'image'], true) ? $bg['type'] : 'color',
            'color' => self::barva($bg['color'] ?? null, '#1d1c1a'),
            'color2' => self::barva($bg['color2'] ?? null, '#d2502c'),
            'dir' => ($bg['dir'] ?? 'v') === 'h' ? 'h' : 'v',
            'mediaId' => isset($bg['mediaId']) ? (int)$bg['mediaId'] : null,
            'fit' => ($bg['fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover',
            'dim' => self::cislo($bg['dim'] ?? 0, 0, 0.9, 0),
        ], 'elements' => []];
        $typy = [...self::ZAKLADNI_PRVKY, ...array_keys($this->prvky)];
        foreach (array_slice((array)($d['elements'] ?? []), 0, self::MAX_PRVKU) as $e) {
            if (!is_array($e)) continue;
            $type = $e['type'] ?? '';
            if (!in_array($type, $typy, true)) continue;
            $b = [
                'id' => preg_replace('/[^a-z0-9]/i', '', (string)($e['id'] ?? '')) ?: bin2hex(random_bytes(4)),
                'type' => $type,
                'name' => mb_substr((string)($e['name'] ?? ''), 0, 60),
                'x' => self::cislo($e['x'] ?? 0, -$W, 2 * $W, 0), 'y' => self::cislo($e['y'] ?? 0, -$H, 2 * $H, 0),
                'w' => self::cislo($e['w'] ?? 400, 4, 4 * $W, 400), 'h' => self::cislo($e['h'] ?? 200, 4, 4 * $H, 200),
                'alpha' => self::cislo($e['alpha'] ?? 1, 0, 1, 1),
                'hidden' => !empty($e['hidden']),
                'showIf' => self::podminky($e['showIf'] ?? []),
            ];
            if ($type === 'text') {
                $sh = $e['shadow'] ?? []; $ol = $e['outline'] ?? []; $tb = $e['bg'] ?? [];
                $b += [
                    'text' => mb_substr((string)($e['text'] ?? ''), 0, 2000),
                    'variants' => array_values(array_map(fn($v) => ['when' => self::podminky($v['when'] ?? []), 'text' => mb_substr((string)($v['text'] ?? ''), 0, 2000)],
                        array_filter(array_slice((array)($e['variants'] ?? []), 0, 10), 'is_array'))),
                    'font' => $this->pisma->existuje((string)($e['font'] ?? '')) ? $e['font'] : 'roboto',
                    'spacing' => self::cislo($e['spacing'] ?? 0, -0.2, 0.5, 0),
                    'fx' => Fx::vycisti($e['fx'] ?? null),
                    'weight' => (int)self::cislo($e['weight'] ?? 700, 100, 900, 700),
                    'size' => self::cislo($e['size'] ?? 80, 8, 600, 80),
                    'color' => self::barva($e['color'] ?? null, '#ffffff'),
                    'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'left',
                    'valign' => in_array($e['valign'] ?? '', ['top', 'middle', 'bottom'], true) ? $e['valign'] : 'top',
                    'lineHeight' => self::cislo($e['lineHeight'] ?? 1.15, 0.7, 3, 1.15),
                    'upper' => !empty($e['upper']),
                    'autoFit' => !empty($e['autoFit']),
                    'shadow' => ['on' => !empty($sh['on']), 'color' => self::barva($sh['color'] ?? null, '#000000'), 'alpha' => self::cislo($sh['alpha'] ?? 0.5, 0, 1, 0.5),
                                 'dx' => self::cislo($sh['dx'] ?? 4, -60, 60, 4), 'dy' => self::cislo($sh['dy'] ?? 4, -60, 60, 4)],
                    'outline' => ['on' => !empty($ol['on']), 'color' => self::barva($ol['color'] ?? null, '#000000'), 'width' => self::cislo($ol['width'] ?? 3, 1, 30, 3)],
                    'bg' => ['on' => !empty($tb['on']), 'color' => self::barva($tb['color'] ?? null, '#000000'), 'alpha' => self::cislo($tb['alpha'] ?? 0.5, 0, 1, 0.5),
                             'padding' => self::cislo($tb['padding'] ?? 24, 0, 300, 24), 'radius' => self::cislo($tb['radius'] ?? 16, 0, 500, 16)],
                ];
            } elseif (isset($this->prvky[$type])) {
                $b += $this->prvky[$type]->vycisti($e, $this);
            } elseif ($type === 'rect') {
                $b += ['color' => self::barva($e['color'] ?? null, '#d2502c'), 'radius' => self::cislo($e['radius'] ?? 0, 0, 1000, 0)];
            } elseif ($type === 'balls') {
                $st = $e['stroke'] ?? [];
                $b += [
                    'src' => mb_substr((string)($e['src'] ?? ''), 0, 300), 'extra' => mb_substr((string)($e['extra'] ?? ''), 0, 300),
                    'style' => ($e['style'] ?? 'flat') === 'glossy' ? 'glossy' : 'flat',
                    'size' => self::cislo($e['size'] ?? 110, 16, 400, 110), 'gap' => self::cislo($e['gap'] ?? 20, 0, 300, 20), 'extraGap' => self::cislo($e['extraGap'] ?? 48, 0, 600, 48),
                    'fill' => self::barva($e['fill'] ?? null, '#d2502c'), 'color' => self::barva($e['color'] ?? null, '#ffffff'),
                    'extraFill' => self::barva($e['extraFill'] ?? null, '#f2b705'), 'extraColor' => self::barva($e['extraColor'] ?? null, '#1d1c1a'),
                    'stroke' => ['on' => !empty($st['on']), 'color' => self::barva($st['color'] ?? null, '#ffffff'), 'width' => self::cislo($st['width'] ?? 4, 1, 40, 4)],
                    'font' => $this->pisma->existuje((string)($e['font'] ?? '')) ? $e['font'] : 'montserrat',
                    'weight' => (int)self::cislo($e['weight'] ?? 700, 100, 900, 700),
                    'numScale' => self::cislo($e['numScale'] ?? 0.46, 0.2, 0.9, 0.46),
                    'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'center',
                    'valign' => in_array($e['valign'] ?? '', ['top', 'middle', 'bottom'], true) ? $e['valign'] : 'middle',
                ];
            } else {
                $b += ['mediaId' => isset($e['mediaId']) ? (int)$e['mediaId'] : null, 'fit' => ($e['fit'] ?? 'contain') === 'cover' ? 'cover' : 'contain'];
            }
            $out['elements'][] = $b;
        }
        return $out;
    }

    /** Podmínky „zobrazit jen když“. @return list<array{l:string,op:string,r:string}> */
    public static function podminky(mixed $list): array
    {
        $out = [];
        foreach (array_slice((array)$list, 0, 10) as $c) {
            if (!is_array($c)) continue;
            $out[] = ['l' => mb_substr((string)($c['l'] ?? ''), 0, 200), 'op' => in_array($c['op'] ?? '', self::OPERATORY, true) ? $c['op'] : '=',
                      'r' => mb_substr((string)($c['r'] ?? ''), 0, 200)];
        }
        return $out;
    }

    /** Média použitá v šabloně (pozadí, obrázky). @return list<int> */
    public static function mediaIds(array $design): array
    {
        $ids = [];
        if ($design['bg']['type'] === 'image' && $design['bg']['mediaId']) $ids[] = $design['bg']['mediaId'];
        foreach ($design['elements'] as $e) if ($e['type'] === 'image' && $e['mediaId']) $ids[] = $e['mediaId'];
        return array_values(array_unique($ids));
    }

    /** Slidy/šablony, které používají písmo. */
    public static function pouzivaPismo(array $design, string $klic): bool
    {
        foreach ($design['elements'] ?? [] as $e) if (($e['font'] ?? '') === $klic) return true;
        return false;
    }
}
