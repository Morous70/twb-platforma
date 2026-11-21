---
titulek: Jak nainstalovat aplikaci na TV Samsung
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-zarizeni
souvisi: nastaveni-zarizeni, jak-nainstalovat-plugin-chrome, jak-nainstalovat-aplikaci-android
klicova: televize, samsung, tizen, tizen studio, párování, kód, ovladač, aplikace, certifikát, režim pro vývojáře
---
# Jak nainstalovat aplikaci na TV Samsung

> 🔧 **Pro správce.**

## K čemu to je

Aplikace **TWB přehrávač** přehrává na televizi Samsung stejná média jako obrazovka v trafice. Média si stahuje ze stejné složky na Google Disku a střídá je sama v náhodném pořadí. Hodí se pro další televizi, třeba ve výloze.

Aplikace funguje na televizích Samsung s Tizenem 4.0 a novějším, tedy zhruba od roku 2018. PDF umí zobrazit jen novější televize, zhruba od roku 2021. Na starších se PDF přeskočí.

Instalace se dělá jednou z počítače s programem **Tizen Studio** od Samsungu. Je zdarma a potřebuje účet Samsung. Počítač a televize musí být ve stejné síti.

## Postup

### 1. Stáhněte aplikaci

1. V aplikaci otevřete **Nastavení → Zařízení**.
2. Klikněte na **Stáhnout aplikaci pro TV Samsung**.
3. Stažený soubor ZIP rozbalte. Vznikne složka **twb-planovac-medii-tizen**. Adresa vaší aplikace v ní už je vyplněná.

### 2. Nainstalujte Tizen Studio

1. Na stránce Samsung Developers stáhněte **Tizen Studio** (verze s IDE) a nainstalujte ho.
2. Spusťte **Package Manager** (Správce balíčků Tizen Studia).
3. Na kartě **Extension SDK** nainstalujte **TV Extensions** a **Samsung Certificate Extension**.

### 3. Zapněte na televizi režim pro vývojáře

1. Na počítači zjistěte jeho adresu IP v místní síti.
2. Na televizi otevřete **Aplikace** (Apps) a sjeďte dolů na **Nastavení aplikací** (App Settings).
3. Na ovladači nebo na klávesnici na obrazovce zadejte **1 2 3 4 5**. Objeví se okno **Developer mode**.
4. Přepněte **Developer mode** na **On**, zadejte adresu IP počítače a potvrďte **OK**.
5. Televizi vypněte a znovu zapněte. Nahoře v **Aplikacích** pak bude nápis **Develop Mode**.

### 4. Připojte televizi v Tizen Studiu

1. Na televizi zjistěte její adresu IP (nastavení sítě v televizi).
2. V Tizen Studiu otevřete **Tools → Device Manager**.
3. Klikněte na **Remote Device Manager** a potom na **+**.
4. Zadejte název televize a její adresu IP, port nechte **26101** a klikněte na **Add**.
5. U televize přepněte **Connection** na **On**.

### 5. Vytvořte certifikát Samsung

1. V Tizen Studiu otevřete **Tools → Certificate Manager**.
2. Klikněte na **+**, vyberte **Samsung** a potom **TV**.
3. Zadejte název profilu, například TWB.
4. Vyberte **Create a new author certificate**, vyplňte údaje a přihlaste se účtem Samsung. Zálohu certifikátu uložte na bezpečné místo.
5. Vyberte **Create a new distributor certificate**. Úroveň oprávnění nechte **Public**.
6. Kliknutím na **+** u připojené televize přidejte její identifikátor (DUID).
7. Klikněte na **Finish**.

Certifikát platí jen pro televize, jejichž identifikátor v něm je. Pro další televizi certifikát upravte a přidejte její identifikátor.

### 6. Nahrajte aplikaci do televize

1. V Tizen Studiu otevřete **File → Import → Tizen → Tizen Project** a vyberte složku **twb-planovac-medii-tizen**.
2. V seznamu **Project Explorer** klikněte na projekt pravým tlačítkem a vyberte **Run As → Tizen Web Application**.
3. Aplikace se nahraje do televize a spustí se.

