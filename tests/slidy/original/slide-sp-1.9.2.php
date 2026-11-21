<?php
// ZMRAZENÁ REFERENCE: slide.php ze Social Planneru 1.9.2 beze změny kódu (jen odstraněny dva require_once na data.php a library.php).
// Slouží jako „orákulum“: modul slidy musí vykreslit stejné obrázky a vyčistit šablony stejně. Needitovat.
declare(strict_types=1);
// Slidy (od 1.6.0, převzato z TWB Plánovače médií): šablona (JSON) → obrázek JPEG vykreslený knihovnou GD.
// Rozvržení textu (zalamování, zmenšení, svislé zarovnání, účaří) je stejné jako v editoru (assets/js/slide-editor.js),
// takže náhled v editoru odpovídá výsledku. Oproti Plánovači má slide formát plátna (čtverec, na výšku, story, na šířku, odkaz).
// Slide je položka Knihovny médií (library.design); soubor v uploads/ se přegeneruje při uložení a těsně před zveřejněním
// příspěvku (pro jeho čas), když se změnila data, datum, svátek, počasí…


/** Formáty plátna: klíč => [šířka, výška, název]. */
const SLIDE_FORMATS = [
    'square'    => [1080, 1080, 'Čtverec 1 : 1 (příspěvek)'],
    'portrait'  => [1080, 1350, 'Na výšku 4 : 5 (Instagram)'],
    'story'     => [1080, 1920, 'Story / Reels 9 : 16'],
    'landscape' => [1920, 1080, 'Na šířku 16 : 9'],
    'link'      => [1200, 630, 'Odkaz / Facebook 1,91 : 1'],
];
function slide_size(string $format): array { $f = SLIDE_FORMATS[$format] ?? SLIDE_FORMATS['square']; return [$f[0], $f[1]]; }

/** Proměnné ve slidu: {x} jako v Plánovači médií i {{x}} jako v textu příspěvku. Vlastní proměnné se seznamem dají 1. hodnotu. */
function resolve_text(string $text, DateTimeImmutable $t): array {
    static $user = null;
    $user ??= user_vars(db());
    return resolve_vars($text, $t, ['index' => 0, 'user' => $user], true);
}

const FONT_DIR = PUBLIC_DIR . '/assets/fonts/slide';
const FONTS = [
    'roboto'     => ['name' => 'Roboto',           'file' => 'Roboto',          'weights' => [400, 700, 900]],
    'montserrat' => ['name' => 'Montserrat',       'file' => 'Montserrat',      'weights' => [400, 700, 900]],
    'opensans'   => ['name' => 'Open Sans',        'file' => 'OpenSans',        'weights' => [400, 700]],
    'oswald'     => ['name' => 'Oswald',           'file' => 'Oswald',          'weights' => [400, 700]],
    'bebas'      => ['name' => 'Bebas Neue',       'file' => 'BebasNeue',       'weights' => [400]],
    'playfair'   => ['name' => 'Playfair Display', 'file' => 'PlayfairDisplay', 'weights' => [400, 700]],
    'archivo'    => ['name' => 'Archivo Black',    'file' => 'ArchivoBlack',    'weights' => [900]],
    'titan'      => ['name' => 'Titan One',        'file' => 'TitanOne',        'weights' => [900]],
    'anton'      => ['name' => 'Anton',            'file' => 'Anton',           'weights' => [700]],
];
/** Vlastní písma (soubory v app/data/fonts – aktualizace je nepřepíše; seznam v meta custom_fonts). */
const CUSTOM_FONT_DIR = ROOT . '/data/fonts';

/** Všechna písma: vestavěná + vlastní ([key => [name, weights, path(w) …]]). */
function fonts_all(bool $reload = false): array {
    static $cache = null;
    if ($cache !== null && !$reload) return $cache;
    $all = [];
    foreach (FONTS as $k => $f) $all[$k] = $f + ['custom' => false];
    $custom = json_decode((string)meta_get('custom_fonts', '{}'), true) ?: [];
    foreach ($custom as $k => $f) {
        if (!is_array($f) || !preg_match('/^u_[a-z0-9]{1,40}$/', (string)$k) || !is_file(CUSTOM_FONT_DIR . '/' . ($f['file'] ?? ''))) continue;
        $all[$k] = ['name' => (string)$f['name'], 'file' => (string)$f['file'], 'weights' => [700], 'custom' => true];
    }
    return $cache = $all;
}
function font_exists(string $key): bool { return isset(fonts_all()[$key]); }

function font_file(string $font, int $weight): string {
    $all = fonts_all();
    $f = $all[$font] ?? $all['roboto'];
    if ($f['custom']) return CUSTOM_FONT_DIR . '/' . $f['file'];
    // nejbližší dostupná tloušťka
    $best = $f['weights'][0];
    foreach ($f['weights'] as $w) if (abs($w - $weight) < abs($best - $weight)) $best = $w;
    return FONT_DIR . "/{$f['file']}-$best.ttf";
}

