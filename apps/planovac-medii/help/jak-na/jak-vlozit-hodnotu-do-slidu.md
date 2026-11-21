---
titulek: Jak vložit do slidu hodnotu z tabulky Data nebo ze zdroje
sekce: jak-na
skupina: Slidy a data
admin: ne
kontext: editor-slidu
souvisi: jak-vytvorit-slide, jak-upravit-tabulku-data, jak-nastavit-vzdaleny-zdroj, jak-pridat-pocasi, slide-promenne-nefunguji
klicova: proměnná, hodnota, formát čísla, formát data, jackpot, datum, odpočet, svátek, zdroj
---
# Jak vložit do slidu hodnotu z tabulky Data nebo ze zdroje

## K čemu to je

Aby se čísla a data na slidu měnila sama, píšete do textu **proměnné** ve složených závorkách, například `{datum}`. Aplikace je při vykreslení nahradí aktuální hodnotou.

## Postup

1. Otevřete slide (**Galerie → Upravit** nebo dlaždice **Slide**).
2. Klikněte na textový prvek.
3. Do pole **Obsah** napište text. Proměnnou vložíte jedním z těchto způsobů:
   - výběrem **+ Vložit proměnnou…** (kalendář, počasí a hodnoty z tabulky **Data**),
   - tlačítkem **Ze zdroje…** (hodnoty ze vzdáleného zdroje, viz [Jak nastavit vzdálený zdroj dat](jak-nastavit-vzdaleny-zdroj)),
   - ručním napsáním.
4. Formát upravíte tlačítky **Formát data…** a **Formát čísla…**. Nejdřív klikněte kurzorem dovnitř proměnné.
5. Klikněte na **Skutečný náhled** a výsledek zkontrolujte.
6. Klikněte na **Uložit**.

[SNÍMEK: textový prvek s proměnnou a tlačítky Formát data… a Formát čísla…]

U pole **Obsah** je tlačítko **?** s přehledem proměnných a formátů, které můžete použít i při psaní.

## Nejčastější proměnné

| Zápis | Výsledek |
|---|---|
| `{datum}` | dnešní datum (`{datum:+1}` zítra) |
| `{den}` / `{Den}` | den v týdnu (pátek / Pátek) |
| `{svatek}` | kdo má dnes svátek (`{svatek:+1}` zítra) |
| `{mesic}`, `{rok}`, `{tyden}` | měsíc, rok, číslo týdne |
| `{odpocet:24.12.2026}` | za 69 dní / zítra / dnes |
| `{Skupina.nejbližší}` | hodnota s nejbližším datem (dnes a dál) |
| `{Skupina.nejbližší.parametr}` | jeho datum |
| `{Skupina.další}` | následující po nejbližším |
| `{Skupina.poslední}` | poslední s datem v minulosti |
| `{Skupina:26.11.2026}` | hodnota s přesně tímto parametrem |
| `{Ceník:Káva}` | hodnota s textovým parametrem |
| `{Počasí:teplota}` | teplota ve výchozím místě, další údaje viz [Jak přidat do slidu počasí](jak-pridat-pocasi) |

„Skupina“ a „parametr“ odpovídají sloupcům v části **Data**. Viz [Jak upravit tabulku Data](jak-upravit-tabulku-data).

## Formátování

Za svislou čáru se přidává formát: `{Sportka.nejbližší|mil}` zobrazí například „125 mil.“. Několik formátů lze řetězit.

| Zápis | Výsledek |
|---|---|
| `\|mil` | 125 mil. |
| `\|mld` | 1,26 mld. |
| `\|cislo` | 125 000 000 |
| `\|kc` | 125 000 000 Kč |
| `\|datum` | 26. 11. 2026 |
| `\|datum:den` | čtvrtek 26. 11. |
| `\|odpocet` | za 41 dní / zítra / dnes |
| `\|velka`, `\|mala`, `\|prvni` | velikost písmen |
| `\|vychozi:—` | co zobrazit, když hodnota chybí |

Nemusíte si je pamatovat. Tlačítka **Formát data…** a **Formát čísla…** ukáží ukázky a vloží správný zápis.

### Vlastní formát data

Zápis `{Sportka.nejbližší.parametr|datum:DN D. M.}` zobrazí například „čt 26. 11.“. Zástupné znaky píšete velkými písmeny: D (5), DD (05), M (3), MM (03), MĚSÍC (březen), MĚSÍCE (března), RR (26), RRRR (2026), DEN (čtvrtek), DN (čt).

## Co se stane potom

Při každé synchronizaci aplikace zkontroluje, jestli se hodnoty změnily. Pokud ano, slid přegeneruje.

## Časté chyby

- **Neznámá proměnná: …** Proměnná je napsaná špatně, nebo skupina či parametr v tabulce **Data** neexistují. Text zůstane ve slidu doslova, včetně závorek.
- **Číslo se zobrazí bez formátu.** Přidejte formát, například `|mil` nebo `|kc`.
- **Datum se zobrazí v jiném tvaru, než chcete.** Použijte **Formát data…**.
- **Emoji ve slidu nejsou vidět.** Písma je neobsahují.
