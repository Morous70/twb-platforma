<?php
declare(strict_types=1);
namespace TWB\Galerie;

use TWB\Jadro\{Db, Host};
use TWB\Obrazky\Obrazky;
use TWB\Tagy\Tagy;
use RuntimeException;

/**
 * Galerie médií (obrázky, videa, PDF) v tabulce aplikace. Soubory jsou ve složce pod náhodnými názvy,
 * tabulka drží název, druh, velikost, kontrolní součet, rozměry, tagy a archiv.
 * Obrázky se zmenšují s uchováním originálu (návrat jedním krokem).
 *
 * Co je specifické pro aplikaci (kde se médium používá, mazání vazeb), řeší funkce `priSmazani` a `pouziti`.
 */
final class Galerie
{
    public const POVOLENE = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'avi' => 'video/x-msvideo', 'mkv' => 'video/x-matroska',
        'pdf' => 'application/pdf',
    ];

    private string $t;
    private string $vyber;
    /** @var array<string, ?string> */
    private array $m;
    /** @var ?callable(array): void */
    private $priSmazani;
    /** @var ?callable(int): array */
    private $pouziti;
    /** @var callable(): int */
    private $cas;
    private ?Tagy $tagy = null;

    /**
     * @param array<string,string> $povolene přípona → mime
     * @param ?callable $priSmazani function(array $radek): void – smazat vazby média v aplikaci (běží v transakci)
     * @param ?callable $pouziti    function(int $id): array – kde se médium používá (pro zobrazení u archivovaných)
     * @param string    $casFormat  'datum' (Y-m-d H:i:s) nebo 'unix' – jak se ukládá čas vytvoření
     */
    public function __construct(
        private Host $host,
        private string $slozka,
        private ?Sloupce $sloupce = null,
        private string $tabulka = 'media',
        private array $povolene = self::POVOLENE,
        ?callable $priSmazani = null,
        ?callable $pouziti = null,
        private string $casFormat = 'datum',
        ?callable $cas = null,
    ) {
        $this->sloupce ??= new Sloupce();
        $this->t = Db::identifikator($tabulka);
        $this->m = $this->sloupce->mapa();
        $sel = [];
        foreach ($this->m as $logicky => $sloupec) if ($sloupec !== null) $sel[] = Db::identifikator($sloupec) . " AS $logicky";
        $this->vyber = 'SELECT ' . implode(', ', $sel) . " FROM {$this->t}";
        $this->priSmazani = $priSmazani;
        $this->pouziti = $pouziti;
        $this->cas = $cas ?? 'time';
        if (!in_array($casFormat, ['datum', 'unix'], true)) throw new \InvalidArgumentException('Neplatný formát času.');
        if (!is_dir($slozka)) @mkdir($slozka, 0775, true);
    }

    public function tagy(): Tagy
    {
        if ($this->sloupce->tagy === null) throw new RuntimeException('Tato tabulka nemá sloupec pro tagy.');
        return $this->tagy ??= new Tagy($this->host->db, $this->tabulka, $this->sloupce->id, $this->sloupce->nazev, $this->sloupce->tagy);
    }

    public static function druhZMime(string $mime): string
    {
        if (str_starts_with($mime, 'video/')) return 'video';
        return $mime === 'application/pdf' ? 'pdf' : 'image';
    }

    /** Řádek s logickými názvy klíčů (soubor, nazev, druh …). */
    public function radek(int $id): array
    {
        $r = $this->host->db->q($this->vyber . ' WHERE ' . Db::identifikator($this->sloupce->id) . ' = ?', [$id])->fetch() ?: throw new RuntimeException('Médium nenalezeno.');
        return $this->doplnMime($r);
    }

    /** Aplikace bez sloupce pro mime (Social Planner): odvodí se z přípony souboru. */
    private function doplnMime(array $r): array
    {
        if ($this->sloupce->mime === null) {
            $r['mime'] = $this->povolene[strtolower(pathinfo((string)$r['soubor'], PATHINFO_EXTENSION))] ?? 'application/octet-stream';
            if (!empty($r['pvSoubor'])) $r['pvMime'] = $this->povolene[strtolower(pathinfo((string)$r['pvSoubor'], PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        }
        return $r;
    }

    /** Cesta k souboru média a údaje pro jeho vydání. @return array{cesta:string,mime:string,nazev:string,md5:?string} */
    public function soubor(int $id, bool $original = false): array
    {
        $r = $this->radek($id);
        $f = $original && !empty($r['pvSoubor']) ? $r['pvSoubor'] : $r['soubor'];
        $cesta = $this->slozka . '/' . basename((string)$f);
        if (!is_file($cesta)) throw new RuntimeException('Soubor média na disku chybí.');
        return ['cesta' => $cesta, 'mime' => $original && !empty($r['pvMime']) ? (string)$r['pvMime'] : (string)$r['mime'], 'nazev' => (string)$r['nazev'], 'md5' => $r['md5'] ?? null];
    }

    /** Seznam médií (nejnovější první) bez názvů souborů na disku. */
    public function vypis(): array
    {
        $out = [];
        $tagy = $this->sloupce->tagy !== null ? $this->tagy() : null;
        foreach ($this->host->db->q($this->vyber . ' ORDER BY ' . Db::identifikator($this->sloupce->id) . ' DESC')->fetchAll() as $r) {
            $r = $this->doplnMime($r);
            $komprimovano = !empty($r['pvSoubor']) ? [
                'orig_size' => (int)$r['pvVelikost'], 'orig_w' => (int)$r['pvSirka'], 'orig_h' => (int)$r['pvVyska'],
                'orig_ext' => strtolower(pathinfo((string)$r['pvSoubor'], PATHINFO_EXTENSION)), 'w' => (int)$r['sirka'], 'h' => (int)$r['vyska'],
            ] : null;
            $arch = !empty($r['archivovano']);
            $out[] = [
                'id' => (int)$r['id'], 'nazev' => (string)$r['nazev'], 'mime' => (string)$r['mime'], 'druh' => (string)$r['druh'], 'velikost' => (int)$r['velikost'],
                'md5' => $r['md5'] ?? null, 'sirka' => isset($r['sirka']) ? (int)$r['sirka'] : null, 'vyska' => isset($r['vyska']) ? (int)$r['vyska'] : null,
                'tagy' => $tagy ? $tagy->tagyPolozky($r + ['tags' => $r['tagy'] ?? null]) : [],
                'archivovano' => $arch, 'vytvoreno' => $r['vytvoreno'] ?? null, 'komprimovano' => $komprimovano,
                'pouziti' => $arch && $this->pouziti ? ($this->pouziti)((int)$r['id']) : null,
            ];
        }
        return $out;
    }

    // ---------- zápis ----------

    private function sloupec(string $logicky): ?string { return $this->m[$logicky] ?? null; }

    /** @param array<string,mixed> $hodnoty logický název → hodnota; nepoužité sloupce se vynechají */
    private function vloz(array $hodnoty): int
    {
        $c = []; $v = [];
        foreach ($hodnoty as $l => $h) if (($s = $this->sloupec($l)) !== null) { $c[] = Db::identifikator($s); $v[] = $h; }
        $this->host->db->q("INSERT INTO {$this->t} (" . implode(', ', $c) . ') VALUES (' . implode(', ', array_fill(0, count($c), '?')) . ')', $v);
        return $this->host->db->posledniId();
    }

    /** @param array<string,mixed> $hodnoty */
    private function uprav(int $id, array $hodnoty): void
    {
        $sada = []; $v = [];
        foreach ($hodnoty as $l => $h) if (($s = $this->sloupec($l)) !== null) { $sada[] = Db::identifikator($s) . ' = ?'; $v[] = $h; }
        if (!$sada) return;
        $v[] = $id;
        $this->host->db->q("UPDATE {$this->t} SET " . implode(', ', $sada) . ' WHERE ' . Db::identifikator($this->sloupce->id) . ' = ?', $v);
    }

    private function ted(): string|int { $t = ($this->cas)(); return $this->casFormat === 'unix' ? $t : date('Y-m-d H:i:s', $t); }

    /**
     * Uloží nahraný soubor do galerie (soubor $cesta se přesune). $nahradId = „Nahradit soubor“: stejná položka, nový soubor.
     * @param list<string> $tagy tagy nové položky (u nahrazení se neuplatní)
     * @return array{id:int, poznamka:?string, nahrazeno:bool}
     */
    public function uloz(string $cesta, string $nazev, array $tagy = [], ?int $nahradId = null): array
    {
        $nazev = trim(str_replace(['/', '\\', "\0"], '_', $nazev));
        $pripona = strtolower(pathinfo($nazev, PATHINFO_EXTENSION));
        try {
            if ($nazev === '' || !isset($this->povolene[$pripona])) throw new RuntimeException("Nepodporovaný typ souboru .$pripona – povoleny jsou obrázky, videa a PDF.");
            $mime = $this->povolene[$pripona];
            if (function_exists('finfo_open')) {
                $zjisten = finfo_file(finfo_open(FILEINFO_MIME_TYPE), $cesta) ?: '';
                $ok = $pripona === 'pdf' ? $zjisten === 'application/pdf'
                    : (str_starts_with($zjisten, 'image/') || str_starts_with($zjisten, 'video/') || $zjisten === 'application/octet-stream');
                if (!$ok) throw new RuntimeException($pripona === 'pdf' ? "Soubor $nazev není platné PDF." : "Soubor $nazev není obrázek ani video.");
                if (str_starts_with($zjisten, 'image/') || str_starts_with($zjisten, 'video/')) $mime = $zjisten;
            }
            $druh = self::druhZMime($mime);
            $stary = null;
            if ($nahradId) {
                $stary = $this->radek($nahradId);
                if ($stary['druh'] !== $druh) {
                    $jak = ['image' => ['obrázek', 'obrázkem'], 'video' => ['video', 'videem'], 'pdf' => ['PDF', 'PDF souborem']][$stary['druh']] ?? ['soubor', 'souborem stejného druhu'];
                    throw new RuntimeException("Soubor musí být stejného druhu: {$jak[0]} nahraďte opět {$jak[1]}.");
                }
            }
        } catch (\Throwable $e) {
            @unlink($cesta);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        $soubor = bin2hex(random_bytes(12)) . '.' . $pripona;
        if (!@rename($cesta, $this->slozka . "/$soubor") && !(@copy($cesta, $this->slozka . "/$soubor") && @unlink($cesta))) throw new RuntimeException('Soubor se nepodařilo uložit do galerie.');
        $plna = $this->slozka . "/$soubor";
        $rozmer = $druh === 'image' ? (@getimagesize($plna) ?: [null, null]) : [null, null];
        $udaje = ['soubor' => $soubor, 'mime' => $mime, 'druh' => $druh, 'velikost' => filesize($plna), 'md5' => md5_file($plna), 'sirka' => $rozmer[0], 'vyska' => $rozmer[1]];

        if ($stary) {
            // název: když ho uživatel nepřejmenoval, převezme se z nového souboru; jinak zůstane jeho jméno s novou příponou
            $prejmenoval = ($stary['nahranyNazev'] ?? null) === null
                || pathinfo((string)$stary['nazev'], PATHINFO_FILENAME) !== pathinfo((string)$stary['nahranyNazev'], PATHINFO_FILENAME);
            $novyNazev = $prejmenoval ? Obrazky::nazevSPriponou((string)$stary['nazev'], $pripona) : $nazev;
            $this->smazSoubory($stary);
            $this->uprav((int)$stary['id'], $udaje + ['nahranyNazev' => $nazev, 'nazev' => $novyNazev,
                'pvSoubor' => null, 'pvMime' => null, 'pvVelikost' => null, 'pvSirka' => null, 'pvVyska' => null]);
            $id = (int)$stary['id'];
            $this->host->protokol->zapis('info', "Soubor nahrazen: {$stary['nazev']} → $novyNazev");
        } else {
            $id = $this->vloz($udaje + ['nazev' => $nazev, 'nahranyNazev' => $nazev, 'vytvoreno' => $this->ted()]);
            if ($tagy && $this->sloupce->tagy !== null) {
                try { $this->tagy()->nastav($id, $this->tagy()->vycistiSeznam($tagy)); } catch (\InvalidArgumentException) { /* nahrání kvůli tagům neselže */ }
            }
            $this->host->protokol->zapis('info', "Do galerie nahráno: $nazev");
        }
        $poznamka = null;
        if ($druh === 'image' && Obrazky::nastaveni($this->host->nastaveni)['auto']) {
            try {
                if ($zprava = $this->komprimuj($id, false)) { $poznamka = $zprava; $this->host->protokol->zapis('info', "Obrázek zkomprimován: $zprava"); }
            } catch (\Throwable $e) {
                $this->host->protokol->zapis('warn', "Obrázek $nazev se nepodařilo zkomprimovat: " . $e->getMessage());
                $poznamka = 'Komprese se nepodařila: ' . $e->getMessage();
            }
        }
        return ['id' => $id, 'poznamka' => $poznamka, 'nahrazeno' => $stary !== null];
    }

    public function prejmenuj(int $id, string $nazev): void
    {
        $r = $this->radek($id);
        $nazev = trim($nazev);
        if ($nazev === '' || preg_match('~[/\\\\]~', $nazev)) throw new RuntimeException('Neplatný název.');
        $pripona = pathinfo((string)$r['nazev'], PATHINFO_EXTENSION);
        if ($pripona !== '' && strtolower(pathinfo($nazev, PATHINFO_EXTENSION)) !== strtolower($pripona)) $nazev .= '.' . $pripona;
        $this->uprav($id, ['nazev' => $nazev]);
    }

    /** Smaže média včetně souborů. @return list<string> názvy smazaných */
    public function smaz(array $ids): array
    {
        $nazvy = [];
        foreach ($ids as $id) {
            try { $r = $this->radek((int)$id); } catch (RuntimeException) { continue; }
            $this->host->db->transakce(function (Db $db) use ($r) {
                if ($this->priSmazani) ($this->priSmazani)($r);
                $db->q("DELETE FROM {$this->t} WHERE " . Db::identifikator($this->sloupce->id) . ' = ?', [$r['id']]);
            });
            $this->smazSoubory($r);
            $nazvy[] = (string)$r['nazev'];
        }
        if ($nazvy) $this->host->protokol->zapis('info', 'Z galerie smazáno: ' . implode(', ', $nazvy));
        return $nazvy;
    }

    private function smazSoubory(array $r): void
    {
        if (!empty($r['soubor'])) @unlink($this->slozka . '/' . basename((string)$r['soubor']));
        if (!empty($r['pvSoubor']) && $r['pvSoubor'] !== $r['soubor']) @unlink($this->slozka . '/' . basename((string)$r['pvSoubor']));
    }

    /** Do archivu / zpět; médium zůstává v aplikaci použité, jen se nenabízí. @return list<string> změněné názvy */
    public function archivuj(array $ids, bool $archivovat): array
    {
        if ($this->sloupce->archivovano === null) throw new RuntimeException('Tato tabulka nemá archiv.');
        $nazvy = [];
        foreach ($ids as $id) {
            try { $r = $this->radek((int)$id); } catch (RuntimeException) { continue; }
            if ($archivovat === !empty($r['archivovano'])) continue;
            $this->uprav((int)$id, ['archivovano' => $archivovat ? date('Y-m-d H:i:s', ($this->cas)()) : null]);
            $nazvy[] = (string)$r['nazev'];
        }
        if ($nazvy) $this->host->protokol->zapis('info', ($archivovat ? 'Do archivu přesunuto: ' : 'Z archivu vráceno: ') . implode(', ', $nazvy));
        return $nazvy;
    }

    // ---------- komprese ----------

    /**
     * Zkomprimuje obrázek (vždy z originálu, pokud existuje). Vrací popis změny, nebo null, když se nic nezměnilo.
     */
    public function komprimuj(int $id, bool $vynutit): ?string
    {
        if (!$this->sloupce->maKompresi()) return null;
        $r = $this->radek($id);
        if ($r['druh'] !== 'image') return null;
        $zdroj = $r['pvSoubor'] ?: $r['soubor'];
        $zdrojCesta = $this->slozka . '/' . basename((string)$zdroj);
        if (!is_file($zdrojCesta)) return null;
        $v = Obrazky::komprimuj($zdrojCesta, Obrazky::nastaveni($this->host->nastaveni), $vynutit);
        if (!$v) return null;

        $soubor = bin2hex(random_bytes(12)) . '.' . $v['ext'];
        file_put_contents($this->slozka . "/$soubor", $v['data']);
        $puvodniVelikost = $r['pvSoubor'] ? (int)$r['pvVelikost'] : (int)$r['velikost'];
        $nove = ['soubor' => $soubor, 'mime' => $v['mime'], 'velikost' => strlen($v['data']), 'md5' => md5($v['data']),
                 'nazev' => Obrazky::nazevSPriponou((string)$r['nazev'], $v['ext']), 'sirka' => $v['w'], 'vyska' => $v['h']];
        if ($r['pvSoubor']) {
            // už komprimovaný → starou zmenšenou verzi nahradit, originál zůstává
            if ($r['soubor'] !== $r['pvSoubor']) @unlink($this->slozka . '/' . basename((string)$r['soubor']));
        } else {
            $nove += ['pvSoubor' => $r['soubor'], 'pvMime' => $r['mime'], 'pvVelikost' => (int)$r['velikost'], 'pvSirka' => $v['ow'], 'pvVyska' => $v['oh']];
        }
        $this->uprav($id, $nove);
        return sprintf('%s: %s → %s, %d×%d → %d×%d', $r['nazev'], Obrazky::velikost($puvodniVelikost), Obrazky::velikost(strlen($v['data'])), $v['ow'], $v['oh'], $v['w'], $v['h']);
    }

    /** Vrátí původní (nekomprimovaný) soubor. */
    public function obnovPuvodni(int $id): bool
    {
        if (!$this->sloupce->maKompresi()) return false;
        $r = $this->radek($id);
        if (!$r['pvSoubor'] || !is_file($this->slozka . '/' . basename((string)$r['pvSoubor']))) return false;
        if ($r['soubor'] !== $r['pvSoubor']) @unlink($this->slozka . '/' . basename((string)$r['soubor']));
        $cesta = $this->slozka . '/' . basename((string)$r['pvSoubor']);
        $rozmer = @getimagesize($cesta) ?: [null, null];
        $this->uprav($id, ['soubor' => $r['pvSoubor'], 'mime' => $r['pvMime'], 'velikost' => filesize($cesta), 'md5' => md5_file($cesta),
            'nazev' => Obrazky::nazevSPriponou((string)$r['nazev'], strtolower(pathinfo((string)$r['pvSoubor'], PATHINFO_EXTENSION))),
            'sirka' => $rozmer[0], 'vyska' => $rozmer[1], 'pvSoubor' => null, 'pvMime' => null, 'pvVelikost' => null, 'pvSirka' => null, 'pvVyska' => null]);
        return true;
    }

    // ---------- zálohy ----------

    /** Soubory galerie, které databáze používá: [název souboru => md5]. Pro skupinu souborů v modulu zalohy. */
    public function soubory(): array
    {
        $out = [];
        $pv = $this->sloupce->pvSoubor !== null ? ', ' . Db::identifikator($this->sloupce->pvSoubor) . ' AS pv' : ", NULL AS pv";
        foreach ($this->host->db->q('SELECT ' . Db::identifikator($this->sloupce->soubor) . " AS f$pv FROM {$this->t}")->fetchAll() as $r) {
            foreach ([$r['f'], $r['pv']] as $f) {
                if ($f && !isset($out[$f]) && is_file($this->slozka . "/$f")) $out[$f] = md5_file($this->slozka . "/$f");
            }
        }
        ksort($out);
        return $out;
    }
}
