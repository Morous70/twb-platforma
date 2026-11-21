# TWB Reklamy

Osobní reklamní server místo Revive Adserveru: jen vlastní kampaně, jeden uživatel, česky.
Adresa nasazení `https://ads.kazbo.eu`, slug na TWB Update Serveru `reklamy`.
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` a `stranky/`.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, zalohy, aktualizace), instalace, přihlášení heslem / Google / Passkey, kampaně, bannery (obrázek z galerie, obrázek z adresy, HTML), weby, zóny a přiřazení, galerie, zálohy, cron, kontrola zdraví | hotovo |
| 0.2 | Doručování: výběr banneru (druh, priorita, váha, termín, limity), vložení asynchronním JS, JS, iframe, odkazem (obrázek + proklik), popup, XML-RPC (`openads.view`), JSON, PHP na serveru; počítání zobrazení a prokliků po hodinách, makra v HTML bannerech, generátor kódů a náhled zóny | hotovo |
| 0.3 | HTML5 bannery (ZIP, `clickTag`, bezpečné rozbalení, izolace), HTML s proměnnými a písmy (moduly html, data, pisma), editor slidů pro bannery libovolného rozměru (modul slidy), stránka Proměnné | hotovo |
| 0.4 | Konverze a trackery (pixel, JS, proměnné jako hodnota objednávky), přiřazení po prokliku (a volitelně po zobrazení), omezení doručení (čas, den, zařízení, jazyk, stránka) a frekvence | |
| 0.5 | Statistiky (dny, měsíce, kampaně, bannery, zóny, weby; CTR, konverze, hodnota), grafy, export CSV, nápověda, obnova zálohy a aktualizace v rozhraní, balíček pro Update Server | |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení
install.php      první spuštění: config.php (když chybí), databáze, heslo
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
media.php        náhled obrázku z galerie nebo vykresleného slidu (jen přihlášený)
slide.php        editor slidu (modul slidy) – ?kampan=K&w=&h= nový, ?id=N úprava banneru
api.php          JSON rozhraní editoru slidů (formáty, náhled, uložení, písma, data) – jen přihlášený, POST s X-CSRF
asset.php        soubory editoru z modulu slidy (slide-editor.js / .css), jen vyjmenované
cron.php         denní záloha, kontrola aktualizací, úklid protokolu (?key=cron_key)
health.php       kontrola po aktualizaci (modul aktualizace)
d/               veřejné doručování reklam (viz níže)
lib/             třídy Reklamy\… (App, Schema, Kampane, Bannery, Weby, Zony, Dorucovani, Vykresleni, Vydej, Kody, XmlRpc, Ui, Vstup …)
stranky/         stránky administrace (PHP šablony)
assets/          style.css, app.js, ikona, slide-shim.js (napojení editoru slidů), slide-init.js
data/            databáze reklamy.sqlite, galerie (media/), zálohy (backups/), písma (fonts/), balíčky HTML5 (html5/),
                 vykreslené slidy (slidy/, dají se kdykoli vykreslit znovu) – chráněno .htaccess
```

## Doručování (složka `d/`)
| Soubor | K čemu |
|---|---|
| `asyn.php` | asynchronní skript: najde `<ins data-twbr-zona="ID">`, jedním požadavkem načte všechny zóny stránky (banner se na stránce neopakuje) |
| `data.php` | JSON pro jednu (`?zona=`) nebo víc zón (`?zony=1,2`), používá ho skript i servery; CORS i s cookies |
| `js.php` | klasický JavaScript (`document.write`) |
| `ram.php` | rám (iframe); HTML banner je přímo obsahem rámu; používá ho i vyskakovací okno |
| `obr.php` + `klik.php?zona=&n=` | vložení odkazem (e-maily): obrázek a proklik se stejným `n` vedou na stejný banner (tabulka `view_map`, i bez cookies) |
| `klik.php?b=&z=&k=` | proklik z vloženého banneru; podpis HMAC dvojice banner–zóna, cíl se bere z banneru. `&cil=` jen u HTML bannerů a jen na doménu cíle nebo adresu uvedenou v HTML (ochrana proti otevřenému přesměrování) |
| `b.php` | obrázek banneru z galerie (`?b=&v=md5`) nebo vykreslený slide (`?b=&v=klíč obsahu`), mezipaměť rok; HTML dokument banneru (`?b=&z=&k=`) |
| `h5.php/<md5>/<cesta>` | soubory HTML5 balíčku (relativní odkazy uvnitř balíčku fungují beze změny); hlavní soubor dostane `clickTag` – s podpisem `?b=&z=&k=` vede přes počítání prokliků |
| `okno.php` | vyskakovací okno (při prvním kliknutí na stránce, `kdy=ihned`, `pod=1`, `jednou=1`) |
| `xmlrpc.php` | XML-RPC `openads.view` kompatibilní s Revive (pro stávající PHP kód) |

