<?php
// Zmrazená kopie lib/html.php z Plánovače médií (bez ukládání do databáze). Needitovat.
// HTML dokumenty v galerii (od 1.16.0): zdroj v media.content (JSON {source, key}), soubor na disku = zdroj
// s dosazenými proměnnými a vloženými písmy aplikace. Přehrávače ho zobrazují izolovaně (sandbox) v ploše 1920×1080.

const HTML_MAX_BYTES = 5 * 1024 * 1024;

/** Nový dokument: tmavé pozadí, okraje, písmo Montserrat (jako nový slide). */
function html_default(): string {
    return "<!DOCTYPE html>\n<html lang=\"cs\">\n<head>\n<meta charset=\"utf-8\">\n<style>\n"
        . "html, body { margin: 0; width: 1920px; height: 1080px; overflow: hidden; }\n"
        . "body { box-sizing: border-box; padding: 80px 110px; background: #1d1c1a; color: #ffffff; font-family: 'Montserrat', sans-serif; font-size: 56px; line-height: 1.3; }\n"
        . "h1 { font-size: 110px; margin: 0 0 40px; }\n"
        . "</style>\n</head>\n<body>\n<h1>Nadpis</h1>\n<p>Text dokumentu</p>\n</body>\n</html>\n";
}

/** Kontrola zdroje: velikost, kódování, není prázdný. Vrací zdroj s jednotnými konci řádků. */
function html_check(string $src): string {
    $src = preg_replace('/^\xEF\xBB\xBF/', '', $src);
    if (strlen($src) > HTML_MAX_BYTES) throw new InvalidArgumentException('Dokument je větší než 5 MB. Zmenšete obrázky v něm.');
    if (!mb_check_encoding($src, 'UTF-8')) {
        $conv = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//TRANSLIT', $src) : false;
        if ($conv === false) throw new InvalidArgumentException('Dokument není v kódování UTF-8.');
        $src = $conv;
    }
    $src = str_replace(["\r\n", "\r"], "\n", $src);
    if (trim(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', 'x', $src))) === '' && !preg_match('~<(img|svg|canvas|video|iframe)\b~i', $src)) {
        throw new InvalidArgumentException('Dokument je prázdný.');
    }
    return $src;
}

/**
 * Dosadí {proměnné} v textu dokumentu – ne uvnitř <script> a <style>. Hodnoty se escapují pro HTML.
 * Editor může v zápisu proměnné nahradit mezeru za &nbsp; apod., proto se zápis před vyhodnocením dekóduje.
 * Vrací [html, neznámé proměnné].
 */
function html_resolve(string $src, DateTimeImmutable $t): array {
    $unknown = [];
    $parts = preg_split('~(<script\b.*?</script\s*>|<style\b.*?</style\s*>|<!--.*?-->)~is', $src, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $p) {
        if ($i % 2 === 1) continue; // skript, styl, komentář beze změny
        $parts[$i] = preg_replace_callback('/\{[^{}<>]{1,300}\}/u', function ($m) use ($t, &$unknown) {
            $raw = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $raw = str_replace("\u{00A0}", ' ', $raw);
            [$out, $unk] = resolve_text($raw, $t);
            if ($unk) { array_push($unknown, ...$unk); return $m[0]; }
            return htmlspecialchars($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }, $p);
    }
    return [implode('', $parts), array_values(array_unique($unknown))];
}

/** Písma aplikace, jejichž název dokument používá (font-family), jako @font-face s daty přímo v dokumentu. */
function html_fonts_css(string $src): string {
    $css = '';
    foreach (fonts_all() as $key => $f) {
        if (!preg_match('/(["\'\s:,;]|&quot;)' . preg_quote($f['name'], '/') . '(["\'\s,;]|&quot;|$)/iu', $src)) continue;
        $weights = $f['custom'] ? [700] : $f['weights'];
        foreach ($weights as $w) {
            $file = font_file($key, $w);
            if (!is_file($file)) continue;
            $fmt = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'otf' ? 'opentype' : 'truetype';
            $css .= "@font-face { font-family: '" . addslashes($f['name']) . "'; font-weight: " . ($f['custom'] ? '100 900' : $w)
                . "; font-style: normal; src: url(data:font/$fmt;base64," . base64_encode((string)file_get_contents($file)) . ") format('$fmt'); }\n";
        }
    }
    return $css;
}

/** Vloží styl s písmy do hlavičky dokumentu. */
function html_inject_head(string $html, string $css): string {
    if ($css === '') return $html;
    $tag = "<style data-mp=\"fonts\">\n$css</style>\n";
    if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $pos) . "\n" . $tag . substr($html, $pos);
    }
    if (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
        $pos = $m[0][1] + strlen($m[0][0]);
        return substr($html, 0, $pos) . "\n<head>$tag</head>" . substr($html, $pos);
    }
    return $tag . $html;
}

/** Hotový dokument pro přehrávač: proměnné + písma. Vrací [html, neznámé proměnné]. */
function html_build(string $src, DateTimeImmutable $t): array {
    [$html, $unknown] = html_resolve($src, $t);
    $html = html_inject_head($html, html_fonts_css($src));
    if (!preg_match('~<meta[^>]+charset~i', $html)) {
        // kódování hned za <!DOCTYPE> (před ním by prohlížeč přešel do režimu kompatibility)
        $html = preg_match('~^\s*<!doctype[^>]*>~i', $html, $m) ? $m[0] . "\n<meta charset=\"utf-8\">" . substr($html, strlen($m[0])) : "<meta charset=\"utf-8\">\n" . $html;
    }
    return [$html, $unknown];
}

/** Adresy na internetu, na které dokument odkazuje (obrázky, skripty, styly, písma) – fungují jen s připojením. */
function html_external_hosts(string $src): array {
    preg_match_all('~(?:\b(?:src|href|poster|data)\s*=\s*["\']?|url\(\s*["\']?|@import\s+["\'])\s*(https?:)?//([a-z0-9.-]+)~i', $src, $m);
    $hosts = array_values(array_unique(array_map('strtolower', $m[2])));
    sort($hosts);
    return $hosts;
}

