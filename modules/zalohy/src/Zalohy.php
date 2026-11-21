<?php
declare(strict_types=1);
namespace TWB\Zalohy;

use TWB\Jadro\{Db, Host};
use ZipArchive;
use RuntimeException;

/**
 * Zálohy: šifrovaný ZIP (AES-256) s databází (database.json) a soubory.
 * Skupiny souborů s příznakem „vzdy=false“ (galerie) se přidají jen při změně nebo když žádná záloha se soubory není.
 * Zůstává posledních $uchovat záloh a vždy nejnovější záloha se soubory.
 *
 * Nastavení, která modul čte/zapisuje: backup_password, backup_auto, last_backup_at, backup_media_hash, schema_version.
 */
final class Zalohy
{
    private const SKUPINA_ZIP = '/^[a-z0-9_]+$/';
    private string $slozka;
    private string $tmp;
    /** @var callable */
    private $cas;
    /** @var ?callable */
    private $poObnoveni;

    /**
     * @param list<string>  $tabulky   tabulky v pořadí pro obnovení (nejdřív ty, na které ostatní odkazují)
     * @param list<string>  $povinne   tabulky, které musí záloha obsahovat (jinak „poškozená“)
     * @param list<string>  $mistni    nastavení patřící k instalaci – obnovením se nepřepisují
     * @param list<Skupina> $skupiny
     * @param ?callable     $poObnoveni function(int $schemaZalohy): void – převod databáze na aktuální verzi apod.
     * @param ?callable     $cas       function(): int – čas (pro testy)
     */
    public function __construct(
        private Host $host,
        private string $nazevAplikace,
        private string $verzeAplikace,
        private int $verzeSchematu,
        private array $tabulky,
        private array $povinne = ['settings'],
        private array $mistni = [],
        private array $skupiny = [],
        private string $prefix = 'twb-zaloha-',
        private string $kdeObnovit = 'Nastavení → Zálohy',
        ?callable $poObnoveni = null,
        ?callable $cas = null,
        private int $uchovat = 7,
        private int $hodina = 3,
        ?string $slozka = null,
    ) {
        if (!preg_match('/^[a-z0-9-]+$/', $prefix)) throw new \InvalidArgumentException('Neplatná předpona záloh.');
        foreach ($tabulky as $t) Db::identifikator($t);
        foreach ($skupiny as $s) if (!preg_match(self::SKUPINA_ZIP, $s->id)) throw new \InvalidArgumentException("Neplatné ID skupiny souborů „{$s->id}“.");
        $this->slozka = $slozka ?? $host->datovaSlozka . '/backups';
        $this->tmp = $host->datovaSlozka . '/tmp';
        $this->cas = $cas ?? 'time';
        $this->poObnoveni = $poObnoveni;
        $this->mistni = array_values(array_unique(array_merge($mistni, ['backup_media_hash', 'last_backup_at'])));
    }

    public function podporovano(): ?string
    {
        if (!class_exists('ZipArchive')) return 'PHP na serveru nemá rozšíření zip.';
        if (!method_exists('ZipArchive', 'setEncryptionName') || !defined('ZipArchive::EM_AES_256')) return 'PHP na serveru neumí šifrovat ZIP (AES). Požádejte hosting o novější knihovnu libzip.';
        return null;
    }

    public function slozka(): string
    {
        if (!is_dir($this->slozka)) @mkdir($this->slozka, 0775, true);
        return $this->slozka;
    }

