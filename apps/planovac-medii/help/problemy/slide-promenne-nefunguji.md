---
titulek: Ve slidu se místo hodnoty zobrazí {…} nebo prázdno
sekce: problemy
admin: ne
kontext: editor-slidu
souvisi: jak-vlozit-hodnotu-do-slidu, jak-upravit-tabulku-data, jak-nastavit-vzdaleny-zdroj
klicova: proměnná, neznámá proměnná, složené závorky, prázdný slide, emoji, formát
---
# Ve slidu se místo hodnoty zobrazí {…} nebo prázdno

## Co se děje

Na slidu je doslovný text se složenými závorkami, například `{Sportka.nejbližší|mil}`, nebo se hodnota nezobrazuje.

## Příčiny a řešení

1. **Proměnná je napsaná špatně.** V editoru slidu aplikace u textového prvku ukáže žluté upozornění **Neznámá proměnná: … zůstane v textu, jak je.** Opravte zápis, nebo proměnnou vložte výběrem **+ Vložit proměnnou…** nebo **Ze zdroje…**. Viz [Jak vložit do slidu hodnotu](jak-vlozit-hodnotu-do-slidu).
2. **Skupina nebo parametr v tabulce Data neexistují nebo se píší jinak.** Zkontrolujte **Data**. Viz [Jak upravit tabulku Data](jak-upravit-tabulku-data).
3. **Zdroj dat nebyl stažen, nebo byl smazán.** V **Data → Vzdálené zdroje** zkontrolujte stav. Mělo by tam být **v pořádku**. Pokud je tam **chyba**, slidy používají poslední úspěšně stažená data. Smazání zdroje způsobí, že proměnné se ukážou jako neznámé. Viz [Jak nastavit vzdálený zdroj dat](jak-nastavit-vzdaleny-zdroj).
4. **Hodnota chybí.** Pomůže formát `|vychozi:—`, který zobrazí náhradní text.
5. **Emoji (např. 🎉) se nezobrazí.** Písma je neobsahují. Použijte běžný text.
6. **Číslo nebo datum má jiný tvar.** Použijte tlačítka **Formát čísla…** a **Formát data…**.
7. **Kuličky ukazují „Teď žádná čísla – zkontrolujte zdroj.“** Seznam čísel nemá hodnotu. Zkontrolujte zdroj, nebo ručně zadejte čísla oddělená mezerou.

## Jak ověřit výsledek

Klikněte v editoru na **Skutečný náhled**. Ukáže, jak slide vypadne na obrazovce.

## Co se stane potom

Slide se po opravě přegeneruje při další synchronizaci a nový obraz se objeví na televizi.
