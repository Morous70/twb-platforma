---
titulek: Jak nastavit upozornění na chyby
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-upozorneni
souvisi: jak-spustit-diagnostiku, hlasky-google-disk
klicova: upozornění, e-mail, sms, bulkgate, chyba, zkušební
---
# Jak nastavit upozornění na chyby

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Když přestane fungovat synchronizace s Google Diskem, televize se přestane aktualizovat. Aplikace vám pošle e-mail, případně SMS.

Upozornění přijde při první chybě, pak nejvýš jednou za 6 hodin a ještě jednou, až se všechno opraví.

## Postup

1. V menu klikněte na **Nastavení**.
2. Otevřete kartu **Upozornění**.
3. Pro e-mail vyplňte pole **E-mail**.
4. Pro SMS vyplňte **Telefon pro SMS**, **BulkGate Application ID** a **BulkGate Application token**. Token najdete v portálu BulkGate v části Modules & APIs → HTTP Simple API.
5. Klikněte na **Uložit**, nebo na **Uložit a odeslat zkušební upozornění**.
6. Zkušební upozornění se odešle a hláška uvede výsledek každého kanálu, například „e-mail: odesláno na …“ nebo „SMS: odesláno na …“.

[SNÍMEK: Nastavení → Upozornění]

## Poznámky

- Telefonní číslo bez předvolby (9 číslic) aplikace doplní o 420.
- U již uloženého tokenu vyplňte pole jen tehdy, když ho chcete změnit.
- E-mail z pole **E-mail** se používá i pro odkaz, kterým se obnovuje zapomenuté heslo. Bez něj obnova hesla nefunguje.
- Zprávy se odesílají jen na kanály, které máte vyplněné. SMS vyžaduje všechny tři údaje.

## Co se stane potom

Při první chybě synchronizace dostanete zprávu s časem a důvodem a odkazem do aplikace. Po opravě přijde zpráva „Synchronizace obrazovky opět funguje.“ (jen pokud jste o chybě už byli informováni).

## Časté chyby

- **Neplatný e-mail.** Zkontrolujte zápis adresy.
- **Není vyplněn e-mail ani SMS (telefon + BulkGate).** Vyplňte aspoň jeden kanál.
- **e-mail: odeslání na … selhalo.** Server e-mail nepřijal. Zkuste jiný e-mail, nebo kontaktujte podporu.
- **SMS: chyba BulkGate – …** Zkontrolujte ID a token v portálu BulkGate a kredit.
