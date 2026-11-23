---
titulek: Reklama na počítači, tabletu a mobilu
sekce: jak-na
admin: ne
souvisi: weby-a-zony, zpusoby-vlozeni, cileni-a-frekvence, statistiky
klicova: mobil, tablet, počítač, zařízení, responzivní, rozměr, retina, 2×, srcset
---
# Reklama na počítači, tabletu a mobilu

Na mobilu je pro reklamu jiné místo než na počítači. Banner se proto **nikdy nezmenšuje** (zmenšený billboard je na mobilu nečitelný),
ale vybere se jiný rozměr. Pomáhají tři věci, které jdou kombinovat.

## 1. Responzivní zóna (základ)
V nastavení zóny vyplňte **Responzivní zóna – rozměry pro různé šířky**, např. `970x250, 728x90, 300x250`, a šířku a výšku nechte prázdné.
Zóna pak bere bannery všech těchto rozměrů. Asynchronní skript změří, **jak široké je místo** na stránce (ne celá obrazovka, takže to funguje
i v úzkém postranním sloupci), a vybere nejširší banner, který se do něj vejde:

| Šířka místa | Banner |
|---|---|
| 970 px a víc | 970×250 |
| 728–969 px | 728×90 |
| 300–727 px | 300×250 |
| méně | nic (náhradní HTML zóny) |

Přesné rozsahy pro vaši zónu ukazuje stránka zóny. Druh a priorita kampaně mají přednost: exkluzivní kampaň jen s rozměrem 300×250 se ukáže i na počítači.
Na jedné stránce se banner neopakuje, takže druhé místo stejné zóny dostane jiný banner nebo menší rozměr.

Responzivní zónu vložte **asynchronním JavaScriptem** (nebo JavaScriptem) – jen skript zná šířku místa. U rámu (iframe), obrázku v e-mailu a volání ze serveru
se rozměr vybírá podle zařízení z prohlížeče: mobil do 360 px, tablet do 768 px, počítač bez omezení.

## 2. Banner jen pro některá zařízení
V úpravě banneru je **Zobrazovat na zařízeních**: počítač, tablet, mobil. Hodí se, když má mobilní verze jiný obsah nebo jiný odkaz.
Celou kampaň omezíte v **Cílení a frekvence** ([Cílení a frekvence](cileni-a-frekvence)).

Zařízení se pozná podle **šířky okna**, kterou posílá skript: pod 768 px mobil, pod 1024 px tablet, jinak počítač (tablet na šířku je tedy počítač).
Bez skriptu (rám, obrázek, server) podle prohlížeče (User-Agent).

## 3. Statistiky podle zařízení
Zobrazení, prokliky i konverze se ukládají se zařízením. Ve **Statistikách** zvolte **Podle: Zařízení**, nebo filtr **Zařízení** – uvidíte CTR zvlášť pro mobil a pro počítač.
Proklik se započítá stejnému zařízení jako zobrazení, konverze zařízení prokliku. Data ze starších verzí jsou „neznámé zařízení“.

## Ostrý obrázek na mobilu (2×)
Mobily a displeje Retina mají víc bodů na pixel, běžný obrázek je na nich mírně rozmazaný. U obrázkového banneru můžete přidat
**obrázek 2×** – stejný banner v dvojnásobném rozměru (k 300×250 obrázek 600×500, u obrázku z adresy adresu obrázku 2×).
Prohlížeč si ho vybere sám, banner na stránce má pořád 300×250. E-mail (obrázek s odkazem) dostane vždy běžný obrázek.