    /** Seznam záloh (nejnovější první). */
    public function seznam(): array
    {
        $out = [];
        foreach (glob($this->slozka() . '/' . $this->prefix . '*.zip') ?: [] as $p) {
            $n = basename($p);
            $out[] = ['name' => $n, 'size' => filesize($p), 'time' => filemtime($p),
                      'files' => str_contains($n, '-soubory'), 'before_restore' => str_contains($n, '-pred-obnovou'), 'uploaded' => str_contains($n, '-nahrana')];
        }
        usort($out, fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
        return $out;
    }

    /** Cesta k záloze podle názvu (ověří tvar názvu, ne cestu). */
    public function cesta(string $nazev): string
    {
        if (!preg_match('/^' . preg_quote($this->prefix, '/') . '[a-z0-9-]+\.zip$/', $nazev) || !is_file($this->slozka() . "/$nazev")) {
            throw new RuntimeException('Záloha nenalezena.');
        }
        return $this->slozka() . "/$nazev";
    }

    /**
     * Vytvoří zálohu. $druh: '' (běžná), 'pred-obnovou', 'rucni'.
     * @return array{name:string,size:int,files:bool}
     */
    public function vytvor(string $druh = '', bool $vynutitSoubory = false): array
    {
        if ($err = $this->podporovano()) throw new RuntimeException($err);
        if ($druh !== '' && !preg_match('/^[a-z0-9-]+$/', $druh)) throw new \InvalidArgumentException('Neplatný druh zálohy.');
        $pass = (string)$this->host->nastaveni->dej('backup_password', '');
        if ($pass === '') throw new RuntimeException('Nejdřív nastavte heslo záloh (' . $this->kdeObnovit . ').');
        @set_time_limit(300);
        $cas = ($this->cas)();

        $soubory = [];
        foreach ($this->skupiny as $s) $soubory[$s->id] = $s->soubory();
        $hash = md5(json_encode(array_map(fn($s) => $soubory[$s->id], array_filter($this->skupiny, fn($s) => !$s->vzdy))));
        $mameSoubory = (bool)array_filter($this->seznam(), fn($b) => $b['files']);
        $sSoubory = $vynutitSoubory || $hash !== $this->host->nastaveni->dej('backup_media_hash') || !$mameSoubory;

        $db = ['app' => $this->nazevAplikace, 'app_version' => $this->verzeAplikace, 'schema_version' => (int)$this->host->nastaveni->dej('schema_version', 1),
               'created_at' => date('c', $cas), 'with_files' => $sSoubory, 'tables' => []];
        foreach ($this->skupiny as $s) $db[$s->klicJson()] = $soubory[$s->id];
        foreach ($this->tabulky as $t) {
            try { $db['tables'][$t] = $this->host->db->q('SELECT * FROM ' . Db::identifikator($t))->fetchAll(); } catch (\Throwable) { continue; }   // tabulka ještě neexistuje
        }
        $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) throw new RuntimeException('Databázi se nepodařilo převést do zálohy.');

        $zaklad = $this->prefix . date('Y-m-d-His', $cas) . ($druh !== '' ? "-$druh" : '') . ($sSoubory ? '-soubory' : '');
        $nazev = "$zaklad.zip";
        for ($i = 2; is_file($this->slozka() . "/$nazev"); $i++) $nazev = "$zaklad-v$i.zip";
        $tmp = $this->slozka() . "/.$nazev.part";
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Nelze vytvořit soubor zálohy.');
        $zip->setPassword($pass);
        $pocet = array_sum(array_map(fn($s) => $s->vzdy ? 0 : count($soubory[$s->id]), $this->skupiny));
        $zip->addFromString('ZALOHA.txt', $this->nazevAplikace . " – záloha\nVytvořeno: " . date('j. n. Y H:i', $cas) . "\nVerze aplikace: " . $this->verzeAplikace . ', databáze: ' . $db['schema_version']
            . "\nSoubory: " . ($sSoubory ? "ano ($pocet)" : 'ne – jsou v poslední záloze „…-soubory.zip“') . "\n\n"
            . 'Obnovení: ' . $this->nazevAplikace . ' → ' . $this->kdeObnovit . " → Obnovit ze souboru.\nZIP je šifrovaný (AES-256) – otevřete ho např. programem 7-Zip.\n");
        $zip->addFromString('database.json', $json);
        $zip->setEncryptionName('database.json', ZipArchive::EM_AES_256, $pass);
        foreach ($this->skupiny as $s) {
            if (!$s->vzdy && !$sSoubory) continue;
            foreach ($soubory[$s->id] as $f => $md5) {
                $zip->addFile($s->slozka . "/$f", "{$s->id}/$f");
                $zip->setEncryptionName("{$s->id}/$f", ZipArchive::EM_AES_256, $pass);
                if ($s->bezKomprese) $zip->setCompressionName("{$s->id}/$f", ZipArchive::CM_STORE);
            }
        }
        if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('Zálohu se nepodařilo zapsat (málo místa?).'); }
        rename($tmp, $this->slozka() . "/$nazev");

