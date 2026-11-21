---
titulek: Nastavení: Zařízení
sekce: nastaveni
admin: ano
kontext: nastaveni-zarizeni
souvisi: jak-nainstalovat-plugin-chrome, jak-nainstalovat-aplikaci-tv, jak-nainstalovat-aplikaci-android, nastaveni-prehravac, nastaveni-google-disk
klicova: zařízení, plugin, chrome, přehrávač, klíč, délka zobrazení, android, televize, samsung, párování, kód, obnovit, restartovat, další soubor, neozývá se, stav, chyba
---
# Nastavení: Zařízení

> 🔧 **Pro správce.**

Na kartě přidáváte zařízení, která přehrávají stejný obsah jako obrazovka v trafice: **plugin pro Chrome** a aplikaci **TWB přehrávač** pro **TV Samsung** a **Android TV**.

Zařízení si samo stahuje soubory ze složky přehrávače na Google Disku a samo je střídá v náhodném pořadí. Seznam souborů si obnovuje každých 5 minut. Když se obsah složky změní, obnoví ho do 15 sekund.

Karta nemá společné tlačítko **Uložit**, každá akce se provede hned.

## Zařízení pro přehrávání

| Pole nebo tlačítko | Význam |
|---|---|
| **+ Přidat zařízení** | přidá zařízení a ukáže jeho klíč (pro plugin pro Chrome) |
| **📺 Spárovat televizi** | spáruje televizi kódem z její obrazovky |
| **Stáhnout plugin pro Chrome** | stáhne plugin jako soubor ZIP. V závorce je jeho verze |
| **Stáhnout aplikaci pro TV Samsung** | stáhne aplikaci pro televizi jako soubor ZIP pro Tizen Studio. V závorce je její verze |
| **Stáhnout aplikaci pro Android TV** | stáhne projekt aplikace pro Android TV jako soubor ZIP (pro GitHub nebo Android Studio). Hotový instalační soubor je na GitHubu v **Releases**. V závorce je verze |
| **Upravit** | změní název, typ a délku zobrazení |
| **Nový klíč** | vytvoří nový klíč. Starý klíč přestane fungovat |
| **Odebrat** | smaže zařízení. Jeho klíč přestane fungovat |

U každého zařízení je vidět typ, délka zobrazení a kdy se naposledy ozvalo. Zelená tečka znamená, že se ozvalo v posledních 15 minutách. Červená tečka a text **neozývá se od** znamenají, že se 15 minut neozvalo.

## Stav a ovládání zařízení

Pod názvem zařízení je vidět:

- co právě hraje a od kdy, například **▶ Zlatý jackpot.jpg – od 14:05**,
- kolik souborů má zařízení v seznamu a kolik už má stažených,
- verze přehrávače,
- poslední chyba s časem, například **⚠ 14:02 Soubor nejde stáhnout**. Chyba seznamu nebo stahování zmizí, jakmile se seznam obnoví bez chyby. Chyba přehrání zmizí po 10 minutách bez další chyby.

Stav se na kartě sám obnovuje každých 15 sekund.

| Tlačítko | Význam |
|---|---|
| **⟳ Obnovit teď** | zařízení hned znovu načte seznam souborů |
| **↻ Restartovat přehrávač** | přehrávač se celý znovu spustí, třeba když se zasekne |
| **⏭ Další soubor** | zařízení přeskočí to, co právě hraje |

Zařízení na tlačítko zareaguje do 15 sekund. Do té doby je u něj **Odesláno** a tlačítka nejdou stisknout.

Tlačítka se ukáží jen u zařízení, které právě přehrává.

| Text u zařízení | Význam |
|---|---|
| **Přehrávač není otevřený** | plugin pro Chrome běží, ale přehrávač v záložce je zavřený |
| **Nepřehrává** | zařízení se neozývá |
| **Stará verze přehrávače – ovládání nefunguje** | zařízení přehrává, ale nehlásí stav a nereaguje na tlačítka. Nainstalujte novou verzi přehrávače |

