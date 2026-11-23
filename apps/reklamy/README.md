# TWB Reklamy

Osobní reklamní server místo Revive Adserveru: jen vlastní kampaně, jeden uživatel, česky.
Adresa nasazení `https://ads.kazbo.eu`, slug na TWB Update Serveru `twb-reklamy` (interní slug aplikace a migrací je `reklamy`). Publikace: `-update.zip` a `.publish.json` do složky „TrafikaBR Update Server – Inbox“ na Google Disku.
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` a `stranky/`.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, zalohy, aktualizace), instalace, přihlášení heslem / Google / Passkey, kampaně, bannery (obrázek z galerie, obrázek z adresy, HTML), weby, zóny a přiřazení, galerie, zálohy, cron, kontrola zdraví | hotovo |
| 0.2 | Doručování: výběr banneru (druh, priorita, váha, termín, limity), vložení asynchronním JS, JS, iframe, odkazem (obrázek + proklik), popup, XML-RPC (`openads.view`), JSON, PHP na serveru; počítání zobrazení a prokliků po hodinách, makra v HTML bannerech, generátor kódů a náhled zóny | hotovo |
| 0.3 | HTML5 bannery (ZIP, `clickTag`, bezpečné rozbalení, izolace), HTML s proměnnými a písmy (moduly html, data, pisma), editor slidů pro bannery libovolného rozměru (modul slidy), stránka Proměnné | hotovo |
| 0.4 | Konverze a trackery (pixel, JS, proměnné jako hodnota objednávky), přiřazení po prokliku (a volitelně po zobrazení), omezení doručení (čas, den, zařízení, jazyk, stránka) a frekvence | hotovo |
| 0.5 | Statistiky (dny, měsíce, kampaně, bannery, zóny, weby; CTR, konverze, hodnota), grafy, export CSV, nápověda, obnova zálohy a aktualizace v rozhraní, balíček pro Update Server | hotovo |
| 0.6 | Nový vzhled administrace podle šablony AdminKit: tmavé postranní menu se skupinami a ikonami (skrytí na počítači, vysunutí na mobilu), horní lišta, karty a dlaždice se stínem, barvy a typografie; funkce beze změny | hotovo |
| 0.7 | Zóna jen se šířkou nebo jen s výškou (druhý rozměr libovolný): přiřazení a výběr bannerů, formulář, kódy pro vložení a náhled | hotovo |
| 0.8 | Banner ve více kampaních (vazba M:N, doručování, limity a statistiky po kampaních), náhled banneru v modálním okně (oko v seznamu), externí data (vzdálené zdroje JSON/CSV z modulu data) | hotovo |
| 0.9 | Reklamy podle zařízení: responzivní zóna se seznamem rozměrů (výběr podle šířky místa na stránce, nic se nezmenšuje), banner jen pro některá zařízení, zařízení podle šířky okna (záloha User-Agent), statistiky po zařízeních, obrázek 2× (srcset) | hotovo |
| 0.9.1 | Prázdný rozměr responzivní zóny (`728x0` = od 728 px do dalšího širšího rozměru nic) | hotovo |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení
install.php      první spuštění: config.php (když chybí), databáze, heslo
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
media.php        náhled obrázku z galerie nebo vykresleného slidu (jen přihlášený)
nahled.php       náhled HTML / HTML5 banneru pro modální okno (jen přihlášený, izolovaný)
slide.php        editor slidu (modul slidy) – ?kampan=K&w=&h= nový, ?id=N úprava banneru
api.php          JSON rozhraní editoru slidů (formáty, náhled, uložení, písma, data) – jen přihlášený, POST s X-CSRF
asset.php        soubory editoru z modulu slidy (slide-editor.js / .css), jen vyjmenované
cron.php         denní záloha, kontrola aktualizací, stažení externích dat, úklid protokolu (?key=cron_key)
health.php       kontrola po aktualizaci (modul aktualizace)
d/               veřejné doručování reklam (viz níže)
lib/             třídy Reklamy\… (App, Schema, Kampane, Bannery, Weby, Zony, Dorucovani, Vykresleni, Vydej, Kody, XmlRpc, Ui, Vstup …)
stranky/         stránky administrace (PHP šablony)
help/            nápověda v Markdownu (_obsah.json + sekce/stránka.md), vykresluje modul napoveda
assets/          style.css, app.js, ikona, slide-shim.js (napojení editoru slidů), slide-init.js
data/            databáze reklamy.sqlite, galerie (media/), zálohy (backups/), písma (fonts/), balíčky HTML5 (html5/),
                 vykreslené slidy (slidy/, dají se kdykoli vykreslit znovu) – chráněno .htaccess
```

