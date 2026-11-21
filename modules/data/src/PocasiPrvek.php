<?php
declare(strict_types=1);
namespace TWB\Data;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use TWB\Pisma\Pisma;
use TWB\Slidy\{Gd, Navrh, Prvek, Text};

/**
 * Prvek „Počasí“ ve slidu a banneru (3 vzhledy: mini, card, days). Vyžaduje modul `slidy`.
 * Ikony OpenWeather se stahují jednou a ukládají do složky; chybějící ikona se jen vynechá.
 */
final class PocasiPrvek implements Prvek
{
    public const DNY = 3;
    public const VZHLEDY = ['mini', 'card', 'days'];

    /** @var callable(string): array{0: int, 1: string} */
    private $http;
    /** @var array<string, true> */
    private array $nezdarene = [];

    /**
     * @param string $slozkaIkon složka pro uložené ikony (vytvoří se)
     * @param callable(string): array{0: int, 1: string}|null $http stáhne adresu → [kód, tělo]
     */
    public function __construct(
        private Predpoved $predpoved,
        private Pisma $pisma,
        private string $slozkaIkon,
        private string $adresaIkon = 'https://openweathermap.org/img/wn',
        ?callable $http = null,
    ) {
        $this->http = $http ?? function (string $url): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
            $telo = curl_exec($ch);
            if ($telo === false) throw new RuntimeException('Ikona se nestáhla.');
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)$telo];
        };
    }

    public function typ(): string { return 'weather'; }

    public function vycisti(array $e, Navrh $n): array
    {
        $sh = $e['shadow'] ?? []; $tb = $e['bg'] ?? [];
        return [
            'look' => in_array($e['look'] ?? '', self::VZHLEDY, true) ? $e['look'] : 'mini',
            'place' => mb_substr(trim((string)($e['place'] ?? '')), 0, 100),
            'font' => $n->pisma()->existuje((string)($e['font'] ?? '')) ? $e['font'] : 'montserrat',
            'weight' => (int)Navrh::cislo($e['weight'] ?? 700, 100, 900, 700),
            'color' => Navrh::barva($e['color'] ?? null, '#ffffff'),
            'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'center',
            'shadow' => ['on' => !empty($sh['on']), 'color' => Navrh::barva($sh['color'] ?? null, '#000000'), 'alpha' => Navrh::cislo($sh['alpha'] ?? 0.5, 0, 1, 0.5),
                         'dx' => Navrh::cislo($sh['dx'] ?? 4, -60, 60, 4), 'dy' => Navrh::cislo($sh['dy'] ?? 4, -60, 60, 4)],
            'bg' => ['on' => !empty($tb['on']), 'color' => Navrh::barva($tb['color'] ?? null, '#000000'), 'alpha' => Navrh::cislo($tb['alpha'] ?? 0.35, 0, 1, 0.35),
                     'padding' => Navrh::cislo($tb['padding'] ?? 32, 0, 300, 32), 'radius' => Navrh::cislo($tb['radius'] ?? 24, 0, 500, 24)],
        ];
    }

    /** Jen části k vykreslení: čas stažení ani chyba nemění obrázek, slide se kvůli nim nepřegeneruje. */
    public function vyhodnot(array $e, DateTimeImmutable $t): array
    {
        [$data] = $this->predpoved->ziskej($e['place']);
        return ['parts' => $data ? $this->rozvrh($e, $data, $t) : []];
    }

    /** Stav prvku pro editor: místo, čas stažení, chyba; bez dat ukázkové rozvržení (sample). */
    public function infoProEditor(array $e, DateTimeImmutable $t): array
    {
        [$data, $chyba] = $this->predpoved->ziskej($e['place']);
        $r = ['place' => $this->predpoved->misto($e['place']), 'error' => $chyba, 'at' => $data['at'] ?? null, 'sample' => !$data];
        if (!$data) $r['parts'] = $this->rozvrh($e, self::ukazka($t), $t);
        return $r;
    }

    /** Vzorová data pro náhled v editoru, když skutečná data ještě nejsou. */
    public static function ukazka(DateTimeImmutable $t): array
    {
        $dny = [];
        foreach ([[18, 9, '02d', 'skoro jasno'], [15, 8, '10d', 'slabý déšť'], [13, 6, '04d', 'zataženo']] as $i => [$mx, $mn, $ic, $ds]) {
            $dny[] = ['date' => $t->modify("+$i day")->format('Y-m-d'), 'max' => $mx, 'min' => $mn, 'icon' => $ic, 'desc' => $ds];
        }
        return ['place' => 'Ukázka', 'now' => ['temp' => 16, 'feels' => 15, 'desc' => 'skoro jasno', 'icon' => '02d', 'humidity' => 62, 'wind' => 3], 'days' => $dny];
    }

    /**
     * Rozvržení prvku: seznam částí s přesnou polohou (stejně je kreslí server i editor).
     *   ['k' => 'icon', 'x', 'y', 's', 'code']        ikona ve čtverci o straně s
     *   ['k' => 'text', 'x', 'y', 'size', 'text']     text zleva od x, y = účaří
     * @return list<array<string, mixed>>
     */
    public function rozvrh(array $e, array $data, DateTimeImmutable $t): array
    {
        $text = new Text($this->pisma);
        $font = $text->soubor($e['font'], $e['weight']);
        $pad = $e['bg']['on'] ? $e['bg']['padding'] : 0;
        $ix = $e['x'] + $pad; $iy = $e['y'] + $pad;
        $iw = max(1, $e['w'] - 2 * $pad); $ih = max(1, $e['h'] - 2 * $pad);
        $tw = fn(string $s, float $size) => $text->sirka($s, $size, $font);
        $alignX = fn(float $w, float $x0, float $width) => match ($e['align']) { 'left' => $x0, 'right' => $x0 + $width - $w, default => $x0 + ($width - $w) / 2 };
        $n = $data['now'] ?? [];
        $deg = fn($v) => Predpoved::cele((float)$v) . ' °C';
        $parts = [];

        if ($e['look'] === 'mini') {
            // ikona + teplota v jednom řádku
            $s = $ih; $T = $ih * 0.56; $gap = $ih * 0.04;
            $txt = $deg($n['temp'] ?? 0);
            $W = $s + $gap + $tw($txt, $T);
            $k = min(1, $iw / $W);
            $s *= $k; $T = floor($T * $k); $gap *= $k;
            $W = $s + $gap + $tw($txt, $T);
            $x = $alignX($W, $ix, $iw);
            $cy = $iy + $ih / 2;
            $parts[] = ['k' => 'icon', 'x' => $x, 'y' => $cy - $s / 2, 's' => $s, 'code' => (string)($n['icon'] ?? '')];
            $parts[] = ['k' => 'text', 'x' => $x + $s + $gap, 'y' => $cy + $T * 0.36, 'size' => $T, 'text' => $txt];
        } elseif ($e['look'] === 'card') {
            // 1. řádek ikona + velká teplota, 2. popis, 3. pocitově · vlhkost · vítr
            $s = $ih * 0.52; $T1 = $ih * 0.36; $T2 = $ih * 0.13; $T3 = $ih * 0.09;
            $t1 = $deg($n['temp'] ?? 0);
            $t2 = Predpoved::velke((string)($n['desc'] ?? ''));
            $t3 = 'Pocitově ' . $deg($n['feels'] ?? 0) . ' · Vlhkost ' . (int)($n['humidity'] ?? 0) . ' % · Vítr ' . Predpoved::cele((float)($n['wind'] ?? 0)) . ' m/s';
            $gap = $ih * 0.03;
            $wid = fn() => max($s + $gap + $tw($t1, $T1), $tw($t2, $T2), $tw($t3, $T3));
            $k = min(1, $iw / max(1, $wid()));
            $s *= $k; $T1 = floor($T1 * $k); $T2 = floor($T2 * $k); $T3 = floor($T3 * $k); $gap *= $k;
            $h1 = $s; $h2 = $T2 * 1.3; $h3 = $T3 * 1.3;
            $H = $h1 + $gap + $h2 + $h3;
            $y = $iy + ($ih - $H) / 2;
            $w1 = $s + $gap + $tw($t1, $T1);
            $x1 = $alignX($w1, $ix, $iw);
            $parts[] = ['k' => 'icon', 'x' => $x1, 'y' => $y, 's' => $s, 'code' => (string)($n['icon'] ?? '')];
            $parts[] = ['k' => 'text', 'x' => $x1 + $s + $gap, 'y' => $y + $h1 / 2 + $T1 * 0.36, 'size' => $T1, 'text' => $t1];
            $y += $h1 + $gap;
            $parts[] = ['k' => 'text', 'x' => $alignX($tw($t2, $T2), $ix, $iw), 'y' => $y + $h2 / 2 + $T2 * 0.36, 'size' => $T2, 'text' => $t2];
            $y += $h2;
            $parts[] = ['k' => 'text', 'x' => $alignX($tw($t3, $T3), $ix, $iw), 'y' => $y + $h3 / 2 + $T3 * 0.36, 'size' => $T3, 'text' => $t3];
        } else {
            // předpověď: 3 sloupce (den, ikona, max / min)
            $cols = [];
            for ($i = 0; $i < self::DNY; $i++) {
                $d = Predpoved::den($data, $i, $t);
                $cols[] = $d ? [Predpoved::popisekDne($d['date'], $t), (string)$d['icon'], Predpoved::cele((float)$d['max']) . '° / ' . Predpoved::cele((float)$d['min']) . '°'] : ['', '', ''];
            }
            $cw = $iw / self::DNY;
            $TL = $ih * 0.17; $s = $ih * 0.5; $TT = $ih * 0.18;
            $maxW = 0;
            foreach ($cols as [$l, , $tt]) $maxW = max($maxW, $tw($l, $TL), $tw($tt, $TT));
            $k = min(1, $cw * 0.92 / max(1, $maxW, $s));
            $TL = floor($TL * $k); $s *= $k; $TT = floor($TT * $k);
            $hL = $TL * 1.3; $hT = $TT * 1.3;
            $H = $hL + $s + $hT;
            $y0 = $iy + ($ih - $H) / 2;
            foreach ($cols as $i => [$l, $ic, $tt]) {
                $cx = $ix + $cw * $i + $cw / 2;
                if ($l === '') continue;
                $parts[] = ['k' => 'text', 'x' => $cx - $tw($l, $TL) / 2, 'y' => $y0 + $hL / 2 + $TL * 0.36, 'size' => $TL, 'text' => $l];
                $parts[] = ['k' => 'icon', 'x' => $cx - $s / 2, 'y' => $y0 + $hL, 's' => $s, 'code' => $ic];
                $parts[] = ['k' => 'text', 'x' => $cx - $tw($tt, $TT) / 2, 'y' => $y0 + $hL + $s + $hT / 2 + $TT * 0.36, 'size' => $TT, 'text' => $tt];
            }
        }
        foreach ($parts as &$p) {
            if ($p['k'] === 'text') $p['w'] = $tw($p['text'], $p['size']); // šířka podle serveru (editor podle ní dorovná písmo)
            foreach (['x', 'y', 's', 'size', 'w'] as $f) if (isset($p[$f])) $p[$f] = round($p[$f], 1);
        }
        unset($p);
        return $parts;
    }

    public function kresli(\GdImage $im, array $e, array $vyh, Text $text): void
    {
        $parts = $vyh['parts'] ?? [];
        if (!$parts) return;
        if ($e['bg']['on']) Gd::zaoblenyObdelnik($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['bg']['radius'], Gd::barva($im, $e['bg']['color'], $e['bg']['alpha'] * $e['alpha']));
        $font = $text->soubor($e['font'], $e['weight']);
        $col = Gd::barva($im, $e['color'], $e['alpha']);
        foreach ($parts as $p) {
            if ($p['k'] === 'icon') {
                $f = $p['code'] !== '' ? $this->ikona($p['code']) : null;
                $src = $f ? @imagecreatefrompng($f) : null;
                if ($src) {
                    imagealphablending($src, true); imagesavealpha($src, true);
                    Gd::vlozObrazek($im, $src, $p['x'], $p['y'], $p['s'], $p['s'], 'contain', $e['alpha']);
                }
            } else {
                if ($e['shadow']['on']) $text->radek($im, $p['size'] * 0.75, $p['x'] + $e['shadow']['dx'], $p['y'] + $e['shadow']['dy'], Gd::barva($im, $e['shadow']['color'], $e['shadow']['alpha'] * $e['alpha']), $font, $p['text']);
                $text->radek($im, $p['size'] * 0.75, $p['x'], $p['y'], $col, $font, $p['text']);
            }
        }
    }

    /** Cesta k uložené ikoně; chybí-li, stáhne se. Vrací null, když ikonu nejde získat. */
    public function ikona(string $kod): ?string
    {
        if (!preg_match('/^[0-9a-z]{2,4}$/', $kod)) return null;
        $f = $this->slozkaIkon . "/ow-$kod.png";
        if (is_file($f)) return $f;
        if (isset($this->nezdarene[$kod])) return null;
        if (!is_dir($this->slozkaIkon)) @mkdir($this->slozkaIkon, 0775, true);
        try { $telo = ($this->http)(rtrim($this->adresaIkon, '/') . "/$kod@4x.png")[1]; }
        catch (Throwable $e) { $telo = null; }
        if (!is_string($telo) || !@imagecreatefromstring($telo)) { $this->nezdarene[$kod] = true; return null; }
        file_put_contents("$f.tmp", $telo);
        rename("$f.tmp", $f);
        return $f;
    }
}
