<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

/** Base64 pro URL (bez doplňku „=“). */
final class Base64Url
{
    public static function koduj(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    public static function dekoduj(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($r === false) throw new \RuntimeException('Neplatná data.');
        return $r;
    }
}
