<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Popisné údaje uložené v obrázku: název, štítky, autor a licence.
 * Zdroje v pořadí důležitosti: XMP (Lightroom, Photoshop, Bridge, darktable…), IPTC (starší programy a fotobanky), EXIF (fotoaparát, Windows).
 * Bez knihoven: XMP se najde jako textový blok <x:xmpmeta> v souboru (JPEG, PNG, WebP, GIF, TIFF), IPTC přes getimagesize/iptcparse,
 * EXIF přes exif_read_data (když ho PHP má).
 */
final class Metadata
{
    /** XMP bývá na začátku souboru; dál se nehledá (paměť, čas). */
    public const XMP_MAX_BAJTU = 4 * 1048576;

    private const NS = [
        'x' => 'adobe:ns:meta/',
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'xmpRights' => 'http://ns.adobe.com/xap/1.0/rights/',
        'photoshop' => 'http://ns.adobe.com/photoshop/1.0/',
        'cc' => 'http://creativecommons.org/ns#',
        'plus' => 'http://ns.useplus.org/ldf/xmp/1.0/',
    ];

    /**
     * @return array{nazev:?string, tagy:list<string>, autor:?string, licence:?string, licence_url:?string}
     */
    public static function cti(string $soubor): array
    {
        $xmp = self::xmp($soubor);
        $iptc = self::iptc($soubor);
        $exif = self::exif($soubor);
        $prvni = static function (?string ...$h): ?string {
            foreach ($h as $v) {
                $v = self::cisty($v);
                if ($v !== null) return $v;
            }
            return null;
        };
        $tagy = [];
        foreach ([$xmp['tagy'], $iptc['tagy'], $exif['tagy']] as $zdroj) {
            foreach ($zdroj as $t) {
                $t = self::cisty($t);
                if ($t === null) continue;
                $k = mb_strtolower($t);
                if (!isset($tagy[$k])) $tagy[$k] = $t;
            }
        }
        $url = self::cisty($xmp['licence_url']);
        if ($url !== null && !preg_match('~^https?://~i', $url)) $url = null;
        return [
            'nazev' => $prvni($xmp['nazev'], $iptc['nazev'], $xmp['titulek'], $iptc['titulek'], $exif['nazev']),
            'tagy' => array_values($tagy),
            'autor' => $prvni($xmp['autor'], $iptc['autor'], $exif['autor']),
            'licence' => $prvni($xmp['licence'], $xmp['prava'], $iptc['licence'], $exif['licence']),
            'licence_url' => $url,
        ];
    }

    /** Text bez řídicích znaků a zbytečných mezer; prázdný → null. */
    private static function cisty(?string $v): ?string
    {
        if ($v === null) return null;
        if (!mb_check_encoding($v, 'UTF-8')) $v = self::zKodovani($v);
        $v = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
        $v = (string)preg_replace('/\s{2,}/u', ' ', $v);
        return $v === '' ? null : $v;
    }

    /** Starší IPTC a EXIF bývají ve Windows-1250 (české programy) – převede se, co není platné UTF-8. */
    private static function zKodovani(string $v): string
    {
        $u = function_exists('iconv') ? @iconv('Windows-1250', 'UTF-8//IGNORE', $v) : false;
        return $u !== false ? $u : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    // ---------- XMP ----------

    /** @return array{nazev:?string, titulek:?string, tagy:list<string>, autor:?string, licence:?string, prava:?string, licence_url:?string} */
    private static function xmp(string $soubor): array
    {
        $out = ['nazev' => null, 'titulek' => null, 'tagy' => [], 'autor' => null, 'licence' => null, 'prava' => null, 'licence_url' => null];
        $data = @file_get_contents($soubor, false, null, 0, self::XMP_MAX_BAJTU);
        if ($data === false) return $out;
        $od = strpos($data, '<x:xmpmeta');
        $do = $od === false ? false : strpos($data, '</x:xmpmeta>', $od);
        if ($od === false || $do === false) return $out;
        $xml = substr($data, $od, $do - $od + 12);
        if (!class_exists(\DOMDocument::class)) return $out;
        $dom = new \DOMDocument();
        // bez načítání externích entit a sítě (XXE)
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) || $dom->doctype !== null) return $out;
        $xp = new \DOMXPath($dom);
        foreach (self::NS as $p => $ns) $xp->registerNamespace($p, $ns);

