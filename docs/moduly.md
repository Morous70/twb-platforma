# Moduly TWB platformy

## Zásady
- Modul = složka `modules/<název>/` s `module.json`, `src/` (třídy `TWB\<Název>\…`), případně `assets/`, `fonty/` a testy v `tests/<název>/`.
- Název modulu a jmenný prostor jsou bez diakritiky (`jadro`, `tagy`, `pisma`), popisy a texty uživatelům jsou česky.
- Minimální verze PHP 8.1 (hosting běží na 8.5). Testy mění upozornění a zastaralé funkce na chyby.
- Moduly nepoužívají globální funkce ani konstanty. Od aplikace smějí použít jen to, co dostanou přes `TWB\Jadro\Host`
  (databáze, nastavení, protokol, datová složka, konfigurace, registr akcí a haků).
- Názvy tabulek a sloupců, které patří aplikaci (např. `media`, `library`), se předávají konstruktoru modulu.
- Co se v aplikacích liší, řeší aplikace přes **haky** (`Registr::hak`, `Registr::sluc`) nebo přes funkci předanou konstruktoru
  (např. `Pisma` se přes ni ptá, které slidy písmo používají).
- Modul s vlastními tabulkami má třídu `Migrace` implementující `ModulMigrace`; verze schématu se ukládá do `module_versions`.
- Změna modulu musí být zpětně kompatibilní v rámci hlavní verze. Každá aplikace má `modules.lock` s verzemi, které používá.

## Spuštění testů
```
php tests/run.php            # všechny moduly
php tests/run.php tagy       # jeden modul
```

## Stav modulů
| Modul | Verze | Stav |
|---|---|---|
| jadro | 0.1.0 | zavaděč, Db, Nastaveni, Protokol, Registr, Host, Migrace |
| tagy | 0.1.0 | hotovo, sloučeno z obou aplikací |
| pisma | 0.1.0 | hotovo; vestavěná písma ve složce `fonty/` |
| obrazky | 0.1.0 | hotovo; komprese, EXIF, průhlednost, animované GIFy (opravena detekce GIFu se dvěma snímky) |
| napoveda | 0.1.0 | hotovo (PHP); klient `assets/help.js` je zatím převzatý z Plánovače beze změny |
| prihlaseni | 0.1.0 | hotovo: Tokeny, Zamek, Heslo, Obnova, Google, Passkey, Relace (+CSRF), Posta; vlastní tabulky `auth_tokens`, `passkeys` |
| zalohy | 0.1.0 | hotovo: `Zalohy` (šifrovaný ZIP AES-256, denní záloha, obnova s pojistnou zálohou, kontrola kontrolních součtů souborů), `Skupina` (galerie, písma …), `Snimek` (SQLite VACUUM INTO – způsob Social Planneru) |
| aktualizace | 0.1.0 | hotovo: `Aktualizace`, `Manifest` (Ed25519), `Balicek` (bezpečné rozbalení), `Curl`; vyžaduje modul `zalohy` |
| galerie | 0.1.0 | hotovo: `Galerie` (nahrání s kontrolou typu i obsahu, nahrazení souboru, komprese s uchováním originálu, archiv, tagy, mazání s vazbami aplikace), `Nahravani` (soubor po částech), `Sloupce` (přiřazení ke sloupcům tabulky), `Migrace` (výchozí tabulka `media`) |
| slidy | 0.1.0 | serverová část hotova: `Navrh` (očištění šablony, formáty dodá aplikace, volitelný vlastní rozměr 16–4096 px pro bannery), `Vykresleni` (JPEG + klíč obsahu), `Text`, `Fx`, `Kulicky`, `Gd`; rozšíření přes rozhraní `Zdroje`, `Promenne`, `Prvek`. Editor v prohlížeči: `web/` (kopie ze Social Planneru + `HOST.md` s tím, co dodává aplikace) |
| html | 0.1.0 | hotovo: `Dokument` (kontrola zdroje do 5 MB, dosazení proměnných mimo skripty/styly/komentáře s HTML escapem, vložení písem z `pisma` do dokumentu, seznam externích adres, klíč obsahu), rozhraní `Promenne`; ukládání řeší aplikace |
| data | 0.1.0 | část 1–3 hotova: `Format` (čísla, data, filtry), `Tabulka` (Skupina\|Parametr\|Hodnota), `Promenne` (kalendář, svátky, vlastní proměnné, podmínky), rozhraní `Zdroje` a `Pocasi`, napojení `ProSlidy` a `ProHtml`, `Migrace` (`data_rows`). `Vzdalene` (zdroje JSON/CSV: stahování podle intervalu, uchování posledních dat při výpadku, protokol výpadku a obnovy, hodnota podle cesty, strom pro editor) a `Hledani` (CSV, cesty). `Predpoved` (počasí OpenWeather pro `{{Počasí:…}}`: mezipaměť podle místa, stahování a obnova, výpadky, hodnoty dnes/zítra/pozítří). Zbývá: správa řádků a zdrojů (uložení, import, smazání) a prvek „Počasí“ do slidů |

