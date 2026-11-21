# Automatické testy

Slouží jen pro vývoj. Na serveru je složka zablokovaná (`.htaccess`) a testy jdou spustit jen z příkazové řádky.

```sh
php tests/run.php                 # testy PHP (asi 5 s)
php tests/run.php --e2e           # navíc testy v prohlížeči (asi 40 s)
php tests/run.php --jen-e2e       # jen testy v prohlížeči
php tests/run.php schedule dated  # jen soubory, jejichž název obsahuje zadaný text
php tests/run.php --keep          # nechat dočasnou kopii (snímky obrazovky u chyb, server.log)
```

Runner zkopíruje aplikaci do dočasné složky bez `config.php` a `data/`, vytvoří prázdnou databázi SQLite a zapne testovací režim Disku (`drive_mock`). Skutečná data se tak nikdy nezmění. Spustí také `php -S` (s `PHP_CLI_SERVER_WORKERS=4`) pro testy přes HTTP.

## MySQL / MariaDB
Výchozí databáze je SQLite. Pro produkční typ databáze použijte samostatnou, prázdnou databázi (celá se smaže!):

```sh
MP_TEST_DSN="mysql:host=localhost;dbname=mp_test;charset=utf8mb4" MP_TEST_USER=mp MP_TEST_PASS=mp php tests/run.php --mysql --e2e
```

## Testy v prohlížeči
Potřebují Node.js a balíček `playwright`, který se hledá přes `NODE_PATH`. Chromium lze zadat v `CHROMIUM_PATH`.

```sh
NODE_PATH=/cesta/k/node_modules CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome php tests/run.php --e2e
```

## Struktura
- `lib.php`: `test()`, `eq()`, `ok()`, `contains()`, `throws()`, `db_reset()`, `make_media()`, `make_scenario()`, `playing($čas)`.
- `unit/*_test.php`: každý soubor běží ve vlastním procesu nad čistou databází.
  - `schedule`: plánovač.
  - `dated`: výběr podle data a migrace v10.
  - `format`: formát data, formát čísla, filtry, vykreslení slidu.
  - `sources`: CSV a JSON.
  - `image`: komprese.
  - `backup`: zálohy.
  - `sync`: synchronizace.
  - `diag`: diagnostika.
  - `auth`: přihlášení (tokeny, obnova hesla, Google, Passkey/WebAuthn, omezení pokusů).
  - `help`: nápověda (obsah help/, odkazy, API, ochrana složky).
  - `fx`: grafický text (styly, očištění, vykreslení – barvy výplně a záře, všechny styly, render_key), rozestup písmen, nová písma.
  - `fonts`: vlastní písma (nahrání, kontrola, české znaky – `fixtures/ascii-jen.ttf`, použití, zálohy, HTTP).
  - `http`: přihlášení, CSRF, `signal.php`, `cron.php`, `backup.php`.
  - `pairing`: párování televize kódem (`pair.php`, `device_pair`, blokace, platnost, druh tizen/android) a zipy aplikací pro TV Samsung a Android TV.
  - `folders_to_tags`: převod složek na tagy (migrace v18) – jméno složky jako tag, archivovaná média, dlouhé názvy, plné médium, prázdné složky.
  - `weather`: počasí – rozbor proměnné, stažení z OpenWeather (náhrada `tests/mock/openweather.php`, ovládání soubory `data/tmp/ow-mock-down`, `ow-mock-temp`, záznam `ow-mock-log`), mezipaměť 15 min, výpadek (poslední data, jedno varování), chybný klíč a místo, místa ze slidů a HTML, úklid, tři vzhledy uvnitř rámečku, vykreslení a klíč obsahu.
  - `tags`: tagy – čištění a limity, zápis podle existujícího tagu, hromadně, přejmenování se sloučením, smazání, API, archiv.
  - `html`: HTML dokumenty – dosazení proměnných mimo skripty/styly, písma v dokumentu, odkazy na internet, limity, `html_save`, nahrání `.html`, CSP sandbox, obnova po změně dat, nabídka zařízením podle verze.
  - `drive_retry`: dočasné chyby Google Disku – rozpoznání (500–504, 429, limit), opakování s čekáním, trvalé chyby hned.
  - `archive`: archiv médií – `media_archive`, `used_in`, odmítnutí přidání do scénáře, návrat do původní složky, trvalé smazání.
  - `control`: ovládání zařízení – `now.php?ping`, příkazy (jednou, propadnutí), změna složky, neozývá se, API `device_cmd`, `devices_alert`.
  - `devices`: zařízení a klíče, `now.php` (seznam, soubory, CORS, blokace špatných klíčů), API Nastavení → Zařízení, zip pluginu.
