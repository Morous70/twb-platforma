---
titulek: Jak zálohovat a obnovit data
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-zalohy
souvisi: jak-nastavit-prehravac, jak-aktualizovat-aplikaci, hlasky-zalohy
klicova: záloha, obnovit, heslo záloh, synology, restore
---
# Jak zálohovat a obnovit data

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Záloha uchovává všechno, co jste v aplikaci vytvořili: scénáře, galerii, slidy, data i nastavení. Když se něco pokazí, vrátíte se k dřívějšímu stavu.

## Jak zálohy fungují

- Každý den od určité hodiny (uvedené na kartě) se vytvoří šifrovaná záloha. Obsahuje celou databázi včetně přístupových údajů a, jen pokud se od minula změnily, soubory galerie.
- Na serveru zůstává posledních několik záloh a vždy poslední záloha se soubory.
- Kopie si stahuje Synology.

## Jak zálohy zapnout

1. V menu klikněte na **Nastavení**.
2. Otevřete kartu **Zálohy**.
3. Zaškrtněte **Automatické denní zálohy**.
4. Do pole **Heslo záloh** zadejte heslo (aspoň 8 znaků, bez apostrofu). Bez hesla se nezálohuje.
5. Případně upravte **Složku na Synology** a **Mazat na Synology zálohy starší než (dní)**. 0 znamená nemazat.
6. Klikněte na **Uložit**. Objeví se hláška **Nastavení záloh uloženo.**
7. Po změně složky nebo počtu dní vložte na Synology nový skript (karta **Přehrávač**, viz [Jak nastavit přehrávač a skript pro Synology](jak-nastavit-prehravac)).

> **Heslo si uložte i mimo aplikaci.** Bez něj zálohu neotevřete. Po změně hesla se starší zálohy otevírají původním heslem.

## Jak zálohu vytvořit ručně

Na kartě **Zálohy** v části **Uložené zálohy** klikněte na **Zálohovat teď**. Objeví se hláška o velikosti, a zda záloha obsahuje soubory galerie, nebo jen databázi.

## Seznam záloh

U každé zálohy vidíte čas, štítky (**se soubory** nebo **jen databáze**, **před obnovením**, **nahraná**), název souboru a velikost. K dispozici jsou tlačítka **Stáhnout**, **Obnovit…** a křížek **×** (smazání zálohy, aplikace se zeptá).

## Jak zálohu obnovit

1. V seznamu u zálohy klikněte na **Obnovit…**. Nebo klikněte na **Obnovit ze souboru…** a vyberte stažený soubor ZIP.
2. Zadejte **Heslo zálohy**. Prázdné pole znamená aktuální heslo záloh.
3. Klikněte na **Ověřit zálohu**. Aplikace ukáže, z kdy záloha je, z jaké verze a kolik obsahuje položek galerie, scénářů a řádků dat.
4. Pokud je všechno v pořádku, klikněte na **Obnovit** a potvrďte.

[SNÍMEK: okno Obnovit zálohu po ověření]

## Co se stane potom

Obnovení nahradí **všechna** současná data (scénáře, galerii, slidy, data, nastavení i heslo do aplikace) stavem ze zálohy. Před obnovením se současný stav automaticky zazálohuje (štítek **před obnovením**), takže ho lze vrátit. Obnoví se i propojený Google účet pro přihlášení. Zaregistrovaná zařízení Passkey obnovení nemění.

Pokud záloha neobsahuje soubory galerie, doplní se z poslední zálohy se soubory nebo ze serveru. Pokud některé soubory chybí, hláška to uvede a podrobnosti jsou v **Protokolu**.

## Časté chyby

- **Heslo záloh musí mít aspoň 8 znaků.** nebo **Heslo záloh nesmí obsahovat apostrof.** Zvolte jiné heslo.
- **Tlačítko Zálohovat teď je neaktivní.** Není nastavené heslo záloh, nebo server zálohy nepodporuje (karta ukáže červeně důvod).
- **Soubor není záloha TWB Plánovače médií.** Vybraný soubor je jiný ZIP.
- **Záloha se nedá otevřít.** Zkuste heslo, které platilo v době vytvoření zálohy.
- **Po obnovení se musím přihlásit jiným heslem.** Obnovilo se i heslo do aplikace, platí heslo z doby zálohy.
- **Synology se zatím neozvalo.** Vložte na Synology nový skript.
