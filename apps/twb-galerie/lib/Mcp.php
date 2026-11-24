<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Mcp\{Server, Nastroj, ChybaNastroje};
use TWB\Licence\Licence;

/**
 * MCP server galerie (mcp.php): nástroje pro Claude, ChatGPT a další AI klienty.
 * Hlavní účel: poslat médium vytvořené AI rovnou do galerie (z adresy nebo jako base64) s názvem, tagy, kategorií,
 * autorem, licencí a údaji o AI (model, prompt). Dál hledání, detail, úprava údajů, kategorie, licence a tagy.
 * Nástroje používají stejnou logiku a kontroly jako API v1 (Api::vnitrni). Mazat médium přes MCP nejde.
 * Oprávnění: read (čtení), write (nahrávání a úpravy) – z OAuth připojení nebo z API klíče.
 */
final class Mcp
{
    /** Největší soubor poslaný jako base64 (po dekódování). Větší soubory posílejte přes url. */
    public const MAX_BASE64 = 20 * 1048576;
    public const MAX_NA_STRANKU = 50;
    /** Rozsahy oprávnění pro OAuth (první = vždy udělen). */
    public const ROZSAHY = [
        'read' => 'Prohlížet média, kategorie, tagy a licence v galerii',
        'write' => 'Nahrávat nová média, zakládat kategorie a upravovat údaje médií (mazat nemůže)',
    ];

    public function __construct(private App $app) {}

    public function server(): Server
    {
        return new Server('twb-galerie', App::NAZEV, $this->app->verze, $this->instrukce(), $this->nastroje(), $this->app->host->protokol,
            static fn(\Throwable $e): ?string => ($e instanceof \RuntimeException && !$e instanceof \PDOException) || $e instanceof \InvalidArgumentException ? $e->getMessage() : null);
    }

    public function instrukce(): string
    {
        return App::NAZEV . ' je soukromé odkladiště médií uživatele (obrázky, videa, PDF), která zatím nepoužil ve svých aplikacích. '
            . 'Média vytvořená AI pošli nástrojem nahraj_medium: soubor z veřejné adresy (url) nebo jako base64 (data_base64, do 20 MB); '
            . 'vyplň výstižný název, tagy, případně kategorii (seznam_kategorii) a u AI obsahu ai_model a ai_prompt – licence se pak nastaví na „Vygenerováno AI“. '
            . 'Existující média najdeš nástrojem hledej_media (fulltext v názvu, tazích, autorovi a poznámce), podrobnosti a odkazy dá detail_media. '
            . 'Každé médium má stránku v administraci (stranka_url) a obvykle veřejný odkaz (verejny_odkaz), který jde vložit jinam. '
            . 'Mazat média přes MCP nejde. Odpovídej česky.';
    }