## Doručování (složka `d/`)
| Soubor | K čemu |
|---|---|
| `asyn.php` | asynchronní skript: najde `<ins data-twbr-zona="ID">`, jedním požadavkem načte všechny zóny stránky (banner se na stránce neopakuje); posílá šířku každého místa (`sirky`) a okna (`sw`) |
| `data.php` | JSON pro jednu (`?zona=`) nebo víc zón (`?zony=1,2`), používá ho skript i servery; CORS i s cookies; volitelně `sirka` / `sirky=300,728` (šířky míst) a `sw` (šířka okna) |
| `js.php` | klasický JavaScript (`document.write`) |
| `ram.php` | rám (iframe); HTML banner je přímo obsahem rámu; používá ho i vyskakovací okno |
| `obr.php` + `klik.php?zona=&n=` | vložení odkazem (e-maily): obrázek a proklik se stejným `n` vedou na stejný banner (tabulka `view_map`, i bez cookies) |
| `klik.php?b=&z=&c=&k=` | proklik z vloženého banneru; podpis HMAC trojice banner–zóna–kampaň (starší odkazy bez `c` platí dál), cíl se bere z banneru. `&cil=` jen u HTML bannerů a jen na doménu cíle nebo adresu uvedenou v HTML (ochrana proti otevřenému přesměrování) |
| `b.php` | obrázek banneru z galerie (`?b=&v=md5`) nebo vykreslený slide (`?b=&v=klíč obsahu`), mezipaměť rok; HTML dokument banneru (`?b=&z=&k=`) |
| `h5.php/<md5>/<cesta>` | soubory HTML5 balíčku (relativní odkazy uvnitř balíčku fungují beze změny); hlavní soubor dostane `clickTag` – s podpisem `?b=&z=&k=` vede přes počítání prokliků |
| `okno.php` | vyskakovací okno (při prvním kliknutí na stránce, `kdy=ihned`, `pod=1`, `jednou=1`) |
| `xmlrpc.php` | XML-RPC `openads.view` kompatibilní s Revive (pro stávající PHP kód) |
| `konverze.php?t=` | konverze z webu inzerenta: `&hodnota=` (Kč), `&id=` (číslo objednávky – podruhé se nezapočítá), `&twbr=` (token prokliku); vrací GIF, s `&format=json` stav pro volání ze serveru |
| `t.js` | skript pro web inzerenta: zapamatuje si `twbr` z adresy (localStorage + cookie první strany, 90 dní) a `twbr.push({tracker, hodnota, id})` pošle konverzi |

Výběr banneru (`lib/Dorucovani.php`): aktivní banner aktivní kampaně v termínu a s nevyčerpanými limity → druh kampaně (exkluzivní > standardní > zbytková) → nejvyšší priorita → los podle váhy kampaně a v ní váhy banneru.
Zobrazení se počítá při doručení, proklik při přesměrování, obojí po hodinách v tabulce `stats`. Roboti (podle prohlížeče) a náhled z administrace (podepsaný parametr `nahled`) se nepočítají.
Chyba doručování se zapíše do protokolu (stejná nejvýš jednou za minutu) a návštěvník dostane prázdný výsledek – stránka s reklamou se nikdy nerozbije.

