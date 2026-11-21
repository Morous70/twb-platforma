<?php
declare(strict_types=1);
namespace Reklamy;

/** Kódy pro vložení zóny na web (stejné způsoby jako v Revive). */
final class Kody
{
    public const ZPUSOBY = [
        'asyn' => ['Asynchronní JavaScript (doporučeno)', 'Nezdržuje načítání stránky; víc zón na stránce se načte jedním požadavkem a banner se neopakuje.'],
        'js' => ['JavaScript (document.write)', 'Starší způsob – banner se zapíše přímo na místo skriptu. Nepoužívejte na stránkách načítaných asynchronně.'],
        'iframe' => ['Rám (iframe)', 'Reklama je oddělená od stránky. Hodí se, když web nepovoluje cizí skripty.'],
        'odkaz' => ['Obrázek s odkazem (e-maily, bez JavaScriptu)', 'Jen obrázkové bannery. NAHODNE nahraďte stejnou jedinečnou hodnotou v obou adresách (např. ID e-mailu z rozesílacího nástroje).'],
        'okno' => ['Vyskakovací okno (popup)', 'Otevře se při prvním kliknutí na stránce (bez kliknutí ho prohlížeče blokují). kdy=ihned otevře hned, jednou=1 jen jednou za návštěvu.'],
        'php' => ['PHP na serveru (JSON)', 'Banner se načte na straně serveru, funguje i bez JavaScriptu u návštěvníka.'],
        'xmlrpc' => ['XML-RPC (PHP na serveru)', 'Kompatibilní s metodou openads.view z Revive – stávající kód pro Revive stačí přesměrovat na tuto adresu.'],
        'json' => ['JSON API', 'Pro vlastní aplikace: GET vrátí HTML banneru i jednotlivé údaje.'],
    ];

    public function __construct(private App $app) {}

    private function u(string $soubor): string { return $this->app->url('d/' . $soubor); }

