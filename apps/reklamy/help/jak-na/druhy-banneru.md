---
titulek: Druhy bannerů
sekce: jak-na
admin: ne
kontext: banner
souvisi: editor-slidu, html5-bannery, promenne
klicova: banner, obrázek, galerie, makro, clickurl
---
# Druhy bannerů

| Druh | Kdy ho použít |
|---|---|
| Obrázek z galerie | běžný banner (JPEG, PNG, GIF, WebP); rozměr se převezme z obrázku |
| Obrázek z jiné adresy | obrázek leží jinde (CDN, web inzerenta) |
| HTML kód | textová reklama, vlastní kód; makra jako `{clickurl}` a [proměnné](promenne) |
| HTML5 | balíček ZIP z Google Web Designeru, Animate apod. ([HTML5 bannery](html5-bannery)) |
| Slide | banner z editoru v libovolném rozměru ([Editor slidů](editor-slidu)) |

Cílová adresa je povinná u všeho kromě HTML, kde odkazy píšete sami pomocí makra `{clickurl}` (např. `<a href="{clickurl}">`). Proklik se počítá a vede na cíl banneru; `{clickurl}https://jina.adresa/` vede jinam, ale jen na doménu cíle nebo adresu uvedenou v HTML.

Obrázek z galerie, který banner nebo slide používá, nejde smazat.
