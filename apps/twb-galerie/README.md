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
| 0.6.1 | Hledání `q` jako fulltext: každé slovo v názvu, tazích, autorovi i poznámce, bez diakritiky a velikosti písmen, fráze v uvozovkách, řazení podle relevance (`razeni=relevance`, výchozí s `q`, pole `relevance`), `q_rezim` všechna / kterékoli slovo, aliasy `all` / `any` u `q_rezim` i `tagy_rezim`; totéž hledání v galerii v administraci | hotovo |
| 0.6.2 | Galerie v administraci: přepínač zobrazení **Mřížka / Seznam / Jen média** (zapamatuje se), větší náhled obrázku (1920 px) nebo přehrání videa v modálním okně s listováním šipkami; `media.php?nahled=<px>` | hotovo |
| 0.6.3 | Okno náhledu jednotně v celé aplikaci (galerie, přehled, detail včetně originálu, PDF a veřejného odkazu); nic se neotevírá v nové kartě, Ctrl i prostřední klik otevřou okno; v okně Stáhnout místo Původní soubor | hotovo |
| 0.6.4 | Ctrl/Cmd+klik a prostřední klik na náhled otevře **detail média ve stejné kartě** (ne zvětšený obrázek ani novou kartu); v detailu, kde detail není kam otevřít, otevře okno | hotovo |
| 0.8.0 | **Veřejný web** na kořeni domény (`verejne.php` přes `DirectoryIndex`, administrace zůstává na `index.php`): hlavní stránka podle návrhu (logo, menu Domů / Galerie / O galerii, tlačítko Přihlášení do administrace, úvodní fotka s nadpisem a tlačítkem, dvě reklamní plochy, karty Vybrané galerie s obálkou a počtem fotografií, patička), stránky Galerie (veřejné kategorie, nejnovější fotky), galerie kategorie (mřížka, stránkování, okno náhledu) a O galerii. Ukazuje jen kategorie označené jako veřejné a v nich obrázky s veřejným odkazem mimo archiv. Stránka administrace **Veřejný web** (texty, úvodní fotka, reklamní plochy, O galerii, vypnutí), u kategorie zveřejnění, popis, obálka a pořadí, v detailu tlačítko Úvodní fotka webu. **Hromadná akce Komprimovat obrázky** (JPEG/WebP, kvalita, největší rozměr, ponechat originál, zachovat metadata) po dávkách se souhrnem ušetřeného místa. Moduly obrazky 0.2.0 (WebP, přenos metadat JPEG) a galerie 0.2.0 (`komprimujPodrobne`) | hotovo |
| 0.8.1 | **Nastavení v kartách** místo jedné dlouhé stránky: Zabezpečení (heslo, obnova hesla, automatické odhlášení, Google, Passkey), Komprese obrázků, Zálohy (nastavení a obnova vlevo, uložené zálohy se Zálohovat teď vpravo), Aktualizace, Systém. Karta se přepne bez načtení stránky a zapamatuje v adrese (`?karta=…#…`), takže obnovení i odkaz vedou zpět; po uložení formuláře i po chybě se vrátí na kartu formuláře, náhled obnovy zálohy zůstane na kartě Zálohy. Staré odkazy `#zalohy`, `#obnova`… otevřou správnou kartu. Tečka u karty, která chce pozornost (chybí e-mail pro obnovu nebo heslo záloh, nová verze, cron neběží). Šipky na klávesnici, na mobilu se lišta karet posouvá do strany | hotovo |
| 0.8.2 | **Reklamy jako kódy z reklamních systémů**: každá reklamní plocha (pod úvodní fotkou, pod galeriemi) má volbu *Obsah plochy* – vlastní text a tlačítko (jako dosud) nebo **kód z reklamního systému** (AdSense, Sklik, Etarget…; HTML/JS se na web vloží bez úprav, nepovinný štítek „Reklama“ nad ním). Nová pole **Kód do hlavičky stránky** (ověření webu, automatické reklamy, měřicí kód) a **Obsah ads.txt** (web ho vydá na `/ads.txt`). Stránka s kódem dostane volnější CSP (skripty, rámečky a styly z https), stránka bez kódu zůstává na přísné. Dosavadní textové plochy se nemění | hotovo |
| 0.7.2 | **MCP a ZIP:** `nahraj_medium` se ZIPem zapíše autora, licenci a údaje o AI (`ai_model` → autor a licence „Vygenerováno AI“) každému rozbalenému obrázku, ne jen tagy, kategorii a poznámku. Popis nástroje ZIP zmiňuje, takže ho AI klient zná. Nápověda „Připojení AI (MCP)“ má oddíl ZIP s obrázky | hotovo |
| 0.7.1 | **Oprava:** nahrání přes API, MCP i v aplikaci už nespadne na „Nepodporovaný typ souboru .“, když jiná aplikace pošle jako `nazev` jen titulek bez přípony (např. „Čelní pohled 30°“) nebo soubor jako `application/octet-stream`. Přípona se vezme z názvu poslaného souboru, jinak podle obsahu (finfo, bez něj první bajty) – jen z povolených typů. Hláška uvádí zjištěný typ obsahu | hotovo |
| 0.7.0 | **MCP server** (`mcp.php`) pro Claude, ChatGPT a další AI klienty: médium vytvořené AI jde poslat rovnou do galerie (z adresy nebo base64) s názvem, tagy, kategorií, autorem, licencí, modelem a promptem; hledání, detail, úprava údajů, kategorie, licence a tagy. Přihlášení OAuth 2.1 (konektor v Claude.ai / ChatGPT, souhlas v administraci) nebo API klíčem (Claude Code). Nový modul platformy `mcp`. Stránka **API a MCP** s adresou a seznamem připojení, nápověda „Připojení AI (MCP)“ | hotovo |
| 0.6 | Galerie jako zdroj médií pro jiné aplikace (podobně jako Pixabay nebo Wikimedia Commons): náhledy 150–1920 px (trvalé u veřejného odkazu, jinak podepsané na 1–2 h), stažení v dané velikosti, licence jako kód ze společného modulu `licence` (shodný se Social Plannerem) s verzí a hotovou atribucí, orientace, trvalá adresa média, rozpoznání vloženého odkazu (`/media/odkaz`), filtry orientace, min. rozměry, více tagů, kategorie podle názvu, výběr podle id, náhodné pořadí se semenem, číselník `/licence`, ETag/304, limit požadavků na klíč; nápověda „Napojení jiné aplikace“ | hotovo |