    private static function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    /** Kód pro vložení zóny daným způsobem. */
    public function kod(array $zona, string $zpusob): string
    {
        $id = (int)$zona['id'];
        $zw = ($zona['width'] ?? null) !== null ? (int)$zona['width'] : 0;
        $zh = ($zona['height'] ?? null) !== null ? (int)$zona['height'] : 0;
        // rám potřebuje oba rozměry: nezadaný se vezme z bannerů zóny (největší), jinak 300×250
        [$w, $hh] = $this->app->zony()->rozmerVlozeni($zona);
        $rozmer = " width=\"$w\" height=\"$hh\"";
        switch ($zpusob) {
            case 'asyn':
                $styl = ' style="display:inline-block' . ($zw ? ";width:{$zw}px" : '') . ($zh ? ";height:{$zh}px" : '') . '"';
                return "<ins data-twbr-zona=\"$id\"$styl></ins>\n<script async src=\"" . self::h($this->u('asyn.php')) . "\"></script>";
            case 'js':
                $u = $this->u('js.php') . "?zona=$id";
                return "<script>\n(function () {\n  var u = " . json_encode($u, JSON_UNESCAPED_SLASHES) . " + '&cb=' + Math.floor(Math.random() * 1e9) + '&loc=' + encodeURIComponent(location.href)\n"
                    . "    + (document.referrer ? '&ref=' + encodeURIComponent(document.referrer) : '');\n  document.write('<scr' + 'ipt src=\"' + u + '\"></scr' + 'ipt>');\n})();\n</script>";
            case 'iframe':
                return '<iframe src="' . self::h($this->u('ram.php') . "?zona=$id") . "\"$rozmer frameborder=\"0\" scrolling=\"no\" style=\"border:0;overflow:hidden\" title=\"Reklama\" loading=\"lazy\"></iframe>";
            case 'odkaz':
                return '<a href="' . self::h($this->u('klik.php') . "?zona=$id&n=NAHODNE") . "\" target=\"_blank\" rel=\"sponsored noopener\">"
                    . '<img src="' . self::h($this->u('obr.php') . "?zona=$id&n=NAHODNE") . '"' . ($zw ? " width=\"$zw\"" : '') . ($zh ? " height=\"$zh\"" : '')
                    . ($zw || $zh ? '' : $rozmer) . ' border="0" alt="Reklama"></a>';
            case 'okno':
                [$ow, $oh] = $this->app->zony()->rozmerVlozeni($zona, 800, 600);
                return '<script src="' . self::h($this->u('okno.php') . "?zona=$id" . ($zw ? '' : "&sirka=$ow") . ($zh ? '' : "&vyska=$oh")) . '"></script>';
            case 'php':
                $u = $this->u('data.php') . "?zona=$id";
                return "<?php\n// TWB Reklamy – zóna #$id\n\$u = " . var_export($u, true) . " . '&' . http_build_query([\n"
                    . "    'loc' => (isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . (\$_SERVER['HTTP_HOST'] ?? '') . (\$_SERVER['REQUEST_URI'] ?? ''),\n"
                    . "    'ua' => \$_SERVER['HTTP_USER_AGENT'] ?? '',\n]);\n"
                    . "\$j = json_decode((string)@file_get_contents(\$u, false, stream_context_create(['http' => ['timeout' => 3]])), true);\n"
                    . "echo \$j['zony'][0]['html'] ?? '';\n";
            case 'xmlrpc':
                $u = $this->u('xmlrpc.php');
                return "<?php\n// TWB Reklamy – zóna #$id přes XML-RPC (openads.view)\n"
                    . "\$info = ['remote_addr' => \$_SERVER['REMOTE_ADDR'] ?? '', 'user_agent' => \$_SERVER['HTTP_USER_AGENT'] ?? '',\n"
                    . "    'page_url' => 'https://' . (\$_SERVER['HTTP_HOST'] ?? '') . (\$_SERVER['REQUEST_URI'] ?? ''), 'referer' => \$_SERVER['HTTP_REFERER'] ?? ''];\n"
                    . "\$m = '';\nforeach (\$info as \$k => \$v) \$m .= '<member><name>' . \$k . '</name><value><string>' . htmlspecialchars(\$v, ENT_XML1) . '</string></value></member>';\n"
                    . "\$xml = '<?xml version=\"1.0\"?><methodCall><methodName>openads.view</methodName><params>'\n"
                    . "    . '<param><value><struct>' . \$m . '</struct></value></param>'\n"
                    . "    . '<param><value><string>zone:$id</string></value></param>'\n"
                    . "    . '<param><value><int>0</int></value></param><param><value><string></string></value></param>'\n"
                    . "    . '<param><value><string></string></value></param><param><value><boolean>0</boolean></value></param>'\n"
                    . "    . '<param><value><array><data></data></array></value></param></params></methodCall>';\n"
                    . "\$odpoved = @file_get_contents(" . var_export($u, true) . ", false, stream_context_create(['http' => [\n"
                    . "    'method' => 'POST', 'header' => 'Content-Type: text/xml', 'content' => \$xml, 'timeout' => 3]]));\n"
                    . "if (\$odpoved && preg_match('~<name>html</name>\\s*<value><string>(.*?)</string>~s', \$odpoved, \$x)) echo htmlspecialchars_decode(\$x[1], ENT_QUOTES | ENT_XML1);\n";
            case 'json':
                return 'GET ' . $this->u('data.php') . "?zona=$id\n    volitelně &loc=<stránka>&ref=<odkud>&ua=<prohlížeč návštěvníka>; víc zón najednou: ?zony=$id,…\n\n"
                    . "{\"zony\": [{\"zona\": $id, \"html\": \"<a …><img …></a>\",\n  \"banner\": {\"id\": 1, \"kampan\": 1, \"typ\": \"image\", \"sirka\": " . $w . ", \"vyska\": " . $hh
                    . ", \"alt\": \"\",\n             \"klik\": \"…/d/klik.php?…\", \"obrazek\": \"…/d/b.php?…\", \"dokument\": null}}]}\n\n"
                    . "Zobrazení se započítá při každém volání. Bez banneru je \"banner\": null a \"html\" obsahuje náhradní HTML zóny.";
        }
        throw new Chyba('Neznámý způsob vložení.');
    }
}
