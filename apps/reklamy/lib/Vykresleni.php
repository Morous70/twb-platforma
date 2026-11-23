<?php
declare(strict_types=1);
namespace Reklamy;

/**
 * HTML banneru pro vložení do stránky, adresy doručování (obrázek, proklik, obsah HTML banneru) a makra.
 *
 * Makra v HTML bannerech (jako v Revive):
 *   {clickurl}       adresa prokliku; připojená adresa se použije jako cíl ({clickurl}https://…), jinak cíl banneru
 *   {clickurl_enc}   totéž zakódované pro parametr adresy
 *   {targeturl}      cílová adresa banneru
 *   {random} {cachebuster} {timestamp} {bannerid} {zoneid} {campaignid} {width} {height}
 * Proměnné modulu data ({datum}, {svatek}, {Skupina:parametr}, vlastní …) se dosadí před makry; neznámé zůstanou beze změny.
 */
final class Vykresleni
{
    public function __construct(private App $app, private Dorucovani $dorucovani) {}

    /** Adresa souboru doručování (složka d/). @param array<string, scalar> $parametry */
    public function adresa(string $soubor, array $parametry = []): string
    {
        return $this->app->url('d/' . $soubor) . ($parametry ? '?' . http_build_query($parametry) : '');
    }

    /**
     * Adresa prokliku (podepsaná trojice banner–zóna–kampaň). Cíl se bere z banneru, ne z adresy.
     * Kampaň (`campaign_id` řádku z doručování) určuje, které kampani se proklik započítá.
     * Zařízení zobrazení (`zarizeni`) jde do parametru d (bez podpisu – jen popisek pro statistiky), aby se proklik započítal stejnému zařízení.
     */
    public function klik(array $banner, int $zona): string
    {
        $b = (int)$banner['id'];
        $c = (int)($banner['campaign_id'] ?? 0);
        return $this->adresa('klik.php', ['b' => $b, 'z' => $zona, 'c' => $c, 'k' => $this->dorucovani->podpis("klik|$b|$zona|$c")] + self::zarizeniOdkazu($banner));
    }

    /** Parametr d se zařízením zobrazení (prázdné pole, když zařízení neznáme). @return array<string, string> */
    private static function zarizeniOdkazu(array $banner): array
    {
        $d = (string)($banner['zarizeni'] ?? '');
        return isset(Cileni::ZARIZENI[$d]) ? ['d' => $d] : [];
    }

    /** Zařízení z parametru d odkazu (pro dokument HTML banneru, který dál vytváří odkazy na proklik). @param array<string, mixed> $get */
    public static function zarizeniZOdkazu(array $get): string
    {
        $d = $get['d'] ?? '';
        return is_string($d) && isset(Cileni::ZARIZENI[$d]) ? $d : '';
    }

    /**
     * Ověří podepsaný odkaz doručování (druh klik nebo html) z parametrů b, z, c, k. Odkazy ze starších verzí (bez c) platí dál
     * a počítají se kampani, pro kterou banner vznikl. Vrací [zóna, kampaň] nebo null.
     * @param array<string, mixed> $get
     * @return ?array{0:int, 1:int}
     */
    public function overOdkaz(string $druh, array $get): ?array
    {
        $b = (string)($get['b'] ?? '');
        $z = (string)($get['z'] ?? '');
        $c = $get['c'] ?? null;
        if (!ctype_digit($b) || !ctype_digit($z)) return null;
        if (is_string($c) && ctype_digit($c)) {
            return $this->dorucovani->overPodpis("$druh|$b|$z|$c", $get['k'] ?? null) ? [(int)$z, (int)$c] : null;
        }
        if ($c !== null || !$this->dorucovani->overPodpis("$druh|$b|$z", $get['k'] ?? null)) return null;
        return [(int)$z, $this->dorucovani->hlavniKampan((int)$b)];
    }

    /**
     * Adresa obrázku banneru (galerie a slidy přes d/b.php, jinak vlastní adresa).
     * $x2: obrázek pro displeje s vyšší hustotou (2×), null když ho banner nemá.
     */
    public function obrazek(array $banner, bool $x2 = false): ?string
    {
        if ($x2) {
            if (!Bannery::maRetinu($banner)) return null;
            if ($banner['type'] === 'external') return (string)$banner['image2x_url'];
            $v = substr((string)$this->app->db->q('SELECT md5 FROM media WHERE id = ?', [$banner['media2x_id']])->fetchColumn(), 0, 8);
            return $this->adresa('b.php', ['b' => (int)$banner['id'], 'v' => $v, 'x' => 2]);
        }
        if ($banner['type'] === 'external') return (string)$banner['image_url'];
        $v = $banner['type'] === 'slide' ? $this->app->slideBannery()->klic($banner)
            : substr((string)$this->app->db->q('SELECT md5 FROM media WHERE id = ?', [$banner['media_id']])->fetchColumn(), 0, 8);
        return $this->adresa('b.php', ['b' => (int)$banner['id'], 'v' => $v]);
    }

    /** Údaje balíčku HTML5 banneru. @return array{zip:string, index:string} */
    public static function balicek(array $banner): array
    {
        $c = json_decode((string)($banner['content'] ?? ''), true);
        if (!is_array($c) || !is_string($c['zip'] ?? null) || !is_string($c['index'] ?? null)) throw new Chyba('Banner nemá balíček HTML5.');
        return $c;
    }

