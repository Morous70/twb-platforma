---
titulek: Jak přidat HTML dokument
sekce: jak-na
skupina: Média a galerie
admin: ne
kontext: galerie
souvisi: jak-vlozit-hodnotu-do-slidu, jak-vytvorit-scenar, jak-pridat-bezici-text
klicova: html, dokument, editor, kód, javascript, oznámení, otevírací doba, stránka
---
# Jak přidat HTML dokument

## K čemu to je

HTML dokument je stránka, kterou přehrávač zobrazí na celou obrazovku, například otevírací dobu o svátcích nebo oznámení. Napíšete ho přímo v aplikaci, nebo nahrajete hotový soubor `.html`.

Dokument může obsahovat hodnoty z **Dat** (například `{datum}`), obrázky, tabulky, styly a JavaScript.

## Jak dokument napsat v aplikaci

1. V menu klikněte na **Galerie**.
2. Klikněte na dlaždici **HTML**. Otevře se editor s ukázkovým nadpisem a textem.
3. Do pole **Název dokumentu** napište název, například „oteviraci-doba-vanoce“. Na Google Disku se objeví jako „název.html“.
4. Klikněte do textu a pište. Lišta nad dokumentem nabízí **Písmo**, **Velikost**, **Formáty**, **Tučné**, **Barva písma**, **Zarovnat**, **Seznam**, **Tabulka**, **Obrázek**, **Odkaz** a další.
5. Klikněte na **Uložit**.

Plocha dokumentu má velikost obrazovky 1920 × 1080 bodů. Co se do ní nevejde, přehrávač nezobrazí.

## Jak vložit hodnotu z Dat

1. Klikněte do textu na místo, kam má hodnota přijít.
2. V nabídce **+ Vložit hodnotu…** vyberte hodnotu. Hodnotu ze vzdáleného zdroje vložíte tlačítkem **Ze zdroje…**.

Hodnota se vloží jako zápis ve složených závorkách, například `{den}`. Přehrávač uvidí skutečnou hodnotu. Ta se sama obnovuje stejně jako u slidů. Přehled zápisů ukáže tlačítko **Proměnné a formáty**.

## Jak vložit obrázek

- **Obrázek z galerie:** klikněte na **🖼 Obrázek z galerie** a vyberte obrázek. Vloží se přímo do dokumentu, takže se zobrazí i bez internetu.
- **Obrázek z internetu:** v liště klikněte na **Obrázek** a zadejte **URL obrázku**. Takový obrázek se na obrazovce zobrazí jen s připojením k internetu.

## Režimy Vizuálně a Kód

Vpravo nahoře přepínáte mezi **Vizuálně** a **Kód**.

- **Vizuálně**: píšete a formátujete jako v textovém editoru.
- **Kód**: celý zdrojový kód dokumentu včetně stylů a JavaScriptu.

Ve vizuálním režimu se JavaScript nespouští a zůstane beze změny. Dokument s JavaScriptem se otevře v režimu **Kód**.

## Jak dokument vyzkoušet

Klikněte na **Náhled**. Ukáže se dokument tak, jak ho uvidí přehrávač: s dnešními hodnotami a se spuštěným JavaScriptem.

## Jak nahrát hotový soubor

**Nový dokument:** v **Galerii** klikněte na dlaždici **Nahrát soubor** a vyberte soubor `.html`. Na počítači ho můžete také přetáhnout do pole **Přetáhněte sem obrázky, videa, PDF nebo HTML**.

**Nahradit obsah existujícího dokumentu:**
1. U dokumentu klikněte na ikonu **Upravit** (tužka).
2. Klikněte na **Nahrát soubor HTML** a vyberte soubor.
3. Klikněte na **Uložit**.

Dokument může mít nejvýš 5 MB.

## Co se stane potom

- Dokument se objeví v **Galerii** s popiskem **HTML**. Filtr **HTML** ukáže jen HTML dokumenty.
- Kliknutím na náhled dokumentu v Galerii ho uvidíte tak, jak ho zobrazí přehrávač.
- Na obrazovku jde, až když ho přidáte do scénáře, viz [Jak vytvořit scénář](jak-vytvorit-scenar). Můžete ho dát i do náhodného výběru.
- Na obrazovce zůstane po stejnou dobu jako obrázek.
- Dokument běží odděleně od aplikace a přehrávače a k jejich datům nemá přístup.
- HTML dokumenty přehrávají přehrávače od verze 1.2.0 (plugin pro Chrome, TV Samsung, Android TV). Starší verze je přeskočí.

## Časté chyby

- **Po uložení se objeví upozornění, že dokument načítá soubory z internetu.** Obrázek, styl nebo skript je z internetu. Bez připojení se nezobrazí. Obrázky raději vkládejte z galerie.
- **Zápis `{…}` zůstal v textu.** Aplikace hodnotu nezná. Zkontrolujte zápis v **Náhledu**, neznámé hodnoty jsou pod ním vypsané.
- **Na televizi Samsung z roku 2018 nebo starší se část dokumentu nezobrazí.** Tyto televize mají starší prohlížeč. Použijte jednodušší styly a JavaScript.
- **Dokument se na televizi neukazuje.** Aktualizujte přehrávač na verzi 1.2.0 nebo novější.
