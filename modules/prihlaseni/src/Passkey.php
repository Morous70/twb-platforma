<?php
declare(strict_types=1);
namespace TWB\Prihlaseni;

use TWB\Jadro\Db;

/**
 * Passkey (WebAuthn) bez knihoven: vlastní CBOR a ověření podpisu přes OpenSSL.
 * Podporuje ES256 (P-256) a RS256 (Windows Hello), attestation „none“.
 * Funguje jen na HTTPS (nebo na localhostu).
 */
final class Passkey
{
    public const VYZVA_SEKUND = 300;

    private string $rpId;
    private string $puvod;
    /** @var callable(): scalar */
    private $cas;

    /** @param (callable(): scalar)|null $cas  hodnota pro created_at a last_used (výchozí datum a čas; Social Planner ukládá čas jako číslo) */
    public function __construct(
        private Db $db,
        private Tokeny $tokeny,
        private string $zakladniUrl,
        private string $nazevAplikace,
        private string $uzivatelId = 'twb-uzivatel',
        private string $uzivatelJmeno = 'twb',
        ?callable $cas = null,
    ) {
        $u = parse_url($zakladniUrl) ?: [];
        $this->rpId = (string)($u['host'] ?? '');
        $this->puvod = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
        $this->cas = $cas ?? fn() => date('Y-m-d H:i:s');
    }

    public function rpId(): string { return $this->rpId; }
    public function puvod(): string { return $this->puvod; }

    public function bezpecnyKontext(): bool
    {
        $u = parse_url($this->zakladniUrl) ?: [];
        return ($u['scheme'] ?? '') === 'https' || in_array($u['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true);
    }

    public function dostupne(): bool { return function_exists('openssl_verify') && $this->bezpecnyKontext(); }

    /** @return list<array<string, mixed>> */
    public function seznam(): array
    {
        return $this->db->q('SELECT id, name, alg, created_at, last_used FROM passkeys ORDER BY id')->fetchAll();
    }

    public function smaz(int $id): bool { return $this->db->q('DELETE FROM passkeys WHERE id = ?', [$id])->rowCount() === 1; }

    // ---------- pomocné ----------

    private static function der(int $tag, string $c): string
    {
        $n = strlen($c);
        $len = $n < 128 ? chr($n) : (chr(0x80 | strlen(ltrim(pack('N', $n), "\0"))) . ltrim(pack('N', $n), "\0"));
        return chr($tag) . $len . $c;
    }

    private static function derInt(string $bin): string
    {
        $bin = ltrim($bin, "\0");
        if ($bin === '' || (ord($bin[0]) & 0x80)) $bin = "\0" . $bin;
        return self::der(0x02, $bin);
    }

    private static function pem(string $spki): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Klíč COSE → [PEM, alg]. Podporuje ES256 (P-256) a RS256. @param array<int|string, mixed> $k @return array{0: string, 1: int} */
    public static function coseNaPem(array $k): array
    {
        $alg = $k[3] ?? null;
        if (($k[1] ?? null) === 2 && $alg === -7 && ($k[-1] ?? null) === 1 && strlen((string)($k[-2] ?? '')) === 32 && strlen((string)($k[-3] ?? '')) === 32) {
            return [self::pem(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3]), -7];
        }
        if (($k[1] ?? null) === 3 && $alg === -257 && !empty($k[-1]) && !empty($k[-2])) {
            $rsa = self::der(0x30, self::derInt($k[-1]) . self::derInt($k[-2]));
            $spki = self::der(0x30, self::der(0x30, hex2bin('06092a864886f70d010101') . "\x05\x00") . self::der(0x03, "\0" . $rsa));
            return [self::pem($spki), -257];
        }
        throw new \RuntimeException('Toto zařízení používá nepodporovaný typ klíče.');
    }

    /** authenticatorData: rpIdHash, příznaky, počítadlo a (při registraci) údaje o klíči. @return array<string, mixed> */
    private static function authData(string $ad, bool $sKlicem): array
    {
        if (strlen($ad) < 37) throw new \RuntimeException('Neplatná data zařízení.');
        $r = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'counter' => unpack('N', substr($ad, 33, 4))[1]];
        if ($sKlicem) {
            if (!($r['flags'] & 0x40) || strlen($ad) < 55) throw new \RuntimeException('Zařízení neposlalo údaje o klíči.');
            $idLen = unpack('n', substr($ad, 53, 2))[1];
            if (strlen($ad) < 55 + $idLen) throw new \RuntimeException('Neplatná data zařízení.');
            $r['credId'] = substr($ad, 55, $idLen);
            $o = 55 + $idLen;
            $key = Cbor::dekoduj($ad, $o);
            if (!is_array($key)) throw new \RuntimeException('Neplatný klíč zařízení.');
            $r['key'] = $key;
        }
        return $r;
    }

    private function vyzva(string $ucel): string
    {
        if ($this->tokeny->pocetPlatnych($ucel) > 100) throw new \RuntimeException('Příliš mnoho požadavků, zkuste to za chvíli.');
        return $this->tokeny->vytvor($ucel, self::VYZVA_SEKUND, Base64Url::koduj(random_bytes(32)));
    }

