<?php
declare(strict_types=1);
namespace TWB\Slidy;

/** Obrázky, které šablona používá (pozadí, prvky typu obrázek). Zařídí aplikace (galerie). */
interface Zdroje
{
    /** Obrázek z galerie jako true-color GD obraz, nebo null (chybí, není obrázek). */
    public function obrazek(?int $id): ?\GdImage;

    /** Otisk souboru – změní se, když se obrázek změní (součást klíče vykreslení). Prázdný řetězec = soubor chybí. */
    public function podpis(int $id): string;
}
