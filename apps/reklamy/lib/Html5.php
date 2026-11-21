<?php
declare(strict_types=1);
namespace Reklamy;

use ZipArchive;

/**
 * HTML5 bannery z balíčku ZIP (Google Web Designer, Animate, Sklik …).
 * Balíček se po kontrole uloží beze změny jako data/html5/<md5>.zip (zálohuje se) a rozbalí do data/html5/<md5>/
 * (rozbalená kopie se dá kdykoli znovu vytvořit). Vydává ho d/h5.php/<md5>/<cesta> s doplněným clickTag.
 */
final class Html5
{
    public const MAX_ZIP = 10 * 1024 * 1024;
    public const MAX_ROZBALENO = 40 * 1024 * 1024;
    public const MAX_SOUBORU = 500;
    /** Povolené přípony souborů v balíčku → typ obsahu. */
    public const TYPY = [
        'html' => 'text/html; charset=utf-8', 'htm' => 'text/html; charset=utf-8', 'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8', 'json' => 'application/json', 'txt' => 'text/plain; charset=utf-8',
        'xml' => 'application/xml', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav',
    ];
    /** Soubory, které balíčky z Macu a Windows obsahují navíc – přeskočí se. */
    private const SMETI = '~(^|/)(__MACOSX/|\.DS_Store$|Thumbs\.db$|desktop\.ini$|\._)~i';

    public function __construct(private string $slozka) {}

    public function slozka(): string { return $this->slozka; }

    /** Bezpečná relativní cesta uvnitř balíčku, nebo null. */
    public static function cesta(string $n): ?string
    {
        if ($n === '' || str_contains($n, "\0") || str_contains($n, '\\') || str_starts_with($n, '/') || preg_match('~^[a-z]:~i', $n)) return null;
        $casti = explode('/', $n);
        foreach ($casti as $c) if ($c === '..' || $c === '.' || $c === '') return null;
        if (!mb_check_encoding($n, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $n) || strlen($n) > 300) return null;
        return $n;
    }

    /**
     * Zkontroluje balíček a uloží ho. Vrací údaje pro banners.content.
     * @return array{zip:string, index:string, soubory:int, bajty:int, nazev:string, sirka:?int, vyska:?int}
     */
    public function nahraj(string $tmp, string $nazev): array
    {
        if (!is_file($tmp)) throw new Chyba('Soubor se nepodařilo nahrát.');
        $vel = (int)filesize($tmp);
        if ($vel > self::MAX_ZIP) throw new Chyba('Balíček HTML5 může mít nejvýš 10 MB.');
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new Chyba('Soubor není platný ZIP. Nahrajte balíček HTML5 (ZIP s index.html).');
        try {
            $polozky = $this->polozky($zip);
            $index = self::najdiIndex(array_keys($polozky));
            $html = (string)$zip->getFromName($polozky[$index]);
        } finally {
            $zip->close();
        }
        [$w, $h] = self::rozmerZHtml($html);
        $md5 = (string)md5_file($tmp);
        if (!is_dir($this->slozka)) @mkdir($this->slozka, 0775, true);
        $cil = "{$this->slozka}/$md5.zip";
        if (!is_file($cil) && !@copy($tmp, $cil)) throw new Chyba('Balíček se nepodařilo uložit do složky data/html5.');
        $this->rozbal($md5);
        return ['zip' => $md5, 'index' => $index, 'soubory' => count($polozky), 'bajty' => $this->velikost($md5),
            'nazev' => mb_substr(basename($nazev), 0, 200), 'sirka' => $w, 'vyska' => $h];
    }

