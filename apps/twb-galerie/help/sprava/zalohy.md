---
titulek: Zálohy a obnova
sekce: sprava
admin: ne
souvisi: aktualizace, cron
klicova: záloha, obnova, heslo záloh, zip
---
# Zálohy a obnova

Zálohy jsou šifrované ZIPy (AES-256) a obsahují databázi i všechny nahrané soubory (originály před kompresí taky). V **Nastavení → Zálohy** nastavte **heslo záloh** – bez něj zálohu neotevřete, uložte si ho mimo aplikaci. Dokud heslo chybí, upozorní na to **Přehled**.

- **Automatická denní záloha** běží z [cronu](cron).
- **Zálohovat teď** vytvoří zálohu hned.
- Zálohu si stáhnete kliknutím na její datum. U velké galerie je záloha velká – počítejte s místem na hostingu.

**Obnova**: vyberte zálohu ze seznamu nebo nahrajte soubor, zadejte heslo zálohy a potvrďte. Aplikace nejdřív ukáže, co záloha obsahuje (počet médií, kampaní a souborů), a před obnovou vytvoří pojistnou zálohu současného stavu. Starší záloha se po obnovení sama převede na současnou verzi.

Po obnově platí veřejné odkazy, které byly v záloze – odkazy vytvořené později přestanou fungovat. Heslo, Passkey, propojený Google účet a API klíče se zálohou nemění.
