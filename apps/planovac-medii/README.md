# TWB Plánovač médií pro obrazovku – instalace na Wedos NoLimit

Aplikace podle plánu skládá obsah složky na Google Disku, kterou čte přehrávač na obrazovce.

## Jak to funguje

Každých 5 minut cron zjistí, které scénáře právě platí, a složku na Disku upraví tak, aby obsahovala přesně jejich média: chybějící soubory nahraje, přebývající odstraní. Když se nic nemění, na Disk vůbec nesahá.

**Pravidla scénáře (platí současně):** platnost od–do, dny v týdnu, hodiny, výjimky (dny, kdy neběží), priorita (nízká / normální / vysoká).

**Skupiny médií:** médium může mít v galerii skupinu (např. ER1 a ER2 ve skupině „ER“). Když je aktivních víc scénářů s médii ze stejné skupiny, zůstane jen to ze scénáře:
1. s vyšší prioritou,
2. při shodě s omezenou platností (od–do) před trvalým,
3. při shodě s kratší platností,
4. při shodě novější.

Média bez skupiny se nevytlačují nikdy.

**Typy médií:**
- obrázky (jpg, png, gif, webp, bmp)
- videa (mp4, mov, webm, m4v, avi, mkv)
- PDF
- **běžící text**: píše se přímo v aplikaci, na Disk jde jako `.txt`
- **odkaz na web**: zadá se adresa, na Disk jde jako `.url`

SlideShow rozpoznává typ jen podle přípony, proto ji aplikace vždy zachová.

**Náhodný výběr (🎲):** položka galerie s několika obrázky nebo videi. Ve scénáři se chová jako jedno médium. Každou hodinu jde na Disk jedno z nich, rotací bez opakování: vystřídají se všechna, než se některé zopakuje, a nový cyklus nezačíná tím, čím skončil předchozí. Může mít i skupinu.

**Počasí (od 1.20.0):** `lib/weather.php`. Poskytovatelé v `WEATHER_PROVIDERS` (teď `openweather`: API 2.5 `weather` + `forecast`, ikony `img/wn/<kód>@4x.png`); další služba = funkce vracející jednotná data `{name, now:{temp, feels, desc, icon, humidity, wind}, days:[{date, min, max, desc, icon}]}` + funkce s adresou ikony. Nastavení `weather_provider`, `weather_api_key`, `weather_place`; mezipaměť v tabulce `weather` (schéma 19, klíč = místo malými písmeny), ikony v `data/weather-icons`. Cron (`weather_refresh_due`) stahuje každých 15 min jen místa ze slidů, HTML dokumentů a výchozí; po chybě nový pokus za 15 min, při výpadku zůstanou poslední data a do Protokolu jde jedno varování. Prvek slidu `type: 'weather'` (`look` mini/card/days, `place`, písmo, barva, zarovnání, podklad, stín); rozvržení počítá jen server (`weather_layout` → části `icon`/`text` s polohou), editor je kreslí (`drawWeather`) a bez dat dostane ukázku (`weather_editor_info`, `sample`). Proměnné `{Počasí:[místo.]pole}` a `{Počasí:[místo.]dnes|zítra|pozítří|denN.max|min|popis|den|datum}` (`weather_value`). API `weather`, `weather_save`, `weather_test`, `weather_icon`. Pro testy `openweather_url` a `openweather_icon_url` v `config.php`.

**Zvětšení náhledu (od 1.19.0):** `thumb(m, true)` přidá `data-zoom`; globální klik (zachycený dřív než klik na kartu) otevře `preview(m, list)` – okno `.modal-box.full`, listování `#pvPrev`/`#pvNext`, klávesy ←/→, tažení prstem. Skupina pro listování = nejbližší `[data-zoom-group]` (seznam čísel v `data-zoom-ids`, jinak všechny miniatury ve skupině): Přehled `.now-grid`, seznam scénářů `.strip` (všechna média scénáře), editor scénáře `#inList` a `#pGrid` (lupa `.zoom-btn`), Galerie (zobrazená média).

**Tagy místo složek (od 1.18.0):** galerie se třídí tagy (např. Loterie, Tabák). Řada tagů nahoře (`tagBar()` v `app.js`): Vše, Bez tagu, tagy s počty, všechny/kterýkoli, vpravo Archiv; přetažení média na tag ho přidá (`media_tags`), klik na tag na kartě = rychlý filtr, vybraný tag dostanou nově nahrané soubory (`upload_chunk` pole `tags`) i nová média z dlaždic (`currentTags()`). Ve výběrech (scénář, náhodný, podle data, obrázek) je stejná řada; v editoru scénáře jde tag přetáhnout do „Ve scénáři“ (přidá všechna jeho média) nebo použít **+ Přidat vše s tagem**. Migrace v18 převedla každou složku na tag stejného jména (nejvýš 30 znaků; archivovaná média podle `archive_folder_id`), vyprázdnila `folder_id`, `archive_folder_id` a tabulku `folders` (ta zůstává kvůli obnově starších záloh – při obnově se převod udělá znovu). API složek (`folders`, `folder_save`, `folder_delete`, `media_move`) je pryč; `media_out` už `folder_id` nevrací.

**Tagy (od 1.17.0):** `media.tags` (JSON seznam, schéma 17), knihovna `lib/tags.php` (`tags_clean` – 30 znaků, nejvýš 10, bez opakování, velikost písmen se nerozlišuje a přebírá se zápis existujícího tagu; `tags_all`, `tags_bulk`, `tag_rename` se sloučením, `tag_delete`). API `tags`, `media_tags {ids, add, remove}`, `tag_rename {from, to}`, `tag_delete {name}`; parametr `tags` přijímají `media_update`, `text_save`, `url_save`, `pool_save`, `dated_save`, `slide_save`, `html_save`; `media_out` vrací `tags`. Klient: `tagField`, `tagFilter` (stav `{sel, mode: and|or}`), `tagOk`, `tagsHtml`, `bulkTags`, `editTagsModal` v `app.js`; pole Tagy je ve společných polích okna Upravit (`commonFields`, inicializace v `openModal`), filtr v galerii (i archiv), editoru scénáře, náhodném výběru, výběru podle data a `pickImage`; karta Nastavení → Tagy (`SET_RENDER.tagy`).

