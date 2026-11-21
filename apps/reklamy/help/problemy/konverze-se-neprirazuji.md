---
titulek: Konverze se nepřiřazují
sekce: problemy
admin: ne
souvisi: konverze
klicova: konverze, tracker, nepřiřazená
---
# Konverze se nepřiřazují

- Je na **všech stránkách** webu inzerenta skript `t.js`? Bez něj se parametr `twbr` z adresy po přechodu na další stránku ztratí.
- Neodstraňuje web inzerenta parametry z adresy (přesměrování na „čistou“ adresu)? Pak pomůže jen cookie návštěvníka.
- Je tracker **aktivní** a sleduje správné kampaně (žádná zaškrtnutá = všechny)?
- Nebyl proklik starší než **okno po prokliku**?
- Neposílá web stejné **číslo objednávky** dvakrát? Druhá konverze se nezapočítá.
- U postbacku ze serveru zkontrolujte odpověď JSON: `stav` a `via` (`click`, `view`, prázdné = nepřiřazená).

Poslední konverze i nepřiřazené ukazuje stránka **Konverze**.