HTML dokumenty banerů (HTML i HTML5, i SVG z balíčku) mají hlavičku `Content-Security-Policy: sandbox allow-scripts allow-popups …`: běží s vlastním prázdným původem,
takže nemají přístup k cookies ani stránkám administrace na stejné doméně. Soubory HTML5 mají `Access-Control-Allow-Origin: *` (písma a data z takového dokumentu).

Makra v HTML bannerech: `{clickurl}` (proklik na cíl banneru, `{clickurl}https://…` na jinou povolenou adresu), `{clickurl_enc}`, `{targeturl}`, `{random}`, `{cachebuster}`, `{timestamp}`, `{bannerid}`, `{zoneid}`, `{campaignid}`, `{width}`, `{height}`.
Před makry se dosadí proměnné modulu data (`{datum}`, `{svatek}`, `{odpocet:24. 12. 2026}`, `{Skupina:parametr}`, vlastní proměnné; ne uvnitř `<script>` a `<style>`)
a když kód jmenuje písmo z modulu pisma (`font-family: 'Montserrat'`), vloží se do dokumentu jako `@font-face`.

## Banner ve více kampaních, náhled, externí data (0.8)
- **Více kampaní**: tabulka `campaign_banners` (M:N). Banner vzniká v kampani (`banners.campaign_id` = kampaň, pro kterou vznikl – jen informace a převod starších záloh, bez cizího klíče) a jde přidat dalším:
  v kampani „Přidat existující banner…“ / „Odebrat“, v úpravě banneru zaškrtávací seznam kampaní. Banner je vždy aspoň v jedné kampani (`Bannery::nastavKampane`).
- **Doručování**: kandidát je dvojice banner–kampaň (`campaign_id` řádku = kampaň dvojice). Zóna s kampaní bere banner jen za tuto kampaň, banner přiřazený zóně přímo běží ve všech svých kampaních;
  termín, druh, priorita, váha, limity, cílení a frekvence se berou z kampaně dvojice. Banner se na stránce neopakuje (ani za jinou kampaň).
- **Statistiky** mají sloupec `campaign_id` (součást klíče), limity kampaně (`Dorucovani::pocty`) i statistiky po kampaních se počítají z něj, takže zůstanou správné i po odebrání nebo smazání banneru.
  Prokliky (`clicks`), zobrazení (`views`), konverze a vložení odkazem (`view_map.campaign_id`) si kampaň pamatují.
- **Odkazy**: proklik a HTML dokument mají podepsanou trojici `klik|b|z|c` / `html|b|z|c` (`Vykresleni::overOdkaz`). Odkazy ze starších verzí (bez `c`) platí dál a počítají se kampani, pro kterou banner vznikl (`Dorucovani::hlavniKampan`), nebo první kampani banneru.
- **Kampaň**: kopie sdílí stejné bannery (nekopírují se); smazání kampaně smaže jen bannery, které nejsou v jiné kampani (`Kampane::jenVlastniBannery`).
- **Převod databáze** (schéma 5): přestavba tabulek `banners` (bez cizího klíče na kampaň) a `stats` (s kampaní) s vypnutými cizími klíči (`App::migruj`), vazby podle `banners.campaign_id`; opakovatelný, takže obnova zálohy ze 0.7 se po obnovení doplní. Test proti zmrazenému schématu 0.7 (`tests/reklamy/original/Schema-0.7.php`).
- **Náhled** (`Ui::nahledTlacitko`, `assets/app.js`): oko v seznamu bannerů a v kampani otevře `<dialog>`; obrázky (galerie, slide, adresa) přímo, HTML z `nahled.php` (jen přihlášený, CSP sandbox), HTML5 přesměrováním na balíček bez podpisu (clickTag vede rovnou na cíl). Velký banner se zmenší (`transform: scale`), Esc / křížek / klik vedle okno zavře a banner zastaví.
- **Externí data** (Obsah → Externí data, `stranky/zdroje.php`): modul data `Vzdalene` (tabulka `sources`, je v zálohách) – zdroje JSON nebo CSV (i tabulka Google), interval stahování, hned první stažení, stav a chyba, strom hodnot se zápisem `{Zdroj:cesta}`, upozornění na bannery, které zdroj používají.
  Proměnné (`App::promenne`) zdroje znají, takže fungují v HTML bannerech i slidech; editor slidů je dostane přes `api.php?a=source_tree`. Cron stahuje podle intervalu (`stahniPo`), při výpadku zůstávají poslední data (jedno varování v protokolu, pak zpráva o obnovení).
