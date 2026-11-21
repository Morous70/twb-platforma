---
titulek: Jak nastavit přehrávač a skript pro Synology
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-prehravac
souvisi: jak-se-zmena-dostane-na-televizi, jak-pripojit-google-disk, jak-zalohovat-a-obnovit
klicova: přehrávač, synology, skript, slideshow, plánovač úloh, klíč
---
# Jak nastavit přehrávač a skript pro Synology

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Přehrávač SlideShow je jen v místní síti, takže mu aplikace na internetu nemůže zavolat přímo. Proto na Synology běží malý skript. Každou minutu se zeptá aplikace, jestli se složka na Google Disku změnila. Když ano, dá přehrávači pokyn, aby si nový obsah stáhl. Stejný skript také stahuje zálohy (viz [Jak zálohovat a obnovit data](jak-zalohovat-a-obnovit)).

Heslo k přehrávači zůstává jen na Synology.

## Postup

1. Nejdřív nastavte cílovou složku na Google Disku, viz [Jak připojit Google Disk](jak-pripojit-google-disk). Bez ní skript nepůjde sestavit.
2. V aplikaci otevřete **Nastavení → Přehrávač**.
3. Vyplňte:
   - **Adresa přehrávače v místní síti** (například `192.168.99.249:8080`),
   - **Uživatel přehrávače**,
   - **Cílová složka v přehrávači**,
   - zaškrtávátko **Mazat staré soubory v přehrávači** podle toho, jak to máte nastavené v přehrávači.
4. Klikněte na **Uložit**.
5. Klikněte na **Kopírovat skript**.
6. Na Synology (DSM) otevřete **Ovládací panel → Plánovač úloh → Vytvořit → Naplánovaná úloha → Uživatelem definovaný skript**.
7. Karta **Obecné**: název například „TWB Plánovač médií – přehrávač“, uživatel **root**, zaškrtnuto **Povoleno**.
8. Karta **Plán**: **Spustit denně**, čas spuštění **00:00**, frekvence **Každou minutu**, čas posledního spuštění **23:59**.
9. Karta **Nastavení úlohy**: do pole **Uživatelem definovaný skript** vložte zkopírovaný skript. Na řádku `PLAYER_PASS='…'` nahraďte text `SEM-DOPLNTE-HESLO` heslem k přehrávači. Heslo nesmí obsahovat apostrof.
10. Uložte úlohu.
11. V aplikaci obnovte stránku. Do minuty by se mělo objevit **Synology se naposledy ozval právě teď**.

[SNÍMEK: Nastavení → Přehrávač s adresou, uživatelem a skriptem]

## Co aplikace ukazuje

Na kartě je vidět, kdy se Synology naposledy ozvalo, a kdy byla poslední změna na Disku. Při změně se objeví **přehrávač spuštěn ✓**, dokud se pokyn neodešle, je tam **čeká na spuštění přehrávače**.

## Nový klíč

Tlačítko **Nový klíč** vytvoří nový klíč pro skript. Starý skript pak přestane fungovat, dokud ho na Synology nenahradíte novým. Aplikace se předem zeptá. Použijte to, jen když máte podezření, že klíč unikl.

Po změně adresy, uživatele, složky nebo klíče vždy vložte na Synology nový skript.

## Co se stane potom

Po každé změně složky na Google Disku dostane přehrávač pokyn ke stažení do minuty. Přehrávač má navíc vlastní pravidelnou synchronizaci (v nastavení přehrávače). Nechte ji zapnutou jako zálohu.

## Časté chyby

- **Synology se zatím neozvalo – skript ještě neběží.** Úloha není zapnutá, nebo běží pod jiným uživatelem než root. V Plánovači úloh ji označte a klikněte na **Spustit**.
- **Přehrávač se neozval déle než 15 minut** (varování v **Přehledu**). Synology se nejspíš nedostane na internet, nebo nedůvěřuje certifikátu webu. V DSM spusťte **Aktualizace a obnovení**.
- **Adresa přehrávače má tvar 192.168.1.10:8080.** Opravte zápis adresy.
- **Nejdřív nastavte cílovou složku na Google Disku.** Doplňte složku.
- **Skript nefunguje po změně klíče.** Vložte nový.
