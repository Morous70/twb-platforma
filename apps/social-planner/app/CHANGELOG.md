# Změny

Verze se číslují MAJOR.MINOR.PATCH: PATCH = opravy, MINOR = nové funkce, MAJOR = velké nebo nekompatibilní změny.

## 1.9.2 – 21. 11. 2026

- **Náhled odkazu přes Facebook:** když web odkazu server aplikace zablokuje (eBay a podobné obchody blokují celé sítě hostingů, výsledkem je chyba 403), aplikace si náhled nechá načíst od Facebooku (Graph API, stejný náhled jako v Ladicím nástroji pro sdílení). Použije klíče Meta aplikace z konfigurace. Když selže i to, hláška vysvětlí, že Facebook si náhled při zveřejnění načte sám.

## 1.9.1 – 21. 11. 2026

- **Oprava náhledu odkazu:** některé weby (např. eBay) odmítaly stažení náhledu chybou „HTTP/2 stream … was not closed cleanly“ nebo 403. Aplikace teď při takové chybě zkusí stránku načíst znovu přes HTTP/1.1, nejdřív jako Facebook a pak jako běžný prohlížeč. Stahuje se jen začátek stránky s údaji pro náhled, takže je načtení i rychlejší.

## 1.9.0 – 21. 11. 2026

- **Titulní stránka má dvě nové reklamní plochy:** plocha 3 pod sekcí *Jak to funguje* a plocha 4 těsně nad patičkou. Nastavují se v *Nastavení → Titulní stránka* stejně jako plochy 1 a 2 (reklamní kód, ukázková plocha, nebo skrýt). Po aktualizaci ukazují ukázkovou plochu, dokud je nenastavíte jinak.

## 1.8.0 – 20. 11. 2026

**Obrázky z internetu** do Knihovny médií – převzato z aplikace Kvízy.

- Tlačítko **Z internetu** v Knihovně médií i v Novém příspěvku: hledání na **Wikimedia Commons** a **Openverse** (Flickr, muzea…) bez registrace, na **Pixabay** a **Pexels** s vlastním API klíčem (okno *Klíče Pixabay / Pexels*). Místo hledání lze vložit odkaz na stránku obrázku, nebo až 20 odkazů najednou.
- Obrázek se stáhne k vám (JPEG do 2 048 px, s vybraným tagem) a převezme **autora a licenci** ze zdroje. Nesvobodné obrázky a licence NC / ND se nenabízejí, stejný obrázek se podruhé nestahuje (ve výsledcích má štítek *v knihovně*).
- Autor a licence u souboru: na kartě (©), ve zvětšeném náhledu s odkazy na zdroj a licenci, ruční úprava a kopírování uvedení autora.
- **Nový příspěvek** připomene u obrázku s licencí CC BY / CC BY-SA povinnost uvést autora a nabídne vložit „Foto: …“ do textu nebo prvního komentáře.
- MCP nástroj `knihovna_medii` vrací i autora a licenci (`autor_licence`, `uvest_autora`).

## 1.7.0 – 20. 11. 2026

AI asistenti (Claude, ChatGPT) pracují s novými funkcemi.

- **MCP nástroj `knihovna_medii`:** obrázky, videa a slidy z Knihovny médií (hledání, tag, typ, archiv) s ID, tagy, rozměrem a náhledem.
- **MCP nástroj `promenne_a_data`:** přehled proměnných pro text – kalendář, vlastní proměnné, tabulka dat s aktuálními hodnotami, vzdálené zdroje s ukázkovými zápisy (s parametrem `zdroj` úplný výpis, např. Allwyn), počasí a formáty.
- **MCP nástroj `nahled_textu`:** dosadí proměnné do textu pro zvolený čas zveřejnění a ohlásí neznámé.
- **`vytvor_koncept`** umí `media_z_knihovny` (ID z knihovny, i slidy a samotné video), vrací náhled textu s dosazenými proměnnými a varuje před neznámou proměnnou.
- API pro příchozí koncepty (`inbox.php`) přijímá i `library_ids`.
- Administrace má faviconu (logo aplikace).

## 1.6.0 – 20. 11. 2026

