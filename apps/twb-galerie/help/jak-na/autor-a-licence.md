---
titulek: Autor, licence a metadata
sekce: jak-na
admin: ne
souvisi: nahravani, kategorie-a-tagy
klicova: autor, licence, copyright, metadata, exif, iptc, xmp, štítky, klíčová slova, creative commons
---
# Autor, licence a metadata

Každé médium může mít **autora**, **licenci** (text, např. „CC BY 4.0“ nebo „zakoupeno na fotobance, jen pro web“) a **odkaz na licenci**. Upravíte je v detailu média v rámečku **Údaje**; prázdné pole údaj smaže. Hledání v galerii prohledává i autora.

## Co se načte z obrázku
Fotoaparáty a programy (Lightroom, Photoshop, Bridge, darktable, fotobanky, Windows → Vlastnosti → Podrobnosti) ukládají popis přímo do souboru. Při nahrání nového obrázku – samotného i ze ZIPu – se načte:

| Údaj | Odkud (v tomto pořadí) |
|---|---|
| Název | XMP Název (dc:title), IPTC Object Name, IPTC/XMP Headline, Windows Název |
| Štítky | XMP Klíčová slova (dc:subject), IPTC Keywords, Windows Klíčová slova – ze všech zdrojů, bez opakování |
| Autor | XMP Autor (dc:creator), IPTC By-line, EXIF Artist, Windows Autoři |
| Licence | XMP Podmínky užití (UsageTerms), XMP Copyright (dc:rights), IPTC Copyright, EXIF Copyright |
| Odkaz na licenci | XMP Copyright URL (WebStatement) nebo Creative Commons license |

- Název z metadat dostane příponu souboru (např. „Hory v mlze.jpg“). Když metadata název nemají, zůstane název souboru.
- Štítky se přidají k tagům vybraným při nahrání. Tag může mít nejvýš 30 znaků a médium nejvýš 10 tagů; delší a další štítky se vynechají.
- Při **nahrazení souboru** se metadata nečtou – údaje, které jste upravili, zůstanou.
- Metadata se čtou z originálu ještě před případnou kompresí, takže se neztratí.