Výběr banneru (`lib/Dorucovani.php`): aktivní banner aktivní kampaně v termínu a s nevyčerpanými limity → druh kampaně (exkluzivní > standardní > zbytková) → nejvyšší priorita → los podle váhy kampaně a v ní váhy banneru.
Zobrazení se počítá při doručení, proklik při přesměrování, obojí po hodinách v tabulce `stats`. Roboti (podle prohlížeče) a náhled z administrace (podepsaný parametr `nahled`) se nepočítají.
Chyba doručování se zapíše do protokolu (stejná nejvýš jednou za minutu) a návštěvník dostane prázdný výsledek – stránka s reklamou se nikdy nerozbije.

HTML dokumenty banerů (HTML i HTML5, i SVG z balíčku) mají hlavičku `Content-Security-Policy: sandbox allow-scripts allow-popups …`: běží s vlastním prázdným původem,
takže nemají přístup k cookies ani stránkám administrace na stejné doméně. Soubory HTML5 mají `Access-Control-Allow-Origin: *` (písma a data z takového dokumentu).

Makra v HTML bannerech: `{clickurl}` (proklik na cíl banneru, `{clickurl}https://…` na jinou povolenou adresu), `{clickurl_enc}`, `{targeturl}`, `{random}`, `{cachebuster}`, `{timestamp}`, `{bannerid}`, `{zoneid}`, `{campaignid}`, `{width}`, `{height}`.
Před makry se dosadí proměnné modulu data (`{datum}`, `{svatek}`, `{odpocet:24. 12. 2026}`, `{Skupina:parametr}`, vlastní proměnné; ne uvnitř `<script>` a `<style>`)
a když kód jmenuje písmo z modulu pisma (`font-family: 'Montserrat'`), vloží se do dokumentu jako `@font-face`.

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
- `banners` – patří kampani; druh `image` (galerie, `media_id`), `external` (`image_url`), `html` (`html`), `html5` a `slide` (JSON ve sloupci `content`: balíček, resp. šablona). Rozměr, cíl prokliku, váha.
- `sites` a `zones` – zóna má volitelný pevný rozměr (bere jen bannery stejného rozměru) a náhradní HTML.
- `zone_campaigns` (celá kampaň, platí i pro později přidané bannery) a `zone_banners` (jednotlivé bannery).
- `stats` – po hodinách (`day`, `hour`, `banner_id`, `zone_id`): zobrazení, prokliky, konverze, hodnota v haléřích. `view_map` – vazba obrázek/proklik u vložení odkazem (úklid po 30 dnech v cronu).
- Moduly: `settings`, `log`, `module_versions`, `media` (galerie), `passkeys`, `auth_tokens` (přihlášení), `data_rows`, `sources`, `weather` (modul data; Reklamy používají jen `data_rows`).
- Verze schématu: 1 = 0.1, 2 = 0.2 (`stats`, `view_map`), 3 = 0.3 (tabulky modulu data).
- `schema_version` v nastavení = verze schématu aplikace; zvýšit i při změně schématu modulu, aby zálohy a návrat aktualizace poznaly starší data.

## Instalace (Wedos NoLimit)
1. Nahrát obsah `twb-reklamy-<verze>-full.zip` (složka `reklamy/`) přes FTP do kořene domény ads.kazbo.eu.
2. Otevřít `https://ads.kazbo.eu/` → instalace vytvoří `config.php` (s náhodným `cron_key`) a databázi, nastaví heslo.
3. V `config.php` doplnit podle potřeby `google_client_id`, `google_client_secret` (přesměrování `<base_url>/google.php`) a `update_token`.
4. V administraci Wedos nastavit cron na adresu z Nastavení → Systém (každých 5–15 minut).
5. Nastavení → Zálohy: heslo záloh. Google účet a Passkey se propojují v Nastavení → Zabezpečení.

## Vývoj a testy
```
php tests/run.php reklamy                 # testy aplikace (správa dat, doručování, obsah banerů, web na vestavěném serveru PHP, Chromium)
php tools/sestavit.php reklamy            # dist/twb-reklamy-<verze>-full.zip a -update.zip
```
Test vložení do stránky, editoru slidů a HTML5 v rámu běží ve skutečném prohlížeči (Playwright + Chromium); bez nich se přeskočí. Webové testy spouštějí `php -S` s konfigurací z proměnné `REKLAMY_CONFIG` (platí jen pro vestavěný server) a ověřují i to, že žádná stránka nevypíše varování PHP.
Galerie v této aplikaci obrázky nezmenšuje (`img_auto = 0`), banner má přesný rozměr obrázku.
