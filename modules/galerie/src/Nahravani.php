<?php
declare(strict_types=1);
namespace TWB\Galerie;

use RuntimeException;

/** Skládání souboru nahrávaného po částech (velké soubory na hostingu s malým limitem požadavku). */
final class Nahravani
{
    public function __construct(private string $slozka, private int $maxVelikost = 512 * 1048576)
    {
        if (!is_dir($slozka)) @mkdir($slozka, 0775, true);
    }

    /**
     * Přijme jednu část. Vrací cestu ke kompletnímu souboru po poslední části, jinak null.
     * Části musí chodit popořadě; první část začíná znovu.
     */
    public function cast(string $uid, int $index, int $celkem, string $cestaCasti): ?string
    {
        $uid = preg_replace('/[^a-f0-9]/', '', $uid);
        if (strlen($uid) < 16 || $index < 0 || $celkem < 1 || $index >= $celkem) throw new RuntimeException('Neplatný požadavek.');
        if (!is_file($cestaCasti)) throw new RuntimeException('Část souboru se nepodařilo přijmout.');
        $cil = $this->slozka . "/$uid.part";
        if ($index === 0) @unlink($cil);
        elseif (!is_file($cil)) throw new RuntimeException('Nahrávání bylo přerušeno, začněte znovu.');
        $data = file_get_contents($cestaCasti);
        if ($data === false) throw new RuntimeException('Část souboru se nepodařilo přečíst.');
        if ((is_file($cil) ? (int)filesize($cil) : 0) + strlen($data) > $this->maxVelikost) {
            @unlink($cil);
            throw new RuntimeException('Soubor je větší než povolených ' . round($this->maxVelikost / 1048576) . ' MB.');
        }
        if (file_put_contents($cil, $data, FILE_APPEND) === false) throw new RuntimeException('Zápis části souboru selhal (málo místa?).');
        return $index === $celkem - 1 ? $cil : null;
    }

    /** Smaže nedokončená nahrávání starší než zadaný počet sekund. */
    public function uklid(int $sekund = 3600, ?int $cas = null): int
    {
        $n = 0;
        foreach (glob($this->slozka . '/*.part') ?: [] as $p) if (filemtime($p) < ($cas ?? time()) - $sekund && @unlink($p)) $n++;
        return $n;
    }
}
