<?php
declare(strict_types=1);
namespace TWB\Galerie;

use TWB\Jadro\Db;

/**
 * Přiřazení logických údajů o médiu ke sloupcům tabulky aplikace. Výchozí = tabulka `media` z Plánovače médií.
 * Sloupec s hodnotou null aplikace nemá (např. bez „pv*“ sloupců nejde komprimovat s návratem k originálu).
 */
final class Sloupce
{
    public function __construct(
        public readonly string $id = 'id',
        public readonly string $soubor = 'filename',
        public readonly string $nazev = 'original_name',
        public readonly ?string $mime = 'mime',
        public readonly string $druh = 'kind',
        public readonly string $velikost = 'size',
        public readonly ?string $md5 = 'md5',
        public readonly ?string $sirka = 'width',
        public readonly ?string $vyska = 'height',
        public readonly ?string $pvSoubor = 'orig_filename',
        public readonly ?string $pvMime = 'orig_mime',
        public readonly ?string $pvVelikost = 'orig_size',
        public readonly ?string $pvSirka = 'orig_w',
        public readonly ?string $pvVyska = 'orig_h',
        public readonly ?string $nahranyNazev = 'upload_name',
        public readonly ?string $tagy = 'tags',
        public readonly ?string $archivovano = 'archived_at',
        public readonly ?string $vytvoreno = 'created_at',
        /** 0.3.0: popis úprav obrázku (ořez, otočení); bez sloupce jde upravovat také, jen se úprava nezapamatuje (komprese pak vychází z originálu). */
        public readonly ?string $upravy = null,
    ) {
        foreach (get_object_vars($this) as $s) if ($s !== null) Db::identifikator($s);
    }

    /** @return array<string, ?string> logický název → sloupec */
    public function mapa(): array { return get_object_vars($this); }

    public function maKompresi(): bool
    {
        return $this->pvSoubor !== null && $this->pvMime !== null && $this->pvVelikost !== null && $this->pvSirka !== null && $this->pvVyska !== null;
    }
}
