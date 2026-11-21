---
titulek: Nastavení: Počasí
sekce: nastaveni
admin: ano
kontext: nastaveni-pocasi
souvisi: jak-pridat-pocasi, nastaveni
klicova: počasí, openweather, api klíč, výchozí místo, vyzkoušet, služba počasí
---
# Nastavení: Počasí

> 🔧 **Pro správce.** Nastavuje se jednou.

## K čemu to je

Na této kartě nastavíte, odkud aplikace bere počasí pro slidy. Počasí se ve slidech používá přes prvek **Počasí** a proměnné `{Počasí:…}`, viz [Jak přidat do slidu počasí](jak-pridat-pocasi).

Počasí se stahuje každých 15 minut, a to jen pro místa, která některý slide nebo HTML dokument používá, a pro výchozí místo.

## Co na kartě je

- **Služba**: odkud se počasí bere. Zatím je k dispozici **OpenWeather**.
- **API klíč**: klíč od služby. Uložený klíč se nezobrazuje. Pole vyplňte jen tehdy, když ho chcete změnit.
- **Výchozí místo**: místo, které se použije, když u prvku nebo proměnné místo nezadáte.
- **Uložit**, **Uložit a vyzkoušet** a **Odebrat klíč**.
- **Stažená místa**: tabulka míst se stavem **v pořádku** nebo **chyba** a časem posledního úspěšného stažení. Místo, které už žádný slide nepoužívá, má poznámku **(nepoužívá se)** a do druhého dne z tabulky zmizí.

## Jak získat API klíč OpenWeather

1. Na stránce openweathermap.org si založte účet a přihlaste se.
2. Otevřete stránku **API keys**. Odkaz na ni je u pole **API klíč**, dokud klíč není uložený.
3. Zkopírujte klíč.

Nový klíč může začít fungovat až za 2 hodiny.

## Jak počasí nastavit

1. V menu klikněte na **Nastavení** a pak na kartu **Počasí**.
2. Do pole **API klíč** vložte klíč.
3. Do pole **Výchozí místo** napište město, například `Praha`.
4. Klikněte na **Uložit a vyzkoušet**.

Pod tlačítky se objeví aktuální počasí: ikona, teplota, popis, pocitová teplota, vlhkost, vítr a předpověď na 3 dny. Místo se přidá do tabulky **Stažená místa**.

## Co se stane, když služba nefunguje

Slidy dál ukazují poslední stažené počasí. Do **Protokolu** se zapíše jedno varování „Počasí pro „…“ se nepodařilo stáhnout“. Až služba zase funguje, zapíše se „Počasí pro „…“ je opět dostupné“.

## Časté chyby

- **OpenWeather nepřijal API klíč.** Klíč je špatně zkopírovaný, nebo je nový a ještě nezačal fungovat. Zkuste to znovu za 2 hodiny.
- **Místo „…“ OpenWeather nezná.** Zkuste název s kódem země, například `Brno,CZ`.
- **Nejdřív uložte API klíč.** Tlačítko **Uložit a vyzkoušet** potřebuje klíč.
- **Překročen limit dotazů OpenWeather.** Bezplatný účet má omezený počet dotazů. Aplikace stahuje každé místo nejvýš jednou za 15 minut, proto by limit neměl dojít. Zkontrolujte, jestli stejný klíč nepoužívá i jiná aplikace.
