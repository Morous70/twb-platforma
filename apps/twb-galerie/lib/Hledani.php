<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Hledání textu v médiích („fulltext“): každé slovo dotazu se hledá v názvu, tagech, autorovi a poznámce,
 * bez ohledu na velikost písmen a diakritiku („zluty kun“ najde „Žlutý kůň“). Fráze v uvozovkách se hledá vcelku.
 * Výsledky se dají seřadit podle relevance (váhy polí níže). Bez SQLite FTS5 – to na hostingu nemusí být
 * a diakritiku neumí; galerie jednoho uživatele má tisíce, ne miliony položek, takže stačí projít řádky v PHP.
 */
final class Hledani
{
    public const MAX_SLOV = 10;
    /** Váhy shody: celý tag, slovo na začátku názvu nebo slova v názvu, kdekoli v názvu, část tagu, autor, poznámka. */
    public const VAHY = ['tag' => 5, 'nazev_slovo' => 4, 'nazev' => 3, 'tag_cast' => 3, 'autor' => 2, 'poznamka' => 1];

    private const MAPA = [
        'á' => 'a', 'ä' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ą' => 'a', 'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ď' => 'd',
        'é' => 'e', 'ě' => 'e', 'ë' => 'e', 'è' => 'e', 'ê' => 'e', 'ę' => 'e', 'í' => 'i', 'ï' => 'i', 'ì' => 'i', 'î' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ł' => 'l', 'ň' => 'n', 'ń' => 'n', 'ñ' => 'n', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ò' => 'o', 'õ' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ś' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u', 'ù' => 'u', 'û' => 'u', 'ű' => 'u',
        'ý' => 'y', 'ÿ' => 'y', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ß' => 'ss',
    ];

    /** Malá písmena bez diakritiky a se zjednodušenými mezerami. */
    public static function normalizuj(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($n)) $s = (string)preg_replace('/\p{Mn}+/u', '', $n);
        }
        $s = strtr($s, self::MAPA);   // bez rozšíření intl, a znaky, které NFD nerozloží (ł, ß)
        return trim((string)preg_replace('/\s+/u', ' ', $s));
    }

    /**
     * Dotaz → hledané výrazy (normalizované): slova oddělená mezerou, fráze v uvozovkách vcelku, bez opakování, prvních MAX_SLOV.
     * @return list<string>
     */
    public static function vyrazy(string $q): array
    {
        $out = [];
        preg_match_all('/"([^"]*)"|(\S+)/u', $q, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            $v = self::normalizuj(isset($x[2]) && $x[2] !== '' ? $x[2] : $x[1]);
            $v = trim($v, " \t,;.");
            if ($v !== '' && !in_array($v, $out, true)) $out[] = $v;
        }
        return array_slice($out, 0, self::MAX_SLOV);   // další slova se nehledají
    }

    /**
     * Skóre shody média (řádek tabulky media) s výrazy. $vse = každý výraz musí někde být (jinak null);
     * jinak stačí jeden. Null = médium nevyhovuje.
     * @param list<string> $vyrazy
     */
    public static function skore(array $r, array $vyrazy, bool $vse = true): ?int
    {
        if (!$vyrazy) return 0;
        $nazev = self::normalizuj(pathinfo((string)$r['original_name'], PATHINFO_FILENAME));
        $tagy = array_map([self::class, 'normalizuj'], Media::tagy($r));
        $autor = self::normalizuj((string)($r['author'] ?? ''));
        $pozn = self::normalizuj((string)($r['note'] ?? ''));
        $celkem = 0;
        foreach ($vyrazy as $v) {
            $b = 0;
            if (in_array($v, $tagy, true)) $b = self::VAHY['tag'];
            elseif (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($v, '/') . '/u', $nazev)) $b = self::VAHY['nazev_slovo'];
            elseif (str_contains($nazev, $v)) $b = self::VAHY['nazev'];
            else {
                foreach ($tagy as $t) if (str_contains($t, $v)) { $b = self::VAHY['tag_cast']; break; }
                if ($b === 0 && $autor !== '' && str_contains($autor, $v)) $b = self::VAHY['autor'];
                if ($b === 0 && $pozn !== '' && str_contains($pozn, $v)) $b = self::VAHY['poznamka'];
            }
            if ($b === 0 && $vse) return null;
            $celkem += $b;
        }
        return $celkem > 0 ? $celkem : null;
    }
}
