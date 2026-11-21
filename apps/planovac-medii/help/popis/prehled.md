---
titulek: Přehled
sekce: popis
admin: ne
kontext: prehled
souvisi: jak-zkontrolovat-co-bezi, jak-vynutit-synchronizaci, televize-se-nemeni
klicova: přehled, právě teď, další změna, týdenní náhled, varování, štítky
---
# Přehled

## K čemu to je

První obrazovka po přihlášení. Ukazuje, co aplikace právě připravila pro televizi, a co poběží dál.

## Co na obrazovce je

### Tlačítko Synchronizovat nyní

Vpravo nahoře. Spustí synchronizaci ihned. Viz [Jak vynutit okamžitou změnu na televizi](jak-vynutit-synchronizaci).

### Varování

Nad panely se mohou zobrazit rámečky:
- červený **Poslední synchronizace selhala: …** s důvodem,
- Google Disk není připojen,
- není nastavena cílová složka,
- chybí výchozí scénář,
- výběr podle data nemá žádné další datum,
- Synology se neozvalo déle než 15 minut,
- cron neběžel déle než 20 minut,
- modré upozornění na novou verzi aplikace.

Každé varování obsahuje odkaz na místo, kde se opraví.

### Panel Právě teď

Ukazuje druh sestavy (**Výchozí scénář**, **Výhradní scénář**, **Běží**, **Jen pevné**, **Nic neběží**), názvy běžících scénářů a miniatury médií. U každého média je napsáno, ze kterého scénáře je. Je tam i čas **Poslední kontrola**.

Miniaturu zvětšíte kliknutím. Ve zvětšeném okně listujete šipkami **←** a **→** (na počítači i klávesami šipek, na telefonu tažením prstem do strany); **Zavřít** okno zavře. Listuje se mezi médii, která právě běží.

Poznámky: **potlačeno**, **teď bez obsahu**, **pevný vypnut**, **vytlačeno scénářem …**. Význam viz [Jak zkontrolovat, co právě běží](jak-zkontrolovat-co-bezi).

Pod médii je **Další změna**: čas a co poběží. Pokud se nic nemění, je tam „V příštích 14 dnech se nic nemění.“

### Týdenní náhled

Tabulka dnů a hodin. Každé políčko je jedna hodina. Stejná barva znamená stejnou sestavu. Kliknutím na políčko nebo na položku legendy se pod tabulkou zobrazí složení. Šipky **‹** a **›** listují po týdnech, **Tento týden** vrátí zpět.

## Automatická aktualizace

Obrazovka se sama obnoví, když se změní to, co právě běží (kontrola každou minutu).
