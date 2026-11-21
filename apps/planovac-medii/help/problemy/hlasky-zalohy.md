---
titulek: Hlášky záloh a aktualizací
sekce: problemy
admin: ano
kontext: nastaveni-zalohy
souvisi: jak-zalohovat-a-obnovit, jak-aktualizovat-aplikaci
klicova: chyba, záloha, obnovení, heslo záloh, aktualizace, token
---
# Hlášky záloh a aktualizací

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## Zálohy

| Hláška | Co znamená | Co dělat |
|---|---|---|
| **Heslo záloh musí mít aspoň 8 znaků.** | Heslo je krátké. | Zvolte delší. |
| **Heslo záloh nesmí obsahovat apostrof.** | Heslo obsahuje znak `'`. | Zvolte heslo bez apostrofu. |
| **Složka na Synology má tvar /volume1/zaloha/twb-media-planner.** | Cesta má špatný tvar. | Zadejte cestu začínající lomítkem, bez `..`. |
| **Soubor není záloha TWB Plánovače médií.** | Nahraný ZIP není záloha aplikace. | Vyberte správný soubor. |
| **Část souboru se nepodařilo přijmout.** | Nahrání zálohy se přerušilo. | Zkuste to znovu. |
| Tlačítko **Zálohovat teď** je šedé | Chybí heslo záloh, nebo server zálohy nepodporuje. | Nastavte heslo záloh. Důvod ukáže červený rámeček na kartě. |
| Heslo zálohy nesedí | Záloha je šifrovaná jiným heslem. | Zadejte heslo, které platilo při vytvoření zálohy. |

## Aktualizace

| Hláška | Co znamená | Co dělat |
|---|---|---|
| **Před aktualizací je potřeba záloha databáze – nastavte heslo záloh (Nastavení → Zálohy).** | Bez hesla záloh se aktualizace nespustí. | Nastavte heslo záloh. |
| **PHP nemůže zapisovat do souborů aplikace: … Aktualizujte ručně přes FTP.** | Server nedovolí přepsat soubory. | Nahrajte novou verzi ručně přes FTP. |
| **V config.php chybí update_token …** | Token aktualizací není nastavený. | Doplňte ho do `config.php` na serveru. |
| **Server odmítl token (HTTP …).** | Token je špatný nebo neplatný. | Zkontrolujte `update_token` v `config.php`. |
| **Podpis vydání nesouhlasí – aktualizace se nenabídne.** | Ověření podpisu selhalo. | Aktualizaci neinstalujte, kontaktujte podporu. |
| **Update Server neodpovídá: …** | Server aktualizací není dostupný. | Zkuste později. |
| **Kontrolní součet staženého balíčku … nesouhlasí – nic se neinstaluje.** | Stažení se poškodilo. | Zkuste znovu. |
| **Tato verze už není k dispozici.** | Mezitím vyšla jiná verze. | Klikněte na **Zjistit novou verzi**. |
| **Aktualizace … selhala … Vrácena verze ….** | Nová verze nefungovala a vrátila se předchozí. | Podrobnosti jsou v **Protokolu**. Kontaktujte podporu. |
| **Aktualizace už probíhá.** | Druhá aktualizace se spustila souběžně. | Počkejte. |
| **Předchozí verze není uložená.** | Není k čemu se vrátit. | Nahrajte starší verzi ručně přes FTP. |

Viz také [Jak zálohovat a obnovit data](jak-zalohovat-a-obnovit) a [Jak aktualizovat aplikaci](jak-aktualizovat-aplikaci).
