---
titulek: Autor, licence a metadata
sekce: jak-na
admin: ne
souvisi: nahravani, kategorie-a-tagy
klicova: autor, licence, copyright, metadata, exif, iptc, xmp, štítky, klíčová slova, creative commons
---
# Autor, licence a metadata

Každé médium může mít **autora** a **licenci**. Upravíte je v detailu média v rámečku **Údaje**; prázdné pole údaj smaže. Hledání v galerii prohledává i autora.

## Licence
- **Licence**: výběr ze stejného seznamu, jaký používají Social Planner a Kvízy: CC0 (volné dílo), CC BY, CC BY-SA, Vlastní foto, Placená licence, Pixabay licence, Pexels licence, Vygenerováno AI a Jiné. Pod výběrem je krátká vysvětlivka.
- **Verze / název licence**: u CC BY a CC BY-SA číslo verze (např. 4.0), u Jiné přesný název licence. U ostatních licencí pole není potřeba.
- **Podrobnosti licence**: volný text, např. „zakoupeno na fotobance, jen pro web“ nebo text z metadat souboru.
- **Odkaz na licenci**: když ho nevyplníte, u Creative Commons a Pixabay / Pexels se použije oficiální znění licence.

V rámečku s údaji se pak ukáže **Uvedení autora** („Foto: Eva Malá, CC BY 4.0“). U CC BY a CC BY-SA ho licence vyžaduje u každého použití. Stejný text dostanou přes API i jiné aplikace (pole `atribuce`).

Médium z verze 0.5 s textovou licencí („CC BY 4.0“, odkaz na creativecommons.org…) dostalo výběr licence automaticky při aktualizaci. Text typu „© Jan Novák“ licenci neurčuje, výběr pak zůstane prázdný.

## Co se načte z obrázku
Fotoaparáty a programy (Lightroom, Photoshop, Bridge, darktable, fotobanky, Windows → Vlastnosti → Podrobnosti) ukládají popis přímo do souboru. Při nahrání nového obrázku – samotného i ze ZIPu – se načte:

| Údaj | Odkud (v tomto pořadí) |
|---|---|
| Název | XMP Název (dc:title), IPTC Object Name, IPTC/XMP Headline, Windows Název |
| Štítky | XMP Klíčová slova (dc:subject), IPTC Keywords, Windows Klíčová slova – ze všech zdrojů, bez opakování |
| Autor | XMP Autor (dc:creator), IPTC By-line, EXIF Artist, Windows Autoři |
| Licence (podrobnosti) | XMP Podmínky užití (UsageTerms), XMP Copyright (dc:rights), IPTC Copyright, EXIF Copyright; výběr licence se z textu a odkazu odhadne (např. „CC BY-SA 4.0“ → CC BY-SA, verze 4.0) |
| Odkaz na licenci | XMP Copyright URL (WebStatement) nebo Creative Commons license |

- Název z metadat dostane příponu souboru (např. „Hory v mlze.jpg“). Když metadata název nemají, zůstane název souboru.
- Štítky se přidají k tagům vybraným při nahrání. Tag může mít nejvýš 30 znaků a médium nejvýš 10 tagů; delší a další štítky se vynechají.
- Při **nahrazení souboru** se metadata nečtou – údaje, které jste upravili, zůstanou.
- Metadata se čtou z originálu ještě před případnou kompresí, takže se neztratí.
