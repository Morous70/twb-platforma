<?php
declare(strict_types=1);
namespace Reklamy;

use TWB\Data\{Format, Tabulka};

/** Vlastní proměnné do textů banerů ({akce}, {kod_slevy} …): text, nebo seznam (střídá se po řádcích). Uložené v nastavení user_vars. */
final class VlastniPromenne
{
    public const TYPY = ['text' => 'Text', 'list' => 'Seznam (každý řádek zvlášť)'];

    public function __construct(private App $app) {}

    /** @return array<string, array{name:string,type:string,value:string}> */
    public function seznam(): array
    {
        $v = $this->app->vlastniPromenne();
        uasort($v, fn($a, $b) => strcoll((string)$a['name'], (string)$b['name']));
        return $v;
    }

    /** Uloží proměnnou; $puvodni = dosavadní název při přejmenování. */
    public function uloz(array $v, ?string $puvodni = null): void
    {
        $nazev = (string)Vstup::text($v, 'name', 'Název', 40);
        if (!preg_match('/^[\p{L}_][\p{L}\p{N}_]*$/u', $nazev)) throw new Chyba('Název proměnné smí obsahovat jen písmena, číslice a podtržítko (bez mezer a teček).');
        if (in_array(Format::klic($nazev), Tabulka::VYHRAZENE, true)) throw new Chyba("Název „{$nazev}“ je vyhrazený pro proměnnou kalendáře.");
        $typ = Vstup::volba($v, 'type', 'Druh', self::TYPY, 'text');
        $hodnota = str_replace(["\r\n", "\r"], "\n", (string)($v['value'] ?? ''));
        if (mb_strlen($hodnota) > 5000) throw new Chyba('Hodnota může mít nejvýš 5000 znaků.');
        $vse = $this->app->vlastniPromenne();
        $klic = mb_strtolower($nazev);
        if ($puvodni !== null) unset($vse[mb_strtolower($puvodni)]);
        elseif (isset($vse[$klic])) throw new Chyba("Proměnná „{$nazev}“ už existuje.");
        $vse[$klic] = ['name' => $nazev, 'type' => $typ, 'value' => $hodnota];
        $this->app->nastaveni()->uloz('user_vars', json_encode($vse, JSON_UNESCAPED_UNICODE));
    }

    public function smaz(string $nazev): void
    {
        $vse = $this->app->vlastniPromenne();
        unset($vse[mb_strtolower($nazev)]);
        $this->app->nastaveni()->uloz('user_vars', json_encode($vse, JSON_UNESCAPED_UNICODE));
    }
}