**Slidy** – editor obrázků s textem a daty převzatý z TWB Plánovače médií.

- Slide je položka **Knihovny médií** (štítek *Slide*, tlačítka *Nový slide*, *✎ Upravit*, *Duplikovat*) a do příspěvku se vkládá jako obrázek. V **Novém příspěvku** tlačítko *Vytvořit slide* otevře editor v nové kartě a po uložení slide sám vloží do příspěvku (u Story a Reels rovnou ve formátu 9 : 16).
- **Formáty plátna:** čtverec 1 : 1, na výšku 4 : 5, Story / Reels 9 : 16, na šířku 16 : 9, odkaz 1,91 : 1. Při změně formátu se prvky poměrně přepočítají.
- Vše jako v Plánovači: texty se zalamováním a automatickým zmenšením, grafický text (zlato, stříbro, barevné styly s obrysem, hloubkou a leskem), stín, obrys, podklad, obrázky z knihovny, obdélníky, **kuličky** s čísly tahu (i ze zdroje Allwyn), prvek **Počasí** (ikona + teplota, karta, předpověď na 3 dny), proměnné `{…}` s formáty, varianty textu a podmínky *Zobrazit jen když*, vrstvy, přichytávání, zpět / znovu, **skutečný náhled** ze serveru (i pro zvolený den a čas).
- **Vlastní písma** (TTF, OTF) s kontrolou českých znaků; ukládají se do `app/data/fonts` a jsou součástí záloh.
- **Aktualizace před zveřejněním:** slide s proměnnými se těsně před odesláním znovu vykreslí pro čas zveřejnění (jen když se obsah změnil). Adresa obrázku pro sítě má nově `?v=…`, aby Facebook a Instagram nepoužily starou kopii.

## 1.5.0 – 20. 11. 2026

Proměnné a externí data převzatá z TWB Plánovače médií. Nabídka **Proměnné** se jmenuje **Proměnné a data** a má karty:

- **Tabulka dat** (Skupina | Parametr | Hodnota): úpravy přímo v tabulce, vložení z Excelu (i s nahrazením skupin), šedé prošlé řádky a jejich smazání, nápověda s aktuální hodnotou skupiny. V textu `{{Sportka.nejbližší|mil}}`, `{{Sportka.nejbližší.parametr|datum:den}}`, `{{Sportka.další}}`, `{{Sportka.poslední}}`, `{{Ceník:Káva}}`.
- **Vzdálené zdroje** JSON a CSV (i tabulka Google): stahují se podle intervalu v cronu a ještě před zveřejněním; při výpadku zůstanou poslední data a do logu jde jedno varování. Předem nastavené **Allwyn** (klíč doplníte do adresy) a **Svátky**. Prohlížeč hodnot s kopírováním zápisu, např. `{{Allwyn:sportka.superjackpot|mil}}`.
- **Počasí** (OpenWeather): API klíč, výchozí místo názvem i souřadnicemi (předvyplněno 49.9884,17.4637), vyzkoušení. `{{Počasí:teplota}}`, `{{Počasí:zítra.max}}`, `{{Počasí:Brno.popis}}`.
- **Nové vestavěné proměnné:** `{{svatek}}` (český kalendář, `{{svatek:+1}}`), `{{tyden}}`, `{{hodina}}`, `{{odpocet:24.12.2026}}`, `{{dnu:…}}`, `{{Den}}`, `{{datum:+1}}`.
- **Formátování hodnot** jako v Plánovači: `|mil`, `|mld`, `|tis`, `|milionu`, `|miliard`, `|cislo`, `|kc`, `|bezmezer`, `|pripona`, `|datum` (pojmenované i vlastní formáty), `|odpocet`, `|velka`, `|mala`, `|prvni`, `|cisla`, `|vychozi`.
- **Nový příspěvek:** tlačítko **＋ Další (data, zdroje, počasí)** otevře výběr všech proměnných s dialogy Formát čísla a Formát data (s ukázkami). Proměnná se vloží tam, kde byl kurzor (text i první komentář). Náhled ukazuje skutečné hodnoty pro zvolený čas zveřejnění.
- Vše se dosazuje při zveřejnění podle jeho data a času; neznámou proměnnou aplikace při ukládání odmítne (s celým zápisem v hlášce).