// ---------- Grafický text: hotové styly (stejný seznam posílá api.php?a=fx_styles editoru) ----------
// Hodnoty tlouštěk a hloubky jsou násobky velikosti písma; síly 0–1. Přechod: 4 barvy shora dolů (pozice FX_STOPS).
const FX_STOPS = [0, 0.38, 0.62, 1];
const FX_STYLES = [
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
const FX_RANGES = ['stroke' => [0, 0.15], 'stroke2' => [0, 0.08], 'depth' => [0, 0.25], 'glow' => [0, 1], 'shadow' => [0, 1], 'gloss' => [0, 1]];
const FX_COLORS = ['strokeColor', 'stroke2Color', 'depthColor', 'glowColor'];

/** Očistí grafický styl textu; null = obyčejný text. */
function sanitize_fx($fx): ?array {
    if (!is_array($fx) || !isset(FX_STYLES[$fx['style'] ?? ''])) return null;
    $p = FX_STYLES[$fx['style']];
    $out = ['style' => $fx['style']];
    $g = array_values((array)($fx['grad'] ?? []));
    for ($i = 0; $i < 4; $i++) $out['grad'][] = hexcolor($g[$i] ?? null, $p['grad'][$i]);
    foreach (FX_RANGES as $k => [$lo, $hi]) $out[$k] = clampf($fx[$k] ?? $p[$k], $lo, $hi, $p[$k]);
    foreach (FX_COLORS as $k) $out[$k] = hexcolor($fx[$k] ?? null, $p[$k]);
    return $out;
}

// ---------- Proměnné ----------

function default_design(): array {
    return ['v' => 1, 'format' => 'square', 'w' => 1080, 'h' => 1080, 'bg' => ['type' => 'color', 'color' => '#1d1c1a', 'color2' => '#d2502c', 'dir' => 'v', 'mediaId' => null, 'fit' => 'cover', 'dim' => 0], 'elements' => []];
}

function clampf($v, float $min, float $max, float $def): float {
    if (!is_numeric($v)) return $def;
    return max($min, min($max, (float)$v));
}
function hexcolor($v, string $def): string { return is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : $def; }

/** Očistí šablonu z prohlížeče (jen známé vlastnosti, rozsahy čísel). */
function sanitize_design($d): array {
    $d = is_array($d) ? $d : [];
    $bg = $d['bg'] ?? [];
    [$W, $H] = slide_size($d['format'] ?? 'square');
    $out = ['v' => 1, 'format' => isset(SLIDE_FORMATS[$d['format'] ?? '']) ? $d['format'] : 'square', 'w' => $W, 'h' => $H, 'bg' => [
        'type' => in_array($bg['type'] ?? '', ['color', 'gradient', 'image'], true) ? $bg['type'] : 'color',
        'color' => hexcolor($bg['color'] ?? null, '#1d1c1a'),
        'color2' => hexcolor($bg['color2'] ?? null, '#d2502c'),
        'dir' => ($bg['dir'] ?? 'v') === 'h' ? 'h' : 'v',
        'mediaId' => isset($bg['mediaId']) ? (int)$bg['mediaId'] : null,
        'fit' => ($bg['fit'] ?? 'cover') === 'contain' ? 'contain' : 'cover',
        'dim' => clampf($bg['dim'] ?? 0, 0, 0.9, 0),
    ], 'elements' => []];
    foreach (array_slice((array)($d['elements'] ?? []), 0, 60) as $e) {
        if (!is_array($e)) continue;
        $type = $e['type'] ?? '';
        if (!in_array($type, ['text', 'rect', 'image', 'balls', 'weather'], true)) continue;
        $b = [
            'id' => preg_replace('/[^a-z0-9]/i', '', (string)($e['id'] ?? '')) ?: bin2hex(random_bytes(4)),
            'type' => $type,
            'name' => mb_substr((string)($e['name'] ?? ''), 0, 60),
            'x' => clampf($e['x'] ?? 0, -$W, 2 * $W, 0), 'y' => clampf($e['y'] ?? 0, -$H, 2 * $H, 0),
            'w' => clampf($e['w'] ?? 400, 4, 4 * $W, 400), 'h' => clampf($e['h'] ?? 200, 4, 4 * $H, 200),
            'alpha' => clampf($e['alpha'] ?? 1, 0, 1, 1),
            'hidden' => !empty($e['hidden']),
            'showIf' => sanitize_clauses($e['showIf'] ?? []),
        ];
        if ($type === 'text') {
            $sh = $e['shadow'] ?? []; $ol = $e['outline'] ?? []; $tb = $e['bg'] ?? [];
            $b += [
                'text' => mb_substr((string)($e['text'] ?? ''), 0, 2000),
                'variants' => array_values(array_map(fn($v) => ['when' => sanitize_clauses($v['when'] ?? []), 'text' => mb_substr((string)($v['text'] ?? ''), 0, 2000)],
                    array_filter(array_slice((array)($e['variants'] ?? []), 0, 10), 'is_array'))),
                'font' => font_exists((string)($e['font'] ?? '')) ? $e['font'] : 'roboto',
                'spacing' => clampf($e['spacing'] ?? 0, -0.2, 0.5, 0),
                'fx' => sanitize_fx($e['fx'] ?? null),
                'weight' => (int)clampf($e['weight'] ?? 700, 100, 900, 700),
                'size' => clampf($e['size'] ?? 80, 8, 600, 80),
                'color' => hexcolor($e['color'] ?? null, '#ffffff'),
                'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'left',
                'valign' => in_array($e['valign'] ?? '', ['top', 'middle', 'bottom'], true) ? $e['valign'] : 'top',
                'lineHeight' => clampf($e['lineHeight'] ?? 1.15, 0.7, 3, 1.15),
                'upper' => !empty($e['upper']),
                'autoFit' => !empty($e['autoFit']),
                'shadow' => ['on' => !empty($sh['on']), 'color' => hexcolor($sh['color'] ?? null, '#000000'), 'alpha' => clampf($sh['alpha'] ?? 0.5, 0, 1, 0.5),
                             'dx' => clampf($sh['dx'] ?? 4, -60, 60, 4), 'dy' => clampf($sh['dy'] ?? 4, -60, 60, 4)],
                'outline' => ['on' => !empty($ol['on']), 'color' => hexcolor($ol['color'] ?? null, '#000000'), 'width' => clampf($ol['width'] ?? 3, 1, 30, 3)],
                'bg' => ['on' => !empty($tb['on']), 'color' => hexcolor($tb['color'] ?? null, '#000000'), 'alpha' => clampf($tb['alpha'] ?? 0.5, 0, 1, 0.5),
                         'padding' => clampf($tb['padding'] ?? 24, 0, 300, 24), 'radius' => clampf($tb['radius'] ?? 16, 0, 500, 16)],
            ];
        } elseif ($type === 'weather') {
            $b += sanitize_weather_element($e);
        } elseif ($type === 'rect') {
            $b += ['color' => hexcolor($e['color'] ?? null, '#d2502c'), 'radius' => clampf($e['radius'] ?? 0, 0, 1000, 0)];
        } elseif ($type === 'balls') {
            $st = $e['stroke'] ?? [];
            $b += [
                'src' => mb_substr((string)($e['src'] ?? ''), 0, 300), 'extra' => mb_substr((string)($e['extra'] ?? ''), 0, 300),
                'style' => ($e['style'] ?? 'flat') === 'glossy' ? 'glossy' : 'flat',
                'size' => clampf($e['size'] ?? 110, 16, 400, 110), 'gap' => clampf($e['gap'] ?? 20, 0, 300, 20), 'extraGap' => clampf($e['extraGap'] ?? 48, 0, 600, 48),
                'fill' => hexcolor($e['fill'] ?? null, '#d2502c'), 'color' => hexcolor($e['color'] ?? null, '#ffffff'),
                'extraFill' => hexcolor($e['extraFill'] ?? null, '#f2b705'), 'extraColor' => hexcolor($e['extraColor'] ?? null, '#1d1c1a'),
                'stroke' => ['on' => !empty($st['on']), 'color' => hexcolor($st['color'] ?? null, '#ffffff'), 'width' => clampf($st['width'] ?? 4, 1, 40, 4)],
                'font' => font_exists((string)($e['font'] ?? '')) ? $e['font'] : 'montserrat',
                'weight' => (int)clampf($e['weight'] ?? 700, 100, 900, 700),
                'numScale' => clampf($e['numScale'] ?? 0.46, 0.2, 0.9, 0.46),
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

const COND_OPS = ['=', '!=', '>', '<', '>=', '<=', 'contains', 'empty', 'notempty'];
function sanitize_clauses($list): array {
    $out = [];
    foreach (array_slice((array)$list, 0, 10) as $c) {
        if (!is_array($c)) continue;
        $out[] = ['l' => mb_substr((string)($c['l'] ?? ''), 0, 200), 'op' => in_array($c['op'] ?? '', COND_OPS, true) ? $c['op'] : '=',
                  'r' => mb_substr((string)($c['r'] ?? ''), 0, 200)];
    }
    return $out;
}

/**
 * Vyhodnotí prvky šablony v daném okamžiku: viditelnost (podmínka „Zobrazit jen když“),
 * zvolená varianta textu a text po dosazení proměnných.
 */
function design_eval(array $design, DateTimeImmutable $t): array {
    $res = [];
    foreach ($design['elements'] as $e) {
        $r = ['visible' => conds_ok($e['showIf'] ?? [], $t)];
        if ($e['type'] === 'text') {
            $src = $e['text'];
            $r['variant'] = -1;
            foreach ($e['variants'] ?? [] as $i => $v) {
                if (($v['when'] ?? []) && conds_ok($v['when'], $t)) { $src = $v['text']; $r['variant'] = $i; break; }
            }
            [$r['text'], $r['unknown']] = resolve_text($src, $t);
        } elseif ($e['type'] === 'balls') {
            [$a, $u1] = resolve_text($e['src'], $t);
            [$b, $u2] = resolve_text($e['extra'], $t);
            $r['nums'] = ball_numbers($a);
            $r['extras'] = ball_numbers($b);
            $r['unknown'] = array_values(array_unique([...$u1, ...$u2]));
        } elseif ($e['type'] === 'weather') {
            $r += weather_eval($e, $t);
        }
        $res[$e['id']] = $r;
    }
    return $res;
}
/** Texty šablony po dosazení proměnných (pro zpětnou kompatibilitu). */
function design_texts(array $design, DateTimeImmutable $t): array {
    $res = [];
    foreach (design_eval($design, $t) as $id => $r) if (isset($r['text'])) $res[$id] = [$r['text'], $r['unknown']];
    return $res;
}

/** Média použitá v šabloně (pozadí, obrázky). */
function design_media_ids(array $design): array {
    $ids = [];
    if ($design['bg']['type'] === 'image' && $design['bg']['mediaId']) $ids[] = $design['bg']['mediaId'];
    foreach ($design['elements'] as $e) if ($e['type'] === 'image' && $e['mediaId']) $ids[] = $e['mediaId'];
    return array_values(array_unique($ids));
}

/** Klíč obsahu: změní se, když se změní šablona, dosazené texty nebo použité obrázky. */
function render_key(array $design, DateTimeImmutable $t): string {
    $md5s = [];
    foreach (design_media_ids($design) as $id) $md5s[$id] = library_file_sig($id);
    return md5(json_encode([$design, design_eval($design, $t), $md5s]));
}

// ---------- Vykreslení (GD) ----------
function gd_color($im, string $hex, float $alpha = 1.0): int {
    [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
    return imagecolorallocatealpha($im, $r, $g, $b, (int)round(127 * (1 - max(0, min(1, $alpha)))));
}

function gd_rrect($im, float $x1, float $y1, float $x2, float $y2, float $r, int $col): void {
    $x1 = (int)round($x1); $y1 = (int)round($y1); $x2 = (int)round($x2); $y2 = (int)round($y2);
    $w = $x2 - $x1; $h = $y2 - $y1;
    if ($w <= 0 || $h <= 0) return;
    $r = (int)max(0, min($r, $w / 2, $h / 2));
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

function load_media_image(?int $id) {
    if (!$id) return null;
    $q = db()->prepare("SELECT file, type FROM library WHERE id = ?");
    $q->execute([$id]);
    $m = $q->fetch();
    if (!$m || $m['type'] !== 'image') return null;
    $path = PUBLIC_DIR . '/uploads/' . basename($m['file']);
    if (!is_file($path)) return null;
    $data = @file_get_contents($path);
    $img = $data !== false ? @imagecreatefromstring($data) : false;
    if (!$img) return null;
    if (!imageistruecolor($img)) imagepalettetotruecolor($img);
    imagealphablending($img, true);
    imagesavealpha($img, true);
    return $img;
}

/** Otisk souboru z knihovny (změní se, když se soubor změní) – pro klíč obsahu slidu. */
function library_file_sig(int $id): string {
    $q = db()->prepare("SELECT file FROM library WHERE id = ?");
    $q->execute([$id]);
    $f = PUBLIC_DIR . '/uploads/' . basename((string)$q->fetchColumn());
    return is_file($f) ? filesize($f) . '-' . filemtime($f) : '';
}

/** Vloží obrázek do obdélníku (cover = vyplnit a oříznout, contain = celý obrázek). */
function draw_fit($dst, $src, float $x, float $y, float $w, float $h, string $fit, float $alpha = 1.0): void {
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
    if ($alpha >= 0.999) {
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
        $na = 127 - (int)round((127 - $a) * $alpha);
        imagesetpixel($tmp, $xx, $yy, ($c & 0xffffff) | ($na << 24));
    }
    imagecopy($dst, $tmp, (int)round($dx), (int)round($dy), 0, 0, $tw, $th);

}

function text_width(string $s, float $size, string $font, float $sp = 0): float {
    if ($s === '') return 0;
    if ($sp != 0) {
        // s rozestupem písmen: součet šířek znaků + rozestupy (stejně jako v editoru)
        $chars = mb_str_split($s);
        $w = 0;
        foreach ($chars as $c) $w += char_adv($c, $size, $font);
        return $w + $sp * $size * (count($chars) - 1);
    }
    $b = imagettfbbox($size * 0.75, 0, $font, $s);
    return (float)($b[2] - $b[0]);
}
/** Šířka jednoho znaku i s okraji (rozdíl šířek „HxH“ a „HH“, funguje i pro mezeru). */
function char_adv(string $c, float $size, string $font): float {
    static $cache = [];
    $k = "$font|$size|$c";
    if (!isset($cache[$k])) {
        $pt = $size * 0.75;
        $a = imagettfbbox($pt, 0, $font, "H{$c}H"); $b = imagettfbbox($pt, 0, $font, 'HH');
        $cache[$k] = (float)(($a[2] - $a[0]) - ($b[2] - $b[0]));
    }
    return $cache[$k];
}
/** Napíše řádek textu; s rozestupem písmen po jednotlivých znacích. */
function gd_line($im, float $pt, float $x, float $y, int $col, string $font, string $text, float $spPx = 0): void {
    if ($spPx == 0) { imagettftext($im, $pt, 0, (int)round($x), (int)round($y), $col, $font, $text); return; }
    $size = $pt / 0.75;
    foreach (mb_str_split($text) as $c) {
        if ($c !== ' ') imagettftext($im, $pt, 0, (int)round($x), (int)round($y), $col, $font, $c);
        $x += char_adv($c, $size, $font) + $spPx;
    }
}

/** Zalomí text do šířky (stejný postup jako v editoru). */
function wrap_lines(string $text, float $size, string $font, float $maxW, float $sp = 0): array {
    $lines = [];
    foreach (preg_split('/\r?\n/', $text) as $para) {
        $words = preg_split('/ +/', $para);
        $line = '';
        foreach ($words as $word) {
            $try = $line === '' ? $word : "$line $word";
            if ($line !== '' && text_width($try, $size, $font, $sp) > $maxW) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
            // slovo delší než řádek: rozdělit po znacích
            while (text_width($line, $size, $font, $sp) > $maxW && mb_strlen($line) > 1) {
                $cut = mb_strlen($line) - 1;
                while ($cut > 1 && text_width(mb_substr($line, 0, $cut), $size, $font, $sp) > $maxW) $cut--;
                $lines[] = mb_substr($line, 0, $cut);
                $line = mb_substr($line, $cut);
            }
        }
        $lines[] = $line;
    }
    return $lines;
}

/** Emoji a podobné symboly písma neobsahují – vynechají se (stejně jako v editoru). */
const EMOJI_RE = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';
function strip_emoji(string $s): string { return preg_replace(EMOJI_RE, '', $s); }

/** Spočítá rozvržení textu: velikost, řádky a jejich pozice. */
function layout_text(array $e, string $text): array {
    $font = font_file($e['font'], $e['weight']);
    $pad = $e['bg']['on'] ? $e['bg']['padding'] : 0;
    $ix = $e['x'] + $pad; $iy = $e['y'] + $pad;
    $iw = max(1, $e['w'] - 2 * $pad); $ih = max(1, $e['h'] - 2 * $pad);
    $text = strip_emoji($text);
    if ($e['upper']) $text = mb_strtoupper($text);
    $size = $e['size'];
    $sp = (float)($e['spacing'] ?? 0);
    $lines = wrap_lines($text, $size, $font, $iw, $sp);
    if ($e['autoFit']) {
        while ($size > 8 && count($lines) * $size * $e['lineHeight'] > $ih) {
            $size = max(8, floor($size * 0.94));
            $lines = wrap_lines($text, $size, $font, $iw, $sp);
        }
    }
    $lh = $size * $e['lineHeight'];
    $blockH = count($lines) * $lh;
    $top = match ($e['valign']) { 'middle' => $iy + ($ih - $blockH) / 2, 'bottom' => $iy + $ih - $blockH, default => $iy };
    $out = [];
    foreach ($lines as $i => $line) {
        $w = text_width($line, $size, $font, $sp);
        $x = match ($e['align']) { 'center' => $ix + ($iw - $w) / 2, 'right' => $ix + $iw - $w, default => $ix };
        $out[] = ['text' => $line, 'x' => $x, 'y' => $top + $i * $lh + ($lh - $size) / 2 + $size * 0.8, 'w' => $w];
    }
    return ['font' => $font, 'size' => $size, 'lines' => $out, 'overflow' => $blockH > $ih + 0.5];
}

function draw_text($im, array $e, string $text): void {
    if ($e['bg']['on']) gd_rrect($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['bg']['radius'], gd_color($im, $e['bg']['color'], $e['bg']['alpha'] * $e['alpha']));
    $L = layout_text($e, $text);
    if (!empty($e['fx'])) { draw_text_fx($im, $e, $L); return; }
    $pt = $L['size'] * 0.75;
    $sp = (float)($e['spacing'] ?? 0) * $L['size'];
    $col = gd_color($im, $e['color'], $e['alpha']);
    foreach ($L['lines'] as $ln) {
        if ($ln['text'] === '') continue;
        $x = (int)round($ln['x']); $y = (int)round($ln['y']);
        if ($e['shadow']['on']) {
            gd_line($im, $pt, $x + (int)$e['shadow']['dx'], $y + (int)$e['shadow']['dy'], gd_color($im, $e['shadow']['color'], $e['shadow']['alpha'] * $e['alpha']), $L['font'], $ln['text'], $sp);
        }
        if ($e['outline']['on']) {
            $oc = gd_color($im, $e['outline']['color'], $e['alpha']);
            $r = $e['outline']['width'];
            $steps = max(12, (int)ceil($r * 4));
            for ($k = 0; $k < $steps; $k++) {
                $a = 2 * M_PI * $k / $steps;
                gd_line($im, $pt, $x + (int)round($r * cos($a)), $y + (int)round($r * sin($a)), $oc, $L['font'], $ln['text'], $sp);
            }
        }
        gd_line($im, $pt, $x, $y, $col, $L['font'], $ln['text'], $sp);
    }
}

// ---------- Grafický text (záře, stín, 3D hloubka, obrysy, výplň přechodem, lesk) ----------
// Stejné vrstvy kreslí editor na plátně (drawTextFx v slide-editor.js), aby náhled odpovídal.
function fx_rgb(string $hex): array { return sscanf($hex, '#%02x%02x%02x'); }
function fx_mix(array $a, array $b, float $t): array { return [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t, $a[2] + ($b[2] - $a[2]) * $t]; }
function fx_hex(array $c): string { return sprintf('#%02x%02x%02x', max(0, min(255, (int)round($c[0]))), max(0, min(255, (int)round($c[1]))), max(0, min(255, (int)round($c[2])))); }
/** Barva přechodu v poměru t (0 = nahoře, 1 = dole). */
function fx_grad(array $grad, float $t): array {
    $t = max(0.0, min(1.0, $t));
    for ($i = 1; $i < 4; $i++) if ($t <= FX_STOPS[$i]) {
        $p0 = FX_STOPS[$i - 1]; $p1 = FX_STOPS[$i];
        return fx_mix(fx_rgb($grad[$i - 1]), fx_rgb($grad[$i]), $p1 > $p0 ? ($t - $p0) / ($p1 - $p0) : 0);
    }
    return fx_rgb($grad[3]);
}
/** Řádky textu bíle na černém plátně oblasti, „ztučněné“ o r px (maska v červeném kanálu). */
function fx_mask(int $w, int $h, array $L, float $rx, float $ry, float $r, float $dy, float $spPx) {
    $m = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($m, 255, 255, 255);
    fx_draw($m, $L, $rx, $ry, $r, $dy, $white, $spPx);
    return $m;
}
/** Nakreslí řádky (s posunem dy) barvou $col, při r > 0 v kruhu kolem (obrys). */
function fx_draw($im, array $L, float $ox, float $oy, float $r, float $dy, int $col, float $spPx): void {
    $pt = $L['size'] * 0.75;
    $steps = $r > 0 ? max(12, (int)ceil($r * 3)) : 0;
    foreach ($L['lines'] as $ln) {
        if ($ln['text'] === '') continue;
        $x = $ln['x'] - $ox; $y = $ln['y'] - $oy + $dy;
        for ($k = 0; $k < $steps; $k++) {
            $a = 2 * M_PI * $k / $steps;
            gd_line($im, $pt, $x + $r * cos($a), $y + $r * sin($a), $col, $L['font'], $ln['text'], $spPx);
        }
        gd_line($im, $pt, $x, $y, $col, $L['font'], $ln['text'], $spPx);
    }
}
/** Rozmazání (zmenšení, Gauss, zvětšení) – rychlé i pro velký poloměr. */
function fx_blur($img, float $radius) {
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
    $o = $cur;
    return $o;
}
/** Přimíchá do $im barvu podle masky (oblast od rx, ry; posun ox, oy). $colorAt(y) volitelně podle řádku. */
function fx_paint($im, $mask, int $rx, int $ry, array $rgb, float $strength, int $ox = 0, int $oy = 0, ?callable $colorAt = null): void {
    if ($strength <= 0) return;
    $w = imagesx($mask); $h = imagesy($mask); $W = imagesx($im); $H = imagesy($im);
    for ($my = 0; $my < $h; $my++) {
        $y = $ry + $my + $oy; if ($y < 0 || $y >= $H) continue;
        $c = $colorAt ? $colorAt($my) : $rgb;
        for ($mx = 0; $mx < $w; $mx++) {
            $v = imagecolorat($mask, $mx, $my) >> 16 & 255;
            if ($v < 2) continue;
            $x = $rx + $mx + $ox; if ($x < 0 || $x >= $W) continue;
            $a = $v / 255 * $strength; if ($a > 1) $a = 1;
            $o = imagecolorat($im, $x, $y);
            imagesetpixel($im, $x, $y, ((int)(($o >> 16 & 255) * (1 - $a) + $c[0] * $a) << 16) | ((int)(($o >> 8 & 255) * (1 - $a) + $c[1] * $a) << 8) | (int)(($o & 255) * (1 - $a) + $c[2] * $a));
        }
    }
}
function draw_text_fx($im, array $e, array $L): void {
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
        $g = fx_blur(fx_mask($rw, $rh, $L, $rx, $ry, $sw + $glowR, $depth / 2, $spPx), $glowR * 2);
        fx_paint($im, $g, $rx, $ry, fx_rgb($fx['glowColor']), $fx['glow'] * $al);
    }
    // 2) stín
    if ($fx['shadow'] > 0) {
        $s = fx_blur(fx_mask($rw, $rh, $L, $rx, $ry, $sw, 0, $spPx), $size * 0.08);
        fx_paint($im, $s, $rx, $ry, [0, 0, 0], $fx['shadow'] * $al, $shX, $shY);
    }
    // 3) 3D hloubka: obrys posunutý dolů, směrem dolů tmavne
    $dc = fx_rgb($fx['depthColor']);
    for ($k = $depth; $k >= 1; $k--) {
        $c = fx_mix($dc, fx_mix($dc, [0, 0, 0], 0.55), $k / max(1, $depth));
        fx_draw($im, $L, 0, 0, $sw, $k, gd_color($im, fx_hex($c), $al), $spPx);
    }
    // 4) obrys a druhý (vnitřní) obrys
    if ($sw > 0) fx_draw($im, $L, 0, 0, $sw, 0, gd_color($im, $fx['strokeColor'], $al), $spPx);
    if ($sw2 > 0) fx_draw($im, $L, 0, 0, $sw2, 0, gd_color($im, $fx['stroke2Color'], $al), $spPx);
    // 5) výplň přechodem po řádcích (od výšky velkých písmen po účaří) + 6) lesk v horní části
    $rowBase = [];
    foreach ($lines as $ln) $rowBase[] = $ln['y'];
    $fill = fx_mask($rw, $rh, $L, $rx, $ry, 0, 0, $spPx);
    $colorAt = function (int $my) use ($rowBase, $ry, $size, $fx) {
        $y = $my + $ry; $base = $rowBase[0];
        foreach ($rowBase as $b) { $base = $b; if ($y <= $b + $size * 0.3) break; }
        $top = $base - $size * 0.72;
        $t = ($y - $top) / ($size * 0.72);
        $c = fx_grad($fx['grad'], $t);
        if ($fx['gloss'] > 0 && $t >= 0 && $t < 0.48) $c = fx_mix($c, [255, 255, 245], $fx['gloss'] * (0.25 + 0.75 * (1 - $t / 0.48)));
        return $c;
    };
    fx_paint($im, $fill, $rx, $ry, [0, 0, 0], $al, 0, 0, $colorAt);
}

// ---------- Kuličky (tažená čísla) ----------
/** Čísla z textu („22 14 27“, „22, 14, 27“…). Neznámá proměnná ve složených závorkách se ignoruje. */
function ball_numbers(string $s): array {
    $s = preg_replace('/\{[^{}]*\}/u', '', $s);
    preg_match_all('/\d+/', $s, $m);
    return array_slice($m[0], 0, 100);
}

/**
 * Rozmístění kuliček v rámečku (stejný výpočet je v editoru, assets/slide-editor.js → layoutBalls).
 * Čísla se řadí zleva, zalomí se podle šířky; když se nevejdou, kuličky se zmenší.
 */
function layout_balls(array $e, array $nums, array $extras): array {
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
function ball_shade(array $rgb, float $nx, float $ny, bool $glossy): array {
    if (!$glossy) return $rgb;
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
function gd_ball($im, float $cx, float $cy, float $r, array $e, bool $extra): void {
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
            $c = ball_shade($fill, $dx / $r, $dy / $r, $glossy);
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

function draw_balls($im, array $e, array $nums, array $extras): void {
    $L = layout_balls($e, $nums, $extras);
    $font = font_file($e['font'], $e['weight']);
    $pt = $L['d'] * $e['numScale'] * 0.75;
    foreach ($L['balls'] as $b) {
        gd_ball($im, $b['x'], $b['y'], $L['d'] / 2, $e, $b['extra']);
        $bb = imagettfbbox($pt, 0, $font, $b['n']);
        $x = $b['x'] - ($bb[0] + $bb[2]) / 2;
        $y = $b['y'] - ($bb[1] + $bb[7]) / 2;
        imagettftext($im, $pt, 0, (int)round($x), (int)round($y), gd_color($im, $b['extra'] ? $e['extraColor'] : $e['color'], $e['alpha']), $font, $b['n']);
    }
}

/** Vykreslí šablonu a vrátí JPEG (data). */
function render_slide(array $design, DateTimeImmutable $t, int $quality = 90): string {
    if (!function_exists('imagettftext')) throw new RuntimeException('Na serveru chybí knihovna GD s podporou písem (FreeType).');
    @ini_set('memory_limit', '512M');
    $SLIDE_W = (int)$design['w']; $SLIDE_H = (int)$design['h'];
    $im = imagecreatetruecolor($SLIDE_W, $SLIDE_H);
    imagealphablending($im, true);
    $bg = $design['bg'];
    imagefilledrectangle($im, 0, 0, $SLIDE_W - 1, $SLIDE_H - 1, gd_color($im, $bg['color']));
    if ($bg['type'] === 'gradient') {
        [$r1, $g1, $b1] = sscanf($bg['color'], '#%02x%02x%02x');
        [$r2, $g2, $b2] = sscanf($bg['color2'], '#%02x%02x%02x');
        $n = $bg['dir'] === 'h' ? $SLIDE_W : $SLIDE_H;
        for ($i = 0; $i < $n; $i++) {
            $k = $i / ($n - 1);
            $c = imagecolorallocate($im, (int)round($r1 + ($r2 - $r1) * $k), (int)round($g1 + ($g2 - $g1) * $k), (int)round($b1 + ($b2 - $b1) * $k));
            $bg['dir'] === 'h' ? imageline($im, $i, 0, $i, $SLIDE_H - 1, $c) : imageline($im, 0, $i, $SLIDE_W - 1, $i, $c);
        }
    } elseif ($bg['type'] === 'image' && ($src = load_media_image($bg['mediaId']))) {
        draw_fit($im, $src, 0, 0, $SLIDE_W, $SLIDE_H, $bg['fit']);

    }
    if ($bg['dim'] > 0) imagefilledrectangle($im, 0, 0, $SLIDE_W - 1, $SLIDE_H - 1, gd_color($im, '#000000', $bg['dim']));

    $ev = design_eval($design, $t);
    foreach ($design['elements'] as $e) {
        if ($e['hidden'] || empty($ev[$e['id']]['visible'])) continue;
        if ($e['type'] === 'rect') {
            gd_rrect($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['radius'], gd_color($im, $e['color'], $e['alpha']));
        } elseif ($e['type'] === 'image') {
            if ($src = load_media_image($e['mediaId'])) { draw_fit($im, $src, $e['x'], $e['y'], $e['w'], $e['h'], $e['fit'], $e['alpha']); }
        } elseif ($e['type'] === 'balls') {
            draw_balls($im, $e, $ev[$e['id']]['nums'] ?? [], $ev[$e['id']]['extras'] ?? []);
        } elseif ($e['type'] === 'weather') {
            draw_weather($im, $e, $ev[$e['id']]['parts'] ?? []);
        } else {
            draw_text($im, $e, $ev[$e['id']]['text'] ?? $e['text']);
        }
    }
    ob_start();
    imagejpeg($im, null, $quality);

    return ob_get_clean();
}

// ---------- prvek Počasí ve slidu (převzato z Plánovače médií) ----------
const WEATHER_DAYS = 3;
const WEATHER_LOOKS = ['mini', 'card', 'days'];
const WEATHER_ICON_DIR = ROOT . '/data/weather-icons';

/** Cesta k uložené ikoně OpenWeather; chybí-li, stáhne se. Vrací null, když ikonu nejde získat. */
function weather_icon_file(string $code): ?string {
    if (!preg_match('/^[0-9a-z]{2,4}$/', $code)) return null;
    $f = WEATHER_ICON_DIR . "/ow-$code.png";
    if (is_file($f)) return $f;
    static $failed = [];
    if (isset($failed[$code])) return null;
    if (!is_dir(WEATHER_ICON_DIR)) @mkdir(WEATHER_ICON_DIR, 0775, true);
    try { $body = weather_http(rtrim((string)cfg('openweather_icon_url', 'https://openweathermap.org/img/wn'), '/') . "/$code@4x.png")[1]; }
    catch (Throwable $e) { $body = null; }
    if (!is_string($body) || !@imagecreatefromstring($body)) { $failed[$code] = true; return null; }
    file_put_contents("$f.tmp", $body);
    rename("$f.tmp", $f);
    return $f;
}

/** Vzorová data pro náhled v editoru, když skutečná data ještě nejsou. */
function weather_sample(DateTimeImmutable $t): array {
    $days = [];
    foreach ([[18, 9, '02d', 'skoro jasno'], [15, 8, '10d', 'slabý déšť'], [13, 6, '04d', 'zataženo']] as $i => [$mx, $mn, $ic, $ds]) {
        $days[] = ['date' => $t->modify("+$i day")->format('Y-m-d'), 'max' => $mx, 'min' => $mn, 'icon' => $ic, 'desc' => $ds];
    }
    return ['place' => 'Ukázka', 'now' => ['temp' => 16, 'feels' => 15, 'desc' => 'skoro jasno', 'icon' => '02d', 'humidity' => 62, 'wind' => 3], 'days' => $days];
}

/**
 * Rozvržení prvku Počasí: seznam částí s přesnou polohou (stejně je kreslí server i editor).
 *   ['k' => 'icon', 'x', 'y', 's', 'code']        ikona ve čtverci o straně s
 *   ['k' => 'text', 'x', 'y', 'size', 'text']     text zleva od x, y = účaří
 */
function weather_layout(array $e, array $data, DateTimeImmutable $t): array {
    $font = font_file($e['font'], $e['weight']);
    $pad = $e['bg']['on'] ? $e['bg']['padding'] : 0;
    $ix = $e['x'] + $pad; $iy = $e['y'] + $pad;
    $iw = max(1, $e['w'] - 2 * $pad); $ih = max(1, $e['h'] - 2 * $pad);
    $tw = fn(string $s, float $size) => text_width($s, $size, $font);
    $alignX = fn(float $w, float $x0, float $width) => match ($e['align']) { 'left' => $x0, 'right' => $x0 + $width - $w, default => $x0 + ($width - $w) / 2 };
    $n = $data['now'] ?? [];
    $deg = fn($v) => weather_int((float)$v) . ' °C';
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
        $t2 = weather_ucfirst((string)($n['desc'] ?? ''));
        $t3 = 'Pocitově ' . $deg($n['feels'] ?? 0) . ' · Vlhkost ' . (int)($n['humidity'] ?? 0) . ' % · Vítr ' . weather_int((float)($n['wind'] ?? 0)) . ' m/s';
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
        for ($i = 0; $i < WEATHER_DAYS; $i++) {
            $d = weather_day($data, $i, $t);
            $cols[] = $d ? [weather_day_label($d['date'], $t), (string)$d['icon'], weather_int((float)$d['max']) . '° / ' . weather_int((float)$d['min']) . '°'] : ['', '', ''];
        }
        $cw = $iw / WEATHER_DAYS;
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

/** Vyhodnocení prvku pro design_eval: jen části k vykreslení (čas stažení ani chyba nemění obrázek, slide se kvůli nim nepřegeneruje). */
function weather_eval(array $e, DateTimeImmutable $t): array {
    [$data] = weather_get($e['place']);
    return ['parts' => $data ? weather_layout($e, $data, $t) : []];
}

/** Stav prvku pro editor: místo, čas stažení, chyba; bez dat ukázkové rozvržení (sample). */
function weather_editor_info(array $e, DateTimeImmutable $t): array {
    [$data, $err] = weather_get($e['place']);
    $r = ['place' => weather_place($e['place']), 'error' => $err, 'at' => $data['at'] ?? null, 'sample' => !$data];
    if (!$data) $r['parts'] = weather_layout($e, weather_sample($t), $t);
    return $r;
}

function draw_weather($im, array $e, array $parts): void {
    if (!$parts) return;
    if ($e['bg']['on']) gd_rrect($im, $e['x'], $e['y'], $e['x'] + $e['w'], $e['y'] + $e['h'], $e['bg']['radius'], gd_color($im, $e['bg']['color'], $e['bg']['alpha'] * $e['alpha']));
    $font = font_file($e['font'], $e['weight']);
    $col = gd_color($im, $e['color'], $e['alpha']);
    foreach ($parts as $p) {
        if ($p['k'] === 'icon') {
            $f = $p['code'] !== '' ? weather_icon_file($p['code']) : null;
            $src = $f ? @imagecreatefrompng($f) : null;
            if ($src) {
                imagealphablending($src, true); imagesavealpha($src, true);
                draw_fit($im, $src, $p['x'], $p['y'], $p['s'], $p['s'], 'contain', $e['alpha']);
            }
        } else {
            if ($e['shadow']['on']) gd_line($im, $p['size'] * 0.75, $p['x'] + $e['shadow']['dx'], $p['y'] + $e['shadow']['dy'], gd_color($im, $e['shadow']['color'], $e['shadow']['alpha'] * $e['alpha']), $font, $p['text']);
            gd_line($im, $p['size'] * 0.75, $p['x'], $p['y'], $col, $font, $p['text']);
        }
    }
}

function sanitize_weather_element(array $e): array {
    $sh = $e['shadow'] ?? []; $tb = $e['bg'] ?? [];
    return [
        'look' => in_array($e['look'] ?? '', WEATHER_LOOKS, true) ? $e['look'] : 'mini',
        'place' => mb_substr(trim((string)($e['place'] ?? '')), 0, 100),
        'font' => font_exists((string)($e['font'] ?? '')) ? $e['font'] : 'montserrat',
        'weight' => (int)clampf($e['weight'] ?? 700, 100, 900, 700),
        'color' => hexcolor($e['color'] ?? null, '#ffffff'),
        'align' => in_array($e['align'] ?? '', ['left', 'center', 'right'], true) ? $e['align'] : 'center',
        'shadow' => ['on' => !empty($sh['on']), 'color' => hexcolor($sh['color'] ?? null, '#000000'), 'alpha' => clampf($sh['alpha'] ?? 0.5, 0, 1, 0.5),
                     'dx' => clampf($sh['dx'] ?? 4, -60, 60, 4), 'dy' => clampf($sh['dy'] ?? 4, -60, 60, 4)],
        'bg' => ['on' => !empty($tb['on']), 'color' => hexcolor($tb['color'] ?? null, '#000000'), 'alpha' => clampf($tb['alpha'] ?? 0.35, 0, 1, 0.35),
                 'padding' => clampf($tb['padding'] ?? 32, 0, 300, 32), 'radius' => clampf($tb['radius'] ?? 24, 0, 500, 24)],
    ];
}

// ---------- slidy v knihovně ----------
/** Slide z knihovny: řádek library s dekódovanou šablonou, nebo null. */
function slide_get(int $id): ?array {
    $q = db()->prepare("SELECT * FROM library WHERE id = ? AND design IS NOT NULL");
    $q->execute([$id]);
    $m = $q->fetch();
    if (!$m) return null;
    $m['design'] = sanitize_design(json_decode((string)$m['design'], true) ?: []);
    return $m;
}

/** Vykreslí slide pro čas $t a uloží ho do jeho souboru v uploads/ (stejný název – příspěvky na něj odkazují). */
function slide_store(int $id, array $design, DateTimeImmutable $t): string {
    $q = db()->prepare("SELECT file FROM library WHERE id = ?");
    $q->execute([$id]);
    $file = (string)$q->fetchColumn();
    if ($file === '') $file = bin2hex(random_bytes(12)) . '.jpg';
    $jpg = render_slide($design, $t);
    $path = PUBLIC_DIR . '/uploads/' . basename($file);
    file_put_contents("$path.tmp", $jpg);
    rename("$path.tmp", $path);
    clearstatcache(true, $path);
    db()->prepare("UPDATE library SET file = ?, size = ?, w = ?, h = ?, design = ?, slide_key = ? WHERE id = ?")
        ->execute([basename($file), strlen($jpg), (int)$design['w'], (int)$design['h'], json_encode($design, JSON_UNESCAPED_UNICODE), render_key($design, $t), $id]);
    return basename($file);
}

/**
 * Těsně před zveřejněním: slidy mezi soubory příspěvku se přegenerují pro čas zveřejnění, pokud se jejich obsah změnil
 * (data, datum, svátek, počasí, použité obrázky). Chyba vykreslení zveřejnění nezastaví – použije se poslední obrázek.
 */
function slides_refresh_files(array $files, DateTimeImmutable $t): void {
    $files = array_values(array_unique(array_filter(array_map('strval', $files))));
    if (!$files) return;
    $q = db()->prepare("SELECT id, name FROM library WHERE file = ? AND design IS NOT NULL");
    foreach ($files as $f) {
        $q->execute([basename($f)]);
        if (!($row = $q->fetch())) continue;
        $m = slide_get((int)$row['id']);
        if (!$m) continue;
        try {
            if ($m['slide_key'] === render_key($m['design'], $t) && is_file(PUBLIC_DIR . '/uploads/' . $m['file'])) continue;
            slide_store((int)$m['id'], $m['design'], $t);
            log_line("Slide „{$m['name']}“ přegenerován pro " . $t->format('j. n. Y G:i') . '.');
        } catch (Throwable $e) {
            log_line("Slide „{$m['name']}“ se nepodařilo vykreslit: " . $e->getMessage() . ' Použije se poslední obrázek.');
        }
    }
}

/** Slidy, které používají písmo (názvy). */
function font_usage(string $key): array {
    $names = [];
    foreach (db()->query("SELECT name, design FROM library WHERE design IS NOT NULL")->fetchAll() as $m) {
        $d = json_decode((string)$m['design'], true) ?: [];
        foreach ($d['elements'] ?? [] as $e) if (($e['font'] ?? '') === $key) { $names[] = $m['name']; break; }
    }
    return $names;
}
