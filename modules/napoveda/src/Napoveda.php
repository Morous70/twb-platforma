<?php
declare(strict_types=1);
namespace TWB\Napoveda;

/**
 * Nápověda: stránky jsou Markdown soubory ve složce <slozka>/<sekce>/<id>.md, pořadí a názvy sekcí jsou v <slozka>/_obsah.json.
 * Každá stránka začíná hlavičkou mezi dvojicemi „---“ (titulek, sekce, admin, kontext, souvisi, klicova).
 */
final class Napoveda
{
    /** @var array{sections: list<array<string, mixed>>, pages: array<string, array<string, mixed>>, context: array<string, string>}|null */
    private ?array $obsah = null;

    public function __construct(private string $slozka) {}

    /** Rozdělí soubor na hlavičku a text. @return array{0: array<string, string>, 1: string} */
    public static function rozdel(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $meta = [];
        $body = $raw;
        if (preg_match('/\A---\n(.*?)\n---\n?(.*)\z/s', $raw, $m)) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^([a-z]+):\s*(.*)$/u', trim($line), $kv)) $meta[$kv[1]] = trim($kv[2]);
            }
            $body = $m[2];
        }
        return [$meta, ltrim($body, "\n")];
    }

    /** Obsah nápovědy: sekce, skupiny a stránky (se jménem a příznakem „pro správce“). */
    public function obsah(): array
    {
        if ($this->obsah !== null) return $this->obsah;
        $json = json_decode((string)@file_get_contents($this->slozka . '/_obsah.json'), true);
        if (!is_array($json)) return $this->obsah = ['sections' => [], 'pages' => [], 'context' => []];
        $pages = [];
        $sections = [];
        foreach ($json['sekce'] ?? [] as $s) {
            $groups = isset($s['skupiny']) ? $s['skupiny'] : [['titulek' => null, 'stranky' => $s['stranky'] ?? []]];
            $outGroups = [];
            foreach ($groups as $g) {
                $items = [];
                foreach ($g['stranky'] as $id) {
                    $file = $this->slozka . "/{$s['id']}/$id.md";
                    $meta = is_file($file) ? self::rozdel((string)file_get_contents($file))[0] : [];
                    $p = ['id' => $id, 'title' => $meta['titulek'] ?? $id, 'admin' => ($meta['admin'] ?? 'ne') === 'ano', 'section' => $s['id'], 'file' => $file];
                    $pages[$id] = $p;
                    $items[] = ['id' => $id, 'title' => $p['title'], 'admin' => $p['admin']];
                }
                $outGroups[] = ['title' => $g['titulek'], 'pages' => $items];
            }
            $sections[] = ['id' => $s['id'], 'title' => $s['titulek'], 'groups' => $outGroups];
        }
        return $this->obsah = ['sections' => $sections, 'pages' => $pages, 'context' => $json['kontext'] ?? []];
    }

    /** Pořadí všech stránek tak, jak jdou za sebou v obsahu. @return list<string> */
    public function poradi(): array { return array_map('strval', array_keys($this->obsah()['pages'])); }

    /** Jedna stránka: hlavička, text v Markdownu, související a sousední stránky. Neznámé ID vrátí null. */
    public function stranka(string $id): ?array
    {
        $toc = $this->obsah();
        $p = $toc['pages'][$id] ?? null;
        if (!$p || !is_file($p['file'])) return null;
        [$meta, $body] = self::rozdel((string)file_get_contents($p['file']));
        $order = $this->poradi();
        $i = array_search($id, $order, true);
        $rel = [];
        foreach (array_filter(array_map('trim', explode(',', $meta['souvisi'] ?? ''))) as $r) {
            if (isset($toc['pages'][$r])) $rel[] = ['id' => $r, 'title' => $toc['pages'][$r]['title']];
        }
        $nb = fn($k) => isset($order[$k]) ? ['id' => $order[$k], 'title' => $toc['pages'][$order[$k]]['title']] : null;
        return [
            'id' => $id, 'title' => $p['title'], 'section' => $p['section'], 'admin' => $p['admin'],
            'md' => $body, 'related' => $rel, 'prev' => $i > 0 ? $nb($i - 1) : null, 'next' => $nb($i + 1),
        ];
    }

    /** Text bez formátování pro hledání. */
    public static function cistyText(string $md): string
    {
        $t = (string)preg_replace('/\[(?:SNÍMEK|OVĚŘIT)[^\]]*\]/u', ' ', $md);
        $t = (string)preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $t);
        $t = (string)preg_replace('/^[\s|:-]*-{3,}[\s|:-]*$/mu', ' ', $t);   // oddělovače tabulek
        $t = (string)preg_replace('/[*`>#|_]+/u', ' ', $t);
        return trim((string)preg_replace('/\s+/u', ' ', $t));
    }

    /** Vše, co klient potřebuje: obsah, mapa kontextů a index pro vyhledávání. */
    public function balicek(): array
    {
        $toc = $this->obsah();
        $index = [];
        foreach ($toc['pages'] as $id => $p) {
            if (!is_file($p['file'])) continue;
            [$meta, $body] = self::rozdel((string)file_get_contents($p['file']));
            $index[] = ['id' => $id, 'title' => $p['title'], 'keys' => $meta['klicova'] ?? '', 'text' => self::cistyText($body)];
        }
        return ['sections' => $toc['sections'], 'context' => $toc['context'], 'index' => $index];
    }

    /**
     * Kontrola obsahu (pro testy a vydání): [chyby, počet nevyřešených [OVĚŘIT], počet značek [SNÍMEK]].
     * Chyba = chybějící soubor nebo hlavička, rozbitý odkaz, stránka mimo obsah, neznámý kontext.
     * @return array{0: list<string>, 1: int, 2: int}
     */
    public function zkontroluj(): array
    {
        $toc = $this->obsah();
        $errors = [];
        $verify = 0;
        $shots = 0;
        foreach ($toc['pages'] as $id => $p) {
            if (!is_file($p['file'])) { $errors[] = "$id: chybí soubor " . basename(dirname($p['file'])) . "/$id.md"; continue; }
            [$meta, $body] = self::rozdel((string)file_get_contents($p['file']));
            foreach (['titulek', 'sekce', 'admin'] as $k) if (($meta[$k] ?? '') === '') $errors[] = "$id: chybí hlavička „{$k}“";
            if (($meta['sekce'] ?? $p['section']) !== $p['section']) $errors[] = "$id: hlavička sekce ({$meta['sekce']}) nesouhlasí se složkou ({$p['section']})";
            if (!in_array($meta['admin'] ?? 'ne', ['ano', 'ne'], true)) $errors[] = "$id: admin musí být ano nebo ne";
            if (!empty($meta['kontext']) && !isset($toc['context'][$meta['kontext']])) $errors[] = "$id: neznámý kontext „{$meta['kontext']}“";
            foreach (array_filter(array_map('trim', explode(',', $meta['souvisi'] ?? ''))) as $r) {
                if (!isset($toc['pages'][$r])) $errors[] = "$id: souvisi odkazuje na neexistující stránku „{$r}“";
            }
            if (preg_match_all('/\[[^\]]*\]\(([^)\s]+)\)/u', $body, $m)) {
                foreach ($m[1] as $href) {
                    if (preg_match('~^(mailto:|https?://)~i', $href)) continue;
                    if (!isset($toc['pages'][$href])) $errors[] = "$id: odkaz na neexistující stránku „{$href}“";
                }
            }
            $verify += (int)preg_match_all('/\[OVĚŘIT/u', $body);
            $shots += (int)preg_match_all('/\[SNÍMEK:/u', $body);
            if (!preg_match('/^# .+/m', $body)) $errors[] = "$id: chybí nadpis „# …“";
        }
        foreach ($toc['context'] as $k => $id) if (!isset($toc['pages'][$id])) $errors[] = "kontext „{$k}“ vede na neexistující stránku „{$id}“";
        foreach (glob($this->slozka . '/*/*.md') ?: [] as $f) {
            $id = basename($f, '.md');
            if (!isset($toc['pages'][$id])) $errors[] = "$id: soubor " . basename(dirname($f)) . "/$id.md není v _obsah.json";
        }
        return [$errors, $verify, $shots];
    }
}