## 1.4.0 – 20. 11. 2026

Knihovna médií převzala Galerii z TWB Plánovače médií.

- **Tagy místo alb.** Každé album se automaticky převedlo na tag stejného jména (soubory o nic nepřišly). Soubor může mít až 10 tagů. Řada tagů nad mřížkou filtruje (víc tagů najednou, přepínač *všechny / kterýkoli*, *Bez tagu*), klik na tag na kartě je rychlý filtr. Tag přidáte tlačítkem 🏷 Tagy, hromadně u vybraných souborů nebo přetažením karty na tag. Nově nahrané soubory dostanou vybraný tag. **Správa tagů**: přejmenování (i sloučení) a mazání.
- **Archiv:** soubor v archivu se nenabízí ve výběru z knihovny, ale naplánované příspěvky ho dál zveřejní. Archiv je vpravo v řadě tagů (počet a velikost). U souborů je vidět, v kolika příspěvcích jsou a kolik jich ještě čeká na zveřejnění.
- **Zvětšený náhled** s listováním mezi zobrazenými soubory (šipky, klávesy ←/→, tažení prstem).
- Hledání v knihovně najde soubor i podle tagu; tlačítko *Vybrat vše zobrazené*.
- **Výběr z knihovny v Novém příspěvku** má stejnou řadu tagů a nenabízí soubory z archivu.

## 1.3.6 – 20. 11. 2026

- **Stav připojení účtů hned po propojení:** po propojení Facebooku, Instagramu i Threads je účet rovnou označen jako „V pořádku“ (token byl právě ověřen). Dřív zůstal „neověřeno“ až do další denní kontroly.
- Na stránce Účty je u stavu v popisku čas posledního ověření, u Threads i datum, do kdy platí token (aplikace ho sama obnovuje).
- MCP nástroj `seznam_uctu` vrací čitelný stav (v pořádku / chyba / neověřeno), čas ověření a u Threads platnost tokenu.

## 1.3.5 – 20. 11. 2026

- **Adresa MCP serveru je nově `…/mcp/index.php`.** Claude odstraňuje koncové lomítko a adresu `…/mcp` hosting přesměrovává; při přesměrování se z požadavků POST stanou GET a spojení selže. Adresa přímo na soubor se nepřesměrovává. Metadata OAuth odpovídají všem tvarům adresy (`/mcp`, `/mcp/`, `/mcp/index.php`), takže stávající připojení (ChatGPT) fungují dál.

## 1.3.4 – 20. 11. 2026

- **Oprava připojení Claude:** adresa konektoru funguje i bez lomítka na konci (`…/mcp`). Dřív ji Apache přesměroval na `…/mcp/` a Claude při přesměrování změnil požadavky POST na GET, takže se spojení nikdy nenavázalo. Metadata OAuth odpovídají tvaru adresy, který klient použil.

## 1.3.3 – 20. 11. 2026

- **Oprava připojení Claude:** MCP server podporuje novou verzi protokolu 2026-07-28 (bezstavová, metoda `server/discover`, hlavičky `MCP-Protocol-Version` / `Mcp-Method`), kterou Claude používá. Dřív server tuto metodu neznal a Claude hlásil „returned an error when connecting“. Starší verze (ChatGPT) fungují dál.
- Správné chybové kódy podle nové verze (nepodporovaná verze 400/-32022, nesoulad hlavičky 400/-32020, neznámá metoda 404).

## 1.3.2 – 20. 11. 2026

- Opakované připojení stejného asistenta (např. několikrát „Connect“ v Claude) už nevytváří duplicitní připojení: po úspěšném novém připojení se starší připojení téže aplikace automaticky zruší.

## 1.3.1 – 20. 11. 2026

- **Diagnostika připojení AI asistentů** (Integrace (API)): záznam posledních požadavků na OAuth a MCP (kdo, kdy, s jakým výsledkem a proč) a tlačítko **Otestovat dostupnost**, které ověří, že hosting propouští požadavky Clauda (`Claude-User`, `python-httpx`) i ChatGPT.
- Hlavička `WWW-Authenticate` už neobsahuje znaky s diakritikou (některé klienty je odmítají).
- Metadata OAuth nabízejí i rozsah `offline_access`.

