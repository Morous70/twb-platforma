<?php
declare(strict_types=1);
namespace Reklamy;

use TWB\Jadro\{Db, Host, Migrace, Nastaveni};
use TWB\Prihlaseni\{Heslo, Zamek, Relace, Tokeny, Google, Passkey};
use TWB\Galerie\Galerie;
use TWB\Zalohy\{Zalohy, Skupina};
use TWB\Aktualizace\Aktualizace;
use TWB\Pisma\Pisma;
use TWB\Slidy\{Navrh, Text};
use TWB\Data\{Tabulka, Promenne, ProSlidy, ProHtml, Vzdalene};
use TWB\Html\Dokument;

/**
 * Aplikace TWB Reklamy: konfigurace, databáze, moduly a služby aplikace na jednom místě.
 * Služby se vytvářejí až při prvním použití.
 */
final class App
{
    public const NAZEV = 'TWB Reklamy';
    public const SLUG = 'reklamy';
    /** Slug aplikace na TWB Update Serveru (liší se od interního SLUG, který jmenuje migrace). */
    public const UPDATE_SLUG = 'twb-reklamy';
    public const UPDATE_SERVER = 'https://updates.trafikabr.cz';
    /** Veřejný klíč Ed25519 TWB Update Serveru (veřejný, smí být v kódu). */
    public const UPDATE_KLIC = 'JTvTWDW+eSP/GtT9zG3t+3UraawTk82vKteb2Dci8Cg=';
    /** Tabulky v pořadí pro obnovu ze zálohy (nejdřív ty, na které ostatní odkazují). */
    public const TABULKY = ['module_versions', 'settings', 'log', 'media', 'passkeys', 'campaigns', 'banners', 'campaign_banners', 'sites', 'zones', 'zone_campaigns', 'zone_banners', 'stats', 'data_rows', 'sources', 'trackers', 'tracker_campaigns', 'clicks', 'views', 'conversions'];
    /** Nastavení, která patří k instalaci a obnova zálohy je nepřepíše. */
    public const MISTNI = ['login_fails', 'login_last_fail', 'update_result', 'update_checked_at', 'update_available', 'update_health_key'];

    public readonly string $verze;
    public readonly string $data;
    public readonly Db $db;
    public readonly Host $host;
    /** @var array<string, object> */
    private array $sluzby = [];
    /** @var array<string, mixed> */
    private array $relaceData = [];
    private bool $vlastniRelace = false;

    /** @param array<string, mixed> $cfg */
    public function __construct(public readonly string $adresar, public readonly array $cfg = [])
    {
        date_default_timezone_set((string)($cfg['timezone'] ?? 'Europe/Prague'));
        $this->verze = trim((string)@file_get_contents($adresar . '/VERSION')) ?: '0.0.0';
        $this->data = rtrim((string)($cfg['data_dir'] ?? $adresar . '/data'), '/');
        if (!is_dir($this->data)) @mkdir($this->data, 0775, true);
        $this->db = Db::pripoj('sqlite:' . $this->data . '/reklamy.sqlite');
        $this->db->pdo()->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;');
        Schema::zakladniTabulky($this->db);
        $this->host = new Host($this->db, new Nastaveni($this->db), new ProtokolDb($this->db), $this->data, $cfg);
    }

