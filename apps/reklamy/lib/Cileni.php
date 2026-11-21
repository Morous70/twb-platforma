<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * Cílení kampaně (JSON v campaigns.targeting) a frekvence (campaigns.cap_impressions za cap_hours).
 * Pravidla: dny v týdnu, hodiny od–do (i přes půlnoc), zařízení, jazyk prohlížeče, stránka (obsahuje / neobsahuje), odkud návštěvník přišel.
 * Prázdné pravidlo = bez omezení. Když pravidlo něco vyžaduje a údaj chybí (např. stránka u vložení na serveru), kampaň se nezobrazí.
 */
final class Cileni
{
    public const DNY = [1 => 'Po', 2 => 'Út', 3 => 'St', 4 => 'Čt', 5 => 'Pá', 6 => 'So', 7 => 'Ne'];
    public const ZARIZENI = ['pocitac' => 'Počítač', 'mobil' => 'Mobil', 'tablet' => 'Tablet'];
    public const MAX_VZORU = 50;

    /** Pravidla z formuláře kampaně, null = bez cílení. @param array<string, mixed> $v @return ?array<string, mixed> */
    public static function zFormulare(array $v): ?array
    {
        $p = [];
        $dny = array_values(array_unique(array_filter(array_map('intval', (array)($v['cil_dny'] ?? [])), fn($d) => isset(self::DNY[$d]))));
        sort($dny);
        if ($dny && count($dny) < 7) $p['dny'] = $dny;
        $od = Vstup::cislo($v, 'cil_od', 'Hodiny od', 0, 23);
        $do = Vstup::cislo($v, 'cil_do', 'Hodiny do', 1, 24);
        if ($od !== null || $do !== null) {
            $od ??= 0; $do ??= 24;
            if ($od === $do) throw new Chyba('Hodiny od a do se nesmí rovnat.');
            if (!($od === 0 && $do === 24)) { $p['od'] = $od; $p['do'] = $do; }
        }
        $zar = array_values(array_intersect(array_keys(self::ZARIZENI), (array)($v['cil_zarizeni'] ?? [])));
        if ($zar && count($zar) < count(self::ZARIZENI)) $p['zarizeni'] = $zar;
        $jaz = array_values(array_unique(array_filter(array_map(fn($x) => strtolower(trim($x)), preg_split('/[\s,;]+/', (string)($v['cil_jazyky'] ?? '')) ?: []))));
        foreach ($jaz as $j) if (!preg_match('/^[a-z]{2,3}$/', $j)) throw new Chyba("Jazyk „{$j}“ zadejte dvoupísmenným kódem (cs, sk, en …).");
        if ($jaz) $p['jazyky'] = $jaz;
        foreach (['stranky' => 'cil_stranky', 'bez_stranek' => 'cil_bez_stranek', 'odkud' => 'cil_odkud'] as $k => $pole) {
            $vz = self::vzory((string)($v[$pole] ?? ''));
            if ($vz) $p[$k] = $vz;
        }
        return $p ?: null;
    }

    /** @return list<string> */
    private static function vzory(string $s): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $s) ?: [] as $r) {
            $r = mb_strtolower(trim($r));
            if ($r === '') continue;
            if (mb_strlen($r) > 300) throw new Chyba('Adresa v cílení může mít nejvýš 300 znaků.');
            $out[] = $r;
        }
        $out = array_values(array_unique($out));
        if (count($out) > self::MAX_VZORU) throw new Chyba('V cílení může být nejvýš ' . self::MAX_VZORU . ' adres v jednom poli.');
        return $out;
    }

    /** Hodnoty pro formulář. @return array<string, mixed> */
    public static function doFormulare(?string $json): array
    {
        $p = json_decode((string)$json, true) ?: [];
        return [
            'cil_dny' => $p['dny'] ?? [], 'cil_od' => $p['od'] ?? null, 'cil_do' => $p['do'] ?? null,
            'cil_zarizeni' => $p['zarizeni'] ?? [], 'cil_jazyky' => implode(', ', $p['jazyky'] ?? []),
            'cil_stranky' => implode("\n", $p['stranky'] ?? []), 'cil_bez_stranek' => implode("\n", $p['bez_stranek'] ?? []), 'cil_odkud' => implode("\n", $p['odkud'] ?? []),
        ];
    }

    /** Splňuje okamžik a návštěvník pravidla? @param ?array<string, mixed> $p */
    public static function splnuje(?array $p, Kontext $k): bool
    {
        if (!$p) return true;
        $t = $k->ted();
        if (!empty($p['dny']) && !in_array((int)date('N', $t), $p['dny'], true)) return false;
        if (isset($p['od'], $p['do'])) {
            $h = (int)date('G', $t);
            $ok = $p['od'] < $p['do'] ? ($h >= $p['od'] && $h < $p['do']) : ($h >= $p['od'] || $h < $p['do']);
            if (!$ok) return false;
        }
        if (!empty($p['zarizeni']) && !in_array($k->zarizeni(), $p['zarizeni'], true)) return false;
        if (!empty($p['jazyky']) && !in_array($k->jazyk(), $p['jazyky'], true)) return false;
        $stranka = mb_strtolower($k->stranka);
        if (!empty($p['stranky']) && !self::obsahuje($stranka, $p['stranky'])) return false;
        if (!empty($p['bez_stranek']) && $stranka !== '' && self::obsahuje($stranka, $p['bez_stranek'])) return false;
        if (!empty($p['odkud']) && !self::obsahuje(mb_strtolower($k->odkud), $p['odkud'])) return false;
        return true;
    }

    /** @param list<string> $vzory */
    private static function obsahuje(string $s, array $vzory): bool
    {
        if ($s === '') return false;
        foreach ($vzory as $v) if (str_contains($s, $v)) return true;
        return false;
    }

    /** Popis cílení slovy (přehled kampaně). */
    public static function popis(?string $json, ?int $cap = null, ?int $capHodin = null): string
    {
        $p = json_decode((string)$json, true) ?: [];
        $c = [];
        if (!empty($p['dny'])) $c[] = implode(', ', array_map(fn($d) => self::DNY[$d], $p['dny']));
        if (isset($p['od'], $p['do'])) $c[] = "{$p['od']}–{$p['do']} h";
        if (!empty($p['zarizeni'])) $c[] = implode(', ', array_map(fn($z) => mb_strtolower(self::ZARIZENI[$z]), $p['zarizeni']));
        if (!empty($p['jazyky'])) $c[] = 'jazyk ' . implode(', ', $p['jazyky']);
        if (!empty($p['stranky'])) $c[] = 'stránky ' . count($p['stranky']);
        if (!empty($p['bez_stranek'])) $c[] = 'mimo ' . count($p['bez_stranek']) . ' stránek';
        if (!empty($p['odkud'])) $c[] = 'odkud ' . count($p['odkud']);
        if ($cap) $c[] = "nejvýš {$cap}× za " . ($capHodin ?: 24) . ' h';
        return $c ? implode(' · ', $c) : 'bez omezení';
    }
}
