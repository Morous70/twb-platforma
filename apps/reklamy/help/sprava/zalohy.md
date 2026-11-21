---
titulek: Zálohy a obnova
sekce: sprava
admin: ne
kontext: nastaveni
souvisi: aktualizace, cron
klicova: záloha, obnova, heslo záloh
---
# Zálohy a obnova

Zálohy jsou šifrované ZIPy (AES-256). V **Nastavení → Zálohy** nastavte **heslo záloh** – bez něj zálohu neotevřete, uložte si ho mimo aplikaci.

- **Automatická denní záloha** běží z [cronu](cron).
- **Zálohovat teď** vytvoří zálohu i se soubory (galerie, písma, HTML5 balíčky).
- Zálohu si můžete stáhnout kliknutím na její datum.

**Obnova**: vyberte zálohu ze seznamu nebo nahrajte soubor, zadejte heslo zálohy a potvrďte. Aplikace nejdřív ukáže, co záloha obsahuje, a před obnovou vytvoří pojistnou zálohu současného stavu. Starší záloha se po obnovení sama převede na současnou verzi. Přihlašovací údaje a místní nastavení zůstanou.
