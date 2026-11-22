# TWB Media galerie

Soukromé odkladiště médií (obrázky, videa, PDF), která si vytvoříte a zatím nepoužijete ve svých aplikacích: jeden uživatel, česky.
Média se třídí do kategorií a tagů a každé má veřejný odkaz.
Adresa nasazení `https://galerie.kazbo.eu`, slug na TWB Update Serveru `twb-galerie` (stejný je i interní slug migrací).
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` (třídy `MediaGalerie\…`) a `stranky/`.
Hosting Wedos NoLimit, PHP 8.5 (kód běží od 8.1), SQLite, bez Composeru.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, tagy, obrazky, zalohy, aktualizace, napoveda), instalace, přihlášení heslem / Google / Passkey, zámek pokusů, obnova zapomenutého hesla e-mailem, odhlášení po nečinnosti nastavitelné v aplikaci, vzhled administrace (jako TWB Reklamy), přehled, protokol, nápověda, kontrola zdraví | hotovo |
| 0.2 | Galerie: nahrávání po částech (přetažením, až 1 GB), mřížka s náhledy, přehrání videa, detail, tagy, kategorie jako složky, hledání a filtry, stránkování, archiv, nahrazení souboru, komprese s uchováním originálu (ve výchozím stavu vypnutá), hromadné akce, veřejné neuhodnutelné odkazy na soubory (Range, ETag, CORS) | hotovo |
| 0.3 | JSON API `api.php/v1` pro čtení i zápis: média (seznam s filtry, detail, stažení s Range, nahrání i po částech a z adresy, úpravy, nahrazení, odkaz, smazání), kategorie, tagy; API klíče s oprávněním čtení / zápis (jen otisk), zámek proti zkoušení klíčů, stránka API, popis v nápovědě | hotovo |
| 0.4 | Zálohy (denní cronem, ruční, stažení, obnova v rozhraní s náhledem obsahu a pojistnou zálohou), aktualizace z Update Serveru (`twb-galerie`), cron s úklidem, upozornění v přehledu, nápověda, balíčky FULL a UPDATE | hotovo |
| 0.4.1 | Kampaně přejmenované na **kategorie** (rozhraní, nápověda, API `/kategorie` a pole `kategorie`, tabulka `categories`, sloupec `media.category_id`; schéma 4 převede starší instalace) a upřesněný účel: odkladiště vlastních médií, která zatím nejsou použitá v aplikacích | hotovo |
| 0.5 | Nahrání ZIPu s obrázky (galerie i API), název, štítky, autor a licence z metadat obrázku (XMP, IPTC, EXIF), autor a licence u média (detail, API, hledání), nápověda „Autor, licence a metadata“ | hotovo |
| 0.5.1 | Galerie: nahrávání ve dvou sloupcích (soubory vlevo, kategorie a tagy vpravo), tagy jako štítky s našeptáváním (galerie i detail), výběr médií tažením myši (Ctrl/Shift přidá, Esc zruší, posun u okraje) | hotovo |
| 0.6 | Galerie jako zdroj médií pro jiné aplikace (podobně jako Pixabay nebo Wikimedia Commons): náhledy 150–1920 px (trvalé u veřejného odkazu, jinak podepsané na 1–2 h), stažení v dané velikosti, licence jako kód ze společného modulu `licence` (shodný se Social Plannerem) s verzí a hotovou atribucí, orientace, trvalá adresa média, rozpoznání vloženého odkazu (`/media/odkaz`), filtry orientace, min. rozměry, více tagů, kategorie podle názvu, výběr podle id, náhodné pořadí se semenem, číselník `/licence`, ETag/304, limit požadavků na klíč; nápověda „Napojení jiné aplikace“ | hotovo |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení, zapomenuté heslo (?zapomenute), nové heslo z e-mailu (?reset=token)
install.php      první spuštění: config.php (když chybí), databáze, heslo, e-mail pro obnovu hesla
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
health.php       kontrola po aktualizaci (modul aktualizace)
nahrat.php       nahrávání po částech (JSON, jen přihlášený, CSRF v hlavičce X-CSRF), i nahrazení souboru
media.php        soubor v administraci (jen přihlášený): ?id=, &nahled=1, &original=1, &stahnout=1
soubor.php       veřejný odkaz soubor.php/<klíč>/<název> (bez přihlášení, ?stahnout=1), náhled soubor.php/<klíč>/w<px>/<název>
nahled.php       podepsaný náhled nahled.php/<id>/<px>/<platí do>/<podpis>/<název> pro média bez veřejného odkazu (adresy vydává API)
cron.php         pravidelné úlohy: cron.php?key=<cron_key> – denní záloha, kontrola aktualizací, úklid nahrávání, náhledů a protokolu
api.php          JSON API api.php/v1/… (klíč v Authorization: Bearer nebo X-Api-Key), popis help/sprava/api.md
lib/             App (služby a moduly), Schema, Media (i náhledy a podpisy), Metadata, Kategorie, Vydej, Api, ApiKlice, ZAdresy, Ui, Vstup, Http, ProtokolDb, Chyba, autoload, bootstrap
stranky/         prehled, galerie, medium (detail), kategorie, api (klíče), nastaveni, protokol, napoveda, prihlaseni
help/            nápověda v Markdownu (_obsah.json + sekce/stránka.md), vykresluje modul napoveda
assets/          style.css, app.js (nahrávání, štítky tagů `input[data-stitky]`, výběr tažením, Passkey…), ikona.svg
data/            databáze galerie.sqlite, média (media/), náhledy (nahledy/, dají se kdykoli vytvořit znovu), části nahrávání (tmp/),
                 zálohy (backups/) – chráněno .htaccess
```

