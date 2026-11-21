<?php
declare(strict_types=1);
namespace TWB\Aktualizace;

use RuntimeException;

/**
 * Ověření odpovědi latest.php. Údaje se berou výhradně z podepsaných bajtů (payload_b64), nikdy z nepodepsaného „payload“.
 * Kód výjimky: 1 = podpis/údaje nesedí, 2 = odpověď serveru nemá očekávaný tvar.
 */
final class Manifest
{
    /** @return ?array{version:string,release_id:string,sha256:string,size:int,released_at:?string,download_path:string} null = server nemá vydání */
    public static function over(string $telo, string $app, string $verejnyKlicB64, int $maxVelikost): ?array
    {
        try { $j = json_decode($telo, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Odpověď serveru není platný JSON.', 2); }
        if (!is_array($j)) throw new RuntimeException('Neočekávaná odpověď serveru.', 2);
        if (array_key_exists('update', $j) && $j['update'] === null) {
            if (($j['app'] ?? null) !== $app) throw new RuntimeException('Server odpověděl pro jinou aplikaci.', 2);
            return null;
        }
        if (($j['algorithm'] ?? '') !== 'Ed25519' || !is_string($j['payload_b64'] ?? null) || !is_string($j['signature'] ?? null)) {
            throw new RuntimeException('Odpověď serveru nemá podpis Ed25519.', 1);
        }
        $raw = base64_decode($j['payload_b64'], true);
        $sig = base64_decode($j['signature'], true);
        $klic = base64_decode($verejnyKlicB64, true);
        if ($raw === false || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || $klic === false || strlen($klic) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($sig, $raw, $klic)) {
            throw new RuntimeException('Podpis vydání nesouhlasí – aktualizace se nenabídne.', 1);
        }
        try { $p = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Podepsané údaje nejsou platný JSON.', 1); }
        $ok = is_array($p)
            && ($p['app'] ?? null) === $app
            && is_string($p['version'] ?? null) && preg_match('/^\d+\.\d+\.\d+$/', $p['version'])
            && is_string($p['release_id'] ?? null) && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $p['release_id'])
            && is_string($p['sha256'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $p['sha256'])
            && is_int($p['size'] ?? null) && $p['size'] > 0 && $p['size'] <= $maxVelikost
            && is_string($p['download_path'] ?? null) && preg_match('~^/api/v1/download\.php\?[A-Za-z0-9=&%._-]+$~', $p['download_path']);
        if (!$ok) throw new RuntimeException('Podepsané údaje o vydání nemají očekávaný tvar.', 1);
        return ['version' => $p['version'], 'release_id' => $p['release_id'], 'sha256' => $p['sha256'], 'size' => $p['size'],
                'released_at' => is_string($p['released_at'] ?? null) ? $p['released_at'] : null, 'download_path' => $p['download_path']];
    }
}
