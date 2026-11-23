---
titulek: Veřejný web
sekce: jak-na
admin: ne
kontext: web
souvisi: verejne-odkazy, kategorie-a-tagy
klicova: web, hlavní stránka, veřejná galerie, reklama, banner, adsense, sklik, reklamní kód, ads.txt, měření návštěvnosti, matomo, gdpr, cookies, souhlas, zásady ochrany osobních údajů, o galerii, úvodní fotka
---
# Veřejný web

Na adrese `https://galerie.kazbo.eu/` je veřejná stránka galerie pro návštěvníky: **Domů**, **Galerie** a **O galerii**.
Administrace zůstává na `https://galerie.kazbo.eu/index.php` (tlačítko **Přihlášení do administrace** v hlavičce a v patičce).

## Co se na webu ukáže
Nic se nezveřejní samo. Na webu je jen to, co výslovně povolíte:

1. V **Kategorie** u kategorie zaškrtněte **Zobrazit na veřejném webu**.
2. Na webu pak budou **obrázky** z této kategorie, které mají **zapnutý veřejný odkaz** a **nejsou v archivu**.

- Vypnutím veřejného odkazu u média ([Veřejné odkazy](verejne-odkazy)) fotka z webu zmizí, i když je kategorie veřejná.
- Videa a PDF se na webu neukazují. Originál se nenabízí – web ukazuje zmenšené náhledy (640 px v mřížce, 1920 px ve zvětšení).
- Prázdná veřejná kategorie se na webu nezobrazí.

## Hlavní stránka
- **Úvodní fotka**: v **Veřejný web → Úvodní fotka**, nebo tlačítkem **Úvodní fotka webu** v detailu obrázku. Musí mít zapnutý veřejný odkaz.
  Když žádnou nezvolíte, použije se obálka první veřejné kategorie.
- **Štítek, nadpis, podnadpis, text tlačítka a poznámka** se upravují tamtéž.
- **Vybrané galerie** jsou karty prvních N veřejných kategorií (počet nastavíte, 0 = sekce se skryje). Každá karta má obálku a počet fotografií.

## Kategorie na webu
U kategorie v **Kategorie → Upravit** nastavíte:
- **Popis na webu** – krátký text pod názvem karty a galerie.
- **Obálka** – obrázek na kartě; bez volby nejnovější fotka kategorie.
- **Pořadí na webu** – menší číslo je dřív; při shodě rozhoduje název.

Stránka galerie ukazuje fotky po 36, nejnovější první. Kliknutím se fotka zvětší, šipkami (nebo posunem prstu) se listuje, Esc okno zavře.
V popisku je uvedení autora podle licence (např. „Foto: Jana Nováková, CC BY 4.0“).

Stránka **Veřejný web** má karty **Základ a texty**, **Reklama**, **Měření návštěvnosti** a **GDPR a cookies**; každá se ukládá svým tlačítkem Uložit.

## Reklama
Vypínač **Zobrazovat reklamu na veřejných stránkách** a pro každou pozici jedno pole pro **kód z reklamního systému** (AdSense, Sklik,
Revive, Etarget…), včetně `<script>`. Kód se vypíše přesně tak, jak ho vložíte – vkládejte jen kód, kterému věříte. Prázdná pozice se nezobrazí.
- **Pod úvodem** – hlavní stránka pod úvodní fotkou, stránka Galerie a galerie kategorie pod nadpisem (banner na šířku, např. 970×250 nebo 728×90).
- **Pod galeriemi** – pod kartami galerií, v galerii kategorie pod mřížkou fotek (obdélník 300×250 nebo banner na šířku).
- **Před patičkou** – úplně dole na hlavní stránce, v galeriích i na stránce O galerii.

**ads.txt** web vydá na adrese `/ads.txt` (řádky dá reklamní systém, AdSense: *Weby → ads.txt*). Prázdné pole = soubor neexistuje.

## Měření návštěvnosti
Vypínač **Měřit návštěvnost veřejných stránek**, pole **Měřicí kód** (celý úsek z Matomo, Google Analytics apod., klidně s `<noscript>`)
a **Kam kód vložit**: na konec stránky (bezpečné pro jakýkoli kód) nebo do hlavičky, když to nástroj výslovně chce. Do administrace se kód nevkládá.

## GDPR a cookies
- **Ptát se návštěvníků na souhlas s cookies** – dole na webu se ukáže lišta (Přijmout vše / Odmítnout / Nastavení). Dokud návštěvník
  nepovolí, **měřicí ani reklamní kód se vůbec nenačte** (je ve stránce jen jako neživý text). Lišta se ptá jen na to, co je opravdu
  vyplněné (měření, reklama). Volbu si prohlížeč pamatuje rok, změnit se dá odkazem **Nastavení cookies** v patičce. Bez souhlasu
  (vypnutý vypínač) se kód vkládá rovnou.
- **Zásady ochrany osobních údajů** – stránka na adrese `/zasady-ochrany-osobnich-udaju` s odkazem v patičce a v liště. Vyplňte provozovatele,
  IČO, e-mail, adresu a případně název stránky. Prázdný **vlastní text** = vzorový text sestavený z těchto údajů a z toho, co web skutečně dělá
  (záznamy serveru, cookie s volbou, měření a reklama podle nastavení). Tlačítkem **Vložit vzorový text do pole** si ho můžete vzít a upravit.
  Vzor je podklad, ne právní posudek. Stránka funguje i při vypnutém webu.

Jakmile stránka obsahuje reklamní nebo měřicí kód, povolí skripty, rámečky a styly z cizích webů (jinak by se nenačetly). Stránky bez kódu
zůstávají na přísném nastavení.

## O galerii
Text stránky napíšete v **Veřejný web → Stránka O galerii**. Odstavce oddělte prázdným řádkem; jde použít `**tučně**`, odrážky `- `,
nadpisy `## ` a odkazy `[text](https://…)`.

## Vypnutí webu
Po odškrtnutí **Veřejný web zapnutý** vede kořen domény rovnou na přihlášení do administrace jako dřív.
**Skrýt web před vyhledávači** přidá stránkám značku `noindex`.

## Technicky
- Kořen domény otevře `verejne.php` díky řádku `DirectoryIndex verejne.php index.php` v `.htaccess`. Odkazy `?s=galerie` a `?s=o-galerii`
  fungují i přes `index.php`, kdyby hosting `.htaccess` nečetl.
- Stránky se smějí 60 sekund držet v mezipaměti prohlížeče, změna se tedy může projevit s malým zpožděním.
