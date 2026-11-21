<?php
declare(strict_types=1);
namespace TWB\Jadro;

/**
 * Zavaděč tříd. Třída TWB\<Modul>\<Název> se hledá v <složka modulů>/<modul>/src/<Název>.php
 * (název modulu malými písmeny, bez diakritiky). Podnázvy tříd (TWB\Modul\Pod\Trida) odpovídají podsložkám.
 */
final class Loader
{
    /** @var list<string> */
    private static array $koreny = [];
    private static bool $zapnuto = false;

    public static function registruj(string $korenModulu): void
    {
        $koren = rtrim($korenModulu, '/\\');
        if (!in_array($koren, self::$koreny, true)) self::$koreny[] = $koren;
        if (!self::$zapnuto) {
            spl_autoload_register([self::class, 'nacti']);
            self::$zapnuto = true;
        }
    }

    public static function nacti(string $trida): void
    {
        if (!str_starts_with($trida, 'TWB\\')) return;
        $casti = explode('\\', substr($trida, 4));
        if (count($casti) < 2) return;
        $modul = strtolower(array_shift($casti));
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $modul)) return;
        foreach ($casti as $c) if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $c)) return;
        foreach (self::$koreny as $koren) {
            $soubor = "$koren/$modul/src/" . implode('/', $casti) . '.php';
            if (is_file($soubor)) { require_once $soubor; return; }
        }
    }

    /** Jen pro testy: zapomene zaregistrované složky. */
    public static function zapomen(): void { self::$koreny = []; }
}