    /** Adresa HTML dokumentu banneru (pro iframe). HTML5: hlavní soubor balíčku, clickTag dostane podle podpisu. */
    public function dokument(array $banner, int $zona): string
    {
        $b = (int)$banner['id'];
        $c = (int)($banner['campaign_id'] ?? 0);
        $p = ['b' => $b, 'z' => $zona, 'c' => $c, 'k' => $this->dorucovani->podpis("html|$b|$zona|$c")] + self::zarizeniOdkazu($banner) + ['cb' => random_int(1, PHP_INT_MAX)];
        if ($banner['type'] === 'html5') {
            $c = self::balicek($banner);
            return $this->adresa('h5.php/' . $c['zip'] . '/' . implode('/', array_map('rawurlencode', explode('/', $c['index']))), $p);
        }
        return $this->adresa('b.php', $p);
    }

    private static function h(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Odkaz kolem obsahu banneru (bez cílové adresy se proklik nepočítá a odkaz chybí). */
    private function odkaz(array $banner, int $zona, string $obsah): string
    {
        if (empty($banner['target_url'])) return $obsah;
        return '<a href="' . self::h($this->klik($banner, $zona)) . '" target="' . self::h($banner['target'] ?: '_blank') . '" rel="sponsored noopener">' . $obsah . '</a>';
    }

    /** HTML banneru pro vložení do cizí stránky (obrázek s odkazem nebo iframe s HTML banneru). */
    public function html(array $banner, int $zona): string
    {
        $w = (int)$banner['width'];
        $h = (int)$banner['height'];
        if (in_array($banner['type'], Bannery::OBRAZKOVE, true)) {
            // obrázek 2×: prohlížeč s hustším displejem si ho vybere sám (srcset), rozměr zůstává rozměrem banneru
            $x2 = $this->obrazek($banner, true);
            $img = '<img src="' . self::h($this->obrazek($banner)) . '"' . ($x2 !== null ? ' srcset="' . self::h($this->obrazek($banner)) . ' 1x, ' . self::h($x2) . ' 2x"' : '')
                . ' width="' . $w . '" height="' . $h . '" alt="' . self::h($banner['alt'] ?? '')
                . '" style="border:0;display:block;max-width:100%;height:auto">';
            return $this->odkaz($banner, $zona, $img);
        }
        return '<iframe src="' . self::h($this->dokument($banner, $zona)) . '" width="' . $w . '" height="' . $h
            . '" style="border:0;overflow:hidden;display:block;max-width:100%" scrolling="no" frameborder="0" title="' . self::h($banner['name']) . '"></iframe>';
    }

    /** Dosadí makra do HTML banneru. */
    public function makra(string $html, array $banner, int $zona): string
    {
        $klik = $this->klik($banner, $zona) . '&cil=';
        $nahodne = (string)random_int(100000000, 999999999);
        return strtr($html, [
            '{clickurl}' => $klik,
            '{clickurl_enc}' => rawurlencode($klik),
            '{targeturl}' => (string)($banner['target_url'] ?? ''),
            '{random}' => $nahodne, '{cachebuster}' => $nahodne,
            '{timestamp}' => (string)time(),
            '{bannerid}' => (string)(int)$banner['id'], '{zoneid}' => (string)$zona, '{campaignid}' => (string)(int)$banner['campaign_id'],
            '{width}' => (string)(int)$banner['width'], '{height}' => (string)(int)$banner['height'],
        ]);
    }

    /**
     * Celý HTML dokument banneru (obsah iframe): dosazené proměnné, makra a písma z modulu pisma, pokud je kód jmenuje (font-family).
     * Úplný dokument zůstane, jinak se kód obalí dokumentem bez okrajů.
     */
    public function dokumentHtml(array $banner, int $zona, ?\DateTimeImmutable $t = null): string
    {
        $zdroj = (string)$banner['html'];
        [$html] = $this->app->dokument()->dosad($zdroj, $t ?? new \DateTimeImmutable());
        $html = $this->makra($html, $banner, $zona);
        if (!preg_match('~<html[\s>]~i', $html)) {
            $cil = self::h($banner['target'] ?: '_blank');
            $html = "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><base target=\"$cil\">"
                . '<style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}</style></head><body>' . $html . '</body></html>';
        }
        return \TWB\Html\Dokument::vlozDoHlavicky($html, $this->app->dokument()->pismaCss($zdroj));
    }

    /** Hlavní soubor HTML5 banneru s clickTag (proklik na cíl banneru přes počítání prokliků). */
    public function html5Index(array $banner, string $html, ?int $zona): string
    {
        $klik = $zona !== null && !empty($banner['target_url']) ? $this->klik($banner, $zona) : (string)($banner['target_url'] ?? '');
        return Html5::sClickTagem($html, $klik);
    }

    /** Smí proklik HTML banneru vést na tuto adresu? Jen http(s) na doménu cíle banneru nebo adresa uvedená přímo v HTML banneru. */
    public static function povolenyCil(array $banner, string $cil): bool
    {
        if (!preg_match('~^https?://~i', $cil) || filter_var($cil, FILTER_VALIDATE_URL) === false) return false;
        $host = strtolower((string)parse_url($cil, PHP_URL_HOST));
        if ($host === '') return false;
        if (!empty($banner['target_url']) && strtolower((string)parse_url((string)$banner['target_url'], PHP_URL_HOST)) === $host) return true;
        $zdroj = (string)($banner['html'] ?? '') . ' ' . (string)($banner['content'] ?? '');
        return $zdroj !== ' ' && (str_contains($zdroj, $cil) || str_contains(html_entity_decode($zdroj, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $cil));
    }

    /** HTML pro zónu bez banneru (náhradní HTML zóny, jinak nic). */
    public static function nahradni(array $zona): string { return (string)($zona['fallback'] ?? ''); }
}
