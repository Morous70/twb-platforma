<?php
declare(strict_types=1);
namespace Reklamy;

use DateTimeImmutable;
use TWB\Slidy\Navrh;

/**
 * Bannery ze slidů: šablona (JSON v banners.content) se vykreslí modulem slidy do JPEG.
 * Hotový obrázek je v data/slidy/<id>-<klíč>.jpg; klíč se změní se šablonou, obrázky i dosazenými proměnnými
 * (např. {datum}), takže se obrázek vykreslí znovu jen tehdy, když by vypadal jinak.
 */
final class SlideBannery
{
    public const KVALITA = 90;

    public function __construct(private App $app) {}

    public function slozka(): string { return $this->app->data . '/slidy'; }

    /** Formáty plátna: běžné rozměry banerů (klíč „300x250“). @return array<string, array{0:int,1:int,2:string}> */
    public static function formaty(): array
    {
        $f = [];
        foreach (Bannery::ROZMERY as $k => $n) {
            [$w, $h] = array_map('intval', explode('x', $k));
            $f[$k] = [$w, $h, $n];
        }
        return $f;
    }

    /** Šablona banneru (očištěná). */
    public function design(array $banner): array
    {
        return $this->app->navrh()->vycisti(json_decode((string)($banner['content'] ?? ''), true));
    }

    /** Nová šablona daného rozměru (běžný rozměr nebo vlastní). */
    public function nova(int $w, int $h): array
    {
        $k = "{$w}x{$h}";
        return isset(self::formaty()[$k]) ? $this->app->navrh()->vychozi($k) : $this->app->navrh()->vychozi(Navrh::VLASTNI, $w, $h);
    }

    /** Krátký klíč obsahu pro daný okamžik. */
    public function klic(array $banner, ?DateTimeImmutable $t = null): string
    {
        return substr($this->app->slidy()->klic($this->design($banner), $t ?? new DateTimeImmutable()), 0, 12);
    }

    /** Cesta k hotovému obrázku banneru (vykreslí ho, když chybí nebo je zastaralý). */
    public function soubor(array $banner, ?DateTimeImmutable $t = null): string
    {
        $t ??= new DateTimeImmutable();
        $id = (int)$banner['id'];
        $d = $this->design($banner);
        $k = substr($this->app->slidy()->klic($d, $t), 0, 12);
        $cesta = $this->slozka() . "/{$id}-{$k}.jpg";
        if (is_file($cesta)) return $cesta;
        if (!is_dir($this->slozka())) @mkdir($this->slozka(), 0775, true);
        @set_time_limit(60);
        $jpg = $this->app->slidy()->vykresli($d, $t, self::KVALITA);
        $tmp = $cesta . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $jpg) === false || !@rename($tmp, $cesta)) {
            @unlink($tmp);
            throw new \RuntimeException('Obrázek slidu se nepodařilo uložit do složky data/slidy.');
        }
        foreach (glob($this->slozka() . "/{$id}-*.jpg") ?: [] as $stary) if ($stary !== $cesta) @unlink($stary);
        return $cesta;
    }

    /** Smaže vykreslené obrázky banneru. */
    public function smazObrazky(int $id): void
    {
        foreach (glob($this->slozka() . "/{$id}-*.jpg") ?: [] as $f) @unlink($f);
    }

    /**
     * Uloží slide z editoru: nový banner v kampani, nebo nová šablona a název existujícího (ostatní údaje banneru zůstanou).
     * Vrací ID banneru.
     */
    public function uloz(?int $id, ?int $kampan, string $nazev, mixed $design): int
    {
        $v = $id ? $this->app->bannery()->radek($id) : ['campaign_id' => $kampan, 'type' => 'slide'];
        if (($v['type'] ?? '') !== 'slide') throw new Chyba('Banner není slide.');
        $v['name'] = $nazev;
        $v['design'] = $design;
        $id = $this->app->bannery()->uloz($v, $id);
        $this->soubor($this->app->bannery()->radek($id));
        return $id;
    }

    /** Šablony slidů, které používají obrázek z galerie. @return list<string> názvy banerů */
    public function pouzivajiObrazek(int $mediaId): array
    {
        $out = [];
        foreach ($this->app->db->q("SELECT id, name, content FROM banners WHERE type = 'slide'")->fetchAll() as $b) {
            if (in_array($mediaId, Navrh::mediaIds($this->design($b)), true)) $out[] = (string)$b['name'];
        }
        return $out;
    }

    /** @return list<string> názvy banerů se slidem, které používají písmo */
    public function pouzivajiPismo(string $klic): array
    {
        $out = [];
        foreach ($this->app->db->q("SELECT name, content FROM banners WHERE type = 'slide'")->fetchAll() as $b) {
            if (Navrh::pouzivaPismo(json_decode((string)$b['content'], true) ?: [], $klic)) $out[] = (string)$b['name'];
        }
        return $out;
    }
}