## Přihlášení
- Heslo je vždy záložní způsob; Google (jediný propojený účet, porovnává se `sub`) a Passkey se jen přidávají v **Nastavení → Zabezpečení**.
- Jeden společný zámek pokusů (modul prihlaseni `Zamek`) pro heslo, Google, Passkey i neplatný odkaz obnovy: 5 chyb → 5 minut.
- Obnova hesla (`TWB\Prihlaseni\Obnova`): odkaz `{base_url}/index.php?reset={token}` jen na e-mail z nastavení `reset_email`, platí 30 minut, jednou, nejvýš 1 žádost za 2 minuty; odpověď je vždy stejná.
  E-mail se posílá přes `mail()` s odesílatelem `mail_from`; `mail_to_file` v config.php (jen testy) ukládá e-maily do složky.
- Odhlášení po nečinnosti: nastavení `idle_minutes` (0 = nikdy, 5–1440), bez něj `session_timeout_min` z config.php (výchozí 120).
- Tabulky `passkeys` a `auth_tokens` nejsou v zálohách (`App::TABULKY`), nastavení zámku a obnovy jsou místní (`App::MISTNI`).

## Galerie (0.2)
- **Tabulka `media`** je z modulu galerie (soubory, názvy, druh, velikost, MD5, rozměry, originál před kompresí, tagy, archiv). Aplikace (schéma 2) přidává
  `category_id`, `note` a `public_key`; modul tyto sloupce nezná a nečte. Kategorie jsou v tabulce `categories` (je v zálohách, obnovuje se před `media`).
- **Nahrávání** (`nahrat.php`, `assets/app.js`): soubor po částech 2 MB (`App::CAST_NAHRANI`) přes `TWB\Galerie\Nahravani`, nejvýš 1 GB (`App::MAX_NAHRANI`),
  při výpadku 3 pokusy; po poslední části `Galerie::uloz` (kontrola typu i obsahu) a `Media::poNahrani` (veřejný klíč, kategorie). Nedokončené části starší než hodina se mažou při dalším nahrávání.
- **Veřejný odkaz** (`soubor.php`): klíč 24 hex znaků (96 bitů, `Media::novyKlic`), unikátní index. Funguje i pro archivovaná média; „Nový odkaz“ starý zneplatní,
  „Vypnout odkaz“ nastaví `public_key = NULL`. Po nahrazení souboru zůstává stejný (ETag = MD5, `max-age=3600`).
- **Vydání souborů** (`Vydej`): Range (přetáčení videa, 206/416), If-None-Match (304), `nosniff`, CSP `sandbox` (kromě PDF, které v sandboxu Chrome nezobrazí),
  `Content-Disposition` s názvem. Veřejné soubory navíc `Access-Control-Allow-Origin: *` a `X-Robots-Tag: noindex`.
- **Náhledy** (`Media::nahled`): obrázky delší než 480 px se pro mřížku zmenší do JPEG v `data/nahledy/<id>-<md5>.jpg`; obrázky nad 40 Mpx se posílají celé (paměť GD).
  Videa ukazuje prohlížeč z `media.php` (`preload="metadata"`), PDF ikonou.
- **Komprese**: nastavení modulu obrazky (`img_auto`, `img_max_w`, `img_max_h`, `img_quality`, `img_min_kb`) v Nastavení. Aplikace při první migraci nastaví `img_auto = 0` –
  odložená média se ukládají v plné kvalitě.