## Struktura
```
verejne.php      veřejný web (kořen domény přes DirectoryIndex): ?s=domu | galerie [&k=<id-název>&strana=] | o-galerii; index.php?s=… sem předá také
index.php        administrace (?p=stránka), přihlášení, zapomenuté heslo (?zapomenute), nové heslo z e-mailu (?reset=token)
install.php      první spuštění: config.php (když chybí), databáze, heslo, e-mail pro obnovu hesla
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
health.php       kontrola po aktualizaci (modul aktualizace)
nahrat.php       nahrávání po částech (JSON, jen přihlášený, CSRF v hlavičce X-CSRF), i nahrazení souboru
media.php        soubor v administraci (jen přihlášený): ?id=, &nahled=1 (480 px) nebo &nahled=<px> (150–1920), &original=1, &stahnout=1
soubor.php       veřejný odkaz soubor.php/<klíč>/<název> (bez přihlášení, ?stahnout=1), náhled soubor.php/<klíč>/w<px>/<název>
nahled.php       podepsaný náhled nahled.php/<id>/<px>/<platí do>/<podpis>/<název> pro média bez veřejného odkazu (adresy vydává API)
cron.php         pravidelné úlohy: cron.php?key=<cron_key> – denní záloha, kontrola aktualizací, úklid nahrávání, náhledů a protokolu
api.php          JSON API api.php/v1/… (klíč v Authorization: Bearer nebo X-Api-Key), popis help/sprava/api.md
mcp.php          MCP server pro AI klienty (Streamable HTTP, OAuth token tgoa_… nebo API klíč tgk_…), popis help/sprava/mcp.md
oauth.php        OAuth 2.1 pro MCP konektory: ?akce=metadata|metadata-zdroje|register|authorize|token|revoke
.well-known/     oauth-authorization-server a oauth-protected-resource (přepis v .htaccess na PHP soubory → oauth.php)
lib/             App (služby a moduly), Schema, Web (veřejný web), Komprese (hromadná komprese), Media (i náhledy a podpisy), Hledani, Metadata, Kategorie, Vydej, Api, ApiKlice, Mcp (nástroje MCP), ZAdresy, Ui, Vstup, Http, ProtokolDb, Chyba, autoload, bootstrap
stranky/         prehled, galerie, medium (detail), komprese (průběh hromadné komprese), kategorie, web (Veřejný web), api (klíče a připojení MCP), nastaveni, protokol, napoveda, prihlaseni, souhlas (OAuth)
help/            nápověda v Markdownu (_obsah.json + sekce/stránka.md), vykresluje modul napoveda
assets/          verejne.css a verejne.js (veřejný web), style.css, app.js (nahrávání, štítky tagů `input[data-stitky]`, výběr tažením, okno náhledu, Passkey…), ikona.svg
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
- **Typ souboru** (0.7.1) rozhoduje obsah: `Galerie::zjistiMime` (finfo, záloha podle prvních bajtů), `nazevSPriponou` doplní příponu k názvu bez povolené přípony. API nejdřív zkusí příponu z názvu poslaného souboru, MCP (`data_base64`) dá přednost obsahu před udaným `mime`.
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

## Hledání (0.6.1)
- `Hledani` (lib/Hledani.php): normalizace (malá písmena, bez diakritiky přes `Normalizer` NFD a záložní mapu pro hosting bez intl), výrazy z dotazu
  (slova, fráze v uvozovkách, nejvýš 10), skóre podle polí (`Hledani::VAHY`). `Media::seznam` nejdřív zúží řádky SQL filtry, pak hledá v PHP
  (bez FTS5: na hostingu nemusí být a diakritiku neumí; galerie jednoho uživatele má tisíce, ne miliony položek). Skóre jde k řádku jako `_skore`.

## Zobrazení galerie a okno náhledu (0.6.2)
- **Přepínač** nad mřížkou: `Mřížka` (náhled s názvem, rozměry, kategorií, tagy a odkazem), `Seznam` (řádek s malým náhledem, druhem a datem nahrání),
  `Jen média` (jen obrázky bez popisků, oříznuté do čtverců, zaškrtávátko se ukáže po najetí myší nebo když je něco vybráno). Volby jsou `Media::ZOBRAZENI`.
  Odkaz `?p=galerie&zobrazeni=<mrizka|seznam|media>` zobrazení přepne a uloží do nastavení `galerie_zobrazeni` (místní, obnova zálohy ho nemění);
  další stránky, filtry a hromadné akce už parametr nepotřebují. Všechna zobrazení mají stejné `<figure>`, takže hromadné akce i výběr tažením fungují ve všech.
- **Okno náhledu** (0.6.3 jednotné pro celou aplikaci, `assets/app.js` + `Ui::okno`): prvek s `data-otevrit-okno` uvnitř skupiny `data-okno-skupina`
  otevře médium v modálním okně; položky skupiny nesou atributy z `Ui::okno($m, $verejna, $src, $detail, $popis)` (`data-okno` druh, `data-okno-src`,
  název, popis, odkaz na detail, stažení, veřejný odkaz). Obrázek je náhled 1920 px (`Media::NAHLED_VELKY_PX`, `media.php?id=&nahled=1920`; menší obrázek
  se pošle celý), video se přehraje, PDF se ukáže v rámu. Šipky ← → (i tlačítka) listují skupinou, sousední obrázky se načtou dopředu; Esc, křížek nebo klik
  vedle okno zavře. Dole název, rozměry, pořadí a tlačítka Detail (ve stejné kartě, v detailu se skryje), Stáhnout a Kopírovat odkaz.
  Skupiny: mřížka galerie, Naposledy nahrané v přehledu, obrázek / PDF v detailu, „zobrazit“ u originálu před kompresí, „Otevřít“ u veřejného odkazu.
  **Nic se neotevírá v nové kartě**: Ctrl/Cmd+klik i prostřední tlačítko na náhledu otevřou detail média ve stejné kartě (0.6.4; kde položka
  nemá detail, tedy v detailu samotném, otevřou okno); na detail vede také název média a tlačítko Detail v okně. Shift+klik otevře okno.
  V nové kartě zůstává jen externí odkaz „podmínky licence“.
- `media.php?nahled=<px>` vydá variantu `Media::varianta` pro px z `Media::NAHLEDY` (ETag obsahuje velikost); `nahled=1` zůstává 480 px pro mřížku.

## MCP server (0.7)
Účel: AI asistent (Claude, ChatGPT …) pošle médium, které vytvořil, rovnou do galerie – i s názvem, tagy, kategorií a údaji o AI.

- **Adresa** `{base_url}/mcp.php`, Streamable HTTP bez trvalého spojení (POST = JSON-RPC, odpověď JSON; GET 405, DELETE 204, OPTIONS 204 s CORS).
  Protokol, verze (2024-11-05 až 2025-11-25 s `initialize`, bezstavová 2026-07-28 se `server/discover`) a chyby řeší modul **`mcp`** (`TWB\Mcp\Server`), převzato z MCP Social Planneru.
- **Přihlášení** (v `mcp.php`): token z `Authorization: Bearer …`
  - `tgk_…` = API klíč galerie (Claude Code, skripty): stejný zámek po neplatných klíčích a limit požadavků jako API; klíč Čtení = oprávnění `read`, Čtení i zápis = `read write`.
  - `tgoa_…` = OAuth přístupový token (`TWB\Mcp\OAuth`): konektor v Claude.ai / ChatGPT. Dynamická registrace klienta, PKCE S256, kód 5 min, přístupový token 1 h,
    refresh 90 dní s rotací, v databázi jen SHA-256. Souhlas `stranky/souhlas.php` (oauth.php?akce=authorize) vyžaduje přihlášení do administrace
    (nepřihlášeného pošle na přihlášení a `index.php` ho vrátí zpět přes `$_SESSION['po_prihlaseni']`); zápis jde při souhlasu odškrtnout.
    Opětovné připojení téže aplikace zruší starší připojení. Připojení jsou na stránce **API a MCP** (odebrání zruší všechny tokeny).
  - bez platného tokenu 401 s `WWW-Authenticate: Bearer resource_metadata="{base_url}/oauth.php?akce=metadata-zdroje"`.
- **Metadata**: `/.well-known/oauth-authorization-server` (RFC 8414) a `/.well-known/oauth-protected-resource` (RFC 9728) – `.well-known/.htaccess` přepisuje na PHP soubory.
  Aplikace proto musí běžet v kořeni domény.
- **Tabulky** modulu: `oauth_clients`, `oauth_grants`, `oauth_codes`, `oauth_tokens` (migrace `mcp` v `module_versions`); nejsou v zálohách.
  `App::migrujPodlePotreby()` (api.php, mcp.php, oauth.php) spustí migraci i u instalace, kde je schéma aplikace aktuální, ale chybí tabulky modulu.
- **Nástroje** (`MediaGalerie\Mcp`, české názvy jako v Social Planneru) volají `Api::vnitrni()` – stejné cesty, kontroly a odpovědi jako API v1:
  `nahraj_medium` (zápis; `url` nebo `data_base64` do 20 MB, i `data:` URI; bez přípony se určí podle `mime` nebo obsahu; ZIP se rozbalí a od 0.7.2 dostane autora, licenci i údaje o AI každý obrázek),
  `hledej_media` (stručné položky, nejvýš 50 na stránku), `detail_media` (id nebo odkaz), `uprav_medium` (zápis), `seznam_kategorii`,
  `vytvor_kategorii` (zápis), `seznam_licenci`, `seznam_tagu`. Mazat přes MCP nejde. Kategorie jde zadat id nebo názvem.
- **Médium z AI**: `ai_model` / `ai_prompt` → licence `ai` (pokud není `licence_kod`), autor = model (pokud není `autor`), do poznámky „Vytvořeno AI (model).“ a „Prompt: …“
  (poznámka do 5000 znaků, prompt se zkrátí) – fulltext pak najde médium i podle promptu. Autor a licence se ověří před uložením souboru.
- Každý zápis přes MCP je v protokolu i s klientem (`MCP (Claude): nahraj_medium`).
- **Připojení v Claude**: Nastavení → Konektory → Přidat vlastní konektor → adresa `https://galerie.kazbo.eu/mcp.php` (OAuth Client ID nechat prázdné) → přihlásit se do galerie
  a povolit. Claude Code: `claude mcp add --transport http galerie https://galerie.kazbo.eu/mcp.php --header "Authorization: Bearer tgk_…"`.

