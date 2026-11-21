<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

/** Dekodér CBOR: čísla, řetězce bajtů a textu, pole, mapy, true/false/null (stačí pro WebAuthn). */
final class Cbor
{
    public static function dekoduj(string $s, int &$o): mixed
    {
        if ($o >= strlen($s)) throw new \RuntimeException('Neplatná data (CBOR).');
        $b = ord($s[$o++]);
        $typ = $b >> 5;
        $info = $b & 31;
        if ($typ === 7) {
            if ($info === 20) return false;
            if ($info === 21) return true;
            if ($info === 22 || $info === 23) return null;
            throw new \RuntimeException('Nepodporovaná hodnota CBOR.');
        }
        if ($info < 24) {
            $n = $info;
        } elseif ($info <= 27) {
            $delka = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$info];
            if ($o + $delka > strlen($s)) throw new \RuntimeException('Neplatná data (CBOR).');
            $n = 0;
            for ($i = 0; $i < $delka; $i++) $n = ($n << 8) | ord($s[$o + $i]);
            $o += $delka;
            if ($n < 0) throw new \RuntimeException('Příliš velké číslo (CBOR).');
        } else {
            throw new \RuntimeException('Nepodporovaná délka CBOR.');
        }
        switch ($typ) {
            case 0: return $n;
            case 1: return -1 - $n;
            case 2:
            case 3:
                if ($o + $n > strlen($s)) throw new \RuntimeException('Neplatná data (CBOR).');
                $v = substr($s, $o, $n);
                $o += $n;
                return $v;
            case 4:
                if ($n > 1000) throw new \RuntimeException('Příliš dlouhé pole (CBOR).');
                $a = [];
                for ($i = 0; $i < $n; $i++) $a[] = self::dekoduj($s, $o);
                return $a;
            case 5:
                if ($n > 1000) throw new \RuntimeException('Příliš dlouhá mapa (CBOR).');
                $m = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = self::dekoduj($s, $o);
                    $m[is_scalar($k) ? $k : serialize($k)] = self::dekoduj($s, $o);
                }
                return $m;
        }
        throw new \RuntimeException('Nepodporovaný typ CBOR.');
    }
}