        $out['nazev'] = self::alt($xp, 'dc:title');
        $out['titulek'] = self::hodnota($xp, 'photoshop:Headline');
        $out['tagy'] = self::seznam($xp, 'dc:subject');
        $autori = self::seznam($xp, 'dc:creator');
        $out['autor'] = $autori ? implode(', ', $autori) : null;
        $out['licence'] = self::alt($xp, 'xmpRights:UsageTerms');
        $out['prava'] = self::alt($xp, 'dc:rights');
        $out['licence_url'] = self::hodnota($xp, 'xmpRights:WebStatement') ?? self::hodnota($xp, 'cc:license');
        return $out;
    }

    /** Vlastnost s jazykovými variantami (rdf:Alt): přednost x-default, pak první. */
    private static function alt(\DOMXPath $xp, string $vlastnost): ?string
    {
        $li = $xp->query("//rdf:Description/$vlastnost/rdf:Alt/rdf:li[@xml:lang='x-default']");
        if ($li && $li->length) return $li->item(0)->textContent;
        $li = $xp->query("//rdf:Description/$vlastnost/rdf:Alt/rdf:li");
        if ($li && $li->length) return $li->item(0)->textContent;
        return self::hodnota($xp, $vlastnost);
    }

    /** Seznam (rdf:Bag / rdf:Seq) nebo jedna hodnota. @return list<string> */
    private static function seznam(\DOMXPath $xp, string $vlastnost): array
    {
        $out = [];
        foreach ($xp->query("//rdf:Description/$vlastnost/*/rdf:li") ?: [] as $li) $out[] = $li->textContent;
        if (!$out && ($v = self::hodnota($xp, $vlastnost)) !== null) $out = array_map('trim', explode(',', $v));
        return $out;
    }

    /** Jednoduchá hodnota: element s textem, rdf:resource, nebo atribut na rdf:Description (zkrácený zápis XMP). */
    private static function hodnota(\DOMXPath $xp, string $vlastnost): ?string
    {
        $el = $xp->query("//rdf:Description/$vlastnost");
        if ($el && $el->length) {
            $n = $el->item(0);
            if ($n instanceof \DOMElement && $n->hasAttributeNS(self::NS['rdf'], 'resource')) return $n->getAttributeNS(self::NS['rdf'], 'resource');
            if ($n->childNodes->length === 1 && $n->firstChild instanceof \DOMText) return $n->textContent;
        }
        [$p, $jmeno] = explode(':', $vlastnost);
        $at = $xp->query("//rdf:Description/@$p:$jmeno");
        return $at && $at->length ? $at->item(0)->nodeValue : null;
    }

    // ---------- IPTC ----------

    /** @return array{nazev:?string, titulek:?string, tagy:list<string>, autor:?string, licence:?string} */
    private static function iptc(string $soubor): array
    {
        $out = ['nazev' => null, 'titulek' => null, 'tagy' => [], 'autor' => null, 'licence' => null];
        $info = [];
        if (!@getimagesize($soubor, $info) || empty($info['APP13']) || !function_exists('iptcparse')) return $out;
        $i = @iptcparse($info['APP13']);
        if (!is_array($i)) return $out;
        // 1:90 = znaková sada; ESC % G znamená UTF-8, jinak se odhadne
        $utf8 = isset($i['1#090'][0]) && str_contains($i['1#090'][0], "\x1B%G");
        $t = static fn(?string $v) => $v === null ? null : ($utf8 || mb_check_encoding($v, 'UTF-8') ? $v : self::zKodovani($v));
        $out['nazev'] = $t($i['2#005'][0] ?? null);
        $out['titulek'] = $t($i['2#105'][0] ?? null);
        $out['tagy'] = array_map(fn($v) => (string)$t($v), $i['2#025'] ?? []);
        $out['autor'] = isset($i['2#080']) ? implode(', ', array_map(fn($v) => (string)$t($v), $i['2#080'])) : null;
        $out['licence'] = $t($i['2#116'][0] ?? null);
        return $out;
    }

    // ---------- EXIF ----------

    /** @return array{nazev:?string, tagy:list<string>, autor:?string, licence:?string} */
    private static function exif(string $soubor): array
    {
        $out = ['nazev' => null, 'tagy' => [], 'autor' => null, 'licence' => null];
        if (!function_exists('exif_read_data')) return $out;
        $typ = @exif_imagetype($soubor);
        if (!in_array($typ, [IMAGETYPE_JPEG, IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM], true)) return $out;
        // XP… (Windows: Vlastnosti → Podrobnosti) jsou v UTF-16LE; PHP je převede podle exif.encode_unicode (výchozí ISO-8859-15 by ztratil češtinu)
        $puvodni = ini_get('exif.encode_unicode');
        @ini_set('exif.encode_unicode', 'UTF-8');
        $e = @exif_read_data($soubor, 'IFD0');
        if ($puvodni !== false) @ini_set('exif.encode_unicode', $puvodni);
        if (!is_array($e)) return $out;
        $xp = static function ($v): ?string {
            if (!is_string($v) || $v === '') return null;
            if (str_contains(rtrim($v, "\0"), "\0")) $v = (string)@mb_convert_encoding($v, 'UTF-8', 'UTF-16LE');
            return rtrim($v, "\0");
        };
        $out['nazev'] = $xp($e['Title'] ?? $e['XPTitle'] ?? null);
        $klic = $xp($e['Keywords'] ?? $e['XPKeywords'] ?? null);
        $out['tagy'] = $klic !== null ? array_map('trim', explode(';', $klic)) : [];
        $artist = isset($e['Artist']) && is_string($e['Artist']) ? rtrim($e['Artist'], "\0 ") : null;
        $out['autor'] = $artist !== '' && $artist !== null ? $artist : $xp($e['Author'] ?? $e['XPAuthor'] ?? null);
        // Copyright může mít dvě části oddělené nulou (fotograf, editor)
        $c = isset($e['Copyright']) && is_string($e['Copyright']) ? trim(str_replace("\0", ' ', $e['Copyright'])) : '';
        $out['licence'] = $c !== '' ? $c : null;
        return $out;
    }
}
