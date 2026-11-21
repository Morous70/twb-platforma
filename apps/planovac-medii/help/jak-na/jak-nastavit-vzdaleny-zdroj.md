---
titulek: Jak nastavit vzdálený zdroj dat
sekce: jak-na
skupina: Slidy a data
admin: ano
kontext: data
souvisi: jak-vlozit-hodnotu-do-slidu, jak-upravit-tabulku-data, hlasky-data
klicova: zdroj, json, csv, google tabulka, allwyn, stahování, interval, ze zdroje
---
# Jak nastavit vzdálený zdroj dat

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Hodnoty pro slidy (například výsledky losování nebo ceník) nemusíte psát ručně. Aplikace je může sama pravidelně stahovat z internetu. Zdrojem je soubor **JSON** nebo **CSV**, například i tabulka Google.

## Postup

1. V menu klikněte na **Data**.
2. V části **Vzdálené zdroje** klikněte na **+ Zdroj**.
3. Do pole **Název** napište název, například `Allwyn`. Použije se ve slidu.
4. Do pole **Adresa** vložte adresu zdroje.
5. Zvolte **Formát**: **JSON** nebo **CSV**. U adresy tabulky Google nebo souboru `.csv` se CSV zvolí samo.
6. U **Stahovat každých (minut)** nastavte interval. Cron běží každých 5 minut, kratší interval proto nemá smysl.
7. U CSV zaškrtněte **První řádek obsahuje názvy sloupců**, pokud to platí.
8. Klikněte na **Uložit**. Zdroj se hned zkusí stáhnout.

[SNÍMEK: okno Nový zdroj]

## Tabulka Google

Vložte její běžný odkaz. Tabulka musí být sdílená jako „Kdokoli s odkazem“. Stáhne se list z odkazu. Oddělovač i kódování (UTF-8, Windows-1250 z Excelu) se poznají samy.

## Jak hodnotu použít ve slidu

V textovém prvku slidu klikněte na **Ze zdroje…** a vyberte hodnotu. Vloží se zápis `{Zdroj:cesta}`. U CSV: `{Ceník:Káva.Cena}` znamená řádek podle prvního sloupce a sloupec podle záhlaví. `{Ceník:3.Cena}` je třetí řádek dat. Bez záhlaví se sloupce číslují (`{Ceník:Káva.2}`).

## Co u zdroje vidíte

U každého zdroje je formát, interval, počet řádků a stav: **v pořádku** a čas posledního stažení, nebo **chyba** s důvodem, nebo **zatím nestaženo**. Při chybě slidy dál používají poslední úspěšně stažená data.

Tlačítka:
- **Stáhnout teď**: stáhne hned, hláška říká, jestli se data změnila.
- **Prohlédnout**: ukáže stažená data. Kliknutím na hodnotu se zkopíruje zápis pro slid.
- **Upravit** a křížek **×** (smazání zdroje; slidy pak ukážou proměnné jako neznámé).

## Co se stane potom

Aplikace zdroj stahuje sama podle nastaveného intervalu. Jakmile se data změní, přegeneruje slidy, které je používají.

## Časté chyby

- **Název zdroje nesmí obsahovat znaky { } | : a tečku.** Upravte název.
- **Zdroj s tímto názvem už existuje.** Zvolte jiný.
- **Tento název je vyhrazený pro proměnnou kalendáře.** Zvolte jiný název.
- **Adresa musí začínat http:// nebo https://.**
- **Zdroj uložen, ale stažení selhalo: …** Zkontrolujte adresu, sdílení tabulky a formát.
- **Zatím nestaženo.** Počkejte na cron, nebo klikněte na **Stáhnout teď**.
- **Stažení selhalo: …** Přečtěte si důvod. Slidy mezitím používají poslední úspěšná data.