- Testy: `tests/reklamy/kampane_banneru_test.php` (převod, správa, doručování, statistiky, odkazy, zdroje) a `kampane_banneru_web_test.php` (formuláře, `nahled.php`, okno v Chromiu, stránka Externí data proti druhému vestavěnému serveru).

## Vzhled administrace (0.6)
- Inspirace šablonou **AdminKit** 3.5.0 (MIT): barvy (`#3b7ddd`, pozadí `#f5f7fb`, menu `#222e3c`), postranní menu 260 px, lišta se stínem, karty bez rámečku, písmo 14 px (`Inter`, pokud ho systém má, jinak systémové).
  Styly v `assets/style.css` jsou napsané nově, bez Bootstrapu a bez souborů z internetu (písmo z Google Fonts se nenačítá). Licence AdminKitu a ikon Feather: `assets/LICENCE.txt`.
- Rozvržení skládá `Ui::stranka()`: menu podle `Ui::SKUPINY` (Reklama, Obsah, Správa), ikony `Ui::ikona()` (vložené SVG, barva podle textu), dlaždice `Ui::dlazdice()`.
- Přepínač menu (`assets/app.js`): na počítači menu skryje a pamatuje si to v prohlížeči (`localStorage` `twbr-menu`, třída `menu-skryte` na `<html>` se nastaví ještě před vykreslením),
  pod 992 px menu vysune přes obsah, zavře ho klik na závoj nebo Esc. Tmavý režim podle systému zůstává.
- Editor slidů si drží vlastní vzhled polí (`.pm`); na jeho stránce je užší okraj obsahu.
- Test `tests/reklamy/vzhled_test.php`: menu (skupiny, ikony, aktivní položka), dlaždice, žádné externí adresy ve stylech a skriptu, přiložená licence a ve skutečném prohlížeči skrytí/vysunutí menu bez vodorovného posunu na mobilu.

## Statistiky, nápověda a správa (0.5)
- **Statistiky** (`stranky/statistiky.php`, `Statistiky::prehled`): období (předvolby nebo od–do), seskupení po dnech, týdnech (ISO), měsících, hodinách dne,
  kampaních, bannerech, zónách a webech; filtr kampaň / banner / web / zóna. Časové řady mají i dny bez provozu. Graf je SVG ze serveru (jedna řada,
  přepínač ukazatele, popisek při najetí), tabulka má CTR, konverzní poměr a součet. CSV pro Excel: UTF-8 s BOM, středník, desetinná čárka, text začínající `=`, `+`, `-`, `@` se nespustí jako vzorec.
  Statistiky smazaného banneru zůstanou (u kampaní jako „smazané bannery“), zobrazení bez zóny pocházejí z vložení ze serveru.
- **Nápověda** (`help/`, stránka `?p=napoveda&s=<id>`, hledání `&q=`): stránky vykresluje `TWB\Napoveda\Markdown` na serveru, hledá `Napoveda::hledej`.
  Tlačítko „?“ u nadpisu vede na stránku podle `kontext` v `_obsah.json` (klíč = stránka administrace). Test hlídá, že obsah projde kontrolou a každá stránka menu má nápovědu.
- **Obnova zálohy** (Nastavení → Zálohy): záloha z úložiště nebo nahraný soubor (`…-nahrana.zip`) → heslo (prázdné = současné) → náhled obsahu → potvrzení.
  Heslo zůstává jen v relaci (10 minut), před obnovou modul zalohy vytvoří pojistnou zálohu, po obnově se databáze převede na současnou verzi.
