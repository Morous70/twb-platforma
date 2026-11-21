# TrafikaBR Update Server – implementace klientského updateru

Tento dokument je závazná vývojářská reference pro aplikace napojené na TrafikaBR Update Server. Vychází z prvního ostrého nasazení updateru v POHODA Data Hubu.

## Kritická pravidla

1. `{"app":"<slug>","update":null}` je **úspěšná odpověď bez aktivního release**, ne chyba podpisu.
2. Podepsaný release má v kořeni odpovědi `algorithm`, `payload`, `payload_b64`, `signature`.
3. Ed25519 ověřovat nad přesnými bajty z `base64_decode(payload_b64)`. Nikdy nad znovu serializovaným `payload`.
4. Veřejný Ed25519 klíč musí být **připnutý v klientovi**. Je veřejný a smí být součástí kódu/instalačního ZIPu.
5. Aplikace v režimu `authenticated` používá tajný klientský `upd_...` token. Ukládat jej do lokální konfigurace, kterou aktualizační ZIP nepřepisuje.
6. Pro `authenticated` režim a kompatibilitu se sdílenými hostingy posílat token současně jako `Authorization: Bearer ...` i `X-Update-Token: ...`. Aplikace v režimu `public_signed` token neposílá; bezpečnost aktualizace stojí na připnutém Ed25519 veřejném klíči a SHA-256.
7. ZIP instalovat až po platném podpisu manifestu a ověření SHA-256.
8. Aktualizace nesmí přepsat lokální konfiguraci, tajné údaje, DB, uploady ani zálohy.
9. ZIP nejprve rozbalit do staging adresáře a kontrolovat cesty proti Zip Slip.
10. Před změnou živých souborů vytvořit zálohu aplikace a databáze; při selhání použít rollback.

## Endpoint kontroly

### `authenticated`

```http
GET https://updates.trafikabr.cz/api/v1/latest.php?app=<slug>
Authorization: Bearer upd_...
X-Update-Token: upd_...
```

### `public_signed`

```http
GET https://updates.trafikabr.cz/api/v1/latest.php?app=<slug>
```

Formát podepsané odpovědi je v obou režimech stejný. Režim `public_signed` odstraňuje pouze požadavek na přístupový token; neodstraňuje ověření Ed25519 ani SHA-256 na straně klienta.

### Žádný aktivní release

```json
{
  "app": "pohoda-data-hub",
  "update": null
}
```

Klient má kontrolu označit jako úspěšnou a Ed25519 vůbec nespouštět.

### Podepsaný release

```json
{
  "algorithm": "Ed25519",
  "payload": {
    "app": "pohoda-data-hub",
    "version": "0.9.5",
    "release_id": "...",
    "size": 5847,
    "sha256": "...",
    "released_at": "...",
    "download_path": "/api/v1/download.php?id=..."
  },
  "payload_b64": "...",
  "signature": "..."
}
```

## Správné Ed25519 ověření

```php
$response = json_decode($body, true, 32, JSON_THROW_ON_ERROR);

if (array_key_exists('update', $response) && $response['update'] === null) {
    return ['available' => false];
}

if (($response['algorithm'] ?? '') !== 'Ed25519'
    || empty($response['payload_b64'])
    || empty($response['signature'])) {
    throw new RuntimeException('Update Server vrátil neočekávaný formát manifestu.');
}

$payloadRaw = base64_decode((string)$response['payload_b64'], true);
$signature  = base64_decode((string)$response['signature'], true);
$publicKey  = base64_decode(UPDATE_PUBLIC_KEY_B64, true);

if ($payloadRaw === false || $signature === false || $publicKey === false) {
    throw new RuntimeException('Manifest obsahuje neplatná podpisová data.');
}

if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
    || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
    throw new RuntimeException('Neplatná délka Ed25519 klíče nebo podpisu.');
}

if (!sodium_crypto_sign_verify_detached($signature, $payloadRaw, $publicKey)) {
    throw new RuntimeException('Digitální podpis aktualizace není platný.');
}

$manifest = json_decode($payloadRaw, true, 32, JSON_THROW_ON_ERROR);
```

## Stažení a instalace

1. Ověřit podepsaný manifest.
2. Porovnat verzi přes `version_compare()`.
3. Pokud je serverová verze novější, stáhnout ZIP z podepsaného `download_path`. V režimu `authenticated` použít stejné dvě tokenové hlavičky; v režimu `public_signed` klientský token neposílat.
4. Ověřit SHA-256 ZIPu proti podepsanému manifestu.
5. Rozbalit do staging adresáře.
6. Zkontrolovat cesty proti `..`, absolutním cestám, symlinkům a zápisu mimo app root.
7. Zálohovat aplikaci i DB.
8. Přepsat jen spravované soubory; zachovat lokální config, tokeny, DB, `data/`, `backup/`, `uploads/`.
9. Spustit případné DB migrace.
10. Ověřit cílovou verzi a základní funkčnost (health-check).
11. Při selhání obnovit zálohu a zapsat rollback.

## Health-check

Krátká kontrola po instalaci. Minimum:
- bootstrap aplikace se načte,
- `APP_VERSION` odpovídá cílové verzi,
- DB lze otevřít,
- lokální konfigurace existuje.

Při selhání má bezpečný updater vrátit předchozí soubory a DB.

## UI a log

- Automaticky pouze kontrolovat dostupnost, typicky max. 1× za 24 hodin.
- Instalaci spouští administrátor.
- `update:null` zobrazit neutrálně jako „žádný aktivní release“.
- Aktuální verzi zobrazovat u názvu aplikace.
- U `authenticated` token zobrazovat pouze maskovaně; u `public_signed` jej updater nemá vyžadovat.
- Logovat `ok`, `no_release`, `auth_error`, `signature_error`, `hash_error`, `install_error`, `rollback`.

## Typické chyby

| Chyba | Příčina | Řešení |
|---|---|---|
| HTTP 401 | u `authenticated` se token neposlal / hosting zahodil `Authorization` / token je jiný; u `public_signed` je 401 neočekávané | zkontrolovat režim aplikace a konfiguraci; u `authenticated` posílat obě hlavičky |
| „Manifest neobsahuje podpis“ + klíče `app, update` | server vrátil `update:null` | zpracovat jako normální stav |
| Ed25519 podpis neplatný | ověřování nad `json_encode(payload)` nebo špatný připnutý klíč | ověřovat přesné `payload_b64` bajty |
| SHA-256 nesouhlasí | ZIP je poškozený nebo jiný | ZIP odstranit, neinstalovat |
| instalace přepsala token/config | lokální soubory nebyly chráněné | přidat protected paths a staging |
