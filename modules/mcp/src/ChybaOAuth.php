<?php
declare(strict_types=1);
namespace TWB\Mcp;

/** Chyba OAuth: kód podle RFC 6749 (invalid_grant …), popis a stav HTTP. Aplikace ji vrátí jako JSON {error, error_description}. */
final class ChybaOAuth extends \RuntimeException
{
    public function __construct(public readonly string $kod, string $popis, public readonly int $http = 400)
    {
        parent::__construct($popis);
    }

    /** @return array{error:string, error_description:string} */
    public function json(): array { return ['error' => $this->kod, 'error_description' => $this->getMessage()]; }
}