## Veřejný web (0.8)
- **Adresy**: `.htaccess` má `DirectoryIndex verejne.php index.php`, takže `https://galerie.kazbo.eu/` je veřejný web a administrace zůstává
  na `index.php` (přihlášení, `?p=…`, odkaz obnovy hesla, Google, OAuth – vše míří na `index.php` výslovně). Odkazy webu jsou `./`, `?s=galerie`,
  `?s=galerie&k=<id>-<název>` a `?s=o-galerii`; `index.php` s `s=` a bez `p=` (jen GET) předá na `verejne.php`, kdyby hosting `DirectoryIndex` nepoužil.
  Tlačítko Přihlášení do administrace vede na `index.php?p=prehled`.
- **Co je veřejné** (`MediaGalerie\Web`, podmínka `VEREJNA`): kategorie s `categories.web_public = 1` a v ní média `kind = image`, `archived_at IS NULL`,
  `public_key IS NOT NULL`. Výchozí stav po aktualizaci: žádná kategorie veřejná, web ukáže „Galerie se připravuje“. Vypnutý veřejný odkaz
  fotku z webu odebere. Videa, PDF a originály se na webu neukazují.
- **Obrázky** jsou trvalé náhledy veřejného odkazu (`Media::adresyNahledu`, 640 px v kartách a mřížce, 1920 px úvodní fotka a okno náhledu),
  takže je drží mezipaměť prohlížeče a nová podepsaná adresa není potřeba.
