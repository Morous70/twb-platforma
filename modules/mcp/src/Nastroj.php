<?php
declare(strict_types=1);
namespace TWB\Mcp;

/**
 * Nástroj MCP: název (česky bez diakritiky, např. „nahraj_medium“), popis pro model, JSON Schema vstupu,
 * potřebné oprávnění a funkce, která ho provede: fn(array $argumenty, array $kontext): array (výsledek jako JSON objekt).
 * Kontext: ['opravneni' => list<string>, 'klient' => název klienta]. Chybu pro uživatele hlaste výjimkou ChybaNastroje.
 */
final class Nastroj
{
    /** @var callable(array<string, mixed>, array<string, mixed>): array<string, mixed> */
    public readonly mixed $funkce;

    /**
     * @param array<string, mixed> $schema  JSON Schema objektu argumentů (type: object)
     * @param string $opravneni  oprávnění, bez kterého nástroj klient neuvidí ani nezavolá
     * @param bool $zapis  mění data (zapíše se do protokolu, anotace readOnlyHint = false)
     * @param bool $mazani  může něco nevratně změnit (anotace destructiveHint)
     * @param bool $site  pracuje s vnějším světem, např. stahuje z adresy (anotace openWorldHint)
     */
    public function __construct(
        public readonly string $nazev,
        public readonly string $titulek,
        public readonly string $popis,
        public readonly array $schema,
        callable $funkce,
        public readonly string $opravneni = 'read',
        public readonly bool $zapis = false,
        public readonly bool $mazani = false,
        public readonly bool $site = false,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $nazev)) throw new \InvalidArgumentException("Neplatný název nástroje „{$nazev}“ (malá písmena bez diakritiky, číslice, _).");
        if (($schema['type'] ?? null) !== 'object') throw new \InvalidArgumentException("Schéma nástroje „{$nazev}“ musí být typu object.");
        $this->funkce = $funkce;
    }

    /** Popis pro tools/list. @return array<string, mixed> */
    public function popisProKlienta(): array
    {
        $schema = $this->schema;
        if (!isset($schema['properties']) || $schema['properties'] === []) $schema['properties'] = new \stdClass();
        return [
            'name' => $this->nazev,
            'title' => $this->titulek,
            'description' => $this->popis,
            'inputSchema' => $schema,
            'annotations' => ['title' => $this->titulek, 'readOnlyHint' => !$this->zapis, 'destructiveHint' => $this->mazani,
                'idempotentHint' => !$this->zapis, 'openWorldHint' => $this->site],
        ];
    }
}
