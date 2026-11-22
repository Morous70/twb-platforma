<?php
declare(strict_types=1);
namespace MediaGalerie;

/** Kampaně: pojmenované složky médií. Smazání kampaně média nemaže, jen je odpojí. */
final class Kampane
{
    public function __construct(private App $app) {}

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM campaigns WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Kampaň nenalezena.');
    }

    /** Kampaně s počtem médií (bez archivu) a velikostí, seřazené česky podle názvu. @return list<array<string, mixed>> */
    public function seznam(): array
    {
        $r = $this->app->db->q('SELECT c.*, COUNT(m.id) AS pocet, COALESCE(SUM(m.size), 0) AS velikost FROM campaigns c
            LEFT JOIN media m ON m.campaign_id = c.id AND m.archived_at IS NULL GROUP BY c.id')->fetchAll();
        usort($r, fn($a, $b) => strcmp(\TWB\Tagy\Tagy::klicRazeni($a['name']), \TWB\Tagy\Tagy::klicRazeni($b['name'])) ?: $a['id'] <=> $b['id']);
        return $r;
    }

    /** Volby pro výběr: [id => název]. @return array<int, string> */
    public function volby(): array
    {
        $out = [];
        foreach ($this->seznam() as $k) $out[(int)$k['id']] = (string)$k['name'];
        return $out;
    }

    /** Uloží novou (id null) nebo upravenou kampaň. @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $nazev = Vstup::text($v, 'name', 'Název', 200);
        $pozn = Vstup::text($v, 'note', 'Poznámka', 2000, false);
        $dupl = $this->app->db->q('SELECT id FROM campaigns WHERE name = ? COLLATE NOCASE AND id IS NOT ?', [$nazev, $id])->fetchColumn();
        if ($dupl) throw new Chyba("Kampaň „{$nazev}“ už existuje.");
        if ($id === null) {
            $this->app->db->q('INSERT INTO campaigns (name, note, created_at, updated_at) VALUES (?, ?, ?, ?)', [$nazev, $pozn, time(), time()]);
            $id = $this->app->db->posledniId();
            $this->app->log('info', "Nová kampaň: {$nazev}");
        } else {
            $this->radek($id);
            $this->app->db->q('UPDATE campaigns SET name = ?, note = ?, updated_at = ? WHERE id = ?', [$nazev, $pozn, time(), $id]);
        }
        return $id;
    }

    /** Smaže kampaň; média zůstanou bez kampaně. Vrací počet odpojených médií. */
    public function smaz(int $id): int
    {
        $k = $this->radek($id);
        return $this->app->db->transakce(function () use ($id, $k) {
            $n = $this->app->db->q('UPDATE media SET campaign_id = NULL WHERE campaign_id = ?', [$id])->rowCount();
            $this->app->db->q('DELETE FROM campaigns WHERE id = ?', [$id]);
            $this->app->log('info', "Smazána kampaň {$k['name']} (média bez kampaně: {$n}).");
            return $n;
        });
    }
}
