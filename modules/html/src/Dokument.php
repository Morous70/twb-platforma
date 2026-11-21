<?php
declare(strict_types=1);
namespace TWB\Html;

use DateTimeImmutable;
use InvalidArgumentException;
use TWB\Pisma\Pisma;

/**
 * HTML dokument jako zdroj obsahu (slide, banner). Modul nic neukládá: zdroj i hotový soubor ukládá aplikace
 * (galerie, tabulka banerů). Hotový dokument = zdroj s dosazenými proměnnými a písmy vloženými přímo do něj.
 */
final class Dokument
{
    public const MAX_BAJTU = 5 * 1024 * 1024;

    public function __construct(private Pisma $pisma, private Promenne $promenne) {}

    /** Nový dokument: tmavé pozadí, okraje, písmo Montserrat. */
    public static function vychozi(int $sirka = 1920, int $vyska = 1080): string
    {
        return "<!DOCTYPE html>\n<html lang=\"cs\">\n<head>\n<meta charset=\"utf-8\">\n<style>\n"
            . "html, body { margin: 0; width: {$sirka}px; height: {$vyska}px; overflow: hidden; }\n"
            . "body { box-sizing: border-box; padding: 80px 110px; background: #1d1c1a; color: #ffffff; font-family: 'Montserrat', sans-serif; font-size: 56px; line-height: 1.3; }\n"
            . "h1 { font-size: 110px; margin: 0 0 40px; }\n"
            . "</style>\n</head>\n<body>\n<h1>Nadpis</h1>\n<p>Text dokumentu</p>\n</body>\n</html>\n";
    }

    /** Kontrola zdroje: velikost, kódování, není prázdný. Vrací zdroj s jednotnými konci řádků. */
    public static function zkontroluj(string $zdroj): string
    {
        $zdroj = preg_replace('/^\xEF\xBB\xBF/', '', $zdroj);
        if (strlen($zdroj) > self::MAX_BAJTU) throw new InvalidArgumentException('Dokument je větší než 5 MB. Zmenšete obrázky v něm.');
        if (!mb_check_encoding($zdroj, 'UTF-8')) {
            $conv = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//TRANSLIT', $zdroj) : false;
            if ($conv === false) throw new InvalidArgumentException('Dokument není v kódování UTF-8.');
            $zdroj = $conv;
        }
        $zdroj = str_replace(["\r\n", "\r"], "\n", $zdroj);
        if (trim(strip_tags(preg_replace('~<(script|style)\b.*?</\1>~is', 'x', $zdroj))) === '' && !preg_match('~<(img|svg|canvas|video|iframe)\b~i', $zdroj)) {
            throw new InvalidArgumentException('Dokument je prázdný.');
        }
        return $zdroj;
    }

    /**
     * Dosadí {proměnné} v textu dokumentu – ne uvnitř <script>, <style> a komentářů. Hodnoty se escapují pro HTML.
     * Editor může v zápisu proměnné nahradit mezeru za &nbsp; apod., proto se zápis před vyhodnocením dekóduje.
     * @return array{0: string, 1: list<string>} [html, neznámé proměnné]
     */
    public function dosad(string $zdroj, DateTimeImmutable $t): array
    {
        $neznama = [];
        $casti = preg_split('~(<script\b.*?</script\s*>|<style\b.*?</style\s*>|<!--.*?-->)~is', $zdroj, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($casti as $i => $p) {
            if ($i % 2 === 1) continue;
            $casti[$i] = preg_replace_callback('/\{[^{}<>]{1,300}\}/u', function ($m) use ($t, &$neznama) {
                $raw = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $raw = str_replace("\u{00A0}", ' ', $raw);
                [$out, $unk] = $this->promenne->vyres($raw, $t);
                if ($unk) { array_push($neznama, ...$unk); return $m[0]; }
                return htmlspecialchars($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }, $p);
        }
        return [implode('', $casti), array_values(array_unique($neznama))];
    }

    /** Písma, jejichž název dokument používá (font-family), jako @font-face s daty přímo v dokumentu. */
    public function pismaCss(string $zdroj): string
    {
        $css = '';
        foreach ($this->pisma->vsechna() as $klic => $f) {
            if (!preg_match('/(["\'\s:,;]|&quot;)' . preg_quote($f['name'], '/') . '(["\'\s,;]|&quot;|$)/iu', $zdroj)) continue;
            $tloustky = $f['custom'] ? [700] : $f['weights'];
            foreach ($tloustky as $w) {
                $soubor = $this->pisma->soubor($klic, $w);
                if (!is_file($soubor)) continue;
                $fmt = strtolower(pathinfo($soubor, PATHINFO_EXTENSION)) === 'otf' ? 'opentype' : 'truetype';
                $css .= "@font-face { font-family: '" . addslashes($f['name']) . "'; font-weight: " . ($f['custom'] ? '100 900' : $w)
                    . "; font-style: normal; src: url(data:font/$fmt;base64," . base64_encode((string)file_get_contents($soubor)) . ") format('$fmt'); }\n";
            }
        }
        return $css;
    }

    /** Vloží styl s písmy do hlavičky dokumentu. */
    public static function vlozDoHlavicky(string $html, string $css): string
    {
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

    /** Hotový dokument: proměnné + písma + kódování. @return array{0: string, 1: list<string>} [html, neznámé proměnné] */
    public function sestav(string $zdroj, DateTimeImmutable $t): array
    {
        [$html, $neznama] = $this->dosad($zdroj, $t);
        $html = self::vlozDoHlavicky($html, $this->pismaCss($zdroj));
        if (!preg_match('~<meta[^>]+charset~i', $html)) {
            // kódování hned za <!DOCTYPE> (před ním by prohlížeč přešel do režimu kompatibility)
            $html = preg_match('~^\s*<!doctype[^>]*>~i', $html, $m) ? $m[0] . "\n<meta charset=\"utf-8\">" . substr($html, strlen($m[0])) : "<meta charset=\"utf-8\">\n" . $html;
        }
        return [$html, $neznama];
    }

    /** Otisk hotového dokumentu: když se nezměnil, není třeba ho ukládat znovu. */
    public function klic(string $zdroj, DateTimeImmutable $t): string { return md5($this->sestav($zdroj, $t)[0]); }

    /** Adresy na internetu, na které dokument odkazuje (obrázky, skripty, styly, písma) – fungují jen s připojením. */
    public static function externiHosty(string $zdroj): array
    {
        preg_match_all('~(?:\b(?:src|href|poster|data)\s*=\s*["\']?|url\(\s*["\']?|@import\s+["\'])\s*(https?:)?//([a-z0-9.-]+)~i', $zdroj, $m);
        $hosts = array_values(array_unique(array_map('strtolower', $m[2])));
        sort($hosts);
        return $hosts;
    }
}
