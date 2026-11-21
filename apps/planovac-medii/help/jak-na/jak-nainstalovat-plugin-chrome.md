---
titulek: Jak nainstalovat plugin pro Chrome
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-zarizeni
souvisi: nastaveni-zarizeni, jak-zkontrolovat-co-bezi
klicova: plugin, rozšíření, chrome, přehrávač, instalace, klíč, celá obrazovka, zařízení
---
# Jak nainstalovat plugin pro Chrome

> 🔧 **Pro správce.**

## K čemu to je

Plugin přehrává v prohlížeči Chrome stejná média jako obrazovka v trafice. Hodí se třeba na počítač v kanceláři nebo doma. Média si stahuje ze stejné složky na Google Disku a střídá je sám v náhodném pořadí.

## Postup

### 1. Přidejte zařízení v aplikaci

1. Otevřete **Nastavení → Zařízení**.
2. Klikněte na **+ Přidat zařízení**.
3. Vyplňte **Název**, například Počítač v kanceláři, a klikněte na **Přidat**.
4. Okno s **adresou aplikace** a **klíčem zařízení** nechte otevřené. Klíč se zobrazí jen teď.

### 2. Stáhněte a rozbalte plugin

1. Na stejné kartě klikněte na **Stáhnout plugin pro Chrome**.
2. Stažený soubor ZIP rozbalte. Vznikne složka **twb-planovac-medii-chrome**.
3. Složku přesuňte na místo, kde zůstane, například do **Dokumentů**. Chrome si plugin načítá z této složky, proto ji nemažte.

### 3. Načtěte plugin do Chrome

1. Do adresního řádku Chrome napište `chrome://extensions` a stiskněte Enter.
2. Vpravo nahoře zapněte **Režim pro vývojáře**.
3. Klikněte na **Načíst rozbalené** a vyberte složku **twb-planovac-medii-chrome**.
4. Plugin **TWB Plánovač médií – přehrávač** se objeví v seznamu a otevře se jeho nastavení.

### 4. Připojte plugin k aplikaci

1. Do pole **Adresa aplikace** vložte adresu z okna v aplikaci.
2. Do pole **Klíč zařízení** vložte klíč.
3. Klikněte na **Uložit a vyzkoušet**. Objeví se **✓ Připojeno jako „název zařízení“**.
4. V aplikaci zavřete okno s klíčem tlačítkem **Hotovo**.

### 5. Připněte ikonu a spusťte přehrávání

1. V Chrome klikněte vpravo nahoře na ikonu **Rozšíření** (dílek skládačky) a u pluginu klikněte na špendlík. Ikona pluginu zůstane vidět vedle adresního řádku.
2. Klikněte na ikonu pluginu a potom na **Přehrávat na celé obrazovce**.
3. Přehrávač se otevře v nové záložce. Na celou obrazovku ho přepnete tlačítkem **⛶**, dvojklikem nebo klávesou F.

## Co se stane potom

- Plugin si stáhne všechna média a uloží je u sebe. Bez internetu přehrává poslední stažený obsah.
- Každých 5 minut si obnoví seznam a stáhne jen nové soubory. Když se obsah složky změní, obnoví seznam do 15 sekund. Seznam obnovíte hned tlačítkem **⟳ Obnovit** v okénku nebo v přehrávači.
- Nový obsah se v přehrávači projeví od dalšího média.
- Číslo na ikoně ukazuje, kolik médií plugin přehrává.
- V **Nastavení → Zařízení** se u zařízení ukáže, kdy se naposledy ozvalo a co právě hraje. Odtud jde přehrávač i ovládat. Ovládání funguje, jen když je přehrávač otevřený.

Ovládací pruh přehrávače se ukáže po pohybu myší:

| Tlačítko nebo klávesa | Význam |
|---|---|
| **⟳ Obnovit**, klávesa R | hned obnoví seznam médií |
| **❚❚**, mezerník | pozastaví přehrávání, dalším stiskem pokračuje |
| **⏭**, šipka vpravo | přeskočí na další médium |
| **⛶**, klávesa F, dvojklik | celá obrazovka |

## Nová verze pluginu

Plugin se sám neaktualizuje. Když je v aplikaci u tlačítka **Stáhnout plugin pro Chrome** vyšší verze:

1. Stáhněte nový ZIP a jeho obsahem přepište složku **twb-planovac-medii-chrome**.
2. Na stránce `chrome://extensions` klikněte u pluginu na ikonu **Znovu načíst** (kruhová šipka).

Nastavení pluginu i stažená média zůstanou.

## Časté chyby

- **Na ikoně je „?“.** Plugin není nastavený. Klikněte na ikonu a potom na **Nastavení pluginu**.
- **Na ikoně je červený „!“.** Seznam se nepodařilo obnovit. Důvod ukáže okénko po kliknutí na ikonu. Přehrávač mezitím přehrává poslední stažený obsah.
- **Neplatný klíč zařízení.** Klíč je špatně zkopírovaný, nebo bylo zařízení odebráno či dostalo nový klíč. V aplikaci klikněte u zařízení na **Nový klíč** a nový klíč vložte do nastavení pluginu.
- **Příliš mnoho pokusů se špatným klíčem.** Z vaší sítě přišlo mnoho špatných klíčů. Opravte klíč a zkuste to znovu za 15 minut.
- **Video hraje bez zvuku a vpravo nahoře je tlačítko 🔇 Zapnout zvuk.** Chrome nepustí zvuk, dokud do stránky nekliknete. Klikněte na tlačítko.
- **Přehrávač ukazuje „Stahuji média…“.** Plugin ještě stahuje soubory. U velkých videí to může chvíli trvat.
- **Odkazy na web se nepřehrávají.** To je záměr, plugin je přeskakuje.
