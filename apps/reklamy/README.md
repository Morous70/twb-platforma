# TWB Reklamy

Osobní reklamní server místo Revive Adserveru: jen vlastní kampaně, jeden uživatel, česky.
Adresa nasazení `https://ads.kazbo.eu`, slug na TWB Update Serveru `reklamy`.
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` a `stranky/`.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, zalohy, aktualizace), instalace, přihlášení heslem / Google / Passkey, kampaně, bannery (obrázek z galerie, obrázek z adresy, HTML), weby, zóny a přiřazení, galerie, zálohy, cron, kontrola zdraví | hotovo |
| 0.2 | Doručování: výběr banneru (druh, priorita, váha, termín, limity), vložení asynchronním JS, JS, iframe, odkazem (obrázek + proklik), popup, XML-RPC, JSON, místním PHP; počítání zobrazení a prokliků, makra v HTML bannerech, generátor kódů | |
| 0.3 | HTML5 bannery (ZIP, `clickTAG`, bezpečné rozbalení), HTML s proměnnými (moduly html, data, pisma), editor slidů pro bannery libovolného rozměru (modul slidy) | |
| 0.4 | Konverze a trackery (pixel, JS, proměnné jako hodnota objednávky), přiřazení po prokliku (a volitelně po zobrazení), omezení doručení (čas, den, zařízení, jazyk, stránka) a frekvence | |
| 0.5 | Statistiky (dny, měsíce, kampaně, bannery, zóny, weby; CTR, konverze, hodnota), grafy, export CSV, nápověda, obnova zálohy a aktualizace v rozhraní, balíček pro Update Server | |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení
install.php      první spuštění: config.php (když chybí), databáze, heslo
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
media.php        náhled obrázku z galerie (jen přihlášený)
cron.php         denní záloha, kontrola aktualizací, úklid protokolu (?key=cron_key)
health.php       kontrola po aktualizaci (modul aktualizace)
lib/             třídy Reklamy\… (App, Schema, Kampane, Bannery, Weby, Zony, Ui, Vstup …)
stranky/         stránky administrace (PHP šablony)
assets/          style.css, app.js, ikona
data/            databáze reklamy.sqlite, galerie (media/), zálohy (backups/) – chráněno .htaccess
```

## Datový model (SQLite, `lib/Schema.php`)
- `campaigns` – druh (`exclusive` přednost, `standard`, `remnant` jen když není nic jiného), priorita 1–10, váha, termín (unix čas), limity celkem a za den, tagy (modul tagy).
- `banners` – patří kampani; druh `image` (galerie, `media_id`), `external` (`image_url`), `html`; přibudou `html5` a `slide` (sloupec `content`). Rozměr, cíl prokliku, váha.
- `sites` a `zones` – zóna má volitelný pevný rozměr (bere jen bannery stejného rozměru) a náhradní HTML.
- `zone_campaigns` (celá kampaň, platí i pro později přidané bannery) a `zone_banners` (jednotlivé bannery).
- Moduly: `settings`, `log`, `module_versions`, `media` (galerie), `passkeys`, `auth_tokens` (přihlášení).
- `schema_version` v nastavení = verze schématu aplikace; zvýšit i při změně schématu modulu, aby zálohy a návrat aktualizace poznaly starší data.

## Instalace (Wedos NoLimit)
1. Nahrát obsah `twb-reklamy-<verze>-full.zip` (složka `reklamy/`) přes FTP do kořene domény ads.kazbo.eu.
2. Otevřít `https://ads.kazbo.eu/` → instalace vytvoří `config.php` (s náhodným `cron_key`) a databázi, nastaví heslo.
3. V `config.php` doplnit podle potřeby `google_client_id`, `google_client_secret` (přesměrování `<base_url>/google.php`) a `update_token`.
4. V administraci Wedos nastavit cron na adresu z Nastavení → Systém (každých 5–15 minut).
5. Nastavení → Zálohy: heslo záloh. Google účet a Passkey se propojují v Nastavení → Zabezpečení.

## Vývoj a testy
```
php tests/run.php reklamy                 # testy aplikace (správa dat + web na vestavěném serveru PHP)
php tools/sestavit.php reklamy            # dist/twb-reklamy-<verze>-full.zip a -update.zip
```
Webové testy spouštějí `php -S` s konfigurací z proměnné `REKLAMY_CONFIG` (platí jen pro vestavěný server) a ověřují i to, že žádná stránka nevypíše varování PHP.
Galerie v této aplikaci obrázky nezmenšuje (`img_auto = 0`), banner má přesný rozměr obrázku.
