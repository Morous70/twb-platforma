---
titulek: Konverze a trackery
sekce: jak-na
admin: ne
kontext: tracker
souvisi: konverze-se-neprirazuji, statistiky
klicova: tracker, pixel, objednávka, postback, twbr
---
# Konverze a trackery

**Tracker** je kód na webu inzerenta, který ohlásí objednávku, registraci nebo poptávku. Konverze se přiřadí banneru, na který návštěvník předtím klikl.

1. **Konverze → Nový tracker**: název a druh.
2. Na stránce trackeru nastavte výchozí hodnotu, **okno po prokliku** (kolik dní po kliknutí se konverze počítá) a případně **okno po zobrazení**.
3. Vložte kódy na web inzerenta: skript `t.js` na **všechny stránky** a po dokončení objednávky `twbr.push({ tracker: …, hodnota: …, id: … })`.

Číslo objednávky (`id`) brání dvojímu započítání. Bez JavaScriptu lze použít obrázek, ze serveru e-shopu postback s odpovědí JSON.

**Jak se konverze přiřadí:** proklik přidá k cílové adrese parametr `twbr`, který si `t.js` na webu inzerenta zapamatuje – funguje i v prohlížečích, které blokují cookies třetích stran. Druhou cestou je cookie návštěvníka. Nepřiřazená konverze se uloží také, jen bez banneru.

Konverze a jejich hodnota se přičtou do statistik banneru a kampaně; kampaň může mít i limit konverzí.
