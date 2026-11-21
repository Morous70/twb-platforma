<?php
declare(strict_types=1);
namespace TWB\Jadro;

/**
 * Registr API akcí a haků. Modul si zapíše své akce; aplikace je pak jen vyvolá.
 * Hak je místo, kde se modul zeptá aplikace (např. „kde se médium používá“).
 */
final class Registr
{
    /** @var array<string, callable> */
    private array $akce = [];
    /** @var array<string, list<callable>> */
    private array $haky = [];

    public function akce(string $nazev, callable $obsluha): void
    {
        if (isset($this->akce[$nazev])) throw new \LogicException("API akce „{$nazev}“ už je zaregistrovaná.");
        $this->akce[$nazev] = $obsluha;
    }

    public function maAkci(string $nazev): bool { return isset($this->akce[$nazev]); }

    /** @return list<string> */
    public function seznamAkci(): array { return array_keys($this->akce); }

    /** @param array<string, mixed> $vstup */
    public function proved(string $nazev, array $vstup = []): mixed
    {
        if (!isset($this->akce[$nazev])) throw new \OutOfBoundsException("Neznámá API akce „{$nazev}“.");
        return ($this->akce[$nazev])($vstup);
    }

    public function hak(string $nazev, callable $obsluha): void { $this->haky[$nazev][] = $obsluha; }

    /** Zavolá všechny obsluhy haku a vrátí jejich výsledky. @return list<mixed> */
    public function zavolej(string $nazev, mixed ...$argumenty): array
    {
        return array_map(fn($fn) => $fn(...$argumenty), $this->haky[$nazev] ?? []);
    }

    /** Sloučí pole vrácená obsluhami haku (např. seznam míst použití). @return list<mixed> */
    public function sluc(string $nazev, mixed ...$argumenty): array
    {
        $out = [];
        foreach ($this->zavolej($nazev, ...$argumenty) as $r) if (is_array($r)) array_push($out, ...array_values($r));
        return $out;
    }
}