**HTML dokumenty (od 1.16.0):** druh média `html` (mime `text/html`). Zdroj je v `media.content` jako JSON `{source, key}`, soubor na disku je hotový dokument pro přehrávač: `html_build()` v `lib/html.php` dosadí `{proměnné}` (mimo `<script>`, `<style>` a komentáře, hodnoty escapované, `&nbsp;` v zápisu nevadí) a vloží `@font-face` s daty písem aplikace, které dokument používá. Obnovuje se spolu se slidy (`regenerate_slides` → `regenerate_html`). API `html_new`, `html_get`, `html_save`, `html_preview`; nahrání souboru `.html/.htm` přes `upload_chunk` (i `replace_id`). Limit 5 MB (`HTML_MAX_BYTES`). `file.php` a `now.php?file=` posílají u HTML `Content-Security-Policy: sandbox allow-scripts` (dokument nikdy neběží s původem aplikace). Editor `assets/html-editor.js`: SunEditor 2.47.12 (MIT, `assets/vendor/suneditor.min.js` – vlastní menší sestavení esbuildem jen s potřebnými pluginy a češtinou, načítá se až při otevření editoru), režim Vizuálně upravuje jen obsah `<body>` (hlavička a skripty zůstávají beze změny, skripty z obsahu se přesunou na jeho konec), režim Kód celý zdroj; náhled v `<iframe sandbox="allow-scripts">`. Zařízení: `DEVICE_EXT` html/htm, `device_now()` HTML neposílá přehrávačům starším než `DEVICE_HTML_SINCE` (1.2.0, podle hlavičky X-Client). Přehrávače: Chrome – sandboxová stránka `frame.html` + `frame.js` (manifest `sandbox`, vlastní `content_security_policy.sandbox`), dokument dostane zprávou; Tizen/Android – `<iframe sandbox="allow-scripts" srcdoc>`; plocha 1920×1080 se zmenší (`transform: scale`), `pointer-events: none`.

**Archiv v galerii (od 1.15.0):** tlačítko **🗄 Archiv** na kartě (hromadně **🗄 Do archivu**) nastaví `media.archived_at` (do 1.17 se pamatovala i původní složka v `archive_folder_id`). Archivované médium zůstává ve scénářích, výběrech i slidech a dál hraje (plánovač a synchronizace s ním počítají jako dosud), jen ho klient (`store.archive`, ne `store.media`) nenabízí v žádném výběru a `scenario_add_media` ho odmítne. Archiv je vpravo v pruhu složek (počet a velikost), u položek ukazuje `used_in` (scénáře, výběry, slidy). Vrácení = `media_archive {ids, archive:false}` do původní složky; trvalé smazání stejně jako běžné (`media_delete`). Schéma 16.

**Výběr médií do scénáře:**
- Na počítači přetahováním z galerie do panelu „Ve scénáři“. Lze přetáhnout i celý tag (všechna jeho média) nebo několik označených médií najednou (klik = označit, Shift = rozsah, dvojklik = přidat).
- Na mobilu tlačítkem +.

**Vnořené scénáře:** do scénáře lze vložit jiný scénář (v editoru „Vložené scénáře“ → „+ Vložit scénář…“). Vložený scénář přidá svá média a o čase běhu i prioritě rozhoduje scénář, do kterého je vložený.
- Typ **Sada** nikdy neběží sám a slouží jen ke vkládání, např. „Loterie – základ“ vložená do více scénářů. Médium pak změníte na jednom místě.
- U vloženého plánovaného scénáře lze zapnout „jen když platí i jeho vlastní pravidla“. Jeho média se pak přidají jen v době, kdy platí jeho dny a hodiny.
- Vkládat lze i do více úrovní. Vložení, které by vytvořilo kruh (A vkládá B, B vkládá A), aplikace nedovolí.

**Slidy (editor):** v Galerii tlačítko „+ Slide“. Plátno 1920×1080 a na něm:
- pozadí: barva, přechod, nebo obrázek z galerie se ztmavením
- textová pole: 6 písem s češtinou, velikost, barva, zarovnání, řádkování, VELKÁ PÍSMENA, automatické zmenšení, stín, obrys, podklad
- obrázky z galerie a obdélníky

Prvky se přetahují myší a přichytávají ke středu a okrajům. Funguje Ctrl+Z / Ctrl+Y.

Výsledkem je obrázek JPG v galerii, který se používá jako každé jiné médium (scénáře, skupiny, náhodné výběry). Tlačítko „Skutečný náhled“ ukáže přesně obrázek ze serveru. Na mobilu lze upravovat jen texty.

**Proměnné v textu:**

| Zápis | Výsledek |
|---|---|
| `{datum}` | 16. 10. 2026 |
| `{datum:+1}` | zítřejší datum |
| `{den}` / `{Den}` | pátek / Pátek |
| `{svatek}` / `{svatek:+1}` | kdo má svátek dnes / zítra (český kalendář) |
| `{mesic}` | měsíc slovy |
| `{rok}` | rok |
| `{tyden}` | číslo týdne |
| `{odpocet:24.12.2026}` | za 69 dní / zítra / dnes |
| `{dnu:24.12.2026}` | počet dní jako číslo |

Filtry: `|velka`, `|mala`, `|prvni` (první písmeno velké).

Slidy s proměnnými cron po změně (např. po půlnoci) sám přegeneruje a nahraje na Disk. Emoji písma neobsahují, proto se ve slidu nezobrazí.

