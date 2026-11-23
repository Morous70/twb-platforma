<?php
declare(strict_types=1);
namespace MediaGalerie;

/**
 * Kategorie: pojmenované složky médií. Smazání kategorie média nemaže, jen je odpojí.
 * Pořadí (0.8.8): sloupec web_order, nastavuje se přetažením myší na stránce Kategorie a platí v administraci, v API i na veřejném webu.
 * Dokud pořadí nikdo nezměnil (všude 0), řadí se česky podle názvu.
 */
final class Kategorie
{
    public function __construct(private App $app) {}

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM categories WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Kategorie nenalezena.');
    }

    /** Kategorie s počtem médií (bez archivu) a velikostí v nastaveném pořadí (při shodě česky podle názvu). @return list<array<string, mixed>> */
    public function seznam(): array
    {
        $r = $this->app->db->q('SELECT c.*, COUNT(m.id) AS pocet, COALESCE(SUM(m.size), 0) AS velikost FROM categories c
            LEFT JOIN media m ON m.category_id = c.id AND m.archived_at IS NULL GROUP BY c.id')->fetchAll();
        usort($r, [self::class, 'porovnej']);
        return $r;
    }

    /** Řazení kategorií: pořadí (web_order), pak česky podle názvu, pak id. @param array<string, mixed> $a @param array<string, mixed> $b */
    public static function porovnej(array $a, array $b): int
    {
        return ((int)($a['web_order'] ?? 0) <=> (int)($b['web_order'] ?? 0)) ?: strcmp(\TWB\Tagy\Tagy::klicRazeni($a['name']), \TWB\Tagy\Tagy::klicRazeni($b['name'])) ?: ((int)$a['id'] <=> (int)$b['id']);
    }

    /**
     * Nové pořadí kategorií (přetažení myší): $ids v požadovaném pořadí dostanou web_order 1, 2, 3…;
     * kategorie, které v seznamu chybí (mezitím přidané v jiném okně), se zařadí za ně v dosavadním pořadí.
     * @param list<int|string> $ids
     */
    public function nastavPoradi(array $ids): void
    {
        $existujici = array_map(fn($k) => (int)$k['id'], $this->seznam());
        $poradi = [];
        foreach ($ids as $id) {
            $id = (int)$id;
            if (in_array($id, $existujici, true) && !in_array($id, $poradi, true)) $poradi[] = $id;
        }
        if (!$poradi) throw new Chyba('Chybí pořadí kategorií.');
        foreach ($existujici as $id) if (!in_array($id, $poradi, true)) $poradi[] = $id;
        $this->app->db->transakce(function () use ($poradi) {
            foreach ($poradi as $i => $id) $this->app->db->q('UPDATE categories SET web_order = ? WHERE id = ?', [$i + 1, $id]);
        });
        $this->app->log('info', 'Změněno pořadí kategorií.');
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
            // nová kategorie na konec, pokud už pořadí někdo nastavil (jinak zůstane 0 = podle názvu)
            $max = (int)$this->app->db->q('SELECT COALESCE(MAX(web_order), 0) FROM categories')->fetchColumn();
            $this->app->db->q('INSERT INTO categories (name, note, web_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', [$nazev, $pozn, $max > 0 ? $max + 1 : 0, time(), time()]);
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
