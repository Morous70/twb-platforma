---
titulek: API
sekce: sprava
admin: ne
kontext: api
souvisi: verejne-odkazy, nahravani
klicova: api, klíč, token, rest, json, curl, skript, automatizace
---
# API

API slouží skriptům a jiným aplikacím: výpis a stažení médií, nahrání (i z adresy), úpravy, kampaně a tagy. Odpovídá ve formátu JSON.

## Klíč
Klíč vytvoříte na stránce **API** (menu Správa). Celý klíč se ukáže jen jednou, aplikace si pamatuje jen jeho otisk.

- **Čtení** smí jen požadavky GET (výpis, detail, stažení souboru).
- **Čtení i zápis** smí vše, včetně nahrávání a mazání.

Klíč se posílá v hlavičce `Authorization: Bearer tgk_…` (nebo `X-Api-Key: tgk_…`). Po 20 neplatných klíčích za 5 minut API na chvíli odpovídá chybou 429.
Zrušený klíč přestane fungovat okamžitě. API klíče nejsou v zálohách.

## Adresy
Všechny cesty začínají `https://galerie.kazbo.eu/api.php/v1`.

| Metoda a cesta | Co dělá | Oprávnění |
|---|---|---|
| GET `/` | informace o aplikaci a klíči | čtení |
| GET `/media` | seznam médií (filtry níže) | čtení |
| GET `/media/{id}` | jedno médium | čtení |
| GET `/media/{id}/soubor` | stáhne soubor (`?original=1` originál před kompresí, `?stahnout=1`) | čtení |
| POST `/media` | nahraje soubor nebo stáhne soubor z adresy | zápis |
| PATCH `/media/{id}` | změní název, poznámku, kampaň, tagy nebo archiv | zápis |
| POST `/media/{id}/soubor` | nahradí soubor (odkaz a údaje zůstanou) | zápis |
| POST `/media/{id}/odkaz` | vytvoří nový veřejný odkaz (starý přestane fungovat) | zápis |
| DELETE `/media/{id}/odkaz` | vypne veřejný odkaz | zápis |
| DELETE `/media/{id}` | smaže médium i soubor | zápis |
| GET `/kampane` | seznam kampaní s počty médií | čtení |
| GET `/kampane/{id}` | jedna kampaň | čtení |
| POST `/kampane` | nová kampaň (`nazev`, `poznamka`) | zápis |
| PATCH `/kampane/{id}` | úprava kampaně | zápis |
| DELETE `/kampane/{id}` | smaže kampaň, média zůstanou bez kampaně | zápis |
| GET `/tagy` | všechny tagy s počty | čtení |

## Filtry seznamu médií
| Parametr | Význam |
|---|---|
| `q` | text v názvu nebo poznámce |
| `druh` | `image`, `video` nebo `pdf` |
| `kampan` | id kampaně, `0` = bez kampaně |
| `tag` | tag (velikost písmen nerozhoduje) |
| `archiv` | `1` = jen archiv (jinak jen média mimo archiv) |
| `razeni` | `nove` (výchozí), `stare`, `nazev`, `velikost` |
| `strana`, `na_stranku` | stránkování, na stránku 1–200 (výchozí 48) |

Odpověď má `celkem`, `strana`, `stran` a pole `media`. Každé médium má `id`, `nazev`, `druh`, `mime`, `velikost` (bajty), `sirka`, `vyska`, `md5`, `kampan` (`id`, `nazev`) nebo null, `tagy`, `poznamka`, `archivovano`, `vytvoreno`, `komprimovano`, `verejny_odkaz` (null, když je vypnutý) a `soubor` (adresa ke stažení přes API).

## Nahrání
POST `/media` jako `multipart/form-data`:

| Pole | Význam |
|---|---|
| `soubor` | soubor (obrázek, video, PDF) |
| `url` | místo souboru: veřejná adresa http(s), ze které se soubor stáhne |
| `nazev` | název (výchozí = název souboru) |
| `kampan` | id kampaně |
| `tagy` | tagy oddělené čárkou |
| `poznamka` | poznámka |
| `uid`, `index`, `celkem` | velký soubor po částech: stejné `uid` (32 hex znaků), `index` od 0, `celkem` = počet částí |

Adresa (`url`) musí být veřejná: vnitřní síť a místní adresy se odmítnou, přesměrování nejvýš 3. Soubor může mít až 1 GB.
Při nahrávání po částech vrací každá část kromě poslední `{"ok": true, "hotovo": false}`; poslední vrátí médium. Části musí jít popořadě.

## Úpravy
PATCH `/media/{id}` s tělem JSON (`Content-Type: application/json`), jen pole, která chcete změnit: `nazev`, `poznamka`, `kampan` (id nebo null), `tagy` (pole), `archiv` (true/false).
Když je některé pole neplatné, nezmění se nic.

## Příklady
- Seznam videí v kampani 3: `curl -H "Authorization: Bearer tgk_…" "https://galerie.kazbo.eu/api.php/v1/media?druh=video&kampan=3"`
- Nahrání: `curl -H "Authorization: Bearer tgk_…" -F soubor=@banner.png -F kampan=3 -F "tagy=web, léto" https://galerie.kazbo.eu/api.php/v1/media`
- Z adresy: `curl -H "Authorization: Bearer tgk_…" -F url=https://example.com/foto.jpg https://galerie.kazbo.eu/api.php/v1/media`
- Úprava: `curl -X PATCH -H "Authorization: Bearer tgk_…" -H "Content-Type: application/json" -d '{"tagy":["web"],"archiv":false}' https://galerie.kazbo.eu/api.php/v1/media/12`

## Chyby
| Kód | Význam |
|---|---|
| 400 | neplatný vstup – důvod je v poli `chyba` |
| 401 | chybí nebo neplatí klíč |
| 403 | klíč má jen oprávnění ke čtení |
| 404 | médium, kampaň nebo cesta neexistuje |
| 405 | metoda na této cestě není povolená |
| 429 | příliš mnoho neplatných klíčů, počkejte 5 minut |
| 500 | chyba serveru, podrobnosti jsou v protokolu aplikace |