**Data:** sekce v menu s řádky **Skupina | Parametr | Hodnota**, např. `Sportka | 26.11.2026 | 125000000`. Parametr je datum (losování) nebo libovolný text (`Ceník | Káva | 45`).
- Řádky upravíte přímo v tabulce, nebo je vložíte z Excelu (zkopírovat tři sloupce → „Vložit z Excelu“). Volitelně se tím nahradí celé skupiny.
- Prošlé řádky jsou šedé a smažete je jedním tlačítkem.
- Po změně dat se dotčené slidy hned přegenerují a do 5 minut jsou na obrazovce.

V textu slidu:

| Zápis | Výsledek |
|---|---|
| `{Sportka.nejbližší}` | hodnota s nejbližším datem (dnes a dál) |
| `{Sportka.nejbližší.parametr}` | jeho datum |
| `{Sportka.další}` | následující po nejbližším |
| `{Sportka.poslední}` | poslední s datem v minulosti |
| `{Sportka:26.11.2026}` | přesně tento parametr |
| `{Ceník:Káva}` | textový parametr |

Formátování:
- Formát čísla – v editoru tlačítko **Formát čísla…** (kurzor v proměnné) nebo volba „vybrat formát čísla…“ při vkládání:
  - `|cislo` → 125 000 000, `|kc` → 125 000 000 Kč, `|tis` → 125 000 tis.
  - `|mil` → 125 mil., `|mld` → 1,26 mld., `|milionu` → 125 milionů (1 milion, 3 miliony, 2,5 milionu), `|miliard` → 1,26 miliardy
  - Desetinná místa za dvojtečkou: `:1` = nejvýš 1 místo (koncové nuly se vynechají), `:0,00` = vždy 2 místa (`{Ceník:Káva|kc:0,00}` → 45,50 Kč).
  - `|bezmezer` → bez mezer mezi tisíci, `|pripona: Kč` → připojí text (`{x|mil|pripona: Kč}` → 125 mil. Kč).
- `|datum` – formát data. V editoru tlačítko **Formát data…** (změní proměnnou, ve které je kurzor, jinak vloží dnešní datum). Pojmenované formáty: `kratke` (26. 11.), `bezmezer` (26.11.2026), `nuly` (05.03.2026), `slovem` (26. listopadu), `dlouhe` (26. listopadu 2026), `den` (čtvrtek 26. 11.), `den-plne` (čtvrtek 26. 11. 2026), `den-slovem` (čtvrtek 26. listopadu), `den-dlouhe` (čtvrtek 26. listopadu 2026), `dentydne` (čtvrtek), `mesic-rok` (listopad 2026).
- Vlastní formát ze zástupných znaků (velkými písmeny): `D` 5, `DD` 05, `M` 3, `MM` 03, `MĚSÍC` březen, `MĚSÍCE` března, `RR` 26, `RRRR` 2026, `DEN` čtvrtek, `DN` čt. Např. `{datum|datum:DN D. MĚSÍCE}` → pá 16. října.
- `|odpocet` → za 41 dní
- `|vychozi:—` → co ukázat, když hodnota chybí

V editoru je vše v nabídce „+ Vložit proměnnou“ a pod tlačítkem „?“.

**Podmínky (editor slidu → Podmínky):**
- **Zobrazit jen když:** prvek (text, obrázek, obdélník) se zobrazí, jen když platí všechny podmínky. Například `Sportka.nejbližší > 100000000`, `den = pátek`, `hodina >= 18`, `datum >= 1.12.2026`.
- **Varianty textu:** použije se první varianta, jejíž podmínky platí, jinak základní text. Například „REKORDNÍ JACKPOT!“, když je jackpot nad 120 mil.

Editor průběžně ukazuje, co právě platí.

**Vzdálené zdroje (Data → Vzdálené zdroje):** data ve formátu JSON stažená z jiné adresy. Zdroj **Allwyn** (`https://trafikabr.cz/allwyn/api.php?action=latest_all`) je předem nastavený.
- Cron zdroj stahuje sám podle intervalu (výchozí 15 minut). Tlačítkem „Stáhnout teď“ ho stáhnete hned.
- Když se data změní, dotčené slidy se přegenerují a nahrají na Disk.
- Když zdroj nebude dostupný nebo vrátí chybu, slidy si nechají poslední stažené hodnoty. Do Protokolu se zapíše jedno varování a po obnovení zpráva o obnovení. SMS ani e-mail se kvůli tomu neposílají.

Ve slidu se hodnota vkládá tlačítkem **Ze zdroje…**: vyberete hodnotu ze seznamu a formát (mil., mld., datum s dnem…). Zápis vypadá takto:

| Zápis | Výsledek |
|---|---|
| `{Allwyn:sportka.superjackpot\|mil}` | 164 mil. (jackpot příštího losování) |
| `{Allwyn:eurojackpot.jackpot\|mld}` | 2,93 mld. |
| `{Allwyn:sportka.datum\|datum:den}` | středa 14. 10. |
| `{Allwyn:sportka.tah1}` | 22 14 27 42 9 5 |
| `{Allwyn:sportka.tah1\|cisla:, }` | 22, 14, 27, 42, 9, 5 |
| `{Allwyn:sportka.tah1.2}` | 14 (druhé číslo) |

Hra se dá zapsat interním klíčem (`mega20`), kódem (`mega-20`) i názvem (`20 Mega`). Velikost písmen ani diakritika nehrají roli. Hodnoty ze zdroje fungují i v podmínkách, např. `Allwyn:eurojackpot.jackpot > 2000000000`.

**Kuličky (editor slidu → + Kuličky):** tažená čísla v barevných kruzích.
- Čísla vyberete tlačítkem „Ze zdroje…“ (např. Sportka 1. tah), nebo je napíšete ručně.
- Dodatková čísla se zobrazí za mezerou jinou barvou.
- Vzhled je plastický (lesk a stín) nebo plochý, s volitelným obrysem. Dále se nastavuje barva kuličky a čísla, průměr, mezery, písmo a zarovnání.
- Víc čísel se zalomí do řádků podle šířky rámečku. Když se nevejdou, kuličky se zmenší.
- Na každý tah Sportky dejte samostatný prvek Kuličky.

