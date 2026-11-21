---
titulek: Hlášky Google Disku a synchronizace
sekce: problemy
admin: ano
kontext: nastaveni-google-disk
souvisi: jak-pripojit-google-disk, televize-se-nemeni, jak-spustit-diagnostiku
klicova: chyba, google disk, synchronizace, 403, 500, internal error, dočasná chyba, oprávnění, složka, připojení
---
# Hlášky Google Disku a synchronizace

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

| Hláška | Co znamená | Co dělat |
|---|---|---|
| **Google Disk není připojen (Nastavení → Připojit Google Disk).** | Aplikace není propojená s Googlem. | Viz [Jak připojit Google Disk](jak-pripojit-google-disk). |
| **Přístup ke Google Disku vypršel nebo byl odvolán – připojte Disk znovu v Nastavení.** | Google odvolal oprávnění, nebo uplynula platnost. | Na kartě **Google Disk** znovu klikněte na **Připojit Google Disk**. |
| **Není nastavena cílová složka na Google Disku.** | Chybí cílová složka. | Vyplňte ji na kartě **Google Disk**. |
| **Zadané ID není složka.** | V poli **Cílová složka** je odkaz na soubor, ne na složku. | Vložte odkaz na složku. |
| **Složka je v koši.** | Cílová složka byla smazána. | Obnovte ji z koše Google Disku, nebo nastavte jinou. |
| **Složka neexistuje.** | Odkaz nebo ID je špatně, nebo ho účet nevidí. | Zkontrolujte odkaz a sdílení. |
| **Nahrání souboru „…“ selhalo: … Připojený Google účet nemá do cílové složky právo zapisovat – složku mu nasdílejte jako Editor.** | Účet nemá právo zápisu. | Nasdílejte složku připojenému účtu jako **Editor**. |
| **Odstranění souboru „…“ ze složky selhalo: … Soubor patří jinému účtu …** | Ve složce je soubor jiného vlastníka. | Odstraňte soubor ručně na Google Disku, nebo dejte připojenému účtu práva **Editor**. |
| **Soubor média chybí na serveru: …** | Soubor, který má být nahrán, na serveru není. | Nahraďte ho v **Galerii** (**Upravit → Nahradit soubor**), nebo médium smažte a nahrajte znovu. |
| **Spojení s Googlem selhalo: …** | Server se nedostal ke Googlu. | Zkuste to za chvíli. Pokud trvá, kontaktujte podporu. |
| **Google Disk (500): … – dočasná chyba na straně Googlu …** (také 502, 503, 504, 429) | Google měl krátký výpadek. Aplikace to několikrát zkusila znovu, ale nepovedlo se. | Nic. Synchronizace to zopakuje sama při příštím běhu. Hned to zkusíte tlačítkem **Synchronizovat nyní**. |
| **Google Disk (kód): …** | Google vrátil chybu. | Přečtěte si text za kódem. |
| **Synchronizace už běží.** | Druhá synchronizace se spustila během první. | Počkejte chvíli. |

## Co znamenají hlášky o výsledku

- **Složka aktualizována.** Změna se provedla.
- **Složka odpovídá plánu.** Nebylo co měnit.
- **Beze změny.** Plán se od minula nezměnil.

Pokud se synchronizace dlouho nedaří, můžete dostat upozornění e-mailem nebo SMS, viz [Jak nastavit upozornění na chyby](jak-nastavit-upozorneni).
