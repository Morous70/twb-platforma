# TWB Media galerie

Soukromá galerie médií (obrázky, videa) pro vlastní kampaně: jeden uživatel, česky.
Adresa nasazení `https://galerie.kazbo.eu`, slug na TWB Update Serveru `twb-galerie` (stejný je i interní slug migrací).
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` (třídy `MediaGalerie\…`) a `stranky/`.
Hosting Wedos NoLimit, PHP 8.5 (kód běží od 8.1), SQLite, bez Composeru.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, tagy, obrazky, zalohy, aktualizace, napoveda), instalace, přihlášení heslem / Google / Passkey, zámek pokusů, obnova zapomenutého hesla e-mailem, odhlášení po nečinnosti nastavitelné v aplikaci, vzhled administrace (jako TWB Reklamy), přehled, protokol, nápověda, kontrola zdraví | hotovo |
| 0.2 | Galerie: nahrávání po částech (přetažením, až 1 GB), mřížka s náhledy, přehrání videa, detail, tagy, kampaně jako složky, hledání a filtry, stránkování, archiv, nahrazení souboru, komprese s uchováním originálu (ve výchozím stavu vypnutá), hromadné akce, veřejné neuhodnutelné odkazy na soubory (Range, ETag, CORS) | hotovo |
| 0.3 | API pro čtení i zápis s API klíči (oprávnění čtení / zápis, uložené jen jako otisk), popis API v nápovědě | plán |
| 0.4 | Zálohy (denní cronem, obnova v rozhraní), aktualizace z Update Serveru, cron, balíčky FULL a UPDATE | plán |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení, zapomenuté heslo (?zapomenute), nové heslo z e-mailu (?reset=token)
install.php      první spuštění: config.php (když chybí), databáze, heslo, e-mail pro obnovu hesla
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
health.php       kontrola po aktualizaci (modul aktualizace)
nahrat.php       nahrávání po částech (JSON, jen přihlášený, CSRF v hlavičce X-CSRF), i nahrazení souboru
media.php        soubor v administraci (jen přihlášený): ?id=, &nahled=1, &original=1, &stahnout=1
soubor.php       veřejný odkaz soubor.php/<klíč>/<název> (bez přihlášení, ?stahnout=1)
lib/             App (služby a moduly), Schema, Media, Kampane, Vydej, Ui, Vstup, Http, ProtokolDb, Chyba, autoload, bootstrap
stranky/         prehled, galerie, medium (detail), kampane, nastaveni, protokol, napoveda, prihlaseni
help/            nápověda v Markdownu (_obsah.json + sekce/stránka.md), vykresluje modul napoveda
assets/          style.css, app.js, ikona.svg
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
  `campaign_id`, `note` a `public_key`; modul tyto sloupce nezná a nečte. Kampaně jsou v tabulce `campaigns` (je v zálohách, obnovuje se před `media`).
- **Nahrávání** (`nahrat.php`, `assets/app.js`): soubor po částech 2 MB (`App::CAST_NAHRANI`) přes `TWB\Galerie\Nahravani`, nejvýš 1 GB (`App::MAX_NAHRANI`),
  při výpadku 3 pokusy; po poslední části `Galerie::uloz` (kontrola typu i obsahu) a `Media::poNahrani` (veřejný klíč, kampaň). Nedokončené části starší než hodina se mažou při dalším nahrávání.
- **Veřejný odkaz** (`soubor.php`): klíč 24 hex znaků (96 bitů, `Media::novyKlic`), unikátní index. Funguje i pro archivovaná média; „Nový odkaz“ starý zneplatní,
  „Vypnout odkaz“ nastaví `public_key = NULL`. Po nahrazení souboru zůstává stejný (ETag = MD5, `max-age=3600`).
- **Vydání souborů** (`Vydej`): Range (přetáčení videa, 206/416), If-None-Match (304), `nosniff`, CSP `sandbox` (kromě PDF, které v sandboxu Chrome nezobrazí),
  `Content-Disposition` s názvem. Veřejné soubory navíc `Access-Control-Allow-Origin: *` a `X-Robots-Tag: noindex`.
- **Náhledy** (`Media::nahled`): obrázky delší než 480 px se pro mřížku zmenší do JPEG v `data/nahledy/<id>-<md5>.jpg`; obrázky nad 40 Mpx se posílají celé (paměť GD).
  Videa ukazuje prohlížeč z `media.php` (`preload="metadata"`), PDF ikonou.
- **Komprese**: nastavení modulu obrazky (`img_auto`, `img_max_w`, `img_max_h`, `img_quality`, `img_min_kb`) v Nastavení. Aplikace při první migraci nastaví `img_auto = 0` –
  podklady kampaní se nahrávají v plné kvalitě.

## Testy a vydání
```
php tests/run.php twb-galerie              # testy aplikace (vestavěný server PHP)
php tools/sestavit.php twb-galerie         # dist/twb-twb-galerie-<verze>-full.zip a -update.zip
```
