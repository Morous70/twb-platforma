---
titulek: Jak nainstalovat aplikaci na Android TV
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-zarizeni
souvisi: nastaveni-zarizeni, jak-nainstalovat-aplikaci-tv
klicova: android, android tv, google tv, chromecast, apk, github, párování, kód, ovladač, aplikace, instalace
---
# Jak nainstalovat aplikaci na Android TV

> 🔧 **Pro správce.**

## K čemu to je

Aplikace **TWB přehrávač** přehrává na televizi nebo boxu s Android TV či Google TV stejná média jako obrazovka v trafice. Je to stejný přehrávač jako v aplikaci pro TV Samsung, ovládá se stejně a páruje se stejným kódem.

Instalační soubor (APK) se sestavuje sám na GitHubu v soukromém repozitáři **twb-prehravac-android**. Nainstalujete ho do televize mimo obchod Google Play.

## Postup

### 1. Stáhněte instalační soubor

1. Na počítači nebo telefonu se přihlaste na github.com a otevřete repozitář **twb-prehravac-android**.
2. Vpravo klikněte na **Releases** a u nejnovější verze stáhněte soubor **twb-prehravac-<verze>.apk**.

### 2. Povolte na televizi instalaci aplikací mimo Google Play

1. Na televizi nainstalujte z Google Play aplikaci, kterou soubor do televize dostanete. Nejjednodušší je **Send Files to TV** (stejnou aplikaci nainstalujte i do telefonu). Jde to i přes USB disk a správce souborů.
2. Otevřete **Nastavení → Aplikace → Zabezpečení a omezení → Neznámé zdroje**.
3. Povolte instalaci pro aplikaci z bodu 1.

### 3. Nainstalujte aplikaci

1. Pošlete soubor APK z telefonu do televize aplikací **Send Files to TV**, nebo ho nahrajte na USB disk a disk připojte k televizi.
2. Na televizi soubor otevřete a zvolte **Instalovat**.
3. Aplikace **TWB přehrávač** se objeví mezi aplikacemi s vlastní dlaždicí.

### 4. Spárujte televizi

1. Spusťte **TWB přehrávač**. Ukáže šestimístný kód, který platí 10 minut.
2. V aplikaci otevřete **Nastavení → Zařízení** a klikněte na **📺 Spárovat televizi**.
3. Zadejte **Kód z televize** a **Název** a klikněte na **Spárovat**.
4. Do několika sekund se na televizi rozběhne přehrávání. Zařízení bude v seznamu s typem **Android**.

## Co se stane potom

- Aplikaci spustíte ovladačem z nabídky aplikací. Po spuštění hned přehrává, párovat znovu není potřeba.
- Média si uloží v televizi a bez internetu přehrává poslední stažený obsah. Co se nevejde, přehraje přímo ze serveru.
- Každých 5 minut si obnoví seznam. Když se obsah složky změní, obnoví seznam do 15 sekund. Obrazovka během přehrávání nezhasne.
- V **Nastavení → Zařízení** se u televize ukáže, co právě hraje. Odtud jde televize i ovládat: **⟳ Obnovit teď**, **↻ Restartovat přehrávač**, **⏭ Další soubor**.

Ovládání ovladačem:

| Tlačítko | Význam |
|---|---|
| **OK** nebo **Přehrát/Pauza** | pozastaví přehrávání, dalším stiskem pokračuje |
| **šipka vpravo** | další médium |
| **Zpět** | otevře nebo zavře okno se stavem. Okno se samo zavře po 10 sekundách |
| v okně se stavem **OK** | hned obnoví seznam médií (**⟳ Obnovit seznam**) |
| podržet **Zpět** | nastavení přehrávače (**Uložit adresu**, **Zrušit spárování**, **Zavřít**) |
| **Domů** | odejde z aplikace |

Pokud má ovladač zelené tlačítko, obnoví seznam také.

## Nová verze aplikace

Když přijde nová verze, stáhněte z **Releases** nový soubor APK a nainstalujte ho stejně jako poprvé. Nainstaluje se přes starou verzi, spárování i stažená média zůstanou.

## Časté chyby

- **Instalace se nepovolí.** V **Neznámých zdrojích** povolte tu aplikaci, ze které soubor otevíráte.
- **Vpravo dole je malá červená ikonka „!“ s časem.** Seznam se nepodařilo obnovit. Důvod ukáže okno po stisku **Zpět**. Přehrává se dál poslední stažený obsah.
- **Televize ukáže párovací kód a hlášku Televize už není spárovaná.** Zařízení bylo v aplikaci odebráno nebo dostalo **Nový klíč**. Spárujte televizi znovu.
- **Kód neplatí.** Opište kód, který televize ukazuje právě teď. Platí 10 minut.
