---
titulek: Jak vložit scénář do jiného scénáře
sekce: jak-na
skupina: Scénáře a plánování
admin: ne
kontext: editor-scenare
souvisi: jak-vytvorit-scenar, jak-nastavit-vychozi-scenar, hlasky-scenar
klicova: sada, pevný, vložený scénář, opakované použití, vkládání
---
# Jak vložit scénář do jiného scénáře

## K čemu to je

Když se stejná média používají na víc místech, nemusíte je přidávat do každého scénáře zvlášť. Vytvoříte **Sadu** a vložíte ji tam, kde ji potřebujete. Pro média, která mají běžet pořád, slouží **Pevný** scénář.

## Typy scénářů

| Typ | Kdy běží |
|---|---|
| **Plánovaný** | podle pravidel v části **Kdy běží** |
| **Výchozí** | když neběží žádný plánovaný scénář |
| **Pevný** | vždy, přidává se ke všemu ostatnímu |
| **Sada** | sama nikdy neběží, jen vložená do jiného scénáře |

## Jak vložit scénář

1. Otevřete scénář, do kterého chcete jiný vložit (**Upravit**).
2. V části **Ve scénáři** otevřete výběr **Vložené scénáře** a zvolte **+ Vložit scénář…**.
3. Vyberte scénář ze seznamu. Sady jsou označené **(sada)**.
4. Pokud vkládáte plánovaný scénář, můžete zaškrtnout **jen když platí i jeho vlastní pravidla**. Vložený scénář se pak uplatní jen ve dnech a hodinách, kdy platí jeho vlastní pravidla.
5. Klikněte na **Uložit**.

Odebrat vložený scénář můžete křížkem **×**.

## Jak vytvořit sadu

1. Klikněte na **+ Nový scénář**.
2. U položky **Typ** zvolte **Sada**.
3. Přidejte média a uložte.
4. Sadu pak vložte do jiných scénářů výše uvedeným postupem.

## Pevný scénář

1. Vytvořte scénář a u položky **Typ** zvolte **Pevný**.
2. Uložte.
3. Pevný scénář se zobrazuje vždy, i při výchozím scénáři a když nic neběží.

V konkrétním plánovaném nebo výchozím scénáři můžete pevný scénář vypnout. Otevřete scénář, v panelu **Pevné scénáře** odškrtněte jeho název. Pak se po dobu běhu tohoto scénáře nezobrazí.

## Co se stane potom

Vložený scénář přidá svá média do scénáře, do kterého je vložen. V seznamu **Scénáře** je u scénáře uvedeno **Vkládá: …** a u vloženého **Vloženo do: …**.

## Časté chyby

- **Sada nic nepromítá.** Je to správně, sada sama nikdy neběží, musí být vložená do jiného scénáře.
- **Vznikl by kruh.** Scénář A nemůže vložit scénář B, pokud B už (i nepřímo) vkládá A. Aplikace to neuloží a vypíše hlášku **… nelze vložit – už (i nepřímo) obsahuje tento scénář. Vznikl by kruh.**
- **Smazání pevného scénáře.** Přestane se zobrazovat ve všech scénářích.