- **Schéma 7**: `categories.web_public`, `web_description`, `web_cover_id` (obálka; když není veřejná, nejnovější fotka), `web_order` (pořadí, pak název).
- **Nastavení** `web` (JSON, je v zálohách): zapnuto, název, štítek, nadpis, podnadpis, tlačítko, poznámka, `uvod_id` (úvodní fotka – obrázek
  s veřejným odkazem mimo archiv; bez ní obálka první veřejné kategorie), vybrané galerie (nadpis, podnadpis, počet 0–12), `banner1` a `banner2`
  (zobrazit, `typ` text/kod, štítek, text, tlačítko, odkaz – jen http(s), mailto, tel nebo adresa na webu, `kod`), `kod_hlavicka`, `ads_txt`,
  text O galerii (Markdown modulu napoveda), patička, tlačítko administrace, `noindex`. Vypnutý web přesměruje na `index.php`.
- **Reklamní kódy (0.8.2)**: plocha s `typ = kod` vloží `kod` bez escapování do `<aside class="banner-kod">` (štítek drobně nad ním), `kod_hlavicka`
  jde na konec `<head>` každé stránky webu. Kód zadává jen přihlášený správce v administraci (tam se jen escapovaně ukáže v poli, nespouští se);
  kontroluje se jen délka (`Web::MAX_KOD` = 20 000 znaků), konce řádků se sjednotí. Zobrazená plocha s kódem musí kód mít; textová pole i kód
  zůstávají uložené, takže přepnutí typu nic neztratí. Starší nastavení bez `typ` = text.
