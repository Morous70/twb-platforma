<?php
declare(strict_types=1);
namespace TWB\Jadro;

/** Migrace schématu modulu. Každý modul s vlastními tabulkami má třídu Migrace, která tohle implementuje. */
interface ModulMigrace
{
    /** Aktuální verze schématu modulu (celé číslo, roste). */
    public static function verze(): int;

    /** Převede schéma ze zadané verze (0 = nic neexistuje) na aktuální. */
    public static function proved(Db $db, int $zVerze): void;
}
