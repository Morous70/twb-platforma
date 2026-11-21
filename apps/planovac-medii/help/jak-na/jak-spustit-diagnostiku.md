---
titulek: Jak spustit diagnostiku
sekce: jak-na
skupina: Správa
admin: ano
kontext: nastaveni-system
souvisi: televize-se-nemeni, kontakt
klicova: diagnostika, kontrola, test, chyba, podpora
---
# Jak spustit diagnostiku

> 🔧 **Pro správce.** Běžná obsluha tuto stránku nepotřebuje.

## K čemu to je

Diagnostika zkontroluje, jestli všechno potřebné funguje, a u problémů poradí, co dělat. Nic nemění a na Google Disk nic nenahrává.

## Postup

1. V menu klikněte na **Nastavení**.
2. Otevřete kartu **Systém**.
3. V části **Diagnostika** klikněte na **Spustit kontrolu**.
4. Počkejte na výsledek.
5. Pro odeslání podpoře klikněte na **Kopírovat výsledek** a vložte ho do e-mailu.

[SNÍMEK: výsledek diagnostiky s několika skupinami]

## Co ukazuje

Výsledky jsou rozdělené do skupin: Server a PHP, Úložiště, Databáze, Cron a synchronizace, Google Disk, Zálohy, Synology a přehrávač, Zdroje dat, Aktualizace, Upozornění. Každá položka má značku:

| Značka | Význam |
|---|---|
| **✓** | V pořádku |
| **!** | Pozor, mělo by se zkontrolovat |
| **✕** | Chyba |
| **i** | Informace |

U upozornění a chyb je pod položkou rada, co s tím.

Nahoře je souhrn: **Vše v pořádku**, nebo počet chyb a upozornění.

## Časté chyby

- **Položka se značkou „i“.** Je to jen informace, třeba že hosting nesděluje volné místo na disku. Není to chyba.
- **Chyba v části Cron a synchronizace.** Pravidelná úloha na serveru neběží. Viz [Na televizi se nic nemění](televize-se-nemeni).
- **Chyba v části Google Disk.** Viz [Hlášky Google Disku a synchronizace](hlasky-google-disk).
