---
titulek: Komprimovat obrázky
sekce: jak-na
admin: ne
kontext: komprese
souvisi: nahravani, verejny-web
klicova: komprese, zmenšit, velikost, místo na disku, webp, jpeg, kvalita, originál
---
# Komprimovat obrázky

Hromadná akce v **Galerii** zmenší vybrané obrázky (velikost souboru, případně rozměr). Hodí se před zveřejněním na webu
nebo když galerie zabírá moc místa.

## Postup
1. V galerii vyberte obrázky (zaškrtnutím, tažením myši nebo **Vybrat vše na stránce**).
2. V **Akce s vybranými…** zvolte **Komprimovat obrázky** a nastavte:
   - **Formát** – **JPEG** (obrázky s průhledností zůstanou PNG) nebo **WebP** (menší soubory, průhlednost zůstane).
   - **Kvalita** 40–95. Doporučeno 75–85; pod 70 už bývají vidět kostičky.
   - **Největší rozměr** delší strany (např. 2560 px), nebo **Nezmenšovat**.
   - **Ponechat originál** – viz níže.
   - **Zachovat metadata** – u JPEG zůstane v souboru EXIF, XMP, IPTC a barevný profil (orientace se opraví, fotka se neotočí dvakrát).
     WebP a PNG metadata v souboru neponesou. Autor, licence, tagy a poznámka v galerii zůstávají vždy.
3. **Provést** otevře stránku s průběhem. Obrázky se zpracovávají po dávkách (několik sekund na dávku), takže kompresi nepřeruší
   časový limit hostingu. Nechte stránku otevřenou; **Přerušit** zastaví po dokončené dávce.
4. Na konci uvidíte souhrn: kolik obrázků se zkomprimovalo, o kolik jsou soubory menší a co se stalo s místem na disku.

Videa a PDF ve výběru se přeskočí. Obrázek, který by po kompresi nebyl menší než teď, zůstane beze změny (počítá se jako „už dost malý“).
Animované GIFy se nekomprimují, přišly by o animaci.

## Ponechat originál, nebo ne?
- **Ponechat originál (výchozí)** – zkomprimovaný soubor se vydává na veřejných odkazech, na webu i přes API, ale originál zůstává
  na disku. V detailu média ho vrátíte tlačítkem **Vrátit původní soubor** a další komprese vždy vychází z něj (žádná ztráta kvality opakováním).
  **Místo na disku se tím neuvolní, naopak přibude** zmenšená kopie.
- **Bez originálu** – originál i dosavadní verze se smažou a zůstane jen zkomprimovaný soubor. Tím se místo na disku opravdu uvolní,
  ale **vrátit to nejde**. Aplikace se před spuštěním zeptá.

Tip: nejdřív kompresi vyzkoušejte s ponechaným originálem, výsledek prohlédněte, a když vyhovuje, spusťte ji znovu bez originálu –
komprese vyjde z originálu, takže kvalita se dvojím zpracováním nezhorší.

## Co se změní
- Přípona v názvu se změní podle formátu (např. `foto.png` → `foto.jpg`). Veřejný odkaz zůstane stejný.
- Náhledy se vytvoří znovu. Zálohy obsahují vždy aktuální soubory (a originály, pokud zůstaly).
