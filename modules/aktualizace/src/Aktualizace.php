<?php
declare(strict_types=1);
namespace TWB\Aktualizace;

use TWB\Jadro\Host;
use TWB\Zalohy\Zalohy;
use RuntimeException;

/**
 * Aktualizace z TWB Update Serveru.
 * Kontrola: latest.php → podpis Ed25519 (veřejný klíč napevno v aplikaci) → novější verze se nabídne.
 * Instalace jen po potvrzení: stažení → SHA-256 → rozbalení do přípravné složky → záloha databáze a kopie současných souborů
 * → přepsání (kromě zachovaných) → kontrola zdraví (provede i převod databáze) → při chybě návrat k předchozí verzi.
 *
 * Nastavení, která modul používá: update_result, update_checked_at, update_available, update_health_key, backup_password.
 */
final class Aktualizace
{
    public const MAX_VELIKOST = 100 * 1048576;
    private string $slozka;
    private string $server;
    /** @var callable(string, ?string): array{code:int, body:string} */
    private $http;
    /** @var callable(string): array{code:int, body:string, error:string} */
    private $zdravi;
    /** @var callable(): int */
    private $cas;

    /**
     * @param string       $app          slug aplikace na Update Serveru (např. „media-planner“)
     * @param string       $adresar      kořen nainstalované aplikace
     * @param string       $koren        složka uvnitř ZIPu, včetně lomítka (např. „media-planner/“)
     * @param list<string> $zachovat     cesty od kořene aplikace, které se nikdy nepřepisují ani nemažou
     * @param list<string> $povinne      soubory, které musí balíček obsahovat (kromě VERSION)
     * @param string       $zakladniUrl  veřejná adresa aplikace (pro kontrolu zdraví: <url>/health.php?key=…)
     * @param string       $predponaZip  předpona názvu staženého ZIPu
     * @param bool         $vyzadujToken false = režim public_signed
     * @param ?callable    $http         function(string $url, ?string $ulozitDo): array{code,body} – pro testy
     * @param ?callable    $zdravi       function(string $url): array{code,body,error} – pro testy
     */
    public function __construct(
        private Host $host,
        private Zalohy $zalohy,
        private string $app,
        private string $nazevAplikace,
        private string $verze,
        private string $adresar,
        private string $verejnyKlic,
        private string $koren,
        private string $zakladniUrl,
        private string $token = '',
        string $server = 'https://updates.trafikabr.cz',
        private array $zachovat = ['config.php', 'data'],
        private array $povinne = ['index.php'],
        private string $predponaZip = 'twb-aplikace-',
        private bool $vyzadujToken = true,
        ?callable $http = null,
        ?callable $zdravi = null,
        ?callable $cas = null,
        ?string $slozka = null,
    ) {
        if (!preg_match('/^[a-z0-9-]+$/', $app)) throw new \InvalidArgumentException('Neplatný slug aplikace.');
        if (!str_ends_with($koren, '/')) throw new \InvalidArgumentException('Složka v ZIPu musí končit lomítkem.');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $verze)) throw new \InvalidArgumentException('Neplatná verze aplikace.');
        $this->adresar = rtrim($adresar, '/');
        $this->server = rtrim($server, '/');
        $this->slozka = $slozka ?? $host->datovaSlozka . '/update';
        $this->http = $http ?? [new Curl($token, $nazevAplikace . '/' . $verze, self::MAX_VELIKOST), 'stahni'];
        $this->zdravi = $zdravi ?? [Curl::class, 'zdravi'];
        $this->cas = $cas ?? 'time';
    }

    public static function maskuj(string $t): string { return $t === '' ? '' : substr($t, 0, 4) . str_repeat('•', 8) . substr($t, -4); }

    /** Proč aktualizace nejde (null = jde). */
    public function nepodporovano(): ?string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) return 'PHP na serveru nemá rozšíření sodium (ověření podpisu).';
        if (!class_exists('ZipArchive')) return 'PHP na serveru nemá rozšíření zip.';
        if (!function_exists('curl_init') && !$this->vlastniHttp()) return 'PHP na serveru nemá rozšíření curl.';
        if ($this->vyzadujToken && trim($this->token) === '') return 'V config.php chybí update_token (token upd_… z TWB Update Serveru).';
        return null;
    }

    private function vlastniHttp(): bool { return !(is_array($this->http) && ($this->http[0] ?? null) instanceof Curl); }

    /** Soubory a složky aplikace, do kterých PHP nemůže zapisovat (prázdné = vše v pořádku). @return list<string> */
    public function nezapisovatelne(): array
    {
        $spatne = [];
        if (!is_writable($this->adresar)) $spatne[] = '/';
        $it = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->adresar, \FilesystemIterator::SKIP_DOTS),
            fn($f) => !in_array($this->rel($f->getPathname()), $this->zachovat, true)), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($it as $f) {
            if (!is_writable($f->getPathname())) $spatne[] = $this->rel($f->getPathname()) . ($f->isDir() ? '/' : '');
            if (count($spatne) >= 10) break;
        }
        return $spatne;
    }

    private function rel(string $cesta): string { return ltrim(substr($cesta, strlen($this->adresar)), '/'); }

    private function zapis(string $vysledek, string $zprava, string $uroven = 'info'): void
    {
        $this->host->nastaveni->uloz('update_result', json_encode(['result' => $vysledek, 'message' => $zprava, 'at' => ($this->cas)()], JSON_UNESCAPED_UNICODE));
        $this->host->protokol->zapis($uroven, "Aktualizace ($vysledek): $zprava");
    }

    /** Zjistí novou verzi. Vrací stav pro zobrazení. */
    public function zkontroluj(): array
    {
        $n = $this->host->nastaveni;
        if ($proc = $this->nepodporovano()) { $this->zapis('config_error', $proc, 'error'); return $this->stav(); }
        $n->uloz('update_checked_at', (string)($this->cas)());
        try {
            $r = ($this->http)($this->server . '/api/v1/latest.php?app=' . rawurlencode($this->app), null);
            if ($r['code'] === 401 || $r['code'] === 403) { $this->zapis('auth_error', 'Server odmítl token (HTTP ' . $r['code'] . '). Zkontrolujte update_token v config.php.', 'error'); return $this->stav(); }
            if ($r['code'] === 404) { $n->uloz('update_available', null); $this->zapis('no_release', 'Aplikace na serveru nemá vydání (HTTP 404).'); return $this->stav(); }
            if ($r['code'] !== 200) { $this->zapis('server_error', "Update Server vrátil HTTP {$r['code']}. Zůstává současná verze.", 'error'); return $this->stav(); }
            $rel = Manifest::over($r['body'], $this->app, $this->verejnyKlic, self::MAX_VELIKOST);
            if (!$rel || version_compare($rel['version'], $this->verze, '<=')) {
                $n->uloz('update_available', null);
                $this->zapis($rel ? 'ok' : 'no_release', $rel ? "Aplikace je aktuální (server: {$rel['version']})." : 'Na serveru není žádné vydání.');
            } else {
                $bylo = json_decode((string)$n->dej('update_available', ''), true);
                $n->uloz('update_available', json_encode($rel));
                $this->zapis('ok', "Je k dispozici verze {$rel['version']}.", ($bylo['version'] ?? '') === $rel['version'] ? 'info' : 'success');
            }
        } catch (RuntimeException $e) {
            $this->zapis($e->getCode() === 1 ? 'signature_error' : 'server_error', $e->getMessage(), 'error');
        }
        return $this->stav();
    }

    /** Volá cron: kontrola jednou za 24 hodin. */
    public function kontrolaDojde(): void
    {
        if ($this->nepodporovano()) return;
        if (($this->cas)() - (int)$this->host->nastaveni->dej('update_checked_at', 0) < 86400) return;
        $this->zkontroluj();
    }

    public function stav(): array
    {
        $n = $this->host->nastaveni;
        $nabidka = json_decode((string)$n->dej('update_available', ''), true);
        if ($nabidka && version_compare($nabidka['version'], $this->verze, '<=')) $nabidka = null;   // mezitím nainstalováno
        $predchozi = is_file($this->slozka . '/previous/VERSION') ? trim((string)file_get_contents($this->slozka . '/previous/VERSION')) : null;
        return [
            'version' => $this->verze, 'app' => $this->app, 'server' => $this->server,
            'token' => self::maskuj($this->token), 'unsupported' => $this->nepodporovano(),
            'checked_at' => (int)$n->dej('update_checked_at', 0) ?: null,
            'available' => $nabidka ?: null,
            'result' => json_decode((string)$n->dej('update_result', ''), true),
            'previous' => $predchozi,
        ];
    }

    // ---------- instalace ----------

    /**
     * Přepíše aplikaci obsahem složky $zdroj. Smaže jen soubory, které nainstalovala předchozí aktualizace
     * a v nové verzi už nejsou (cizí soubory, např. .user.ini od hostingu, nechá být).
     */
    private function pouzij(string $zdroj): void
    {
        $manifest = $this->slozka . '/installed-files.json';
        $nove = Balicek::soubory($zdroj, $this->zachovat);
        foreach ($nove as $rel) Balicek::zapis($this->adresar . "/$rel", "$zdroj/$rel");
        $stare = json_decode((string)@file_get_contents($manifest), true) ?: [];
        $sada = array_flip($nove);
        foreach ($stare as $rel) {
            if (is_string($rel) && !isset($sada[$rel]) && !Balicek::zachovana($rel, $this->zachovat) && !preg_match('~(^|/)\.\.(/|$)~', $rel)) @unlink($this->adresar . "/$rel");
        }
        file_put_contents($manifest, json_encode($nove, JSON_UNESCAPED_SLASHES));
        if (function_exists('opcache_reset')) @opcache_reset();
        clearstatcache(true);
    }

    /** Nová verze odpovídá? (health.php běží už s novým kódem a provede převod databáze.) */
    private function zdravi(string $ocekavana): ?string
    {
        $klic = bin2hex(random_bytes(16));
        $this->host->nastaveni->uloz('update_health_key', $klic);
        $url = rtrim($this->zakladniUrl, '/') . '/health.php?key=' . $klic;
        $err = null;
        for ($pokus = 0; $pokus < 3; $pokus++) {
            $r = ($this->zdravi)($url);
            $j = json_decode($r['body'], true);
            if ($r['code'] === 200 && ($j['ok'] ?? false) && ($j['version'] ?? '') === $ocekavana) { $this->host->nastaveni->uloz('update_health_key', null); return null; }
            $err = $r['error'] !== '' ? 'kontrola nové verze neodpověděla: ' . $r['error']
                : "kontrola nové verze vrátila HTTP {$r['code']}: " . mb_substr(trim(strip_tags($r['body'])), 0, 300);
            usleep(700000);
        }
        $this->host->nastaveni->uloz('update_health_key', null);
        return $err;
    }

    /**
     * Nainstaluje nabízenou verzi. $verze musí odpovídat nabídce (ochrana před dvojím kliknutím).
     * @return array{ok:bool,message:string,version:string}
     */
    public function instaluj(string $verze): array
    {
        $n = $this->host->nastaveni;
        if ($proc = $this->nepodporovano()) throw new RuntimeException($proc);
        $rel = json_decode((string)$n->dej('update_available', ''), true);
        if (!$rel || $rel['version'] !== $verze) throw new RuntimeException('Tato verze už není k dispozici. Zjistěte novou verzi znovu.');
        if (version_compare($verze, $this->verze, '<=')) throw new RuntimeException("Verze $verze není novější než nainstalovaná {$this->verze}.");
        if ($spatne = $this->nezapisovatelne()) throw new RuntimeException('PHP nemůže zapisovat do souborů aplikace: ' . implode(', ', $spatne) . '. Aktualizujte ručně přes FTP.');
        if ((string)$n->dej('backup_password', '') === '' || $this->zalohy->podporovano()) throw new RuntimeException('Před aktualizací je potřeba záloha databáze – nastavte heslo záloh (Nastavení → Zálohy).');

        @set_time_limit(900);
        ignore_user_abort(true);
        @mkdir($this->slozka, 0775, true);
        $zamek = fopen($this->slozka . '/install.lock', 'c');
        if (!flock($zamek, LOCK_EX | LOCK_NB)) throw new RuntimeException('Aktualizace už probíhá.');
        $zip = $this->slozka . "/{$this->predponaZip}$verze.zip";
        $stage = $this->slozka . '/staging';
        $prev = $this->slozka . '/previous';
        $stara = trim((string)@file_get_contents($this->adresar . '/VERSION')) ?: $this->verze;
        $staraSchema = (int)$n->dej('schema_version', 1);
        $pouzito = false;
        try {
            // 1) stažení a kontrola
            $this->host->protokol->zapis('info', "Aktualizace na $verze: stahuji balíček.");
            @unlink($zip);
            $r = ($this->http)($this->server . $rel['download_path'], $zip);
            if ($r['code'] !== 200) {
                @unlink($zip);
                $this->zapis($r['code'] === 401 || $r['code'] === 403 ? 'auth_error' : 'install_error', "Stažení balíčku $verze selhalo (HTTP {$r['code']}).", 'error');
                throw new ChybaZapsana("Stažení balíčku selhalo (HTTP {$r['code']}).");
            }
            clearstatcache(true, $zip);
            if (filesize($zip) !== $rel['size'] || !hash_equals($rel['sha256'], (string)hash_file('sha256', $zip))) {
                @unlink($zip);
                $this->zapis('hash_error', "Kontrolní součet staženého balíčku $verze nesouhlasí – nic se neinstaluje.", 'error');
                throw new ChybaZapsana('Kontrolní součet staženého balíčku nesouhlasí – nic se neinstaluje.');
            }
            // 2) rozbalení do přípravné složky
            Balicek::smaz($stage);
            @mkdir($stage, 0775, true);
            $soubory = Balicek::rozbal($zip, $stage, $this->koren, $this->zachovat);
            $v = is_file("$stage/VERSION") ? trim((string)file_get_contents("$stage/VERSION")) : '';
            if ($v !== $verze) throw new RuntimeException("Balíček obsahuje verzi „{$v}“ místo $verze.");
            foreach ($this->povinne as $potreba) {
                if (!in_array($potreba, $soubory, true)) throw new RuntimeException("V balíčku chybí $potreba.");
            }
            // 3) záloha databáze a kopie současné verze
            $zaloha = $this->zalohy->vytvor('pred-aktualizaci');
            Balicek::smaz($prev);
            @mkdir($prev, 0775, true);
            foreach (Balicek::soubory($this->adresar, $this->zachovat) as $f) Balicek::zapis("$prev/$f", $this->adresar . "/$f");
            file_put_contents("$prev/.update.json", json_encode(['version' => $stara, 'schema' => $staraSchema, 'backup' => $zaloha['name'], 'at' => ($this->cas)()]));
            // 4) přepsání a kontrola
            $this->host->protokol->zapis('info', "Aktualizace na $verze: přepisuji soubory (" . count($soubory) . ').');
            $pouzito = true;
            $this->pouzij($stage);
            if ($err = $this->zdravi($verze)) throw new RuntimeException("Nová verze nefunguje ($err).");
            Balicek::smaz($stage);
            @unlink($zip);
            $n->uloz('update_available', null);
            $this->zapis('ok', "Nainstalována verze $verze (předchozí $stara je uložená pro návrat).", 'success');
            return ['ok' => true, 'version' => $verze, 'message' => "Nainstalována verze $verze."];
        } catch (\Throwable $e) {
            $zprava = $e->getMessage();
            if ($pouzito) {
                try {
                    $this->vratPredchozi();
                    $this->zapis('rollback', "Aktualizace na $verze selhala: $zprava Vrácena verze $stara.", 'error');
                    $zprava .= " Vrácena předchozí verze $stara.";
                } catch (\Throwable $e2) {
                    $this->zapis('install_error', "Aktualizace na $verze selhala a návrat také: {$e2->getMessage()} Nahrajte aplikaci ručně přes FTP.", 'error');
                    $zprava .= ' Návrat se nepodařil: ' . $e2->getMessage() . ' Nahrajte aplikaci ručně přes FTP.';
                }
            } elseif (!$e instanceof ChybaZapsana) {
                $this->zapis('install_error', "Aktualizace na $verze selhala: $zprava", 'error');
            }
            Balicek::smaz($stage);
            return ['ok' => false, 'version' => $stara, 'message' => $zprava];
        } finally {
            flock($zamek, LOCK_UN);
            fclose($zamek);
        }
    }

    /**
     * Vrátí uloženou předchozí verzi (soubory). Databáze se vrátí ze zálohy před aktualizací jen tehdy,
     * když ji nová verze převedla na vyšší verzi dat (stará aplikace by jí nerozuměla).
     * @return array{version:string,db_restored:bool}
     */
    public function vratPredchozi(): array
    {
        $n = $this->host->nastaveni;
        $prev = $this->slozka . '/previous';
        $meta = json_decode((string)@file_get_contents("$prev/.update.json"), true);
        if (!$meta || !is_file("$prev/VERSION")) throw new RuntimeException('Předchozí verze není uložená.');
        if ($spatne = $this->nezapisovatelne()) throw new RuntimeException('PHP nemůže zapisovat do souborů aplikace: ' . implode(', ', $spatne) . '.');
        @set_time_limit(600);
        $this->pouzij($prev);
        $n->obnov();
        $dbVracena = false;
        if ((int)$n->dej('schema_version', 1) > (int)$meta['schema'] && !empty($meta['backup'])) {
            try { $cesta = $this->zalohy->cesta((string)$meta['backup']); } catch (RuntimeException) { $cesta = null; }
            if ($cesta !== null) { $this->zalohy->obnov($cesta, (string)$n->dej('backup_password')); $dbVracena = true; }
        }
        Balicek::smaz($prev);   // uložená verze je teď nainstalovaná
        $this->host->protokol->zapis('info', "Vrácena verze {$meta['version']}" . ($dbVracena ? " včetně databáze ze zálohy {$meta['backup']}" : '') . '.');
        return ['version' => $meta['version'], 'db_restored' => $dbVracena];
    }
}
