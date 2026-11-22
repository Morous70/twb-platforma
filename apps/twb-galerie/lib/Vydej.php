<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Vydání souboru prohlížeči: ETag, podmíněný požadavek (304), rozsahy bajtů (přetáčení videa) a bezpečné hlavičky.
 * Soubor se nikdy nespouští: Content-Type je z databáze, s nosniff a CSP (sandbox kromě PDF).
 */
final class Vydej
{
    /**
     * @param string $soubor     cesta na disku
     * @param string $mime       Content-Type
     * @param string $nazev      název pro Content-Disposition
     * @param string $etag       např. md5 obsahu
     * @param string $cache      hodnota Cache-Control
     * @param bool   $stahnout   attachment místo inline
     * @param array<string, string> $navic další hlavičky
     */
    public static function posli(string $soubor, string $mime, string $nazev, string $etag, string $cache, bool $stahnout = false, array $navic = []): never
    {
        $velikost = (int)filesize($soubor);
        $etag = '"' . preg_replace('/[^A-Za-z0-9._-]/', '', $etag) . '"';
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        // PDF prohlížeč v Chrome v režimu sandbox nefunguje; PDF zobrazuje vestavěný prohlížeč, který skripty dokumentu nespouští
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'" . ($mime === 'application/pdf' ? '' : '; sandbox'));
        header('Accept-Ranges: bytes');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int)filemtime($soubor)) . ' GMT');
        header('Cache-Control: ' . $cache);
        header('Content-Disposition: ' . ($stahnout ? 'attachment' : 'inline') . '; filename="' . self::ascii($nazev) . '"; filename*=UTF-8\'\'' . rawurlencode($nazev));
        foreach ($navic as $k => $v) header("$k: $v");

        $kdyz = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($kdyz !== '' && in_array($etag, array_map('trim', explode(',', $kdyz)), true)) {
            http_response_code(304);
            exit;
        }
        $od = 0; $do = $velikost - 1;
        $rozsah = (string)($_SERVER['HTTP_RANGE'] ?? '');
        $ifRange = (string)($_SERVER['HTTP_IF_RANGE'] ?? '');
        if ($rozsah !== '' && ($ifRange === '' || $ifRange === $etag)) {
            $r = self::rozsah($rozsah, $velikost);
            if ($r === null) {
                http_response_code(416);
                header("Content-Range: bytes */$velikost");
                exit;
            }
            [$od, $do] = $r;
            http_response_code(206);
            header("Content-Range: bytes $od-$do/$velikost");
        }
        header('Content-Length: ' . ($do - $od + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD' || $velikost === 0) exit;
        while (ob_get_level() > 0) ob_end_clean();
        $fh = fopen($soubor, 'rb');
        if ($fh === false) exit;
        fseek($fh, $od);
        $zbyva = $do - $od + 1;
        while ($zbyva > 0 && !feof($fh) && !connection_aborted()) {
            $kus = fread($fh, min(1048576, $zbyva));
            if ($kus === false || $kus === '') break;
            echo $kus;
            $zbyva -= strlen($kus);
            flush();
        }
        fclose($fh);
        exit;
    }

    /** Jeden rozsah „bytes=a-b“, „bytes=a-“ nebo „bytes=-n“. Víc rozsahů = celý soubor od prvního. @return ?array{0:int,1:int} */
    public static function rozsah(string $hlavicka, int $velikost): ?array
    {
        if (!preg_match('/^bytes=\s*(\d*)\s*-\s*(\d*)/', $hlavicka, $m) || ($m[1] === '' && $m[2] === '') || $velikost <= 0) return null;
        if ($m[1] === '') {
            $n = min((int)$m[2], $velikost);
            return $n > 0 ? [$velikost - $n, $velikost - 1] : null;
        }
        $od = (int)$m[1];
        $do = $m[2] === '' ? $velikost - 1 : min((int)$m[2], $velikost - 1);
        return $od <= $do && $od < $velikost ? [$od, $do] : null;
    }

    private static function ascii(string $s): string
    {
        $s = (string)preg_replace('/[^\x20-\x7E]/', '_', $s);
        return str_replace(['"', '\\'], '_', $s);
    }
}