- `e2e/*.test.mjs`: Playwright.
  - `zaklad`: všechny stránky, vzhled, mobil.
  - `pocasi` (e2e): Nastavení → Počasí (chybný a správný klíč, zkouška, tabulka míst, viditelnost všech karet), editor slidu (+ Počasí, vzhledy, místo, ukázka bez dat, uložení, náhled proti serveru), proměnné v nabídce a přehledu.
  - `zvetseni` (e2e): zvětšení náhledu v Přehledu, seznamu scénářů, editoru scénáře (miniatura, lupa bez označení karty) a Galerii; listování tlačítky, klávesami a tažením prstem; šířka okna, velikost obrázku.
  - `tagy` (e2e): pole v okně Upravit, štítky na kartě (3 + „+N“), řada tagů (Vše, Bez tagu, všechny/kterýkoli), rychlý filtr z karty, hledání, hromadně, přetažení na tag, nahrání s vybraným tagem, editor scénáře (řada tagů, + Přidat vše s tagem), editor slidu, Nastavení → Tagy, archiv, telefon.
  - `html` (e2e): editor Vizuálně/Kód, vložení hodnoty a obrázku z galerie, náhled se skriptem v izolovaném rámu, nahrání a nahrazení souboru, náhodný výběr, telefon.
  - `galerie`: nahrání s kompresí, výběr podle data psaný po číslicích, náhled týdne, synchronizace, archiv.
  - `diagnostika`.
  - `necinnost`: odhlášení po nečinnosti (upozornění, Zůstat přihlášen, psaní, vypršení). Trvá asi 45 s.
  - `prihlaseni`: Passkey (virtuální zařízení), Google, zapomenuté heslo, karta Zabezpečení.
  - `mobil`: telefon 390 px – menu ☰, Nahrát plakát, Přidat do scénáře, spodní lišta, karty Nastavení.
  - `napoveda`: obsah, hledání, tlačítka „?“, mobilní obsah.
  - `tv`: aplikace pro TV Samsung v Chromiu s napodobeným Tizenem na vlastním serveru (párování, přehrávání, ovladač, stav, nastavení, chyba, přehrání ze serveru, nové spuštění, zrušení spárování, HTML dokument v izolovaném rámu) a kontrola syntaxe ES2017 (potřebuje balíček `acorn` v NODE_PATH).
  - `fx`: editor – výběr a doladění stylu, rozestup, uložení, porovnání náhledu v editoru se serverem, Nastavení → Písma pro slidy.
  - `android`: přehrávač z projektu pro Android (assets/www) s napodobeným `window.TwbAndroid` – párování s druhem android, tlačítka předávaná aplikací.
  - `plugin`: plugin pro Chrome načtený do Chromia (připojení, stažení, odznak, okénko, přehrávač všech druhů médií, chyba serveru, změna složky, HTML dokument v izolované stránce) a karta Nastavení → Zařízení.
- `e2e/setup.php` připraví heslo `test-heslo-123` a testovací obrázky.

Nový test: soubor `tests/unit/<název>_test.php` začíná `require dirname(__DIR__) . '/lib.php';` a obsahuje volání `test('popis', function () { … });`.