## ZIP a metadata (0.5)
- **Metadata** (`Metadata::cti`): bez knihoven. XMP = textový blok `<x:xmpmeta>` v prvních 4 MB souboru (JPEG, PNG, WebP, TIFF), čte se DOM bez sítě
  a s DOCTYPE se odmítne (XXE); IPTC přes `getimagesize` + `iptcparse` (UTF-8 podle 1:90, jinak Windows-1250); EXIF přes `exif_read_data`
  s `exif.encode_unicode = UTF-8` (Windows XP… pole). Přednost: XMP → IPTC → EXIF, štítky ze všech zdrojů bez opakování.
- **Nahrání** (`Media::nahraj`): společné pro `nahrat.php`, API i ZIP. Metadata se čtou z dočasného souboru ještě před `Galerie::uloz`
  (před kompresí); název = titulek + přípona (`Media::nazevZTitulku`), když ho nezadal klient API; štítky se přidají k zadaným tagům
  (`Media::spojTagy`, jen platné a nejvýš 10). Nahrazení souboru metadata nečte.
- **Autor a licence**: sloupce `media.author`, `license`, `license_url` (schéma 5), `Media::nastavAutora`; odkaz jen http(s).
- **ZIP** (`Media::nahrajZip`): jen obrázky (`Media::ZIP_PRIPONY`), i z podsložek; přeskočí složky, skryté soubory, `__MACOSX`, `Thumbs.db`
  a jiné typy (vrátí je v `preskoceno`); nejvýš 2 000 položek a 1 GB rozbaleně; každá položka se rozbaluje po 1 MB a nesmí být větší, než ZIP uvádí
  (zip bomba); zaheslované položky se odmítnou; jména z Windows v CP852 se převedou. Chybný obrázek se nahlásí v `chyby`, ostatní se uloží.

## API (0.3)
- **Směrování** v `Api::smeruj` (cesty `/`, `/media`, `/media/{id}`, `/media/{id}/soubor`, `/media/{id}/odkaz`, `/kategorie`, `/kategorie/{id}`, `/tagy`). Test hlídá,
  že každou cestu popisuje `help/sprava/api.md`. PATCH přijímá JSON; soubory jen POST (PHP multipart u PATCH/PUT nečte).
- **Klíče** (`ApiKlice`, tabulka `api_keys`, schéma 3): `tgk_` + 40 hex, v databázi SHA-256 a prvních 12 znaků; ukáže se jednou (stránka API, bez přesměrování
  a bez uložení do relace). Oprávnění `read` = jen GET/HEAD, `write` = vše. Tabulka se nezálohuje. Po 20 neplatných klíčích za 5 minut odpověď 429
  (počítadlo `api_fails` / `api_fail_since` je místní nastavení).
- **Úpravy** médií se nejdřív celé ověří a pak zapíšou v transakci (neplatný tag nezmění ani název). Neznámé pole = 400.
- **Stažení z adresy** (`ZAdresy`): jen http(s) na portech 80/443, bez jména a hesla v adrese, všechny IP adresy hostitele musí být veřejné
  (ne soukromé, rezervované, link-local, 100.64/10, IPv4 v IPv6), spojení přes `CURLOPT_RESOLVE` na ověřenou IP, přesměrování nejvýš 3 a každé se ověří,
  velikost se hlídá během stahování (1 GB). `stahovani_http` v config.php nahradí stahování (jen testy).
- **Apache** předává hlavičku Authorization přes `SetEnvIf` v `.htaccess` (PHP jako CGI/FastCGI ji jinak nevidí); záloha je hlavička `X-Api-Key`.

## Galerie jako zdroj pro jiné aplikace (0.6)
- **Návrh** vychází z Knihovny médií Social Planneru 1.9.2 (`app/lib/stockimg.php`): aplikace volá zdroje **ze serveru**, výsledky převádí
  do společného tvaru (název, náhled ~320 px, stažení ≤ 2000 px, rozměry, trvalá adresa stránky kvůli duplicitám, autor, licence jako kód),
  obrázek si stáhne k sobě. Galerie je proto pro ostatní aplikace další zdroj s klíčem jen ke čtení; klíč pro prohlížeč (CORS) není potřeba.
- **Náhledy** (`Media::varianta`, `Media::NAHLEDY` = 150, 320, 640, 1280, 1920 px delší strany): JPEG s bílým podkladem v `data/nahledy/<id>-<md5>-<px>.jpg`,
  vzniknou při prvním použití; menší obrázek nebo nad 40 Mpx = původní soubor. Úklid smaže náhledy jiného obsahu i starý tvar `<id>-<md5>.jpg` z 0.5.
