<?php
declare(strict_types=1);
namespace TWB\Zalohy;

/** Skupina souborů, které patří k záloze (galerie, písma, nahrané soubory …). */
final class Skupina
{
    /** @var callable(): array<string,string> */
    private $soubory;

    /**
     * @param string   $id       složka uvnitř ZIPu (např. „media“, „fonts“)
     * @param string   $slozka   složka na disku
     * @param callable $soubory  vrací [název => md5] souborů, které jsou právě potřeba
     * @param string   $klic     klíč v database.json (výchozí „<id>_files“)
     * @param bool     $vzdy     true = ve všech zálohách (malé soubory); false = jen když se změnily
     * @param bool     $uklidit  po obnovení smazat soubory, které databáze nepoužívá
     * @param bool     $bezKomprese soubory se jen ukládají (obrázky a videa už jsou komprimované)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $slozka,
        callable $soubory,
        public readonly string $klic = '',
        public readonly bool $vzdy = false,
        public readonly bool $uklidit = false,
        public readonly string $vzor = '/^[A-Za-z0-9._-]+$/',
        public readonly bool $bezKomprese = false,
    ) {
        $this->soubory = $soubory;
    }

    public function klicJson(): string { return $this->klic !== '' ? $this->klic : $this->id . '_files'; }

    /** @return array<string,string> */
    public function soubory(): array { return ($this->soubory)(); }

    public function platnyNazev(string $n): bool { return (bool)preg_match($this->vzor, $n); }
}
