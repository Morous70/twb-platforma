<?php
declare(strict_types=1);
namespace MediaGalerie;

use TWB\Galerie\Nahravani;
use TWB\Licence\Licence;

/**
 * JSON API (api.php/v1/…). Klíč v hlavičce `Authorization: Bearer tgk_…` (nebo `X-Api-Key`).
 * Klíč s oprávněním read smí jen GET, write vše. Odpověď: {"ok": true, …} nebo {"ok": false, "chyba": "…"}.
 * Popis pro uživatele: help/sprava/api.md (musí odpovídat této třídě, hlídá test).
 */
final class Api
{
    public const VERZE = 'v1';
    public const MAX_NA_STRANKU = 200;

    /** Soubor k vydání místo JSON (GET /media/{id}/soubor). @var ?array{cesta:string, mime:string, nazev:string, md5:string} */
    public ?array $soubor = null;

    /** Hlavičky odpovědi navíc (limit požadavků, Retry-After). @var array<string, string> */
    public array $hlavicky = [];

    /** Nejvýš id v jednom výběru (?id=1,2,3) a tagů v ?tagy=. */
    public const MAX_ID = 200;
    public const MAX_TAGU = 10;

    public function __construct(private App $app) {}

    /**
     * Zpracuje požadavek. $soubory = $_FILES, $telo = surové tělo (JSON).
     * @param array<string, mixed> $get @param array<string, mixed> $post @param array<string, mixed> $soubory
     * @return array{0:int, 1:array<string, mixed>}
     */
    public function zpracuj(string $metoda, string $cesta, ?string $klic, array $get = [], array $post = [], array $soubory = [], string $telo = ''): array
    {
        $klice = $this->app->apiKlice();
        if (($s = $klice->zamceno()) > 0) return [429, ['ok' => false, 'chyba' => "Příliš mnoho neplatných klíčů, zkuste to za {$s} s."]];
        $k = $klic !== null && $klic !== '' ? $klice->over($klic) : null;
        if (!$k) {
            if ($klic !== null && $klic !== '') $klice->neuspech();
            return [401, ['ok' => false, 'chyba' => 'Chybí nebo neplatí API klíč (hlavička Authorization: Bearer …).']];
        }
        [$zbyva, $pockej] = $klice->pocitej($k);
        if ($zbyva >= 0) $this->hlavicky += ['X-RateLimit-Limit' => (string)$klice->limit(), 'X-RateLimit-Remaining' => (string)$zbyva];
        if ($pockej > 0) {
            $this->hlavicky['Retry-After'] = (string)$pockej;
            return [429, ['ok' => false, 'chyba' => "Příliš mnoho požadavků s tímto klíčem (nejvýš {$klice->limit()} za minutu), zkuste to za {$pockej} s."]];
        }
        $metoda = strtoupper($metoda);
        if ($metoda !== 'GET' && $metoda !== 'HEAD' && $k['scope'] !== 'write') return [403, ['ok' => false, 'chyba' => 'Klíč má jen oprávnění ke čtení.']];
        $json = [];
        if ($telo !== '' && $post === [] && $soubory === []) {
            $json = json_decode($telo, true);
            if (!is_array($json)) return [400, ['ok' => false, 'chyba' => 'Tělo požadavku není platný JSON objekt.']];
        }
        $vstup = $json + $post;
        $c = array_values(array_filter(explode('/', trim($cesta, '/')), 'strlen'));
        if (($c[0] ?? '') !== self::VERZE) return [404, ['ok' => false, 'chyba' => 'Neznámá cesta. API je na adrese api.php/' . self::VERZE . '/…']];
        array_shift($c);
        try {
            return $this->smeruj($metoda === 'HEAD' ? 'GET' : $metoda, $c, $get, $vstup, $soubory, $k);
        } catch (Chyba | \InvalidArgumentException $e) {
            return [str_contains($e->getMessage(), 'nenalezen') ? 404 : 400, ['ok' => false, 'chyba' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return [400, ['ok' => false, 'chyba' => $e->getMessage()]];
        }
    }

    /**
     * Volání API zevnitř aplikace (MCP server) bez klíče a bez limitu požadavků: stejné cesty, kontroly vstupu a odpovědi jako api.php/v1.
     * Oprávnění ověřuje volající. Chyba = výjimka Chyba se stejným textem, jaký by vrátilo API.
     * @param array<string, mixed> $get @param array<string, mixed> $vstup @param array<string, mixed> $soubory
     * @return array<string, mixed>
     */
    public function vnitrni(string $metoda, string $cesta, array $get = [], array $vstup = [], array $soubory = []): array
    {
        $c = array_values(array_filter(explode('/', trim($cesta, '/')), 'strlen'));
        try {
            [$kod, $data] = $this->smeruj(strtoupper($metoda), $c, $get, $vstup, $soubory, ['name' => 'MCP', 'scope' => 'write']);
        } catch (\InvalidArgumentException $e) {
            throw new Chyba($e->getMessage());
        }
        if ($kod >= 400) throw new Chyba((string)($data['chyba'] ?? 'Požadavek se nepodařilo provést.'));
        return $data;
    }

    /** @param list<string> $c @return array{0:int, 1:array<string, mixed>} */
    private function smeruj(string $m, array $c, array $get, array $v, array $soubory, array $klic): array
    {
        $id = isset($c[1]) && ctype_digit($c[1]) ? (int)$c[1] : null;
        $cesta = implode('/', array_map(fn($x) => ctype_digit($x) ? '{id}' : $x, $c));
        $nepovolena = [405, ['ok' => false, 'chyba' => "Metoda {$m} tu není povolená."]];
        switch ($cesta) {
            case '':
                return $m === 'GET' ? [200, ['ok' => true, 'aplikace' => App::NAZEV, 'verze' => $this->app->verze, 'api' => self::VERZE,
                    'klic' => $klic['name'], 'opravneni' => $klic['scope']]] : $nepovolena;
            case 'media':
                if ($m === 'GET') return [200, $this->seznam($get)];
                if ($m === 'POST') return [201, ['ok' => true] + $this->nahraj($v, $soubory, null)];
                return $nepovolena;
            case 'media/odkaz':
                if ($m !== 'GET') return $nepovolena;
                return [200, ['ok' => true, 'medium' => $this->medium($this->podleOdkazu((string)($get['url'] ?? '')))]];
            case 'media/{id}':
                if ($m === 'GET') return [200, ['ok' => true, 'medium' => $this->medium($this->app->media()->radek($id))]];
                if ($m === 'PATCH' || $m === 'POST') return [200, ['ok' => true, 'medium' => $this->uprav($id, $v)]];
                if ($m === 'DELETE') {
                    $this->app->media()->radek($id);
                    $this->app->galerie()->smaz([$id]);
                    $this->app->media()->uklidNahledy();
                    return [200, ['ok' => true, 'smazano' => $id]];
                }
                return $nepovolena;
            case 'media/{id}/soubor':
                if ($m === 'GET') {
                    $r = $this->app->media()->radek($id);
                    $sirka = isset($get['sirka']) && $get['sirka'] !== '' ? (int)$get['sirka'] : null;
                    if ($sirka !== null && !in_array($sirka, Media::NAHLEDY, true)) throw new Chyba('sirka musí být ' . implode(', ', Media::NAHLEDY) . '.');
                    $varianta = $sirka !== null && empty($get['original']) ? $this->app->media()->varianta($r, $sirka) : null;
                    $this->soubor = $varianta !== null
                        ? ['cesta' => $varianta, 'mime' => 'image/jpeg', 'nazev' => pathinfo((string)$r['original_name'], PATHINFO_FILENAME) . "-{$sirka}.jpg", 'md5' => "w{$sirka}" . $r['md5']]
                        : $this->app->galerie()->soubor($id, !empty($get['original'])) + ['md5' => (string)$r['md5']];
                    return [200, ['ok' => true]];
                }
                if ($m === 'POST' || $m === 'PUT') return [200, ['ok' => true] + $this->nahraj($v, $soubory, $id)];
                return $nepovolena;
            case 'media/{id}/odkaz':
                if ($m === 'POST') { $this->app->media()->novyOdkaz($id); return [200, ['ok' => true, 'medium' => $this->medium($this->app->media()->radek($id))]]; }
                if ($m === 'DELETE') { $this->app->media()->zrusOdkaz($id); return [200, ['ok' => true, 'medium' => $this->medium($this->app->media()->radek($id))]]; }
                return $nepovolena;
            case 'kategorie':
                if ($m === 'GET') return [200, ['ok' => true, 'kategorie' => array_map([$this, 'kategorie'], $this->app->kategorie()->seznam())]];
                if ($m === 'POST') {
                    $nove = $this->app->kategorie()->uloz(['name' => $v['nazev'] ?? '', 'note' => $v['poznamka'] ?? '']);
                    return [201, ['ok' => true, 'kategorie' => $this->kategoriePodleId($nove)]];
                }
                return $nepovolena;
            case 'kategorie/{id}':
                if ($m === 'GET') return [200, ['ok' => true, 'kategorie' => $this->kategoriePodleId($id)]];
                if ($m === 'PATCH' || $m === 'POST') {
                    $k = $this->app->kategorie()->radek($id);
                    $this->app->kategorie()->uloz(['name' => $v['nazev'] ?? $k['name'], 'note' => array_key_exists('poznamka', $v) ? $v['poznamka'] : $k['note']], $id);
                    return [200, ['ok' => true, 'kategorie' => $this->kategoriePodleId($id)]];
                }
                if ($m === 'DELETE') return [200, ['ok' => true, 'smazano' => $id, 'odpojeno_medii' => $this->app->kategorie()->smaz($id)]];
                return $nepovolena;
            case 'licence':
                if ($m !== 'GET') return $nepovolena;
                return [200, ['ok' => true, 'licence' => array_map(fn($kod, $nazev) => ['kod' => $kod, 'nazev' => $nazev, 'napoveda' => Licence::NAPOVEDA[$kod],
                    's_verzi' => in_array($kod, Licence::S_VERZI, true), 'povinne_uvedeni' => Licence::povinneUvedeni($kod), 'odkaz' => Licence::odkaz($kod)],
                    array_keys(Licence::KODY), Licence::KODY)]];
            case 'tagy':
                if ($m !== 'GET') return $nepovolena;
                return [200, ['ok' => true, 'tagy' => array_values(array_map(fn($t) => ['nazev' => $t['name'], 'pocet' => $t['count']], $this->app->galerie()->tagy()->vsechny()))]];
        }
        return [404, ['ok' => false, 'chyba' => 'Neznámá cesta.']];
    }

    // ---------- čtení ----------

    /** @return array<string, mixed> */
    private function seznam(array $get): array
    {
        $na = (int)($get['na_stranku'] ?? Media::NA_STRANKU);
        if ($na < 1 || $na > self::MAX_NA_STRANKU) throw new Chyba('na_stranku musí být 1 až ' . self::MAX_NA_STRANKU . '.');
        $razeni = (string)($get['razeni'] ?? '');
        $f = [
            'q' => (string)($get['q'] ?? ''),
            'druh' => (string)($get['druh'] ?? ''),
            'kategorie' => $this->kategorieFiltr($get['kategorie'] ?? null),
            'tag' => (string)($get['tag'] ?? ''),
            'tagy' => $this->seznamZTextu($get['tagy'] ?? null, self::MAX_TAGU, 'tagy'),
            'tagy_vse' => !in_array($get['tagy_rezim'] ?? 'vse', ['kterykoli', 'any'], true),
            'q_vse' => !in_array($get['q_rezim'] ?? 'vse', ['kterykoli', 'any'], true),
            'archiv' => !empty($get['archiv']) && $get['archiv'] !== '0',
            'doporucene' => !empty($get['doporucene']) && $get['doporucene'] !== '0',
            'razeni' => $razeni,
            'seme' => (string)($get['seme'] ?? ''),
            'orientace' => (string)($get['orientace'] ?? ''),
            'min_sirka' => $this->cislo($get, 'min_sirka'),
            'min_vyska' => $this->cislo($get, 'min_vyska'),
            'id' => array_map('intval', $this->seznamZTextu($get['id'] ?? null, self::MAX_ID, 'id')),
            'strana' => (int)($get['strana'] ?? 1),
        ];
        if ($f['druh'] !== '' && !isset(Media::DRUHY[$f['druh']])) throw new Chyba('druh musí být image, video nebo pdf.');
        if ($razeni !== '' && !in_array($razeni, ['nahodne', 'relevance'], true) && !isset(Media::RAZENI[$razeni])) throw new Chyba('razeni musí být ' . implode(', ', array_keys(Media::RAZENI)) . ', nahodne nebo relevance.');
        if ($f['orientace'] !== '' && !isset(Media::ORIENTACE[$f['orientace']])) throw new Chyba('orientace musí být ' . implode(', ', array_keys(Media::ORIENTACE)) . '.');
        foreach (['tagy_rezim', 'q_rezim'] as $rez) {
            if (!in_array($get[$rez] ?? 'vse', ['vse', 'kterykoli', 'all', 'any'], true)) throw new Chyba("{$rez} musí být vse nebo kterykoli (také all / any).");
        }
        foreach ($f['id'] as $i) if ($i < 1) throw new Chyba('id musí být kladná čísla oddělená čárkou.');
        if ($razeni === 'nahodne' && $f['seme'] === '') $f['seme'] = bin2hex(random_bytes(4));
        $hleda = Hledani::vyrazy($f['q']) !== [];
        if ($razeni === '' && !$f['id'] && !$hleda) $f['razeni'] = 'nove';
        if ($razeni === 'relevance' && !$hleda) $f['razeni'] = 'nove';
        $s = $this->app->media()->seznam($f, $na);
        return ['ok' => true, 'celkem' => $s['celkem'], 'strana' => $s['strana'], 'stran' => $s['stran'], 'na_stranku' => $na]
            + ($razeni === 'nahodne' ? ['seme' => $f['seme']] : [])
            + ['media' => array_map(fn($r) => $this->medium($r) + (isset($r['_skore']) ? ['relevance' => (int)$r['_skore']] : []), $s['polozky'])];
    }

    /** Kategorie v seznamu: id, 0 = bez kategorie, nebo název (velikost písmen nerozhoduje). */
    private function kategorieFiltr(mixed $k): string
    {
        if ($k === null || $k === '') return '';
        $k = trim((string)$k);
        if (ctype_digit($k)) return (string)(int)$k;
        foreach ($this->app->kategorie()->volby() as $id => $nazev) if (mb_strtolower($nazev) === mb_strtolower($k)) return (string)$id;
        throw new Chyba("Kategorie „{$k}“ nenalezena.");
    }

    /** Hodnoty oddělené čárkou (nebo pole) bez prázdných. @return list<string> */
    private function seznamZTextu(mixed $v, int $max, string $pole): array
    {
        if ($v === null || $v === '') return [];
        $h = array_values(array_filter(array_map('trim', is_array($v) ? array_map('strval', $v) : explode(',', (string)$v)), 'strlen'));
        if (count($h) > $max) throw new Chyba("{$pole}: nejvýš {$max} hodnot.");
        return $h;
    }

    private function cislo(array $get, string $pole): int
    {
        if (!isset($get[$pole]) || $get[$pole] === '') return 0;
        if (!ctype_digit((string)$get[$pole])) throw new Chyba("{$pole} musí být celé číslo (px).");
        return (int)$get[$pole];
    }

    /**
     * Médium podle odkazu z této galerie: detail v administraci (index.php?p=medium&id=…), veřejný odkaz (soubor.php/<klíč>/…),
     * podepsaný náhled (nahled.php/<id>/…) nebo adresa API (api.php/v1/media/<id>…). Jiný server = chyba.
     */
    public function podleOdkazu(string $url): array
    {
        $url = trim($url);
        if ($url === '') throw new Chyba('Zadejte odkaz v parametru url.');
        $u = parse_url($url);
        $z = parse_url($this->app->url('/'));
        if (!is_array($u) || !isset($u['host']) || !preg_match('~^https?$~i', (string)($u['scheme'] ?? ''))
            || strcasecmp((string)$u['host'], (string)($z['host'] ?? '')) !== 0 || (int)($u['port'] ?? 0) !== (int)($z['port'] ?? 0)) {
            throw new Chyba('Odkaz nevede do této galerie (' . $this->app->url() . ').');
        }
        $zaklad = rtrim((string)($z['path'] ?? ''), '/');
        $cesta = rawurldecode((string)($u['path'] ?? '/'));
        if ($zaklad !== '' && !str_starts_with($cesta, $zaklad . '/')) throw new Chyba('Odkaz nevede do této galerie (' . $this->app->url() . ').');
        $cesta = substr($cesta, strlen($zaklad));
        parse_str((string)($u['query'] ?? ''), $q);
        $media = $this->app->media();
        if (preg_match('~^/soubor\.php/([0-9a-f]{24})(/|$)~', $cesta, $m) || ($cesta === '/soubor.php' && is_string($q['k'] ?? null) && preg_match('~^([0-9a-f]{24})$~', $q['k'], $m))) {
            return $media->podleKlice($m[1]) ?? throw new Chyba('Médium nenalezeno (veřejný odkaz byl vypnutý nebo změněný).');
        }
        if (preg_match('~^/nahled\.php/(\d+)/~', $cesta, $m)) return $media->radek((int)$m[1]);
        if (preg_match('~^/api\.php/' . self::VERZE . '/media/(\d+)(/|$)~', $cesta, $m)) return $media->radek((int)$m[1]);
        if (in_array($cesta, ['/', '/index.php'], true) && ($q['p'] ?? '') === 'medium' && ctype_digit((string)($q['id'] ?? ''))) return $media->radek((int)$q['id']);
        throw new Chyba('Z odkazu nejde poznat médium. Použijte odkaz na detail média, veřejný odkaz nebo adresu z API.');
    }

    /** Médium pro API. @return array<string, mixed> */
    public function medium(array $r): array
    {
        static $kategorie = null;
        $kategorie ??= $this->app->kategorie()->volby();
        $kid = $r['category_id'] !== null ? (int)$r['category_id'] : null;
        if ($kid !== null && !isset($kategorie[$kid])) $kategorie = $this->app->kategorie()->volby();
        $nahledy = $this->app->media()->adresyNahledu($r);
        return [
            'id' => (int)$r['id'],
            'nazev' => (string)$r['original_name'],
            'druh' => (string)$r['kind'],
            'mime' => (string)$r['mime'],
            'velikost' => (int)$r['size'],
            'sirka' => $r['width'] !== null ? (int)$r['width'] : null,
            'vyska' => $r['height'] !== null ? (int)$r['height'] : null,
            'md5' => (string)$r['md5'],
            'kategorie' => $kid !== null ? ['id' => $kid, 'nazev' => $kategorie[$kid] ?? null] : null,
            'tagy' => Media::tagy($r),
            'poznamka' => $r['note'],
            'autor' => $r['author'],
            'licence' => $r['license'],
            'licence_kod' => $r['license_code'],
            'licence_verze' => $r['license_version'],
            'licence_nazev' => Licence::nazev($r['license_code'], $r['license_version']),
            'licence_url' => $r['license_url'],
            'licence_odkaz' => Licence::odkaz($r['license_code'], $r['license_version'], $r['license_url']),
            'atribuce' => Media::uvedeni($r),
            'orientace' => Media::orientace($r),
            'archivovano' => $r['archived_at'] ? date('c', (int)strtotime((string)$r['archived_at'])) : null,
            'doporucena' => !empty($r['featured']),
            'vytvoreno' => date('c', (int)strtotime((string)$r['created_at'])),
            'komprimovano' => !empty($r['orig_filename']) ? ['velikost' => (int)$r['orig_size'], 'sirka' => (int)$r['orig_w'], 'vyska' => (int)$r['orig_h']] : null,
            'verejny_odkaz' => $this->app->media()->verejnaAdresa($r),
            'nahledy' => $nahledy['adresy'] ?? null,
            'nahledy_plati_do' => isset($nahledy['plati_do']) ? date('c', $nahledy['plati_do']) : null,
            'stranka_url' => $this->app->url('index.php?p=medium&id=' . (int)$r['id']),
            'soubor' => $this->app->url('api.php/' . self::VERZE . '/media/' . (int)$r['id'] . '/soubor'),
        ];
    }

    /** @return array<string, mixed> */
    private function kategorie(array $k): array
    {
        return ['id' => (int)$k['id'], 'nazev' => (string)$k['name'], 'poznamka' => $k['note'], 'pocet_medii' => (int)($k['pocet'] ?? 0),
            'velikost' => (int)($k['velikost'] ?? 0), 'vytvoreno' => date('c', (int)$k['created_at'])];
    }

    private function kategoriePodleId(int $id): array
    {
        foreach ($this->app->kategorie()->seznam() as $k) if ((int)$k['id'] === $id) return $this->kategorie($k);
        throw new Chyba('Kategorie nenalezena.');
    }

    // ---------- zápis ----------

    /**
     * Nahrání nového souboru ($nahradit null) nebo nahrazení. Zdroj: multipart pole `soubor`, nebo `url` (stažení z adresy).
     * Velký soubor po částech: pole uid, index, celkem a v každé části `soubor`; odpověď bez média, dokud nepřijde poslední část.
     * @return array<string, mixed>
     */
    private function nahraj(array $v, array $soubory, ?int $nahradit): array
    {
        if ($nahradit !== null) $this->app->media()->radek($nahradit);
        $kategorie = isset($v['kategorie']) && $v['kategorie'] !== '' && $v['kategorie'] !== null ? (int)$v['kategorie'] : null;
        if ($kategorie !== null) $this->app->kategorie()->radek($kategorie);
        $tagy = $this->tagy($v['tagy'] ?? []);
        $poznamka = isset($v['poznamka']) ? Vstup::text($v, 'poznamka', 'poznamka', 5000, false) : null;
        $tmp = $this->app->data . '/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0775, true);
        $nazev = mb_substr(trim((string)($v['nazev'] ?? '')), 0, 200);
        $nazevPevny = $nazev !== '';   // název zadaný v požadavku má přednost před titulkem z metadat

        $f = $soubory['soubor'] ?? null;
        if (is_array($f)) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new Chyba(in_array($f['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                    ? 'Soubor je větší, než server přijme v jednom požadavku (' . ini_get('upload_max_filesize') . '). Pošlete ho po částech (uid, index, celkem).'
                    : 'Soubor se nepodařilo přijmout.');
            }
            if (isset($v['celkem'])) {
                $n = new Nahravani($tmp, App::MAX_NAHRANI);
                if ((int)($v['index'] ?? -1) === 0) $n->uklid();
                $cesta = $n->cast((string)($v['uid'] ?? ''), (int)($v['index'] ?? -1), (int)$v['celkem'], (string)$f['tmp_name']);
                if ($cesta === null) return ['hotovo' => false];
            } else {
                $cesta = $tmp . '/api-' . bin2hex(random_bytes(8));
                if (!(is_uploaded_file((string)$f['tmp_name']) ? move_uploaded_file((string)$f['tmp_name'], $cesta) : rename((string)$f['tmp_name'], $cesta))) {
                    throw new Chyba('Soubor se nepodařilo uložit.');
                }
            }
            $puvodni = (string)($f['name'] ?? '');
            if ($nazev === '') $nazev = $puvodni !== '' ? $puvodni : 'soubor';
            elseif (pathinfo($nazev, PATHINFO_EXTENSION) === '' && pathinfo($puvodni, PATHINFO_EXTENSION) !== '') $nazev .= '.' . pathinfo($puvodni, PATHINFO_EXTENSION);
        } elseif (isset($v['url']) && is_string($v['url'])) {
            $cesta = $tmp . '/url-' . bin2hex(random_bytes(8));
            $zAdresy = $this->app->zAdresy()->stahni(trim($v['url']), $cesta);
            if ($nazev === '') $nazev = $zAdresy;
            elseif (pathinfo($nazev, PATHINFO_EXTENSION) === '') $nazev .= '.' . pathinfo($zAdresy, PATHINFO_EXTENSION);
        } else {
            throw new Chyba('Pošlete soubor v poli „soubor“ (multipart/form-data), nebo adresu v poli „url“.');
        }
        if ($nahradit === null && strtolower(pathinfo($nazev, PATHINFO_EXTENSION)) === 'zip') {
            // ZIP s obrázky: každý obrázek jako samostatné médium
            $r = $this->app->media()->nahrajZip($cesta, $tagy, $kategorie);
            if ($poznamka !== null) foreach ($r['media'] as $id) $this->app->media()->nastavPoznamku($id, $poznamka);
            $this->app->log('info', 'API (ZIP): ' . $nazev . ', obrázků ' . count($r['media']));
            return ['hotovo' => true, 'zip' => true, 'media' => array_map(fn($id) => $this->medium($this->app->media()->radek($id)), $r['media']),
                'preskoceno' => $r['preskoceno'], 'chyby' => $r['chyby']];
        }
        $vysledek = $this->app->media()->nahraj($cesta, $nazev, $tagy, $kategorie, $nahradit, $nazevPevny);
        $id = $vysledek['id'];
        if ($nahradit === null && $poznamka !== null) $this->app->media()->nastavPoznamku($id, $poznamka);
        $this->app->log('info', 'API (' . ($nahradit ? 'nahrazení' : 'nahrání') . "): {$vysledek['nazev']}");
        return ['hotovo' => true, 'medium' => $this->medium($this->app->media()->radek($id)), 'poznamka_komprese' => $vysledek['poznamka']];
    }

    /** Úprava údajů: nazev, poznamka, kategorie (id nebo null), tagy (pole nebo text s čárkami), archiv (bool), doporucena (bool), autor, licence, licence_url, licence_kod, licence_verze. Vše se ověří před zápisem. */
    private function uprav(int $id, array $v): array
    {
        $r = $this->app->media()->radek($id);
        $znama = ['nazev', 'poznamka', 'kategorie', 'tagy', 'archiv', 'doporucena', 'autor', 'licence', 'licence_url', 'licence_kod', 'licence_verze'];
        if ($nezname = array_diff(array_keys($v), $znama)) throw new Chyba('Neznámé pole: ' . implode(', ', $nezname) . '. Povolená: ' . implode(', ', $znama) . '.');
        $nazev = array_key_exists('nazev', $v) ? Vstup::text($v, 'nazev', 'nazev', 200) : null;
        $pozn = array_key_exists('poznamka', $v) ? Vstup::text($v, 'poznamka', 'poznamka', 5000, false) : null;
        $kategorie = array_key_exists('kategorie', $v) ? ($v['kategorie'] === null || $v['kategorie'] === '' ? null : (int)$v['kategorie']) : false;
        if (is_int($kategorie)) $this->app->kategorie()->radek($kategorie);
        $tagy = array_key_exists('tagy', $v) ? $this->tagy($v['tagy']) : null;
        $autor = array_key_exists('autor', $v) ? Vstup::text($v, 'autor', 'autor', 200, false) : $r['author'];
        $licence = array_key_exists('licence', $v) ? Vstup::text($v, 'licence', 'licence', 2000, false) : $r['license'];
        $licenceUrl = array_key_exists('licence_url', $v) ? Vstup::text($v, 'licence_url', 'licence_url', 500, false) : $r['license_url'];
        if ($licenceUrl !== null && $licenceUrl !== '' && !preg_match('~^https?://\S+$~i', $licenceUrl)) throw new Chyba('licence_url musí začínat http:// nebo https://.');
        $zmenaAutora = array_intersect(['autor', 'licence', 'licence_url'], array_keys($v)) !== [];
        $zmenaKodu = array_intersect(['licence_kod', 'licence_verze'], array_keys($v)) !== [];
        $kod = array_key_exists('licence_kod', $v) ? ($v['licence_kod'] === null ? null : (string)$v['licence_kod']) : $r['license_code'];
        $verze = array_key_exists('licence_verze', $v) ? ($v['licence_verze'] === null ? null : (string)$v['licence_verze']) : $r['license_version'];
        if ($zmenaKodu) {
            try { [$kod, $verze] = Licence::vycisti($kod, $verze); } catch (\InvalidArgumentException $e) { throw new Chyba($e->getMessage()); }
        }
        $g = $this->app->galerie();
        $this->app->db->transakce(function () use ($g, $id, $r, $v, $nazev, $pozn, $kategorie, $tagy, $zmenaAutora, $autor, $licence, $licenceUrl, $zmenaKodu, $kod, $verze) {
            if ($zmenaAutora) $this->app->media()->nastavAutora($id, $autor ?: null, $licence ?: null, $licenceUrl ?: null);
            if ($zmenaKodu) $this->app->media()->nastavLicenci($id, $kod, $verze);
            if ($nazev !== null && $nazev !== $r['original_name']) $g->prejmenuj($id, $nazev);
            if (array_key_exists('poznamka', $v)) $this->app->media()->nastavPoznamku($id, $pozn);
            if ($kategorie !== false) $this->app->media()->nastavKategorii([$id], $kategorie);
            if ($tagy !== null) $g->tagy()->nastav($id, $tagy);
            if (array_key_exists('archiv', $v)) $g->archivuj([$id], (bool)$v['archiv']);
            if (array_key_exists('doporucena', $v)) $this->app->media()->nastavDoporucene([$id], (bool)$v['doporucena']);
        });
        return $this->medium($this->app->media()->radek($id));
    }

    /** @return list<string> */
    private function tagy(mixed $t): array
    {
        if ($t === null || $t === '') return [];
        if (!is_array($t) && !is_string($t)) throw new Chyba('tagy musí být pole nebo text s čárkami.');
        return $this->app->galerie()->tagy()->vycistiSeznam($t);
    }
}