- **Adresy náhledů** (`Media::adresyNahledu`): médium s veřejným odkazem → `soubor.php/<klíč>/w<px>/<název>.jpg?v=<md5>` (cache rok, immutable);
  bez odkazu → `nahled.php/<id>/<px>/<platí do>/<HMAC>` (HMAC-SHA256 s místním nastavením `nahled_tajemstvi`, platnost zaokrouhlená na celé hodiny,
  1–2 h, delší platnost se odmítne). Obě s CORS `*` a `Cross-Origin-Resource-Policy: cross-origin`, takže jdou do `<img>` na jiném webu.
- **Licence**: modul `licence` (`TWB\Licence\Licence`: číselník `KODY` shodný s `IMG_LICENSES`, `vycisti`, `odhad`, `nazev`, `odkaz`, `uvedeni`).
  Sloupce `media.license_code`, `license_version` (schéma 6, dosavadní text se převede odhadem; „©…“ zůstane neurčený). Text `license` zůstává jako podrobnosti.
  Při nahrání obrázku se kód odhadne z metadat. Detail média: výběr licence, verze, podrobnosti, odkaz; zobrazí „Uvedení autora“.
- **API**: nová pole média `licence_kod`, `licence_verze`, `licence_nazev`, `licence_odkaz`, `atribuce` (`text`, `html`, `povinne`), `orientace`, `nahledy`,
  `nahledy_plati_do`, `stranka_url`; `GET /media/odkaz?url=` (detail, veřejný odkaz, náhled, adresa API – jen tento server), `GET /licence`,
  `GET /media/{id}/soubor?sirka=`; filtry `orientace`, `min_sirka`, `min_vyska`, `tagy` + `tagy_rezim`, `kategorie` i názvem, `id=1,2,3`, `razeni=nahodne` + `seme`.
- **Šetrnost**: čtení má `ETag` a `Cache-Control: private, no-cache` (If-None-Match → 304). Limit požadavků na klíč za minutu (`api_limit_za_minutu`
  v config.php, výchozí 300, 0 = vypnuto; sloupce `api_keys.rate_window`, `rate_count`), hlavičky `X-RateLimit-Limit`, `X-RateLimit-Remaining`, při překročení 429 + `Retry-After`.
- **Prompt pro napojení** jiné aplikace (adaptér hledání, odkaz, převod, stažení, licence): v projektových souborech `twb-galerie-api/prompt-napojeni-galerie.md`;
  totéž stručně v nápovědě `help/sprava/napojeni-aplikaci.md`.

## Zálohy, aktualizace a cron (0.4)
- **Zálohy** (modul zalohy, `App::zalohy`): šifrovaný ZIP `twb-galerie-…zip` v `data/backups/` s tabulkami `App::TABULKY` a skupinou souborů `media`
  (média i originály). Denní záloha z cronu (`backup_auto`, nutné heslo záloh), ruční v Nastavení. Obnova: výběr nebo nahrání souboru → náhled
  (počet médií, kategorií, souborů; potvrzení v relaci `twbg_obnova` platí 10 minut a jednou) → pojistná záloha → obnova. Místní nastavení (`App::MISTNI`),
  heslo, Passkey a API klíče zůstávají. Veřejné odkazy jsou v tabulce `media`, takže po obnově platí ty ze zálohy.
- **Aktualizace** (modul aktualizace): Update Server, slug `twb-galerie`, kořen balíčku `twb-galerie/`, `config.php` a `data/` se nemění; `update_token` v config.php.
- **Cron**: klíč `cron_key` (instalace vygeneruje náhodný), čas posledního běhu `cron_last_run`; přehled upozorní, když cron neběží 2 dny, když chybí heslo záloh
  nebo je k dispozici nová verze.

## Testy a vydání
```
php tests/run.php twb-galerie              # testy aplikace (vestavěný server PHP)
php tools/sestavit.php twb-galerie         # dist/twb-galerie-<verze>-full.zip a -update.zip
```

## Instalace (Wedos NoLimit)
1. Nahrát obsah `twb-galerie-<verze>-full.zip` (složka `twb-galerie/`) přes FTP do kořene domény galerie.kazbo.eu.
2. Otevřít `https://galerie.kazbo.eu/` → instalace vytvoří `config.php` (s náhodným `cron_key`) a databázi, nastaví heslo a e-mail pro obnovu hesla.
3. V `config.php` doplnit podle potřeby `google_client_id`, `google_client_secret` (přesměrování `https://galerie.kazbo.eu/google.php`) a `update_token`.
4. V PHP na Wedosu zvýšit `upload_max_filesize` a `post_max_size` aspoň na 8 MB (nahrávání jde po 2 MB částech).
5. V administraci Wedos nastavit cron na adresu z Nastavení → Systém (každých 5–15 minut).
6. Nastavení → Zálohy: heslo záloh. Google účet a Passkey se propojují v Nastavení → Zabezpečení, API klíče na stránce API.
