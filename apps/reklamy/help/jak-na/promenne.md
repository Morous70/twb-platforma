---
titulek: Proměnné v textech
sekce: jak-na
admin: ne
kontext: promenne
souvisi: editor-slidu, druhy-banneru, externi-data
klicova: datum, svátek, odpočet, tabulka dat, excel
---
# Proměnné v textech

Proměnné píšete do textů slidů a HTML bannerů ve složených závorkách a dosadí se při každém zobrazení.

- **Kalendář**: `{datum}`, `{den}`, `{svatek}`, `{odpocet:24. 12. 2026}`, `{datum:+1}` …
- **Vlastní proměnné**: text nebo seznam (každé zobrazení v textu bere další řádek).
- **Tabulka dat**: řádky Skupina | Parametr | Hodnota, vkládání z Excelu. `{Akce:sleva}` vrátí hodnotu parametru `sleva`; když je parametr datum, `{Akce.nejbližší}` vybere řádek nejbližšího data.

- **Externí data**: hodnoty ze zdrojů JSON nebo CSV na jiném serveru, `{Ceník:káva.cena}` – viz [Externí data](externi-data).

Filtry za svislou čarou: `{cena|mil}`, `{nic|vychozi:—}`. Stránka **Proměnné** má pole **Vyzkoušet**, kde výsledek hned uvidíte.