**Výhradní scénář:** po dobu svého běhu potlačí všechny ostatní.

**Výchozí scénář:** běží, když neběží žádný jiný. Může být jen jeden.

**Pevný scénář:** zobrazuje se vždy, ať běží cokoli: plánované scénáře, výchozí i výhradní scénář, a také když neběží nic. Pevných scénářů může být víc. V nastavení plánovaného nebo výchozího scénáře je v části „Pevné scénáře“ můžete odškrtnout. Dokud takový scénář běží, pevný scénář se nezobrazí. Ve skupinách médií soutěží jako běžný scénář s prioritou normální.

**Příklad**
- Scénář 1 (Po, Čt): ER1, Allwyn
- Scénář 2 (14.–15. 10. 2026): ER2
- 14. 10. (St): ER2 · 15. 10. (Čt): ER2 + Allwyn · ostatní Po a Čt: ER1 + Allwyn · jindy: výchozí scénář

Přehled v aplikaci ukazuje, co je ve složce teď, kdy bude další změna, a týdenní náhled (den × hodina).

## Aktualizace z předchozí verze

**Od verze 1.6.0 přes TWB Update Server:** v `config.php` doplňte `'update_token' => 'upd_…'` (token aplikace `media-planner` z administrace Update Serveru). Pak Nastavení → Systém → Aktualizace → **Zjistit novou verzi** / **Aktualizovat**. Ručně přes FTP to jde dál stejně:

Přes FTP přepište všechny soubory **kromě** `config.php` a složky `data/`. Databáze se při prvním otevření aplikace (nebo při běhu cronu) sama převede na novou verzi. Média, scénáře ani nastavení se nemění.

## Instalace

### 0. Verze PHP
V administraci Wedos u webhostingu zkontrolujte, že běží PHP 8.0 nebo novější (doporučeno 8.2+).

### 1. Databáze
V administraci Wedos → webhosting → **Databáze** vytvořte MySQL databázi a poznamenejte si server, název, uživatele a heslo.

### 2. Nahrání souborů
Obsah archivu nahrajte přes FTP do složky `www/media-planner` (aplikace pak poběží na `https://trafikabr.cz/media-planner`). Složka `data/` musí být zapisovatelná (na Wedosu je standardně).

### 3. Konfigurace
Zkopírujte `config.sample.php` jako `config.php` a vyplňte:
- přístup k databázi (`db_dsn`, `db_user`, `db_pass`),
- `cron_key` – dlouhý náhodný řetězec,
- `mail_from` – adresa na vaší doméně, např. `planovac@trafikabr.cz`,
- Google údaje (krok 5).

### 4. Instalační průvodce
Otevřete `https://trafikabr.cz/media-planner/install.php`, nastavte heslo. Tím je instalace hotová.

