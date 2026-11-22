<?php
declare(strict_types=1);

/**
 * Sestavení balíčků aplikace pro TWB Update Server.
 *
 * Aplikace: apps/<app>/ s VERSION, modules.lock a (volitelně) build.json.
 * Balíček: ZIP s jedinou kořenovou složkou (build.json → "koren", výchozí „<app>/“):
 *   – soubory aplikace (bez config.php, *.sqlite, logů),
 *   – modules/<modul>/… přesně ty moduly a verze, které jmenuje modules.lock (bez testů),
 *   – BUILD.json (verze, moduly, SHA-256 každého souboru).
 * FULL = vše (první instalace přes FTP). UPDATE = totéž bez souborů, které se při aktualizaci nepřepisují
 * (install.php, config.sample.php, složka data/ …); modul `aktualizace` stejně sahá jen na to, co balíček obsahuje.
 */
final class Sestavovac
{
    /** Soubory, které nepatří do žádného balíčku. */
    private const NIKDY = ['.gitkeep', '.DS_Store', 'Thumbs.db'];
    private const NIKDY_PRIPONY = ['sqlite', 'sqlite-wal', 'sqlite-shm', 'log', 'zip'];
    /** Soubory modulu, které se do balíčku nedávají. */
    private const MODUL_VYNECHAT = ['tests', 'docs', '.git'];

    /** @var callable(): int */
    private $cas;

    public function __construct(private string $koren, ?callable $cas = null, private bool $lint = false)
    {
        $this->koren = rtrim($koren, '/');
        $this->cas = $cas ?? 'time';
    }

