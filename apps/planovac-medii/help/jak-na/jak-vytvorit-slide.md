---
titulek: Jak vytvořit slide
sekce: jak-na
skupina: Slidy a data
admin: ne
kontext: editor-slidu
souvisi: jak-vlozit-hodnotu-do-slidu, jak-upravit-tabulku-data, jak-vytvorit-scenar, slide-promenne-nefunguji
klicova: slide, editor, text, obrázek, obdélník, kuličky, pozadí, náhled, podmínka, varianta
---
# Jak vytvořit slide

## K čemu to je

**Slide** je obrázek vytvořený přímo v aplikaci, tedy plakát s textem, který se může sám měnit podle data a čísel (například výše jackpotu). Výsledek je obrázek 1920×1080 pixelů. Chová se jako každý jiný obrázek, přidáte ho do scénáře.

## Postup

1. V menu klikněte na **Galerie**.
2. Klikněte na dlaždici **Slide**. Otevře se editor.
3. Do pole **Název slidu** napište název. Volitelně vyplňte **Skupinu** a tlačítkem **🏷 Tagy** přidejte tagy.
4. Upravte slide (viz níže).
5. Klikněte na **Skutečný náhled**, abyste viděli, jak slide vypadne na obrazovce.
6. Klikněte na **Uložit**. Objeví se hláška **Slide „…“ uložen.**
7. Klikněte na **← Zpět**. Slide najdete v **Galerii** a přidáte ho do scénáře.

[SNÍMEK: editor slidu se seznamem prvků vlevo, plátnem uprostřed a vlastnostmi vpravo]

## Co lze do slidu přidat

Vlevo nahoře jsou tlačítka:
- **+ Text**: text, který může obsahovat proměnné.
- **+ Obrázek**: obrázek z **Galerie**.
- **+ Obdélník**: barevná plocha, třeba jako podklad pod text.
- **+ Kuličky**: čísla v kuličkách (například tažená čísla).
- **+ Počasí**: aktuální počasí nebo předpověď na 3 dny, viz [Jak přidat do slidu počasí](jak-pridat-pocasi).

Pod tlačítky je seznam vrstev. Šipky **↑** a **↓** mění pořadí (výš je navrchu). Kolečko **●** prvek skryje nebo zobrazí.

Prvek vyberete kliknutím na plátno nebo na seznam. Vpravo se zobrazí jeho vlastnosti. Pokud nevyberete nic, vpravo upravujete **Pozadí**: barvu, přechod nebo obrázek, a **Ztmavení**. Ztmavení pozadí zlepší čitelnost textu na fotce.

## Ovládání myší a klávesami

- Klik vybere prvek, tažení ho posouvá, rohy mění velikost.
- Šipky na klávesnici posouvají prvek (se Shiftem o 10 pixelů).
- **Delete** prvek smaže, **Ctrl+D** ho duplikuje.
- Prvky se přichytávají ke středu a okrajům.
- **↶** a **↷** (nebo Ctrl+Z a Ctrl+Y) vrací a opakují změny.
- U vybraného prvku jsou tlačítka **↔ střed**, **↕ střed**, **Duplikovat** a **Smazat**.

## Text, který se mění

Do textu lze vložit proměnné, například `{datum}` nebo hodnotu z tabulky **Data**. Viz [Jak vložit do slidu hodnotu z tabulky Data nebo ze zdroje](jak-vlozit-hodnotu-do-slidu).

## Zobrazit prvek jen za podmínky

U každého prvku je část **Podmínky**:
- **Zobrazit jen když**: prvek se zobrazí jen tehdy, když platí **všechny** podmínky. Pokud žádné nejsou, zobrazuje se vždy. Přidáte je tlačítkem **+ podmínka**.
- **Varianty textu** (jen u textu): tlačítkem **+ Varianta textu** přidáte jiné znění, které se použije za určitých podmínek. Použije se první varianta, jejíž podmínky platí. Pokud žádná neplatí, použije se základní text nahoře.

Prvek s podmínkami je v seznamu vrstev označený **⚑**.

## Co se stane potom

Slide je obrázek, který se vykresluje na serveru. Když se změní to, co obsahuje (den, datum, hodnoty v tabulce Data nebo ve zdroji), aplikace slide sama přegeneruje. Nový obraz se na televizi objeví při nejbližší synchronizaci (obvykle do 5 minut). Po změně dat to oznamuje hláška **Přegenerováno slidů: … (na obrazovku do 5 min).**

## Na mobilu

Na mobilu nebo tabletu lze upravit jen **texty** slidu. Rozložení slidu měňte na počítači.

## Časté chyby

- **Zadejte název slidu.** Pole **Název slidu** je povinné.
- **Slide se nepodařilo uložit** nebo **Slide se nepodařilo vykreslit.** Za dvojtečkou je důvod. Zkuste jiný obrázek nebo zjednodušte text. Pokud se to opakuje, kontaktujte správce.
- **Emoji (např. 🎉) písma neobsahují, ve slidu se nezobrazí.** Použijte obyčejný text.
- **Neznámá proměnná: … zůstane v textu, jak je.** Proměnná je napsaná špatně, viz [Ve slidu se místo hodnoty zobrazí {…}](slide-promenne-nefunguji).
- **Slide má neuložené změny. Opravdu odejít?** Pokud odejdete, změny se ztratí. Nejdřív klikněte na **Uložit**.