## 1.3.0 – 20. 11. 2026

- **MCP server pro AI asistenty** (Claude, ChatGPT) na adrese `/mcp/`. Nástroje: seznam účtů a skupin, příspěvky v období, statistiky, nejlepší čas, sady hashtagů, šablony a vytvoření konceptu. Asistent nic nezveřejní ani nesmaže.
- **Přihlášení asistenta přes OAuth 2.1** (automatická registrace klienta, PKCE): při připojení se přihlásíte do Social Planneru a potvrdíte přístup; oprávnění ke konceptům lze odškrtnout.
- Na stránce **Integrace (API)** je adresa serveru, návod pro Claude a ChatGPT a seznam připojených asistentů s možností odebrat přístup.
- Nové složky `mcp/`, `oauth/` a `.well-known/` (s vlastními `.htaccess`).

## 1.2.0 – 20. 11. 2026

- **Nastavení titulní stránky** (Nastavení → Titulní stránka): u obou reklamních ploch lze zvolit vlastní reklamní kód (AdSense, Sklik, vlastní banner), ukázkovou plochu, nebo plochu skrýt. Volitelný popisek „Reklama“, kód do `<head>` stránky, povolení indexace vyhledávači a vypnutí celé titulní stránky (adresa webu pak otevře přihlášení).
- Nastavení reklam se už nezadává v `app/config.php` (klíč `landing_ads` z verze 1.1.0 se nepoužívá).
- **Nový vzhled přihlášení** ve stylu titulní stránky: tlačítko pro zobrazení hesla, Passkey, obnova hesla e-mailem a odkaz zpět na úvodní stránku.

## 1.1.0 – 20. 11. 2026

- **Veřejná úvodní stránka** na adrese webu (`index.php`): představení aplikace, ukázka kalendáře s aktuálním měsícem, hlavní funkce, „Jak to funguje“, „O aplikaci“ a dvě reklamní plochy. Texty a odkazy reklam se nastavují v `app/config.php` klíčem `landing_ads`.
- **Kalendář má novou adresu** `kalendar.php` (dřív `index.php`). Menu i odkazy jsou upravené; po přihlášení se dál otevírá Přehled.
- Písmo úvodní stránky (Plus Jakarta Sans) je uložené přímo v aplikaci, nic se nenačítá z Googlu.
- Po propojení Facebooku se zobrazí stránka Účty a propojení.

## 1.0.0 – 20. 11. 2026

První číslovaná verze. Shrnuje vše, co aplikace umí, a nově:

- **Nová adresářová struktura.** Skripty a styly jsou ve složce `assets/` (`assets/js`, `assets/css`), dokumentace v `app/`. Adresy stránek se nemění, propojení s Metou, Googlem i cron fungují dál. Staré soubory v kořeni webu se po nahrání smažou samy.
- **Celá složka `app/` je chráněná** souborem `.htaccess` (dřív jen `app/data/`).
- **Uspořádané menu:** Plánování, Obsah, Výsledky, Účty, Nastavení. Propojení Facebooku, Instagramu a Threads je na stránce Účty.
- **Lépe čitelné formuláře:** výraznější okraje polí, tučnější popisky a odlišený zástupný text.
- **Číslo verze** je v zápatí a v Nápovědě.
- **Doporučení nejlepšího času** k publikování podle vlastních statistik (Statistiky a pole „Kdy publikovat“).

### Co aplikace umí
Plánování a publikování na Facebook stránky, Instagram a Threads (příspěvky, galerie, reels, stories, první komentář), kalendář, opakování, proměnné v textu, šablony, sady hashtagů, skupiny účtů, hromadné plánování, knihovna médií, statistiky (FB, IG, Threads), přehled, oznámení e-mailem, zabezpečení (heslo, Passkey, odhlášení po nečinnosti), zálohy včetně Google Drive, API pro příjem konceptů z jiných aplikací.
