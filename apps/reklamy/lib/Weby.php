<?php
declare(strict_types=1);
namespace Reklamy;

/** Weby, na kterých se reklamy zobrazují (každý má své zóny). */
final class Weby
{
    public function __construct(private App $app) {}

    /** @return list<array<string, mixed>> */
    public function seznam(): array
    {
        return $this->app->db->q('SELECT s.*, (SELECT COUNT(*) FROM zones z WHERE z.site_id = s.id) AS zones FROM sites s ORDER BY s.name')->fetchAll();
    }

    /** @return array<string, mixed> */
    public function radek(int $id): array
    {
        return $this->app->db->q('SELECT * FROM sites WHERE id = ?', [$id])->fetch() ?: throw new Chyba('Web nenalezen.');
    }

    /** @param array<string, mixed> $v */
    public function uloz(array $v, ?int $id = null): int
    {
        $d = [
            'name' => Vstup::text($v, 'name', 'Název'),
            'url' => Vstup::adresa($v, 'url', 'Adresa webu'),
            'note' => Vstup::text($v, 'note', 'Poznámka', 5000, false),
            'updated_at' => time(),
        ];
        $db = $this->app->db;
        if ($id === null) {
            $d['created_at'] = $d['updated_at'];
            $db->q('INSERT INTO sites (name, url, note, updated_at, created_at) VALUES (?, ?, ?, ?, ?)', array_values($d));
            $id = $db->posledniId();
            $this->app->log('info', "Nový web: {$d['name']}");
            return $id;
        }
        $this->radek($id);
        $db->q('UPDATE sites SET name = ?, url = ?, note = ?, updated_at = ? WHERE id = ?', [...array_values($d), $id]);
        return $id;
    }

    public function smaz(int $id): void
    {
        $w = $this->radek($id);
        $this->app->db->q('DELETE FROM sites WHERE id = ?', [$id]);
        $this->app->log('info', "Web smazán i se zónami: {$w['name']}");
    }
}
