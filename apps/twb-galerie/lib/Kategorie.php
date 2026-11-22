<?php
declare(strict_types=1);
namespace MediaGalerie;

/** Kategorie: pojmenované složky médií. Smazání kategorie média nemaže, jen je odpojí. */
final class Kategorie
{
    public function __construct(private App $app) {}

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM categories WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Kategorie nenalezena.');
    }

    /** Kategorie s počtem médií (bez archivu) a velikostí, seřazené česky podle názvu. @return list<array<string, mixed>> */
    public function seznam(): array
    {
        $r = $this->app->db->q('SELECT c.*, COUNT(m.id) AS pocet, COALESCE(SUM(m.size), 0) AS velikost FROM categories c
            LEFT JOIN media m ON m.category_id = c.id AND m.archived_at IS NULL GROUP BY c.id')->fetchAll();
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

    /** Uloží novou (id null) nebo upravenou kategorii. @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $nazev = Vstup::text($v, 'name', 'Název', 200);
        $pozn = Vstup::text($v, 'note', 'Poznámka', 2000, false);
        $dupl = $this->app->db->q('SELECT id FROM categories WHERE name = ? COLLATE NOCASE AND id IS NOT ?', [$nazev, $id])->fetchColumn();
        if ($dupl) throw new Chyba("Kategorie „{$nazev}“ už existuje.");
        if ($id === null) {
            $this->app->db->q('INSERT INTO categories (name, note, created_at, updated_at) VALUES (?, ?, ?, ?)', [$nazev, $pozn, time(), time()]);
            $id = $this->app->db->posledniId();
            $this->app->log('info', "Nová kategorie: {$nazev}");
        } else {
            $this->radek($id);
            $this->app->db->q('UPDATE categories SET name = ?, note = ?, updated_at = ? WHERE id = ?', [$nazev, $pozn, time(), $id]);
        }
        return $id;
    }

    /** Smaže kategorii; média zůstanou bez kategorie. Vrací počet odpojených médií. */
    public function smaz(int $id): int
    {
        $k = $this->radek($id);
        return $this->app->db->transakce(function () use ($id, $k) {
            $n = $this->app->db->q('UPDATE media SET category_id = NULL WHERE category_id = ?', [$id])->rowCount();
            $this->app->db->q('DELETE FROM categories WHERE id = ?', [$id]);
            $this->app->log('info', "Smazána kategorie {$k['name']} (média bez kategorie: {$n}).");
            return $n;
        });
    }
}
