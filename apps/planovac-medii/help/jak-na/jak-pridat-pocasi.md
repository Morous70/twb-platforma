---
titulek: Jak přidat do slidu počasí
sekce: jak-na
skupina: Slidy a data
admin: ne
kontext: editor-slidu
souvisi: nastaveni-pocasi, jak-vytvorit-slide, jak-vlozit-hodnotu-do-slidu
klicova: počasí, teplota, předpověď, openweather, ikona počasí, zítra, vlhkost, vítr
---
# Jak přidat do slidu počasí

## K čemu to je

Slide může ukazovat aktuální počasí nebo předpověď na 3 dny. Počasí se samo obnovuje každých 15 minut.

Nejdřív musí správce v **Nastavení → Počasí** zadat API klíč a výchozí místo, viz [Nastavení: Počasí](nastaveni-pocasi).

## Jak přidat prvek Počasí

1. Otevřete slide (**Galerie → Upravit** nebo dlaždice **Slide**).
2. Vlevo klikněte na **+ Počasí**.
3. V části **Vzhled** vyberte jednu ze tří možností:
   - **Ikona + teplota**: ikona počasí a teplota.
   - **Karta s detaily**: ikona, teplota, popis (například „Zataženo“), pocitová teplota, vlhkost a vítr.
   - **Předpověď na 3 dny**: dnes, zítra a pozítří, u každého ikona a nejvyšší / nejnižší teplota.
4. Do pole **Místo** napište město, například `Brno`. Když pole necháte prázdné, použije se výchozí místo z Nastavení. Místo se uloží, když stisknete Enter nebo kliknete jinam.
5. Prvek posuňte a tažením za rohy nastavte velikost. Velikost ikony a písma se řídí velikostí rámečku.
6. Podle potřeby nastavte **Písmo**, **Tloušťka**, **Barva textu**, **Zarovnání**, **Podklad** a **Stín textu**.
7. Klikněte na **Uložit**.

Pod polem **Místo** je vidět, pro které místo se počasí ukazuje a kdy bylo staženo.

[SNÍMEK: editor slidu s prvkem Počasí a volbou Vzhled]

## Jak vložit jen jeden údaj do textu

Údaj o počasí můžete napsat i do běžného textu, například „Venku je {Počasí:teplota} °C“.

1. Klikněte na textový prvek.
2. V nabídce **+ Vložit proměnnou…** vyberte ve skupině **Počasí** požadovaný údaj.

| Zápis | Výsledek |
|---|---|
| `{Počasí:teplota}` | teplota, například 12 |
| `{Počasí:pocitová}` | pocitová teplota |
| `{Počasí:popis}` | Zataženo |
| `{Počasí:vlhkost}` | vlhkost v % |
| `{Počasí:vítr}` | rychlost větru v m/s |
| `{Počasí:místo}` | název místa |
| `{Počasí:dnes.max}`, `{Počasí:dnes.min}` | nejvyšší a nejnižší teplota dnes |
| `{Počasí:zítra.max}`, `{Počasí:zítra.popis}` | zítřejší teplota a popis |
| `{Počasí:pozítří.den}` | den v týdnu pozítří (Sobota) |
| `{Počasí:Brno.teplota}` | počasí pro jiné místo než výchozí |

Teplota se píše bez jednotky, „°C“ napište za proměnnou sami. Proměnné o počasí jdou použít i v podmínkách, například **Zobrazit jen když** `{Počasí:teplota}` > 25.

## Co se stane potom

- Slide se překreslí, když se počasí změní. Na obrazovce je tedy vždy počasí nejvýš 15 minut staré.
- Když služba počasí nefunguje, slide ukazuje poslední stažené počasí. Do **Protokolu** se zapíše jedno varování.

## Časté chyby

- **V editoru je počasí průhledné a pod polem Místo je upozornění.** Počasí pro toto místo zatím není stažené, proto editor ukazuje jen ukázku. Na obrazovce se ukázka neobjeví. Důvod je napsaný v upozornění, například chybí API klíč nebo služba místo nezná.
- **Místo „…“ OpenWeather nezná.** Zkuste název s kódem země, například `Praha,CZ`.
- **V nabídce + Vložit proměnnou… chybí skupina Počasí.** V **Nastavení → Počasí** není uložený API klíč.