    /** @return list<Nastroj> */
    public function nastroje(): array
    {
        $text = fn(string $popis, int $max = 0) => ['type' => 'string', 'description' => $popis] + ($max ? ['maxLength' => $max] : []);
        $tagy = ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 30], 'maxItems' => 10, 'description' => 'Tagy (nejvýš 10, každý do 30 znaků), např. ["AI", "podzim", "banner"]'];
        $kategorie = ['type' => ['integer', 'string'], 'description' => 'Kategorie: id nebo přesný název (seznam_kategorii). Prázdné = bez kategorie.'];
        $licence = [
            'licence_kod' => ['type' => 'string', 'enum' => array_keys(Licence::KODY), 'description' => 'Kód licence (seznam_licenci). U AI obsahu výchozí „ai“.'],
            'licence_verze' => $text('Verze licence u CC BY / CC BY-SA (např. 4.0), u „jine“ přesný název licence', 120),
            'licence_url' => $text('Odkaz na znění licence nebo zdroj (http/https)', 500),
            'autor' => $text('Autor (u AI obsahu výchozí název modelu)', 200),
        ];
        return [
            new Nastroj('nahraj_medium', 'Nahrát médium do galerie',
                'Uloží do galerie nový obrázek, video nebo PDF – typicky médium vytvořené AI. Zdroj: url (veřejná adresa http/https, galerie si soubor stáhne, až 1 GB) '
                . 'nebo data_base64 (obsah souboru v base64, do 20 MB; pak zadej i nazev s příponou nebo mime). Typ se ověří podle obsahu. '
                . 'Víc obrázků najednou: pošli ZIP (nazev nebo adresa končí na .zip, u data_base64 stačí obsah ZIP) – každý obrázek se uloží zvlášť '
                . 'a tagy, kategorie, poznámka, autor, licence i údaje o AI platí pro všechny; vrací media, preskoceno a chyby. '
                . 'Údaje: nazev, tagy, kategorie, poznamka, autor a licence. U obsahu z AI vyplň ai_model a ai_prompt: licence bude „Vygenerováno AI“, '
                . 'autor název modelu a model s promptem se uloží do poznámky (jde podle nich hledat). Vrací uložené médium s odkazy.',
                ['type' => 'object', 'properties' => [
                    'url' => $text('Veřejná adresa souboru (http/https). Použij buď url, nebo data_base64.', 2000),
                    'data_base64' => $text('Obsah souboru v base64 (i jako data:…;base64,…), nejvýš 20 MB po dekódování'),
                    'mime' => $text('Typ souboru u data_base64, když nazev nemá příponu (image/png, image/jpeg, image/webp, video/mp4, application/pdf …)', 100),
                    'nazev' => $text('Název média, ideálně s příponou (např. „Podzimní les.png“). Bez zadání podle souboru nebo metadat.', 200),
                    'tagy' => $tagy,
                    'kategorie' => $kategorie,
                    'poznamka' => $text('Poznámka (k čemu médium je, kde ho chceš použít …)', 3000),
                    'ai_model' => $text('Model, který médium vytvořil (např. „GPT Image 1“, „Midjourney v7“, „Imagen 4“)', 120),
                    'ai_prompt' => $text('Zadání (prompt), ze kterého médium vzniklo', 3000),
                ] + $licence],
                fn(array $a) => $this->nahraj($a), 'write', zapis: true, site: true),
            new Nastroj('hledej_media', 'Hledat média',
                'Seznam médií v galerii s filtry a stránkováním, nejnovější první (s q podle relevance). q hledá každé slovo v názvu, tazích, autorovi i poznámce '
                . '(bez diakritiky, fráze v uvozovkách). Vrací stručné údaje, náhled a odkazy; podrobnosti dá detail_media.',
                ['type' => 'object', 'properties' => [
                    'q' => $text('Hledaný text', 200),
                    'druh' => ['type' => 'string', 'enum' => ['image', 'video', 'pdf'], 'description' => 'Jen obrázky, videa nebo PDF'],
                    'kategorie' => ['type' => ['integer', 'string'], 'description' => 'Kategorie: id, přesný název, nebo 0 = bez kategorie'],
                    'tagy' => $tagy + ['description' => 'Jen média s těmito tagy (výchozí všechny zároveň, viz tagy_rezim)'],
                    'tagy_rezim' => ['type' => 'string', 'enum' => ['vse', 'kterykoli'], 'description' => 'vse = všechny tagy (výchozí), kterykoli = aspoň jeden'],
                    'orientace' => ['type' => 'string', 'enum' => array_keys(Media::ORIENTACE)],
                    'razeni' => ['type' => 'string', 'enum' => array_merge(array_keys(Media::RAZENI), ['relevance', 'nahodne'])],
                    'archiv' => ['type' => 'boolean', 'description' => 'true = hledat v archivu místo aktivních médií'],
                    'doporucene' => ['type' => 'boolean', 'description' => 'true = jen média označená jako doporučená (veřejný web z nich ukazuje náhodný výběr)'],
                    'strana' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Stránka (výchozí 1)'],
                    'na_stranku' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_NA_STRANKU, 'description' => 'Počet na stránku (výchozí 20, nejvýš ' . self::MAX_NA_STRANKU . ')'],
                ]],
                fn(array $a) => $this->hledej($a)),
            new Nastroj('detail_media', 'Detail média',
                'Všechny údaje jednoho média: rozměr, velikost, kategorie, tagy, poznámka, autor, licence a hotové uvedení autora (atribuce), '
                . 'veřejný odkaz, náhledy 150–1920 px, stránka v administraci. Zadej id, nebo odkaz (adresa detailu, veřejný odkaz nebo náhled z této galerie).',
                ['type' => 'object', 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'odkaz' => $text('Odkaz na médium z této galerie', 2000),
                ]],
                fn(array $a) => ['medium' => $this->medium($a)]),
            new Nastroj('uprav_medium', 'Upravit údaje média',
                'Změní údaje existujícího média: název, poznámku, kategorii, tagy (nahradí dosavadní), autora, licenci, archiv, doporučení pro veřejný web, ohnisko a celou fotku. Zadej jen pole, která se mají změnit; '
                . 'prázdný text nebo null pole vymaže. Soubor ani médium nemaže.',
                ['type' => 'object', 'required' => ['id'], 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1],
                    'nazev' => $text('Nový název', 200),
                    'poznamka' => ['type' => ['string', 'null'], 'maxLength' => 5000],
                    'kategorie' => ['type' => ['integer', 'string', 'null'], 'description' => 'id nebo název kategorie; null = bez kategorie'],
                    'tagy' => $tagy + ['description' => 'Nový seznam tagů (nahradí dosavadní)'],
                    'archiv' => ['type' => 'boolean', 'description' => 'true = přesunout do archivu, false = vrátit z archivu'],
                    'doporucena' => ['type' => 'boolean', 'description' => 'true = doporučená fotografie (veřejný web z doporučených ukazuje náhodný výběr), false = zrušit doporučení'],
                    'ohnisko' => ['type' => ['object', 'null'], 'required' => ['x', 'y'], 'additionalProperties' => false,
                        'properties' => ['x' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100], 'y' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100]],
                        'description' => 'Ohnisko obrázku v % zleva (x) a shora (y): výřezy na veřejném webu (úvodní fotka, mřížky) se drží kolem něj; null = střed'],
                    'cela_fotka' => ['type' => 'boolean', 'description' => 'true = veřejný web ukáže celou fotku (okolí doplní rozmazaná kopie), false = ořez kolem ohniska'],
                    'licence' => ['type' => ['string', 'null'], 'maxLength' => 2000, 'description' => 'Licence jako text (původní znění z metadat)'],
                ] + array_map(fn($p) => ['type' => [$p['type'], 'null']] + (isset($p['enum']) ? ['enum' => array_merge($p['enum'], [null])] : []) + $p, $licence)],
                fn(array $a) => $this->uprav($a), 'write', zapis: true),
            new Nastroj('seznam_kategorii', 'Seznam kategorií', 'Kategorie (složky) médií: id, název, poznámka a počet médií.',
                ['type' => 'object', 'properties' => []],
                fn() => ['kategorie' => array_map(fn($k) => ['id' => $k['id'], 'nazev' => $k['nazev'], 'poznamka' => $k['poznamka'], 'pocet_medii' => $k['pocet_medii']],
                    $this->api('GET', 'kategorie')['kategorie'])]),
            new Nastroj('vytvor_kategorii', 'Založit kategorii', 'Založí novou kategorii (složku) médií. Název musí být jedinečný.',
                ['type' => 'object', 'required' => ['nazev'], 'properties' => ['nazev' => $text('Název kategorie', 200), 'poznamka' => $text('Poznámka', 2000)]],
                fn(array $a) => ['kategorie' => $this->api('POST', 'kategorie', [], ['nazev' => (string)($a['nazev'] ?? ''), 'poznamka' => (string)($a['poznamka'] ?? '')])['kategorie']],
                'write', zapis: true),
            new Nastroj('seznam_licenci', 'Seznam licencí',
                'Číselník licencí médií (kód, název, vysvětlivka, zda má verzi, zda je povinné uvádět autora). Kód se zadává do licence_kod.',
                ['type' => 'object', 'properties' => []],
                fn() => ['licence' => array_map(fn($l) => array_intersect_key($l, array_flip(['kod', 'nazev', 'napoveda', 's_verzi', 'povinne_uvedeni'])), $this->api('GET', 'licence')['licence'])]),
            new Nastroj('seznam_tagu', 'Seznam tagů', 'Všechny používané tagy s počtem médií (pro jednotné označování nových médií).',
                ['type' => 'object', 'properties' => []],
                fn() => ['tagy' => $this->api('GET', 'tagy')['tagy']]),
        ];
    }

    // ---------- nástroje ----------

    /** @return array<string, mixed> */
    private function api(string $metoda, string $cesta, array $get = [], array $vstup = [], array $soubory = []): array
    {
        return (new Api($this->app))->vnitrni($metoda, $cesta, $get, $vstup, $soubory);
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function nahraj(array $a): array
    {
        $url = $this->text($a, 'url');
        $data = $this->text($a, 'data_base64');
        if (($url === '') === ($data === '')) throw new ChybaNastroje('Zadejte právě jeden zdroj: url (adresa souboru), nebo data_base64 (obsah souboru).');
        $model = $this->text($a, 'ai_model');
        $prompt = $this->text($a, 'ai_prompt');
        $ai = $model !== '' || $prompt !== '';
        $kategorie = $this->kategorie($a['kategorie'] ?? null);

        // poznámka: zadaný text + údaje o AI (do 5000 znaků, prompt se případně zkrátí)
        $poznamka = $this->text($a, 'poznamka');
        if ($ai) {
            $hlava = trim($poznamka . "\n\n" . 'Vytvořeno AI' . ($model !== '' ? " ({$model})" : '') . '.');
            $poznamka = $prompt !== '' ? $hlava . "\nPrompt: " . mb_substr($prompt, 0, max(0, 4990 - mb_strlen($hlava))) : $hlava;
        }
        $v = array_filter(['nazev' => $this->text($a, 'nazev'), 'tagy' => $this->tagy($a['tagy'] ?? null), 'kategorie' => $kategorie,
            'poznamka' => $poznamka], fn($x) => $x !== '' && $x !== null && $x !== []);

        // autor a licence se ověří předem, aby se při chybě nic neuložilo
        $upravy = [];
        $autor = $this->text($a, 'autor') ?: ($ai ? $model : '');
        if ($autor !== '') $upravy['autor'] = $autor;
        foreach (['licence_url', 'licence_verze'] as $k) if ($this->text($a, $k) !== '') $upravy[$k] = $this->text($a, $k);
        $kod = $this->text($a, 'licence_kod') ?: ($ai ? 'ai' : '');
        if ($kod !== '') $upravy['licence_kod'] = $kod;
        if (isset($upravy['licence_kod']) || isset($upravy['licence_verze'])) {
            try { Licence::vycisti($upravy['licence_kod'] ?? null, $upravy['licence_verze'] ?? null); } catch (\InvalidArgumentException $e) { throw new ChybaNastroje($e->getMessage()); }
        }
        if (isset($upravy['licence_url']) && !preg_match('~^https?://\S+$~i', $upravy['licence_url'])) throw new ChybaNastroje('licence_url musí začínat http:// nebo https://.');

        $soubory = [];
        $docasny = null;
        if ($url !== '') {
            $v['url'] = $url;
        } else {
            $docasny = $this->zBase64($data, $v, $this->text($a, 'mime'));
            $soubory['soubor'] = ['tmp_name' => $docasny, 'name' => $v['nazev'], 'error' => UPLOAD_ERR_OK, 'size' => filesize($docasny)];
        }
        try {
            $r = $this->api('POST', 'media', [], $v, $soubory);
        } finally {
            if ($docasny !== null && is_file($docasny)) @unlink($docasny);
        }
        if (!empty($r['zip'])) {
            // ZIP: autor, licence a údaje o AI platí pro každý rozbalený obrázek (mají přednost před metadaty obrázku)
            $media = [];
            $chyby = $r['chyby'];
            foreach ($r['media'] as $m) {
                if ($upravy) {
                    try { $m = $this->api('PATCH', 'media/' . $m['id'], [], $upravy)['medium']; }
                    catch (\RuntimeException $e) { $chyby[] = "{$m['nazev']}: autora a licenci se nepodařilo zapsat – " . $e->getMessage(); }
                }
                $media[] = $this->kratce($m);
            }
            $this->app->log('info', 'MCP: ZIP, nahráno ' . count($media) . ' obrázků' . ($ai ? ' (AI)' : '') . '.');
            return ['zip' => true, 'media' => $media, 'preskoceno' => $r['preskoceno'], 'chyby' => $chyby];
        }
        $m = $r['medium'];
        if ($upravy) $m = $this->api('PATCH', 'media/' . $m['id'], [], $upravy)['medium'];
        $this->app->log('info', "MCP: nahráno „{$m['nazev']}“" . ($ai ? ' (AI)' : '') . '.');
        return ['medium' => $this->kratce($m) + ['soubor' => $m['soubor']]] + ($r['poznamka_komprese'] ? ['poznamka_komprese' => $r['poznamka_komprese']] : []);
    }

    /** Uloží base64 do dočasného souboru; doplní název s příponou. Vrací cestu. @param array<string, mixed> $v */
    private function zBase64(string $data, array &$v, string $mime): string
    {
        if (preg_match('~^data:([\w.+-]+/[\w.+-]+)?(;[^,]*)?;base64,~i', $data, $m)) {
            $data = substr($data, strlen($m[0]));
            if ($mime === '' && !empty($m[1])) $mime = strtolower($m[1]);
        }
        $data = (string)preg_replace('/\s+/', '', $data);
        if (strlen($data) > intdiv(self::MAX_BASE64, 3) * 4 + 4) throw new ChybaNastroje('Soubor v data_base64 je větší než 20 MB. Pošlete ho přes url.');
        $obsah = base64_decode(strtr($data, '-_', '+/'), true);
        if ($obsah === false || $obsah === '') throw new ChybaNastroje('data_base64 není platný base64.');
        if (strlen($obsah) > self::MAX_BASE64) throw new ChybaNastroje('Soubor v data_base64 je větší než 20 MB. Pošlete ho přes url.');
        $tmp = $this->app->data . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $cesta = $tmp . '/mcp-' . bin2hex(random_bytes(8));
        if (@file_put_contents($cesta, $obsah) === false) throw new \RuntimeException('Dočasný soubor se nepodařilo uložit.');
        $nazev = (string)($v['nazev'] ?? '');
        if (pathinfo($nazev, PATHINFO_EXTENSION) === '') {
            // typ podle obsahu má přednost před deklarovaným (klient často pošle application/octet-stream)
            $zjisten = \TWB\Galerie\Galerie::zjistiMime($cesta);
            $typ = $zjisten !== null && $zjisten !== 'application/octet-stream' ? $zjisten : ($mime !== '' ? strtolower($mime) : (string)$zjisten);
            $pripona = $typ === 'application/zip' ? 'zip' : $this->app->galerie()->priponaProMime($typ);
            if ($pripona === null) { @unlink($cesta); throw new ChybaNastroje("Nepodporovaný typ souboru ({$typ}). Galerie přijímá obrázky, videa a PDF."); }
            $v['nazev'] = ($nazev !== '' ? $nazev : 'ai-' . date('Y-m-d-His')) . '.' . $pripona;
        }
        return $cesta;
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function hledej(array $a): array
    {
        $na = (int)($a['na_stranku'] ?? 20);
        if ($na < 1 || $na > self::MAX_NA_STRANKU) throw new ChybaNastroje('na_stranku musí být 1 až ' . self::MAX_NA_STRANKU . '.');
        $get = ['na_stranku' => $na, 'strana' => max(1, (int)($a['strana'] ?? 1))];
        foreach (['q', 'druh', 'tagy_rezim', 'orientace', 'razeni'] as $k) if ($this->text($a, $k) !== '') $get[$k] = $this->text($a, $k);
        if (isset($a['kategorie']) && $a['kategorie'] !== '' && $a['kategorie'] !== null) $get['kategorie'] = (string)$a['kategorie'];
        if ($t = $this->tagy($a['tagy'] ?? null)) $get['tagy'] = implode(',', $t);
        if (!empty($a['archiv'])) $get['archiv'] = '1';
        if (!empty($a['doporucene'])) $get['doporucene'] = '1';
        $r = $this->api('GET', 'media', $get);
        return ['celkem' => $r['celkem'], 'strana' => $r['strana'], 'stran' => $r['stran'], 'media' => array_map(fn($m) => $this->kratce($m), $r['media'])];
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function medium(array $a): array
    {
        if (!empty($a['id'])) return $this->api('GET', 'media/' . (int)$a['id'])['medium'];
        if ($this->text($a, 'odkaz') !== '') return $this->api('GET', 'media/odkaz', ['url' => $this->text($a, 'odkaz')])['medium'];
        throw new ChybaNastroje('Zadejte id média, nebo odkaz.');
    }

    /** @param array<string, mixed> $a @return array<string, mixed> */
    private function uprav(array $a): array
    {
        $id = (int)($a['id'] ?? 0);
        if ($id < 1) throw new ChybaNastroje('Zadejte id média.');
        unset($a['id']);
        if (array_key_exists('kategorie', $a)) $a['kategorie'] = $this->kategorie($a['kategorie']);
        if (array_key_exists('tagy', $a)) $a['tagy'] = $this->tagy($a['tagy']);
        if (!$a) throw new ChybaNastroje('Zadejte aspoň jedno pole ke změně.');
        $m = $this->api('PATCH', "media/{$id}", [], $a)['medium'];
        return ['medium' => $this->kratce($m)];
    }

    // ---------- pomůcky ----------

    /** Stručný popis média pro seznamy a výsledek nahrání. @param array<string, mixed> $m @return array<string, mixed> */
    public function kratce(array $m): array
    {
        $poz = $m['poznamka'] !== null && mb_strlen((string)$m['poznamka']) > 300 ? mb_substr((string)$m['poznamka'], 0, 300) . '…' : $m['poznamka'];
        return [
            'id' => $m['id'], 'nazev' => $m['nazev'], 'druh' => $m['druh'], 'mime' => $m['mime'], 'velikost' => $m['velikost'],
            'rozmer' => $m['sirka'] !== null && $m['vyska'] !== null ? "{$m['sirka']}×{$m['vyska']}" : null,
            'kategorie' => $m['kategorie']['nazev'] ?? null, 'tagy' => $m['tagy'], 'poznamka' => $poz,
            'autor' => $m['autor'], 'licence' => $m['licence_nazev'], 'atribuce' => $m['atribuce']['text'] ?? null,
            'archivovano' => $m['archivovano'] !== null, 'doporucena' => $m['doporucena'] ?? false, 'vytvoreno' => $m['vytvoreno'],
            'ohnisko' => $m['ohnisko'] ?? null, 'cela_fotka' => $m['cela_fotka'] ?? false,
            'stranka_url' => $m['stranka_url'], 'verejny_odkaz' => $m['verejny_odkaz'],
            'nahled' => $m['nahledy']['640'] ?? null,
        ];
    }

    private function text(array $a, string $k): string
    {
        $v = $a[$k] ?? null;
        if ($v === null) return '';
        if (!is_string($v) && !is_int($v) && !is_float($v)) throw new ChybaNastroje("{$k} musí být text.");
        return trim((string)$v);
    }

    /** Tagy jako pole nebo text s čárkami. @return list<string> */
    private function tagy(mixed $t): array
    {
        if ($t === null || $t === '' || $t === []) return [];
        if (is_string($t)) $t = explode(',', $t);
        if (!is_array($t)) throw new ChybaNastroje('tagy musí být pole textů.');
        return array_values(array_filter(array_map(fn($x) => trim(is_scalar($x) ? (string)$x : ''), $t), 'strlen'));
    }

    /** Kategorie podle id nebo názvu (velikost písmen nerozhoduje). Null = bez kategorie. */
    private function kategorie(mixed $k): ?int
    {
        if ($k === null || $k === '' || $k === 0 || $k === '0') return null;
        if (is_int($k) || (is_string($k) && ctype_digit(trim($k)))) return (int)$this->app->kategorie()->radek((int)$k)['id'];
        if (!is_string($k)) throw new ChybaNastroje('kategorie musí být id nebo název.');
        foreach ($this->app->kategorie()->volby() as $id => $nazev) if (mb_strtolower($nazev) === mb_strtolower(trim($k))) return (int)$id;
        throw new ChybaNastroje("Kategorie „{$k}“ neexistuje. Seznam dá seznam_kategorii, novou založí vytvor_kategorii.");
    }
}
