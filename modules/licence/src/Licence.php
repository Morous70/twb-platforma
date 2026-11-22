<?php
declare(strict_types=1);
namespace TWB\Licence;

/**
 * Licence médií: společný číselník, odhad kódu z textu nebo odkazu, čitelný název a uvedení autora.
 * Kódy jsou shodné s číselníkem IMG_LICENSES v Social Planneru a Kvízech, aby si aplikace mohly média předávat beze změny.
 * „verze“ je u CC BY a CC BY-SA číslo verze (např. 4.0), u „Jiné“ přesný název licence; u ostatních se nepoužívá.
 */
final class Licence
{
    public const KODY = [
        'cc0'      => 'CC0 (volné dílo)',
        'cc-by'    => 'CC BY',
        'cc-by-sa' => 'CC BY-SA',
        'vlastni'  => 'Vlastní foto',
        'placena'  => 'Placená licence',
        'pixabay'  => 'Pixabay licence',
        'pexels'   => 'Pexels licence',
        'ai'       => 'Vygenerováno AI',
        'jine'     => 'Jiné',
    ];

    /** Vysvětlivky k výběru ve formuláři. */
    public const NAPOVEDA = [
        'cc0'      => 'volné dílo, autora uvádět nemusíte',
        'cc-by'    => 'volné k použití, ale musíte uvést autora; verzi (např. 4.0) doplňte do pole Verze',
        'cc-by-sa' => 'uveďte autora a dílo sdílejte pod stejnou licencí; verzi (např. 4.0) doplňte do pole Verze',
        'vlastni'  => 'fotka nebo grafika, kterou jste vytvořili vy',
        'placena'  => 'koupená fotka nebo fotobanka',
        'pixabay'  => 'obsah z Pixabay – lze použít i upravit zdarma, autora uvádět nemusíte',
        'pexels'   => 'fotka z Pexels – lze použít i upravit zdarma, uvedení fotografa je vítané',
        'ai'       => 'obsah vytvořený umělou inteligencí, autora obvykle neuvádíte',
        'jine'     => 'přesný název licence napište do pole Verze / název',
    ];

    /** Licence, u kterých má smysl pole „verze“ (verze u CC, název u Jiné). */
    public const S_VERZI = ['cc-by', 'cc-by-sa', 'jine'];

    /** Licence, které vyžadují uvedení autora u každého použití. */
    public const POVINNE_UVEDENI = ['cc-by', 'cc-by-sa'];

    public const MAX_VERZE = 120;

    private const URL = [
        'cc0' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        'pixabay' => 'https://pixabay.com/service/license-summary/',
        'pexels' => 'https://www.pexels.com/license/',
    ];

    public static function platny(?string $kod): bool { return $kod !== null && isset(self::KODY[$kod]); }

    /**
     * Ověří a očistí dvojici kód + verze. Prázdný kód = licence neuvedena (null, null). Verze se zahodí, kde nedává smysl.
     * @return array{0:?string, 1:?string}
     */
    public static function vycisti(?string $kod, ?string $verze): array
    {
        $kod = trim((string)$kod);
        if ($kod === '') return [null, null];
        if (!isset(self::KODY[$kod])) throw new \InvalidArgumentException('Neznámý kód licence „' . $kod . '“. Povolené: ' . implode(', ', array_keys(self::KODY)) . '.');
        $verze = trim((string)preg_replace('/\s+/u', ' ', (string)$verze));
        if (mb_strlen($verze) > self::MAX_VERZE) throw new \InvalidArgumentException('Verze nebo název licence smí mít nejvýš ' . self::MAX_VERZE . ' znaků.');
        if (!in_array($kod, self::S_VERZI, true) || $verze === '') $verze = null;
        if ($verze !== null && $kod !== 'jine' && !preg_match('/^\d+(\.\d+)?$/', $verze)) throw new \InvalidArgumentException('Verze licence CC je číslo, např. 4.0.');
        return [$kod, $verze];
    }