- **ads.txt**: `.htaccess` (mod_rewrite) přepíše `/ads.txt` na `verejne.php?s=ads.txt` (jde i `index.php?s=ads.txt`), vydá `text/plain` z `ads_txt`,
  prázdné = 404.
- **Hlavičky**: `Cache-Control: public, max-age=60`, CSP jen vlastní skripty a styly, `X-Frame-Options: SAMEORIGIN`. Když stránka ukazuje reklamní
  kód (plocha s kódem nebo kód v hlavičce), `Web::csp(true)` povolí `https:` a `'unsafe-inline'` pro skripty, styly, rámečky a spojení (AdSense a Sklik
  načítají skripty z mnoha domén a vkládají vložené skripty); `frame-ancestors`, `base-uri` a `form-action` zůstávají `'self'`. Skript `assets/verejne.js` (menu na mobilu, okno
  náhledu se šipkami, Esc a posunem prstu), styl `assets/verejne.css` (bez externích písem).

## Hromadná komprese (0.8)
- Galerie → hromadná akce **Komprimovat obrázky**: formát JPEG (průhledné zůstanou PNG) nebo WebP, kvalita 40–95, největší rozměr (`Komprese::ROZMERY`),
  **Ponechat originál** (výchozí) a **Zachovat metadata** (JPEG → JPEG: EXIF s orientací 1, XMP, IPTC, ICC; modul obrazky 0.2.0).
- **Po krocích** (`MediaGalerie\Komprese`): úloha v místním nastavení `komprese_uloha`, stránka `?p=komprese` volá `akce=krok` (JSON, `Accept: application/json`)
  dokud není hotovo; krok trvá nejvýš 8 s a aspoň jeden obrázek, `set_time_limit(120)`, `ignore_user_abort`. Před každým obrázkem se zapíše `rozpracovano`;
  kdyby PHP spadlo (paměť), další krok obrázek přeskočí jako chybu. Bez JS tlačítko Pokračovat. Přerušit = už hotové zůstanou.
- **Originály**: s ponecháním zůstává `orig_filename` (návrat v detailu, další komprese vždy z originálu), takže místo na disku přibude zmenšená kopie.
  Bez ponechání se originál i předchozí verze smažou (`Galerie::komprimujPodrobne(..., ponechatOriginal: false)`) a místo se uvolní; aplikace se předem zeptá.
  Výsledek, který není menší než dosavadní soubor, se zahodí (`jenMensi`).
- **Souhrn**: počet zkomprimovaných, přeskočených a chyb, velikost vydávaných souborů před a po, změna místa na disku; zapíše se do protokolu.

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