## Známé chyby nalezené při vyčleňování
- `Napoveda::zkontroluj()` a původní `help_check()` v Plánovači: ve zprávách typu `„$k“` bere PHP uvozovku jako součást jména proměnné. V modulu opraveno, v Plánovači zmizí po přepnutí na modul.
- Původní `img_is_animated_gif()` nepoznala GIF se dvěma snímky, takže by se zmenšil a přišel o animaci. V modulu opraveno.
- `imagedestroy()` je od PHP 8.5 zastaralé, moduly ho nepoužívají.

## Poznámky k přihlášení
- Moduly nesahají na `$_SESSION` ani `$_SERVER`: `Relace` dostane pole (u aplikace `$_SESSION`), `Google` dostane HTTP klienta, `Obnova` dostane odesílání e-mailu a adresu. Díky tomu jdou testovat bez webového serveru.
- Rozdíly mezi aplikacemi řeší parametry: tvar odkazu obnovy (`{zaklad}/?reset={token}` v Plánovači, `{zaklad}/login.php?reset={token}` v Social Planneru), formát času u Passkey (datum a čas, nebo číslo), záložní otisk hesla z konfigurace.
- Zkušební (mock) přihlášení přes Google zůstává věcí aplikace – modul ho nezná.
- Testy ověřují i to, že odstranění kontroly podpisu nebo počítadla Passkey test shodí.

## Poznámky k zálohám a aktualizaci
- `Zalohy` nezná tabulky ani složky konkrétní aplikace: dostane pořadí tabulek, povinné tabulky, místní nastavení (nepřepisují se obnovou) a `Skupina` souborů. Plánovač: skupina `media` (galerie, jen při změně, s úklidem po obnovení) a `fonts` (vždy). Formát `database.json` a názvy složek v ZIPu zůstaly shodné s Plánovačem, takže jde obnovit i jeho dosavadní zálohy.
- Převod databáze po obnovení starší zálohy je zavěšený jako callable `poObnoveni` (v Plánovači `run_migrations`).
- Social Planner nahradí vlastní zálohu stejným modulem; jeho dosavadní snímek SQLite (`Snimek::automaticky`) zůstává k dispozici jako lehčí varianta.
- `Aktualizace` nikdy nesahá na `config.php` a `data/` (parametr `zachovat`), maže jen soubory, které předchozí aktualizace sama nainstalovala (evidence v `installed-files.json`), a při selhání kontroly zdraví vrátí soubory; databázi vrací ze zálohy před aktualizací jen tehdy, když ji nová verze převedla na vyšší verzi dat.
- Server a kontrola zdraví jsou vyměnitelné (callable), takže testy běží bez sítě. Testy ověřují: podpis, SHA-256, Zip Slip, symbolické odkazy, zachované cesty, návrat souborů i databáze; odstranění kterékoli z těchto kontrol aspoň jeden test shodí.
- Úrovně protokolu: modul zapisuje `info`, `warn`, `error` (původní úroveň `success` se zapisuje jako `info`).

