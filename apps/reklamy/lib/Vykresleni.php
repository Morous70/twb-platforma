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
 */
final class Vykresleni
{
    public function __construct(private App $app, private Dorucovani $dorucovani) {}

    /** Adresa souboru doručování (složka d/). @param array<string, scalar> $parametry */
    public function adresa(string $soubor, array $parametry = []): string
    {
        return $this->app->url('d/' . $soubor) . ($parametry ? '?' . http_build_query($parametry) : '');
    }

    /** Adresa prokliku (podepsaná dvojice banner–zóna). Cíl se bere z banneru, ne z adresy. */
    public function klik(array $banner, int $zona): string
    {
        $b = (int)$banner['id'];
        return $this->adresa('klik.php', ['b' => $b, 'z' => $zona, 'k' => $this->dorucovani->podpis("klik|$b|$zona")]);
    }

    /** Adresa obrázku banneru (galerie přes d/b.php, jinak vlastní adresa). */
    public function obrazek(array $banner): string
    {
        if ($banner['type'] === 'external') return (string)$banner['image_url'];
        $v = substr((string)$this->app->db->q('SELECT md5 FROM media WHERE id = ?', [$banner['media_id']])->fetchColumn(), 0, 8);
        return $this->adresa('b.php', ['b' => (int)$banner['id'], 'v' => $v]);
    }

    /** Adresa HTML dokumentu banneru (pro iframe). */
    public function dokument(array $banner, int $zona): string
    {
        $b = (int)$banner['id'];
        return $this->adresa('b.php', ['b' => $b, 'z' => $zona, 'k' => $this->dorucovani->podpis("html|$b|$zona"), 'cb' => random_int(1, PHP_INT_MAX)]);
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
        if (in_array($banner['type'], ['image', 'external'], true)) {
            $img = '<img src="' . self::h($this->obrazek($banner)) . '" width="' . $w . '" height="' . $h . '" alt="' . self::h($banner['alt'] ?? '')
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

    /** Celý HTML dokument banneru (obsah iframe). Úplný dokument zůstane, jen se dosadí makra. */
    public function dokumentHtml(array $banner, int $zona): string
    {
        $html = $this->makra((string)$banner['html'], $banner, $zona);
        if (preg_match('~<html[\s>]~i', $html)) return $html;
        $cil = self::h($banner['target'] ?: '_blank');
        return "<!doctype html>\n<html lang=\"cs\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><base target=\"$cil\">"
            . '<style>html,body{margin:0;padding:0;overflow:hidden;background:transparent}</style></head><body>' . $html . '</body></html>';
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
