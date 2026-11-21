---
titulek: Externí data
sekce: jak-na
admin: ne
kontext: zdroje
souvisi: promenne, editor-slidu
klicova: zdroj, json, csv, tabulka google, api, ceník, kurz
---
# Externí data

Externí data jsou hodnoty z jiného serveru, které se samy dosazují do bannerů – ceník, kurz, výsledky, počet volných míst, tabulka Google.

## Přidání zdroje
1. **Obsah → Externí data → Nový zdroj**.
2. **Název** (bez `{ } | :` a tečky) – pod ním budete zdroj psát do textů.
3. **Adresa** zdroje: JSON (odpověď API) nebo CSV. Tabulku Google stačí publikovat na webu (Soubor → Sdílet → Publikovat na webu) a vložit odkaz, aplikace si ji stáhne jako CSV.
4. **Interval** – jak často se má zdroj stahovat (stahuje cron, takže nejčastěji tak často, jak cron běží).

Po uložení se zdroj hned stáhne. Kliknutím na název uvidíte jeho hodnoty i se zápisem, který zkopírujete do banneru.

## Použití v banneru
V textu slidu nebo v HTML banneru napište `{Zdroj:cesta}`, např. `{Ceník:káva.cena}`. Cesta jsou klíče JSON oddělené tečkou; u seznamu stačí hodnota, podle které se položka pozná (`{Ceník:Káva.Cena}` najde řádek s „Káva“).
Fungují i filtry: `{Ceník:káva.cena|mil}`, `{Kurz:eur|vychozi:—}`. V editoru slidů najdete zdroje v nabídce proměnných.

Slide se po změně dat vykreslí znovu sám, HTML banner dosazuje hodnoty při každém zobrazení.

## Když zdroj neodpovídá
Bannery dál ukazují poslední stažená data. Do protokolu se zapíše jedno varování a po obnovení zpráva, že je zdroj opět dostupný. Stav vidíte v seznamu zdrojů; **Stáhnout** zkusí zdroj stáhnout hned.
