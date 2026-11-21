---
titulek: Jak zařídit, aby jedno médium nahradilo jiné
sekce: jak-na
skupina: Scénáře a plánování
admin: ne
kontext: scenare
souvisi: jak-nastavit-kdy-scenar-bezi, jak-vytvorit-vyber-podle-data, scenar-nebezi, na-televizi-bezi-neco-jineho
klicova: skupina, priorita, výhradní, nahradit, přednost, vytlačeno
---
# Jak zařídit, aby jedno médium nahradilo jiné

## K čemu to je

Často potřebujete, aby se v určitou dobu promítal jen jeden z řady obrázků. Například aktuální plakát Extra renty má nahradit starší plakáty téže hry.

K tomu slouží **Skupina** média, **Priorita** scénáře a volba **Výhradní**.

## Skupina: jen jedno z řady

Média, která mají stejnou **Skupinu** (například `ER` pro Extra rentu), se navzájem nahrazují. Pokud je jich ve skupině víc, promítá se jen médium z jednoho scénáře, a to z toho, který „vyhrává“.

### Jak skupinu nastavit

1. V menu klikněte na **Galerie**.
2. U média klikněte na ikonu **Upravit** (tužka). U slidu se otevře editor slidu a pole **Skupina** je v jeho horní liště.
3. Do pole **Skupina** napište zkratku, například `ER` pro Extra rentu. U všech médií, která se mají nahrazovat, napište stejnou.
4. Uložte.

Označení skupiny je vidět na náhledu média v **Galerii** i ve scénáři.

### Kdo vyhrává

Když ve stejné skupině běží média z víc scénářů najednou, vyhraje scénář podle tohoto pořadí:
1. vyšší **Priorita** (**Vysoká** přebije **Normální**, ta **Nízkou**),
2. při shodě scénář s **omezenou platností** (má vyplněno **Platnost od** nebo **do**),
3. při další shodě scénář s **kratším obdobím** platnosti,
4. při další shodě **novější** scénář.

Média z poraženého scénáře se v dané skupině nepromítají. V **Přehledu** se zobrazí jako **vytlačeno scénářem …**.

### Příklad

- Scénář „Extra renta – běžný týden“, Priorita **Normální**, médium ve skupině `ER`.
- Scénář „Extra renta – speciální akce“, Priorita **Vysoká**, období 20.–24. 10., médium ve skupině `ER`.

Během 20.–24. 10. se promítá médium z druhého scénáře, jinak z prvního. Příklad je jen ilustrační.

## Priorita

**Priorita** se uplatní jen mezi médii **ve stejné skupině**. Nerozhoduje o tom, který scénář se promítá celkově. Scénáře, které právě platí, se jinak promítají **společně**.

## Výhradní scénář

Zaškrtnutí **Výhradní** u scénáře znamená, že po dobu jeho běhu se **potlačí všechny ostatní plánované scénáře** (například pro mimořádné oznámení). Pevné scénáře se promítají dál. Výchozí scénář nenastoupí, protože výhradní scénář běží.

V **Přehledu** je potlačený scénář uveden jako **potlačeno: …**.

## Časté chyby

- **Médium chybí na televizi, i když je ve scénáři.** Zkontrolujte v **Přehledu**, jestli není vytlačeno jiným scénářem ve stejné skupině.
- **Priorita nic nemění.** Média nemají stejnou **Skupinu**.
- **Dva obrázky ze stejného scénáře se v jedné skupině nenahrazují.** Nahrazení funguje mezi scénáři, ne uvnitř jednoho scénáře.
- **Zapomenutý výhradní scénář.** Pokud běží, ostatní scénáře se nezobrazují. Odškrtněte **Výhradní**, nebo scénář pozastavte.