    /** Projde balíček: [relativní cesta => název v ZIPu]. Chybu hlásí srozumitelně. @return array<string, string> */
    private function polozky(ZipArchive $zip): array
    {
        if ($zip->numFiles > self::MAX_SOUBORU * 2) throw new Chyba('Balíček obsahuje příliš mnoho souborů (nejvýš ' . self::MAX_SOUBORU . ').');
        $out = []; $celkem = 0; $spatne = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st === false) throw new Chyba('Balíček je poškozený.');
            $n = (string)$st['name'];
            if (str_ends_with($n, '/') || preg_match(self::SMETI, $n)) continue;
            $opsys = 0; $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                throw new Chyba('Balíček obsahuje symbolický odkaz („' . $n . '“) – takový balíček nelze použít.');
            }
            $c = self::cesta($n);
            if ($c === null) throw new Chyba('Balíček obsahuje nepovolenou cestu k souboru („' . mb_substr($n, 0, 80) . '“).');
            if (!isset(self::TYPY[strtolower(pathinfo($c, PATHINFO_EXTENSION))])) { $spatne[] = $c; continue; }
            $celkem += (int)$st['size'];
            if ($celkem > self::MAX_ROZBALENO) throw new Chyba('Rozbalený balíček by měl víc než 40 MB.');
            $out[$c] = $n;
        }
        if ($spatne) throw new Chyba('Balíček obsahuje nepovolené soubory: ' . implode(', ', array_slice($spatne, 0, 5)) . (count($spatne) > 5 ? ' a další' : '')
            . '. Povolené jsou HTML, JS, CSS, JSON, obrázky, písma, video a zvuk.');
        if (!$out) throw new Chyba('Balíček je prázdný.');
        if (count($out) > self::MAX_SOUBORU) throw new Chyba('Balíček obsahuje příliš mnoho souborů (nejvýš ' . self::MAX_SOUBORU . ').');
        return $out;
    }

    /** Hlavní soubor: index.html v kořeni nebo v jediné složce, jinak jediný soubor HTML. @param list<string> $cesty */
    public static function najdiIndex(array $cesty): string
    {
        $html = array_values(array_filter($cesty, fn($c) => preg_match('~\.html?$~i', $c)));
        if (!$html) throw new Chyba('Balíček neobsahuje soubor HTML (index.html).');
        $indexy = array_values(array_filter($html, fn($c) => preg_match('~(^|/)index\.html?$~i', $c)));
        usort($indexy, fn($a, $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
        if ($indexy && substr_count($indexy[0], '/') <= 1) return $indexy[0];
        if (count($html) === 1) return $html[0];
        throw new Chyba('V balíčku chybí index.html a souborů HTML je víc – nevím, který je banner.');
    }

    /** Rozměr z <meta name="ad.size" content="width=300,height=250">. @return array{0:?int,1:?int} */
    public static function rozmerZHtml(string $html): array
    {
        if (preg_match('~<meta[^>]+name\s*=\s*["\']ad\.size["\'][^>]*>~i', $html, $m)
            && preg_match('~width\s*=\s*(\d{1,4})~i', $m[0], $w) && preg_match('~height\s*=\s*(\d{1,4})~i', $m[0], $h)) {
            return [(int)$w[1], (int)$h[1]];
        }
        return [null, null];
    }

    /** Rozbalí balíček (když ještě rozbalený není). Vrací složku. */
    public function rozbal(string $md5): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $md5)) throw new Chyba('Neplatný balíček.');
        $dir = "{$this->slozka}/$md5";
        if (is_dir($dir)) return $dir;
        $zipSoubor = "$dir.zip";
        if (!is_file($zipSoubor)) throw new Chyba('Balíček HTML5 na disku chybí.');
        $zip = new ZipArchive();
        if ($zip->open($zipSoubor) !== true) throw new Chyba('Balíček HTML5 je poškozený.');
        $tmp = "$dir." . bin2hex(random_bytes(4)) . '.tmp';
        try {
            $celkem = 0;
            foreach ($this->polozky($zip) as $rel => $n) {
                $cil = "$tmp/$rel";
                if (!is_dir(dirname($cil))) @mkdir(dirname($cil), 0775, true);
                $in = $zip->getStream($n);
                $out = @fopen($cil, 'wb');
                if (!$in || !$out) throw new Chyba('Balíček se nepodařilo rozbalit.');
                while (!feof($in)) {
                    $kus = (string)fread($in, 65536);
                    $celkem += strlen($kus);
                    if ($celkem > self::MAX_ROZBALENO) throw new Chyba('Rozbalený balíček by měl víc než 40 MB.');
                    fwrite($out, $kus);
                }
                fclose($in); fclose($out);
            }
            if (!@rename($tmp, $dir) && !is_dir($dir)) throw new Chyba('Balíček se nepodařilo rozbalit.');
        } finally {
            $zip->close();
            if (is_dir($tmp)) self::smazSlozku($tmp);
        }
        return $dir;
    }

    private function velikost(string $md5): int
    {
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$this->slozka}/$md5", \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) $n += $f->getSize();
        return $n;
    }

    /** Soubor balíčku k vydání (rozbalí balíček, když je potřeba), nebo null. */
    public function soubor(string $md5, string $rel): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $md5) || self::cesta($rel) === null || !is_file("{$this->slozka}/$md5.zip")) return null;
        if (!isset(self::TYPY[strtolower(pathinfo($rel, PATHINFO_EXTENSION))])) return null;
        $p = $this->rozbal($md5) . '/' . $rel;
        return is_file($p) && !is_link($p) ? $p : null;
    }

    public static function typ(string $rel): string { return self::TYPY[strtolower(pathinfo($rel, PATHINFO_EXTENSION))] ?? 'application/octet-stream'; }

    /** Doplní do hlavního souboru clickTag (i clickTAG, clicktag) – proměnnou banner nepřepíše. */
    public static function sClickTagem(string $html, string $klik): string
    {
        $js = '<script>(function(u){["clickTag","clickTAG","clicktag"].forEach(function(n){try{Object.defineProperty(window,n,{get:function(){return u},set:function(){},configurable:false})}catch(e){window[n]=u}})})('
            . json_encode($klik, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $p) . $js . substr($html, $p);
        }
        if (preg_match('~<html\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $p = $m[0][1] + strlen($m[0][0]);
            return substr($html, 0, $p) . '<head>' . $js . '</head>' . substr($html, $p);
        }
        return $js . $html;
    }

    /** Balíčky, které bannery používají: [soubor.zip => md5] (pro zálohu). @param list<string> $md5 */
    public function soubory(array $md5): array
    {
        $out = [];
        foreach (array_unique($md5) as $m) if (preg_match('/^[a-f0-9]{32}$/', $m) && is_file("{$this->slozka}/$m.zip")) $out["$m.zip"] = $m;
        ksort($out);
        return $out;
    }

    /** Smaže balíčky, které už žádný banner nepoužívá. @param list<string> $pouzite */
    public function uklid(array $pouzite): int
    {
        $n = 0;
        foreach (glob("{$this->slozka}/*.zip") ?: [] as $z) {
            $m = basename($z, '.zip');
            if (in_array($m, $pouzite, true)) continue;
            @unlink($z);
            if (is_dir("{$this->slozka}/$m")) self::smazSlozku("{$this->slozka}/$m");
            $n++;
        }
        return $n;
    }

    private static function smazSlozku(string $d): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($d);
    }
}
