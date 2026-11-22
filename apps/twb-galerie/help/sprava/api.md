---
titulek: API
sekce: sprava
admin: ne
kontext: api
souvisi: napojeni-aplikaci, verejne-odkazy, autor-a-licence
klicova: api, klíč, token, rest, json, curl, skript, automatizace, náhled, limit, etag
---
# API

API slouží skriptům a jiným aplikacím: výpis a stažení médií, nahrání (i z adresy), úpravy, kategorie a tagy. Odpovídá ve formátu JSON.
Jak galerii zapojit jako zdroj obrázků do jiné aplikace (podobně jako Pixabay), popisuje [Napojení jiné aplikace](napojeni-aplikaci).

## Klíč
Klíč vytvoříte na stránce **API a MCP** (menu Správa). Celý klíč se ukáže jen jednou, aplikace si pamatuje jen jeho otisk.

- **Čtení** smí jen požadavky GET (výpis, detail, stažení souboru).
- **Čtení i zápis** smí vše, včetně nahrávání a mazání.

Klíč se posílá v hlavičce `Authorization: Bearer tgk_…` (nebo `X-Api-Key: tgk_…`). Po 20 neplatných klíčích za 5 minut API na chvíli odpovídá chybou 429.
Zrušený klíč přestane fungovat okamžitě. API klíče nejsou v zálohách.

Jeden klíč smí poslat nejvýš 300 požadavků za minutu (správce to může změnit v config.php, `api_limit_za_minutu`). Každá odpověď má hlavičky `X-RateLimit-Limit` a `X-RateLimit-Remaining`; po překročení přijde chyba 429 s hlavičkou `Retry-After` (za kolik sekund to zkusit znovu).

## Adresy
Všechny cesty začínají `https://galerie.kazbo.eu/api.php/v1`.

| Metoda a cesta | Co dělá | Oprávnění |
|---|---|---|
| GET `/` | informace o aplikaci a klíči | čtení |
| GET `/media` | seznam médií (filtry níže) | čtení |
| GET `/media/{id}` | jedno médium | čtení |
| GET `/media/odkaz` | najde médium podle odkazu z této galerie (`?url=`) | čtení |
| GET `/media/{id}/soubor` | stáhne soubor (`?sirka=` zmenšený obrázek, `?original=1` originál před kompresí, `?stahnout=1`) | čtení |
| POST `/media` | nahraje soubor (i ZIP s obrázky) nebo stáhne soubor z adresy | zápis |
| PATCH `/media/{id}` | změní název, poznámku, kategorii, tagy, archiv, autora nebo licenci | zápis |
| POST `/media/{id}/soubor` | nahradí soubor (odkaz a údaje zůstanou) | zápis |
| POST `/media/{id}/odkaz` | vytvoří nový veřejný odkaz (starý přestane fungovat) | zápis |
| DELETE `/media/{id}/odkaz` | vypne veřejný odkaz | zápis |
| DELETE `/media/{id}` | smaže médium i soubor | zápis |
| GET `/kategorie` | seznam kategorií s počty médií | čtení |
| GET `/kategorie/{id}` | jedna kategorie | čtení |
| POST `/kategorie` | nová kategorie (`nazev`, `poznamka`) | zápis |
| PATCH `/kategorie/{id}` | úprava kategorie | zápis |
| DELETE `/kategorie/{id}` | smaže kategorii, média zůstanou bez kategorie | zápis |
| GET `/tagy` | všechny tagy s počty | čtení |
| GET `/licence` | číselník licencí (kód, název, vysvětlivka, zda má verzi, zda je povinné uvést autora) | čtení |

## Filtry seznamu médií
| Parametr | Význam |
|---|---|
| `q` | hledaná slova: každé se hledá v názvu, tazích, autorovi i poznámce, bez ohledu na velikost písmen a diakritiku („snezka“ najde „Sněžka“). Fráze v uvozovkách (`"černý les"`) se hledá vcelku; nejvýš 10 slov. |
| `q_rezim` | `vse` (výchozí: médium obsahuje všechna slova) nebo `kterykoli` (aspoň jedno); místo nich jde i `all` / `any` |
| `druh` | `image`, `video` nebo `pdf` |
| `kategorie` | id kategorie, `0` = bez kategorie, nebo název kategorie |
| `tag` | jeden tag (velikost písmen nerozhoduje) |
| `tagy` | více tagů oddělených čárkou (nejvýš 10) |
| `tagy_rezim` | `vse` (výchozí: médium má všechny tagy) nebo `kterykoli` (aspoň jeden); místo nich jde i `all` / `any` |
| `orientace` | `na_sirku`, `na_vysku` nebo `ctverec` (strany se liší nejvýš o 2 %) |
| `min_sirka`, `min_vyska` | nejmenší šířka nebo výška v px |
| `id` | jen tato média, id oddělená čárkou (nejvýš 200); bez `razeni` v zadaném pořadí |
| `archiv` | `1` = jen archiv (jinak jen média mimo archiv) |
| `razeni` | `nove` (výchozí bez `q`), `relevance` (výchozí s `q`), `stare`, `nazev`, `velikost`, `nahodne` |
| `seme` | u `razeni=nahodne`: stejné semeno = stejné pořadí (i při stránkování). Bez něj odpověď vrátí nové `seme`, které pošlete s další stránkou. |
| `strana`, `na_stranku` | stránkování, na stránku 1–200 (výchozí 48) |