    /** @return array{app:string,slozka:string,verze:string,korenZip:string,jenFull:list<string>,povinne:list<string>,lock:array<string,string>} */
    public function nacti(string $app): array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $app)) throw new RuntimeException("Neplatný název aplikace „{$app}“.");
        $slozka = "{$this->koren}/apps/$app";
        if (!is_dir($slozka)) throw new RuntimeException("Aplikace $app neexistuje (chybí $slozka).");
        $verze = trim((string)@file_get_contents("$slozka/VERSION"));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $verze)) throw new RuntimeException("Soubor VERSION aplikace $app musí obsahovat verzi ve tvaru 1.2.3.");
        $cfg = [];
        if (is_file("$slozka/build.json")) {
            $cfg = json_decode((string)file_get_contents("$slozka/build.json"), true);
            if (!is_array($cfg)) throw new RuntimeException("build.json aplikace $app není platný JSON.");
        }
        $korenZip = (string)($cfg['koren'] ?? "$app/");
        if (!preg_match('~^[A-Za-z0-9._-]+/$~', $korenZip)) throw new RuntimeException('Složka v ZIPu („koren“) musí být jeden název zakončený lomítkem.');
        $lock = [];
        if (is_file("$slozka/modules.lock")) {
            $j = json_decode((string)file_get_contents("$slozka/modules.lock"), true);
            if (!is_array($j) || !is_array($j['moduly'] ?? null)) throw new RuntimeException("modules.lock aplikace $app není platný (čekám {\"moduly\": {…}}).");
            foreach ($j['moduly'] as $m => $v) {
                if (!is_string($m) || !preg_match('/^[a-z0-9]+$/', $m) || !is_string($v) || !preg_match('/^\d+\.\d+\.\d+$/', $v)) throw new RuntimeException("modules.lock: neplatný záznam „" . (is_string($m) ? $m : '?') . '“.');
            }
            $lock = $j['moduly'];
            ksort($lock);
        }
        return [
            'app' => $app, 'slozka' => $slozka, 'verze' => $verze, 'korenZip' => $korenZip, 'lock' => $lock,
            'jenFull' => array_values($cfg['jen_full'] ?? ['install.php', 'config.sample.php', 'data']),
            'povinne' => array_values($cfg['povinne'] ?? ['index.php']),
        ];
    }

    /** @return array{name:string,version:string,requires:array<string,string>} */
    public function modul(string $nazev): array
    {
        $soubor = "{$this->koren}/modules/$nazev/module.json";
        if (!is_file($soubor)) throw new RuntimeException("Modul $nazev neexistuje.");
        $j = json_decode((string)file_get_contents($soubor), true);
        if (!is_array($j) || ($j['name'] ?? null) !== $nazev || !preg_match('/^\d+\.\d+\.\d+$/', (string)($j['version'] ?? ''))) {
            throw new RuntimeException("module.json modulu $nazev je neplatný.");
        }
        return ['name' => $nazev, 'version' => $j['version'], 'requires' => is_array($j['requires'] ?? null) ? $j['requires'] : []];
    }

    /** Splňuje verze podmínku? Podporuje „>=1.2.3“, „^1.2.3“ (stejná hlavní verze, ne nižší) a přesnou verzi. */
    public static function splnuje(string $verze, string $podminka): bool
    {
        $podminka = trim($podminka);
        if (preg_match('/^>=\s*(\d+\.\d+\.\d+)$/', $podminka, $m)) return version_compare($verze, $m[1], '>=');
        if (preg_match('/^\^\s*(\d+)\.(\d+\.\d+)$/', $podminka, $m)) return version_compare($verze, "{$m[1]}.{$m[2]}", '>=') && (int)explode('.', $verze)[0] === (int)$m[1];
        if (preg_match('/^\d+\.\d+\.\d+$/', $podminka)) return $verze === $podminka;
        throw new RuntimeException("Neznámá podmínka verze „{$podminka}“.");
    }

    /**
     * Zkontroluje modules.lock: moduly existují, verze v zámku = verze v module.json, závislosti splněné uvnitř zámku.
     * @return list<string> chyby (prázdné = v pořádku)
     */
    public function over(string $app): array
    {
        $a = $this->nacti($app);
        $chyby = [];
        $verze = [];
        foreach ($a['lock'] as $nazev => $v) {
            try {
                $m = $this->modul($nazev);
            } catch (RuntimeException $e) { $chyby[] = $e->getMessage(); continue; }
            $verze[$nazev] = $m['version'];
            if ($m['version'] !== $v) $chyby[] = "modules.lock: $nazev má v zámku $v, ale modul je ve verzi {$m['version']} (zkontrolujte změny a spusťte sestavit.php $app --zamek).";
        }
        foreach ($a['lock'] as $nazev => $v) {
            if (!isset($verze[$nazev])) continue;
            foreach ($this->modul($nazev)['requires'] as $potreba => $podminka) {
                if (!isset($a['lock'][$potreba])) { $chyby[] = "Modul $nazev vyžaduje modul $potreba, který není v modules.lock."; continue; }
                if (!self::splnuje($a['lock'][$potreba], (string)$podminka)) $chyby[] = "Modul $nazev vyžaduje $potreba $podminka, v zámku je {$a['lock'][$potreba]}.";
            }
        }
        return $chyby;
    }

    /** Přepíše modules.lock podle verzí z module.json (vědomé potvrzení nových verzí). */
    public function aktualizujZamek(string $app): void
    {
        $a = $this->nacti($app);
        $novy = [];
        foreach (array_keys($a['lock']) as $nazev) $novy[$nazev] = $this->modul($nazev)['version'];
        file_put_contents("{$a['slozka']}/modules.lock", json_encode(['app' => $app, 'moduly' => $novy], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Soubory balíčku: relativní cesta v balíčku => absolutní cesta na disku.
     * @return array<string,string>
     */
    public function soubory(string $app, bool $plny): array
    {
        $a = $this->nacti($app);
        $out = [];
        $jenFull = static function (string $rel) use ($a): bool {
            foreach ($a['jenFull'] as $k) if ($rel === $k || str_starts_with($rel, "$k/")) return true;
            return false;
        };
        foreach ($this->projdi($a['slozka']) as $rel => $abs) {
            if (!$plny && $jenFull($rel)) continue;
            if (!self::povolen($rel)) continue;
            $out[$rel] = $abs;
        }
        foreach (array_keys($a['lock']) as $nazev) {
            $zaklad = "{$this->koren}/modules/$nazev";
            foreach ($this->projdi($zaklad) as $rel => $abs) {
                if (in_array(explode('/', $rel)[0], self::MODUL_VYNECHAT, true) || !self::povolen($rel)) continue;
                $out["modules/$nazev/$rel"] = $abs;
            }
        }
        // soubory, které vznikají až při sestavení, nesmí být v zdrojích
        unset($out['BUILD.json']);
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function povolen(string $rel): bool
    {
        $nazev = basename($rel);
        if ($rel === 'config.php' || in_array($nazev, self::NIKDY, true)) return false;
        $pripona = strtolower(pathinfo($nazev, PATHINFO_EXTENSION));
        if (in_array($pripona, self::NIKDY_PRIPONY, true)) return false;
        // runtime obsah složky data (média, dočasné soubory) do balíčku nepatří; zůstane jen .htaccess
        if (preg_match('~^data/(media|tmp|update|backups|weather-icons)/~', $rel)) return false;
        return true;
    }

    /** @return array<string,string> relativní => absolutní, bez odkazů */
    private function projdi(string $slozka): array
    {
        $out = [];
        if (!is_dir($slozka)) return $out;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($slozka, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
        foreach ($it as $f) {
            if ($f->isLink()) throw new RuntimeException('Symbolický odkaz v balíčku není povolen: ' . $f->getPathname());
            if (!$f->isFile()) continue;
            $rel = ltrim(substr($f->getPathname(), strlen($slozka)), '/');
            $out[$rel] = $f->getPathname();
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Sestaví FULL a UPDATE balíček do složky $cil.
     * @return array{full:array{soubor:string,sha256:string,size:int,souboru:int},update:array{soubor:string,sha256:string,size:int,souboru:int},verze:string}
     */
    public function sestav(string $app, string $cil): array
    {
        $a = $this->nacti($app);
        if ($chyby = $this->over($app)) throw new RuntimeException("Balíček nelze sestavit:\n- " . implode("\n- ", $chyby));
        $out = ['verze' => $a['verze']];
        foreach (['full' => true, 'update' => false] as $druh => $plny) {
            $soubory = $this->soubory($app, $plny);
            foreach ($a['povinne'] as $p) if (!isset($soubory[$p])) throw new RuntimeException("V balíčku chybí povinný soubor $p.");
            if ($this->lint) $this->zkontrolujPhp($soubory);
            if (($soubory['VERSION'] ?? null) === null) throw new RuntimeException('V balíčku chybí VERSION.');
            @mkdir($cil, 0775, true);
            $zip = $cil . '/' . self::nazevBalicku($app, $a['verze'], $druh);
            $out[$druh] = $this->zapis($zip, $a, $soubory, $druh);
        }
        return $out;
    }

    /** Název ZIPu: twb-<aplikace>-<verze>-<druh>.zip; aplikace se jménem twb-… předponu nezdvojí (twb-galerie → twb-galerie-0.4.0-full.zip). */
    public static function nazevBalicku(string $app, string $verze, string $druh): string
    {
        return (str_starts_with($app, 'twb-') ? $app : "twb-$app") . "-$verze-$druh.zip";
    }

    /** @param array<string,string> $soubory */
    private function zapis(string $zip, array $a, array $soubory, string $druh): array
    {
        $cas = ($this->cas)();
        $hash = [];
        foreach ($soubory as $rel => $abs) $hash[$rel] = hash_file('sha256', $abs);
        $build = json_encode([
            'app' => $a['app'], 'verze' => $a['verze'], 'druh' => $druh, 'moduly' => $a['lock'],
            'sestaveno' => gmdate('c', $cas), 'soubory' => $hash,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @unlink($zip);
        $z = new ZipArchive();
        if ($z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException("Nelze vytvořit $zip.");
        $pridej = function (string $rel, ?string $abs, ?string $obsah) use ($z, $a, $cas): void {
            $jm = $a['korenZip'] . $rel;
            $ok = $abs !== null ? $z->addFile($abs, $jm) : $z->addFromString($jm, (string)$obsah);
            if (!$ok) throw new RuntimeException("Nelze přidat $rel do balíčku.");
            $i = $z->count() - 1;
            $z->setMtimeIndex($i, $cas);
            $z->setExternalAttributesIndex($i, ZipArchive::OPSYS_UNIX, 0100644 << 16);
            $z->setCompressionIndex($i, ZipArchive::CM_DEFLATE);
        };
        foreach ($soubory as $rel => $abs) $pridej($rel, $abs, null);
        $pridej('BUILD.json', null, $build);
        if (!$z->close()) throw new RuntimeException("Nelze dokončit $zip.");
        return ['soubor' => $zip, 'sha256' => hash_file('sha256', $zip), 'size' => filesize($zip), 'souboru' => count($soubory) + 1];
    }

    /** @param array<string,string> $soubory */
    private function zkontrolujPhp(array $soubory): void
    {
        $chyby = [];
        foreach ($soubory as $rel => $abs) {
            if (!str_ends_with($rel, '.php')) continue;
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($abs) . ' 2>&1', $vystup, $kod);
            if ($kod !== 0) $chyby[] = "$rel: " . trim(implode(' ', $vystup));
            $vystup = [];
        }
        if ($chyby) throw new RuntimeException("Chyba syntaxe PHP:\n- " . implode("\n- ", $chyby));
    }
}