### 5. Google Disk
1. [Google Cloud Console](https://console.cloud.google.com/) → nový projekt.
2. **APIs & Services → Library** → zapněte **Google Drive API**.
3. **OAuth consent screen**: typ *External*, vyplňte název a e-mail. Pak stav přepněte na **In production** (tlačítko *Publish app*). V režimu *Testing* by přístup každých 7 dní vypršel. Ověření Googlem pro vlastní potřebu není nutné, jen se při přihlášení zobrazí upozornění „aplikace není ověřena“ → *Pokračovat*.
4. **Credentials → Create credentials → OAuth client ID**, typ *Web application*, Authorized redirect URI:
   `https://trafikabr.cz/media-planner/oauth.php`
5. Client ID a Client secret vložte do `config.php`.
6. V aplikaci: **Nastavení → Připojit Google Disk**, pak vložte odkaz na složku, kterou čte přehrávač.

### 6. Cron
Wedos administrace → webhosting → **Cron**: spouštět každých 5 minut skript `media-planner/cron.php`. Pokud Wedos cron vyžaduje URL, použijte `https://trafikabr.cz/media-planner/cron.php?key=VAS_CRON_KEY`.

Váš vlastní cron může volat stejnou URL jako zálohu. Dvojí spuštění nevadí, druhé počká nebo skončí. Přesná URL i příkaz jsou v aplikaci v Nastavení.

### 7. Upozornění
V Nastavení vyplňte e-mail, případně telefon a údaje BulkGate (Application ID a token z portálu BulkGate → *Modules & APIs → HTTP Simple API*). Tlačítkem pošlete zkušební zprávu.

Upozornění přijde při první chybě synchronizace, pak nejvýš jednou za 6 hodin, a znovu po opravě.

## Poznámky
- Do galerie lze nahrát obrázky (jpg, png, gif, webp, bmp) a videa (mp4, mov, webm, m4v, avi, mkv). Nahrává se po částech, takže limit hostingu na velikost uploadu nevadí.
- Odstraněné soubory jdou do koše Google Disku (30 dní lze obnovit), v Nastavení lze přepnout na trvalé mazání. Podsložky v cílové složce se nemění.
- Zálohujte databázi a složku `data/media`.
- Testovací režim bez Googlu: v `config.php` nastavte `'drive_mock' => true`. Soubory se pak kopírují do `data/mock-drive/`.
- Náhledy PDF vykresluje knihovna pdf.js (Mozilla, licence Apache 2.0), která je přibalená ve složce `assets/vendor`.
- Písma (Roboto, Montserrat, Open Sans, Oswald, Bebas Neue, Playfair Display) jsou ve složce `assets/fonts` pod licencí SIL Open Font License. Kalendář jmen pochází z balíčku namedays-cs (MIT).
- Slidy vykresluje knihovna GD s podporou písem (FreeType). Ověříte to souborem `gdtest.php` (dodává se zvlášť).

## Spuštění synchronizace přehrávače (Synology)

Přehrávač SlideShow si složku z Disku stahuje sám jen jednou za interval (např. 3600 s). Aby se změny projevily hned, spouští jeho synchronizaci skript na Synology v místní síti. Přehrávač tak nemusí být dostupný z internetu.

- Cron po každé změně ve složce (nahrání nebo smazání) uloží čas změny (`last_change_at`).
- `signal.php?key=…` vrátí tento čas. Klíč je v Nastavení a lze ho vyměnit tlačítkem Nový klíč.
- Skript na Synology (Plánovač úloh, každou minutu, uživatel root) se zeptá na `signal.php`. Při novější změně zavolá v místní síti `POST /ajax/synchronize?url=gdrive://<ID složky>&method=GET&target=/&clearFolder=true`.
- Hotový skript a návod pro DSM 6.2 jsou v Nastavení → Přehrávač SlideShow. Heslo k přehrávači se doplňuje jen do skriptu na Synology.
- Pokud se Synology neozve déle než 15 minut, zobrazí Přehled upozornění.
- Pozor: u API přehrávače musí mít zdroj předponu `gdrive://`, jinak synchronizace skončí chybou (`filesFailed: 1`).

## Komprese obrázků a nahrazení souboru

- **Automatická komprese** (Nastavení → Obrázky) při nahrání a nahrazení souboru:
  - Obrázek větší než max. rozměr (výchozí 1920×1080) se zmenší.
  - Soubor nad hranicí velikosti (výchozí 1024 kB) se převede na JPG (výchozí kvalita 85 %) i bez zmenšení.
  - Obrázky se skutečnou průhledností se jen zmenší a zůstanou PNG.
  - Animované GIFy zůstanou beze změny.
  - Fotky se otočí podle EXIF.
  - Výsledek se použije jen tehdy, když je menší než originál.
- **Originál** se uchová (sloupce `orig_*` v tabulce `media`). U obrázku je štítek 🗜 a v jeho úpravách tlačítka „Vrátit původní“ a „Komprimovat znovu“. Komprimuje se vždy z originálu podle aktuálního nastavení. Originál se smaže až se smazáním položky z galerie.
- **Hromadně:** v galerii označte obrázky a klikněte na „🗜 Zmenšit a komprimovat“.
- **Nahradit soubor** (obrázek, video, PDF): v úpravách položky. Nový soubor musí být stejného druhu. Položka zůstane ve všech scénářích, náhodných výběrech i slidech.
  - Název se převezme z nového souboru. Když byla položka přejmenována, zůstane jméno a změní se jen přípona.
- Ve všech případech zůstává ID položky. Slidy, které obrázek používají, se přegenerují a cron při příští synchronizaci vymění soubor na Disku.

## Verze, vzhled a ikona

- Číslo verze je jen v souboru `VERSION` (tvar hlavní.vedlejší.oprava). Načítá ho `lib/version.php` a čte ho i TWB Update Server. Novinky jsou v `APP_CHANGELOG` v `lib/version.php` a zobrazují se v Nastavení → Systém.
- Balíček pro aktualizaci se jmenuje `twb-planovac-medii-<verze>.zip`. Uvnitř je vždy složka `media-planner/`.
- Ikona a favicony jsou v `assets/icons/`, manifest pro přidání na plochu telefonu je `manifest.webmanifest`. Hlavičku stránek (favicony, vzhled bez probliknutí) vypisuje funkce `page_head()`.
- Vzhled Automaticky / Světlý / Tmavý se volí v levém panelu. Volba se ukládá zvlášť v každém prohlížeči (localStorage `mp-theme`), ručně zvolený vzhled nastavuje atribut `data-theme` na `<html>`.

## Instalace jako aplikace (PWA)

- Aplikaci lze nainstalovat na plochu počítače i telefonu. Otevírá se pak ve vlastním okně bez lišty prohlížeče.
  - Chrome a Edge na počítači a Chrome na Androidu: tlačítko **⤓ Instalovat aplikaci** v levém panelu nebo na kartě Nastavení → Systém.
  - iPhone a iPad (Safari): Sdílet → Přidat na plochu.
- Potřebné soubory:
  - `manifest.webmanifest`: název, ikony včetně maskovatelné pro Android, zkratky Přehled, Galerie a Scénáře.
  - `sw.js`: service worker. Nic z aplikace neukládá do mezipaměti, jen při výpadku sítě zobrazí `offline.html`.
- Instalace funguje jen přes HTTPS, což Wedos splňuje.

## Zdroje CSV

- V sekci Data → Vzdálené zdroje zvolte formát **CSV**. U adresy končící `.csv` nebo u odkazu na tabulku Google se formát zvolí sám.
- **Tabulka Google:** stačí běžný odkaz (`…/spreadsheets/d/ID/edit#gid=N`). Aplikace ho při stahování převede na export CSV daného listu. Tabulka musí být sdílená „Kdokoli s odkazem“.
- Oddělovač (`;`, `,`, tabulátor, `|`) se rozpozná sám. Kódování UTF-8 i Windows-1250 (Excel) taky. Volba „První řádek obsahuje názvy sloupců“ je zapnutá jako výchozí. Nejvýš 5 000 řádků a 100 sloupců.
- Ve slidu:
  - `{Ceník:Káva.Cena}` vybere řádek podle hodnoty v 1. sloupci a sloupec podle záhlaví. Na velikosti písmen a diakritice nezáleží.
  - `{Ceník:3.Cena}` vybere 3. řádek dat.
  - Bez záhlaví se sloupce číslují: `{Ceník:Káva.2}`.
  - Funguje i tlačítko „Ze zdroje…“ a všechny formáty čísla a data.
- Technicky se CSV ukládá jako seznam řádků `[{_row: 1. sloupec, Záhlaví: hodnota, …}]` a prochází se stejně jako JSON (`_row` je první v `SRC_ID_KEYS`).

## Zálohy

- **Nastavení → Zálohy**: heslo záloh (aspoň 8 znaků, bez apostrofu), automatické zálohy, složka na Synology a počet dní, po kterých se tam zálohy mažou.
- **Každý den od 3:00** (cron) vznikne `data/backups/twb-planovac-zaloha-RRRR-MM-DD-HHMMSS[-soubory].zip`. Je to ZIP šifrovaný AES-256 heslem záloh a obsahuje:
  - `database.json`: všechny tabulky včetně nastavení a přístupových údajů;
  - `media/…`: soubory galerie včetně uchovaných originálů, ale jen když se od poslední zálohy se soubory změnily (jinak je jméno zálohy bez `-soubory`).
- Na serveru zůstává posledních 7 záloh a vždy nejnovější záloha se soubory. Složka `data/` je zvenku nepřístupná.
- **Synology:** skript (karta Přehrávač) si jednou za hodinu přes `backup.php?key=…` stáhne nové zálohy do zvolené složky a smaže zálohy starší než zvolený počet dní.
- **Obnovení:** v Nastavení → Zálohy → Obnovit… (ze seznamu) nebo Obnovit ze souboru… (ZIP ze Synology).
  - Nejdřív se ověří heslo a ukáže obsah zálohy.
  - Před obnovením se současný stav zazálohuje (`-pred-obnovou-soubory`).
  - Soubory galerie se doplní ze zálohy, z jiné zálohy se soubory nebo ze serveru a ověří se podle md5.
  - Záloha ze starší verze se po obnovení převede migrací. Zálohu z novější verze aplikace obnovit nejde.
- `config.php` v záloze není. Uložte si ho zvlášť.
- ZIP s AES heslem otevřete např. programem 7-Zip. Průzkumník Windows ho neotevře.

## 📅 Výběr podle data

- Galerie → **+ 📅 Podle data**: do výběru přidáte obrázky, slidy, videa nebo PDF a ke každé položce jeden nebo více dní.
  - Když název souboru obsahuje datum (`2026-10-24`, `2026_10_24`, `20261024`, `24.10.2026`, `24-10-2026`, `261024`), předvyplní se.
- Výběr vložíte do scénáře jako jedno médium. V daný den se zobrazí všechny položky s tímto dnem (celý den, v čase podle scénáře). V ostatní dny výběr nedodá nic.
- **Scénář, který by v danou chvíli nezobrazil nic, se bere jako neběžící.** Když neběží jiný plánovaný scénář, nastoupí výchozí. Přehled ukazuje takové scénáře jako „teď bez obsahu“.
- Položka bez vlastní skupiny přebírá skupinu výběru.
- Přehled upozorní, když používaný výběr nemá žádné další datum.
- Data jsou v tabulce `dated_items (pool_id, media_id, day)` (`SCHEMA_VERSION = 9`).

## 🔐 Přihlášení: heslo, Google, Passkey
- Heslo je vždy záloha. Google a Passkey se **přidávají**, nic se nevypíná. Správa je v **Nastavení → Zabezpečení** (`#nastaveni/zabezpeceni`). Logika je v `lib/auth.php`, přihlašovací stránka v `index.php`.
- **Omezení pokusů** je společné pro heslo, obnovu hesla, Google i Passkey: po 5 chybách zámek na 5 minut (`login_fails`, `login_last_fail`). Úspěšné přihlášení a zámek se zapisují do Protokolu.
- **Zapomenuté heslo:** odkaz `/?reset=<token>` se posílá jen na adresu z Nastavení → Upozornění (`notify_email`). Token má 32 bajtů, v databázi (`auth_tokens`) je jen jeho SHA-256, platí 30 minut a jen jednou. Nejvýš 1 žádost za 2 minuty. Odpověď je vždy stejná. Odkaz se skládá z `base_url`, ne z hlavičky Host.
- **Google:** `login-google.php` (OpenID Connect, rozsah `openid email`, `state` a `nonce` v relaci). Používá `google_client_id` a `google_client_secret` jako Google Disk. V Google Cloud Console je potřeba přidat druhou adresu pro přesměrování `<base_url>/login-google.php`. Povolený je jediný účet, ukládá se jeho `sub` (`login_google_sub`). Propojuje se jen z přihlášené relace (`?link=1`).
- **Passkey (WebAuthn):** bez knihoven, CBOR a ověření podpisu (ES256, RS256) přes OpenSSL, attestation `none`. Vyžaduje HTTPS (nebo localhost), ověření uživatele (otisk, obličej, PIN) a jednorázovou výzvu (5 minut, tabulka `auth_tokens`). `rp.id` je hostitel z `base_url`. Kontroluje se `origin`, `rpIdHash`, příznaky, podpis a čítač (nesmí klesnout). Veřejný vstup `passkey.php`, správa zařízení v `api.php` (`passkeys`, tabulka `passkeys`).
- **Odhlášení po nečinnosti:** `idle_limit_seconds()` a `is_logged_in()` v `lib/bootstrap.php`. Čas poslední aktivity je v relaci (`auth_last`), nastavení `idle_minutes` (výchozí 60, 0 = nikdy, 5 až 1440) je v Nastavení → Zabezpečení. Požadavek s hlavičkou `X-Passive: 1` (automatické obnovování Přehledu a Protokolu) čas neobnovuje. Akce `session` vrací zbývající čas, `idle_save` ukládá nastavení. Klient `assets/idle.js` 2 minuty předem ukáže okno „Zůstat přihlášen“ a při práci bez požadavků občas pošle `session`. Po vypršení je v relaci hláška pro přihlašovací stránku a záznam v Protokolu. Testovací limit v sekundách: `idle_seconds_test` (jen s `drive_mock`).
- **Zálohy:** tabulky `passkeys` a `auth_tokens` se nezálohují ani při obnovení nemažou. Nastavení (heslo, propojený Google účet) se obnoví.
- Testy: `tests/unit/auth_test.php`, `tests/e2e/prihlaseni.test.mjs` (Passkey přes virtuální zařízení Chromia). Testovací server běží na `localhost`, protože Passkey nepřijme IP adresu jako `rp.id`.

## 📱 Telefon
- Do šířky 760 px: horní pruh `.topbar` (☰, název obrazovky `#tbTitle`), levý panel `.sidebar` se vysouvá (`body.menu-open`, zavření výběrem, klepnutím vedle, ✕, Esc). Nad 760 px beze změny.
- Galerie: přidávací dlaždice 1:1 (`ADD_TILES`, `TILE_ICONS` v app.js; první barevná `#upTile` „Nahrát soubor“ otevře `#fileIn`), popis v bublině `.tip` jen při najetí myší (`@media (hover: hover)`) nebo fokusu klávesnicí; na telefonu 4 sloupce, bez bubliny a bez plochy pro přetažení. Filtry v posuvných řádcích. Lišta `.save-bar` (editor scénáře a slidu) je přilepená dole.
- Okno média (`editMedia`): část „Scénáře“ s akcí API `scenario_add_media` (jen POST, idempotentní, zápis do Protokolu).
- Změna adresy (`hashchange`, i tlačítko Zpět) zavře otevřené okno; `route()` volaný po akcích okno nezavírá. `route()` nevykreslí stránku, pokud se mezitím přešlo jinam (`routeSeq`).
- Test `tests/e2e/mobil.test.mjs`.

## 📖 Nápověda
- Nápověda pro běžné uživatele je v aplikaci pod **Nápověda** (`#napoveda`). Texty jsou Markdown soubory `help/<sekce>/<id>.md`, pořadí a sekce určuje `help/_obsah.json` (včetně mapy `kontext` pro tlačítka „?“). Složka `help/` je z internetu zablokovaná (`.htaccess`), stránky se čtou přes `api.php?a=help` (jen pro přihlášené).
- Hlavička souboru: `titulek`, `sekce`, `admin` (ano = návod pro správce, 🔧), `kontext`, `souvisi` (ID stránek, čárkou), `klicova` (slova pro hledání).
- Odkaz na jinou stránku: `[text](id-stranky)`. Značka `[SNÍMEK: popis]` vykreslí rámeček, kam se později doplní snímek obrazovky. Značka `[OVĚŘIT: …]` nesmí zůstat ve vydané verzi (hlídá test).
- Tlačítko „?“ v aplikaci: `helpBtn('@kontext')` nebo `helpBtn('id-stranky', true)` (nová záložka – editory a okna). Kontrolu obsahu dělá `help_check()` v `lib/help.php` (spouští ji test `help`).

## 🩺 Diagnostika

Nastavení → Systém → **Spustit kontrolu**. Ověří a značkou ✓ / ! / ✕ označí:

- PHP (verze, rozšíření, GD s písmy a zkušebním textem, paměť pro velké obrázky, časové pásmo).
- Úložiště: zápis do `data/`, místo na disku. Ověří také, že `data/` a `lib/` nejsou přístupné z internetu (požadavek na vlastní adresu z `base_url`).
- Databázi a verzi dat, chybějící soubory galerie.
- Cron a poslední synchronizaci.
- Google Disk: připojení, práva ke cílové složce, soubory jiného účtu. **Jen čtení – na Disk nic nenahrává.**
- Zálohy: podpora AES, zkušební šifrovaný ZIP v `data/tmp` (hned se smaže), heslo, stáří poslední zálohy.
- Synology: kdy se skript naposledy ozval, zda byla doručena poslední změna, stahování záloh.
- Zdroje dat a nastavení upozornění.

Tlačítko **Kopírovat výsledek** zkopíruje výsledek jako text (např. pro podporu).

## 🖥️ Zařízení pro přehrávání (plugin pro Chrome)
- **Nastavení → Zařízení:** seznam zařízení (název, typ chrome/android/tizen, vlastní délka zobrazení, kdy se ozvalo), **+ Přidat zařízení**, **Nový klíč**, **Odebrat**, výchozí délka zobrazení (20 s, nastavení `device_duration`) a **Stáhnout plugin pro Chrome** (`api.php?a=extension_zip` zabalí `extensions/chrome/`; složka `extensions/` je z internetu zablokovaná).
- Klíč `dev_…` se ukáže jen při vytvoření, v tabulce `devices` je jen SHA-256 (`lib/devices.php`, schéma 12). Zálohy tabulku neobsahují a obnovení ji nemění.
- **`now.php`** (jen čtení, klíč v hlavičce `X-Device-Key` nebo `?key=`, verze klienta v `X-Client`, CORS `*`):
  - bez parametrů → JSON `{ok, api:1, device:{id,name,kind,duration}, refresh_minutes, playlist:{mode,active,fixed}, files:[{id,name,kind,mime,md5,size}], list_at, stale, warning}`; `kind` = image/video/pdf/text/url;
  - `?file=<ID>` → obsah souboru z Google Disku (po kusech, `drive_stream`). Jen soubory, které jsou právě ve složce přehrávače.
  - Seznam ze složky se drží 60 s (`device_files_cache`); když Disk neodpoví, vrátí se poslední známý se `stale: true`.
  - 20 špatných klíčů z jedné IP za 15 min → 429 na 15 min (`device_fails`, zvlášť od přihlašování).
- **Plugin** (`extensions/chrome/`, Manifest V3, vlastní verze v `manifest.json`): `bg.js` každých 5 min obnoví seznam, stáhne chybějící soubory do Cache Storage (klíč = md5), uklidí odebrané a nastaví odznak (počet / „!“ / „?“). `popup.html` náhled a stav, `options.html` adresa + klíč, `player.html` náhodné pořadí bez opakování, obrázky/PDF (1. stránka, pdf.js)/texty na nastavenou dobu, videa celá se zvukem, odkazy se přeskočí.
- **Aplikace pro TV Samsung** (`extensions/tizen/`, webová aplikace Tizen 4.0+, verze v `config.xml`; projekt pro Tizen Studio vč. `.project`/`.tproject`): `api.php?a=extension_zip&kind=tizen` do zipu vloží `config.js` s `base_url`. `app.js` je psaný v ES2017 bez modulů (kontroluje test `tv` přes acorn). Párování: `pair.php?a=start` (kód 6 číslic + tajný token, 10 min, 10 žádostí/h/IP) → v aplikaci `device_pair {code, name, duration}` (5 špatných kódů = 15 min blokace) → `pair.php?a=poll&t=` vydá klíč jednou (tabulka `device_pairing`, schéma 13). Soubory v IndexedDB (klíč md5), co se nevejde → přehrání přímo z `now.php?file=…&key=…`. Při 403 z `now.php` se televize odpáruje a ukáže nový kód. Ovladač: OK/Přehrát-Pauza, →, zelené B, Zpět (stav), podržet Zpět (nastavení). Spořič vypíná `webapis.appcommon.setScreenSaver`.
- **Aplikace pro Android TV** (`extensions/android/`, Gradle projekt, AGP 8.5.2, Gradle wrapper 8.9, minSdk 21, verze v `app/build.gradle`): `MainActivity` = celoobrazovkový WebView (`WebViewAssetLoader`, `https://appassets.androidplatform.net/assets/www/index.html`), `FLAG_KEEP_SCREEN_ON`, přehrávání bez gesta, tlačítka Zpět (krátce/podržet 1 s), Přehrát/Pauza, zelené a další posílá do `window.twbTv.key()`, `window.TwbAndroid.version()`. Dlaždice Android TV `drawable-xhdpi/banner.png`. Podpis vlastním klíčem `app/twb-prehravac.jks` (repozitář jen soukromý). `extension_zip('android')` vloží do `app/src/main/assets/www/` přehrávač z `extensions/tizen/` (bez `config.xml`, `.project`, `.tproject`) a `config.js` s `base_url`. GitHub: soukromé repo **Morous70/twb-prehravac-android**, workflow `.github/workflows/build.yml` sestaví `assembleRelease`, APK dá do Releases `v<verze>` a výsledek + konec výpisu zapíše do větve `ci-log` (`status.txt`).
- **Ovládání a stav (schéma 15, přehrávače 1.1.0):** zařízení každých 15 s volá `POST now.php?ping=1` se stavem (co hraje a od kdy, soubory/staženo, verze, poslední chyba) a dostane jednou čekající příkaz (`refresh`, `restart`, `next`; zadává `api.php?a=device_cmd`, propadne po 120 s) a číslo změny složky (`last_change_at` ze synchronizace) – při změně si seznam obnoví samo. Zařízení, které se 15 min neozvalo, hlásí `api.php?a=devices_alert` (červený pruh v aplikaci).
- Párování ví, kdo o kód žádá: `pair.php?a=start&kind=tizen|android` → `device_pairing.kind` (schéma 14) → typ zařízení.

## ✨ Grafický text a písma
- Prvek Text má `spacing` (rozestup písmen v em, −0,2…0,5; při ≠ 0 se kreslí po znacích, šířka znaku = `char_adv` „HxH“ − „HH“, totéž `charAdv` v editoru) a `fx` (grafický styl, null = obyčejný text): `{style, grad[4], stroke, strokeColor, stroke2, stroke2Color, depth, depthColor, glow, glowColor, shadow, gloss}`. Tloušťky/hloubka jsou násobky velikosti písma.
- Hotové styly `FX_STYLES` v `lib/slide.php` (editor je čte přes `api.php?a=fx_styles`), přechod v bodech `FX_STOPS` [0; 0,38; 0,62; 1] od výšky velkých písmen (účaří − 0,72 em) po účaří.
- Vykreslení `draw_text_fx`: záře (rozmazaná ztučnělá maska), stín, 3D hloubka (obrys posunutý dolů, tmavne k 55 % černé), obrys, druhý obrys, výplň přechodem s leskem v horních 48 %. Počítá se jen v oblasti textu. Editor kreslí totéž na plátně (`drawTextFx`, `ctx.filter = blur()`); test porovnává náhled se serverem (průměrný rozdíl < 14 z 255).
- Písma: vestavěná `FONTS` (+ Archivo Black, Titan One, Anton), vlastní v `data/fonts/` se seznamem v nastavení `custom_fonts` (`lib/fonts.php`, klíče `u_…`). API `fonts`, `font` (soubor písma), `fonts_custom`, `font_upload`, `font_rename`, `font_delete` (použité ve slidu nejde smazat). Zálohy: `font_files` v `database.json` + `fonts/…` v zipu, obnovení je vrátí.

## Automatické testy

Složka `tests/` slouží jen pro vývoj. Na serveru je zablokovaná a nic nedělá, při aktualizaci ji nahrávat nemusíte. Spuštění: `php tests/run.php` (podrobnosti v `tests/README.md`).

## Aktualizace (TWB Update Server)

- Slug aplikace `media-planner`, režim `authenticated`. Token je v `config.php` (`update_token`), v aplikaci se ukazuje jen zamaskovaný. Veřejný klíč serveru je napevno v `lib/update.php`.
- Kontrola: cron jednou za 24 h a tlačítko **Zjistit novou verzi**. Odpověď `latest.php` musí mít platný podpis Ed25519 přes `payload_b64`.
- Instalace jen po kliknutí:
  1. Stažení a kontrola SHA-256 a velikosti.
  2. Rozbalení do `data/update/staging` (odmítne cesty mimo `media-planner/`, `..` i odkazy).
  3. Záloha databáze (`-pred-aktualizaci`) a kopie současných souborů do `data/update/previous`.
  4. Přepsání všeho kromě `config.php` a `data/`.
  5. Kontrola `health.php` (načte všechny části aplikace a převede databázi).
  6. Při chybě automatický návrat.
- Aktualizace smaže jen soubory, které nainstalovala předchozí aktualizace (`data/update/installed-files.json`). Cizí soubory jako `.user.ini` zůstanou.
- Potřebuje nastavené heslo záloh a právo PHP zapisovat do souborů aplikace (ověří Diagnostika).