## Poznámky ke galerii
- Tabulka aplikace se popisuje objektem `Sloupce` (výchozí = `media` z Plánovače). Social Planner: `library`, sloupce `file`, `name`, `type`, `w`, `h`, bez `mime`, `md5` a bez sloupců pro originál; čas se tam ukládá jako unix (`casFormat: 'unix'`). Bez sloupců pro originál se nekomprimuje, bez sloupce pro tagy nebo archiv příslušné funkce hlásí srozumitelnou chybu.
- Co je specifické pro aplikaci, řeší funkce předané konstruktoru: `priSmazani` (smazat vazby média, běží v transakci spolu s řádkem) a `pouziti` (kde se médium používá). Virtuální položky Plánovače (slide, HTML, náhodný a datovaný výběr) zůstávají v jeho tabulce `media` a spravují je jeho moduly/kód; galerie pracuje s obrázky, videi a PDF.
- Zdroj nahraného souboru se přesune a po chybě smaže. Názvy souborů na disku jsou náhodné; originál po kompresi se uchovává a komprese vždy vychází z něj.
- Zvláštní chování oproti Plánovači: nahrávání po částech vyžaduje souvislé pořadí částí a hlídá celkovou velikost (výchozí 512 MB).
- Sloupec `Galerie::soubory()` se použije jako zdroj pro `Skupina` v modulu `zalohy`.

## Slidy

- Výstup je shodný s Social Plannerem 1.9.2 bajt po bajtu: test spouští zmrazenou kopii původního `slide.php` (`tests/slidy/original/`, needitovat) v samostatném procesu a porovnává očištěnou šablonu, klíč obsahu i výsledný JPEG.
- Počasí a proměnné/podmínky nejsou součástí modulu: patří do modulu `data` a připojí se přes `Prvek` a `Promenne`.
- Šablony Plánovače nemají `format/w/h`; po přechodu je dostanou a klíče vykreslení se jednou změní (slidy se jednou přegenerují).
- JS editor: základem je verze Social Planneru (nadmnožina Plánovačovy; rozdíl je jen formáty plátna, čas náhledu, písma a pole `grp`). Test hlídá shodu kopie, syntaxi a úplnost popisu v `web/HOST.md`. V prohlížeči zatím neotestováno.

## HTML

- Chování je shodné s `lib/html.php` z Plánovače médií; test spouští zmrazenou kopii (`tests/html/original/`) a porovnává kontrolu, dosazení, písma, sestavení i externí adresy. Výchozí dokument má volitelný rozměr (pro bannery), bez zadání je 1920×1080 jako dosud.
- Modul nic neukládá a nezná databázi: zdroj, hotový soubor a pravidelné přegenerování (`regenerate_html` v Plánovači) patří aplikaci, která si hlídá změnu přes `klic()`.

## Data

- Základem je `data.php` ze Social Planneru 1.9.2 (zná zápis `{x}` i `{{x}}`); test spouští zmrazenou kopii (`tests/data/original/`) a porovnává přes 900 vstupů: formáty čísel a dat, filtry, proměnné kalendáře i svátků, vlastní proměnné, tabulku dat, podmínky.
- Vlastní proměnné dodává aplikace funkcí (v Social Planneru tabulka `variables`); vzdálené zdroje a počasí se připojí přes `Zdroje` a `Pocasi`. Bez nich jsou jejich proměnné „neznámé“ a zůstanou v textu.
- Svátky jsou v `modules/data/data/svatky.php` (kopie ze Social Planneru, shodná s Plánovačem).
- Zdroje: `tests/data/original/sources-sp-1.9.2.php` je zmrazený originál; test porovnává výsledky, stav tabulky `sources`, protokol i stahované adresy včetně chyb (HTTP, neplatný JSON, `success:false`, CSV jako webová stránka) a obnovy. Odpovědi serveru i čas se do modulu dodávají funkcemi, takže testy nepotřebují síť. Text varování v protokolu je neutrální (originál říká „Příspěvky používají…“).
- Počasí: `tests/data/original/weather-sp-1.9.2.php` je zmrazený originál; test porovnává hodnoty, tabulku `weather`, protokol i volané adresy OpenWeather (chyby 401/404/429/500, výpadek, obnova, mazání nepoužitých míst). API klíč, výchozí místo a seznam míst použitých v textech dodává aplikace funkcemi (`mistaVTextu()` je najde v textu, v zápisu `{{…}}` i `{…}`). Při výpadku je text v protokolu neutrální.
