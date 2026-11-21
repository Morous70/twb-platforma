<?php
declare(strict_types=1);
namespace TWB\Pisma;

use TWB\Jadro\Nastaveni;
use TWB\Jadro\Protokol;

/**
 * Písma pro slidy: vestavěná sada (soubory ve složce modulu `fonty/`, kterou sestavení zkopíruje do aplikace)
 * a vlastní písma nahraná uživatelem (soubory v datové složce, aby je aktualizace nepřepsala;
 * seznam v nastavení `custom_fonts`: {klíč: {name, file, created_at}}).
 */
final class Pisma
{
    public const MAX_BAJTU = 5 * 1024 * 1024;
    public const CESKE_ZNAKY = 'ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚŤĎŇ';

    /** Vestavěná písma: klíč → [název, základ souboru, tloušťky]. */
    public const VESTAVENA = [
        'roboto'     => ['name' => 'Roboto',           'file' => 'Roboto',          'weights' => [400, 700, 900]],
        'montserrat' => ['name' => 'Montserrat',       'file' => 'Montserrat',      'weights' => [400, 700, 900]],
        'opensans'   => ['name' => 'Open Sans',        'file' => 'OpenSans',        'weights' => [400, 700]],
        'oswald'     => ['name' => 'Oswald',           'file' => 'Oswald',          'weights' => [400, 700]],
        'bebas'      => ['name' => 'Bebas Neue',       'file' => 'BebasNeue',       'weights' => [400]],
        'playfair'   => ['name' => 'Playfair Display', 'file' => 'PlayfairDisplay', 'weights' => [400, 700]],
        'archivo'    => ['name' => 'Archivo Black',    'file' => 'ArchivoBlack',    'weights' => [900]],
        'titan'      => ['name' => 'Titan One',        'file' => 'TitanOne',        'weights' => [900]],
        'anton'      => ['name' => 'Anton',            'file' => 'Anton',           'weights' => [700]],
    ];

    /** @var array<string, array{name: string, file: string, weights: list<int>, custom: bool}>|null */
    private ?array $cache = null;

    /**
     * @param string $slozkaVestavenych složka se soubory <Základ>-<tloušťka>.ttf
     * @param string $slozkaVlastnich   datová složka vlastních písem
     * @param (callable(string): list<string>)|null $pouziti  vrátí názvy slidů, které písmo používají (zařídí aplikace)
     * @param string $urlVlastni        adresa souboru vlastního písma pro editor, {klic} se nahradí
     * @param string $urlVestaveneSablona adresa vestavěného písma, {soubor} a {tloustka} se nahradí
     */
    public function __construct(
        private Nastaveni $nastaveni,
        private Protokol $protokol,
        private string $slozkaVestavenych,
        private string $slozkaVlastnich,
        private $pouziti = null,
        private string $urlVlastni = 'api.php?a=font&key={klic}',
        private string $urlVestavene = 'assets/fonts/{soubor}-{tloustka}.ttf',
        private string $klicNastaveni = 'custom_fonts',
    ) {}

    /** Vlastní písma: [klíč => [name, file, created_at]]. @return array<string, array<string, mixed>> */
    public function vlastni(): array
    {
        $c = json_decode((string)$this->nastaveni->dej($this->klicNastaveni, '{}'), true);
        return is_array($c) ? $c : [];
    }

    /** @param array<string, array<string, mixed>> $c */
    private function ulozVlastni(array $c): void
    {
        $this->nastaveni->uloz($this->klicNastaveni, json_encode($c, JSON_UNESCAPED_UNICODE));
        $this->cache = null;
    }

    /** Všechna písma: vestavěná + vlastní. @return array<string, array{name: string, file: string, weights: list<int>, custom: bool}> */
    public function vsechna(bool $znovu = false): array
    {
        if ($this->cache !== null && !$znovu) return $this->cache;
        $all = [];
        foreach (self::VESTAVENA as $k => $f) $all[$k] = $f + ['custom' => false];
        foreach ($this->vlastni() as $k => $f) {
            if (!is_array($f) || !preg_match('/^u_[a-z0-9]{1,40}$/', (string)$k) || !is_file($this->slozkaVlastnich . '/' . ($f['file'] ?? ''))) continue;
            $all[$k] = ['name' => (string)$f['name'], 'file' => (string)$f['file'], 'weights' => [700], 'custom' => true];
        }
        return $this->cache = $all;
    }

    public function existuje(string $klic): bool { return isset($this->vsechna()[$klic]); }

    /** Cesta k souboru písma pro danou tloušťku (nejbližší dostupná); neznámé písmo → Roboto. */
    public function soubor(string $klic, int $tloustka): string
    {
        $all = $this->vsechna();
        $f = $all[$klic] ?? $all['roboto'];
        if ($f['custom']) return $this->slozkaVlastnich . '/' . $f['file'];
        $best = $f['weights'][0];
        foreach ($f['weights'] as $w) if (abs($w - $tloustka) < abs($best - $tloustka)) $best = $w;
        return $this->slozkaVestavenych . "/{$f['file']}-$best.ttf";
    }

    /** Otisk vykresleného znaku (prázdný = null). */
    private function otiskZnaku(string $soubor, string $znak): ?string
    {
        $im = imagecreatetruecolor(64, 64);
        imagettftext($im, 32, 0, 12, 48, 0xffffff, $soubor, $znak);
        $data = '';
        $neco = false;
        for ($y = 0; $y < 64; $y += 2) for ($x = 0; $x < 64; $x += 2) { $v = imagecolorat($im, $x, $y) & 255; $data .= chr($v); if ($v > 30) $neco = true; }
        return $neco ? md5($data) : null;
    }

