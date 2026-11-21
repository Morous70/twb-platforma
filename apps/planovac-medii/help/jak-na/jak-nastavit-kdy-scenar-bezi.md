---
titulek: Jak nastavit, kdy scénář běží
sekce: jak-na
skupina: Scénáře a plánování
admin: ne
kontext: editor-scenare
souvisi: jak-vytvorit-scenar, jak-nahradit-medium-skupina, scenar-nebezi
klicova: kdy běží, období, dny v týdnu, hodiny, výjimky, platnost, plán
---
# Jak nastavit, kdy scénář běží

## K čemu to je

U **plánovaného** scénáře určujete, v které dny a hodiny se má promítat. Například jen v pondělí a ve čtvrtek od 6:00 do 20:59.

Část **Kdy běží** je vidět jen u typu **Plánovaný**. U typů Výchozí, Pevný a Sada se pravidla nenastavují.

## Postup

1. Otevřete scénář (**Scénáře → Upravit**, nebo **+ Nový scénář**).
2. Volitelně vyplňte **Platnost od** a **Platnost do**. Scénář pak běží jen v tomto období včetně obou krajních dnů. Pole nechte prázdná, pokud nemá být omezený.
3. U **Dny v týdnu** klikněte na dny, kdy má scénář běžet. Zkratky **Vše**, **Po–Pá** a **Víkend** označí skupinu najednou.
4. U **Hodiny** označte hodiny, kdy má běžet. Tlačítka **Celý den** a **Nic** označí všechno nebo nic. Můžete táhnout myší přes víc hodin najednou.
5. Pokud existují dny, kdy scénář běžet nemá, přidejte je do **Výjimek**: vyberte datum a klikněte na **Přidat**. Výjimku odeberete křížkem **×**.
6. Klikněte na **Uložit**.

[SNÍMEK: část Kdy běží s vybranými dny a hodinami]

## Jak číst hodiny

Hodina **6** znamená **6:00 až 6:59**. Pokud má scénář běžet do 21:00, označte poslední hodinu **20**. Hodina 21 by znamenala, že běží ještě do 21:59.

Vedle nadpisu **Hodiny** se zobrazuje shrnutí vybraných hodin.

## Všechna pravidla platí zároveň

Scénář běží, jen když platí **všechno**:
- dnešní datum je v období od–do (pokud je zadané),
- dnešní den je mezi vybranými dny v týdnu,
- současná hodina je mezi vybranými hodinami,
- dnešek není ve výjimkách.

## Co se stane potom

V seznamu **Scénáře** se pod názvem zobrazí shrnutí pravidel. V **Přehledu** uvidíte, jestli scénář právě teď běží (štítek **Běží teď**). V **Týdenním náhledu** vidíte, co poběží v které hodině.

Po skončení období se u scénáře zobrazí štítek **Skončil** a v seznamu zůstane ztlumený.

## Časté chyby

- **Hodinu „do“ jsem nechal/a neoznačenou.** Scénář pak skončí o hodinu dřív.
- **Prázdné dny nebo hodiny.** Uložení se odmítne hláškou **Vyberte aspoň jeden den v týdnu.** nebo **Vyberte aspoň jednu hodinu.**
- **Datum „od“ je po datu „do“.** Prohoďte data.
- **Scénář má správná pravidla, ale nic neukazuje.** Pravděpodobně nemá žádný obsah, je pozastavený, nebo ho potlačil výhradní scénář nebo jiný scénář ve stejné skupině. Viz [Scénář neběží, ačkoli by měl](scenar-nebezi).