Odpověď má `celkem`, `strana`, `stran` a pole `media`. Při hledání s `q` má každé médium navíc `relevance`, číslo podle toho, kde se slova našla: celý tag 5, slovo v názvu 4, část názvu nebo tagu 3, autor 2, poznámka 1, sečtené přes všechna slova. Při stejném čísle je dřív novější médium.

Každé médium má:

| Pole | Význam |
|---|---|
| `id`, `nazev`, `druh`, `mime`, `velikost` (bajty), `sirka`, `vyska`, `md5` | základní údaje |
| `orientace` | `na_sirku`, `na_vysku`, `ctverec`, nebo null (rozměr neznámý) |
| `kategorie` | `id` a `nazev`, nebo null |
| `tagy`, `poznamka` | tagy a poznámka |
| `autor` | autor |
| `licence_kod`, `licence_verze`, `licence_nazev` | licence z číselníku (`GET /licence`), např. `cc-by`, `4.0`, „CC BY 4.0“; null = neuvedena |
| `licence`, `licence_url`, `licence_odkaz` | podrobnosti licence (text), zadaný odkaz a odkaz na znění licence (zadaný, jinak podle kódu) |
| `atribuce` | hotové uvedení autora: `text` („Foto: Eva Malá, CC BY 4.0“), `html` (s odkazem na licenci) a `povinne` (true u CC BY a CC BY-SA) |
| `nahledy` | u obrázků adresy náhledů podle delší strany: `150`, `320`, `640`, `1280`, `1920`; jdou vložit do `<img>` bez klíče |
| `nahledy_plati_do` | null = adresy náhledů jsou trvalé (médium má veřejný odkaz); jinak čas, do kdy platí (1–2 hodiny) |
| `stranka_url` | trvalá adresa média (detail v administraci) – podle ní jiná aplikace pozná, že médium už má |
| `archivovano`, `vytvoreno`, `komprimovano` | archiv, datum nahrání, údaje originálu před kompresí |
| `verejny_odkaz` | veřejný odkaz na soubor (null, když je vypnutý) |
| `soubor` | adresa ke stažení přes API (s klíčem) |

## Náhledy
- Náhled je JPEG, jehož delší strana má zvolenou velikost. Menší obrázek se pošle celý v původním formátu.
- Médium s **veřejným odkazem** má trvalé adresy náhledů (`soubor.php/<klíč>/w640/…`). Ty se změní jen s novým obsahem nebo novým odkazem, takže je prohlížeče a jiné servery smějí držet v mezipaměti napořád.
- Médium **bez veřejného odkazu** má podepsané adresy (`nahled.php/…`), které platí 1–2 hodiny. Stačí na zobrazení výsledků hledání. Natrvalo si obrázek stáhněte přes `GET /media/{id}/soubor?sirka=1920` s klíčem.

## Mezipaměť (ETag)
Odpovědi na čtení mají hlavičku `ETag`. Když ji pošlete zpět v `If-None-Match` a nic se nezměnilo, API odpoví 304 bez těla. Adresy podepsaných náhledů se mění každou hodinu, takže se mění i ETag seznamu.

## Nahrání
POST `/media` jako `multipart/form-data`:

| Pole | Význam |
|---|---|
| `soubor` | soubor (obrázek, video, PDF) nebo ZIP s obrázky |
| `url` | místo souboru: veřejná adresa http(s), ze které se soubor stáhne |
| `nazev` | název (výchozí = název z metadat obrázku, jinak název souboru). Bez přípony (např. jen „Čelní pohled 30°“) se přípona doplní z názvu poslaného souboru, a když ani ten ji nemá, podle obsahu souboru |
| `kategorie` | id kategorie |
| `tagy` | tagy oddělené čárkou |
| `poznamka` | poznámka |
| `uid`, `index`, `celkem` | velký soubor po částech: stejné `uid` (32 hex znaků), `index` od 0, `celkem` = počet částí |

