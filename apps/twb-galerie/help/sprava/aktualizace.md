---
titulek: Aktualizace
sekce: sprava
admin: ne
souvisi: zalohy, cron
klicova: update, verze, instalace, update server
---
# Aktualizace

Aktualizace přicházejí z TWB Update Serveru (aplikace **twb-galerie**). V config.php musí být `update_token`.

1. [Cron](cron) jednou denně zjistí, jestli je nová verze, a **Přehled** ji ohlásí; ručně v **Nastavení → Aktualizace → Zjistit novou verzi**.
2. **Nainstalovat** stáhne balíček, ověří podpis, zálohuje databázi, nahradí soubory a zkontroluje, že nová verze běží. Když kontrola selže, vrátí se sama předchozí verze.
3. **Vrátit předchozí verzi** jde i ručně.

Soubor config.php a složka data (databáze a nahraná média) se aktualizací nikdy nemění.