    /** České znaky, které písmo nemá (vykreslí se jako prázdno nebo jako náhradní obdélník). @return list<string> */
    public function chybejiciZnaky(string $soubor): array
    {
        $notdef = $this->otiskZnaku($soubor, "\u{FFFE}");
        $chybi = [];
        foreach (mb_str_split(self::CESKE_ZNAKY) as $ch) {
            $sig = $this->otiskZnaku($soubor, $ch);
            if ($sig === null || $sig === $notdef) $chybi[] = $ch;
        }
        return $chybi;
    }

    /** Nahraje písmo. Vrací [písmo, chybějící české znaky]. @return array{0: array<string, mixed>, 1: list<string>} */
    public function nahraj(string $tmp, string $puvodniNazev, string $nazev = ''): array
    {
        $ext = strtolower(pathinfo($puvodniNazev, PATHINFO_EXTENSION));
        if (!in_array($ext, ['ttf', 'otf'], true)) throw new \InvalidArgumentException('Písmo musí být soubor TTF nebo OTF.');
        $velikost = @filesize($tmp);
        if (!$velikost) throw new \InvalidArgumentException('Soubor je prázdný.');
        if ($velikost > self::MAX_BAJTU) throw new \InvalidArgumentException('Písmo je větší než 5 MB.');
        $hlavicka = (string)file_get_contents($tmp, false, null, 0, 4);
        if (!in_array($hlavicka, ["\x00\x01\x00\x00", 'OTTO', 'true', 'ttcf'], true)) throw new \InvalidArgumentException('Soubor není písmo TTF ani OTF.');
        if (!function_exists('imagettfbbox') || @imagettfbbox(20, 0, $tmp, 'Abc 123') === false) {
            throw new \InvalidArgumentException('Písmo se nepodařilo načíst – soubor je poškozený nebo ho server neumí použít.');
        }
        @mkdir($this->slozkaVlastnich, 0775, true);
        $klic = 'u_' . bin2hex(random_bytes(5));
        $soubor = "$klic.$ext";
        if (!copy($tmp, $this->slozkaVlastnich . "/$soubor")) throw new \RuntimeException('Písmo se nepodařilo uložit na server.');
        $nazev = trim($nazev) !== '' ? trim($nazev) : trim((string)preg_replace('/[-_]+/', ' ', pathinfo($puvodniNazev, PATHINFO_FILENAME)));
        $nazev = mb_substr($nazev !== '' ? $nazev : 'Moje písmo', 0, 60);
        $c = $this->vlastni();
        $c[$klic] = ['name' => $nazev, 'file' => $soubor, 'created_at' => date('Y-m-d H:i:s')];
        $this->ulozVlastni($c);
        $chybi = $this->chybejiciZnaky($this->slozkaVlastnich . "/$soubor");
        $this->protokol->zapis('info', "Nahráno písmo „{$nazev}“." . ($chybi ? ' Chybí české znaky: ' . implode(' ', $chybi) . '.' : ''));
        return [$this->vystup($klic), $chybi];
    }

    /** Písmo ve tvaru pro editor. @return array<string, mixed> */
    public function vystup(string $klic): array
    {
        $f = $this->vsechna()[$klic];
        $files = [];
        foreach ($f['weights'] as $w) {
            $files[$w] = $f['custom'] ? str_replace('{klic}', $klic, $this->urlVlastni)
                : strtr($this->urlVestavene, ['{soubor}' => $f['file'], '{tloustka}' => (string)$w]);
        }
        return ['key' => $klic, 'name' => $f['name'], 'weights' => $f['weights'], 'custom' => $f['custom'], 'files' => $files];
    }

    /** Slidy, které písmo používají (názvy). @return list<string> */
    public function pouziti(string $klic): array
    {
        return $this->pouziti ? array_values(($this->pouziti)($klic)) : [];
    }

    /** @return array<string, mixed> */
    public function prejmenuj(string $klic, string $nazev): array
    {
        $c = $this->vlastni();
        if (!isset($c[$klic])) throw new \InvalidArgumentException('Písmo nenalezeno.');
        $nazev = mb_substr(trim($nazev), 0, 60);
        if ($nazev === '') throw new \InvalidArgumentException('Zadejte název písma.');
        $c[$klic]['name'] = $nazev;
        $this->ulozVlastni($c);
        return $this->vystup($klic);
    }

    public function smaz(string $klic): void
    {
        $c = $this->vlastni();
        if (!isset($c[$klic])) throw new \InvalidArgumentException('Písmo nenalezeno.');
        $pouzite = $this->pouziti($klic);
        if ($pouzite) {
            throw new \InvalidArgumentException('Písmo je použité ve slidu ' . implode(', ', array_map(fn($n) => "„{$n}“", array_slice($pouzite, 0, 5)))
                . (count($pouzite) > 5 ? ' a dalších' : '') . '. Nejdřív ve slidu vyberte jiné písmo.');
        }
        @unlink($this->slozkaVlastnich . '/' . $c[$klic]['file']);
        $nazev = $c[$klic]['name'];
        unset($c[$klic]);
        $this->ulozVlastni($c);
        $this->protokol->zapis('info', "Odebráno písmo „{$nazev}“.");
    }

    /** Soubory vlastních písem pro zálohu: [soubor => md5]. @return array<string, string> */
    public function souboryProZalohu(): array
    {
        $out = [];
        foreach ($this->vlastni() as $f) {
            $p = $this->slozkaVlastnich . '/' . ($f['file'] ?? '');
            if (preg_match('/^u_[a-z0-9]+\.(ttf|otf)$/', $f['file'] ?? '') && is_file($p)) $out[$f['file']] = (string)md5_file($p);
        }
        return $out;
    }
}
