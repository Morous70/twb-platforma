# TrafikaBR Update Server – read-only API pro TWB App Hub

Toto API poskytuje TWB App Hubu technickou historii verzí. Nerozhoduje, co je veřejné vydání, a žádný endpoint nemění releases, `latest_release_id`, balíčky ani jiné části update workflow.

## Přístup podle režimu aplikace

Read-only API používá stejnou per-app access policy jako `latest.php` a `download.php`:

- `authenticated` – vyžaduje stejný klientský token `upd_...` jako stávající update API; podporovány jsou obě hlavičky:

```http
Authorization: Bearer upd_...
X-Update-Token: upd_...
```

  Token musí patřit aplikaci uvedené v URL.
- `public_signed` – klientský token není pro read-only API vyžadován. Metadata technických verzí jsou veřejně čitelná.

Chybějící nebo neznámý režim se bezpečně chová jako `authenticated`.

## Seznam technických verzí

```http
GET /api/v1/apps/{app}/versions
```

Verze jsou řazené od nejnovější podle SemVer. Odpověď obsahuje také stav `active` nebo `withdrawn`; Update Server tím pouze popisuje technický stav a neurčuje veřejné vydání.

## Detail technické verze

```http
GET /api/v1/apps/{app}/versions/{version}
```

## Změny v rozsahu

```http
GET /api/v1/apps/{app}/changes?from=1.7.2&to=1.7.8
```

Vrací technické verze `> from` a `<= to`, seřazené vzestupně. Pokud `from == to`, pole `versions` je prázdné.

## Stavové kódy

- `200` – úspěch
- `401` – chybějící nebo neplatný klientský token u aplikace v režimu `authenticated`
- `404` – aplikace nebo konkrétní verze nebyla nalezena
- `405` – nepovolená HTTP metoda
- `422` – neplatný SemVer rozsah
- `503` – Update Server není nainstalován

JSON je vracen jako UTF-8. API nikdy nevrací klientský token, interní filesystem cesty, podpisový privátní klíč ani jiné citlivé údaje.
