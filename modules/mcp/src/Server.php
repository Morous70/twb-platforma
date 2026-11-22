<?php
declare(strict_types=1);
namespace TWB\Mcp;

use TWB\Jadro\Protokol;

/**
 * MCP server (Model Context Protocol) přes Streamable HTTP bez trvalého spojení: jeden POST = jedna zpráva JSON-RPC
 * (nebo dávka), odpověď je JSON. Server je bezstavový (bez Mcp-Session-Id a bez SSE streamu).
 * Zná starší generaci protokolu s handshakem `initialize` (2024-11-05 až 2025-11-25) i bezstavovou 2026-07-28
 * (`server/discover`, verze v `_meta` každého požadavku). Chování je převzaté z MCP Social Planneru.
 *
 * Nic neví o HTTP ani o přihlášení: aplikace ověří token, zjistí oprávnění a předá tělo požadavku.
 */
final class Server
{
    /** Bezstavová verze protokolu (bez initialize). */
    public const MODERNI = ['2026-07-28'];
    /** Starší verze s handshakem initialize (nejnovější první). */
    public const VERZE = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];
    /** Největší tělo požadavku, které aplikace má načíst (base64 souboru se vejde do zhruba 22 MB). */
    public const MAX_TELO = 30 * 1048576;

    /** @var array<string, Nastroj> */
    private array $nastroje = [];
    /** @var callable(\Throwable): ?string */
    private $proUzivatele;

    /**
     * @param list<Nastroj> $nastroje
     * @param (callable(\Throwable): ?string)|null $proUzivatele  vrátí text chyby pro uživatele, nebo null (= chyba serveru, zapíše se do protokolu)
     */
    public function __construct(
        private string $nazev,
        private string $titulek,
        private string $verzeAplikace,
        private string $instrukce,
        array $nastroje,
        private ?Protokol $protokol = null,
        ?callable $proUzivatele = null,
    ) {
        foreach ($nastroje as $n) {
            if (isset($this->nastroje[$n->nazev])) throw new \InvalidArgumentException("Nástroj „{$n->nazev}“ je dvakrát.");
            $this->nastroje[$n->nazev] = $n;
        }
        $this->proUzivatele = $proUzivatele ?? static fn(\Throwable $e): ?string => $e instanceof ChybaNastroje || $e instanceof \InvalidArgumentException ? $e->getMessage() : null;
    }

    /** @return array<string, Nastroj> */
    public function nastroje(): array { return $this->nastroje; }

    /**
     * Zpracuje tělo požadavku POST. Vrací [stav HTTP, JSON odpověď nebo null]. Null = bez těla (jen notifikace → 202).
     * @param list<string> $opravneni  např. ['read', 'write']
     * @return array{0:int, 1:array<mixed>|null}
     */
    public function zpracujTelo(string $telo, array $opravneni, string $klient = '', string $hlavickaVerze = ''): array
    {
        $zprava = json_decode($telo, true);
        if (!is_array($zprava)) return [400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error: neplatný JSON.']]];
        $davka = array_is_list($zprava) && $zprava !== [];
        $vystup = [];
        $stav = 200;
        foreach ($davka ? $zprava : [$zprava] as $m) {
            [$s, $r] = is_array($m) ? $this->zpracuj($m, $opravneni, $klient, $hlavickaVerze)
                : [400, ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']]];
            if ($r !== null) $vystup[] = $r;
            if (!$davka) $stav = $s;
        }
        if (!$vystup) return [$stav === 400 ? 400 : 202, null];
        return $davka ? [200, $vystup] : [$stav, $vystup[0]];
    }

    /**
     * Jedna zpráva JSON-RPC.
     * @param array<mixed> $msg @param list<string> $opravneni
     * @return array{0:int, 1:array<string, mixed>|null}
     */
    public function zpracuj(array $msg, array $opravneni, string $klient = '', string $hlavickaVerze = ''): array
    {
        $id = $msg['id'] ?? null;
        $metoda = is_string($msg['method'] ?? null) ? $msg['method'] : '';
        $notifikace = !array_key_exists('id', $msg);
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $metaVerze = is_string($meta['io.modelcontextprotocol/protocolVersion'] ?? null) ? $meta['io.modelcontextprotocol/protocolVersion'] : '';

        // generace protokolu: bezstavová 2026-07-28+ (bez initialize), nebo starší s handshakem
        $moderni = $metoda !== 'initialize' && ($metaVerze !== '' || $metoda === 'server/discover' || ($hlavickaVerze !== '' && $hlavickaVerze >= self::MODERNI[0]));
        $hotovo = fn(array $r) => $moderni ? ['resultType' => 'complete'] + $r : $r;
        $ok = fn($vysledek) => [200, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $vysledek]];
        $chyba = fn(int $http, int $kod, string $text, $data = null) => [$http, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $kod, 'message' => $text] + ($data !== null ? ['data' => $data] : [])]];

        if (($msg['jsonrpc'] ?? '') !== '2.0' || $metoda === '') return $notifikace ? [202, null] : $chyba(400, -32600, 'Invalid Request');
        if ($moderni) {
            $verze = $metaVerze !== '' ? $metaVerze : $hlavickaVerze;
            if ($hlavickaVerze !== '' && $metaVerze !== '' && $hlavickaVerze !== $metaVerze) {
                return $notifikace ? [400, null] : $chyba(400, -32020, 'Header mismatch: MCP-Protocol-Version does not match _meta protocolVersion', ['header' => $hlavickaVerze, 'body' => $metaVerze]);
            }
            if ($verze !== '' && !in_array($verze, self::MODERNI, true)) {
                return $notifikace ? [400, null] : $chyba(400, -32022, 'Unsupported protocol version', ['supported' => array_merge(self::MODERNI, self::VERZE), 'requested' => $verze]);
            }
        }
        if ($notifikace) return [202, null];   // notifications/initialized, cancelled …

        $info = ['name' => $this->nazev, 'title' => $this->titulek, 'version' => $this->verzeAplikace];
        switch ($metoda) {
            case 'server/discover':
                return $ok(['resultType' => 'complete', 'supportedVersions' => array_merge(self::MODERNI, self::VERZE), 'capabilities' => ['tools' => new \stdClass()],
                    'instructions' => $this->instrukce, 'ttlMs' => 3600000, 'cacheScope' => 'private', '_meta' => ['io.modelcontextprotocol/serverInfo' => $info]]);
            case 'initialize':
                $chce = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : '';
                return $ok(['protocolVersion' => in_array($chce, self::VERZE, true) ? $chce : self::VERZE[0],
                    'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => $info, 'instructions' => $this->instrukce]);
            case 'ping':
                return $ok($moderni ? ['resultType' => 'complete'] : new \stdClass());
            case 'tools/list':
                $seznam = [];
                foreach ($this->nastroje as $n) if (in_array($n->opravneni, $opravneni, true)) $seznam[] = $n->popisProKlienta();
                return $ok($hotovo(['tools' => $seznam] + ($moderni ? ['ttlMs' => 300000, 'cacheScope' => 'private'] : [])));
            case 'tools/call':
                $nazev = is_string($params['name'] ?? null) ? $params['name'] : '';
                $argumenty = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $n = $this->nastroje[$nazev] ?? null;
                if ($n === null) return $chyba($moderni ? 400 : 200, -32602, "Unknown tool: {$nazev}");
                if (!in_array($n->opravneni, $opravneni, true)) {
                    return $ok($hotovo(['content' => [['type' => 'text', 'text' => "Chyba: připojení nemá oprávnění „{$n->opravneni}“ potřebné pro nástroj {$nazev}. Připojte konektor znovu a povolte ho."]], 'isError' => true]));
                }
                return $ok($hotovo($this->zavolej($n, $argumenty, ['opravneni' => $opravneni, 'klient' => $klient])));
            case 'resources/list':           return $ok($hotovo(['resources' => []]));
            case 'resources/templates/list': return $ok($hotovo(['resourceTemplates' => []]));
            case 'prompts/list':             return $ok($hotovo(['prompts' => []]));
        }
        // moderní klient pozná nepodporovanou metodu podle 404, starší klienti dostanou 200
        return $chyba($moderni ? 404 : 200, -32601, "Method not found: {$metoda}");
    }

    /** @param array<string, mixed> $argumenty @param array<string, mixed> $kontext @return array<string, mixed> */
    private function zavolej(Nastroj $n, array $argumenty, array $kontext): array
    {
        try {
            $data = ($n->funkce)($argumenty, $kontext);
            if ($n->zapis) $this->protokol?->zapis('info', 'MCP' . ($kontext['klient'] !== '' ? " ({$kontext['klient']})" : '') . ": {$n->nazev}");
            $text = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
            return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $data === [] ? new \stdClass() : $data, 'isError' => false];
        } catch (\Throwable $e) {
            $zprava = ($this->proUzivatele)($e);
            if ($zprava === null) {
                $this->protokol?->zapis('error', "MCP: chyba v nástroji {$n->nazev}: " . $e->getMessage());
                $zprava = 'Chyba serveru při provádění nástroje (podrobnosti jsou v protokolu aplikace).';
            }
            return ['content' => [['type' => 'text', 'text' => 'Chyba: ' . $zprava]], 'isError' => true];
        }
    }
}
