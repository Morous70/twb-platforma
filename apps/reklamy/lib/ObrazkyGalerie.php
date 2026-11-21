<?php
declare(strict_types=1);
namespace Reklamy;

use TWB\Galerie\Galerie;

/** Obrázky z galerie pro vykreslení slidů (rozhraní modulu slidy). */
final class ObrazkyGalerie implements \TWB\Slidy\Zdroje
{
    public function __construct(private Galerie $galerie) {}

    public function obrazek(?int $id): ?\GdImage
    {
        if (!$id) return null;
        try { $f = $this->galerie->soubor($id); } catch (\RuntimeException) { return null; }
        if (!str_starts_with($f['mime'], 'image/')) return null;
        $im = @imagecreatefromstring((string)file_get_contents($f['cesta']));
        if (!$im) return null;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        imagealphablending($im, true);
        imagesavealpha($im, true);
        return $im;
    }

    public function podpis(int $id): string
    {
        try { $f = $this->galerie->soubor($id); } catch (\RuntimeException) { return ''; }
        return (string)($f['md5'] ?? md5_file($f['cesta']));
    }
}