    /**
     * Odhadne kód licence z volného textu (metadata, starší údaje) a odkazu na licenci.
     * Nejistý nebo „všechna práva vyhrazena“ → kód null (licence neurčena); jiná svobodná licence → „jine“ s názvem.
     * Licence CC s NC nebo ND se vrací jako „jine“ s přesným názvem (nesmí se zaměnit s CC BY).
     * @return array{kod:?string, verze:?string}
     */
    public static function odhad(?string $text, ?string $url = null): array
    {
        $u = strtolower(trim((string)$url));
        $t = trim((string)preg_replace('/\s+/u', ' ', (string)$text));
        if ($u !== '' && preg_match('~creativecommons\.org/licenses/([a-z-]+)/(\d+(?:\.\d+)?)?~', $u, $m)) {
            $druh = $m[1]; $v = $m[2] ?? '';
            if ($druh === 'by' || $druh === 'by-sa') return ['kod' => 'cc-' . $druh, 'verze' => $v !== '' ? $v : null];
            return ['kod' => 'jine', 'verze' => 'CC ' . strtoupper($druh) . ($v !== '' ? " $v" : '')];
        }
        if ($u !== '' && preg_match('~creativecommons\.org/publicdomain/(zero|mark)~', $u)) return ['kod' => 'cc0', 'verze' => null];
        if ($u !== '' && str_contains($u, 'pixabay.com/service/')) return ['kod' => 'pixabay', 'verze' => null];
        if ($u !== '' && str_contains($u, 'pexels.com/license')) return ['kod' => 'pexels', 'verze' => null];
        if ($t === '') return ['kod' => null, 'verze' => null];

        // „CC BY-SA 4.0“, „CC-BY-4.0“, „Creative Commons Attribution-ShareAlike 4.0 International“
        $n = strtolower($t);
        $n = str_replace(['creative commons', 'attribution', 'sharealike', 'share alike', 'noncommercial', 'non-commercial', 'noderivatives', 'noderivs', 'no derivatives'],
            ['cc', 'by', 'sa', 'sa', 'nc', 'nc', 'nd', 'nd', 'nd'], $n);
        if (preg_match('~\bcc[\s-]*(by(?:[\s-]*(?:sa|nc|nd))*)\b[\s-]*(\d+(?:\.\d+)?)?~', $n, $m)) {
            $casti = preg_split('~[\s-]+~', $m[1]) ?: [];
            $v = $m[2] ?? '';
            if ($casti === ['by'] || $casti === ['by', 'sa']) return ['kod' => 'cc-' . implode('-', $casti), 'verze' => $v !== '' ? $v : null];
            return ['kod' => 'jine', 'verze' => mb_substr('CC ' . strtoupper(implode('-', $casti)) . ($v !== '' ? " $v" : ''), 0, self::MAX_VERZE)];
        }
        if (preg_match('~\bcc[\s-]?0\b|\bcc[\s-]?zero\b|public domain|volné dílo|publicdomain~u', $n)) return ['kod' => 'cc0', 'verze' => null];
        if (str_contains($n, 'pixabay')) return ['kod' => 'pixabay', 'verze' => null];
        if (str_contains($n, 'pexels')) return ['kod' => 'pexels', 'verze' => null];
        if (preg_match('~vygenerov|umělá inteligence|\bai[\s-]generated|\bgenerated by ai\b~u', $n)) return ['kod' => 'ai', 'verze' => null];
        if (preg_match('~©|\(c\)|copyright|all rights reserved|všechna práva~u', $n)) return ['kod' => null, 'verze' => null];
        return ['kod' => 'jine', 'verze' => mb_substr($t, 0, self::MAX_VERZE)];
    }

    /** Čitelný název: „CC BY-SA 4.0“, „Pixabay licence“, u Jiné přesný název. Null = licence neuvedena. */
    public static function nazev(?string $kod, ?string $verze = null): ?string
    {
        if (!self::platny($kod)) return null;
        $verze = trim((string)$verze);
        if ($kod === 'jine') return $verze !== '' ? $verze : self::KODY['jine'];
        return self::KODY[$kod] . (in_array($kod, ['cc-by', 'cc-by-sa'], true) && $verze !== '' ? " $verze" : '');
    }

    /** Odkaz na znění licence: zadaný (jen http/https) má přednost, jinak známý odkaz podle kódu a verze. */
    public static function odkaz(?string $kod, ?string $verze = null, ?string $zadany = null): ?string
    {
        $zadany = trim((string)$zadany);
        if ($zadany !== '' && preg_match('~^https?://[^\s<>"]+$~i', $zadany)) return $zadany;
        if (!self::platny($kod)) return null;
        if (in_array($kod, ['cc-by', 'cc-by-sa'], true)) {
            $v = trim((string)$verze) !== '' ? trim((string)$verze) : '4.0';
            return 'https://creativecommons.org/licenses/' . substr((string)$kod, 3) . '/' . $v . '/';
        }
        return self::URL[$kod] ?? null;
    }

    public static function povinneUvedeni(?string $kod): bool { return in_array($kod, self::POVINNE_UVEDENI, true); }

    /**
     * Uvedení autora: části „autor“, „zdroj“, „licence“ (prázdné se vynechají) a z nich text i HTML.
     * $upraveno: u CC BY / CC BY-SA připojí „(upraveno)“ – pro aplikace, které obrázek vždy ořežou nebo zmenší.
     * @param array{autor?:?string, zdroj?:?string, zdroj_url?:?string, kod?:?string, verze?:?string, licence_url?:?string} $u
     * @return array{casti: list<array{druh:string, text:string, url:?string}>, text:string, html:string, povinne:bool}
     */
    public static function uvedeni(array $u, string $predpona = 'Foto: ', bool $upraveno = false): array
    {
        $kod = isset($u['kod']) && self::platny($u['kod']) ? $u['kod'] : null;
        $casti = [];
        $autor = trim((string)($u['autor'] ?? ''));
        if ($autor !== '') $casti[] = ['druh' => 'autor', 'text' => $autor, 'url' => null];
        $zdroj = trim((string)($u['zdroj'] ?? ''));
        if ($zdroj !== '') $casti[] = ['druh' => 'zdroj', 'text' => $zdroj, 'url' => self::bezpecnyOdkaz($u['zdroj_url'] ?? null)];
        $nazev = self::nazev($kod, $u['verze'] ?? null);
        if ($nazev !== null) {
            if ($upraveno && in_array($kod, ['cc-by', 'cc-by-sa'], true)) $nazev .= ' (upraveno)';
            $casti[] = ['druh' => 'licence', 'text' => $nazev, 'url' => self::odkaz($kod, $u['verze'] ?? null, $u['licence_url'] ?? null)];
        }
        $text = $casti ? $predpona . implode(', ', array_column($casti, 'text')) : '';
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $html = $casti ? $h($predpona) . implode(', ', array_map(static fn(array $c): string => $c['url'] !== null
            ? '<a href="' . $h($c['url']) . '" target="_blank" rel="noopener' . ($c['druh'] === 'licence' ? ' license' : '') . '">' . $h($c['text']) . '</a>'
            : $h($c['text']), $casti)) : '';
        return ['casti' => $casti, 'text' => $text, 'html' => $html, 'povinne' => self::povinneUvedeni($kod)];
    }

    private static function bezpecnyOdkaz(?string $url): ?string
    {
        $url = trim((string)$url);
        return $url !== '' && mb_strlen($url) <= 500 && preg_match('~^https?://[^\s<>"]+$~i', $url) ? $url : null;
    }
}