    /** Načte konfiguraci (soubor nemusí existovat – pak platí výchozí hodnoty) a vytvoří aplikaci. */
    public static function zaved(string $adresar, string $soubor): self
    {
        $cfg = is_file($soubor) ? require $soubor : [];
        if (!is_array($cfg)) throw new \RuntimeException('config.php musí vracet pole.');
        $app = new self($adresar, $cfg + ['__soubor' => $soubor]);
        if (!empty($cfg['debug'])) {
            error_reporting(E_ALL);
            set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
                if (!(error_reporting() & $no)) return false;
                throw new \ErrorException($str, 0, $no, $file, $line);
            });
        }
        return $app;
    }

    public function cfg(string $klic, mixed $vychozi = null): mixed { return $this->cfg[$klic] ?? $vychozi; }

    public function nastaveni(): Nastaveni { return $this->host->nastaveni; }

    public function log(string $uroven, string $zprava): void { $this->host->protokol->zapis($uroven, $zprava); }

    /** Veřejná adresa aplikace bez lomítka na konci. */
    public function url(string $cesta = ''): string
    {
        $zaklad = rtrim((string)$this->cfg('base_url', ''), '/');
        if ($zaklad === '' && isset($_SERVER['HTTP_HOST'])) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
            $zaklad = ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $dir;
        }
        return $zaklad . ($cesta !== '' ? '/' . ltrim($cesta, '/') : '');
    }

    /** Převede databázi na aktuální verzi (aplikace i moduly). Vrací true, když se něco změnilo. */
    public function migruj(): bool
    {
        $m = new Migrace($this->db);
        $zmena = false;
        $zmena = $m->spust('prihlaseni', \TWB\Prihlaseni\Migrace::class) || $zmena;
        $zmena = $m->spust('galerie', \TWB\Galerie\Migrace::class) || $zmena;
        $zmena = $m->spust('data', \TWB\Data\Migrace::class) || $zmena;
        $prvni = $m->verze(self::SLUG) === 0;
        // převod 5 přestavuje tabulku banners: s cizími klíči by DROP TABLE smazal přiřazení do zón a kampaní
        $pdo = $this->db->pdo();
        $vypnout = $m->verze(self::SLUG) < Schema::verze() && !$pdo->inTransaction();
        if ($vypnout) $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $zmena = $m->spust(self::SLUG, Schema::class) || $zmena;
        } finally {
            if ($vypnout) $pdo->exec('PRAGMA foreign_keys = ON');
        }
        if ($prvni) {
            // banner musí mít přesné rozměry – galerie obrázky sama nezmenšuje
            $this->nastaveni()->uloz('img_auto', '0');
        }
        if ((int)$this->nastaveni()->dej('schema_version', 0) !== Schema::verze()) $this->nastaveni()->uloz('schema_version', (string)Schema::verze());
        return $zmena;
    }

    public function nainstalovano(): bool
    {
        return (new Migrace($this->db))->verze(self::SLUG) > 0 && $this->heslo()->jeNastavene();
    }

    // ---------- služby ----------

    /** @template T of object @param class-string<T>|string $klic @param callable(): T $vytvor @return T */
    private function sluzba(string $klic, callable $vytvor): object { return $this->sluzby[$klic] ??= $vytvor(); }

    public function heslo(): Heslo { return $this->sluzba('heslo', fn() => new Heslo($this->nastaveni())); }
    public function zamek(): Zamek { return $this->sluzba('zamek', fn() => new Zamek($this->nastaveni(), $this->host->protokol)); }
    public function tokeny(): Tokeny { return $this->sluzba('tokeny', fn() => new Tokeny($this->db)); }

    /** Pro testy: relace nad vlastním polem místo $_SESSION. @param array<string, mixed> $pole */
    public function pouzijRelaci(array &$pole): void
    {
        $this->relaceData = &$pole;
        $this->vlastniRelace = true;
        unset($this->sluzby['relace']);
    }

    public function relace(): Relace
    {
        return $this->sluzba('relace', function () {
            if ($this->vlastniRelace) return new Relace($this->relaceData, $this->host->protokol, static function (): void {});
            Relace::spust('twb_reklamy');
            return new Relace($_SESSION, $this->host->protokol);
        });
    }

    public function limitNecinnosti(): int { return max(0, (int)$this->cfg('session_timeout_min', 120)) * 60; }

    public function google(): Google
    {
        return $this->sluzba('google', fn() => new Google((string)$this->cfg('google_client_id', ''), (string)$this->cfg('google_client_secret', ''),
            $this->url('google.php'), [Http::class, 'pozadavek']));
    }

    public function passkey(): Passkey
    {
        return $this->sluzba('passkey', fn() => new Passkey($this->db, $this->tokeny(), $this->url(), self::NAZEV, 'twb-reklamy', 'reklamy'));
    }

    public function galerie(): Galerie
    {
        return $this->sluzba('galerie', fn() => new Galerie($this->host, $this->data . '/media',
            povolene: array_intersect_key(Galerie::POVOLENE, array_flip(['jpg', 'jpeg', 'png', 'gif', 'webp'])),
            priSmazani: function (array $r): void {
                $n = (int)$this->db->q('SELECT COUNT(*) FROM banners WHERE media_id = ?', [$r['id']])->fetchColumn();
                if ($n > 0) throw new \RuntimeException("Obrázek „{$r['nazev']}“ používá banner ({$n}×), nejdřív ho z banneru odeberte.");
                $s = $this->slideBannery()->pouzivajiObrazek((int)$r['id']);
                if ($s) throw new \RuntimeException("Obrázek „{$r['nazev']}“ je ve slidu " . implode(', ', array_map(fn($x) => "„{$x}“", array_slice($s, 0, 3))) . ', nejdřív ho ze slidu odeberte.');
            },
            pouziti: fn(int $id) => [
                ...array_map(fn($b) => 'Banner: ' . $b['name'], $this->db->q('SELECT name FROM banners WHERE media_id = ?', [$id])->fetchAll()),
                ...array_map(fn($n) => 'Slide: ' . $n, $this->slideBannery()->pouzivajiObrazek($id)),
            ]));
    }

    public function zalohy(): Zalohy
    {
        return $this->sluzba('zalohy', fn() => new Zalohy($this->host, self::NAZEV, $this->verze, Schema::verze(), self::TABULKY,
            povinne: ['settings', 'campaigns', 'banners'], mistni: self::MISTNI,
            skupiny: [
                new Skupina('media', $this->data . '/media', fn() => $this->galerie()->soubory(), bezKomprese: true),
                new Skupina('fonts', $this->data . '/fonts', fn() => $this->pisma()->souboryProZalohu(), vzdy: true),
                new Skupina('html5', $this->data . '/html5', fn() => $this->html5()->soubory($this->bannery()->balickyHtml5()), bezKomprese: true),
            ],
            prefix: 'twb-reklamy-', kdeObnovit: 'Nastavení → Zálohy',
            poObnoveni: function (int $schema): void { $this->nastaveni()->obnov(); $this->migruj(); }));
    }

    public function aktualizace(): Aktualizace
    {
        return $this->sluzba('aktualizace', fn() => new Aktualizace($this->host, $this->zalohy(), self::UPDATE_SLUG, self::NAZEV, $this->verze, $this->adresar,
            (string)$this->cfg('update_public_key', self::UPDATE_KLIC), 'reklamy/', $this->url(), (string)$this->cfg('update_token', ''),
            server: (string)$this->cfg('update_server', self::UPDATE_SERVER), zachovat: ['config.php', 'data'],
            povinne: ['index.php', 'health.php', 'lib/bootstrap.php'], predponaZip: 'twb-reklamy-'));
    }

    public function dorucovani(): Dorucovani { return $this->sluzba('dorucovani', fn() => new Dorucovani($this)); }
    public function vykresleni(): Vykresleni { return $this->sluzba('vykresleni', fn() => new Vykresleni($this, $this->dorucovani())); }

    /** Pro soubory doručování: převod databáze jen tehdy, když nesedí verze (bez zbytečné práce u každého zobrazení). */
    public function pripravDoruceni(): bool
    {
        if ((int)$this->nastaveni()->dej('schema_version', 0) !== Schema::verze()) $this->migruj();
        return (new Migrace($this->db))->verze(self::SLUG) > 0;
    }

    // ---------- obsah banerů: písma, proměnné, slidy, HTML ----------

    /** Složka modulů platformy (v balíčku modules/ vedle aplikace, při vývoji ../../modules). */
    public static function moduly(): string { return dirname((new \ReflectionClass(Pisma::class))->getFileName(), 3); }

    /** Nápověda (Markdown ve složce help/, modul napoveda). */
    public function napoveda(): \TWB\Napoveda\Napoveda { return $this->sluzba('napoveda', fn() => new \TWB\Napoveda\Napoveda($this->adresar . '/help')); }

    public function pisma(): Pisma
    {
        return $this->sluzba('pisma', fn() => new Pisma($this->nastaveni(), $this->host->protokol, self::moduly() . '/pisma/fonty', $this->data . '/fonts',
            pouziti: fn(string $k) => $this->slideBannery()->pouzivajiPismo($k),
            urlVlastni: 'api.php?a=pismo&klic={klic}', urlVestavene: 'api.php?a=pismo&soubor={soubor}&tloustka={tloustka}'));
    }

    public function tabulkaDat(): Tabulka { return $this->sluzba('tabulkaDat', fn() => new Tabulka($this->db)); }

    /** Vlastní proměnné: [název malými písmeny => [name, type (text|list), value]]. @return array<string, array<string, string>> */
    public function vlastniPromenne(): array
    {
        $v = json_decode((string)$this->nastaveni()->dej('user_vars', '{}'), true);
        return is_array($v) ? $v : [];
    }

    /**
     * Externí data: vzdálené zdroje JSON / CSV (modul data, tabulka sources). Cron je stahuje podle intervalu,
     * v textech banerů se čtou jako {Zdroj:cesta}. Konfigurace `zdroje_http` (callable) nahradí stahování – jen pro testy.
     */
    public function zdroje(): Vzdalene
    {
        return $this->sluzba('zdroje', fn() => new Vzdalene($this->db, $this->host->protokol, self::NAZEV . ' ' . $this->verze,
            is_callable($this->cfg['zdroje_http'] ?? null) ? $this->cfg['zdroje_http'] : null,
            is_callable($this->cfg['zdroje_cas'] ?? null) ? $this->cfg['zdroje_cas'] : null));
    }

    public function promenne(): Promenne
    {
        return $this->sluzba('promenne', fn() => new Promenne($this->tabulkaDat(), fn() => $this->vlastniPromenne(), $this->zdroje()));
    }

    public function navrh(): Navrh
    {
        return $this->sluzba('navrh', fn() => new Navrh($this->pisma(), SlideBannery::formaty(), '300x250', vlastniRozmer: true, min: 16, max: Bannery::MAX_ROZMER));
    }

    public function slidy(): \TWB\Slidy\Vykresleni
    {
        return $this->sluzba('slidy', fn() => new \TWB\Slidy\Vykresleni($this->navrh(), new Text($this->pisma()), new ObrazkyGalerie($this->galerie()), new ProSlidy($this->promenne())));
    }

    public function slideBannery(): SlideBannery { return $this->sluzba('slideBannery', fn() => new SlideBannery($this)); }
    public function html5(): Html5 { return $this->sluzba('html5', fn() => new Html5($this->data . '/html5')); }
    public function dokument(): Dokument { return $this->sluzba('dokument', fn() => new Dokument($this->pisma(), new ProHtml($this->promenne()))); }

    public function konverze(): Konverze { return $this->sluzba('konverze', fn() => new Konverze($this)); }
    public function statistiky(): Statistiky { return $this->sluzba('statistiky', fn() => new Statistiky($this)); }
    public function kampane(): Kampane { return $this->sluzba('kampane', fn() => new Kampane($this)); }
    public function bannery(): Bannery { return $this->sluzba('bannery', fn() => new Bannery($this)); }
    public function weby(): Weby { return $this->sluzba('weby', fn() => new Weby($this)); }
    public function zony(): Zony { return $this->sluzba('zony', fn() => new Zony($this)); }
}