- **Aktualizace** (Nastavení → Aktualizace): stav, zjistit novou verzi, nainstalovat nabídnutou, vrátit předchozí (modul aktualizace; cron zjišťuje jednou denně).
- **Balíček**: `php tools/sestavit.php reklamy`; `tests/reklamy/balicek_test.php` ověří obsah, zámek modulů a že rozbalený balíček běží samostatně s moduly z `modules/`.

## Konverze, cílení a frekvence (0.4)
- **Trackery** (stránka Konverze): kód na webu inzerenta, druh (nákup, poptávka, registrace, jiné), výchozí hodnota, okno po prokliku (1–365 dní) a po zobrazení (0–90, 0 = vypnuto),
  jedna konverze na proklik, sledované kampaně (žádná = všechny). Stránka trackeru ukáže kódy: `t.js` na všech stránkách, `twbr.push` po objednávce, obrázek bez JS a postback ze serveru.
- **Přiřazení** (`lib/Konverze.php`): 1. token `twbr=<id prokliku>.<podpis>`, který `klik.php` přidá do cílové adresy (funguje i v prohlížečích bez cookies třetích stran),
  2. cookie návštěvníka `twbr_v` (poslední proklik v okně), 3. zobrazení v okně po zobrazení. Konverze se přičte do `stats` banneru a zóny (počet a hodnota); nepřiřazená se uloží bez banneru.
- **Cílení kampaně** (`lib/Cileni.php`, JSON v `campaigns.targeting`): dny v týdnu, hodiny od–do (i přes půlnoc), zařízení podle prohlížeče, jazyk (Accept-Language),
  stránka obsahuje / neobsahuje, odkud návštěvník přišel. Chybí-li údaj, který pravidlo vyžaduje (stránka u vložení ze serveru), kampaň se nezobrazí.
- **Frekvence**: nejvýš N zobrazení kampaně jednomu návštěvníkovi za X hodin (cookie `twbr_f`); u vložení ze serveru (bez cookies) se neuplatní.
- Cookies `twbr_v` a `twbr_f` jsou HttpOnly, na HTTPS `SameSite=None; Secure`. V Nastavení → Měření jde vypnout cookies návštěvníků i parametr `twbr` v cílové adrese.

## Obsah banerů (0.3)
- **Slide** (`type = slide`): šablona JSON v `banners.content` (modul slidy: texty s grafickými styly, obdélníky, obrázky z galerie, kuličky, podmínky „zobrazit jen když“).
  Editor `slide.php` je převzatý z modulu beze změny (`modules/slidy/web/slide-editor.js`); co potřebuje od aplikace, dodává `assets/slide-shim.js` (viz `modules/slidy/web/HOST.md`).
  Formáty plátna = běžné rozměry banerů + „vlastní“ (16–4096 px). Hotový obrázek JPEG (`data/slidy/<id>-<klíč>.jpg`) se vykreslí v GD při prvním zobrazení;
  klíč se změní se šablonou, obrázky i dosazenými proměnnými, takže např. text s `{datum}` se každý den vykreslí znovu sám. Tagy a počasí editor v Reklamách nemá.
- **HTML5** (`type = html5`): ZIP nejvýš 10 MB / 40 MB rozbalený / 500 souborů; jen povolené přípony (HTML, JS, CSS, JSON, obrázky, písma, video, zvuk),
  žádné cesty mimo balíček ani symbolické odkazy. Hlavní soubor `index.html` (i v jediné složce), rozměr z `<meta name="ad.size">`.
  Balíček se uloží beze změny jako `data/html5/<md5>.zip` (je v zálohách) a rozbalí do `data/html5/<md5>/`; nepoužívané balíčky maže úprava/smazání banneru a cron.
  `clickTag`, `clickTAG` i `clicktag` doplní server na začátek `<head>` jako vlastnost, kterou skript banneru nepřepíše.
