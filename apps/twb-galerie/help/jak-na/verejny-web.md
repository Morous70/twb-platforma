---
titulek: Veřejný web
sekce: jak-na
admin: ne
kontext: web
souvisi: verejne-odkazy, kategorie-a-tagy
klicova: web, hlavní stránka, veřejná galerie, reklama, banner, adsense, sklik, reklamní kód, ads.txt, o galerii, úvodní fotka
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

## Reklamní plochy
Na hlavní stránce jsou dvě: **Reklamní banner** pod úvodní fotkou a **Reklamní plocha** pod galeriemi (ta je i na stránkách galerií).
U každé nastavíte, zda se zobrazí, štítek vlevo, text, text tlačítka a odkaz. Odkaz může být `https://…`, `mailto:…`, `tel:…`
nebo adresa na tomto webu, např. `?s=o-galerii`. Bez odkazu se tlačítko nezobrazí.

V poli **Obsah plochy** vyberete, co v ploše bude:
- **Vlastní text a tlačítko** – štítek vlevo, text, text tlačítka a odkaz (viz výše).
- **Kód z reklamního systému** – do pole vložíte celý kód reklamní jednotky tak, jak ho dá AdSense, Sklik, Etarget nebo jiný systém,
  včetně `<script>`. Na web se vloží bez úprav. Štítek (např. *Reklama*) se ukáže drobně nad reklamou, prázdný = bez štítku.

Vkládejte jen kód od reklamního systému, kterému věříte – kód se nekontroluje a na webu se spustí. Po přepnutí se nic neztratí,
text i kód zůstanou uložené.

## Reklamní systémy
- **Kód do hlavičky stránky** se vloží do `<head>` všech stránek webu. Sem patří kód, který reklamní systém chce „mezi značky head“:
  ověření vlastnictví webu, automatické reklamy AdSense nebo měřicí (retargetingový) kód Skliku.
- **Obsah ads.txt** web vydá na adrese `/ads.txt`. Řádky dostanete v reklamním systému (AdSense: *Weby → ads.txt*). Prázdné pole = soubor neexistuje.
- Jakmile stránka obsahuje reklamní kód, povolí skripty, rámečky a styly z cizích webů (jinak by se reklama nenačetla). Stránky bez kódu
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
