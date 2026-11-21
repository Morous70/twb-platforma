<?php
declare(strict_types=1);
namespace TWB\Napoveda;

/**
 * Markdown nápovědy na HTML na serveru – stejná podmnožina jako klient assets/help.js (helpMarkdown):
 * nadpisy #–####, odstavce, odrážky a číslované seznamy (i vnořené), tabulky, citace, **tučně**, `kód`,
 * odkazy na stránky nápovědy [text](id) a na web [text](https://…), značky [OVĚŘIT …] a [SNÍMEK: …].
 * Pro aplikace, které stránky vykreslují na serveru (bez JS klienta).
 */
final class Markdown
{
    private const POLOZKA = '/^(\s*)([-*]|\d+\.)\s+(.*)$/u';

    /** @var callable(string): string */
    private $odkaz;

    /** @param ?callable(string): string $odkaz adresa stránky nápovědy podle ID (výchozí „#napoveda/<id>“ jako v help.js) */
    public function __construct(?callable $odkaz = null)
    {
        $this->odkaz = $odkaz ?? fn(string $id) => '#napoveda/' . $id;
    }

    /** Escapování shodné s esc() klienta (apostrof jako &#39;). */
    private static function h(string $s): string { return strtr($s, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']); }

    public function radek(string $s): string
    {
        $kody = [];
        $t = (string)preg_replace_callback('/`([^`]+)`/u', function ($m) use (&$kody) { $kody[] = $m[1]; return "\0" . (count($kody) - 1) . "\0"; }, $s);
        $t = self::h($t);
        $t = (string)preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', $t);
        $t = (string)preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', function ($m) {
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (preg_match('~^(mailto:|https?://)~i', $href)) return '<a href="' . self::h($href) . '" target="_blank" rel="noopener">' . $m[1] . '</a>';
            return '<a href="' . self::h(($this->odkaz)($href)) . '">' . $m[1] . '</a>';
        }, $t);
        $t = (string)preg_replace('/\[(OVĚŘIT[^\]]*)\]/u', '<span class="help-verify">[$1]</span>', $t);
        return (string)preg_replace_callback('/\0(\d+)\0/', fn($m) => '<code>' . self::h($kody[(int)$m[1]]) . '</code>', $t);
    }

    /** @param list<string> $lines @return array{0: string, 1: int} */
    private function seznam(array $lines, int $i): array
    {
        preg_match(self::POLOZKA, $lines[$i], $prvni);
        $zaklad = strlen($prvni[1]);
        $cislovany = (bool)preg_match('/\d/', $prvni[2]);
        $start = $cislovany ? (int)$prvni[2] : 1;
        $tag = $cislovany ? 'ol' : 'ul';
        $html = "<$tag" . ($cislovany && $start !== 1 ? " start=\"$start\"" : '') . '>';
        $n = count($lines);
        while ($i < $n) {
            $j = $i;
            while ($j < $n && trim($lines[$j]) === '') $j++;
            if ($j >= $n || !preg_match(self::POLOZKA, $lines[$j], $m) || strlen($m[1]) !== $zaklad || (bool)preg_match('/\d/', $m[2]) !== $cislovany) break;
            $i = $j + 1;
            $text = $m[3];
            $pod = '';
            while ($i < $n && trim($lines[$i]) !== '' && strlen($lines[$i]) - strlen(ltrim($lines[$i])) > $zaklad) {
                if (preg_match(self::POLOZKA, $lines[$i])) { [$h, $i] = $this->seznam($lines, $i); $pod .= $h; }
                else { $text .= ' ' . trim($lines[$i]); $i++; }
            }
            $html .= '<li>' . $this->radek($text) . $pod . '</li>';
        }
        return [$html . "</$tag>", $i];
    }

    /** @return list<string> */
    private static function bunky(string $r): array
    {
        $r = (string)preg_replace('/^\||\|$/', '', trim($r));
        return array_map(fn($c) => str_replace('\\|', '|', trim($c)), preg_split('/(?<!\\\\)\|/', $r) ?: []);
    }

    public function html(string $md): string
    {
        $lines = explode("\n", str_replace("\r", '', $md));
        $n = count($lines);
        $html = '';
        $i = 0;
        while ($i < $n) {
            $ln = $lines[$i];
            if (trim($ln) === '') { $i++; continue; }
            if (preg_match('/^(#{1,4})\s+(.*)$/u', $ln, $m)) { $u = strlen($m[1]); $html .= "<h$u>" . $this->radek($m[2]) . "</h$u>"; $i++; continue; }
            if (str_starts_with($ln, '>')) {
                $q = [];
                while ($i < $n && str_starts_with($lines[$i], '>')) $q[] = $this->radek((string)preg_replace('/^>\s?/', '', $lines[$i++]));
                $html .= '<blockquote>' . implode('<br>', $q) . '</blockquote>';
                continue;
            }
            if (preg_match('/^\|.*\|\s*$/', $ln) && $i + 1 < $n && preg_match('/^\|[\s:|-]+\|\s*$/', $lines[$i + 1])) {
                $hlava = self::bunky($ln);
                $i += 2;
                $html .= '<div class="help-table"><table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . $this->radek($c) . '</th>', $hlava)) . '</tr></thead><tbody>';
                while ($i < $n && preg_match('/^\|.*\|\s*$/', $lines[$i])) {
                    $html .= '<tr>' . implode('', array_map(fn($c) => '<td>' . $this->radek($c) . '</td>', self::bunky($lines[$i++]))) . '</tr>';
                }
                $html .= '</tbody></table></div>';
                continue;
            }
            if (preg_match(self::POLOZKA, $ln)) { [$h, $i] = $this->seznam($lines, $i); $html .= $h; continue; }
            $p = [];
            while ($i < $n && trim($lines[$i]) !== '' && !preg_match('/^(#{1,4}\s|>|\|.*\|\s*$)/u', $lines[$i]) && !preg_match(self::POLOZKA, $lines[$i])) $p[] = trim($lines[$i++]);
            if (preg_match('/^\[SNÍMEK:\s*(.+)\]$/u', implode(' ', $p), $m)) $html .= '<div class="help-shot"><span>📷 Snímek obrazovky</span>' . self::h($m[1]) . '</div>';
            else $html .= '<p>' . implode('<br>', array_map([$this, 'radek'], $p)) . '</p>';
        }
        return $html;
    }
}
