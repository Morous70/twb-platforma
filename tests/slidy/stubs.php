<?php
declare(strict_types=1);
// Společné zástupné funkce pro porovnání modulu slidy s původním kódem (proměnné a podmínky).

function stub_vars(): array { return ['datum' => '21. 11. 2026', 'jmeno' => 'Karel Čapek', 'cislo' => '42', 'cisla' => '7 14 21 28 35 49', 'dalsi' => '3', 'prazdne' => '']; }

/** @return array{0:string,1:list<string>} */
function stub_resolve(string $text, DateTimeImmutable $t): array
{
    $unknown = []; $v = stub_vars();
    $out = preg_replace_callback('/\{\{?\s*([a-z_]+)\s*\}?\}/u', function ($m) use ($v, &$unknown) {
        if (array_key_exists($m[1], $v)) return $v[$m[1]];
        $unknown[] = $m[1];
        return $m[0];
    }, $text);
    return [$out, array_values(array_unique($unknown))];
}

function stub_conds(array $clauses, DateTimeImmutable $t): bool
{
    foreach ($clauses as $c) {
        $l = stub_resolve($c['l'], $t)[0]; $r = stub_resolve($c['r'], $t)[0];
        $ok = match ($c['op']) {
            '=' => $l === $r, '!=' => $l !== $r, '>' => (float)$l > (float)$r, '<' => (float)$l < (float)$r,
            '>=' => (float)$l >= (float)$r, '<=' => (float)$l <= (float)$r,
            'contains' => $r === '' || str_contains($l, $r), 'empty' => $l === '', 'notempty' => $l !== '',
            default => false,
        };
        if (!$ok) return false;
    }
    return true;
}