Typ souboru se ověřuje podle obsahu, ne podle hlavičky `Content-Type` ani přípony: soubor poslaný jako `application/octet-stream` nebo bez přípony projde, když je to obrázek, video nebo PDF. Chybová hláška u nepovoleného typu uvádí, co soubor skutečně obsahuje.

Adresa (`url`) musí být veřejná: vnitřní síť a místní adresy se odmítnou, přesměrování nejvýš 3. Soubor může mít až 1 GB.
Při nahrávání po částech vrací každá část kromě poslední `{"ok": true, "hotovo": false}`; poslední vrátí médium. Části musí jít popořadě.

U obrázku se z metadat načtou štítky (přidají se k `tagy`), autor a licence – viz [Autor, licence a metadata](autor-a-licence).
ZIP (soubor s příponou `.zip`) se rozbalí a každý obrázek se uloží zvlášť; odpověď má místo `medium` pole `media`, seznam přeskočených souborů `preskoceno` a `chyby`. Kategorie, tagy a poznámka platí pro všechny obrázky.

## Úpravy
PATCH `/media/{id}` s tělem JSON (`Content-Type: application/json`), jen pole, která chcete změnit: `nazev`, `poznamka`, `kategorie` (id nebo null), `tagy` (pole), `archiv` (true/false), `autor`, `licence`, `licence_url` (http/https; null nebo prázdný text údaj smaže), `licence_kod` (kód z `GET /licence`, null = neuvedena) a `licence_verze` (u CC číslo verze, u `jine` název licence).
Když je některé pole neplatné, nezmění se nic.

## Příklady
- Seznam videí v kategorii 3: `curl -H "Authorization: Bearer tgk_…" "https://galerie.kazbo.eu/api.php/v1/media?druh=video&kategorie=3"`
- Nahrání: `curl -H "Authorization: Bearer tgk_…" -F soubor=@banner.png -F kategorie=3 -F "tagy=web, léto" https://galerie.kazbo.eu/api.php/v1/media`
- ZIP s obrázky: `curl -H "Authorization: Bearer tgk_…" -F soubor=@fotky.zip -F kategorie=3 https://galerie.kazbo.eu/api.php/v1/media`
- Z adresy: `curl -H "Authorization: Bearer tgk_…" -F url=https://example.com/foto.jpg https://galerie.kazbo.eu/api.php/v1/media`
- Hledání „západ slunce“ kdekoli (název, tagy, autor, poznámka), stačí jedno ze slov: `curl -G -H "Authorization: Bearer tgk_…" --data-urlencode "q=západ slunce" -d q_rezim=kterykoli https://galerie.kazbo.eu/api.php/v1/media`
- Obrázky na šířku s tagem „léto“, nejméně 1200 px široké: `curl -H "Authorization: Bearer tgk_…" "https://galerie.kazbo.eu/api.php/v1/media?druh=image&tagy=léto&orientace=na_sirku&min_sirka=1200"`
- Médium podle vloženého odkazu: `curl -G -H "Authorization: Bearer tgk_…" --data-urlencode "url=https://galerie.kazbo.eu/soubor.php/…" https://galerie.kazbo.eu/api.php/v1/media/odkaz`
- Obrázek s delší stranou 1920 px: `curl -H "Authorization: Bearer tgk_…" -o foto.jpg "https://galerie.kazbo.eu/api.php/v1/media/12/soubor?sirka=1920"`
- Úprava: `curl -X PATCH -H "Authorization: Bearer tgk_…" -H "Content-Type: application/json" -d '{"tagy":["web"],"archiv":false}' https://galerie.kazbo.eu/api.php/v1/media/12`

## Chyby
| Kód | Význam |
|---|---|
| 304 | odpověď se nezměnila (poslali jste `If-None-Match` se stejným ETagem) |
| 400 | neplatný vstup – důvod je v poli `chyba` |
| 401 | chybí nebo neplatí klíč |
| 403 | klíč má jen oprávnění ke čtení |
| 404 | médium, kategorie nebo cesta neexistuje |
| 405 | metoda na této cestě není povolená |
| 429 | příliš mnoho neplatných klíčů (počkejte 5 minut) nebo požadavků s jedním klíčem (počkejte podle `Retry-After`) |
| 500 | chyba serveru, podrobnosti jsou v protokolu aplikace |