        if ($sSoubory) $this->host->nastaveni->uloz('backup_media_hash', $hash);
        $this->host->nastaveni->uloz('last_backup_at', (string)$cas);
        $velikost = (int)filesize($this->slozka() . "/$nazev");
        $this->uklid();
        $this->host->protokol->zapis('info', "Záloha vytvořena: $nazev (" . self::velikost($velikost) . ($sSoubory ? ', se soubory' : ', jen databáze') . ').');
        return ['name' => $nazev, 'size' => $velikost, 'files' => $sSoubory];
    }

    public static function velikost(int $b): string
    {
        return $b >= 1048576 ? number_format($b / 1048576, 1, ',', ' ') . ' MB' : max(1, (int)round($b / 1024)) . ' kB';
    }

    /** Ponechá posledních $uchovat záloh a navíc vždy nejnovější zálohu se soubory. */
    public function uklid(): void
    {
        $seznam = $this->seznam();
        $ponechat = array_slice(array_column($seznam, 'name'), 0, $this->uchovat);
        foreach ($seznam as $b) if ($b['files']) { $ponechat[] = $b['name']; break; }
        foreach ($seznam as $b) if (!in_array($b['name'], $ponechat, true)) @unlink($this->slozka() . '/' . $b['name']);
        $cas = ($this->cas)();
        foreach (glob($this->slozka() . '/.*.part') ?: [] as $p) if (filemtime($p) < $cas - 3600) @unlink($p);
    }

    /** Volá cron: jednou denně od zadané hodiny. Vrací zálohu, nebo null, když není co dělat. */
    public function dojde(): ?array
    {
        $n = $this->host->nastaveni;
        if ($n->dej('backup_auto', '1') !== '1' || (string)$n->dej('backup_password', '') === '' || $this->podporovano()) return null;
        $cas = ($this->cas)();
        if ((int)date('G', $cas) < $this->hodina) return null;
        $posledni = (int)$n->dej('last_backup_at', 0);
        if ($posledni && date('Y-m-d', $posledni) === date('Y-m-d', $cas) && (int)date('G', $posledni) >= $this->hodina) return null;
        return $this->vytvor();
    }

    /** Otevře zálohu heslem; vrací [ZipArchive, data databáze]. */
    public function otevri(string $cesta, string $heslo): array
    {
        $zip = new ZipArchive();
        if ($zip->open($cesta) !== true) throw new RuntimeException('Soubor není platná záloha (ZIP).');
        $zip->setPassword($heslo);
        $json = $zip->getFromName('database.json');
        if ($json === false) {
            $zip->close();
            throw new RuntimeException('Zálohu nelze otevřít – nesprávné heslo, nebo to není záloha aplikace ' . $this->nazevAplikace . '.');
        }
        $db = json_decode($json, true);
        if (!is_array($db) || !isset($db['tables']) || !is_array($db['tables'])) { $zip->close(); throw new RuntimeException('Záloha je poškozená.'); }
        foreach ($this->povinne as $t) if (!isset($db['tables'][$t])) { $zip->close(); throw new RuntimeException('Záloha je poškozená.'); }
        return [$zip, $db];
    }

    /** Informace o záloze (pro potvrzení před obnovením). */
    public function info(string $cesta, string $heslo): array
    {
        [$zip, $db] = $this->otevri($cesta, $heslo);
        $zip->close();
        $soubory = 0;
        foreach ($this->skupiny as $s) if (!$s->vzdy) $soubory += count($db[$s->klicJson()] ?? []);
        return ['created_at' => $db['created_at'] ?? null, 'app_version' => $db['app_version'] ?? '?', 'schema_version' => (int)($db['schema_version'] ?? 0),
                'with_files' => !empty($db['with_files']), 'tabulky' => array_map('count', $db['tables']), 'files' => $soubory];
    }

    /**
     * Obnoví zálohu. Před obnovením vytvoří pojistnou zálohu současného stavu (se soubory).
     * Soubory: ze stejné zálohy, jinak z jiné zálohy v úložišti, jinak ze souborů, které už na disku jsou (kontrola md5).
     * @return array{safety:string,missing:list<string>}
     */
    public function obnov(string $cesta, string $heslo): array
    {
        @set_time_limit(600);
        [$zip, $db] = $this->otevri($cesta, $heslo);
        $schema = (int)($db['schema_version'] ?? 0);
        if ($schema > $this->verzeSchematu) { $zip->close(); throw new RuntimeException("Záloha je z novější verze aplikace ({$db['app_version']}). Nejdřív aktualizujte aplikaci."); }

        // 1) soubory připravit do dočasné složky (nic se zatím nemění)
        $stage = $this->tmp . '/restore-' . bin2hex(random_bytes(4));
        @mkdir($stage, 0775, true);
        $chybi = [];
        $zeZipu = function (ZipArchive $z, Skupina $s, string $f, string $md5) use ($stage): bool {
            $data = $z->getFromName("{$s->id}/$f");
            if ($data === false || md5($data) !== $md5) return false;
            @mkdir("$stage/{$s->id}", 0775, true);
            return file_put_contents("$stage/{$s->id}/$f", $data) !== false;
        };
        $jine = null;
        $zavri = function () use (&$jine) { foreach ((array)$jine as $o) $o->close(); $jine = []; };
        foreach ($this->skupiny as $s) {
            foreach ((array)($db[$s->klicJson()] ?? []) as $f => $md5) {
                $f = (string)$f;
                if (!$s->platnyNazev($f)) continue;
                if (!$s->vzdy && is_file($s->slozka . "/$f") && md5_file($s->slozka . "/$f") === $md5) continue;   // už je na místě
                if ($zeZipu($zip, $s, $f, (string)$md5)) continue;
                if ($s->vzdy) continue;   // malé soubory jsou vždy přímo v záloze
                if ($jine === null) {
                    $jine = [];
                    foreach ($this->seznam() as $b) {
                        if (!$b['files'] || $this->slozka() . '/' . $b['name'] === realpath($cesta)) continue;
                        $o = new ZipArchive();
                        if ($o->open($this->slozka() . '/' . $b['name']) === true) { $o->setPassword($heslo); $jine[] = $o; }
                    }
                }
                $nalezeno = false;
                foreach ($jine as $o) if ($zeZipu($o, $s, $f, (string)$md5)) { $nalezeno = true; break; }
                if (!$nalezeno) $chybi[] = $f;
            }
        }
        $zavri();
        $zip->close();

        // 2) pojistná záloha současného stavu
        $pojistna = $this->vytvor('pred-obnovou', true)['name'];

        // 3) databáze
        $mistni = [];
        foreach ($this->mistni as $k) $mistni[$k] = $this->host->nastaveni->dej($k);
        $pdo = $this->host->db->pdo();
        $sqlite = $this->host->db->jeSqlite();
        $pdo->exec($sqlite ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
        try {
            $pdo->beginTransaction();
            foreach (array_reverse($this->tabulky) as $t) { try { $pdo->exec('DELETE FROM ' . Db::identifikator($t)); } catch (\Throwable) { /* tabulka neexistuje */ } }
            foreach ($this->tabulky as $t) {
                $radky = $db['tables'][$t] ?? [];
                if (!$radky) continue;
                $sloupce = array_values(array_intersect(array_keys($radky[0]), $this->sloupce($t)));
                if (!$sloupce) continue;
                $sql = 'INSERT INTO ' . Db::identifikator($t) . ' (' . implode(', ', array_map([Db::class, 'identifikator'], $sloupce)) . ') VALUES (' . implode(', ', array_fill(0, count($sloupce), '?')) . ')';
                $st = $pdo->prepare($sql);
                foreach ($radky as $r) $st->execute(array_map(fn($c) => $r[$c] ?? null, $sloupce));
            }
            $this->host->nastaveni->obnov();
            foreach ($mistni as $k => $v) $this->host->nastaveni->uloz($k, $v);
            $this->host->nastaveni->uloz('schema_version', (string)max(1, $schema));
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $pdo->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
            $this->smazSlozku($stage);
            $this->host->nastaveni->obnov();
            throw new RuntimeException('Obnovení databáze selhalo, nic se nezměnilo: ' . $e->getMessage());
        }
        $pdo->exec($sqlite ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1');
        $this->host->nastaveni->obnov();

        // 4) soubory na místo, nepoužívané odstranit (jsou v pojistné záloze)
        foreach ($this->skupiny as $s) {
            if (!is_dir($s->slozka)) @mkdir($s->slozka, 0775, true);
            foreach (glob("$stage/{$s->id}/*") ?: [] as $p) rename($p, $s->slozka . '/' . basename($p));
            if ($s->uklidit) {
                $pouzite = $s->soubory();
                foreach (glob($s->slozka . '/*') ?: [] as $p) {
                    $b = basename($p);
                    if (is_file($p) && $b[0] !== '.' && !isset($pouzite[$b])) @unlink($p);
                }
            }
        }
        $this->smazSlozku($stage);

        // 5) převod databáze na aktuální verzi apod.
        if ($this->poObnoveni) ($this->poObnoveni)($schema);
        $this->host->nastaveni->obnov();
        $this->host->protokol->zapis('info', 'Obnovena záloha z ' . (!empty($db['created_at']) ? date('j. n. Y H:i', (int)strtotime($db['created_at'])) : '?')
            . " (verze {$db['app_version']}). Pojistná záloha: $pojistna." . ($chybi ? ' Chybějící soubory: ' . count($chybi) . '.' : ''));
        return ['safety' => $pojistna, 'missing' => $chybi];
    }

    /** @return list<string> */
    private function sloupce(string $tabulka): array
    {
        $st = $this->host->db->q('SELECT * FROM ' . Db::identifikator($tabulka) . ' LIMIT 0');
        $out = [];
        for ($i = 0, $n = $st->columnCount(); $i < $n; $i++) $out[] = (string)$st->getColumnMeta($i)['name'];
        return $out;
    }

    private function smazSlozku(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            is_dir("$dir/$f") ? $this->smazSlozku("$dir/$f") : @unlink("$dir/$f");
        }
        @rmdir($dir);
    }
}
