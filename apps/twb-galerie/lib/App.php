<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Jadro\{Db, Host, Migrace, Nastaveni};
use TWB\Prihlaseni\{Heslo, Zamek, Relace, Tokeny, Google, Passkey, Obnova, Posta};
use TWB\Galerie\Galerie;
use TWB\Zalohy\{Zalohy, Skupina};
use TWB\Aktualizace\Aktualizace;

/**
 * Aplikace TWB Media galerie: konfigurace, databáze, moduly a služby aplikace na jednom místě.
 * Služby se vytvářejí až při prvním použití.
 */
final class App
{
    public const NAZEV = 'TWB Media galerie';
    /** Interní slug (migrace v module_versions). */
    public const SLUG = 'twb-galerie';
    /** Slug aplikace na TWB Update Serveru. */
    public const UPDATE_SLUG = 'twb-galerie';
    public const UPDATE_SERVER = 'https://updates.trafikabr.cz';
    /** Veřejný klíč Ed25519 TWB Update Serveru (veřejný, smí být v kódu). */
    public const UPDATE_KLIC = 'JTvTWDW+eSP/GtT9zG3t+3UraawTk82vKteb2Dci8Cg=';
    /** Tabulky v pořadí pro obnovu ze zálohy. Passkey a jednorázové tokeny se nezálohují (obnova by vrátila odebrané zařízení). */
    public const TABULKY = ['module_versions', 'settings', 'log', 'media'];
    /** Nastavení, která patří k instalaci a obnova zálohy je nepřepíše. */
    public const MISTNI = ['login_fails', 'login_last_fail', 'reset_last_sent', 'update_result', 'update_checked_at', 'update_available', 'update_health_key'];
    /** Výchozí odhlášení po nečinnosti v minutách (když není v nastavení ani v config.php). */
    public const NECINNOST_MIN = 120;

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
        $this->db = Db::pripoj('sqlite:' . $this->data . '/galerie.sqlite');
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

    /** Veřejná adresa aplikace bez lomítka na konci (z config.php; hlavička Host jen když base_url chybí). */
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
        $zmena = $m->spust(self::SLUG, Schema::class) || $zmena;
        if ((int)$this->nastaveni()->dej('schema_version', 0) !== Schema::verze()) $this->nastaveni()->uloz('schema_version', (string)Schema::verze());
        return $zmena;
    }

    public function nainstalovano(): bool
    {
        return (new Migrace($this->db))->verze(self::SLUG) > 0 && $this->heslo()->jeNastavene();
    }

    // ---------- služby ----------

    /** @template T of object @param callable(): T $vytvor @return T */
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
            Relace::spust('twb_galerie');
            return new Relace($_SESSION, $this->host->protokol);
        });
    }

    /** Odhlášení po nečinnosti v minutách: Nastavení → Zabezpečení, jinak config.php (session_timeout_min). 0 = neodhlašovat. */
    public function necinnostMinut(): int
    {
        $v = $this->nastaveni()->dej('idle_minutes', null);
        return max(0, (int)($v ?? $this->cfg('session_timeout_min', self::NECINNOST_MIN)));
    }

    public function limitNecinnosti(): int { return $this->necinnostMinut() * 60; }

    public function google(): Google
    {
        return $this->sluzba('google', fn() => new Google((string)$this->cfg('google_client_id', ''), (string)$this->cfg('google_client_secret', ''),
            $this->url('google.php'), [Http::class, 'pozadavek']));
    }

    public function passkey(): Passkey
    {
        return $this->sluzba('passkey', fn() => new Passkey($this->db, $this->tokeny(), $this->url(), self::NAZEV, 'twb-galerie', 'galerie'));
    }

    /** E-mail, na který smí přijít odkaz pro obnovu hesla (Nastavení → Zabezpečení). */
    public function emailObnovy(): string { return trim((string)$this->nastaveni()->dej('reset_email', '')); }

    public function posta(): Posta
    {
        $test = $this->cfg('mail_to_file');
        return $this->sluzba('posta', fn() => new Posta((string)$this->cfg('mail_from', 'galerie@kazbo.eu'), self::NAZEV,
            is_string($test) && $test !== '' ? $test : null));
    }

    public function obnova(): Obnova
    {
        return $this->sluzba('obnova', fn() => new Obnova($this->nastaveni(), $this->tokeny(), $this->heslo(), $this->zamek(), $this->host->protokol,
            fn(string $komu, string $predmet, string $text) => $this->posta()->posli($komu, $predmet, $text),
            fn() => $this->emailObnovy(), $this->url(), self::NAZEV, '{zaklad}/index.php?reset={token}'));
    }

    public function galerie(): Galerie
    {
        return $this->sluzba('galerie', fn() => new Galerie($this->host, $this->data . '/media'));
    }

    public function zalohy(): Zalohy
    {
        return $this->sluzba('zalohy', fn() => new Zalohy($this->host, self::NAZEV, $this->verze, Schema::verze(), self::TABULKY,
            povinne: ['settings', 'media'], mistni: self::MISTNI,
            skupiny: [new Skupina('media', $this->data . '/media', fn() => $this->galerie()->soubory(), bezKomprese: true)],
            prefix: 'twb-galerie-', kdeObnovit: 'Nastavení → Zálohy',
            poObnoveni: function (int $schema): void { $this->nastaveni()->obnov(); $this->migruj(); }));
    }

    public function aktualizace(): Aktualizace
    {
        return $this->sluzba('aktualizace', fn() => new Aktualizace($this->host, $this->zalohy(), self::UPDATE_SLUG, self::NAZEV, $this->verze, $this->adresar,
            (string)$this->cfg('update_public_key', self::UPDATE_KLIC), 'twb-galerie/', $this->url(), (string)$this->cfg('update_token', ''),
            server: (string)$this->cfg('update_server', self::UPDATE_SERVER), zachovat: ['config.php', 'data'],
            povinne: ['index.php', 'health.php', 'lib/bootstrap.php'], predponaZip: 'twb-galerie-'));
    }

    /** Nápověda (Markdown ve složce help/, modul napoveda). */
    public function napoveda(): \TWB\Napoveda\Napoveda { return $this->sluzba('napoveda', fn() => new \TWB\Napoveda\Napoveda($this->adresar . '/help')); }
}
