<?php
declare(strict_types=1);
namespace MediaGalerie;

/** Jednoduchý HTTP klient přes cURL (přihlášení Google). */
final class Http
{
    /**
     * @param array{form?: array<string, string>, headers?: list<string>} $volby
     * @return array{code: int, json: ?array, body: string}
     */
    public static function pozadavek(string $metoda, string $adresa, array $volby = []): array
    {
        if (!function_exists('curl_init')) throw new \RuntimeException('PHP na serveru nemá rozšíření curl.');
        $ch = curl_init($adresa);
        $hlavicky = $volby['headers'] ?? [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $metoda, CURLOPT_USERAGENT => App::NAZEV]);
        if (isset($volby['form'])) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($volby['form']));
        if ($hlavicky) curl_setopt($ch, CURLOPT_HTTPHEADER, $hlavicky);
        $telo = curl_exec($ch);
        $kod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $chyba = curl_error($ch);
        if ($telo === false) throw new \RuntimeException('Spojení selhalo: ' . $chyba);
        $json = json_decode((string)$telo, true);
        return ['code' => $kod, 'json' => is_array($json) ? $json : null, 'body' => (string)$telo];
    }
}