### 7. Spárujte televizi

1. Televize ukáže šestimístný kód. Platí 10 minut, pak se sám vymění za nový.
2. V aplikaci otevřete **Nastavení → Zařízení** a klikněte na **📺 Spárovat televizi**.
3. Zadejte **Kód z televize** a **Název**, například Televize ve výloze, a klikněte na **Spárovat**.
4. Do několika sekund se na televizi rozběhne přehrávání.

## Co se stane potom

- Aplikaci spustíte ovladačem z nabídky **Aplikace**. Jmenuje se **TWB přehrávač**. Po spuštění hned přehrává, párovat znovu není potřeba.
- Aplikace si média uloží v televizi. Bez internetu přehrává poslední stažený obsah. Co se do televize nevejde, přehraje přímo ze serveru.
- Každých 5 minut si obnoví seznam. Když se obsah složky změní, obnoví seznam do 15 sekund. Nový obsah se projeví od dalšího média.
- Spořič obrazovky je během přehrávání vypnutý.
- V **Nastavení → Zařízení** se u televize ukáže, kdy se naposledy ozvala a co právě hraje. Odtud jde televize i ovládat: **⟳ Obnovit teď**, **↻ Restartovat přehrávač**, **⏭ Další soubor**.

Ovládání ovladačem:

| Tlačítko | Význam |
|---|---|
| **OK** nebo **Přehrát/Pauza** | pozastaví přehrávání, dalším stiskem pokračuje |
| **šipka vpravo** | další médium |
| zelené **B** | hned obnoví seznam médií |
| **Zpět** | otevře nebo zavře okno se stavem. Okno se samo zavře po 10 sekundách |
| podržet **Zpět** | nastavení přehrávače |
| **Domů** | odejde z aplikace |

Ovladače bez barevných tlačítek: stiskněte **Zpět** a v okně se stavem stiskněte **OK** na tlačítku **⟳ Obnovit seznam**.

V nastavení přehrávače (podržet **Zpět**) se šipkami nahoru a dolů vybírá a tlačítkem **OK** potvrzuje:

| Tlačítko | Význam |
|---|---|
| **Uložit adresu** | uloží adresu aplikace. Když se adresa změní, televize ukáže nový párovací kód |
| **Zrušit spárování** | televize zapomene klíč a ukáže nový párovací kód |
| **Zavřít** | zavře nastavení |

## Nová verze aplikace

Aplikace se sama neaktualizuje. Když je v aplikaci u tlačítka **Stáhnout aplikaci pro TV Samsung** vyšší verze:

1. Stáhněte nový ZIP a jeho obsahem přepište složku **twb-planovac-medii-tizen**.
2. Nahrajte aplikaci do televize stejně jako v kroku **6. Nahrajte aplikaci do televize**.

## Časté chyby

- **Vpravo dole je malá červená ikonka „!“ s časem.** Seznam se nepodařilo obnovit, čas ukazuje poslední úspěšné obnovení. Důvod ukáže okno po stisku **Zpět**. Přehrává se dál poslední stažený obsah.
- **Televize místo přehrávání ukáže párovací kód a hlášku Televize už není spárovaná.** Zařízení bylo v aplikaci odebráno nebo dostalo **Nový klíč**. Spárujte televizi znovu.
- **Kód neplatí.** Kód je špatně opsaný, nebo už propadl. Opište kód, který televize ukazuje právě teď.
- **Příliš mnoho špatných kódů.** Párování je na 15 minut zablokované. Počkejte a zkuste to znovu.
- **Párovací kód se nepodařilo získat.** Televize se nedostane k aplikaci. Zkontrolujte připojení televize k internetu a adresu v nastavení přehrávače.
- **Tizen Studio hlásí při nahrávání chybu 1010.** Nejčastější příčinou je certifikát. Zkontrolujte, že je v certifikátu identifikátor (DUID) této televize.
- **PDF se na televizi nezobrazuje.** Starší televize PDF neumí, přehrávač je přeskakuje.
