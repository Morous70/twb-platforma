---
titulek: Jak upravit tabulku Data
sekce: jak-na
skupina: Slidy a data
admin: ne
kontext: data
souvisi: jak-vlozit-hodnotu-do-slidu, jak-vytvorit-slide, hlasky-data
klicova: data, tabulka, řádek, excel, importovat, smazat prošlé, jackpot, hodnota
---
# Jak upravit tabulku Data

## K čemu to je

V části **Data** jsou hodnoty, které se zobrazují na slidech, například výše jackpotu pro jednotlivá losování. Když je změníte, slidy se přegenerují samy.

## Struktura tabulky

Každý řádek má tři údaje: **Skupina | Parametr | Hodnota**.

- **Skupina**: o co jde, například `Sportka`.
- **Parametr**: datum (například `26.11.2026`), nebo libovolný text (například název položky ceníku).
- **Hodnota**: číslo nebo text, například `125 000 000`.

Pod tabulkou je při vybrané skupině nápověda, jak hodnotu vložit do slidu, a u skupin s daty **Nejbližší** a **Poslední prošlý**.

## Jak přidat řádek

1. V menu klikněte na **Data**.
2. Klikněte na **+ Řádek**.
3. Vyplňte **Skupinu**, **Parametr** a **Hodnotu**.
4. Klikněte na **Uložit**, nebo na **Uložit a další** (případně stiskněte Enter v poli **Hodnota**).

## Jak řádek upravit nebo smazat

- Kliknutím do buňky text změňte. Po opuštění buňky (nebo po Enteru) se řádek uloží. Objeví se hláška **Uloženo.**
- Křížek **×** na konci řádku řádek smaže.

## Jak vložit víc řádků z Excelu

1. V Excelu označte tři sloupce **Skupina, Parametr, Hodnota** (záhlaví není nutné) a zkopírujte je (Ctrl+C).
2. V aplikaci v části **Data** klikněte na **Vložit z Excelu**.
3. Vložte data (Ctrl+V). Pod polem se ukáže počet rozpoznaných řádků.
4. Pokud chcete, aby nahradily stávající řádky ve vkládaných skupinách, zaškrtněte **Nahradit stávající řádky ve skupinách, které vkládám**. Jinak se řádky jen přidají.
5. Klikněte na **Vložit**.

Sloupce mohou být oddělené tabulátorem, středníkem nebo svislou čárou.

## Jak smazat prošlé řádky

Řádky s datem v minulosti jsou označené **prošlé**. Tlačítko **Smazat prošlé (…)** je smaže všechny najednou. Aplikace se předem zeptá a potvrdit musíte.

## Co se stane potom

Slidy, které změněné hodnoty používají, se přegenerují. Hláška to oznamuje: **Přegenerováno slidů: … (na obrazovku do 5 min).**

## Časté chyby

- **Řádek nelze uložit: …** Za dvojtečkou je důvod, například špatný zápis data.
- **Vložte řádky z Excelu (Skupina, Parametr, Hodnota).** Pole pro vložení je prázdné.
- **Nic k importu – …** Řádky nemají aspoň tři sloupce.
- **Přeskočeno: …** Některé řádky se nepodařilo vložit.
- **Hodnota se na slidu nezobrazí.** Skupina nebo parametr jsou ve slidu napsané jinak než v tabulce. Viz [Jak vložit do slidu hodnotu](jak-vlozit-hodnotu-do-slidu).
- **Smazáním prošlých jsem smazal/a i potřebné řádky.** Smazání nejde vzít zpět. Před smazáním se podívejte na označení **prošlé**.