- **Proměnné** (stránka Proměnné): kalendář, vlastní proměnné (`settings.user_vars`, text nebo seznam) a tabulka dat `data_rows` (Skupina | Parametr | Hodnota, vložení z Excelu).
- **Písma**: vestavěná sada modulu pisma + vlastní TTF/OTF (nahrání v editoru → Písma…, `data/fonts`, v zálohách). Písmo použité ve slidu ani obrázek použitý ve slidu nejde smazat.

## Datový model (SQLite, `lib/Schema.php`)
- `campaigns` – druh (`exclusive` přednost, `standard`, `remnant` jen když není nic jiného), priorita 1–10, váha, termín (unix čas), limity celkem a za den, tagy (modul tagy).
- `banners` – druh `image` (galerie, `media_id`), `external` (`image_url`), `html` (`html`), `html5` a `slide` (JSON ve sloupci `content`: balíček, resp. šablona). Rozměr, cíl prokliku, váha.
- `sites` a `zones` – zóna má volitelnou pevnou šířku a výšku, každou zvlášť (`NULL` = libovolná; bere jen bannery, které v zadaných rozměrech sedí) a náhradní HTML. Rám a popup bez pevného rozměru dostanou v kódu největší rozměr bannerů zóny (jinak 300×250, popup 800×600).
- `campaign_banners` – banner v kampaních (M:N, aspoň jedna); `banners.campaign_id` = kampaň, pro kterou banner vznikl.
- `zone_campaigns` (celá kampaň, platí i pro později přidané bannery) a `zone_banners` (jednotlivé bannery).
- `trackers`, `tracker_campaigns` – trackery konverzí a kampaně, které sledují. `conversions` – konverze (tracker, kampaň, banner, zóna, proklik, `via` = `click` / `view` / prázdné, hodnota v haléřích, `ref` = číslo objednávky, unikátní v trackeru).
- `clicks` a `views` – prokliky a zobrazení návštěvníků pro přiřazení (zobrazení jen když nějaký tracker má okno po zobrazení); cron maže starší než nejdelší okno.
- `campaigns.targeting`, `cap_impressions`, `cap_hours` – cílení a frekvence.
- `stats` – po hodinách (`day`, `hour`, `banner_id`, `zone_id`, `campaign_id`): zobrazení, prokliky, konverze, hodnota v haléřích. `view_map` – vazba obrázek/proklik u vložení odkazem (úklid po 30 dnech v cronu).
- Moduly: `settings`, `log`, `module_versions`, `media` (galerie), `passkeys`, `auth_tokens` (přihlášení), `data_rows`, `sources`, `weather` (modul data; Reklamy používají `data_rows` a od 0.8 `sources` – externí data).
- Verze schématu: 1 = 0.1, 2 = 0.2 (`stats`, `view_map`), 3 = 0.3 (tabulky modulu data), 4 = 0.4 (konverze, cílení, frekvence; převod jde bezpečně zopakovat), 5 = 0.8 (banner ve více kampaních, kampaň ve statistikách).
- `schema_version` v nastavení = verze schématu aplikace; zvýšit i při změně schématu modulu, aby zálohy a návrat aktualizace poznaly starší data.

## Instalace (Wedos NoLimit)
1. Nahrát obsah `twb-reklamy-<verze>-full.zip` (složka `reklamy/`) přes FTP do kořene domény ads.kazbo.eu.
2. Otevřít `https://ads.kazbo.eu/` → instalace vytvoří `config.php` (s náhodným `cron_key`) a databázi, nastaví heslo.
3. V `config.php` doplnit podle potřeby `google_client_id`, `google_client_secret` (přesměrování `<base_url>/google.php`) a `update_token`.
4. V administraci Wedos nastavit cron na adresu z Nastavení → Systém (každých 5–15 minut).
5. Nastavení → Zálohy: heslo záloh. Google účet a Passkey se propojují v Nastavení → Zabezpečení.

