<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Správa štítků (tagů) médií – stránka Štítky (0.8.8): seznam s počty, přejmenování, sloučení více štítků do jednoho a smazání.
 * Štítky leží jako JSON v media.tags; samotné úpravy dělá modul tagy (Tagy::prejmenuj sloučí, když cílový název už existuje).
 * Velikost písmen se nerozlišuje („Léto“ a „léto“ je jeden štítek).
 */
final class Stitky
{
    public function __construct(private App $app) {}

    private function tagy(): \TWB\Tagy\Tagy { return $this->app->galerie()->tagy(); }

    /**
     * Všechny štítky seřazené česky: název, počet médií mimo archiv a v archivu.
     * @return list<array{nazev: string, pocet: int, archiv: int}>
     */
    public function seznam(string $hledat = ''): array
    {
        $pocty = [];
        foreach ($this->app->db->q('SELECT tags, archived_at FROM media WHERE tags IS NOT NULL')->fetchAll() as $r) {
            foreach (array_unique(array_map([\TWB\Tagy\Tagy::class, 'klic'], Media::tagy($r))) as $k) {
                $pocty[$k] ??= [0, 0];
                $pocty[$k][$r['archived_at'] ? 1 : 0]++;
            }
        }
        $h = Hledani::normalizuj(trim($hledat));
        $out = [];
        foreach ($this->tagy()->vsechny() as $k => $t) {
            if ($h !== '' && !str_contains(Hledani::normalizuj($t['name']), $h)) continue;
            $out[] = ['nazev' => $t['name'], 'pocet' => $pocty[$k][0] ?? 0, 'archiv' => $pocty[$k][1] ?? 0];
        }
        return $out;
    }

    /** Přejmenuje štítek u všech médií; když nový název už existuje, štítky se sloučí. Vrací počet změněných médií. */
    public function prejmenuj(string $z, string $na): int
    {
        try {
            $n = $this->tagy()->prejmenuj($z, $na);
        } catch (\InvalidArgumentException $e) {
            throw new Chyba($e->getMessage());
        }
        $this->app->log('info', "Štítek „{$z}“ přejmenován na „{$na}“ ({$n} médií).");
        return $n;
    }

    /**
     * Sloučí vybrané štítky do jednoho (cíl může být jeden z nich, existující jiný, nebo nový název).
     * Vrací počet změněných médií (médium se započítá jednou, i když mělo víc sloučených štítků).
     * @param list<string> $zdroje
     */
    public function slouc(array $zdroje, string $cil): int
    {
        try {
            $cil = $this->tagy()->vycisti($cil) ?? throw new Chyba('Zadejte název štítku, do kterého se mají vybrané sloučit.');
        } catch (\InvalidArgumentException $e) {
            throw new Chyba($e->getMessage());
        }
        $zname = $this->tagy()->vsechny();
        $ck = \TWB\Tagy\Tagy::klic($cil);
        $zdroje = array_values(array_unique(array_filter(array_map(fn($t) => \TWB\Tagy\Tagy::klic(trim((string)$t)), $zdroje), fn($k) => isset($zname[$k]))));
        if (count($zdroje) + (isset($zname[$ck]) && !in_array($ck, $zdroje, true) ? 1 : 0) < 2) throw new Chyba('Vyberte aspoň dva štítky ke sloučení (nebo jeden a cílový existující štítek).');
        $pred = $this->otisky();
        // cíl v zápisu existujícího štítku, jinak jak ho uživatel napsal; nejdřív přejmenovat cíl samotný (změna velikosti písmen)
        $this->app->db->transakce(function () use ($zdroje, $cil, $ck, $zname) {
            if (isset($zname[$ck]) && $zname[$ck]['name'] !== $cil) $this->tagy()->prejmenuj($zname[$ck]['name'], $cil);
            foreach ($zdroje as $k) if ($k !== $ck) $this->tagy()->prejmenuj($zname[$k]['name'], $cil);
        });
        $n = count(array_diff_assoc($this->otisky(), $pred));
        $nazvy = implode(', ', array_map(fn($k) => $zname[$k]['name'], $zdroje));
        $this->app->log('info', "Štítky {$nazvy} sloučeny do „{$cil}“ ({$n} médií).");
        return $n;
    }

    /** Smaže štítek u všech médií (média zůstanou). Vrací počet změněných médií. */
    public function smaz(string $nazev): int
    {
        $n = $this->tagy()->smaz($nazev);
        if ($n === 0) throw new Chyba('Štítek nenalezen.');
        $this->app->log('info', "Smazán štítek „{$nazev}“ ({$n} médií).");
        return $n;
    }

    /** @return array<int, string> id média => JSON štítků */
    private function otisky(): array
    {
        return $this->app->db->q('SELECT id, tags FROM media WHERE tags IS NOT NULL')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
