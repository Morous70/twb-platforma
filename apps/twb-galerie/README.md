# TWB Media galerie

Soukromá galerie médií (obrázky, videa) pro vlastní kampaně: jeden uživatel, česky.
Adresa nasazení `https://galerie.kazbo.eu`, slug na TWB Update Serveru `twb-galerie` (stejný je i interní slug migrací).
Aplikace je složená z modulů platformy (`modules/`), vlastní kód je v `lib/` (třídy `MediaGalerie\…`) a `stranky/`.
Hosting Wedos NoLimit, PHP 8.5 (kód běží od 8.1), SQLite, bez Composeru.

## Etapy
| Verze | Obsah | Stav |
|---|---|---|
| 0.1 | Kostra z modulů (jadro, prihlaseni, galerie, tagy, obrazky, zalohy, aktualizace, napoveda), instalace, přihlášení heslem / Google / Passkey, zámek pokusů, obnova zapomenutého hesla e-mailem, odhlášení po nečinnosti nastavitelné v aplikaci, vzhled administrace (jako TWB Reklamy), přehled, protokol, nápověda, kontrola zdraví | hotovo |
| 0.2 | Galerie: nahrávání po částech, mřížka s náhledy, přehrání videa, detail, tagy, kampaně jako složky, hledání a filtry, archiv, nahrazení souboru, komprese s uchováním originálu, hromadné akce, veřejné neuhodnutelné odkazy na soubory | plán |
| 0.3 | API pro čtení i zápis s API klíči (oprávnění čtení / zápis, uložené jen jako otisk), popis API v nápovědě | plán |
| 0.4 | Zálohy (denní cronem, obnova v rozhraní), aktualizace z Update Serveru, cron, balíčky FULL a UPDATE | plán |

## Struktura
```
index.php        administrace (?p=stránka), přihlášení, zapomenuté heslo (?zapomenute), nové heslo z e-mailu (?reset=token)
install.php      první spuštění: config.php (když chybí), databáze, heslo, e-mail pro obnovu hesla
google.php       přihlášení / propojení Google účtu (OpenID Connect)
passkey.php      JSON rozhraní Passkey (WebAuthn) pro assets/app.js
health.php       kontrola po aktualizaci (modul aktualizace)
lib/             App (služby a moduly), Schema, Ui, Vstup, Http, ProtokolDb, Chyba, autoload, bootstrap
stranky/         prehled, galerie, nastaveni, protokol, napoveda, prihlaseni
help/            nápověda v Markdownu (_obsah.json + sekce/stránka.md), vykresluje modul napoveda
assets/          style.css, app.js, ikona.svg
data/            databáze galerie.sqlite, média (media/), zálohy (backups/) – chráněno .htaccess
```

## Přihlášení
- Heslo je vždy záložní způsob; Google (jediný propojený účet, porovnává se `sub`) a Passkey se jen přidávají v **Nastavení → Zabezpečení**.
- Jeden společný zámek pokusů (modul prihlaseni `Zamek`) pro heslo, Google, Passkey i neplatný odkaz obnovy: 5 chyb → 5 minut.
- Obnova hesla (`TWB\Prihlaseni\Obnova`): odkaz `{base_url}/index.php?reset={token}` jen na e-mail z nastavení `reset_email`, platí 30 minut, jednou, nejvýš 1 žádost za 2 minuty; odpověď je vždy stejná.
  E-mail se posílá přes `mail()` s odesílatelem `mail_from`; `mail_to_file` v config.php (jen testy) ukládá e-maily do složky.
- Odhlášení po nečinnosti: nastavení `idle_minutes` (0 = nikdy, 5–1440), bez něj `session_timeout_min` z config.php (výchozí 120).
- Tabulky `passkeys` a `auth_tokens` nejsou v zálohách (`App::TABULKY`), nastavení zámku a obnovy jsou místní (`App::MISTNI`).

## Testy a vydání
```
php tests/run.php twb-galerie              # testy aplikace (vestavěný server PHP)
php tools/sestavit.php twb-galerie         # dist/twb-twb-galerie-<verze>-full.zip a -update.zip
```