## Vývoj a testy
```
php tests/run.php reklamy                 # testy aplikace (správa dat, doručování, obsah banerů, konverze, statistiky, zálohy, balíček, web na vestavěném serveru PHP, Chromium)
php tools/sestavit.php reklamy            # dist/twb-reklamy-<verze>-full.zip a -update.zip
```
Test vložení do stránky, editoru slidů a HTML5 v rámu běží ve skutečném prohlížeči (Playwright + Chromium); bez nich se přeskočí. Webové testy spouštějí `php -S` s konfigurací z proměnné `REKLAMY_CONFIG` (platí jen pro vestavěný server) a ověřují i to, že žádná stránka nevypíše varování PHP.
Galerie v této aplikaci obrázky nezmenšuje (`img_auto = 0`), banner má přesný rozměr obrázku.

## Reklamy podle zařízení (0.9)
- **Responzivní zóna**: `zones.sizes` = JSON seznam rozměrů (`["970x250","728x90","300x250"]`, od nejširšího; `Zony::rozmeryZTextu`), šířka a výška zóny jsou pak prázdné.
  `Zony::pasuje` bere bannery všech rozměrů seznamu (přiřazení, náhled, zóny banneru; nesedící přímá přiřazení po změně zóny nebo banneru odebere `Zony::odeberNesedici`).
- **Výběr** (`Dorucovani::vyber($zona, $k, $typy, $sirkaMista)`): u responzivní zóny jen bannery široké nejvýš jako místo; v nejlepší skupině (druh + priorita) vyhraje nejširší rozměr
  (pak vyšší), mezi bannery tohoto rozměru se losuje. Šířku místa posílá `asyn.php` (`sirky`, značka `<ins>` jako blok přes celou šířku rodiče), JavaScript (`sirka` = šířka rodiče skriptu);
  když chybí: šířka okna `sw`, jinak odhad podle zařízení (`Kontext::ODHAD_SIRKY`: mobil 360, tablet 768, počítač bez omezení). Banner se nikdy nezmenšuje – nic se nevejde = náhradní HTML.
- **Zařízení** (`Kontext::zarizeni`): parametr `d` odkazu (proklik a HTML dokument nesou zařízení zobrazení, bez podpisu – jen popisek statistik) → šířka okna `sw`
  (pod 768 mobil, pod 1024 tablet) → User-Agent. Řídí se jím cílení kampaně i `banners.devices` (čárkami `pocitac,tablet,mobil`, NULL = všechna; `Bannery::proZarizeni`).
- **Statistiky**: `stats.device` je součástí klíče (`''` = data před 0.9), `clicks.device` a `views.device` – konverze dostane zařízení prokliku nebo zobrazení.
  Přehled `podle=zarizeni` a filtr `zarizeni` (`Statistiky::prehled`).
- **Obrázek 2×**: `banners.media2x_id` (galerie, přesně dvojnásobný rozměr; galerie ho nedovolí smazat) nebo `image2x_url` (obrázek z adresy).
  `Vykresleni::html` přidá `srcset="… 1x, … 2x"`, `d/b.php?x=2`, JSON `obrazek2x`. Vložení odkazem (e-mail) vydává běžný obrázek, slidy 2× nemají.
- **Převod databáze** (schéma 6): nové sloupce a přestavba `stats` s `device`, opakovatelný (obnovená záloha z 0.8). Test proti zmrazenému schématu 0.8 (`tests/reklamy/original/Schema-0.8.php`).
- **Prázdný rozměr** (0.9.1): položka s výškou 0 (`728x0`). `Zony::prazdnaHranice` vrátí šířku nejširšího prázdného rozměru, který se do místa vejde;
  bannery užší nebo stejně široké se pak nepoužijí (širší do šířky místa ano). Bannery a rozměr rámu berou jen skutečné rozměry (`Zony::rozmeryBanneru`).
  Prázdný rozměr nesmí sdílet šířku s jiným a zóna musí mít aspoň jeden skutečný rozměr.
