<?php
declare(strict_types=1);
namespace TWB\Aktualizace;

use RuntimeException;
use ZipArchive;

/** Práce se soubory balíčku: bezpečné rozbalení (Zip Slip, odkazy), výpis souborů, zápis a mazání. */
final class Balicek
{
    /** Je cesta (relativní k aplikaci) mezi zachovanými soubory? */
    public static function zachovana(string $rel, array $zachovat): bool
    {
        foreach ($zachovat as $k) if ($rel === $k || str_starts_with($rel, "$k/")) return true;
        return false;
    }

    /**
     * Rozbalí ZIP (soubory uvnitř složky $koren) do $cil. Odmítne nebezpečné cesty a symbolické odkazy.
     * Zachované soubory (config.php, data/ …) přeskočí. Vrací seznam rozbalených souborů.
     * @return list<string>
     */
    public static function rozbal(string $zipCesta, string $cil, string $koren, array $zachovat): array
    {
        $z = new ZipArchive();
        if ($z->open($zipCesta) !== true) throw new RuntimeException('Stažený soubor není platný ZIP.');
        $soubory = [];
        try {
            for ($i = 0; $i < $z->numFiles; $i++) {
                $st = $z->statIndex($i);
                $jmeno = $st['name'];
                if ($jmeno === $koren || $jmeno === rtrim($koren, '/')) continue;
                if (!str_starts_with($jmeno, $koren)) throw new RuntimeException("Balíček obsahuje soubor mimo složku $koren: $jmeno");
                $rel = substr($jmeno, strlen($koren));
                if ($rel === '' || str_contains($rel, "\0") || str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.(/|$)~', $rel) || preg_match('~^[A-Za-z]:~', $rel)) {
                    throw new RuntimeException("Balíček obsahuje nepovolenou cestu: $jmeno");
                }
                if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    throw new RuntimeException("Balíček obsahuje symbolický odkaz: $jmeno");
                }
                if (str_ends_with($rel, '/')) continue;
                if (self::zachovana($rel, $zachovat)) continue;
                $kam = "$cil/$rel";
                @mkdir(dirname($kam), 0775, true);
                $in = $z->getStream($jmeno);
                $out = fopen($kam, 'wb');
                if (!$in || !$out) throw new RuntimeException("Nelze rozbalit $jmeno.");
                stream_copy_to_stream($in, $out);
                fclose($in); fclose($out);
                if (filesize($kam) !== $st['size']) throw new RuntimeException("Soubor $jmeno se nerozbalil celý.");
                $soubory[] = $rel;
            }
        } finally {
            $z->close();
        }
        return $soubory;
    }

    /** Soubory složky (relativní cesty) bez zachovaných. @return list<string> */
    public static function soubory(string $koren, array $zachovat): array
    {
        $out = [];
        if (!is_dir($koren)) return $out;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($koren, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = ltrim(substr($f->getPathname(), strlen($koren)), '/');
            if (!self::zachovana($rel, $zachovat) && $f->isFile()) $out[] = $rel;
        }
        sort($out);
        return $out;
    }

    /** Zapíše soubor bezpečně (dočasný soubor + přejmenování). */
    public static function zapis(string $cil, string $zdroj): void
    {
        @mkdir(dirname($cil), 0775, true);
        $tmp = $cil . '.upd-' . bin2hex(random_bytes(3));
        if (!@copy($zdroj, $tmp) || !@rename($tmp, $cil)) { @unlink($tmp); throw new RuntimeException('Nelze zapsat soubor ' . basename($cil) . '.'); }
        // OPcache by jinak mohl dál spouštět starou verzi souboru (stejný čas změny v rámci jedné sekundy)
        if (function_exists('opcache_invalidate') && str_ends_with($cil, '.php')) @opcache_invalidate($cil, true);
    }

    public static function smaz(string $cesta): void
    {
        if (is_link($cesta) || is_file($cesta)) { @unlink($cesta); return; }
        if (!is_dir($cesta)) return;
        foreach (scandir($cesta) ?: [] as $f) if ($f !== '.' && $f !== '..') self::smaz("$cesta/$f");
        @rmdir($cesta);
    }
}