    /** Ověří clientDataJSON: typ, původ a výzvu (jednorázově). Vrátí surové bajty clientDataJSON. */
    private function clientData(string $b64, string $typ, string $ucel): string
    {
        $raw = Base64Url::dekoduj($b64);
        $cd = json_decode($raw, true);
        if (!is_array($cd) || ($cd['type'] ?? '') !== $typ) throw new \RuntimeException('Neplatná odpověď zařízení.');
        if (!empty($cd['crossOrigin'])) throw new \RuntimeException('Neplatný původ.');
        if (!hash_equals($this->puvod, (string)($cd['origin'] ?? ''))) throw new \RuntimeException('Neplatný původ.');
        if (!$this->tokeny->pouzij($ucel, (string)($cd['challenge'] ?? ''))) throw new \RuntimeException('Výzva vypršela nebo už byla použita.');
        return $raw;
    }

    /** @param array<string, mixed> $p */
    private function zkontrolujPriznaky(array $p): void
    {
        if (!hash_equals(hash('sha256', $this->rpId, true), $p['rpIdHash'])) throw new \RuntimeException('Zařízení patří k jiné adrese.');
        if (!($p['flags'] & 0x01)) throw new \RuntimeException('Chybí potvrzení přítomnosti uživatele.');
        if (!($p['flags'] & 0x04)) throw new \RuntimeException('Zařízení neověřilo uživatele (otisk, obličej nebo PIN).');
    }

    // ---------- registrace ----------

    /** @return array<string, mixed> */
    public function moznostiRegistrace(): array
    {
        $vylouci = [];
        foreach ($this->db->q('SELECT cred_id FROM passkeys')->fetchAll() as $r) $vylouci[] = ['type' => 'public-key', 'id' => $r['cred_id']];
        return ['publicKey' => [
            'challenge' => $this->vyzva('wa-reg'),
            'rp' => ['id' => $this->rpId, 'name' => $this->nazevAplikace],
            'user' => ['id' => Base64Url::koduj($this->uzivatelId), 'name' => $this->uzivatelJmeno, 'displayName' => $this->nazevAplikace],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => $vylouci, 'attestation' => 'none', 'timeout' => 120000,
        ]];
    }

    /** Zaregistruje zařízení. Vrátí ID nového záznamu. @param array<string, mixed> $cred */
    public function registruj(array $cred, string $nazev): int
    {
        $resp = $cred['response'] ?? [];
        $this->clientData((string)($resp['clientDataJSON'] ?? ''), 'webauthn.create', 'wa-reg');
        $o = 0;
        $att = Cbor::dekoduj(Base64Url::dekoduj((string)($resp['attestationObject'] ?? '')), $o);
        if (!is_array($att) || !is_string($att['authData'] ?? null)) throw new \RuntimeException('Neplatná odpověď zařízení.');
        $p = self::authData($att['authData'], true);
        $this->zkontrolujPriznaky($p);
        [$pem, $alg] = self::coseNaPem($p['key']);
        $credB64 = Base64Url::koduj($p['credId']);
        if (isset($cred['rawId']) && Base64Url::koduj(Base64Url::dekoduj((string)$cred['rawId'])) !== $credB64) throw new \RuntimeException('Neplatná odpověď zařízení.');
        $hash = hash('sha256', $p['credId']);
        if ($this->db->q('SELECT 1 FROM passkeys WHERE cred_hash = ?', [$hash])->fetchColumn()) throw new \RuntimeException('Toto zařízení už je zaregistrované.');
        $nazev = trim(mb_substr($nazev, 0, 100)) ?: 'Zařízení';
        $this->db->q('INSERT INTO passkeys (name, cred_hash, cred_id, public_key, alg, sign_count, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$nazev, $hash, $credB64, $pem, $alg, $p['counter'], ($this->cas)()]);
        return $this->db->posledniId();
    }

    // ---------- přihlášení ----------

    /** @return array<string, mixed> */
    public function moznostiPrihlaseni(): array
    {
        return ['publicKey' => ['challenge' => $this->vyzva('wa-login'), 'rpId' => $this->rpId,
            'allowCredentials' => [], 'userVerification' => 'required', 'timeout' => 120000]];
    }

    /** Ověří přihlášení Passkey. Vrátí řádek zařízení; při chybě vyhodí RuntimeException. @param array<string, mixed> $cred @return array<string, mixed> */
    public function overPrihlaseni(array $cred): array
    {
        $resp = $cred['response'] ?? [];
        $row = $this->db->q('SELECT * FROM passkeys WHERE cred_hash = ?', [hash('sha256', Base64Url::dekoduj((string)($cred['rawId'] ?? '')))])->fetch();
        if (!$row) throw new \RuntimeException('Toto zařízení není zaregistrované.');
        $cdRaw = $this->clientData((string)($resp['clientDataJSON'] ?? ''), 'webauthn.get', 'wa-login');
        $ad = Base64Url::dekoduj((string)($resp['authenticatorData'] ?? ''));
        $p = self::authData($ad, false);
        $this->zkontrolujPriznaky($p);
        $podpis = Base64Url::dekoduj((string)($resp['signature'] ?? ''));
        if (openssl_verify($ad . hash('sha256', $cdRaw, true), $podpis, $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) throw new \RuntimeException('Neplatný podpis.');
        $ulozene = (int)$row['sign_count'];
        if (($p['counter'] > 0 || $ulozene > 0) && $p['counter'] <= $ulozene) throw new \RuntimeException('Počítadlo zařízení se nezvýšilo (možná kopie klíče).');
        $this->db->q('UPDATE passkeys SET sign_count = ?, last_used = ? WHERE id = ?', [$p['counter'], ($this->cas)(), $row['id']]);
        return $row;
    }
}