## Upozornění: zařízení se neozývá

Když se zařízení 15 minut neozve, ukáže se nahoře v celé aplikaci červený pruh, například **Zařízení Televize ve výloze se neozývá od 14:05**. Odkaz **Nastavení → Zařízení** v pruhu otevře tuto kartu.

Pruh zmizí sám, jakmile se zařízení znovu ozve. Zařízení, které se ještě nikdy neozvalo, upozornění nehlásí.

## Okno Přidat zařízení

| Pole | Význam |
|---|---|
| **Název** | jak se zařízení bude jmenovat, například Počítač v kanceláři |
| **Typ** | **Chrome**, **Android** nebo **TV Samsung** |
| **Délka zobrazení (s)** | jak dlouho zařízení ukazuje jeden obrázek. Prázdné pole znamená výchozí délku |

Po kliknutí na **Přidat** se objeví okno s **adresou aplikace** a **klíčem zařízení**. Obojí zkopírujte tlačítkem **Kopírovat** a vložte do nastavení pluginu.

**Klíč se zobrazí jen jednou.** Pokud ho ztratíte, klikněte u zařízení na **Nový klíč**.

Klíč umí jen číst seznam souborů a stahovat je. Nic v aplikaci nezmění.

## Okno Spárovat televizi

| Pole | Význam |
|---|---|
| **Kód z televize** | šestimístný kód, který ukazuje aplikace **TWB přehrávač** na televizi Samsung nebo Android TV |
| **Název** | jak se televize bude jmenovat, například Televize ve výloze |
| **Délka zobrazení (s)** | prázdné pole znamená výchozí délku |

Po kliknutí na **Spárovat** vznikne zařízení s typem **TV Samsung** nebo **Android** (podle televize) a televize si sama vyzvedne svůj klíč. Klíč se nikde nezobrazuje ani neopisuje.

Když televizi odeberete nebo jí vytvoříte **Nový klíč**, televize ukáže nový párovací kód.

Postup: [Jak nainstalovat aplikaci na TV Samsung](jak-nainstalovat-aplikaci-tv), [Jak nainstalovat aplikaci na Android TV](jak-nainstalovat-aplikaci-android).

## Výchozí délka zobrazení

| Pole nebo tlačítko | Význam |
|---|---|
| **Sekundy** | jak dlouho zařízení ukazuje obrázek, slide, PDF nebo text. Výchozí je 20 sekund. Povoleno je 3 až 3600 |
| **Uložit** | uloží hodnotu |

Videa se přehrají vždy celá, délka zobrazení se na ně nevztahuje.

Změna se v zařízení projeví při jeho dalším obnovení seznamu, nejpozději do 5 minut. Hned ji projevíte tlačítkem **⟳ Obnovit teď**.

## Co zařízení přehrává

| Soubor ve složce | Jak se zobrazí |
|---|---|
| obrázek, slide | celý, na nastavenou dobu |
| video | celé, se zvukem |
| PDF | jen první stránka, na nastavenou dobu. Starší televize (zhruba před rokem 2021) PDF přeskočí |
| text (.txt) | velkým písmem uprostřed, na nastavenou dobu |
| HTML dokument (.html) | na celou obrazovku (plocha 1920 × 1080 zmenšená podle obrazovky), na nastavenou dobu. Jen přehrávače od verze 1.2.0, starší ho přeskočí |
| odkaz (.url) | přeskočí se |

Postup instalace: [Jak nainstalovat plugin pro Chrome](jak-nainstalovat-plugin-chrome), [Jak nainstalovat aplikaci na TV Samsung](jak-nainstalovat-aplikaci-tv), [Jak nainstalovat aplikaci na Android TV](jak-nainstalovat-aplikaci-android).

## Zálohy

Zálohy zařízení neobsahují. Obnovení zálohy zařízení ani jejich klíče nezmění.
