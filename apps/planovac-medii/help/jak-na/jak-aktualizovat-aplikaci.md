---
titulek: Jak aktualizovat aplikaci a vrátit předchozí verzi
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-system
souvisi: jak-zalohovat-a-obnovit, jak-spustit-diagnostiku, hlasky-zalohy
klicova: aktualizace, nová verze, update, vrátit, rollback
---
# Jak aktualizovat aplikaci a vrátit předchozí verzi

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Nové verze přinášejí opravy a novinky. Aplikace se jednou denně sama zeptá, jestli existuje novější verze. Nainstaluje se ale až po vašem kliknutí.

## Jak zjistit, že je nová verze

- V **Přehledu** se objeví modré upozornění **Je k dispozici nová verze aplikace …**.
- Na kartě **Nastavení → Systém** v části **Aktualizace** je uvedena nainstalovaná verze, čas poslední kontroly a případná nová verze s datem vydání a velikostí.
- Kontrolu můžete spustit hned tlačítkem **Zjistit novou verzi**.

## Jak aktualizovat

1. Otevřete **Nastavení → Systém → Aktualizace**.
2. Zkontrolujte, že máte nastavené heslo záloh (viz [Jak zálohovat a obnovit data](jak-zalohovat-a-obnovit)).
3. Klikněte na **Aktualizovat na …**.
4. Přečtěte si dotaz a potvrďte. Instalace trvá obvykle do minuty, nezavírejte stránku.
5. Po úspěchu se stránka sama znovu načte.

[SNÍMEK: Nastavení → Systém → Aktualizace s nabídkou nové verze]

## Co aplikace udělá

Stáhne podepsaný balíček a ověří ho. Před instalací zazálohuje databázi a uloží současnou verzi. Po instalaci ověří, že nová verze funguje. Pokud nefunguje, sama se vrátí k předchozí verzi. Soubor `config.php` a data zůstávají.

## Jak se vrátit k předchozí verzi

1. Otevřete **Nastavení → Systém → Aktualizace**.
2. Klikněte na **Vrátit předchozí verzi …**. Tlačítko je vidět, jen když je předchozí verze uložená.
3. Přečtěte si dotaz a potvrďte.

Pokud nová verze změnila databázi, obnoví se i databáze ze zálohy před aktualizací. Změny od aktualizace se pak ztratí.

## Časté chyby

- **Před aktualizací je potřeba záloha databáze – nastavte heslo záloh (Nastavení → Zálohy).** Nastavte heslo záloh.
- **PHP nemůže zapisovat do souborů aplikace: … Aktualizujte ručně přes FTP.** Server nedovoluje aplikaci přepsat své soubory. Nahrajte novou verzi ručně přes FTP.
- **V config.php chybí update_token.** Token aktualizací se doplňuje v souboru `config.php` na serveru.
- **Server odmítl token.** Zkontrolujte `update_token` v `config.php`.
- **Kontrolní součet … nesouhlasí – nic se neinstaluje.** Balíček se stáhl poškozený. Zkuste to znovu.
- **Aktualizace … selhala … Vrácena verze ….** Aplikace se vrátila k předchozí verzi. Podrobnosti jsou v **Protokolu**.
- **Tato verze už není k dispozici.** Klikněte znovu na **Zjistit novou verzi**.
