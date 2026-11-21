<?php
declare(strict_types=1);
namespace Reklamy;

/** Minimální XML-RPC (bez knihoven): čtení volání a zápis odpovědi nebo chyby. */
final class XmlRpc
{
    /** @return array{0: string, 1: list<mixed>} název metody a parametry */
    public static function ctiVolani(string $xml): array
    {
        if ($xml === '' || strlen($xml) > 1048576) throw new \InvalidArgumentException('Prázdné nebo příliš velké volání.');
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) throw new \InvalidArgumentException('Volání nesmí obsahovat DOCTYPE.');
        $d = new \DOMDocument();
        $stare = libxml_use_internal_errors(true);
        $ok = $d->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($stare);
        if (!$ok || !$d->documentElement || $d->documentElement->nodeName !== 'methodCall') throw new \InvalidArgumentException('Neplatné XML-RPC volání.');
        $metoda = '';
        $parametry = [];
        foreach ($d->documentElement->childNodes as $n) {
            if ($n->nodeName === 'methodName') $metoda = trim($n->textContent);
            if ($n->nodeName === 'params') {
                foreach ($n->childNodes as $p) {
                    if ($p->nodeName !== 'param') continue;
                    $v = self::prvni($p, 'value');
                    $parametry[] = $v ? self::hodnota($v) : null;
                }
            }
        }
        if ($metoda === '') throw new \InvalidArgumentException('Chybí název metody.');
        return [$metoda, $parametry];
    }

    private static function prvni(\DOMNode $n, string $jmeno): ?\DOMElement
    {
        foreach ($n->childNodes as $c) if ($c instanceof \DOMElement && $c->nodeName === $jmeno) return $c;
        return null;
    }

    private static function hodnota(\DOMElement $v): mixed
    {
        $typ = null;
        foreach ($v->childNodes as $c) if ($c instanceof \DOMElement) { $typ = $c; break; }
        if ($typ === null) return $v->textContent;   // bez typu = řetězec
        $t = $typ->textContent;
        switch ($typ->nodeName) {
            case 'int': case 'i4': case 'i8': return (int)trim($t);
            case 'boolean': return trim($t) === '1';
            case 'double': return (float)trim($t);
            case 'string': case 'dateTime.iso8601': return $t;
            case 'base64': return (string)base64_decode(trim($t), true);
            case 'nil': return null;
            case 'struct':
                $out = [];
                foreach ($typ->childNodes as $m) {
                    if (!$m instanceof \DOMElement || $m->nodeName !== 'member') continue;
                    $nazev = self::prvni($m, 'name');
                    $hod = self::prvni($m, 'value');
                    if ($nazev) $out[trim($nazev->textContent)] = $hod ? self::hodnota($hod) : null;
                }
                return $out;
            case 'array':
                $out = [];
                $data = self::prvni($typ, 'data');
                if ($data) foreach ($data->childNodes as $h) if ($h instanceof \DOMElement && $h->nodeName === 'value') $out[] = self::hodnota($h);
                return $out;
        }
        return $t;
    }

    private static function zapisHodnotu(mixed $v): string
    {
        if (is_bool($v)) return '<boolean>' . ($v ? 1 : 0) . '</boolean>';
        if (is_int($v)) return "<int>$v</int>";
        if (is_float($v)) return '<double>' . $v . '</double>';
        if ($v === null) return '<string></string>';
        if (is_array($v)) {
            if (array_is_list($v)) return '<array><data>' . implode('', array_map(fn($x) => '<value>' . self::zapisHodnotu($x) . '</value>', $v)) . '</data></array>';
            $s = '<struct>';
            foreach ($v as $k => $x) $s .= '<member><name>' . htmlspecialchars((string)$k, ENT_XML1, 'UTF-8') . '</name><value>' . self::zapisHodnotu($x) . '</value></member>';
            return $s . '</struct>';
        }
        return '<string>' . htmlspecialchars((string)$v, ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8') . '</string>';
    }

    public static function odpoved(mixed $v): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodResponse><params><param><value>" . self::zapisHodnotu($v) . "</value></param></params></methodResponse>\n";
    }

    public static function chyba(int $kod, string $zprava): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodResponse><fault><value>" . self::zapisHodnotu(['faultCode' => $kod, 'faultString' => $zprava]) . "</value></fault></methodResponse>\n";
    }

    /** Volání pro klienta (generátor kódu a testy). @param list<mixed> $parametry */
    public static function volani(string $metoda, array $parametry): string
    {
        $s = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<methodCall><methodName>" . htmlspecialchars($metoda, ENT_XML1, 'UTF-8') . '</methodName><params>';
        foreach ($parametry as $p) $s .= '<param><value>' . self::zapisHodnotu($p) . '</value></param>';
        return $s . "</params></methodCall>\n";
    }
}
