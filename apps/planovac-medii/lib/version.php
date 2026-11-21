<?php
// Název a verze aplikace. Verze: hlavní.vedlejší.oprava (oprava = jen chyby, vedlejší = nové funkce, hlavní = velká změna).
// Číslo verze je jen v souboru VERSION v kořeni aplikace (čte ho i TWB Update Server).

const APP_NAME = 'TWB Plánovač médií';
const APP_SHORT_NAME = 'TWB Plánovač';
define('APP_VERSION', trim((string)@file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '0.0.0');

/** Novinky po verzích (nejnovější nahoře). */
const APP_CHANGELOG = [
    '1.20.0' => [
        'date' => '2026-10-22',
        'items' => [
            'Počasí ve slidech: nový prvek Počasí v editoru slidu se třemi vzhledy (Ikona + teplota, Karta s detaily, Předpověď na 3 dny) a místem; proměnné {Počasí:teplota}, {Počasí:zítra.max}, {Počasí:Brno.popis} a další do textů, podmínek i HTML dokumentů.',
            'Nastavení → Počasí: služba (OpenWeather, další lze doplnit), API klíč, výchozí místo, tlačítko Uložit a vyzkoušet a tabulka stažených míst.',
            'Počasí se stahuje každých 15 minut jen pro použitá místa; při výpadku slidy ukazují poslední stažená data a do Protokolu jde jedno varování.',
            'Karty Nastavení se při malé šířce okna zalamují, žádná se neschová.',
        ],
    ],
    '1.19.0' => [
        'date' => '2026-10-22',
        'items' => [
            'Zvětšení náhledu: kliknutím na miniaturu v Přehledu (Právě teď), v seznamu Scénářů a v editoru scénáře (Ve scénáři) se otevře zvětšená ukázka přes celou šířku okna. V galerii pro výběr médií v editoru scénáře je na náhledu ikona lupy, karta se tím neoznačí.',
            'Ve zvětšené ukázce jde listovat šipkami ← → (i klávesami šipek, na telefonu tažením prstem do strany) – mezi médii, která právě běží, médii scénáře nebo zobrazenými médii v Galerii.',
        ],
    ],
    '1.18.0' => [
        'date' => '2026-10-22',
        'items' => [
            'Složky v galerii nahradily tagy. Každá složka se při aktualizaci změnila na tag se stejným názvem, takže se nic neztratilo. Média mohou mít víc tagů najednou.',
            'Nahoře v Galerii je řada tagů: Vše, Bez tagu, tagy s počtem médií, přepínač všechny / kterýkoli a vpravo Archiv. Na telefonu se řada posouvá prstem do strany.',
            'Rychlý filtr: klik na tag na kartě média ukáže jen média s tímto tagem, druhý klik filtr zruší.',
            'Přetažením média na tag v řadě mu tag přidáte. Když je tag vybraný, nově nahrané soubory i nová média z dlaždic ho dostanou rovnou.',
            'V editoru scénáře, náhodném výběru a výběru podle data je místo složek stejná řada tagů. Do scénáře přetažením tagu přidáte všechna jeho média; s jedním vybraným tagem totéž udělá tlačítko + Přidat vše s tagem.',
            'Tagy se přejmenovávají a mažou v Nastavení → Tagy. Pole Složka z oken Upravit a z editorů zmizelo.',
        ],
    ],
    '1.17.0' => [
        'date' => '2026-10-21',
        'items' => [
            'Tagy médií: štítky pro třídění a hledání (např. Vánoce, Sportka). Přidělíte je v okně Upravit (Enter nebo čárka tag potvrdí, nabízejí se už použité tagy), v editoru slidu a HTML dokumentu tlačítkem 🏷 Tagy, nebo hromadně v liště Vybráno … Médium může mít nejvýš 10 tagů po 30 znacích.',
            'Na kartě média jsou vidět nejvýš 3 tagy, ostatní jako „+2“. V Galerii je filtr 🏷 Tagy s volbou všechny / kterýkoli, pole Hledat… hledá i v tazích. Stejný filtr je i při výběru médií do scénáře, náhodného výběru, výběru podle data a obrázku do slidu.',
            'Nastavení → Tagy: přehled tagů s počtem médií, přejmenování (na existující tag se sloučí) a smazání u všech médií najednou.',
            'Tagy zůstávají i v archivu a jsou součástí zálohy. Na to, co se promítá, nemají vliv (na rozdíl od Skupiny).',
        ],
    ],
    '1.16.1' => [
        'date' => '2026-10-21',
        'items' => [
            'Oprava: když Google Disk vrátí dočasnou chybu (např. „500 Internal Error“, 503, překročený limit), aplikace to po chvilce zkusí znovu (až 4×). Synchronizace se kvůli krátkému výpadku Googlu už nezastaví. Když se to nepovede ani tak, hláška řekne, že jde o dočasnou chybu Googlu a synchronizace se zopakuje sama.',
        ],
    ],
    '1.16.0' => [
        'date' => '2026-10-19',
        'items' => [
            'HTML dokumenty v galerii: nová dlaždice HTML otevře editor, ve kterém dokument napíšete (písmo, velikost, barvy, zarovnání, seznamy, tabulky, obrázky, odkazy). Hotový soubor .html můžete také nahrát nebo jím obsah nahradit.',
            'Editor má dva režimy: Vizuálně (jako textový editor) a Kód (celý zdroj včetně stylů a JavaScriptu). Plocha dokumentu je 1920 × 1080 jako obrazovka. Tlačítko Náhled ukáže dokument tak, jak ho uvidí přehrávač.',
            'Do dokumentu jde vložit hodnotu z Dat a zdrojů (Vložit hodnotu, Ze zdroje…) – sama se obnovuje jako u slidů –, obrázek z galerie (vloží se přímo do dokumentu) i obrázek z internetu. Písma aplikace se do dokumentu přidají sama.',
            'Při uložení aplikace upozorní, když dokument načítá soubory z internetu (bez připojení se nezobrazí).',
            'Přehrávače 1.2.0 (plugin pro Chrome, TV Samsung, Android TV) zobrazí HTML dokument na celou obrazovku, odděleně od přehrávače (bez přístupu k jeho datům). Starší verze HTML dokumenty přeskočí a hrají dál ostatní média.',
        ],
    ],
    '1.15.1' => [
        'date' => '2026-10-19',
        'items' => [
            'Oprava: u karty média v galerii nebylo kvůli novému tlačítku Archiv vidět tlačítko Smazat. Karta má nyní tři malé nenápadné ikony (Upravit, Do archivu, Smazat) v jedné řadě; název se ukáže po najetí myší. Karta je zase nižší.',
        ],
    ],
    '1.15.0' => [
        'date' => '2026-10-19',
        'items' => [
            'Archiv v galerii: médium můžete dát do archivu tlačítkem Archiv (i hromadně). Přestane se nabízet při přidávání do scénářů, výběrů a slidů, ale dál hraje všude, kde už je použité. Hodí se třeba pro plakáty k akcím, které už proběhly.',
            'Archiv otevřete vpravo v pruhu složek (🗄 Archiv, s počtem a velikostí). U každého média je vidět, kde se ještě používá. Z archivu jde médium vrátit do původní složky nebo trvale smazat.',
            'Oprava: záře kolem velkého grafického textu (např. „−20 %“ ve velikosti 400) už nemá hranatý, čtverečkovaný okraj.',
        ],
    ],
    '1.14.0' => [
        'date' => '2026-10-19',
        'items' => [
            'Ovládání zařízení z aplikace: v Nastavení → Zařízení jsou u každého přehrávače tlačítka Obnovit teď, Restartovat přehrávač a Další soubor. Zařízení zareaguje do 15 s.',
            'Stav zařízení: co právě hraje a od kdy, kolik souborů má stažených, verze přehrávače a poslední chyba. Stav se na kartě sám obnovuje.',
            'Když se změní obsah složky přehrávače, zařízení si seznam obnoví samo do 15 s (ne až za 5 minut).',
            'Upozornění: když se zařízení 15 minut neozve, ukáže se nahoře v aplikaci červený pruh. Zmizí, jakmile se zařízení znovu ozve.',
            'Nové verze přehrávačů 1.1.0 (plugin pro Chrome, TV Samsung, Android TV). Starší verze přehrávají dál, jen bez ovládání a hlášení stavu.',
        ],
    ],
    '1.13.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Grafický text ve slidu: 11 hotových stylů (Zlato, Stříbro, Červená, Modrá, Zelená, Bílá se stínem a pět stylů pro světlé pozadí) se září, stínem, 3D hloubkou, obrysy, výplní přechodem a leskem. Styl jde doladit (barvy přechodu, obrysy, hloubka, záře, stín, lesk) a vrátit. Funguje i s proměnnými.',
            'Rozestup písmen u textu: Velmi úzký, Užší, Normální, Širší, Široký. Zalamování a zmenšení textu s ním počítá.',
            'Nová písma Archivo Black, Titan One a Anton.',
            'Vlastní písma: Nastavení → Obrázky → Písma pro slidy – nahrání TTF/OTF, kontrola českých znaků, přejmenování, odebrání (jen nepoužitého). Písma jsou v zálohách a aktualizace je nepřepíše.',
            'Nápověda: nové stránky Jak udělat grafický text a Jak přidat vlastní písmo.',
        ],
    ],
    '1.12.1' => [
        'date' => '2026-10-18',
        'items' => [
            'Oprava: u slidu nešlo nastavit Skupinu. Editor slidu má v horní liště pole Skupina (s nabídkou existujících skupin); skupina se uloží se slidem a při další úpravě zůstane.',
        ],
    ],
    '1.12.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Aplikace TWB přehrávač pro Android TV / Google TV: stejný přehrávač, ovládání i párování kódem jako u TV Samsung. Obrazovka během přehrávání nezhasne.',
            'Instalační soubor (APK) se sestavuje sám na GitHubu v soukromém repozitáři twb-prehravac-android a je ke stažení v Releases. Nová verze se nainstaluje přes starou.',
            'Spárovat televizi rozpozná, jestli jde o TV Samsung, nebo Android TV, a podle toho nastaví typ zařízení.',
            'Nastavení → Zařízení: tlačítko Stáhnout aplikaci pro Android TV (projekt pro GitHub nebo Android Studio). Nápověda: nová stránka Jak nainstalovat aplikaci na Android TV.',
        ],
    ],
    '1.11.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Aplikace pro TV Samsung (TWB přehrávač): přehrává na televizi stejná média jako obrazovka v trafice, ukládá si je v televizi a funguje i bez internetu. Co se do televize nevejde, přehraje přímo ze serveru.',
            'Párování kódem: televize ukáže šestimístný kód, v Nastavení → Zařízení ho zadáte tlačítkem Spárovat televizi. Klíč se nikam neopisuje. Po odebrání zařízení nebo novém klíči televize ukáže nový kód.',
            'Ovladač: OK nebo Přehrát/Pauza = pauza, šipka vpravo = další, zelené B = obnovit, Zpět = okno se stavem, podržet Zpět = nastavení. Při chybě je vpravo dole malá ikonka „!“ s časem posledního obnovení. Spořič obrazovky je vypnutý.',
            'Nastavení → Zařízení: tlačítka Spárovat televizi a Stáhnout aplikaci pro TV Samsung (projekt pro Tizen Studio s předvyplněnou adresou aplikace).',
            'Nápověda: nová stránka Jak nainstalovat aplikaci na TV Samsung, doplněné Nastavení: Zařízení a Slovníček.',
        ],
    ],
    '1.10.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Plugin pro Chrome: přehrává v prohlížeči stejná média jako obrazovka v trafice. Soubory si stahuje ze složky přehrávače na Google Disku, střídá je v náhodném pořadí a funguje i bez internetu s posledním staženým obsahem.',
            'Obrázky, slidy, PDF (první stránka) a texty se zobrazí na nastavenou dobu (výchozí 20 s), videa celá se zvukem, odkazy se přeskočí. Seznam se obnovuje každých 5 minut nebo tlačítkem Obnovit.',
            'Okénko po kliknutí na ikonu: náhled, co běží, počet médií, čas obnovení a tlačítko Přehrávat na celé obrazovce. Číslo na ikoně ukazuje počet médií, červený „!“ chybu.',
            'Nová karta Nastavení → Zařízení: přidání zařízení s vlastním klíčem jen pro čtení, nový klíč, odebrání, výchozí i vlastní délka zobrazení a stažení pluginu.',
            'Nápověda: nové stránky Jak nainstalovat plugin pro Chrome a Nastavení: Zařízení.',
        ],
    ],
    '1.9.2' => [
        'date' => '2026-10-18',
        'items' => [
            'Galerie: přidávací tlačítka jsou čtvercové dlaždice s ikonou (Nahrát soubor, Slide, Běžící text, Odkaz na web, Náhodný výběr, Podle data). Na počítači se po najetí myší ukáže popis.',
            'Nahrát soubor je samostatná barevná dlaždice; na telefonu nahradila tlačítko „Nahrát plakát“. Na počítači zůstává i plocha pro přetažení.',
            'Nápověda: názvy tlačítek v návodech podle nových dlaždic.',
        ],
    ],
    '1.9.1' => [
        'date' => '2026-10-18',
        'items' => [
            'Přehled na telefonu: obrázky (Právě teď i podrobnosti z týdenního náhledu) dva vedle sebe jako v Galerii.',
            'Oprava: když jste během nahrávání odešli z Galerie, nahrávání skončilo chybou a mohlo překreslit stránku, na které jste byli (např. rozpracovaný scénář).',
        ],
    ],
    '1.9.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Telefon: nahoře úzký pruh s názvem obrazovky a tlačítkem ☰, menu se vysouvá zleva. Obsah začíná hned nahoře.',
            'Galerie na telefonu: tlačítko „📷 Nahrát plakát“ (vyfotit nebo vybrat z fotek), složky a filtry v posuvných řádcích.',
            'Okno Upravit u obrázku, videa a PDF: část Scénáře – kde je médium použité a tlačítko „Přidat do scénáře“ (i na počítači).',
            'Telefon: Zrušit/Uložit v editoru scénáře a slidu přilepené dole, karty Nastavení se zalamují, tlačítko Zpět zavře otevřené okno.',
            'Opravy: rychlé přepínání stránek mohlo vykreslit předchozí stránku; editor scénáře na telefonu přetékal do strany; po aktualizaci se soubory PHP mažou z mezipaměti OPcache.',
        ],
    ],
    '1.8.1' => [
        'date' => '2026-10-18',
        'items' => [
            'Odhlášení po nečinnosti: po nastavené době bez práce (výchozí 60 minut, 0 = nikdy) aplikace odhlásí. Nastavení je v Nastavení → Zabezpečení.',
            'Dvě minuty předem se ukáže okno „Brzy budete odhlášeni“ s tlačítkem „Zůstat přihlášen“. Psaní v editoru relaci udrží, automatické obnovování Přehledu a Protokolu se za práci nepočítá.',
            'Po odhlášení přihlašovací stránka vysvětlí důvod a do Protokolu se zapíše záznam. Nápověda doplněna.',
        ],
    ],
    '1.8.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Zapomenuté heslo: na přihlašovací stránce odkaz „Zapomenuté heslo“. Jednorázový odkaz (platí 30 minut) přijde na e-mail z Nastavení → Upozornění.',
            'Přihlášení přes Google: smí se přihlásit jen jeden propojený účet. Propojení je v Nastavení → Zabezpečení. V Google je potřeba přidat adresu pro přesměrování login-google.php.',
            'Přihlášení pomocí Passkey (otisk, obličej, PIN nebo bezpečnostní klíč): zařízení se přidávají, přejmenovávají a odebírají v Nastavení → Zabezpečení. Heslo funguje vždy.',
            'Nová karta Nastavení → Zabezpečení (sem se přesunula změna hesla). Omezení pokusů (5 chyb, pauza 5 minut) platí pro všechny způsoby přihlášení. Přihlášení a změny zabezpečení se zapisují do Protokolu.',
            'Nápověda: nové stránky o Passkey, přihlášení přes Google, kartě Zabezpečení a hláškách přihlášení; přepsaná stránka Zapomenuté heslo.',
        ],
    ],
    '1.7.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Nápověda pro běžné uživatele přímo v aplikaci (položka „Nápověda“ v menu): 62 stránek – začínáme, návody krok za krokem, popis obrazovek, nastavení, řešení problémů, časté dotazy, slovníček a kontakt.',
            'Vyhledávání v nápovědě (bez ohledu na diakritiku a skloňování), obsah vlevo, související stránky a odkazy „předchozí/další“.',
            'Tlačítka „?“ u nadpisů Přehledu, Scénářů, Galerie, Dat, Protokolu, karet Nastavení a v editorech a oknech vedou přímo na příslušnou stránku nápovědy.',
            'Texty jsou soubory ve složce help/ (Markdown); návody pro správce jsou označené 🔧. Snímky obrazovky se doplní v dalších verzích.',
        ],
    ],
    '1.6.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Aktualizace z TWB Update Serveru: Nastavení → Systém → Aktualizace. Aplikace se ptá jednou denně (cron), kdykoli i tlačítkem „Zjistit novou verzi“; v Přehledu se objeví upozornění.',
            'Instaluje se jen po kliknutí: podepsaný balíček (Ed25519) se ověří kontrolním součtem, před instalací se zazálohuje databáze a uloží současná verze; když nová verze nefunguje, vrátí se předchozí sama.',
            'Tlačítko „Vrátit předchozí verzi“ (poslední uložená verze; databáze se vrátí ze zálohy, jen když ji nová verze změnila).',
            'Diagnostika ověří zápis do souborů aplikace a stav Update Serveru.',
        ],
    ],
    '1.5.2' => [
        'date' => '2026-10-18',
        'items' => [
            'Automatické testy (složka tests/): plánovač, výběr podle data, formáty, zdroje CSV/JSON, komprese, zálohy, synchronizace, diagnostika, přihlášení a testy v prohlížeči. Na serveru se nespouštějí.',
            'Oprava: vlastní formát data jen „DEN“ (den v týdnu) se zaměnil s pojmenovaným formátem „den“ (den v týdnu s datem).',
            'Diagnostika radí, co dělat se zdrojem, který se ještě nestáhl.',
        ],
    ],
    '1.5.1' => [
        'date' => '2026-10-18',
        'items' => [
            'Diagnostika: na hostingu, který nedovolí zjistit volné místo (Wedos), se místo chyby zobrazí jen velikost galerie a záloh.',
            'Diagnostika: správné tvary čísel (2 soubory, 5 souborů).',
        ],
    ],
    '1.5.0' => [
        'date' => '2026-10-18',
        'items' => [
            'Diagnostika v Nastavení → Systém: jedním tlačítkem ověří PHP a jeho rozšíření, písma, paměť pro velké obrázky, místo na disku, databázi a soubory galerie.',
            'Kontroluje i Google Disk (práva ke složce, soubory jiného účtu), cron, zálohy včetně zkušebního šifrovaného ZIP, spojení se Synology, zdroje dat a upozornění.',
            'Každá položka má zelenou, oranžovou nebo červenou značku a radu, co dělat; výsledek jde zkopírovat jako text. Na Google Disk diagnostika nic nezapisuje.',
            'Ověří také, že složky data/ a lib/ nejsou přístupné z internetu.',
        ],
    ],
    '1.4.1' => [
        'date' => '2026-10-17',
        'items' => [
            'Oprava: ve výběru podle data se ručně zadané datum uložilo s rokem 2 (editor ho převzal už po první číslici roku). Den se nově přidává tlačítkem „+ Přidat“ nebo klávesou Enter.',
            'Data s chybným rokem se při aktualizaci samy opraví na aktuální rok; rok mimo 2000–2099 aplikace odmítne.',
            'Datum se předvyplní i z názvu ve tvaru RRMMDD, např. er_261022_01.jpg → 22. 10. 2026.',
        ],
    ],
    '1.4.0' => [
        'date' => '2026-10-17',
        'items' => [
            'Nový typ položky galerie 📅 Výběr podle data: obrázky, slidy, videa i PDF se zobrazí jen ve své dny – stačí jeden scénář.',
            'Jedna položka může mít více dní, na jeden den může připadnout více položek; datum se předvyplní z názvu souboru, pokud ho obsahuje.',
            'Scénář, který by v danou chvíli nezobrazil nic (např. výběr bez položky na dnešek), se bere jako neběžící – nastoupí výchozí scénář.',
            'Přehled upozorní, když používaný výběr podle data nemá žádné další datum.',
        ],
    ],
    '1.3.0' => [
        'date' => '2026-10-17',
        'items' => [
            'Zálohy: každý den ve 3:00 šifrovaný ZIP (AES-256) s celou databází; soubory galerie jen když se změnily.',
            'Na serveru zůstává posledních 7 záloh a vždy poslední záloha se soubory; skript na Synology si zálohy stahuje a maže starší než 90 dní.',
            'Nová karta Nastavení → Zálohy: heslo, Zálohovat teď, stažení, obnovení ze seznamu i ze souboru (před obnovením se současný stav zazálohuje).',
        ],
    ],
    '1.2.0' => [
        'date' => '2026-10-17',
        'items' => [
            'Vzdálené zdroje ve formátu CSV – např. ceník z tabulky Google (stačí běžný odkaz) nebo soubor CSV z webu.',
            'Hodnota z CSV ve slidu: {Ceník:Káva.Cena} – řádek podle prvního sloupce, sloupec podle záhlaví; nebo podle čísla řádku {Ceník:3.Cena}.',
            'Oddělovač i kódování CSV (UTF-8, Windows-1250 z Excelu) se rozpoznají automaticky.',
        ],
    ],
    '1.1.0' => [
        'date' => '2026-10-17',
        'items' => [
            'Instalace jako aplikace na plochu počítače i telefonu (tlačítko „Instalovat aplikaci“ v levém panelu, na iPhonu přes Sdílet → Přidat na plochu).',
            'Nainstalovaná aplikace se otevírá ve vlastním okně bez lišty prohlížeče a má zkratky Přehled, Galerie a Scénáře.',
            'Při výpadku internetu se místo chyby prohlížeče zobrazí stránka „Jste offline“.',
        ],
    ],
    '1.0.0' => [
        'date' => '2026-10-17',
        'items' => [
            'První číslovaná verze – nový název TWB Plánovač médií, ikona a favicon.',
            'Nastavení rozdělené na karty, každá s vlastním tlačítkem Uložit.',
            'Přepínač vzhledu: automaticky / světlý / tmavý.',
            'Komprese obrázků při nahrání (nastavitelná), originál zůstává uchovaný a jde vrátit; hromadná komprese v galerii.',
            'Nahrazení souboru u obrázku, videa i PDF – položka zůstane ve všech scénářích.',
            'Po změně ve složce spustí skript na Synology synchronizaci přehrávače SlideShow (do minuty místo hodiny).',
            'Pevný scénář – zobrazuje se ve všech scénářích, v konkrétním scénáři ho lze vypnout.',
            'Formát data a formát čísla v textech slidů (dialogy s ukázkami, vlastní formát).',
        ],
    ],
];

/** Společné prvky hlavičky stránek: favicony, manifest a vzhled (bez probliknutí). */
function page_head(string $title): void {
    $t = htmlspecialchars($title, ENT_QUOTES);
    $v = rawurlencode(APP_VERSION);
    echo <<<HTML
  <title>$t</title>
  <link rel="icon" href="assets/icons/icon.svg?v=$v" type="image/svg+xml">
  <link rel="icon" href="assets/icons/favicon-32.png?v=$v" sizes="32x32" type="image/png">
  <link rel="alternate icon" href="assets/icons/favicon.ico?v=$v" sizes="16x16 32x32 48x48">
  <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png?v=$v">
  <link rel="manifest" href="manifest.webmanifest?v=$v">
  <meta name="theme-color" content="#d2502c">
  <meta name="application-name" content="TWB Plánovač">
  <meta name="apple-mobile-web-app-title" content="TWB Plánovač">
  <script>try{var t=localStorage.getItem('mp-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}
  if('serviceWorker' in navigator)addEventListener('load',function(){navigator.serviceWorker.register('sw.js?v=$v').catch(function(){});});</script>

HTML;
}
