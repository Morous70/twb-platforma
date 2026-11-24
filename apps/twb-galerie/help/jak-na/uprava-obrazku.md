---
titulek: Upravit obrázek (ořez, otočení)
sekce: jak-na
admin: ne
kontext: medium
souvisi: komprese, verejne-odkazy
klicova: ohnisko, hlavní bod, celá fotka, useknutá fotka, oříznutá úvodní fotka, výřez na webu, upravit, ořez, oříznout, výřez, otočit, otočení, překlopit, zrcadlit, poměr stran, 16:9, 1:1, 4:3, 3:2, 2:1, 9:16
---
# Upravit obrázek (ořez, otočení)

Obrázek jde oříznout, otočit a překlopit přímo v detailu média. Původní soubor se uloží, takže úpravu kdykoli vrátíte.
Pro veřejný web jde navíc zvolit **ohnisko** – místo, které musí zůstat vidět, když web fotku ořízne (viz níže).

## Postup
1. Otevřete detail obrázku a klikněte na **Upravit obrázek** (nebo rozbalte kartu *Upravit obrázek* pod údaji o souboru).
2. **Otočení a překlopení**: **⟲ Doleva**, **⟳ Doprava** (o 90°), **⇋ Překlopit vodorovně** (zrcadlově), **⇅ Překlopit svisle**.
3. **Ořez**: v náhledu tažením nakreslete výřez. Uvnitř ho posunete, za úchyty změníte velikost; zaměřený výřez posunou i šipky (se Shiftem víc).
4. **Poměr stran**: **Volný**, **1:1**, **16:9**, **4:3**, **3:2** nebo **2:1**. Volba **Prohodit** udělá z poměru obrázek na výšku (9:16, 3:4, 2:3, 1:2).
   Po výběru poměru se nabídne co největší výřez uprostřed; výsledek má poměr přesně na pixely.
5. Pod náhledem vidíte výsledný rozměr. **Uložit úpravu** úpravu provede, **Zrušit výřez** ořez zruší, **Začít znovu** vrátí všechno do původního stavu.

## Co se s obrázkem stane
- Formát zůstává (JPEG i s metadaty, PNG i s průhledností, WebP). GIF a BMP se uloží jako PNG. Animovaný GIF upravit nejde – přišel by o animaci.
- **Původní soubor zůstává** – tlačítko **Vrátit původní soubor** vrátí obrázek před úpravou (i před kompresí). Úpravy jdou skládat: další úprava
  vychází z už upraveného obrázku.
- **Veřejný odkaz zůstává stejný**, na webech, kde je obrázek vložený, se ukáže upravená verze. Náhledy se vytvoří znovu.
- Komprese upraveného obrázku vychází z upravené verze, úprava se tedy neztratí. Komprese *bez ponechání originálu* původní soubor smaže – pak už úpravu
  vrátit nejde.

## Ohnisko a zobrazení na webu
Veřejný web fotky ořezává do pevného tvaru: úvodní fotka je na počítači široký pruh, na mobilu obdélník 16:9, mřížky fotek 4:3, karty galerií 5:3.
Bez nastavení se řeže od středu (úvodní fotka kousek pod středem), takže se může useknout hlava nebo hlavní detail.

1. V detailu obrázku klikněte na **Ohnisko na webu** (nebo rozbalte kartu *Ohnisko a zobrazení na webu*).
2. **Klikněte do fotky** na místo, které musí zůstat vidět (obličej, budova…). Bod jde i táhnout nebo zadat čísly *Ohnisko zleva / shora* v procentech.
3. Pod fotkou hned vidíte **náhled**: úvodní fotka na počítači a na mobilu, mřížka fotek a karta galerie.
4. Když z fotky nejde nic useknout (panorama, plakát), zaškrtněte **Zobrazit celou fotku** – web ukáže celý snímek a prázdné okolí doplní
   rozmazanou kopií téže fotky.
5. **Uložit**. **Zrušit ohnisko** vrátí ořez od středu.

Soubor se nemění, ohnisko platí na celém veřejném webu (úvodní fotka, přihlášení, mřížky, doporučené fotky, obálky galerií). Po ořezu, otočení,
vrácení původního souboru nebo nahrazení souboru se ohnisko zruší (ukazovalo by jinam) – nastavte ho znovu. Přes API a MCP jde nastavit polem
`ohnisko` (`{"x": 30, "y": 25}` nebo `null`) a `cela_fotka`.
